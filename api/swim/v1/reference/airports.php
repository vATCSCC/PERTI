<?php
/**
 * VATSWIM API v1 - Airport Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airports/lookup?faa={lid}&icao={code}  - Code conversion
 *   GET /reference/airports/search?q=...&near=lat,lon     - Search
 *   GET /reference/airports/{code}                        - Full profile
 *   GET /reference/airports/{code}/facilities             - Responsible TRACON/Center
 *   GET /reference/airports/{code}/runways                - Runway configurations
 *   GET /reference/airports/{code}/taxi-times             - Proxy to taxi-times.php
 *   GET /reference/airports/{code}/connect-times          - Connect-to-push times
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airports/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$code = !empty($path_parts[0]) ? strtoupper(trim($path_parts[0])) : null;
$action = $path_parts[1] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_airports',
    'item' => 'airport',
    'name' => 'VATSWIM Airport Reference',
    'filename' => 'swim_airports_' . date('Ymd_His')
];

$cache_params = array_filter([
    'code' => $code, 'action' => $action,
    'q' => swim_get_param('q'), 'near' => swim_get_param('near'),
    'faa' => swim_get_param('faa'), 'icao' => swim_get_param('icao'),
    'include' => swim_get_param('include'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference', $cache_params, $format, $format_options)) {
    exit;
}

// Route to handler
if ($code === 'LOOKUP') {
    handleLookup($format, $cache_params, $format_options);
} elseif ($code === 'SEARCH') {
    handleSearch($include_geometry, $format, $cache_params, $format_options);
} elseif ($code !== null && $action === 'facilities') {
    handleFacilities($code, $format, $cache_params, $format_options);
} elseif ($code !== null && $action === 'runways') {
    handleRunways($code, $format, $cache_params, $format_options);
} elseif ($code !== null && $action === 'taxi-times') {
    header('Location: /api/swim/v1/reference/taxi-times/' . urlencode($code) . '?' . $_SERVER['QUERY_STRING']);
    exit;
} elseif ($code !== null && $action === 'connect-times') {
    handleConnectTimes($code, $format, $cache_params, $format_options);
} elseif ($code !== null) {
    handleAirportProfile($code, $include_geometry, $format, $cache_params, $format_options);
} else {
    SwimResponse::error('Specify an airport code, or use /lookup or /search', 400, 'MISSING_PARAM');
}

function handleLookup($format, $cache_params, $format_options) {
    $faa = swim_get_param('faa');
    $icao = swim_get_param('icao');

    if (!$faa && !$icao) {
        SwimResponse::error('Provide faa or icao parameter', 400, 'MISSING_PARAM');
    }

    $conn = get_conn_gis();
    if (!$conn) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    if ($faa) {
        $stmt = $conn->prepare("SELECT icao_id, iata_id, airport_name, country_code, region_code FROM airports WHERE iata_id = :faa LIMIT 5");
        $stmt->execute([':faa' => strtoupper($faa)]);
    } else {
        $stmt = $conn->prepare("SELECT icao_id, iata_id, airport_name, country_code, region_code FROM airports WHERE icao_id = :icao LIMIT 5");
        $stmt->execute([':icao' => strtoupper($icao)]);
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($results)) {
        SwimResponse::error('Airport not found', 404, 'NOT_FOUND');
    }

    SwimResponse::formatted([
        'airports' => $results,
        'count' => count($results),
    ], $format, 'reference', $cache_params, $format_options);
}

function handleAirportProfile($code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $geom_col = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT icao_id, iata_id, airport_name, country_code, region_code,
                   lat, lon, elevation_ft, airport_type, parent_artcc, parent_tracon
                   $geom_col
            FROM airports
            WHERE " . (strlen($code) === 3 ? "iata_id = :code" : "icao_id = :code") . "
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Try the other field
        $alt_sql = "SELECT icao_id, iata_id, airport_name, country_code, region_code,
                           lat, lon, elevation_ft, airport_type, parent_artcc, parent_tracon
                           $geom_col
                    FROM airports
                    WHERE " . (strlen($code) === 3 ? "icao_id = :code" : "iata_id = :code") . "
                    LIMIT 1";
        $stmt2 = $conn->prepare($alt_sql);
        $stmt2->execute([':code' => $code]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        SwimResponse::error("Airport not found: $code", 404, 'NOT_FOUND');
    }

    if (isset($row['geometry'])) {
        $row['geometry'] = json_decode($row['geometry'], true);
    }

    SwimResponse::formatted(['airport' => $row], $format, 'reference', $cache_params, $format_options);
}

function handleFacilities($code, $format, $cache_params, $format_options) {
    $gis = GISService::getInstance();
    if (!$gis) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $conn = get_conn_gis();
    $stmt = $conn->prepare("SELECT lat, lon FROM airports WHERE icao_id = :code OR iata_id = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $apt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$apt) {
        SwimResponse::error("Airport not found: $code", 404, 'NOT_FOUND');
    }

    $boundaries = $gis->getBoundariesAtPoint((float)$apt['lat'], (float)$apt['lon']);

    SwimResponse::formatted([
        'airport' => $code,
        'facilities' => $boundaries,
    ], $format, 'reference', $cache_params, $format_options);
}

function handleRunways($code, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT runway_id, length_ft, width_ft, surface, heading
            FROM dbo.airport_geometry
            WHERE airport_icao = ?
            ORDER BY runway_id";
    $stmt = sqlsrv_query($conn, $sql, [$code]);

    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $runways = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $runways[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    // Also try with K prefix removed for FAA LIDs
    if (empty($runways) && strlen($code) === 4 && $code[0] === 'K') {
        $faa_lid = substr($code, 1);
        $stmt2 = sqlsrv_query($conn, $sql, [$faa_lid]);
        if ($stmt2 !== false) {
            while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
                $runways[] = $row;
            }
            sqlsrv_free_stmt($stmt2);
        }
    }

    SwimResponse::formatted([
        'airport' => $code,
        'runways' => $runways,
        'count' => count($runways),
    ], $format, 'reference', $cache_params, $format_options);
}

function handleConnectTimes($code, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT airport_icao, unimpeded_connect_sec, sample_size, confidence, last_refreshed_utc
            FROM dbo.airport_connect_reference
            WHERE airport_icao = ?";
    $stmt = sqlsrv_query($conn, $sql, [$code]);

    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("No connect time data for: $code", 404, 'NOT_FOUND');
    }

    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) {
            $row[$k] = $v->format('c');
        }
    }

    SwimResponse::formatted([
        'airport' => $code,
        'connect_time' => $row,
        'methodology' => [
            'description' => 'Unimpeded connect-to-push time, 90-day rolling window',
            'default_connect_sec' => 900,
            'refresh_schedule' => 'Daily at 02:15Z',
        ],
    ], $format, 'reference', $cache_params, $format_options);
}

function handleSearch($include_geometry, $format, $cache_params, $format_options) {
    $q = swim_get_param('q');
    $near = swim_get_param('near');
    $radius = swim_get_int_param('radius', 25, 1, 250);
    $country = swim_get_param('country');
    $airport_class = swim_get_param('class');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 50, 1, 100);

    if (!$q && !$near) {
        SwimResponse::error('Provide q (text search) or near (lat,lon) parameter', 400, 'MISSING_PARAM');
    }

    $conn = get_conn_gis();
    if (!$conn) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $geom_col = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $where = [];
    $params = [];
    $order_by = "airport_name";

    if ($q) {
        $where[] = "(icao_id ILIKE :q OR iata_id ILIKE :q OR airport_name ILIKE :qw)";
        $params[':q'] = $q . '%';
        $params[':qw'] = '%' . $q . '%';
    }

    if ($near) {
        $parts = explode(',', $near);
        if (count($parts) !== 2) {
            SwimResponse::error('near parameter must be lat,lon', 400, 'INVALID_PARAM');
        }
        $lat = (float)$parts[0];
        $lon = (float)$parts[1];
        $radius_m = $radius * 1852;  // nm to meters
        $where[] = "ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)";
        $params[':lat'] = $lat;
        $params[':lon'] = $lon;
        $params[':radius'] = $radius_m;
        $order_by = "ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint($lon, $lat), 4326)::geography)";
    }

    if ($country) {
        $where[] = "country_code = :country";
        $params[':country'] = strtoupper($country);
    }

    if ($airport_class) {
        $where[] = "airport_type = :class";
        $params[':class'] = $airport_class;
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    // Count
    $count_sql = "SELECT COUNT(*) AS total FROM airports $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    // Fetch
    $sql = "SELECT icao_id, iata_id, airport_name, country_code, region_code,
                   lat, lon, elevation_ft, airport_type $geom_col
            FROM airports $where_sql
            ORDER BY $order_by
            LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($airports as &$a) {
        if (isset($a['geometry'])) {
            $a['geometry'] = json_decode($a['geometry'], true);
        }
    }

    $data = ['airports' => $airports, 'count' => count($airports), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference', $cache_params, $format_options);
}
