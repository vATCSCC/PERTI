# Playbook Facility Route Counts

**Date**: 2026-03-24
**Status**: Draft
**Scope**: Internal API + SWIM API + Playbook UI

## Problem

The playbook has ~42K routes, each with pre-computed `traversed_artccs`, `traversed_tracons`, `traversed_sectors_low/high/superhigh` columns in MySQL (and mirrored to `swim_playbook_routes` in SWIM_API). Currently, these are exposed per-route or per-play, but there is no aggregate view answering "how many routes traverse each facility across the playbook?"

## Solution

Add facility route count aggregation to:
1. Internal API (`api/data/playbook/facility_counts.php`)
2. SWIM API (`api/swim/v1/playbook/facility-counts` + `include=facility_counts` on plays endpoint)
3. Playbook UI (catalog sidebar section + route analysis panel section)

## Data Source

### MySQL (`perti_site.playbook_routes`)

All 5 traversed columns are populated at route save time via `computeTraversedFacilities()` (PostGIS) and backfilled via `scripts/playbook/backfill_geometry.php`:

| Column | Type | Format | Example |
|--------|------|--------|---------|
| `traversed_artccs` | VARCHAR(500) | CSV | `ZNY,ZDC,ZBW,CZUL` |
| `traversed_tracons` | VARCHAR(500) | CSV | `N90,PCT,C90` |
| `traversed_sectors_low` | VARCHAR(500) | CSV | `ZNY_42,ZDC_15` |
| `traversed_sectors_high` | VARCHAR(500) | CSV | `ZNY_HIGH_31,ZDC_HIGH_22` |
| `traversed_sectors_superhigh` | VARCHAR(500) | CSV | `ZLA_SH_1` |

### Azure SQL (`SWIM_API.dbo.swim_playbook_routes`)

Mirror of MySQL columns as `NVARCHAR(MAX)`. Azure SQL has `STRING_SPLIT()` for server-side aggregation.

## API Design

### 1. Internal API: `GET api/data/playbook/facility_counts.php`

**Auth**: Session-based (same as playbook list)

**Parameters** (match `list.php` filter params):

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `scope` | string | `filtered` | `filtered` (apply filters) or `all` (entire playbook) |
| `category` | string | null | Filter by play category |
| `source` | string | null | Filter by play source (FAA/DCC/etc.) |
| `status` | string | `active` | Filter by play status |
| `artcc` | string | null | Filter by ARTCC in `facilities_involved` |
| `search` | string | null | Full-text search on play name/description |
| `hide_legacy` | int | 0 | Hide `_old_` and FAA_HISTORICAL plays |
| `type` | string | null | Filter to specific facility type: `ARTCC`, `TRACON`, `SECTOR_LOW`, `SECTOR_HIGH`, `SECTOR_SUPERHIGH` |

**Response**:
```json
{
  "success": true,
  "scope": "filtered",
  "total_routes": 1847,
  "routes_with_traversal": 1623,
  "facility_counts": {
    "ARTCC": [
      {"code": "ZNY", "route_count": 234},
      {"code": "ZDC", "route_count": 198},
      {"code": "ZBW", "route_count": 156}
    ],
    "TRACON": [
      {"code": "N90", "route_count": 112},
      {"code": "PCT", "route_count": 87}
    ],
    "SECTOR_LOW": [
      {"code": "ZNY_42", "route_count": 23}
    ],
    "SECTOR_HIGH": [
      {"code": "ZDC_HIGH_31", "route_count": 45}
    ],
    "SECTOR_SUPERHIGH": [
      {"code": "ZLA_SH_1", "route_count": 12}
    ]
  }
}
```

Each array is sorted by `route_count` descending.

**Implementation**: PHP-side aggregation. Query all matching routes' traversed_* columns (lightweight — just 5 short text fields per row), split CSV in PHP, accumulate counts in associative arrays, sort descending.

```php
// Pseudocode
$sql = "SELECT r.traversed_artccs, r.traversed_tracons,
               r.traversed_sectors_low, r.traversed_sectors_high,
               r.traversed_sectors_superhigh
        FROM playbook_routes r
        JOIN playbook_plays p ON r.play_id = p.play_id
        WHERE p.status != 'archived' ..." ;
// Apply same filter WHERE clauses as list.php

$counts = ['ARTCC' => [], 'TRACON' => [], ...];
while ($row = fetch) {
    foreach (explode(',', $row['traversed_artccs']) as $code) {
        if ($code !== '') $counts['ARTCC'][$code] = ($counts['ARTCC'][$code] ?? 0) + 1;
    }
    // ... same for other types
}
// Sort each type descending by count
```

