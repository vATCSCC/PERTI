<?php
/**
 * Playbook Save API
 * POST — Create or update a play (metadata + routes array in JSON body).
 * Logs all changes to playbook_changelog with AIRAC cycle.
 */

include_once(dirname(__DIR__, 3) . '/sessions/handler.php');
// handler.php already includes config.php and input.php via include_once
define('PERTI_MYSQL_ONLY', true);
include_once(dirname(__DIR__, 3) . '/load/connect.php');

header('Content-Type: application/json');

// Auth
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $cid = '0';
}

if (!$perm) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$play_id       = isset($body['play_id']) ? (int)$body['play_id'] : 0;
$play_name     = trim($body['play_name'] ?? '');
$display_name  = trim($body['display_name'] ?? '');
$description   = trim($body['description'] ?? '');
$category      = trim($body['category'] ?? '');
$scenario_type = trim($body['scenario_type'] ?? '');
$route_format  = in_array($body['route_format'] ?? '', ['standard', 'split']) ? $body['route_format'] : 'standard';
$status        = in_array($body['status'] ?? '', ['active', 'draft', 'archived']) ? $body['status'] : 'active';
$airac_cycle   = trim($body['airac_cycle'] ?? '');
$facilities_involved = trim($body['facilities_involved'] ?? '');
$impacted_area = trim($body['impacted_area'] ?? '');
$source        = in_array($body['source'] ?? '', ['DCC', 'ECFMP', 'CANOC']) ? $body['source'] : 'DCC';
$remarks       = trim($body['remarks'] ?? '');
$org_code      = isset($body['org_code']) && $body['org_code'] !== '' ? $body['org_code'] : null;
$routes        = isset($body['routes']) && is_array($body['routes']) ? $body['routes'] : [];

if ($play_name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'play_name is required']);
    exit;
}

function normalizePlayName($name) {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name));
}

/**
 * Extract a route endpoint identifier for LINESTRING bookending.
 * PostGIS resolve_waypoint() handles airports (KJFK), TRACONs (A90, PCT),
 * ARTCCs (ZNY, ZBW), and FAA codes (JFK) via nav_fixes + airports + area_centers.
 *
 * Priority: origin_airports (most specific) → origin label → origin_artccs (fallback)
 */
function _extractRouteEndpoint($label, $airportsCsv = '', $artccsCsv = '') {
    // 1. Try airports CSV first — most specific endpoint
    if ($airportsCsv !== '') {
        $first = strtoupper(trim(explode(',', $airportsCsv)[0]));
        if ($first !== '' && preg_match('/^[A-Z]{3,4}$/', $first)) {
            return $first;
        }
    }

    // 2. Try the label field (airport ICAO, TRACON code, ARTCC code, etc.)
    $label = strtoupper(trim($label));
    if ($label !== '' && preg_match('/^[A-Z][A-Z0-9]{1,4}$/', $label)) {
        return $label;
    }

    // 3. Fall back to first ARTCC in artccs CSV
    if ($artccsCsv !== '') {
        $first = strtoupper(trim(explode(',', $artccsCsv)[0]));
        if ($first !== '' && $first !== 'UNKN' && preg_match('/^[A-Z]{2,4}$/', $first)) {
            return $first;
        }
    }

    return '';
}

/**
 * Compute traversed facilities using the PostGIS expand_route_with_artccs() function.
 * This reuses the same route parsing/expansion pipeline as route.php and the ADL
 * parse queue — properly resolving airways, DPs/STARs, airports, and FBD tokens.
 *
 * The origin/dest airports are prepended/appended to the route_string so the
 * resulting LINESTRING spans the full origin-to-destination path.
 *
 * Returns array with: artccs, tracons, sectors_low, sectors_high, sectors_superhigh
 * Each value is a comma-separated string of boundary codes.
 */
