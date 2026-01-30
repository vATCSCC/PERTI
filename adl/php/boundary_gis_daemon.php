<?php
/**
 * ADL Boundary Detection Daemon - PostGIS Version
 *
 * Processes boundary detection (ARTCC/TRACON) using PostGIS instead of ADL's
 * STContains spatial queries. This offloads spatial workload from the expensive
 * Hyperscale ADL to the cheaper PostGIS B2s instance.
 *
 * ARCHITECTURE:
 *   1. Read flights needing boundary detection from ADL (same as original)
 *   2. Send lat/lon/altitude batch to PostGIS for spatial lookup
 *   3. Write results (ARTCC code, TRACON code) back to ADL
 *
 * TIERING (matches ADL sp_ProcessBoundaryAndCrossings_Background):
 *   Tier 1: New flights (no current_artcc_id) - every cycle
 *   Tier 2: Grid cell changed - every cycle
 *   Tier 3: Below FL180 (terminal) - every 2 cycles
 *   Tier 4: Enroute (FL180-FL450) - every 5 cycles
 *   Tier 5: High altitude (FL450+) - every 10 cycles
 *
 * DATABASES:
 *   - $conn_adl (Azure SQL): adl_flight_core, adl_flight_position
 *   - PostGIS (via GISService): artcc_boundaries, tracon_boundaries
 *
 * USAGE:
 *   php boundary_gis_daemon.php              # Run once
 *   php boundary_gis_daemon.php --loop       # Run continuously
 *   php boundary_gis_daemon.php --loop --interval=15
 *
 * FALLBACK:
 *   If PostGIS is unavailable, set --adl-only to fall back to ADL SP
 */

// ============================================================================
// PID file to prevent multiple instances
// ============================================================================
define('PID_FILE', sys_get_temp_dir() . '/adl_boundary_gis_daemon.pid');
define('GRID_SIZE', 0.5);  // Grid cell size in degrees (~30nm at mid-latitude)

