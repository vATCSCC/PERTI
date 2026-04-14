<?php
/**
 * VATSWIM API v1 - AIRAC Cycle Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airac/current              - Current AIRAC cycle metadata
 *   GET /reference/airac/changelog?cycle=2603 - Changes in a specific cycle
 *   GET /reference/airac/superseded?type=procedure - List superseded items
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airac/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$format_options = [
    'root' => 'swim_airac',
    'item' => 'airac',
    'name' => 'VATSWIM AIRAC Reference',
    'filename' => 'swim_airac_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'cycle' => swim_get_param('cycle'),
    'type' => swim_get_param('type'),
    'airport' => swim_get_param('airport'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_airac', $cache_params, $format, $format_options)) {
    exit;
}

switch ($sub) {
    case 'current':
        handleCurrentCycle($format, $cache_params, $format_options);
        break;
    case 'changelog':
        handleChangelog($format, $cache_params, $format_options);
        break;
    case 'superseded':
        handleSuperseded($format, $cache_params, $format_options);
        break;
    default:
        SwimResponse::error("Unknown AIRAC sub-resource: $sub. Use 'current', 'changelog', or 'superseded'.", 400, 'INVALID_RESOURCE');
}

function handleCurrentCycle($format, $cache_params, $format_options) {
    // AIRAC cycles follow a fixed 28-day schedule starting from a known epoch
    // Epoch: AIRAC 2301 effective 2023-01-26
    $epoch = new DateTime('2023-01-26', new DateTimeZone('UTC'));
    $now = new DateTime('now', new DateTimeZone('UTC'));

    $diff_days = (int)$now->diff($epoch)->days;
    $cycle_num = intdiv($diff_days, 28);
    $days_offset = $cycle_num * 28;
    $current_start = clone $epoch;
    $current_start->modify("+{$days_offset} days");

    $next_start = clone $current_start;
    $next_start->modify('+28 days');

    $days_remaining = (int)$now->diff($next_start)->days;

    // Compute cycle code: YYMM format
    $year_short = (int)$current_start->format('y');
    $cycle_in_year = intdiv((int)$current_start->diff(new DateTime($current_start->format('Y') . '-01-01'))->days, 28) + 1;
    $cycle_code = sprintf('%02d%02d', $year_short, $cycle_in_year);

    $next_year_short = (int)$next_start->format('y');
    $next_cycle_in_year = intdiv((int)$next_start->diff(new DateTime($next_start->format('Y') . '-01-01'))->days, 28) + 1;
    $next_cycle_code = sprintf('%02d%02d', $next_year_short, $next_cycle_in_year);

    $expiry_date = clone $next_start;
    $expiry_date->modify('-1 day');

    SwimResponse::formatted([
        'cycle' => $cycle_code,
        'effective_date' => $current_start->format('Y-m-d'),
        'expiry_date' => $expiry_date->format('Y-m-d'),
        'next_cycle' => $next_cycle_code,
        'next_effective' => $next_start->format('Y-m-d'),
        'days_remaining' => $days_remaining,
        'data_sources' => ['FAA NASR (US)', 'X-Plane 12 CIFP (International)'],
    ], $format, 'reference_airac', $cache_params, $format_options);
}

function handleChangelog($format, $cache_params, $format_options) {
    $cycle = swim_get_param('cycle');
    $type = swim_get_param('type');
    $airport = swim_get_param('airport');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $conn = get_conn_ref();
    if (!$conn) SwimResponse::error('REF database unavailable', 503, 'SERVICE_UNAVAILABLE');

    $where = [];
    $params = [];

    if ($cycle) { $where[] = "airac_cycle = ?"; $params[] = $cycle; }
    if ($type) { $where[] = "change_type = ?"; $params[] = strtolower($type); }
    if ($airport) { $where[] = "(entry_name LIKE ? OR entry_name = ?)"; $params[] = '%' . strtoupper($airport) . '%'; $params[] = strtoupper($airport); }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.navdata_changelogs $where_sql";
    $count_stmt = sqlsrv_query($conn, $count_sql, $params);
    $total = 0;
    if ($count_stmt !== false) {
        $r = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($r['total'] ?? 0);
        sqlsrv_free_stmt($count_stmt);
    }

    // Summary by type
    $summary_sql = "SELECT change_type, COUNT(*) AS cnt FROM dbo.navdata_changelogs $where_sql GROUP BY change_type";
    $summary_stmt = sqlsrv_query($conn, $summary_sql, $params);
    $summary = [];
    if ($summary_stmt !== false) {
        while ($sr = sqlsrv_fetch_array($summary_stmt, SQLSRV_FETCH_ASSOC)) {
            $summary[$sr['change_type']] = (int)$sr['cnt'];
        }
        sqlsrv_free_stmt($summary_stmt);
    }

    // Fetch page
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT change_type, table_name AS entity_type, entry_name AS entity_name, delta_detail, old_value, new_value, airac_cycle, created_utc
            FROM dbo.navdata_changelogs $where_sql
            ORDER BY created_utc DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) SwimResponse::error('Query failed', 500, 'DB_ERROR');

    $changes = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('c');
        }
        $changes[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    $data = [
        'changes' => $changes,
        'count' => count($changes),
        'total' => $total,
        'summary' => $summary,
    ];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airac', $cache_params, $format_options);
}

function handleSuperseded($format, $cache_params, $format_options) {
    $type = swim_get_param('type');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 500);

    if (!$type) {
        SwimResponse::error("type parameter required (fix, procedure, airway)", 400, 'MISSING_PARAM');
    }

    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $offset = ($page - 1) * $per_page;

    switch (strtolower($type)) {
        case 'fix':
            $count_sql = "SELECT COUNT(*) AS total FROM nav_fixes WHERE is_superseded = true";
            $sql = "SELECT fix_name, lat, lon, fix_type, superseded_cycle, superseded_reason, effective_date
                    FROM nav_fixes WHERE is_superseded = true ORDER BY fix_name LIMIT :limit OFFSET :offset";
            break;
        case 'procedure':
            $count_sql = "SELECT COUNT(*) AS total FROM nav_procedures WHERE is_superseded = true";
            $sql = "SELECT computer_code, procedure_name, procedure_type, airport_icao, superseded_cycle, source, effective_date
                    FROM nav_procedures WHERE is_superseded = true ORDER BY airport_icao, procedure_name LIMIT :limit OFFSET :offset";
            break;
        case 'airway':
            $count_sql = "SELECT COUNT(*) AS total FROM airways WHERE is_superseded = true";
            $sql = "SELECT airway_name, superseded_cycle, effective_date
                    FROM airways WHERE is_superseded = true ORDER BY airway_name LIMIT :limit OFFSET :offset";
            break;
        default:
            SwimResponse::error("Invalid type: $type. Use fix, procedure, or airway.", 400, 'INVALID_PARAM');
            return;
    }

    $count_stmt = $conn->query($count_sql);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $stmt = $conn->prepare($sql);
    $stmt->execute([':limit' => $per_page, ':offset' => $offset]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = ['type' => $type, 'superseded' => $items, 'count' => count($items), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airac', $cache_params, $format_options);
}
