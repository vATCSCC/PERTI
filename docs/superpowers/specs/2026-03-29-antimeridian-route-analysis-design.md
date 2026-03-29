# Antimeridian-Safe Route Analysis

**Date**: 2026-03-29
**Status**: Design
**Scope**: PostGIS spatial functions + PHP analysis endpoints

## Problem

Routes crossing the antimeridian (International Date Line, +/-180 longitude) produce
incorrect facility traversal results. A route like ANC_CARGO_ROUTES (Asia to PANC)
shows every ARTCC/FIR/TRACON on the globe as "traversed" instead of just the
facilities along the actual flight path.

### Root Cause

`ST_MakeLine()` builds a LINESTRING in SRID 4326 geometry space. When consecutive
waypoints cross the antimeridian (e.g. lon 170 to lon -170), PostGIS creates a
Cartesian segment that sweeps 340 degrees the *wrong way* around the globe instead
of 20 degrees across the antimeridian. All downstream geometry operations
(`ST_Intersects`, `ST_LineLocatePoint`, `ST_Intersection`) operate on this incorrect
line, producing false positive boundary hits and wrong fractional positions.

`ST_Segmentize(geom::geography, 50000)::geometry` correctly densifies along the
geodesic shortest path, but when cast back to geometry the result still has at
least one segment near +/-180 with a ~360-degree Cartesian jump. A single bad
segment is enough to intersect nearly every boundary on Earth.

`ST_Length(geom::geography)` is NOT affected -- geography type computes geodesic
distance correctly regardless of the antimeridian.

### Observed Symptoms

- Facility traversal panel shows 50+ ARTCCs for a Pacific route
- Distance-within-facility values are nonsensical
- Crossing daemon produces hundreds of false boundary crossings for trans-Pacific flights
- Route distance (from geography) is correct but fractional positions are wrong

## Solution: ST_ShiftLongitude Strategy

When a route crosses the antimeridian, shift all coordinates to the [0, 360] range
using `ST_ShiftLongitude()`. This eliminates the discontinuity at +/-180 because
-170 becomes 190, creating a continuous Cartesian path. Boundary geometries are
shifted to match before intersection. Output coordinates are normalized back to
[-180, 180].

### Why ST_ShiftLongitude

- Built-in PostGIS function, no custom math needed
- Shifts [-180, 0] to [180, 360]; leaves [0, 180] unchanged
- Result: Pacific routes that cross the antimeridian get continuous longitude values
- All Cartesian geometry operations (ST_Intersects, ST_LineLocatePoint,
  ST_Intersection, ST_Contains, ST_LineInterpolatePoint) work correctly on the
  shifted geometry because there is no discontinuity
- Negligible performance cost (coordinate addition per vertex)

### Limitations

- Assumes no boundary polygon simultaneously straddles both the prime meridian AND
  the antimeridian (not possible for real-world airspace boundaries)
- Route must not wrap more than 360 degrees (not a real flight path)

## Helper Functions

Two new PostGIS functions centralize detection and output normalization.

### crosses_antimeridian(geom geometry) -> boolean

Detects whether any segment of a LINESTRING has a longitude jump exceeding 180
degrees between consecutive vertices.

```sql
CREATE OR REPLACE FUNCTION crosses_antimeridian(p_geom geometry)
RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT EXISTS (
        SELECT 1
        FROM (
            SELECT
                ST_X((dp).geom) AS lon,
                LAG(ST_X((dp).geom)) OVER (ORDER BY (dp).path[1]) AS prev_lon
            FROM ST_DumpPoints(p_geom) dp
        ) pts
        WHERE prev_lon IS NOT NULL
          AND ABS(lon - prev_lon) > 180
    );
$$;
```

Notes:
- Uses `ST_DumpPoints` + window function to compare consecutive vertices
- Threshold of 180 degrees is unambiguous -- no real-world waypoint pair within a
  route would have a legitimate longitude gap > 180 without crossing the antimeridian
