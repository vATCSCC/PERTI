<?php
/**
 * api/adl/demand/details.php
 *
 * Demand Monitor Details API - Returns individual flights captured by a monitor
 *
 * This endpoint provides flight-level detail for a single demand monitor,
 * allowing users to see exactly which flights are being counted.
 *
 * Parameters:
 *   type           - Required: Monitor type (fix, segment, airway, airway_segment, via_fix)
 *   fix            - Required for type=fix: Fix name
 *   from           - Required for type=segment/airway_segment: From fix
 *   to             - Required for type=segment/airway_segment: To fix
 *   airway         - Required for type=airway/airway_segment: Airway name
 *   via            - Required for type=via_fix: Via fix/airway
 *   via_type       - Required for type=via_fix: 'fix' or 'airway'
 *   filter_type    - Required for type=via_fix: airport/tracon/artcc
 *   filter_code    - Required for type=via_fix: Filter code (KBOS, N90, ZDC)
 *   direction      - Required for type=via_fix: arr/dep/both
 *   minutes_ahead  - Optional: Time window (default 60, max 720)
 *
 * Response:
 *   {
 *     "monitor_id": "fix_MERIT",
 *     "monitor_type": "fix",
 *     "flights": [
 *       {
 *         "flight_uid": 12345,
 *         "callsign": "AAL123",
 *         "departure": "KJFK",
 *         "destination": "KBOS",
 *         "aircraft_type": "A320",
 *         "eta_utc": "2026-01-15T22:30:00Z",
 *         "minutes_until": 15,
 *         "phase": "enroute"
 *       }
 *     ],
 *     "total_count": 12
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
    echo json_encode(["error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["error" => "The sqlsrv extension is not available."]);
    exit;
}

function sql_error_msg() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = ($e['SQLSTATE'] ?? '') . " " . ($e['code'] ?? '') . " " . trim($e['message'] ?? '');
    }
    return implode(" | ", $msgs);
}

// Parse parameters
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
$minutesAhead = isset($_GET['minutes_ahead']) ? (int)$_GET['minutes_ahead'] : 60;
$minutesAhead = max(15, min(720, $minutesAhead)); // 15 min to 12 hours

if (empty($type)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required parameter: type"]);
    exit;
}

// Validate type-specific parameters
switch ($type) {
    case 'fix':
        $fix = isset($_GET['fix']) ? strtoupper(trim($_GET['fix'])) : '';
        if (empty($fix)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required parameter: fix"]);
            exit;
        }
        $monitorId = 'fix_' . $fix;
        break;

    case 'segment':
        $from = isset($_GET['from']) ? strtoupper(trim($_GET['from'])) : '';
        $to = isset($_GET['to']) ? strtoupper(trim($_GET['to'])) : '';
        if (empty($from) || empty($to)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required parameters: from, to"]);
            exit;
        }
        $monitorId = 'segment_' . $from . '_' . $to;
        break;

    case 'airway':
        $airway = isset($_GET['airway']) ? strtoupper(trim($_GET['airway'])) : '';
        if (empty($airway)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required parameter: airway"]);
            exit;
        }
        $monitorId = 'airway_' . $airway;
        break;

    case 'airway_segment':
        $airway = isset($_GET['airway']) ? strtoupper(trim($_GET['airway'])) : '';
        $from = isset($_GET['from']) ? strtoupper(trim($_GET['from'])) : '';
        $to = isset($_GET['to']) ? strtoupper(trim($_GET['to'])) : '';
        if (empty($airway) || empty($from) || empty($to)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required parameters: airway, from, to"]);
            exit;
        }
        $monitorId = 'airway_' . $airway . '_' . $from . '_' . $to;
        break;

    case 'via_fix':
        $via = isset($_GET['via']) ? strtoupper(trim($_GET['via'])) : '';
        $viaType = isset($_GET['via_type']) ? strtolower(trim($_GET['via_type'])) : 'fix';
        $filterType = isset($_GET['filter_type']) ? strtolower(trim($_GET['filter_type'])) : '';
        $filterCode = isset($_GET['filter_code']) ? strtoupper(trim($_GET['filter_code'])) : '';
        $direction = isset($_GET['direction']) ? strtolower(trim($_GET['direction'])) : 'both';

        if (empty($via) || empty($filterType) || empty($filterCode)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required parameters: via, filter_type, filter_code"]);
            exit;
        }
        $monitorId = 'via_' . $filterType . '_' . $filterCode . '_' . $direction . '_' . $via;
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid type: $type"]);
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
    echo json_encode(["error" => "Unable to connect to ADL database.", "sql_error" => sql_error_msg()]);
    exit;
}

$flights = [];
$sqlError = null;

switch ($type) {
    case 'fix':
        // Use fn_FixDemand
        $sql = "SELECT * FROM dbo.fn_FixDemand(?, ?, NULL) ORDER BY eta_utc";
        $stmt = sqlsrv_query($conn, $sql, [$fix, $minutesAhead]);
        if ($stmt === false) {
            $sqlError = sql_error_msg();
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $flights[] = formatFlightRow($row);
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'segment':
        // Use fn_RouteSegmentDemand
        $sql = "SELECT * FROM dbo.fn_RouteSegmentDemand(?, ?, ?, NULL) ORDER BY eta_utc";
        $stmt = sqlsrv_query($conn, $sql, [$from, $to, $minutesAhead]);
        if ($stmt === false) {
            $sqlError = sql_error_msg();
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $flights[] = formatFlightRow($row);
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'airway':
        // Use fn_AirwayDemand (list version)
        $sql = "SELECT DISTINCT
                    c.flight_uid, c.callsign, c.phase,
                    fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                    fp.aircraft_type,
                    MIN(w.eta_utc) AS eta_utc,
                    DATEDIFF(MINUTE, GETUTCDATE(), MIN(w.eta_utc)) AS minutes_until
                FROM dbo.adl_flight_waypoints w
                INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                WHERE w.on_airway = ?
                  AND w.eta_utc >= GETUTCDATE()
                  AND w.eta_utc < DATEADD(MINUTE, ?, GETUTCDATE())
                  AND c.is_active = 1
                  AND c.phase NOT IN ('arrived', 'disconnected')
                GROUP BY c.flight_uid, c.callsign, c.phase,
                         fp.fp_dept_icao, fp.fp_dest_icao, fp.aircraft_type
                ORDER BY eta_utc";
        $stmt = sqlsrv_query($conn, $sql, [$airway, $minutesAhead]);
        if ($stmt === false) {
            $sqlError = sql_error_msg();
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $flights[] = formatFlightRow($row);
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'airway_segment':
        // Use fn_AirwaySegmentDemand
        $sql = "SELECT * FROM dbo.fn_AirwaySegmentDemand(?, ?, ?, ?, NULL) ORDER BY entry_eta";
        $stmt = sqlsrv_query($conn, $sql, [$airway, $from, $to, $minutesAhead]);
        if ($stmt === false) {
            $sqlError = sql_error_msg();
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $flight = formatFlightRow($row);
                if (isset($row['entry_eta'])) {
                    $flight['eta_utc'] = formatDateTime($row['entry_eta']);
                }
                $flights[] = $flight;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'via_fix':
        // Build query based on via_type and filter
        $filterColumn = $direction === 'arr' ? 'fp_dest' : ($direction === 'dep' ? 'fp_dept' : 'fp_dest');
        $filterColumnAlt = $direction === 'arr' ? 'fp_dest' : ($direction === 'dep' ? 'fp_dept' : 'fp_dept');

        switch ($filterType) {
            case 'airport':
                $filterClause = "(fp.fp_dest_icao = ? OR fp.fp_dept_icao = ?)";
                $filterParams = [$filterCode, $filterCode];
                if ($direction === 'arr') {
                    $filterClause = "fp.fp_dest_icao = ?";
                    $filterParams = [$filterCode];
                } else if ($direction === 'dep') {
                    $filterClause = "fp.fp_dept_icao = ?";
                    $filterParams = [$filterCode];
                }
                break;
            case 'tracon':
                $filterClause = "(fp.fp_dest_tracon = ? OR fp.fp_dept_tracon = ?)";
                $filterParams = [$filterCode, $filterCode];
                if ($direction === 'arr') {
                    $filterClause = "fp.fp_dest_tracon = ?";
                    $filterParams = [$filterCode];
                } else if ($direction === 'dep') {
                    $filterClause = "fp.fp_dept_tracon = ?";
                    $filterParams = [$filterCode];
                }
                break;
            case 'artcc':
                $filterClause = "(fp.fp_dest_artcc = ? OR fp.fp_dept_artcc = ?)";
                $filterParams = [$filterCode, $filterCode];
                if ($direction === 'arr') {
                    $filterClause = "fp.fp_dest_artcc = ?";
                    $filterParams = [$filterCode];
                } else if ($direction === 'dep') {
                    $filterClause = "fp.fp_dept_artcc = ?";
                    $filterParams = [$filterCode];
                }
                break;
            default:
                $filterClause = "1=1";
                $filterParams = [];
        }

        if ($viaType === 'airway') {
            // Via airway
            $sql = "SELECT DISTINCT
                        c.flight_uid, c.callsign, c.phase,
                        fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                        fp.aircraft_type,
                        MIN(w.eta_utc) AS eta_utc,
                        DATEDIFF(MINUTE, GETUTCDATE(), MIN(w.eta_utc)) AS minutes_until
                    FROM dbo.adl_flight_waypoints w
                    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                    WHERE w.on_airway = ?
                      AND w.eta_utc >= GETUTCDATE()
                      AND w.eta_utc < DATEADD(MINUTE, ?, GETUTCDATE())
                      AND c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND $filterClause
                    GROUP BY c.flight_uid, c.callsign, c.phase,
                             fp.fp_dept_icao, fp.fp_dest_icao, fp.aircraft_type
                    ORDER BY eta_utc";
            $params = array_merge([$via, $minutesAhead], $filterParams);
        } else {
            // Via fix
            $sql = "SELECT DISTINCT
                        c.flight_uid, c.callsign, c.phase,
                        fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                        fp.aircraft_type,
                        w.eta_utc,
                        DATEDIFF(MINUTE, GETUTCDATE(), w.eta_utc) AS minutes_until
                    FROM dbo.adl_flight_waypoints w
                    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                    WHERE w.fix_name = ?
                      AND w.eta_utc >= GETUTCDATE()
                      AND w.eta_utc < DATEADD(MINUTE, ?, GETUTCDATE())
                      AND c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND $filterClause
                    ORDER BY eta_utc";
            $params = array_merge([$via, $minutesAhead], $filterParams);
        }

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $sqlError = sql_error_msg();
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $flights[] = formatFlightRow($row);
            }
            sqlsrv_free_stmt($stmt);
        }
        break;
}

sqlsrv_close($conn);

// Build response
$response = [
    "monitor_id" => $monitorId,
    "monitor_type" => $type,
    "minutes_ahead" => $minutesAhead,
    "flights" => $flights,
    "total_count" => count($flights)
];

if ($sqlError) {
    $response["sql_error"] = $sqlError;
}

echo json_encode($response, JSON_PRETTY_PRINT);

/**
 * Format a flight row from SQL result
 */
function formatFlightRow($row) {
    return [
        "flight_uid" => (int)($row['flight_uid'] ?? 0),
        "callsign" => $row['callsign'] ?? '',
        "departure" => $row['departure'] ?? ($row['fp_dept_icao'] ?? ''),
        "destination" => $row['destination'] ?? ($row['fp_dest_icao'] ?? ''),
        "aircraft_type" => $row['aircraft_type'] ?? '',
        "eta_utc" => formatDateTime($row['eta_utc'] ?? null),
        "minutes_until" => (int)($row['minutes_until'] ?? ($row['minutes_until_over'] ?? 0)),
        "phase" => $row['phase'] ?? ''
    ];
}

/**
 * Format DateTime to ISO string
 */
function formatDateTime($dt) {
    if ($dt === null) return null;
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
    return $dt;
}
