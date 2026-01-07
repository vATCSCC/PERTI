#!/usr/bin/env python3
"""Fix archive procedures to use correct column names"""

import pymssql

SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USERNAME = 'jpeterson'
PASSWORD = '***REMOVED***'

# SQL fixes using correct column names
FIX_SQL = """
-- Fix view v_archive_stats to use correct column names
IF EXISTS (SELECT * FROM sys.views WHERE name = 'v_archive_stats')
    DROP VIEW dbo.v_archive_stats;
"""

CREATE_VIEW = """
CREATE VIEW dbo.v_archive_stats
AS
SELECT
    'adl_flight_trajectory' AS table_name,
    COUNT(*) AS row_count,
    MIN(recorded_utc) AS oldest_record,
    MAX(recorded_utc) AS newest_record,
    'HOT' AS tier
FROM dbo.adl_flight_trajectory

UNION ALL

SELECT
    'adl_trajectory_archive',
    COUNT(*),
    MIN(timestamp_utc),
    MAX(timestamp_utc),
    source_tier
FROM dbo.adl_trajectory_archive
GROUP BY source_tier

UNION ALL

SELECT
    'adl_flight_archive',
    COUNT(*),
    MIN(archived_utc),
    MAX(archived_utc),
    'ARCHIVE'
FROM dbo.adl_flight_archive

UNION ALL

SELECT
    'adl_flight_changelog',
    COUNT(*),
    MIN(change_utc),
    MAX(change_utc),
    'CHANGELOG'
FROM dbo.adl_flight_changelog;
"""

# Fix sp_Archive_Trajectory_ToWarm
FIX_WARM_PROC = """
CREATE OR ALTER PROCEDURE dbo.sp_Archive_Trajectory_ToWarm
    @batch_size INT = NULL,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @rows_archived INT = 0;
    DECLARE @rows_deleted INT = 0;
    DECLARE @hot_hours INT;
    DECLARE @cutoff_utc DATETIME2(0);

    SELECT @hot_hours = CAST(config_value AS INT)
    FROM dbo.adl_archive_config WHERE config_key = 'TRAJECTORY_HOT_HOURS';
    SET @hot_hours = ISNULL(@hot_hours, 24);

    IF @batch_size IS NULL
        SELECT @batch_size = CAST(config_value AS INT)
        FROM dbo.adl_archive_config WHERE config_key = 'ARCHIVE_BATCH_SIZE';
    SET @batch_size = ISNULL(@batch_size, 10000);

    SET @cutoff_utc = DATEADD(HOUR, -@hot_hours, SYSUTCDATETIME());

    IF @debug = 1
        PRINT 'Moving trajectory older than: ' + CONVERT(VARCHAR, @cutoff_utc, 120);

    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Archive_Trajectory_ToWarm', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        WHILE 1 = 1
        BEGIN
            INSERT INTO dbo.adl_trajectory_archive (
                flight_uid, callsign, timestamp_utc, lat, lon,
                altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm,
                sample_interval_sec, source_tier
            )
            SELECT TOP (@batch_size)
                t.flight_uid,
                c.callsign,
                t.recorded_utc,
                t.lat,
                t.lon,
                t.altitude_ft,
                t.groundspeed_kts,
                t.heading_deg,
                t.vertical_rate_fpm,
                60,
                'WARM'
            FROM dbo.adl_flight_trajectory t
            LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE t.recorded_utc < @cutoff_utc
            ORDER BY t.recorded_utc ASC;

            IF @@ROWCOUNT = 0 BREAK;
            SET @rows_archived = @rows_archived + @@ROWCOUNT;

            DELETE TOP (@batch_size) FROM dbo.adl_flight_trajectory
            WHERE recorded_utc < @cutoff_utc;

            SET @rows_deleted = @rows_deleted + @@ROWCOUNT;

            IF @rows_archived > @batch_size * 5
                WAITFOR DELAY '00:00:01';
        END

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
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;

        IF @debug = 1
            THROW;
    END CATCH

    SELECT @rows_archived AS rows_archived, @rows_deleted AS rows_deleted;
END;
"""

