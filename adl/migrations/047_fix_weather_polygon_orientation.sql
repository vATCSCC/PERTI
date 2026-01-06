-- ============================================================================
-- Migration 047: Fix Weather Polygon Orientation
-- 
-- SQL Server geography requires counter-clockwise ring orientation for
-- exterior polygon rings. If clockwise, STContains matches the INVERSE
-- (everything outside the polygon).
--
-- This migration:
-- 1. Fixes existing polygons using ReorientObject()
-- 2. Updates the import procedure to validate orientation
--
-- Date: 2026-01-06
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '============================================================================';
PRINT 'Migration 047: Fix Weather Polygon Orientation';
PRINT '============================================================================';

-- ============================================================================
-- Step 1: Check current polygon areas (if > half Earth, orientation is wrong)
-- ============================================================================

PRINT '';
PRINT 'Checking existing polygons...';

SELECT 
    alert_id,
    source_id,
    area_sq_nm,
    geometry.STArea() / 1000000.0 AS area_sq_km,
    CASE 
        WHEN geometry.STArea() > 255000000000000.0 THEN 'INVERTED (> half Earth)'
        ELSE 'OK'
    END AS status
FROM dbo.weather_alerts
WHERE geometry IS NOT NULL;

-- ============================================================================
-- Step 2: Fix inverted polygons using ReorientObject()
-- ============================================================================

PRINT '';
PRINT 'Fixing inverted polygons...';

UPDATE dbo.weather_alerts
SET geometry = geometry.ReorientObject(),
    area_sq_nm = geometry.ReorientObject().STArea() / 3429904.0
WHERE geometry IS NOT NULL
  AND geometry.STArea() > 255000000000000.0;  -- > half Earth's surface

PRINT '  Fixed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' inverted polygons';

-- ============================================================================
-- Step 3: Clear incorrect weather impact data
-- ============================================================================

PRINT '';
PRINT 'Clearing incorrect impact data...';

-- Clear the flight_weather_impact table
DELETE FROM dbo.adl_flight_weather_impact;
PRINT '  Cleared adl_flight_weather_impact table';

-- Clear weather flags on flights
UPDATE dbo.adl_flight_core
SET weather_impact = NULL,
    weather_alert_ids = NULL,
    last_weather_check_utc = NULL
WHERE weather_impact IS NOT NULL;

PRINT '  Cleared weather_impact on ' + CAST(@@ROWCOUNT AS VARCHAR) + ' flights';

-- ============================================================================
-- Step 4: Update import procedure to fix orientation on import
-- ============================================================================

