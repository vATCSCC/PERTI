<?php
/**
 * Schedule SUA Activation
 *
 * POST endpoint to create a new SUA activation.
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include(__DIR__ . "/../../../load/config.php");
include(__DIR__ . "/../../../load/connect.php");

// Check permissions
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = strip_tags($_SESSION['VATSIM_CID']);
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check && $p_check->num_rows > 0) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_CID'] = 0;
}

if (!$perm) {
    http_response_code(403);
    exit();
}

// Check ADL connection
if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection not available']);
    exit;
}

// Get POST data
$sua_id = isset($_POST['sua_id']) ? strip_tags($_POST['sua_id']) : null;
$sua_type = isset($_POST['sua_type']) ? strtoupper(strip_tags($_POST['sua_type'])) : null;
$tfr_subtype = isset($_POST['tfr_subtype']) ? strtoupper(strip_tags($_POST['tfr_subtype'])) : null;
$name = isset($_POST['name']) ? strip_tags($_POST['name']) : null;
$artcc = isset($_POST['artcc']) ? strtoupper(strip_tags($_POST['artcc'])) : null;
$start_utc = isset($_POST['start_utc']) ? strip_tags($_POST['start_utc']) : null;
$end_utc = isset($_POST['end_utc']) ? strip_tags($_POST['end_utc']) : null;
$lower_alt = isset($_POST['lower_alt']) ? strip_tags($_POST['lower_alt']) : null;
$upper_alt = isset($_POST['upper_alt']) ? strip_tags($_POST['upper_alt']) : null;
$remarks = isset($_POST['remarks']) ? strip_tags($_POST['remarks']) : null;
$created_by = $_SESSION['VATSIM_CID'];

// Validation
if (!$sua_type || !$name || !$start_utc || !$end_utc) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// Insert query
$sql = "INSERT INTO sua_activations (sua_id, sua_type, tfr_subtype, name, artcc, start_utc, end_utc, lower_alt, upper_alt, remarks, created_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SCHEDULED')";

$params = [
    $sua_id,
    $sua_type,
    $tfr_subtype,
    $name,
    $artcc,
    $start_utc,
    $end_utc,
    $lower_alt,
    $upper_alt,
    $remarks,
    $created_by
];

$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to create activation']);
    exit;
}

sqlsrv_free_stmt($stmt);
http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'Activation scheduled']);
