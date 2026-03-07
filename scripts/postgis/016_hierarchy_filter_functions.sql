-- =============================================================================
-- VATSIM_GIS: Add is_subsector Filter to ARTCC Boundary Functions
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Version: 1.0
-- Date: 2026-03-07
--
-- PURPOSE:
--   Migration 014 added hierarchy support to artcc_boundaries (L0 super-centers,
--   L2+ sub-areas) with an is_subsector flag. The batch detection functions (006,
--   007) were updated to filter these out, but 11 other functions still query
--   artcc_boundaries without AND NOT ab.is_subsector. This migration adds the
--   filter to all remaining functions to ensure only L1 operational FIRs are
--   returned in crossing calculations, route analysis, and boundary lookups.
--
-- FUNCTIONS UPDATED (11 total):
--   From 001_boundaries_schema.sql:
--     1. get_route_artccs(GEOMETRY)
--   From 002_extended_functions.sql:
--     2. get_route_boundaries()
--     3. get_boundaries_at_point()
--     4. analyze_tmi_route()
--     5. get_artcc_for_airport()
--   From 004_route_expansion_functions.sql:
--     6. expand_route_with_artccs()
--     7. expand_route_with_boundaries()
--     8. analyze_route_from_waypoints()
--   From 008_trajectory_crossings.sql:
--     9. get_trajectory_artcc_crossings()
--    10. get_artccs_traversed()
--   From 009_tracon_crossings.sql:
--    11. get_trajectory_all_crossings()
--
-- FUNCTIONS THAT INHERIT THE FIX (no direct changes):
--   - get_route_artccs_from_waypoints() -> calls get_route_artccs(GEOMETRY)
--   - get_route_artccs(TEXT) -> calls expand_route_with_artccs()
--   - calculate_crossing_etas() -> calls get_trajectory_all_crossings()
--   - calculate_crossings_batch() -> calls calculate_crossing_etas()
--   - expand_routes_batch() -> calls expand_route_with_artccs()
--   - expand_routes_with_geojson() -> calls expand_routes_batch()
--   - expand_routes_full() -> calls expand_route_with_artccs()
--   - route_to_geojson_feature() -> calls expand_route_with_artccs()
--   - routes_to_geojson_collection() -> calls route_to_geojson_feature()
--
-- ALREADY SAFE (no changes):
--   - get_artcc_at_point() (006) - has filter
--   - detect_boundaries_batch() (006) - has filter
--   - detect_boundaries_batch_optimized() (006) - has filter
--   - detect_boundaries_and_sectors_batch() (007) - has filter
--
-- SAFETY:
--   All changes are CREATE OR REPLACE FUNCTION - safe to run while daemons
--   are active. Leverages existing filtered index idx_artcc_boundaries_fir_only.
-- =============================================================================

-- =============================================================================
-- 1. get_route_artccs(GEOMETRY) - from 001_boundaries_schema.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector at WHERE clause
-- =============================================================================

CREATE OR REPLACE FUNCTION get_route_artccs(route_geom GEOMETRY)
RETURNS TABLE (
    artcc_code VARCHAR(4),
    fir_name VARCHAR(64),
    traversal_order FLOAT
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        ab.artcc_code,
        ab.fir_name,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, ab.geom))) AS traversal_order
    FROM artcc_boundaries ab
    WHERE ST_Intersects(route_geom, ab.geom)
      AND NOT ab.is_subsector
    ORDER BY traversal_order;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_artccs(GEOMETRY) IS 'Returns ARTCCs traversed by a route LineString, ordered by traversal position (L1 FIRs only)';

