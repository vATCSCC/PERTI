<?php
/**
 * api/adl/demand/fix.php
 *
 * Fix Demand API - Returns flights passing through a specific fix in a time window
 *
 * Parameters:
 *   fix        - Required: Navigation fix identifier (e.g., MERIT, LANNA)
 *   minutes    - Optional: Time window in minutes (default 60, max 720)
 *   dep_tracon - Optional: Filter by departure TRACON (e.g., N90)
 *   arr_tracon - Optional: Filter by arrival TRACON
 *   format     - Optional: 'list' (default) or 'count'
 *
 * Example:
 *   GET /api/adl/demand/fix?fix=MERIT&minutes=45&dep_tracon=N90
 *
 * Response:
 *   {
 *     "fix": "MERIT",
 *     "time_window_minutes": 45,
 *     "filters": { "dep_tracon": "N90" },
 *     "count": 12,
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
$fix = isset($_GET['fix']) ? get_upper('fix') : '';
$minutes = isset($_GET['minutes']) ? (int)$_GET['minutes'] : 60;
$minutes = max(5, min(720, $minutes)); // Clamp to 5-720 minutes
$depTracon = isset($_GET['dep_tracon']) && !empty($_GET['dep_tracon']) ? get_upper('dep_tracon') : null;
$arrTracon = isset($_GET['arr_tracon']) && !empty($_GET['arr_tracon']) ? get_upper('arr_tracon') : null;
$format = isset($_GET['format']) ? get_lower('format') : 'list';

// Validate required parameter
if (empty($fix)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Missing required parameter: fix",
        "example" => "/api/adl/demand/fix?fix=MERIT&minutes=45&dep_tracon=N90"
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
$sql = "SELECT * FROM dbo.fn_FixDemand(?, ?, NULL, ?, ?) ORDER BY eta_at_fix";
$params = [$fix, $minutes, $depTracon, $arrTracon];

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
    if (isset($row['eta_at_fix']) && $row['eta_at_fix'] instanceof DateTime) {
        $row['eta_at_fix'] = $row['eta_at_fix']->format('Y-m-d\TH:i:s\Z');
    }
    $flights[] = $row;
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

// Build response
$response = [
    "fix" => $fix,
    "time_window_minutes" => $minutes,
    "filters" => array_filter([
        "dep_tracon" => $depTracon,
        "arr_tracon" => $arrTracon
    ]),
    "count" => count($flights),
    "generated_utc" => gmdate('Y-m-d\TH:i:s\Z')
];

if ($format !== 'count') {
    $response['flights'] = $flights;
}

echo json_encode($response, JSON_PRETTY_PRINT);
