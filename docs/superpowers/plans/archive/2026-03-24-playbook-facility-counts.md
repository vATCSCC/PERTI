# Per-Play Facility Route Counts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-play facility route counts to the playbook system — internal API, SWIM API, and UI — so users see how many routes traverse each ARTCC/TRACON/sector within a selected play.

**Architecture:** Aggregate route counts from existing `traversed_*` CSV columns (already populated per-route). PHP server-side aggregation for API responses; JS client-side aggregation for real-time UI. No new DB tables or PostGIS queries needed.

**Tech Stack:** PHP 8.2 (server APIs), vanilla JS + jQuery 2.2.4 (UI), Azure SQL `STRING_SPLIT()` (SWIM standalone), CSS3 (bar visualization)

**Spec:** `docs/superpowers/specs/2026-03-24-playbook-facility-counts-design.md`

---

## File Structure

| File | Responsibility | Status |
|------|---------------|--------|
| `api/data/playbook/get.php` | Internal API — add `facility_counts` to single-play response | Modify |
| `api/swim/v1/playbook/plays.php` | SWIM API — add `facility_counts` to `handleGetSingle()` | Modify |
| `api/swim/v1/playbook/facility-counts.php` | SWIM standalone lightweight counts-only endpoint | Create |
| `assets/js/playbook.js` | Sidebar "Traversed Facilities" → counts with pills/bars; pass counts to route analysis panel | Modify |
| `assets/js/route-analysis-panel.js` | Conditional "Play Facility Counts" section; 6th `options` param on `show()` | Modify |
| `playbook.php` | Add `<div id="ra-play-facility-counts">` container in route analysis panel HTML | Modify |
| `assets/css/playbook.css` | Styles for count bars, pill tabs, hover states, count rows | Modify |
| `assets/locales/en-US.json` | i18n keys for new UI strings | Modify |
| `api-docs/openapi.yaml` | Document new endpoint + FacilityCounts schema | Modify |

---

## Task 1: Internal API — `get.php` Facility Counts

**Files:**
- Modify: `api/data/playbook/get.php:69-90`

- [ ] **Step 1: Read current `get.php` to confirm state**

Verify the file matches the expected state: `SELECT *` on line 64, route loop lines 69-78, JSON response on line 86-90.

- [ ] **Step 2: Add facility counts aggregation after route fetch loop**

Insert the following PHP code between the route fetch loop (after line 79, `$stmt->close();`) and the permission flags block (line 82). The aggregation iterates over the already-fetched `$routes` array and builds count objects per facility type, with per-route deduplication and ARTCC normalization.

```php
// Aggregate facility counts from route traversal columns
$facility_counts = ['ARTCC' => [], 'TRACON' => [], 'SECTOR_LOW' => [],
                    'SECTOR_HIGH' => [], 'SECTOR_SUPERHIGH' => []];
$routes_with_traversal = 0;
$fc_column_map = [
    'ARTCC'             => 'traversed_artccs',
    'TRACON'            => 'traversed_tracons',
    'SECTOR_LOW'        => 'traversed_sectors_low',
    'SECTOR_HIGH'       => 'traversed_sectors_high',
    'SECTOR_SUPERHIGH'  => 'traversed_sectors_superhigh',
];

foreach ($routes as $r) {
    $has_data = false;
    foreach ($fc_column_map as $type => $col) {
        $val = trim($r[$col] ?? '');
        if ($val === '') continue;
        $has_data = true;
        // Dedupe per-route: if a route re-enters ZNY, count ZNY once for this route
        $codes = array_unique(array_filter(array_map('trim', explode(',', $val))));
        if ($type === 'ARTCC') {
            $codes = array_map(function($c) {
                return ArtccNormalizer::normalize($c);
            }, $codes);
            $codes = array_unique($codes);
        }
        foreach ($codes as $code) {
            if ($code === '') continue;
            $facility_counts[$type][$code] = ($facility_counts[$type][$code] ?? 0) + 1;
        }
    }
    if ($has_data) $routes_with_traversal++;
}

// Sort each type descending by count, format as arrays
$formatted_counts = [];
foreach ($facility_counts as $type => $counts) {
    arsort($counts);
    $formatted_counts[$type] = [];
    foreach ($counts as $code => $count) {
        $formatted_counts[$type][] = ['code' => $code, 'route_count' => $count];
    }
}
$formatted_counts['total_routes'] = count($routes);
$formatted_counts['routes_with_traversal'] = $routes_with_traversal;
```

**Important**: Note that `$routes` array already has ARTCC normalization applied at line 74-76 (`ArtccNormalizer::toL1Csv`), so the `traversed_artccs` values in `$routes` are already L1. The extra `ArtccNormalizer::normalize()` call in the aggregation handles any edge cases and is harmless if already normalized.

- [ ] **Step 3: Add `facility_counts` to JSON response**

Change the final `json_encode` call (line 86-90) from:

```php
echo json_encode([
    'success' => true,
    'play' => $play,
    'routes' => $routes
]);
```

To:

```php
echo json_encode([
    'success' => true,
    'play' => $play,
    'routes' => $routes,
    'facility_counts' => $formatted_counts
]);
```

- [ ] **Step 4: Verify manually**

Run: `curl -s "https://perti.vatcscc.org/api/data/playbook/get.php?id=1" | python -m json.tool | head -50`
Expected: Response includes `facility_counts` with `total_routes`, `routes_with_traversal`, and typed arrays.

- [ ] **Step 5: Commit**

```bash
git add api/data/playbook/get.php
git commit -m "feat(playbook): add facility_counts to internal get API response"
```

---

## Task 2: SWIM API — `plays.php` Facility Counts in `handleGetSingle()`

**Files:**
- Modify: `api/swim/v1/playbook/plays.php:261-280`

- [ ] **Step 1: Read current `plays.php` to confirm state**

Verify `handleGetSingle()` at line 199: routes fetched with explicit SELECT (lines 246-254), `formatRoute()` creates `traversal` arrays (lines 355-361), response at line 280 (`SwimResponse::success($formatted)`).

- [ ] **Step 2: Add facility counts aggregation after route formatting**

Insert the following PHP code after line 273 (the `include_geometry` block) and before line 275 (the GeoJSON format check). This aggregates from the already-formatted `$routes` array which has `traversal` sub-objects with arrays per type.

