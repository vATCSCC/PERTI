-- ============================================================================
-- Add GDT columns to physical adl_flights table
--
-- The adl_flights table exists as a physical table (not a view).
-- This migration adds the missing GDT-related columns needed by the
-- simulation scripts (gs_simulate.php, gdp_simulate.php).
--
-- Run after: 004_gdt_columns_fix.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Adding GDT columns to adl_flights table ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Only proceed if adl_flights is a table
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flights')
BEGIN
    PRINT 'adl_flights exists as a table - adding missing columns...';

    -- Original CTD/CTA
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'octd_utc')
        ALTER TABLE dbo.adl_flights ADD octd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'octa_utc')
        ALTER TABLE dbo.adl_flights ADD octa_utc DATETIME2(0) NULL;

    -- Controlled times
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctd_utc')
        ALTER TABLE dbo.adl_flights ADD ctd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'cta_utc')
        ALTER TABLE dbo.adl_flights ADD cta_utc DATETIME2(0) NULL;

    -- Original/Baseline ETD/ETA
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'oetd_utc')
        ALTER TABLE dbo.adl_flights ADD oetd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'betd_utc')
        ALTER TABLE dbo.adl_flights ADD betd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'oeta_utc')
        ALTER TABLE dbo.adl_flights ADD oeta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'beta_utc')
        ALTER TABLE dbo.adl_flights ADD beta_utc DATETIME2(0) NULL;

    -- ETE columns
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'oete_minutes')
        ALTER TABLE dbo.adl_flights ADD oete_minutes INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'cete_minutes')
        ALTER TABLE dbo.adl_flights ADD cete_minutes INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ete_minutes')
        ALTER TABLE dbo.adl_flights ADD ete_minutes INT NULL;

    -- IGTA and ETA prefix
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'igta_utc')
        ALTER TABLE dbo.adl_flights ADD igta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'eta_prefix')
        ALTER TABLE dbo.adl_flights ADD eta_prefix NCHAR(1) NULL;

    -- TMI control fields
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctl_type')
        ALTER TABLE dbo.adl_flights ADD ctl_type NVARCHAR(8) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctl_element')
        ALTER TABLE dbo.adl_flights ADD ctl_element NVARCHAR(8) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctl_prgm')
        ALTER TABLE dbo.adl_flights ADD ctl_prgm NVARCHAR(50) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'delay_status')
        ALTER TABLE dbo.adl_flights ADD delay_status NVARCHAR(16) NULL;

    -- Delay metrics
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'program_delay_min')
        ALTER TABLE dbo.adl_flights ADD program_delay_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'absolute_delay_min')
        ALTER TABLE dbo.adl_flights ADD absolute_delay_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'schedule_variation_min')
        ALTER TABLE dbo.adl_flights ADD schedule_variation_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'delay_capped')
        ALTER TABLE dbo.adl_flights ADD delay_capped BIT NULL DEFAULT 0;

    -- Ground Stop specific
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gs_held')
        ALTER TABLE dbo.adl_flights ADD gs_held BIT NULL DEFAULT 0;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gs_release_utc')
        ALTER TABLE dbo.adl_flights ADD gs_release_utc DATETIME2(0) NULL;

    -- Exemption fields
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctl_exempt')
        ALTER TABLE dbo.adl_flights ADD ctl_exempt BIT NULL DEFAULT 0;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctl_exempt_reason')
        ALTER TABLE dbo.adl_flights ADD ctl_exempt_reason NVARCHAR(64) NULL;

    -- Slot fields
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'slot_time')
        ALTER TABLE dbo.adl_flights ADD slot_time NVARCHAR(8) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'slot_time_utc')
        ALTER TABLE dbo.adl_flights ADD slot_time_utc DATETIME2(0) NULL;

    -- GDP specific
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gdp_program_id')
        ALTER TABLE dbo.adl_flights ADD gdp_program_id NVARCHAR(50) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gdp_slot_index')
        ALTER TABLE dbo.adl_flights ADD gdp_slot_index INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gdp_slot_time_utc')
        ALTER TABLE dbo.adl_flights ADD gdp_slot_time_utc DATETIME2(0) NULL;

    PRINT 'Added GDT columns to adl_flights table';
END
ELSE
BEGIN
    PRINT 'adl_flights does not exist as a table - no action needed';
END
GO

-- Verify columns were added
PRINT '';
PRINT 'Column count for adl_flights:';
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'adl_flights';

PRINT '';
PRINT 'GDT-specific columns in adl_flights:';
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'adl_flights'
  AND COLUMN_NAME IN (
    'ctd_utc', 'cta_utc', 'octd_utc', 'octa_utc',
    'oetd_utc', 'betd_utc', 'oeta_utc', 'beta_utc',
    'oete_minutes', 'cete_minutes', 'ete_minutes',
    'igta_utc', 'eta_prefix',
    'ctl_type', 'ctl_element', 'ctl_prgm', 'delay_status',
    'program_delay_min', 'absolute_delay_min', 'schedule_variation_min',
    'delay_capped', 'gs_held', 'gs_release_utc',
    'ctl_exempt', 'ctl_exempt_reason',
    'slot_time', 'slot_time_utc',
    'gdp_program_id', 'gdp_slot_index', 'gdp_slot_time_utc'
  )
ORDER BY COLUMN_NAME;
GO

PRINT '';
PRINT '=== Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
