<?php
/**
 * VATSWIM vATIS Monitor
 *
 * Polls vATIS data to extract active ATIS information.
 *
 * @package VATSWIM
 * @subpackage vATIS Integration
 * @version 1.0.0
 */

namespace VatSwim\VATIS;

/**
 * ATIS Monitor - Polls and caches vATIS data
 */
class ATISMonitor
{
    private const VATSIM_DATA_URL = 'https://data.vatsim.net/v3/vatsim-data.json';
    private const CACHE_TTL = 60; // 1 minute

    private array $atisCache = [];
    private int $lastFetch = 0;
    private bool $verbose = false;

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * Get all active ATIS broadcasts
     *
     * @return array Array of ATIS data keyed by airport ICAO
     */
    public function getActiveATIS(): array
    {
        $this->refreshCache();
        return $this->atisCache;
    }

    /**
     * Get ATIS for a specific airport
     *
     * @param string $icao Airport ICAO code
     * @return array|null ATIS data or null if not found
     */
    public function getATISForAirport(string $icao): ?array
    {
        $this->refreshCache();
        $icao = strtoupper($icao);

        return $this->atisCache[$icao] ?? null;
    }

    /**
     * Get airports with active ATIS
     *
     * @return array List of airport ICAO codes
     */
    public function getActiveAirports(): array
    {
        $this->refreshCache();
        return array_keys($this->atisCache);
    }

    /**
     * Refresh ATIS cache if stale
     */
    private function refreshCache(): void
    {
        if (time() - $this->lastFetch < self::CACHE_TTL) {
            return;
        }

        $data = $this->fetchVatsimData();
        if ($data === null) {
            return;
        }

        $this->parseATISData($data);
        $this->lastFetch = time();
    }

