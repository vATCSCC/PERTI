<?php
/**
 * VATSWIM vATIS SWIM Sync
 *
 * Syncs correlated ATIS data to VATSWIM.
 *
 * @package VATSWIM
 * @subpackage vATIS Integration
 * @version 1.0.0
 */

namespace VatSwim\VATIS;

/**
 * SWIM Sync - Pushes correlated data to VATSWIM
 */
class SWIMSync
{
    private string $apiKey;
    private string $baseUrl;
    private bool $verbose;
    private RunwayCorrelator $correlator;
    private WeatherExtractor $weather;

    public function __construct(
        string $apiKey,
        string $baseUrl,
        RunwayCorrelator $correlator,
        WeatherExtractor $weather
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->correlator = $correlator;
        $this->weather = $weather;
        $this->verbose = false;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * Sync runway correlation for a specific flight
     *
     * @param array $flight Flight data
     * @return bool Success
     */
    public function syncFlight(array $flight): bool
    {
        $callsign = $flight['callsign'] ?? null;
        if (!$callsign) {
            return false;
        }

        $correlation = $this->correlator->correlate($flight);
        $data = $this->buildUpdatePayload($flight, $correlation);

        if (empty($data)) {
            return true; // Nothing to sync
        }

        $data['callsign'] = $callsign;
        $data['source'] = 'vatis';

        return $this->post('/ingest/adl', $data);
    }

    /**
     * Sync all active flights at airports with ATIS
     *
     * @param array $flights Array of active flights
     * @return array Stats about sync results
     */
    public function syncActiveFlights(array $flights): array
    {
        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        foreach ($flights as $flight) {
            $stats['processed']++;

            // Check if flight is at an airport with ATIS
            $deptIcao = $flight['dept_icao'] ?? $flight['departure_icao'] ?? null;
            $destIcao = $flight['dest_icao'] ?? $flight['destination_icao'] ?? null;

            $hasDeptAtis = $deptIcao && $this->correlator->getExpectedDepartureRunway($deptIcao) !== null;
            $hasDestAtis = $destIcao && $this->correlator->getExpectedArrivalRunway($destIcao) !== null;

            if (!$hasDeptAtis && !$hasDestAtis) {
                $stats['skipped']++;
                continue;
            }

            if ($this->syncFlight($flight)) {
                $stats['updated']++;
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Build update payload from correlation data
     */
    private function buildUpdatePayload(array $flight, array $correlation): array
    {
        $data = [];

        // Departure data
        if ($correlation['departure']) {
            $dept = $correlation['departure'];

            if (!empty($dept['expected_runway'])) {
                $data['departure_runway'] = $dept['expected_runway'];
            }

            if (!empty($dept['atis_letter'])) {
                $data['departure_atis_code'] = $dept['atis_letter'];
            }

            if (!empty($dept['altimeter'])) {
                $data['departure_altimeter'] = $dept['altimeter'];
            }

            // Wind for performance calculations
            if (!empty($dept['wind'])) {
                $data['departure_wind_dir'] = $dept['wind']['direction'];
                $data['departure_wind_speed'] = $dept['wind']['speed'];
                if (!empty($dept['wind']['gust'])) {
                    $data['departure_wind_gust'] = $dept['wind']['gust'];
                }
            }
        }

        // Arrival data
        if ($correlation['arrival']) {
            $arr = $correlation['arrival'];

            if (!empty($arr['expected_runway'])) {
                $data['arrival_runway'] = $arr['expected_runway'];
            }

            if (!empty($arr['expected_approach'])) {
                $data['expected_approach'] = $arr['expected_approach'];
            }

            if (!empty($arr['atis_letter'])) {
                $data['arrival_atis_code'] = $arr['atis_letter'];
            }

            if (!empty($arr['altimeter'])) {
                $data['arrival_altimeter'] = $arr['altimeter'];
            }

            // Weather for arrival
            if (!empty($arr['visibility'])) {
                $data['arrival_visibility'] = $arr['visibility'];
            }

            if (!empty($arr['ceiling'])) {
                $data['arrival_ceiling'] = $arr['ceiling'];
            }

            // Wind for arrivals
            if (!empty($arr['wind'])) {
                $data['arrival_wind_dir'] = $arr['wind']['direction'];
                $data['arrival_wind_speed'] = $arr['wind']['speed'];
                if (!empty($arr['wind']['gust'])) {
                    $data['arrival_wind_gust'] = $arr['wind']['gust'];
                }
            }
        }

        return $data;
    }

    /**
     * Sync airport weather data
     *
     * @param string $icao Airport ICAO
     * @return bool Success
     */
    public function syncAirportWeather(string $icao): bool
    {
        $weather = $this->weather->getWeather($icao);
        if (!$weather) {
            return false;
        }

        $data = [
            'icao' => $icao,
            'source' => 'vatis',
            'atis_code' => $weather['atis_letter'],
            'atis_time' => $weather['time_utc'],
            'flight_category' => $weather['flight_category']
        ];

        if ($weather['wind']) {
            $data['wind_direction'] = $weather['wind']['direction'];
            $data['wind_speed'] = $weather['wind']['speed'];
            $data['wind_gust'] = $weather['wind']['gust'];
        }

        if ($weather['visibility']) {
            $data['visibility_sm'] = $weather['visibility']['statute_miles'];
        }

        if ($weather['ceiling']) {
            $data['ceiling_ft'] = $weather['ceiling']['feet'];
        }

        if ($weather['altimeter']) {
            $data['altimeter_inhg'] = $weather['altimeter']['inhg'];
            $data['altimeter_hpa'] = $weather['altimeter']['hpa'];
        }

        $data['temperature_c'] = $weather['temperature'];
        $data['dewpoint_c'] = $weather['dewpoint'];

        return $this->post('/ingest/weather', $data);
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
                'X-SWIM-Source: vatis',
                'User-Agent: VATSWIM-vATIS/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->verbose) {
            error_log("[VATSWIM-vATIS] POST $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-vATIS] cURL error: $error");
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * GET data from VATSWIM API
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
                'User-Agent: VATSWIM-vATIS/1.0.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Get active flights from VATSWIM for correlation
     *
     * @param array $airports Filter by airports (optional)
     * @return array Flights
     */
    public function getActiveFlights(array $airports = []): array
    {
        $endpoint = '/flights?status=active';

        if (!empty($airports)) {
            $endpoint .= '&airports=' . implode(',', $airports);
        }

        $response = $this->get($endpoint);
        return $response['flights'] ?? [];
    }
}
