<?php
/** RAD API: Filter Preset CRUD — GET/POST/DELETE /api/rad/filters.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
global $conn_pdo;
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn_pdo->prepare(
        "SELECT * FROM rad_filter_presets WHERE user_cid IS NULL OR user_cid = ? ORDER BY name");
    $stmt->execute([(int)$cid]);
    $presets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    rad_respond_json(200, ['status' => 'ok', 'data' => $presets]);

} elseif ($method === 'POST') {
    $body = rad_read_payload();
    $name = $body['name'] ?? null;
    $filters_json = $body['filters_json'] ?? null;
    $is_global = !empty($body['global']);
    if (!$name || !$filters_json) {
        rad_respond_json(400, ['status' => 'error', 'message' => 'name and filters_json required']);
    }
    $stmt = $conn_pdo->prepare(
        "INSERT INTO rad_filter_presets (user_cid, name, filters_json) VALUES (?, ?, ?)");
    $stmt->execute([$is_global ? null : (int)$cid, $name, json_encode($filters_json)]);
    rad_respond_json(201, ['status' => 'ok', 'id' => $conn_pdo->lastInsertId()]);

} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $stmt = $conn_pdo->prepare("DELETE FROM rad_filter_presets WHERE id = ? AND (user_cid = ? OR user_cid IS NULL)");
    $stmt->execute([(int)$id, (int)$cid]);
    rad_respond_json(200, ['status' => 'ok']);

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
