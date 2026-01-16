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

// Basic permission check – mirror other management endpoints:
// allow if DEV is defined or a VATSIM_CID is present in the session.
$perm = false;
if (defined('DEV')) {
    $perm = true;
} else {
    if (isset($_SESSION['VATSIM_CID']) && $_SESSION['VATSIM_CID'] != '') {
        $perm = true;
    }
}

if ($perm !== true) {
    http_response_code(403);
    exit();
}

// Require fields
if (!isset($_POST['id']) || !isset($_POST['probability'])) {
    http_response_code(400);
    exit();
}

$id = post_int('id');
$probability = post_int('probability');

// Normalize: anything >= 4 is treated as Actual (4)
if ($probability < 0) {
    $probability = 0;
}
if ($probability >= 4) {
    $probability = 4;
}

$stmt = $conn_sqli->prepare("UPDATE p_enroute_init_times SET probability=? WHERE id=?");
if ($stmt === false) {
    http_response_code(500);
    exit();
}

$stmt->bind_param("ii", $probability, $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    http_response_code(200);
} else {
    http_response_code(500);
}

?>