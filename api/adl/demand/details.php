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
 *   flight         - Optional: Filter to specific callsign or flight_uid
 *   airline        - Optional: Filter by airline prefix (e.g., UAL, AAL)
 *   aircraft_type  - Optional: Filter by aircraft type (e.g., B738, A320)
 *   aircraft_category - Optional: Filter by category (HEAVY, LARGE, SMALL)
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

/**
 * Build SQL WHERE clause for flight filters
 *
 * Supported filters:
 *   airline         - Callsign prefix (e.g., "UAL", "AAL", "SWA")
 *   aircraft_type   - Aircraft type code (e.g., "B738", "A320")
 *   aircraft_category - Wake category: HEAVY, LARGE, SMALL
 *   origin          - Departure airport (e.g., "KJFK")
 *   destination     - Arrival airport (e.g., "KLAX")
 *
 * @param array $filter Flight filter definition
 * @param string $coreAlias Alias for adl_flight_core table (default 'c')
 * @param string $planAlias Alias for adl_flight_plan table (default 'fp')
 * @return array ['clause' => SQL string, 'params' => array of values]
 */
function buildFlightFilterClause($filter, $coreAlias = 'c', $planAlias = 'fp') {
    if (empty($filter) || !is_array($filter)) {
        return ['clause' => '', 'params' => []];
    }

    $clauses = [];
    $params = [];

    // Airline filter (callsign prefix)
    if (!empty($filter['airline'])) {
        $airline = strtoupper(trim($filter['airline']));
        $clauses[] = "$coreAlias.callsign LIKE ?";
        $params[] = $airline . '%';
    }

    // Aircraft type filter
    if (!empty($filter['aircraft_type'])) {
        $type = strtoupper(trim($filter['aircraft_type']));
        $clauses[] = "$planAlias.aircraft_type = ?";
        $params[] = $type;
    }

    // Aircraft category filter (HEAVY, LARGE, SMALL)
    if (!empty($filter['aircraft_category'])) {
        $category = strtoupper(trim($filter['aircraft_category']));
        switch ($category) {
            case 'HEAVY':
                $clauses[] = "($planAlias.aircraft_type LIKE 'B74%' OR $planAlias.aircraft_type LIKE 'B77%' OR $planAlias.aircraft_type LIKE 'B78%' OR $planAlias.aircraft_type LIKE 'A33%' OR $planAlias.aircraft_type LIKE 'A34%' OR $planAlias.aircraft_type LIKE 'A35%' OR $planAlias.aircraft_type LIKE 'A38%' OR $planAlias.aircraft_type LIKE 'B76%' OR $planAlias.aircraft_type IN ('DC10', 'MD11', 'C5', 'C17', 'A310', 'A306', 'B752', 'B753'))";
                break;
            case 'LARGE':
                $clauses[] = "($planAlias.aircraft_type LIKE 'B73%' OR $planAlias.aircraft_type LIKE 'A32%' OR $planAlias.aircraft_type LIKE 'A31%' OR $planAlias.aircraft_type LIKE 'A22%' OR $planAlias.aircraft_type LIKE 'E1%' OR $planAlias.aircraft_type LIKE 'E2%' OR $planAlias.aircraft_type LIKE 'CRJ%' OR $planAlias.aircraft_type IN ('MD80', 'MD81', 'MD82', 'MD83', 'MD87', 'MD88', 'B712', 'B721', 'B722', 'DC9', 'DC8'))";
                break;
            case 'SMALL':
                $clauses[] = "($planAlias.aircraft_type NOT LIKE 'B74%' AND $planAlias.aircraft_type NOT LIKE 'B77%' AND $planAlias.aircraft_type NOT LIKE 'B78%' AND $planAlias.aircraft_type NOT LIKE 'A33%' AND $planAlias.aircraft_type NOT LIKE 'A34%' AND $planAlias.aircraft_type NOT LIKE 'A35%' AND $planAlias.aircraft_type NOT LIKE 'A38%' AND $planAlias.aircraft_type NOT LIKE 'B76%' AND $planAlias.aircraft_type NOT LIKE 'B73%' AND $planAlias.aircraft_type NOT LIKE 'A32%' AND $planAlias.aircraft_type NOT LIKE 'A31%')";
                break;
        }
    }

    // Origin airport filter
    if (!empty($filter['origin'])) {
        $origin = strtoupper(trim($filter['origin']));
        $clauses[] = "$planAlias.fp_dept_icao = ?";
        $params[] = $origin;
    }

    // Destination airport filter
    if (!empty($filter['destination'])) {
        $dest = strtoupper(trim($filter['destination']));
        $clauses[] = "$planAlias.fp_dest_icao = ?";
        $params[] = $dest;
    }

    if (empty($clauses)) {
        return ['clause' => '', 'params' => []];
    }

    return [
        'clause' => ' AND ' . implode(' AND ', $clauses),
        'params' => $params
    ];
}

