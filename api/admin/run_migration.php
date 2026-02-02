<?php
/**
 * Run Database Migration
 *
 * One-time use endpoint to run specific migrations.
 * DELETE THIS FILE AFTER USE.
 *
 * Usage: GET ?migration=add_canadian_fixes&confirm=yes
 */

require_once __DIR__ . '/../../load/config.php';

header('Content-Type: application/json');

// Require confirmation parameter
if (($_GET['confirm'] ?? '') !== 'yes') {
    echo json_encode([
        'success' => false,
        'error' => 'Add ?confirm=yes to run migration',
        'available_migrations' => ['add_canadian_fixes']
    ]);
    exit;
}

$migration = $_GET['migration'] ?? '';

if ($migration !== 'add_canadian_fixes') {
    echo json_encode([
        'success' => false,
        'error' => 'Unknown migration: ' . $migration,
        'available_migrations' => ['add_canadian_fixes']
    ]);
    exit;
}

try {
    // Connect to VATSIM_ADL
    $dsn = "sqlsrv:server=tcp:" . ADL_SQL_HOST . ",1433;Database=" . ADL_SQL_DATABASE;
    $pdo = new PDO($dsn, ADL_SQL_USERNAME, ADL_SQL_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $results = [];

    // Canadian fixes for YVR TMI analysis
    $fixes = [
        ['EGRET', 'WAYPOINT', 48.71162777, -122.5094472],
        ['NADPI', 'WAYPOINT', 51.714444, -117.34],
        ['NOVAR', 'WAYPOINT', 50.6725, -116.390278],
    ];

    foreach ($fixes as $fix) {
        // Check if exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.nav_fixes WHERE fix_name = ?");
        $stmt->execute([$fix[0]]);
        $exists = (int)$stmt->fetchColumn() > 0;

        if ($exists) {
            $results[] = ['fix' => $fix[0], 'status' => 'already_exists'];
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, source)
                VALUES (?, ?, ?, ?, 'points.csv')
            ");
            $stmt->execute([$fix[0], $fix[1], $fix[2], $fix[3]]);
            $results[] = ['fix' => $fix[0], 'status' => 'inserted', 'lat' => $fix[2], 'lon' => $fix[3]];
        }
    }

    // Verify
    $stmt = $pdo->query("
        SELECT fix_name, fix_type, lat, lon
        FROM dbo.nav_fixes
        WHERE fix_name IN ('EGRET', 'NADPI', 'NOVAR')
    ");
    $verification = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'migration' => 'add_canadian_fixes',
        'results' => $results,
        'verification' => $verification,
        'message' => 'Migration complete. DELETE THIS FILE NOW.'
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
