# Route Symbology: Filter Icons, Fan Connectors & Hitbox Improvements

## Summary

Add facility filter support to the route visualization system across `route.php` and `playbook.php`. This includes new endpoint icons (TSD jet airplane silhouettes for airports, prohibition overlays for filtered facilities), a new filter fan connector layer, improved route click hitboxes, and playbook-to-route interoperability for filter data.

## Scope

### In Scope
1. **9 endpoint icons** (6 base + 3 filter variants) for airports, TRACONs, ARTCCs
2. **Filter token parser** in route-maplibre.js for `(-TOKEN1 -TOKEN2)` syntax
3. **Filter fan connector layer** (`routes-filter-fan`) with dense dotted dash pattern
4. **Hitbox improvement** via `queryRenderedFeatures` bbox tolerance
5. **Playbook filter injection** in `plotOnMap()` and `openInRoutePage()`
6. **Help dialog & i18n** updates for filter syntax documentation
7. **Symbology panel** updates for filter fan controls

### Out of Scope
- Filter animations (kept static for performance)
- Changes to PB directive format (`PB.PLAY.ORIG.DEST`)
- Changes to playbook DB schema (filters already stored)
- Changes to playbook route editor UI (filter inputs already exist)
- Changes to API endpoints (already store/retrieve filters)

---

## 1. Icon System

### Current State
6 SVG SDF icons in `route-maplibre.js` (lines 2028-2063):
- `airport-origin` / `airport-dest` (triangles)
- `tracon-origin` / `tracon-dest` (diamonds)
- `artcc-origin` / `artcc-dest` (squares)

### New State
9 SVG SDF icons (6 base + 3 filter):

| Icon Name | Shape | Purpose |
|-----------|-------|---------|
| `airport-origin` | Upward-pointing airplane silhouette (filled) | Origin airport |
| `airport-dest` | Downward-pointing airplane silhouette (filled) | Destination airport |
| `tracon-origin` | Filled diamond | Origin TRACON (unchanged) |
| `tracon-dest` | Outline diamond | Destination TRACON (unchanged) |
| `artcc-origin` | Filled square | Origin ARTCC (unchanged) |
| `artcc-dest` | Outline square | Destination ARTCC (unchanged) |
| `airport-filter` | Airplane + circle-slash overlay | Filtered airport |
| `tracon-filter` | Diamond + circle-slash overlay | Filtered TRACON |
| `artcc-filter` | Square + circle-slash overlay | Filtered ARTCC |

**Implementation note**: Filter variants are facility-type-specific only (same icon regardless of origin/dest, since the prohibition overlay makes direction irrelevant). The `icon-image` case expression grows from 7 to 10 cases (6 existing base + 3 new filter + 1 default). The 6 existing non-filter cases must be guarded with `['!=', ['get', 'isFiltered'], true]` to prevent filter endpoints from matching base icon conditions.

### Airport Icon Design
The airplane silhouette follows the TSD (Traffic Situation Display) jet icon style used by Material Design's `flight` icon:
- 20x20 SVG canvas
- Origin: airplane pointing upward (nose at top)
- Dest: airplane pointing downward (nose at bottom, 180-degree rotation)
- White fill, SDF-compatible (single color, alpha channel only)

### Filter Overlay Design
Circle with diagonal slash (prohibition symbol):
- Thin circle (stroke-width 1.5-2px) at ~80% of icon canvas
- Diagonal line from upper-left to lower-right through the circle
- Overlaid on the base facility icon
- All white/alpha for SDF tinting

---

## 2. Token Parsing

### Grammar
```
route_line  ::= token+ [';' color]
token       ::= facility | filter_group | waypoint | marker | ...
filter_group ::= '(' filter+ ')'
filter      ::= '-' facility_code
facility_code ::= /[A-Z][A-Z0-9]{1,4}/
```

### Rules
- Parentheses are **always required**, even for a single filter: `(-KDFW)` not `-KDFW`
- Multiple filters in one group: `(-KDFW -KAFW -KDAL)`
- Filter groups appear after origin or before dest in the route string
- Bare `-TOKEN` outside parentheses is NOT treated as a filter
- All facility types supported: airports, TRACONs, ARTCCs/FIR/ACC

