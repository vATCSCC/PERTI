<?php
/**
 * VATSWIM API v1 - Geographic Hierarchy Navigation
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/hierarchy                              - Entry point (regions)
 *   GET /reference/hierarchy/{type}/{code}                - Node detail + children
 *   GET /reference/hierarchy/{type}/{code}/children       - Children of specific type
 *   GET /reference/hierarchy/{type}/{code}/ancestors      - Parent chain to root
 *   GET /reference/hierarchy/search?q=...                 - Cross-level search
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/hierarchy/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_hierarchy',
    'item' => 'node',
    'name' => 'VATSWIM Geographic Hierarchy',
    'filename' => 'swim_hierarchy_' . date('Ymd_His')
];

$cache_params = array_filter([
    'path' => implode('/', $path_parts),
    'q' => swim_get_param('q'),
    'type' => swim_get_param('type'),
    'include' => swim_get_param('include'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_hierarchy', $cache_params, $format, $format_options)) {
    exit;
}

// Load hierarchy reference data
$hierarchy_file = __DIR__ . '/../../../../assets/data/hierarchy.json';
if (!file_exists($hierarchy_file)) {
    SwimResponse::error('Hierarchy data not available', 503, 'SERVICE_UNAVAILABLE');
}
$hierarchy_data = json_decode(file_get_contents($hierarchy_file), true);

// Route
if (empty($path_parts)) {
    handleRoot($hierarchy_data, $format, $cache_params, $format_options);
} elseif ($path_parts[0] === 'search') {
    handleHierarchySearch($hierarchy_data, $format, $cache_params, $format_options);
} elseif (count($path_parts) >= 2) {
    $type = $path_parts[0];
    $code = strtoupper($path_parts[1]);
    $action = $path_parts[2] ?? null;

    if ($action === 'children') {
        handleChildren($hierarchy_data, $type, $code, $format, $cache_params, $format_options);
    } elseif ($action === 'ancestors') {
        handleAncestors($hierarchy_data, $type, $code, $format, $cache_params, $format_options);
    } else {
        handleNode($hierarchy_data, $type, $code, $include_geometry, $format, $cache_params, $format_options);
    }
} else {
    SwimResponse::error('Specify a node type and code, or use /search', 400, 'MISSING_PARAM');
}

function handleRoot($data, $format, $cache_params, $format_options) {
    $roots = [];
    foreach ($data['regions'] as $region) {
        $roots[] = [
            'code' => $region['code'],
            'name' => $region['name'],
            'type' => 'region',
            'children_count' => count($region['divisions']),
        ];
    }

    SwimResponse::formatted([
        'levels' => ['region', 'division', 'dcc_region', 'center', 'tracon', 'airport', 'runway'],
        'roots' => $roots,
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleNode($data, $type, $code, $include_geometry, $format, $cache_params, $format_options) {
    switch ($type) {
        case 'region':
            $region = findRegion($data, $code);
            if (!$region) SwimResponse::error("Region not found: $code", 404, 'NOT_FOUND');
            $children = array_map(fn($d) => [
                'code' => $d['code'], 'name' => $d['name'], 'type' => 'division',
                'children_count' => count($d['dcc_regions'] ?? []) + count($d['centers'] ?? []),
            ], $region['divisions']);

            SwimResponse::formatted([
                'node' => ['code' => $region['code'], 'name' => $region['name'], 'type' => 'region'],
                'breadcrumb' => [],
                'children' => ['divisions' => $children],
            ], $format, 'reference_hierarchy', $cache_params, $format_options);
            break;

        case 'division':
            $result = findDivision($data, $code);
            if (!$result) SwimResponse::error("Division not found: $code", 404, 'NOT_FOUND');
            [$div, $parent_region] = $result;

            $children = [];
            if (!empty($div['dcc_regions'])) {
                $children['dcc_regions'] = array_map(fn($d) => [
                    'code' => $d['code'], 'name' => $d['name'], 'type' => 'dcc_region',
                    'children_count' => count($d['centers']),
                ], $div['dcc_regions']);
            }
            if (!empty($div['centers'])) {
                $children['centers'] = array_map(fn($c) => [
                    'code' => $c, 'type' => 'center',
                ], $div['centers']);
            }

            SwimResponse::formatted([
                'node' => ['code' => $div['code'], 'name' => $div['name'], 'type' => 'division'],
                'breadcrumb' => [['code' => $parent_region['code'], 'name' => $parent_region['name'], 'type' => 'region']],
                'children' => $children,
            ], $format, 'reference_hierarchy', $cache_params, $format_options);
            break;

        case 'center':
            handleCenterNode($data, $code, $include_geometry, $format, $cache_params, $format_options);
            break;

        case 'tracon':
            handleTraconNode($data, $code, $include_geometry, $format, $cache_params, $format_options);
            break;

        default:
            SwimResponse::error("Unsupported hierarchy type: $type", 400, 'INVALID_PARAM');
    }
}

function handleCenterNode($data, $code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $stmt = $conn->prepare("SELECT artcc_code, fir_name, hierarchy_type $geom FROM artcc_boundaries WHERE artcc_code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $center = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$center) SwimResponse::error("Center not found: $code", 404, 'NOT_FOUND');
    if (isset($center['geometry'])) $center['geometry'] = json_decode($center['geometry'], true);

    // Build breadcrumb from hierarchy data
    $breadcrumb = buildBreadcrumb($data, 'center', $code);

    // Get TRACONs
    $tracon_stmt = $conn->prepare("SELECT tracon_code, tracon_name FROM tracon_boundaries WHERE parent_artcc = :code ORDER BY tracon_code");
    $tracon_stmt->execute([':code' => $code]);
    $tracons = $tracon_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get sector count
    $sector_stmt = $conn->prepare("SELECT sector_type, COUNT(*) AS cnt FROM sector_boundaries WHERE parent_artcc = :code GROUP BY sector_type");
    $sector_stmt->execute([':code' => $code]);
    $sector_summary = [];
    while ($row = $sector_stmt->fetch(PDO::FETCH_ASSOC)) {
        $sector_summary[$row['sector_type']] = (int)$row['cnt'];
    }

    SwimResponse::formatted([
        'node' => [
            'code' => $center['artcc_code'],
            'name' => $center['fir_name'],
            'type' => 'center',
            'geometry' => $center['geometry'] ?? null,
            'detail_url' => "/api/swim/v1/reference/facilities/centers/$code",
        ],
        'breadcrumb' => $breadcrumb,
        'children' => [
            'tracons' => array_map(fn($t) => ['code' => $t['tracon_code'], 'name' => $t['tracon_name'], 'type' => 'tracon'], $tracons),
        ],
        'summary' => [
            'total_tracons' => count($tracons),
            'sectors' => $sector_summary,
        ],
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleTraconNode($data, $code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $stmt = $conn->prepare("SELECT tracon_code, tracon_name, parent_artcc $geom FROM tracon_boundaries WHERE tracon_code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $tracon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracon) SwimResponse::error("TRACON not found: $code", 404, 'NOT_FOUND');
    if (isset($tracon['geometry'])) $tracon['geometry'] = json_decode($tracon['geometry'], true);

    // Get airports within TRACON
    $apt_sql = "SELECT a.icao_id, a.arpt_id, a.arpt_name FROM airports a, tracon_boundaries t
                WHERE t.tracon_code = :code AND ST_Contains(t.geom, a.geom) ORDER BY a.icao_id";
    $apt_stmt = $conn->prepare($apt_sql);
    $apt_stmt->execute([':code' => $code]);
    $airports = $apt_stmt->fetchAll(PDO::FETCH_ASSOC);

    $breadcrumb = buildBreadcrumb($data, 'tracon', $code, $tracon['parent_artcc']);

    SwimResponse::formatted([
        'node' => [
            'code' => $tracon['tracon_code'],
            'name' => $tracon['tracon_name'],
            'type' => 'tracon',
            'parent_artcc' => $tracon['parent_artcc'],
            'geometry' => $tracon['geometry'] ?? null,
            'detail_url' => "/api/swim/v1/reference/facilities/tracons/$code",
        ],
        'breadcrumb' => $breadcrumb,
        'children' => [
            'airports' => array_map(fn($a) => [
                'code' => $a['icao_id'], 'faa_lid' => $a['arpt_id'], 'name' => $a['arpt_name'], 'type' => 'airport'
            ], $airports),
        ],
        'summary' => ['total_airports' => count($airports)],
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleHierarchySearch($data, $format, $cache_params, $format_options) {
    $q = swim_get_param('q');
    $type_filter = swim_get_param('type');

    if (!$q || strlen($q) < 2) SwimResponse::error('q parameter required (min 2 chars)', 400, 'MISSING_PARAM');

    $results = [];
    $q_upper = strtoupper($q);

    // Search static hierarchy (regions, divisions, DCC regions)
    if (!$type_filter || in_array($type_filter, ['region', 'division', 'dcc_region'])) {
        foreach ($data['regions'] as $region) {
            if ((!$type_filter || $type_filter === 'region') && (stripos($region['name'], $q) !== false || stripos($region['code'], $q_upper) !== false)) {
                $results[] = ['code' => $region['code'], 'name' => $region['name'], 'type' => 'region'];
            }
            foreach ($region['divisions'] as $div) {
                if ((!$type_filter || $type_filter === 'division') && (stripos($div['name'], $q) !== false || stripos($div['code'], $q_upper) !== false)) {
                    $results[] = ['code' => $div['code'], 'name' => $div['name'], 'type' => 'division'];
                }
                foreach ($div['dcc_regions'] ?? [] as $dcc) {
                    if ((!$type_filter || $type_filter === 'dcc_region') && (stripos($dcc['name'], $q) !== false || stripos($dcc['code'], $q_upper) !== false)) {
                        $results[] = ['code' => $dcc['code'], 'name' => $dcc['name'], 'type' => 'dcc_region'];
                    }
                }
            }
        }
    }

    // Search PostGIS for centers, TRACONs, airports
    $conn = get_conn_gis();
    if ($conn) {
        if (!$type_filter || $type_filter === 'center') {
            $stmt = $conn->prepare("SELECT artcc_code AS code, fir_name AS name FROM artcc_boundaries WHERE artcc_code ILIKE :q OR fir_name ILIKE :qw LIMIT 20");
            $stmt->execute([':q' => $q_upper . '%', ':qw' => '%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $results[] = array_merge($r, ['type' => 'center']); }
        }
        if (!$type_filter || $type_filter === 'tracon') {
            $stmt = $conn->prepare("SELECT tracon_code AS code, tracon_name AS name FROM tracon_boundaries WHERE tracon_code ILIKE :q OR tracon_name ILIKE :qw LIMIT 20");
            $stmt->execute([':q' => $q_upper . '%', ':qw' => '%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $results[] = array_merge($r, ['type' => 'tracon']); }
        }
        if (!$type_filter || $type_filter === 'airport') {
            $stmt = $conn->prepare("SELECT icao_id AS code, arpt_name AS name FROM airports WHERE icao_id ILIKE :q OR arpt_id ILIKE :q OR arpt_name ILIKE :qw LIMIT 20");
            $stmt->execute([':q' => $q_upper . '%', ':qw' => '%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $results[] = array_merge($r, ['type' => 'airport']); }
        }
    }

    SwimResponse::formatted([
        'query' => $q,
        'results' => $results,
        'count' => count($results),
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleChildren($data, $type, $code, $format, $cache_params, $format_options) {
    $child_type = swim_get_param('type');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    // For center -> airports (across all TRACONs)
    if ($type === 'center' && $child_type === 'airport') {
        $conn = get_conn_gis();
        if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

        $offset = ($page - 1) * $per_page;
        $sql = "SELECT a.icao_id, a.arpt_id, a.arpt_name
                FROM airports a, artcc_boundaries b
                WHERE b.artcc_code = :code AND ST_Contains(b.geom, a.geom)
                ORDER BY a.icao_id LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':code' => $code, ':limit' => $per_page, ':offset' => $offset]);
        $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        SwimResponse::formatted([
            'parent' => ['type' => $type, 'code' => $code],
            'child_type' => 'airport',
            'children' => $airports,
            'count' => count($airports),
        ], $format, 'reference_hierarchy', $cache_params, $format_options);
        return;
    }

    SwimResponse::error("Unsupported children query: $type/$code children of type $child_type", 400, 'INVALID_PARAM');
}

function handleAncestors($data, $type, $code, $format, $cache_params, $format_options) {
    $breadcrumb = [];

    if ($type === 'tracon') {
        $conn = get_conn_gis();
        if ($conn) {
            $stmt = $conn->prepare("SELECT parent_artcc FROM tracon_boundaries WHERE tracon_code = :code LIMIT 1");
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $breadcrumb = buildBreadcrumb($data, 'tracon', $code, $row['parent_artcc']);
        }
    } elseif ($type === 'center') {
        $breadcrumb = buildBreadcrumb($data, 'center', $code);
    }

    SwimResponse::formatted([
        'node' => ['type' => $type, 'code' => $code],
        'ancestors' => $breadcrumb,
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

// === HELPERS ===

function findRegion($data, $code) {
    foreach ($data['regions'] as $r) { if (strtoupper($r['code']) === $code) return $r; }
    return null;
}

function findDivision($data, $code) {
    foreach ($data['regions'] as $r) {
        foreach ($r['divisions'] as $d) {
            if (strtoupper($d['code']) === $code) return [$d, $r];
        }
    }
    return null;
}

function buildBreadcrumb($data, $type, $code, $parent_artcc = null) {
    $crumbs = [];

    // Find which division/region owns this center
    $center_code = ($type === 'center') ? $code : $parent_artcc;
    if (!$center_code) return $crumbs;

    foreach ($data['regions'] as $region) {
        foreach ($region['divisions'] as $div) {
            $all_centers = $div['centers'] ?? [];
            foreach ($div['dcc_regions'] ?? [] as $dcc) {
                $all_centers = array_merge($all_centers, $dcc['centers']);
            }
            if (in_array($center_code, $all_centers)) {
                $crumbs[] = ['code' => $region['code'], 'name' => $region['name'], 'type' => 'region'];
                $crumbs[] = ['code' => $div['code'], 'name' => $div['name'], 'type' => 'division'];

                foreach ($div['dcc_regions'] ?? [] as $dcc) {
                    if (in_array($center_code, $dcc['centers'])) {
                        $crumbs[] = ['code' => $dcc['code'], 'name' => $dcc['name'], 'type' => 'dcc_region'];
                        break;
                    }
                }

                if ($type === 'tracon' && $parent_artcc) {
                    $crumbs[] = ['code' => $parent_artcc, 'type' => 'center'];
                }

                return $crumbs;
            }
        }
    }

    return $crumbs;
}
