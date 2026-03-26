<?php
/**
 * Backfill flight detail columns in route_history_facts.
 *
 * Reads callsign, dfix, afix, dp_name, star_name from Azure SQL
 * (adl_flight_core + adl_flight_plan) and updates MySQL rows that
 * have NULL callsign.
 *
 * Deploy to production via VFS API, run via HTTP:
 *   ?action=status  - Show progress
 *   ?action=run     - Process one batch
 *   ?action=run&batch=1000 - Custom batch size
 *
 * Curl loop:
 *   for i in $(seq 1 5000); do
 *     curl -s --max-time 120 "https://perti.vatcscc.org/scripts/routes/backfill_flight_details.php?action=run&batch=500" -o /dev/null
 *     sleep 2
 *   done
 */

set_time_limit(90);
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Catch fatal errors and output as JSON
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
require_once(__DIR__ . "/normalize_callsign.php");

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'status';
$batchSize = max(100, min(2000, (int)($_GET['batch'] ?? 500)));

// ── Status ──
if ($action === 'status') {
    // Count total and remaining
    $total = $conn_pdo->query("SELECT COUNT(*) FROM route_history_facts")->fetchColumn();
    $remaining = $conn_pdo->query("SELECT COUNT(*) FROM route_history_facts WHERE callsign IS NULL")->fetchColumn();
    $done = $total - $remaining;

    echo json_encode([
        'status' => 'ok',
        'total' => (int)$total,
        'done' => (int)$done,
        'remaining' => (int)$remaining,
        'pct' => $total > 0 ? round($done / $total * 100, 1) : 0,
    ]);
    exit;
}

// ── Run batch ──
if ($action !== 'run') {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

// Get ADL connection via PDO sqlsrv (NOT sqlsrv extension)
// The sqlsrv extension segfaults on Azure Linux with 4+ char() columns (HY090 bug)
if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE')) {
    echo json_encode(['status' => 'error', 'message' => 'ADL config not defined']);
    exit;
}
try {
    $adlPdo = new PDO(
        "sqlsrv:Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE . ";Encrypt=true;TrustServerCertificate=false;LoginTimeout=10;ConnectionPooling=1",
        ADL_SQL_USERNAME,
        ADL_SQL_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'ADL PDO connect failed: ' . $e->getMessage()]);
    exit;
}

$startTime = microtime(true);

// 1. Get batch of flight_uids that need backfill
$stmt = $conn_pdo->prepare(
    "SELECT fact_id, flight_uid, partition_month
     FROM route_history_facts
     WHERE callsign IS NULL
     ORDER BY flight_uid ASC
     LIMIT ?"
);
$stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo json_encode(['status' => 'complete', 'message' => 'No rows need backfill']);
    exit;
}

// 2. Build flight_uid list for ADL query
$uids = array_column($rows, 'flight_uid');
$uidMap = []; // flight_uid => [fact_id, partition_month]
foreach ($rows as $r) {
    $uidMap[$r['flight_uid']] = $r;
}

// 3. Query ADL for flight details (batch via IN clause)
$uidList = implode(',', array_map('intval', $uids));

$sql = "SELECT
            c.flight_uid,
            c.callsign,
            p.dfix,
            p.afix,
            p.dp_name,
            p.star_name
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE c.flight_uid IN ($uidList)";

$adlStmt = $adlPdo->query($sql);
if (!$adlStmt) {
    echo json_encode(['status' => 'error', 'message' => 'ADL query failed']);
    exit;
}

// 4. Build update data
$adlData = [];
while ($row = $adlStmt->fetch(PDO::FETCH_ASSOC)) {
    $adlData[$row['flight_uid']] = $row;
}
$adlStmt->closeCursor();

// 5. Batch UPDATE MySQL rows
$updateStmt = $conn_pdo->prepare(
    "UPDATE route_history_facts
     SET callsign = ?,
         flight_number = ?,
         dfix = ?,
         afix = ?,
         dp_name = ?,
         star_name = ?
     WHERE fact_id = ? AND partition_month = ?"
);

$updated = 0;
$notFound = 0;

$conn_pdo->beginTransaction();
foreach ($rows as $r) {
    $uid = $r['flight_uid'];
    $adl = $adlData[$uid] ?? null;

    if ($adl) {
        $cs = trim($adl['callsign'] ?? '');
        $fn = $cs !== '' ? normalize_callsign($cs) : null;
        $updateStmt->execute([
            $cs !== '' ? $cs : null,
            $fn,
            !empty($adl['dfix']) ? trim($adl['dfix']) : null,
            !empty($adl['afix']) ? trim($adl['afix']) : null,
            !empty($adl['dp_name']) ? trim($adl['dp_name']) : null,
            !empty($adl['star_name']) ? trim($adl['star_name']) : null,
            $r['fact_id'],
            $r['partition_month'],
        ]);
        $updated++;
    } else {
        // Flight not found in ADL (archived/purged) — mark with empty string
        // so we don't re-query it
        $updateStmt->execute([
            '',    // callsign = empty string (not NULL) to mark as processed
            null,  // flight_number
            null, null, null, null,
            $r['fact_id'],
            $r['partition_month'],
        ]);
        $notFound++;
    }
}
$conn_pdo->commit();

$elapsed = round(microtime(true) - $startTime, 1);

echo json_encode([
    'status' => 'ok',
    'batch_size' => count($rows),
    'updated' => $updated,
    'not_found' => $notFound,
    'elapsed_sec' => $elapsed,
    'rate' => $elapsed > 0 ? round(count($rows) / $elapsed, 0) : 0,
]);
