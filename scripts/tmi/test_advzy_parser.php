<?php
/**
 * ADVZY Parser Test - Tests parseAdvzyFile() with real data samples.
 * Run: php test_advzy_parser.php
 */

require_once __DIR__ . '/../../load/perti_constants.php';
require_once __DIR__ . '/advzy_parser.php';

echo "=== ADVZY Parser Tests ===\n\n";

$sampleAdvzy = <<<'ADVZY'
Jeremy P | ZNY C1 — 03/28/2020 20:31
vATCSCC ADVZY 001 ZDC 03/29/2020 CDM GROUND STOP
CTL ELEMENT: DCA
ELEMENT TYPE: APT
ADL TIME: 0031Z
GROUND STOP PERIOD: 29/0030Z - 29/0115Z
CUMULATIVE PROGRAM PERIOD: 23/0026Z - 23/0115Z
FLT INCL: ZNY DEPARTURES TO DCA
ADDITIONAL DEP FACILITIES INCLUDED:
CURRENT TOTAL, MAXIMUM, AVERAGE DELAYS: 90/45/15
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS:
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 90/45/15
PROBABILITY OF EXTENSION: MEDIUM
IMPACTING CONDITION: VOLUME / VOLUME
COMMENTS:

290030-290115
20/03/29 00:31

Jeremy P | ZNY C1 — 03/28/2020 21:01
vATCSCC ADVZY 003 DCA 03/29/2020 CDM GS CNX
CTL ELEMENT: DCA
ELEMENT TYPE: APT
ADL TIME: 0100Z
GS CNX PERIOD: 29/0026Z - 29/0100Z
FLIHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP:
COMMENTS: STOP WAVEY UNTIL 0145Z
290026-290100
20/03/29 01:00
Jeremy P | ZNY C1 — 04/17/2020 19:10
vATCSCC ADVZY 002 BOS/ZBW 04/17/2020 CDM GROUND DELAY PROGRAM
CTL ELEMENT: BOS
ELEMENT TYPE: APT
ADL TIME: 2306Z
DELAY ASSIGNMENT MODE: DAS
ARRIVALS ESTIMATED FOR: 17/2306Z - 18/0300Z
CUMULATIVE PROGRAM PERIOD: 17/2306Z - 18/0300Z
PROGRAM RATE: 28
POP-UP FACTOR: HIGH
FLT INCL: 1stTier+Canada
DEPARTURE SCOPE: (1stTier)
DELAY ASSIGNMENT TABLE APPLIES TO: ZNY/ZOB/ZDC/CZY
DELAY LIMIT: 600
MAXIMUM DELAY: 600
AVERAGE DELAY: 75
IMPACTING CONDITION: VOLUME / VOLUME
COMMENTS: THE GDP ENCOMPASSES THE PRECOORDINATED RELEASES PER HOUR REQUESTED
          BY ZBW. THIS ADVZY IS FOR RECORD-KEEPING PURPOSES ONLY.
172306-180300
20/04/17 23:06

Jeremy P | ZNY C1 — 02/28/2020 17:07
vATCSCC ADVZY 001 DCC 02/28/2020 ROUTE RQD
NAME: C90_TO_MSP
IMPACTED AREA: ZAU
REASON: OTHER
INCLUDE TRAFFIC: KORD/KMDW DEPARTURES TO KMSP
VALID: ETD 290030 TO 290500
FACILITIES INCLUDED: ZAU/ZMP
PROBABILITY OF EXTENSION: LOW
REMARKS:
ASSOCIATED RESTRICTIONS:
MODIFICATIONS:
ROUTE:
ORIG    DEST    ROUTE
----    ----    -----
ORD     MSP     >PMPKN NEATO DLLAN RONIC KAMMA< KKILR3
MDW     MSP     >PEKUE OBENE MONNY MNOSO< BLUEM3
ORD     MSP     >BAE< EAU9 (NON-RNAV TYPE:PROPS)
MDW     MSP     >PLL DBQ ALO< KASPR7 (NON-RNAV TYPE:PROPS)

TMI ID: RRDCC001
290030-290500
20/02/28 22:06

Jeremy P | ZNY C1 — 04/02/2020 18:18
vATCSCC ADVZY 001 DCC 04/02/2020 OPERATIONS PLAN
EVENT TIME: 021500 - AND LATER
_________________________________________________________________________
THESE ARE THE TRAFFIC MANAGEMENT INITIATIVES
DISCUSSED ALREADY IN ORDER TO MANAGE CTP AND
NON-CTP TRAFFIC.
_________________________________________________________________________

TERMINAL ACTIVE:
NONE

021500-AND LATER
20/04/02 22:17

