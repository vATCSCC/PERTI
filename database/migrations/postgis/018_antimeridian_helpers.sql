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
