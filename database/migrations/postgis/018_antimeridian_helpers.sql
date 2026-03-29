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

-- ----------------------------------------------------------------------------
-- safe_shift_geom(geometry) -> geometry
--
-- Shifts a geometry to [0, 360] longitude space, but ONLY when safe.
-- Boundaries that straddle the prime meridian (0° longitude) with a span
-- under 180° must NOT be shifted — doing so moves their negative-longitude
-- vertices to ~350° while positive vertices stay near 0°, creating invalid
-- or semantically wrong polygons that wrap the long way around.
--
-- Safe cases (shifted):
--   - Entirely Western hemisphere (all lon < 0) → shifted to [180, 360]
--   - Entirely Eastern hemisphere (all lon > 0) → no-op
--   - Straddles antimeridian (span >= 180°) → shifted correctly
-- Unsafe case (returned as-is):
--   - Straddles or touches prime meridian (XMin < 0, XMax >= 0, span < 180°)
--     e.g., BIRD FIR [-70°, 0°] — shifting would create [290°, 0°] wrapping
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION safe_shift_geom(p_geom geometry)
RETURNS geometry LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN ST_XMin(p_geom) < 0 AND ST_XMax(p_geom) >= 0
             AND (ST_XMax(p_geom) - ST_XMin(p_geom)) < 180
        THEN ST_MakeValid(p_geom)
        ELSE ST_MakeValid(ST_ShiftLongitude(p_geom))
    END;
$$;

COMMENT ON FUNCTION safe_shift_geom IS
    'Shifts geometry to [0,360] longitude space, skipping prime-meridian-crossing boundaries to avoid wrapping errors. Always returns ST_MakeValid geometry.';

-- Grants
GRANT EXECUTE ON FUNCTION crosses_antimeridian TO jpeterson;
GRANT EXECUTE ON FUNCTION normalize_lon TO jpeterson;
GRANT EXECUTE ON FUNCTION safe_shift_geom TO jpeterson;
