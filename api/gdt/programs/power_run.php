<?php
/**
 * GDT Programs - Power Run (Multi-Scenario Modeling) API
 *
 * POST /api/gdt/programs/power_run.php
 *
 * Iterates the simulation pipeline over a range of parameter values to produce
 * a comparison table. Supports sweeping over distance, data time, or rate.
 *
 * All scenarios run as dry_run (in-process, no persistent changes).
 *
 * Optimized for performance:
 * - In-process simulation (no HTTP overhead per scenario)
 * - Cached flight list for rate sweeps (flights don't change between scenarios)
 * - Single ADL query per unique scope
 *
 * Request body (JSON):
 * {
 *   "program_id": 1,
 *   "sweep_param": "distance|rate|end_time",
 *   "sweep_start": 100,         // Start value (nm for distance, flights/hr for rate, minutes offset for end_time)
 *   "sweep_end": 500,           // End value
 *   "sweep_step": 50,           // Step size
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "program_id": 1,
 *     "sweep_param": "distance",
 *     "scenarios": [
 *       { "param_value": 100, "summary": { ... } },
 *       { "param_value": 150, "summary": { ... } },
 *       ...
 *     ]
 *   }
 * }
 *
 * @version 2.0.0
 * @date 2026-03-29
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
require_once __DIR__ . '/../../../load/perti_constants.php';
$auth_cid = gdt_optional_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();
$conn_adl = gdt_get_conn_adl();

// ============================================================================
// Validate Input
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$sweep_param = strtolower(trim($payload['sweep_param'] ?? ''));
$sweep_start = isset($payload['sweep_start']) ? (int)$payload['sweep_start'] : 0;
$sweep_end = isset($payload['sweep_end']) ? (int)$payload['sweep_end'] : 0;
$sweep_step = isset($payload['sweep_step']) ? (int)$payload['sweep_step'] : 0;

if ($program_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'program_id is required']);
}

if (!in_array($sweep_param, ['distance', 'rate', 'end_time'])) {
    respond_json(400, ['status' => 'error', 'message' => 'sweep_param must be distance, rate, or end_time']);
}

if ($sweep_step <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'sweep_step must be > 0']);
}

if ($sweep_start > $sweep_end) {
    respond_json(400, ['status' => 'error', 'message' => 'sweep_start must be <= sweep_end']);
}

// Cap iterations to prevent abuse
$iterations = ($sweep_end - $sweep_start) / $sweep_step + 1;
if ($iterations > 20) {
    respond_json(400, ['status' => 'error', 'message' => 'Too many iterations (max 20). Increase sweep_step or narrow range.']);
}

// Validate program exists
$program = get_program($conn_tmi, $program_id);
if (!$program) {
    respond_json(404, ['status' => 'error', 'message' => 'Program not found']);
}

$program_type = $program['program_type'] ?? '';
if ($program_type === 'GS') {
    respond_json(400, ['status' => 'error', 'message' => 'Power Run not applicable to Ground Stop programs']);
}

// ============================================================================
// Parse Scope and Exemption Filters (from program record)
// ============================================================================

$ctl_element = $program['ctl_element'] ?? '';
$start_utc = $program['start_utc'] ?? null;
$end_utc = $program['end_utc'] ?? null;

$scope = [];
if (!empty($program['scope_json'])) {
    $scope = is_string($program['scope_json']) ? json_decode($program['scope_json'], true) : $program['scope_json'];
    if (!is_array($scope)) $scope = [];
}

$origin_centers = isset($scope['origin_centers']) ? split_codes($scope['origin_centers']) : [];
$origin_airports = isset($scope['origin_airports']) ? split_codes($scope['origin_airports']) : [];
$carriers = isset($scope['carriers']) ? split_codes($scope['carriers']) : [];
$aircraft_type = isset($scope['aircraft_type']) ? strtoupper(trim($scope['aircraft_type'])) : 'ALL';
$base_distance_nm = isset($scope['distance_nm']) ? (int)$scope['distance_nm'] : 0;

// Fall back to program's flt_incl_* columns
if (empty($carriers) && !empty($program['flt_incl_carrier'])) {
    $carriers = split_codes($program['flt_incl_carrier']);
}
if ($aircraft_type === 'ALL' && !empty($program['flt_incl_type']) && strtoupper($program['flt_incl_type']) !== 'ALL') {
    $aircraft_type = strtoupper(trim($program['flt_incl_type']));
}

// Exemption rules (from program's exemption settings)
$exempt_airborne = true;

// ============================================================================
// Query ADL Flights (cached for rate sweeps)
// ============================================================================

/**
 * Query matching flights from ADL for a given end_utc.
 */