IF OBJECT_ID('dbo.sp_ImportWeatherAlerts', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ImportWeatherAlerts;
GO

CREATE PROCEDURE dbo.sp_ImportWeatherAlerts
    @json NVARCHAR(MAX),
    @source_url NVARCHAR(500) = NULL,
    @alerts_inserted INT = 0 OUTPUT,
    @alerts_updated INT = 0 OUTPUT,
    @alerts_expired INT = 0 OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @alerts_received INT = 0;
    
    SET @alerts_inserted = 0;
    SET @alerts_updated = 0;
    SET @alerts_expired = 0;
    
    -- Parse JSON into temp table
    SELECT
        JSON_VALUE(j.value, '$.alert_type') AS alert_type,
        JSON_VALUE(j.value, '$.hazard') AS hazard,
        JSON_VALUE(j.value, '$.severity') AS severity,
        JSON_VALUE(j.value, '$.source_id') AS source_id,
        TRY_CAST(JSON_VALUE(j.value, '$.valid_from') AS DATETIME2(0)) AS valid_from_utc,
        TRY_CAST(JSON_VALUE(j.value, '$.valid_to') AS DATETIME2(0)) AS valid_to_utc,
        TRY_CAST(JSON_VALUE(j.value, '$.floor_fl') AS INT) AS floor_fl,
        TRY_CAST(JSON_VALUE(j.value, '$.ceiling_fl') AS INT) AS ceiling_fl,
        TRY_CAST(JSON_VALUE(j.value, '$.direction') AS INT) AS direction_deg,
        TRY_CAST(JSON_VALUE(j.value, '$.speed') AS INT) AS speed_kts,
        JSON_VALUE(j.value, '$.wkt') AS wkt,
        TRY_CAST(JSON_VALUE(j.value, '$.center_lat') AS FLOAT) AS center_lat,
        TRY_CAST(JSON_VALUE(j.value, '$.center_lon') AS FLOAT) AS center_lon,
        JSON_VALUE(j.value, '$.raw_text') AS raw_text
    INTO #incoming
    FROM OPENJSON(@json) j;
    
    SET @alerts_received = @@ROWCOUNT;
    
    -- Process each incoming alert
    DECLARE @source_id NVARCHAR(100);
    DECLARE @alert_type VARCHAR(20);
    DECLARE @hazard VARCHAR(20);
    DECLARE @severity VARCHAR(10);
    DECLARE @valid_from DATETIME2(0);
    DECLARE @valid_to DATETIME2(0);
    DECLARE @floor_fl INT;
    DECLARE @ceiling_fl INT;
    DECLARE @direction_deg INT;
    DECLARE @speed_kts INT;
    DECLARE @wkt NVARCHAR(MAX);
    DECLARE @center_lat FLOAT;
    DECLARE @center_lon FLOAT;
    DECLARE @raw_text NVARCHAR(MAX);
    DECLARE @geometry GEOGRAPHY;
    DECLARE @area_sq_nm FLOAT;
    
    DECLARE alert_cursor CURSOR FOR
        SELECT source_id, alert_type, hazard, severity, valid_from_utc, valid_to_utc,
               floor_fl, ceiling_fl, direction_deg, speed_kts, wkt, center_lat, center_lon, raw_text
        FROM #incoming
        WHERE source_id IS NOT NULL AND valid_to_utc > @now;
    
    OPEN alert_cursor;
    FETCH NEXT FROM alert_cursor INTO 
        @source_id, @alert_type, @hazard, @severity, @valid_from, @valid_to,
        @floor_fl, @ceiling_fl, @direction_deg, @speed_kts, @wkt, @center_lat, @center_lon, @raw_text;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Parse WKT to geography
        BEGIN TRY
            SET @geometry = geography::STGeomFromText(@wkt, 4326);
            
            -- FIX: Check if polygon is inverted (area > half Earth)
            -- If so, reorient it to get the correct smaller polygon
            IF @geometry.STArea() > 255000000000000.0
            BEGIN
                SET @geometry = @geometry.ReorientObject();
            END
            
            SET @area_sq_nm = @geometry.STArea() / 3429904.0;
        END TRY
        BEGIN CATCH
            SET @geometry = NULL;
            SET @area_sq_nm = NULL;
        END CATCH
        
        -- Check if alert already exists
        IF EXISTS (SELECT 1 FROM dbo.weather_alerts WHERE source_id = @source_id)
        BEGIN
            -- Update existing
            UPDATE dbo.weather_alerts
            SET alert_type = @alert_type,
                hazard = @hazard,
                severity = @severity,
                valid_from_utc = @valid_from,
                valid_to_utc = @valid_to,
                floor_fl = @floor_fl,
                ceiling_fl = @ceiling_fl,
                direction_deg = @direction_deg,
                speed_kts = @speed_kts,
                geometry = @geometry,
                center_lat = @center_lat,
                center_lon = @center_lon,
                area_sq_nm = @area_sq_nm,
                raw_text = @raw_text,
                is_active = 1,
                updated_utc = @now
            WHERE source_id = @source_id;
            
            SET @alerts_updated = @alerts_updated + 1;
        END
        ELSE
        BEGIN
            -- Insert new
            INSERT INTO dbo.weather_alerts (
                alert_type, hazard, severity, source_id, valid_from_utc, valid_to_utc,
                floor_fl, ceiling_fl, direction_deg, speed_kts, geometry,
                center_lat, center_lon, area_sq_nm, raw_text, is_active, created_utc, updated_utc
            )
            VALUES (
                @alert_type, @hazard, @severity, @source_id, @valid_from, @valid_to,
                @floor_fl, @ceiling_fl, @direction_deg, @speed_kts, @geometry,
                @center_lat, @center_lon, @area_sq_nm, @raw_text, 1, @now, @now
            );
            
            SET @alerts_inserted = @alerts_inserted + 1;
        END
        
        FETCH NEXT FROM alert_cursor INTO 
            @source_id, @alert_type, @hazard, @severity, @valid_from, @valid_to,
            @floor_fl, @ceiling_fl, @direction_deg, @speed_kts, @wkt, @center_lat, @center_lon, @raw_text;
    END
    
    CLOSE alert_cursor;
    DEALLOCATE alert_cursor;
    
    -- Expire old alerts
    UPDATE dbo.weather_alerts
    SET is_active = 0
    WHERE is_active = 1
      AND valid_to_utc < @now;
    
    SET @alerts_expired = @@ROWCOUNT;
    
    -- Log import
    INSERT INTO dbo.weather_import_log (
        import_utc, source_url, alerts_received, alerts_inserted, 
        alerts_updated, alerts_expired, duration_ms, status
    )
    VALUES (
        @now, @source_url, @alerts_received, @alerts_inserted,
        @alerts_updated, @alerts_expired,
        DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
        'SUCCESS'
    );
    
    DROP TABLE #incoming;
END
GO

PRINT '  Updated procedure: sp_ImportWeatherAlerts (with orientation fix)';
GO

-- ============================================================================
-- Step 5: Verify fix
-- ============================================================================

PRINT '';
PRINT 'Verifying polygon areas after fix...';

SELECT 
    alert_id,
    source_id,
    ROUND(area_sq_nm, 2) AS area_sq_nm,
    ROUND(geometry.STArea() / 1000000.0, 2) AS area_sq_km,
    CASE 
        WHEN geometry.STArea() > 255000000000000.0 THEN 'STILL INVERTED!'
        ELSE 'OK'
    END AS status
FROM dbo.weather_alerts
WHERE geometry IS NOT NULL;

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '============================================================================';
PRINT 'Migration 047 Complete';
PRINT '============================================================================';
PRINT '';
PRINT 'What was fixed:';
PRINT '  - Existing inverted polygons reoriented using ReorientObject()';
PRINT '  - Import procedure now auto-fixes inverted polygons';
PRINT '  - Cleared incorrect weather impact data';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Re-run weather detection: EXEC sp_DetectWeatherImpact';
PRINT '  2. Verify reasonable impact counts (should be much lower)';
PRINT '============================================================================';
GO