-- =============================================================================
-- 2. get_route_boundaries() - from 002_extended_functions.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector to ARTCC section
-- =============================================================================

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

    -- ARTCCs (always included) - filter out sub-areas and super-centers
    RETURN QUERY
    SELECT
        'ARTCC'::VARCHAR(20) AS boundary_type,
        ab.artcc_code::VARCHAR(50) AS boundary_code,
        ab.fir_name::VARCHAR(64) AS boundary_name,
        ab.artcc_code::VARCHAR(4) AS parent_artcc,
        ab.floor_altitude,
        ab.ceiling_altitude,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, ab.geom)))::FLOAT AS traversal_order,
        jsonb_build_object(
            'lon', ST_X(ST_StartPoint(ST_Intersection(route_geom, ab.geom))),
            'lat', ST_Y(ST_StartPoint(ST_Intersection(route_geom, ab.geom)))
        ) AS entry_point,
        jsonb_build_object(
            'lon', ST_X(ST_EndPoint(ST_Intersection(route_geom, ab.geom))),
            'lat', ST_Y(ST_EndPoint(ST_Intersection(route_geom, ab.geom)))
        ) AS exit_point
    FROM artcc_boundaries ab
    WHERE ST_Intersects(route_geom, ab.geom)
      AND NOT ab.is_subsector;

    -- TRACONs (for departure/arrival, typically below FL180)
    RETURN QUERY
    SELECT
        'TRACON'::VARCHAR(20) AS boundary_type,
        tb.tracon_code::VARCHAR(50) AS boundary_code,
        tb.tracon_name::VARCHAR(64) AS boundary_name,
        tb.parent_artcc::VARCHAR(4) AS parent_artcc,
        tb.floor_altitude,
        tb.ceiling_altitude,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, tb.geom)))::FLOAT AS traversal_order,
        jsonb_build_object(
            'lon', ST_X(ST_StartPoint(ST_Intersection(route_geom, tb.geom))),
            'lat', ST_Y(ST_StartPoint(ST_Intersection(route_geom, tb.geom)))
        ) AS entry_point,
        jsonb_build_object(
            'lon', ST_X(ST_EndPoint(ST_Intersection(route_geom, tb.geom))),
            'lat', ST_Y(ST_EndPoint(ST_Intersection(route_geom, tb.geom)))
        ) AS exit_point
    FROM tracon_boundaries tb
    WHERE ST_Intersects(route_geom, tb.geom);

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
            ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, sb.geom)))::FLOAT AS traversal_order,
            jsonb_build_object(
                'lon', ST_X(ST_StartPoint(ST_Intersection(route_geom, sb.geom))),
                'lat', ST_Y(ST_StartPoint(ST_Intersection(route_geom, sb.geom)))
            ) AS entry_point,
            jsonb_build_object(
                'lon', ST_X(ST_EndPoint(ST_Intersection(route_geom, sb.geom))),
                'lat', ST_Y(ST_EndPoint(ST_Intersection(route_geom, sb.geom)))
            ) AS exit_point
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'LOW'
          AND ST_Intersects(route_geom, sb.geom)
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= 0);  -- Low sectors affect climb/descent

        -- HIGH sectors (typically FL240 to FL600)
        RETURN QUERY
        SELECT
            'SECTOR_HIGH'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, sb.geom)))::FLOAT AS traversal_order,
            jsonb_build_object(
                'lon', ST_X(ST_StartPoint(ST_Intersection(route_geom, sb.geom))),
                'lat', ST_Y(ST_StartPoint(ST_Intersection(route_geom, sb.geom)))
            ) AS entry_point,
            jsonb_build_object(
                'lon', ST_X(ST_EndPoint(ST_Intersection(route_geom, sb.geom))),
                'lat', ST_Y(ST_EndPoint(ST_Intersection(route_geom, sb.geom)))
            ) AS exit_point
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'HIGH'
          AND ST_Intersects(route_geom, sb.geom)
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
            ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, sb.geom)))::FLOAT AS traversal_order,
            jsonb_build_object(
                'lon', ST_X(ST_StartPoint(ST_Intersection(route_geom, sb.geom))),
                'lat', ST_Y(ST_StartPoint(ST_Intersection(route_geom, sb.geom)))
            ) AS entry_point,
            jsonb_build_object(
                'lon', ST_X(ST_EndPoint(ST_Intersection(route_geom, sb.geom))),
                'lat', ST_Y(ST_EndPoint(ST_Intersection(route_geom, sb.geom)))
            ) AS exit_point
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'SUPERHIGH'
          AND ST_Intersects(route_geom, sb.geom)
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= cruise_altitude);
    END IF;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_boundaries IS 'Returns all boundaries (ARTCC L1 FIRs, TRACON, sectors) traversed by a route';

