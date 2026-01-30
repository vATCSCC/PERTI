-- =============================================================================
-- VATSIM_GIS PostGIS Proximity Tiers
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-30
-- =============================================================================
--
-- PURPOSE:
--   Compute proximity tiers from a given boundary using BFS on adjacency network.
--   - Tier 0: Self (the origin boundary)
--   - Tier 1: Direct neighbors with LINE adjacency (shared border)
--   - Tier 1.5: Direct neighbors with POINT adjacency (corner touch only)
--   - Tier 2: Neighbors of Tier 1 boundaries (via LINE)
--   - Tier 2.5: Neighbors via POINT, or LINE neighbors of Tier 1.5
--   - etc.
--
-- USAGE:
--   SELECT * FROM get_proximity_tiers('ARTCC', 'ZFW', 5);
--   SELECT * FROM get_proximity_tiers('ARTCC', 'ZFW', 3, TRUE);  -- same type only
--
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Function: Get proximity tiers from a boundary
-- Uses BFS with half-tier increments for POINT (corner) adjacencies
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_proximity_tiers(
    p_boundary_type VARCHAR(20),
    p_boundary_code VARCHAR(50),
    p_max_tier FLOAT DEFAULT 5.0,
    p_same_type_only BOOLEAN DEFAULT FALSE
)
RETURNS TABLE (
    tier FLOAT,
    boundary_type VARCHAR(20),
    boundary_code VARCHAR(50),
    boundary_name VARCHAR(64),
    adjacency_from VARCHAR(50),      -- Which boundary we reached this from
    adjacency_class VARCHAR(10)      -- How we reached it (LINE or POINT)
) AS $$
DECLARE
    current_tier FLOAT := 0;
    found_new BOOLEAN := TRUE;
