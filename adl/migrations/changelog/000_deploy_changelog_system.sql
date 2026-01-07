-- ============================================================================
-- ADL CHANGELOG SYSTEM - MASTER DEPLOYMENT SCRIPT
-- ============================================================================
--
-- This script deploys the complete ADL Changelog System in the correct order.
-- It implements a field-level audit trail for ADL normalized flight tables.
--
-- DEPLOYMENT ORDER (Tiered Approach):
--   Tier 1: Schema upgrade (001) - Upgrades changelog table structure
--   Tier 2: Core trigger (002)   - Tracks flight identity and lifecycle
--   Tier 3: Plan/Times (003-004) - Tracks route and time changes
--   Tier 4: Aircraft/TMI (005)   - Tracks equipment and TMI changes
--   Tier 5: Procedures (006)     - Query and maintenance utilities
--
-- USAGE:
--   Execute this script against the VATSIM_ADL database
--   Or execute individual migration files in order (001-006)
--
-- ROLLBACK:
--   Use the rollback section at the bottom of this file
--
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '╔════════════════════════════════════════════════════════════════════╗';
PRINT '║           ADL CHANGELOG SYSTEM - DEPLOYMENT                        ║';
PRINT '╠════════════════════════════════════════════════════════════════════╣';
PRINT '║  Version: 1.0                                                      ║';
PRINT '║  Date: ' + CONVERT(VARCHAR, GETUTCDATE(), 120) + '                              ║';
PRINT '╚════════════════════════════════════════════════════════════════════╝';
PRINT '';
GO

-- ============================================================================
-- PRE-DEPLOYMENT CHECKS
-- ============================================================================

PRINT '=== Pre-Deployment Checks ===';
PRINT '';

-- Check that required tables exist
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_core table not found. Run ADL core migrations first.', 16, 1);
    RETURN;
END
PRINT '✓ adl_flight_core exists';

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_plan') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_plan table not found. Run ADL core migrations first.', 16, 1);
    RETURN;
END
PRINT '✓ adl_flight_plan exists';

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_times table not found. Run ADL core migrations first.', 16, 1);
    RETURN;
END
PRINT '✓ adl_flight_times exists';

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_aircraft') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_aircraft table not found. Run ADL core migrations first.', 16, 1);
    RETURN;
END
PRINT '✓ adl_flight_aircraft exists';

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_tmi table not found. Run ADL core migrations first.', 16, 1);
    RETURN;
END
PRINT '✓ adl_flight_tmi exists';

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_changelog table not found. Run migration 002_adl_times_trajectory.sql first.', 16, 1);
    RETURN;
END
PRINT '✓ adl_flight_changelog exists';

PRINT '';
PRINT 'All pre-deployment checks passed.';
PRINT '';
GO

-- ============================================================================
-- TIER 1: SCHEMA UPGRADE
-- ============================================================================

PRINT '┌──────────────────────────────────────────────────────────────────┐';
PRINT '│ TIER 1: Schema Upgrade                                          │';
PRINT '└──────────────────────────────────────────────────────────────────┘';
GO

-- Add callsign column
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'callsign')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD callsign NVARCHAR(16) NULL;
    PRINT '  + Added callsign column';
END
GO

-- Add change_type column
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'change_type')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD change_type CHAR(1) NOT NULL DEFAULT 'U';
    PRINT '  + Added change_type column';
END
GO

-- Add change_reason column
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'change_reason')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD change_reason NVARCHAR(50) NULL;
    PRINT '  + Added change_reason column';
END
GO

-- Add batch_id column
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'batch_id')
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ADD batch_id UNIQUEIDENTIFIER NULL;
    PRINT '  + Added batch_id column';
END
GO

-- Rename changed_utc to change_utc if needed
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'changed_utc')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'change_utc')
BEGIN
    EXEC sp_rename 'dbo.adl_flight_changelog.changed_utc', 'change_utc', 'COLUMN';
    PRINT '  + Renamed changed_utc to change_utc';
END
GO

-- Widen value columns
DECLARE @old_len INT, @new_len INT;
SELECT @old_len = max_length FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'old_value';
SELECT @new_len = max_length FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'new_value';

