<?php
/**
 * VATSWIM smartCARS PIREP Transformer
 *
 * Transforms smartCARS webhook data to VATSWIM format.
 *
 * @package VATSWIM
 * @subpackage smartCARS Integration
 * @version 1.0.0
 */

namespace VatSwim\SmartCars;

/**
 * Transforms smartCARS data to VATSWIM format
 */
class PIREPTransformer
{
    /**
     * Transform pirep.started event data
     */
    public function transformStarted(array $data): array
    {
        $pirep = $data['pirep'] ?? $data;
        $flight = $data['flight'] ?? [];
        $pilot = $data['pilot'] ?? [];

        $result = [
            'callsign' => $this->getCallsign($pirep, $flight),
            'dept_icao' => $pirep['departure_icao'] ?? $flight['departure_icao'] ?? null,
            'dest_icao' => $pirep['arrival_icao'] ?? $flight['arrival_icao'] ?? null,
            'aircraft_type' => $pirep['aircraft_icao'] ?? $flight['aircraft_icao'] ?? null,
            'source' => 'smartcars',
        ];

        // Pilot info
        if (!empty($pilot['vatsim_id'])) {
            $result['cid'] = (int) $pilot['vatsim_id'];
        }

        // Flight plan
        if (!empty($pirep['route'])) {
            $result['fp_route'] = $pirep['route'];
        }

        if (!empty($pirep['cruise_altitude'])) {
            $result['fp_altitude_ft'] = (int) $pirep['cruise_altitude'];
        }

        // Predictions (T1-T4)
        if (!empty($pirep['estimated_departure_time'])) {
            $deptTime = $this->parseTime($pirep['estimated_departure_time']);
            $result['lgtd_utc'] = $deptTime;
            // Assume 15 minutes from gate to runway
            $result['lrtd_utc'] = $this->addMinutes($deptTime, 15);
        }

        if (!empty($pirep['estimated_arrival_time'])) {
            $arrTime = $this->parseTime($pirep['estimated_arrival_time']);
            $result['lrta_utc'] = $arrTime;
            // Assume 10 minutes from runway to gate
            $result['lgta_utc'] = $this->addMinutes($arrTime, 10);
        }

        // OUT time (flight started = pushback)
        $result['out_utc'] = gmdate('c');

        return array_filter($result);
    }

    /**
     * Transform pirep.position event data
     */
    public function transformPosition(array $data): array
    {
        $position = $data['position'] ?? $data;
        $pirep = $data['pirep'] ?? [];

        return [
            'callsign' => $this->getCallsign($pirep, []),
            'latitude' => (float) ($position['latitude'] ?? 0),
            'longitude' => (float) ($position['longitude'] ?? 0),
            'altitude_ft' => (int) ($position['altitude'] ?? 0),
            'groundspeed_kts' => (int) ($position['groundspeed'] ?? 0),
            'heading_deg' => (int) ($position['heading'] ?? 0),
            'vertical_rate_fpm' => (int) ($position['vertical_speed'] ?? 0),
            'on_ground' => (bool) ($position['on_ground'] ?? false),
            'source' => 'smartcars',
        ];
    }

    /**
     * Transform pirep.completed event data
     */
    public function transformCompleted(array $data): array
    {
        $pirep = $data['pirep'] ?? $data;
        $flight = $data['flight'] ?? [];
        $pilot = $data['pilot'] ?? [];

        $result = [
            'callsign' => $this->getCallsign($pirep, $flight),
            'dept_icao' => $pirep['departure_icao'] ?? $flight['departure_icao'] ?? null,
            'dest_icao' => $pirep['arrival_icao'] ?? $flight['arrival_icao'] ?? null,
            'aircraft_type' => $pirep['aircraft_icao'] ?? $flight['aircraft_icao'] ?? null,
            'source' => 'smartcars',
        ];

        // Pilot info
        if (!empty($pilot['vatsim_id'])) {
            $result['cid'] = (int) $pilot['vatsim_id'];
        }

        // Actual OOOI times (T11-T14)
        if (!empty($pirep['block_off_time'])) {
            $result['out_utc'] = $this->parseTime($pirep['block_off_time']);
        }

        if (!empty($pirep['takeoff_time'])) {
            $result['off_utc'] = $this->parseTime($pirep['takeoff_time']);
        }

        if (!empty($pirep['landing_time'])) {
            $result['on_utc'] = $this->parseTime($pirep['landing_time']);
        }

        if (!empty($pirep['block_on_time'])) {
            $result['in_utc'] = $this->parseTime($pirep['block_on_time']);
        }

        // If only start/end times available, derive OOOI
        if (empty($result['off_utc']) && !empty($pirep['departure_time'])) {
            $result['off_utc'] = $this->parseTime($pirep['departure_time']);
        }

        if (empty($result['on_utc']) && !empty($pirep['arrival_time'])) {
            $result['on_utc'] = $this->parseTime($pirep['arrival_time']);
        }

        // Flight statistics
        if (!empty($pirep['flight_time'])) {
            $result['block_time_minutes'] = (int) $pirep['flight_time'];
        }

        if (!empty($pirep['fuel_used'])) {
            $result['fuel_used_lbs'] = (float) $pirep['fuel_used'];
        }

        if (!empty($pirep['distance'])) {
            $result['distance_nm'] = (float) $pirep['distance'];
        }

        // IN time (flight completed)
        if (empty($result['in_utc'])) {
            $result['in_utc'] = gmdate('c');
        }

        return array_filter($result);
    }

    /**
     * Transform flight.booked event data
     */
    public function transformBooked(array $data): array
    {
        $flight = $data['flight'] ?? $data;
        $pilot = $data['pilot'] ?? [];

        $result = [
            'callsign' => $flight['callsign'] ?? ($flight['airline_icao'] ?? 'AAA') . ($flight['flight_number'] ?? ''),
            'dept_icao' => $flight['departure_icao'] ?? null,
            'dest_icao' => $flight['arrival_icao'] ?? null,
            'aircraft_type' => $flight['aircraft_icao'] ?? null,
            'source' => 'smartcars',
        ];

        // Schedule times (if available)
        if (!empty($flight['scheduled_departure_time'])) {
            $result['std_utc'] = $this->parseTime($flight['scheduled_departure_time']);
        }

        if (!empty($flight['scheduled_arrival_time'])) {
            $result['sta_utc'] = $this->parseTime($flight['scheduled_arrival_time']);
        }

        // Pilot info
        if (!empty($pilot['vatsim_id'])) {
            $result['cid'] = (int) $pilot['vatsim_id'];
        }

        return array_filter($result);
    }

    /**
     * Get callsign from PIREP or flight data
     */
    private function getCallsign(array $pirep, array $flight): string
    {
        if (!empty($pirep['callsign'])) {
            return $pirep['callsign'];
        }

        if (!empty($flight['callsign'])) {
            return $flight['callsign'];
        }

        $airline = $pirep['airline_icao'] ?? $flight['airline_icao'] ?? 'AAA';
        $number = $pirep['flight_number'] ?? $flight['flight_number'] ?? '001';

        return $airline . $number;
    }

    /**
     * Parse time to ISO8601 format
     */
    private function parseTime(string $time): string
    {
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return gmdate('c');
        }
        return gmdate('c', $timestamp);
    }

    /**
     * Add minutes to ISO8601 time
     */
    private function addMinutes(string $time, int $minutes): string
    {
        $timestamp = strtotime($time);
        return gmdate('c', $timestamp + ($minutes * 60));
    }
}