### 2. SWIM API: `GET api/swim/v1/playbook/facility-counts`

**Auth**: API key (any tier)

**Parameters**:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `category` | string | null | Filter by play category |
| `source` | string | null | Filter by play source |
| `artcc` | string | null | Filter by ARTCC |
| `type` | string | null | Filter to specific facility type |

**Response**: Same structure as internal API, wrapped in SWIM envelope:
```json
{
  "status": "success",
  "data": {
    "scope": "all",
    "total_routes": 42187,
    "routes_with_traversal": 38450,
    "facility_counts": { ... }
  },
  "meta": {
    "api_version": "1.0",
    "generated_utc": "2026-03-24T15:30:00Z"
  }
}
```

**Implementation**: Azure SQL `STRING_SPLIT()` for efficient server-side aggregation:
```sql
SELECT value AS facility_code, COUNT(*) AS route_count
FROM swim_playbook_routes r
JOIN swim_playbook_plays p ON r.play_id = p.play_id
CROSS APPLY STRING_SPLIT(r.traversed_artccs, ',')
WHERE value != '' AND p.status = 'active'
GROUP BY value
ORDER BY route_count DESC
```

One query per facility type (5 queries total), or UNION ALL for a single round-trip.

### 3. SWIM Plays Endpoint Update: `include=facility_counts`

**Endpoint**: `GET api/swim/v1/playbook/plays?include=facility_counts`

When `facility_counts` is in the `include` param, append to the response:
```json
{
  "data": [ ...plays... ],
  "facility_counts": {
    "ARTCC": [...],
    "TRACON": [...],
    ...
  }
}
```

This aggregates across all plays in the current result set (respects existing filters). Computed from the same routes that produce the play list.

### 4. OpenAPI Spec Update

Add `/playbook/facility-counts` endpoint to `api-docs/openapi.yaml` with:
- Parameter definitions (category, source, artcc, type)
- Response schema (FacilityCountsResponse)
- 200/401/500 responses

Update `/playbook/plays` endpoint to document `include=facility_counts` option.

## UI Design

### Catalog Sidebar: "Facility Route Counts" Section

**Location**: Below existing "Traversed Facilities" section in the info overlay, OR as a new section in the catalog overlay (left sidebar) visible even when no play is selected.

**Decision**: Place in the **catalog overlay** (left sidebar) so it's always visible regardless of play selection. This makes it a playbook-wide tool.

**Structure**:
```
[Facility Route Counts]                    [filtered ▾]
┌─────────────────────────────────────────────────────┐
│ ARTCC │ TRACON │ Sec Low │ Sec High │ Sec SH │      │  ← pill tabs
├─────────────────────────────────────────────────────┤
│ ZNY    234 ████████████████████                      │
│ ZDC    198 ███████████████                           │
│ ZBW    156 ████████████                              │
│ ZID    134 ██████████                                │
│ CZUL    89 ███████                                   │
│ ... (show top 15, expandable)                        │
└─────────────────────────────────────────────────────┘
```

- **Collapsible** (collapsed by default, expand via header click)
- **Pill tabs** to switch between facility types (ARTCC, TRACON, Sector Low/High/SH)
- **Sorted** by route count descending
- **Inline bar** (proportional to max count, CSS width%)
- **Scope toggle**: "filtered" (matches current catalog filters) / "all" (entire playbook)
- **Click a facility row** → highlights boundary on map + sets catalog filter to plays traversing that facility
- **Top 15** shown by default, "Show all (N)" expander for full list

### Route Analysis Panel: "Playbook Summary" Section

**Location**: New full-width section above the existing "Facility Traversal" table. Only visible when in playbook context (not single-route analysis from route.php).

**Structure**:
```
┌─ Playbook Facility Summary ──────────────────── [scope: filtered ▾] [export ▾] ─┐
│                                                                                   │
│  Facility     │ Type        │ Routes │ % of Total │                               │
│  ─────────────┼─────────────┼────────┼────────────┤                               │
│  ZNY          │ ARTCC       │    234 │     12.7%  │ ████████████                  │
│  N90          │ TRACON      │    189 │     10.3%  │ ██████████                    │
│  ZDC          │ ARTCC       │    198 │     10.8%  │ ██████████                    │
│  ...                                                                              │
│                                                                                   │
│  [ARTCC] [TRACON] [Sec Low] [Sec High] [Sec SH]   ← type filter pills           │
└───────────────────────────────────────────────────────────────────────────────────┘
```

