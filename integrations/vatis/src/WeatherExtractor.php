<?php
/**
 * VATSWIM Weather Extractor
 *
 * Extracts and normalizes weather data from ATIS.
 *
 * @package VATSWIM
 * @subpackage vATIS Integration
 * @version 1.0.0
 */

namespace VatSwim\VATIS;

/**
 * Weather Extractor - Normalizes ATIS weather for SWIM
 */
class WeatherExtractor
{
    private ATISMonitor $atisMonitor;

    public function __construct(ATISMonitor $atisMonitor)
    {
        $this->atisMonitor = $atisMonitor;
    }

    /**
     * Get normalized weather for an airport
     *
     * @param string $icao Airport ICAO
     * @return array|null Weather data or null
     */
    public function getWeather(string $icao): ?array
    {
        $atis = $this->atisMonitor->getATISForAirport($icao);
        if (!$atis) {
            return null;
        }

        return [
            'icao' => $icao,
            'atis_letter' => $atis['letter'],
            'time_utc' => $atis['time_utc'],
            'wind' => $this->normalizeWind($atis['wind']),
            'visibility' => $this->normalizeVisibility($atis['visibility']),
            'ceiling' => $this->normalizeCeiling($atis['ceiling']),
            'temperature' => $atis['temperature'],
            'dewpoint' => $atis['dewpoint'],
            'altimeter' => $this->normalizeAltimeter($atis['altimeter']),
            'flight_category' => $this->determineFlightCategory(
                $atis['visibility'],
                $atis['ceiling']
            ),
            'fetched_at' => $atis['fetched_at']
        ];
    }

    /**
     * Get weather affecting a flight (departure and arrival)
     *
     * @param array $flight Flight data
     * @return array Weather for both airports
     */
    public function getFlightWeather(array $flight): array
    {
        $result = [
            'departure' => null,
            'arrival' => null
        ];

        $deptIcao = $flight['dept_icao'] ?? $flight['departure_icao'] ?? null;
        if ($deptIcao) {
            $result['departure'] = $this->getWeather($deptIcao);
        }

        $destIcao = $flight['dest_icao'] ?? $flight['destination_icao'] ?? null;
        if ($destIcao) {
            $result['arrival'] = $this->getWeather($destIcao);
        }

        return $result;
    }

    /**
     * Calculate headwind/tailwind component for a runway
     *
     * @param string $icao Airport ICAO
     * @param string $runway Runway number (e.g., "04L")
     * @return array|null Wind components or null
     */
    public function getRunwayWindComponent(string $icao, string $runway): ?array
    {
        $weather = $this->getWeather($icao);
        if (!$weather || !$weather['wind']) {
            return null;
        }

        // Extract runway heading from runway number
        $runwayMatch = [];
        if (!preg_match('/^(\d{1,2})/', $runway, $runwayMatch)) {
            return null;
        }

        $runwayHeading = (int) $runwayMatch[1] * 10;
        $windDir = $weather['wind']['direction'];
        $windSpeed = $weather['wind']['speed'];
        $windGust = $weather['wind']['gust'];

        // Calculate wind angle relative to runway
        $angleDiff = $windDir - $runwayHeading;

        // Normalize to -180 to 180
        while ($angleDiff > 180) $angleDiff -= 360;
        while ($angleDiff < -180) $angleDiff += 360;

        $angleRad = deg2rad($angleDiff);

        // Headwind is positive, tailwind is negative
        $headwind = $windSpeed * cos($angleRad);
        $crosswind = $windSpeed * sin($angleRad);

        $result = [
            'runway' => $runway,
            'runway_heading' => $runwayHeading,
            'wind_direction' => $windDir,
            'wind_speed' => $windSpeed,
            'headwind' => round($headwind, 1),
            'crosswind' => round(abs($crosswind), 1),
            'crosswind_direction' => $crosswind > 0 ? 'right' : 'left',
            'is_tailwind' => $headwind < 0
        ];

        // Calculate with gusts if present
        if ($windGust) {
            $gustHeadwind = $windGust * cos($angleRad);
            $gustCrosswind = $windGust * sin($angleRad);
            $result['gust_headwind'] = round($gustHeadwind, 1);
            $result['gust_crosswind'] = round(abs($gustCrosswind), 1);
        }

        return $result;
    }

    /**
     * Check if a runway has acceptable crosswind
     *
     * @param string $icao Airport ICAO
     * @param string $runway Runway number
     * @param int $maxCrosswind Maximum acceptable crosswind (knots)
     * @return bool True if acceptable
     */
    public function isRunwayCrosswindAcceptable(string $icao, string $runway, int $maxCrosswind = 20): bool
    {
        $component = $this->getRunwayWindComponent($icao, $runway);
        if (!$component) {
            return true; // Assume acceptable if unknown
        }

        $crosswind = $component['crosswind'];
        $gustCrosswind = $component['gust_crosswind'] ?? $crosswind;

        return max($crosswind, $gustCrosswind) <= $maxCrosswind;
    }