- IMMUTABLE because result depends only on input geometry

### normalize_lon(lon double precision) -> double precision

Normalizes a longitude value from [0, 360] back to [-180, 180] for output.

```sql
CREATE OR REPLACE FUNCTION normalize_lon(p_lon double precision)
RETURNS double precision LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_lon > 180 THEN p_lon - 360 ELSE p_lon END;
$$;
```

## Affected Functions -- Detailed Changes

### Pattern Applied to Each Function

Every function that builds a route LINESTRING and intersects it with boundaries
gets the same structural change:

```
1. Build route geometry (ST_MakeLine or ST_GeomFromText)
2. If geography-based densification exists (only analyze_route_traversal):
   Densify FIRST, then detect + shift (see "Densification Ordering" below)
3. Otherwise: v_shifted := crosses_antimeridian(route_geom)
4. IF v_shifted THEN route_geom := ST_ShiftLongitude(route_geom)
5. In ST_Intersects / ST_Intersection calls:
   Replace: ST_Intersects(route, boundary.geom)
   With:    ST_Intersects(route, CASE WHEN v_shifted
                THEN ST_ShiftLongitude(boundary.geom) ELSE boundary.geom END)
6. In ST_Contains / ST_LineInterpolatePoint calls (entry/exit determination):
   Shift the boundary argument when v_shifted is true.
   ST_LineInterpolatePoint uses the route (already shifted) -- no extra change.
7. In output coordinates (ST_X / lon values):
   Replace: ST_X(point)
   With:    normalize_lon(ST_X(point))
```

### Densification Ordering for analyze_route_traversal()

`analyze_route_traversal()` is the only function that uses geography-based
densification (`ST_Segmentize(::geography)`). Since geography expects [-180, 180]
input, the order must be:

```
1. Densify first:  v_route := ST_Segmentize(p_route_geom::geography, 50000)::geometry
2. Then detect:    v_shifted := crosses_antimeridian(v_route)
3. Then shift:     IF v_shifted THEN v_route := ST_ShiftLongitude(v_route)
```

The densified geometry preserves the correct geodesic path. Shifting it afterward
removes the single-segment discontinuity at +/-180. All subsequent Cartesian
operations then work correctly on the shifted geometry.

For route length, continue using `ST_Length(v_route::geography)` BEFORE shifting --
geography handles antimeridian correctly.

### File: database/migrations/postgis/018_antimeridian_helpers.sql (NEW)

Creates `crosses_antimeridian()` and `normalize_lon()` helper functions.
Grants execute to jpeterson.

### File: database/migrations/postgis/route_analysis_function.sql

#### analyze_route_traversal()

Current flow:
```
densify -> compute length -> intersect boundaries -> extract fractions -> merge -> output
```

New flow:
```
densify -> compute length -> detect AM crossing -> shift if needed ->
intersect (shifted) boundaries -> extract fractions -> merge ->
output with normalize_lon()
```

Changes:
- Add `v_shifted boolean` variable
- After densification + length computation, detect and shift
- In `boundary_hits` CTE: wrap each `ab.geom` / `tb.geom` / `sb.geom` with
  conditional `ST_ShiftLongitude`
- In `fractions` CTE: `ST_X` calls already extract from intersection results
  which are in shifted space -- apply `normalize_lon()` to `e_lon` and `x_lon`
- In final SELECT: `normalize_lon()` on the ROUND(e_lon/x_lon) outputs

#### route_string_to_linestring()

Add antimeridian detection + shift after `ST_MakeLine`. Since this function returns
a raw geometry consumed by `analyze_route_traversal()` (which does its own
detection), no shift is needed here -- the consumer handles it. However, if this
function is called standalone, the caller gets broken geometry. For safety, add a
parameter `p_shift_antimeridian boolean DEFAULT false` or just document that the
caller must handle antimeridian.

Decision: Leave `route_string_to_linestring()` unchanged. It is only called from
`analysis.php` which passes its output to `analyze_route_traversal()`, and that
function handles the shift. Adding a shift here would double-shift.

