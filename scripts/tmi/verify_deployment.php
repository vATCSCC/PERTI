<?php
/**
 * VATSIM_TMI Database Deployment Verification Script
 * 
 * Run this script after deploying the TMI database migration to verify
 * all objects were created correctly.
 * 
 * Usage:
 *   php scripts/tmi/verify_deployment.php
 *   
 * Or via browser (if accessible):
 *   https://perti.vatcscc.org/scripts/tmi/verify_deployment.php?allow=1
 */

// Prevent web access in production (remove this block for testing)
if (php_sapi_name() !== 'cli' && !isset($_GET['allow'])) {
    die('This script should be run from CLI. Add ?allow=1 to run from browser.');
}

// Load configuration
require_once __DIR__ . '/../../load/connect.php';

// Output formatting
$is_cli = php_sapi_name() === 'cli';
$nl = $is_cli ? "\n" : "<br>\n";
$bold_start = $is_cli ? "\033[1m" : "<strong>";
$bold_end = $is_cli ? "\033[0m" : "</strong>";
$green = $is_cli ? "\033[32m" : "<span style='color:green'>";
$red = $is_cli ? "\033[31m" : "<span style='color:red'>";
$yellow = $is_cli ? "\033[33m" : "<span style='color:orange'>";
$reset = $is_cli ? "\033[0m" : "</span>";

if (!$is_cli) {
    echo "<!DOCTYPE html><html><head><title>TMI Deployment Verification</title>";
    echo "<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#eee;}pre{white-space:pre-wrap;}</style></head><body>";
    echo "<h1>VATSIM_TMI Deployment Verification</h1><pre>";
}

echo "{$bold_start}========================================{$bold_end}{$nl}";
echo "{$bold_start}VATSIM_TMI Deployment Verification{$bold_end}{$nl}";
echo "{$bold_start}========================================{$bold_end}{$nl}{$nl}";

$all_passed = true;

// ============================================================================
// Step 1: Check Connection
// ============================================================================

echo "{$bold_start}[1] Connection Test{$bold_end}{$nl}";

if (!$conn_tmi) {
    echo "{$red}✗ TMI connection failed!{$reset}{$nl}";
    echo "  Check your config.php for TMI_SQL_* constants.{$nl}";
    if (function_exists('sqlsrv_errors') && sqlsrv_errors()) {
        foreach (sqlsrv_errors() as $error) {
            echo "  Error: " . $error['message'] . "{$nl}";
        }
    }
    exit(1);
}

echo "{$green}✓ Connected to VATSIM_TMI successfully{$reset}{$nl}{$nl}";

// ============================================================================
// Step 2: Check Tables
// ============================================================================

echo "{$bold_start}[2] Tables Check{$bold_end}{$nl}";

$expected_tables = [
    'tmi_entries',
    'tmi_programs', 
    'tmi_advisories',
    'tmi_slots',
    'tmi_reroutes',
    'tmi_reroute_flights',
    'tmi_reroute_compliance_log',
    'tmi_public_routes',
    'tmi_events',
    'tmi_advisory_sequences'
];

$sql = "SELECT name FROM sys.tables WHERE is_ms_shipped = 0 ORDER BY name";
$result = sqlsrv_query($conn_tmi, $sql);

$found_tables = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $found_tables[] = $row['name'];
    }
}

$tables_ok = true;
foreach ($expected_tables as $table) {
    if (in_array($table, $found_tables)) {
        echo "  {$green}✓{$reset} {$table}{$nl}";
    } else {
        echo "  {$red}✗{$reset} {$table} - MISSING{$nl}";
        $tables_ok = false;
    }
}

// Check for unexpected tables
$unexpected = array_diff($found_tables, $expected_tables);
foreach ($unexpected as $table) {
    echo "  {$yellow}?{$reset} {$table} - unexpected (may be OK){$nl}";
}

echo "{$nl}Tables: " . count($found_tables) . "/" . count($expected_tables) . " found";
echo ($tables_ok ? " {$green}✓{$reset}" : " {$red}✗{$reset}") . "{$nl}{$nl}";
if (!$tables_ok) $all_passed = false;

// ============================================================================
// Step 3: Check Views
// ============================================================================

echo "{$bold_start}[3] Views Check{$bold_end}{$nl}";

$expected_views = [
    'vw_tmi_active_entries',
    'vw_tmi_active_advisories',
    'vw_tmi_active_programs',
    'vw_tmi_active_reroutes',
    'vw_tmi_active_public_routes',
    'vw_tmi_recent_entries'
];

