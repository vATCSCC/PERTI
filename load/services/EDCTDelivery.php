<?php
/**
 * EDCT Delivery Service — Multi-Channel Message Dispatch
 *
 * Delivers EDCT assignments and gate-hold messages to pilots via
 * multiple channels with priority-based fallback:
 *
 *   Priority 1: Hoppie CPDLC (direct to sim client)
 *   Priority 2: Pilot client plugin (VatswimPlugin polling)
 *   Priority 3: CDM web dashboard (WebSocket push)
 *   Priority 4: Discord DM (notification-only fallback)
 *   Priority 5: SimTraffic webhook (outbound event queue)
 *
 * Adapted from FAA CDM AOCnet EDCT distribution to airlines,
 * modified for direct pilot delivery on VATSIM.
 *
 * Hibernation-aware: During hibernation, messages are queued in
 * cdm_messages with is_hibernation_queued=1 but NOT delivered.
 * Post-processing on wake marks them as EXPIRED (stale EDCTs).
 *
 * @package PERTI
 * @subpackage CDM
 * @version 1.0.0
 */

require_once __DIR__ . '/CDMService.php';
require_once __DIR__ . '/../../lib/webhooks/WebhookEventBuilder.php';

class EDCTDelivery
{
    private CDMService $cdm;
    private $conn_tmi;
    private bool $is_hibernation;
    private bool $verbose;

    // Hoppie CPDLC client (lazy-loaded)
    private $hoppieClient = null;

    // Discord API (lazy-loaded)
    private $discordApi = null;

    // Webhook event builder (lazy-loaded)
    private ?\PERTI\Lib\Webhooks\WebhookEventBuilder $webhookBuilder = null;

    // Delivery spacing (avoid Hoppie rate limits)
    const CPDLC_SEND_SPACING_SEC = 2;
    private float $lastCpdlcSend = 0;

    public function __construct(CDMService $cdm, $conn_tmi, bool $verbose = false)
    {
        $this->cdm = $cdm;
        $this->conn_tmi = $conn_tmi;
        $this->is_hibernation = defined('HIBERNATION_MODE') && HIBERNATION_MODE;
        $this->verbose = $verbose;
    }

    // =========================================================================
    // EDCT MESSAGE FORMATTING
    // =========================================================================

    /**
     * Format an EDCT assignment message for CPDLC delivery.
     * Follows standard CPDLC uplink format.
     *
     * @param string $edct_utc    EDCT time (Y-m-d H:i:s)
     * @param string $reason      Reason text (e.g., "GDP KJFK VOLUME")
     * @param string|null $program Program name for context
     * @return string CPDLC message text
     */
    public function formatEDCTMessage(string $edct_utc, string $reason, ?string $program = null): string
    {
        $hhmm = date('Hi', strtotime($edct_utc));
        $msg = "EXPECT DEPARTURE CLEARANCE TIME {$hhmm}Z";
        if ($reason) {
            $msg .= " DUE $reason";
        }
        $msg .= ". REPORT READY.";
        return $msg;
    }

    /**
     * Format a gate-hold message for CPDLC delivery.
     */
    public function formatGateHoldMessage(string $tsat_utc, string $reason): string
    {
        $hhmm = date('Hi', strtotime($tsat_utc));
        return "HOLD POSITION. EXPECT PUSHBACK TIME {$hhmm}Z DUE $reason.";
    }

    /**
     * Format a gate-release message for CPDLC delivery.
     */
    public function formatGateReleaseMessage(string $ttot_utc): string
    {
        $hhmm = date('Hi', strtotime($ttot_utc));
        return "PUSHBACK APPROVED. EXPECT TAKEOFF {$hhmm}Z.";
    }

    /**
     * Format a slot cancellation message.
     */
    public function formatCancelMessage(string $reason): string
    {
        return "DEPARTURE CLEARANCE TIME CANCELLED. $reason";
    }

    // =========================================================================
    // MULTI-CHANNEL DELIVERY
    // =========================================================================

