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
 *   */5 * * * * curl -s https://perti.vatcscc.org/api/stats/load_network_stats.php > /dev/null
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../load/config.php';

// Prevent duplicate runs within 4 minutes
$lockFile = sys_get_temp_dir() . '/vatsim_stats_loader.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 240) {
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

    // Connect to VATSIM_STATS database
    $dsn = STATS_SQL_DSN;
    $conn = new PDO($dsn, STATS_SQL_USERNAME, STATS_SQL_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30
    ]);

    // Call the stored procedure to tag and insert the snapshot
    $sql = "EXEC sp_TagNetworkSnapshot @snapshot_time = ?, @total_pilots = ?, @total_controllers = ?";
    $snapshotTime = gmdate('Y-m-d H:i:s');

    $stmt = $conn->prepare($sql);
    $stmt->execute([$snapshotTime, $totalPilots, $totalControllers]);

    // Get the inserted row to verify
    $verifySql = "SELECT TOP 1 snapshot_time, total_pilots, total_controllers, traffic_level
                  FROM fact_network_5min
                  ORDER BY snapshot_time DESC";
    $verifyStmt = $conn->query($verifySql);
    $lastRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'timestamp' => $snapshotTime,
        'data' => [
            'pilots' => $totalPilots,
            'controllers' => $totalControllers,
            'prefiles' => $totalPrefiles
        ],
        'inserted' => $lastRow,
        'source' => 'vatsim_api_v3'
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
