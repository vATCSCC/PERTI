<?php
/**
 * Historical Route Export API
 *
 * GET /api/data/route-history/export.php
 * Exports route data in various formats (CSV, clipboard, GeoJSON).
 * Uses same filter parameters as search.php.
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

// ── Parse parameters ──
$format = get_input('format') ?? 'csv';
$routeDimId = get_input('route_dim_id') ? (int)get_input('route_dim_id') : null;

// Location filters
$orig      = array_filter(array_map('trim', explode(',', get_input('orig') ?? '')));
$dest      = array_filter(array_map('trim', explode(',', get_input('dest') ?? '')));
$origMode  = get_input('orig_mode') ?? 'airport';
$destMode  = get_input('dest_mode') ?? 'airport';

// Aircraft filters
$aircraft  = array_filter(array_map('trim', explode(',', get_input('aircraft') ?? '')));
$manufacturer = array_filter(array_map('trim', explode(',', get_input('manufacturer') ?? '')));
$weight    = array_filter(array_map('trim', explode(',', get_input('weight') ?? '')));
$wake      = array_filter(array_map('trim', explode(',', get_input('wake') ?? '')));
$engine    = array_filter(array_map('trim', explode(',', get_input('engine') ?? '')));

// Operator filters
$airline   = array_filter(array_map('trim', explode(',', get_input('airline') ?? '')));
$callsign  = trim(get_input('callsign') ?? '');
$opGroup   = array_filter(array_map('trim', explode(',', get_input('op_group') ?? '')));

// Time filters
$dateFrom  = get_input('date_from');
$dateTo    = get_input('date_to');
$months    = array_filter(array_map('intval', explode(',', get_input('month') ?? '')));
$dows      = array_filter(array_map('intval', explode(',', get_input('dow') ?? '')));
$hourMin   = get_input('hour_min') !== '' ? (int)get_input('hour_min') : null;
$hourMax   = get_input('hour_max') !== '' ? (int)get_input('hour_max') : null;
$seasons   = array_filter(array_map('trim', explode(',', get_input('season') ?? '')));
$years     = array_filter(array_map('intval', explode(',', get_input('year') ?? '')));

// ── Build query ──
$where = [];
$params = [];
$joins = '';

// If route_dim_id is provided, export that specific route group
if ($routeDimId) {
    $where[] = "f.route_dim_id = ?";
    $params[] = $routeDimId;
} else {
    // Apply full search filters (same logic as search.php)

    // Location filters
    $origCol = match($origMode) {
        'tracon' => 'f.origin_tracon',
        'artcc'  => 'f.origin_artcc',
        default  => 'f.origin_icao',
    };
    $destCol = match($destMode) {
        'tracon' => 'f.dest_tracon',
        'artcc'  => 'f.dest_artcc',
        default  => 'f.dest_icao',
    };

    if (!empty($orig)) {
        $ph = [];
        foreach ($orig as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
        $where[] = "$origCol IN (" . implode(',', $ph) . ")";
    }
    if (!empty($dest)) {
        $ph = [];
        foreach ($dest as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
        $where[] = "$destCol IN (" . implode(',', $ph) . ")";
    }

    // Aircraft filters
    $needAircraftJoin = !empty($aircraft) || !empty($manufacturer) || !empty($weight) || !empty($wake) || !empty($engine);
    if ($needAircraftJoin) {
        $joins .= ' JOIN dim_aircraft_type dat ON dat.aircraft_dim_id = f.aircraft_dim_id';
        if (!empty($aircraft)) {
            $ph = [];
            foreach ($aircraft as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
            $where[] = "dat.icao_code IN (" . implode(',', $ph) . ")";
        }
        if (!empty($manufacturer)) {
            $ph = [];
            foreach ($manufacturer as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
            $where[] = "dat.manufacturer IN (" . implode(',', $ph) . ")";
        }
        if (!empty($weight)) {
            $ph = [];
            foreach ($weight as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
            $where[] = "dat.weight_class IN (" . implode(',', $ph) . ")";
        }
        if (!empty($wake)) {
            $ph = [];
            foreach ($wake as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
            $where[] = "dat.wake_category IN (" . implode(',', $ph) . ")";
        }
        if (!empty($engine)) {
            $ph = [];
            foreach ($engine as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
            $where[] = "dat.engine_type IN (" . implode(',', $ph) . ")";
        }
    }

    // Operator filters
    $needOperatorJoin = !empty($airline) || $callsign !== '' || !empty($opGroup);
    if ($needOperatorJoin) {
        $joins .= ' JOIN dim_operator dop ON dop.operator_dim_id = f.operator_dim_id';
        if (!empty($airline)) {
            $ph = [];
            foreach ($airline as $v) { $ph[] = '?'; $params[] = strtoupper($v); }
            $where[] = "dop.airline_icao IN (" . implode(',', $ph) . ")";
        }
        if ($callsign !== '') {
            $where[] = "dop.callsign_prefix = ?";
            $params[] = strtoupper(substr($callsign, 0, 3));
        }
        if (!empty($opGroup)) {
            $ph = [];
            foreach ($opGroup as $v) { $ph[] = '?'; $params[] = $v; }
            $where[] = "dop.operator_group IN (" . implode(',', $ph) . ")";
        }
    }

    // Time filters
    $needTimeJoin = $dateFrom || $dateTo || !empty($months) || !empty($dows)
        || $hourMin !== null || $hourMax !== null || !empty($seasons) || !empty($years);
    if ($needTimeJoin) {
        $joins .= ' JOIN dim_time dtm ON dtm.time_dim_id = f.time_dim_id';
        if ($dateFrom) { $where[] = "dtm.flight_date >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = "dtm.flight_date <= ?"; $params[] = $dateTo; }
        if (!empty($months)) {
            $ph = [];
            foreach ($months as $v) { $ph[] = '?'; $params[] = $v; }
            $where[] = "dtm.month_val IN (" . implode(',', $ph) . ")";
        }
        if (!empty($dows)) {
            $ph = [];
            foreach ($dows as $v) { $ph[] = '?'; $params[] = $v; }
            $where[] = "dtm.day_of_week IN (" . implode(',', $ph) . ")";
        }
        if ($hourMin !== null) { $where[] = "dtm.hour_utc >= ?"; $params[] = $hourMin; }
        if ($hourMax !== null) { $where[] = "dtm.hour_utc <= ?"; $params[] = $hourMax; }
        if (!empty($seasons)) {
            $ph = [];
            foreach ($seasons as $v) { $ph[] = '?'; $params[] = $v; }
            $where[] = "dtm.season IN (" . implode(',', $ph) . ")";
        }
        if (!empty($years)) {
            $ph = [];
            foreach ($years as $v) { $ph[] = '?'; $params[] = $v; }
            $where[] = "dtm.year_val IN (" . implode(',', $ph) . ")";
        }
    }

    // Partition pruning
    if (!empty($years) || ($dateFrom && $dateTo)) {
        $pmValues = [];
        if (!empty($years)) {
            foreach ($years as $y) {
                for ($m = 1; $m <= 12; $m++) {
                    $pmValues[] = $y * 100 + $m;
                }
            }
        }
        if ($dateFrom && $dateTo) {
            $startYm = (int)date('Ym', strtotime($dateFrom));
            $endYm   = (int)date('Ym', strtotime($dateTo));
            $cur = $startYm;
            while ($cur <= $endYm) {
                $pmValues[] = $cur;
                $m = $cur % 100;
                $y = (int)($cur / 100);
                $m++;
                if ($m > 12) { $m = 1; $y++; }
                $cur = $y * 100 + $m;
            }
        }
        if (!empty($pmValues)) {
            $pmValues = array_unique($pmValues);
            $ph = [];
            foreach ($pmValues as $v) { $ph[] = '?'; $params[] = $v; }
            $where[] = "f.partition_month IN (" . implode(',', $ph) . ")";
        }
    }
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Query data ──
if ($routeDimId) {
    // Export variants of a single route group
    $sql = "
        SELECT
            f.origin_icao,
            f.dest_icao,
            dr.normalized_route,
            f.raw_route,
            COUNT(*) as flight_count,
            ROUND(AVG(f.gcd_nm), 1) as avg_distance_nm,
            ROUND(AVG(f.ete_minutes)) as avg_ete_minutes,
            ROUND(AVG(f.altitude_ft)) as avg_altitude_ft,
            MIN(dtm2.flight_date) as first_filed,
            MAX(dtm2.flight_date) as last_filed
        FROM route_history_facts f
        JOIN dim_route dr ON dr.route_dim_id = f.route_dim_id
        JOIN dim_time dtm2 ON dtm2.time_dim_id = f.time_dim_id
        $whereClause
        GROUP BY f.origin_icao, f.dest_icao, dr.normalized_route, f.raw_route
        ORDER BY flight_count DESC
        LIMIT 1000
    ";
} else {
    // Export grouped routes from full search
    $limit = $format === 'geojson' ? 5000 : 10000;
    $sql = "
        SELECT
            f.origin_icao,
            f.dest_icao,
            dr.normalized_route,
            dr.sample_raw_route as raw_route,
            COUNT(*) as flight_count,
            ROUND(AVG(f.gcd_nm), 1) as avg_distance_nm,
            ROUND(AVG(f.ete_minutes)) as avg_ete_minutes,
            ROUND(AVG(f.altitude_ft)) as avg_altitude_ft,
            MIN(dr.first_seen) as first_filed,
            MAX(dr.last_seen) as last_filed
        FROM route_history_facts f
        JOIN dim_route dr ON dr.route_dim_id = f.route_dim_id
        $joins
        $whereClause
        GROUP BY f.origin_icao, f.dest_icao, f.route_dim_id, dr.normalized_route, dr.sample_raw_route
        ORDER BY flight_count DESC
        LIMIT $limit
    ";
}

$stmt = $conn_pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Output by format ──
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="routes_export_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['origin', 'destination', 'normalized_route', 'raw_route', 'flight_count', 'avg_distance_nm', 'avg_ete_minutes', 'avg_altitude', 'first_filed', 'last_filed']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['origin_icao'],
            $row['dest_icao'],
            $row['normalized_route'],
            $row['raw_route'],
            $row['flight_count'],
            $row['avg_distance_nm'],
            $row['avg_ete_minutes'],
            $row['avg_altitude_ft'],
            $row['first_filed'],
            $row['last_filed']
        ]);
    }
    fclose($output);

} elseif ($format === 'clipboard') {
    header('Content-Type: text/plain; charset=utf-8');

    // Tab-separated for easy paste into spreadsheets
    echo implode("\t", ['origin', 'destination', 'normalized_route', 'raw_route', 'flight_count', 'avg_distance_nm', 'avg_ete_minutes', 'avg_altitude', 'first_filed', 'last_filed']) . "\n";

    foreach ($rows as $row) {
        echo implode("\t", [
            $row['origin_icao'],
            $row['dest_icao'],
            $row['normalized_route'],
            $row['raw_route'],
            $row['flight_count'],
            $row['avg_distance_nm'],
            $row['avg_ete_minutes'],
            $row['avg_altitude_ft'],
            $row['first_filed'],
            $row['last_filed']
        ]) . "\n";
    }

} elseif ($format === 'geojson') {
    header('Content-Type: application/json; charset=utf-8');

    $features = [];
    foreach ($rows as $row) {
        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'origin' => $row['origin_icao'],
                'destination' => $row['dest_icao'],
                'normalized_route' => $row['normalized_route'],
                'raw_route' => $row['raw_route'],
                'flight_count' => (int)$row['flight_count'],
                'avg_distance_nm' => (float)$row['avg_distance_nm'],
                'avg_ete_minutes' => (int)$row['avg_ete_minutes'],
                'avg_altitude_ft' => (int)$row['avg_altitude_ft'],
                'first_filed' => $row['first_filed'],
                'last_filed' => $row['last_filed']
            ],
            'geometry' => null  // No coordinates in MySQL schema
        ];
    }

    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => $features
    ], JSON_UNESCAPED_UNICODE);

} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Invalid format. Use csv, clipboard, or geojson.']);
}
