<?php
/**
 * Airspace Element Delete API
 * Soft-deletes an airspace element (sets is_active = 0)
 * Use ?hard=1 for permanent deletion
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/connect.php");

// Check Permissions
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
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Use ADL database connection
try {
    $dsn = "sqlsrv:Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE;
    $pdo = new PDO($dsn, ADL_SQL_USERNAME, ADL_SQL_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get element_id from POST or GET
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = array_merge($_GET, $_POST);
}

$element_id = isset($input['element_id']) ? intval($input['element_id']) : null;
$hard_delete = isset($input['hard']) ? boolval($input['hard']) : false;

if (!$element_id) {
    http_response_code(400);
    echo json_encode(['error' => 'element_id is required']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($hard_delete) {
        // First delete any crossings referencing this element
        $stmt = $pdo->prepare("DELETE FROM dbo.adl_flight_planned_crossings WHERE element_id = :element_id");
        $stmt->execute([':element_id' => $element_id]);
        $crossings_deleted = $stmt->rowCount();

        // Then delete the element
        $stmt = $pdo->prepare("DELETE FROM dbo.adl_airspace_element WHERE element_id = :element_id");
        $stmt->execute([':element_id' => $element_id]);
        $affected = $stmt->rowCount();

        $message = "Element permanently deleted";
        if ($crossings_deleted > 0) {
            $message .= " ($crossings_deleted crossings removed)";
        }
    } else {
        // Soft delete
        $stmt = $pdo->prepare("UPDATE dbo.adl_airspace_element SET is_active = 0, updated_at = GETUTCDATE() WHERE element_id = :element_id");
        $stmt->execute([':element_id' => $element_id]);
        $affected = $stmt->rowCount();
        $message = "Element deactivated";
    }

    $pdo->commit();

    if ($affected > 0) {
        echo json_encode([
            'success' => true,
            'element_id' => $element_id,
            'hard_delete' => $hard_delete,
            'message' => $message
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Element not found']);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to delete element',
        'message' => $e->getMessage()
    ]);
}
?>
