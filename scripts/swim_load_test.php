<?php
/**
 * VATSWIM API Load Testing Script
 *
 * Simulates realistic API load to validate system performance before production.
 * Tests REST API endpoints with configurable concurrency and duration.
 *
 * Usage:
 *   php swim_load_test.php [profile] [duration_seconds]
 *
 * Profiles:
 *   light    - 10 req/sec, simulates low traffic
 *   moderate - 50 req/sec, simulates normal operations
 *   heavy    - 200 req/sec, simulates peak traffic
 *   stress   - 500 req/sec, stress test for limits
 *
 * Example:
 *   php swim_load_test.php moderate 60
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

// Configuration
$config = [
    'base_url' => 'https://perti.vatcscc.org/api/swim/v1',
    'api_key' => getenv('SWIM_API_KEY') ?: 'swim_dev_test_001',
    'timeout' => 10,
    'profiles' => [
        'light' => ['rps' => 10, 'concurrency' => 2],
        'moderate' => ['rps' => 50, 'concurrency' => 10],
        'heavy' => ['rps' => 200, 'concurrency' => 50],
        'stress' => ['rps' => 500, 'concurrency' => 100],
    ],
    'endpoints' => [
        ['method' => 'GET', 'path' => '/', 'weight' => 5],
        ['method' => 'GET', 'path' => '/flights', 'weight' => 30],
        ['method' => 'GET', 'path' => '/flights?dest_icao=KJFK', 'weight' => 20],
        ['method' => 'GET', 'path' => '/flights?artcc=ZNY', 'weight' => 15],
        ['method' => 'GET', 'path' => '/positions', 'weight' => 10],
        ['method' => 'GET', 'path' => '/tmi/programs', 'weight' => 10],
        ['method' => 'GET', 'path' => '/metering/KJFK', 'weight' => 5],
        ['method' => 'GET', 'path' => '/flights?format=geojson', 'weight' => 5],
    ]
];

// Parse arguments
$profile = $argv[1] ?? 'light';
$duration = (int)($argv[2] ?? 30);

if (!isset($config['profiles'][$profile])) {
    echo "Invalid profile: $profile\n";
    echo "Available profiles: " . implode(', ', array_keys($config['profiles'])) . "\n";
    exit(1);
}

$settings = $config['profiles'][$profile];

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           VATSWIM API Load Testing Script                    ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Profile:     " . str_pad($profile, 45) . " ║\n";
echo "║  Target RPS:  " . str_pad($settings['rps'], 45) . " ║\n";
echo "║  Concurrency: " . str_pad($settings['concurrency'], 45) . " ║\n";
echo "║  Duration:    " . str_pad($duration . " seconds", 45) . " ║\n";
echo "║  Base URL:    " . str_pad(substr($config['base_url'], 0, 44), 45) . " ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Statistics
$stats = [
    'total_requests' => 0,
    'successful' => 0,
    'failed' => 0,
    'rate_limited' => 0,
    'timeouts' => 0,
    'latencies' => [],
    'status_codes' => [],
    'errors' => [],
    'start_time' => microtime(true),
];

// Build weighted endpoint list
$weightedEndpoints = [];
foreach ($config['endpoints'] as $endpoint) {
    for ($i = 0; $i < $endpoint['weight']; $i++) {
        $weightedEndpoints[] = $endpoint;
    }
}

/**
 * Make a single API request
 */
function makeRequest($url, $apiKey, $timeout) {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: VATSWIM-LoadTest/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $startTime = microtime(true);
    $response = curl_exec($ch);
    $latency = (microtime(true) - $startTime) * 1000;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 400,
        'status_code' => $httpCode,
        'latency_ms' => $latency,
        'error' => $error,
        'rate_limited' => $httpCode === 429,
        'timeout' => $httpCode === 0 && strpos($error, 'timed out') !== false,
    ];
}

/**
 * Run load test
 */
