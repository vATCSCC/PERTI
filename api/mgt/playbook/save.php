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
include_once(dirname(__DIR__, 3) . '/load/playbook_visibility.php');
include_once(__DIR__ . '/playbook_helpers.php');

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
$facilities_involved = normalizeCanadianArtccCsv(trim($body['facilities_involved'] ?? ''));
$impacted_area = trim($body['impacted_area'] ?? '');
$source        = in_array($body['source'] ?? '', ['DCC', 'ECFMP', 'CANOC', 'CADENA']) ? $body['source'] : 'DCC';
$visibility    = in_array($body['visibility'] ?? '', ['public', 'local', 'private_users', 'private_org']) ? $body['visibility'] : 'public';
$remarks       = trim($body['remarks'] ?? '');
$org_code      = isset($body['org_code']) && $body['org_code'] !== '' ? $body['org_code'] : null;
$routes        = isset($body['routes']) && is_array($body['routes']) ? $body['routes'] : [];

if ($play_name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'play_name is required']);
    exit;
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

    if (!can_edit_play($old, $conn_sqli)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to edit this play']);
        exit;
    }

    // Reject visibility changes for FAA-imported plays
    if (in_array($old['source'] ?? '', ['FAA', 'FAA_HISTORICAL']) && $visibility !== ($old['visibility'] ?? 'public')) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot change visibility on FAA-imported plays']);
        exit;
    }

    // Check for duplicate play name (same normalized name + source, different play_id)
    if ($play_name_norm !== ($old['play_name_norm'] ?? '')) {
        $dup_stmt = $conn_sqli->prepare("SELECT play_id FROM playbook_plays WHERE play_name_norm = ? AND source = ? AND play_id != ?");
        $old_source = $old['source'] ?? '';
        $dup_stmt->bind_param('ssi', $play_name_norm, $old_source, $play_id);
        $dup_stmt->execute();
        if ($dup_stmt->get_result()->num_rows > 0) {
            $dup_stmt->close();
            http_response_code(409);
            echo json_encode(['error' => "A play named \"$play_name\" already exists"]);
            exit;
        }
        $dup_stmt->close();
    }

    // Update play
    $stmt = $conn_sqli->prepare("UPDATE playbook_plays SET
        play_name=?, play_name_norm=?, display_name=?, description=?, category=?,
        scenario_type=?, route_format=?, status=?, airac_cycle=?,
        facilities_involved=?, impacted_area=?, remarks=?, route_count=?,
        org_code=?, visibility=?, updated_by=?
        WHERE play_id=?");
    $stmt->bind_param('sssssssssssssissi',
        $play_name, $play_name_norm, $display_name, $description, $category,
        $scenario_type, $route_format, $status, $airac_cycle,
        $facilities_involved, $impacted_area, $remarks, $route_count,
        $org_code, $visibility, $changed_by, $play_id);
    $stmt->execute();
    $stmt->close();

    // Log field-level changes with enhanced detail (name, IP, context)
    $changed_by_name = $_SESSION['VATSIM_NAME'] ?? $_SESSION['VATSIM_FNAME'] ?? null;
    $client_ip = !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
        : ($_SERVER['REMOTE_ADDR'] ?? null);
    $fields = [
        'play_name' => $play_name, 'display_name' => $display_name,
        'description' => $description, 'category' => $category,
        'scenario_type' => $scenario_type, 'route_format' => $route_format,
        'status' => $status, 'facilities_involved' => $facilities_involved,
        'impacted_area' => $impacted_area, 'remarks' => $remarks,
        'visibility' => $visibility,
    ];
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, field_name, old_value, new_value, airac_cycle, changed_by, changed_by_name, ip_address) VALUES (?, 'play_updated', ?, ?, ?, ?, ?, ?, ?)");
    foreach ($fields as $fname => $new_val) {
        $old_val = $old[$fname] ?? '';
        if ((string)$old_val !== (string)$new_val) {
            $cl_stmt->bind_param('isssssss', $play_id, $fname, $old_val, $new_val, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
            $cl_stmt->execute();
        }
    }
    $cl_stmt->close();

    // Snapshot old routes before deletion for changelog diffing
    $old_routes = [];
    $old_r_stmt = $conn_sqli->prepare("SELECT route_string, origin, origin_filter, dest, dest_filter, origin_airports, origin_tracons, origin_artccs, dest_airports, dest_tracons, dest_artccs, remarks FROM playbook_routes WHERE play_id = ? ORDER BY sort_order");
    $old_r_stmt->bind_param('i', $play_id);
    $old_r_stmt->execute();
    $old_r_result = $old_r_stmt->get_result();
    while ($or = $old_r_result->fetch_assoc()) {
        $old_routes[] = $or;
    }
    $old_r_stmt->close();

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
         org_code, visibility, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssssssssssisss',
        $play_name, $play_name_norm, $display_name, $description, $category,
        $scenario_type, $route_format, $source, $status, $airac_cycle,
        $facilities_involved, $impacted_area, $remarks, $route_count,
        $org_code, $visibility, $changed_by);
    $stmt->execute();
    $play_id = (int)$conn_sqli->insert_id;
    $stmt->close();

    // Log creation with enhanced detail
    $changed_by_name = $_SESSION['VATSIM_NAME'] ?? $_SESSION['VATSIM_FNAME'] ?? null;
    $client_ip = !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
        : ($_SERVER['REMOTE_ADDR'] ?? null);
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, airac_cycle, changed_by, changed_by_name, ip_address) VALUES (?, 'play_created', ?, ?, ?, ?)");
    $cl_stmt->bind_param('issss', $play_id, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
    $cl_stmt->execute();
    $cl_stmt->close();
}

