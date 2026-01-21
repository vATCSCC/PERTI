<?php

namespace Modules\Vatswim\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * VATSWIM API Client for phpVMS 7
 *
 * Handles all communication with the VATSWIM API.
 */
class VatswimClient
{
    protected Client $client;
    protected string $apiKey;
    protected string $baseUrl;
    protected string $logChannel;

    public function __construct(string $apiKey, string $baseUrl)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logChannel = config('vatswim.log_channel', 'stack');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-SWIM-Source' => 'phpvms',
                'User-Agent' => 'phpVMS-VATSWIM/1.0.0'
            ]
        ]);
    }

    /**
     * Submit flight data to VATSWIM ADL ingest
     *
     * @param array $flightData Flight data in VATSWIM format
     * @return array|null Response data or null on failure
     */
    public function submitFlight(array $flightData): ?array
    {
        return $this->post('/ingest/adl', $flightData);
    }

    /**
     * Submit OOOI times
     *
     * @param string $callsign Flight callsign
     * @param string $deptIcao Departure ICAO
     * @param string $destIcao Destination ICAO
     * @param array $times OOOI times (out_utc, off_utc, on_utc, in_utc)
     * @return array|null
     */
    public function submitOOOI(string $callsign, string $deptIcao, string $destIcao, array $times): ?array
    {
        $data = [
            'callsign' => $callsign,
            'dept_icao' => $deptIcao,
            'dest_icao' => $destIcao,
            'source' => 'phpvms'
        ];

        // Add provided times
        foreach (['out_utc', 'off_utc', 'on_utc', 'in_utc'] as $field) {
            if (isset($times[$field])) {
                $data[$field] = $times[$field];
            }
        }

        return $this->post('/ingest/adl', $data);
    }

    /**
     * Submit CDM prediction times (T1-T4)
     *
     * @param string $callsign Flight callsign
     * @param string $deptIcao Departure ICAO
     * @param string $destIcao Destination ICAO
     * @param array $predictions CDM prediction times
     * @return array|null
     */
    public function submitCDMPredictions(string $callsign, string $deptIcao, string $destIcao, array $predictions): ?array
    {
        $data = [
            'callsign' => $callsign,
            'dept_icao' => $deptIcao,
            'dest_icao' => $destIcao,
            'source' => 'phpvms'
        ];

        // CDM T1-T4 fields
        $cdmFields = ['lrtd_utc', 'lrta_utc', 'lgtd_utc', 'lgta_utc', 'ertd_utc', 'erta_utc'];
        foreach ($cdmFields as $field) {
            if (isset($predictions[$field])) {
                $data[$field] = $predictions[$field];
            }
        }

        return $this->post('/ingest/adl', $data);
    }

    /**
     * Submit schedule times (STD/STA)
     *
     * @param string $callsign Flight callsign
     * @param string $deptIcao Departure ICAO
     * @param string $destIcao Destination ICAO
     * @param string|null $stdUtc Scheduled Time of Departure
     * @param string|null $staUtc Scheduled Time of Arrival
     * @return array|null
     */
    public function submitSchedule(string $callsign, string $deptIcao, string $destIcao,
                                   ?string $stdUtc, ?string $staUtc): ?array
    {
        $data = [
            'callsign' => $callsign,
            'dept_icao' => $deptIcao,
            'dest_icao' => $destIcao,
            'source' => 'phpvms'
        ];

        if ($stdUtc) {
            $data['std_utc'] = $stdUtc;
        }
        if ($staUtc) {
            $data['sta_utc'] = $staUtc;
        }

        return $this->post('/ingest/adl', $data);
    }

    /**
     * Get flight data from VATSWIM
     *
     * @param string $callsign Flight callsign
     * @return array|null
     */
    public function getFlight(string $callsign): ?array
    {
        return $this->get('/flights/' . urlencode($callsign));
    }

    /**
     * Make a POST request to the API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @return array|null Response data or null on failure
     */
    protected function post(string $endpoint, array $data): ?array
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $data
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (config('vatswim.verbose_logging')) {
                Log::channel($this->logChannel)->debug('[VATSWIM] POST ' . $endpoint, [
                    'request' => $data,
                    'response' => $body,
                    'status' => $response->getStatusCode()
                ]);
            }

            return $body;

        } catch (GuzzleException $e) {
            Log::channel($this->logChannel)->error('[VATSWIM] API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Make a GET request to the API
     *
     * @param string $endpoint API endpoint
     * @param array $query Query parameters
     * @return array|null
     */
    protected function get(string $endpoint, array $query = []): ?array
    {
        try {
            $response = $this->client->get($endpoint, [
                'query' => $query
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (config('vatswim.verbose_logging')) {
                Log::channel($this->logChannel)->debug('[VATSWIM] GET ' . $endpoint, [
                    'response' => $body,
                    'status' => $response->getStatusCode()
                ]);
            }

            return $body;

        } catch (GuzzleException $e) {
            Log::channel($this->logChannel)->error('[VATSWIM] API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