```php
// Aggregate facility_counts from formatted route traversal data
$fc = ['artccs' => [], 'tracons' => [], 'sectors_low' => [],
       'sectors_high' => [], 'sectors_superhigh' => []];
$with_traversal = 0;

foreach ($routes as $r) {
    $trav = $r['traversal'] ?? [];
    $has = false;
    foreach ($fc as $key => &$counts) {
        $codes = array_unique($trav[$key] ?? []);
        if (!empty($codes)) $has = true;
        foreach ($codes as $code) {
            if ($code === '') continue;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
    }
    unset($counts);
    if ($has) $with_traversal++;
}

// Sort descending, format as arrays
$facility_counts = ['total_routes' => count($routes), 'routes_with_traversal' => $with_traversal];
foreach ($fc as $key => $counts) {
    arsort($counts);
    $facility_counts[$key] = [];
    foreach ($counts as $code => $count) {
        $facility_counts[$key][] = ['code' => $code, 'route_count' => $count];
    }
}

$formatted['facility_counts'] = $facility_counts;
```

**Key**: This must go AFTER `$formatted['routes'] = $routes;` (line 268) and after the geometry expansion block (line 273), but BEFORE `SwimResponse::success($formatted)` (line 280). The SWIM format uses snake_case keys (`artccs`, `sectors_high`) matching the existing `traversal` object convention.

- [ ] **Step 3: Verify via SWIM API**

Run: `curl -s -H "X-API-Key: YOUR_KEY" "https://perti.vatcscc.org/api/swim/v1/playbook/plays?id=1" | python -m json.tool | grep -A 5 facility_counts`
Expected: `facility_counts` object with `total_routes`, `routes_with_traversal`, typed arrays.

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/playbook/plays.php
git commit -m "feat(swim): add facility_counts to playbook plays single-play response"
```

---

## Task 3: SWIM Standalone Endpoint — `facility-counts.php`

**Files:**
- Create: `api/swim/v1/playbook/facility-counts.php`

- [ ] **Step 1: Read the existing SWIM playbook endpoint for auth pattern reference**

Read `api/swim/v1/playbook/traversal.php` lines 1-35 for the auth bootstrap pattern. The key points:
- `require_once __DIR__ . '/../auth.php'` (defines `SwimAuth`, `SwimResponse`, `swim_init_auth()`, `swim_get_param()`)
- Auth is required: `$auth = swim_init_auth(true);`
- Uses `get_conn_swim()` for Azure SQL access
- Does **NOT** define `PERTI_MYSQL_ONLY` or `PERTI_SWIM_ONLY` (needs Azure SQL)

- [ ] **Step 2: Create the new endpoint file**

Create `api/swim/v1/playbook/facility-counts.php` with the following content:

```php
<?php
/**
 * VATSWIM API v1 — Per-Play Facility Route Counts
 *
 * Returns aggregated facility traversal counts for a single playbook play.
 * Lightweight alternative to fetching full play data with routes.
 *
 * GET /api/swim/v1/playbook/facility-counts?play_id=123
 *
 * @version 1.0.0
 * @since 2026-03-24
 */

require_once __DIR__ . '/../auth.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    SwimResponse::handlePreflight();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// Auth: any tier can read
$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

$conn_swim_api = get_conn_swim();
if (!$conn_swim_api) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$play_id = swim_get_int_param('play_id', 0, 1, 999999999);
if ($play_id <= 0) {
    SwimResponse::error('play_id parameter is required and must be a positive integer', 400, 'MISSING_PARAM');
}

// Verify play exists
$check = sqlsrv_query($conn_swim_api,
    "SELECT play_id, play_name, route_count FROM dbo.swim_playbook_plays WHERE play_id = ?",
    [$play_id]
);
if ($check === false) {
    SwimResponse::error('Database error', 500, 'DB_ERROR');
}
$play_row = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($check);

if (!$play_row) {
    SwimResponse::error('Play not found', 404, 'NOT_FOUND');
}

// Aggregate facility counts using STRING_SPLIT with per-route deduplication
$sql = "
SELECT facility_type, code, COUNT(*) AS route_count
FROM (
    SELECT 'artccs' AS facility_type, r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_artccs, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'tracons', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_tracons, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'sectors_low', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_low, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'sectors_high', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_high, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))

    UNION ALL

    SELECT 'sectors_superhigh', r.route_id, LTRIM(RTRIM(s.value))
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_superhigh, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
    GROUP BY r.route_id, LTRIM(RTRIM(s.value))
) deduped
GROUP BY facility_type, code
ORDER BY facility_type, route_count DESC
";

