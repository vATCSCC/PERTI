<?php
/**
 * VATSWIM API v1 - Playbook Plays Endpoint
 *
 * Read-only access to playbook plays and their routes.
 * Data from SWIM_API database.
 *
 * GET /api/swim/v1/playbook/plays              - List plays (paginated)
 * GET /api/swim/v1/playbook/plays?id=123        - Get single play with routes (by ID)
 * GET /api/swim/v1/playbook/plays?name=ORD+EAST+1 - Get single play with routes (by name)
 * GET /api/swim/v1/playbook/plays?category=...   - Filter by category
 * GET /api/swim/v1/playbook/plays?source=...     - Filter by source
 * GET /api/swim/v1/playbook/plays?search=...     - Search play name
 * GET /api/swim/v1/playbook/plays?artcc=...      - Filter by ARTCC in facilities_involved (aliases: fir, acc)
 * GET /api/swim/v1/playbook/plays?status=...     - Filter by status (active/draft/archived)
 * GET /api/swim/v1/playbook/plays?format=geojson - GeoJSON output
 * GET /api/swim/v1/playbook/plays?include=geometry - Add route geometry via PostGIS
 *
 * @version 1.3.0
 * @since 2026-03-14
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/playbook_visibility.php';
require_once __DIR__ . '/../../../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Only GET is supported
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// Public endpoint -- auth is optional
swim_init_auth(false, false);

// MySQL connection (needed for ACL/visibility checks on non-public plays)
global $conn_sqli;

// SWIM-only: query swim_playbook_plays in SWIM_API
$conn_swim_api = get_conn_swim();
if (!$conn_swim_api) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$id   = swim_get_param('id');
$name = swim_get_param('name');

if ($id) {
    handleGetSingle((int)$id);
} elseif ($name !== null && $name !== '') {
    $resolved_id = resolvePlayIdByName(trim($name));
    if ($resolved_id === null) {
        SwimResponse::error('Play not found with name: ' . $name, 404, 'NOT_FOUND');
    }
    handleGetSingle($resolved_id);
} else {
    handleGetList();
}

// ============================================================================
// GET - List Plays (Paginated)
// ============================================================================
function handleGetList(): void {
    global $conn_sqli, $conn_swim_api;

    $auth = tryOptionalAuth();

    $category = swim_get_param('category');
    $source   = swim_get_param('source');
    $search   = swim_get_param('search');
    $artcc    = swim_get_param('artcc') ?? swim_get_param('fir') ?? swim_get_param('acc');
    $status   = swim_get_param('status');
    $format   = swim_get_param('format', 'json');
    $page     = swim_get_int_param('page', 1, 1, 1000);
    $per_page = swim_get_int_param('per_page', 50, 1, 200);
    $offset   = ($page - 1) * $per_page;

    $api_cid  = resolveApiKeyCid($auth);
    $is_admin = ($api_cid !== null && $conn_sqli) ? checkIsAdmin($api_cid, $conn_sqli) : false;

    // SWIM path: sqlsrv against swim_playbook_plays
    $where  = [];
    $params = [];

    // Visibility (ACL post-filtered in PHP via MySQL)
    if (!$is_admin) {
        if ($api_cid === null) {
            $where[] = "visibility = 'public'";
        } else {
            $where[] = "(visibility = 'public' OR (visibility = 'local' AND created_by = ?) OR visibility IN ('private_users','private_org'))";
            $params[] = (string)$api_cid;
        }
    }

    if ($status !== null && $status !== '') {
        $where[] = "status = ?";
        $params[] = $status;
    } else {
        $where[] = "status != 'archived'";
    }

    if ($category !== null && $category !== '') {
        $where[] = "category = ?";
        $params[] = $category;
    }

    if ($source !== null && $source !== '') {
        $where[] = "source = ?";
        $params[] = $source;
    }

    if ($search !== null && $search !== '') {
        $where[] = "(play_name LIKE '%' + ? + '%' OR display_name LIKE '%' + ? + '%' OR description LIKE '%' + ? + '%')";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    if ($artcc !== null && $artcc !== '') {
        $artcc_val = strtoupper(normalizeArtccAlias($artcc));
        $where[] = "CHARINDEX(',' + ? + ',', ',' + ISNULL(facilities_involved,'') + ',') > 0";
        $params[] = $artcc_val;
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.swim_playbook_plays $where_sql";
    $count_stmt = sqlsrv_query($conn_swim_api, $count_sql, $params);
    if ($count_stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database error (count): ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }
    $total = (int)sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
    sqlsrv_free_stmt($count_stmt);

    if ($total === 0) {
        outputResponse([], 0, $page, $per_page, $format);
        return;
    }

    // Fetch paginated data
    $data_sql = "SELECT play_id, play_name, play_name_norm, display_name,
                        description, category, impacted_area, facilities_involved,
                        scenario_type, route_format, source, status,
                        airac_cycle, route_count, org_code, visibility,
                        created_by, updated_by, updated_at, created_at
                 FROM dbo.swim_playbook_plays
                 $where_sql
                 ORDER BY source ASC, play_name ASC
                 OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $data_params = array_merge($params, [$offset, $per_page]);
    $data_stmt = sqlsrv_query($conn_swim_api, $data_sql, $data_params);
    if ($data_stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($data_stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($data_stmt);

    // Post-filter non-public visibility using MySQL ACL
    $filtered = [];
    foreach ($rows as $row) {
        $vis = $row['visibility'] ?? 'public';
        if (($vis === 'private_users' || $vis === 'private_org') && !$is_admin) {
            if ($api_cid === null || !$conn_sqli || !can_cid_view_play($row, $api_cid, $conn_sqli, false)) {
                continue;
            }
        }
        $filtered[] = formatPlay($row);
    }

    $removed = count($rows) - count($filtered);
    if ($removed > 0) {
        $total = max(0, $total - $removed);
    }

    outputResponse($filtered, $total, $page, $per_page, $format);
}

// ============================================================================
// GET - Single Play with Routes
// ============================================================================
function handleGetSingle(int $id): void {
    global $conn_sqli, $conn_swim_api;

    $auth = tryOptionalAuth();

    $format  = swim_get_param('format', 'json');
    $include = swim_get_param('include');
    $include_geometry = false;
    if ($include !== null && $include !== '') {
        $include_parts = array_map('trim', explode(',', strtolower($include)));
        $include_geometry = in_array('geometry', $include_parts, true);
    }

    $api_cid  = resolveApiKeyCid($auth);
    $is_admin = ($api_cid !== null && $conn_sqli) ? checkIsAdmin($api_cid, $conn_sqli) : false;

    // SWIM path: sqlsrv against swim_playbook_plays
    $play_sql = "SELECT play_id, play_name, play_name_norm, display_name,
                        description, category, impacted_area, facilities_involved,
                        scenario_type, route_format, source, status,
                        airac_cycle, route_count, org_code, visibility,
                        created_by, updated_by, updated_at, created_at
                 FROM dbo.swim_playbook_plays WHERE play_id = ?";
    $play_stmt = sqlsrv_query($conn_swim_api, $play_sql, [$id]);
    if ($play_stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }
    $play = sqlsrv_fetch_array($play_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($play_stmt);

    if (!$play) {
        SwimResponse::error('Play not found', 404, 'NOT_FOUND');
    }

    // Visibility check (ACL via MySQL)
    $visibility = $play['visibility'] ?? 'public';
    if ($visibility !== 'public') {
        if ($api_cid === null) {
            SwimResponse::error('Authentication required for non-public plays', 401, 'UNAUTHORIZED');
        }
        if (!$conn_sqli || !can_cid_view_play($play, $api_cid, $conn_sqli, $is_admin)) {
            SwimResponse::error('Access denied to this play', 403, 'FORBIDDEN');
        }
    }

    // Fetch routes
    $route_sql = "SELECT route_id, route_string, origin, origin_filter, dest, dest_filter,
                         origin_airports, origin_tracons, origin_artccs,
                         dest_airports, dest_tracons, dest_artccs,
                         traversed_artccs, traversed_tracons,
                         traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
                         route_geometry, remarks, sort_order
                  FROM dbo.swim_playbook_routes
                  WHERE play_id = ?
                  ORDER BY sort_order ASC";
    $route_stmt = sqlsrv_query($conn_swim_api, $route_sql, [$id]);
    if ($route_stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database error (routes): ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $routes = [];
    while ($r = sqlsrv_fetch_array($route_stmt, SQLSRV_FETCH_ASSOC)) {
        $routes[] = formatRoute($r);
    }
    sqlsrv_free_stmt($route_stmt);

    $formatted = formatPlay($play);
    $formatted['routes'] = $routes;

    // Optionally expand route geometry via PostGIS
    if ($include_geometry && !empty($routes)) {
        $formatted['routes'] = expandPlaybookRouteGeometry($formatted['routes']);
    }

    if ($format === 'geojson') {
        outputGeoJSON($formatted, $include_geometry);
        return;
    }

    SwimResponse::success($formatted);
}

// ============================================================================
// Formatting Helpers
// ============================================================================

/**
 * Format a play row for the API response.
 */
