<?php
/**
 * VATSWIM API v1 — Per-Play Facility Route Counts
 *
 * Returns aggregated facility traversal counts and sector coverage
 * for a single playbook play. Lightweight alternative to fetching
 * full play data with routes.
 *
 * GET /api/swim/v1/playbook/facility-counts?play_id=123
 *
 * @version 1.0.0
 * @since 2026-03-24
 */

// Bootstrap: auth.php handles config + connect with PERTI_SWIM_ONLY optimization.
// PostGIS get_conn_gis() is always available via lazy loading.
require_once __DIR__ . '/../auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    SwimResponse::handlePreflight();
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    SwimResponse::error('Method not allowed. Use GET.', 405, 'METHOD_NOT_ALLOWED');
}

$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

$conn_swim_api = get_conn_swim();
if (!$conn_swim_api) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$play_id = swim_get_int_param('play_id', 0, 0, 999999999);
if ($play_id <= 0) {
    SwimResponse::error('play_id parameter is required and must be a positive integer', 400, 'MISSING_PARAM');
}

// Verify play exists
$check = sqlsrv_query($conn_swim_api,
    "SELECT play_id, play_name, route_count FROM dbo.swim_playbook_plays WHERE play_id = ?",
    [$play_id]
);
if ($check === false) {
    SwimResponse::error('Database error', 500, 'DB_ERROR');
}
$play_row = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($check);

if (!$play_row) {
    SwimResponse::error('Play not found', 404, 'NOT_FOUND');
}

// Aggregate facility counts using STRING_SPLIT with per-route deduplication
$sql = "
SELECT facility_type, code, COUNT(*) AS route_count
FROM (
    SELECT 'artccs' AS facility_type, r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_artccs, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'tracons', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_tracons, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'sectors_low', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_low, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'sectors_high', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_high, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'sectors_superhigh', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_superhigh, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))
) deduped
GROUP BY facility_type, code
ORDER BY facility_type, route_count DESC
";

$params = [$play_id, $play_id, $play_id, $play_id, $play_id];
$stmt = sqlsrv_query($conn_swim_api, $sql, $params);
if ($stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$result = [
    'artccs' => [], 'tracons' => [], 'sectors_low' => [],
    'sectors_high' => [], 'sectors_superhigh' => []
];
$all_sector_codes = [];
$swim_to_type = ['sectors_low' => 'LOW', 'sectors_high' => 'HIGH', 'sectors_superhigh' => 'SUPERHIGH'];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $type = $row['facility_type'];
    if (isset($result[$type])) {
        $result[$type][] = ['code' => $row['code'], 'route_count' => (int)$row['route_count']];
        if (isset($swim_to_type[$type])) {
            $all_sector_codes[$row['code']] = $swim_to_type[$type];
        }
    }
}
sqlsrv_free_stmt($stmt);

// Routes with any traversal data
$count_sql = "SELECT COUNT(DISTINCT route_id) AS cnt FROM dbo.swim_playbook_routes
              WHERE play_id = ? AND (
                  ISNULL(traversed_artccs,'') != '' OR
                  ISNULL(traversed_tracons,'') != '' OR
                  ISNULL(traversed_sectors_low,'') != '' OR
                  ISNULL(traversed_sectors_high,'') != '' OR
                  ISNULL(traversed_sectors_superhigh,'') != ''
              )";
$count_stmt = sqlsrv_query($conn_swim_api, $count_sql, [$play_id]);
$with_trav = 0;
if ($count_stmt !== false) {
    $cr = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
    $with_trav = (int)($cr['cnt'] ?? 0);
    sqlsrv_free_stmt($count_stmt);
}

$result['total_routes'] = (int)($play_row['route_count'] ?? 0);
$result['routes_with_traversal'] = $with_trav;

// Sector coverage via PostGIS
if (!empty($all_sector_codes)) {
    $conn_gis = get_conn_gis();
    if ($conn_gis) {
        try {
            $sector_codes_list = array_keys($all_sector_codes);
            $ph = implode(',', array_fill(0, count($sector_codes_list), '?'));
            $stmt_gis = $conn_gis->prepare(
                "SELECT sector_code, parent_artcc, sector_type FROM sector_boundaries WHERE sector_code IN ($ph)"
            );
            $stmt_gis->execute($sector_codes_list);
            $sector_artcc_map = [];
            $relevant_artccs = [];
            foreach ($stmt_gis->fetchAll(PDO::FETCH_ASSOC) as $si) {
                $sector_artcc_map[$si['sector_code']] = $si['parent_artcc'];
                $relevant_artccs[$si['parent_artcc']] = true;
            }

            if (!empty($relevant_artccs)) {
                $artcc_list = array_keys($relevant_artccs);
                $ph2 = implode(',', array_fill(0, count($artcc_list), '?'));
                $stmt_t = $conn_gis->prepare(
                    "SELECT parent_artcc, sector_type, COUNT(*) AS total FROM sector_boundaries WHERE parent_artcc IN ($ph2) GROUP BY parent_artcc, sector_type"
                );
                $stmt_t->execute($artcc_list);
                $sector_totals = [];
                foreach ($stmt_t->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $sector_totals[$t['parent_artcc']][$t['sector_type']] = (int)$t['total'];
                }

                $play_sectors = [];
                foreach ($all_sector_codes as $code => $stype) {
                    $artcc = $sector_artcc_map[$code] ?? null;
                    if ($artcc) $play_sectors[$artcc][$stype][$code] = true;
                }

                $coverage_data = [];
                foreach ($play_sectors as $artcc => $types) {
                    foreach ($types as $stype => $codes_set) {
                        $tc = count($codes_set);
                        $tot = $sector_totals[$artcc][$stype] ?? 0;
                        $coverage_data[$artcc][$stype] = [
                            'traversed' => $tc,
                            'total' => $tot,
                            'pct' => $tot > 0 ? round($tc / $tot * 100, 1) : 0,
                        ];
                    }
                }
                $result['coverage'] = [
                    'sector_totals' => $sector_totals,
                    'play_sectors' => $coverage_data,
                    'sector_artcc_map' => $sector_artcc_map,
                ];
            }
        } catch (Exception $e) {
            // PostGIS unavailable — skip coverage
        }
    }
}

SwimResponse::success([
    'play_id' => (int)$play_row['play_id'],
    'play_name' => $play_row['play_name'],
    'facility_counts' => $result,
]);