$params = [$play_id, $play_id, $play_id, $play_id, $play_id];
$stmt = sqlsrv_query($conn_swim_api, $sql, $params);
if ($stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

// Build result structure
$result = [
    'artccs' => [], 'tracons' => [], 'sectors_low' => [],
    'sectors_high' => [], 'sectors_superhigh' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $type = $row['facility_type'];
    if (isset($result[$type])) {
        $result[$type][] = ['code' => $row['code'], 'route_count' => (int)$row['route_count']];
    }
}
sqlsrv_free_stmt($stmt);

// Count routes with any traversal data
$count_sql = "SELECT COUNT(DISTINCT route_id) AS cnt FROM dbo.swim_playbook_routes
              WHERE play_id = ? AND (
                  ISNULL(traversed_artccs,'') != '' OR
                  ISNULL(traversed_tracons,'') != '' OR
                  ISNULL(traversed_sectors_low,'') != '' OR
                  ISNULL(traversed_sectors_high,'') != '' OR
                  ISNULL(traversed_sectors_superhigh,'') != ''
              )";
$count_stmt = sqlsrv_query($conn_swim_api, $count_sql, [$play_id]);
$with_trav = 0;
if ($count_stmt !== false) {
    $cr = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
    $with_trav = (int)($cr['cnt'] ?? 0);
    sqlsrv_free_stmt($count_stmt);
}

$result['total_routes'] = (int)($play_row['route_count'] ?? 0);
$result['routes_with_traversal'] = $with_trav;

SwimResponse::success([
    'play_id' => (int)$play_row['play_id'],
    'play_name' => $play_row['play_name'],
    'facility_counts' => $result
]);
```

**Key design decisions:**
- Uses `GROUP BY r.route_id, code` in subqueries for per-route deduplication (same as `SELECT DISTINCT` but clearer intent)
- 5 UNION ALL subqueries, one per traversal type — each already deduplicated
- Separate count query for `routes_with_traversal` — avoids complex join
- Does NOT use `PERTI_MYSQL_ONLY` (needs Azure SQL via `get_conn_swim()`)
- Does NOT use `PERTI_SWIM_ONLY` — follows `traversal.php` bootstrap pattern (loads full config + connect, then auth)

- [ ] **Step 3: Verify the new endpoint**

Run: `curl -s -H "X-API-Key: YOUR_KEY" "https://perti.vatcscc.org/api/swim/v1/playbook/facility-counts?play_id=1" | python -m json.tool`
Expected: JSON with `play_id`, `play_name`, `facility_counts` object.

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/playbook/facility-counts.php
git commit -m "feat(swim): add standalone facility-counts endpoint for playbook plays"
```

---

## Task 3b: OpenAPI Spec Update

**Files:**
- Modify: `api-docs/openapi.yaml`

- [ ] **Step 1: Read the existing playbook section of openapi.yaml**

Search for existing playbook endpoint definitions to understand the schema patterns in use.

- [ ] **Step 2: Add `FacilityCount` and `FacilityCounts` schemas**

Add the following schemas to the `components/schemas` section:

```yaml
    FacilityCount:
      type: object
      properties:
        code:
          type: string
          description: Facility identifier (e.g., ZNY, N90, ZNY_42)
          example: ZNY
        route_count:
          type: integer
          description: Number of routes traversing this facility
          example: 35
      required:
        - code
        - route_count

    FacilityCounts:
      type: object
      properties:
        total_routes:
          type: integer
          description: Total routes in the play
        routes_with_traversal:
          type: integer
          description: Routes that have traversal data computed
        artccs:
          type: array
          items:
            $ref: '#/components/schemas/FacilityCount'
        tracons:
          type: array
          items:
            $ref: '#/components/schemas/FacilityCount'
        sectors_low:
          type: array
          items:
            $ref: '#/components/schemas/FacilityCount'
        sectors_high:
          type: array
          items:
            $ref: '#/components/schemas/FacilityCount'
        sectors_superhigh:
          type: array
          items:
            $ref: '#/components/schemas/FacilityCount'
```

- [ ] **Step 3: Add `facility_counts` to the single-play response schema**

Find the existing plays single-play response and add `facility_counts` referencing the new schema.

- [ ] **Step 4: Add new `/playbook/facility-counts` endpoint**

```yaml
  /playbook/facility-counts:
    get:
      summary: Per-play facility route counts
      description: Returns aggregated facility traversal counts for a single playbook play.
      security:
        - apiKeyAuth: []
      parameters:
        - name: play_id
          in: query
          required: true
          schema:
            type: integer
          description: Play ID to aggregate
      responses:
        '200':
          description: Facility counts
          content:
            application/json:
              schema:
                type: object
                properties:
                  status:
                    type: string
                    example: success
                  data:
                    type: object
                    properties:
                      play_id:
                        type: integer
                      play_name:
                        type: string
                      facility_counts:
                        $ref: '#/components/schemas/FacilityCounts'
        '400':
          description: Missing or invalid play_id
        '401':
          description: Authentication required
        '404':
          description: Play not found
```

- [ ] **Step 5: Commit**

```bash
git add api-docs/openapi.yaml
git commit -m "docs(openapi): add facility-counts endpoint and FacilityCounts schema"
```

---

## Task 4: i18n Keys

**Files:**
- Modify: `assets/locales/en-US.json:4210-4220` (playbook section)

- [ ] **Step 1: Read the existing playbook i18n keys**

Read `assets/locales/en-US.json` around line 4210 to find the existing `traversedFacilities` and related keys.

- [ ] **Step 2: Add new i18n keys**

Add the following keys to the `playbook` section of `en-US.json`, near the existing `traversedFacilities` key:

```json
"facilityRouteCounts": "Facility Route Counts",
"facilityRouteCountsDesc": "{count} routes",
"routeCountOf": "{count}/{total}",
"routeCountPercent": "{percent}% of play",
"noTraversalData": "No traversal data available",
"missingTraversalNote": "({count} routes missing data)",
"facilityCountsExport": "Export Facility Counts",
"facilityCountsTitle": "Play Facility Counts ({count} routes in {play})",
"tabArtcc": "ARTCC",
"tabTracon": "TRACON",
"tabSectorSH": "Sec SH",
"tabSectorHigh": "Sec High",
"tabSectorLow": "Sec Low",
"countRoutes": "Routes",
"countPercent": "% of Play"
```

- [ ] **Step 3: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(i18n): add facility route counts i18n keys"
```

---

## Task 5: CSS Styles for Facility Counts

**Files:**
- Modify: `assets/css/playbook.css:513` (after existing `.pb-fac-code` styles)

- [ ] **Step 1: Read current playbook.css traversed facility styles**

Read `assets/css/playbook.css` lines 483-515 to see existing `.pb-traversed-*` and `.pb-fac-code` styles.

- [ ] **Step 2: Add new CSS for facility count bars and pill tabs**

Insert the following CSS after line 513 (after the `.pb-fac-code` block, before the `/* ── Detail Placeholder ──` comment):

```css
/* ── Facility Route Counts (sidebar) ─────────────────────────────── */
.pb-fc-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    margin-bottom: 4px;
}
.pb-fc-pill {
    font-size: 0.6rem;
    padding: 1px 5px;
    border-radius: 8px;
    cursor: pointer;
    background: rgba(0,0,0,0.06);
    color: #666;
    border: 1px solid transparent;
    user-select: none;
    transition: background 0.15s, color 0.15s;
}
.pb-fc-pill:hover { background: rgba(0,0,0,0.1); color: #333; }
.pb-fc-pill.active {
    background: #3073b7;
    color: #fff;
    border-color: #2563a0;
}
.pb-fc-row {
    display: flex;
    align-items: center;
    padding: 1px 0;
    cursor: pointer;
    border-radius: 2px;
    transition: background 0.1s;
}
.pb-fc-row:hover { background: rgba(0,0,0,0.04); }
.pb-fc-row.highlighted { background: rgba(48,115,183,0.12); }
.pb-fc-code {
    font-size: 0.65rem;
    font-weight: 600;
    min-width: 70px;
    color: #444;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pb-fc-count {
    font-size: 0.6rem;
    color: #888;
    min-width: 40px;
    text-align: right;
    margin-right: 4px;
}
.pb-fc-bar-wrap {
    flex: 1;
    height: 8px;
    background: rgba(0,0,0,0.04);
    border-radius: 4px;
    overflow: hidden;
}
.pb-fc-bar {
    height: 100%;
    background: #3073b7;
    border-radius: 4px;
    transition: width 0.2s ease;
}
.pb-fc-bar.artcc   { background: #3073b7; }
.pb-fc-bar.tracon  { background: #e07c24; }
.pb-fc-bar.sector  { background: #27ae60; }

/* ── Facility Route Counts (route analysis panel) ────────────────── */
.ra-fc-section {
    margin-bottom: 12px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
}
.ra-fc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    font-size: 0.75rem;
    font-weight: 600;
    color: #444;
}
.ra-fc-pills {
    display: flex;
    gap: 3px;
    padding: 4px 10px;
    border-bottom: 1px solid #eee;
}
.ra-fc-pill {
    font-size: 0.62rem;
    padding: 2px 6px;
    border-radius: 10px;
    cursor: pointer;
    background: rgba(0,0,0,0.05);
    color: #666;
    border: 1px solid transparent;
    user-select: none;
    transition: all 0.15s;
}
.ra-fc-pill:hover { background: rgba(0,0,0,0.1); }
.ra-fc-pill.active { background: #3073b7; color: #fff; }
.ra-fc-body { padding: 0; }
.ra-fc-table {
    width: 100%;
    font-size: 0.68rem;
    border-collapse: collapse;
}
.ra-fc-table th {
    text-align: left;
    font-weight: 600;
    color: #777;
    padding: 3px 8px;
    border-bottom: 1px solid #eee;
    font-size: 0.6rem;
    text-transform: uppercase;
    cursor: pointer;
    user-select: none;
}
.ra-fc-table th:hover { color: #333; }
.ra-fc-table td {
    padding: 2px 8px;
    border-bottom: 1px solid #f5f5f5;
}
.ra-fc-table tr { cursor: pointer; transition: background 0.1s; }
.ra-fc-table tr:hover { background: rgba(0,0,0,0.03); }
.ra-fc-table tr.highlighted { background: rgba(48,115,183,0.1); }
.ra-fc-bar-cell {
    width: 40%;
    padding-right: 12px;
}
.ra-fc-bar-outer {
    height: 10px;
    background: rgba(0,0,0,0.04);
    border-radius: 5px;
    overflow: hidden;
}
.ra-fc-bar-inner {
    height: 100%;
    border-radius: 5px;
    transition: width 0.2s ease;
}
.ra-fc-export-btn {
    font-size: 0.6rem;
    padding: 1px 6px;
    border-radius: 3px;
    background: transparent;
    border: 1px solid #ccc;
    color: #888;
    cursor: pointer;
}
.ra-fc-export-btn:hover { background: #f0f0f0; color: #555; }
```

- [ ] **Step 3: Commit**

```bash
git add assets/css/playbook.css
git commit -m "feat(playbook): add CSS for facility route count bars and pills"
```

---

## Task 6: Playbook UI Sidebar — Enhanced Traversed Facilities

**Files:**
- Modify: `assets/js/playbook.js:1132-1193`

This is the largest single change. The existing "Traversed Facilities" section (lines 1132-1193 of `playbook.js`) builds `Set()` objects for unique facility codes and renders comma-separated lists. We replace this with count objects and a tabbed bar visualization.

- [ ] **Step 1: Read current sidebar traversal code**

Read `assets/js/playbook.js` lines 1130-1195 to verify the exact code to replace.

- [ ] **Step 2: Replace Set-based collection with count objects**

Replace lines 1134-1145 (the `var travArtccs = new Set()` through the `forEach` loop) with count-based aggregation:

```javascript
            var travArtccs = {}, travTracons = {}, travSecLow = {}, travSecHigh = {}, travSecSuper = {};
            var routesWithTrav = 0;
            routes.forEach(function(r) {
                csvSplit(r.origin_airports).forEach(function(a) { if (a) origSet.add(a.toUpperCase()); });
                csvSplit(r.origin_artccs).forEach(function(a) { if (a) origSet.add(a.toUpperCase()); });
                csvSplit(r.dest_airports).forEach(function(a) { if (a) destSet.add(a.toUpperCase()); });
                csvSplit(r.dest_artccs).forEach(function(a) { if (a) destSet.add(a.toUpperCase()); });
                var hasTravData = false;
                // Per-route deduplication: build seen set per type, then increment counts
                var seen = {};
                csvSplit(r.traversed_artccs).forEach(function(a) { if (a) { a = a.toUpperCase(); seen[a] = 1; hasTravData = true; } });
                Object.keys(seen).forEach(function(a) { travArtccs[a] = (travArtccs[a] || 0) + 1; });
                seen = {};
                csvSplit(r.traversed_tracons).forEach(function(a) { if (a) { a = a.toUpperCase(); seen[a] = 1; hasTravData = true; } });
                Object.keys(seen).forEach(function(a) { travTracons[a] = (travTracons[a] || 0) + 1; });
                seen = {};
                csvSplit(r.traversed_sectors_low).forEach(function(a) { if (a) { a = a.toUpperCase(); seen[a] = 1; hasTravData = true; } });
                Object.keys(seen).forEach(function(a) { travSecLow[a] = (travSecLow[a] || 0) + 1; });
                seen = {};
                csvSplit(r.traversed_sectors_high).forEach(function(a) { if (a) { a = a.toUpperCase(); seen[a] = 1; hasTravData = true; } });
                Object.keys(seen).forEach(function(a) { travSecHigh[a] = (travSecHigh[a] || 0) + 1; });
                seen = {};
                csvSplit(r.traversed_sectors_superhigh).forEach(function(a) { if (a) { a = a.toUpperCase(); seen[a] = 1; hasTravData = true; } });
                Object.keys(seen).forEach(function(a) { travSecSuper[a] = (travSecSuper[a] || 0) + 1; });
                if (hasTravData) routesWithTrav++;
            });
```

**Note**: The `origSet` and `destSet` still use `Set()` — only the traversed collections change to count objects. The origin/dest collection code on lines 1137-1140 is preserved.

- [ ] **Step 3: Replace traversed facility display**

Replace lines 1162-1193 (the entire "Traversed facilities summary" block) with the new pill-tabbed count display:

```javascript
            // Traversed facilities summary with route counts (collapsible, pill-tabbed)
            var fcTypes = [
                { key: 'ARTCC', label: t('playbook.tabArtcc'), data: travArtccs, cls: 'artcc' },
                { key: 'TRACON', label: t('playbook.tabTracon'), data: travTracons, cls: 'tracon' },
                { key: 'SECTOR_SUPERHIGH', label: t('playbook.tabSectorSH'), data: travSecSuper, cls: 'sector' },
                { key: 'SECTOR_HIGH', label: t('playbook.tabSectorHigh'), data: travSecHigh, cls: 'sector' },
                { key: 'SECTOR_LOW', label: t('playbook.tabSectorLow'), data: travSecLow, cls: 'sector' }
            ];
            var hasTrav = fcTypes.some(function(ft) { return Object.keys(ft.data).length > 0; });
            if (hasTrav) {
                var totalRt = routes.length;
                html += '<div class="pb-play-traversed">';
                html += '<div class="pb-traversed-header" id="pb_traversed_toggle"><i class="fas fa-chevron-right"></i> <strong>' + t('playbook.facilityRouteCounts') + '</strong>';
                html += ' <span class="text-muted small">(' + t('playbook.facilityRouteCountsDesc', { count: totalRt }) + ')</span></div>';
                html += '<div class="pb-traversed-body" id="pb_traversed_body" style="display:none;">';
                // Pill tabs
                html += '<div class="pb-fc-pills" id="pb_fc_pills">';
                var firstActive = true;
                fcTypes.forEach(function(ft) {
                    var cnt = Object.keys(ft.data).length;
                    if (cnt === 0) return;
                    html += '<span class="pb-fc-pill' + (firstActive ? ' active' : '') + '" data-fc-type="' + ft.key + '">' + escHtml(ft.label) + ' (' + cnt + ')</span>';
                    firstActive = false;
                });
                html += '</div>';
                // Count rows container
                html += '<div id="pb_fc_rows"></div>';
                if (routesWithTrav < totalRt) {
                    html += '<div class="text-muted small mt-1">' + t('playbook.missingTraversalNote', { count: totalRt - routesWithTrav }) + '</div>';
                }
                html += '</div></div>';
            }
```

- [ ] **Step 4: Store facility counts for route analysis panel access**

After the `renderInfoOverlay` HTML is built, store the computed counts so `showRouteAnalysis` can pass them. Add to the play data in module scope. Find the place where `lastViewedPlay` or similar state is stored, and add:

```javascript
// Store facility counts for route analysis panel
window._pbFacilityCounts = {
    ARTCC: travArtccs, TRACON: travTracons,
    SECTOR_LOW: travSecLow, SECTOR_HIGH: travSecHigh, SECTOR_SUPERHIGH: travSecSuper
};
window._pbFacilityTotalRoutes = routes.length;
window._pbFacilityPlayName = play.play_name || play.display_name || '';
```

- [ ] **Step 5: Add pill tab switching and row rendering logic**

After the existing event delegation block for `#pb_traversed_toggle` (search for `pb_traversed_toggle` in the click handler section), add the pill tab switching and row render function:

```javascript
        // Facility count pill tabs
        $(document).on('click', '.pb-fc-pill', function() {
            $('.pb-fc-pill').removeClass('active');
            $(this).addClass('active');
            renderFcRows($(this).data('fc-type'));
        });

        // Facility count row click → highlight on map
        $(document).on('click', '.pb-fc-row', function() {
            var code = $(this).data('fc-code');
            var type = $(this).data('fc-type');
            if (!code || !type) return;
            // Toggle highlight
            var wasActive = $(this).hasClass('highlighted');
            $('.pb-fc-row').removeClass('highlighted');
            if (!wasActive) {
                $(this).addClass('highlighted');
                highlightFacilityOnMap(type, code);
            } else {
                clearFacilityHighlight();
            }
        });
```

Add the `renderFcRows` helper function:

```javascript
    function renderFcRows(typeKey) {
        var container = document.getElementById('pb_fc_rows');
        if (!container) return;
        var counts = (window._pbFacilityCounts || {})[typeKey] || {};
        var totalRt = window._pbFacilityTotalRoutes || 1;
        // Sort descending by count
        var sorted = Object.keys(counts).sort(function(a, b) { return counts[b] - counts[a]; });
        var maxCount = sorted.length > 0 ? counts[sorted[0]] : 1;
        var barCls = 'artcc';
        if (typeKey === 'TRACON') barCls = 'tracon';
        else if (typeKey.indexOf('SECTOR') === 0) barCls = 'sector';
        var html = '';
        sorted.forEach(function(code) {
            var cnt = counts[code];
            var pct = Math.round(cnt / maxCount * 100);
            html += '<div class="pb-fc-row" data-fc-code="' + escHtml(code) + '" data-fc-type="' + escHtml(typeKey) + '" title="' + escHtml(code) + ': ' + cnt + '/' + totalRt + ' routes">';
            html += '<span class="pb-fc-code">' + escHtml(code) + '</span>';
            html += '<span class="pb-fc-count">' + cnt + '/' + totalRt + '</span>';
            html += '<div class="pb-fc-bar-wrap"><div class="pb-fc-bar ' + barCls + '" style="width:' + pct + '%"></div></div>';
            html += '</div>';
        });
        if (sorted.length === 0) {
            html = '<div class="text-muted small" style="padding:2px 0;">' + t('playbook.noTraversalData') + '</div>';
        }
        container.innerHTML = html;
    }
```

- [ ] **Step 6: Add map highlight helpers**

Add the `highlightFacilityOnMap` and `clearFacilityHighlight` helper functions:

```javascript
    function highlightFacilityOnMap(type, code) {
        if (typeof MapLibreRoute === 'undefined') return;
        var map = MapLibreRoute.getMap ? MapLibreRoute.getMap() : null;
        if (!map) return;
        // Clear all first
        clearFacilityHighlight();
        if (type === 'ARTCC') {
            if (map.getLayer('artcc-play-traversed')) {
                map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE', code]);
            }
        } else if (type === 'TRACON') {
            if (map.getLayer('tracon-search-include')) {
                map.setFilter('tracon-search-include', ['in', 'sector', code]);
            }
        } else if (type === 'SECTOR_HIGH') {
            if (map.getLayer('high-sector-search-include')) {
                map.setFilter('high-sector-search-include', ['in', 'label', code]);
            }
        } else if (type === 'SECTOR_LOW') {
            if (map.getLayer('low-sector-search-include')) {
                map.setFilter('low-sector-search-include', ['in', 'label', code]);
            }
        } else if (type === 'SECTOR_SUPERHIGH') {
            if (map.getLayer('superhigh-sector-search-include')) {
                map.setFilter('superhigh-sector-search-include', ['in', 'label', code]);
            }
        }
        // Fit map bounds to the facility boundary
        var sourceId = null, filterProp = null;
        if (type === 'ARTCC') { sourceId = 'artcc-boundaries'; filterProp = 'ICAOCODE'; }
        else if (type === 'TRACON') { sourceId = 'tracon-boundaries'; filterProp = 'sector'; }
        else if (type === 'SECTOR_HIGH') { sourceId = 'high-splits'; filterProp = 'label'; }
        else if (type === 'SECTOR_LOW') { sourceId = 'low-splits'; filterProp = 'label'; }
        else if (type === 'SECTOR_SUPERHIGH') { sourceId = 'superhigh-splits'; filterProp = 'label'; }
        if (sourceId && typeof turf !== 'undefined') {
            var source = map.getSource(sourceId);
            if (source && source._data) {
                var features = (source._data.features || []).filter(function(f) {
                    return f.properties && f.properties[filterProp] === code;
                });
                if (features.length > 0) {
                    var bbox = turf.bbox(turf.featureCollection(features));
                    map.fitBounds([[bbox[0], bbox[1]], [bbox[2], bbox[3]]], { padding: 60, duration: 800 });
                }
            }
        }
    }

    function clearFacilityHighlight() {
        if (typeof MapLibreRoute === 'undefined') return;
        var map = MapLibreRoute.getMap ? MapLibreRoute.getMap() : null;
        if (!map) return;
        var empty = ['in', 'ICAOCODE', ''];
        if (map.getLayer('artcc-play-traversed')) map.setFilter('artcc-play-traversed', empty);
        var emptyLabel = ['in', 'label', ''];
        var emptySector = ['in', 'sector', ''];
        if (map.getLayer('tracon-search-include')) map.setFilter('tracon-search-include', emptySector);
        if (map.getLayer('high-sector-search-include')) map.setFilter('high-sector-search-include', emptyLabel);
        if (map.getLayer('low-sector-search-include')) map.setFilter('low-sector-search-include', emptyLabel);
        if (map.getLayer('superhigh-sector-search-include')) map.setFilter('superhigh-sector-search-include', emptyLabel);
    }
```

**Important**: These must NOT conflict with the anonymous ARTCC highlight function at lines 529-558 of `playbook.js` which handles the overall play ARTCC highlighting via `map.setFilter('artcc-play-traversed', ...)`. The facility count highlight is a temporary "click to focus" action that overrides the traversed layer while active. When the traversed body is collapsed or a new play is selected, the play-level traversed highlight will naturally re-apply.

- [ ] **Step 7: Wire up initial render on traversed body expand**

Find the existing `pb_traversed_toggle` click handler (search for `pb_traversed_toggle` in the event delegation). After the body toggle logic, add a call to render the first active tab's rows:

```javascript
// After expanding the traversed body, render the first active pill's rows
if ($('#pb_traversed_body').is(':visible')) {
    var activeType = $('.pb-fc-pill.active').data('fc-type');
    if (activeType) renderFcRows(activeType);
}
```

- [ ] **Step 8: Commit**

```bash
git add assets/js/playbook.js
git commit -m "feat(playbook): replace traversed facilities with per-facility route counts UI"
```

---

## Task 7: Playbook UI — Pass Facility Counts to Route Analysis Panel

**Files:**
- Modify: `assets/js/playbook.js:3874`

- [ ] **Step 1: Read the `showRouteAnalysis` bridge function**

Read `assets/js/playbook.js` lines 3860-3880 to confirm the current `RouteAnalysisPanel.show()` call.

- [ ] **Step 2: Pass facility counts as 6th argument**

Change line 3874 from:

```javascript
                RouteAnalysisPanel.show(resp, routeStr, origin, dest, mapRouteId);
```

To:

```javascript
                var fcOpts = window._pbFacilityCounts ? {
                    facilityCounts: window._pbFacilityCounts,
                    totalRoutes: window._pbFacilityTotalRoutes || 0,
                    playName: window._pbFacilityPlayName || ''
                } : undefined;
                RouteAnalysisPanel.show(resp, routeStr, origin, dest, mapRouteId, fcOpts);
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/playbook.js
git commit -m "feat(playbook): pass facility counts to route analysis panel"
```

---

## Task 8: Route Analysis Panel — Conditional Facility Counts Section

**Files:**
- Modify: `assets/js/route-analysis-panel.js:237-245`
- Modify: `playbook.php:268-269`

- [ ] **Step 1: Read route-analysis-panel.js `show()` function**

Read `assets/js/route-analysis-panel.js` lines 235-270 to see the current 5-parameter `show()` function.

- [ ] **Step 2: Add 6th `options` parameter to `show()`**

Change line 237 from:

```javascript
    function show(data, routeStr, origin, dest, routeId) {
```

To:

```javascript
    function show(data, routeStr, origin, dest, routeId, options) {
```

Then after line 262 (after the `body.style.display = 'block'` block), add the facility counts render:

```javascript
        // Render play facility counts section if provided
        var fcContainer = document.getElementById('ra-play-facility-counts');
        if (fcContainer) {
            if (options && options.facilityCounts) {
                renderPlayFacilityCounts(fcContainer, options);
            } else {
                fcContainer.innerHTML = '';
                fcContainer.style.display = 'none';
            }
        }
```

- [ ] **Step 3: Add `renderPlayFacilityCounts` function**

Add the following function to `route-analysis-panel.js` (inside the IIFE, near the bottom before the public API `return` statement):

```javascript
    // ── Play Facility Counts (conditional section) ──────────────────
    var fcActiveType = 'ARTCC';
    var fcSortCol = 'count'; // 'count' or 'name'
    var fcSortAsc = false;

    function renderPlayFacilityCounts(container, options) {
        var fc = options.facilityCounts || {};
        var totalRoutes = options.totalRoutes || 0;
        var playName = options.playName || '';

        var types = [
            { key: 'ARTCC', label: PERTII18n.t('playbook.tabArtcc'), cls: 'artcc' },
            { key: 'TRACON', label: PERTII18n.t('playbook.tabTracon'), cls: 'tracon' },
            { key: 'SECTOR_SUPERHIGH', label: PERTII18n.t('playbook.tabSectorSH'), cls: 'sector' },
            { key: 'SECTOR_HIGH', label: PERTII18n.t('playbook.tabSectorHigh'), cls: 'sector' },
            { key: 'SECTOR_LOW', label: PERTII18n.t('playbook.tabSectorLow'), cls: 'sector' }
        ];

        var hasData = types.some(function(t) { return fc[t.key] && Object.keys(fc[t.key]).length > 0; });
        if (!hasData) {
            container.innerHTML = '';
            container.style.display = 'none';
            return;
        }

        container.style.display = 'block';
        var html = '<div class="ra-fc-section">';
        // Header
        html += '<div class="ra-fc-header">';
        html += '<span>' + PERTII18n.t('playbook.facilityCountsTitle', { count: totalRoutes, play: playName }) + '</span>';
        html += '<button class="ra-fc-export-btn" id="ra-fc-export"><i class="fas fa-clipboard"></i></button>';
        html += '</div>';
        // Pills
        html += '<div class="ra-fc-pills">';
        types.forEach(function(t) {
            var cnt = fc[t.key] ? Object.keys(fc[t.key]).length : 0;
            if (cnt === 0) return;
            html += '<span class="ra-fc-pill' + (t.key === fcActiveType ? ' active' : '') + '" data-ra-fc-type="' + t.key + '">' + t.label + ' (' + cnt + ')</span>';
        });
        html += '</div>';
        // Table
        html += '<div class="ra-fc-body"><table class="ra-fc-table"><thead><tr>';
        html += '<th data-ra-fc-sort="name">' + PERTII18n.t('routeAnalysis.col.facility') + '</th>';
        html += '<th data-ra-fc-sort="count" class="text-right">' + PERTII18n.t('playbook.countRoutes') + '</th>';
        html += '<th class="text-right">' + PERTII18n.t('playbook.countPercent') + '</th>';
        html += '<th class="ra-fc-bar-cell"></th>';
        html += '</tr></thead><tbody id="ra-fc-tbody"></tbody></table></div>';
        html += '</div>';
        container.innerHTML = html;

        // Render initial data
        renderFcTableRows(fc, totalRoutes);

        // Event: pill tabs
        container.querySelectorAll('.ra-fc-pill').forEach(function(pill) {
            pill.addEventListener('click', function() {
                container.querySelectorAll('.ra-fc-pill').forEach(function(p) { p.classList.remove('active'); });
                pill.classList.add('active');
                fcActiveType = pill.getAttribute('data-ra-fc-type');
                renderFcTableRows(fc, totalRoutes);
            });
        });

        // Event: sort headers
        container.querySelectorAll('th[data-ra-fc-sort]').forEach(function(th) {
            th.addEventListener('click', function() {
                var col = th.getAttribute('data-ra-fc-sort');
                if (fcSortCol === col) { fcSortAsc = !fcSortAsc; }
                else { fcSortCol = col; fcSortAsc = col === 'name'; }
                renderFcTableRows(fc, totalRoutes);
            });
        });

        // Event: row click → map highlight
        container.addEventListener('click', function(e) {
            var row = e.target.closest('tr[data-ra-fc-code]');
            if (!row) return;
            var code = row.getAttribute('data-ra-fc-code');
            var type = row.getAttribute('data-ra-fc-type');
            var wasActive = row.classList.contains('highlighted');
            container.querySelectorAll('tr.highlighted').forEach(function(r) { r.classList.remove('highlighted'); });
            if (!wasActive) {
                row.classList.add('highlighted');
                highlightSingleFacility(type, code);
            } else {
                clearHighlight();
            }
        });

        // Event: export
        var exportBtn = container.querySelector('#ra-fc-export');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                exportFacilityCounts(fc, totalRoutes, playName);
            });
        }
    }

    function renderFcTableRows(fc, totalRoutes) {
        var tbody = document.getElementById('ra-fc-tbody');
        if (!tbody) return;
        var counts = fc[fcActiveType] || {};
        var keys = Object.keys(counts);
        var maxCount = 0;
        keys.forEach(function(k) { if (counts[k] > maxCount) maxCount = counts[k]; });

        // Sort
        keys.sort(function(a, b) {
            if (fcSortCol === 'name') return fcSortAsc ? a.localeCompare(b) : b.localeCompare(a);
            return fcSortAsc ? counts[a] - counts[b] : counts[b] - counts[a];
        });

        var barCls = 'artcc';
        if (fcActiveType === 'TRACON') barCls = 'tracon';
        else if (fcActiveType.indexOf('SECTOR') === 0) barCls = 'sector';

        var html = '';
        keys.forEach(function(code) {
            var cnt = counts[code];
            var pct = totalRoutes > 0 ? (cnt / totalRoutes * 100).toFixed(1) : '0.0';
            var barW = maxCount > 0 ? Math.round(cnt / maxCount * 100) : 0;
            html += '<tr data-ra-fc-code="' + code + '" data-ra-fc-type="' + fcActiveType + '">';
            html += '<td>' + code + '</td>';
            html += '<td class="text-right">' + cnt + '</td>';
            html += '<td class="text-right">' + pct + '%</td>';
            html += '<td class="ra-fc-bar-cell"><div class="ra-fc-bar-outer"><div class="ra-fc-bar-inner ' + barCls + '" style="width:' + barW + '%"></div></div></td>';
            html += '</tr>';
        });

        if (keys.length === 0) {
            html = '<tr><td colspan="4" class="text-muted text-center" style="padding:8px;">' + PERTII18n.t('playbook.noTraversalData') + '</td></tr>';
        }
        tbody.innerHTML = html;
    }

    function exportFacilityCounts(fc, totalRoutes, playName) {
        var types = ['ARTCC', 'TRACON', 'SECTOR_SUPERHIGH', 'SECTOR_HIGH', 'SECTOR_LOW'];
        var lines = ['Play: ' + playName, 'Total Routes: ' + totalRoutes, ''];
        types.forEach(function(type) {
            var counts = fc[type] || {};
            var keys = Object.keys(counts);
            if (keys.length === 0) return;
            keys.sort(function(a, b) { return counts[b] - counts[a]; });
            lines.push(type + ':');
            keys.forEach(function(code) {
                var pct = totalRoutes > 0 ? (counts[code] / totalRoutes * 100).toFixed(1) : '0.0';
                lines.push('  ' + code + '\t' + counts[code] + '\t' + pct + '%');
            });
            lines.push('');
        });
        var text = lines.join('\n');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        }
    }
```

- [ ] **Step 3b: Add `highlightSingleFacility` function**

This is a **new** function — it does NOT exist in the codebase. The existing `updateFacilityHighlights()` (line 802) works on ALL traversal data at once; we need a function that highlights a single facility code and fits the map bounds. Add this near the `clearHighlight()` function (after line 856):

```javascript
    function highlightSingleFacility(type, code) {
        var map = getMap();
        if (!map) return;
        // Clear existing highlights first
        clearHighlight();
        // Set filter to show only the clicked facility
        if (type === 'ARTCC') {
            if (map.getLayer('artcc-play-traversed')) {
                map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE', code]);
            }
        } else if (type === 'TRACON') {
            if (map.getLayer('tracon-search-include')) {
                map.setFilter('tracon-search-include', ['in', 'sector', code]);
            }
        } else if (type === 'SECTOR_HIGH') {
            if (map.getLayer('high-sector-search-include')) {
                map.setFilter('high-sector-search-include', ['in', 'label', code]);
            }
        } else if (type === 'SECTOR_LOW') {
            if (map.getLayer('low-sector-search-include')) {
                map.setFilter('low-sector-search-include', ['in', 'label', code]);
            }
        } else if (type === 'SECTOR_SUPERHIGH') {
            if (map.getLayer('superhigh-sector-search-include')) {
                map.setFilter('superhigh-sector-search-include', ['in', 'label', code]);
            }
        }
        // Fit map bounds to the facility boundary (reuse pattern from zoomToFacility, line 587-602)
        var sourceId = null, filterProp = null;
        if (type === 'ARTCC') { sourceId = 'artcc-boundaries'; filterProp = 'ICAOCODE'; }
        else if (type === 'TRACON') { sourceId = 'tracon-boundaries'; filterProp = 'sector'; }
        else if (type === 'SECTOR_HIGH') { sourceId = 'high-splits'; filterProp = 'label'; }
        else if (type === 'SECTOR_LOW') { sourceId = 'low-splits'; filterProp = 'label'; }
        else if (type === 'SECTOR_SUPERHIGH') { sourceId = 'superhigh-splits'; filterProp = 'label'; }
        if (sourceId && typeof turf !== 'undefined') {
            var source = map.getSource(sourceId);
            if (source && source._data) {
                var features = (source._data.features || []).filter(function (f) {
                    return f.properties && f.properties[filterProp] === code;
                });
                if (features.length > 0) {
                    var bbox = turf.bbox(turf.featureCollection(features));
                    map.fitBounds([[bbox[0], bbox[1]], [bbox[2], bbox[3]]], {
                        padding: 60, duration: 800
                    });
                }
            }
        }
    }
```

**Note**: This follows the same pattern as the existing `zoomToFacility()` function at line 587-602 of `route-analysis-panel.js`, which uses `turf.bbox()` + `map.fitBounds()` to zoom to a facility boundary. The `getMap()` helper is already available in the module scope.

**Key points:**
- The function uses `PERTII18n.t()` for all user-facing strings (i18n compliance).
- `highlightSingleFacility()` is a **new** function created above (Step 3b). `clearHighlight()` is an existing function in `route-analysis-panel.js` (line 858) that resets all map layer filters.
- The `renderPlayFacilityCounts` function is self-contained and only renders when `options.facilityCounts` is provided.
- Existing callers from `route.php` pass only 5 args, so `options` is `undefined` and the section is hidden.

- [ ] **Step 4: Add HTML container in `playbook.php`**

In `playbook.php`, insert a new `<div>` just before the route analysis summary (line 268, before `<div class="ra-summary" id="ra-summary">`):

```html
            <div id="ra-play-facility-counts"></div>
```

This goes after line 267 (`<div class="ra-picker-matches" id="ra-picker-matches"></div></div>`) and before line 268 (`<div class="ra-summary" id="ra-summary"></div>`).

- [ ] **Step 5: Commit**

```bash
git add assets/js/route-analysis-panel.js playbook.php
git commit -m "feat(playbook): add facility counts section to route analysis panel"
```

---

## Task 9: Verify & Test All Changes

- [ ] **Step 1: Test internal API**

```bash
curl -s "https://perti.vatcscc.org/api/data/playbook/get.php?id=1" | python -m json.tool | grep -A 20 facility_counts
```

Expected: `facility_counts` with typed arrays, `total_routes`, `routes_with_traversal`.

- [ ] **Step 2: Test SWIM plays endpoint**

```bash
curl -s -H "X-API-Key: YOUR_KEY" "https://perti.vatcscc.org/api/swim/v1/playbook/plays?id=1" | python -m json.tool | grep -A 20 facility_counts
```

Expected: Same structure with snake_case keys (`artccs`, `sectors_high`).

- [ ] **Step 3: Test SWIM standalone endpoint**

```bash
curl -s -H "X-API-Key: YOUR_KEY" "https://perti.vatcscc.org/api/swim/v1/playbook/facility-counts?play_id=1" | python -m json.tool
```

Expected: `play_id`, `play_name`, `facility_counts` object.

- [ ] **Step 4: Test SWIM error cases**

```bash
# Missing play_id
curl -s -H "X-API-Key: YOUR_KEY" "https://perti.vatcscc.org/api/swim/v1/playbook/facility-counts"
# Non-existent play
curl -s -H "X-API-Key: YOUR_KEY" "https://perti.vatcscc.org/api/swim/v1/playbook/facility-counts?play_id=999999"
# No auth
curl -s "https://perti.vatcscc.org/api/swim/v1/playbook/facility-counts?play_id=1"
```

Expected: 400/404/401 respectively.

- [ ] **Step 5: Visual test on playbook.php**

Navigate to `https://perti.vatcscc.org/playbook.php`, select a play with traversal data. Verify:
1. "Facility Route Counts" header shows (not old "Traversed Facilities")
2. Pill tabs appear (ARTCC, TRACON, etc.) — only tabs with data
3. Clicking a pill switches the count rows
4. Bars are proportional to max count
5. Count format shows `N/total`
6. Clicking a row highlights the boundary on the map

- [ ] **Step 6: Visual test on route analysis panel**

Click "Analyze" on any route within the selected play. Verify:
1. "Play Facility Counts" section appears above "Facility Traversal" table
2. Pill tabs and table render correctly
3. Sorting by column header works
4. Click row → map highlight
5. Export button copies to clipboard

- [ ] **Step 7: Commit final state**

If any fixes were needed, commit them:

```bash
git add -A
git commit -m "fix(playbook): address testing feedback for facility counts"
```

---

## Task 10: Final PR

- [ ] **Step 1: Create branch and push**

```bash
git checkout -b feature/playbook-facility-counts
git push -u origin feature/playbook-facility-counts
```

- [ ] **Step 2: Create pull request**

```bash
gh pr create --title "feat(playbook): per-play facility route counts" --body "$(cat <<'EOF'
## Summary
- Add `facility_counts` to internal playbook get API (`get.php`)
- Add `facility_counts` to SWIM plays single-play response (`plays.php`)
- Add standalone SWIM `facility-counts.php` endpoint
- Replace sidebar "Traversed Facilities" with per-facility route counts with pill tabs and bars
- Add conditional "Play Facility Counts" section to route analysis panel
- i18n keys, CSS, map highlight support

## Test plan
- [ ] Internal API returns `facility_counts` with correct counts per type
- [ ] SWIM plays endpoint includes `facility_counts` in single-play response
- [ ] SWIM standalone endpoint returns counts for valid play_id
- [ ] SWIM standalone returns 400/404/401 for bad/missing/no-auth requests
- [ ] Sidebar shows pill tabs and count bars, switches between types
- [ ] Click facility row highlights boundary on map
- [ ] Route analysis panel shows counts section when triggered from play
- [ ] Route analysis panel does NOT show counts when used from route.php
- [ ] Export button copies counts to clipboard
- [ ] Works on plays with 0 traversal data (shows "no data" message)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
