-- ============================================================================
-- 041_oooi_deploy.sql
-- Complete OOOI Zone Detection System Deployment
-- 
-- This script deploys the full OOOI system:
-- 1. Schema (tables, columns)
-- 2. Functions (zone detection)
-- 3. Stored procedures (zone transition, import, batch processing)
-- 4. Fallback zones for top airports
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  PERTI OOOI Zone Detection System - Full Deployment';
PRINT '  Version 1.0 - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';

-- ============================================================================
-- PART 1: SCHEMA
-- ============================================================================

PRINT '? PART 1: Schema';

-- airport_geometry table
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_geometry') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airport_geometry (
        id INT IDENTITY(1,1) PRIMARY KEY,
        airport_icao NVARCHAR(4) NOT NULL,
        zone_type NVARCHAR(16) NOT NULL,
        zone_name NVARCHAR(32) NULL,
        osm_id BIGINT NULL,
        geometry GEOGRAPHY NOT NULL,
        geometry_wkt NVARCHAR(MAX) NULL,
        center_lat DECIMAL(10,7) NULL,
        center_lon DECIMAL(11,7) NULL,
        heading_deg SMALLINT NULL,
        length_ft INT NULL,
        width_ft INT NULL,
        elevation_ft INT NULL,
        is_active BIT NOT NULL DEFAULT 1,
        source NVARCHAR(16) NOT NULL DEFAULT 'OSM',
        import_utc DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE NONCLUSTERED INDEX IX_airport_geo_icao ON dbo.airport_geometry (airport_icao, zone_type);
    CREATE SPATIAL INDEX IX_airport_geo_spatial ON dbo.airport_geometry (geometry) USING GEOGRAPHY_AUTO_GRID;
    PRINT '  ? Created airport_geometry';
END
ELSE PRINT '  - airport_geometry exists';
GO

-- adl_zone_events table
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_zone_events') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_zone_events (
        event_id BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid BIGINT NOT NULL,
        event_utc DATETIME2(0) NOT NULL,
        event_type NVARCHAR(16) NOT NULL,
        airport_icao NVARCHAR(4) NULL,
        from_zone NVARCHAR(16) NULL,
        to_zone NVARCHAR(16) NOT NULL,
        zone_name NVARCHAR(32) NULL,
        lat DECIMAL(10,7) NOT NULL,
        lon DECIMAL(11,7) NOT NULL,
        altitude_ft INT NULL,
        groundspeed_kts INT NULL,
        heading_deg SMALLINT NULL,
        vertical_rate_fpm INT NULL,
        detection_method NVARCHAR(32) NOT NULL DEFAULT 'OSM_GEOMETRY',
        distance_to_zone_m DECIMAL(10,2) NULL,
        confidence DECIMAL(3,2) NULL,
        INDEX IX_zone_events_flight (flight_uid, event_utc),
        INDEX IX_zone_events_airport (airport_icao, event_utc DESC)
    );
    PRINT '  ? Created adl_zone_events';
END
ELSE PRINT '  - adl_zone_events exists';
GO

-- airport_geometry_import_log
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_geometry_import_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airport_geometry_import_log (
        import_id INT IDENTITY(1,1) PRIMARY KEY,
        airport_icao NVARCHAR(4) NOT NULL,
        import_utc DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        source NVARCHAR(16) NOT NULL,
        zones_imported INT NOT NULL DEFAULT 0,
        runways_count INT NULL,
        taxiways_count INT NULL,
        parking_count INT NULL,
        success BIT NOT NULL DEFAULT 1,
        error_message NVARCHAR(MAX) NULL
    );
    PRINT '  ? Created airport_geometry_import_log';
END
ELSE PRINT '  - airport_geometry_import_log exists';
GO

-- Add columns to adl_flight_core
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'current_zone')
    ALTER TABLE dbo.adl_flight_core ADD current_zone NVARCHAR(16) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'current_zone_airport')
    ALTER TABLE dbo.adl_flight_core ADD current_zone_airport NVARCHAR(4) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_zone_check_utc')
    ALTER TABLE dbo.adl_flight_core ADD last_zone_check_utc DATETIME2(0) NULL;
PRINT '  ? adl_flight_core columns added';
GO

-- Add extended zone times to adl_flight_times
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'parking_left_utc')
    ALTER TABLE dbo.adl_flight_times ADD parking_left_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'taxiway_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD taxiway_entered_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'hold_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD hold_entered_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'runway_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD runway_entered_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'takeoff_roll_utc')
    ALTER TABLE dbo.adl_flight_times ADD takeoff_roll_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'rotation_utc')
    ALTER TABLE dbo.adl_flight_times ADD rotation_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'approach_start_utc')
    ALTER TABLE dbo.adl_flight_times ADD approach_start_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'threshold_utc')
    ALTER TABLE dbo.adl_flight_times ADD threshold_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'touchdown_utc')
    ALTER TABLE dbo.adl_flight_times ADD touchdown_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'rollout_end_utc')
    ALTER TABLE dbo.adl_flight_times ADD rollout_end_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'taxiway_arr_utc')
    ALTER TABLE dbo.adl_flight_times ADD taxiway_arr_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'parking_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD parking_entered_utc DATETIME2(0) NULL;
