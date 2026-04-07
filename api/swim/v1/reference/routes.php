<?php
/**
 * VATSWIM API v1 - Route Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/routes/popular?origin=KJFK&dest=KLAX    - Most popular routes
 *   GET /reference/routes/statistics?origin=KJFK&dest=KLAX  - Aggregate city-pair stats
 */

// Load MySQL BEFORE SWIM auth (auth.php sets PERTI_SWIM_ONLY which skips MySQL)
require_once __DIR__ . '/../../../../load/config.php';
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/routes/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$format_options = [
    'root' => 'swim_routes',
    'item' => 'route',
    'name' => 'VATSWIM Route Reference',
    'filename' => 'swim_routes_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'origin' => swim_get_param('origin'),
    'dest' => swim_get_param('dest'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_route', $cache_params, $format, $format_options)) {
    exit;
}

switch ($sub) {
    case 'popular':
        handlePopularRoutes($format, $cache_params, $format_options);
        break;
    case 'statistics':
        handleRouteStatistics($format, $cache_params, $format_options);
        break;
    default:
        SwimResponse::error("Unknown routes sub-resource: $sub. Use 'popular' or 'statistics'.", 400, 'INVALID_RESOURCE');
}

function handlePopularRoutes($format, $cache_params, $format_options) {
    $origin = strtoupper(swim_get_param('origin', ''));
    $dest = strtoupper(swim_get_param('dest', ''));

    if (!$origin || !$dest) {
        SwimResponse::error('Both origin and dest parameters required', 400, 'MISSING_PARAM');
    }

    global $conn_pdo;
    if (!$conn_pdo) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT r.route_string,
                   COUNT(*) AS frequency,
                   AVG(f.flight_time_sec) AS avg_flight_time_sec,
                   MAX(t.date) AS last_seen
            FROM route_history_facts f
            JOIN dim_route r ON f.route_id = r.id
            JOIN dim_time t ON f.time_id = t.id
            WHERE r.origin_icao = :origin AND r.dest_icao = :dest
            GROUP BY r.route_string
            ORDER BY frequency DESC
            LIMIT 20";

    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([':origin' => $origin, ':dest' => $dest]);
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_flights = array_sum(array_column($routes, 'frequency'));

    foreach ($routes as &$r) {
        $r['frequency'] = (int)$r['frequency'];
        $r['avg_flight_time_sec'] = $r['avg_flight_time_sec'] !== null ? (int)round($r['avg_flight_time_sec']) : null;
        $r['percentage'] = $total_flights > 0 ? round(100.0 * $r['frequency'] / $total_flights, 1) : 0;
    }

    SwimResponse::formatted([
        'origin' => $origin,
        'dest' => $dest,
        'routes' => $routes,
        'count' => count($routes),
        'total_flights_sampled' => $total_flights,
    ], $format, 'reference_route', $cache_params, $format_options);
}

function handleRouteStatistics($format, $cache_params, $format_options) {
    $origin = strtoupper(swim_get_param('origin', ''));
    $dest = strtoupper(swim_get_param('dest', ''));

    if (!$origin || !$dest) {
        SwimResponse::error('Both origin and dest parameters required', 400, 'MISSING_PARAM');
    }

    global $conn_pdo;
    if (!$conn_pdo) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    // Aggregate stats
    $sql = "SELECT COUNT(*) AS total_flights,
                   COUNT(DISTINCT r.route_string) AS unique_routes,
                   AVG(f.flight_time_sec) AS avg_flight_time_sec,
                   MIN(t.date) AS earliest_date,
                   MAX(t.date) AS latest_date
            FROM route_history_facts f
            JOIN dim_route r ON f.route_id = r.id
            JOIN dim_time t ON f.time_id = t.id
            WHERE r.origin_icao = :origin AND r.dest_icao = :dest";
    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([':origin' => $origin, ':dest' => $dest]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Common aircraft types
    $type_sql = "SELECT at.icao_code, COUNT(*) AS flights
                 FROM route_history_facts f
                 JOIN dim_route r ON f.route_id = r.id
                 JOIN dim_aircraft_type at ON f.aircraft_type_id = at.id
                 WHERE r.origin_icao = :origin AND r.dest_icao = :dest
                 GROUP BY at.icao_code
                 ORDER BY flights DESC LIMIT 10";
    $type_stmt = $conn_pdo->prepare($type_sql);
    $type_stmt->execute([':origin' => $origin, ':dest' => $dest]);
    $common_types = $type_stmt->fetchAll(PDO::FETCH_ASSOC);

    SwimResponse::formatted([
        'origin' => $origin,
        'dest' => $dest,
        'statistics' => [
            'total_flights' => (int)($stats['total_flights'] ?? 0),
            'unique_routes' => (int)($stats['unique_routes'] ?? 0),
            'avg_flight_time_sec' => $stats['avg_flight_time_sec'] !== null ? (int)round($stats['avg_flight_time_sec']) : null,
            'date_range' => [
                'earliest' => $stats['earliest_date'],
                'latest' => $stats['latest_date'],
            ],
        ],
        'common_aircraft' => $common_types,
    ], $format, 'reference_route', $cache_params, $format_options);
}
