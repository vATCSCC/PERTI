# Antimeridian-Safe Route Analysis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix false facility traversal results for routes crossing the antimeridian (+/-180 longitude) by applying `ST_ShiftLongitude` to normalize route and boundary geometries.

**Architecture:** When a route crosses the antimeridian, shift all coordinates to [0, 360] range using PostGIS `ST_ShiftLongitude()`, also shift boundary geometries before intersection, then normalize output coordinates back to [-180, 180]. Two helper functions (`crosses_antimeridian`, `normalize_lon`) centralize detection and normalization. Applied to 9 PostGIS functions + 1 PHP endpoint.

**Tech Stack:** PostgreSQL/PostGIS (PL/pgSQL), PHP 8.2

**Spec:** `docs/superpowers/specs/2026-03-29-antimeridian-route-analysis-design.md`

---

## File Map

| File | Action | What Changes |
|------|--------|--------------|
| `database/migrations/postgis/018_antimeridian_helpers.sql` | CREATE | 2 new helper functions |
| `database/migrations/postgis/route_analysis_function.sql` | MODIFY | `analyze_route_traversal()` |
| `scripts/postgis/008_trajectory_crossings.sql` | MODIFY | 4 functions: `get_trajectory_all_crossings`, `get_trajectory_artcc_crossings`, `get_trajectory_sector_crossings`, `get_artccs_traversed` |
| `database/migrations/postgis/002_extended_functions.sql` | MODIFY | 3 functions: `get_route_boundaries`, `analyze_tmi_route`, `get_route_tracons` |
| `api/data/playbook/analysis.php` | MODIFY | Mode 1 + Mode 2 SQL CTEs |

**Not changed:** `build_trajectory_line()`, `route_string_to_linestring()`, `calculate_crossing_etas()`, `calculate_crossings_batch()`, `get_boundaries_at_point()`, `get_artcc_for_airport()`, `api/data/route-history/analysis.php`

---

### Task 1: Create antimeridian helper functions

**Files:**
- Create: `database/migrations/postgis/018_antimeridian_helpers.sql`

- [ ] **Step 1: Create the migration file**

Create `database/migrations/postgis/018_antimeridian_helpers.sql` with:

```sql
-- ============================================================================
-- PostGIS Migration 018: Antimeridian Helper Functions
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
--
-- Purpose: Helper functions for detecting and handling routes that cross the
--          antimeridian (International Date Line, +/-180 longitude).
--
-- Used by: analyze_route_traversal, get_trajectory_all_crossings,
--          get_route_boundaries, and other spatial analysis functions.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- crosses_antimeridian(geometry) -> boolean
--
-- Returns TRUE if any segment of a LINESTRING has a longitude jump > 180
-- degrees between consecutive vertices, indicating an antimeridian crossing.
-- ----------------------------------------------------------------------------
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

COMMENT ON FUNCTION crosses_antimeridian IS
    'Detects if a LINESTRING crosses the antimeridian (lon jump > 180 between consecutive vertices)';

-- ----------------------------------------------------------------------------
-- normalize_lon(double precision) -> double precision
--
-- Normalizes a longitude from [0, 360] back to [-180, 180] for output.
-- Values already in [-180, 180] pass through unchanged.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION normalize_lon(p_lon double precision)
RETURNS double precision LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_lon > 180 THEN p_lon - 360.0 ELSE p_lon END;
$$;

COMMENT ON FUNCTION normalize_lon IS
    'Normalizes longitude from [0,360] back to [-180,180] for display';

-- Grants
GRANT EXECUTE ON FUNCTION crosses_antimeridian TO jpeterson;
GRANT EXECUTE ON FUNCTION normalize_lon TO jpeterson;
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/postgis/018_antimeridian_helpers.sql
git commit -m "feat(postgis): add antimeridian helper functions (migration 018)"
```

---

### Task 2: Update analyze_route_traversal()

**Files:**
- Modify: `database/migrations/postgis/route_analysis_function.sql` (lines 38-189, the `analyze_route_traversal` function body)

This is the most complex change because it has geography-based densification that must happen BEFORE the shift.

- [ ] **Step 1: Replace the function body**

In `database/migrations/postgis/route_analysis_function.sql`, replace the entire `analyze_route_traversal` function (lines 21-189) with:

```sql
CREATE OR REPLACE FUNCTION analyze_route_traversal(
    p_route_geom geometry,
    p_facility_types text[] DEFAULT ARRAY['ARTCC','FIR']
) RETURNS TABLE (
    facility_type    text,
    facility_id      text,
    facility_name    text,
    entry_fraction   double precision,
    exit_fraction    double precision,
    distance_nm      double precision,
    entry_lat        double precision,
    entry_lon        double precision,
    exit_lat         double precision,
    exit_lon         double precision,
    traversal_order  int,
    floor_altitude   int,
    ceiling_altitude int
) LANGUAGE plpgsql AS $$
DECLARE
    v_route_length_m  double precision;
    v_route_geog      geography;
    v_route           geometry;
    v_shifted         boolean;
BEGIN
    -- Validate input
    IF p_route_geom IS NULL OR ST_IsEmpty(p_route_geom) THEN
        RETURN;
    END IF;

    -- Densify route to approximate great-circle arcs (50km max segment length).
    -- This prevents Cartesian-vs-geodesic mismatch at higher latitudes where
    -- straight segments in SRID 4326 deviate from the great circle path.
    -- MUST happen before shift because geography expects [-180,180] input.
    v_route := ST_Segmentize(p_route_geom::geography, 50000)::geometry;

    -- Pre-compute route length in meters for NM conversion.
    -- Use geography BEFORE shifting (geography handles antimeridian correctly).
    v_route_geog := v_route::geography;
    v_route_length_m := ST_Length(v_route_geog);

    IF v_route_length_m < 1 THEN
        RETURN;
    END IF;

    -- Detect and handle antimeridian crossing.
    -- After densification there may still be one segment with a ~360-degree
    -- Cartesian jump at +/-180. Shifting to [0,360] removes the discontinuity.
    v_shifted := crosses_antimeridian(v_route);
    IF v_shifted THEN
        v_route := ST_ShiftLongitude(v_route);
    END IF;

    RETURN QUERY
    WITH boundary_hits AS (
        -- ARTCC/FIR boundaries (US ARTCCs use KZ__ prefix, Canadian use CZ__)
        SELECT
            CASE
                WHEN normalize_artcc_code(ab.artcc_code) ~ '^Z[A-Z]{2}$' THEN 'ARTCC'
                WHEN ab.artcc_code ~ '^CZ' THEN 'FIR'
                ELSE 'FIR'
            END AS ftype,
            normalize_artcc_code(ab.artcc_code) AS fid,
            ab.fir_name::text AS fname,
            ST_Intersection(
                v_route,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ST_MakeValid(ab.geom))
                     ELSE ST_MakeValid(ab.geom) END
            ) AS intersection_geom,
            ab.floor_altitude AS f_alt,
            ab.ceiling_altitude AS c_alt
        FROM artcc_boundaries ab
        WHERE ('ARTCC' = ANY(p_facility_types) OR 'FIR' = ANY(p_facility_types))
          AND ST_Intersects(
                v_route,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ST_MakeValid(ab.geom))
                     ELSE ST_MakeValid(ab.geom) END
              )
          AND ab.geom IS NOT NULL
          AND NOT ab.is_subsector

        UNION ALL

        -- TRACON boundaries
        SELECT
            'TRACON'::text AS ftype,
            tb.tracon_code::text AS fid,
            CASE
                WHEN tb.sector_code IS NOT NULL AND tb.sector_code <> tb.tracon_code
                THEN tb.tracon_name || ' (' || tb.tracon_code || ')'
                ELSE tb.tracon_name
            END::text AS fname,
            ST_Intersection(
                v_route,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ST_MakeValid(tb.geom))
                     ELSE ST_MakeValid(tb.geom) END
            ) AS intersection_geom,
            tb.floor_altitude AS f_alt,
            tb.ceiling_altitude AS c_alt
        FROM tracon_boundaries tb
        WHERE 'TRACON' = ANY(p_facility_types)
          AND ST_Intersects(
                v_route,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ST_MakeValid(tb.geom))
                     ELSE ST_MakeValid(tb.geom) END
              )
          AND tb.geom IS NOT NULL

        UNION ALL

        -- Sector boundaries (HIGH, LOW, SUPERHIGH)
        SELECT
            ('SECTOR_' || UPPER(sb.sector_type))::text AS ftype,
            sb.sector_code::text AS fid,
            COALESCE(sb.sector_name, sb.sector_code || ' (' || sb.parent_artcc || ')')::text AS fname,
            ST_Intersection(
                v_route,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ST_MakeValid(sb.geom))
                     ELSE ST_MakeValid(sb.geom) END
            ) AS intersection_geom,
            sb.floor_altitude AS f_alt,
            sb.ceiling_altitude AS c_alt
        FROM sector_boundaries sb
        WHERE sb.geom IS NOT NULL
          AND ST_Intersects(
                v_route,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ST_MakeValid(sb.geom))
                     ELSE ST_MakeValid(sb.geom) END
              )
          AND (
              ('SECTOR_HIGH' = ANY(p_facility_types) AND UPPER(sb.sector_type) = 'HIGH')
              OR ('SECTOR_LOW' = ANY(p_facility_types) AND UPPER(sb.sector_type) = 'LOW')
              OR ('SECTOR_SUPERHIGH' = ANY(p_facility_types) AND UPPER(sb.sector_type) = 'SUPERHIGH')
          )
    ),
    extracted AS (
        -- Extract linestring components from any geometry type (handles GeometryCollections)
        SELECT
            bh.ftype,
            bh.fid,
            bh.fname,
            bh.f_alt,
            bh.c_alt,
            (ST_Dump(ST_CollectionExtract(bh.intersection_geom, 2))).geom AS seg_geom
        FROM boundary_hits bh
        WHERE NOT ST_IsEmpty(bh.intersection_geom)
    ),
    fractions AS (
        SELECT
            s.ftype,
            s.fid,
            s.fname,
            s.f_alt,
            s.c_alt,
            ST_LineLocatePoint(v_route, ST_StartPoint(s.seg_geom)) AS entry_frac,
            ST_LineLocatePoint(v_route, ST_EndPoint(s.seg_geom)) AS exit_frac,
            ST_Length(s.seg_geom::geography) / 1852.0 AS dist_nm,
            ST_Y(ST_StartPoint(s.seg_geom)) AS e_lat,
            normalize_lon(ST_X(ST_StartPoint(s.seg_geom))) AS e_lon,
            ST_Y(ST_EndPoint(s.seg_geom)) AS x_lat,
            normalize_lon(ST_X(ST_EndPoint(s.seg_geom))) AS x_lon
        FROM extracted s
        WHERE ST_GeometryType(s.seg_geom) = 'ST_LineString'
          AND ST_NPoints(s.seg_geom) >= 2
    ),
    merged AS (
        -- Merge duplicate entries from overlapping boundary polygons (e.g. altitude layers)
        -- Altitude data is incomplete/unreliable so we merge by facility identity only
        SELECT
            f.ftype,
            f.fid,
            f.fname,
            MIN(f.f_alt) AS f_alt,
            MAX(f.c_alt) AS c_alt,
            MIN(f.entry_frac) AS entry_frac,
            MAX(f.exit_frac) AS exit_frac,
            SUM(f.dist_nm) AS dist_nm,
            (ARRAY_AGG(f.e_lat ORDER BY f.entry_frac ASC))[1] AS e_lat,
            (ARRAY_AGG(f.e_lon ORDER BY f.entry_frac ASC))[1] AS e_lon,
            (ARRAY_AGG(f.x_lat ORDER BY f.exit_frac DESC))[1] AS x_lat,
            (ARRAY_AGG(f.x_lon ORDER BY f.exit_frac DESC))[1] AS x_lon
        FROM fractions f
        WHERE f.dist_nm > 0.5
        GROUP BY f.ftype, f.fid, f.fname
    )
    SELECT
        m.ftype,
        m.fid,
        m.fname,
        m.entry_frac,
        m.exit_frac,
        ROUND(m.dist_nm::numeric, 1)::double precision,
        ROUND(m.e_lat::numeric, 6)::double precision,
        ROUND(m.e_lon::numeric, 6)::double precision,
        ROUND(m.x_lat::numeric, 6)::double precision,
        ROUND(m.x_lon::numeric, 6)::double precision,
        ROW_NUMBER() OVER (ORDER BY m.entry_frac ASC)::int AS torder,
        m.f_alt,
        m.c_alt
    FROM merged m
    ORDER BY m.entry_frac ASC;
END;
$$;
```

