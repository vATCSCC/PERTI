-- =============================================================================
-- VATSIM_GIS PostGIS Tier Validation
-- Compares GIS-computed proximity tiers against ADL manual tier mappings
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-30
-- =============================================================================
--
-- PURPOSE:
--   Validate that GIS-computed proximity tiers match the manually-configured
--   tier mappings in the ADL artcc_topology tables.
--
-- USAGE:
--   Run this script to generate a comparison report
--
-- =============================================================================

-- ADL Manual Tier 1 Configurations (extracted from 002_artcc_topology_seed.sql)
-- These are the expected 1st tier neighbors for each CONUS ARTCC
CREATE TEMP TABLE adl_tier1_expected (
    artcc_code VARCHAR(4),
    expected_neighbors TEXT[]
);

INSERT INTO adl_tier1_expected VALUES
    ('ZAB', ARRAY['ZLA', 'ZDV', 'ZKC', 'ZFW', 'ZHU']),
    ('ZAU', ARRAY['ZMP', 'ZKC', 'ZID', 'ZOB']),
    ('ZBW', ARRAY['ZDC', 'ZNY', 'ZOB']),
    ('ZDC', ARRAY['ZBW', 'ZNY', 'ZOB', 'ZID', 'ZTL', 'ZJX']),
    ('ZDV', ARRAY['ZLC', 'ZLA', 'ZAB', 'ZMP', 'ZKC']),
    ('ZFW', ARRAY['ZME', 'ZKC', 'ZAB', 'ZHU']),
    ('ZHU', ARRAY['ZAB', 'ZFW', 'ZME', 'ZTL', 'ZJX', 'ZMA']),
    ('ZID', ARRAY['ZAU', 'ZOB', 'ZDC', 'ZME', 'ZTL', 'ZKC']),
    ('ZJX', ARRAY['ZMA', 'ZHU', 'ZTL', 'ZDC']),
    ('ZKC', ARRAY['ZMP', 'ZAU', 'ZID', 'ZME', 'ZFW', 'ZAB', 'ZDV']),
    ('ZLA', ARRAY['ZLC', 'ZOA', 'ZDV', 'ZAB']),
    ('ZLC', ARRAY['ZDV', 'ZLA', 'ZMP', 'ZOA', 'ZSE']),
    ('ZMA', ARRAY['ZJX', 'ZHU']),
    ('ZME', ARRAY['ZTL', 'ZID', 'ZKC', 'ZFW', 'ZHU']),
    ('ZMP', ARRAY['ZAU', 'ZOB', 'ZKC', 'ZDV', 'ZLC']),
    ('ZNY', ARRAY['ZBW', 'ZDC', 'ZOB']),
    ('ZOA', ARRAY['ZLA', 'ZSE', 'ZLC']),
    ('ZOB', ARRAY['ZAU', 'ZMP', 'ZID', 'ZDC', 'ZNY', 'ZBW']),
    ('ZSE', ARRAY['ZOA', 'ZLC']),
    ('ZTL', ARRAY['ZID', 'ZDC', 'ZJX', 'ZME', 'ZHU']);

-- =============================================================================
-- COMPARISON QUERY: GIS Tier 1 vs ADL Tier 1
-- =============================================================================

