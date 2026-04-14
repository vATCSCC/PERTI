# CTP Pull-Based Playbook Sync

**Date**: 2026-03-26
**Status**: Draft
**Companion to**: `2026-03-23-ctp-playbook-sync-design.md` (push-based spec)

## Problem

The push-based CTP sync spec requires the CTP team to add a webhook call to their Route Planner. This creates a dependency on external code changes. We need a pull-based alternative that polls the CTP API's `GET /api/Routes` endpoint directly, requiring zero code changes on their side — only a CTP API key.

## Architecture

```
CTP API (ASP.NET)          PERTI Pull Script           PERTI Playbook (MySQL + PostGIS)
GET /api/Routes  ───────>  ctp_pull_sync.php  ───────>  CTPPlaybookSync service
(X-API-Key auth)           (HTTP-triggered)              (shared with push endpoint)
                           content-hash diff
                           data transform
```

The pull script fetches the full route set from the CTP API, computes a content hash, and if changed, transforms the data into the internal format and calls the same sync service that the push endpoint (`ctp-routes.php`) uses.

## Design

### Approach: Shared Sync Service (Refactored from Push Endpoint)

Extract the sync algorithm from `ctp-routes.php` into `load/services/CTPPlaybookSync.php`. Both the existing push endpoint and the new pull script call the same service. This eliminates code duplication and ensures identical behavior.

**Extracted functions** (currently in `ctp-routes.php`):
- `_findOrCreatePlay()` → `CTPPlaybookSync::findOrCreatePlay()`
- `_loadCurrentRoutes()` → `CTPPlaybookSync::loadCurrentRoutes()`
- `_diffRoutes()` → `CTPPlaybookSync::diffRoutes()`
- `_traversalKey()` → `CTPPlaybookSync::traversalKey()`
- `_insertRoutes()` → `CTPPlaybookSync::insertRoutes()`
- `_updateRouteChanged()` → `CTPPlaybookSync::updateRouteChanged()`
- `_updateMetadataOnly()` → `CTPPlaybookSync::updateMetadataOnly()`
- `_deleteRoutes()` → `CTPPlaybookSync::deleteRoutes()`
- `_upsertThroughput()` → `CTPPlaybookSync::upsertThroughput()`
- `_writeChangelogs()` → `CTPPlaybookSync::writeChangelogs()`
- `_updatePlayRevision()` → `CTPPlaybookSync::updatePlayRevision()`

**New orchestrator method**:
```php
CTPPlaybookSync::run(
    mysqli  $conn,
    array   $routes,         // Internal format (identifier, group, routestring, ...)
    int     $session_id,
    int     $revision,
    array   $group_mapping,  // ['OCA' => 'ocean', 'AMAS' => 'na', 'EMEA' => 'emea']
    ?string $changed_by_cid = null,
    bool    $skip_revision_check = false  // Pull mode: bypass per-play revision idempotency
): array  // Returns ['revision' => N, 'plays' => [...]]
```

The push endpoint (`ctp-routes.php`) becomes a thin HTTP wrapper: validate request → call `CTPPlaybookSync::run()` → return response.

### CTP API Client

New file: `load/services/CTPApiClient.php`

```php
class CTPApiClient {
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30) { ... }

    /**
     * Fetch all routes from CTP API.
     * GET /api/Routes — returns RouteSegment[] with Locations[].
     * @return array Raw CTP API response (array of RouteSegment objects)
     * @throws CTPApiException on HTTP error, timeout, or invalid JSON
     */
    public function fetchRoutes(): array { ... }

    /**
     * Check API availability.
     * @return bool True if API responds with 200
     */
    public function isAvailable(): bool { ... }
}
```