PRINT '  ? adl_flight_times extended zone columns added';
GO

PRINT '';
PRINT '? PART 2: Functions';

-- fn_DetectCurrentZone
IF OBJECT_ID('dbo.fn_DetectCurrentZone', 'FN') IS NOT NULL DROP FUNCTION dbo.fn_DetectCurrentZone;
GO
CREATE FUNCTION dbo.fn_DetectCurrentZone(@airport_icao NVARCHAR(4), @lat DECIMAL(10,7), @lon DECIMAL(11,7), @altitude_ft INT, @groundspeed_kts INT)
RETURNS NVARCHAR(16) AS
BEGIN
    DECLARE @zone NVARCHAR(16) = 'UNKNOWN';
    DECLARE @airport_elev INT, @agl INT;
    IF @lat IS NULL OR @lon IS NULL OR @airport_icao IS NULL RETURN 'UNKNOWN';
    SELECT @airport_elev = ISNULL(CAST(ELEV AS INT), 0) FROM dbo.apts WHERE ICAO_ID = @airport_icao;
    SET @agl = @altitude_ft - ISNULL(@airport_elev, 0);
    IF @agl > 500 RETURN 'AIRBORNE';
    SELECT TOP 1 @zone = ag.zone_type FROM dbo.airport_geometry ag
    WHERE ag.airport_icao = @airport_icao AND ag.is_active = 1 AND ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 100
    ORDER BY CASE ag.zone_type WHEN 'PARKING' THEN 1 WHEN 'GATE' THEN 2 WHEN 'HOLD' THEN 3 WHEN 'RUNWAY' THEN 4 WHEN 'TAXILANE' THEN 5 WHEN 'TAXIWAY' THEN 6 WHEN 'APRON' THEN 7 ELSE 99 END, ag.geometry.STDistance(geography::Point(@lat, @lon, 4326));
    IF @zone != 'UNKNOWN' RETURN @zone;
    IF @groundspeed_kts < 5 RETURN 'PARKING';
    IF @groundspeed_kts BETWEEN 5 AND 35 RETURN 'TAXIWAY';
    IF @groundspeed_kts > 35 AND @agl < 100 RETURN 'RUNWAY';
    RETURN 'AIRBORNE';
END
GO
PRINT '  ? Created fn_DetectCurrentZone';
GO

PRINT '';
PRINT '? PART 3: Stored Procedures';

-- sp_GenerateFallbackZones
IF OBJECT_ID('dbo.sp_GenerateFallbackZones', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_GenerateFallbackZones;
GO
CREATE PROCEDURE dbo.sp_GenerateFallbackZones @airport_icao NVARCHAR(4), @zones_created INT = NULL OUTPUT AS
BEGIN
    SET NOCOUNT ON;
    SET @zones_created = 0;
    DECLARE @lat DECIMAL(10,7), @lon DECIMAL(11,7), @elev INT;
    SELECT @lat = LAT_DECIMAL, @lon = LONG_DECIMAL, @elev = CAST(ELEV AS INT) FROM dbo.apts WHERE ICAO_ID = @airport_icao;
    IF @lat IS NULL RETURN;
    DELETE FROM dbo.airport_geometry WHERE airport_icao = @airport_icao AND source = 'FALLBACK';
    DECLARE @center GEOGRAPHY = geography::Point(@lat, @lon, 4326);
    INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, geometry, center_lat, center_lon, elevation_ft, source)
    VALUES 
        (@airport_icao, 'RUNWAY', 'FALLBACK_RWY', @center.STBuffer(200), @lat, @lon, @elev, 'FALLBACK'),
        (@airport_icao, 'TAXIWAY', 'FALLBACK_TWY', @center.STBuffer(500).STDifference(@center.STBuffer(200)), @lat, @lon, @elev, 'FALLBACK'),
        (@airport_icao, 'APRON', 'FALLBACK_APRON', @center.STBuffer(800).STDifference(@center.STBuffer(500)), @lat, @lon, @elev, 'FALLBACK'),
        (@airport_icao, 'PARKING', 'FALLBACK_PARK', @center.STBuffer(1200).STDifference(@center.STBuffer(800)), @lat, @lon, @elev, 'FALLBACK');
    SET @zones_created = 4;
    INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, success) VALUES (@airport_icao, 'FALLBACK', 4, 1);
END
GO
PRINT '  ? Created sp_GenerateFallbackZones';
GO

