<?php
/**
 * VATSWIM VAM SWIM Client
 *
 * API client for VATSWIM.
 *
 * @package VATSWIM
 * @subpackage VAM Integration
 * @version 1.0.0
 */

namespace VatSwim\VAM;

/**
 * VATSWIM API Client
 */
class SWIMClient
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

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * Submit flight data to VATSWIM
     */
    public function submitFlight(array $flightData): bool
    {
        $response = $this->post('/ingest/adl', $flightData);
        return $response !== null && ($response['success'] ?? false);
    }

    /**
     * Submit track position
     */
    public function submitTrack(array $trackData): bool
    {
        $response = $this->post('/ingest/track', $trackData);
        return $response !== null && ($response['success'] ?? false);
    }

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
                'X-SWIM-Source: vam',
                'User-Agent: VAM-VATSWIM/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->verbose) {
            error_log("[VATSWIM-VAM] POST $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-VAM] cURL error: $error");
            return null;
        }

        return json_decode($response, true);
    }
}
