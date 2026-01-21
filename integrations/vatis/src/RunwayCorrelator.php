<?php
/**
 * VATSWIM Runway Correlator
 *
 * Correlates ATIS runway information with active flights.
 *
 * @package VATSWIM
 * @subpackage vATIS Integration
 * @version 1.0.0
 */

namespace VatSwim\VATIS;

/**
 * Runway Correlator - Matches ATIS runways to flights
 */
class RunwayCorrelator
{
    private ATISMonitor $atisMonitor;
    private array $flightCache = [];

    public function __construct(ATISMonitor $atisMonitor)
    {
        $this->atisMonitor = $atisMonitor;
    }

    /**
     * Correlate a flight with ATIS runway data
     *
     * @param array $flight Flight data with dept_icao and/or dest_icao
     * @return array Correlated data with runway assignments
     */
    public function correlate(array $flight): array
    {
        $result = [
            'callsign' => $flight['callsign'] ?? null,
            'departure' => null,
            'arrival' => null
        ];

        // Correlate departure
        $deptIcao = $flight['dept_icao'] ?? $flight['departure_icao'] ?? null;
        if ($deptIcao) {
            $atis = $this->atisMonitor->getATISForAirport($deptIcao);
            if ($atis) {
                $result['departure'] = $this->extractDepartureInfo($atis, $flight);
            }
        }

        // Correlate arrival
        $destIcao = $flight['dest_icao'] ?? $flight['destination_icao'] ?? null;
        if ($destIcao) {
            $atis = $this->atisMonitor->getATISForAirport($destIcao);
            if ($atis) {
                $result['arrival'] = $this->extractArrivalInfo($atis, $flight);
            }
        }

        return $result;
    }

    /**
     * Batch correlate multiple flights
     *
     * @param array $flights Array of flight data
     * @return array Array of correlated results keyed by callsign
     */
    public function correlateMultiple(array $flights): array
    {
        $results = [];

        foreach ($flights as $flight) {
            $callsign = $flight['callsign'] ?? null;
            if ($callsign) {
                $results[$callsign] = $this->correlate($flight);
            }
        }

        return $results;
    }

    /**
     * Get expected departure runway for a flight
     *
     * @param string $icao Departure airport ICAO
     * @param array|null $flight Optional flight data for SID matching
     * @return string|null Expected departure runway
     */
    public function getExpectedDepartureRunway(string $icao, ?array $flight = null): ?string
    {
        $atis = $this->atisMonitor->getATISForAirport($icao);
        if (!$atis) {
            return null;
        }

        $runways = $atis['runways_departure'] ?? [];
        if (empty($runways)) {
            return null;
        }

        // If only one runway, return it
        if (count($runways) === 1) {
            return $runways[0];
        }

        // If flight has SID, try to match runway
        if ($flight && !empty($flight['sid'])) {
            $matchedRunway = $this->matchSIDToRunway($flight['sid'], $runways);
            if ($matchedRunway) {
                return $matchedRunway;
            }
        }

        // Return first runway as default
        return $runways[0];
    }

    /**
     * Get expected arrival runway for a flight
     *
     * @param string $icao Destination airport ICAO
     * @param array|null $flight Optional flight data for approach matching
     * @return string|null Expected arrival runway
     */
    public function getExpectedArrivalRunway(string $icao, ?array $flight = null): ?string
    {
        $atis = $this->atisMonitor->getATISForAirport($icao);
        if (!$atis) {
            return null;
        }

        $runways = $atis['runways_arrival'] ?? [];
        if (empty($runways)) {
            // Fall back to approaches in use
            $approaches = $atis['approaches_in_use'] ?? [];
            if (!empty($approaches)) {
                return $approaches[0]['runway'] ?? null;
            }
            return null;
        }

        // If only one runway, return it
        if (count($runways) === 1) {
            return $runways[0];
        }

        // If flight has STAR, try to match runway
        if ($flight && !empty($flight['star'])) {
            $matchedRunway = $this->matchSTARToRunway($flight['star'], $runways);
            if ($matchedRunway) {
                return $matchedRunway;
            }
        }

        // Return first runway as default
        return $runways[0];
    }

    /**
     * Get expected approach type for a runway
     *
     * @param string $icao Airport ICAO
     * @param string $runway Runway number
     * @return string|null Approach type (ILS, RNAV, etc.)
     */
    public function getExpectedApproach(string $icao, string $runway): ?string
    {
        $atis = $this->atisMonitor->getATISForAirport($icao);
        if (!$atis) {
            return null;
        }

        $approaches = $atis['approaches_in_use'] ?? [];
        foreach ($approaches as $approach) {
            if ($approach['runway'] === $runway) {
                return $approach['type'];
            }
        }

        return null;
    }

