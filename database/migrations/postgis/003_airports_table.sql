-- =============================================================================
-- VATSIM_GIS Airports Reference Table
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-29
-- =============================================================================
--
-- PURPOSE:
--   Minimal airports reference table for facility-inclusion lookups.
--   Used by analyze_tmi_route() to determine which ARTCC contains airports.
--
-- DATA SOURCE:
--   Import from VATSIM_REF.dbo.airports or external airport database
--
-- =============================================================================

-- Enable PostGIS extension (if not already enabled)
CREATE EXTENSION IF NOT EXISTS postgis;

-- =============================================================================
-- TABLE: airports
-- =============================================================================

DROP TABLE IF EXISTS airports CASCADE;

CREATE TABLE airports (
    airport_id      SERIAL PRIMARY KEY,
    icao_id         VARCHAR(4) NOT NULL,              -- ICAO code (e.g., KJFK, KLAX)
    iata_id         VARCHAR(3),                        -- IATA code (e.g., JFK, LAX)
    airport_name    VARCHAR(100),                      -- Full airport name
    lat             DECIMAL(9,6) NOT NULL,             -- Latitude
    lon             DECIMAL(10,6) NOT NULL,            -- Longitude
    elevation_ft    INT,                               -- Elevation in feet
    airport_type    VARCHAR(20),                       -- large_airport, medium_airport, small_airport
    country_code    VARCHAR(2),                        -- ISO country code
    region_code     VARCHAR(10),                       -- US state or region code
    parent_artcc    VARCHAR(4),                        -- Cached parent ARTCC (for fast lookups)
    parent_tracon   VARCHAR(16),                       -- Cached parent TRACON
    geom            GEOMETRY(Point, 4326)              -- Spatial geometry (auto-populated by trigger)
        GENERATED ALWAYS AS (ST_SetSRID(ST_MakePoint(lon, lat), 4326)) STORED,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Unique constraint on ICAO code
CREATE UNIQUE INDEX idx_airports_icao ON airports (icao_id);

-- Spatial index for efficient containment queries
CREATE INDEX idx_airports_geom ON airports USING GIST (geom);

-- Index on parent ARTCC for fast facility grouping
CREATE INDEX idx_airports_artcc ON airports (parent_artcc);

-- Index on country for regional filtering
CREATE INDEX idx_airports_country ON airports (country_code);

COMMENT ON TABLE airports IS 'Reference table for airport locations and ARTCC/TRACON assignments';

-- =============================================================================
-- TRIGGER: Auto-update parent_artcc on insert/update
-- =============================================================================

CREATE OR REPLACE FUNCTION update_airport_artcc()
RETURNS TRIGGER AS $$
BEGIN
    -- Find containing ARTCC (prefer non-oceanic, smallest)
    SELECT ab.artcc_code INTO NEW.parent_artcc
    FROM artcc_boundaries ab
    WHERE ST_Contains(ab.geom, ST_SetSRID(ST_MakePoint(NEW.lon, NEW.lat), 4326))
    ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
    LIMIT 1;

    -- Find containing TRACON
    SELECT tb.tracon_code INTO NEW.parent_tracon
    FROM tracon_boundaries tb
    WHERE ST_Contains(tb.geom, ST_SetSRID(ST_MakePoint(NEW.lon, NEW.lat), 4326))
    ORDER BY ST_Area(tb.geom)
    LIMIT 1;

    NEW.updated_at := NOW();
    RETURN NEW;
EXCEPTION WHEN undefined_table THEN
    -- Boundaries tables don't exist yet, skip assignment
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_airports_update_artcc
    BEFORE INSERT OR UPDATE OF lat, lon ON airports
    FOR EACH ROW
    EXECUTE FUNCTION update_airport_artcc();

-- =============================================================================
-- HELPER FUNCTION: Bulk update parent_artcc for all airports
-- Run after importing boundaries to populate cached values
-- =============================================================================

CREATE OR REPLACE FUNCTION refresh_airport_artccs()
RETURNS TABLE (
    updated_count INT,
    no_artcc_count INT
) AS $$
DECLARE
    v_updated INT := 0;
    v_no_artcc INT := 0;
BEGIN
    -- Update parent_artcc for all airports
    UPDATE airports apt
    SET parent_artcc = (
        SELECT ab.artcc_code
        FROM artcc_boundaries ab
        WHERE ST_Contains(ab.geom, apt.geom)
        ORDER BY ab.is_oceanic NULLS LAST, ST_Area(ab.geom)
        LIMIT 1
    ),
    parent_tracon = (
        SELECT tb.tracon_code
        FROM tracon_boundaries tb
        WHERE ST_Contains(tb.geom, apt.geom)
        ORDER BY ST_Area(tb.geom)
        LIMIT 1
    ),
    updated_at = NOW();

    GET DIAGNOSTICS v_updated = ROW_COUNT;

    SELECT COUNT(*) INTO v_no_artcc
    FROM airports WHERE parent_artcc IS NULL;

    RETURN QUERY SELECT v_updated, v_no_artcc;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION refresh_airport_artccs IS 'Refreshes parent_artcc and parent_tracon for all airports';

-- =============================================================================
-- HELPER FUNCTION: Get airports in a boundary
-- =============================================================================

CREATE OR REPLACE FUNCTION get_airports_in_artcc(
    p_artcc_code VARCHAR(4)
)
RETURNS TABLE (
    icao_id VARCHAR(4),
    airport_name VARCHAR(100),
    lat DECIMAL(9,6),
    lon DECIMAL(10,6)
) AS $$
BEGIN
    RETURN QUERY
    SELECT apt.icao_id, apt.airport_name, apt.lat, apt.lon
    FROM airports apt
    WHERE apt.parent_artcc = p_artcc_code
    ORDER BY apt.icao_id;
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION get_airports_in_tracon(
    p_tracon_code VARCHAR(16)
)
RETURNS TABLE (
    icao_id VARCHAR(4),
    airport_name VARCHAR(100),
    lat DECIMAL(9,6),
    lon DECIMAL(10,6)
) AS $$
BEGIN
    RETURN QUERY
    SELECT apt.icao_id, apt.airport_name, apt.lat, apt.lon
    FROM airports apt
    WHERE apt.parent_tracon = p_tracon_code
    ORDER BY apt.icao_id;
END;
$$ LANGUAGE plpgsql STABLE;

-- =============================================================================
-- SAMPLE DATA: Major US Airports (for testing)
-- Full data should be imported from VATSIM_REF or external source
-- =============================================================================

-- Uncomment to insert sample data for testing:
/*
INSERT INTO airports (icao_id, iata_id, airport_name, lat, lon, elevation_ft, airport_type, country_code, region_code) VALUES
('KJFK', 'JFK', 'John F Kennedy International', 40.639801, -73.778900, 13, 'large_airport', 'US', 'US-NY'),
('KLAX', 'LAX', 'Los Angeles International', 33.942501, -118.408997, 128, 'large_airport', 'US', 'US-CA'),
('KORD', 'ORD', 'Chicago O''Hare International', 41.978600, -87.904800, 672, 'large_airport', 'US', 'US-IL'),
('KDFW', 'DFW', 'Dallas Fort Worth International', 32.896900, -97.038002, 607, 'large_airport', 'US', 'US-TX'),
('KATL', 'ATL', 'Hartsfield Jackson Atlanta International', 33.636700, -84.428101, 1026, 'large_airport', 'US', 'US-GA'),
('KDEN', 'DEN', 'Denver International', 39.861698, -104.672997, 5431, 'large_airport', 'US', 'US-CO'),
('KMCO', 'MCO', 'Orlando International', 28.429399, -81.309000, 96, 'large_airport', 'US', 'US-FL'),
('KSFO', 'SFO', 'San Francisco International', 37.618999, -122.375000, 13, 'large_airport', 'US', 'US-CA'),
('KLAS', 'LAS', 'Harry Reid International', 36.080101, -115.152000, 2181, 'large_airport', 'US', 'US-NV'),
('KMIA', 'MIA', 'Miami International', 25.793200, -80.290604, 8, 'large_airport', 'US', 'US-FL'),
('KSEA', 'SEA', 'Seattle Tacoma International', 47.449001, -122.308998, 433, 'large_airport', 'US', 'US-WA'),
('KPHX', 'PHX', 'Phoenix Sky Harbor International', 33.437302, -112.007797, 1135, 'large_airport', 'US', 'US-AZ'),
('KBOS', 'BOS', 'General Edward Lawrence Logan International', 42.364300, -71.005203, 20, 'large_airport', 'US', 'US-MA'),
('KEWR', 'EWR', 'Newark Liberty International', 40.692501, -74.168701, 18, 'large_airport', 'US', 'US-NJ'),
('KLGA', 'LGA', 'LaGuardia', 40.777199, -73.872597, 21, 'large_airport', 'US', 'US-NY'),
('KIAD', 'IAD', 'Washington Dulles International', 38.944500, -77.455803, 313, 'large_airport', 'US', 'US-VA'),
('KDCA', 'DCA', 'Ronald Reagan Washington National', 38.852100, -77.037697, 15, 'large_airport', 'US', 'US-VA'),
('KPHL', 'PHL', 'Philadelphia International', 39.871899, -75.241096, 36, 'large_airport', 'US', 'US-PA'),
('KSTL', 'STL', 'St Louis Lambert International', 38.748697, -90.370003, 618, 'large_airport', 'US', 'US-MO'),
('KMSP', 'MSP', 'Minneapolis Saint Paul International', 44.882000, -93.221802, 841, 'large_airport', 'US', 'US-MN');
*/

-- =============================================================================
-- GRANTS
-- =============================================================================

-- Grant select on airports to GIS_admin
GRANT SELECT ON airports TO GIS_admin;
GRANT EXECUTE ON FUNCTION refresh_airport_artccs() TO GIS_admin;
GRANT EXECUTE ON FUNCTION get_airports_in_artcc(VARCHAR) TO GIS_admin;
GRANT EXECUTE ON FUNCTION get_airports_in_tracon(VARCHAR) TO GIS_admin;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
