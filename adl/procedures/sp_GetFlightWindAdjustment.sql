-- ============================================================================
-- sp_GetFlightWindAdjustment
--
-- Calculates wind adjustment (headwind/tailwind) for a flight based on:
-- - Current position or departure airport
-- - Destination airport
-- - Cruise altitude
--
-- Returns the average wind component along the flight track.
-- Positive = tailwind (reduces flight time)
-- Negative = headwind (increases flight time)
--
-- Part of ETA Accuracy Improvement Initiative
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_GetFlightWindAdjustment') AND type = 'P')
    DROP PROCEDURE dbo.sp_GetFlightWindAdjustment;
GO

CREATE PROCEDURE dbo.sp_GetFlightWindAdjustment
    @flight_uid         BIGINT,
    @wind_component_kts DECIMAL(6,2) OUTPUT,
    @wind_confidence    DECIMAL(3,2) OUTPUT,
    @debug              BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    -- Initialize outputs
    SET @wind_component_kts = 0;
    SET @wind_confidence = 0;

    -- Get flight details
    DECLARE @curr_lat DECIMAL(10,7);
    DECLARE @curr_lon DECIMAL(11,7);
    DECLARE @dest_lat DECIMAL(10,7);
    DECLARE @dest_lon DECIMAL(11,7);
    DECLARE @altitude_ft INT;
    DECLARE @dept_lat DECIMAL(10,7);
    DECLARE @dept_lon DECIMAL(11,7);
    DECLARE @phase NVARCHAR(16);

    SELECT
        @curr_lat = p.lat,
        @curr_lon = p.lon,
        @altitude_ft = COALESCE(p.altitude_ft, fp.fp_altitude_ft, 35000),
        @phase = c.phase
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.flight_uid = @flight_uid;

    -- Get departure and destination coordinates
    SELECT
        @dept_lat = dept.LAT_DECIMAL,
        @dept_lon = dept.LONG_DECIMAL,
        @dest_lat = dest.LAT_DECIMAL,
        @dest_lon = dest.LONG_DECIMAL
    FROM dbo.adl_flight_plan fp
    LEFT JOIN dbo.apts dept ON dept.ICAO_ID = fp.fp_dept_icao
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
    WHERE fp.flight_uid = @flight_uid;

    -- If no destination, can't calculate wind
    IF @dest_lat IS NULL OR @dest_lon IS NULL
    BEGIN
        IF @debug = 1 PRINT 'No destination coordinates available';
        RETURN;
    END

    -- Determine starting point
    IF @curr_lat IS NULL OR @curr_lon IS NULL
    BEGIN
        -- Prefile or no position - use departure airport
        IF @dept_lat IS NULL OR @dept_lon IS NULL
        BEGIN
            IF @debug = 1 PRINT 'No position or departure coordinates';
            RETURN;
        END
        SET @curr_lat = @dept_lat;
        SET @curr_lon = @dept_lon;
    END

    -- Check if we have wind data
    DECLARE @wind_count INT;
    SELECT @wind_count = COUNT(*)
    FROM dbo.wind_grid
    WHERE valid_time_utc >= DATEADD(HOUR, -6, SYSUTCDATETIME())
      AND valid_time_utc <= DATEADD(HOUR, 6, SYSUTCDATETIME());

    IF @wind_count = 0
    BEGIN
        IF @debug = 1 PRINT 'No wind data available';
        RETURN;
    END

    -- Calculate track bearing from current position to destination
    -- Using simplified great circle initial bearing
    DECLARE @lat1_rad FLOAT = RADIANS(@curr_lat);
    DECLARE @lat2_rad FLOAT = RADIANS(@dest_lat);
    DECLARE @dlon_rad FLOAT = RADIANS(@dest_lon - @curr_lon);

    DECLARE @x FLOAT = SIN(@dlon_rad) * COS(@lat2_rad);
    DECLARE @y FLOAT = COS(@lat1_rad) * SIN(@lat2_rad) - SIN(@lat1_rad) * COS(@lat2_rad) * COS(@dlon_rad);
    DECLARE @track_deg INT = CAST((DEGREES(ATN2(@x, @y)) + 360) % 360 AS INT);

    IF @debug = 1
    BEGIN
        PRINT 'Position: (' + CAST(@curr_lat AS VARCHAR) + ', ' + CAST(@curr_lon AS VARCHAR) + ')';
        PRINT 'Destination: (' + CAST(@dest_lat AS VARCHAR) + ', ' + CAST(@dest_lon AS VARCHAR) + ')';
        PRINT 'Track: ' + CAST(@track_deg AS VARCHAR) + ' degrees';
        PRINT 'Altitude: ' + CAST(@altitude_ft AS VARCHAR) + ' ft';
    END

    -- Sample wind at multiple points along the route
    -- Use 3 sample points: current, midpoint, and near destination
    DECLARE @samples TABLE (
        sample_id INT,
        lat DECIMAL(10,7),
        lon DECIMAL(11,7),
        wind_u DECIMAL(6,2),
        wind_v DECIMAL(6,2),
        wind_speed DECIMAL(5,1),
        wind_dir SMALLINT
    );

    -- Sample 1: Current position (or departure)
    DECLARE @w_speed DECIMAL(5,1), @w_dir SMALLINT, @w_u DECIMAL(6,2), @w_v DECIMAL(6,2);

    EXEC dbo.sp_GetWindAtPoint @curr_lat, @curr_lon, @altitude_ft, NULL,
        @w_speed OUTPUT, @w_dir OUTPUT, @w_u OUTPUT, @w_v OUTPUT;

    IF @w_speed IS NOT NULL
        INSERT INTO @samples VALUES (1, @curr_lat, @curr_lon, @w_u, @w_v, @w_speed, @w_dir);

    -- Sample 2: Midpoint
    DECLARE @mid_lat DECIMAL(10,7) = (@curr_lat + @dest_lat) / 2;
    DECLARE @mid_lon DECIMAL(11,7) = (@curr_lon + @dest_lon) / 2;

    EXEC dbo.sp_GetWindAtPoint @mid_lat, @mid_lon, @altitude_ft, NULL,
        @w_speed OUTPUT, @w_dir OUTPUT, @w_u OUTPUT, @w_v OUTPUT;

    IF @w_speed IS NOT NULL
        INSERT INTO @samples VALUES (2, @mid_lat, @mid_lon, @w_u, @w_v, @w_speed, @w_dir);

    -- Sample 3: Near destination (80% of way)
    DECLARE @near_dest_lat DECIMAL(10,7) = @curr_lat + (@dest_lat - @curr_lat) * 0.8;
    DECLARE @near_dest_lon DECIMAL(11,7) = @curr_lon + (@dest_lon - @curr_lon) * 0.8;

    EXEC dbo.sp_GetWindAtPoint @near_dest_lat, @near_dest_lon, @altitude_ft, NULL,
        @w_speed OUTPUT, @w_dir OUTPUT, @w_u OUTPUT, @w_v OUTPUT;

    IF @w_speed IS NOT NULL
        INSERT INTO @samples VALUES (3, @near_dest_lat, @near_dest_lon, @w_u, @w_v, @w_speed, @w_dir);

    -- Calculate average wind component along track
    DECLARE @sample_count INT;
    SELECT @sample_count = COUNT(*) FROM @samples;

    IF @sample_count = 0
    BEGIN
        IF @debug = 1 PRINT 'No wind samples found';
        RETURN;
    END

    -- For each sample, calculate component along track
    DECLARE @total_component DECIMAL(8,2) = 0;

    SELECT @total_component = SUM(
        -- Wind component = wind_speed * cos(wind_dir - track - 180)
        -- Positive when wind is helping (tailwind)
        wind_speed * COS(RADIANS((wind_dir - @track_deg + 180 + 360) % 360))
    )
    FROM @samples;

    SET @wind_component_kts = @total_component / @sample_count;

    -- Confidence based on sample coverage
    SET @wind_confidence = CAST(@sample_count AS DECIMAL) / 3.0;

    IF @debug = 1
    BEGIN
        PRINT 'Wind samples: ' + CAST(@sample_count AS VARCHAR);
        PRINT 'Average wind component: ' + CAST(@wind_component_kts AS VARCHAR) + ' kts';
        PRINT 'Wind confidence: ' + CAST(@wind_confidence AS VARCHAR);

        SELECT sample_id, lat, lon, wind_speed, wind_dir,
               wind_speed * COS(RADIANS((wind_dir - @track_deg + 180 + 360) % 360)) AS component
        FROM @samples;
    END
END
GO

PRINT 'Created procedure dbo.sp_GetFlightWindAdjustment';
GO