// Parse parameters
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
$minutesAhead = isset($_GET['minutes_ahead']) ? (int)$_GET['minutes_ahead'] : 60;
$minutesAhead = max(15, min(720, $minutesAhead)); // 15 min to 12 hours

// Optional flight filter - filter to specific callsign or flight_uid
$flightFilter = isset($_GET['flight']) ? trim($_GET['flight']) : '';
$flightFilterClause = '';
$flightFilterParams = [];

// Build flight filter from optional parameters
$flightFilterDef = [];
if (isset($_GET['airline']) && !empty($_GET['airline'])) {
    $flightFilterDef['airline'] = $_GET['airline'];
}
if (isset($_GET['aircraft_type']) && !empty($_GET['aircraft_type'])) {
    $flightFilterDef['aircraft_type'] = $_GET['aircraft_type'];
}
if (isset($_GET['aircraft_category']) && !empty($_GET['aircraft_category'])) {
    $flightFilterDef['aircraft_category'] = $_GET['aircraft_category'];
}
if (isset($_GET['origin']) && !empty($_GET['origin'])) {
    $flightFilterDef['origin'] = $_GET['origin'];
}
if (isset($_GET['destination']) && !empty($_GET['destination'])) {
    $flightFilterDef['destination'] = $_GET['destination'];
}

// Build the SQL clause for flight filters
$flightFilterResult = buildFlightFilterClause($flightFilterDef);
$extraFilterClause = $flightFilterResult['clause'];
$extraFilterParams = $flightFilterResult['params'];

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

// Build flight filter if specified
if (!empty($flightFilter)) {
    if (is_numeric($flightFilter)) {
        $flightFilterClause = " AND c.flight_uid = ?";
        $flightFilterParams = [(int)$flightFilter];
    } else {
        $flightFilterClause = " AND c.callsign = ?";
        $flightFilterParams = [strtoupper($flightFilter)];
    }
}