Key changes from original:
- Added `v_shifted boolean` variable
- After densification + length computation: `v_shifted := crosses_antimeridian(v_route); IF v_shifted THEN v_route := ST_ShiftLongitude(v_route);`
- All `ST_MakeValid(ab.geom)` / `tb.geom` / `sb.geom` wrapped in conditional `ST_ShiftLongitude`
- `normalize_lon()` applied to `e_lon` and `x_lon` in `fractions` CTE

- [ ] **Step 2: Commit**

```bash
git add database/migrations/postgis/route_analysis_function.sql
git commit -m "feat(postgis): add antimeridian handling to analyze_route_traversal"
```

---

### Task 3: Update trajectory crossing functions

**Files:**
- Modify: `scripts/postgis/008_trajectory_crossings.sql` (4 functions)

`build_trajectory_line()`, `calculate_crossing_etas()`, and `calculate_crossings_batch()` are NOT changed. Only the 4 functions that do direct boundary intersection are changed.

- [ ] **Step 1: Update get_trajectory_artcc_crossings()**

In `scripts/postgis/008_trajectory_crossings.sql`, replace the `get_trajectory_artcc_crossings` function (lines 70-138) with:

```sql
CREATE OR REPLACE FUNCTION get_trajectory_artcc_crossings(
    p_waypoints JSONB
)
RETURNS TABLE (
    artcc_code VARCHAR(4),
    artcc_name VARCHAR(64),
    is_oceanic BOOLEAN,
    crossing_lat DECIMAL(10,6),
    crossing_lon DECIMAL(11,6),
    crossing_fraction FLOAT,
    distance_nm FLOAT,
    crossing_type VARCHAR(5)  -- 'ENTRY' or 'EXIT'
) AS $$
DECLARE
    trajectory GEOMETRY;
    total_length_m FLOAT;
    v_shifted BOOLEAN;
BEGIN
    -- Build trajectory line
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN;
    END IF;

    -- Get total length BEFORE shifting (geography handles antimeridian correctly)
    total_length_m := ST_Length(trajectory::geography);

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(trajectory);
    IF v_shifted THEN
        trajectory := ST_ShiftLongitude(trajectory);
    END IF;

    RETURN QUERY
    WITH crossings AS (
        -- Find all intersection points with ARTCC boundaries
        SELECT
            ab.artcc_code,
            ab.fir_name AS artcc_name,
            COALESCE(ab.is_oceanic, FALSE) AS is_oceanic,
            (ST_Dump(ST_Intersection(
                trajectory,
                ST_Boundary(CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END)
            ))).geom AS crossing_point
        FROM artcc_boundaries ab
        WHERE ST_Intersects(
            trajectory,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
        )
    ),
    crossing_details AS (
        SELECT
            c.artcc_code,
            c.artcc_name,
            c.is_oceanic,
            ST_Y(c.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            normalize_lon(ST_X(c.crossing_point))::DECIMAL(11,6) AS crossing_lon,
            ST_LineLocatePoint(trajectory, c.crossing_point) AS crossing_fraction
        FROM crossings c
        WHERE ST_GeometryType(c.crossing_point) = 'ST_Point'
    )
    SELECT
        cd.artcc_code,
        cd.artcc_name,
        cd.is_oceanic,
        cd.crossing_lat,
        cd.crossing_lon,
        cd.crossing_fraction,
        (cd.crossing_fraction * total_length_m / 1852.0)::FLOAT AS distance_nm,
        -- Determine if entry or exit based on containment before/after crossing
        CASE
            WHEN ST_Contains(
                (SELECT CASE WHEN v_shifted THEN ST_ShiftLongitude(ab2.geom) ELSE ab2.geom END
                 FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.artcc_code LIMIT 1),
                ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
            ) THEN 'ENTRY'::VARCHAR(5)
            ELSE 'EXIT'::VARCHAR(5)
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 2: Update get_trajectory_sector_crossings()**

Replace the `get_trajectory_sector_crossings` function (lines 148-218) with:

```sql
CREATE OR REPLACE FUNCTION get_trajectory_sector_crossings(
    p_waypoints JSONB,
    p_sector_type VARCHAR(16) DEFAULT NULL  -- 'LOW', 'HIGH', 'SUPERHIGH', or NULL for all
)
RETURNS TABLE (
    sector_code VARCHAR(16),
    sector_name VARCHAR(64),
    sector_type VARCHAR(16),
    parent_artcc VARCHAR(4),
    crossing_lat DECIMAL(10,6),
    crossing_lon DECIMAL(11,6),
    crossing_fraction FLOAT,
    distance_nm FLOAT,
    crossing_type VARCHAR(5)
) AS $$
DECLARE
    trajectory GEOMETRY;
    total_length_m FLOAT;
    v_shifted BOOLEAN;
