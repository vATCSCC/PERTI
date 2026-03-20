-- ============================================================================
-- Fix sp_Archive_CompletedFlights: Remove flight_status reference
-- and disable changelog triggers during cascade delete
--
-- Migration 007 (core) dropped flight_status from adl_flight_core,
-- but sp_Archive_CompletedFlights still references c.flight_status.
-- Fix: populate flight_status in the archive from c.phase instead.
-- Also: changelog triggers fire during cascade delete and try to INSERT
-- into adl_flight_changelog referencing the flight_uid being deleted,
-- causing FK_changelog_core violation. Fix: disable triggers during delete.
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Fix sp_Archive_CompletedFlights: flight_status + trigger safety ===';
GO

CREATE OR ALTER PROCEDURE dbo.sp_Archive_CompletedFlights
    @hours_since_last_seen INT = NULL,
    @batch_size INT = NULL,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @rows_archived INT = 0;
    DECLARE @rows_deleted INT = 0;
    DECLARE @cutoff_utc DATETIME2(0);

    -- Get config values
    IF @hours_since_last_seen IS NULL
        SELECT @hours_since_last_seen = CAST(config_value AS INT)
        FROM dbo.adl_archive_config WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS';
    SET @hours_since_last_seen = ISNULL(@hours_since_last_seen, 2);

    IF @batch_size IS NULL
        SELECT @batch_size = CAST(config_value AS INT)
        FROM dbo.adl_archive_config WHERE config_key = 'ARCHIVE_BATCH_SIZE';
    SET @batch_size = ISNULL(@batch_size, 1000);

    SET @cutoff_utc = DATEADD(HOUR, -@hours_since_last_seen, SYSUTCDATETIME());

    IF @debug = 1
        PRINT 'Archiving flights not seen since: ' + CONVERT(VARCHAR, @cutoff_utc, 120);

    -- Log start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Archive_CompletedFlights', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Collect flight_uids to archive
        CREATE TABLE #flights_to_archive (flight_uid BIGINT PRIMARY KEY);

        INSERT INTO #flights_to_archive (flight_uid)
        SELECT TOP (@batch_size) c.flight_uid
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 0
          AND c.last_seen_utc < @cutoff_utc
          AND NOT EXISTS (
              SELECT 1 FROM dbo.adl_flight_archive arc
              WHERE arc.flight_uid = c.flight_uid
          )
        ORDER BY c.last_seen_utc ASC;

        IF @debug = 1
            SELECT COUNT(*) AS flights_to_archive FROM #flights_to_archive;

        -- Archive flights with all related data denormalized
        -- Note: flight_status populated from c.phase (flight_status column was dropped in migration 007)
        INSERT INTO dbo.adl_flight_archive (
            flight_uid, flight_key, cid, callsign, phase, flight_status,
            first_seen_utc, last_seen_utc, logon_time_utc,
            fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_route, fp_altitude_ft,
            dp_name, star_name, dfix, afix, fp_dept_artcc, fp_dest_artcc,
            fp_dept_tracon, fp_dest_tracon, gcd_nm, aircraft_type,
            aircraft_icao, weight_class, engine_type, wake_category, airline_icao, airline_name,
            etd_utc, eta_utc, atd_utc, ata_utc,
            ctl_type, delay_minutes, edct_utc,
            flight_duration_min, total_distance_nm, max_altitude_ft,
            trajectory_points, changelog_entries
        )
        SELECT
            c.flight_uid, c.flight_key, c.cid, c.callsign, c.phase, c.phase,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao, fp.fp_route, fp.fp_altitude_ft,
            fp.dp_name, fp.star_name, fp.dfix, fp.afix, fp.fp_dept_artcc, fp.fp_dest_artcc,
            fp.fp_dept_tracon, fp.fp_dest_tracon, fp.gcd_nm, fp.aircraft_type,
            a.aircraft_icao, a.weight_class, a.engine_type, a.wake_category, a.airline_icao, a.airline_name,
            t.etd_utc, t.eta_utc, t.atd_utc, t.ata_utc,
            tmi.ctl_type, tmi.delay_minutes, tmi.edct_utc,
            DATEDIFF(MINUTE, c.first_seen_utc, c.last_seen_utc) AS flight_duration_min,
            fp.gcd_nm AS total_distance_nm,
            (SELECT MAX(altitude_ft) FROM dbo.adl_flight_trajectory tr WHERE tr.flight_uid = c.flight_uid) AS max_altitude_ft,
            (SELECT COUNT(*) FROM dbo.adl_flight_trajectory tr WHERE tr.flight_uid = c.flight_uid) AS trajectory_points,
            (SELECT COUNT(*) FROM dbo.adl_flight_changelog cl WHERE cl.flight_uid = c.flight_uid) AS changelog_entries
        FROM #flights_to_archive fta
        JOIN dbo.adl_flight_core c ON c.flight_uid = fta.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid;

        SET @rows_archived = @@ROWCOUNT;

        -- Disable changelog triggers before cascade delete to prevent FK_changelog_core violation.
        -- The cascade delete on adl_flight_core triggers changelog triggers on child tables,
        -- which try to INSERT into adl_flight_changelog referencing the flight_uid being deleted.
        DISABLE TRIGGER dbo.tr_adl_flight_core_Changelog ON dbo.adl_flight_core;
        DISABLE TRIGGER dbo.tr_adl_flight_plan_Changelog ON dbo.adl_flight_plan;
        DISABLE TRIGGER dbo.tr_adl_flight_aircraft_Changelog ON dbo.adl_flight_aircraft;
        DISABLE TRIGGER dbo.tr_adl_flight_times_Changelog ON dbo.adl_flight_times;
        DISABLE TRIGGER dbo.tr_adl_flight_tmi_Changelog ON dbo.adl_flight_tmi;

        -- Delete changelog entries for these flights (already counted in archive)
        DELETE cl
        FROM dbo.adl_flight_changelog cl
        INNER JOIN #flights_to_archive fta ON fta.flight_uid = cl.flight_uid;

        -- Delete archived flights from live tables (CASCADE handles related tables)
        DELETE c
        FROM dbo.adl_flight_core c
        INNER JOIN #flights_to_archive fta ON fta.flight_uid = c.flight_uid;

        SET @rows_deleted = @@ROWCOUNT;

        -- Re-enable changelog triggers
        ENABLE TRIGGER dbo.tr_adl_flight_core_Changelog ON dbo.adl_flight_core;
        ENABLE TRIGGER dbo.tr_adl_flight_plan_Changelog ON dbo.adl_flight_plan;
        ENABLE TRIGGER dbo.tr_adl_flight_aircraft_Changelog ON dbo.adl_flight_aircraft;
        ENABLE TRIGGER dbo.tr_adl_flight_times_Changelog ON dbo.adl_flight_times;
        ENABLE TRIGGER dbo.tr_adl_flight_tmi_Changelog ON dbo.adl_flight_tmi;

        DROP TABLE #flights_to_archive;

        COMMIT TRANSACTION;

        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            rows_processed = @rows_archived,
            rows_archived = @rows_archived,
            rows_deleted = @rows_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        -- Re-enable triggers even on failure
        BEGIN TRY
            ENABLE TRIGGER dbo.tr_adl_flight_core_Changelog ON dbo.adl_flight_core;
            ENABLE TRIGGER dbo.tr_adl_flight_plan_Changelog ON dbo.adl_flight_plan;
            ENABLE TRIGGER dbo.tr_adl_flight_aircraft_Changelog ON dbo.adl_flight_aircraft;
            ENABLE TRIGGER dbo.tr_adl_flight_times_Changelog ON dbo.adl_flight_times;
            ENABLE TRIGGER dbo.tr_adl_flight_tmi_Changelog ON dbo.adl_flight_tmi;
        END TRY
        BEGIN CATCH
            -- Swallow nested errors from re-enable
        END CATCH

        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;

        IF @debug = 1
            THROW;
    END CATCH

    SELECT @rows_archived AS flights_archived, @rows_deleted AS rows_deleted_from_live;
END;
GO

PRINT 'Fixed sp_Archive_CompletedFlights: flight_status + trigger safety';
GO
