<?php
/**
 * Switch active organization context
 * POST { "org_code": "vatcan" }
 */
include_once(dirname(__DIR__, 2) . '/sessions/handler.php');
include_once(dirname(__DIR__, 2) . '/load/config.php');
define('PERTI_MYSQL_ONLY', true);
include_once(dirname(__DIR__, 2) . '/load/connect.php');
require_once(dirname(__DIR__, 2) . '/load/org_context.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$cid = $_SESSION['VATSIM_CID'] ?? null;
if (!$cid) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$target_org = $input['org_code'] ?? null;

if (!$target_org || !in_array($target_org, get_user_orgs())) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid org_code']);
    exit;
}

// Reload org context with target org
load_org_context((int)$cid, $conn_sqli, $target_org);

$org_info = get_org_info($conn_sqli);

echo json_encode([
    'success' => true,
    'org_code' => get_org_code(),
    'privileged' => is_org_privileged(),
    'display_name' => $org_info['display_name'],
    'default_locale' => $org_info['default_locale']
]);
