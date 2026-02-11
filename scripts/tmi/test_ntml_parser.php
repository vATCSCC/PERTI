<?php
/**
 * NTML Parser Test - Tests parseNtmlFile() with real data samples.
 * Run: php test_ntml_parser.php
 */

require_once __DIR__ . '/../../load/perti_constants.php';
require_once __DIR__ . '/ntml_parser.php';

echo "=== NTML Parser Tests ===\n\n";

// Sample NTML file content mimicking real data
$sampleNtml = <<<'NTML'
Jeremy P | ZNY C1 — 04/17/2020 19:45
17/2344    BOS via MERIT 15MIT VOLUME:VOLUME EXCL:NONE 2345-0000 ZBW:ZNY
17/2349    BOS STOP VOLUME:VOLUME EXCL:NONE 2345-0015 ZNY:PHL
17/2350    BOS 8MINIT VOLUME:VOLUME EXCL:NONE 2330-0300 ZBW:CZY
Jeremy P | ZNY C1 — 04/17/2020 20:11
18/0010     D/D from JFK, +45/0010 VOLUME:VOLUME
18/0012    BOS STOP VOLUME:VOLUME EXCL:NONE 0000-0100 ZBW:CZY
Jeremy P | ZNY C1 — 04/17/2020 20:19
18/0019    ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME
Jeremy P | ZNY C1 — 04/17/2020 20:39
18/0039    BOS via MERIT 20MIT VOLUME:VOLUME EXCL:NONE 0030-0300 ZBW:ZNY
18/0040    CFR BOS departures  VOLUME:VOLUME EXCL:NONE 0045-0300 ZNY:N90,JFK,EWR,LGA,PHL
18/0043    BOS departures 40MIT VOLUME:VOLUME EXCL:NONE 0045-0300 ZDC:PCT
Jeremy P | ZNY C1 — 04/18/2020 18:06
18/2206    ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2230-0400 ZTL:ZJX,ZME,ZID,ZHU
Jeremy P | ZNY C1 — 04/18/2020 18:21
18/2221    ATL    VMC    ARR:26R/27L/28 DEP:26L/27R    AAR(Strat):132    ADR:70
Jeremy P | ZNY C1 — 04/24/2020 19:05
24/2305    MIA via CIGAR 35MIT VOLUME:VOLUME EXCL:NONE 2300-0400 ZMA:ZJX30
24/2313    MIA 30MIT PER AIRPORT VOLUME:VOLUME EXCL:NONE 2300-0400 ZNY:N90,PHL,EWR,JFK,LGA,ISP
Jeremy P | ZNY C1 — 04/18/2020 19:33
18/2338    APREQ ATL departures via BOBZY VOLUME:VOLUME EXCL:NONE 2330-0100 ZTL:CLT
Jeremy P | ZNY C1 — 04/18/2020 19:50
18/2355    ATL via JAX DEPARTURES  ALT:AOB300 VOLUME:VOLUME EXCL:NONE 0000-0400 ZTL:ZJX
Jeremy P | ZNY C1 — 04/24/2020 20:53
25/0059    ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME
25/0114    MIA STOP RUNWAY:CONFIG CHG EXCL:NONE 0115-0130 ZMA:ZJX
Jeremy P | ZNY C1 — 04/24/2020 21:22
25/0121     D/D from ATL, +30/0115 VOLUME:VOLUME
NTML;

// Add 2024+ format samples with split headers, multi-line, bot codes
$sampleNtml .= <<<'NTML'

Joshua D | ZLA C1

 — 02/10/2024 16:08
10/2108    LAS via TYEGR 35MIT NO STACKS,
SINGLE STREAM EXCL:NONE VOLUME:SUPER BOWL 2359-0400 ZLA:ZDV $ 05B01A
disregard bot^
MIT / MINIT
APP
 — 02/16/2024 17:14
16/2214    KDFW    VMC    ARR:36L/35R DEP:36R/35C    AAR(Strat):80 ADR:96 $ 01A00A
Dean V | ZHU EC — 02/16/2024 18:12
16/2312    HOU via ALL 20MIT PER STREAM EXCL:PROPS VOLUME:VOLUME 2359-0400 ZHU:ZFW $ 05B01A

Joshua D | ZLA C1

 — 02/10/2024 22:35