function formatPlay(array $row): array {
    return [
        'play_id'             => (int)$row['play_id'],
        'play_name'           => $row['play_name'],
        'display_name'        => $row['display_name'] ?: null,
        'description'         => $row['description'] ?: null,
        'category'            => $row['category'] ?: null,
        'impacted_area'       => !empty($row['impacted_area']) ? ArtccNormalizer::toL1Csv($row['impacted_area'], '/') : null,
        'facilities_involved' => !empty($row['facilities_involved']) ? ArtccNormalizer::toL1Csv($row['facilities_involved'], ',') : null,
        'scenario_type'       => $row['scenario_type'] ?: null,
        'route_format'        => $row['route_format'] ?? 'standard',
        'source'              => $row['source'] ?? 'DCC',
        'status'              => $row['status'] ?? 'active',
        'airac_cycle'         => $row['airac_cycle'] ?: null,
        'route_count'         => (int)($row['route_count'] ?? 0),
        'visibility'          => $row['visibility'] ?? 'public',
        'metadata'            => [
            'created_by' => $row['created_by'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'created_at' => _formatDateVal($row['created_at'] ?? null),
            'updated_at' => _formatDateVal($row['updated_at'] ?? null),
        ],
    ];
}

/**
 * Format a date value from either MySQL (string) or sqlsrv (DateTime object).
 */
function _formatDateVal($val): ?string {
    if ($val === null) return null;
    if ($val instanceof \DateTime) return $val->format('Y-m-d H:i:s');
    return (string)$val;
}

/**
 * Format a route row for the API response.
 */
function formatRoute(array $r): array {
    // Parse frozen geometry envelope if available
    $frozen = null;
    if (!empty($r['route_geometry'])) {
        $decoded = json_decode($r['route_geometry'], true);
        // Support both envelope format and legacy bare GeoJSON
        if ($decoded && isset($decoded['geojson'])) {
            $frozen = $decoded;  // New envelope format
        } elseif ($decoded && isset($decoded['type'])) {
            $frozen = ['geojson' => $decoded];  // Legacy bare GeoJSON
        }
    }

    return [
        'route_id'     => (int)$r['route_id'],
        'route_string' => $r['route_string'],
        'origin'       => $r['origin'] ?: null,
        'origin_filter'=> $r['origin_filter'] ?: null,
        'dest'         => $r['dest'] ?: null,
        'dest_filter'  => $r['dest_filter'] ?: null,
        'scope'        => [
            'origin_airports'  => csvToArray($r['origin_airports'] ?? ''),
            'origin_tracons'   => csvToArray($r['origin_tracons'] ?? ''),
            'origin_artccs'    => csvToArray($r['origin_artccs'] ?? ''),
            'dest_airports'    => csvToArray($r['dest_airports'] ?? ''),
            'dest_tracons'     => csvToArray($r['dest_tracons'] ?? ''),
            'dest_artccs'      => csvToArray($r['dest_artccs'] ?? ''),
        ],
        'traversal'    => [
            'artccs'           => csvToArray(ArtccNormalizer::toL1Csv($r['traversed_artccs'] ?? '', ',')),
            'tracons'          => csvToArray($r['traversed_tracons'] ?? ''),
            'sectors_low'      => csvToArray($r['traversed_sectors_low'] ?? ''),
            'sectors_high'     => csvToArray($r['traversed_sectors_high'] ?? ''),
            'sectors_superhigh'=> csvToArray($r['traversed_sectors_superhigh'] ?? ''),
        ],
        'geometry'     => $frozen ? ($frozen['geojson'] ?? null) : null,
        'waypoints'    => $frozen ? ($frozen['waypoints'] ?? null) : null,
        'distance_nm'  => $frozen ? ($frozen['distance_nm'] ?? null) : null,
        'remarks'      => $r['remarks'] ?: null,
        'sort_order'   => (int)($r['sort_order'] ?? 0),
    ];
}

/**
 * Convert a CSV string to an array of trimmed, non-empty values.
 */
function csvToArray(string $csv): array {
    if (trim($csv) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $csv)), function ($v) {
        return $v !== '';
    }));
}

