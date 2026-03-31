<?php
/**
 * VATSWIM Client Bridges Integration Test
 *
 * Validates all 5 bridges and cross-cutting components.
 * Run: php scripts/test_bridges.php
 */

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed, $errors;
    if ($condition) {
        echo "  PASS  $name\n";
        $passed++;
    } else {
        echo "  FAIL  $name" . ($detail ? " — $detail" : "") . "\n";
        $failed++;
        $errors[] = $name;
    }
}

$root = dirname(__DIR__);

echo "=== VATSWIM Client Bridges Integration Test ===\n\n";

// =========================================================================
// Bridge 1: HoppieWriter (PHP server-side)
// =========================================================================
echo "Bridge 1: HoppieWriter\n";

// Test CDMService constants
require_once "$root/load/services/CDMService.php";
$origConstants = ['MSG_EDCT', 'MSG_GATE_HOLD', 'MSG_GATE_RELEASE', 'MSG_SLOT_UPDATE', 'MSG_CANCEL', 'MSG_INFO'];
$newConstants = [
    'MSG_EDCT_AMENDED', 'MSG_EDCT_CANCEL', 'MSG_CTOT', 'MSG_GS_HOLD', 'MSG_GS_RELEASE',
    'MSG_REROUTE', 'MSG_FLOW_MEASURE', 'MSG_MIT', 'MSG_AFP', 'MSG_METERING',
    'MSG_HOLD', 'MSG_CTP_SLOT', 'MSG_WEATHER_REROUTE', 'MSG_TOS_QUERY', 'MSG_TOS_ACK',
    'MSG_TOS_ASSIGN', 'MSG_TRAFFIC_ADV',
];
foreach ($origConstants as $c) {
    test("CDMService::$c exists", defined("CDMService::$c"));
}
foreach ($newConstants as $c) {
    test("CDMService::$c exists", defined("CDMService::$c"));
}

// Test EDCTDelivery formatters — instantiate without DB connections
// CDMService constructor requires $conn_swim; mock with null and suppress errors
$cdmRef = new ReflectionClass('CDMService');
$cdm = $cdmRef->newInstanceWithoutConstructor();

require_once "$root/load/services/EDCTDelivery.php";
$edctRef = new ReflectionClass('EDCTDelivery');
$delivery = $edctRef->newInstanceWithoutConstructor();
// Inject CDM via reflection so formatter methods work
$cdmProp = $edctRef->getProperty('cdm');
$cdmProp->setAccessible(true);
$cdmProp->setValue($delivery, $cdm);

$formatTests = [
    ['formatEDCTMessage',          ['2026-01-01 12:00:00', 'GDP KJFK VOLUME']],
    ['formatGateHoldMessage',      ['2026-01-01 12:00:00', 'VOLUME']],
    ['formatGateReleaseMessage',   ['2026-01-01 12:00:00']],
    ['formatCancelMessage',        ['GDP CANCELLED']],
    ['formatEDCTAmendedMessage',   ['2026-01-01 13:00:00', '2026-01-01 12:00:00']],
    ['formatEDCTCancelMessage',    ['2026-01-01 12:00:00']],
    ['formatCTOTMessage',          ['2026-01-01 12:00:00', 'REG001']],
    ['formatGSHoldMessage',        ['KJFK', '2026-01-01 14:00:00']],
    ['formatGSReleaseMessage',     ['KJFK', 'RELEASED']],
    ['formatRerouteMessage',       ['ADV-001', 'J75 MERIT J80', 'VOICE']],
    ['formatFlowMeasureMessage',   ['MDI', '120NM', 'CZUL']],
    ['formatMITMessage',           [30, 'MERIT']],
    ['formatAFPMessage',           ['ZNY', 12, 45]],
    ['formatMeteringMessage',      ['MERIT', '2026-01-01 12:30:00']],
    ['formatHoldMessage',          ['MERIT', '2026-01-01 13:00:00']],
    ['formatCTPSlotMessage',       ['TUDEP', '2026-01-01 14:00:00', 'NAT A']],
    ['formatWeatherRerouteMessage',['ZNY SECTOR 66', 'J75 MERIT J80']],
    ['formatTOSQueryMessage',      ['KJFK', 'KLAX']],
    ['formatTOSAckMessage',        [3]],
    ['formatTOSAssignMessage',     [2, 'J75 MERIT J80', 'VOLUME', 'ADV-001']],
    ['formatTrafficAdvisory',      ['arrival_volume', 'KJFK', 'KEWR']],
];

