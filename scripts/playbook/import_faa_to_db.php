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

    if (!isset($plays[$pn])) $plays[$pn] = ['routes' => [], 'artccs' => [], 'dest_artccs' => []];

    $plays[$pn]['routes'][] = [
        trim($rs), trim($row[2]), trim($row[5]),
        trim($row[2]), trim($row[3]), trim($row[4]),
        trim($row[5]), trim($row[6]), trim($row[7]),
    ];

    foreach (explode(',', trim($row[4])) as $a) { $a = trim($a); if ($a) $plays[$pn]['artccs'][$a] = 1; }
    foreach (explode(',', trim($row[7])) as $a) { $a = trim($a); if ($a) { $plays[$pn]['artccs'][$a] = 1; $plays[$pn]['dest_artccs'][$a] = 1; } }
    $total_routes++;
}
fclose($handle);

$play_count = count($plays);
echo "Parsed: $total_routes routes, $play_count plays\n";
flush();

function normPlay($n) { return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $n)); }

/**
 * FAA National Playbook category lookup table.
 * Authoritative source: https://www.fly.faa.gov/playbook/
 * Each play name maps to one of 9 official FAA categories.
 */
function getFaaCategoryMap() {
    $map = [];

    // --- Airports (96 plays) ---
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
    foreach ($airports as $p) $map[$p] = 'Airports';

    // --- East to West Transcon (53 plays) ---
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
    foreach ($e2w as $p) $map[$p] = 'East to West Transcon';

    // --- Equipment (4 plays) ---
    $equipment = ['GTK MBPV','GTK MDCS','GTK ZSU NB','GTK ZSU SB'];
    foreach ($equipment as $p) $map[$p] = 'Equipment';

    // --- Regional Routes (106 plays) ---
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
    foreach ($regional as $p) $map[$p] = 'Regional Routes';

    // --- Snowbird (22 plays) ---
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
    foreach ($snowbird as $p) $map[$p] = 'Snowbird';

    // --- Space Ops (4 plays) ---
    $spaceOps = ['CAPE LAUNCH 1','CAPE LAUNCH 2A','CAPE LAUNCH 2B','CAPE LAUNCH NB'];
    foreach ($spaceOps as $p) $map[$p] = 'Space Ops';

    // --- Special Ops (13 plays) ---
    $specialOps = [
        'BCT AREA NO GAWKS',
        'GA LIGHT JETS TO MIA AND SATS','GA PROPS TO MIA AND SATS','GA TO BCT AREA',
        'GA TO EWR AND SATS','GA TO MIA AND SATS VIA MOGAE','GA TO SUA',
        'MIA AND SATS VIA DEEP WATER','MIA VIA MOGAE',
        'PHL TO ZBW CZU ZEU','SOUTH TO ZBW','WEST TO ZBW','ZBW CZU TO ZDC',
    ];
    foreach ($specialOps as $p) $map[$p] = 'Special Ops';

    // --- SUA Activity (11 plays) ---
    $sua = [
        'DC METROS TO ZBW','SENTRY MAYHEM SOUTHBOUND',
        'STAVE 1','STAVE 2 FLORIDA ARVLS',
        'WATRS ROUTES TO AVOID SENTRY',
        'YANKEE DC METS TO ZBW','YANKEE NO GA VIA LENDY','YANKEE PHL DEPT TO ZBW CZU',
        'YANKEE PHL DEPT TO ZEU','YANKEE SOUTH TO ZBW','YANKEE WEST TO BDL BED BVY LWM',
    ];
    foreach ($sua as $p) $map[$p] = 'SUA Activity';

    // --- West to East Transcon (46 plays) ---
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
    foreach ($w2e as $p) $map[$p] = 'West to East Transcon';

    return $map;
}

function deriveCategory($playName, $destArtccs) {
    static $faaMap = null;
    if ($faaMap === null) $faaMap = getFaaCategoryMap();

    // Direct lookup
    if (isset($faaMap[$playName])) return $faaMap[$playName];

    // Strip _old_XXXX suffix and try again
    $baseName = preg_replace('/_old_\d+$/', '', $playName);
    if ($baseName !== $playName && isset($faaMap[$baseName])) return $faaMap[$baseName];

    return null;
}

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
        $cat = deriveCategory($pn, array_keys($pd['dest_artccs']));

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
