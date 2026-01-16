<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

header('Content-Type: application/json');

$id = isset($_GET['id']) ? get_int('id') : 0;

$result = [
    'id' => 0
];

if ($id > 0) {
    $q = $conn_sqli->query("SELECT * FROM tmi_ground_stops WHERE id='$id' LIMIT 1");
    if ($q && mysqli_num_rows($q) === 1) {
        $data = mysqli_fetch_assoc($q);

        $status_labels = [
            0 => 'Draft',
            1 => 'Proposed',
            2 => 'Actual',
            3 => 'Cancelled'
        ];

        $status = isset($data['status']) ? (int)$data['status'] : 0;

        $result = [
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

echo json_encode($result);

?>
