<?php
/**
 * Reference Data Sync Daemon
 *
 * Long-running daemon that performs a daily reimport of reference data at 06:00Z:
 *   1. CDRs from assets/data/cdrs.csv -> VATSIM_REF dbo.coded_departure_routes
 *   2. Preferred routes from prefroutes_db.csv -> VATSIM_REF dbo.preferred_routes
 *   3. FAA playbook routes from assets/data/playbook_routes.csv -> MySQL playbook_plays + playbook_routes
 *   4. Mirror CDR/preferred/playbook into SWIM_API tables
 *
 * This daemon runs even during hibernation since it handles static reference data
 * that does not depend on operational flight processing.
 *
 * Usage:
 *   php refdata_sync_daemon.php
 *
 * @package PERTI
 * @subpackage RefData
 * @version 1.0.0
 */

set_time_limit(0);

// Load dependencies
require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/connect.php';
require_once __DIR__ . '/../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

// ============================================================================
// Constants
// ============================================================================

/** Daily sync target hour (UTC) */
define('REFDATA_SYNC_HOUR_UTC', 6);

/** PID file path */
define('REFDATA_PID_FILE', sys_get_temp_dir() . '/refdata_sync_daemon.pid');

/** Heartbeat file path */
define('REFDATA_HEARTBEAT_FILE', sys_get_temp_dir() . '/refdata_sync_daemon.heartbeat');

/** Last sync timestamp file */
define('REFDATA_LAST_SYNC_FILE', sys_get_temp_dir() . '/refdata_sync_last.txt');

/** Maximum age (seconds) before forcing an immediate sync on startup */
define('REFDATA_MAX_AGE_SECONDS', 86400); // 24 hours

/** CDR batch insert size */
define('CDR_BATCH_SIZE', 500);

/** Playbook play batch insert size */
define('PLAYBOOK_PLAY_BATCH_SIZE', 100);

/** Playbook route batch insert size */
define('PLAYBOOK_ROUTE_BATCH_SIZE', 200);

/** Preferred route batch insert size */
define('PREFERRED_ROUTE_BATCH_SIZE', 500);

/** Preferred route PostGIS traversal batch size */
define('PREFERRED_ROUTE_POSTGIS_BATCH_SIZE', 80);

/** FAA canonical preferred-routes CSV source (AIRAC updates). */
define('PREFERRED_ROUTE_FAA_URL', 'https://www.fly.faa.gov/rmt/data_file/prefroutes_db.csv');

/** Web root for resolving asset paths */
define('REFDATA_WWWROOT', getenv('WWWROOT') ?: dirname(__DIR__));

// ============================================================================
// Signal Handling
// ============================================================================

$shutdownRequested = false;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () {
        global $shutdownRequested;
        $shutdownRequested = true;
        logMsg("SIGTERM received, shutting down after current operation");
    });
    pcntl_signal(SIGINT, function () {
        global $shutdownRequested;
        $shutdownRequested = true;
        logMsg("SIGINT received, shutting down after current operation");
    });
}

// ============================================================================
// Logging
// ============================================================================

/**
 * Log a message with UTC timestamp to stderr.
 */
function logMsg(string $msg, string $level = 'INFO'): void {
    $ts = gmdate('Y-m-d H:i:s');
    fwrite(STDERR, "[$ts UTC] [$level] [refdata-sync] $msg\n");
}

// ============================================================================
// Process Management
// ============================================================================

/**
 * Write heartbeat JSON file for monitoring.
 */
function writeHeartbeat(string $status, array $extra = []): void {
    $payload = array_merge([
        'pid'         => getmypid(),
        'status'      => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts'     => time(),
    ], $extra);

    $written = @file_put_contents(REFDATA_HEARTBEAT_FILE, json_encode($payload), LOCK_EX);
    if ($written === false) {
        logMsg("Failed to write heartbeat file", 'WARN');
    }
}

/**
 * Write PID file and register cleanup on shutdown.
 */
function writePid(): void {
    file_put_contents(REFDATA_PID_FILE, (string)getmypid());
    register_shutdown_function(function () {
        if (file_exists(REFDATA_PID_FILE)) {
            @unlink(REFDATA_PID_FILE);
        }
        if (file_exists(REFDATA_HEARTBEAT_FILE)) {
            @unlink(REFDATA_HEARTBEAT_FILE);
        }
    });
}

/**
 * Check if another daemon instance is already running.
 */
function isAlreadyRunning(): bool {
    if (!file_exists(REFDATA_PID_FILE)) {
        return false;
    }

    $pid = (int)file_get_contents(REFDATA_PID_FILE);
    if ($pid <= 0) {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        return count($output) > 1;
    }

    return posix_kill($pid, 0);
}

// ============================================================================
// Sync State
// ============================================================================

/**
 * Get the Unix timestamp of the last successful sync, or 0 if never synced.
 */
function getLastSyncTime(): int {
    if (!file_exists(REFDATA_LAST_SYNC_FILE)) {
        return 0;
    }
    $ts = (int)trim(file_get_contents(REFDATA_LAST_SYNC_FILE));
    return $ts > 0 ? $ts : 0;
}

/**
 * Record the current time as the last successful sync.
 */
function setLastSyncTime(): void {
    file_put_contents(REFDATA_LAST_SYNC_FILE, (string)time(), LOCK_EX);
}

// ============================================================================
// Canadian ARTCC Normalization — uses shared lib/ArtccNormalizer.php
// ============================================================================

/**
 * Normalize Canadian FIR codes that appear as route tokens.
 */
function normalizeRouteCanadian(string $routeString): string {
    static $codes = ['CZE','CZU','CZV','CZW','CZY','CZM','CZQ','CZO'];
    $parts = preg_split('/\s+/', trim($routeString));
    $changed = false;
    foreach ($parts as &$p) {
        if (in_array(strtoupper($p), $codes)) {
            $old = $p;
            $p = ArtccNormalizer::normalize($p);
            if ($p !== $old) {
                $changed = true;
            }
        }
    }
    unset($p);
    return $changed ? implode(' ', $parts) : $routeString;
}

/**
 * Strip leading/trailing tokens from route_string that duplicate the
 * origin/dest fields (airports or ARTCCs).  Handles K-prefix variants.
 */
function stripRouteEndpoints(string $rs, string $origAirports, string $origArtccs,
                              string $destAirports, string $destArtccs): string {
    $parts = preg_split('/\s+/', trim($rs));
    if (count($parts) < 2) return $rs;

    // Build origin code set
    $origCodes = [];
    foreach (explode(',', $origAirports) as $c) {
        $c = strtoupper(trim($c));
        if ($c === '') continue;
        $origCodes[] = $c;
        if (strlen($c) === 3 && ctype_alpha($c)) $origCodes[] = 'K' . $c;
        if (strlen($c) === 4 && $c[0] === 'K') $origCodes[] = substr($c, 1);
    }
    foreach (explode(',', $origArtccs) as $c) {
        $c = strtoupper(trim($c));
        if ($c !== '' && $c !== 'UNKN') $origCodes[] = $c;
    }

    if ($origCodes && in_array(strtoupper($parts[0]), $origCodes)) {
        array_shift($parts);
    }

    if (count($parts) < 2) return implode(' ', $parts);

    // Build dest code set
    $destCodes = [];
    foreach (explode(',', $destAirports) as $c) {
        $c = strtoupper(trim($c));
        if ($c === '') continue;
        $destCodes[] = $c;
        if (strlen($c) === 3 && ctype_alpha($c)) $destCodes[] = 'K' . $c;
        if (strlen($c) === 4 && $c[0] === 'K') $destCodes[] = substr($c, 1);
    }
    foreach (explode(',', $destArtccs) as $c) {
        $c = strtoupper(trim($c));
        if ($c !== '' && $c !== 'UNKN') $destCodes[] = $c;
    }

    if ($destCodes && in_array(strtoupper(end($parts)), $destCodes)) {
        array_pop($parts);
    }

    return implode(' ', $parts);
}

/**
 * Resolve preferred-routes CSV path.
 *
 * Search order:
 *   1) assets/data/prefroutes_db.csv
 *   2) sibling of repo root (../prefroutes_db.csv) for local workflows
 *   3) FAA canonical URL download to temp file (AIRAC fallback)
 */
