<?php
/**
 * VATSWIM API v1 - Airspace Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airspace/boundaries?type=artcc     - List boundaries by type
 *   GET /reference/airspace/boundaries/{type}/{code}  - Single boundary + geometry
 *   GET /reference/airspace/at-point?lat=X&lon=Y      - Point-in-polygon
 *   GET /reference/airspace/firs?pattern=EG..          - FIR listing
 *   GET /reference/airspace/sectors?artcc=ZNY          - Sector listing
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airspace/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_airspace',
    'item' => 'boundary',
    'name' => 'VATSWIM Airspace Reference',
    'filename' => 'swim_airspace_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'type' => swim_get_param('type') ?? ($path_parts[1] ?? null),
    'code' => $path_parts[2] ?? null,
    'lat' => swim_get_param('lat'), 'lon' => swim_get_param('lon'),
    'alt' => swim_get_param('alt'),
    'pattern' => swim_get_param('pattern'),
    'artcc' => swim_get_param('artcc'), 'strata' => swim_get_param('strata'),
    'simplify' => swim_get_param('simplify'),
    'include' => swim_get_param('include'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_airspace', $cache_params, $format, $format_options)) {
    exit;
}

$conn = get_conn_gis();
if (!$conn) {
    SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
}

switch ($sub) {
    case 'boundaries':
        $type = $path_parts[1] ?? swim_get_param('type');
        $code = isset($path_parts[2]) ? strtoupper($path_parts[2]) : null;
        if ($code) {
            handleBoundaryDetail($conn, $type, $code, $format, $cache_params, $format_options);
        } else {
            handleBoundaryList($conn, $type, $include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'at-point':
        handleAtPoint($format, $cache_params, $format_options);
        break;

    case 'firs':
        handleFirs($conn, $include_geometry, $format, $cache_params, $format_options);
        break;

    case 'sectors':
        handleSectors($conn, $include_geometry, $format, $cache_params, $format_options);
        break;

    default:
        SwimResponse::error("Unknown airspace sub-resource: $sub. Use 'boundaries', 'at-point', 'firs', or 'sectors'.", 400, 'INVALID_RESOURCE');
}

function handleBoundaryDetail($conn, $type, $code, $format, $cache_params, $format_options) {
    $simplify = swim_get_param('simplify');
    $geom_expr = $simplify
        ? "ST_AsGeoJSON(ST_Simplify(geom, " . (float)$simplify . "), 5) AS geometry"
        : "ST_AsGeoJSON(geom, 5) AS geometry";

    $table = getBoundaryTable($type);
    if (!$table) {
        SwimResponse::error("Invalid boundary type: $type. Use artcc, tracon, or sector.", 400, 'INVALID_PARAM');
    }

    $code_col = $table['code_col'];
    $sql = "SELECT *, $geom_expr,
                   ST_Area(geom::geography) / 3429904.0 AS area_sq_nm
            FROM {$table['table']}
            WHERE $code_col = :code
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        SwimResponse::error("Boundary not found: $type/$code", 404, 'NOT_FOUND');
    }

    $row['geometry'] = json_decode($row['geometry'] ?? 'null', true);
    $row['area_sq_nm'] = isset($row['area_sq_nm']) ? round((float)$row['area_sq_nm'], 1) : null;
    unset($row['geom']);

    SwimResponse::formatted(['boundary' => $row], $format, 'reference_airspace', $cache_params, $format_options);
}

function handleBoundaryList($conn, $type, $include_geometry, $format, $cache_params, $format_options) {
    if (!$type) {
        SwimResponse::error("type parameter required (artcc, tracon, sector)", 400, 'MISSING_PARAM');
    }

    $table = getBoundaryTable($type);
    if (!$table) {
        SwimResponse::error("Invalid type: $type", 400, 'INVALID_PARAM');
    }

    $strata = swim_get_param('strata');
    $artcc = swim_get_param('artcc');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = [];
    $params = [];

    if ($strata && $type === 'sector') {
        $where[] = "sector_type = :strata";
        $params[':strata'] = strtoupper($strata);
    }
    if ($artcc && in_array($type, ['tracon', 'sector'])) {
        $where[] = "parent_artcc = :artcc";
        $params[':artcc'] = strtoupper($artcc);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM {$table['table']} $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $cols = $table['list_cols'];
    $sql = "SELECT $cols $geom FROM {$table['table']} $where_sql
            ORDER BY {$table['code_col']} LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        if (isset($r['geometry'])) $r['geometry'] = json_decode($r['geometry'], true);
    }

    $data = ['boundaries' => $rows, 'count' => count($rows), 'total' => $total, 'type' => $type];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airspace', $cache_params, $format_options);
}

function handleAtPoint($format, $cache_params, $format_options) {
    $lat = swim_get_param('lat');
    $lon = swim_get_param('lon');
    $alt = swim_get_param('alt');

    if ($lat === null || $lon === null) {
        SwimResponse::error('lat and lon parameters required', 400, 'MISSING_PARAM');
    }

    $gis = GISService::getInstance();
    if (!$gis) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $result = $gis->getBoundariesAtPoint((float)$lat, (float)$lon, $alt !== null ? (int)$alt : null);

    SwimResponse::formatted([
        'query' => ['lat' => (float)$lat, 'lon' => (float)$lon, 'alt' => $alt !== null ? (int)$alt : null],
        'boundaries' => $result,
    ], $format, 'reference_airspace', $cache_params, $format_options);
}

function handleFirs($conn, $include_geometry, $format, $cache_params, $format_options) {
    $pattern = swim_get_param('pattern');
    $is_oceanic = swim_get_param('is_oceanic');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = ["hierarchy_type = 'FIR'"];
    $params = [];

    if ($pattern) {
        $like = str_replace('.', '_', $pattern);
        $like = str_replace('*', '%', $like);
        $where[] = "artcc_code LIKE :pattern";
        $params[':pattern'] = strtoupper($like);
    }
    if ($is_oceanic !== null) {
        $where[] = "is_oceanic = :oceanic";
        $params[':oceanic'] = filter_var($is_oceanic, FILTER_VALIDATE_BOOLEAN);
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM artcc_boundaries $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT artcc_code, artcc_name, hierarchy_type, is_oceanic $geom
            FROM artcc_boundaries $where_sql
            ORDER BY artcc_code LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $firs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($firs as &$f) {
        if (isset($f['geometry'])) $f['geometry'] = json_decode($f['geometry'], true);
    }

    $data = ['firs' => $firs, 'count' => count($firs), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airspace', $cache_params, $format_options);
}

function handleSectors($conn, $include_geometry, $format, $cache_params, $format_options) {
    $artcc = swim_get_param('artcc');
    $strata = swim_get_param('strata');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = [];
    $params = [];

    if ($artcc) { $where[] = "parent_artcc = :artcc"; $params[':artcc'] = strtoupper($artcc); }
    if ($strata) { $where[] = "sector_type = :strata"; $params[':strata'] = strtoupper($strata); }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM sector_boundaries $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT sector_code, sector_name, parent_artcc, sector_type, floor_fl, ceiling_fl $geom
            FROM sector_boundaries $where_sql
            ORDER BY parent_artcc, sector_code LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $sectors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sectors as &$s) {
        if (isset($s['geometry'])) $s['geometry'] = json_decode($s['geometry'], true);
    }

    $data = ['sectors' => $sectors, 'count' => count($sectors), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airspace', $cache_params, $format_options);
}

function getBoundaryTable($type) {
    $tables = [
        'artcc' => ['table' => 'artcc_boundaries', 'code_col' => 'artcc_code', 'list_cols' => 'artcc_code, artcc_name, hierarchy_type, is_oceanic'],
        'tracon' => ['table' => 'tracon_boundaries', 'code_col' => 'tracon_code', 'list_cols' => 'tracon_code, tracon_name, parent_artcc'],
        'sector' => ['table' => 'sector_boundaries', 'code_col' => 'sector_code', 'list_cols' => 'sector_code, sector_name, parent_artcc, sector_type, floor_fl, ceiling_fl'],
    ];
    return $tables[strtolower($type)] ?? null;
}
