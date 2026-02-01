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
require_once("../../load/input.php");

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

// Helper function to generate time bin SQL based on granularity
function getTimeBinSQL($timeColumn, $granularity) {
    // $granularity is in minutes: 15, 30, or 60
    switch ($granularity) {
        case 15:
            // Round down to nearest 15 minutes
            return "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', $timeColumn) / 15) * 15, '2000-01-01')";
        case 30:
            // Round down to nearest 30 minutes
            return "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', $timeColumn) / 30) * 30, '2000-01-01')";
        case 60:
        default:
            // Round down to nearest hour
            return "DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeColumn), 0)";
    }
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
$airport = isset($_GET['airport']) ? get_upper('airport') : '';
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;
$timeBin = isset($_GET['time_bin']) ? trim($_GET['time_bin']) : null; // For drill-down
$granularity = isset($_GET['granularity']) ? (int)$_GET['granularity'] : 60; // Minutes (default 60)
$direction = isset($_GET['direction']) ? get_lower('direction') : 'both';

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

// Debug: Check data availability for columns
$debugQuery = "
    SELECT
        COUNT(*) AS total_flights,
        SUM(CASE WHEN airline_icao IS NOT NULL AND airline_icao != '' THEN 1 ELSE 0 END) AS has_airline,
        SUM(CASE WHEN aircraft_type IS NOT NULL AND aircraft_type != '' THEN 1 ELSE 0 END) AS has_aircraft_type,
        SUM(CASE WHEN fp_rule IS NOT NULL AND fp_rule != '' THEN 1 ELSE 0 END) AS has_fp_rule,
        SUM(CASE WHEN dfix IS NOT NULL AND dfix != '' THEN 1 ELSE 0 END) AS has_dfix,
        SUM(CASE WHEN afix IS NOT NULL AND afix != '' THEN 1 ELSE 0 END) AS has_afix,
        SUM(CASE WHEN dp_name IS NOT NULL AND dp_name != '' THEN 1 ELSE 0 END) AS has_dp,
        SUM(CASE WHEN star_name IS NOT NULL AND star_name != '' THEN 1 ELSE 0 END) AS has_star
    FROM dbo.vw_adl_flights
    WHERE (fp_dest_icao = ? OR fp_dept_icao = ?)
      AND (COALESCE(eta_runway_utc, eta_utc) >= ? OR COALESCE(etd_runway_utc, etd_utc) >= ?)
";
$debugStmt = sqlsrv_query($conn, $debugQuery, [$airport, $airport, $startSQL, $startSQL]);
if ($debugStmt !== false) {
    $debugRow = sqlsrv_fetch_array($debugStmt, SQLSRV_FETCH_ASSOC);
    $response['_debug_data_availability'] = $debugRow;
    sqlsrv_free_stmt($debugStmt);
}

// If time_bin is specified, return detailed flight list for that bin
if ($timeBin !== null) {
    $response['flights'] = getFlightsForTimeBin($conn, $helper, $airport, $timeBin, $direction, $granularity);
} else {
    // Return summary data
    $response['top_origins'] = getTopOrigins($conn, $helper, $airport, $startSQL, $endSQL);
    $response['top_carriers'] = getTopCarriers($conn, $helper, $airport, $startSQL, $endSQL, $direction);
    $response['origin_artcc_breakdown'] = getOriginARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity);

    // New breakdown data for demand chart filters
    $response['dest_artcc_breakdown'] = getDestARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity);
    $response['weight_breakdown'] = getWeightBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity);
    $response['carrier_breakdown'] = getCarrierBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity);
    $response['equipment_breakdown'] = getEquipmentBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity);
    $response['rule_breakdown'] = getRuleBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity);
    $response['dep_fix_breakdown'] = getDepFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity);
    $response['arr_fix_breakdown'] = getArrFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity);
    $response['dp_breakdown'] = getDPBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity);
    $response['star_breakdown'] = getSTARBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity);
}

sqlsrv_close($conn);
echo json_encode($response);

/**
 * Helper function to extract phase breakdown from a row
 */
function extractPhases($row) {
    return [
        "arrived" => (int)($row['phase_arrived'] ?? 0),
        "disconnected" => (int)($row['phase_disconnected'] ?? 0),
        "descending" => (int)($row['phase_descending'] ?? 0),
        "enroute" => (int)($row['phase_enroute'] ?? 0),
        "departed" => (int)($row['phase_departed'] ?? 0),
        "taxiing" => (int)($row['phase_taxiing'] ?? 0),
        "prefile" => (int)($row['phase_prefile'] ?? 0),
        "unknown" => (int)($row['phase_unknown'] ?? 0)
    ];
}

