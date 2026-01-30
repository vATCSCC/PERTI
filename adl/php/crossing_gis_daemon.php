<?php
/**
 * GIS-Based Crossing Calculation Daemon
 *
 * Calculates planned boundary crossings using PostGIS trajectory-line intersection
 * instead of the ADL waypoint-containment approach. This provides:
 *
 *   - Precise crossing coordinates (not just "between waypoint A and B")
 *   - Accurate distance-along-route to each crossing point
 *   - ETAs calculated at actual crossing locations
 *   - Handles diagonal boundary crossings correctly
 *
 * Tiers (same as waypoint_eta_daemon):
 *   Tier 0: Flights within 30nm of next waypoint (every 15s)
 *   Tier 1: Enroute flights 30-100nm from next waypoint (every 30s)
 *   Tier 2: All other enroute flights (every 60s)
 *   Tier 3: Climbing/descending flights (every 2 min)
 *   Tier 4: Prefiles and taxiing (every 5 min)
 *
 * Usage:
 *   php crossing_gis_daemon.php              # Run once
 *   php crossing_gis_daemon.php --loop       # Run continuously
 *   php crossing_gis_daemon.php --tier=0     # Process only tier 0
 *   php crossing_gis_daemon.php --batch=100  # Batch size
 */

// ============================================================================
// PID file to prevent multiple instances
// ============================================================================
define('PID_FILE', sys_get_temp_dir() . '/crossing_gis_daemon.pid');

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
require_once __DIR__ . '/../../load/services/GISService.php';

// Configuration
define('DEFAULT_INTERVAL', 30);           // Base interval in seconds
define('DEFAULT_BATCH_SIZE', 50);         // Flights per batch
define('DEFAULT_MAX_FLIGHTS', 200);       // Max flights per cycle

// Tier intervals (in cycles of base interval)
define('TIER_INTERVALS', [
    0 => 1,    // Every cycle (30s) - imminent
    1 => 2,    // Every 2 cycles (60s)
    2 => 4,    // Every 4 cycles (2 min)
    3 => 8,    // Every 8 cycles (4 min)
    4 => 16,   // Every 16 cycles (8 min)
]);

class CrossingGISDaemon
{
    private $connAdl;
    private ?GISService $gis = null;
    private int $batchSize;
    private int $maxFlights;
    private int $interval;
    private bool $running = true;
    private int $cycleCount = 0;

    // Stats
    private int $totalFlightsProcessed = 0;
    private int $totalCrossingsFound = 0;
    private int $totalGisSuccess = 0;
    private int $totalFailed = 0;

    public function __construct(
        $conn_adl,
        int $batchSize = DEFAULT_BATCH_SIZE,
        int $maxFlights = DEFAULT_MAX_FLIGHTS,
        int $interval = DEFAULT_INTERVAL
    ) {
        $this->connAdl = $conn_adl;
        $this->batchSize = $batchSize;
        $this->maxFlights = $maxFlights;
        $this->interval = $interval;

        // Initialize PostGIS connection
        $this->gis = GISService::getInstance();
        if (!$this->gis || !$this->gis->isConnected()) {
            $this->log("WARNING: PostGIS not available - daemon will not function");
        }

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

    private function getSqlError(): string
    {
        $errors = sqlsrv_errors();
        if ($errors) {
            return $errors[0]['message'] ?? 'Unknown SQL error';
        }
        return 'Unknown error';
    }

    /**
     * Get tier for this cycle based on intervals
     */
    private function getTierForCycle(): int
    {
        $this->cycleCount++;
        foreach (TIER_INTERVALS as $tier => $interval) {
            if ($this->cycleCount % $interval === 0) {
                return $tier;
            }
        }
        return 0;
    }

    /**
     * Fetch flights needing crossing calculation with their waypoints
     */
    private function fetchFlightsWithWaypoints(int $tier): array
    {
        // Select flights based on tier (similar to waypoint_eta_daemon)
        $tierCondition = match ($tier) {
            0 => "AND pos.dist_to_next_waypoint_nm <= 30",
            1 => "AND pos.dist_to_next_waypoint_nm > 30 AND pos.dist_to_next_waypoint_nm <= 100",
            2 => "AND pos.dist_to_next_waypoint_nm > 100",
            3 => "AND core.flight_phase IN ('CLIMBING', 'DESCENDING')",
            4 => "AND core.flight_phase IN ('PREFILED', 'TAXIING', 'PUSHBACK')",
            default => ""
        };

        // Fetch flights with recent position updates that need crossing recalculation
        $sql = "
            SELECT TOP ({$this->maxFlights})
                core.flight_uid,
                core.callsign,
                pos.lat AS current_lat,
                pos.lon AS current_lon,
                pos.groundspeed_kts,
                pos.dist_flown_nm,
                plan.waypoint_count,
                DATEDIFF(MINUTE, ISNULL(xing.last_calc, '2000-01-01'), GETUTCDATE()) AS mins_since_calc
            FROM dbo.adl_flight_core core
            JOIN dbo.adl_flight_position pos ON core.flight_uid = pos.flight_uid
            JOIN dbo.adl_flight_plan plan ON core.flight_uid = plan.flight_uid
            LEFT JOIN (
                SELECT flight_uid, MAX(calculated_at) AS last_calc
                FROM dbo.adl_flight_planned_crossings
                GROUP BY flight_uid
            ) xing ON core.flight_uid = xing.flight_uid
            WHERE core.is_active = 1
              AND plan.waypoint_count >= 2
              AND pos.lat IS NOT NULL
              AND pos.lon IS NOT NULL
              {$tierCondition}
              -- Only recalculate if:
              -- 1. Never calculated, or
              -- 2. Last calc > 5 minutes ago, or
              -- 3. Position changed significantly (via boundary_updated_at proxy)
              AND (
                  xing.last_calc IS NULL
                  OR DATEDIFF(MINUTE, xing.last_calc, GETUTCDATE()) > 5
              )
            ORDER BY
                CASE WHEN xing.last_calc IS NULL THEN 0 ELSE 1 END,  -- Prioritize never-calculated
                pos.dist_to_next_waypoint_nm ASC  -- Then by proximity to next waypoint
        ";

        $stmt = sqlsrv_query($this->connAdl, $sql);
        if ($stmt === false) {
            $this->log("ERROR: Failed to fetch flights - " . $this->getSqlError());
            return [];
        }

        $flights = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Fetch waypoints for this flight
            $waypoints = $this->fetchWaypoints($row['flight_uid']);
            if (count($waypoints) >= 2) {
                $flights[] = [
                    'flight_uid' => $row['flight_uid'],
                    'callsign' => $row['callsign'],
                    'current_lat' => (float)$row['current_lat'],
                    'current_lon' => (float)$row['current_lon'],
                    'groundspeed_kts' => (int)$row['groundspeed_kts'],
                    'dist_flown_nm' => (float)$row['dist_flown_nm'],
                    'waypoints' => $waypoints
                ];
            }
        }
        sqlsrv_free_stmt($stmt);

        return $flights;
    }

