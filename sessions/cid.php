<?php
/**
 * @deprecated This file is no longer used as of the session handler simplification.
 * The cURL-based session validation has been removed in favor of native PHP sessions.
 * This file can be safely deleted after confirming no external systems depend on it.
 *
 * Previously: Called by sessions/handler.php to validate sessions via CID + IP
 */

include_once('../load/config.php');
include_once('../load/connect.php');

if (!isset($_POST['cid']) || !isset($_POST['ip'])) {
    exit;
}

// Setting Values
$cid = post_input('cid');
$ip = post_input('ip');

// Use prepared statement to prevent SQL injection
$stmt = mysqli_prepare($conn_sqli, "SELECT COUNT(*) as 'total', cid, first_name, last_name, last_selfcookie FROM users WHERE last_session_ip=? AND cid=?");
mysqli_stmt_bind_param($stmt, "ss", $ip, $cid);
mysqli_stmt_execute($stmt);
$count_check_valid = mysqli_stmt_get_result($stmt);

while ($data_check_valid = mysqli_fetch_array($count_check_valid)) {
    if ($data_check_valid['total'] > 0) {
        // Setting Values
        $first_name = $data_check_valid['first_name'];
        $last_name = $data_check_valid['last_name'];


        // Building Array
        $export = array(
            'cid' => $cid,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'selfcookie' => strip_tags($data_check_valid['last_selfcookie']),
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