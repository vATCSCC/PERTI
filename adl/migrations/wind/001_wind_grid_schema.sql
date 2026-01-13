-- ============================================================================
-- Wind Grid Schema - Upper Level Wind Forecast Data
--
-- Stores GFS wind forecast data from Open-Meteo API for ETA calculations
-- Data refreshed every 6 hours (matches GFS model runs)
--
-- Part of ETA Accuracy Improvement Initiative
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Wind Grid Schema Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. wind_grid - Current wind data at grid points
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_grid') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_grid (
        grid_id             INT IDENTITY(1,1) NOT NULL,

        -- Grid location (5-degree resolution for global coverage)
        lat                 DECIMAL(5,2) NOT NULL,       -- -90 to 90
        lon                 DECIMAL(6,2) NOT NULL,       -- -180 to 180

        -- Pressure level (altitude)
        pressure_hpa        INT NOT NULL,                -- 200, 250, 300, etc.

        -- Wind data
        wind_speed_kts      DECIMAL(5,1) NOT NULL,       -- Wind speed in knots
        wind_dir_deg        SMALLINT NOT NULL,           -- Wind direction (0-360)

        -- U/V components for easier interpolation
        wind_u_kts          DECIMAL(6,2) NOT NULL,       -- East-west component (+E/-W)
        wind_v_kts          DECIMAL(6,2) NOT NULL,       -- North-south component (+N/-S)

        -- Forecast metadata
        forecast_hour       INT NOT NULL DEFAULT 0,      -- Hours from model run (0, 3, 6, 12, etc.)
        model_run_utc       DATETIME2(0) NOT NULL,       -- When model was run
        valid_time_utc      DATETIME2(0) NOT NULL,       -- When forecast is valid

        -- Update tracking
        fetched_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT PK_wind_grid PRIMARY KEY CLUSTERED (grid_id),
        CONSTRAINT UQ_wind_grid_point UNIQUE (lat, lon, pressure_hpa, valid_time_utc)
    );

    -- Index for spatial lookups
    CREATE NONCLUSTERED INDEX IX_wind_grid_location
        ON dbo.wind_grid (lat, lon, pressure_hpa, valid_time_utc)
        INCLUDE (wind_u_kts, wind_v_kts, wind_speed_kts, wind_dir_deg);

    -- Index for cleanup of old data
    CREATE NONCLUSTERED INDEX IX_wind_grid_valid_time
        ON dbo.wind_grid (valid_time_utc);

    PRINT 'Created table dbo.wind_grid';
END
ELSE
BEGIN
    PRINT 'Table dbo.wind_grid already exists - skipping';
END
GO

-- ============================================================================
-- 2. wind_grid_regions - Define regions to fetch wind data for
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_grid_regions') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_grid_regions (
        region_id           INT IDENTITY(1,1) NOT NULL,
        region_name         NVARCHAR(64) NOT NULL,

        -- Bounding box
        lat_min             DECIMAL(5,2) NOT NULL,
        lat_max             DECIMAL(5,2) NOT NULL,
        lon_min             DECIMAL(6,2) NOT NULL,
        lon_max             DECIMAL(6,2) NOT NULL,

        -- Grid resolution (degrees)
        grid_step           DECIMAL(3,1) NOT NULL DEFAULT 5.0,

        -- Active flag
        is_active           BIT NOT NULL DEFAULT 1,
        priority            INT NOT NULL DEFAULT 100,

        CONSTRAINT PK_wind_grid_regions PRIMARY KEY CLUSTERED (region_id)
    );

    PRINT 'Created table dbo.wind_grid_regions';

    -- Insert default regions (CONUS + oceanic)
    INSERT INTO dbo.wind_grid_regions (region_name, lat_min, lat_max, lon_min, lon_max, grid_step, priority)
    VALUES
        ('CONUS', 25.0, 50.0, -125.0, -65.0, 5.0, 1),           -- Continental US
        ('North Atlantic', 30.0, 60.0, -60.0, 0.0, 5.0, 2),     -- NAT tracks
        ('Europe', 35.0, 60.0, -10.0, 30.0, 5.0, 3),            -- Europe
        ('Pacific', 20.0, 50.0, -180.0, -120.0, 5.0, 4);        -- Pacific

    PRINT 'Inserted default wind grid regions';
END
ELSE
BEGIN
    PRINT 'Table dbo.wind_grid_regions already exists - skipping';
END
GO

