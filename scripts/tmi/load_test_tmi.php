<?php
/**
 * TMI Load Testing Script
 *
 * Simulates multiple concurrent users submitting TMIs to test server capacity.
 *
 * Default scenario: 30 users submitting 25 restrictions + 4 advisories each
 * over a 5-minute window (870 total TMIs).
 *
 * Usage: php scripts/tmi/load_test_tmi.php [--users=30] [--restrictions=25] [--advisories=4] [--duration=300] [--base-url=URL]
 *
 * @package PERTI
 * @subpackage TMI/Testing
 */

// Configuration defaults
$config = [
    'users' => 30,
    'restrictions_per_user' => 25,
    'advisories_per_user' => 4,
    'duration_seconds' => 300, // 5 minutes
    'base_url' => 'https://perti.vatcscc.org',
    'production' => false, // Always use staging for load tests
    'concurrent_connections' => 10, // Max simultaneous connections
    'dry_run' => false, // If true, don't actually send requests
];

// Parse command line arguments
foreach ($argv as $arg) {
    if (preg_match('/^--([a-z-]+)=(.+)$/i', $arg, $matches)) {
        $key = strtolower($matches[1]);
        $value = $matches[2];

        if ($key === 'users') $config['users'] = (int)$value;
        if ($key === 'restrictions') $config['restrictions_per_user'] = (int)$value;
        if ($key === 'advisories') $config['advisories_per_user'] = (int)$value;
        if ($key === 'duration') $config['duration_seconds'] = (int)$value;
        if ($key === 'base-url') $config['base_url'] = $value;
        if ($key === 'concurrent') $config['concurrent_connections'] = (int)$value;
        if ($key === 'dry-run') $config['dry_run'] = ($value === 'true' || $value === '1');
    }
}

// Calculate totals
$total_tmis_per_user = $config['restrictions_per_user'] + $config['advisories_per_user'];
$total_tmis = $config['users'] * $total_tmis_per_user;
$requests_per_second = $total_tmis / $config['duration_seconds'];

// Print configuration
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║              TMI LOAD TESTING SCRIPT                             ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  Configuration:                                                  ║\n";
echo "║  • Users:              " . str_pad($config['users'], 42) . "║\n";
echo "║  • Restrictions/user:  " . str_pad($config['restrictions_per_user'], 42) . "║\n";
echo "║  • Advisories/user:    " . str_pad($config['advisories_per_user'], 42) . "║\n";
echo "║  • Duration:           " . str_pad($config['duration_seconds'] . " seconds (" . round($config['duration_seconds']/60, 1) . " min)", 42) . "║\n";
echo "║  • Total TMIs:         " . str_pad($total_tmis, 42) . "║\n";
echo "║  • Target rate:        " . str_pad(round($requests_per_second, 2) . " requests/sec", 42) . "║\n";
echo "║  • Concurrent conns:   " . str_pad($config['concurrent_connections'], 42) . "║\n";
echo "║  • Base URL:           " . str_pad(substr($config['base_url'], 0, 40), 42) . "║\n";
echo "║  • Mode:               " . str_pad($config['dry_run'] ? 'DRY RUN (no requests)' : 'LIVE TEST', 42) . "║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Airports and facilities for generating realistic data
$airports = ['KJFK', 'KEWR', 'KLGA', 'KBOS', 'KPHL', 'KDCA', 'KIAD', 'KBWI', 'KATL', 'KORD', 'KDFW', 'KLAX', 'KSFO', 'KDEN', 'KMIA'];
$facilities = ['N90', 'A90', 'C90', 'D10', 'I90', 'P50', 'S46', 'PCT', 'Y90'];
$artccs = ['ZNY', 'ZBW', 'ZDC', 'ZTL', 'ZOB', 'ZAU', 'ZLA', 'ZOA', 'ZSE', 'ZDV', 'ZMA', 'ZJX'];
$fixes = ['LENDY', 'PARCH', 'BIGGY', 'BRIGS', 'CAMRN', 'DIXIE', 'EMJAY', 'FINNN', 'GREKI', 'HOLEY', 'JIFFY', 'KORRY'];
$reasons = ['VOLUME', 'WEATHER', 'EQUIPMENT', 'RUNWAY', 'STAFFING'];
$entry_types = ['MIT', 'MINIT', 'STOP', 'APREQ', 'CFR', 'DELAY'];
$advisory_types = ['OPSPLAN', 'FREEFORM', 'HOTLINE'];