### Example
```
ZFW (-KDFW -KAFW) ZHU (-KAUS -KSAT) >LOOSE MEM HUTCC KNSAW RUSSA< GLAVN2 KATL
```
Parsed as:
- Origin: `ZFW` (ARTCC)
- Origin filters: `KDFW`, `KAFW` (airports excluded from ZFW scope)
- Additional origin: `ZHU` (ARTCC)
- Additional origin filters: `KAUS`, `KSAT`
- Mandatory enroute: `LOOSE MEM HUTCC KNSAW RUSSA`
- Procedure: `GLAVN2`
- Dest: `KATL`

### Parser Location
New function `extractFilterGroups(text)` in `route-maplibre.js`, called early in `processAndDisplayRoutes()` (around line 2482) **before** the existing expansion pipeline stages. It:
1. Scans for `(...)` blocks containing `-` prefixed tokens
2. Extracts filter facility codes
3. Strips filter groups from the route text before passing to expansion
4. Returns `{ cleanText, filters: [{ code, side, position }] }`

The `side` (origin/dest) is determined by position: filter groups before the enroute portion are origin filters; those after are dest filters. Heuristic: filter groups appearing before the first `>` marker or in the first half of tokens are origin; those after `<` or in the second half are dest.

---

## 3. Rendering

### Filter Endpoints
Filter facilities are added to the `route-endpoints` GeoJSON source with:
- `isFiltered: true` property
- `facilityType`: determined by `detectFacilityType()` (airport/tracon/artcc)
- `isOrigin`: based on which side the filter group appeared

The `route-endpoints-symbols` layer's `icon-image` expression adds 3 new filter cases (checked first, before the 6 base cases):
```javascript
// Filter cases (checked first — order matters)
['all', ['==', ['get', 'isFiltered'], true], ['==', ['get', 'facilityType'], 'airport']], 'endpoint-airport-filter',
['all', ['==', ['get', 'isFiltered'], true], ['==', ['get', 'facilityType'], 'tracon']], 'endpoint-tracon-filter',
['all', ['==', ['get', 'isFiltered'], true], ['==', ['get', 'facilityType'], 'artcc']], 'endpoint-artcc-filter',
// Existing 6 base cases follow (guarded with ['!=', ['get', 'isFiltered'], true])
```

Filter endpoint icon size: same as base (`icon-size` 0.6-0.7).
Filter endpoint opacity: slightly reduced (0.7) to visually subordinate them.

### Filter Fan Connectors
New MapLibre layer `routes-filter-fan` added after the existing `routes-fan` layer:

```javascript
{
    id: 'routes-filter-fan',
    type: 'line',
    source: 'routes',
    filter: ['==', ['get', 'isFilterFan'], true],
    paint: {
        'line-color': ['get', 'color'],
        'line-width': 1.0,
        'line-dasharray': [1, 2],
        'line-opacity': 0.5
    }
}
```

**Generation**: When a filter endpoint is resolved to coordinates, a fan connector is drawn from the filter point to the nearest point on the main route. This reuses the existing fan connector logic (walk from endpoint to first non-airport waypoint) but sets `isFilterFan: true` instead of `isFan: true`.

### Symbology Panel
Add filter fan controls to the symbology settings panel in `route.php` (after existing fan controls, lines 2141-2163):
- Color picker (inherits route color by default)
- Width slider (default 1.0)
- Opacity slider (default 0.5)
- Dash pattern selector (default `[1, 2]`)

Add `filterFan` entry to `DEFAULT_SYMBOLOGY` in `route-symbology.js`:
```javascript
filterFan: { color: null, width: 1.0, opacity: 0.5, dashArray: [1, 2] }
```

Update `applyToMapLibre()` to handle `routes-filter-fan` layer paint properties.

---

## 4. Hitbox Improvement

### Current State
Single `queryRenderedFeatures(e.point, ...)` call at line 3321 of `route-maplibre.js`. Point query (zero tolerance) makes thin lines hard to click.

