-- ============================================================================
-- Wind Tiered Resolution Migration
--
-- Implements a tiered approach to wind data storage:
-- - Higher resolution (0.25°) near major airports and domestic airspace
-- - Medium resolution (0.5°) for international enroute
-- - Lower resolution (1.0°) for remote/oceanic areas
--
-- Uses a precomputed grid-tier lookup table for efficient fetching.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Wind Tiered Resolution Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Add tier column to wind_grid if not exists
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.wind_grid') AND name = 'tier')
BEGIN
    ALTER TABLE dbo.wind_grid ADD tier TINYINT NULL;
    PRINT 'Added tier column to wind_grid';
END
GO

-- ============================================================================
-- 2. Create tier configuration table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_tier_config') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_tier_config (
        tier            TINYINT PRIMARY KEY,
        tier_name       VARCHAR(50) NOT NULL,
        resolution_deg  DECIMAL(4,2) NOT NULL,
        min_altitude_ft INT NOT NULL,          -- 0 = all levels
        description     VARCHAR(200) NULL
    );

    -- Tier definitions
    INSERT INTO dbo.wind_tier_config (tier, tier_name, resolution_deg, min_altitude_ft, description)
    VALUES
        (0, 'DOMESTIC_AIRPORT', 0.25, 0,     'CONUS/CAN/MEX/CAR near major airports - all levels'),
        (1, 'DOMESTIC_ENROUTE', 0.25, 10000, 'CONUS/CAN/MEX/CAR enroute - AOA 10,000ft'),
        (2, 'NAT_WATRS',        0.25, 18000, 'North Atlantic/WATRS oceanic - AOA FL180'),
        (3, 'PACIFIC_OCEANIC',  0.25, 24000, 'Oakland Oceanic (ZAK) - AOA FL240'),
        (4, 'INTL_AIRPORT',     0.50, 0,     'International near major airports - all levels'),
        (5, 'EUROPE_ENROUTE',   0.50, 10000, 'Europe enroute - AOA 10,000ft'),
        (6, 'PACIFIC_REMOTE',   0.50, 18000, 'Remote Pacific oceanic - AOA FL180'),
        (7, 'INTL_ENROUTE',     0.50, 24000, 'South America/Africa/Middle East enroute - AOA FL240'),
        (8, 'REMOTE_AIRPORT',   1.00, 10000, 'Remote areas near airports - AOA 10,000ft'),
        (9, 'ASIA_ENROUTE',     1.00, 10000, 'Asia/Middle East enroute - AOA 10,000ft'),
        (10,'POLAR_OCEANIC',    1.00, 18000, 'Polar/remote oceanic - AOA FL180');

    PRINT 'Created wind_tier_config table with tier definitions';
END
GO

-- ============================================================================
-- 3. Create grid-tier lookup table (precomputed)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_grid_tier_lookup') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_grid_tier_lookup (
        lat             DECIMAL(6,2) NOT NULL,
        lon             DECIMAL(7,2) NOT NULL,
        tier            TINYINT NOT NULL,
        near_airport    BIT NOT NULL DEFAULT 0,
        region_code     VARCHAR(20) NULL,
        CONSTRAINT PK_wind_grid_tier_lookup PRIMARY KEY (lat, lon)
    );

    CREATE INDEX IX_wind_grid_tier_lookup_tier ON dbo.wind_grid_tier_lookup(tier);

    PRINT 'Created wind_grid_tier_lookup table';
END
GO

