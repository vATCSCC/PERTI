<?php
/**
 * Facility Summary API — breakdown data for TRACON/ARTCC/Group demand
 * Mirrors api/demand/summary.php but scoped to facility boundaries.
 *
 * Parameters:
 *   type       — tracon, artcc, group (required)
 *   code       — facility code e.g. PCT, ZDC (required)
 *   mode       — airport (default) or crossing
 *   direction  — arr, dep, both (default); thru only in crossing mode
 *   granularity — integer minutes: 15, 30, 60 (default 60)
 *   start, end — ISO 8601 time range
 *   time_bin   — ISO 8601 timestamp for drill-down (optional)
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . "/../../load/config.php");
require_once(__DIR__ . "/../../load/input.php");
require_once(__DIR__ . "/../../load/cache.php");

// Check ADL database configuration
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

function fs_sql_error_message() {
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

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "sqlsrv extension not available."]);
    exit;
}

// --- Parameters ---
$type = isset($_GET['type']) ? get_lower('type') : '';
$code = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';
$mode = isset($_GET['mode']) ? get_lower('mode') : 'airport';
$direction = isset($_GET['direction']) ? get_lower('direction') : 'both';
$granularity = isset($_GET['granularity']) ? (int)$_GET['granularity'] : 60;
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;
$timeBin = isset($_GET['time_bin']) ? trim($_GET['time_bin']) : null;

// --- Validation ---
if (!in_array($type, ['tracon', 'artcc', 'group'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid type. Must be tracon, artcc, or group."]);
    exit;
}
if (empty($code)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Missing code parameter."]);
    exit;
}
if (!in_array($mode, ['airport', 'crossing'])) {
    $mode = 'airport';
}
if (!in_array($direction, ['arr', 'dep', 'both', 'thru'])) {
    $direction = 'both';
}
if ($direction === 'thru' && $mode !== 'crossing') {
    $direction = 'both';
}
if (!in_array($granularity, [15, 30, 60])) {
    $granularity = 60;
}

// --- Time range ---
$now = new DateTime('now', new DateTimeZone('UTC'));
if ($start !== null) {
    try { $startDt = new DateTime($start, new DateTimeZone('UTC')); }
    catch (Exception $e) { $startDt = (clone $now)->modify('-1 hour'); }
} else {
    $startDt = (clone $now)->modify('-1 hour');
}
if ($end !== null) {
    try { $endDt = new DateTime($end, new DateTimeZone('UTC')); }
    catch (Exception $e) { $endDt = (clone $now)->modify('+6 hours'); }
} else {
    $endDt = (clone $now)->modify('+6 hours');
}

$startSQL = $startDt->format('Y-m-d H:i:s');
$endSQL = $endDt->format('Y-m-d H:i:s');

// --- Group resolution ---
$group = null;
$modeFallback = false;
if ($type === 'group') {
    $group = fs_resolveFirGroup($code);
    if (!$group) {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Group not found: $code"]);
        exit;
    }
    // Time range cap for wildcard groups
    if (isset($group['patterns']) && in_array('*', $group['patterns'])) {
        $rangeSecs = $endDt->getTimestamp() - $startDt->getTimestamp();
        if ($rangeSecs > 4 * 3600) {
            $endDt = (clone $startDt)->modify('+4 hours');
            $endSQL = $endDt->format('Y-m-d H:i:s');
        }
    }
    // Pattern groups can't use crossing mode
    if ($mode === 'crossing' && !isset($group['members'])) {
        $mode = 'airport';
        $modeFallback = true;
    }
}

// --- Cache check ---
$ttl = ($type === 'group') ? 60 : 30;
$cacheKey = demand_cache_key('facility_summary', [
    'type' => $type, 'code' => $code, 'mode' => $mode,
    'direction' => $direction, 'granularity' => $granularity,
    'start' => $startSQL, 'end' => $endSQL, 'time_bin' => $timeBin
]);
$cached = apcu_cache_get($cacheKey);
if ($cached !== null) {
    header('X-Cache: HIT');
    $clientHash = isset($_SERVER['HTTP_X_IF_DATA_HASH']) ? $_SERVER['HTTP_X_IF_DATA_HASH'] : null;
    if ($clientHash && isset($cached['data_hash']) && $clientHash === $cached['data_hash']) {
        echo json_encode(["unchanged" => true, "data_hash" => $cached['data_hash']]);
    } else {
        echo json_encode($cached);
    }
    exit;
}

// --- Database connection ---
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];
$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Unable to connect to ADL database.", "sql_error" => fs_sql_error_message()]);
    exit;
}


// ===========================================================================
// HELPER FUNCTIONS
// ===========================================================================

function fs_resolveFirGroup($code, $depth = 0) {
    if ($depth > 5) return null;
    static $firData = null;
    if ($firData === null) {
        $firFile = __DIR__ . '/../../assets/data/fir_tiers.json';
        if (!file_exists($firFile)) return null;
        $firData = json_decode(file_get_contents($firFile), true);
    }
    foreach (['regional', 'byIcaoPrefix', 'global'] as $section) {
        if (!isset($firData[$section])) continue;
        foreach ($firData[$section] as $key => $grp) {
            if (isset($grp['code']) && $grp['code'] === $code) {
                if (isset($grp['alias'])) {
                    return fs_resolveFirGroup($grp['alias'], $depth + 1);
                }
                return $grp;
            }
        }
    }
    return null;
}

function fs_getTimeBinSQL($timeExpr, $granularity) {
    switch ($granularity) {
        case 15:
            return "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 15) * 15, '2000-01-01')";
        case 30:
            return "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 30) * 30, '2000-01-01')";
        case 60:
        default:
            return "DATEADD(HOUR, DATEDIFF(HOUR, 0, {$timeExpr}), 0)";
    }
}

function fs_getPhaseAggregationSQL($phaseCol = 'phase') {
    return "
        SUM(CASE WHEN {$phaseCol} = 'arrived' THEN 1 ELSE 0 END) AS phase_arrived,
        SUM(CASE WHEN {$phaseCol} = 'disconnected' THEN 1 ELSE 0 END) AS phase_disconnected,
        SUM(CASE WHEN {$phaseCol} = 'descending' THEN 1 ELSE 0 END) AS phase_descending,
        SUM(CASE WHEN {$phaseCol} = 'enroute' THEN 1 ELSE 0 END) AS phase_enroute,
        SUM(CASE WHEN {$phaseCol} = 'departed' THEN 1 ELSE 0 END) AS phase_departed,
        SUM(CASE WHEN {$phaseCol} = 'taxiing' THEN 1 ELSE 0 END) AS phase_taxiing,
        SUM(CASE WHEN {$phaseCol} = 'prefile' THEN 1 ELSE 0 END) AS phase_prefile,
        SUM(CASE WHEN {$phaseCol} NOT IN ('arrived','disconnected','descending','enroute','departed','taxiing','prefile') OR {$phaseCol} IS NULL THEN 1 ELSE 0 END) AS phase_unknown
    ";
}

function fs_extractPhases($row) {
    return [
        "arrived"      => (int)($row['phase_arrived'] ?? 0),
        "disconnected" => (int)($row['phase_disconnected'] ?? 0),
        "descending"   => (int)($row['phase_descending'] ?? 0),
        "enroute"      => (int)($row['phase_enroute'] ?? 0),
        "departed"     => (int)($row['phase_departed'] ?? 0),
        "taxiing"      => (int)($row['phase_taxiing'] ?? 0),
        "prefile"      => (int)($row['phase_prefile'] ?? 0),
        "unknown"      => (int)($row['phase_unknown'] ?? 0)
    ];
}

function fs_getNormalizedFrom($includeAircraft = false) {
    $sql = "FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid";
    if ($includeAircraft) {
        $sql .= "\n        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid";
    }
    return $sql;
}

/**
 * Build WHERE clause for facility filter on a given direction side.
 * Returns ['clause' => SQL, 'params' => array] or null.
 */
