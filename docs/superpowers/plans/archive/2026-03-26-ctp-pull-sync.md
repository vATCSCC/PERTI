# CTP Pull-Based Playbook Sync — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a pull-based sync mechanism that polls the CTP API's `GET /api/Routes` endpoint and syncs routes into 4 PERTI playbook plays (`CTPE26`, `CTPE26_NA`, `CTPE26_EU`, `CTPE26_OCA`), reusing the existing push endpoint's sync algorithm via a shared service class.

**Architecture:** Extract the sync logic from `api/swim/v1/ingest/ctp-routes.php` into `load/services/CTPPlaybookSync.php`. Build a CTP API client (`load/services/CTPApiClient.php`) that fetches, transforms, and content-hashes route data. A new pull orchestrator (`scripts/ctp/ctp_pull_sync.php`) calls both. The push endpoint becomes a thin wrapper. All CTP plays are `private_org` / `org_code = 'CTP'`.

**Tech Stack:** PHP 8.2, MySQL 8 (perti_site), PostGIS (VATSIM_GIS), cURL for CTP API

**Spec:** `docs/superpowers/specs/2026-03-26-ctp-pull-sync-design.md`

**No automated test suite** — this project tests manually. Each task includes verification steps using PHP CLI or browser.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `load/config.php` | **Modify** — Add 6 CTP_PULL/CTP_EVENT/CTP_API constants |
| `database/migrations/playbook/015_ctp_pull_sync_state.sql` | **Create** — Sync state table |
| `load/services/CTPPlaybookSync.php` | **Create** — Extracted sync service: 3-phase diff/traverse/write algorithm |
| `load/services/CTPApiClient.php` | **Create** — CTP API HTTP client + data transformer + content hasher |
| `api/swim/v1/ingest/ctp-routes.php` | **Modify** — Thin wrapper over CTPPlaybookSync, updated play naming |
| `scripts/ctp/ctp_pull_sync.php` | **Create** — HTTP-triggered pull orchestrator |

---

### Task 1: Add CTP configuration constants

**Files:**
- Modify: `load/config.php:180-196` (feature flags section, before closing `}`)

- [ ] **Step 1: Add CTP constants before the closing `}` of the `if (!defined("SQL_USERNAME"))` block**

In `load/config.php`, find the line `define("HIBERNATION_MODE", env('HIBERNATION_MODE', true));` (line 195) and add the CTP constants immediately after it:

```php
    // =========================================================================
    // CTP PULL-BASED SYNC CONFIGURATION
    // =========================================================================
    define('CTP_PULL_ENABLED',  (bool)env('CTP_PULL_ENABLED', '0'));
    define('CTP_PULL_SECRET',   env('CTP_PULL_SECRET', ''));
    define('CTP_API_URL',       env('CTP_API_URL', ''));
    define('CTP_API_KEY',       env('CTP_API_KEY', ''));
    define('CTP_EVENT_CODE',    env('CTP_EVENT_CODE', ''));
    define('CTP_SESSION_ID',    (int)env('CTP_SESSION_ID', '0'));
    define('CTP_GROUP_MAPPING', json_decode(
        env('CTP_GROUP_MAPPING', '{"OCA":"oca","AMAS":"na","EMEA":"eu","FULL":"full"}'),
        true
    ) ?: ['OCA' => 'oca', 'AMAS' => 'na', 'EMEA' => 'eu', 'FULL' => 'full']);
```

- [ ] **Step 2: Verify syntax**

Run: `php -l load/config.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add load/config.php
git commit -m "feat(ctp): add pull sync configuration constants"
```

---

### Task 2: Create migration for sync state table

