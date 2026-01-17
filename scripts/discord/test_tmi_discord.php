<?php
/**
 * TMI Discord Integration Test Script
 *
 * Tests posting TMI notifications to Discord
 * using proper NTML and Advisory formatting.
 *
 * Run from command line: php scripts/discord/test_tmi_discord.php
 */

// Load main config (contains Discord credentials)
require_once __DIR__ . '/../../load/config.php';

// Load TMI Discord module
require_once __DIR__ . '/../../load/discord/TMIDiscord.php';

echo "===========================================\n";
echo "TMI Discord Integration Test\n";
echo "===========================================\n\n";

$tmi = new TMIDiscord();

if (!$tmi->isAvailable()) {
    echo "ERROR: Discord integration not available\n";
    exit(1);
}

echo "Discord integration available. Running tests...\n\n";

// Pause between messages to avoid rate limiting
$pause = 2;

// =============================================
// Test 1: NTML Entry (MIT)
// =============================================
echo "1. Testing NTML Entry (MIT)...\n";

$mitEntry = [
    'entry_id' => 999,
    'entry_type' => 'MIT',
    'determinant' => '05B01',
    'restriction_value' => 20,
    'condition_text' => 'JFK LENDY',
    'requesting_facility' => 'ZNY',
    'providing_facility' => 'ZBW',
    'valid_from' => gmdate('Y-m-d H:i:s'),
    'valid_until' => gmdate('Y-m-d H:i:s', strtotime('+2 hours')),
    'reason_code' => 'VOLUME',
    'qualifiers' => 'HEAVY,PER_FIX',
    'exclusions' => 'LIFEGUARD, MEDEVAC',
    'status' => 'ACTIVE'
];

