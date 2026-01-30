-- =============================================================================
-- VATSIM_GIS Batch Boundary Detection Functions
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Version: 1.0
-- Date: 2026-01-30
--
-- PURPOSE:
--   Batch boundary detection for multiple flight positions.
--   Replaces ADL's sp_ProcessBoundaryDetectionBatch with PostGIS operations.
--   Designed to be called from boundary_gis_daemon.php
--
-- KEY FUNCTIONS:
--   1. detect_boundaries_batch() - Process multiple flights at once
--   2. get_artcc_at_point() - Single point ARTCC lookup (optimized)
--
-- PERFORMANCE:
--   - Uses GIST spatial index for O(log n) lookups
--   - Batch processing reduces round-trips
--   - Estimated: 1000 flights in <500ms on B2s tier
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. get_artcc_at_point - Optimized single-point ARTCC lookup
-- -----------------------------------------------------------------------------
-- Returns the ARTCC containing a point (prefers non-oceanic, smallest area)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_artcc_at_point(
    p_lat DECIMAL(10,6),
    p_lon DECIMAL(11,6)
)
RETURNS TABLE (
    artcc_code VARCHAR(4),
    artcc_name VARCHAR(64),
    is_oceanic BOOLEAN
) AS $$
DECLARE
    point_geom GEOMETRY;
BEGIN
    point_geom := ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326);

    RETURN QUERY
    SELECT
        ab.artcc_code,
        ab.fir_name,
        COALESCE(ab.is_oceanic, FALSE)
    FROM artcc_boundaries ab
    WHERE ST_Contains(ab.geom, point_geom)
    ORDER BY
        COALESCE(ab.is_oceanic, FALSE),  -- Prefer non-oceanic
        ST_Area(ab.geom)                  -- Then smallest area (most specific)
    LIMIT 1;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_artcc_at_point IS 'Returns the ARTCC containing a geographic point';

-- -----------------------------------------------------------------------------
-- 2. detect_boundaries_batch - Batch boundary detection for multiple flights
-- -----------------------------------------------------------------------------
-- Input: JSONB array of {flight_uid, lat, lon, altitude} objects
-- Output: flight_uid, artcc_code, artcc_name, tracon_code (if applicable)
--
-- Example input:
--   [
--     {"flight_uid": 12345, "lat": 32.897, "lon": -97.038, "altitude": 35000},
--     {"flight_uid": 12346, "lat": 28.429, "lon": -81.309, "altitude": 38000}
--   ]
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_boundaries_batch(
    p_flights JSONB
)
RETURNS TABLE (
    flight_uid BIGINT,
    lat DECIMAL(10,6),
    lon DECIMAL(11,6),
    altitude INT,
    artcc_code VARCHAR(4),
    artcc_name VARCHAR(64),
    tracon_code VARCHAR(16),
    tracon_name VARCHAR(64),
    is_oceanic BOOLEAN
) AS $$
DECLARE
    v_flight RECORD;
    v_point GEOMETRY;
    v_artcc RECORD;
    v_tracon RECORD;
BEGIN
    -- Process each flight
    FOR v_flight IN
        SELECT
            (f->>'flight_uid')::BIGINT AS fuid,
            (f->>'lat')::DECIMAL(10,6) AS flat,
            (f->>'lon')::DECIMAL(11,6) AS flon,
            COALESCE((f->>'altitude')::INT, 0) AS falt
        FROM jsonb_array_elements(p_flights) AS f
    LOOP
        -- Create point geometry
        v_point := ST_SetSRID(ST_MakePoint(v_flight.flon, v_flight.flat), 4326);

        -- Initialize output row
        flight_uid := v_flight.fuid;
        lat := v_flight.flat;
        lon := v_flight.flon;
        altitude := v_flight.falt;
        artcc_code := NULL;
        artcc_name := NULL;
        tracon_code := NULL;
        tracon_name := NULL;
        is_oceanic := FALSE;

        -- Find containing ARTCC (prefer non-oceanic, smallest area)
        SELECT ab.artcc_code, ab.fir_name, COALESCE(ab.is_oceanic, FALSE)
        INTO v_artcc
        FROM artcc_boundaries ab
        WHERE ST_Contains(ab.geom, v_point)
        ORDER BY COALESCE(ab.is_oceanic, FALSE), ST_Area(ab.geom)
        LIMIT 1;

        IF v_artcc.artcc_code IS NOT NULL THEN
            artcc_code := v_artcc.artcc_code;
            artcc_name := v_artcc.fir_name;
            is_oceanic := v_artcc.is_oceanic;
        END IF;

        -- Find containing TRACON (no altitude filter - all boundaries SFC to UNL)
        SELECT tb.tracon_code, tb.tracon_name
        INTO v_tracon
        FROM tracon_boundaries tb
        WHERE ST_Contains(tb.geom, v_point)
        ORDER BY ST_Area(tb.geom)
        LIMIT 1;

        IF v_tracon.tracon_code IS NOT NULL THEN
            tracon_code := v_tracon.tracon_code;
            tracon_name := v_tracon.tracon_name;
        END IF;

        RETURN NEXT;
    END LOOP;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION detect_boundaries_batch IS 'Batch boundary detection for multiple flights - returns ARTCC and TRACON for each position';

