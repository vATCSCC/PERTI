# SimTraffic-VATSWIM Webhook Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace SimTraffic per-flight polling with bidirectional event-driven webhooks + WebSocket, covering full gate-to-gate lifecycle events inbound and TMI control events outbound.

**Architecture:** REST webhook endpoints on both sides for guaranteed at-least-once delivery, with WebSocket as a supplementary low-latency channel. Outbound events queue in `swim_webhook_events` and are dispatched by a delivery daemon. Inbound events are deduplicated by `event_id` and processed through the existing `processSimTrafficFlight()` function. The polling daemon is demoted to a 10-minute reconciliation fallback.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv), Ratchet WebSocket, HMAC-SHA256 signatures

**Spec:** `docs/superpowers/specs/2026-03-30-simtraffic-vatswim-webhook-bridge-design.md`

---

## File Map

### New Files

| File | Responsibility |
|------|---------------|
| `database/migrations/swim/034_swim_webhook_tables.sql` | Schema: two new tables + indexes |
| `lib/webhooks/WebhookReceiver.php` | HMAC verification, timestamp check, idempotency dedup |
| `lib/webhooks/WebhookSender.php` | Outbound dispatch, HMAC signing, retry logic |
| `lib/webhooks/WebhookEventBuilder.php` | Build event envelopes from TMI/CDM actions |
| `api/swim/v1/webhooks/simtraffic.php` | Inbound webhook REST endpoint |
| `api/swim/v1/webhooks/register.php` | Webhook subscription CRUD endpoint |
| `scripts/webhook_delivery_daemon.php` | Outbound event queue processor (10s cycle) |

### Modified Files

| File | Changes |
|------|---------|
| `lib/connectors/sources/SimTrafficConnector.php` | Add webhook endpoints, update type metadata |
| `load/services/EDCTDelivery.php` | Add Channel 5: SimTraffic webhook event queuing |
| `api/swim/v1/ws/WebSocketServer.php` | Add `publish` action handler for inbound lifecycle events |
| `api/swim/v1/ingest/simtraffic.php` | Add deprecation header |
| `load/swim_config.php` | Add webhook constants |
| `scripts/archival_daemon.php` | Add webhook event purge step |
| `scripts/simtraffic_swim_poll.php` | Change default interval from 120s to 600s |
| `scripts/startup.sh` | Add webhook_delivery_daemon to daemon list |

---

## Task 1: Database Migration

**Files:**
- Create: `database/migrations/swim/034_swim_webhook_tables.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Migration 034: Webhook tables for SimTraffic-VATSWIM bidirectional event bridge
-- Database: SWIM_API
-- Date: 2026-03-30

-- ============================================================================
-- Table: swim_webhook_subscriptions
-- Stores webhook endpoint registrations (inbound + outbound)
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_webhook_subscriptions')
BEGIN
    CREATE TABLE dbo.swim_webhook_subscriptions (
        id                   INT IDENTITY(1,1) PRIMARY KEY,
        source_id            VARCHAR(32)   NOT NULL,
        direction            VARCHAR(8)    NOT NULL,
        callback_url         VARCHAR(512)  NOT NULL,
        shared_secret        VARCHAR(128)  NOT NULL,
        event_types          VARCHAR(MAX)  NULL,
        is_active            BIT           NOT NULL DEFAULT 1,
        created_utc          DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc          DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        last_success_utc     DATETIME2     NULL,
        last_failure_utc     DATETIME2     NULL,
        consecutive_failures INT           NOT NULL DEFAULT 0
    );

    CREATE INDEX IX_webhook_subs_source
        ON dbo.swim_webhook_subscriptions (source_id, direction, is_active);
END;

-- ============================================================================
-- Table: swim_webhook_events
-- Event queue (outbound) + dedup log (inbound), 30-day retention
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_webhook_events')
BEGIN
    CREATE TABLE dbo.swim_webhook_events (
        event_id         VARCHAR(64)   NOT NULL PRIMARY KEY,
        event_type       VARCHAR(64)   NOT NULL,
        direction        VARCHAR(8)    NOT NULL,
        source_id        VARCHAR(32)   NOT NULL,
        source_channel   VARCHAR(8)    NOT NULL DEFAULT 'rest',
        payload          NVARCHAR(MAX) NULL,
        status           VARCHAR(16)   NOT NULL DEFAULT 'pending',
        attempts         INT           NOT NULL DEFAULT 0,
        next_retry_utc   DATETIME2     NULL,
        created_utc      DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        delivered_utc    DATETIME2     NULL,
        flight_uid       BIGINT        NULL,
        callsign         VARCHAR(16)   NULL
    );

    -- Outbound delivery queue (split into two — SQL Server filtered indexes cannot use OR/IN)
    CREATE INDEX IX_webhook_events_pending
        ON dbo.swim_webhook_events (next_retry_utc)
        INCLUDE (event_id, event_type, source_id, payload, attempts)
        WHERE status = 'pending';

    CREATE INDEX IX_webhook_events_sent
        ON dbo.swim_webhook_events (next_retry_utc)
        INCLUDE (event_id, event_type, source_id, payload, attempts)
        WHERE status = 'sent';

    -- Purge by age
    CREATE INDEX IX_webhook_events_created
        ON dbo.swim_webhook_events (created_utc);

    -- Inbound dedup lookup
    CREATE INDEX IX_webhook_events_dedup
        ON dbo.swim_webhook_events (event_id, created_utc)
        WHERE direction = 'inbound';
END;

-- ============================================================================
-- Seed: SimTraffic webhook subscriptions
-- shared_secret values are placeholders — replace before production use
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM dbo.swim_webhook_subscriptions WHERE source_id = 'simtraffic')
BEGIN
    -- Inbound: SimTraffic pushes lifecycle events to us
    INSERT INTO dbo.swim_webhook_subscriptions
        (source_id, direction, callback_url, shared_secret, event_types)
    VALUES
        ('simtraffic', 'inbound', '/api/swim/v1/webhooks/simtraffic', 'REPLACE_WITH_SHARED_SECRET_INBOUND', '*');

    -- Outbound: We push TMI events to SimTraffic
    INSERT INTO dbo.swim_webhook_subscriptions
        (source_id, direction, callback_url, shared_secret, event_types)
    VALUES
        ('simtraffic', 'outbound', 'https://hooks.simtraffic.net/vatswim', 'REPLACE_WITH_SHARED_SECRET_OUTBOUND', '*');
END;
```