Jeremy P | ZNY C1 — 04/18/2020 15:27
vATCSCC ADVZY 004 DCC 04/18/2020 ROUTE FYI
NAME: NE_TO_ATL_PARTIAL
IMPACTED AREA: ZNY
REASON: VOLUME
INCLUDE TRAFFIC: KJFK/KEWR/KLGA DEPARTURES TO KATL
VALID: ETD 182300 TO 190300
FACILITIES INCLUDED: ZDC/ZJX/ZNY/ZTL
PROBABILITY OF EXTENSION: LOW
REMARKS: WILL BE MADE RECOMMENDED WHEN J48 MIT EXCEEDS 30
ASSOCIATED RESTRICTIONS:
MODIFICATIONS:
ROUTE:
ORIG    DEST    ROUTE
----    ----    -----
JFK     ATL     WAVEY EMJAY J174 ORF J121 BARTL KAATT
                Q172 YUTEE SKWKR JJEDI2
EWR LGA ATL     WHITE J209 SBY J79 KATZN J193 WEAVR
                J121 BARTL KAATT Q172 YUTEE SKWKR JJEDI2

TMI ID: RRDCC004
182300-190300
20/04/18 19:26
Jeremy P | ZNY C1 — 01/01/2022 20:03
vATCSCC ADVZY 002 LGA/ZNY 01/02/2022 CDM GROUND STOP
CTL ELEMENT: LGA
ELEMENT TYPE: APT
ADL TIME: 0102Z
GROUND STOP PERIOD: 02/0102Z - 02/0130Z
FLT INCL: ALL_FLIGHTS
DEP FACILITIES INCLUDED: (Manual) ZNY ZDC
IMPACTING CONDITION: VOLUME / VOLUME
COMMENTS:

020102 - 020130
22/01/02 01:02
Dean V | ZHU DATM

 — 02/07/2026 19:03
vATCSCC ADVZY 001 DCC 02/08/2026 HOTLINE ACTIVATION
EVENT TIME: 07/2359Z - 08/0400Z
CONSTRAINED FACILITIES: ZOA/ZLA/ZLC/ZSE

THE WEST COAST HOTLINE IS BEING ACTIVATED TO ADDRESS VOLUME.

072359-080400
26/02/08 00:03 DV/KK

Zackaria | ZTL I1 — 02/07/2026 20:08
vATCSCC ADVZY 002 SJC/ZOA CDM GROUND STOP
CTL ELEMENT: SJC
ELEMENT TYPE: APT
ADL TIME: 0107Z
GROUND STOP PERIOD: 08/0107Z - 08/0200Z
FLT INCL: (MANUAL) ZLA
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: (1343, 53, 49)
PROBABILITY OF EXTENSION: HIGH
IMPACTING CONDITION: VOLUME/VOLUME
COMMENTS:

EFFECTIVE TIME: 080107 - 080200
SIGNATURE: 26/02/08 01:07
ADVZY;

$entries = parseAdvzyFile($sampleAdvzy);

// Define expected results
$expected = [
    ['type' => 'GS',      'ctl' => 'DCA', 'adv' => 'ADVZY 001', 'fac' => 'ZDC'],
    ['type' => 'CNX',     'ctl' => 'DCA', 'adv' => 'ADVZY 003', 'fac' => 'DCA'],
    ['type' => 'GDP',     'ctl' => 'BOS', 'adv' => 'ADVZY 002', 'rate' => 28],
    ['type' => 'REROUTE', 'ctl' => 'MSP', 'adv' => 'ADVZY 001', 'name' => 'C90_TO_MSP'],
    ['type' => 'ATCSCC',  'ctl' => null,  'adv' => 'ADVZY 001', 'fac' => 'DCC'],
    ['type' => 'REROUTE', 'ctl' => 'ATL', 'adv' => 'ADVZY 004', 'name' => 'NE_TO_ATL_PARTIAL'],
    ['type' => 'GS',      'ctl' => 'LGA', 'adv' => 'ADVZY 002', 'fac' => 'LGA/ZNY'],
    ['type' => 'ATCSCC',  'ctl' => null,  'adv' => 'ADVZY 001', 'fac' => 'DCC'],
    ['type' => 'GS',      'ctl' => 'SJC', 'adv' => 'ADVZY 002', 'fac' => 'SJC/ZOA'],
];

$passed = 0;
$failed = 0;

echo "Parsed " . count($entries) . " entries (expected " . count($expected) . ")\n\n";