WITH gis_tier1 AS (
    -- Get GIS-computed Tier 1 neighbors for each CONUS ARTCC
    -- Note: PostGIS uses ICAO codes (KZFW), ADL uses FAA codes (ZFW)
    SELECT
        SUBSTRING(pt.boundary_code, 2) AS artcc_code,  -- Strip 'K' prefix
        array_agg(
            CASE
                WHEN pt.boundary_type = 'ARTCC' AND pt.boundary_code LIKE 'KZ%'
                THEN SUBSTRING(pt.boundary_code, 2)
                ELSE pt.boundary_code
            END
            ORDER BY pt.boundary_code
        ) FILTER (WHERE pt.tier = 1 AND pt.boundary_type = 'ARTCC') AS gis_neighbors
    FROM artcc_boundaries ab
    CROSS JOIN LATERAL get_proximity_tiers('ARTCC', ab.artcc_code, 1.0, TRUE) pt
    WHERE ab.artcc_code LIKE 'KZ%'  -- US ARTCCs only
      AND ab.artcc_code NOT IN ('KZAN')  -- Exclude Alaska
      AND NOT COALESCE(ab.is_oceanic, FALSE)
    GROUP BY ab.artcc_code
),
comparison AS (
    SELECT
        adl.artcc_code,
        adl.expected_neighbors,
        COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[]) AS gis_neighbors,
        -- Convert to sorted arrays for comparison
        (SELECT array_agg(x ORDER BY x) FROM unnest(adl.expected_neighbors) x) AS adl_sorted,
        (SELECT array_agg(x ORDER BY x) FROM unnest(COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[])) x) AS gis_sorted
    FROM adl_tier1_expected adl
    LEFT JOIN gis_tier1 gis ON gis.artcc_code = adl.artcc_code
)
SELECT
    artcc_code,
    array_length(expected_neighbors, 1) AS adl_count,
    array_length(gis_neighbors, 1) AS gis_count,
    CASE
        WHEN adl_sorted = gis_sorted THEN 'MATCH'
        ELSE 'MISMATCH'
    END AS status,
    -- Show what ADL expects that GIS doesn't have
    (SELECT array_agg(x ORDER BY x)
     FROM unnest(expected_neighbors) x
     WHERE x NOT IN (SELECT unnest(gis_neighbors))) AS adl_only,
    -- Show what GIS found that ADL doesn't expect
    (SELECT array_agg(x ORDER BY x)
     FROM unnest(gis_neighbors) x
     WHERE x NOT IN (SELECT unnest(expected_neighbors))) AS gis_only,
    -- Full lists for reference
    array_to_string(adl_sorted, ', ') AS adl_neighbors,
    array_to_string(gis_sorted, ', ') AS gis_neighbors_list
FROM comparison
ORDER BY artcc_code;

-- =============================================================================
-- SUMMARY STATISTICS
-- =============================================================================

SELECT
    'VALIDATION SUMMARY' AS report,
    COUNT(*) AS total_artccs,
    SUM(CASE WHEN adl_sorted = gis_sorted THEN 1 ELSE 0 END) AS exact_matches,
    SUM(CASE WHEN adl_sorted != gis_sorted THEN 1 ELSE 0 END) AS mismatches,
    ROUND(100.0 * SUM(CASE WHEN adl_sorted = gis_sorted THEN 1 ELSE 0 END) / COUNT(*), 1) AS match_pct
FROM (
    SELECT
        adl.artcc_code,
        (SELECT array_agg(x ORDER BY x) FROM unnest(adl.expected_neighbors) x) AS adl_sorted,
        (SELECT array_agg(x ORDER BY x) FROM unnest(COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[])) x) AS gis_sorted
    FROM adl_tier1_expected adl
    LEFT JOIN (
        SELECT
            SUBSTRING(pt.boundary_code, 2) AS artcc_code,
            array_agg(
                CASE
                    WHEN pt.boundary_type = 'ARTCC' AND pt.boundary_code LIKE 'KZ%'
                    THEN SUBSTRING(pt.boundary_code, 2)
                    ELSE pt.boundary_code
                END
                ORDER BY pt.boundary_code
            ) FILTER (WHERE pt.tier = 1 AND pt.boundary_type = 'ARTCC') AS gis_neighbors
        FROM artcc_boundaries ab
        CROSS JOIN LATERAL get_proximity_tiers('ARTCC', ab.artcc_code, 1.0, TRUE) pt
        WHERE ab.artcc_code LIKE 'KZ%'
          AND ab.artcc_code NOT IN ('KZAN')
          AND NOT COALESCE(ab.is_oceanic, FALSE)
        GROUP BY ab.artcc_code
    ) gis ON gis.artcc_code = adl.artcc_code
) sub;

