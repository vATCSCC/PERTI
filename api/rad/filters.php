<?php
/** RAD API: Filter Preset CRUD — GET/POST/DELETE /api/rad/filters.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
global $conn_pdo;
$method = $_SERVER['REQUEST_METHOD'];

// Ensure rad_filter_presets table exists (auto-create if needed)
try {
    $conn_pdo->exec("
        CREATE TABLE IF NOT EXISTS rad_filter_presets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_cid INT NULL,
            name VARCHAR(100) NOT NULL,
            filters_json TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) {
    // Table may already exist; continue
}

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        // Load specific preset
        $stmt = $conn_pdo->prepare("SELECT * FROM rad_filter_presets WHERE id = ? AND (user_cid IS NULL OR user_cid = ?)");
        $stmt->execute([(int)$id, (int)$cid]);
        $preset = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($preset && !empty($preset['filters_json'])) {
            $preset['filters'] = json_decode($preset['filters_json'], true);
        }
        rad_respond_json(200, ['status' => 'ok', 'data' => $preset ?: null]);
    } else {
        // List all presets
        $stmt = $conn_pdo->prepare(
            "SELECT id, name, user_cid, created_at FROM rad_filter_presets WHERE user_cid IS NULL OR user_cid = ? ORDER BY name");
        $stmt->execute([(int)$cid]);
        $presets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        rad_respond_json(200, ['status' => 'ok', 'data' => $presets]);
    }

} elseif ($method === 'POST') {
    $body = rad_read_payload();
    $name = $body['name'] ?? null;
    // Accept both 'filters' (JS) and 'filters_json' (direct)
    $filters = $body['filters'] ?? $body['filters_json'] ?? null;
    $is_global = !empty($body['global']);
    if (!$name || !$filters) {
        rad_respond_json(400, ['status' => 'error', 'message' => 'name and filters required']);
    }
    $filters_json = is_string($filters) ? $filters : json_encode($filters);
    $stmt = $conn_pdo->prepare(
        "INSERT INTO rad_filter_presets (user_cid, name, filters_json) VALUES (?, ?, ?)");
    $stmt->execute([$is_global ? null : (int)$cid, $name, $filters_json]);
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
