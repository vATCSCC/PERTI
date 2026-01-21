<?php
/**
 * VATSWIM smartCARS SWIM Sync
 *
 * Handles API communication with VATSWIM.
 *
 * @package VATSWIM
 * @subpackage smartCARS Integration
 * @version 1.0.0
 */

namespace VatSwim\SmartCars;

/**
 * VATSWIM API client for smartCARS
 */
class SWIMSync
{
    private string $apiKey;
    private string $baseUrl;
    private bool $verbose;

    public function __construct(string $apiKey, string $baseUrl = 'https://perti.vatcscc.org/api/swim/v1')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->verbose = false;
    }

    /**
     * Enable verbose logging
     */
    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * Submit flight data to VATSWIM
     *
     * @param array $flightData Flight data in VATSWIM format
     * @return bool Success
     */
    public function submitFlight(array $flightData): bool
    {
        $response = $this->post('/ingest/adl', $flightData);
        return $response !== null && ($response['success'] ?? false);
    }

    /**
     * Submit track position to VATSWIM
     *
     * @param array $trackData Track data in VATSWIM format
     * @return bool Success
     */
    public function submitTrack(array $trackData): bool
    {
        $response = $this->post('/ingest/track', $trackData);
        return $response !== null && ($response['success'] ?? false);
    }

    /**
     * Make POST request to VATSWIM API
     */
    private function post(string $endpoint, array $data): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $json = json_encode($data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'X-SWIM-Source: smartcars',
                'User-Agent: smartCARS-VATSWIM/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->verbose) {
            error_log("[VATSWIM] POST $endpoint - HTTP $httpCode");
            error_log("[VATSWIM] Request: $json");
            error_log("[VATSWIM] Response: $response");
        }

        if ($error) {
            error_log("[VATSWIM] cURL error: $error");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[VATSWIM] API error: HTTP $httpCode - $response");
            return null;
        }

        return json_decode($response, true);
    }
}
