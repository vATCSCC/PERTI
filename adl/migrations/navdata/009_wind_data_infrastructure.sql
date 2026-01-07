-- ============================================================================
-- 009_wind_data_infrastructure.sql
-- Wind Data Integration - Phase 1: Database Infrastructure
--
-- Creates tables and functions for storing NOAA GFS/RAP wind data
-- and calculating headwind/tailwind components for ETA improvement.
--
-- Date: 2026-01-07
-- ============================================================================

SET NOCOUNT ON;
PRINT '=== Wind Data Infrastructure Migration ===';
PRINT 'Started: ' + CONVERT(VARCHAR, GETDATE(), 120);

-- ============================================================================
-- Table 1: wx_wind_grid - Stores wind data at grid points
-- ============================================================================

IF OBJECT_ID('dbo.wx_wind_grid', 'U') IS NULL
BEGIN
    PRINT 'Creating wx_wind_grid table...';
    
    CREATE TABLE dbo.wx_wind_grid (
        grid_id             INT IDENTITY(1,1) NOT NULL,
        source              CHAR(3) NOT NULL,           -- 'RAP' or 'GFS'
        valid_time_utc      DATETIME2(0) NOT NULL,      -- When wind data is valid
        forecast_hour       TINYINT NOT NULL,           -- 0=analysis, 3, 6
        pressure_mb         SMALLINT NOT NULL,          -- 200, 250, 300, 350, 400, 500
        lat                 DECIMAL(5,2) NOT NULL,      -- Grid point latitude (-90 to 90)
        lon                 DECIMAL(6,2) NOT NULL,      -- Grid point longitude (-180 to 180)
        u_wind_mps          SMALLINT NOT NULL,          -- U component (m/s * 10 for precision)
        v_wind_mps          SMALLINT NOT NULL,          -- V component (m/s * 10 for precision)
        wind_speed_kts      SMALLINT NOT NULL,          -- Pre-computed wind speed (knots)
        wind_dir_deg        SMALLINT NOT NULL,          -- Pre-computed wind direction (0-359°)
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_wx_wind_grid PRIMARY KEY CLUSTERED (grid_id),
        CONSTRAINT CK_wx_wind_grid_source CHECK (source IN ('RAP', 'GFS')),
        CONSTRAINT CK_wx_wind_grid_pressure CHECK (pressure_mb IN (200, 250, 300, 350, 400, 500)),
        CONSTRAINT CK_wx_wind_grid_forecast CHECK (forecast_hour IN (0, 3, 6)),
        CONSTRAINT CK_wx_wind_grid_lat CHECK (lat BETWEEN -90 AND 90),
        CONSTRAINT CK_wx_wind_grid_lon CHECK (lon BETWEEN -180 AND 180),
        CONSTRAINT CK_wx_wind_grid_dir CHECK (wind_dir_deg BETWEEN 0 AND 359)
    );
    
    -- Primary lookup index: source + time + level + position
    CREATE NONCLUSTERED INDEX IX_wx_wind_grid_lookup 
        ON dbo.wx_wind_grid (source, valid_time_utc, pressure_mb, lat, lon)
        INCLUDE (u_wind_mps, v_wind_mps, wind_speed_kts, wind_dir_deg);
    
    -- Spatial lookup index for finding nearby grid points
    CREATE NONCLUSTERED INDEX IX_wx_wind_grid_spatial 
        ON dbo.wx_wind_grid (lat, lon, pressure_mb, valid_time_utc)
        INCLUDE (source, u_wind_mps, v_wind_mps, wind_speed_kts, wind_dir_deg);
    
    -- Cleanup index: for purging old data
    CREATE NONCLUSTERED INDEX IX_wx_wind_grid_cleanup 
        ON dbo.wx_wind_grid (source, valid_time_utc);
    
    PRINT '  wx_wind_grid table created with indexes';
END
ELSE
BEGIN
    PRINT '  wx_wind_grid table already exists';
END
GO

-- ============================================================================
-- Table 2: wx_wind_import_log - Tracks import history
-- ============================================================================

