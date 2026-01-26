<?php
/**
 * Migration 014: Migrate NTML to TMI_PROGRAMS
 * 
 * This script migrates Ground Stop programs from VATSIM_ADL.dbo.ntml
 * to VATSIM_TMI.dbo.tmi_programs.
 * 
 * Run from command line: php 014_migrate_ntml_to_tmi_programs.php
 * Or via browser (not recommended for large datasets)
 * 
 * Date: 2026-01-26
 */

// Load configuration
require_once(__DIR__ . '/../../../load/config.php');

echo "=== Migration 014: Migrate NTML to TMI_PROGRAMS ===\n\n";

// ============================================================================
// Connect to both databases
// ============================================================================

echo "Connecting to databases...\n";

// VATSIM_ADL connection (source)
$adl_conn_info = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "ConnectionPooling" => 0
];
$conn_adl = sqlsrv_connect(ADL_SQL_HOST, $adl_conn_info);

if ($conn_adl === false) {
    die("ERROR: Could not connect to VATSIM_ADL\n" . print_r(sqlsrv_errors(), true));
}
echo "  Connected to VATSIM_ADL\n";

// VATSIM_TMI connection (target)
$tmi_conn_info = [
    "Database" => TMI_SQL_DATABASE,
    "UID" => TMI_SQL_USERNAME,
    "PWD" => TMI_SQL_PASSWORD,
    "ConnectionPooling" => 0
];
$conn_tmi = sqlsrv_connect(TMI_SQL_HOST, $tmi_conn_info);

if ($conn_tmi === false) {
    die("ERROR: Could not connect to VATSIM_TMI\n" . print_r(sqlsrv_errors(), true));
}
echo "  Connected to VATSIM_TMI\n\n";

// ============================================================================
// Part 0: Check source data
// ============================================================================

echo "Part 0: Checking source data...\n";

$result = sqlsrv_query($conn_adl, "SELECT COUNT(*) AS cnt FROM dbo.ntml");
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$source_count = $row['cnt'];
echo "  Source records in VATSIM_ADL.dbo.ntml: {$source_count}\n";

$result = sqlsrv_query($conn_tmi, "SELECT COUNT(*) AS cnt FROM dbo.tmi_programs");
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$target_count = $row['cnt'];
echo "  Existing records in VATSIM_TMI.dbo.tmi_programs: {$target_count}\n\n";

// ============================================================================
// Part 1: Clear test data (program_id <= 10)
// ============================================================================

echo "Part 1: Clearing test data (program_id <= 10)...\n";

$result = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_programs WHERE program_id <= 10");
if ($result === false) {
    echo "  WARNING: Could not delete test data: " . print_r(sqlsrv_errors(), true) . "\n";
} else {
    $deleted = sqlsrv_rows_affected($result);
    echo "  Deleted {$deleted} test records\n";
}
echo "\n";

// ============================================================================
// Part 2: Get existing program_guids in target (to avoid duplicates)
// ============================================================================

echo "Part 2: Checking for existing records...\n";

$existing_guids = [];
$result = sqlsrv_query($conn_tmi, "SELECT program_guid FROM dbo.tmi_programs");
while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $existing_guids[$row['program_guid']] = true;
}
echo "  Found " . count($existing_guids) . " existing program GUIDs\n\n";

// ============================================================================
// Part 3: Fetch all records from source
// ============================================================================

echo "Part 3: Fetching source records...\n";

$sql = "SELECT * FROM dbo.ntml ORDER BY program_id";
$result = sqlsrv_query($conn_adl, $sql);

if ($result === false) {
    die("ERROR: Could not fetch from ntml\n" . print_r(sqlsrv_errors(), true));
}

$records = [];
while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $records[] = $row;
}
echo "  Fetched " . count($records) . " records from ntml\n\n";

// ============================================================================
// Part 4: Insert into target with IDENTITY_INSERT
// ============================================================================

echo "Part 4: Migrating records...\n";

// Enable IDENTITY_INSERT
$result = sqlsrv_query($conn_tmi, "SET IDENTITY_INSERT dbo.tmi_programs ON");
if ($result === false) {
    die("ERROR: Could not enable IDENTITY_INSERT\n" . print_r(sqlsrv_errors(), true));
}

$inserted = 0;
$skipped = 0;
$errors = 0;

