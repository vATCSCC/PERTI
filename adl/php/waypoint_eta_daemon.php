<?php
/**
 * ADL Waypoint ETA Daemon
 *
 * Processes waypoint ETA calculations separately from the main VATSIM refresh daemon.
 * Uses tiered processing to prioritize flights based on proximity to next waypoint.
 *
 * Tiers:
 *   Tier 0: Flights within 30nm of next waypoint (every 15s)
 *   Tier 1: Enroute flights 30-100nm from next waypoint (every 30s)
 *   Tier 2: All other enroute flights (every 60s)
 *   Tier 3: Climbing/descending flights (every 2 min)
 *   Tier 4: Prefiles and taxiing (every 5 min)
 *
 * IMPORTANT: Uses $conn_adl (Azure SQL VATSIM_ADL database)
 *
 * Concurrency Protection:
 *   - PHP-level: PID file prevents multiple daemon instances
 *
 * Usage:
 *   php waypoint_eta_daemon.php              # Run once (all tiers)
 *   php waypoint_eta_daemon.php --loop       # Run continuously
 *   php waypoint_eta_daemon.php --loop --interval=15  # Custom interval
 *   php waypoint_eta_daemon.php --tier=0     # Run only tier 0 (imminent)
 */

// ============================================================================
// PID file to prevent multiple instances
// ============================================================================
define('PID_FILE', sys_get_temp_dir() . '/adl_waypoint_eta_daemon.pid');

function acquirePidLock(): bool {
    if (file_exists(PID_FILE)) {
        $existingPid = (int) file_get_contents(PID_FILE);
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$existingPid}\" 2>NUL", $output, $exitCode);
            $processExists = count($output) > 1;
        } else {
            $processExists = posix_kill($existingPid, 0);
        }
        if ($processExists) {
            echo "ERROR: Another instance is already running (PID: {$existingPid})\n";
            echo "If this is incorrect, delete: " . PID_FILE . "\n";
            return false;
        }
        unlink(PID_FILE);
    }
    file_put_contents(PID_FILE, getmypid());
    return true;
}

function releasePidLock(): void {
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
}

register_shutdown_function('releasePidLock');

require_once __DIR__ . '/../../load/connect.php';

// Configuration
define('DEFAULT_INTERVAL', 15);           // Base interval in seconds
define('DEFAULT_MAX_FLIGHTS', 500);       // Flights per batch
define('SP_TIMEOUT', 120);                // SQL timeout in seconds
define('STAGGER_OFFSET', 10);             // Seconds to offset from ADL daemon cycle to reduce contention

// Tier intervals (in cycles of base interval)
// With 15s base: Tier 0 = every 15s, Tier 1 = every 30s, etc.
define('TIER_INTERVALS', [
    0 => 1,    // Every cycle (15s) - imminent waypoint crossing
    1 => 2,    // Every 2 cycles (30s) - approaching waypoint
    2 => 4,    // Every 4 cycles (60s) - enroute
    3 => 8,    // Every 8 cycles (2 min) - transitioning
    4 => 20,   // Every 20 cycles (5 min) - ground/prefile
]);

class WaypointEtaDaemon
{
    private $conn;
    private int $maxFlights;
    private int $interval;
    private bool $running = true;
    private int $cycleCount = 0;

    public function __construct($conn_adl, int $maxFlights = DEFAULT_MAX_FLIGHTS, int $interval = DEFAULT_INTERVAL)
    {
        $this->conn = $conn_adl;
        $this->maxFlights = $maxFlights;
        $this->interval = $interval;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }

    public function shutdown(): void
    {
        $this->log("Shutdown signal received");
        $this->running = false;
    }

    private function log(string $message): void
    {
        $timestamp = gmdate('Y-m-d H:i:s\Z');
        echo "[{$timestamp}] {$message}\n";
    }

    /**
     * Determine which tier to process this cycle based on intervals
     */
    private function getTierForCycle(): int
    {
        $this->cycleCount++;
        $intervals = TIER_INTERVALS;

        // Process highest tier that matches this cycle
        // Tier 0 runs every cycle, Tier 1 every 2 cycles, etc.
        foreach ($intervals as $tier => $interval) {
            if ($this->cycleCount % $interval === 0) {
                return $tier;
            }
        }

        return 0; // Default to tier 0
    }

    /**
     * Execute waypoint ETA calculation SP for a specific tier
     */
    private function processWaypointEtas(?int $tier = null): ?array
    {
        $sql = "EXEC dbo.sp_CalculateWaypointETABatch_Tiered @tier = ?, @max_flights = ?, @debug = 0";
        $options = ['QueryTimeout' => SP_TIMEOUT];

        $startTime = microtime(true);
        $stmt = @sqlsrv_query($this->conn, $sql, [$tier, $this->maxFlights], $options);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $this->log("ERROR: Waypoint ETA SP failed - " . json_encode($errors[0]['message'] ?? 'Unknown'));
            return null;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            return [
                'flights_processed' => 0,
                'waypoints_updated' => 0,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
                'tier' => $tier,
            ];
        }

