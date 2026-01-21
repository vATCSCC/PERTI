<?php
/**
 * Public Routes Migration Script
 *
 * Migrates data from VATSIM_ADL.dbo.public_routes to VATSIM_TMI.dbo.tmi_public_routes
 * Uses PHP to bridge the two Azure SQL databases (cross-DB queries not supported).
 *
 * Run from CLI: php scripts/migrate_public_routes.php
 * Or via browser: /scripts/migrate_public_routes.php
 */

// Load database connections
require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/connect.php';

header('Content-Type: text/plain; charset=utf-8');

echo "===========================================\n";
echo "Public Routes Migration: ADL -> TMI\n";
echo "===========================================\n\n";

// Check connections
if (!$conn_adl) {
    die("ERROR: VATSIM_ADL connection not available\n");
}
if (!$conn_tmi) {
    die("ERROR: VATSIM_TMI connection not available\n");
}

echo "[OK] Both database connections available\n\n";

// Step 1: Count source records
echo "Step 1: Checking source table (VATSIM_ADL.dbo.public_routes)...\n";
$count_sql = "SELECT COUNT(*) as total FROM dbo.public_routes";
$stmt = sqlsrv_query($conn_adl, $count_sql);
if ($stmt === false) {
    die("ERROR: Cannot query source table: " . print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$source_count = (int)$row['total'];
sqlsrv_free_stmt($stmt);
echo "  Source has $source_count routes\n\n";

if ($source_count === 0) {
    echo "No data to migrate. Exiting.\n";
    exit(0);
}

// Step 2: Count target records
echo "Step 2: Checking target table (VATSIM_TMI.dbo.tmi_public_routes)...\n";
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_public_routes";
$stmt = sqlsrv_query($conn_tmi, $count_sql);
if ($stmt === false) {
    die("ERROR: Cannot query target table: " . print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$target_count = (int)$row['total'];
sqlsrv_free_stmt($stmt);
echo "  Target has $target_count existing routes\n\n";

// Step 3: Fetch all source records
echo "Step 3: Fetching source records...\n";
$select_sql = "SELECT
    id, status, name, adv_number, route_string, advisory_text,
    color, line_weight, line_style,
    valid_start_utc, valid_end_utc,
    constrained_area, reason, origin_filter, dest_filter, facilities,
    route_geojson, created_by, created_utc, updated_utc
FROM dbo.public_routes";

$stmt = sqlsrv_query($conn_adl, $select_sql);
if ($stmt === false) {
    die("ERROR: Cannot fetch source data: " . print_r(sqlsrv_errors(), true));
}

$routes = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $routes[] = $row;
}
sqlsrv_free_stmt($stmt);
echo "  Fetched " . count($routes) . " routes from source\n\n";

// Step 4: Get existing names in target (for deduplication)
echo "Step 4: Checking for duplicates...\n";
$existing_sql = "SELECT name, valid_start_utc FROM dbo.tmi_public_routes";
$stmt = sqlsrv_query($conn_tmi, $existing_sql);
$existing = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = $row['name'] . '|' . ($row['valid_start_utc'] instanceof DateTime ? $row['valid_start_utc']->format('Y-m-d H:i:s') : $row['valid_start_utc']);
        $existing[$key] = true;
    }
    sqlsrv_free_stmt($stmt);
}
echo "  Found " . count($existing) . " existing routes in target\n\n";

// Step 5: Insert new records
echo "Step 5: Migrating new routes...\n";
$insert_sql = "INSERT INTO dbo.tmi_public_routes (
    status, name, adv_number, route_string, advisory_text,
    color, line_weight, line_style,
    valid_start_utc, valid_end_utc,
    constrained_area, reason, origin_filter, dest_filter, facilities,
    route_geojson, created_by, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$migrated = 0;
$skipped = 0;
$errors = 0;

foreach ($routes as $route) {
    // Check for duplicate
    $validStart = $route['valid_start_utc'] instanceof DateTime ? $route['valid_start_utc']->format('Y-m-d H:i:s') : $route['valid_start_utc'];
    $key = $route['name'] . '|' . $validStart;

    if (isset($existing[$key])) {
        $skipped++;
        continue;
    }

    // Prepare parameters
    $params = [
        $route['status'] ?? 1,
        $route['name'],
        $route['adv_number'],
        $route['route_string'],
        $route['advisory_text'],
        $route['color'] ?? '#e74c3c',
        $route['line_weight'] ?? 3,
        $route['line_style'] ?? 'solid',
        $route['valid_start_utc'],
        $route['valid_end_utc'],
        $route['constrained_area'],
        $route['reason'],
        $route['origin_filter'],
        $route['dest_filter'],
        $route['facilities'],
        $route['route_geojson'],
        $route['created_by'],
        $route['created_utc'] ?? date('Y-m-d H:i:s'),
        $route['updated_utc'] ?? date('Y-m-d H:i:s')
    ];

    $stmt = sqlsrv_query($conn_tmi, $insert_sql, $params);
    if ($stmt === false) {
        $errors++;
        $err = sqlsrv_errors();
        echo "  ERROR inserting '{$route['name']}': " . ($err[0]['message'] ?? 'Unknown') . "\n";
    } else {
        $migrated++;
        sqlsrv_free_stmt($stmt);
    }
}

echo "\n";
echo "===========================================\n";
echo "Migration Complete!\n";
echo "===========================================\n";
echo "  Migrated: $migrated\n";
echo "  Skipped (duplicates): $skipped\n";
echo "  Errors: $errors\n";

// Verify final count
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_public_routes";
$stmt = sqlsrv_query($conn_tmi, $count_sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$final_count = (int)$row['total'];
sqlsrv_free_stmt($stmt);
echo "  Target now has: $final_count routes\n";
