-- ============================================================================
-- ADL Changelog System - Migration 002: Flight Core Trigger
--
-- Creates the changelog trigger for adl_flight_core table
-- Tracks: callsign, cid, phase, flight_status, is_active, last_source
--
-- Run Order: 2 of 6
-- Depends on: 001_changelog_schema_upgrade.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Changelog Migration 002: Flight Core Trigger ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Trigger: tr_adl_flight_core_Changelog
-- ============================================================================

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_core_Changelog
ON dbo.adl_flight_core
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_core';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();

    -- =============================================
    -- INSERT: Log all non-null fields for new flights
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT flight_uid, callsign, 'I', @table_name, 'callsign', NULL, callsign, @batch_id, @now
        FROM inserted WHERE callsign IS NOT NULL

        UNION ALL

        SELECT flight_uid, callsign, 'I', @table_name, 'cid', NULL, CAST(cid AS NVARCHAR(20)), @batch_id, @now
        FROM inserted WHERE cid IS NOT NULL

        UNION ALL

        SELECT flight_uid, callsign, 'I', @table_name, 'phase', NULL, phase, @batch_id, @now
        FROM inserted WHERE phase IS NOT NULL

        UNION ALL

        SELECT flight_uid, callsign, 'I', @table_name, 'is_active', NULL, CAST(is_active AS NVARCHAR(5)), @batch_id, @now
        FROM inserted WHERE is_active IS NOT NULL

        UNION ALL

        SELECT flight_uid, callsign, 'I', @table_name, 'last_source', NULL, last_source, @batch_id, @now
        FROM inserted WHERE last_source IS NOT NULL;
    END

    -- =============================================
    -- UPDATE: Log only changed fields
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)

        -- callsign changes
        SELECT i.flight_uid, i.callsign, 'U', @table_name, 'callsign', d.callsign, i.callsign, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        WHERE ISNULL(i.callsign, '') <> ISNULL(d.callsign, '')

        UNION ALL

        -- cid changes (rare but possible)
        SELECT i.flight_uid, i.callsign, 'U', @table_name, 'cid',
               CAST(d.cid AS NVARCHAR(20)), CAST(i.cid AS NVARCHAR(20)), @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        WHERE ISNULL(i.cid, 0) <> ISNULL(d.cid, 0)

        UNION ALL

        -- phase changes (important for lifecycle tracking) - use 'S' for status
        SELECT i.flight_uid, i.callsign, 'S', @table_name, 'phase', d.phase, i.phase, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        WHERE ISNULL(i.phase, '') <> ISNULL(d.phase, '')

        UNION ALL

        -- is_active changes (flight appearing/disappearing)
        SELECT i.flight_uid, i.callsign, 'U', @table_name, 'is_active',
               CAST(d.is_active AS NVARCHAR(5)), CAST(i.is_active AS NVARCHAR(5)), @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        WHERE ISNULL(i.is_active, 0) <> ISNULL(d.is_active, 0)

        UNION ALL

        -- last_source changes
        SELECT i.flight_uid, i.callsign, 'U', @table_name, 'last_source', d.last_source, i.last_source, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        WHERE ISNULL(i.last_source, '') <> ISNULL(d.last_source, '');
    END

    -- =============================================
    -- DELETE: Log flight removal
    -- =============================================
    IF EXISTS (SELECT 1 FROM deleted) AND NOT EXISTS (SELECT 1 FROM inserted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT flight_uid, callsign, 'D', @table_name, 'flight_uid', CAST(flight_uid AS NVARCHAR(20)), NULL, @batch_id, @now
        FROM deleted;
    END
END;
GO

PRINT 'Created trigger dbo.tr_adl_flight_core_Changelog';
GO

PRINT '';
PRINT '=== ADL Changelog Migration 002 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
