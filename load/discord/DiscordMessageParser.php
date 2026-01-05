<?php
/**
 * Discord Message Parser
 *
 * Parses Discord message content to extract structured data
 * including TMIs (Traffic Management Initiatives), advisories,
 * mentions, and other PERTI-relevant information.
 */

class DiscordMessageParser {

    // TMI type constants
    const TMI_GROUND_STOP = 'GS';
    const TMI_GDP = 'GDP';
    const TMI_AFP = 'AFP';
    const TMI_REROUTE = 'REROUTE';
    const TMI_GROUND_DELAY = 'GD';
    const TMI_MIT = 'MIT';
    const TMI_MINIT = 'MINIT';
    const TMI_STOP = 'STOP';

    // Status constants
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_ENDED = 'ENDED';
    const STATUS_CANCELLED = 'CANCELLED';
    const STATUS_MODIFIED = 'MODIFIED';

    /**
     * Parse TMI information from message content
     *
     * Supports multiple TMI formats:
     * - Ground Stop: "GS KJFK - Weather - 1400Z-1600Z"
     * - GDP: "GDP KORD - Volume - Max Delay 90min"
     * - AFP: "AFP ZNY - Volume - 15 MIT"
     * - Reroute: "REROUTE: ZNY deps to KATL via J75 MPASS"
     * - Cancellation: "GS KJFK CANCELLED"
     *
     * @param string $content Message content
     * @return array|null Parsed TMI data or null if not recognized
     */
    public function parseTMI($content) {
        $content = trim($content);

        if (empty($content)) {
            return null;
        }

        // Try each parser in order of specificity
        $parsers = [
            'parseGroundStopEnd',
            'parseGDPEnd',
            'parseGroundStop',
            'parseGDP',
            'parseAFP',
            'parseReroute',
            'parseMIT',
            'parseGenericTMI'
        ];

        foreach ($parsers as $parser) {
            $result = $this->$parser($content);
            if ($result !== null) {
                $result['raw'] = $content;
                $result['parsed_at'] = gmdate('Y-m-d\TH:i:s\Z');
                return $result;
            }
        }

        return null;
    }

    /**
     * Parse Ground Stop initiation
     *
     * Formats:
     * - "GS KJFK"
     * - "GS KJFK - Weather"
     * - "GS KJFK - Weather - 1400Z-1600Z"
     * - "GROUND STOP KJFK"
     *
     * @param string $content
     * @return array|null
     */
    private function parseGroundStop($content) {
        // Pattern: GS {AIRPORT} [- {REASON}] [- {TIME_RANGE}]
        $pattern = '/^GS\s+([A-Z]{3,4})(?:\s*[-:]\s*([^-]+?))?(?:\s*[-:]\s*(\d{4}Z?)\s*[-to]+\s*(\d{4}Z?))?$/i';

        if (preg_match($pattern, $content, $matches)) {
            return [
                'tmi_type' => self::TMI_GROUND_STOP,
                'airport' => strtoupper($matches[1]),
                'reason' => isset($matches[2]) ? trim($matches[2]) : null,
                'start_time' => isset($matches[3]) ? $this->normalizeZuluTime($matches[3]) : null,
                'end_time' => isset($matches[4]) ? $this->normalizeZuluTime($matches[4]) : null,
                'status' => self::STATUS_ACTIVE
            ];
        }

        // Pattern: Ground Stop {AIRPORT}
        if (preg_match('/GROUND\s*STOP\s+([A-Z]{3,4})/i', $content, $matches)) {
            return [
                'tmi_type' => self::TMI_GROUND_STOP,
                'airport' => strtoupper($matches[1]),
                'reason' => 'See message for details',
                'status' => self::STATUS_ACTIVE
            ];
        }

        return null;
    }

