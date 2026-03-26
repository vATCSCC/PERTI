<?php
/**
 * CTP Pull-Based Playbook Sync
 *
 * HTTP-triggered script that polls GET /api/Routes on the CTP API,
 * detects changes via content hashing, and syncs routes into 4 playbooks.
 *
 * Usage:
 *   ?action=status              Show current sync state
 *   ?action=sync&secret=XXX     Trigger a sync cycle
 *   ?action=sync&secret=XXX&force=1  Force sync even if hash unchanged
 *
 * @version 1.0.0
 */

// ── Bootstrap ─────────────────────────────────────────────────────────
// Determine web root: support both direct execution and VFS upload
$webRoot = realpath(__DIR__ . '/../../');
if (!file_exists($webRoot . '/load/config.php')) {
    // Fallback for VFS upload to wwwroot
    $webRoot = '/home/site/wwwroot';
}

require_once $webRoot . '/load/config.php';
require_once $webRoot . '/load/connect.php';
require_once $webRoot . '/load/services/CTPPlaybookSync.php';
require_once $webRoot . '/load/services/CTPApiClient.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$secret = $_GET['secret'] ?? ($_SERVER['HTTP_X_PULL_SECRET'] ?? '');
$force  = (bool)($_GET['force'] ?? false);

// ── Auth ──────────────────────────────────────────────────────────────
if ($action !== 'status' && CTP_PULL_SECRET !== '' && $secret !== CTP_PULL_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing secret']);
    exit;
}

// ── Config guard ─────────────────────────────────────────────────────
if (!CTP_PULL_ENABLED) {
    echo json_encode(['error' => 'CTP pull sync is disabled (CTP_PULL_ENABLED=0)']);
    exit;
}
if (CTP_API_URL === '' || CTP_API_KEY === '') {
    echo json_encode(['error' => 'CTP_API_URL and CTP_API_KEY must be configured']);
    exit;
}
if (CTP_EVENT_CODE === '') {
    echo json_encode(['error' => 'CTP_EVENT_CODE must be configured']);
    exit;
}
if (CTP_SESSION_ID <= 0) {
    echo json_encode(['error' => 'CTP_SESSION_ID must be a positive integer']);
    exit;
}

global $conn_sqli;
$session_id = CTP_SESSION_ID;
$event_code = CTP_EVENT_CODE;

// ── Ensure state row exists ──────────────────────────────────────────
$ins = $conn_sqli->prepare("INSERT IGNORE INTO ctp_pull_sync_state (session_id, event_code) VALUES (?, ?)");
$ins->bind_param('is', $session_id, $event_code);
$ins->execute();
$ins->close();

