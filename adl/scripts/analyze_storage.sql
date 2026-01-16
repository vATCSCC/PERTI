-- ============================================================================
-- VATSIM_ADL Storage Analysis Script
-- Analyzes table sizes, row counts, and identifies optimization targets
-- ============================================================================

SET NOCOUNT ON;

PRINT '=== VATSIM_ADL Storage Analysis ==='
PRINT 'Run Date: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)
PRINT ''

-- ============================================================================
-- 1. Overall Database Size
-- ============================================================================
PRINT '--- 1. DATABASE SIZE OVERVIEW ---'

SELECT
    DB_NAME() AS database_name,
    CAST(SUM(size) * 8.0 / 1024 / 1024 AS DECIMAL(10,2)) AS total_size_gb,
    CAST(SUM(CASE WHEN type = 0 THEN size ELSE 0 END) * 8.0 / 1024 / 1024 AS DECIMAL(10,2)) AS data_size_gb,
    CAST(SUM(CASE WHEN type = 1 THEN size ELSE 0 END) * 8.0 / 1024 / 1024 AS DECIMAL(10,2)) AS log_size_gb
FROM sys.database_files;

-- ============================================================================
-- 2. Table Size Analysis (All Tables)
-- ============================================================================
PRINT ''
PRINT '--- 2. TABLE SIZE BREAKDOWN (Top 20) ---'

SELECT TOP 20
    t.name AS table_name,
    s.name AS schema_name,
    p.rows AS row_count,
    CAST(SUM(a.total_pages) * 8.0 / 1024 AS DECIMAL(12,2)) AS total_space_mb,
    CAST(SUM(a.used_pages) * 8.0 / 1024 AS DECIMAL(12,2)) AS used_space_mb,
    CAST((SUM(a.total_pages) - SUM(a.used_pages)) * 8.0 / 1024 AS DECIMAL(12,2)) AS unused_space_mb,
    CAST(SUM(a.total_pages) * 8.0 / 1024 / 1024 AS DECIMAL(10,4)) AS total_space_gb,
    CAST(100.0 * SUM(a.total_pages) / NULLIF((SELECT SUM(total_pages) FROM sys.allocation_units), 0) AS DECIMAL(5,2)) AS pct_of_db
FROM sys.tables t
INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
INNER JOIN sys.indexes i ON t.object_id = i.object_id
INNER JOIN sys.partitions p ON i.object_id = p.object_id AND i.index_id = p.index_id
INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
WHERE t.is_ms_shipped = 0
GROUP BY t.name, s.name, p.rows
ORDER BY SUM(a.total_pages) DESC;

-- ============================================================================
-- 3. Index Size Analysis
-- ============================================================================
PRINT ''
PRINT '--- 3. INDEX SIZE ANALYSIS (Top 20) ---'

SELECT TOP 20
    OBJECT_NAME(i.object_id) AS table_name,
    i.name AS index_name,
    i.type_desc AS index_type,
    CAST(SUM(s.used_page_count) * 8.0 / 1024 AS DECIMAL(12,2)) AS index_size_mb,
    SUM(s.row_count) AS row_count
FROM sys.dm_db_partition_stats s
INNER JOIN sys.indexes i ON s.object_id = i.object_id AND s.index_id = i.index_id
WHERE OBJECTPROPERTY(i.object_id, 'IsUserTable') = 1
GROUP BY i.object_id, i.name, i.type_desc
ORDER BY SUM(s.used_page_count) DESC;

-- ============================================================================
-- 4. ADL Flight Tables Specific Analysis
-- ============================================================================
PRINT ''
PRINT '--- 4. ADL FLIGHT TABLES BREAKDOWN ---'

SELECT
    t.name AS table_name,
    p.rows AS row_count,
    CAST(SUM(a.total_pages) * 8.0 / 1024 AS DECIMAL(12,2)) AS total_mb,
    CAST(SUM(a.total_pages) * 8.0 / 1024 / 1024 AS DECIMAL(10,4)) AS total_gb,
    CASE
        WHEN p.rows > 0 THEN CAST(SUM(a.used_pages) * 8.0 * 1024 / p.rows AS DECIMAL(10,2))
        ELSE 0
    END AS avg_row_bytes
FROM sys.tables t
INNER JOIN sys.indexes i ON t.object_id = i.object_id
INNER JOIN sys.partitions p ON i.object_id = p.object_id AND i.index_id = p.index_id
INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
WHERE t.name LIKE 'adl_flight%'
GROUP BY t.name, p.rows
ORDER BY SUM(a.total_pages) DESC;

-- ============================================================================
-- 5. Trajectory Table Deep Dive
-- ============================================================================
PRINT ''
PRINT '--- 5. TRAJECTORY DATA ANALYSIS ---'

-- Monthly breakdown
IF OBJECT_ID('dbo.adl_flight_trajectory', 'U') IS NOT NULL
BEGIN
    SELECT
        YEAR(recorded_utc) AS year,
        MONTH(recorded_utc) AS month,
        COUNT(*) AS records,
        COUNT(DISTINCT flight_uid) AS unique_flights,
        CAST(COUNT(*) * 1.0 / NULLIF(COUNT(DISTINCT flight_uid), 0) AS DECIMAL(10,1)) AS avg_points_per_flight
    FROM dbo.adl_flight_trajectory
    GROUP BY YEAR(recorded_utc), MONTH(recorded_utc)
    ORDER BY year DESC, month DESC;
