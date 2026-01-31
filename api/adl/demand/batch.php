<?php
/**
 * api/adl/demand/batch.php
 *
 * Batch Demand API - Returns time-bucketed demand counts for multiple monitors
 *
 * Efficiently queries traffic demand for multiple fixes/segments in a single call,
 * returning counts grouped into time buckets for visualization (like Google Maps traffic).
 *
 * Parameters:
 *   monitors       - Required: JSON array of monitor definitions
 *   bucket_minutes - Optional: Time bucket size (default 15, min 5, max 60)
 *   horizon_hours  - Optional: Projection horizon in hours (default 4, max 12)
 *
 * Monitor Types:
 *   - fix: Traffic through a navigation fix
 *     { "type": "fix", "fix": "MERIT" }
 *
 *   - segment: Traffic between two fixes
 *     { "type": "segment", "from": "CAM", "to": "GONZZ" }
 *
 *   - airway: All traffic on an airway
 *     { "type": "airway", "airway": "J48" }
 *
 *   - airway_segment: Traffic on an airway between two fixes
 *     { "type": "airway_segment", "airway": "J48", "from": "LANNA", "to": "MOL" }
 *
 *   - via_fix: Filtered traffic (by airport/tracon/artcc) through a fix or airway
 *     { "type": "via_fix", "via": "MERIT", "via_type": "fix",
 *       "filter": { "type": "airport", "code": "KBOS", "direction": "arr" } }
 *     { "type": "via_fix", "via": "J48", "via_type": "airway",
 *       "filter": { "type": "artcc", "code": "ZDC", "direction": "both" } }
 *
 * Filter Types: airport, tracon, artcc
 * Direction: arr (arrivals), dep (departures), both
 *
 * Flight Filters (optional, can be added to any monitor type):
 *   "flight_filter": {
 *     "airline": "UAL",           // Callsign prefix
 *     "aircraft_type": "B738",    // Specific aircraft type
 *     "aircraft_category": "HEAVY", // HEAVY, LARGE, or SMALL
 *     "origin": "KJFK",           // Origin airport
 *     "destination": "KLAX"       // Destination airport
 *   }
 *
 * Examples with flight filters:
 *   { "type": "fix", "fix": "MERIT", "flight_filter": { "airline": "UAL" } }
 *   { "type": "fix", "fix": "MERIT", "flight_filter": { "aircraft_category": "HEAVY" } }
 *
 * Example:
 *   GET /api/adl/demand/batch?monitors=[{"type":"fix","fix":"MERIT"}]&bucket_minutes=15&horizon_hours=4
 *
 * Response:
 *   {
 *     "generated_utc": "2026-01-15T22:00:00Z",
 *     "bucket_minutes": 15,
 *     "horizon_hours": 4,
 *     "buckets": [
 *       { "index": 0, "start": "2026-01-15T22:00:00Z", "label": "+0" },
 *       { "index": 1, "start": "2026-01-15T22:15:00Z", "label": "+15" },
 *       ...
 *     ],
 *     "monitors": [
 *       {
 *         "id": "fix_MERIT",
 *         "type": "fix",
 *         "fix": "MERIT",
 *         "lat": 40.123,
 *         "lon": -73.456,
 *         "counts": [12, 8, 15, 6, ...],
 *         "total": 41
 *       }
 *     ]
 *   }
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

// Define PERTI_LOADED for swim_config.php access control
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}

require_once(__DIR__ . '/../../../load/config.php');
require_once(__DIR__ . '/../../../load/swim_config.php');

// Validate config
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode([
        "error" => "ADL_SQL_* constants are not defined. Check config.php."
    ]);
    exit;
}

// Validate sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode([
        "error" => "The sqlsrv extension is not available."
    ]);
    exit;
}

function adl_sql_error_message() {
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
        // Match callsign starting with the airline code
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
        // Map to SQL Server aircraft category logic
        // Heavy = B747, B777, B787, A330, A340, A350, A380, B767, DC10, MD11, C5, C17, etc.
        // This uses a simplified approach - match common prefixes
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

/**
 * Check if a value looks like a SID/STAR procedure name
 * Pattern: 3+ letters followed by a digit, optionally followed by a letter
 * Examples: SNFLD3, ICONS5, DUMEP1T, KRSTA4, BNFSH3, PRICY4
 *
 * @param string $value The value to check
 * @return bool True if it matches the SID/STAR pattern
 */
function isProcedureName($value) {
    // Pattern: 3+ uppercase letters, followed by a digit, optionally followed by a letter
    return preg_match('/^[A-Z]{3,}[0-9][A-Z]?$/', strtoupper($value)) === 1;
}

/**
 * Check if a value could be a procedure base name (without version number)
 * Pattern: 3+ letters (could be start of a STAR like "SNFLD" for "SNFLD3")
 *
 * @param string $value The value to check
 * @return bool True if it could be a procedure base name
 */
function couldBeProcedureBaseName($value) {
    // 3-5 uppercase letters that aren't an airway (airways start with J/V/Q/T/Y/L/M/A/B/G/R followed by digits)
    $upper = strtoupper($value);
    if (strlen($upper) < 3 || strlen($upper) > 6) {
        return false;
    }
    // Check if it's NOT an airway pattern
    if (preg_match('/^[JVQTYLMABGR][0-9]+$/', $upper)) {
        return false;
    }
    // Check if it's all letters
    return preg_match('/^[A-Z]+$/', $upper) === 1;
}

// Parse parameters
$monitorsJson = isset($_GET['monitors']) ? trim($_GET['monitors']) : '';
$bucketMinutes = isset($_GET['bucket_minutes']) ? (int)$_GET['bucket_minutes'] : 15;
$bucketMinutes = max(5, min(60, $bucketMinutes)); // Clamp to 5-60 minutes
$horizonHours = isset($_GET['horizon_hours']) ? (int)$_GET['horizon_hours'] : 4;
$horizonHours = max(1, min(12, $horizonHours)); // Clamp to 1-12 hours

// Validate required parameters
if (empty($monitorsJson)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Missing required parameter: monitors",
        "example" => '/api/adl/demand/batch?monitors=[{"type":"fix","fix":"MERIT"}]&bucket_minutes=15&horizon_hours=4'
    ]);
    exit;
}

// Validate JSON
$monitors = json_decode($monitorsJson, true);
if ($monitors === null || !is_array($monitors)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Invalid JSON in monitors parameter",
        "example" => '[{"type":"fix","fix":"MERIT"},{"type":"segment","from":"CAM","to":"GONZZ"}]'
    ]);
    exit;
}