switch ($type) {
    case 'fix':
        // Query with position_status: before = hasn't reached fix, after = past fix
        $sql = "SELECT DISTINCT
                    c.flight_uid, c.callsign, c.phase,
                    fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                    fp.aircraft_type,
                    w.eta_utc,
                    DATEDIFF(MINUTE, GETUTCDATE(), w.eta_utc) AS minutes_until,
                    CASE
                        WHEN w.eta_utc > GETUTCDATE() THEN 'before'
                        ELSE 'after'
                    END AS position_status
                FROM dbo.adl_flight_waypoints w
                INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                WHERE w.fix_name = ?
                  AND w.eta_utc >= DATEADD(MINUTE, -30, GETUTCDATE())
                  AND w.eta_utc < DATEADD(MINUTE, ?, GETUTCDATE())
                  AND c.is_active = 1
                  AND c.phase NOT IN ('arrived', 'disconnected')
                  $flightFilterClause
                  $extraFilterClause
                ORDER BY position_status DESC, eta_utc";
        $params = array_merge([$fix, $minutesAhead], $flightFilterParams, $extraFilterParams);
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

    case 'segment':
        // Query with position_status: before/in/after based on from/to fix ETAs
        $sql = "WITH FlightsWithBothFixes AS (
                    SELECT c.flight_uid
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    WHERE c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND EXISTS (
                          SELECT 1 FROM dbo.adl_flight_waypoints w1
                          WHERE w1.flight_uid = c.flight_uid AND w1.fix_name = ?
                      )
                      AND EXISTS (
                          SELECT 1 FROM dbo.adl_flight_waypoints w2
                          WHERE w2.flight_uid = c.flight_uid AND w2.fix_name = ?
                      )
                      $extraFilterClause
                ),
                FlightETAs AS (
                    SELECT
                        f.flight_uid,
                        (SELECT TOP 1 w1.eta_utc FROM dbo.adl_flight_waypoints w1
                         WHERE w1.flight_uid = f.flight_uid AND w1.fix_name = ?
                         ORDER BY w1.sequence_num) AS entry_eta,
                        (SELECT TOP 1 w2.eta_utc FROM dbo.adl_flight_waypoints w2
                         WHERE w2.flight_uid = f.flight_uid AND w2.fix_name = ?
                         ORDER BY w2.sequence_num) AS exit_eta
                    FROM FlightsWithBothFixes f
                )
                SELECT DISTINCT
                    c.flight_uid, c.callsign, c.phase,
                    fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                    fp.aircraft_type,
                    fe.entry_eta AS eta_utc,
                    DATEDIFF(MINUTE, GETUTCDATE(), fe.entry_eta) AS minutes_until,
                    CASE
                        WHEN fe.entry_eta > GETUTCDATE() THEN 'before'
                        WHEN fe.exit_eta > GETUTCDATE() THEN 'in'
                        ELSE 'after'
                    END AS position_status
                FROM FlightETAs fe
                INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fe.flight_uid
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = fe.flight_uid
                WHERE fe.entry_eta >= DATEADD(MINUTE, -30, GETUTCDATE())
                  AND fe.entry_eta < DATEADD(MINUTE, ?, GETUTCDATE())
                  $flightFilterClause
                ORDER BY
                    CASE position_status WHEN 'in' THEN 1 WHEN 'before' THEN 2 ELSE 3 END,
                    fe.entry_eta";
        $params = array_merge([$from, $to], $extraFilterParams, [$from, $to, $minutesAhead], $flightFilterParams);
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

    case 'airway':
        // Query with position_status for entire airway
        $sql = "WITH AirwayFlights AS (
                    SELECT
                        c.flight_uid, c.callsign, c.phase,
                        fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                        fp.aircraft_type,
                        MIN(w.eta_utc) AS first_eta,
                        MAX(w.eta_utc) AS last_eta
                    FROM dbo.adl_flight_waypoints w
                    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                    WHERE w.on_airway = ?
                      AND c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      $flightFilterClause
                      $extraFilterClause
                    GROUP BY c.flight_uid, c.callsign, c.phase,
                             fp.fp_dept_icao, fp.fp_dest_icao, fp.aircraft_type
                )
                SELECT
                    flight_uid, callsign, phase, departure, destination, aircraft_type,
                    first_eta AS eta_utc,
                    DATEDIFF(MINUTE, GETUTCDATE(), first_eta) AS minutes_until,
                    CASE
                        WHEN first_eta > GETUTCDATE() THEN 'before'
                        WHEN last_eta > GETUTCDATE() THEN 'in'
                        ELSE 'after'
                    END AS position_status
                FROM AirwayFlights
                WHERE first_eta >= DATEADD(MINUTE, -30, GETUTCDATE())
                  AND first_eta < DATEADD(MINUTE, ?, GETUTCDATE())
                ORDER BY
                    CASE WHEN first_eta > GETUTCDATE() THEN 'before'
                         WHEN last_eta > GETUTCDATE() THEN 'in'
                         ELSE 'after' END,
                    first_eta";
        $params = array_merge([$airway], $flightFilterParams, $extraFilterParams, [$minutesAhead]);
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

    case 'airway_segment':
        // Query with position_status: before/in/after based on entry and exit ETAs
        $sql = "WITH FlightsWithBothFixes AS (
                    SELECT c.flight_uid
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    WHERE c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND EXISTS (
                          SELECT 1 FROM dbo.adl_flight_waypoints w1
                          WHERE w1.flight_uid = c.flight_uid AND w1.fix_name = ?
                      )
                      AND EXISTS (
                          SELECT 1 FROM dbo.adl_flight_waypoints w2
                          WHERE w2.flight_uid = c.flight_uid AND w2.fix_name = ?
                      )
                      $extraFilterClause
                ),
                FlightETAs AS (
                    SELECT
                        f.flight_uid,
                        (SELECT TOP 1 w1.eta_utc FROM dbo.adl_flight_waypoints w1
                         WHERE w1.flight_uid = f.flight_uid AND w1.fix_name = ?
                         ORDER BY w1.sequence_num) AS entry_eta,
                        (SELECT TOP 1 w2.eta_utc FROM dbo.adl_flight_waypoints w2
                         WHERE w2.flight_uid = f.flight_uid AND w2.fix_name = ?
                         ORDER BY w2.sequence_num) AS exit_eta
                    FROM FlightsWithBothFixes f
                )
                SELECT DISTINCT
                    c.flight_uid, c.callsign, c.phase,
                    fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                    fp.aircraft_type,
                    fe.entry_eta AS eta_utc,
                    DATEDIFF(MINUTE, GETUTCDATE(), fe.entry_eta) AS minutes_until,
                    CASE
                        WHEN fe.entry_eta > GETUTCDATE() THEN 'before'
                        WHEN fe.exit_eta > GETUTCDATE() THEN 'in'
                        ELSE 'after'
                    END AS position_status
                FROM FlightETAs fe
                INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fe.flight_uid
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = fe.flight_uid
                WHERE fe.entry_eta >= DATEADD(MINUTE, -30, GETUTCDATE())
                  AND fe.entry_eta < DATEADD(MINUTE, ?, GETUTCDATE())
                  $flightFilterClause
                ORDER BY
                    CASE
                        WHEN fe.entry_eta > GETUTCDATE() THEN 2
                        WHEN fe.exit_eta > GETUTCDATE() THEN 1
                        ELSE 3
                    END,
                    fe.entry_eta";
        $params = array_merge([$from, $to], $extraFilterParams, [$from, $to, $minutesAhead], $flightFilterParams);
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

    case 'via_fix':
        // Build filter clause based on filter_type and direction
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
            // Via airway with position_status
            $sql = "WITH AirwayFlights AS (
                        SELECT
                            c.flight_uid, c.callsign, c.phase,
                            fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                            fp.aircraft_type,
                            MIN(w.eta_utc) AS first_eta,
                            MAX(w.eta_utc) AS last_eta
                        FROM dbo.adl_flight_waypoints w
                        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                        WHERE w.on_airway = ?
                          AND c.is_active = 1
                          AND c.phase NOT IN ('arrived', 'disconnected')
                          AND $filterClause
                          $flightFilterClause
                          $extraFilterClause
                        GROUP BY c.flight_uid, c.callsign, c.phase,
                                 fp.fp_dept_icao, fp.fp_dest_icao, fp.aircraft_type
                    )
                    SELECT
                        flight_uid, callsign, phase, departure, destination, aircraft_type,
                        first_eta AS eta_utc,
                        DATEDIFF(MINUTE, GETUTCDATE(), first_eta) AS minutes_until,
                        CASE
                            WHEN first_eta > GETUTCDATE() THEN 'before'
                            WHEN last_eta > GETUTCDATE() THEN 'in'
                            ELSE 'after'
                        END AS position_status
                    FROM AirwayFlights
                    WHERE first_eta >= DATEADD(MINUTE, -30, GETUTCDATE())
                      AND first_eta < DATEADD(MINUTE, ?, GETUTCDATE())
                    ORDER BY position_status DESC, first_eta";
            $params = array_merge([$via], $filterParams, $flightFilterParams, $extraFilterParams, [$minutesAhead]);
        } else {
            // Via fix with position_status
            $sql = "SELECT DISTINCT
                        c.flight_uid, c.callsign, c.phase,
                        fp.fp_dept_icao AS departure, fp.fp_dest_icao AS destination,
                        fp.aircraft_type,
                        w.eta_utc,
                        DATEDIFF(MINUTE, GETUTCDATE(), w.eta_utc) AS minutes_until,
                        CASE
                            WHEN w.eta_utc > GETUTCDATE() THEN 'before'
                            ELSE 'after'
                        END AS position_status
                    FROM dbo.adl_flight_waypoints w
                    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                    WHERE w.fix_name = ?
                      AND w.eta_utc >= DATEADD(MINUTE, -30, GETUTCDATE())
                      AND w.eta_utc < DATEADD(MINUTE, ?, GETUTCDATE())
                      AND c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND $filterClause
                      $flightFilterClause
                      $extraFilterClause
                    ORDER BY position_status DESC, eta_utc";
            $params = array_merge([$via, $minutesAhead], $filterParams, $flightFilterParams, $extraFilterParams);
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
    $flight = [
        "flight_uid" => (int)($row['flight_uid'] ?? 0),
        "callsign" => $row['callsign'] ?? '',
        "departure" => $row['departure'] ?? ($row['fp_dept_icao'] ?? ''),
        "destination" => $row['destination'] ?? ($row['fp_dest_icao'] ?? ''),
        "aircraft_type" => $row['aircraft_type'] ?? '',
        "eta_utc" => formatDateTime($row['eta_utc'] ?? null),
        "minutes_until" => (int)($row['minutes_until'] ?? ($row['minutes_until_over'] ?? 0)),
        "phase" => $row['phase'] ?? ''
    ];

    // Add position status if available
    if (isset($row['position_status'])) {
        $flight['status'] = $row['position_status'];
    }

    return $flight;
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
