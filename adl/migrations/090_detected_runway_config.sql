-- =====================================================
-- Detected Runway Configuration from Flight Tracks
-- Migration: 090_detected_runway_config.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Detect runway usage from actual flight data
--          to enhance rate suggestions when no ATIS
--
-- DEPENDENCIES (must run first):
--   - core/002_adl_times_trajectory.sql (adl_flight_trajectory table)
--   - 089_atis_weather_columns.sql (weather columns in vatsim_atis)
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- DEPENDENCY CHECK
-- =====================================================

-- Check for required columns from migration 089
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'weather_category')
BEGIN
    RAISERROR('ERROR: Missing weather_category column in vatsim_atis. Run 089_atis_weather_columns.sql first.', 16, 1);
    RETURN;
END
GO

-- Check for required trajectory table from core migrations
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'adl_flight_trajectory')
BEGIN
    RAISERROR('ERROR: Missing adl_flight_trajectory table. Run core/002_adl_times_trajectory.sql first.', 16, 1);
    RETURN;
END
GO

PRINT 'Dependency check passed - proceeding with migration';
GO

-- =====================================================
-- 1. DETECTED RUNWAY CONFIG TABLE
-- Stores runway configurations detected from flight tracks
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'detected_runway_config')
BEGIN
    CREATE TABLE dbo.detected_runway_config (
        detection_id        BIGINT IDENTITY(1,1) PRIMARY KEY,
        airport_icao        VARCHAR(4) NOT NULL,

        -- Detection window
        window_start_utc    DATETIME2 NOT NULL,
        window_end_utc      DATETIME2 NOT NULL,
        window_minutes      INT NOT NULL,  -- 30, 60, etc.

        -- Detected arrival runway(s)
        arr_runway_primary  VARCHAR(4) NULL,      -- Primary runway (highest count)
        arr_runway_secondary VARCHAR(4) NULL,     -- Secondary runway (if dual ops)
        arr_heading_primary SMALLINT NULL,        -- Heading of primary (10-degree bucket)
        arr_flight_count    INT NOT NULL DEFAULT 0,
        arr_total_ops       INT NOT NULL DEFAULT 0,

        -- Detected departure runway(s)
        dep_runway_primary  VARCHAR(4) NULL,
        dep_runway_secondary VARCHAR(4) NULL,
        dep_heading_primary SMALLINT NULL,
        dep_flight_count    INT NOT NULL DEFAULT 0,
        dep_total_ops       INT NOT NULL DEFAULT 0,

        -- Confidence metrics
        detection_confidence DECIMAL(5,2) NULL,   -- 0-100%
        sample_size         INT NOT NULL DEFAULT 0,
        heading_spread_deg  SMALLINT NULL,        -- Standard deviation of headings

        -- Config matching
        matched_config_id   INT NULL,             -- FK to airport_config if matched
        matched_config_name VARCHAR(32) NULL,
        match_score         INT NULL,             -- 0-100

        -- Metadata
        created_utc         DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        INDEX IX_detected_airport (airport_icao, window_end_utc DESC),
        INDEX IX_detected_recent (window_end_utc DESC)
    );
    PRINT 'Created dbo.detected_runway_config table';
END
GO

-- =====================================================
-- 2. RUNWAY HEADING REFERENCE TABLE
-- Maps runway designators to magnetic headings
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'runway_heading_ref')
BEGIN
    CREATE TABLE dbo.runway_heading_ref (
        runway_id       VARCHAR(4) NOT NULL,
        heading_min     SMALLINT NOT NULL,    -- Start of heading band
        heading_max     SMALLINT NOT NULL,    -- End of heading band
        PRIMARY KEY (runway_id)
    );

    -- Populate with standard runway headings (10-degree bands centered on runway heading)
    INSERT INTO dbo.runway_heading_ref (runway_id, heading_min, heading_max) VALUES
    ('01', 355, 25),   ('19', 175, 205),
    ('02', 5, 35),     ('20', 185, 215),
    ('03', 15, 45),    ('21', 195, 225),
    ('04', 25, 55),    ('22', 205, 235),
    ('05', 35, 65),    ('23', 215, 245),
    ('06', 45, 75),    ('24', 225, 255),
    ('07', 55, 85),    ('25', 235, 265),
    ('08', 65, 95),    ('26', 245, 275),
    ('09', 75, 105),   ('27', 255, 285),
    ('10', 85, 115),   ('28', 265, 295),
    ('11', 95, 125),   ('29', 275, 305),
    ('12', 105, 135),  ('30', 285, 315),
    ('13', 115, 145),  ('31', 295, 325),
    ('14', 125, 155),  ('32', 305, 335),
    ('15', 135, 165),  ('33', 315, 345),
    ('16', 145, 175),  ('34', 325, 355),
    ('17', 155, 185),  ('35', 335, 5),
    ('18', 165, 195),  ('36', 345, 15);

    PRINT 'Created and populated dbo.runway_heading_ref table';