- [ ] **Step 2: Apply migration to SWIM_API**

Run against SWIM_API database using `jpeterson` admin credentials (adl_api_user lacks CREATE TABLE):

```bash
sqlcmd -S vatsim.database.windows.net -d SWIM_API -U jpeterson -P Jhp21012 -i database/migrations/swim/034_swim_webhook_tables.sql
```

Expected: Tables created, 2 seed rows inserted, no errors.

- [ ] **Step 3: Verify tables exist**

```sql
SELECT name FROM SWIM_API.sys.tables WHERE name LIKE 'swim_webhook%' ORDER BY name;
-- Expected: swim_webhook_events, swim_webhook_subscriptions

SELECT * FROM dbo.swim_webhook_subscriptions;
-- Expected: 2 rows (inbound + outbound for simtraffic)
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/swim/034_swim_webhook_tables.sql
git commit -m "feat(swim): add webhook tables for SimTraffic event bridge (migration 034)"
```

---

## Task 2: WebhookReceiver Library

**Files:**
- Create: `lib/webhooks/WebhookReceiver.php`

- [ ] **Step 1: Create the WebhookReceiver class**

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add lib/webhooks/WebhookReceiver.php
git commit -m "feat(webhooks): add WebhookReceiver with HMAC verify and idempotency"
```

---

## Task 3: WebhookSender Library

**Files:**
- Create: `lib/webhooks/WebhookSender.php`

- [ ] **Step 1: Create the WebhookSender class**

```php
<?php
/**
 * WebhookSender — Outbound webhook dispatch with HMAC signing and retry.
 *
 * Dispatches queued events from swim_webhook_events to registered callback URLs.
 * Signs payloads with HMAC-SHA256 and handles retries with exponential backoff.
 *
 * @package PERTI\Lib\Webhooks
 */

namespace PERTI\Lib\Webhooks;

use PERTI\Lib\Connectors\CircuitBreaker;

class WebhookSender
{
    private $conn;
    private CircuitBreaker $circuitBreaker;
    private array $retryIntervals;
    private int $batchSize;
    private bool $verbose;

    /**
     * @param resource       $conn             sqlsrv connection to SWIM_API
     * @param CircuitBreaker $circuitBreaker   Circuit breaker instance
     * @param array          $retryIntervals   Seconds between retries [10, 30, 90]
     * @param int            $batchSize        Max events per dispatch cycle
     * @param bool           $verbose          Enable debug logging
     */
    public function __construct(
        $conn,
        CircuitBreaker $circuitBreaker,
        array $retryIntervals = [10, 30, 90],
        int $batchSize = 50,
        bool $verbose = false
    ) {
        $this->conn = $conn;
        $this->circuitBreaker = $circuitBreaker;
        $this->retryIntervals = $retryIntervals;
        $this->batchSize = $batchSize;
        $this->verbose = $verbose;
    }

