<?php
/**
 * GDT API Common Utilities
 * 
 * Shared functions for Ground Delay Tools API endpoints.
 * Uses VATSIM_TMI database for program/slot management and
 * VATSIM_ADL database for flight data.
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

// Prevent direct access
if (!defined('GDT_API_INCLUDED')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Direct access not allowed']);
    exit;
}

// ============================================================================
// Response Helpers
// ============================================================================

/**
 * Send JSON response and exit
 */
function respond_json($code, $payload) {
    if (!isset($payload['timestamp'])) {
        $payload['timestamp'] = gmdate('c');
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Read request payload from JSON body or form data
 */
function read_request_payload() {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return array_merge($_GET ?? [], $_POST ?? []);
}

// ============================================================================
// Session & Database Connections
// ============================================================================

// Start session BEFORE loading config/connect, because connect.php's closing
// PHP tag outputs a trailing newline that sends headers, preventing later
// session_start() calls from succeeding (headers_sent() returns true).
require_once(__DIR__ . '/../../sessions/handler.php');

// Load core dependencies (same pattern as /api/tmi/helpers.php)
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');
require_once(__DIR__ . '/../../load/tmi_log.php');

// ============================================================================
// Authentication
// ============================================================================

/**
 * Require authenticated session for destructive operations (activate, cancel, purge, publish).
 * Sends 401 and exits if not authenticated.
 * @return string The authenticated user's VATSIM CID
 */
function gdt_require_auth() {
    if (!isset($_SESSION['VATSIM_CID']) || empty($_SESSION['VATSIM_CID'])) {
        respond_json(401, [
            'status' => 'error',
            'message' => 'Authentication required. Please log in.'
        ]);
    }

    return $_SESSION['VATSIM_CID'];
}

/**
 * Get authenticated CID if available, otherwise return 'anonymous'.
 * Use for preview/modeling/simulation endpoints that don't require login.
 * @return string VATSIM CID or 'anonymous'
 */
function gdt_optional_auth() {
    if (isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID'])) {
        return $_SESSION['VATSIM_CID'];
    }
    return 'anonymous';
}

/**
 * Get TMI database connection (VATSIM_TMI - program/slot management)
 * Uses the global connection established in connect.php
 * @return resource|null SQLSRV connection resource
 */
function gdt_get_conn_tmi() {
    global $conn_tmi;
    
    if (!$conn_tmi) {
        $errors = filter_sqlsrv_errors();
        respond_json(500, [
            'status'  => 'error',
            'message' => 'TMI SQL connection not established. Check TMI_SQL_* constants in config.php.',
            'errors'  => $errors
        ]);
    }
    
    return $conn_tmi;
}

/**
 * Get ADL database connection (VATSIM_ADL - flight data)
 * Uses the global connection established in connect.php
 * @return resource|null SQLSRV connection resource
 */
function gdt_get_conn_adl() {
    global $conn_adl;
    
    if (!$conn_adl) {
        $errors = filter_sqlsrv_errors();
        respond_json(500, [
            'status'  => 'error',
            'message' => 'ADL SQL connection not established. Check ADL_SQL_* constants in config.php.',
            'errors'  => $errors
        ]);
    }
    
    return $conn_adl;
}

/**
 * Filter out informational SQL Server messages
 */
function filter_sqlsrv_errors() {
    if (!function_exists('sqlsrv_errors')) {
        return null;
    }
    
    $all_errors = sqlsrv_errors();
    if (!$all_errors) {
        return null;
    }
    
    // Filter out info messages (5701=Changed database, 5703=Changed language)
    $errors = array_filter($all_errors, function($e) {
        $code = isset($e['code']) ? $e['code'] : 0;
        return !in_array($code, [5701, 5703]);
    });
    
    return empty($errors) ? null : array_values($errors);
}

// ============================================================================
// Data Type Helpers
// ============================================================================

/**
 * Parse and validate UTC datetime string
 * @param string $s Input datetime string
 * @return string|null SQL Server compatible datetime or null
 */
function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s) === '') return null;
    
    try {
        $dt = new DateTime(trim($s));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Convert DateTime to ISO 8601 string for JSON
 * @param mixed $val DateTime object or value
 * @return string|mixed ISO string or original value
 */
function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof DateTimeInterface) {
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    return $val;
}