$sql = "SELECT name FROM sys.views WHERE is_ms_shipped = 0 ORDER BY name";
$result = sqlsrv_query($conn_tmi, $sql);

$found_views = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $found_views[] = $row['name'];
    }
}

$views_ok = true;
foreach ($expected_views as $view) {
    if (in_array($view, $found_views)) {
        echo "  {$green}✓{$reset} {$view}{$nl}";
    } else {
        echo "  {$red}✗{$reset} {$view} - MISSING{$nl}";
        $views_ok = false;
    }
}

echo "{$nl}Views: " . count($found_views) . "/" . count($expected_views) . " found";
echo ($views_ok ? " {$green}✓{$reset}" : " {$red}✗{$reset}") . "{$nl}{$nl}";
if (!$views_ok) $all_passed = false;

// ============================================================================
// Step 4: Check Stored Procedures
// ============================================================================

echo "{$bold_start}[4] Stored Procedures Check{$bold_end}{$nl}";

$expected_procs = [
    'sp_GetNextAdvisoryNumber',
    'sp_LogTmiEvent',
    'sp_ExpireOldEntries',
    'sp_GetActivePublicRoutes'
];

$sql = "SELECT name FROM sys.procedures WHERE is_ms_shipped = 0 ORDER BY name";
$result = sqlsrv_query($conn_tmi, $sql);

$found_procs = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $found_procs[] = $row['name'];
    }
}

$procs_ok = true;
foreach ($expected_procs as $proc) {
    if (in_array($proc, $found_procs)) {
        echo "  {$green}✓{$reset} {$proc}{$nl}";
    } else {
        echo "  {$red}✗{$reset} {$proc} - MISSING{$nl}";
        $procs_ok = false;
    }
}

echo "{$nl}Procedures: " . count($found_procs) . "/" . count($expected_procs) . " found";
echo ($procs_ok ? " {$green}✓{$reset}" : " {$red}✗{$reset}") . "{$nl}{$nl}";
if (!$procs_ok) $all_passed = false;

// ============================================================================
// Step 5: Check Indexes (sample)
// ============================================================================

echo "{$bold_start}[5] Indexes Check (critical){$bold_end}{$nl}";

$sql = "SELECT COUNT(*) as cnt FROM sys.indexes i 
        JOIN sys.tables t ON i.object_id = t.object_id 
        WHERE i.name IS NOT NULL AND t.is_ms_shipped = 0 AND i.type > 0";
$result = sqlsrv_query($conn_tmi, $sql);
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$index_count = $row['cnt'];

// Check critical indexes
$critical_indexes = [
    'IX_entries_status',
    'IX_entries_active',
    'IX_programs_active',
    'IX_slots_program_time',
    'IX_advisories_active',
    'IX_events_time'
];

$sql = "SELECT i.name FROM sys.indexes i 
        JOIN sys.tables t ON i.object_id = t.object_id 
        WHERE i.name IS NOT NULL AND t.is_ms_shipped = 0";
$result = sqlsrv_query($conn_tmi, $sql);

$found_indexes = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $found_indexes[] = $row['name'];
    }
}

$indexes_ok = true;
foreach ($critical_indexes as $idx) {
    if (in_array($idx, $found_indexes)) {
        echo "  {$green}✓{$reset} {$idx}{$nl}";
    } else {
        echo "  {$red}✗{$reset} {$idx} - MISSING{$nl}";
        $indexes_ok = false;
    }
}

echo "{$nl}Total indexes found: {$index_count}";
echo ($indexes_ok ? " {$green}✓{$reset}" : " {$yellow}⚠{$reset}") . "{$nl}{$nl}";

// ============================================================================
// Step 6: Test Basic Operations
// ============================================================================

echo "{$bold_start}[6] Basic Operations Test{$bold_end}{$nl}";

// Test advisory number generation
echo "  Testing sp_GetNextAdvisoryNumber...{$nl}";
$sql = "DECLARE @num NVARCHAR(16); EXEC sp_GetNextAdvisoryNumber @next_number = @num OUTPUT; SELECT @num AS adv_num;";
$result = sqlsrv_query($conn_tmi, $sql);
if ($result && ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC))) {
    echo "    {$green}✓{$reset} Generated: {$row['adv_num']}{$nl}";
} else {
    echo "    {$red}✗{$reset} Failed to generate advisory number{$nl}";
    if (sqlsrv_errors()) {
        foreach (sqlsrv_errors() as $e) {
            echo "      Error: " . $e['message'] . "{$nl}";
        }
    }
    $all_passed = false;
}

