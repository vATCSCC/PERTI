# Per-Play Facility Route Counts

**Date**: 2026-03-24
**Status**: Draft
**Scope**: Internal API + SWIM API + Playbook UI

## Problem

When viewing a playbook play (e.g., "ORD EAST GATE 1" with 45 routes), the existing "Traversed Facilities" section shows **which** facilities are traversed (unique codes) but not **how many routes** traverse each one. Users need to see: "ZNY superhigh sector ZNY_SH_1: 12 routes, N90 TRACON: 10 routes, ZDC ARTCC: 35 routes" — per-facility route counts within a single play.

## Solution

Enhance per-play facility traversal to include route counts:
1. **Internal API** (`api/data/playbook/get.php`) — add `facility_counts` to single-play response
2. **SWIM API** (`api/swim/v1/playbook/plays.php`) — add `facility_counts` to single-play response
3. **SWIM standalone** (`api/swim/v1/playbook/facility-counts.php`) — lightweight counts-only endpoint
4. **Playbook UI** — enhance existing "Traversed Facilities" sidebar section + add counts section to route analysis panel

## Data Source

All 5 traversed columns are already populated per-route in MySQL and mirrored to SWIM_API:

| Column | MySQL Type | SWIM Type | Format | Example |
|--------|-----------|-----------|--------|---------|
| `traversed_artccs` | VARCHAR(500) | NVARCHAR(MAX) | CSV | `ZNY,ZDC,ZBW,CZUL` |
| `traversed_tracons` | VARCHAR(500) | NVARCHAR(MAX) | CSV | `N90,PCT,C90` |
| `traversed_sectors_low` | VARCHAR(500) | NVARCHAR(MAX) | CSV | `ZNY_42,ZDC_15` |
| `traversed_sectors_high` | VARCHAR(500) | NVARCHAR(MAX) | CSV | `ZNY_HIGH_31,ZDC_HIGH_22` |
| `traversed_sectors_superhigh` | VARCHAR(500) | NVARCHAR(MAX) | CSV | `ZLA_SH_1` |

**No new PostGIS queries or backfill needed** — aggregation is purely from existing stored data.

## API Design

### 1. Internal API: `GET api/data/playbook/get.php?id=123`

**Change**: Add `facility_counts` object to the existing single-play response.

The `get.php` endpoint already loads all routes with their `traversed_*` columns. After fetching routes, aggregate counts in PHP before returning.

**Updated response** (new field highlighted):

```json
{
  "success": true,
  "play": { "play_id": 123, "play_name": "ORD_EAST_1", ... },
  "routes": [ ... ],
  "facility_counts": {
    "total_routes": 45,
    "routes_with_traversal": 42,
    "ARTCC": [
      {"code": "ZNY", "route_count": 35},
      {"code": "ZDC", "route_count": 28},
      {"code": "ZBW", "route_count": 12}
    ],
    "TRACON": [
      {"code": "N90", "route_count": 22},
      {"code": "PCT", "route_count": 15}
    ],
    "SECTOR_LOW": [
      {"code": "ZNY_42", "route_count": 8}
    ],
    "SECTOR_HIGH": [
      {"code": "ZDC_HIGH_31", "route_count": 18}
    ],
    "SECTOR_SUPERHIGH": [
      {"code": "ZNY_SH_1", "route_count": 12}
    ]
  }
}
```

Each array sorted by `route_count` descending.

**Implementation** (in `get.php`, after route fetch loop):

```php
// Aggregate facility counts from route traversal columns
$facility_counts = ['ARTCC' => [], 'TRACON' => [], 'SECTOR_LOW' => [],
                    'SECTOR_HIGH' => [], 'SECTOR_SUPERHIGH' => []];
$routes_with_traversal = 0;
$column_map = [
    'ARTCC'             => 'traversed_artccs',
    'TRACON'            => 'traversed_tracons',
    'SECTOR_LOW'        => 'traversed_sectors_low',
    'SECTOR_HIGH'       => 'traversed_sectors_high',
    'SECTOR_SUPERHIGH'  => 'traversed_sectors_superhigh',
];

foreach ($routes_raw as $r) {
    $has_data = false;
    foreach ($column_map as $type => $col) {
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
$formatted_counts['total_routes'] = count($routes_raw);
$formatted_counts['routes_with_traversal'] = $routes_with_traversal;
```

