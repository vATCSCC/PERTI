<?php
/**
 * NTML Format Validation Test Script
 * Tests message building functions against historical NTML_2020.txt format
 * 
 * Usage: php test_ntml_format.php
 */

echo "=== NTML Format Validation Tests ===\n";
echo "Date: " . date('Y-m-d H:i:s') . " UTC\n\n";

// Include the message building functions
// Since they're in api/mgt/ntml/post.php, we need to extract them

/**
 * Build NTML message in proper format
 */
function buildNTMLMessageFromEntry($entry, $protocol) {
    $logTime = gmdate('d/Hi');
    $type = strtoupper($entry['entry_type'] ?? 'MIT');
    
    switch ($type) {
        case 'MIT':
        case 'MINIT':
        case 'STOP':
        case 'APREQ':
        case 'CFR':
            return buildRestrictionNTML($entry, $logTime);
        case 'HOLDING':
        case 'DELAY':
            return buildDelayNTML($entry, $logTime);
        case 'CONFIG':
            return buildConfigNTML($entry, $logTime);
        case 'CANCEL':
            return buildCancelNTML($entry, $logTime);
        case 'TBM':
            return buildTBMNTML($entry, $logTime);
        default:
            return "$logTime {$entry['raw']}";
    }
}

function buildRestrictionNTML($entry, $logTime) {
    $type = strtoupper($entry['entry_type'] ?? 'MIT');
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? $entry['condition_text'] ?? '');
    $flowType = strtolower($entry['flow_type'] ?? 'arrivals');
    
    $restriction = '';
    if ($type === 'STOP') {
        $restriction = 'STOP';
    } elseif ($type === 'APREQ' || $type === 'CFR') {
        $restriction = $type;
    } else {
        $value = $entry['restriction_value'] ?? $entry['distance'] ?? $entry['minutes'] ?? '';
        $restriction = "{$value}{$type}";
    }
    
    $qualifiers = '';
    if (!empty($entry['qualifiers'])) {
        $quals = is_string($entry['qualifiers']) ? explode(',', $entry['qualifiers']) : $entry['qualifiers'];
        $qualifiers = ' ' . implode(' ', array_map(function($q) {
            return strtoupper(str_replace('_', ' ', trim($q)));
        }, $quals));
    }
    
    $opts = [];
    if (!empty($entry['aircraft_type'])) {
        $opts[] = 'TYPE:' . strtoupper($entry['aircraft_type']);
    }
    if (!empty($entry['altitude'])) {
        $altType = strtoupper($entry['alt_type'] ?? 'AT');
        $opts[] = "ALT:{$altType}" . strtoupper($entry['altitude']);
    }
    
    $reason = strtoupper($entry['reason_code'] ?? 'VOLUME');
    if ($reason === 'VOLUME') {
        $opts[] = 'VOLUME:VOLUME';
    } elseif ($reason === 'WEATHER') {
        $detail = strtoupper($entry['reason_detail'] ?? $entry['weather'] ?? 'WEATHER');
        $opts[] = "WEATHER:{$detail}";
    } elseif ($reason === 'RUNWAY') {
        $detail = strtoupper($entry['reason_detail'] ?? 'CONFIG');
        $opts[] = "RUNWAY:{$detail}";
    } else {
        $opts[] = "{$reason}:{$reason}";
    }
    
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    $opts[] = "EXCL:{$excl}";
    
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+2 hours'));
    $opts[] = "{$validFrom}-{$validUntil}";
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    if ($reqFac || $provFac) {
        $opts[] = "{$reqFac}:{$provFac}";
    }
    
    $optStr = implode(' ', $opts);
    
    if ($type === 'APREQ' || $type === 'CFR') {
        $line = "{$logTime}    {$restriction} {$airport}";
        if ($flowType === 'departures') {
            $line .= ' departures';
        }
        if ($fix) {
            $line .= " via {$fix}";
        }
    } elseif ($fix) {
        $line = "{$logTime}    {$airport}";
        if ($flowType === 'departures') {
            $line .= ' departures';
        }
        $line .= " via {$fix} {$restriction}";
    } else {
        $line = "{$logTime}    {$airport}";
        if ($flowType === 'departures') {
            $line .= ' departures';
        }
        $line .= " {$restriction}";
    }
    
    $line .= "{$qualifiers} {$optStr}";
    
    return trim($line);
}

