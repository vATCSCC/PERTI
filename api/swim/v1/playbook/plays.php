<?php
/**
 * VATSWIM API v1 - Playbook Plays Endpoint
 *
 * Read-only access to playbook plays and their routes.
 * Data from perti_site MySQL database (playbook_plays + playbook_routes tables).
 *
 * GET /api/swim/v1/playbook/plays              - List plays (paginated)
 * GET /api/swim/v1/playbook/plays?id=123        - Get single play with routes
 * GET /api/swim/v1/playbook/plays?category=...   - Filter by category
 * GET /api/swim/v1/playbook/plays?source=...     - Filter by source
 * GET /api/swim/v1/playbook/plays?search=...     - Search play name
 * GET /api/swim/v1/playbook/plays?artcc=...      - Filter by ARTCC in facilities_involved (aliases: fir, acc)
 * GET /api/swim/v1/playbook/plays?status=...     - Filter by status (active/draft/archived)
 * GET /api/swim/v1/playbook/plays?format=geojson - GeoJSON output (stub)
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/playbook_visibility.php';

// MySQL connection (playbook data is in perti_site)
global $conn_sqli;

if (!$conn_sqli) {
    SwimResponse::error('MySQL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

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

$id = swim_get_param('id');

if ($id) {
    handleGetSingle((int)$id);
} else {
    handleGetList();
}

// ============================================================================
// GET - List Plays (Paginated)
// ============================================================================
function handleGetList(): void {
    global $conn_sqli;

    $auth = tryOptionalAuth();  // Attempt auth if key provided, but don't require it

    $category = swim_get_param('category');
    $source   = swim_get_param('source');
    $search   = swim_get_param('search');
    $artcc    = swim_get_param('artcc') ?? swim_get_param('fir') ?? swim_get_param('acc');
    $status   = swim_get_param('status');
    $format   = swim_get_param('format', 'json');
    $page     = swim_get_int_param('page', 1, 1, 1000);
    $per_page = swim_get_int_param('per_page', 50, 1, 200);
    $offset   = ($page - 1) * $per_page;

    // Resolve API key owner CID for visibility filtering
    $api_cid  = resolveApiKeyCid($auth);
    $is_admin = ($api_cid !== null) ? checkIsAdmin($api_cid, $conn_sqli) : false;

    // Build WHERE clauses
    $where  = [];
    $params = [];
    $types  = '';

    // Visibility filtering
    $vis = build_visibility_where($api_cid, $is_admin);
    if ($vis['sql'] !== '') {
        $where[] = preg_replace('/^\s*AND\s+/', '', $vis['sql']);
        $params  = array_merge($params, $vis['params']);
        $types  .= $vis['types'];
    }

    // Status filter (default: exclude archived)
    if ($status !== null && $status !== '') {
        $where[] = "p.status = ?";
        $params[] = $status;
        $types .= 's';
    } else {
        $where[] = "p.status != 'archived'";
    }

    if ($category !== null && $category !== '') {
        $where[] = "p.category = ?";
        $params[] = $category;
        $types .= 's';
    }

    if ($source !== null && $source !== '') {
        $where[] = "p.source = ?";
        $params[] = $source;
        $types .= 's';
    }

    if ($search !== null && $search !== '') {
        $where[] = "(p.play_name LIKE ? OR p.display_name LIKE ? OR p.description LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    if ($artcc !== null && $artcc !== '') {
        $artcc = strtoupper($artcc);
        $artcc = normalizeArtccAlias($artcc);
        $where[] = "FIND_IN_SET(?, p.facilities_involved) > 0";
        $params[] = $artcc;
        $types .= 's';
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $count_sql  = "SELECT COUNT(*) AS total FROM playbook_plays p $where_sql";
    $count_stmt = $conn_sqli->prepare($count_sql);
    if ($types !== '') {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    // Fetch play IDs for current page
    $ids_sql    = "SELECT p.play_id FROM playbook_plays p $where_sql ORDER BY p.source ASC, p.play_name ASC LIMIT ? OFFSET ?";
    $ids_stmt   = $conn_sqli->prepare($ids_sql);
    $ids_types  = $types . 'ii';
    $ids_params = array_merge($params, [$per_page, $offset]);
    $ids_stmt->bind_param($ids_types, ...$ids_params);
    $ids_stmt->execute();
    $ids_result = $ids_stmt->get_result();

    $play_ids = [];
    while ($r = $ids_result->fetch_assoc()) {
        $play_ids[] = (int)$r['play_id'];
    }
    $ids_stmt->close();

    if (empty($play_ids)) {
        outputResponse([], $total, $page, $per_page, $format);
        return;
    }

    // Fetch full play data for the page
    $id_list = implode(',', $play_ids);
    $data_sql = "SELECT play_id, play_name, play_name_norm, display_name,
                        description, category, impacted_area, facilities_involved,
                        scenario_type, route_format, source, status,
                        airac_cycle, route_count, org_code, visibility,
                        created_by, updated_by, updated_at, created_at
                 FROM playbook_plays
                 WHERE play_id IN ($id_list)
                 ORDER BY source ASC, play_name ASC";

    $data_result = $conn_sqli->query($data_sql);
    $rows = [];
    while ($row = $data_result->fetch_assoc()) {
        $rows[] = $row;
    }

    // Post-filter visibility for private_org rows
    $filtered = [];
    foreach ($rows as $row) {
        if (($row['visibility'] ?? 'public') === 'private_org' && !$is_admin) {
            if ($api_cid === null || !can_cid_view_play($row, $api_cid, $conn_sqli, false)) {
                continue;
            }
        }
        $filtered[] = formatPlay($row);
    }

    // Adjust total if rows were removed by post-filter
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
    global $conn_sqli;

    $auth = tryOptionalAuth();

    $format = swim_get_param('format', 'json');

    // Fetch the play
    $stmt = $conn_sqli->prepare("SELECT * FROM playbook_plays WHERE play_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $play = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$play) {
        SwimResponse::error('Play not found', 404, 'NOT_FOUND');
    }

    // Visibility check
    $api_cid  = resolveApiKeyCid($auth);
    $is_admin = ($api_cid !== null) ? checkIsAdmin($api_cid, $conn_sqli) : false;

    $visibility = $play['visibility'] ?? 'public';
    if ($visibility !== 'public') {
        if ($api_cid === null) {
            SwimResponse::error('Authentication required for non-public plays', 401, 'UNAUTHORIZED');
        }
        if (!can_cid_view_play($play, $api_cid, $conn_sqli, $is_admin)) {
            SwimResponse::error('Access denied to this play', 403, 'FORBIDDEN');
        }
    }

    // Fetch routes for this play
    $route_stmt = $conn_sqli->prepare(
        "SELECT route_id, route_string, origin, origin_filter, dest, dest_filter,
                origin_airports, origin_tracons, origin_artccs,
                dest_airports, dest_tracons, dest_artccs,
                traversed_artccs, traversed_tracons,
                traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
                remarks, sort_order
         FROM playbook_routes
         WHERE play_id = ?
         ORDER BY sort_order ASC"
    );
    $route_stmt->bind_param('i', $id);
    $route_stmt->execute();
    $route_result = $route_stmt->get_result();

    $routes = [];
    while ($r = $route_result->fetch_assoc()) {
        $routes[] = formatRoute($r);
    }
    $route_stmt->close();

    $formatted = formatPlay($play);
    $formatted['routes'] = $routes;

    if ($format === 'geojson') {
        outputGeoJSON($formatted);
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
        'impacted_area'       => $row['impacted_area'] ?: null,
        'facilities_involved' => $row['facilities_involved'] ?: null,
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
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ],
    ];
}

/**
 * Format a route row for the API response.
 */