function computeTraversedFacilities($route_string, $origin_artccs, $dest_artccs,
                                    $origin = '', $dest = '',
                                    $origin_airports = '', $dest_airports = '') {
    static $conn_gis_cached = null;
    static $gis_available = null;

    $result = [
        'artccs' => '',
        'tracons' => '',
        'sectors_low' => '',
        'sectors_high' => '',
        'sectors_superhigh' => '',
    ];

    // Lazy-init GIS connection (only once per request)
    if ($gis_available === null) {
        if (function_exists('get_conn_gis')) {
            $conn_gis_cached = get_conn_gis();
            $gis_available = ($conn_gis_cached !== null && $conn_gis_cached !== false);
        } else {
            $gis_available = false;
        }
    }

    if (!$gis_available || trim($route_string) === '') {
        return $result;
    }

    $artccs = [];
    $tracons = [];
    $sectors_low = [];
    $sectors_high = [];
    $sectors_superhigh = [];

    try {
        // Build full route string: prepend origin airport, append dest airport
        // so the LINESTRING spans the complete origin→destination path.
        $fullRoute = strtoupper(trim($route_string));

        // Resolve origin/dest endpoints: airports → label → ARTCCs
        // PostGIS resolve_waypoint() handles all types (airports, TRACONs, ARTCCs)
        $origEndpoint = _extractRouteEndpoint($origin, $origin_airports, $origin_artccs);
        $destEndpoint = _extractRouteEndpoint($dest, $dest_airports, $dest_artccs);

        if ($origEndpoint) {
            $fullRoute = $origEndpoint . ' ' . $fullRoute;
        }
        if ($destEndpoint) {
            $fullRoute = $fullRoute . ' ' . $destEndpoint;
        }

        // Use expand_route_with_artccs() for proper route expansion (handles
        // airways, DPs/STARs, airports, FBD tokens — same as route.php).
        // Then intersect the resulting geometry with TRACON + sector boundaries.
        $sql = "WITH route AS (
                    SELECT artccs_traversed, route_geometry AS geom
                    FROM expand_route_with_artccs(?)
                )
                SELECT 'artcc' AS btype, unnest(route.artccs_traversed) AS code
                FROM route WHERE route.geom IS NOT NULL
                UNION ALL
                SELECT 'tracon', t.tracon_code
                FROM route JOIN tracon_boundaries t ON ST_Intersects(route.geom, t.geom)
                WHERE route.geom IS NOT NULL
                UNION ALL
                SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code
                FROM route JOIN sector_boundaries s ON ST_Intersects(route.geom, s.geom)
                WHERE route.geom IS NOT NULL";

        $stmt = $conn_gis_cached->prepare($sql);
        $stmt->execute([$fullRoute]);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $code = $row['code'];
            switch ($row['btype']) {
                case 'artcc':
                    if (strlen($code) === 4 && $code[0] === 'K') {
                        $code = substr($code, 1);
                    }
                    $artccs[] = $code;
                    break;
                case 'tracon':
                    $tracons[] = $code;
                    break;
                case 'sector_low':
                    $sectors_low[] = $code;
                    break;
                case 'sector_high':
                    $sectors_high[] = $code;
                    break;
                case 'sector_superhigh':
                    $sectors_superhigh[] = $code;
                    break;
            }
        }
    } catch (\Exception $e) {
        // Silently fail — traversal data will just be empty
    }

    // Merge origin + dest ARTCCs (traversed by definition), skip UNKN
    foreach (explode(',', $origin_artccs) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN') $artccs[] = $a;
    }
    foreach (explode(',', $dest_artccs) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN') $artccs[] = $a;
    }

    $result['artccs'] = implode(',', array_unique(array_filter($artccs)));
    $result['tracons'] = implode(',', array_unique(array_filter($tracons)));
    $result['sectors_low'] = implode(',', array_unique(array_filter($sectors_low)));
    $result['sectors_high'] = implode(',', array_unique(array_filter($sectors_high)));
    $result['sectors_superhigh'] = implode(',', array_unique(array_filter($sectors_superhigh)));

    return $result;
}

$play_name_norm = normalizePlayName($play_name);
$route_count = count($routes);
$changed_by = session_get('VATSIM_CID', '0');