function getPreferredRoutesCsvPath(): ?string {
    $candidates = [
        REFDATA_WWWROOT . '/assets/data/prefroutes_db.csv',
        dirname(REFDATA_WWWROOT) . '/prefroutes_db.csv',
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    // AIRAC fallback: download canonical FAA preferred-routes CSV
    $downloaded = downloadPreferredRoutesCsvFromFaa();
    if ($downloaded !== null) {
        return $downloaded;
    }

    return null;
}

/**
 * Download preferred-routes CSV from FAA canonical endpoint into temp file.
 */
function downloadPreferredRoutesCsvFromFaa(): ?string {
    $url = PREFERRED_ROUTE_FAA_URL;
    $context = stream_context_create([
        'http' => [
            'timeout' => 45,
            'user_agent' => 'PERTI-refdata-sync/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);
    if ($data === false || trim($data) === '') {
        logMsg("Failed to download FAA preferred routes CSV: $url", 'WARN');
        return null;
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'prefroutes_');
    if ($tmp === false) {
        logMsg("Downloaded FAA preferred routes CSV but could not allocate temp file", 'WARN');
        return null;
    }

    $written = @file_put_contents($tmp, $data, LOCK_EX);
    if ($written === false) {
        @unlink($tmp);
        logMsg("Failed to persist downloaded FAA preferred routes CSV to temp file", 'WARN');
        return null;
    }

    logMsg("Using FAA preferred routes CSV download: $url");
    return $tmp;
}

/**
 * Normalize any facility code to upper-case canonical form.
 */
function normalizeFacilityCode(?string $code): ?string {
    $code = strtoupper(trim((string)$code));
    if ($code === '' || $code === 'UNKN' || $code === 'NONE' || $code === 'N/A') {
        return null;
    }
    return ArtccNormalizer::normalize($code);
}

/**
 * Normalize to L1 ARTCC/FIR form.
 */
function normalizeCenterL1(?string $code): ?string {
    $norm = normalizeFacilityCode($code);
    if ($norm === null) {
        return null;
    }
    $l1 = ArtccNormalizer::toL1Csv($norm);
    $l1 = strtoupper(trim($l1));
    return $l1 === '' ? null : $l1;
}

/**
 * Determine if a code is ARTCC/FIR-like (for dep_artcc/arr_artcc assignment).
 */
function isArtccLike(?string $code): bool {
    if ($code === null) {
        return false;
    }
    $code = strtoupper(trim($code));
    if ($code === '') {
        return false;
    }
    return (bool)preg_match('/^(?:K?Z[A-Z0-9]{2,3}|CZ[A-Z]{2,3})$/', $code);
}

/**
 * dep_artcc / arr_artcc are aliases of origin/dest center when center is ARTCC-like.
 */
function toDepArrArtcc(?string $center): ?string {
    $center = normalizeCenterL1($center);
    if ($center === null || !isArtccLike($center)) {
        return null;
    }
    return $center;
}

/**
 * Build airport lookup from assets/data/apts.csv.
 *
 * Returns:
 *   [code => ['icao' => ?string, 'arpt' => ?string, 'center' => ?string, 'tracon' => ?string]]
 */
function loadAirportLookupFromAptsCsv(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $path = REFDATA_WWWROOT . '/assets/data/apts.csv';
    if (!file_exists($path)) {
        logMsg("apts.csv not found ($path); airport/TRACON enrichment will be limited", 'WARN');
        return $cache;
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
        logMsg("Unable to open apts.csv ($path); airport/TRACON enrichment will be limited", 'WARN');
        return $cache;
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        logMsg("apts.csv has no header row; airport/TRACON enrichment disabled", 'WARN');
        return $cache;
    }

    $idx = [];
    foreach ($header as $i => $name) {
        $idx[trim((string)$name)] = $i;
    }

    $get = static function (array $row, array $idxMap, string $col): string {
        if (!isset($idxMap[$col])) {
            return '';
        }
        return trim((string)($row[$idxMap[$col]] ?? ''));
    };

    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $icao = strtoupper($get($row, $idx, 'ICAO_ID'));
        $arpt = strtoupper($get($row, $idx, 'ARPT_ID'));
        if ($icao === '' && $arpt === '') {
            continue;
        }

        $center = normalizeFacilityCode($get($row, $idx, 'RESP_ARTCC_ID'));

        $tracon = null;
        $traconCols = [
            'Approach ID',
            'Secondary Approach ID',
            'Departure ID',
            'Secondary Departure ID',
            'Approach/Departure ID',
            'Consolidated Approach ID',
        ];
        foreach ($traconCols as $col) {
            $candidate = strtoupper($get($row, $idx, $col));
            if ($candidate !== '' && $candidate !== 'NONE' && $candidate !== 'N/A' && $candidate !== 'UNKN') {
                $tracon = $candidate;
                break;
            }
        }

        $entry = [
            'icao' => $icao !== '' ? $icao : null,
            'arpt' => $arpt !== '' ? $arpt : null,
            'center' => $center,
            'tracon' => $tracon,
        ];

        $keys = [];
        if ($icao !== '') {
            $keys[] = $icao;
            if (strlen($icao) === 4 && $icao[0] === 'K') {
                $keys[] = substr($icao, 1);
            }
        }
        if ($arpt !== '') {
            $keys[] = $arpt;
            if (strlen($arpt) === 3) {
                $keys[] = 'K' . $arpt;
            }
        }

        foreach (array_unique($keys) as $key) {
            // Prefer explicit ICAO entries over weaker aliases.
            if (!isset($cache[$key]) || ($cache[$key]['icao'] === null && $entry['icao'] !== null)) {
                $cache[$key] = $entry;
            }
        }
        $count++;
    }

    fclose($handle);
    logMsg("Loaded airport lookup from apts.csv ($count rows, " . count($cache) . " keys)");
    return $cache;
}

/**
 * Load area center metadata from VATSIM_REF.area_centers.
 *
 * Returns:
 *   [center_code => ['type' => string, 'parent_artcc' => ?string]]
 */
function loadAreaCenterLookupFromRef($connRef): array {
    $map = [];
    $stmt = sqlsrv_query($connRef, "SELECT center_code, center_type, parent_artcc FROM dbo.area_centers");
    if ($stmt === false) {
        logMsg("Unable to query area_centers for preferred route enrichment: " . adl_sql_error_message(), 'WARN');
        return $map;
    }

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $code = strtoupper(trim((string)($row['center_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $map[$code] = [
            'type' => strtoupper(trim((string)($row['center_type'] ?? ''))),
            'parent_artcc' => normalizeFacilityCode((string)($row['parent_artcc'] ?? '')),
        ];
    }

    sqlsrv_free_stmt($stmt);
    return $map;
}

/**
 * Resolve endpoint to normalized code and parent facilities.
 *
 * @param string $raw Raw CSV endpoint value
 * @param array<string,array<string,mixed>> $airportLookup
 * @param array<string,array<string,mixed>> $centerLookup
 * @param ?string $fallbackCenter CSV-provided center field (DCNTR/ACNTR)
 * @return array{code:string,raw:string,is_airport:bool,tracon:?string,center:?string,airport:?array}
 */
function resolvePreferredEndpoint(string $raw, array $airportLookup, array $centerLookup, ?string $fallbackCenter = null): array {
    $rawCode = strtoupper(trim($raw));
    $normalized = $rawCode;
    $isAirport = false;
    $airport = null;

    if ($rawCode !== '' && isset($airportLookup[$rawCode]) && !empty($airportLookup[$rawCode]['icao'])) {
        $airport = $airportLookup[$rawCode];
        $normalized = strtoupper((string)$airport['icao']);
        $isAirport = true;
    }

    $tracon = null;
    $center = null;

    if ($isAirport && $airport !== null) {
        $tracon = normalizeFacilityCode((string)($airport['tracon'] ?? ''));
        $center = normalizeFacilityCode((string)($airport['center'] ?? ''));
    } elseif ($rawCode !== '' && isset($centerLookup[$rawCode])) {
        $meta = $centerLookup[$rawCode];
        $ctype = strtoupper((string)($meta['type'] ?? ''));
        if ($ctype === 'TRACON') {
            $tracon = $rawCode;
            $center = normalizeFacilityCode((string)($meta['parent_artcc'] ?? ''));
        } elseif ($ctype === 'ARTCC' || $ctype === 'FIR') {
            $center = normalizeFacilityCode($rawCode);
        }
    }

    if ($center === null) {
        $center = normalizeFacilityCode($fallbackCenter);
    }

    return [
        'code' => $normalized !== '' ? $normalized : $rawCode,
        'raw' => $rawCode,
        'is_airport' => $isAirport,
        'tracon' => $tracon,
        'center' => $center,
        'airport' => $airport,
    ];
}

/**
 * Strip origin/destination token from route body when endpoint is an airport.
 * Falls back to DCT when no intermediate body remains.
 */
function stripPreferredRouteAirportEndpoints(string $route, array $origin, array $dest): string {
    $tokens = preg_split('/\s+/', trim($route));
    if (!is_array($tokens) || empty($tokens)) {
        return 'DCT';
    }

    $originSet = [];
    if (!empty($origin['is_airport'])) {
        $originSet[] = strtoupper((string)$origin['raw']);
        $originSet[] = strtoupper((string)$origin['code']);
        if (!empty($origin['airport']['arpt'])) {
            $originSet[] = strtoupper((string)$origin['airport']['arpt']);
        }
        if (!empty($origin['airport']['icao'])) {
            $icao = strtoupper((string)$origin['airport']['icao']);
            $originSet[] = $icao;
            if (strlen($icao) === 4 && $icao[0] === 'K') {
                $originSet[] = substr($icao, 1);
            }
        }
        if (in_array(strtoupper((string)$tokens[0]), array_unique($originSet), true)) {
            array_shift($tokens);
        }
    }

    $destSet = [];
    if (!empty($dest['is_airport']) && !empty($tokens)) {
        $destSet[] = strtoupper((string)$dest['raw']);
        $destSet[] = strtoupper((string)$dest['code']);
        if (!empty($dest['airport']['arpt'])) {
            $destSet[] = strtoupper((string)$dest['airport']['arpt']);
        }
        if (!empty($dest['airport']['icao'])) {
            $icao = strtoupper((string)$dest['airport']['icao']);
            $destSet[] = $icao;
            if (strlen($icao) === 4 && $icao[0] === 'K') {
                $destSet[] = substr($icao, 1);
            }
        }
        if (in_array(strtoupper((string)end($tokens)), array_unique($destSet), true)) {
            array_pop($tokens);
        }
    }

    $clean = trim(preg_replace('/\s+/', ' ', implode(' ', $tokens)));
    return $clean === '' ? 'DCT' : $clean;
}

// ============================================================================
// CDR Import
// ============================================================================

/**
 * Import CDRs from cdrs.csv into VATSIM_REF dbo.coded_departure_routes.
 *
 * Steps:
 *   1. Parse CSV (no header; format: CODE,ROUTE per line)
 *   2. DELETE + INSERT in a transaction
 *   3. Update dep_artcc/arr_artcc via temp table populated from VATSIM_ADL.dbo.apts
 *
 * @return array{success: bool, message: string, count: int}
 */
function syncCdrs(): array {
    $csvPath = REFDATA_WWWROOT . '/assets/data/cdrs.csv';
    if (!file_exists($csvPath)) {
        return ['success' => false, 'message' => "CDR CSV not found: $csvPath", 'count' => 0];
    }

    $conn = get_conn_ref();
    if (!$conn) {
        return ['success' => false, 'message' => 'VATSIM_REF connection unavailable', 'count' => 0];
    }

    logMsg("Parsing CDR CSV: $csvPath");

    // Parse CSV
    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => "Cannot open CDR CSV: $csvPath", 'count' => 0];
    }

    $rows = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }

        $cdrCode  = strtoupper(trim($parts[0]));
        $fullRoute = trim($parts[1]);

        if ($cdrCode === '' || $fullRoute === '') {
            continue;
        }

        // Skip deprecated _OLD versions
        if (strpos($cdrCode, '_OLD') !== false) {
            continue;
        }

        // Extract origin/destination ICAO from route tokens
        $routeParts = preg_split('/\s+/', $fullRoute);
        $originIcao = null;
        $destIcao   = null;

        // First token matching airport pattern = origin
        if (count($routeParts) > 0) {
            $first = $routeParts[0];
            if (preg_match('/^[KCPM][A-Z]{3}$/', $first)) {
                $originIcao = $first;
            }
        }

        // Last token matching airport pattern = destination
        if (count($routeParts) > 1) {
            $last = $routeParts[count($routeParts) - 1];
            // Strip any trailing procedure notation (e.g., "KBWI" from "RAVNN8 KBWI")
            if (preg_match('/^[KCPM][A-Z]{3}$/', $last)) {
                $destIcao = $last;
            }
        }

        // Fallback: extract from CDR code pattern (e.g., ABQATL* = ABQ->ATL)
        if ((!$originIcao || !$destIcao) && strlen($cdrCode) >= 6) {
            $potentialOrig = 'K' . substr($cdrCode, 0, 3);
            $potentialDest = 'K' . substr($cdrCode, 3, 3);

            if (!$originIcao && preg_match('/^K[A-Z]{3}$/', $potentialOrig)) {
                $originIcao = $potentialOrig;
            }
            if (!$destIcao && preg_match('/^K[A-Z]{3}$/', $potentialDest)) {
                $destIcao = $potentialDest;
            }
        }

        $rows[] = [$cdrCode, $fullRoute, $originIcao, $destIcao];
    }
    fclose($handle);

    $totalRows = count($rows);
    logMsg("Parsed $totalRows CDR rows (excluding _OLD)");

    if ($totalRows === 0) {
        return ['success' => false, 'message' => 'CDR CSV parsed but contained no valid rows', 'count' => 0];
    }

    // Transaction: DELETE + INSERT
    if (sqlsrv_begin_transaction($conn) === false) {
        return ['success' => false, 'message' => 'Failed to begin CDR transaction: ' . adl_sql_error_message(), 'count' => 0];
    }

    $deleteStmt = sqlsrv_query($conn, "DELETE FROM dbo.coded_departure_routes");
    if ($deleteStmt === false) {
        sqlsrv_rollback($conn);
        return ['success' => false, 'message' => 'Failed to delete existing CDRs: ' . adl_sql_error_message(), 'count' => 0];
    }
    sqlsrv_free_stmt($deleteStmt);

    $insertSql = "INSERT INTO dbo.coded_departure_routes "
        . "(cdr_code, full_route, origin_icao, dest_icao, is_active, source) "
        . "VALUES (?, ?, ?, ?, 1, 'cdrs.csv')";

    $inserted = 0;
    $errors   = 0;

    foreach ($rows as $row) {
        $stmt = sqlsrv_query($conn, $insertSql, $row);
        if ($stmt === false) {
            $errors++;
            if ($errors <= 5) {
                logMsg("CDR insert error ({$row[0]}): " . adl_sql_error_message(), 'WARN');
            }
        } else {
            $inserted++;
            sqlsrv_free_stmt($stmt);
        }
    }

    if ($errors > 0 && $inserted === 0) {
        sqlsrv_rollback($conn);
        return ['success' => false, 'message' => "All $errors CDR inserts failed, rolled back", 'count' => 0];
    }

    if (sqlsrv_commit($conn) === false) {
        return ['success' => false, 'message' => 'CDR transaction commit failed: ' . adl_sql_error_message(), 'count' => 0];
    }

    logMsg("Inserted $inserted CDRs ($errors errors)");

    // Post-insert: populate dep_artcc/arr_artcc via temp table approach
    // (Azure SQL Basic tier does not support cross-database references)
    $artccUpdated = updateCdrArtccs($conn);

    $msg = "CDR sync complete: $inserted rows";
    if ($errors > 0) {
        $msg .= " ($errors errors)";
    }
    if ($artccUpdated > 0) {
        $msg .= ", $artccUpdated ARTCC lookups populated";
    }

    return ['success' => true, 'message' => $msg, 'count' => $inserted];
}

/**
 * Populate dep_artcc and arr_artcc on coded_departure_routes by looking up
 * the airport's responsible ARTCC in VATSIM_ADL.dbo.apts.
 *
 * Uses a temp table approach because Azure SQL Basic tier does not support
 * cross-database references (VATSIM_ADL.dbo.apts from VATSIM_REF context).
 *
 * Steps:
 *   1. Read ICAO->ARTCC mapping from VATSIM_ADL via get_conn_adl()
 *   2. Create #artcc_map temp table in VATSIM_REF connection
 *   3. Batch-insert the mapping
 *   4. UPDATE coded_departure_routes via JOIN to #artcc_map
 *
 * @param resource $connRef  The VATSIM_REF sqlsrv connection
 * @return int Total number of rows updated
 */
function updateCdrArtccs($connRef): int {
    // Step 1: Read ICAO->ARTCC mapping from VATSIM_ADL
    $connAdl = get_conn_adl();
    if (!$connAdl) {
        logMsg("Cannot connect to VATSIM_ADL for ARTCC lookup — skipping ARTCC population", 'WARN');
        return 0;
    }

    $mapping = [];
    $stmt = sqlsrv_query($connAdl, "SELECT ICAO_ID, RESP_ARTCC_ID FROM dbo.apts WHERE ICAO_ID IS NOT NULL AND RESP_ARTCC_ID IS NOT NULL AND LEN(ICAO_ID) = 4");
    if ($stmt === false) {
        logMsg("Failed to query apts table: " . adl_sql_error_message(), 'WARN');
        return 0;
    }
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $mapping[$row['ICAO_ID']] = $row['RESP_ARTCC_ID'];
    }
    sqlsrv_free_stmt($stmt);
    logMsg("Loaded " . count($mapping) . " ICAO->ARTCC mappings from VATSIM_ADL.apts");

    if (empty($mapping)) {
        return 0;
    }

    // Step 2: Create temp table in VATSIM_REF
    $stmt = sqlsrv_query($connRef, "IF OBJECT_ID('tempdb..#artcc_map') IS NOT NULL DROP TABLE #artcc_map; CREATE TABLE #artcc_map (icao NVARCHAR(4) PRIMARY KEY, artcc NVARCHAR(4))");
    if ($stmt === false) {
        logMsg("Failed to create #artcc_map temp table: " . adl_sql_error_message(), 'WARN');
        return 0;
    }
    sqlsrv_free_stmt($stmt);

    // Step 3: Batch-insert mapping (900 rows/chunk)
    $items = [];
    foreach ($mapping as $icao => $artcc) {
        $items[] = [$icao, $artcc];
    }

    $chunkSize = 900;
    $inserted = 0;
    for ($i = 0; $i < count($items); $i += $chunkSize) {
        $chunk = array_slice($items, $i, $chunkSize);
        $values = [];
        $params = [];
        foreach ($chunk as $pair) {
            $values[] = "(?, ?)";
            $params[] = $pair[0];
            $params[] = $pair[1];
        }
        $sql = "INSERT INTO #artcc_map (icao, artcc) VALUES " . implode(',', $values);
        $stmt = sqlsrv_query($connRef, $sql, $params);
        if ($stmt !== false) {
            $inserted += count($chunk);
            sqlsrv_free_stmt($stmt);
        }
    }
    logMsg("Inserted $inserted rows into #artcc_map temp table");

    // Step 4: Update dep_artcc via JOIN
    $updated = 0;

    $stmt = sqlsrv_query($connRef, "UPDATE c SET c.dep_artcc = m.artcc FROM dbo.coded_departure_routes c INNER JOIN #artcc_map m ON c.origin_icao = m.icao WHERE c.dep_artcc IS NULL");
    if ($stmt !== false) {
        $depCount = sqlsrv_rows_affected($stmt);
        $updated += $depCount;
        sqlsrv_free_stmt($stmt);
        logMsg("dep_artcc updated: $depCount rows");
    } else {
        logMsg("dep_artcc update failed: " . adl_sql_error_message(), 'WARN');
    }

    $stmt = sqlsrv_query($connRef, "UPDATE c SET c.arr_artcc = m.artcc FROM dbo.coded_departure_routes c INNER JOIN #artcc_map m ON c.dest_icao = m.icao WHERE c.arr_artcc IS NULL");
    if ($stmt !== false) {
        $arrCount = sqlsrv_rows_affected($stmt);
        $updated += $arrCount;
        sqlsrv_free_stmt($stmt);
        logMsg("arr_artcc updated: $arrCount rows");
    } else {
        logMsg("arr_artcc update failed: " . adl_sql_error_message(), 'WARN');
    }

    // Cleanup temp table
    sqlsrv_query($connRef, "DROP TABLE #artcc_map");

    return $updated;
}