IF OBJECT_ID('dbo.wx_wind_import_log', 'U') IS NULL
BEGIN
    PRINT 'Creating wx_wind_import_log table...';
    
    CREATE TABLE dbo.wx_wind_import_log (
        import_id           INT IDENTITY(1,1) NOT NULL,
        source              CHAR(3) NOT NULL,           -- 'RAP' or 'GFS'
        cycle_time_utc      DATETIME2(0) NOT NULL,      -- Model cycle (e.g., 12Z)
        forecast_hour       TINYINT NOT NULL,           -- 0, 3, 6
        grid_points         INT NULL,                   -- Number of points imported
        file_size_kb        INT NULL,                   -- Downloaded file size
        download_ms         INT NULL,                   -- Download duration
        parse_ms            INT NULL,                   -- Parse duration
        import_ms           INT NULL,                   -- DB import duration
        import_started_utc  DATETIME2(3) NOT NULL,
        import_completed_utc DATETIME2(3) NULL,
        status              VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
        error_message       NVARCHAR(500) NULL,
        
        CONSTRAINT PK_wx_wind_import_log PRIMARY KEY CLUSTERED (import_id),
        CONSTRAINT CK_wx_wind_import_status CHECK (status IN ('RUNNING', 'SUCCESS', 'FAILED', 'PARTIAL'))
    );
    
    CREATE NONCLUSTERED INDEX IX_wx_wind_import_cycle 
        ON dbo.wx_wind_import_log (source, cycle_time_utc, forecast_hour);
    
    CREATE NONCLUSTERED INDEX IX_wx_wind_import_status 
        ON dbo.wx_wind_import_log (status, import_started_utc);
    
    PRINT '  wx_wind_import_log table created';
END
ELSE
BEGIN
    PRINT '  wx_wind_import_log table already exists';
END
GO

-- ============================================================================
-- Table 3: Extend adl_flight_times with wind columns
-- ============================================================================

PRINT 'Checking adl_flight_times wind columns...';

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_source')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_source VARCHAR(10) NULL;
    PRINT '  Added wind_source column';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_valid_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_valid_utc DATETIME2(0) NULL;
    PRINT '  Added wind_valid_utc column';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_head_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_head_kts SMALLINT NULL;
    PRINT '  Added wind_head_kts column (positive = headwind)';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_cross_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_cross_kts SMALLINT NULL;
    PRINT '  Added wind_cross_kts column (positive = from right)';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_speed_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_speed_kts SMALLINT NULL;
    PRINT '  Added wind_speed_kts column';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_dir_deg')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_dir_deg SMALLINT NULL;
    PRINT '  Added wind_dir_deg column';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'wind_calc_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD wind_calc_utc DATETIME2(0) NULL;
    PRINT '  Added wind_calc_utc column';
END
GO

-- ============================================================================
-- Function 1: fn_AltitudeToPressure - Convert flight level to pressure
-- ============================================================================