/**
 * SQL for phase aggregation in breakdown queries
 */
function getPhaseAggregationSQL($phaseCol = 'phase') {
    return "
        SUM(CASE WHEN {$phaseCol} = 'arrived' THEN 1 ELSE 0 END) AS phase_arrived,
        SUM(CASE WHEN {$phaseCol} = 'disconnected' THEN 1 ELSE 0 END) AS phase_disconnected,
        SUM(CASE WHEN {$phaseCol} = 'descending' THEN 1 ELSE 0 END) AS phase_descending,
        SUM(CASE WHEN {$phaseCol} = 'enroute' THEN 1 ELSE 0 END) AS phase_enroute,
        SUM(CASE WHEN {$phaseCol} = 'departed' THEN 1 ELSE 0 END) AS phase_departed,
        SUM(CASE WHEN {$phaseCol} = 'taxiing' THEN 1 ELSE 0 END) AS phase_taxiing,
        SUM(CASE WHEN {$phaseCol} = 'prefile' THEN 1 ELSE 0 END) AS phase_prefile,
        SUM(CASE WHEN {$phaseCol} NOT IN ('arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile') OR {$phaseCol} IS NULL THEN 1 ELSE 0 END) AS phase_unknown
    ";
}

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
function getOriginARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeBinSQL = getTimeBinSQL('COALESCE(eta_runway_utc, eta_utc)', $granularity);
    // Override with inline query that includes phase data
    // Use ISNULL to include flights without departure ARTCC as 'UNKN'
    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            ISNULL(fp_dept_artcc, 'UNKN') AS artcc,
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.vw_adl_flights
        WHERE fp_dest_icao = ?
          AND COALESCE(eta_runway_utc, eta_utc) >= ?
          AND COALESCE(eta_runway_utc, eta_utc) < ?
        GROUP BY {$timeBinSQL}, ISNULL(fp_dept_artcc, 'UNKN')
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
                "count" => (int)$row['count'],
                "phases" => extractPhases($row)
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get destination ARTCC breakdown for departures (for chart visualization)
 */
function getDestARTCCBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeBinSQL = getTimeBinSQL('COALESCE(etd_runway_utc, etd_utc)', $granularity);
    // Override with inline query that includes phase data
    // Use ISNULL to include flights without destination ARTCC as 'UNKN'
    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            ISNULL(fp_dest_artcc, 'UNKN') AS artcc,
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.vw_adl_flights
        WHERE fp_dept_icao = ?
          AND COALESCE(etd_runway_utc, etd_utc) >= ?
          AND COALESCE(etd_runway_utc, etd_utc) < ?
        GROUP BY {$timeBinSQL}, ISNULL(fp_dest_artcc, 'UNKN')
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
                "count" => (int)$row['count'],
                "phases" => extractPhases($row)
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get weight class breakdown by time bin
 */
function getWeightBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' :
               ($direction === 'dep' ? 'COALESCE(etd_runway_utc, etd_utc)' : 'COALESCE(eta_runway_utc, eta_utc)');
    $airportCol = $direction === 'arr' ? 'fp_dest_icao' :
                  ($direction === 'dep' ? 'fp_dept_icao' : 'fp_dest_icao');

    if ($direction === 'both') {
        $timeBinSQL = getTimeBinSQL('op_time', $granularity);
        $sql = "
            WITH Combined AS (
                SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, weight_class, phase
                FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                UNION ALL
                SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, weight_class, phase
                FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
            )
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(weight_class, 'UNKNOWN') AS weight_class,
                COUNT(*) AS count,
                {$phaseAgg}
            FROM Combined
            WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
            GROUP BY {$timeBinSQL}, COALESCE(weight_class, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $airport, $startSQL, $endSQL];
    } else {
        $timeBinSQL = getTimeBinSQL($timeCol, $granularity);
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(weight_class, 'UNKNOWN') AS weight_class,
                COUNT(*) AS count,
                {$phaseAgg}
            FROM dbo.vw_adl_flights
            WHERE $airportCol = ?
              AND $timeCol IS NOT NULL
              AND $timeCol >= ?
              AND $timeCol < ?
            GROUP BY {$timeBinSQL}, COALESCE(weight_class, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $startSQL, $endSQL];
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
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
                "count" => (int)$row['count'],
                "phases" => extractPhases($row)
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    return $results;
}

/**
 * Get carrier breakdown by time bin
 */
function getCarrierBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity = 60) {
    // Phase aggregation SQL for flight status filtering
    $phaseAgg = "
        SUM(CASE WHEN phase = 'arrived' THEN 1 ELSE 0 END) AS phase_arrived,
        SUM(CASE WHEN phase = 'disconnected' THEN 1 ELSE 0 END) AS phase_disconnected,
        SUM(CASE WHEN phase = 'descending' THEN 1 ELSE 0 END) AS phase_descending,
        SUM(CASE WHEN phase = 'enroute' THEN 1 ELSE 0 END) AS phase_enroute,
        SUM(CASE WHEN phase = 'departed' THEN 1 ELSE 0 END) AS phase_departed,
        SUM(CASE WHEN phase = 'taxiing' THEN 1 ELSE 0 END) AS phase_taxiing,
        SUM(CASE WHEN phase = 'prefile' THEN 1 ELSE 0 END) AS phase_prefile,
        SUM(CASE WHEN phase NOT IN ('arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile') OR phase IS NULL THEN 1 ELSE 0 END) AS phase_unknown
    ";

    $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' :
               ($direction === 'dep' ? 'COALESCE(etd_runway_utc, etd_utc)' : 'COALESCE(eta_runway_utc, eta_utc)');
    $airportCol = $direction === 'arr' ? 'fp_dest_icao' :
                  ($direction === 'dep' ? 'fp_dept_icao' : 'fp_dest_icao');

    if ($direction === 'both') {
        $timeBinSQL = getTimeBinSQL('op_time', $granularity);
        $sql = "
            WITH Combined AS (
                SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, airline_icao, phase
                FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                UNION ALL
                SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, airline_icao, phase
                FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
            )
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(airline_icao, 'UNKNOWN') AS carrier,
                COUNT(*) AS count,
                {$phaseAgg}
            FROM Combined
            WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
            GROUP BY {$timeBinSQL}, COALESCE(airline_icao, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $airport, $startSQL, $endSQL];
    } else {
        $timeBinSQL = getTimeBinSQL($timeCol, $granularity);
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(airline_icao, 'UNKNOWN') AS carrier,
                COUNT(*) AS count,
                {$phaseAgg}
            FROM dbo.vw_adl_flights
            WHERE $airportCol = ?
              AND $timeCol IS NOT NULL
              AND $timeCol >= ?
              AND $timeCol < ?
            GROUP BY {$timeBinSQL}, COALESCE(airline_icao, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $startSQL, $endSQL];
    }

    error_log("getCarrierBreakdown: direction=$direction, sql_length=" . strlen($sql) . ", params=" . json_encode($params));

    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getCarrierBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

    $rowCount = 0;
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rowCount++;
            $timeBin = $row['time_bin'];
            if ($timeBin instanceof DateTime) {
                $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
            }

            if (!isset($results[$timeBin])) {
                $results[$timeBin] = [];
            }
            $results[$timeBin][] = [
                "carrier" => $row['carrier'],
                "count" => (int)$row['count'],
                "phases" => extractPhases($row)
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    error_log("getCarrierBreakdown: returned $rowCount rows, " . count($results) . " time bins");
    return $results;
}

/**
 * Get equipment breakdown by time bin
 */
function getEquipmentBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' :
               ($direction === 'dep' ? 'COALESCE(etd_runway_utc, etd_utc)' : 'COALESCE(eta_runway_utc, eta_utc)');
    $airportCol = $direction === 'arr' ? 'fp_dest_icao' :
                  ($direction === 'dep' ? 'fp_dept_icao' : 'fp_dest_icao');

    if ($direction === 'both') {
        $timeBinSQL = getTimeBinSQL('op_time', $granularity);
        $sql = "
            WITH Combined AS (
                SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, aircraft_type, phase
                FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                UNION ALL
                SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, aircraft_type, phase
                FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
            )
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(aircraft_type, 'UNKNOWN') AS equipment,
                COUNT(*) AS count,
                {$phaseAgg}
            FROM Combined
            WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
            GROUP BY {$timeBinSQL}, COALESCE(aircraft_type, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $airport, $startSQL, $endSQL];
    } else {
        $timeBinSQL = getTimeBinSQL($timeCol, $granularity);
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(aircraft_type, 'UNKNOWN') AS equipment,
                COUNT(*) AS count,
                {$phaseAgg}
            FROM dbo.vw_adl_flights
            WHERE $airportCol = ?
              AND $timeCol IS NOT NULL
              AND $timeCol >= ?
              AND $timeCol < ?
            GROUP BY {$timeBinSQL}, COALESCE(aircraft_type, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $startSQL, $endSQL];
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getEquipmentBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

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
            "count" => (int)$row['count'],
            "phases" => extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $results;
}

/**
 * Get flight rule (IFR/VFR) breakdown by time bin
 */
function getRuleBreakdown($conn, $helper, $airport, $direction, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' :
               ($direction === 'dep' ? 'COALESCE(etd_runway_utc, etd_utc)' : 'COALESCE(eta_runway_utc, eta_utc)');
    $airportCol = $direction === 'arr' ? 'fp_dest_icao' :
                  ($direction === 'dep' ? 'fp_dept_icao' : 'fp_dest_icao');

    if ($direction === 'both') {
        $timeBinSQL = getTimeBinSQL('op_time', $granularity);
        $sql = "
            WITH Combined AS (
                SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, fp_rule, phase
                FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                UNION ALL
                SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, fp_rule, phase
                FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
            )
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(fp_rule, 'UNKNOWN') AS [rule],
                COUNT(*) AS count,
                {$phaseAgg}
            FROM Combined
            WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
            GROUP BY {$timeBinSQL}, COALESCE(fp_rule, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $airport, $startSQL, $endSQL];
    } else {
        $timeBinSQL = getTimeBinSQL($timeCol, $granularity);
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(fp_rule, 'UNKNOWN') AS [rule],
                COUNT(*) AS count,
                {$phaseAgg}
            FROM dbo.vw_adl_flights
            WHERE $airportCol = ?
              AND $timeCol IS NOT NULL
              AND $timeCol >= ?
              AND $timeCol < ?
            GROUP BY {$timeBinSQL}, COALESCE(fp_rule, 'UNKNOWN')
            ORDER BY time_bin, count DESC
        ";
        $params = [$airport, $startSQL, $endSQL];
    }

    error_log("getRuleBreakdown: direction=$direction, params=" . json_encode($params));
    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getRuleBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

    $rowCount = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rowCount++;
        $timeBin = $row['time_bin'];
        if ($timeBin instanceof DateTime) {
            $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
        }

        if (!isset($results[$timeBin])) {
            $results[$timeBin] = [];
        }
        $results[$timeBin][] = [
            "rule" => $row['rule'],
            "count" => (int)$row['count'],
            "phases" => extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);

    error_log("getRuleBreakdown: returned $rowCount rows, " . count($results) . " time bins");
    return $results;
}

/**
 * Get departure fix breakdown by time bin (departures only)
 */
function getDepFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeBinSQL = getTimeBinSQL('COALESCE(etd_runway_utc, etd_utc)', $granularity);
    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            COALESCE(dfix, 'UNKNOWN') AS fix,
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.vw_adl_flights
        WHERE fp_dept_icao = ?
          AND COALESCE(etd_runway_utc, etd_utc) IS NOT NULL
          AND COALESCE(etd_runway_utc, etd_utc) >= ?
          AND COALESCE(etd_runway_utc, etd_utc) < ?
        GROUP BY {$timeBinSQL}, COALESCE(dfix, 'UNKNOWN')
        ORDER BY time_bin, count DESC
    ";
    $params = [$airport, $startSQL, $endSQL];

    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getDepFixBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

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
            "count" => (int)$row['count'],
            "phases" => extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $results;
}