    /**
     * Extract departure info from ATIS for a flight
     */
    private function extractDepartureInfo(array $atis, array $flight): array
    {
        $info = [
            'airport' => $atis['icao'],
            'atis_letter' => $atis['letter'],
            'atis_time' => $atis['time_utc'],
            'wind' => $atis['wind'],
            'altimeter' => $atis['altimeter'],
            'available_runways' => $atis['runways_departure'],
            'expected_runway' => null
        ];

        // Determine expected runway
        $sid = $flight['sid'] ?? null;
        $runways = $atis['runways_departure'] ?? [];

        if (!empty($runways)) {
            if (count($runways) === 1) {
                $info['expected_runway'] = $runways[0];
            } elseif ($sid) {
                $info['expected_runway'] = $this->matchSIDToRunway($sid, $runways);
            }

            if (!$info['expected_runway']) {
                $info['expected_runway'] = $runways[0];
            }
        }

        return $info;
    }

    /**
     * Extract arrival info from ATIS for a flight
     */
    private function extractArrivalInfo(array $atis, array $flight): array
    {
        $info = [
            'airport' => $atis['icao'],
            'atis_letter' => $atis['letter'],
            'atis_time' => $atis['time_utc'],
            'wind' => $atis['wind'],
            'altimeter' => $atis['altimeter'],
            'visibility' => $atis['visibility'],
            'ceiling' => $atis['ceiling'],
            'available_runways' => $atis['runways_arrival'],
            'approaches_in_use' => $atis['approaches_in_use'],
            'expected_runway' => null,
            'expected_approach' => null
        ];

        // Determine expected runway
        $star = $flight['star'] ?? null;
        $runways = $atis['runways_arrival'] ?? [];

        if (!empty($runways)) {
            if (count($runways) === 1) {
                $info['expected_runway'] = $runways[0];
            } elseif ($star) {
                $info['expected_runway'] = $this->matchSTARToRunway($star, $runways);
            }

            if (!$info['expected_runway']) {
                $info['expected_runway'] = $runways[0];
            }

            // Get approach type for expected runway
            if ($info['expected_runway']) {
                $approaches = $atis['approaches_in_use'] ?? [];
                foreach ($approaches as $approach) {
                    if ($approach['runway'] === $info['expected_runway']) {
                        $info['expected_approach'] = $approach['type'];
                        break;
                    }
                }
            }
        }

        return $info;
    }

    /**
     * Match SID name to available runways
     *
     * @param string $sid SID name
     * @param array $runways Available runways
     * @return string|null Matched runway
     */
    private function matchSIDToRunway(string $sid, array $runways): ?string
    {
        $sid = strtoupper($sid);

        // Common patterns:
        // - RNAV departures often have runway in name (e.g., KORRY4 for 04 departures)
        // - Runway numbers embedded (e.g., DEEZZ5.4L)

        // Check if SID explicitly includes runway
        foreach ($runways as $runway) {
            // Pattern like "DEEZZ5.4L" or "RNAV_4L"
            if (preg_match('/[^0-9]' . preg_quote($runway, '/') . '$/i', $sid)) {
                return $runway;
            }
        }

        // Check for runway number at end of SID
        if (preg_match('/(\d{1,2}[LRC]?)$/', $sid, $match)) {
            $sidRunway = $match[1];
            foreach ($runways as $runway) {
                if ($runway === $sidRunway) {
                    return $runway;
                }
            }
        }

        return null;
    }

    /**
     * Match STAR name to available runways
     *
     * @param string $star STAR name
     * @param array $runways Available runways
     * @return string|null Matched runway
     */
    private function matchSTARToRunway(string $star, array $runways): ?string
    {
        $star = strtoupper($star);

        // Similar logic to SID matching
        foreach ($runways as $runway) {
            if (preg_match('/[^0-9]' . preg_quote($runway, '/') . '$/i', $star)) {
                return $runway;
            }
        }

        if (preg_match('/(\d{1,2}[LRC]?)$/', $star, $match)) {
            $starRunway = $match[1];
            foreach ($runways as $runway) {
                if ($runway === $starRunway) {
                    return $runway;
                }
            }
        }

        return null;
    }

    /**
     * Check if conditions are IFR at an airport
     *
     * @param string $icao Airport ICAO
     * @return bool|null True if IFR, false if VFR, null if unknown
     */
    public function isIFR(string $icao): ?bool
    {
        $atis = $this->atisMonitor->getATISForAirport($icao);
        if (!$atis) {
            return null;
        }

        $visibility = $atis['visibility'];
        $ceiling = $atis['ceiling'];

        // Can't determine without weather data
        if ($visibility === null && $ceiling === null) {
            return null;
        }

        // IFR: ceiling below 1000 feet or visibility below 3 SM
        if ($ceiling !== null && $ceiling < 1000) {
            return true;
        }

        if ($visibility !== null) {
            // Handle fractional visibility
            if (is_string($visibility) && str_contains($visibility, '/')) {
                $parts = explode('/', $visibility);
                $vis = (float) $parts[0] / (float) $parts[1];
            } else {
                $vis = (float) $visibility;
            }

            if ($vis < 3) {
                return true;
            }
        }

        return false;
    }
}