foreach ($formatTests as [$method, $args]) {
    $result = call_user_func_array([$delivery, $method], $args);
    test("EDCTDelivery::$method returns non-empty string", is_string($result) && strlen($result) > 0, "got: " . var_export($result, true));
}

// Test deliverMessage method exists
test("EDCTDelivery::deliverMessage exists", method_exists($delivery, 'deliverMessage'));

// Test migration file
$migrationFile = "$root/database/migrations/tmi/056_bridge1_delivery_config.sql";
test("Migration 056 exists", file_exists($migrationFile));
if (file_exists($migrationFile)) {
    $sql = file_get_contents($migrationFile);
    test("Migration has tmi_delivery_log", strpos($sql, 'tmi_delivery_log') !== false);
    test("Migration has delivery_mode", strpos($sql, 'delivery_mode') !== false);
}

// Test i18n keys
$localeFile = "$root/assets/locales/en-US.json";
test("en-US.json exists", file_exists($localeFile));
if (file_exists($localeFile)) {
    $locale = json_decode(file_get_contents($localeFile), true);
    test("i18n: bridges.* keys exist", isset($locale['bridges']));
    test("i18n: bridges has 5 bridge entries", isset($locale['bridges']['bridge1']) && isset($locale['bridges']['bridge5']));
    test("i18n: cdm.delivery.* keys exist", isset($locale['cdm']['delivery']));
    test("i18n: cdm.delivery.messageTypes exists", isset($locale['cdm']['delivery']['messageTypes']));
    test("i18n: swim.tos.* keys exist", isset($locale['swim']['tos']));
    test("i18n: swim.aman.* keys exist", isset($locale['swim']['aman']));
}

// Test WebSocket channels
$wsFile = "$root/api/swim/v1/ws/WebSocketServer.php";
test("WebSocketServer.php exists", file_exists($wsFile));
if (file_exists($wsFile)) {
    $wsCode = file_get_contents($wsFile);
    test("WS valid channels include cdm.*", strpos($wsCode, "'cdm.*'") !== false);
    test("WS valid channels include aman.*", strpos($wsCode, "'aman.*'") !== false);
    test("WS regex includes cdm|aman", strpos($wsCode, 'cdm|aman') !== false);
    test("WS has cdm.edct event", strpos($wsCode, "'cdm.edct'") !== false);
    test("WS has aman.sequence event", strpos($wsCode, "'aman.sequence'") !== false);
}

// Test TOS endpoints
test("TOS file.php exists", file_exists("$root/api/swim/v1/tos/file.php"));
test("TOS status.php exists", file_exists("$root/api/swim/v1/tos/status.php"));

// Test AMAN endpoint
test("AMAN aman.php exists", file_exists("$root/api/swim/v1/ingest/aman.php"));

// Test TOS migration
test("TOS migration 033 exists", file_exists("$root/database/migrations/swim/033_tos_options.sql"));

echo "\n";

// =========================================================================
// Bridge 2: FSD Bridge (Go)
// =========================================================================
echo "Bridge 2: FSD Bridge\n";
$fsdDir = "$root/integrations/fsd-bridge";
test("go.mod exists", file_exists("$fsdDir/go.mod"));
test("main.go exists", file_exists("$fsdDir/main.go"));
test("config.go exists", file_exists("$fsdDir/config.go"));
test("fsd/server.go exists", file_exists("$fsdDir/internal/fsd/server.go"));
test("fsd/protocol.go exists", file_exists("$fsdDir/internal/fsd/protocol.go"));
test("fsd/client.go exists", file_exists("$fsdDir/internal/fsd/client.go"));
test("swim/consumer.go exists", file_exists("$fsdDir/internal/swim/consumer.go"));
test("swim/events.go exists", file_exists("$fsdDir/internal/swim/events.go"));
test("bridge/translator.go exists", file_exists("$fsdDir/internal/bridge/translator.go"));
test("bridge/state.go exists", file_exists("$fsdDir/internal/bridge/state.go"));
test("locale/locale.go exists", file_exists("$fsdDir/internal/locale/locale.go"));
test("locales/en-US.yaml exists", file_exists("$fsdDir/locales/en-US.yaml"));
test("locales/fr-CA.yaml exists", file_exists("$fsdDir/locales/fr-CA.yaml"));
test("config.example.yaml exists", file_exists("$fsdDir/config.example.yaml"));
echo "\n";

