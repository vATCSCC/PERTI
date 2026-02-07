<?php
/**
 * GDT Programs - Simulate API
 * 
 * POST /api/gdt/programs/simulate.php
 * 
 * Generates slots and runs RBS (Ration By Schedule) algorithm to assign
 * flights to slots. This is the core modeling step before activation.
 * 
 * For GDP programs: Generates slots, queries matching flights from ADL,
 * and runs sp_TMI_AssignFlightsRBS.
 * 
 * For GS programs: Queries matching flights and runs sp_TMI_ApplyGroundStop.
 * 
 * Request body (JSON):
 * {
 *   "program_id": 1,                    // Required: program to simulate
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
// Validate Program ID
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

// Can only simulate PROPOSED or MODELING programs
$status = $program['status'] ?? '';
if (!in_array($status, ['PROPOSED', 'MODELING'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Cannot simulate program in status: {$status}. Must be PROPOSED or MODELING."
    ]);
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

// Exemption rules
$exempt_airborne = isset($exemptions['airborne']) ? (bool)$exemptions['airborne'] : true;
$exempt_departing_within_min = isset($exemptions['departing_within_min']) ? (int)$exemptions['departing_within_min'] : 0;
$exempt_origins = isset($exemptions['origins']) ? split_codes($exemptions['origins']) : [];
$exempt_callsigns = isset($exemptions['callsigns']) ? split_codes($exemptions['callsigns']) : [];

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
    
    // GS affects flights departing during the GS period
    if ($start_utc) {
        $where[] = "etd_runway_utc >= ?";
        $params[] = datetime_to_iso($start_utc);
    }
    if ($end_utc) {
        $where[] = "etd_runway_utc <= ?";
        $params[] = datetime_to_iso($end_utc);
    }
} else {
    // GDP filters by arrival airport and ETA window
    $where[] = "fp_dest_icao = ?";
    $params[] = $ctl_element;
    
    if ($start_utc) {
        $where[] = "eta_runway_utc >= ?";
        $params[] = datetime_to_iso($start_utc);
    }
    if ($end_utc) {
        $where[] = "eta_runway_utc <= ?";
        $params[] = datetime_to_iso($end_utc);
    }
}

// Origin center filter
if (count($origin_centers) > 0) {
    $placeholders = implode(',', array_fill(0, count($origin_centers), '?'));
    $where[] = "fp_dept_artcc IN ({$placeholders})";
    foreach ($origin_centers as $c) { $params[] = $c; }
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

// Exclude already arrived flights
$where[] = "(phase IS NULL OR phase != 'arrived')";

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
        etd_runway_utc AS etd_utc,
        eta_runway_utc AS eta_utc,
        -- Include epoch values for JavaScript compatibility
        DATEDIFF(SECOND, '1970-01-01', etd_runway_utc) AS etd_epoch,
        DATEDIFF(SECOND, '1970-01-01', eta_runway_utc) AS eta_epoch,
        ete_minutes,
        ac_cat AS aircraft_type,
        phase,
        gs_flag
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
        'exempt_reason' => $exempt_reason
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
            exempt_reason NVARCHAR(32)
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
    
    // Insert flights in batches
    foreach ($flights_for_sp as $f) {
        $ins_sql = "
            INSERT INTO #FlightList (flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, arr_center, carrier, aircraft_type, flight_status, is_exempt, exempt_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $ins_stmt = sqlsrv_query($conn_tmi, $ins_sql, [
            $f['flight_uid'],
            $f['callsign'],
            $f['eta_utc'],
            $f['etd_utc'],
            $f['dep_airport'],
            $f['arr_airport'],
            $f['dep_center'],
            $f['arr_center'],
            $f['carrier'],
            $f['aircraft_type'],
            $f['flight_status'],
            $f['is_exempt'],
            $f['exempt_reason']
        ]);
        if ($ins_stmt === false) {
            // Log but continue
            error_log("Failed to insert flight: " . json_encode(sqlsrv_errors()));
        } else {
            sqlsrv_free_stmt($ins_stmt);
        }
    }
    
    // Call appropriate stored procedure based on program type
    if ($program_type === 'GS') {
        $exec_sql = "
            DECLARE @held_count INT, @exempt_count INT;
            
            DECLARE @flights dbo.FlightListType;
            INSERT INTO @flights SELECT * FROM #FlightList;
            
            EXEC dbo.sp_TMI_ApplyGroundStop 
                @program_id = ?, 
                @flights = @flights,
                @held_count = @held_count OUTPUT,
                @exempt_count = @exempt_count OUTPUT;
            
            SELECT @held_count AS assigned_count, @exempt_count AS exempt_count;
            
            DROP TABLE #FlightList;
        ";
    } else {
        $exec_sql = "
            DECLARE @assigned_count INT, @exempt_count INT;
            
            DECLARE @flights dbo.FlightListType;
            INSERT INTO @flights SELECT * FROM #FlightList;
            
            EXEC dbo.sp_TMI_AssignFlightsRBS 
                @program_id = ?, 
                @flights = @flights,
                @assigned_count = @assigned_count OUTPUT,
                @exempt_count = @exempt_count OUTPUT;
            
            SELECT @assigned_count AS assigned_count, @exempt_count AS exempt_count;
            
            DROP TABLE #FlightList;
        ";
    }
    
    $exec_stmt = sqlsrv_query($conn_tmi, $exec_sql, [$program_id]);
    
    if ($exec_stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to execute assignment procedure',
            'errors' => sqlsrv_errors()
        ]);
    }
    
    $result_row = sqlsrv_fetch_array($exec_stmt, SQLSRV_FETCH_ASSOC);
    if ($result_row) {
        $assigned_count = (int)($result_row['assigned_count'] ?? 0);
        $exempt_count = (int)($result_row['exempt_count'] ?? 0);
    }
    sqlsrv_free_stmt($exec_stmt);
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

// Build summary
$summary = [
    'total_flights' => count($flights_for_sp),
    'assigned_count' => $assigned_count,
    'exempt_count' => $exempt_count,
    'slot_count' => $slot_count,
    'avg_delay_min' => $program['avg_delay_min'] ?? null,
    'max_delay_min' => $program['max_delay_min'] ?? null,
    'total_delay_min' => $program['total_delay_min'] ?? null
];

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'message' => 'Simulation complete',
    'data' => [
        'program_id' => $program_id,
        'program_type' => $program_type,
        'program_status' => $program['status'] ?? 'MODELING',
        'slot_count' => $slot_count,
        'assigned_count' => $assigned_count,
        'exempt_count' => $exempt_count,
        'summary' => $summary,
        'flights' => $flight_control_result['success'] ? $flight_control_result['data'] : [],
        'slots' => $slots
    ]
]);
