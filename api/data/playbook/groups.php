<?php
/**
 * Playbook Groups Data API
 * GET ?play_id=X — Load per-play route groups
 */

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/input.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
perti_set_cors();

$play_id = get_int('play_id');
if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid play_id']);
    exit;
}

$stmt = $conn_sqli->prepare("SELECT * FROM playbook_route_groups WHERE play_id = ? ORDER BY sort_order ASC, group_id ASC");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$result = $stmt->get_result();

$groups = [];
while ($row = $result->fetch_assoc()) {
    $row['group_id'] = (int)$row['group_id'];
    $row['play_id'] = (int)$row['play_id'];
    $row['sort_order'] = (int)$row['sort_order'];
    $row['route_ids'] = json_decode($row['route_ids'], true);
    $groups[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'groups' => $groups
]);