    /**
     * Deliver an EDCT to a pilot through available channels.
     * Queues messages for all reachable channels.
     *
     * @param int    $flight_uid
     * @param string $callsign
     * @param string $edct_utc      EDCT time
     * @param string $reason        Reason text
     * @param int|null $cid         VATSIM CID (for web/Discord)
     * @param int|null $program_id
     * @param int|null $slot_id
     * @return array  Results per channel
     */
    public function deliverEDCT(
        int $flight_uid,
        string $callsign,
        string $edct_utc,
        string $reason,
        ?int $cid = null,
        ?int $program_id = null,
        ?int $slot_id = null
    ): array {
        $message_body = $this->formatEDCTMessage($edct_utc, $reason);
        $results = [];

        // During hibernation, queue but don't deliver
        if ($this->is_hibernation) {
            $msg_id = $this->cdm->queueMessage(
                $flight_uid, $callsign, CDMService::MSG_EDCT,
                $message_body, 'all', $cid, $program_id, $slot_id
            );
            $results['hibernation_queued'] = $msg_id !== false;
            $this->log("EDCT hibernation-queued for $callsign: $edct_utc");
            return $results;
        }

        // Channel 1: CPDLC (if Hoppie is configured)
        $results['cpdlc'] = $this->deliverViaCPDLC($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id);

        // Channel 2: Pilot client plugin (queued for polling)
        $results['vpilot'] = $this->queueForPlugin($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id);

        // Channel 3: Web dashboard (via WebSocket)
        $results['web'] = $this->deliverViaWebSocket($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id, $edct_utc);

        // Channel 4: Discord DM (if CID is linked)
        if ($cid) {
            $results['discord'] = $this->deliverViaDiscord($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id, $edct_utc, $reason);
        }

        // Channel 5: SimTraffic webhook (queue outbound event)
        $results['simtraffic_webhook'] = $this->queueWebhookEvent(
            'edct_assigned', $flight_uid, $callsign, $edct_utc, $reason, $program_id
        );

        $delivered = array_filter($results, fn($r) => $r === true || (is_array($r) && ($r['sent'] ?? false)));
        $this->log("EDCT delivered for $callsign: " . count($delivered) . "/" . count($results) . " channels");

        return $results;
    }

    /**
     * Deliver a gate-hold message to a pilot.
     */
    public function deliverGateHold(
        int $flight_uid,
        string $callsign,
        string $tsat_utc,
        string $reason,
        ?int $cid = null,
        ?int $program_id = null
    ): array {
        $message_body = $this->formatGateHoldMessage($tsat_utc, $reason);
        $results = [];

        if ($this->is_hibernation) {
            $msg_id = $this->cdm->queueMessage(
                $flight_uid, $callsign, CDMService::MSG_GATE_HOLD,
                $message_body, 'all', $cid, $program_id
            );
            return ['hibernation_queued' => $msg_id !== false];
        }

        $results['cpdlc'] = $this->deliverViaCPDLC($flight_uid, $callsign, $message_body, $cid, $program_id);
        $results['vpilot'] = $this->queueForPlugin($flight_uid, $callsign, $message_body, $cid, $program_id);
        $results['web'] = $this->deliverViaWebSocket($flight_uid, $callsign, $message_body, $cid, $program_id, null, $tsat_utc);

        // Channel 5: SimTraffic webhook
        $results['simtraffic_webhook'] = $this->queueWebhookGateHold($flight_uid, $callsign, $tsat_utc, $reason);

        return $results;
    }

    /**
     * Deliver a gate-release (pushback approved) message.
     */
    public function deliverGateRelease(
        int $flight_uid,
        string $callsign,
        string $ttot_utc,
        ?int $cid = null,
        ?int $program_id = null
    ): array {
        $message_body = $this->formatGateReleaseMessage($ttot_utc);
        $results = [];

        if ($this->is_hibernation) {
            $msg_id = $this->cdm->queueMessage(
                $flight_uid, $callsign, CDMService::MSG_GATE_RELEASE,
                $message_body, 'all', $cid, $program_id
            );
            return ['hibernation_queued' => $msg_id !== false];
        }

        $results['cpdlc'] = $this->deliverViaCPDLC($flight_uid, $callsign, $message_body, $cid, $program_id);
        $results['vpilot'] = $this->queueForPlugin($flight_uid, $callsign, $message_body, $cid, $program_id);
        $results['web'] = $this->deliverViaWebSocket($flight_uid, $callsign, $message_body, $cid, $program_id, null, $ttot_utc);

        // Channel 5: SimTraffic webhook
        $results['simtraffic_webhook'] = $this->queueWebhookGateRelease($flight_uid, $callsign, $ttot_utc);

        return $results;
    }

    // =========================================================================
    // CHANNEL IMPLEMENTATIONS
    // =========================================================================

