<?php
/**
 * Advisory Format Validation Test Script
 * Tests advisory formatting functions against TFMS spec (Advisories_and_General_Messages_v1_3.pdf)
 * 
 * Usage: php test_advisory_format.php
 */

echo "=== Advisory Format Validation Tests ===\n";
echo "Date: " . date('Y-m-d H:i:s') . " UTC\n\n";

// Load TMIDiscord class
require_once __DIR__ . '/../../load/discord/TMIDiscord.php';

// Create mock DiscordAPI for testing
class MockDiscordAPI {
    public function isConfigured() { return true; }
    public function getConfiguredChannels() { return []; }
    public function createMessage($channel, $data) { 
        return ['id' => 'test_' . time(), 'content' => $data['content']]; 
    }
}

// Create TMIDiscord instance
$tmiDiscord = new TMIDiscord(new MockDiscordAPI());

// Use reflection to access private methods for testing
$refClass = new ReflectionClass('TMIDiscord');

/**
 * Helper to invoke private methods
 */
function invokePrivate($object, $methodName, array $args = []) {
    global $refClass;
    $method = $refClass->getMethod($methodName);
    $method->setAccessible(true);
    return $method->invoke($object, ...$args);
}

// ============================================
// TEST CASES
// ============================================

$tests = [
    // Ground Stop Advisory Tests
    [
        'name' => 'Ground Stop Advisory - Basic',
        'method' => 'formatGroundStopAdvisory',
        'input' => [
            'advisory_number' => '001',
            'ctl_element' => 'JFK',
            'artcc' => 'ZNY',
            'issue_date' => '2026-01-17',
            'adl_time' => '1500',
            'start_utc' => '2026-01-17 15:00:00',
            'end_utc' => '2026-01-17 18:00:00',
            'scope_tier' => 'TIER 1',
            'dep_facilities' => 'ZBW ZDC ZOB',
            'prev_total_delay' => '0',
            'prev_max_delay' => '0',
            'prev_avg_delay' => '0',
            'new_total_delay' => '450',
            'new_max_delay' => '120',
            'new_avg_delay' => '45',
            'prob_extension' => 'HIGH',
            'impacting_condition' => 'WEATHER',
            'condition_text' => 'THUNDERSTORMS'
        ],
        'expected_patterns' => [
            '/vATCSCC ADVZY 001 JFK\/ZNY \d{2}\/\d{2}\/\d{4} CDM GROUND STOP/',
            '/CTL ELEMENT: JFK/',
            '/ELEMENT TYPE: APT/',
            '/GROUND STOP PERIOD:.*Z -.*Z/',
            '/FLT INCL: TIER 1/',
            '/PROBABILITY OF EXTENSION: HIGH/',
            '/IMPACTING CONDITION: WEATHER THUNDERSTORMS/'
        ]
    ],
    
    // Ground Stop Cancellation Tests
    [
        'name' => 'Ground Stop Cancellation',
        'method' => 'formatGroundStopCancellation',
        'input' => [
            'advisory_number' => '002',
            'ctl_element' => 'JFK',
            'artcc' => 'ZNY',
            'issue_date' => '2026-01-17',
            'adl_time' => '1800',
            'start_utc' => '2026-01-17 15:00:00',
            'end_utc' => '2026-01-17 18:00:00',
            'comments' => 'Weather improved'
        ],
        'expected_patterns' => [
            '/vATCSCC ADVZY 002 JFK\/ZNY \d{2}\/\d{2}\/\d{4} CDM GS CNX/',
            '/CTL ELEMENT: JFK/',
            '/GS CNX PERIOD:/',
            '/COMMENTS: Weather improved/'
        ]
    ],
    
    // GDP Advisory Tests
    [
        'name' => 'GDP Advisory - DAS Mode',
        'method' => 'formatGDPAdvisory',
        'input' => [
            'advisory_number' => '003',
            'ctl_element' => 'EWR',
            'artcc' => 'ZNY',
            'issue_date' => '2026-01-17',
            'adl_time' => '1400',
            'delay_mode' => 'DAS',
            'start_utc' => '2026-01-17 14:00:00',
            'end_utc' => '2026-01-17 22:00:00',
            'program_rate' => '30',
            'scope_tier' => 'TIER 2',
            'departure_scope' => 'ZBW ZDC ZOB CONUS',
            'delay_limit' => '180',
            'max_delay' => '120',
            'avg_delay' => '45',
            'impacting_condition' => 'VOLUME',
            'condition_text' => 'HIGH VOLUME'
        ],
        'expected_patterns' => [
            '/vATCSCC ADVZY 003 EWR\/ZNY \d{2}\/\d{2}\/\d{4} CDM GROUND DELAY PROGRAM/',
            '/DELAY ASSIGNMENT MODE: DAS/',
            '/PROGRAM RATE: 30/',
            '/DEPARTURE SCOPE: ZBW ZDC ZOB CONUS/',
            '/DELAY LIMIT: 180/',
            '/IMPACTING CONDITION: VOLUME \/ HIGH VOLUME/'
        ]
    ],
    
    // GDP Cancellation Tests
    [
        'name' => 'GDP Cancellation',
        'method' => 'formatGDPCancellation',
        'input' => [
            'advisory_number' => '004',
            'ctl_element' => 'EWR',
            'artcc' => 'ZNY',
            'issue_date' => '2026-01-17',
            'adl_time' => '2200',
            'start_utc' => '2026-01-17 14:00:00',
            'end_utc' => '2026-01-17 22:00:00',
            'comments' => 'Volume subsided'
        ],
        'expected_patterns' => [
            '/vATCSCC ADVZY 004 EWR\/ZNY \d{2}\/\d{2}\/\d{4} CDM GROUND DELAY PROGRAM CNX/',
            '/GDP CNX PERIOD:/',
            '/DISREGARD EDCTS FOR DEST EWR/'
        ]
    ],
    
    // Reroute Advisory Tests
    [
        'name' => 'Reroute Advisory - Route RQD',
        'method' => 'formatRerouteAdvisory',
        'input' => [
            'advisory_number' => '005',
            'facility' => 'DCC',
            'issue_date' => '2026-01-17',
            'action' => 'RQD',
            'route_type' => 'ROUTE',
            'route_name' => 'ZBW_A2_JFK',
            'impacted_area' => 'NY METRO',
            'reason' => 'WEATHER',
            'reason_detail' => 'CONVECTIVE ACTIVITY',
            'include_traffic' => 'ZBW DEPARTURES TO JFK',
            'start_utc' => '2026-01-17 15:00:00',
            'end_utc' => '2026-01-17 22:00:00',
            'valid_type' => 'ETD',
            'facilities' => 'ZBW ZOB ZDC',
            'prob_extension' => 'MEDIUM',
            'routes' => [
                ['origin' => 'BOS', 'dest' => 'JFK', 'route' => 'BOS PATSS >J48 JFK< JFK'],
                ['origin' => 'PVD', 'dest' => 'JFK', 'route' => 'PVD >J48 JFK< JFK']
            ]
        ],
        'expected_patterns' => [
            '/vATCSCC ADVZY 005 DCC \d{2}\/\d{2}\/\d{4} ROUTE RQD/',
            '/NAME: ZBW_A2_JFK/',
            '/IMPACTED AREA: NY METRO/',
            '/REASON: WEATHER \/ CONVECTIVE ACTIVITY/',
            '/VALID: ETD \d{6} TO \d{6}/',
            '/PROBABILITY OF EXTENSION: MEDIUM/',
            '/BOS.*JFK.*>J48 JFK</',  // Protected segment check
        ]
    ],
];

