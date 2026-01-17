<?php
/**
 * NTML Discord Test Endpoint
 *
 * Simple endpoint for testing NTML Discord posting.
 *
 * Usage: GET https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026
 *
 * @version 1.2.0
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Version for deployment check
define('TEST_VERSION', '1.2.0');

// Test API key
define('TEST_API_KEY', 'perti-ntml-test-2026');

// If just checking version
if (isset($_GET['version'])) {
    echo json_encode(['version' => TEST_VERSION, 'time' => gmdate('Y-m-d H:i:s')]);
    exit;
}

// Validate API key
$providedKey = $_SERVER['HTTP_X_TEST_KEY'] ?? $_GET['key'] ?? '';
if ($providedKey !== TEST_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing API key. Use ?key=perti-ntml-test-2026']);
    exit;
}

// Load main config (contains Discord credentials)
require_once __DIR__ . '/../../load/config.php';

// Load Discord classes
require_once __DIR__ . '/../../load/discord/DiscordAPI.php';
require_once __DIR__ . '/../../load/discord/TMIDiscord.php';

// Initialize Discord
$discord = new DiscordAPI();
$tmi = new TMIDiscord($discord);

if (!$discord->isConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Discord still not configured']);
    exit;
}

// Get test type from query
$testType = $_GET['type'] ?? 'all';

$results = [];
$passed = 0;
$failed = 0;

// Pause between messages (seconds)
$pause = 1;

// Post header
$headerResult = $discord->createMessage('ntml_staging', [
    'content' => "```\n=== NTML FORMAT TEST ===\nTime: " . gmdate('Y-m-d H:i:s') . " UTC\nTriggered via API test endpoint\n```"
]);

if (!$headerResult) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to post to Discord: ' . $discord->getLastError(),
        'http_code' => $discord->getLastHttpCode()
    ]);
    exit;
}

$results['header'] = ['success' => true, 'message_id' => $headerResult['id'] ?? null];
sleep($pause);

// Define test cases
$ntmlTests = [
    [
        'name' => 'MIT with via fix',
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
        'name' => 'MIT with NO STACKS',
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
        'data' => [
            'entry_type' => 'STOP',
            'airport' => 'EWR',
            'flow_type' => 'arrivals',
            'reason_code' => 'WEATHER',
            'reason_detail' => 'THUNDERSTORMS',
            'exclusions' => 'LIFEGUARD,MEDEVAC',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+30 minutes')),
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'N90'
        ]
    ],
    [
        'name' => 'MINIT basic',
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
        'name' => 'D/D Departure Delay',
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
        'name' => 'E/D with Holding',
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
        'name' => 'CFR Multi-airport',
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

// Run NTML tests
if ($testType === 'all' || $testType === 'ntml') {
    foreach ($ntmlTests as $test) {
        $result = $tmi->postNtmlEntry($test['data'], 'ntml_staging');
        
        $testResult = [
            'name' => $test['name'],
            'success' => ($result && isset($result['id'])),
            'message_id' => $result['id'] ?? null,
            'error' => $result ? null : $discord->getLastError()
        ];
        
        if ($testResult['success']) {
            $passed++;
        } else {
            $failed++;
        }
        
        $results['ntml'][] = $testResult;
        sleep($pause);
    }
}

// Run Advisory tests
if ($testType === 'all' || $testType === 'advisory') {
    // Ground Stop
    $gsResult = $tmi->postGroundStopAdvisory([
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
        'comments' => 'TEST - Expected to improve by ' . gmdate('Hi', strtotime('+2 hours')) . 'Z'
    ], 'advzy_staging');
    
    $results['advisory'][] = [
        'name' => 'Ground Stop Advisory',
        'success' => ($gsResult && isset($gsResult['id'])),
        'message_id' => $gsResult['id'] ?? null,
        'error' => $gsResult ? null : $discord->getLastError()
    ];
    if ($gsResult && isset($gsResult['id'])) $passed++; else $failed++;
    
    sleep($pause);
    
    // GDP
    $gdpResult = $tmi->postGDPAdvisory([
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
        'impacting_condition' => 'VOLUME',
        'comments' => 'TEST - Monitor for extension'
    ], 'advzy_staging');
    
    $results['advisory'][] = [
        'name' => 'GDP Advisory',
        'success' => ($gdpResult && isset($gdpResult['id'])),
        'message_id' => $gdpResult['id'] ?? null,
        'error' => $gdpResult ? null : $discord->getLastError()
    ];
    if ($gdpResult && isset($gdpResult['id'])) $passed++; else $failed++;
    
    sleep($pause);
    
    // Reroute
    $rrResult = $tmi->postRerouteAdvisory([
        'advisory_number' => 'T03',
        'facility' => 'DCC',
        'action' => 'RQD',
        'route_type' => 'ROUTE',
        'route_name' => 'TEST_ZBW_A2_JFK',
        'impacted_area' => 'NY METRO',
        'reason' => 'WEATHER',
        'reason_detail' => 'CONVECTIVE',
        'include_traffic' => 'ZBW DEPS TO JFK',
        'start_utc' => gmdate('Y-m-d H:i:s'),
        'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
        'valid_type' => 'ETD',
        'facilities' => 'ZBW ZOB ZDC',
        'prob_extension' => 'MEDIUM',
        'routes' => [
            ['origin' => 'BOS', 'dest' => 'JFK', 'route' => 'BOS PATSS >J48 JFK< JFK'],
            ['origin' => 'PVD', 'dest' => 'JFK', 'route' => 'PVD >J48 JFK< JFK']
        ]
    ], 'advzy_staging');
    
    $results['advisory'][] = [
        'name' => 'Reroute Advisory',
        'success' => ($rrResult && isset($rrResult['id'])),
        'message_id' => $rrResult['id'] ?? null,
        'error' => $rrResult ? null : $discord->getLastError()
    ];
    if ($rrResult && isset($rrResult['id'])) $passed++; else $failed++;
}

// Return results
echo json_encode([
    'success' => $failed === 0,
    'summary' => [
        'passed' => $passed,
        'failed' => $failed,
        'total' => $passed + $failed
    ],
    'results' => $results,
    'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
    'channels' => [
        'ntml' => '#ntml-staging (1039586515115839621)',
        'advisory' => '#advzy-staging (1039586515115839622)'
    ]
], JSON_PRETTY_PRINT);
