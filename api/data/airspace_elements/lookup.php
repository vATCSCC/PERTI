<?php
/**
 * Airspace Element Lookup API
 * Returns reference data for creating/editing airspace elements
 *
 * GET ?type=boundaries        - List available boundaries for reference
 * GET ?type=fixes&search=JFK  - Search fixes by name
 * GET ?type=airways&search=J  - Search airways
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

$type = isset($_GET['type']) ? strtolower($_GET['type']) : 'boundaries';
$search = isset($_GET['search']) ? trim($_GET['search']) : null;
$boundary_type = isset($_GET['boundary_type']) ? strtoupper($_GET['boundary_type']) : null;
$limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 500) : 100;

try {
    switch ($type) {
        // ================================================================
        // List boundaries for reference
        // ================================================================
        case 'boundaries':
            $sql = "
                SELECT TOP (:limit)
                    boundary_id,
                    boundary_code,
                    boundary_type,
                    boundary_name
                FROM dbo.adl_boundary
                WHERE is_active = 1
            ";
            $params = [':limit' => $limit];

            if ($boundary_type) {
                $sql .= " AND boundary_type = :boundary_type";
                $params[':boundary_type'] = $boundary_type;
            }

            if ($search) {
                $sql .= " AND (boundary_code LIKE :search OR boundary_name LIKE :search2)";
                $params[':search'] = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY boundary_type, boundary_code";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get boundary types for filtering
            $typesStmt = $pdo->query("SELECT DISTINCT boundary_type FROM dbo.adl_boundary WHERE is_active = 1 ORDER BY boundary_type");
            $boundary_types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);
            break;

        // ================================================================
        // Search fixes (requires navdata)
        // ================================================================
        case 'fixes':
            if (!$search || strlen($search) < 2) {
                $data = [];
                break;
            }

            // Check if navdata table exists
            $tableCheck = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'navdata_fixes'");
            if ($tableCheck->fetch()) {
                $sql = "
                    SELECT TOP (:limit)
                        fix_name,
                        fix_type,
                        lat,
                        lon
                    FROM dbo.navdata_fixes
                    WHERE fix_name LIKE :search
                    ORDER BY fix_name
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':search' => $search . '%', ':limit' => $limit]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Fallback: return empty with note
                $data = [];
            }
            break;

        // ================================================================
        // Search airways (requires navdata)
        // ================================================================
        case 'airways':
            if (!$search || strlen($search) < 1) {
                $data = [];
                break;
            }

            // Check if airways table exists
            $tableCheck = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'navdata_airways'");
            if ($tableCheck->fetch()) {
                $sql = "
                    SELECT DISTINCT TOP (:limit)
                        airway_id,
                        airway_type
                    FROM dbo.navdata_airways
                    WHERE airway_id LIKE :search
                    ORDER BY airway_id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':search' => $search . '%', ':limit' => $limit]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $data = [];
            }
            break;

        // ================================================================
        // Element subtypes reference
        // ================================================================
        case 'subtypes':
            $data = [
                'VOLUME' => [
                    'ARTCC' => 'Air Route Traffic Control Center',
                    'SECTOR_HIGH' => 'High-altitude Sector',
                    'SECTOR_LOW' => 'Low-altitude Sector',
                    'SECTOR_SUPERHIGH' => 'Super-high Sector',
                    'TRACON' => 'Terminal Radar Approach Control',
                    'ATCT' => 'Airport Traffic Control Tower',
                    'FCA' => 'Flow Constrained Area',
                    'AFP' => 'Airspace Flow Program',
                    'CUSTOM' => 'Custom Volume'
                ],
                'POINT' => [
                    'FIX' => 'Navigation Fix',
                    'NAVAID' => 'Navigation Aid (VOR/NDB)',
                    'AIRPORT' => 'Airport',
                    'METER_FIX' => 'Metering Fix',
                    'CUSTOM' => 'Custom Point'
                ],
                'LINE' => [
                    'AIRWAY' => 'Published Airway',
                    'STAR' => 'Standard Terminal Arrival Route',
                    'SID' => 'Standard Instrument Departure',
                    'ROUTE' => 'Published Route',
                    'CUSTOM' => 'Custom Line'
                ]
            ];
            break;

        // ================================================================
        // Categories in use
        // ================================================================
        case 'categories':
            $stmt = $pdo->query("
                SELECT DISTINCT category, COUNT(*) AS element_count
                FROM dbo.adl_airspace_element
                WHERE category IS NOT NULL
                GROUP BY category
                ORDER BY category
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add suggested categories if not present
            $suggested = ['TMI', 'FCA', 'AFP', 'REROUTE', 'CUSTOM'];
            $existing = array_column($data, 'category');
            foreach ($suggested as $cat) {
                if (!in_array($cat, $existing)) {
                    $data[] = ['category' => $cat, 'element_count' => 0, 'suggested' => true];
                }
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type. Use: boundaries, fixes, airways, subtypes, categories']);
            exit;
    }

    $response = [
        'success' => true,
        'type' => $type,
        'count' => count($data),
        'data' => $data
    ];

    if ($type === 'boundaries' && isset($boundary_types)) {
        $response['boundary_types'] = $boundary_types;
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Query failed',
        'message' => $e->getMessage()
    ]);
}
?>
