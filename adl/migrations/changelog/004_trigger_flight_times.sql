-- ============================================================================
-- ADL Changelog System - Migration 004: Flight Times Trigger
--
-- Creates the changelog trigger for adl_flight_times table
-- Tracks: ETD, ETA, CTD, CTA, EDCT, OOOI times, delay_minutes
--
-- Run Order: 4 of 6
-- Depends on: 001_changelog_schema_upgrade.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Changelog Migration 004: Flight Times Trigger ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Trigger: tr_adl_flight_times_Changelog
-- ============================================================================

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_times_Changelog
ON dbo.adl_flight_times
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_times';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @epoch_1900 DATETIME2(0) = '1900-01-01';

    -- =============================================
    -- UPDATE: Log time field changes
    -- (Times table is usually created with the flight, so INSERT tracking is minimal)
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)

        -- ETD (estimated time of departure)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'etd_utc',
               CONVERT(NVARCHAR(30), d.etd_utc, 126),
               CONVERT(NVARCHAR(30), i.etd_utc, 126), NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.etd_utc, @epoch_1900) <> ISNULL(d.etd_utc, @epoch_1900)

        UNION ALL

        -- ETD Runway
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'etd_runway_utc',
               CONVERT(NVARCHAR(30), d.etd_runway_utc, 126),
               CONVERT(NVARCHAR(30), i.etd_runway_utc, 126), NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.etd_runway_utc, @epoch_1900) <> ISNULL(d.etd_runway_utc, @epoch_1900)

        UNION ALL

        -- ATD (actual time of departure)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'atd_utc',
               CONVERT(NVARCHAR(30), d.atd_utc, 126),
               CONVERT(NVARCHAR(30), i.atd_utc, 126), 'DEPARTED', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.atd_utc, @epoch_1900) <> ISNULL(d.atd_utc, @epoch_1900)

        UNION ALL

        -- ATD Runway (wheels up)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'atd_runway_utc',
               CONVERT(NVARCHAR(30), d.atd_runway_utc, 126),
               CONVERT(NVARCHAR(30), i.atd_runway_utc, 126), 'WHEELS_UP', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.atd_runway_utc, @epoch_1900) <> ISNULL(d.atd_runway_utc, @epoch_1900)

        UNION ALL

        -- ETA (estimated time of arrival)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'eta_utc',
               CONVERT(NVARCHAR(30), d.eta_utc, 126),
               CONVERT(NVARCHAR(30), i.eta_utc, 126), NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.eta_utc, @epoch_1900) <> ISNULL(d.eta_utc, @epoch_1900)

        UNION ALL

        -- ETA Runway
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'eta_runway_utc',
               CONVERT(NVARCHAR(30), d.eta_runway_utc, 126),
               CONVERT(NVARCHAR(30), i.eta_runway_utc, 126), NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.eta_runway_utc, @epoch_1900) <> ISNULL(d.eta_runway_utc, @epoch_1900)

        UNION ALL

        -- ATA (actual time of arrival)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ata_utc',
               CONVERT(NVARCHAR(30), d.ata_utc, 126),
               CONVERT(NVARCHAR(30), i.ata_utc, 126), 'ARRIVED', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ata_utc, @epoch_1900) <> ISNULL(d.ata_utc, @epoch_1900)

        UNION ALL

        -- ATA Runway (wheels down)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ata_runway_utc',
               CONVERT(NVARCHAR(30), d.ata_runway_utc, 126),
               CONVERT(NVARCHAR(30), i.ata_runway_utc, 126), 'WHEELS_DOWN', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ata_runway_utc, @epoch_1900) <> ISNULL(d.ata_runway_utc, @epoch_1900)

        UNION ALL

        -- CTD (controlled time of departure)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ctd_utc',
               CONVERT(NVARCHAR(30), d.ctd_utc, 126),
               CONVERT(NVARCHAR(30), i.ctd_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ctd_utc, @epoch_1900) <> ISNULL(d.ctd_utc, @epoch_1900)

        UNION ALL

        -- CTA (controlled time of arrival)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'cta_utc',
               CONVERT(NVARCHAR(30), d.cta_utc, 126),
               CONVERT(NVARCHAR(30), i.cta_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.cta_utc, @epoch_1900) <> ISNULL(d.cta_utc, @epoch_1900)

        UNION ALL

        -- EDCT (expect departure clearance time)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'edct_utc',
               CONVERT(NVARCHAR(30), d.edct_utc, 126),
               CONVERT(NVARCHAR(30), i.edct_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.edct_utc, @epoch_1900) <> ISNULL(d.edct_utc, @epoch_1900)

        UNION ALL

        -- Center entry time
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'center_entry_utc',
               CONVERT(NVARCHAR(30), d.center_entry_utc, 126),
               CONVERT(NVARCHAR(30), i.center_entry_utc, 126), 'BOUNDARY_CROSSING', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.center_entry_utc, @epoch_1900) <> ISNULL(d.center_entry_utc, @epoch_1900)

        UNION ALL

        -- Center exit time
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'center_exit_utc',
               CONVERT(NVARCHAR(30), d.center_exit_utc, 126),
               CONVERT(NVARCHAR(30), i.center_exit_utc, 126), 'BOUNDARY_CROSSING', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.center_exit_utc, @epoch_1900) <> ISNULL(d.center_exit_utc, @epoch_1900)

        UNION ALL

        -- Delay minutes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'delay_minutes',
               CAST(d.delay_minutes AS NVARCHAR(20)),
               CAST(i.delay_minutes AS NVARCHAR(20)), 'DELAY_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.delay_minutes, 0) <> ISNULL(d.delay_minutes, 0)

        UNION ALL

        -- ETE (estimated time enroute)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ete_minutes',
               CAST(d.ete_minutes AS NVARCHAR(20)),
               CAST(i.ete_minutes AS NVARCHAR(20)), NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ete_minutes, 0) <> ISNULL(d.ete_minutes, 0);
    END

    -- =============================================
    -- INSERT: Log initial times when row is created
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)

        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'eta_utc',
               NULL, CONVERT(NVARCHAR(30), i.eta_utc, 126), @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.eta_utc IS NOT NULL

        UNION ALL

        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'etd_utc',
               NULL, CONVERT(NVARCHAR(30), i.etd_utc, 126), @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.etd_utc IS NOT NULL;
    END
END;
GO

PRINT 'Created trigger dbo.tr_adl_flight_times_Changelog';
GO

PRINT '';
PRINT '=== ADL Changelog Migration 004 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