11/0330  LAS CANCEL ALL MIT ZLA:ZOA
Brody B | ZLA EC — 02/07/2026 18:52
07/2330    LAX via BURGL, REBRG 15 MIT AS ONE 0000-0400 ZLA:ZOA
07/2330    SAN via ALL 25 MIT 0000-0400 ZLA:ZOA
07/2330    ALL EXCL LAX, LAS, SAN via ALL 20 MIT PER STREAM 0000-0400 ZLA:ZOA, ZLC, ZDV, ZAB
Matt B | ZJX C3 — 02/09/2024 18:29
09/2345 JAX via DUCHY, ICONS 20 MIT JETS 2345-0330 ZJX:ZTL,CLT
09/2359 APREQ JAX to PNS, MYR, DAB 2359-0308 ZJX:JAX
10/0313 CANCEL ALL TMI ZJX: ZTL, ZDC, ZHU, ZMA
Zackaria | ZTL I1 — 02/07/2026 19:42
08/0042   CFR LAS to SJC VOLUME:VOLUME 0042-0230 ZOA:ZLA
Jeremy P | ZNY C1 — 04/24/2020 20:53
25/0052    MIA    LVMC    ARR:26R/30 DEP:26L/27    AAR(Strat):60    ADR:72
NTML;

$entries = parseNtmlFile($sampleNtml);

// Define expected results: [type, ctl_element, key_check_field, key_check_value]
$expected = [
    ['MIT',     'BOS',  'restriction_value', 15,    'mit_fix', 'MERIT'],
    ['STOP',    'BOS',  'impacting_condition', 'VOLUME', null, null],
    ['MINIT',   'BOS',  'restriction_value', 8,     null, null],
    ['DD',      'JFK',  null, null,                  null, null],
    ['STOP',    'BOS',  null, null,                  null, null],
    ['ED',      'BOS',  null, null,                  null, null],
    ['MIT',     'BOS',  'restriction_value', 20,    'mit_fix', 'MERIT'],
    ['CFR',     'BOS',  null, null,                  null, null],
    ['MIT',     'BOS',  'restriction_value', 40,    null, null],
    ['TBM',     'ATL',  null, null,                  null, null],
    ['CONFIG',  'ATL',  null, null,                  null, null],
    ['MIT',     'MIA',  'restriction_value', 35,    'mit_fix', 'CIGAR'],
    ['MIT',     'MIA',  'restriction_value', 30,    null, null],
    ['APREQ',   'ATL',  'mit_fix', 'BOBZY',         null, null],
    ['MIT',     'ATL',  null, null,                  null, null],
    ['AD',      'MIA',  null, null,                  null, null],
    ['STOP',    'MIA',  'impacting_condition', 'RUNWAY', null, null],
    ['DD',      'ATL',  null, null,                  null, null],
    // 2024+ entries
    ['MIT',     'LAS',  'restriction_value', 35,    'cause_text', 'SUPER BOWL'],
    ['CONFIG',  'KDFW', null, null,                  null, null],
    ['MIT',     'HOU',  'restriction_value', 20,    'exclusions', 'PROPS'],
    ['CANCEL',  'LAS',  null, null,                  null, null],
    // 2026 entries
    ['MIT',     'LAX',  'restriction_value', 15,    'mit_fix', 'BURGL, REBRG'],
    ['MIT',     'SAN',  'restriction_value', 25,    null, null],
    ['MIT',     'ALL',  'restriction_value', 20,    null, null],
    ['MIT',     'JAX',  'restriction_value', 20,    'mit_fix', 'DUCHY, ICONS'],
    ['APREQ',   'JAX',  null, null,                  null, null],
    ['CANCEL',  null,   null, null,                  null, null],
    ['CFR',     'LAS',  null, null,                  null, null],
    ['CONFIG',  'MIA',  null, null,                  null, null],
];

$passed = 0;
$failed = 0;

echo "Parsed " . count($entries) . " entries (expected " . count($expected) . ")\n\n";