-- =============================================================================
-- 3. get_boundaries_at_point() - from 002_extended_functions.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector to ARTCC section
-- =============================================================================

CREATE OR REPLACE FUNCTION get_boundaries_at_point(
    p_lat DECIMAL(10,6),
    p_lon DECIMAL(11,6),
    p_altitude INT DEFAULT NULL
)
RETURNS TABLE (
    boundary_type VARCHAR(20),
    boundary_code VARCHAR(50),
    boundary_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    floor_altitude INT,
    ceiling_altitude INT,
    is_oceanic BOOLEAN
) AS $$
DECLARE
    point_geom GEOMETRY;
BEGIN
    point_geom := ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326);

    -- ARTCC (return single best match - prefer non-oceanic, smallest, L1 FIRs only)
    RETURN QUERY
    SELECT DISTINCT ON (ab.artcc_code)
        'ARTCC'::VARCHAR(20) AS boundary_type,
        ab.artcc_code::VARCHAR(50) AS boundary_code,
        ab.fir_name::VARCHAR(64) AS boundary_name,
        ab.artcc_code::VARCHAR(4) AS parent_artcc,
        ab.floor_altitude,
        ab.ceiling_altitude,
        ab.is_oceanic
    FROM artcc_boundaries ab
    WHERE ST_Contains(ab.geom, point_geom)
      AND NOT ab.is_subsector
    ORDER BY ab.artcc_code, ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
    LIMIT 1;

    -- TRACON (if altitude below FL180 or not specified)
    IF p_altitude IS NULL OR p_altitude < 18000 THEN
        RETURN QUERY
        SELECT
            'TRACON'::VARCHAR(20) AS boundary_type,
            tb.tracon_code::VARCHAR(50) AS boundary_code,
            tb.tracon_name::VARCHAR(64) AS boundary_name,
            tb.parent_artcc::VARCHAR(4) AS parent_artcc,
            tb.floor_altitude,
            tb.ceiling_altitude,
            FALSE AS is_oceanic
        FROM tracon_boundaries tb
        WHERE ST_Contains(tb.geom, point_geom)
          AND (tb.floor_altitude IS NULL OR tb.floor_altitude <= COALESCE(p_altitude, 18000))
          AND (tb.ceiling_altitude IS NULL OR tb.ceiling_altitude >= COALESCE(p_altitude, 0));
    END IF;

    -- LOW sectors
    IF p_altitude IS NULL OR p_altitude < 24000 THEN
        RETURN QUERY
        SELECT
            'SECTOR_LOW'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            FALSE AS is_oceanic
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'LOW'
          AND ST_Contains(sb.geom, point_geom)
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= COALESCE(p_altitude, 24000))
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= COALESCE(p_altitude, 0));
    END IF;

    -- HIGH sectors
    IF p_altitude IS NULL OR (p_altitude >= 10000 AND p_altitude <= 60000) THEN
        RETURN QUERY
        SELECT
            'SECTOR_HIGH'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            FALSE AS is_oceanic
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'HIGH'
          AND ST_Contains(sb.geom, point_geom)
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= COALESCE(p_altitude, 35000))
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= COALESCE(p_altitude, 10000));
    END IF;

    -- SUPERHIGH sectors
    IF p_altitude IS NULL OR p_altitude >= 35000 THEN
        RETURN QUERY
        SELECT
            'SECTOR_SUPERHIGH'::VARCHAR(20) AS boundary_type,
            sb.sector_code::VARCHAR(50) AS boundary_code,
            sb.sector_name::VARCHAR(64) AS boundary_name,
            sb.parent_artcc::VARCHAR(4) AS parent_artcc,
            sb.floor_altitude,
            sb.ceiling_altitude,
            FALSE AS is_oceanic
        FROM sector_boundaries sb
        WHERE sb.sector_type = 'SUPERHIGH'
          AND ST_Contains(sb.geom, point_geom)
          AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= COALESCE(p_altitude, 60000))
          AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= COALESCE(p_altitude, 35000));
    END IF;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_boundaries_at_point IS 'Returns all boundaries containing a geographic point at given altitude (L1 FIRs only for ARTCC)';

