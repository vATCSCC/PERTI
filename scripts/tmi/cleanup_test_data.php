<?php
/**
 * TMI Test Data Cleanup Script
 * 
 * Removes test data created by test_crud.php
 * 
 * Usage: php scripts/tmi/cleanup_test_data.php
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

echo "=== TMI Test Data Cleanup ===\n";
echo "Date: " . date('Y-m-d H:i:s T') . "\n\n";

// Check connection
if (!$conn_tmi) {
    die("ERROR: Cannot connect to VATSIM_TMI database\n");
}

$cleanup_file = __DIR__ . '/test_data_ids.json';

// Option 1: Use saved IDs
if (file_exists($cleanup_file)) {
    echo "Found test data IDs file...\n";
    $created = json_decode(file_get_contents($cleanup_file), true);
    
    // Delete in reverse dependency order
    if (!empty($created['public_routes'])) {
        foreach ($created['public_routes'] as $id) {
            $result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
            echo $result !== false ? "✓ Deleted public route $id\n" : "✗ Failed to delete public route $id\n";
        }
    }
    
    if (!empty($created['reroutes'])) {
        foreach ($created['reroutes'] as $id) {
            // Delete flight assignments first
            sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_reroute_flights WHERE reroute_id = ?", [$id]);
            $result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_reroutes WHERE reroute_id = ?", [$id]);
            echo $result !== false ? "✓ Deleted reroute $id\n" : "✗ Failed to delete reroute $id\n";
        }
    }
    
    if (!empty($created['advisories'])) {
        foreach ($created['advisories'] as $id) {
            $result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_advisories WHERE advisory_id = ?", [$id]);
            echo $result !== false ? "✓ Deleted advisory $id\n" : "✗ Failed to delete advisory $id\n";
        }
    }
    
    if (!empty($created['programs'])) {
        foreach ($created['programs'] as $id) {
            // Delete slots first
            sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_slots WHERE program_id = ?", [$id]);
            $result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_programs WHERE program_id = ?", [$id]);
            echo $result !== false ? "✓ Deleted program $id\n" : "✗ Failed to delete program $id\n";
        }
    }
    
    if (!empty($created['entries'])) {
        foreach ($created['entries'] as $id) {
            $result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_entries WHERE entry_id = ?", [$id]);
            echo $result !== false ? "✓ Deleted entry $id\n" : "✗ Failed to delete entry $id\n";
        }
    }
    
    // Clean up events from test
    $result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_events WHERE source_type = 'TEST'");
    $affected = sqlsrv_rows_affected($result);
    echo "✓ Deleted $affected test events\n";
    
    // Remove the IDs file
    unlink($cleanup_file);
    echo "\n✓ Removed test data IDs file\n";
    
} else {
    // Option 2: Delete all TEST source records
    echo "No saved IDs file found. Deleting all TEST source records...\n\n";
    
    $tables = [
        'tmi_public_routes' => 'created_by',
        'tmi_reroute_flights' => null,  // handled by cascade
        'tmi_reroutes' => 'source_type',
        'tmi_advisories' => 'source_type',
        'tmi_slots' => null,  // handled by cascade
        'tmi_programs' => 'source_type',
        'tmi_entries' => 'source_type',
        'tmi_events' => 'source_type'
    ];
    
    foreach ($tables as $table => $source_col) {
        if ($source_col) {
            $sql = "DELETE FROM dbo.$table WHERE $source_col = 'TEST' OR $source_col = 'test_script'";
            $result = sqlsrv_query($conn_tmi, $sql);
            if ($result !== false) {
                $affected = sqlsrv_rows_affected($result);
                echo "✓ Deleted $affected records from $table\n";
            } else {
                echo "✗ Failed to delete from $table\n";
            }
        }
    }
}

echo "\n=== Cleanup Complete ===\n";
