<?php
/**
 * TMI CRUD Test Script
 * 
 * Creates test data across all TMI tables to verify API operations.
 * Run this script to populate the database with sample data.
 * 
 * Usage: php scripts/tmi/test_crud.php
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

echo "=== TMI CRUD Test Script ===\n";
echo "Date: " . date('Y-m-d H:i:s T') . "\n\n";

// Check connection
if (!$conn_tmi) {
    die("ERROR: Cannot connect to VATSIM_TMI database\n");
}
echo "✓ Connected to VATSIM_TMI\n\n";

// Track created IDs for cleanup
$created = [
    'entries' => [],
    'advisories' => [],
    'programs' => [],
    'reroutes' => [],
    'public_routes' => []
];

// ============================================
// TEST 1: Create TMI Entry (NTML)
// ============================================
echo "--- Test 1: TMI Entries ---\n";

$entry_data = [
    'determinant_code' => '05B01',
    'protocol_type' => 5,
    'entry_type' => 'MIT',
    'ctl_element' => 'KJFK',
    'element_type' => 'APT',
    'requesting_facility' => 'N90',
    'providing_facility' => 'ZNY',
    'restriction_value' => 10,
    'restriction_unit' => 'MIT',
    'condition_text' => 'KJFK ARR via LENDY',
    'reason_code' => 'VOLUME',
    'reason_detail' => 'High arrival demand',
    'status' => 'ACTIVE',
    'source_type' => 'TEST',
    'created_by' => 'test_script',
    'created_by_name' => 'TMI Test Script'
];

$entry_sql = "INSERT INTO dbo.tmi_entries 
    (determinant_code, protocol_type, entry_type, ctl_element, element_type,
     requesting_facility, providing_facility, restriction_value, restriction_unit,
     condition_text, reason_code, reason_detail, status, source_type,
     created_by, created_by_name)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS id;";

$entry_params = [
    $entry_data['determinant_code'],
    $entry_data['protocol_type'],
    $entry_data['entry_type'],
    $entry_data['ctl_element'],
    $entry_data['element_type'],
    $entry_data['requesting_facility'],
    $entry_data['providing_facility'],
    $entry_data['restriction_value'],
    $entry_data['restriction_unit'],
    $entry_data['condition_text'],
    $entry_data['reason_code'],
    $entry_data['reason_detail'],
    $entry_data['status'],
    $entry_data['source_type'],
    $entry_data['created_by'],
    $entry_data['created_by_name']
];

$result = sqlsrv_query($conn_tmi, $entry_sql, $entry_params);
if ($result) {
    sqlsrv_next_result($result);
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $entry_id = $row['id'];
    $created['entries'][] = $entry_id;
    echo "✓ Created entry ID: $entry_id\n";
    
    // Verify read
    $verify_sql = "SELECT * FROM dbo.tmi_entries WHERE entry_id = ?";
    $verify = sqlsrv_query($conn_tmi, $verify_sql, [$entry_id]);
    $entry = sqlsrv_fetch_array($verify, SQLSRV_FETCH_ASSOC);
    echo "  - Entry type: {$entry['entry_type']}\n";
    echo "  - CTL element: {$entry['ctl_element']}\n";
    echo "  - Status: {$entry['status']}\n";
} else {
    echo "✗ Failed to create entry\n";
    print_r(sqlsrv_errors());
}

// ============================================
// TEST 2: Create TMI Program (GS/GDP)
// ============================================
echo "\n--- Test 2: TMI Programs ---\n";

$program_data = [
    'ctl_element' => 'KEWR',
    'element_type' => 'APT',
    'program_type' => 'GS',
    'program_name' => 'Test Ground Stop',
    'adv_number' => 'TEST 001',
    'start_utc' => date('Y-m-d H:i:s'),
    'end_utc' => date('Y-m-d H:i:s', strtotime('+2 hours')),
    'status' => 'PROPOSED',
    'is_proposed' => 1,
    'is_active' => 0,
    'impacting_condition' => 'WEATHER',
    'cause_text' => 'Test: Low visibility conditions',
    'source_type' => 'TEST',
    'created_by' => 'test_script'
];

$program_sql = "INSERT INTO dbo.tmi_programs 
    (ctl_element, element_type, program_type, program_name, adv_number,
     start_utc, end_utc, status, is_proposed, is_active,
     impacting_condition, cause_text, source_type, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS id;";

$program_params = [
    $program_data['ctl_element'],
    $program_data['element_type'],
    $program_data['program_type'],
    $program_data['program_name'],
    $program_data['adv_number'],
    $program_data['start_utc'],
    $program_data['end_utc'],
    $program_data['status'],
    $program_data['is_proposed'],
    $program_data['is_active'],
    $program_data['impacting_condition'],
    $program_data['cause_text'],
    $program_data['source_type'],
    $program_data['created_by']
];

$result = sqlsrv_query($conn_tmi, $program_sql, $program_params);
if ($result) {
    sqlsrv_next_result($result);
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $program_id = $row['id'];
    $created['programs'][] = $program_id;
    echo "✓ Created program ID: $program_id\n";
    
    // Verify
    $verify = sqlsrv_query($conn_tmi, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$program_id]);
    $program = sqlsrv_fetch_array($verify, SQLSRV_FETCH_ASSOC);
    echo "  - Program type: {$program['program_type']}\n";
    echo "  - Airport: {$program['ctl_element']}\n";
    echo "  - Status: {$program['status']}\n";
} else {
    echo "✗ Failed to create program\n";
    print_r(sqlsrv_errors());
}

// ============================================
// TEST 3: Create TMI Advisory
// ============================================
echo "\n--- Test 3: TMI Advisories ---\n";

// Get next advisory number
$adv_num_result = sqlsrv_query($conn_tmi, "DECLARE @num NVARCHAR(16); EXEC sp_GetNextAdvisoryNumber @next_number = @num OUTPUT; SELECT @num AS adv_num;");
$adv_num = 'ADVZY 001';
if ($adv_num_result && ($row = sqlsrv_fetch_array($adv_num_result, SQLSRV_FETCH_ASSOC))) {
    $adv_num = $row['adv_num'];
}
echo "  Generated advisory number: $adv_num\n";

$advisory_data = [
    'advisory_number' => $adv_num,
    'advisory_type' => 'GS',
    'ctl_element' => 'KEWR',
    'element_type' => 'APT',
    'program_id' => $program_id ?? null,
    'effective_from' => date('Y-m-d H:i:s'),
    'effective_until' => date('Y-m-d H:i:s', strtotime('+2 hours')),
    'subject' => 'KEWR GROUND STOP - TEST',
    'body_text' => 'THIS IS A TEST ADVISORY. Ground Stop issued for Newark Liberty International Airport due to low visibility conditions. Duration: 2 hours.',
    'reason_code' => 'WEATHER',
    'reason_detail' => 'Low ceiling and visibility',
    'status' => 'ACTIVE',
    'is_proposed' => 0,
    'source_type' => 'TEST',
    'created_by' => 'test_script',
    'created_by_name' => 'TMI Test Script'
];

$advisory_sql = "INSERT INTO dbo.tmi_advisories 
    (advisory_number, advisory_type, ctl_element, element_type, program_id,
     effective_from, effective_until, subject, body_text, reason_code,
     reason_detail, status, is_proposed, source_type, created_by, created_by_name)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS id;";

$advisory_params = [
    $advisory_data['advisory_number'],
    $advisory_data['advisory_type'],
    $advisory_data['ctl_element'],
    $advisory_data['element_type'],
    $advisory_data['program_id'],
    $advisory_data['effective_from'],
    $advisory_data['effective_until'],
    $advisory_data['subject'],
    $advisory_data['body_text'],
    $advisory_data['reason_code'],
    $advisory_data['reason_detail'],
    $advisory_data['status'],
    $advisory_data['is_proposed'],
    $advisory_data['source_type'],
    $advisory_data['created_by'],
    $advisory_data['created_by_name']
];

$result = sqlsrv_query($conn_tmi, $advisory_sql, $advisory_params);
if ($result) {
    sqlsrv_next_result($result);
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $advisory_id = $row['id'];
    $created['advisories'][] = $advisory_id;
    echo "✓ Created advisory ID: $advisory_id\n";
    
    // Verify
    $verify = sqlsrv_query($conn_tmi, "SELECT * FROM dbo.tmi_advisories WHERE advisory_id = ?", [$advisory_id]);
    $advisory = sqlsrv_fetch_array($verify, SQLSRV_FETCH_ASSOC);
    echo "  - Advisory number: {$advisory['advisory_number']}\n";
    echo "  - Type: {$advisory['advisory_type']}\n";
    echo "  - Subject: {$advisory['subject']}\n";
} else {
    echo "✗ Failed to create advisory\n";
    print_r(sqlsrv_errors());
}

// ============================================
// TEST 4: Create TMI Reroute
// ============================================
echo "\n--- Test 4: TMI Reroutes ---\n";

$reroute_data = [
    'name' => 'Test ZNY East Reroute',
    'status' => 2, // ACTIVE
    'adv_number' => 'TEST RTE 001',
    'start_utc' => date('Y-m-d H:i:s'),
    'end_utc' => date('Y-m-d H:i:s', strtotime('+4 hours')),
    'time_basis' => 'ETD',
    'protected_segment' => 'J75 BIGGY J36',
    'protected_fixes' => '["BIGGY","BRIGS","RANEY"]',
    'avoid_fixes' => '["LENDY","PARCH"]',
    'route_type' => 'FULL',
    'origin_centers' => '["ZBW","ZOB"]',
    'dest_airports' => '["KJFK","KEWR","KLGA"]',
    'include_ac_cat' => 'ALL',
    'weight_class' => 'ALL',
    'airborne_filter' => 'NOT_AIRBORNE',
    'impacting_condition' => 'WEATHER',
    'comments' => 'Test reroute for CRUD verification',
    'color' => '#e74c3c',
    'line_weight' => 3,
    'line_style' => 'solid',
    'source_type' => 'TEST',
    'created_by' => 'test_script'
];

$reroute_sql = "INSERT INTO dbo.tmi_reroutes 
    (name, status, adv_number, start_utc, end_utc, time_basis,
     protected_segment, protected_fixes, avoid_fixes, route_type,
     origin_centers, dest_airports, include_ac_cat, weight_class,
     airborne_filter, impacting_condition, comments,
     color, line_weight, line_style, source_type, created_by, activated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS id;";

$reroute_params = [
    $reroute_data['name'],
    $reroute_data['status'],
    $reroute_data['adv_number'],
    $reroute_data['start_utc'],
    $reroute_data['end_utc'],
    $reroute_data['time_basis'],
    $reroute_data['protected_segment'],
    $reroute_data['protected_fixes'],
    $reroute_data['avoid_fixes'],
    $reroute_data['route_type'],
    $reroute_data['origin_centers'],
    $reroute_data['dest_airports'],
    $reroute_data['include_ac_cat'],
    $reroute_data['weight_class'],
    $reroute_data['airborne_filter'],
    $reroute_data['impacting_condition'],
    $reroute_data['comments'],
    $reroute_data['color'],
    $reroute_data['line_weight'],
    $reroute_data['line_style'],
    $reroute_data['source_type'],
    $reroute_data['created_by'],
    date('Y-m-d H:i:s') // activated_at
];

$result = sqlsrv_query($conn_tmi, $reroute_sql, $reroute_params);
if ($result) {
    sqlsrv_next_result($result);
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $reroute_id = $row['id'];
    $created['reroutes'][] = $reroute_id;
    echo "✓ Created reroute ID: $reroute_id\n";
    
    // Verify
    $verify = sqlsrv_query($conn_tmi, "SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?", [$reroute_id]);
    $reroute = sqlsrv_fetch_array($verify, SQLSRV_FETCH_ASSOC);
    echo "  - Name: {$reroute['name']}\n";
    echo "  - Status: {$reroute['status']}\n";
    echo "  - Protected segment: {$reroute['protected_segment']}\n";
} else {
    echo "✗ Failed to create reroute\n";
    print_r(sqlsrv_errors());
}

// ============================================
// TEST 5: Create TMI Public Route
// ============================================
echo "\n--- Test 5: TMI Public Routes ---\n";

$route_data = [
    'name' => 'Test SWAP Route',
    'status' => 1, // Active
    'adv_number' => 'TEST RTE 001',
    'reroute_id' => $reroute_id ?? null,
    'route_string' => 'KJFK..BIGGY.J75.BRIGS.J36.RANEY..DESTINATION',
    'advisory_text' => 'Test SWAP route for traffic management',
    'color' => '#3498db',
    'line_weight' => 4,
    'line_style' => 'dashed',
    'valid_start_utc' => date('Y-m-d H:i:s'),
    'valid_end_utc' => date('Y-m-d H:i:s', strtotime('+4 hours')),
    'constrained_area' => 'ZNY',
    'reason' => 'TEST/TRAFFIC MANAGEMENT',
    'facilities' => 'ZBW/ZNY/ZDC',
    'created_by' => 'test_script'
];

$route_sql = "INSERT INTO dbo.tmi_public_routes 
    (name, status, adv_number, reroute_id, route_string, advisory_text,
     color, line_weight, line_style, valid_start_utc, valid_end_utc,
     constrained_area, reason, facilities, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS id;";

$route_params = [
    $route_data['name'],
    $route_data['status'],
    $route_data['adv_number'],
    $route_data['reroute_id'],
    $route_data['route_string'],
    $route_data['advisory_text'],
    $route_data['color'],
    $route_data['line_weight'],
    $route_data['line_style'],
    $route_data['valid_start_utc'],
    $route_data['valid_end_utc'],
    $route_data['constrained_area'],
    $route_data['reason'],
    $route_data['facilities'],
    $route_data['created_by']
];

$result = sqlsrv_query($conn_tmi, $route_sql, $route_params);
if ($result) {
    sqlsrv_next_result($result);
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $route_id = $row['id'];
    $created['public_routes'][] = $route_id;
    echo "✓ Created public route ID: $route_id\n";
    
    // Verify
    $verify = sqlsrv_query($conn_tmi, "SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$route_id]);
    $route = sqlsrv_fetch_array($verify, SQLSRV_FETCH_ASSOC);
    echo "  - Name: {$route['name']}\n";
    echo "  - Route: {$route['route_string']}\n";
    echo "  - Color: {$route['color']}\n";
} else {
    echo "✗ Failed to create public route\n";
    print_r(sqlsrv_errors());
}

// ============================================
// TEST 6: Test Views
// ============================================
echo "\n--- Test 6: Views ---\n";

$views = [
    'vw_tmi_active_entries' => 'Active Entries',
    'vw_tmi_active_advisories' => 'Active Advisories', 
    'vw_tmi_active_programs' => 'Active Programs',
    'vw_tmi_active_reroutes' => 'Active Reroutes',
    'vw_tmi_active_public_routes' => 'Active Public Routes',
    'vw_tmi_recent_entries' => 'Recent Entries'
];

foreach ($views as $view => $desc) {
    $result = sqlsrv_query($conn_tmi, "SELECT COUNT(*) as cnt FROM dbo.$view");
    if ($result) {
        $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
        echo "✓ $desc ($view): {$row['cnt']} records\n";
    } else {
        echo "✗ Failed to query $view\n";
    }
}

// ============================================
// TEST 7: Test Event Logging
// ============================================
echo "\n--- Test 7: Event Logging ---\n";

$event_sql = "EXEC sp_LogTmiEvent 
    @entity_type = 'ENTRY',
    @entity_id = ?,
    @event_type = 'TEST',
    @event_detail = 'Test event from CRUD script',
    @source_type = 'TEST',
    @actor_id = 'test_script',
    @actor_name = 'TMI Test Script'";

if (isset($entry_id)) {
    $result = sqlsrv_query($conn_tmi, $event_sql, [$entry_id]);
    if ($result !== false) {
        echo "✓ Logged test event for entry $entry_id\n";
    } else {
        echo "✗ Failed to log event\n";
        print_r(sqlsrv_errors());
    }
}

// Check events table
$events = sqlsrv_query($conn_tmi, "SELECT TOP 5 * FROM dbo.tmi_events ORDER BY event_utc DESC");
$event_count = 0;
while ($row = sqlsrv_fetch_array($events, SQLSRV_FETCH_ASSOC)) {
    $event_count++;
}
echo "  Latest events in log: $event_count\n";

// ============================================
// SUMMARY
// ============================================
echo "\n=== Test Summary ===\n";
echo "Entries created: " . count($created['entries']) . "\n";
echo "Programs created: " . count($created['programs']) . "\n";
echo "Advisories created: " . count($created['advisories']) . "\n";
echo "Reroutes created: " . count($created['reroutes']) . "\n";
echo "Public Routes created: " . count($created['public_routes']) . "\n";

// Save created IDs for cleanup
$cleanup_file = __DIR__ . '/test_data_ids.json';
file_put_contents($cleanup_file, json_encode($created, JSON_PRETTY_PRINT));
echo "\nCreated IDs saved to: $cleanup_file\n";

echo "\n=== Test Complete ===\n";
echo "To test APIs, try:\n";
echo "  curl https://perti.vatcscc.org/api/tmi/active.php\n";
echo "  curl https://perti.vatcscc.org/api/tmi/entries.php?active_only=1\n";
echo "  curl https://perti.vatcscc.org/api/tmi/reroutes.php?active_only=1\n";
