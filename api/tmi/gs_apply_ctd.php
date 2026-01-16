<?php
// api/tmi/gs_apply_ctd.php
// Apply Ground Stop parameters to ADL flights by setting CTD (EDCT),
// and recomputing CTA, CETE, and ETA fields in dbo.adl_flights.

header('Content-Type: application/json');

// Basic CORS allowance for same-site XHR; adjust as needed.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'error'   => 'Failed to read request body',
        'updated' => 0
    ]);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'error'   => 'Invalid JSON payload',
        'updated' => 0
    ]);
    exit;
}

// We only care about the per-flight CTD updates here.
// gs_end_utc is in the payload but not used directly in this script.
$updates = isset($data['updates']) && is_array($data['updates']) ? $data['updates'] : [];

if (!$updates) {
    echo json_encode([
        'ok'      => true,
        'updated' => 0
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 1) Load ADL config and verify constants
// ---------------------------------------------------------------------------

require_once("../../load/config.php"); // ADL_SQL_* constants

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {

    http_response_code(500);
    echo json_encode([
        "ok"    => false,
        "error" => "ADL_SQL_* constants are not defined. Check load/config.php for ADL_SQL_HOST, ADL_SQL_DATABASE, ADL_SQL_USERNAME, ADL_SQL_PASSWORD."
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 2) Helper for formatting sqlsrv_errors(), same as other ADL APIs
// ---------------------------------------------------------------------------

if (!function_exists('adl_sql_error_message')) {
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
}

// ---------------------------------------------------------------------------
// 3) Connect to Azure SQL ADL database
// ---------------------------------------------------------------------------

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode([
        "ok"    => false,
        "error" => "The sqlsrv extension is not available in PHP. It is required to connect to Azure SQL (ADL)."
    ]);
    exit;
}

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "ok"        => false,
        "error"     => "Unable to connect to ADL Azure SQL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 4) Apply CTD / CTA / CETE / ETA per flight
//    - ctd_utc : GS-controlled departure time (EDCT)
//    - cta_utc : arrival from CTD + ETE (or fallback ETA)
//    - cete_minutes : difference between baseline ETA/EST and CTD+ETE
//    - eta_prefix : set to 'C' (controlled)
//    - eta_runway_utc : mirror CTA in the ADL ETA field
//
//    NOTE: Data lives in normalized tables - update adl_flight_times via
//    join to adl_flight_core (which has callsign) and adl_flight_plan (which has fp_dept_icao/fp_dest_icao)
// ---------------------------------------------------------------------------

$totalUpdated = 0;

foreach ($updates as $u) {
    if (!is_array($u)) {
        continue;
    }

    $callsign = isset($u['callsign']) ? trim($u['callsign']) : '';
    $depIcao  = isset($u['dep_icao']) ? trim($u['dep_icao']) : null;
    $destIcao = isset($u['dest_icao']) ? trim($u['dest_icao']) : null;
    $ctdUtc   = isset($u['ctd_utc']) ? trim($u['ctd_utc']) : '';

    if ($callsign === '' || $ctdUtc === '') {
        continue;
    }

    // Update adl_flight_times via join to adl_flight_core and adl_flight_plan
    // The time columns live in adl_flight_times, not the physical adl_flights table
    $sql = "
        UPDATE t
        SET
            t.ctd_utc = ?,
            t.cta_utc = CASE
                          WHEN t.ete_minutes IS NOT NULL
                              THEN DATEADD(MINUTE, t.ete_minutes, ?)
                          WHEN t.eta_runway_utc IS NOT NULL
                              THEN t.eta_runway_utc
                          ELSE NULL
                      END,
            t.eta_prefix = 'C',
            t.eta_runway_utc = CASE
                                 WHEN t.ete_minutes IS NOT NULL
                                     THEN DATEADD(MINUTE, t.ete_minutes, ?)
                                 WHEN t.eta_runway_utc IS NOT NULL
                                     THEN t.eta_runway_utc
                                 ELSE NULL
                             END,
            t.cete_minutes = CASE
                               WHEN t.eta_runway_utc IS NOT NULL AND t.ete_minutes IS NOT NULL
                                   THEN DATEDIFF(MINUTE, t.eta_runway_utc, DATEADD(MINUTE, t.ete_minutes, ?))
                               ELSE NULL
                           END
        FROM dbo.adl_flight_times AS t
        INNER JOIN dbo.adl_flight_core AS c ON c.flight_uid = t.flight_uid
        INNER JOIN dbo.adl_flight_plan AS fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.callsign = ?
          ";

    $params = [$ctdUtc, $ctdUtc, $ctdUtc, $ctdUtc, $callsign];

    if ($depIcao !== null && $depIcao !== '') {
        $sql .= " AND fp.fp_dept_icao = ?";
        $params[] = $depIcao;
    }
    if ($destIcao !== null && $destIcao !== '') {
        $sql .= " AND fp.fp_dest_icao = ?";
        $params[] = $destIcao;
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        // Optionally log server-side:
        // error_log('gs_apply_ctd sql error: ' . adl_sql_error_message() . ' SQL=' . $sql);
        continue;
    }

    $rows = sqlsrv_rows_affected($stmt);
    if ($rows === false) {
        $rows = 0;
    }
    $totalUpdated += $rows;
}

// Optionally close the connection
sqlsrv_close($conn);

echo json_encode([
    'ok'      => true,
    'updated' => $totalUpdated
]);
