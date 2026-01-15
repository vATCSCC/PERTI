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
 * Monitor JSON format:
 *   [
 *     { "type": "fix", "fix": "MERIT" },
 *     { "type": "segment", "from": "CAM", "to": "GONZZ" }
 *   ]
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

// Execute query
$sql = "SELECT * FROM dbo.fn_BatchDemandBucketed(?, ?, ?, NULL) ORDER BY monitor_idx, bucket_num";
$params = [$monitorsJson, $bucketMinutes, $horizonHours];

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Query execution failed.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

// Fetch results and organize by monitor
$now = new DateTime('now', new DateTimeZone('UTC'));
$numBuckets = (int)ceil(($horizonHours * 60) / $bucketMinutes);

// Initialize monitors array with metadata
$monitorData = [];
foreach ($monitors as $idx => $m) {
    $monitorIdx = $idx + 1; // SQL ROW_NUMBER starts at 1
    $id = '';
    if ($m['type'] === 'fix') {
        $id = 'fix_' . strtoupper($m['fix']);
        $monitorData[$monitorIdx] = [
            'id' => $id,
            'type' => 'fix',
            'fix' => strtoupper($m['fix']),
            'lat' => null,
            'lon' => null,
            'counts' => array_fill(0, $numBuckets, 0),
            'total' => 0
        ];
    } else if ($m['type'] === 'segment') {
        $id = 'segment_' . strtoupper($m['from']) . '_' . strtoupper($m['to']);
        $monitorData[$monitorIdx] = [
            'id' => $id,
            'type' => 'segment',
            'from_fix' => strtoupper($m['from']),
            'to_fix' => strtoupper($m['to']),
            'from_lat' => null,
            'from_lon' => null,
            'to_lat' => null,
            'to_lon' => null,
            'counts' => array_fill(0, $numBuckets, 0),
            'total' => 0
        ];
    }
}

// Fill in data from query results
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $monitorIdx = (int)$row['monitor_idx'];
    $bucketNum = (int)$row['bucket_num'];
    $count = (int)$row['flight_count'];

    if (isset($monitorData[$monitorIdx]) && $bucketNum >= 0 && $bucketNum < $numBuckets) {
        $monitorData[$monitorIdx]['counts'][$bucketNum] = $count;
        $monitorData[$monitorIdx]['total'] += $count;

        // Set coordinates if not already set
        if ($monitorData[$monitorIdx]['type'] === 'fix') {
            if ($monitorData[$monitorIdx]['lat'] === null && $row['fix_lat'] !== null) {
                $monitorData[$monitorIdx]['lat'] = (float)$row['fix_lat'];
                $monitorData[$monitorIdx]['lon'] = (float)$row['fix_lon'];
            }
        } else {
            if ($monitorData[$monitorIdx]['from_lat'] === null && $row['from_lat'] !== null) {
                $monitorData[$monitorIdx]['from_lat'] = (float)$row['from_lat'];
                $monitorData[$monitorIdx]['from_lon'] = (float)$row['from_lon'];
                $monitorData[$monitorIdx]['to_lat'] = (float)$row['to_lat'];
                $monitorData[$monitorIdx]['to_lon'] = (float)$row['to_lon'];
            }
        }
    }
}

sqlsrv_free_stmt($stmt);

// If coordinates weren't returned (no demand), look them up
foreach ($monitorData as $monitorIdx => &$monitor) {
    if ($monitor['type'] === 'fix' && $monitor['lat'] === null) {
        // Look up fix coordinates
        $fixSql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE fix_name = ?";
        $fixStmt = sqlsrv_query($conn, $fixSql, [$monitor['fix']]);
        if ($fixStmt && $row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
            $monitor['lat'] = (float)$row['lat'];
            $monitor['lon'] = (float)$row['lon'];
        }
        if ($fixStmt) sqlsrv_free_stmt($fixStmt);
    } else if ($monitor['type'] === 'segment' && $monitor['from_lat'] === null) {
        // Look up segment coordinates
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

echo json_encode($response, JSON_PRETTY_PRINT);
