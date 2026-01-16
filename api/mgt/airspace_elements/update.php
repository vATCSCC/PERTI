<?php
/**
 * Airspace Element Update API
 * Updates an existing custom airspace element
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
        $cid = session_get('VATSIM_CID', '');
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// Required field
$element_id = isset($input['element_id']) ? intval($input['element_id']) : null;

if (!$element_id) {
    http_response_code(400);
    echo json_encode(['error' => 'element_id is required']);
    exit;
}

// Build dynamic UPDATE query based on provided fields
$updates = [];
$params = [':element_id' => $element_id];

$allowed_fields = [
    'element_name' => 'element_name',
    'element_type' => 'element_type',
    'element_subtype' => 'element_subtype',
    'reference_boundary_id' => 'reference_boundary_id',
    'reference_fix_name' => 'reference_fix_name',
    'reference_airway' => 'reference_airway',
    'definition_json' => 'definition_json',
    'radius_nm' => 'radius_nm',
    'floor_fl' => 'floor_fl',
    'ceiling_fl' => 'ceiling_fl',
    'category' => 'category',
    'description' => 'description',
    'is_active' => 'is_active'
];

foreach ($allowed_fields as $input_key => $db_column) {
    if (isset($input[$input_key])) {
        $value = $input[$input_key];

        // Type conversion
        if (in_array($db_column, ['reference_boundary_id', 'floor_fl', 'ceiling_fl'])) {
            $value = $value === '' ? null : intval($value);
        } elseif ($db_column === 'radius_nm') {
            $value = $value === '' ? null : floatval($value);
        } elseif ($db_column === 'is_active') {
            $value = intval($value);
        } elseif ($db_column === 'definition_json' && is_array($value)) {
            $value = json_encode($value);
        } elseif ($db_column === 'element_type') {
            $value = strtoupper(trim($value));
        }

        $updates[] = "$db_column = :$db_column";
        $params[":$db_column"] = $value;
    }
}

// Handle geometry_wkt specially (needs function call)
$geometry_wkt = isset($input['geometry_wkt']) ? trim($input['geometry_wkt']) : null;
if ($geometry_wkt) {
    $updates[] = "geometry = geography::STGeomFromText(:geometry_wkt, 4326)";
    $params[':geometry_wkt'] = $geometry_wkt;
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['error' => 'No fields to update']);
    exit;
}

// Always update updated_at
$updates[] = "updated_at = GETUTCDATE()";

try {
    $pdo->beginTransaction();

    $sql = "UPDATE dbo.adl_airspace_element SET " . implode(', ', $updates) . " WHERE element_id = :element_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $affected = $stmt->rowCount();

    $pdo->commit();

    if ($affected > 0) {
        echo json_encode([
            'success' => true,
            'element_id' => $element_id,
            'message' => 'Element updated successfully'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Element not found']);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to update element',
        'message' => $e->getMessage()
    ]);
}
?>