// Test event logging
echo "  Testing sp_LogTmiEvent...{$nl}";
$sql = "EXEC sp_LogTmiEvent 
    @entity_type = 'TEST', 
    @entity_id = 0, 
    @event_type = 'VERIFICATION', 
    @event_detail = 'Deployment test',
    @source_type = 'SCRIPT',
    @source_id = 'verify_deployment.php'";
$result = sqlsrv_query($conn_tmi, $sql);
if ($result) {
    echo "    {$green}✓{$reset} Event logged successfully{$nl}";
} else {
    echo "    {$red}✗{$reset} Failed to log event{$nl}";
    $all_passed = false;
}

// Test expire procedure (should run without error even with no data)
echo "  Testing sp_ExpireOldEntries...{$nl}";
$sql = "EXEC sp_ExpireOldEntries";
$result = sqlsrv_query($conn_tmi, $sql);
if ($result) {
    echo "    {$green}✓{$reset} Expire procedure ran successfully{$nl}";
} else {
    echo "    {$red}✗{$reset} Failed to run expire procedure{$nl}";
    $all_passed = false;
}

// Test views (should return empty but not error)
echo "  Testing views...{$nl}";
$views_to_test = ['vw_tmi_active_entries', 'vw_tmi_active_programs', 'vw_tmi_active_advisories'];
foreach ($views_to_test as $view) {
    $sql = "SELECT COUNT(*) as cnt FROM {$view}";
    $result = sqlsrv_query($conn_tmi, $sql);
    if ($result) {
        $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
        echo "    {$green}✓{$reset} {$view}: {$row['cnt']} rows{$nl}";
    } else {
        echo "    {$red}✗{$reset} {$view}: query failed{$nl}";
        $all_passed = false;
    }
}

echo "{$nl}";

// ============================================================================
// Step 7: Table Column Counts
// ============================================================================

echo "{$bold_start}[7] Table Column Counts{$bold_end}{$nl}";

$expected_columns = [
    'tmi_entries' => 35,
    'tmi_programs' => 47,
    'tmi_advisories' => 40,
    'tmi_slots' => 22,
    'tmi_reroutes' => 45,
    'tmi_reroute_flights' => 30,
    'tmi_reroute_compliance_log' => 9,
    'tmi_public_routes' => 21,
    'tmi_events' => 18,
    'tmi_advisory_sequences' => 2
];

$sql = "SELECT t.name AS table_name, COUNT(c.column_id) AS column_count
        FROM sys.tables t
        JOIN sys.columns c ON t.object_id = c.object_id
        WHERE t.is_ms_shipped = 0
        GROUP BY t.name
        ORDER BY t.name";
$result = sqlsrv_query($conn_tmi, $sql);

$columns_ok = true;
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $table = $row['table_name'];
        $count = $row['column_count'];
        $expected = $expected_columns[$table] ?? '?';
        
        if ($count == $expected) {
            echo "  {$green}✓{$reset} {$table}: {$count} columns{$nl}";
        } else {
            echo "  {$yellow}⚠{$reset} {$table}: {$count} columns (expected {$expected}){$nl}";
        }
    }
}

echo "{$nl}";

// ============================================================================
// Summary
// ============================================================================

echo "{$bold_start}========================================{$bold_end}{$nl}";
echo "{$bold_start}SUMMARY{$bold_end}{$nl}";
echo "{$bold_start}========================================{$bold_end}{$nl}";

if ($all_passed && $tables_ok && $views_ok && $procs_ok) {
    echo "{$nl}{$green}✓ ALL CHECKS PASSED{$reset}{$nl}";
    echo "{$nl}VATSIM_TMI deployment verified successfully!{$nl}";
    echo "The database is ready for use.{$nl}";
} else {
    echo "{$nl}{$red}✗ SOME CHECKS FAILED{$reset}{$nl}";
    echo "{$nl}Please review the errors above and run the migration script:{$nl}";
    echo "  database/migrations/tmi/001_tmi_core_schema_azure_sql.sql{$nl}";
}

echo "{$nl}";

if (!$is_cli) {
    echo "</pre></body></html>";
}

// Cleanup test event
$sql = "DELETE FROM tmi_events WHERE entity_type = 'TEST' AND source_id = 'verify_deployment.php'";
sqlsrv_query($conn_tmi, $sql);

exit($all_passed ? 0 : 1);
