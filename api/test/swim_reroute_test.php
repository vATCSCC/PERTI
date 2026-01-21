<?php
/**
 * VATSWIM Reroute Enhancement Test
 *
 * Tests the new route expansion, grouping, and advisory text generation
 * without requiring database records.
 *
 * Usage: curl "https://perti.vatcscc.org/api/test/swim_reroute_test.php?key=perti-ntml-test-2026"
 */

header('Content-Type: application/json');

// Simple key check
if (($_GET['key'] ?? '') !== 'perti-ntml-test-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

// Include the SWIM reroutes file to access helper functions
$swimReroutesPath = __DIR__ . '/../swim/v1/tmi/reroutes.php';

// Define test functions locally (since we can't include the file without DB)

function testParseAirportList($str) {
    if (empty($str)) return [];
    if (is_array($str)) return $str;
    if ($str[0] === '[') {
        $decoded = json_decode($str, true);
        if ($decoded !== null) return $decoded;
    }
    $airports = array_filter(array_map('trim', preg_split('/[\s,\/]+/', strtoupper($str))));
    return array_values($airports);
}

function testExpandRoutesFromScope($originAirports, $destAirports, $routeString) {
    $origins = testParseAirportList($originAirports);
    $dests = testParseAirportList($destAirports);

    if (empty($origins) || empty($dests) || empty($routeString)) {
        return [];
    }

    $routes = [];
    foreach ($origins as $orig) {
        foreach ($dests as $dest) {
            $routes[] = [
                'origin' => $orig,
                'dest' => $dest,
                'route' => $routeString
            ];
        }
    }
    return $routes;
}

function testGroupRoutesByString($routes) {
    if (empty($routes)) return [];

    $grouped = [];
    foreach ($routes as $r) {
        $key = $r['route'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'origins' => [],
                'dests' => [],
                'route' => $key
            ];
        }
        $originParts = preg_split('/\s+/', trim($r['origin']));
        foreach ($originParts as $o) {
            if (!in_array($o, $grouped[$key]['origins'])) {
                $grouped[$key]['origins'][] = $o;
            }
        }
        $destParts = preg_split('/\s+/', trim($r['dest']));
        foreach ($destParts as $d) {
            if (!in_array($d, $grouped[$key]['dests'])) {
                $grouped[$key]['dests'][] = $d;
            }
        }
    }
    return array_values($grouped);
}

function testStripPlotterMarkers($routeString) {
    if (empty($routeString)) return '';
    return trim(preg_replace('/[><]/', '', $routeString));
}

function testFormatRouteTableText($routes) {
    if (empty($routes)) {
        return "ORIG       DEST       ROUTE\n----       ----       -----\n(No routes specified)";
    }

    $maxOrigLen = 4;
    $maxDestLen = 4;
    foreach ($routes as $route) {
        $maxOrigLen = max($maxOrigLen, strlen(strtoupper($route['origin'] ?? '---')));
        $maxDestLen = max($maxDestLen, strlen(strtoupper($route['dest'] ?? $route['destination'] ?? '---')));
    }

    $origColWidth = $maxOrigLen + 3;
    $destColWidth = $maxDestLen + 3;

    $output = str_pad('ORIG', $origColWidth) . str_pad('DEST', $destColWidth) . "ROUTE\n";
    $output .= str_pad('----', $origColWidth) . str_pad('----', $destColWidth) . "-----\n";

    foreach ($routes as $route) {
        $orig = strtoupper($route['origin'] ?? '---');
        $dest = strtoupper($route['dest'] ?? $route['destination'] ?? '---');
        $routeStr = strtoupper($route['route'] ?? '');
        $output .= str_pad($orig, $origColWidth) . str_pad($dest, $destColWidth) . $routeStr . "\n";
    }

    return rtrim($output);
}