function formatRoute(array $r): array {
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
            'artccs'           => csvToArray($r['traversed_artccs'] ?? ''),
            'tracons'          => csvToArray($r['traversed_tracons'] ?? ''),
            'sectors_low'      => csvToArray($r['traversed_sectors_low'] ?? ''),
            'sectors_high'     => csvToArray($r['traversed_sectors_high'] ?? ''),
            'sectors_superhigh'=> csvToArray($r['traversed_sectors_superhigh'] ?? ''),
        ],
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
 * Output a GeoJSON stub (no geometry data available for plays at this time).
 */
function outputGeoJSON(array $play): void {
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => [],
        'metadata' => [
            'generated' => gmdate('c'),
            'play_id'   => $play['play_id'],
            'play_name' => $play['play_name'],
            'note'      => 'Route geometry not yet available via SWIM API. Use route_string for route parsing.',
            'count'     => 0,
            'source'    => 'perti_playbook',
        ],
    ];

    header('Content-Type: application/geo+json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Output a GeoJSON stub for a collection of plays.
 */
function outputGeoJSONCollection(array $plays): void {
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => [],
        'metadata' => [
            'generated' => gmdate('c'),
            'note'      => 'Route geometry not yet available via SWIM API. Use route_string for route parsing.',
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
    global $conn_swim, $conn_adl;

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

    $conn = $conn_swim ?: $conn_adl;
    if (!$conn) {
        return null;
    }

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
    global $conn_swim, $conn_adl;
    $conn = $conn_swim ?: $conn_adl;
    if (!$conn) {
        return null;
    }

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
 * Normalize ARTCC alias codes (FAA 3-letter to ICAO 4-letter for Canadian FIRs).
 */
function normalizeArtccAlias(string $code): string {
    static $aliases = [
        'CZE' => 'CZEG', 'CZU' => 'CZUL', 'CZV' => 'CZVR',
        'CZW' => 'CZWG', 'CZY' => 'CZYZ', 'CZM' => 'CZQM',
        'CZQ' => 'CZQX', 'CZO' => 'CZQO',
        'ZEG' => 'CZEG', 'ZUL' => 'CZUL', 'ZVR' => 'CZVR',
        'ZWG' => 'CZWG', 'ZYZ' => 'CZYZ', 'ZQM' => 'CZQM',
        'ZQX' => 'CZQX', 'ZQO' => 'CZQO', 'CZX' => 'CZQX',
        'KZAK' => 'ZAK', 'KZWY' => 'ZWY', 'PGZU' => 'ZUA',
        'PAZA' => 'ZAN', 'PAZN' => 'ZAP', 'PHZH' => 'ZHN',
        'ZMX' => 'MMMX', 'ZMT' => 'MMTY', 'ZMZ' => 'MMZT',
        'ZMR' => 'MMMD', 'ZMC' => 'MMUN', 'ZSU' => 'TJZS',
    ];
    return $aliases[$code] ?? $code;
}