// ============================================================================
// Output Helpers
// ============================================================================

/**
 * Send a paginated list response.
 */
function outputResponse(array $plays, int $total, int $page, int $per_page, string $format): void {
    if ($format === 'geojson') {
        outputGeoJSONCollection($plays);
        return;
    }

    SwimResponse::paginated($plays, $total, $page, $per_page);
}

/**
 * Output GeoJSON for a single play. If geometry was expanded, each route
 * already has a 'geometry' key; otherwise routes get Point features from
 * origin/dest airports.
 */
function outputGeoJSON(array $play, bool $hasGeometry = false): void {
    $features = [];

    foreach ($play['routes'] ?? [] as $route) {
        $geom = $route['geometry'] ?? null;
        if ($geom) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geom,
                'properties' => [
                    'route_id'     => $route['route_id'],
                    'route_string' => $route['route_string'],
                    'origin'       => $route['origin'],
                    'dest'         => $route['dest'],
                    'distance_nm'  => $route['distance_nm'] ?? null,
                ],
            ];
        }
    }

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'generated' => gmdate('c'),
            'play_id'   => $play['play_id'],
            'play_name' => $play['play_name'],
            'count'     => count($features),
            'source'    => 'perti_playbook',
        ],
    ];

    if (empty($features) && !$hasGeometry) {
        $geojson['metadata']['note'] = 'Add include=geometry to populate route geometries.';
    }

    header('Content-Type: application/geo+json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Output a GeoJSON FeatureCollection for a list of plays (list mode).
 */
function outputGeoJSONCollection(array $plays): void {
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => [],
        'metadata' => [
            'generated' => gmdate('c'),
            'note'      => 'Use ?id=<play_id>&format=geojson&include=geometry for route geometries.',
            'count'     => 0,
            'source'    => 'perti_playbook',
        ],
    ];

    header('Content-Type: application/geo+json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// Geometry Expansion
// ============================================================================

/**
 * Expand playbook routes with GeoJSON geometry via PostGIS GISService.
 * Adds geometry, waypoints, distance_nm, artccs_traversed to each route.
 */
function expandPlaybookRouteGeometry(array $routes): array {
    // Identify routes that need live PostGIS expansion (no frozen geometry)
    $needsExpansion = [];
    $expansionIndices = [];
    foreach ($routes as $idx => $route) {
        if ($route['geometry'] === null) {
            $needsExpansion[] = $route['route_string'];
            $expansionIndices[] = $idx;
        }
    }

    // All routes have frozen geometry -- no PostGIS needed
    if (empty($needsExpansion)) {
        return $routes;
    }

    // Only expand the routes that lack frozen geometry
    require_once __DIR__ . '/../../../../load/services/GISService.php';

    $gis = GISService::getInstance();
    if (!$gis) {
        // GIS unavailable -- routes without frozen geometry keep null fields (already set by formatRoute)
        return $routes;
    }

    $expanded = $gis->expandRoutesBatch($needsExpansion);

    // Index by route string for lookup
    $geoByRoute = [];
    foreach ($expanded as $result) {
        $geoByRoute[$result['route']] = $result;
    }

    // Merge geometry only into routes that needed expansion
    foreach ($expansionIndices as $idx) {
        $route = &$routes[$idx];
        $geo = $geoByRoute[$route['route_string']] ?? null;
        if ($geo && $geo['geojson'] && empty($geo['error'])) {
            $route['geometry'] = $geo['geojson'];
            $route['waypoints'] = extractWaypoints($geo);
            $route['distance_nm'] = $geo['distance_nm'];

            // Populate traversal.artccs from GIS if static data is empty
            if (empty($route['traversal']['artccs']) && !empty($geo['artccs'])) {
                $route['traversal']['artccs'] = $geo['artccs'];
            }
        }
    }

    return $routes;
}

/**
 * Extract waypoints as lat/lon objects from GeoJSON coordinates.
 */
function extractWaypoints(array $geo): ?array {
    if (!$geo['geojson'] || !isset($geo['geojson']['coordinates'])) {
        return null;
    }

    $coords = $geo['geojson']['coordinates'];
    $waypoints = [];
    foreach ($coords as $coord) {
        $waypoints[] = [
            'lat' => round($coord[1], 6),
            'lon' => round($coord[0], 6),
        ];
    }
    return $waypoints;
}

// ============================================================================
// Auth / Visibility Helpers
// ============================================================================

/**
 * Attempt SWIM authentication without requiring it.
 *
 * If an API key is present (Authorization header or X-API-Key), validates it
 * and returns the SwimAuth instance. If no key is present, returns null.
 * Unlike swim_init_auth(false), this actually validates the key when present,
 * which allows us to resolve the owner CID for visibility filtering.
 *
 * @return SwimAuth|null Authenticated auth object, or null for anonymous access
 */
function tryOptionalAuth(): ?SwimAuth {
    global $conn_swim;

    SwimResponse::handlePreflight();

    // Check if any auth credentials are present
    $has_auth = !empty($_SERVER['HTTP_AUTHORIZATION'])
             || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
             || !empty($_SERVER['HTTP_X_API_KEY']);

    if (!$has_auth && function_exists('getallheaders')) {
        $headers = getallheaders();
        if ($headers !== false) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization' && !empty($value)) {
                    $has_auth = true;
                    break;
                }
            }
        }
    }

    if (!$has_auth) {
        return null;
    }

    if (!$conn_swim) {
        return null;
    }

    $conn = $conn_swim;

    $auth = new SwimAuth($conn);
    if (!$auth->authenticate()) {
        // Key was provided but invalid -- still allow public access
        return null;
    }

    $key_info = $auth->getKeyInfo();
    SwimResponse::setTier($key_info['tier'] ?? 'public');

    return $auth;
}

