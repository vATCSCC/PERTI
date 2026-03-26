<?php
/**
 * Historical Route Detail API
 *
 * GET /api/data/route-history/detail.php?route_dim_id=123
 * Returns route group detail with variants and statistics.
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');

$routeDimId = (int)(get_input('route_dim_id') ?? 0);
if ($routeDimId <= 0) {
    echo json_encode(['success' => false, 'error' => 'route_dim_id required']);
    exit;
}

// Route info
$stmt = $conn_pdo->prepare("
    SELECT dr.route_dim_id, dr.normalized_route, dr.sample_raw_route,
           dr.waypoint_count, dr.first_seen, dr.last_seen,
           COUNT(f.fact_id) as flight_count,
           COUNT(DISTINCT f.raw_route) as variant_count
    FROM dim_route dr
    LEFT JOIN route_history_facts f ON f.route_dim_id = dr.route_dim_id
    WHERE dr.route_dim_id = ?
    GROUP BY dr.route_dim_id
");
$stmt->execute([$routeDimId]);
$route = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$route) {
    echo json_encode(['success' => false, 'error' => 'Route not found']);
    exit;
}

// Variants (top 50 raw routes by count)
$vStmt = $conn_pdo->prepare("
    SELECT raw_route, COUNT(*) as cnt,
           MAX(dtm.flight_date) as last_filed
    FROM route_history_facts f
    JOIN dim_time dtm ON dtm.time_dim_id = f.time_dim_id
    WHERE f.route_dim_id = ?
    GROUP BY raw_route
    ORDER BY cnt DESC
    LIMIT 50
");
$vStmt->execute([$routeDimId]);
$variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

// Aircraft mix (top 20)
$acStmt = $conn_pdo->prepare("
    SELECT dat.icao_code, dat.manufacturer, dat.model, COUNT(*) as cnt
    FROM route_history_facts f
    JOIN dim_aircraft_type dat ON dat.aircraft_dim_id = f.aircraft_dim_id
    WHERE f.route_dim_id = ?
    GROUP BY dat.icao_code, dat.manufacturer, dat.model
    ORDER BY cnt DESC
    LIMIT 20
");
$acStmt->execute([$routeDimId]);
$aircraftMix = $acStmt->fetchAll(PDO::FETCH_ASSOC);

// Airline mix (top 20)
$alStmt = $conn_pdo->prepare("
    SELECT dop.airline_icao, dop.airline_name, COUNT(*) as cnt
    FROM route_history_facts f
    JOIN dim_operator dop ON dop.operator_dim_id = f.operator_dim_id
    WHERE f.route_dim_id = ?
    GROUP BY dop.airline_icao, dop.airline_name
    ORDER BY cnt DESC
    LIMIT 20
");
$alStmt->execute([$routeDimId]);
$airlineMix = $alStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly trend
$mtStmt = $conn_pdo->prepare("
    SELECT CONCAT(dtm.year_val, '-', LPAD(dtm.month_val, 2, '0')) as month,
           COUNT(*) as cnt
    FROM route_history_facts f
    JOIN dim_time dtm ON dtm.time_dim_id = f.time_dim_id
    WHERE f.route_dim_id = ?
    GROUP BY dtm.year_val, dtm.month_val
    ORDER BY dtm.year_val, dtm.month_val
");
$mtStmt->execute([$routeDimId]);
$monthlyTrend = $mtStmt->fetchAll(PDO::FETCH_ASSOC);

// Altitude distribution
$altStmt = $conn_pdo->prepare("
    SELECT altitude_ft, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND altitude_ft IS NOT NULL
    GROUP BY altitude_ft
    ORDER BY cnt DESC
    LIMIT 15
");
$altStmt->execute([$routeDimId]);
$altDist = $altStmt->fetchAll(PDO::FETCH_ASSOC);

// Departure fix distribution (from dfix column, fallback to first route token)
$depFixStmt = $conn_pdo->prepare("
    SELECT COALESCE(NULLIF(dfix, ''), SUBSTRING_INDEX(raw_route, ' ', 1)) as fix_name,
           COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND (dfix IS NOT NULL OR (raw_route IS NOT NULL AND raw_route != ''))
    GROUP BY fix_name
    HAVING fix_name IS NOT NULL AND fix_name != ''
    ORDER BY cnt DESC
    LIMIT 15
");
$depFixStmt->execute([$routeDimId]);
$depFixDist = $depFixStmt->fetchAll(PDO::FETCH_ASSOC);

// Arrival fix distribution (from afix column, fallback to last route token)
$arrFixStmt = $conn_pdo->prepare("
    SELECT COALESCE(NULLIF(afix, ''), SUBSTRING_INDEX(raw_route, ' ', -1)) as fix_name,
           COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND (afix IS NOT NULL OR (raw_route IS NOT NULL AND raw_route != ''))
    GROUP BY fix_name
    HAVING fix_name IS NOT NULL AND fix_name != ''
    ORDER BY cnt DESC
    LIMIT 15
");
$arrFixStmt->execute([$routeDimId]);
$arrFixDist = $arrFixStmt->fetchAll(PDO::FETCH_ASSOC);

// SID/DP distribution
$dpStmt = $conn_pdo->prepare("
    SELECT dp_name, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND dp_name IS NOT NULL AND dp_name != ''
    GROUP BY dp_name
    ORDER BY cnt DESC
    LIMIT 15
");
$dpStmt->execute([$routeDimId]);
$dpDist = $dpStmt->fetchAll(PDO::FETCH_ASSOC);

// STAR distribution
$starStmt = $conn_pdo->prepare("
    SELECT star_name, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND star_name IS NOT NULL AND star_name != ''
    GROUP BY star_name
    ORDER BY cnt DESC
    LIMIT 15
");
$starStmt->execute([$routeDimId]);
$starDist = $starStmt->fetchAll(PDO::FETCH_ASSOC);

// Departure runway distribution
$depRwyStmt = $conn_pdo->prepare("
    SELECT dep_rwy, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND dep_rwy IS NOT NULL AND dep_rwy != ''
    GROUP BY dep_rwy
    ORDER BY cnt DESC
    LIMIT 15
");
$depRwyStmt->execute([$routeDimId]);
$depRwyDist = $depRwyStmt->fetchAll(PDO::FETCH_ASSOC);

// Arrival runway distribution
$arrRwyStmt = $conn_pdo->prepare("
    SELECT arr_rwy, COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND arr_rwy IS NOT NULL AND arr_rwy != ''
    GROUP BY arr_rwy
    ORDER BY cnt DESC
    LIMIT 15
");
$arrRwyStmt->execute([$routeDimId]);
$arrRwyDist = $arrRwyStmt->fetchAll(PDO::FETCH_ASSOC);

// Callsign distribution grouped by airline prefix
// Groups like UAL, DAL, AAL with expandable per-callsign detail
$csPrefixStmt = $conn_pdo->prepare("
    SELECT
        CASE WHEN callsign REGEXP '^[A-Z]{2,3}[0-9]'
             THEN REGEXP_SUBSTR(callsign, '^[A-Z]{2,3}')
             ELSE callsign
        END as prefix,
        COUNT(*) as cnt
    FROM route_history_facts
    WHERE route_dim_id = ? AND callsign IS NOT NULL AND callsign != ''
    GROUP BY prefix
    ORDER BY cnt DESC
    LIMIT 20
");
$csPrefixStmt->execute([$routeDimId]);
$csPrefixes = $csPrefixStmt->fetchAll(PDO::FETCH_ASSOC);

// For each prefix, get top callsigns
$callsignByAirline = [];
foreach ($csPrefixes as $pf) {
    $csDetailStmt = $conn_pdo->prepare("
        SELECT callsign as cs, COUNT(*) as cnt
        FROM route_history_facts
        WHERE route_dim_id = ?
          AND callsign IS NOT NULL AND callsign != ''
          AND callsign LIKE ?
        GROUP BY callsign
        ORDER BY cnt DESC
        LIMIT 15
    ");
    $likePattern = $pf['prefix'] . '%';
    $csDetailStmt->execute([$routeDimId, $likePattern]);
    $callsignByAirline[] = [
        'prefix' => $pf['prefix'],
        'cnt' => (int)$pf['cnt'],
        'callsigns' => $csDetailStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

echo json_encode([
    'success'  => true,
    'route'    => $route,
    'variants' => $variants,
    'stats'    => [
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
