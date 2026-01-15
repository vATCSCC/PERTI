<?php
/**
 * VATSIM SWIM Configuration
 * 
 * Configuration settings for the System Wide Information Management (SWIM) API.
 * 
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('PERTI_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted');
}

/**
 * SWIM API Configuration
 */
define('SWIM_API_VERSION', '1.0.0');
define('SWIM_API_PREFIX', '/api/swim/v1');

/**
 * Rate Limiting Configuration (requests per minute)
 */
$SWIM_RATE_LIMITS = [
    'system'    => 10000,  // Trusted systems (vNAS, CRC, SimTraffic)
    'partner'   => 1000,   // Integration partners (VAs, etc.)
    'developer' => 100,    // Developer testing
    'public'    => 30      // Public consumers
];

/**
 * API Key Prefixes
 */
$SWIM_KEY_PREFIXES = [
    'system'    => 'swim_sys_',
    'partner'   => 'swim_par_',
    'developer' => 'swim_dev_',
    'public'    => 'swim_pub_'
];

/**
 * Data Source Identifiers
 */
$SWIM_DATA_SOURCES = [
    'VATSIM'         => 'vatsim',           // VATSIM network (flight plans, connections)
    'VATCSCC'        => 'vatcscc',          // vATCSCC/PERTI (ADL, TMI, OOOI, demand)
    'VNAS'           => 'vnas',             // vNAS (track, ATC, clearances)
    'CRC'            => 'crc',              // CRC (track, tags, handoffs)
    'EUROSCOPE'      => 'euroscope',        // EuroScope (track, tags)
    'SIMTRAFFIC'     => 'simtraffic',       // SimTraffic (metering, sequencing)
    'SIMBRIEF'       => 'simbrief',         // SimBrief (OFP data)
    'HOPPIE'         => 'hoppie',           // Hoppie ACARS
    'SIMULATOR'      => 'simulator',        // Pilot simulators (telemetry)
    'VIRTUAL_AIRLINE'=> 'virtual_airline'   // Virtual airlines (schedules)
];

/**
 * Data Authority Rules
 */
$SWIM_DATA_AUTHORITY = [
    'identity'      => ['VATSIM', false],
    'flight_plan'   => ['VATSIM', false],
    'simbrief'      => ['SIMBRIEF', false],
    'adl'           => ['VATCSCC', false],
    'tmi'           => ['VATCSCC', false],
    'track'         => ['VNAS', true],
    'metering'      => ['SIMTRAFFIC', true],
    'telemetry'     => ['SIMULATOR', true],
    'datalink'      => ['HOPPIE', false],
    'airline'       => ['VIRTUAL_AIRLINE', false]
];

/**
 * GUFI Configuration
 */
define('SWIM_GUFI_PREFIX', 'VAT');
define('SWIM_GUFI_SEPARATOR', '-');

/**
 * Cache TTL Settings (seconds)
 */
$SWIM_CACHE_TTL = [
    'flights_list'   => 5,
    'flight_single'  => 3,
    'positions'      => 2,
    'tmi_programs'   => 10,
    'stats'          => 60
];

/**
 * Response Pagination Defaults
 */
define('SWIM_DEFAULT_PAGE_SIZE', 100);
define('SWIM_MAX_PAGE_SIZE', 1000);
define('SWIM_GEOJSON_PRECISION', 5);

/**
 * Data Retention (days)
 */
$SWIM_DATA_RETENTION = [
    'active_flights'    => 1,
    'positions'         => 7,
    'telemetry'         => 1,
    'tmi_history'       => 365,
    'audit_log'         => 90
];

/**
 * CORS Configuration
 */
$SWIM_CORS_ORIGINS = [
    'https://perti.vatcscc.org',
    'https://vatcscc.org',
    'https://swim.vatcscc.org',
    'http://localhost:3000',
    'http://localhost:8080'
];

/**
 * Helper: Generate GUFI
 */
function swim_generate_gufi($callsign, $dept_icao, $dest_icao, $date = null) {
    if ($date === null) {
        $date = gmdate('Ymd');
    }
    return implode(SWIM_GUFI_SEPARATOR, [
        SWIM_GUFI_PREFIX,
        $date,
        strtoupper(trim($callsign)),
        strtoupper(trim($dept_icao)),
        strtoupper(trim($dest_icao))
    ]);
}

/**
 * Helper: Parse GUFI
 */
function swim_parse_gufi($gufi) {
    $parts = explode(SWIM_GUFI_SEPARATOR, $gufi);
    if (count($parts) !== 5 || $parts[0] !== SWIM_GUFI_PREFIX) {
        return false;
    }
    return [
        'prefix'   => $parts[0],
        'date'     => $parts[1],
        'callsign' => $parts[2],
        'dept'     => $parts[3],
        'dest'     => $parts[4]
    ];
}

/**
 * Helper: Get API Key Tier
 */
function swim_get_key_tier($api_key) {
    global $SWIM_KEY_PREFIXES;
    foreach ($SWIM_KEY_PREFIXES as $tier => $prefix) {
        if (strpos($api_key, $prefix) === 0) {
            return $tier;
        }
    }
    return false;
}

/**
 * Helper: Get Rate Limit
 */
function swim_get_rate_limit($tier) {
    global $SWIM_RATE_LIMITS;
    return $SWIM_RATE_LIMITS[$tier] ?? $SWIM_RATE_LIMITS['public'];
}

/**
 * Helper: Check Data Authority
 */
function swim_can_write($field_path, $source) {
    global $SWIM_DATA_AUTHORITY, $SWIM_DATA_SOURCES;
    $root_path = explode('.', $field_path)[0];
    if (!isset($SWIM_DATA_AUTHORITY[$root_path])) {
        return false;
    }
    [$authoritative, $can_override] = $SWIM_DATA_AUTHORITY[$root_path];
    if (strtoupper($source) === $authoritative) {
        return true;
    }
    if ($can_override) {
        return in_array(strtolower($source), array_values($SWIM_DATA_SOURCES));
    }
    return false;
}