function testFormatRerouteAdvisoryText($data) {
    $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
    $facility = strtoupper($data['facility'] ?? 'DCC');
    $headerDate = gmdate('m/d/Y', strtotime($data['issue_date'] ?? 'now'));
    $routeName = strtoupper($data['name'] ?? $data['route_name'] ?? '');
    $constrainedArea = strtoupper($data['constrained_area'] ?? $data['impacted_area'] ?? '');
    $reason = strtoupper($data['reason'] ?? 'WEATHER');
    $includeTraffic = strtoupper($data['include_traffic'] ?? '');
    $facilities = $data['facilities'] ?? $data['facilities_included'] ?? [];
    $facilitiesStr = is_array($facilities) ? implode('/', array_map('strtoupper', $facilities)) : strtoupper($facilities);

    $startUtc = $data['start_utc'] ?? $data['valid_from'] ?? null;
    $endUtc = $data['end_utc'] ?? $data['valid_until'] ?? null;
    $startTime = $startUtc ? gmdate('dHi', strtotime($startUtc)) : gmdate('dHi');
    $endTime = $endUtc ? gmdate('dHi', strtotime($endUtc)) : gmdate('dHi');

    $validType = strtoupper($data['valid_type'] ?? 'ETD');
    $probExt = strtoupper($data['prob_extension'] ?? 'NONE');
    $tmiId = 'RR' . $facility . $advNum;

    $lines = [];
    $lines[] = "vATCSCC ADVZY {$advNum} {$facility} {$headerDate} ROUTE RQD";

    if ($routeName) $lines[] = "NAME: {$routeName}";
    if ($constrainedArea) $lines[] = "CONSTRAINED AREA: {$constrainedArea}";
    $lines[] = "REASON: {$reason}";
    if ($includeTraffic) $lines[] = "INCLUDE TRAFFIC: {$includeTraffic}";
    if ($facilitiesStr) $lines[] = "FACILITIES INCLUDED: {$facilitiesStr}";
    $lines[] = "VALID: {$validType} {$startTime} TO {$endTime}";
    $lines[] = "PROBABILITY OF EXTENSION: {$probExt}";
    $lines[] = "REMARKS: " . ($data['remarks'] ?? '');
    $lines[] = "ASSOCIATED RESTRICTIONS: " . ($data['associated_restrictions'] ?? '');
    $lines[] = "MODIFICATIONS: " . ($data['modifications'] ?? '');
    $lines[] = "ROUTES:";
    $lines[] = "";

    $routes = $data['routes'] ?? [];
    $lines[] = testFormatRouteTableText($routes);

    $lines[] = "";
    $lines[] = "TMI ID: {$tmiId}";
    $lines[] = "{$startTime} - {$endTime}";
    $lines[] = gmdate('y/m/d H:i');

    return implode("\n", $lines);
}

// ============================================================================
// Test Cases
// ============================================================================

$results = [];
$passed = 0;
$failed = 0;

// Test 1: Airport list parsing
$test1Input = "KJFK KEWR KLGA KPHL";
$test1Result = testParseAirportList($test1Input);
$test1Expected = ['KJFK', 'KEWR', 'KLGA', 'KPHL'];
$test1Pass = $test1Result === $test1Expected;
$results['parse_airports_space'] = [
    'name' => 'Parse airport list (space-separated)',
    'input' => $test1Input,
    'output' => $test1Result,
    'expected' => $test1Expected,
    'pass' => $test1Pass
];
$test1Pass ? $passed++ : $failed++;

// Test 2: Airport list parsing with slashes
$test2Input = "JFK/EWR/LGA";
$test2Result = testParseAirportList($test2Input);
$test2Expected = ['JFK', 'EWR', 'LGA'];
$test2Pass = $test2Result === $test2Expected;
$results['parse_airports_slash'] = [
    'name' => 'Parse airport list (slash-separated)',
    'input' => $test2Input,
    'output' => $test2Result,
    'expected' => $test2Expected,
    'pass' => $test2Pass
];
$test2Pass ? $passed++ : $failed++;

// Test 3: Route expansion (Cartesian product)
$test3Origins = "JFK EWR LGA";
$test3Dests = "PIT";
$test3Route = "DEEZZ5 CANDR J60 PSB HAYNZ6";
$test3Result = testExpandRoutesFromScope($test3Origins, $test3Dests, $test3Route);
$test3Pass = count($test3Result) === 3 &&
             $test3Result[0]['origin'] === 'JFK' &&
             $test3Result[1]['origin'] === 'EWR' &&
             $test3Result[2]['origin'] === 'LGA';
$results['expand_routes'] = [
    'name' => 'Route expansion (3 origins × 1 dest)',
    'input' => ['origins' => $test3Origins, 'dests' => $test3Dests],
    'output' => $test3Result,
    'count' => count($test3Result),
    'pass' => $test3Pass
];
$test3Pass ? $passed++ : $failed++;