function fs_buildFacilityWhere($type, $code, $group, $directionSide) {
    if ($type === 'tracon') {
        $col = $directionSide === 'arr' ? 'fp.fp_dest_tracon' : 'fp.fp_dept_tracon';
        return ['clause' => "$col = ?", 'params' => [$code]];
    }
    if ($type === 'artcc') {
        $col = $directionSide === 'arr' ? 'fp.fp_dest_artcc' : 'fp.fp_dept_artcc';
        return ['clause' => "$col = ?", 'params' => [$code]];
    }
    if ($type === 'group' && $group) {
        if (isset($group['members'])) {
            $placeholders = implode(',', array_fill(0, count($group['members']), '?'));
            $col = $directionSide === 'arr' ? 'fp.fp_dest_artcc' : 'fp.fp_dept_artcc';
            return ['clause' => "$col IN ($placeholders)", 'params' => $group['members']];
        }
        if (isset($group['patterns'])) {
            $likes = [];
            $params = [];
            $col = $directionSide === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
            foreach ($group['patterns'] as $pattern) {
                $likes[] = "$col LIKE ?";
                $params[] = str_replace('*', '%', $pattern);
            }
            $clause = '(' . implode(' OR ', $likes) . ')';
            if (!empty($group['exclude'])) {
                $exPlaceholders = implode(',', array_fill(0, count($group['exclude']), '?'));
                $clause .= " AND $col NOT IN ($exPlaceholders)";
                $params = array_merge($params, $group['exclude']);
            }
            return ['clause' => $clause, 'params' => $params];
        }
    }
    return null;
}

