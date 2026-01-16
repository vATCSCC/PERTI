<?php
/**
 * Save Hourly Rates API
 * Saves per-airport hourly AAR/ADR data to VATSIM_ADL database
 */

header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include("../../load/config.php");
include("../../load/connect.php");

// Check VATSIM_ADL connection
if (!isset($conn_adl) || $conn_adl === false) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection to VATSIM_ADL failed'
    ]);
    exit;
}

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// Get JSON payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

// Validate required fields
if (empty($data['plan_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing plan_id']);
    exit;
}

if (empty($data['airports']) || !is_array($data['airports'])) {
    echo json_encode(['success' => false, 'message' => 'Missing airports data']);
    exit;
}

$plan_id = intval($data['plan_id']);
$statsim_url = $data['statsim_url'] ?? '';

try {
    // Begin transaction
    sqlsrv_begin_transaction($conn_adl);
    
    // Delete existing rates for this plan
    $delete_hourly = "DELETE FROM dbo.r_hourly_rates WHERE plan_id = ?";
    $stmt = sqlsrv_query($conn_adl, $delete_hourly, [$plan_id]);
    if ($stmt === false) throw new Exception("Delete hourly failed: " . print_r(sqlsrv_errors(), true));
    sqlsrv_free_stmt($stmt);
    
    $delete_totals = "DELETE FROM dbo.r_airport_totals WHERE plan_id = ?";
    $stmt = sqlsrv_query($conn_adl, $delete_totals, [$plan_id]);
    if ($stmt === false) throw new Exception("Delete totals failed: " . print_r(sqlsrv_errors(), true));
    sqlsrv_free_stmt($stmt);
    
    // Insert new data for each airport
    foreach ($data['airports'] as $airport) {
        $icao = $airport['icao'];
        $name = $airport['name'] ?? '';
        $totals = $airport['totals'] ?? [];
        
        // Insert airport totals
        $insert_totals = "INSERT INTO dbo.r_airport_totals 
            (plan_id, icao, name, statsim_arr, statsim_dep, vatsim_aar, vatsim_adr, rw_aar, rw_adr, statsim_url, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";
        
        $totals_params = [
            $plan_id,
            $icao,
            $name,
            $totals['statsim_arr'] ?? null,
            $totals['statsim_dep'] ?? null,
            $totals['vatsim_aar'] ?? null,
            $totals['vatsim_adr'] ?? null,
            $totals['rw_aar'] ?? null,
            $totals['rw_adr'] ?? null,
            $statsim_url
        ];
        
        $stmt = sqlsrv_query($conn_adl, $insert_totals, $totals_params);
        if ($stmt === false) throw new Exception("Insert totals failed for $icao: " . print_r(sqlsrv_errors(), true));
        sqlsrv_free_stmt($stmt);
        
        // Insert hourly data
        if (!empty($airport['hours']) && is_array($airport['hours'])) {
            foreach ($airport['hours'] as $hour) {
                $insert_hourly = "INSERT INTO dbo.r_hourly_rates 
                    (plan_id, icao, hour_timestamp, hour_date, hour_time, 
                     statsim_arr, statsim_dep, vatsim_aar, vatsim_adr, rw_aar, rw_adr)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $hourly_params = [
                    $plan_id,
                    $icao,
                    $hour['timestamp'] ?? null,
                    $hour['date'] ?? null,
                    $hour['time'] ?? null,
                    $hour['statsim_arr'] ?? null,
                    $hour['statsim_dep'] ?? null,
                    $hour['vatsim_aar'],
                    $hour['vatsim_adr'],
                    $hour['rw_aar'],
                    $hour['rw_adr']
                ];
                
                $stmt = sqlsrv_query($conn_adl, $insert_hourly, $hourly_params);
                if ($stmt === false) throw new Exception("Insert hourly failed for $icao: " . print_r(sqlsrv_errors(), true));
                sqlsrv_free_stmt($stmt);
            }
        }
    }
    
    // Commit transaction
    sqlsrv_commit($conn_adl);
    
    echo json_encode([
        'success' => true,
        'message' => 'Rates saved successfully to VATSIM_ADL',
        'plan_id' => $plan_id,
        'airports_saved' => count($data['airports'])
    ]);
    
} catch (Exception $e) {
    sqlsrv_rollback($conn_adl);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