BEGIN
    -- Create temp table to track visited boundaries and their tiers
    CREATE TEMP TABLE IF NOT EXISTS _proximity_visited (
        boundary_type VARCHAR(20),
        boundary_code VARCHAR(50),
        boundary_name VARCHAR(64),
        tier FLOAT,
        adjacency_from VARCHAR(50),
        adjacency_class VARCHAR(10),
        PRIMARY KEY (boundary_type, boundary_code)
    ) ON COMMIT DROP;

    -- Clear any previous data
    TRUNCATE _proximity_visited;

    -- Insert origin as Tier 0
    INSERT INTO _proximity_visited (boundary_type, boundary_code, boundary_name, tier, adjacency_from, adjacency_class)
    SELECT
        p_boundary_type,
        p_boundary_code,
        COALESCE(
            (SELECT fir_name FROM artcc_boundaries WHERE artcc_code = p_boundary_code AND p_boundary_type = 'ARTCC'),
            (SELECT tracon_name FROM tracon_boundaries WHERE tracon_code = p_boundary_code AND p_boundary_type = 'TRACON'),
            (SELECT sector_name FROM sector_boundaries WHERE sector_code = p_boundary_code AND p_boundary_type LIKE 'SECTOR_%'),
            p_boundary_code
        ),
        0,
        NULL,
        NULL;

    -- BFS loop
    WHILE found_new AND current_tier < p_max_tier LOOP
        found_new := FALSE;

        -- Find LINE adjacencies from current whole-tier boundaries (adds +1 tier)
        INSERT INTO _proximity_visited (boundary_type, boundary_code, boundary_name, tier, adjacency_from, adjacency_class)
        SELECT DISTINCT ON (ba.target_type, ba.target_code)
            ba.target_type,
            ba.target_code,
            ba.target_name,
            current_tier + 1.0,
            ba.source_code,
            'LINE'
        FROM boundary_adjacency ba
        JOIN _proximity_visited pv ON pv.boundary_type = ba.source_type
                                   AND pv.boundary_code = ba.source_code
        WHERE pv.tier = current_tier  -- Only from whole tiers
          AND ba.adjacency_class = 'LINE'
          AND NOT EXISTS (
              SELECT 1 FROM _proximity_visited v2
              WHERE v2.boundary_type = ba.target_type
                AND v2.boundary_code = ba.target_code
          )
          AND (NOT p_same_type_only OR ba.target_type = p_boundary_type)
          AND current_tier + 1.0 <= p_max_tier
        ORDER BY ba.target_type, ba.target_code, ba.shared_length_nm DESC NULLS LAST
        ON CONFLICT DO NOTHING;

        IF FOUND THEN found_new := TRUE; END IF;

        -- Find POINT adjacencies from current whole-tier boundaries (adds +0.5 tier)
        INSERT INTO _proximity_visited (boundary_type, boundary_code, boundary_name, tier, adjacency_from, adjacency_class)
        SELECT DISTINCT ON (ba.target_type, ba.target_code)
            ba.target_type,
            ba.target_code,
            ba.target_name,
            current_tier + 0.5,
            ba.source_code,
            'POINT'
        FROM boundary_adjacency ba
        JOIN _proximity_visited pv ON pv.boundary_type = ba.source_type
                                   AND pv.boundary_code = ba.source_code
        WHERE pv.tier = current_tier  -- Only from whole tiers
          AND ba.adjacency_class = 'POINT'
          AND NOT EXISTS (
              SELECT 1 FROM _proximity_visited v2
              WHERE v2.boundary_type = ba.target_type
                AND v2.boundary_code = ba.target_code
          )
          AND (NOT p_same_type_only OR ba.target_type = p_boundary_type)
          AND current_tier + 0.5 <= p_max_tier
        ORDER BY ba.target_type, ba.target_code
        ON CONFLICT DO NOTHING;

        IF FOUND THEN found_new := TRUE; END IF;

        -- Find LINE adjacencies from half-tier boundaries (adds +0.5 to make whole tier)
        INSERT INTO _proximity_visited (boundary_type, boundary_code, boundary_name, tier, adjacency_from, adjacency_class)
        SELECT DISTINCT ON (ba.target_type, ba.target_code)
            ba.target_type,
            ba.target_code,
            ba.target_name,
            current_tier + 1.0,  -- 0.5 + 0.5 = 1.0 from origin perspective
            ba.source_code,
            'LINE'
        FROM boundary_adjacency ba
        JOIN _proximity_visited pv ON pv.boundary_type = ba.source_type
                                   AND pv.boundary_code = ba.source_code
        WHERE pv.tier = current_tier + 0.5  -- From half-tier
          AND ba.adjacency_class = 'LINE'
          AND NOT EXISTS (
              SELECT 1 FROM _proximity_visited v2
              WHERE v2.boundary_type = ba.target_type
                AND v2.boundary_code = ba.target_code
          )
          AND (NOT p_same_type_only OR ba.target_type = p_boundary_type)
          AND current_tier + 1.0 <= p_max_tier
        ORDER BY ba.target_type, ba.target_code, ba.shared_length_nm DESC NULLS LAST
        ON CONFLICT DO NOTHING;

        IF FOUND THEN found_new := TRUE; END IF;

        current_tier := current_tier + 1.0;
    END LOOP;

    -- Return results (use aliases to avoid conflict with RETURNS TABLE column names)
    RETURN QUERY
    SELECT
        pv.tier::FLOAT AS tier,
        pv.boundary_type::VARCHAR(20) AS boundary_type,
        pv.boundary_code::VARCHAR(50) AS boundary_code,
        pv.boundary_name::VARCHAR(64) AS boundary_name,
        pv.adjacency_from::VARCHAR(50) AS adjacency_from,
        pv.adjacency_class::VARCHAR(10) AS adjacency_class
    FROM _proximity_visited pv
    ORDER BY pv.tier, pv.boundary_type, pv.boundary_code;

    -- Cleanup
    DROP TABLE IF EXISTS _proximity_visited;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_proximity_tiers IS 'Get all boundaries within N tiers of a given boundary. LINE adjacencies = whole tier, POINT adjacencies = half tier.';