### 2. SWIM API: `GET api/swim/v1/playbook/plays?id=123`

**Change**: Add `facility_counts` to the single-play response in `handleGetSingle()`.

The existing `handleGetSingle()` (line 199 of `plays.php`) already fetches all routes and returns them with per-route `traversal` objects. After the route fetch loop, aggregate counts from the formatted routes' `traversal` arrays.

**Updated SWIM response** (new field):

```json
{
  "status": "success",
  "data": {
    "play_id": 123,
    "play_name": "ORD_EAST_1",
    "routes": [ ... ],
    "facility_counts": {
      "total_routes": 45,
      "routes_with_traversal": 42,
      "artccs": [
        {"code": "ZNY", "route_count": 35},
        {"code": "ZDC", "route_count": 28}
      ],
      "tracons": [
        {"code": "N90", "route_count": 22}
      ],
      "sectors_low": [
        {"code": "ZNY_42", "route_count": 8}
      ],
      "sectors_high": [
        {"code": "ZDC_HIGH_31", "route_count": 18}
      ],
      "sectors_superhigh": [
        {"code": "ZNY_SH_1", "route_count": 12}
      ]
    }
  }
}
```

Note: SWIM uses snake_case keys (`artccs`, `sectors_high`) matching the existing per-route `traversal` object convention (lines 355-361 of `plays.php`).

**Implementation** (in `handleGetSingle()`, after route formatting, before response):

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
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
    }
    unset($counts);
    if ($has) $with_traversal++;
}

// Sort descending, format
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

### 3. SWIM Standalone: `GET api/swim/v1/playbook/facility-counts?play_id=123`

**New file**: `api/swim/v1/playbook/facility-counts.php`

Lightweight endpoint for API consumers who want just the counts without loading all routes. Uses Azure SQL `STRING_SPLIT()` for server-side aggregation.

**IMPORTANT**: Do NOT define `PERTI_MYSQL_ONLY` — this endpoint queries Azure SQL via `get_conn_swim()`. Follow the standard SWIM auth pattern: include `auth.php`, call `swim_init_auth()`, use `get_conn_swim()`.

**Auth**: API key (any tier)

**Parameters**:

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `play_id` | int | Yes | Play ID to aggregate |

**Response**: Same `facility_counts` structure as the plays endpoint.

**Implementation**: Azure SQL with per-route deduplication:

```sql
SELECT 'artccs' AS facility_type, code, COUNT(*) AS route_count
FROM (
    SELECT DISTINCT r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_artccs, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
) deduped
GROUP BY code

UNION ALL

SELECT 'tracons', code, COUNT(*)
FROM (
    SELECT DISTINCT r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_tracons, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
) deduped
GROUP BY code

UNION ALL

SELECT 'sectors_low', code, COUNT(*)
FROM (
    SELECT DISTINCT r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_low, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
) deduped
GROUP BY code

UNION ALL

SELECT 'sectors_high', code, COUNT(*)
FROM (
    SELECT DISTINCT r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_high, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
) deduped
GROUP BY code

UNION ALL

SELECT 'sectors_superhigh', code, COUNT(*)
FROM (
    SELECT DISTINCT r.route_id, LTRIM(RTRIM(s.value)) AS code
    FROM dbo.swim_playbook_routes r
    CROSS APPLY STRING_SPLIT(r.traversed_sectors_superhigh, ',') s
    WHERE r.play_id = ? AND LTRIM(RTRIM(s.value)) != ''
) deduped
GROUP BY code

ORDER BY facility_type, route_count DESC
```

### 4. OpenAPI Spec Update

Update `api-docs/openapi.yaml`:
- Add `facility_counts` to the single-play response schema under `/playbook/plays?id={id}`
- Add new `/playbook/facility-counts` endpoint definition
- Define `FacilityCount` schema: `{code: string, route_count: integer}`
- Define `FacilityCounts` schema with typed arrays + `total_routes` + `routes_with_traversal`

