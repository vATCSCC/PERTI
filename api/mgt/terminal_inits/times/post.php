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
    if (!isset($_POST['init_id']) || !isset($_POST['time'])) {
        http_response_code(400);
        exit;
    }

    $init_id = intval($_POST['init_id']);
    $time    = strip_tags($_POST['time']);

    // Optional probability; default to 0 (CDW) if not provided
    $probability = 0;
    if (isset($_POST['probability']) && $_POST['probability'] !== '') {
        $probability = intval($_POST['probability']);
    }

    if ($probability < 0) {
        $probability = 0;
    } elseif ($probability > 4) {
        $probability = 4;
    }

    try {
        $conn_pdo->beginTransaction();

        $sql = "INSERT INTO p_terminal_init_times (time, probability, init_id)
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
