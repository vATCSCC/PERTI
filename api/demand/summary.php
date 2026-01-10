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

// Use ADL Query Helper for normalized table support
require_once(__DIR__ . '/../adl/AdlQueryHelper.php');
$helper = new AdlQueryHelper();

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
    $response['flights'] = getFlightsForTimeBin($conn, $helper, $airport, $timeBin, $direction);
} else {
    // Return summary data
    $response['top_origins'] = getTopOrigins($conn, $helper, $airport, $startSQL, $endSQL);
    $response['top_carriers'] = getTopCarriers($conn, $helper, $airport, $startSQL, $endSQL, $direction);
    $response['origin_artcc_breakdown'] = getOriginARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL);

    // New breakdown data for demand chart filters
    $response['dest_artcc_breakdown'] = getDestARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL);
    $response['weight_breakdown'] = getWeightBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL);
    $response['carrier_breakdown'] = getCarrierBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL);
    $response['equipment_breakdown'] = getEquipmentBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL);
    $response['rule_breakdown'] = getRuleBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL);
    $response['dep_fix_breakdown'] = getDepFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL);
    $response['arr_fix_breakdown'] = getArrFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL);
    $response['dp_breakdown'] = getDPBreakdown($conn, $helper, $airport, $startSQL, $endSQL);
    $response['star_breakdown'] = getSTARBreakdown($conn, $helper, $airport, $startSQL, $endSQL);
}

sqlsrv_close($conn);
echo json_encode($response);

/**
 * Get top origin ARTCCs for arrivals
 */
function getTopOrigins($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildTopOriginsQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
function getTopCarriers($conn, $helper, $airport, $startSQL, $endSQL, $direction) {
    if ($direction === 'both') {
        // Both - union arrivals and departures
        $query = $helper->buildTopCarriersBothQuery($airport, $startSQL, $endSQL);
        $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
    $query = $helper->buildTopCarriersSingleQuery($airport, $startSQL, $endSQL, $direction);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
function getOriginARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildOriginARTCCBreakdownQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
 * Get destination ARTCC breakdown for departures (for chart visualization)
 */
function getDestARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildDestARTCCBreakdownQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
 * Get weight class breakdown by time bin
 */
function getWeightBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL) {
    $query = $helper->buildWeightClassBreakdownQuery($airport, $direction, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "weight_class" => $row['weight_class'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get carrier breakdown by time bin
 */
function getCarrierBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL) {
    $query = $helper->buildCarrierBreakdownQuery($airport, $direction, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "carrier" => $row['carrier'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get equipment breakdown by time bin
 */
function getEquipmentBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL) {
    $query = $helper->buildEquipmentBreakdownQuery($airport, $direction, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "equipment" => $row['equipment'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get flight rule (IFR/VFR) breakdown by time bin
 */
function getRuleBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL) {
    $query = $helper->buildFlightRuleBreakdownQuery($airport, $direction, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "rule" => $row['rule'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get departure fix breakdown by time bin (departures only)
 */
function getDepFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildDepFixBreakdownQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "fix" => $row['fix'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get arrival fix breakdown by time bin (arrivals only)
 */
function getArrFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildArrFixBreakdownQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "fix" => $row['fix'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get DP/SID breakdown by time bin (departures only)
 */
function getDPBreakdown($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildDPBreakdownQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "dp" => $row['dp'],
                "count" => (int)$row['count']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get STAR breakdown by time bin (arrivals only)
 */
function getSTARBreakdown($conn, $helper, $airport, $startSQL, $endSQL) {
    $query = $helper->buildSTARBreakdownQuery($airport, $startSQL, $endSQL);
    $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);
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
                "star" => $row['star'],
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
function getFlightsForTimeBin($conn, $helper, $airport, $timeBin, $direction) {
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
        $query = $helper->buildFlightsForTimeBinQuery($airport, $binStartSQL, $binEndSQL, 'arr');
        $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);

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
                    "status" => $row['phase'] ?? 'unknown',
                    "aircraft" => $row['aircraft_type']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    }

    // Get departures
    if ($direction === 'dep' || $direction === 'both') {
        $query = $helper->buildFlightsForTimeBinQuery($airport, $binStartSQL, $binEndSQL, 'dep');
        $stmt = sqlsrv_query($conn, $query['sql'], $query['params']);

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
                    "status" => $row['phase'] ?? 'unknown',
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

// Note: Status is now derived directly from the 'phase' column in the database
// Valid phases: prefile, taxiing, departed, enroute, descending, arrived, unknown
