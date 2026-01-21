<?php
/**
 * VATSWIM VAM Flight Sync
 *
 * Syncs active flights and PIREPs from VAM to VATSWIM.
 *
 * @package VATSWIM
 * @subpackage VAM Integration
 * @version 1.0.0
 */

namespace VatSwim\VAM;

/**
 * Flight synchronization service
 */
class FlightSync
{
    private VAMClient $vamClient;
    private SWIMClient $swimClient;
    private bool $verbose;

    /** @var array Track synced flights to avoid duplicates */
    private array $syncedFlights = [];

    public function __construct(VAMClient $vamClient, SWIMClient $swimClient)
    {
        $this->vamClient = $vamClient;
        $this->swimClient = $swimClient;
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
     * Sync all active flights
     *
     * @return array Sync results
     */
    public function syncActiveFlights(): array
    {
        $results = [
            'processed' => 0,
            'synced' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        $flights = $this->vamClient->getActiveFlights();

        if ($this->verbose) {
            error_log("[VATSWIM-VAM] Found " . count($flights) . " active flights");
        }

        foreach ($flights as $flight) {
            $results['processed']++;

            try {
                $flightData = $this->transformFlight($flight);

                // Skip if we've already synced this flight recently
                $flightKey = $flightData['callsign'] . '_' . $flightData['dept_icao'] . '_' . $flightData['dest_icao'];
                if (isset($this->syncedFlights[$flightKey])) {
                    $results['skipped']++;
                    continue;
                }

                if ($this->swimClient->submitFlight($flightData)) {
                    $results['synced']++;
                    $this->syncedFlights[$flightKey] = time();
                } else {
                    $results['errors']++;
                }
            } catch (\Exception $e) {
                error_log("[VATSWIM-VAM] Error syncing flight: " . $e->getMessage());
                $results['errors']++;
            }
        }

        // Clean up old synced flight entries (older than 1 hour)
        $this->cleanupSyncedFlights();

        return $results;
    }

    /**
     * Sync recent completed PIREPs
     *
     * @param int $hours Hours to look back
     * @return array Sync results
     */
    public function syncRecentPireps(int $hours = 24): array
    {
        $results = [
            'processed' => 0,
            'synced' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        $pireps = $this->vamClient->getRecentPireps($hours);

        if ($this->verbose) {
            error_log("[VATSWIM-VAM] Found " . count($pireps) . " recent PIREPs");
        }

        foreach ($pireps as $pirep) {
            $results['processed']++;

            try {
                $flightData = $this->transformPirep($pirep);

                if ($this->swimClient->submitFlight($flightData)) {
                    $results['synced']++;
                } else {
                    $results['errors']++;
                }
            } catch (\Exception $e) {
                error_log("[VATSWIM-VAM] Error syncing PIREP: " . $e->getMessage());
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Transform VAM flight to VATSWIM format
     */
    private function transformFlight(array $flight): array
    {
        $data = [
            'callsign' => $this->getCallsign($flight),
            'dept_icao' => $flight['departure_icao'] ?? $flight['dep_icao'] ?? null,
            'dest_icao' => $flight['arrival_icao'] ?? $flight['arr_icao'] ?? null,
            'aircraft_type' => $flight['aircraft_icao'] ?? $flight['aircraft'] ?? null,
            'source' => 'vam',
        ];

        // Pilot VATSIM ID
        if (!empty($flight['pilot_vatsim_id']) || !empty($flight['vatsim_id'])) {
            $data['cid'] = (int) ($flight['pilot_vatsim_id'] ?? $flight['vatsim_id']);
        }

        // Aircraft registration
        if (!empty($flight['registration'])) {
            $data['registration'] = $flight['registration'];
        }

        // Flight plan
        if (!empty($flight['route'])) {
            $data['fp_route'] = $flight['route'];
        }

        if (!empty($flight['cruise_altitude'])) {
            $data['fp_altitude_ft'] = (int) $flight['cruise_altitude'];
        }

        // Schedule times
        if (!empty($flight['scheduled_departure'])) {
            $data['std_utc'] = $this->formatTime($flight['scheduled_departure']);
        }

        if (!empty($flight['scheduled_arrival'])) {
            $data['sta_utc'] = $this->formatTime($flight['scheduled_arrival']);
        }

        // Estimated times (predictions)
        if (!empty($flight['estimated_departure'])) {
            $deptTime = $this->formatTime($flight['estimated_departure']);
            $data['lgtd_utc'] = $deptTime;
            $data['lrtd_utc'] = $this->addMinutes($deptTime, 15);
        }

        if (!empty($flight['estimated_arrival'])) {
            $arrTime = $this->formatTime($flight['estimated_arrival']);
            $data['lrta_utc'] = $arrTime;
            $data['lgta_utc'] = $this->addMinutes($arrTime, 10);
        }

        // Current position if available
        if (!empty($flight['current_latitude']) && !empty($flight['current_longitude'])) {
            $data['lat'] = (float) $flight['current_latitude'];
            $data['lon'] = (float) $flight['current_longitude'];
            $data['altitude_ft'] = (int) ($flight['current_altitude'] ?? 0);
            $data['groundspeed_kts'] = (int) ($flight['current_groundspeed'] ?? 0);
            $data['heading_deg'] = (int) ($flight['current_heading'] ?? 0);
        }

        // OOOI times if available
        if (!empty($flight['block_off_time'])) {
            $data['out_utc'] = $this->formatTime($flight['block_off_time']);
        }

        if (!empty($flight['takeoff_time'])) {
            $data['off_utc'] = $this->formatTime($flight['takeoff_time']);
        }

        return array_filter($data);
    }

    /**
     * Transform VAM PIREP to VATSWIM format (with actuals)
     */
    private function transformPirep(array $pirep): array
    {
        $data = $this->transformFlight($pirep);

        // OOOI actuals
        if (!empty($pirep['block_off_time']) || !empty($pirep['departure_time'])) {
            $data['out_utc'] = $this->formatTime($pirep['block_off_time'] ?? $pirep['departure_time']);
        }

        if (!empty($pirep['takeoff_time'])) {
            $data['off_utc'] = $this->formatTime($pirep['takeoff_time']);
        }

        if (!empty($pirep['landing_time'])) {
            $data['on_utc'] = $this->formatTime($pirep['landing_time']);
        }

        if (!empty($pirep['block_on_time']) || !empty($pirep['arrival_time'])) {
            $data['in_utc'] = $this->formatTime($pirep['block_on_time'] ?? $pirep['arrival_time']);
        }

        // Flight statistics
        if (!empty($pirep['flight_time'])) {
            $data['block_time_minutes'] = (int) $pirep['flight_time'];
        }

        if (!empty($pirep['fuel_used'])) {
            $data['fuel_used_lbs'] = (float) $pirep['fuel_used'];
        }

        if (!empty($pirep['distance'])) {
            $data['distance_nm'] = (float) $pirep['distance'];
        }

        return array_filter($data);
    }

    /**
     * Get callsign from flight data
     */
    private function getCallsign(array $flight): string
    {
        if (!empty($flight['callsign'])) {
            return $flight['callsign'];
        }

        $airline = $flight['airline_icao'] ?? $flight['airline'] ?? 'AAA';
        $number = $flight['flight_number'] ?? $flight['flightnum'] ?? '001';

        return $airline . $number;
    }

    /**
     * Format time to ISO8601
     */
    private function formatTime(string $time): string
    {
        $timestamp = strtotime($time);
        return $timestamp ? gmdate('c', $timestamp) : gmdate('c');
    }

    /**
     * Add minutes to ISO8601 time
     */
    private function addMinutes(string $time, int $minutes): string
    {
        $timestamp = strtotime($time);
        return gmdate('c', $timestamp + ($minutes * 60));
    }

    /**
     * Clean up old synced flight entries
     */
    private function cleanupSyncedFlights(): void
    {
        $cutoff = time() - 3600; // 1 hour ago
        foreach ($this->syncedFlights as $key => $timestamp) {
            if ($timestamp < $cutoff) {
                unset($this->syncedFlights[$key]);
            }
        }
    }
}