-- =============================================================================
-- 4. analyze_tmi_route() - from 002_extended_functions.sql
-- =============================================================================
-- Changes: Added AND NOT is_subsector to ARTCC array_agg,
--          Added AND NOT ab.is_subsector to both airport ARTCC lookups
-- =============================================================================

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
BEGIN
    -- Parse route geometry from GeoJSON
    -- Supports: raw coordinates array, LineString geometry, Feature with geometry
    IF p_route_geojson IS NULL THEN
        RETURN QUERY SELECT '{}'::TEXT[], '{}'::TEXT[], '{}'::TEXT[], '[]'::JSONB, NULL::VARCHAR(4), NULL::VARCHAR(4);
        RETURN;
    END IF;

    -- Try to extract geometry
    BEGIN
        IF p_route_geojson ? 'coordinates' AND p_route_geojson->>'type' = 'LineString' THEN
            -- Direct LineString
            route_geom := ST_SetSRID(ST_GeomFromGeoJSON(p_route_geojson::text), 4326);
        ELSIF p_route_geojson ? 'geometry' THEN
            -- Feature with geometry
            route_geom := ST_SetSRID(ST_GeomFromGeoJSON((p_route_geojson->'geometry')::text), 4326);
        ELSIF jsonb_typeof(p_route_geojson) = 'array' THEN
            -- Raw coordinates array [[lon,lat], [lon,lat], ...]
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
            -- Try as waypoints format [{lon, lat}, ...]
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

    -- Get ARTCCs traversed (ordered by traversal) - L1 FIRs only
    SELECT array_agg(DISTINCT artcc_code ORDER BY artcc_code)
    INTO v_artccs
    FROM artcc_boundaries
    WHERE ST_Intersects(geom, route_geom)
      AND NOT is_subsector;

    -- Get TRACONs traversed
    SELECT array_agg(DISTINCT tracon_code ORDER BY tracon_code)
    INTO v_tracons
    FROM tracon_boundaries
    WHERE ST_Intersects(geom, route_geom);

    -- Get sectors traversed at cruise altitude (as JSONB array)
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'code', sector_code,
        'name', sector_name,
        'type', sector_type,
        'artcc', parent_artcc
    )), '[]'::jsonb)
    INTO v_sectors
    FROM sector_boundaries
    WHERE ST_Intersects(geom, route_geom)
      AND (floor_altitude IS NULL OR floor_altitude <= p_cruise_altitude)
      AND (ceiling_altitude IS NULL OR ceiling_altitude >= p_cruise_altitude);

    -- Get origin airport's ARTCC (if airports table exists and origin provided) - L1 FIRs only
    IF p_origin_icao IS NOT NULL THEN
        BEGIN
            SELECT ab.artcc_code INTO v_origin_artcc
            FROM airports apt
            JOIN artcc_boundaries ab ON ST_Contains(ab.geom, apt.geom) AND NOT ab.is_subsector
            WHERE apt.icao_id = p_origin_icao
            ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
            LIMIT 1;
        EXCEPTION WHEN undefined_table THEN
            v_origin_artcc := NULL;
        END;
    END IF;

    -- Get destination airport's ARTCC - L1 FIRs only
    IF p_dest_icao IS NOT NULL THEN
        BEGIN
            SELECT ab.artcc_code INTO v_dest_artcc
            FROM airports apt
            JOIN artcc_boundaries ab ON ST_Contains(ab.geom, apt.geom) AND NOT ab.is_subsector
            WHERE apt.icao_id = p_dest_icao
            ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
            LIMIT 1;
        EXCEPTION WHEN undefined_table THEN
            v_dest_artcc := NULL;
        END;
    END IF;

    RETURN QUERY SELECT
        COALESCE(v_artccs, '{}'),        -- facilities_traversed (ARTCCs as facilities)
        COALESCE(v_artccs, '{}'),        -- artccs_traversed
        COALESCE(v_tracons, '{}'),       -- tracons_traversed
        COALESCE(v_sectors, '[]'::jsonb), -- sectors_traversed
        v_origin_artcc,                   -- origin_artcc
        v_dest_artcc;                     -- dest_artcc
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION analyze_tmi_route IS 'Analyzes TMI route proposal and returns all traversed facilities (L1 FIRs only for ARTCC)';