for ($i = 0; $i < max(count($entries), count($expected)); $i++) {
    $e = $entries[$i] ?? null;
    $exp = $expected[$i] ?? null;

    if (!$e && $exp) {
        echo "  FAIL [{$i}] Missing entry - expected {$exp['type']} {$exp['ctl']}\n";
        $failed++;
        continue;
    }
    if ($e && !$exp) {
        echo "  EXTRA [{$i}] type={$e['_type']} ctl={$e['ctl_element']} adv={$e['advisory_number']}\n";
        continue;
    }

    $typeOk = ($e['_type'] === $exp['type']);
    $ctlOk = ($exp['ctl'] === null || $e['ctl_element'] === $exp['ctl']);
    $advOk = ($e['advisory_number'] === $exp['adv']);
    $extraOk = true;

    if (isset($exp['rate'])) $extraOk = ($e['program_rate'] == $exp['rate']);
    if (isset($exp['name'])) $extraOk = ($e['route_name'] === $exp['name']);
    if (isset($exp['fac'])) $extraOk = ($e['_advzy_facility'] === $exp['fac']);

    $ok = $typeOk && $ctlOk && $advOk && $extraOk;

    if ($ok) {
        echo "  PASS [{$i}] {$e['_type']} {$e['ctl_element']}";
        if ($e['advisory_number']) echo " {$e['advisory_number']}";
        if ($e['route_name']) echo " \"{$e['route_name']}\"";
        if ($e['start_utc']) echo " {$e['start_utc']}";
        echo "\n";
        $passed++;
    } else {
        echo "  FAIL [{$i}]";
        if (!$typeOk) echo " type: exp={$exp['type']} got={$e['_type']}";
        if (!$ctlOk) echo " ctl: exp={$exp['ctl']} got={$e['ctl_element']}";
        if (!$advOk) echo " adv: exp={$exp['adv']} got={$e['advisory_number']}";
        if (!$extraOk) echo " extra_check_failed";
        echo "\n         raw: " . substr($e['_raw'], 0, 80) . "\n";
        $failed++;
    }
}

echo "\n=== ADVZY Parser: {$passed} passed, {$failed} failed ===\n";

// Detail checks
echo "\n--- Detail Checks ---\n";

// Check GS delays parsed
$gs = $entries[0] ?? null;
if ($gs) {
    $hasDelays = !empty($gs['_delays']);
    echo "  GS delays: " . ($hasDelays ? "total={$gs['_delays']['total']} max={$gs['_delays']['max']} avg={$gs['_delays']['avg']}" : "MISSING") . "\n";
}

// Check GDP fields
$gdp = $entries[2] ?? null;
if ($gdp) {
    echo "  GDP rate={$gdp['program_rate']} delay_limit={$gdp['delay_limit_min']}";
    echo " scope_centers=" . ($gdp['scope_centers'] ? implode(',', $gdp['scope_centers']) : 'NULL');
    echo " impacting={$gdp['impacting_condition']}\n";
}

// Check route parsing
$route = $entries[3] ?? null;
if ($route) {
    $numRoutes = count($route['_routes'] ?? []);
    echo "  Route '{$route['route_name']}': {$numRoutes} routes";
    echo " traffic={$route['traffic_from']}->{$route['traffic_to']}";
    echo " valid={$route['start_utc']} to {$route['end_utc']}\n";
    if ($route['_routes']) {
        foreach ($route['_routes'] as $r) {
            echo "    {$r['orig']} -> {$r['dest']}: " . substr($r['route'], 0, 50) . "\n";
        }
    }
}

// Check multi-line route continuation
$route2 = $entries[5] ?? null;
if ($route2 && !empty($route2['_routes'])) {
    $r0 = $route2['_routes'][0] ?? null;
    $hasContinuation = $r0 && strpos($r0['route'], 'Q172') !== false;
    echo "  Multi-line route join: " . ($hasContinuation ? 'PASS' : 'FAIL');
    echo " (" . substr($r0['route'] ?? '', 0, 60) . "...)\n";
}

// Check split header (2026 hotline)
$hotline = $entries[7] ?? null;
if ($hotline) {
    echo "  Split header author: " . ($hotline['_ntml_author'] ?? 'NULL') . "\n";
    echo "  HOTLINE start_utc: " . ($hotline['start_utc'] ?? 'NULL') . "\n";
}

// Check EFFECTIVE TIME footer (2026 GS)
$gs2026 = $entries[8] ?? null;
if ($gs2026) {
    echo "  2026 GS start_utc: " . ($gs2026['start_utc'] ?? 'NULL');
    echo " signature: " . ($gs2026['_entry_timestamp'] ?? 'NULL') . "\n";
}

// Check CNX
$cnx = $entries[1] ?? null;
if ($cnx) {
    echo "  CNX comments: " . ($cnx['comments'] ?? 'NULL') . "\n";
}

exit($failed > 0 ? 1 : 0);
