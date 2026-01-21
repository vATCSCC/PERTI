<?php
/**
 * VATSWIM smartCARS Webhook Receiver
 *
 * Receives webhook events from smartCARS 3 and syncs to VATSWIM.
 *
 * Webhook Setup in smartCARS:
 *   URL: https://your-server.com/vatswim/smartcars/webhook
 *   Secret: Your webhook secret (for signature verification)
 *
 * @package VATSWIM
 * @subpackage smartCARS Integration
 * @version 1.0.0
 */

namespace VatSwim\SmartCars;

/**
 * Receives and processes smartCARS webhooks
 */
class WebhookReceiver
{
    private string $webhookSecret;
    private SWIMSync $swimSync;
    private PIREPTransformer $transformer;

    public function __construct(string $webhookSecret, SWIMSync $swimSync)
    {
        $this->webhookSecret = $webhookSecret;
        $this->swimSync = $swimSync;
        $this->transformer = new PIREPTransformer();
    }

    /**
     * Handle incoming webhook request
     *
     * @param string $payload Raw request body
     * @param string $signature X-SmartCARS-Signature header
     * @return array Response to send back
     */
    public function handle(string $payload, string $signature): array
    {
        // Verify signature
        if (!$this->verifySignature($payload, $signature)) {
            return ['success' => false, 'error' => 'Invalid signature', 'code' => 401];
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON payload', 'code' => 400];
        }

        $event = $data['event'] ?? null;
        $eventData = $data['data'] ?? [];

        if (!$event) {
            return ['success' => false, 'error' => 'Missing event type', 'code' => 400];
        }

        // Route to appropriate handler
        $result = match ($event) {
            'pirep.started' => $this->handlePirepStarted($eventData),
            'pirep.position' => $this->handlePirepPosition($eventData),
            'pirep.completed' => $this->handlePirepCompleted($eventData),
            'pirep.cancelled' => $this->handlePirepCancelled($eventData),
            'flight.booked' => $this->handleFlightBooked($eventData),
            default => ['success' => false, 'error' => "Unknown event: $event", 'code' => 400]
        };

        return $result;
    }

    /**
     * Verify webhook signature
     */
    private function verifySignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            return true; // Skip verification if no secret configured
        }

        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Handle pirep.started event
     *
     * Pilot has started tracking a flight in smartCARS.
     * Submit initial flight data with predictions.
     */
    private function handlePirepStarted(array $data): array
    {
        $flightData = $this->transformer->transformStarted($data);

        if ($this->swimSync->submitFlight($flightData)) {
            return ['success' => true, 'message' => 'Flight started, synced to VATSWIM'];
        }

        return ['success' => false, 'error' => 'Failed to sync to VATSWIM', 'code' => 500];
    }

    /**
     * Handle pirep.position event
     *
     * Position update during flight (ACARS tracking).
     */
    private function handlePirepPosition(array $data): array
    {
        $trackData = $this->transformer->transformPosition($data);

        if ($this->swimSync->submitTrack($trackData)) {
            return ['success' => true, 'message' => 'Position synced'];
        }

        return ['success' => false, 'error' => 'Failed to sync position', 'code' => 500];
    }

    /**
     * Handle pirep.completed event
     *
     * Flight completed, submit final data with OOOI times.
     */
    private function handlePirepCompleted(array $data): array
    {
        $flightData = $this->transformer->transformCompleted($data);

        if ($this->swimSync->submitFlight($flightData)) {
            return ['success' => true, 'message' => 'Flight completed, synced to VATSWIM'];
        }

        return ['success' => false, 'error' => 'Failed to sync to VATSWIM', 'code' => 500];
    }

    /**
     * Handle pirep.cancelled event
     *
     * Flight was cancelled/aborted.
     */
    private function handlePirepCancelled(array $data): array
    {
        // Log cancellation but don't sync to VATSWIM
        // (flight will naturally expire from ADL)
        return ['success' => true, 'message' => 'Cancellation noted'];
    }

    /**
     * Handle flight.booked event
     *
     * Pilot booked a flight (pre-file).
     */
    private function handleFlightBooked(array $data): array
    {
        $flightData = $this->transformer->transformBooked($data);

        if ($this->swimSync->submitFlight($flightData)) {
            return ['success' => true, 'message' => 'Booking synced as preflight'];
        }

        return ['success' => false, 'error' => 'Failed to sync booking', 'code' => 500];
    }
}
