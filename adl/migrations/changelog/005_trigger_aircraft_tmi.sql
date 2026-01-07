-- ============================================================================
-- ADL Changelog System - Migration 005: Aircraft & TMI Triggers
--
-- Creates the changelog triggers for adl_flight_aircraft and adl_flight_tmi
-- Aircraft: tracks equipment and carrier changes
-- TMI: tracks traffic management initiative assignments and changes
--
-- Run Order: 5 of 6
-- Depends on: 001_changelog_schema_upgrade.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Changelog Migration 005: Aircraft & TMI Triggers ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Trigger: tr_adl_flight_aircraft_Changelog
-- ============================================================================

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_aircraft_Changelog
ON dbo.adl_flight_aircraft
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_aircraft';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();

    -- =============================================
    -- INSERT: Log initial aircraft assignment
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)

        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'aircraft_icao',
               NULL, i.aircraft_icao, @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.aircraft_icao IS NOT NULL

        UNION ALL

        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'airline_icao',
               NULL, i.airline_icao, @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.airline_icao IS NOT NULL

        UNION ALL

        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'weight_class',
               NULL, i.weight_class, @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.weight_class IS NOT NULL;
    END

    -- =============================================
    -- UPDATE: Log aircraft/carrier changes (rare but important)
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)

        -- Aircraft type changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'aircraft_icao',
               d.aircraft_icao, i.aircraft_icao, 'EQUIPMENT_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.aircraft_icao, '') <> ISNULL(d.aircraft_icao, '')

        UNION ALL

        -- Airline/carrier changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'airline_icao',
               d.airline_icao, i.airline_icao, 'CARRIER_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.airline_icao, '') <> ISNULL(d.airline_icao, '')

        UNION ALL

        -- Weight class changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'weight_class',
               d.weight_class, i.weight_class, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.weight_class, '') <> ISNULL(d.weight_class, '')

        UNION ALL

        -- Wake category changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'wake_category',
               d.wake_category, i.wake_category, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.wake_category, '') <> ISNULL(d.wake_category, '');
    END
END;
GO

PRINT 'Created trigger dbo.tr_adl_flight_aircraft_Changelog';
GO

-- ============================================================================
-- Trigger: tr_adl_flight_tmi_Changelog
-- ============================================================================

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_tmi_Changelog
ON dbo.adl_flight_tmi
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_tmi';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @epoch_1900 DATETIME2(0) = '1900-01-01';

    -- =============================================
    -- INSERT: Log new TMI assignments
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)

        -- Control type assignment
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'ctl_type',
               NULL, i.ctl_type, 'TMI_NEW', @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.ctl_type IS NOT NULL

        UNION ALL

        -- EDCT assignment
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'edct_utc',
               NULL, CONVERT(NVARCHAR(30), i.edct_utc, 126), 'TMI_NEW', @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.edct_utc IS NOT NULL

        UNION ALL

        -- Slot assignment
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'slot_time_utc',
               NULL, CONVERT(NVARCHAR(30), i.slot_time_utc, 126), 'TMI_NEW', @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.slot_time_utc IS NOT NULL;
    END

    -- =============================================
    -- UPDATE: TMI changes are critical for traffic management tracking
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)

        -- Control type changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ctl_type',
               d.ctl_type, i.ctl_type, 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ctl_type, '') <> ISNULL(d.ctl_type, '')

        UNION ALL

        -- CTD changes (from adl_flight_tmi, not times)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'ctd_utc',
               CONVERT(NVARCHAR(30), d.ctd_utc, 126),
               CONVERT(NVARCHAR(30), i.ctd_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.ctd_utc, @epoch_1900) <> ISNULL(d.ctd_utc, @epoch_1900)

        UNION ALL

        -- CTA changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'cta_utc',
               CONVERT(NVARCHAR(30), d.cta_utc, 126),
               CONVERT(NVARCHAR(30), i.cta_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.cta_utc, @epoch_1900) <> ISNULL(d.cta_utc, @epoch_1900)

        UNION ALL

        -- EDCT changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'edct_utc',
               CONVERT(NVARCHAR(30), d.edct_utc, 126),
               CONVERT(NVARCHAR(30), i.edct_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.edct_utc, @epoch_1900) <> ISNULL(d.edct_utc, @epoch_1900)

        UNION ALL

        -- Slot time changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'slot_time_utc',
               CONVERT(NVARCHAR(30), d.slot_time_utc, 126),
               CONVERT(NVARCHAR(30), i.slot_time_utc, 126), 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.slot_time_utc, @epoch_1900) <> ISNULL(d.slot_time_utc, @epoch_1900)

        UNION ALL

        -- Slot status changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'slot_status',
               d.slot_status, i.slot_status, 'TMI_UPDATE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.slot_status, '') <> ISNULL(d.slot_status, '')

        UNION ALL

        -- Delay minutes changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'delay_minutes',
               CAST(d.delay_minutes AS NVARCHAR(20)),
               CAST(i.delay_minutes AS NVARCHAR(20)), 'DELAY_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.delay_minutes, 0) <> ISNULL(d.delay_minutes, 0)

        UNION ALL

        -- Delay status changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'delay_status',
               d.delay_status, i.delay_status, 'DELAY_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.delay_status, '') <> ISNULL(d.delay_status, '')

        UNION ALL

        -- Exempt status changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'is_exempt',
               CAST(d.is_exempt AS NVARCHAR(5)),
               CAST(i.is_exempt AS NVARCHAR(5)), 'EXEMPT_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.is_exempt, 0) <> ISNULL(d.is_exempt, 0)

        UNION ALL

        -- Exempt reason changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'exempt_reason',
               d.exempt_reason, i.exempt_reason, 'EXEMPT_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.exempt_reason, '') <> ISNULL(d.exempt_reason, '')

        UNION ALL

        -- Reroute status changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'reroute_status',
               d.reroute_status, i.reroute_status, 'REROUTE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.reroute_status, '') <> ISNULL(d.reroute_status, '');
    END

    -- =============================================
    -- DELETE: Log TMI removal
    -- =============================================
    IF EXISTS (SELECT 1 FROM deleted) AND NOT EXISTS (SELECT 1 FROM inserted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)
        SELECT d.flight_uid, c.callsign, 'D', @table_name, 'ctl_type',
               d.ctl_type, NULL, 'TMI_REMOVED', @batch_id, @now
        FROM deleted d
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = d.flight_uid
        WHERE d.ctl_type IS NOT NULL;
    END
END;
GO

PRINT 'Created trigger dbo.tr_adl_flight_tmi_Changelog';
GO

PRINT '';
PRINT '=== ADL Changelog Migration 005 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