/**
 * Get arrival fix breakdown by time bin (arrivals only)
 */
function getArrFixBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeBinSQL = getTimeBinSQL('COALESCE(eta_runway_utc, eta_utc)', $granularity);
    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            COALESCE(afix, 'UNKNOWN') AS fix,
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.vw_adl_flights
        WHERE fp_dest_icao = ?
          AND COALESCE(eta_runway_utc, eta_utc) IS NOT NULL
          AND COALESCE(eta_runway_utc, eta_utc) >= ?
          AND COALESCE(eta_runway_utc, eta_utc) < ?
        GROUP BY {$timeBinSQL}, COALESCE(afix, 'UNKNOWN')
        ORDER BY time_bin, count DESC
    ";
    $params = [$airport, $startSQL, $endSQL];

    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getArrFixBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

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
            "count" => (int)$row['count'],
            "phases" => extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $results;
}

/**
 * Get DP/SID breakdown by time bin (departures only)
 * Groups procedures by base name (strips trailing version digits, e.g., BNFSH2 + BNFSH3 -> BNFSH#)
 */
function getDPBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeBinSQL = getTimeBinSQL('COALESCE(etd_runway_utc, etd_utc)', $granularity);
    // Strip trailing digits from procedure names to group versions together
    // BNFSH2 + BNFSH3 -> BNFSH#
    $dpBaseSQL = "CASE
        WHEN dp_name IS NULL THEN 'UNKNOWN'
        WHEN dp_name LIKE '%[0-9]' THEN LEFT(dp_name, LEN(dp_name) - 1) + '#'
        ELSE dp_name
    END";
    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            {$dpBaseSQL} AS dp,
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.vw_adl_flights
        WHERE fp_dept_icao = ?
          AND COALESCE(etd_runway_utc, etd_utc) IS NOT NULL
          AND COALESCE(etd_runway_utc, etd_utc) >= ?
          AND COALESCE(etd_runway_utc, etd_utc) < ?
        GROUP BY {$timeBinSQL}, {$dpBaseSQL}
        ORDER BY time_bin, count DESC
    ";
    $params = [$airport, $startSQL, $endSQL];

    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getDPBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

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
            "count" => (int)$row['count'],
            "phases" => extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $results;
}