BEGIN
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN;
    END IF;

    -- Get total length BEFORE shifting
    total_length_m := ST_Length(trajectory::geography);

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(trajectory);
    IF v_shifted THEN
        trajectory := ST_ShiftLongitude(trajectory);
    END IF;

    RETURN QUERY
    WITH crossings AS (
        SELECT
            sb.sector_code,
            sb.sector_name,
            sb.sector_type,
            sb.parent_artcc,
            (ST_Dump(ST_Intersection(
                trajectory,
                ST_Boundary(CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END)
            ))).geom AS crossing_point
        FROM (SELECT sector_code, sector_name, sector_type, parent_artcc, geom FROM sector_boundaries WHERE ST_IsValid(geom)) sb
        WHERE ST_Intersects(
            trajectory,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
        )
          AND (p_sector_type IS NULL OR sb.sector_type = p_sector_type)
    ),
    crossing_details AS (
        SELECT
            c.sector_code,
            c.sector_name,
            c.sector_type,
            c.parent_artcc,
            ST_Y(c.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            normalize_lon(ST_X(c.crossing_point))::DECIMAL(11,6) AS crossing_lon,
            ST_LineLocatePoint(trajectory, c.crossing_point) AS crossing_fraction
        FROM crossings c
        WHERE ST_GeometryType(c.crossing_point) = 'ST_Point'
    )
    SELECT
        cd.sector_code,
        cd.sector_name,
        cd.sector_type,
        cd.parent_artcc,
        cd.crossing_lat,
        cd.crossing_lon,
        cd.crossing_fraction,
        (cd.crossing_fraction * total_length_m / 1852.0)::FLOAT AS distance_nm,
        CASE
            WHEN ST_Contains(
                (SELECT CASE WHEN v_shifted THEN ST_ShiftLongitude(sb2.geom) ELSE sb2.geom END
                 FROM sector_boundaries sb2 WHERE sb2.sector_code = cd.sector_code LIMIT 1),
                ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
            ) THEN 'ENTRY'::VARCHAR(5)
            ELSE 'EXIT'::VARCHAR(5)
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 3: Update get_trajectory_all_crossings()**

Replace the `get_trajectory_all_crossings` function (lines 228-318) with:

```sql
CREATE OR REPLACE FUNCTION get_trajectory_all_crossings(
    p_waypoints JSONB
)
RETURNS TABLE (
    boundary_type VARCHAR(16),
    boundary_code VARCHAR(16),
    boundary_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    crossing_lat DECIMAL(10,6),
    crossing_lon DECIMAL(11,6),
    crossing_fraction FLOAT,
    distance_nm FLOAT,
    crossing_type VARCHAR(5)
) AS $$
DECLARE
    trajectory GEOMETRY;
    total_length_m FLOAT;
    v_shifted BOOLEAN;
BEGIN
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN;
    END IF;

    -- Get total length BEFORE shifting (geography handles antimeridian correctly)
    total_length_m := ST_Length(trajectory::geography);

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(trajectory);
    IF v_shifted THEN
        trajectory := ST_ShiftLongitude(trajectory);
    END IF;

    RETURN QUERY
    -- ARTCC crossings
    WITH artcc_crossings AS (
        SELECT
            'ARTCC'::VARCHAR(16) AS boundary_type,
            ab.artcc_code::VARCHAR(16) AS boundary_code,
            ab.fir_name AS boundary_name,
            ab.artcc_code AS parent_artcc,
            (ST_Dump(ST_Intersection(
                trajectory,
                ST_Boundary(CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END)
            ))).geom AS crossing_point
        FROM artcc_boundaries ab
        WHERE ST_Intersects(
            trajectory,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
        )
    ),
    sector_crossings AS (
        SELECT
            sb.sector_type::VARCHAR(16) AS boundary_type,
            sb.sector_code::VARCHAR(16) AS boundary_code,
            sb.sector_name AS boundary_name,
            sb.parent_artcc,
            (ST_Dump(ST_Intersection(
                trajectory,
                ST_Boundary(CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END)
            ))).geom AS crossing_point
        FROM (SELECT sector_code, sector_type, sector_name, parent_artcc, geom FROM sector_boundaries WHERE ST_IsValid(geom)) sb
        WHERE ST_Intersects(
            trajectory,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
        )
    ),
    all_crossings AS (
        SELECT * FROM artcc_crossings
        UNION ALL
        SELECT * FROM sector_crossings
    ),
    crossing_details AS (
        SELECT
            ac.boundary_type,
            ac.boundary_code,
            ac.boundary_name,
            ac.parent_artcc,
            ST_Y(ac.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            normalize_lon(ST_X(ac.crossing_point))::DECIMAL(11,6) AS crossing_lon,
            ST_LineLocatePoint(trajectory, ac.crossing_point) AS crossing_fraction
        FROM all_crossings ac
        WHERE ST_GeometryType(ac.crossing_point) = 'ST_Point'
    )
    SELECT
        cd.boundary_type,
        cd.boundary_code,
        cd.boundary_name,
        cd.parent_artcc,
        cd.crossing_lat,
        cd.crossing_lon,
        cd.crossing_fraction,
        (cd.crossing_fraction * total_length_m / 1852.0)::FLOAT AS distance_nm,
        -- Entry/exit determination
        CASE
            WHEN cd.boundary_type = 'ARTCC' THEN
                CASE WHEN ST_Contains(
                    (SELECT CASE WHEN v_shifted THEN ST_ShiftLongitude(ab2.geom) ELSE ab2.geom END
                     FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.boundary_code::VARCHAR(4) LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
            ELSE
                CASE WHEN ST_Contains(
                    (SELECT CASE WHEN v_shifted THEN ST_ShiftLongitude(sb2.geom) ELSE sb2.geom END
                     FROM sector_boundaries sb2 WHERE sb2.sector_code = cd.boundary_code LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 4: Update get_artccs_traversed()**

Replace the `get_artccs_traversed` function (lines 439-473) with:

```sql
CREATE OR REPLACE FUNCTION get_artccs_traversed(
    p_waypoints JSONB
)
RETURNS TEXT[] AS $$
DECLARE
    trajectory GEOMETRY;
    artccs TEXT[];
    v_shifted BOOLEAN;
BEGIN
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN ARRAY[]::TEXT[];
    END IF;

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(trajectory);
    IF v_shifted THEN
        trajectory := ST_ShiftLongitude(trajectory);
    END IF;

    -- Get unique ARTCCs in first-crossing order using ST_Boundary for precise points
    SELECT array_agg(sub.artcc_code ORDER BY sub.first_crossing)
    INTO artccs
    FROM (
        SELECT
            ab.artcc_code,
            MIN(ST_LineLocatePoint(trajectory, crossing_point.geom)) AS first_crossing
        FROM artcc_boundaries ab
        CROSS JOIN LATERAL (
            SELECT (ST_Dump(ST_Intersection(
                trajectory,
                ST_Boundary(CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END)
            ))).geom
        ) AS crossing_point
        WHERE ST_Intersects(
            trajectory,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
        )
          AND NOT COALESCE(ab.is_oceanic, FALSE)
          AND ST_GeometryType(crossing_point.geom) = 'ST_Point'
        GROUP BY ab.artcc_code
    ) sub;

    RETURN COALESCE(artccs, ARRAY[]::TEXT[]);
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 5: Commit**

```bash
git add scripts/postgis/008_trajectory_crossings.sql
git commit -m "feat(postgis): add antimeridian handling to trajectory crossing functions"
```

---

### Task 4: Update extended boundary functions

**Files:**
- Modify: `database/migrations/postgis/002_extended_functions.sql` (3 functions)

- [ ] **Step 1: Update get_route_boundaries()**

Replace the `get_route_boundaries` function (lines 26-181) with:

```sql
CREATE OR REPLACE FUNCTION get_route_boundaries(
    waypoints JSONB,
    cruise_altitude INT DEFAULT 35000,
    include_sectors BOOLEAN DEFAULT TRUE
)
RETURNS TABLE (
    boundary_type VARCHAR(20),
    boundary_code VARCHAR(50),
    boundary_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    floor_altitude INT,
    ceiling_altitude INT,
    traversal_order FLOAT,
    entry_point JSONB,
    exit_point JSONB
) AS $$
DECLARE
    route_geom GEOMETRY;
    intersection_geom GEOMETRY;
    v_shifted BOOLEAN;
BEGIN
    -- Build LineString from waypoints array
    SELECT ST_MakeLine(
        array_agg(
            ST_SetSRID(ST_MakePoint(
                (wp->>'lon')::float,
                (wp->>'lat')::float
            ), 4326)
            ORDER BY ordinality
        )
    )
    INTO route_geom
    FROM jsonb_array_elements(waypoints) WITH ORDINALITY AS t(wp, ordinality);

    -- Return empty if no valid route
    IF route_geom IS NULL OR ST_NumPoints(route_geom) < 2 THEN
        RETURN;
    END IF;

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(route_geom);
    IF v_shifted THEN
        route_geom := ST_ShiftLongitude(route_geom);
    END IF;

    -- ARTCCs (always included)
    RETURN QUERY
    SELECT
        'ARTCC'::VARCHAR(20) AS boundary_type,
        ab.artcc_code::VARCHAR(50) AS boundary_code,
        ab.fir_name::VARCHAR(64) AS boundary_name,
        ab.artcc_code::VARCHAR(4) AS parent_artcc,
        ab.floor_altitude,
        ab.ceiling_altitude,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(
            route_geom,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
        )))::FLOAT AS traversal_order,
        jsonb_build_object(
            'lon', normalize_lon(ST_X(ST_StartPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
            )))),
            'lat', ST_Y(ST_StartPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
            )))
        ) AS entry_point,
        jsonb_build_object(
            'lon', normalize_lon(ST_X(ST_EndPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
            )))),
            'lat', ST_Y(ST_EndPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
            )))
        ) AS exit_point
    FROM artcc_boundaries ab
    WHERE ST_Intersects(
        route_geom,
        CASE WHEN v_shifted THEN ST_ShiftLongitude(ab.geom) ELSE ab.geom END
    );

    -- TRACONs (for departure/arrival, typically below FL180)
    RETURN QUERY
    SELECT
        'TRACON'::VARCHAR(20) AS boundary_type,
        tb.tracon_code::VARCHAR(50) AS boundary_code,
        tb.tracon_name::VARCHAR(64) AS boundary_name,
        tb.parent_artcc::VARCHAR(4) AS parent_artcc,
        tb.floor_altitude,
        tb.ceiling_altitude,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(
            route_geom,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
        )))::FLOAT AS traversal_order,
        jsonb_build_object(
            'lon', normalize_lon(ST_X(ST_StartPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
            )))),
            'lat', ST_Y(ST_StartPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
            )))
        ) AS entry_point,
        jsonb_build_object(
            'lon', normalize_lon(ST_X(ST_EndPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
            )))),
            'lat', ST_Y(ST_EndPoint(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
            )))
        ) AS exit_point
    FROM tracon_boundaries tb
    WHERE ST_Intersects(
        route_geom,
        CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
    );

    -- Sectors (if requested)
    IF include_sectors THEN
        -- LOW sectors (typically surface to FL240)
        RETURN QUERY
        SELECT
            'SECTOR_LOW'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
            )))::FLOAT AS traversal_order,
            jsonb_build_object(
                'lon', normalize_lon(ST_X(ST_StartPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))),
                'lat', ST_Y(ST_StartPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))
            ) AS entry_point,
            jsonb_build_object(
                'lon', normalize_lon(ST_X(ST_EndPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))),
                'lat', ST_Y(ST_EndPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))
            ) AS exit_point
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'LOW'
          AND ST_Intersects(
              route_geom,
              CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
          )
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= 0);

        -- HIGH sectors (typically FL240 to FL600)
        RETURN QUERY
        SELECT
            'SECTOR_HIGH'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
            )))::FLOAT AS traversal_order,
            jsonb_build_object(
                'lon', normalize_lon(ST_X(ST_StartPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))),
                'lat', ST_Y(ST_StartPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))
            ) AS entry_point,
            jsonb_build_object(
                'lon', normalize_lon(ST_X(ST_EndPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))),
                'lat', ST_Y(ST_EndPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))
            ) AS exit_point
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'HIGH'
          AND ST_Intersects(
              route_geom,
              CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
          )
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= cruise_altitude);

        -- SUPERHIGH sectors (typically FL350+)
        RETURN QUERY
        SELECT
            'SECTOR_SUPERHIGH'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(
                route_geom,
                CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
            )))::FLOAT AS traversal_order,
            jsonb_build_object(
                'lon', normalize_lon(ST_X(ST_StartPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))),
                'lat', ST_Y(ST_StartPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))
            ) AS entry_point,
            jsonb_build_object(
                'lon', normalize_lon(ST_X(ST_EndPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))),
                'lat', ST_Y(ST_EndPoint(ST_Intersection(
                    route_geom,
                    CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
                )))
            ) AS exit_point
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'SUPERHIGH'
          AND ST_Intersects(
              route_geom,
              CASE WHEN v_shifted THEN ST_ShiftLongitude(sb.geom) ELSE sb.geom END
          )
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= cruise_altitude);
    END IF;
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 2: Update analyze_tmi_route()**

Replace the `analyze_tmi_route` function (lines 306-440). The changes are:
1. After building `route_geom` (the END of the geometry-building section, around line 372), add detection + shift
2. In the 3 `ST_Intersects` calls (lines 383, 389, 400), wrap `geom` conditionally

Replace lines 306-440 with:

```sql
CREATE OR REPLACE FUNCTION analyze_tmi_route(
    p_route_geojson JSONB,
    p_origin_icao VARCHAR(4) DEFAULT NULL,
    p_dest_icao VARCHAR(4) DEFAULT NULL,
    p_cruise_altitude INT DEFAULT 35000
)
RETURNS TABLE (
    facilities_traversed TEXT[],
    artccs_traversed TEXT[],
    tracons_traversed TEXT[],
    sectors_traversed JSONB,
    origin_artcc VARCHAR(4),
    dest_artcc VARCHAR(4)
) AS $$
DECLARE
    route_geom GEOMETRY;
    v_artccs TEXT[] := '{}';
    v_tracons TEXT[] := '{}';
    v_sectors JSONB := '[]'::jsonb;
    v_origin_artcc VARCHAR(4);
    v_dest_artcc VARCHAR(4);
    v_shifted BOOLEAN;
BEGIN
    -- Parse route geometry from GeoJSON
    IF p_route_geojson IS NULL THEN
        RETURN QUERY SELECT '{}'::TEXT[], '{}'::TEXT[], '{}'::TEXT[], '[]'::JSONB, NULL::VARCHAR(4), NULL::VARCHAR(4);
        RETURN;
    END IF;

    -- Try to extract geometry
    BEGIN
        IF p_route_geojson ? 'coordinates' AND p_route_geojson->>'type' = 'LineString' THEN
            route_geom := ST_SetSRID(ST_GeomFromGeoJSON(p_route_geojson::text), 4326);
        ELSIF p_route_geojson ? 'geometry' THEN
            route_geom := ST_SetSRID(ST_GeomFromGeoJSON((p_route_geojson->'geometry')::text), 4326);
        ELSIF jsonb_typeof(p_route_geojson) = 'array' THEN
            SELECT ST_MakeLine(
                array_agg(
                    ST_SetSRID(ST_MakePoint(
                        (coord->0)::float,
                        (coord->1)::float
                    ), 4326)
                    ORDER BY ordinality
                )
            )
            INTO route_geom
            FROM jsonb_array_elements(p_route_geojson) WITH ORDINALITY AS t(coord, ordinality);
        ELSE
            SELECT ST_MakeLine(
                array_agg(
                    ST_SetSRID(ST_MakePoint(
                        (wp->>'lon')::float,
                        (wp->>'lat')::float
                    ), 4326)
                    ORDER BY ordinality
                )
            )
            INTO route_geom
            FROM jsonb_array_elements(p_route_geojson) WITH ORDINALITY AS t(wp, ordinality);
        END IF;
    EXCEPTION WHEN OTHERS THEN
        route_geom := NULL;
    END;

    IF route_geom IS NULL OR ST_IsEmpty(route_geom) THEN
        RETURN QUERY SELECT '{}'::TEXT[], '{}'::TEXT[], '{}'::TEXT[], '[]'::JSONB, NULL::VARCHAR(4), NULL::VARCHAR(4);
        RETURN;
    END IF;

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(route_geom);
    IF v_shifted THEN
        route_geom := ST_ShiftLongitude(route_geom);
    END IF;

    -- Get ARTCCs traversed
    SELECT array_agg(DISTINCT artcc_code ORDER BY artcc_code)
    INTO v_artccs
    FROM artcc_boundaries
    WHERE ST_Intersects(
        CASE WHEN v_shifted THEN ST_ShiftLongitude(geom) ELSE geom END,
        route_geom
    );

    -- Get TRACONs traversed
    SELECT array_agg(DISTINCT tracon_code ORDER BY tracon_code)
    INTO v_tracons
    FROM tracon_boundaries
    WHERE ST_Intersects(
        CASE WHEN v_shifted THEN ST_ShiftLongitude(geom) ELSE geom END,
        route_geom
    );

    -- Get sectors traversed at cruise altitude
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'code', sector_code,
        'name', sector_name,
        'type', sector_type,
        'artcc', parent_artcc
    )), '[]'::jsonb)
    INTO v_sectors
    FROM sector_boundaries
    WHERE ST_Intersects(
        CASE WHEN v_shifted THEN ST_ShiftLongitude(geom) ELSE geom END,
        route_geom
    )
      AND (floor_altitude IS NULL OR floor_altitude <= p_cruise_altitude)
      AND (ceiling_altitude IS NULL OR ceiling_altitude >= p_cruise_altitude);

    -- Get origin airport's ARTCC (point lookup, no antimeridian issue)
    IF p_origin_icao IS NOT NULL THEN
        BEGIN
            SELECT ab.artcc_code INTO v_origin_artcc
            FROM airports apt
            JOIN artcc_boundaries ab ON ST_Contains(ab.geom, apt.geom)
            WHERE apt.icao_id = p_origin_icao
            ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
            LIMIT 1;
        EXCEPTION WHEN undefined_table THEN
            v_origin_artcc := NULL;
        END;
    END IF;

    -- Get destination airport's ARTCC
    IF p_dest_icao IS NOT NULL THEN
        BEGIN
            SELECT ab.artcc_code INTO v_dest_artcc
            FROM airports apt
            JOIN artcc_boundaries ab ON ST_Contains(ab.geom, apt.geom)
            WHERE apt.icao_id = p_dest_icao
            ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
            LIMIT 1;
        EXCEPTION WHEN undefined_table THEN
            v_dest_artcc := NULL;
        END;
    END IF;

    RETURN QUERY SELECT
        COALESCE(v_artccs, '{}'),
        COALESCE(v_artccs, '{}'),
        COALESCE(v_tracons, '{}'),
        COALESCE(v_sectors, '[]'::jsonb),
        v_origin_artcc,
        v_dest_artcc;
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 3: Update get_route_tracons()**

