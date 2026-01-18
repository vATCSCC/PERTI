<?php
/**
 * VATSWIM Configuration
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
 *
 * With response caching (APCu), ETags, and gzip compression enabled,
 * ~80-90% of requests hit cache with zero DB load. These limits reflect
 * the reduced infrastructure cost per request.
 *
 * Effective DB queries/min (assuming 85% cache hit rate):
 *   - system:    4,500 queries/min (30,000 * 0.15)
 *   - partner:     450 queries/min (3,000 * 0.15)
 *   - developer:    45 queries/min (300 * 0.15)
 *   - public:       15 queries/min (100 * 0.15)
 */
$SWIM_RATE_LIMITS = [
    'system'    => 30000,  // Trusted systems (vNAS, CRC, SimTraffic) - burst capacity for bulk sync
    'partner'   => 3000,   // Integration partners (VAs, etc.) - real-time operational needs
    'developer' => 300,    // Developer testing - rapid iteration
    'public'    => 100     // Public consumers - dashboard/widget use cases
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
 *
 * Per FAA CDM spec, sources are categorized by function:
 * - Core: VATSIM (identity), VATCSCC (TMI/ADL)
 * - Track: vNAS, CRC, EuroScope (ATC radar/automation)
 * - ACARS: Generic category (Hoppie, etc.) for OOOI times
 * - Metering: SimTraffic (TBFM-style), TopSky (EuroScope AMAN)
 * - External: SimBrief (OFP), Virtual Airlines (schedules, CDM T1-T4)
 * - Future: vFDS (vEDST, TDLS, vTFMS)
 */
$SWIM_DATA_SOURCES = [
    // Core sources
    'VATSIM'          => 'vatsim',           // VATSIM network (flight plans, connections)
    'VATCSCC'         => 'vatcscc',          // vATCSCC/PERTI (ADL, TMI, demand)

    // Track/position sources (ATC automation)
    'VNAS'            => 'vnas',             // vNAS (track, ATC, clearances)
    'CRC'             => 'crc',              // CRC (track, tags, handoffs)
    'EUROSCOPE'       => 'euroscope',        // EuroScope (track, tags)

    // Pilot sources
    'SIMULATOR'       => 'simulator',        // Pilot simulators (telemetry, FMC)

    // ACARS sources (CDM T11-T14 actuals)
    'ACARS'           => 'acars',            // Generic ACARS (OOOI, position reports)
    'HOPPIE'          => 'hoppie',           // Hoppie ACARS (maps to 'acars' for priority)

    // Metering sources
    'SIMTRAFFIC'      => 'simtraffic',       // SimTraffic (TBFM-style metering)
    'TOPSKY'          => 'topsky',           // TopSky EuroScope plugin (AMAN)

    // External data sources (CDM T1-T4 predictions, schedules)
    'SIMBRIEF'        => 'simbrief',         // SimBrief (OFP data, ETD/ETA)
    'VIRTUAL_AIRLINE' => 'virtual_airline',  // Virtual airlines (schedules, LRTD/LRTA/LGTD/LGTA)

    // Future integrations (planned - per plan)
    'VFDS'            => 'vfds',             // vFlightDataSystems (vEDST, TDLS, vTFMS)
    'VEDST'           => 'vedst',            // vEDST - Enhanced Departure Sequencing Tool
];

/**
 * Data Authority Rules
 * Format: [authoritative_source, can_override]
 *
 * Per FAA CDM spec:
 * - Schedule data (STD/STA) comes from VAs (OAG analog) or SimBrief
 * - CDM T1-T4 predictions come from Virtual Airlines
 * - OOOI actuals (T11-T14) come from ACARS
 * - TMI controlled times come from VATCSCC only
 */
$SWIM_DATA_AUTHORITY = [
    // Core identity - VATSIM only
    'identity'      => ['VATSIM', false],
    'flight_plan'   => ['VATSIM', false],

    // OFP data - SimBrief
    'simbrief'      => ['SIMBRIEF', false],

    // TMI/ADL - VATCSCC only (immutable)
    'adl'           => ['VATCSCC', false],
    'tmi'           => ['VATCSCC', false],

    // Track data - vNAS primary, others can override
    'track'         => ['VNAS', true],

    // Metering - SimTraffic primary, others can override
    'metering'      => ['SIMTRAFFIC', true],

    // Telemetry - Simulator primary, others can override
    'telemetry'     => ['SIMULATOR', true],

    // ACARS/datalink - ACARS is primary (Hoppie maps to acars)
    'datalink'      => ['ACARS', true],

    // Schedule data (CDM STD/STA, T1-T4) - VA primary, SimBrief fallback
    'schedule'      => ['VIRTUAL_AIRLINE', true],

    // Airline-specific data
    'airline'       => ['VIRTUAL_AIRLINE', false]
];

/**
 * Source Priority Rankings (1 = highest priority)
 * Lower number = higher priority. Sources not listed default to priority 99.
 * Used for conflict resolution when multiple sources can write to same field.
 *
 * Per FAA CDM spec and plan:
 * - Track: ATC automation > pilot sim > ACARS
 * - OOOI: ACARS (T11-T14) > simulator > VA > ADL parsing
 * - Schedule: VA (OAG analog) > SimBrief > manual
 * - Metering: SimTraffic > VATCSCC > vNAS > TopSky
 * - Times: SimTraffic > VATCSCC > vNAS > vFDS > SimBrief > simulator
 */
$SWIM_SOURCE_PRIORITY = [
    // Track position data
    'track' => [
        'vnas'       => 1,  // Primary ATC automation (radar)
        'crc'        => 2,  // Secondary ATC client
        'euroscope'  => 3,  // European ATC client
        'simulator'  => 4,  // Pilot flight sim telemetry
        'acars'      => 5,  // Generic ACARS position reports
        'hoppie'     => 5,  // Hoppie (same priority as acars)
    ],

    // OOOI times (CDM T11-T14 actuals)
    // Priority: ACARS > VA AOC > simulator > ADL parsing
    // VAs have more reliable OOOI detection than raw sim telemetry
    'oooi' => [
        'acars'           => 1,  // ACARS is authoritative for OOOI
        'hoppie'          => 1,  // Hoppie (same priority as acars)
        'virtual_airline' => 2,  // VA AOC systems (better detection than raw sim)
        'simulator'       => 3,  // Flight sim telemetry
        'vatcscc'         => 4,  // ADL parsing fallback
    ],

    // Schedule data (CDM STD/STA - OAG analog)
    'schedule' => [
        'virtual_airline' => 1,  // VA schedules (OAG analog)
        'simbrief'        => 2,  // SimBrief OFP scheduled times
        'vatcscc'         => 3,  // Manual schedule entry
    ],

    // Metering data (TBFM-style)
    'metering' => [
        'simtraffic' => 1,  // SimTraffic is primary metering
        'vatcscc'    => 2,  // PERTI manual metering
        'vnas'       => 3,  // vNAS arrival management
        'topsky'     => 4,  // TopSky EuroScope AMAN plugin
    ],

    // General times (ETA/ETD, runway times)
    'times' => [
        'simtraffic' => 1,  // SimTraffic for runway ETAs
        'vatcscc'    => 2,  // ADL calculated times
        'vnas'       => 3,  // vNAS times
        'vfds'       => 4,  // vFDS (EDST/TDLS/vTFMS) - planned
        'simbrief'   => 5,  // SimBrief OFP calculated times
        'simulator'  => 6,  // Flight sim FMC times
    ],

    // CDM T1-T4 airline predictions (LRTD/LRTA/LGTD/LGTA)
    'cdm_predictions' => [
        'virtual_airline' => 1,  // VA is authoritative for T1-T4
        'simbrief'        => 2,  // SimBrief fallback
    ],

    // Telemetry data
    'telemetry' => [
        'simulator'       => 1,  // Direct from flight sim
        'virtual_airline' => 2,  // VA PIREP systems
        'acars'           => 3,  // Generic ACARS reports
        'hoppie'          => 3,  // Hoppie (same as acars)
    ],
];

/**
 * Field Merge Behavior
 * Defines how each field handles conflicts when same-priority sources update.
 *
 * Types:
 * - 'monotonic':       Reject if incoming timestamp is older than current (position)
 * - 'variable':        Accept if incoming timestamp is newer than current (ETAs, delays)
 * - 'latest':          Always accept last write (legacy behavior)
 * - 'immutable':       Only authoritative source can write, never override
 * - 'once':            Accept first write only, ignore subsequent updates
 * - 'priority_based':  Higher priority source always wins, regardless of arrival order
 *                      (used for OOOI times where ACARS should override sim even if sim arrives first)
 */
$SWIM_FIELD_MERGE_BEHAVIOR = [
    // Position fields - monotonic (aircraft only moves forward in time)
    'lat'              => 'monotonic',
    'lon'              => 'monotonic',
    'altitude_ft'      => 'monotonic',
    'groundspeed_kts'  => 'monotonic',
    'heading_deg'      => 'monotonic',
    'vertical_rate_fpm'=> 'monotonic',

    // OOOI times (CDM T11-T14) - priority_based (ACARS > sim > VA > ADL)
    // Higher priority source always wins, not a race to first write
    'out_utc'          => 'priority_based',  // T13 - Actual Off-Block (AOBT)
    'off_utc'          => 'priority_based',  // T11 - Actual Takeoff (ATOT)
    'on_utc'           => 'priority_based',  // T12 - Actual Landing (ALDT)
    'in_utc'           => 'priority_based',  // T14 - Actual In-Block (AIBT)

    // Generic ETA/ETD - variable (can change based on FMC updates)
    'eta_utc'          => 'variable',
    'etd_utc'          => 'variable',
    'eta_runway_utc'   => 'variable',

    // Schedule times (CDM - OAG analog) - immutable once set
    'std_utc'          => 'immutable',  // Scheduled Time of Departure (from VA/SimBrief)
    'sta_utc'          => 'immutable',  // Scheduled Time of Arrival (from VA/SimBrief)

    // CDM T1-T4 airline predictions - variable (VA can update)
    'lrtd_utc'         => 'variable',   // T1 - Airline Runway Time of Departure
    'lrta_utc'         => 'variable',   // T2 - Airline Runway Time of Arrival
    'lgtd_utc'         => 'variable',   // T3 - Airline Gate Time of Departure
    'lgta_utc'         => 'variable',   // T4 - Airline Gate Time of Arrival

    // CDM T7/T8 earliest acceptable times - variable (VA constraint)
    'ertd_utc'         => 'variable',   // T7 - Earliest Runway Time of Departure
    'erta_utc'         => 'variable',   // T8 - Earliest Runway Time of Arrival

    // CDM target times - immutable (VATCSCC only)
    'tobt_utc'         => 'immutable',  // Target Off-Block Time (CDM pushback target)

    // TMI fields - immutable (only VATCSCC can set)
    'gs_held'          => 'immutable',
    'ctl_type'         => 'immutable',
    'is_exempt'        => 'immutable',
    'edct_utc'         => 'immutable',  // Expected Departure Clearance Time
    'ctd_utc'          => 'immutable',  // Controlled Time of Departure
    'cta_utc'          => 'immutable',  // Controlled Time of Arrival
    'slot_time_utc'    => 'immutable',
    'program_id'       => 'immutable',

    // Metering - variable (adjustments allowed)
    'delay_minutes'    => 'variable',
    'sequence'         => 'variable',
    'gate'             => 'variable',
    'frozen'           => 'variable',
    'runway'           => 'variable',
    'sta_meter_fix_utc'=> 'variable',   // STA at meter fix (TBFM-style)

    // Flight plan core - immutable (only VATSIM)
    'fp_dept_icao'     => 'immutable',
    'fp_dest_icao'     => 'immutable',
    'callsign'         => 'immutable',
    'cid'              => 'immutable',

    // Flight plan details - latest (allow amendments)
    'fp_route'         => 'latest',
    'fp_remarks'       => 'latest',
    'aircraft_type'    => 'latest',
    'cruise_altitude'  => 'latest',
    'cruise_speed'     => 'latest',
];

/**
 * Field to Authority Group Mapping
 * Maps individual fields to their authority group for permission checking.
 *
 * Per FAA CDM spec:
 * - T1-T4 (airline predictions): schedule authority (VA primary)
 * - T7-T8 (earliest acceptable): schedule authority (VA constraint)
 * - T11-T14 (actuals/OOOI): datalink authority (ACARS primary)
 * - TMI controlled times: tmi authority (VATCSCC only)
 */
$SWIM_FIELD_AUTHORITY_MAP = [
    // Track fields (ATC automation)
    'lat'               => 'track',
    'lon'               => 'track',
    'altitude_ft'       => 'track',
    'groundspeed_kts'   => 'track',
    'heading_deg'       => 'track',

    // Telemetry fields (from flight sim)
    'vertical_rate_fpm' => 'telemetry',

    // OOOI times (CDM T11-T14) - datalink authority (ACARS primary)
    'out_utc'           => 'datalink',  // T13 - AOBT
    'off_utc'           => 'datalink',  // T11 - ATOT
    'on_utc'            => 'datalink',  // T12 - ALDT
    'in_utc'            => 'datalink',  // T14 - AIBT

    // Schedule times (OAG analog) - schedule authority
    'std_utc'           => 'schedule',  // Scheduled Time of Departure
    'sta_utc'           => 'schedule',  // Scheduled Time of Arrival

    // CDM T1-T4 airline predictions - schedule authority (VA primary)
    'lrtd_utc'          => 'schedule',  // T1 - Airline Runway Time of Departure
    'lrta_utc'          => 'schedule',  // T2 - Airline Runway Time of Arrival
    'lgtd_utc'          => 'schedule',  // T3 - Airline Gate Time of Departure
    'lgta_utc'          => 'schedule',  // T4 - Airline Gate Time of Arrival

    // CDM T7/T8 earliest acceptable times - schedule authority (VA constraint)
    'ertd_utc'          => 'schedule',  // T7 - Earliest Runway Time of Departure
    'erta_utc'          => 'schedule',  // T8 - Earliest Runway Time of Arrival

    // TMI fields - tmi authority (VATCSCC only)
    'gs_held'           => 'tmi',
    'ctl_type'          => 'tmi',
    'is_exempt'         => 'tmi',
    'edct_utc'          => 'tmi',       // Expected Departure Clearance Time
    'ctd_utc'           => 'tmi',       // Controlled Time of Departure
    'cta_utc'           => 'tmi',       // Controlled Time of Arrival
    'tobt_utc'          => 'tmi',       // Target Off-Block Time (CDM)
    'slot_time_utc'     => 'tmi',
    'program_id'        => 'tmi',
    'delay_minutes'     => 'tmi',

    // Metering fields - metering authority (SimTraffic primary)
    'sequence'          => 'metering',
    'gate'              => 'metering',
    'frozen'            => 'metering',
    'runway'            => 'metering',
    'eta_runway_utc'    => 'metering',
    'sta_meter_fix_utc' => 'metering',  // STA at meter fix (TBFM-style)

    // Generic time fields - adl authority
    'eta_utc'           => 'adl',
    'etd_utc'           => 'adl',

    // Flight plan fields
    'fp_dept_icao'      => 'flight_plan',
    'fp_dest_icao'      => 'flight_plan',
    'fp_route'          => 'adl',
    'fp_remarks'        => 'adl',
    'callsign'          => 'identity',
    'cid'               => 'identity',
    'aircraft_type'     => 'adl',
    'cruise_altitude'   => 'adl',
    'cruise_speed'      => 'adl',
];

/**
 * Timestamp Tolerance (seconds)
 * Allow acceptance of slightly older data to account for network latency.
 */
define('SWIM_TIMESTAMP_TOLERANCE', 5);

/**
 * GUFI Configuration
 */
define('SWIM_GUFI_PREFIX', 'VAT');
define('SWIM_GUFI_SEPARATOR', '-');

/**
 * Cache TTL Settings (seconds)
 * Base TTLs per endpoint - actual TTL is tier-adjusted
 */
$SWIM_CACHE_TTL = [
    'flights_list'   => 5,
    'flight_single'  => 3,
    'positions'      => 2,
    'tmi_programs'   => 10,
    'metering'       => 5,
    'stats'          => 60
];

/**
 * Tier-Based Cache TTL Multipliers
 *
 * Data syncs from VATSIM_ADL every 15 seconds, so even PUBLIC tier
 * polling every 30 seconds gets fresh-enough data. Higher tiers get
 * lower TTLs for more real-time access.
 *
 * Effective TTL = base_ttl * multiplier
 */
$SWIM_TIER_CACHE_MULTIPLIERS = [
    'system'    => 1,    // Real-time (5s base = 5s cache)
    'partner'   => 2,    // Near real-time (5s base = 10s cache)
    'developer' => 3,    // Development (5s base = 15s cache)
    'public'    => 6     // General access (5s base = 30s cache)
];

/**
 * Response Compression Settings
 */
define('SWIM_ENABLE_GZIP', true);
define('SWIM_GZIP_MIN_SIZE', 1024);  // Only compress responses > 1KB

/**
 * ETag Settings
 */
define('SWIM_ENABLE_ETAG', true);

/**
 * Response Format Configuration
 *
 * Supported formats by endpoint type:
 *   - flights:  json, fixm, xml, geojson, csv, kml, ndjson
 *   - metering: json, fixm, xml, csv, ndjson (no spatial data)
 *   - positions: json, fixm, xml, geojson, csv, kml, ndjson
 *
 * Format descriptions:
 *   - json:    Standard JSON with snake_case field names (default)
 *   - fixm:    JSON with FIXM 4.3.0 camelCase field names
 *   - xml:     XML format for enterprise/SOAP integrations
 *   - geojson: GeoJSON FeatureCollection for mapping (Leaflet, Mapbox)
 *   - csv:     CSV for spreadsheet/analytics export (Excel, pandas)
 *   - kml:     KML for Google Earth visualization
 *   - ndjson:  Newline-delimited JSON for streaming/bulk processing
 */
$SWIM_SUPPORTED_FORMATS = [
    'flights'   => ['json', 'fixm', 'xml', 'geojson', 'csv', 'kml', 'ndjson'],
    'metering'  => ['json', 'fixm', 'xml', 'csv', 'ndjson'],
    'positions' => ['json', 'fixm', 'xml', 'geojson', 'csv', 'kml', 'ndjson'],
    'default'   => ['json', 'fixm', 'xml', 'ndjson']
];

/**
 * Format Content-Type headers
 */
$SWIM_FORMAT_CONTENT_TYPES = [
    'json'    => 'application/json; charset=utf-8',
    'fixm'    => 'application/json; charset=utf-8',
    'xml'     => 'application/xml; charset=utf-8',
    'geojson' => 'application/geo+json; charset=utf-8',
    'csv'     => 'text/csv; charset=utf-8',
    'kml'     => 'application/vnd.google-earth.kml+xml; charset=utf-8',
    'ndjson'  => 'application/x-ndjson; charset=utf-8'
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
 * Helper: Check Data Authority (group-level)
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

/**
 * Helper: Get authority group for a specific field
 * @param string $field Field name (e.g., 'lat', 'gs_held', 'eta_utc')
 * @return string|null Authority group name or null if not mapped
 */
function swim_get_field_authority_group($field) {
    global $SWIM_FIELD_AUTHORITY_MAP;
    return $SWIM_FIELD_AUTHORITY_MAP[$field] ?? null;
}

/**
 * Helper: Check if source can write a specific field
 * @param string $field Field name
 * @param string $source Source identifier
 * @return bool True if source is authorized to write the field
 */
function swim_can_write_field($field, $source) {
    $authority_group = swim_get_field_authority_group($field);
    if ($authority_group === null) {
        // Field not mapped - fall back to allowing write (backward compat)
        return true;
    }
    return swim_can_write($authority_group, $source);
}

/**
 * Helper: Get source priority for a field group
 * @param string $field_group Field group (track, oooi, metering, times, telemetry)
 * @param string $source Source identifier
 * @return int Priority (1 = highest, 99 = default/lowest)
 */
function swim_get_source_priority($field_group, $source) {
    global $SWIM_SOURCE_PRIORITY;
    $source = strtolower($source);
    if (!isset($SWIM_SOURCE_PRIORITY[$field_group])) {
        return 99;
    }
    return $SWIM_SOURCE_PRIORITY[$field_group][$source] ?? 99;
}

/**
 * Helper: Get merge behavior for a field
 * @param string $field Field name
 * @return string Merge behavior type (monotonic, variable, latest, immutable, once)
 */
function swim_get_field_merge_behavior($field) {
    global $SWIM_FIELD_MERGE_BEHAVIOR;
    return $SWIM_FIELD_MERGE_BEHAVIOR[$field] ?? 'latest';
}

/**
 * Helper: Determine if an update should be accepted based on merge rules
 *
 * @param string $field Field name being updated
 * @param string $incoming_source Source providing the update
 * @param mixed $incoming_timestamp Timestamp of incoming data (DateTime, string, or null)
 * @param mixed $current_timestamp Timestamp of current data (DateTime, string, or null)
 * @param string|null $current_source Source that last wrote the field (if known)
 * @param mixed $current_value Current value of the field (for 'once' behavior)
 * @return array ['accept' => bool, 'reason' => string]
 */
function swim_should_accept_update($field, $incoming_source, $incoming_timestamp = null,
                                   $current_timestamp = null, $current_source = null,
                                   $current_value = null) {
    // Step 1: Check if source is authorized to write this field at all
    if (!swim_can_write_field($field, $incoming_source)) {
        return [
            'accept' => false,
            'reason' => 'not_authorized',
            'message' => "Source '$incoming_source' not authorized to write field '$field'"
        ];
    }

    // Step 2: Get merge behavior for this field
    $behavior = swim_get_field_merge_behavior($field);

    // Handle immutable fields - only authoritative source can write
    if ($behavior === 'immutable') {
        $authority_group = swim_get_field_authority_group($field);
        global $SWIM_DATA_AUTHORITY;
        if (isset($SWIM_DATA_AUTHORITY[$authority_group])) {
            [$authoritative, ] = $SWIM_DATA_AUTHORITY[$authority_group];
            if (strtoupper($incoming_source) !== $authoritative) {
                return [
                    'accept' => false,
                    'reason' => 'immutable',
                    'message' => "Field '$field' is immutable, only $authoritative can write"
                ];
            }
        }
        return ['accept' => true, 'reason' => 'authoritative'];
    }

    // Handle 'once' fields - only accept if current value is null/empty
    if ($behavior === 'once') {
        if ($current_value !== null && $current_value !== '') {
            return [
                'accept' => false,
                'reason' => 'already_set',
                'message' => "Field '$field' already has a value and only accepts first write"
            ];
        }
        return ['accept' => true, 'reason' => 'first_write'];
    }

    // Handle 'latest' behavior - always accept (legacy mode)
    if ($behavior === 'latest') {
        return ['accept' => true, 'reason' => 'latest_wins'];
    }

    // Step 3: For priority-based behaviors, compare source priorities
    $authority_group = swim_get_field_authority_group($field);

    // Map authority groups to priority groups
    // Per FAA CDM spec:
    // - datalink (OOOI) uses 'oooi' priority (ACARS > sim > VA > ADL)
    // - schedule (STD/STA, T1-T4, T7-T8) uses 'schedule' priority (VA > SimBrief)
    // - cdm_predictions (LRTD/LRTA/LGTD/LGTA) uses 'cdm_predictions' priority
    $priority_group_map = [
        'track'      => 'track',
        'telemetry'  => 'telemetry',
        'datalink'   => 'oooi',           // OOOI times use oooi priority
        'schedule'   => 'schedule',       // Schedule/CDM times use schedule priority
        'metering'   => 'metering',
        'tmi'        => 'times',
        'adl'        => 'times',
    ];
    $priority_group = $priority_group_map[$authority_group] ?? 'times';

    // Handle 'priority_based' behavior - higher priority source always wins
    // This is used for OOOI times where ACARS should override sim even if sim arrives first
    if ($behavior === 'priority_based') {
        $incoming_priority = swim_get_source_priority($priority_group, $incoming_source);
        $current_priority = $current_source
            ? swim_get_source_priority($priority_group, $current_source)
            : 99;

        // If no current value, accept any authorized source
        if ($current_value === null || $current_value === '') {
            return ['accept' => true, 'reason' => 'first_write'];
        }

        // Higher priority (lower number) always wins
        if ($incoming_priority < $current_priority) {
            return ['accept' => true, 'reason' => 'higher_priority_override'];
        }

        // Same priority - accept if from same source (update) or newer timestamp
        if ($incoming_priority === $current_priority) {
            // Same source can always update its own value
            if (strtolower($incoming_source) === strtolower($current_source ?? '')) {
                return ['accept' => true, 'reason' => 'same_source_update'];
            }
            // Different source with same priority - use timestamp
            $incoming_ts = swim_parse_timestamp($incoming_timestamp);
            $current_ts = swim_parse_timestamp($current_timestamp);
            if ($incoming_ts !== null && $current_ts !== null) {
                if ($incoming_ts->getTimestamp() >= $current_ts->getTimestamp()) {
                    return ['accept' => true, 'reason' => 'same_priority_newer'];
                }
            }
            // Accept if we can't determine (no timestamps)
            return ['accept' => true, 'reason' => 'same_priority_no_timestamp'];
        }

        // Lower priority source cannot override
        return [
            'accept' => false,
            'reason' => 'lower_priority',
            'message' => "Source '$incoming_source' has lower priority than current source '$current_source'"
        ];
    }

    $incoming_priority = swim_get_source_priority($priority_group, $incoming_source);
    $current_priority = $current_source
        ? swim_get_source_priority($priority_group, $current_source)
        : 99;

    // Higher priority (lower number) always wins
    if ($incoming_priority < $current_priority) {
        return ['accept' => true, 'reason' => 'higher_priority'];
    }

    // Lower priority source cannot overwrite higher priority
    if ($incoming_priority > $current_priority) {
        return [
            'accept' => false,
            'reason' => 'lower_priority',
            'message' => "Source '$incoming_source' has lower priority than current source"
        ];
    }

    // Step 4: Same priority - use timestamp-based tiebreaker
    // Parse timestamps if provided as strings
    $incoming_ts = swim_parse_timestamp($incoming_timestamp);
    $current_ts = swim_parse_timestamp($current_timestamp);

    // If timestamps unavailable, accept (can't determine)
    if ($incoming_ts === null || $current_ts === null) {
        return ['accept' => true, 'reason' => 'no_timestamp'];
    }

    $tolerance = SWIM_TIMESTAMP_TOLERANCE;

    if ($behavior === 'monotonic') {
        // Monotonic: reject if incoming is older than current (minus tolerance)
        $threshold = $current_ts->getTimestamp() - $tolerance;
        if ($incoming_ts->getTimestamp() < $threshold) {
            return [
                'accept' => false,
                'reason' => 'stale_data',
                'message' => "Incoming data is older than current (monotonic field)"
            ];
        }
        return ['accept' => true, 'reason' => 'newer_or_equal'];
    }

    if ($behavior === 'variable') {
        // Variable: accept if incoming is newer than current (with tolerance)
        $threshold = $current_ts->getTimestamp() + $tolerance;
        if ($incoming_ts->getTimestamp() >= $threshold) {
            return ['accept' => true, 'reason' => 'newer_timestamp'];
        }
        // Within tolerance - accept to allow for minor time differences
        return ['accept' => true, 'reason' => 'within_tolerance'];
    }

    // Default: accept
    return ['accept' => true, 'reason' => 'default'];
}

/**
 * Helper: Parse timestamp to DateTime object
 * @param mixed $timestamp DateTime object, ISO string, or null
 * @return DateTime|null
 */
function swim_parse_timestamp($timestamp) {
    if ($timestamp === null) {
        return null;
    }
    if ($timestamp instanceof DateTime) {
        return $timestamp;
    }
    if (is_string($timestamp)) {
        try {
            return new DateTime($timestamp);
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

/**
 * Helper: Evaluate merge decision for a batch of fields
 * Returns array of fields with their accept/reject decisions
 *
 * @param array $fields Array of field updates: ['field' => ['value' => x, 'timestamp' => y], ...]
 * @param string $source Incoming source
 * @param array $current_data Current flight data with timestamps and source info
 * @return array ['accepted' => [...], 'rejected' => [...]]
 */
function swim_evaluate_field_updates($fields, $source, $current_data = []) {
    $accepted = [];
    $rejected = [];

    foreach ($fields as $field => $update) {
        $incoming_ts = $update['timestamp'] ?? null;
        $incoming_value = $update['value'] ?? $update;

        // Get current state for this field
        $current_ts = $current_data[$field . '_updated_at'] ?? $current_data['last_sync_utc'] ?? null;
        $current_source = $current_data[$field . '_source'] ?? null;
        $current_value = $current_data[$field] ?? null;

        $decision = swim_should_accept_update(
            $field,
            $source,
            $incoming_ts,
            $current_ts,
            $current_source,
            $current_value
        );

        if ($decision['accept']) {
            $accepted[$field] = [
                'value' => $incoming_value,
                'reason' => $decision['reason']
            ];
        } else {
            $rejected[$field] = [
                'value' => $incoming_value,
                'reason' => $decision['reason'],
                'message' => $decision['message'] ?? ''
            ];
        }
    }

    return ['accepted' => $accepted, 'rejected' => $rejected];
}

/**
 * Helper: Get effective cache TTL for endpoint and tier
 *
 * @param string $endpoint Endpoint key (flights_list, flight_single, etc.)
 * @param string $tier API tier (system, partner, developer, public)
 * @return int TTL in seconds
 */
function swim_get_cache_ttl($endpoint, $tier = 'public') {
    global $SWIM_CACHE_TTL, $SWIM_TIER_CACHE_MULTIPLIERS;

    $base_ttl = $SWIM_CACHE_TTL[$endpoint] ?? 5;
    $multiplier = $SWIM_TIER_CACHE_MULTIPLIERS[$tier] ?? 6;

    return $base_ttl * $multiplier;
}

/**
 * Helper: Generate cache key from request parameters
 *
 * @param string $endpoint Endpoint identifier
 * @param array $params Request parameters to include in key
 * @return string Cache key
 */
function swim_cache_key($endpoint, $params = []) {
    ksort($params);  // Ensure consistent ordering
    return 'swim_' . $endpoint . '_' . md5(json_encode($params));
}

/**
 * Helper: Check if APCu caching is available
 *
 * @return bool
 */
function swim_cache_available() {
    return function_exists('apcu_fetch') && apcu_enabled();
}

/**
 * Helper: Get cached response
 *
 * @param string $cache_key
 * @return array|null Cached data or null if not found
 */
function swim_cache_get($cache_key) {
    if (!swim_cache_available()) {
        return null;
    }
    $data = apcu_fetch($cache_key, $success);
    return $success ? $data : null;
}

/**
 * Helper: Store response in cache
 *
 * @param string $cache_key
 * @param mixed $data Data to cache
 * @param int $ttl TTL in seconds
 * @return bool Success
 */
function swim_cache_set($cache_key, $data, $ttl) {
    if (!swim_cache_available()) {
        return false;
    }
    return apcu_store($cache_key, $data, $ttl);
}