/**
 * Load fix_name -> L1 center mapping for route tokens using nav_fixes.artcc_id.
 *
 * @param resource $connRef
 * @param array<int,string> $tokens
 * @return array<string,string>
 */
function loadNavFixCenterLookupFromRef($connRef, array $tokens): array {
    $lookup = [];
    if (empty($tokens)) {
        return $lookup;
    }

    $normTokens = [];
    foreach ($tokens as $token) {
        $token = strtoupper(trim((string)$token));
        if ($token === '') {
            continue;
        }
        // Keep bounded token length to avoid pathological IN lists.
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $token)) {
            continue;
        }
        $normTokens[$token] = true;
    }
    $normTokens = array_keys($normTokens);
    if (empty($normTokens)) {
        return $lookup;
    }

    $chunkSize = 900; // SQL Server parameter safety
    for ($i = 0; $i < count($normTokens); $i += $chunkSize) {
        $chunk = array_slice($normTokens, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT fix_name, artcc_id
                FROM dbo.nav_fixes
                WHERE artcc_id IS NOT NULL
                  AND fix_name IN ($placeholders)";
        $stmt = sqlsrv_query($connRef, $sql, $chunk);
        if ($stmt === false) {
            logMsg("Failed nav_fixes token lookup for preferred route enrichment: " . adl_sql_error_message(), 'WARN');
            continue;
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fix = strtoupper(trim((string)($row['fix_name'] ?? '')));
            $center = normalizeCenterL1((string)($row['artcc_id'] ?? ''));
            if ($fix !== '' && $center !== null) {
                $lookup[$fix] = $center;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    return $lookup;
}

/**
 * Compute ordered, deduplicated L1 ARTCC/FIR traversal list for a route.
 *
 * Resolution order per token:
 *   airport lookup center -> area_centers (ARTCC/FIR/TRACON parent) ->
 *   nav_fixes.artcc_id -> ARTCC-like token literal.
 *
 * @param array<string,mixed> $origin
 * @param array<string,mixed> $dest
 * @param array<string,array<string,mixed>> $airportLookup
 * @param array<string,array<string,mixed>> $centerLookup
 * @param array<string,string> $navFixCenterLookup
 */
function computePreferredTraversedCenters(
    string $fullRoute,
    array $origin,
    array $dest,
    array $airportLookup,
    array $centerLookup,
    array $navFixCenterLookup
): ?string {
    $ordered = [];
    $addCenter = static function (?string $center) use (&$ordered): void {
        $center = normalizeCenterL1($center);
        if ($center === null || !isArtccLike($center)) {
            return;
        }
        if (!in_array($center, $ordered, true)) {
            $ordered[] = $center;
        }
    };

    // Ensure endpoints participate even when route body is stripped.
    $addCenter($origin['center'] ?? null);

    $tokens = preg_split('/\s+/', strtoupper(trim($fullRoute)));
    if (is_array($tokens)) {
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $center = null;

            if (isset($airportLookup[$token]) && !empty($airportLookup[$token]['center'])) {
                $center = (string)$airportLookup[$token]['center'];
            } elseif (isset($centerLookup[$token])) {
                $meta = $centerLookup[$token];
                $type = strtoupper((string)($meta['type'] ?? ''));
                if ($type === 'TRACON') {
                    $center = (string)($meta['parent_artcc'] ?? '');
                } elseif ($type === 'ARTCC' || $type === 'FIR') {
                    $center = $token;
                }
            } elseif (isset($navFixCenterLookup[$token])) {
                $center = $navFixCenterLookup[$token];
            } elseif (isArtccLike($token)) {
                $center = $token;
            }

            $addCenter($center);
        }
    }

    $addCenter($dest['center'] ?? null);

    if (empty($ordered)) {
        return null;
    }
    return implode(',', $ordered);
}

/**
 * Build a traversal route string for PostGIS expansion.
 *
 * Uses source full_route when it already appears endpoint-complete; otherwise
 * rebuilds as: origin_code + cleaned route_string + dest_code.
 *
 * @param array<string,mixed> $row
 */
function buildPreferredPostgisTraversalRoute(array $row): string {
    $full = strtoupper(trim((string)($row['full_route'] ?? '')));
    $originRaw = strtoupper(trim((string)($row['origin_raw'] ?? '')));
    $destRaw = strtoupper(trim((string)($row['dest_raw'] ?? '')));
    $originCode = strtoupper(trim((string)($row['origin_code'] ?? '')));
    $destCode = strtoupper(trim((string)($row['dest_code'] ?? '')));

    if ($full !== '') {
        $fullTokens = preg_split('/\s+/', $full);
        if (is_array($fullTokens) && !empty($fullTokens)) {
            $first = strtoupper((string)$fullTokens[0]);
            $last = strtoupper((string)$fullTokens[count($fullTokens) - 1]);
            $originCandidates = array_values(array_filter(array_unique([$originRaw, $originCode])));
            $destCandidates = array_values(array_filter(array_unique([$destRaw, $destCode])));
            if (in_array($first, $originCandidates, true) && in_array($last, $destCandidates, true)) {
                return trim(preg_replace('/\s+/', ' ', $full));
            }
        }
    }

    $body = strtoupper(trim((string)($row['route_string'] ?? '')));
    if ($body === '') {
        $body = 'DCT';
    }

    $parts = [];
    if ($originCode !== '') {
        $parts[] = $originCode;
    }
    $parts[] = $body;
    if ($destCode !== '') {
        $parts[] = $destCode;
    }

    $rebuilt = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    if ($rebuilt !== '') {
        return $rebuilt;
    }

    return $full;
}

/**
 * Compute traversed_centers using PostGIS route traversal (expand_route_with_artccs).
 *
 * This is the canonical method for preferred-route center traversal. If PostGIS
 * is unavailable, import fails (no heuristic fallback).
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{success: bool, message: string, total: int, computed: int, unresolved: int}
 */
function computePreferredTraversedCentersViaPostgis(array &$rows): array {
    $total = count($rows);
    if ($total === 0) {
        return ['success' => true, 'message' => 'No preferred rows to traverse', 'total' => 0, 'computed' => 0, 'unresolved' => 0];
    }

    $connGis = get_conn_gis();
    if (!($connGis instanceof PDO)) {
        return [
            'success' => false,
            'message' => 'PostGIS connection unavailable; traversed_centers requires PostGIS route traversal',
            'total' => $total,
            'computed' => 0,
            'unresolved' => $total,
        ];
    }

    $computed = 0;
    $unresolved = 0;

    for ($offset = 0; $offset < $total; $offset += PREFERRED_ROUTE_POSTGIS_BATCH_SIZE) {
        $batchEnd = min($offset + PREFERRED_ROUTE_POSTGIS_BATCH_SIZE, $total);
        $valueTuples = [];
        $params = [];

        for ($i = $offset; $i < $batchEnd; $i++) {
            $routeForTraversal = buildPreferredPostgisTraversalRoute($rows[$i]);
            $valueTuples[] = "(?, ?)";
            $params[] = $routeForTraversal;
            $params[] = $i;
        }

        $sql = "SELECT
                    v.route_idx,
                    COALESCE(array_to_string(era.artccs_traversed, ','), '') AS traversed_centers,
                    COALESCE(jsonb_array_length(era.waypoints), 0) AS waypoint_count
                FROM (VALUES " . implode(',', $valueTuples) . ") AS v(route_text, route_idx)
                LEFT JOIN LATERAL expand_route_with_artccs(v.route_text) era ON TRUE";

        try {
            $stmt = $connGis->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'PostGIS traversal query failed: ' . $e->getMessage(),
                'total' => $total,
                'computed' => $computed,
                'unresolved' => $total - $computed,
            ];
        }

        $seen = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idx = (int)($r['route_idx'] ?? -1);
            if ($idx < 0 || $idx >= $total) {
                continue;
            }
            $seen[$idx] = true;

            $waypointCount = (int)($r['waypoint_count'] ?? 0);
            $csv = strtoupper(trim((string)($r['traversed_centers'] ?? '')));
            if ($waypointCount >= 2 && $csv !== '') {
                $tokens = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($x) => $x !== ''));
                $rows[$idx]['traversed_centers'] = !empty($tokens) ? implode(',', $tokens) : null;
            } else {
                $rows[$idx]['traversed_centers'] = null;
            }

            if (!empty($rows[$idx]['traversed_centers'])) {
                $computed++;
            } else {
                $unresolved++;
            }
        }

        // Defensive: if a row was not returned by query, mark unresolved.
        for ($i = $offset; $i < $batchEnd; $i++) {
            if (!isset($seen[$i])) {
                $rows[$i]['traversed_centers'] = null;
                $unresolved++;
            }
        }
    }

    $msg = "PostGIS traversal complete: $computed/$total preferred routes resolved";
    if ($unresolved > 0) {
        $msg .= " ($unresolved unresolved)";
    }

    return [
        'success' => true,
        'message' => $msg,
        'total' => $total,
        'computed' => $computed,
        'unresolved' => $unresolved,
    ];
}

