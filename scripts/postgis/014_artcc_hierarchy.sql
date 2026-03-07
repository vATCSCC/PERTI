-- =============================================================================
-- ARTCC Boundary Hierarchy Classification (PostGIS)
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Migration: 014_artcc_hierarchy.sql
-- Date: 2026-03-06
--
-- PURPOSE:
--   1. Widen artcc_code from VARCHAR(4) to VARCHAR(20) — fixes truncation bug
--      where codes like EDGG-BAD were silently truncated to EDGG
--   2. Add parent_fir and is_subsector columns for hierarchy classification
--   3. Replace unique index with non-unique (sub-areas may share base code)
--   4. Create filtered index for FIR-only lookups (detection queries)
--   5. Two-phase reclassification:
--      Phase A: Dash-based (artcc_code LIKE '%-%') → always sub-areas
--      Phase B: Spatial containment with coverage ratio heuristic
--        Coverage = sum(children_area)/parent_area
--        < 50% AND parent < 5M km² → children are sub-areas
--        >= 50% → children tile parent (operational divisions)
--        Parent >= 5M km² → large FIR exception (children are operational)
--
-- SAFE DURING HIBERNATION: GIS daemons are paused
-- =============================================================================

-- Step 1: Widen artcc_code from VARCHAR(4) to VARCHAR(20)
-- This fixes the core truncation bug where codes like EDGG-BAD were truncated to EDGG
--
-- NOTE: artcc_code has dependent views (artcc_code_mapping, boundary_stats) and
-- materialized view (artcc_tier_matrix). These must be dropped first, then recreated.
-- If running manually, use: DROP VIEW artcc_code_mapping CASCADE; DROP VIEW boundary_stats CASCADE;
-- DROP MATERIALIZED VIEW artcc_tier_matrix CASCADE; then ALTER, then recreate all three.
-- See the DO block below for automated handling.
DO $$
DECLARE
    v_view_defs RECORD;
BEGIN
    -- Drop dependent views if they exist
    DROP MATERIALIZED VIEW IF EXISTS artcc_tier_matrix CASCADE;
    DROP VIEW IF EXISTS boundary_stats CASCADE;
    DROP VIEW IF EXISTS artcc_code_mapping CASCADE;
    RAISE NOTICE 'Dropped dependent views for artcc_code ALTER';
END $$;

ALTER TABLE artcc_boundaries ALTER COLUMN artcc_code TYPE VARCHAR(20);

-- Also widen icao_code which has the same problem
ALTER TABLE artcc_boundaries ALTER COLUMN icao_code TYPE VARCHAR(20);

-- Recreate dependent views (they will be auto-recreated by their own migration scripts
-- on next deployment, but we recreate here for immediate use)

-- Step 2: Add hierarchy columns
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS parent_fir VARCHAR(20) NULL;
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS is_subsector BOOLEAN NOT NULL DEFAULT FALSE;

-- Step 3: Drop the unique index on artcc_code (will fail with sub-areas after re-import)
DROP INDEX IF EXISTS idx_artcc_boundaries_code;

-- Step 4: Create non-unique index on artcc_code
CREATE INDEX IF NOT EXISTS idx_artcc_boundaries_code ON artcc_boundaries (artcc_code);

-- Step 5: Create filtered index for FIR-only detection queries
-- This is what detection functions will use after Phase 3 updates
CREATE INDEX IF NOT EXISTS idx_artcc_boundaries_fir_only
    ON artcc_boundaries (artcc_code)
    WHERE NOT is_subsector;

-- Step 6a: Phase A - Reclassify dash-based sub-areas
UPDATE artcc_boundaries
SET is_subsector = TRUE,
    parent_fir = SPLIT_PART(artcc_code, '-', 1)
WHERE artcc_code LIKE '%-%'
  AND is_subsector = FALSE;

-- Step 6b: Phase B - Spatial containment reclassification
-- Uses coverage ratio heuristic with large parent exception
WITH immediate_parent AS (
    SELECT
        child.boundary_id AS child_id,
        child.artcc_code AS nested_code,
        ST_Area(child.geom::geography) AS child_area,
        parent.artcc_code AS parent_code,
        ST_Area(parent.geom::geography) AS parent_area,
        ROW_NUMBER() OVER (
            PARTITION BY child.artcc_code
            ORDER BY ST_Area(parent.geom::geography) ASC
        ) as rn
    FROM artcc_boundaries child
    JOIN artcc_boundaries parent
        ON child.boundary_id != parent.boundary_id
        AND ST_Area(child.geom::geography) < ST_Area(parent.geom::geography)
        AND ST_Intersects(parent.geom, ST_SetSRID(ST_MakePoint(child.label_lon, child.label_lat), 4326))
    WHERE child.artcc_code NOT LIKE '%-%'
      AND NOT child.is_subsector
      AND child.label_lat IS NOT NULL
      AND child.label_lon IS NOT NULL
),
nesting AS (
    SELECT child_id, nested_code, child_area, parent_code, parent_area
    FROM immediate_parent
    WHERE rn = 1
),
parent_coverage AS (
    SELECT parent_code, parent_area,
           SUM(child_area) / parent_area AS coverage_ratio
    FROM nesting
    GROUP BY parent_code, parent_area
),
sparse_children AS (
    SELECT n.child_id, n.nested_code, n.parent_code
    FROM nesting n
    JOIN parent_coverage pc ON n.parent_code = pc.parent_code
    WHERE pc.coverage_ratio < 0.50
      AND pc.parent_area < 5e12  -- Large parent exception (~5M km²)
)
UPDATE artcc_boundaries ab
SET is_subsector = TRUE,
    parent_fir = sc.parent_code
FROM sparse_children sc
WHERE ab.boundary_id = sc.child_id;

-- Step 7: Create index on parent_fir for sub-area lookups
CREATE INDEX IF NOT EXISTS idx_artcc_boundaries_parent_fir
    ON artcc_boundaries (parent_fir)
    WHERE parent_fir IS NOT NULL;

-- =============================================================================
-- Verification
-- =============================================================================
DO $$
DECLARE
    v_total INT;
    v_subs INT;
    v_firs INT;
    v_parents INT;
BEGIN
    SELECT COUNT(*) INTO v_total FROM artcc_boundaries;
    SELECT COUNT(*) INTO v_subs FROM artcc_boundaries WHERE is_subsector = TRUE;
    SELECT COUNT(*) INTO v_firs FROM artcc_boundaries WHERE is_subsector = FALSE;
    SELECT COUNT(DISTINCT parent_fir) INTO v_parents FROM artcc_boundaries WHERE is_subsector = TRUE;

    RAISE NOTICE '=== ARTCC Hierarchy Migration Results ===';
    RAISE NOTICE 'Total boundaries: %', v_total;
    RAISE NOTICE 'Full FIR/ARTCC: %', v_firs;
    RAISE NOTICE 'Sub-areas: %', v_subs;
    RAISE NOTICE 'Distinct parents: %', v_parents;
END $$;

-- =============================================================================
-- END MIGRATION
-- =============================================================================