/**
 * Generate a simulated user with a unique CID
 */
function generateUser($index) {
    return [
        'cid' => 1000000 + $index,
        'name' => "LoadTest User $index"
    ];
}

/**
 * Generate a restriction TMI entry
 */
function generateRestriction($user, $index) {
    global $airports, $facilities, $artccs, $fixes, $reasons, $entry_types;

    $airport = $airports[array_rand($airports)];
    $facility = $facilities[array_rand($facilities)];
    $artcc = $artccs[array_rand($artccs)];
    $fix = $fixes[array_rand($fixes)];
    $reason = $reasons[array_rand($reasons)];
    $entry_type = $entry_types[array_rand($entry_types)];

    $value = rand(5, 30);
    $valid_from = date('Y-m-d\TH:i', strtotime('+' . rand(0, 30) . ' minutes'));
    $valid_until = date('Y-m-d\TH:i', strtotime('+' . rand(2, 6) . ' hours'));

    // Build preview text based on entry type
    $preview = date('d/Hi') . " $airport ARR VIA $fix ##$entry_type ";
    if ($entry_type === 'MIT') {
        $preview .= "{$value}NM ";
    } elseif ($entry_type === 'MINIT') {
        $preview .= "{$value}MIN ";
    } elseif ($entry_type === 'DELAY') {
        $preview .= "{$value}MIN ";
    } else {
        $preview .= "ACTIVE ";
    }
    $preview .= "$reason " . date('Hi', strtotime($valid_from)) . "-" . date('Hi', strtotime($valid_until));
    $preview .= " REQ:$facility PROV:$artcc [LOADTEST-{$user['cid']}-$index]";

    return [
        'type' => 'ntml',
        'entryType' => $entry_type,
        'preview' => $preview,
        'orgs' => ['vatcscc'],
        'data' => [
            'ctl_element' => $airport,
            'req_facility' => $facility,
            'prov_facility' => $artcc,
            'value' => $value,
            'qualifiers' => [],
            'valid_from' => $valid_from,
            'valid_until' => $valid_until,
            'reason_category' => $reason,
            'reason_cause' => 'Load test generated',
            'via_fix' => $fix
        ]
    ];
}

/**
 * Generate an advisory TMI entry
 */
function generateAdvisory($user, $index, $advNum) {
    global $airports, $reasons, $advisory_types;

    $airport = $airports[array_rand($airports)];
    $reason = $reasons[array_rand($reasons)];
    $advisory_type = $advisory_types[array_rand($advisory_types)];

    $valid_from = date('Y-m-d\TH:i', strtotime('+' . rand(0, 30) . ' minutes'));
    $valid_until = date('Y-m-d\TH:i', strtotime('+' . rand(2, 6) . ' hours'));

    $preview = "vATCSCC ADVZY LT-$advNum $airport " . date('m/d/Y') . "\n";
    $preview .= strtoupper($advisory_type) . " ADVISORY\n";
    $preview .= "Effective: " . date('Hi', strtotime($valid_from)) . " UTC through " . date('Hi', strtotime($valid_until)) . " UTC\n";
    $preview .= "Reason: $reason\n";
    $preview .= "[LOADTEST-{$user['cid']}-$index]";

    return [
        'type' => 'advisory',
        'entryType' => $advisory_type,
        'preview' => $preview,
        'orgs' => ['vatcscc'],
        'data' => [
            'number' => "ADVZY LT-$advNum",
            'impacted_area' => $airport,
            'effective_time' => $valid_from,
            'end_time' => $valid_until,
            'subject' => "$airport $advisory_type - Load Test",
            'reason' => $reason
        ]
    ];
}

/**
 * Build all requests to be executed
 */