// Validate monitor definitions
foreach ($monitors as $idx => $m) {
    if (!isset($m['type'])) {
        http_response_code(400);
        echo json_encode([
            "error" => "Monitor at index $idx missing 'type' field"
        ]);
        exit;
    }
    if ($m['type'] === 'fix' && empty($m['fix'])) {
        http_response_code(400);
        echo json_encode([
            "error" => "Fix monitor at index $idx missing 'fix' field"
        ]);
        exit;
    }
    if ($m['type'] === 'segment' && (empty($m['from']) || empty($m['to']))) {
        http_response_code(400);
        echo json_encode([
            "error" => "Segment monitor at index $idx missing 'from' or 'to' field"
        ]);
        exit;
    }
    if ($m['type'] === 'airway' && empty($m['airway'])) {
        http_response_code(400);
        echo json_encode([
            "error" => "Airway monitor at index $idx missing 'airway' field"
        ]);
        exit;
    }
    if ($m['type'] === 'airway_segment' && (empty($m['airway']) || empty($m['from']) || empty($m['to']))) {
        http_response_code(400);
        echo json_encode([
            "error" => "Airway segment monitor at index $idx missing 'airway', 'from', or 'to' field"
        ]);
        exit;
    }
    if ($m['type'] === 'via_fix' && (empty($m['via']) || empty($m['filter']))) {
        http_response_code(400);
        echo json_encode([
            "error" => "Via-fix monitor at index $idx missing 'via' or 'filter' field"
        ]);
        exit;
    }
}

// Limit number of monitors
if (count($monitors) > 50) {
    http_response_code(400);
    echo json_encode([
        "error" => "Too many monitors. Maximum is 50."
    ]);
    exit;
}

// APCu cache check - batch queries are expensive
$cache_key = swim_cache_key('demand_batch', [
    'hash' => md5($monitorsJson),
    'bucket' => $bucketMinutes,
    'horizon' => $horizonHours
]);
$cached = swim_cache_get($cache_key);
if ($cached !== null) {
    header('X-Cache: HIT');
    echo json_encode($cached, JSON_PRETTY_PRINT);
    exit;
}
header('X-Cache: MISS');

// Connect to database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Fetch results and organize by monitor
$now = new DateTime('now', new DateTimeZone('UTC'));
$numBuckets = (int)ceil(($horizonHours * 60) / $bucketMinutes);

// Separate monitors by type for processing
$batchMonitors = []; // fix, segment - use fn_BatchDemandBucketed
$airwayMonitors = []; // airway - use fn_AirwayDemandBucketed
$airwaySegmentMonitors = []; // airway_segment - use fn_AirwaySegmentDemandBucketed
$viaMonitors = []; // via_fix - use fn_ViaDemandBucketed

foreach ($monitors as $idx => $m) {
    switch ($m['type']) {
        case 'fix':
        case 'segment':
            $batchMonitors[$idx] = $m;
            break;
        case 'airway':
            $airwayMonitors[$idx] = $m;
            break;
        case 'airway_segment':
            $airwaySegmentMonitors[$idx] = $m;
            break;
        case 'via_fix':
            $viaMonitors[$idx] = $m;
            break;
    }
}

// Initialize all monitors with metadata
$monitorData = [];
foreach ($monitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $data = [
        'counts' => array_fill(0, $numBuckets, 0),
        'total' => 0
    ];

    switch ($m['type']) {
        case 'fix':
            $data['id'] = 'fix_' . strtoupper($m['fix']);
            $data['type'] = 'fix';
            $data['fix'] = strtoupper($m['fix']);
            $data['lat'] = null;
            $data['lon'] = null;
            break;
        case 'segment':
            $data['id'] = 'segment_' . strtoupper($m['from']) . '_' . strtoupper($m['to']);
            $data['type'] = 'segment';
            $data['from_fix'] = strtoupper($m['from']);
            $data['to_fix'] = strtoupper($m['to']);
            $data['from_lat'] = null;
            $data['from_lon'] = null;
            $data['to_lat'] = null;
            $data['to_lon'] = null;
            break;
        case 'airway':
            $data['id'] = 'airway_' . strtoupper($m['airway']);
            $data['type'] = 'airway';
            $data['airway'] = strtoupper($m['airway']);
            $data['lat'] = null;
            $data['lon'] = null;
            break;
        case 'airway_segment':
            $data['id'] = 'airway_' . strtoupper($m['airway']) . '_' . strtoupper($m['from']) . '_' . strtoupper($m['to']);
            $data['type'] = 'airway_segment';
            $data['airway'] = strtoupper($m['airway']);
            $data['from_fix'] = strtoupper($m['from']);
            $data['to_fix'] = strtoupper($m['to']);
            $data['from_lat'] = null;
            $data['from_lon'] = null;
            $data['to_lat'] = null;
            $data['to_lon'] = null;
            break;
        case 'via_fix':
            $filterCode = strtoupper($m['filter']['code'] ?? '');
            $filterType = $m['filter']['type'] ?? 'airport';
            $filterDir = $m['filter']['direction'] ?? 'both';
            $data['id'] = 'via_' . $filterType . '_' . $filterCode . '_' . $filterDir . '_' . strtoupper($m['via']);
            $data['type'] = 'via_fix';
            $data['via'] = strtoupper($m['via']);
            $data['via_type'] = $m['via_type'] ?? 'fix';
            $data['filter'] = [
                'type' => $filterType,
                'code' => $filterCode,
                'direction' => $filterDir
            ];
            $data['lat'] = null;
            $data['lon'] = null;
            break;
    }

    $monitorData[$monitorIdx] = $data;
}

// Track errors for debugging
$sqlErrors = [];

// Separate monitors WITH flight filters (need inline SQL) from those WITHOUT (can use SQL functions)
$batchMonitorsNoFilter = [];
$batchMonitorsWithFilter = [];
foreach ($batchMonitors as $idx => $m) {
    if (!empty($m['flight_filter'])) {
        $batchMonitorsWithFilter[$idx] = $m;
    } else {
        $batchMonitorsNoFilter[$idx] = $m;
    }
}

