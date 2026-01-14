-- ============================================================================
-- Boundary Crossings Diagnostic Script
-- Run this to check deployment status and identify issues
-- ============================================================================

SET NOCOUNT ON;
PRINT '=== BOUNDARY CROSSINGS DIAGNOSTIC ===';
PRINT 'Run time: ' + CONVERT(VARCHAR, SYSUTCDATETIME(), 120) + ' UTC';
PRINT '';

-- ============================================================================
-- 1. Check stored procedures
-- ============================================================================
PRINT '1. STORED PROCEDURES:';
PRINT '---------------------';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_ProcessBoundaryAndCrossings_Background') AND type = 'P')
BEGIN
    PRINT 'sp_ProcessBoundaryAndCrossings_Background: EXISTS';
    SELECT 'sp_ProcessBoundaryAndCrossings_Background' AS proc_name, modify_date
    FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_ProcessBoundaryAndCrossings_Background');
END
ELSE
    PRINT 'sp_ProcessBoundaryAndCrossings_Background: MISSING - DEPLOY NEEDED!';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_DetectRegionalFlight') AND type = 'P')
    PRINT 'sp_DetectRegionalFlight: EXISTS';
ELSE
    PRINT 'sp_DetectRegionalFlight: MISSING - DEPLOY NEEDED!';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_CalculatePlannedCrossings') AND type = 'P')
    PRINT 'sp_CalculatePlannedCrossings: EXISTS';
ELSE
    PRINT 'sp_CalculatePlannedCrossings: MISSING - DEPLOY NEEDED!';

PRINT '';

-- ============================================================================
-- 2. Check required tables
-- ============================================================================
PRINT '2. REQUIRED TABLES:';
PRINT '-------------------';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_boundary_log') AND type = 'U')
    PRINT 'adl_flight_boundary_log: EXISTS';
ELSE
    PRINT 'adl_flight_boundary_log: MISSING - RUN 001_boundaries_schema.sql!';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_planned_crossings') AND type = 'U')
    PRINT 'adl_flight_planned_crossings: EXISTS';
ELSE
    PRINT 'adl_flight_planned_crossings: MISSING - RUN 001_planned_crossings_schema.sql!';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_boundary_grid') AND type = 'U')
    PRINT 'adl_boundary_grid: EXISTS';
ELSE
    PRINT 'adl_boundary_grid: MISSING - RUN 009_boundary_grid_lookup.sql!';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_region_group') AND type = 'U')
    PRINT 'adl_region_group: EXISTS';
ELSE
    PRINT 'adl_region_group: MISSING - RUN 001_planned_crossings_schema.sql!';

PRINT '';

-- ============================================================================
-- 3. Check column names in adl_flight_core
-- ============================================================================
PRINT '3. COLUMN CHECK (adl_flight_core):';
PRINT '----------------------------------';

-- Check for phase vs flight_phase
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'phase')
    PRINT 'Column "phase": EXISTS';
ELSE
    PRINT 'Column "phase": MISSING';

IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'flight_phase')
    PRINT 'Column "flight_phase": EXISTS';
ELSE
    PRINT 'Column "flight_phase": MISSING';

-- Check crossing columns
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'crossing_region_flags')
    PRINT 'Column "crossing_region_flags": EXISTS';
ELSE
    PRINT 'Column "crossing_region_flags": MISSING - RUN 002_flight_core_crossing_columns.sql!';

IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'crossing_last_calc_utc')
    PRINT 'Column "crossing_last_calc_utc": EXISTS';
ELSE
    PRINT 'Column "crossing_last_calc_utc": MISSING - RUN 002_flight_core_crossing_columns.sql!';

IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'current_artcc')
    PRINT 'Column "current_artcc": EXISTS';
ELSE
    PRINT 'Column "current_artcc": MISSING';

PRINT '';

-- ============================================================================
-- 4. Check data state
-- ============================================================================
PRINT '4. DATA STATE:';
PRINT '--------------';

-- Boundaries
SELECT 'adl_boundary' AS [table], COUNT(*) AS total_rows,
       SUM(CASE WHEN boundary_type = 'ARTCC' THEN 1 ELSE 0 END) AS artcc_count,
       SUM(CASE WHEN boundary_type = 'TRACON' THEN 1 ELSE 0 END) AS tracon_count,
       SUM(CASE WHEN boundary_type LIKE 'SECTOR%' THEN 1 ELSE 0 END) AS sector_count
FROM dbo.adl_boundary;

-- Boundary grid
SELECT 'adl_boundary_grid' AS [table], COUNT(*) AS total_rows,
       COUNT(DISTINCT boundary_id) AS unique_boundaries
FROM dbo.adl_boundary_grid;

