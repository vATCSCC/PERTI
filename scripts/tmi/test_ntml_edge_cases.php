<?php
/**
 * NTML Edge Case Validation Test Script
 * Tests parsing and formatting for complex/edge case scenarios
 * 
 * Usage: php test_ntml_edge_cases.php
 */

echo "=== NTML Edge Case Validation Tests ===\n";
echo "Date: " . date('Y-m-d H:i:s') . " UTC\n\n";

// Test Cases organized by complexity

$edgeCases = [
    
    // =============================================
    // MULTIPLE AIRPORTS
    // =============================================
    [
        'category' => 'Multiple Airports',
        'tests' => [
            [
                'name' => 'Multiple destination airports',
                'input' => 'STOP MIA,FLL,RSW VOLUME:VOLUME EXCL:NONE 2100-0300 ZMA:F11',
                'expected_type' => 'STOP',
                'expected_condition' => 'MIA,FLL,RSW',
                'validate' => function($parsed) {
                    return strpos($parsed['condition'], 'MIA') !== false &&
                           strpos($parsed['condition'], 'FLL') !== false &&
                           strpos($parsed['condition'], 'RSW') !== false;
                }
            ],
            [
                'name' => 'Multiple airports with spaces',
                'input' => 'CFR MIA, FLL departures TYPE:ALL VOLUME:VOLUME 2100-0400 ZMA:F11',
                'expected_type' => 'CFR',
                'validate' => function($parsed) {
                    return strpos($parsed['condition'], 'MIA') !== false;
                }
            ],
            [
                'name' => 'Three NY Metro airports',
                'input' => '15MIT EWR,LGA,JFK via BIGGY VOLUME:VOLUME EXCL:NONE 2200-0400 ZNY:N90',
                'expected_type' => 'MIT',
                'expected_condition' => 'EWR,LGA,JFK',
                'expected_via' => 'BIGGY'
            ]
        ]
    ],
    
    // =============================================
    // MULTIPLE FIXES
    // =============================================
    [
        'category' => 'Multiple Fixes',
        'tests' => [
            [
                'name' => 'Multiple via fixes',
                'input' => '20MIT ATL via CHPPR,GLAVN VOLUME:VOLUME EXCL:NONE 2100-0300 ZTL:A80',
                'expected_type' => 'MIT',
                'expected_via' => 'CHPPR,GLAVN'
            ],
            [
                'name' => 'Airway as via',
                'input' => '25MIT LGA via J146 NO STACKS VOLUME:VOLUME EXCL:NONE 2200-0300 ZNY:ZOB',
                'expected_type' => 'MIT',
                'expected_via' => 'J146'
            ],
            [
                'name' => 'Fix without via keyword',
                'input' => 'JFK LENDY 20MIT VOLUME:VOLUME EXCL:NONE 2300-0300 ZNY:ZBW',
                'expected_type' => 'MIT',
                'expected_condition' => 'JFK',
                'expected_via' => 'LENDY'
            ]
        ]
    ],
    
    // =============================================
    // COMPLEX QUALIFIERS
    // =============================================
    [
        'category' => 'Complex Qualifiers',
        'tests' => [
            [
                'name' => 'NO STACKS qualifier',
                'input' => '20MIT JFK via CAMRN NO STACKS VOLUME:VOLUME EXCL:NONE 2200-0300 ZNY:ZBW',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return isset($parsed['qualifiers']) && 
                           in_array('NO_STACKS', $parsed['qualifiers']);
                }
            ],
            [
                'name' => 'PER AIRPORT qualifier',
                'input' => '15MIT EWR,LGA departures via BIGGY PER AIRPORT VOLUME:VOLUME EXCL:NONE 0030-0145 N90:ZNY',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return isset($parsed['qualifiers']) && 
                           (in_array('PER_AIRPORT', $parsed['qualifiers']) || 
                            in_array('PER AIRPORT', $parsed['qualifiers']));
                }
            ],
            [
                'name' => 'Multiple qualifiers',
                'input' => '20MIT BOS via MERIT NO STACKS TYPE:JETS VOLUME:VOLUME EXCL:PROPS 2345-0000 ZBW:ZNY',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    $hasNoStacks = isset($parsed['qualifiers']) && 
                                   in_array('NO_STACKS', $parsed['qualifiers']);
                    $hasTypeJets = isset($parsed['aircraft_type']) && 
                                   strtoupper($parsed['aircraft_type']) === 'JETS';
                    return $hasNoStacks || $hasTypeJets;
                }
            ],
            [
                'name' => 'RALT (Runway Alternating) qualifier',
                'input' => '30MIT ORD via PLANO RALT VOLUME:VOLUME EXCL:NONE 2100-0300 ZAU:C90',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return isset($parsed['qualifiers']) && 
                           in_array('RALT', $parsed['qualifiers']);
                }
            ]
        ]
    ],
    
    // =============================================
    // HOLDING PATTERNS
    // =============================================
    [
        'category' => 'Holding Patterns',
        'tests' => [
            [
                'name' => 'E/D entering holding',
                'input' => 'ZDC E/D for BOS, +Holding/0019/13 ACFT NAVAID:DEALE VOLUME:VOLUME',
                'expected_type' => 'HOLDING',
                'validate' => function($parsed) {
                    return $parsed['delayType'] === 'ED' && 
                           $parsed['isHolding'] === true;
                }
            ],
            [
                'name' => 'A/D with holding and navaid',
                'input' => 'ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME',
                'expected_type' => 'HOLDING',
                'validate' => function($parsed) {
                    return $parsed['delayType'] === 'AD' && 
                           $parsed['isHolding'] === true &&
                           $parsed['navaid'] === 'OMN';
                }
            ],
            [
                'name' => 'D/D with LATE NOTE',
                'input' => 'D/D from PHL, -60/0215/10 ACFT LATE NOTE VOLUME:VOLUME',
                'expected_type' => 'HOLDING',
                'validate' => function($parsed) {
                    return $parsed['delayType'] === 'DD';
                }
            ],
            [
                'name' => 'Holding terminating',
                'input' => 'ZDC A/D to IAD, -Holding/0330/5 ACFT VOLUME:VOLUME',
                'expected_type' => 'HOLDING',
                'validate' => function($parsed) {
                    return $parsed['delayChange'] === 'terminating' ||
                           $parsed['delayChange'] === 'decreasing';
                }
            ]
        ]
    ],
    
    // =============================================
    // CONFIG EDGE CASES
    // =============================================
    [
        'category' => 'Config Edge Cases',
        'tests' => [
            [
                'name' => 'Config with ILS and VAP approach types',
                'input' => 'JFK VMC ARR:ILS_31R_VAP_31L DEP:31L AAR(Strat):58 ADR:24',
                'expected_type' => 'CONFIG',
                'validate' => function($parsed) {
                    return strpos($parsed['arrRunways'], 'ILS_31R') !== false;
                }
            ],
            [
                'name' => 'Config with LOC approach',
                'input' => 'LGA VMC ARR:LOC_31 DEP:31 AAR(Strat):30 ADR:32',
                'expected_type' => 'CONFIG',
                'validate' => function($parsed) {
                    return strpos($parsed['arrRunways'], 'LOC') !== false;
                }
            ],
            [
                'name' => 'Config with RNAV approach',
                'input' => 'EWR VMC ARR:04R/RNAV_X_29 DEP:04L AAR(Strat):40 ADR:38',
                'expected_type' => 'CONFIG',
                'validate' => function($parsed) {
                    return strpos($parsed['arrRunways'], 'RNAV') !== false;
                }
            ],
            [
                'name' => 'IMC with AAR Adjustment',
                'input' => 'PHL IMC ARR:27R DEP:27L/35 AAR(Dyn):36 AAR Adjustment:XW-TLWD ADR:28',
                'expected_type' => 'CONFIG',
                'validate' => function($parsed) {
                    return $parsed['weather'] === 'IMC' && 
                           !empty($parsed['aarAdjustment']);
                }
            ],
            [
                'name' => 'Multiple runways',
                'input' => 'ATL VMC ARR:26R/27L/28 DEP:26L/27R AAR(Strat):132 ADR:70',
                'expected_type' => 'CONFIG',
                'validate' => function($parsed) {
                    return strpos($parsed['arrRunways'], '26R') !== false &&
                           strpos($parsed['arrRunways'], '27L') !== false;
                }
            ]
        ]
    ],
    
    // =============================================
    // EXCLUSIONS
    // =============================================
    [
        'category' => 'Exclusions',
        'tests' => [
            [
                'name' => 'EXCL:PROPS',
                'input' => '20MIT BOS via MERIT VOLUME:VOLUME EXCL:PROPS 2345-0000 ZBW:ZNY',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return strtoupper($parsed['exclusions']) === 'PROPS';
                }
            ],
            [
                'name' => 'EXCL:DIVERSIONS',
                'input' => '15MIT JFK via CAMRN VOLUME:VOLUME EXCL:DIVERSIONS 2200-0300 ZNY:ZBW',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return strtoupper($parsed['exclusions']) === 'DIVERSIONS';
                }
            ],
            [
                'name' => 'EXCL with facility',
                'input' => '20MIT LGA via J146 VOLUME:VOLUME EXCL:PHL,EWR 2200-0300 ZNY:ZOB',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return strpos($parsed['exclusions'], 'PHL') !== false;
                }
            ]
        ]
    ],
    
    // =============================================
    // REASON VARIATIONS
    // =============================================
    [
        'category' => 'Reason Variations',
        'tests' => [
            [
                'name' => 'WEATHER:THUNDERSTORMS',
                'input' => '30MIT DFW via TURKI WEATHER:THUNDERSTORMS EXCL:NONE 2100-0300 ZFW:D10',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return strtoupper($parsed['reason']) === 'WEATHER';
                }
            ],
            [
                'name' => 'VOLUME:FNO (Friday Night Ops)',
                'input' => '25MIT JFK via CAMRN VOLUME:FNO EXCL:NONE 2300-0400 ZNY:ZBW',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return strtoupper($parsed['reason']) === 'VOLUME';
                }
            ],
            [
                'name' => 'RUNWAY:CONFIG CHG',
                'input' => '15MIT ORD via PLANO RUNWAY:CONFIG CHG EXCL:NONE 2200-2300 ZAU:C90',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return strtoupper($parsed['reason']) === 'RUNWAY';
                }
            ]
        ]
    ],
    
    // =============================================
    // TBM VARIATIONS
    // =============================================
    [
        'category' => 'TBM Variations',
        'tests' => [
            [
                'name' => 'TBM with sector',
                'input' => 'ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2100-0300 A80:ZTL',
                'expected_type' => 'TBM',
                'validate' => function($parsed) {
                    return $parsed['sector'] === '3_WEST';
                }
            ],
            [
                'name' => 'TBM without sector',
                'input' => 'DFW TBM VOLUME:VOLUME EXCL:NONE 2200-0200 D10:ZFW',
                'expected_type' => 'TBM',
                'validate' => function($parsed) {
                    return empty($parsed['sector']);
                }
            ]
        ]
    ],
    
    // =============================================
    // FACILITY VARIATIONS
    // =============================================
    [
        'category' => 'Facility Variations',
        'tests' => [
            [
                'name' => 'TRACON as facility',
                'input' => '20MIT JFK via CAMRN VOLUME:VOLUME EXCL:NONE 2200-0300 N90:ZBW',
                'expected_type' => 'MIT',
                'validate' => function($parsed) {
                    return $parsed['toFacility'] === 'N90' || 
                           $parsed['fromFacility'] === 'N90';
                }
            ],
            [
                'name' => '4-letter TRACON',
                'input' => 'CFR MIA departures VOLUME:VOLUME EXCL:NONE 2100-0400 ZMA:F11',
                'expected_type' => 'CFR',
                'validate' => function($parsed) {
                    return strlen($parsed['fromFacility']) >= 2;
                }
            ],
            [
                'name' => 'Canadian center',
                'input' => '8MINIT BOS VOLUME:VOLUME EXCL:NONE 2330-0300 ZBW:CZY',
                'expected_type' => 'MINIT',
                'validate' => function($parsed) {
                    return $parsed['fromFacility'] === 'CZY' || 
                           $parsed['toFacility'] === 'CZY';
                }
            ]
        ]
    ]
];

