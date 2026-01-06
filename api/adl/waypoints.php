<?php

// api/adl/waypoints.php
// Returns flight waypoints from ADL Azure SQL (adl_flight_waypoints) in JSON format.
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
// First, resolve to flight_uid if needed
// ---------------------------------------------------------------------------

if ($flight_uid <= 0) {
    if ($flight_key !== '') {
        $sql = "SELECT flight_uid FROM dbo.adl_flight_core WHERE flight_key = ? AND is_active = 1";
        $params = [$flight_key];
    } else {
        $sql = "SELECT flight_uid FROM dbo.adl_flight_core WHERE callsign = ? AND is_active = 1 ORDER BY last_seen_utc DESC";
        $params = [$callsign];
    }

    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode([
            "error" => "Database error looking up flight.",
            "sql_error" => adl_sql_error_message()
        ]);
        exit;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            "error" => "Flight not found",
            "waypoints" => []
        ]);
        exit;
    }

    $flight_uid = (int)$row['flight_uid'];
}

// ---------------------------------------------------------------------------
// Fetch waypoints for the flight
// ---------------------------------------------------------------------------

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
        w.is_tod,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        c.callsign,
        c.flight_key
    FROM dbo.adl_flight_waypoints w
    JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
    WHERE w.flight_uid = ?
    ORDER BY w.sequence_num ASC
";

$stmt = sqlsrv_query($conn_adl, $sql, [$flight_uid]);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database error fetching waypoints.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

$waypoints = [];
$flightInfo = null;

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Capture flight info from first row
    if ($flightInfo === null) {
        $flightInfo = [
            'flight_uid' => $flight_uid,
            'flight_key' => $row['flight_key'],
            'callsign' => $row['callsign'],
            'fp_dept_icao' => $row['fp_dept_icao'],
            'fp_dest_icao' => $row['fp_dest_icao']
        ];
    }

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

// If no waypoints found, try to get basic route from flight_plan
if (empty($waypoints)) {
    echo json_encode([
        "flight" => $flightInfo,
        "waypoints" => [],
        "count" => 0,
        "message" => "No parsed waypoints available for this flight"
    ]);
    exit;
}

echo json_encode([
    "flight" => $flightInfo,
    "waypoints" => $waypoints,
    "count" => count($waypoints)
]);

?>
