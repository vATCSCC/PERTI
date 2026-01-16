<?php

include_once('../load/config.php');
include_once('../load/connect.php');

if (!isset($_POST['selfcookie']) || !isset($_POST['ip'])) {
    exit;
}

// Setting Values
$selfcookie = post_input('selfcookie');
$ip = post_input('ip');

$result_check_valid = ("SELECT COUNT(*) as 'total', cid, first_name, last_name FROM users WHERE last_session_ip='$ip' AND last_selfcookie='$selfcookie'");
$count_check_valid = mysqli_query($conn_sqli, $result_check_valid);

while ($data_check_valid = mysqli_fetch_array($count_check_valid)) {
    if ($data_check_valid['total'] > 0) {
        // Setting Values
        $cid = $data_check_valid['cid'];
        $first_name = $data_check_valid['first_name'];
        $last_name = $data_check_valid['last_name'];
        
        // Building Array
        $export = array(
            'cid' => $cid,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'status' => 'success'
        );

        echo json_encode($export);

    } else {
        // Building Array
        $export = array(
            'status' => 'error'
        );

        echo json_encode($export);

    }
}

?>