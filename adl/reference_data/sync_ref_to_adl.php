<?php
/**
 * VATSIM_REF → VATSIM_ADL Sync Script (PHP Version)
 *
 * Syncs reference data FROM VATSIM_REF (authoritative) TO VATSIM_ADL (cache)
 * Required because Azure SQL doesn't support cross-database queries.
 *
 * Schedule: Run nightly or after AIRAC cycle updates
 * Run from command line: php sync_ref_to_adl.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

require_once(__DIR__ . '/../../load/config.php');

// Verify both connections are configured
if (!defined("REF_SQL_HOST") || !defined("REF_SQL_DATABASE")) {
    die("ERROR: REF_SQL_* constants not defined in config.php\n");
}
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE")) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n");
}

$startTime = microtime(true);

echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "            VATSIM_REF → VATSIM_ADL SYNC SCRIPT                                 \n";
echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "Source: VATSIM_REF (authoritative)\n";
echo "Target: VATSIM_ADL (cache)\n";
echo "Started at: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// Connect to VATSIM_REF (source)
$refConnectionInfo = [
    "Database" => REF_SQL_DATABASE,
    "UID" => REF_SQL_USERNAME,
    "PWD" => REF_SQL_PASSWORD,
    "LoginTimeout" => 30,
    "Encrypt" => true,
    "TrustServerCertificate" => false
];

$connRef = sqlsrv_connect(REF_SQL_HOST, $refConnectionInfo);
if ($connRef === false) {
    die("ERROR: Cannot connect to VATSIM_REF - " . print_r(sqlsrv_errors(), true) . "\n");
}
echo "Connected to VATSIM_REF (source)\n";

// Connect to VATSIM_ADL (target)
$adlConnectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "LoginTimeout" => 30,
    "Encrypt" => true,
    "TrustServerCertificate" => false
];

$connAdl = sqlsrv_connect(ADL_SQL_HOST, $adlConnectionInfo);
if ($connAdl === false) {
    die("ERROR: Cannot connect to VATSIM_ADL - " . print_r(sqlsrv_errors(), true) . "\n");
}
echo "Connected to VATSIM_ADL (target)\n\n";

$success = 0;
$failed = 0;

/**
 * Sync a table from REF to ADL
 */
