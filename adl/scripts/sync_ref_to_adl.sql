-- ============================================================================
-- VATSIM_REF → VATSIM_ADL Sync Script
--
-- Purpose: Sync reference data FROM VATSIM_REF TO VATSIM_ADL cache tables
-- Schedule: Run nightly or after AIRAC cycle updates
--
-- Architecture:
-- - VATSIM_REF (Basic $5/mo) = Authoritative source for reference data
-- - VATSIM_ADL = Local cache tables for sp_ParseRoute performance
--
-- This script syncs TO_ADL, refreshing the cache from the authoritative source.
-- ============================================================================

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

PRINT '=== VATSIM_REF → VATSIM_ADL Sync ==='
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
GO

-- ============================================================================
-- Sync each table using TRUNCATE + INSERT pattern
-- This ensures atomic, consistent updates
-- ============================================================================

DECLARE @start_time DATETIME2;
DECLARE @row_count INT;
DECLARE @table_name NVARCHAR(64);
DECLARE @error_msg NVARCHAR(MAX);

BEGIN TRY
    BEGIN TRANSACTION;

    -- ========================================================================
    -- 1. nav_fixes (~270K rows)
    -- ========================================================================
    SET @table_name = 'nav_fixes';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    TRUNCATE TABLE [VATSIM_ADL].dbo.nav_fixes;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.nav_fixes ON;

    INSERT INTO [VATSIM_ADL].dbo.nav_fixes (
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
    FROM [VATSIM_REF].dbo.nav_fixes;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.nav_fixes OFF;

    -- Log to REF database
    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 2. airways
    -- ========================================================================
    SET @table_name = 'airways';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    -- Must delete segments first due to FK
    DELETE FROM [VATSIM_ADL].dbo.airway_segments;
    TRUNCATE TABLE [VATSIM_ADL].dbo.airways;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.airways ON;

    INSERT INTO [VATSIM_ADL].dbo.airways (
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
    FROM [VATSIM_REF].dbo.airways;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.airways OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 3. airway_segments
    -- ========================================================================
    SET @table_name = 'airway_segments';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.airway_segments ON;

    INSERT INTO [VATSIM_ADL].dbo.airway_segments (
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
    FROM [VATSIM_REF].dbo.airway_segments;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.airway_segments OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 4. nav_procedures
    -- ========================================================================
    SET @table_name = 'nav_procedures';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    TRUNCATE TABLE [VATSIM_ADL].dbo.nav_procedures;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.nav_procedures ON;

    INSERT INTO [VATSIM_ADL].dbo.nav_procedures (
        procedure_id, procedure_type, airport_icao, procedure_name, computer_code,
        transition_name, full_route, runways,
        is_active, source, effective_date
    )
    SELECT
        procedure_id, procedure_type, airport_icao, procedure_name, computer_code,
        transition_name, full_route, runways,
        is_active, source, effective_date
    FROM [VATSIM_REF].dbo.nav_procedures;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.nav_procedures OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 5. coded_departure_routes
    -- ========================================================================
    SET @table_name = 'coded_departure_routes';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    TRUNCATE TABLE [VATSIM_ADL].dbo.coded_departure_routes;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.coded_departure_routes ON;

    INSERT INTO [VATSIM_ADL].dbo.coded_departure_routes (
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
    FROM [VATSIM_REF].dbo.coded_departure_routes;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.coded_departure_routes OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 6. playbook_routes
    -- ========================================================================
    SET @table_name = 'playbook_routes';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    TRUNCATE TABLE [VATSIM_ADL].dbo.playbook_routes;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.playbook_routes ON;

    INSERT INTO [VATSIM_ADL].dbo.playbook_routes (
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
    FROM [VATSIM_REF].dbo.playbook_routes;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.playbook_routes OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 7. area_centers
    -- ========================================================================
    SET @table_name = 'area_centers';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    TRUNCATE TABLE [VATSIM_ADL].dbo.area_centers;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.area_centers ON;

    INSERT INTO [VATSIM_ADL].dbo.area_centers (
        center_id, center_code, center_type, center_name,
        lat, lon, parent_artcc, position_geo
    )
    SELECT
        center_id, center_code, center_type, center_name,
        lat, lon, parent_artcc, position_geo
    FROM [VATSIM_REF].dbo.area_centers;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.area_centers OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    -- ========================================================================
    -- 8. oceanic_fir_bounds
    -- ========================================================================
    SET @table_name = 'oceanic_fir_bounds';
    SET @start_time = SYSUTCDATETIME();
    PRINT 'Syncing ' + @table_name + '...';

    TRUNCATE TABLE [VATSIM_ADL].dbo.oceanic_fir_bounds;

    SET IDENTITY_INSERT [VATSIM_ADL].dbo.oceanic_fir_bounds ON;

    INSERT INTO [VATSIM_ADL].dbo.oceanic_fir_bounds (
        fir_id, fir_code, fir_name, fir_type,
        min_lat, max_lat, min_lon, max_lon,
        keeps_tier_1
    )
    SELECT
        fir_id, fir_code, fir_name, fir_type,
        min_lat, max_lat, min_lon, max_lon,
        keeps_tier_1
    FROM [VATSIM_REF].dbo.oceanic_fir_bounds;

    SET @row_count = @@ROWCOUNT;
    SET IDENTITY_INSERT [VATSIM_ADL].dbo.oceanic_fir_bounds OFF;

    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms)
    VALUES (@table_name, @row_count, 'TO_ADL', 'SUCCESS', DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()));

    PRINT '  Synced ' + CAST(@row_count AS VARCHAR) + ' rows';

    COMMIT TRANSACTION;
    PRINT '';
    PRINT '=== Sync Complete (SUCCESS) ===';

END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    SET @error_msg = ERROR_MESSAGE();

    -- Log the failure
    INSERT INTO [VATSIM_REF].dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, error_message)
    VALUES (ISNULL(@table_name, 'UNKNOWN'), 0, 'TO_ADL', 'FAILED', @error_msg);

    PRINT '';
    PRINT '=== Sync FAILED ===';
    PRINT 'Error: ' + @error_msg;
END CATCH
GO

-- ============================================================================
-- Summary
-- ============================================================================
PRINT '';
PRINT 'Recent sync history:';

SELECT TOP 10
    sync_timestamp,
    table_name,
    rows_synced,
    sync_direction,
    sync_status,
    duration_ms,
    LEFT(ISNULL(error_message, ''), 50) AS error_preview
FROM [VATSIM_REF].dbo.ref_sync_log
ORDER BY sync_id DESC;
GO

PRINT '';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