    /**
     * Fetch waypoints for a flight
     */
    private function fetchWaypoints(int $flightUid): array
    {
        $sql = "
            SELECT
                sequence_num,
                lat,
                lon,
                fix_name
            FROM dbo.adl_flight_waypoints
            WHERE flight_uid = ?
              AND lat IS NOT NULL
              AND lon IS NOT NULL
            ORDER BY sequence_num
        ";

        $stmt = sqlsrv_query($this->connAdl, $sql, [$flightUid]);
        if ($stmt === false) {
            return [];
        }

        $waypoints = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $waypoints[] = [
                'sequence_num' => (int)$row['sequence_num'],
                'lat' => (float)$row['lat'],
                'lon' => (float)$row['lon'],
                'fix_name' => $row['fix_name']
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $waypoints;
    }

    /**
     * Calculate crossings for a single flight using PostGIS
     */
    private function calculateCrossingsForFlight(array $flight): array
    {
        if (!$this->gis) {
            return [];
        }

        return $this->gis->calculateCrossingEtas(
            $flight['waypoints'],
            $flight['current_lat'],
            $flight['current_lon'],
            $flight['dist_flown_nm'],
            $flight['groundspeed_kts']
        );
    }

    /**
     * Write crossings to ADL
     */
    private function writeCrossingsToADL(int $flightUid, array $crossings, int $tier): int
    {
        if (empty($crossings)) {
            return 0;
        }

        // Delete existing crossings for this flight
        $deleteSql = "DELETE FROM dbo.adl_flight_planned_crossings WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->connAdl, $deleteSql, [$flightUid]);
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
        }

        // Insert new crossings
        $insertSql = "
            INSERT INTO dbo.adl_flight_planned_crossings (
                flight_uid,
                crossing_source,
                boundary_code,
                boundary_type,
                crossing_type,
                crossing_order,
                entry_lat,
                entry_lon,
                planned_entry_utc,
                calculated_at,
                calculation_tier
            ) VALUES (?, 'BOUNDARY', ?, ?, ?, ?, ?, ?, ?, GETUTCDATE(), ?)
        ";

        $inserted = 0;
        $order = 1;

        foreach ($crossings as $crossing) {
            // Convert ETA string to DateTime if needed
            $eta = null;
            if (!empty($crossing['eta_utc'])) {
                try {
                    $eta = new DateTime($crossing['eta_utc']);
                } catch (Exception $e) {
                    $eta = null;
                }
            }

            $params = [
                $flightUid,
                $crossing['boundary_code'],
                $crossing['boundary_type'],
                $crossing['crossing_type'],
                $order,
                $crossing['crossing_lat'],
                $crossing['crossing_lon'],
                $eta,
                $tier
            ];

            $stmt = sqlsrv_query($this->connAdl, $insertSql, $params);
            if ($stmt !== false) {
                sqlsrv_free_stmt($stmt);
                $inserted++;
                $order++;
            }
        }

        return $inserted;
    }