### File: scripts/postgis/008_trajectory_crossings.sql

#### build_trajectory_line()

Leave unchanged. This is a low-level geometry builder called by multiple functions
that each handle shifting internally. Shifting here would require all callers to
know the geometry is in [0, 360] space, which breaks the abstraction.

#### get_trajectory_all_crossings()

Changes:
- After `build_trajectory_line()`, detect and shift
- Shift `total_length_m` computation: use `ST_Length(trajectory::geography)` BEFORE
  shifting (geography handles AM correctly)
- In `artcc_crossings` CTE: shift `ab.geom` and `ST_Boundary(ab.geom)` conditionally
- In `sector_crossings` CTE: shift `sb.geom` and `ST_Boundary(sb.geom)` conditionally
- In `crossing_details` CTE: `normalize_lon()` on `ST_X(crossing_point)`
- In entry/exit `ST_Contains`: shift the boundary lookup geometry conditionally
- In `ST_LineInterpolatePoint`: uses `trajectory` which is already shifted -- correct

#### get_trajectory_artcc_crossings()

Same pattern as `get_trajectory_all_crossings` ARTCC block. Independent function
that does its own `build_trajectory_line` + intersection.

#### get_trajectory_sector_crossings()

Same pattern for sector block.

#### get_artccs_traversed()

Changes:
- After `build_trajectory_line()`, detect and shift
- Shift `ab.geom` and `ST_Boundary(ab.geom)` in the CROSS JOIN LATERAL and
  `ST_Intersects` filter
- `normalize_lon()` not needed here (returns TEXT[] of ARTCC codes, no coordinates)

#### calculate_crossing_etas()

No changes needed. Pure wrapper that calls `get_trajectory_all_crossings()` and
does time arithmetic on the results. The underlying function handles shifting.

#### calculate_crossings_batch()

No changes needed. Pure wrapper that calls `calculate_crossing_etas()`.

### File: database/migrations/postgis/002_extended_functions.sql

#### get_route_boundaries()

Changes:
- After `ST_MakeLine` (line 47-57), detect and shift
- 5 RETURN QUERY blocks (ARTCC, TRACON, SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH):
  each must shift boundary geom conditionally in `ST_Intersects` and `ST_Intersection`
- `ST_LineLocatePoint` calls use `route_geom` which is shifted -- correct
- `ST_StartPoint` / `ST_EndPoint` of intersection results are in shifted space --
  apply `normalize_lon()` to the lon values in entry_point/exit_point JSONB

#### analyze_tmi_route()

Changes:
- After building `route_geom` (multiple code paths: lines 339, 342, 345, 358),
  detect and shift
- 3 intersection blocks (ARTCC line 383, TRACON line 389, sector line 400):
  shift `geom` conditionally
- No coordinate output normalization needed (returns codes/names, not coordinates)

#### get_route_tracons()

Changes:
- After `ST_MakeLine` (line 462), detect and shift
- Shift `tb.geom` in `ST_Intersects` and `ST_Intersection` (line 483-485)
- `normalize_lon()` not needed (returns codes/names + traversal_order float)

#### get_boundaries_at_point()

No changes. Point-in-polygon lookup, no route involved.

#### get_artcc_for_airport()

No changes. Point-in-polygon lookup.

### File: api/data/playbook/analysis.php

#### Mode 1 (client waypoints, lines 182-216)

The WKT is built from client waypoint coordinates. The SQL query computes
`ST_Length(geom::geography)` (correct) and `ST_LineLocatePoint(geom, point)`
(broken for antimeridian routes).

Fix: Add antimeridian detection + shift in the SQL CTE.

