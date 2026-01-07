-- ============================================================
-- Advisory Builder Enhancements
-- Migration: 020_advisory_builder_enhancements.sql
-- Database: VATSIM_ADL (Azure SQL)
-- ============================================================

-- Add Discord posting tracking columns to dcc_advisories
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'discord_message_id')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        discord_message_id  NVARCHAR(256) NULL,         -- Discord message ID(s) - comma separated if split
        discord_posted_at   DATETIME2 NULL,             -- When posted to Discord
        discord_channel_id  NVARCHAR(64) NULL;          -- Channel posted to

    PRINT 'Added Discord tracking columns to dcc_advisories';
END
GO

-- Add TFMS-specific fields for structured advisory data
-- These are optional and allow storing structured data beyond the body_text

-- GDP specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'gdp_rate')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        gdp_rate            INT NULL,                   -- Program rate per hour
        gdp_delay_cap       INT NULL,                   -- Max delay in minutes
        gdp_scope_centers   NVARCHAR(MAX) NULL;         -- JSON array of scope centers

    PRINT 'Added GDP-specific columns to dcc_advisories';
END
GO

-- GS specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'gs_reason')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        gs_reason           NVARCHAR(64) NULL,          -- GS reason (WEATHER, EQUIPMENT, etc.)
        gs_probability      NVARCHAR(32) NULL;          -- Probability of extension

    PRINT 'Added GS-specific columns to dcc_advisories';
END
GO

-- AFP/FCA specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'afp_fca')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        afp_fca             NVARCHAR(64) NULL,          -- FCA identifier
        afp_rate            INT NULL;                   -- AFP rate per hour

    PRINT 'Added AFP-specific columns to dcc_advisories';
END
GO

-- CTOP specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'ctop_name')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        ctop_name           NVARCHAR(64) NULL,          -- CTOP program name
        ctop_fcas           NVARCHAR(MAX) NULL,         -- JSON array of FCAs
        ctop_caps           NVARCHAR(MAX) NULL;         -- JSON array of caps

    PRINT 'Added CTOP-specific columns to dcc_advisories';
END
GO

-- Reroute specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'reroute_name')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        reroute_name        NVARCHAR(64) NULL,          -- Route name (e.g., GOLDDR)
        reroute_area        NVARCHAR(32) NULL,          -- Constrained area
        reroute_string      NVARCHAR(MAX) NULL,         -- Full route string
        reroute_from        NVARCHAR(256) NULL,         -- Traffic from filter
        reroute_to          NVARCHAR(256) NULL;         -- Traffic to filter

    PRINT 'Added Reroute-specific columns to dcc_advisories';
END
GO

-- MIT specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'mit_miles')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD
        mit_miles           INT NULL,                   -- MIT/MINIT value
        mit_type            NVARCHAR(8) NULL,           -- MIT or MINIT
        mit_fix             NVARCHAR(32) NULL;          -- At fix

    PRINT 'Added MIT-specific columns to dcc_advisories';
END
GO

-- Allow NULL for created_by since Advisory Builder may not have user context
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'created_by' AND is_nullable = 0)
BEGIN
    ALTER TABLE dbo.dcc_advisories ALTER COLUMN created_by NVARCHAR(64) NULL;
    PRINT 'Made created_by nullable';
END
GO

-- Add default for created_by if not provided
IF NOT EXISTS (SELECT * FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('dbo.dcc_advisories') AND COL_NAME(parent_object_id, parent_column_id) = 'created_by')
BEGIN
    ALTER TABLE dbo.dcc_advisories ADD CONSTRAINT DF_dcc_advisories_created_by DEFAULT 'SYSTEM' FOR created_by;
    PRINT 'Added default constraint for created_by';
END
GO

-- Allow NULL for valid_start_utc for draft advisories
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_advisories') AND name = 'valid_start_utc' AND is_nullable = 0)
BEGIN
    ALTER TABLE dbo.dcc_advisories ALTER COLUMN valid_start_utc DATETIME2 NULL;
    PRINT 'Made valid_start_utc nullable for draft advisories';
END
GO

PRINT '====================================================';
PRINT 'Advisory Builder enhancements migration complete';
PRINT '====================================================';
GO
