<?php
// api/demand/facility.php
// Facility-level demand aggregation: TRACON, ARTCC/FIR, Group
// Supports airport aggregation and boundary crossing modes

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

function facility_sql_error_message() {
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
$granularity = isset($_GET['granularity']) ? get_lower('granularity') : 'hourly';
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;

// Validate type
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

// Thru only valid in crossing mode
if ($direction === 'thru' && $mode !== 'crossing') {
    $direction = 'both';
}

if (!in_array($granularity, ['15min', '30min', 'hourly'])) {
    $granularity = 'hourly';
}

// Parse time range
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
    $group = resolveFirGroup($code);
    if (!$group) {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Group not found: $code"]);
        exit;
    }

    // Time range validation for global/wildcard pattern groups
    if (isset($group['patterns']) && in_array('*', $group['patterns'])) {
        $rangeSecs = $endDt->getTimestamp() - $startDt->getTimestamp();
        if ($rangeSecs > 4 * 3600) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Time range too large for this group. Maximum 4 hours."]);
            exit;
        }
    }

    // Pattern-based groups can't use crossing mode — fall back to airport
    if ($mode === 'crossing' && !isset($group['members'])) {
        $mode = 'airport';
        $modeFallback = true;
    }
}

// --- APCu cache ---
$ttl = ($type === 'group') ? 60 : 30;
$cacheKey = demand_cache_key('facility', [
    'type' => $type, 'code' => $code, 'mode' => $mode,
    'direction' => $direction, 'granularity' => $granularity,
    'start' => $startSQL, 'end' => $endSQL
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

// --- DB connection ---
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];
$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Unable to connect to ADL database.", "sql_error" => facility_sql_error_message()]);
    exit;
}

// --- Build response ---
$response = [
    "success" => true,
    "facility" => [
        "type" => $type,
        "code" => $code,
        "name" => getFacilityName($type, $code, $group),
        "mode" => $mode,
        "mode_fallback" => $modeFallback,
    ],
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "time_range" => [
        "start" => $startDt->format("Y-m-d\\TH:i:s\\Z"),
        "end" => $endDt->format("Y-m-d\\TH:i:s\\Z")
    ],
    "granularity" => $granularity,
    "direction" => $direction,
    "data" => [
        "arrivals" => [],
        "departures" => []
    ],
    "summary" => [
        "total_arrivals" => 0,
        "total_departures" => 0,
        "top_airports" => [],
        "top_carriers" => [],
    ]
];

// --- Execute queries ---
if ($mode === 'crossing') {
    executeCrossingQuery($conn, $type, $code, $group, $direction, $granularity, $startSQL, $endSQL, $response);
} else {
    executeAirportAggregationQuery($conn, $type, $code, $group, $direction, $granularity, $startSQL, $endSQL, $response);
}

// Build summary from accumulated data
buildSummary($response);

sqlsrv_close($conn);

// Cache and return (skip caching on error)
if (!$response['success']) {
    header('X-Cache: ERROR');
    echo json_encode($response);
    exit;
}
$dataHash = md5(json_encode($response));
$response['data_hash'] = $dataHash;
apcu_cache_set($cacheKey, $response, $ttl);
header('X-Cache: MISS');

$clientHash = isset($_SERVER['HTTP_X_IF_DATA_HASH']) ? $_SERVER['HTTP_X_IF_DATA_HASH'] : null;
echo json_encode($clientHash === $dataHash ? ["unchanged" => true, "data_hash" => $dataHash] : $response);
exit;


// ===========================================================================
// HELPER FUNCTIONS
// ===========================================================================

function resolveFirGroup($code, $depth = 0) {
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
                    return resolveFirGroup($grp['alias'], $depth + 1);
                }
                return $grp;
            }
        }
    }
    return null;
}

function getFacilityName($type, $code, $group = null) {
    if ($type === 'group' && $group) {
        return $group['label'] ?? $code;
    }
    if ($type === 'tracon') {
        static $traconData = null;
        if ($traconData === null) {
            $f = __DIR__ . '/../../assets/data/tracon_tiers.json';
            $traconData = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        }
        foreach (['us', 'canada', 'caribbean', 'global'] as $region) {
            if (!isset($traconData[$region])) continue;
            foreach ($traconData[$region] as $t) {
                if ($t['code'] === $code) return $t['name'];
            }
        }
    }
    return $code;
}

