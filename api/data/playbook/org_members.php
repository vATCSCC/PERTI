<?php
/**
 * Playbook Organization Members API
 * GET ?orgs=vatcscc,canoc  — List all members of specified organization(s)
 *
 * Returns members with name, CID, and org affiliations.
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

// Auth required
if (!isset($_SESSION['VATSIM_CID'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$orgs_raw = trim(get_input('orgs'));
if (!$orgs_raw) {
    echo json_encode(['success' => true, 'members' => [], 'orgs' => []]);
    exit;
}

// Parse comma-separated org codes
$org_codes = array_filter(array_map('trim', explode(',', $orgs_raw)));
if (empty($org_codes)) {
    echo json_encode(['success' => true, 'members' => [], 'orgs' => []]);
    exit;
}

// Validate org codes exist
$placeholders = implode(',', array_fill(0, count($org_codes), '?'));
$types = str_repeat('s', count($org_codes));

$org_stmt = $conn_sqli->prepare("SELECT org_code, display_name FROM organizations WHERE org_code IN ($placeholders) AND is_active = 1");
$org_stmt->bind_param($types, ...$org_codes);
$org_stmt->execute();
$org_result = $org_stmt->get_result();
$valid_orgs = [];
while ($row = $org_result->fetch_assoc()) {
    $valid_orgs[] = $row;
}
$org_stmt->close();

if (empty($valid_orgs)) {
    echo json_encode(['success' => true, 'members' => [], 'orgs' => []]);
    exit;
}

$valid_codes = array_column($valid_orgs, 'org_code');
$placeholders = implode(',', array_fill(0, count($valid_codes), '?'));
$types = str_repeat('s', count($valid_codes));

// Get all members of these orgs
$sql = "SELECT DISTINCT u.cid, u.first_name, u.last_name
        FROM users u
        INNER JOIN user_orgs uo ON u.cid = uo.cid
        WHERE uo.org_code IN ($placeholders)
        ORDER BY u.last_name, u.first_name";

$stmt = $conn_sqli->prepare($sql);
$stmt->bind_param($types, ...$valid_codes);
$stmt->execute();
$result = $stmt->get_result();

$members = [];
$cids = [];
while ($row = $result->fetch_assoc()) {
    $members[] = [
        'cid'        => (int)$row['cid'],
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name'],
        'name'       => $row['first_name'] . ' ' . $row['last_name']
    ];
    $cids[] = (int)$row['cid'];
}
$stmt->close();

// Enrich with all org memberships for each member
if ($cids) {
    $cid_placeholders = implode(',', array_fill(0, count($cids), '?'));
    $cid_types = str_repeat('i', count($cids));

    $mo_stmt = $conn_sqli->prepare("SELECT uo.cid, uo.org_code, o.display_name
                                    FROM user_orgs uo
                                    JOIN organizations o ON o.org_code = uo.org_code AND o.is_active = 1
                                    WHERE uo.cid IN ($cid_placeholders)");
    $mo_stmt->bind_param($cid_types, ...$cids);
    $mo_stmt->execute();
    $mo_result = $mo_stmt->get_result();

    $org_map = [];
    while ($orow = $mo_result->fetch_assoc()) {
        $org_map[(int)$orow['cid']][] = [
            'org_code'     => $orow['org_code'],
            'display_name' => $orow['display_name']
        ];
    }
    $mo_stmt->close();

    foreach ($members as &$m) {
        $m['orgs'] = $org_map[$m['cid']] ?? [];
    }
    unset($m);
}

echo json_encode([
    'success' => true,
    'orgs' => $valid_orgs,
    'members' => $members,
    'member_count' => count($members)
]);