END
GO

-- =====================================================
-- 3. FUNCTION: Map Heading to Runway
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('dbo.fn_HeadingToRunway') AND type = 'FN')
    DROP FUNCTION dbo.fn_HeadingToRunway;
GO

CREATE FUNCTION dbo.fn_HeadingToRunway(
    @heading SMALLINT
)
RETURNS VARCHAR(4)
AS
BEGIN
    DECLARE @runway VARCHAR(4);

    -- Normalize heading to 0-359
    SET @heading = @heading % 360;
    IF @heading < 0 SET @heading = @heading + 360;

    -- Find matching runway
    SELECT TOP 1 @runway = runway_id
    FROM dbo.runway_heading_ref
    WHERE (heading_min <= heading_max AND @heading BETWEEN heading_min AND heading_max)
       OR (heading_min > heading_max AND (@heading >= heading_min OR @heading <= heading_max));  -- Handle wrap-around (e.g., 355-25)

    RETURN @runway;
END;
GO

PRINT 'Created fn_HeadingToRunway function';
GO

-- =====================================================
-- 4. PROCEDURE: Detect Runways from Flight Tracks
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_DetectRunwaysFromFlights')
    DROP PROCEDURE dbo.sp_DetectRunwaysFromFlights;
GO

CREATE PROCEDURE dbo.sp_DetectRunwaysFromFlights
    @window_minutes INT = 30,           -- Detection window (default 30 min)
    @min_flights INT = 3,               -- Minimum flights to detect config
    @max_altitude_ft INT = 4000,        -- Max altitude for approach/departure detection
    @airport_filter VARCHAR(4) = NULL   -- Optional single airport filter
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @window_end DATETIME2 = GETUTCDATE();
    DECLARE @window_start DATETIME2 = DATEADD(MINUTE, -@window_minutes, @window_end);

    -- ===========================================
    -- STEP 1: Collect Flight Operations Data
    -- Get flights that arrived or departed in window
    -- with their final approach/departure headings
    -- ===========================================

    CREATE TABLE #FlightOps (
        airport_icao VARCHAR(4),
        op_type VARCHAR(3),           -- 'ARR' or 'DEP'
        callsign VARCHAR(12),
        heading_deg SMALLINT,
        altitude_ft INT,
        recorded_utc DATETIME2,
        runway_detected VARCHAR(4)
    );

    -- Arrivals: flights that transitioned to 'arrived' phase
    -- Use last trajectory point below max altitude
    INSERT INTO #FlightOps
    SELECT
        fp.fp_dest_icao AS airport_icao,
        'ARR' AS op_type,
        c.callsign,
        t.heading_deg,
        t.altitude_ft,
        t.recorded_utc,
        dbo.fn_HeadingToRunway(t.heading_deg) AS runway_detected
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_plan fp ON c.flight_uid = fp.flight_uid
    INNER JOIN dbo.adl_flight_trajectory t ON c.flight_uid = t.flight_uid
    WHERE c.phase = 'arrived'
      AND c.last_seen_utc BETWEEN @window_start AND @window_end
      AND t.altitude_ft < @max_altitude_ft
      AND t.altitude_ft > 100  -- Above ground level
      AND (@airport_filter IS NULL OR fp.fp_dest_icao = @airport_filter)
      AND t.recorded_utc = (
          -- Get the last trajectory point below max altitude
          SELECT MAX(t2.recorded_utc)
          FROM dbo.adl_flight_trajectory t2
          WHERE t2.flight_uid = c.flight_uid
            AND t2.altitude_ft < @max_altitude_ft
            AND t2.altitude_ft > 100
            AND t2.recorded_utc BETWEEN DATEADD(HOUR, -1, c.last_seen_utc) AND c.last_seen_utc
      );

    -- Departures: flights that transitioned from taxiing to departed
    -- Use first trajectory point above ground level
    INSERT INTO #FlightOps
    SELECT
        fp.fp_dept_icao AS airport_icao,
        'DEP' AS op_type,
        c.callsign,
        t.heading_deg,
        t.altitude_ft,
        t.recorded_utc,
        dbo.fn_HeadingToRunway(t.heading_deg) AS runway_detected
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_plan fp ON c.flight_uid = fp.flight_uid
    INNER JOIN dbo.adl_flight_times ft ON c.flight_uid = ft.flight_uid
    INNER JOIN dbo.adl_flight_trajectory t ON c.flight_uid = t.flight_uid
    WHERE c.phase IN ('departed', 'enroute', 'descending', 'arrived')
      AND ft.atd_utc BETWEEN @window_start AND @window_end
      AND t.altitude_ft BETWEEN 500 AND @max_altitude_ft
      AND (@airport_filter IS NULL OR fp.fp_dept_icao = @airport_filter)
      AND t.recorded_utc = (
          -- Get the first trajectory point after departure in climb
          SELECT MIN(t2.recorded_utc)
          FROM dbo.adl_flight_trajectory t2
          WHERE t2.flight_uid = c.flight_uid
            AND t2.altitude_ft BETWEEN 500 AND @max_altitude_ft
            AND t2.recorded_utc BETWEEN ft.atd_utc AND DATEADD(MINUTE, 10, ft.atd_utc)
      );

    -- ===========================================
    -- STEP 2: Aggregate by Airport and Runway
    -- ===========================================

    CREATE TABLE #RunwayCounts (
        airport_icao VARCHAR(4),
        op_type VARCHAR(3),
        runway_detected VARCHAR(4),
        heading_avg SMALLINT,
        flight_count INT,
        rank_num INT
    );

    INSERT INTO #RunwayCounts
    SELECT
        airport_icao,
        op_type,
        runway_detected,
        AVG(heading_deg) AS heading_avg,
        COUNT(DISTINCT callsign) AS flight_count,
        ROW_NUMBER() OVER (
            PARTITION BY airport_icao, op_type
            ORDER BY COUNT(DISTINCT callsign) DESC
        ) AS rank_num
    FROM #FlightOps
    WHERE runway_detected IS NOT NULL
    GROUP BY airport_icao, op_type, runway_detected
    HAVING COUNT(DISTINCT callsign) >= 1;

    -- ===========================================
    -- STEP 3: Build Detected Configurations
    -- ===========================================

    -- Get airports with enough traffic
    CREATE TABLE #AirportSummary (
        airport_icao VARCHAR(4),
        arr_total INT,
        dep_total INT,
        total_ops INT
    );

    INSERT INTO #AirportSummary
    SELECT
        airport_icao,
        SUM(CASE WHEN op_type = 'ARR' THEN flight_count ELSE 0 END) AS arr_total,
        SUM(CASE WHEN op_type = 'DEP' THEN flight_count ELSE 0 END) AS dep_total,
        SUM(flight_count) AS total_ops
    FROM #RunwayCounts
    GROUP BY airport_icao
    HAVING SUM(flight_count) >= @min_flights;

    -- ===========================================
    -- STEP 4: Insert Detected Configurations
    -- ===========================================

    INSERT INTO dbo.detected_runway_config (
        airport_icao,
        window_start_utc,
        window_end_utc,
        window_minutes,
        arr_runway_primary,
        arr_runway_secondary,
        arr_heading_primary,
        arr_flight_count,
        arr_total_ops,
        dep_runway_primary,
        dep_runway_secondary,
        dep_heading_primary,
        dep_flight_count,
        dep_total_ops,
        detection_confidence,
        sample_size
    )
    SELECT
        a.airport_icao,
        @window_start,
        @window_end,
        @window_minutes,
        -- Arrival runway(s)
        arr_p.runway_detected,
        arr_s.runway_detected,
        arr_p.heading_avg,
        ISNULL(arr_p.flight_count, 0),
        a.arr_total,
        -- Departure runway(s)
        dep_p.runway_detected,
        dep_s.runway_detected,
        dep_p.heading_avg,
        ISNULL(dep_p.flight_count, 0),
        a.dep_total,
        -- Confidence: primary runway usage % (higher = more consistent)
        CASE
            WHEN a.total_ops > 0 THEN
                (ISNULL(arr_p.flight_count, 0) + ISNULL(dep_p.flight_count, 0)) * 100.0 / a.total_ops
            ELSE 0
        END,
        a.total_ops
    FROM #AirportSummary a
    LEFT JOIN #RunwayCounts arr_p ON a.airport_icao = arr_p.airport_icao AND arr_p.op_type = 'ARR' AND arr_p.rank_num = 1
    LEFT JOIN #RunwayCounts arr_s ON a.airport_icao = arr_s.airport_icao AND arr_s.op_type = 'ARR' AND arr_s.rank_num = 2
    LEFT JOIN #RunwayCounts dep_p ON a.airport_icao = dep_p.airport_icao AND dep_p.op_type = 'DEP' AND dep_p.rank_num = 1
    LEFT JOIN #RunwayCounts dep_s ON a.airport_icao = dep_s.airport_icao AND dep_s.op_type = 'DEP' AND dep_s.rank_num = 2;

    DECLARE @detected_count INT = @@ROWCOUNT;

    -- ===========================================
    -- STEP 5: Match to Airport Configs
    -- ===========================================

    UPDATE d
    SET
        d.matched_config_id = c.config_id,
        d.matched_config_name = c.config_name,
        d.match_score = CASE
            WHEN s.arr_runways LIKE '%' + d.arr_runway_primary + '%'
                 AND s.dep_runways LIKE '%' + d.dep_runway_primary + '%' THEN 100
            WHEN s.arr_runways LIKE '%' + d.arr_runway_primary + '%' THEN 70
            WHEN s.dep_runways LIKE '%' + d.dep_runway_primary + '%' THEN 60
            ELSE 30
        END
    FROM dbo.detected_runway_config d
    CROSS APPLY (
        SELECT TOP 1 c2.config_id, c2.config_name, s2.arr_runways, s2.dep_runways
        FROM dbo.airport_config c2
        JOIN dbo.vw_airport_config_summary s2 ON c2.config_id = s2.config_id
        WHERE c2.airport_icao = d.airport_icao
          AND c2.is_active = 1
          AND (s2.arr_runways LIKE '%' + d.arr_runway_primary + '%'
               OR s2.dep_runways LIKE '%' + d.dep_runway_primary + '%')
        ORDER BY
            CASE WHEN s2.arr_runways LIKE '%' + d.arr_runway_primary + '%'
                      AND s2.dep_runways LIKE '%' + d.dep_runway_primary + '%' THEN 0
                 WHEN s2.arr_runways LIKE '%' + d.arr_runway_primary + '%' THEN 1
                 ELSE 2 END
    ) c
    JOIN dbo.vw_airport_config_summary s ON c.config_id = s.config_id
    WHERE d.window_end_utc = @window_end
      AND d.matched_config_id IS NULL;

    -- Cleanup
    DROP TABLE #FlightOps;
    DROP TABLE #RunwayCounts;
    DROP TABLE #AirportSummary;

    -- Return summary
    SELECT
        @detected_count AS airports_detected,
        @window_start AS window_start,
        @window_end AS window_end,
        @window_minutes AS window_minutes;
