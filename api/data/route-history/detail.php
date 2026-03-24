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

echo json_encode([
    'success'  => true,
    'route'    => $route,
    'variants' => $variants,
    'stats'    => [
        'aircraft_mix'         => $aircraftMix,
        'airline_mix'          => $airlineMix,
        'monthly_trend'        => $monthlyTrend,
        'altitude_distribution' => $altDist,
    ],
], JSON_UNESCAPED_UNICODE);
