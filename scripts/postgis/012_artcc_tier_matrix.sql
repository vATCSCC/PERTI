-- =============================================================================
-- VATSIM_GIS: ARTCC Tier Matrix Materialized View
-- Precomputes proximity tiers for all US ARTCCs for instant lookups
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-30
-- =============================================================================
--
-- PURPOSE:
--   Precompute tier 0-2 relationships for all US ARTCCs to avoid repeated
--   BFS traversal during GDT scope builder and other tier queries.
--   Reduces ~4.5s (20 ARTCCs × 220ms) to ~10ms single table scan.
--
-- USAGE:
--   SELECT * FROM artcc_tier_matrix WHERE origin_code = 'KZFW' AND tier <= 2;
--   SELECT * FROM get_artcc_tier_matrix('KZFW', 2.0);  -- Function wrapper
--   REFRESH MATERIALIZED VIEW CONCURRENTLY artcc_tier_matrix;  -- After boundary changes
--
-- =============================================================================

-- Drop existing if recreating
DROP MATERIALIZED VIEW IF EXISTS artcc_tier_matrix;

-- =============================================================================
-- Materialized View: ARTCC Tier Matrix
-- Precomputes all tier relationships for US ARTCCs (tier 0 through 3)
-- =============================================================================
CREATE MATERIALIZED VIEW artcc_tier_matrix AS
WITH RECURSIVE tier_expansion AS (
    -- Tier 0: Self (all US ARTCCs)
    SELECT
        ab.artcc_code AS origin_code,
        ab.artcc_code AS neighbor_code,
        COALESCE(ab.fir_name, ab.artcc_code) AS neighbor_name,
        0::FLOAT AS tier,
        NULL::VARCHAR(50) AS adjacency_from,
        NULL::VARCHAR(10) AS adjacency_class
    FROM artcc_boundaries ab
    WHERE ab.artcc_code LIKE 'KZ%'
      AND ab.artcc_code NOT IN ('KZAN')  -- Exclude Alaska (oceanic)
      AND NOT COALESCE(ab.is_oceanic, FALSE)

    UNION ALL

    -- Tier N+1: Expand via boundary_adjacency
    SELECT
        te.origin_code,
        ba.target_code AS neighbor_code,
        COALESCE(ab2.fir_name, ba.target_code) AS neighbor_name,
        te.tier + CASE
            WHEN ba.adjacency_class = 'LINE' THEN 1.0
            WHEN ba.adjacency_class = 'POINT' THEN 0.5
            ELSE 1.0
        END AS tier,
        te.neighbor_code AS adjacency_from,
        ba.adjacency_class
    FROM tier_expansion te
    JOIN boundary_adjacency ba
        ON ba.source_type = 'ARTCC'
        AND ba.source_code = te.neighbor_code
        AND ba.target_type = 'ARTCC'
    LEFT JOIN artcc_boundaries ab2 ON ab2.artcc_code = ba.target_code
    WHERE te.tier < 3  -- Expand up to tier 3
      AND ba.target_code != te.origin_code  -- Don't loop back to origin
      AND NOT EXISTS (
          -- Don't revisit already-visited neighbors at a lower tier
          SELECT 1 FROM tier_expansion te2
          WHERE te2.origin_code = te.origin_code
            AND te2.neighbor_code = ba.target_code
            AND te2.tier <= te.tier
      )
)
SELECT DISTINCT ON (origin_code, neighbor_code)
    origin_code,
    neighbor_code,
    neighbor_name,
    tier,
    adjacency_from,
    adjacency_class
FROM tier_expansion
ORDER BY origin_code, neighbor_code, tier;

-- Create indexes for fast lookups
CREATE UNIQUE INDEX idx_artcc_tier_matrix_pk
    ON artcc_tier_matrix (origin_code, neighbor_code);

CREATE INDEX idx_artcc_tier_matrix_origin
    ON artcc_tier_matrix (origin_code, tier);

CREATE INDEX idx_artcc_tier_matrix_neighbor
    ON artcc_tier_matrix (neighbor_code);

