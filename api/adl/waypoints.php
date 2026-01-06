<?php

// api/adl/waypoints.php
// Returns flight waypoints from ADL Azure SQL (adl_flight_waypoints) in JSON format.
// Also returns airport coordinates for origin/destination for fallback route display.
// Lookup by flight_uid, flight_key, or callsign.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once("../../load/config.php"); // ADL_SQL_* constants

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {

    http_response_code(500);
    echo json_encode([
        "error" => "ADL_SQL_* constants are not defined."
    ]);
    exit;
}

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode([
        "error" => "The sqlsrv extension is not available in PHP."
    ]);
    exit;
}

function adl_sql_error_message()
{
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) {
        return "";
    }
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn_adl = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn_adl === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Unable to connect to ADL Azure SQL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Determine lookup key: flight_uid, flight_key, or callsign
// ---------------------------------------------------------------------------

$flight_uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$flight_key = isset($_GET['key']) ? trim($_GET['key']) : '';
$callsign = '';
if (isset($_GET['cs'])) {
    $callsign = strtoupper(trim($_GET['cs']));
} elseif (isset($_GET['callsign'])) {
    $callsign = strtoupper(trim($_GET['callsign']));
}

// Validate input
if ($flight_uid <= 0 && $flight_key === '' && $callsign === '') {
    http_response_code(400);
    echo json_encode([
        "error" => "Must provide ?uid=<flight_uid>, ?key=<flight_key>, or ?cs=<CALLSIGN>"
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// First, get flight info including airport coordinates
// ---------------------------------------------------------------------------

if ($flight_uid > 0) {
    $lookupSql = "
        SELECT
            c.flight_uid, c.flight_key, c.callsign,
            fp.fp_dept_icao, fp.fp_dest_icao,
            p.lat AS ac_lat, p.lon AS ac_lon,
            dept.LAT_DECIMAL AS dept_lat, dept.LONG_DECIMAL AS dept_lon,
            dest.LAT_DECIMAL AS dest_lat, dest.LONG_DECIMAL AS dest_lon
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.apts dept ON dept.ICAO_ID = fp.fp_dept_icao
        LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
        WHERE c.flight_uid = ?
    ";
    $lookupParams = [$flight_uid];
} elseif ($flight_key !== '') {
    $lookupSql = "
        SELECT
            c.flight_uid, c.flight_key, c.callsign,
            fp.fp_dept_icao, fp.fp_dest_icao,
            p.lat AS ac_lat, p.lon AS ac_lon,
            dept.LAT_DECIMAL AS dept_lat, dept.LONG_DECIMAL AS dept_lon,
            dest.LAT_DECIMAL AS dest_lat, dest.LONG_DECIMAL AS dest_lon
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.apts dept ON dept.ICAO_ID = fp.fp_dept_icao
        LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
        WHERE c.flight_key = ? AND c.is_active = 1
    ";
    $lookupParams = [$flight_key];
} else {
    $lookupSql = "
        SELECT TOP 1
            c.flight_uid, c.flight_key, c.callsign,
            fp.fp_dept_icao, fp.fp_dest_icao,
            p.lat AS ac_lat, p.lon AS ac_lon,
            dept.LAT_DECIMAL AS dept_lat, dept.LONG_DECIMAL AS dept_lon,
            dest.LAT_DECIMAL AS dest_lat, dest.LONG_DECIMAL AS dest_lon
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.apts dept ON dept.ICAO_ID = fp.fp_dept_icao
        LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
        WHERE c.callsign = ? AND c.is_active = 1
        ORDER BY c.last_seen_utc DESC
    ";
    $lookupParams = [$callsign];
}

$stmt = sqlsrv_query($conn_adl, $lookupSql, $lookupParams);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database error looking up flight.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

$flightRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$flightRow) {
    http_response_code(404);
    echo json_encode([
        "error" => "Flight not found",
        "flight" => null,
        "waypoints" => [],
        "count" => 0
    ]);
    exit;
}

$flight_uid = (int)$flightRow['flight_uid'];

// Build flight info with airport coordinates
$flightInfo = [
    'flight_uid' => $flight_uid,
    'flight_key' => $flightRow['flight_key'],
    'callsign' => $flightRow['callsign'],
    'fp_dept_icao' => $flightRow['fp_dept_icao'],
    'fp_dest_icao' => $flightRow['fp_dest_icao'],
    'dept_lat' => $flightRow['dept_lat'] !== null ? (float)$flightRow['dept_lat'] : null,
    'dept_lon' => $flightRow['dept_lon'] !== null ? (float)$flightRow['dept_lon'] : null,
    'dest_lat' => $flightRow['dest_lat'] !== null ? (float)$flightRow['dest_lat'] : null,
    'dest_lon' => $flightRow['dest_lon'] !== null ? (float)$flightRow['dest_lon'] : null,
    'ac_lat' => $flightRow['ac_lat'] !== null ? (float)$flightRow['ac_lat'] : null,
    'ac_lon' => $flightRow['ac_lon'] !== null ? (float)$flightRow['ac_lon'] : null
];

// ---------------------------------------------------------------------------
// Fetch waypoints for the flight
// ---------------------------------------------------------------------------

// Debug mode
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

$sql = "
    SELECT
        w.sequence_num,
        w.fix_name,
        w.lat,
        w.lon,
        w.fix_type,
        w.source,
        w.on_airway,
        w.planned_alt_ft,
        w.is_step_climb_point,
        w.is_toc,
        w.is_tod
    FROM dbo.adl_flight_waypoints w
    WHERE w.flight_uid = ?
    ORDER BY w.sequence_num ASC
";

// Explicitly type the parameter as BIGINT
$params = array(
    array($flight_uid, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_INT, SQLSRV_SQLTYPE_BIGINT)
);

$stmt = sqlsrv_query($conn_adl, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database error fetching waypoints.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

$waypoints = [];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $waypoints[] = [
        'seq' => (int)$row['sequence_num'],
        'fix' => $row['fix_name'],
        'lat' => (float)$row['lat'],
        'lon' => (float)$row['lon'],
        'type' => $row['fix_type'],
        'source' => $row['source'],
        'airway' => $row['on_airway'],
        'alt_ft' => $row['planned_alt_ft'] !== null ? (int)$row['planned_alt_ft'] : null,
        'is_toc' => (bool)$row['is_toc'],
        'is_tod' => (bool)$row['is_tod'],
        'is_step' => (bool)$row['is_step_climb_point']
    ];
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn_adl);

// Return response - always includes flight info with airport coords
$response = [
    "flight" => $flightInfo,
    "waypoints" => $waypoints,
    "count" => count($waypoints),
    "message" => empty($waypoints) ? "No parsed waypoints - use airport coords for simple route" : null
];

// Add debug info if requested
if ($debug) {
    $response['debug'] = [
        'flight_uid_queried' => $flight_uid,
        'flight_uid_type' => gettype($flight_uid),
        'sql_errors' => adl_sql_error_message()
    ];
}

echo json_encode($response);

?>
