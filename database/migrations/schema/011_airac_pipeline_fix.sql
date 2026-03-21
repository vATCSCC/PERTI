-- =============================================================================
-- Migration: 011_airac_pipeline_fix.sql
-- Purpose:   Fix AIRAC pipeline data-loss bugs + add supersession tracking
-- Date:      2026-03-19
-- Auth:      Admin (jpeterson) - adl_api_user lacks DDL
-- =============================================================================
--
-- CHANGES:
--   1a. Drop UNIQUE index on airways.airway_name (REF + ADL)
--   1b. Widen airway_name from NVARCHAR(8) to NVARCHAR(30) (REF + ADL)
--   1c. Widen fix_name from NVARCHAR(16) to NVARCHAR(32) (REF + ADL)
--   1d. Add supersession columns to 5 reference tables (REF)
--   1e. Add supersession columns to 5 reference tables (ADL)
--   1f. Create navdata_changelogs table (REF only)
--
-- ROOT CAUSES FIXED:
--   - IX_airway_name UNIQUE index caused 91% airway data loss (multi-region
--     airways like W3 have 19 variants with same name, different fix_sequences)
--   - airway_name NVARCHAR(8) too narrow for future international names
--   - fix_name NVARCHAR(16) truncates 353 ZZ_ pseudo-fixes (max 28 chars)
--   - _old_ entries were filtered out losing all historical supersession data
--   - No changelog tracking between AIRAC cycles
--
-- RUN INSTRUCTIONS:
--   This file has two sections. Run each section on its respective database:
--     Section A: Connect to VATSIM_REF and run lines marked "VATSIM_REF"
--     Section B: Connect to VATSIM_ADL and run lines marked "VATSIM_ADL"
--   Azure SQL Basic does NOT support cross-database USE statements.
-- =============================================================================


-- =============================================================================
-- SECTION A: VATSIM_REF
-- Connect to VATSIM_REF before running this section
-- =============================================================================

-- 1a. Drop UNIQUE index on airways.airway_name, recreate as non-unique
--     Must drop BEFORE ALTER COLUMN (index references the column)
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_airway_name' AND object_id = OBJECT_ID('dbo.airways'))
    DROP INDEX IX_airway_name ON dbo.airways;
GO

-- 1b. Widen airway_name columns
ALTER TABLE dbo.airways ALTER COLUMN airway_name NVARCHAR(30) NOT NULL;
GO

ALTER TABLE dbo.airway_segments ALTER COLUMN airway_name NVARCHAR(30);
GO

-- Recreate as non-unique index
CREATE NONCLUSTERED INDEX IX_airway_name ON dbo.airways (airway_name);
GO

-- 1c. Widen fix_name column (353 ZZ_ pseudo-fixes exceed 16 chars, max 28)
--     Must drop indexes on fix_name first, then recreate after ALTER
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_fix_name' AND object_id = OBJECT_ID('dbo.nav_fixes'))
    DROP INDEX IX_fix_name ON dbo.nav_fixes;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_fix_name_type' AND object_id = OBJECT_ID('dbo.nav_fixes'))
    DROP INDEX IX_fix_name_type ON dbo.nav_fixes;
GO

ALTER TABLE dbo.nav_fixes ALTER COLUMN fix_name NVARCHAR(32);
GO

-- Recreate indexes on widened column
CREATE NONCLUSTERED INDEX IX_fix_name ON dbo.nav_fixes (fix_name);
CREATE NONCLUSTERED INDEX IX_fix_name_type ON dbo.nav_fixes (fix_name, fix_type);
GO

-- 1d. Add supersession columns to 5 reference tables
--     Tracks which entries are historical (_old_) and why

-- nav_fixes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_fixes') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.nav_fixes ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- airways
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.airways') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.airways ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- coded_departure_routes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.coded_departure_routes') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.coded_departure_routes ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- nav_procedures
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_procedures') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.nav_procedures ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- playbook_routes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.playbook_routes') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.playbook_routes ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- 1f. Create navdata_changelogs table
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'navdata_changelogs')
BEGIN
    CREATE TABLE dbo.navdata_changelogs (
        changelog_id INT IDENTITY(1,1) PRIMARY KEY,
        airac_cycle NVARCHAR(8) NOT NULL,
        table_name NVARCHAR(64) NOT NULL,
        entry_name NVARCHAR(64) NOT NULL,
        change_type NVARCHAR(16) NOT NULL,  -- added, removed, moved, changed, superseded
        old_value NVARCHAR(MAX) NULL,       -- JSON of previous state
        new_value NVARCHAR(MAX) NULL,       -- JSON of new state
        delta_detail NVARCHAR(256) NULL,    -- e.g., 'moved 2.3nm', 'fix_sequence changed'
        created_utc DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE NONCLUSTERED INDEX IX_changelog_cycle ON dbo.navdata_changelogs (airac_cycle);
    CREATE NONCLUSTERED INDEX IX_changelog_table ON dbo.navdata_changelogs (table_name, entry_name);
END
GO

PRINT 'VATSIM_REF migration complete.';
GO


-- =============================================================================
-- SECTION B: VATSIM_ADL
-- Connect to VATSIM_ADL before running this section
-- =============================================================================

-- 1a. Drop UNIQUE index on airways.airway_name, recreate as non-unique
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_airway_name' AND object_id = OBJECT_ID('dbo.airways'))
    DROP INDEX IX_airway_name ON dbo.airways;
GO

-- 1b. Widen airway_name columns
ALTER TABLE dbo.airways ALTER COLUMN airway_name NVARCHAR(30) NOT NULL;
GO

ALTER TABLE dbo.airway_segments ALTER COLUMN airway_name NVARCHAR(30);
GO

-- Recreate as non-unique index
CREATE NONCLUSTERED INDEX IX_airway_name ON dbo.airways (airway_name);
GO

-- 1c. Widen fix_name column
--     Must drop indexes on fix_name first, then recreate after ALTER
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_fix_name' AND object_id = OBJECT_ID('dbo.nav_fixes'))
    DROP INDEX IX_fix_name ON dbo.nav_fixes;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_fix_name_type' AND object_id = OBJECT_ID('dbo.nav_fixes'))
    DROP INDEX IX_fix_name_type ON dbo.nav_fixes;
GO

ALTER TABLE dbo.nav_fixes ALTER COLUMN fix_name NVARCHAR(32);
GO

-- Recreate indexes on widened column
CREATE NONCLUSTERED INDEX IX_fix_name ON dbo.nav_fixes (fix_name);
CREATE NONCLUSTERED INDEX IX_fix_name_type ON dbo.nav_fixes (fix_name, fix_type);
GO

-- 1e. Add supersession columns to 5 reference tables (ADL mirrors)

-- nav_fixes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_fixes') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.nav_fixes ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- airways
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.airways') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.airways ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- coded_departure_routes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.coded_departure_routes') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.coded_departure_routes ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- nav_procedures
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_procedures') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.nav_procedures ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

-- playbook_routes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.playbook_routes') AND name = 'is_superseded')
BEGIN
    ALTER TABLE dbo.playbook_routes ADD
        is_superseded BIT NOT NULL DEFAULT 0,
        superseded_cycle NVARCHAR(8) NULL,
        superseded_reason NVARCHAR(16) NULL;
END
GO

PRINT 'VATSIM_ADL migration complete.';
GO