$result = $tmi->postNtmlEntry($mitEntry, 'ntml_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 2: NTML Entry (MINIT)
// =============================================
echo "2. Testing NTML Entry (MINIT)...\n";

$minitEntry = [
    'entry_type' => 'MINIT',
    'determinant' => '06A02',
    'restriction_value' => 5,
    'condition_text' => 'EWR departures to ZDC',
    'requesting_facility' => 'ZDC',
    'providing_facility' => 'N90',
    'valid_from' => gmdate('Y-m-d H:i:s'),
    'valid_until' => gmdate('Y-m-d H:i:s', strtotime('+3 hours')),
    'reason_code' => 'WEATHER',
    'status' => 'ACTIVE'
];

$result = $tmi->postNtmlEntry($minitEntry, 'ntml_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 3: NTML Entry (DELAY)
// =============================================
echo "3. Testing NTML Entry (DELAY)...\n";

$delayEntry = [
    'entry_type' => 'DELAY',
    'determinant' => '04C01',
    'delay_facility' => 'JFK',
    'charge_facility' => 'ZNY',
    'longest_delay' => 45,
    'delay_trend' => 'Increasing',
    'flights_delayed' => 12,
    'reason_code' => 'WEATHER',
    'holding' => 'yes_initiating',
    'holding_location' => 'LENDY',
    'notes' => 'Expect improvement after 2100Z'
];

$result = $tmi->postNtmlEntry($delayEntry, 'ntml_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 4: NTML Entry (CONFIG)
// =============================================
echo "4. Testing NTML Entry (CONFIG)...\n";

$configEntry = [
    'entry_type' => 'CONFIG',
    'determinant' => '01B03',
    'airport' => 'JFK',
    'weather' => 'IMC',
    'arr_runways' => '22L/22R',
    'dep_runways' => '31L',
    'aar' => 40,
    'adr' => 45,
    'single_runway' => 'no',
    'wx_tstorm' => true
];

$result = $tmi->postNtmlEntry($configEntry, 'ntml_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 5: Ground Stop Advisory
// =============================================
echo "5. Testing Ground Stop Advisory...\n";

$gsData = [
    'advisory_number' => '003',
    'ctl_element' => 'EWR',
    'artcc' => 'ZNY',
    'adl_time' => gmdate('Y-m-d H:i:s'),
    'start_utc' => gmdate('Y-m-d H:i:s'),
    'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+2 hours')),
    'dep_facilities' => ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZNY', 'ZOB'],
    'prob_extension' => 'HIGH',
    'impacting_condition' => 'VOLUME',
    'comments' => 'ALTERNATES RECOMMENDED: JFK, LGA'
];

$result = $tmi->postGroundStopAdvisory($gsData, 'advzy_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 6: GDP Advisory
// =============================================
echo "6. Testing GDP Advisory...\n";

$gdpData = [
    'advisory_number' => '004',
    'ctl_element' => 'JFK',
    'artcc' => 'ZNY',
    'adl_time' => gmdate('Y-m-d H:i:s'),
    'delay_mode' => 'DAS',
    'start_utc' => gmdate('Y-m-d H:i:s'),
    'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+5 hours')),
    'program_rate' => '30/30/35/35/40/40',
    'dep_scope' => '(TIER 2) ZBW ZDC ZNY ZOB ZID ZTL ZJX',
    'max_delay' => 90,
    'avg_delay' => 45,
    'impacting_condition' => 'RUNWAY',
    'cause_text' => 'Reduced capacity due to construction',
    'comments' => 'Monitor for possible extension'
];

$result = $tmi->postGDPAdvisory($gdpData, 'advzy_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 7: Reroute Advisory
// =============================================
echo "7. Testing Reroute Advisory...\n";

$rerouteData = [
    'advisory_number' => '005',
    'facility' => 'DCC',
    'route_type' => 'ROUTE',
    'action' => 'RQD',
    'impacted_area' => 'ZNY',
    'reason' => 'VOLUME',
    'include_traffic' => 'KJFK/KEWR/KLGA DEPARTURES TO KPIT',
    'start_utc' => gmdate('Y-m-d H:i:s'),
    'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
    'facilities' => ['ZNY', 'ZOB'],
    'prob_extension' => 'MEDIUM',
    'remarks' => '',
    'associated_restrictions' => 'ZNY REQUESTS AOB FL300',
    'modifications' => '',
    'routes' => [
        ['origin' => 'JFK', 'dest' => 'PIT', 'route' => 'DEEZZ5 >CANDR J60 PSB< HAYNZ6'],
        ['origin' => 'EWR', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6'],
        ['origin' => 'LGA', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6']
    ]
];

$result = $tmi->postRerouteAdvisory($rerouteData, 'advzy_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 8: Informational Advisory
// =============================================
echo "8. Testing Informational Advisory...\n";

$infoData = [
    'advisory_number' => '006',
    'facility' => 'DCC',
    'start_utc' => gmdate('Y-m-d H:i:s'),
    'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+8 hours')),
    'body_text' => "THE EAST COAST HOTLINE IS BEING ACTIVATED TO ADDRESS VOLUME IN ZOB/ZAU/ZID/ZDC/ZNY/ZBW/ZMP.\n\nTHE LOCATION IS THE VATUSA TEAMSPEAK, EAST COAST HOTLINE, (TS.VATUSA.NET), NO PIN.\n\nPARTICIPATION IS RECOMMENDED FOR ZOB/ZAU/ZID/ZDC/ZNY/ZBW/ZMP. ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN."
];

$result = $tmi->postInformationalAdvisory($infoData, 'advzy_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 9: NTML Cancellation
// =============================================
echo "9. Testing NTML Cancellation...\n";

$mitEntry['cancel_reason'] = 'Weather improving, demand reduced';

$result = $tmi->postNtmlCancellation($mitEntry, 'ntml_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}
sleep($pause);

// =============================================
// Test 10: Ground Stop Cancellation
// =============================================
echo "10. Testing Ground Stop Cancellation...\n";

$gsData['advisory_number'] = '007';
$gsData['cancel_reason'] = 'Weather has cleared';

$result = $tmi->postGroundStopCancellation($gsData, 'advzy_staging');
if ($result) {
    echo "   SUCCESS: Message ID {$result['id']}\n";
} else {
    echo "   ERROR: " . $tmi->getAPI()->getLastError() . "\n";
}

echo "\n===========================================\n";
echo "All Tests Complete!\n";
echo "===========================================\n";
echo "\nCheck the Discord channels:\n";
echo "- #⚠ntml-staging⚠ for NTML entries\n";
echo "- #⚠advzy-staging⚠ for advisories\n";