Replace the `get_route_tracons` function (lines 449-488) with:

```sql
CREATE OR REPLACE FUNCTION get_route_tracons(
    waypoints JSONB
)
RETURNS TABLE (
    tracon_code VARCHAR(16),
    tracon_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    traversal_order FLOAT
) AS $$
DECLARE
    route_geom GEOMETRY;
    v_shifted BOOLEAN;
BEGIN
    -- Build LineString from waypoints
    SELECT ST_MakeLine(
        array_agg(
            ST_SetSRID(ST_MakePoint(
                (wp->>'lon')::float,
                (wp->>'lat')::float
            ), 4326)
            ORDER BY ordinality
        )
    )
    INTO route_geom
    FROM jsonb_array_elements(waypoints) WITH ORDINALITY AS t(wp, ordinality);

    IF route_geom IS NULL OR ST_NumPoints(route_geom) < 2 THEN
        RETURN;
    END IF;

    -- Detect and handle antimeridian crossing
    v_shifted := crosses_antimeridian(route_geom);
    IF v_shifted THEN
        route_geom := ST_ShiftLongitude(route_geom);
    END IF;

    RETURN QUERY
    SELECT
        tb.tracon_code,
        tb.tracon_name,
        tb.parent_artcc,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(
            route_geom,
            CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
        )))::FLOAT AS traversal_order
    FROM tracon_boundaries tb
    WHERE ST_Intersects(
        route_geom,
        CASE WHEN v_shifted THEN ST_ShiftLongitude(tb.geom) ELSE tb.geom END
    )
    ORDER BY traversal_order;
END;
$$ LANGUAGE plpgsql STABLE;
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/postgis/002_extended_functions.sql
git commit -m "feat(postgis): add antimeridian handling to extended boundary functions"
```