    /**
     * Normalize wind data
     */
    private function normalizeWind(?array $wind): ?array
    {
        if (!$wind) {
            return null;
        }

        return [
            'direction' => $wind['direction'],
            'direction_cardinal' => $this->degreesToCardinal($wind['direction']),
            'speed' => $wind['speed'],
            'gust' => $wind['gust'],
            'variable' => $wind['direction'] === 0 && $wind['speed'] < 3
        ];
    }

    /**
     * Convert wind direction to cardinal
     */
    private function degreesToCardinal(int $degrees): string
    {
        $directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
                       'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

        $index = (int) round($degrees / 22.5) % 16;
        return $directions[$index];
    }

    /**
     * Normalize visibility
     */
    private function normalizeVisibility(?string $visibility): ?array
    {
        if ($visibility === null) {
            return null;
        }

        // Handle fractional (e.g., "1/2", "1/4")
        if (str_contains($visibility, '/')) {
            $parts = explode('/', $visibility);
            $sm = (float) $parts[0] / (float) $parts[1];
        } else {
            $sm = (float) $visibility;
        }

        // 10+ usually means 10 or greater
        if ($sm >= 10) {
            $sm = 10;
        }

        return [
            'raw' => $visibility,
            'statute_miles' => $sm,
            'meters' => round($sm * 1609.34)
        ];
    }

    /**
     * Normalize ceiling
     */
    private function normalizeCeiling(?int $ceiling): ?array
    {
        if ($ceiling === null) {
            return null;
        }

        return [
            'feet' => $ceiling,
            'meters' => round($ceiling * 0.3048)
        ];
    }

    /**
     * Normalize altimeter setting
     */
    private function normalizeAltimeter(?string $altimeter): ?array
    {
        if ($altimeter === null) {
            return null;
        }

        $inhg = null;
        $hpa = null;

        // Determine format
        if (strlen($altimeter) === 4 && !str_contains($altimeter, '.')) {
            // Could be inches (2992) or hectopascals (1013)
            $value = (int) $altimeter;
            if ($value >= 2800 && $value <= 3100) {
                // Inches format (2992 = 29.92)
                $inhg = $value / 100;
                $hpa = round($inhg * 33.8639);
            } elseif ($value >= 950 && $value <= 1050) {
                // Hectopascals
                $hpa = $value;
                $inhg = round($value / 33.8639, 2);
            }
        } elseif (str_contains($altimeter, '.')) {
            // Decimal inches (29.92)
            $inhg = (float) $altimeter;
            $hpa = round($inhg * 33.8639);
        }

        return [
            'raw' => $altimeter,
            'inhg' => $inhg,
            'hpa' => $hpa
        ];
    }

    /**
     * Determine flight category
     *
     * @param mixed $visibility Visibility value
     * @param int|null $ceiling Ceiling in feet
     * @return string VFR, MVFR, IFR, or LIFR
     */
    private function determineFlightCategory($visibility, ?int $ceiling): string
    {
        // Parse visibility to statute miles
        $visSM = null;
        if ($visibility !== null) {
            if (is_string($visibility) && str_contains($visibility, '/')) {
                $parts = explode('/', $visibility);
                $visSM = (float) $parts[0] / (float) $parts[1];
            } else {
                $visSM = (float) $visibility;
            }
        }

        // LIFR: ceiling < 500 or visibility < 1
        if (($ceiling !== null && $ceiling < 500) ||
            ($visSM !== null && $visSM < 1)) {
            return 'LIFR';
        }

        // IFR: ceiling < 1000 or visibility < 3
        if (($ceiling !== null && $ceiling < 1000) ||
            ($visSM !== null && $visSM < 3)) {
            return 'IFR';
        }

        // MVFR: ceiling <= 3000 or visibility <= 5
        if (($ceiling !== null && $ceiling <= 3000) ||
            ($visSM !== null && $visSM <= 5)) {
            return 'MVFR';
        }

        // VFR: ceiling > 3000 and visibility > 5
        return 'VFR';
    }

    /**
     * Get density altitude for an airport
     *
     * @param string $icao Airport ICAO
     * @param int $fieldElevation Airport field elevation in feet
     * @return int|null Density altitude in feet
     */
    public function getDensityAltitude(string $icao, int $fieldElevation): ?int
    {
        $weather = $this->getWeather($icao);
        if (!$weather) {
            return null;
        }

        $altimeter = $weather['altimeter']['inhg'] ?? null;
        $temp = $weather['temperature'];

        if ($altimeter === null || $temp === null) {
            return null;
        }

        // Pressure altitude = field elevation + (1000 * (29.92 - altimeter))
        $pressureAlt = $fieldElevation + (1000 * (29.92 - $altimeter));

        // Standard temp at field elevation (15°C at sea level, -2°C per 1000ft)
        $standardTemp = 15 - (2 * $fieldElevation / 1000);

        // Density altitude = pressure altitude + (120 * (actual temp - standard temp))
        $densityAlt = $pressureAlt + (120 * ($temp - $standardTemp));

        return (int) round($densityAlt);
    }
}