-- =============================================================================
-- 5. get_artcc_for_airport() - from 002_extended_functions.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector to JOIN
-- =============================================================================

CREATE OR REPLACE FUNCTION get_artcc_for_airport(
    p_icao VARCHAR(4)
)
RETURNS VARCHAR(4) AS $$
DECLARE
    v_artcc VARCHAR(4);
BEGIN
    SELECT ab.artcc_code INTO v_artcc
    FROM airports apt
    JOIN artcc_boundaries ab ON ST_Contains(ab.geom, apt.geom) AND NOT ab.is_subsector
    WHERE apt.icao_id = p_icao
    ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
    LIMIT 1;

    RETURN v_artcc;
EXCEPTION WHEN undefined_table THEN
    RETURN NULL;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_artcc_for_airport IS 'Returns the L1 FIR ARTCC containing an airport by ICAO code';

-- =============================================================================
-- 6. expand_route_with_artccs() - from 004_route_expansion_functions.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector at ARTCC intersection query
-- =============================================================================

CREATE OR REPLACE FUNCTION expand_route_with_artccs(p_route_string TEXT)
RETURNS TABLE (
    waypoints JSONB,
    artccs_traversed TEXT[],
    route_geometry GEOMETRY
) AS $$
DECLARE
    v_waypoints JSONB;
    v_route_geom GEOMETRY;
    v_artccs TEXT[];
BEGIN
    -- Expand route to waypoints as JSONB
    SELECT jsonb_agg(
        jsonb_build_object(
            'seq', er.waypoint_seq,
            'id', er.waypoint_id,
            'lat', er.lat,
            'lon', er.lon,
            'type', er.waypoint_type
        ) ORDER BY er.waypoint_seq
    )
    INTO v_waypoints
    FROM expand_route(p_route_string) er;

    -- Build route geometry LineString from waypoints
    IF v_waypoints IS NOT NULL AND jsonb_array_length(v_waypoints) >= 2 THEN
        SELECT ST_MakeLine(
            ARRAY(
                SELECT ST_SetSRID(ST_MakePoint(
                    (wp->>'lon')::FLOAT,
                    (wp->>'lat')::FLOAT
                ), 4326)
                FROM jsonb_array_elements(v_waypoints) AS wp
                ORDER BY (wp->>'seq')::INT
            )
        ) INTO v_route_geom;
    END IF;

    -- Find ARTCCs traversed (in traversal order by route centroid position) - L1 FIRs only
    IF v_route_geom IS NOT NULL THEN
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                  AND NOT ab.is_subsector
                ORDER BY ab.artcc_code, traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;
    END IF;

    -- Return results
    waypoints := COALESCE(v_waypoints, '[]'::JSONB);
    artccs_traversed := COALESCE(v_artccs, ARRAY[]::TEXT[]);
    route_geometry := v_route_geom;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_route_with_artccs(TEXT) IS 'Expands route and returns traversed L1 FIR ARTCCs in order';

-- =============================================================================
-- 7. expand_route_with_boundaries() - from 004_route_expansion_functions.sql
-- =============================================================================
-- Changes: Added AND NOT ab.is_subsector to both ARTCC queries (traversal + boundaries)
-- =============================================================================