// =============================================
// RUN TESTS (Placeholder - actual parsing would be from ntml.js)
// =============================================

echo "=== Edge Case Test Summary ===\n\n";

$totalTests = 0;
$passedTests = 0;

foreach ($edgeCases as $category) {
    echo "Category: {$category['category']}\n";
    echo str_repeat('-', 50) . "\n";
    
    foreach ($category['tests'] as $test) {
        $totalTests++;
        
        // Display test info
        echo "  Test: {$test['name']}\n";
        echo "  Input: {$test['input']}\n";
        echo "  Expected Type: {$test['expected_type']}\n";
        
        if (isset($test['expected_condition'])) {
            echo "  Expected Condition: {$test['expected_condition']}\n";
        }
        if (isset($test['expected_via'])) {
            echo "  Expected Via: {$test['expected_via']}\n";
        }
        
        // Note: Actual parsing validation would require running ntml.js parser
        // This script documents the test cases for manual or JS-based testing
        echo "  Status: ⏳ Manual validation required\n";
        echo "\n";
    }
    
    echo "\n";
}

echo "=== Summary ===\n";
echo "Total edge cases documented: $totalTests\n";
echo "\n";
echo "These test cases should be validated by:\n";
echo "1. Entering each input in the NTML quick entry form (ntml.php)\n";
echo "2. Verifying the parsed values match expected\n";
echo "3. Submitting to Discord and verifying output format\n";
echo "\n";

