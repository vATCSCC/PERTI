<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

// Simple permission check (read-only endpoint, so we do not block non-logged-in users)
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
}

header('Content-Type: application/json');

// Collect rows
$rows = [];

$status_labels = [
    0 => 'Draft',
    1 => 'Proposed',
    2 => 'Actual',
    3 => 'Cancelled'
];

$q = $conn_sqli->query("SELECT * FROM tmi_ground_stops ORDER BY updated_at DESC, id DESC");

if ($q) {
    while ($data = mysqli_fetch_assoc($q)) {
        $status = isset($data['status']) ? (int)$data['status'] : 0;
        $rows[] = [
            'id' => (int)$data['id'],
            'name' => $data['name'],
            'ctl_element' => $data['ctl_element'],
            'element_type' => $data['element_type'],
            'airports' => $data['airports'],
            'start_utc' => $data['start_utc'],
            'end_utc' => $data['end_utc'],
            'prob_ext' => $data['prob_ext'],
            'origin_centers' => $data['origin_centers'],
            'origin_airports' => $data['origin_airports'],
            'flt_incl_carrier' => $data['flt_incl_carrier'],
            'flt_incl_type' => $data['flt_incl_type'],
            'dep_facilities' => $data['dep_facilities'],
            'comments' => $data['comments'],
            'adv_number' => $data['adv_number'],
            'advisory_text' => $data['advisory_text'],
            'status' => $status,
            'status_label' => isset($status_labels[$status]) ? $status_labels[$status] : 'Unknown'
        ];
    }
}

echo json_encode($rows);

?>
