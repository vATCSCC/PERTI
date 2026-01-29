-- =============================================================================
-- VATSIM_GIS PostGIS Boundaries Schema
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-29
-- =============================================================================
--
-- PURPOSE:
--   Spatial boundary storage for efficient route-polygon intersection queries.
--   Replaces SQL Server grid-based approach with native PostGIS operations.
--
-- TABLES:
--   1. artcc_boundaries    - ARTCC/FIR polygon boundaries
--   2. sector_boundaries   - High/Low/SuperHigh sector polygons
--   3. tracon_boundaries   - TRACON polygon boundaries
--
-- USAGE:
--   Route traversal query example:
--   SELECT artcc_code FROM artcc_boundaries
--   WHERE ST_Intersects(geom, ST_MakeLine(route_waypoints))
--   ORDER BY ST_LineLocatePoint(route_line, ST_Centroid(geom));
--
-- =============================================================================

-- Enable PostGIS extension
CREATE EXTENSION IF NOT EXISTS postgis;

-- =============================================================================
-- TABLE 1: artcc_boundaries
-- =============================================================================

DROP TABLE IF EXISTS artcc_boundaries CASCADE;

CREATE TABLE artcc_boundaries (
    boundary_id     SERIAL PRIMARY KEY,
    artcc_code      VARCHAR(4) NOT NULL,           -- e.g., ZNY, ZDC, ZLA
    fir_name        VARCHAR(64),                    -- Full FIR name
    icao_code       VARCHAR(4),                     -- ICAO identifier
    vatsim_region   VARCHAR(16),                    -- VATSIM region (VATUSA, etc.)
    vatsim_division VARCHAR(16),                    -- VATSIM division
    vatsim_subdiv   VARCHAR(16),                    -- VATSIM subdivision
    floor_altitude  INT,                            -- Floor in feet (NULL = surface)
    ceiling_altitude INT,                           -- Ceiling in feet (NULL = unlimited)
    is_oceanic      BOOLEAN DEFAULT FALSE,          -- Oceanic FIR flag
    label_lat       DECIMAL(9,6),                   -- Label position latitude
    label_lon       DECIMAL(10,6),                  -- Label position longitude
    geom            GEOMETRY(MultiPolygon, 4326) NOT NULL,  -- WGS84 boundary
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Spatial index (GiST) for efficient intersection queries
CREATE INDEX idx_artcc_boundaries_geom ON artcc_boundaries USING GIST (geom);

-- Standard index on artcc_code for lookups
CREATE UNIQUE INDEX idx_artcc_boundaries_code ON artcc_boundaries (artcc_code);

COMMENT ON TABLE artcc_boundaries IS 'ARTCC/FIR boundary polygons for route intersection analysis';

-- =============================================================================
-- TABLE 2: sector_boundaries
-- =============================================================================

DROP TABLE IF EXISTS sector_boundaries CASCADE;

CREATE TABLE sector_boundaries (
    sector_id       SERIAL PRIMARY KEY,
    sector_code     VARCHAR(16) NOT NULL,           -- e.g., ZNY_42, ZLA_HIGH_31
    sector_name     VARCHAR(64),                    -- Human-readable name
    parent_artcc    VARCHAR(4) NOT NULL,            -- Parent ARTCC code
    sector_type     VARCHAR(16) NOT NULL,           -- HIGH, LOW, SUPERHIGH
    floor_altitude  INT,                            -- Floor in feet
    ceiling_altitude INT,                           -- Ceiling in feet
    label_lat       DECIMAL(9,6),
    label_lon       DECIMAL(10,6),
    geom            GEOMETRY(MultiPolygon, 4326) NOT NULL,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Spatial index
CREATE INDEX idx_sector_boundaries_geom ON sector_boundaries USING GIST (geom);

-- Composite index for filtering by ARTCC and type
CREATE INDEX idx_sector_boundaries_artcc_type ON sector_boundaries (parent_artcc, sector_type);

COMMENT ON TABLE sector_boundaries IS 'Enroute sector boundaries (high/low/superhigh) for detailed route analysis';

-- =============================================================================
-- TABLE 3: tracon_boundaries
-- =============================================================================

DROP TABLE IF EXISTS tracon_boundaries CASCADE;

CREATE TABLE tracon_boundaries (
    tracon_id       SERIAL PRIMARY KEY,
    tracon_code     VARCHAR(16) NOT NULL,           -- e.g., N90, SCT, A80
    tracon_name     VARCHAR(64),                    -- Human-readable name
    parent_artcc    VARCHAR(4),                     -- Parent ARTCC code
    sector_code     VARCHAR(16),                    -- Sector within TRACON
    floor_altitude  INT,                            -- Floor in feet
    ceiling_altitude INT,                           -- Ceiling in feet
    label_lat       DECIMAL(9,6),
    label_lon       DECIMAL(10,6),
    geom            GEOMETRY(MultiPolygon, 4326) NOT NULL,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Spatial index
CREATE INDEX idx_tracon_boundaries_geom ON tracon_boundaries USING GIST (geom);

-- Index on parent ARTCC
CREATE INDEX idx_tracon_boundaries_artcc ON tracon_boundaries (parent_artcc);

COMMENT ON TABLE tracon_boundaries IS 'TRACON boundary polygons';

-- =============================================================================
-- HELPER FUNCTIONS
-- =============================================================================

-- Function: Get ARTCCs traversed by a route (as LineString)
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
    ORDER BY traversal_order;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_artccs IS 'Returns ARTCCs traversed by a route LineString, ordered by traversal position';

-- Function: Get ARTCCs from waypoint array
CREATE OR REPLACE FUNCTION get_route_artccs_from_waypoints(
    waypoints JSONB  -- Array of {lon, lat} objects in order
)
RETURNS TABLE (
    artcc_code VARCHAR(4),
    fir_name VARCHAR(64),
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

    -- Return intersecting ARTCCs
    RETURN QUERY SELECT * FROM get_route_artccs(route_geom);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_artccs_from_waypoints IS 'Returns ARTCCs traversed by waypoints array [{lon, lat}, ...]';

-- Function: Get sectors traversed at a specific altitude
CREATE OR REPLACE FUNCTION get_route_sectors(
    route_geom GEOMETRY,
    cruise_altitude INT,
    sector_type_filter VARCHAR(16) DEFAULT NULL
)
RETURNS TABLE (
    sector_code VARCHAR(16),
    sector_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    sector_type VARCHAR(16),
    traversal_order FLOAT
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        sb.sector_code,
        sb.sector_name,
        sb.parent_artcc,
        sb.sector_type,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, sb.geom))) AS traversal_order
    FROM sector_boundaries sb
    WHERE ST_Intersects(route_geom, sb.geom)
      AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
      AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= cruise_altitude)
      AND (sector_type_filter IS NULL OR sb.sector_type = sector_type_filter)
    ORDER BY traversal_order;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_sectors IS 'Returns sectors traversed by a route at specified altitude';

-- =============================================================================
-- PERFORMANCE STATS VIEW
-- =============================================================================

CREATE OR REPLACE VIEW boundary_stats AS
SELECT
    'artcc_boundaries' AS table_name,
    COUNT(*) AS row_count,
    pg_size_pretty(pg_total_relation_size('artcc_boundaries')) AS total_size,
    pg_size_pretty(pg_relation_size('artcc_boundaries')) AS data_size,
    pg_size_pretty(pg_indexes_size('artcc_boundaries')) AS index_size
FROM artcc_boundaries
UNION ALL
SELECT
    'sector_boundaries',
    COUNT(*),
    pg_size_pretty(pg_total_relation_size('sector_boundaries')),
    pg_size_pretty(pg_relation_size('sector_boundaries')),
    pg_size_pretty(pg_indexes_size('sector_boundaries'))
FROM sector_boundaries
UNION ALL
SELECT
    'tracon_boundaries',
    COUNT(*),
    pg_size_pretty(pg_total_relation_size('tracon_boundaries')),
    pg_size_pretty(pg_relation_size('tracon_boundaries')),
    pg_size_pretty(pg_indexes_size('tracon_boundaries'))
FROM tracon_boundaries;

-- =============================================================================
-- GRANT PERMISSIONS (adjust as needed)
-- =============================================================================

-- Create read-only role for web application
-- CREATE ROLE perti_readonly;
-- GRANT SELECT ON ALL TABLES IN SCHEMA public TO perti_readonly;
-- GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO perti_readonly;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