function syncTable($connRef, $connAdl, $tableName, $columns, $hasIdentity = true, $preTruncateFk = null) {
    global $success, $failed;

    $startTime = microtime(true);
    echo "────────────────────────────────────────────────────────────────────────────────\n";
    echo "Syncing: $tableName\n";

    try {
        // Handle FK dependencies
        if ($preTruncateFk) {
            $deleteStmt = sqlsrv_query($connAdl, "DELETE FROM dbo.$preTruncateFk");
            if ($deleteStmt === false) {
                throw new Exception("Failed to delete from $preTruncateFk: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($deleteStmt);
            echo "  Cleared dependent table: $preTruncateFk\n";
        }

        // Truncate target table
        $truncateStmt = sqlsrv_query($connAdl, "TRUNCATE TABLE dbo.$tableName");
        if ($truncateStmt === false) {
            // TRUNCATE may fail if there are FK constraints, try DELETE instead
            $deleteStmt = sqlsrv_query($connAdl, "DELETE FROM dbo.$tableName");
            if ($deleteStmt === false) {
                throw new Exception("Failed to truncate/delete $tableName: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($deleteStmt);
        } else {
            sqlsrv_free_stmt($truncateStmt);
        }
        echo "  Truncated target table\n";

        // Enable IDENTITY_INSERT if needed
        if ($hasIdentity) {
            sqlsrv_query($connAdl, "SET IDENTITY_INSERT dbo.$tableName ON");
        }

        // Read all data from REF
        $selectSql = "SELECT " . implode(', ', $columns) . " FROM dbo.$tableName";
        $selectStmt = sqlsrv_query($connRef, $selectSql);
        if ($selectStmt === false) {
            throw new Exception("Failed to select from REF.$tableName: " . print_r(sqlsrv_errors(), true));
        }

        // Prepare insert statement for ADL
        $placeholders = array_fill(0, count($columns), '?');
        $insertSql = "INSERT INTO dbo.$tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $rowCount = 0;
        $batchSize = 1000;
        $batch = [];

        while ($row = sqlsrv_fetch_array($selectStmt, SQLSRV_FETCH_NUMERIC)) {
            // Insert directly
            $insertStmt = sqlsrv_query($connAdl, $insertSql, $row);
            if ($insertStmt === false) {
                // Log but continue
                if ($rowCount < 5) {
                    echo "  Warning: Insert error at row $rowCount: " . print_r(sqlsrv_errors(), true) . "\n";
                }
            } else {
                sqlsrv_free_stmt($insertStmt);
            }
            $rowCount++;

            // Progress indicator
            if ($rowCount % 10000 == 0) {
                echo "  Processed $rowCount rows...\n";
            }
        }
        sqlsrv_free_stmt($selectStmt);

        // Disable IDENTITY_INSERT
        if ($hasIdentity) {
            sqlsrv_query($connAdl, "SET IDENTITY_INSERT dbo.$tableName OFF");
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        echo "  Synced $rowCount rows in {$elapsed}s\n";
        $success++;

        return $rowCount;

    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        $failed++;
        return 0;
    }
}

// ============================================================================
// Sync each table
// ============================================================================

// 1. nav_fixes (~534K rows)
syncTable($connRef, $connAdl, 'nav_fixes', [
    'fix_id', 'fix_name', 'fix_type', 'lat', 'lon',
    'artcc_id', 'state_code', 'country_code',
    'freq_mhz', 'mag_var', 'elevation_ft',
    'source', 'effective_date'
    // Note: position_geo is computed, not synced
], true);

// 2. airways (clear segments first due to FK)
syncTable($connRef, $connAdl, 'airways', [
    'airway_id', 'airway_name', 'airway_type',
    'fix_sequence', 'fix_count', 'start_fix', 'end_fix',
    'min_alt_ft', 'max_alt_ft', 'direction',
    'source', 'effective_date'
], true, 'airway_segments');

// 3. airway_segments
syncTable($connRef, $connAdl, 'airway_segments', [
    'segment_id', 'airway_id', 'airway_name',
    'sequence_num', 'from_fix', 'to_fix',
    'from_lat', 'from_lon', 'to_lat', 'to_lon',
    'distance_nm', 'course_deg',
    'min_alt_ft', 'max_alt_ft'
    // Note: segment_geo is computed, not synced
], true);

// 4. nav_procedures (~97K rows)
syncTable($connRef, $connAdl, 'nav_procedures', [
    'procedure_id', 'procedure_type', 'airport_icao', 'procedure_name', 'computer_code',
    'transition_name', 'full_route', 'runways',
    'is_active', 'source', 'effective_date'
], true);

// 5. coded_departure_routes (~41K rows)
syncTable($connRef, $connAdl, 'coded_departure_routes', [
    'cdr_id', 'cdr_code', 'full_route',
    'origin_icao', 'dest_icao', 'direction',
    'altitude_min_ft', 'altitude_max_ft',
    'is_active', 'source', 'effective_date'
], true);

// 6. playbook_routes (~35K rows)
syncTable($connRef, $connAdl, 'playbook_routes', [
    'playbook_id', 'play_name', 'full_route',
    'origin_airports', 'origin_tracons', 'origin_artccs',
    'dest_airports', 'dest_tracons', 'dest_artccs',
    'altitude_min_ft', 'altitude_max_ft',
    'is_active', 'source', 'effective_date'
], true);

// 7. area_centers (~39 rows)
syncTable($connRef, $connAdl, 'area_centers', [
    'center_id', 'center_code', 'center_type', 'center_name',
    'lat', 'lon', 'parent_artcc'
    // Note: position_geo is computed, not synced
], true);

// 8. oceanic_fir_bounds (~11 rows)
syncTable($connRef, $connAdl, 'oceanic_fir_bounds', [
    'fir_id', 'fir_code', 'fir_name', 'fir_type',
    'min_lat', 'max_lat', 'min_lon', 'max_lon',
    'keeps_tier_1'
], true);

// ============================================================================
// Summary
// ============================================================================
$elapsed = microtime(true) - $startTime;
$minutes = floor($elapsed / 60);
$seconds = $elapsed % 60;

echo "\n════════════════════════════════════════════════════════════════════════════════\n";
echo "                              SYNC COMPLETE                                     \n";
echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "Tables synced successfully: $success\n";
echo "Tables failed: $failed\n";
echo "Total time: {$minutes}m " . round($seconds, 1) . "s\n";
echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";

// Log sync completion to VATSIM_REF
$logSql = "INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
           VALUES ('ALL_TABLES', ?, 'TO_ADL', ?, ?)";
$logParams = [$success, ($failed == 0 ? 'SUCCESS' : 'PARTIAL'), round($elapsed * 1000)];
sqlsrv_query($connRef, $logSql, $logParams);

sqlsrv_close($connRef);
sqlsrv_close($connAdl);

if ($failed > 0) {
    echo "\nWARNING: Some tables failed to sync. Check errors above.\n";
    exit(1);
}
