<?php
/**
 * VATSWIM Departure Sequencer
 *
 * Calculates departure sequences based on various constraints.
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 * @version 1.0.0
 */

namespace VatSwim\VFDS;

/**
 * Departure Sequencer - Calculates optimal departure sequences
 */
class DepartureSequencer
{
    /**
     * Separation requirements by wake category (seconds)
     */
    private const WAKE_SEPARATION = [
        'SUPER' => ['SUPER' => 120, 'HEAVY' => 120, 'LARGE' => 120, 'SMALL' => 120],
        'HEAVY' => ['SUPER' => 180, 'HEAVY' => 120, 'LARGE' => 120, 'SMALL' => 120],
        'LARGE' => ['SUPER' => 180, 'HEAVY' => 120, 'LARGE' => 90, 'SMALL' => 90],
        'SMALL' => ['SUPER' => 180, 'HEAVY' => 120, 'LARGE' => 90, 'SMALL' => 60]
    ];

    /**
     * Minimum departure interval (seconds) by runway config
     */
    private const MIN_DEPARTURE_INTERVAL = [
        'single' => 90,      // Single runway
        'parallel_close' => 60,  // Close parallel runways
        'parallel_far' => 45,    // Far parallel runways
        'intersecting' => 120    // Intersecting runways
    ];

    private array $constraints = [];
    private array $edcts = [];

    /**
     * Add flow constraint
     *
     * @param string $type Constraint type (MIT, MINIT, etc.)
     * @param array $params Constraint parameters
     */
    public function addConstraint(string $type, array $params): void
    {
        $this->constraints[] = [
            'type' => $type,
            'params' => $params
        ];
    }

    /**
     * Set EDCT assignments
     *
     * @param array $edcts Keyed by callsign
     */
    public function setEDCTs(array $edcts): void
    {
        $this->edcts = $edcts;
    }

    /**
     * Calculate departure sequence for a list of flights
     *
     * @param array $flights Flights to sequence
     * @param array $options Sequencing options
     * @return array Sequenced flights with departure times
     */
    public function sequence(array $flights, array $options = []): array
    {
        $runwayConfig = $options['runway_config'] ?? 'single';
        $currentTime = $options['current_time'] ?? time();
        $maxLookahead = $options['max_lookahead'] ?? 7200; // 2 hours

        // Separate flights by runway
        $byRunway = $this->groupByRunway($flights);

        // Calculate sequence for each runway
        $sequences = [];
        foreach ($byRunway as $runway => $runwayFlights) {
            $sequences[$runway] = $this->sequenceRunway(
                $runwayFlights,
                $runway,
                $runwayConfig,
                $currentTime,
                $maxLookahead
            );
        }

        // Merge and re-sequence if multiple runways
        return $this->mergeSequences($sequences, $runwayConfig);
    }

    /**
     * Calculate Required Navigation Performance (RBS) order
     *
     * @param array $flights Flights to sequence
     * @return array RBS ordered flights
     */
    public function calculateRBS(array $flights): array
    {
        // Ration By Schedule - sort by original proposed time
        usort($flights, function ($a, $b) {
            $timeA = strtotime($a['proposed_departure_time_utc'] ?? $a['p_time'] ?? '23:59');
            $timeB = strtotime($b['proposed_departure_time_utc'] ?? $b['p_time'] ?? '23:59');
            return $timeA <=> $timeB;
        });

        return array_values($flights);
    }

    /**
     * Calculate First Come First Served order
     *
     * @param array $flights Flights to sequence
     * @return array FCFS ordered flights
     */
    public function calculateFCFS(array $flights): array
    {
        // First Come First Served - sort by ready time
        usort($flights, function ($a, $b) {
            $timeA = strtotime($a['ready_time_utc'] ?? $a['wheels_up_time'] ?? '23:59');
            $timeB = strtotime($b['ready_time_utc'] ?? $b['wheels_up_time'] ?? '23:59');
            return $timeA <=> $timeB;
        });

        return array_values($flights);
    }

    /**
     * Calculate required departure time for a meter fix
     *
     * @param array $flight Flight data
     * @param string $meterFix Meter fix name
     * @param string $staUtc Scheduled Time of Arrival at meter fix
     * @return string|null Required departure time
     */
    public function calculateRequiredDepartureTime(array $flight, string $meterFix, string $staUtc): ?string
    {
        // Get flight time to meter fix
        $flightTimeMinutes = $this->estimateFlightTimeToFix($flight, $meterFix);
        if ($flightTimeMinutes === null) {
            return null;
        }

        // Add taxi time
        $taxiTimeMinutes = $flight['estimated_taxi_time'] ?? 15;

        // Total time needed
        $totalMinutes = $flightTimeMinutes + $taxiTimeMinutes;

        // Calculate required departure (wheels up) time
        $staTimestamp = strtotime($staUtc);
        $requiredTimestamp = $staTimestamp - ($totalMinutes * 60);

        return gmdate('Y-m-d\TH:i:s\Z', $requiredTimestamp);
    }