END;
GO

PRINT 'Created sp_DetectRunwaysFromFlights procedure';
GO

-- =====================================================
-- 5. VIEW: Current Detected Configuration
-- Returns most recent detection for each airport
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_detected_config')
    DROP VIEW dbo.vw_current_detected_config;
GO

CREATE VIEW dbo.vw_current_detected_config AS
WITH LatestDetection AS (
    SELECT
        airport_icao,
        arr_runway_primary,
        arr_runway_secondary,
        arr_heading_primary,
        arr_flight_count,
        dep_runway_primary,
        dep_runway_secondary,
        dep_heading_primary,
        dep_flight_count,
        detection_confidence,
        sample_size,
        matched_config_id,
        matched_config_name,
        match_score,
        window_end_utc,
        created_utc,
        ROW_NUMBER() OVER (PARTITION BY airport_icao ORDER BY window_end_utc DESC) AS rn
    FROM dbo.detected_runway_config
    WHERE window_end_utc > DATEADD(HOUR, -2, GETUTCDATE())  -- Only recent detections
)
SELECT
    airport_icao,
    arr_runway_primary,
    arr_runway_secondary,
    arr_heading_primary,
    arr_flight_count,
    dep_runway_primary,
    dep_runway_secondary,
    dep_heading_primary,
    dep_flight_count,
    detection_confidence,
    sample_size,
    matched_config_id,
    matched_config_name,
    match_score,
    window_end_utc AS detected_utc,
    DATEDIFF(MINUTE, window_end_utc, GETUTCDATE()) AS age_mins