/**
 * Split space/comma-delimited codes into array
 * @param mixed $val Input string or array
 * @return array Array of uppercase codes
 */
function split_codes($val) {
    if (is_array($val)) {
        $val = implode(' ', $val);
    }
    if (!is_string($val)) return [];
    
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    
    $val = str_replace([",", ";", "\n", "\r", "\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    
    $out = [];
    $seen = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !isset($seen[$p])) {
            $seen[$p] = true;
            $out[] = $p;
        }
    }
    return $out;
}

// ============================================================================
// Query Helpers
// ============================================================================

/**
 * Fetch all rows from a query, converting DateTime objects
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array ['success' => bool, 'data' => array|null, 'error' => mixed]
 */
function fetch_all($conn, $sql, $params = []) {
    $stmt = count($params) > 0 
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        return [
            'success' => false,
            'data' => null,
            'error' => sqlsrv_errors()
        ];
    }
    
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to ISO strings
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $row[$k] = datetime_to_iso($v);
            }
        }
        $rows[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'success' => true,
        'data' => $rows,
        'error' => null
    ];
}

/**
 * Fetch single row from a query
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array ['success' => bool, 'data' => array|null, 'error' => mixed]
 */
function fetch_one($conn, $sql, $params = []) {
    $result = fetch_all($conn, $sql, $params);
    if (!$result['success']) {
        return $result;
    }
    return [
        'success' => true,
        'data' => count($result['data']) > 0 ? $result['data'][0] : null,
        'error' => null
    ];
}

/**
 * Fetch a single scalar value
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array [value, error]
 */
function fetch_value($conn, $sql, $params = []) {
    $stmt = count($params) > 0 
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        return [null, sqlsrv_errors()];
    }
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    
    if (!$row) {
        return [null, null];
    }
    
    $val = $row[0];
    if ($val instanceof DateTimeInterface) {
        $val = datetime_to_iso($val);
    }
    
    return [$val, null];
}

/**
 * Execute a query that doesn't return results
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array ['success' => bool, 'rows_affected' => int|null, 'error' => mixed]
 */
function execute_query($conn, $sql, $params = []) {
    $stmt = count($params) > 0 
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        return [
            'success' => false,
            'rows_affected' => null,
            'error' => sqlsrv_errors()
        ];
    }
    
    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    return [
        'success' => true,
        'rows_affected' => $rows,
        'error' => null
    ];
}

/**
 * Get current UTC time from SQL Server
 * @param resource $conn SQLSRV connection
 * @return string ISO formatted UTC time
 */
function get_server_utc($conn) {
    list($utc, $err) = fetch_value($conn, "SELECT SYSUTCDATETIME()");
    return $utc ?? date('Y-m-d\TH:i:s') . 'Z';
}

// ============================================================================
// TMI-Specific Helpers
// ============================================================================

/**
 * Program types supported by GDT
 */
define('GDT_PROGRAM_TYPES', [
    'GS' => [
        'name' => 'Ground Stop',
        'has_slots' => false,
        'has_rates' => false
    ],
    'GDP-DAS' => [
        'name' => 'GDP - Delay Assignment System',
        'has_slots' => true,
        'has_rates' => true
    ],
    'GDP-GAAP' => [
        'name' => 'GDP - General Aviation Airport Program',
        'has_slots' => true,
        'has_rates' => true,
        'has_reserve' => true
    ],
    'GDP-UDP' => [
        'name' => 'GDP - Unified Delay Program',
        'has_slots' => true,
        'has_rates' => true,
        'has_reserve' => true
    ],
    'AFP' => [
        'name' => 'Airspace Flow Program',
        'has_slots' => true,
        'has_rates' => true
    ]
]);

