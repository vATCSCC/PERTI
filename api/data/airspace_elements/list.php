<?php
/**
 * Airspace Elements List API
 * Returns list of custom airspace elements
 *
 * GET ?category=TMI          - Filter by category
 * GET ?type=VOLUME           - Filter by element type
 * GET ?active=1              - Filter by active status
 * GET ?search=FCA            - Search by name
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

$category = isset($_GET['category']) ? $_GET['category'] : null;
$type = isset($_GET['type']) ? strtoupper($_GET['type']) : null;
$active = isset($_GET['active']) ? get_int('active') : 1;
$search = isset($_GET['search']) ? $_GET['search'] : null;

try {
    $sql = "
        SELECT
            element_id,
            element_name,
            element_type,
            element_subtype,
            reference_boundary_id,
            reference_fix_name,
            reference_airway,
            radius_nm,
            floor_fl,
            ceiling_fl,
            category,
            description,
            created_by,
            created_at,
            updated_at,
            is_active,
            -- Get boundary info if referenced
            b.boundary_code AS ref_boundary_code,
            b.boundary_type AS ref_boundary_type
        FROM dbo.adl_airspace_element e
        LEFT JOIN dbo.adl_boundary b ON b.boundary_id = e.reference_boundary_id
        WHERE 1=1
    ";

    $params = [];

    if ($active !== null) {
        $sql .= " AND e.is_active = :active";
        $params[':active'] = $active;
    }

    if ($category) {
        $sql .= " AND e.category = :category";
        $params[':category'] = $category;
    }

    if ($type) {
        $sql .= " AND e.element_type = :type";
        $params[':type'] = $type;
    }

    if ($search) {
        $sql .= " AND (e.element_name LIKE :search OR e.description LIKE :search2)";
        $params[':search'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY e.category, e.element_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique categories for filtering
    $catStmt = $pdo->query("SELECT DISTINCT category FROM dbo.adl_airspace_element WHERE category IS NOT NULL ORDER BY category");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'count' => count($data),
        'categories' => $categories,
        'data' => $data
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Query failed',
        'message' => $e->getMessage()
    ]);
}
?>
