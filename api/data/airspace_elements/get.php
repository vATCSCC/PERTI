<?php
/**
 * Airspace Element Get API
 * Returns a single airspace element by ID
 *
 * GET ?element_id=123
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");

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

$element_id = isset($_GET['element_id']) ? get_int('element_id') : null;

if (!$element_id) {
    http_response_code(400);
    echo json_encode(['error' => 'element_id is required']);
    exit;
}

try {
    $sql = "
        SELECT
            e.element_id,
            e.element_name,
            e.element_type,
            e.element_subtype,
            e.reference_boundary_id,
            e.reference_fix_name,
            e.reference_airway,
            e.geometry.STAsText() AS geometry_wkt,
            e.definition_json,
            e.radius_nm,
            e.floor_fl,
            e.ceiling_fl,
            e.category,
            e.description,
            e.created_by,
            e.created_at,
            e.updated_at,
            e.is_active,
            -- Referenced boundary info
            b.boundary_code AS ref_boundary_code,
            b.boundary_type AS ref_boundary_type,
            b.boundary_name AS ref_boundary_name
        FROM dbo.adl_airspace_element e
        LEFT JOIN dbo.adl_boundary b ON b.boundary_id = e.reference_boundary_id
        WHERE e.element_id = :element_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':element_id' => $element_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        http_response_code(404);
        echo json_encode(['error' => 'Element not found']);
        exit;
    }

    // Get crossing statistics for this element
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_crossings,
            COUNT(DISTINCT flight_uid) AS unique_flights,
            MIN(planned_entry_utc) AS earliest_crossing,
            MAX(planned_entry_utc) AS latest_crossing
        FROM dbo.adl_flight_planned_crossings
        WHERE element_id = :element_id
          AND planned_entry_utc >= GETUTCDATE()
    ");
    $statsStmt->execute([':element_id' => $element_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $data,
        'crossing_stats' => $stats
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Query failed',
        'message' => $e->getMessage()
    ]);
}
?>
