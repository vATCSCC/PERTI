<?php
/**
 * GDT Programs - Re-optimize API
 *
 * POST /api/gdt/programs/reoptimize.php
 *
 * Runs a rolling re-optimization cycle on an active GDP/AFP program.
 * This is the manual entry point; the daemon will call this same logic
 * on a 2-5 minute timer (Phase 3 daemon integration).
 *
 * Re-optimization cycle:
 *   1. Query current flights from ADL matching program scope
 *   2. Detect new popup flights (sp_TMI_DetectPopups)
 *   3. Run orchestrated re-optimization (sp_TMI_ReoptimizeProgram):
 *      a. Assign pending popups to open slots
 *      b. Compress: move delayed flights to earlier vacant slots
 *      c. Adjust reserves: convert RESERVED -> REGULAR as demand fills
 *      d. Update program metrics
 *      e. Log event
 *
 * Request body (JSON):
 * {
 *   "program_id": 1    // Required: active program to re-optimize
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Re-optimization complete",
 *   "data": {
 *     "program_id": 1,
 *     "cycle": 5,
 *     "popups_detected": 3,
 *     "popups_assigned": 2,
 *     "slots_compressed": 1,
 *     "delay_saved_min": 15,
 *     "reserves_converted": 4,
 *     "actions_taken": true,
 *     "updated_metrics": { ... }
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-03-05
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();
$conn_adl = gdt_get_conn_adl();

// ============================================================================
// Validate Program
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

$status = $program['status'] ?? '';
if ($status !== 'ACTIVE') {
    respond_json(400, [
        'status' => 'error',
        'message' => "Re-optimization only available for ACTIVE programs. Current status: {$status}"
    ]);
}

$program_type = $program['program_type'] ?? '';
if ($program_type === 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Re-optimization is not applicable to Ground Stop programs.'
    ]);
}

$ctl_element = $program['ctl_element'] ?? '';
$start_utc = $program['start_utc'] ?? null;
$end_utc = $program['end_utc'] ?? null;

// ============================================================================
// Step 1: Query Current Flights from ADL for Popup Detection
// ============================================================================

$where = [];
$params = [];

// GDP: filter by arrival airport and ETA window
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

// Exclude arrived flights
$where[] = "(phase IS NULL OR phase != 'arrived')";

// Apply saved scope filters from program's scope_json
if (!empty($program['scope_json'])) {
    $saved_scope = is_string($program['scope_json']) ? json_decode($program['scope_json'], true) : $program['scope_json'];
    if (is_array($saved_scope)) {
        // Origin center filter
        if (!empty($saved_scope['origin_centers'])) {
            $centers = split_codes($saved_scope['origin_centers']);
            $artcc_codes = [];
            $fir_like_patterns = [];
            $fir_wildcard = false;
            foreach ($centers as $c) {
                if (strpos($c, 'FIR:') === 0) {
                    $prefix = substr($c, 4);
                    if ($prefix === '' || $prefix === '*') { $fir_wildcard = true; }
                    else { $fir_like_patterns[] = $prefix; }
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
        }

        // Origin airport filter
        if (!empty($saved_scope['origin_airports'])) {
            $airports = split_codes($saved_scope['origin_airports']);
            if (count($airports) > 0) {
                $placeholders = implode(',', array_fill(0, count($airports), '?'));
                $where[] = "fp_dept_icao IN ({$placeholders})";
                foreach ($airports as $a) { $params[] = $a; }
            }
        }

        // Carrier filter
        if (!empty($saved_scope['carriers'])) {
            $carriers = split_codes($saved_scope['carriers']);
            if (count($carriers) > 0) {
                $placeholders = implode(',', array_fill(0, count($carriers), '?'));
                $where[] = "major_carrier IN ({$placeholders})";
                foreach ($carriers as $c) { $params[] = $c; }
            }
        }

        // Aircraft type filter
        $aircraft_type = isset($saved_scope['aircraft_type']) ? strtoupper(trim($saved_scope['aircraft_type'])) : 'ALL';
        if ($aircraft_type === 'JET') {
            $where[] = "UPPER(ISNULL(ac_cat,'')) = 'JET'";
        } elseif ($aircraft_type === 'PROP') {
            $where[] = "UPPER(ISNULL(ac_cat,'')) = 'PROP'";
        }
    }
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

$flights_sql = "
    SELECT
        flight_uid,
        callsign,
        major_carrier AS carrier,
        fp_dept_icao AS dep_airport,
        fp_dest_icao AS arr_airport,
        fp_dept_artcc AS dep_center,
        fp_dest_artcc AS arr_center,
        COALESCE(etd_runway_utc, etd_utc, std_utc) AS etd_utc,
        COALESCE(eta_runway_utc, eta_utc) AS eta_utc,
        ac_cat AS aircraft_type,
        phase AS flight_status,
        COALESCE(dist_to_dest_nm, gcd_nm) AS dist_to_dest_nm
    FROM dbo.vw_adl_flights
    {$where_sql}
";

$flights_result = fetch_all($conn_adl, $flights_sql, $params);

if (!$flights_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to query flights from ADL',
        'errors' => $flights_result['error']
    ]);
}

$adl_flights = $flights_result['data'];

// ============================================================================
// Step 2: Detect New Popup Flights
// ============================================================================

$popups_detected = 0;

if (count($adl_flights) > 0) {
    // Build temp table matching FlightListType (14 columns)
    $temp_sql = "
        CREATE TABLE #FlightList (
            flight_uid BIGINT,
            callsign NVARCHAR(12),
            eta_utc DATETIME2(0),
            etd_utc DATETIME2(0),
            dep_airport NVARCHAR(4),
            arr_airport NVARCHAR(4),
            dep_center NVARCHAR(4),
            arr_center NVARCHAR(4),
            carrier NVARCHAR(8),
            aircraft_type NVARCHAR(8),
            flight_status NVARCHAR(16),
            is_exempt BIT,
            exempt_reason NVARCHAR(32),
            dist_to_dest_nm FLOAT
        );
    ";

    $temp_stmt = sqlsrv_query($conn_tmi, $temp_sql);
    if ($temp_stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to create temp table',
            'errors' => sqlsrv_errors()
        ]);
    }
    sqlsrv_free_stmt($temp_stmt);

    // Batch insert flights
    $batch_size = 50;
    $cols = 'flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, arr_center, carrier, aircraft_type, flight_status, is_exempt, exempt_reason, dist_to_dest_nm';
    $chunks = array_chunk($adl_flights, $batch_size);

    foreach ($chunks as $batch) {
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
            $batch_params[] = $f['arr_center'] ?? null;
            $batch_params[] = $f['carrier'];
            $batch_params[] = $f['aircraft_type'];
            $batch_params[] = $f['flight_status'] ?? null;
            $batch_params[] = 0;     // is_exempt — popups aren't pre-exempt
            $batch_params[] = null;  // exempt_reason
            $batch_params[] = $f['dist_to_dest_nm'] ?? null;
        }
        $ins_sql = "INSERT INTO #FlightList ({$cols}) VALUES " . implode(', ', $value_rows);
        $ins_stmt = sqlsrv_query($conn_tmi, $ins_sql, $batch_params);
        if ($ins_stmt === false) {
            $err = sqlsrv_errors();
            sqlsrv_query($conn_tmi, "IF OBJECT_ID('tempdb..#FlightList') IS NOT NULL DROP TABLE #FlightList");
            respond_json(500, [
                'status' => 'error',
                'message' => 'Failed to batch-insert flights for popup detection',
                'errors' => $err
            ]);
        }
        sqlsrv_free_stmt($ins_stmt);
    }

    // Call sp_TMI_DetectPopups with the flight list
    $detect_sql = "
        DECLARE @popup_count INT;

        DECLARE @flights dbo.FlightListType;
        INSERT INTO @flights SELECT * FROM #FlightList;

        EXEC dbo.sp_TMI_DetectPopups
            @program_id = ?,
            @flights = @flights,
            @popup_count = @popup_count OUTPUT;

        SELECT @popup_count AS popup_count;

        DROP TABLE #FlightList;
    ";

    $detect_stmt = sqlsrv_query($conn_tmi, $detect_sql, [$program_id]);

    if ($detect_stmt === false) {
        $err = sqlsrv_errors();
        sqlsrv_query($conn_tmi, "IF OBJECT_ID('tempdb..#FlightList') IS NOT NULL DROP TABLE #FlightList");
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to detect popup flights',
            'errors' => $err
        ]);
    }

    $detect_row = sqlsrv_fetch_array($detect_stmt, SQLSRV_FETCH_ASSOC);
    if ($detect_row) {
        $popups_detected = (int)($detect_row['popup_count'] ?? 0);
    }
    sqlsrv_free_stmt($detect_stmt);
}

// ============================================================================
// Step 2.5: Populate filing_time_utc for flights missing it (cross-DB bridge)
// filing_time_utc = adl_flight_core.first_seen_utc (best proxy on VATSIM)
// ============================================================================

$missing_ft = fetch_all($conn_tmi,
    "SELECT control_id, flight_uid FROM dbo.tmi_flight_control
     WHERE program_id = ? AND filing_time_utc IS NULL AND flight_uid IS NOT NULL",
    [$program_id]
);

if ($missing_ft['success'] && count($missing_ft['data']) > 0) {
    $ft_uids = array_column($missing_ft['data'], 'flight_uid');
    $ft_control_map = [];
    foreach ($missing_ft['data'] as $row) {
        $ft_control_map[$row['flight_uid']] = $row['control_id'];
    }

    foreach (array_chunk($ft_uids, 100) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $adl_ft = fetch_all($conn_adl,
            "SELECT flight_uid, first_seen_utc FROM dbo.adl_flight_core WHERE flight_uid IN ({$placeholders})",
            $chunk
        );
        if ($adl_ft['success']) {
            foreach ($adl_ft['data'] as $r) {
                if ($r['first_seen_utc'] !== null && isset($ft_control_map[$r['flight_uid']])) {
                    execute_query($conn_tmi,
                        "UPDATE dbo.tmi_flight_control SET filing_time_utc = ? WHERE control_id = ?",
                        [$r['first_seen_utc'], $ft_control_map[$r['flight_uid']]]
                    );
                }
            }
        }
    }
}

// ============================================================================
// Step 3: Run Re-optimization Orchestrator
// ============================================================================

$triggered_by = $auth_cid ?: 'MANUAL';

$reopt_sql = "
    DECLARE @popups_assigned INT, @slots_compressed INT, @delay_saved_min INT,
            @reserves_converted INT, @actions_taken BIT;
    EXEC dbo.sp_TMI_ReoptimizeProgram
        @program_id = ?,
        @triggered_by = ?,
        @popups_assigned = @popups_assigned OUTPUT,
        @slots_compressed = @slots_compressed OUTPUT,
        @delay_saved_min = @delay_saved_min OUTPUT,
        @reserves_converted = @reserves_converted OUTPUT,
        @actions_taken = @actions_taken OUTPUT;
    SELECT
        @popups_assigned AS popups_assigned,
        @slots_compressed AS slots_compressed,
        @delay_saved_min AS delay_saved_min,
        @reserves_converted AS reserves_converted,
        @actions_taken AS actions_taken;
";

$reopt_stmt = sqlsrv_query($conn_tmi, $reopt_sql, [$program_id, $triggered_by]);

if ($reopt_stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to run re-optimization',
        'errors' => filter_sqlsrv_errors()
    ]);
}

$reopt_row = sqlsrv_fetch_array($reopt_stmt, SQLSRV_FETCH_ASSOC);
$popups_assigned = 0;
$slots_compressed = 0;
$delay_saved_min = 0;
$reserves_converted = 0;
$actions_taken = false;

if ($reopt_row) {
    $popups_assigned = (int)($reopt_row['popups_assigned'] ?? 0);
    $slots_compressed = (int)($reopt_row['slots_compressed'] ?? 0);
    $delay_saved_min = (int)($reopt_row['delay_saved_min'] ?? 0);
    $reserves_converted = (int)($reopt_row['reserves_converted'] ?? 0);
    $actions_taken = (bool)($reopt_row['actions_taken'] ?? 0);
}
sqlsrv_free_stmt($reopt_stmt);

// Refresh program metrics
$program = get_program($conn_tmi, $program_id);

// ============================================================================
// Response
// ============================================================================

$message_parts = [];
if ($popups_detected > 0) $message_parts[] = "{$popups_detected} popups detected";
if ($popups_assigned > 0) $message_parts[] = "{$popups_assigned} popups assigned";
if ($slots_compressed > 0) $message_parts[] = "{$slots_compressed} slots compressed (-{$delay_saved_min}min)";
if ($reserves_converted > 0) $message_parts[] = "{$reserves_converted} reserves released";

$message = $actions_taken
    ? 'Re-optimization complete: ' . implode(', ', $message_parts)
    : 'Re-optimization complete: no actions needed';

// Log to TMI unified log
log_tmi_action($conn_tmi, [
    'action_category' => 'PROGRAM',
    'action_type'     => 'REOPTIMIZE',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => 'GDP reoptimized: ' . ($program['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element' => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], null, [
    'popups_detected'    => $popups_detected,
    'popups_assigned'    => $popups_assigned,
    'slots_compressed'   => $slots_compressed,
    'delay_saved_min'    => $delay_saved_min,
    'reserves_converted' => $reserves_converted,
    'actions_taken'      => $actions_taken,
], [
    'program_id' => $program_id,
]);

respond_json(200, [
    'status' => 'ok',
    'message' => $message,
    'data' => [
        'program_id' => $program_id,
        'cycle' => (int)($program['reopt_cycle'] ?? 0),
        'popups_detected' => $popups_detected,
        'popups_assigned' => $popups_assigned,
        'slots_compressed' => $slots_compressed,
        'delay_saved_min' => $delay_saved_min,
        'reserves_converted' => $reserves_converted,
        'actions_taken' => $actions_taken,
        'updated_metrics' => [
            'avg_delay_min' => $program['avg_delay_min'] ?? null,
            'max_delay_min' => $program['max_delay_min'] ?? null,
            'total_delay_min' => $program['total_delay_min'] ?? null,
            'total_flights' => $program['total_flights'] ?? null,
            'controlled_flights' => $program['controlled_flights'] ?? null,
            'exempt_flights' => $program['exempt_flights'] ?? null,
            'last_compression_utc' => datetime_to_iso($program['last_compression_utc'] ?? null),
            'last_reopt_utc' => datetime_to_iso($program['last_reopt_utc'] ?? null)
        ]
    ]
]);