function buildDelayNTML($entry, $logTime) {
    $delayType = strtoupper($entry['delay_type'] ?? 'D/D');
    if ($delayType === 'ED') $delayType = 'E/D';
    if ($delayType === 'AD') $delayType = 'A/D';
    if ($delayType === 'DD') $delayType = 'D/D';
    
    $prep = 'from';
    if ($delayType === 'E/D') $prep = 'for';
    if ($delayType === 'A/D') $prep = 'to';
    
    $facility = strtoupper($entry['delay_facility'] ?? $entry['airport'] ?? '');
    $reportingFac = $entry['reporting_facility'] ?? '';
    
    $delayMin = $entry['longest_delay'] ?? $entry['delay_minutes'] ?? $entry['minutes'] ?? '';
    $trend = strtolower($entry['delay_trend'] ?? 'steady');
    $holding = $entry['holding'] ?? $entry['is_holding'] ?? 'no';
    
    $sign = '';
    if ($trend === 'increasing' || $trend === 'inc' || $trend === 'initiating') {
        $sign = '+';
    } elseif ($trend === 'decreasing' || $trend === 'dec' || $trend === 'terminating') {
        $sign = '-';
    }
    
    $delayValue = '';
    if ($holding === 'yes' || $holding === 'yes_initiating' || strpos(strtolower($holding), 'holding') !== false) {
        $delayValue = ($sign ?: '+') . 'Holding';
    } else {
        $delayValue = "{$sign}{$delayMin}";
    }
    
    $reportTime = $entry['report_time'] ?? $entry['delay_time'] ?? gmdate('Hi');
    $acftCount = $entry['flights_delayed'] ?? $entry['acft_count'] ?? '';
    
    $line = "{$logTime}";
    if ($reportingFac) {
        $line .= "    {$reportingFac}";
    }
    $line .= " {$delayType} {$prep} {$facility}, {$delayValue}/{$reportTime}";
    if ($acftCount) {
        $line .= "/{$acftCount} ACFT";
    }
    
    if (!empty($entry['fix'])) {
        $line .= ' NAVAID:' . strtoupper($entry['fix']);
    }
    
    $reason = strtoupper($entry['reason_code'] ?? 'VOLUME');
    if ($reason === 'VOLUME') {
        $line .= ' VOLUME:VOLUME';
    } else {
        $line .= " {$reason}:{$reason}";
    }
    
    return trim($line);
}

function buildConfigNTML($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $weather = strtoupper($entry['weather'] ?? 'VMC');
    $arrRwys = strtoupper($entry['arr_runways'] ?? '');
    $depRwys = strtoupper($entry['dep_runways'] ?? '');
    $aar = $entry['aar'] ?? '60';
    $aarType = $entry['aar_type'] ?? 'Strat';
    $aarAdj = $entry['aar_adjustment'] ?? '';
    $adr = $entry['adr'] ?? '60';
    
    $line = "{$logTime}    {$airport}    {$weather}    ARR:{$arrRwys} DEP:{$depRwys}    AAR({$aarType}):{$aar}";
    if ($aarAdj) {
        $line .= " AAR Adjustment:{$aarAdj}";
    }
    $line .= "    ADR:{$adr}";
    
    return $line;
}

function buildCancelNTML($entry, $logTime) {
    $cancelType = strtoupper($entry['cancel_type'] ?? '');
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? '');
    
    $line = "{$logTime}    ";
    
    if ($cancelType === 'ALL') {
        $line .= 'ALL TMI CANCELLED';
    } else {
        $line .= "CANCEL {$airport}";
        if ($fix) {
            $line .= " via {$fix}";
        }
        if (!empty($entry['restriction_value'])) {
            $line .= ' ' . $entry['restriction_value'] . 'MIT';
        }
    }
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
}