/**
 * Build crossing mode WHERE clause.
 */
function fs_buildCrossingWhere($type, $code, $group) {
    $boundaryType = ($type === 'tracon') ? 'TRACON' : 'ARTCC';
    if ($type === 'group' && $group && isset($group['members'])) {
        $placeholders = implode(',', array_fill(0, count($group['members']), '?'));
        return [
            'clause' => "cx.boundary_code IN ($placeholders)",
            'params' => $group['members'],
            'boundary_type' => $boundaryType
        ];
    }
    return [
        'clause' => "cx.boundary_code = ?",
        'params' => [$code],
        'boundary_type' => $boundaryType
    ];
}


// ===========================================================================
// BREAKDOWN QUERY FUNCTIONS
// ===========================================================================

/**
 * Run a breakdown query for airport mode (non-crossing).
 * Handles 'arr', 'dep', and 'both' directions.
 */
function fs_runAirportBreakdown($conn, $type, $code, $group, $direction, $groupByCol, $aliasName, $granularity, $startSQL, $endSQL, $includeAircraft = false, $coalesce = "'UNKNOWN'") {
    $fromBase = fs_getNormalizedFrom($includeAircraft);
    $groupExpr = $coalesce ? "COALESCE($groupByCol, $coalesce)" : $groupByCol;

    if ($direction === 'both') {
        $arrWhere = fs_buildFacilityWhere($type, $code, $group, 'arr');
        $depWhere = fs_buildFacilityWhere($type, $code, $group, 'dep');
        if (!$arrWhere || !$depWhere) return [];

        $phaseAgg = fs_getPhaseAggregationSQL();
        $timeBinSQL = fs_getTimeBinSQL('op_time', $granularity);

        $sql = "
            WITH Combined AS (
                SELECT COALESCE(t.eta_runway_utc, t.eta_utc) AS op_time, $groupByCol AS dim_val, c.phase
                $fromBase
                WHERE {$arrWhere['clause']}
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) IS NOT NULL
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ? AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                UNION ALL
                SELECT COALESCE(t.etd_runway_utc, t.etd_utc) AS op_time, $groupByCol AS dim_val, c.phase
                $fromBase
                WHERE {$depWhere['clause']}
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) IS NOT NULL
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ? AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
            )
            SELECT
                {$timeBinSQL} AS time_bin,
                COALESCE(dim_val, $coalesce) AS {$aliasName},
                COUNT(*) AS count,
                {$phaseAgg}
            FROM Combined
            WHERE op_time IS NOT NULL
            GROUP BY {$timeBinSQL}, COALESCE(dim_val, $coalesce)
            ORDER BY time_bin, count DESC
        ";
        $params = array_merge($arrWhere['params'], [$startSQL, $endSQL], $depWhere['params'], [$startSQL, $endSQL]);
    } else {
        $dirSide = ($direction === 'dep') ? 'dep' : 'arr';
        $facWhere = fs_buildFacilityWhere($type, $code, $group, $dirSide);
        if (!$facWhere) return [];

        $timeCol = ($dirSide === 'arr')
            ? 'COALESCE(t.eta_runway_utc, t.eta_utc)'
            : 'COALESCE(t.etd_runway_utc, t.etd_utc)';

        $phaseAgg = fs_getPhaseAggregationSQL('c.phase');
        $timeBinSQL = fs_getTimeBinSQL($timeCol, $granularity);

        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                {$groupExpr} AS {$aliasName},
                COUNT(*) AS count,
                {$phaseAgg}
            $fromBase
            WHERE {$facWhere['clause']}
              AND $timeCol IS NOT NULL
              AND $timeCol >= ? AND $timeCol < ?
            GROUP BY {$timeBinSQL}, {$groupExpr}
            ORDER BY time_bin, count DESC
        ";
        $params = array_merge($facWhere['params'], [$startSQL, $endSQL]);
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        error_log("facility_summary breakdown ($aliasName) failed: " . fs_sql_error_message());
        return [];
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tb = $row['time_bin'];
        if ($tb instanceof DateTime) {
            $tb = $tb->format("Y-m-d\\TH:i:s\\Z");
        }
        if (!isset($results[$tb])) {
            $results[$tb] = [];
        }
        $results[$tb][] = [
            $aliasName => $row[$aliasName],
            "count" => (int)$row['count'],
            "phases" => fs_extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

/**
 * Run a breakdown query for crossing mode.
 */
function fs_runCrossingBreakdown($conn, $type, $code, $group, $direction, $groupByCol, $aliasName, $granularity, $startSQL, $endSQL, $includeAircraft = false, $coalesce = "'UNKNOWN'") {
    $crossingWhere = fs_buildCrossingWhere($type, $code, $group);
    $groupExpr = $coalesce ? "COALESCE($groupByCol, $coalesce)" : $groupByCol;
    $crossingTimeExpr = 'cx.planned_entry_utc';
    $timeBinSQL = fs_getTimeBinSQL($crossingTimeExpr, $granularity);
    $phaseAgg = fs_getPhaseAggregationSQL('c.phase');

    $aircraftJoin = $includeAircraft ? "LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid" : "";

    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            {$groupExpr} AS {$aliasName},
            COUNT(*) AS count,
            {$phaseAgg}
        FROM dbo.adl_flight_planned_crossings cx
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = cx.flight_uid
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = cx.flight_uid
        INNER JOIN dbo.adl_flight_times t ON t.flight_uid = cx.flight_uid
        {$aircraftJoin}
        WHERE {$crossingWhere['clause']}
          AND cx.boundary_type = ?
          AND cx.crossing_type IN ('ENTRY', 'CROSS')
          AND {$crossingTimeExpr} >= ?
          AND {$crossingTimeExpr} < ?
        GROUP BY {$timeBinSQL}, {$groupExpr}
        ORDER BY time_bin, count DESC
    ";
    $params = array_merge($crossingWhere['params'], [$crossingWhere['boundary_type'], $startSQL, $endSQL]);

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        error_log("facility_summary crossing breakdown ($aliasName) failed: " . fs_sql_error_message());
        return [];
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tb = $row['time_bin'];
        if ($tb instanceof DateTime) {
            $tb = $tb->format("Y-m-d\\TH:i:s\\Z");
        }
        if (!isset($results[$tb])) {
            $results[$tb] = [];
        }
        $results[$tb][] = [
            $aliasName => $row[$aliasName],
            "count" => (int)$row['count'],
            "phases" => fs_extractPhases($row)
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

/**
 * Dispatch a breakdown query to airport or crossing mode.
 */
function fs_getBreakdown($conn, $type, $code, $group, $mode, $direction, $groupByCol, $aliasName, $granularity, $startSQL, $endSQL, $includeAircraft = false, $coalesce = "'UNKNOWN'") {
    if ($mode === 'crossing') {
        return fs_runCrossingBreakdown($conn, $type, $code, $group, $direction, $groupByCol, $aliasName, $granularity, $startSQL, $endSQL, $includeAircraft, $coalesce);
    }
    return fs_runAirportBreakdown($conn, $type, $code, $group, $direction, $groupByCol, $aliasName, $granularity, $startSQL, $endSQL, $includeAircraft, $coalesce);
}

/**
 * Get top items by total count from a breakdown result.
 */
function fs_getTopFromBreakdown($breakdown, $key, $limit = 10) {
    $totals = [];
    foreach ($breakdown as $bin => $items) {
        foreach ($items as $item) {
            $val = $item[$key] ?? 'UNKNOWN';
            if (!isset($totals[$val])) $totals[$val] = 0;
            $totals[$val] += $item['count'];
        }
    }
    arsort($totals);
    $top = [];
    foreach (array_slice($totals, 0, $limit, true) as $val => $count) {
        $top[] = [$key => $val, 'count' => $count];
    }
    return $top;
}


// ===========================================================================
// DRILL-DOWN FUNCTION
// ===========================================================================

/**
 * Get individual flights for a specific time bin within a facility.
 */
function fs_getFlightsForTimeBin($conn, $type, $code, $group, $mode, $direction, $timeBin, $granularity) {
    $flights = [];

    try {
        $binStart = new DateTime($timeBin, new DateTimeZone('UTC'));
        $binEnd = (clone $binStart)->modify("+{$granularity} minutes");
    } catch (Exception $e) {
        return $flights;
    }

    $binStartSQL = $binStart->format('Y-m-d H:i:s');
    $binEndSQL = $binEnd->format('Y-m-d H:i:s');

    $selectCols = "c.callsign,
        fp.fp_dept_icao AS origin,
        fp.fp_dest_icao AS destination,
        fp.fp_dept_artcc AS origin_artcc,
        fp.fp_dest_artcc AS dest_artcc,
        c.phase,
        fp.aircraft_type,
        ac.airline_icao AS carrier,
        ac.weight_class,
        fp.fp_rule AS flight_rules,
        fp.dfix,
        fp.afix,
        fp.dp_name,
        fp.star_name";

    if ($mode === 'crossing') {
        $crossingWhere = fs_buildCrossingWhere($type, $code, $group);
        $crossingTimeExpr = 'cx.planned_entry_utc';

        $sql = "
            SELECT {$selectCols},
                {$crossingTimeExpr} AS op_time,
                cx.crossing_type AS direction_type
            FROM dbo.adl_flight_planned_crossings cx
            INNER JOIN dbo.adl_flight_core c ON c.flight_uid = cx.flight_uid
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = cx.flight_uid
            INNER JOIN dbo.adl_flight_times t ON t.flight_uid = cx.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
            WHERE {$crossingWhere['clause']}
              AND cx.boundary_type = ?
              AND cx.crossing_type IN ('ENTRY', 'CROSS')
              AND {$crossingTimeExpr} >= ?
              AND {$crossingTimeExpr} < ?
            ORDER BY {$crossingTimeExpr}
        ";
        $params = array_merge($crossingWhere['params'], [$crossingWhere['boundary_type'], $binStartSQL, $binEndSQL]);

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $time = $row['op_time'];
                if ($time instanceof DateTime) {
                    $time = $time->format("Y-m-d\\TH:i:s\\Z");
                }
                $dirType = strtolower($row['direction_type'] ?? '');
                $flights[] = fs_buildFlightRecord($row, $time, $dirType === 'entry' ? 'arrival' : 'crossing');
            }
            sqlsrv_free_stmt($stmt);
        }
    } else {
        $fromBase = "FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid";

        // Arrivals
        if ($direction === 'arr' || $direction === 'both') {
            $facWhere = fs_buildFacilityWhere($type, $code, $group, 'arr');
            if ($facWhere) {
                $arrTimeCol = 'COALESCE(t.eta_runway_utc, t.eta_utc)';
                $sql = "SELECT {$selectCols}, {$arrTimeCol} AS op_time
                    $fromBase
                    WHERE {$facWhere['clause']}
                      AND {$arrTimeCol} >= ? AND {$arrTimeCol} < ?
                    ORDER BY {$arrTimeCol}";
                $params = array_merge($facWhere['params'], [$binStartSQL, $binEndSQL]);
                $stmt = sqlsrv_query($conn, $sql, $params);
                if ($stmt !== false) {
                    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                        $time = $row['op_time'];
                        if ($time instanceof DateTime) {
                            $time = $time->format("Y-m-d\\TH:i:s\\Z");
                        }
                        $flights[] = fs_buildFlightRecord($row, $time, 'arrival');
                    }
                    sqlsrv_free_stmt($stmt);
                }
            }
        }

        // Departures
        if ($direction === 'dep' || $direction === 'both') {
            $facWhere = fs_buildFacilityWhere($type, $code, $group, 'dep');
            if ($facWhere) {
                $depTimeCol = 'COALESCE(t.etd_runway_utc, t.etd_utc)';
                $sql = "SELECT {$selectCols}, {$depTimeCol} AS op_time
                    $fromBase
                    WHERE {$facWhere['clause']}
                      AND {$depTimeCol} >= ? AND {$depTimeCol} < ?
                    ORDER BY {$depTimeCol}";
                $params = array_merge($facWhere['params'], [$binStartSQL, $binEndSQL]);
                $stmt = sqlsrv_query($conn, $sql, $params);
                if ($stmt !== false) {
                    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                        $time = $row['op_time'];
                        if ($time instanceof DateTime) {
                            $time = $time->format("Y-m-d\\TH:i:s\\Z");
                        }
                        $flights[] = fs_buildFlightRecord($row, $time, 'departure');
                    }
                    sqlsrv_free_stmt($stmt);
                }
            }
        }
    }

    usort($flights, function($a, $b) {
        return strcmp($a['time'] ?? '', $b['time'] ?? '');
    });

    return $flights;
}