IF @old_len IS NOT NULL AND @old_len <> -1
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ALTER COLUMN old_value NVARCHAR(MAX) NULL;
    PRINT '  + Widened old_value to NVARCHAR(MAX)';
END

IF @new_len IS NOT NULL AND @new_len <> -1
BEGIN
    ALTER TABLE dbo.adl_flight_changelog ALTER COLUMN new_value NVARCHAR(MAX) NULL;
    PRINT '  + Widened new_value to NVARCHAR(MAX)';
END
GO

-- Create batch tracking table
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
    PRINT '  + Created adl_changelog_batch table';
END
GO

-- Create change types lookup
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_changelog_change_types') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_changelog_change_types (
        change_type     CHAR(1) NOT NULL PRIMARY KEY,
        description     NVARCHAR(50) NOT NULL,
        is_status_change BIT NOT NULL DEFAULT 0
    );
    INSERT INTO dbo.adl_changelog_change_types VALUES
        ('I', 'Insert - New record', 0),
        ('U', 'Update - Field changed', 0),
        ('D', 'Delete - Record removed', 0),
        ('S', 'Status - Phase transition', 1);
    PRINT '  + Created adl_changelog_change_types table';
END
GO

-- Add new indexes
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'IX_changelog_callsign')
BEGIN
    CREATE NONCLUSTERED INDEX IX_changelog_callsign ON dbo.adl_flight_changelog (callsign, change_utc DESC) WHERE callsign IS NOT NULL;
    PRINT '  + Created IX_changelog_callsign index';
END

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'IX_changelog_type')
BEGIN
    CREATE NONCLUSTERED INDEX IX_changelog_type ON dbo.adl_flight_changelog (change_type, change_utc DESC);
    PRINT '  + Created IX_changelog_type index';
END

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND name = 'IX_changelog_batch')
BEGIN
    CREATE NONCLUSTERED INDEX IX_changelog_batch ON dbo.adl_flight_changelog (batch_id) WHERE batch_id IS NOT NULL;
    PRINT '  + Created IX_changelog_batch index';
END
GO

PRINT '  ✓ Tier 1 Complete';
PRINT '';
GO

-- ============================================================================
-- TIER 2: CORE TRIGGER
-- ============================================================================

