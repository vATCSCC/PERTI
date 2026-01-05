<?php
/**
 * Update SUA Activation
 *
 * POST endpoint to update an existing SUA activation.
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
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
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
$id = isset($_POST['id']) ? intval($_POST['id']) : null;
$start_utc = isset($_POST['start_utc']) ? strip_tags($_POST['start_utc']) : null;
$end_utc = isset($_POST['end_utc']) ? strip_tags($_POST['end_utc']) : null;
$lower_alt = isset($_POST['lower_alt']) ? strip_tags($_POST['lower_alt']) : null;
$upper_alt = isset($_POST['upper_alt']) ? strip_tags($_POST['upper_alt']) : null;
$remarks = isset($_POST['remarks']) ? strip_tags($_POST['remarks']) : null;
$status = isset($_POST['status']) ? strtoupper(strip_tags($_POST['status'])) : null;

// Validation
if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing activation ID']);
    exit;
}

// Build update query dynamically
$updates = [];
$params = [];

if ($start_utc !== null) {
    $updates[] = "start_utc = ?";
    $params[] = $start_utc;
}
if ($end_utc !== null) {
    $updates[] = "end_utc = ?";
    $params[] = $end_utc;
}
if ($lower_alt !== null) {
    $updates[] = "lower_alt = ?";
    $params[] = $lower_alt;
}
if ($upper_alt !== null) {
    $updates[] = "upper_alt = ?";
    $params[] = $upper_alt;
}
if ($remarks !== null) {
    $updates[] = "remarks = ?";
    $params[] = $remarks;
}
if ($status !== null) {
    $updates[] = "status = ?";
    $params[] = $status;
}

$updates[] = "updated_at = GETUTCDATE()";

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
    exit;
}

$sql = "UPDATE sua_activations SET " . implode(', ', $updates) . " WHERE id = ?";
$params[] = $id;

$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update activation']);
    exit;
}

sqlsrv_free_stmt($stmt);
http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'Activation updated']);
