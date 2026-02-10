<?php
/**
 * TMR Staffing API - Fetch planned staffing from PERTI plan
 *
 * GET ?p_id=N — Returns terminal, enroute, and DCC staffing data for the plan
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';

$p_id = get_int('p_id');
if (!$p_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing p_id']);
    exit;
}

$result = [
    'terminal' => [],
    'enroute' => [],
    'dcc' => [],
];

// Terminal staffing
$stmt = $conn_pdo->prepare("SELECT id, facility_name, staffing_status, staffing_quantity, comments FROM p_terminal_staffing WHERE p_id = ? ORDER BY facility_name");
$stmt->execute([$p_id]);
$result['terminal'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enroute staffing
$stmt = $conn_pdo->prepare("SELECT id, facility_name, staffing_status, staffing_quantity, comments FROM p_enroute_staffing WHERE p_id = ? ORDER BY facility_name");
$stmt->execute([$p_id]);
$result['enroute'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// DCC staffing
$stmt = $conn_pdo->prepare("SELECT id, position_name, position_facility, personnel_name, personnel_ois FROM p_dcc_staffing WHERE p_id = ? ORDER BY position_name");
$stmt->execute([$p_id]);
$result['dcc'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($result['terminal']) + count($result['enroute']) + count($result['dcc']);

echo json_encode([
    'success' => true,
    'staffing' => $result,
    'total' => $total,
]);
