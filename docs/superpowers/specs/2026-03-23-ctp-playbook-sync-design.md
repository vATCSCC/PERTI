# CTP Route Planner → Playbook Sync

**Date**: 2026-03-23
**Status**: Draft

## Problem

The CTP (Cross the Pond) Route Planner at `https://planning.ctp.vatsim.net/routeplanner/routes/` manages route definitions for CTP events. These routes need to be automatically synced into PERTI's Playbook system as 4 private playbooks shared to org `CTP`, with full changelog tracking and PERTI-native route processing (facility traversal, geometry, etc.).

## External System: CTP Route Planner

**Repo**: `vatsimnetwork/ctp-route-planner` (Django app)

**Route model** (their `Route` table):
- `identifier` (PK, string) — route name (e.g., `NATA`, `EGLL-KJFK-1`)
- `group` (string) — segment group: `OCA` (oceanic), `AMAS` (North American), `EMEA` (European)
- `routestring` (text) — the actual route string
- `facilities` (text) — CTP-provided facility list (space-separated)
- `tags` (text) — CTP-provided tags (space-separated)

**Save flow**: After every save/delete on the route planner, `notify_routes_changed()` fires a Discord webhook with a full CSV dump of all routes. Our integration follows the same pattern — they add a second webhook call to our endpoint alongside the Discord one.

**Revision system**: `RouteRevisionSet` snapshots all routes after each save, numbered sequentially.

**Auth context**: `request.user_cid` (VATSIM CID) and `request.user_roles` are available at save time but not stored on the route or revision models.

## Design

### Integration Pattern: Full-State Sync

The CTP Route Planner sends the **complete set of routes** to our endpoint after every save. We diff against current playbook state to produce changelog entries for adds, updates, and deletes. This is:

- **Idempotent**: re-sending the same revision is a no-op
- **Simple for the caller**: just POST everything, no need to track deltas
- **Consistent with their Discord pattern**: fire-and-forget full state on save

### Endpoint

```
POST /api/swim/v1/ingest/ctp-routes.php
```

**Auth**: SWIM API key, System tier, `ctp` field write authority (same auth pattern as `api/swim/v1/ingest/ctp.php`).

**Connection bootstrap**: The SWIM auth module (`api/swim/v1/auth.php`) defines `PERTI_SWIM_ONLY`, which disables MySQL connections. Since this endpoint writes to MySQL playbook tables, the endpoint must load `config.php` and `connect.php` **before** including `auth.php`, so that MySQL connections (`$conn_pdo`, `$conn_sqli`) are initialized. Then include `auth.php` for SWIM API key validation only (skipping its `connect.php` include via `PERTI_LOADED` guard). This is the same approach used by endpoints that need both MySQL and SWIM auth.

**Max payload**: 1000 routes per request. Payloads exceeding this limit return `400`.

### Request Payload

```json
{
  "session_id": 1,
  "revision": 42,
  "changed_by_cid": 1745968,
  "group_mapping": {
    "OCA":  "ocean",
    "AMAS": "na",
    "EMEA": "emea"
  },
  "routes": [
    {
      "identifier": "T220",
      "group": "OCA",
      "routestring": "VESMI 6050N 6040N 6030N 6020N 6015N BALIX",
      "facilities": "CZQO EGGX"
    },
    {
      "identifier": "KBOS_DOVEY_1",
      "group": "AMAS",
      "routestring": "BRUWN7 BRUWN DOVEY",
      "facilities": "ZBW ZNY ZWY",
      "tags": "BOS_BRUWN"
    }
  ]
}
```

**Fields**:

| Field | Required | Description |
|-------|----------|-------------|
| `session_id` | Yes | CTP session ID. Used to construct play names and link to `ctp_sessions` in VATSIM_TMI (if exists). Does not require a matching `ctp_sessions` row — plays are self-contained. |
| `revision` | Yes | CTP Route Planner revision number. Stored on plays for idempotency — skips processing if `revision <= last_processed_revision`. |
| `changed_by_cid` | No | VATSIM CID of the person who made the change. If omitted, changelog uses system attribution ("CTP Route Planner"). Name resolved by querying `users` table; falls back to `"CTP User {cid}"` if not found. |
| `group_mapping` | Yes | Maps CTP group values to playbook scope. Valid scope values: `ocean`, `na`, `emea`. Groups not present in the mapping are included in the FULL playbook only (not rejected). |
| `routes` | Yes | Complete set of current routes. Empty array = delete all routes from all plays. Max 1000 routes. |

**Route object fields**:

| Field | Required | Description |
|-------|----------|-------------|
| `identifier` | Yes | Unique route name within the CTP planner (e.g., `NATA`). |
| `group` | Yes | Segment group (e.g., `OCA`, `AMAS`, `EMEA`). Freeform — unknown values are accepted and routed to FULL playbook only. |
| `routestring` | Yes | The route string — shared with PERTI's `route_string` column. |
| `facilities` | No | CTP-provided facility list (stored as-is in `external_facilities`). |
| `tags` | No | CTP-provided tags (stored as-is in `external_tags`). |

### 4 Auto-Created Playbooks

On first sync for a `session_id`, 4 plays are auto-created. The `ctp_session_id` column already exists on `playbook_plays` (added in migration 012).

| Play Name | `ctp_scope` | `visibility` | `org_code` | `source` | Routes Included |
|-----------|-------------|-------------|------------|----------|-----------------|
| `CTPE26-{session_id}-FULL` | (null) | `private_org` | `CTP` | `CTP` | All routes |
| `CTPE26-{session_id}-NA` | `NA` | `private_org` | `CTP` | `CTP` | Routes with group mapped to `na` |
| `CTPE26-{session_id}-EMEA` | `EU` | `private_org` | `CTP` | `CTP` | Routes with group mapped to `emea` |
| `CTPE26-{session_id}-OCEANIC` | `OCEANIC` | `private_org` | `CTP` | `CTP` | Routes with group mapped to `ocean` |

**Note**: The EMEA play uses `ctp_scope = 'EU'` (matching the existing ENUM values `NA`, `OCEANIC`, `EU` from migration 012) despite being named `EMEA` in the play name. This is intentional — `ctp_scope` aligns with the existing three-perspective model (`seg_na_route`, `seg_oceanic_route`, `seg_eu_route` in `ctp_flight_control`), while the play name uses the CTP team's preferred terminology.

**Play metadata**:
- `ctp_session_id` = the session_id from the payload
- `category` = `CTP`
- `status` = `active`
- `source` = `CTP` (requires ENUM extension — see migration below)
- `created_by` = `changed_by_cid` or `0` (system)
- `description` = auto-generated (e.g., "CTPE26 Session 1 — Full Route Set")

**Play name uniqueness**: Play names include `session_id`, so `CTPE26-1-FULL` and `CTPE26-2-FULL` are distinct. The unique index `idx_name_source` on `(play_name_norm, source)` ensures no collisions within the `CTP` source.

If plays already exist for the session_id, they are reused (matched by `play_name`).

**Play creation changelog**: When plays are auto-created, a `play_created` changelog entry is recorded per play.

### Schema Changes

**Migration**: `database/migrations/playbook/014_ctp_external_fields.sql`

