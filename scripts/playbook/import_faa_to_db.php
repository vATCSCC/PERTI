<?php
/**
 * FAA Playbook CSV → Database Import
 *
 * Imports assets/data/playbook_routes.csv into playbook_plays + playbook_routes.
 * Groups ~55K CSV rows by play name into ~1,800 plays (source=FAA).
 * Idempotent: deletes existing FAA plays before re-importing.
 * Uses batch INSERT for performance (~15s vs ~120s).
 *
 * Usage: Upload to Azure, hit via public URL, then delete.
 */

set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');

$host = "vatcscc-perti.mysql.database.azure.com";
$db   = "perti_site";
$user = "jpeterson";
$pass = "Jhp21012";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

// Find CSV
$csv_paths = [
    __DIR__ . '/../../assets/data/playbook_routes.csv',
    '/home/site/wwwroot/assets/data/playbook_routes.csv',
];
$csv_path = null;
foreach ($csv_paths as $p) { if (file_exists($p)) { $csv_path = $p; break; } }
if (!$csv_path) die("CSV not found\n");

echo "Reading: $csv_path\n";
flush();

// Parse CSV into plays
$handle = fopen($csv_path, 'r');
$header = fgetcsv($handle);
$plays = [];
$total_routes = 0;

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 8) continue;
    $pn = trim($row[0]);
    $rs = trim($row[1]);
    if (empty($pn) || empty($rs)) continue;

    if (!isset($plays[$pn])) $plays[$pn] = ['routes' => [], 'artccs' => []];

    $plays[$pn]['routes'][] = [
        trim($rs), trim($row[2]), trim($row[5]),
        trim($row[2]), trim($row[3]), trim($row[4]),
        trim($row[5]), trim($row[6]), trim($row[7]),
    ];

    foreach (explode(',', trim($row[4])) as $a) { $a = trim($a); if ($a) $plays[$pn]['artccs'][$a] = 1; }
    foreach (explode(',', trim($row[7])) as $a) { $a = trim($a); if ($a) $plays[$pn]['artccs'][$a] = 1; }
    $total_routes++;
}
fclose($handle);

$play_count = count($plays);
echo "Parsed: $total_routes routes, $play_count plays\n";
flush();

function normPlay($n) { return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $n)); }

// Check re-import
$existing = (int)$pdo->query("SELECT COUNT(*) FROM playbook_plays WHERE source='FAA'")->fetchColumn();
$is_reimport = $existing > 0;
echo $is_reimport ? "Re-import ($existing existing FAA plays)\n" : "First import\n";
flush();

$pdo->beginTransaction();

try {
    if ($is_reimport) {
        $pdo->exec("DELETE FROM playbook_changelog WHERE play_id IN (SELECT play_id FROM playbook_plays WHERE source='FAA')");
        $pdo->exec("DELETE FROM playbook_plays WHERE source='FAA'");
        echo "Deleted existing FAA data\n";
        flush();
    }

    // Insert plays in batches of 100
    $play_ids = [];  // play_name => play_id
    $batch = [];
    $batch_names = [];
    $pi = 0;

    foreach ($plays as $pn => $pd) {
        $artccs = array_keys($pd['artccs']);
        sort($artccs);
        $fac = implode(',', $artccs);
        $imp = implode('/', $artccs);
        $cat = (stripos($pn, 'CAN') === 0) ? 'CANADIAN' : null;

        $batch[] = [$pn, normPlay($pn), $cat, $fac, $imp, count($pd['routes'])];
        $batch_names[] = $pn;

        if (count($batch) >= 100 || $pi === $play_count - 1) {
            $vals = [];
            $params = [];
            foreach ($batch as $b) {
                $vals[] = "(?,?,?,?,?,'standard','FAA','active',?,'import',NOW())";
                $params = array_merge($params, $b);
            }
            $sql = "INSERT INTO playbook_plays (play_name,play_name_norm,category,facilities_involved,impacted_area,route_format,source,status,route_count,created_by,created_at) VALUES " . implode(',', $vals);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Get IDs for this batch
            $first_id = (int)$pdo->lastInsertId();
            for ($i = 0; $i < count($batch_names); $i++) {
                $play_ids[$batch_names[$i]] = $first_id + $i;
            }

            $batch = [];
            $batch_names = [];
        }
        $pi++;
    }

    echo "Inserted $play_count plays\n";
    flush();

    // Insert routes in batches of 200
    $route_batch = [];
    $ri = 0;

    foreach ($plays as $pn => $pd) {
        $pid = $play_ids[$pn];
        $sort = 0;
        foreach ($pd['routes'] as $r) {
            $route_batch[] = [$pid, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $sort++];
            $ri++;

            if (count($route_batch) >= 200) {
                $vals = [];
                $params = [];
                foreach ($route_batch as $rb) {
                    $vals[] = "(?,?,?,?,?,?,?,?,?,?,?)";
                    $params = array_merge($params, $rb);
                }
                $sql = "INSERT INTO playbook_routes (play_id,route_string,origin,dest,origin_airports,origin_tracons,origin_artccs,dest_airports,dest_tracons,dest_artccs,sort_order) VALUES " . implode(',', $vals);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $route_batch = [];

                if ($ri % 5000 === 0) { echo "  Routes: $ri / $total_routes\n"; flush(); }
            }
        }
    }

    // Flush remaining routes
    if (count($route_batch) > 0) {
        $vals = [];
        $params = [];
        foreach ($route_batch as $rb) {
            $vals[] = "(?,?,?,?,?,?,?,?,?,?,?)";
            $params = array_merge($params, $rb);
        }
        $sql = "INSERT INTO playbook_routes (play_id,route_string,origin,dest,origin_airports,origin_tracons,origin_artccs,dest_airports,dest_tracons,dest_artccs,sort_order) VALUES " . implode(',', $vals);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    echo "Inserted $ri routes\n";
    flush();

    // Changelog entries (batch)
    $action = $is_reimport ? 'faa_reimport' : 'faa_import';
    $cl_batch = [];
    foreach ($play_ids as $pn => $pid) {
        $cl_batch[] = $pid;
        if (count($cl_batch) >= 200) {
            $vals = [];
            $params = [];
            foreach ($cl_batch as $id) {
                $vals[] = "(?,'$action','import',NOW())";
                $params[] = $id;
            }
            $pdo->prepare("INSERT INTO playbook_changelog (play_id,action,changed_by,changed_at) VALUES " . implode(',', $vals))->execute($params);
            $cl_batch = [];
        }
    }
    if (count($cl_batch) > 0) {
        $vals = [];
        $params = [];
        foreach ($cl_batch as $id) {
            $vals[] = "(?,'$action','import',NOW())";
            $params[] = $id;
        }
        $pdo->prepare("INSERT INTO playbook_changelog (play_id,action,changed_by,changed_at) VALUES " . implode(',', $vals))->execute($params);
    }

    $pdo->commit();
    echo "\nDone: $play_count plays, $ri routes, " . count($play_ids) . " changelog entries\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
}
