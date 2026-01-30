-- =============================================================================
-- VATSIM_GIS Trajectory Crossing Functions
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Version: 1.0
-- Date: 2026-01-30
--
-- PURPOSE:
--   Compute precise boundary crossing points and times by intersecting
--   flight trajectory lines with boundary polygons. This replaces the
--   waypoint-containment approach with actual line-polygon intersection.
--
-- KEY FUNCTIONS:
--   1. build_trajectory_line() - Build LineString from waypoints
--   2. get_trajectory_boundary_crossings() - Get all boundary crossings
--   3. get_trajectory_artcc_crossings() - ARTCC-specific crossings
--   4. calculate_crossing_etas() - Add ETA calculations to crossings
--
-- ADVANTAGES OVER WAYPOINT-CONTAINMENT:
--   - Precise crossing coordinates (not just "between waypoint A and B")
--   - Accurate distance-along-route to crossing point
--   - Detects crossings even between sparse waypoints
--   - Handles diagonal boundary crossings correctly
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. build_trajectory_line - Build LineString geometry from waypoint array
-- -----------------------------------------------------------------------------
-- Input: JSONB array of {lat, lon, sequence_num} objects (ordered by sequence)
-- Output: LineString geometry in SRID 4326
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION build_trajectory_line(
    p_waypoints JSONB
)
RETURNS GEOMETRY AS $$
DECLARE
    route_geom GEOMETRY;
BEGIN
    -- Build LineString from waypoints using WITH ORDINALITY for robust ordering
    -- Falls back to sequence_num if provided, otherwise uses array position
    SELECT ST_MakeLine(
        array_agg(
            ST_SetSRID(ST_MakePoint(
                (wp->>'lon')::FLOAT,
                (wp->>'lat')::FLOAT
            ), 4326)
            ORDER BY COALESCE((wp->>'sequence_num')::INT, ordinality::INT)
        )
    )
    INTO route_geom
    FROM jsonb_array_elements(p_waypoints) WITH ORDINALITY AS t(wp, ordinality)
    WHERE (wp->>'lat') IS NOT NULL
      AND (wp->>'lon') IS NOT NULL;

    RETURN route_geom;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMENT ON FUNCTION build_trajectory_line IS 'Build LineString from waypoints JSONB array';

-- -----------------------------------------------------------------------------
-- 2. get_trajectory_artcc_crossings - Get ARTCC boundary crossings
-- -----------------------------------------------------------------------------
-- Input: JSONB waypoints array
-- Output: All ARTCC boundaries crossed with crossing coordinates and distance
--
-- Returns crossings in route order (by fraction along trajectory).
-- Each crossing includes entry/exit flag based on direction of travel.
-- -----------------------------------------------------------------------------
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
        -- Find all intersection points with ARTCC boundaries
        SELECT
            ab.artcc_code,
            ab.fir_name AS artcc_name,
            COALESCE(ab.is_oceanic, FALSE) AS is_oceanic,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(ab.geom)))).geom AS crossing_point
        FROM artcc_boundaries ab
        WHERE ST_Intersects(trajectory, ab.geom)
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
                (SELECT ab2.geom FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.artcc_code LIMIT 1),
                ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
            ) THEN 'ENTRY'::VARCHAR(5)
            ELSE 'EXIT'::VARCHAR(5)
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_trajectory_artcc_crossings IS 'Get all ARTCC boundary crossings along a trajectory with precise coordinates';