/**
 * Import preferred routes from prefroutes_db.csv into VATSIM_REF.preferred_routes.
 *
 * Transform rules:
 *   - Strip origin/dest tokens from route_string when endpoint is an airport.
 *   - Normalize origin/dest endpoint to ICAO if airport-mappable.
 *   - Populate origin/dest TRACON and center fields.
 *   - dep_artcc = origin_center and arr_artcc = dest_center (ARTCC/FIR-like only).
 *   - Compute traversed_centers via PostGIS route traversal.
 *
 * @return array{success: bool, message: string, count: int}
 */
function syncPreferredRoutes(): array {
    $csvPath = getPreferredRoutesCsvPath();
    if (!$csvPath) {
        return ['success' => false, 'message' => 'Preferred routes CSV not found (checked assets/data and repo parent)', 'count' => 0];
    }

    $connRef = get_conn_ref();
    if (!$connRef) {
        return ['success' => false, 'message' => 'VATSIM_REF connection unavailable', 'count' => 0];
    }

    logMsg("Parsing preferred routes CSV: $csvPath");

    $airportLookup = loadAirportLookupFromAptsCsv();
    $centerLookup = loadAreaCenterLookupFromRef($connRef);

    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => "Cannot open preferred routes CSV: $csvPath", 'count' => 0];
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'Preferred routes CSV has no header row', 'count' => 0];
    }

    $col = [];
    foreach ($header as $i => $name) {
        $n = trim((string)$name);
        $n = preg_replace('/^\xEF\xBB\xBF/u', '', $n);
        $col[$n] = $i;
    }

    $required = ['Orig', 'Route String', 'Dest', 'Type', 'Seq'];
    foreach ($required as $req) {
        if (!array_key_exists($req, $col)) {
            fclose($handle);
            return ['success' => false, 'message' => "Preferred routes CSV missing required column: $req", 'count' => 0];
        }
    }

    $getCol = static function (array $row, array $idx, string $name): string {
        if (!isset($idx[$name])) {
            return '';
        }
        return trim((string)($row[$idx[$name]] ?? ''));
    };

    $parsedRows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $origRaw = strtoupper($getCol($row, $col, 'Orig'));
        $destRaw = strtoupper($getCol($row, $col, 'Dest'));
        $fullRoute = trim($getCol($row, $col, 'Route String'));
        $routeType = strtoupper($getCol($row, $col, 'Type'));
        $seqRaw = $getCol($row, $col, 'Seq');

        if ($origRaw === '' || $destRaw === '' || $fullRoute === '' || $routeType === '' || $seqRaw === '') {
            continue;
        }

        $origin = resolvePreferredEndpoint($origRaw, $airportLookup, $centerLookup, $getCol($row, $col, 'DCNTR'));
        $dest = resolvePreferredEndpoint($destRaw, $airportLookup, $centerLookup, $getCol($row, $col, 'ACNTR'));

        $originCenter = normalizeCenterL1($origin['center'] ?? null);
        $destCenter = normalizeCenterL1($dest['center'] ?? null);

        $cleanRoute = stripPreferredRouteAirportEndpoints($fullRoute, $origin, $dest);

        $parsedRows[] = [
            'origin_code' => strtoupper((string)$origin['code']),
            'dest_code' => strtoupper((string)$dest['code']),
            'origin_raw' => $origRaw,
            'dest_raw' => $destRaw,
            'full_route' => strtoupper($fullRoute),
            'route_string' => strtoupper($cleanRoute),
            'hours1' => $getCol($row, $col, 'Hours1'),
            'hours2' => $getCol($row, $col, 'Hours2'),
            'hours3' => $getCol($row, $col, 'Hours3'),
            'route_type' => $routeType,
            'area' => $getCol($row, $col, 'Area'),
            'altitude' => $getCol($row, $col, 'Altitude'),
            'aircraft' => $getCol($row, $col, 'Aircraft'),
            'direction' => $getCol($row, $col, 'Direction'),
            'seq' => (int)$seqRaw,
            'origin_tracon' => normalizeFacilityCode((string)($origin['tracon'] ?? '')),
            'origin_center' => $originCenter,
            'dest_tracon' => normalizeFacilityCode((string)($dest['tracon'] ?? '')),
            'dest_center' => $destCenter,
            'dep_artcc' => toDepArrArtcc($originCenter), // requested: dep_artcc = origin_center (ARTCC/FIR only)
            'arr_artcc' => toDepArrArtcc($destCenter),   // requested: arr_artcc = dest_center (ARTCC/FIR only)
            'origin_is_airport' => !empty($origin['is_airport']) ? 1 : 0,
            'dest_is_airport' => !empty($dest['is_airport']) ? 1 : 0,
        ];
    }
    fclose($handle);

    if (empty($parsedRows)) {
        return ['success' => false, 'message' => 'Preferred routes CSV parsed but contained no valid rows', 'count' => 0];
    }

    $postgisTraversal = computePreferredTraversedCentersViaPostgis($parsedRows);
    if (!$postgisTraversal['success']) {
        return ['success' => false, 'message' => $postgisTraversal['message'], 'count' => 0];
    }
    logMsg($postgisTraversal['message']);

    if (sqlsrv_begin_transaction($connRef) === false) {
        return ['success' => false, 'message' => 'Failed to begin preferred-routes transaction: ' . adl_sql_error_message(), 'count' => 0];
    }

    $delStmt = sqlsrv_query($connRef, "DELETE FROM dbo.preferred_routes");
    if ($delStmt === false) {
        sqlsrv_rollback($connRef);
        return ['success' => false, 'message' => 'Failed to clear preferred_routes: ' . adl_sql_error_message(), 'count' => 0];
    }
    sqlsrv_free_stmt($delStmt);

    $insertSql = "INSERT INTO dbo.preferred_routes (
            origin_code, dest_code, origin_raw, dest_raw, route_string,
            hours1, hours2, hours3, route_type, area, altitude, aircraft, direction, seq,
            dep_artcc, arr_artcc, origin_tracon, origin_center, dest_tracon, dest_center,
            traversed_centers, origin_is_airport, dest_is_airport,
            is_active, source, effective_date, last_updated_utc
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            1, 'prefroutes_db.csv', CAST(GETUTCDATE() AS DATE), SYSUTCDATETIME()
        )";

    $inserted = 0;
    $errors = 0;

    foreach ($parsedRows as $row) {
        $params = [
            $row['origin_code'], $row['dest_code'], $row['origin_raw'], $row['dest_raw'], $row['route_string'],
            $row['hours1'] !== '' ? $row['hours1'] : null,
            $row['hours2'] !== '' ? $row['hours2'] : null,
            $row['hours3'] !== '' ? $row['hours3'] : null,
            $row['route_type'],
            $row['area'] !== '' ? $row['area'] : null,
            $row['altitude'] !== '' ? $row['altitude'] : null,
            $row['aircraft'] !== '' ? $row['aircraft'] : null,
            $row['direction'] !== '' ? $row['direction'] : null,
            $row['seq'],
            $row['dep_artcc'],
            $row['arr_artcc'],
            $row['origin_tracon'],
            $row['origin_center'],
            $row['dest_tracon'],
            $row['dest_center'],
            $row['traversed_centers'],
            $row['origin_is_airport'],
            $row['dest_is_airport'],
        ];

        $stmt = sqlsrv_query($connRef, $insertSql, $params);
        if ($stmt === false) {
            $errors++;
            if ($errors <= 5) {
                logMsg("Preferred route insert error ({$row['origin_raw']}->{$row['dest_raw']}): " . adl_sql_error_message(), 'WARN');
            }
        } else {
            $inserted++;
            sqlsrv_free_stmt($stmt);
        }
    }

    if ($inserted === 0) {
        sqlsrv_rollback($connRef);
        return ['success' => false, 'message' => "All preferred route inserts failed ($errors errors)", 'count' => 0];
    }

    if (sqlsrv_commit($connRef) === false) {
        return ['success' => false, 'message' => 'Preferred routes commit failed: ' . adl_sql_error_message(), 'count' => 0];
    }

    $msg = "Preferred route sync complete: $inserted rows";
    if ($errors > 0) {
        $msg .= " ($errors errors)";
    }

    // Export CSV for client-side search
    $csvResult = exportPreferredRoutesCsv($connRef);
    if ($csvResult['success']) {
        $msg .= "; CSV exported ({$csvResult['count']} rows)";
    } else {
        $msg .= "; CSV export failed: {$csvResult['message']}";
    }

    return ['success' => true, 'message' => $msg, 'count' => $inserted];
}

