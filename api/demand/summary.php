<?php

// api/demand/summary.php
// Returns flight summary data for demand visualization
// Includes top origin ARTCCs, top carriers, and optional flight list for drill-down

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once("../../load/config.php");

// Check ADL database configuration
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

// Helper function for SQL Server error messages
function adl_sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

// Check sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "sqlsrv extension not available."]);
    exit;
}

// Connect to ADL database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Get parameters
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : '';
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;
$timeBin = isset($_GET['time_bin']) ? trim($_GET['time_bin']) : null; // For drill-down
$direction = isset($_GET['direction']) ? strtolower(trim($_GET['direction'])) : 'both';

// Validate airport
if (empty($airport) || strlen($airport) !== 4) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid or missing airport parameter."]);
    sqlsrv_close($conn);
    exit;
}

// Parse time range
$now = new DateTime('now', new DateTimeZone('UTC'));

if ($start !== null) {
    try {
        $startDt = new DateTime($start, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        $startDt = (clone $now)->modify('-6 hours');
    }
} else {
    $startDt = (clone $now)->modify('-6 hours');
}

if ($end !== null) {
    try {
        $endDt = new DateTime($end, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        $endDt = (clone $now)->modify('+6 hours');
    }
} else {
    $endDt = (clone $now)->modify('+6 hours');
}

$startSQL = $startDt->format('Y-m-d H:i:s');
$endSQL = $endDt->format('Y-m-d H:i:s');

$response = [
    "success" => true,
    "airport" => $airport,
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "time_range" => [
        "start" => $startDt->format("Y-m-d\\TH:i:s\\Z"),
        "end" => $endDt->format("Y-m-d\\TH:i:s\\Z")
    ]
];

// If time_bin is specified, return detailed flight list for that bin
if ($timeBin !== null) {
    $response['flights'] = getFlightsForTimeBin($conn, $airport, $timeBin, $direction);
} else {
    // Return summary data
    $response['top_origins'] = getTopOrigins($conn, $airport, $startSQL, $endSQL);
    $response['top_carriers'] = getTopCarriers($conn, $airport, $startSQL, $endSQL, $direction);
    $response['origin_artcc_breakdown'] = getOriginARTCCBreakdown($conn, $airport, $startSQL, $endSQL);
}

sqlsrv_close($conn);
echo json_encode($response);

/**
 * Get top origin ARTCCs for arrivals
 */
function getTopOrigins($conn, $airport, $startSQL, $endSQL) {
    $sql = "
        SELECT TOP 10
            fp_dept_artcc AS artcc,
            COUNT(*) AS count
        FROM dbo.adl_flights
        WHERE fp_dest_icao = ?
          AND eta_runway_utc IS NOT NULL
          AND eta_runway_utc >= ?
          AND eta_runway_utc < ?
          AND fp_dept_artcc IS NOT NULL
          AND fp_dept_artcc != ''
        GROUP BY fp_dept_artcc
        ORDER BY count DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$airport, $startSQL, $endSQL]);
    $results = [];

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = [
                "artcc" => $row['artcc'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get top carriers
 */
function getTopCarriers($conn, $airport, $startSQL, $endSQL, $direction) {
    $whereClause = "";
    if ($direction === 'arr') {
        $whereClause = "fp_dest_icao = ? AND eta_runway_utc >= ? AND eta_runway_utc < ?";
    } elseif ($direction === 'dep') {
        $whereClause = "fp_dept_icao = ? AND etd_runway_utc >= ? AND etd_runway_utc < ?";
    } else {
        // Both - union arrivals and departures
        $sql = "
            SELECT TOP 10 carrier, SUM(cnt) AS count FROM (
                SELECT LEFT(callsign, 3) AS carrier, COUNT(*) AS cnt
                FROM dbo.adl_flights
                WHERE fp_dest_icao = ?
                  AND eta_runway_utc >= ?
                  AND eta_runway_utc < ?
                  AND callsign IS NOT NULL
                  AND LEN(callsign) >= 3
                GROUP BY LEFT(callsign, 3)
                UNION ALL
                SELECT LEFT(callsign, 3) AS carrier, COUNT(*) AS cnt
                FROM dbo.adl_flights
                WHERE fp_dept_icao = ?
                  AND etd_runway_utc >= ?
                  AND etd_runway_utc < ?
                  AND callsign IS NOT NULL
                  AND LEN(callsign) >= 3
                GROUP BY LEFT(callsign, 3)
            ) combined
            GROUP BY carrier
            ORDER BY count DESC
        ";

        $stmt = sqlsrv_query($conn, $sql, [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]);
        $results = [];

        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $results[] = [
                    "carrier" => $row['carrier'],
                    "count" => (int)$row['count']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }

        return $results;
    }

    // Single direction query
    $sql = "
        SELECT TOP 10
            LEFT(callsign, 3) AS carrier,
            COUNT(*) AS count
        FROM dbo.adl_flights
        WHERE {$whereClause}
          AND callsign IS NOT NULL
          AND LEN(callsign) >= 3
        GROUP BY LEFT(callsign, 3)
        ORDER BY count DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$airport, $startSQL, $endSQL]);
    $results = [];

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = [
                "carrier" => $row['carrier'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get origin ARTCC breakdown for arrivals (for chart visualization)
 */
function getOriginARTCCBreakdown($conn, $airport, $startSQL, $endSQL) {
    $sql = "
        SELECT
            DATEADD(HOUR, DATEDIFF(HOUR, 0, eta_runway_utc), 0) AS time_bin,
            fp_dept_artcc AS artcc,
            COUNT(*) AS count
        FROM dbo.adl_flights
        WHERE fp_dest_icao = ?
          AND eta_runway_utc IS NOT NULL
          AND eta_runway_utc >= ?
          AND eta_runway_utc < ?
          AND fp_dept_artcc IS NOT NULL
          AND fp_dept_artcc != ''
        GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, eta_runway_utc), 0), fp_dept_artcc
        ORDER BY time_bin, count DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$airport, $startSQL, $endSQL]);
    $results = [];

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $timeBin = $row['time_bin'];
            if ($timeBin instanceof DateTime) {
                $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
            }

            if (!isset($results[$timeBin])) {
                $results[$timeBin] = [];
            }
            $results[$timeBin][] = [
                "artcc" => $row['artcc'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get detailed flight list for a specific time bin (drill-down)
 */
function getFlightsForTimeBin($conn, $airport, $timeBin, $direction) {
    // Parse the time bin to get start and end of the hour
    try {
        $binStart = new DateTime($timeBin, new DateTimeZone('UTC'));
        $binEnd = (clone $binStart)->modify('+1 hour');
    } catch (Exception $e) {
        return [];
    }

    $binStartSQL = $binStart->format('Y-m-d H:i:s');
    $binEndSQL = $binEnd->format('Y-m-d H:i:s');

    $flights = [];

    // Get arrivals
    if ($direction === 'arr' || $direction === 'both') {
        $sql = "
            SELECT
                callsign,
                fp_dept_icao AS origin,
                fp_dest_icao AS destination,
                fp_dept_artcc AS origin_artcc,
                eta_runway_utc AS eta,
                flight_status,
                is_active,
                aircraft_type
            FROM dbo.adl_flights
            WHERE fp_dest_icao = ?
              AND eta_runway_utc >= ?
              AND eta_runway_utc < ?
            ORDER BY eta_runway_utc
        ";

        $stmt = sqlsrv_query($conn, $sql, [$airport, $binStartSQL, $binEndSQL]);

        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $eta = $row['eta'];
                if ($eta instanceof DateTime) {
                    $eta = $eta->format("Y-m-d\\TH:i:s\\Z");
                }

                $flights[] = [
                    "callsign" => $row['callsign'],
                    "origin" => $row['origin'],
                    "destination" => $row['destination'],
                    "origin_artcc" => $row['origin_artcc'],
                    "time" => $eta,
                    "direction" => "arrival",
                    "status" => deriveStatus($row['flight_status'], $row['is_active']),
                    "aircraft" => $row['aircraft_type']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    }

    // Get departures
    if ($direction === 'dep' || $direction === 'both') {
        $sql = "
            SELECT
                callsign,
                fp_dept_icao AS origin,
                fp_dest_icao AS destination,
                fp_dest_artcc AS dest_artcc,
                etd_runway_utc AS etd,
                flight_status,
                is_active,
                aircraft_type
            FROM dbo.adl_flights
            WHERE fp_dept_icao = ?
              AND etd_runway_utc >= ?
              AND etd_runway_utc < ?
            ORDER BY etd_runway_utc
        ";

        $stmt = sqlsrv_query($conn, $sql, [$airport, $binStartSQL, $binEndSQL]);

        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $etd = $row['etd'];
                if ($etd instanceof DateTime) {
                    $etd = $etd->format("Y-m-d\\TH:i:s\\Z");
                }

                $flights[] = [
                    "callsign" => $row['callsign'],
                    "origin" => $row['origin'],
                    "destination" => $row['destination'],
                    "dest_artcc" => $row['dest_artcc'],
                    "time" => $etd,
                    "direction" => "departure",
                    "status" => deriveStatus($row['flight_status'], $row['is_active']),
                    "aircraft" => $row['aircraft_type']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    }

    // Sort by time
    usort($flights, function($a, $b) {
        return strcmp($a['time'], $b['time']);
    });

    return $flights;
}

/**
 * Derive FSM status from flight_status and is_active
 */
function deriveStatus($flightStatus, $isActive) {
    if ($flightStatus === 'L') return 'arrived';
    if ($flightStatus === 'A') return 'active';
    if ($flightStatus === 'D') return 'departed';
    if ($isActive == 1) return 'scheduled';
    return 'proposed';
}
