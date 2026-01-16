-- ============================================================================
-- ARCHIVE: adl_flights_history (Legacy Table)
--
-- This table contains historical flight data from Dec 9 - Jan 6, 2026
-- It was replaced by the new normalized schema (adl_flight_core, etc.)
--
-- Size: 209.35 GB | Records: 213.8 million
-- STATUS: No longer receiving writes - safe to archive/drop
--
-- OPTION 1: Export to Blob Storage then DROP (Recommended)
-- OPTION 2: Keep compressed for SQL queries
-- OPTION 3: Delete if historical data not needed
-- ============================================================================

SET NOCOUNT ON;

PRINT '=== Legacy adl_flights_history Archive Script ==='
PRINT 'Current size: ~209 GB'
PRINT 'Records: ~213.8 million'
PRINT 'Date range: Dec 9, 2025 - Jan 6, 2026'
PRINT ''

-- ============================================================================
-- OPTION 1A: Enable Page Compression (if keeping in SQL)
-- Estimated savings: 50-70% = ~105-145 GB freed
-- Time estimate: 2-4 hours (can run online)
-- ============================================================================

-- Check current compression state
SELECT
    p.data_compression_desc,
    COUNT(*) AS partition_count,
    SUM(p.rows) AS total_rows
FROM sys.partitions p
WHERE p.object_id = OBJECT_ID('dbo.adl_flights_history')
GROUP BY p.data_compression_desc;

-- To compress (run during low-usage period):
-- ALTER TABLE dbo.adl_flights_history REBUILD WITH (DATA_COMPRESSION = PAGE);

-- ============================================================================
-- OPTION 1B: Export to Parquet/CSV for Blob Archive
-- Use Azure Data Factory or BCP for large exports
-- ============================================================================

-- Export via BCP (run from command line):
-- bcp "SELECT * FROM VATSIM_ADL.dbo.adl_flights_history" queryout "adl_flights_history.csv" -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P "PASSWORD" -c -t","

-- ============================================================================
-- OPTION 2: Create Summary Table Before Dropping
-- Keep aggregated statistics without raw data
-- ============================================================================

IF OBJECT_ID('dbo.adl_flights_history_summary', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.adl_flights_history_summary (
        summary_date DATE NOT NULL,
        hour_utc TINYINT NOT NULL,
        fp_dept_icao CHAR(4) NULL,
        fp_dest_icao CHAR(4) NULL,
        phase NVARCHAR(16) NULL,
        total_flights INT NOT NULL,
        avg_altitude INT NULL,
        avg_groundspeed INT NULL,
        PRIMARY KEY (summary_date, hour_utc, fp_dept_icao, fp_dest_icao, phase)
    ) WITH (DATA_COMPRESSION = PAGE);

    PRINT 'Created summary table template';
END

-- Populate summary (run before dropping source):
/*
INSERT INTO dbo.adl_flights_history_summary
SELECT
    CAST(snapshot_utc AS DATE) AS summary_date,
    DATEPART(HOUR, snapshot_utc) AS hour_utc,
    fp_dept_icao,
    fp_dest_icao,
    phase,
    COUNT(*) AS total_flights,
    AVG(altitude) AS avg_altitude,
    AVG(groundspeed) AS avg_groundspeed
FROM dbo.adl_flights_history
GROUP BY
    CAST(snapshot_utc AS DATE),
    DATEPART(HOUR, snapshot_utc),
    fp_dept_icao,
    fp_dest_icao,
    phase;
*/

-- ============================================================================
-- OPTION 3: DROP TABLE (after backup/export)
-- ============================================================================

-- DANGER ZONE: Only run after confirming backup!
-- DROP TABLE dbo.adl_flights_history;
-- DROP TABLE dbo.adl_flights_history_cool;  -- Also unused

-- ============================================================================
-- COST IMPACT ANALYSIS
-- ============================================================================
/*
Current State:
- adl_flights_history: 209.35 GB (79% of database)
- adl_flights_history_cool: 7.32 GB (3% of database)
- TOTAL LEGACY: ~217 GB

Azure SQL Hyperscale Storage Cost:
- Storage: $0.10/GB/month (first 10 TB)
- Current legacy storage cost: 217 GB × $0.10 = ~$22/month

Azure Blob Storage (Cool Tier) Cost:
- Storage: $0.01/GB/month
- Same data in blob: 217 GB × $0.01 = ~$2.17/month

SAVINGS IF DROPPED/ARCHIVED: ~$20/month storage
PLUS: Reduced compute costs from smaller working set
*/

PRINT '=== Review options above and execute chosen path ==='
GO