function getTimeBinSQL($granularity, $timeExpr) {
    if ($granularity === '15min') {
        return "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 15) * 15, '2000-01-01')";
    } elseif ($granularity === '30min') {
        return "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 30) * 30, '2000-01-01')";
    }
    return "DATEADD(HOUR, DATEDIFF(HOUR, 0, {$timeExpr}), 0)";
}

/**
 * Build WHERE clause for facility type filter.
 * Returns ['clause' => SQL, 'params' => array] or null.
 */
function buildFacilityWhere($type, $code, $group, $directionSide) {
    // $directionSide = 'arr' or 'dep'
    if ($type === 'tracon') {
        $col = $directionSide === 'arr' ? 'fp.fp_dest_tracon' : 'fp.fp_dept_tracon';
        return ['clause' => "$col = ?", 'params' => [$code]];
    }

    if ($type === 'artcc') {
        $col = $directionSide === 'arr' ? 'fp.fp_dest_artcc' : 'fp.fp_dept_artcc';
        return ['clause' => "$col = ?", 'params' => [$code]];
    }

    if ($type === 'group' && $group) {
        return buildGroupWhereClause($group, $directionSide);
    }

    return null;
}

function buildGroupWhereClause($group, $directionSide) {
    $params = [];

    if (isset($group['members'])) {
        $placeholders = implode(',', array_fill(0, count($group['members']), '?'));
        $col = $directionSide === 'arr' ? 'fp.fp_dest_artcc' : 'fp.fp_dept_artcc';
        return ['clause' => "$col IN ($placeholders)", 'params' => $group['members']];
    }

    if (isset($group['patterns'])) {
        $likes = [];
        $col = $directionSide === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';

        foreach ($group['patterns'] as $pattern) {
            $sqlPattern = str_replace('*', '%', $pattern);
            $likes[] = "$col LIKE ?";
            $params[] = $sqlPattern;
        }
        $clause = '(' . implode(' OR ', $likes) . ')';

        if (isset($group['exclude']) && !empty($group['exclude'])) {
            $exPlaceholders = implode(',', array_fill(0, count($group['exclude']), '?'));
            $clause .= " AND $col NOT IN ($exPlaceholders)";
            $params = array_merge($params, $group['exclude']);
        }

        return ['clause' => $clause, 'params' => $params];
    }

    return null;
}

/**
 * Airport aggregation mode: queries adl_flight_core/plan/times tables
 * with facility-level WHERE filters.
 */