-- ============================================================================
-- 4. Create region definitions table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_regions') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_regions (
        region_code     VARCHAR(20) PRIMARY KEY,
        region_name     VARCHAR(100) NOT NULL,
        lat_min         DECIMAL(6,2) NOT NULL,
        lat_max         DECIMAL(6,2) NOT NULL,
        lon_min         DECIMAL(7,2) NOT NULL,
        lon_max         DECIMAL(7,2) NOT NULL,
        base_tier       TINYINT NOT NULL,      -- Tier when NOT near airport
        airport_tier    TINYINT NOT NULL,      -- Tier when near airport
        priority        TINYINT NOT NULL       -- Higher priority regions checked first
    );

    -- Region definitions (higher priority checked first for overlaps)
    INSERT INTO dbo.wind_regions (region_code, region_name, lat_min, lat_max, lon_min, lon_max, base_tier, airport_tier, priority)
    VALUES
        -- Domestic (highest priority)
        ('CONUS',       'Continental US',           24, 50, -125, -66,   1, 0, 10),
        ('ZAN',         'Anchorage ARTCC',          54, 72, -180, -130,  1, 0, 10),
        ('ZHN',         'Honolulu ARTCC',           15, 30, -180, -150,  1, 0, 10),
        ('CAN_S',       'Canada South',             49, 60, -140, -52,   1, 0, 10),
        ('MEX',         'Mexico',                   14, 32, -118, -86,   1, 0, 9),
        ('CAR',         'Caribbean',                10, 28, -90, -59,    1, 0, 9),

        -- Oceanic
        ('NAT',         'North Atlantic Tracks',    40, 65, -60, -10,    2, 2, 8),
        ('WATRS',       'West Atlantic Routes',     18, 40, -80, -55,    2, 2, 8),
        ('ZAK',         'Oakland Oceanic',          20, 55, -180, -130,  3, 3, 7),

        -- Europe
        ('EUR_W',       'Western Europe',           35, 60, -12, 20,     5, 4, 6),
        ('EUR_E',       'Eastern Europe',           35, 60, 20, 45,      5, 4, 6),

        -- Pacific
        ('PAC_W',       'Western Pacific',          -10, 50, 100, 150,   6, 4, 5),
        ('PAC_C',       'Central Pacific',          -10, 30, 150, 180,   6, 6, 5),

        -- Other international
        ('ASIA',        'Asia mainland',            10, 55, 60, 145,     9, 4, 4),
        ('MID_EAST',    'Middle East',              12, 42, 25, 65,      7, 4, 4),
        ('AFRICA',      'Africa',                   -35, 35, -20, 55,    7, 4, 3),
        ('S_AMERICA',   'South America',            -55, 15, -82, -32,   7, 4, 3),
        ('AUSTRALIA',   'Australia/Oceania',        -50, -10, 110, 180,  9, 4, 3),

        -- Remote/polar (lowest priority - catch-all)
        ('POLAR_N',     'Arctic',                   65, 90, -180, 180,   10, 10, 1),
        ('POLAR_S',     'Antarctic',                -90, -60, -180, 180, 10, 10, 1),
        ('REMOTE',      'Remote oceanic',           -60, 65, -180, 180,  10, 8, 0);

    PRINT 'Created wind_regions table with region definitions';
END
GO

-- ============================================================================
-- 5. Create major airports table for proximity checking
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.wind_major_airports') AND type = 'U')
BEGIN
    CREATE TABLE dbo.wind_major_airports (
        icao_id     CHAR(4) PRIMARY KEY,
        lat         DECIMAL(10,7) NOT NULL,
        lon         DECIMAL(11,7) NOT NULL,
        airport_name VARCHAR(100) NULL
    );

    -- Index for proximity lookups
    CREATE INDEX IX_wind_major_airports_lat ON dbo.wind_major_airports(lat);
    CREATE INDEX IX_wind_major_airports_lon ON dbo.wind_major_airports(lon);

    PRINT 'Created wind_major_airports table';
END
GO

