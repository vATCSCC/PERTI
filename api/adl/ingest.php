<?php
/**
 * VATSIM Data Ingestion API Endpoint
 *
 * Triggers a single VATSIM data fetch and process cycle.
 * Useful for testing or manual refresh.
 *
 * GET /api/adl/ingest.php - Run one ingestion cycle
 */

header('Content-Type: application/json; charset=utf-8');

// Load config and connection
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

// Check ADL connection
if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to VATSIM_ADL database']);
    exit;
}

$result = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'success' => false
];

$startTime = microtime(true);

// Fetch VATSIM data
$ch = curl_init('https://data.vatsim.net/v3/vatsim-data.json');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'PERTI-ADL/1.0',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_ENCODING => 'gzip'
]);

$json = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$result['fetch_time_ms'] = round((microtime(true) - $startTime) * 1000);

if ($error) {
    $result['error'] = "CURL Error: {$error}";
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($httpCode !== 200) {
    $result['error'] = "HTTP Error: {$httpCode}";
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Validate JSON
$data = json_decode($json, true);
if ($data === null) {
    $result['error'] = 'Invalid JSON from VATSIM';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$result['pilots_in_feed'] = count($data['pilots'] ?? []);
$result['controllers_in_feed'] = count($data['controllers'] ?? []);

// Call the stored procedure
$procStart = microtime(true);
$result['json_length'] = strlen($json);
$result['json_preview'] = substr($json, 0, 500) . '...';

$sql = "EXEC dbo.sp_Adl_RefreshFromVatsim_Normalized @Json = ?";
$params = [$json];
$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    $result['error'] = 'Database error executing procedure';
    $result['sql_errors'] = $errors;
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Check for warnings/info messages
$warnings = sqlsrv_errors(SQLSRV_ERR_ALL);
if ($warnings) {
    $result['sql_warnings'] = $warnings;
}

// Get result stats
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row) {
    $result['success'] = true;
    $result['stats'] = [
        'pilots_received' => $row['pilots_received'] ?? 0,
        'new_flights' => $row['new_flights'] ?? 0,
        'updated_flights' => $row['updated_flights'] ?? 0,
        'routes_queued' => $row['routes_queued'] ?? 0,
        'etds_calculated' => $row['etds_calculated'] ?? 0,
        'simbrief_parsed' => $row['simbrief_parsed'] ?? 0,
        'etas_calculated' => $row['etas_calculated'] ?? 0,
        'waypoint_etas' => $row['waypoint_etas'] ?? 0,
        'trajectories_logged' => $row['trajectories_logged'] ?? 0,
        'zone_transitions' => $row['zone_transitions'] ?? 0,
        'boundary_transitions' => $row['boundary_transitions'] ?? 0,
        'proc_elapsed_ms' => $row['elapsed_ms'] ?? 0
    ];
}

sqlsrv_free_stmt($stmt);

$result['proc_time_ms'] = round((microtime(true) - $procStart) * 1000);
$result['total_time_ms'] = round((microtime(true) - $startTime) * 1000);

sqlsrv_close($conn_adl);

echo json_encode($result, JSON_PRETTY_PRINT);