    /**
     * Apply EDCTs to sequence
     *
     * @param array $sequence Current sequence
     * @return array Sequence with EDCTs applied
     */
    public function applyEDCTs(array $sequence): array
    {
        foreach ($sequence as &$flight) {
            $callsign = $flight['callsign'] ?? '';

            if (isset($this->edcts[$callsign])) {
                $flight['edct'] = $this->edcts[$callsign];
                $flight['has_edct'] = true;

                // Adjust calculated departure time if EDCT is later
                $edctTimestamp = strtotime($this->edcts[$callsign]);
                $calcTimestamp = strtotime($flight['calculated_departure_time'] ?? '00:00');

                if ($edctTimestamp > $calcTimestamp) {
                    $flight['calculated_departure_time'] = gmdate('Y-m-d\TH:i:s\Z', $edctTimestamp);
                    $flight['delayed_by_edct'] = true;
                }
            }
        }

        return $sequence;
    }

    /**
     * Calculate delay for each flight
     *
     * @param array $sequence Sequenced flights
     * @return array Flights with delay information
     */
    public function calculateDelays(array $sequence): array
    {
        foreach ($sequence as &$flight) {
            $proposed = strtotime($flight['proposed_departure_time_utc'] ?? $flight['p_time'] ?? '00:00');
            $calculated = strtotime($flight['calculated_departure_time'] ?? '00:00');

            $delaySeconds = max(0, $calculated - $proposed);
            $flight['delay_minutes'] = (int) ($delaySeconds / 60);
            $flight['delay_category'] = $this->categorizeDelay($flight['delay_minutes']);
        }

        return $sequence;
    }

    /**
     * Group flights by departure runway
     */
    private function groupByRunway(array $flights): array
    {
        $byRunway = [];

        foreach ($flights as $flight) {
            $runway = $flight['departure_runway'] ?? $flight['drwy'] ?? 'UNKNOWN';
            if (!isset($byRunway[$runway])) {
                $byRunway[$runway] = [];
            }
            $byRunway[$runway][] = $flight;
        }

        return $byRunway;
    }

    /**
     * Sequence flights for a single runway
     */
    private function sequenceRunway(
        array $flights,
        string $runway,
        string $runwayConfig,
        int $currentTime,
        int $maxLookahead
    ): array {
        // Start with RBS order
        $flights = $this->calculateRBS($flights);

        $minInterval = self::MIN_DEPARTURE_INTERVAL[$runwayConfig] ?? 90;
        $lastDepartureTime = $currentTime;
        $lastWakeCategory = null;

        $sequenced = [];
        $sequence = 1;

        foreach ($flights as $flight) {
            // Get proposed time
            $proposedTime = strtotime($flight['proposed_departure_time_utc'] ?? $flight['p_time'] ?? gmdate('c'));

            // Check if within lookahead
            if ($proposedTime > $currentTime + $maxLookahead) {
                continue;
            }

            // Calculate earliest possible departure
            $earliestTime = max($proposedTime, $currentTime);

            // Apply wake turbulence separation
            $wakeCategory = $this->getWakeCategory($flight);
            if ($lastWakeCategory) {
                $wakeSep = self::WAKE_SEPARATION[$lastWakeCategory][$wakeCategory] ?? 90;
                $earliestTime = max($earliestTime, $lastDepartureTime + $wakeSep);
            }

            // Apply minimum interval
            $earliestTime = max($earliestTime, $lastDepartureTime + $minInterval);

            // Apply constraints
            $earliestTime = $this->applyConstraints($flight, $earliestTime);

            // Set calculated departure time
            $flight['calculated_departure_time'] = gmdate('Y-m-d\TH:i:s\Z', $earliestTime);
            $flight['sequence'] = $sequence++;
            $flight['runway'] = $runway;

            $sequenced[] = $flight;

            $lastDepartureTime = $earliestTime;
            $lastWakeCategory = $wakeCategory;
        }

        return $sequenced;
    }

