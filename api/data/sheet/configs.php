<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

$p_id = get_input('p_id');

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

header('Content-Type: application/json');

$rows = [];
$query = $conn_sqli->query("SELECT * FROM p_configs WHERE p_id='$p_id'");

if ($query) {
    while ($data = mysqli_fetch_assoc($query)) {
        $rows[] = [
            'id' => (int)$data['id'],
            'airport' => $data['airport'],
            'weather' => (int)$data['weather'],
            'arrive' => $data['arrive'],
            'depart' => $data['depart'],
            'aar' => $data['aar'],
            'adr' => $data['adr'],
            'comments' => $data['comments'],
            'has_autofill' => false,
            'autofill_aar' => 0,
            'autofill_adr' => 0,
        ];
    }
}

echo json_encode(['perm' => true, 'rows' => $rows]);
