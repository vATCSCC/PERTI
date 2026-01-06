-- ============================================================================
-- sp_ImportAirportGeometry.sql
-- Imports OSM airport geometry from JSON (Overpass API result)
-- 
-- Usage: Call from PHP after fetching from Overpass API
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_ImportAirportGeometry', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ImportAirportGeometry;
GO

CREATE PROCEDURE dbo.sp_ImportAirportGeometry
    @airport_icao   NVARCHAR(4),
    @osm_json       NVARCHAR(MAX),
    @zones_imported INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    SET @zones_imported = 0;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @runways INT = 0;
    DECLARE @taxiways INT = 0;
    DECLARE @parking INT = 0;
    DECLARE @error_msg NVARCHAR(MAX) = NULL;
    
    BEGIN TRY
        -- Delete existing geometry for this airport
        DELETE FROM dbo.airport_geometry WHERE airport_icao = @airport_icao;
        
        -- ====================================================================
        -- Parse OSM elements from JSON
        -- ====================================================================
        
        -- The OSM JSON structure:
        -- { "elements": [ { "type": "way/node", "id": 123, "tags": {...}, "geometry": [...] }, ... ] }
        
        -- Insert elements with geometry (ways with nodes resolved)
        INSERT INTO dbo.airport_geometry (
            airport_icao, zone_type, zone_name, osm_id, 
            geometry, geometry_wkt, center_lat, center_lon,
            source
        )
        SELECT 
            @airport_icao,
            -- Map aeroway tag to zone type
            CASE 
                WHEN tags.aeroway = 'runway' THEN 'RUNWAY'
                WHEN tags.aeroway = 'taxiway' THEN 'TAXIWAY'
                WHEN tags.aeroway = 'taxilane' THEN 'TAXILANE'
                WHEN tags.aeroway = 'apron' THEN 'APRON'
                WHEN tags.aeroway = 'parking_position' THEN 'PARKING'
                WHEN tags.aeroway = 'gate' THEN 'GATE'
                WHEN tags.aeroway = 'holding_position' THEN 'HOLD'
                ELSE 'UNKNOWN'
            END,
            -- Zone name (ref tag for runways/taxiways, name for others)
            COALESCE(tags.ref, tags.name),
            elem.id,
            -- Create geography - need to build from coordinates
            CASE 
                WHEN elem.elem_type = 'node' THEN
                    -- Point: buffer to ~20m radius
                    geography::Point(elem.lat, elem.lon, 4326).STBuffer(20)
                ELSE
                    -- Way: would need resolved geometry (handled by Overpass)
                    -- For now, use center point with buffer
                    geography::Point(elem.lat, elem.lon, 4326).STBuffer(
                        CASE tags.aeroway
                            WHEN 'runway' THEN 50    -- Wide buffer for runways
                            WHEN 'taxiway' THEN 20
                            WHEN 'apron' THEN 100
                            ELSE 15
                        END
                    )
            END,
            NULL,  -- WKT populated separately if needed
            elem.lat,
            elem.lon,
            'OSM'
        FROM OPENJSON(@osm_json, '$.elements')
        WITH (
            elem_type NVARCHAR(16) '$.type',
            id BIGINT '$.id',
            lat DECIMAL(10,7) '$.lat',
            lon DECIMAL(11,7) '$.lon',
            center_lat DECIMAL(10,7) '$.center.lat',
            center_lon DECIMAL(11,7) '$.center.lon',
            tags NVARCHAR(MAX) '$.tags' AS JSON
        ) AS elem
        OUTER APPLY OPENJSON(elem.tags)
        WITH (
            aeroway NVARCHAR(32) '$.aeroway',
            ref NVARCHAR(32) '$.ref',
            name NVARCHAR(64) '$.name'
        ) AS tags
        WHERE tags.aeroway IS NOT NULL
          AND COALESCE(elem.lat, elem.center_lat) IS NOT NULL;
        
        SET @zones_imported = @@ROWCOUNT;
        
        -- Count by type
        SELECT @runways = COUNT(*) FROM dbo.airport_geometry 
        WHERE airport_icao = @airport_icao AND zone_type = 'RUNWAY';
        SELECT @taxiways = COUNT(*) FROM dbo.airport_geometry 
        WHERE airport_icao = @airport_icao AND zone_type IN ('TAXIWAY', 'TAXILANE');
        SELECT @parking = COUNT(*) FROM dbo.airport_geometry 
        WHERE airport_icao = @airport_icao AND zone_type IN ('PARKING', 'GATE');
        
    END TRY
    BEGIN CATCH
        SET @error_msg = ERROR_MESSAGE();
        SET @zones_imported = 0;
    END CATCH
    
    -- Log import
    INSERT INTO dbo.airport_geometry_import_log (
        airport_icao, source, zones_imported, 
        runways_count, taxiways_count, parking_count,
        success, error_message
    )
    VALUES (
        @airport_icao, 'OSM', @zones_imported,
        @runways, @taxiways, @parking,
        CASE WHEN @error_msg IS NULL THEN 1 ELSE 0 END,
        @error_msg
    );
    
