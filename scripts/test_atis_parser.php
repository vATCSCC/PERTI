<?php
/**
 * ATIS Parser Test Script
 *
 * Tests the ATIS parser with sample ATIS texts to verify correct extraction
 * of runways and approaches before running the backfill.
 *
 * Usage:
 *   php scripts/test_atis_parser.php
 *   php scripts/test_atis_parser.php --verbose
 */

require_once(__DIR__ . '/atis_parser.php');

$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

// Test cases: description => [atis_text, expected_landing, expected_departing]
$testCases = [
    // US Standard
    'US Standard LDG/DEP' => [
        'LDG RWY 27L, DEP RWY 28R',
        ['27L'],
        ['28R']
    ],

    // LAX Style - the main fix
    'LAX INST APCHS IN PROG' => [
        'INST APCHS AND RNAV RNP APCHS IN PROG RWY 24R AND RWY 25L. SIMUL INSTR DEPARTURES IN PROG RWYS 24 AND 25',
        ['24R', '25L'],
        ['24', '25']
    ],

    // Full LAX ATIS
    'LAX Full ATIS' => [
        'KLAX ATIS INFO QUEBEC 0753Z. INST APCHS AND RNAV RNP APCHS IN PROG RWY 24R AND RWY 25L. SIMUL VISUAL APCHS TO ALL RWYS ARE IN PROG. SIMUL INSTR DEPARTURES IN PROG RWYS 24 AND 25. NOTICE TO AIR MISSIONS. BIRD ACT. RWY 06L CLSD. ...ADVS YOU HAVE INFO QUEBEC',
        ['24R', '25L'],
        ['24', '25']
    ],

    // JFK Style
    'JFK Style' => [
        'LDG RWYS 4L AND 4R. DEP RWYS 31L AND 31R. EXPECT ILS RWY 4L OR RWY 4R',
        ['04L', '04R'],
        ['31L', '31R']
    ],

    // Space-separated runways
    'Space-separated' => [
        'LDG RWY 4L 4R, DEPTG RWY 31L 31R',
        ['04L', '04R'],
        ['31L', '31R']
    ],

    // Slash-separated (no space)
    'Slash-separated' => [
        'ARR DEP RWY 12L/12R',
        ['12L', '12R'],
        ['12L', '12R']
    ],

    // Heathrow style
    'Heathrow Style' => [
        'ARRIVALS RUNWAY 27L. DEPARTURES RUNWAY 27R',
        ['27L'],
        ['27R']
    ],

    // Australian bracket format
    'Australian Bracket' => [
        '[RWY] 16R ARR [RWY] 16L DEP',
        ['16R'],
        ['16L']
    ],

    // European RWY IN USE
    'European RWY IN USE' => [
        'RUNWAY IN USE 09 FOR ARRIVALS AND DEPARTURES',
        ['09'],
        ['09']
    ],

    // Mixed separators
    'Mixed Separators' => [
        'LDG RWYS 04L, 04R AND 22L. DEP RWY 22R',
        ['04L', '04R', '22L'],
        ['22R']
    ],

    // ATL style (multiple runways)
    'ATL Style' => [
        'LDG RWYS 26L 27L 28, DEP RWYS 26R 27R',
        ['26L', '27L', '28'],
        ['26R', '27R']
    ],

    // SFO style
    'SFO Style' => [
        'LDG RWYS 28L 28R, DEP RWYS 01L 01R',
        ['28L', '28R'],
        ['01L', '01R']
    ],

    // ORD SIMUL ILS
    'ORD SIMUL ILS' => [
        'SIMUL ILS APCHS IN USE RWYS 10L AND 10C',
        ['10C', '10L'],
        []
    ],

    // Canadian style
    'Canadian Style' => [
        'ARR RWY 24R AND 24L, DEP RWY 24R',
        ['24L', '24R'],
        ['24R']
    ],

    // JFK APPROACH IN USE style (RY instead of RWY)
    'JFK APPROACH IN USE' => [
        'JFK ATIS INFO O 2251Z. 08009KT 5SM RA BR BKN007 OVC015 05/04 A3002 (THREE ZERO ZERO TWO). APPROACH IN USE ILS RY 4R, ILS 4L. DEPTG RY 4L.. NOTAMS... READBACK ALL RWY ASSIGNMENTS',
        ['04L', '04R'],
        ['04L']
    ],
];

echo "=== ATIS Parser Test Suite ===\n\n";

$passed = 0;
$failed = 0;

foreach ($testCases as $name => $test) {
    list($atisText, $expectedLanding, $expectedDeparting) = $test;

    $result = parseAtisRunways($atisText);

    // Sort for comparison
    sort($result['landing']);
    sort($result['departing']);
    sort($expectedLanding);
    sort($expectedDeparting);

    $landingMatch = $result['landing'] === $expectedLanding;
    $departingMatch = $result['departing'] === $expectedDeparting;
    $testPassed = $landingMatch && $departingMatch;

    if ($testPassed) {
        $passed++;
        echo "✓ PASS: $name\n";
        if ($verbose) {
            echo "  Landing: " . implode(', ', $result['landing']) . "\n";
            echo "  Departing: " . implode(', ', $result['departing']) . "\n";
            if (!empty($result['approaches'])) {
                echo "  Approaches: " . json_encode($result['approaches']) . "\n";
            }
        }
    } else {
        $failed++;
        echo "✗ FAIL: $name\n";
        echo "  ATIS: $atisText\n";
        if (!$landingMatch) {
            echo "  Landing:   got [" . implode(', ', $result['landing']) . "]\n";
            echo "             expected [" . implode(', ', $expectedLanding) . "]\n";
        }
        if (!$departingMatch) {
            echo "  Departing: got [" . implode(', ', $result['departing']) . "]\n";
            echo "             expected [" . implode(', ', $expectedDeparting) . "]\n";
        }
    }
    echo "\n";
}

echo "=== Results ===\n";
echo "Passed: $passed / " . count($testCases) . "\n";
echo "Failed: $failed / " . count($testCases) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed! Safe to run backfill.\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed. Review parser before backfill.\n";
    exit(1);
}