-- Active flights
SELECT 'adl_flight_core (active)' AS [table],
       COUNT(*) AS active_flights,
       SUM(CASE WHEN current_artcc IS NOT NULL THEN 1 ELSE 0 END) AS with_artcc,
       SUM(CASE WHEN crossing_region_flags IS NOT NULL THEN 1 ELSE 0 END) AS with_region_flags,
       SUM(CASE WHEN crossing_last_calc_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_crossing_calc
FROM dbo.adl_flight_core
WHERE is_active = 1;

-- Flight boundary log (last hour)
SELECT 'adl_flight_boundary_log (1hr)' AS [table],
       COUNT(*) AS total_entries,
       COUNT(DISTINCT flight_uid) AS unique_flights,
       SUM(CASE WHEN boundary_type = 'ARTCC' THEN 1 ELSE 0 END) AS artcc_entries,
       SUM(CASE WHEN boundary_type = 'TRACON' THEN 1 ELSE 0 END) AS tracon_entries
FROM dbo.adl_flight_boundary_log
WHERE entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME());

-- Flight boundary log (last 24 hours)
SELECT 'adl_flight_boundary_log (24hr)' AS [table],
       COUNT(*) AS total_entries,
       MAX(entry_time) AS last_entry
FROM dbo.adl_flight_boundary_log
WHERE entry_time > DATEADD(HOUR, -24, SYSUTCDATETIME());

-- Planned crossings
SELECT 'adl_flight_planned_crossings (1hr)' AS [table],
       COUNT(*) AS total_crossings,
       COUNT(DISTINCT flight_uid) AS unique_flights,
       MAX(calculated_at) AS last_calc
FROM dbo.adl_flight_planned_crossings
WHERE calculated_at > DATEADD(HOUR, -1, SYSUTCDATETIME());

PRINT '';

-- ============================================================================
-- 5. Check phase column values
-- ============================================================================
PRINT '5. PHASE COLUMN VALUES:';
PRINT '-----------------------';

SELECT 'phase distribution' AS [check], phase, COUNT(*) AS flight_count
FROM dbo.adl_flight_core
WHERE is_active = 1
GROUP BY phase
ORDER BY flight_count DESC;

-- Check if flight_phase is populated (if it exists)
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'flight_phase')
BEGIN
    PRINT '';
    PRINT 'flight_phase column exists - checking values:';
    EXEC sp_executesql N'SELECT ''flight_phase distribution'' AS [check], flight_phase, COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE is_active = 1 GROUP BY flight_phase';
END

PRINT '';

-- ============================================================================
-- 6. Check pending flights for processing
-- ============================================================================
PRINT '6. PENDING FLIGHTS FOR PROCESSING:';
PRINT '----------------------------------';

-- Flights pending boundary detection
SELECT 'Pending boundary detection' AS [check], COUNT(*) AS pending_count
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND p.lat IS NOT NULL
  AND (c.current_artcc_id IS NULL
       OR c.last_grid_lat IS NULL
       OR c.last_grid_lat != CAST(FLOOR(p.lat / 0.5) AS SMALLINT)
       OR c.last_grid_lon != CAST(FLOOR(p.lon / 0.5) AS SMALLINT));

-- Flights pending crossing calculation
SELECT 'Pending crossing calc' AS [check], COUNT(*) AS pending_count
FROM dbo.adl_flight_core c
WHERE c.is_active = 1
  AND c.crossing_region_flags IS NOT NULL
  AND (c.crossing_last_calc_utc IS NULL OR c.crossing_needs_recalc = 1);

-- Region group
SELECT 'adl_region_group' AS [table], region_id, region_code, region_name
FROM dbo.adl_region_group;

PRINT '';

-- ============================================================================
-- 7. Recent boundary log entries (sample)
-- ============================================================================
PRINT '7. RECENT BOUNDARY LOG ENTRIES:';
PRINT '-------------------------------';

SELECT TOP 10
    bl.log_id, bl.flight_uid, bl.boundary_type, bl.boundary_code,
    bl.entry_time, bl.exit_time, bl.duration_seconds
FROM dbo.adl_flight_boundary_log bl
ORDER BY bl.entry_time DESC;

PRINT '';
PRINT '=== END DIAGNOSTIC ===';
PRINT '';
PRINT 'SUMMARY:';
PRINT '--------';
PRINT 'If "top crossings" is empty on status.php, check:';
PRINT '1. Is the boundary daemon running? (php boundary_daemon.php --loop)';
PRINT '2. Are there entries in adl_flight_boundary_log from the last hour?';
PRINT '3. Are stored procedures deployed and up to date?';
PRINT '4. Does the SP use the correct column name (phase vs flight_phase)?';
GO
