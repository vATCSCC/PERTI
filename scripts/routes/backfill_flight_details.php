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

include(__DIR__ . "/../../load/config.php");
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

// Get ADL connection
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    echo json_encode(['status' => 'error', 'message' => 'ADL connection unavailable']);
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
$stmt->execute([$batchSize]);
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
// sqlsrv doesn't support array binding, so we interpolate safe integer values
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

$adlResult = sqlsrv_query($conn_adl, $sql);
if ($adlResult === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'ADL query failed',
        'errors' => sqlsrv_errors()
    ]);
    exit;
}

// 4. Build update data
$adlData = [];
while ($row = sqlsrv_fetch_array($adlResult, SQLSRV_FETCH_ASSOC)) {
    $adlData[$row['flight_uid']] = $row;
}
sqlsrv_free_stmt($adlResult);

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

$elapsed = round(microtime(true) - $startTime, 1);

echo json_encode([
    'status' => 'ok',
    'batch_size' => count($rows),
    'updated' => $updated,
    'not_found' => $notFound,
    'elapsed_sec' => $elapsed,
    'rate' => $elapsed > 0 ? round(count($rows) / $elapsed, 0) : 0,
]);
