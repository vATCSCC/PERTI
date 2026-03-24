<?php
/**
 * Historical Route Stats API
 *
 * GET /api/data/route-history/stats.php
 * Returns aggregate summary for currently applied filters.
 * Same filter parameters as search.php.
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');

// ── Parse parameters ──
$orig      = array_filter(array_map('trim', explode(',', get_input('orig') ?? '')));
$dest      = array_filter(array_map('trim', explode(',', get_input('dest') ?? '')));
$origMode  = get_input('orig_mode') ?? 'airport';
$destMode  = get_input('dest_mode') ?? 'airport';
$aircraft  = array_filter(array_map('trim', explode(',', get_input('aircraft') ?? '')));
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

// ── Guard: at least one filter required ──
$hasFilter = !empty($orig) || !empty($dest) || !empty($aircraft) || !empty($manufacturer)
    || !empty($weight) || !empty($wake) || !empty($engine) || !empty($airline)
    || $callsign !== '' || !empty($opGroup) || $dateFrom || $dateTo
    || !empty($months) || !empty($dows) || $hourMin !== null || $hourMax !== null
    || !empty($seasons) || !empty($years);

if (!$hasFilter) {
    echo json_encode(['success' => false, 'error' => 'At least one filter is required']);
    exit;
}

// ── Build query ──
$where = [];
$params = [];
$joins = '';

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

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Aggregate stats query ──
$sql = "
    SELECT
        COUNT(*) as total_flights,
        COUNT(DISTINCT f.route_dim_id) as total_routes,
        ROUND(AVG(f.gcd_nm), 1) as avg_distance_nm,
        ROUND(AVG(f.ete_minutes)) as avg_ete_minutes
    FROM route_history_facts f
    $joins
    $whereClause
";

$stmt = $conn_pdo->prepare($sql);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Min/max date query ──
$dateSql = "
    SELECT
        MIN(dtm2.flight_date) as min_date,
        MAX(dtm2.flight_date) as max_date
    FROM route_history_facts f
    JOIN dim_time dtm2 ON dtm2.time_dim_id = f.time_dim_id
    $joins
    $whereClause
";

$dateStmt = $conn_pdo->prepare($dateSql);
$dateStmt->execute($params);
$dates = $dateStmt->fetch(PDO::FETCH_ASSOC);

// ── Top aircraft query ──
$aircraftSql = "
    SELECT dat2.icao_code
    FROM route_history_facts f
    JOIN dim_aircraft_type dat2 ON dat2.aircraft_dim_id = f.aircraft_dim_id
    $joins
    $whereClause
    GROUP BY dat2.icao_code
    ORDER BY COUNT(*) DESC
    LIMIT 1
";

$aircraftStmt = $conn_pdo->prepare($aircraftSql);
$aircraftStmt->execute($params);
$topAircraft = $aircraftStmt->fetchColumn();

// ── Top airline query ──
$airlineSql = "
    SELECT dop2.airline_icao
    FROM route_history_facts f
    JOIN dim_operator dop2 ON dop2.operator_dim_id = f.operator_dim_id
    $joins
    $whereClause
    GROUP BY dop2.airline_icao
    ORDER BY COUNT(*) DESC
    LIMIT 1
";

$airlineStmt = $conn_pdo->prepare($airlineSql);
$airlineStmt->execute($params);
$topAirline = $airlineStmt->fetchColumn();

// ── Return response ──
echo json_encode([
    'success' => true,
    'total_flights' => (int)$stats['total_flights'],
    'total_routes' => (int)$stats['total_routes'],
    'avg_distance_nm' => (float)$stats['avg_distance_nm'],
    'avg_ete_minutes' => (int)$stats['avg_ete_minutes'],
    'min_date' => $dates['min_date'],
    'max_date' => $dates['max_date'],
    'top_aircraft' => $topAircraft ?: null,
    'top_airline' => $topAirline ?: null
], JSON_UNESCAPED_UNICODE);