// ============================================
// RUN TESTS
// ============================================

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    $result = invokePrivate($tmiDiscord, $test['method'], [$test['input']]);
    
    $allPatternsMatch = true;
    $failedPatterns = [];
    
    foreach ($test['expected_patterns'] as $pattern) {
        if (!preg_match($pattern, $result)) {
            $allPatternsMatch = false;
            $failedPatterns[] = $pattern;
        }
    }
    
    if ($allPatternsMatch) {
        echo "✓ PASS: {$test['name']}\n";
        echo "  Output preview:\n";
        // Show first few lines
        $lines = explode("\n", $result);
        foreach (array_slice($lines, 0, 5) as $line) {
            echo "    $line\n";
        }
        if (count($lines) > 5) {
            echo "    ... (" . (count($lines) - 5) . " more lines)\n";
        }
        echo "\n";
        $passed++;
    } else {
        echo "✗ FAIL: {$test['name']}\n";
        echo "  Full output:\n";
        foreach (explode("\n", $result) as $line) {
            echo "    $line\n";
        }
        echo "  Failed patterns:\n";
        foreach ($failedPatterns as $pattern) {
            echo "    $pattern\n";
        }
        echo "\n";
        $failed++;
    }
}

// ============================================
// 68-CHARACTER LINE WRAP TEST
// ============================================