---

### Task 5: Update playbook analysis PHP endpoint

**Files:**
- Modify: `api/data/playbook/analysis.php` (lines 193-216 for Mode 1, lines 253-277 for Mode 2)

- [ ] **Step 1: Update Mode 1 SQL (client waypoints)**

In `api/data/playbook/analysis.php`, replace the `$dist_sql` string (lines 193-216) with:

```php
        $dist_sql = "WITH route AS (
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
                     GROUP BY rs.geom_raw, rs.geom, rs.shifted";
```

- [ ] **Step 2: Update Mode 2 SQL (expand_route)**

In the same file, replace the `$er_sql` string (lines 253-277) with:

```php
        $er_sql = "WITH expanded AS (
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
                   GROUP BY rs.geom_raw, rs.geom, rs.shifted";
```

- [ ] **Step 3: Commit**

```bash
git add api/data/playbook/analysis.php
git commit -m "feat(analysis): add antimeridian handling to playbook route analysis"
```

---

### Task 6: Deploy to PostGIS and verify

**Files:** None (deployment + testing)

Deploy SQL migrations in order to the VATSIM_GIS database. Use the PostGIS connection credentials from `.claude/credentials.md`.

- [ ] **Step 1: Deploy helper functions**

Run `database/migrations/postgis/018_antimeridian_helpers.sql` against VATSIM_GIS.