**Auth**: `X-API-Key` header (same pattern as the CTP Route Planner's `CTPAPIClient`).

**Error handling**: On HTTP 4xx/5xx or timeout, throw `CTPApiException` with status code and message. The pull script catches this and logs the error without crashing.

**Retry**: One automatic retry on timeout (30s default). No retry on auth errors (401/403).

### Data Transformation

The CTP API returns `RouteSegment` objects (ASP.NET camelCase serialization). These must be mapped to the internal route format expected by `CTPPlaybookSync::run()`.

**CTP API response format** (`GET /api/Routes`):
```json
[
  {
    "identifier": "NATA",
    "routeString": "VESMI 6050N 6040N 6030N 6020N 6015N BALIX",
    "routeSegmentGroup": "OCA",
    "routeSegmentTags": ["preferred", "primary"],
    "maximumAircraftPerHour": 45,
    "locations": [
      {"identifier": "VESMI", "latitude": 53.5, "longitude": -50.0, "maximumAircraftPerHour": 18},
      {"identifier": "BALIX", "latitude": 51.0, "longitude": -8.0, "maximumAircraftPerHour": 18}
    ]
  }
]
```

**Field mapping**:

| CTP API field | Internal field | Notes |
|---------------|----------------|-------|
| `identifier` | `identifier` | Direct 1:1 |
| `routeString` | `routestring` | Direct 1:1 |
| `routeSegmentGroup` | `group` | Direct 1:1 (e.g., `OCA`, `AMAS`, `EMEA`) |
| `routeSegmentTags` (array) | `tags` | Joined with space separator |
| `locations[0].identifier` | `origin` | First waypoint as origin label |
| `locations[-1].identifier` | `dest` | Last waypoint as dest label |
| `maximumAircraftPerHour` | `throughput.peak_rate_hr` | Only if > 0 |
| (not available) | `facilities` | Empty string — CTP API doesn't expose `ProvidedFacilityProgression` in GET response |

**Transform function** (in `CTPApiClient.php`):
```php
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

        $locs = $seg['locations'] ?? [];
        if (!empty($locs)) {
            $route['origin'] = $locs[0]['identifier'] ?? '';
            $route['dest']   = end($locs)['identifier'] ?? '';
        }

        $maxRate = (int)($seg['maximumAircraftPerHour'] ?? 0);
        if ($maxRate > 0) {
            $route['throughput'] = ['peak_rate_hr' => $maxRate];
        }

        // Store full location data as metadata for future use
        if (!empty($locs)) {
            $route['_locations'] = $locs;
        }

        $result[] = $route;
    }
    return $result;
}
```

**Notes on unavailable fields**:
- `Color` and `Enabled` exist in the CTP API's database migration but NOT on the `RouteSegment` C# model (submodule mismatch). If they become available later, add: `Enabled=false` → skip route, `Color` → store in `external_tags`.
- `ProvidedFacilityProgression` is not `.Include()`'d in the GET query. If it becomes available, extract Sector identifiers as `facilities`.

### Content-Hash Change Detection

Since the CTP API has no revision endpoint or webhook, we use content hashing to detect changes.

**Hash computation**:
```php
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
```

**Hashing rules**:
- Sort routes by identifier for deterministic order
- Normalize case (uppercase route strings and groups)
- Sort tags alphabetically
- Include Location identifiers (not lat/lon — those don't change)
- Include `maximumAircraftPerHour` (throughput changes should trigger sync)

**Synthetic revision**: Each time the content hash changes, increment a `synthetic_revision` counter stored in the sync state table. This becomes the `$revision` passed to `CTPPlaybookSync::run()`, enabling the existing idempotency checks to work unchanged.

### Sync State Table

**Migration**: `database/migrations/playbook/015_ctp_pull_sync_state.sql`

```sql
CREATE TABLE IF NOT EXISTS ctp_pull_sync_state (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    session_id      INT NOT NULL,
    content_hash    VARCHAR(32) NULL,
    synthetic_rev   INT NOT NULL DEFAULT 0,
    route_count     INT NOT NULL DEFAULT 0,
    last_sync_at    DATETIME NULL,
    last_check_at   DATETIME NULL,
    last_error      TEXT NULL,
    status          ENUM('idle','syncing','error') NOT NULL DEFAULT 'idle',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

| Column | Purpose |
|--------|---------|
| `session_id` | CTP session being tracked |
| `content_hash` | MD5 of last synced route set (32 chars) |
| `synthetic_rev` | Monotonic counter, incremented on each detected change |
| `route_count` | Number of routes in last sync |
| `last_sync_at` | Timestamp of last successful sync (content changed) |
| `last_check_at` | Timestamp of last poll (even if no changes) |
| `last_error` | Error message from last failed attempt |
| `status` | Current state: `idle` (waiting), `syncing` (in progress), `error` (last attempt failed) |

### Pull Orchestrator

**New file**: `scripts/ctp/ctp_pull_sync.php`

HTTP-triggered script following the established backfill pattern:

```
GET  ?action=status     → Show current sync state (JSON)
GET  ?action=sync       → Trigger a sync cycle
GET  ?action=sync&force=1 → Force sync even if content hash unchanged
```

**Sync cycle**:

1. **Lock check**: If `status = 'syncing'` and `last_check_at` was within 5 minutes, skip (prevent concurrent runs). If stale (> 5 min), reset to `idle` (crash recovery).

2. **Set status** to `syncing`, update `last_check_at`.

3. **Fetch routes**: `CTPApiClient::fetchRoutes()`. On error → set status to `error`, store message, return.

4. **Compute content hash**: `CTPApiClient::computeContentHash($ctpRoutes)`.

5. **Compare hash**: If hash matches `content_hash` in state table and `force` is not set → set status to `idle`, return with `"unchanged": true`.

6. **Transform routes**: `CTPApiClient::transformRoutes($ctpRoutes)` → internal format.

7. **Increment revision**: `synthetic_rev + 1`.

8. **Run sync**: `CTPPlaybookSync::run($conn, $routes, $session_id, $revision, $group_mapping)`.

9. **Update state**: Set `content_hash`, `synthetic_rev`, `route_count`, `last_sync_at`, status to `idle`.

10. **Return result**: JSON with sync counts per play.

**Status response**:
```json
{
  "session_id": 1,
  "status": "idle",
  "content_hash": "a1b2c3d4...",
  "synthetic_revision": 7,
  "route_count": 46,
  "last_sync_at": "2026-03-26T14:30:00Z",
  "last_check_at": "2026-03-26T14:35:00Z",
  "last_error": null
}
```

**Sync response**:
```json
{
  "action": "sync",
  "changed": true,
  "revision": 8,
  "route_count": 47,
  "plays": {
    "full":  {"play_id": 40, "added": 1, "updated": 0, "deleted": 0, "unchanged": 46},
    "na":    {"play_id": 41, "added": 1, "updated": 0, "deleted": 0, "unchanged": 15},
    "emea":  {"play_id": 42, "added": 0, "updated": 0, "deleted": 0, "unchanged": 18},
    "ocean": {"play_id": 43, "added": 0, "updated": 0, "deleted": 0, "unchanged": 13}
  },
  "elapsed_ms": 2340
}
```

### Configuration

New constants in `load/config.php` (or Azure App Settings):

```php
// CTP Pull-Based Sync
define('CTP_PULL_ENABLED',     env('CTP_PULL_ENABLED', false));
define('CTP_API_URL',          env('CTP_API_URL', ''));           // e.g., https://ctp-api.vatsim.net
define('CTP_API_KEY',          env('CTP_API_KEY', ''));           // X-API-Key for CTP API
define('CTP_SESSION_ID',       (int)env('CTP_SESSION_ID', 0));   // Current CTP session
define('CTP_GROUP_MAPPING',    json_decode(env('CTP_GROUP_MAPPING', '{"OCA":"ocean","AMAS":"na","EMEA":"emea"}'), true));
```

**Azure App Settings** (set per event):
- `CTP_PULL_ENABLED` = `1`
- `CTP_API_URL` = `https://ctp-api.vatsim.net` (or wherever the CTP API is hosted)
- `CTP_API_KEY` = (obtained from CTP team)
- `CTP_SESSION_ID` = `1` (set per event)
- `CTP_GROUP_MAPPING` = `{"OCA":"ocean","AMAS":"na","EMEA":"emea"}` (default, override per event if needed)

### Scheduling

**Recommended**: Curl loop (proven pattern from backfill scripts):
```bash
# Poll every 2 minutes
while true; do
  curl -s --max-time 60 "https://perti.vatcscc.org/scripts/ctp/ctp_pull_sync.php?action=sync" -o /dev/null
  sleep 120
done
```

**Alternative**: Add to `cron.php` as a 2-minute cron job once the CTP API URL and key are configured.

**Alternative**: Add to `scripts/startup.sh` as a daemon with a sleep loop for fully automated operation on App Service.

### Coexistence with Push Endpoint

Both push and pull can operate simultaneously:

- **Push** (`ctp-routes.php`): CTP Route Planner POSTs full state after saves. Uses CTP-provided `revision` number. Passes `skip_revision_check = false` — relies on per-play `external_revision` for idempotency.
- **Pull** (`ctp_pull_sync.php`): Polls CTP API at intervals. Uses content-hash for idempotency. Passes `skip_revision_check = true` — bypasses per-play revision checks since the content hash already determined that data has changed.

**Why separate idempotency mechanisms**: The push endpoint receives explicit revision numbers from the CTP Route Planner (1, 2, 3...). The pull script has no access to revision numbers — the CTP API's `GET /api/Routes` returns the current state without versioning. If both modes shared the same revision-based idempotency, their revision number spaces would collide: push might set `external_revision = 42` on a play, then pull would try `synthetic_revision = 9`, which would be skipped (9 < 42), effectively disabling pull permanently.

**Resolution**: The `skip_revision_check` flag decouples the two modes. Pull uses content hash as its idempotency gate (checked before calling the sync service). Push uses per-play `external_revision` as its idempotency gate (checked inside the sync service). Both write `external_revision` to the play for record-keeping, but only push relies on it for skip logic.

**Atomicity**: Per-play MySQL transactions ensure that concurrent push and pull writes don't corrupt data. If both run simultaneously with identical route data, the second writer produces identical results (same diffs, same writes).

**Recommended mode**: Use pull-only initially. Enable push later as an optimization for lower latency (push triggers immediate sync on save, pull handles missed webhooks as a safety net).

---

## File Changes

| File | Change |
|------|--------|
| `load/services/CTPPlaybookSync.php` | **New** — extracted sync service class (from ctp-routes.php) |
| `load/services/CTPApiClient.php` | **New** — CTP API HTTP client + data transformer + content hasher |
| `api/swim/v1/ingest/ctp-routes.php` | **Modified** — thin wrapper calling CTPPlaybookSync::run() |
| `scripts/ctp/ctp_pull_sync.php` | **New** — pull orchestrator (HTTP-triggered) |
| `database/migrations/playbook/015_ctp_pull_sync_state.sql` | **New** — sync state table |
| `load/config.php` | **Modified** — add CTP_PULL_* constants |

## Dependencies

- **CTP API key**: Must be obtained from the CTP team. This is the only external dependency.
- **CTP API availability**: The API must be running and accessible from Azure App Service.
- **PostGIS (B2s tier)**: Required for route traversal computation. Already available.
- **Migration 014**: Must be applied first (CTP external fields on playbook_routes/plays). Migration files exist but need to be confirmed as deployed.
- **`playbook_helpers.php`**: `computeTraversedFacilities()` already exists and works.

## Migration Deployment Order

1. Apply migration 014 (`014_ctp_external_fields.sql`) if not already deployed
2. Apply migration 015 (`015_ctp_pull_sync_state.sql`)
3. Deploy code changes (service extraction + pull script)
4. Configure Azure App Settings (CTP_API_URL, CTP_API_KEY, CTP_SESSION_ID)
5. Set `CTP_PULL_ENABLED=1`
6. Start curl loop or add to cron

## Testing Strategy

1. **Unit test the transformer**: Verify CTP API response format maps correctly to internal format. Test with real CTP API data if available, otherwise mock responses based on the DataStructures.cs model.
2. **Content hash stability**: Verify identical route sets produce identical hashes regardless of JSON field ordering.
3. **Sync with empty CTP API**: Verify graceful handling when CTP API returns `[]` (delete all routes from plays).
4. **Sync with unavailable CTP API**: Verify error state is recorded and no data is modified.
5. **Force sync**: Verify `?action=sync&force=1` runs even when content hash matches.
6. **Push/pull coexistence**: Verify both endpoints can operate on the same plays without conflicts.

## Future Enhancements

- **Daemon mode**: Convert from HTTP-triggered to a continuous daemon in `scripts/startup.sh` if polling needs to be always-on.
- **Multi-session support**: Track multiple `session_id` values simultaneously (e.g., for concurrent CTP events). Currently single-session.
- **Webhook fallback**: If the CTP team adds webhook support, the pull script can switch to push-primary with pull as fallback.
- **Color/Enabled fields**: When the CTP API model adds these properties, extend the transformer to handle `enabled: false` (skip route) and `color` (store in external_tags or a new column).
- **Facility progression**: When `ProvidedFacilityProgression` is included in GET responses, extract Sector identifiers as `facilities`.