        return [
            'flights_processed' => $row['flights_processed'] ?? 0,
            'waypoints_updated' => $row['waypoints_updated'] ?? 0,
            'elapsed_ms' => $row['elapsed_ms'] ?? round((microtime(true) - $startTime) * 1000),
            'tier' => $row['tier_requested'] ?? $tier,
        ];
    }

    /**
     * Run a single processing cycle
     */
    public function runOnce(?int $forceTier = null): array
    {
        $tier = $forceTier ?? $this->getTierForCycle();

        $result = $this->processWaypointEtas($tier);

        if ($result === null) {
            return ['tier' => $tier, 'error' => true];
        }

        if ($result['flights_processed'] > 0) {
            $this->log(sprintf(
                "Tier %d: %d flights, %d waypoints updated in %dms",
                $tier,
                $result['flights_processed'],
                $result['waypoints_updated'],
                $result['elapsed_ms']
            ));
        }

        return $result;
    }

    /**
     * Run continuous loop with tiered intervals
     */
    public function runLoop(): void
    {
        $this->log("Starting waypoint ETA daemon (max_flights: {$this->maxFlights}, interval: {$this->interval}s)");
        $this->log("Tier intervals: " . json_encode(TIER_INTERVALS));
        $this->log("Connected to VATSIM_ADL Azure SQL database");

        // Stagger start to avoid colliding with ADL daemon's 15-second cycle
        if (STAGGER_OFFSET > 0) {
            $this->log("Staggering start by " . STAGGER_OFFSET . "s to reduce ADL contention...");
            sleep(STAGGER_OFFSET);
        }

        $stats = [
            'total_runs' => 0,
            'total_flights' => 0,
            'total_waypoints' => 0,
            'total_ms' => 0,
            'tier_runs' => [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0],
            'errors' => 0,
        ];

        while ($this->running) {
            $cycleStart = microtime(true);

            // Determine which tier to process
            $tier = $this->getTierForCycle();

            $result = $this->runOnce($tier);
            $stats['total_runs']++;
            $stats['tier_runs'][$tier]++;

            if (isset($result['error'])) {
                $stats['errors']++;
            } else {
                $stats['total_flights'] += $result['flights_processed'] ?? 0;
                $stats['total_waypoints'] += $result['waypoints_updated'] ?? 0;
                $stats['total_ms'] += $result['elapsed_ms'] ?? 0;
            }

            // Log stats every 20 runs (~5 minutes)
            if ($stats['total_runs'] % 20 === 0) {
                $avgMs = $stats['total_runs'] > 0 ? round($stats['total_ms'] / $stats['total_runs']) : 0;
                $this->log(sprintf(
                    "=== Stats @ run %d === flights: %d, waypoints: %d, avg_ms: %d, errors: %d, tiers: %s",
                    $stats['total_runs'],
                    $stats['total_flights'],
                    $stats['total_waypoints'],
                    $avgMs,
                    $stats['errors'],
                    json_encode($stats['tier_runs'])
                ));
            }

            // Sleep remaining time in interval
            $cycleElapsed = microtime(true) - $cycleStart;
            $sleepTime = $this->interval - $cycleElapsed;

            if ($sleepTime > 0 && $this->running) {
                usleep((int)($sleepTime * 1000000));
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->log("Daemon stopped");
    }
}

// =============================================================================
// Main entry point
// =============================================================================

$options = getopt('', ['loop', 'tier::', 'flights::', 'interval::', 'help']);

if (isset($options['help'])) {
    echo "ADL Waypoint ETA Daemon\n";
    echo "========================\n";
    echo "Calculates ETAs for waypoints along flight routes.\n\n";
    echo "Usage: php waypoint_eta_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --tier=N         Process only tier N (0-4), default: tiered by cycle\n";
    echo "  --flights=N      Max flights per batch (default: " . DEFAULT_MAX_FLIGHTS . ")\n";
    echo "  --interval=N     Base interval in seconds (default: " . DEFAULT_INTERVAL . ")\n";
    echo "  --help           Show this help\n";
    echo "\nTiers:\n";
    echo "  0: Imminent (<30nm to waypoint) - every 15s\n";
    echo "  1: Approaching (<100nm) - every 30s\n";
    echo "  2: Enroute - every 60s\n";
    echo "  3: Transitioning - every 2 min\n";
    echo "  4: Ground/Prefile - every 5 min\n";
    exit(0);
}

if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    echo "Make sure load/connect.php establishes \$conn_adl connection.\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database successfully.\n";

$maxFlights = isset($options['flights']) ? (int)$options['flights'] : DEFAULT_MAX_FLIGHTS;
$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;
$forceTier = isset($options['tier']) ? (int)$options['tier'] : null;

// Acquire PID lock for loop mode to prevent multiple instances
if (isset($options['loop'])) {
    if (!acquirePidLock()) {
        exit(1);
    }
    echo "PID lock acquired (PID: " . getmypid() . ")\n";
}

$daemon = new WaypointEtaDaemon($conn_adl, $maxFlights, $interval);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $result = $daemon->runOnce($forceTier);
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}
