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

require_once(__DIR__ . '/../../../load/config.php');

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

// Process batch monitors (fix, segment) using fn_BatchDemandBucketed
if (!empty($batchMonitors)) {
    $batchJson = json_encode(array_values($batchMonitors));
    $sql = "SELECT * FROM dbo.fn_BatchDemandBucketed(?, ?, ?, NULL) ORDER BY monitor_idx, bucket_num";
    $params = [$batchJson, $bucketMinutes, $horizonHours];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $sqlErrors[] = ['function' => 'fn_BatchDemandBucketed', 'error' => adl_sql_error_message()];
    } else {
        // Map batch index back to original index
        $batchIndexMap = array_keys($batchMonitors);

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

// Process airway monitors using fn_AirwayDemandBucketed
foreach ($airwayMonitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $sql = "SELECT * FROM dbo.fn_AirwayDemandBucketed(?, ?, ?, NULL)";
    $params = [strtoupper($m['airway']), $bucketMinutes, $horizonHours];

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

    // Look up airway midpoint coordinates from airway_segments
    $airwaySql = "SELECT TOP 1 from_lat, from_lon, to_lat, to_lon
                  FROM dbo.airway_segments
                  WHERE airway_name = ?
                  ORDER BY sequence_num";
    $airwayStmt = sqlsrv_query($conn, $airwaySql, [strtoupper($m['airway'])]);
    if ($airwayStmt && $row = sqlsrv_fetch_array($airwayStmt, SQLSRV_FETCH_ASSOC)) {
        // Use the first segment's from fix as the display point
        $monitorData[$monitorIdx]['lat'] = (float)$row['from_lat'];
        $monitorData[$monitorIdx]['lon'] = (float)$row['from_lon'];
    }
    if ($airwayStmt) sqlsrv_free_stmt($airwayStmt);
}

// Process airway segment monitors using fn_AirwaySegmentDemandBucketed
foreach ($airwaySegmentMonitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $sql = "SELECT * FROM dbo.fn_AirwaySegmentDemandBucketed(?, ?, ?, ?, ?, NULL)";
    $params = [strtoupper($m['airway']), strtoupper($m['from']), strtoupper($m['to']), $bucketMinutes, $horizonHours];

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

    // Look up segment coordinates
    $fixSql = "SELECT fix_name, lat, lon FROM dbo.nav_fixes WHERE fix_name IN (?, ?)";
    $fixStmt = sqlsrv_query($conn, $fixSql, [strtoupper($m['from']), strtoupper($m['to'])]);
    if ($fixStmt) {
        while ($row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
            if (strtoupper($row['fix_name']) === $monitorData[$monitorIdx]['from_fix']) {
                $monitorData[$monitorIdx]['from_lat'] = (float)$row['lat'];
                $monitorData[$monitorIdx]['from_lon'] = (float)$row['lon'];
            } else if (strtoupper($row['fix_name']) === $monitorData[$monitorIdx]['to_fix']) {
                $monitorData[$monitorIdx]['to_lat'] = (float)$row['lat'];
                $monitorData[$monitorIdx]['to_lon'] = (float)$row['lon'];
            }
        }
        sqlsrv_free_stmt($fixStmt);
    }
}

// Process via_fix monitors using fn_ViaDemandBucketed
foreach ($viaMonitors as $idx => $m) {
    $monitorIdx = $idx + 1;
    $filterType = $m['filter']['type'] ?? 'airport';
    $filterCode = strtoupper($m['filter']['code'] ?? '');
    $direction = $m['filter']['direction'] ?? 'both';
    $viaValue = strtoupper($m['via']);
    $viaType = $m['via_type'] ?? 'fix';

    $sql = "SELECT * FROM dbo.fn_ViaDemandBucketed(?, ?, ?, ?, ?, ?, ?, NULL)";
    $params = [$filterType, $filterCode, $direction, $viaValue, $viaType, $bucketMinutes, $horizonHours];

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
        $fixSql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE fix_name = ?";
        $fixStmt = sqlsrv_query($conn, $fixSql, [$viaValue]);
        if ($fixStmt && $row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
            $monitorData[$monitorIdx]['lat'] = (float)$row['lat'];
            $monitorData[$monitorIdx]['lon'] = (float)$row['lon'];
        }
        if ($fixStmt) sqlsrv_free_stmt($fixStmt);
    }
}

// Look up missing coordinates for fix/segment monitors
foreach ($monitorData as $monitorIdx => &$monitor) {
    if ($monitor['type'] === 'fix' && $monitor['lat'] === null) {
        $fixSql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE fix_name = ?";
        $fixStmt = sqlsrv_query($conn, $fixSql, [$monitor['fix']]);
        if ($fixStmt && $row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
            $monitor['lat'] = (float)$row['lat'];
            $monitor['lon'] = (float)$row['lon'];
        }
        if ($fixStmt) sqlsrv_free_stmt($fixStmt);
    } else if ($monitor['type'] === 'segment' && $monitor['from_lat'] === null) {
        $fixSql = "SELECT fix_name, lat, lon FROM dbo.nav_fixes WHERE fix_name IN (?, ?)";
        $fixStmt = sqlsrv_query($conn, $fixSql, [$monitor['from_fix'], $monitor['to_fix']]);
        if ($fixStmt) {
            while ($row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
                if (strtoupper($row['fix_name']) === $monitor['from_fix']) {
                    $monitor['from_lat'] = (float)$row['lat'];
                    $monitor['from_lon'] = (float)$row['lon'];
                } else if (strtoupper($row['fix_name']) === $monitor['to_fix']) {
                    $monitor['to_lat'] = (float)$row['lat'];
                    $monitor['to_lon'] = (float)$row['lon'];
                }
            }
            sqlsrv_free_stmt($fixStmt);
        }
    }
}

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

echo json_encode($response, JSON_PRETTY_PRINT);
