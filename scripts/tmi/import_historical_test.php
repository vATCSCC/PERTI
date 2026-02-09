<?php
/**
 * TMI Historical Import - Test Harness
 *
 * Validates the parser logic with sample Discord messages without database writes.
 * Run: php import_historical_test.php
 *
 * @package PERTI
 * @subpackage Scripts/TMI
 * @date 2026-02-09
 */

// We need perti_detect_element_type() from perti_constants.php
// But we don't need config.php (no DB). Load just the constants.
require_once __DIR__ . '/../../load/perti_constants.php';

// Include the import script's functions (source them by including the file
// in a way that skips the main execution). We'll source functions directly instead.
// Since import_historical.php has functions at global scope after the main block,
// we can't include it without triggering main logic. So we define test data here
// and post to it via dry_run.

echo "=== TMI Historical Import - Parser Tests ===\n\n";

// Sample Discord messages
$testEntries = [

    // 1. Ground Stop advisory
    '```
ATCSCC ADVZY 003 KJFK/ZNY 01/09/2026 CDM GROUND STOP

CTL ELEMENT...............: KJFK
ELEMENT TYPE..............: ARPT
ADL TIME..................: 091530
SCOPE - CENTERS...........: ZNY ZBW ZDC
IMPACTING CONDITION.......: WEATHER
PROBABILITY OF EXTENSION..: 75%

COMMENTS:
LOW IFR CONDITIONS WITH VISIBILITY BELOW MINIMUMS.
EXPECT DELAYS TO CONTINUE THROUGH 2100Z.

091530-092359
```',

    // 2. GDP advisory
    '```
ATCSCC ADVZY 005 KEWR/ZNY 01/09/2026 CDM GROUND DELAY PROGRAM

CTL ELEMENT...............: KEWR
ELEMENT TYPE..............: ARPT
ADL TIME..................: 091600
DELAY ASSIGNMENT MODE.....: RBS+
SCOPE - CENTERS...........: ZNY ZBW ZDC ZOB
SCOPE - TIERS.............: TIER 2
PROGRAM RATE..............: 30/HR
MAX DELAY.................: 120 MINS
IMPACTING CONDITION.......: WEATHER

COMMENTS:
THUNDERSTORMS IN THE AREA REDUCING ARRIVAL CAPACITY.

091600-100200
```',

    // 3. MIT restriction
    '```
ATCSCC ADVZY 007 ZNY 01/09/2026 MIT

FACILITY..................: ZNY
ADL TIME..................: 091700
RESTRICTION...............: 20 NM MIT
AT FIX....................: CAMRN
IMPACTING CONDITION.......: VOLUME

091700-092200
```',

    // 4. Reroute advisory
    '```
ATCSCC ADVZY 008 SOUTH1 01/09/2026 PLAYBOOK ROUTE

ROUTE DESIGNATOR..........: SOUTH1
ADL TIME..................: 091800
CONSTRAINED AREA..........: ZNY
TRAFFIC FROM..............: ZDC ZTL ZJX
TRAFFIC TO................: ZBW
IMPACTING CONDITION.......: WEATHER

ROUTE:
KDFW J29 SDF J24 ROD BRISS3

PARTICIPATING FACS........: ZFW ZME ZID ZOB ZBW

COMMENTS:
REROUTE AROUND ZNY CONVECTIVE ACTIVITY

091800-100600
```',

    // 5. General ATCSCC message
    '```
ATCSCC ADVZY 010 ATCSCC 01/09/2026 GENERAL MESSAGE

SUBJECT...................: SEVERE WEATHER OPERATIONS
ADL TIME..................: 091900

ALL FACILITIES BE ADVISED THAT CONVECTIVE ACTIVITY IS EXPECTED
TO IMPACT THE ZNY/ZBW/ZDC COMPLEX FROM 2000Z THROUGH 0400Z.
EXPECT INCREASED TMI ACTIVITY DURING THIS PERIOD.

END OF MESSAGE
```',

    // 6. Cancellation
    '```
ATCSCC ADVZY 012 KJFK/ZNY 01/09/2026 GS CANCELLATION

CANCEL ADVISORY...........: GS ADVZY 003
ADL TIME..................: 092100
EFFECTIVE IMMEDIATELY

REASON:
WEATHER CONDITIONS IMPROVING. VFR CONDITIONS EXPECTED BY 2200Z.

END OF MESSAGE
```',

    // 7. AFP advisory
    '```
ATCSCC ADVZY 015 FCA001 01/10/2026 CDM AIRSPACE FLOW PROGRAM

CTL ELEMENT...............: FCA001
ELEMENT TYPE..............: FCA
ADL TIME..................: 101400
DELAY ASSIGNMENT MODE.....: RBS+
PROGRAM RATE..............: 25/HR
SCOPE.....................: ZDC SECTOR 33/34/35
IMPACTING CONDITION.......: WEATHER

101400-102200
```',

    // 8. Staging prefix message (should be stripped)
    '🧪 **[STAGING]** ```
ATCSCC ADVZY 020 KLGA/ZNY 01/10/2026 CDM GROUND STOP

CTL ELEMENT...............: KLGA
ELEMENT TYPE..............: ARPT
ADL TIME..................: 101500
SCOPE - CENTERS...........: ZNY ZBW
IMPACTING CONDITION.......: EQUIPMENT

101500-101800
```',
];

// Build the test payload
$payload = json_encode([
    'entries' => $testEntries,
    'dry_run' => true
]);

// POST to the import script
echo "Testing " . count($testEntries) . " entries in dry_run mode...\n\n";

// Use the script via CLI by writing to a temp file and calling it
$tmpFile = tempnam(sys_get_temp_dir(), 'tmi_test_');
file_put_contents($tmpFile, $payload);

// Execute via CLI
$scriptPath = __DIR__ . '/import_historical.php';
$output = shell_exec("php \"{$scriptPath}\" < \"{$tmpFile}\" 2>&1");
unlink($tmpFile);

if (!$output) {
    echo "ERROR: No output from script\n";
    exit(1);
}

// Strip PHP warnings from output before JSON parsing
$jsonStart = strpos($output, '{');
if ($jsonStart !== false && $jsonStart > 0) {
    $output = substr($output, $jsonStart);
}
$result = json_decode($output, true);
if (!$result) {
    echo "ERROR: Invalid JSON output:\n{$output}\n";
    exit(1);
}

// Display results
echo "Dry Run: " . ($result['dry_run'] ? 'YES' : 'NO') . "\n";
echo "Total: {$result['counts']['total']}\n\n";

$expectedTypes = ['GS', 'GDP', 'MIT', 'REROUTE', 'ATCSCC', 'CNX', 'AFP', 'GS'];
$passed = 0;
$failed = 0;

foreach ($result['results'] as $i => $r) {
    $expected = $expectedTypes[$i] ?? '??';
    $detected = $r['type'] ?? $r['reason'] ?? 'NONE';
    $match = ($detected === $expected);

    if ($match) {
        echo "  PASS [{$i}] Expected: {$expected}, Got: {$detected}\n";
        $passed++;

        // Show key parsed fields
        $p = $r['parsed'] ?? [];
        if ($p['ctl_element'] ?? null) echo "         ctl_element: {$p['ctl_element']}\n";
        if ($p['advisory_number'] ?? null) echo "         advisory_number: {$p['advisory_number']}\n";
        if ($p['start_utc'] ?? null) echo "         start_utc: {$p['start_utc']}\n";
        if ($p['end_utc'] ?? null) echo "         end_utc: {$p['end_utc']}\n";
        if ($p['program_rate'] ?? null) echo "         program_rate: {$p['program_rate']}\n";
        if ($p['impacting_condition'] ?? null) echo "         impacting_condition: {$p['impacting_condition']}\n";
        if ($p['restriction_value'] ?? null) echo "         restriction: {$p['restriction_value']} {$p['restriction_unit']}\n";
        if ($p['mit_fix'] ?? null) echo "         mit_fix: {$p['mit_fix']}\n";
        if ($p['route_string'] ?? null) echo "         route_string: {$p['route_string']}\n";
        if ($p['scope_centers'] ?? null) echo "         scope_centers: " . implode(' ', $p['scope_centers']) . "\n";
    } else {
        echo "  FAIL [{$i}] Expected: {$expected}, Got: {$detected}\n";
        if (isset($r['reason'])) echo "         reason: {$r['reason']}\n";
        if (isset($r['preview'])) echo "         preview: {$r['preview']}\n";
        $failed++;
    }
    echo "\n";
}

echo "=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