/**
 * Get STAR breakdown by time bin (arrivals only)
 * Groups procedures by base name (strips trailing version digits, e.g., BNFSH2 + BNFSH3 -> BNFSH#)
 */
function getSTARBreakdown($conn, $helper, $airport, $startSQL, $endSQL, $granularity = 60) {
    $phaseAgg = getPhaseAggregationSQL();
    $timeBinSQL = getTimeBinSQL('COALESCE(eta_runway_utc, eta_utc)', $granularity);
    // Strip trailing digits from procedure names to group versions together
    // BNFSH2 + BNFSH3 -> BNFSH#
    $starBaseSQL = "CASE
        WHEN star_name IS NULL THEN 'UNKNOWN'
        WHEN star_name LIKE '%[0-9]' THEN LEFT(star_name, LEN(star_name) - 1) + '#'
        ELSE star_name
    END";
    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            {$starBaseSQL} AS star,
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.vw_adl_flights
        WHERE fp_dest_icao = ?
          AND COALESCE(eta_runway_utc, eta_utc) IS NOT NULL
          AND COALESCE(eta_runway_utc, eta_utc) >= ?
          AND COALESCE(eta_runway_utc, eta_utc) < ?
        GROUP BY {$timeBinSQL}, {$starBaseSQL}
        ORDER BY time_bin, count DESC
    ";
    $params = [$airport, $startSQL, $endSQL];

    $stmt = sqlsrv_query($conn, $sql, $params);
    $results = [];

    if ($stmt === false) {
        error_log("getSTARBreakdown query failed: " . adl_sql_error_message());
        return $results;
    }

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
            "count" => (int)$row['count'],
            "phases" => extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $results;
}

/**
 * Get detailed flight list for a specific time bin (drill-down)
 * @param int $granularity - Bin size in minutes (15, 30, or 60)
 */
function getFlightsForTimeBin($conn, $helper, $airport, $timeBin, $direction, $granularity = 60) {
    // Validate granularity (default to 60 if invalid)
    if (!in_array($granularity, [15, 30, 60])) {
        $granularity = 60;
    }

    // Parse the time bin to get start and end based on granularity
    try {
        $binStart = new DateTime($timeBin, new DateTimeZone('UTC'));
        $binEnd = (clone $binStart)->modify("+{$granularity} minutes");
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
                    "dest_artcc" => $row['dest_artcc'] ?? null,
                    "time" => $eta,
                    "direction" => "arrival",
                    "status" => $row['phase'] ?? 'unknown',
                    "aircraft" => $row['aircraft_type'],
                    "carrier" => $row['carrier'] ?? null,
                    "weight_class" => $row['weight_class'] ?? null,
                    "flight_rules" => $row['flight_rules'] ?? null,
                    "dfix" => $row['dfix'] ?? null,
                    "afix" => $row['afix'] ?? null,
                    "dp_name" => $row['dp_name'] ?? null,
                    "star_name" => $row['star_name'] ?? null
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
                    "origin_artcc" => $row['origin_artcc'] ?? null,
                    "dest_artcc" => $row['dest_artcc'],
                    "time" => $etd,
                    "direction" => "departure",
                    "status" => $row['phase'] ?? 'unknown',
                    "aircraft" => $row['aircraft_type'],
                    "carrier" => $row['carrier'] ?? null,
                    "weight_class" => $row['weight_class'] ?? null,
                    "flight_rules" => $row['flight_rules'] ?? null,
                    "dfix" => $row['dfix'] ?? null,
                    "afix" => $row['afix'] ?? null,
                    "dp_name" => $row['dp_name'] ?? null,
                    "star_name" => $row['star_name'] ?? null
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
