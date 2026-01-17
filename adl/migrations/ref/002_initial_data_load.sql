-- ============================================================================
-- VATSIM_REF - Migration 002: Initial Data Load from VATSIM_ADL
--
-- ONE-TIME SCRIPT: Populates VATSIM_REF with existing reference data
-- Run this ONCE after creating the schema (001_vatsim_ref_schema.sql)
--
-- Prerequisites:
-- - VATSIM_REF schema created (001)
-- - Linked server or cross-database access to VATSIM_ADL
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== VATSIM_REF Initial Data Load ==='
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
GO

-- ============================================================================
-- NOTE: This script must be run from a context that can access BOTH databases.
-- Option A: Run from VATSIM_ADL with cross-database INSERT
-- Option B: Run from master with fully qualified names
-- ============================================================================

-- Variable to track success
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64);

-- ============================================================================
-- 1. nav_fixes (largest table ~270K rows)
-- ============================================================================
SET @table_name = 'nav_fixes';
PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.nav_fixes ON;

INSERT INTO [VATSIM_REF].dbo.nav_fixes (
    fix_id, fix_name, fix_type, lat, lon,
    artcc_id, state_code, country_code,
    freq_mhz, mag_var, elevation_ft,
    source, effective_date, position_geo
)
SELECT
    fix_id, fix_name, fix_type, lat, lon,
    artcc_id, state_code, country_code,
    freq_mhz, mag_var, elevation_ft,
    source, effective_date, position_geo
FROM [VATSIM_ADL].dbo.nav_fixes;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.nav_fixes OFF;

-- Log the sync
INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
SET @start_time = SYSUTCDATETIME();
GO

-- ============================================================================
-- 2. airways
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'airways';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.airways ON;

INSERT INTO [VATSIM_REF].dbo.airways (
    airway_id, airway_name, airway_type,
    fix_sequence, fix_count, start_fix, end_fix,
    min_alt_ft, max_alt_ft, direction,
    source, effective_date
)
SELECT
    airway_id, airway_name, airway_type,
    fix_sequence, fix_count, start_fix, end_fix,
    min_alt_ft, max_alt_ft, direction,
    source, effective_date
FROM [VATSIM_ADL].dbo.airways;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.airways OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- 3. airway_segments
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'airway_segments';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.airway_segments ON;

INSERT INTO [VATSIM_REF].dbo.airway_segments (
    segment_id, airway_id, airway_name,
    sequence_num, from_fix, to_fix,
    from_lat, from_lon, to_lat, to_lon,
    distance_nm, course_deg,
    min_alt_ft, max_alt_ft, segment_geo
)
SELECT
    segment_id, airway_id, airway_name,
    sequence_num, from_fix, to_fix,
    from_lat, from_lon, to_lat, to_lon,
    distance_nm, course_deg,
    min_alt_ft, max_alt_ft, segment_geo
FROM [VATSIM_ADL].dbo.airway_segments;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.airway_segments OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- 4. nav_procedures
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'nav_procedures';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.nav_procedures ON;

INSERT INTO [VATSIM_REF].dbo.nav_procedures (
    procedure_id, procedure_type, airport_icao, procedure_name, computer_code,
    transition_name, full_route, runways,
    is_active, source, effective_date
)
SELECT
    procedure_id, procedure_type, airport_icao, procedure_name, computer_code,
    transition_name, full_route, runways,
    is_active, source, effective_date
FROM [VATSIM_ADL].dbo.nav_procedures;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.nav_procedures OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- 5. coded_departure_routes
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'coded_departure_routes';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.coded_departure_routes ON;

INSERT INTO [VATSIM_REF].dbo.coded_departure_routes (
    cdr_id, cdr_code, full_route,
    origin_icao, dest_icao, direction,
    altitude_min_ft, altitude_max_ft,
    is_active, source, effective_date
)
SELECT
    cdr_id, cdr_code, full_route,
    origin_icao, dest_icao, direction,
    altitude_min_ft, altitude_max_ft,
    is_active, source, effective_date
FROM [VATSIM_ADL].dbo.coded_departure_routes;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.coded_departure_routes OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- 6. playbook_routes
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'playbook_routes';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.playbook_routes ON;

INSERT INTO [VATSIM_REF].dbo.playbook_routes (
    playbook_id, play_name, full_route,
    origin_airports, origin_tracons, origin_artccs,
    dest_airports, dest_tracons, dest_artccs,
    altitude_min_ft, altitude_max_ft,
    is_active, source, effective_date
)
SELECT
    playbook_id, play_name, full_route,
    origin_airports, origin_tracons, origin_artccs,
    dest_airports, dest_tracons, dest_artccs,
    altitude_min_ft, altitude_max_ft,
    is_active, source, effective_date
FROM [VATSIM_ADL].dbo.playbook_routes;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.playbook_routes OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- 7. area_centers
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'area_centers';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.area_centers ON;

INSERT INTO [VATSIM_REF].dbo.area_centers (
    center_id, center_code, center_type, center_name,
    lat, lon, parent_artcc, position_geo
)
SELECT
    center_id, center_code, center_type, center_name,
    lat, lon, parent_artcc, position_geo
FROM [VATSIM_ADL].dbo.area_centers;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.area_centers OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- 8. oceanic_fir_bounds
-- ============================================================================
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64) = 'oceanic_fir_bounds';

PRINT 'Loading ' + @table_name + '...';

SET IDENTITY_INSERT [VATSIM_REF].dbo.oceanic_fir_bounds ON;

INSERT INTO [VATSIM_REF].dbo.oceanic_fir_bounds (
    fir_id, fir_code, fir_name, fir_type,
    min_lat, max_lat, min_lon, max_lon,
    keeps_tier_1
)
SELECT
    fir_id, fir_code, fir_name, fir_type,
    min_lat, max_lat, min_lon, max_lon,
    keeps_tier_1
FROM [VATSIM_ADL].dbo.oceanic_fir_bounds;

SET @row_count = @@ROWCOUNT;
SET IDENTITY_INSERT [VATSIM_REF].dbo.oceanic_fir_bounds OFF;

INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
VALUES (@table_name, @row_count, 'FROM_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

PRINT '  Loaded ' + CAST(@row_count AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- Summary
-- ============================================================================
PRINT '';
PRINT '=== Initial Data Load Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Summary:';

SELECT
    table_name,
    rows_synced,
    sync_status,
    duration_ms
FROM [VATSIM_REF].dbo.ref_sync_log
WHERE sync_direction = 'FROM_ADL'
ORDER BY sync_id DESC;
GO
