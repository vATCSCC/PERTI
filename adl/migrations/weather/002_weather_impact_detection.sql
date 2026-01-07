-- ============================================================================
-- Migration 045: Weather Impact Detection
-- Phase 5C: Detect flights affected by weather hazards
--
-- Creates procedure to check flight positions against active weather polygons
-- and track weather impact on flights
--
-- Date: 2026-01-06
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '============================================================================';
PRINT 'Migration 045: Weather Impact Detection';
PRINT '============================================================================';

-- ============================================================================
-- Procedure: sp_DetectWeatherImpact
-- Detects flights within or near active weather hazard polygons
-- ============================================================================

IF OBJECT_ID('dbo.sp_DetectWeatherImpact', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_DetectWeatherImpact;
GO

CREATE PROCEDURE dbo.sp_DetectWeatherImpact
    @flights_checked INT = NULL OUTPUT,
    @impacts_detected INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @flights_checked = 0;
    SET @impacts_detected = 0;
    
    -- ========================================================================
    -- Step 1: Get active weather alerts
    -- ========================================================================
    
    SELECT 
        alert_id,
        alert_type,
        hazard,
        severity,
        floor_fl,
        ceiling_fl,
        geometry
    INTO #active_alerts
    FROM dbo.weather_alerts
    WHERE is_active = 1
      AND valid_to_utc > @now
      AND geometry IS NOT NULL;
    
    IF (SELECT COUNT(*) FROM #active_alerts) = 0
    BEGIN
        -- No active weather alerts, clear any existing impacts
        UPDATE dbo.adl_flight_core
        SET weather_impact = NULL,
            weather_alert_ids = NULL,
            last_weather_check_utc = @now
        WHERE weather_impact IS NOT NULL;
        
        DROP TABLE #active_alerts;
        RETURN;
    END
    
    -- ========================================================================
    -- Step 2: Check each active flight against weather polygons
    -- ========================================================================
    
    SELECT 
        c.flight_uid,
        p.lat,
        p.lon,
        p.altitude_ft,
        c.weather_impact AS prev_impact,
        c.weather_alert_ids AS prev_alert_ids
    INTO #flights
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL
      AND p.lon IS NOT NULL;
    
    SET @flights_checked = @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 3: Detect impacts (DIRECT = inside polygon)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        a.alert_id,
        a.hazard,
        a.severity,
        'DIRECT' AS impact_type,
        0.0 AS distance_nm
    INTO #impacts
    FROM #flights f
    CROSS JOIN #active_alerts a
    WHERE a.geometry.STContains(geography::Point(f.lat, f.lon, 4326)) = 1
      -- Check altitude if specified
      AND (a.floor_fl IS NULL OR f.altitude_ft / 100 >= a.floor_fl)
      AND (a.ceiling_fl IS NULL OR f.altitude_ft / 100 <= a.ceiling_fl);
    
    -- ========================================================================
    -- Step 4: Detect NEAR impacts (within 20nm but not inside)
    -- ========================================================================
    
    INSERT INTO #impacts (flight_uid, lat, lon, altitude_ft, alert_id, hazard, severity, impact_type, distance_nm)
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        a.alert_id,
        a.hazard,
        a.severity,
        'NEAR',
        a.geometry.STDistance(geography::Point(f.lat, f.lon, 4326)) / 1852.0 AS distance_nm
    FROM #flights f
    CROSS JOIN #active_alerts a
    WHERE a.geometry.STDistance(geography::Point(f.lat, f.lon, 4326)) < 37040  -- 20nm in meters
      AND a.geometry.STContains(geography::Point(f.lat, f.lon, 4326)) = 0
      AND (a.floor_fl IS NULL OR f.altitude_ft / 100 >= a.floor_fl - 10)  -- ±1000ft buffer
      AND (a.ceiling_fl IS NULL OR f.altitude_ft / 100 <= a.ceiling_fl + 10)
      AND NOT EXISTS (
          SELECT 1 FROM #impacts i 
          WHERE i.flight_uid = f.flight_uid AND i.alert_id = a.alert_id
      );
    
    SET @impacts_detected = (SELECT COUNT(DISTINCT flight_uid) FROM #impacts);
    
    -- ========================================================================
    -- Step 5: Log new impacts to adl_flight_weather_impact
    -- ========================================================================
    
    INSERT INTO dbo.adl_flight_weather_impact (
        flight_uid, alert_id, detected_utc, impact_type, 
        distance_nm, lat, lon, altitude_ft
    )
    SELECT DISTINCT
        i.flight_uid,
        i.alert_id,
        @now,
        i.impact_type,
        i.distance_nm,
        i.lat,
        i.lon,
        i.altitude_ft
    FROM #impacts i
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_weather_impact wi
        WHERE wi.flight_uid = i.flight_uid 
          AND wi.alert_id = i.alert_id
          AND wi.cleared_utc IS NULL
    );
    
    -- ========================================================================
    -- Step 6: Clear impacts for flights no longer affected
    -- ========================================================================
    
    UPDATE wi
    SET wi.cleared_utc = @now
    FROM dbo.adl_flight_weather_impact wi
    WHERE wi.cleared_utc IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM #impacts i
          WHERE i.flight_uid = wi.flight_uid AND i.alert_id = wi.alert_id
      );
    
    -- ========================================================================
    -- Step 7: Update flight_core with aggregated impact status
    -- ========================================================================
    
    -- Build aggregated impact info per flight
    ;WITH flight_impacts AS (
        SELECT 
            flight_uid,
            -- Worst impact type (DIRECT > NEAR)
            MAX(CASE WHEN impact_type = 'DIRECT' THEN 2 ELSE 1 END) AS impact_level,
            -- Most severe hazard
            MAX(CASE hazard 
                WHEN 'CONVECTIVE' THEN 5
                WHEN 'TURB' THEN 4
                WHEN 'ICE' THEN 3
                ELSE 2 
            END) AS hazard_level,
            -- Comma-separated alert IDs
            STRING_AGG(CAST(alert_id AS VARCHAR(10)), ',') AS alert_ids
        FROM #impacts
        GROUP BY flight_uid
    )
    UPDATE c
    SET c.weather_impact = CASE 
            WHEN fi.impact_level = 2 THEN 
                CASE fi.hazard_level
                    WHEN 5 THEN 'DIRECT_CONVECTIVE'
                    WHEN 4 THEN 'DIRECT_TURB'
                    WHEN 3 THEN 'DIRECT_ICE'
                    ELSE 'DIRECT'
                END
            ELSE 
                CASE fi.hazard_level
                    WHEN 5 THEN 'NEAR_CONVECTIVE'
                    WHEN 4 THEN 'NEAR_TURB'
                    WHEN 3 THEN 'NEAR_ICE'
                    ELSE 'NEAR'
                END
        END,
        c.weather_alert_ids = fi.alert_ids,
        c.last_weather_check_utc = @now
    FROM dbo.adl_flight_core c
    JOIN flight_impacts fi ON fi.flight_uid = c.flight_uid;
    
    -- Clear weather impact for flights no longer affected
    UPDATE dbo.adl_flight_core
    SET weather_impact = NULL,
        weather_alert_ids = NULL,
        last_weather_check_utc = @now
    WHERE is_active = 1
      AND weather_impact IS NOT NULL
      AND flight_uid NOT IN (SELECT DISTINCT flight_uid FROM #impacts);
    
    -- ========================================================================
    -- Cleanup
    -- ========================================================================
    
    DROP TABLE #active_alerts;
    DROP TABLE #flights;
    DROP TABLE #impacts;
    
    -- Return summary
    SELECT 
        @flights_checked AS flights_checked,
        @impacts_detected AS flights_impacted,
        (SELECT COUNT(*) FROM dbo.weather_alerts WHERE is_active = 1 AND valid_to_utc > @now) AS active_alerts;
END
GO

PRINT '  Created procedure: sp_DetectWeatherImpact';
GO

-- ============================================================================
-- Procedure: sp_GetFlightWeatherImpact
-- Returns weather impact details for a specific flight
-- ============================================================================

IF OBJECT_ID('dbo.sp_GetFlightWeatherImpact', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GetFlightWeatherImpact;
GO

CREATE PROCEDURE dbo.sp_GetFlightWeatherImpact
    @flight_uid BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Current impact status
    SELECT 
        c.flight_uid,
        c.callsign,
        c.weather_impact,
        c.weather_alert_ids,
        c.last_weather_check_utc,
        p.lat,
        p.lon,
        p.altitude_ft
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.flight_uid = @flight_uid;
    
    -- Active impacts with alert details
    SELECT 
        wi.impact_id,
        wi.alert_id,
        wi.impact_type,
        wi.distance_nm,
        wi.detected_utc,
        wa.alert_type,
        wa.hazard,
        wa.severity,
        wa.source_id,
        wa.valid_from_utc,
        wa.valid_to_utc,
        wa.floor_fl,
        wa.ceiling_fl,
        wa.raw_text,
        DATEDIFF(MINUTE, SYSUTCDATETIME(), wa.valid_to_utc) AS minutes_remaining
    FROM dbo.adl_flight_weather_impact wi
    JOIN dbo.weather_alerts wa ON wa.alert_id = wi.alert_id
    WHERE wi.flight_uid = @flight_uid
      AND wi.cleared_utc IS NULL
    ORDER BY 
        CASE wi.impact_type WHEN 'DIRECT' THEN 1 ELSE 2 END,
        CASE wa.hazard WHEN 'CONVECTIVE' THEN 1 WHEN 'TURB' THEN 2 WHEN 'ICE' THEN 3 ELSE 4 END;
    
    -- Impact history (last 24 hours)
    SELECT 
        wi.impact_id,
        wi.alert_id,
        wi.impact_type,
        wi.detected_utc,
        wi.cleared_utc,
        wa.hazard,
        wa.source_id
    FROM dbo.adl_flight_weather_impact wi
    JOIN dbo.weather_alerts wa ON wa.alert_id = wi.alert_id
    WHERE wi.flight_uid = @flight_uid
      AND wi.detected_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())
    ORDER BY wi.detected_utc DESC;
END
GO

PRINT '  Created procedure: sp_GetFlightWeatherImpact';
GO

-- ============================================================================
-- Procedure: sp_GetWeatherImpactSummary
-- Returns summary of all flights affected by weather
-- ============================================================================

IF OBJECT_ID('dbo.sp_GetWeatherImpactSummary', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GetWeatherImpactSummary;
GO

CREATE PROCEDURE dbo.sp_GetWeatherImpactSummary
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Summary by hazard type
    SELECT 
        wa.hazard,
        wa.alert_type,
        COUNT(DISTINCT wi.flight_uid) AS flights_affected,
        SUM(CASE WHEN wi.impact_type = 'DIRECT' THEN 1 ELSE 0 END) AS direct_impacts,
        SUM(CASE WHEN wi.impact_type = 'NEAR' THEN 1 ELSE 0 END) AS near_impacts
    FROM dbo.weather_alerts wa
    LEFT JOIN dbo.adl_flight_weather_impact wi ON wi.alert_id = wa.alert_id AND wi.cleared_utc IS NULL
    WHERE wa.is_active = 1
      AND wa.valid_to_utc > SYSUTCDATETIME()
    GROUP BY wa.hazard, wa.alert_type
    ORDER BY flights_affected DESC;
    
    -- Individual alert details
    SELECT 
        wa.alert_id,
        wa.alert_type,
        wa.hazard,
        wa.severity,
        wa.source_id,
        wa.valid_from_utc,
        wa.valid_to_utc,
        DATEDIFF(MINUTE, SYSUTCDATETIME(), wa.valid_to_utc) AS minutes_remaining,
        COUNT(DISTINCT wi.flight_uid) AS flights_affected
    FROM dbo.weather_alerts wa
    LEFT JOIN dbo.adl_flight_weather_impact wi ON wi.alert_id = wa.alert_id AND wi.cleared_utc IS NULL
    WHERE wa.is_active = 1
      AND wa.valid_to_utc > SYSUTCDATETIME()
    GROUP BY wa.alert_id, wa.alert_type, wa.hazard, wa.severity, wa.source_id, 
             wa.valid_from_utc, wa.valid_to_utc
    ORDER BY flights_affected DESC;
    
    -- List of affected flights
    SELECT TOP 100
        c.flight_uid,
        c.callsign,
        c.weather_impact,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        p.altitude_ft,
        wi.impact_type,
        wa.hazard,
        wa.source_id
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_weather_impact wi ON wi.flight_uid = c.flight_uid AND wi.cleared_utc IS NULL
    JOIN dbo.weather_alerts wa ON wa.alert_id = wi.alert_id
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.is_active = 1
    ORDER BY 
        CASE wi.impact_type WHEN 'DIRECT' THEN 1 ELSE 2 END,
        CASE wa.hazard WHEN 'CONVECTIVE' THEN 1 WHEN 'TURB' THEN 2 ELSE 3 END;
END
GO

PRINT '  Created procedure: sp_GetWeatherImpactSummary';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '============================================================================';
PRINT 'Migration 045 Complete';
PRINT '============================================================================';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_DetectWeatherImpact (batch detection for all flights)';
PRINT '  - sp_GetFlightWeatherImpact (single flight impact details)';
PRINT '  - sp_GetWeatherImpactSummary (system-wide impact summary)';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Add sp_DetectWeatherImpact call to refresh procedure';
PRINT '  2. Test with: EXEC sp_DetectWeatherImpact';
PRINT '============================================================================';
GO