-- sp_ProcessZoneDetectionBatch
IF OBJECT_ID('dbo.sp_ProcessZoneDetectionBatch', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_ProcessZoneDetectionBatch;
GO
CREATE PROCEDURE dbo.sp_ProcessZoneDetectionBatch @transitions_detected INT = NULL OUTPUT AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @transitions_detected = 0;
    
    -- Identify flights near airports
    SELECT c.flight_uid, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, p.heading_deg, p.vertical_rate_fpm,
           c.current_zone AS prev_zone, c.current_zone_airport AS prev_airport,
           CASE WHEN ft.off_utc IS NULL THEN fp.fp_dept_icao WHEN ISNULL(p.pct_complete, 0) > 80 THEN fp.fp_dest_icao ELSE NULL END AS check_airport,
           CASE WHEN ft.off_utc IS NOT NULL THEN 1 ELSE 0 END AS has_departed
    INTO #flights
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1 AND p.lat IS NOT NULL AND (ft.off_utc IS NULL OR ISNULL(p.pct_complete, 0) > 80);
    
    -- Detect zones
    SELECT f.*, dbo.fn_DetectCurrentZone(f.check_airport, f.lat, f.lon, f.altitude_ft, f.groundspeed_kts) AS current_zone
    INTO #detections FROM #flights f WHERE f.check_airport IS NOT NULL;
    
    -- Log transitions
    INSERT INTO dbo.adl_zone_events (flight_uid, event_utc, event_type, airport_icao, from_zone, to_zone, lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm, detection_method, confidence)
    SELECT flight_uid, @now, 'TRANSITION', check_airport, prev_zone, current_zone, lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm, 'BATCH', 0.75
    FROM #detections WHERE current_zone != ISNULL(prev_zone, '');
    SET @transitions_detected = @@ROWCOUNT;
    
    -- Update core
    UPDATE c SET c.current_zone = d.current_zone, c.current_zone_airport = d.check_airport, c.last_zone_check_utc = @now
    FROM dbo.adl_flight_core c JOIN #detections d ON d.flight_uid = c.flight_uid;
    
    -- Update OOOI times for departures
    UPDATE ft SET
        out_utc = CASE WHEN d.prev_zone = 'PARKING' AND d.current_zone NOT IN ('PARKING','GATE') AND ft.out_utc IS NULL THEN @now ELSE ft.out_utc END,
        off_utc = CASE WHEN d.prev_zone = 'RUNWAY' AND d.current_zone = 'AIRBORNE' AND ft.off_utc IS NULL THEN @now ELSE ft.off_utc END
    FROM dbo.adl_flight_times ft JOIN #detections d ON d.flight_uid = ft.flight_uid WHERE d.has_departed = 0 AND d.current_zone != ISNULL(d.prev_zone, '');
    
    -- Update OOOI times for arrivals
    UPDATE ft SET
        on_utc = CASE WHEN d.prev_zone = 'AIRBORNE' AND d.current_zone = 'RUNWAY' AND ft.on_utc IS NULL THEN @now ELSE ft.on_utc END,
        in_utc = CASE WHEN d.prev_zone IN ('TAXIWAY','APRON') AND d.current_zone = 'PARKING' AND ft.in_utc IS NULL THEN @now ELSE ft.in_utc END
    FROM dbo.adl_flight_times ft JOIN #detections d ON d.flight_uid = ft.flight_uid WHERE d.has_departed = 1 AND d.current_zone != ISNULL(d.prev_zone, '');
    
    DROP TABLE #flights; DROP TABLE #detections;
END
GO
PRINT '  ? Created sp_ProcessZoneDetectionBatch';
GO

PRINT '';
PRINT '? PART 4: Seed Fallback Zones for Top Airports';

-- Generate fallback zones for busiest VATSIM airports
DECLARE @airports TABLE (icao NVARCHAR(4));
INSERT INTO @airports VALUES 
    ('KLAX'),('KJFK'),('KATL'),('KORD'),('KDFW'),('KDEN'),('KSFO'),('KLAS'),('KMIA'),('KPHX'),
    ('KSEA'),('KEWR'),('KMCO'),('KBOS'),('KDTW'),('KIAD'),('KPHL'),('KFLL'),('KSAN'),('KSLC'),
    ('CYYZ'),('CYVR'),('CYUL'),('EGLL'),('EGKK'),('EHAM'),('EDDF'),('LFPG'),('LEMD'),('LIRF');

DECLARE @icao NVARCHAR(4), @created INT;
DECLARE airport_cursor CURSOR FOR SELECT icao FROM @airports;
OPEN airport_cursor;
FETCH NEXT FROM airport_cursor INTO @icao;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.airport_geometry WHERE airport_icao = @icao)
    BEGIN
        EXEC dbo.sp_GenerateFallbackZones @icao, @created OUTPUT;
    END
    FETCH NEXT FROM airport_cursor INTO @icao;
END
CLOSE airport_cursor;
DEALLOCATE airport_cursor;

SELECT COUNT(DISTINCT airport_icao) AS airports_with_zones FROM dbo.airport_geometry;
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  OOOI Deployment Complete!';
PRINT '';
PRINT '  To integrate with refresh procedure, add:';
PRINT '';
PRINT '    DECLARE @zone_transitions INT;';
PRINT '    EXEC dbo.sp_ProcessZoneDetectionBatch @zone_transitions OUTPUT;';
PRINT '';
PRINT '  Next: Import OSM data for more accurate zones (optional)';
PRINT '==========================================================================';
GO
