-- =====================================================
-- Phase 5E.1: Boundary Import Stored Procedure (v4 - Geometry-First)
-- Migration: 050_boundary_import_procedure.sql
-- Description: Imports boundaries from WKT with robust geometry handling
-- Fix: Parse as GEOMETRY first, MakeValid, then convert to GEOGRAPHY
-- =====================================================

-- Procedure to import a single boundary with proper polygon orientation
CREATE OR ALTER PROCEDURE sp_ImportBoundary
    @boundary_type VARCHAR(20),
    @boundary_code VARCHAR(50),
    @boundary_name NVARCHAR(255) = NULL,
    @parent_artcc VARCHAR(10) = NULL,
    @sector_number VARCHAR(20) = NULL,
    @icao_code VARCHAR(10) = NULL,
    @vatsim_region VARCHAR(50) = NULL,
    @vatsim_division VARCHAR(50) = NULL,
    @vatsim_subdivision VARCHAR(50) = NULL,
    @is_oceanic BIT = 0,
    @floor_altitude INT = NULL,
    @ceiling_altitude INT = NULL,
    @label_lat DECIMAL(10,6) = NULL,
    @label_lon DECIMAL(11,6) = NULL,
    @wkt_geometry NVARCHAR(MAX),
    @shape_length DECIMAL(18,10) = NULL,
    @shape_area DECIMAL(18,10) = NULL,
    @source_object_id INT = NULL,
    @source_fid INT = NULL,
    @source_file VARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @geometry GEOMETRY;
    DECLARE @geography GEOGRAPHY;
    DECLARE @oriented_geography GEOGRAPHY;
    DECLARE @boundary_id INT;
    
    BEGIN TRY
        -- Step 1: Parse as GEOMETRY first (more lenient than GEOGRAPHY)
        SET @geometry = GEOMETRY::STGeomFromText(@wkt_geometry, 4326);
        
        -- Step 2: Apply MakeValid on geometry to fix any issues
        IF @geometry.STIsValid() = 0
        BEGIN
            SET @geometry = @geometry.MakeValid();
        END
        
        -- Step 3: Ensure we have a polygon-type geometry
        -- MakeValid can sometimes return GeometryCollection, extract polygon part
        IF @geometry.STGeometryType() = 'GeometryCollection'
        BEGIN
            DECLARE @i INT = 1;
            DECLARE @polyGeom GEOMETRY = NULL;
            WHILE @i <= @geometry.STNumGeometries()
            BEGIN
                DECLARE @part GEOMETRY = @geometry.STGeometryN(@i);
                IF @part.STGeometryType() IN ('Polygon', 'MultiPolygon')
                BEGIN
                    IF @polyGeom IS NULL
                        SET @polyGeom = @part;
                    ELSE
                        SET @polyGeom = @polyGeom.STUnion(@part);
                END
                SET @i = @i + 1;
            END
            IF @polyGeom IS NOT NULL
                SET @geometry = @polyGeom;
        END
        
        -- Step 4: Convert to geography
        SET @geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);
        
        -- Step 5: Check if geography is valid, apply MakeValid if needed
        IF @geography.STIsValid() = 0
        BEGIN
            -- Re-parse through geometry to fix
            SET @geometry = GEOMETRY::STGeomFromText(@geography.STAsText(), 4326).MakeValid();
            SET @geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);
        END
        
        -- Step 6: Apply polygon orientation fix for large polygons
        -- SQL Server geography requires exterior rings to be counter-clockwise
        IF @geography.STArea() > 255000000000000  -- ~255 trillion sq meters (half Earth)
        BEGIN
            SET @oriented_geography = @geography.ReorientObject();
        END
        ELSE
        BEGIN
            SET @oriented_geography = @geography;
        END
        
        -- Step 7: Final validity check after reorientation
        IF @oriented_geography.STIsValid() = 0
        BEGIN
            SET @geometry = GEOMETRY::STGeomFromText(@oriented_geography.STAsText(), 4326).MakeValid();
            SET @oriented_geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);
        END
        
        -- Check for existing boundary with same type and code
        DECLARE @existing_id INT;
        SELECT @existing_id = boundary_id 
        FROM adl_boundary 
        WHERE boundary_type = @boundary_type 
          AND boundary_code = @boundary_code;
        
        IF @existing_id IS NOT NULL
        BEGIN
            -- Update existing
            UPDATE adl_boundary
            SET boundary_name = @boundary_name,
                parent_artcc = @parent_artcc,
                sector_number = @sector_number,
                icao_code = @icao_code,
                vatsim_region = @vatsim_region,
                vatsim_division = @vatsim_division,
                vatsim_subdivision = @vatsim_subdivision,
                is_oceanic = @is_oceanic,
                floor_altitude = @floor_altitude,
                ceiling_altitude = @ceiling_altitude,
                label_lat = @label_lat,
                label_lon = @label_lon,
                boundary_geography = @oriented_geography,
                shape_length = @shape_length,
                shape_area = @shape_area,
                source_object_id = @source_object_id,
                source_fid = @source_fid,
                source_file = @source_file,
                is_active = 1,
                updated_at = GETUTCDATE()
            WHERE boundary_id = @existing_id;
            
            SET @boundary_id = @existing_id;
        END
        ELSE
        BEGIN
            -- Insert new
            INSERT INTO adl_boundary (
                boundary_type, boundary_code, boundary_name,
                parent_artcc, sector_number,
                icao_code, vatsim_region, vatsim_division, vatsim_subdivision,
                is_oceanic, floor_altitude, ceiling_altitude,
                label_lat, label_lon,
                boundary_geography,
                shape_length, shape_area,
                source_object_id, source_fid, source_file,
                is_active
            )
            VALUES (
                @boundary_type, @boundary_code, @boundary_name,
                @parent_artcc, @sector_number,
                @icao_code, @vatsim_region, @vatsim_division, @vatsim_subdivision,
                @is_oceanic, @floor_altitude, @ceiling_altitude,
                @label_lat, @label_lon,
                @oriented_geography,
                @shape_length, @shape_area,
                @source_object_id, @source_fid, @source_file,
                1
            );
            
            SET @boundary_id = SCOPE_IDENTITY();
        END
        
    END TRY
    BEGIN CATCH
        -- Log error but don't fail entirely
        PRINT 'Error importing boundary ' + @boundary_code + ': ' + ERROR_MESSAGE();
        SET @boundary_id = -1;
    END CATCH
    
    -- Return result for PHP to fetch
    SELECT @boundary_id as boundary_id;
