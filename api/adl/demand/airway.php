<?php
/**
 * api/adl/demand/airway.php
 *
 * Airway Segment Demand API - Returns flights on an airway between two fixes
 *
 * Parameters:
 *   airway     - Required: Airway identifier (e.g., J48, V1, Q100)
 *   from_fix   - Required: Segment start fix (e.g., LANNA)
 *   to_fix     - Required: Segment end fix (e.g., MOL)
 *   minutes    - Optional: Time window in minutes (default 60, max 720)
 *   format     - Optional: 'list' (default) or 'count'
 *
 * Example:
 *   GET /api/adl/demand/airway?airway=J48&from_fix=LANNA&to_fix=MOL&minutes=180
 *
 * Response:
 *   {
 *     "airway": "J48",
 *     "segment": { "from": "LANNA", "to": "MOL" },
 *     "time_window_minutes": 180,
 *     "count": 8,
 *     "generated_utc": "2026-01-15T14:30:00Z",
 *     "flights": [...]
 *   }
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once(__DIR__ . '/../../../load/config.php');

// Validate config
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode([
        "error" => "ADL_SQL_* constants are not defined. Check config.php."
    ]);
    exit;
}

// Validate sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode([
        "error" => "The sqlsrv extension is not available."
    ]);
    exit;
}

function adl_sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = ($e['SQLSTATE'] ?? '') . " " . ($e['code'] ?? '') . " " . trim($e['message'] ?? '');
    }
    return implode(" | ", $msgs);
}

// Parse parameters
$airway = isset($_GET['airway']) ? get_upper('airway') : '';
$fromFix = isset($_GET['from_fix']) ? get_upper('from_fix') : '';
$toFix = isset($_GET['to_fix']) ? get_upper('to_fix') : '';
$minutes = isset($_GET['minutes']) ? (int)$_GET['minutes'] : 60;
$minutes = max(5, min(720, $minutes)); // Clamp to 5-720 minutes
$format = isset($_GET['format']) ? get_lower('format') : 'list';

// Validate required parameters
$missing = [];
if (empty($airway)) $missing[] = 'airway';
if (empty($fromFix)) $missing[] = 'from_fix';
if (empty($toFix)) $missing[] = 'to_fix';

if (!empty($missing)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Missing required parameters: " . implode(', ', $missing),
        "example" => "/api/adl/demand/airway?airway=J48&from_fix=LANNA&to_fix=MOL&minutes=180"
    ]);
    exit;
}

// Connect to database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Execute query
$sql = "SELECT * FROM dbo.fn_AirwaySegmentDemand(?, ?, ?, ?, NULL) ORDER BY entry_eta";
$params = [$airway, $fromFix, $toFix, $minutes];

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Query execution failed.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

// Fetch results
$flights = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Convert DateTime objects to ISO strings
    if (isset($row['entry_eta']) && $row['entry_eta'] instanceof DateTime) {
        $row['entry_eta'] = $row['entry_eta']->format('Y-m-d\TH:i:s\Z');
    }
    if (isset($row['exit_eta']) && $row['exit_eta'] instanceof DateTime) {
        $row['exit_eta'] = $row['exit_eta']->format('Y-m-d\TH:i:s\Z');
    }
    $flights[] = $row;
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

// Build response
$response = [
    "airway" => $airway,
    "segment" => [
        "from" => $fromFix,
        "to" => $toFix
    ],
    "time_window_minutes" => $minutes,
    "count" => count($flights),
    "generated_utc" => gmdate('Y-m-d\TH:i:s\Z')
];

if ($format !== 'count') {
    $response['flights'] = $flights;
}

echo json_encode($response, JSON_PRETTY_PRINT);
