<?php
/**
 * Playbook Get API
 * Returns a single play with all its routes.
 *
 * GET ?id=123  - Get play by ID
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/input.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

$play_id = get_int('id');
if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid play id']);
    exit;
}

// Fetch play
$stmt = $conn_sqli->prepare("SELECT * FROM playbook_plays WHERE play_id = ?");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$play = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$play) {
    http_response_code(404);
    echo json_encode(['error' => 'Play not found']);
    exit;
}

$play['play_id'] = (int)$play['play_id'];
$play['route_count'] = (int)$play['route_count'];

// Fetch routes
$stmt = $conn_sqli->prepare("SELECT * FROM playbook_routes WHERE play_id = ? ORDER BY sort_order ASC, route_id ASC");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$result = $stmt->get_result();

$routes = [];
while ($row = $result->fetch_assoc()) {
    $row['route_id'] = (int)$row['route_id'];
    $row['play_id'] = (int)$row['play_id'];
    $row['sort_order'] = (int)$row['sort_order'];
    $routes[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'play' => $play,
    'routes' => $routes
]);
