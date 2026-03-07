-- =====================================================
-- Multi-Level ARTCC/FIR Boundary Hierarchy (PostGIS)
-- Migration: 015_boundary_hierarchy.sql
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
-- Date: 2026-03-07
--
-- PURPOSE:
--   Add hierarchy_level and hierarchy_type columns to artcc_boundaries
--   and tracon_boundaries. Create boundary_hierarchy edge table.
--   Recreate boundary_stats view (dropped by migration 014).
--   Backfill existing rows with approximate hierarchy values.
--
-- DEPENDS ON: 014_artcc_hierarchy.sql (is_subsector column, artcc_code widened)
-- SAFE DURING HIBERNATION: GIS daemons are paused
-- =====================================================

-- =====================
-- Step 2A: Add hierarchy columns to artcc_boundaries
-- =====================
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS hierarchy_level SMALLINT NULL;
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS hierarchy_type VARCHAR(30) NULL;
CREATE INDEX IF NOT EXISTS idx_artcc_hierarchy_level ON artcc_boundaries(hierarchy_level);

DO $$ BEGIN RAISE NOTICE 'Added hierarchy columns to artcc_boundaries'; END $$;

-- =====================
-- Step 2B: Create boundary_hierarchy edge table
-- =====================
CREATE TABLE IF NOT EXISTS boundary_hierarchy (
    edge_id SERIAL PRIMARY KEY,
    parent_boundary_id INT NOT NULL,
    child_boundary_id  INT NOT NULL,
    parent_code VARCHAR(50) NOT NULL,
    child_code  VARCHAR(50) NOT NULL,
    parent_type VARCHAR(20) NOT NULL,
    child_type  VARCHAR(20) NOT NULL,
    relationship_type VARCHAR(20) NOT NULL,
    coverage_ratio DECIMAL(5,4) NULL,
    computed_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (parent_boundary_id, child_boundary_id)
);

CREATE INDEX IF NOT EXISTS idx_hier_parent ON boundary_hierarchy(parent_boundary_id);
CREATE INDEX IF NOT EXISTS idx_hier_child ON boundary_hierarchy(child_boundary_id);
CREATE INDEX IF NOT EXISTS idx_hier_parent_code ON boundary_hierarchy(parent_code);
CREATE INDEX IF NOT EXISTS idx_hier_child_code ON boundary_hierarchy(child_code);

DO $$ BEGIN RAISE NOTICE 'Created boundary_hierarchy table with indexes'; END $$;

-- =====================
-- Step 2B2: Add hierarchy columns to tracon_boundaries
-- =====================
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS hierarchy_level SMALLINT NULL;
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS hierarchy_type VARCHAR(30) NULL;
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS parent_fir VARCHAR(20) NULL;
CREATE INDEX IF NOT EXISTS idx_tracon_hierarchy_type ON tracon_boundaries(hierarchy_type);

DO $$ BEGIN RAISE NOTICE 'Added hierarchy columns to tracon_boundaries'; END $$;

-- =====================
-- Step 2B3: Recreate views dropped by migration 014
-- boundary_stats: simple row counts per boundary table
-- artcc_code_mapping: mapping view (recreate if it existed)
-- artcc_tier_matrix: handled by 012_artcc_tier_matrix.sql (run separately after import)
-- =====================
CREATE OR REPLACE VIEW boundary_stats AS
SELECT 'artcc_boundaries' AS table_name,
       COUNT(*) AS total_rows,
       COUNT(*) FILTER (WHERE NOT is_subsector) AS active_rows
FROM artcc_boundaries
UNION ALL
SELECT 'tracon_boundaries',
       COUNT(*),
       COUNT(*)
FROM tracon_boundaries
UNION ALL
SELECT 'sector_boundaries',
       COUNT(*),
       COUNT(*)
FROM sector_boundaries;

DO $$ BEGIN RAISE NOTICE 'Recreated boundary_stats view'; END $$;

-- =====================
-- Step 2D: Backfill existing data (approximate)
-- =====================
UPDATE artcc_boundaries SET hierarchy_level = 1, hierarchy_type = 'FIR'
WHERE NOT is_subsector AND hierarchy_level IS NULL;

DO $$
DECLARE
    v_count INT;
BEGIN
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'Backfilled % ARTCC boundaries as Level 1 FIR', v_count;
END $$;

UPDATE artcc_boundaries SET hierarchy_level = 2, hierarchy_type = 'NAMED_SUB_AREA'
WHERE is_subsector AND hierarchy_level IS NULL;

DO $$
DECLARE
    v_count INT;
BEGIN
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'Backfilled % ARTCC_SUB boundaries as Level 2 NAMED_SUB_AREA', v_count;
END $$;

-- =====================
-- Verification
-- =====================
DO $$
DECLARE
    v_l1 INT;
    v_l2 INT;
    v_edges INT;
BEGIN
    SELECT COUNT(*) INTO v_l1 FROM artcc_boundaries WHERE hierarchy_level = 1;
    SELECT COUNT(*) INTO v_l2 FROM artcc_boundaries WHERE hierarchy_level = 2;
    SELECT COUNT(*) INTO v_edges FROM boundary_hierarchy;
    RAISE NOTICE '=== Verification ===';
    RAISE NOTICE 'Level 1 (FIR): % boundaries', v_l1;
    RAISE NOTICE 'Level 2 (SUB): % boundaries', v_l2;
    RAISE NOTICE 'Hierarchy edges: %', v_edges;
    RAISE NOTICE '015_boundary_hierarchy.sql migration complete';
END $$;