END
GO

PRINT 'Created stored procedure dbo.sp_ImportAirportGeometry';
GO


-- ============================================================================
-- sp_GenerateFallbackZones
-- Creates distance-based fallback zones for airports without OSM data
-- ============================================================================

IF OBJECT_ID('dbo.sp_GenerateFallbackZones', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GenerateFallbackZones;
GO

CREATE PROCEDURE dbo.sp_GenerateFallbackZones
    @airport_icao   NVARCHAR(4),
    @zones_created  INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    SET @zones_created = 0;
    
    DECLARE @lat DECIMAL(10,7);
    DECLARE @lon DECIMAL(11,7);
    DECLARE @elev INT;
    
    -- Get airport center
    SELECT @lat = LAT_DECIMAL, @lon = LONG_DECIMAL, @elev = CAST(ELEV AS INT)
    FROM dbo.apts
    WHERE ICAO_ID = @airport_icao;
    
    IF @lat IS NULL
        RETURN;
    
    -- Delete existing fallback zones
    DELETE FROM dbo.airport_geometry 
    WHERE airport_icao = @airport_icao AND source = 'FALLBACK';
    
    -- Create concentric zones around airport center
    DECLARE @center GEOGRAPHY = geography::Point(@lat, @lon, 4326);
    
    -- Runway zone: 0-200m from center (rough approximation)
    INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, geometry, center_lat, center_lon, elevation_ft, source)
    VALUES (@airport_icao, 'RUNWAY', 'FALLBACK_RWY', @center.STBuffer(200), @lat, @lon, @elev, 'FALLBACK');
    
    -- Taxiway zone: 200-500m ring
    INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, geometry, center_lat, center_lon, elevation_ft, source)
    VALUES (@airport_icao, 'TAXIWAY', 'FALLBACK_TWY', @center.STBuffer(500).STDifference(@center.STBuffer(200)), @lat, @lon, @elev, 'FALLBACK');
    
    -- Apron zone: 500-800m ring
    INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, geometry, center_lat, center_lon, elevation_ft, source)
    VALUES (@airport_icao, 'APRON', 'FALLBACK_APRON', @center.STBuffer(800).STDifference(@center.STBuffer(500)), @lat, @lon, @elev, 'FALLBACK');
    
    -- Parking zone: 800-1200m ring
    INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, geometry, center_lat, center_lon, elevation_ft, source)
    VALUES (@airport_icao, 'PARKING', 'FALLBACK_PARK', @center.STBuffer(1200).STDifference(@center.STBuffer(800)), @lat, @lon, @elev, 'FALLBACK');
    
    SET @zones_created = 4;
    
    -- Log
    INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, success)
    VALUES (@airport_icao, 'FALLBACK', 4, 1);
    
END
GO

PRINT 'Created stored procedure dbo.sp_GenerateFallbackZones';
GO


-- ============================================================================
-- sp_EnsureAirportGeometry
-- Ensures an airport has geometry (creates fallback if needed)
-- ============================================================================

IF OBJECT_ID('dbo.sp_EnsureAirportGeometry', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_EnsureAirportGeometry;
GO

CREATE PROCEDURE dbo.sp_EnsureAirportGeometry
    @airport_icao   NVARCHAR(4)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check if airport already has geometry
    IF EXISTS (SELECT 1 FROM dbo.airport_geometry WHERE airport_icao = @airport_icao)
        RETURN;
    
    -- Create fallback zones
    DECLARE @created INT;
    EXEC dbo.sp_GenerateFallbackZones @airport_icao, @created OUTPUT;
    
END
GO

PRINT 'Created stored procedure dbo.sp_EnsureAirportGeometry';
GO