END

-- Tier distribution
PRINT ''
PRINT '--- 5b. TRAJECTORY TIER DISTRIBUTION ---'

IF OBJECT_ID('dbo.adl_flight_trajectory', 'U') IS NOT NULL
BEGIN
    SELECT
        tier,
        tier_reason,
        COUNT(*) AS records,
        CAST(100.0 * COUNT(*) / SUM(COUNT(*)) OVER() AS DECIMAL(5,2)) AS pct
    FROM dbo.adl_flight_trajectory
    WHERE recorded_utc >= DATEADD(DAY, -30, GETUTCDATE())
    GROUP BY tier, tier_reason
    ORDER BY tier, COUNT(*) DESC;
END

-- ============================================================================
-- 6. Changelog Table Analysis
-- ============================================================================
PRINT ''
PRINT '--- 6. CHANGELOG DATA ANALYSIS ---'

IF OBJECT_ID('dbo.adl_flight_changelog', 'U') IS NOT NULL
BEGIN
    SELECT
        target_table,
        COUNT(*) AS records,
        MIN(changed_utc) AS oldest_record,
        MAX(changed_utc) AS newest_record,
        DATEDIFF(DAY, MIN(changed_utc), MAX(changed_utc)) AS days_span
    FROM dbo.adl_flight_changelog
    GROUP BY target_table
    ORDER BY COUNT(*) DESC;
END

-- ============================================================================
-- 7. Data Age Analysis (What can be archived?)
-- ============================================================================
PRINT ''
PRINT '--- 7. DATA AGE ANALYSIS ---'

-- Trajectory age distribution
IF OBJECT_ID('dbo.adl_flight_trajectory', 'U') IS NOT NULL
BEGIN
    SELECT
        CASE
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 7 THEN '0-7 days'
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 30 THEN '8-30 days'
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 90 THEN '31-90 days'
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 180 THEN '91-180 days'
            ELSE '180+ days'
        END AS age_bucket,
        COUNT(*) AS records,
        CAST(100.0 * COUNT(*) / SUM(COUNT(*)) OVER() AS DECIMAL(5,2)) AS pct,
        CAST(COUNT(*) * 200.0 / 1024 / 1024 / 1024 AS DECIMAL(10,4)) AS est_size_gb
    FROM dbo.adl_flight_trajectory
    GROUP BY
        CASE
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 7 THEN '0-7 days'
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 30 THEN '8-30 days'
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 90 THEN '31-90 days'
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 180 THEN '91-180 days'
            ELSE '180+ days'
        END
    ORDER BY
        CASE
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 7 THEN 1
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 30 THEN 2
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 90 THEN 3
            WHEN DATEDIFF(DAY, recorded_utc, GETUTCDATE()) <= 180 THEN 4
            ELSE 5
        END;
END

-- ============================================================================
-- 8. Inactive Flight Analysis
-- ============================================================================
PRINT ''
PRINT '--- 8. INACTIVE FLIGHTS ANALYSIS ---'

IF OBJECT_ID('dbo.adl_flight_core', 'U') IS NOT NULL
BEGIN
    SELECT
        is_active,
        phase,
        COUNT(*) AS flights,
        MIN(first_seen_utc) AS oldest,
        MAX(last_seen_utc) AS newest
    FROM dbo.adl_flight_core
    GROUP BY is_active, phase
    ORDER BY is_active DESC, COUNT(*) DESC;
END

-- ============================================================================
-- 9. Spatial Index Size
-- ============================================================================
PRINT ''
PRINT '--- 9. SPATIAL INDEX OVERHEAD ---'

SELECT
    OBJECT_NAME(i.object_id) AS table_name,
    i.name AS index_name,
    i.type_desc,
    CAST(SUM(ps.used_page_count) * 8.0 / 1024 AS DECIMAL(12,2)) AS size_mb
FROM sys.indexes i
INNER JOIN sys.dm_db_partition_stats ps ON i.object_id = ps.object_id AND i.index_id = ps.index_id
WHERE i.type_desc = 'SPATIAL'
GROUP BY i.object_id, i.name, i.type_desc;

-- ============================================================================
-- 10. Compression Candidates
-- ============================================================================
PRINT ''
PRINT '--- 10. COMPRESSION ANALYSIS ---'

SELECT
    t.name AS table_name,
    p.data_compression_desc AS current_compression,
    p.rows,
    CAST(SUM(a.total_pages) * 8.0 / 1024 AS DECIMAL(12,2)) AS size_mb,
    CASE p.data_compression_desc
        WHEN 'NONE' THEN 'Could save 50-70% with PAGE compression'
        WHEN 'ROW' THEN 'Could save additional 20-30% with PAGE'
        ELSE 'Already compressed'
    END AS recommendation
FROM sys.tables t
INNER JOIN sys.partitions p ON t.object_id = p.object_id
INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
WHERE t.name LIKE 'adl_%' AND p.rows > 10000
GROUP BY t.name, p.data_compression_desc, p.rows
ORDER BY SUM(a.total_pages) DESC;

PRINT ''
PRINT '=== Analysis Complete ==='
GO
