<?php
/**
 * VATSWIM API v1 - Utility Functions
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/utilities/distance?from=X&to=Y  - Great circle distance
 *   GET /reference/utilities/bearing?from=X&to=Y   - Bearing between points
 *   GET /reference/utilities/decode-route?route=... - Proxy to routes/resolve
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/utilities/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;
$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

$format_options = [
    'root' => 'swim_utilities',
    'item' => 'result',
    'name' => 'VATSWIM Utilities',
    'filename' => 'swim_utility_' . date('Ymd_His')
];

switch ($sub) {
    case 'distance':
        handleDistance($format, $format_options);
        break;
    case 'bearing':
        handleBearing($format, $format_options);
        break;
    case 'decode-route':
        handleDecodeRoute();
        break;
    default:
        SwimResponse::error("Unknown utility: $sub. Use 'distance', 'bearing', or 'decode-route'.", 400, 'INVALID_RESOURCE');
}

function resolvePoint($param_name) {
    $val = swim_get_param($param_name, '');
    if (!$val) return null;

    // Check if it's lat,lon pair
    if (preg_match('/^-?\d+\.?\d*\s*,\s*-?\d+\.?\d*$/', $val)) {
        $parts = array_map('trim', explode(',', $val));
        return ['lat' => (float)$parts[0], 'lon' => (float)$parts[1], 'label' => $val];
    }

    // Otherwise treat as fix/airport code - resolve via PostGIS
    $conn = get_conn_gis();
    if (!$conn) return null;

    $code = strtoupper(trim($val));

    // Try airports first
    $stmt = $conn->prepare("SELECT lat, lon FROM airports WHERE icao_id = :code OR arpt_id = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return ['lat' => (float)$row['lat'], 'lon' => (float)$row['lon'], 'label' => $code];

    // Try fixes
    $stmt2 = $conn->prepare("SELECT lat, lon FROM nav_fixes WHERE fix_name = :code AND (is_superseded = false OR is_superseded IS NULL) LIMIT 1");
    $stmt2->execute([':code' => $code]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row2) return ['lat' => (float)$row2['lat'], 'lon' => (float)$row2['lon'], 'label' => $code];

    return null;
}

function handleDistance($format, $format_options) {
    $from = resolvePoint('from');
    $to = resolvePoint('to');

    if (!$from) SwimResponse::error("Could not resolve 'from' parameter", 400, 'INVALID_PARAM');
    if (!$to) SwimResponse::error("Could not resolve 'to' parameter", 400, 'INVALID_PARAM');

    $dist = vincentyDistance($from['lat'], $from['lon'], $to['lat'], $to['lon']);
    $bearing = initialBearing($from['lat'], $from['lon'], $to['lat'], $to['lon']);
    $final_bearing = initialBearing($to['lat'], $to['lon'], $from['lat'], $from['lon']);
    $final_bearing = fmod($final_bearing + 180, 360);

    SwimResponse::formatted([
        'from' => $from,
        'to' => $to,
        'distance_nm' => round($dist / 1852, 1),
        'distance_km' => round($dist / 1000, 1),
        'initial_bearing' => round($bearing, 1),
        'final_bearing' => round($final_bearing, 1),
    ], $format, 'reference', [], $format_options);
}

function handleBearing($format, $format_options) {
    $from = resolvePoint('from');
    $to = resolvePoint('to');

    if (!$from) SwimResponse::error("Could not resolve 'from' parameter", 400, 'INVALID_PARAM');
    if (!$to) SwimResponse::error("Could not resolve 'to' parameter", 400, 'INVALID_PARAM');

    $bearing = initialBearing($from['lat'], $from['lon'], $to['lat'], $to['lon']);

    SwimResponse::formatted([
        'from' => $from,
        'to' => $to,
        'bearing' => round($bearing, 1),
    ], $format, 'reference', [], $format_options);
}

function handleDecodeRoute() {
    $route = swim_get_param('route');
    $origin = swim_get_param('origin');
    $dest = swim_get_param('dest');

    if (!$route) SwimResponse::error("route parameter required", 400, 'MISSING_PARAM');

    // Redirect to existing resolve endpoint
    $params = http_build_query(array_filter([
        'route_string' => $route,
        'origin' => $origin,
        'dest' => $dest,
        'format' => swim_get_param('format'),
    ]));
    header('Location: /api/swim/v1/routes/resolve?' . $params, true, 307);
    exit;
}

/**
 * Vincenty distance formula (meters)
 */
function vincentyDistance($lat1, $lon1, $lat2, $lon2) {
    $a = 6378137.0;
    $f = 1 / 298.257223563;
    $b = $a * (1 - $f);

    $lat1 = deg2rad($lat1); $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2); $lon2 = deg2rad($lon2);

    $U1 = atan((1 - $f) * tan($lat1));
    $U2 = atan((1 - $f) * tan($lat2));
    $L = $lon2 - $lon1;
    $lambda = $L;

    $sinU1 = sin($U1); $cosU1 = cos($U1);
    $sinU2 = sin($U2); $cosU2 = cos($U2);

    for ($i = 0; $i < 100; $i++) {
        $sinLam = sin($lambda); $cosLam = cos($lambda);
        $sinSig = sqrt(pow($cosU2 * $sinLam, 2) + pow($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLam, 2));
        if ($sinSig == 0) return 0;
        $cosSig = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLam;
        $sigma = atan2($sinSig, $cosSig);
        $sinAlpha = $cosU1 * $cosU2 * $sinLam / $sinSig;
        $cos2Alpha = 1 - $sinAlpha * $sinAlpha;
        $cos2SigM = ($cos2Alpha != 0) ? $cosSig - 2 * $sinU1 * $sinU2 / $cos2Alpha : 0;
        $C = $f / 16 * $cos2Alpha * (4 + $f * (4 - 3 * $cos2Alpha));
        $prev = $lambda;
        $lambda = $L + (1 - $C) * $f * $sinAlpha * ($sigma + $C * $sinSig * ($cos2SigM + $C * $cosSig * (-1 + 2 * $cos2SigM * $cos2SigM)));
        if (abs($lambda - $prev) < 1e-12) break;
    }

    $u2 = $cos2Alpha * ($a * $a - $b * $b) / ($b * $b);
    $A = 1 + $u2 / 16384 * (4096 + $u2 * (-768 + $u2 * (320 - 175 * $u2)));
    $B = $u2 / 1024 * (256 + $u2 * (-128 + $u2 * (74 - 47 * $u2)));
    $deltaSig = $B * $sinSig * ($cos2SigM + $B / 4 * ($cosSig * (-1 + 2 * $cos2SigM * $cos2SigM) - $B / 6 * $cos2SigM * (-3 + 4 * $sinSig * $sinSig) * (-3 + 4 * $cos2SigM * $cos2SigM)));

    return $b * $A * ($sigma - $deltaSig);
}

/**
 * Initial bearing (degrees)
 */
function initialBearing($lat1, $lon1, $lat2, $lon2) {
    $lat1 = deg2rad($lat1); $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2); $lon2 = deg2rad($lon2);
    $dLon = $lon2 - $lon1;
    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}