function executeAirportAggregationQuery($conn, $type, $code, $group, $direction, $granularity, $startSQL, $endSQL, &$response) {
    $arrTimeExpr = "COALESCE(t.eta_runway_utc, t.eta_utc)";
    $depTimeExpr = "COALESCE(t.etd_runway_utc, t.etd_utc)";

    // Arrivals query
    if ($direction === 'arr' || $direction === 'both') {
        $timeBinSQL = getTimeBinSQL($granularity, $arrTimeExpr);
        $where = buildFacilityWhere($type, $code, $group, 'arr');
        if ($where) {
            $sql = "
                SELECT
                    {$timeBinSQL} AS time_bin,
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.phase = 'arrived' THEN 1 ELSE 0 END) AS arrived,
                    SUM(CASE WHEN c.phase = 'disconnected' THEN 1 ELSE 0 END) AS disconnected,
                    SUM(CASE WHEN c.phase = 'descending' THEN 1 ELSE 0 END) AS descending,
                    SUM(CASE WHEN c.phase = 'enroute' THEN 1 ELSE 0 END) AS enroute,
                    SUM(CASE WHEN c.phase = 'departed' THEN 1 ELSE 0 END) AS departed,
                    SUM(CASE WHEN c.phase = 'taxiing' THEN 1 ELSE 0 END) AS taxiing,
                    SUM(CASE WHEN c.phase = 'prefile' THEN 1 ELSE 0 END) AS prefile,
                    SUM(CASE WHEN c.phase NOT IN ('arrived','disconnected','descending','enroute','departed','taxiing','prefile') OR c.phase IS NULL THEN 1 ELSE 0 END) AS unknown,
                    fp.fp_dest_icao AS airport
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE {$where['clause']}
                  AND {$arrTimeExpr} IS NOT NULL
                  AND {$arrTimeExpr} >= ?
                  AND {$arrTimeExpr} < ?
                  AND (c.phase != 'arrived' OR {$timeBinSQL} < GETUTCDATE())
                GROUP BY {$timeBinSQL}, fp.fp_dest_icao
                ORDER BY time_bin
            ";
            $params = array_merge($where['params'], [$startSQL, $endSQL]);
            $result = runFacilityQuery($conn, $sql, $params, 'arrivals', $response);
        }
    }

    // Departures query
    if ($direction === 'dep' || $direction === 'both') {
        $timeBinSQL = getTimeBinSQL($granularity, $depTimeExpr);
        $where = buildFacilityWhere($type, $code, $group, 'dep');
        if ($where) {
            $sql = "
                SELECT
                    {$timeBinSQL} AS time_bin,
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.phase = 'arrived' THEN 1 ELSE 0 END) AS arrived,
                    SUM(CASE WHEN c.phase = 'disconnected' THEN 1 ELSE 0 END) AS disconnected,
                    SUM(CASE WHEN c.phase = 'descending' THEN 1 ELSE 0 END) AS descending,
                    SUM(CASE WHEN c.phase = 'enroute' THEN 1 ELSE 0 END) AS enroute,
                    SUM(CASE WHEN c.phase = 'departed' THEN 1 ELSE 0 END) AS departed,
                    SUM(CASE WHEN c.phase = 'taxiing' THEN 1 ELSE 0 END) AS taxiing,
                    SUM(CASE WHEN c.phase = 'prefile' THEN 1 ELSE 0 END) AS prefile,
                    SUM(CASE WHEN c.phase NOT IN ('arrived','disconnected','descending','enroute','departed','taxiing','prefile') OR c.phase IS NULL THEN 1 ELSE 0 END) AS unknown,
                    fp.fp_dept_icao AS airport
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE {$where['clause']}
                  AND {$depTimeExpr} IS NOT NULL
                  AND {$depTimeExpr} >= ?
                  AND {$depTimeExpr} < ?
                GROUP BY {$timeBinSQL}, fp.fp_dept_icao
                ORDER BY time_bin
            ";
            $params = array_merge($where['params'], [$startSQL, $endSQL]);
            $result = runFacilityQuery($conn, $sql, $params, 'departures', $response);
        }
    }
}

/**
 * Boundary crossing mode: queries adl_flight_planned_crossings
 */
