<?php
/**
 * Playbook Organizations List API
 * GET — List all active organizations for ACL sharing UI.
 */

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
perti_set_cors();

// Auth required
if (!isset($_SESSION['VATSIM_CID'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$result = $conn_sqli->query("SELECT org_code, org_name, display_name, region FROM organizations WHERE is_active = 1 ORDER BY display_name");

$orgs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $orgs[] = $row;
}

echo json_encode(['success' => true, 'orgs' => $orgs]);