END;
GO


-- Procedure to detect which boundaries contain a flight position
CREATE OR ALTER PROCEDURE sp_DetectFlightBoundaries
    @flight_uid BIGINT,
    @latitude DECIMAL(10,7),
    @longitude DECIMAL(11,7),
    @altitude INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @point GEOGRAPHY = GEOGRAPHY::Point(@latitude, @longitude, 4326);
    DECLARE @now DATETIME2 = GETUTCDATE();
    
    -- Find matching ARTCC
    DECLARE @artcc_id INT, @artcc_code VARCHAR(50);
    SELECT TOP 1 
        @artcc_id = boundary_id,
        @artcc_code = boundary_code
    FROM adl_boundary
    WHERE boundary_type = 'ARTCC'
      AND is_active = 1
      AND boundary_geography.STContains(@point) = 1
    ORDER BY 
        is_oceanic ASC,
        boundary_geography.STArea() ASC;
    
    -- Find matching sector
    DECLARE @sector_id INT, @sector_code VARCHAR(50);
    SELECT TOP 1 
        @sector_id = boundary_id,
        @sector_code = boundary_code
    FROM adl_boundary
    WHERE boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
      AND is_active = 1
      AND boundary_geography.STContains(@point) = 1
      AND (floor_altitude IS NULL OR @altitude IS NULL OR @altitude >= floor_altitude * 100)
      AND (ceiling_altitude IS NULL OR @altitude IS NULL OR @altitude <= ceiling_altitude * 100)
    ORDER BY boundary_geography.STArea() ASC;
    
    -- Find matching TRACON (only at lower altitudes)
    DECLARE @tracon_id INT, @tracon_code VARCHAR(50);
    IF @altitude IS NULL OR @altitude < 18000
    BEGIN
        SELECT TOP 1 
            @tracon_id = boundary_id,
            @tracon_code = boundary_code
        FROM adl_boundary
        WHERE boundary_type = 'TRACON'
          AND is_active = 1
          AND boundary_geography.STContains(@point) = 1
        ORDER BY boundary_geography.STArea() ASC;
    END
    
    -- Get previous boundaries for this flight
    DECLARE @prev_artcc_id INT, @prev_sector_id INT, @prev_tracon_id INT;
    SELECT 
        @prev_artcc_id = current_artcc_id,
        @prev_sector_id = current_sector_id,
        @prev_tracon_id = current_tracon_id
    FROM adl_flight_core
    WHERE flight_uid = @flight_uid;
    
    -- Log boundary transitions
    IF ISNULL(@prev_artcc_id, 0) <> ISNULL(@artcc_id, 0)
    BEGIN
        IF @prev_artcc_id IS NOT NULL
        BEGIN
            UPDATE adl_flight_boundary_log
            SET exit_time = @now, exit_lat = @latitude, exit_lon = @longitude, exit_altitude = @altitude
            WHERE flight_id = @flight_uid AND boundary_id = @prev_artcc_id AND exit_time IS NULL;
        END
        
        IF @artcc_id IS NOT NULL
        BEGIN
            INSERT INTO adl_flight_boundary_log (flight_id, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
            VALUES (@flight_uid, @artcc_id, 'ARTCC', @artcc_code, @now, @latitude, @longitude, @altitude);
        END
    END
    
    IF ISNULL(@prev_sector_id, 0) <> ISNULL(@sector_id, 0)
    BEGIN
        IF @prev_sector_id IS NOT NULL
        BEGIN
            UPDATE adl_flight_boundary_log
            SET exit_time = @now, exit_lat = @latitude, exit_lon = @longitude, exit_altitude = @altitude
            WHERE flight_id = @flight_uid AND boundary_id = @prev_sector_id AND exit_time IS NULL;
        END
        
        IF @sector_id IS NOT NULL
        BEGIN
            DECLARE @sector_type VARCHAR(20);
            SELECT @sector_type = boundary_type FROM adl_boundary WHERE boundary_id = @sector_id;
            
            INSERT INTO adl_flight_boundary_log (flight_id, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
            VALUES (@flight_uid, @sector_id, @sector_type, @sector_code, @now, @latitude, @longitude, @altitude);
        END
    END
    
    IF ISNULL(@prev_tracon_id, 0) <> ISNULL(@tracon_id, 0)
    BEGIN
        IF @prev_tracon_id IS NOT NULL
        BEGIN
            UPDATE adl_flight_boundary_log
            SET exit_time = @now, exit_lat = @latitude, exit_lon = @longitude, exit_altitude = @altitude
            WHERE flight_id = @flight_uid AND boundary_id = @prev_tracon_id AND exit_time IS NULL;
        END
        
        IF @tracon_id IS NOT NULL
        BEGIN
            INSERT INTO adl_flight_boundary_log (flight_id, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
            VALUES (@flight_uid, @tracon_id, 'TRACON', @tracon_code, @now, @latitude, @longitude, @altitude);
        END
    END
    
    -- Update flight core with current boundaries
    UPDATE adl_flight_core
    SET current_artcc = @artcc_code,
        current_artcc_id = @artcc_id,
        current_sector = @sector_code,
        current_sector_id = @sector_id,
        current_tracon = @tracon_code,
        current_tracon_id = @tracon_id,
        boundary_updated_at = @now
    WHERE flight_uid = @flight_uid;
    
    -- Return current boundaries
    SELECT 
        @artcc_code as artcc,
        @artcc_id as artcc_id,
        @sector_code as sector,
        @sector_id as sector_id,
        @tracon_code as tracon,
        @tracon_id as tracon_id;
END;
GO


-- Batch detection for all active flights
CREATE OR ALTER PROCEDURE sp_DetectAllFlightBoundaries
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @processed INT = 0;
    DECLARE @start_time DATETIME2 = GETUTCDATE();
    
    DECLARE @flight_uid BIGINT, @lat DECIMAL(10,7), @lon DECIMAL(11,7), @alt INT;
    
    DECLARE flight_cursor CURSOR FAST_FORWARD FOR
    SELECT fc.flight_uid, fp.lat, fp.lon, fp.altitude_ft
    FROM adl_flight_core fc
    INNER JOIN adl_flight_position fp ON fc.flight_uid = fp.flight_uid
    WHERE fp.lat IS NOT NULL 
      AND fp.lon IS NOT NULL
      AND fc.is_active = 1;
    
    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO @flight_uid, @lat, @lon, @alt;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC sp_DetectFlightBoundaries @flight_uid, @lat, @lon, @alt;
        SET @processed = @processed + 1;
        FETCH NEXT FROM flight_cursor INTO @flight_uid, @lat, @lon, @alt;
    END
    
    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;
    
    DECLARE @elapsed INT = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE());
    
    SELECT @processed as flights_processed, @elapsed as elapsed_ms;
END;
GO


PRINT 'Phase 5E.1: Boundary import procedures created successfully (v4 - Geometry-First)';
GO