-- ============================================================================
-- 3. wind_fetch_log - Track API fetch history
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_fetch_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_fetch_log (
        fetch_id            INT IDENTITY(1,1) NOT NULL,
        region_id           INT NULL,

        -- Fetch details
        fetch_start_utc     DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        fetch_end_utc       DATETIME2(0) NULL,

        -- Results
        points_fetched      INT NULL,
        points_inserted     INT NULL,
        api_calls           INT NULL,

        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'RUNNING',  -- RUNNING, SUCCESS, FAILED
        error_message       NVARCHAR(512) NULL,

        CONSTRAINT PK_wind_fetch_log PRIMARY KEY CLUSTERED (fetch_id)
    );

    PRINT 'Created table dbo.wind_fetch_log';
END
ELSE
BEGIN
    PRINT 'Table dbo.wind_fetch_log already exists - skipping';
END
GO

-- ============================================================================
-- 4. Function to calculate wind component along track
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_WindComponent') AND type = 'FN')
    DROP FUNCTION dbo.fn_WindComponent;
GO

CREATE FUNCTION dbo.fn_WindComponent(
    @wind_speed_kts DECIMAL(5,1),
    @wind_dir_deg   SMALLINT,
    @track_deg      SMALLINT
)
RETURNS DECIMAL(6,2)
AS
BEGIN
    -- Calculate headwind/tailwind component
    -- Positive = tailwind (faster), Negative = headwind (slower)
    DECLARE @angle_diff DECIMAL(5,2);
    DECLARE @component DECIMAL(6,2);

    -- Calculate angle between wind direction and track
    -- Wind FROM direction, track TO direction
    SET @angle_diff = (@wind_dir_deg - @track_deg + 180) % 360;
    IF @angle_diff < 0 SET @angle_diff = @angle_diff + 360;

    -- Component along track (cosine of angle difference)
    -- Wind FROM 270, Track TO 90 (eastbound) = tailwind
    SET @component = @wind_speed_kts * COS(RADIANS(@angle_diff));

    RETURN @component;
END
GO

PRINT 'Created function dbo.fn_WindComponent';
GO

-- ============================================================================
-- 5. Procedure to get interpolated wind at a point
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_GetWindAtPoint') AND type = 'P')
    DROP PROCEDURE dbo.sp_GetWindAtPoint;
GO