```sql
-- 1. Extend source ENUM to include CTP
ALTER TABLE playbook_plays
  MODIFY COLUMN source ENUM('FAA','DCC','ECFMP','CANOC','CADENA','FAA_HISTORICAL','CTP')
  NOT NULL DEFAULT 'DCC';

-- 2. Add external revision tracking to plays
ALTER TABLE playbook_plays ADD COLUMN external_revision BIGINT NULL;

-- 3. Add external metadata columns to routes
ALTER TABLE playbook_routes ADD COLUMN external_id VARCHAR(100) NULL;
ALTER TABLE playbook_routes ADD COLUMN external_source VARCHAR(50) NULL;
ALTER TABLE playbook_routes ADD COLUMN external_group VARCHAR(100) NULL;
ALTER TABLE playbook_routes ADD COLUMN external_facilities TEXT NULL;
ALTER TABLE playbook_routes ADD COLUMN external_tags TEXT NULL;

-- 4. Relax origin/dest NOT NULL constraint (CTP oceanic routes have no airports)
ALTER TABLE playbook_routes MODIFY COLUMN origin VARCHAR(200) NULL DEFAULT '';
ALTER TABLE playbook_routes MODIFY COLUMN dest VARCHAR(200) NULL DEFAULT '';

-- 5. Unique index for sync lookups (prevents duplicate external_ids within a play)
CREATE UNIQUE INDEX IX_playbook_routes_external
  ON playbook_routes (play_id, external_source, external_id);
```

**Why relax origin/dest**: CTP oceanic routes like `SUNOT 50N050W ... MALOT` have waypoint-only endpoints, not airports. The existing `NOT NULL` constraint would require empty strings, which is semantically inconsistent. Making them `NULL DEFAULT ''` is backward-compatible with existing data.

**Why UNIQUE index on external_id**: Prevents duplicate route identifiers within a play from malformed payloads. The sync algorithm matches routes by `external_id`, so uniqueness is a correctness requirement.

### Sync Algorithm

The algorithm is structured in three phases to minimize redundant work:

1. **Diff phase** — compute all diffs across all 4 playbooks (pure MySQL, no PostGIS)
2. **Traversal phase** — call PostGIS once per unique `route_string` that needs processing
3. **Write phase** — apply cached results to all playbooks, batch-write changelog entries

Each playbook is written in its own MySQL transaction. If one play fails, previously synced plays remain committed and can be skipped on re-send (via per-play revision tracking).

#### Phase 1: Diff (all plays)

For each of the 4 plays (full, na, emea, ocean):

1. **Filter** incoming routes by group mapping. For FULL: all routes. For scoped plays: only routes whose group maps to that scope.

2. **Idempotency check**: If `play.external_revision >= incoming revision`, skip this play entirely. Re-sends return `200` with zero counts, not `409`.

3. **Load** current routes: `SELECT * FROM playbook_routes WHERE play_id = ? AND external_source = 'CTP'`, indexed by `external_id`.

4. **Diff** into three buckets:
   - Incoming `external_id` not in current → **ADD**
   - Incoming `external_id` in current, any field changed → **UPDATE** (sub-classified below)
   - Current `external_id` not in incoming → **DELETE**
   - All fields match → **SKIP**

5. **Sub-classify updates**:
   - `route_string` changed → **ROUTE_CHANGED** (needs PostGIS recomputation)
   - Only `external_facilities`, `external_tags`, or `external_group` changed → **METADATA_ONLY** (skip PostGIS, update external fields only)

Collect all unique `route_string` values from ADDs and ROUTE_CHANGED updates across all 4 plays into a deduplication set.

#### Phase 2: Traversal (deduplicated PostGIS)

For each unique `route_string` in the deduplication set (N calls, not 2N+):

1. Extract `origin`/`dest` via `_extractRouteEndpoint()` on the first/last tokens. For airport-starting routes (e.g., `KJFK ...`), this extracts correctly. For oceanic waypoint-only routes (e.g., `VESMI ... BALIX`), origin/dest are set to the waypoint name — acceptable as meaningful endpoint labels.

2. Call `computeTraversedFacilities($route_string, ...)` — same function used by `api/mgt/playbook/route.php`. This runs PostGIS `expand_route_with_artccs()` to compute: `origin_artccs`, `dest_artccs`, `origin_tracons`, `dest_tracons`, route geometry, distance, waypoint list.

3. Cache the result keyed by `route_string`.

**PostGIS context note**: For oceanic routes, there are no bookend airports to prepend/append. The LINESTRING starts/ends at the first/last waypoint. This is acceptable — the route geometry accurately represents the segment.

