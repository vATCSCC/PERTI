-- =============================================================================
-- VATSIM_GIS TRACON Crossing Functions
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Version: 1.0
-- Date: 2026-02-01
--
-- PURPOSE:
--   Add TRACON boundary crossing support to trajectory analysis.
--   Updates get_trajectory_all_crossings to include ARTCCs, sectors, AND TRACONs.
--
-- CHANGES:
--   1. New function: get_trajectory_tracon_crossings()
--   2. Updated function: get_trajectory_all_crossings() - now includes TRACONs
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. get_trajectory_tracon_crossings - Get TRACON boundary crossings
-- -----------------------------------------------------------------------------
-- Input: JSONB waypoints array
-- Output: All TRACON boundaries crossed with crossing coordinates
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_trajectory_tracon_crossings(
    p_waypoints JSONB
)
RETURNS TABLE (
    tracon_code VARCHAR(16),
    tracon_name VARCHAR(64),
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
            tb.tracon_code,
            tb.tracon_name,
            tb.parent_artcc,
            (ST_Dump(ST_Intersection(trajectory, ST_Boundary(tb.geom)))).geom AS crossing_point
        FROM tracon_boundaries tb
        WHERE ST_Intersects(trajectory, tb.geom)
    ),
    crossing_details AS (
        SELECT
            c.tracon_code,
            c.tracon_name,
            c.parent_artcc,
            ST_Y(c.crossing_point)::DECIMAL(10,6) AS crossing_lat,
            ST_X(c.crossing_point)::DECIMAL(11,6) AS crossing_lon,
            ST_LineLocatePoint(trajectory, c.crossing_point) AS crossing_fraction
        FROM crossings c
        WHERE ST_GeometryType(c.crossing_point) = 'ST_Point'
    )
    SELECT
        cd.tracon_code,
        cd.tracon_name,
        cd.parent_artcc,
        cd.crossing_lat,
        cd.crossing_lon,
        cd.crossing_fraction,
        (cd.crossing_fraction * total_length_m / 1852.0)::FLOAT AS distance_nm,
        CASE
            WHEN ST_Contains(
                (SELECT tb2.geom FROM tracon_boundaries tb2 WHERE tb2.tracon_code = cd.tracon_code LIMIT 1),
                ST_LineInterpolatePoint(trajectory, LEAST(cd.crossing_fraction + 0.001, 1.0))
            ) THEN 'ENTRY'::VARCHAR(5)
            ELSE 'EXIT'::VARCHAR(5)
        END AS crossing_type
    FROM crossing_details cd
    ORDER BY cd.crossing_fraction;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_trajectory_tracon_crossings IS 'Get all TRACON boundary crossings along a trajectory';

-- -----------------------------------------------------------------------------
-- 2. get_trajectory_all_crossings - Updated to include TRACONs
-- -----------------------------------------------------------------------------
-- Returns all boundary crossings (ARTCC, sectors, AND TRACONs) in route order.
-- boundary_type values: 'ARTCC', 'HIGH', 'LOW', 'SUPERHIGH', 'TRACON'
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
    -- TRACON crossings (new)
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
                    (SELECT ab2.geom FROM artcc_boundaries ab2 WHERE ab2.artcc_code = cd.boundary_code::VARCHAR(4) LIMIT 1),
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

COMMENT ON FUNCTION get_trajectory_all_crossings IS 'Get all boundary crossings (ARTCC + sectors + TRACONs) along a trajectory';

-- =============================================================================
-- GRANT PERMISSIONS (if needed)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION get_trajectory_tracon_crossings TO gis_readonly;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
