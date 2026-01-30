-- =============================================================================
-- VATSIM_GIS: BRIN Indexes for Large Tables
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-30
-- =============================================================================
--
-- BRIN (Block Range INdex) Analysis Results:
--
-- Tables analyzed for BRIN suitability (need 100K+ rows + high correlation):
--   ✓ nav_fixes:         534,804 rows  - fix_id correlation: 1.000  [QUALIFIES]
--   ✗ nav_procedures:     97,889 rows  - too small
--   ✗ playbook_routes:    55,682 rows  - too small
--   ✗ airway_segments:    43,580 rows  - too small
--   ✗ All others:         <42,000 rows - too small
--
-- BRIN vs B-tree trade-offs:
--   - BRIN: 100-1000x smaller, but only for range scans on ordered data
--   - B-tree: Required for equality lookups, unique constraints, ORDER BY
--
-- For nav_fixes, BRIN on fix_id provides minimal benefit since:
--   1. Primary key lookups already use B-tree (fast)
--   2. Most queries use name/spatial lookups, not fix_id ranges
--   3. GiST handles spatial queries (BRIN can't help)
--
-- Adding BRIN here for completeness and as template for future large tables.
-- =============================================================================

-- =============================================================================
-- BRIN Index: nav_fixes.fix_id
-- Benefit: Tiny index (~64KB vs 11MB B-tree) for range scans on fix_id
-- Use case: Batch processing, data exports, pagination by ID
-- =============================================================================
DROP INDEX IF EXISTS idx_nav_fixes_fix_id_brin;
CREATE INDEX idx_nav_fixes_fix_id_brin ON nav_fixes USING BRIN (fix_id)
    WITH (pages_per_range = 128);

COMMENT ON INDEX idx_nav_fixes_fix_id_brin IS
    'BRIN index for range scans on fix_id. Tiny size (~64KB) vs B-tree primary key.';

-- =============================================================================
-- Verification: Compare index sizes
-- =============================================================================
SELECT
    indexname,
    pg_size_pretty(pg_relation_size(indexname::regclass)) AS size,
    CASE
        WHEN indexname LIKE '%brin%' THEN 'BRIN'
        WHEN indexname LIKE '%gist%' OR indexname LIKE '%geom%' THEN 'GiST (spatial)'
        ELSE 'B-tree'
    END AS index_type
FROM pg_indexes
WHERE tablename = 'nav_fixes'
ORDER BY pg_relation_size(indexname::regclass) DESC;

-- =============================================================================
-- Template: BRIN for future large tables
-- Copy and modify when tables exceed 100K+ rows with naturally ordered columns
-- =============================================================================
/*
-- Example: If boundary_adjacency grows to 500K+ rows
CREATE INDEX idx_boundary_adjacency_computed_brin
    ON boundary_adjacency USING BRIN (computed_at)
    WITH (pages_per_range = 128);

-- Example: If playbook_routes grows to 500K+ rows
CREATE INDEX idx_playbook_routes_effective_brin
    ON playbook_routes USING BRIN (effective_date)
    WITH (pages_per_range = 128);

-- Check correlation before adding BRIN (needs > 0.9):
SELECT tablename, attname, correlation
FROM pg_stats
WHERE schemaname = 'public' AND tablename = 'your_table'
ORDER BY abs(correlation) DESC;
*/

-- =============================================================================
-- When NOT to use BRIN:
-- =============================================================================
-- 1. Tables under 100K rows (B-tree overhead is negligible)
-- 2. Columns with low correlation (data not physically ordered)
-- 3. Columns needing equality lookups (WHERE x = 'value')
-- 4. Columns in ORDER BY clauses (B-tree required)
-- 5. Columns needing unique constraints (B-tree required)
-- 6. Spatial/geometry columns (use GiST instead)
-- =============================================================================
