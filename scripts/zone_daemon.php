<?php
/**
 * PERTI Zone Detection Daemon
 *
 * Runs independently from the main ADL daemon, processing zone detection
 * with tiered intervals to reduce database load.
 *
 * Tier System (15s VATSIM minimum):
 *   Tier 0: Active runway (GS > 30, on RWY) - Every 15s (every cycle)
 *   Tier 1: Taxiing (GS 5-30 kts) - Every 15s (every cycle)
 *   Tier 2: Parked (GS < 5 kts) - Every 60s (4 cycles)
 *   Tier 3: Arriving (>80% complete) - Every 15s (every cycle)
 *   Tier 4: Prefile/no position - Every 120s (8 cycles)
 *
 * Usage:
 *   php zone_daemon.php [--interval=15]
 *
 * Options:
 *   --interval=N   Base cycle interval in seconds (default: 15, min: 15)
 *
 * @package PERTI
 * @subpackage ADL
 * @version 1.0.0
 */

// Parse command line arguments
$options = getopt('', ['interval::', 'debug']);
$cycleInterval = isset($options['interval']) ? (int)$options['interval'] : 15;
$debug = isset($options['debug']);

// Enforce minimum 15s (VATSIM API refresh rate)
if ($cycleInterval < 15) {
    $cycleInterval = 15;
}

// Tier bitmasks
define('TIER_0_RUNWAY', 1);     // 2^0
define('TIER_1_TAXIING', 2);    // 2^1
define('TIER_2_PARKED', 4);     // 2^2
define('TIER_3_ARRIVING', 8);   // 2^3
define('TIER_4_PREFILE', 16);   // 2^4
define('TIER_ALL', 31);         // All tiers

// Tier intervals (in cycles, not seconds)
$tierIntervals = [
    0 => 1,   // Every cycle (15s)
    1 => 1,   // Every cycle (15s)
    2 => 4,   // Every 4 cycles (60s)
    3 => 1,   // Every cycle (15s)
    4 => 8,   // Every 8 cycles (120s)
];

echo "[ZONE DAEMON] Starting...\n";
echo "[ZONE DAEMON] Cycle interval: {$cycleInterval}s\n";
echo "[ZONE DAEMON] PID: " . getmypid() . "\n";
echo "[ZONE DAEMON] Tier intervals:\n";
echo "  Tier 0 (Runway):   Every {$tierIntervals[0]} cycle(s) = " . ($tierIntervals[0] * $cycleInterval) . "s\n";
echo "  Tier 1 (Taxiing):  Every {$tierIntervals[1]} cycle(s) = " . ($tierIntervals[1] * $cycleInterval) . "s\n";
echo "  Tier 2 (Parked):   Every {$tierIntervals[2]} cycle(s) = " . ($tierIntervals[2] * $cycleInterval) . "s\n";
echo "  Tier 3 (Arriving): Every {$tierIntervals[3]} cycle(s) = " . ($tierIntervals[3] * $cycleInterval) . "s\n";
echo "  Tier 4 (Prefile):  Every {$tierIntervals[4]} cycle(s) = " . ($tierIntervals[4] * $cycleInterval) . "s\n";

// Change to the web root directory
$wwwroot = getenv('WWWROOT') ?: dirname(__DIR__);
chdir($wwwroot);

echo "[ZONE DAEMON] Working directory: " . getcwd() . "\n";

// Include the database connection
require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/connect.php';

$conn = get_conn_adl();

if (!$conn) {
    echo "[ZONE DAEMON] ERROR: VATSIM_ADL connection failed\n";
    exit(1);
}

echo "[ZONE DAEMON] Connected to VATSIM_ADL\n";
echo "[ZONE DAEMON] Entering main loop...\n";
echo "========================================\n";

// Stats
$stats = [
    'cycles' => 0,
    'total_checked' => 0,
    'total_transitions' => 0,
    'total_ms' => 0,
    'errors' => 0,
];

$cycleCount = 0;

while (true) {
    $cycleStart = microtime(true);
    $cycleCount++;
    $now = gmdate('Y-m-d H:i:s');

    // Determine which tiers to process this cycle
    $tierMask = 0;
    $tiersProcessed = [];

    foreach ($tierIntervals as $tier => $interval) {
        if ($cycleCount % $interval === 0) {
            $tierMask |= (1 << $tier);
            $tiersProcessed[] = $tier;
        }
    }

    if ($tierMask === 0) {
        // No tiers to process this cycle (shouldn't happen with current config)
        sleep($cycleInterval);
        continue;
    }

    // Call the tiered zone detection SP
    $sql = "EXEC dbo.sp_ProcessZoneDetectionBatch_Tiered
            @tier_mask = ?,
            @transitions_detected = ? OUTPUT,
            @flights_checked = ? OUTPUT";

    $transitionsDetected = 0;
    $flightsChecked = 0;

    $params = [
        [$tierMask, SQLSRV_PARAM_IN],
        [&$transitionsDetected, SQLSRV_PARAM_INOUT, SQLSRV_PHPTYPE_INT],
        [&$flightsChecked, SQLSRV_PARAM_INOUT, SQLSRV_PHPTYPE_INT],
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        echo "[{$now}] ERROR: Zone detection failed - " . ($errors[0]['message'] ?? 'Unknown') . "\n";
        $stats['errors']++;
        sleep($cycleInterval);
        continue;
    }

    // Fetch output parameters
    sqlsrv_next_result($stmt);
    sqlsrv_free_stmt($stmt);

    $cycleMs = round((microtime(true) - $cycleStart) * 1000);

    // Update stats
    $stats['cycles']++;
    $stats['total_checked'] += $flightsChecked;
    $stats['total_transitions'] += $transitionsDetected;
    $stats['total_ms'] += $cycleMs;

    // Log output
    $tierStr = implode(',', $tiersProcessed);
    if ($debug || $transitionsDetected > 0 || $cycleCount % 20 === 0) {
        echo "[{$now}] Cycle #{$cycleCount}: Tiers [{$tierStr}] | ";
        echo "Checked: {$flightsChecked} | Transitions: {$transitionsDetected} | ";
        echo "{$cycleMs}ms\n";
    }

    // Periodic summary every 100 cycles (~25 minutes)
    if ($cycleCount % 100 === 0) {
        $avgMs = $stats['cycles'] > 0 ? round($stats['total_ms'] / $stats['cycles']) : 0;
        echo "========================================\n";
        echo "[ZONE DAEMON] Summary after {$stats['cycles']} cycles:\n";
        echo "  Total flights checked: {$stats['total_checked']}\n";
        echo "  Total transitions: {$stats['total_transitions']}\n";
        echo "  Average cycle time: {$avgMs}ms\n";
        echo "  Errors: {$stats['errors']}\n";
        echo "========================================\n";
    }

    // Sleep until next cycle
    $elapsed = microtime(true) - $cycleStart;
    $sleepTime = max(0, $cycleInterval - $elapsed);
    if ($sleepTime > 0) {
        usleep((int)($sleepTime * 1000000));
    }
}
