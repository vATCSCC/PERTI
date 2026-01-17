<?php
/**
 * api/system/health.php
 *
 * System health monitoring endpoint for PERTI/SWIM infrastructure.
 * Returns PHP-FPM stats, database connection health, daemon status, and resource usage.
 *
 * Access: Requires admin authentication or localhost access
 *
 * Cost: FREE - uses built-in metrics only
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Load config first to get MONITORING_API_KEY
require_once(__DIR__ . '/../../load/config.php');

// Simple auth check - allow localhost or require API key
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
$apiKey = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
// Use MONITORING_API_KEY from config if defined, otherwise use default
$validKey = (defined('MONITORING_API_KEY') && MONITORING_API_KEY !== '')
    ? MONITORING_API_KEY
    : 'perti-vatcscc-health';

if (!$isLocalhost && $apiKey !== $validKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Use ?key=YOUR_KEY or access from localhost.']);
    exit;
}

$health = [
    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'status' => 'healthy',
    'checks' => [],
];

// ============================================================================
// 1. PHP-FPM Status (if status page enabled)
// ============================================================================
$fpmStatus = null;
$fpmUrl = 'http://127.0.0.1/fpm-status?json';

// Try to fetch FPM status via internal request
$ctx = stream_context_create(['http' => ['timeout' => 2]]);
$fpmJson = @file_get_contents($fpmUrl, false, $ctx);

if ($fpmJson !== false) {
    $fpmStatus = json_decode($fpmJson, true);
}

if ($fpmStatus) {
    $activeWorkers = $fpmStatus['active processes'] ?? 0;
    $maxChildren = $fpmStatus['max children reached'] ?? 0;
    $listenQueue = $fpmStatus['listen queue'] ?? 0;
    $idleWorkers = $fpmStatus['idle processes'] ?? 0;
    $totalProcesses = $fpmStatus['total processes'] ?? 0;

    $health['checks']['php_fpm'] = [
        'status' => ($listenQueue > 10 || $maxChildren > 0) ? 'warning' : 'healthy',
        'active_workers' => $activeWorkers,
        'idle_workers' => $idleWorkers,
        'total_workers' => $totalProcesses,
        'listen_queue' => $listenQueue,
        'max_children_reached' => $maxChildren,
        'accepted_connections' => $fpmStatus['accepted conn'] ?? 0,
        'slow_requests' => $fpmStatus['slow requests'] ?? 0,
    ];

    if ($listenQueue > 10) {
        $health['status'] = 'degraded';
    }
} else {
    // Fallback: count PHP processes
    $phpProcesses = 0;
    if (PHP_OS_FAMILY !== 'Windows') {
        exec('ps aux | grep -c "[p]hp-fpm"', $output);
        $phpProcesses = (int)($output[0] ?? 0);
    }

    $health['checks']['php_fpm'] = [
        'status' => 'unknown',
        'note' => 'FPM status page not accessible. Enable pm.status_path in php-fpm.conf',
        'process_count' => $phpProcesses,
    ];
}

// ============================================================================
// 2. Database Connection Health
// ============================================================================
$dbHealth = ['status' => 'unknown', 'connections' => []];

// ADL Database
if (defined('ADL_SQL_HOST') && defined('ADL_SQL_DATABASE')) {
    $connInfo = [
        'Database' => ADL_SQL_DATABASE,
        'UID' => ADL_SQL_USERNAME,
        'PWD' => ADL_SQL_PASSWORD,
        'LoginTimeout' => 5,
        'ConnectionPooling' => true,
    ];

    $startTime = microtime(true);
    $conn = @sqlsrv_connect(ADL_SQL_HOST, $connInfo);
    $connectTime = round((microtime(true) - $startTime) * 1000);

    if ($conn !== false) {
        // Check for blocking sessions
        $blockingSql = "
            SELECT COUNT(*) AS blocking_count
            FROM sys.dm_exec_requests
            WHERE blocking_session_id != 0
        ";
        $stmt = @sqlsrv_query($conn, $blockingSql);
        $blockingCount = 0;
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $blockingCount = (int)($row['blocking_count'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        // Check active connections
        $connSql = "
            SELECT COUNT(*) AS active_connections
            FROM sys.dm_exec_sessions
            WHERE is_user_process = 1
        ";
        $stmt = @sqlsrv_query($conn, $connSql);
        $activeConns = 0;
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $activeConns = (int)($row['active_connections'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        // Check app locks
        $lockSql = "
            SELECT resource_description, request_session_id, request_mode
            FROM sys.dm_tran_locks
            WHERE resource_type = 'APPLICATION'
        ";
        $stmt = @sqlsrv_query($conn, $lockSql);
        $appLocks = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $appLocks[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }

        sqlsrv_close($conn);

        $dbHealth['connections']['adl'] = [
            'status' => $blockingCount > 0 ? 'warning' : 'healthy',
            'connect_time_ms' => $connectTime,
            'active_connections' => $activeConns,
            'blocking_sessions' => $blockingCount,
            'app_locks' => $appLocks,
        ];

        if ($blockingCount > 0) {
            $health['status'] = 'degraded';
        }
    } else {
        $errors = sqlsrv_errors();
        $dbHealth['connections']['adl'] = [
            'status' => 'error',
            'error' => $errors[0]['message'] ?? 'Connection failed',
        ];
        $health['status'] = 'unhealthy';
    }
}

// SWIM Database (if configured)
if (defined('SWIM_SQL_HOST') && defined('SWIM_SQL_DATABASE')) {
    $connInfo = [
        'Database' => SWIM_SQL_DATABASE,
        'UID' => SWIM_SQL_USERNAME ?? ADL_SQL_USERNAME,
        'PWD' => SWIM_SQL_PASSWORD ?? ADL_SQL_PASSWORD,
        'LoginTimeout' => 5,
    ];

    $startTime = microtime(true);
    $conn = @sqlsrv_connect(SWIM_SQL_HOST, $connInfo);
    $connectTime = round((microtime(true) - $startTime) * 1000);

    if ($conn !== false) {
        sqlsrv_close($conn);
        $dbHealth['connections']['swim'] = [
            'status' => 'healthy',
            'connect_time_ms' => $connectTime,
        ];
    } else {
        $dbHealth['connections']['swim'] = [
            'status' => 'error',
            'connect_time_ms' => $connectTime,
        ];
    }
}

$dbHealth['status'] = 'healthy';
foreach ($dbHealth['connections'] as $db => $info) {
    if ($info['status'] === 'error') {
        $dbHealth['status'] = 'unhealthy';
        break;
    } elseif ($info['status'] === 'warning') {
        $dbHealth['status'] = 'degraded';
    }
}

$health['checks']['database'] = $dbHealth;

// ============================================================================
// 3. Daemon Health (check PID files)
// ============================================================================
$daemonHealth = ['status' => 'unknown', 'daemons' => []];

$pidFiles = [
    'boundary_daemon' => sys_get_temp_dir() . '/adl_boundary_daemon.pid',
    'parse_queue_daemon' => sys_get_temp_dir() . '/adl_parse_queue_daemon.pid',
    'waypoint_eta_daemon' => sys_get_temp_dir() . '/adl_waypoint_eta_daemon.pid',
    'archival_daemon' => sys_get_temp_dir() . '/perti_archival_daemon.pid',
];

foreach ($pidFiles as $name => $pidFile) {
    $status = 'unknown';
    $pid = null;

    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);

        // Check if process is running
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
            $running = count($output) > 1;
        } else {
            $running = posix_kill($pid, 0);
        }

        $status = $running ? 'running' : 'stale_pid';
    } else {
        $status = 'not_running';
    }

    $daemonHealth['daemons'][$name] = [
        'status' => $status,
        'pid' => $pid,
        'pid_file' => $pidFile,
    ];
}

// Determine overall daemon health
$runningCount = 0;
foreach ($daemonHealth['daemons'] as $d) {
    if ($d['status'] === 'running') $runningCount++;
}
$daemonHealth['status'] = $runningCount >= 3 ? 'healthy' : ($runningCount > 0 ? 'degraded' : 'unhealthy');
$daemonHealth['running_count'] = $runningCount;
$daemonHealth['total_count'] = count($pidFiles);

$health['checks']['daemons'] = $daemonHealth;

// ============================================================================
// 4. Memory Usage
// ============================================================================
$memoryHealth = [
    'php_memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
    'php_memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
    'php_memory_limit' => ini_get('memory_limit'),
];

// System memory (Linux only)
if (PHP_OS_FAMILY !== 'Windows' && file_exists('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

    if ($total && $available) {
        $totalMb = round($total[1] / 1024);
        $availableMb = round($available[1] / 1024);
        $usedMb = $totalMb - $availableMb;
        $usedPct = round(($usedMb / $totalMb) * 100, 1);

        $memoryHealth['system_total_mb'] = $totalMb;
        $memoryHealth['system_available_mb'] = $availableMb;
        $memoryHealth['system_used_mb'] = $usedMb;
        $memoryHealth['system_used_pct'] = $usedPct;
        $memoryHealth['status'] = $usedPct > 90 ? 'warning' : 'healthy';
    }
}

$health['checks']['memory'] = $memoryHealth;

// ============================================================================
// 5. Recent Errors (from log files if accessible)
// ============================================================================
$logDir = '/home/LogFiles';
if (is_dir($logDir)) {
    $recentErrors = [];
    $logFiles = ['vatsim_adl.log', 'boundary.log', 'parse_queue.log', 'waypoint_eta.log'];

    foreach ($logFiles as $logFile) {
        $path = "$logDir/$logFile";
        if (file_exists($path)) {
            // Get last 50 lines and filter for errors
            $lines = array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -50);
            foreach ($lines as $line) {
                if (stripos($line, 'error') !== false || stripos($line, 'exception') !== false) {
                    $recentErrors[] = [
                        'file' => $logFile,
                        'message' => substr($line, 0, 200),
                    ];
                }
            }
        }
    }

    $health['checks']['logs'] = [
        'recent_errors' => array_slice($recentErrors, -10),
        'error_count' => count($recentErrors),
    ];
}

// ============================================================================
// Final Status
// ============================================================================
foreach ($health['checks'] as $check) {
    if (isset($check['status'])) {
        if ($check['status'] === 'unhealthy') {
            $health['status'] = 'unhealthy';
            break;
        } elseif ($check['status'] === 'degraded' && $health['status'] !== 'unhealthy') {
            $health['status'] = 'degraded';
        }
    }
}

// Set HTTP status code based on health
if ($health['status'] === 'unhealthy') {
    http_response_code(503);
} elseif ($health['status'] === 'degraded') {
    http_response_code(200); // Still 200 but status shows degraded
}

echo json_encode($health, JSON_PRETTY_PRINT);