-- -----------------------------------------------------------------------------
-- Function: Get proximity tier between two specific boundaries
-- Returns the tier distance, or NULL if not reachable within max_tier
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_proximity_distance(
    p_source_type VARCHAR(20),
    p_source_code VARCHAR(50),
    p_target_type VARCHAR(20),
    p_target_code VARCHAR(50),
    p_max_tier FLOAT DEFAULT 10.0,
    p_same_type_only BOOLEAN DEFAULT FALSE
)
RETURNS FLOAT AS $$
DECLARE
    result_tier FLOAT;
BEGIN
    SELECT tier INTO result_tier
    FROM get_proximity_tiers(p_source_type, p_source_code, p_max_tier, p_same_type_only)
    WHERE boundary_type = p_target_type
      AND boundary_code = p_target_code;

    RETURN result_tier;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_proximity_distance IS 'Get the proximity tier distance between two boundaries';

-- -----------------------------------------------------------------------------
-- Function: Get boundaries at a specific tier (or tier range)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_boundaries_at_tier(
    p_boundary_type VARCHAR(20),
    p_boundary_code VARCHAR(50),
    p_tier_min FLOAT,
    p_tier_max FLOAT DEFAULT NULL,
    p_same_type_only BOOLEAN DEFAULT FALSE
)
RETURNS TABLE (
    tier FLOAT,
    boundary_type VARCHAR(20),
    boundary_code VARCHAR(50),
    boundary_name VARCHAR(64),
    adjacency_class VARCHAR(10)
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        pt.tier,
        pt.boundary_type,
        pt.boundary_code,
        pt.boundary_name,
        pt.adjacency_class
    FROM get_proximity_tiers(
        p_boundary_type,
        p_boundary_code,
        COALESCE(p_tier_max, p_tier_min),
        p_same_type_only
    ) pt
    WHERE pt.tier >= p_tier_min
      AND pt.tier <= COALESCE(p_tier_max, p_tier_min);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_boundaries_at_tier IS 'Get boundaries at a specific tier or tier range from origin';

-- -----------------------------------------------------------------------------
-- Function: Get tier summary (count per tier)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_proximity_summary(
    p_boundary_type VARCHAR(20),
    p_boundary_code VARCHAR(50),
    p_max_tier FLOAT DEFAULT 5.0,
    p_same_type_only BOOLEAN DEFAULT FALSE
)
RETURNS TABLE (
    tier FLOAT,
    boundary_count INT,
    boundary_codes TEXT[]
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        pt.tier,
        COUNT(*)::INT AS boundary_count,
        array_agg(pt.boundary_code ORDER BY pt.boundary_code) AS boundary_codes
    FROM get_proximity_tiers(p_boundary_type, p_boundary_code, p_max_tier, p_same_type_only) pt
    GROUP BY pt.tier
    ORDER BY pt.tier;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION get_proximity_summary IS 'Get summary of boundary counts per tier';

-- -----------------------------------------------------------------------------
-- View: ARTCC proximity matrix (precomputed for common queries)
-- This is expensive to materialize but useful for repeated lookups
-- -----------------------------------------------------------------------------
-- CREATE MATERIALIZED VIEW IF NOT EXISTS artcc_proximity_matrix AS
-- WITH RECURSIVE tiers AS (
--     SELECT
--         a.artcc_code AS origin,
--         a.artcc_code AS target,
--         0::FLOAT AS tier
--     FROM artcc_boundaries a
--     WHERE NOT COALESCE(a.is_oceanic, FALSE)
--
--     UNION ALL
--
--     ... recursive CTE for all pairs
-- )
-- SELECT * FROM tiers;
--
-- Note: Materialized view is optional - use get_proximity_tiers() for on-demand queries

-- =============================================================================
-- GRANT PERMISSIONS (adjust as needed)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION get_proximity_tiers TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_proximity_distance TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_boundaries_at_tier TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_proximity_summary TO gis_readonly;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