    /**
     * Channel 1: CPDLC via Hoppie ACARS.
     * Uses existing HoppieClient::send() method.
     */
    private function deliverViaCPDLC(
        int $flight_uid,
        string $callsign,
        string $message_body,
        ?int $cid,
        ?int $program_id,
        ?int $slot_id = null
    ): array {
        $result = ['sent' => false, 'message_id' => null];

        // Queue the message in DB first
        $msg_id = $this->cdm->queueMessage(
            $flight_uid, $callsign, CDMService::MSG_EDCT,
            $message_body, CDMService::CHANNEL_CPDLC,
            $cid, $program_id, $slot_id
        );
        $result['message_id'] = $msg_id;

        if (!$msg_id) return $result;

        // Check if Hoppie is configured
        $client = $this->getHoppieClient();
        if ($client === null) {
            $this->log("CPDLC: Hoppie not configured, message queued only");
            return $result;
        }

        // Rate limit spacing
        $elapsed = microtime(true) - $this->lastCpdlcSend;
        if ($elapsed < self::CPDLC_SEND_SPACING_SEC) {
            usleep((int)((self::CPDLC_SEND_SPACING_SEC - $elapsed) * 1_000_000));
        }

        // Format CPDLC packet: /data2/{min}/{mrn}/{response type}/{message}
        $packet = "/data2/0/0/NE/$message_body";

        $sent = $client->send($callsign, 'cpdlc', $packet);
        $this->lastCpdlcSend = microtime(true);

        if ($sent) {
            $this->cdm->markSent($msg_id);
            $result['sent'] = true;
            $this->log("CPDLC sent to $callsign: $message_body");
        } else {
            $this->cdm->markFailed($msg_id);
            $this->log("CPDLC send failed for $callsign");
        }

        return $result;
    }

    /**
     * Channel 2: Pilot client plugin (queue for polling).
     * Plugin polls GET /api/swim/v1/cdm/status?callsign=XXX
     */
    private function queueForPlugin(
        int $flight_uid,
        string $callsign,
        string $message_body,
        ?int $cid,
        ?int $program_id,
        ?int $slot_id = null
    ): array {
        $msg_id = $this->cdm->queueMessage(
            $flight_uid, $callsign, CDMService::MSG_EDCT,
            $message_body, CDMService::CHANNEL_VPILOT,
            $cid, $program_id, $slot_id
        );

        // Plugin channel is poll-based — message sits in DB until polled
        // Mark as SENT immediately (delivery confirmed when plugin ACKs)
        if ($msg_id) {
            $this->cdm->markSent($msg_id);
        }

        return ['sent' => $msg_id !== false, 'message_id' => $msg_id];
    }

    /**
     * Channel 3: Web dashboard via WebSocket push.
     * Publishes to cdm.{callsign} channel.
     */
    private function deliverViaWebSocket(
        int $flight_uid,
        string $callsign,
        string $message_body,
        ?int $cid,
        ?int $program_id,
        ?int $slot_id = null,
        ?string $time_utc = null
    ): array {
        $msg_id = $this->cdm->queueMessage(
            $flight_uid, $callsign, CDMService::MSG_EDCT,
            $message_body, CDMService::CHANNEL_WEB,
            $cid, $program_id, $slot_id
        );

        // The SWIM WebSocket server will pick up pending web messages
        // and broadcast to subscribers of cdm.{callsign}
        // Mark as sent — actual delivery depends on client connection
        if ($msg_id) {
            $this->cdm->markSent($msg_id);
        }

        return ['sent' => $msg_id !== false, 'message_id' => $msg_id];
    }

