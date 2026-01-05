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

// Simple permission check: require logged-in VATSIM CID or DEV mode
$perm = false;
if (defined('DEV')) {
    $perm = true;
} else {
    if (isset($_SESSION['VATSIM_CID']) && $_SESSION['VATSIM_CID'] !== '') {
        $perm = true;
    }
}

if ($perm === true) {
    if (!isset($_POST['id']) || !isset($_POST['probability'])) {
        http_response_code(400);
        exit;
    }

    $id          = intval($_POST['id']);
    $probability = intval($_POST['probability']);

    if ($probability < 0) {
        $probability = 0;
    } elseif ($probability > 4) {
        $probability = 4;
    }

    $query = $conn_sqli->query("UPDATE p_terminal_init_times SET probability='$probability' WHERE id=$id");

    if ($query) {
        http_response_code(200);
    } else {
        http_response_code(500);
    }
} else {
    http_response_code(403);
}

?>