function buildTBMNTML($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $sector = strtoupper($entry['sector'] ?? '');
    
    $reason = strtoupper($entry['reason_code'] ?? 'VOLUME');
    $reasonStr = ($reason === 'VOLUME') ? 'VOLUME:VOLUME' : "{$reason}:{$reason}";
    
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+2 hours'));
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    
    $line = "{$logTime}    {$airport} TBM";
    if ($sector) {
        $line .= " {$sector}";
    }
    $line .= " {$reasonStr} EXCL:{$excl} {$validFrom}-{$validUntil}";
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
}

// ============================================
// TEST CASES
// ============================================

$tests = [
    // MIT Tests (from NTML_2020.txt patterns)
    [
        'name' => 'MIT with via fix (arrivals)',
        'input' => [
            'entry_type' => 'MIT',
            'airport' => 'BOS',
            'fix' => 'MERIT',
            'restriction_value' => '15',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2345',
            'valid_until' => '0000',
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'ZNY'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+BOS via MERIT 15MIT VOLUME:VOLUME EXCL:NONE 2345-0000 ZBW:ZNY$/'
    ],
    [
        'name' => 'MIT with departures',
        'input' => [
            'entry_type' => 'MIT',
            'airport' => 'BOS',
            'restriction_value' => '40',
            'flow_type' => 'departures',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '0045',
            'valid_until' => '0300',
            'requesting_facility' => 'ZDC',
            'providing_facility' => 'PCT'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+BOS departures 40MIT VOLUME:VOLUME EXCL:NONE 0045-0300 ZDC:PCT$/'
    ],
    [
        'name' => 'MIT with qualifier NO STACKS',
        'input' => [
            'entry_type' => 'MIT',
            'airport' => 'LGA',
            'fix' => 'J146',
            'restriction_value' => '25',
            'flow_type' => 'arrivals',
            'qualifiers' => 'NO_STACKS',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2200',
            'valid_until' => '0300',
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'ZOB'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+LGA via J146 25MIT NO STACKS VOLUME:VOLUME EXCL:NONE 2200-0300 ZNY:ZOB$/'
    ],
    
    // STOP Tests
    [
        'name' => 'STOP arrivals',
        'input' => [
            'entry_type' => 'STOP',
            'airport' => 'BOS',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2345',
            'valid_until' => '0015',
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'PHL'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+BOS STOP VOLUME:VOLUME EXCL:NONE 2345-0015 ZNY:PHL$/'
    ],
    [
        'name' => 'STOP departures',
        'input' => [
            'entry_type' => 'STOP',
            'airport' => 'ATL',
            'flow_type' => 'departures',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2345',
            'valid_until' => '0100',
            'requesting_facility' => 'ZDC',
            'providing_facility' => 'PCT'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+ATL departures STOP VOLUME:VOLUME EXCL:NONE 2345-0100 ZDC:PCT$/'
    ],
    
    // MINIT Tests
    [
        'name' => 'MINIT basic',
        'input' => [
            'entry_type' => 'MINIT',
            'airport' => 'BOS',
            'restriction_value' => '8',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2330',
            'valid_until' => '0300',
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'CZY'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+BOS 8MINIT VOLUME:VOLUME EXCL:NONE 2330-0300 ZBW:CZY$/'
    ],
    
    // Delay Tests
    [
        'name' => 'D/D (Departure Delay)',
        'input' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'D/D',
            'delay_facility' => 'JFK',
            'longest_delay' => '45',
            'delay_trend' => 'increasing',
            'report_time' => '0010',
            'reason_code' => 'VOLUME'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4} D\/D from JFK, \+45\/0010 VOLUME:VOLUME$/'
    ],
    [
        'name' => 'E/D (Enroute Delay) with facility and ACFT',
        'input' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'E/D',
            'reporting_facility' => 'ZDC',
            'delay_facility' => 'BOS',
            'longest_delay' => '30',
            'delay_trend' => 'increasing',
            'report_time' => '0019',
            'flights_delayed' => '13',
            'reason_code' => 'VOLUME'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+ZDC E\/D for BOS, \+30\/0019\/13 ACFT VOLUME:VOLUME$/'
    ],
    [
        'name' => 'A/D (Arrival Delay) with Holding',
        'input' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'A/D',
            'reporting_facility' => 'ZJX66',
            'delay_facility' => 'MIA',
            'holding' => 'yes_initiating',
            'delay_trend' => 'initiating',
            'report_time' => '0058',
            'fix' => 'OMN',
            'reason_code' => 'VOLUME'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+ZJX66 A\/D to MIA, \+Holding\/0058 NAVAID:OMN VOLUME:VOLUME$/'
    ],
    
    // Config Tests
    [
        'name' => 'Config VMC',
        'input' => [
            'entry_type' => 'CONFIG',
            'airport' => 'ATL',
            'weather' => 'VMC',
            'arr_runways' => '26R/27L/28',
            'dep_runways' => '26L/27R',
            'aar' => '132',
            'aar_type' => 'Strat',
            'adr' => '70'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+ATL\s+VMC\s+ARR:26R\/27L\/28 DEP:26L\/27R\s+AAR\(Strat\):132\s+ADR:70$/'
    ],
    [
        'name' => 'Config with ILS approach types',
        'input' => [
            'entry_type' => 'CONFIG',
            'airport' => 'JFK',
            'weather' => 'VMC',
            'arr_runways' => 'ILS_31R_VAP_31L',
            'dep_runways' => '31L',
            'aar' => '58',
            'aar_type' => 'Strat',
            'adr' => '24'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+JFK\s+VMC\s+ARR:ILS_31R_VAP_31L DEP:31L\s+AAR\(Strat\):58\s+ADR:24$/'
    ],
    [
        'name' => 'Config IMC with AAR Adjustment',
        'input' => [
            'entry_type' => 'CONFIG',
            'airport' => 'PHL',
            'weather' => 'IMC',
            'arr_runways' => '27R',
            'dep_runways' => '27L/35',
            'aar' => '36',
            'aar_type' => 'Dyn',
            'aar_adjustment' => 'XW-TLWD',
            'adr' => '28'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+PHL\s+IMC\s+ARR:27R DEP:27L\/35\s+AAR\(Dyn\):36 AAR Adjustment:XW-TLWD\s+ADR:28$/'
    ],
    
    // TBM Tests
    [
        'name' => 'TBM basic',
        'input' => [
            'entry_type' => 'TBM',
            'airport' => 'ATL',
            'sector' => '3_WEST',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2100',
            'valid_until' => '0300',
            'requesting_facility' => 'A80',
            'providing_facility' => 'ZTL'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2100-0300 A80:ZTL$/'
    ],
    
    // CFR Tests
    [
        'name' => 'CFR (Call for Release)',
        'input' => [
            'entry_type' => 'CFR',
            'airport' => 'MIA,FLL',
            'flow_type' => 'departures',
            'aircraft_type' => 'ALL',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => '2100',
            'valid_until' => '0400',
            'requesting_facility' => 'ZMA',
            'providing_facility' => 'F11'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+CFR MIA,FLL departures TYPE:ALL VOLUME:VOLUME EXCL:NONE 2100-0400 ZMA:F11$/'
    ],
    
    // Cancel Tests
    [
        'name' => 'Cancel specific TMI',
        'input' => [
            'entry_type' => 'CANCEL',
            'cancel_type' => 'SPECIFIC',
            'airport' => 'JFK',
            'fix' => 'LENDY',
            'restriction_value' => '20',
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'ZBW'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+CANCEL JFK via LENDY 20MIT ZNY:ZBW$/'
    ],
    [
        'name' => 'Cancel ALL TMI',
        'input' => [
            'entry_type' => 'CANCEL',
            'cancel_type' => 'ALL'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+ALL TMI CANCELLED$/'
    ]
];

// ============================================
// RUN TESTS
// ============================================

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    $result = buildNTMLMessageFromEntry($test['input'], 5);
    $match = preg_match($test['expected_pattern'], $result);
    
    if ($match) {
        echo "✓ PASS: {$test['name']}\n";
        echo "  Output: $result\n\n";
        $passed++;
    } else {
        echo "✗ FAIL: {$test['name']}\n";
        echo "  Output:   $result\n";
        echo "  Expected: {$test['expected_pattern']}\n\n";
        $failed++;
    }
}

echo "=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total:  " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed! NTML format is compliant.\n";
} else {
    echo "\n✗ Some tests failed. Review output above.\n";
}