FROM LatestDetection
WHERE rn = 1;
GO

PRINT 'Created vw_current_detected_config view';
GO

-- =====================================================
-- 6. UPDATE sp_GetSuggestedRates to use detected config
-- =====================================================

-- Add new step between ATIS lookup and config matching
-- to check for detected configuration from flight tracks

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GetSuggestedRates')
    DROP PROCEDURE dbo.sp_GetSuggestedRates;
GO

CREATE PROCEDURE dbo.sp_GetSuggestedRates
    @airport_icao VARCHAR(4)
AS
BEGIN
    SET NOCOUNT ON;

    -- ===========================================
    -- RESULT VARIABLES
    -- ===========================================
    DECLARE @has_atis BIT = 0;
    DECLARE @atis_code CHAR(1) = NULL;
    DECLARE @atis_age_mins INT = NULL;
    DECLARE @weather_category VARCHAR(8) = 'VMC';
    DECLARE @flight_category VARCHAR(4) = NULL;
    DECLARE @config_id INT = NULL;
    DECLARE @config_name VARCHAR(32) = NULL;
    DECLARE @config_matched BIT = 0;
    DECLARE @match_type VARCHAR(16) = NULL;
    DECLARE @match_score INT = 0;
    DECLARE @arr_runways VARCHAR(32) = NULL;
    DECLARE @dep_runways VARCHAR(32) = NULL;
    DECLARE @vatsim_aar SMALLINT = NULL;
    DECLARE @vatsim_adr SMALLINT = NULL;
    DECLARE @rw_aar SMALLINT = NULL;
    DECLARE @rw_adr SMALLINT = NULL;
    DECLARE @effective_utc DATETIME2 = GETUTCDATE();
    DECLARE @rate_source VARCHAR(64) = NULL;

    -- Weather variables
    DECLARE @wind_dir SMALLINT = NULL;
    DECLARE @wind_speed SMALLINT = NULL;
    DECLARE @wind_gust SMALLINT = NULL;
    DECLARE @visibility DECIMAL(4,1) = NULL;
    DECLARE @ceiling INT = NULL;
    DECLARE @weather_impact_cat INT = 0;

    -- Detected config variables
    DECLARE @detected_arr_runway VARCHAR(4) = NULL;
    DECLARE @detected_dep_runway VARCHAR(4) = NULL;
    DECLARE @detected_age_mins INT = NULL;

    -- ===========================================
    -- STEP 1: Get Latest ATIS Data
    -- ===========================================
    SELECT TOP 1
        @has_atis = 1,
        @atis_code = a.atis_code,
        @weather_category = ISNULL(a.weather_category, 'VMC'),
        @flight_category = a.flight_category,
        @effective_utc = a.fetched_utc,
        @atis_age_mins = DATEDIFF(MINUTE, a.fetched_utc, GETUTCDATE()),
        @wind_dir = a.wind_dir_deg,
        @wind_speed = a.wind_speed_kt,
        @wind_gust = a.wind_gust_kt,
        @visibility = a.visibility_sm,
        @ceiling = a.ceiling_ft
    FROM dbo.vatsim_atis a
    WHERE a.airport_icao = @airport_icao
      AND a.parse_status = 'PARSED'
      AND a.fetched_utc > DATEADD(HOUR, -2, GETUTCDATE())
    ORDER BY a.fetched_utc DESC;

    -- ===========================================
    -- STEP 2: Get Current Runway Configuration from ATIS
    -- ===========================================
    IF @has_atis = 1
    BEGIN
        SELECT
            @arr_runways = STRING_AGG(CASE WHEN runway_use IN ('ARR', 'BOTH') THEN runway_id END, '/'),
            @dep_runways = STRING_AGG(CASE WHEN runway_use IN ('DEP', 'BOTH') THEN runway_id END, '/')
        FROM dbo.vw_current_runways_in_use
        WHERE airport_icao = @airport_icao;
    END

    -- ===========================================
    -- STEP 2.5: Get Detected Config from Flight Tracks
    -- Use if no ATIS or as validation
    -- ===========================================
    SELECT TOP 1
        @detected_arr_runway = arr_runway_primary,
        @detected_dep_runway = dep_runway_primary,
        @detected_age_mins = age_mins,  -- View pre-calculates this
        @config_id = CASE WHEN @has_atis = 0 THEN matched_config_id ELSE @config_id END,
        @config_name = CASE WHEN @has_atis = 0 THEN matched_config_name ELSE @config_name END,
        @match_score = CASE WHEN @has_atis = 0 THEN match_score ELSE @match_score END,
        @match_type = CASE WHEN @has_atis = 0 THEN 'DETECTED_TRACKS' ELSE @match_type END,
        @config_matched = CASE WHEN @has_atis = 0 AND matched_config_id IS NOT NULL THEN 1 ELSE @config_matched END
    FROM dbo.vw_current_detected_config
    WHERE airport_icao = @airport_icao
      AND detection_confidence >= 50  -- Only use confident detections
      AND age_mins < 60;              -- Detection must be recent

    -- If no ATIS, use detected runways
    IF @has_atis = 0 AND @detected_arr_runway IS NOT NULL
    BEGIN
        SET @arr_runways = @detected_arr_runway;
        SET @dep_runways = ISNULL(@detected_dep_runway, @detected_arr_runway);
    END

    -- ===========================================
    -- STEP 3: Calculate Weather Impact Category
    -- ===========================================
    IF EXISTS (SELECT 1 FROM dbo.airport_weather_impact WHERE airport_icao = @airport_icao OR airport_icao = 'ANY')
    BEGIN
        SELECT @weather_impact_cat = MAX(wind_cat)
        FROM dbo.airport_weather_impact
        WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
          AND is_active = 1
          AND @wind_speed BETWEEN ISNULL(wspd_min, 0) AND ISNULL(wspd_max, 999)
          AND (@wind_gust IS NULL OR @wind_gust BETWEEN ISNULL(wgst_min, 0) AND ISNULL(wgst_max, 999));

        SELECT @weather_impact_cat = ISNULL(
            (SELECT MAX(cig_cat) FROM dbo.airport_weather_impact
             WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
               AND is_active = 1
               AND @ceiling BETWEEN ISNULL(cig_min, 0) AND ISNULL(cig_max, 99999)),
            @weather_impact_cat);

        SELECT @weather_impact_cat = ISNULL(
            (SELECT MAX(vis_cat) FROM dbo.airport_weather_impact
             WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
               AND is_active = 1
               AND @visibility BETWEEN ISNULL(vis_min, 0) AND ISNULL(vis_max, 999)),
            @weather_impact_cat);

        IF @weather_impact_cat >= 3
            SET @weather_category = 'VLIMC';
        ELSE IF @weather_impact_cat = 2 AND @weather_category NOT IN ('LIMC', 'VLIMC')
            SET @weather_category = 'LIMC';
        ELSE IF @weather_impact_cat = 1 AND @weather_category NOT IN ('IMC', 'LIMC', 'VLIMC')
            SET @weather_category = 'IMC';
    END

    -- ===========================================
    -- STEP 4: Multi-Level Config Matching
    -- ===========================================
    IF @config_id IS NULL AND @arr_runways IS NOT NULL
    BEGIN
        CREATE TABLE #ConfigMatch (
            config_id INT,
            config_name VARCHAR(32),
            match_type VARCHAR(16),
            match_score INT
        );

        INSERT INTO #ConfigMatch
        SELECT
            c.config_id,
            c.config_name,
            CASE
                WHEN s.arr_runways = @arr_runways AND s.dep_runways = @dep_runways THEN 'EXACT'
                WHEN s.arr_runways = @arr_runways THEN 'PARTIAL_ARR'
                WHEN s.dep_runways = @dep_runways THEN 'PARTIAL_DEP'
                WHEN @arr_runways IS NOT NULL AND CHARINDEX(@arr_runways, s.arr_runways) > 0 THEN 'SUBSET_ARR'
                WHEN @dep_runways IS NOT NULL AND CHARINDEX(@dep_runways, s.dep_runways) > 0 THEN 'SUBSET_DEP'
                ELSE 'NONE'
            END,
            CASE
                WHEN s.arr_runways = @arr_runways AND s.dep_runways = @dep_runways THEN 100
                WHEN s.arr_runways = @arr_runways THEN 80
                WHEN s.dep_runways = @dep_runways THEN 70
                WHEN @arr_runways IS NOT NULL AND CHARINDEX(@arr_runways, s.arr_runways) > 0 THEN 50
                WHEN @dep_runways IS NOT NULL AND CHARINDEX(@dep_runways, s.dep_runways) > 0 THEN 40
                ELSE 0
            END
        FROM dbo.airport_config c
        JOIN dbo.vw_airport_config_summary s ON c.config_id = s.config_id
        WHERE c.airport_icao = @airport_icao
          AND c.is_active = 1;

        SELECT TOP 1
            @config_id = config_id,
            @config_name = config_name,
            @match_type = match_type,
            @match_score = match_score,
            @config_matched = CASE WHEN match_score > 0 THEN 1 ELSE 0 END
        FROM #ConfigMatch
        WHERE match_score > 0
        ORDER BY match_score DESC;

        DROP TABLE #ConfigMatch;
    END

    -- ===========================================
    -- STEP 5: Wind-Based Config Selection (fallback)
    -- ===========================================
    IF @config_id IS NULL AND @wind_dir IS NOT NULL
    BEGIN
        DECLARE @preferred_heading INT = (@wind_dir + 180) % 360;
        DECLARE @preferred_runway VARCHAR(4) = dbo.fn_HeadingToRunway(@preferred_heading);

        SELECT TOP 1
            @config_id = c.config_id,
            @config_name = c.config_name,
            @match_type = 'WIND_BASED',
            @match_score = 30
        FROM dbo.airport_config c
        JOIN dbo.airport_config_runway cr ON c.config_id = cr.config_id
        WHERE c.airport_icao = @airport_icao
          AND c.is_active = 1
          AND LEFT(cr.runway_id, 2) = @preferred_runway
          AND cr.runway_use IN ('ARR', 'BOTH')
        ORDER BY cr.priority;

        IF @config_id IS NOT NULL
            SET @config_matched = 1;
    END

    -- ===========================================
    -- STEP 6: Capacity-Based Fallback
    -- ===========================================
    IF @config_id IS NULL
    BEGIN
        SELECT TOP 1
            @config_id = c.config_id,
            @config_name = c.config_name,
            @match_type = 'CAPACITY_DEFAULT',
            @match_score = 10
        FROM dbo.airport_config c
        JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
        WHERE c.airport_icao = @airport_icao
          AND c.is_active = 1
          AND r.source = 'VATSIM'
          AND r.weather = @weather_category
          AND r.rate_type = 'ARR'
        ORDER BY r.rate_value DESC;

        IF @config_id IS NULL
        BEGIN
            SELECT TOP 1
                @config_id = c.config_id,
                @config_name = c.config_name,
                @match_type = 'VMC_FALLBACK',
                @match_score = 5
            FROM dbo.airport_config c
            JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
            WHERE c.airport_icao = @airport_icao
              AND c.is_active = 1
              AND r.source = 'VATSIM'
              AND r.weather = 'VMC'
              AND r.rate_type = 'ARR'
            ORDER BY r.rate_value DESC;
        END
    END

    -- ===========================================
    -- STEP 7: Get Rates with Fallback Cascade
    -- ===========================================
    IF @config_id IS NOT NULL
    BEGIN
        DECLARE @weather_fallback TABLE (weather VARCHAR(8), priority INT);
        INSERT INTO @weather_fallback VALUES (@weather_category, 1), ('VMC', 2);

        SELECT TOP 1 @vatsim_aar = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id AND r.source = 'VATSIM' AND r.rate_type = 'ARR' AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SELECT TOP 1 @vatsim_adr = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id AND r.source = 'VATSIM' AND r.rate_type = 'DEP' AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SELECT TOP 1 @rw_aar = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id AND r.source = 'RW' AND r.rate_type = 'ARR' AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SELECT TOP 1 @rw_adr = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id AND r.source = 'RW' AND r.rate_type = 'DEP' AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SET @rate_source = @match_type + ' (' + CAST(@match_score AS VARCHAR) + '%)';
        IF @weather_category <> 'VMC'
            SET @rate_source = @rate_source + ', ' + @weather_category;
        IF @detected_arr_runway IS NOT NULL
            SET @rate_source = @rate_source + ', detected RWY ' + @detected_arr_runway;
    END

    -- ===========================================
    -- STEP 8: Adjust Confidence
    -- ===========================================
    IF @atis_age_mins > 60
        SET @match_score = @match_score * 0.7;
    ELSE IF @atis_age_mins > 30
        SET @match_score = @match_score * 0.9;

    IF @has_atis = 1 AND @flight_category IS NULL
        SET @match_score = @match_score * 0.8;

    -- Boost confidence if detected config matches ATIS
    IF @has_atis = 1 AND @detected_arr_runway IS NOT NULL
       AND @arr_runways LIKE '%' + @detected_arr_runway + '%'
        SET @match_score = LEAST(@match_score * 1.1, 100);

    -- ===========================================
    -- RETURN RESULT
    -- ===========================================
    SELECT
        @airport_icao AS airport_icao,
        @has_atis AS has_atis,
        @atis_code AS atis_code,
        @atis_age_mins AS atis_age_mins,
        @flight_category AS flight_category,
        @weather_category AS weather_category,
        @weather_impact_cat AS weather_impact_category,
        @config_matched AS config_matched,
        @config_id AS config_id,
        @config_name AS config_name,
        @match_type AS match_type,
        CAST(@match_score AS INT) AS match_score,
        @arr_runways AS arr_runways,
        @dep_runways AS dep_runways,
        @detected_arr_runway AS detected_arr_runway,
        @detected_dep_runway AS detected_dep_runway,
        @detected_age_mins AS detected_age_mins,
        @wind_dir AS wind_dir_deg,
        @wind_speed AS wind_speed_kt,
        @wind_gust AS wind_gust_kt,
        @visibility AS visibility_sm,
        @ceiling AS ceiling_ft,
        @vatsim_aar AS vatsim_aar,
        @vatsim_adr AS vatsim_adr,
        @rw_aar AS rw_aar,
        @rw_adr AS rw_adr,
        CASE WHEN @has_atis = 0 OR @config_matched = 0 OR @match_score < 50 THEN 1 ELSE 0 END AS is_suggested,
        @rate_source AS rate_source,
        @effective_utc AS effective_utc;
