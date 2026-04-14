# Route Resolution Pipeline Divergence Audit

**Date**: 2026-03-19
**Status**: Complete audit with live test verification

## Executive Summary

PERTI has **7 route resolution pipelines** that should produce consistent results but diverge in 8 critical dimensions. Live testing with real playbook route 402533 (`CAM BRUIN YCF YEE ASP J522 GRB J106 GEP J70 MLP.GLASR3 → KSEA`) confirms:

- **analysis.php Mode 2** produces 6 waypoints (airways not expanded)
- **expand_route_with_artccs()** also produces 6 waypoints (same root cause)
- **Client-side route-maplibre.js** produces full airway-expanded geometry (tested separately via awys.csv)
- **Root cause**: PostGIS airway data is severely incomplete (J522 has 1 segment instead of ~15+)

---

## The 7 Pipelines

| # | Pipeline | Location | When Used |
|---|----------|----------|-----------|
| 1 | **expand_route()** | PostGIS function | Core route expansion (called by #2, #6) |
| 2 | **expand_route_with_artccs()** | PostGIS function | Playbook save, backfill, _rcb.php recompute |
| 3 | **computeTraversedFacilities()** | `api/mgt/playbook/playbook_helpers.php` | Playbook route save/edit via API |
| 4 | **backfill_geometry.php** | `scripts/playbook/backfill_geometry.php` | Batch geometry backfill |
| 5 | **_rcb.php** (temp) | Production temp script | ARTCC-only recompute (currently running) |
| 6 | **analysis.php Mode 2** | `api/data/playbook/analysis.php` | Route analysis panel (server fallback) |
| 7 | **route-maplibre.js** (Mode 1) | `assets/js/route-maplibre.js` | Client-side map rendering → analysis panel |

---

## Dimension-by-Dimension Comparison

### 1. Waypoint/Fix Resolution

| Pipeline | Data Source | Disambiguation | Coordinate Parsing |
|----------|-----------|----------------|-------------------|
| **expand_route()** (#1) | PostGIS `nav_fixes` (269K), `airports`, `area_centers` | Proximity (prev+lookahead lat/lon) | 5 aviation formats via `parse_coordinate_token()` |
| **expand_route_with_artccs()** (#2) | Calls #1 | Inherited from #1 | Inherited from #1 |
| **computeTraversedFacilities()** (#3) | Calls #2 | Inherited from #1 | Inherited from #1 |
| **backfill_geometry.php** (#4) | Calls #2 | Inherited from #1 | Inherited from #1 |
| **_rcb.php** (#5) | Calls #2 | Inherited from #1 | Inherited from #1 |
| **analysis.php Mode 2** (#6) | Calls #1 directly (NOT #2) | Inherited from #1 | Inherited from #1 |
| **route-maplibre.js** (#7) | `points.csv` (client-loaded), `areaCenters` | Proximity (prev+next context) | Custom `parseCoordinateToken()` |

**Divergence**: Pipeline #7 uses a completely separate fix database (`points.csv`) from PostGIS `nav_fixes`. These may have different fix counts, coordinates, or disambiguation behavior.

### 2. Airway Expansion

| Pipeline | Data Source | Gap Checks | Failure Behavior |
|----------|-----------|------------|-----------------|
| **PostGIS (#1-6)** | `airways` + `airway_segments` tables | 2000km intra-segment, 500km inter-segment | **Silent skip** — airway token dropped, exit fix processed as standalone |
| **route-maplibre.js (#7)** | `awys.csv` (client-loaded) | None documented | Falls through to fix resolution |

**CRITICAL DIVERGENCE (verified with live data)**:

PostGIS `airway_segments` table has **severely incomplete data**:
- **J522**: 1 segment (RSI→RAGNO) — real J522 has ~15+ segments including ASP, GRB, BAE, BUF, etc.
- **J106**: 10 fixes across 2 variants — missing GRB and GEP
- **J70**: Works correctly (GEP→MLP, 5 waypoints)

**Impact**: Routes using airways with missing data silently degrade to fix-only resolution. Tested route 402533 produced 6 waypoints instead of ~25+, with a 2075nm straight-line distance that misses intermediate ARTCC crossings.

**Client-side `awys.csv`** is the authoritative data source (per CLAUDE.md: "Authoritative source is `assets/data/awys.csv`; DB tables can go stale").

### 3. SID/STAR Procedure Handling

| Pipeline | Handling | Data Source |
|----------|----------|-------------|
| **PostGIS (#1-6)** | **Strip dot notation only** — `KDFW.LOWGN5` → `KDFW`. Procedure name discarded. No waypoint expansion. | N/A |
| **route-maplibre.js (#7)** | **Full procedure expansion** via `procs_enhanced.js` — `getDpRoutePoints()` / `getStarRoutePoints()` resolve to component waypoint arrays | `procs_enhanced.js` (54.6KB, DP/STAR CSV databases) |

**CRITICAL DIVERGENCE**: Server pipelines treat `MLP.GLASR3` as just `MLP`. Client expands `GLASR3` STAR to its component waypoints (MLP → intermediate STAR fixes → runway). This means:
- Server geometry ends at the last named fix before the procedure
- Client geometry includes the full arrival/departure procedure path
- Facility traversal near origin/destination airports may differ

### 4. Origin/Destination Endpoint Injection

| Pipeline | Injects Origin? | Injects Dest? | Deduplication |
|----------|----------------|---------------|---------------|
| **computeTraversedFacilities()** (#3) | Yes (airport from `origin_airports` or `origin` label) | Yes | Checks if already first/last token |
| **backfill_geometry.php** (#4) | Yes | Yes | Checks if already first/last token |
| **_rcb.php** (#5) | Yes | Yes | Checks if already first/last token; filters UNKN/VARIOUS |
| **analysis.php Mode 2** (#6) | Yes (`$origin . ' ' . $full_route`) | Yes | **NO deduplication** — always prepends/appends |
| **route-maplibre.js** (#7) | No (routes rendered as-is) | No | N/A |

**DIVERGENCE**: analysis.php Mode 2 always prepends origin and appends dest without checking if they're already in the route. This caused the duplicate `KDFW KDFW` waypoint observed in testing.

### 5. ARTCC Code Normalization

| Pipeline | KZ→Z Prefix Strip | Canadian 3→4 Letter | PAZA→ZAN | Applied To |
|----------|-------------------|---------------------|----------|------------|
| **PostGIS (#1-2)** | No | No | No | N/A — returns raw boundary codes |
| **computeTraversedFacilities()** (#3) | Yes (`normalizeCanadianArtcc()`) | Yes | Yes | GIS results only (**NOT origin/dest ARTCCs**) |
| **backfill_geometry.php** (#4) | Yes | Yes | Yes | Route tokens + origin/dest ARTCCs + GIS results |
| **_rcb.php** (#5) | Yes (`normalizeCA()`) | Yes | Yes | Route tokens + origin/dest ARTCCs + GIS results |
| **analysis.php Mode 2** (#6) | **No** | **No** | **No** | No normalization at all |
| **route-maplibre.js** (#7) | N/A (client-side) | N/A | N/A | Uses `facility-hierarchy.js` aliases for display |

**DIVERGENCE**: analysis.php returns raw K-prefixed ARTCC codes (`KZFW`, `KZME`, `KZSE`) while backfill/computeTraversed normalize to `ZFW`, `ZME`, `ZSE`. The `analyze_route_traversal()` function returns raw codes from `artcc_boundaries.artcc_code`.

**computeTraversedFacilities()** has a **partial normalization bug**: normalizes GIS results but NOT origin/dest ARTCCs passed in as parameters.

### 6. ARTCC Boundary Filtering

| Pipeline | is_subsector Filter | ST_IsValid Pre-filter | ST_MakeValid |
|----------|--------------------|-----------------------|-------------|
| **expand_route_with_artccs()** (#2) | **Yes** (migration 016) | No | No |
| **computeTraversedFacilities()** (#3) | Relies on #2 for ARTCCs; **NO filter** for TRACONs/sectors | **No** | No |
| **analyze_route_traversal()** (used by #6) | **Yes** (`NOT ab.is_subsector`) | No | **Yes** (`ST_MakeValid()`) |

**DIVERGENCE**: computeTraversedFacilities() does NOT pre-filter `ST_IsValid()` on TRACON/sector boundaries. Known 108 invalid TRACONs + 265 invalid sectors can cause "Geometry could not be converted to GEOS" errors. `analyze_route_traversal()` uses `ST_MakeValid()` which is safer.

### 7. Facility Traversal Detection

| Pipeline | ARTCC Source | TRACON Detection | Sector Detection | Ordering |
|----------|-------------|-----------------|-----------------|----------|
| **expand_route_with_artccs()** (#2) | `ST_Intersects` + `ST_LineLocatePoint` centroid ordering, `DISTINCT ON` | No | No | Centroid position along route |
| **computeTraversedFacilities()** (#3) | From #2 `artccs_traversed` array | Own `ST_Intersects` LATERAL JOIN | Own `ST_Intersects` LATERAL JOIN | ARTCC→TRACON→Sector, then `ST_LineLocatePoint` |
| **analyze_route_traversal()** (used by #6) | `ST_Intersection` + `ST_Dump` + entry/exit fractions | Same approach | Same approach | Entry fraction along route |
| **backfill_geometry.php** (#4) | From #2 + origin/dest merge | Own LATERAL JOIN (same as #3) | Own LATERAL JOIN (same as #3) | Same as #3 |
| **_rcb.php** (#5) | From #2 + origin/dest merge | No | No | N/A |

**DIVERGENCE**: Two completely different facility traversal approaches:
- **Approach A** (#2 via centroid): `DISTINCT ON (artcc_code)` + centroid ordering. If a route re-enters the same ARTCC, only first crossing is kept.
- **Approach B** (#6 via analyze_route_traversal): `ST_Dump` extracts individual crossing segments, computes entry/exit fractions for each segment, handles re-entry correctly.

### 8. Route Geometry Handling

| Pipeline | Densification | Distance Calculation | Geometry Stored? |
|----------|--------------|---------------------|-----------------|
| **expand_route_with_artccs()** (#2) | None (straight lines between waypoints) | `ST_Length(geography)` | Yes (LINESTRING) |
| **analyze_route_traversal()** (#6) | `ST_Segmentize(50km)` on geography | `ST_Length(geography)` of densified route | No (input only) |
| **route-maplibre.js** (#7) | None (straight lines) | Client-side Haversine | No (rendering only) |

**DIVERGENCE**: `analyze_route_traversal()` densifies to 50km segments for accurate great-circle ARTCC boundary detection. `expand_route_with_artccs()` uses raw straight lines, which at high latitudes (Canadian routes) can deviate significantly from the geodesic path, potentially missing ARTCC crossings.

---

## Live Test Results

### Test Route 402533

**Route**: `CAM BRUIN YCF YEE ASP J522 GRB J106 GEP J70 MLP.GLASR3`
**Origin ARTCCs**: ZBW | **Dest ARTCCs**: ZSE | **Dest Airport**: KSEA

| Pipeline | Waypoints | ARTCCs | Distance | Notes |
|----------|-----------|--------|----------|-------|
| **_rcb.php backfill** | N/A | ZBW,CZYZ,ZMP,ZLC,ZSE | N/A | Normalized codes, includes origin/dest merge |
| **analysis.php Mode 2** | 6 (no airway expansion) | KZBW,CZYZ,KZMP,KZLC,KZSE | 2075nm | K-prefix on US codes, airways skipped |
| **expand_route_with_artccs()** | 6 | KZBW,CZYZ,KZMP,KZLC,KZSE | 2075nm | Same as analysis.php (same function) |

**Key findings**:
1. Airways J522 and J106 **completely failed to expand** due to missing PostGIS data
2. J70 expanded correctly (GEP→ABR→DIK→LWT→MLP)
3. Route went from ~25 expected waypoints to just 6
4. ARTCC codes preserved K-prefix (`KZBW`) vs normalized (`ZBW`)
5. Missing intermediate waypoints means straight-line geometry from ASP (Michigan) to KSEA (Seattle) — 1643nm with no curvature, potentially missing intermediate ARTCC crossings

### PostGIS Airway Data Quality (Verified)

| Airway | Expected Fixes | PostGIS Segments | Status |
|--------|---------------|-----------------|--------|
| J522 | ~15+ (ASP, GRB, BAE, BUF, etc.) | 1 (RSI→RAGNO) | **Severely incomplete** |
| J106 | ~15+ (GRB, GEP, etc.) | 10 fixes, 2 variants | **Missing key fixes** |
| J70 | ~10+ | Works (GEP→MLP, 5 fixes) | OK |

---

## Divergence Priority Matrix

| # | Divergence | Severity | Impact | Affected Pipelines |
|---|-----------|----------|--------|-------------------|
| **D1** | PostGIS airway data incomplete | **CRITICAL** | Silent route degradation; missing intermediate waypoints/ARTCCs | #1-6 (all server-side) |
| **D2** | No SID/STAR expansion server-side | **HIGH** | Missing procedure waypoints near airports; facility traversal gaps at origin/dest | #1-6 |
| **D3** | ARTCC code normalization inconsistent | **MEDIUM** | K-prefix codes in some outputs, normalized in others; breaks facility matching/comparison | #3 vs #4/#5 vs #6 |
| **D4** | analysis.php duplicate endpoint injection | **LOW** | Duplicate first waypoint when origin matches first route token | #6 |
| **D5** | No ST_IsValid pre-filter in computeTraversedFacilities | **MEDIUM** | TRACON/sector queries can fail on 373 invalid boundaries | #3, #4 |
| **D6** | Different fix databases (points.csv vs nav_fixes) | **MEDIUM** | Client and server may resolve same fix name to different coordinates | #7 vs #1-6 |
| **D7** | Densification difference | **LOW** | High-latitude routes may miss ARTCC crossings in #2 but catch them in #6 | #2 vs #6 |
| **D8** | computeTraversedFacilities partial normalization | **LOW** | Origin/dest ARTCCs not normalized but GIS results are | #3 |

---

## Recommended Fix Priority

### Priority 1: Sync PostGIS airway data from awys.csv (fixes D1)
The `assets/data/awys.csv` is authoritative. The PostGIS `airways` + `airway_segments` tables are stale/incomplete. Need to run the airway import pipeline (`scripts/playbook/import_airways.php` or equivalent) to populate PostGIS from the CSV source.

**Impact**: Fixes the most critical divergence. All server-side pipelines will produce full airway expansion.

### Priority 2: Add ARTCC normalization to analyze_route_traversal output (fixes D3, D8)
Either:
- Add normalization inside `analyze_route_traversal()` (PostGIS function)
- Or normalize in the PHP consumer (`analysis.php`)

Also fix `computeTraversedFacilities()` to normalize origin/dest ARTCCs (not just GIS results).

### Priority 3: Fix analysis.php duplicate endpoint injection (fixes D4)
Add deduplication check before prepending origin / appending dest, matching the pattern used in backfill_geometry.php and _rcb.php.

### Priority 4: Add ST_IsValid pre-filter to computeTraversedFacilities (fixes D5)
Add `WHERE ST_IsValid(geom)` to the TRACON/sector subqueries in the LATERAL JOIN.

### Priority 5: Add SID/STAR expansion to PostGIS (fixes D2)
Create a PostGIS function that resolves procedure tokens using `nav_procedures` data. This is a larger effort but would close the biggest functional gap between client and server resolution.

### Priority 6: Align fix databases (fixes D6)
Either:
- Generate `points.csv` from PostGIS `nav_fixes` to ensure consistency
- Or import `points.csv` into PostGIS as the authoritative source

---

## Source Files Referenced

| File | Pipeline | Lines of Interest |
|------|----------|-------------------|
| `scripts/postgis/004_route_expansion_functions.sql` | #1, #2 | expand_route (505-766), expand_route_with_artccs (774-835), expand_airway (182-368) |
| `scripts/postgis/016_hierarchy_filter_functions.sql` | #2 | is_subsector filter (545-607) |
| `database/migrations/postgis/route_analysis_function.sql` | #6 | analyze_route_traversal (1-188) |
| `api/mgt/playbook/playbook_helpers.php` | #3 | computeTraversedFacilities (86-254) |
| `scripts/playbook/backfill_geometry.php` | #4 | Full backfill pipeline (407 lines) |
| `api/data/playbook/analysis.php` | #6 | Mode 2 route expansion (230-282), facility traversal (284-292) |
| `assets/js/route-maplibre.js` | #7 | ConvertRoute (825-867), getPointByName (650-707), procedure expansion (2758-2770) |
| `assets/js/procs_enhanced.js` | #7 | getDpRoutePoints (883), getStarRoutePoints (922), expandRouteProcedures (967) |
| `assets/data/awys.csv` | #7 | Authoritative airway data |

---

## Methodology

1. **Code analysis**: Read all 7 pipeline source files via parallel subagents
2. **Verification**: Spot-checked specific claims (SQL queries, function signatures, normalization logic) via targeted reads
3. **Live testing**: Deployed temp PHP scripts to production, tested real playbook route 402533 through analysis.php and expand_route_with_artccs()
4. **Airway debugging**: Queried PostGIS `airways` and `airway_segments` tables directly to confirm J522/J106 data gaps
5. **Comparison**: Matched analysis.php output against _rcb.php backfilled `traversed_artccs` values