function acquirePidLock(): bool
{
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

function releasePidLock(): void
{
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
}

register_shutdown_function('releasePidLock');

require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../load/services/GISService.php';

// Configuration
define('DEFAULT_MAX_FLIGHTS', 100);
define('DEFAULT_INTERVAL', 15);
define('ADL_TIMEOUT', 120);  // SQL timeout for ADL writes

class BoundaryGISDaemon
{
    private $connAdl;
    private ?GISService $gis;
    private int $maxFlights;
    private int $interval;
    private bool $running = true;
    private bool $adlOnly = false;
    private int $cycleCount = 0;

    // Stats
    private int $totalGisSuccess = 0;
    private int $totalAdlFallback = 0;
    private int $totalFailed = 0;
    private int $totalTransitions = 0;

    public function __construct(
        $conn_adl,
        int $maxFlights = DEFAULT_MAX_FLIGHTS,
        int $interval = DEFAULT_INTERVAL,
        bool $adlOnly = false
    ) {
        $this->connAdl = $conn_adl;
        $this->maxFlights = $maxFlights;
        $this->interval = $interval;
        $this->adlOnly = $adlOnly;

        // Get GIS service (may be null if not configured)
        $this->gis = GISService::getInstance();

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
     * Get flights needing boundary detection from ADL with tiering
     *
     * Tiering schedule (similar to ADL SP):
     *   Tier 1: New flights (no ARTCC) - every cycle
     *   Tier 2: Grid changed - every cycle
     *   Tier 3: Terminal (<FL180) - every 2 cycles
     *   Tier 4: Enroute (FL180-FL450) - every 5 cycles
     *   Tier 5: High (>FL450) - every 10 cycles
     */
    private function getFlightsNeedingDetection(): array
    {
        $this->cycleCount++;
        $cycle = $this->cycleCount;
        $gridSize = GRID_SIZE;

        // Build tier conditions based on cycle number
        $tierConditions = [];

        // Tier 1 & 2: Always process new flights and grid-changed flights
        $tierConditions[] = "(c.current_artcc_id IS NULL)";
        $tierConditions[] = "(c.last_grid_lat IS NULL OR c.last_grid_lat != CAST(FLOOR(p.lat / {$gridSize}) AS SMALLINT) OR c.last_grid_lon != CAST(FLOOR(p.lon / {$gridSize}) AS SMALLINT))";

        // Tier 3: Terminal (below FL180) every 2 cycles
        if ($cycle % 2 === 0) {
            $tierConditions[] = "(p.altitude_ft < 18000 AND c.current_artcc_id IS NOT NULL)";
        }

        // Tier 4: Enroute (FL180-FL450) every 5 cycles
        if ($cycle % 5 === 0) {
            $tierConditions[] = "(p.altitude_ft BETWEEN 18000 AND 45000 AND c.current_artcc_id IS NOT NULL)";
        }

        // Tier 5: High altitude (>FL450) every 10 cycles
        if ($cycle % 10 === 0) {
            $tierConditions[] = "(p.altitude_ft > 45000 AND c.current_artcc_id IS NOT NULL)";
        }

        $whereClause = implode(" OR ", $tierConditions);

        $sql = "
            SELECT TOP ({$this->maxFlights})
                c.flight_uid,
                p.lat,
                p.lon,
                ISNULL(p.altitude_ft, 0) AS altitude_ft,
                c.current_artcc,
                c.current_artcc_id,
                c.current_tracon,
                c.current_tracon_id,
                CAST(FLOOR(p.lat / {$gridSize}) AS SMALLINT) AS grid_lat,
                CAST(FLOOR(p.lon / {$gridSize}) AS SMALLINT) AS grid_lon
            FROM dbo.adl_flight_core c WITH (NOLOCK)
            JOIN dbo.adl_flight_position p WITH (NOLOCK) ON p.flight_uid = c.flight_uid
            WHERE c.is_active = 1
              AND p.lat IS NOT NULL
              AND p.lat BETWEEN -90 AND 90
              AND p.lon BETWEEN -180 AND 180
              AND ({$whereClause})
            ORDER BY
                CASE WHEN c.current_artcc_id IS NULL THEN 0 ELSE 1 END,
                ISNULL(c.boundary_updated_at, '1900-01-01') ASC
        ";

        $stmt = sqlsrv_query($this->connAdl, $sql);
        if ($stmt === false) {
            $this->log("ERROR: Failed to query flights - " . $this->getSqlError());
            return [];
        }

        $flights = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $flights[] = [
                'flight_uid' => (int)$row['flight_uid'],
                'lat' => (float)$row['lat'],
                'lon' => (float)$row['lon'],
                'altitude' => (int)$row['altitude_ft'],
                'current_artcc' => $row['current_artcc'],
                'current_artcc_id' => $row['current_artcc_id'],
                'current_tracon' => $row['current_tracon'],
                'current_tracon_id' => $row['current_tracon_id'],
                'grid_lat' => (int)$row['grid_lat'],
                'grid_lon' => (int)$row['grid_lon']
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $flights;
    }

    /**
     * Process flights through PostGIS
     */
    private function processViaGIS(array $flights): array
    {
        if (!$this->gis || empty($flights)) {
            return [];
        }

        $results = $this->gis->detectBoundariesBatch($flights);

        if (empty($results)) {
            $this->log("GIS returned empty results - " . ($this->gis->getLastError() ?? 'no error'));
        }

        return $results;
    }

    /**
     * Fall back to ADL stored procedure
     */
    private function processViaADL(int $maxFlights): ?array
    {
        $sql = "EXEC dbo.sp_ProcessBoundaryAndCrossings_Background @max_flights_per_run = ?, @max_crossings_per_run = 0, @debug = 0";
        $options = ['QueryTimeout' => ADL_TIMEOUT];

        $startTime = microtime(true);
        $stmt = @sqlsrv_query($this->connAdl, $sql, [$maxFlights], $options);

        if ($stmt === false) {
            $this->log("ERROR: ADL SP failed - " . $this->getSqlError());
            return null;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return [
            'boundary_flights' => $row['boundary_flights'] ?? 0,
            'boundary_transitions' => $row['boundary_transitions'] ?? 0,
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000)
        ];
    }

    /**
     * Write GIS results back to ADL
     */
    private function writeResultsToADL(array $results, array $originalFlights): array
    {
        $transitions = 0;
        $updated = 0;

        // Index original flights by flight_uid for comparison
        $flightMap = [];
        foreach ($originalFlights as $f) {
            $flightMap[$f['flight_uid']] = $f;
        }

        foreach ($results as $r) {
            $flightUid = $r['flight_uid'];
            $original = $flightMap[$flightUid] ?? null;

            if (!$original) continue;

            // Check for ARTCC transition
            $oldArtcc = $original['current_artcc'];
            $newArtcc = $r['artcc_code'];

            if ($oldArtcc !== $newArtcc && $newArtcc !== null) {
                $transitions++;
            }

            // Update ADL with new boundary info
            $updateSql = "
                UPDATE dbo.adl_flight_core
                SET current_artcc = ?,
                    current_tracon = ?,
                    last_grid_lat = ?,
                    last_grid_lon = ?,
                    boundary_updated_at = SYSUTCDATETIME()
                WHERE flight_uid = ?
            ";

            $params = [
                $r['artcc_code'],
                $r['tracon_code'],
                $original['grid_lat'],
                $original['grid_lon'],
                $flightUid
            ];

            $stmt = sqlsrv_query($this->connAdl, $updateSql, $params);
            if ($stmt === false) {
                $this->log("ERROR: Failed to update flight {$flightUid} - " . $this->getSqlError());
                continue;
            }
            sqlsrv_free_stmt($stmt);
            $updated++;
        }

        return [
            'updated' => $updated,
            'transitions' => $transitions
        ];
    }

    /**
     * Get pending flight count (for stats)
     */
    private function getPendingCount(): int
    {
        $gridSize = GRID_SIZE;
        $sql = "
            SELECT COUNT(*) AS cnt
            FROM dbo.adl_flight_core c WITH (NOLOCK)
            JOIN dbo.adl_flight_position p WITH (NOLOCK) ON p.flight_uid = c.flight_uid
            WHERE c.is_active = 1
              AND p.lat IS NOT NULL
              AND (c.current_artcc_id IS NULL
                   OR c.last_grid_lat IS NULL
                   OR c.last_grid_lat != CAST(FLOOR(p.lat / {$gridSize}) AS SMALLINT)
                   OR c.last_grid_lon != CAST(FLOOR(p.lon / {$gridSize}) AS SMALLINT))
        ";

        $stmt = sqlsrv_query($this->connAdl, $sql);
        if ($stmt === false) return 0;

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row['cnt'] ?? 0;
    }

    private function getSqlError(): string
    {
        $errors = sqlsrv_errors();
        if (empty($errors)) return 'Unknown error';
        return $errors[0]['message'] ?? json_encode($errors[0]);
    }

    /**
     * Run a single processing cycle
     */
    public function runOnce(): array
    {
        $startTime = microtime(true);

        // Check if we should use GIS or ADL-only mode
        $useGis = !$this->adlOnly && $this->gis !== null;

        if (!$useGis) {
            // Fallback to ADL stored procedure
            $this->log("Using ADL-only mode (GIS not available or disabled)");
            $result = $this->processViaADL($this->maxFlights);
            if ($result) {
                $this->totalAdlFallback++;
                return $result;
            }
            return ['error' => true, 'message' => 'ADL fallback failed'];
        }

        // Get flights needing detection
        $flights = $this->getFlightsNeedingDetection();
        if (empty($flights)) {
            return ['pending' => 0, 'processed' => 0, 'gis' => 0, 'transitions' => 0];
        }

        // Process through PostGIS
        $gisResults = $this->processViaGIS($flights);
        $gisCount = count($gisResults);

        // If GIS failed, fall back to ADL
        if ($gisCount === 0 && count($flights) > 0) {
            $this->log("GIS returned no results, falling back to ADL SP");
            $this->totalAdlFallback++;
            $result = $this->processViaADL($this->maxFlights);
            if ($result) {
                $result['method'] = 'adl_fallback';
                return $result;
            }
            return ['error' => true, 'message' => 'Both GIS and ADL failed'];
        }

        // Write GIS results back to ADL
        $writeResult = $this->writeResultsToADL($gisResults, $flights);

        $this->totalGisSuccess += $gisCount;
        $this->totalTransitions += $writeResult['transitions'];

        $elapsedMs = round((microtime(true) - $startTime) * 1000);

        return [
            'pending' => count($flights),
            'processed' => $writeResult['updated'],
            'gis' => $gisCount,
            'transitions' => $writeResult['transitions'],
            'elapsed_ms' => $elapsedMs,
            'method' => 'gis',
            'cycle' => $this->cycleCount
        ];
    }

    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $gisAvailable = $this->gis !== null;
        $mode = $this->adlOnly ? 'ADL-only' : ($gisAvailable ? 'PostGIS' : 'ADL fallback');

        $this->log("Starting boundary GIS daemon (mode: {$mode}, max_flights: {$this->maxFlights}, interval: {$this->interval}s)");

        if ($gisAvailable && !$this->adlOnly) {
            $this->log("PostGIS connection: OK");
        } else {
            $this->log("WARNING: Using ADL fallback - PostGIS not available or disabled");
        }

        while ($this->running) {
            $result = $this->runOnce();

            if (isset($result['error'])) {
                $this->log("ERROR: " . ($result['message'] ?? 'Unknown'));
                $this->totalFailed++;
            } else {
                $method = $result['method'] ?? 'unknown';
                $this->log(sprintf(
                    "Cycle %d: %d flights, %d transitions, %dms (%s)",
                    $this->cycleCount,
                    $result['processed'] ?? 0,
                    $result['transitions'] ?? 0,
                    $result['elapsed_ms'] ?? 0,
                    $method
                ));
            }

            // Log stats every 20 cycles
            if ($this->cycleCount % 20 === 0) {
                $total = $this->totalGisSuccess + $this->totalAdlFallback;
                $gisRate = $total > 0 ? round(($this->totalGisSuccess / $total) * 100) : 0;
                $pending = $this->getPendingCount();

                $this->log(sprintf(
                    "=== Stats @ cycle %d === GIS: %d, ADL: %d, failed: %d, rate: %d%%, pending: %d, transitions: %d",
                    $this->cycleCount,
                    $this->totalGisSuccess,
                    $this->totalAdlFallback,
                    $this->totalFailed,
                    $gisRate,
                    $pending,
                    $this->totalTransitions
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

$options = getopt('', ['loop', 'flights::', 'interval::', 'adl-only', 'help']);

if (isset($options['help'])) {
    echo "ADL Boundary Detection Daemon (PostGIS Version)\n";
    echo "================================================\n";
    echo "Processes ARTCC/TRACON boundary detection using PostGIS.\n\n";
    echo "Usage: php boundary_gis_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --flights=N      Max flights per cycle (default: 100)\n";
    echo "  --interval=N     Seconds between cycles (default: 15)\n";
    echo "  --adl-only       Disable GIS, use ADL stored procedure only\n";
    echo "  --help           Show this help\n";
    exit(0);
}

if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database.\n";

$maxFlights = isset($options['flights']) ? (int)$options['flights'] : DEFAULT_MAX_FLIGHTS;
$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;
$adlOnly = isset($options['adl-only']);

// Check GIS availability
$gis = GISService::getInstance();
if ($gis && !$adlOnly) {
    echo "PostGIS connection: OK\n";
} else {
    echo "PostGIS connection: " . ($adlOnly ? "DISABLED (--adl-only)" : "NOT AVAILABLE") . "\n";
}

// Acquire PID lock for loop mode
if (isset($options['loop'])) {
    if (!acquirePidLock()) {
        exit(1);
    }
    echo "PID lock acquired (PID: " . getmypid() . ")\n";
}

$daemon = new BoundaryGISDaemon($conn_adl, $maxFlights, $interval, $adlOnly);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $stats = $daemon->runOnce();
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}
