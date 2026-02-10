<?php
/**
 * ADL Route Parse Queue Daemon - PostGIS Edition
 *
 * Processes the parse queue using PostGIS for route expansion (faster, cheaper).
 * Falls back to ADL sp_ParseRoute if GIS is unavailable.
 *
 * Key differences from original parse_queue_daemon.php:
 *   - Uses PostGIS expand_route_with_artccs() for route parsing
 *   - 2-3x faster than ADL stored procedure
 *   - Offloads CPU from ADL (16 vCore Hyperscale) to GIS (2 vCore B2s)
 *   - Falls back to ADL if PostGIS fails
 *
 * Usage:
 *   php parse_queue_gis_daemon.php              # Run once
 *   php parse_queue_gis_daemon.php --loop       # Run continuously
 *   php parse_queue_gis_daemon.php --loop --batch=100  # Custom batch size
 *   php parse_queue_gis_daemon.php --adl-only   # Force ADL-only mode (fallback)
 *
 * @version 1.0.0
 * @date 2026-01-29
 */

// ============================================================================
// PID file to prevent multiple instances
// ============================================================================
define('PID_FILE', sys_get_temp_dir() . '/adl_parse_queue_gis_daemon.pid');

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

// Include connections and services
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../load/services/GISService.php';

// Configuration
define('DEFAULT_BATCH_SIZE', 50);
define('DEFAULT_INTERVAL', 10);
define('MAX_ITERATIONS', 20);
define('STAGGER_OFFSET', 0);  // No stagger needed - this daemon uses different resources

class ParseQueueGISDaemon
{
    private $conn_adl;           // Azure SQL (ADL) connection
    private ?GISService $gis;    // PostGIS service
    private int $batchSize;
    private int $interval;
    private bool $running = true;
    private bool $adlOnly = false;

    // Stats
    private int $gisSuccess = 0;
    private int $adlFallback = 0;
    private int $failures = 0;

    public function __construct($conn_adl, int $batchSize = DEFAULT_BATCH_SIZE, int $interval = DEFAULT_INTERVAL, bool $adlOnly = false)
    {
        $this->conn_adl = $conn_adl;
        $this->batchSize = $batchSize;
        $this->interval = $interval;
        $this->adlOnly = $adlOnly;

        // Initialize GIS service
        $this->gis = $adlOnly ? null : GISService::getInstance();

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
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }

