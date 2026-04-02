<?php
/**
 * RAD API Common Utilities
 *
 * Shared auth, DB connections, and helpers for Route Amendment Dialogue endpoints.
 * Follows api/gdt/common.php pattern.
 */

if (!defined('RAD_API_INCLUDED')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Direct access not allowed']);
    exit;
}

// Session BEFORE config/connect (connect.php closing tag sends headers)
require_once(__DIR__ . '/../../sessions/handler.php');

if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');
require_once(__DIR__ . '/../../load/services/RADService.php');

function rad_respond_json($code, $payload) {
    if (!isset($payload['timestamp'])) $payload['timestamp'] = gmdate('c');
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rad_read_payload() {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }
    return array_merge($_GET ?? [], $_POST ?? []);
}

function rad_require_auth() {
    if (!isset($_SESSION['VATSIM_CID']) || empty($_SESSION['VATSIM_CID'])) {
        rad_respond_json(401, ['status' => 'error', 'message' => 'Authentication required']);
    }
    return $_SESSION['VATSIM_CID'];
}

function rad_get_service() {
    global $conn_adl, $conn_tmi;
    if (!$conn_adl) rad_respond_json(500, ['status' => 'error', 'message' => 'ADL connection unavailable']);
    if (!$conn_tmi) rad_respond_json(500, ['status' => 'error', 'message' => 'TMI connection unavailable']);
    $conn_gis = get_conn_gis();
    return new RADService($conn_adl, $conn_tmi, $conn_gis);
}

/**
 * Require TMU-level permission for amendment write operations.
 * Checks admin_users table in MySQL (presence = authorized).
 */
function rad_require_tmu($cid) {
    global $conn_sqli;
    $stmt = $conn_sqli->prepare("SELECT 1 FROM admin_users WHERE cid=? LIMIT 1");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        rad_respond_json(403, ['status' => 'error', 'message' => 'TMU-level permission required for amendments']);
    }
    $stmt->close();
}