- **Sortable columns**: click header to sort by facility, type, count, or percentage
- **Type filter pills**: same as existing facility filter pattern in route-analysis-panel.js
- **Export**: clipboard / CSV (reuse existing export pattern)
- **Click row** → highlight boundary on map + filter catalog
- **Only shown in playbook context** (check `window.PlaybookContext` or similar flag)

## Map Interaction

### Highlight Pattern

Reuse existing MapLibre filter mechanism:
```javascript
// ARTCC highlight
map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE', facilityCode]);

// TRACON highlight
map.setFilter('tracon-search-include', ['in', 'sector', facilityCode]);

// Sector highlight (sector layers use 'label' property, not 'sector')
map.setFilter(sectorType + '-sector-search-include', ['in', 'label', facilityCode]);
```

### Filter-on-Click Pattern

When a facility is clicked in the counts UI:
1. Highlight the boundary on the map (using existing layers)
2. Update the catalog search to include a `traverses:FACILITY_CODE` filter
3. `applyFilters()` re-runs with the new constraint
4. Plays not containing routes through that facility are hidden
5. A filter badge appears: "traverses: ZNY [x]" (removable)

This requires extending the existing `matchesSearch()` and `buildSearchIndex()` functions in playbook.js:
- `buildSearchIndex()` must add route-level traversal codes to the play's `_facilityCodes` set (currently only includes play-level `facilities_involved` and `impacted_area`). Extend it to iterate the play's `agg_traversed_artccs`, `agg_traversed_tracons`, `agg_traversed_sectors_low/high/superhigh` aggregate fields and add those codes to `_facilityCodes`.
- `matchesSearch()` must recognize the `traverses:` prefix and match against these expanded facility codes.

## File Changes

### New Files
| File | Purpose |
|------|---------|
| `api/data/playbook/facility_counts.php` | Internal facility count aggregation endpoint |
| `api/swim/v1/playbook/facility-counts.php` | SWIM facility count endpoint |

### Modified Files
| File | Change |
|------|--------|
| `api/swim/v1/playbook/plays.php` | Add `include=facility_counts` support |
| `api-docs/openapi.yaml` | Document new endpoint + updated plays param |
| `assets/js/playbook.js` | Catalog sidebar facility counts section, filter-on-click, `traverses:` search field |
| `assets/js/route-analysis-panel.js` | Playbook summary section (conditional on playbook context) |
| `playbook.php` | HTML containers for new sections |
| `assets/css/playbook.css` | Styles for facility counts section |
| `assets/locales/en-US.json` | i18n keys for new UI strings |

### Unchanged Files (verified no changes needed)
| File | Reason |
|------|--------|
| `api/data/playbook/list.php` | Filters reused via shared logic, no changes to list endpoint itself |
| `api/data/playbook/analysis.php` | Per-route analysis unchanged |
| `api/mgt/playbook/playbook_helpers.php` | Traversal computation unchanged |
| `load/services/GISService.php` | No new PostGIS queries needed |
| `scripts/swim_refdata_sync.php` | Sync pipeline unchanged (traversed columns already synced) |

## Performance

- **Internal API**: ~42K rows, 5 short text columns each. MySQL fetch + PHP split/count: ~50-100ms.
- **SWIM API**: Azure SQL `STRING_SPLIT()` with `GROUP BY`: ~100-200ms for 56K rows.
- **No caching needed initially** — queries are fast enough. Can add 60s cache later if needed.
- **Client-side**: Single fetch on catalog load + re-fetch on filter change (debounced 300ms).

## Edge Cases

- **Routes without traversal data**: Count as `routes_with_traversal` < `total_routes`. Show "(N routes missing traversal data)" note if > 5% missing.
- **Empty filter results**: Return empty arrays, `total_routes: 0`.
- **Very long sector codes**: Some CDM sectors have codes like `EDGGFRK/1` (up to 50 chars). Display truncated in sidebar, full on hover.
- **Duplicate facility codes in single route**: A route's `traversed_artccs` might list `ZNY,ZDC,ZNY` if the route re-enters ZNY. Count as 1 route for ZNY (dedupe per-route before counting).
- **ARTCC normalization**: Apply `ArtccNormalizer::toL1Csv()` to ensure consistent L1 codes (strip sub-sector suffixes).