    /**
     * Parse Ground Stop cancellation/end
     *
     * Formats:
     * - "GS KJFK CANCELLED"
     * - "GS KJFK ENDED"
     * - "GROUND STOP KJFK LIFTED"
     *
     * @param string $content
     * @return array|null
     */
    private function parseGroundStopEnd($content) {
        $pattern = '/(GS|GROUND\s*STOP)\s+([A-Z]{3,4})\s+(CANCEL|END|LIFT|STOP|TERMINAT|PURGE)/i';

        if (preg_match($pattern, $content, $matches)) {
            return [
                'tmi_type' => self::TMI_GROUND_STOP,
                'airport' => strtoupper($matches[2]),
                'status' => self::STATUS_ENDED,
                'end_action' => strtoupper($matches[3])
            ];
        }

        return null;
    }

    /**
     * Parse GDP (Ground Delay Program)
     *
     * Formats:
     * - "GDP KORD"
     * - "GDP KORD - Volume"
     * - "GDP KORD - Volume - Max Delay 90min"
     * - "GDP KORD - Weather - ADR 40"
     *
     * @param string $content
     * @return array|null
     */
    private function parseGDP($content) {
        $pattern = '/^GDP\s+([A-Z]{3,4})(?:\s*[-:]\s*([^-]+?))?(?:\s*[-:]\s*(.+))?$/i';

        if (preg_match($pattern, $content, $matches)) {
            $result = [
                'tmi_type' => self::TMI_GDP,
                'airport' => strtoupper($matches[1]),
                'reason' => isset($matches[2]) ? trim($matches[2]) : null,
                'status' => self::STATUS_ACTIVE
            ];

            // Parse additional details (delay, ADR, etc.)
            if (isset($matches[3])) {
                $details = trim($matches[3]);
                $result['details'] = $details;

                // Extract max delay
                if (preg_match('/(\d+)\s*min/i', $details, $delayMatch)) {
                    $result['max_delay_minutes'] = (int)$delayMatch[1];
                }

                // Extract ADR (Airport Departure Rate)
                if (preg_match('/ADR\s*(\d+)/i', $details, $adrMatch)) {
                    $result['adr'] = (int)$adrMatch[1];
                }

                // Extract AAR (Airport Arrival Rate)
                if (preg_match('/AAR\s*(\d+)/i', $details, $aarMatch)) {
                    $result['aar'] = (int)$aarMatch[1];
                }
            }

            return $result;
        }

        return null;
    }

    /**
     * Parse GDP cancellation/end
     *
     * @param string $content
     * @return array|null
     */
    private function parseGDPEnd($content) {
        $pattern = '/GDP\s+([A-Z]{3,4})\s+(CANCEL|END|TERMINAT|PURGE)/i';

        if (preg_match($pattern, $content, $matches)) {
            return [
                'tmi_type' => self::TMI_GDP,
                'airport' => strtoupper($matches[1]),
                'status' => self::STATUS_ENDED,
                'end_action' => strtoupper($matches[2])
            ];
        }

        return null;
    }

    /**
     * Parse AFP (Airspace Flow Program)
     *
     * Formats:
     * - "AFP ZNY"
     * - "AFP ZNY - Volume - 15 MIT"
     *
     * @param string $content
     * @return array|null
     */
    private function parseAFP($content) {
        $pattern = '/^AFP\s+([A-Z]{3})(?:\s*[-:]\s*(.+))?$/i';

        if (preg_match($pattern, $content, $matches)) {
            $result = [
                'tmi_type' => self::TMI_AFP,
                'facility' => strtoupper($matches[1]),
                'status' => self::STATUS_ACTIVE
            ];

            if (isset($matches[2])) {
                $result['details'] = trim($matches[2]);

                // Extract MIT value
                if (preg_match('/(\d+)\s*MIT/i', $matches[2], $mitMatch)) {
                    $result['mit'] = (int)$mitMatch[1];
                }
            }

            return $result;
        }

        return null;
    }