### Change
Replace point query with bbox query using 5px tolerance:

```javascript
var bbox = [[e.point.x - 5, e.point.y - 5], [e.point.x + 5, e.point.y + 5]];
var allFeatures = graphic_map.queryRenderedFeatures(bbox, {
    layers: ['routes-solid', 'routes-dashed', 'routes-fan', 'routes-filter-fan'],
});
```

**Performance**: Zero cost — MapLibre uses GPU-accelerated R-tree for spatial queries. bbox query is the same code path as point query with a larger search area.

### Additional Changes
- Add `routes-filter-fan` to the cursor handler layer array (line 3286)
- No changes to feature deduplication or route identification logic

---

## 5. Playbook Filter Interoperability

### Problem
Playbook stores `origin_filter` and `dest_filter` as DB fields (e.g., `-KDFW -KAFW`). When `plotOnMap()` and `openInRoutePage()` build route strings for the map, they assemble `[origin, route_string, dest]` but **drop filter data**. This means playbook routes never render filter symbology on the map.

### Solution (Approach A — Minimal Delta)
Inject `(-filter1 -filter2)` groups from `r.origin_filter` / `r.dest_filter` into the assembled route string. **Critical ordering**: filter groups must be injected **after** the mandatory marker wrapping step (lines 2421-2439), not during the initial `parts` assembly. This prevents the mandatory wrapping logic from incorrectly applying `>` / `<` markers to filter group tokens.

**Helper function** (new, shared by both functions):
```javascript
function buildFilterGroup(filterStr) {
    if (!filterStr) return '';
    var filters = filterStr.trim().split(/\s+/).filter(Boolean);
    if (!filters.length) return '';
    return '(' + filters.map(function(f) {
        return f.charAt(0) === '-' ? f : '-' + f;
    }).join(' ') + ')';
}
```

**`plotOnMap()`** — 3 paths (search, groups, default) at lines 2375-2413:
Route string assembly remains unchanged (`parts = [origin, route_string, dest]`). After the mandatory wrapping step (line 2444), inject filter groups into the wrapped text:

```javascript
// After mandatory wrapping, inject filter groups from DB fields
text = text.split('\n').map(function(line, idx) {
    var r = selected[idx]; // or correct route for this line
    if (!r) return line;
    var origGroup = buildFilterGroup(r.origin_filter);
    var destGroup = buildFilterGroup(r.dest_filter);
    if (!origGroup && !destGroup) return line;
    // Separate color suffix
    var colorSuffix = '';
    var semiIdx = line.indexOf(';');
    if (semiIdx !== -1) { colorSuffix = line.slice(semiIdx); line = line.slice(0, semiIdx); }
    var tokens = line.trim().split(/\s+/);
    // Insert origin filter after first token (origin facility)
    if (origGroup && tokens.length > 1) tokens.splice(1, 0, origGroup);
    // Append dest filter before last token (dest facility)
    if (destGroup && tokens.length > 1) tokens.splice(tokens.length - 1, 0, destGroup);
    return tokens.join(' ') + colorSuffix;
}).join('\n');
```

**`openInRoutePage()`** — 2 paths (groups, default) at lines 2503-2519:
Same injection pattern, applied after route string assembly (no mandatory wrapping in this function, so injection during `parts` assembly is safe here):
```javascript
var parts = [];
if (r.origin) parts.push(r.origin);
var origGroup = buildFilterGroup(r.origin_filter);
if (origGroup) parts.push(origGroup);
parts.push(r.route_string);
var destGroup = buildFilterGroup(r.dest_filter);
if (destGroup) parts.push(destGroup);
if (r.dest) parts.push(r.dest);
```

### What Does NOT Change
- `buildCurrentPBDirective()` — PB directive uses dot-separated format, incompatible with parentheses
- Route editor UI — already has filter input fields
- API save/update/fetch — already handles filters correctly
- `consolidateRoutes()` — already merges filter Sets
- Pivot groups — already tracks filter data

