<?php
/**
 * Backfill dep_rwy/arr_rwy columns from raw_route strings.
 *
 * Extracts runway designators from /{RWY} tokens (SimBrief format):
 *   AMLUH2G/33  → dep_rwy=33
 *   BK83A/16L   → arr_rwy=16L
 *   /09R        → standalone runway token
 *
 * Deploy to production via VFS API, run via HTTP:
 *   ?action=status  - Show progress
 *   ?action=run     - Process one batch
 *   ?action=run&batch=2000 - Custom batch size
 *
 * Curl loop:
 *   for i in $(seq 1 5000); do
 *     curl -s --max-time 120 "https://perti.vatcscc.org/scripts/routes/backfill_runways.php?action=run&batch=2000" -o /dev/null
 *     sleep 2
 *   done
 */

set_time_limit(90);
error_reporting(E_ALL);
ini_set('display_errors', 0);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'status' => 'fatal',
            'error' => $err['message'],
            'file' => basename($err['file']),
            'line' => $err['line'],
        ]);
    }
});

include(__DIR__ . "/../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include(__DIR__ . "/../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'status';
$batchSize = max(100, min(5000, (int)($_GET['batch'] ?? 2000)));

// ── Status ──
if ($action === 'status') {
    $total = $conn_pdo->query("SELECT COUNT(*) FROM route_history_facts WHERE raw_route IS NOT NULL AND raw_route != ''")->fetchColumn();
    $remaining = $conn_pdo->query("SELECT COUNT(*) FROM route_history_facts WHERE raw_route IS NOT NULL AND raw_route != '' AND dep_rwy IS NULL AND arr_rwy IS NULL")->fetchColumn();
    $withDep = $conn_pdo->query("SELECT COUNT(*) FROM route_history_facts WHERE dep_rwy IS NOT NULL")->fetchColumn();
    $withArr = $conn_pdo->query("SELECT COUNT(*) FROM route_history_facts WHERE arr_rwy IS NOT NULL")->fetchColumn();

    echo json_encode([
        'status' => 'ok',
        'total_with_route' => (int)$total,
        'remaining' => (int)$remaining,
        'with_dep_rwy' => (int)$withDep,
        'with_arr_rwy' => (int)$withArr,
        'pct' => $total > 0 ? round(($total - $remaining) / $total * 100, 1) : 0,
    ]);
    exit;
}

if ($action !== 'run') {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

$startTime = microtime(true);

// Get batch of rows with raw_route but no runway data
// Use fact_id ordering for stable pagination
$stmt = $conn_pdo->prepare(
    "SELECT fact_id, partition_month, raw_route
     FROM route_history_facts
     WHERE raw_route IS NOT NULL AND raw_route != ''
       AND dep_rwy IS NULL AND arr_rwy IS NULL
     ORDER BY fact_id ASC
     LIMIT ?"
);
$stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo json_encode(['status' => 'complete', 'message' => 'No rows need backfill']);
    exit;
}

// Extract runways from raw_route using same logic as normalize_route.php
function extract_runways(string $raw): array {
    $route = strtoupper(trim($raw));
    $tokens = preg_split('/\s+/', $route);
    $runways = [];

    foreach ($tokens as $token) {
        if ($token === '') continue;

        // Standalone runway token: /09R, /27L, /18
        if (preg_match('/^\/(\d{2}[LCR]?)$/', $token, $m)) {
            $runways[] = $m[1];
            continue;
        }

        // Procedure/airport + runway suffix: AMLUH2G/33, LFML/13L
        if (strpos($token, '/') !== false) {
            if (preg_match('/^.+\/(\d{2}[LRC]?)$/', $token, $m)) {
                $runways[] = $m[1];
            }
        }
    }

    return [
        'dep_rwy' => !empty($runways) ? $runways[0] : null,
        'arr_rwy' => count($runways) > 1 ? $runways[count($runways) - 1] : null,
    ];
}

// Batch: build temp table with results, then UPDATE JOIN
$conn_pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS _rwy_tmp (
    fact_id BIGINT UNSIGNED NOT NULL,
    partition_month INT NOT NULL,
    dep_rwy VARCHAR(3) NULL,
    arr_rwy VARCHAR(3) NULL,
    has_rwy TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (fact_id, partition_month)
) ENGINE=MEMORY");

$conn_pdo->exec("TRUNCATE TABLE _rwy_tmp");

$chunks = array_chunk($rows, 200);
$withData = 0;
$noData = 0;

foreach ($chunks as $chunk) {
    $vals = [];
    $params = [];
    foreach ($chunk as $r) {
        $rwy = extract_runways($r['raw_route']);
        $hasRwy = ($rwy['dep_rwy'] !== null || $rwy['arr_rwy'] !== null) ? 1 : 0;
        if ($hasRwy) $withData++;
        else $noData++;
        $vals[] = '(?,?,?,?,?)';
        $params[] = $r['fact_id'];
        $params[] = $r['partition_month'];
        $params[] = $rwy['dep_rwy'];
        $params[] = $rwy['arr_rwy'];
        $params[] = $hasRwy;
    }
    $sql = "INSERT INTO _rwy_tmp (fact_id, partition_month, dep_rwy, arr_rwy, has_rwy) VALUES " . implode(',', $vals);
    $ins = $conn_pdo->prepare($sql);
    $ins->execute($params);
}

// UPDATE JOIN: set runway columns for rows that have data
$conn_pdo->exec("UPDATE route_history_facts f
    JOIN _rwy_tmp t ON t.fact_id = f.fact_id AND t.partition_month = f.partition_month AND t.has_rwy = 1
    SET f.dep_rwy = t.dep_rwy, f.arr_rwy = t.arr_rwy");

// For rows WITHOUT runway data, set empty string to mark as processed
// (so they don't get re-queried). Use '' instead of NULL.
$conn_pdo->exec("UPDATE route_history_facts f
    JOIN _rwy_tmp t ON t.fact_id = f.fact_id AND t.partition_month = f.partition_month AND t.has_rwy = 0
    SET f.dep_rwy = '', f.arr_rwy = ''");

$conn_pdo->exec("DROP TEMPORARY TABLE _rwy_tmp");

$elapsed = round(microtime(true) - $startTime, 1);

echo json_encode([
    'status' => 'ok',
    'batch_size' => count($rows),
    'with_runway' => $withData,
    'no_runway' => $noData,
    'elapsed_sec' => $elapsed,
    'rate' => $elapsed > 0 ? round(count($rows) / $elapsed, 0) : 0,
]);