function executeCrossingQuery($conn, $type, $code, $group, $direction, $granularity, $startSQL, $endSQL, &$response) {
    $crossingTimeExpr = "cx.planned_entry_utc";
    $timeBinSQL = getTimeBinSQL($granularity, $crossingTimeExpr);

    // Build boundary filter
    if ($type === 'group' && $group && isset($group['members'])) {
        $placeholders = implode(',', array_fill(0, count($group['members']), '?'));
        $boundaryWhere = "cx.boundary_code IN ($placeholders)";
        $boundaryParams = $group['members'];
    } else {
        $boundaryWhere = "cx.boundary_code = ?";
        $boundaryParams = [$code];
    }

    $boundaryType = ($type === 'tracon') ? 'TRACON' : 'ARTCC';

    // Direction filter for crossing mode
    $directionWhere = '';
    $directionParams = [];

    if ($direction === 'thru') {
        // Overflights: neither origin nor destination is within the facility
        if ($type === 'group' && $group && isset($group['members'])) {
            $placeholders2 = implode(',', array_fill(0, count($group['members']), '?'));
            $directionWhere = "AND fp.fp_dept_artcc NOT IN ($placeholders2) AND fp.fp_dest_artcc NOT IN ($placeholders2)";
            $directionParams = array_merge($group['members'], $group['members']);
        } else {
            $deptCol = ($type === 'tracon') ? 'fp.fp_dept_tracon' : 'fp.fp_dept_artcc';
            $destCol = ($type === 'tracon') ? 'fp.fp_dest_tracon' : 'fp.fp_dest_artcc';
            $directionWhere = "AND $deptCol != ? AND $destCol != ?";
            $directionParams = [$code, $code];
        }
    } elseif ($direction === 'arr') {
        if ($type === 'group' && $group && isset($group['members'])) {
            $placeholders2 = implode(',', array_fill(0, count($group['members']), '?'));
            $directionWhere = "AND fp.fp_dest_artcc IN ($placeholders2)";
            $directionParams = $group['members'];
        } else {
            $col = ($type === 'tracon') ? 'fp.fp_dest_tracon' : 'fp.fp_dest_artcc';
            $directionWhere = "AND $col = ?";
            $directionParams = [$code];
        }
    } elseif ($direction === 'dep') {
        if ($type === 'group' && $group && isset($group['members'])) {
            $placeholders2 = implode(',', array_fill(0, count($group['members']), '?'));
            $directionWhere = "AND fp.fp_dept_artcc IN ($placeholders2)";
            $directionParams = $group['members'];
        } else {
            $col = ($type === 'tracon') ? 'fp.fp_dept_tracon' : 'fp.fp_dept_artcc';
            $directionWhere = "AND $col = ?";
            $directionParams = [$code];
        }
    }
    // direction === 'both': no additional filter

    // Determine which data bucket to populate based on direction
    $dataKey = ($direction === 'dep') ? 'departures' : 'arrivals';

    $sql = "
        SELECT
            {$timeBinSQL} AS time_bin,
            COUNT(*) AS total,
            SUM(CASE WHEN c.phase = 'arrived' THEN 1 ELSE 0 END) AS arrived,
            SUM(CASE WHEN c.phase = 'disconnected' THEN 1 ELSE 0 END) AS disconnected,
            SUM(CASE WHEN c.phase = 'descending' THEN 1 ELSE 0 END) AS descending,
            SUM(CASE WHEN c.phase = 'enroute' THEN 1 ELSE 0 END) AS enroute,
            SUM(CASE WHEN c.phase = 'departed' THEN 1 ELSE 0 END) AS departed,
            SUM(CASE WHEN c.phase = 'taxiing' THEN 1 ELSE 0 END) AS taxiing,
            SUM(CASE WHEN c.phase = 'prefile' THEN 1 ELSE 0 END) AS prefile,
            SUM(CASE WHEN c.phase NOT IN ('arrived','disconnected','descending','enroute','departed','taxiing','prefile') OR c.phase IS NULL THEN 1 ELSE 0 END) AS unknown,
            fp.fp_dest_icao AS dest_airport,
            fp.fp_dept_icao AS dept_airport
        FROM dbo.adl_flight_planned_crossings cx
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = cx.flight_uid
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = cx.flight_uid
        WHERE {$boundaryWhere}
          AND cx.boundary_type = ?
          AND cx.crossing_type IN ('ENTRY', 'CROSS')
          AND {$crossingTimeExpr} >= ?
          AND {$crossingTimeExpr} < ?
          {$directionWhere}
        GROUP BY {$timeBinSQL}, fp.fp_dest_icao, fp.fp_dept_icao
        ORDER BY time_bin
    ";

    $params = array_merge($boundaryParams, [$boundaryType, $startSQL, $endSQL], $directionParams);

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $response['success'] = false;
        $response['error'] = "Crossing query error: " . facility_sql_error_message();
        return;
    }

    // Aggregate crossing results into time bins
    $bins = [];
    $airportCounts = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $timeBin = $row['time_bin'];
        if ($timeBin instanceof DateTime) {
            $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
        }

        if (!isset($bins[$timeBin])) {
            $bins[$timeBin] = [
                'time_bin' => $timeBin,
                'total' => 0,
                'breakdown' => [
                    'arrived' => 0, 'disconnected' => 0, 'descending' => 0,
                    'enroute' => 0, 'departed' => 0, 'taxiing' => 0,
                    'prefile' => 0, 'unknown' => 0
                ],
                'by_airport' => []
            ];
        }
        $bin = &$bins[$timeBin];
        $count = (int)$row['total'];
        $bin['total'] += $count;
        $bin['breakdown']['arrived'] += (int)$row['arrived'];
        $bin['breakdown']['disconnected'] += (int)$row['disconnected'];
        $bin['breakdown']['descending'] += (int)$row['descending'];
        $bin['breakdown']['enroute'] += (int)$row['enroute'];
        $bin['breakdown']['departed'] += (int)$row['departed'];
        $bin['breakdown']['taxiing'] += (int)$row['taxiing'];
        $bin['breakdown']['prefile'] += (int)$row['prefile'];
        $bin['breakdown']['unknown'] += (int)$row['unknown'];

        // Track per-airport counts
        $destApt = $row['dest_airport'] ?? 'UNKN';
        $deptApt = $row['dept_airport'] ?? 'UNKN';
        if ($direction !== 'dep') {
            $bin['by_airport'][$destApt] = ($bin['by_airport'][$destApt] ?? 0) + $count;
            $airportCounts[$destApt] = ($airportCounts[$destApt] ?? ['arr' => 0, 'dep' => 0]);
            $airportCounts[$destApt]['arr'] += $count;
        }
        if ($direction !== 'arr') {
            $bin['by_airport'][$deptApt] = ($bin['by_airport'][$deptApt] ?? 0) + $count;
            $airportCounts[$deptApt] = ($airportCounts[$deptApt] ?? ['arr' => 0, 'dep' => 0]);
            $airportCounts[$deptApt]['dep'] += $count;
        }
        unset($bin);
    }
    sqlsrv_free_stmt($stmt);

    $response['data'][$dataKey] = array_values($bins);
    // Store airport counts for summary building
    $response['_airport_counts'] = $airportCounts;
}