for ($i = 0; $i < max(count($entries), count($expected)); $i++) {
    $e = $entries[$i] ?? null;
    $exp = $expected[$i] ?? null;

    if (!$e && $exp) {
        echo "  FAIL [{$i}] Missing entry - expected {$exp[0]} {$exp[1]}\n";
        $failed++;
        continue;
    }
    if ($e && !$exp) {
        echo "  EXTRA [{$i}] type={$e['_type']} ctl={$e['ctl_element']} raw={$e['_raw']}\n";
        continue;
    }

    $typeOk = ($e['_type'] === $exp[0]);
    $ctlOk = ($exp[1] === null || $e['ctl_element'] === $exp[1]);
    $field1Ok = ($exp[2] === null || ($e[$exp[2]] ?? null) == $exp[3]);
    $field2Ok = ($exp[4] === null || ($e[$exp[4]] ?? null) == $exp[5]);
    $ok = $typeOk && $ctlOk && $field1Ok && $field2Ok;

    if ($ok) {
        echo "  PASS [{$i}] {$e['_type']} {$e['ctl_element']}";
        if ($e['restriction_value'] ?? null) echo " {$e['restriction_value']}{$e['restriction_unit']}";
        if ($e['mit_fix'] ?? null) echo " via {$e['mit_fix']}";
        if ($e['start_utc'] ?? null) echo " {$e['start_utc']}";
        if ($e['requesting_facility'] ?? null) echo " {$e['requesting_facility']}:{$e['providing_facility']}";
        echo "\n";
        $passed++;
    } else {
        echo "  FAIL [{$i}]";
        if (!$typeOk) echo " type: exp={$exp[0]} got={$e['_type']}";
        if (!$ctlOk) echo " ctl: exp={$exp[1]} got={$e['ctl_element']}";
        if (!$field1Ok) echo " {$exp[2]}: exp={$exp[3]} got=" . ($e[$exp[2]] ?? 'NULL');
        if (!$field2Ok) echo " {$exp[4]}: exp={$exp[5]} got=" . ($e[$exp[4]] ?? 'NULL');
        echo "\n         raw: {$e['_raw']}\n";
        $failed++;
    }
}

echo "\n=== NTML Parser: {$passed} passed, {$failed} failed ===\n";

// Also test specific parsing details
echo "\n--- Detail Checks ---\n";

// Check multi-line continuation joined correctly
$multiLine = $entries[18] ?? null; // LAS via TYEGR 35MIT NO STACKS, SINGLE STREAM...
if ($multiLine) {
    $hasNoStacks = $multiLine['qualifiers'] && in_array('NO STACKS', $multiLine['qualifiers']);
    $hasSingleStream = $multiLine['qualifiers'] && in_array('SINGLE STREAM', $multiLine['qualifiers']);
    echo "  Multi-line join: NO STACKS=" . ($hasNoStacks ? 'YES' : 'NO')
        . " SINGLE STREAM=" . ($hasSingleStream ? 'YES' : 'NO') . "\n";
}

// Check date resolution (header 04/17/2020, entry day 18 -> 04/18/2020)
$dayRollover = $entries[3] ?? null; // D/D from JFK, day 18
if ($dayRollover && $dayRollover['_entry_timestamp']) {
    $dateOk = str_starts_with($dayRollover['_entry_timestamp'], '2020-04-18');
    echo "  Date rollover (day 18 under 04/17 header): " . ($dateOk ? 'PASS' : 'FAIL')
        . " -> {$dayRollover['_entry_timestamp']}\n";
}

// Check CONFIG parsing
$config = $entries[10] ?? null; // ATL VMC
if ($config && isset($config['_config'])) {
    $c = $config['_config'];
    echo "  CONFIG parse: weather={$c['weather']} arr={$c['arr_rwys']} dep={$c['dep_rwys']}"
        . " aar={$c['aar']}({$c['aar_type']}) adr={$c['adr']}\n";
}

// Check D/D parsing
$dd = $entries[3] ?? null;
if ($dd && isset($dd['_delay'])) {
    $d = $dd['_delay'];
    echo "  D/D parse: dir={$d['direction']} val={$d['value']} at={$d['measured_at']}\n";
}

// Check A/D parsing
$ad = $entries[15] ?? null;
if ($ad && isset($ad['_delay'])) {
    $d = $ad['_delay'];
    echo "  A/D parse: dir={$d['direction']} val={$d['value']} navaid={$d['navaid']}\n";
}

// Check noise filtering (disregard bot^, MIT / MINIT, APP should be skipped)
echo "  Noise filtered: " . count($entries) . " entries (noise lines skipped)\n";

exit($failed > 0 ? 1 : 0);
