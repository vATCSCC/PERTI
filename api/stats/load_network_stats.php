<?php
/**
 * VATSIM Network Stats Loader
 *
 * Fetches current network statistics from VATSIM API and stores in VATSIM_STATS database.
 * Designed to run every 5 minutes via cron or Azure WebJob.
 *
 * Usage:
 *   curl -s https://perti.vatcscc.org/api/stats/load_network_stats.php
 *
 * Cron (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * curl -s https://perti.vatcscc.org/api/stats/load_network_stats.php
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

// Prevent duplicate runs within 4 minutes (bypass with ?force=1 for testing)
$lockFile = sys_get_temp_dir() . '/vatsim_stats_loader.lock';
$forceRun = isset($_GET['force']) && $_GET['force'] === '1';
if (!$forceRun && file_exists($lockFile) && (time() - filemtime($lockFile)) < 240) {
    echo json_encode([
        'success' => false,
        'error' => 'Another instance ran recently',
        'last_run' => date('Y-m-d H:i:s', filemtime($lockFile))
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
        "ConnectionPooling" => 1
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

    // Get the inserted row to verify
    $verifySql = "SELECT TOP 1 snapshot_time, total_pilots, total_controllers, traffic_level
                  FROM fact_network_5min
                  ORDER BY snapshot_time DESC";
    $verifyStmt = sqlsrv_query($conn, $verifySql);

    $lastRow = null;
    if ($verifyStmt !== false) {
        $lastRow = sqlsrv_fetch_array($verifyStmt, SQLSRV_FETCH_ASSOC);
        // Convert DateTime object to string if needed
        if ($lastRow && isset($lastRow['snapshot_time']) && $lastRow['snapshot_time'] instanceof DateTime) {
            $lastRow['snapshot_time'] = $lastRow['snapshot_time']->format('Y-m-d H:i:s');
        }
        sqlsrv_free_stmt($verifyStmt);
    }

    sqlsrv_close($conn);

    // Regenerate the analytics cache after successful data load
    $cacheResult = null;
    $cacheGeneratorPath = __DIR__ . '/generate_cache.php';
    if (file_exists($cacheGeneratorPath)) {
        // Include the cache generator (runs synchronously)
        ob_start();
        $_GET['internal'] = '1'; // Mark as internal call
        include $cacheGeneratorPath;
        $cacheOutput = ob_get_clean();
        $cacheResult = json_decode($cacheOutput, true);
        unset($_GET['internal']);
    }

    $response = [
        'success' => true,
        'timestamp' => $snapshotTime,
        'data' => [
            'pilots' => $totalPilots,
            'controllers' => $totalControllers,
            'prefiles' => $totalPrefiles
        ],
        'inserted' => $lastRow,
        'source' => 'vatsim_api_v3',
        'cache_regenerated' => $cacheResult ? ($cacheResult['success'] ?? false) : false
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
