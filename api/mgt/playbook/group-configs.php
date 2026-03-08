<?php
/**
 * Playbook Group Configs Management API
 * POST   — Create or update a global config with rules
 * DELETE — Delete a config (via ?id=X or JSON body)
 *
 * POST body: {
 *   config_id: int (0 = create, >0 = update),
 *   config_name: string,
 *   description: string,
 *   rules: [{ group_name, group_color, sort_order, match_field, match_value }]
 * }
 */

define('PERTI_MYSQL_ONLY', true);
include_once(dirname(__DIR__, 3) . '/load/connect.php');

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    $config_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($config_id <= 0) {
        $body = json_decode(file_get_contents('php://input'), true);
        $config_id = isset($body['id']) ? (int)$body['id'] : 0;
    }

    if ($config_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing config id']);
        exit;
    }

    // Rules cascade-delete via FK
    $stmt = $conn_sqli->prepare("DELETE FROM playbook_group_configs WHERE config_id = ?");
    $stmt->bind_param('i', $config_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $config_id = isset($body['config_id']) ? (int)$body['config_id'] : 0;
    $config_name = trim($body['config_name'] ?? '');
    $description = trim($body['description'] ?? '');
    $rules = isset($body['rules']) && is_array($body['rules']) ? $body['rules'] : [];
    $changed_by = isset($_SESSION) ? ($_SESSION['VATSIM_CID'] ?? '0') : '0';

    if ($config_name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'config_name is required']);
        exit;
    }

    $valid_match_fields = [
        'origin_tracons', 'origin_artccs', 'origin_firs',
        'dest_tracons', 'dest_artccs', 'dest_firs',
        'origin_airports', 'dest_airports', 'route_contains'
    ];

    if ($config_id > 0) {
        // Update existing config
        $stmt = $conn_sqli->prepare("UPDATE playbook_group_configs SET config_name = ?, description = ? WHERE config_id = ?");
        $stmt->bind_param('ssi', $config_name, $description, $config_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0 && $conn_sqli->errno) {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['error' => 'Config not found']);
            exit;
        }
        $stmt->close();

        // Delete existing rules
        $del = $conn_sqli->prepare("DELETE FROM playbook_group_config_rules WHERE config_id = ?");
        $del->bind_param('i', $config_id);
        $del->execute();
        $del->close();
    } else {
        // Create new config
        $stmt = $conn_sqli->prepare("INSERT INTO playbook_group_configs (config_name, description, created_by) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $config_name, $description, $changed_by);
        $stmt->execute();
        $config_id = (int)$conn_sqli->insert_id;
        $stmt->close();
    }

    // Insert rules
    if (!empty($rules)) {
        $ins = $conn_sqli->prepare("INSERT INTO playbook_group_config_rules
            (config_id, group_name, group_color, sort_order, match_field, match_value)
            VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($rules as $idx => $r) {
            $g_name = trim($r['group_name'] ?? 'Group');
            $g_color = trim($r['group_color'] ?? '#e74c3c');
            $g_sort = isset($r['sort_order']) ? (int)$r['sort_order'] : $idx;
            $m_field = $r['match_field'] ?? '';
            $m_value = trim($r['match_value'] ?? '');

            if (!in_array($m_field, $valid_match_fields) || $m_value === '') {
                continue;
            }

            $ins->bind_param('ississ', $config_id, $g_name, $g_color, $g_sort, $m_field, $m_value);
            $ins->execute();
        }
        $ins->close();
    }

    echo json_encode([
        'success' => true,
        'config_id' => $config_id
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