function runLoadTest($config, $settings, $duration, $weightedEndpoints, &$stats) {
    $endTime = microtime(true) + $duration;
    $requestInterval = 1.0 / $settings['rps']; // seconds between requests

    $lastReportTime = microtime(true);
    $reportInterval = 5; // Report every 5 seconds

    echo "Starting load test...\n\n";
    echo str_pad("Time", 8) . str_pad("Requests", 10) . str_pad("Success", 10) .
         str_pad("Failed", 10) . str_pad("429s", 8) . str_pad("Avg Latency", 12) . "\n";
    echo str_repeat("-", 58) . "\n";

    while (microtime(true) < $endTime) {
        $batchStart = microtime(true);

        // Send batch of concurrent requests
        $mh = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < min($settings['concurrency'], $settings['rps']); $i++) {
            $endpoint = $weightedEndpoints[array_rand($weightedEndpoints)];
            $url = $config['base_url'] . $endpoint['path'];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $config['timeout'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $config['api_key'],
                    'Accept: application/json',
                    'User-Agent: VATSWIM-LoadTest/1.0'
                ],
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'start' => microtime(true)];
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        // Process results
        foreach ($handles as $handle) {
            $ch = $handle['ch'];
            $latency = (microtime(true) - $handle['start']) * 1000;

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            $stats['total_requests']++;
            $stats['latencies'][] = $latency;

            if (!isset($stats['status_codes'][$httpCode])) {
                $stats['status_codes'][$httpCode] = 0;
            }
            $stats['status_codes'][$httpCode]++;

            if ($httpCode >= 200 && $httpCode < 400) {
                $stats['successful']++;
            } elseif ($httpCode === 429) {
                $stats['rate_limited']++;
                $stats['failed']++;
            } elseif ($httpCode === 0) {
                $stats['timeouts']++;
                $stats['failed']++;
            } else {
                $stats['failed']++;
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        // Report progress
        if (microtime(true) - $lastReportTime >= $reportInterval) {
            $elapsed = microtime(true) - $stats['start_time'];
            $avgLatency = count($stats['latencies']) > 0
                ? array_sum($stats['latencies']) / count($stats['latencies'])
                : 0;

            echo str_pad(sprintf("%.0fs", $elapsed), 8) .
                 str_pad($stats['total_requests'], 10) .
                 str_pad($stats['successful'], 10) .
                 str_pad($stats['failed'], 10) .
                 str_pad($stats['rate_limited'], 8) .
                 str_pad(sprintf("%.1fms", $avgLatency), 12) . "\n";

            $lastReportTime = microtime(true);
        }

        // Pace requests
        $batchDuration = microtime(true) - $batchStart;
        $targetBatchTime = $settings['concurrency'] * $requestInterval;
        if ($batchDuration < $targetBatchTime) {
            usleep(($targetBatchTime - $batchDuration) * 1000000);
        }
    }
}

// Run the test
runLoadTest($config, $settings, $duration, $weightedEndpoints, $stats);

// Calculate final statistics
$totalTime = microtime(true) - $stats['start_time'];
$actualRps = $stats['total_requests'] / $totalTime;

$latencies = $stats['latencies'];
sort($latencies);
$count = count($latencies);

$avgLatency = $count > 0 ? array_sum($latencies) / $count : 0;
$minLatency = $count > 0 ? min($latencies) : 0;
$maxLatency = $count > 0 ? max($latencies) : 0;
$p50 = $count > 0 ? $latencies[(int)($count * 0.50)] : 0;
$p95 = $count > 0 ? $latencies[(int)($count * 0.95)] : 0;
$p99 = $count > 0 ? $latencies[(int)($count * 0.99)] : 0;

$successRate = $stats['total_requests'] > 0
    ? ($stats['successful'] / $stats['total_requests']) * 100
    : 0;

// Print final report
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    LOAD TEST RESULTS                         ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  REQUESTS                                                    ║\n";
echo "║    Total:        " . str_pad(number_format($stats['total_requests']), 42) . " ║\n";
echo "║    Successful:   " . str_pad(number_format($stats['successful']), 42) . " ║\n";
echo "║    Failed:       " . str_pad(number_format($stats['failed']), 42) . " ║\n";
echo "║    Rate Limited: " . str_pad(number_format($stats['rate_limited']), 42) . " ║\n";
echo "║    Timeouts:     " . str_pad(number_format($stats['timeouts']), 42) . " ║\n";
echo "║    Success Rate: " . str_pad(sprintf("%.2f%%", $successRate), 42) . " ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  THROUGHPUT                                                  ║\n";
echo "║    Target RPS:   " . str_pad($settings['rps'], 42) . " ║\n";
echo "║    Actual RPS:   " . str_pad(sprintf("%.2f", $actualRps), 42) . " ║\n";
echo "║    Duration:     " . str_pad(sprintf("%.2f seconds", $totalTime), 42) . " ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  LATENCY (milliseconds)                                      ║\n";
echo "║    Min:          " . str_pad(sprintf("%.2f ms", $minLatency), 42) . " ║\n";
echo "║    Avg:          " . str_pad(sprintf("%.2f ms", $avgLatency), 42) . " ║\n";
echo "║    Max:          " . str_pad(sprintf("%.2f ms", $maxLatency), 42) . " ║\n";
echo "║    P50:          " . str_pad(sprintf("%.2f ms", $p50), 42) . " ║\n";
echo "║    P95:          " . str_pad(sprintf("%.2f ms", $p95), 42) . " ║\n";
echo "║    P99:          " . str_pad(sprintf("%.2f ms", $p99), 42) . " ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  STATUS CODES                                                ║\n";
ksort($stats['status_codes']);
foreach ($stats['status_codes'] as $code => $count) {
    echo "║    HTTP $code:     " . str_pad(number_format($count), 42) . " ║\n";
}
echo "╚══════════════════════════════════════════════════════════════╝\n";

// Recommendations
echo "\n";
echo "RECOMMENDATIONS:\n";
echo str_repeat("-", 60) . "\n";

if ($stats['rate_limited'] > 0) {
    $pct = ($stats['rate_limited'] / $stats['total_requests']) * 100;
    echo "⚠  Rate limiting triggered ($stats[rate_limited] requests, " . sprintf("%.1f%%", $pct) . ")\n";
    echo "   Consider using a higher tier API key for this load level.\n\n";
}

if ($p95 > 500) {
    echo "⚠  P95 latency is high (" . sprintf("%.0f ms", $p95) . ")\n";
    echo "   Consider database optimization or caching improvements.\n\n";
}

if ($successRate < 99) {
    echo "⚠  Success rate below 99% ($successRate%)\n";
    echo "   Check error logs for failing requests.\n\n";
}

if ($actualRps < $settings['rps'] * 0.8) {
    echo "⚠  Actual throughput below target\n";
    echo "   Target: $settings[rps] RPS, Actual: " . sprintf("%.1f", $actualRps) . " RPS\n";
    echo "   May indicate server-side bottleneck or network issues.\n\n";
}

if ($successRate >= 99 && $p95 < 200 && $stats['rate_limited'] == 0) {
    echo "✓  All metrics within acceptable ranges!\n";
    echo "   System appears ready for this load level.\n";
}

echo "\n";
exit($successRate >= 95 ? 0 : 1);