/**
 * Export preferred routes to CSV for client-side PlaybookCDRSearch module.
 * Output: assets/data/preferred_routes.csv
 */
function exportPreferredRoutesCsv($connRef): array
{
    $sql = "SELECT origin_code, dest_code, route_string, route_type, area, altitude,
                   aircraft, direction, dep_artcc, arr_artcc, origin_tracon, dest_tracon
            FROM dbo.preferred_routes
            WHERE is_active = 1
            ORDER BY origin_code, dest_code";

    $stmt = sqlsrv_query($connRef, $sql);
    if ($stmt === false) {
        return ['success' => false, 'message' => 'Query failed: ' . adl_sql_error_message(), 'count' => 0];
    }

    $wwwroot = defined('REFDATA_WWWROOT') ? REFDATA_WWWROOT : __DIR__ . '/../';
    $csvPath = rtrim($wwwroot, '/') . '/assets/data/preferred_routes.csv';

    $fp = fopen($csvPath, 'w');
    if (!$fp) {
        sqlsrv_free_stmt($stmt);
        return ['success' => false, 'message' => 'Cannot open CSV for writing: ' . $csvPath, 'count' => 0];
    }

    // Header
    fputcsv($fp, ['origin_code', 'dest_code', 'route_string', 'route_type', 'area', 'altitude',
                   'aircraft', 'direction', 'dep_artcc', 'arr_artcc', 'origin_tracon', 'dest_tracon']);

    $count = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        fputcsv($fp, [
            $row['origin_code'] ?? '',
            $row['dest_code'] ?? '',
            $row['route_string'] ?? '',
            $row['route_type'] ?? '',
            $row['area'] ?? '',
            $row['altitude'] ?? '',
            $row['aircraft'] ?? '',
            $row['direction'] ?? '',
            $row['dep_artcc'] ?? '',
            $row['arr_artcc'] ?? '',
            $row['origin_tracon'] ?? '',
            $row['dest_tracon'] ?? '',
        ]);
        $count++;
    }

    fclose($fp);
    sqlsrv_free_stmt($stmt);

    logMsg("Preferred routes CSV exported: $count rows to $csvPath");
    return ['success' => true, 'count' => $count];
}

// ============================================================================
// FAA Playbook Import
// ============================================================================

/**
 * FAA National Playbook category lookup table.
 * Authoritative source: https://www.fly.faa.gov/playbook/
 * Each play name maps to one of 9 official FAA categories.
 *
 * @return array<string, string> play_name => category
 */
