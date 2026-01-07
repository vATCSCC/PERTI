<?php

// api/mgt/config_data/delete.php
// Deletes an airport configuration from ADL SQL Server
// Note: CASCADE on foreign keys automatically deletes child rows (runways, rates)

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

// Accept both 'id' and 'config_id' for backwards compatibility
$id = isset($_REQUEST['config_id']) ? intval($_REQUEST['config_id']) : (isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0);

// Validate
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Config ID is required']);
    exit();
}

// Check if ADL connection is available
if (!$conn_adl) {
    // Fallback to MySQL (legacy)
    $query = $conn_sqli->query("DELETE FROM config_data WHERE id=$id");

    if ($query) {
        http_response_code(200);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit();
}

// Use ADL SQL Server
// CASCADE on FK will automatically delete child rows
$sql = "DELETE FROM dbo.airport_config WHERE config_id = ?";
$stmt = sqlsrv_query($conn_adl, $sql, [$id]);

if ($stmt === false) {
    http_response_code(500);
    error_log("ADL config delete failed: " . adl_sql_error_message());
    echo json_encode(['error' => 'Database error']);
} else {
    $rowsAffected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($rowsAffected > 0) {
        http_response_code(200);
        echo json_encode(['success' => true, 'deleted' => $rowsAffected]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found']);
    }
}

?>
