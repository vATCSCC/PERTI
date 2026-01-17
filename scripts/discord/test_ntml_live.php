<?php
/**
 * NTML Live Discord Test Script
 * 
 * Tests NTML formatting by posting to the backup Discord server staging channels.
 * Validates format compliance from test_ntml_format.php in a live environment.
 * 
 * Run from command line: php scripts/discord/test_ntml_live.php
 * 
 * @version 1.0.0
 * @date 2026-01-17
 */

// Load backup Discord configuration
require_once __DIR__ . '/config_backup.php';

// Load TMI Discord module
require_once __DIR__ . '/../../load/discord/TMIDiscord.php';

echo "===========================================\n";
echo "NTML Live Discord Test\n";
echo "Date: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "===========================================\n\n";

$tmi = new TMIDiscord();

if (!$tmi->isAvailable()) {
    echo "ERROR: Discord integration not available\n";
    echo "Check that DISCORD_BOT_TOKEN is configured in config_backup.php\n";
    exit(1);
}

echo "✓ Discord integration available\n";
echo "  Target: Backup Discord Server (Staging Channels)\n\n";

// Pause between messages to avoid rate limiting
$pause = 2;
$passed = 0;
$failed = 0;

// =============================================
// NTML FORMAT TESTS
// These match the patterns from test_ntml_format.php
// =============================================

$tests = [
    [
        'name' => 'MIT with via fix (arrivals)',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'BOS',
            'fix' => 'MERIT',
            'restriction_value' => '15',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+2 hours')),
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'ZNY'
        ]
    ],
    [
        'name' => 'MIT with departures',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'BOS',
            'restriction_value' => '40',
            'flow_type' => 'departures',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+3 hours')),
            'requesting_facility' => 'ZDC',
            'providing_facility' => 'PCT'
        ]
    ],
    [
        'name' => 'MIT with qualifier NO STACKS',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'LGA',
            'fix' => 'J146',
            'restriction_value' => '25',
            'flow_type' => 'arrivals',
            'qualifiers' => 'NO_STACKS',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+3 hours')),
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'ZOB'
        ]
    ],
    [
        'name' => 'STOP arrivals',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'STOP',
            'airport' => 'BOS',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'LIFEGUARD,MEDEVAC',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+30 minutes')),
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'PHL'
        ]
    ],
    [
        'name' => 'MINIT basic',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'MINIT',
            'airport' => 'BOS',
            'restriction_value' => '8',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+3 hours')),
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'CZY'
        ]
    ],
    [
        'name' => 'D/D (Departure Delay)',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'D/D',
            'delay_facility' => 'JFK',
            'longest_delay' => '45',
            'delay_trend' => 'increasing',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '8',
            'reason_code' => 'VOLUME'
        ]
    ],
    [
        'name' => 'E/D (Enroute Delay) with Holding',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'E/D',
            'reporting_facility' => 'ZDC',
            'delay_facility' => 'BOS',
            'holding' => 'yes_initiating',
            'delay_trend' => 'initiating',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '13',
            'fix' => 'DEALE',
            'reason_code' => 'VOLUME'
        ]
    ],
    [
        'name' => 'Config VMC',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'CONFIG',
            'airport' => 'ATL',
            'weather' => 'VMC',
            'arr_runways' => '26R/27L/28',
            'dep_runways' => '26L/27R',
            'aar' => '132',
            'aar_type' => 'Strat',
            'adr' => '70'
        ]
    ],
    [
        'name' => 'Config IMC with AAR Adjustment',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'CONFIG',
            'airport' => 'PHL',
            'weather' => 'IMC',
            'arr_runways' => '27R',
            'dep_runways' => '27L/35',
            'aar' => '36',
            'aar_type' => 'Dyn',
            'aar_adjustment' => 'XW-TLWD',
            'adr' => '28'
        ]
    ],
    [
        'name' => 'CFR (Call for Release)',
        'type' => 'ntml',
        'data' => [
            'entry_type' => 'CFR',
            'airport' => 'MIA,FLL',
            'flow_type' => 'departures',
            'aircraft_type' => 'ALL',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+4 hours')),
            'requesting_facility' => 'ZMA',
            'providing_facility' => 'F11'
        ]
    ]
];

// =============================================
// RUN TESTS
// =============================================

echo "Posting " . count($tests) . " test messages to Discord...\n\n";