### Filter Format Normalization
DB stores filters as space-separated codes, optionally with `-` prefix. The injection normalizes: codes without `-` get it prepended. This ensures the route-maplibre.js parser always sees `(-KDFW -KAFW)` format.

---

## 6. Help Dialog & Documentation

### route.php Help Panel (lines 1548-1603)
Add new bullet after the existing route syntax items:

> **Facility Filters**: Use `(-CODE)` to mark facilities as filtered (excluded). Example: `ZFW (-KDFW -KAFW)` means "ZFW ARTCC scope, excluding KDFW and KAFW". Filters work for airports, TRACONs, and ARTCCs. Parentheses are always required, even for a single filter.

### i18n Keys
Add to `assets/locales/en-US.json`:
- `route.help.filters` — Filter syntax help text
- `route.help.filtersExample` — Example with filters
- `route.symbology.filterFan` — "Filter Fan"
- `route.symbology.filterFanColor` — "Filter Fan Color"
- `route.symbology.filterFanWidth` — "Filter Fan Width"
- `route.symbology.filterFanOpacity` — "Filter Fan Opacity"
- `route.symbology.filterFanDash` — "Filter Fan Dash"

Add to `assets/locales/fr-CA.json`:
- French translations for all new keys above

`en-CA.json` and `en-EU.json` — no changes needed (fall through to en-US).

---

## 7. Files Modified

| File | Changes |
|------|---------|
| `assets/js/route-maplibre.js` | New icons (9 SVGs), filter parser, filter fan layer, filter endpoint generation, bbox hitbox, cursor handler update |
| `assets/js/route-symbology.js` | `filterFan` default, `applyToMapLibre()` for new layer |
| `assets/js/playbook.js` | Filter injection in `plotOnMap()` (3 paths) and `openInRoutePage()` (2 paths) |
| `route.php` | Help dialog update, symbology panel filter fan controls |
| `assets/locales/en-US.json` | New i18n keys for filter help and symbology |
| `assets/locales/fr-CA.json` | French translations for new keys |

---

## 8. Non-Goals & Constraints

- **No animation**: Filter fan connectors are static (no pulsing, no transitions) for computational efficiency
- **No new DB schema**: Playbook already stores `origin_filter`/`dest_filter`
- **No new API endpoints**: Existing playbook API already handles filters
- **SDF icons only**: All icons remain single-color SDF for MapLibre tinting compatibility
- **MapLibre `line-dasharray` limitation**: Not data-driven, requiring separate `routes-filter-fan` layer (cannot conditionally apply dash pattern within a single layer)

## 9. Edge Cases

- **Unknown filter facility**: If a filter code (e.g., `FOOBAR`) cannot be geocoded by `resolve_waypoint()`, the filter endpoint simply does not render. No error — silent skip, same as unknown waypoints in the main route.
- **Mandatory marker wrapping order**: In `plotOnMap()`, filter groups are injected AFTER mandatory marker wrapping to prevent `>(-KDFW)` corruption. See Section 5 for details.
- **Empty filter strings**: DB may store empty `origin_filter`/`dest_filter`. The `buildFilterGroup()` helper returns `''` for empty/null input — no injection occurs.
- **Filter groups in hand-typed routes**: Users may type `(-KDFW)` directly in route.php's text area. The parser handles this natively — no playbook-specific path needed.
- **Existing parentheses in routes**: Current route syntax does not use parentheses. The parser only matches `(` ... `)` blocks where ALL tokens start with `-`. A block like `(FIX1 FIX2)` without `-` prefixes is NOT treated as a filter group.

## 10. Testing

Manual testing via live site:
1. Type route with filters in route.php: `ZFW (-KDFW) >J4 MEM< KATL` — verify filter icon and fan connector render
2. Select playbook routes with stored filters — verify filter symbology appears on map
3. Click "Open in Route Page" from playbook — verify filters carry over
4. Test hitbox improvement — click near (but not directly on) route lines
5. Test symbology panel — adjust filter fan color/width/opacity/dash
6. Verify help dialog shows filter syntax documentation
