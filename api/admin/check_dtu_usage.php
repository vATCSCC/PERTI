<?php
/**
 * DTU Usage Diagnostic - Check Azure SQL resource consumption
 *
 * Run via: https://perti.vatcscc.org/api/admin/check_dtu_usage.php
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Auth check (same as migration scripts)
$authorized = false;
if (isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID'])) {
    global $conn_sqli;
    $cid = $_SESSION['VATSIM_CID'];
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    if ($p_check && $p_check->num_rows > 0) {
        $authorized = true;
    }
}

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authorized && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    if ($matches[1] === 'swim_sys_vatcscc_internal_001') {
        $authorized = true;
    }
}

// Also allow query param for easy browser access
if (!$authorized && isset($_GET['key']) && $_GET['key'] === 'swim_sys_vatcscc_internal_001') {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

global $conn_swim;
if (!$conn_swim) {
    echo json_encode(['success' => false, 'error' => 'SWIM database connection not available']);
    exit;
}

$results = [
    'success' => true,
    'timestamp' => gmdate('c'),
    'database' => [],
    'resource_stats' => [],
    'table_stats' => [],
    'recent_activity' => [],
];

// Get database info
$sql = "SELECT DB_NAME() AS db_name, @@VERSION AS sql_version";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['database']['name'] = $row['db_name'];
    $results['database']['version'] = substr($row['sql_version'], 0, 100) . '...';
    sqlsrv_free_stmt($stmt);
}

// Get resource stats (Azure SQL specific - last 15 minutes)
// This view is available in Azure SQL Database
$sql = "
    SELECT TOP 15
        end_time,
        avg_cpu_percent,
        avg_data_io_percent,
        avg_log_write_percent,
        avg_memory_usage_percent,
        max_worker_percent,
        max_session_percent
    FROM sys.dm_db_resource_stats
    ORDER BY end_time DESC
";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $endTime = $row['end_time'];
        if ($endTime instanceof DateTime) {
            $endTime = $endTime->format('Y-m-d H:i:s');
        }
        $results['resource_stats'][] = [
            'time' => $endTime,
            'cpu_pct' => round($row['avg_cpu_percent'], 1),
            'data_io_pct' => round($row['avg_data_io_percent'], 1),
            'log_write_pct' => round($row['avg_log_write_percent'], 1),
            'memory_pct' => round($row['avg_memory_usage_percent'] ?? 0, 1),
            'worker_pct' => round($row['max_worker_percent'], 1),
            'session_pct' => round($row['max_session_percent'], 1),
        ];
    }
    sqlsrv_free_stmt($stmt);
} else {
    $results['resource_stats_error'] = 'dm_db_resource_stats not available (may not be Azure SQL)';
}

// Calculate summary stats
if (!empty($results['resource_stats'])) {
    $cpuValues = array_column($results['resource_stats'], 'cpu_pct');
    $ioValues = array_column($results['resource_stats'], 'data_io_pct');

    $results['summary'] = [
        'period' => 'Last 15 minutes',
        'cpu' => [
            'avg' => round(array_sum($cpuValues) / count($cpuValues), 1),
            'max' => max($cpuValues),
            'min' => min($cpuValues),
        ],
        'data_io' => [
            'avg' => round(array_sum($ioValues) / count($ioValues), 1),
            'max' => max($ioValues),
            'min' => min($ioValues),
        ],
        'assessment' => '',
    ];

    // Provide assessment
    $maxCpu = max($cpuValues);
    $avgCpu = $results['summary']['cpu']['avg'];
    if ($maxCpu > 90) {
        $results['summary']['assessment'] = 'HIGH: DTU frequently maxed out. Consider upgrading tier.';
    } elseif ($maxCpu > 70) {
        $results['summary']['assessment'] = 'MODERATE: DTU usage elevated. 60s sync may cause throttling.';
    } elseif ($avgCpu > 50) {
        $results['summary']['assessment'] = 'FAIR: Room for improvement but monitor closely with faster sync.';
    } else {
        $results['summary']['assessment'] = 'GOOD: Plenty of headroom. 60s sync should work fine.';
    }
}

// Get table row counts
$sql = "
    SELECT
        t.name AS table_name,
        p.rows AS row_count
    FROM sys.tables t
    INNER JOIN sys.partitions p ON t.object_id = p.object_id AND p.index_id IN (0, 1)
    WHERE t.name LIKE 'swim_%'
    ORDER BY p.rows DESC
";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results['table_stats'][] = [
            'table' => $row['table_name'],
            'rows' => (int)$row['row_count'],
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// Get active flight count
$sql = "SELECT COUNT(*) as active_count FROM dbo.swim_flights WHERE is_active = 1";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['active_flights'] = (int)$row['active_count'];
    sqlsrv_free_stmt($stmt);
}

// Get stale flight count (would be marked inactive on next cleanup)
$sql = "
    SELECT COUNT(*) as stale_count
    FROM dbo.swim_flights
    WHERE is_active = 1
      AND last_sync_utc < DATEADD(MINUTE, -5, GETUTCDATE())
";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['stale_flights'] = (int)$row['stale_count'];
    sqlsrv_free_stmt($stmt);
}

echo json_encode($results, JSON_PRETTY_PRINT);