function buildRequests($config) {
    $requests = [];
    $advCounter = 1;

    for ($u = 0; $u < $config['users']; $u++) {
        $user = generateUser($u);

        // Generate restrictions for this user
        for ($r = 0; $r < $config['restrictions_per_user']; $r++) {
            $requests[] = [
                'user' => $user,
                'payload' => [
                    'entries' => [generateRestriction($user, $r)],
                    'production' => $config['production'],
                    'userCid' => $user['cid'],
                    'userName' => $user['name']
                ]
            ];
        }

        // Generate advisories for this user
        for ($a = 0; $a < $config['advisories_per_user']; $a++) {
            $requests[] = [
                'user' => $user,
                'payload' => [
                    'entries' => [generateAdvisory($user, $a, $advCounter++)],
                    'production' => $config['production'],
                    'userCid' => $user['cid'],
                    'userName' => $user['name']
                ]
            ];
        }
    }

    // Shuffle to simulate realistic random ordering
    shuffle($requests);

    return $requests;
}

/**
 * Execute a single HTTP request
 */
function executeRequest($url, $payload, $timeout = 30) {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Load-Test: true'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = microtime(true) - $start;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300 && empty($error),
        'http_code' => $httpCode,
        'duration' => $duration,
        'response' => $response,
        'error' => $error
    ];
}

/**
 * Execute requests with rate limiting using curl_multi
 */
function executeLoadTest($requests, $config) {
    $url = rtrim($config['base_url'], '/') . '/api/mgt/tmi/publish.php';
    $total = count($requests);
    $delay_between_requests = $config['duration_seconds'] / $total;

    echo "Starting load test...\n";
    echo "Target URL: $url\n";
    echo "Delay between requests: " . round($delay_between_requests * 1000, 1) . "ms\n\n";

    // Metrics
    $metrics = [
        'start_time' => microtime(true),
        'total_requests' => $total,
        'completed' => 0,
        'successful' => 0,
        'failed' => 0,
        'response_times' => [],
        'http_codes' => [],
        'errors' => []
    ];

    // Progress bar
    $progressWidth = 50;

    // DRY RUN MODE - simulate without making actual requests
    if ($config['dry_run']) {
        echo "DRY RUN MODE - Simulating requests...\n\n";
        foreach ($requests as $index => $req) {
            // Simulate request with random response time
            $simulated_time = rand(50, 500) / 1000;
            usleep((int)($simulated_time * 100000)); // Small delay for realism

            $metrics['completed']++;
            $metrics['successful']++;
            $metrics['response_times'][] = $simulated_time;
            $metrics['http_codes'][200] = ($metrics['http_codes'][200] ?? 0) + 1;

            // Update progress
            if ($metrics['completed'] % 10 === 0 || $metrics['completed'] === $total) {
                $progress = $metrics['completed'] / $total;
                $filled = (int)($progress * $progressWidth);
                $empty = $progressWidth - $filled;
                $elapsed = microtime(true) - $metrics['start_time'];
                $rate = $metrics['completed'] / max(0.001, $elapsed);

                printf("\r[%s%s] %d/%d (%.1f req/s) Success: %d Failed: %d",
                    str_repeat('#', $filled),
                    str_repeat('-', $empty),
                    $metrics['completed'],
                    $total,
                    $rate,
                    $metrics['successful'],
                    $metrics['failed']
                );
            }
        }

        $metrics['end_time'] = microtime(true);
        $metrics['total_duration'] = $metrics['end_time'] - $metrics['start_time'];
        return $metrics;
    }

    // LIVE MODE - Check for curl extension
    if (!function_exists('curl_multi_init')) {
        die("\nERROR: PHP curl extension is not available. Enable it in php.ini or use --dry-run=true\n");
    }

    // Use curl_multi for concurrent requests
    $mh = curl_multi_init();
    $active_requests = [];
    $request_index = 0;

    while ($metrics['completed'] < $total) {
        // Add new requests up to concurrent limit
        while (count($active_requests) < $config['concurrent_connections'] && $request_index < $total) {
            $req = $requests[$request_index];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($req['payload']),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Load-Test: true',
                    'X-Test-User-CID: ' . $req['user']['cid']
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            curl_multi_add_handle($mh, $ch);
            $active_requests[(int)$ch] = [
                'handle' => $ch,
                'start_time' => microtime(true),
                'request_index' => $request_index
            ];
            $request_index++;

            // Rate limiting delay
            usleep((int)($delay_between_requests * 1000000 / $config['concurrent_connections']));
        }

        // Execute pending requests
        $running = null;
        curl_multi_exec($mh, $running);

        // Check for completed requests
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int)$ch;

            if (isset($active_requests[$key])) {
                $reqInfo = $active_requests[$key];
                $duration = microtime(true) - $reqInfo['start_time'];

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $response = curl_multi_getcontent($ch);
                $error = curl_error($ch);

                $metrics['completed']++;
                $metrics['response_times'][] = $duration;
                $metrics['http_codes'][$httpCode] = ($metrics['http_codes'][$httpCode] ?? 0) + 1;

                if ($httpCode >= 200 && $httpCode < 300 && empty($error)) {
                    $metrics['successful']++;
                } else {
                    $metrics['failed']++;
                    $metrics['errors'][] = [
                        'request_index' => $reqInfo['request_index'],
                        'http_code' => $httpCode,
                        'error' => $error ?: 'HTTP error',
                        'response' => substr($response, 0, 200)
                    ];
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active_requests[$key]);
            }
        }

        // Update progress bar
        $progress = $metrics['completed'] / $total;
        $filled = (int)($progress * $progressWidth);
        $empty = $progressWidth - $filled;
        $elapsed = microtime(true) - $metrics['start_time'];
        $rate = $metrics['completed'] / max(0.001, $elapsed);

        printf("\r[%s%s] %d/%d (%.1f req/s) Success: %d Failed: %d",
            str_repeat('#', $filled),
            str_repeat('-', $empty),
            $metrics['completed'],
            $total,
            $rate,
            $metrics['successful'],
            $metrics['failed']
        );

        // Small pause to avoid CPU spinning
        if ($running > 0) {
            curl_multi_select($mh, 0.1);
        }
    }

    curl_multi_close($mh);

    $metrics['end_time'] = microtime(true);
    $metrics['total_duration'] = $metrics['end_time'] - $metrics['start_time'];

    return $metrics;
}

