-- ============================================================================
-- Migration 046: Integrate Weather Detection into Refresh Cycle
-- Phase 5C: Automatically detect weather impacts during VATSIM refresh
--
-- Adds weather detection call to sp_Adl_RefreshFromVatsim_Normalized
--
-- Date: 2026-01-06
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '============================================================================';
PRINT 'Migration 046: Integrate Weather Detection into Refresh Cycle';
PRINT '============================================================================';

-- ============================================================================
-- Create wrapper procedure that runs weather detection after refresh
-- This avoids modifying the main refresh procedure
-- ============================================================================

IF OBJECT_ID('dbo.sp_Adl_RefreshWithWeather', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_Adl_RefreshWithWeather;
GO

CREATE PROCEDURE dbo.sp_Adl_RefreshWithWeather
    @json NVARCHAR(MAX),
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @refresh_result NVARCHAR(MAX);
    DECLARE @weather_flights INT, @weather_impacts INT;
    
    -- ========================================================================
    -- Step 1: Run the main VATSIM refresh
    -- ========================================================================
    
    EXEC dbo.sp_Adl_RefreshFromVatsim_Normalized @json = @json;
    
    IF @debug = 1
        PRINT 'Main refresh completed in ' + 
              CAST(DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()) AS VARCHAR) + 'ms';
    
    -- ========================================================================
    -- Step 2: Run weather impact detection (only if alerts exist)
    -- ========================================================================
    
    DECLARE @weather_start DATETIME2(3) = SYSUTCDATETIME();
    
    IF EXISTS (SELECT 1 FROM dbo.weather_alerts WHERE is_active = 1 AND valid_to_utc > SYSUTCDATETIME())
    BEGIN
        EXEC dbo.sp_DetectWeatherImpact 
            @flights_checked = @weather_flights OUTPUT,
            @impacts_detected = @weather_impacts OUTPUT;
        
        IF @debug = 1
            PRINT 'Weather detection: ' + CAST(@weather_flights AS VARCHAR) + ' flights checked, ' +
                  CAST(@weather_impacts AS VARCHAR) + ' impacted in ' +
                  CAST(DATEDIFF(MILLISECOND, @weather_start, SYSUTCDATETIME()) AS VARCHAR) + 'ms';
    END
    ELSE
    BEGIN
        IF @debug = 1
            PRINT 'Weather detection: No active alerts, skipped';
    END
    
    -- ========================================================================
    -- Return timing summary
    -- ========================================================================
    
    SELECT 
        DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()) AS total_ms,
        DATEDIFF(MILLISECOND, @weather_start, SYSUTCDATETIME()) AS weather_ms,
        @weather_flights AS weather_flights_checked,
        @weather_impacts AS weather_impacts_detected;
END
GO

PRINT '  Created procedure: sp_Adl_RefreshWithWeather';
GO

-- ============================================================================
-- Create scheduled job procedure for weather import + detection
-- Can be called by external scheduler (cron, Task Scheduler, Azure Function)
-- ============================================================================

IF OBJECT_ID('dbo.sp_WeatherRefreshCycle', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_WeatherRefreshCycle;
GO

CREATE PROCEDURE dbo.sp_WeatherRefreshCycle
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @flights_checked INT, @impacts_detected INT;
    
    -- Run weather impact detection
    EXEC dbo.sp_DetectWeatherImpact 
        @flights_checked = @flights_checked OUTPUT,
        @impacts_detected = @impacts_detected OUTPUT;
    
    -- Clean up expired alerts
    UPDATE dbo.weather_alerts
    SET is_active = 0
    WHERE is_active = 1
      AND valid_to_utc < SYSUTCDATETIME();
    
    -- Return results
    SELECT 
        @flights_checked AS flights_checked,
        @impacts_detected AS impacts_detected,
        (SELECT COUNT(*) FROM dbo.weather_alerts WHERE is_active = 1 AND valid_to_utc > SYSUTCDATETIME()) AS active_alerts,
        SYSUTCDATETIME() AS run_utc;
END
GO

PRINT '  Created procedure: sp_WeatherRefreshCycle';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '============================================================================';
PRINT 'Migration 046 Complete';
PRINT '============================================================================';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_Adl_RefreshWithWeather (combined refresh + weather detection)';
PRINT '  - sp_WeatherRefreshCycle (standalone weather detection for schedulers)';
PRINT '';
PRINT 'Integration options:';
PRINT '  1. Replace sp_Adl_RefreshFromVatsim_Normalized calls with sp_Adl_RefreshWithWeather';
PRINT '  2. Or call sp_DetectWeatherImpact separately after each refresh';
PRINT '  3. Or schedule sp_WeatherRefreshCycle to run every 30-60 seconds';
PRINT '============================================================================';
GO
