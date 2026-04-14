<?php
/**
 * vNAS Controller Feed Polling Daemon
 *
 * Polls the vNAS controller data feed and upserts controller data into
 * SWIM_API's swim_controllers table, then enriches with vNAS-specific
 * data (ERAM/STARS sector assignments, positions, roles).
 *
 * vNAS Controller Feed: https://live.env.vnas.vatsim.net/data-feed/controllers.json
 *
 * Features:
 *   - Polls vNAS controller feed every 60s (configurable)
 *   - Calls sp_Swim_UpsertControllers for base controller data
 *   - Calls sp_Swim_EnrichControllersVnas for ERAM/STARS enrichment
 *   - Publishes WebSocket events on connect/disconnect
 *   - Circuit breaker pattern (6 errors/60s -> 3-min cooldown)
 *   - PID file singleton, heartbeat file
 *
 * Usage:
 *   php vnas_controller_poll.php [--loop] [--interval=60] [--debug]
 *
 * @package PERTI
 * @subpackage SWIM\Controllers
 * @version 1.0.0
 */

// Parse CLI arguments
$options = getopt('', ['loop', 'interval:', 'debug']);
$runLoop = isset($options['loop']);
$pollInterval = isset($options['interval']) ? (int)$options['interval'] : 60;
$debug = isset($options['debug']);

// Enforce minimum
$pollInterval = max(30, $pollInterval);

// Load dependencies
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// WebSocket event publishing
require_once __DIR__ . '/swim_ws_events.php';

// ============================================================================
// Constants
// ============================================================================

define('VNAS_CTRL_API_URL', 'https://live.env.vnas.vatsim.net/data-feed/controllers.json');
define('VNAS_CTRL_STATE_FILE', sys_get_temp_dir() . '/perti_vnas_ctrl_state.json');
define('VNAS_CTRL_CACHE_FILE', sys_get_temp_dir() . '/perti_vnas_ctrl_cache.json');
define('VNAS_CTRL_CIRCUIT_WINDOW', 60);
define('VNAS_CTRL_CIRCUIT_MAX_ERRORS', 6);
define('VNAS_CTRL_CIRCUIT_COOLDOWN', 180);

// ============================================================================
// Logging
// ============================================================================

function vnas_ctrl_log(string $message, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

// ============================================================================
// Circuit Breaker (shared class)
// ============================================================================

require_once __DIR__ . '/../lib/connectors/CircuitBreaker.php';

$vnas_ctrl_circuit_breaker = new \PERTI\Lib\Connectors\CircuitBreaker(
    VNAS_CTRL_STATE_FILE,
    VNAS_CTRL_CIRCUIT_WINDOW,
    VNAS_CTRL_CIRCUIT_MAX_ERRORS,
    VNAS_CTRL_CIRCUIT_COOLDOWN
);

function vnas_ctrl_is_circuit_open(): bool {
    global $vnas_ctrl_circuit_breaker;
    return $vnas_ctrl_circuit_breaker->isOpen();
}

function vnas_ctrl_record_error(): void {
    global $vnas_ctrl_circuit_breaker;
    if ($vnas_ctrl_circuit_breaker->recordError()) {
        vnas_ctrl_log("Circuit breaker tripped -- cooldown " . VNAS_CTRL_CIRCUIT_COOLDOWN . "s", 'WARN');
    }
}

function vnas_ctrl_reset_circuit(): void {
    global $vnas_ctrl_circuit_breaker;
    $vnas_ctrl_circuit_breaker->reset();
}

// ============================================================================
// HTTP Helper
// ============================================================================

function vnas_ctrl_fetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: PERTI-vNAS-Controller-Daemon/1.0'
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        vnas_ctrl_log("HTTP $httpCode from $url" . ($error ? ": $error" : ''), 'ERROR');
        vnas_ctrl_record_error();
        return null;
    }

    $data = json_decode($response, true);
    if ($data === null) {
        vnas_ctrl_log("Invalid JSON from $url", 'ERROR');
        vnas_ctrl_record_error();
        return null;
    }

    return $data;
}

// ============================================================================
// Data Transformation
// ============================================================================

/**
 * Transform vNAS controller feed into base upsert batch.
 * Extracts CID, callsign, frequency, rating, lat/lon from vatsimData block.
 *
 * @param array $controllers Raw controllers array from vNAS feed
 * @return array [upsertBatch, enrichBatch]
 */