foreach ($tests as $i => $test) {
    $num = $i + 1;
    echo "{$num}. Testing: {$test['name']}...\n";
    
    $result = $tmi->postNtmlEntry($test['data'], 'ntml_staging');
    
    if ($result && isset($result['id'])) {
        echo "   ✓ SUCCESS: Message ID {$result['id']}\n";
        $passed++;
    } else {
        $error = $tmi->getAPI()->getLastError() ?? 'Unknown error';
        echo "   ✗ ERROR: {$error}\n";
        $failed++;
    }
    
    // Brief pause between posts
    if ($i < count($tests) - 1) {
        sleep($pause);
    }
}

// =============================================
// ADVISORY TESTS
// =============================================

echo "\n--- Advisory Tests ---\n\n";

$advisoryTests = [
    [
        'name' => 'Ground Stop Advisory',
        'method' => 'postGroundStopAdvisory',
        'channel' => 'advzy_staging',
        'data' => [
            'advisory_number' => 'T01',
            'ctl_element' => 'JFK',
            'artcc' => 'ZNY',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+2 hours')),
            'scope_tier' => 'TIER 1',
            'dep_facilities' => 'ZBW ZDC ZOB',
            'prob_extension' => 'HIGH',
            'impacting_condition' => 'WEATHER',
            'condition_text' => 'THUNDERSTORMS',
            'comments' => 'TEST MESSAGE - Expected to improve by ' . gmdate('Hi', strtotime('+2 hours')) . 'Z'
        ]
    ],
    [
        'name' => 'GDP Advisory',
        'method' => 'postGDPAdvisory',
        'channel' => 'advzy_staging',
        'data' => [
            'advisory_number' => 'T02',
            'ctl_element' => 'EWR',
            'artcc' => 'ZNY',
            'delay_mode' => 'DAS',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+5 hours')),
            'program_rate' => '30',
            'scope_tier' => 'TIER 2',
            'departure_scope' => 'ZBW ZDC ZOB',
            'delay_limit' => '180',
            'max_delay' => '120',
            'avg_delay' => '45',
            'impacting_condition' => 'VOLUME',
            'condition_text' => 'HIGH VOLUME',
            'comments' => 'TEST MESSAGE - Monitor for possible extension'
        ]
    ],
    [
        'name' => 'Reroute Advisory',
        'method' => 'postRerouteAdvisory',
        'channel' => 'advzy_staging',
        'data' => [
            'advisory_number' => 'T03',
            'facility' => 'DCC',
            'action' => 'RQD',
            'route_type' => 'ROUTE',
            'route_name' => 'ZBW_A2_JFK_TEST',
            'impacted_area' => 'NY METRO',
            'reason' => 'WEATHER',
            'reason_detail' => 'CONVECTIVE ACTIVITY',
            'include_traffic' => 'ZBW DEPARTURES TO JFK',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
            'valid_type' => 'ETD',
            'facilities' => 'ZBW ZOB ZDC',
            'prob_extension' => 'MEDIUM',
            'routes' => [
                ['origin' => 'BOS', 'dest' => 'JFK', 'route' => 'BOS PATSS >J48 JFK< JFK'],
                ['origin' => 'PVD', 'dest' => 'JFK', 'route' => 'PVD >J48 JFK< JFK']
            ]
        ]
    ]
];

foreach ($advisoryTests as $i => $test) {
    $num = $i + 1;
    echo "{$num}. Testing: {$test['name']}...\n";
    
    $method = $test['method'];
    $result = $tmi->$method($test['data'], $test['channel']);
    
    if ($result && isset($result['id'])) {
        echo "   ✓ SUCCESS: Message ID {$result['id']}\n";
        $passed++;
    } else {
        $error = $tmi->getAPI()->getLastError() ?? 'Unknown error';
        echo "   ✗ ERROR: {$error}\n";
        $failed++;
    }
    
    sleep($pause);
}

// =============================================
// SUMMARY
// =============================================

$total = $passed + $failed;
echo "\n===========================================\n";
echo "Test Summary\n";
echo "===========================================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total:  {$total}\n";

if ($failed === 0) {
    echo "\n✓ All tests passed!\n";
} else {
    echo "\n✗ Some tests failed. Check Discord channels and errors above.\n";
}

echo "\nCheck the Discord staging channels:\n";
echo "- #⚠ntml-staging⚠ for NTML entries\n";
echo "- #⚠advzy-staging⚠ for advisories\n";
echo "\nMessages marked as TEST to avoid confusion.\n";