function fs_buildFlightRecord($row, $time, $direction) {
    return [
        "callsign"     => $row['callsign'],
        "origin"       => $row['origin'],
        "destination"  => $row['destination'],
        "origin_artcc" => $row['origin_artcc'] ?? null,
        "dest_artcc"   => $row['dest_artcc'] ?? null,
        "time"         => $time,
        "direction"    => $direction,
        "status"       => $row['phase'] ?? 'unknown',
        "aircraft"     => $row['aircraft_type'] ?? null,
        "carrier"      => $row['carrier'] ?? null,
        "weight_class" => $row['weight_class'] ?? null,
        "flight_rules" => $row['flight_rules'] ?? null,
        "dfix"         => $row['dfix'] ?? null,
        "afix"         => $row['afix'] ?? null,
        "dp_name"      => $row['dp_name'] ?? null,
        "star_name"    => $row['star_name'] ?? null,
    ];
}


// ===========================================================================
// MAIN EXECUTION
// ===========================================================================

// --- Drill-down mode ---
if ($timeBin !== null) {
    $flights = fs_getFlightsForTimeBin($conn, $type, $code, $group, $mode, $direction, $timeBin, $granularity);
    $response = ['success' => true, 'flights' => $flights];
    $jsonResponse = json_encode($response);
    $dataHash = md5($jsonResponse);
    $response['data_hash'] = $dataHash;
    apcu_cache_set($cacheKey, $response, $ttl);
    header('X-Cache: MISS');
    echo json_encode($response);
    sqlsrv_close($conn);
    exit;
}

