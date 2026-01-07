-- ============================================================================
-- ADL Changelog System - Migration 003: Flight Plan Trigger
--
-- Creates the changelog trigger for adl_flight_plan table
-- Tracks: route, origin, destination, altitude, SID, STAR, fixes, ARTCCs
--
-- Run Order: 3 of 6
-- Depends on: 001_changelog_schema_upgrade.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Changelog Migration 003: Flight Plan Trigger ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Trigger: tr_adl_flight_plan_Changelog
-- ============================================================================

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_plan_Changelog
ON dbo.adl_flight_plan
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @batch_id UNIQUEIDENTIFIER = NEWID();
    DECLARE @table_name NVARCHAR(50) = 'adl_flight_plan';
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();

    -- =============================================
    -- INSERT: Log key fields for new flight plans
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND NOT EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)

        -- Route
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_route', NULL, LEFT(i.fp_route, 4000), @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.fp_route IS NOT NULL

        UNION ALL

        -- Departure airport
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_dept_icao', NULL, i.fp_dept_icao, @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.fp_dept_icao IS NOT NULL

        UNION ALL

        -- Destination airport
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_dest_icao', NULL, i.fp_dest_icao, @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.fp_dest_icao IS NOT NULL

        UNION ALL

        -- Altitude
        SELECT i.flight_uid, c.callsign, 'I', @table_name, 'fp_altitude_ft', NULL, CAST(i.fp_altitude_ft AS NVARCHAR(20)), @batch_id, @now
        FROM inserted i
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE i.fp_altitude_ft IS NOT NULL;
    END

    -- =============================================
    -- UPDATE: Log route and flight plan changes
    -- =============================================
    IF EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, change_reason, batch_id, change_utc)

        -- Route changes (very important)
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_route',
               LEFT(d.fp_route, 4000), LEFT(i.fp_route, 4000), 'ROUTE_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(LEFT(i.fp_route, 4000), '') <> ISNULL(LEFT(d.fp_route, 4000), '')

        UNION ALL

        -- Origin changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_dept_icao',
               d.fp_dept_icao, i.fp_dept_icao, 'ORIGIN_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_dept_icao, '') <> ISNULL(d.fp_dept_icao, '')

        UNION ALL

        -- Destination changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_dest_icao',
               d.fp_dest_icao, i.fp_dest_icao, 'DEST_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_dest_icao, '') <> ISNULL(d.fp_dest_icao, '')

        UNION ALL

        -- Altitude changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_altitude_ft',
               CAST(d.fp_altitude_ft AS NVARCHAR(20)), CAST(i.fp_altitude_ft AS NVARCHAR(20)), NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_altitude_ft, 0) <> ISNULL(d.fp_altitude_ft, 0)

        UNION ALL

        -- SID/DP changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'dp_name',
               d.dp_name, i.dp_name, 'SID_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.dp_name, '') <> ISNULL(d.dp_name, '')

        UNION ALL

        -- STAR changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'star_name',
               d.star_name, i.star_name, 'STAR_CHANGE', @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.star_name, '') <> ISNULL(d.star_name, '')

        UNION ALL

        -- Departure fix changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'dfix',
               d.dfix, i.dfix, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.dfix, '') <> ISNULL(d.dfix, '')

        UNION ALL

        -- Arrival fix changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'afix',
               d.afix, i.afix, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.afix, '') <> ISNULL(d.afix, '')

        UNION ALL

        -- Departure ARTCC changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_dept_artcc',
               d.fp_dept_artcc, i.fp_dept_artcc, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_dept_artcc, '') <> ISNULL(d.fp_dept_artcc, '')

        UNION ALL

        -- Destination ARTCC changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'fp_dest_artcc',
               d.fp_dest_artcc, i.fp_dest_artcc, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.fp_dest_artcc, '') <> ISNULL(d.fp_dest_artcc, '')

        UNION ALL

        -- Departure runway changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'dep_runway',
               d.dep_runway, i.dep_runway, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.dep_runway, '') <> ISNULL(d.dep_runway, '')

        UNION ALL

        -- Arrival runway changes
        SELECT i.flight_uid, c.callsign, 'U', @table_name, 'arr_runway',
               d.arr_runway, i.arr_runway, NULL, @batch_id, @now
        FROM inserted i
        JOIN deleted d ON d.flight_uid = i.flight_uid
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = i.flight_uid
        WHERE ISNULL(i.arr_runway, '') <> ISNULL(d.arr_runway, '');
    END

    -- =============================================
    -- DELETE: Log flight plan removal
    -- =============================================
    IF EXISTS (SELECT 1 FROM deleted) AND NOT EXISTS (SELECT 1 FROM inserted)
    BEGIN
        INSERT INTO dbo.adl_flight_changelog
            (flight_uid, callsign, change_type, source_table, field_name, old_value, new_value, batch_id, change_utc)
        SELECT d.flight_uid, c.callsign, 'D', @table_name, 'flight_uid',
               CAST(d.flight_uid AS NVARCHAR(20)), NULL, @batch_id, @now
        FROM deleted d
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = d.flight_uid;
    END
END;
GO

PRINT 'Created trigger dbo.tr_adl_flight_plan_Changelog';
GO

PRINT '';
PRINT '=== ADL Changelog Migration 003 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
