<?php
/**
 * Monitoring Log Analyzer
 *
 * Analyzes /home/LogFiles/monitoring.log to identify trends and potential issues.
 *
 * Usage:
 *   php analyze_monitoring.php                  # Last hour summary
 *   php analyze_monitoring.php --hours=24       # Last 24 hours
 *   php analyze_monitoring.php --alerts         # Show only alerts/warnings
 */

$options = getopt('', ['hours::', 'alerts', 'help', 'json']);

if (isset($options['help'])) {
    echo "Monitoring Log Analyzer\n";
    echo "=======================\n";
    echo "Usage: php analyze_monitoring.php [options]\n";
    echo "  --hours=N    Analyze last N hours (default: 1)\n";
    echo "  --alerts     Show only alerts/warnings\n";
    echo "  --json       Output as JSON\n";
    echo "  --help       Show this help\n";
    exit(0);
}

$hours = isset($options['hours']) ? (int)$options['hours'] : 1;
$alertsOnly = isset($options['alerts']);
$jsonOutput = isset($options['json']);

$logFile = '/home/LogFiles/monitoring.log';

if (!file_exists($logFile)) {
    echo "Log file not found: $logFile\n";
    echo "Run monitoring_daemon.php --loop first.\n";
    exit(1);
}

$cutoff = time() - ($hours * 3600);
$metrics = [];

// Parse log file
$handle = fopen($logFile, 'r');
while (($line = fgets($handle)) !== false) {
    $data = json_decode(trim($line), true);
    if (!$data || !isset($data['ts'])) continue;

    $ts = strtotime($data['ts']);
    if ($ts < $cutoff) continue;

    $metrics[] = $data;
}
fclose($handle);

if (empty($metrics)) {
    echo "No metrics found in the last $hours hour(s).\n";
    exit(0);
}

// Calculate statistics
$stats = [
    'period_hours' => $hours,
    'samples' => count($metrics),
    'first_sample' => $metrics[0]['ts'],
    'last_sample' => $metrics[count($metrics) - 1]['ts'],
    'fpm' => [
        'active_avg' => 0,
        'active_max' => 0,
        'queue_max' => 0,
        'max_children_reached' => 0,
    ],
    'db' => [
        'conns_avg' => 0,
        'conns_max' => 0,
        'blocking_total' => 0,
        'latency_avg' => 0,
        'latency_max' => 0,
    ],
    'memory' => [
        'used_pct_avg' => 0,
        'used_pct_max' => 0,
    ],
    'daemons' => [
        'running_min' => PHP_INT_MAX,
    ],
    'alerts' => [],
];

$fpmActiveSum = 0;
$fpmQueueSum = 0;
$dbConnsSum = 0;
$dbLatencySum = 0;
$memUsedSum = 0;
$memSamples = 0;

foreach ($metrics as $m) {
    // FPM
    $active = $m['fpm']['active'] ?? 0;
    $queue = $m['fpm']['queue'] ?? 0;
    $maxReached = $m['fpm']['max_reached'] ?? 0;

    $fpmActiveSum += $active;
    $stats['fpm']['active_max'] = max($stats['fpm']['active_max'], $active);
    $stats['fpm']['queue_max'] = max($stats['fpm']['queue_max'], $queue);
    $stats['fpm']['max_children_reached'] += $maxReached;

    // Alerts
    if ($queue > 5) {
        $stats['alerts'][] = ['time' => $m['ts'], 'type' => 'fpm_queue', 'value' => $queue];
    }

    // DB
    $conns = $m['db']['conns'] ?? 0;
    $blocking = $m['db']['blocking'] ?? 0;
    $latency = $m['db']['latency_ms'] ?? 0;

    $dbConnsSum += $conns;
    $dbLatencySum += $latency;
    $stats['db']['conns_max'] = max($stats['db']['conns_max'], $conns);
    $stats['db']['latency_max'] = max($stats['db']['latency_max'], $latency);
    $stats['db']['blocking_total'] += $blocking;

    if ($blocking > 0) {
        $stats['alerts'][] = ['time' => $m['ts'], 'type' => 'db_blocking', 'value' => $blocking];
    }

    // Memory
    $memUsed = $m['mem']['sys_used_pct'] ?? 0;
    if ($memUsed > 0) {
        $memUsedSum += $memUsed;
        $memSamples++;
        $stats['memory']['used_pct_max'] = max($stats['memory']['used_pct_max'], $memUsed);

        if ($memUsed > 85) {
            $stats['alerts'][] = ['time' => $m['ts'], 'type' => 'memory_high', 'value' => $memUsed];
        }
    }

    // Daemons
    $running = $m['daemons']['running'] ?? 0;
    $stats['daemons']['running_min'] = min($stats['daemons']['running_min'], $running);
}

