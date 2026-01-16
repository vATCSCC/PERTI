<?php
/**
 * api/adl/demand/debug.php
 *
 * Debug endpoint for demand monitoring - checks waypoint data for specific flights
 *
 * Parameters:
 *   callsign   - Optional: Specific callsign to check (e.g., SWA1991)
 *   fix        - Optional: Check all flights with this fix in route
 *   airway     - Optional: Check all flights with this airway in route
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once(__DIR__ . '/../../../load/config.php');

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["error" => "The sqlsrv extension is not available."]);
    exit;
}

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to connect to ADL database."]);
    exit;
}

$callsign = isset($_GET['callsign']) ? strtoupper(trim($_GET['callsign'])) : '';
$fix = isset($_GET['fix']) ? strtoupper(trim($_GET['fix'])) : '';
$airway = isset($_GET['airway']) ? strtoupper(trim($_GET['airway'])) : '';

$result = [];

// If callsign specified, get waypoints for that flight
if (!empty($callsign)) {
    $sql = "SELECT
                c.callsign, c.flight_uid, c.phase, c.is_active,
                fp.fp_dept_icao, fp.fp_dest_icao,
                w.sequence_num, w.fix_name, w.on_airway, w.eta_utc, w.source
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_waypoints w ON w.flight_uid = c.flight_uid
            WHERE c.callsign = ?
            ORDER BY w.sequence_num";
    $stmt = sqlsrv_query($conn, $sql, [$callsign]);

    $flight = null;
    $waypoints = [];

    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!$flight) {
                $flight = [
                    'callsign' => $row['callsign'],
                    'flight_uid' => $row['flight_uid'],
                    'phase' => $row['phase'],
                    'is_active' => $row['is_active'],
                    'departure' => $row['fp_dept_icao'],
                    'destination' => $row['fp_dest_icao']
                ];
            }
            if ($row['fix_name']) {
                $eta = $row['eta_utc'] instanceof DateTime ? $row['eta_utc']->format('Y-m-d\TH:i:s\Z') : $row['eta_utc'];
                $waypoints[] = [
                    'seq' => $row['sequence_num'],
                    'fix' => $row['fix_name'],
                    'airway' => $row['on_airway'],
                    'eta' => $eta,
                    'source' => $row['source']
                ];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    $result['flight'] = $flight;
    $result['waypoints'] = $waypoints;
    $result['waypoint_count'] = count($waypoints);
}

// If fix specified, find flights with that fix
if (!empty($fix)) {
    $sql = "SELECT DISTINCT
                c.callsign, c.flight_uid, c.phase,
                fp.fp_dept_icao, fp.fp_dest_icao,
                w.fix_name, w.on_airway, w.eta_utc
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            INNER JOIN dbo.adl_flight_waypoints w ON w.flight_uid = c.flight_uid
            WHERE w.fix_name = ?
              AND c.is_active = 1
              AND c.phase NOT IN ('arrived', 'disconnected')
            ORDER BY c.callsign";
    $stmt = sqlsrv_query($conn, $sql, [$fix]);

    $flights = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $eta = $row['eta_utc'] instanceof DateTime ? $row['eta_utc']->format('Y-m-d\TH:i:s\Z') : $row['eta_utc'];
            $flights[] = [
                'callsign' => $row['callsign'],
                'flight_uid' => $row['flight_uid'],
                'phase' => $row['phase'],
                'route' => $row['fp_dept_icao'] . ' → ' . $row['fp_dest_icao'],
                'fix_airway' => $row['on_airway'],
                'fix_eta' => $eta
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    $result['flights_via_fix'] = [
        'fix' => $fix,
        'count' => count($flights),
        'flights' => $flights
    ];
}

// If airway specified, find flights on that airway
if (!empty($airway)) {
    $sql = "SELECT DISTINCT
                c.callsign, c.flight_uid, c.phase,
                fp.fp_dept_icao, fp.fp_dest_icao
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            INNER JOIN dbo.adl_flight_waypoints w ON w.flight_uid = c.flight_uid
            WHERE w.on_airway = ?
              AND c.is_active = 1
              AND c.phase NOT IN ('arrived', 'disconnected')
            ORDER BY c.callsign";
    $stmt = sqlsrv_query($conn, $sql, [$airway]);

    $flights = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $flights[] = [
                'callsign' => $row['callsign'],
                'flight_uid' => $row['flight_uid'],
                'phase' => $row['phase'],
                'route' => $row['fp_dept_icao'] . ' → ' . $row['fp_dest_icao']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    $result['flights_on_airway'] = [
        'airway' => $airway,
        'count' => count($flights),
        'flights' => $flights
    ];
}

// If no params, show usage
if (empty($callsign) && empty($fix) && empty($airway)) {
    $result = [
        'usage' => [
            'callsign' => '?callsign=SWA1991 - Get waypoints for a specific flight',
            'fix' => '?fix=JASSE - Find all flights with this fix in route',
            'airway' => '?airway=Q90 - Find all flights on this airway',
            'combined' => '?callsign=SWA1991&fix=JASSE - Can combine params'
        ],
        'examples' => [
            'api/adl/demand/debug.php?callsign=SWA1991',
            'api/adl/demand/debug.php?fix=JASSE',
            'api/adl/demand/debug.php?airway=Q90',
            'api/adl/demand/debug.php?fix=JASSE&fix=DNERO'
        ]
    ];
}

sqlsrv_close($conn);

echo json_encode($result, JSON_PRETTY_PRINT);