function query_adl_flights($conn_adl, $ctl_element, $start_utc, $end_utc,
                           $origin_centers, $origin_airports, $carriers, $aircraft_type) {
    $where = [];
    $params = [];

    // GDP filters by arrival airport and ETA window
    $where[] = "fp_dest_icao = ?";
    $params[] = $ctl_element;

    if ($start_utc) {
        $where[] = "COALESCE(eta_runway_utc, eta_utc) >= ?";
        $params[] = datetime_to_iso($start_utc);
    }
    if ($end_utc) {
        $where[] = "COALESCE(eta_runway_utc, eta_utc) <= ?";
        $params[] = datetime_to_iso($end_utc);
    }

    // Origin center filter
    $artcc_codes = [];
    $fir_like_patterns = [];
    $fir_wildcard = false;
    foreach ($origin_centers as $c) {
        if (strpos($c, 'FIR:') === 0) {
            $prefix = substr($c, 4);
            if ($prefix === '' || $prefix === '*') {
                $fir_wildcard = true;
            } else {
                $fir_like_patterns[] = $prefix;
                $expanded = perti_expand_fir_pattern($c);
                foreach ($expanded as $code) { $artcc_codes[] = $code; }
            }
        } else {
            $artcc_codes[] = $c;
        }
    }
    if (!$fir_wildcard) {
        $origin_conditions = [];
        if (count($artcc_codes) > 0) {
            $placeholders = implode(',', array_fill(0, count($artcc_codes), '?'));
            $origin_conditions[] = "fp_dept_artcc IN ({$placeholders})";
            foreach ($artcc_codes as $c) { $params[] = $c; }
        }
        foreach ($fir_like_patterns as $prefix) {
            $origin_conditions[] = "fp_dept_icao LIKE ?";
            $params[] = $prefix . '%';
        }
        if (count($origin_conditions) > 0) {
            $where[] = "(" . implode(' OR ', $origin_conditions) . ")";
        }
    }

    // Origin airport filter
    if (count($origin_airports) > 0) {
        $placeholders = implode(',', array_fill(0, count($origin_airports), '?'));
        $where[] = "fp_dept_icao IN ({$placeholders})";
        foreach ($origin_airports as $a) { $params[] = $a; }
    }

    // Carrier filter
    if (count($carriers) > 0) {
        $placeholders = implode(',', array_fill(0, count($carriers), '?'));
        $where[] = "major_carrier IN ({$placeholders})";
        foreach ($carriers as $c) { $params[] = $c; }
    }

    // Aircraft type filter
    if ($aircraft_type === 'JET') {
        $where[] = "UPPER(ISNULL(ac_cat,'')) = 'JET'";
    } elseif ($aircraft_type === 'PROP') {
        $where[] = "UPPER(ISNULL(ac_cat,'')) = 'PROP'";
    }

    // Exclude arrived flights
    $where[] = "(phase IS NULL OR phase != 'arrived')";

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $flights_sql = "
        SELECT
            flight_uid, callsign, major_carrier,
            fp_dept_icao AS dep_airport, fp_dest_icao AS arr_airport,
            fp_dept_artcc AS dep_center, fp_dest_artcc AS arr_center,
            COALESCE(etd_runway_utc, etd_utc, std_utc) AS etd_utc,
            COALESCE(eta_runway_utc, eta_utc) AS eta_utc,
            ac_cat AS aircraft_type, phase,
            COALESCE(dist_to_dest_nm, gcd_nm) AS dist_to_dest_nm
        FROM dbo.vw_adl_flights
        {$where_sql}
        ORDER BY eta_runway_utc ASC, flight_uid ASC
    ";

    $result = fetch_all($conn_adl, $flights_sql, $params);
    return $result['success'] ? $result['data'] : [];
}

/**
 * Apply exemption rules to flight list. Returns flights_for_sp array.
 */
