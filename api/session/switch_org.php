<?php
/**
 * Switch active organization context
 * POST { "org_code": "canoc" }
 *
 * Works for both authenticated and anonymous users.
 * Authenticated users: reloads full org context from user_orgs.
 * Anonymous users: sets ORG_CODE in session if org is valid/active.
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

$input = json_decode(file_get_contents('php://input'), true);
$target_org = $input['org_code'] ?? null;

if (!$target_org) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid org_code']);
    exit;
}

$cid = $_SESSION['VATSIM_CID'] ?? null;

if ($cid) {
    // Authenticated user: validate against their org memberships
    if (!in_array($target_org, get_user_orgs())) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid org_code']);
        exit;
    }

    load_org_context((int)$cid, $conn_sqli, $target_org);

    // Sync PHP session locale to new org's default
    $org_info_loc = get_org_info($conn_sqli);
    $_SESSION['PERTI_LOCALE'] = $org_info_loc['default_locale'] ?? 'en-US';
} else {
    // Anonymous user: validate org exists and is active (never allow global)
    if ($target_org === 'global') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid org_code']);
        exit;
    }
    $stmt = mysqli_prepare($conn_sqli, "SELECT org_code, display_name, default_locale FROM organizations WHERE org_code = ? AND is_active = 1");
    mysqli_stmt_bind_param($stmt, "s", $target_org);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $org_row = mysqli_fetch_assoc($result);

    if (!$org_row) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid org_code']);
        exit;
    }

    $_SESSION['ORG_CODE'] = $target_org;
    $_SESSION['ORG_PRIVILEGED'] = false;
    $_SESSION['ORG_GLOBAL'] = false;
    $_SESSION['ORG_ALL'] = [$target_org];
    $_SESSION['PERTI_LOCALE'] = $org_row['default_locale'] ?? 'en-US';

    // Clear cached org info
    unset($_SESSION['ORG_INFO_' . $target_org]);
}

$org_info = get_org_info($conn_sqli);

echo json_encode([
    'success' => true,
    'org_code' => get_org_code(),
    'privileged' => is_org_privileged(),
    'global' => is_org_global(),
    'display_name' => $org_info['display_name'],
    'default_locale' => $org_info['default_locale']
]);
