<?php
/**
 * VATSWIM Hoppie SWIM Updater
 *
 * Submits extracted CPDLC clearance data to VATSWIM.
 *
 * @package VATSWIM
 * @subpackage Hoppie CPDLC Integration
 * @version 1.0.0
 */

namespace VatSwim\Hoppie;

/**
 * SWIM Updater for Hoppie CPDLC data
 */
class SWIMUpdater
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
     * Submit clearance data to VATSWIM
     *
     * @param array $clearance Parsed clearance from CPDLCParser
     * @return bool Success
     */
    public function submitClearance(array $clearance): bool
    {
        $data = $this->transformClearance($clearance);

        if (empty($data['callsign'])) {
            return false;
        }

        return $this->post('/ingest/adl', $data);
    }

    /**
     * Transform parsed clearance to VATSWIM format
     */
    private function transformClearance(array $clearance): array
    {
        $data = [
            'source' => 'hoppie'
        ];

        // Basic identity
        if (!empty($clearance['callsign'])) {
            $data['callsign'] = $clearance['callsign'];
        }

        if (!empty($clearance['destination'])) {
            $data['dest_icao'] = $clearance['destination'];
        }

        // Clearance data
        if (!empty($clearance['cleared_altitude_fl'])) {
            $data['cleared_altitude_fl'] = $clearance['cleared_altitude_fl'];
        }

        if (!empty($clearance['sid'])) {
            $data['sid'] = $clearance['sid'];
        }

        if (!empty($clearance['departure_runway'])) {
            $data['departure_runway'] = $clearance['departure_runway'];
        }

        if (!empty($clearance['cleared_runway'])) {
            $data['arrival_runway'] = $clearance['cleared_runway'];
        }

        if (!empty($clearance['squawk'])) {
            $data['squawk'] = $clearance['squawk'];
        }

        if (!empty($clearance['direct_to'])) {
            $data['direct_to'] = $clearance['direct_to'];
        }

        // Timestamp
        if (!empty($clearance['timestamp'])) {
            $data['clearance_time_utc'] = $clearance['timestamp'];
        }

        return $data;
    }

    /**
     * POST data to VATSWIM API
     */
    private function post(string $endpoint, array $data): bool
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
                'X-SWIM-Source: hoppie',
                'User-Agent: VATSWIM-Hoppie/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->verbose) {
            error_log("[VATSWIM-Hoppie] POST $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-Hoppie] cURL error: $error");
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }
}
