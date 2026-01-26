-- ============================================================================
-- Migration 013: Add NTML columns to tmi_programs
-- 
-- Purpose: Add all columns from VATSIM_ADL.dbo.ntml to VATSIM_TMI.dbo.tmi_programs
--          to enable full data migration and unified GDT management
--
-- Run on: VATSIM_TMI database
-- Credentials: TMI_admin / ***REMOVED***
-- Server: vatsim.database.windows.net
-- 
-- Date: 2026-01-26
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Starting Migration 013: Add NTML columns to tmi_programs ===';
PRINT '';

-- ============================================================================
-- PART 1: Add scope columns
-- ============================================================================
PRINT 'Part 1: Adding scope columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'scope_type')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD scope_type NVARCHAR(16) NULL;
    PRINT '  Added: scope_type';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'scope_tier')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD scope_tier TINYINT NULL;
    PRINT '  Added: scope_tier';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'scope_distance_nm')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD scope_distance_nm INT NULL;
    PRINT '  Added: scope_distance_nm';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'scope_json')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD scope_json NVARCHAR(MAX) NULL;
    PRINT '  Added: scope_json';
END
GO

-- ============================================================================
-- PART 2: Add exemption columns
-- ============================================================================
PRINT 'Part 2: Adding exemption columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'exemptions_json')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD exemptions_json NVARCHAR(MAX) NULL;
    PRINT '  Added: exemptions_json';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'exempt_airborne')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD exempt_airborne BIT NOT NULL DEFAULT 1;
    PRINT '  Added: exempt_airborne';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'exempt_within_min')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD exempt_within_min INT NULL;
    PRINT '  Added: exempt_within_min';
END
GO

-- ============================================================================
-- PART 3: Add flight filter columns
-- ============================================================================
PRINT 'Part 3: Adding flight filter columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'flt_incl_carrier')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD flt_incl_carrier NVARCHAR(512) NULL;
    PRINT '  Added: flt_incl_carrier';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'flt_incl_type')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD flt_incl_type NVARCHAR(8) NULL;
    PRINT '  Added: flt_incl_type';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'flt_incl_fix')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD flt_incl_fix NVARCHAR(8) NULL;
    PRINT '  Added: flt_incl_fix';
END
GO

-- ============================================================================
-- PART 4: Add flight metrics columns
-- ============================================================================
PRINT 'Part 4: Adding flight metrics columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'total_flights')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD total_flights INT NULL;
    PRINT '  Added: total_flights';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'controlled_flights')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD controlled_flights INT NULL;
    PRINT '  Added: controlled_flights';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'exempt_flights')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD exempt_flights INT NULL;
    PRINT '  Added: exempt_flights';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'airborne_flights')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD airborne_flights INT NULL;
    PRINT '  Added: airborne_flights';
END
GO

-- ============================================================================
-- PART 5: Add delay metrics columns
-- ============================================================================
PRINT 'Part 5: Adding delay metrics columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'avg_delay_min')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD avg_delay_min DECIMAL(8,2) NULL;
    PRINT '  Added: avg_delay_min';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'max_delay_min')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD max_delay_min INT NULL;
    PRINT '  Added: max_delay_min';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'total_delay_min')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD total_delay_min BIGINT NULL;
    PRINT '  Added: total_delay_min';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'target_delay_mult')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD target_delay_mult DECIMAL(3,2) NULL DEFAULT 1.0;
    PRINT '  Added: target_delay_mult';
END
GO

-- ============================================================================
-- PART 6: Add rate configuration columns
-- ============================================================================
PRINT 'Part 6: Adding rate configuration columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'rates_hourly_json')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD rates_hourly_json NVARCHAR(MAX) NULL;
    PRINT '  Added: rates_hourly_json';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'reserve_hourly_json')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD reserve_hourly_json NVARCHAR(MAX) NULL;
    PRINT '  Added: reserve_hourly_json';
END
GO

-- ============================================================================
-- PART 7: Add model/revision columns
-- ============================================================================
PRINT 'Part 7: Adding model/revision columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'model_time_utc')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD model_time_utc DATETIME2(0) NULL;
    PRINT '  Added: model_time_utc';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'revision_number')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD revision_number INT NOT NULL DEFAULT 0;
    PRINT '  Added: revision_number';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'successor_program_id')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD successor_program_id INT NULL;
    PRINT '  Added: successor_program_id';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'prob_extension')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD prob_extension NVARCHAR(8) NULL;
    PRINT '  Added: prob_extension';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'comments')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD comments NVARCHAR(MAX) NULL;
    PRINT '  Added: comments';
END
GO

-- ============================================================================
-- PART 8: Add audit columns (to match ntml naming)
-- ============================================================================
PRINT 'Part 8: Adding audit columns...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'modified_by')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD modified_by NVARCHAR(64) NULL;
    PRINT '  Added: modified_by';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'modified_utc')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD modified_utc DATETIME2(0) NULL;
    PRINT '  Added: modified_utc';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'activated_utc')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD activated_utc DATETIME2(0) NULL;
    PRINT '  Added: activated_utc';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'purged_by')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD purged_by NVARCHAR(64) NULL;
    PRINT '  Added: purged_by';
END

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'purged_utc')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD purged_utc DATETIME2(0) NULL;
    PRINT '  Added: purged_utc';
END
GO

-- ============================================================================
-- PART 9: Add indexes
-- ============================================================================
PRINT 'Part 9: Adding indexes...';

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_programs_type_status' AND object_id = OBJECT_ID('dbo.tmi_programs'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_programs_type_status ON dbo.tmi_programs(program_type, status);
    PRINT '  Added: IX_tmi_programs_type_status';
END

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_programs_created' AND object_id = OBJECT_ID('dbo.tmi_programs'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_programs_created ON dbo.tmi_programs(created_at DESC);
    PRINT '  Added: IX_tmi_programs_created';
END

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_programs_element' AND object_id = OBJECT_ID('dbo.tmi_programs'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_programs_element ON dbo.tmi_programs(ctl_element, status);
    PRINT '  Added: IX_tmi_programs_element';
END
GO

PRINT '';
PRINT '=== Migration 013 Complete ===';
PRINT '';
PRINT 'Next: Run data migration to copy records from VATSIM_ADL.dbo.ntml to VATSIM_TMI.dbo.tmi_programs';
GO