CREATE OR REPLACE FUNCTION expand_route_with_boundaries(
    p_route_string TEXT,
    p_altitude INT DEFAULT 35000
)
RETURNS TABLE (
    waypoints JSONB,
    artccs_traversed TEXT[],
    boundaries_traversed JSONB,
    route_geometry GEOMETRY,
    total_distance_nm DECIMAL(10,2)
) AS $$
DECLARE
    v_waypoints JSONB;
    v_route_geom GEOMETRY;
    v_artccs TEXT[];
    v_boundaries JSONB;
    v_distance DECIMAL(10,2);
BEGIN
    -- Expand route to waypoints
    SELECT jsonb_agg(
        jsonb_build_object(
            'seq', er.waypoint_seq,
            'id', er.waypoint_id,
            'lat', er.lat,
            'lon', er.lon,
            'type', er.waypoint_type
        ) ORDER BY er.waypoint_seq
    )
    INTO v_waypoints
    FROM expand_route(p_route_string) er;

    -- Build route geometry
    IF v_waypoints IS NOT NULL AND jsonb_array_length(v_waypoints) >= 2 THEN
        SELECT ST_MakeLine(
            ARRAY(
                SELECT ST_SetSRID(ST_MakePoint(
                    (wp->>'lon')::FLOAT,
                    (wp->>'lat')::FLOAT
                ), 4326)
                FROM jsonb_array_elements(v_waypoints) AS wp
                ORDER BY (wp->>'seq')::INT
            )
        ) INTO v_route_geom;
    END IF;

    -- Calculate distance in nautical miles
    IF v_route_geom IS NOT NULL THEN
        SELECT ST_Length(v_route_geom::geography) / 1852.0 INTO v_distance;
    END IF;

    -- Find ARTCCs traversed (in traversal order) - L1 FIRs only
    IF v_route_geom IS NOT NULL THEN
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                  AND NOT ab.is_subsector
                ORDER BY ab.artcc_code, traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;

        -- Find all boundaries with altitude filtering - L1 FIRs only
        SELECT jsonb_agg(boundary_data ORDER BY (boundary_data->>'traversal_order')::FLOAT)
        INTO v_boundaries
        FROM (
            SELECT jsonb_build_object(
                'code', ab.artcc_code,
                'name', ab.fir_name,
                'floor', ab.floor_altitude,
                'ceiling', ab.ceiling_altitude,
                'is_oceanic', ab.is_oceanic,
                'traversal_order', ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom)))
            ) AS boundary_data
            FROM artcc_boundaries ab
            WHERE ST_Intersects(ab.geom, v_route_geom)
              AND NOT ab.is_subsector
              AND (ab.floor_altitude IS NULL OR ab.floor_altitude <= p_altitude)
              AND (ab.ceiling_altitude IS NULL OR ab.ceiling_altitude >= p_altitude)
        ) sub;
    END IF;

    -- Return results
    waypoints := COALESCE(v_waypoints, '[]'::JSONB);
    artccs_traversed := COALESCE(v_artccs, ARRAY[]::TEXT[]);
    boundaries_traversed := COALESCE(v_boundaries, '[]'::JSONB);
    route_geometry := v_route_geom;
    total_distance_nm := COALESCE(v_distance, 0);
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_route_with_boundaries(TEXT, INT) IS 'Full route analysis with altitude-filtered boundary traversal (L1 FIRs only)';

-- =============================================================================
-- 8. analyze_route_from_waypoints() - from 004_route_expansion_functions.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector at ARTCC intersection query
-- =============================================================================

CREATE OR REPLACE FUNCTION analyze_route_from_waypoints(p_waypoints JSONB)
RETURNS TABLE (
    artccs_traversed TEXT[],
    route_geometry GEOMETRY,
    total_distance_nm DECIMAL(10,2)
) AS $$
DECLARE
    v_route_geom GEOMETRY;
    v_artccs TEXT[];
    v_distance DECIMAL(10,2);