function vnas_ctrl_transform(array $controllers): array {
    $upsertBatch = [];
    $enrichBatch = [];

    foreach ($controllers as $ctrl) {
        $vatsimData = $ctrl['vatsimData'] ?? null;
        if (!$vatsimData || empty($vatsimData['cid'])) {
            continue;
        }

        $cid = (int)$vatsimData['cid'];
        $callsign = $vatsimData['callsign'] ?? '';
        if (!$callsign) continue;

        // Frequency: vNAS reports Hz, convert to MHz decimal
        $freqHz = $vatsimData['primaryFrequency'] ?? 0;
        $freqMhz = $freqHz > 0 ? round($freqHz / 1000000, 3) : null;

        // Rating mapping (vNAS userRating string -> numeric)
        $ratingMap = [
            'OBS' => 1, 'S1' => 2, 'S2' => 3, 'S3' => 4,
            'C1' => 5, 'C2' => 6, 'C3' => 7,
            'I1' => 8, 'I2' => 9, 'I3' => 10,
            'SUP' => 11, 'ADM' => 12
        ];
        $ratingStr = $vatsimData['userRating'] ?? '';
        $ratingNum = $ratingMap[$ratingStr] ?? null;

        // Is observer (from vNAS top-level)
        $isObserver = !empty($ctrl['isObserver']) ? 1 : 0;

        // Base upsert data (matches sp_Swim_UpsertControllers JSON schema)
        $upsertBatch[] = [
            'cid'          => $cid,
            'callsign'     => $callsign,
            'frequency'    => $freqMhz,
            'visual_range' => null,
            'rating'       => $ratingNum,
            'logon_utc'    => $ctrl['loginTime'] ?? null,
            'lat'          => null,  // vNAS doesn't provide lat/lon
            'lon'          => null,
            'text_atis'    => $vatsimData['controllerInfo'] ?? null,
            'is_observer'  => $isObserver,
        ];

        // vNAS enrichment data
        $primaryPosition = null;
        $secondaryPositions = [];

        foreach (($ctrl['positions'] ?? []) as $pos) {
            if (!empty($pos['isPrimary'])) {
                $primaryPosition = $pos;
            } else {
                $secondaryPositions[] = [
                    'facilityId'   => $pos['facilityId'] ?? null,
                    'facilityName' => $pos['facilityName'] ?? null,
                    'positionId'   => $pos['positionId'] ?? null,
                    'positionName' => $pos['positionName'] ?? null,
                    'positionType' => $pos['positionType'] ?? null,
                    'radioName'    => $pos['radioName'] ?? null,
                ];
            }
        }

        // Extract ERAM/STARS data from primary position
        $eramSectorId = null;
        $starsSectorId = null;
        $starsAreaId = null;

        if ($primaryPosition) {
            $eramData = $primaryPosition['eramData'] ?? null;
            if ($eramData) {
                $eramSectorId = $eramData['sectorId'] ?? null;
            }
            $starsData = $primaryPosition['starsData'] ?? null;
            if ($starsData) {
                $starsSectorId = $starsData['sectorId'] ?? null;
                $starsAreaId = $starsData['areaId'] ?? null;
            }
        }

        $enrichBatch[] = [
            'cid'             => $cid,
            'artcc_id'        => $ctrl['artccId'] ?? null,
            'facility_id'     => $ctrl['primaryFacilityId'] ?? null,
            'position_id'     => $ctrl['primaryPositionId'] ?? null,
            'position_name'   => $primaryPosition['positionName'] ?? null,
            'position_type'   => $primaryPosition['positionType'] ?? null,
            'radio_name'      => $primaryPosition['radioName'] ?? null,
            'role'            => $ctrl['role'] ?? null,
            'eram_sector_id'  => $eramSectorId,
            'stars_sector_id' => $starsSectorId,
            'stars_area_id'   => $starsAreaId,
            'secondary_json'  => !empty($secondaryPositions) ? json_encode($secondaryPositions) : null,
            'is_observer'     => $isObserver,
        ];
    }

    return [$upsertBatch, $enrichBatch];
}

// ============================================================================
// Main Poll Function
// ============================================================================