function getFaaCategoryMap(): array {
    $map = [];

    $airports = [
        'ATL NO CHPPR','ATL NO CHPPR GLAVN','ATL NO HOBTT','ATL NO JJEDI','ATL NO ONDRE',
        'ATL NO OZZZI ONDRE','AUS SAT MRF',
        'BOS NO JFUND 1','BOS NO JFUND 2',
        'CLT NO BANKR','CLT NO CHSLY','CLT NO FILPZ','CLT NO FILPZ PARQR','CLT NO JONZE',
        'CLT NO JONZE BANKR','CLT NO PARQR','CLT NO STOCR',
        'DEN GCK 1','DEN GCK 2','DEN NO NORTHWEST','DEN OBH','DEN OBH ONL','DEN ONL',
        'DFW BEREE NORTH FLOW','DFW BEREE SOUTH FLOW','DFW BGTOE','DFW BOOVE',
        'DFW EAST 1 NORTH FLOW','DFW EAST 1 SOUTH FLOW','DFW EAST 2 NORTH FLOW','DFW EAST 2 SOUTH FLOW',
        'DFW FSM','DFW NO DOGS HEAD','DFW SEEVR 1 NORTH FLOW','DFW SEEVR 1 SOUTH FLOW',
        'DFW SEEVR 2','DFW SEEVR 3','DFW VKTRY','DFW WEST',
        'DTW BONZZ HTROD','DTW EAST','DTW HANBL','DTW TPGUN FERRL','DTW WEST',
        'IAH AEX NORTH','IAH AEX SOUTH','IAH DOOBI NORTH','IAH DOOBI SOUTH',
        'IAH DRLLR NORTH','IAH DRLLR SOUTH','IAH EAST 1','IAH EAST 2','IAH KOBLE',
        'IAH LINKK 1','IAH LINKK 2 NORTH','IAH LINKK 2 SOUTH','IAH TEJAS','IAH WEST',
        'LAS NO DVC','LAS NO J92','LAS NO MLF_BCE',
        'MCO NO GRNCH','MCO NO GRNCH PRICY','MCO NO GTOUT',
        'MDW BVT','MDW FISSK GSH','MDW FWA GSH','MDW NO JILLZ','MDW NO MOTIF','MDW PIA',
        'MEM BLUZZ','MEM BRBBQ','MEM MIDNIGHT',
        'MSP BAINY','MSP BLUEM','MSP EAST','MSP KKILR','MSP NORTH WEST','MSP SOUTH','MSP SOUTH EAST','MSP TORGY',
        'ORD EAST 1','ORD EAST 2','ORD EAST 3','ORD FWA','ORD JVL 1','ORD JVL 2',
        'ORD NO BENKY 1','ORD NO BENKY 2','ORD NO BENKY CHPMN','ORD NO BENKY FYTTE',
        'ORD NO VEECK','ORD NO VEECK WATSN 1','ORD NO VEECK WATSN 2',
        'ORD OXI ROYKO 1','ORD OXI ROYKO 2','ORD PAITN WATSN',
        'PHX EAGUL NO ZUN','PHX NO EAGUL','PHX NO HYDRR','PHX NO J11 EAST','PHX NO J11 WEST','PHX NO J92',
        'SFO RNAV 1',
        'YYZ NO LINNG',
    ];
    foreach ($airports as $p) { $map[$p] = 'Airports'; }

    $e2w = [
        'BUM',
        'CAN AGLIN WEST 1','CAN AGLIN WEST 2','CAN AGLIN WEST 3',
        'CAN CHICA WEST 1','CAN CHICA WEST 2','CAN CHICA WEST 3',
        'CAN KENPA WEST 1','CAN KENPA WEST 2','CAN KENPA WEST 3','CAN KENPA WEST 4','CAN KENPA WEST 5','CAN KENPA WEST 6',
        'CAN NOSIK WEST 1','CAN NOSIK WEST 2','CAN NOSIK WEST 3','CAN NOSIK WEST 4',
        'CAN OVORA WEST 1','CAN OVORA WEST 2',
        'CAN ROTMA WEST 1','CAN ROTMA WEST 2','CAN ROTMA WEST 3','CAN ROTMA WEST 4',
        'CAN SSM WEST 1','CAN SSM WEST 2','CAN SSM WEST 3','CAN SSM WEST 4',
        'CAN SSM WEST 5','CAN SSM WEST 6','CAN SSM WEST 7','CAN SSM WEST 8',
        'CAN STNRD WEST 1','CAN STNRD WEST 2',
        'DELMARVA 1','FAM','FDRER','GREKI 4','HAVANA WEST','HLC',
        'HNKER 1','HNKER 2','JCT','LEV WEST','LNK','MCI WEST','MCW WEST',
        'MEX MRF WEST','NO EWM ELP','ONL',
        'PNH 1','PNH 2',
        'ROCKIES NORTH 1','ROCKIES NORTH 2','ROCKIES SOUTH 1','ROCKIES SOUTH 2',
        'SAN ANDREAS 1','SAN ANDREAS 2','SLN','STL','TUL 1',
    ];
    foreach ($e2w as $p) { $map[$p] = 'East to West Transcon'; }

    $equipment = ['GTK MBPV','GTK MDCS','GTK ZSU NB','GTK ZSU SB'];
    foreach ($equipment as $p) { $map[$p] = 'Equipment'; }

    $regional = [
        'CANCUN ARRIVALS','COWBOYS EAST','COWBOYS WEST',
        'DC METRO NATS ESCAPE VIA GOATR','DC NORTH','DC NORTH 2',
        'DQO TUNNEL SOUTHWEST','DQO TUNNEL WEST',
        'FLORIDA TO MIDWEST 2','FLORIDA TO MIDWEST ESCAPE',
        'FLORIDA TO NE 1','FLORIDA TO NE 2','FLORIDA TO NE 3','FLORIDA TO NE 4','FLORIDA TO NE 5',
        'FLORIDA TO NE ESCAPE',
        'FLORIDA TO OHIO VALLEY 1','FLORIDA TO OHIO VALLEY 2','FLORIDA TO TEXAS',
        'GREKI 1','GREKI 2','GREKI 3',
        'LAKE ERIE EAST','LAKE ERIE WEST',
        'LAS AREA AVOIDANCE EAST','LAS AREA AVOIDANCE WEST',
        'LIMBO NORTH','LIMBO SOUTH','LIMBO SOUTHWEST','LIMBO WEST',
        'MACER 1','MACER 2','MACER 3',
        'MAZATLAN BYPASS','MCO ESCAPE',
        'MEX OBGIY WEST 1','MEX OBGIY WEST 2',
        'MIDWEST PNH WEST','MIDWEST TO FLORIDA',
        'MOJAVE EAST','MOJAVE WEST',
        'N90 THROUGH ZBW',
        'NE TO ATL CLT',
        'NE TO FLORIDA VIA J48 1','NE TO FLORIDA VIA J48 2','NE TO FLORIDA VIA J48 3',
        'NE TO FLORIDA VIA J6',
        'NE TO FLORIDA VIA J64 1','NE TO FLORIDA VIA J64 2','NE TO FLORIDA VIA J64 3',
        'NE TO FLORIDA VIA Q409',
        'NE TO FLORIDA VIA Q480 1','NE TO FLORIDA VIA Q480 2','NE TO FLORIDA VIA Q480 3',
        'NE TO FLORIDA VIA Q75 1','NE TO FLORIDA VIA Q75 2',
        'NE TO FLORIDA VIA Q97 1','NE TO FLORIDA VIA Q97 2',
        'NE TO TEXAS ZME',
        'NEW YORK DUCT WEST','NEW YORK DUCT NORTH',
        'NO J6 2','NO J80',
        'NO Q34 1','NO Q34 2','NO Q34 3',
        'OHIO VALLEY TO FLORIDA 2','OHIO VALLEY TO FLORIDA 3',
        'PHLYER NORTH','PHLYER SOUTH','PHLYER WEST',
        'POTOMAC NORTH LOW','PSK',
        'RSW AREA ESCAPE',
        'SERBOS 1','SERMN EAST','SERMN NORTH','SERMN SOUTH',
        'SIERRA 1','SIERRA 2',
        'SKI COUNTRY 1','SKI COUNTRY 2','SKI COUNTRY 3',
        'SPRINGS EAST','SPRINGS WEST',
        'TEXAS TO FLORIDA',
        'TPA AREA ESCAPE',
        'WATRS','WEVEL',
        'ZAB NO DOGS PAW','ZBW HEADI','ZBW MICAH',
        'ZBW NATS ESCAPE VIA HNK','ZBW NATS ESCAPE VIA SYR',
        'ZBW TO FLORIDA VIA Q29 1','ZBW TO FLORIDA VIA Q29 2',
        'ZBW VIA HNK ESCAPE','ZBW VIA HTO ESCAPE','ZBW VIA SYR ESCAPE',
        'ZEU ESCAPE','ZNY WEST CAPPING','ZTL TO FLORIDA ESCAPE',
    ];
    foreach ($regional as $p) { $map[$p] = 'Regional Routes'; }

    $snowbird = [
        'ATL TO ZBW','ATLANTIC NORTH 2','ATLANTIC SOUTH 2',
        'CARIBBEAN ARVLS VIA FUNDI','CARIBBEAN ARVLS VIA URSUS','CARIBBEAN HARP SOUTH',
        'CUBA ARRIVALS VIA URSUS','CUBA ARVLS VIA FUNDI','CUBA ARVLS VIA TUNSL',
        'DOM REP CARIBBEAN HARP NORTH','DOMESTIC HARP NORTH','DOMESTIC HARP SOUTH',
        'HOLIDAY GULF ROUTES','NYSATS TO FL',
        'SOUTH TO DCMETS','SOUTH TO HPN','SOUTH TO NY SATS','SOUTH TO PHL AND PHL SATS',
        'UPSTATE NY-CANADA VIA J61 Q103',
        'ZMA CARIBBEAN HARP NORTH','ZMR ARVLS VIA CANOA','ZSU CARIBBEAN HARP NORTH',
    ];
    foreach ($snowbird as $p) { $map[$p] = 'Snowbird'; }

    $spaceOps = ['CAPE LAUNCH 1','CAPE LAUNCH 2A','CAPE LAUNCH 2B','CAPE LAUNCH NB'];
    foreach ($spaceOps as $p) { $map[$p] = 'Space Ops'; }

    $specialOps = [
        'BCT AREA NO GAWKS',
        'GA LIGHT JETS TO MIA AND SATS','GA PROPS TO MIA AND SATS','GA TO BCT AREA',
        'GA TO EWR AND SATS','GA TO MIA AND SATS VIA MOGAE','GA TO SUA',
        'MIA AND SATS VIA DEEP WATER','MIA VIA MOGAE',
        'PHL TO ZBW CZU ZEU','SOUTH TO ZBW','WEST TO ZBW','ZBW CZU TO ZDC',
    ];
    foreach ($specialOps as $p) { $map[$p] = 'Special Ops'; }

    $sua = [
        'DC METROS TO ZBW','SENTRY MAYHEM SOUTHBOUND',
        'STAVE 1','STAVE 2 FLORIDA ARVLS',
        'WATRS ROUTES TO AVOID SENTRY',
        'YANKEE DC METS TO ZBW','YANKEE NO GA VIA LENDY','YANKEE PHL DEPT TO ZBW CZU',
        'YANKEE PHL DEPT TO ZEU','YANKEE SOUTH TO ZBW','YANKEE WEST TO BDL BED BVY LWM',
    ];
    foreach ($sua as $p) { $map[$p] = 'SUA Activity'; }

    $w2e = [
        'BAE 1','BAE 2',
        'CAN AGLIN EAST 1','CAN AGLIN EAST 2','CAN AGLIN EAST 3',
        'CAN GERTY EAST 1','CAN GERTY EAST 2','CAN GERTY EAST 3',
        'CAN NOTAP EAST 1','CAN NOTAP EAST 2','CAN NOTAP EAST 3',
        'CAN RUBKI EAST 1','CAN RUBKI EAST 2','CAN RUBKI EAST 3','CAN RUBKI EAST 4',
        'CAN STNRD EAST 1','CAN STNRD EAST 2',
        'CAN ULUTO EAST 1','CAN ULUTO EAST 2','CAN ULUTO EAST 3','CAN ULUTO EAST 4',
        'CEW','GRB','HAVANA EAST','HITMN','IIU',
        'JOT 1','JOT 2',
        'LEV EAST 1','LEV EAST 2',
        'MCI',
        'MEX AMUDI EAST 1','MEX AMUDI EAST 2','MEX CUS EAST','MEX VYLLA EAST',
        'MGM 1','MGM 2','MGM 3','MGM 4',
        'N90 PREF ROUTES','OBK','PXV','ROD','SPI','VHP','VLKNN',
        'WEST TO Q-Y',
    ];
    foreach ($w2e as $p) { $map[$p] = 'West to East Transcon'; }

    return $map;
}

