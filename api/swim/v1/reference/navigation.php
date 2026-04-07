<?php
/**
 * VATSWIM API v1 - Navigation Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/navigation/fixes              - List/search fixes
 *   GET /reference/navigation/fixes/{name}        - Fix detail (may return array)
 *   GET /reference/navigation/airways             - List airways
 *   GET /reference/navigation/airways/{name}      - Airway with segments + geometry
 *   GET /reference/navigation/airways/{name}/segment?from=X&to=Y - Partial airway
 *   GET /reference/navigation/procedures          - List DPs/STARs
 *   GET /reference/navigation/procedures/{code}   - Procedure detail by computer_code
 *   GET /reference/navigation/procedures/airport/{icao}?type=DP - Per-airport list
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/navigation/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;
$code = isset($path_parts[1]) ? trim($path_parts[1]) : null;
$action = $path_parts[2] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_navigation',
    'item' => 'nav_element',
    'name' => 'VATSWIM Navigation Reference',
    'filename' => 'swim_nav_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub, 'code' => $code, 'action' => $action,
    'name' => swim_get_param('name'), 'type' => swim_get_param('type'),
    'near' => swim_get_param('near'), 'artcc' => swim_get_param('artcc'),
    'airport' => swim_get_param('airport'), 'contains_fix' => swim_get_param('contains_fix'),
    'from' => swim_get_param('from'), 'to' => swim_get_param('to'),
    'include' => swim_get_param('include'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_nav', $cache_params, $format, $format_options)) {
    exit;
}

$conn = get_conn_gis();
if (!$conn) {
    SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
}

switch ($sub) {
    case 'fixes':
        if ($code) {
            handleFixDetail($conn, strtoupper($code), $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleFixList($conn, $include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'airways':
        if ($code && $action === 'segment') {
            handleAirwaySegment($conn, strtoupper($code), $format, $cache_params, $format_options);
        } elseif ($code) {
            handleAirwayDetail($conn, strtoupper($code), $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleAirwayList($conn, $format, $cache_params, $format_options);
        }
        break;

    case 'procedures':
        if ($code === 'airport' && isset($path_parts[2])) {
            handleProceduresByAirport($conn, strtoupper($path_parts[2]), $format, $cache_params, $format_options);
        } elseif ($code) {
            handleProcedureDetail($conn, $code, $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleProcedureList($conn, $format, $cache_params, $format_options);
        }
        break;

    default:
        SwimResponse::error("Unknown navigation sub-resource: $sub. Use 'fixes', 'airways', or 'procedures'.", 400, 'INVALID_RESOURCE');
}

// === FIX HANDLERS ===

function handleFixDetail($conn, $name, $include_geometry, $format, $cache_params, $format_options) {
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT fix_name, lat, lon, fix_type, artcc_id,
                   is_superseded $geom
            FROM nav_fixes
            WHERE fix_name = :name AND (is_superseded = false OR is_superseded IS NULL)
            ORDER BY fix_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':name' => $name]);
    $fixes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($fixes)) {
        SwimResponse::error("Fix not found: $name", 404, 'NOT_FOUND');
    }

    foreach ($fixes as &$f) {
        if (isset($f['geometry'])) $f['geometry'] = json_decode($f['geometry'], true);
        $f['latitude'] = (float)$f['lat'];
        $f['longitude'] = (float)$f['lon'];
        unset($f['lat'], $f['lon']);
    }

    SwimResponse::formatted([
        'fix_name' => $name,
        'locations' => $fixes,
        'count' => count($fixes),
        'note' => count($fixes) > 1 ? 'Fix name exists in multiple locations' : null,
    ], $format, 'reference_nav', $cache_params, $format_options);
}

function handleFixList($conn, $include_geometry, $format, $cache_params, $format_options) {
    $name = swim_get_param('name');
    $type = swim_get_param('type');
    $near = swim_get_param('near');
    $radius = swim_get_int_param('radius', 25, 1, 250);
    $artcc = swim_get_param('artcc');
    $country = swim_get_param('country');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 200);

    $where = ["(is_superseded = false OR is_superseded IS NULL)"];
    $params = [];

    if ($name) {
        if (str_contains($name, '*')) {
            $where[] = "fix_name LIKE :name";
            $params[':name'] = str_replace('*', '%', $name);
        } else {
            $where[] = "fix_name = :name";
            $params[':name'] = strtoupper($name);
        }
    }
    if ($type) {
        $where[] = "fix_type = :type";
        $params[':type'] = strtoupper($type);
    }
    if ($artcc) {
        $where[] = "artcc_id = :artcc";
        $params[':artcc'] = strtoupper($artcc);
    }

    $order_by = "fix_name";
    if ($near) {
        $parts = explode(',', $near);
        if (count($parts) !== 2) SwimResponse::error('near must be lat,lon', 400, 'INVALID_PARAM');
        $lat = (float)$parts[0];
        $lon = (float)$parts[1];
        $radius_m = $radius * 1852;
        $where[] = "ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)";
        $params[':lat'] = $lat;
        $params[':lon'] = $lon;
        $params[':radius'] = $radius_m;
        $order_by = "ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint($lon, $lat), 4326)::geography)";
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * $per_page;

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";

    $count_sql = "SELECT COUNT(*) AS total FROM nav_fixes $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT fix_name, lat, lon, fix_type, artcc_id $geom
            FROM nav_fixes $where_sql ORDER BY $order_by LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $fixes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fixes as &$f) {
        if (isset($f['geometry'])) $f['geometry'] = json_decode($f['geometry'], true);
        $f['latitude'] = (float)$f['lat'];
        $f['longitude'] = (float)$f['lon'];
        unset($f['lat'], $f['lon']);
    }

    $data = ['fixes' => $fixes, 'count' => count($fixes), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_nav', $cache_params, $format_options);
}

// === AIRWAY HANDLERS ===

function handleAirwayDetail($conn, $name, $include_geometry, $format, $cache_params, $format_options) {
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";

    $sql = "SELECT sequence_num, from_fix, to_fix, distance_nm $geom
            FROM airway_segments
            WHERE airway_name = :name
            ORDER BY sequence_num";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':name' => $name]);
    $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($segments)) {
        SwimResponse::error("Airway not found: $name", 404, 'NOT_FOUND');
    }

    foreach ($segments as &$s) {
        if (isset($s['geometry'])) $s['geometry'] = json_decode($s['geometry'], true);
        $s['distance_nm'] = isset($s['distance_nm']) ? round((float)$s['distance_nm'], 1) : null;
    }

    $total_distance = array_sum(array_column($segments, 'distance_nm'));

    $full_geom = null;
    if ($include_geometry) {
        $geom_sql = "SELECT ST_AsGeoJSON(geom, 5) AS geometry FROM airways WHERE airway_name = :name LIMIT 1";
        $geom_stmt = $conn->prepare($geom_sql);
        $geom_stmt->execute([':name' => $name]);
        $geom_row = $geom_stmt->fetch(PDO::FETCH_ASSOC);
        if ($geom_row) $full_geom = json_decode($geom_row['geometry'], true);
    }

    SwimResponse::formatted([
        'airway_name' => $name,
        'segments' => $segments,
        'segment_count' => count($segments),
        'total_distance_nm' => round($total_distance, 1),
        'geometry' => $full_geom,
    ], $format, 'reference_nav', $cache_params, $format_options);
}

function handleAirwaySegment($conn, $name, $format, $cache_params, $format_options) {
    $from = strtoupper(swim_get_param('from', ''));
    $to = strtoupper(swim_get_param('to', ''));

    if (!$from || !$to) {
        SwimResponse::error('Both from and to fix parameters required', 400, 'MISSING_PARAM');
    }

    $sql = "SELECT * FROM expand_airway(:name, :from_fix, :to_fix)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':name' => $name, ':from_fix' => $from, ':to_fix' => $to]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($result)) {
        SwimResponse::error("Could not expand airway $name from $from to $to", 404, 'NOT_FOUND');
    }

    SwimResponse::formatted([
        'airway_name' => $name,
        'from_fix' => $from,
        'to_fix' => $to,
        'waypoints' => $result,
        'count' => count($result),
    ], $format, 'reference_nav', $cache_params, $format_options);
}

function handleAirwayList($conn, $format, $cache_params, $format_options) {
    $name = swim_get_param('name');
    $type = swim_get_param('type');
    $contains_fix = swim_get_param('contains_fix');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 200);

    $where = ["(is_superseded = false OR is_superseded IS NULL)"];
    $params = [];

    if ($name) {
        if (str_contains($name, '*')) {
            $where[] = "airway_name LIKE :name";
            $params[':name'] = str_replace('*', '%', $name);
        } else {
            $where[] = "airway_name = :name";
            $params[':name'] = strtoupper($name);
        }
    }
    if ($type) {
        $where[] = "airway_name LIKE :type_prefix";
        $params[':type_prefix'] = strtoupper($type) . '%';
    }
    if ($contains_fix) {
        $where[] = "airway_name IN (SELECT DISTINCT airway_name FROM airway_segments WHERE fix_from = :fix OR fix_to = :fix2)";
        $params[':fix'] = strtoupper($contains_fix);
        $params[':fix2'] = strtoupper($contains_fix);
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(DISTINCT airway_name) AS total FROM airways $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT airway_name, airway_type, source
            FROM airways $where_sql
            ORDER BY airway_name
            LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $airways = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = ['airways' => $airways, 'count' => count($airways), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_nav', $cache_params, $format_options);
}

// === PROCEDURE HANDLERS ===

function handleProcedureDetail($conn, $computer_code, $include_geometry, $format, $cache_params, $format_options) {
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT computer_code, procedure_name, procedure_type, airport_icao,
                   transition_name, transition_type, full_route,
                   source, is_superseded $geom
            FROM nav_procedures
            WHERE computer_code = :code
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $computer_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        SwimResponse::error("Procedure not found: $computer_code", 404, 'NOT_FOUND');
    }

    if (isset($row['geometry'])) $row['geometry'] = json_decode($row['geometry'], true);

    SwimResponse::formatted(['procedure' => $row], $format, 'reference_nav', $cache_params, $format_options);
}

function handleProcedureList($conn, $format, $cache_params, $format_options) {
    $airport = swim_get_param('airport');
    $type = swim_get_param('type');
    $name = swim_get_param('name');
    $transition = swim_get_param('transition');
    $trans_type = swim_get_param('transition_type');
    $source = swim_get_param('source');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 200);

    $where = ["(is_superseded = false OR is_superseded IS NULL)"];
    $params = [];

    if ($airport) { $where[] = "airport_icao = :airport"; $params[':airport'] = strtoupper($airport); }
    if ($type) { $where[] = "procedure_type = :type"; $params[':type'] = strtoupper($type); }
    if ($name) {
        if (str_contains($name, '*')) {
            $where[] = "procedure_name LIKE :name";
            $params[':name'] = str_replace('*', '%', strtoupper($name));
        } else {
            $where[] = "procedure_name = :name";
            $params[':name'] = strtoupper($name);
        }
    }
    if ($transition) { $where[] = "transition_name = :trans"; $params[':trans'] = strtoupper($transition); }
    if ($trans_type) { $where[] = "transition_type = :ttype"; $params[':ttype'] = $trans_type; }
    if ($source) { $where[] = "source = :source"; $params[':source'] = $source; }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM nav_procedures $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT computer_code, procedure_name, procedure_type, airport_icao,
                   transition_name, transition_type, source
            FROM nav_procedures $where_sql
            ORDER BY airport_icao, procedure_type, procedure_name
            LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $procs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = ['procedures' => $procs, 'count' => count($procs), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_nav', $cache_params, $format_options);
}

function handleProceduresByAirport($conn, $icao, $format, $cache_params, $format_options) {
    $type = swim_get_param('type');
    $where = ["airport_icao = :airport", "(is_superseded = false OR is_superseded IS NULL)"];
    $params = [':airport' => $icao];

    if ($type) {
        $where[] = "procedure_type = :type";
        $params[':type'] = strtoupper($type);
    }

    $sql = "SELECT procedure_name, procedure_type, transition_name, transition_type, computer_code, source
            FROM nav_procedures
            WHERE " . implode(' AND ', $where) . "
            ORDER BY procedure_type, procedure_name, transition_type, transition_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by procedure name
    $grouped = [];
    foreach ($rows as $row) {
        $key = $row['procedure_type'] . ':' . $row['procedure_name'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'procedure_name' => $row['procedure_name'],
                'procedure_type' => $row['procedure_type'],
                'transitions' => [],
            ];
        }
        $grouped[$key]['transitions'][] = [
            'transition_name' => $row['transition_name'],
            'transition_type' => $row['transition_type'],
            'computer_code' => $row['computer_code'],
        ];
    }

    SwimResponse::formatted([
        'airport' => $icao,
        'procedures' => array_values($grouped),
        'count' => count($grouped),
    ], $format, 'reference_nav', $cache_params, $format_options);
}