-- -----------------------------------------------------------------------------
-- 3. get_trajectory_sector_crossings - Get sector boundary crossings
-- -----------------------------------------------------------------------------
-- Input: JSONB waypoints array, optional sector_type filter
-- Output: All sector boundaries crossed with crossing coordinates
-- -----------------------------------------------------------------------------
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
BEGIN
    trajectory := build_trajectory_line(p_waypoints);

    IF trajectory IS NULL THEN
        RETURN;
    END IF;

    total_length_m := ST_Length(trajectory::geography);

    RETURN QUERY
    WITH crossings AS (
        SELECT
            sb.sector_code,
            sb.sector_name,
            sb.sector_type,
            sb.parent_artcc,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(sb.geom)))).geom AS crossing_point
        FROM sector_boundaries sb
        WHERE ST_Intersects(trajectory, sb.geom)
          AND (p_sector_type IS NULL OR sb.sector_type = p_sector_type)
    ),
    crossing_details AS (
        SELECT
            c.sector_code,
            c.sector_name,
            c.sector_type,
            c.parent_artcc,
            ST_Y(c.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            ST_X(c.crossing_point)::DECIMAL(11,6) AS crossing_lon,
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
                (SELECT sb2.geom FROM sector_boundaries sb2 WHERE sb2.sector_code = cd.sector_code LIMIT 1),
                ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
            ) THEN 'ENTRY'::VARCHAR(5)
            ELSE 'EXIT'::VARCHAR(5)
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_trajectory_sector_crossings IS 'Get all sector boundary crossings along a trajectory';

-- -----------------------------------------------------------------------------
-- 4. get_trajectory_all_crossings - Combined ARTCC + sector crossings
-- -----------------------------------------------------------------------------
-- Returns all boundary crossings (ARTCC and sectors) in route order.
-- Most efficient for getting complete crossing picture in one call.
-- -----------------------------------------------------------------------------
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
    -- ARTCC crossings
    WITH artcc_crossings AS (
        SELECT
            'ARTCC'::VARCHAR(16) AS boundary_type,
            ab.artcc_code::VARCHAR(16) AS boundary_code,
            ab.fir_name AS boundary_name,
            ab.artcc_code AS parent_artcc,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(ab.geom)))).geom AS crossing_point
        FROM artcc_boundaries ab
        WHERE ST_Intersects(trajectory, ab.geom)
    ),
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
        -- Entry/exit determination
        CASE
            WHEN cd.boundary_type = 'ARTCC' THEN
                CASE WHEN ST_Contains(
                    (SELECT ab2.geom FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.boundary_code::VARCHAR(4) LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
            ELSE
                CASE WHEN ST_Contains(
                    (SELECT sb2.geom FROM sector_boundaries sb2 WHERE sb2.sector_code = cd.boundary_code LIMIT 1),
                    ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
                ) THEN 'ENTRY'::VARCHAR(5) ELSE 'EXIT'::VARCHAR(5) END
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_trajectory_all_crossings IS 'Get all boundary crossings (ARTCC + sectors) along a trajectory';

-- -----------------------------------------------------------------------------
-- 5. calculate_crossing_etas - Add ETA to crossings based on groundspeed
-- -----------------------------------------------------------------------------
-- Input: waypoints, current position, groundspeed, current time
-- Output: Crossings with calculated ETA at each crossing point
--
-- This is the main function for ETA calculation - combines trajectory
-- crossing detection with time calculation.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION calculate_crossing_etas(
    p_waypoints JSONB,
    p_current_lat DECIMAL(10,6),
    p_current_lon DECIMAL(11,6),
    p_dist_flown_nm FLOAT,
    p_groundspeed_kts INT,
    p_current_time TIMESTAMP WITH TIME ZONE DEFAULT NOW()
)
RETURNS TABLE (
    boundary_type VARCHAR(16),
    boundary_code VARCHAR(16),
    boundary_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    crossing_lat DECIMAL(10,6),
    crossing_lon DECIMAL(11,6),
    distance_from_origin_nm FLOAT,
    distance_remaining_nm FLOAT,
    eta_utc TIMESTAMP WITH TIME ZONE,
    crossing_type VARCHAR(5)
) AS $$
DECLARE
    effective_gs INT;
BEGIN
    -- Use groundspeed if valid, otherwise default to 450 kts
    effective_gs := CASE
        WHEN p_groundspeed_kts > 50 AND p_groundspeed_kts < 700 THEN p_groundspeed_kts
        ELSE 450
    END;

    RETURN QUERY
    SELECT
        c.boundary_type,
        c.boundary_code,
        c.boundary_name,
        c.parent_artcc,
        c.crossing_lat,
        c.crossing_lon,
        c.distance_nm AS distance_from_origin_nm,
        GREATEST(c.distance_nm - p_dist_flown_nm, 0)::FLOAT AS distance_remaining_nm,
        -- ETA = current_time + (distance_remaining / groundspeed) hours
        (p_current_time +
            (INTERVAL '1 hour' * GREATEST(c.distance_nm - p_dist_flown_nm, 0) / effective_gs)
        )::TIMESTAMP WITH TIME ZONE AS eta_utc,
        c.crossing_type
    FROM get_trajectory_all_crossings(p_waypoints) c
    WHERE c.distance_nm > p_dist_flown_nm  -- Only future crossings
    ORDER BY c.distance_nm;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION calculate_crossing_etas IS 'Calculate ETAs for all future boundary crossings along trajectory';

-- -----------------------------------------------------------------------------
-- 6. Batch version for daemon use
-- -----------------------------------------------------------------------------
-- Process multiple flights at once for efficiency.
-- Input: JSONB array of flight objects with waypoints and current state
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION calculate_crossings_batch(
    p_flights JSONB
)
RETURNS TABLE (
    flight_uid BIGINT,
    boundary_type VARCHAR(16),
    boundary_code VARCHAR(16),
    boundary_name VARCHAR(64),
    crossing_lat DECIMAL(10,6),
    crossing_lon DECIMAL(11,6),
    distance_from_origin_nm FLOAT,
    distance_remaining_nm FLOAT,
    eta_utc TIMESTAMP WITH TIME ZONE,
    crossing_type VARCHAR(5)
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        (f->>'flight_uid')::BIGINT AS flight_uid,
        c.boundary_type,
        c.boundary_code,
        c.boundary_name,
        c.crossing_lat,
        c.crossing_lon,
        c.distance_from_origin_nm,
        c.distance_remaining_nm,
        c.eta_utc,
        c.crossing_type
    FROM jsonb_array_elements(p_flights) AS f
    CROSS JOIN LATERAL calculate_crossing_etas(
        f->'waypoints',
        (f->>'current_lat')::DECIMAL(10,6),
        (f->>'current_lon')::DECIMAL(11,6),
        COALESCE((f->>'dist_flown_nm')::FLOAT, 0),
        COALESCE((f->>'groundspeed_kts')::INT, 450),
        COALESCE((f->>'current_time')::TIMESTAMP WITH TIME ZONE, NOW())
    ) c;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION calculate_crossings_batch IS 'Batch crossing ETA calculation for multiple flights';

-- =============================================================================
-- HELPER FUNCTIONS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Get ARTCCs traversed by route (simplified - just the codes in order)
-- -----------------------------------------------------------------------------
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

    -- Get unique ARTCCs in crossing order (GROUP BY ensures uniqueness)
    SELECT array_agg(sub.artcc_code ORDER BY sub.min_fraction)
    INTO artccs
    FROM (
        SELECT
            ab.artcc_code,
            MIN(ST_LineLocatePoint(trajectory,
                (ST_Dump(ST_Intersection(trajectory, ab.geom))).geom
            )) AS min_fraction
        FROM artcc_boundaries ab
        WHERE ST_Intersects(trajectory, ab.geom)
          AND NOT COALESCE(ab.is_oceanic, FALSE)  -- Exclude oceanic
        GROUP BY ab.artcc_code
    ) sub;

    RETURN COALESCE(artccs, ARRAY[]::TEXT[]);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_artccs_traversed IS 'Get array of ARTCC codes traversed by route in order';

-- =============================================================================
-- GRANT PERMISSIONS (adjust as needed)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION build_trajectory_line TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_trajectory_artcc_crossings TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_trajectory_sector_crossings TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_trajectory_all_crossings TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION calculate_crossing_etas TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION calculate_crossings_batch TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_artccs_traversed TO gis_readonly;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