# Fix sp_Purge_OldData
FIX_PURGE_PROC = """
CREATE OR ALTER PROCEDURE dbo.sp_Purge_OldData
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @batch_size INT;
    DECLARE @trajectory_days INT, @changelog_days INT, @flight_days INT, @runlog_days INT;
    DECLARE @traj_deleted INT = 0, @changelog_deleted INT = 0;
    DECLARE @flight_deleted INT = 0, @runlog_deleted INT = 0;
    DECLARE @total_deleted INT = 0;
    DECLARE @deleted_batch INT;

    SELECT @trajectory_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'TRAJECTORY_COLD_DAYS';
    SELECT @changelog_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'CHANGELOG_RETENTION_DAYS';
    SELECT @flight_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'FLIGHT_ARCHIVE_RETENTION_DAYS';
    SELECT @runlog_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'RUN_LOG_RETENTION_DAYS';
    SELECT @batch_size = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'ARCHIVE_BATCH_SIZE';

    SET @trajectory_days = ISNULL(@trajectory_days, 90);
    SET @changelog_days = ISNULL(@changelog_days, 90);
    SET @flight_days = ISNULL(@flight_days, 365);
    SET @runlog_days = ISNULL(@runlog_days, 30);
    SET @batch_size = ISNULL(@batch_size, 10000);

    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Purge_OldData', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@batch_size) FROM dbo.adl_trajectory_archive
            WHERE archived_utc < DATEADD(DAY, -@trajectory_days, SYSUTCDATETIME());

            SET @deleted_batch = @@ROWCOUNT;
            SET @traj_deleted = @traj_deleted + @deleted_batch;

            IF @deleted_batch = 0 BREAK;
            IF @deleted_batch < @batch_size BREAK;

            WAITFOR DELAY '00:00:00.500';
        END

        WHILE 1 = 1
        BEGIN
            DELETE TOP (@batch_size) FROM dbo.adl_flight_changelog
            WHERE change_utc < DATEADD(DAY, -@changelog_days, SYSUTCDATETIME());

            SET @deleted_batch = @@ROWCOUNT;
            SET @changelog_deleted = @changelog_deleted + @deleted_batch;

            IF @deleted_batch = 0 BREAK;
            IF @deleted_batch < @batch_size BREAK;

            WAITFOR DELAY '00:00:00.500';
        END

        WHILE 1 = 1
        BEGIN
            DELETE TOP (@batch_size) FROM dbo.adl_flight_archive
            WHERE archived_utc < DATEADD(DAY, -@flight_days, SYSUTCDATETIME());

            SET @deleted_batch = @@ROWCOUNT;
            SET @flight_deleted = @flight_deleted + @deleted_batch;

            IF @deleted_batch = 0 BREAK;
            IF @deleted_batch < @batch_size BREAK;

            WAITFOR DELAY '00:00:00.500';
        END

        IF EXISTS (SELECT 1 FROM sys.objects WHERE name = 'adl_run_log' AND type = 'U')
        BEGIN
            WHILE 1 = 1
            BEGIN
                DELETE TOP (@batch_size) FROM dbo.adl_run_log
                WHERE started_utc < DATEADD(DAY, -@runlog_days, SYSUTCDATETIME());

                SET @deleted_batch = @@ROWCOUNT;
                SET @runlog_deleted = @runlog_deleted + @deleted_batch;

                IF @deleted_batch = 0 BREAK;
                IF @deleted_batch < @batch_size BREAK;
            END
        END

        DELETE FROM dbo.adl_archive_log
        WHERE started_utc < DATEADD(DAY, -90, SYSUTCDATETIME())
          AND log_id <> @log_id;

        SET @total_deleted = @traj_deleted + @changelog_deleted + @flight_deleted + @runlog_deleted;

        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            rows_deleted = @total_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;

    END TRY
    BEGIN CATCH
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;

        IF @debug = 1
            THROW;
    END CATCH

    SELECT
        @traj_deleted AS trajectory_deleted,
        @changelog_deleted AS changelog_deleted,
        @flight_deleted AS flights_deleted,
        @runlog_deleted AS runlog_deleted,
        @total_deleted AS total_deleted;
END;
"""

