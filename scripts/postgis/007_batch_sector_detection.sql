-- =============================================================================
-- VATSIM_GIS Batch Sector Detection Functions
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Version: 1.0
-- Date: 2026-01-30
--
-- PURPOSE:
--   Batch sector detection for multiple flight positions.
--   Detects LOW, HIGH, and SUPERHIGH sectors based on flight altitude.
--   Designed to be called from boundary_gis_daemon.php
--
-- ALTITUDE THRESHOLDS (matching ADL):
--   LOW sectors: altitude < 24000 ft
--   HIGH sectors: 24000 <= altitude < 45000 ft
--   SUPERHIGH sectors: altitude >= 45000 ft
--
-- KEY FUNCTIONS:
--   1. detect_sectors_batch_optimized() - Batch sector detection
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. detect_sectors_batch_optimized - Batch sector detection for multiple flights
-- -----------------------------------------------------------------------------
-- Input: JSONB array of {flight_uid, lat, lon, altitude} objects
-- Output: flight_uid, sector_low, sector_high, sector_superhigh
--
-- Uses set-based operations for efficiency on large batches.
-- Returns all sectors containing each position (no altitude filtering).
-- NOTE: All boundaries treated as SFC to UNL until airspace volumes available.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_sectors_batch_optimized(
    p_flights JSONB
)
RETURNS TABLE (
    flight_uid BIGINT,
    lat DECIMAL(10,6),
    lon DECIMAL(11,6),
    altitude INT,
    sector_low VARCHAR(16),
    sector_low_name VARCHAR(64),
    sector_high VARCHAR(16),
    sector_high_name VARCHAR(64),
    sector_superhigh VARCHAR(16),
    sector_superhigh_name VARCHAR(64),
    parent_artcc VARCHAR(4)
) AS $$
BEGIN
    RETURN QUERY
    WITH flights AS (
        -- Parse input JSONB to rows with pre-computed geometry
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
    -- Find LOW sectors (smallest area - no altitude filter)
    low_sectors AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            sb.sector_code,
            sb.sector_name,
            sb.parent_artcc
        FROM flights f
        LEFT JOIN sector_boundaries sb ON
            sb.sector_type = 'LOW'
            AND ST_Contains(sb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(sb.geom) NULLS LAST
    ),
    -- Find HIGH sectors (smallest area - no altitude filter)
    high_sectors AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            sb.sector_code,
            sb.sector_name,
            sb.parent_artcc
        FROM flights f
        LEFT JOIN sector_boundaries sb ON
            sb.sector_type = 'HIGH'
            AND ST_Contains(sb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(sb.geom) NULLS LAST
    ),
    -- Find SUPERHIGH sectors (smallest area - no altitude filter)
    superhigh_sectors AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            sb.sector_code,
            sb.sector_name,
            sb.parent_artcc
        FROM flights f
        LEFT JOIN sector_boundaries sb ON
            sb.sector_type = 'SUPERHIGH'
            AND ST_Contains(sb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(sb.geom) NULLS LAST
    )
    SELECT
        f.fuid AS flight_uid,
        f.flat AS lat,
        f.flon AS lon,
        f.falt AS altitude,
        l.sector_code AS sector_low,
        l.sector_name AS sector_low_name,
        h.sector_code AS sector_high,
        h.sector_name AS sector_high_name,
        s.sector_code AS sector_superhigh,
        s.sector_name AS sector_superhigh_name,
        COALESCE(l.parent_artcc, h.parent_artcc, s.parent_artcc) AS parent_artcc
    FROM flights f
    LEFT JOIN low_sectors l ON f.fuid = l.fuid
    LEFT JOIN high_sectors h ON f.fuid = h.fuid
    LEFT JOIN superhigh_sectors s ON f.fuid = s.fuid;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION detect_sectors_batch_optimized IS 'Batch sector detection - all boundaries SFC to UNL';

-- -----------------------------------------------------------------------------
-- 2. detect_all_sectors_for_flight - Get all sectors at a point
-- -----------------------------------------------------------------------------
-- Returns all sectors (any tier) that contain the flight position.
-- NOTE: All boundaries treated as SFC to UNL (no altitude filtering).
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_all_sectors_for_flight(
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
    ORDER BY
        sb.sector_type,
        ST_Area(sb.geom);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION detect_all_sectors_for_flight IS 'Returns all sectors containing a point - all boundaries SFC to UNL';

-- -----------------------------------------------------------------------------
-- 3. Combined boundary + sector batch detection
-- -----------------------------------------------------------------------------
-- This function combines ARTCC/TRACON and sector detection in one call
-- for maximum efficiency. Returns all boundary information in one query.
-- NOTE: All boundaries treated as SFC to UNL (no altitude filtering)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_boundaries_and_sectors_batch(
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
    is_oceanic BOOLEAN,
    sector_low VARCHAR(16),
    sector_high VARCHAR(16),
    sector_superhigh VARCHAR(16),
    sector_strata VARCHAR(10)
) AS $$
BEGIN
    RETURN QUERY
    WITH flights AS (
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
    -- ARTCC detection (prefer non-oceanic, smallest area)
    artcc_matches AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            ab.artcc_code,
            ab.fir_name AS artcc_name,
            COALESCE(ab.is_oceanic, FALSE) AS is_oceanic
        FROM flights f
        LEFT JOIN artcc_boundaries ab ON ST_Contains(ab.geom, f.point_geom)
        ORDER BY f.fuid, COALESCE(ab.is_oceanic, FALSE), ST_Area(ab.geom) NULLS LAST
    ),
    -- TRACON detection (smallest area - no altitude filter)
    tracon_matches AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            tb.tracon_code,
            tb.tracon_name
        FROM flights f
        LEFT JOIN tracon_boundaries tb ON ST_Contains(tb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(tb.geom) NULLS LAST
    ),
    -- LOW sector detection (smallest area - no altitude filter)
    low_sectors AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            sb.sector_code
        FROM flights f
        LEFT JOIN sector_boundaries sb ON
            sb.sector_type = 'LOW'
            AND ST_Contains(sb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(sb.geom) NULLS LAST
    ),
    -- HIGH sector detection (smallest area - no altitude filter)
    high_sectors AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            sb.sector_code
        FROM flights f
        LEFT JOIN sector_boundaries sb ON
            sb.sector_type = 'HIGH'
            AND ST_Contains(sb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(sb.geom) NULLS LAST
    ),
    -- SUPERHIGH sector detection (smallest area - no altitude filter)
    superhigh_sectors AS (
        SELECT DISTINCT ON (f.fuid)
            f.fuid,
            sb.sector_code
        FROM flights f
        LEFT JOIN sector_boundaries sb ON
            sb.sector_type = 'SUPERHIGH'
            AND ST_Contains(sb.geom, f.point_geom)
        ORDER BY f.fuid, ST_Area(sb.geom) NULLS LAST
    )
    SELECT
        f.fuid AS flight_uid,
        f.flat AS lat,
        f.flon AS lon,
        f.falt AS altitude,
        a.artcc_code,
        a.artcc_name,
        t.tracon_code,
        t.tracon_name,
        a.is_oceanic,
        l.sector_code AS sector_low,
        h.sector_code AS sector_high,
        s.sector_code AS sector_superhigh,
        (CASE
            WHEN f.falt < 24000 THEN 'LOW'
            WHEN f.falt < 45000 THEN 'HIGH'
            ELSE 'SUPERHIGH'
        END)::VARCHAR(10) AS sector_strata
    FROM flights f
    LEFT JOIN artcc_matches a ON f.fuid = a.fuid
    LEFT JOIN tracon_matches t ON f.fuid = t.fuid
    LEFT JOIN low_sectors l ON f.fuid = l.fuid
    LEFT JOIN high_sectors h ON f.fuid = h.fuid
    LEFT JOIN superhigh_sectors s ON f.fuid = s.fuid;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION detect_boundaries_and_sectors_batch IS 'Combined ARTCC/TRACON/Sector detection - all boundaries SFC to UNL';

-- =============================================================================
-- GRANT PERMISSIONS (adjust as needed)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION detect_sectors_batch_optimized TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION detect_all_sectors_for_flight TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION detect_boundaries_and_sectors_batch TO gis_readonly;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