/**
 * Execute a facility query and aggregate results into time bins with airport breakdown.
 */
function runFacilityQuery($conn, $sql, $params, $dataKey, &$response) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $response['success'] = false;
        $response['error'] = "Query error ($dataKey): " . facility_sql_error_message();
        return;
    }

    $bins = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $timeBin = $row['time_bin'];
        if ($timeBin instanceof DateTime) {
            $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
        }
        $airport = $row['airport'] ?? 'UNKN';

        if (!isset($bins[$timeBin])) {
            $bins[$timeBin] = [
                'time_bin' => $timeBin,
                'total' => 0,
                'breakdown' => [
                    'arrived' => 0, 'disconnected' => 0, 'descending' => 0,
                    'enroute' => 0, 'departed' => 0, 'taxiing' => 0,
                    'prefile' => 0, 'unknown' => 0
                ],
                'by_airport' => []
            ];
        }
        $bin = &$bins[$timeBin];
        $count = (int)$row['total'];
        $bin['total'] += $count;
        $bin['breakdown']['arrived'] += (int)$row['arrived'];
        $bin['breakdown']['disconnected'] += (int)$row['disconnected'];
        $bin['breakdown']['descending'] += (int)$row['descending'];
        $bin['breakdown']['enroute'] += (int)$row['enroute'];
        $bin['breakdown']['departed'] += (int)$row['departed'];
        $bin['breakdown']['taxiing'] += (int)$row['taxiing'];
        $bin['breakdown']['prefile'] += (int)$row['prefile'];
        $bin['breakdown']['unknown'] += (int)$row['unknown'];
        $bin['by_airport'][$airport] = ($bin['by_airport'][$airport] ?? 0) + $count;

        // Track for summary
        if (!isset($response['_airport_counts'])) $response['_airport_counts'] = [];
        if (!isset($response['_airport_counts'][$airport])) $response['_airport_counts'][$airport] = ['arr' => 0, 'dep' => 0];
        $response['_airport_counts'][$airport][$dataKey === 'arrivals' ? 'arr' : 'dep'] += $count;

        unset($bin);
    }
    sqlsrv_free_stmt($stmt);

    $response['data'][$dataKey] = array_values($bins);
}

/**
 * Build summary from accumulated response data.
 */
function buildSummary(&$response) {
    // Total counts
    foreach ($response['data']['arrivals'] as $bin) {
        $response['summary']['total_arrivals'] += $bin['total'];
    }
    foreach ($response['data']['departures'] as $bin) {
        $response['summary']['total_departures'] += $bin['total'];
    }

    // Top airports from accumulated counts
    $airportCounts = $response['_airport_counts'] ?? [];
    unset($response['_airport_counts']);

    uasort($airportCounts, function($a, $b) {
        return ($b['arr'] + $b['dep']) - ($a['arr'] + $a['dep']);
    });

    $top = array_slice($airportCounts, 0, 10, true);
    foreach ($top as $apt => $counts) {
        $response['summary']['top_airports'][] = [
            'code' => $apt,
            'arrivals' => $counts['arr'],
            'departures' => $counts['dep'],
            'total' => $counts['arr'] + $counts['dep']
        ];
    }
}
