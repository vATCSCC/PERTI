<?php
/**
 * Historical Route Search API
 *
 * GET /api/data/route-history/search.php
 * Returns grouped or raw routes matching filter criteria.
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
include("../../../load/aircraft_families.php");

header('Content-Type: application/json; charset=utf-8');

// ── Parse parameters ──
$orig      = array_filter(array_map('trim', explode(',', get_input('orig') ?? '')));
$dest      = array_filter(array_map('trim', explode(',', get_input('dest') ?? '')));
$origMode  = get_input('orig_mode') ?? 'airport';
$destMode  = get_input('dest_mode') ?? 'airport';
$aircraft  = array_filter(array_map('trim', explode(',', get_input('aircraft') ?? '')));
$families  = array_filter(array_map('trim', explode(',', get_input('family') ?? '')));
$manufacturer = array_filter(array_map('trim', explode(',', get_input('manufacturer') ?? '')));
$weight    = array_filter(array_map('trim', explode(',', get_input('weight') ?? '')));
$wake      = array_filter(array_map('trim', explode(',', get_input('wake') ?? '')));
$engine    = array_filter(array_map('trim', explode(',', get_input('engine') ?? '')));
$airline   = array_filter(array_map('trim', explode(',', get_input('airline') ?? '')));
$callsign  = trim(get_input('callsign') ?? '');
$opGroup   = array_filter(array_map('trim', explode(',', get_input('op_group') ?? '')));
$dateFrom  = get_input('date_from');
$dateTo    = get_input('date_to');
$months    = array_filter(array_map('intval', explode(',', get_input('month') ?? '')));
$dows      = array_filter(array_map('intval', explode(',', get_input('dow') ?? '')));
$hourMin   = get_input('hour_min') !== '' ? (int)get_input('hour_min') : null;
$hourMax   = get_input('hour_max') !== '' ? (int)get_input('hour_max') : null;
$seasons   = array_filter(array_map('trim', explode(',', get_input('season') ?? '')));
$years     = array_filter(array_map('intval', explode(',', get_input('year') ?? '')));
$page      = max(1, (int)(get_input('page') ?: 1));
$perPage   = min(200, max(1, (int)(get_input('per_page') ?: 50)));
$sort      = get_input('sort') ?: 'frequency';
$view      = get_input('view') ?: 'grouped';

// Expand family selections into ICAO codes, merge with explicit aircraft filter
if (!empty($families)) {
    foreach ($families as $fKey) {
        if (isset($AIRCRAFT_FAMILIES[$fKey])) {
            $aircraft = array_merge($aircraft, $AIRCRAFT_FAMILIES[$fKey]);
        }
    }
    $aircraft = array_unique($aircraft);
}

// ── Guard: at least one filter required ──
$hasFilter = !empty($orig) || !empty($dest) || !empty($aircraft) || !empty($manufacturer)
    || !empty($weight) || !empty($wake) || !empty($engine) || !empty($airline)
    || $callsign !== '' || !empty($opGroup) || $dateFrom || $dateTo
    || !empty($months) || !empty($dows) || $hourMin !== null || $hourMax !== null
    || !empty($seasons) || !empty($years) || !empty($families);

if (!$hasFilter) {
    echo json_encode(['success' => false, 'error' => 'At least one filter is required']);
    exit;
}

// ── Build query ──
$where = [];
$params = [];
$joins = '';
$paramIdx = 0;

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

// Aircraft filters (need JOIN to dim_aircraft_type)
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

// Operator filters (need JOIN to dim_operator)
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

// Time filters (need JOIN to dim_time)
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

// Partition pruning hint: if date range or year specified
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

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Grouped view ──
if ($view === 'grouped') {
    // Count query
    $countSql = "SELECT COUNT(DISTINCT CONCAT(f.route_dim_id, ':', f.origin_icao, ':', f.dest_icao)) as total_routes, COUNT(*) as total_flights
                 FROM route_history_facts f $joins $whereClause";
    $countStmt = $conn_pdo->prepare($countSql);
    $countStmt->execute($params);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    // Sort
    $orderBy = match($sort) {
        'distance'   => 'avg_distance_nm DESC',
        'ete'        => 'avg_ete_minutes ASC',
        'last_filed' => 'last_filed DESC',
        default      => 'flight_count DESC',
    };

    $offset = ($page - 1) * $perPage;
    $dataSql = "
        SELECT
            f.route_dim_id,
            dr.normalized_route,
            dr.sample_raw_route,
            f.origin_icao,
            f.dest_icao,
            COUNT(*) as flight_count,
            COUNT(DISTINCT f.raw_route) as variant_count,
            ROUND(AVG(f.gcd_nm), 1) as avg_distance_nm,
            ROUND(AVG(f.ete_minutes)) as avg_ete_minutes,
            ROUND(AVG(f.altitude_ft)) as avg_altitude_ft,
            MIN(dr.first_seen) as first_filed,
            MAX(dr.last_seen) as last_filed
        FROM route_history_facts f
        JOIN dim_route dr ON dr.route_dim_id = f.route_dim_id
        $joins
        $whereClause
        GROUP BY f.route_dim_id, f.origin_icao, f.dest_icao, dr.normalized_route, dr.sample_raw_route
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset
    ";
    $dataStmt = $conn_pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $routes = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // Add frequency_pct
    $totalFlights = (int)$counts['total_flights'];
    foreach ($routes as &$r) {
        $r['frequency_pct'] = $totalFlights > 0
            ? round((int)$r['flight_count'] / $totalFlights * 100, 1)
            : 0;
    }

    echo json_encode([
        'success'      => true,
        'total_routes'  => (int)$counts['total_routes'],
        'total_flights' => $totalFlights,
        'page'          => $page,
        'per_page'      => $perPage,
        'routes'        => $routes,
    ], JSON_UNESCAPED_UNICODE);

} else {
    // Raw view — each row is a distinct raw_route
    $countSql = "SELECT COUNT(DISTINCT f.raw_route) as total_routes, COUNT(*) as total_flights
                 FROM route_history_facts f $joins $whereClause";
    $countStmt = $conn_pdo->prepare($countSql);
    $countStmt->execute($params);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    $offset = ($page - 1) * $perPage;
    $dataSql = "
        SELECT
            f.raw_route,
            f.route_dim_id,
            f.origin_icao,
            f.dest_icao,
            COUNT(*) as flight_count,
            ROUND(AVG(f.gcd_nm), 1) as avg_distance_nm,
            ROUND(AVG(f.ete_minutes)) as avg_ete_minutes,
            ROUND(AVG(f.altitude_ft)) as avg_altitude_ft,
            MIN(dtm2.flight_date) as first_filed,
            MAX(dtm2.flight_date) as last_filed
        FROM route_history_facts f
        JOIN dim_time dtm2 ON dtm2.time_dim_id = f.time_dim_id
        $joins
        $whereClause
        GROUP BY f.raw_route, f.route_dim_id, f.origin_icao, f.dest_icao
        ORDER BY flight_count DESC
        LIMIT $perPage OFFSET $offset
    ";
    $dataStmt = $conn_pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $routes = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalFlights = (int)$counts['total_flights'];
    foreach ($routes as &$r) {
        $r['frequency_pct'] = $totalFlights > 0
            ? round((int)$r['flight_count'] / $totalFlights * 100, 1)
            : 0;
    }

    echo json_encode([
        'success'      => true,
        'total_routes'  => (int)$counts['total_routes'],
        'total_flights' => $totalFlights,
        'page'          => $page,
        'per_page'      => $perPage,
        'routes'        => $routes,
    ], JSON_UNESCAPED_UNICODE);
}
