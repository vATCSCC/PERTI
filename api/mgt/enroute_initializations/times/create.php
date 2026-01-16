<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../../load/config.php");
include("../../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $vt = session_get('VATSIM_CID', '');

        $u_e = $conn_sqli->query("SELECT COUNT(*) AS total, role FROM p_users WHERE cid='$vt'")->fetch_assoc();
        if ($u_e['total'] > 0) {
            if ($u_e['role'] == 7 || $u_e['role'] == 8 || $u_e['role'] == 9) {
                $perm = true;
            }
        }
    }
} else {
    $perm = true;
}

if ($perm === true) {
    if (!isset($_POST['init_id']) || !isset($_POST['time']) || !isset($_POST['probability'])) {
        http_response_code(400);
        exit;
    }

    $init_id     = post_int('init_id');
    $time        = post_input('time');
    $probability = post_int('probability');

    if ($probability < 0) {
        $probability = 0;
    } elseif ($probability > 4) {
        $probability = 4;
    }

    try {
        $conn_pdo->beginTransaction();

        $sql = "INSERT INTO p_enroute_init_times (time, probability, init_id)
                VALUES (:time, :probability, :init_id)";
        $stmt = $conn_pdo->prepare($sql);
        $stmt->execute([
            ':time'        => $time,
            ':probability' => $probability,
            ':init_id'     => $init_id
        ]);

        $conn_pdo->commit();
        http_response_code(200);
    } catch (PDOException $e) {
        $conn_pdo->rollback();
        http_response_code(500);
    }
} else {
    http_response_code(403);
}

?>
