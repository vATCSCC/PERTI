<?php
/**
 * VATSWIM API v1 — Batch Route Traversal Lookup
 *
 * Returns L1 ARTCC/FIR traversal, TRACONs, sectors, geometry, distance,
 * and waypoints for one or more route strings. Used by CTP Route Planner
 * to auto-populate facility fields before saving.
 *
 * POST /api/swim/v1/playbook/traversal
 *   Body: { "routes": ["VESMI 6050N ..."], "fields": ["artccs"] }
 *
 * ARTCCs returned are L1 only — PostGIS artcc_boundaries stores only
 * top-level ARTCC/FIR boundaries (no sub-areas, deep-sub-areas, or
 * supercenters).
 *
 * @version 1.0.0
 */

// Bootstrap: Need PostGIS via computeTraversedFacilities(),
// which lazy-loads get_conn_gis() internally.
// Load config + connect BEFORE auth so PostGIS getter is available.
require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';
require_once __DIR__ . '/../../mgt/playbook/playbook_helpers.php';
require_once __DIR__ . '/../auth.php';

// Auth: any tier can read (no write authority required)
$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { SwimResponse::handlePreflight(); }
if ($method !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// ── Parse request ─────────────────────────────────────────────────────
$body = swim_get_json_body();
if (!$body || !is_array($body)) {
    SwimResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$routes = $body['routes'] ?? null;
$fields = $body['fields'] ?? null; // null = all fields

if (!is_array($routes) || empty($routes)) {
    SwimResponse::error('routes array is required and must be non-empty', 400, 'MISSING_PARAM');
}
if (count($routes) > 100) {
    SwimResponse::error('Maximum 100 routes per request', 400, 'BATCH_LIMIT');
}

// Validate fields filter
$valid_fields = ['artccs', 'tracons', 'sectors', 'geometry', 'distance', 'waypoints'];
if ($fields !== null) {
    if (!is_array($fields)) {
        SwimResponse::error('fields must be an array', 400, 'INVALID_PARAM');
    }
    foreach ($fields as $f) {
        if (!in_array($f, $valid_fields)) {
            SwimResponse::error("Invalid field '{$f}'. Valid: " . implode(', ', $valid_fields), 400, 'INVALID_PARAM');
        }
    }
}

$include_all = ($fields === null);

// ── Process routes ────────────────────────────────────────────────────
$results = [];
foreach ($routes as $rs) {
    if (!is_string($rs) || trim($rs) === '') {
        $results[] = ['route_string' => (string)$rs, 'error' => 'Empty route string'];
        continue;
    }

    $rs_clean = trim($rs);

    try {
        // computeTraversedFacilities returns L1 ARTCCs from PostGIS
        // artcc_boundaries (already filtered — no sub-areas/supercenters).
        // ARTCC codes are normalized via ArtccNormalizer (K-prefix stripped,
        // Canadian aliases resolved, UNKN/VARIOUS filtered).
        $tf = computeTraversedFacilities($rs_clean, '', '', '', '', '', '');

        $result = ['route_string' => $rs_clean];

        // ARTCCs (L1 ARTCC/FIR codes only)
        if ($include_all || in_array('artccs', $fields)) {
            $result['artccs'] = array_values(array_filter(
                explode(',', $tf['artccs']),
                function($v) { return trim($v) !== ''; }
            ));
        }

        // TRACONs
        if ($include_all || in_array('tracons', $fields)) {
            $result['tracons'] = array_values(array_filter(
                explode(',', $tf['tracons']),
                function($v) { return trim($v) !== ''; }
            ));
        }

        // Sectors (grouped by type)
        if ($include_all || in_array('sectors', $fields)) {
            $result['sectors'] = [
                'low'       => array_values(array_filter(explode(',', $tf['sectors_low']), function($v) { return trim($v) !== ''; })),
                'high'      => array_values(array_filter(explode(',', $tf['sectors_high']), function($v) { return trim($v) !== ''; })),
                'superhigh' => array_values(array_filter(explode(',', $tf['sectors_superhigh']), function($v) { return trim($v) !== ''; })),
            ];
        }

        // Extract geometry envelope fields from route_geometry JSON
        $geom_data = null;
        if ($tf['route_geometry']) {
            $geom_data = json_decode($tf['route_geometry'], true);
        }

        if ($include_all || in_array('distance', $fields)) {
            $result['distance_nm'] = ($geom_data && isset($geom_data['distance_nm']))
                ? $geom_data['distance_nm'] : null;
        }

        if ($include_all || in_array('waypoints', $fields)) {
            $result['waypoints'] = ($geom_data && isset($geom_data['waypoints']))
                ? $geom_data['waypoints'] : [];
        }

        if ($include_all || in_array('geometry', $fields)) {
            $result['geometry'] = ($geom_data && isset($geom_data['geojson']))
                ? $geom_data['geojson'] : null;
        }

        $results[] = $result;
    } catch (\Exception $e) {
        $results[] = [
            'route_string' => $rs_clean,
            'artccs' => [],
            'error' => 'Processing failed: ' . $e->getMessage(),
        ];
    }
}

SwimResponse::success([
    'count'   => count($results),
    'results' => $results,
]);
