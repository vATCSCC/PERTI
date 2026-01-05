<?php
/**
 * Cancel/Delete SUA Activation
 *
 * POST endpoint to cancel or delete an activation.
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
$hard_delete = isset($_POST['hard_delete']) && $_POST['hard_delete'] === 'true';

if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing activation ID']);
    exit;
}

if ($hard_delete) {
    // Permanently delete
    $sql = "DELETE FROM sua_activations WHERE id = ?";
} else {
    // Soft delete (set status to CANCELLED)
    $sql = "UPDATE sua_activations SET status = 'CANCELLED', updated_at = GETUTCDATE() WHERE id = ?";
}

$stmt = sqlsrv_query($conn_adl, $sql, [$id]);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to cancel activation']);
    exit;
}

sqlsrv_free_stmt($stmt);
http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'Activation cancelled']);