    /**
     * Parse Reroute advisory
     *
     * Formats:
     * - "REROUTE: ZNY deps to KATL via J75 MPASS"
     * - "REROUTES: All ZDC arrivals via BRISS"
     *
     * @param string $content
     * @return array|null
     */
    private function parseReroute($content) {
        $pattern = '/^REROUTES?\s*[:]\s*(.+)$/i';

        if (preg_match($pattern, $content, $matches)) {
            $details = trim($matches[1]);

            $result = [
                'tmi_type' => self::TMI_REROUTE,
                'details' => $details,
                'status' => self::STATUS_ACTIVE
            ];

            // Try to extract origin facility
            if (preg_match('/([A-Z]{3})\s+deps?/i', $details, $originMatch)) {
                $result['origin_facility'] = strtoupper($originMatch[1]);
            }

            // Try to extract destination
            if (preg_match('/to\s+([A-Z]{3,4})/i', $details, $destMatch)) {
                $result['destination'] = strtoupper($destMatch[1]);
            }

            // Try to extract route
            if (preg_match('/via\s+(.+?)(?:\s+\d|$)/i', $details, $routeMatch)) {
                $result['route'] = trim($routeMatch[1]);
            }

            return $result;
        }

        return null;
    }

    /**
     * Parse MIT (Miles-In-Trail)
     *
     * Formats:
     * - "MIT 15 ZNY to ZDC"
     * - "15 MIT ZNY arrivals"
     *
     * @param string $content
     * @return array|null
     */
    private function parseMIT($content) {
        // Pattern: MIT {VALUE} {DETAILS}
        if (preg_match('/^MIT\s+(\d+)\s+(.+)$/i', $content, $matches)) {
            return [
                'tmi_type' => self::TMI_MIT,
                'mit' => (int)$matches[1],
                'details' => trim($matches[2]),
                'status' => self::STATUS_ACTIVE
            ];
        }

        // Pattern: {VALUE} MIT {DETAILS}
        if (preg_match('/^(\d+)\s+MIT\s+(.+)$/i', $content, $matches)) {
            return [
                'tmi_type' => self::TMI_MIT,
                'mit' => (int)$matches[1],
                'details' => trim($matches[2]),
                'status' => self::STATUS_ACTIVE
            ];
        }

        return null;
    }

    /**
     * Parse generic TMI mentions
     *
     * Catches TMI keywords not matched by specific parsers
     *
     * @param string $content
     * @return array|null
     */
    private function parseGenericTMI($content) {
        $upperContent = strtoupper($content);

        // Check for TMI keywords
        $tmiKeywords = [
            'GROUND STOP' => self::TMI_GROUND_STOP,
            'GROUND DELAY' => self::TMI_GDP,
            'AIRSPACE FLOW' => self::TMI_AFP,
            'MILES IN TRAIL' => self::TMI_MIT,
            'MINUTES IN TRAIL' => self::TMI_MINIT
        ];

        foreach ($tmiKeywords as $keyword => $type) {
            if (strpos($upperContent, $keyword) !== false) {
                // Try to extract airport code
                $airport = null;
                if (preg_match('/\b([A-Z]{4})\b/', $content, $airportMatch)) {
                    $airport = $airportMatch[1];
                }

                return [
                    'tmi_type' => $type,
                    'airport' => $airport,
                    'details' => $content,
                    'status' => self::STATUS_ACTIVE,
                    'parse_confidence' => 'LOW'
                ];
            }
        }

        return null;
    }

    /**
     * Parse advisory message
     *
     * @param string $content Message content
     * @return array|null Parsed advisory data
     */
    public function parseAdvisory($content) {
        $content = trim($content);

        if (empty($content)) {
            return null;
        }

        // Check for advisory keywords
        $advisoryKeywords = ['ADVISORY', 'NOTAM', 'ATTENTION', 'NOTICE', 'ALERT'];

        $upperContent = strtoupper($content);
        foreach ($advisoryKeywords as $keyword) {
            if (strpos($upperContent, $keyword) !== false) {
                return [
                    'type' => 'ADVISORY',
                    'keyword' => $keyword,
                    'content' => $content,
                    'parsed_at' => gmdate('Y-m-d\TH:i:s\Z')
                ];
            }
        }

        return null;
    }

    /**
     * Extract user mentions from message content
     *
     * Discord format: <@USER_ID> or <@!USER_ID>
     *
     * @param string $content Message content
     * @return array Array of user IDs
     */
    public function extractMentions($content) {
        $mentions = [];

        if (preg_match_all('/<@!?(\d+)>/', $content, $matches)) {
            $mentions = array_unique($matches[1]);
        }

        return $mentions;
    }