// Insert routes
$all_traversed_artccs = [];
if (!empty($routes)) {
    $route_stmt = $conn_sqli->prepare("INSERT INTO playbook_routes
        (play_id, route_string, origin, origin_filter, dest, dest_filter,
         origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs,
         traversed_artccs, traversed_tracons,
         traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
         route_geometry, remarks, sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $sort = 0;
    foreach ($routes as $r) {
        $rs = normalizeRouteCanadian(trim($r['route_string'] ?? ''));
        $orig = trim($r['origin'] ?? '');
        $orig_filter = trim($r['origin_filter'] ?? '');
        $dst = trim($r['dest'] ?? '');
        $dst_filter = trim($r['dest_filter'] ?? '');
        $oa = trim($r['origin_airports'] ?? '');
        $ot = trim($r['origin_tracons'] ?? '');
        $oar = normalizeCanadianArtccCsv(trim($r['origin_artccs'] ?? ''));
        $da = trim($r['dest_airports'] ?? '');
        $dt = trim($r['dest_tracons'] ?? '');
        $dar = normalizeCanadianArtccCsv(trim($r['dest_artccs'] ?? ''));
        $remarks_r = trim($r['remarks'] ?? '');

        if ($rs === '') continue;

        // Compute traversed facilities + frozen geometry using PostGIS route expansion
        $tf = computeTraversedFacilities($rs, $oar, $dar, $orig, $dst, $oa, $da);
        $traversed = $tf['artccs'];
        $trav_tracons = $tf['tracons'];
        $trav_sec_low = $tf['sectors_low'];
        $trav_sec_high = $tf['sectors_high'];
        $trav_sec_superhigh = $tf['sectors_superhigh'];
        $route_geom = $tf['route_geometry'];

        // Collect traversed ARTCCs for play-level facilities_involved aggregation
        foreach (explode(',', $traversed) as $a) {
            $a = trim($a);
            if ($a !== '') $all_traversed_artccs[$a] = 1;
        }

        $route_stmt->bind_param('issssssssssssssssssi',
            $play_id, $rs, $orig, $orig_filter, $dst, $dst_filter,
            $oa, $ot, $oar, $da, $dt, $dar,
            $traversed, $trav_tracons,
            $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
            $route_geom, $remarks_r, $sort);
        $route_stmt->execute();
        $sort++;
    }
    $route_stmt->close();
}

// Recompute play-level facilities_involved from per-route traversed ARTCCs
// (overrides client-sent value which may be stale or computed from buggy GIS)
if (!empty($all_traversed_artccs)) {
    $agg_artccs = array_keys($all_traversed_artccs);
    sort($agg_artccs);
    $agg_fac = implode(',', $agg_artccs);
    $agg_imp = implode('/', $agg_artccs);
    $upd = $conn_sqli->prepare("UPDATE playbook_plays SET facilities_involved = ?, impacted_area = ? WHERE play_id = ?");
    $upd->bind_param('ssi', $agg_fac, $agg_imp, $play_id);
    $upd->execute();
    $upd->close();
}

// Log route-level changes (only for updates where we have old_routes snapshot)
if (isset($old_routes)) {
    // Build normalized new route list for comparison
    $new_routes_norm = [];
    foreach ($routes as $r) {
        $rs = normalizeRouteCanadian(trim($r['route_string'] ?? ''));
        if ($rs === '') continue;
        $new_routes_norm[] = [
            'route_string' => $rs,
            'origin' => trim($r['origin'] ?? ''),
            'origin_filter' => trim($r['origin_filter'] ?? ''),
            'dest' => trim($r['dest'] ?? ''),
            'dest_filter' => trim($r['dest_filter'] ?? ''),
            'origin_airports' => trim($r['origin_airports'] ?? ''),
            'origin_tracons' => trim($r['origin_tracons'] ?? ''),
            'origin_artccs' => normalizeCanadianArtccCsv(trim($r['origin_artccs'] ?? '')),
            'dest_airports' => trim($r['dest_airports'] ?? ''),
            'dest_tracons' => trim($r['dest_tracons'] ?? ''),
            'dest_artccs' => normalizeCanadianArtccCsv(trim($r['dest_artccs'] ?? '')),
            'remarks' => trim($r['remarks'] ?? ''),
        ];
    }

    // Index by uppercased route_string for matching
    $old_by_key = [];
    foreach ($old_routes as $or) {
        $key = strtoupper(trim($or['route_string']));
        $old_by_key[$key][] = $or;
    }
    $new_by_key = [];
    foreach ($new_routes_norm as $nr) {
        $key = strtoupper(trim($nr['route_string']));
        $new_by_key[$key][] = $nr;
    }

    $rcl = $conn_sqli->prepare("INSERT INTO playbook_changelog
        (play_id, action, field_name, old_value, new_value, airac_cycle, changed_by, changed_by_name, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Deleted routes: in old but not in new
    foreach ($old_by_key as $key => $entries) {
        if (!isset($new_by_key[$key])) {
            foreach ($entries as $or) {
                $a = 'route_deleted';
                $fn = null;
                $ov = $or['route_string'];
                $nv = null;
                $rcl->bind_param('issssssss', $play_id, $a, $fn, $ov, $nv, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
                $rcl->execute();
            }
        }
    }

    // Added routes: in new but not in old
    foreach ($new_by_key as $key => $entries) {
        if (!isset($old_by_key[$key])) {
            foreach ($entries as $nr) {
                $a = 'route_added';
                $fn = null;
                $ov = null;
                $nv = $nr['route_string'];
                $rcl->bind_param('issssssss', $play_id, $a, $fn, $ov, $nv, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
                $rcl->execute();
            }
        }
    }

    // Modified routes: same route_string key, compare fields
    $route_diff_fields = ['origin', 'dest', 'origin_filter', 'dest_filter',
        'origin_airports', 'origin_tracons', 'origin_artccs',
        'dest_airports', 'dest_tracons', 'dest_artccs', 'remarks'];
    foreach ($new_by_key as $key => $new_entries) {
        if (!isset($old_by_key[$key])) continue;
        $old_entries = $old_by_key[$key];
        $max = min(count($new_entries), count($old_entries));
        for ($i = 0; $i < $max; $i++) {
            foreach ($route_diff_fields as $fn) {
                $ov = (string)($old_entries[$i][$fn] ?? '');
                $nv = (string)($new_entries[$i][$fn] ?? '');
                if ($ov !== $nv) {
                    $a = 'route_updated';
                    $rcl->bind_param('issssssss', $play_id, $a, $fn, $ov, $nv, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
                    $rcl->execute();
                }
            }
        }
        // Extra new entries with same route_string count as additions
        for ($i = $max; $i < count($new_entries); $i++) {
            $a = 'route_added';
            $fn = null;
            $ov = null;
            $nv = $new_entries[$i]['route_string'];
            $rcl->bind_param('issssssss', $play_id, $a, $fn, $ov, $nv, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
            $rcl->execute();
        }
        // Extra old entries with same route_string count as deletions
        for ($i = $max; $i < count($old_entries); $i++) {
            $a = 'route_deleted';
            $fn = null;
            $ov = $old_entries[$i]['route_string'];
            $nv = null;
            $rcl->bind_param('issssssss', $play_id, $a, $fn, $ov, $nv, $airac_cycle, $changed_by, $changed_by_name, $client_ip);
            $rcl->execute();
        }
    }

    $rcl->close();
}

echo json_encode([
    'success' => true,
    'play_id' => $play_id,
    'route_count' => $route_count
]);
