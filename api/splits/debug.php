<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/connect_adl.php';

if (!$conn_adl) {
    echo json_encode(['error' => 'DB connection failed', 'details' => $conn_adl_error]);
    exit;
}

$result = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'configs' => [],
    'positions' => [],
    'areas' => []
];

// Get all configs
$sql = "SELECT * FROM splits_configs ORDER BY id DESC";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $result['configs'][] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get all positions
$sql = "SELECT * FROM splits_positions ORDER BY config_id, sort_order";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $result['positions'][] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get all areas
$sql = "SELECT * FROM splits_areas ORDER BY artcc, area_name";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $result['areas'][] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

$result['summary'] = [
    'total_configs' => count($result['configs']),
    'total_positions' => count($result['positions']),
    'total_areas' => count($result['areas']),
    'active_configs' => count(array_filter($result['configs'], fn($c) => $c['status'] === 'active'))
];

echo json_encode($result, JSON_PRETTY_PRINT);