/**
 * Valid program statuses
 */
define('GDT_PROGRAM_STATUSES', [
    'PROPOSED',   // Created but not activated
    'MODELING',   // Slots generated, being modeled
    'ACTIVE',     // Live program
    'EXTENDED',   // Extended from original end time
    'SUPERSEDED', // Replaced by revision
    'COMPLETED',  // Finished normally
    'CANCELLED',  // Cancelled before completion
    'PURGED'      // Cancelled and flight controls cleared
]);

/**
 * Validate program type
 */
function is_valid_program_type($type) {
    return isset(GDT_PROGRAM_TYPES[$type]);
}

/**
 * Get program from TMI database
 * @param resource $conn TMI connection
 * @param int $program_id Program ID
 * @return array|null Program record or null
 */
function get_program($conn, $program_id) {
    $result = fetch_one($conn, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [(int)$program_id]);
    return $result['success'] ? $result['data'] : null;
}

/**
 * Get active program for an element
 * @param resource $conn TMI connection
 * @param string $ctl_element Control element (airport code)
 * @return array|null Program record or null
 */
function get_active_program($conn, $ctl_element) {
    $result = fetch_one($conn,
        "SELECT * FROM dbo.tmi_programs WHERE ctl_element = ? AND is_active = 1",
        [strtoupper(trim($ctl_element))]
    );
    return $result['success'] ? $result['data'] : null;
}

/**
 * Look up the responsible ARTCC for an airport ICAO code.
 * Returns 3-letter ARTCC code (e.g. 'ZNY') or fallback.
 */
function resolve_program_artcc($program, $conn_adl = null) {
    $ctl = $program['ctl_element'] ?? '';

    // Try database lookup via RESP_ARTCC_ID
    if ($conn_adl && $ctl !== '') {
        $stmt = sqlsrv_query($conn_adl,
            "SELECT RESP_ARTCC_ID FROM dbo.apts WHERE ICAO_ID = ?",
            [$ctl]
        );
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row && !empty($row['RESP_ARTCC_ID'])) {
                return $row['RESP_ARTCC_ID'];
            }
        }
    }

    // Fallback: extract from scope_json origin_centers (first entry stripped of K prefix)
    $scope = $program['scope_json'] ?? null;
    if (is_string($scope)) {
        $scope = json_decode($scope, true);
    }
    if (is_array($scope) && !empty($scope['origin_centers'])) {
        $first = $scope['origin_centers'][0];
        // origin_centers uses ICAO format (KZNY) — strip K prefix for advisory format (ZNY)
        if (strlen($first) === 4 && $first[0] === 'K') {
            return substr($first, 1);
        }
        return $first;
    }

    return 'ZZZ';
}

/**
 * Extract scope facilities string from program's scope_json for advisory text.
 * Returns comma-separated ARTCC codes or fallback.
 */
function resolve_scope_facilities($program, $fallback = null) {
    $scope = $program['scope_json'] ?? null;
    if (is_string($scope)) {
        $scope = json_decode($scope, true);
    }
    if (is_array($scope) && !empty($scope['origin_centers'])) {
        return implode(' ', $scope['origin_centers']);
    }
    return $fallback ?? 'ALL';
}

/**
 * Format program rate for advisory display.
 * Fixed rate: "30/HR"
 * Variable rate (hourly): "25 / 30 / 35 / 40/HR" (each hour's rate)
 */
