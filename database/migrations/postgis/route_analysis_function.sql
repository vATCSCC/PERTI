-- ============================================================================
-- PostGIS Migration: Route Analysis Function
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
--
-- Purpose: Analyze route geometry against facility boundaries to produce
--          ordered facility traversal with distances and fractional positions.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Function: analyze_route_traversal
--
-- Given a route LINESTRING, intersects with facility boundaries and returns
-- ordered traversal segments with entry/exit fractions and distances.
--
-- Parameters:
--   p_route_geom    - Route as LINESTRING (SRID 4326)
--   p_facility_types - Array of types to check: 'ARTCC','FIR','TRACON','SECTOR_HIGH','SECTOR_LOW','SECTOR_SUPERHIGH'
--
-- Returns table of traversal segments ordered by entry position along route.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION analyze_route_traversal(
    p_route_geom geometry,
    p_facility_types text[] DEFAULT ARRAY['ARTCC','FIR']
) RETURNS TABLE (
    facility_type   text,
    facility_id     text,
    facility_name   text,
    entry_fraction  double precision,
    exit_fraction   double precision,
    distance_nm     double precision,
    entry_lat       double precision,
    entry_lon       double precision,
    exit_lat        double precision,
    exit_lon        double precision,
    traversal_order int
) LANGUAGE plpgsql AS $$
DECLARE
    v_route_length_m  double precision;
    v_route_geog      geography;
BEGIN
    -- Validate input
    IF p_route_geom IS NULL OR ST_IsEmpty(p_route_geom) THEN
        RETURN;
    END IF;

    -- Pre-compute route length in meters for NM conversion
    v_route_geog := p_route_geom::geography;
    v_route_length_m := ST_Length(v_route_geog);

    IF v_route_length_m < 1 THEN
        RETURN;
    END IF;

    RETURN QUERY
    WITH boundary_hits AS (
        -- ARTCC/FIR boundaries (US ARTCCs have Z__ codes like ZDC, ZNY)
        SELECT
            CASE
                WHEN ab.artcc_code ~ '^Z[A-Z]{2}$' THEN 'ARTCC'
                ELSE 'FIR'
            END AS ftype,
            ab.artcc_code::text AS fid,
            ab.fir_name::text AS fname,
            ST_Intersection(p_route_geom, ab.geom) AS intersection_geom
        FROM artcc_boundaries ab
        WHERE ('ARTCC' = ANY(p_facility_types) OR 'FIR' = ANY(p_facility_types))
          AND ST_Intersects(p_route_geom, ab.geom)
          AND ab.geom IS NOT NULL

        UNION ALL

        -- TRACON boundaries
        SELECT
            'TRACON'::text AS ftype,
            tb.tracon_code::text AS fid,
            tb.tracon_name::text AS fname,
            ST_Intersection(p_route_geom, tb.geom) AS intersection_geom
        FROM tracon_boundaries tb
        WHERE 'TRACON' = ANY(p_facility_types)
          AND ST_Intersects(p_route_geom, tb.geom)
          AND tb.geom IS NOT NULL
    ),
    segments AS (
        -- Extract line segments from intersections (multi-line results get dumped)
        SELECT
            bh.ftype,
            bh.fid,
            bh.fname,
            (ST_Dump(bh.intersection_geom)).geom AS seg_geom
        FROM boundary_hits bh
        WHERE NOT ST_IsEmpty(bh.intersection_geom)
          AND ST_GeometryType(bh.intersection_geom) IN ('ST_LineString','ST_MultiLineString')
    ),
    fractions AS (
        SELECT
            s.ftype,
            s.fid,
            s.fname,
            ST_LineLocatePoint(p_route_geom, ST_StartPoint(s.seg_geom)) AS entry_frac,
            ST_LineLocatePoint(p_route_geom, ST_EndPoint(s.seg_geom)) AS exit_frac,
            ST_Length(s.seg_geom::geography) / 1852.0 AS dist_nm,
            ST_Y(ST_StartPoint(s.seg_geom)) AS e_lat,
            ST_X(ST_StartPoint(s.seg_geom)) AS e_lon,
            ST_Y(ST_EndPoint(s.seg_geom)) AS x_lat,
            ST_X(ST_EndPoint(s.seg_geom)) AS x_lon
        FROM segments s
        WHERE ST_NPoints(s.seg_geom) >= 2
    )
    SELECT
        f.ftype,
        f.fid,
        f.fname,
        f.entry_frac,
        f.exit_frac,
        ROUND(f.dist_nm::numeric, 1)::double precision,
        ROUND(f.e_lat::numeric, 6)::double precision,
        ROUND(f.e_lon::numeric, 6)::double precision,
        ROUND(f.x_lat::numeric, 6)::double precision,
        ROUND(f.x_lon::numeric, 6)::double precision,
        ROW_NUMBER() OVER (ORDER BY f.entry_frac ASC)::int AS torder
    FROM fractions f
    WHERE f.dist_nm > 0.5  -- Filter out trivial boundary grazes
    ORDER BY f.entry_frac ASC;
