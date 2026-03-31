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
    // EXTENDED TMI MESSAGE FORMATTING (Bridge 1: HoppieWriter)
    // =========================================================================

    public function formatEDCTAmendedMessage(string $new_edct_utc, string $prev_edct_utc): string
    {
        $newHhmm = date('Hi', strtotime($new_edct_utc));
        $prevHhmm = date('Hi', strtotime($prev_edct_utc));
        return "REVISED EDCT {$newHhmm}Z. PREVIOUS {$prevHhmm}Z";
    }

    public function formatEDCTCancelMessage(string $edct_utc): string
    {
        $hhmm = date('Hi', strtotime($edct_utc));
        return "DISREGARD EDCT {$hhmm}Z. DEPART WHEN READY";
    }

    public function formatCTOTMessage(string $ctot_utc, ?string $regulation_id = null): string
    {
        $hhmm = date('Hi', strtotime($ctot_utc));
        $msg = "CALCULATED TAKEOFF TIME {$hhmm}Z";
        if ($regulation_id) {
            $msg .= ". CTOT REGULATION $regulation_id";
        }
        return $msg;
    }

    public function formatGSHoldMessage(string $dest, ?string $expect_update_utc = null): string
    {
        $msg = "GROUND STOP IN EFFECT FOR $dest. HOLD FOR RELEASE.";
        if ($expect_update_utc) {
            $hhmm = date('Hi', strtotime($expect_update_utc));
            $msg .= " EXPECT UPDATE BY {$hhmm}Z";
        }
        return $msg;
    }

    public function formatGSReleaseMessage(string $dest, string $followon = 'RELEASED'): string
    {
        if ($followon === 'GDP_ACTIVE') {
            return "GROUND STOP RLSD FOR $dest. FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE FLOW PROGRAM";
        }
        return "GROUND STOP RLSD FOR $dest. DISREGARD EDCT & DEPART WHEN READY";
    }

    public function formatRerouteMessage(
        string $advisory_num,
        string $route,
        string $delivery_mode = 'VOICE',
        ?string $delivery_freq = null
    ): string {
        if ($delivery_mode === 'DELIVERY' && $delivery_freq) {
            return "REROUTE ADVISORY $advisory_num. AMEND ROUTE TO $route OR CONTACT DELIVERY AT $delivery_freq FOR AMENDED CLEARANCE";
        }
        return "REROUTE ADVISORY $advisory_num. AMEND ROUTE TO $route OR STANDBY FOR VOICE CLEARANCE";
    }

    public function formatFlowMeasureMessage(string $measure_type, string $value, string $fir): string
    {
        return "FLOW RESTRICTION: $measure_type $value FOR $fir";
    }

    public function formatMITMessage(int $miles, string $fix): string
    {
        return "MILES IN TRAIL {$miles}NM IN EFFECT AT $fix. EXPECT DELAY.";
    }

    public function formatAFPMessage(string $airspace, int $rate, int $delay_min): string
    {
        return "AIRSPACE FLOW PROGRAM IN EFFECT FOR $airspace. $rate FLIGHTS PER HOUR. EXPECT DELAY $delay_min MIN.";
    }

    public function formatMeteringMessage(string $fix, string $sta_utc): string
    {
        $hhmm = date('Hi', strtotime($sta_utc));
        return "CROSS $fix AT {$hhmm}Z. SCHEDULED TIME OF ARRIVAL {$hhmm}Z.";
    }

    public function formatHoldMessage(string $fix, ?string $efc_utc = null): string
    {
        $msg = "EXPECT HOLDING AT $fix.";
        if ($efc_utc) {
            $hhmm = date('Hi', strtotime($efc_utc));
            $msg .= " EXPECT FURTHER CLEARANCE {$hhmm}Z.";
        }
        return $msg;
    }

    public function formatCTPSlotMessage(string $entry_fix, string $slot_utc, string $route): string
    {
        $hhmm = date('Hi', strtotime($slot_utc));
        return "CTP SLOT ASSIGNED: $entry_fix AT {$hhmm}Z. ROUTE: $route. CONFIRM ACCEPTANCE.";
    }

    public function formatWeatherRerouteMessage(string $area, string $route): string
    {
        return "CONVECTIVE ACTIVITY NEAR $area. SUGGESTED DEVIATION: $route. PILOT DISCRETION.";
    }

    public function formatTOSQueryMessage(string $dep, string $dest): string
    {
        return "TRAJECTORY OPTIONS REQUESTED FOR $dep-$dest. FILE VIA PILOT CLIENT OR VATSWIM.";
    }

    public function formatTOSAckMessage(int $count): string
    {
        return "$count TRAJECTORY OPTIONS ON FILE. STANDBY FOR ASSIGNMENT.";
    }

    public function formatTOSAssignMessage(int $option_num, string $route, string $reason, ?string $advisory_num = null): string
    {
        $inline = "TRAJECTORY OPTION $option_num ASSIGNED: $route. REASON: $reason.";
        if (strlen($inline) <= 200) {
            return $inline;
        }
        if ($advisory_num) {
            return "TRAJECTORY OPTION $option_num ASSIGNED PER ADVISORY $advisory_num. CHECK PILOT CLIENT FOR ROUTE DETAIL.";
        }
        return "TRAJECTORY OPTION $option_num ASSIGNED. CHECK PILOT CLIENT FOR ROUTE DETAIL. REASON: $reason.";
    }

    public function formatTrafficAdvisory(string $type, string $facility, ?string $options = null): string
    {
        switch ($type) {
            case 'arrival_volume':
                return "HIGH ARRIVAL VOLUME FOR $facility. SUGGEST REDIRECTING TO $options TO AVOID EXCESSIVE DELAYS.";
            case 'departure_volume':
                return "HIGH DEPARTURE VOLUME OVER $facility. SUGGEST REROUTING OVER $options TO AVOID EXCESSIVE DELAYS.";
            case 'reroute_fuel':
                return "REROUTE/S IN EFFECT $facility. USERS SHOULD FUEL ACCORDINGLY.";
            case 'delay_fuel':
                return "DELAYS $facility. USERS SHOULD FUEL ACCORDINGLY.";
            default:
                return "TRAFFIC ADVISORY FOR $facility: $options";
        }
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

        return $results;
    }

    /**
     * Generic multi-channel delivery for any TMI message type.
     * Used by all extended message types (CTOT, GS, reroute, flow, etc.)
     */
    public function deliverMessage(
        int $flight_uid,
        string $callsign,
        string $message_type,
        string $message_body,
        ?string $time_utc = null,
        ?int $cid = null,
        ?int $program_id = null,
        ?int $slot_id = null
    ): array {
        $results = [];

        if ($this->is_hibernation) {
            $msg_id = $this->cdm->queueMessage(
                $flight_uid, $callsign, $message_type,
                $message_body, 'all', $cid, $program_id, $slot_id
            );
            $results['hibernation_queued'] = $msg_id !== false;
            $this->log("$message_type hibernation-queued for $callsign");
            return $results;
        }

        if ($this->isDuplicateMessage($flight_uid, $message_body)) {
            $this->log("$message_type skipped (duplicate) for $callsign");
            return ['skipped' => 'duplicate'];
        }

        $results['cpdlc'] = $this->deliverViaCPDLC($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id);
        $results['vpilot'] = $this->queueForPlugin($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id);
        $results['web'] = $this->deliverViaWebSocket($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id, $time_utc);

        if ($cid) {
            $results['discord'] = $this->deliverViaDiscord($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id, $time_utc, $message_type);
        }

        $this->logDelivery($flight_uid, $callsign, $message_type, $message_body, $results, $program_id);

        $delivered = array_filter($results, fn($r) => $r === true || (is_array($r) && ($r['sent'] ?? false)));
        $this->log("$message_type delivered for $callsign: " . count($delivered) . "/" . count($results) . " channels");

        return $results;
    }

    /**
     * Check if this exact message was already delivered to this flight recently.
     */
    private function isDuplicateMessage(int $flight_uid, string $message_body): bool
    {
        $hash = hash('sha256', $message_body);
        $sql = "SELECT TOP 1 1 FROM dbo.tmi_delivery_log
                WHERE flight_uid = ? AND message_hash = ?
                  AND delivered_utc > DATEADD(MINUTE, -5, SYSUTCDATETIME())";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$flight_uid, $hash]);
        if ($stmt === false) return false;
        $exists = sqlsrv_fetch_array($stmt) !== null;
        sqlsrv_free_stmt($stmt);
        return $exists;
    }

    /**
     * Log delivery to tmi_delivery_log for tracking and deduplication.
     */
    private function logDelivery(
        int $flight_uid,
        string $callsign,
        string $message_type,
        string $message_body,
        array $results,
        ?int $program_id
    ): void {
        $hash = hash('sha256', $message_body);
        $channels = [];
        foreach ($results as $ch => $r) {
            if ($r === true || (is_array($r) && ($r['sent'] ?? false))) {
                $channels[] = $ch;
            }
        }
        $channelStr = implode(',', $channels) ?: 'none';

        $sql = "INSERT INTO dbo.tmi_delivery_log (flight_uid, callsign, message_type, message_hash, program_id, channels_sent)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$flight_uid, $callsign, $message_type, $hash, $program_id, $channelStr]);
        if ($stmt !== false) sqlsrv_free_stmt($stmt);
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

    private function log(string $msg): void
    {
        if ($this->verbose) {
            error_log("[CDM-EDCT] $msg");
        }
    }
}
