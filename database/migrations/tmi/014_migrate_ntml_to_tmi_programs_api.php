<?php
/**
 * Migration 014: Migrate NTML to TMI_PROGRAMS
 * 
 * This script migrates Ground Stop programs from VATSIM_ADL.dbo.ntml
 * to VATSIM_TMI.dbo.tmi_programs via web endpoint.
 * 
 * Access: GET/POST /database/migrations/tmi/014_migrate_ntml_to_tmi_programs_api.php?run=1
 * 
 * Date: 2026-01-26
 */

header('Content-Type: application/json; charset=utf-8');

// Load configuration
require_once(__DIR__ . '/../../../load/config.php');
require_once(__DIR__ . '/../../../load/connect.php');

// Check for run parameter
if (!isset($_GET['run']) || $_GET['run'] !== '1') {
    echo json_encode([
        'status' => 'info',
        'message' => 'Migration 014: Migrate NTML to TMI_PROGRAMS',
        'usage' => 'Add ?run=1 to execute the migration',
        'warning' => 'This will copy all programs from ntml to tmi_programs'
    ], JSON_PRETTY_PRINT);
    exit;
}

$results = [
    'status' => 'running',
    'steps' => []
];

// Step 0: Check connections
$results['steps'][] = ['step' => 0, 'action' => 'Checking connections'];

if (!$conn_adl) {
    $results['status'] = 'error';
    $results['message'] = 'VATSIM_ADL connection not available';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

if (!$conn_tmi) {
    $results['status'] = 'error';
    $results['message'] = 'VATSIM_TMI connection not available';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['steps'][] = ['step' => 0, 'result' => 'Both connections OK'];

// Step 1: Count source records
$stmt = sqlsrv_query($conn_adl, "SELECT COUNT(*) AS cnt FROM dbo.ntml");
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$source_count = $row['cnt'];
$results['source_count'] = $source_count;
$results['steps'][] = ['step' => 1, 'action' => 'Source count', 'count' => $source_count];

// Step 2: Count target records before
$stmt = sqlsrv_query($conn_tmi, "SELECT COUNT(*) AS cnt FROM dbo.tmi_programs");
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$target_before = $row['cnt'];
$results['target_before'] = $target_before;
$results['steps'][] = ['step' => 2, 'action' => 'Target count before', 'count' => $target_before];

// Step 3: Clear test data (program_id <= 10)
$stmt = sqlsrv_query($conn_tmi, "DELETE FROM dbo.tmi_programs WHERE program_id <= 10");
$deleted = sqlsrv_rows_affected($stmt);
$results['steps'][] = ['step' => 3, 'action' => 'Cleared test data', 'deleted' => $deleted];

// Step 4: Get existing GUIDs to avoid duplicates
$existing_guids = [];
$stmt = sqlsrv_query($conn_tmi, "SELECT program_guid FROM dbo.tmi_programs");
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $existing_guids[strtoupper($row['program_guid'])] = true;
}
$results['steps'][] = ['step' => 4, 'action' => 'Existing GUIDs', 'count' => count($existing_guids)];

// Step 5: Fetch all source records
$stmt = sqlsrv_query($conn_adl, "SELECT * FROM dbo.ntml ORDER BY program_id");
$records = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Convert DateTime objects
    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) {
            $row[$k] = $v->format('Y-m-d H:i:s');
        }
    }
    $records[] = $row;
}
$results['steps'][] = ['step' => 5, 'action' => 'Fetched source records', 'count' => count($records)];

// Step 6: Enable IDENTITY_INSERT and insert records
sqlsrv_query($conn_tmi, "SET IDENTITY_INSERT dbo.tmi_programs ON");

$inserted = 0;
$skipped = 0;
$errors = 0;
$error_details = [];

foreach ($records as $r) {
    // Skip if already exists
    $guid = strtoupper($r['program_guid'] ?? '');
    if (isset($existing_guids[$guid])) {
        $skipped++;
        continue;
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
            $error_details[] = [
                'program_id' => $r['program_id'],
                'error' => sqlsrv_errors()
            ];
        }
    } else {
        $inserted++;
    }
}

// Disable IDENTITY_INSERT
sqlsrv_query($conn_tmi, "SET IDENTITY_INSERT dbo.tmi_programs OFF");

$results['steps'][] = [
    'step' => 6, 
    'action' => 'Insert records',
    'inserted' => $inserted,
    'skipped' => $skipped,
    'errors' => $errors
];

if (count($error_details) > 0) {
    $results['error_details'] = $error_details;
}

// Step 7: Reseed identity
$stmt = sqlsrv_query($conn_tmi, "SELECT MAX(program_id) AS max_id FROM dbo.tmi_programs");
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$max_id = $row['max_id'] ?? 0;

sqlsrv_query($conn_tmi, "DBCC CHECKIDENT ('dbo.tmi_programs', RESEED, {$max_id})");
$results['steps'][] = ['step' => 7, 'action' => 'Reseed identity', 'max_id' => $max_id];

// Step 8: Final count
$stmt = sqlsrv_query($conn_tmi, "SELECT COUNT(*) AS cnt FROM dbo.tmi_programs");
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$target_after = $row['cnt'];
$results['target_after'] = $target_after;
$results['steps'][] = ['step' => 8, 'action' => 'Final count', 'count' => $target_after];

// Summary
$results['status'] = ($errors == 0 && $target_after >= $source_count) ? 'success' : 'partial';
$results['summary'] = [
    'source_records' => $source_count,
    'inserted' => $inserted,
    'skipped' => $skipped,
    'errors' => $errors,
    'final_count' => $target_after
];

echo json_encode($results, JSON_PRETTY_PRINT);
