<?php
/**
 * Create Custom TFR
 *
 * POST endpoint to create a custom TFR with geometry.
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
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
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
$tfr_subtype = isset($_POST['tfr_subtype']) ? strtoupper(post_input('tfr_subtype')) : 'OTHER';
$name = isset($_POST['name']) ? post_input('name') : null;
$artcc = isset($_POST['artcc']) ? strtoupper(post_input('artcc')) : null;
$start_utc = isset($_POST['start_utc']) ? post_input('start_utc') : null;
$end_utc = isset($_POST['end_utc']) ? post_input('end_utc') : null;
$lower_alt = isset($_POST['lower_alt']) ? post_input('lower_alt') : 'GND';
$upper_alt = isset($_POST['upper_alt']) ? post_input('upper_alt') : 'UNLTD';
$remarks = isset($_POST['remarks']) ? post_input('remarks') : null;
$notam_number = isset($_POST['notam_number']) ? post_input('notam_number') : null;
$geometry = isset($_POST['geometry']) ? $_POST['geometry'] : null;
$created_by = $_SESSION['VATSIM_CID'];

// Validation
if (!$name || !$start_utc || !$end_utc) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields (name, start_utc, end_utc)']);
    exit;
}

// Convert datetime-local format (YYYY-MM-DDTHH:MM) to SQL Server format (YYYY-MM-DD HH:MM:SS)
$start_utc = str_replace('T', ' ', $start_utc) . ':00';
$end_utc = str_replace('T', ' ', $end_utc) . ':00';

// Validate geometry if provided (should be valid JSON)
if ($geometry) {
    $decoded = json_decode($geometry);
    if ($decoded === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid geometry JSON']);
        exit;
    }
}

// Insert query
$sql = "INSERT INTO sua_activations (sua_id, sua_type, tfr_subtype, name, artcc, start_utc, end_utc, lower_alt, upper_alt, remarks, notam_number, geometry, created_by, status)
        VALUES (NULL, 'TFR', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SCHEDULED')";

$params = [
    $tfr_subtype,
    $name,
    $artcc,
    $start_utc,
    $end_utc,
    $lower_alt,
    $upper_alt,
    $remarks,
    $notam_number,
    $geometry,
    $created_by
];

$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    $errorMsg = $errors ? $errors[0]['message'] : 'Unknown database error';
    error_log("TFR creation failed: " . $errorMsg);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to create TFR: ' . $errorMsg]);
    exit;
}

sqlsrv_free_stmt($stmt);
http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'TFR created']);