```bash
psql "host=vatcscc-gis.postgres.database.azure.com port=5432 dbname=VATSIM_GIS user=jpeterson sslmode=require" \
  -f database/migrations/postgis/018_antimeridian_helpers.sql
```

- [ ] **Step 2: Deploy updated functions**

Run these in order:

```bash
psql "host=vatcscc-gis.postgres.database.azure.com port=5432 dbname=VATSIM_GIS user=jpeterson sslmode=require" \
  -f database/migrations/postgis/002_extended_functions.sql

psql "host=vatcscc-gis.postgres.database.azure.com port=5432 dbname=VATSIM_GIS user=jpeterson sslmode=require" \
  -f scripts/postgis/008_trajectory_crossings.sql

psql "host=vatcscc-gis.postgres.database.azure.com port=5432 dbname=VATSIM_GIS user=jpeterson sslmode=require" \
  -f database/migrations/postgis/route_analysis_function.sql
```

- [ ] **Step 3: Verify helper functions work**

```sql
-- Should return TRUE (crosses antimeridian)
SELECT crosses_antimeridian(
    ST_GeomFromText('LINESTRING(170 50, -170 50)', 4326)
);

-- Should return FALSE (CONUS route)
SELECT crosses_antimeridian(
    ST_GeomFromText('LINESTRING(-73.78 40.64, -118.41 33.94)', 4326)
);

-- Should return -170.0
SELECT normalize_lon(190.0);

-- Should return -73.78 (no change)
SELECT normalize_lon(-73.78);
```