# Fix sp_Get_Flight_History
FIX_HISTORY_PROC = """
CREATE OR ALTER PROCEDURE dbo.sp_Get_Flight_History
    @callsign NVARCHAR(16) = NULL,
    @flight_uid BIGINT = NULL,
    @cid INT = NULL,
    @dept_icao CHAR(4) = NULL,
    @dest_icao CHAR(4) = NULL,
    @from_date DATE = NULL,
    @to_date DATE = NULL,
    @include_trajectory BIT = 0,
    @include_changelog BIT = 0,
    @max_results INT = 100
AS
BEGIN
    SET NOCOUNT ON;

    IF @callsign IS NULL AND @flight_uid IS NULL AND @cid IS NULL AND @from_date IS NULL
        SET @from_date = DATEADD(DAY, -30, CAST(SYSUTCDATETIME() AS DATE));

    SET @to_date = ISNULL(@to_date, CAST(SYSUTCDATETIME() AS DATE));

    SELECT TOP (@max_results)
        archive_id,
        flight_uid,
        callsign,
        cid,
        fp_dept_icao AS origin,
        fp_dest_icao AS destination,
        first_seen_utc,
        last_seen_utc,
        flight_duration_min,
        aircraft_type,
        aircraft_icao,
        airline_icao,
        airline_name,
        phase AS final_phase,
        fp_route AS route,
        fp_altitude_ft AS altitude,
        gcd_nm AS distance_nm,
        max_altitude_ft,
        trajectory_points,
        changelog_entries,
        archived_utc
    FROM dbo.adl_flight_archive
    WHERE (@callsign IS NULL OR callsign = @callsign)
      AND (@flight_uid IS NULL OR flight_uid = @flight_uid)
      AND (@cid IS NULL OR cid = @cid)
      AND (@dept_icao IS NULL OR fp_dept_icao = @dept_icao)
      AND (@dest_icao IS NULL OR fp_dest_icao = @dest_icao)
      AND (@from_date IS NULL OR CAST(first_seen_utc AS DATE) >= @from_date)
      AND (CAST(first_seen_utc AS DATE) <= @to_date)
    ORDER BY first_seen_utc DESC;

    IF @include_trajectory = 1 AND @flight_uid IS NOT NULL
    BEGIN
        SELECT 'HOT' AS tier, recorded_utc AS timestamp_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg
        FROM dbo.adl_flight_trajectory
        WHERE flight_uid = @flight_uid

        UNION ALL

        SELECT source_tier, timestamp_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg
        FROM dbo.adl_trajectory_archive
        WHERE flight_uid = @flight_uid

        ORDER BY timestamp_utc ASC;
    END

    IF @include_changelog = 1 AND @flight_uid IS NOT NULL
    BEGIN
        SELECT change_utc, source_table, field_name, old_value, new_value
        FROM dbo.adl_flight_changelog
        WHERE flight_uid = @flight_uid
        ORDER BY change_utc ASC;
    END
END;
"""

# Fix sp_Get_Flight_Track
FIX_TRACK_PROC = """
CREATE OR ALTER PROCEDURE dbo.sp_Get_Flight_Track
    @flight_uid BIGINT = NULL,
    @callsign NVARCHAR(16) = NULL,
    @simplify BIT = 0,
    @max_points INT = 1000
AS
BEGIN
    SET NOCOUNT ON;

    IF @flight_uid IS NULL AND @callsign IS NOT NULL
    BEGIN
        SELECT TOP 1 @flight_uid = flight_uid
        FROM dbo.adl_flight_core
        WHERE callsign = @callsign
        ORDER BY last_seen_utc DESC;

        IF @flight_uid IS NULL
        BEGIN
            SELECT TOP 1 @flight_uid = flight_uid
            FROM dbo.adl_flight_archive
            WHERE callsign = @callsign
            ORDER BY last_seen_utc DESC;
        END
    END

    IF @flight_uid IS NULL
    BEGIN
        SELECT 'ERROR' AS status, 'Flight not found' AS message;
        RETURN;
    END

    ;WITH AllTrajectory AS (
        SELECT
            'HOT' AS tier,
            recorded_utc AS timestamp_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            ROW_NUMBER() OVER (ORDER BY recorded_utc) AS rn,
            COUNT(*) OVER () AS total_count
        FROM dbo.adl_flight_trajectory
        WHERE flight_uid = @flight_uid

        UNION ALL

        SELECT
            source_tier,
            timestamp_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            ROW_NUMBER() OVER (ORDER BY timestamp_utc) AS rn,
            COUNT(*) OVER () AS total_count
        FROM dbo.adl_trajectory_archive
        WHERE flight_uid = @flight_uid
    )
    SELECT
        tier,
        timestamp_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        heading_deg,
        vertical_rate_fpm
    FROM AllTrajectory
    WHERE @simplify = 0
       OR rn % CEILING(CAST(total_count AS FLOAT) / @max_points) = 1
       OR rn = 1
       OR rn = total_count
    ORDER BY timestamp_utc ASC;
END;
"""