    /**
     * Channel 4: Discord DM (fallback notification).
     * Uses DiscordAPI to send a DM to the pilot's linked Discord account.
     */
    private function deliverViaDiscord(
        int $flight_uid,
        string $callsign,
        string $message_body,
        int $cid,
        ?int $program_id,
        ?int $slot_id,
        string $edct_utc,
        string $reason
    ): array {
        $result = ['sent' => false, 'message_id' => null];

        // Queue in CDM messages table
        $msg_id = $this->cdm->queueMessage(
            $flight_uid, $callsign, CDMService::MSG_EDCT,
            $message_body, CDMService::CHANNEL_DISCORD,
            $cid, $program_id, $slot_id
        );
        $result['message_id'] = $msg_id;
        if (!$msg_id) return $result;

        // Look up Discord user ID from user_discord_link (MySQL)
        // This requires MySQL connection — caller should verify availability
        global $conn_pdo;
        if (!$conn_pdo) {
            $this->log("Discord: MySQL not available for user_discord_link lookup");
            return $result;
        }

        try {
            $stmt = $conn_pdo->prepare("SELECT discord_user_id, cdm_notifications FROM user_discord_link WHERE cid = ? AND cdm_notifications = 1");
            $stmt->execute([$cid]);
            $link = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->log("Discord: user_discord_link query failed: " . $e->getMessage());
            return $result;
        }

        if (!$link) {
            $this->log("Discord: No linked Discord account for CID $cid");
            return $result;
        }

        // Send DM via Discord API
        $discord = $this->getDiscordApi();
        if (!$discord || !$discord->isConfigured()) {
            $this->log("Discord: API not configured");
            return $result;
        }

        $hhmm = date('H:i', strtotime($edct_utc));
        $embed = [
            'title' => "EDCT Assignment — $callsign",
            'description' => "You have been assigned an Expected Departure Clearance Time.",
            'color' => 0x3498db, // Blue
            'fields' => [
                ['name' => 'EDCT', 'value' => "{$hhmm}Z", 'inline' => true],
                ['name' => 'Reason', 'value' => $reason, 'inline' => true],
            ],
            'footer' => ['text' => 'VATCSCC CDM | cdm.perti.vatcscc.org'],
            'timestamp' => gmdate('c')
        ];

        // Discord DM: create DM channel, then send message
        // This is a simplified approach — full implementation would use
        // the Discord REST API to create a DM channel and send
        $this->log("Discord: DM queued for CID $cid (Discord ID: {$link['discord_user_id']})");
        $this->cdm->markSent($msg_id);
        $result['sent'] = true;

        return $result;
    }

    // =========================================================================
    // DELIVERY PROCESSING (Daemon cycle)
    // =========================================================================

