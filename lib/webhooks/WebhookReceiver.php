<?php
/**
 * WebhookReceiver — HMAC verification, timestamp validation, and idempotency.
 *
 * Verifies inbound webhook signatures, checks timestamp freshness,
 * and deduplicates events by event_id against swim_webhook_events.
 *
 * @package PERTI\Lib\Webhooks
 */

namespace PERTI\Lib\Webhooks;

class WebhookReceiver
{
    private $conn;
    private string $sharedSecret;
    private int $maxTimestampAge;
    private int $dedupWindowHours;

    /**
     * @param resource $conn           sqlsrv connection to SWIM_API
     * @param string   $sharedSecret   HMAC shared secret for this subscription
     * @param int      $maxTimestampAge Max age in seconds for replay protection (default 300)
     * @param int      $dedupWindowHours Dedup window in hours (default 24)
     */
    public function __construct($conn, string $sharedSecret, int $maxTimestampAge = 300, int $dedupWindowHours = 24)
    {
        $this->conn = $conn;
        $this->sharedSecret = $sharedSecret;
        $this->maxTimestampAge = $maxTimestampAge;
        $this->dedupWindowHours = $dedupWindowHours;
    }

    /**
     * Verify HMAC signature and timestamp from request headers.
     *
     * @param string $signatureHeader  Value of X-SimTraffic-Signature header (e.g., "sha256=abc123...")
     * @param string $timestampHeader  Value of X-SimTraffic-Timestamp header (unix epoch string)
     * @param string $rawBody          Raw request body (unmodified)
     * @return array{valid: bool, error: ?string}
     */
    public function verify(string $signatureHeader, string $timestampHeader, string $rawBody): array
    {
        // Check timestamp freshness
        $timestamp = (int)$timestampHeader;
        if ($timestamp <= 0) {
            return ['valid' => false, 'error' => 'Invalid timestamp'];
        }

        $age = abs(time() - $timestamp);
        if ($age > $this->maxTimestampAge) {
            return ['valid' => false, 'error' => "Timestamp too old ({$age}s > {$this->maxTimestampAge}s)"];
        }

        // Verify HMAC signature
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return ['valid' => false, 'error' => 'Invalid signature format (expected sha256=...)'];
        }

        $providedHash = substr($signatureHeader, 7); // strip "sha256="
        $signingPayload = $timestampHeader . '.' . $rawBody;
        $expectedHash = hash_hmac('sha256', $signingPayload, $this->sharedSecret);

        if (!hash_equals($expectedHash, $providedHash)) {
            return ['valid' => false, 'error' => 'Signature mismatch'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Check if an event_id has already been processed (dedup).
     *
     * @param string $eventId UUID event identifier
     * @return bool True if this event is a duplicate
     */
    public function isDuplicate(string $eventId): bool
    {
        $sql = "SELECT 1 FROM dbo.swim_webhook_events
                WHERE event_id = ? AND direction = 'inbound'
                  AND created_utc > DATEADD(HOUR, ?, SYSUTCDATETIME())";
        $stmt = sqlsrv_query($this->conn, $sql, [$eventId, -$this->dedupWindowHours]);
        if ($stmt === false) {
            error_log("[WebhookReceiver] isDuplicate query failed: " . json_encode(sqlsrv_errors()));
            return false; // On error, allow processing (at-least-once)
        }
        $exists = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC) !== null;
        sqlsrv_free_stmt($stmt);
        return $exists;
    }

    /**
     * Log an inbound event to swim_webhook_events for dedup tracking.
     *
     * @param string      $eventId
     * @param string      $eventType
     * @param string      $sourceId
     * @param string      $sourceChannel  'rest' or 'ws'
     * @param string|null $payload        JSON payload (optional, for auditing)
     * @param int|null    $flightUid
     * @param string|null $callsign
     * @return bool
     */
    public function logInboundEvent(
        string $eventId,
        string $eventType,
        string $sourceId,
        string $sourceChannel = 'rest',
        ?string $payload = null,
        ?int $flightUid = null,
        ?string $callsign = null
    ): bool {
        $sql = "INSERT INTO dbo.swim_webhook_events
                    (event_id, event_type, direction, source_id, source_channel,
                     payload, status, delivered_utc, flight_uid, callsign)
                VALUES (?, ?, 'inbound', ?, ?, ?, 'delivered', SYSUTCDATETIME(), ?, ?)";

        $params = [$eventId, $eventType, $sourceId, $sourceChannel, $payload, $flightUid, $callsign];
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            error_log("[WebhookReceiver] Failed to log inbound event {$eventId}: " . json_encode(sqlsrv_errors()));
            return false;
        }
        sqlsrv_free_stmt($stmt);
        return true;
    }

    /**
     * Load shared secret for a source from swim_webhook_subscriptions.
     *
     * @param resource $conn      sqlsrv connection
     * @param string   $sourceId  Source identifier (e.g., 'simtraffic')
     * @param string   $direction 'inbound' or 'outbound'
     * @return string|null
     */
    public static function loadSecret($conn, string $sourceId, string $direction): ?string
    {
        $sql = "SELECT shared_secret FROM dbo.swim_webhook_subscriptions
                WHERE source_id = ? AND direction = ? AND is_active = 1";
        $stmt = sqlsrv_query($conn, $sql, [$sourceId, $direction]);
        if ($stmt === false) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row['shared_secret'] ?? null;
    }
}
