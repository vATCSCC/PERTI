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

-- Grant execute to app users (GIS_admin owns; jpeterson for admin access)
GRANT EXECUTE ON FUNCTION analyze_route_traversal TO jpeterson;
GRANT EXECUTE ON FUNCTION route_string_to_linestring TO jpeterson;
