<?php

// api/mgt/config_data/bulk.php
// Handles bulk actions on airport configurations (activate, deactivate, delete)

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json');

// Check Perms
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
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
// (E)

// Get request data
$action = isset($_REQUEST['action']) ? strip_tags($_REQUEST['action']) : '';
$idsRaw = isset($_REQUEST['ids']) ? $_REQUEST['ids'] : '';

// Parse IDs - can be comma-separated string or array
if (is_array($idsRaw)) {
    $ids = array_map('intval', $idsRaw);
} else {
    $ids = array_map('intval', array_filter(explode(',', $idsRaw)));
}

// Validate
if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Action is required']);
    exit();
}

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'No config IDs provided']);
    exit();
}

if (!in_array($action, ['activate', 'deactivate', 'delete'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use: activate, deactivate, or delete']);
    exit();
}

// Check if ADL connection is available
if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'ADL database connection not available']);
    exit();
}

// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$affectedRows = 0;
$errors = [];

switch ($action) {
    case 'activate':
        $sql = "UPDATE dbo.airport_config SET is_active = 1, updated_utc = GETUTCDATE() WHERE config_id IN ($placeholders)";
        $stmt = sqlsrv_query($conn_adl, $sql, $ids);
        if ($stmt === false) {
            $errors[] = adl_sql_error_message();
        } else {
            $affectedRows = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'deactivate':
        $sql = "UPDATE dbo.airport_config SET is_active = 0, updated_utc = GETUTCDATE() WHERE config_id IN ($placeholders)";
        $stmt = sqlsrv_query($conn_adl, $sql, $ids);
        if ($stmt === false) {
            $errors[] = adl_sql_error_message();
        } else {
            $affectedRows = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'delete':
        // CASCADE on FK will automatically delete child rows (runways, rates)
        $sql = "DELETE FROM dbo.airport_config WHERE config_id IN ($placeholders)";
        $stmt = sqlsrv_query($conn_adl, $sql, $ids);
        if ($stmt === false) {
            $errors[] = adl_sql_error_message();
        } else {
            $affectedRows = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
        }
        break;
}

if (!empty($errors)) {
    http_response_code(500);
    error_log("ADL bulk $action failed: " . implode('; ', $errors));
    echo json_encode(['error' => 'Database error', 'details' => $errors]);
} else {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'action' => $action,
        'affected' => $affectedRows,
        'requested' => count($ids)
    ]);
}

?>