**Deduplication impact**: Each route appears in FULL + its scoped play = 2 playbooks. With N incoming routes, the naive approach would make ~2N PostGIS calls. With deduplication, it's at most N calls. For metadata-only updates it's 0 calls.

#### Phase 3: Write (per play, batched)

For each play that has changes:

1. **Begin transaction.**

2. **Batch-insert new routes**: Multi-row `INSERT INTO playbook_routes (play_id, route_string, external_id, external_source, external_group, external_facilities, external_tags, origin, dest, origin_artccs, dest_artccs, ...) VALUES (...), (...), (...)` using cached traversal results.

3. **Update changed routes**:
   - ROUTE_CHANGED: Update `route_string`, `external_*` fields, and all PERTI-computed fields from cached traversal. Prepared statement executed in a loop.
   - METADATA_ONLY: Update only `external_facilities`, `external_tags`, `external_group`. No traversal fields touched.

4. **Delete removed routes**: `DELETE FROM playbook_routes WHERE route_id IN (...)`.

5. **Batch-insert changelog entries**: Multi-row `INSERT INTO playbook_changelog (...) VALUES (...), (...), (...)` for all changes in this play (see Changelog Entries below).

6. **Update play**: Set `external_revision = incoming revision`, `updated_at = NOW()`, `updated_by = changed_by_cid`.

7. **Commit transaction.**

### Changelog Entries

Each route change produces one or more `playbook_changelog` rows:

**Route added**:
```
action = 'route_added'
route_id = (new route_id)
field_name = NULL
old_value = NULL
new_value = route_string
changed_by = changed_by_cid (or NULL)
changed_by_name = (resolved from users table) or 'CTP Route Planner'
session_context = '{"source": "ctp-route-planner", "revision": 42, "ctp_session_id": 1}'
```

**Route updated** (one entry per changed field):
```
action = 'route_updated'
route_id = (existing route_id)
field_name = 'route_string' | 'external_facilities' | 'external_tags' | 'external_group'
old_value = (previous value)
new_value = (new value)
changed_by = changed_by_cid (or NULL)
changed_by_name = (resolved from users table) or 'CTP Route Planner'
session_context = '{"source": "ctp-route-planner", "revision": 42, "ctp_session_id": 1}'
```

**Route deleted**:
```
action = 'route_deleted'
route_id = (deleted route_id)
field_name = NULL
old_value = route_string
new_value = NULL
changed_by = changed_by_cid (or NULL)
changed_by_name = (resolved from users table) or 'CTP Route Planner'
session_context = '{"source": "ctp-route-planner", "revision": 42, "ctp_session_id": 1}'
```

**Name resolution**: If `changed_by_cid` is provided, query `SELECT name_first, name_last FROM users WHERE cid = ?`. If found, use `"{name_first} {name_last}"`. If not found, use `"CTP User {cid}"`. If `changed_by_cid` is omitted, use `"CTP Route Planner"`.

### Response

```json
{
  "success": true,
  "revision": 42,
  "plays": {
    "full":    {"play_id": 40, "added": 12, "updated": 3, "deleted": 1, "unchanged": 30},
    "na":      {"play_id": 41, "added": 5,  "updated": 1, "deleted": 0, "unchanged": 10},
    "emea":    {"play_id": 42, "added": 4,  "updated": 1, "deleted": 0, "unchanged": 12},
    "ocean":   {"play_id": 43, "added": 3,  "updated": 1, "deleted": 1, "unchanged": 8}
  }
}
```

**Idempotent re-sends**: If all plays have `external_revision >= incoming revision`, the response is `200` with all counts at zero — not `409`. This allows transparent retries without error handling on the caller side.

**Error responses**:
- `401` — Invalid or missing API key
- `403` — API key lacks `ctp` field authority
- `400` — Validation errors (missing required fields, invalid `group_mapping` scope values, payload exceeds 1000 routes)
- `500` — Internal error (PostGIS unavailable, DB error)

### Route Processing Pipeline

CTP routes go through the **exact same processing** as user-created playbook routes:

1. `computeTraversedFacilities()` in `api/mgt/playbook/playbook_helpers.php`
2. PostGIS `expand_route_with_artccs()` — resolves waypoints, airways, coordinates
3. Spatial joins against `tracon_boundaries`, `sector_boundaries`
4. Route geometry (GeoJSON LINESTRING) stored for map rendering
5. Distance computation (nautical miles)
6. Origin/destination endpoint extraction via `_extractRouteEndpoint()`

The only difference is that CTP routes store additional `external_*` metadata alongside the PERTI-computed fields.

### File Changes

| File | Change |
|------|--------|
| `api/swim/v1/ingest/ctp-routes.php` | **New** — main endpoint |
| `database/migrations/playbook/014_ctp_external_fields.sql` | **New** — schema: ENUM extension, external columns, origin/dest relaxation, UNIQUE index |
| `load/swim_config.php` | Already has CTP data source config — no changes needed |

### Maintenance Notes

- `api/mgt/playbook/save.php` has a PHP-side source whitelist (`['DCC', 'ECFMP', 'CANOC', 'CADENA']`) that does not include `CTP`. This is fine — the new endpoint bypasses `save.php` entirely. If CTP plays need to be editable through the normal playbook UI in the future, that whitelist must be updated.
- Editing CTP-synced routes through the normal playbook UI is not blocked — but manual edits to `route_string` will be overwritten on the next sync. The `external_*` fields serve as the sync anchor.

### Dependencies

- PostGIS must be available for route processing (currently B2s tier, kept during hibernation)
- SWIM API key infrastructure already exists
- `playbook_helpers.php` `computeTraversedFacilities()` already exists
- `playbook_changelog` table and changelog patterns already exist
- `playbook_plays` ACL and visibility infrastructure already exists
- `playbook_plays.ctp_session_id` column already exists (migration 012)

### Integration Guide (for CTP team)

The CTP Route Planner needs to add one webhook call in `routeplanner/discord.py` alongside `notify_routes_changed()`, or in `routes_save()` / `route_delete()`:

```python
def _send_to_perti(action: str, user_cid: int = None) -> None:
    perti_url = getattr(settings, "PERTI_WEBHOOK_URL", "")
    perti_key = getattr(settings, "PERTI_API_KEY", "")
    if not perti_url:
        return
    try:
        routes = Route.objects.all().order_by("group", "identifier")
        latest_rev = RouteRevisionSet.objects.order_by('-number').first()
        payload = {
            "session_id": getattr(settings, "CTP_SESSION_ID", 1),
            "revision": latest_rev.number if latest_rev else 0,
            "changed_by_cid": user_cid,
            "group_mapping": getattr(settings, "CTP_GROUP_MAPPING", {
                "OCA": "ocean",
                "AMAS": "na",
                "EMEA": "emea"
            }),
            "routes": [
                {
                    "identifier": r.identifier,
                    "group": r.group,
                    "routestring": r.routestring,
                    "facilities": r.facilities,
                    "tags": r.tags,
                }
                for r in routes
            ]
        }
        requests.post(
            perti_url,
            json=payload,
            headers={"X-API-Key": perti_key},
            timeout=30,
        )
    except Exception as exc:
        logger.warning("PERTI webhook failed: %s", exc)
```

Settings to add on the CTP Route Planner:
- `PERTI_WEBHOOK_URL` = `https://perti.vatcscc.org/api/swim/v1/ingest/ctp-routes.php`
- `PERTI_API_KEY` = (SWIM API key with System tier + ctp authority)
- `CTP_SESSION_ID` = (set per event deployment)
- `CTP_GROUP_MAPPING` = (optional override, defaults to `{"OCA": "ocean", "AMAS": "na", "EMEA": "emea"}`)

**Retry recommendation**: The webhook is fire-and-forget. If PERTI is temporarily unavailable, the next save on the route planner will re-send the full state, so missed webhooks self-heal. For critical events, the CTP team can manually re-trigger by saving with no changes (the revision increments, and the full state is re-sent).
