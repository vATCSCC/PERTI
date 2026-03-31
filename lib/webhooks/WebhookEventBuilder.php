<?php
/**
 * WebhookEventBuilder — Constructs event envelopes for outbound webhook dispatch.
 *
 * Builds standardized event payloads from TMI/CDM actions and queues
 * them in swim_webhook_events for the delivery daemon.
 *
 * @package PERTI\Lib\Webhooks
 */

namespace PERTI\Lib\Webhooks;

class WebhookEventBuilder
{
    private $conn;
    private string $sourceId;

    /**
     * @param resource $conn     sqlsrv connection to SWIM_API
     * @param string   $sourceId Target source (default 'simtraffic')
     */
    public function __construct($conn, string $sourceId = 'simtraffic')
    {
        $this->conn = $conn;
        $this->sourceId = $sourceId;
    }

    /**
     * Build and queue a tmi.edct_assigned event.
     */
    public function edctAssigned(
        int $flightUid,
        string $callsign,
        string $edctUtc,
        ?int $programId = null,
        ?string $reason = null
    ): ?string {
        return $this->queueEvent('tmi.edct_assigned', $flightUid, $callsign, [
            'callsign' => $callsign,
            'edct_utc' => $edctUtc,
            'program_id' => $programId,
            'reason' => $reason,
        ]);
    }

    /**
     * Build and queue a tmi.edct_revised event.
     */
    public function edctRevised(
        int $flightUid,
        string $callsign,
        string $oldEdctUtc,
        string $newEdctUtc,
        ?int $programId = null,
        ?string $reason = null
    ): ?string {
        return $this->queueEvent('tmi.edct_revised', $flightUid, $callsign, [
            'callsign' => $callsign,
            'old_edct_utc' => $oldEdctUtc,
            'new_edct_utc' => $newEdctUtc,
            'program_id' => $programId,
            'reason' => $reason,
        ]);
    }

    /**
     * Build and queue a tmi.edct_cancelled event.
     */
    public function edctCancelled(
        int $flightUid,
        string $callsign,
        ?string $reason = null
    ): ?string {
        return $this->queueEvent('tmi.edct_cancelled', $flightUid, $callsign, [
            'callsign' => $callsign,
            'reason' => $reason,
        ]);
    }

    /**
     * Build and queue a tmi.ground_stop event.
     */
    public function groundStop(
        string $airport,
        string $startUtc,
        ?string $endUtc = null,
        ?string $reason = null
    ): ?string {
        return $this->queueEvent('tmi.ground_stop', null, null, [
            'airport' => $airport,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'reason' => $reason,
        ]);
    }

    /**
     * Build and queue a tmi.ground_stop_lifted event.
     */
    public function groundStopLifted(
        string $airport,
        string $endUtc
    ): ?string {
        return $this->queueEvent('tmi.ground_stop_lifted', null, null, [
            'airport' => $airport,
            'end_utc' => $endUtc,
        ]);
    }

    /**
     * Build and queue a tmi.reroute event.
     */
    public function reroute(
        int $flightUid,
        string $callsign,
        string $newRoute,
        ?string $reason = null
    ): ?string {
        return $this->queueEvent('tmi.reroute', $flightUid, $callsign, [
            'callsign' => $callsign,
            'new_route' => $newRoute,
            'reason' => $reason,
        ]);
    }

    /**
     * Build and queue a tmi.gate_hold event.
     */
    public function gateHold(
        int $flightUid,
        string $callsign,
        string $tsatUtc,
        ?string $reason = null
    ): ?string {
        return $this->queueEvent('tmi.gate_hold', $flightUid, $callsign, [
            'callsign' => $callsign,
            'tsat_utc' => $tsatUtc,
            'reason' => $reason,
        ]);
    }

    /**
     * Build and queue a tmi.gate_release event.
     */
    public function gateRelease(
        int $flightUid,
        string $callsign,
        string $ttotUtc
    ): ?string {
        return $this->queueEvent('tmi.gate_release', $flightUid, $callsign, [
            'callsign' => $callsign,
            'ttot_utc' => $ttotUtc,
        ]);
    }

    /**
     * Build event envelope and insert into swim_webhook_events queue.
     *
     * @return string|null event_id on success, null on failure
     */
    private function queueEvent(string $eventType, ?int $flightUid, ?string $callsign, array $data): ?string
    {
        $eventId = 'evt_' . bin2hex(random_bytes(16));

        $envelope = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'source' => 'vatswim',
            'data' => $data,
        ];

        $payload = json_encode($envelope);

        $sql = "INSERT INTO dbo.swim_webhook_events
                    (event_id, event_type, direction, source_id, source_channel,
                     payload, status, flight_uid, callsign)
                VALUES (?, ?, 'outbound', ?, 'rest', ?, 'pending', ?, ?)";

        $params = [$eventId, $eventType, $this->sourceId, $payload, $flightUid, $callsign];
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            error_log("[WebhookEventBuilder] Failed to queue {$eventType}: " . json_encode(sqlsrv_errors()));
            return null;
        }
        sqlsrv_free_stmt($stmt);

        return $eventId;
    }
}