    /**
     * Merge sequences from multiple runways
     */
    private function mergeSequences(array $sequences, string $runwayConfig): array
    {
        $merged = [];

        foreach ($sequences as $runway => $flights) {
            foreach ($flights as $flight) {
                $merged[] = $flight;
            }
        }

        // Sort by calculated departure time
        usort($merged, function ($a, $b) {
            $timeA = strtotime($a['calculated_departure_time'] ?? '23:59');
            $timeB = strtotime($b['calculated_departure_time'] ?? '23:59');
            return $timeA <=> $timeB;
        });

        // Reassign global sequence numbers
        $sequence = 1;
        foreach ($merged as &$flight) {
            $flight['global_sequence'] = $sequence++;
        }

        return $merged;
    }

    /**
     * Get wake turbulence category for flight
     */
    private function getWakeCategory(array $flight): string
    {
        // Check for explicit category
        if (!empty($flight['wake_category'])) {
            return strtoupper($flight['wake_category']);
        }

        // Estimate from aircraft type
        $type = strtoupper($flight['aircraft_type'] ?? '');

        // Super
        if (in_array($type, ['A388', 'A380'])) {
            return 'SUPER';
        }

        // Heavy
        $heavyTypes = ['B744', 'B748', 'B772', 'B773', 'B77L', 'B77W', 'B788', 'B789', 'B78X',
                       'A332', 'A333', 'A339', 'A342', 'A343', 'A345', 'A346', 'A359', 'A35K',
                       'MD11', 'DC10', 'B763', 'B764'];
        if (in_array($type, $heavyTypes)) {
            return 'HEAVY';
        }

        // Small
        $smallTypes = ['C172', 'C182', 'PA28', 'PA32', 'SR22', 'DA40', 'DA42', 'C152', 'PA18'];
        if (in_array($type, $smallTypes)) {
            return 'SMALL';
        }

        // Default to Large
        return 'LARGE';
    }

    /**
     * Apply flow constraints to earliest time
     */
    private function applyConstraints(array $flight, int $earliestTime): int
    {
        foreach ($this->constraints as $constraint) {
            switch ($constraint['type']) {
                case 'MIT':
                    // Miles in trail - convert to time separation
                    $miles = $constraint['params']['miles'] ?? 10;
                    $groundspeed = $flight['estimated_groundspeed'] ?? 400;
                    $timeSeconds = ($miles / $groundspeed) * 3600;
                    // This would need tracking of previous flights to same fix
                    break;

                case 'MINIT':
                    // Minutes in trail
                    $minutes = $constraint['params']['minutes'] ?? 5;
                    // Similar to MIT
                    break;

                case 'AFP':
                    // Airspace Flow Program
                    if (!empty($constraint['params']['rate'])) {
                        // Rate-based constraint
                    }
                    break;
            }
        }

        return $earliestTime;
    }

    /**
     * Estimate flight time to a fix
     */
    private function estimateFlightTimeToFix(array $flight, string $fix): ?int
    {
        // This would ideally use route data and winds
        // For now, use a rough estimate based on distance

        // Default estimate if no better data
        return 30; // 30 minutes default
    }

    /**
     * Categorize delay duration
     */
    private function categorizeDelay(int $delayMinutes): string
    {
        if ($delayMinutes === 0) {
            return 'NONE';
        } elseif ($delayMinutes <= 15) {
            return 'MINOR';
        } elseif ($delayMinutes <= 30) {
            return 'MODERATE';
        } elseif ($delayMinutes <= 60) {
            return 'SIGNIFICANT';
        } else {
            return 'SEVERE';
        }
    }

    /**
     * Get sequence statistics
     *
     * @param array $sequence Sequenced flights
     * @return array Statistics
     */
    public function getStatistics(array $sequence): array
    {
        $delays = array_column($sequence, 'delay_minutes');

        return [
            'total_flights' => count($sequence),
            'flights_with_edct' => count(array_filter($sequence, fn($f) => !empty($f['has_edct']))),
            'average_delay' => count($delays) > 0 ? array_sum($delays) / count($delays) : 0,
            'max_delay' => count($delays) > 0 ? max($delays) : 0,
            'min_delay' => count($delays) > 0 ? min($delays) : 0,
            'total_delay' => array_sum($delays),
            'by_category' => [
                'NONE' => count(array_filter($sequence, fn($f) => ($f['delay_category'] ?? '') === 'NONE')),
                'MINOR' => count(array_filter($sequence, fn($f) => ($f['delay_category'] ?? '') === 'MINOR')),
                'MODERATE' => count(array_filter($sequence, fn($f) => ($f['delay_category'] ?? '') === 'MODERATE')),
                'SIGNIFICANT' => count(array_filter($sequence, fn($f) => ($f['delay_category'] ?? '') === 'SIGNIFICANT')),
                'SEVERE' => count(array_filter($sequence, fn($f) => ($f['delay_category'] ?? '') === 'SEVERE'))
            ]
        ];
    }
}