    /**
     * Process pending outbound events from the queue.
     *
     * @return array{dispatched: int, failed: int, dead: int, skipped_circuit: bool}
     */
    public function processPendingEvents(): array
    {
        $result = ['dispatched' => 0, 'failed' => 0, 'dead' => 0, 'skipped_circuit' => false];

        if ($this->circuitBreaker->isOpen()) {
            $result['skipped_circuit'] = true;
            $this->log("Circuit breaker OPEN — skipping dispatch");
            return $result;
        }

        // Fetch pending events ready for dispatch
        $sql = "SELECT TOP (?) event_id, event_type, source_id, payload, attempts
                FROM dbo.swim_webhook_events
                WHERE direction = 'outbound'
                  AND status IN ('pending', 'sent')
                  AND (next_retry_utc IS NULL OR next_retry_utc <= SYSUTCDATETIME())
                ORDER BY created_utc ASC";

        $stmt = sqlsrv_query($this->conn, $sql, [$this->batchSize]);
        if ($stmt === false) {
            $this->log("ERROR: Failed to fetch pending events: " . json_encode(sqlsrv_errors()));
            return $result;
        }

        $events = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $events[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        if (empty($events)) return $result;

        // Group events by source_id for batched dispatch
        $grouped = [];
        foreach ($events as $event) {
            $grouped[$event['source_id']][] = $event;
        }

        foreach ($grouped as $sourceId => $sourceEvents) {
            $sub = $this->loadSubscription($sourceId, 'outbound');
            if (!$sub) {
                $this->log("No active outbound subscription for source '{$sourceId}'");
                continue;
            }

            $dispatchResult = $this->dispatch($sub, $sourceEvents);
            $result['dispatched'] += $dispatchResult['dispatched'];
            $result['failed'] += $dispatchResult['failed'];
            $result['dead'] += $dispatchResult['dead'];
        }

        return $result;
    }

    /**
     * Dispatch a batch of events to a webhook endpoint.
     *
     * @param array $subscription  Row from swim_webhook_subscriptions
     * @param array $events        Array of event rows
     * @return array{dispatched: int, failed: int, dead: int}
     */
    private function dispatch(array $subscription, array $events): array
    {
        $result = ['dispatched' => 0, 'failed' => 0, 'dead' => 0];

        $callbackUrl = $subscription['callback_url'];
        $secret = $subscription['shared_secret'];
        $subId = $subscription['id'];

        // Build batch payload
        $payloads = [];
        foreach ($events as $event) {
            $decoded = json_decode($event['payload'], true);
            if ($decoded !== null) {
                $payloads[] = $decoded;
            }
        }

        $body = json_encode([
            'batch_id' => 'batch_' . bin2hex(random_bytes(8)),
            'events' => $payloads,
            'count' => count($payloads),
        ]);

        // Sign the payload
        $timestamp = (string)time();
        $signingPayload = $timestamp . '.' . $body;
        $signature = 'sha256=' . hash_hmac('sha256', $signingPayload, $secret);

        // Send HTTP request
        $ch = curl_init($callbackUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-VATSWIM-Signature: ' . $signature,
                'X-VATSWIM-Timestamp: ' . $timestamp,
                'User-Agent: VATSWIM/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log("CURL error dispatching to {$callbackUrl}: {$curlError}");
            $this->handleBatchFailure($events, $subId);
            $this->circuitBreaker->recordError();
            $result['failed'] = count($events);
            return $result;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            // Success
            $this->markEventsDelivered($events);
            $this->updateSubscriptionSuccess($subId);
            $this->circuitBreaker->recordSuccess();
            $result['dispatched'] = count($events);
            $this->log("Dispatched " . count($events) . " events to {$callbackUrl} (HTTP {$httpCode})");
        } elseif ($httpCode >= 500) {
            // Server error — retry + circuit breaker
            $this->handleBatchFailure($events, $subId);
            $tripped = $this->circuitBreaker->recordError();
            $result['failed'] = count($events);
            if ($tripped) {
                $this->log("Circuit breaker TRIPPED after 5xx from {$callbackUrl}");
            }
        } else {
            // 4xx — likely a persistent problem, still retry but don't trip circuit
            $this->handleBatchFailure($events, $subId);
            $result['failed'] = count($events);
            $this->log("HTTP {$httpCode} from {$callbackUrl}: {$response}");
        }

        return $result;
    }

    /**
     * Handle failure for a batch of events — increment attempts, schedule retry or dead-letter.
     */
    private function handleBatchFailure(array $events, int $subscriptionId): void
    {
        foreach ($events as $event) {
            $attempts = ($event['attempts'] ?? 0) + 1;
            $maxRetries = count($this->retryIntervals);

            if ($attempts > $maxRetries) {
                // Dead letter
                $sql = "UPDATE dbo.swim_webhook_events SET status = 'dead', attempts = ? WHERE event_id = ?";
                sqlsrv_query($this->conn, $sql, [$attempts, $event['event_id']]);
                $this->log("Event {$event['event_id']} dead-lettered after {$attempts} attempts");
            } else {
                // Schedule retry
                $delaySec = $this->retryIntervals[$attempts - 1] ?? 90;
                $sql = "UPDATE dbo.swim_webhook_events
                        SET status = 'sent', attempts = ?,
                            next_retry_utc = DATEADD(SECOND, ?, SYSUTCDATETIME())
                        WHERE event_id = ?";
                sqlsrv_query($this->conn, $sql, [$attempts, $delaySec, $event['event_id']]);
            }
        }

        // Update subscription failure tracking
        $sql = "UPDATE dbo.swim_webhook_subscriptions
                SET last_failure_utc = SYSUTCDATETIME(),
                    consecutive_failures = consecutive_failures + 1,
                    updated_utc = SYSUTCDATETIME()
                WHERE id = ?";
        sqlsrv_query($this->conn, $sql, [$subscriptionId]);
    }

    /**
     * Mark events as delivered after successful dispatch.
     */
    private function markEventsDelivered(array $events): void
    {
        foreach ($events as $event) {
            $sql = "UPDATE dbo.swim_webhook_events
                    SET status = 'delivered', delivered_utc = SYSUTCDATETIME()
                    WHERE event_id = ?";
            sqlsrv_query($this->conn, $sql, [$event['event_id']]);
        }
    }

    /**
     * Update subscription success tracking.
     */
    private function updateSubscriptionSuccess(int $subscriptionId): void
    {
        $sql = "UPDATE dbo.swim_webhook_subscriptions
                SET last_success_utc = SYSUTCDATETIME(),
                    consecutive_failures = 0,
                    updated_utc = SYSUTCDATETIME()
                WHERE id = ?";
        sqlsrv_query($this->conn, $sql, [$subscriptionId]);
    }

    /**
     * Load an active subscription for a source and direction.
     */
    private function loadSubscription(string $sourceId, string $direction): ?array
    {
        $sql = "SELECT id, callback_url, shared_secret, event_types
                FROM dbo.swim_webhook_subscriptions
                WHERE source_id = ? AND direction = ? AND is_active = 1";
        $stmt = sqlsrv_query($this->conn, $sql, [$sourceId, $direction]);
        if ($stmt === false) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            error_log("[WebhookSender] $msg");
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add lib/webhooks/WebhookSender.php
git commit -m "feat(webhooks): add WebhookSender with HMAC signing and retry queue"
```

---

## Task 4: WebhookEventBuilder Library

**Files:**
- Create: `lib/webhooks/WebhookEventBuilder.php`

- [ ] **Step 1: Create the WebhookEventBuilder class**

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add lib/webhooks/WebhookEventBuilder.php
git commit -m "feat(webhooks): add WebhookEventBuilder for outbound TMI events"
```

---

## Task 5: Inbound Webhook Endpoint

**Files:**
- Create: `api/swim/v1/webhooks/simtraffic.php`

- [ ] **Step 1: Create the inbound webhook receiver endpoint**

```php
<?php
/**
 * SimTraffic Inbound Webhook Receiver
 *
 * Receives lifecycle events from SimTraffic via REST webhook.
 * Verifies HMAC-SHA256 signature, deduplicates by event_id,
 * and processes flights through processSimTrafficFlight().
 *
 * POST /api/swim/v1/webhooks/simtraffic.php
 *
 * Headers:
 *   X-SimTraffic-Signature: sha256=<hmac>
 *   X-SimTraffic-Timestamp: <unix_epoch>
 *
 * Body: {"events": [...], "count": N}
 *   or:  {"event_id": "...", "event_type": "...", "data": {...}}  (single event)
 *
 * @package PERTI\SWIM\Webhooks
 */

// Load config + DB connections
require_once __DIR__ . '/../../../../load/config.php';
require_once __DIR__ . '/../../../../load/connect.php';

// Load dependencies
require_once __DIR__ . '/../../../../lib/webhooks/WebhookReceiver.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../ingest/simtraffic.php'; // for processSimTrafficFlight()

use PERTI\Lib\Webhooks\WebhookReceiver;

header('Content-Type: application/json');

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$conn = get_conn_swim();

// Read raw body before any parsing
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

// Check for HMAC signature headers
$signatureHeader = $_SERVER['HTTP_X_SIMTRAFFIC_SIGNATURE'] ?? '';
$timestampHeader = $_SERVER['HTTP_X_SIMTRAFFIC_TIMESTAMP'] ?? '';

$hmacPresent = !empty($signatureHeader) && !empty($timestampHeader);

if ($hmacPresent) {
    // HMAC verification path
    $secret = WebhookReceiver::loadSecret($conn, 'simtraffic', 'inbound');
    if (!$secret) {
        http_response_code(500);
        echo json_encode(['error' => 'Webhook subscription not configured']);
        exit;
    }

    $receiver = new WebhookReceiver($conn, $secret);
    $verification = $receiver->verify($signatureHeader, $timestampHeader, $rawBody);

    if (!$verification['valid']) {
        http_response_code(401);
        echo json_encode(['error' => 'Signature verification failed', 'detail' => $verification['error']]);
        exit;
    }
} else {
    // Fallback: API key auth (backward compatibility during transition)
    $auth = swim_init_auth(true, true);
    if (!$auth->canWriteField('times')) {
        http_response_code(403);
        echo json_encode(['error' => 'Requires System or Partner tier with times authority']);
        exit;
    }
    // Create receiver without HMAC (dedup only)
    $receiver = new WebhookReceiver($conn, '', 300, 24);
}

// Parse JSON
$body = json_decode($rawBody, true);
if ($body === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Normalize to events array (support single event or batch)
if (isset($body['events']) && is_array($body['events'])) {
    $events = $body['events'];
} elseif (isset($body['event_id'])) {
    $events = [$body];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Expected "events" array or single event with "event_id"']);
    exit;
}

// Enforce batch limit
if (count($events) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Batch size exceeded. Maximum 500 events per request.']);
    exit;
}

// Process events
$accepted = 0;
$duplicates = 0;
$notFound = 0;
$errors = [];

foreach ($events as $event) {
    $eventId = $event['event_id'] ?? null;
    $eventType = $event['event_type'] ?? 'unknown';
    $data = $event['data'] ?? $event;

    if (!$eventId) {
        $errors[] = ['error' => 'Missing event_id', 'event_type' => $eventType];
        continue;
    }

    // Dedup check
    if ($receiver->isDuplicate($eventId)) {
        $duplicates++;
        continue;
    }

    // Convert lifecycle event data to SimTraffic ingest format
    // The data payload matches the existing processSimTrafficFlight() record format
    $record = $data;

    try {
        $result = processSimTrafficFlight($conn, $record, 'simtraffic');

        // Log to webhook events table
        $receiver->logInboundEvent(
            $eventId,
            $eventType,
            'simtraffic',
            'rest',
            json_encode($event),
            $result['flight_uid'] ?? null,
            $result['callsign'] ?? ($data['callsign'] ?? null)
        );

        if ($result['status'] === 'updated') {
            $accepted++;
        } elseif ($result['status'] === 'not_found') {
            $notFound++;
        } else {
            $accepted++; // no_changes still counts as accepted
        }
    } catch (\Exception $e) {
        $errors[] = [
            'event_id' => $eventId,
            'error' => $e->getMessage(),
        ];
    }
}

// Update subscription last_success
if ($accepted > 0 || $duplicates > 0) {
    $sql = "UPDATE dbo.swim_webhook_subscriptions
            SET last_success_utc = SYSUTCDATETIME(),
                consecutive_failures = 0,
                updated_utc = SYSUTCDATETIME()
            WHERE source_id = 'simtraffic' AND direction = 'inbound' AND is_active = 1";
    sqlsrv_query($conn, $sql);
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'accepted' => $accepted,
    'duplicates' => $duplicates,
    'not_found' => $notFound,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10),
]);
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/webhooks/simtraffic.php
git commit -m "feat(webhooks): add SimTraffic inbound webhook receiver endpoint"
```

---

## Task 6: Webhook Registration Endpoint

**Files:**
- Create: `api/swim/v1/webhooks/register.php`

- [ ] **Step 1: Create the registration endpoint**

```php
<?php
/**
 * Webhook Subscription Management
 *
 * GET    /api/swim/v1/webhooks/register.php           — List subscriptions
 * POST   /api/swim/v1/webhooks/register.php           — Create subscription
 * DELETE /api/swim/v1/webhooks/register.php?id=N       — Deactivate subscription
 *
 * Requires System tier API key.
 *
 * @package PERTI\SWIM\Webhooks
 */

require_once __DIR__ . '/../../../../load/config.php';
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$conn = get_conn_swim();

// Require system tier
$auth = swim_init_auth(true, false);
if ($auth->getTier() !== 'system') {
    http_response_code(403);
    echo json_encode(['error' => 'Requires System tier API key']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $sql = "SELECT id, source_id, direction, callback_url, event_types, is_active,
                       created_utc, updated_utc, last_success_utc, last_failure_utc,
                       consecutive_failures
                FROM dbo.swim_webhook_subscriptions
                ORDER BY source_id, direction";
        $stmt = sqlsrv_query($conn, $sql);
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime objects to strings
            foreach (['created_utc', 'updated_utc', 'last_success_utc', 'last_failure_utc'] as $col) {
                if ($row[$col] instanceof \DateTime) {
                    $row[$col] = $row[$col]->format('Y-m-d H:i:s');
                }
            }
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        echo json_encode(['subscriptions' => $rows]);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        $required = ['source_id', 'direction', 'callback_url', 'shared_secret'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: {$field}"]);
                exit;
            }
        }

        if (!in_array($body['direction'], ['inbound', 'outbound'])) {
            http_response_code(400);
            echo json_encode(['error' => 'direction must be "inbound" or "outbound"']);
            exit;
        }

        $sql = "INSERT INTO dbo.swim_webhook_subscriptions
                    (source_id, direction, callback_url, shared_secret, event_types)
                VALUES (?, ?, ?, ?, ?)";
        $params = [
            $body['source_id'],
            $body['direction'],
            $body['callback_url'],
            $body['shared_secret'],
            $body['event_types'] ?? '*',
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create subscription']);
            exit;
        }

        // Get inserted ID
        $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
        $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($idStmt);

        echo json_encode(['success' => true, 'id' => $idRow['id']]);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid subscription id']);
            exit;
        }

        $sql = "UPDATE dbo.swim_webhook_subscriptions
                SET is_active = 0, updated_utc = SYSUTCDATETIME()
                WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);
        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        echo json_encode(['success' => $rows > 0, 'deactivated' => $rows]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/webhooks/register.php
git commit -m "feat(webhooks): add webhook subscription management endpoint"
```

---

## Task 7: Webhook Delivery Daemon

**Files:**
- Create: `scripts/webhook_delivery_daemon.php`

- [ ] **Step 1: Create the delivery daemon**

```php
#!/usr/bin/env php
<?php
/**
 * Webhook Delivery Daemon
 *
 * Processes outbound events from swim_webhook_events queue.
 * Dispatches batches to registered webhook callback URLs.
 * Runs on a 10-second cycle.
 *
 * Usage:
 *   php scripts/webhook_delivery_daemon.php --loop        # Continuous
 *   php scripts/webhook_delivery_daemon.php --once        # Single run
 *   php scripts/webhook_delivery_daemon.php --loop --debug
 *
 * @package PERTI\Scripts
 */

declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit', '128M');

$wwwroot = dirname(__DIR__);
require_once $wwwroot . '/load/config.php';
require_once $wwwroot . '/load/connect.php';
require_once $wwwroot . '/lib/webhooks/WebhookSender.php';
require_once $wwwroot . '/lib/connectors/CircuitBreaker.php';

use PERTI\Lib\Webhooks\WebhookSender;
use PERTI\Lib\Connectors\CircuitBreaker;

// CLI arguments
$loop = in_array('--loop', $argv ?? []);
$once = in_array('--once', $argv ?? []);
$debug = in_array('--debug', $argv ?? []);
$interval = 10; // 10-second cycle

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
        $interval = (int)$m[1];
    }
}

$continuous = $loop && !$once;

echo "Webhook Delivery Daemon\n";
echo "=======================\n";
echo "Mode: " . ($continuous ? "Continuous (every {$interval}s)" : 'Single run') . "\n";
echo "Debug: " . ($debug ? 'ON' : 'OFF') . "\n\n";

// Check hibernation
if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
    echo "HIBERNATION: Outbound webhook delivery paused.\n";
    if (!$continuous) exit(0);
}

$cycleCount = 0;

do {
    $cycleCount++;

    // Skip during hibernation (events queue, drain on wake)
    if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
        if ($debug) echo "[" . date('Y-m-d H:i:s') . "] Hibernation — skipping cycle\n";
        if ($continuous) { sleep($interval); continue; }
        break;
    }

    $conn = get_conn_swim();
    if (!$conn) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: No SWIM_API connection\n";
        if ($continuous) { sleep($interval); continue; }
        exit(1);
    }

    $cb = new CircuitBreaker(
        sys_get_temp_dir() . '/perti_simtraffic_webhook_state.json',
        60,  // 60s window
        6,   // 6 errors to trip
        180  // 3-min cooldown
    );

    $sender = new WebhookSender($conn, $cb, [10, 30, 90], 50, $debug);

    if ($debug) echo "[" . date('Y-m-d H:i:s') . "] Cycle {$cycleCount}: Processing pending events...\n";

    $result = $sender->processPendingEvents();

    $msg = sprintf(
        "dispatched=%d failed=%d dead=%d circuit=%s",
        $result['dispatched'],
        $result['failed'],
        $result['dead'],
        $result['skipped_circuit'] ? 'OPEN' : 'closed'
    );

    if ($debug || $result['dispatched'] > 0 || $result['failed'] > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
    }

    // Periodic stats every 60 cycles (10 minutes)
    if ($cycleCount % 60 === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Stats after {$cycleCount} cycles\n";
    }

    if ($continuous) {
        sleep($interval);
    }

} while ($continuous);

exit(0);
```

- [ ] **Step 2: Commit**

```bash
git add scripts/webhook_delivery_daemon.php
git commit -m "feat(webhooks): add outbound webhook delivery daemon (10s cycle)"
```

---

## Task 8: Modify Existing Files

**Files:**
- Modify: `load/swim_config.php`
- Modify: `lib/connectors/sources/SimTrafficConnector.php`
- Modify: `api/swim/v1/ingest/simtraffic.php`
- Modify: `scripts/simtraffic_swim_poll.php`

- [ ] **Step 1: Add webhook constants to swim_config.php**

Add after the existing `$SWIM_RATE_LIMITS` block:

```php
// ============================================================================
// Webhook Configuration
// ============================================================================
$SWIM_WEBHOOK_CONFIG = [
    'retry_intervals'      => [10, 30, 90],   // seconds between retries
    'batch_size'           => 50,              // max events per dispatch
    'batch_window_sec'     => 5,               // max wait before dispatch
    'signing_algo'         => 'sha256',        // HMAC algorithm
    'dedup_window_hours'   => 24,              // inbound dedup window
    'event_retention_days' => 30,              // swim_webhook_events purge
    'max_timestamp_age'    => 300,             // replay protection window (seconds)
    'inbound_rate_limit'   => 1000,            // req/min for webhook endpoints
];
```

- [ ] **Step 2: Update SimTrafficConnector**

In `lib/connectors/sources/SimTrafficConnector.php`, update `getEndpoints()` and `getConfig()`:

```php
public function getEndpoints(): array
{
    return [
        'ingest'  => '/api/swim/v1/ingest/simtraffic.php',
        'webhook' => '/api/swim/v1/webhooks/simtraffic.php',
        'register' => '/api/swim/v1/webhooks/register.php',
    ];
}

public function getConfig(): array
{
    return array_merge(parent::getConfig(), [
        'auth_field'    => 'metering',
        'batch_limit'   => 500,
        'poll_daemon'   => 'scripts/simtraffic_swim_poll.php',
        'poll_interval' => '600s',
        'webhook_daemon' => 'scripts/webhook_delivery_daemon.php',
        'webhook_interval' => '10s',
        'data_fields'   => ['departure_times', 'arrival_times', 'metering_data'],
        'client_sdk'    => 'integrations/connectors/simtraffic/',
    ]);
}
```

- [ ] **Step 3: Add deprecation header to legacy ingest endpoint**

In `api/swim/v1/ingest/simtraffic.php`, add near the top (after auth check, before processing):

```php
// Deprecation notice — webhook endpoint is preferred
header('X-Deprecated: Use POST /api/swim/v1/webhooks/simtraffic instead');
header('Sunset: 2026-09-30');
```

- [ ] **Step 4: Change polling daemon default interval**

In `scripts/simtraffic_swim_poll.php`, change the default interval from 120 to 600:

```php
// Old: $interval = 120;
$interval = 600;  // Demoted to reconciliation fallback (webhooks are primary)
```

- [ ] **Step 5: Commit**

```bash
git add load/swim_config.php lib/connectors/sources/SimTrafficConnector.php api/swim/v1/ingest/simtraffic.php scripts/simtraffic_swim_poll.php
git commit -m "feat(webhooks): update config, connector, and demote polling to reconciliation"
```

---

## Task 9: EDCTDelivery Channel 5

**Files:**
- Modify: `load/services/EDCTDelivery.php`

- [ ] **Step 1: Add WebhookEventBuilder dependency**

At the top of the file, after the existing `require_once`:

```php
require_once __DIR__ . '/../../lib/webhooks/WebhookEventBuilder.php';
```

Add a private property:

```php
private ?\PERTI\Lib\Webhooks\WebhookEventBuilder $webhookBuilder = null;
```

- [ ] **Step 2: Add Channel 5 to deliverEDCT()**

In `deliverEDCT()`, after the Channel 4 Discord block (after line 154) and before the `$delivered` count:

```php
    // Channel 5: SimTraffic webhook (queue outbound event)
    $results['simtraffic_webhook'] = $this->queueWebhookEvent(
        'edct_assigned', $flight_uid, $callsign, $edct_utc, $reason, $program_id
    );
```

- [ ] **Step 3: Add Channel 5 to deliverGateHold() and deliverGateRelease()**

In `deliverGateHold()`, after Channel 3 WebSocket:

```php
    // Channel 5: SimTraffic webhook
    $results['simtraffic_webhook'] = $this->queueWebhookGateHold($flight_uid, $callsign, $tsat_utc, $reason);
```

In `deliverGateRelease()`, after Channel 3 WebSocket:

```php
    // Channel 5: SimTraffic webhook
    $results['simtraffic_webhook'] = $this->queueWebhookGateRelease($flight_uid, $callsign, $ttot_utc);
```

- [ ] **Step 4: Add webhook helper methods**

Add before the `// LAZY-LOADED DEPENDENCIES` section:

```php
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
     * Push event to WebSocket server via file-based IPC for real-time delivery.
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

    private function getWebhookBuilder(): ?\PERTI\Lib\Webhooks\WebhookEventBuilder
    {
        if ($this->webhookBuilder !== null) return $this->webhookBuilder;

        // Need SWIM_API connection
        $conn = function_exists('get_conn_swim') ? get_conn_swim() : null;
        if (!$conn) return null;

        $this->webhookBuilder = new \PERTI\Lib\Webhooks\WebhookEventBuilder($conn);
        return $this->webhookBuilder;
    }
```

- [ ] **Step 5: Commit**

```bash
git add load/services/EDCTDelivery.php
git commit -m "feat(webhooks): add Channel 5 SimTraffic webhook to EDCTDelivery"
```

---

## Task 10: WebSocket Publish Action Handler

**Files:**
- Modify: `api/swim/v1/ws/WebSocketServer.php`

- [ ] **Step 1: Add `publish` case to onMessage() switch**

In the `onMessage()` method's switch statement (around line 278), add before the `default` case:

```php
        case 'publish':
            $this->handlePublish($from, $client, $data);
            break;
```

- [ ] **Step 2: Add handlePublish() method**

Add after the existing `handleStatus()` method.

**Design note**: The WS server is a long-running Ratchet daemon. `processSimTrafficFlight()` lives in a request-scoped ingest file and expects a SWIM_API `sqlsrv` connection that the WS server doesn't hold (its `$this->dbConn` connects to ADL for API key auth only). The cleanest approach is to forward the publish to the webhook REST endpoint via internal HTTP, then ACK the WS client. This reuses all existing ingest + dedup logic without loading request-scoped code into the daemon.

```php
    /**
     * Handle inbound publish from system-tier clients (e.g., SimTraffic).
     * Forwards lifecycle events to the REST webhook endpoint for processing,
     * then ACKs the WS client.
     *
     * We forward to REST rather than calling processSimTrafficFlight() directly
     * because (a) the WS server daemon doesn't hold a SWIM_API connection and
     * (b) the ingest function is request-scoped code not designed for long-running use.
     */
    protected function handlePublish(ConnectionInterface $conn, ClientConnection $client, array $data): void
    {
        // Only system-tier clients can publish
        if ($client->getTier() !== 'system') {
            $this->sendError($conn, 'FORBIDDEN', 'Publish requires system tier');
            return;
        }

        $channel = $data['channel'] ?? null;
        $eventData = $data['data'] ?? null;
        $eventId = $data['event_id'] ?? ('ws_' . bin2hex(random_bytes(8)));

        if (!$channel || !$eventData) {
            $this->sendError($conn, 'INVALID_PUBLISH', 'Requires "channel" and "data" fields');
            return;
        }

        // Only allow simtraffic.lifecycle.* channels
        if (!str_starts_with($channel, 'simtraffic.lifecycle.')) {
            $this->sendError($conn, 'INVALID_CHANNEL', 'Can only publish to simtraffic.lifecycle.* channels');
            return;
        }

        // Forward to the REST webhook endpoint via internal HTTP
        // This reuses all ingest + dedup logic without duplicating code
        $webhookUrl = 'http://127.0.0.1/api/swim/v1/webhooks/simtraffic.php';
        $apiKey = $client->getApiKey(); // SimTraffic's system-tier key

        $payload = json_encode([
            'events' => [[
                'event_id' => $eventId,
                'event_type' => $channel,
                'data' => $eventData,
            ]],
        ]);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-SWIM-API-Key: ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode >= 400) {
            $this->sendError($conn, 'PUBLISH_ERROR',
                $curlErr ?: "Webhook returned HTTP {$httpCode}");
            return;
        }

        $result = json_decode($response, true) ?? [];

        $this->send($conn, [
            'type' => 'publish_ack',
            'event_id' => $eventId,
            'accepted' => $result['accepted'] ?? 0,
            'duplicates' => $result['duplicates'] ?? 0,
        ]);
    }
```

- [ ] **Step 3: Commit**

```bash
git add api/swim/v1/ws/WebSocketServer.php
git commit -m "feat(webhooks): add WS publish action for SimTraffic lifecycle events"
```

---

## Task 11: Archival Daemon Purge + Startup Script

**Files:**
- Modify: `scripts/archival_daemon.php`
- Modify: `scripts/startup.sh`

- [ ] **Step 1: Add webhook event purge to archival daemon**

In `scripts/archival_daemon.php`, in the `runArchival()` function, add after the existing Step 5 (TMI purge) and before the failure check loop:

```php
    // Step 6: Purge old webhook events (30-day retention)
    // Runs always (even during hibernation — these are delivery logs, not flight data)
    logMsg("Step 6/6: Purging webhook events older than 30 days...");
    $retentionDays = 30;
    $purgeWebhookSql = "DELETE TOP (10000) FROM dbo.swim_webhook_events
                        WHERE created_utc < DATEADD(DAY, -{$retentionDays}, SYSUTCDATETIME())";
    // Run against SWIM_API connection
    $connSwim = function_exists('get_conn_swim') ? get_conn_swim() : null;
    if ($connSwim) {
        $purgeStmt = sqlsrv_query($connSwim, $purgeWebhookSql);
        $purgeRows = $purgeStmt ? sqlsrv_rows_affected($purgeStmt) : 0;
        if ($purgeStmt) sqlsrv_free_stmt($purgeStmt);
        $results['steps']['purge_webhook_events'] = [
            'success' => true,
            'rows_deleted' => $purgeRows,
        ];
        if ($purgeRows > 0) logMsg("  Purged {$purgeRows} webhook events");
    } else {
        $results['steps']['purge_webhook_events'] = ['success' => true, 'skipped' => true, 'reason' => 'no_swim_conn'];
    }
```

- [ ] **Step 2: Add webhook delivery daemon to startup.sh**

In `scripts/startup.sh`, in the conditional daemons section (non-hibernation), add:

```bash
# Webhook delivery daemon (outbound event queue)
nohup php /home/site/wwwroot/scripts/webhook_delivery_daemon.php --loop >> /home/LogFiles/webhook_delivery.log 2>&1 &
echo "  Started webhook_delivery_daemon.php (PID: $!)"
```

- [ ] **Step 3: Commit**

```bash
git add scripts/archival_daemon.php scripts/startup.sh
git commit -m "feat(webhooks): add event purge to archival daemon and delivery daemon to startup"
```

---

## Task 12: Validation & Smoke Test

- [ ] **Step 1: Verify migration applied**

Query SWIM_API:
```sql
SELECT name FROM sys.tables WHERE name LIKE 'swim_webhook%';
-- Expected: swim_webhook_events, swim_webhook_subscriptions

SELECT * FROM dbo.swim_webhook_subscriptions;
-- Expected: 2 rows (simtraffic inbound + outbound)
```

- [ ] **Step 2: Test inbound webhook endpoint (no HMAC, API key fallback)**

```bash
curl -s -X POST "https://perti.vatcscc.org/api/swim/v1/webhooks/simtraffic.php" \
  -H "Content-Type: application/json" \
  -H "X-SWIM-API-Key: swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2" \
  -d '{
    "events": [{
      "event_id": "evt_test_001",
      "event_type": "flight.departed",
      "data": {
        "callsign": "TEST001",
        "departure_afld": "KJFK",
        "arrival_afld": "KLAX",
        "departure": {"takeoff_time": "2026-03-30T20:00:00Z"}
      }
    }]
  }'
```

Expected: `{"success":true,"accepted":0,"duplicates":0,"not_found":1,"errors":0,...}` (not_found=1 since TEST001 doesn't exist)

- [ ] **Step 3: Test dedup (send same event_id twice)**

Run the same curl command again with `event_id: "evt_test_001"`.

Expected: `{"success":true,"accepted":0,"duplicates":1,...}`

- [ ] **Step 4: Test registration endpoint**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/webhooks/register.php" \
  -H "X-SWIM-API-Key: swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2"
```

Expected: JSON with 2 subscriptions listed.

- [ ] **Step 5: Verify connector health reflects webhooks**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/connectors/health.php" \
  -H "X-SWIM-API-Key: swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2"
```

Expected: SimTraffic connector shows webhook endpoints.

- [ ] **Step 6: Final commit (any fixes from smoke test)**

```bash
git add -A
git commit -m "fix(webhooks): address smoke test findings"
```

---

## Summary

| Task | Component | Files | Commits |
|------|-----------|-------|---------|
| 1 | Database migration | 1 new | 1 |
| 2 | WebhookReceiver | 1 new | 1 |
| 3 | WebhookSender | 1 new | 1 |
| 4 | WebhookEventBuilder | 1 new | 1 |
| 5 | Inbound webhook endpoint | 1 new | 1 |
| 6 | Registration endpoint | 1 new | 1 |
| 7 | Delivery daemon | 1 new | 1 |
| 8 | Config + connector + legacy updates | 4 modified | 1 |
| 9 | EDCTDelivery Channel 5 | 1 modified | 1 |
| 10 | WebSocket publish handler | 1 modified | 1 |
| 11 | Archival purge + startup | 2 modified | 1 |
| 12 | Validation & smoke test | 0 | 0-1 |

**Total**: 7 new files, 8 modified files, 11-12 commits