// Process batch monitors WITHOUT flight filters using fn_BatchDemandBucketed
if (!empty($batchMonitorsNoFilter)) {
    $batchJson = json_encode(array_values($batchMonitorsNoFilter));
    $sql = "SELECT * FROM dbo.fn_BatchDemandBucketed(?, ?, ?, NULL) ORDER BY monitor_idx, bucket_num";
    $params = [$batchJson, $bucketMinutes, $horizonHours];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $sqlErrors[] = ['function' => 'fn_BatchDemandBucketed', 'error' => adl_sql_error_message()];
    } else {
        // Map batch index back to original index
        $batchIndexMap = array_keys($batchMonitorsNoFilter);

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $batchIdx = (int)$row['monitor_idx'] - 1; // 0-indexed
            if (isset($batchIndexMap[$batchIdx])) {
                $monitorIdx = $batchIndexMap[$batchIdx] + 1;
                $bucketNum = (int)$row['bucket_num'];
                $count = (int)$row['flight_count'];

                if (isset($monitorData[$monitorIdx]) && $bucketNum >= 0 && $bucketNum < $numBuckets) {
                    $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
                    $monitorData[$monitorIdx]['total'] += $count;

                    // Set coordinates
                    if ($monitorData[$monitorIdx]['type'] === 'fix') {
                        if ($monitorData[$monitorIdx]['lat'] === null && isset($row['fix_lat'])) {
                            $monitorData[$monitorIdx]['lat'] = (float)$row['fix_lat'];
                            $monitorData[$monitorIdx]['lon'] = (float)$row['fix_lon'];
                        }
                    } else if ($monitorData[$monitorIdx]['type'] === 'segment') {
                        if ($monitorData[$monitorIdx]['from_lat'] === null && isset($row['from_lat'])) {
                            $monitorData[$monitorIdx]['from_lat'] = (float)$row['from_lat'];
                            $monitorData[$monitorIdx]['from_lon'] = (float)$row['from_lon'];
                            $monitorData[$monitorIdx]['to_lat'] = (float)$row['to_lat'];
                            $monitorData[$monitorIdx]['to_lon'] = (float)$row['to_lon'];
                        }
                    }
                }
            }
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Process batch monitors WITH flight filters using inline SQL
foreach ($batchMonitorsWithFilter as $idx => $m) {
    $monitorIdx = $idx + 1;
    $filterResult = buildFlightFilterClause($m['flight_filter'] ?? []);
    $filterClause = $filterResult['clause'];
    $filterParams = $filterResult['params'];

    if ($m['type'] === 'fix') {
        // Fix monitor with flight filter - inline SQL
        $fixName = strtoupper($m['fix']);
        $sql = "WITH TimeBounds AS (
                    SELECT GETUTCDATE() AS start_time,
                           DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                ),
                BucketCounts AS (
                    SELECT
                        DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ? AS bucket_num,
                        COUNT(DISTINCT w.flight_uid) AS flight_count
                    FROM dbo.adl_flight_waypoints w
                    CROSS JOIN TimeBounds tb
                    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                    WHERE w.fix_name = ?
                      AND w.eta_utc >= tb.start_time
                      AND w.eta_utc < tb.end_time
                      AND c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      $filterClause
                    GROUP BY DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ?
                )
                SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
        $params = array_merge([$horizonHours, $bucketMinutes, $fixName], $filterParams, [$bucketMinutes]);

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $sqlErrors[] = ['monitor' => $monitorData[$monitorIdx]['id'], 'error' => adl_sql_error_message()];
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $bucketNum = (int)$row['bucket_num'];
                $count = (int)$row['flight_count'];
                if ($bucketNum >= 0 && $bucketNum < $numBuckets) {
                    $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
                    $monitorData[$monitorIdx]['total'] += $count;
                }
            }
            sqlsrv_free_stmt($stmt);
        }
    } else if ($m['type'] === 'segment') {
        // Segment monitor with flight filter - inline SQL
        $fromFix = strtoupper($m['from']);
        $toFix = strtoupper($m['to']);
        $sql = "WITH TimeBounds AS (
                    SELECT GETUTCDATE() AS start_time,
                           DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                ),
                FlightsWithBothFixes AS (
                    SELECT c.flight_uid
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    WHERE c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints w1 WHERE w1.flight_uid = c.flight_uid AND w1.fix_name = ?)
                      AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints w2 WHERE w2.flight_uid = c.flight_uid AND w2.fix_name = ?)
                      $filterClause
                ),
                FlightEntryTimes AS (
                    SELECT f.flight_uid,
                           (SELECT TOP 1 w.eta_utc FROM dbo.adl_flight_waypoints w WHERE w.flight_uid = f.flight_uid AND w.fix_name = ? ORDER BY w.sequence_num) AS entry_eta
                    FROM FlightsWithBothFixes f
                ),
                BucketCounts AS (
                    SELECT
                        DATEDIFF(MINUTE, tb.start_time, fe.entry_eta) / ? AS bucket_num,
                        COUNT(DISTINCT fe.flight_uid) AS flight_count
                    FROM FlightEntryTimes fe
                    CROSS JOIN TimeBounds tb
                    WHERE fe.entry_eta >= tb.start_time AND fe.entry_eta < tb.end_time
                    GROUP BY DATEDIFF(MINUTE, tb.start_time, fe.entry_eta) / ?
                )
                SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
        $params = array_merge([$horizonHours, $fromFix, $toFix], $filterParams, [$fromFix, $bucketMinutes, $bucketMinutes]);

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $sqlErrors[] = ['monitor' => $monitorData[$monitorIdx]['id'], 'error' => adl_sql_error_message()];
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $bucketNum = (int)$row['bucket_num'];
                $count = (int)$row['flight_count'];
                if ($bucketNum >= 0 && $bucketNum < $numBuckets) {
                    $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
                    $monitorData[$monitorIdx]['total'] += $count;
                }
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

// Process airway monitors using fn_AirwayDemandBucketed (or inline SQL with filters)
foreach ($airwayMonitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $airwayName = strtoupper($m['airway']);

    // Check if this monitor has a flight filter
    if (!empty($m['flight_filter'])) {
        // Use inline SQL with flight filter
        $filterResult = buildFlightFilterClause($m['flight_filter']);
        $filterClause = $filterResult['clause'];
        $filterParams = $filterResult['params'];

        $sql = "WITH TimeBounds AS (
                    SELECT GETUTCDATE() AS start_time,
                           DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                ),
                BucketCounts AS (
                    SELECT
                        DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ? AS bucket_num,
                        COUNT(DISTINCT w.flight_uid) AS flight_count
                    FROM dbo.adl_flight_waypoints w
                    CROSS JOIN TimeBounds tb
                    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                    WHERE (',' + ISNULL(w.on_airway, '') + ',') LIKE '%,' + ? + ',%'
                      AND w.eta_utc >= tb.start_time
                      AND w.eta_utc < tb.end_time
                      AND c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      $filterClause
                    GROUP BY DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ?
                )
                SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
        $params = array_merge([$horizonHours, $bucketMinutes, $airwayName], $filterParams, [$bucketMinutes]);
    } else {
        // Use SQL function without filter
        $sql = "SELECT * FROM dbo.fn_AirwayDemandBucketed(?, ?, ?, NULL)";
        $params = [$airwayName, $bucketMinutes, $horizonHours];
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $sqlErrors[] = ['function' => 'fn_AirwayDemandBucketed', 'error' => adl_sql_error_message()];
    } else {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bucketNum = (int)$row['bucket_num'];
            $count = (int)$row['flight_count'];

            if ($bucketNum >= 0 && $bucketNum < $numBuckets) {
                $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
                $monitorData[$monitorIdx]['total'] += $count;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // Look up full airway geometry (all segments along the airway)
    $airwayName = strtoupper(trim($m['airway']));
    $airwaySql = "SELECT from_fix, to_fix, sequence_num, from_lat, from_lon, to_lat, to_lon
                  FROM dbo.airway_segments
                  WHERE RTRIM(airway_name) = ?
                  ORDER BY sequence_num";
    $airwayStmt = sqlsrv_query($conn, $airwaySql, [$airwayName]);

    $geometry = [];
    if ($airwayStmt) {
        while ($row = sqlsrv_fetch_array($airwayStmt, SQLSRV_FETCH_ASSOC)) {
            if (empty($geometry)) {
                // Add first point
                $geometry[] = [(float)$row['from_lon'], (float)$row['from_lat']];
            }
            // Add end point of each segment
            $geometry[] = [(float)$row['to_lon'], (float)$row['to_lat']];
        }
        sqlsrv_free_stmt($airwayStmt);
    }

    if (!empty($geometry)) {
        $monitorData[$monitorIdx]['geometry'] = $geometry;
        // Use first point for label position
        $monitorData[$monitorIdx]['lat'] = $geometry[0][1];
        $monitorData[$monitorIdx]['lon'] = $geometry[0][0];
    } else {
        // Fallback: Get geometry from actual flight waypoints on this airway
        // Find a flight that uses this airway and extract all waypoints on it
        $fallbackSql = "WITH FlightOnAirway AS (
                            SELECT TOP 1 flight_uid
                            FROM dbo.adl_flight_waypoints
                            WHERE (',' + ISNULL(on_airway, '') + ',') LIKE '%,' + ? + ',%'
                              AND lat IS NOT NULL
                            ORDER BY waypoint_id DESC
                        )
                        SELECT w.fix_name, w.lat, w.lon, w.sequence_num
                        FROM dbo.adl_flight_waypoints w
                        WHERE w.flight_uid = (SELECT flight_uid FROM FlightOnAirway)
                          AND (',' + ISNULL(w.on_airway, '') + ',') LIKE '%,' + ? + ',%'
                          AND w.lat IS NOT NULL
                        ORDER BY w.sequence_num";
        $fallbackStmt = sqlsrv_query($conn, $fallbackSql, [$airwayName, $airwayName]);

        $airwayGeometry = [];
        if ($fallbackStmt) {
            while ($row = sqlsrv_fetch_array($fallbackStmt, SQLSRV_FETCH_ASSOC)) {
                $airwayGeometry[] = [(float)$row['lon'], (float)$row['lat']];
            }
            sqlsrv_free_stmt($fallbackStmt);
        }

        if (count($airwayGeometry) >= 2) {
            $monitorData[$monitorIdx]['geometry'] = $airwayGeometry;
            $monitorData[$monitorIdx]['lat'] = $airwayGeometry[0][1];
            $monitorData[$monitorIdx]['lon'] = $airwayGeometry[0][0];
        } else if (count($airwayGeometry) === 1) {
            // Single point fallback
            $monitorData[$monitorIdx]['lat'] = $airwayGeometry[0][1];
            $monitorData[$monitorIdx]['lon'] = $airwayGeometry[0][0];
        }
    }
}