- [ ] **Step 4: Verify analyze_route_traversal with antimeridian route**

```sql
-- Tokyo to Anchorage (crosses antimeridian)
-- Expected: ~3-5 facilities (FIRs + ZAN), NOT 50+
SELECT facility_type, facility_id, facility_name,
       ROUND(distance_nm::numeric, 0) AS dist_nm,
       entry_lon, exit_lon
FROM analyze_route_traversal(
    ST_GeomFromText('LINESTRING(139.68 35.55, 170.0 45.0, -170.0 55.0, -149.99 61.17)', 4326),
    ARRAY['ARTCC', 'FIR']
);
```

- [ ] **Step 5: Verify CONUS regression**

```sql
-- JFK to LAX (no antimeridian crossing, regression check)
-- Expected: Same facilities as before (ZNY, ZOB, ZID, ZKC, ZAB, ZLA or similar)
SELECT facility_type, facility_id, facility_name,
       ROUND(distance_nm::numeric, 0) AS dist_nm
FROM analyze_route_traversal(
    ST_GeomFromText('LINESTRING(-73.78 40.64, -87.90 41.97, -118.41 33.94)', 4326),
    ARRAY['ARTCC']
);
```

- [ ] **Step 6: Verify crossing daemon functions**

```sql
-- Test get_trajectory_all_crossings with Pacific waypoints
SELECT boundary_type, boundary_code, boundary_name,
       crossing_lat, crossing_lon, distance_nm, crossing_type
FROM get_trajectory_all_crossings(
    '[{"lat":35.55,"lon":139.68,"sequence_num":1},
      {"lat":45.0,"lon":170.0,"sequence_num":2},
      {"lat":55.0,"lon":-170.0,"sequence_num":3},
      {"lat":61.17,"lon":-149.99,"sequence_num":4}]'::jsonb
);
-- Expected: Small number of crossings, all with valid lon in [-180,180]
```

- [ ] **Step 7: Push PHP changes to deploy**

The PHP changes deploy via GitHub Actions on push to main. Push the branch or merge to main to deploy `analysis.php`.

- [ ] **Step 8: Test via playbook UI**

1. Open playbook on production: `https://perti.vatcscc.org/playbook.php`
2. Find or create a route crossing the antimeridian (search for ANC_CARGO or create a custom RJTT-PANC route)
3. Click "Analyze" on the route
4. Verify the facility traversal panel shows only relevant Pacific/Alaska facilities
5. Verify distances and times look reasonable
6. Test a CONUS route (e.g. JFK-LAX) to confirm no regression