// =========================================================================
// Bridge 3: EuroScope Plugin (C++)
// =========================================================================
echo "Bridge 3: EuroScope Plugin\n";
$esDir = "$root/integrations/euroscope-plugin";
test("VATSWIMPlugin.cpp exists", file_exists("$esDir/VATSWIMPlugin.cpp"));
test("VATSWIMPlugin.h exists", file_exists("$esDir/VATSWIMPlugin.h"));
test("SWIMClient.cpp exists", file_exists("$esDir/SWIMClient.cpp"));
test("SWIMClient.h exists", file_exists("$esDir/SWIMClient.h"));
test("TagItems.cpp exists", file_exists("$esDir/TagItems.cpp"));
test("TagItems.h exists", file_exists("$esDir/TagItems.h"));
test("LocaleResource.cpp exists", file_exists("$esDir/LocaleResource.cpp"));
test("LocaleResource.h exists", file_exists("$esDir/LocaleResource.h"));
test("locales/en-US.ini exists", file_exists("$esDir/locales/en-US.ini"));
test("locales/fr-CA.ini exists", file_exists("$esDir/locales/fr-CA.ini"));
test("vcxproj exists", file_exists("$esDir/VATSWIMPlugin.vcxproj"));
echo "\n";

// =========================================================================
// Bridge 4: Pilot Portal (Vue 3)
// =========================================================================
echo "Bridge 4: Pilot Portal\n";
$ppDir = "$root/integrations/pilot-portal";
test("package.json exists", file_exists("$ppDir/package.json"));
test("vite.config.js exists", file_exists("$ppDir/vite.config.js"));
test("App.vue exists", file_exists("$ppDir/src/App.vue"));
test("main.js exists", file_exists("$ppDir/src/main.js"));
test("swim.js API client exists", file_exists("$ppDir/src/api/swim.js"));
test("useWebSocket.js exists", file_exists("$ppDir/src/composables/useWebSocket.js"));
test("FlightStatus.vue exists", file_exists("$ppDir/src/components/FlightStatus.vue"));
test("TOSFiling.vue exists", file_exists("$ppDir/src/components/TOSFiling.vue"));
test("AMANSequence.vue exists", file_exists("$ppDir/src/components/AMANSequence.vue"));
test("TMIAdvisories.vue exists", file_exists("$ppDir/src/components/TMIAdvisories.vue"));
test("i18n/en-US.json exists", file_exists("$ppDir/src/i18n/en-US.json"));
test("i18n/fr-CA.json exists", file_exists("$ppDir/src/i18n/fr-CA.json"));
test("i18n/index.js exists", file_exists("$ppDir/src/i18n/index.js"));
echo "\n";

// =========================================================================
// Bridge 5: AOC Client (C/C++)
// =========================================================================
echo "Bridge 5: AOC Client\n";
$aocDir = "$root/integrations/aoc-client";
test("CMakeLists.txt exists", file_exists("$aocDir/CMakeLists.txt"));
test("main.cpp exists", file_exists("$aocDir/src/main.cpp"));
test("aoc_client.cpp exists", file_exists("$aocDir/src/aoc_client.cpp"));
test("swim_api.cpp exists", file_exists("$aocDir/src/swim_api.cpp"));
test("sim_msfs.cpp exists", file_exists("$aocDir/src/sim_msfs.cpp"));
test("sim_xplane.cpp exists", file_exists("$aocDir/src/sim_xplane.cpp"));
test("locale.cpp exists", file_exists("$aocDir/src/locale.cpp"));
test("telemetry.cpp exists", file_exists("$aocDir/src/telemetry.cpp"));
test("locales/en-US.ini exists", file_exists("$aocDir/locales/en-US.ini"));
test("locales/fr-CA.ini exists", file_exists("$aocDir/locales/fr-CA.ini"));
echo "\n";

// =========================================================================
// Summary
// =========================================================================
echo "================================\n";
echo "Results: $passed passed, $failed failed\n";
if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
echo "================================\n";
exit($failed > 0 ? 1 : 0);
