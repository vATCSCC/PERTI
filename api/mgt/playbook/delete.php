<?php
/**
 * Playbook Delete API
 * POST — Archive or hard-delete a play.
 * Logs to playbook_changelog.
 */

include_once(dirname(__DIR__, 3) . '/sessions/handler.php');
include("../../../load/config.php");
include("../../../load/input.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json');

// Auth
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check && $p_check->num_rows > 0) $perm = true;
    }
} else {
    $perm = true;
}

if (!$perm) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$play_id     = (int)($body['play_id'] ?? 0);
$action      = $body['action'] ?? 'archive'; // archive, restore, delete
$airac_cycle = trim($body['airac_cycle'] ?? '');
$changed_by  = session_get('VATSIM_CID', '0');

if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'play_id is required']);
    exit;
}

// Verify play exists
$stmt = $conn_sqli->prepare("SELECT play_id, status, source FROM playbook_plays WHERE play_id = ?");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$play = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$play) {
    http_response_code(404);
    echo json_encode(['error' => 'Play not found']);
    exit;
}

$cl_action = '';

if ($action === 'archive') {
    $stmt = $conn_sqli->prepare("UPDATE playbook_plays SET status='archived', updated_by=? WHERE play_id=?");
    $stmt->bind_param('si', $changed_by, $play_id);
    $stmt->execute();
    $stmt->close();
    $cl_action = 'play_archived';

} elseif ($action === 'restore') {
    $stmt = $conn_sqli->prepare("UPDATE playbook_plays SET status='active', updated_by=? WHERE play_id=?");
    $stmt->bind_param('si', $changed_by, $play_id);
    $stmt->execute();
    $stmt->close();
    $cl_action = 'play_restored';

} elseif ($action === 'delete') {
    // Hard delete (cascades to routes)
    $stmt = $conn_sqli->prepare("DELETE FROM playbook_plays WHERE play_id=?");
    $stmt->bind_param('i', $play_id);
    $stmt->execute();
    $stmt->close();
    $cl_action = 'play_deleted';

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use: archive, restore, delete']);
    exit;
}

// Log to changelog
$cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, airac_cycle, changed_by) VALUES (?, ?, ?, ?)");
$cl_stmt->bind_param('isss', $play_id, $cl_action, $airac_cycle, $changed_by);
$cl_stmt->execute();
$cl_stmt->close();

echo json_encode([
    'success' => true,
    'play_id' => $play_id,
    'action' => $action
]);