-- ============================================================================
-- 6. Populate major airports from apts table (if exists)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.apts') AND type = 'U')
BEGIN
    -- Clear and repopulate
    TRUNCATE TABLE dbo.wind_major_airports;

    -- Insert major airports (Class B, C, and major international)
    -- Using airports with significant traffic
    INSERT INTO dbo.wind_major_airports (icao_id, lat, lon, airport_name)
    SELECT ICAO_ID, LAT_DECIMAL, LONG_DECIMAL, NULL
    FROM dbo.apts
    WHERE LAT_DECIMAL IS NOT NULL
      AND LONG_DECIMAL IS NOT NULL
      AND (
          -- US Class B airports
          ICAO_ID IN ('KATL','KBOS','KBWI','KCLT','KDCA','KDEN','KDFW','KDTW',
                      'KEWR','KFLL','KHNL','KHOU','KIAD','KIAH','KJFK','KLAS',
                      'KLAX','KLGA','KMCI','KMCO','KMDW','KMEM','KMIA','KMKE',
                      'KMSP','KORD','KPDX','KPHL','KPHX','KPIT','KSAN','KSEA',
                      'KSFO','KSLC','KSTL','KTPA','PANC','PHNL')
          -- Major Canadian
          OR ICAO_ID IN ('CYYZ','CYVR','CYUL','CYYC','CYOW','CYEG','CYWG','CYQB')
          -- Major Mexican
          OR ICAO_ID IN ('MMMX','MMUN','MMTJ','MMGL','MMMY')
          -- Major Caribbean
          OR ICAO_ID IN ('TNCM','TJSJ','MKJP','MYNN','MUHA')
          -- Major European
          OR ICAO_ID IN ('EGLL','EGKK','EGLC','LFPG','LFPO','EDDF','EDDM','EHAM',
                        'LEMD','LEBL','LIRF','LSZH','LOWW','EKCH','ENGM','ESSA')
          -- Major Asian
          OR ICAO_ID IN ('RJTT','RJAA','RKSI','VHHH','WSSS','VTBS','RPLL','WMKK')
          -- Major Middle East
          OR ICAO_ID IN ('OMDB','OERK','LLBG','OTHH','OEJN')
          -- Major Oceania
          OR ICAO_ID IN ('YSSY','YMML','NZAA','NZWN')
          -- Major South America
          OR ICAO_ID IN ('SBGR','SCEL','SKBO','SEQM','SPJC')
          -- Major Africa
          OR ICAO_ID IN ('FAOR','HECA','GMMN','DNMM')
      );

    DECLARE @apt_count INT;
    SELECT @apt_count = COUNT(*) FROM dbo.wind_major_airports;
    PRINT 'Populated wind_major_airports with ' + CAST(@apt_count AS VARCHAR) + ' airports';
END
GO

-- ============================================================================
-- 7. Create procedure to build the grid-tier lookup
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_BuildWindGridTierLookup') AND type = 'P')
    DROP PROCEDURE dbo.sp_BuildWindGridTierLookup;
GO