if ($play_id > 0) {
    // --- UPDATE existing play ---
    // Fetch old values for changelog
    $old_stmt = $conn_sqli->prepare("SELECT * FROM playbook_plays WHERE play_id = ?");
    $old_stmt->bind_param('i', $play_id);
    $old_stmt->execute();
    $old = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    if (!$old) {
        http_response_code(404);
        echo json_encode(['error' => 'Play not found']);
        exit;
    }

    // Update play
    $stmt = $conn_sqli->prepare("UPDATE playbook_plays SET
        play_name=?, play_name_norm=?, display_name=?, description=?, category=?,
        scenario_type=?, route_format=?, status=?, airac_cycle=?,
        facilities_involved=?, impacted_area=?, remarks=?, route_count=?,
        org_code=?, updated_by=?
        WHERE play_id=?");
    $stmt->bind_param('ssssssssssssissi',
        $play_name, $play_name_norm, $display_name, $description, $category,
        $scenario_type, $route_format, $status, $airac_cycle,
        $facilities_involved, $impacted_area, $remarks, $route_count,
        $org_code, $changed_by, $play_id);
    $stmt->execute();
    $stmt->close();

    // Log field-level changes
    $fields = [
        'play_name' => $play_name, 'display_name' => $display_name,
        'description' => $description, 'category' => $category,
        'scenario_type' => $scenario_type, 'route_format' => $route_format,
        'status' => $status, 'facilities_involved' => $facilities_involved,
        'impacted_area' => $impacted_area, 'remarks' => $remarks,
    ];
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, field_name, old_value, new_value, airac_cycle, changed_by) VALUES (?, 'play_updated', ?, ?, ?, ?, ?)");
    foreach ($fields as $fname => $new_val) {
        $old_val = $old[$fname] ?? '';
        if ((string)$old_val !== (string)$new_val) {
            $cl_stmt->bind_param('isssss', $play_id, $fname, $old_val, $new_val, $airac_cycle, $changed_by);
            $cl_stmt->execute();
        }
    }
    $cl_stmt->close();

    // Replace routes: delete old, insert new
    $del_stmt = $conn_sqli->prepare("DELETE FROM playbook_routes WHERE play_id = ?");
    $del_stmt->bind_param('i', $play_id);
    $del_stmt->execute();
    $del_stmt->close();

} else {
    // --- CREATE new play ---
    $stmt = $conn_sqli->prepare("INSERT INTO playbook_plays
        (play_name, play_name_norm, display_name, description, category,
         scenario_type, route_format, source, status, airac_cycle,
         facilities_involved, impacted_area, remarks, route_count,
         org_code, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssssssssssiss',
        $play_name, $play_name_norm, $display_name, $description, $category,
        $scenario_type, $route_format, $source, $status, $airac_cycle,
        $facilities_involved, $impacted_area, $remarks, $route_count,
        $org_code, $changed_by);
    $stmt->execute();
    $play_id = (int)$conn_sqli->insert_id;
    $stmt->close();

    // Log creation
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, airac_cycle, changed_by) VALUES (?, 'play_created', ?, ?)");
    $cl_stmt->bind_param('iss', $play_id, $airac_cycle, $changed_by);
    $cl_stmt->execute();
    $cl_stmt->close();
}

// Insert routes
if (!empty($routes)) {
    $route_stmt = $conn_sqli->prepare("INSERT INTO playbook_routes
        (play_id, route_string, origin, origin_filter, dest, dest_filter,
         origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs,
         traversed_artccs, traversed_tracons,
         traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
         sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $sort = 0;
    foreach ($routes as $r) {
        $rs = trim($r['route_string'] ?? '');
        $orig = trim($r['origin'] ?? '');
        $orig_filter = trim($r['origin_filter'] ?? '');
        $dst = trim($r['dest'] ?? '');
        $dst_filter = trim($r['dest_filter'] ?? '');
        $oa = trim($r['origin_airports'] ?? '');
        $ot = trim($r['origin_tracons'] ?? '');
        $oar = trim($r['origin_artccs'] ?? '');
        $da = trim($r['dest_airports'] ?? '');
        $dt = trim($r['dest_tracons'] ?? '');
        $dar = trim($r['dest_artccs'] ?? '');

        if ($rs === '') continue;

        // Compute traversed facilities using PostGIS route expansion
        $tf = computeTraversedFacilities($rs, $oar, $dar, $orig, $dst, $oa, $da);
        $traversed = $tf['artccs'];
        $trav_tracons = $tf['tracons'];
        $trav_sec_low = $tf['sectors_low'];
        $trav_sec_high = $tf['sectors_high'];
        $trav_sec_superhigh = $tf['sectors_superhigh'];

        $route_stmt->bind_param('issssssssssssssssi',
            $play_id, $rs, $orig, $orig_filter, $dst, $dst_filter,
            $oa, $ot, $oar, $da, $dt, $dar,
            $traversed, $trav_tracons,
            $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
            $sort);
        $route_stmt->execute();
        $sort++;
    }
    $route_stmt->close();
}

echo json_encode([
    'success' => true,
    'play_id' => $play_id,
    'route_count' => $route_count
]);
