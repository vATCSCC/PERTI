<?php
/**
 * VATSWIM API v1 - Aircraft Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/aircraft/types           - List/search aircraft types
 *   GET /reference/aircraft/types/{icao}    - Single type detail
 *   GET /reference/aircraft/families        - List all families
 *   GET /reference/aircraft/families/{key}  - Family detail with members
 *   GET /reference/aircraft/performance/{icao} - BADA performance data
 *
 * Query Parameters (types list):
 *   search        - Free text (manufacturer, model, ICAO code)
 *   manufacturer  - Manufacturer filter
 *   weight_class  - S, L, H, SUPER
 *   wake_category - L, M, H, J
 *   engine_type   - jet, turboprop, piston
 *   family        - Family key filter (e.g., a320fam)
 *   page, per_page, format
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

// Parse path: /reference/aircraft/{sub}/{code}
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/aircraft/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;  // 'types', 'families', 'performance'
$code = isset($path_parts[1]) ? strtoupper(trim($path_parts[1])) : null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

$format_options = [
    'root' => 'swim_aircraft',
    'item' => 'aircraft',
    'name' => 'VATSWIM Aircraft Reference',
    'filename' => 'swim_aircraft_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'code' => $code,
    'search' => swim_get_param('search'),
    'manufacturer' => swim_get_param('manufacturer'),
    'weight_class' => swim_get_param('weight_class'),
    'wake_category' => swim_get_param('wake_category'),
    'engine_type' => swim_get_param('engine_type'),
    'family' => swim_get_param('family'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_aircraft', $cache_params, $format, $format_options)) {
    exit;
}

// Load aircraft families
require_once __DIR__ . '/../../../../load/aircraft_families.php';
global $AIRCRAFT_FAMILIES;

// Route to handler
switch ($sub) {
    case 'families':
        if ($code) {
            handleFamilyDetail(strtolower($code), $AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } else {
            handleFamilyList($AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        }
        break;

    case 'performance':
        if (!$code) {
            SwimResponse::error('ICAO type code required for performance lookup', 400, 'MISSING_PARAM');
        }
        handlePerformance($code, $format, $cache_params, $format_options);
        break;

    case 'types':
    case null:
        if ($sub === null && $code === null) {
            handleTypeList($AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } elseif ($sub === 'types' && $code) {
            handleTypeDetail($code, $AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } elseif ($sub === 'types') {
            handleTypeList($AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } else {
            // $sub is something else - treat as ICAO code for backward compat
            handleTypeDetail(strtoupper($sub), $AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        }
        break;

    default:
        SwimResponse::error("Unknown aircraft sub-resource: $sub. Use 'types', 'families', or 'performance'.", 400, 'INVALID_RESOURCE');
}

function handleTypeDetail($icao, $families, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT * FROM dbo.ACD_Data WHERE ICAO_Code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$icao]);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("Aircraft type not found: $icao", 404, 'NOT_FOUND');
    }

    $aircraft = formatTypeRow($row);

    // Add family info
    $aircraft['family'] = null;
    $aircraft['family_members'] = [];
    foreach ($families as $key => $members) {
        if (in_array($icao, $members)) {
            $aircraft['family'] = $key;
            $aircraft['family_members'] = $members;
            break;
        }
    }

    SwimResponse::formatted(['aircraft' => $aircraft], $format, 'reference_aircraft', $cache_params, $format_options);
}

function handleTypeList($families, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $search = swim_get_param('search');
    $manufacturer = swim_get_param('manufacturer');
    $weight_class = swim_get_param('weight_class');
    $wake_category = swim_get_param('wake_category');
    $engine_type = swim_get_param('engine_type');
    $family_filter = swim_get_param('family');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(ICAO_Code LIKE ? OR Manufacturer LIKE ? OR Model LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }
    if ($manufacturer) {
        $where[] = "Manufacturer LIKE ?";
        $params[] = '%' . $manufacturer . '%';
    }
    if ($weight_class) {
        $where[] = "WeightClass = ?";
        $params[] = strtoupper($weight_class);
    }
    if ($wake_category) {
        $where[] = "WTC = ?";
        $params[] = strtoupper($wake_category);
    }
    if ($engine_type) {
        $where[] = "EngineType LIKE ?";
        $params[] = '%' . $engine_type . '%';
    }

    // Family filter: expand to list of ICAO codes
    if ($family_filter && isset($families[strtolower($family_filter)])) {
        $members = $families[strtolower($family_filter)];
        $placeholders = implode(',', array_fill(0, count($members), '?'));
        $where[] = "ICAO_Code IN ($placeholders)";
        $params = array_merge($params, $members);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.ACD_Data $where_sql";
    $count_stmt = sqlsrv_query($conn, $count_sql, $params);
    $total = 0;
    if ($count_stmt !== false) {
        $r = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($r['total'] ?? 0);
        sqlsrv_free_stmt($count_stmt);
    }

    // Fetch page
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT * FROM dbo.ACD_Data $where_sql ORDER BY ICAO_Code OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $types = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $types[] = formatTypeRow($row);
    }
    sqlsrv_free_stmt($stmt);

    $data = ['types' => $types, 'count' => count($types), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_aircraft', $cache_params, $format_options);
}

function handleFamilyList($families, $format, $cache_params, $format_options) {
    $list = [];
    foreach ($families as $key => $members) {
        $list[] = [
            'key' => $key,
            'member_count' => count($members),
            'members' => $members,
        ];
    }

    SwimResponse::formatted([
        'families' => $list,
        'count' => count($list),
    ], $format, 'reference_aircraft', $cache_params, $format_options);
}

function handleFamilyDetail($key, $families, $format, $cache_params, $format_options) {
    if (!isset($families[$key])) {
        SwimResponse::error("Aircraft family not found: $key", 404, 'NOT_FOUND');
    }

    $members = $families[$key];

    // Fetch full details for each member
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $placeholders = implode(',', array_fill(0, count($members), '?'));
    $sql = "SELECT * FROM dbo.ACD_Data WHERE ICAO_Code IN ($placeholders) ORDER BY ICAO_Code";
    $stmt = sqlsrv_query($conn, $sql, $members);

    $details = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $details[] = formatTypeRow($row);
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::formatted([
        'family' => $key,
        'member_count' => count($members),
        'member_codes' => $members,
        'members' => $details,
    ], $format, 'reference_aircraft', $cache_params, $format_options);
}

function handlePerformance($icao, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT * FROM dbo.ACD_Data WHERE ICAO_Code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$icao]);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("Aircraft type not found: $icao", 404, 'NOT_FOUND');
    }

    $aircraft = formatTypeRow($row);

    // Try BADA performance data
    $performance = null;
    $bada_sql = "SELECT TOP 1 * FROM dbo.bada_opf WHERE icao_code = ?";
    $bada_stmt = sqlsrv_query($conn, $bada_sql, [$icao]);
    if ($bada_stmt !== false) {
        $bada_row = sqlsrv_fetch_array($bada_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($bada_stmt);
        if ($bada_row) {
            $performance = $bada_row;
        }
    }

    SwimResponse::formatted([
        'aircraft' => $aircraft,
        'performance' => $performance,
        'source' => $performance ? 'BADA' : null,
    ], $format, 'reference_aircraft', $cache_params, $format_options);
}

/**
 * Format ACD_Data row to consistent API response shape
 */
function formatTypeRow($row) {
    return [
        'icao_code' => $row['ICAO_Code'] ?? $row['icao_code'] ?? null,
        'name' => $row['TypeName'] ?? $row['Model'] ?? null,
        'manufacturer' => $row['Manufacturer'] ?? null,
        'weight_class' => $row['WeightClass'] ?? null,
        'wake_category' => $row['WTC'] ?? null,
        'engine_type' => $row['EngineType'] ?? null,
        'engine_count' => isset($row['EngineCount']) ? (int)$row['EngineCount'] : null,
    ];
}
