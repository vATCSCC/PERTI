<?php
/**
 * ADL Boundary Detection Daemon
 *
 * Processes boundary detection (ARTCC/TRACON) and planned crossings separately
 * from the main VATSIM refresh daemon. This prevents timeouts from blocking
 * flight data ingestion.
 *
 * IMPORTANT: Uses $conn_adl (Azure SQL VATSIM_ADL database)
 *
 * Usage:
 *   php boundary_daemon.php              # Run once
 *   php boundary_daemon.php --loop       # Run continuously
 *   php boundary_daemon.php --loop --interval=30  # Custom interval
 */

require_once __DIR__ . '/../../load/connect.php';

// Configuration
define('DEFAULT_MAX_FLIGHTS', 500);      // Flights per boundary run
define('DEFAULT_MAX_CROSSINGS', 100);    // Crossings per run
define('DEFAULT_INTERVAL', 30);          // Seconds between runs
define('SP_TIMEOUT', 120);               // SQL timeout in seconds

class BoundaryDaemon
{
    private $conn;
    private int $maxFlights;
    private int $maxCrossings;
    private int $interval;
    private bool $running = true;

    public function __construct($conn_adl, int $maxFlights = DEFAULT_MAX_FLIGHTS, int $maxCrossings = DEFAULT_MAX_CROSSINGS, int $interval = DEFAULT_INTERVAL)
    {
        $this->conn = $conn_adl;
        $this->maxFlights = $maxFlights;
        $this->maxCrossings = $maxCrossings;
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
     * Get count of flights pending boundary detection
     */
    private function getPendingCount(): int
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM dbo.adl_flight_core c
                JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
                WHERE c.is_active = 1
                  AND p.lat IS NOT NULL
                  AND (c.current_artcc_id IS NULL
                       OR c.last_grid_lat IS NULL
                       OR c.last_grid_lat != CAST(FLOOR(p.lat / 0.5) AS SMALLINT)
                       OR c.last_grid_lon != CAST(FLOOR(p.lon / 0.5) AS SMALLINT))";

        $stmt = sqlsrv_query($this->conn, $sql);
        if ($stmt === false) return 0;

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row['cnt'] ?? 0;
    }

    /**
     * Execute boundary detection SP
     */
    private function processBoundary(): ?array
    {
        $sql = "EXEC dbo.sp_ProcessBoundaryAndCrossings_Background @max_flights_per_run = ?, @max_crossings_per_run = ?, @debug = 0";
        $options = ['QueryTimeout' => SP_TIMEOUT];

        $startTime = microtime(true);
        $stmt = @sqlsrv_query($this->conn, $sql, [$this->maxFlights, $this->maxCrossings], $options);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $this->log("ERROR: Boundary SP failed - " . json_encode($errors[0]['message'] ?? 'Unknown'));
            return null;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $elapsedMs = round((microtime(true) - $startTime) * 1000);

        return [
            'boundary_flights' => $row['boundary_flights'] ?? 0,
            'boundary_transitions' => $row['boundary_transitions'] ?? 0,
            'crossings_calculated' => $row['crossings_calculated'] ?? 0,
            'elapsed_ms' => $row['elapsed_ms'] ?? $elapsedMs,
        ];
    }

    /**
     * Run a single processing cycle
     */
    public function runOnce(): array
    {
        $pending = $this->getPendingCount();

        if ($pending == 0) {
            $this->log("No flights pending boundary detection");
            return ['pending' => 0, 'processed' => 0];
        }

        // Adaptive batch size based on backlog
        $isBacklogged = $pending > 500;
        $batchFlights = $isBacklogged ? 1000 : $this->maxFlights;

        if ($isBacklogged) {
            $this->log("BACKLOG: {$pending} pending - processing up to {$batchFlights} flights");
        } else {
            $this->log("Processing boundary detection ({$pending} pending)...");
        }

        // Temporarily increase batch size for this run
        $origMaxFlights = $this->maxFlights;
        $this->maxFlights = $batchFlights;

        $result = $this->processBoundary();

        $this->maxFlights = $origMaxFlights;

        if ($result === null) {
            return ['pending' => $pending, 'processed' => 0, 'error' => true];
        }

        $this->log(sprintf(
            "Processed %d flights, %d transitions, %d crossings in %dms",
            $result['boundary_flights'],
            $result['boundary_transitions'],
            $result['crossings_calculated'],
            $result['elapsed_ms']
        ));

        return [
            'pending' => $pending,
            'processed' => $result['boundary_flights'],
            'transitions' => $result['boundary_transitions'],
            'crossings' => $result['crossings_calculated'],
            'elapsed_ms' => $result['elapsed_ms'],
        ];
    }

    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $this->log("Starting boundary daemon (max_flights: {$this->maxFlights}, interval: {$this->interval}s)");
        $this->log("Connected to VATSIM_ADL Azure SQL database");

        $totalRuns = 0;
        $totalTransitions = 0;
        $totalCrossings = 0;
        $totalMs = 0;

        while ($this->running) {
            $result = $this->runOnce();
            $totalRuns++;

            if (!isset($result['error'])) {
                $totalTransitions += $result['transitions'] ?? 0;
                $totalCrossings += $result['crossings'] ?? 0;
                $totalMs += $result['elapsed_ms'] ?? 0;
            }

            // Log stats every 10 runs
            if ($totalRuns % 10 === 0) {
                $avgMs = $totalRuns > 0 ? round($totalMs / $totalRuns) : 0;
                $this->log("=== Stats @ run {$totalRuns} === transitions: {$totalTransitions}, crossings: {$totalCrossings}, avg_ms: {$avgMs}");
            }

            if ($this->running) {
                // Sleep less if backlogged
                $pending = $result['pending'] ?? 0;
                $sleepTime = ($pending > 500) ? max(5, $this->interval / 2) : $this->interval;
                sleep((int)$sleepTime);
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

$options = getopt('', ['loop', 'flights::', 'crossings::', 'interval::', 'help']);

if (isset($options['help'])) {
    echo "ADL Boundary Detection Daemon\n";
    echo "==============================\n";
    echo "Processes ARTCC/TRACON boundary detection and planned crossings.\n\n";
    echo "Usage: php boundary_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --flights=N      Max flights per run (default: 500)\n";
    echo "  --crossings=N    Max crossings per run (default: 100)\n";
    echo "  --interval=N     Seconds between runs (default: 30)\n";
    echo "  --help           Show this help\n";
    exit(0);
}

if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database successfully.\n";

$maxFlights = isset($options['flights']) ? (int)$options['flights'] : DEFAULT_MAX_FLIGHTS;
$maxCrossings = isset($options['crossings']) ? (int)$options['crossings'] : DEFAULT_MAX_CROSSINGS;
$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;

$daemon = new BoundaryDaemon($conn_adl, $maxFlights, $maxCrossings, $interval);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $stats = $daemon->runOnce();
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}
