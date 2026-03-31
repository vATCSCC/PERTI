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
            $this->logError("Failed to fetch pending events: " . json_encode(sqlsrv_errors()));
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

        // Build batch payload, tracking which events have valid JSON
        $payloads = [];
        $validEvents = [];
        $invalidEvents = [];
        foreach ($events as $event) {
            $decoded = json_decode($event['payload'], true);
            if ($decoded !== null) {
                $payloads[] = $decoded;
                $validEvents[] = $event;
            } else {
                $invalidEvents[] = $event;
                $this->logError("Event {$event['event_id']} has invalid JSON payload — dead-lettering");
            }
        }

        // Dead-letter events with corrupt JSON
        foreach ($invalidEvents as $badEvent) {
            $sql = "UPDATE dbo.swim_webhook_events SET status = 'dead', attempts = attempts + 1 WHERE event_id = ?";
            $s = sqlsrv_query($this->conn, $sql, [$badEvent['event_id']]);
            if ($s) sqlsrv_free_stmt($s);
            $result['dead']++;
        }

        if (empty($payloads)) return $result;

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
            $this->logError("CURL error dispatching to {$callbackUrl}: {$curlError}");
            $this->handleBatchFailure($events, $subId);
            $this->circuitBreaker->recordError();
            $result['failed'] = count($events);
            return $result;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            // Success — only mark events that were actually sent
            $this->markEventsDelivered($validEvents);
            $this->updateSubscriptionSuccess($subId);
            $this->circuitBreaker->recordSuccess();
            $result['dispatched'] = count($validEvents);
            $this->log("Dispatched " . count($events) . " events to {$callbackUrl} (HTTP {$httpCode})");
        } elseif ($httpCode >= 500) {
            // Server error — retry + circuit breaker
            $this->handleBatchFailure($events, $subId);
            $tripped = $this->circuitBreaker->recordError();
            $result['failed'] = count($events);
            if ($tripped) {
                $this->logError("Circuit breaker TRIPPED after 5xx from {$callbackUrl}");
            }
        } else {
            // 4xx — likely a persistent problem, still retry but don't trip circuit
            $this->handleBatchFailure($events, $subId);
            $result['failed'] = count($events);
            $this->logError("HTTP {$httpCode} from {$callbackUrl}: {$response}");
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
                $s = sqlsrv_query($this->conn, $sql, [$attempts, $event['event_id']]);
                if ($s) sqlsrv_free_stmt($s);
                $this->logError("Event {$event['event_id']} dead-lettered after {$attempts} attempts");
            } else {
                // Schedule retry
                $delaySec = $this->retryIntervals[$attempts - 1] ?? 90;
                $sql = "UPDATE dbo.swim_webhook_events
                        SET status = 'sent', attempts = ?,
                            next_retry_utc = DATEADD(SECOND, ?, SYSUTCDATETIME())
                        WHERE event_id = ?";
                $s = sqlsrv_query($this->conn, $sql, [$attempts, $delaySec, $event['event_id']]);
                if ($s) sqlsrv_free_stmt($s);
            }
        }

        // Update subscription failure tracking
        $sql = "UPDATE dbo.swim_webhook_subscriptions
                SET last_failure_utc = SYSUTCDATETIME(),
                    consecutive_failures = consecutive_failures + 1,
                    updated_utc = SYSUTCDATETIME()
                WHERE id = ?";
        $s = sqlsrv_query($this->conn, $sql, [$subscriptionId]);
        if ($s) sqlsrv_free_stmt($s);
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
            $s = sqlsrv_query($this->conn, $sql, [$event['event_id']]);
            if ($s) sqlsrv_free_stmt($s);
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
        $s = sqlsrv_query($this->conn, $sql, [$subscriptionId]);
        if ($s) sqlsrv_free_stmt($s);
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

    private function logError(string $msg): void
    {
        error_log("[WebhookSender] $msg");
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            error_log("[WebhookSender] $msg");
        }
    }
}