function vnas_ctrl_poll(bool $debug = false): array {
    $conn_swim = get_conn_swim();
    $stats = [
        'fetched'      => 0,
        'inserted'     => 0,
        'updated'      => 0,
        'disconnected' => 0,
        'enriched'     => 0,
        'ws_events'    => 0,
        'errors'       => 0,
    ];

    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM database connection unavailable', 'stats' => $stats];
    }

    // Check circuit breaker
    if (vnas_ctrl_is_circuit_open()) {
        return ['success' => true, 'message' => 'Circuit breaker open -- in cooldown', 'stats' => $stats];
    }

    // Fetch vNAS controller feed
    $data = vnas_ctrl_fetch(VNAS_CTRL_API_URL);
    if ($data === null) {
        return ['success' => false, 'message' => 'Failed to fetch vNAS controller feed', 'stats' => $stats];
    }

    $controllers = $data['controllers'] ?? [];
    $stats['fetched'] = count($controllers);

    if ($debug) {
        vnas_ctrl_log("  Fetched " . count($controllers) . " controllers from vNAS");
    }

    if (empty($controllers)) {
        vnas_ctrl_reset_circuit();
        return ['success' => true, 'message' => '0 controllers in feed', 'stats' => $stats];
    }

    // Transform to upsert + enrich batches
    [$upsertBatch, $enrichBatch] = vnas_ctrl_transform($controllers);

    if ($debug) {
        vnas_ctrl_log("  Transformed: " . count($upsertBatch) . " upsert, " . count($enrichBatch) . " enrich");
    }

    // Step 1: Base upsert via sp_Swim_UpsertControllers
    if (!empty($upsertBatch)) {
        $json = json_encode($upsertBatch);
        $sql = "DECLARE @ins INT, @upd INT, @disc INT;
                EXEC dbo.sp_Swim_UpsertControllers @Json = ?, @Source = 'vnas',
                    @Inserted = @ins OUTPUT, @Updated = @upd OUTPUT, @Disconnected = @disc OUTPUT;
                SELECT @ins AS inserted, @upd AS updated, @disc AS disconnected;";

        $stmt = @sqlsrv_query($conn_swim, $sql, [$json]);
        if ($stmt !== false) {
            // Advance to the SELECT result set
            if (sqlsrv_next_result($stmt)) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                if ($row) {
                    $stats['inserted'] = (int)($row['inserted'] ?? 0);
                    $stats['updated'] = (int)($row['updated'] ?? 0);
                    $stats['disconnected'] = (int)($row['disconnected'] ?? 0);
                }
            }
            sqlsrv_free_stmt($stmt);

            if ($debug) {
                vnas_ctrl_log("  Upsert: +{$stats['inserted']} new, ~{$stats['updated']} updated, -{$stats['disconnected']} disconnected");
            }
        } else {
            $stats['errors']++;
            $errors = sqlsrv_errors();
            vnas_ctrl_log("sp_Swim_UpsertControllers failed: " . json_encode($errors), 'ERROR');
        }
    }

    // Step 2: vNAS enrichment via sp_Swim_EnrichControllersVnas
    if (!empty($enrichBatch)) {
        $json = json_encode($enrichBatch);
        $sql = "DECLARE @enr INT;
                EXEC dbo.sp_Swim_EnrichControllersVnas @Json = ?, @Enriched = @enr OUTPUT;
                SELECT @enr AS enriched;";

        $stmt = @sqlsrv_query($conn_swim, $sql, [$json]);
        if ($stmt !== false) {
            if (sqlsrv_next_result($stmt)) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                if ($row) {
                    $stats['enriched'] = (int)($row['enriched'] ?? 0);
                }
            }
            sqlsrv_free_stmt($stmt);

            if ($debug) {
                vnas_ctrl_log("  Enriched: {$stats['enriched']} controllers with vNAS data");
            }
        } else {
            $stats['errors']++;
            $errors = sqlsrv_errors();
            vnas_ctrl_log("sp_Swim_EnrichControllersVnas failed: " . json_encode($errors), 'ERROR');
        }
    }

    // Step 3: Staffing, consolidation, and top-down detection (2B)
    $conn_adl = get_conn_adl();
    $staffingEvents = [];

    if ($conn_adl && !empty($enrichBatch)) {
        $staffedCount = 0;
        $consolidations = [];
        $topdowns = [];

        foreach ($enrichBatch as $ctrl) {
            if ($ctrl['is_observer']) continue;
            $cid = $ctrl['cid'];
            $artcc = $ctrl['artcc_id'];

            // 3a. ERAM position → boundary staffing
            if (!empty($ctrl['eram_sector_id']) && !empty($artcc)) {
                $sql = "UPDATE b SET
                            b.is_staffed = 1,
                            b.staffed_by_cid = ?,
                            b.staffed_updated_utc = SYSUTCDATETIME()
                        FROM dbo.adl_boundary b
                        INNER JOIN dbo.vnas_position_sector_map psm
                            ON psm.boundary_id = b.boundary_id
                        INNER JOIN dbo.vnas_positions p
                            ON p.position_ulid = psm.position_ulid
                        WHERE p.parent_artcc = ?
                          AND p.eram_sector_id = ?";
                $stmt = @sqlsrv_query($conn_adl, $sql, [$cid, $artcc, $ctrl['eram_sector_id']]);
                if ($stmt !== false) {
                    $staffedCount += max(0, sqlsrv_rows_affected($stmt));
                    sqlsrv_free_stmt($stmt);
                }
            }

            // 3b. STARS TCP → boundary staffing via tcp_sector_map
            if (!empty($ctrl['stars_sector_id']) && !empty($artcc)) {
                $sql = "UPDATE b SET
                            b.is_staffed = 1,
                            b.staffed_by_cid = ?,
                            b.staffed_updated_utc = SYSUTCDATETIME()
                        FROM dbo.adl_boundary b
                        INNER JOIN dbo.vnas_tcp_sector_map tsm
                            ON tsm.boundary_id = b.boundary_id
                        WHERE tsm.parent_artcc = ?
                          AND tsm.sector_id = ?
                          AND tsm.boundary_id IS NOT NULL";
                $stmt = @sqlsrv_query($conn_adl, $sql, [$cid, $artcc, $ctrl['stars_sector_id']]);
                if ($stmt !== false) {
                    $staffedCount += max(0, sqlsrv_rows_affected($stmt));
                    sqlsrv_free_stmt($stmt);
                }
            }

            // 3c. Consolidation: multiple secondary positions in same ARTCC
            $secondaryPositions = !empty($ctrl['secondary_json']) ? json_decode($ctrl['secondary_json'], true) : [];
            if (is_array($secondaryPositions) && count($secondaryPositions) > 0) {
                $consolidations[] = [
                    'cid'         => $cid,
                    'artcc'       => $artcc,
                    'primary'     => $ctrl['position_name'] ?? $ctrl['facility_id'],
                    'secondaries' => count($secondaryPositions),
                ];
            }

            // 3d. Top-down: positions spanning multiple facilities
            if (is_array($secondaryPositions) && count($secondaryPositions) > 0) {
                $primaryFacility = $ctrl['facility_id'];
                $coveredFacilities = [];
                foreach ($secondaryPositions as $sec) {
                    $secFac = $sec['facilityId'] ?? null;
                    if ($secFac && $secFac !== $primaryFacility) {
                        $coveredFacilities[] = $secFac;
                    }
                }
                if (!empty($coveredFacilities)) {
                    $topdowns[] = [
                        'cid'       => $cid,
                        'artcc'     => $artcc,
                        'primary'   => $primaryFacility,
                        'covered'   => array_unique($coveredFacilities),
                    ];
                }
            }
        }

        // 3e. Unstaffing sweep: clear boundaries not updated in last 90s
        $sweepSql = "UPDATE dbo.adl_boundary SET
                         is_staffed = 0,
                         staffed_by_cid = NULL,
                         staffed_updated_utc = NULL
                     WHERE is_staffed = 1
                       AND staffed_updated_utc < DATEADD(SECOND, -90, SYSUTCDATETIME())";
        $sweepStmt = @sqlsrv_query($conn_adl, $sweepSql);
        $unstaffedCount = 0;
        if ($sweepStmt !== false) {
            $unstaffedCount = max(0, sqlsrv_rows_affected($sweepStmt));
            sqlsrv_free_stmt($sweepStmt);
        }

        $stats['staffed_boundaries'] = $staffedCount;
        $stats['unstaffed_sweep'] = $unstaffedCount;
        $stats['consolidations'] = count($consolidations);
        $stats['topdowns'] = count($topdowns);

        if ($debug) {
            vnas_ctrl_log("  Staffing: {$staffedCount} boundaries staffed, {$unstaffedCount} swept");
            vnas_ctrl_log("  Consolidation: " . count($consolidations) . " controllers with secondary positions");
            vnas_ctrl_log("  Top-down: " . count($topdowns) . " controllers covering multiple facilities");
        }

        // Build staffing WebSocket events
        foreach ($consolidations as $c) {
            $staffingEvents[] = [
                'type' => 'controller.consolidation',
                'data' => $c,
            ];
        }
        foreach ($topdowns as $t) {
            $staffingEvents[] = [
                'type' => 'controller.topdown',
                'data' => $t,
            ];
        }

        // 3f. Facility staffing metrics
        $metricSql = "SELECT
                          c.vnas_facility_id,
                          c.vnas_artcc_id,
                          COUNT(*) AS staffed
                      FROM dbo.swim_controllers c
                      WHERE c.is_active = 1
                        AND c.vnas_facility_id IS NOT NULL
                        AND (c.is_observer = 0 OR c.is_observer IS NULL)
                      GROUP BY c.vnas_facility_id, c.vnas_artcc_id";
        $metricStmt = @sqlsrv_query($conn_swim, $metricSql);
        if ($metricStmt) {
            while ($row = sqlsrv_fetch_array($metricStmt, SQLSRV_FETCH_ASSOC)) {
                $staffingEvents[] = [
                    'type' => 'facility.staffing',
                    'data' => [
                        'facility_id' => $row['vnas_facility_id'],
                        'artcc_id'    => $row['vnas_artcc_id'],
                        'staffed'     => (int)$row['staffed'],
                    ],
                ];
            }
            sqlsrv_free_stmt($metricStmt);
        }
    }

    // Step 4: Publish WebSocket events
    $wsEvents = [];
    // Include staffing events
    foreach ($staffingEvents as $se) {
        $wsEvents[] = $se;
    }

    if ($stats['inserted'] > 0) {
        // Query newly connected controllers for event payload
        $newSql = "SELECT TOP 50 cid, callsign, frequency, facility_type, facility_id, logon_utc
                   FROM dbo.swim_controllers
                   WHERE is_active = 1 AND first_seen_utc = last_seen_utc
                   ORDER BY first_seen_utc DESC";
        $newStmt = @sqlsrv_query($conn_swim, $newSql);
        if ($newStmt) {
            while ($row = sqlsrv_fetch_array($newStmt, SQLSRV_FETCH_ASSOC)) {
                $logonUtc = $row['logon_utc'];
                if ($logonUtc instanceof \DateTime) {
                    $logonUtc = $logonUtc->format('Y-m-d\TH:i:s\Z');
                }
                $wsEvents[] = [
                    'type' => 'controller.connected',
                    'data' => [
                        'cid'           => $row['cid'],
                        'callsign'      => $row['callsign'],
                        'frequency'     => $row['frequency'] ? (float)$row['frequency'] : null,
                        'facility_type' => $row['facility_type'],
                        'facility_id'   => $row['facility_id'],
                        'logon_utc'     => $logonUtc,
                    ],
                ];
            }
            sqlsrv_free_stmt($newStmt);
        }
    }

    if ($stats['disconnected'] > 0) {
        // Query recent disconnect events
        $discSql = "SELECT TOP 50 cid, callsign, facility_type, facility_id, session_minutes
                    FROM dbo.swim_controller_log
                    WHERE event_type = 'DISCONNECTED'
                    ORDER BY event_utc DESC";
        $discStmt = @sqlsrv_query($conn_swim, $discSql);
        if ($discStmt) {
            while ($row = sqlsrv_fetch_array($discStmt, SQLSRV_FETCH_ASSOC)) {
                $wsEvents[] = [
                    'type' => 'controller.disconnected',
                    'data' => [
                        'cid'             => $row['cid'],
                        'callsign'        => $row['callsign'],
                        'facility_type'   => $row['facility_type'],
                        'facility_id'     => $row['facility_id'],
                        'session_minutes' => $row['session_minutes'],
                    ],
                ];
            }
            sqlsrv_free_stmt($discStmt);
        }
    }

    // Always publish a batch positions event
    $wsEvents[] = [
        'type' => 'controller.positions',
        'data' => [
            'count'   => $stats['fetched'],
            'source'  => 'vnas',
        ],
    ];

    if (!empty($wsEvents) && function_exists('swim_publishToWebSocket')) {
        swim_publishToWebSocket($wsEvents);
        $stats['ws_events'] = count($wsEvents);
    }

    // Reset circuit breaker on success
    if ($stats['errors'] === 0) {
        vnas_ctrl_reset_circuit();
    }

    $msg = sprintf('%d fetched, +%d new, ~%d updated, -%d disc, %d enriched, %d staffed, %d swept',
        $stats['fetched'], $stats['inserted'], $stats['updated'],
        $stats['disconnected'], $stats['enriched'],
        $stats['staffed_boundaries'] ?? 0, $stats['unstaffed_sweep'] ?? 0);

    return [
        'success' => true,
        'message' => $msg,
        'stats'   => $stats,
    ];
}