CREATE PROCEDURE dbo.sp_GetWindAtPoint
    @lat            DECIMAL(10,7),
    @lon            DECIMAL(11,7),
    @altitude_ft    INT,
    @valid_time     DATETIME2(0) = NULL,
    @wind_speed_kts DECIMAL(5,1) OUTPUT,
    @wind_dir_deg   SMALLINT OUTPUT,
    @wind_u_kts     DECIMAL(6,2) OUTPUT,
    @wind_v_kts     DECIMAL(6,2) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    -- Default to current time
    IF @valid_time IS NULL
        SET @valid_time = SYSUTCDATETIME();

    -- Determine pressure level from altitude
    -- Standard atmosphere approximations
    DECLARE @pressure_hpa INT;
    SET @pressure_hpa = CASE
        WHEN @altitude_ft >= 38000 THEN 200   -- FL380+
        WHEN @altitude_ft >= 32000 THEN 250   -- FL320-FL380
        WHEN @altitude_ft >= 26000 THEN 300   -- FL260-FL320
        WHEN @altitude_ft >= 20000 THEN 400   -- FL200-FL260
        ELSE 500                               -- Below FL200
    END;

    -- Find nearest grid points and interpolate
    -- Using bilinear interpolation
    DECLARE @lat_lo DECIMAL(5,2) = FLOOR(@lat / 5.0) * 5.0;
    DECLARE @lat_hi DECIMAL(5,2) = @lat_lo + 5.0;
    DECLARE @lon_lo DECIMAL(6,2) = FLOOR(@lon / 5.0) * 5.0;
    DECLARE @lon_hi DECIMAL(6,2) = @lon_lo + 5.0;

    -- Get 4 corner wind values
    DECLARE @u00 DECIMAL(6,2), @v00 DECIMAL(6,2);
    DECLARE @u01 DECIMAL(6,2), @v01 DECIMAL(6,2);
    DECLARE @u10 DECIMAL(6,2), @v10 DECIMAL(6,2);
    DECLARE @u11 DECIMAL(6,2), @v11 DECIMAL(6,2);

    -- Get closest valid time
    DECLARE @closest_valid DATETIME2(0);
    SELECT TOP 1 @closest_valid = valid_time_utc
    FROM dbo.wind_grid
    WHERE pressure_hpa = @pressure_hpa
      AND ABS(DATEDIFF(HOUR, valid_time_utc, @valid_time)) <= 6
    ORDER BY ABS(DATEDIFF(MINUTE, valid_time_utc, @valid_time));

    -- If no wind data, return NULLs
    IF @closest_valid IS NULL
    BEGIN
        SET @wind_speed_kts = NULL;
        SET @wind_dir_deg = NULL;
        SET @wind_u_kts = NULL;
        SET @wind_v_kts = NULL;
        RETURN;
    END

    -- Get corner values
    SELECT @u00 = wind_u_kts, @v00 = wind_v_kts
    FROM dbo.wind_grid
    WHERE lat = @lat_lo AND lon = @lon_lo AND pressure_hpa = @pressure_hpa AND valid_time_utc = @closest_valid;

    SELECT @u01 = wind_u_kts, @v01 = wind_v_kts
    FROM dbo.wind_grid
    WHERE lat = @lat_lo AND lon = @lon_hi AND pressure_hpa = @pressure_hpa AND valid_time_utc = @closest_valid;

    SELECT @u10 = wind_u_kts, @v10 = wind_v_kts
    FROM dbo.wind_grid
    WHERE lat = @lat_hi AND lon = @lon_lo AND pressure_hpa = @pressure_hpa AND valid_time_utc = @closest_valid;

    SELECT @u11 = wind_u_kts, @v11 = wind_v_kts
    FROM dbo.wind_grid
    WHERE lat = @lat_hi AND lon = @lon_hi AND pressure_hpa = @pressure_hpa AND valid_time_utc = @closest_valid;

    -- If missing corners, use nearest available
    IF @u00 IS NULL OR @u01 IS NULL OR @u10 IS NULL OR @u11 IS NULL
    BEGIN
        SELECT TOP 1
            @wind_u_kts = wind_u_kts,
            @wind_v_kts = wind_v_kts,
            @wind_speed_kts = wind_speed_kts,
            @wind_dir_deg = wind_dir_deg
        FROM dbo.wind_grid
        WHERE pressure_hpa = @pressure_hpa
          AND valid_time_utc = @closest_valid
        ORDER BY ABS(lat - @lat) + ABS(lon - @lon);
        RETURN;
    END

    -- Bilinear interpolation weights
    DECLARE @x_weight DECIMAL(5,4) = (@lon - @lon_lo) / 5.0;
    DECLARE @y_weight DECIMAL(5,4) = (@lat - @lat_lo) / 5.0;

    -- Interpolate U component
    DECLARE @u_lo DECIMAL(6,2) = @u00 * (1 - @x_weight) + @u01 * @x_weight;
    DECLARE @u_hi DECIMAL(6,2) = @u10 * (1 - @x_weight) + @u11 * @x_weight;
    SET @wind_u_kts = @u_lo * (1 - @y_weight) + @u_hi * @y_weight;

    -- Interpolate V component
    DECLARE @v_lo DECIMAL(6,2) = @v00 * (1 - @x_weight) + @v01 * @x_weight;
    DECLARE @v_hi DECIMAL(6,2) = @v10 * (1 - @x_weight) + @v11 * @x_weight;
    SET @wind_v_kts = @v_lo * (1 - @y_weight) + @v_hi * @y_weight;

    -- Convert U/V to speed and direction
    SET @wind_speed_kts = SQRT(@wind_u_kts * @wind_u_kts + @wind_v_kts * @wind_v_kts);
    SET @wind_dir_deg = CAST((CAST(DEGREES(ATN2(-@wind_u_kts, -@wind_v_kts)) AS INT) + 360) % 360 AS SMALLINT);
END
GO

PRINT 'Created procedure dbo.sp_GetWindAtPoint';
GO

-- ============================================================================
-- 6. Procedure to cleanup old wind data
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_WindGridCleanup') AND type = 'P')
    DROP PROCEDURE dbo.sp_WindGridCleanup;
GO

CREATE PROCEDURE dbo.sp_WindGridCleanup
    @hours_to_keep INT = 48
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff DATETIME2(0) = DATEADD(HOUR, -@hours_to_keep, SYSUTCDATETIME());
    DECLARE @deleted INT;

    DELETE FROM dbo.wind_grid
    WHERE valid_time_utc < @cutoff;

    SET @deleted = @@ROWCOUNT;

    PRINT 'Deleted ' + CAST(@deleted AS VARCHAR) + ' old wind grid records';
END
GO

PRINT 'Created procedure dbo.sp_WindGridCleanup';
GO

PRINT '';
PRINT '=== Wind Grid Schema Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
