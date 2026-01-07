-- ============================================================================
-- ADL Changelog System - Migration 001: Schema Upgrade
--
-- Upgrades the existing adl_flight_changelog table with additional fields
-- for comprehensive field-level audit trailing
--
-- Run Order: 1 of 6
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Changelog Migration 001: Schema Upgrade ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Upgrade adl_flight_changelog Table
-- ============================================================================

-- Add callsign column for denormalized querying
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'callsign')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD callsign NVARCHAR(16) NULL;
    PRINT 'Added callsign column to adl_flight_changelog';
END
GO

-- Add change_type column (I=Insert, U=Update, D=Delete, S=Status)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'change_type')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD change_type CHAR(1) NOT NULL DEFAULT 'U';
    PRINT 'Added change_type column to adl_flight_changelog';
END
GO

-- Add change_reason column for categorization
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'change_reason')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD change_reason NVARCHAR(50) NULL;
    PRINT 'Added change_reason column to adl_flight_changelog';
END
GO

-- Add batch_id column for grouping changes from same refresh cycle
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'batch_id')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD batch_id UNIQUEIDENTIFIER NULL;
    PRINT 'Added batch_id column to adl_flight_changelog';
END
GO

-- Rename changed_utc to change_utc for consistency with design doc (if exists)
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'changed_utc')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'change_utc')
BEGIN
    EXEC sp_rename 'dbo.adl_flight_changelog.changed_utc', 'change_utc', 'COLUMN';
    PRINT 'Renamed changed_utc to change_utc';
END
GO

-- Widen old_value and new_value columns to NVARCHAR(MAX) for route storage
-- SQL Server doesn't allow direct ALTER for widening, so we need to check current size
DECLARE @old_max_length INT, @new_max_length INT;

SELECT @old_max_length = max_length
FROM sys.columns
WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'old_value';

SELECT @new_max_length = max_length
FROM sys.columns
WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'new_value';

-- Only alter if not already MAX (-1)
IF @old_max_length IS NOT NULL AND @old_max_length <> -1
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ALTER COLUMN old_value NVARCHAR(MAX) NULL;
    PRINT 'Widened old_value column to NVARCHAR(MAX)';
END

IF @new_max_length IS NOT NULL AND @new_max_length <> -1
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ALTER COLUMN new_value NVARCHAR(MAX) NULL;
    PRINT 'Widened new_value column to NVARCHAR(MAX)';
END
GO

-- ============================================================================
-- 2. Create Batch Tracking Table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_changelog_batch') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_changelog_batch (
        batch_id            UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        batch_start_utc     DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        batch_end_utc       DATETIME2(3) NULL,
        source_process      NVARCHAR(100) NULL,
        flights_processed   INT NULL,
        changes_logged      INT NULL,

        CONSTRAINT PK_adl_changelog_batch PRIMARY KEY (batch_id)
    );

    CREATE NONCLUSTERED INDEX IX_batch_start ON dbo.adl_changelog_batch (batch_start_utc DESC);

    PRINT 'Created table dbo.adl_changelog_batch';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_changelog_batch already exists - skipping';
END
GO

-- ============================================================================
-- 3. Add New Indexes
-- ============================================================================

-- Index for callsign lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'IX_changelog_callsign')
BEGIN
    CREATE NONCLUSTERED INDEX IX_changelog_callsign
    ON dbo.adl_flight_changelog (callsign, change_utc DESC)
    WHERE callsign IS NOT NULL;
    PRINT 'Created index IX_changelog_callsign';
END
GO

-- Index for change type filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'IX_changelog_type')
BEGIN
    CREATE NONCLUSTERED INDEX IX_changelog_type
    ON dbo.adl_flight_changelog (change_type, change_utc DESC);
    PRINT 'Created index IX_changelog_type';
END
GO

-- Index for batch grouping
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'IX_changelog_batch')
BEGIN
    CREATE NONCLUSTERED INDEX IX_changelog_batch
    ON dbo.adl_flight_changelog (batch_id)
    WHERE batch_id IS NOT NULL;
    PRINT 'Created index IX_changelog_batch';
END
GO

-- ============================================================================
-- 4. Create Change Type Lookup Table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_changelog_change_types') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_changelog_change_types (
        change_type     CHAR(1) NOT NULL PRIMARY KEY,
        description     NVARCHAR(50) NOT NULL,
        is_status_change BIT NOT NULL DEFAULT 0
    );

    INSERT INTO dbo.adl_changelog_change_types (change_type, description, is_status_change)
    VALUES
        ('I', 'Insert - New record', 0),
        ('U', 'Update - Field value changed', 0),
        ('D', 'Delete - Record removed', 0),
        ('S', 'Status - Phase/status transition', 1);

    PRINT 'Created and populated table dbo.adl_changelog_change_types';
END
GO

PRINT '';
PRINT '=== ADL Changelog Migration 001 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