// --- Summary mode: run all 10 breakdown queries ---
$response = [
    'success' => true,
    'facility' => ['type' => $type, 'code' => $code, 'mode' => $mode, 'mode_fallback' => $modeFallback],
    'time_range' => ['start' => $startSQL, 'end' => $endSQL],
];

// DP/STAR use SQL CASE to strip trailing version digits (matches summary.php pattern)
$dpBaseSQL = "CASE WHEN fp.dp_name IS NULL THEN 'UNKNOWN' WHEN fp.dp_name LIKE '%[0-9]' THEN LEFT(fp.dp_name, LEN(fp.dp_name) - 1) + '#' ELSE fp.dp_name END";
$starBaseSQL = "CASE WHEN fp.star_name IS NULL THEN 'UNKNOWN' WHEN fp.star_name LIKE '%[0-9]' THEN LEFT(fp.star_name, LEN(fp.star_name) - 1) + '#' ELSE fp.star_name END";

// Origin ARTCC breakdown
$response['origin_artcc_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'fp.fp_dept_artcc', 'artcc', $granularity, $startSQL, $endSQL
);

// Dest ARTCC breakdown
$response['dest_artcc_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'fp.fp_dest_artcc', 'artcc', $granularity, $startSQL, $endSQL
);

// Carrier breakdown
$response['carrier_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'ac.airline_icao', 'carrier', $granularity, $startSQL, $endSQL, true
);

