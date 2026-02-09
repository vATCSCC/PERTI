/*
    ADL Raw Data Lake - External Tables

    Step 2: Create external tables for querying Parquet files.

    External tables provide SQL access to Parquet files in blob storage.
    The v_trajectory_archive view uses OPENROWSET with filepath() to extract
    partition values (year/month/day) from the Hive-style folder structure.

    IMPORTANT: Synapse Serverless doesn't auto-extract partition columns from
    folder paths into external table columns like Spark/Hive does. Use the
    v_trajectory_archive view which handles this via filepath().

    Run in Synapse Studio with "Use database: ADL_Archive"

    Author: Claude (AI-assisted implementation)
    Date: 2026-02-02
*/

USE ADL_Archive;
GO

-- =============================================================================
-- Trajectory External Table (Raw)
-- =============================================================================
-- Points to all Parquet files under trajectory/ folder
-- Does NOT include partition columns (use v_trajectory_archive view instead)

-- To recreate: DROP EXTERNAL TABLE dbo.trajectory_archive;

CREATE EXTERNAL TABLE dbo.trajectory_archive
(
    flight_uid          BIGINT,
    callsign            VARCHAR(10),
    dept_icao           VARCHAR(4),
    dest_icao           VARCHAR(4),
    timestamp_utc       DATETIME2,
    lat                 FLOAT,
    lon                 FLOAT,
    altitude_ft         INT,
    groundspeed_kts     INT,
    heading_deg         INT,
    vertical_rate_fpm   INT
)
WITH (
    LOCATION = 'trajectory/',
    DATA_SOURCE = ADL_RawArchive,
    FILE_FORMAT = ParquetFormat
);
GO

-- =============================================================================
-- Main View: Extracts Partition Columns via filepath()
-- =============================================================================
-- Uses OPENROWSET to read Parquet files and extract year/month/day from
-- the Hive-style folder structure: trajectory/year=YYYY/month=MM/day=DD/
--
-- ALWAYS use this view for queries - it provides proper partition columns
-- for filtering and partition pruning.

CREATE OR ALTER VIEW dbo.v_trajectory_archive
AS
SELECT
    flight_uid,
    callsign,
    dept_icao,
    dest_icao,
    timestamp_utc,
    lat,
    lon,
    altitude_ft,
    groundspeed_kts,
    heading_deg,
    vertical_rate_fpm,
    -- Extract partition values from folder path (year=XXXX/month=XX/day=XX)
    CAST(r.filepath(1) AS INT) AS [year],
    CAST(r.filepath(2) AS INT) AS [month],
    CAST(r.filepath(3) AS INT) AS [day],
    DATEFROMPARTS(CAST(r.filepath(1) AS INT), CAST(r.filepath(2) AS INT), CAST(r.filepath(3) AS INT)) AS flight_date
FROM OPENROWSET(
    BULK 'trajectory/year=*/month=*/day=*/*.parquet',
    DATA_SOURCE = 'ADL_RawArchive',
    FORMAT = 'PARQUET'
) WITH (
    flight_uid          BIGINT,
    callsign            VARCHAR(10),
    dept_icao           VARCHAR(4),
    dest_icao           VARCHAR(4),
    timestamp_utc       DATETIME2,
    lat                 FLOAT,
    lon                 FLOAT,
    altitude_ft         INT,
    groundspeed_kts     INT,
    heading_deg         INT,
    vertical_rate_fpm   INT
) AS r;
GO

-- =============================================================================
-- Verify objects were created
-- =============================================================================

SELECT 'External Table' as Type, name
FROM sys.external_tables
WHERE name = 'trajectory_archive';

SELECT 'View' as Type, name
FROM sys.views
WHERE name = 'v_trajectory_archive';
GO

PRINT 'External tables and views created successfully.';
PRINT 'Next: Run 03_create_views.sql for common query patterns';
GO
