<?php
/**
 * CTP Playbook Sync Service
 *
 * Shared 3-phase sync algorithm used by both the push endpoint
 * (api/swim/v1/ingest/ctp-routes.php) and the pull orchestrator
 * (scripts/ctp/ctp_pull_sync.php).
 *
 * Phase 1: Diff — compute adds/updates/deletes per play (pure MySQL)
 * Phase 2: Traversal — deduplicated PostGIS calls for route geometry
 * Phase 3: Write — per-play MySQL transactions with changelog
 */

require_once __DIR__ . '/../../api/mgt/playbook/playbook_helpers.php';

class CTPPlaybookSync
{
    /**
     * Run the full 3-phase sync for a set of CTP routes.
     *
     * @param mysqli  $conn               MySQL connection (perti_site)
     * @param array   $routes             Routes in internal format:
     *                                    [{identifier, group, routestring, tags, facilities,
     *                                      origin, dest, origin_filter, dest_filter, throughput?}]
     * @param string  $event_code         Event code for play naming (e.g., 'CTPE26')
     * @param int     $session_id         CTP session ID
     * @param int     $revision           Revision number (CTP-provided or synthetic)
     * @param array   $group_mapping      Maps CTP group → play key: ['OCA'=>'oca','AMAS'=>'na','EMEA'=>'eu','FULL'=>'full']
     * @param ?string $changed_by_cid     VATSIM CID of the person who made the change (null = system)
     * @param bool    $skip_revision_check When true (pull mode), bypass per-play external_revision idempotency
     * @param array   $slot_routes        Slot-assigned routes from CTPApiClient::extractSlotRoutes() (optional)
     * @return array  ['revision' => N, 'plays' => ['slotted' => [...], 'full' => [...], 'na' => [...], ...]]
     */
    public static function run(
        \mysqli $conn,
        array   $routes,
        string  $event_code,
        int     $session_id,
        int     $revision,
        array   $group_mapping,
        ?string $changed_by_cid = null,
        bool    $skip_revision_check = false,
        array   $slot_routes = []
    ): array {
        // ── Resolve author ──────────────────────────────────────────
        $changed_by_name = 'CTP Route Planner';
        $changed_by_str  = $changed_by_cid ? (string)$changed_by_cid : null;
        if ($changed_by_cid) {
            $u = $conn->prepare("SELECT name_first, name_last FROM users WHERE cid = ?");
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

        // ── Play scope definitions ──────────────────────────────────
        // 'slotted' = CTPE26 (routes actually assigned to slots)
        // 'full'    = CTPE26_FULL (all combinatorial pivot-point matches)
        $play_scopes = [
            'slotted' => ['name' => $event_code,               'scope' => null,      'desc' => "{$event_code} - Slot-Assigned Routes"],
            'full'    => ['name' => "{$event_code}_FULL",      'scope' => null,      'desc' => "{$event_code} - All Combinatorial Routes"],
            'na'      => ['name' => "{$event_code}_NA",        'scope' => 'NA',      'desc' => "{$event_code} - North America"],
            'eu'      => ['name' => "{$event_code}_EU",        'scope' => 'EU',      'desc' => "{$event_code} - Europe"],
            'oca'     => ['name' => "{$event_code}_OCA",       'scope' => 'OCEANIC', 'desc' => "{$event_code} - Oceanic"],
        ];

        // Build group→play-key reverse map
        $group_to_play = [];
        foreach ($group_mapping as $grp => $play_key) {
            $group_to_play[strtoupper($grp)] = strtolower($play_key);
        }

        // Filter routes per play — each segment route goes to its group play
        $routes_per_play = [];
        foreach ($play_scopes as $key => $def) {
            $routes_per_play[$key] = [];
        }
        $warnings = [];
        foreach ($routes as $r) {
            $g = strtoupper($r['group'] ?? '');
            if (isset($group_to_play[$g]) && isset($play_scopes[$group_to_play[$g]])) {
                $routes_per_play[$group_to_play[$g]][] = $r;
            } else {
                $warnings[] = "Route '{$r['identifier']}' has unmapped group '{$g}' — skipped";
            }
        }

        // Stitch all combinatorial full routes → CTPE26_FULL
        $routes_per_play['full'] = self::stitchFullRoutes(
            $routes_per_play['na'] ?? [],
            $routes_per_play['oca'] ?? [],
            $routes_per_play['eu'] ?? []
        );

        // Slot-assigned routes → CTPE26 (subset of combos actually assigned to slots)
        $routes_per_play['slotted'] = $slot_routes;

        // ══════════════════════════════════════════════════════════════
        // Phase 1: Diff (all plays — pure MySQL, no PostGIS)
        // ══════════════════════════════════════════════════════════════
        $traversal_needed = [];
        $play_state = [];

        foreach ($play_scopes as $key => $def) {
            $play = self::findOrCreatePlay($conn, $def, $session_id, $changed_by_str, $changed_by_name);

            // Cleanup: remove routes without CTP external_source (legacy manual imports)
            $cleanup = $conn->prepare("DELETE FROM playbook_routes WHERE play_id = ? AND (external_source IS NULL OR external_source = '')");
            $cleanup->bind_param('i', $play['play_id']);
            $cleanup->execute();
            $cleaned = $cleanup->affected_rows;
            $cleanup->close();
            if ($cleaned > 0) {
                $warnings[] = "Cleaned {$cleaned} orphaned routes from play '{$def['name']}'";
            }

            // Idempotency: skip if revision already processed (push mode only)
            if (!$skip_revision_check
                && $play['external_revision'] !== null
                && (int)$play['external_revision'] >= $revision) {
                $play_state[$key] = ['play' => $play, 'skipped' => true];
                continue;
            }

            $current = self::loadCurrentRoutes($conn, $play['play_id']);
            $diff    = self::diffRoutes($current, $routes_per_play[$key]);

            $play_state[$key] = ['play' => $play, 'skipped' => false, 'diff' => $diff];

            foreach ($diff['adds'] as $r) {
                $tk = self::traversalKey($r);
                if (!isset($traversal_needed[$tk])) $traversal_needed[$tk] = $r;
            }
            foreach ($diff['route_changes'] as $rc) {
                $tk = self::traversalKey($rc['new']);
                if (!isset($traversal_needed[$tk])) $traversal_needed[$tk] = $rc['new'];
            }
        }

        // ══════════════════════════════════════════════════════════════
        // Phase 2: Traversal (deduplicated PostGIS)
        // ══════════════════════════════════════════════════════════════
        $traversal_cache = [];
        foreach ($traversal_needed as $tk => $r) {
            $rs     = normalizeRouteCanadian(trim($r['routestring']));
            $origin = trim($r['origin'] ?? '');
            $dest   = trim($r['dest'] ?? '');
            $tf = computeTraversedFacilities($rs, '', '', $origin, $dest, '', '');
            $traversal_cache[$tk] = $tf;
        }

        // ══════════════════════════════════════════════════════════════
        // Phase 3: Write (per-play MySQL transactions)
        // ══════════════════════════════════════════════════════════════
        $session_ctx = json_encode([
            'source'         => 'ctp-sync',
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

            $conn->begin_transaction();
            try {
                $added   = self::insertRoutes($conn, $play_id, $diff['adds'], $traversal_cache);
                $upd_rc  = self::updateRouteChanged($conn, $play_id, $diff['route_changes'], $traversal_cache);
                $upd_md  = self::updateMetadataOnly($conn, $diff['metadata_changes']);
                $deleted = self::deleteRoutes($conn, $diff['deletes']);
                self::upsertThroughput($conn, $play_id, $diff);
                self::writeChangelogs($conn, $play_id, $diff, $changed_by_str, $changed_by_name, $session_ctx);
                self::updatePlayRevision($conn, $play_id, $revision, $changed_by_str);

                $conn->commit();
                $response_plays[$key] = [
                    'play_id'   => $play_id,
                    'added'     => $added,
                    'updated'   => $upd_rc + $upd_md,
                    'deleted'   => $deleted,
                    'unchanged' => count($diff['unchanged']),
                ];
            } catch (\Exception $e) {
                $conn->rollback();
                $response_plays[$key] = [
                    'play_id' => $play_id,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        $result = ['revision' => $revision, 'plays' => $response_plays];
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }
        return $result;
    }

    // ══════════════════════════════════════════════════════════════════
    // Helper methods (extracted from ctp-routes.php)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Find existing CTP play by name or create it. Returns play row.
     */
    public static function findOrCreatePlay(\mysqli $conn, array $def, int $session_id,
                                             ?string $changed_by, string $changed_by_name): array {
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
        $ins->bind_param('ssssssssssiss',
            $name, $name_norm, $name, $description, $cat, $src, $status,
            $vis, $org, $ctp_scope, $session_id, $cb, $cb);
        $ins->execute();
        $play_id = $conn->insert_id;
        $ins->close();

        // Changelog: play_created
        $cl = $conn->prepare("INSERT INTO playbook_changelog
            (play_id, action, changed_by, changed_by_name, session_context)
            VALUES (?, 'play_created', ?, ?, ?)");
        $ctx = json_encode(['source' => 'ctp-sync', 'ctp_session_id' => $session_id]);
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
    public static function loadCurrentRoutes(\mysqli $conn, int $play_id): array {
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
    public static function diffRoutes(array $current, array $incoming): array {
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

            if (strtoupper($rs_new) !== strtoupper($rs_old)
                || strtoupper($origin_new) !== strtoupper($origin_old)
                || strtoupper($dest_new) !== strtoupper($dest_old)) {
                $route_changes[] = ['old' => $old, 'new' => $r];
                continue;
            }

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

            if (isset($r['throughput'])) {
                $metadata_changes[] = ['old' => $old, 'new' => $r];
                continue;
            }

            $unchanged[] = $eid;
        }

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
    public static function traversalKey(array $r): string {
        $rs = strtoupper(normalizeRouteCanadian(trim($r['routestring'])));
        $o  = strtoupper(trim($r['origin'] ?? ''));
        $d  = strtoupper(trim($r['dest'] ?? ''));
        return "{$rs}|{$o}|{$d}";
    }

    /**
     * Stitch full end-to-end routes from NA + OCA + EU segments.
     *
     * NA segment: airport → oceanic entry point (last fix in routestring)
     * OCA segment: oceanic entry → oceanic exit (first/last fix)
     * EU segment: oceanic exit → destination airport (first fix → airport)
     *
     * Joins at shared pivot points, deduplicating the pivot fix.
     *
     * @param array $na  NA/AMAS segment routes (internal format)
     * @param array $oca OCA segment routes (internal format)
     * @param array $eu  EU/EMEA segment routes (internal format)
     * @return array Full routes in internal format with group='FULL'
     */
    public static function stitchFullRoutes(array $na, array $oca, array $eu): array {
        // NA: extract last token of routestring as oceanic entry point
        // OCA: first token = entry, last token = exit
        // EU: first token = exit point

        // Index OCA by entry point (first token of routestring)
        $ocaByEntry = [];
        foreach ($oca as $r) {
            $parts = preg_split('/\s+/', trim($r['routestring']));
            if (empty($parts)) continue;
            $entry = strtoupper($parts[0]);
            $ocaByEntry[$entry][] = $r;
        }

        // Index EU by exit point (first token of routestring)
        $euByExit = [];
        foreach ($eu as $r) {
            $parts = preg_split('/\s+/', trim($r['routestring']));
            if (empty($parts)) continue;
            $exit = strtoupper($parts[0]);
            $euByExit[$exit][] = $r;
        }

        $full = [];
        foreach ($na as $rNa) {
            $naParts = preg_split('/\s+/', trim($rNa['routestring']));
            if (empty($naParts)) continue;
            $ocaEntry = strtoupper(end($naParts));

            // Find OCA segments starting at this entry point
            if (!isset($ocaByEntry[$ocaEntry])) continue;

            foreach ($ocaByEntry[$ocaEntry] as $rOca) {
                $ocaParts = preg_split('/\s+/', trim($rOca['routestring']));
                $ocaExit = strtoupper(end($ocaParts));

                // Find EU segments starting at this exit point
                if (!isset($euByExit[$ocaExit])) continue;

                foreach ($euByExit[$ocaExit] as $rEu) {
                    $euParts = preg_split('/\s+/', trim($rEu['routestring']));

                    // Stitch: NA full + OCA (skip entry pivot) + EU (skip exit pivot)
                    $ocaContinuation = implode(' ', array_slice($ocaParts, 1));
                    $euContinuation  = implode(' ', array_slice($euParts, 1));

                    $fullRs = trim($rNa['routestring'])
                        . ($ocaContinuation !== '' ? ' ' . $ocaContinuation : '')
                        . ($euContinuation !== ''  ? ' ' . $euContinuation  : '');

                    // Origin/dest from route fields (routestrings already have airports stripped)
                    $origin = strtoupper($rNa['origin'] ?? '');
                    $dest   = strtoupper($rEu['dest'] ?? '');

                    $eid = $rNa['identifier'] . '_' . $rOca['identifier'] . '_' . $rEu['identifier'];

                    $full[] = [
                        'identifier'  => $eid,
                        'group'       => 'FULL',
                        'routestring' => $fullRs,
                        'tags'        => trim($rNa['identifier'] . ' ' . $rOca['identifier'] . ' ' . $rEu['identifier']),
                        'facilities'  => '',
                        'color'       => '',
                        'enabled'     => true,
                        'origin'      => $origin,
                        'dest'        => $dest,
                    ];
                }
            }
        }

        return $full;
    }

    /**
     * Insert new routes with traversal data. Returns count inserted.
     */
    public static function insertRoutes(\mysqli $conn, int $play_id, array $adds, array &$cache): int {
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

            $tk = self::traversalKey($r);
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

            $stmt->bind_param('issssssssssssssssssisssss',
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
    public static function updateRouteChanged(\mysqli $conn, int $play_id, array $changes, array &$cache): int {
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

            $tk = self::traversalKey($r);
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
    public static function updateMetadataOnly(\mysqli $conn, array $changes): int {
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
    public static function deleteRoutes(\mysqli $conn, array $deletes): int {
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
    public static function upsertThroughput(\mysqli $conn, int $play_id, array $diff): void {
        $throughput_routes = [];

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

            $st = $conn->prepare("INSERT INTO playbook_route_throughput
                (route_id, play_id, source, planned_count, slot_count, peak_rate_hr, avg_rate_hr,
                 period_start, period_end, metadata_json, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    planned_count = VALUES(planned_count), slot_count = VALUES(slot_count),
                    peak_rate_hr = VALUES(peak_rate_hr), avg_rate_hr = VALUES(avg_rate_hr),
                    period_start = VALUES(period_start), period_end = VALUES(period_end),
                    metadata_json = VALUES(metadata_json), updated_by = VALUES(updated_by)");
            $st->bind_param('iisiiidssss',
                $rid, $play_id, $src, $pc, $sc, $pr, $ar,
                $ps_val, $pe_val, $meta, $updby);
            $st->execute();
            $st->close();
        }
    }

    /**
     * Write changelog entries for all changes in a play.
     */
    public static function writeChangelogs(\mysqli $conn, int $play_id, array $diff,
                                            ?string $changed_by, string $changed_by_name,
                                            string $session_ctx): void {
        $stmt = $conn->prepare("INSERT INTO playbook_changelog
            (play_id, route_id, action, field_name, old_value, new_value,
             changed_by, changed_by_name, session_context)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Added routes
        foreach ($diff['adds'] as $r) {
            $eid = $r['identifier'];
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

        // Route-changed updates
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
    public static function updatePlayRevision(\mysqli $conn, int $play_id, int $revision, ?string $changed_by): void {
        $stmt = $conn->prepare("UPDATE playbook_plays SET
            external_revision = ?,
            route_count = (SELECT COUNT(*) FROM playbook_routes WHERE play_id = ?),
            updated_by = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE play_id = ?");
        $cb = $changed_by ?? '0';
        $stmt->bind_param('iisi', $revision, $play_id, $cb, $play_id);
        $stmt->execute();
        $stmt->close();
    }
}