/**
 * Calculate and display statistics
 */
function displayResults($metrics) {
    echo "\n\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║                      LOAD TEST RESULTS                           ║\n";
    echo "╠══════════════════════════════════════════════════════════════════╣\n";

    // Calculate response time stats
    $times = $metrics['response_times'];
    if (count($times) > 0) {
        sort($times);
        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);
        $p50 = $times[(int)(count($times) * 0.5)];
        $p95 = $times[(int)(count($times) * 0.95)];
        $p99 = $times[(int)(count($times) * 0.99)];
    } else {
        $avg = $min = $max = $p50 = $p95 = $p99 = 0;
    }

    $successRate = $metrics['total_requests'] > 0
        ? ($metrics['successful'] / $metrics['total_requests']) * 100
        : 0;
    $actualRate = $metrics['total_duration'] > 0
        ? $metrics['completed'] / $metrics['total_duration']
        : 0;

    echo "║  Duration:           " . str_pad(round($metrics['total_duration'], 2) . " seconds", 44) . "║\n";
    echo "║  Total Requests:     " . str_pad($metrics['total_requests'], 44) . "║\n";
    echo "║  Successful:         " . str_pad($metrics['successful'] . " (" . round($successRate, 1) . "%)", 44) . "║\n";
    echo "║  Failed:             " . str_pad($metrics['failed'], 44) . "║\n";
    echo "║  Actual Rate:        " . str_pad(round($actualRate, 2) . " requests/sec", 44) . "║\n";
    echo "╠══════════════════════════════════════════════════════════════════╣\n";
    echo "║  Response Times:                                                 ║\n";
    echo "║  • Min:              " . str_pad(round($min * 1000, 1) . " ms", 44) . "║\n";
    echo "║  • Max:              " . str_pad(round($max * 1000, 1) . " ms", 44) . "║\n";
    echo "║  • Average:          " . str_pad(round($avg * 1000, 1) . " ms", 44) . "║\n";
    echo "║  • P50 (Median):     " . str_pad(round($p50 * 1000, 1) . " ms", 44) . "║\n";
    echo "║  • P95:              " . str_pad(round($p95 * 1000, 1) . " ms", 44) . "║\n";
    echo "║  • P99:              " . str_pad(round($p99 * 1000, 1) . " ms", 44) . "║\n";
    echo "╠══════════════════════════════════════════════════════════════════╣\n";
    echo "║  HTTP Response Codes:                                            ║\n";

    ksort($metrics['http_codes']);
    foreach ($metrics['http_codes'] as $code => $count) {
        $pct = ($count / $metrics['total_requests']) * 100;
        echo "║  • HTTP $code:         " . str_pad("$count (" . round($pct, 1) . "%)", 44) . "║\n";
    }

    echo "╚══════════════════════════════════════════════════════════════════╝\n";

    // Show errors if any
    if (count($metrics['errors']) > 0) {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║                         ERRORS                                   ║\n";
        echo "╠══════════════════════════════════════════════════════════════════╣\n";

        // Group and count errors
        $errorGroups = [];
        foreach ($metrics['errors'] as $err) {
            $key = $err['http_code'] . ': ' . $err['error'];
            if (!isset($errorGroups[$key])) {
                $errorGroups[$key] = ['count' => 0, 'sample' => $err];
            }
            $errorGroups[$key]['count']++;
        }

        foreach ($errorGroups as $key => $group) {
            echo "║  " . str_pad(substr($key, 0, 50) . " (x{$group['count']})", 64) . "║\n";
        }

        echo "╚══════════════════════════════════════════════════════════════════╝\n";
    }

    // Performance assessment
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║                    PERFORMANCE ASSESSMENT                        ║\n";
    echo "╠══════════════════════════════════════════════════════════════════╣\n";

    $grade = 'A';
    $notes = [];

    if ($successRate < 99) {
        $grade = $successRate < 95 ? 'F' : ($successRate < 98 ? 'C' : 'B');
        $notes[] = "Error rate is concerning (" . round(100 - $successRate, 1) . "%)";
    }

    if ($p95 > 2) {
        $grade = $p95 > 5 ? 'D' : 'C';
        $notes[] = "P95 response time is high (" . round($p95 * 1000) . "ms)";
    } elseif ($p95 > 1) {
        $notes[] = "P95 response time could be improved";
    }

    if ($avg > 1) {
        $notes[] = "Average response time is elevated";
    }

    if (count($notes) === 0) {
        $notes[] = "Server handled the load well!";
    }

    echo "║  Grade: " . str_pad($grade, 57) . "║\n";
    foreach ($notes as $note) {
        echo "║  • " . str_pad(substr($note, 0, 60), 61) . "║\n";
    }

    echo "╚══════════════════════════════════════════════════════════════════╝\n";
}