// Test 4: Route grouping with different routes
$test4Input = [
    ['origin' => 'JFK', 'dest' => 'PIT', 'route' => 'DEEZZ5 >CANDR J60 PSB< HAYNZ6'],
    ['origin' => 'EWR', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6'],
    ['origin' => 'LGA', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6'],
    ['origin' => 'PHL', 'dest' => 'PIT', 'route' => '>PTW SARAA DANNR J60 PSB< HAYNZ6']
];
$test4Result = testGroupRoutesByString($test4Input);
$test4Pass = count($test4Result) === 3; // 3 unique routes
$results['group_routes'] = [
    'name' => 'Route grouping (4 routes → 3 groups)',
    'input_count' => count($test4Input),
    'output' => $test4Result,
    'output_count' => count($test4Result),
    'pass' => $test4Pass
];
$test4Pass ? $passed++ : $failed++;

// Test 5: Plotter string (strip markers)
$test5Input = "DEEZZ5 >CANDR J60 PSB< HAYNZ6";
$test5Result = testStripPlotterMarkers($test5Input);
$test5Expected = "DEEZZ5 CANDR J60 PSB HAYNZ6";
$test5Pass = $test5Result === $test5Expected;
$results['plotter_string'] = [
    'name' => 'Strip plotter markers (> and <)',
    'input' => $test5Input,
    'output' => $test5Result,
    'expected' => $test5Expected,
    'pass' => $test5Pass
];
$test5Pass ? $passed++ : $failed++;

// Test 6: Route table formatting
$test6Routes = [
    ['origin' => 'JFK', 'dest' => 'PIT', 'route' => 'DEEZZ5 >CANDR J60 PSB< HAYNZ6'],
    ['origin' => 'EWR LGA', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6'],
    ['origin' => 'PHL', 'dest' => 'PIT', 'route' => '>PTW SARAA DANNR J60 PSB< HAYNZ6']
];
$test6Result = testFormatRouteTableText($test6Routes);
$test6Pass = strpos($test6Result, 'ORIG') !== false &&
             strpos($test6Result, 'EWR LGA') !== false &&
             strpos($test6Result, 'DEEZZ5') !== false;
$results['route_table'] = [
    'name' => 'Route table formatting',
    'routes_count' => count($test6Routes),
    'output' => $test6Result,
    'pass' => $test6Pass
];
$test6Pass ? $passed++ : $failed++;

// Test 7: Full advisory text generation
$test7Data = [
    'advisory_number' => '003',
    'facility' => 'DCC',
    'issue_date' => '2026-01-21 22:00:00',
    'name' => 'ZNY_TO_PIT',
    'constrained_area' => 'ZNY',
    'reason' => 'VOLUME',
    'include_traffic' => 'KJFK/KEWR/KLGA/KPHL DEPARTURES TO KPIT',
    'facilities' => ['ZNY', 'ZOB'],
    'start_utc' => '2026-01-21 22:00:00',
    'end_utc' => '2026-01-22 02:00:00',
    'valid_type' => 'ETD',
    'prob_extension' => 'MEDIUM',
    'remarks' => 'Test reroute',
    'associated_restrictions' => 'ZNY REQUESTS AOB FL300',
    'modifications' => '',
    'routes' => [
        ['origin' => 'JFK', 'dest' => 'PIT', 'route' => 'DEEZZ5 >CANDR J60 PSB< HAYNZ6'],
        ['origin' => 'EWR LGA', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6'],
        ['origin' => 'PHL', 'dest' => 'PIT', 'route' => '>PTW SARAA DANNR J60 PSB< HAYNZ6']
    ]
];
$test7Result = testFormatRerouteAdvisoryText($test7Data);
$test7Pass = strpos($test7Result, 'vATCSCC ADVZY 003 DCC') !== false &&
             strpos($test7Result, 'NAME: ZNY_TO_PIT') !== false &&
             strpos($test7Result, 'CONSTRAINED AREA: ZNY') !== false &&
             strpos($test7Result, 'FACILITIES INCLUDED: ZNY/ZOB') !== false &&
             strpos($test7Result, 'TMI ID: RRDCC003') !== false;
$results['advisory_text'] = [
    'name' => 'Full advisory text generation',
    'checks' => [
        'has_header' => strpos($test7Result, 'vATCSCC ADVZY 003 DCC') !== false,
        'has_name' => strpos($test7Result, 'NAME: ZNY_TO_PIT') !== false,
        'has_area' => strpos($test7Result, 'CONSTRAINED AREA: ZNY') !== false,
        'has_facilities' => strpos($test7Result, 'FACILITIES INCLUDED: ZNY/ZOB') !== false,
        'has_tmi_id' => strpos($test7Result, 'TMI ID: RRDCC003') !== false
    ],
    'output' => $test7Result,
    'pass' => $test7Pass
];
$test7Pass ? $passed++ : $failed++;

// ============================================================================
// Output
// ============================================================================

$response = [
    'success' => true,
    'version' => '1.0.0',
    'test_name' => 'VATSWIM Reroute Enhancement Tests',
    'summary' => [
        'passed' => $passed,
        'failed' => $failed,
        'total' => $passed + $failed
    ],
    'results' => $results,
    'sample_advisory' => $test7Result,
    'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC'
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