// ── Load state ───────────────────────────────────────────────────────
function loadState(\mysqli $conn, int $session_id): array {
    $st = $conn->prepare("SELECT * FROM ctp_pull_sync_state WHERE session_id = ?");
    $st->bind_param('i', $session_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: [];
}

$state = loadState($conn_sqli, $session_id);

// ── STATUS action ────────────────────────────────────────────────────
if ($action === 'status') {
    echo json_encode([
        'session_id'         => $session_id,
        'event_code'         => $event_code,
        'status'             => $state['status'] ?? 'idle',
        'content_hash'       => $state['content_hash'] ?? null,
        'synthetic_revision' => (int)($state['synthetic_rev'] ?? 0),
        'route_count'        => (int)($state['route_count'] ?? 0),
        'last_sync_at'       => $state['last_sync_at'] ?? null,
        'last_check_at'      => $state['last_check_at'] ?? null,
        'last_error'         => $state['last_error'] ?? null,
        'config' => [
            'api_url'       => CTP_API_URL,
            'event_code'    => CTP_EVENT_CODE,
            'group_mapping' => CTP_GROUP_MAPPING,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── SYNC action ──────────────────────────────────────────────────────
if ($action !== 'sync') {
    echo json_encode(['error' => 'Unknown action. Use ?action=status or ?action=sync']);
    exit;
}

$start_ms = microtime(true);

// Lock check: prevent concurrent runs
if (($state['status'] ?? '') === 'syncing') {
    $lastCheck = strtotime($state['last_check_at'] ?? '2000-01-01');
    if (time() - $lastCheck < 300) {
        echo json_encode(['error' => 'Sync already in progress', 'last_check_at' => $state['last_check_at']]);
        exit;
    }
    // Stale lock (> 5 min) — reset and proceed
}

// Set status to syncing
$now = gmdate('Y-m-d H:i:s');
$upd = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'syncing', last_check_at = ?, last_error = NULL WHERE session_id = ?");
$upd->bind_param('si', $now, $session_id);
$upd->execute();
$upd->close();

try {
    // 1. Fetch routes from CTP API
    $client = new CTPApiClient(CTP_API_URL, CTP_API_KEY);
    $ctpRoutes = $client->fetchRoutes();

    // 2. Compute content hash
    $newHash = CTPApiClient::computeContentHash($ctpRoutes);
    $oldHash = $state['content_hash'] ?? null;

    // 3. Check if content changed
    if (!$force && $newHash === $oldHash) {
        // No changes — update last_check_at and return
        $upd2 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'idle', last_check_at = ? WHERE session_id = ?");
        $upd2->bind_param('si', $now, $session_id);
        $upd2->execute();
        $upd2->close();

        $elapsed = round((microtime(true) - $start_ms) * 1000);
        echo json_encode([
            'action'     => 'sync',
            'changed'    => false,
            'hash'       => $newHash,
            'route_count'=> count($ctpRoutes),
            'elapsed_ms' => $elapsed,
        ]);
        exit;
    }

    // 4. Transform routes
    $routes = CTPApiClient::transformRoutes($ctpRoutes);

    // 5. Increment synthetic revision
    $newRev = ((int)($state['synthetic_rev'] ?? 0)) + 1;

    // 6. Run sync
    $result = CTPPlaybookSync::run(
        $conn_sqli,
        $routes,
        $event_code,
        $session_id,
        $newRev,
        CTP_GROUP_MAPPING,
        null,   // changed_by_cid: system
        true    // skip_revision_check: pull uses content hash for idempotency
    );

    // 7. Update state
    $routeCount = count($ctpRoutes);
    $upd3 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET
        status = 'idle', content_hash = ?, synthetic_rev = ?,
        route_count = ?, last_sync_at = ?, last_check_at = ?, last_error = NULL
        WHERE session_id = ?");
    $upd3->bind_param('siissi', $newHash, $newRev, $routeCount, $now, $now, $session_id);
    $upd3->execute();
    $upd3->close();

    $elapsed = round((microtime(true) - $start_ms) * 1000);
    echo json_encode([
        'action'      => 'sync',
        'changed'     => true,
        'revision'    => $newRev,
        'hash'        => $newHash,
        'route_count' => $routeCount,
        'plays'       => $result['plays'],
        'warnings'    => $result['warnings'] ?? [],
        'elapsed_ms'  => $elapsed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (CTPApiException $e) {
    // CTP API error — record and return
    $errMsg = $e->getMessage();
    $upd4 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'error', last_error = ?, last_check_at = ? WHERE session_id = ?");
    $upd4->bind_param('ssi', $errMsg, $now, $session_id);
    $upd4->execute();
    $upd4->close();

    http_response_code(502);
    echo json_encode(['error' => 'CTP API error', 'detail' => $errMsg]);

} catch (\Throwable $e) {
    // Internal error
    $errMsg = $e->getMessage();
    $upd5 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'error', last_error = ?, last_check_at = ? WHERE session_id = ?");
    $upd5->bind_param('ssi', $errMsg, $now, $session_id);
    $upd5->execute();
    $upd5->close();

    http_response_code(500);
    echo json_encode(['error' => 'Internal error', 'detail' => $errMsg]);
}
