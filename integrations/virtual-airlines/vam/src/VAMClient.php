<?php
/**
 * VATSWIM VAM API Client
 *
 * Client for Virtual Airlines Manager REST API.
 *
 * @package VATSWIM
 * @subpackage VAM Integration
 * @version 1.0.0
 */

namespace VatSwim\VAM;

/**
 * VAM API Client
 */
class VAMClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Get active flights
     *
     * @return array Array of active flight data
     */
    public function getActiveFlights(): array
    {
        $data = $this->get('/api/v1/flights/active');
        return $data['flights'] ?? $data['data'] ?? [];
    }

    /**
     * Get recent PIREPs
     *
     * @param int $hours Hours to look back
     * @return array Array of PIREPs
     */
    public function getRecentPireps(int $hours = 24): array
    {
        $data = $this->get('/api/v1/pireps/recent', ['hours' => $hours]);
        return $data['pireps'] ?? $data['data'] ?? [];
    }

    /**
     * Get flight schedules
     *
     * @return array Array of scheduled flights
     */
    public function getSchedules(): array
    {
        $data = $this->get('/api/v1/schedules');
        return $data['schedules'] ?? $data['data'] ?? [];
    }

    /**
     * Get pilot information
     *
     * @param int $pilotId Pilot ID
     * @return array|null Pilot data
     */
    public function getPilot(int $pilotId): ?array
    {
        $data = $this->get("/api/v1/pilots/{$pilotId}");
        return $data['pilot'] ?? $data['data'] ?? null;
    }

    /**
     * Get flight details
     *
     * @param int $flightId Flight ID
     * @return array|null Flight data
     */
    public function getFlight(int $flightId): ?array
    {
        $data = $this->get("/api/v1/flights/{$flightId}");
        return $data['flight'] ?? $data['data'] ?? null;
    }

    /**
     * Make GET request to VAM API
     */
    private function get(string $endpoint, array $query = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: VATSWIM-VAM/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[VATSWIM-VAM] cURL error: $error");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[VATSWIM-VAM] API error: HTTP $httpCode - $response");
            return null;
        }

        return json_decode($response, true);
    }
}
