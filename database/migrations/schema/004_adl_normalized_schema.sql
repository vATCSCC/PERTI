-- ============================================================================
-- 024_adl_normalized_schema.sql
-- 
-- ADL Database Redesign - Normalized Schema with GIS Support
-- 
-- This migration creates the new normalized ADL table structure with:
-- - 11 normalized tables (decomposed from monolithic adl_flights)
-- - GIS route parsing support (GEOGRAPHY columns, spatial indexes)
-- - Tiered async parsing queue
-- - Reference data tables for route expansion
-- - Backward-compatible view (vw_adl_flights)
-- 
-- IMPORTANT: This migration should be run on the ADL Azure SQL database,
-- not the main PERTI database. The individual scripts are in the
-- /adl/migrations/core/ directory.
--
-- Run Order:
--   1. core/001_adl_core_tables.sql
--   2. core/002_adl_times_trajectory.sql
--   3. core/003_adl_waypoints_stepclimbs.sql
--   4. core/004_adl_reference_tables.sql
--   5. core/005_adl_views_seed_data.sql
--   6. procedures/fn_GetParseTier.sql
--   7. procedures/sp_ParseQueue.sql
-- 
-- See /adl/README.md for full documentation.
-- ============================================================================

-- This file serves as a placeholder/pointer to the ADL-specific migrations.
-- The actual scripts are in the /adl/ directory to keep them organized
-- separately from the main PERTI database migrations.

PRINT '======================================================================';
PRINT 'ADL Normalized Schema Migration';
PRINT '======================================================================';
PRINT '';
PRINT 'This migration should be run on the ADL Azure SQL database.';
PRINT 'Execute the scripts from the /adl/migrations/core/ directory in order:';
PRINT '';
PRINT '  1. /adl/migrations/core/001_adl_core_tables.sql';
PRINT '  2. /adl/migrations/core/002_adl_times_trajectory.sql';
PRINT '  3. /adl/migrations/core/003_adl_waypoints_stepclimbs.sql';
PRINT '  4. /adl/migrations/core/004_adl_reference_tables.sql';
PRINT '  5. /adl/migrations/core/005_adl_views_seed_data.sql';
PRINT '';
PRINT 'Then deploy the stored procedures:';
PRINT '  6. /adl/procedures/fn_GetParseTier.sql';
PRINT '  7. /adl/procedures/sp_ParseQueue.sql';
PRINT '';
PRINT 'See /adl/README.md for complete documentation.';
PRINT '======================================================================';
GO
