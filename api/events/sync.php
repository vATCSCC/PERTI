<?php
/**
 * Division Events Sync API Endpoint
 *
 * POST /api/events/sync - Trigger event sync from division APIs
 * GET  /api/events/sync - Get sync status/last sync time
 *
 * Query params:
 *   source - 'VATUSA', 'VATCAN', 'VATSIM', or 'ALL' (default: ALL)
 *
 * @package PERTI\API\Events
 * @version 1.0.0
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../scripts/sync_division_events.php';

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

        $sql = "
            SELECT
                source,
                COUNT(*) as event_count,
                MAX(synced_at) as last_synced,
                MIN(start_utc) as earliest_event,
                MAX(start_utc) as latest_event
            FROM dbo.division_events
            GROUP BY source
        ";

        $stmt = $conn_adl->query($sql);
        $stats = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['source']] = [
                'count' => (int)$row['event_count'],
                'last_synced' => $row['last_synced'],
                'earliest_event' => $row['earliest_event'],
                'latest_event' => $row['latest_event'],
            ];
        }

        // Total upcoming events
        $upcoming = $conn_adl->query("
            SELECT COUNT(*) as cnt FROM dbo.division_events
            WHERE end_utc IS NULL OR end_utc > SYSUTCDATETIME()
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'total_upcoming' => (int)$upcoming['cnt'],
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

    $results = sync_division_events($source);
    echo json_encode($results);

} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
    ]);
}