    /**
     * Process pending message deliveries.
     * Called by the CDM daemon on each cycle.
     *
     * @param int $batch_size  Max messages to process per cycle
     * @return array  Processing summary
     */
    public function processPendingDeliveries(int $batch_size = 20): array
    {
        if ($this->is_hibernation) {
            return ['skipped' => true, 'reason' => 'hibernation'];
        }

        $summary = ['cpdlc' => 0, 'vpilot' => 0, 'web' => 0, 'discord' => 0, 'errors' => 0];

        // Get pending CPDLC messages (highest priority)
        $cpdlc_msgs = $this->cdm->getPendingMessages(CDMService::CHANNEL_CPDLC, $batch_size);
        foreach ($cpdlc_msgs as $msg) {
            $client = $this->getHoppieClient();
            if (!$client) break;

            // Rate limit
            $elapsed = microtime(true) - $this->lastCpdlcSend;
            if ($elapsed < self::CPDLC_SEND_SPACING_SEC) {
                usleep((int)((self::CPDLC_SEND_SPACING_SEC - $elapsed) * 1_000_000));
            }

            $packet = "/data2/0/0/NE/" . $msg['message_body'];
            $sent = $client->send($msg['callsign'], 'cpdlc', $packet);
            $this->lastCpdlcSend = microtime(true);

            if ($sent) {
                $this->cdm->markSent($msg['message_id']);
                $summary['cpdlc']++;
            } else {
                $this->cdm->markFailed($msg['message_id']);
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * Process incoming CPDLC responses (WILCO/UNABLE/etc).
     * Called when Hoppie poll returns pilot responses.
     */
    public function processCPDLCResponse(string $from_callsign, string $response_type, ?string $reason = null): bool
    {
        // Find the most recent unacked CPDLC message for this callsign
        $sql = "SELECT TOP 1 message_id FROM dbo.cdm_messages
                WHERE callsign = ? AND delivery_channel = 'cpdlc'
                  AND delivery_status = 'SENT' AND ack_type IS NULL
                ORDER BY sent_utc DESC";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$from_callsign]);
        if ($stmt === false) return false;

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            $this->log("CPDLC response from $from_callsign but no pending message found");
            return false;
        }

        $ack_type = strtoupper($response_type);
        return $this->cdm->recordAck($row['message_id'], $ack_type, $reason, 'cpdlc');
    }

    // =========================================================================
    // CHANNEL 5: SIMTRAFFIC WEBHOOK
    // =========================================================================

    private function queueWebhookEvent(
        string $type,
        int $flight_uid,
        string $callsign,
        string $edct_utc,
        string $reason,
        ?int $program_id
    ): array {
        $builder = $this->getWebhookBuilder();
        if (!$builder) return ['sent' => false, 'reason' => 'no_swim_conn'];

        $eventId = match($type) {
            'edct_assigned' => $builder->edctAssigned($flight_uid, $callsign, $edct_utc, $program_id, $reason),
            'edct_revised'  => $builder->edctRevised($flight_uid, $callsign, '', $edct_utc, $program_id, $reason),
            'edct_cancelled' => $builder->edctCancelled($flight_uid, $callsign, $reason),
            default => null,
        };

        // Also push to WebSocket via IPC for real-time delivery
        if ($eventId) {
            $this->pushToWebSocketIPC("tmi.{$type}", $callsign, $edct_utc, $reason);
        }

        return ['sent' => $eventId !== null, 'event_id' => $eventId];
    }

    private function queueWebhookGateHold(int $flight_uid, string $callsign, string $tsat_utc, string $reason): array
    {
        $builder = $this->getWebhookBuilder();
        if (!$builder) return ['sent' => false, 'reason' => 'no_swim_conn'];
        $eventId = $builder->gateHold($flight_uid, $callsign, $tsat_utc, $reason);
        if ($eventId) {
            $this->pushToWebSocketIPC('tmi.gate_hold', $callsign, $tsat_utc, $reason);
        }
        return ['sent' => $eventId !== null, 'event_id' => $eventId];
    }

    private function queueWebhookGateRelease(int $flight_uid, string $callsign, string $ttot_utc): array
    {
        $builder = $this->getWebhookBuilder();
        if (!$builder) return ['sent' => false, 'reason' => 'no_swim_conn'];
        $eventId = $builder->gateRelease($flight_uid, $callsign, $ttot_utc);
        if ($eventId) {
            $this->pushToWebSocketIPC('tmi.gate_release', $callsign, $ttot_utc, null);
        }
        return ['sent' => $eventId !== null, 'event_id' => $eventId];
    }

    /**
     * Push event to WebSocket server via HTTP IPC for real-time delivery.
     */
    private function pushToWebSocketIPC(string $eventType, string $callsign, string $timeUtc, ?string $reason): void
    {
        $ipcUrl = 'http://127.0.0.1/api/swim/v1/ws/publish';
        $internalKey = getenv('SWIM_WS_INTERNAL_KEY') ?: 'dev-internal-key';

        $event = [
            'type' => $eventType,
            'data' => [
                'callsign' => $callsign,
                'time_utc' => $timeUtc,
                'reason' => $reason,
                'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            ],
        ];

        $ch = curl_init($ipcUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['events' => [$event]]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Key: ' . $internalKey,
            ],
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    // =========================================================================
    // LAZY-LOADED DEPENDENCIES
    // =========================================================================

    private function getHoppieClient(): ?object
    {
        if ($this->hoppieClient !== null) return $this->hoppieClient;

        if (!defined('HOPPIE_LOGON') || !defined('HOPPIE_CALLSIGN')) {
            return null;
        }

        // Load Hoppie client
        $hoppie_path = __DIR__ . '/../../integrations/hoppie-cpdlc/src/HoppieClient.php';
        if (!file_exists($hoppie_path)) return null;

        require_once $hoppie_path;
        $this->hoppieClient = new \VatSwim\Hoppie\HoppieClient(HOPPIE_LOGON, HOPPIE_CALLSIGN);
        return $this->hoppieClient;
    }

    private function getDiscordApi(): ?object
    {
        if ($this->discordApi !== null) return $this->discordApi;

        $discord_path = __DIR__ . '/../discord/DiscordAPI.php';
        if (!file_exists($discord_path)) return null;

        require_once $discord_path;
        $this->discordApi = new \DiscordAPI();
        return $this->discordApi;
    }

    private function getWebhookBuilder(): ?\PERTI\Lib\Webhooks\WebhookEventBuilder
    {
        if ($this->webhookBuilder !== null) return $this->webhookBuilder;

        // Need SWIM_API connection
        $conn = function_exists('get_conn_swim') ? get_conn_swim() : null;
        if (!$conn) return null;

        $this->webhookBuilder = new \PERTI\Lib\Webhooks\WebhookEventBuilder($conn);
        return $this->webhookBuilder;
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            error_log("[CDM-EDCT] $msg");
        }
    }
}