// Process airway segment monitors using fn_AirwaySegmentDemandBucketed (or inline SQL with filters)
foreach ($airwaySegmentMonitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $airwayName = strtoupper($m['airway']);
    $fromFix = strtoupper($m['from']);
    $toFix = strtoupper($m['to']);

    // Check if this monitor has a flight filter
    if (!empty($m['flight_filter'])) {
        // Use inline SQL with flight filter
        $filterResult = buildFlightFilterClause($m['flight_filter']);
        $filterClause = $filterResult['clause'];
        $filterParams = $filterResult['params'];

        $sql = "WITH TimeBounds AS (
                    SELECT GETUTCDATE() AS start_time,
                           DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                ),
                FlightsWithBothFixes AS (
                    SELECT c.flight_uid
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    WHERE c.is_active = 1
                      AND c.phase NOT IN ('arrived', 'disconnected')
                      AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints w1 WHERE w1.flight_uid = c.flight_uid AND w1.fix_name = ?)
                      AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints w2 WHERE w2.flight_uid = c.flight_uid AND w2.fix_name = ?)
                      $filterClause
                ),
                FlightEntryTimes AS (
                    SELECT f.flight_uid,
                           (SELECT TOP 1 w.eta_utc FROM dbo.adl_flight_waypoints w WHERE w.flight_uid = f.flight_uid AND w.fix_name = ? ORDER BY w.sequence_num) AS entry_eta
                    FROM FlightsWithBothFixes f
                ),
                BucketCounts AS (
                    SELECT
                        DATEDIFF(MINUTE, tb.start_time, fe.entry_eta) / ? AS bucket_num,
                        COUNT(DISTINCT fe.flight_uid) AS flight_count
                    FROM FlightEntryTimes fe
                    CROSS JOIN TimeBounds tb
                    WHERE fe.entry_eta >= tb.start_time AND fe.entry_eta < tb.end_time
                    GROUP BY DATEDIFF(MINUTE, tb.start_time, fe.entry_eta) / ?
                )
                SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
        $params = array_merge([$horizonHours, $fromFix, $toFix], $filterParams, [$fromFix, $bucketMinutes, $bucketMinutes]);
    } else {
        // Use SQL function without filter
        $sql = "SELECT * FROM dbo.fn_AirwaySegmentDemandBucketed(?, ?, ?, ?, ?, NULL)";
        $params = [$airwayName, $fromFix, $toFix, $bucketMinutes, $horizonHours];
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $sqlErrors[] = ['function' => 'fn_AirwaySegmentDemandBucketed', 'error' => adl_sql_error_message()];
    } else {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bucketNum = (int)$row['bucket_num'];
            $count = (int)$row['flight_count'];

            if ($bucketNum >= 0 && $bucketNum < $numBuckets) {
                $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
                $monitorData[$monitorIdx]['total'] += $count;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // Look up full airway segment geometry (all fixes along the airway between from and to)
    $airwayName = strtoupper(trim($m['airway']));
    $fromFix = strtoupper(trim($m['from']));
    $toFix = strtoupper(trim($m['to']));

    // First, get sequence numbers for from and to fixes on this airway
    $seqSql = "SELECT RTRIM(from_fix) AS from_fix, RTRIM(to_fix) AS to_fix, sequence_num, from_lat, from_lon, to_lat, to_lon
               FROM dbo.airway_segments
               WHERE RTRIM(airway_name) = ?
               ORDER BY sequence_num";
    $seqStmt = sqlsrv_query($conn, $seqSql, [$airwayName]);

    $geometry = [];
    $fromSeq = null;
    $toSeq = null;
    $segments = [];

    if ($seqStmt) {
        while ($row = sqlsrv_fetch_array($seqStmt, SQLSRV_FETCH_ASSOC)) {
            // Trim whitespace from fix names for comparison
            $rowFromFix = strtoupper(trim($row['from_fix'] ?? ''));
            $rowToFix = strtoupper(trim($row['to_fix'] ?? ''));

            $segments[] = [
                'from_fix' => $rowFromFix,
                'to_fix' => $rowToFix,
                'seq' => (int)$row['sequence_num'],
                'from_lat' => (float)$row['from_lat'],
                'from_lon' => (float)$row['from_lon'],
                'to_lat' => (float)$row['to_lat'],
                'to_lon' => (float)$row['to_lon']
            ];
            // Find sequence of from/to fixes
            if ($rowFromFix === $fromFix && $fromSeq === null) {
                $fromSeq = (int)$row['sequence_num'];
            }
            if ($rowToFix === $fromFix && $fromSeq === null) {
                $fromSeq = (int)$row['sequence_num'] + 1;
            }
            if ($rowFromFix === $toFix && $toSeq === null) {
                $toSeq = (int)$row['sequence_num'];
            }
            if ($rowToFix === $toFix && $toSeq === null) {
                $toSeq = (int)$row['sequence_num'] + 1;
            }
        }
        sqlsrv_free_stmt($seqStmt);

        // Build geometry array from segments between fromSeq and toSeq
        if ($fromSeq !== null && $toSeq !== null) {
            $startSeq = min($fromSeq, $toSeq);
            $endSeq = max($fromSeq, $toSeq);

            foreach ($segments as $seg) {
                if ($seg['seq'] >= $startSeq && $seg['seq'] < $endSeq) {
                    if (empty($geometry)) {
                        // Add first point
                        $geometry[] = [$seg['from_lon'], $seg['from_lat']];
                    }
                    // Add end point of each segment
                    $geometry[] = [$seg['to_lon'], $seg['to_lat']];
                }
            }

            // Reverse if we're going opposite direction on the airway
            if ($fromSeq > $toSeq && count($geometry) > 0) {
                $geometry = array_reverse($geometry);
            }
        }
    }

    // If geometry was found from airway_segments, use it
    if (!empty($geometry)) {
        $monitorData[$monitorIdx]['geometry'] = $geometry;
        $monitorData[$monitorIdx]['from_lat'] = $geometry[0][1];
        $monitorData[$monitorIdx]['from_lon'] = $geometry[0][0];
        $monitorData[$monitorIdx]['to_lat'] = $geometry[count($geometry)-1][1];
        $monitorData[$monitorIdx]['to_lon'] = $geometry[count($geometry)-1][0];
    } else {
        // Fallback: Get geometry from actual flight waypoint data
        // First, try to find a flight that uses this airway and passes through both fixes
        $routeSql = "WITH FlightOnAirway AS (
                         -- Find a recent flight that has both fixes on this airway
                         SELECT TOP 1 w1.flight_uid
                         FROM dbo.adl_flight_waypoints w1
                         INNER JOIN dbo.adl_flight_waypoints w2 ON w2.flight_uid = w1.flight_uid
                         WHERE RTRIM(w1.fix_name) = ?
                           AND RTRIM(w2.fix_name) = ?
                           AND w1.lat IS NOT NULL
                           AND w2.lat IS NOT NULL
                           AND ((',' + ISNULL(w1.on_airway, '') + ',') LIKE '%,' + ? + ',%' OR (',' + ISNULL(w2.on_airway, '') + ',') LIKE '%,' + ? + ',%')
                         ORDER BY w1.waypoint_id DESC
                     ),
                     FixSequences AS (
                         SELECT
                             (SELECT TOP 1 sequence_num FROM dbo.adl_flight_waypoints
                              WHERE flight_uid = (SELECT flight_uid FROM FlightOnAirway)
                                AND RTRIM(fix_name) = ?) AS from_seq,
                             (SELECT TOP 1 sequence_num FROM dbo.adl_flight_waypoints
                              WHERE flight_uid = (SELECT flight_uid FROM FlightOnAirway)
                                AND RTRIM(fix_name) = ?) AS to_seq
                     )
                     SELECT w.fix_name, w.lat, w.lon, w.sequence_num, w.on_airway
                     FROM dbo.adl_flight_waypoints w
                     CROSS JOIN FixSequences fs
                     WHERE w.flight_uid = (SELECT flight_uid FROM FlightOnAirway)
                       AND w.lat IS NOT NULL
                       AND w.sequence_num >= CASE WHEN fs.from_seq < fs.to_seq THEN fs.from_seq ELSE fs.to_seq END
                       AND w.sequence_num <= CASE WHEN fs.from_seq < fs.to_seq THEN fs.to_seq ELSE fs.from_seq END
                     ORDER BY CASE WHEN fs.from_seq < fs.to_seq THEN w.sequence_num ELSE -w.sequence_num END";
        $routeStmt = sqlsrv_query($conn, $routeSql, [$fromFix, $toFix, $airwayName, $airwayName, $fromFix, $toFix]);

        $routeGeometry = [];
        if ($routeStmt) {
            while ($row = sqlsrv_fetch_array($routeStmt, SQLSRV_FETCH_ASSOC)) {
                $routeGeometry[] = [(float)$row['lon'], (float)$row['lat']];
            }
            sqlsrv_free_stmt($routeStmt);
        }

        if (count($routeGeometry) >= 2) {
            $monitorData[$monitorIdx]['geometry'] = $routeGeometry;
            $monitorData[$monitorIdx]['from_lat'] = $routeGeometry[0][1];
            $monitorData[$monitorIdx]['from_lon'] = $routeGeometry[0][0];
            $monitorData[$monitorIdx]['to_lat'] = $routeGeometry[count($routeGeometry)-1][1];
            $monitorData[$monitorIdx]['to_lon'] = $routeGeometry[count($routeGeometry)-1][0];
        }

        // Second fallback: Find ANY flight through both fixes (regardless of airway)
        if (count($routeGeometry) < 2) {
            $fallbackSql = "WITH FlightWithBothFixes AS (
                                SELECT TOP 1 w1.flight_uid
                                FROM dbo.adl_flight_waypoints w1
                                INNER JOIN dbo.adl_flight_waypoints w2 ON w2.flight_uid = w1.flight_uid
                                WHERE RTRIM(w1.fix_name) = ?
                                  AND RTRIM(w2.fix_name) = ?
                                  AND w1.lat IS NOT NULL
                                  AND w2.lat IS NOT NULL
                                ORDER BY w1.waypoint_id DESC
                            ),
                            FixSequences AS (
                                SELECT
                                    (SELECT TOP 1 sequence_num FROM dbo.adl_flight_waypoints
                                     WHERE flight_uid = (SELECT flight_uid FROM FlightWithBothFixes)
                                       AND RTRIM(fix_name) = ?) AS from_seq,
                                    (SELECT TOP 1 sequence_num FROM dbo.adl_flight_waypoints
                                     WHERE flight_uid = (SELECT flight_uid FROM FlightWithBothFixes)
                                       AND RTRIM(fix_name) = ?) AS to_seq
                            )
                            SELECT w.fix_name, w.lat, w.lon, w.sequence_num
                            FROM dbo.adl_flight_waypoints w
                            CROSS JOIN FixSequences fs
                            WHERE w.flight_uid = (SELECT flight_uid FROM FlightWithBothFixes)
                              AND w.lat IS NOT NULL
                              AND w.sequence_num >= CASE WHEN fs.from_seq < fs.to_seq THEN fs.from_seq ELSE fs.to_seq END
                              AND w.sequence_num <= CASE WHEN fs.from_seq < fs.to_seq THEN fs.to_seq ELSE fs.from_seq END
                            ORDER BY CASE WHEN fs.from_seq < fs.to_seq THEN w.sequence_num ELSE -w.sequence_num END";
            $fallbackStmt = sqlsrv_query($conn, $fallbackSql, [$fromFix, $toFix, $fromFix, $toFix]);

            $routeGeometry = [];
            if ($fallbackStmt) {
                while ($row = sqlsrv_fetch_array($fallbackStmt, SQLSRV_FETCH_ASSOC)) {
                    $routeGeometry[] = [(float)$row['lon'], (float)$row['lat']];
                }
                sqlsrv_free_stmt($fallbackStmt);
            }

            if (count($routeGeometry) >= 2) {
                $monitorData[$monitorIdx]['geometry'] = $routeGeometry;
                $monitorData[$monitorIdx]['from_lat'] = $routeGeometry[0][1];
                $monitorData[$monitorIdx]['from_lon'] = $routeGeometry[0][0];
                $monitorData[$monitorIdx]['to_lat'] = $routeGeometry[count($routeGeometry)-1][1];
                $monitorData[$monitorIdx]['to_lon'] = $routeGeometry[count($routeGeometry)-1][0];
            }
        }

        // Final fallback: get endpoint coordinates individually from nav_fixes or waypoints
        if (count($routeGeometry) < 2) {
            // Query from_fix coordinate
            if ($monitorData[$monitorIdx]['from_lat'] === null) {
                $fixSql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE RTRIM(fix_name) = ?";
                $fixStmt = sqlsrv_query($conn, $fixSql, [$fromFix]);
                if ($fixStmt && $row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
                    $monitorData[$monitorIdx]['from_lat'] = (float)$row['lat'];
                    $monitorData[$monitorIdx]['from_lon'] = (float)$row['lon'];
                }
                if ($fixStmt) sqlsrv_free_stmt($fixStmt);

                // If not in nav_fixes, try adl_flight_waypoints
                if ($monitorData[$monitorIdx]['from_lat'] === null) {
                    $wpSql = "SELECT TOP 1 lat, lon FROM dbo.adl_flight_waypoints WHERE RTRIM(fix_name) = ? AND lat IS NOT NULL ORDER BY waypoint_id DESC";
                    $wpStmt = sqlsrv_query($conn, $wpSql, [$fromFix]);
                    if ($wpStmt && $row = sqlsrv_fetch_array($wpStmt, SQLSRV_FETCH_ASSOC)) {
                        $monitorData[$monitorIdx]['from_lat'] = (float)$row['lat'];
                        $monitorData[$monitorIdx]['from_lon'] = (float)$row['lon'];
                    }
                    if ($wpStmt) sqlsrv_free_stmt($wpStmt);
                }
            }

            // Query to_fix coordinate
            if ($monitorData[$monitorIdx]['to_lat'] === null) {
                $fixSql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE RTRIM(fix_name) = ?";
                $fixStmt = sqlsrv_query($conn, $fixSql, [$toFix]);
                if ($fixStmt && $row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
                    $monitorData[$monitorIdx]['to_lat'] = (float)$row['lat'];
                    $monitorData[$monitorIdx]['to_lon'] = (float)$row['lon'];
                }
                if ($fixStmt) sqlsrv_free_stmt($fixStmt);

                // If not in nav_fixes, try adl_flight_waypoints
                if ($monitorData[$monitorIdx]['to_lat'] === null) {
                    $wpSql = "SELECT TOP 1 lat, lon FROM dbo.adl_flight_waypoints WHERE RTRIM(fix_name) = ? AND lat IS NOT NULL ORDER BY waypoint_id DESC";
                    $wpStmt = sqlsrv_query($conn, $wpSql, [$toFix]);
                    if ($wpStmt && $row = sqlsrv_fetch_array($wpStmt, SQLSRV_FETCH_ASSOC)) {
                        $monitorData[$monitorIdx]['to_lat'] = (float)$row['lat'];
                        $monitorData[$monitorIdx]['to_lon'] = (float)$row['lon'];
                    }
                    if ($wpStmt) sqlsrv_free_stmt($wpStmt);
                }
            }

            // Build simple 2-point geometry if we have both endpoints
            if ($monitorData[$monitorIdx]['from_lat'] !== null && $monitorData[$monitorIdx]['to_lat'] !== null) {
                $monitorData[$monitorIdx]['geometry'] = [
                    [$monitorData[$monitorIdx]['from_lon'], $monitorData[$monitorIdx]['from_lat']],
                    [$monitorData[$monitorIdx]['to_lon'], $monitorData[$monitorIdx]['to_lat']]
                ];
            }
        }
    }
}