function format_rate_display($program) {
    $flat_rate = $program['program_rate'] ?? 0;

    // Check for hourly variable rates
    $hourly_json = $program['rates_hourly_json'] ?? null;
    if (is_string($hourly_json) && strlen($hourly_json) > 2) {
        $hourly = json_decode($hourly_json, true);
        if (is_array($hourly) && count($hourly) > 1) {
            ksort($hourly);
            $rates = array_values($hourly);
            if (count(array_unique($rates)) > 1) {
                return implode(' / ', $rates) . '/HR';
            }
        }
    }

    // Check quarter rates and derive hourly averages
    $quarter_json = $program['rates_quarter_json'] ?? null;
    if (is_string($quarter_json) && strlen($quarter_json) > 2) {
        $quarters = json_decode($quarter_json, true);
        if (is_array($quarters) && count($quarters) > 4) {
            $hour_buckets = [];
            foreach ($quarters as $key => $val) {
                $hh = substr($key, 0, 2);
                $hour_buckets[$hh][] = (int)$val;
            }
            ksort($hour_buckets);
            if (count($hour_buckets) > 1) {
                $hourly_rates = [];
                foreach ($hour_buckets as $vals) {
                    $hourly_rates[] = (int)round(array_sum($vals) / count($vals));
                }
                if (count(array_unique($hourly_rates)) > 1) {
                    return implode(' / ', $hourly_rates) . '/HR';
                }
            }
        }
    }

    return "{$flat_rate}/HR";
}

/**
 * Generate ACTUAL advisory text (vATCSCC format).
 * Supports both GDP and GS program types.
 *
 * @param array $program Program record from tmi_programs
 * @param string $advisory_number Advisory number string
 * @param resource|null $conn_adl ADL connection for ARTCC lookup
 * @return string Formatted advisory text
 */
function generate_actual_advisory($program, $advisory_number, $conn_adl = null) {
    $program_type = $program['program_type'] ?? 'GS';
    $is_gdp = strpos($program_type, 'GDP') !== false;
    $ctl_element = $program['ctl_element'] ?? 'UNKN';
    $element_type = $program['element_type'] ?? 'APT';
    $artcc = resolve_program_artcc($program, $conn_adl);

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $adl_time = $now->format('Hi') . 'Z';
    $header_date = $now->format('m/d/Y');

    $start = $program['start_utc'] instanceof DateTime ? $program['start_utc'] : new DateTime($program['start_utc']);
    $end = $program['end_utc'] instanceof DateTime ? $program['end_utc'] : new DateTime($program['end_utc']);
    $start_period = $start->format('d/Hi') . 'Z';
    $end_period = $end->format('d/Hi') . 'Z';

    $start_code = $start->format('dHi');
    $end_code = $end->format('dHi');
    $footer_timestamp = $now->format('y/m/d H:i');

    $total_delay = round($program['total_delay_min'] ?? 0);
    $max_delay = round($program['max_delay_min'] ?? $program['delay_limit_min'] ?? 0);
    $avg_delay = round($program['avg_delay_min'] ?? 0);

    $flt_incl = $program['flt_incl_type'] ?? 'ALL';
    $facilities = resolve_scope_facilities($program, $artcc);
    $prob_extension = $program['prob_extension'] ?? 'MODERATE';
    $reason = $program['impacting_condition'] ?? 'VOLUME';
    $comments = $program['comments'] ?? 'NONE';

    $adv_num = preg_replace('/[^0-9]/', '', $advisory_number) ?: '001';
    $adv_num = str_pad($adv_num, 3, '0', STR_PAD_LEFT);

    // Scope tier label from scope_json
    $scope = $program['scope_json'] ?? null;
    if (is_string($scope)) $scope = json_decode($scope, true);
    $scope_group = (is_array($scope) && !empty($scope['scope_group'])) ? $scope['scope_group'] : 'Tier1';

    $lines = [];

    if ($is_gdp) {
        $program_rate = $program['program_rate'] ?? 0;
        $controlled_flights = $program['controlled_flights'] ?? $program['flight_count'] ?? 0;

        // Format rate display: variable rates show each hour " / " delimited
        $rate_display = format_rate_display($program);

        $lines[] = "vATCSCC ADVZY {$adv_num} {$ctl_element}/{$artcc} {$header_date} CDM GROUND DELAY PROGRAM";
        $lines[] = "CTL ELEMENT: {$ctl_element}";
        $lines[] = "ELEMENT TYPE: {$element_type}";
        $lines[] = "ADL TIME: {$adl_time}";
        $lines[] = "GDP PERIOD: {$start_period} - {$end_period}";
        $lines[] = "FLT INCL: {$flt_incl}";
        $lines[] = "DEP FACILITIES INCLUDED: ({$scope_group}) {$facilities}";
        $lines[] = "PROGRAM RATE: {$rate_display}";
        $lines[] = "DELAY ASSIGNMENT MODE: UDP";
        $lines[] = "NEW TOTAL, MAXIMUM, AVERAGE DELAYS: {$total_delay} / {$max_delay} / {$avg_delay}";
        $lines[] = "CONTROLLED FLIGHTS: {$controlled_flights}";
        $lines[] = "PROBABILITY OF EXTENSION: {$prob_extension}";
        $lines[] = "IMPACTING CONDITION: {$reason}";
        $lines[] = "COMMENTS: {$comments}";
    } else {
        $lines[] = "vATCSCC ADVZY {$adv_num} {$ctl_element}/{$artcc} {$header_date} CDM GROUND STOP";
        $lines[] = "CTL ELEMENT: {$ctl_element}";
        $lines[] = "ELEMENT TYPE: {$element_type}";
        $lines[] = "ADL TIME: {$adl_time}";
        $lines[] = "GROUND STOP PERIOD: {$start_period} - {$end_period}";
        $lines[] = "FLT INCL: {$flt_incl}";
        $lines[] = "DEP FACILITIES INCLUDED: ({$scope_group}) {$facilities}";
        $lines[] = "NEW TOTAL, MAXIMUM, AVERAGE DELAYS: {$total_delay} / {$max_delay} / {$avg_delay}";
        $lines[] = "PROBABILITY OF EXTENSION: {$prob_extension}";
        $lines[] = "IMPACTING CONDITION: {$reason}";
        $lines[] = "COMMENTS: {$comments}";
    }

    $lines[] = "";
    $lines[] = "{$start_code}-{$end_code}";
    $lines[] = $footer_timestamp;

    return implode("\n", $lines);
}

