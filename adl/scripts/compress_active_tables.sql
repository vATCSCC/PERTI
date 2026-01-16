-- ============================================================================
-- COMPRESS: Active Tables Optimization
--
-- Applies PAGE compression to uncompressed tables
-- PAGE compression provides 50-70% space reduction for historical data
--
-- Tables to compress:
-- - adl_flight_changelog: 40 GB → ~16 GB (save ~24 GB)
-- - adl_flight_trajectory: 1.5 GB → ~0.6 GB (save ~0.9 GB)
-- - wind_grid: 1.7 GB → ~0.7 GB (save ~1 GB)
-- ============================================================================

SET NOCOUNT ON;

PRINT '=== Active Tables Compression Script ==='
PRINT 'Run during low-usage period (2-4 AM UTC recommended)'
PRINT ''

-- ============================================================================
-- Pre-check: Current compression status
-- ============================================================================

PRINT '--- Current Compression Status ---'
SELECT
    t.name AS table_name,
    p.data_compression_desc AS current_compression,
    SUM(p.rows) AS total_rows,
    CAST(SUM(a.total_pages) * 8.0 / 1024 / 1024 AS DECIMAL(10,2)) AS size_gb
FROM sys.tables t
INNER JOIN sys.partitions p ON t.object_id = p.object_id
INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
WHERE t.name IN ('adl_flight_changelog', 'adl_flight_trajectory', 'wind_grid',
                 'adl_zone_events', 'adl_flight_boundary_log', 'adl_flight_waypoints')
GROUP BY t.name, p.data_compression_desc
ORDER BY SUM(a.total_pages) DESC;

-- ============================================================================
-- 1. Compress adl_flight_changelog (40 GB → ~16 GB)
-- ============================================================================

PRINT ''
PRINT '--- Compressing adl_flight_changelog ---'
PRINT 'Estimated time: 10-20 minutes'
PRINT 'Estimated savings: ~24 GB'

-- This runs ONLINE - reads/writes continue during compression
ALTER TABLE dbo.adl_flight_changelog REBUILD WITH (DATA_COMPRESSION = PAGE, ONLINE = ON);

PRINT 'adl_flight_changelog compression complete'
GO

-- ============================================================================
-- 2. Compress adl_flight_trajectory (1.5 GB → ~0.6 GB)
-- ============================================================================

PRINT ''
PRINT '--- Compressing adl_flight_trajectory ---'
PRINT 'Estimated time: 2-5 minutes'
PRINT 'Estimated savings: ~0.9 GB'

ALTER TABLE dbo.adl_flight_trajectory REBUILD WITH (DATA_COMPRESSION = PAGE, ONLINE = ON);

PRINT 'adl_flight_trajectory compression complete'
GO

-- ============================================================================
-- 3. Compress wind_grid (1.7 GB → ~0.7 GB)
-- ============================================================================

PRINT ''
PRINT '--- Compressing wind_grid ---'
PRINT 'Estimated time: 2-5 minutes'
PRINT 'Estimated savings: ~1 GB'

ALTER TABLE dbo.wind_grid REBUILD WITH (DATA_COMPRESSION = PAGE, ONLINE = ON);

PRINT 'wind_grid compression complete'
GO

-- ============================================================================
-- 4. Compress adl_zone_events (563 MB → ~225 MB)
-- ============================================================================

PRINT ''
PRINT '--- Compressing adl_zone_events ---'

ALTER TABLE dbo.adl_zone_events REBUILD WITH (DATA_COMPRESSION = PAGE, ONLINE = ON);

PRINT 'adl_zone_events compression complete'
GO

-- ============================================================================
-- 5. Compress adl_flight_boundary_log (366 MB → ~146 MB)
-- ============================================================================

PRINT ''
PRINT '--- Compressing adl_flight_boundary_log ---'

ALTER TABLE dbo.adl_flight_boundary_log REBUILD WITH (DATA_COMPRESSION = PAGE, ONLINE = ON);

PRINT 'adl_flight_boundary_log compression complete'
GO

-- ============================================================================
-- 6. Compress adl_flight_waypoints (1 GB → ~400 MB)
-- ============================================================================

PRINT ''
PRINT '--- Compressing adl_flight_waypoints ---'

ALTER TABLE dbo.adl_flight_waypoints REBUILD WITH (DATA_COMPRESSION = PAGE, ONLINE = ON);

PRINT 'adl_flight_waypoints compression complete'
GO

-- ============================================================================
-- Post-check: Verify compression
-- ============================================================================

PRINT ''
PRINT '--- Post-Compression Status ---'
SELECT
    t.name AS table_name,
    p.data_compression_desc AS compression,
    SUM(p.rows) AS total_rows,
    CAST(SUM(a.total_pages) * 8.0 / 1024 / 1024 AS DECIMAL(10,2)) AS size_gb
FROM sys.tables t
INNER JOIN sys.partitions p ON t.object_id = p.object_id
INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
WHERE t.name IN ('adl_flight_changelog', 'adl_flight_trajectory', 'wind_grid',
                 'adl_zone_events', 'adl_flight_boundary_log', 'adl_flight_waypoints')
GROUP BY t.name, p.data_compression_desc
ORDER BY SUM(a.total_pages) DESC;

PRINT ''
PRINT '=== Compression Complete ==='
PRINT 'Expected total savings: ~27 GB'
GO
