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
--   5. Reclassify existing sub-area data
--
-- SAFE DURING HIBERNATION: GIS daemons are paused
-- =============================================================================

-- Step 1: Widen artcc_code from VARCHAR(4) to VARCHAR(20)
-- This fixes the core truncation bug
ALTER TABLE artcc_boundaries ALTER COLUMN artcc_code TYPE VARCHAR(20);

-- Also widen icao_code which has the same problem
ALTER TABLE artcc_boundaries ALTER COLUMN icao_code TYPE VARCHAR(20);

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

-- Step 6: Reclassify existing data
-- Any artcc_code with a dash is a sub-area
UPDATE artcc_boundaries
SET is_subsector = TRUE,
    parent_fir = SPLIT_PART(artcc_code, '-', 1)
WHERE artcc_code LIKE '%-%'
  AND is_subsector = FALSE;

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