/**
 * Write an advisory record to tmi_advisories.
 *
 * @param resource $conn_tmi TMI sqlsrv connection
 * @param array $program Program record
 * @param string $advisory_number Advisory number string
 * @param string $advisory_type 'GDP', 'GS', 'GDP_CNX', 'GS_CNX'
 * @param string $advisory_text Full advisory text
 * @param string|null $created_by User CID
 * @param string|null $created_by_name User name
 */
function write_advisory_record($conn_tmi, $program, $advisory_number, $advisory_type, $advisory_text, $created_by = null, $created_by_name = null) {
    $program_type = $program['program_type'] ?? 'GS';
    $is_cancel = strpos($advisory_type, 'CNX') !== false;
    $subject = $is_cancel
        ? strtoupper(str_replace('-', ' ', $program_type)) . ' CANCELLATION'
        : 'CDM ' . strtoupper(str_replace('-', ' ', $program_type));

    $sql = "INSERT INTO dbo.tmi_advisories (
                advisory_number, advisory_type,
                ctl_element, element_type, scope_facilities,
                effective_from, effective_until,
                subject, body_text,
                reason_code, reason_detail,
                status, is_proposed,
                source_type, source_id,
                created_by, created_by_name,
                org_code
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $advisory_number,
        $advisory_type,
        $program['ctl_element'] ?? null,
        $program['element_type'] ?? 'APT',
        resolve_scope_facilities($program),
        $program['start_utc'] ?? null,
        $program['end_utc'] ?? null,
        $subject,
        $advisory_text,
        $program['impacting_condition'] ?? null,
        $program['cause_text'] ?? null,
        'PUBLISHED',
        0,
        'GDT',
        $program['program_id'] ?? null,
        $created_by,
        $created_by_name,
        'vatcscc'
    ];

    $stmt = sqlsrv_query($conn_tmi, $sql, $params);
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
        return true;
    }
    return false;
}
