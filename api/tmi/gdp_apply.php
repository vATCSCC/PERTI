<?php
/**
 * GDP Apply API
 * 
 * Applies GDP simulation from sandbox (adl_flights_gdp) to live ADL (adl_flights).
 * Also updates gdp_log to mark the program as ACTIVE.
 * 
 * Input (JSON POST):
 *   - program_id: GDP program identifier
 *   - gdp_airport: CTL element (for validation)
 *   - gdp_start, gdp_end: Program times
 *   - Plus other config to store in gdp_log
 * 
 * Process:
 *   1. Validate simulation exists in sandbox
 *   2. Copy CTD/CTA/slot assignments from sandbox to live adl_flights
 *   3. Create/update gdp_log entry with ACTIVE status
 *   4. Clear sandbox
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php');

function split_codes($val) {
    if (is_array($val)) $val = implode(' ', $val);
    if (!is_string($val)) return [];
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    $val = str_replace([",",";","\n","\r","\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    $seen = []; $out = [];
    foreach ($parts as $p) { $p = trim($p); if ($p !== '' && !isset($seen[$p])) { $seen[$p]=1; $out[]=$p; } }
    return $out;
}

function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s)==='') return null;
    try { $dt = new DateTime(trim($s)); } catch (Exception $e) { return null; }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof \DateTimeInterface) {
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new \DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    if (is_string($val)) return $val;
    return $val;
}

// Input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$program_id          = isset($input['program_id']) ? trim($input['program_id']) : '';
$ctl_element         = isset($input['gdp_airport']) ? strtoupper(trim($input['gdp_airport'])) : '';
$gdp_start_raw       = isset($input['gdp_start']) ? $input['gdp_start'] : null;
$gdp_end_raw         = isset($input['gdp_end']) ? $input['gdp_end'] : null;
$program_rate        = isset($input['program_rate']) ? (int)$input['program_rate'] : 40;
$reserve_rate        = isset($input['reserve_rate']) ? (int)$input['reserve_rate'] : 0;
$program_rates_hourly = isset($input['program_rates_hourly']) ? $input['program_rates_hourly'] : null;
$reserve_rates_hourly = isset($input['reserve_rates_hourly']) ? $input['reserve_rates_hourly'] : null;
$scope_centers       = isset($input['gdp_origin_centers']) ? $input['gdp_origin_centers'] : '';
$scope_airports      = isset($input['gdp_origin_airports']) ? $input['gdp_origin_airports'] : '';
$scope_carriers      = isset($input['gdp_flt_incl_carrier']) ? $input['gdp_flt_incl_carrier'] : '';
$scope_ac_type       = isset($input['gdp_flt_incl_type']) ? strtoupper(trim($input['gdp_flt_incl_type'])) : 'ALL';
$exemptions_json     = isset($input['exemptions']) ? json_encode($input['exemptions']) : null;
$adv_number          = isset($input['adv_number']) ? trim($input['adv_number']) : '';
$impacting_condition = isset($input['impacting_condition']) ? trim($input['impacting_condition']) : '';
$prob_extension      = isset($input['prob_extension']) ? trim($input['prob_extension']) : '';
$user_id             = isset($input['user_id']) ? trim($input['user_id']) : null;

$gdp_start = parse_utc_datetime($gdp_start_raw);
$gdp_end   = parse_utc_datetime($gdp_end_raw);

// Validate
if ($ctl_element === '') {
    echo json_encode(['status'=>'error','message'=>'gdp_airport (CTL element) is required.'], JSON_PRETTY_PRINT);
    exit;
}
if ($gdp_start === null || $gdp_end === null) {
    echo json_encode(['status'=>'error','message'=>'gdp_start and gdp_end are required.'], JSON_PRETTY_PRINT);
    exit;
}

// Generate program_id if not provided
if ($program_id === '') {
    $program_id = 'GDP-' . $ctl_element . '-' . date('YmdHi', strtotime($gdp_start));
}

// Connection
$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode(['status'=>'error','message'=>'ADL SQL connection not established.'], JSON_PRETTY_PRINT);
    exit;
}

// Check sandbox has data
$check = sqlsrv_query($conn, "SELECT COUNT(*) AS cnt FROM dbo.adl_flights_gdp WHERE ctl_type LIKE 'GDP%'");
if ($check === false) {
    echo json_encode(['status'=>'error','message'=>'Failed to check sandbox','errors'=>sqlsrv_errors()], JSON_PRETTY_PRINT);
    exit;
}
$row = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
$sandbox_count = (int)($row['cnt'] ?? 0);

if ($sandbox_count === 0) {
    echo json_encode(['status'=>'error','message'=>'No flights in GDP sandbox. Run simulation first.'], JSON_PRETTY_PRINT);
    exit;
}

// Begin transaction
if (!sqlsrv_begin_transaction($conn)) {
    echo json_encode(['status'=>'error','message'=>'Failed to begin transaction'], JSON_PRETTY_PRINT);
    exit;
}

try {
    // 1) Update normalized tables with GDP control times from sandbox
    //    Match by flight_key via adl_flight_core

    // 1a) Update adl_flight_times for time columns
    $times_sql = "
        UPDATE t
        SET
            t.ctd_utc = gs.ctd_utc,
            t.cta_utc = gs.cta_utc,
            t.oetd_utc = gs.oetd_utc,
            t.betd_utc = gs.betd_utc,
            t.oeta_utc = gs.oeta_utc,
            t.beta_utc = gs.beta_utc
        FROM dbo.adl_flight_times t
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
        INNER JOIN dbo.adl_flights_gdp gs ON c.flight_key = gs.flight_key
        WHERE gs.ctl_type LIKE 'GDP%'
    ";
    $times_stmt = sqlsrv_query($conn, $times_sql);
    if ($times_stmt === false) throw new Exception('Update adl_flight_times failed: ' . json_encode(sqlsrv_errors()));

    // 1b) Ensure TMI rows exist for affected flights
    $insert_tmi_sql = "
        INSERT INTO dbo.adl_flight_tmi (flight_uid)
        SELECT DISTINCT c.flight_uid
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flights_gdp gs ON c.flight_key = gs.flight_key
        WHERE gs.ctl_type LIKE 'GDP%'
          AND NOT EXISTS (
              SELECT 1 FROM dbo.adl_flight_tmi tmi WHERE tmi.flight_uid = c.flight_uid
          )
    ";
    $ins_tmi_stmt = sqlsrv_query($conn, $insert_tmi_sql);
    if ($ins_tmi_stmt === false) throw new Exception('Insert adl_flight_tmi failed: ' . json_encode(sqlsrv_errors()));

    // 1c) Update adl_flight_tmi for control columns
    $tmi_sql = "
        UPDATE tmi
        SET
            tmi.ctl_type = gs.ctl_type,
            tmi.ctl_element = gs.ctl_element,
            tmi.delay_status = gs.delay_status,
            tmi.program_delay_min = gs.program_delay_min,
            tmi.absolute_delay_min = gs.absolute_delay_min,
            tmi.schedule_variation_min = gs.schedule_variation_min,
            tmi.gdp_program_id = gs.gdp_program_id,
            tmi.gdp_slot_index = gs.gdp_slot_index,
            tmi.gdp_slot_time_utc = gs.gdp_slot_time_utc
        FROM dbo.adl_flight_tmi tmi
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = tmi.flight_uid
        INNER JOIN dbo.adl_flights_gdp gs ON c.flight_key = gs.flight_key
        WHERE gs.ctl_type LIKE 'GDP%'
    ";
    $tmi_stmt = sqlsrv_query($conn, $tmi_sql);
    if ($tmi_stmt === false) throw new Exception('Update adl_flight_tmi failed: ' . json_encode(sqlsrv_errors()));

    $affected_rows = sqlsrv_rows_affected($tmi_stmt);

    // 2) Get summary metrics from sandbox before clearing
    $metrics = [
        'total_flights' => 0,
        'affected_flights' => 0,
        'exempt_flights' => 0,
        'total_delay_min' => 0,
        'max_delay_min' => 0,
        'avg_delay_min' => 0,
        'flights_in_stack' => 0,
        'slot_utilization' => 0
    ];
    
    $metrics_stmt = sqlsrv_query($conn, "
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN ctl_type = 'GDP' THEN 1 ELSE 0 END) AS assigned,
            SUM(CASE WHEN ctl_type = 'GDP-STK' THEN 1 ELSE 0 END) AS stack,
            SUM(CAST(ISNULL(program_delay_min, 0) AS BIGINT)) AS sum_delay,
            MAX(program_delay_min) AS max_delay,
            AVG(CAST(program_delay_min AS FLOAT)) AS avg_delay
        FROM dbo.adl_flights_gdp
        WHERE ctl_type LIKE 'GDP%'
    ");
    if ($metrics_stmt !== false && ($r = sqlsrv_fetch_array($metrics_stmt, SQLSRV_FETCH_ASSOC))) {
        $metrics['total_flights'] = (int)$r['total'];
        $metrics['affected_flights'] = (int)$r['assigned'];
        $metrics['flights_in_stack'] = (int)$r['stack'];
        $metrics['total_delay_min'] = (int)$r['sum_delay'];
        $metrics['max_delay_min'] = (int)$r['max_delay'];
        $metrics['avg_delay_min'] = $r['avg_delay'] !== null ? round((float)$r['avg_delay'], 1) : 0;
    }
    
    // Get slot utilization
    $slot_stmt = sqlsrv_query($conn, "
        SELECT 
            COUNT(*) AS total_slots,
            SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned_slots
        FROM dbo.adl_slots_gdp
        WHERE program_id = ?
    ", [$program_id]);
    if ($slot_stmt !== false && ($r = sqlsrv_fetch_array($slot_stmt, SQLSRV_FETCH_ASSOC))) {
        $total_slots = (int)$r['total_slots'];
        $assigned_slots = (int)$r['assigned_slots'];
        $metrics['slot_utilization'] = $total_slots > 0 ? round(($assigned_slots / $total_slots) * 100, 1) : 0;
    }
    
    // 3) Create or update gdp_log entry
    //    First check if program already exists
    $check_log = sqlsrv_query($conn, "SELECT id FROM dbo.gdp_log WHERE program_id = ?", [$program_id]);
    $existing_id = null;
    if ($check_log !== false && ($r = sqlsrv_fetch_array($check_log, SQLSRV_FETCH_ASSOC))) {
        $existing_id = $r['id'];
    }
    
    // Encode hourly rates as JSON if array
    $program_rates_json = is_array($program_rates_hourly) ? json_encode($program_rates_hourly) : $program_rates_hourly;
    $reserve_rates_json = is_array($reserve_rates_hourly) ? json_encode($reserve_rates_hourly) : $reserve_rates_hourly;
    
    if ($existing_id) {
        // Update existing
        $log_upd = sqlsrv_query($conn, "
            UPDATE dbo.gdp_log SET
                status = 'ACTIVE',
                modified_utc = GETUTCDATE(),
                program_start_utc = ?,
                program_end_utc = ?,
                program_rate = ?,
                program_rates_hourly = ?,
                reserve_rate = ?,
                reserve_rates_hourly = ?,
                scope_centers = ?,
                scope_airports = ?,
                scope_carriers = ?,
                scope_aircraft_type = ?,
                exemptions = ?,
                adv_number = ?,
                impacting_condition = ?,
                probability_of_extension = ?,
                total_flights = ?,
                affected_flights = ?,
                exempt_flights = ?,
                total_delay_min = ?,
                max_delay_min = ?,
                avg_delay_min = ?,
                flights_in_stack = ?,
                slot_utilization = ?,
                modified_by = ?
            WHERE program_id = ?
        ", [
            $gdp_start, $gdp_end,
            $program_rate, $program_rates_json,
            $reserve_rate, $reserve_rates_json,
            $scope_centers, $scope_airports, $scope_carriers, $scope_ac_type,
            $exemptions_json, $adv_number, $impacting_condition, $prob_extension,
            $metrics['total_flights'], $metrics['affected_flights'], $metrics['exempt_flights'],
            $metrics['total_delay_min'], $metrics['max_delay_min'], $metrics['avg_delay_min'],
            $metrics['flights_in_stack'], $metrics['slot_utilization'],
            $user_id, $program_id
        ]);
        if ($log_upd === false) throw new Exception('UPDATE gdp_log failed: ' . json_encode(sqlsrv_errors()));
    } else {
        // Insert new
        $log_ins = sqlsrv_query($conn, "
            INSERT INTO dbo.gdp_log (
                program_id, ctl_element, status, created_utc,
                program_start_utc, program_end_utc,
                program_rate, program_rates_hourly,
                reserve_rate, reserve_rates_hourly,
                scope_centers, scope_airports, scope_carriers, scope_aircraft_type,
                exemptions, adv_number, impacting_condition, probability_of_extension,
                total_flights, affected_flights, exempt_flights,
                total_delay_min, max_delay_min, avg_delay_min,
                flights_in_stack, slot_utilization, created_by
            ) VALUES (
                ?, ?, 'ACTIVE', GETUTCDATE(),
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?
            )
        ", [
            $program_id, $ctl_element,
            $gdp_start, $gdp_end,
            $program_rate, $program_rates_json,
            $reserve_rate, $reserve_rates_json,
            $scope_centers, $scope_airports, $scope_carriers, $scope_ac_type,
            $exemptions_json, $adv_number, $impacting_condition, $prob_extension,
            $metrics['total_flights'], $metrics['affected_flights'], $metrics['exempt_flights'],
            $metrics['total_delay_min'], $metrics['max_delay_min'], $metrics['avg_delay_min'],
            $metrics['flights_in_stack'], $metrics['slot_utilization'], $user_id
        ]);
        if ($log_ins === false) throw new Exception('INSERT gdp_log failed: ' . json_encode(sqlsrv_errors()));
    }
    
    // 4) Clear sandbox
    $clear = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gdp");
    if ($clear === false) throw new Exception('Clear sandbox failed: ' . json_encode(sqlsrv_errors()));
    
    if (!sqlsrv_commit($conn)) throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));
    
} catch (Exception $e) {
    sqlsrv_rollback($conn);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

// Get final flight list from vw_adl_flights (normalized tables view)
$flights_out = [];
$flights_stmt = sqlsrv_query($conn, "
    SELECT f.*, tmi.gdp_program_id, tmi.gdp_slot_index, tmi.gdp_slot_time_utc
    FROM dbo.vw_adl_flights f
    LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = f.flight_uid
    WHERE tmi.gdp_program_id = ?
    ORDER BY tmi.gdp_slot_index ASC, f.eta_runway_utc ASC
", [$program_id]);

if ($flights_stmt !== false) {
    while ($row = sqlsrv_fetch_array($flights_stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $val) {
            if ($val instanceof DateTimeInterface) {
                $row[$key] = datetime_to_iso($val);
            }
        }
        $flights_out[] = $row;
    }
}

echo json_encode([
    'status' => 'ok',
    'message' => 'GDP applied to live ADL.',
    'program_id' => $program_id,
    'applied_count' => $affected_rows,
    'metrics' => $metrics,
    'flights' => $flights_out
], JSON_PRETTY_PRINT);
?>