-- -----------------------------------------------------------------------------
-- 3. detect_boundaries_batch_optimized - Set-based version (faster for large batches)
-- -----------------------------------------------------------------------------
-- Uses set operations instead of row-by-row processing
-- Better performance for batches > 100 flights
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_boundaries_batch_optimized(
    p_flights JSONB
)
RETURNS TABLE (
    flight_uid BIGINT,
    lat DECIMAL(10,6),
    lon DECIMAL(11,6),
    altitude INT,
    artcc_code VARCHAR(4),
    artcc_name VARCHAR(64),
    tracon_code VARCHAR(16),
    tracon_name VARCHAR(64),
    is_oceanic BOOLEAN
) AS $$
BEGIN
    RETURN QUERY
    WITH flights AS (
        -- Parse input JSONB to rows
        SELECT
            (f->>'flight_uid')::BIGINT AS fuid,
            (f->>'lat')::DECIMAL(10,6) AS flat,
            (f->>'lon')::DECIMAL(11,6) AS flon,
            COALESCE((f->>'altitude')::INT, 0) AS falt,
            ST_SetSRID(ST_MakePoint(
                (f->>'lon')::FLOAT,
                (f->>'lat')::FLOAT
            ), 4326) AS point_geom
        FROM jsonb_array_elements(p_flights) AS f
    ),
    artcc_matches AS (
        -- Find ARTCC for each flight (with ranking for best match)
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            f.flat,
            f.flon,
            f.falt,
            ab.artcc_code,
            ab.fir_name AS artcc_name,
            COALESCE(ab.is_oceanic, FALSE) AS is_oceanic
        FROM flights f
        LEFT JOIN artcc_boundaries ab ON ST_Contains(ab.geom, f.point_geom)
        ORDER BY f.fuid, COALESCE(ab.is_oceanic, FALSE), ST_Area(ab.geom) NULLS LAST
    ),
    tracon_matches AS (
        -- Find TRACON (no altitude filter - all boundaries SFC to UNL)
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            tb.tracon_code,
            tb.tracon_name
        FROM flights f
        LEFT JOIN tracon_boundaries tb ON ST_Contains(tb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(tb.geom) NULLS LAST
    )
    SELECT
        a.fuid AS flight_uid,
        a.flat AS lat,
        a.flon AS lon,
        a.falt AS altitude,
        a.artcc_code,
        a.artcc_name,
        t.tracon_code,
        t.tracon_name,
        a.is_oceanic
    FROM artcc_matches a
    LEFT JOIN tracon_matches t ON a.fuid = t.fuid;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION detect_boundaries_batch_optimized IS 'Set-based batch boundary detection - optimized for large batches';

-- -----------------------------------------------------------------------------
-- 4. detect_sector_for_flight - Get all sectors containing a flight position
-- -----------------------------------------------------------------------------
-- Returns all sectors (LOW, HIGH, SUPERHIGH) at the position.
-- NOTE: All boundaries treated as SFC to UNL (no altitude filtering).
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_sector_for_flight(
    p_lat DECIMAL(10,6),
    p_lon DECIMAL(11,6),
    p_altitude INT DEFAULT 0
)
RETURNS TABLE (
    sector_code VARCHAR(16),
    sector_name VARCHAR(64),
    parent_artcc VARCHAR(4),
    sector_type VARCHAR(16),
    floor_altitude INT,
    ceiling_altitude INT
) AS $$
DECLARE
    point_geom GEOMETRY;
BEGIN
    point_geom := ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326);

    RETURN QUERY
    SELECT
        sb.sector_code,
        sb.sector_name,
        sb.parent_artcc,
        sb.sector_type,
        sb.floor_altitude,
        sb.ceiling_altitude
    FROM sector_boundaries sb
    WHERE ST_Contains(sb.geom, point_geom)
    ORDER BY sb.sector_type, ST_Area(sb.geom);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION detect_sector_for_flight IS 'Returns all sectors containing a point - all boundaries SFC to UNL';

-- =============================================================================
-- PERFORMANCE INDEXES (ensure they exist)
-- =============================================================================
-- These should already exist from 001_boundaries_schema.sql but verify:
CREATE INDEX IF NOT EXISTS idx_artcc_boundaries_geom ON artcc_boundaries USING GIST (geom);
CREATE INDEX IF NOT EXISTS idx_sector_boundaries_geom ON sector_boundaries USING GIST (geom);
CREATE INDEX IF NOT EXISTS idx_tracon_boundaries_geom ON tracon_boundaries USING GIST (geom);

-- =============================================================================
-- GRANT PERMISSIONS (adjust as needed)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION get_artcc_at_point TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION detect_boundaries_batch TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION detect_boundaries_batch_optimized TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION detect_sector_for_flight TO gis_readonly;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
