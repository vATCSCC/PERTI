-- =====================================================
-- Phase 5E.1: Boundary Import Stored Procedure (Fixed)
-- Migration: 050_boundary_import_procedure.sql
-- Description: Imports boundaries from WKT with polygon orientation fix
-- =====================================================

-- Procedure to import a single boundary with proper polygon orientation
-- Note: Accepts WKT instead of GeoJSON (PHP converts GeoJSON->WKT before calling)
CREATE OR ALTER PROCEDURE sp_ImportBoundary
    @boundary_type VARCHAR(20),
    @boundary_code VARCHAR(20),
    @boundary_name VARCHAR(100) = NULL,
    @parent_artcc VARCHAR(10) = NULL,
    @sector_number VARCHAR(10) = NULL,
    @icao_code VARCHAR(10) = NULL,
    @vatsim_region VARCHAR(20) = NULL,
    @vatsim_division VARCHAR(20) = NULL,
    @vatsim_subdivision VARCHAR(20) = NULL,
    @is_oceanic BIT = 0,
    @floor_altitude INT = NULL,
    @ceiling_altitude INT = NULL,
    @label_lat DECIMAL(10,6) = NULL,
    @label_lon DECIMAL(11,6) = NULL,
    @wkt_geometry NVARCHAR(MAX),          -- WKT format (POLYGON, MULTIPOLYGON)
    @shape_length DECIMAL(15,10) = NULL,
    @shape_area DECIMAL(15,10) = NULL,
    @source_object_id INT = NULL,
    @source_fid INT = NULL,
    @source_file VARCHAR(50) = NULL,
    @boundary_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @geography GEOGRAPHY;
    DECLARE @oriented_geography GEOGRAPHY;
    
    BEGIN TRY
        -- Parse WKT to geography
        SET @geography = GEOGRAPHY::STGeomFromText(@wkt_geometry, 4326);
        
        -- Apply polygon orientation fix
        -- SQL Server geography requires exterior rings to be counter-clockwise
        -- Large polygons (>hemisphere) may be inverted without this fix
        IF @geography.STArea() > 255000000000000  -- ~255 trillion sq meters (half Earth)
        BEGIN
            -- Reorient the polygon
            SET @oriented_geography = @geography.ReorientObject();
        END
        ELSE
        BEGIN
            SET @oriented_geography = @geography;
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
        -- Log error but don't fail entirely - some geometries may be invalid
        PRINT 'Error importing boundary ' + @boundary_code + ': ' + ERROR_MESSAGE();
        SET @boundary_id = -1;
    END CATCH
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
    DECLARE @artcc_id INT, @artcc_code VARCHAR(20);
    SELECT TOP 1 
        @artcc_id = boundary_id,
        @artcc_code = boundary_code
    FROM adl_boundary
    WHERE boundary_type = 'ARTCC'
      AND is_active = 1
      AND boundary_geography.STContains(@point) = 1
    ORDER BY 
        -- Prefer non-oceanic, then by area (smaller = more specific)
        is_oceanic ASC,
        boundary_geography.STArea() ASC;
    
    -- Find matching sector
    DECLARE @sector_id INT, @sector_code VARCHAR(20);
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
    
    -- Find matching TRACON (only at lower altitudes, typically < FL180)
    DECLARE @tracon_id INT, @tracon_code VARCHAR(20);
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
    -- ARTCC transition
    IF ISNULL(@prev_artcc_id, 0) <> ISNULL(@artcc_id, 0)
    BEGIN
        -- Close previous ARTCC entry
        IF @prev_artcc_id IS NOT NULL
        BEGIN
            UPDATE adl_flight_boundary_log
            SET exit_time = @now,
                exit_lat = @latitude,
                exit_lon = @longitude,
                exit_altitude = @altitude
            WHERE flight_id = @flight_uid
              AND boundary_id = @prev_artcc_id
              AND exit_time IS NULL;
        END
        
        -- Create new ARTCC entry
        IF @artcc_id IS NOT NULL
        BEGIN
            INSERT INTO adl_flight_boundary_log (
                flight_id, boundary_id, boundary_type, boundary_code,
                entry_time, entry_lat, entry_lon, entry_altitude
            )
            VALUES (
                @flight_uid, @artcc_id, 'ARTCC', @artcc_code,
                @now, @latitude, @longitude, @altitude
            );
        END
    END
    
    -- Sector transition
    IF ISNULL(@prev_sector_id, 0) <> ISNULL(@sector_id, 0)
    BEGIN
        IF @prev_sector_id IS NOT NULL
        BEGIN
            UPDATE adl_flight_boundary_log
            SET exit_time = @now,
                exit_lat = @latitude,
                exit_lon = @longitude,
                exit_altitude = @altitude
            WHERE flight_id = @flight_uid
              AND boundary_id = @prev_sector_id
              AND exit_time IS NULL;
        END
        
        IF @sector_id IS NOT NULL
        BEGIN
            DECLARE @sector_type VARCHAR(20);
            SELECT @sector_type = boundary_type FROM adl_boundary WHERE boundary_id = @sector_id;
            
            INSERT INTO adl_flight_boundary_log (
                flight_id, boundary_id, boundary_type, boundary_code,
                entry_time, entry_lat, entry_lon, entry_altitude
            )
            VALUES (
                @flight_uid, @sector_id, @sector_type, @sector_code,
                @now, @latitude, @longitude, @altitude
            );
        END
    END
    
    -- TRACON transition
    IF ISNULL(@prev_tracon_id, 0) <> ISNULL(@tracon_id, 0)
    BEGIN
        IF @prev_tracon_id IS NOT NULL
        BEGIN
            UPDATE adl_flight_boundary_log
            SET exit_time = @now,
                exit_lat = @latitude,
                exit_lon = @longitude,
                exit_altitude = @altitude
            WHERE flight_id = @flight_uid
              AND boundary_id = @prev_tracon_id
              AND exit_time IS NULL;
        END
        
        IF @tracon_id IS NOT NULL
        BEGIN
            INSERT INTO adl_flight_boundary_log (
                flight_id, boundary_id, boundary_type, boundary_code,
                entry_time, entry_lat, entry_lon, entry_altitude
            )
            VALUES (
                @flight_uid, @tracon_id, 'TRACON', @tracon_code,
                @now, @latitude, @longitude, @altitude
            );
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
    
    -- Process each active flight with position data
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


PRINT 'Phase 5E.1: Boundary import procedures created successfully';
PRINT 'Procedures: sp_ImportBoundary, sp_DetectFlightBoundaries, sp_DetectAllFlightBoundaries';
GO