/**
 * Resolve the CID of the API key owner, if authenticated.
 *
 * The SwimAuth class does not include owner_cid in its SELECT, so we query
 * it directly from swim_api_keys using the key's database ID.
 *
 * @param SwimAuth|null $auth Auth result (null if public access)
 * @return int|null Owner CID or null
 */
function resolveApiKeyCid(?SwimAuth $auth): ?int {
    if ($auth === null) {
        return null;
    }

    $key_info = $auth->getKeyInfo();
    if (!$key_info) {
        return null;
    }

    $key_id = $key_info['id'] ?? null;
    if ($key_id === null) {
        return null;
    }

    // Query owner_cid from the swim_api_keys table (not included in auth SELECT)
    global $conn_swim;
    if (!$conn_swim) {
        return null;
    }
    $conn = $conn_swim;

    $sql = "SELECT owner_cid FROM dbo.swim_api_keys WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$key_id]);
    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if ($row && $row['owner_cid'] !== null) {
        return (int)$row['owner_cid'];
    }

    return null;
}

/**
 * Check if a CID is in the admin_users table.
 */
function checkIsAdmin(int $cid, $conn): bool {
    $stmt = $conn->prepare("SELECT cid FROM admin_users WHERE cid = ?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $is_admin = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $is_admin;
}

/**
 * Resolve a play_id from a play name.
 * Searches SWIM_API first, falls back to MySQL.
 * Uses case-insensitive exact match against play_name.
 */
function resolvePlayIdByName(string $name): ?int {
    global $conn_swim_api;

    $stmt = sqlsrv_query($conn_swim_api,
        "SELECT play_id FROM dbo.swim_playbook_plays WHERE play_name = ? OR play_name_norm = ?",
        [$name, strtoupper(str_replace(' ', '_', $name))]
    );
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($row) return (int)$row['play_id'];
    }

    return null;
}

/**
 * Normalize ARTCC alias codes (FAA 3-letter to ICAO 4-letter for Canadian FIRs).
 */
function normalizeArtccAlias(string $code): string {
    // SWIM-specific aliases not in the shared normalizer:
    // - Reverse Canadian FIR suffixes (ZEG→CZEG etc.) for API consumers
    // - Mexican/Caribbean FIR codes
    static $swim_extras = [
        'ZEG' => 'CZEG', 'ZUL' => 'CZUL', 'ZVR' => 'CZVR',
        'ZWG' => 'CZWG', 'ZYZ' => 'CZYZ', 'ZQM' => 'CZQM',
        'ZQX' => 'CZQX', 'ZQO' => 'CZQO',
        'ZMX' => 'MMMX', 'ZMT' => 'MMTY', 'ZMZ' => 'MMZT',
        'ZMR' => 'MMMD', 'ZMC' => 'MMUN', 'ZSU' => 'TJZS',
    ];
    $normalized = ArtccNormalizer::normalize($code);
    return $swim_extras[$normalized] ?? $normalized;
}
