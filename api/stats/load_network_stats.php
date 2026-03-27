<?php
/**
 * VATSIM Network Stats Loader
 *
 * Fetches current network statistics from VATSIM API and stores in VATSIM_STATS database.
 * Designed to be called frequently (e.g. every minute) but self-throttles
 * based on STATS_SNAPSHOT_MIN_INTERVAL_SEC.
 *
 * Usage:
 *   curl -s https://perti.vatcscc.org/api/stats/load_network_stats.php
 *
 * Cron (recommended every minute; script enforces its own min interval):
 *   * * * * * curl -s https://perti.vatcscc.org/api/stats/load_network_stats.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set up error handler to return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error',
        'message' => $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
});

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/config_stats.php';

// Verify STATS constants are defined
if (!defined('STATS_SQL_HOST') || !defined('STATS_SQL_DATABASE')) {
    echo json_encode([
        'success' => false,
        'error' => 'STATS database not configured',
        'defined' => [
            'STATS_SQL_HOST' => defined('STATS_SQL_HOST'),
            'STATS_SQL_DATABASE' => defined('STATS_SQL_DATABASE'),
            'STATS_SQL_USERNAME' => defined('STATS_SQL_USERNAME'),
            'STATS_SQL_PASSWORD' => defined('STATS_SQL_PASSWORD')
        ]
    ]);
    exit;
}

// Helper for SQL Server error messages
function stats_sql_error_message()
{
    if (!function_exists('sqlsrv_errors')) {
        return "sqlsrv extension not loaded";
    }
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) {
        return "Unknown error";
    }
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

// Prevent duplicate runs within configured interval (bypass with ?force=1 for testing)
$lockFile = sys_get_temp_dir() . '/vatsim_stats_loader.lock';
$forceRun = isset($_GET['force']) && $_GET['force'] === '1';
$snapshotMinInterval = defined('STATS_SNAPSHOT_MIN_INTERVAL_SEC')
    ? (int)STATS_SNAPSHOT_MIN_INTERVAL_SEC
    : 240;
if (!$forceRun && file_exists($lockFile) && (time() - filemtime($lockFile)) < $snapshotMinInterval) {
    echo json_encode([
        'success' => false,
        'error' => 'Snapshot run throttled by min interval',
        'last_run' => gmdate('Y-m-d\TH:i:s\Z', filemtime($lockFile)),
        'min_interval_seconds' => $snapshotMinInterval
    ]);
    exit;
}
touch($lockFile);

try {
    // Fetch VATSIM data
    $vatsimUrl = 'https://data.vatsim.net/v3/vatsim-data.json';
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header' => "User-Agent: PERTI-StatsLoader/1.0\r\n"
        ]
    ]);

    $json = @file_get_contents($vatsimUrl, false, $context);
    if ($json === false) {
        throw new Exception('Failed to fetch VATSIM API');
    }

    $data = json_decode($json, true);
    if (!$data) {
        throw new Exception('Invalid JSON from VATSIM API');
    }

    // Extract counts
    $totalPilots = isset($data['pilots']) ? count($data['pilots']) : 0;
    $totalControllers = isset($data['controllers']) ? count($data['controllers']) : 0;
    $totalPrefiles = isset($data['prefiles']) ? count($data['prefiles']) : 0;

    // Connect to VATSIM_STATS database using sqlsrv
    if (!function_exists('sqlsrv_connect')) {
        throw new Exception('sqlsrv extension not loaded');
    }

    $connectionInfo = [
        "Database" => STATS_SQL_DATABASE,
        "UID"      => STATS_SQL_USERNAME,
        "PWD"      => STATS_SQL_PASSWORD,
        "ConnectionPooling" => 1,
        "LoginTimeout" => 5
    ];

    $conn = sqlsrv_connect(STATS_SQL_HOST, $connectionInfo);
    if ($conn === false) {
        throw new Exception('Database connection failed: ' . stats_sql_error_message());
    }

    // Call the stored procedure to tag and insert the snapshot
    $snapshotTime = gmdate('Y-m-d H:i:s');
    $sql = "EXEC sp_TagNetworkSnapshot @snapshot_time = ?, @total_pilots = ?, @total_controllers = ?";

    $params = [$snapshotTime, $totalPilots, $totalControllers];
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new Exception('Stored procedure failed: ' . stats_sql_error_message());
    }
    sqlsrv_free_stmt($stmt);

    // Avoid extra verification query each run; use known inserted values.
    $lastRow = [
        'snapshot_time' => $snapshotTime,
        'total_pilots' => $totalPilots,
        'total_controllers' => $totalControllers
    ];

    sqlsrv_close($conn);

    // Regenerate analytics cache on a slower cadence (critical for free-tier CPU budget).
    $cacheResult = [
        'success' => false,
        'status' => 'skipped',
        'reason' => 'disabled'
    ];
    $cacheEnabled = defined('STATS_ENABLE_CACHE_REGEN') ? (bool)STATS_ENABLE_CACHE_REGEN : true;
    $cacheMinInterval = defined('STATS_CACHE_REFRESH_INTERVAL_SEC')
        ? (int)STATS_CACHE_REFRESH_INTERVAL_SEC
        : 300;
    $cacheLockFile = sys_get_temp_dir() . '/vatsim_stats_cache_refresh.lock';
    $forceCache = isset($_GET['force_cache']) && $_GET['force_cache'] === '1';

    if ($cacheEnabled) {
        $cacheGeneratorPath = __DIR__ . '/generate_cache.php';
        if (file_exists($cacheGeneratorPath)) {
            $cacheStale = !file_exists($cacheLockFile) || (time() - filemtime($cacheLockFile)) >= $cacheMinInterval;
            if ($forceCache || $cacheStale) {
                $previousInternal = $_GET['internal'] ?? null;
                try {
                    ob_start();
                    $_GET['internal'] = '1'; // Mark as internal call
                    include $cacheGeneratorPath;
                    $cacheOutput = ob_get_clean();
                    $decoded = json_decode($cacheOutput, true);
                    $cacheResult = is_array($decoded)
                        ? $decoded
                        : ['success' => false, 'status' => 'error', 'reason' => 'invalid_json'];
                    if (!empty($cacheResult['success'])) {
                        @touch($cacheLockFile);
                    }
                } catch (Throwable $cacheEx) {
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    $cacheResult = [
                        'success' => false,
                        'status' => 'error',
                        'reason' => 'exception',
                        'message' => $cacheEx->getMessage()
                    ];
                } finally {
                    if ($previousInternal === null) {
                        unset($_GET['internal']);
                    } else {
                        $_GET['internal'] = $previousInternal;
                    }
                }
            } else {
                $cacheResult = [
                    'success' => false,
                    'status' => 'skipped',
                    'reason' => 'refresh_interval',
                    'min_interval_seconds' => $cacheMinInterval,
                    'last_refresh_utc' => gmdate('Y-m-d\TH:i:s\Z', filemtime($cacheLockFile))
                ];
            }
        } else {
            $cacheResult = [
                'success' => false,
                'status' => 'skipped',
                'reason' => 'generator_missing'
            ];
        }
    }

    $response = [
        'success' => true,
        'timestamp' => $snapshotTime,
        'snapshot_min_interval_seconds' => $snapshotMinInterval,
        'data' => [
            'pilots' => $totalPilots,
            'controllers' => $totalControllers,
            'prefiles' => $totalPrefiles
        ],
        'inserted' => $lastRow,
        'source' => 'vatsim_api_v3',
        'cache_regenerated' => !empty($cacheResult['success']),
        'cache' => $cacheResult
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
