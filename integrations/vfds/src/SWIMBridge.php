<?php
/**
 * VATSWIM vFDS SWIM Bridge
 *
 * Bidirectional data bridge between vFDS and VATSWIM.
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 * @version 1.0.0
 */

namespace VatSwim\VFDS;

/**
 * SWIM Bridge - Bidirectional sync between vFDS and VATSWIM
 */
class SWIMBridge
{
    private EDSTClient $edstClient;
    private TDLSSync $tdlsSync;
    private DepartureSequencer $sequencer;
    private string $swimApiKey;
    private string $swimBaseUrl;
    private bool $verbose;

    public function __construct(
        EDSTClient $edstClient,
        TDLSSync $tdlsSync,
        DepartureSequencer $sequencer,
        string $swimApiKey,
        string $swimBaseUrl
    ) {
        $this->edstClient = $edstClient;
        $this->tdlsSync = $tdlsSync;
        $this->sequencer = $sequencer;
        $this->swimApiKey = $swimApiKey;
        $this->swimBaseUrl = rtrim($swimBaseUrl, '/');
        $this->verbose = false;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
        $this->edstClient->setVerbose($verbose);
        $this->tdlsSync->setVerbose($verbose);
    }

    /**
     * Perform full bidirectional sync
     *
     * @param string|null $airport Filter by airport
     * @return array Sync statistics
     */
    public function sync(?string $airport = null): array
    {
        $stats = [
            'vfds_to_swim' => [
                'departures' => 0,
                'arrivals' => 0,
                'tmi' => 0,
                'errors' => 0
            ],
            'swim_to_vfds' => [
                'flights' => 0,
                'tmi' => 0,
                'errors' => 0
            ],
            'sequencing' => [
                'processed' => 0,
                'updated' => 0
            ]
        ];

        // Step 1: Sync departures from vFDS to SWIM
        $deptStats = $this->tdlsSync->syncToSWIM($airport);
        $stats['vfds_to_swim']['departures'] = $deptStats['synced'];
        $stats['vfds_to_swim']['errors'] += $deptStats['errors'];

        // Step 2: Sync TMI data from vFDS to SWIM
        $tmiStats = $this->syncTMIFromVFDS($airport);
        $stats['vfds_to_swim']['tmi'] = $tmiStats['synced'];
        $stats['vfds_to_swim']['errors'] += $tmiStats['errors'];

        // Step 3: Get active flights from SWIM
        $flights = $this->getActiveFlightsFromSWIM($airport);

        // Step 4: Calculate sequences
        if (!empty($flights)) {
            $sequence = $this->sequencer->sequence($flights);
            $sequence = $this->sequencer->applyEDCTs($sequence);
            $sequence = $this->sequencer->calculateDelays($sequence);

            $stats['sequencing']['processed'] = count($sequence);

            // Step 5: Push sequenced data back to SWIM
            $seqStats = $this->pushSequencesToSWIM($sequence);
            $stats['sequencing']['updated'] = $seqStats['synced'];
        }

        // Step 6: Push TMI/flow data from SWIM to vFDS
        if ($airport) {
            $swimTMI = $this->getTMIFromSWIM($airport);
            if ($swimTMI) {
                if ($this->tdlsSync->syncTMIToVFDS($airport, $swimTMI)) {
                    $stats['swim_to_vfds']['tmi']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Handle webhook from vFDS
     *
     * @param array $payload Webhook payload
     * @return bool Success
     */
    public function handleWebhook(array $payload): bool
    {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];

        switch ($event) {
            case 'departure.updated':
                return $this->handleDepartureUpdate($data);

            case 'arrival.updated':
                return $this->handleArrivalUpdate($data);

            case 'edct.assigned':
                return $this->handleEDCTAssigned($data);

            case 'edct.cancelled':
                return $this->handleEDCTCancelled($data);

            case 'tmi.activated':
            case 'tmi.updated':
            case 'tmi.cancelled':
                return $this->handleTMIEvent($event, $data);

            default:
                if ($this->verbose) {
                    error_log("[VATSWIM-vFDS] Unknown webhook event: $event");
                }
                return false;
        }
    }

    /**
     * Get flight enrichment from SWIM for vFDS display
     *
     * @param string $callsign Aircraft callsign
     * @return array|null Enrichment data
     */
    public function getFlightEnrichment(string $callsign): ?array
    {
        $endpoint = "/flights/" . urlencode($callsign);
        $flight = $this->getFromSWIM($endpoint);

        if (!$flight) {
            return null;
        }

        return [
            'callsign' => $callsign,
            'tmi_status' => $flight['tmi_status'] ?? null,
            'flow_constrained' => $flight['flow_constrained'] ?? false,
            'slot_time' => $flight['slot_time_utc'] ?? null,
            'expected_delay' => $flight['delay_minutes'] ?? 0,
            'metering' => [
                'sta' => $flight['sta_utc'] ?? null,
                'eta' => $flight['eta_utc'] ?? null,
                'meter_fix' => $flight['meter_fix'] ?? null
            ],
            'sequence' => [
                'position' => $flight['departure_sequence'] ?? null,
                'runway' => $flight['departure_runway'] ?? null
            ]
        ];
    }

    /**
     * Sync TMI data from vFDS to SWIM
     */
    private function syncTMIFromVFDS(?string $airport): array
    {
        $stats = ['synced' => 0, 'errors' => 0];

        // Get ground stops
        $groundStops = $this->edstClient->getGroundStops();
        foreach ($groundStops as $gs) {
            if ($airport && ($gs['airport'] ?? '') !== $airport) {
                continue;
            }

            $data = [
                'source' => 'vfds',
                'type' => 'ground_stop',
                'airport' => $gs['airport'],
                'status' => $gs['status'] ?? 'ACTIVE',
                'reason' => $gs['reason'] ?? null,
                'start_time' => $gs['start_time'] ?? null,
                'end_time' => $gs['end_time'] ?? null
            ];

            if ($this->postToSWIM('/tmi/programs', $data)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        // Get GDPs
        $gdps = $this->edstClient->getGDPs();
        foreach ($gdps as $gdp) {
            if ($airport && ($gdp['airport'] ?? '') !== $airport) {
                continue;
            }

            $data = [
                'source' => 'vfds',
                'type' => 'gdp',
                'airport' => $gdp['airport'],
                'status' => $gdp['status'] ?? 'ACTIVE',
                'adr' => $gdp['adr'] ?? null,
                'scope' => $gdp['scope'] ?? null,
                'start_time' => $gdp['start_time'] ?? null,
                'end_time' => $gdp['end_time'] ?? null
            ];

            if ($this->postToSWIM('/tmi/programs', $data)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        // Get MITs
        $mits = $this->edstClient->getMITs();
        foreach ($mits as $mit) {
            $data = [
                'source' => 'vfds',
                'type' => 'mit',
                'fix' => $mit['fix'] ?? null,
                'value' => $mit['value'] ?? null,
                'direction' => $mit['direction'] ?? null,
                'start_time' => $mit['start_time'] ?? null,
                'end_time' => $mit['end_time'] ?? null
            ];

            if ($this->postToSWIM('/tmi/measures', $data)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Get active flights from SWIM
     */
    private function getActiveFlightsFromSWIM(?string $airport): array
    {
        $endpoint = '/flights?status=active';
        if ($airport) {
            $endpoint .= '&dept_icao=' . urlencode($airport);
        }

        $response = $this->getFromSWIM($endpoint);
        return $response['flights'] ?? [];
    }

    /**
     * Get TMI data from SWIM
     */
    private function getTMIFromSWIM(string $airport): ?array
    {
        $endpoint = '/tmi/airports/' . urlencode($airport);
        return $this->getFromSWIM($endpoint);
    }

    /**
     * Push sequences back to SWIM
     */
    private function pushSequencesToSWIM(array $sequence): array
    {
        $stats = ['synced' => 0, 'errors' => 0];

        foreach ($sequence as $flight) {
            $data = [
                'callsign' => $flight['callsign'],
                'source' => 'vfds',
                'departure_sequence' => $flight['global_sequence'] ?? $flight['sequence'],
                'calculated_departure_time_utc' => $flight['calculated_departure_time'],
                'delay_minutes' => $flight['delay_minutes'] ?? 0
            ];

            if (!empty($flight['edct'])) {
                $data['edct_utc'] = $flight['edct'];
            }

            if ($this->postToSWIM('/ingest/adl', $data)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Handle departure update from vFDS
     */
    private function handleDepartureUpdate(array $data): bool
    {
        $swimData = [
            'callsign' => $data['callsign'] ?? null,
            'source' => 'vfds'
        ];

        if (!$swimData['callsign']) {
            return false;
        }

        // Map fields
        $fieldMap = [
            'departure_runway' => 'departure_runway',
            'sid' => 'sid',
            'squawk' => 'squawk',
            'route' => 'fp_route',
            'altitude' => 'fp_altitude_fl'
        ];

        foreach ($fieldMap as $vfdsField => $swimField) {
            if (isset($data[$vfdsField])) {
                $swimData[$swimField] = $data[$vfdsField];
            }
        }

        return $this->postToSWIM('/ingest/adl', $swimData);
    }

    /**
     * Handle arrival update from vFDS
     */
    private function handleArrivalUpdate(array $data): bool
    {
        $swimData = [
            'callsign' => $data['callsign'] ?? null,
            'source' => 'vfds'
        ];

        if (!$swimData['callsign']) {
            return false;
        }

        $fieldMap = [
            'arrival_runway' => 'arrival_runway',
            'star' => 'star',
            'approach' => 'expected_approach'
        ];

        foreach ($fieldMap as $vfdsField => $swimField) {
            if (isset($data[$vfdsField])) {
                $swimData[$swimField] = $data[$vfdsField];
            }
        }

        return $this->postToSWIM('/ingest/adl', $swimData);
    }

    /**
     * Handle EDCT assignment from vFDS
     */
    private function handleEDCTAssigned(array $data): bool
    {
        $swimData = [
            'callsign' => $data['callsign'] ?? null,
            'source' => 'vfds',
            'edct_utc' => $data['edct'] ?? null,
            'edct_reason' => $data['reason'] ?? null,
            'edct_facility' => $data['facility'] ?? null
        ];

        if (!$swimData['callsign'] || !$swimData['edct_utc']) {
            return false;
        }

        return $this->postToSWIM('/ingest/adl', $swimData);
    }

    /**
     * Handle EDCT cancellation from vFDS
     */
    private function handleEDCTCancelled(array $data): bool
    {
        $swimData = [
            'callsign' => $data['callsign'] ?? null,
            'source' => 'vfds',
            'edct_utc' => null,
            'edct_cancelled' => true
        ];

        if (!$swimData['callsign']) {
            return false;
        }

        return $this->postToSWIM('/ingest/adl', $swimData);
    }

    /**
     * Handle TMI event from vFDS
     */
    private function handleTMIEvent(string $event, array $data): bool
    {
        $swimData = [
            'source' => 'vfds',
            'event' => $event,
            'type' => $data['type'] ?? null,
            'airport' => $data['airport'] ?? null,
            'status' => $data['status'] ?? null
        ];

        // Copy relevant fields
        $fields = ['reason', 'adr', 'scope', 'start_time', 'end_time', 'fix', 'value'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $swimData[$field] = $data[$field];
            }
        }

        return $this->postToSWIM('/tmi/events', $swimData);
    }

    /**
     * POST to SWIM API
     */
    private function postToSWIM(string $endpoint, array $data): bool
    {
        $url = $this->swimBaseUrl . $endpoint;
        $json = json_encode($data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->swimApiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'X-SWIM-Source: vfds',
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
            error_log("[VATSWIM-vFDS] POST $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-vFDS] cURL error: $error");
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * GET from SWIM API
     */
    private function getFromSWIM(string $endpoint): ?array
    {
        $url = $this->swimBaseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->swimApiKey,
                'Accept: application/json',
                'User-Agent: VATSWIM-vFDS/1.0.0'
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
}
