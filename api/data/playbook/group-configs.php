<?php
/**
 * Playbook Group Configs Data API
 * GET          — List all global configs
 * GET ?id=X    — Get a single config with its rules
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

$config_id = get_int('id');

if ($config_id > 0) {
    // Get single config with rules
    $stmt = $conn_sqli->prepare("SELECT * FROM playbook_group_configs WHERE config_id = ?");
    $stmt->bind_param('i', $config_id);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$config) {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found']);
        exit;
    }

    $config['config_id'] = (int)$config['config_id'];

    // Fetch rules
    $stmt = $conn_sqli->prepare("SELECT * FROM playbook_group_config_rules WHERE config_id = ? ORDER BY sort_order ASC, rule_id ASC");
    $stmt->bind_param('i', $config_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rules = [];
    while ($row = $result->fetch_assoc()) {
        $row['rule_id'] = (int)$row['rule_id'];
        $row['config_id'] = (int)$row['config_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $rules[] = $row;
    }
    $stmt->close();

    $config['rules'] = $rules;

    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
} else {
    // List all configs
    $result = $conn_sqli->query("SELECT config_id, config_name, description, created_by, created_at, updated_at FROM playbook_group_configs ORDER BY config_name ASC");

    $configs = [];
    while ($row = $result->fetch_assoc()) {
        $row['config_id'] = (int)$row['config_id'];
        $configs[] = $row;
    }

    echo json_encode([
        'success' => true,
        'configs' => $configs
    ]);
}