// Process via_fix monitors using fn_ViaDemandBucketed (or inline SQL with flight filters)
foreach ($viaMonitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $locationFilterType = $m['filter']['type'] ?? 'airport';
    $locationFilterCode = strtoupper($m['filter']['code'] ?? '');
    $direction = $m['filter']['direction'] ?? 'both';
    $viaValue = strtoupper($m['via']);
    $viaType = $m['via_type'] ?? 'fix';

    // Check if this monitor has a flight filter
    if (!empty($m['flight_filter'])) {
        // Use inline SQL with flight filter
        $flightFilterResult = buildFlightFilterClause($m['flight_filter']);
        $flightFilterClause = $flightFilterResult['clause'];
        $flightFilterParams = $flightFilterResult['params'];

        // Build location filter clause
        $locationClause = "1=1";
        switch ($locationFilterType) {
            case 'airport':
                if ($direction === 'arr') {
                    $locationClause = "fp.fp_dest_icao = ?";
                } else if ($direction === 'dep') {
                    $locationClause = "fp.fp_dept_icao = ?";
                } else {
                    $locationClause = "(fp.fp_dest_icao = ? OR fp.fp_dept_icao = ?)";
                }
                break;
            case 'tracon':
                if ($direction === 'arr') {
                    $locationClause = "fp.fp_dest_tracon = ?";
                } else if ($direction === 'dep') {
                    $locationClause = "fp.fp_dept_tracon = ?";
                } else {
                    $locationClause = "(fp.fp_dest_tracon = ? OR fp.fp_dept_tracon = ?)";
                }
                break;
            case 'artcc':
                if ($direction === 'arr') {
                    $locationClause = "fp.fp_dest_artcc = ?";
                } else if ($direction === 'dep') {
                    $locationClause = "fp.fp_dept_artcc = ?";
                } else {
                    $locationClause = "(fp.fp_dest_artcc = ? OR fp.fp_dept_artcc = ?)";
                }
                break;
        }
        $locationParams = ($direction === 'both') ? [$locationFilterCode, $locationFilterCode] : [$locationFilterCode];

        if ($viaType === 'fix') {
            // Check if viaValue looks like a SID/STAR procedure name
            $isProcedure = isProcedureName($viaValue);
            $isBaseName = couldBeProcedureBaseName($viaValue);

            if ($isProcedure || $isBaseName) {
                // Use procedure-aware matching on star_name/dp_name
                if ($isProcedure) {
                    // Full procedure name like SNFLD3 - exact match or base match
                    $baseName = preg_replace('/[0-9][A-Z]?$/', '', $viaValue);
                    $procMatchClause = "(fp.star_name = ? OR fp.star_name LIKE ? OR fp.dp_name = ? OR fp.dp_name LIKE ?)";
                    $procParams = [$viaValue, $baseName . '%', $viaValue, $baseName . '%'];
                } else {
                    // Base name like SNFLD - prefix match on star_name/dp_name
                    $procMatchClause = "(fp.star_name LIKE ? OR fp.dp_name LIKE ?)";
                    $procParams = [$viaValue . '%', $viaValue . '%'];
                }

                $sql = "WITH TimeBounds AS (
                            SELECT GETUTCDATE() AS start_time,
                                   DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                        ),
                        MatchingFlights AS (
                            SELECT DISTINCT c.flight_uid, t.eta_runway_utc
                            FROM dbo.adl_flight_core c
                            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                            INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                            CROSS JOIN TimeBounds tb
                            WHERE $procMatchClause
                              AND c.is_active = 1
                              AND c.phase NOT IN ('arrived', 'disconnected')
                              AND t.eta_runway_utc >= tb.start_time
                              AND t.eta_runway_utc < tb.end_time
                              AND $locationClause
                              $flightFilterClause
                        ),
                        BucketCounts AS (
                            SELECT
                                DATEDIFF(MINUTE, tb.start_time, mf.eta_runway_utc) / ? AS bucket_num,
                                COUNT(*) AS flight_count
                            FROM MatchingFlights mf
                            CROSS JOIN TimeBounds tb
                            GROUP BY DATEDIFF(MINUTE, tb.start_time, mf.eta_runway_utc) / ?
                        )
                        SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
                $params = array_merge([$horizonHours], $procParams, $locationParams, $flightFilterParams, [$bucketMinutes, $bucketMinutes]);
            } else {
                // Standard fix matching (not a procedure name)
                $sql = "WITH TimeBounds AS (
                            SELECT GETUTCDATE() AS start_time,
                                   DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                        ),
                        BucketCounts AS (
                            SELECT
                                DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ? AS bucket_num,
                                COUNT(DISTINCT w.flight_uid) AS flight_count
                            FROM dbo.adl_flight_waypoints w
                            CROSS JOIN TimeBounds tb
                            INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                            WHERE w.fix_name = ?
                              AND w.eta_utc >= tb.start_time
                              AND w.eta_utc < tb.end_time
                              AND c.is_active = 1
                              AND c.phase NOT IN ('arrived', 'disconnected')
                              AND $locationClause
                              $flightFilterClause
                            GROUP BY DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ?
                        )
                        SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
                $params = array_merge([$horizonHours, $bucketMinutes, $viaValue], $locationParams, $flightFilterParams, [$bucketMinutes]);
            }
        } else {
            // Via airway
            $sql = "WITH TimeBounds AS (
                        SELECT GETUTCDATE() AS start_time,
                               DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                    ),
                    BucketCounts AS (
                        SELECT
                            DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ? AS bucket_num,
                            COUNT(DISTINCT w.flight_uid) AS flight_count
                        FROM dbo.adl_flight_waypoints w
                        CROSS JOIN TimeBounds tb
                        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
                        WHERE (',' + ISNULL(w.on_airway, '') + ',') LIKE '%,' + ? + ',%'
                          AND w.eta_utc >= tb.start_time
                          AND w.eta_utc < tb.end_time
                          AND c.is_active = 1
                          AND c.phase NOT IN ('arrived', 'disconnected')
                          AND $locationClause
                          $flightFilterClause
                        GROUP BY DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / ?
                    )
                    SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
            $params = array_merge([$horizonHours, $bucketMinutes, $viaValue], $locationParams, $flightFilterParams, [$bucketMinutes]);
        }
    } else {
        // No flight filter - use SQL function OR inline SQL for procedures
        $isProcedure = isProcedureName($viaValue);
        $isBaseName = couldBeProcedureBaseName($viaValue);

        if ($viaType === 'fix' && ($isProcedure || $isBaseName)) {
            // For procedure-like values, use inline SQL with star_name/dp_name matching
            // Build location filter clause
            $locationClause = "1=1";
            switch ($locationFilterType) {
                case 'airport':
                    if ($direction === 'arr') {
                        $locationClause = "fp.fp_dest_icao = ?";
                    } else if ($direction === 'dep') {
                        $locationClause = "fp.fp_dept_icao = ?";
                    } else {
                        $locationClause = "(fp.fp_dest_icao = ? OR fp.fp_dept_icao = ?)";
                    }
                    break;
                case 'tracon':
                    if ($direction === 'arr') {
                        $locationClause = "fp.fp_dest_tracon = ?";
                    } else if ($direction === 'dep') {
                        $locationClause = "fp.fp_dept_tracon = ?";
                    } else {
                        $locationClause = "(fp.fp_dest_tracon = ? OR fp.fp_dept_tracon = ?)";
                    }
                    break;
                case 'artcc':
                    if ($direction === 'arr') {
                        $locationClause = "fp.fp_dest_artcc = ?";
                    } else if ($direction === 'dep') {
                        $locationClause = "fp.fp_dept_artcc = ?";
                    } else {
                        $locationClause = "(fp.fp_dest_artcc = ? OR fp.fp_dept_artcc = ?)";
                    }
                    break;
            }
            $locationParams = ($direction === 'both') ? [$locationFilterCode, $locationFilterCode] : [$locationFilterCode];

            // Build procedure match clause (no waypoint check - matching on procedure names directly)
            if ($isProcedure) {
                $baseName = preg_replace('/[0-9][A-Z]?$/', '', $viaValue);
                $procMatchClause = "(fp.star_name = ? OR fp.star_name LIKE ? OR fp.dp_name = ? OR fp.dp_name LIKE ?)";
                $procParams = [$viaValue, $baseName . '%', $viaValue, $baseName . '%'];
            } else {
                $procMatchClause = "(fp.star_name LIKE ? OR fp.dp_name LIKE ?)";
                $procParams = [$viaValue . '%', $viaValue . '%'];
            }

            $sql = "WITH TimeBounds AS (
                        SELECT GETUTCDATE() AS start_time,
                               DATEADD(HOUR, ?, GETUTCDATE()) AS end_time
                    ),
                    MatchingFlights AS (
                        SELECT DISTINCT c.flight_uid, t.eta_runway_utc
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        CROSS JOIN TimeBounds tb
                        WHERE $procMatchClause
                          AND c.is_active = 1
                          AND c.phase NOT IN ('arrived', 'disconnected')
                          AND t.eta_runway_utc >= tb.start_time
                          AND t.eta_runway_utc < tb.end_time
                          AND $locationClause
                    ),
                    BucketCounts AS (
                        SELECT
                            DATEDIFF(MINUTE, tb.start_time, mf.eta_runway_utc) / ? AS bucket_num,
                            COUNT(*) AS flight_count
                        FROM MatchingFlights mf
                        CROSS JOIN TimeBounds tb
                        GROUP BY DATEDIFF(MINUTE, tb.start_time, mf.eta_runway_utc) / ?
                    )
                    SELECT bucket_num, flight_count FROM BucketCounts ORDER BY bucket_num";
            $params = array_merge([$horizonHours], $procParams, $locationParams, [$bucketMinutes, $bucketMinutes]);
        } else {
            // Use SQL function for standard fixes and airways
            $sql = "SELECT * FROM dbo.fn_ViaDemandBucketed(?, ?, ?, ?, ?, ?, ?, NULL)";
            $params = [$locationFilterType, $locationFilterCode, $direction, $viaValue, $viaType, $bucketMinutes, $horizonHours];
        }
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $sqlErrors[] = ['function' => 'fn_ViaDemandBucketed', 'error' => adl_sql_error_message()];
    } else {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bucketNum = (int)$row['bucket_num'];
            $count = (int)$row['flight_count'];

            if ($bucketNum >= 0 && $bucketNum < $numBuckets) {
                $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
                $monitorData[$monitorIdx]['total'] += $count;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // Look up via fix coordinates (if via_type is 'fix')
    if ($viaType === 'fix') {
        $fixSql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE RTRIM(fix_name) = ?";
        $fixStmt = sqlsrv_query($conn, $fixSql, [$viaValue]);
        if ($fixStmt && $row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
            $monitorData[$monitorIdx]['lat'] = (float)$row['lat'];
            $monitorData[$monitorIdx]['lon'] = (float)$row['lon'];
        }
        if ($fixStmt) sqlsrv_free_stmt($fixStmt);

        // Fallback: try adl_flight_waypoints if nav_fixes doesn't have it
        if ($monitorData[$monitorIdx]['lat'] === null) {
            $wpSql = "SELECT TOP 1 lat, lon FROM dbo.adl_flight_waypoints WHERE RTRIM(fix_name) = ? AND lat IS NOT NULL ORDER BY waypoint_id DESC";
            $wpStmt = sqlsrv_query($conn, $wpSql, [$viaValue]);
            if ($wpStmt && $row = sqlsrv_fetch_array($wpStmt, SQLSRV_FETCH_ASSOC)) {
                $monitorData[$monitorIdx]['lat'] = (float)$row['lat'];
                $monitorData[$monitorIdx]['lon'] = (float)$row['lon'];
            }
            if ($wpStmt) sqlsrv_free_stmt($wpStmt);
        }
    }
}

// =========================================================================
// BATCH COORDINATE LOOKUP - Replace N+1 queries with 2 queries max
// =========================================================================

// Step 1: Collect all unique fix names needing coordinates
$fixesNeedingCoords = [];
foreach ($monitorData as $monitor) {
    if ($monitor['type'] === 'fix' && $monitor['lat'] === null) {
        $fixesNeedingCoords[strtoupper($monitor['fix'])] = true;
    } else if ($monitor['type'] === 'segment') {
        if ($monitor['from_lat'] === null) {
            $fixesNeedingCoords[strtoupper($monitor['from_fix'])] = true;
        }
        if ($monitor['to_lat'] === null) {
            $fixesNeedingCoords[strtoupper($monitor['to_fix'])] = true;
        }
    }
}

// Step 2: Batch query nav_fixes (1 query for all fixes)
$fixCoords = [];
if (!empty($fixesNeedingCoords)) {
    $fixNames = array_keys($fixesNeedingCoords);
    $placeholders = implode(',', array_fill(0, count($fixNames), '?'));

    $sql = "SELECT RTRIM(fix_name) AS fix_name, lat, lon
            FROM dbo.nav_fixes
            WHERE RTRIM(fix_name) IN ($placeholders)";
    $stmt = sqlsrv_query($conn, $sql, $fixNames);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fixCoords[strtoupper(trim($row['fix_name']))] = [
                'lat' => (float)$row['lat'],
                'lon' => (float)$row['lon']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Step 3: Fallback query for missing fixes (1 query)
    $missingFixes = array_diff($fixNames, array_keys($fixCoords));
    if (!empty($missingFixes)) {
        $placeholders = implode(',', array_fill(0, count($missingFixes), '?'));
        $sql = "SELECT RTRIM(fix_name) AS fix_name, lat, lon
                FROM (
                    SELECT fix_name, lat, lon,
                           ROW_NUMBER() OVER (PARTITION BY RTRIM(fix_name) ORDER BY waypoint_id DESC) AS rn
                    FROM dbo.adl_flight_waypoints
                    WHERE RTRIM(fix_name) IN ($placeholders) AND lat IS NOT NULL
                ) sub WHERE rn = 1";
        $stmt = sqlsrv_query($conn, $sql, array_values($missingFixes));
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $fixCoords[strtoupper(trim($row['fix_name']))] = [
                    'lat' => (float)$row['lat'],
                    'lon' => (float)$row['lon']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

// Step 4: Apply coordinates to monitors
foreach ($monitorData as $monitorIdx => &$monitor) {
    if ($monitor['type'] === 'fix' && $monitor['lat'] === null) {
        $key = strtoupper($monitor['fix']);
        if (isset($fixCoords[$key])) {
            $monitor['lat'] = $fixCoords[$key]['lat'];
            $monitor['lon'] = $fixCoords[$key]['lon'];
        }
    } else if ($monitor['type'] === 'segment') {
        $fromKey = strtoupper($monitor['from_fix']);
        $toKey = strtoupper($monitor['to_fix']);
        if ($monitor['from_lat'] === null && isset($fixCoords[$fromKey])) {
            $monitor['from_lat'] = $fixCoords[$fromKey]['lat'];
            $monitor['from_lon'] = $fixCoords[$fromKey]['lon'];
        }
        if ($monitor['to_lat'] === null && isset($fixCoords[$toKey])) {
            $monitor['to_lat'] = $fixCoords[$toKey]['lat'];
            $monitor['to_lon'] = $fixCoords[$toKey]['lon'];
        }
    }
}
unset($monitor);

sqlsrv_close($conn);

// Build bucket time labels
$buckets = [];
for ($i = 0; $i < $numBuckets; $i++) {
    $bucketStart = clone $now;
    $bucketStart->add(new DateInterval('PT' . ($i * $bucketMinutes) . 'M'));
    $label = '+' . ($i * $bucketMinutes);
    $buckets[] = [
        'index' => $i,
        'start' => $bucketStart->format('Y-m-d\TH:i:s\Z'),
        'label' => $label
    ];
}

// Build response
$response = [
    "generated_utc" => $now->format('Y-m-d\TH:i:s\Z'),
    "bucket_minutes" => $bucketMinutes,
    "horizon_hours" => $horizonHours,
    "num_buckets" => $numBuckets,
    "buckets" => $buckets,
    "monitors" => array_values($monitorData)
];

// Add SQL errors if any occurred (for debugging)
if (!empty($sqlErrors)) {
    $response['sql_errors'] = $sqlErrors;
}

// Cache for 60 seconds
swim_cache_set($cache_key, $response, 60);

echo json_encode($response, JSON_PRETTY_PRINT);
