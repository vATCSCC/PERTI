
-- Step 5: Update sp_ImportBoundary to accept parent_fir parameter
CREATE   PROCEDURE sp_ImportBoundary
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
    @source_file VARCHAR(100) = NULL,
    @parent_fir VARCHAR(20) = NULL
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
            SET @geometry = GEOMETRY::STGeomFromText(@geography.STAsText(), 4326).MakeValid();
            SET @geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);
        END

        -- Step 6: Apply polygon orientation fix for large polygons
        IF @geography.STArea() > 255000000000000
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
                parent_fir = @parent_fir,
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
                parent_artcc, parent_fir, sector_number,
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
                @parent_artcc, @parent_fir, @sector_number,
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
        PRINT 'Error importing boundary ' + @boundary_code + ': ' + ERROR_MESSAGE();
        SET @boundary_id = -1;
    END CATCH

    -- Return result for PHP to fetch
    SELECT @boundary_id as boundary_id;
END;

(1 row affected)