IF OBJECT_ID('dbo.fn_AltitudeToPressure', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_AltitudeToPressure;
GO

CREATE FUNCTION dbo.fn_AltitudeToPressure(
    @altitude_ft INT
)
RETURNS SMALLINT
AS
BEGIN
    -- Map altitude to nearest available pressure level
    -- Using standard atmosphere approximations:
    -- FL450 ≈ 150mb, FL390 ≈ 200mb, FL340 ≈ 250mb, FL300 ≈ 300mb
    -- FL260 ≈ 350mb, FL240 ≈ 400mb, FL180 ≈ 500mb
    
    RETURN CASE 
        WHEN @altitude_ft >= 39000 THEN 200  -- FL390+
        WHEN @altitude_ft >= 32000 THEN 250  -- FL320-FL390
        WHEN @altitude_ft >= 28000 THEN 300  -- FL280-FL320
        WHEN @altitude_ft >= 25000 THEN 350  -- FL250-FL280
        WHEN @altitude_ft >= 22000 THEN 400  -- FL220-FL250
        ELSE 500                              -- Below FL220
    END;
END;
GO

PRINT 'Created fn_AltitudeToPressure function';
GO

-- ============================================================================
-- Function 2: fn_GetHeadwindComponent - Calculate headwind from wind vector
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetHeadwindComponent', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetHeadwindComponent;
GO

CREATE FUNCTION dbo.fn_GetHeadwindComponent(
    @wind_dir_deg INT,      -- Direction wind is FROM (0-359)
    @wind_speed_kts INT,    -- Wind speed in knots
    @track_deg INT          -- Aircraft track/heading (0-359)
)
RETURNS INT
AS
BEGIN
    -- Calculate headwind component (positive = headwind, negative = tailwind)
    -- Wind direction is where wind comes FROM
    -- To get headwind, we need the component of wind opposing the track
    
    DECLARE @relative_angle_rad DECIMAL(9,6);
    
    -- Angle between wind direction and track
    -- Add 180 to get direction wind is GOING TO, then subtract track
    SET @relative_angle_rad = RADIANS(
        ((@wind_dir_deg + 180) % 360) - @track_deg
    );
    
    -- Headwind component = wind speed * cos(relative angle)
    -- Positive when wind opposes motion (headwind)
    RETURN CAST(-1 * @wind_speed_kts * COS(@relative_angle_rad) AS INT);
END;
GO

PRINT 'Created fn_GetHeadwindComponent function';
GO

-- ============================================================================
-- Function 3: fn_GetCrosswindComponent - Calculate crosswind from wind vector
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetCrosswindComponent', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetCrosswindComponent;
GO

CREATE FUNCTION dbo.fn_GetCrosswindComponent(
    @wind_dir_deg INT,      -- Direction wind is FROM (0-359)
    @wind_speed_kts INT,    -- Wind speed in knots
    @track_deg INT          -- Aircraft track/heading (0-359)
)
RETURNS INT
AS
BEGIN
    -- Calculate crosswind component (positive = from right, negative = from left)
    
    DECLARE @relative_angle_rad DECIMAL(9,6);
    
    SET @relative_angle_rad = RADIANS(
        ((@wind_dir_deg + 180) % 360) - @track_deg
    );
    
    -- Crosswind component = wind speed * sin(relative angle)
    RETURN CAST(-1 * @wind_speed_kts * SIN(@relative_angle_rad) AS INT);
END;
GO

PRINT 'Created fn_GetCrosswindComponent function';
GO

-- ============================================================================
-- Function 4: fn_GetLatestWindTime - Get most recent valid wind data time
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetLatestWindTime', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetLatestWindTime;
GO

CREATE FUNCTION dbo.fn_GetLatestWindTime(
    @source CHAR(3) = 'RAP'
)
RETURNS DATETIME2(0)
AS
BEGIN
    RETURN (
        SELECT MAX(valid_time_utc) 
        FROM dbo.wx_wind_grid 
        WHERE source = @source 
          AND forecast_hour = 0
    );
END;
GO

PRINT 'Created fn_GetLatestWindTime function';
GO

-- ============================================================================
-- TVF 1: fn_GetWindAtPosition - Get interpolated wind at a position
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetWindAtPosition', 'IF') IS NOT NULL
    DROP FUNCTION dbo.fn_GetWindAtPosition;
GO

CREATE FUNCTION dbo.fn_GetWindAtPosition(
    @lat DECIMAL(9,6),
    @lon DECIMAL(9,6),
    @altitude_ft INT,
    @valid_time DATETIME2(0) = NULL
)
RETURNS TABLE
AS RETURN
(
    WITH TargetParams AS (
        SELECT 
            dbo.fn_AltitudeToPressure(@altitude_ft) AS pressure_mb,
            ISNULL(@valid_time, dbo.fn_GetLatestWindTime('RAP')) AS valid_time,
            FLOOR(@lat) AS lat_floor,
            CEILING(@lat) AS lat_ceil,
            FLOOR(@lon) AS lon_floor,
            CEILING(@lon) AS lon_ceil
    ),
    GridPoints AS (
        -- Get the 4 surrounding grid points for bilinear interpolation
        SELECT 
            g.lat,
            g.lon,
            g.u_wind_mps,
            g.v_wind_mps,
            g.wind_speed_kts,
            g.wind_dir_deg,
            -- Bilinear interpolation weights
            (1.0 - ABS(g.lat - @lat)) * (1.0 - ABS(g.lon - @lon)) AS weight
        FROM dbo.wx_wind_grid g
        CROSS JOIN TargetParams t
        WHERE g.source = 'RAP'
          AND g.forecast_hour = 0
          AND g.pressure_mb = t.pressure_mb
          AND g.valid_time_utc = t.valid_time
          AND g.lat IN (t.lat_floor, t.lat_ceil)
          AND g.lon IN (t.lon_floor, t.lon_ceil)
    )
    SELECT 
        -- Weighted average of u/v components, convert to knots
        CAST(SUM(g.u_wind_mps * g.weight) / NULLIF(SUM(g.weight), 0) / 10.0 * 1.944 AS INT) AS u_kts,
        CAST(SUM(g.v_wind_mps * g.weight) / NULLIF(SUM(g.weight), 0) / 10.0 * 1.944 AS INT) AS v_kts,
        -- Weighted average wind speed
        CAST(SUM(g.wind_speed_kts * g.weight) / NULLIF(SUM(g.weight), 0) AS INT) AS wind_speed_kts,
        -- Weighted average direction (simplified - works for small variations)
        CAST(SUM(g.wind_dir_deg * g.weight) / NULLIF(SUM(g.weight), 0) AS INT) % 360 AS wind_dir_deg,
        -- Metadata
        (SELECT pressure_mb FROM TargetParams) AS pressure_mb,
        (SELECT valid_time FROM TargetParams) AS valid_time_utc,
        COUNT(*) AS grid_points_used
    FROM GridPoints g
);
GO

PRINT 'Created fn_GetWindAtPosition table-valued function';
GO

-- ============================================================================
-- Procedure 1: sp_PurgeOldWindData - Cleanup old wind grid data
-- ============================================================================

IF OBJECT_ID('dbo.sp_PurgeOldWindData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_PurgeOldWindData;
GO

CREATE PROCEDURE dbo.sp_PurgeOldWindData
    @keep_hours INT = 6,        -- Keep data from last N hours
    @purged_count INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @cutoff DATETIME2(0) = DATEADD(HOUR, -@keep_hours, SYSUTCDATETIME());
    
    DELETE FROM dbo.wx_wind_grid
    WHERE valid_time_utc < @cutoff;
    
    SET @purged_count = @@ROWCOUNT;
    
    IF @purged_count > 0
        PRINT 'Purged ' + CAST(@purged_count AS VARCHAR) + ' old wind grid rows';
END;
GO

PRINT 'Created sp_PurgeOldWindData procedure';
GO

-- ============================================================================
-- Procedure 2: sp_InsertWindGridBatch - Bulk insert wind data
-- ============================================================================

IF OBJECT_ID('dbo.sp_InsertWindGridBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_InsertWindGridBatch;
GO

CREATE PROCEDURE dbo.sp_InsertWindGridBatch
    @source CHAR(3),
    @valid_time_utc DATETIME2(0),
    @forecast_hour TINYINT,
    @wind_data dbo.WindGridType READONLY,  -- TVP defined below
    @inserted_count INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Delete existing data for this source/time/forecast to allow re-import
    DELETE FROM dbo.wx_wind_grid
    WHERE source = @source
      AND valid_time_utc = @valid_time_utc
      AND forecast_hour = @forecast_hour;
    
    -- Insert new data
    INSERT INTO dbo.wx_wind_grid (
        source, valid_time_utc, forecast_hour, pressure_mb,
        lat, lon, u_wind_mps, v_wind_mps, wind_speed_kts, wind_dir_deg
    )
    SELECT 
        @source,
        @valid_time_utc,
        @forecast_hour,
        pressure_mb,
        lat,
        lon,
        u_wind_mps,
        v_wind_mps,
        -- Pre-compute wind speed: sqrt(u² + v²) * 0.1944 (convert m/s*10 to kts)
        CAST(SQRT(POWER(u_wind_mps / 10.0, 2) + POWER(v_wind_mps / 10.0, 2)) * 1.944 AS SMALLINT),
        -- Pre-compute wind direction: atan2(-u, -v) converted to degrees
        -- Note: Meteorological convention is direction wind comes FROM
        CAST((DEGREES(ATN2(-1.0 * u_wind_mps, -1.0 * v_wind_mps)) + 360) % 360 AS SMALLINT)
    FROM @wind_data;
    
    SET @inserted_count = @@ROWCOUNT;
END;
GO

-- Create the table type for batch inserts
IF TYPE_ID('dbo.WindGridType') IS NOT NULL
    DROP TYPE dbo.WindGridType;
GO

CREATE TYPE dbo.WindGridType AS TABLE (
    pressure_mb     SMALLINT NOT NULL,
    lat             DECIMAL(5,2) NOT NULL,
    lon             DECIMAL(6,2) NOT NULL,
    u_wind_mps      SMALLINT NOT NULL,
    v_wind_mps      SMALLINT NOT NULL
);
GO

PRINT 'Created sp_InsertWindGridBatch procedure and WindGridType';
GO

-- ============================================================================
-- View 1: vw_wind_data_status - Current wind data status
-- ============================================================================

IF OBJECT_ID('dbo.vw_wind_data_status', 'V') IS NOT NULL
    DROP VIEW dbo.vw_wind_data_status;
GO

CREATE VIEW dbo.vw_wind_data_status
AS
SELECT 
    source,
    valid_time_utc,
    forecast_hour,
    COUNT(DISTINCT pressure_mb) AS pressure_levels,
    COUNT(*) AS grid_points,
    MIN(lat) AS min_lat,
    MAX(lat) AS max_lat,
    MIN(lon) AS min_lon,
    MAX(lon) AS max_lon,
    AVG(wind_speed_kts) AS avg_wind_kts,
    MAX(wind_speed_kts) AS max_wind_kts,
    DATEDIFF(MINUTE, valid_time_utc, SYSUTCDATETIME()) AS age_minutes,
    MIN(created_utc) AS imported_utc
FROM dbo.wx_wind_grid
GROUP BY source, valid_time_utc, forecast_hour;
GO

PRINT 'Created vw_wind_data_status view';
GO

-- ============================================================================
-- Test the headwind function
-- ============================================================================

PRINT '';
PRINT 'Testing headwind calculation function...';
PRINT '  Track 360° (North), Wind FROM 360° (North) @ 50kts:';
PRINT '    Headwind = ' + CAST(dbo.fn_GetHeadwindComponent(360, 50, 360) AS VARCHAR) + ' kts (expected: +50)';
PRINT '  Track 360° (North), Wind FROM 180° (South) @ 50kts:';
PRINT '    Headwind = ' + CAST(dbo.fn_GetHeadwindComponent(180, 50, 360) AS VARCHAR) + ' kts (expected: -50 tailwind)';
PRINT '  Track 90° (East), Wind FROM 90° (East) @ 50kts:';
PRINT '    Headwind = ' + CAST(dbo.fn_GetHeadwindComponent(90, 50, 90) AS VARCHAR) + ' kts (expected: +50)';
PRINT '  Track 90° (East), Wind FROM 360° (North) @ 50kts:';
PRINT '    Crosswind = ' + CAST(dbo.fn_GetCrosswindComponent(360, 50, 90) AS VARCHAR) + ' kts (expected: ~50 from left)';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '=== Wind Data Infrastructure Migration Complete ===';
PRINT '';
PRINT 'Tables created:';
PRINT '  - wx_wind_grid (wind data storage)';
PRINT '  - wx_wind_import_log (import tracking)';
PRINT '';
PRINT 'Columns added to adl_flight_times:';
PRINT '  - wind_source, wind_valid_utc';
PRINT '  - wind_head_kts, wind_cross_kts';
PRINT '  - wind_speed_kts, wind_dir_deg, wind_calc_utc';
PRINT '';
PRINT 'Functions created:';
PRINT '  - fn_AltitudeToPressure (altitude to pressure level)';
PRINT '  - fn_GetHeadwindComponent (wind vector to headwind)';
PRINT '  - fn_GetCrosswindComponent (wind vector to crosswind)';
PRINT '  - fn_GetLatestWindTime (most recent wind data time)';
PRINT '  - fn_GetWindAtPosition (interpolated wind lookup)';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_PurgeOldWindData (cleanup old data)';
PRINT '  - sp_InsertWindGridBatch (bulk import)';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_wind_data_status (data status summary)';
PRINT '';
PRINT 'Completed: ' + CONVERT(VARCHAR, GETDATE(), 120);
GO