PRINT '┌──────────────────────────────────────────────────────────────────┐';
PRINT '│ TIER 2: Flight Core Trigger                                     │';
PRINT '└──────────────────────────────────────────────────────────────────┘';
GO

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_core_Changelog
ON dbo.adl_flight_core
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_core';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();

    -- INSERT
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT flight_uid, callsign, 'I', @table_name, 'callsign', NULL, callsign, @batch_id, @now FROM inserted WHERE callsign IS NOT NULL
        UNION ALL SELECT flight_uid, callsign, 'I', @table_name, 'cid', NULL, CAST(cid AS NVARCHAR(20)), @batch_id, @now FROM inserted WHERE cid IS NOT NULL
        UNION ALL SELECT flight_uid, callsign, 'I', @table_name, 'phase', NULL, phase, @batch_id, @now FROM inserted WHERE phase IS NOT NULL
        UNION ALL SELECT flight_uid, callsign, 'I', @table_name, 'is_active', NULL, CAST(is_active AS NVARCHAR(5)), @batch_id, @now FROM inserted WHERE is_active IS NOT NULL;
    END

    -- UPDATE
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT i.flight_uid, i.callsign, 'U', @table_name, 'callsign', d.callsign, i.callsign, @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid WHERE ISNULL(i.callsign, '') <> ISNULL(d.callsign, '')
        UNION ALL
        SELECT i.flight_uid, i.callsign, 'S', @table_name, 'phase', d.phase, i.phase, @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid WHERE ISNULL(i.phase, '') <> ISNULL(d.phase, '')
        UNION ALL
        SELECT i.flight_uid, i.callsign, 'S', @table_name, 'flight_status', d.flight_status, i.flight_status, @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid WHERE ISNULL(i.flight_status, '') <> ISNULL(d.flight_status, '')
        UNION ALL
        SELECT i.flight_uid, i.callsign, 'U', @table_name, 'is_active', CAST(d.is_active AS NVARCHAR(5)), CAST(i.is_active AS NVARCHAR(5)), @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid WHERE ISNULL(i.is_active, 0) <> ISNULL(d.is_active, 0);
    END

    -- DELETE
    IF EXISTS (SELECT 1 FROM deleted) AND NOT EXISTS (SELECT 1 FROM inserted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT flight_uid, callsign, 'D', @table_name, 'flight_uid', CAST(flight_uid AS NVARCHAR(20)), NULL, @batch_id, @now FROM deleted;
    END
END;
GO

PRINT '  + Created tr_adl_flight_core_Changelog';
PRINT '  ✓ Tier 2 Complete';
PRINT '';
GO

-- ============================================================================
-- TIER 3: PLAN & TIMES TRIGGERS
-- ============================================================================

PRINT '┌──────────────────────────────────────────────────────────────────┐';
PRINT '│ TIER 3: Flight Plan & Times Triggers                            │';
PRINT '└──────────────────────────────────────────────────────────────────┘';
GO

-- Flight Plan Trigger
CREATE OR ALTER TRIGGER dbo.tr_adl_flight_plan_Changelog
ON dbo.adl_flight_plan
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_plan';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();

    -- INSERT
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_route', NULL, LEFT(i.fp_route, 4000), @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.fp_route IS NOT NULL
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_dept_icao', NULL, i.fp_dept_icao, @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.fp_dept_icao IS NOT NULL
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_dest_icao', NULL, i.fp_dest_icao, @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.fp_dest_icao IS NOT NULL;
    END

    -- UPDATE
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_route', LEFT(d.fp_route, 4000), LEFT(i.fp_route, 4000), 'ROUTE_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(LEFT(i.fp_route, 4000), '') <> ISNULL(LEFT(d.fp_route, 4000), '')
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_dept_icao', d.fp_dept_icao, i.fp_dept_icao, 'ORIGIN_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_dept_icao, '') <> ISNULL(d.fp_dept_icao, '')
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_dest_icao', d.fp_dest_icao, i.fp_dest_icao, 'DEST_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_dest_icao, '') <> ISNULL(d.fp_dest_icao, '')
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_altitude_ft', CAST(d.fp_altitude_ft AS NVARCHAR(20)), CAST(i.fp_altitude_ft AS NVARCHAR(20)), NULL, @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_altitude_ft, 0) <> ISNULL(d.fp_altitude_ft, 0)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'dp_name', d.dp_name, i.dp_name, 'SID_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.dp_name, '') <> ISNULL(d.dp_name, '')
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'star_name', d.star_name, i.star_name, 'STAR_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.star_name, '') <> ISNULL(d.star_name, '');
    END

    -- DELETE
    IF EXISTS (SELECT 1 FROM deleted) AND NOT EXISTS (SELECT 1 FROM inserted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT d.flight_uid, c.callsign, 'D', @table_name, 'flight_uid', CAST(d.flight_uid AS NVARCHAR(20)), NULL, @batch_id, @now
        FROM deleted d LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = d.flight_uid;
    END
END;
GO

PRINT '  + Created tr_adl_flight_plan_Changelog';
GO

-- Flight Times Trigger
CREATE OR ALTER TRIGGER dbo.tr_adl_flight_times_Changelog
ON dbo.adl_flight_times
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_times';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @epoch DATETIME2(0) = '1900-01-01';

    -- UPDATE (primary use case for times)
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'eta_utc', CONVERT(NVARCHAR(30), d.eta_utc, 126), CONVERT(NVARCHAR(30), i.eta_utc, 126), NULL, @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.eta_utc, @epoch) <> ISNULL(d.eta_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'etd_utc', CONVERT(NVARCHAR(30), d.etd_utc, 126), CONVERT(NVARCHAR(30), i.etd_utc, 126), NULL, @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.etd_utc, @epoch) <> ISNULL(d.etd_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ctd_utc', CONVERT(NVARCHAR(30), d.ctd_utc, 126), CONVERT(NVARCHAR(30), i.ctd_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ctd_utc, @epoch) <> ISNULL(d.ctd_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'cta_utc', CONVERT(NVARCHAR(30), d.cta_utc, 126), CONVERT(NVARCHAR(30), i.cta_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.cta_utc, @epoch) <> ISNULL(d.cta_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'atd_runway_utc', CONVERT(NVARCHAR(30), d.atd_runway_utc, 126), CONVERT(NVARCHAR(30), i.atd_runway_utc, 126), 'WHEELS_UP', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.atd_runway_utc, @epoch) <> ISNULL(d.atd_runway_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ata_runway_utc', CONVERT(NVARCHAR(30), d.ata_runway_utc, 126), CONVERT(NVARCHAR(30), i.ata_runway_utc, 126), 'WHEELS_DOWN', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ata_runway_utc, @epoch) <> ISNULL(d.ata_runway_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'delay_minutes', CAST(d.delay_minutes AS NVARCHAR(20)), CAST(i.delay_minutes AS NVARCHAR(20)), 'DELAY_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.delay_minutes, 0) <> ISNULL(d.delay_minutes, 0);
    END
END;
GO

PRINT '  + Created tr_adl_flight_times_Changelog';
PRINT '  ✓ Tier 3 Complete';
PRINT '';
GO

-- ============================================================================
-- TIER 4: AIRCRAFT & TMI TRIGGERS
-- ============================================================================

PRINT '┌──────────────────────────────────────────────────────────────────┐';
PRINT '│ TIER 4: Aircraft & TMI Triggers                                 │';
PRINT '└──────────────────────────────────────────────────────────────────┘';
GO

-- Aircraft Trigger
CREATE OR ALTER TRIGGER dbo.tr_adl_flight_aircraft_Changelog
ON dbo.adl_flight_aircraft
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_aircraft';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();

    -- INSERT
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'aircraft_icao', NULL, i.aircraft_icao, @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.aircraft_icao IS NOT NULL
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'airline_icao', NULL, i.airline_icao, @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.airline_icao IS NOT NULL;
    END

    -- UPDATE
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'aircraft_icao', d.aircraft_icao, i.aircraft_icao, 'EQUIPMENT_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.aircraft_icao, '') <> ISNULL(d.aircraft_icao, '')
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'airline_icao', d.airline_icao, i.airline_icao, 'CARRIER_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.airline_icao, '') <> ISNULL(d.airline_icao, '');
    END
END;
GO

PRINT '  + Created tr_adl_flight_aircraft_Changelog';
GO

-- TMI Trigger
CREATE OR ALTER TRIGGER dbo.tr_adl_flight_tmi_Changelog
ON dbo.adl_flight_tmi
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_tmi';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @epoch DATETIME2(0) = '1900-01-01';

    -- INSERT
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'ctl_type', NULL, i.ctl_type, 'TMI_NEW', @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.ctl_type IS NOT NULL
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'edct_utc', NULL, CONVERT(NVARCHAR(30), i.edct_utc, 126), 'TMI_NEW', @batch_id, @now
        FROM inserted i LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid WHERE i.edct_utc IS NOT NULL;
    END

    -- UPDATE
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ctl_type', d.ctl_type, i.ctl_type, 'TMI_UPDATE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ctl_type, '') <> ISNULL(d.ctl_type, '')
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'edct_utc', CONVERT(NVARCHAR(30), d.edct_utc, 126), CONVERT(NVARCHAR(30), i.edct_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.edct_utc, @epoch) <> ISNULL(d.edct_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'slot_time_utc', CONVERT(NVARCHAR(30), d.slot_time_utc, 126), CONVERT(NVARCHAR(30), i.slot_time_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.slot_time_utc, @epoch) <> ISNULL(d.slot_time_utc, @epoch)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'delay_minutes', CAST(d.delay_minutes AS NVARCHAR(20)), CAST(i.delay_minutes AS NVARCHAR(20)), 'DELAY_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.delay_minutes, 0) <> ISNULL(d.delay_minutes, 0)
        UNION ALL
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'is_exempt', CAST(d.is_exempt AS NVARCHAR(5)), CAST(i.is_exempt AS NVARCHAR(5)), 'EXEMPT_CHANGE', @batch_id, @now
        FROM inserted i JOIN deleted d ON d.flight_uid = i.flight_uid LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.is_exempt, 0) <> ISNULL(d.is_exempt, 0);
    END

    -- DELETE
    IF EXISTS (SELECT 1 FROM deleted) AND NOT EXISTS (SELECT 1 FROM inserted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT d.flight_uid, c.callsign, 'D', @table_name, 'ctl_type', d.ctl_type, NULL, 'TMI_REMOVED', @batch_id, @now
        FROM deleted d LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = d.flight_uid WHERE d.ctl_type IS NOT NULL;
    END
END;
GO

PRINT '  + Created tr_adl_flight_tmi_Changelog';
PRINT '  ✓ Tier 4 Complete';
PRINT '';
GO

-- ============================================================================
-- TIER 5: UTILITY PROCEDURES
-- ============================================================================

PRINT '┌──────────────────────────────────────────────────────────────────┐';
PRINT '│ TIER 5: Utility Procedures                                      │';
PRINT '└──────────────────────────────────────────────────────────────────┘';
GO

-- sp_GetFlightHistory
CREATE OR ALTER PROCEDURE dbo.sp_GetFlightHistory
    @callsign NVARCHAR(16) = NULL,
    @flight_uid BIGINT = NULL,
    @hours_back INT = 24
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @cutoff DATETIME2(3) = DATEADD(HOUR, -@hours_back, SYSUTCDATETIME());

    SELECT cl.changelog_id, cl.flight_uid, cl.callsign, cl.change_utc, cl.change_type,
           ct.description AS change_type_desc, cl.source_table, cl.field_name,
           cl.old_value, cl.new_value, cl.change_reason
    FROM dbo.adl_flight_changelog cl
    LEFT JOIN dbo.adl_changelog_change_types ct ON ct.change_type = cl.change_type
    WHERE ((@flight_uid IS NOT NULL AND cl.flight_uid = @flight_uid) OR (@callsign IS NOT NULL AND cl.callsign = @callsign))
      AND cl.change_utc >= @cutoff
    ORDER BY cl.change_utc ASC;
END;
GO
PRINT '  + Created sp_GetFlightHistory';
GO

-- sp_ArchiveChangelog
CREATE OR ALTER PROCEDURE dbo.sp_ArchiveChangelog
    @days_to_keep INT = 90,
    @batch_size INT = 10000,
    @dry_run BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @cutoff DATETIME2(3) = DATEADD(DAY, -@days_to_keep, SYSUTCDATETIME());
    DECLARE @deleted INT = 0, @batch INT = 0, @rows INT = 1;

    DECLARE @eligible INT;
    SELECT @eligible = COUNT(*) FROM dbo.adl_flight_changelog WHERE change_utc < @cutoff;

    IF @dry_run = 1
    BEGIN
        SELECT @eligible AS records_to_delete, @cutoff AS cutoff_date;
        RETURN;
    END

    WHILE @rows > 0 AND @batch < 100
    BEGIN
        DELETE TOP (@batch_size) FROM dbo.adl_flight_changelog WHERE change_utc < @cutoff;
        SET @rows = @@ROWCOUNT;
        SET @deleted = @deleted + @rows;
        SET @batch = @batch + 1;
        IF @rows >= @batch_size WAITFOR DELAY '00:00:01';
    END

    DELETE FROM dbo.adl_changelog_batch WHERE batch_start_utc < @cutoff;
    SELECT @deleted AS rows_archived, @batch AS batches;
END;
GO
PRINT '  + Created sp_ArchiveChangelog';
GO

-- sp_GetChangelogStats
CREATE OR ALTER PROCEDURE dbo.sp_GetChangelogStats @days_back INT = 7
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @cutoff DATETIME2(3) = DATEADD(DAY, -@days_back, SYSUTCDATETIME());

    SELECT CAST(change_utc AS DATE) AS change_date, source_table, change_type, COUNT(*) AS changes
    FROM dbo.adl_flight_changelog WHERE change_utc >= @cutoff
    GROUP BY CAST(change_utc AS DATE), source_table, change_type
    ORDER BY change_date DESC, source_table;
END;
GO
PRINT '  + Created sp_GetChangelogStats';
GO

PRINT '  ✓ Tier 5 Complete';
PRINT '';
GO

-- ============================================================================
-- DEPLOYMENT VERIFICATION
-- ============================================================================

PRINT '┌──────────────────────────────────────────────────────────────────┐';
PRINT '│ DEPLOYMENT VERIFICATION                                         │';
PRINT '└──────────────────────────────────────────────────────────────────┘';
GO

-- Verify triggers
SELECT
    t.name AS table_name,
    tr.name AS trigger_name,
    CASE WHEN tr.is_disabled = 0 THEN '✓ Enabled' ELSE '✗ Disabled' END AS status
FROM sys.triggers tr
JOIN sys.tables t ON tr.parent_id = t.object_id
WHERE tr.name LIKE 'tr_adl_%_Changelog'
ORDER BY t.name;

-- Verify procedures
SELECT
    name AS procedure_name,
    '✓ Created' AS status
FROM sys.procedures
WHERE name IN ('sp_GetFlightHistory', 'sp_ArchiveChangelog', 'sp_GetChangelogStats')
ORDER BY name;

-- Verify tables
SELECT
    name AS table_name,
    '✓ Exists' AS status
FROM sys.tables
WHERE name IN ('adl_flight_changelog', 'adl_changelog_batch', 'adl_changelog_change_types')
ORDER BY name;
GO

PRINT '';
PRINT '╔════════════════════════════════════════════════════════════════════╗';
PRINT '║           DEPLOYMENT COMPLETE                                      ║';
PRINT '╠════════════════════════════════════════════════════════════════════╣';
PRINT '║  All changelog triggers and procedures have been deployed.        ║';
PRINT '║  The system will now automatically track changes to:              ║';
PRINT '║    - adl_flight_core (callsign, phase, status, is_active)         ║';
PRINT '║    - adl_flight_plan (route, airports, SID, STAR)                 ║';
PRINT '║    - adl_flight_times (ETD, ETA, CTD, CTA, OOOI)                  ║';
PRINT '║    - adl_flight_aircraft (equipment, carrier)                     ║';
PRINT '║    - adl_flight_tmi (EDCT, slots, delays, exemptions)             ║';
PRINT '╚════════════════════════════════════════════════════════════════════╝';
GO

-- ============================================================================
-- ROLLBACK SCRIPT (Uncomment to disable triggers)
-- ============================================================================
/*
PRINT 'Rolling back changelog triggers...';

DISABLE TRIGGER dbo.tr_adl_flight_core_Changelog ON dbo.adl_flight_core;
DISABLE TRIGGER dbo.tr_adl_flight_plan_Changelog ON dbo.adl_flight_plan;
DISABLE TRIGGER dbo.tr_adl_flight_times_Changelog ON dbo.adl_flight_times;
DISABLE TRIGGER dbo.tr_adl_flight_aircraft_Changelog ON dbo.adl_flight_aircraft;
DISABLE TRIGGER dbo.tr_adl_flight_tmi_Changelog ON dbo.adl_flight_tmi;

PRINT 'All changelog triggers disabled.';

-- To completely remove:
-- DROP TRIGGER dbo.tr_adl_flight_core_Changelog;
-- DROP TRIGGER dbo.tr_adl_flight_plan_Changelog;
-- DROP TRIGGER dbo.tr_adl_flight_times_Changelog;
-- DROP TRIGGER dbo.tr_adl_flight_aircraft_Changelog;
-- DROP TRIGGER dbo.tr_adl_flight_tmi_Changelog;
-- DROP PROCEDURE dbo.sp_GetFlightHistory;
-- DROP PROCEDURE dbo.sp_ArchiveChangelog;
-- DROP PROCEDURE dbo.sp_GetChangelogStats;
-- DROP TABLE dbo.adl_changelog_batch;
-- DROP TABLE dbo.adl_changelog_change_types;
*/
