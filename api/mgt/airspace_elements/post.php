<?php
/**
 * Airspace Element Create API
 * Creates a new custom airspace element
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
    $_SESSION['VATSIM_CID'] = 0;
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

// Required fields
$element_name = isset($input['element_name']) ? trim($input['element_name']) : null;
$element_type = isset($input['element_type']) ? strtoupper(trim($input['element_type'])) : null;

if (!$element_name || !$element_type) {
    http_response_code(400);
    echo json_encode(['error' => 'element_name and element_type are required']);
    exit;
}

// Validate element_type
$valid_types = ['VOLUME', 'POINT', 'LINE'];
if (!in_array($element_type, $valid_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'element_type must be one of: VOLUME, POINT, LINE']);
    exit;
}

// Optional fields
$element_subtype = isset($input['element_subtype']) ? trim($input['element_subtype']) : null;
$reference_boundary_id = isset($input['reference_boundary_id']) ? intval($input['reference_boundary_id']) : null;
$reference_fix_name = isset($input['reference_fix_name']) ? trim($input['reference_fix_name']) : null;
$reference_airway = isset($input['reference_airway']) ? trim($input['reference_airway']) : null;
$geometry_wkt = isset($input['geometry_wkt']) ? trim($input['geometry_wkt']) : null;
$definition_json = isset($input['definition_json']) ? $input['definition_json'] : null;
$radius_nm = isset($input['radius_nm']) ? floatval($input['radius_nm']) : null;
$floor_fl = isset($input['floor_fl']) ? intval($input['floor_fl']) : null;
$ceiling_fl = isset($input['ceiling_fl']) ? intval($input['ceiling_fl']) : null;
$category = isset($input['category']) ? trim($input['category']) : null;
$description = isset($input['description']) ? trim($input['description']) : null;

$created_by = isset($_SESSION['VATSIM_CID']) ? $_SESSION['VATSIM_CID'] : 'system';

try {
    $pdo->beginTransaction();

    // Build SQL with optional geometry
    if ($geometry_wkt) {
        $sql = "
            INSERT INTO dbo.adl_airspace_element (
                element_name, element_type, element_subtype,
                reference_boundary_id, reference_fix_name, reference_airway,
                geometry, definition_json, radius_nm,
                floor_fl, ceiling_fl, category, description, created_by
            ) VALUES (
                :element_name, :element_type, :element_subtype,
                :reference_boundary_id, :reference_fix_name, :reference_airway,
                geography::STGeomFromText(:geometry_wkt, 4326), :definition_json, :radius_nm,
                :floor_fl, :ceiling_fl, :category, :description, :created_by
            )
        ";
    } else {
        $sql = "
            INSERT INTO dbo.adl_airspace_element (
                element_name, element_type, element_subtype,
                reference_boundary_id, reference_fix_name, reference_airway,
                definition_json, radius_nm,
                floor_fl, ceiling_fl, category, description, created_by
            ) VALUES (
                :element_name, :element_type, :element_subtype,
                :reference_boundary_id, :reference_fix_name, :reference_airway,
                :definition_json, :radius_nm,
                :floor_fl, :ceiling_fl, :category, :description, :created_by
            )
        ";
    }

    $stmt = $pdo->prepare($sql);
    $params = [
        ':element_name' => $element_name,
        ':element_type' => $element_type,
        ':element_subtype' => $element_subtype,
        ':reference_boundary_id' => $reference_boundary_id,
        ':reference_fix_name' => $reference_fix_name,
        ':reference_airway' => $reference_airway,
        ':definition_json' => is_array($definition_json) ? json_encode($definition_json) : $definition_json,
        ':radius_nm' => $radius_nm,
        ':floor_fl' => $floor_fl,
        ':ceiling_fl' => $ceiling_fl,
        ':category' => $category,
        ':description' => $description,
        ':created_by' => $created_by
    ];

    if ($geometry_wkt) {
        $params[':geometry_wkt'] = $geometry_wkt;
    }

    $stmt->execute($params);
    $element_id = $pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'element_id' => $element_id,
        'message' => 'Element created successfully'
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create element',
        'message' => $e->getMessage()
    ]);
}
?>