// Main execution
echo "Building request queue...\n";
$requests = buildRequests($config);
echo "Generated " . count($requests) . " requests\n\n";

// Confirm before running
if (!$config['dry_run']) {
    echo "WARNING: This will send " . count($requests) . " real requests to the server.\n";
    echo "The requests will be sent to STAGING (production=false).\n";
    echo "Press Enter to continue or Ctrl+C to abort...\n";

    // Check if running interactively (cross-platform)
    $isInteractive = defined('STDIN') && (PHP_OS_FAMILY !== 'Windows'
        ? (function_exists('posix_isatty') && posix_isatty(STDIN))
        : true);
    if ($isInteractive) {
        fgets(STDIN);
    }
}

$metrics = executeLoadTest($requests, $config);
displayResults($metrics);

// Save results to file
$resultsFile = __DIR__ . '/load_test_results_' . date('Y-m-d_His') . '.json';
$results = [
    'config' => $config,
    'metrics' => [
        'total_requests' => $metrics['total_requests'],
        'successful' => $metrics['successful'],
        'failed' => $metrics['failed'],
        'total_duration' => $metrics['total_duration'],
        'avg_response_time' => array_sum($metrics['response_times']) / max(1, count($metrics['response_times'])),
        'http_codes' => $metrics['http_codes'],
        'error_count' => count($metrics['errors'])
    ],
    'timestamp' => date('c')
];
file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT));
echo "\nResults saved to: $resultsFile\n";