## UI Design

### Catalog Sidebar: Enhanced "Traversed Facilities" Section

**Location**: Replace the existing "Traversed Facilities" section in `renderInfoOverlay()` (playbook.js lines 1162-1193). Currently shows unique facility codes; enhance to show route counts.

**Current behavior** (lines 1134-1145): Builds `Set()` for each facility type (unique codes only).

**New behavior**: Build count objects instead of Sets:

```javascript
// Before (current):
var travArtccs = new Set();
routes.forEach(function(r) {
    csvSplit(r.traversed_artccs).forEach(function(a) { if (a) travArtccs.add(a.toUpperCase()); });
});

// After (new):
var travArtccs = {};  // code → route count
routes.forEach(function(r) {
    var seen = {};  // dedupe per-route
    csvSplit(r.traversed_artccs).forEach(function(a) {
        if (a) seen[a.toUpperCase()] = 1;
    });
    Object.keys(seen).forEach(function(a) {
        travArtccs[a] = (travArtccs[a] || 0) + 1;
    });
});
```

**Display structure** (replaces current comma-separated list):

```
▶ Facility Route Counts (45 routes)
  ┌──────────────────────────────────────────┐
  │ [ARTCC] [TRACON] [Sec SH] [Sec Hi] [Lo] │  ← pill tabs
  ├──────────────────────────────────────────┤
  │ ZNY     35/45  ██████████████████████     │
  │ ZDC     28/45  ███████████████            │
  │ ZBW     12/45  ████████                   │
  │ ZID      8/45  █████                      │
  │ CZUL     5/45  ███                        │
  └──────────────────────────────────────────┘
```

- Show `route_count/total_routes` format (e.g., "35/45")
- Proportional bar (CSS width% relative to max count in that type)
- Sorted by count descending
- Pill tabs to switch facility type (reuse existing `.pb-fac-code` styling)
- **Click a row** → highlight boundary on map via existing `map.setFilter()` mechanism
- Collapsible (same toggle pattern as current `pb_traversed_toggle`)

### Route Analysis Panel: "Facility Counts" Section

**Location**: New full-width section above the existing "Facility Traversal" table in `route-analysis-panel.js`. Only shown when the analysis was triggered from a playbook play (not a standalone route on route.php).

**Trigger**: When `RouteAnalysisPanel.show()` is called from playbook.js, pass the play's facility counts data. The panel checks for this data and renders the section if present.

**API signature change**: The current `show()` function accepts 5 parameters: `show(data, routeStr, origin, dest, routeId)`. Add a 6th optional `options` parameter:

```javascript
// BEFORE:
function show(data, routeStr, origin, dest, routeId)

// AFTER:
function show(data, routeStr, origin, dest, routeId, options)
// options = { facilityCounts, totalRoutes, playName } or undefined
```

Existing callers (route.php) pass 5 args and are unaffected. Only playbook.js passes the 6th.

**Data flow**:
```
playbook.js: user clicks "Analyze" on a route within a play
  ├── Already has: play routes with traversed_* fields (from get.php)
  ├── Computes: facility_counts (client-side, same as sidebar)
  └── Calls RouteAnalysisPanel.show(data, routeStr, origin, dest, routeId, {
          facilityCounts: { ARTCC: {ZNY: 35, ...}, TRACON: {...}, ... },
          totalRoutes: 45,
          playName: "ORD_EAST_1"
      })
```

**Display structure**:

```
┌─ Play Facility Counts (45 routes in ORD_EAST_1) ──────────── [export ▾] ─┐
│                                                                           │
│  [ARTCC] [TRACON] [Sec Low] [Sec High] [Sec SH]  ← type filter pills    │
│                                                                           │
│  Facility       │ Routes │ % of Play │                                    │
│  ───────────────┼────────┼───────────┤                                    │
│  ZNY            │  35    │   77.8%   │ ████████████████████████████████    │
│  ZDC            │  28    │   62.2%   │ █████████████████████████           │
│  ZBW            │  12    │   26.7%   │ ███████████                         │
│  ...                                                                      │
└───────────────────────────────────────────────────────────────────────────┘
```