-- =============================================================================
-- DETAILED MISMATCH ANALYSIS
-- =============================================================================

-- Show which ARTCCs have differences and why
WITH gis_tier1 AS (
    SELECT
        SUBSTRING(pt.boundary_code, 2) AS artcc_code,
        array_agg(
            CASE
                WHEN pt.boundary_type = 'ARTCC' AND pt.boundary_code LIKE 'KZ%'
                THEN SUBSTRING(pt.boundary_code, 2)
                ELSE pt.boundary_code
            END
            ORDER BY pt.boundary_code
        ) FILTER (WHERE pt.tier = 1 AND pt.boundary_type = 'ARTCC') AS gis_neighbors
    FROM artcc_boundaries ab
    CROSS JOIN LATERAL get_proximity_tiers('ARTCC', ab.artcc_code, 1.0, TRUE) pt
    WHERE ab.artcc_code LIKE 'KZ%'
      AND ab.artcc_code NOT IN ('KZAN')
      AND NOT COALESCE(ab.is_oceanic, FALSE)
    GROUP BY ab.artcc_code
)
SELECT
    'MISMATCH DETAILS' AS report,
    adl.artcc_code,
    '---' AS separator,
    'ADL expects: ' || array_to_string(adl.expected_neighbors, ', ') AS adl_config,
    'GIS found: ' || array_to_string(COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[]), ', ') AS gis_computed,
    'Missing in GIS: ' || COALESCE(
        (SELECT array_to_string(array_agg(x ORDER BY x), ', ')
         FROM unnest(adl.expected_neighbors) x
         WHERE x NOT IN (SELECT unnest(COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[])))),
        'none'
    ) AS missing_in_gis,
    'Extra in GIS: ' || COALESCE(
        (SELECT array_to_string(array_agg(x ORDER BY x), ', ')
         FROM unnest(COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[])) x
         WHERE x NOT IN (SELECT unnest(adl.expected_neighbors))),
        'none'
    ) AS extra_in_gis
FROM adl_tier1_expected adl
LEFT JOIN gis_tier1 gis ON gis.artcc_code = adl.artcc_code
WHERE (SELECT array_agg(x ORDER BY x) FROM unnest(adl.expected_neighbors) x)
   != (SELECT array_agg(x ORDER BY x) FROM unnest(COALESCE(gis.gis_neighbors, ARRAY[]::TEXT[])) x)
ORDER BY adl.artcc_code;

-- =============================================================================
-- GIS EXTRA FINDINGS (International Adjacencies)
-- =============================================================================

-- Show what GIS finds that ADL doesn't track (Mexican FIRs, etc.)
WITH gis_all_tier1 AS (
    SELECT
        SUBSTRING(ab.artcc_code, 2) AS artcc_code,
        pt.boundary_code AS neighbor_code,
        pt.boundary_name AS neighbor_name,
        pt.adjacency_class
    FROM artcc_boundaries ab
    CROSS JOIN LATERAL get_proximity_tiers('ARTCC', ab.artcc_code, 1.0, FALSE) pt
    WHERE ab.artcc_code LIKE 'KZ%'
      AND ab.artcc_code NOT IN ('KZAN')
      AND NOT COALESCE(ab.is_oceanic, FALSE)
      AND pt.tier = 1
      AND pt.boundary_code NOT LIKE 'KZ%'  -- Non-US ARTCCs
)
SELECT
    'INTERNATIONAL ADJACENCIES (GIS BONUS)' AS report,
    artcc_code AS us_artcc,
    neighbor_code AS intl_neighbor,
    neighbor_name,
    adjacency_class
FROM gis_all_tier1
ORDER BY artcc_code, neighbor_code;

-- Cleanup
DROP TABLE IF EXISTS adl_tier1_expected;

-- =============================================================================
-- END VALIDATION
-- =============================================================================
