<?php
/**
 * VATSWIM TDLS (Tower Departure List) Sync
 *
 * Synchronizes departure list data between vFDS and VATSWIM.
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 * @version 1.0.0
 */

namespace VatSwim\VFDS;

/**
 * TDLS Sync - Tower Departure List synchronization
 */
class TDLSSync
{
    private EDSTClient $edstClient;
    private string $swimApiKey;
    private string $swimBaseUrl;
    private bool $verbose;

    public function __construct(EDSTClient $edstClient, string $swimApiKey, string $swimBaseUrl)
    {
        $this->edstClient = $edstClient;
        $this->swimApiKey = $swimApiKey;
        $this->swimBaseUrl = rtrim($swimBaseUrl, '/');
        $this->verbose = false;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
        $this->edstClient->setVerbose($verbose);
    }

    /**
     * Sync departure list from vFDS to VATSWIM
     *
     * @param string|null $airport Filter by airport
     * @return array Sync statistics
     */
    public function syncToSWIM(?string $airport = null): array
    {
        $stats = [
            'fetched' => 0,
            'synced' => 0,
            'errors' => 0
        ];

        // Get departure list from vFDS
        $departures = $this->edstClient->getDepartureList($airport);
        $stats['fetched'] = count($departures);

        foreach ($departures as $departure) {
            $swimData = $this->transformDepartureToSWIM($departure);

            if ($this->postToSWIM('/ingest/adl', $swimData)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Sync EDCT assignments to VATSWIM
     *
     * @param array $edctList List of EDCT assignments
     * @return array Sync statistics
     */
    public function syncEDCTsToSWIM(array $edctList): array
    {
        $stats = [
            'processed' => 0,
            'synced' => 0,
            'errors' => 0
        ];

        foreach ($edctList as $edct) {
            $stats['processed']++;

            $swimData = [
                'callsign' => $edct['callsign'],
                'source' => 'vfds',
                'edct_utc' => $edct['edct'],
                'edct_reason' => $edct['reason'] ?? null,
                'edct_facility' => $edct['facility'] ?? null
            ];

            if ($this->postToSWIM('/ingest/adl', $swimData)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Push flight data from VATSWIM to vFDS
     *
     * @param array $flights Flights to push
     * @return array Sync statistics
     */
    public function pushFlightsToVFDS(array $flights): array
    {
        $stats = [
            'processed' => 0,
            'synced' => 0,
            'errors' => 0
        ];

        foreach ($flights as $flight) {
            $stats['processed']++;

            $annotations = $this->buildVFDSAnnotations($flight);
            $callsign = $flight['callsign'] ?? null;

            if (!$callsign) {
                $stats['errors']++;
                continue;
            }

            if ($this->edstClient->updateAnnotations($callsign, $annotations)) {
                $stats['synced']++;
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Sync TMI status to vFDS
     *
     * @param string $airport Airport ICAO
     * @param array $tmiData TMI data from VATSWIM
     * @return bool Success
     */
    public function syncTMIToVFDS(string $airport, array $tmiData): bool
    {
        // Build annotation data for TMI
        $annotations = [];

        if (!empty($tmiData['ground_stop'])) {
            $annotations['GS'] = $tmiData['ground_stop']['status'] ?? 'ACTIVE';
            $annotations['GS_REASON'] = $tmiData['ground_stop']['reason'] ?? '';
        }

        if (!empty($tmiData['gdp'])) {
            $annotations['GDP'] = $tmiData['gdp']['status'] ?? 'ACTIVE';
            $annotations['GDP_ADR'] = $tmiData['gdp']['adr'] ?? '';
        }

        if (!empty($tmiData['mit'])) {
            $annotations['MIT'] = $tmiData['mit']['value'] ?? '';
            $annotations['MIT_FIX'] = $tmiData['mit']['fix'] ?? '';
        }

        // This would push to vFDS facility-level annotations
        // Implementation depends on vFDS API specifics
        return true;
    }

    /**
     * Get departure sequence for an airport
     *
     * @param string $airport Airport ICAO
     * @return array Sequenced departures
     */
    public function getDepartureSequence(string $airport): array
    {
        $departures = $this->edstClient->getDepartureList($airport);

        // Sort by expected departure time
        usort($departures, function ($a, $b) {
            $timeA = $a['p_time'] ?? $a['proposed_time'] ?? PHP_INT_MAX;
            $timeB = $b['p_time'] ?? $b['proposed_time'] ?? PHP_INT_MAX;
            return $timeA <=> $timeB;
        });

        // Add sequence numbers
        $sequence = 1;
        foreach ($departures as &$departure) {
            $departure['sequence'] = $sequence++;
        }

        return $departures;
    }

    /**
     * Transform vFDS departure to VATSWIM format
     */
    private function transformDepartureToSWIM(array $departure): array
    {
        $data = [
            'source' => 'vfds'
        ];

        // Callsign
        if (!empty($departure['callsign'])) {
            $data['callsign'] = $departure['callsign'];
        }

        // Airports
        if (!empty($departure['departure']) || !empty($departure['dep'])) {
            $data['dept_icao'] = $departure['departure'] ?? $departure['dep'];
        }

        if (!empty($departure['destination']) || !empty($departure['dest'])) {
            $data['dest_icao'] = $departure['destination'] ?? $departure['dest'];
        }

        // Times
        if (!empty($departure['p_time'])) {
            $data['proposed_departure_time_utc'] = $departure['p_time'];
        }

        if (!empty($departure['edct'])) {
            $data['edct_utc'] = $departure['edct'];
        }

        // Flight data
        if (!empty($departure['aircraft_type']) || !empty($departure['type'])) {
            $data['aircraft_type'] = $departure['aircraft_type'] ?? $departure['type'];
        }

        if (!empty($departure['altitude']) || !empty($departure['rfl'])) {
            $data['fp_altitude_ft'] = ($departure['altitude'] ?? $departure['rfl']) * 100;
        }

        if (!empty($departure['route'])) {
            $data['fp_route'] = $departure['route'];
        }

        // Runway/SID
        if (!empty($departure['departure_runway']) || !empty($departure['drwy'])) {
            $data['departure_runway'] = $departure['departure_runway'] ?? $departure['drwy'];
        }

        if (!empty($departure['sid'])) {
            $data['sid'] = $departure['sid'];
        }

        // Sequence
        if (!empty($departure['sequence'])) {
            $data['departure_sequence'] = $departure['sequence'];
        }

        // EDST specific fields
        if (!empty($departure['coordination_fix'])) {
            $data['coordination_fix'] = $departure['coordination_fix'];
        }

        if (!empty($departure['coordination_time'])) {
            $data['coordination_time_utc'] = $departure['coordination_time'];
        }

        return $data;
    }

    /**
     * Build vFDS annotations from VATSWIM flight data
     */
    private function buildVFDSAnnotations(array $flight): array
    {
        $annotations = [];

        // TMI status
        if (!empty($flight['tmi_status'])) {
            $annotations['TMI'] = $flight['tmi_status'];
        }

        // Metering times
        if (!empty($flight['sta_utc'])) {
            $annotations['STA'] = $flight['sta_utc'];
        }

        if (!empty($flight['eta_utc'])) {
            $annotations['ETA'] = $flight['eta_utc'];
        }

        // Slot times
        if (!empty($flight['slot_time_utc'])) {
            $annotations['SLOT'] = $flight['slot_time_utc'];
        }

        // Ground delay
        if (!empty($flight['delay_minutes'])) {
            $annotations['DLY'] = $flight['delay_minutes'] . 'M';
        }

        // Flow control
        if (!empty($flight['flow_constrained'])) {
            $annotations['FLOW'] = 'Y';
        }

        // Reroute
        if (!empty($flight['rerouted'])) {
            $annotations['RRTE'] = 'Y';
        }

        return $annotations;
    }

    /**
     * POST to VATSWIM API
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
            error_log("[VATSWIM-vFDS] POST SWIM $endpoint - HTTP $httpCode");
        }

        if ($error) {
            error_log("[VATSWIM-vFDS] cURL error: $error");
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * GET from VATSWIM API
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