    /**
     * Run a single processing cycle
     */
    public function runOnce(?int $forceTier = null): array
    {
        if (!$this->gis || !$this->gis->isConnected()) {
            return ['error' => true, 'message' => 'PostGIS not connected'];
        }

        $startTime = microtime(true);
        $tier = $forceTier ?? $this->getTierForCycle();

        // Fetch flights with waypoints
        $flights = $this->fetchFlightsWithWaypoints($tier);

        if (empty($flights)) {
            return [
                'tier' => $tier,
                'flights' => 0,
                'crossings' => 0,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000)
            ];
        }

        $totalCrossings = 0;
        $flightsProcessed = 0;

        // Process in batches to avoid memory issues
        foreach (array_chunk($flights, $this->batchSize) as $batch) {
            foreach ($batch as $flight) {
                $crossings = $this->calculateCrossingsForFlight($flight);

                if (!empty($crossings)) {
                    $inserted = $this->writeCrossingsToADL($flight['flight_uid'], $crossings, $tier);
                    $totalCrossings += $inserted;
                    $this->totalGisSuccess++;
                } else {
                    // No crossings found - might be entirely within one ARTCC
                    // Still mark as processed by inserting a placeholder or just counting
                }

                $flightsProcessed++;
            }
        }

        $this->totalFlightsProcessed += $flightsProcessed;
        $this->totalCrossingsFound += $totalCrossings;

        $elapsedMs = round((microtime(true) - $startTime) * 1000);

        return [
            'tier' => $tier,
            'flights' => $flightsProcessed,
            'crossings' => $totalCrossings,
            'elapsed_ms' => $elapsedMs,
            'cycle' => $this->cycleCount
        ];
    }

    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $this->log("Starting crossing GIS daemon");
        $this->log("Config: batch={$this->batchSize}, max_flights={$this->maxFlights}, interval={$this->interval}s");
        $this->log("PostGIS: " . ($this->gis && $this->gis->isConnected() ? "connected" : "NOT CONNECTED"));

        if (!$this->gis || !$this->gis->isConnected()) {
            $this->log("ERROR: Cannot start without PostGIS connection");
            return;
        }

        while ($this->running) {
            $result = $this->runOnce();

            if (isset($result['error'])) {
                $this->log("ERROR: " . ($result['message'] ?? 'Unknown'));
                $this->totalFailed++;
            } elseif ($result['flights'] > 0) {
                $this->log(sprintf(
                    "Tier %d: %d flights, %d crossings, %dms",
                    $result['tier'],
                    $result['flights'],
                    $result['crossings'],
                    $result['elapsed_ms']
                ));
            }

            // Log stats every 20 cycles
            if ($this->cycleCount % 20 === 0 && $this->cycleCount > 0) {
                $this->log(sprintf(
                    "=== Stats @ cycle %d === flights: %d, crossings: %d, GIS: %d, failed: %d",
                    $this->cycleCount,
                    $this->totalFlightsProcessed,
                    $this->totalCrossingsFound,
                    $this->totalGisSuccess,
                    $this->totalFailed
                ));
            }

            if ($this->running) {
                sleep($this->interval);
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

$options = getopt('', ['loop', 'tier::', 'batch::', 'max::', 'interval::', 'help']);

if (isset($options['help'])) {
    echo "GIS-Based Crossing Calculation Daemon\n";
    echo "=====================================\n";
    echo "Calculates boundary crossings using PostGIS trajectory intersection.\n\n";
    echo "Usage: php crossing_gis_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --tier=N         Process only tier N (0-4)\n";
    echo "  --batch=N        Batch size (default: " . DEFAULT_BATCH_SIZE . ")\n";
    echo "  --max=N          Max flights per cycle (default: " . DEFAULT_MAX_FLIGHTS . ")\n";
    echo "  --interval=N     Base interval in seconds (default: " . DEFAULT_INTERVAL . ")\n";
    echo "  --help           Show this help\n";
    exit(0);
}

if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database.\n";

$batchSize = isset($options['batch']) ? (int)$options['batch'] : DEFAULT_BATCH_SIZE;
$maxFlights = isset($options['max']) ? (int)$options['max'] : DEFAULT_MAX_FLIGHTS;
$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;
$forceTier = isset($options['tier']) ? (int)$options['tier'] : null;

if (isset($options['loop'])) {
    if (!acquirePidLock()) {
        exit(1);
    }
    echo "PID lock acquired (PID: " . getmypid() . ")\n";
}

$daemon = new CrossingGISDaemon($conn_adl, $batchSize, $maxFlights, $interval);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $result = $daemon->runOnce($forceTier);
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}
