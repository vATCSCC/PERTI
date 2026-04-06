<?php
/**
 * VATSWIM API v1 - Facility Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/facilities/centers                  - List all ARTCCs
 *   GET /reference/facilities/centers/{code}           - Center detail
 *   GET /reference/facilities/centers/{code}/tiers     - Adjacency tiers
 *   GET /reference/facilities/centers/{code}/sectors   - Sectors in center
 *   GET /reference/facilities/tracons                  - List all TRACONs
 *   GET /reference/facilities/tracons/{code}           - TRACON detail + airports
 *   GET /reference/facilities/dcc-regions              - DCC region listing
 *   GET /reference/facilities/lists/{name}             - Curated airport lists
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/facilities/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;
$code = isset($path_parts[1]) ? strtoupper(trim($path_parts[1])) : null;
$action = $path_parts[2] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_facilities',
    'item' => 'facility',
    'name' => 'VATSWIM Facility Reference',
    'filename' => 'swim_facilities_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub, 'code' => $code, 'action' => $action,
    'artcc' => swim_get_param('artcc'), 'strata' => swim_get_param('strata'),
    'depth' => swim_get_param('depth'),
    'include' => swim_get_param('include'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_facility', $cache_params, $format, $format_options)) {
    exit;
}

switch ($sub) {
    case 'centers':
        if ($code && $action === 'tiers') {
            handleCenterTiers($code, $format, $cache_params, $format_options);
        } elseif ($code && $action === 'sectors') {
            handleCenterSectors($code, $format, $cache_params, $format_options);
        } elseif ($code) {
            handleCenterDetail($code, $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleCenterList($include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'tracons':
        if ($code) {
            handleTraconDetail($code, $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleTraconList($include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'dcc-regions':
        handleDccRegions($format, $cache_params, $format_options);
        break;

    case 'lists':
        handleCuratedList($code ? strtolower($code) : null, $format, $cache_params, $format_options);
        break;

    default:
        SwimResponse::error("Unknown facilities sub-resource: $sub. Use 'centers', 'tracons', 'dcc-regions', or 'lists'.", 400, 'INVALID_RESOURCE');
}

function handleCenterList($include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT artcc_code, artcc_name, hierarchy_type, is_oceanic $geom
            FROM artcc_boundaries ORDER BY artcc_code";
    $stmt = $conn->query($sql);
    $centers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($centers as &$c) {
        if (isset($c['geometry'])) $c['geometry'] = json_decode($c['geometry'], true);
    }

    SwimResponse::formatted([
        'centers' => $centers,
        'count' => count($centers),
    ], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCenterDetail($code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT artcc_code, artcc_name, hierarchy_type, is_oceanic $geom
            FROM artcc_boundaries WHERE artcc_code = :code LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $center = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$center) SwimResponse::error("Center not found: $code", 404, 'NOT_FOUND');
    if (isset($center['geometry'])) $center['geometry'] = json_decode($center['geometry'], true);

    // Count children
    $tracon_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM tracon_boundaries WHERE parent_artcc = :code");
    $tracon_stmt->execute([':code' => $code]);
    $center['total_tracons'] = (int)($tracon_stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $sector_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sector_boundaries WHERE parent_artcc = :code");
    $sector_stmt->execute([':code' => $code]);
    $center['total_sectors'] = (int)($sector_stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    SwimResponse::formatted(['center' => $center], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCenterTiers($code, $format, $cache_params, $format_options) {
    $depth = swim_get_int_param('depth', 1, 1, 4);

    $gis = GISService::getInstance();
    if (!$gis) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $tiers = $gis->getProximityTiers('ARTCC', $code, (float)$depth, true);

    // Group by tier
    $grouped = [];
    foreach ($tiers as $t) {
        $tier_key = (string)$t['tier'];
        if (!isset($grouped[$tier_key])) $grouped[$tier_key] = [];
        $grouped[$tier_key][] = $t;
    }

    SwimResponse::formatted([
        'center' => $code,
        'max_depth' => $depth,
        'tiers' => $grouped,
        'total_neighbors' => count($tiers),
    ], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCenterSectors($code, $format, $cache_params, $format_options) {
    $strata = swim_get_param('strata');
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $where = ["parent_artcc = :code"];
    $params = [':code' => $code];
    if ($strata) { $where[] = "sector_type = :strata"; $params[':strata'] = strtoupper($strata); }

    $sql = "SELECT sector_code, sector_name, sector_type, floor_fl, ceiling_fl
            FROM sector_boundaries WHERE " . implode(' AND ', $where) . "
            ORDER BY sector_type, sector_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $sectors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    SwimResponse::formatted([
        'center' => $code,
        'sectors' => $sectors,
        'count' => count($sectors),
    ], $format, 'reference_facility', $cache_params, $format_options);
}

function handleTraconList($include_geometry, $format, $cache_params, $format_options) {
    $artcc = swim_get_param('artcc');
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $where = [];
    $params = [];
    if ($artcc) { $where[] = "parent_artcc = :artcc"; $params[':artcc'] = strtoupper($artcc); }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT tracon_code, tracon_name, parent_artcc $geom
            FROM tracon_boundaries $where_sql ORDER BY tracon_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $tracons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tracons as &$t) {
        if (isset($t['geometry'])) $t['geometry'] = json_decode($t['geometry'], true);
    }

    SwimResponse::formatted(['tracons' => $tracons, 'count' => count($tracons)], $format, 'reference_facility', $cache_params, $format_options);
}

function handleTraconDetail($code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT tracon_code, tracon_name, parent_artcc $geom
            FROM tracon_boundaries WHERE tracon_code = :code LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $tracon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracon) SwimResponse::error("TRACON not found: $code", 404, 'NOT_FOUND');
    if (isset($tracon['geometry'])) $tracon['geometry'] = json_decode($tracon['geometry'], true);

    // Get airports within this TRACON via spatial containment
    $apt_sql = "SELECT a.icao_code, a.faa_lid, a.name
                FROM airports a, tracon_boundaries t
                WHERE t.tracon_code = :code
                AND ST_Contains(t.geom, a.geom)
                ORDER BY a.icao_code";
    $apt_stmt = $conn->prepare($apt_sql);
    $apt_stmt->execute([':code' => $code]);
    $airports = $apt_stmt->fetchAll(PDO::FETCH_ASSOC);

    $tracon['airports'] = $airports;
    $tracon['airport_count'] = count($airports);

    SwimResponse::formatted(['tracon' => $tracon], $format, 'reference_facility', $cache_params, $format_options);
}

function handleDccRegions($format, $cache_params, $format_options) {
    $hierarchy_file = __DIR__ . '/../../../../assets/data/hierarchy.json';
    if (!file_exists($hierarchy_file)) {
        SwimResponse::error('Hierarchy data not available', 503, 'SERVICE_UNAVAILABLE');
    }

    $data = json_decode(file_get_contents($hierarchy_file), true);
    $regions = [];

    // Extract DCC regions from VATUSA
    foreach ($data['regions'] ?? [] as $region) {
        foreach ($region['divisions'] ?? [] as $div) {
            if (!empty($div['dcc_regions'])) {
                foreach ($div['dcc_regions'] as $dcc) {
                    $regions[] = [
                        'code' => $dcc['code'],
                        'name' => $dcc['name'],
                        'division' => $div['code'],
                        'centers' => $dcc['centers'],
                        'center_count' => count($dcc['centers']),
                    ];
                }
            }
        }
    }

    SwimResponse::formatted(['dcc_regions' => $regions, 'count' => count($regions)], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCuratedList($name, $format, $cache_params, $format_options) {
    if (!$name) {
        SwimResponse::error('List name required. Valid: oep35, core30, aspm82, opsnet45', 400, 'MISSING_PARAM');
    }

    $hierarchy_file = __DIR__ . '/../../../../assets/data/hierarchy.json';
    if (!file_exists($hierarchy_file)) {
        SwimResponse::error('List data not available', 503, 'SERVICE_UNAVAILABLE');
    }

    $data = json_decode(file_get_contents($hierarchy_file), true);
    $list_key = strtolower($name);

    if (!isset($data['curated_lists'][$list_key])) {
        SwimResponse::error("Unknown list: $name. Valid: oep35, core30, aspm82, opsnet45", 404, 'NOT_FOUND');
    }

    $list = $data['curated_lists'][$list_key];

    SwimResponse::formatted([
        'list' => $list,
        'count' => count($list['airports']),
    ], $format, 'reference_facility', $cache_params, $format_options);
}
