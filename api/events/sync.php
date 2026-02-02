<?php
/**
 * PERTI Events Sync API Endpoint
 *
 * POST /api/events/sync - Trigger event sync from division APIs
 * GET  /api/events/sync - Get sync status/last sync time
 *
 * Query params:
 *   source - 'VATUSA', 'VATCAN', 'VATSIM', or 'ALL' (default: ALL)
 *
 * @package PERTI\API\Events
 * @version 2.0.0
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../scripts/sync_perti_events.php';

// Check permissions (require login for POST)
$perm = false;
if (isset($_SESSION['VATSIM_CID'])) {
    $cid = session_get('VATSIM_CID', '');
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    if ($p_check && $p_check->num_rows > 0) {
        $perm = true;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Return last sync info
    try {
        global $conn_adl;
        if (!$conn_adl) {
            throw new Exception('ADL database not available');
        }

        // Stats by source
        $sql = "
            SELECT
                source,
                COUNT(*) as event_count,
                MAX(synced_utc) as last_synced,
                MIN(start_utc) as earliest_event,
                MAX(start_utc) as latest_event,
                SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN logging_enabled = 1 THEN 1 ELSE 0 END) as logging_enabled_count
            FROM dbo.perti_events
            GROUP BY source
        ";

        $stmt = $conn_adl->query($sql);
        $stats = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['source']] = [
                'count' => (int)$row['event_count'],
                'active' => (int)$row['active_count'],
                'logging_enabled' => (int)$row['logging_enabled_count'],
                'last_synced' => $row['last_synced'],
                'earliest_event' => $row['earliest_event'],
                'latest_event' => $row['latest_event'],
            ];
        }

        // Stats by event type
        $typeStats = $conn_adl->query("
            SELECT event_type, COUNT(*) as cnt
            FROM dbo.perti_events
            WHERE end_utc > SYSUTCDATETIME()
            GROUP BY event_type
        ");
        $byType = [];
        while ($row = $typeStats->fetch(PDO::FETCH_ASSOC)) {
            $byType[$row['event_type']] = (int)$row['cnt'];
        }

        // Total upcoming and currently logging
        $counts = $conn_adl->query("
            SELECT
                SUM(CASE WHEN end_utc > SYSUTCDATETIME() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN logging_enabled = 1
                    AND SYSUTCDATETIME() BETWEEN logging_start_utc AND logging_end_utc
                    THEN 1 ELSE 0 END) as currently_logging
            FROM dbo.perti_events
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'by_type' => $byType,
            'total_upcoming' => (int)($counts['upcoming'] ?? 0),
            'currently_logging' => (int)($counts['currently_logging'] ?? 0),
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }

} elseif ($method === 'POST') {
    // Trigger sync (requires permission)
    if (!$perm) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required',
        ]);
        exit;
    }

    $source = $_GET['source'] ?? $_POST['source'] ?? 'ALL';
    $source = strtoupper($source);

    if (!in_array($source, ['ALL', 'VATUSA', 'VATCAN', 'VATSIM'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid source. Must be ALL, VATUSA, VATCAN, or VATSIM',
        ]);
        exit;
    }

    $results = sync_perti_events($source);
    echo json_encode($results);

} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
    ]);
}
