<?php
/**
 * GDT Programs - Simulate API
 * 
 * POST /api/gdt/programs/simulate.php
 * 
 * Generates slots and runs FPFS+RBD (First-Planned-First-Served with
 * Ration-By-Distance tiebreaker) algorithm to assign flights to slots.
 * This is the core modeling step before activation.
 *
 * For GDP programs: Generates slots, queries matching flights from ADL,
 * and runs sp_TMI_AssignFlightsFPFS.
 * 
 * For GS programs: Queries matching flights and runs sp_TMI_ApplyGroundStop.
 * 
 * Request body (JSON):
 * {
 *   "program_id": 1,                    // Required: program to simulate
 *   "dry_run": false,                   // Optional: if true, runs simulation without persisting
 *   "what_if_rate": null,               // Optional: override program rate for what-if (dry_run only)
 *   "what_if_end_utc": null,            // Optional: override end time for what-if (dry_run only)
 *   "what_if_delay_cap": null,          // Optional: override delay cap for what-if (dry_run only)
 *   "scope": {                          // Optional: override scope filters
 *     "origin_centers": ["ZNY", "ZDC"], // Filter by departure ARTCC
 *     "origin_airports": ["KJFK"],      // Filter by departure airport
 *     "carriers": ["AAL", "DAL"],       // Filter by carrier
 *     "aircraft_type": "ALL",           // ALL, JET, PROP
 *     "distance_nm": 500                // Filter by distance from element
 *   },
 *   "exemptions": {                     // Optional: exemption rules
 *     "airborne": true,                 // Exempt airborne flights
 *     "departing_within_min": 30,       // Exempt flights departing soon
 *     "origins": ["KLGA"],              // Exempt specific origins
 *     "callsigns": ["AAL100"]           // Exempt specific flights
 *   }
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Simulation complete",
 *   "data": {
 *     "program_id": 1,
 *     "slot_count": 60,
 *     "assigned_count": 45,
 *     "exempt_count": 5,
 *     "summary": { ... delay metrics ... },
 *     "flights": [ ... flight assignments ... ],
 *     "slots": [ ... slot allocation ... ]
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
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
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();
$conn_adl = gdt_get_conn_adl();

// ============================================================================
// Validate Program ID & Dry-Run Flag
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$dry_run = isset($payload['dry_run']) && $payload['dry_run'];

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

// Normal mode: only PROPOSED/MODELING. Dry-run: also allow ACTIVE programs.
$status = $program['status'] ?? '';
$allowed_statuses = $dry_run
    ? array_merge(PERTI_MODELING_STATUSES, ['ACTIVE'])
    : PERTI_MODELING_STATUSES;

if (!in_array($status, $allowed_statuses)) {
    $msg = $dry_run
        ? "Cannot simulate program in status: {$status}. Must be " . implode(', ', $allowed_statuses) . "."
        : "Cannot simulate program in status: {$status}. Must be " . implode(' or ', PERTI_MODELING_STATUSES) . ".";
    respond_json(400, [
        'status' => 'error',
        'message' => $msg
    ]);
}

// Apply what-if overrides (dry_run only) — these affect the simulation
// but are never persisted to the database
$what_if_overrides = [];
if ($dry_run) {
    if (isset($payload['what_if_rate']) && (int)$payload['what_if_rate'] > 0) {
        $what_if_overrides['program_rate'] = (int)$payload['what_if_rate'];
    }
    if (isset($payload['what_if_end_utc']) && trim($payload['what_if_end_utc']) !== '') {
        $what_if_overrides['end_utc'] = parse_utc_datetime($payload['what_if_end_utc']);
    }
    if (isset($payload['what_if_delay_cap']) && (int)$payload['what_if_delay_cap'] > 0) {
        $what_if_overrides['delay_limit_min'] = (int)$payload['what_if_delay_cap'];
    }
}

$program_type = $program['program_type'] ?? 'GS';
$ctl_element = $program['ctl_element'] ?? '';
$start_utc = $program['start_utc'] ?? null;
$end_utc = $program['end_utc'] ?? null;

// ============================================================================
// Parse Scope and Exemption Filters
// ============================================================================

$scope = isset($payload['scope']) ? $payload['scope'] : [];
$exemptions = isset($payload['exemptions']) ? $payload['exemptions'] : [];

// Extract scope values (can override program's scope_json)
$origin_centers = isset($scope['origin_centers']) ? split_codes($scope['origin_centers']) : [];
$origin_airports = isset($scope['origin_airports']) ? split_codes($scope['origin_airports']) : [];
$carriers = isset($scope['carriers']) ? split_codes($scope['carriers']) : [];
$aircraft_type = isset($scope['aircraft_type']) ? strtoupper(trim($scope['aircraft_type'])) : 'ALL';
$distance_nm = isset($scope['distance_nm']) ? (int)$scope['distance_nm'] : 0;

// If no scope provided, try to parse from program's scope_json
if (empty($origin_centers) && empty($origin_airports) && !empty($program['scope_json'])) {
    $saved_scope = is_string($program['scope_json']) ? json_decode($program['scope_json'], true) : $program['scope_json'];
    if (is_array($saved_scope)) {
        if (isset($saved_scope['origin_centers'])) $origin_centers = split_codes($saved_scope['origin_centers']);
        if (isset($saved_scope['origin_airports'])) $origin_airports = split_codes($saved_scope['origin_airports']);
        if (isset($saved_scope['carriers'])) $carriers = split_codes($saved_scope['carriers']);
        if (isset($saved_scope['aircraft_type'])) $aircraft_type = strtoupper(trim($saved_scope['aircraft_type']));
        if (isset($saved_scope['distance_nm'])) $distance_nm = (int)$saved_scope['distance_nm'];
    }
}

// Fall back to program's flt_incl_* columns if not already set from scope
if (empty($carriers) && !empty($program['flt_incl_carrier'])) {
    $carriers = split_codes($program['flt_incl_carrier']);
}
if ($aircraft_type === 'ALL' && !empty($program['flt_incl_type']) && strtoupper($program['flt_incl_type']) !== 'ALL') {
    $aircraft_type = strtoupper(trim($program['flt_incl_type']));
}

// Exemption rules
$exempt_airborne = isset($exemptions['airborne']) ? (bool)$exemptions['airborne'] : true;
$exempt_departing_within_min = isset($exemptions['departing_within_min']) ? (int)$exemptions['departing_within_min'] : 0;
$exempt_origins = isset($exemptions['origins']) ? split_codes($exemptions['origins']) : [];
$exempt_callsigns = isset($exemptions['callsigns']) ? split_codes($exemptions['callsigns']) : [];

// ============================================================================
// Dry-Run Transaction: begin transaction so all DB changes can be rolled back
// ============================================================================

if ($dry_run) {
    // Apply what-if overrides to program record temporarily via UPDATE inside transaction
    sqlsrv_begin_transaction($conn_tmi);

    if (!empty($what_if_overrides)) {
        $override_sets = [];
        $override_params = [];
        foreach ($what_if_overrides as $col => $val) {
            $override_sets[] = "{$col} = ?";
            $override_params[] = $val;
        }
        $override_params[] = $program_id;
        $override_sql = "UPDATE dbo.tmi_programs SET " . implode(', ', $override_sets) . " WHERE program_id = ?";
        $override_stmt = sqlsrv_query($conn_tmi, $override_sql, $override_params);
        if ($override_stmt !== false) {
            sqlsrv_free_stmt($override_stmt);
        }
    }

    // Re-read program with overrides applied
    $program = get_program($conn_tmi, $program_id);
}

// Use potentially-overridden end_utc for flight query
$end_utc = $program['end_utc'] ?? null;

// ============================================================================
// Step 1: Generate Slots (GDP/AFP only)
// ============================================================================

$slot_count = 0;

if ($program_type !== 'GS') {
    $slot_sql = "
        DECLARE @slot_count INT;
        EXEC dbo.sp_TMI_GenerateSlots @program_id = ?, @slot_count = @slot_count OUTPUT;
        SELECT @slot_count AS slot_count;
    ";
    
    $slot_stmt = sqlsrv_query($conn_tmi, $slot_sql, [$program_id]);
    
    if ($slot_stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to generate slots',
            'errors' => sqlsrv_errors()
        ]);
    }
    
    $slot_row = sqlsrv_fetch_array($slot_stmt, SQLSRV_FETCH_ASSOC);
    if ($slot_row && isset($slot_row['slot_count'])) {
        $slot_count = (int)$slot_row['slot_count'];
    }
    sqlsrv_free_stmt($slot_stmt);
}

// ============================================================================
// Step 2: Query Matching Flights from ADL
// ============================================================================

$where = [];
$params = [];

// For GDP: filter by arrival airport and ETA window
// For GS: filter by arrival airport and ETD window (GS affects departures)
if ($program_type === 'GS') {
    // GS filters by arrival airport
    $where[] = "fp_dest_icao = ?";
    $params[] = $ctl_element;

    // GS holds flights not yet departed — use best available departure time
    // etd_runway_utc is often NULL for prefiled flights, fall back to etd_utc or std_utc
    if ($start_utc) {
        $where[] = "COALESCE(etd_runway_utc, etd_utc, std_utc) >= ?";
        $params[] = datetime_to_iso($start_utc);
    }
    if ($end_utc) {
        $where[] = "COALESCE(etd_runway_utc, etd_utc, std_utc) <= ?";
        $params[] = datetime_to_iso($end_utc);
    }

    // GS only affects flights not yet departed
    $where[] = "(phase IS NULL OR phase IN ('prefile', 'taxiing', 'scheduled'))";
} else {
    // GDP filters by arrival airport and ETA window
    $where[] = "fp_dest_icao = ?";
    $params[] = $ctl_element;

    // Use best available arrival time
    if ($start_utc) {
        $where[] = "COALESCE(eta_runway_utc, eta_utc) >= ?";
        $params[] = datetime_to_iso($start_utc);
    }
    if ($end_utc) {
        $where[] = "COALESCE(eta_runway_utc, eta_utc) <= ?";
        $params[] = datetime_to_iso($end_utc);
    }
}

// Origin center filter — handle both ARTCC codes and FIR: ICAO-prefix patterns
// FIR: prefix tokens come from fir-integration.js for international airports
$artcc_codes = [];
$fir_like_patterns = [];
$fir_wildcard = false;
foreach ($origin_centers as $c) {
    if (strpos($c, 'FIR:') === 0) {
        $prefix = substr($c, 4);
        if ($prefix === '' || $prefix === '*') {
            $fir_wildcard = true; // FIR: alone = all origins, skip filter
        } else {
            $fir_like_patterns[] = $prefix;
            // Also expand to known FIR codes for fp_dept_artcc matching
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

// For GDP: exclude already arrived flights (GS has its own phase filter above)
if ($program_type !== 'GS') {
    $where[] = "(phase IS NULL OR phase != 'arrived')";
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Query flights from ADL
$flights_sql = "
    SELECT
        flight_uid,
        flight_key,
        callsign,
        major_carrier,
        fp_dept_icao AS dep_airport,
        fp_dest_icao AS arr_airport,
        fp_dept_artcc AS dep_center,
        fp_dest_artcc AS arr_center,
        COALESCE(etd_runway_utc, etd_utc, std_utc) AS etd_utc,
        COALESCE(eta_runway_utc, eta_utc) AS eta_utc,
        -- Include epoch values for JavaScript compatibility
        DATEDIFF(SECOND, '1970-01-01', COALESCE(etd_runway_utc, etd_utc, std_utc)) AS etd_epoch,
        DATEDIFF(SECOND, '1970-01-01', COALESCE(eta_runway_utc, eta_utc)) AS eta_epoch,
        ete_minutes,
        ac_cat AS aircraft_type,
        phase,
        gs_flag,
        -- Distance to destination for RBD tiebreaker (FPFS algorithm)
        -- Airborne: actual remaining distance; pre-departure: GCD from origin
        COALESCE(dist_to_dest_nm, gcd_nm) AS dist_to_dest_nm
    FROM dbo.vw_adl_flights
    {$where_sql}
    ORDER BY eta_runway_utc ASC, flight_uid ASC
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
// Step 3: Apply Exemption Rules
// ============================================================================

$flights_for_sp = [];
$now_utc = new DateTime('now', new DateTimeZone('UTC'));

foreach ($adl_flights as $flight) {
    $is_exempt = false;
    $exempt_reason = null;
    
    // Check airborne exemption
    if ($exempt_airborne) {
        $phase = strtolower($flight['phase'] ?? '');
        if (in_array($phase, ['departed', 'enroute', 'descending'])) {
            $is_exempt = true;
            $exempt_reason = 'AIRBORNE';
        }
    }
    
    // Check departing-within exemption
    if (!$is_exempt && $exempt_departing_within_min > 0 && !empty($flight['etd_utc'])) {
        $etd = new DateTime($flight['etd_utc'], new DateTimeZone('UTC'));
        $diff_min = ($etd->getTimestamp() - $now_utc->getTimestamp()) / 60;
        if ($diff_min <= $exempt_departing_within_min && $diff_min >= 0) {
            $is_exempt = true;
            $exempt_reason = 'DEPARTING_SOON';
        }
    }
    
    // Check origin exemption
    if (!$is_exempt && count($exempt_origins) > 0) {
        if (in_array(strtoupper($flight['dep_airport'] ?? ''), $exempt_origins)) {
            $is_exempt = true;
            $exempt_reason = 'EXEMPT_ORIGIN';
        }
    }
    
    // Check callsign exemption
    if (!$is_exempt && count($exempt_callsigns) > 0) {
        if (in_array(strtoupper($flight['callsign'] ?? ''), $exempt_callsigns)) {
            $is_exempt = true;
            $exempt_reason = 'EXEMPT_FLIGHT';
        }
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

// ============================================================================
// Step 4: Call Assignment Stored Procedure
// ============================================================================

$assigned_count = 0;
$exempt_count = 0;

if (count($flights_for_sp) > 0) {
    // Build table-valued parameter data
    // Note: For PHP + SQL Server, we need to build an INSERT INTO temp table approach
    // since sqlsrv doesn't directly support table-valued parameters easily
    
    // Create temp table and insert flights
    // Must match dbo.FlightListType column order exactly
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
    
    // Insert flights in batches of 50 (700 params per batch, well under sqlsrv limits)
    $batch_size = 50;
    $cols = 'flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, arr_center, carrier, aircraft_type, flight_status, is_exempt, exempt_reason, dist_to_dest_nm';
    $chunks = array_chunk($flights_for_sp, $batch_size);

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
        if ($ins_stmt === false) {
            error_log("Failed to batch-insert flights: " . json_encode(sqlsrv_errors()));
        } else {
            sqlsrv_free_stmt($ins_stmt);
        }
    }
    
    // Call appropriate stored procedure based on program type
    if ($program_type === 'GS') {
        // Direct SQL for GS — the SP has an ETA BETWEEN filter that drops flights
        // with NULL eta_utc (common for prefiled flights found via ETD-based query).
        // We replicate the SP logic here as separate queries to avoid parameter binding issues.
        $gs_end = datetime_to_iso($end_utc);
        // GS control times (EDCTs) are issued at GS end + 1 minute
        // e.g., GS period 1200-1259Z → EDCTs at 1300Z
        $gs_release = datetime_to_iso(date_create($gs_end)->modify('+1 minute')->format('Y-m-d\TH:i:s'));

        // Step 4a: Clear existing assignments
        $del_stmt = sqlsrv_query($conn_tmi,
            "DELETE FROM dbo.tmi_flight_control WHERE program_id = ?",
            [$program_id]
        );
        if ($del_stmt !== false) sqlsrv_free_stmt($del_stmt);

        // Step 4b: Insert ground-stopped flights with CTD/CTA calculations
        $ins_sql = "
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id,
                ctl_elem, ctl_type,
                ctl_exempt, ctl_exempt_reason,
                gs_held, gs_release_utc,
                orig_eta_utc, orig_etd_utc,
                ctd_utc, cta_utc,
                program_delay_min,
                dep_airport, arr_airport, dep_center, arr_center,
                flight_status_at_ctl, control_assigned_utc
            )
            SELECT
                f.flight_uid,
                f.callsign,
                ?,
                ?,
                'GS',
                f.is_exempt,
                f.exempt_reason,
                CASE WHEN f.is_exempt = 1 THEN 0 ELSE 1 END,
                ?,
                f.eta_utc,
                f.etd_utc,
                CASE
                    WHEN f.is_exempt = 1 THEN f.etd_utc
                    WHEN f.etd_utc >= ? THEN f.etd_utc
                    ELSE ?
                END,
                CASE
                    WHEN f.is_exempt = 1 THEN f.eta_utc
                    WHEN f.etd_utc >= ? THEN f.eta_utc
                    WHEN f.eta_utc IS NOT NULL AND f.etd_utc IS NOT NULL
                        THEN DATEADD(MINUTE, DATEDIFF(MINUTE, f.etd_utc, f.eta_utc), ?)
                    ELSE NULL
                END,
                CASE
                    WHEN f.is_exempt = 1 THEN 0
                    WHEN f.etd_utc >= ? THEN 0
                    WHEN f.etd_utc IS NOT NULL THEN DATEDIFF(MINUTE, f.etd_utc, ?)
                    ELSE 0
                END,
                f.dep_airport,
                f.arr_airport,
                f.dep_center,
                f.arr_center,
                f.flight_status,
                SYSUTCDATETIME()
            FROM #FlightList f
        ";
        $ins_params = [
            $program_id, $ctl_element, $gs_release,    // program_id, ctl_elem, gs_release_utc
            $gs_release, $gs_release,                  // CTD: etd >= gs_release, ELSE gs_release
            $gs_release, $gs_release,                  // CTA: etd >= gs_release, DATEADD(...gs_release)
            $gs_release, $gs_release,                  // delay: etd >= gs_release, DATEDIFF(etd, gs_release)
        ];
        $ins_stmt = sqlsrv_query($conn_tmi, $ins_sql, $ins_params);
        if ($ins_stmt === false) {
            $err = sqlsrv_errors();
            sqlsrv_query($conn_tmi, "IF OBJECT_ID('tempdb..#FlightList') IS NOT NULL DROP TABLE #FlightList");
            respond_json(500, [
                'status' => 'error',
                'message' => 'Failed to insert GS flight control records',
                'errors' => $err
            ]);
        }
        sqlsrv_free_stmt($ins_stmt);

        // Step 4c: Count results
        $cnt_result = fetch_one($conn_tmi,
            "SELECT ISNULL(SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END), 0) AS assigned_count, ISNULL(SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END), 0) AS exempt_count FROM dbo.tmi_flight_control WHERE program_id = ?",
            [$program_id]
        );
        if ($cnt_result['success'] && $cnt_result['data']) {
            $assigned_count = (int)($cnt_result['data']['assigned_count'] ?? 0);
            $exempt_count = (int)($cnt_result['data']['exempt_count'] ?? 0);
        }

        // Step 4d: Update program metrics
        execute_query($conn_tmi, "
            UPDATE dbo.tmi_programs
            SET total_flights = (SELECT COUNT(*) FROM dbo.tmi_flight_control WHERE program_id = ?),
                controlled_flights = (SELECT ISNULL(SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END), 0) FROM dbo.tmi_flight_control WHERE program_id = ?),
                exempt_flights = (SELECT ISNULL(SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END), 0) FROM dbo.tmi_flight_control WHERE program_id = ?),
                avg_delay_min = (SELECT ISNULL(AVG(CAST(program_delay_min AS DECIMAL(8,2))), 0) FROM dbo.tmi_flight_control WHERE program_id = ? AND ctl_exempt = 0),
                max_delay_min = (SELECT ISNULL(MAX(program_delay_min), 0) FROM dbo.tmi_flight_control WHERE program_id = ? AND ctl_exempt = 0),
                total_delay_min = (SELECT ISNULL(SUM(program_delay_min), 0) FROM dbo.tmi_flight_control WHERE program_id = ? AND ctl_exempt = 0),
                updated_at = SYSUTCDATETIME()
            WHERE program_id = ?
        ", [$program_id, $program_id, $program_id, $program_id, $program_id, $program_id, $program_id]);

        // Drop temp table
        $drop_stmt = sqlsrv_query($conn_tmi, "DROP TABLE #FlightList");
        if ($drop_stmt !== false) sqlsrv_free_stmt($drop_stmt);

    } else {
        // GDP/AFP path: use FPFS+RBD stored procedure for slot assignment
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
            // #FlightList is dropped inside exec_sql, but if it failed before that:
            sqlsrv_query($conn_tmi, "IF OBJECT_ID('tempdb..#FlightList') IS NOT NULL DROP TABLE #FlightList");
            respond_json(500, [
                'status' => 'error',
                'message' => 'Failed to execute assignment procedure',
                'errors' => $err
            ]);
        }

        $result_row = sqlsrv_fetch_array($exec_stmt, SQLSRV_FETCH_ASSOC);
        if ($result_row) {
            $assigned_count = (int)($result_row['assigned_count'] ?? 0);
            $exempt_count = (int)($result_row['exempt_count'] ?? 0);
        }
        sqlsrv_free_stmt($exec_stmt);

        // Note: sp_TMI_AssignFlightsFPFS already updates tmi_programs metrics
        // (excluding UNASSIGNED flights from delay averages via slot_id IS NOT NULL)

        // Populate filing_time_utc from ADL (cross-DB bridge for fairness metrics)
        // filing_time_utc = adl_flight_core.first_seen_utc (best proxy on VATSIM)
        if (!$dry_run) {
            $missing_ft = fetch_all($conn_tmi,
                "SELECT control_id, flight_uid FROM dbo.tmi_flight_control
                 WHERE program_id = ? AND filing_time_utc IS NULL AND flight_uid IS NOT NULL",
                [$program_id]
            );
            if ($missing_ft['success'] && count($missing_ft['data']) > 0) {
                $ft_uids = array_column($missing_ft['data'], 'flight_uid');
                $ft_map = [];
                foreach ($missing_ft['data'] as $row) {
                    $ft_map[$row['flight_uid']] = $row['control_id'];
                }
                foreach (array_chunk($ft_uids, 100) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $adl_ft = fetch_all($conn_adl,
                        "SELECT flight_uid, first_seen_utc FROM dbo.adl_flight_core WHERE flight_uid IN ({$ph})",
                        $chunk
                    );
                    if ($adl_ft['success']) {
                        foreach ($adl_ft['data'] as $r) {
                            if ($r['first_seen_utc'] !== null && isset($ft_map[$r['flight_uid']])) {
                                execute_query($conn_tmi,
                                    "UPDATE dbo.tmi_flight_control SET filing_time_utc = ? WHERE control_id = ?",
                                    [$r['first_seen_utc'], $ft_map[$r['flight_uid']]]
                                );
                            }
                        }
                    }
                }
            }
        }
    }
} // end if (count($flights_for_sp) > 0)

// ============================================================================
// Step 4b: Slot Bridging (successor programs only)
//
// When this program has a parent_program_id (GS-to-GDP transition or GDP revision),
// link slots between predecessor and successor via bridge columns so delay
// assignments are carried forward and the full program chain is traceable.
// ============================================================================

$parent_id = (int)($program['parent_program_id'] ?? 0);
$bridged_count = 0;
if ($parent_id > 0 && $program_type !== 'GS' && !$dry_run) {
    // Determine bridge reason from transition_type
    $transition_type = $program['transition_type'] ?? '';
    $bridge_reason = match($transition_type) {
        'GS_TO_GDP' => 'TRANSITION',
        'REVISION'  => 'REVISION',
        default     => 'BRIDGED',
    };

    // Get parent program's assigned slots keyed by flight_uid
    $parent_slots = fetch_all($conn_tmi, "
        SELECT slot_id, assigned_flight_uid
        FROM dbo.tmi_slots
        WHERE program_id = ?
          AND assigned_flight_uid IS NOT NULL
          AND slot_status = 'ASSIGNED'
    ", [$parent_id]);

    if ($parent_slots['success'] && !empty($parent_slots['data'])) {
        // Build map: flight_uid -> parent slot_id
        $parent_map = [];
        foreach ($parent_slots['data'] as $ps) {
            $parent_map[$ps['assigned_flight_uid']] = (int)$ps['slot_id'];
        }

        // Get this program's assigned slots
        $child_slots = fetch_all($conn_tmi, "
            SELECT slot_id, assigned_flight_uid
            FROM dbo.tmi_slots
            WHERE program_id = ?
              AND assigned_flight_uid IS NOT NULL
              AND slot_status = 'ASSIGNED'
        ", [$program_id]);

        if ($child_slots['success'] && !empty($child_slots['data'])) {
            $bridged_count = 0;
            foreach ($child_slots['data'] as $cs) {
                $fuid = $cs['assigned_flight_uid'];
                if (isset($parent_map[$fuid])) {
                    $parent_slot_id = $parent_map[$fuid];
                    $child_slot_id = (int)$cs['slot_id'];

                    // Link child slot back to parent slot
                    execute_query($conn_tmi,
                        "UPDATE dbo.tmi_slots SET bridge_from_slot_id = ?, bridge_reason = ? WHERE slot_id = ?",
                        [$parent_slot_id, $bridge_reason, $child_slot_id]
                    );

                    // Link parent slot forward to child slot
                    execute_query($conn_tmi,
                        "UPDATE dbo.tmi_slots SET bridge_to_slot_id = ?, bridge_reason = ? WHERE slot_id = ?",
                        [$child_slot_id, $bridge_reason, $parent_slot_id]
                    );

                    $bridged_count++;
                }
            }

            if ($bridged_count > 0) {
                // Log bridging event
                execute_query($conn_tmi,
                    "INSERT INTO dbo.tmi_events (entity_type, entity_id, program_id, event_type, event_detail, source_type, event_utc)
                     VALUES ('PROGRAM', ?, ?, 'SLOT_BRIDGING', ?, 'SYSTEM', SYSUTCDATETIME())",
                    [$program_id, $program_id, "Bridged {$bridged_count} slots from program #{$parent_id} ({$bridge_reason})"]
                );
            }
        }
    }
}

// ============================================================================
// Step 5: Fetch Results
// ============================================================================

// Refresh program data
$program = get_program($conn_tmi, $program_id);

// Get flight assignments with epoch values for JavaScript compatibility
$flight_control_result = fetch_all($conn_tmi, "
    SELECT *,
        DATEDIFF(SECOND, '1970-01-01', ctd_utc) AS ctd_epoch,
        DATEDIFF(SECOND, '1970-01-01', cta_utc) AS cta_epoch,
        DATEDIFF(SECOND, '1970-01-01', orig_etd_utc) AS orig_etd_epoch,
        DATEDIFF(SECOND, '1970-01-01', orig_eta_utc) AS orig_eta_epoch
    FROM dbo.tmi_flight_control
    WHERE program_id = ?
    ORDER BY cta_utc ASC, orig_eta_utc ASC
", [$program_id]);

// Get slot allocation (GDP only)
$slots = [];
if ($program_type !== 'GS') {
    $slots_result = fetch_all($conn_tmi, "
        SELECT * FROM dbo.tmi_slots
        WHERE program_id = ?
        ORDER BY slot_index ASC
    ", [$program_id]);
    $slots = $slots_result['success'] ? $slots_result['data'] : [];
}

// Build summary with enhanced analytics
$summary = [
    'total_flights' => count($flights_for_sp),
    'assigned_count' => $assigned_count,
    'exempt_count' => $exempt_count,
    'slot_count' => $slot_count,
    'avg_delay_min' => $program['avg_delay_min'] ?? null,
    'max_delay_min' => $program['max_delay_min'] ?? null,
    'total_delay_min' => $program['total_delay_min'] ?? null
];

// Enhanced analytical metrics (computed from flight control data)
$flights_data = $flight_control_result['success'] ? $flight_control_result['data'] : [];
$delays = [];
$delayed_count = 0;
$on_time_count = 0;
$time_bins = []; // For stack_peak calculation

foreach ($flights_data as $fc) {
    $delay_val = (int)($fc['program_delay_min'] ?? 0);
    if (($fc['ctl_exempt'] ?? 0) == 1) continue; // Skip exempt flights

    $delays[] = $delay_val;
    if ($delay_val > 0) $delayed_count++;
    if ($delay_val <= 5) $on_time_count++;

    // Track concurrent delays per 15-min bin for stack_peak
    $ctd = $fc['ctd_utc'] ?? null;
    if ($ctd && $delay_val > 0) {
        $ctdStr = ($ctd instanceof DateTimeInterface) ? $ctd->format('Y-m-d H:i') : substr((string)$ctd, 0, 16);
        // Round to 15-min bin
        $ts = strtotime(is_string($ctdStr) ? $ctdStr : '');
        if ($ts) {
            $bin = $ts - ($ts % 900); // 900s = 15 min
            $time_bins[$bin] = ($time_bins[$bin] ?? 0) + 1;
        }
    }
}

$total_non_exempt = count($delays);
$summary['affected_flights'] = $delayed_count;
$summary['slot_utilization'] = ($slot_count > 0) ? round($assigned_count / $slot_count * 100, 1) : null;
$summary['on_time_pct'] = ($total_non_exempt > 0) ? round($on_time_count / $total_non_exempt * 100, 1) : null;

// Delay variance (standard deviation)
if (count($delays) > 1) {
    $mean = array_sum($delays) / count($delays);
    $variance = array_sum(array_map(function($d) use ($mean) { return pow($d - $mean, 2); }, $delays)) / count($delays);
    $summary['delay_variance'] = round(sqrt($variance), 1);
} else {
    $summary['delay_variance'] = 0;
}

// 95th percentile delay
if (count($delays) > 0) {
    sort($delays);
    $p95_index = (int)ceil(count($delays) * 0.95) - 1;
    $p95_index = max(0, min($p95_index, count($delays) - 1));
    $summary['delay_p95'] = $delays[$p95_index];
} else {
    $summary['delay_p95'] = 0;
}

// Stack peak: max concurrent delayed flights in any single 15-min bin
$summary['stack_peak'] = !empty($time_bins) ? max($time_bins) : 0;

// Slot bridging count (flights carried forward from predecessor program)
if ($bridged_count > 0) {
    $summary['bridged_flights'] = $bridged_count;
    $summary['bridge_parent_id'] = $parent_id;
}

// ============================================================================
// Dry-Run Rollback: undo all DB changes made during simulation
// ============================================================================

if ($dry_run) {
    sqlsrv_rollback($conn_tmi);
}

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'message' => $dry_run ? 'What-if simulation complete (no changes persisted)' : 'Simulation complete',
    'data' => [
        'program_id' => $program_id,
        'program_type' => $program_type,
        'program_status' => $program['status'] ?? 'MODELING',
        'dry_run' => $dry_run,
        'what_if_overrides' => !empty($what_if_overrides) ? $what_if_overrides : null,
        'slot_count' => $slot_count,
        'assigned_count' => $assigned_count,
        'exempt_count' => $exempt_count,
        'summary' => $summary,
        'flights' => $flight_control_result['success'] ? $flight_control_result['data'] : [],
        'slots' => $slots
    ]
]);