// =============================================
// JAVASCRIPT TEST HARNESS OUTPUT
// =============================================

echo "=== JavaScript Test Harness ===\n";
echo "Copy this to browser console on ntml.php to run automated tests:\n\n";

echo "const edgeCaseTests = " . json_encode($edgeCases, JSON_PRETTY_PRINT) . ";\n\n";

echo <<<'JS'
// Run tests (copy this to console after the above)
function runEdgeCaseTests(tests) {
    let passed = 0, failed = 0;
    tests.forEach(category => {
        console.group(category.category);
        category.tests.forEach(test => {
            try {
                // Parse the input
                const result = parseNLPInput(test.input);
                
                // Check expected type
                let typeMatch = result.type === test.expected_type;
                
                // Run custom validator if provided
                let customPass = true;
                if (test.validate) {
                    customPass = test.validate(result);
                }
                
                if (typeMatch && customPass) {
                    console.log('✓', test.name);
                    passed++;
                } else {
                    console.error('✗', test.name, 'Expected:', test.expected_type, 'Got:', result.type);
                    console.log('  Parsed:', result);
                    failed++;
                }
            } catch (e) {
                console.error('✗', test.name, 'Error:', e.message);
                failed++;
            }
        });
        console.groupEnd();
    });
    console.log(`\nResults: ${passed} passed, ${failed} failed`);
}

// Run: runEdgeCaseTests(edgeCaseTests);
JS;

echo "\n";