END;
$$;

-- ----------------------------------------------------------------------------
-- Function: route_string_to_linestring
--
-- Resolves a space-delimited route string to a LINESTRING by looking up
-- each fix in nav_fixes. Unknown fixes are skipped.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION route_string_to_linestring(
    p_route_string text,
    p_origin_icao text DEFAULT NULL,
    p_dest_icao text DEFAULT NULL
) RETURNS geometry LANGUAGE plpgsql AS $$
DECLARE
    v_fixes text[];
    v_points geometry[];
    v_fix text;
    v_pt geometry;
    v_result geometry;
BEGIN
    -- Split route string on whitespace
    v_fixes := regexp_split_to_array(TRIM(p_route_string), '\s+');

    -- Optionally prepend origin airport
    IF p_origin_icao IS NOT NULL THEN
        SELECT geom INTO v_pt FROM airports WHERE icao_id = UPPER(p_origin_icao) LIMIT 1;
        IF v_pt IS NOT NULL THEN
            v_points := ARRAY[v_pt];
        ELSE
            v_points := ARRAY[]::geometry[];
        END IF;
    ELSE
        v_points := ARRAY[]::geometry[];
    END IF;

    -- Look up each fix
    FOREACH v_fix IN ARRAY v_fixes
    LOOP
        v_fix := UPPER(TRIM(v_fix));

        -- Skip airways, SIDs, STARs, DCT, and empty tokens
        IF v_fix = '' OR v_fix = 'DCT' OR v_fix = 'DIRECT'
           OR v_fix ~ '^[JQV]\d+$'        -- J/Q/V airways
           OR v_fix ~ '^[A-Z]{2}\d+$'     -- 2-letter airways
           OR LENGTH(v_fix) < 2
        THEN
            CONTINUE;
        END IF;

        -- Try nav_fixes first
        SELECT geom INTO v_pt FROM nav_fixes WHERE fix_name = v_fix LIMIT 1;

        -- Try airports if not found in fixes
        IF v_pt IS NULL THEN
            SELECT geom INTO v_pt FROM airports WHERE icao_id = v_fix LIMIT 1;
        END IF;

        IF v_pt IS NOT NULL THEN
            v_points := array_append(v_points, v_pt);
        END IF;
    END LOOP;

    -- Optionally append destination airport
    IF p_dest_icao IS NOT NULL THEN
        SELECT geom INTO v_pt FROM airports WHERE icao_id = UPPER(p_dest_icao) LIMIT 1;
        IF v_pt IS NOT NULL THEN
            v_points := array_append(v_points, v_pt);
        END IF;
    END IF;

    -- Need at least 2 points
    IF array_length(v_points, 1) IS NULL OR array_length(v_points, 1) < 2 THEN
        RETURN NULL;
    END IF;

    v_result := ST_SetSRID(ST_MakeLine(v_points), 4326);
    RETURN v_result;
END;
$$;

-- Grant execute to app user
GRANT EXECUTE ON FUNCTION analyze_route_traversal TO adl_api_user;
GRANT EXECUTE ON FUNCTION route_string_to_linestring TO adl_api_user;