    /**
     * Get queue statistics
     */
    private function getQueueStats(): array
    {
        $sql = "
            SELECT
                COUNT(CASE WHEN status = 'PENDING' AND next_eligible_utc <= SYSUTCDATETIME() THEN 1 END) AS pending,
                COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
                COUNT(CASE WHEN status = 'COMPLETE' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS complete,
                COUNT(CASE WHEN status = 'FAILED' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS failed
            FROM dbo.adl_parse_queue WITH (NOLOCK)
        ";

        $stmt = sqlsrv_query($this->conn_adl, $sql);
        if ($stmt === false) return [];

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ?: [];
    }

    /**
     * Reset items stuck in PROCESSING for more than 5 minutes
     */
    private function resetStuckItems(): int
    {
        $sql = "
            UPDATE dbo.adl_parse_queue
            SET status = 'PENDING',
                next_eligible_utc = SYSUTCDATETIME(),
                started_utc = NULL
            WHERE status = 'PROCESSING'
              AND (started_utc < DATEADD(MINUTE, -5, SYSUTCDATETIME())
                   OR started_utc IS NULL)
        ";

        $stmt = sqlsrv_query($this->conn_adl, $sql);
        if ($stmt === false) return 0;

        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($rows > 0) {
            $this->log("Reset {$rows} stuck PROCESSING items");
        }

        return $rows;
    }

    /**
     * Backfill orphaned flights that have parse_status=PENDING in adl_flight_plan
     * but no corresponding row in adl_parse_queue. This can happen if the ingest
     * procedure's MERGE into the queue fails silently (e.g., NULL parse_tier).
     */
    private function backfillOrphanedFlights(): int
    {
        $sql = "
            INSERT INTO dbo.adl_parse_queue (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
            SELECT fp.flight_uid, COALESCE(fp.parse_tier, 2), 'PENDING', SYSUTCDATETIME(), SYSUTCDATETIME()
            FROM dbo.adl_flight_plan fp
            JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
            LEFT JOIN dbo.adl_parse_queue q ON q.flight_uid = fp.flight_uid
            WHERE c.is_active = 1
              AND fp.parse_status = 'PENDING'
              AND q.flight_uid IS NULL
              AND fp.fp_route IS NOT NULL
              AND fp.fp_route != ''
        ";

        $stmt = sqlsrv_query($this->conn_adl, $sql);
        if ($stmt === false) return 0;

        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($rows > 0) {
            $this->log("Backfilled {$rows} orphaned PENDING flights into parse queue");
        }

        return $rows;
    }

    /**
     * Claim a batch of routes for processing
     */
    private function claimBatch(int $size): array
    {
        // Select pending items
        $selectSql = "
            SELECT TOP ({$size}) pq.flight_uid, fp.fp_route, fp.fp_dept_icao, fp.fp_dest_icao
            FROM dbo.adl_parse_queue pq WITH (UPDLOCK, READPAST)
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = pq.flight_uid
            WHERE pq.status = 'PENDING' AND pq.next_eligible_utc <= SYSUTCDATETIME()
            ORDER BY pq.parse_tier, pq.queued_utc
        ";

        $stmt = sqlsrv_query($this->conn_adl, $selectSql);
        if ($stmt === false) {
            $this->log("ERROR claiming batch: " . print_r(sqlsrv_errors(), true));
            return [];
        }

        $batch = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $batch[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        if (empty($batch)) return [];

        // Mark as processing
        $flightUids = array_column($batch, 'flight_uid');
        $uidList = implode(',', $flightUids);

        $updateSql = "
            UPDATE dbo.adl_parse_queue
            SET status = 'PROCESSING', started_utc = SYSUTCDATETIME(), attempts = attempts + 1
            WHERE flight_uid IN ({$uidList})
        ";

        $stmt = sqlsrv_query($this->conn_adl, $updateSql);
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
        }

        return $batch;
    }

    /**
     * Parse a route using PostGIS
     * Returns waypoints array or null on failure
     */
    private function parseRouteGIS(string $routeString): ?array
    {
        if (!$this->gis) return null;

        $result = $this->gis->expandRoute($routeString);

        if (!$result || empty($result['waypoints'])) {
            return null;
        }

        return $result;
    }

    /**
     * Parse a route using ADL stored procedure (fallback)
     */
    private function parseRouteADL(int $flightUid): bool
    {
        $sql = "EXEC dbo.sp_ParseRoute @flight_uid = ?, @debug = 0";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$flightUid]);

        if ($stmt === false) {
            return false;
        }

        sqlsrv_free_stmt($stmt);
        return true;
    }

    /**
     * Write GIS results to ADL tables
     */
    private function writeResultsToADL(int $flightUid, array $gisResult, string $routeString): bool
    {
        $waypoints = $gisResult['waypoints'];
        $artccs = $gisResult['artccs'] ?? [];
        $distanceNm = $gisResult['distance_nm'] ?? 0;
        $geojson = $gisResult['geojson'] ?? null;

        // Normalize waypoint field names (PostGIS uses 'id'/'type', ADL uses 'fix_id'/'fix_type')
        $waypoints = array_map(function($wp) {
            return [
                'fix_id' => $wp['id'] ?? $wp['fix_id'] ?? null,
                'lat' => $wp['lat'] ?? null,
                'lon' => $wp['lon'] ?? null,
                'fix_type' => $wp['type'] ?? $wp['fix_type'] ?? 'FIX',
                'source' => $wp['source'] ?? 'GIS',
                'seq' => $wp['seq'] ?? null
            ];
        }, $waypoints);

        // Build expanded route string from waypoints
        $expandedRoute = implode(' ', array_column($waypoints, 'fix_id'));
        $waypointCount = count($waypoints);

        // Build WKT LineString for geometry
        $lineStringWkt = null;
        if ($waypointCount >= 2) {
            $points = [];
            foreach ($waypoints as $wp) {
                if (isset($wp['lon']) && isset($wp['lat'])) {
                    $points[] = round($wp['lon'], 6) . ' ' . round($wp['lat'], 6);
                }
            }
            if (count($points) >= 2) {
                $lineStringWkt = 'LINESTRING(' . implode(', ', $points) . ')';
            }
        }

        // Build waypoints JSON for filtering
        $waypointsJson = json_encode($waypoints);

        // Extract SID/STAR names and departure/arrival fixes from route
        $routeInfo = $this->extractRouteInfo($routeString, $waypoints);
        $dpName = $routeInfo['dp_name'];
        $starName = $routeInfo['star_name'];
        $dfix = $routeInfo['dfix'];
        $afix = $routeInfo['afix'];

        // Update adl_flight_plan
        $updatePlanSql = "
            UPDATE dbo.adl_flight_plan
            SET fp_route_expanded = ?,
                parse_status = 'COMPLETE',
                parse_utc = SYSUTCDATETIME(),
                waypoint_count = ?,
                route_total_nm = ?,
                waypoints_json = ?,
                route_geometry = CASE WHEN ? IS NOT NULL THEN geography::STGeomFromText(?, 4326) ELSE route_geometry END,
                dp_name = COALESCE(?, dp_name),
                star_name = COALESCE(?, star_name),
                dfix = COALESCE(?, dfix),
                afix = COALESCE(?, afix)
            WHERE flight_uid = ?
        ";

        $stmt = sqlsrv_query($this->conn_adl, $updatePlanSql, [
            $expandedRoute,
            $waypointCount,
            $distanceNm,
            $waypointsJson,
            $lineStringWkt,
            $lineStringWkt,
            $dpName,
            $starName,
            $dfix,
            $afix,
            $flightUid
        ]);

        if ($stmt === false) {
            $this->log("ERROR updating flight_plan: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        sqlsrv_free_stmt($stmt);

        // Delete existing waypoints
        $deleteSql = "DELETE FROM dbo.adl_flight_waypoints WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $deleteSql, [$flightUid]);
        if ($stmt !== false) sqlsrv_free_stmt($stmt);

        // Insert new waypoints
        $insertSql = "
            INSERT INTO dbo.adl_flight_waypoints (
                flight_uid, sequence_num, fix_name, lat, lon, position_geo,
                fix_type, source, segment_dist_nm, cum_dist_nm
            ) VALUES (?, ?, ?, ?, ?, geography::Point(?, ?, 4326), ?, ?, ?, ?)
        ";

        $prevLat = null;
        $prevLon = null;
        $cumDist = 0;

        foreach ($waypoints as $seq => $wp) {
            $lat = $wp['lat'] ?? null;
            $lon = $wp['lon'] ?? null;

            if ($lat === null || $lon === null) continue;

            // Calculate segment distance
            $segmentDist = 0;
            if ($prevLat !== null && $prevLon !== null) {
                $segmentDist = $this->haversineDistance($prevLat, $prevLon, $lat, $lon);
            }
            $cumDist += $segmentDist;

            $stmt = sqlsrv_query($this->conn_adl, $insertSql, [
                $flightUid,
                $seq + 1,                          // sequence_num (1-based)
                $wp['fix_id'] ?? null,
                $lat,
                $lon,
                $lat,                              // for geography::Point
                $lon,
                $wp['fix_type'] ?? 'FIX',
                $wp['source'] ?? 'GIS',
                round($segmentDist, 2),
                round($cumDist, 2)
            ]);

            if ($stmt !== false) sqlsrv_free_stmt($stmt);

            $prevLat = $lat;
            $prevLon = $lon;
        }

        return true;
    }

    /**
     * Calculate haversine distance in nautical miles
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 3440.065; // Earth radius in nautical miles

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $R * $c;
    }

    /**
     * Extract SID/STAR names and departure/arrival fixes from route string and waypoints.
     *
     * Logic:
     * - dp_name (SID): First token in route matching procedure pattern (ends with digit+letter)
     * - star_name (STAR): Last token in route matching procedure pattern
     * - dfix: First fix in waypoints after departure (2nd waypoint typically)
     * - afix: Last fix before arrival (2nd-to-last waypoint typically)
     *
     * @param string $routeString Original filed route
     * @param array $waypoints Expanded waypoints array
     * @return array ['dp_name' => string|null, 'star_name' => string|null, 'dfix' => string|null, 'afix' => string|null]
     */
    private function extractRouteInfo(string $routeString, array $waypoints): array
    {
        $dpName = null;
        $starName = null;
        $dfix = null;
        $afix = null;

        // Parse route string tokens
        $tokens = preg_split('/\s+/', strtoupper(trim($routeString)));
        $tokens = array_values(array_filter($tokens, fn($t) => !empty($t) && $t !== 'DCT'));

        // Procedure pattern: 3-6 letters followed by digit + optional letter (e.g., KRSTA4, KKILR3, ULKIG4S, WYNDE3)
        // More permissive pattern to catch various naming conventions
        $procedurePattern = '/^[A-Z]{2,7}\d[A-Z0-9]?$/';

        // Check first few route tokens for SID (departure procedure)
        for ($i = 0; $i < min(3, count($tokens)); $i++) {
            $token = preg_replace('/\/.*$/', '', $tokens[$i]); // Remove /altitude suffixes
            if (preg_match($procedurePattern, $token)) {
                $dpName = $token;
                break;
            }
        }

        // Check last few route tokens for STAR (arrival procedure)
        for ($i = count($tokens) - 1; $i >= max(0, count($tokens) - 3); $i--) {
            $token = preg_replace('/\/.*$/', '', $tokens[$i]); // Remove /altitude suffixes
            if (preg_match($procedurePattern, $token)) {
                // Make sure it's not the same as SID (for short routes)
                if ($token !== $dpName) {
                    $starName = $token;
                }
                break;
            }
        }

        // Extract fixes from waypoints if available
        if (!empty($waypoints) && count($waypoints) >= 2) {
            // Extract dfix - first non-airway fix after departure
            for ($i = 1; $i < min(5, count($waypoints)); $i++) {
                $wp = $waypoints[$i];
                $fixId = $wp['fix_id'] ?? $wp['id'] ?? null;
                $fixType = $wp['fix_type'] ?? $wp['type'] ?? '';

                // Skip airway waypoints (type contains 'airway_')
                if ($fixId && strpos($fixType, 'airway_') === false) {
                    $dfix = strtoupper($fixId);
                    break;
                }
            }

            // Extract afix - last non-airway fix before arrival
            for ($i = count($waypoints) - 2; $i >= max(0, count($waypoints) - 5); $i--) {
                $wp = $waypoints[$i];
                $fixId = $wp['fix_id'] ?? $wp['id'] ?? null;
                $fixType = $wp['fix_type'] ?? $wp['type'] ?? '';

                if ($fixId && strpos($fixType, 'airway_') === false) {
                    $afix = strtoupper($fixId);
                    break;
                }
            }
        }

        return [
            'dp_name' => $dpName,
            'star_name' => $starName,
            'dfix' => $dfix,
            'afix' => $afix
        ];
    }

    /**
     * Mark a route as complete in the queue
     */
    private function markComplete(int $flightUid): void
    {
        $sql = "UPDATE dbo.adl_parse_queue SET status = 'COMPLETE', completed_utc = SYSUTCDATETIME() WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$flightUid]);
        if ($stmt !== false) sqlsrv_free_stmt($stmt);
    }

    /**
     * Mark a route as failed in the queue
     */
    private function markFailed(int $flightUid, string $errorMsg): void
    {
        $sql = "UPDATE dbo.adl_parse_queue SET status = 'FAILED', error_message = ?, next_eligible_utc = DATEADD(MINUTE, 5, SYSUTCDATETIME()) WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [substr($errorMsg, 0, 500), $flightUid]);
        if ($stmt !== false) sqlsrv_free_stmt($stmt);
    }

    /**
     * Process a single route
     */
    private function processRoute(array $item): string
    {
        $flightUid = $item['flight_uid'];
        $routeString = $item['fp_route'];

        if (empty($routeString)) {
            $this->markFailed($flightUid, 'Empty route string');
            return 'skipped';
        }

        // Try GIS first (unless ADL-only mode)
        if (!$this->adlOnly && $this->gis) {
            $gisResult = $this->parseRouteGIS($routeString);

            if ($gisResult && !empty($gisResult['waypoints'])) {
                // Write GIS results to ADL
                if ($this->writeResultsToADL($flightUid, $gisResult, $routeString)) {
                    $this->markComplete($flightUid);
                    $this->gisSuccess++;
                    return 'gis';
                }
            }
        }

        // Fallback to ADL stored procedure
        if ($this->parseRouteADL($flightUid)) {
            $this->markComplete($flightUid);
            $this->adlFallback++;
            return 'adl';
        }

        // Both failed
        $this->markFailed($flightUid, 'GIS and ADL parsing both failed');
        $this->failures++;
        return 'failed';
    }

    /**
     * Process a batch of routes
     */
    private function processBatch(int $batchSize = null): int
    {
        $size = $batchSize ?? $this->batchSize;
        $batch = $this->claimBatch($size);

        if (empty($batch)) return 0;

        $processed = 0;
        foreach ($batch as $item) {
            $result = $this->processRoute($item);
            if ($result !== 'failed') {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Run a single processing cycle
     */
    public function runOnce(): array
    {
        $startTime = microtime(true);
        $this->gisSuccess = 0;
        $this->adlFallback = 0;
        $this->failures = 0;

        $this->resetStuckItems();
        $this->backfillOrphanedFlights();

        $queueStats = $this->getQueueStats();
        $pending = $queueStats['pending'] ?? 0;

        if ($pending == 0) {
            $this->log("Queue empty, nothing to process");
            return ['processed' => 0, 'queue_stats' => $queueStats];
        }

        $isBacklogged = $pending > 100;
        $catchupBatchSize = $isBacklogged ? 200 : $this->batchSize;

        if ($isBacklogged) {
            $this->log("BACKLOG detected ({$pending} pending) - using batch size {$catchupBatchSize}");
        } else {
            $this->log("Processing queue ({$pending} pending)...");
        }

        $iterations = 0;
        $totalProcessed = 0;

        while ($iterations < MAX_ITERATIONS && $this->running) {
            $processed = $this->processBatch($catchupBatchSize);
            $totalProcessed += $processed;
            $iterations++;

            if ($processed < $catchupBatchSize) {
                break;
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000);

        $this->log(sprintf(
            "Processed %d routes in %dms (GIS: %d, ADL fallback: %d, failed: %d)",
            $totalProcessed, $durationMs, $this->gisSuccess, $this->adlFallback, $this->failures
        ));

        return [
            'processed' => $totalProcessed,
            'gis_success' => $this->gisSuccess,
            'adl_fallback' => $this->adlFallback,
            'failures' => $this->failures,
            'duration_ms' => $durationMs,
            'queue_stats' => $this->getQueueStats()
        ];
    }

    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $gisStatus = $this->gis ? 'connected' : 'unavailable (ADL-only mode)';
        $this->log("Starting GIS parse queue daemon (batch: {$this->batchSize}, interval: {$this->interval}s)");
        $this->log("PostGIS: {$gisStatus}");
        $this->log("ADL Fallback: enabled");

        if (STAGGER_OFFSET > 0) {
            sleep(STAGGER_OFFSET);
        }

        while ($this->running) {
            $stats = $this->runOnce();

            $qs = $stats['queue_stats'];
            $this->log(sprintf(
                "Queue: %d pending | GIS rate: %.0f%% | %d complete | %d failed",
                $qs['pending'] ?? 0,
                $stats['processed'] > 0 ? ($stats['gis_success'] / $stats['processed'] * 100) : 0,
                $qs['complete'] ?? 0,
                $qs['failed'] ?? 0
            ));

            if ($this->running) {
                $sleepTime = ($stats['processed'] == 0) ? $this->interval * 2 : $this->interval;
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

$options = getopt('', ['loop', 'batch::', 'interval::', 'adl-only', 'help']);

if (isset($options['help'])) {
    echo "ADL Route Parse Queue Daemon - PostGIS Edition\n";
    echo "==============================================\n";
    echo "Processes routes using PostGIS for faster, cheaper parsing.\n";
    echo "Falls back to ADL stored procedure if GIS fails.\n\n";
    echo "Usage: php parse_queue_gis_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --batch=N        Routes per batch (default: 50)\n";
    echo "  --interval=N     Seconds between cycles (default: 10)\n";
    echo "  --adl-only       Force ADL-only mode (disable GIS)\n";
    echo "  --help           Show this help\n\n";
    echo "Cost Savings:\n";
    echo "  - Offloads ~40% of ADL spatial workload to PostGIS\n";
    echo "  - PostGIS parsing is 2-3x faster than ADL\n";
    echo "  - Estimated savings: \$150-200/month\n";
    exit(0);
}

// Check ADL connection
if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    echo "Check that ADL_SQL_* constants are defined in load/config.php\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database.\n";

// Check GIS connection
$gis = GISService::getInstance();
if ($gis) {
    echo "Connected to PostGIS (VATSIM_GIS) database.\n";
} else {
    echo "WARNING: PostGIS unavailable - running in ADL-only mode.\n";
}

$batchSize = isset($options['batch']) ? (int)$options['batch'] : DEFAULT_BATCH_SIZE;
$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;
$adlOnly = isset($options['adl-only']);

if (isset($options['loop'])) {
    if (!acquirePidLock()) {
        exit(1);
    }
    echo "PID lock acquired (PID: " . getmypid() . ")\n";
}

$daemon = new ParseQueueGISDaemon($conn_adl, $batchSize, $interval, $adlOnly);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $stats = $daemon->runOnce();
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}
