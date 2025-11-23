<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {

        // Getting CID Value
        $cid = strip_tags($_SESSION['VATSIM_CID']);

        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");

        if ($p_check) {
            $perm = true;
        }

    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

$airport = strip_tags($_POST['airport']);
$arr = strip_tags($_POST['arr']);
$dep = strip_tags($_POST['dep']);
$vmc_aar = strip_tags($_POST['vmc_aar']);
$lvmc_aar = strip_tags($_POST['lvmc_aar']);
$imc_aar = strip_tags($_POST['imc_aar']);
$limc_aar = strip_tags($_POST['limc_aar']);
$vmc_adr = strip_tags($_POST['vmc_adr']);
$imc_adr = strip_tags($_POST['imc_adr']);

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query
    $sql = "INSERT INTO config_data (airport, arr, dep, vmc_aar, lvmc_aar, imc_aar, limc_aar, vmc_adr, imc_adr) VALUES ('$airport', '$arr', '$dep', '$vmc_aar', '$lvmc_aar', '$imc_aar', '$limc_aar', '$vmc_adr', '$imc_adr')";

    $conn_pdo->exec($sql);

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>