-- =============================================================================
-- VATSIM_GIS Extended PostGIS Functions
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-29
-- =============================================================================
--
-- PURPOSE:
--   Extended spatial functions for TMI route analysis and boundary detection.
--   Provides comprehensive route traversal analysis for all boundary types.
--
-- FUNCTIONS:
--   1. get_route_boundaries()      - All boundaries traversed by route
--   2. get_boundaries_at_point()   - Point-in-polygon lookup
--   3. analyze_tmi_route()         - TMI route proposal analysis
--   4. get_route_tracons()         - TRACONs traversed by route
--
-- =============================================================================

-- =============================================================================
-- FUNCTION 1: get_route_boundaries
-- Returns ALL boundary types traversed by a route with altitude filtering
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

    -- ARTCCs (always included)
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
    WHERE ST_Intersects(route_geom, ab.geom);

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

COMMENT ON FUNCTION get_route_boundaries IS 'Returns all boundaries (ARTCC, TRACON, sectors) traversed by a route';

-- =============================================================================
-- FUNCTION 2: get_boundaries_at_point
-- Point-in-polygon lookup for real-time position detection
-- Replaces ADL grid+STContains approach
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

    -- ARTCC (return single best match - prefer non-oceanic, smallest)
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

COMMENT ON FUNCTION get_boundaries_at_point IS 'Returns all boundaries containing a geographic point at given altitude';

-- =============================================================================
-- FUNCTION 3: analyze_tmi_route
-- TMI route proposal analysis for coordination
-- Returns facilities traversed and affected airports
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

    -- Get ARTCCs traversed (ordered by traversal)
    SELECT array_agg(DISTINCT artcc_code ORDER BY artcc_code)
    INTO v_artccs
    FROM artcc_boundaries
    WHERE ST_Intersects(geom, route_geom);

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

    -- Get origin airport's ARTCC (if airports table exists and origin provided)
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
        COALESCE(v_artccs, '{}'),        -- facilities_traversed (ARTCCs as facilities)
        COALESCE(v_artccs, '{}'),        -- artccs_traversed
        COALESCE(v_tracons, '{}'),       -- tracons_traversed
        COALESCE(v_sectors, '[]'::jsonb), -- sectors_traversed
        v_origin_artcc,                   -- origin_artcc
        v_dest_artcc;                     -- dest_artcc
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION analyze_tmi_route IS 'Analyzes TMI route proposal and returns all traversed facilities';

-- =============================================================================
-- FUNCTION 4: get_route_tracons
-- TRACONs traversed by a route (for departure/arrival analysis)
-- =============================================================================

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

    RETURN QUERY
    SELECT
        tb.tracon_code,
        tb.tracon_name,
        tb.parent_artcc,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, tb.geom)))::FLOAT AS traversal_order
    FROM tracon_boundaries tb
    WHERE ST_Intersects(route_geom, tb.geom)
    ORDER BY traversal_order;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_tracons IS 'Returns TRACONs traversed by a route';

-- =============================================================================
-- FUNCTION 5: get_artcc_for_airport
-- Lookup ARTCC containing an airport
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
    JOIN artcc_boundaries ab ON ST_Contains(ab.geom, apt.geom)
    WHERE apt.icao_id = p_icao
    ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
    LIMIT 1;

    RETURN v_artcc;
EXCEPTION WHEN undefined_table THEN
    RETURN NULL;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_artcc_for_airport IS 'Returns the ARTCC containing an airport by ICAO code';

-- =============================================================================
-- END MIGRATION
-- =============================================================================