END;
GO

PRINT 'Updated sp_GetSuggestedRates with flight track detection';
GO

-- =====================================================
-- 7. CLEANUP PROCEDURE
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_CleanupDetectedConfigs')
    DROP PROCEDURE dbo.sp_CleanupDetectedConfigs;
GO

CREATE PROCEDURE dbo.sp_CleanupDetectedConfigs
    @retention_hours INT = 24
AS
BEGIN
    SET NOCOUNT ON;

    DELETE FROM dbo.detected_runway_config
    WHERE window_end_utc < DATEADD(HOUR, -@retention_hours, GETUTCDATE());

    SELECT @@ROWCOUNT AS deleted_count;
END;
GO

PRINT 'Created sp_CleanupDetectedConfigs procedure';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '090_detected_runway_config.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - detected_runway_config: Stores runway detections from flight tracks';
PRINT '  - runway_heading_ref: Maps headings to runway designators';
PRINT '';
PRINT 'Functions created:';
PRINT '  - fn_HeadingToRunway: Convert heading to runway number';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_DetectRunwaysFromFlights: Run every 30 min to detect runways';
PRINT '  - sp_CleanupDetectedConfigs: Remove old detection records';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_current_detected_config: Latest detection per airport';
PRINT '';
PRINT 'Updated procedures:';
PRINT '  - sp_GetSuggestedRates: Now uses detected config as fallback';
GO