/**
 * Derive a play's category using the FAA lookup map. Falls back to null for
 * unrecognized play names.
 */
function deriveCategory(string $playName): ?string {
    static $faaMap = null;
    if ($faaMap === null) {
        $faaMap = getFaaCategoryMap();
    }

    if (isset($faaMap[$playName])) {
        return $faaMap[$playName];
    }

    // Strip _old_XXXX suffix and try again
    $baseName = preg_replace('/_old_\d+$/', '', $playName);
    if ($baseName !== $playName && isset($faaMap[$baseName])) {
        return $faaMap[$baseName];
    }

    return null;
}

/**
 * Normalize a play name to a comparison-safe form (uppercase alphanumeric only).
 */
function normPlayName(string $name): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name));
}

/**
 * Import FAA playbook routes from playbook_routes.csv into MySQL
 * playbook_plays + playbook_routes tables.
 *
 * Steps:
 *   1. Parse CSV (has header row)
 *   2. Group routes by play name
 *   3. In a transaction: delete FAA data, re-insert plays + routes + changelog
 *
 * @return array{success: bool, message: string, plays: int, routes: int}
 */
function syncPlaybook(): array {
    global $conn_pdo;

    if (!$conn_pdo) {
        return ['success' => false, 'message' => 'MySQL PDO connection unavailable', 'plays' => 0, 'routes' => 0];
    }

    $csvPath = REFDATA_WWWROOT . '/assets/data/playbook_routes.csv';
    if (!file_exists($csvPath)) {
        return ['success' => false, 'message' => "Playbook CSV not found: $csvPath", 'plays' => 0, 'routes' => 0];
    }

    logMsg("Parsing playbook CSV: $csvPath");

    // Parse CSV into plays (grouped by play name)
    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => "Cannot open playbook CSV: $csvPath", 'plays' => 0, 'routes' => 0];
    }

    // Skip header row
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'Playbook CSV has no header row', 'plays' => 0, 'routes' => 0];
    }

    $plays       = [];
    $totalRoutes = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 8) {
            continue;
        }

        $playName   = trim($row[0]);
        $routeStr   = trim($row[1]);

        if ($playName === '' || $routeStr === '') {
            continue;
        }

        if (!isset($plays[$playName])) {
            $plays[$playName] = ['routes' => [], 'artccs' => [], 'dest_artccs' => []];
        }

        // Strip embedded origin/dest from route_string (CSV has them in both)
        $origApts  = trim($row[2]);
        $destApts  = trim($row[5]);
        $origArtc  = ArtccNormalizer::normalizeCsv(trim($row[4]));
        $destArtc  = ArtccNormalizer::normalizeCsv(trim($row[7]));
        $cleanRoute = stripRouteEndpoints(
            normalizeRouteCanadian($routeStr),
            $origApts, $origArtc, $destApts, $destArtc
        );

        $plays[$playName]['routes'][] = [
            $cleanRoute,                          // route_string (cleaned)
            $origApts,                            // origin (airports)
            $destApts,                            // dest (airports)
            $origApts,                            // origin_airports
            trim($row[3]),                        // origin_tracons
            $origArtc,                            // origin_artccs
            $destApts,                            // dest_airports
            trim($row[6]),                        // dest_tracons
            $destArtc,                            // dest_artccs
        ];

        // Accumulate unique ARTCCs for the play-level facilities_involved
        foreach (explode(',', trim($row[4])) as $a) {
            $a = ArtccNormalizer::normalize(trim($a));
            if ($a !== '') {
                $plays[$playName]['artccs'][$a] = true;
            }
        }
        foreach (explode(',', trim($row[7])) as $a) {
            $a = ArtccNormalizer::normalize(trim($a));
            if ($a !== '') {
                $plays[$playName]['artccs'][$a] = true;
                $plays[$playName]['dest_artccs'][$a] = true;
            }
        }

        $totalRoutes++;
    }
    fclose($handle);

    $playCount = count($plays);
    logMsg("Parsed $totalRoutes routes across $playCount plays");

    if ($playCount === 0) {
        return ['success' => false, 'message' => 'Playbook CSV parsed but contained no valid plays', 'plays' => 0, 'routes' => 0];
    }

    // Check if this is a re-import
    $existingStmt = $conn_pdo->query("SELECT COUNT(*) FROM playbook_plays WHERE source='FAA'");
    $existingCount = (int)$existingStmt->fetchColumn();
    $isReimport = $existingCount > 0;

    logMsg($isReimport
        ? "Re-import mode ($existingCount existing FAA plays will be replaced)"
        : "First import mode");

    $conn_pdo->beginTransaction();

    try {
        // Preserve traversal data before DELETE — keyed by (play_name, sort_order)
        $savedTraversals = [];
        if ($isReimport) {
            $travStmt = $conn_pdo->query("
                SELECT p.play_name, r.sort_order,
                    r.traversed_artccs, r.traversed_tracons,
                    r.traversed_sectors_low, r.traversed_sectors_high, r.traversed_sectors_superhigh
                FROM playbook_routes r
                JOIN playbook_plays p ON p.play_id = r.play_id
                WHERE p.source = 'FAA' AND r.traversed_artccs IS NOT NULL
            ");
            while ($t = $travStmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $t['play_name'] . '|' . $t['sort_order'];
                $savedTraversals[$key] = $t;
            }
            logMsg("Saved traversal data for " . count($savedTraversals) . " routes");
        }

        // Delete existing FAA data (routes cascade via FK, but changelog needs explicit delete)
        if ($isReimport) {
            $conn_pdo->exec("DELETE FROM playbook_changelog WHERE play_id IN (SELECT play_id FROM playbook_plays WHERE source='FAA')");
            $conn_pdo->exec("DELETE FROM playbook_plays WHERE source='FAA'");
            logMsg("Deleted existing FAA plays and changelog entries");
        }

        // Insert plays in batches
        $playIds    = []; // play_name => play_id
        $batch      = [];
        $batchNames = [];
        $playIndex  = 0;

        foreach ($plays as $playName => $playData) {
            $artccs = array_keys($playData['artccs']);
            sort($artccs);
            $facilitiesInvolved = implode(',', $artccs);
            $impactedArea       = implode('/', $artccs);
            $category           = deriveCategory($playName);

            $batch[]      = [$playName, normPlayName($playName), $category, $facilitiesInvolved, $impactedArea, count($playData['routes'])];
            $batchNames[] = $playName;

            $isLastPlay = ($playIndex === $playCount - 1);

            if (count($batch) >= PLAYBOOK_PLAY_BATCH_SIZE || $isLastPlay) {
                $placeholders = [];
                $params       = [];
                foreach ($batch as $b) {
                    $placeholders[] = "(?,?,?,?,?,'standard','FAA','active','public',?,'refdata_sync',NOW())";
                    $params = array_merge($params, $b);
                }
                $sql = "INSERT INTO playbook_plays "
                    . "(play_name,play_name_norm,category,facilities_involved,impacted_area,"
                    . "route_format,source,status,visibility,route_count,created_by,created_at) "
                    . "VALUES " . implode(',', $placeholders);
                $stmt = $conn_pdo->prepare($sql);
                $stmt->execute($params);

                // Retrieve auto-increment IDs for this batch
                $firstId = (int)$conn_pdo->lastInsertId();
                for ($i = 0; $i < count($batchNames); $i++) {
                    $playIds[$batchNames[$i]] = $firstId + $i;
                }

                $batch      = [];
                $batchNames = [];
            }

            $playIndex++;
        }

        logMsg("Inserted $playCount plays");

        // Insert routes in batches
        $routeBatch    = [];
        $routeInserted = 0;

        foreach ($plays as $playName => $playData) {
            $playId   = $playIds[$playName];
            $sortOrder = 0;

            foreach ($playData['routes'] as $r) {
                $routeBatch[] = [
                    $playId,
                    $r[0], $r[1], $r[2], // route_string, origin, dest
                    $r[3], $r[4], $r[5], // origin_airports, origin_tracons, origin_artccs
                    $r[6], $r[7], $r[8], // dest_airports, dest_tracons, dest_artccs
                    $sortOrder++,
                ];

                if (count($routeBatch) >= PLAYBOOK_ROUTE_BATCH_SIZE) {
                    flushRouteBatch($conn_pdo, $routeBatch);
                    $routeInserted += count($routeBatch);
                    $routeBatch = [];

                    if ($routeInserted % 5000 === 0) {
                        logMsg("  Routes progress: $routeInserted / $totalRoutes");
                    }
                }
            }
        }

        // Flush remaining routes
        if (count($routeBatch) > 0) {
            flushRouteBatch($conn_pdo, $routeBatch);
            $routeInserted += count($routeBatch);
        }

        logMsg("Inserted $routeInserted routes");

        // Restore traversal data from pre-DELETE snapshot via temp table
        if (count($savedTraversals) > 0) {
            $conn_pdo->exec("CREATE TEMPORARY TABLE _traversal_restore (
                play_name VARCHAR(100),
                sort_order INT,
                traversed_artccs TEXT,
                traversed_tracons TEXT,
                traversed_sectors_low TEXT,
                traversed_sectors_high TEXT,
                traversed_sectors_superhigh TEXT,
                PRIMARY KEY (play_name, sort_order)
            )");

            // Batch insert saved traversals into temp table
            $tBatch = [];
            $tParams = [];
            foreach ($savedTraversals as $key => $t) {
                $tBatch[] = "(?,?,?,?,?,?,?)";
                $tParams = array_merge($tParams, [
                    $t['play_name'], $t['sort_order'],
                    $t['traversed_artccs'], $t['traversed_tracons'],
                    $t['traversed_sectors_low'], $t['traversed_sectors_high'],
                    $t['traversed_sectors_superhigh'],
                ]);

                if (count($tBatch) >= 500) {
                    $conn_pdo->prepare(
                        "INSERT INTO _traversal_restore VALUES " . implode(',', $tBatch)
                    )->execute($tParams);
                    $tBatch = [];
                    $tParams = [];
                }
            }
            if (count($tBatch) > 0) {
                $conn_pdo->prepare(
                    "INSERT INTO _traversal_restore VALUES " . implode(',', $tBatch)
                )->execute($tParams);
            }

            // Single bulk UPDATE via JOIN
            $restored = $conn_pdo->exec("
                UPDATE playbook_routes r
                JOIN playbook_plays p ON p.play_id = r.play_id
                JOIN _traversal_restore t ON t.play_name = p.play_name AND t.sort_order = r.sort_order
                SET r.traversed_artccs = t.traversed_artccs,
                    r.traversed_tracons = t.traversed_tracons,
                    r.traversed_sectors_low = t.traversed_sectors_low,
                    r.traversed_sectors_high = t.traversed_sectors_high,
                    r.traversed_sectors_superhigh = t.traversed_sectors_superhigh
            ");

            $conn_pdo->exec("DROP TEMPORARY TABLE IF EXISTS _traversal_restore");
            logMsg("Restored traversal data for " . count($savedTraversals) . " routes ($restored rows updated)");
        }

        // Changelog entries
        $action  = $isReimport ? 'faa_reimport' : 'faa_import';
        $clBatch = [];

        foreach ($playIds as $playName => $playId) {
            $clBatch[] = $playId;

            if (count($clBatch) >= PLAYBOOK_ROUTE_BATCH_SIZE) {
                flushChangelogBatch($conn_pdo, $clBatch, $action);
                $clBatch = [];
            }
        }
        if (count($clBatch) > 0) {
            flushChangelogBatch($conn_pdo, $clBatch, $action);
        }

        $conn_pdo->commit();

        $msg = "Playbook sync complete: $playCount plays, $routeInserted routes";
        return ['success' => true, 'message' => $msg, 'plays' => $playCount, 'routes' => $routeInserted];

    } catch (\Throwable $e) {
        $conn_pdo->rollBack();
        $msg = "Playbook sync failed (rolled back): " . $e->getMessage();
        logMsg($msg, 'ERROR');
        return ['success' => false, 'message' => $msg, 'plays' => 0, 'routes' => 0];
    }
}

/**
 * Flush a batch of route rows into playbook_routes via a single prepared INSERT.
 *
 * @param PDO   $pdo   MySQL PDO connection
 * @param array $batch Array of route row arrays
 */
function flushRouteBatch(PDO $pdo, array $batch): void {
    $placeholders = [];
    $params       = [];
    foreach ($batch as $rb) {
        $placeholders[] = "(?,?,?,?,?,?,?,?,?,?,?)";
        $params = array_merge($params, $rb);
    }
    $sql = "INSERT INTO playbook_routes "
        . "(play_id,route_string,origin,dest,"
        . "origin_airports,origin_tracons,origin_artccs,"
        . "dest_airports,dest_tracons,dest_artccs,sort_order) "
        . "VALUES " . implode(',', $placeholders);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Flush a batch of changelog entries for FAA import/reimport.
 *
 * @param PDO    $pdo    MySQL PDO connection
 * @param array  $playIds Array of play_id integers
 * @param string $action  Changelog action (faa_import or faa_reimport)
 */
function flushChangelogBatch(PDO $pdo, array $playIds, string $action): void {
    $placeholders = [];
    $params       = [];
    foreach ($playIds as $id) {
        $placeholders[] = "(?,'$action','refdata_sync',NOW())";
        $params[] = $id;
    }
    $sql = "INSERT INTO playbook_changelog (play_id,action,changed_by,changed_at) VALUES "
        . implode(',', $placeholders);
    $pdo->prepare($sql)->execute($params);
}

// ============================================================================
// Main Sync Orchestrator
// ============================================================================

/**
 * Run CDR, preferred-route, and playbook syncs (plus SWIM mirror sync).
 * Returns a combined result summary.
 *
 * @return array{success: bool, message: string}
 */
function runFullSync(): array {
    $startTime = microtime(true);
    $results   = [];
    $allOk     = true;

    logMsg("========================================");
    logMsg("Starting reference data sync");
    logMsg("========================================");

    // 1. CDR sync
    logMsg("--- Phase 1: CDR sync ---");
    $cdrResult = syncCdrs();
    $results[] = $cdrResult['message'];
    if (!$cdrResult['success']) {
        $allOk = false;
        logMsg("CDR sync FAILED: " . $cdrResult['message'], 'ERROR');
    } else {
        logMsg("CDR sync OK: " . $cdrResult['message']);
    }

    // 2. Preferred routes sync
    logMsg("--- Phase 2: Preferred routes sync ---");
    $prefResult = syncPreferredRoutes();
    $results[] = $prefResult['message'];
    if (!$prefResult['success']) {
        $allOk = false;
        logMsg("Preferred routes sync FAILED: " . $prefResult['message'], 'ERROR');
    } else {
        logMsg("Preferred routes sync OK: " . $prefResult['message']);
    }

    // 3. Playbook sync
    logMsg("--- Phase 3: Playbook sync ---");
    $pbResult = syncPlaybook();
    $results[] = $pbResult['message'];
    if (!$pbResult['success']) {
        $allOk = false;
        logMsg("Playbook sync FAILED: " . $pbResult['message'], 'ERROR');
    } else {
        logMsg("Playbook sync OK: " . $pbResult['message']);
    }

    // 4. SWIM reference data sync (CDR + Preferred + Playbook -> SWIM_API)
    // Non-fatal: SWIM sync failure does not affect $allOk
    logMsg("--- Phase 4: SWIM reference data sync ---");
    require_once __DIR__ . '/swim_refdata_sync.php';
    $swimResult = runSwimRefdataSync();
    $results[] = 'SWIM: ' . $swimResult['message'];
    if (!$swimResult['success']) {
        logMsg("SWIM refdata sync issue (non-fatal): " . $swimResult['message'], 'WARN');
    } else {
        logMsg("SWIM refdata sync OK: " . $swimResult['message']);
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    $summary = implode(' | ', $results) . " [{$elapsed}s]";

    logMsg("========================================");
    logMsg("Sync " . ($allOk ? "COMPLETE" : "COMPLETED WITH ERRORS") . " in {$elapsed}s");
    logMsg("========================================");

    return ['success' => $allOk, 'message' => $summary];
}

// ============================================================================
// Sleep Calculation
// ============================================================================

/**
 * Calculate the number of seconds until the next occurrence of the target
 * hour (UTC). If the target hour is right now (within the same minute),
 * returns 0; otherwise returns seconds until the next day's target hour.
 *
 * @return int Seconds to sleep
 */
function secondsUntilNextSync(): int {
    $now       = time();
    $todaySync = gmmktime(REFDATA_SYNC_HOUR_UTC, 0, 0, (int)gmdate('n', $now), (int)gmdate('j', $now), (int)gmdate('Y', $now));

    if ($todaySync > $now) {
        return $todaySync - $now;
    }

    // Target hour already passed today; schedule for tomorrow
    $tomorrowSync = $todaySync + 86400;
    return $tomorrowSync - $now;
}

// ============================================================================
// Main Daemon Loop
// ============================================================================

// Guard against duplicate instances
if (isAlreadyRunning()) {
    logMsg("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

writePid();
writeHeartbeat('starting');

logMsg("========================================");
logMsg("Reference Data Sync Daemon Starting");
logMsg("  Sync schedule: daily at " . str_pad((string)REFDATA_SYNC_HOUR_UTC, 2, '0', STR_PAD_LEFT) . ":00Z");
logMsg("  PID: " . getmypid());
logMsg("  WWWROOT: " . REFDATA_WWWROOT);
logMsg("========================================");

// Check if an immediate sync is needed (never synced, or last sync > 24h ago)
$lastSync = getLastSyncTime();
$syncAge  = ($lastSync > 0) ? (time() - $lastSync) : PHP_INT_MAX;

if ($syncAge >= REFDATA_MAX_AGE_SECONDS) {
    if ($lastSync === 0) {
        logMsg("No previous sync recorded -- running immediate sync");
    } else {
        $ageHours = round($syncAge / 3600, 1);
        logMsg("Last sync was {$ageHours}h ago (threshold: 24h) -- running immediate sync");
    }

    writeHeartbeat('syncing', ['reason' => 'startup']);
    $result = runFullSync();

    if ($result['success']) {
        setLastSyncTime();
        logMsg("Startup sync succeeded");
    } else {
        logMsg("Startup sync completed with errors: " . $result['message'], 'WARN');
        // Still update last sync time to avoid retry-storming
        setLastSyncTime();
    }
} else {
    $ageHours = round($syncAge / 3600, 1);
    logMsg("Last sync was {$ageHours}h ago -- skipping immediate sync");
}

// Enter the main sleep-wake loop
while (!$shutdownRequested) {
    $sleepSeconds = secondsUntilNextSync();
    $nextSyncUtc  = gmdate('Y-m-d H:i:s', time() + $sleepSeconds);

    logMsg("Next sync at $nextSyncUtc UTC (sleeping {$sleepSeconds}s)");
    writeHeartbeat('sleeping', ['next_sync_utc' => $nextSyncUtc]);

    // Sleep in 1-second increments to allow graceful shutdown
    $slept = 0;
    while ($slept < $sleepSeconds && !$shutdownRequested) {
        sleep(1);
        $slept++;

        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    if ($shutdownRequested) {
        break;
    }

    // Run the sync
    writeHeartbeat('syncing', ['reason' => 'scheduled']);
    $result = runFullSync();

    if ($result['success']) {
        setLastSyncTime();
    } else {
        logMsg("Scheduled sync completed with errors", 'WARN');
        setLastSyncTime();
    }
}

writeHeartbeat('stopped');
logMsg("Reference Data Sync Daemon exiting");