foreach ($records as $r) {
    // Skip if already exists
    if (isset($existing_guids[$r['program_guid']])) {
        $skipped++;
        continue;
    }
    
    // Convert DateTime objects to strings
    foreach ($r as $k => $v) {
        if ($v instanceof DateTime) {
            $r[$k] = $v->format('Y-m-d H:i:s');
        }
    }
    
    $sql = "
        INSERT INTO dbo.tmi_programs (
            program_id, program_guid, ctl_element, element_type, program_type,
            program_name, adv_number, start_utc, end_utc, cumulative_start,
            cumulative_end, status, is_proposed, is_active, program_rate,
            reserve_rate, delay_limit_min, target_delay_mult, rates_hourly_json,
            reserve_hourly_json, scope_type, scope_tier, scope_distance_nm,
            scope_json, exemptions_json, exempt_airborne, exempt_within_min,
            flt_incl_carrier, flt_incl_type, flt_incl_fix, impacting_condition,
            cause_text, comments, prob_extension, revision_number, parent_program_id,
            total_flights, controlled_flights, exempt_flights, airborne_flights,
            avg_delay_min, max_delay_min, total_delay_min, created_by, created_at,
            updated_at, activated_by, activated_at, purged_by, purged_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
    ";
    
    $params = [
        $r['program_id'],
        $r['program_guid'],
        $r['ctl_element'],
        $r['element_type'],
        $r['program_type'],
        $r['program_name'],
        $r['adv_number'],
        $r['start_utc'],
        $r['end_utc'],
        $r['cumulative_start'],
        $r['cumulative_end'],
        $r['status'],
        $r['is_proposed'],
        $r['is_active'],
        $r['program_rate'],
        $r['reserve_rate'],
        $r['delay_limit_min'],
        $r['target_delay_mult'],
        $r['rates_hourly_json'],
        $r['reserve_hourly_json'],
        $r['scope_type'] ?? null,
        $r['scope_tier'] ?? null,
        $r['scope_distance_nm'] ?? null,
        $r['scope_json'] ?? null,
        $r['exemptions_json'] ?? null,
        $r['exempt_airborne'] ?? 1,
        $r['exempt_within_min'] ?? null,
        $r['flt_incl_carrier'] ?? null,
        $r['flt_incl_type'] ?? null,
        $r['flt_incl_fix'] ?? null,
        $r['impacting_condition'],
        $r['cause_text'],
        $r['comments'] ?? null,
        $r['prob_extension'] ?? null,
        $r['revision_number'] ?? 0,
        $r['parent_program_id'] ?? null,
        $r['total_flights'],
        $r['controlled_flights'],
        $r['exempt_flights'],
        $r['airborne_flights'] ?? null,
        $r['avg_delay_min'],
        $r['max_delay_min'],
        $r['total_delay_min'],
        $r['created_by'],
        $r['created_utc'],      // -> created_at
        $r['modified_utc'],     // -> updated_at
        $r['activated_by'] ?? null,
        $r['activated_utc'] ?? null,  // -> activated_at
        $r['purged_by'] ?? null,
        $r['purged_utc'] ?? null      // -> purged_at
    ];
    
    $stmt = sqlsrv_query($conn_tmi, $sql, $params);
    
    if ($stmt === false) {
        $errors++;
        if ($errors <= 5) {
            echo "  ERROR on program_id {$r['program_id']}: " . print_r(sqlsrv_errors(), true) . "\n";
        }
    } else {
        $inserted++;
        if ($inserted % 50 == 0) {
            echo "  Inserted {$inserted} records...\n";
        }
    }
}

// Disable IDENTITY_INSERT
sqlsrv_query($conn_tmi, "SET IDENTITY_INSERT dbo.tmi_programs OFF");

echo "\n  Migration results:\n";
echo "    Inserted: {$inserted}\n";
echo "    Skipped (duplicates): {$skipped}\n";
echo "    Errors: {$errors}\n\n";

// ============================================================================
// Part 5: Reseed identity
// ============================================================================

echo "Part 5: Reseeding identity...\n";

$result = sqlsrv_query($conn_tmi, "SELECT MAX(program_id) AS max_id FROM dbo.tmi_programs");
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$max_id = $row['max_id'] ?? 0;

$result = sqlsrv_query($conn_tmi, "DBCC CHECKIDENT ('dbo.tmi_programs', RESEED, {$max_id})");
if ($result === false) {
    echo "  WARNING: Could not reseed identity\n";
} else {
    echo "  Identity reseeded to {$max_id}\n";
}
echo "\n";

// ============================================================================
// Part 6: Verify migration
// ============================================================================

echo "Part 6: Verification...\n";

$result = sqlsrv_query($conn_tmi, "SELECT COUNT(*) AS cnt FROM dbo.tmi_programs");
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$final_count = $row['cnt'];

echo "  Final records in tmi_programs: {$final_count}\n";
echo "  Source records in ntml: {$source_count}\n";

if ($final_count >= $source_count) {
    echo "  STATUS: Migration SUCCESSFUL\n";
} else {
    echo "  STATUS: WARNING - Record count mismatch\n";
}

echo "\n=== Migration 014 Complete ===\n";
echo "\nNext steps:\n";
echo "  1. Test /api/gdt/ endpoints\n";
echo "  2. Update gdt.js to use /api/gdt/ endpoints\n";
echo "  3. Consider deprecating old /api/tmi/gs/ endpoints\n";

// Close connections
sqlsrv_close($conn_adl);
sqlsrv_close($conn_tmi);
