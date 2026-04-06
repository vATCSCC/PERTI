<?php
/**
 * VATSWIM API v1 - Airline Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airlines              - List/search all airlines
 *   GET /reference/airlines/{icao}       - Single airline detail
 *
 * Query Parameters:
 *   search       - Free text search (name, callsign, code)
 *   country      - Country filter
 *   page         - Page number (default 1)
 *   per_page     - Results per page (default 100, max 1000)
 *   format       - Response format: json (default), xml, csv, ndjson
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

// Parse path: /reference/airlines/{icao_code}
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airlines/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$airline_code = !empty($path_parts[0]) ? strtoupper(trim($path_parts[0])) : null;

// Validate airline code
if ($airline_code !== null && (strlen($airline_code) < 2 || strlen($airline_code) > 4)) {
    SwimResponse::error('Invalid airline code. Use 2-letter IATA or 3-letter ICAO.', 400, 'INVALID_CODE');
}

// Query parameters
$search = swim_get_param('search');
$country = swim_get_param('country');
$page = swim_get_int_param('page', 1, 1, 10000);
$per_page = swim_get_int_param('per_page', 100, 1, 1000);
$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

$format_options = [
    'root' => 'swim_airlines',
    'item' => 'airline',
    'name' => 'VATSWIM Airline Reference' . ($airline_code ? ' - ' . $airline_code : ''),
    'filename' => 'swim_airlines' . ($airline_code ? '_' . $airline_code : '') . '_' . date('Ymd_His')
];

// Cache key
$cache_params = array_filter([
    'airline' => $airline_code,
    'search' => $search,
    'country' => $country,
    'page' => $page > 1 ? (string)$page : null,
    'per_page' => $per_page != 100 ? (string)$per_page : null,
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_airline', $cache_params, $format, $format_options)) {
    exit;
}

// Connect to ADL (airlines table)
$conn = get_conn_adl();
if (!$conn) {
    SwimResponse::error('Database connection unavailable', 503, 'SERVICE_UNAVAILABLE');
}

if ($airline_code !== null) {
    handleSingleAirline($conn, $airline_code, $format, $cache_params, $format_options);
} else {
    handleAirlineList($conn, $search, $country, $page, $per_page, $format, $cache_params, $format_options);
}

function handleSingleAirline($conn, $code, $format, $cache_params, $format_options) {
    // Try ICAO first, then IATA
    $sql = "SELECT icao_code, iata_code, name, callsign, country
            FROM dbo.airlines
            WHERE icao_code = ? OR iata_code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$code, $code]);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("Airline not found: $code", 404, 'NOT_FOUND');
    }

    $airline = formatAirlineRow($row);
    SwimResponse::formatted(['airline' => $airline], $format, 'reference_airline', $cache_params, $format_options);
}

function handleAirlineList($conn, $search, $country, $page, $per_page, $format, $cache_params, $format_options) {
    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(name LIKE ? OR callsign LIKE ? OR icao_code LIKE ? OR iata_code LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    if ($country) {
        $where[] = "country = ?";
        $params[] = strtoupper($country);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.airlines $where_sql";
    $count_stmt = sqlsrv_query($conn, $count_sql, $params);
    $total = 0;
    if ($count_stmt !== false) {
        $count_row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($count_row['total'] ?? 0);
        sqlsrv_free_stmt($count_stmt);
    }

    // Fetch page
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT icao_code, iata_code, name, callsign, country
            FROM dbo.airlines
            $where_sql
            ORDER BY icao_code
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $airlines = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $airlines[] = formatAirlineRow($row);
    }
    sqlsrv_free_stmt($stmt);

    $data = [
        'airlines' => $airlines,
        'count' => count($airlines),
        'total' => $total,
    ];

    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airline', $cache_params, $format_options);
}

function formatAirlineRow($row) {
    return [
        'icao_code' => $row['icao_code'] ?? null,
        'iata_code' => $row['iata_code'] ?? null,
        'name' => $row['name'] ?? null,
        'callsign' => $row['callsign'] ?? null,
        'country' => $row['country'] ?? null,
    ];
}
