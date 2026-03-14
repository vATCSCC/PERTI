<?php
/**
 * Reference Data Sync Daemon
 *
 * Long-running daemon that performs a daily reimport of reference data at 06:00Z:
 *   1. CDRs from assets/data/cdrs.csv -> VATSIM_REF dbo.coded_departure_routes
 *   2. FAA playbook routes from assets/data/playbook_routes.csv -> MySQL playbook_plays + playbook_routes
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
// Canadian ARTCC Normalization (shared with import_faa_to_db.php)
// ============================================================================

/** Map of abbreviated Canadian ARTCC codes to their full ICAO equivalents. */
$CANADIAN_ARTCC_MAP = [
    'CZE' => 'CZEG', 'CZU' => 'CZUL', 'CZV' => 'CZVR',
    'CZW' => 'CZWG', 'CZY' => 'CZYZ', 'CZM' => 'CZQM',
    'CZQ' => 'CZQX', 'CZO' => 'CZQO',
    'PAZA' => 'ZAN',
];

/**
 * Normalize a single ARTCC code: strip US K-prefix, expand Canadian 3-letter codes.
 */
function normalizeArtcc(string $code): string {
    global $CANADIAN_ARTCC_MAP;
    $code = strtoupper(trim($code));
    if ($code === '') {
        return '';
    }
    // Strip K-prefix from US ICAO ARTCC codes (KZNY -> ZNY)
    if (preg_match('/^KZ[A-Z]{2}$/', $code)) {
        $code = substr($code, 1);
    }
    return $CANADIAN_ARTCC_MAP[$code] ?? $code;
}

/**
 * Normalize a comma-separated list of ARTCC codes.
 */
function normalizeArtccCsv(string $csv): string {
    if (trim($csv) === '') {
        return $csv;
    }
    return implode(',', array_map('normalizeArtcc', explode(',', $csv)));
}

/**
 * Normalize Canadian FIR codes that appear as route tokens.
 */
function normalizeRouteCanadian(string $routeString): string {
    global $CANADIAN_ARTCC_MAP;
    $codes = array_keys($CANADIAN_ARTCC_MAP);
    // Only normalize the 3-letter CZ codes that actually appear in routes
    $codes = array_filter($codes, function ($c) { return strlen($c) === 3; });

    $parts = preg_split('/\s+/', trim($routeString));
    $changed = false;
    foreach ($parts as &$p) {
        if (in_array(strtoupper($p), $codes)) {
            $old = $p;
            $p = normalizeArtcc($p);
            if ($p !== $old) {
                $changed = true;
            }
        }
    }
    unset($p);
    return $changed ? implode(' ', $parts) : $routeString;
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

        $plays[$playName]['routes'][] = [
            normalizeRouteCanadian($routeStr),   // route_string
            trim($row[2]),                        // origin (airports)
            trim($row[5]),                        // dest (airports)
            trim($row[2]),                        // origin_airports
            trim($row[3]),                        // origin_tracons
            normalizeArtccCsv(trim($row[4])),     // origin_artccs
            trim($row[5]),                        // dest_airports
            trim($row[6]),                        // dest_tracons
            normalizeArtccCsv(trim($row[7])),     // dest_artccs
        ];

        // Accumulate unique ARTCCs for the play-level facilities_involved
        foreach (explode(',', trim($row[4])) as $a) {
            $a = normalizeArtcc(trim($a));
            if ($a !== '') {
                $plays[$playName]['artccs'][$a] = true;
            }
        }
        foreach (explode(',', trim($row[7])) as $a) {
            $a = normalizeArtcc(trim($a));
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
 * Run both CDR and playbook syncs. Returns a combined result summary.
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

    // 2. Playbook sync
    logMsg("--- Phase 2: Playbook sync ---");
    $pbResult = syncPlaybook();
    $results[] = $pbResult['message'];
    if (!$pbResult['success']) {
        $allOk = false;
        logMsg("Playbook sync FAILED: " . $pbResult['message'], 'ERROR');
    } else {
        logMsg("Playbook sync OK: " . $pbResult['message']);
    }

    // 3. SWIM reference data sync (CDR + Playbook -> SWIM_API)
    // Non-fatal: SWIM sync failure does not affect $allOk
    logMsg("--- Phase 3: SWIM reference data sync ---");
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
