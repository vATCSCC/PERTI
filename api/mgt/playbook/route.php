<?php
/**
 * Playbook Route API
 * POST — Add, edit, or delete an individual route within a play.
 * Logs to playbook_changelog with field-level diffs.
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

$action      = $body['action'] ?? '';   // add, edit, delete
$play_id     = (int)($body['play_id'] ?? 0);
$route_id    = (int)($body['route_id'] ?? 0);
$airac_cycle = trim($body['airac_cycle'] ?? '');
$changed_by  = session_get('VATSIM_CID', '0');

if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'play_id is required']);
    exit;
}

// Verify play exists and check edit permission
$play_check = $conn_sqli->prepare("SELECT play_id, visibility, created_by, org_code FROM playbook_plays WHERE play_id = ?");
$play_check->bind_param('i', $play_id);
$play_check->execute();
$play_data = $play_check->get_result()->fetch_assoc();
$play_check->close();

if (!$play_data) {
    http_response_code(404);
    echo json_encode(['error' => 'Play not found']);
    exit;
}

if (!can_edit_play($play_data, $conn_sqli)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to edit this play']);
    exit;
}

if ($action === 'add') {
    $rs = normalizeRouteCanadian(trim($body['route_string'] ?? ''));
    $orig = trim($body['origin'] ?? '');
    $orig_filter = trim($body['origin_filter'] ?? '');
    $dst = trim($body['dest'] ?? '');
    $dst_filter = trim($body['dest_filter'] ?? '');
    $oa = trim($body['origin_airports'] ?? '');
    $ot = trim($body['origin_tracons'] ?? '');
    $oar = normalizeCanadianArtccCsv(trim($body['origin_artccs'] ?? ''));
    $da = trim($body['dest_airports'] ?? '');
    $dt = trim($body['dest_tracons'] ?? '');
    $dar = normalizeCanadianArtccCsv(trim($body['dest_artccs'] ?? ''));
    $remarks_r = trim($body['remarks'] ?? '');
    $sort = (int)($body['sort_order'] ?? 0);

    if ($rs === '') {
        http_response_code(400);
        echo json_encode(['error' => 'route_string is required']);
        exit;
    }

    // Compute traversed facilities + frozen geometry using PostGIS route expansion
    $tf = computeTraversedFacilities($rs, $oar, $dar, $orig, $dst, $oa, $da);
    $trav_artccs = $tf['artccs'];
    $trav_tracons = $tf['tracons'];
    $trav_sec_low = $tf['sectors_low'];
    $trav_sec_high = $tf['sectors_high'];
    $trav_sec_superhigh = $tf['sectors_superhigh'];
    $route_geom = $tf['route_geometry'];

    $stmt = $conn_sqli->prepare("INSERT INTO playbook_routes
        (play_id, route_string, origin, origin_filter, dest, dest_filter,
         origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs,
         traversed_artccs, traversed_tracons,
         traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
         route_geometry, remarks, sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('issssssssssssssssssi',
        $play_id, $rs, $orig, $orig_filter, $dst, $dst_filter,
        $oa, $ot, $oar, $da, $dt, $dar,
        $trav_artccs, $trav_tracons,
        $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
        $route_geom, $remarks_r, $sort);
    $stmt->execute();
    $new_route_id = (int)$conn_sqli->insert_id;
    $stmt->close();

    // Update route count
    $rc_stmt = $conn_sqli->prepare("UPDATE playbook_plays SET route_count = (SELECT COUNT(*) FROM playbook_routes WHERE play_id = ?) WHERE play_id = ?");
    $rc_stmt->bind_param('ii', $play_id, $play_id);
    $rc_stmt->execute();
    $rc_stmt->close();

    // Changelog
    $cl = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, route_id, action, new_value, airac_cycle, changed_by) VALUES (?, ?, 'route_added', ?, ?, ?)");
    $cl->bind_param('iisss', $play_id, $new_route_id, $rs, $airac_cycle, $changed_by);
    $cl->execute();
    $cl->close();

    echo json_encode(['success' => true, 'route_id' => $new_route_id]);

} elseif ($action === 'edit') {
    if ($route_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'route_id is required for edit']);
        exit;
    }

    // Fetch old route
    $old_stmt = $conn_sqli->prepare("SELECT * FROM playbook_routes WHERE route_id = ? AND play_id = ?");
    $old_stmt->bind_param('ii', $route_id, $play_id);
    $old_stmt->execute();
    $old = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    if (!$old) {
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
        exit;
    }

    $rs = normalizeRouteCanadian(trim($body['route_string'] ?? $old['route_string']));
    $orig = trim($body['origin'] ?? $old['origin']);
    $orig_filter = trim($body['origin_filter'] ?? ($old['origin_filter'] ?? ''));
    $dst = trim($body['dest'] ?? $old['dest']);
    $dst_filter = trim($body['dest_filter'] ?? ($old['dest_filter'] ?? ''));
    $oa = trim($body['origin_airports'] ?? ($old['origin_airports'] ?? ''));
    $ot = trim($body['origin_tracons'] ?? ($old['origin_tracons'] ?? ''));
    $oar = normalizeCanadianArtccCsv(trim($body['origin_artccs'] ?? ($old['origin_artccs'] ?? '')));
    $da = trim($body['dest_airports'] ?? ($old['dest_airports'] ?? ''));
    $dt = trim($body['dest_tracons'] ?? ($old['dest_tracons'] ?? ''));
    $dar = normalizeCanadianArtccCsv(trim($body['dest_artccs'] ?? ($old['dest_artccs'] ?? '')));
    $remarks_r = trim($body['remarks'] ?? ($old['remarks'] ?? ''));

    // Recompute traversed facilities + frozen geometry using PostGIS route expansion
    $tf = computeTraversedFacilities($rs, $oar, $dar, $orig, $dst, $oa, $da);
    $trav_artccs = $tf['artccs'];
    $trav_tracons = $tf['tracons'];
    $trav_sec_low = $tf['sectors_low'];
    $trav_sec_high = $tf['sectors_high'];
    $trav_sec_superhigh = $tf['sectors_superhigh'];
    $route_geom = $tf['route_geometry'];

    $stmt = $conn_sqli->prepare("UPDATE playbook_routes SET
        route_string=?, origin=?, origin_filter=?, dest=?, dest_filter=?,
        origin_airports=?, origin_tracons=?, origin_artccs=?,
        dest_airports=?, dest_tracons=?, dest_artccs=?,
        traversed_artccs=?, traversed_tracons=?,
        traversed_sectors_low=?, traversed_sectors_high=?, traversed_sectors_superhigh=?,
        route_geometry=?, remarks=?
        WHERE route_id=?");
    $stmt->bind_param('ssssssssssssssssssi',
        $rs, $orig, $orig_filter, $dst, $dst_filter,
        $oa, $ot, $oar, $da, $dt, $dar,
        $trav_artccs, $trav_tracons,
        $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
        $route_geom, $remarks_r, $route_id);
    $stmt->execute();
    $stmt->close();

    // Log field-level changes
    $fields = ['route_string' => $rs, 'origin' => $orig, 'origin_filter' => $orig_filter,
               'dest' => $dst, 'dest_filter' => $dst_filter, 'remarks' => $remarks_r];
    $cl = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, route_id, action, field_name, old_value, new_value, airac_cycle, changed_by) VALUES (?, ?, 'route_updated', ?, ?, ?, ?, ?)");
    foreach ($fields as $fn => $nv) {
        $ov = $old[$fn] ?? '';
        if ((string)$ov !== (string)$nv) {
            $cl->bind_param('iisssss', $play_id, $route_id, $fn, $ov, $nv, $airac_cycle, $changed_by);
            $cl->execute();
        }
    }
    $cl->close();

    echo json_encode(['success' => true, 'route_id' => $route_id]);

} elseif ($action === 'delete') {
    if ($route_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'route_id is required for delete']);
        exit;
    }

    // Fetch old route for changelog
    $old_stmt = $conn_sqli->prepare("SELECT route_string FROM playbook_routes WHERE route_id = ? AND play_id = ?");
    $old_stmt->bind_param('ii', $route_id, $play_id);
    $old_stmt->execute();
    $old = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    if (!$old) {
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
        exit;
    }

    $stmt = $conn_sqli->prepare("DELETE FROM playbook_routes WHERE route_id = ?");
    $stmt->bind_param('i', $route_id);
    $stmt->execute();
    $stmt->close();

    // Update route count
    $rc_stmt = $conn_sqli->prepare("UPDATE playbook_plays SET route_count = (SELECT COUNT(*) FROM playbook_routes WHERE play_id = ?) WHERE play_id = ?");
    $rc_stmt->bind_param('ii', $play_id, $play_id);
    $rc_stmt->execute();
    $rc_stmt->close();

    // Changelog
    $old_rs = $old['route_string'];
    $cl = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, route_id, action, old_value, airac_cycle, changed_by) VALUES (?, ?, 'route_deleted', ?, ?, ?)");
    $cl->bind_param('iisss', $play_id, $route_id, $old_rs, $airac_cycle, $changed_by);
    $cl->execute();
    $cl->close();

    echo json_encode(['success' => true, 'deleted' => $route_id]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use: add, edit, delete']);
}