echo "\n=== 68-Character Line Wrap Tests ===\n";

$wrapMethod = $refClass->getMethod('wrapText');
$wrapMethod->setAccessible(true);

$longText = "This is a very long comment that should be wrapped at 68 characters per the IATA Type B message format specification for advisory messages.";
$wrappedText = $wrapMethod->invoke($tmiDiscord, $longText);

$linesOver68 = 0;
foreach (explode("\n", $wrappedText) as $line) {
    if (strlen($line) > 68) {
        $linesOver68++;
        echo "✗ Line exceeds 68 chars ({" . strlen($line) . "}): $line\n";
    }
}

if ($linesOver68 === 0) {
    echo "✓ All lines are 68 characters or less\n";
    $passed++;
} else {
    echo "✗ FAIL: $linesOver68 lines exceed 68 characters\n";
    $failed++;
}

// ============================================
// SUMMARY
// ============================================

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total:  " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed! Advisory format is TFMS compliant.\n";
} else {
    echo "\n✗ Some tests failed. Review output above.\n";
}

// ============================================
// EXAMPLE OUTPUT
// ============================================

echo "\n=== Example Advisory Outputs ===\n\n";

echo "--- Ground Stop Advisory ---\n";
echo invokePrivate($tmiDiscord, 'formatGroundStopAdvisory', [[
    'advisory_number' => '001',
    'ctl_element' => 'JFK',
    'artcc' => 'ZNY',
    'start_utc' => '2026-01-17 15:00:00',
    'end_utc' => '2026-01-17 18:00:00',
    'scope_tier' => 'TIER 1',
    'prob_extension' => 'HIGH',
    'impacting_condition' => 'WEATHER',
    'condition_text' => 'THUNDERSTORMS',
    'comments' => 'Expected to improve by 1800Z'
]]);
echo "\n\n";

echo "--- GDP Advisory ---\n";
echo invokePrivate($tmiDiscord, 'formatGDPAdvisory', [[
    'advisory_number' => '003',
    'ctl_element' => 'EWR',
    'artcc' => 'ZNY',
    'delay_mode' => 'DAS',
    'start_utc' => '2026-01-17 14:00:00',
    'end_utc' => '2026-01-17 22:00:00',
    'program_rate' => '30',
    'scope_tier' => 'TIER 2',
    'departure_scope' => 'ZBW ZDC ZOB',
    'delay_limit' => '180',
    'impacting_condition' => 'VOLUME',
    'comments' => 'Monitor weather closely'
]]);
echo "\n\n";

echo "--- Reroute Advisory ---\n";
echo invokePrivate($tmiDiscord, 'formatRerouteAdvisory', [[
    'advisory_number' => '005',
    'facility' => 'DCC',
    'action' => 'RQD',
    'route_type' => 'ROUTE',
    'route_name' => 'ZBW_A2_JFK',
    'impacted_area' => 'NY METRO',
    'reason' => 'WEATHER',
    'reason_detail' => 'THUNDERSTORMS',
    'include_traffic' => 'ZBW DEPARTURES TO JFK',
    'start_utc' => '2026-01-17 15:00:00',
    'end_utc' => '2026-01-17 22:00:00',
    'valid_type' => 'ETD',
    'facilities' => 'ZBW ZOB ZDC',
    'prob_extension' => 'MEDIUM',
    'routes' => [
        ['origin' => 'BOS', 'dest' => 'JFK', 'route' => 'BOS PATSS >J48 JFK< JFK'],
        ['origin' => 'PVD', 'dest' => 'JFK', 'route' => 'PVD >J48 JFK< JFK']
    ]
]]);
echo "\n";
