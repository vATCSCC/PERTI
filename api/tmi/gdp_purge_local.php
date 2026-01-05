<?php
/**
 * GDP Purge Local API
 * 
 * Clears the GDP sandbox table without affecting live ADL.
 * Used to reset simulation state before re-modeling.
 * 
 * Input (JSON POST):
 *   - program_id: Optional - clear specific program's slots
 *   - clear_slots: Boolean - also clear slot allocation table
 * 
 * Output:
 *   - Confirmation of cleared data
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$program_id  = isset($input['program_id']) ? trim($input['program_id']) : '';
$clear_slots = isset($input['clear_slots']) ? (bool)$input['clear_slots'] : true;

$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode(['status'=>'error','message'=>'ADL SQL connection not established.'], JSON_PRETTY_PRINT);
    exit;
}

if (!sqlsrv_begin_transaction($conn)) {
    echo json_encode(['status'=>'error','message'=>'Failed to begin transaction'], JSON_PRETTY_PRINT);
    exit;
}

try {
    // Clear sandbox flights
    $del_flights = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gdp");
    if ($del_flights === false) throw new Exception('DELETE adl_flights_gdp failed: ' . json_encode(sqlsrv_errors()));
    $flights_cleared = sqlsrv_rows_affected($del_flights);
    
    // Clear slots if requested
    $slots_cleared = 0;
    if ($clear_slots) {
        if ($program_id !== '') {
            $del_slots = sqlsrv_query($conn, "DELETE FROM dbo.adl_slots_gdp WHERE program_id = ?", [$program_id]);
        } else {
            // Clear all simulation slots (status not ACTIVE in gdp_log)
            $del_slots = sqlsrv_query($conn, "
                DELETE FROM dbo.adl_slots_gdp 
                WHERE program_id NOT IN (
                    SELECT program_id FROM dbo.gdp_log WHERE status = 'ACTIVE'
                )
            ");
        }
        if ($del_slots === false) throw new Exception('DELETE adl_slots_gdp failed: ' . json_encode(sqlsrv_errors()));
        $slots_cleared = sqlsrv_rows_affected($del_slots);
    }
    
    // Update any DRAFT/SIMULATED programs back to DRAFT
    if ($program_id !== '') {
        $upd_log = sqlsrv_query($conn, "
            UPDATE dbo.gdp_log 
            SET status = 'DRAFT', modified_utc = GETUTCDATE()
            WHERE program_id = ? AND status IN ('DRAFT', 'SIMULATED')
        ", [$program_id]);
    }
    
    if (!sqlsrv_commit($conn)) throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));
    
} catch (Exception $e) {
    sqlsrv_rollback($conn);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'message' => 'GDP sandbox cleared.',
    'flights_cleared' => $flights_cleared,
    'slots_cleared' => $slots_cleared
], JSON_PRETTY_PRINT);
?>