- **Sortable**: click column header to sort by name or count
- **Type filter pills**: same pattern as existing facility filter in `renderFacilityFilters()`
- **Bar visualization**: proportional to max count (CSS)
- **Click row** → highlight boundary on map
- **Export**: clipboard / CSV (reuse existing `exportClipboard()` pattern)
- **Conditional render**: only when `facilityCounts` option is provided

## Map Interaction

### Highlight on Click

Reuse existing MapLibre filter mechanism:

```javascript
// ARTCC highlight
map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE', facilityCode]);

// TRACON highlight
map.setFilter('tracon-search-include', ['in', 'sector', facilityCode]);

// Sector highlight (sector layers use 'label' property, not 'sector')
map.setFilter(sectorType + '-sector-search-include', ['in', 'label', facilityCode]);
```

Clicking a facility row in either the sidebar or route analysis panel:
1. Highlights the boundary polygon on the map (existing layers, filter update)
2. Fits map bounds to the boundary extent
3. Shows a brief tooltip: "ZNY: 35 of 45 routes"

Clicking again or clicking a different facility clears the previous highlight.

## File Changes

### New Files
| File | Purpose |
|------|---------|
| `api/swim/v1/playbook/facility-counts.php` | SWIM standalone facility counts endpoint |

### Modified Files
| File | Change |
|------|--------|
| `api/data/playbook/get.php` | Add `facility_counts` aggregation to single-play response |
| `api/swim/v1/playbook/plays.php` | Add `facility_counts` to `handleGetSingle()` response |
| `api-docs/openapi.yaml` | Document new endpoint + updated response schema |
| `assets/js/playbook.js` | Enhance "Traversed Facilities" section → counts with pill tabs, bars, click-to-highlight; pass counts to route analysis panel |
| `assets/js/route-analysis-panel.js` | Add conditional "Play Facility Counts" section |
| `assets/css/playbook.css` | Styles for count bars, pill tabs, hover states |
| `assets/locales/en-US.json` | i18n keys for new UI strings |

### Unchanged Files (verified)
| File | Reason |
|------|--------|
| `api/data/playbook/list.php` | List endpoint unaffected (per-play counts only on get) |
| `api/data/playbook/analysis.php` | Per-route analysis unchanged |
| `api/mgt/playbook/playbook_helpers.php` | Traversal computation unchanged |
| `load/services/GISService.php` | No new PostGIS queries needed |
| `scripts/swim_refdata_sync.php` | Sync pipeline unchanged (traversed columns already synced) |
| `playbook.php` | No new HTML containers needed — existing `#pb_info_content` rendered dynamically by JS |

## Performance

- **Internal API (get.php)**: Routes already fetched; PHP aggregation of ~5-200 routes per play is <1ms overhead.
- **SWIM single-play**: Same — routes already in memory, aggregation is trivial.
- **SWIM standalone endpoint**: Azure SQL `STRING_SPLIT()` + `GROUP BY` on ~5-200 rows per play: <10ms.
- **Client-side**: Counts computed in JS from already-loaded route data. Zero additional network requests for sidebar.
- **Route analysis panel**: Receives pre-computed counts from playbook.js. Zero additional fetch.

## Edge Cases

- **Routes without traversal data**: `routes_with_traversal` < `total_routes`. Show "(N routes missing data)" note if gap > 0.
- **Single-route plays**: Still show counts section (trivially: every facility = 1/1).
- **Duplicate facility in single route**: A route re-entering ZNY lists it twice in CSV. **Deduplicate per-route** before counting (each route contributes at most 1 to each facility's count).
- **ARTCC normalization**: Apply `ArtccNormalizer::normalize()` (internal) / L1 normalization (SWIM) to ensure consistent codes.
- **Long sector codes**: CDM sectors like `EDGGFRK/1` (up to 50 chars). Truncate display with full code on hover/title attribute.
- **Empty traversal for all routes**: Show "No traversal data available" message instead of empty table.
- **Play with 0 routes**: Don't show facility counts section at all.
