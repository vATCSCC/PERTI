<?php
/**
 * VATSWIM vFDS EDST Client
 *
 * Client for vEDST (Enhanced Data Support Tool) data exchange.
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 * @version 1.0.0
 */

namespace VatSwim\VFDS;

/**
 * EDST Client - Interfaces with vEDST/vFDS systems
 */
class EDSTClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $facilityId;
    private bool $verbose;

    public function __construct(string $baseUrl, string $apiKey, string $facilityId)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->facilityId = $facilityId;
        $this->verbose = false;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * Get departure list for a facility
     *
     * @param string|null $airport Filter by departure airport
     * @return array Departure list
     */
    public function getDepartureList(?string $airport = null): array
    {
        $endpoint = "/facilities/{$this->facilityId}/departures";

        if ($airport) {
            $endpoint .= "?airport=" . urlencode($airport);
        }

        $response = $this->get($endpoint);
        return $response['departures'] ?? [];
    }

    /**
     * Get arrival list for a facility
     *
     * @param string|null $airport Filter by arrival airport
     * @return array Arrival list
     */
    public function getArrivalList(?string $airport = null): array
    {
        $endpoint = "/facilities/{$this->facilityId}/arrivals";

        if ($airport) {
            $endpoint .= "?airport=" . urlencode($airport);
        }

        $response = $this->get($endpoint);
        return $response['arrivals'] ?? [];
    }

    /**
     * Get EDST flight data
     *
     * @param string $callsign Aircraft callsign
     * @return array|null Flight data or null
     */
    public function getFlightData(string $callsign): ?array
    {
        $endpoint = "/flights/" . urlencode($callsign);
        return $this->get($endpoint);
    }

    /**
     * Submit EDCT (Expected Departure Clearance Time)
     *
     * @param string $callsign Aircraft callsign
     * @param string $edct EDCT in ISO format or HHMM
     * @param string|null $reason Reason code (TMI, WEATHER, etc.)
     * @return bool Success
     */
    public function submitEDCT(string $callsign, string $edct, ?string $reason = null): bool
    {
        $data = [
            'callsign' => $callsign,
            'edct' => $edct,
            'facility' => $this->facilityId
        ];

        if ($reason) {
            $data['reason'] = $reason;
        }

        return $this->post('/edct', $data);
    }

    /**
     * Update flight strip annotation
     *
     * @param string $callsign Aircraft callsign
     * @param array $annotations Key-value annotations
     * @return bool Success
     */
    public function updateAnnotations(string $callsign, array $annotations): bool
    {
        $data = [
            'callsign' => $callsign,
            'annotations' => $annotations
        ];

        return $this->put("/flights/{$callsign}/annotations", $data);
    }

    /**
     * Get TMI (Traffic Management Initiative) status for an airport
     *
     * @param string $airport Airport ICAO
     * @return array|null TMI data
     */
    public function getTMIStatus(string $airport): ?array
    {
        $endpoint = "/tmi/airports/" . urlencode($airport);
        return $this->get($endpoint);
    }

    /**
     * Get active ground stops
     *
     * @return array Active ground stops
     */
    public function getGroundStops(): array
    {
        $response = $this->get('/tmi/groundstops');
        return $response['ground_stops'] ?? [];
    }

    /**
     * Get active ground delay programs
     *
     * @return array Active GDPs
     */
    public function getGDPs(): array
    {
        $response = $this->get('/tmi/gdps');
        return $response['gdps'] ?? [];
    }

    /**
     * Get miles-in-trail restrictions
     *
     * @return array MIT restrictions
     */
    public function getMITs(): array
    {
        $response = $this->get('/tmi/mits');
        return $response['mits'] ?? [];
    }

    /**
     * Get flight's calculated TBFM times
     *
     * @param string $callsign Aircraft callsign
     * @return array|null TBFM times
     */
    public function getTBFMTimes(string $callsign): ?array
    {
        $endpoint = "/flights/{$callsign}/tbfm";
        return $this->get($endpoint);
    }

    /**
     * Subscribe to flight updates (for webhook integration)
     *
     * @param string $webhookUrl URL to receive updates
     * @param array $events Events to subscribe to
     * @return bool Success
     */
    public function subscribeToUpdates(string $webhookUrl, array $events = ['all']): bool
    {
        $data = [
            'webhook_url' => $webhookUrl,
            'events' => $events,
            'facility' => $this->facilityId
        ];

        return $this->post('/subscriptions', $data);
    }

    /**
     * GET request to vFDS
     */
    private function get(string $endpoint): ?array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'X-Facility-ID: ' . $this->facilityId,
                'User-Agent: VATSWIM-vFDS/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->verbose) {
            error_log("[VATSWIM-vFDS] GET $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-vFDS] cURL error: $error");
            return null;
        }

        if ($httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * POST request to vFDS
     */
    private function post(string $endpoint, array $data): bool
    {
        return $this->sendRequest('POST', $endpoint, $data);
    }

    /**
     * PUT request to vFDS
     */
    private function put(string $endpoint, array $data): bool
    {
        return $this->sendRequest('PUT', $endpoint, $data);
    }

    /**
     * Send HTTP request
     */
    private function sendRequest(string $method, string $endpoint, array $data): bool
    {
        $url = $this->baseUrl . $endpoint;
        $json = json_encode($data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Facility-ID: ' . $this->facilityId,
                'User-Agent: VATSWIM-vFDS/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->verbose) {
            error_log("[VATSWIM-vFDS] $method $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-vFDS] cURL error: $error");
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }
}
