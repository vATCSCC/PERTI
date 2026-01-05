<?php
/**
 * GDP Purge (Full) API
 * 
 * Cancels an active GDP:
 * 1. Clears GDP control fields from live adl_flights
 * 2. Updates gdp_log status to PURGED
 * 3. Optionally clears slot allocation
 * 
 * Input (JSON POST):
 *   - program_id: GDP program to purge (required)
 *   - gdp_airport: CTL element (alternative lookup)
 * 
 * Output:
 *   - Confirmation with count of cleared flights
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php');

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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$program_id  = isset($input['program_id']) ? trim($input['program_id']) : '';
$ctl_element = isset($input['gdp_airport']) ? strtoupper(trim($input['gdp_airport'])) : '';
$user_id     = isset($input['user_id']) ? trim($input['user_id']) : null;

// Must have program_id or ctl_element
if ($program_id === '' && $ctl_element === '') {
    echo json_encode(['status'=>'error','message'=>'program_id or gdp_airport is required.'], JSON_PRETTY_PRINT);
    exit;
}

$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode(['status'=>'error','message'=>'ADL SQL connection not established.'], JSON_PRETTY_PRINT);
    exit;
}

// If only ctl_element provided, find active program
if ($program_id === '' && $ctl_element !== '') {
    $find_stmt = sqlsrv_query($conn, "
        SELECT program_id FROM dbo.gdp_log 
        WHERE ctl_element = ? AND status = 'ACTIVE'
        ORDER BY created_utc DESC
    ", [$ctl_element]);
    if ($find_stmt !== false && ($r = sqlsrv_fetch_array($find_stmt, SQLSRV_FETCH_ASSOC))) {
        $program_id = $r['program_id'];
    }
}

if ($program_id === '') {
    echo json_encode(['status'=>'error','message'=>'No active GDP found for specified airport.'], JSON_PRETTY_PRINT);
    exit;
}

if (!sqlsrv_begin_transaction($conn)) {
    echo json_encode(['status'=>'error','message'=>'Failed to begin transaction'], JSON_PRETTY_PRINT);
    exit;
}

try {
    // 1) Clear GDP control fields from live adl_flights
    $clear_sql = "
        UPDATE dbo.adl_flights
        SET 
            ctl_type = NULL,
            ctl_element = NULL,
            ctd_utc = NULL,
            cta_utc = NULL,
            delay_status = NULL,
            program_delay_min = NULL,
            gdp_program_id = NULL,
            gdp_slot_index = NULL,
            gdp_slot_time_utc = NULL
        WHERE gdp_program_id = ?
    ";
    $clear_stmt = sqlsrv_query($conn, $clear_sql, [$program_id]);
    if ($clear_stmt === false) throw new Exception('Clear from live ADL failed: ' . json_encode(sqlsrv_errors()));
    $flights_cleared = sqlsrv_rows_affected($clear_stmt);
    
    // 2) Update gdp_log status to PURGED
    $upd_log = sqlsrv_query($conn, "
        UPDATE dbo.gdp_log 
        SET status = 'PURGED', 
            modified_utc = GETUTCDATE(),
            modified_by = ?
        WHERE program_id = ?
    ", [$user_id, $program_id]);
    if ($upd_log === false) throw new Exception('Update gdp_log failed: ' . json_encode(sqlsrv_errors()));
    
    // 3) Clear sandbox if any
    $clear_sandbox = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gdp WHERE gdp_program_id = ?", [$program_id]);
    
    // 4) Keep slots for historical reference (don't delete from adl_slots_gdp)
    //    Just mark them as cancelled
    $cancel_slots = sqlsrv_query($conn, "
        UPDATE dbo.adl_slots_gdp
        SET slot_status = 'CANCELLED', modified_utc = GETUTCDATE()
        WHERE program_id = ? AND slot_status = 'ASSIGNED'
    ", [$program_id]);
    
    if (!sqlsrv_commit($conn)) throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));
    
} catch (Exception $e) {
    sqlsrv_rollback($conn);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

// Get final program info
$log_info = null;
$log_stmt = sqlsrv_query($conn, "SELECT * FROM dbo.gdp_log WHERE program_id = ?", [$program_id]);
if ($log_stmt !== false && ($r = sqlsrv_fetch_array($log_stmt, SQLSRV_FETCH_ASSOC))) {
    foreach ($r as $key => $val) {
        if ($val instanceof DateTimeInterface) {
            $r[$key] = datetime_to_iso($val);
        }
    }
    $log_info = $r;
}

echo json_encode([
    'status' => 'ok',
    'message' => 'GDP purged from live ADL.',
    'program_id' => $program_id,
    'flights_cleared' => $flights_cleared,
    'program_info' => $log_info
], JSON_PRETTY_PRINT);
?>