```sql
WITH route AS (
    SELECT ST_GeomFromText(:wkt, 4326) AS geom_raw
),
route_shifted AS (
    SELECT
        geom_raw,
        crosses_antimeridian(geom_raw) AS shifted,
        CASE WHEN crosses_antimeridian(geom_raw)
             THEN ST_ShiftLongitude(geom_raw)
             ELSE geom_raw
        END AS geom
    FROM route
),
wp AS (
    SELECT
        (elem->>'fix')::text AS fix_name,
        (elem->>'lat')::float AS lat,
        (elem->>'lon')::float AS lon,
        ordinality AS seq
    FROM jsonb_array_elements(:wps::jsonb) WITH ORDINALITY AS t(elem, ordinality)
)
SELECT
    ST_AsText(rs.geom_raw) AS route_wkt,
    ST_Length(rs.geom_raw::geography) / 1852.0 AS total_dist_nm,
    jsonb_agg(
        jsonb_build_object(
            'fix_name', wp.fix_name,
            'lat', wp.lat,
            'lon', wp.lon,
            'fraction', ST_LineLocatePoint(
                rs.geom,
                CASE WHEN rs.shifted
                     THEN ST_ShiftLongitude(ST_SetSRID(ST_MakePoint(wp.lon, wp.lat), 4326))
                     ELSE ST_SetSRID(ST_MakePoint(wp.lon, wp.lat), 4326)
                END
            )
        ) ORDER BY wp.seq
    ) AS waypoints_json
FROM route_shifted rs, wp
GROUP BY rs.geom_raw, rs.geom, rs.shifted
```

Key points:
- `route_wkt` and `total_dist_nm` use the ORIGINAL (unshifted) geometry -- geography
  handles antimeridian correctly, and the WKT is passed to `analyze_route_traversal()`
  which does its own shifting
- `ST_LineLocatePoint` uses the shifted geometry and shifted waypoint points
- Waypoint lat/lon in the output are the original values (not shifted)

#### Mode 2 (expand_route, lines 253-277)

Same pattern. The CTE builds geometry from `expand_route()` waypoints via
`ST_MakeLine`. Add antimeridian detection + shift for the `ST_LineLocatePoint` calls.

```sql
WITH expanded AS (
    SELECT waypoint_seq, waypoint_id, lat, lon, waypoint_type
    FROM expand_route(:route)
),
route_line AS (
    SELECT ST_MakeLine(
        ARRAY(
            SELECT ST_SetSRID(ST_MakePoint(e.lon::float, e.lat::float), 4326)
            FROM expanded e ORDER BY e.waypoint_seq
        )
    ) AS geom
),
route_shifted AS (
    SELECT
        rl.geom AS geom_raw,
        crosses_antimeridian(rl.geom) AS shifted,
        CASE WHEN crosses_antimeridian(rl.geom)
             THEN ST_ShiftLongitude(rl.geom)
             ELSE rl.geom
        END AS geom
    FROM route_line rl
)
SELECT
    ST_AsText(rs.geom_raw) AS route_wkt,
    ST_Length(rs.geom_raw::geography) / 1852.0 AS total_dist_nm,
    jsonb_agg(
        jsonb_build_object(
            'fix_name', e.waypoint_id,
            'lat', e.lat,
            'lon', e.lon,
            'fraction', ST_LineLocatePoint(
                rs.geom,
                CASE WHEN rs.shifted
                     THEN ST_ShiftLongitude(ST_SetSRID(ST_MakePoint(e.lon::float, e.lat::float), 4326))
                     ELSE ST_SetSRID(ST_MakePoint(e.lon::float, e.lat::float), 4326)
                END
            )
        ) ORDER BY e.waypoint_seq
    ) AS waypoints_json
FROM expanded e, route_shifted rs
GROUP BY rs.geom_raw, rs.geom, rs.shifted
```

#### Facility traversal call (line 299-304)

The call to `analyze_route_traversal(ST_GeomFromText(:wkt, 4326), ...)` passes the
ORIGINAL WKT (unshifted). `analyze_route_traversal()` handles its own shifting
internally. No change needed here.

### File: api/data/route-history/analysis.php

#### Route CTE (lines 67-89)

