-- ============================================================================
-- ADL Diagnostic Script
-- Run this to check your current schema and identify any issues
-- ============================================================================

PRINT '=== ADL Schema Diagnostic ===';
PRINT '';

-- 1. Check apts table columns
PRINT '1. APTS TABLE COLUMNS:';
SELECT COLUMN_NAME, DATA_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'apts'
ORDER BY ORDINAL_POSITION;

-- 2. Check if airlines table exists
PRINT '';
PRINT '2. AIRLINES TABLE:';
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airlines') AND type = 'U')
    PRINT 'EXISTS - checking columns...';
ELSE
    PRINT 'DOES NOT EXIST - airline_name will be NULL';

SELECT COLUMN_NAME, DATA_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'airlines';

-- 3. Check current SP version
PRINT '';
PRINT '3. STORED PROCEDURE CHECK:';
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_Adl_RefreshFromVatsim_Normalized') AND type = 'P')
BEGIN
    PRINT 'sp_Adl_RefreshFromVatsim_Normalized EXISTS';
    SELECT create_date, modify_date 
    FROM sys.objects 
    WHERE object_id = OBJECT_ID(N'dbo.sp_Adl_RefreshFromVatsim_Normalized');
END
ELSE
    PRINT 'sp_Adl_RefreshFromVatsim_Normalized DOES NOT EXIST - needs to be deployed!';

-- 4. Check sample data in normalized tables
PRINT '';
PRINT '4. SAMPLE DATA CHECK:';

SELECT 'adl_flight_core' AS [table], COUNT(*) AS [rows], 
       SUM(CASE WHEN flight_status IS NOT NULL THEN 1 ELSE 0 END) AS [with_flight_status]
FROM dbo.adl_flight_core;

SELECT 'adl_flight_position' AS [table], COUNT(*) AS [rows],
       SUM(CASE WHEN dist_to_dest_nm IS NOT NULL THEN 1 ELSE 0 END) AS [with_dist_to_dest],
       SUM(CASE WHEN pct_complete IS NOT NULL THEN 1 ELSE 0 END) AS [with_pct_complete]
FROM dbo.adl_flight_position;

SELECT 'adl_flight_plan' AS [table], COUNT(*) AS [rows],
       SUM(CASE WHEN fp_dept_artcc IS NOT NULL THEN 1 ELSE 0 END) AS [with_dept_artcc],
       SUM(CASE WHEN fp_dest_tracon IS NOT NULL THEN 1 ELSE 0 END) AS [with_dest_tracon]
FROM dbo.adl_flight_plan;

SELECT 'adl_flight_aircraft' AS [table], COUNT(*) AS [rows],
       SUM(CASE WHEN aircraft_faa IS NOT NULL THEN 1 ELSE 0 END) AS [with_aircraft_faa],
       SUM(CASE WHEN engine_type IS NOT NULL THEN 1 ELSE 0 END) AS [with_engine_type]
FROM dbo.adl_flight_aircraft;

SELECT 'adl_parse_queue' AS [table], COUNT(*) AS [rows],
       SUM(CASE WHEN route_hash IS NOT NULL THEN 1 ELSE 0 END) AS [with_route_hash],
       SUM(CASE WHEN started_utc IS NOT NULL THEN 1 ELSE 0 END) AS [with_started_utc]
FROM dbo.adl_parse_queue;

-- 5. Sample apts data to verify column names
PRINT '';
PRINT '5. SAMPLE APTS DATA:';
SELECT TOP 3 * FROM dbo.apts WHERE ICAO_ID LIKE 'K%';

PRINT '';
PRINT '=== END DIAGNOSTIC ===';
