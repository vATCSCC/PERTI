<?php
/**
 * Playbook Groups Management API
 * POST — Save per-play groups (replace all groups for that play)
 *
 * JSON body: { play_id: int, groups: [{ group_name, group_color, route_ids: [...], sort_order }] }
 */

define('PERTI_MYSQL_ONLY', true);
include_once(dirname(__DIR__, 3) . '/load/connect.php');

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['play_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$play_id = (int)$body['play_id'];
$groups = isset($body['groups']) && is_array($body['groups']) ? $body['groups'] : [];

if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid play_id']);
    exit;
}

// Verify play exists
$check = $conn_sqli->prepare("SELECT play_id FROM playbook_plays WHERE play_id = ?");
$check->bind_param('i', $play_id);
$check->execute();
if (!$check->get_result()->fetch_assoc()) {
    $check->close();
    http_response_code(404);
    echo json_encode(['error' => 'Play not found']);
    exit;
}
$check->close();

// Delete existing groups for this play
$del = $conn_sqli->prepare("DELETE FROM playbook_route_groups WHERE play_id = ?");
$del->bind_param('i', $play_id);
$del->execute();
$del->close();

// Insert new groups
$changed_by = isset($_SESSION) ? ($_SESSION['VATSIM_CID'] ?? '0') : '0';
if (!empty($groups)) {
    $ins = $conn_sqli->prepare("INSERT INTO playbook_route_groups
        (play_id, group_name, group_color, route_ids, sort_order, created_by)
        VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($groups as $idx => $g) {
        $name = trim($g['group_name'] ?? 'Group');
        $color = trim($g['group_color'] ?? '#e74c3c');
        $route_ids_json = json_encode(isset($g['route_ids']) && is_array($g['route_ids']) ? array_map('intval', $g['route_ids']) : []);
        $sort = isset($g['sort_order']) ? (int)$g['sort_order'] : $idx;

        $ins->bind_param('isssis', $play_id, $name, $color, $route_ids_json, $sort, $changed_by);
        $ins->execute();
    }
    $ins->close();
}

echo json_encode([
    'success' => true,
    'play_id' => $play_id,
    'group_count' => count($groups)
]);
