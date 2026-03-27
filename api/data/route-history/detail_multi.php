<?php
/**
 * Historical Route Multi-Detail API
 *
 * GET /api/data/route-history/detail_multi.php?route_dim_ids=1,2,3
 * Returns aggregated detail statistics across multiple route groups.
 * Used by the Group & Compare dialog to show the full data suite.
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');

$idsRaw = get_input('route_dim_ids') ?? '';
if (empty($idsRaw)) {
    echo json_encode(['success' => false, 'error' => 'route_dim_ids required']);
    exit;
}

// Parse and validate IDs (max 6)
$ids = array_filter(array_map('intval', explode(',', $idsRaw)), function($id) {
    return $id > 0;
});
$ids = array_values(array_unique($ids));

if (count($ids) === 0) {
    echo json_encode(['success' => false, 'error' => 'No valid route_dim_ids']);
    exit;
}
if (count($ids) > 6) {
    $ids = array_slice($ids, 0, 6);
}

// Build placeholder string for prepared statements
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Aircraft mix (top 20 across all selected routes)
$acStmt = $conn_pdo->prepare("
    SELECT dat.icao_code, dat.manufacturer, dat.model, COUNT(*) as cnt
    FROM route_history_facts f
    JOIN dim_aircraft_type dat ON dat.aircraft_dim_id = f.aircraft_dim_id
    WHERE f.route_dim_id IN ($placeholders)
    GROUP BY dat.icao_code, dat.manufacturer, dat.model
    ORDER BY cnt DESC
    LIMIT 20
");
$acStmt->execute($ids);
$aircraftMix = $acStmt->fetchAll(PDO::FETCH_ASSOC);

// Airline mix (top 20)
$alStmt = $conn_pdo->prepare("
    SELECT dop.airline_icao, dop.airline_name, COUNT(*) as cnt
    FROM route_history_facts f
    JOIN dim_operator dop ON dop.operator_dim_id = f.operator_dim_id
    WHERE f.route_dim_id IN ($placeholders)
    GROUP BY dop.airline_icao, dop.airline_name
    ORDER BY cnt DESC
    LIMIT 20
");
$alStmt->execute($ids);
$airlineMix = $alStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly trend
$mtStmt = $conn_pdo->prepare("
    SELECT CONCAT(dtm.year_val, '-', LPAD(dtm.month_val, 2, '0')) as month,
           COUNT(*) as cnt
    FROM route_history_facts f
    JOIN dim_time dtm ON dtm.time_dim_id = f.time_dim_id
    WHERE f.route_dim_id IN ($placeholders)
    GROUP BY dtm.year_val, dtm.month_val
    ORDER BY dtm.year_val, dtm.month_val
");
$mtStmt->execute($ids);
$monthlyTrend = $mtStmt->fetchAll(PDO::FETCH_ASSOC);

// Altitude distribution
$altStmt = $conn_pdo->prepare("
    SELECT altitude_ft, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND altitude_ft IS NOT NULL
    GROUP BY altitude_ft
    ORDER BY cnt DESC
    LIMIT 15
");
$altStmt->execute($ids);
$altDist = $altStmt->fetchAll(PDO::FETCH_ASSOC);

// Departure fix distribution
$depFixStmt = $conn_pdo->prepare("
    SELECT COALESCE(NULLIF(dfix, ''), SUBSTRING_INDEX(raw_route, ' ', 1)) as fix_name,
           COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND (dfix IS NOT NULL OR (raw_route IS NOT NULL AND raw_route != ''))
    GROUP BY fix_name
    HAVING fix_name IS NOT NULL AND fix_name != ''
    ORDER BY cnt DESC
    LIMIT 15
");
$depFixStmt->execute($ids);
$depFixDist = $depFixStmt->fetchAll(PDO::FETCH_ASSOC);

// Arrival fix distribution
$arrFixStmt = $conn_pdo->prepare("
    SELECT COALESCE(NULLIF(afix, ''), SUBSTRING_INDEX(raw_route, ' ', -1)) as fix_name,
           COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND (afix IS NOT NULL OR (raw_route IS NOT NULL AND raw_route != ''))
    GROUP BY fix_name
    HAVING fix_name IS NOT NULL AND fix_name != ''
    ORDER BY cnt DESC
    LIMIT 15
");
$arrFixStmt->execute($ids);
$arrFixDist = $arrFixStmt->fetchAll(PDO::FETCH_ASSOC);

// SID/DP distribution
$dpStmt = $conn_pdo->prepare("
    SELECT dp_name, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND dp_name IS NOT NULL AND dp_name != ''
    GROUP BY dp_name
    ORDER BY cnt DESC
    LIMIT 15
");
$dpStmt->execute($ids);
$dpDist = $dpStmt->fetchAll(PDO::FETCH_ASSOC);

// STAR distribution
$starStmt = $conn_pdo->prepare("
    SELECT star_name, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND star_name IS NOT NULL AND star_name != ''
    GROUP BY star_name
    ORDER BY cnt DESC
    LIMIT 15
");
$starStmt->execute($ids);
$starDist = $starStmt->fetchAll(PDO::FETCH_ASSOC);

// Departure runway distribution
$depRwyStmt = $conn_pdo->prepare("
    SELECT dep_rwy, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND dep_rwy IS NOT NULL AND dep_rwy != ''
    GROUP BY dep_rwy
    ORDER BY cnt DESC
    LIMIT 15
");
$depRwyStmt->execute($ids);
$depRwyDist = $depRwyStmt->fetchAll(PDO::FETCH_ASSOC);

// Arrival runway distribution
$arrRwyStmt = $conn_pdo->prepare("
    SELECT arr_rwy, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND arr_rwy IS NOT NULL AND arr_rwy != ''
    GROUP BY arr_rwy
    ORDER BY cnt DESC
    LIMIT 15
");
$arrRwyStmt->execute($ids);
$arrRwyDist = $arrRwyStmt->fetchAll(PDO::FETCH_ASSOC);

// Callsign distribution grouped by airline prefix
$csPrefixStmt = $conn_pdo->prepare("
    SELECT
        CASE WHEN callsign REGEXP '^[A-Z]{2,3}[0-9]'
             THEN REGEXP_SUBSTR(callsign, '^[A-Z]{2,3}')
             ELSE callsign
        END as prefix,
        COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id IN ($placeholders) AND callsign IS NOT NULL AND callsign != ''
    GROUP BY prefix
    ORDER BY cnt DESC
    LIMIT 20
");
$csPrefixStmt->execute($ids);
$csPrefixes = $csPrefixStmt->fetchAll(PDO::FETCH_ASSOC);

// For each prefix, get top callsigns
$callsignByAirline = [];
foreach ($csPrefixes as $pf) {
    $csDetailStmt = $conn_pdo->prepare("
        SELECT callsign as cs, COUNT(*) as cnt
        FROM route_history_facts
        WHERE route_dim_id IN ($placeholders)
          AND callsign IS NOT NULL AND callsign != ''
          AND callsign LIKE ?
        GROUP BY callsign
        ORDER BY cnt DESC
        LIMIT 15
    ");
    $likePattern = $pf['prefix'] . '%';
    $params = array_merge($ids, [$likePattern]);
    $csDetailStmt->execute($params);
    $callsignByAirline[] = [
        'prefix' => $pf['prefix'],
        'cnt' => (int)$pf['cnt'],
        'callsigns' => $csDetailStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

echo json_encode([
    'success' => true,
    'stats'   => [
        'aircraft_mix'          => $aircraftMix,
        'airline_mix'           => $airlineMix,
        'monthly_trend'         => $monthlyTrend,
        'altitude_distribution' => $altDist,
        'dep_fix_distribution'  => $depFixDist,
        'arr_fix_distribution'  => $arrFixDist,
        'dp_distribution'       => $dpDist,
        'star_distribution'     => $starDist,
        'dep_rwy_distribution'  => $depRwyDist,
        'arr_rwy_distribution'  => $arrRwyDist,
        'callsign_by_airline'   => $callsignByAirline,
    ],
], JSON_UNESCAPED_UNICODE);
