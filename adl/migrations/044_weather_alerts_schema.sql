-- ============================================================================
-- Migration 044: Weather Alerts Schema
-- Phase 5A: Weather & Boundaries Integration
-- 
-- Creates tables for storing weather hazards (SIGMET/AIRMET) with polygon
-- boundaries from aviationweather.gov
--
-- Date: 2026-01-06
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '============================================================================';
PRINT 'Migration 044: Weather Alerts Schema';
PRINT '============================================================================';

-- ============================================================================
-- Table: weather_alerts
-- Stores SIGMET/AIRMET data with polygon boundaries
-- ============================================================================

IF OBJECT_ID('dbo.weather_alerts', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.weather_alerts (
        alert_id            INT IDENTITY(1,1) PRIMARY KEY,
        alert_type          NVARCHAR(16) NOT NULL,     -- SIGMET, AIRMET, CONVECTIVE, OUTLOOK
        hazard              NVARCHAR(32) NOT NULL,     -- CONVECTIVE, TURB, ICE, IFR, MTN, ASH
        severity            NVARCHAR(16) NULL,         -- SEV, MOD, LGT
        source_id           NVARCHAR(32) NOT NULL,     -- e.g., WST1, SIGC05, WA1Z
        
        -- Time validity
        valid_from_utc      DATETIME2(0) NOT NULL,
        valid_to_utc        DATETIME2(0) NOT NULL,
        
        -- Altitude range (in 100s of feet, i.e., flight levels)
        floor_fl            INT NULL,                  -- 0 = surface, 100 = FL100
        ceiling_fl          INT NULL,                  -- 450 = FL450
        
        -- Movement (for moving hazards)
        direction_deg       INT NULL,                  -- Direction of movement (0-360)
        speed_kts           INT NULL,                  -- Speed of movement in knots
        
        -- Geometry
        geometry            GEOGRAPHY NOT NULL,        -- Polygon boundary
        center_lat          DECIMAL(10,7) NULL,        -- Centroid latitude
        center_lon          DECIMAL(11,7) NULL,        -- Centroid longitude
        area_sq_nm          DECIMAL(12,2) NULL,        -- Area in square nautical miles
        
        -- Metadata
        raw_text            NVARCHAR(MAX) NULL,        -- Original SIGMET/AIRMET text
        import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        last_updated_utc    DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        is_active           BIT NOT NULL DEFAULT 1,
        
        -- Indexes
        INDEX IX_weather_active (is_active, valid_to_utc),
        INDEX IX_weather_type (alert_type, hazard),
        INDEX IX_weather_source (source_id),
        INDEX IX_weather_valid (valid_from_utc, valid_to_utc)
    );
    
    -- Spatial index for polygon queries
    CREATE SPATIAL INDEX IX_weather_geo ON dbo.weather_alerts(geometry)
    USING GEOGRAPHY_AUTO_GRID
    WITH (CELLS_PER_OBJECT = 16);
    
    PRINT '  Created table: weather_alerts';
END
ELSE
BEGIN
    PRINT '  Table weather_alerts already exists';
END
GO

-- ============================================================================
-- Table: weather_import_log
-- Tracks weather import history for monitoring
-- ============================================================================

IF OBJECT_ID('dbo.weather_import_log', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.weather_import_log (
        log_id              INT IDENTITY(1,1) PRIMARY KEY,
        import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        source_url          NVARCHAR(256) NOT NULL,
        
        -- Stats
        alerts_received     INT NOT NULL DEFAULT 0,
        alerts_inserted     INT NOT NULL DEFAULT 0,
        alerts_updated      INT NOT NULL DEFAULT 0,
        alerts_expired      INT NOT NULL DEFAULT 0,
        
        -- Timing
        duration_ms         INT NULL,
        
        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'SUCCESS',  -- SUCCESS, PARTIAL, FAILED
        error_message       NVARCHAR(MAX) NULL,
        
        INDEX IX_import_log_date (import_utc DESC)
    );
    
    PRINT '  Created table: weather_import_log';
END
ELSE
BEGIN
    PRINT '  Table weather_import_log already exists';
END
GO

-- ============================================================================
-- Table: adl_flight_weather_impact
-- Tracks which flights are affected by weather hazards
-- ============================================================================

IF OBJECT_ID('dbo.adl_flight_weather_impact', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.adl_flight_weather_impact (
        impact_id           BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,
        alert_id            INT NOT NULL,
        
        detected_utc        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        cleared_utc         DATETIME2(0) NULL,         -- When flight exited hazard
        
        impact_type         NVARCHAR(16) NOT NULL,     -- DIRECT, NEAR, ROUTE
        distance_nm         DECIMAL(8,2) NULL,         -- Distance to hazard edge
        
        -- Position at detection
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,
        altitude_ft         INT NULL,
        
        INDEX IX_weather_impact_flight (flight_uid, detected_utc),
        INDEX IX_weather_impact_alert (alert_id, detected_utc),
        INDEX IX_weather_impact_active (cleared_utc) WHERE cleared_utc IS NULL
    );
    
    PRINT '  Created table: adl_flight_weather_impact';
END
ELSE
BEGIN
    PRINT '  Table adl_flight_weather_impact already exists';
END
GO

-- ============================================================================
-- Add weather columns to adl_flight_core
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'weather_impact')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD
        weather_impact      NVARCHAR(32) NULL,         -- NONE, DIRECT, NEAR, ROUTE
        weather_alert_ids   NVARCHAR(256) NULL,        -- Comma-separated alert IDs
        last_weather_check_utc DATETIME2(0) NULL;
    
    PRINT '  Added weather columns to adl_flight_core';
END
ELSE
BEGIN
    PRINT '  Weather columns already exist on adl_flight_core';
END
GO

-- ============================================================================
-- Procedure: sp_ImportWeatherAlerts
-- Called by PHP after fetching data from aviationweather.gov
-- ============================================================================

IF OBJECT_ID('dbo.sp_ImportWeatherAlerts', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ImportWeatherAlerts;
GO

CREATE PROCEDURE dbo.sp_ImportWeatherAlerts
    @json NVARCHAR(MAX),
    @source_url NVARCHAR(256) = 'aviationweather.gov',
    @alerts_inserted INT OUTPUT,
    @alerts_updated INT OUTPUT,
    @alerts_expired INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    
    SET @alerts_inserted = 0;
    SET @alerts_updated = 0;
    SET @alerts_expired = 0;
    
    -- Parse JSON into temp table
    SELECT 
        CAST(j.alert_type AS NVARCHAR(16)) AS alert_type,
        CAST(j.hazard AS NVARCHAR(32)) AS hazard,
        CAST(j.severity AS NVARCHAR(16)) AS severity,
        CAST(j.source_id AS NVARCHAR(32)) AS source_id,
        TRY_CAST(j.valid_from AS DATETIME2(0)) AS valid_from_utc,
        TRY_CAST(j.valid_to AS DATETIME2(0)) AS valid_to_utc,
        TRY_CAST(j.floor_fl AS INT) AS floor_fl,
        TRY_CAST(j.ceiling_fl AS INT) AS ceiling_fl,
        TRY_CAST(j.direction AS INT) AS direction_deg,
        TRY_CAST(j.speed AS INT) AS speed_kts,
        CAST(j.wkt AS NVARCHAR(MAX)) AS wkt,
        TRY_CAST(j.center_lat AS DECIMAL(10,7)) AS center_lat,
        TRY_CAST(j.center_lon AS DECIMAL(11,7)) AS center_lon,
        CAST(j.raw_text AS NVARCHAR(MAX)) AS raw_text
    INTO #alerts
    FROM OPENJSON(@json)
    WITH (
        alert_type NVARCHAR(16),
        hazard NVARCHAR(32),
        severity NVARCHAR(16),
        source_id NVARCHAR(32),
        valid_from NVARCHAR(32),
        valid_to NVARCHAR(32),
        floor_fl INT,
        ceiling_fl INT,
        direction INT,
        speed INT,
        wkt NVARCHAR(MAX),
        center_lat DECIMAL(10,7),
        center_lon DECIMAL(11,7),
        raw_text NVARCHAR(MAX)
    ) AS j
    WHERE j.source_id IS NOT NULL 
      AND j.wkt IS NOT NULL
      AND LEN(j.wkt) > 10;
    
    -- Insert new alerts
    INSERT INTO dbo.weather_alerts (
        alert_type, hazard, severity, source_id,
        valid_from_utc, valid_to_utc, floor_fl, ceiling_fl,
        direction_deg, speed_kts, geometry, center_lat, center_lon,
        raw_text, import_utc, is_active
    )
    SELECT 
        a.alert_type, a.hazard, a.severity, a.source_id,
        a.valid_from_utc, a.valid_to_utc, a.floor_fl, a.ceiling_fl,
        a.direction_deg, a.speed_kts,
        TRY_CAST(geography::STGeomFromText(a.wkt, 4326) AS GEOGRAPHY),
        a.center_lat, a.center_lon,
        a.raw_text, @now, 1
    FROM #alerts a
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.weather_alerts w 
        WHERE w.source_id = a.source_id 
          AND w.valid_from_utc = a.valid_from_utc
    )
    AND TRY_CAST(geography::STGeomFromText(a.wkt, 4326) AS GEOGRAPHY) IS NOT NULL;
    
    SET @alerts_inserted = @@ROWCOUNT;
    
    -- Update existing alerts (if polygon changed)
    UPDATE w
    SET w.geometry = TRY_CAST(geography::STGeomFromText(a.wkt, 4326) AS GEOGRAPHY),
        w.valid_to_utc = a.valid_to_utc,
        w.center_lat = a.center_lat,
        w.center_lon = a.center_lon,
        w.last_updated_utc = @now,
        w.is_active = 1
    FROM dbo.weather_alerts w
    INNER JOIN #alerts a ON w.source_id = a.source_id AND w.valid_from_utc = a.valid_from_utc
    WHERE w.valid_to_utc != a.valid_to_utc
       OR w.is_active = 0;
    
    SET @alerts_updated = @@ROWCOUNT;
    
    -- Mark expired alerts as inactive
    UPDATE dbo.weather_alerts
    SET is_active = 0
    WHERE is_active = 1
      AND valid_to_utc < @now;
    
    SET @alerts_expired = @@ROWCOUNT;
    
    -- Calculate area for new alerts
    UPDATE dbo.weather_alerts
    SET area_sq_nm = geometry.STArea() / 3429904.0  -- sq meters to sq nm
    WHERE area_sq_nm IS NULL
      AND geometry IS NOT NULL;
    
    -- Log import
    DECLARE @duration_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
    
    INSERT INTO dbo.weather_import_log (
        import_utc, source_url, alerts_received, alerts_inserted, 
        alerts_updated, alerts_expired, duration_ms, status
    )
    VALUES (
        @now, @source_url, (SELECT COUNT(*) FROM #alerts), @alerts_inserted,
        @alerts_updated, @alerts_expired, @duration_ms, 'SUCCESS'
    );
    
    DROP TABLE #alerts;
    
    -- Return summary
    SELECT 
        @alerts_inserted AS inserted,
        @alerts_updated AS updated,
        @alerts_expired AS expired,
        @duration_ms AS duration_ms;
END
GO

PRINT '  Created procedure: sp_ImportWeatherAlerts';
GO

-- ============================================================================
-- Procedure: sp_GetActiveWeatherAlerts
-- Returns active weather alerts for display
-- ============================================================================

IF OBJECT_ID('dbo.sp_GetActiveWeatherAlerts', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GetActiveWeatherAlerts;
GO

CREATE PROCEDURE dbo.sp_GetActiveWeatherAlerts
    @alert_type NVARCHAR(16) = NULL,
    @hazard NVARCHAR(32) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        alert_id,
        alert_type,
        hazard,
        severity,
        source_id,
        valid_from_utc,
        valid_to_utc,
        floor_fl,
        ceiling_fl,
        direction_deg,
        speed_kts,
        geometry.STAsText() AS wkt,
        center_lat,
        center_lon,
        area_sq_nm,
        raw_text,
        DATEDIFF(MINUTE, SYSUTCDATETIME(), valid_to_utc) AS minutes_remaining
    FROM dbo.weather_alerts
    WHERE is_active = 1
      AND valid_to_utc > SYSUTCDATETIME()
      AND (@alert_type IS NULL OR alert_type = @alert_type)
      AND (@hazard IS NULL OR hazard = @hazard)
    ORDER BY 
        CASE hazard 
            WHEN 'CONVECTIVE' THEN 1 
            WHEN 'TURB' THEN 2 
            WHEN 'ICE' THEN 3 
            ELSE 4 
        END,
        valid_to_utc;
END
GO

PRINT '  Created procedure: sp_GetActiveWeatherAlerts';
GO

-- ============================================================================
-- Procedure: sp_CleanupExpiredWeather
-- Removes old weather data (run daily)
-- ============================================================================

IF OBJECT_ID('dbo.sp_CleanupExpiredWeather', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CleanupExpiredWeather;
GO

CREATE PROCEDURE dbo.sp_CleanupExpiredWeather
    @retention_days INT = 7
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @cutoff DATETIME2(0) = DATEADD(DAY, -@retention_days, SYSUTCDATETIME());
    DECLARE @deleted_alerts INT, @deleted_impacts INT, @deleted_logs INT;
    
    -- Delete old weather impacts
    DELETE FROM dbo.adl_flight_weather_impact
    WHERE detected_utc < @cutoff;
    SET @deleted_impacts = @@ROWCOUNT;
    
    -- Delete old weather alerts
    DELETE FROM dbo.weather_alerts
    WHERE valid_to_utc < @cutoff;
    SET @deleted_alerts = @@ROWCOUNT;
    
    -- Delete old import logs
    DELETE FROM dbo.weather_import_log
    WHERE import_utc < @cutoff;
    SET @deleted_logs = @@ROWCOUNT;
    
    SELECT 
        @deleted_alerts AS alerts_deleted,
        @deleted_impacts AS impacts_deleted,
        @deleted_logs AS logs_deleted;
END
GO

PRINT '  Created procedure: sp_CleanupExpiredWeather';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '============================================================================';
PRINT 'Migration 044 Complete';
PRINT '============================================================================';
PRINT '';
PRINT 'Tables created:';
PRINT '  - weather_alerts (SIGMET/AIRMET storage with polygons)';
PRINT '  - weather_import_log (import history)';
PRINT '  - adl_flight_weather_impact (flight-to-weather associations)';
PRINT '';
PRINT 'Columns added to adl_flight_core:';
PRINT '  - weather_impact';
PRINT '  - weather_alert_ids';
PRINT '  - last_weather_check_utc';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_ImportWeatherAlerts (JSON import from PHP)';
PRINT '  - sp_GetActiveWeatherAlerts (query active alerts)';
PRINT '  - sp_CleanupExpiredWeather (retention cleanup)';
PRINT '';
PRINT 'Next: Run the PHP import script to fetch weather data';
PRINT '============================================================================';
GO