    /**
     * Fetch VATSIM data feed
     */
    private function fetchVatsimData(): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::VATSIM_DATA_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'VATSWIM-vATIS/1.0.0',
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            if ($this->verbose) {
                error_log("[VATSWIM-vATIS] cURL error: $error");
            }
            return null;
        }

        if ($httpCode !== 200) {
            if ($this->verbose) {
                error_log("[VATSWIM-vATIS] HTTP error: $httpCode");
            }
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->verbose) {
                error_log("[VATSWIM-vATIS] JSON parse error: " . json_last_error_msg());
            }
            return null;
        }

        return $data;
    }

    /**
     * Parse ATIS data from VATSIM feed
     */
    private function parseATISData(array $data): void
    {
        $this->atisCache = [];

        // ATIS are in the 'atis' array in VATSIM data
        $atisList = $data['atis'] ?? [];

        foreach ($atisList as $atis) {
            $callsign = $atis['callsign'] ?? '';
            $textAtis = $atis['text_atis'] ?? [];
            $frequency = $atis['frequency'] ?? '';
            $logonTime = $atis['logon_time'] ?? '';

            // Extract airport ICAO from callsign (e.g., KJFK_ATIS -> KJFK)
            $icao = $this->extractAirportFromCallsign($callsign);
            if (!$icao) {
                continue;
            }

            // Parse the ATIS text
            $atisText = is_array($textAtis) ? implode(' ', $textAtis) : $textAtis;
            $parsed = $this->parseATISText($atisText);

            $this->atisCache[$icao] = [
                'icao' => $icao,
                'callsign' => $callsign,
                'frequency' => $frequency,
                'logon_time' => $logonTime,
                'raw_text' => $atisText,
                'letter' => $parsed['letter'],
                'time_utc' => $parsed['time_utc'],
                'wind' => $parsed['wind'],
                'visibility' => $parsed['visibility'],
                'ceiling' => $parsed['ceiling'],
                'temperature' => $parsed['temperature'],
                'dewpoint' => $parsed['dewpoint'],
                'altimeter' => $parsed['altimeter'],
                'runways_departure' => $parsed['runways_departure'],
                'runways_arrival' => $parsed['runways_arrival'],
                'approaches_in_use' => $parsed['approaches_in_use'],
                'notams' => $parsed['notams'],
                'fetched_at' => gmdate('c')
            ];
        }
    }

    /**
     * Extract airport ICAO from ATIS callsign
     */
    private function extractAirportFromCallsign(string $callsign): ?string
    {
        // Common patterns: KJFK_ATIS, KJFK_D_ATIS, EGLL_ATIS
        if (preg_match('/^([A-Z]{4})(?:_[A-Z])?_ATIS$/i', $callsign, $match)) {
            return strtoupper($match[1]);
        }

        // Also handle XXXX_INFO pattern
        if (preg_match('/^([A-Z]{4})_INFO$/i', $callsign, $match)) {
            return strtoupper($match[1]);
        }

        return null;
    }

    /**
     * Parse ATIS text to extract structured data
     */
    private function parseATISText(string $text): array
    {
        $result = [
            'letter' => null,
            'time_utc' => null,
            'wind' => null,
            'visibility' => null,
            'ceiling' => null,
            'temperature' => null,
            'dewpoint' => null,
            'altimeter' => null,
            'runways_departure' => [],
            'runways_arrival' => [],
            'approaches_in_use' => [],
            'notams' => []
        ];

        $text = strtoupper($text);

        // ATIS letter (INFO ALPHA, INFORMATION BRAVO, etc.)
        if (preg_match('/INFO(?:RMATION)?\s+([A-Z])/', $text, $match)) {
            $result['letter'] = $match[1];
        }

        // Time (e.g., "1234Z", "12:34Z")
        if (preg_match('/(\d{4})Z/', $text, $match)) {
            $result['time_utc'] = $match[1];
        }

        // Wind (e.g., "WIND 270 AT 15", "WND 27010KT")
        if (preg_match('/WIND\s+(\d{3})\s+(?:AT\s+)?(\d+)(?:\s*(?:G|GUST(?:ING)?)?\s*(\d+))?/', $text, $match)) {
            $result['wind'] = [
                'direction' => (int) $match[1],
                'speed' => (int) $match[2],
                'gust' => isset($match[3]) ? (int) $match[3] : null
            ];
        } elseif (preg_match('/(\d{3})(\d{2,3})(?:G(\d{2,3}))?KT/', $text, $match)) {
            $result['wind'] = [
                'direction' => (int) $match[1],
                'speed' => (int) $match[2],
                'gust' => isset($match[3]) ? (int) $match[3] : null
            ];
        }

        // Visibility (e.g., "VIS 10", "VISIBILITY 3SM")
        if (preg_match('/VIS(?:IBILITY)?\s+(\d+(?:\/\d+)?)\s*(?:SM)?/', $text, $match)) {
            $result['visibility'] = $match[1];
        }

        // Ceiling (e.g., "CEILING 2500", "BKN025")
        if (preg_match('/CEIL(?:ING)?\s+(\d+)/', $text, $match)) {
            $result['ceiling'] = (int) $match[1];
        } elseif (preg_match('/(?:BKN|OVC)(\d{3})/', $text, $match)) {
            $result['ceiling'] = (int) $match[1] * 100;
        }

        // Temperature (e.g., "TEMP 22", "TEMPERATURE 15")
        if (preg_match('/TEMP(?:ERATURE)?\s+(-?\d+)/', $text, $match)) {
            $result['temperature'] = (int) $match[1];
        }

        // Dewpoint (e.g., "DEW POINT 18", "DP 12")
        if (preg_match('/(?:DEW\s*POINT|DP)\s+(-?\d+)/', $text, $match)) {
            $result['dewpoint'] = (int) $match[1];
        }

        // Altimeter (e.g., "ALTIMETER 2992", "ALT 30.12", "QNH 1013")
        if (preg_match('/ALT(?:IMETER)?\s+(\d{4}|\d{2}\.\d{2})/', $text, $match)) {
            $result['altimeter'] = $match[1];
        } elseif (preg_match('/QNH\s+(\d{4})/', $text, $match)) {
            $result['altimeter'] = $match[1];
        }

        // Departure runways (e.g., "DEPARTING RWY 4L AND 4R", "DEP RWYS 28L 28R")
        if (preg_match('/DEP(?:ART(?:ING|URE)?)?(?:\s+RWYS?)?[\s:]+([0-9LRC,\s]+(?:AND\s+)?[0-9LRC]*)/i', $text, $match)) {
            $runways = preg_split('/[\s,]+(?:AND\s+)?/', trim($match[1]));
            $result['runways_departure'] = array_filter($runways, fn($r) => preg_match('/^\d{1,2}[LRC]?$/', $r));
        }

        // Arrival runways (e.g., "LANDING RWY 22L", "ARR RWYS 27L 27R")
        if (preg_match('/(?:LAND(?:ING)?|ARR(?:IV(?:AL|ING))?|EXPECT(?:ING)?)(?:\s+RWYS?)?[\s:]+([0-9LRC,\s]+(?:AND\s+)?[0-9LRC]*)/i', $text, $match)) {
            $runways = preg_split('/[\s,]+(?:AND\s+)?/', trim($match[1]));
            $result['runways_arrival'] = array_filter($runways, fn($r) => preg_match('/^\d{1,2}[LRC]?$/', $r));
        }

        // Approaches in use (e.g., "ILS RWY 4R APCH IN USE", "EXPECT VECTORS ILS 28L")
        if (preg_match_all('/(ILS|RNAV|VOR|NDB|VISUAL|GPS)\s+(?:RWY\s+)?(\d{1,2}[LRC]?)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $result['approaches_in_use'][] = [
                    'type' => $match[1],
                    'runway' => $match[2]
                ];
            }
        }

        return $result;
    }

    /**
     * Force refresh of cache
     */
    public function forceRefresh(): void
    {
        $this->lastFetch = 0;
        $this->refreshCache();
    }
}