// ============================================================================
// PID / Heartbeat
// ============================================================================

function vnas_ctrl_write_heartbeat(string $file, string $status, array $extra = []): void {
    $payload = array_merge([
        'pid'         => getmypid(),
        'status'      => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts'     => time(),
    ], $extra);
    @file_put_contents($file, json_encode($payload), LOCK_EX);
}

function vnas_ctrl_write_pid(string $pidFile): void {
    file_put_contents($pidFile, getmypid());
    register_shutdown_function(function () use ($pidFile) {
        if (file_exists($pidFile)) @unlink($pidFile);
    });
}

function vnas_ctrl_check_existing_instance(string $pidFile): bool {
    if (!file_exists($pidFile)) return false;
    $pid = (int)file_get_contents($pidFile);
    if ($pid <= 0) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        return count($output) > 1;
    }
    return posix_kill($pid, 0);
}

// ============================================================================
// Main Daemon Logic
// ============================================================================

$pidFile = sys_get_temp_dir() . '/vnas_controller_poll.pid';
$heartbeatFile = sys_get_temp_dir() . '/vnas_controller_poll.heartbeat';

// Singleton
if (vnas_ctrl_check_existing_instance($pidFile)) {
    vnas_ctrl_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

vnas_ctrl_write_pid($pidFile);
register_shutdown_function(function () use ($heartbeatFile) {
    if (file_exists($heartbeatFile)) @unlink($heartbeatFile);
});
vnas_ctrl_write_heartbeat($heartbeatFile, 'starting');

vnas_ctrl_log("========================================");
vnas_ctrl_log("vNAS Controller Feed Polling Daemon");
vnas_ctrl_log("  Poll interval: {$pollInterval}s");
vnas_ctrl_log("  Mode: " . ($runLoop ? 'daemon (continuous)' : 'single run'));
vnas_ctrl_log("  PID: " . getmypid());
vnas_ctrl_log("========================================");

$cycleCount = 0;

do {
    $cycleStart = microtime(true);
    $cycleCount++;
    vnas_ctrl_write_heartbeat($heartbeatFile, 'running', ['cycle' => $cycleCount]);

    vnas_ctrl_log("--- vNAS controller poll cycle #$cycleCount ---");

    // Skip during hibernation
    if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
        vnas_ctrl_log("  Skipped (hibernation mode)");
    } else {
        try {
            $result = vnas_ctrl_poll($debug);

            if ($result['success']) {
                vnas_ctrl_log("  " . $result['message']);
                if ($debug && !empty($result['stats'])) {
                    vnas_ctrl_log("  Stats: " . json_encode($result['stats']), 'DEBUG');
                }
            } else {
                vnas_ctrl_log("  " . $result['message'], 'ERROR');
            }
        } catch (Throwable $e) {
            vnas_ctrl_log("Poll exception: " . $e->getMessage(), 'ERROR');
        }
    }

    $cycleDuration = microtime(true) - $cycleStart;
    vnas_ctrl_write_heartbeat($heartbeatFile, 'idle', [
        'cycle'    => $cycleCount,
        'cycle_ms' => (int)round($cycleDuration * 1000),
    ]);

    if ($runLoop) {
        $sleepSeconds = max(1, (int)ceil($pollInterval - $cycleDuration));
        $sleepRemaining = $sleepSeconds;
        while ($sleepRemaining > 0) {
            sleep(1);
            $sleepRemaining--;
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

} while ($runLoop);

vnas_ctrl_log("vNAS Controller Feed Polling Daemon exiting");