# Fix sp_Trajectory_Stats
FIX_STATS_PROC = """
CREATE OR ALTER PROCEDURE dbo.sp_Trajectory_Stats
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        'HOT (Live)' AS tier,
        COUNT(*) AS position_count,
        COUNT(DISTINCT flight_uid) AS flight_count,
        MIN(recorded_utc) AS oldest,
        MAX(recorded_utc) AS newest,
        DATEDIFF(HOUR, MIN(recorded_utc), MAX(recorded_utc)) AS hours_span,
        CAST(COUNT(*) * 80 / 1024.0 / 1024.0 AS DECIMAL(10,2)) AS est_size_mb
    FROM dbo.adl_flight_trajectory

    UNION ALL

    SELECT
        'WARM (Archive)',
        COUNT(*),
        COUNT(DISTINCT flight_uid),
        MIN(timestamp_utc),
        MAX(timestamp_utc),
        DATEDIFF(HOUR, MIN(timestamp_utc), MAX(timestamp_utc)),
        CAST(COUNT(*) * 80 / 1024.0 / 1024.0 AS DECIMAL(10,2))
    FROM dbo.adl_trajectory_archive
    WHERE source_tier = 'WARM'

    UNION ALL

    SELECT
        'COLD (Archive)',
        COUNT(*),
        COUNT(DISTINCT flight_uid),
        MIN(timestamp_utc),
        MAX(timestamp_utc),
        DATEDIFF(HOUR, MIN(timestamp_utc), MAX(timestamp_utc)),
        CAST(COUNT(*) * 80 / 1024.0 / 1024.0 AS DECIMAL(10,2))
    FROM dbo.adl_trajectory_archive
    WHERE source_tier = 'COLD';

    SELECT
        CAST(recorded_utc AS DATE) AS log_date,
        COUNT(*) AS positions_logged,
        COUNT(DISTINCT flight_uid) AS flights_tracked
    FROM dbo.adl_flight_trajectory
    GROUP BY CAST(recorded_utc AS DATE)
    ORDER BY log_date DESC;
END;
"""

# Create sp_Log_Trajectory (the underscore version that calls existing sp_LogTrajectory)
CREATE_LOG_TRAJECTORY = """
CREATE OR ALTER PROCEDURE dbo.sp_Log_Trajectory
    @force_log BIT = 0
AS
BEGIN
    -- Wrapper that calls the existing sp_LogTrajectory procedure
    EXEC dbo.sp_LogTrajectory;
END;
"""

# Fix fn_IsTrajectoryLoggingDue
FIX_LOGGING_DUE_FN = """
CREATE OR ALTER FUNCTION dbo.fn_IsTrajectoryLoggingDue()
RETURNS BIT
AS
BEGIN
    DECLARE @log_interval_sec INT;
    DECLARE @last_log_utc DATETIME2(0);
    DECLARE @seconds_since_last INT;

    SELECT @log_interval_sec = CAST(config_value AS INT)
    FROM dbo.adl_archive_config
    WHERE config_key = 'TRAJECTORY_LOG_INTERVAL_SEC';

    SET @log_interval_sec = ISNULL(@log_interval_sec, 60);

    SELECT @last_log_utc = MAX(recorded_utc)
    FROM dbo.adl_flight_trajectory;

    IF @last_log_utc IS NULL
        RETURN 1;

    SET @seconds_since_last = DATEDIFF(SECOND, @last_log_utc, SYSUTCDATETIME());

    IF @seconds_since_last >= @log_interval_sec
        RETURN 1;

    RETURN 0;
END;
"""

def main():
    print(f"Connecting to {SERVER}/{DATABASE}...")
    conn = pymssql.connect(server=SERVER, user=USERNAME, password=PASSWORD, database=DATABASE, tds_version='7.3')
    cursor = conn.cursor()
    print("Connected!")

    batches = [
        ("Drop old view", FIX_SQL),
        ("Create v_archive_stats view", CREATE_VIEW),
        ("Fix sp_Archive_Trajectory_ToWarm", FIX_WARM_PROC),
        ("Fix sp_Purge_OldData", FIX_PURGE_PROC),
        ("Fix sp_Get_Flight_History", FIX_HISTORY_PROC),
        ("Fix sp_Get_Flight_Track", FIX_TRACK_PROC),
        ("Fix sp_Trajectory_Stats", FIX_STATS_PROC),
        ("Create sp_Log_Trajectory wrapper", CREATE_LOG_TRAJECTORY),
        ("Fix fn_IsTrajectoryLoggingDue", FIX_LOGGING_DUE_FN),
    ]

    for name, sql in batches:
        try:
            cursor.execute(sql)
            conn.commit()
            print(f"  OK: {name}")
        except Exception as e:
            print(f"  ERROR ({name}): {str(e)[:80]}")

    cursor.close()
    conn.close()
    print("\nFixes applied!")

if __name__ == '__main__':
    main()