Same issue as playbook analysis Mode 2. The CTE builds geometry from `expand_route()`
via `ST_MakeLine` and computes `ST_Length(::geography)`.

This endpoint does NOT compute fractions or call `analyze_route_traversal()`. It
returns waypoints and total distance only. `ST_Length(::geography)` is correct. The
`route_wkt` is used for map visualization which already has frontend antimeridian
handling via `normalizeForIDL()`.

This endpoint does not compute fractions or call `analyze_route_traversal()`. It
returns waypoints, total distance, and `route_wkt` only. The `route_wkt` has the
antimeridian discontinuity in the raw WKT, but the frontend JS applies
`normalizeForIDL()` before rendering, so the map display is correct.

**No changes needed for route-history/analysis.php.**

## Migration Deployment

### New migration file

`database/migrations/postgis/018_antimeridian_helpers.sql`

Contents: `crosses_antimeridian()` + `normalize_lon()` + GRANT statements.

### Updated function files

These are `CREATE OR REPLACE` -- they overwrite existing functions in-place. Deploy
in order:

1. `018_antimeridian_helpers.sql` (new helpers must exist first)
2. `002_extended_functions.sql` (get_route_boundaries, analyze_tmi_route, get_route_tracons)
3. `008_trajectory_crossings.sql` (all trajectory/crossing functions)
4. `route_analysis_function.sql` (analyze_route_traversal)

PHP changes deploy via normal GitHub Actions push to main.

### Rollback

All changes are backward-compatible. Functions work identically for routes that
don't cross the antimeridian (the `v_shifted` flag is false, all conditional
expressions evaluate to the original path). Rollback by redeploying the previous
function versions.

## Testing

### Manual Verification

1. Find or create a playbook route that crosses the antimeridian
   (e.g. ANC_CARGO_ROUTES or a custom RJTT -> PANC route)
2. Run route analysis via the playbook analysis panel
3. Verify facility traversal shows only Pacific/Alaska facilities, not all of CONUS
4. Verify distances and times are reasonable for a trans-Pacific route
5. Compare against a known CONUS route to ensure no regression

### PostGIS Direct Test

```sql
-- Test: Tokyo to Anchorage (crosses antimeridian)
SELECT * FROM analyze_route_traversal(
    ST_GeomFromText('LINESTRING(139.68 35.55, 170.0 45.0, -170.0 55.0, -149.99 61.17)', 4326),
    ARRAY['ARTCC', 'FIR']
);
-- Expected: ~3-5 facilities (Japanese FIR, Russian FIR, ZAN)
-- Before fix: 50+ facilities

-- Test: JFK to LAX (does not cross antimeridian, regression check)
SELECT * FROM analyze_route_traversal(
    ST_GeomFromText('LINESTRING(-73.78 40.64, -87.90 41.97, -118.41 33.94)', 4326),
    ARRAY['ARTCC']
);
-- Expected: Same results as before (ZNY, ZOB, ZID, ZKC, ZAB, ZLA or similar)
```

### Edge Cases

- Route exactly along the antimeridian (lon = 180 or -180)
- Route crossing antimeridian multiple times (e.g. Pacific island hopping)
- Route with waypoints exactly at lon = 0 and lon = 180
- Route entirely in Eastern hemisphere (no crossing, should be unaffected)
- Route entirely in Western hemisphere (no crossing, should be unaffected)

## Files Changed Summary

| File | Action | Scope |
|------|--------|-------|
| `database/migrations/postgis/018_antimeridian_helpers.sql` | CREATE | 2 new functions |
| `database/migrations/postgis/route_analysis_function.sql` | MODIFY | `analyze_route_traversal()` |
| `scripts/postgis/008_trajectory_crossings.sql` | MODIFY | 5 functions |
| `database/migrations/postgis/002_extended_functions.sql` | MODIFY | 3 functions |
| `api/data/playbook/analysis.php` | MODIFY | Mode 1 + Mode 2 SQL CTEs |
| `api/data/route-history/analysis.php` | NONE | Already correct (geography + frontend) |
