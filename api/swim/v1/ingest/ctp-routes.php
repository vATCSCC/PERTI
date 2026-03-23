<?php
/**
 * VATSWIM API v1 — CTP Route Planner → Playbook Sync
 *
 * Full-state sync: CTP sends complete route set after every save.
 * We diff against current playbook state and produce changelogs for
 * adds, updates, and deletes across 4 auto-created playbooks.
 *
 * POST /api/swim/v1/ingest/ctp-routes.php
 *
 * @version 1.0.0
 */

// ── Bootstrap ─────────────────────────────────────────────────────────
// Load MySQL + PostGIS BEFORE SWIM auth (auth.php would set PERTI_SWIM_ONLY)
require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';
require_once __DIR__ . '/../../mgt/playbook/playbook_helpers.php';
require_once __DIR__ . '/../auth.php';

// ── Auth ──────────────────────────────────────────────────────────────
$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('API key lacks CTP field write authority', 403, 'FORBIDDEN');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { SwimResponse::handlePreflight(); }
if ($method !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// ── Validate payload ──────────────────────────────────────────────────
$body = swim_get_json_body();
if (!$body || !is_array($body)) {
    SwimResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$session_id     = (int)($body['session_id'] ?? 0);
$revision       = (int)($body['revision'] ?? 0);
$group_mapping  = $body['group_mapping'] ?? null;
$routes_in      = $body['routes'] ?? null;
$changed_by_cid = $body['changed_by_cid'] ?? null;

if ($session_id <= 0) SwimResponse::error('session_id is required (positive integer)', 400, 'MISSING_PARAM');
if ($revision <= 0)   SwimResponse::error('revision is required (positive integer)', 400, 'MISSING_PARAM');
if (!is_array($group_mapping) || empty($group_mapping))
    SwimResponse::error('group_mapping object is required', 400, 'MISSING_PARAM');
if (!is_array($routes_in))
    SwimResponse::error('routes array is required', 400, 'MISSING_PARAM');
if (count($routes_in) > 1000)
    SwimResponse::error('Maximum 1000 routes per request', 400, 'BATCH_LIMIT');

$valid_scopes = ['ocean', 'na', 'emea'];
foreach ($group_mapping as $grp => $scp) {
    if (!in_array(strtolower($scp), $valid_scopes))
        SwimResponse::error("Invalid scope '{$scp}' in group_mapping. Valid: ocean, na, emea", 400, 'INVALID_PARAM');
}

foreach ($routes_in as $idx => $r) {
    if (empty($r['identifier']))  SwimResponse::error("Route[$idx]: identifier is required", 400, 'INVALID_ROUTE');
    if (!isset($r['group']))      SwimResponse::error("Route[$idx]: group is required", 400, 'INVALID_ROUTE');
    if (empty($r['routestring'])) SwimResponse::error("Route[$idx]: routestring is required", 400, 'INVALID_ROUTE');
}

// ── Resolve author ────────────────────────────────────────────────────
global $conn_sqli;

$changed_by_name = 'CTP Route Planner';
$changed_by_str  = $changed_by_cid ? (string)$changed_by_cid : null;
if ($changed_by_cid) {
    $u = $conn_sqli->prepare("SELECT name_first, name_last FROM users WHERE cid = ?");
    $u->bind_param('s', $changed_by_cid);
    $u->execute();
    $ur = $u->get_result();
    if ($row = $ur->fetch_assoc()) {
        $changed_by_name = trim($row['name_first'] . ' ' . $row['name_last']);
    } else {
        $changed_by_name = "CTP User {$changed_by_cid}";
    }
    $u->close();
}

// ── Play scope definitions ────────────────────────────────────────────
$play_scopes = [
    'full'  => ['name' => "CTPE26-{$session_id}-FULL",    'scope' => null,      'desc' => "CTPE26 Session {$session_id} - Full Route Set"],
    'na'    => ['name' => "CTPE26-{$session_id}-NA",      'scope' => 'NA',      'desc' => "CTPE26 Session {$session_id} - North America"],
    'emea'  => ['name' => "CTPE26-{$session_id}-EMEA",    'scope' => 'EU',      'desc' => "CTPE26 Session {$session_id} - Europe/Middle East/Africa"],
    'ocean' => ['name' => "CTPE26-{$session_id}-OCEANIC", 'scope' => 'OCEANIC', 'desc' => "CTPE26 Session {$session_id} - Oceanic"],
];

// Build group→scope reverse map
$group_to_scope = [];
foreach ($group_mapping as $grp => $scp) {
    $group_to_scope[strtoupper($grp)] = strtolower($scp);
}

// Filter routes per play
$routes_per_play = [];
foreach ($play_scopes as $key => $def) {
    if ($key === 'full') {
        $routes_per_play[$key] = $routes_in;
    } else {
        $routes_per_play[$key] = [];
        foreach ($routes_in as $r) {
            $g = strtoupper($r['group'] ?? '');
            if (isset($group_to_scope[$g]) && $group_to_scope[$g] === $key) {
                $routes_per_play[$key][] = $r;
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════════════
// Phase 1: Diff (all plays — pure MySQL, no PostGIS)
// ══════════════════════════════════════════════════════════════════════
$traversal_needed = []; // traversal_key => route data
$play_state = [];

foreach ($play_scopes as $key => $def) {
    $play = _findOrCreatePlay($conn_sqli, $def, $session_id, $changed_by_str, $changed_by_name);

    // Idempotency: skip if revision already processed
    if ($play['external_revision'] !== null && (int)$play['external_revision'] >= $revision) {
        $play_state[$key] = ['play' => $play, 'skipped' => true];
        continue;
    }

    $current = _loadCurrentRoutes($conn_sqli, $play['play_id']);
    $diff    = _diffRoutes($current, $routes_per_play[$key]);

    $play_state[$key] = ['play' => $play, 'skipped' => false, 'diff' => $diff];

    // Collect unique route strings needing PostGIS traversal
    foreach ($diff['adds'] as $r) {
        $tk = _traversalKey($r);
        if (!isset($traversal_needed[$tk])) $traversal_needed[$tk] = $r;
    }
    foreach ($diff['route_changes'] as $rc) {
        $tk = _traversalKey($rc['new']);
        if (!isset($traversal_needed[$tk])) $traversal_needed[$tk] = $rc['new'];
    }
}

// ══════════════════════════════════════════════════════════════════════
// Phase 2: Traversal (deduplicated PostGIS — N calls, not 2N)
// ══════════════════════════════════════════════════════════════════════
$traversal_cache = [];
foreach ($traversal_needed as $tk => $r) {
    $rs     = normalizeRouteCanadian(trim($r['routestring']));
    $origin = trim($r['origin'] ?? '');
    $dest   = trim($r['dest'] ?? '');

    // computeTraversedFacilities handles PostGIS lazy-connect internally.
    // PostGIS artcc_boundaries contains only L1 ARTCC/FIR boundaries —
    // no sub-areas, deep-sub-areas, or supercenters — so results are
    // already filtered to top-level facilities.
    $tf = computeTraversedFacilities($rs, '', '', $origin, $dest, '', '');
    $traversal_cache[$tk] = $tf;
}

// ══════════════════════════════════════════════════════════════════════
// Phase 3: Write (per-play MySQL transactions)
// ══════════════════════════════════════════════════════════════════════
$session_ctx = json_encode([
    'source'         => 'ctp-route-planner',
    'revision'       => $revision,
    'ctp_session_id' => $session_id,
], JSON_UNESCAPED_SLASHES);

$response_plays = [];
foreach ($play_scopes as $key => $def) {
    $ps = $play_state[$key];
    if ($ps['skipped']) {
        $response_plays[$key] = [
            'play_id' => $ps['play']['play_id'], 'added' => 0,
            'updated' => 0, 'deleted' => 0, 'unchanged' => 0,
        ];
        continue;
    }

    $play    = $ps['play'];
    $diff    = $ps['diff'];
    $play_id = (int)$play['play_id'];

    $conn_sqli->begin_transaction();
    try {
        $added   = _insertRoutes($conn_sqli, $play_id, $diff['adds'], $traversal_cache);
        $upd_rc  = _updateRouteChanged($conn_sqli, $play_id, $diff['route_changes'], $traversal_cache);
        $upd_md  = _updateMetadataOnly($conn_sqli, $diff['metadata_changes']);
        $deleted = _deleteRoutes($conn_sqli, $diff['deletes']);
        _upsertThroughput($conn_sqli, $play_id, $diff);
        _writeChangelogs($conn_sqli, $play_id, $diff, $changed_by_str, $changed_by_name, $session_ctx);
        _updatePlayRevision($conn_sqli, $play_id, $revision, $changed_by_str);

        $conn_sqli->commit();
        $response_plays[$key] = [
            'play_id'   => $play_id,
            'added'     => $added,
            'updated'   => $upd_rc + $upd_md,
            'deleted'   => $deleted,
            'unchanged' => count($diff['unchanged']),
        ];
    } catch (\Exception $e) {
        $conn_sqli->rollback();
        $response_plays[$key] = [
            'play_id' => $play_id,
            'error'   => $e->getMessage(),
        ];
    }
}

SwimResponse::success([
    'revision' => $revision,
    'plays'    => $response_plays,
]);

// ══════════════════════════════════════════════════════════════════════
// Helper functions
// ══════════════════════════════════════════════════════════════════════

/**
 * Find existing CTP play by name or create it. Returns play row.
 */
function _findOrCreatePlay($conn, $def, $session_id, $changed_by, $changed_by_name) {
    $name      = $def['name'];
    $name_norm = normalizePlayName($name);

    $st = $conn->prepare("SELECT * FROM playbook_plays WHERE play_name_norm = ? AND source = 'CTP' LIMIT 1");
    $st->bind_param('s', $name_norm);
    $st->execute();
    $res = $st->get_result();
    $play = $res->fetch_assoc();
    $st->close();

    if ($play) return $play;

    // Auto-create play
    $ctp_scope   = $def['scope'];
    $description = $def['desc'];
    $src = 'CTP';
    $vis = 'private_org';
    $org = 'CTP';
    $cat = 'CTP';
    $status = 'active';
    $cb = $changed_by ?? '0';

    $ins = $conn->prepare("INSERT INTO playbook_plays
        (play_name, play_name_norm, display_name, description, category, source, status,
         visibility, org_code, ctp_scope, ctp_session_id, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param('sssssssssisis',
        $name, $name_norm, $name, $description, $cat, $src, $status,
        $vis, $org, $ctp_scope, $session_id, $cb, $cb);
    $ins->execute();
    $play_id = $conn->insert_id;
    $ins->close();

    // Changelog: play_created
    $cl = $conn->prepare("INSERT INTO playbook_changelog
        (play_id, action, changed_by, changed_by_name, session_context)
        VALUES (?, 'play_created', ?, ?, ?)");
    $ctx = json_encode(['source' => 'ctp-route-planner', 'ctp_session_id' => $session_id]);
    $cl->bind_param('isss', $play_id, $changed_by, $changed_by_name, $ctx);
    $cl->execute();
    $cl->close();

    // Re-fetch to get all columns (with defaults)
    $st2 = $conn->prepare("SELECT * FROM playbook_plays WHERE play_id = ?");
    $st2->bind_param('i', $play_id);
    $st2->execute();
    $play = $st2->get_result()->fetch_assoc();
    $st2->close();

    return $play;
}

/**
 * Load current CTP-sourced routes for a play, indexed by external_id.
 */
function _loadCurrentRoutes($conn, $play_id) {
    $st = $conn->prepare("SELECT * FROM playbook_routes WHERE play_id = ? AND external_source = 'CTP'");
    $st->bind_param('i', $play_id);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[$r['external_id']] = $r;
    }
    $st->close();
    return $rows;
}

/**
 * Diff incoming routes against current routes.
 * Returns: adds, route_changes, metadata_changes, deletes, unchanged
 */
function _diffRoutes($current, $incoming) {
    $adds = [];
    $route_changes = [];
    $metadata_changes = [];
    $deletes = [];
    $unchanged = [];

    $seen = [];
    foreach ($incoming as $r) {
        $eid = $r['identifier'];
        $seen[$eid] = true;

        if (!isset($current[$eid])) {
            $adds[] = $r;
            continue;
        }

        $old = $current[$eid];
        $rs_new = normalizeRouteCanadian(trim($r['routestring']));
        $rs_old = trim($old['route_string'] ?? '');

        $origin_new = trim($r['origin'] ?? '');
        $origin_old = trim($old['origin'] ?? '');
        $dest_new   = trim($r['dest'] ?? '');
        $dest_old   = trim($old['dest'] ?? '');

        // Check if route string or origin/dest changed (needs PostGIS recomputation)
        if (strtoupper($rs_new) !== strtoupper($rs_old)
            || strtoupper($origin_new) !== strtoupper($origin_old)
            || strtoupper($dest_new) !== strtoupper($dest_old)) {
            $route_changes[] = ['old' => $old, 'new' => $r];
            continue;
        }

        // Check metadata-only changes
        $ef_new = trim($r['facilities'] ?? '');
        $ef_old = trim($old['external_facilities'] ?? '');
        $et_new = trim($r['tags'] ?? '');
        $et_old = trim($old['external_tags'] ?? '');
        $eg_new = strtoupper(trim($r['group'] ?? ''));
        $eg_old = strtoupper(trim($old['external_group'] ?? ''));
        $of_new = trim($r['origin_filter'] ?? '');
        $of_old = trim($old['origin_filter'] ?? '');
        $df_new = trim($r['dest_filter'] ?? '');
        $df_old = trim($old['dest_filter'] ?? '');

        if ($ef_new !== $ef_old || $et_new !== $et_old || $eg_new !== $eg_old
            || $of_new !== $of_old || $df_new !== $df_old) {
            $metadata_changes[] = ['old' => $old, 'new' => $r];
            continue;
        }

        // Check throughput changes
        if (isset($r['throughput'])) {
            $metadata_changes[] = ['old' => $old, 'new' => $r];
            continue;
        }

        $unchanged[] = $eid;
    }

    // Routes in current but not in incoming → delete
    foreach ($current as $eid => $row) {
        if (!isset($seen[$eid])) {
            $deletes[] = $row;
        }
    }

    return compact('adds', 'route_changes', 'metadata_changes', 'deletes', 'unchanged');
}

/**
 * Cache key for traversal deduplication.
 */
function _traversalKey($r) {
    $rs = strtoupper(normalizeRouteCanadian(trim($r['routestring'])));
    $o  = strtoupper(trim($r['origin'] ?? ''));
    $d  = strtoupper(trim($r['dest'] ?? ''));
    return "{$rs}|{$o}|{$d}";
}

/**
 * Insert new routes with traversal data. Returns count inserted.
 */
function _insertRoutes($conn, $play_id, $adds, &$cache) {
    if (empty($adds)) return 0;

    $stmt = $conn->prepare("INSERT INTO playbook_routes
        (play_id, route_string, origin, origin_filter, dest, dest_filter,
         origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs,
         traversed_artccs, traversed_tracons,
         traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
         route_geometry, remarks, sort_order,
         external_id, external_source, external_group, external_facilities, external_tags)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $count = 0;
    $sort = 0;
    $ext_src = 'CTP';
    $empty = '';

    foreach ($adds as $r) {
        $rs = normalizeRouteCanadian(trim($r['routestring']));
        if ($rs === '') continue;

        $tk = _traversalKey($r);
        $tf = $cache[$tk] ?? ['artccs' => '', 'tracons' => '', 'sectors_low' => '',
            'sectors_high' => '', 'sectors_superhigh' => '', 'route_geometry' => null];

        $origin  = trim($r['origin'] ?? '');
        $dest    = trim($r['dest'] ?? '');
        $o_filt  = trim($r['origin_filter'] ?? '');
        $d_filt  = trim($r['dest_filter'] ?? '');
        $eid     = $r['identifier'];
        $eg      = strtoupper(trim($r['group'] ?? ''));
        $ef      = trim($r['facilities'] ?? '');
        $et      = trim($r['tags'] ?? '');
        $geom    = $tf['route_geometry'];

        $stmt->bind_param('issssssssssssssssssississs',
            $play_id, $rs, $origin, $o_filt, $dest, $d_filt,
            $empty, $empty, $empty,
            $empty, $empty, $empty,
            $tf['artccs'], $tf['tracons'],
            $tf['sectors_low'], $tf['sectors_high'], $tf['sectors_superhigh'],
            $geom, $empty, $sort,
            $eid, $ext_src, $eg, $ef, $et);
        $stmt->execute();
        $sort++;
        $count++;
    }
    $stmt->close();
    return $count;
}

/**
 * Update routes where route_string or origin/dest changed.
 */
function _updateRouteChanged($conn, $play_id, $changes, &$cache) {
    if (empty($changes)) return 0;

    $stmt = $conn->prepare("UPDATE playbook_routes SET
        route_string = ?, origin = ?, origin_filter = ?, dest = ?, dest_filter = ?,
        traversed_artccs = ?, traversed_tracons = ?,
        traversed_sectors_low = ?, traversed_sectors_high = ?, traversed_sectors_superhigh = ?,
        route_geometry = ?,
        external_group = ?, external_facilities = ?, external_tags = ?
        WHERE route_id = ?");

    $count = 0;
    foreach ($changes as $ch) {
        $r   = $ch['new'];
        $old = $ch['old'];
        $rs  = normalizeRouteCanadian(trim($r['routestring']));

        $tk = _traversalKey($r);
        $tf = $cache[$tk] ?? ['artccs' => '', 'tracons' => '', 'sectors_low' => '',
            'sectors_high' => '', 'sectors_superhigh' => '', 'route_geometry' => null];

        $origin = trim($r['origin'] ?? '');
        $dest   = trim($r['dest'] ?? '');
        $o_filt = trim($r['origin_filter'] ?? '');
        $d_filt = trim($r['dest_filter'] ?? '');
        $eg     = strtoupper(trim($r['group'] ?? ''));
        $ef     = trim($r['facilities'] ?? '');
        $et     = trim($r['tags'] ?? '');
        $geom   = $tf['route_geometry'];
        $rid    = (int)$old['route_id'];

        $stmt->bind_param('ssssssssssssssi',
            $rs, $origin, $o_filt, $dest, $d_filt,
            $tf['artccs'], $tf['tracons'],
            $tf['sectors_low'], $tf['sectors_high'], $tf['sectors_superhigh'],
            $geom,
            $eg, $ef, $et,
            $rid);
        $stmt->execute();
        $count++;
    }
    $stmt->close();
    return $count;
}

/**
 * Update metadata-only changes (no PostGIS recomputation).
 */
function _updateMetadataOnly($conn, $changes) {
    if (empty($changes)) return 0;

    $stmt = $conn->prepare("UPDATE playbook_routes SET
        origin_filter = ?, dest_filter = ?,
        external_group = ?, external_facilities = ?, external_tags = ?
        WHERE route_id = ?");

    $count = 0;
    foreach ($changes as $ch) {
        $r   = $ch['new'];
        $old = $ch['old'];

        $o_filt = trim($r['origin_filter'] ?? '');
        $d_filt = trim($r['dest_filter'] ?? '');
        $eg     = strtoupper(trim($r['group'] ?? ''));
        $ef     = trim($r['facilities'] ?? '');
        $et     = trim($r['tags'] ?? '');
        $rid    = (int)$old['route_id'];

        $stmt->bind_param('sssssi', $o_filt, $d_filt, $eg, $ef, $et, $rid);
        $stmt->execute();
        $count++;
    }
    $stmt->close();
    return $count;
}

/**
 * Delete routes no longer in the incoming set.
 */
function _deleteRoutes($conn, $deletes) {
    if (empty($deletes)) return 0;

    $ids = array_map(function($r) { return (int)$r['route_id']; }, $deletes);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $conn->prepare("DELETE FROM playbook_routes WHERE route_id IN ({$placeholders})");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/**
 * Upsert throughput data for routes that include it.
 */
function _upsertThroughput($conn, $play_id, $diff) {
    // Collect routes with throughput from adds, route_changes, and metadata_changes
    $throughput_routes = [];

    // For adds: we need the route_id from the just-inserted rows
    // Query by external_id to get the route_id
    foreach ($diff['adds'] as $r) {
        if (!isset($r['throughput']) || $r['throughput'] === null) continue;
        $throughput_routes[] = ['identifier' => $r['identifier'], 'throughput' => $r['throughput']];
    }
    foreach ($diff['route_changes'] as $ch) {
        $r = $ch['new'];
        if (!isset($r['throughput']) || $r['throughput'] === null) continue;
        $throughput_routes[] = ['identifier' => $r['identifier'], 'throughput' => $r['throughput'],
                                'route_id' => (int)$ch['old']['route_id']];
    }
    foreach ($diff['metadata_changes'] as $ch) {
        $r = $ch['new'];
        if (!isset($r['throughput']) || $r['throughput'] === null) continue;
        $throughput_routes[] = ['identifier' => $r['identifier'], 'throughput' => $r['throughput'],
                                'route_id' => (int)$ch['old']['route_id']];
    }

    if (empty($throughput_routes)) return;

    // Resolve route_ids for newly added routes
    foreach ($throughput_routes as &$tr) {
        if (!isset($tr['route_id'])) {
            $st = $conn->prepare("SELECT route_id FROM playbook_routes
                WHERE play_id = ? AND external_source = 'CTP' AND external_id = ? LIMIT 1");
            $st->bind_param('is', $play_id, $tr['identifier']);
            $st->execute();
            $res = $st->get_result();
            if ($row = $res->fetch_assoc()) {
                $tr['route_id'] = (int)$row['route_id'];
            }
            $st->close();
        }
    }
    unset($tr);

    // Upsert throughput
    foreach ($throughput_routes as $tr) {
        if (!isset($tr['route_id'])) continue;

        $tp = $tr['throughput'];
        $rid    = $tr['route_id'];
        $src    = 'CTP';
        $pc     = isset($tp['planned_count']) ? (int)$tp['planned_count'] : null;
        $sc     = isset($tp['slot_count']) ? (int)$tp['slot_count'] : null;
        $pr     = isset($tp['peak_rate_hr']) ? (int)$tp['peak_rate_hr'] : null;
        $ar     = isset($tp['avg_rate_hr']) ? (float)$tp['avg_rate_hr'] : null;
        $ps_val = $tp['period_start'] ?? null;
        $pe_val = $tp['period_end'] ?? null;
        $meta   = isset($tp['metadata']) ? json_encode($tp['metadata'], JSON_UNESCAPED_UNICODE) : null;
        $updby  = 'ctp_sync';

        // MySQL ON DUPLICATE KEY UPDATE (keyed on route_id + source)
        $st = $conn->prepare("INSERT INTO playbook_route_throughput
            (route_id, play_id, source, planned_count, slot_count, peak_rate_hr, avg_rate_hr,
             period_start, period_end, metadata_json, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                planned_count = VALUES(planned_count), slot_count = VALUES(slot_count),
                peak_rate_hr = VALUES(peak_rate_hr), avg_rate_hr = VALUES(avg_rate_hr),
                period_start = VALUES(period_start), period_end = VALUES(period_end),
                metadata_json = VALUES(metadata_json), updated_by = VALUES(updated_by)");
        $st->bind_param('iisiiidsssss',
            $rid, $play_id, $src, $pc, $sc, $pr, $ar,
            $ps_val, $pe_val, $meta, $updby);
        $st->execute();
        $st->close();
    }
}

/**
 * Write changelog entries for all changes in a play.
 */
function _writeChangelogs($conn, $play_id, $diff, $changed_by, $changed_by_name, $session_ctx) {
    $stmt = $conn->prepare("INSERT INTO playbook_changelog
        (play_id, route_id, action, field_name, old_value, new_value,
         changed_by, changed_by_name, session_context)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Added routes
    foreach ($diff['adds'] as $r) {
        $eid = $r['identifier'];
        // Look up route_id
        $rid_stmt = $conn->prepare("SELECT route_id FROM playbook_routes
            WHERE play_id = ? AND external_source = 'CTP' AND external_id = ? LIMIT 1");
        $rid_stmt->bind_param('is', $play_id, $eid);
        $rid_stmt->execute();
        $res = $rid_stmt->get_result();
        $rid = ($row = $res->fetch_assoc()) ? (int)$row['route_id'] : null;
        $rid_stmt->close();

        $action = 'route_added';
        $fn = null; $ov = null;
        $nv = $r['routestring'];
        $stmt->bind_param('iissssss' . 's',
            $play_id, $rid, $action, $fn, $ov, $nv,
            $changed_by, $changed_by_name, $session_ctx);
        $stmt->execute();
    }

    // Route-changed updates (log route_string and any field changes)
    foreach ($diff['route_changes'] as $ch) {
        $rid = (int)$ch['old']['route_id'];
        $fields = [
            'route_string'       => [trim($ch['old']['route_string'] ?? ''), normalizeRouteCanadian(trim($ch['new']['routestring']))],
            'origin'             => [trim($ch['old']['origin'] ?? ''),        trim($ch['new']['origin'] ?? '')],
            'dest'               => [trim($ch['old']['dest'] ?? ''),          trim($ch['new']['dest'] ?? '')],
            'origin_filter'      => [trim($ch['old']['origin_filter'] ?? ''), trim($ch['new']['origin_filter'] ?? '')],
            'dest_filter'        => [trim($ch['old']['dest_filter'] ?? ''),   trim($ch['new']['dest_filter'] ?? '')],
            'external_group'     => [trim($ch['old']['external_group'] ?? ''), strtoupper(trim($ch['new']['group'] ?? ''))],
            'external_facilities'=> [trim($ch['old']['external_facilities'] ?? ''), trim($ch['new']['facilities'] ?? '')],
            'external_tags'      => [trim($ch['old']['external_tags'] ?? ''), trim($ch['new']['tags'] ?? '')],
        ];
        foreach ($fields as $fn => [$ov, $nv]) {
            if ($ov !== $nv) {
                $action = 'route_updated';
                $stmt->bind_param('iissssss' . 's',
                    $play_id, $rid, $action, $fn, $ov, $nv,
                    $changed_by, $changed_by_name, $session_ctx);
                $stmt->execute();
            }
        }
    }

    // Metadata-only updates
    foreach ($diff['metadata_changes'] as $ch) {
        $rid = (int)$ch['old']['route_id'];
        $fields = [
            'origin_filter'      => [trim($ch['old']['origin_filter'] ?? ''), trim($ch['new']['origin_filter'] ?? '')],
            'dest_filter'        => [trim($ch['old']['dest_filter'] ?? ''),   trim($ch['new']['dest_filter'] ?? '')],
            'external_group'     => [trim($ch['old']['external_group'] ?? ''), strtoupper(trim($ch['new']['group'] ?? ''))],
            'external_facilities'=> [trim($ch['old']['external_facilities'] ?? ''), trim($ch['new']['facilities'] ?? '')],
            'external_tags'      => [trim($ch['old']['external_tags'] ?? ''), trim($ch['new']['tags'] ?? '')],
        ];
        foreach ($fields as $fn => [$ov, $nv]) {
            if ($ov !== $nv) {
                $action = 'route_updated';
                $stmt->bind_param('iissssss' . 's',
                    $play_id, $rid, $action, $fn, $ov, $nv,
                    $changed_by, $changed_by_name, $session_ctx);
                $stmt->execute();
            }
        }
    }

    // Deleted routes
    foreach ($diff['deletes'] as $r) {
        $rid = (int)$r['route_id'];
        $action = 'route_deleted';
        $fn = null;
        $ov = $r['route_string'];
        $nv = null;
        $stmt->bind_param('iissssss' . 's',
            $play_id, $rid, $action, $fn, $ov, $nv,
            $changed_by, $changed_by_name, $session_ctx);
        $stmt->execute();
    }

    $stmt->close();
}

/**
 * Update play revision and route_count after sync.
 */
function _updatePlayRevision($conn, $play_id, $revision, $changed_by) {
    $stmt = $conn->prepare("UPDATE playbook_plays SET
        external_revision = ?,
        route_count = (SELECT COUNT(*) FROM playbook_routes WHERE play_id = ?),
        updated_by = ?,
        updated_at = CURRENT_TIMESTAMP
        WHERE play_id = ?");
    $stmt->bind_param('iisi', $revision, $play_id, $changed_by, $play_id);
    $stmt->execute();
    $stmt->close();
}