BEGIN
    -- Build route geometry from waypoints
    IF p_waypoints IS NOT NULL AND jsonb_array_length(p_waypoints) >= 2 THEN
        SELECT ST_MakeLine(
            ARRAY(
                SELECT ST_SetSRID(ST_MakePoint(
                    (wp->>'lon')::FLOAT,
                    (wp->>'lat')::FLOAT
                ), 4326)
                FROM jsonb_array_elements(p_waypoints) AS wp
            )
        ) INTO v_route_geom;
    END IF;

    IF v_route_geom IS NOT NULL THEN
        -- Calculate distance
        SELECT ST_Length(v_route_geom::geography) / 1852.0 INTO v_distance;

        -- Find ARTCCs traversed - L1 FIRs only
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                  AND NOT ab.is_subsector
                ORDER BY ab.artcc_code, traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;
    END IF;

    artccs_traversed := COALESCE(v_artccs, ARRAY[]::TEXT[]);
    route_geometry := v_route_geom;
    total_distance_nm := COALESCE(v_distance, 0);
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION analyze_route_from_waypoints(JSONB) IS 'Analyzes pre-expanded waypoints for ARTCC crossings (L1 FIRs only)';

-- =============================================================================
-- 9. get_trajectory_artcc_crossings() - from 008_trajectory_crossings.sql
-- =============================================================================
-- Changes: Added AND NOT ab.is_subsector to main CTE query
--          Added AND NOT ab2.is_subsector to entry/exit subquery
-- =============================================================================

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
BEGIN
    -- Build trajectory line
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN;
    END IF;

    -- Get total length in meters (for nm conversion)
    total_length_m := ST_Length(trajectory::geography);

    RETURN QUERY
    WITH crossings AS (
        -- Find all intersection points with ARTCC boundaries (L1 FIRs only)
        SELECT
            ab.artcc_code,
            ab.fir_name AS artcc_name,
            COALESCE(ab.is_oceanic, FALSE) AS is_oceanic,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(ab.geom)))).geom AS crossing_point
        FROM artcc_boundaries ab
        WHERE ST_Intersects(trajectory, ab.geom)
          AND NOT ab.is_subsector
    ),
    crossing_details AS (
        SELECT
            c.artcc_code,
            c.artcc_name,
            c.is_oceanic,
            ST_Y(c.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            ST_X(c.crossing_point)::DECIMAL(11,6) AS crossing_lon,
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
                (SELECT ab2.geom FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.artcc_code AND NOT ab2.is_subsector LIMIT 1),
                ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
            ) THEN 'ENTRY'::VARCHAR(5)
            ELSE 'EXIT'::VARCHAR(5)
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_trajectory_artcc_crossings IS 'Get all ARTCC boundary crossings along a trajectory (L1 FIRs only)';

-- =============================================================================
-- 10. get_artccs_traversed() - from 008_trajectory_crossings.sql
-- =============================================================================
-- Change: Added AND NOT ab.is_subsector (was missing alongside oceanic filter)
-- =============================================================================

CREATE OR REPLACE FUNCTION get_artccs_traversed(
    p_waypoints JSONB
)
RETURNS TEXT[] AS $$
DECLARE
    trajectory GEOMETRY;
    artccs TEXT[];
BEGIN
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN ARRAY[]::TEXT[];
    END IF;

    -- Get unique ARTCCs in first-crossing order using ST_Boundary for precise points
    -- This matches the approach used in get_trajectory_artcc_crossings
    SELECT array_agg(sub.artcc_code ORDER BY sub.first_crossing)
    INTO artccs
    FROM (
        SELECT
            ab.artcc_code,
            MIN(ST_LineLocatePoint(trajectory, crossing_point.geom)) AS first_crossing
        FROM artcc_boundaries ab
        CROSS JOIN LATERAL (
            SELECT (ST_Dump(ST_Intersection(trajectory, ST_Boundary(ab.geom)))).geom
        ) AS crossing_point
        WHERE ST_Intersects(trajectory, ab.geom)
          AND NOT COALESCE(ab.is_oceanic, FALSE)
          AND NOT ab.is_subsector
          AND ST_GeometryType(crossing_point.geom) = 'ST_Point'
        GROUP BY ab.artcc_code
    ) sub;

    RETURN COALESCE(artccs, ARRAY[]::TEXT[]);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_artccs_traversed IS 'Get array of ARTCC codes traversed by route in crossing order (L1 FIRs only)';

-- =============================================================================
-- 11. get_trajectory_all_crossings() - from 009_tracon_crossings.sql
-- =============================================================================
-- Changes: Added AND NOT ab.is_subsector to artcc_crossings CTE
--          Widened VARCHAR(4) to VARCHAR(20) in entry/exit subquery
--          Added AND NOT ab2.is_subsector to entry/exit subquery
-- =============================================================================

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
BEGIN
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN;
    END IF;

    total_length_m := ST_Length(trajectory::geography);

    RETURN QUERY
    -- ARTCC crossings (L1 FIRs only)
    WITH artcc_crossings AS (
        SELECT
            'ARTCC'::VARCHAR(16) AS boundary_type,
            ab.artcc_code::VARCHAR(16) AS boundary_code,
            ab.fir_name AS boundary_name,
            ab.artcc_code AS parent_artcc,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(ab.geom)))).geom AS crossing_point
        FROM artcc_boundaries ab
        WHERE ST_Intersects(trajectory, ab.geom)
          AND NOT ab.is_subsector
    ),
    -- Sector crossings (HIGH, LOW, SUPERHIGH - sector_type becomes boundary_type)
    sector_crossings AS (
        SELECT
            sb.sector_type::VARCHAR(16) AS boundary_type,
            sb.sector_code::VARCHAR(16) AS boundary_code,
            sb.sector_name AS boundary_name,
            sb.parent_artcc,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(sb.geom)))).geom AS crossing_point
        FROM sector_boundaries sb
        WHERE ST_Intersects(trajectory, sb.geom)
    ),
    -- TRACON crossings
    tracon_crossings AS (
        SELECT
            'TRACON'::VARCHAR(16) AS boundary_type,
            tb.tracon_code::VARCHAR(16) AS boundary_code,
            tb.tracon_name AS boundary_name,
            tb.parent_artcc,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(tb.geom)))).geom AS crossing_point
        FROM tracon_boundaries tb
        WHERE ST_Intersects(trajectory, tb.geom)
    ),
    -- Combine all crossing types
    all_crossings AS (
        SELECT * FROM artcc_crossings
        UNION ALL
        SELECT * FROM sector_crossings
        UNION ALL
        SELECT * FROM tracon_crossings
    ),
    crossing_details AS (
        SELECT
            ac.boundary_type,
            ac.boundary_code,
            ac.boundary_name,
            ac.parent_artcc,
            ST_Y(ac.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            ST_X(ac.crossing_point)::DECIMAL(11,6) AS crossing_lon,
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
        -- Entry/exit determination based on boundary type
        CASE
            WHEN cd.boundary_type = 'ARTCC' THEN
                CASE WHEN ST_Contains(
                    (SELECT ab2.geom FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.boundary_code::VARCHAR(20) AND NOT ab2.is_subsector LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
            WHEN cd.boundary_type = 'TRACON' THEN
                CASE WHEN ST_Contains(
                    (SELECT tb2.geom FROM tracon_boundaries tb2 WHERE tb2.tracon_code = cd.boundary_code LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
            ELSE
                -- Sectors (HIGH, LOW, SUPERHIGH)
                CASE WHEN ST_Contains(
                    (SELECT sb2.geom FROM sector_boundaries sb2 WHERE sb2.sector_code = cd.boundary_code LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_trajectory_all_crossings IS 'Get all boundary crossings (ARTCC L1 FIRs + sectors + TRACONs) along a trajectory';

-- =============================================================================
-- END MIGRATION
-- =============================================================================