$count = count($metrics);
$stats['fpm']['active_avg'] = round($fpmActiveSum / $count, 1);
$stats['db']['conns_avg'] = round($dbConnsSum / $count, 1);
$stats['db']['latency_avg'] = round($dbLatencySum / $count);
$stats['memory']['used_pct_avg'] = $memSamples > 0 ? round($memUsedSum / $memSamples, 1) : 0;

// Output
if ($jsonOutput) {
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($alertsOnly) {
    if (empty($stats['alerts'])) {
        echo "No alerts in the last $hours hour(s).\n";
    } else {
        echo "Alerts in the last $hours hour(s):\n";
        echo str_repeat('-', 60) . "\n";
        foreach ($stats['alerts'] as $alert) {
            echo sprintf("  [%s] %s: %s\n", $alert['time'], $alert['type'], $alert['value']);
        }
    }
    exit(0);
}

// Full report
echo "======================================\n";
echo "PERTI Monitoring Report\n";
echo "Period: Last $hours hour(s)\n";
echo "Samples: {$stats['samples']} ({$stats['first_sample']} to {$stats['last_sample']})\n";
echo "======================================\n\n";

echo "PHP-FPM Workers:\n";
echo "  Active:     avg={$stats['fpm']['active_avg']}, max={$stats['fpm']['active_max']}\n";
echo "  Queue max:  {$stats['fpm']['queue_max']}\n";
echo "  Max children reached: {$stats['fpm']['max_children_reached']} times\n";
if ($stats['fpm']['active_max'] >= 45) {
    echo "  WARNING: Workers near capacity (max is 50). Consider increasing pm.max_children.\n";
}
echo "\n";

echo "Database:\n";
echo "  Connections:  avg={$stats['db']['conns_avg']}, max={$stats['db']['conns_max']}\n";
echo "  Latency:      avg={$stats['db']['latency_avg']}ms, max={$stats['db']['latency_max']}ms\n";
echo "  Blocking:     {$stats['db']['blocking_total']} total incidents\n";
if ($stats['db']['blocking_total'] > 0) {
    echo "  WARNING: Database blocking detected! Check for long-running queries.\n";
}
echo "\n";

echo "Memory:\n";
echo "  System used:  avg={$stats['memory']['used_pct_avg']}%, max={$stats['memory']['used_pct_max']}%\n";
if ($stats['memory']['used_pct_max'] > 85) {
    echo "  WARNING: Memory usage exceeded 85%.\n";
}
echo "\n";

echo "Daemons:\n";
echo "  Minimum running: {$stats['daemons']['running_min']} (should be 4+)\n";
if ($stats['daemons']['running_min'] < 3) {
    echo "  WARNING: Daemons may have crashed. Check log files.\n";
}
echo "\n";

if (!empty($stats['alerts'])) {
    echo "Alerts (" . count($stats['alerts']) . " total):\n";
    foreach (array_slice($stats['alerts'], -10) as $alert) {
        echo "  [{$alert['time']}] {$alert['type']}: {$alert['value']}\n";
    }
}

echo "\n======================================\n";
echo "Capacity Planning:\n";
$fpmUtilization = ($stats['fpm']['active_max'] / 50) * 100;
echo "  Current FPM utilization: " . round($fpmUtilization) . "% of 50 workers\n";
if ($fpmUtilization > 80) {
    echo "  RECOMMENDATION: Increase pm.max_children to 75-100\n";
} elseif ($fpmUtilization < 30) {
    echo "  Current capacity is sufficient for expected growth.\n";
} else {
    echo "  Monitor during peak hours. May need to scale for SWIM.\n";
}
echo "======================================\n";
