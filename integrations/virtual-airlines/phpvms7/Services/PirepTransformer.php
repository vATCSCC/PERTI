<?php

namespace Modules\Vatswim\Services;

use App\Models\Pirep;
use Illuminate\Support\Carbon;

/**
 * Transforms phpVMS PIREP data to VATSWIM format
 */
class PirepTransformer
{
    /**
     * Transform a PIREP to VATSWIM flight data format
     *
     * @param Pirep $pirep The PIREP to transform
     * @param bool $includeActuals Include actual OOOI times
     * @return array VATSWIM-formatted flight data
     */
    public function transform(Pirep $pirep, bool $includeActuals = false): array
    {
        $data = [
            'callsign' => $this->getCallsign($pirep),
            'dept_icao' => $pirep->dpt_airport_id,
            'dest_icao' => $pirep->arr_airport_id,
            'aircraft_type' => $pirep->aircraft?->icao ?? null,
            'source' => 'phpvms',
        ];

        // Include pilot CID if configured and available
        if (config('vatswim.include_pilot_cid') && $pirep->user?->vatsim_id) {
            $data['cid'] = (int) $pirep->user->vatsim_id;
        }

        // Include aircraft registration
        if (config('vatswim.include_registration') && $pirep->aircraft?->registration) {
            $data['registration'] = $pirep->aircraft->registration;
        }

        // Flight plan data
        if ($pirep->route) {
            $data['fp_route'] = $pirep->route;
        }

        if ($pirep->level) {
            $data['fp_altitude_ft'] = $pirep->level * 100; // FL to feet
        }

        // Airline identifier
        if (config('vatswim.airline_icao')) {
            $data['operator_icao'] = config('vatswim.airline_icao');
        }

        // Schedule times from flight if available
        if (config('vatswim.submit_schedule') && $pirep->flight) {
            $schedule = $this->getScheduleTimes($pirep);
            if ($schedule) {
                $data = array_merge($data, $schedule);
            }
        }

        // CDM predictions based on PIREP estimates
        if (config('vatswim.submit_predictions')) {
            $predictions = $this->getPredictionTimes($pirep);
            if ($predictions) {
                $data = array_merge($data, $predictions);
            }
        }

        // Actual OOOI times (from ACARS or PIREP)
        if ($includeActuals && config('vatswim.submit_actuals')) {
            $actuals = $this->getActualTimes($pirep);
            if ($actuals) {
                $data = array_merge($data, $actuals);
            }
        }

        // Flight statistics if available
        if ($pirep->flight_time) {
            $data['block_time_minutes'] = $pirep->flight_time;
        }

        if ($pirep->fuel_used) {
            $data['fuel_used_lbs'] = $pirep->fuel_used->local(); // Convert to lbs
        }

        if ($pirep->distance) {
            $data['distance_nm'] = $pirep->distance->toUnit('nm');
        }

        return $data;
    }

    /**
     * Get callsign for the PIREP
     */
    protected function getCallsign(Pirep $pirep): string
    {
        // Use PIREP's callsign if set
        if ($pirep->callsign) {
            return $pirep->callsign;
        }

        // Build from airline code + flight number
        $airline = $pirep->airline?->icao ?? config('vatswim.airline_icao', 'AAA');
        $flightNumber = $pirep->flight_number ?? $pirep->id;

        return $airline . $flightNumber;
    }

    /**
     * Get schedule times (STD/STA) from flight schedule
     */
    protected function getScheduleTimes(Pirep $pirep): ?array
    {
        $flight = $pirep->flight;
        if (!$flight) {
            return null;
        }

        $times = [];

        if ($flight->dpt_time) {
            // Combine with PIREP date to get full datetime
            $times['std_utc'] = $this->formatTimeForDate($flight->dpt_time, $pirep->created_at);
        }

        if ($flight->arr_time) {
            $times['sta_utc'] = $this->formatTimeForDate($flight->arr_time, $pirep->created_at);
        }

        return empty($times) ? null : $times;
    }

    /**
     * Get CDM prediction times (T1-T4)
     */
    protected function getPredictionTimes(Pirep $pirep): ?array
    {
        $times = [];

        // If we have planned times, use them as predictions
        if ($pirep->planned_flight_time) {
            $estimatedDeparture = $pirep->created_at;
            $estimatedArrival = $estimatedDeparture->copy()->addMinutes($pirep->planned_flight_time);

            // T3 - Gate Time of Departure (LGTD)
            $times['lgtd_utc'] = $estimatedDeparture->toIso8601String();

            // T1 - Runway Time of Departure (LRTD) - assume 15 min after gate
            $times['lrtd_utc'] = $estimatedDeparture->copy()->addMinutes(15)->toIso8601String();

            // T2 - Runway Time of Arrival (LRTA)
            $times['lrta_utc'] = $estimatedArrival->toIso8601String();

            // T4 - Gate Time of Arrival (LGTA) - assume 10 min after runway
            $times['lgta_utc'] = $estimatedArrival->copy()->addMinutes(10)->toIso8601String();
        }

        return empty($times) ? null : $times;
    }

    /**
     * Get actual OOOI times (T11-T14) from PIREP/ACARS data
     */
    protected function getActualTimes(Pirep $pirep): ?array
    {
        $times = [];

        // phpVMS stores actual times in different ways depending on ACARS
        // Check for block times first (gate times)
        if ($pirep->block_off_time) {
            $times['out_utc'] = Carbon::parse($pirep->block_off_time)->toIso8601String();
        }

        if ($pirep->block_on_time) {
            $times['in_utc'] = Carbon::parse($pirep->block_on_time)->toIso8601String();
        }

        // Check for air times (takeoff/landing)
        if ($pirep->submitted_at && $pirep->flight_time) {
            // Estimate takeoff from submitted time minus flight time
            $landingTime = $pirep->submitted_at;
            $takeoffTime = $landingTime->copy()->subMinutes($pirep->flight_time);

            if (!isset($times['off_utc'])) {
                $times['off_utc'] = $takeoffTime->toIso8601String();
            }

            $times['on_utc'] = $landingTime->toIso8601String();
        }

        return empty($times) ? null : $times;
    }

    /**
     * Format a time string for a specific date
     */
    protected function formatTimeForDate(string $time, Carbon $date): string
    {
        // Parse time (HH:MM or HHMM format)
        $time = str_replace(':', '', $time);
        $hours = (int) substr($time, 0, 2);
        $minutes = (int) substr($time, 2, 2);

        return $date->copy()
            ->setTime($hours, $minutes, 0)
            ->setTimezone('UTC')
            ->toIso8601String();
    }
}