function apply_exemptions($adl_flights) {
    $flights_for_sp = [];
    $now_utc = new DateTime('now', new DateTimeZone('UTC'));

    foreach ($adl_flights as $flight) {
        $is_exempt = false;
        $exempt_reason = null;

        // Airborne exemption
        $phase = strtolower($flight['phase'] ?? '');
        if (in_array($phase, ['departed', 'enroute', 'descending'])) {
            $is_exempt = true;
            $exempt_reason = 'AIRBORNE';
        }

        $flights_for_sp[] = [
            'flight_uid' => $flight['flight_uid'],
            'callsign' => $flight['callsign'],
            'eta_utc' => $flight['eta_utc'],
            'etd_utc' => $flight['etd_utc'],
            'dep_airport' => $flight['dep_airport'],
            'arr_airport' => $flight['arr_airport'],
            'dep_center' => $flight['dep_center'],
            'arr_center' => $flight['arr_center'] ?? null,
            'carrier' => $flight['major_carrier'],
            'aircraft_type' => $flight['aircraft_type'],
            'flight_status' => $flight['phase'] ?? null,
            'is_exempt' => $is_exempt ? 1 : 0,
            'exempt_reason' => $exempt_reason,
            'dist_to_dest_nm' => $flight['dist_to_dest_nm'] ?? null
        ];
    }

    return $flights_for_sp;
}

/**
 * Run a single GDP simulation scenario in-process using a transaction + rollback.
 * Returns summary array or error string.
 */