// Weight class breakdown
$response['weight_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'ac.weight_class', 'weight_class', $granularity, $startSQL, $endSQL, true
);

// Equipment breakdown
$response['equipment_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'fp.aircraft_type', 'equipment', $granularity, $startSQL, $endSQL
);

// Flight rule breakdown
$response['rule_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'fp.fp_rule', 'rule', $granularity, $startSQL, $endSQL
);

// Departure fix breakdown
$response['dep_fix_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'fp.dfix', 'fix', $granularity, $startSQL, $endSQL
);

// Arrival fix breakdown
$response['arr_fix_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    'fp.afix', 'fix', $granularity, $startSQL, $endSQL
);

// DP breakdown (with version grouping)
$response['dp_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    $dpBaseSQL, 'dp', $granularity, $startSQL, $endSQL, false, null
);

// STAR breakdown (with version grouping)
$response['star_breakdown'] = fs_getBreakdown(
    $conn, $type, $code, $group, $mode, $direction,
    $starBaseSQL, 'star', $granularity, $startSQL, $endSQL, false, null
);

// Top origins/carriers
$response['top_origins'] = fs_getTopFromBreakdown($response['origin_artcc_breakdown'], 'artcc');
$response['top_carriers'] = fs_getTopFromBreakdown($response['carrier_breakdown'], 'carrier');

sqlsrv_close($conn);

// --- Hash and cache ---
$jsonResponse = json_encode($response);
$dataHash = md5($jsonResponse);
$response['data_hash'] = $dataHash;
apcu_cache_set($cacheKey, $response, $ttl);

// --- Client hash check ---
$clientHash = isset($_SERVER['HTTP_X_IF_DATA_HASH']) ? $_SERVER['HTTP_X_IF_DATA_HASH'] : null;
header('X-Cache: MISS');
echo json_encode($clientHash === $dataHash ? ["unchanged" => true, "data_hash" => $dataHash] : $response);