**Files:**
- Create: `database/migrations/playbook/015_ctp_pull_sync_state.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- ============================================================================
-- Migration: 015_ctp_pull_sync_state.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   State tracking for CTP pull-based sync (content hash, revision)
-- Depends:   014_ctp_external_fields.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS ctp_pull_sync_state (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    session_id      INT NOT NULL,
    event_code      VARCHAR(20) NOT NULL DEFAULT '',
    content_hash    VARCHAR(32) NULL DEFAULT NULL,
    synthetic_rev   INT NOT NULL DEFAULT 0,
    route_count     INT NOT NULL DEFAULT 0,
    last_sync_at    DATETIME NULL DEFAULT NULL,
    last_check_at   DATETIME NULL DEFAULT NULL,
    last_error      TEXT NULL DEFAULT NULL,
    status          ENUM('idle','syncing','error') NOT NULL DEFAULT 'idle',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Verify SQL syntax**

Run: `php -r "echo 'OK';"` (migration is applied manually via `scripts/run_migration.php` or direct SQL)

- [ ] **Step 3: Commit**

```bash
git add database/migrations/playbook/015_ctp_pull_sync_state.sql
git commit -m "feat(ctp): add pull sync state table migration 015"
```

---

### Task 3: Extract CTPPlaybookSync service from ctp-routes.php

This is the largest task — extract all 11 helper functions from `ctp-routes.php` into a reusable service class, plus add a `run()` orchestrator that encapsulates the 3-phase algorithm. The service also updates the play naming convention from `CTPE26-{session_id}-FULL` to `{event_code}` / `{event_code}_NA` / `{event_code}_EU` / `{event_code}_OCA`, and changes the route-to-play mapping so each route goes to exactly one play (no "all routes" FULL play).

**Files:**
- Create: `load/services/CTPPlaybookSync.php`

**Docs to reference:**
- `docs/superpowers/specs/2026-03-26-ctp-pull-sync-design.md` — Play structure, group mapping, permissions
- `api/mgt/playbook/playbook_helpers.php` — `computeTraversedFacilities()` signature (7 params), `normalizeRouteCanadian()`, `normalizePlayName()`

- [ ] **Step 1: Create the service file with class skeleton and `run()` method**

Create `load/services/CTPPlaybookSync.php`. The `run()` method implements the 3-phase algorithm (diff → traverse → write) that was previously inline in `ctp-routes.php`. Key changes from the original:

1. **Play naming**: Uses `$event_code` directly (e.g., `CTPE26`) instead of `CTPE26-{session_id}-FULL`
2. **Route-to-play mapping**: Each route goes to exactly one play based on its group. No "all routes" FULL play.
3. **`$skip_revision_check`**: When `true` (pull mode), bypasses per-play `external_revision` idempotency check
4. **Valid scopes**: `['full', 'na', 'eu', 'oca']` (was `['ocean', 'na', 'emea']`)

```php
<?php
/**
 * CTP Playbook Sync Service
 *
 * Shared 3-phase sync algorithm used by both the push endpoint
 * (api/swim/v1/ingest/ctp-routes.php) and the pull orchestrator
 * (scripts/ctp/ctp_pull_sync.php).
 *
 * Phase 1: Diff — compute adds/updates/deletes per play (pure MySQL)
 * Phase 2: Traverse — deduplicated PostGIS calls for route geometry
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
     * @return array  ['revision' => N, 'plays' => ['full' => [...], 'na' => [...], ...]]
     */
    public static function run(
        \mysqli $conn,
        array   $routes,
        string  $event_code,
        int     $session_id,
        int     $revision,
        array   $group_mapping,
        ?string $changed_by_cid = null,
        bool    $skip_revision_check = false
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
        $play_scopes = [
            'full' => ['name' => $event_code,               'scope' => null,      'desc' => "{$event_code} - Full Routes"],
            'na'   => ['name' => "{$event_code}_NA",        'scope' => 'NA',      'desc' => "{$event_code} - North America"],
            'eu'   => ['name' => "{$event_code}_EU",        'scope' => 'EU',      'desc' => "{$event_code} - Europe"],
            'oca'  => ['name' => "{$event_code}_OCA",       'scope' => 'OCEANIC', 'desc' => "{$event_code} - Oceanic"],
        ];

        // Build group→play-key reverse map
        $group_to_play = [];
        foreach ($group_mapping as $grp => $play_key) {
            $group_to_play[strtoupper($grp)] = strtolower($play_key);
        }

        // Filter routes per play — each route goes to exactly one play
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

        // ══════════════════════════════════════════════════════════════
        // Phase 1: Diff (all plays — pure MySQL, no PostGIS)
        // ══════════════════════════════════════════════════════════════
        $traversal_needed = [];
        $play_state = [];

        foreach ($play_scopes as $key => $def) {
            $play = self::findOrCreatePlay($conn, $def, $session_id, $changed_by_str, $changed_by_name);

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
```

- [ ] **Step 2: Verify syntax**

Run: `php -l load/services/CTPPlaybookSync.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add load/services/CTPPlaybookSync.php
git commit -m "feat(ctp): extract CTPPlaybookSync service from ctp-routes.php"
```

---

### Task 4: Update ctp-routes.php to use the shared service

Replace the 700-line push endpoint with a thin wrapper that validates the request and calls `CTPPlaybookSync::run()`. Also updates `valid_scopes` to include `'full'` and `'oca'`, and accepts `event_code` in the payload (defaulting to `CTP_EVENT_CODE` config).

**Files:**
- Modify: `api/swim/v1/ingest/ctp-routes.php` (full rewrite — keeping auth + validation, delegating sync)

- [ ] **Step 1: Rewrite ctp-routes.php as a thin wrapper**

Replace the entire file with:

```php
<?php
/**
 * VATSWIM API v1 — CTP Route Planner → Playbook Sync (Push Endpoint)
 *
 * Full-state sync: CTP sends complete route set after every save.
 * Delegates to CTPPlaybookSync service for the actual sync algorithm.
 *
 * POST /api/swim/v1/ingest/ctp-routes.php
 *
 * @version 2.0.0
 */

// ── Bootstrap ─────────────────────────────────────────────────────────
// Load MySQL + PostGIS BEFORE SWIM auth (auth.php would set PERTI_SWIM_ONLY)
require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';
require_once __DIR__ . '/../../../load/services/CTPPlaybookSync.php';
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
$event_code     = trim($body['event_code'] ?? CTP_EVENT_CODE);

if ($session_id <= 0) SwimResponse::error('session_id is required (positive integer)', 400, 'MISSING_PARAM');
if ($revision <= 0)   SwimResponse::error('revision is required (positive integer)', 400, 'MISSING_PARAM');
if (!is_array($group_mapping) || empty($group_mapping))
    SwimResponse::error('group_mapping object is required', 400, 'MISSING_PARAM');
if (!is_array($routes_in))
    SwimResponse::error('routes array is required', 400, 'MISSING_PARAM');
if (count($routes_in) > 1000)
    SwimResponse::error('Maximum 1000 routes per request', 400, 'BATCH_LIMIT');
if ($event_code === '')
    SwimResponse::error('event_code is required (in payload or CTP_EVENT_CODE config)', 400, 'MISSING_PARAM');

$valid_scopes = ['full', 'na', 'eu', 'oca'];
foreach ($group_mapping as $grp => $scp) {
    if (!in_array(strtolower($scp), $valid_scopes))
        SwimResponse::error("Invalid scope '{$scp}' in group_mapping. Valid: " . implode(', ', $valid_scopes), 400, 'INVALID_PARAM');
}

foreach ($routes_in as $idx => $r) {
    if (empty($r['identifier']))  SwimResponse::error("Route[$idx]: identifier is required", 400, 'INVALID_ROUTE');
    if (!isset($r['group']))      SwimResponse::error("Route[$idx]: group is required", 400, 'INVALID_ROUTE');
    if (empty($r['routestring'])) SwimResponse::error("Route[$idx]: routestring is required", 400, 'INVALID_ROUTE');
}

// ── Delegate to sync service ─────────────────────────────────────────
global $conn_sqli;

$result = CTPPlaybookSync::run(
    $conn_sqli,
    $routes_in,
    $event_code,
    $session_id,
    $revision,
    $group_mapping,
    $changed_by_cid ? (string)$changed_by_cid : null,
    false  // skip_revision_check = false (push uses revision-based idempotency)
);

SwimResponse::success($result);
```

- [ ] **Step 2: Verify syntax**

Run: `php -l api/swim/v1/ingest/ctp-routes.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/swim/v1/ingest/ctp-routes.php
git commit -m "refactor(ctp): rewrite push endpoint as thin wrapper over CTPPlaybookSync"
```

---

### Task 5: Create CTPApiClient

HTTP client that fetches routes from the CTP API, transforms CTP `RouteSegment` format to PERTI internal format, and computes content hashes.

**Files:**
- Create: `load/services/CTPApiClient.php`

- [ ] **Step 1: Create the client file**

```php
<?php
/**
 * CTP API Client
 *
 * Fetches routes from the CTP API (vatsimnetwork/ctp-api), transforms
 * RouteSegment objects to PERTI internal format, and computes content
 * hashes for change detection.
 */

class CTPApiException extends \RuntimeException {}

class CTPApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Fetch all routes from CTP API.
     *
     * GET /api/Routes — returns RouteSegment[] with Locations[].
     * ASP.NET Core serializes properties as camelCase.
     *
     * @return array Raw CTP API response (array of RouteSegment objects)
     * @throws CTPApiException on HTTP error, timeout, or invalid JSON
     */
    public function fetchRoutes(): array {
        $url = $this->baseUrl . '/api/Routes';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            // Retry once on timeout
            if (stripos($curlErr, 'timeout') !== false || stripos($curlErr, 'timed out') !== false) {
                $ch2 = curl_init($url);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_HTTPHEADER     => [
                        'X-API-Key: ' . $this->apiKey,
                        'Accept: application/json',
                    ],
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                ]);
                $response = curl_exec($ch2);
                $httpCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $curlErr  = curl_error($ch2);
                curl_close($ch2);
            }
            if ($response === false) {
                throw new CTPApiException("CTP API request failed: {$curlErr}");
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new CTPApiException("CTP API returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new CTPApiException("CTP API returned invalid JSON");
        }

        return $data;
    }

    /**
     * Check if CTP API is reachable.
     *
     * @return bool True if API responds with 2xx
     */
    public function isAvailable(): bool {
        try {
            $ch = curl_init($this->baseUrl . '/api/Routes');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'X-API-Key: ' . $this->apiKey,
                    'Accept: application/json',
                ],
                CURLOPT_NOBODY => true,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Transform CTP API RouteSegment objects to PERTI internal route format.
     *
     * CTP API (camelCase):              PERTI internal:
     * - identifier                   -> identifier
     * - routeString                  -> routestring
     * - routeSegmentGroup            -> group
     * - routeSegmentTags (array)     -> tags (space-separated)
     * - locations[0].identifier      -> origin
     * - locations[-1].identifier     -> dest
     * - maximumAircraftPerHour       -> throughput.peak_rate_hr (if > 0)
     *
     * @param array $ctpRoutes Raw CTP API response
     * @return array Routes in PERTI internal format
     */
    public static function transformRoutes(array $ctpRoutes): array {
        $result = [];
        foreach ($ctpRoutes as $seg) {
            $route = [
                'identifier'  => $seg['identifier'] ?? '',
                'group'       => $seg['routeSegmentGroup'] ?? '',
                'routestring' => $seg['routeString'] ?? '',
                'tags'        => is_array($seg['routeSegmentTags'] ?? null)
                                 ? implode(' ', $seg['routeSegmentTags'])
                                 : '',
                'facilities'  => '',
            ];

            // Extract origin/dest from Locations array
            $locs = $seg['locations'] ?? [];
            if (!empty($locs)) {
                $route['origin'] = $locs[0]['identifier'] ?? '';
                $lastLoc = end($locs);
                $route['dest'] = $lastLoc['identifier'] ?? '';
            } else {
                $route['origin'] = '';
                $route['dest']   = '';
            }

            // Extract throughput from maximumAircraftPerHour
            $maxRate = (int)($seg['maximumAircraftPerHour'] ?? 0);
            if ($maxRate > 0) {
                $route['throughput'] = ['peak_rate_hr' => $maxRate];
            }

            $result[] = $route;
        }
        return $result;
    }

    /**
     * Compute a deterministic content hash of the CTP route set.
     *
     * Used for change detection: if the hash matches the last sync,
     * no data has changed and the sync can be skipped.
     *
     * @param array $ctpRoutes Raw CTP API response (before transformation)
     * @return string MD5 hash (32 hex chars)
     */
    public static function computeContentHash(array $ctpRoutes): string {
        $normalized = [];
        foreach ($ctpRoutes as $r) {
            $tags = $r['routeSegmentTags'] ?? [];
            if (is_array($tags)) sort($tags);
            $normalized[] = [
                'id' => $r['identifier'] ?? '',
                'rs' => strtoupper(trim($r['routeString'] ?? '')),
                'gr' => strtoupper($r['routeSegmentGroup'] ?? ''),
                'tg' => is_array($tags) ? implode(',', $tags) : '',
                'mr' => (int)($r['maximumAircraftPerHour'] ?? 0),
                'lc' => array_map(
                    fn($l) => $l['identifier'] ?? '',
                    $r['locations'] ?? []
                ),
            ];
        }
        usort($normalized, fn($a, $b) => strcmp($a['id'], $b['id']));
        return md5(json_encode($normalized));
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l load/services/CTPApiClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify transform function logic with a quick CLI test**

Run:
```bash
php -r "
require 'load/services/CTPApiClient.php';
\$mock = [
  ['identifier'=>'NATA','routeString'=>'VESMI 6050N BALIX','routeSegmentGroup'=>'OCA',
   'routeSegmentTags'=>['preferred'],'maximumAircraftPerHour'=>45,
   'locations'=>[['identifier'=>'VESMI','latitude'=>53.5,'longitude'=>-50],
                 ['identifier'=>'BALIX','latitude'=>51,'longitude'=>-8]]]
];
\$out = CTPApiClient::transformRoutes(\$mock);
echo json_encode(\$out[0], JSON_PRETTY_PRINT) . PHP_EOL;
echo 'Hash: ' . CTPApiClient::computeContentHash(\$mock) . PHP_EOL;
echo 'Hash stable: ' . (CTPApiClient::computeContentHash(\$mock) === CTPApiClient::computeContentHash(\$mock) ? 'YES' : 'NO') . PHP_EOL;
"
```

Expected output should show the transformed route with `identifier`, `routestring`, `group`, `origin=VESMI`, `dest=BALIX`, `throughput.peak_rate_hr=45`, and two identical hashes.

- [ ] **Step 4: Commit**

```bash
git add load/services/CTPApiClient.php
git commit -m "feat(ctp): add CTPApiClient with fetch, transform, and content hash"
```

---

### Task 6: Create pull orchestrator script

HTTP-triggered script that polls the CTP API, detects changes via content hash, and calls `CTPPlaybookSync::run()`. Follows the established backfill-script pattern (`?action=status` / `?action=sync`).

**Files:**
- Create: `scripts/ctp/ctp_pull_sync.php`

- [ ] **Step 1: Create the directory and script**

```php
<?php
/**
 * CTP Pull-Based Playbook Sync
 *
 * HTTP-triggered script that polls GET /api/Routes on the CTP API,
 * detects changes via content hashing, and syncs routes into 4 playbooks.
 *
 * Usage:
 *   ?action=status              Show current sync state
 *   ?action=sync&secret=XXX     Trigger a sync cycle
 *   ?action=sync&secret=XXX&force=1  Force sync even if hash unchanged
 *
 * @version 1.0.0
 */

// ── Bootstrap ─────────────────────────────────────────────────────────
// Determine web root: support both direct execution and VFS upload
$webRoot = realpath(__DIR__ . '/../../');
if (!file_exists($webRoot . '/load/config.php')) {
    // Fallback for VFS upload to wwwroot
    $webRoot = '/home/site/wwwroot';
}

require_once $webRoot . '/load/config.php';
require_once $webRoot . '/load/connect.php';
require_once $webRoot . '/load/services/CTPPlaybookSync.php';
require_once $webRoot . '/load/services/CTPApiClient.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$secret = $_GET['secret'] ?? ($_SERVER['HTTP_X_PULL_SECRET'] ?? '');
$force  = (bool)($_GET['force'] ?? false);

// ── Auth ──────────────────────────────────────────────────────────────
if ($action !== 'status' && CTP_PULL_SECRET !== '' && $secret !== CTP_PULL_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing secret']);
    exit;
}

// ── Config guard ─────────────────────────────────────────────────────
if (!CTP_PULL_ENABLED) {
    echo json_encode(['error' => 'CTP pull sync is disabled (CTP_PULL_ENABLED=0)']);
    exit;
}
if (CTP_API_URL === '' || CTP_API_KEY === '') {
    echo json_encode(['error' => 'CTP_API_URL and CTP_API_KEY must be configured']);
    exit;
}
if (CTP_EVENT_CODE === '') {
    echo json_encode(['error' => 'CTP_EVENT_CODE must be configured']);
    exit;
}
if (CTP_SESSION_ID <= 0) {
    echo json_encode(['error' => 'CTP_SESSION_ID must be a positive integer']);
    exit;
}

global $conn_sqli;
$session_id = CTP_SESSION_ID;
$event_code = CTP_EVENT_CODE;

// ── Ensure state row exists ──────────────────────────────────────────
$conn_sqli->query("INSERT IGNORE INTO ctp_pull_sync_state (session_id, event_code) VALUES ({$session_id}, " . $conn_sqli->real_escape_string("'{$event_code}'") . ")");
// Safer approach:
$ins = $conn_sqli->prepare("INSERT IGNORE INTO ctp_pull_sync_state (session_id, event_code) VALUES (?, ?)");
$ins->bind_param('is', $session_id, $event_code);
$ins->execute();
$ins->close();

// ── Load state ───────────────────────────────────────────────────────
function loadState(\mysqli $conn, int $session_id): array {
    $st = $conn->prepare("SELECT * FROM ctp_pull_sync_state WHERE session_id = ?");
    $st->bind_param('i', $session_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: [];
}

$state = loadState($conn_sqli, $session_id);

// ── STATUS action ────────────────────────────────────────────────────
if ($action === 'status') {
    echo json_encode([
        'session_id'         => $session_id,
        'event_code'         => $event_code,
        'status'             => $state['status'] ?? 'idle',
        'content_hash'       => $state['content_hash'] ?? null,
        'synthetic_revision' => (int)($state['synthetic_rev'] ?? 0),
        'route_count'        => (int)($state['route_count'] ?? 0),
        'last_sync_at'       => $state['last_sync_at'] ?? null,
        'last_check_at'      => $state['last_check_at'] ?? null,
        'last_error'         => $state['last_error'] ?? null,
        'config' => [
            'api_url'       => CTP_API_URL,
            'event_code'    => CTP_EVENT_CODE,
            'group_mapping' => CTP_GROUP_MAPPING,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── SYNC action ──────────────────────────────────────────────────────
if ($action !== 'sync') {
    echo json_encode(['error' => 'Unknown action. Use ?action=status or ?action=sync']);
    exit;
}

$start_ms = microtime(true);

// Lock check: prevent concurrent runs
if (($state['status'] ?? '') === 'syncing') {
    $lastCheck = strtotime($state['last_check_at'] ?? '2000-01-01');
    if (time() - $lastCheck < 300) {
        echo json_encode(['error' => 'Sync already in progress', 'last_check_at' => $state['last_check_at']]);
        exit;
    }
    // Stale lock (> 5 min) — reset
}

// Set status to syncing
$now = gmdate('Y-m-d H:i:s');
$upd = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'syncing', last_check_at = ?, last_error = NULL WHERE session_id = ?");
$upd->bind_param('si', $now, $session_id);
$upd->execute();
$upd->close();

try {
    // 1. Fetch routes from CTP API
    $client = new CTPApiClient(CTP_API_URL, CTP_API_KEY);
    $ctpRoutes = $client->fetchRoutes();

    // 2. Compute content hash
    $newHash = CTPApiClient::computeContentHash($ctpRoutes);
    $oldHash = $state['content_hash'] ?? null;

    // 3. Check if content changed
    if (!$force && $newHash === $oldHash) {
        // No changes — update last_check_at and return
        $upd2 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'idle', last_check_at = ? WHERE session_id = ?");
        $upd2->bind_param('si', $now, $session_id);
        $upd2->execute();
        $upd2->close();

        $elapsed = round((microtime(true) - $start_ms) * 1000);
        echo json_encode([
            'action'     => 'sync',
            'changed'    => false,
            'hash'       => $newHash,
            'route_count'=> count($ctpRoutes),
            'elapsed_ms' => $elapsed,
        ]);
        exit;
    }

    // 4. Transform routes
    $routes = CTPApiClient::transformRoutes($ctpRoutes);

    // 5. Increment synthetic revision
    $newRev = ((int)($state['synthetic_rev'] ?? 0)) + 1;

    // 6. Run sync
    $result = CTPPlaybookSync::run(
        $conn_sqli,
        $routes,
        $event_code,
        $session_id,
        $newRev,
        CTP_GROUP_MAPPING,
        null,   // changed_by_cid: system
        true    // skip_revision_check: pull uses content hash for idempotency
    );

    // 7. Update state
    $routeCount = count($ctpRoutes);
    $upd3 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET
        status = 'idle', content_hash = ?, synthetic_rev = ?,
        route_count = ?, last_sync_at = ?, last_check_at = ?, last_error = NULL
        WHERE session_id = ?");
    $upd3->bind_param('siissi', $newHash, $newRev, $routeCount, $now, $now, $session_id);
    $upd3->execute();
    $upd3->close();

    $elapsed = round((microtime(true) - $start_ms) * 1000);
    echo json_encode([
        'action'      => 'sync',
        'changed'     => true,
        'revision'    => $newRev,
        'hash'        => $newHash,
        'route_count' => $routeCount,
        'plays'       => $result['plays'],
        'warnings'    => $result['warnings'] ?? [],
        'elapsed_ms'  => $elapsed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (CTPApiException $e) {
    // CTP API error — record and return
    $errMsg = $e->getMessage();
    $upd4 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'error', last_error = ?, last_check_at = ? WHERE session_id = ?");
    $upd4->bind_param('ssi', $errMsg, $now, $session_id);
    $upd4->execute();
    $upd4->close();

    http_response_code(502);
    echo json_encode(['error' => 'CTP API error', 'detail' => $errMsg]);

} catch (\Throwable $e) {
    // Internal error
    $errMsg = $e->getMessage();
    $upd5 = $conn_sqli->prepare("UPDATE ctp_pull_sync_state SET status = 'error', last_error = ?, last_check_at = ? WHERE session_id = ?");
    $upd5->bind_param('ssi', $errMsg, $now, $session_id);
    $upd5->execute();
    $upd5->close();

    http_response_code(500);
    echo json_encode(['error' => 'Internal error', 'detail' => $errMsg]);
}
```

- [ ] **Step 2: Remove the redundant raw query line**

The script has a raw `$conn_sqli->query(...)` INSERT before the parameterized one. Remove the raw query line (the one starting with `$conn_sqli->query("INSERT IGNORE...`). Keep only the parameterized version below it.

- [ ] **Step 3: Verify syntax**

Run: `php -l scripts/ctp/ctp_pull_sync.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add scripts/ctp/ctp_pull_sync.php
git commit -m "feat(ctp): add pull-based sync orchestrator script"
```

---

### Task 7: Final verification and commit

- [ ] **Step 1: Verify all files have valid syntax**

Run:
```bash
php -l load/config.php && \
php -l load/services/CTPPlaybookSync.php && \
php -l load/services/CTPApiClient.php && \
php -l api/swim/v1/ingest/ctp-routes.php && \
php -l scripts/ctp/ctp_pull_sync.php
```

Expected: All files report `No syntax errors detected`

- [ ] **Step 2: Verify the require chain works**

Run:
```bash
php -r "
define('PERTI_MYSQL_ONLY', true);
require 'load/config.php';
echo 'CTP_PULL_ENABLED: ' . var_export(CTP_PULL_ENABLED, true) . PHP_EOL;
echo 'CTP_GROUP_MAPPING: ' . json_encode(CTP_GROUP_MAPPING) . PHP_EOL;
echo 'CTP_EVENT_CODE: ' . var_export(CTP_EVENT_CODE, true) . PHP_EOL;
"
```

Expected: Shows the default config values (CTP_PULL_ENABLED=false, CTP_GROUP_MAPPING with 4 entries, CTP_EVENT_CODE='')

- [ ] **Step 3: Verify CTPApiClient loads independently**

Run:
```bash
php -r "
require 'load/services/CTPApiClient.php';
echo 'CTPApiClient: OK' . PHP_EOL;
echo 'CTPApiException: OK' . PHP_EOL;
echo 'Transform empty: ' . json_encode(CTPApiClient::transformRoutes([])) . PHP_EOL;
echo 'Hash empty: ' . CTPApiClient::computeContentHash([]) . PHP_EOL;
"
```

Expected: Both classes load, transform of `[]` returns `[]`, hash returns a 32-char hex string

- [ ] **Step 4: Verify file list matches spec**

Confirm these 6 files exist:
- `load/config.php` — modified (CTP constants)
- `database/migrations/playbook/015_ctp_pull_sync_state.sql` — new
- `load/services/CTPPlaybookSync.php` — new
- `load/services/CTPApiClient.php` — new
- `api/swim/v1/ingest/ctp-routes.php` — modified (thin wrapper)
- `scripts/ctp/ctp_pull_sync.php` — new

- [ ] **Step 5: Final commit (if any unstaged changes)**

```bash
git status
# If clean, done. If changes remain:
git add -A
git commit -m "feat(ctp): pull-based playbook sync — final polish"
```