function run_scenario($conn_tmi, $program_id, $flights_for_sp, $overrides = []) {
    // Begin transaction for dry-run rollback
    sqlsrv_begin_transaction($conn_tmi);

    // Apply overrides to program record temporarily
    if (!empty($overrides)) {
        $sets = [];
        $params = [];
        foreach ($overrides as $col => $val) {
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
        $params[] = $program_id;
        $sql = "UPDATE dbo.tmi_programs SET " . implode(', ', $sets) . " WHERE program_id = ?";
        $stmt = sqlsrv_query($conn_tmi, $sql, $params);
        if ($stmt !== false) sqlsrv_free_stmt($stmt);
    }

    // Step 1: Generate slots
    $slot_sql = "
        DECLARE @slot_count INT;
        EXEC dbo.sp_TMI_GenerateSlots @program_id = ?, @slot_count = @slot_count OUTPUT;
        SELECT @slot_count AS slot_count;
    ";
    $slot_stmt = sqlsrv_query($conn_tmi, $slot_sql, [$program_id]);
    if ($slot_stmt === false) {
        $err = sqlsrv_errors();
        sqlsrv_rollback($conn_tmi);
        return ['error' => 'Slot generation failed: ' . ($err[0]['message'] ?? 'unknown')];
    }
    $slot_row = sqlsrv_fetch_array($slot_stmt, SQLSRV_FETCH_ASSOC);
    $slot_count = ($slot_row && isset($slot_row['slot_count'])) ? (int)$slot_row['slot_count'] : 0;
    sqlsrv_free_stmt($slot_stmt);

    // Step 2: Insert flights into temp table
    $assigned_count = 0;
    $exempt_count = 0;

    if (count($flights_for_sp) > 0) {
        $temp_sql = "
            CREATE TABLE #FlightList (
                flight_uid BIGINT, callsign NVARCHAR(12),
                eta_utc DATETIME2(0), etd_utc DATETIME2(0),
                dep_airport NVARCHAR(4), arr_airport NVARCHAR(4),
                dep_center NVARCHAR(4), arr_center NVARCHAR(4),
                carrier NVARCHAR(8), aircraft_type NVARCHAR(8),
                flight_status NVARCHAR(16), is_exempt BIT,
                exempt_reason NVARCHAR(32), dist_to_dest_nm FLOAT
            );
        ";
        $temp_stmt = sqlsrv_query($conn_tmi, $temp_sql);
        if ($temp_stmt === false) {
            sqlsrv_rollback($conn_tmi);
            return ['error' => 'Failed to create temp table'];
        }
        sqlsrv_free_stmt($temp_stmt);

        // Batch insert flights
        $cols = 'flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, arr_center, carrier, aircraft_type, flight_status, is_exempt, exempt_reason, dist_to_dest_nm';
        foreach (array_chunk($flights_for_sp, 50) as $batch) {
            $value_rows = [];
            $batch_params = [];
            foreach ($batch as $f) {
                $value_rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $batch_params[] = $f['flight_uid'];
                $batch_params[] = $f['callsign'];
                $batch_params[] = $f['eta_utc'];
                $batch_params[] = $f['etd_utc'];
                $batch_params[] = $f['dep_airport'];
                $batch_params[] = $f['arr_airport'];
                $batch_params[] = $f['dep_center'];
                $batch_params[] = $f['arr_center'];
                $batch_params[] = $f['carrier'];
                $batch_params[] = $f['aircraft_type'];
                $batch_params[] = $f['flight_status'];
                $batch_params[] = $f['is_exempt'];
                $batch_params[] = $f['exempt_reason'];
                $batch_params[] = $f['dist_to_dest_nm'];
            }
            $ins_sql = "INSERT INTO #FlightList ({$cols}) VALUES " . implode(', ', $value_rows);
            $ins_stmt = sqlsrv_query($conn_tmi, $ins_sql, $batch_params);
            if ($ins_stmt !== false) sqlsrv_free_stmt($ins_stmt);
        }

        // Step 3: Run FPFS+RBD assignment
        $exec_sql = "
            DECLARE @assigned_count INT, @exempt_count INT;
            DECLARE @flights dbo.FlightListType;
            INSERT INTO @flights SELECT * FROM #FlightList;
            EXEC dbo.sp_TMI_AssignFlightsFPFS
                @program_id = ?,
                @flights = @flights,
                @assigned_count = @assigned_count OUTPUT,
                @exempt_count = @exempt_count OUTPUT;
            SELECT @assigned_count AS assigned_count, @exempt_count AS exempt_count;
            DROP TABLE #FlightList;
        ";
        $exec_stmt = sqlsrv_query($conn_tmi, $exec_sql, [$program_id]);
        if ($exec_stmt === false) {
            $err = sqlsrv_errors();
            sqlsrv_query($conn_tmi, "IF OBJECT_ID('tempdb..#FlightList') IS NOT NULL DROP TABLE #FlightList");
            sqlsrv_rollback($conn_tmi);
            return ['error' => 'Assignment failed: ' . ($err[0]['message'] ?? 'unknown')];
        }
        $result_row = sqlsrv_fetch_array($exec_stmt, SQLSRV_FETCH_ASSOC);
        if ($result_row) {
            $assigned_count = (int)($result_row['assigned_count'] ?? 0);
            $exempt_count = (int)($result_row['exempt_count'] ?? 0);
        }
        sqlsrv_free_stmt($exec_stmt);
    }

    // Step 4: Compute analytics from tmi_flight_control (before rollback)
    $analytics_sql = "
        SELECT
            COUNT(*) AS total_controlled,
            AVG(CAST(delay_minutes AS FLOAT)) AS avg_delay_min,
            MAX(delay_minutes) AS max_delay_min,
            SUM(delay_minutes) AS total_delay_min,
            SUM(CASE WHEN delay_minutes <= 0 THEN 1 ELSE 0 END) AS on_time_count
        FROM dbo.tmi_flight_control
        WHERE program_id = ? AND is_exempt = 0
    ";
    $analytics_stmt = sqlsrv_query($conn_tmi, $analytics_sql, [$program_id]);
    $analytics = null;
    if ($analytics_stmt !== false) {
        $analytics = sqlsrv_fetch_array($analytics_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($analytics_stmt);
    }

    // Compute P95 delay
    $p95_delay = null;
    $delay_p95_sql = "
        SELECT delay_minutes FROM (
            SELECT delay_minutes,
                   PERCENT_RANK() OVER (ORDER BY delay_minutes) AS pct
            FROM dbo.tmi_flight_control
            WHERE program_id = ? AND is_exempt = 0 AND delay_minutes IS NOT NULL
        ) t
        WHERE pct >= 0.95
        ORDER BY delay_minutes ASC
        OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY
    ";
    $p95_stmt = sqlsrv_query($conn_tmi, $delay_p95_sql, [$program_id]);
    if ($p95_stmt !== false) {
        $p95_row = sqlsrv_fetch_array($p95_stmt, SQLSRV_FETCH_ASSOC);
        if ($p95_row) {
            $p95_delay = (float)($p95_row['delay_minutes'] ?? 0);
        }
        sqlsrv_free_stmt($p95_stmt);
    }

    $total_controlled = ($analytics && isset($analytics['total_controlled'])) ? (int)$analytics['total_controlled'] : 0;
    $on_time_count = ($analytics && isset($analytics['on_time_count'])) ? (int)$analytics['on_time_count'] : 0;
    $on_time_pct = $total_controlled > 0 ? round(($on_time_count / $total_controlled) * 100, 1) : null;

    $summary = [
        'total_flights' => count($flights_for_sp),
        'assigned_count' => $assigned_count,
        'exempt_count' => $exempt_count,
        'slot_count' => $slot_count,
        'avg_delay_min' => ($analytics && $analytics['avg_delay_min'] !== null) ? round((float)$analytics['avg_delay_min'], 1) : null,
        'max_delay_min' => ($analytics && $analytics['max_delay_min'] !== null) ? (int)$analytics['max_delay_min'] : null,
        'total_delay_min' => ($analytics && $analytics['total_delay_min'] !== null) ? (int)$analytics['total_delay_min'] : null,
        'controlled_flights' => $total_controlled,
        'delay_p95' => $p95_delay,
        'on_time_pct' => $on_time_pct,
    ];

    // Rollback all changes
    sqlsrv_rollback($conn_tmi);

    return ['summary' => $summary, 'slot_count' => $slot_count,
            'assigned_count' => $assigned_count, 'exempt_count' => $exempt_count];
}

// ============================================================================
// Run Scenarios
// ============================================================================

$scenarios = [];

// For rate sweeps, cache the flight list (flights don't change between scenarios)
$cached_flights = null;
if ($sweep_param === 'rate') {
    $adl_flights = query_adl_flights($conn_adl, $ctl_element, $start_utc, $end_utc,
                                     $origin_centers, $origin_airports, $carriers, $aircraft_type);
    $cached_flights = apply_exemptions($adl_flights);
}

for ($val = $sweep_start; $val <= $sweep_end; $val += $sweep_step) {
    $overrides = [];
    $flights_for_scenario = $cached_flights;

    switch ($sweep_param) {
        case 'distance':
            // Distance sweep: need fresh flight query (scope changes)
            // Note: distance filtering is typically done at the ADL query level
            // For now, we query with the base scope and the SP handles the rest
            $adl_flights = query_adl_flights($conn_adl, $ctl_element, $start_utc, $end_utc,
                                             $origin_centers, $origin_airports, $carriers, $aircraft_type);
            // Filter by distance
            $adl_flights = array_filter($adl_flights, function($f) use ($val) {
                $dist = $f['dist_to_dest_nm'] ?? null;
                return $dist === null || $dist <= $val;
            });
            $adl_flights = array_values($adl_flights);
            $flights_for_scenario = apply_exemptions($adl_flights);
            break;

        case 'rate':
            // Rate sweep: use cached flights, override program_rate
            $overrides['program_rate'] = $val;
            break;

        case 'end_time':
            // End time sweep: need fresh flight query (ETA window changes)
            $end = $program['end_utc'];
            $endStr = ($end instanceof DateTimeInterface) ? $end->format('Y-m-d H:i:s') : (string)$end;
            $newEnd = date('Y-m-d H:i:s', strtotime($endStr) + ($val * 60));
            $overrides['end_utc'] = $newEnd;

            $adl_flights = query_adl_flights($conn_adl, $ctl_element, $start_utc, $newEnd,
                                             $origin_centers, $origin_airports, $carriers, $aircraft_type);
            $flights_for_scenario = apply_exemptions($adl_flights);
            break;
    }

    $result = run_scenario($conn_tmi, $program_id, $flights_for_scenario, $overrides);

    if (isset($result['error'])) {
        $scenarios[] = [
            'param_value' => $val,
            'param_label' => formatParamLabel($sweep_param, $val),
            'error' => $result['error'],
        ];
    } else {
        $scenarios[] = [
            'param_value' => $val,
            'param_label' => formatParamLabel($sweep_param, $val),
            'summary' => $result['summary'],
            'slot_count' => $result['slot_count'],
            'assigned_count' => $result['assigned_count'],
            'exempt_count' => $result['exempt_count'],
        ];
    }
}

// ============================================================================
// Response
// ============================================================================

// Log to TMI unified log
log_tmi_action($conn_tmi, [
    'action_category' => 'PROGRAM',
    'action_type'     => 'SIMULATE',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => 'GDP power run: ' . ($program['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element' => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], null, [
    'sweep_param'     => $sweep_param,
    'sweep_start'     => $sweep_start,
    'sweep_end'       => $sweep_end,
    'sweep_step'      => $sweep_step,
    'scenario_count'  => count($scenarios),
], [
    'program_id' => $program_id,
]);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Power Run complete: ' . count($scenarios) . ' scenarios evaluated',
    'data' => [
        'program_id' => $program_id,
        'sweep_param' => $sweep_param,
        'sweep_start' => $sweep_start,
        'sweep_end' => $sweep_end,
        'sweep_step' => $sweep_step,
        'scenarios' => $scenarios,
    ]
]);

function formatParamLabel($param, $val) {
    switch ($param) {
        case 'distance': return $val . ' nm';
        case 'rate': return $val . '/hr';
        case 'end_time': return '+' . $val . ' min';
        default: return (string)$val;
    }
}