    /**
     * Extract role mentions from message content
     *
     * Discord format: <@&ROLE_ID>
     *
     * @param string $content Message content
     * @return array Array of role IDs
     */
    public function extractRoleMentions($content) {
        $roles = [];

        if (preg_match_all('/<@&(\d+)>/', $content, $matches)) {
            $roles = array_unique($matches[1]);
        }

        return $roles;
    }

    /**
     * Extract channel mentions from message content
     *
     * Discord format: <#CHANNEL_ID>
     *
     * @param string $content Message content
     * @return array Array of channel IDs
     */
    public function extractChannelMentions($content) {
        $channels = [];

        if (preg_match_all('/<#(\d+)>/', $content, $matches)) {
            $channels = array_unique($matches[1]);
        }

        return $channels;
    }

    /**
     * Extract URLs from message content
     *
     * @param string $content Message content
     * @return array Array of URLs
     */
    public function extractUrls($content) {
        $urls = [];

        $pattern = '/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i';
        if (preg_match_all($pattern, $content, $matches)) {
            $urls = array_unique($matches[0]);
        }

        return $urls;
    }

    /**
     * Extract airport codes from message content
     *
     * @param string $content Message content
     * @return array Array of airport codes (4-letter ICAO codes)
     */
    public function extractAirportCodes($content) {
        $airports = [];

        // Match 4-letter codes that look like ICAO codes
        if (preg_match_all('/\b([A-Z]{4})\b/', strtoupper($content), $matches)) {
            // Filter to likely airport codes (K prefix for US, C for Canada, etc.)
            foreach ($matches[1] as $code) {
                if (preg_match('/^[KC][A-Z]{3}$/', $code) || // US/Canada
                    preg_match('/^[EPLS][A-Z]{3}$/', $code) || // Europe
                    preg_match('/^[A-Z]{4}$/', $code)) { // Generic
                    $airports[] = $code;
                }
            }
            $airports = array_unique($airports);
        }

        return $airports;
    }

    /**
     * Extract facility codes from message content
     *
     * @param string $content Message content
     * @return array Array of facility codes (3-letter ARTCC codes)
     */
    public function extractFacilityCodes($content) {
        $facilities = [];

        // Common ARTCC prefixes
        $artccPattern = '/\b(Z[A-Z]{2})\b/';
        if (preg_match_all($artccPattern, strtoupper($content), $matches)) {
            $facilities = array_unique($matches[1]);
        }

        return $facilities;
    }

    /**
     * Strip Discord formatting from content
     *
     * Removes mentions, emojis, and markdown
     *
     * @param string $content Message content
     * @return string Plain text content
     */
    public function stripFormatting($content) {
        // Remove user mentions
        $content = preg_replace('/<@!?\d+>/', '', $content);

        // Remove role mentions
        $content = preg_replace('/<@&\d+>/', '', $content);

        // Remove channel mentions
        $content = preg_replace('/<#\d+>/', '', $content);

        // Remove custom emojis
        $content = preg_replace('/<a?:\w+:\d+>/', '', $content);

        // Remove timestamp formatting
        $content = preg_replace('/<t:\d+(?::[tTdDfFR])?>/', '', $content);

        // Remove basic markdown
        $content = preg_replace('/[*_~`|]/', '', $content);

        // Clean up whitespace
        $content = preg_replace('/\s+/', ' ', $content);

        return trim($content);
    }

    /**
     * Normalize Zulu time format
     *
     * @param string $time Time string (e.g., "1400", "1400Z")
     * @return string Normalized format (e.g., "1400Z")
     */
    private function normalizeZuluTime($time) {
        $time = strtoupper(trim($time));

        // Remove trailing Z if present
        $time = rtrim($time, 'Z');

        // Ensure 4 digits
        if (strlen($time) < 4) {
            $time = str_pad($time, 4, '0', STR_PAD_LEFT);
        }

        return $time . 'Z';
    }
}