-- Add comment
COMMENT ON MATERIALIZED VIEW artcc_tier_matrix IS
    'Precomputed ARTCC proximity tiers (0-3) for instant lookups. Refresh after boundary changes.';

-- =============================================================================
-- Function: Get ARTCC tier matrix for a single ARTCC
-- Drop-in replacement for get_proximity_tiers() when only ARTCC-to-ARTCC needed
-- =============================================================================
CREATE OR REPLACE FUNCTION get_artcc_tier_matrix(
    p_artcc_code VARCHAR(10),
    p_max_tier FLOAT DEFAULT 3.0,
    p_us_only BOOLEAN DEFAULT TRUE
) RETURNS TABLE (
    tier FLOAT,
    boundary_type VARCHAR(20),
    boundary_code VARCHAR(50),
    boundary_name VARCHAR(64),
    adjacency_from VARCHAR(50),
    adjacency_class VARCHAR(10)
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        m.tier,
        'ARTCC'::VARCHAR(20) AS boundary_type,
        m.neighbor_code::VARCHAR(50) AS boundary_code,
        m.neighbor_name::VARCHAR(64) AS boundary_name,
        m.adjacency_from::VARCHAR(50),
        m.adjacency_class::VARCHAR(10)
    FROM artcc_tier_matrix m
    WHERE m.origin_code = UPPER(p_artcc_code)
      AND m.tier <= p_max_tier
      AND (NOT p_us_only OR m.neighbor_code LIKE 'KZ%')
    ORDER BY m.tier, m.neighbor_code;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_artcc_tier_matrix IS
    'Fast ARTCC tier lookup using precomputed materialized view';

-- =============================================================================
-- Function: Get all US ARTCC tiers in one query (for GDT bulk export)
-- Returns tier data for all 20 CONUS ARTCCs at once
-- =============================================================================
CREATE OR REPLACE FUNCTION get_all_artcc_tiers(
    p_max_tier FLOAT DEFAULT 2.0
) RETURNS TABLE (
    origin_code VARCHAR(10),
    tier FLOAT,
    neighbor_code VARCHAR(50),
    neighbor_name VARCHAR(64)
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        m.origin_code::VARCHAR(10),
        m.tier,
        m.neighbor_code::VARCHAR(50),
        m.neighbor_name::VARCHAR(64)
    FROM artcc_tier_matrix m
    WHERE m.origin_code LIKE 'KZ%'
      AND m.neighbor_code LIKE 'KZ%'  -- US only
      AND m.tier <= p_max_tier
    ORDER BY m.origin_code, m.tier, m.neighbor_code;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_all_artcc_tiers IS
    'Bulk export all US ARTCC tiers for GDT scope builder';

-- =============================================================================
-- Function: Refresh tier matrix (call after boundary changes)
-- =============================================================================
CREATE OR REPLACE FUNCTION refresh_artcc_tier_matrix()
RETURNS TEXT AS $$
DECLARE
    start_time TIMESTAMP;
    row_count INT;
BEGIN
    start_time := clock_timestamp();

    REFRESH MATERIALIZED VIEW CONCURRENTLY artcc_tier_matrix;

    SELECT count(*) INTO row_count FROM artcc_tier_matrix;

    RETURN format('Refreshed artcc_tier_matrix: %s rows in %s ms',
        row_count,
        round(EXTRACT(EPOCH FROM (clock_timestamp() - start_time)) * 1000)
    );
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION refresh_artcc_tier_matrix IS
    'Refresh the ARTCC tier matrix after boundary or adjacency changes';

-- =============================================================================
-- Verification Query
-- =============================================================================
SELECT
    'artcc_tier_matrix' AS view_name,
    count(*) AS total_rows,
    count(DISTINCT origin_code) AS artcc_count,
    max(tier) AS max_tier
FROM artcc_tier_matrix;

-- Sample output for ZFW
SELECT origin_code, tier, array_agg(neighbor_code ORDER BY neighbor_code) AS neighbors
FROM artcc_tier_matrix
WHERE origin_code = 'KZFW' AND tier <= 2
GROUP BY origin_code, tier
ORDER BY tier;