CREATE PROCEDURE dbo.sp_BuildWindGridTierLookup
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start DATETIME2 = SYSUTCDATETIME();
    DECLARE @proximity_nm DECIMAL(6,2) = 50;  -- 50nm radius for airport proximity
    DECLARE @proximity_deg DECIMAL(6,4);

    -- Approximate: 1 degree latitude = 60nm
    SET @proximity_deg = @proximity_nm / 60.0;

    IF @debug = 1
        PRINT 'Building wind grid tier lookup (proximity: ' + CAST(@proximity_nm AS VARCHAR) + 'nm)...';

    -- Clear existing lookup
    TRUNCATE TABLE dbo.wind_grid_tier_lookup;

    -- Build lookup for all resolution levels
    -- We need to generate grid points at 0.25° intervals (finest resolution)
    -- and assign each to a tier

    -- Create temp table with all potential grid points
    CREATE TABLE #grid_points (
        lat DECIMAL(6,2),
        lon DECIMAL(7,2)
    );

    -- Generate global grid at 0.25° resolution
    -- Lat: -90 to 90, Lon: -180 to 179.75
    DECLARE @lat DECIMAL(6,2) = -90;
    DECLARE @lon DECIMAL(7,2);

    WHILE @lat <= 90
    BEGIN
        SET @lon = -180;
        WHILE @lon < 180
        BEGIN
            INSERT INTO #grid_points (lat, lon) VALUES (@lat, @lon);
            SET @lon = @lon + 0.25;
        END
        SET @lat = @lat + 0.25;
    END

    IF @debug = 1
    BEGIN
        DECLARE @total INT;
        SELECT @total = COUNT(*) FROM #grid_points;
        PRINT 'Generated ' + CAST(@total AS VARCHAR) + ' grid points';
    END

    -- First pass: Assign base tier based on region
    INSERT INTO dbo.wind_grid_tier_lookup (lat, lon, tier, near_airport, region_code)
    SELECT
        g.lat,
        g.lon,
        COALESCE(r.base_tier, 10) AS tier,  -- Default to tier 10 (remote) if no region match
        0 AS near_airport,
        r.region_code
    FROM #grid_points g
    OUTER APPLY (
        SELECT TOP 1 region_code, base_tier, airport_tier
        FROM dbo.wind_regions
        WHERE g.lat BETWEEN lat_min AND lat_max
          AND g.lon BETWEEN lon_min AND lon_max
        ORDER BY priority DESC
    ) r;

    IF @debug = 1
        PRINT 'Assigned base tiers to all grid points';

    -- Second pass: Check airport proximity and upgrade tier
    UPDATE l
    SET l.near_airport = 1,
        l.tier = r.airport_tier
    FROM dbo.wind_grid_tier_lookup l
    INNER JOIN dbo.wind_regions r ON r.region_code = l.region_code
    WHERE EXISTS (
        SELECT 1
        FROM dbo.wind_major_airports a
        WHERE ABS(a.lat - l.lat) <= @proximity_deg
          AND ABS(a.lon - l.lon) <= @proximity_deg * 1.5  -- Wider for longitude at higher latitudes
          AND (
              -- Haversine approximation for 50nm
              -- Using simplified calculation: sqrt((dlat*60)^2 + (dlon*60*cos(lat))^2) < 50
              SQRT(
                  POWER((a.lat - l.lat) * 60, 2) +
                  POWER((a.lon - l.lon) * 60 * COS(RADIANS(l.lat)), 2)
              ) <= @proximity_nm
          )
    );

    IF @debug = 1
    BEGIN
        DECLARE @upgraded INT;
        SELECT @upgraded = COUNT(*) FROM dbo.wind_grid_tier_lookup WHERE near_airport = 1;
        PRINT 'Upgraded ' + CAST(@upgraded AS VARCHAR) + ' grid points near airports';
    END

    -- Cleanup
    DROP TABLE #grid_points;

    -- Report stats
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Grid points by tier:';
        SELECT
            t.tier,
            t.tier_name,
            t.resolution_deg,
            COUNT(l.lat) AS grid_points,
            SUM(CASE WHEN l.near_airport = 1 THEN 1 ELSE 0 END) AS near_airports
        FROM dbo.wind_tier_config t
        LEFT JOIN dbo.wind_grid_tier_lookup l ON l.tier = t.tier
        GROUP BY t.tier, t.tier_name, t.resolution_deg
        ORDER BY t.tier;

        PRINT '';
        PRINT 'Completed in ' + CAST(DATEDIFF(SECOND, @start, SYSUTCDATETIME()) AS VARCHAR) + ' seconds';
    END
END
GO

PRINT 'Created procedure dbo.sp_BuildWindGridTierLookup';
GO

-- ============================================================================
-- 8. Create view for tier statistics
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_WindTierStats')
    DROP VIEW dbo.vw_WindTierStats;
GO

CREATE VIEW dbo.vw_WindTierStats
AS
SELECT
    t.tier,
    t.tier_name,
    t.resolution_deg,
    t.min_altitude_ft,
    CASE t.min_altitude_ft
        WHEN 0 THEN 10      -- All levels
        WHEN 10000 THEN 8   -- AOA 10000 (700 hPa and above)
        WHEN 18000 THEN 6   -- AOA FL180 (500 hPa and above)
        WHEN 24000 THEN 5   -- AOA FL240 (400 hPa and above)
        ELSE 5
    END AS pressure_level_count,
    COUNT(l.lat) AS lookup_points,
    -- Estimate actual grid points based on resolution
    COUNT(l.lat) * POWER(0.25 / t.resolution_deg, 2) AS effective_points,
    SUM(CASE WHEN l.near_airport = 1 THEN 1 ELSE 0 END) AS near_airport_points
FROM dbo.wind_tier_config t
LEFT JOIN dbo.wind_grid_tier_lookup l ON l.tier = t.tier
GROUP BY t.tier, t.tier_name, t.resolution_deg, t.min_altitude_ft;
GO

PRINT 'Created view dbo.vw_WindTierStats';
GO

PRINT '';
PRINT '=== Wind Tiered Resolution Migration Complete ===';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Run: EXEC dbo.sp_BuildWindGridTierLookup @debug = 1';
PRINT '  2. Review tier stats: SELECT * FROM dbo.vw_WindTierStats';
PRINT '  3. Update fetch script to use tiered approach';
PRINT '';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
