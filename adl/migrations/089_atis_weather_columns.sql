-- =====================================================
-- ATIS Weather Columns Migration
-- Migration: 089_atis_weather_columns.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Add weather extraction columns to vatsim_atis
--          for flight category and rate suggestion
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. ADD WEATHER COLUMNS TO VATSIM_ATIS
-- =====================================================

-- Wind direction (0-360, NULL = variable)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'wind_dir_deg')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD wind_dir_deg SMALLINT NULL;
    PRINT 'Added wind_dir_deg column';
END
GO

-- Wind speed in knots
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'wind_speed_kt')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD wind_speed_kt SMALLINT NULL;
    PRINT 'Added wind_speed_kt column';
END
GO

-- Wind gust speed (NULL if no gusts)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'wind_gust_kt')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD wind_gust_kt SMALLINT NULL;
    PRINT 'Added wind_gust_kt column';
END
GO

-- Visibility in statute miles
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'visibility_sm')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD visibility_sm DECIMAL(4,1) NULL;
    PRINT 'Added visibility_sm column';
END
GO

-- Ceiling (lowest BKN/OVC/VV) in feet AGL
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'ceiling_ft')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD ceiling_ft INT NULL;
    PRINT 'Added ceiling_ft column';
END
GO

-- Altimeter setting in inches Hg
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'altimeter_inhg')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD altimeter_inhg DECIMAL(5,2) NULL;
    PRINT 'Added altimeter_inhg column';
END
GO

-- FAA flight category (VFR, MVFR, IFR, LIFR)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'flight_category')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD flight_category VARCHAR(4) NULL;
    PRINT 'Added flight_category column';
END
GO

-- PERTI weather category (VMC, LVMC, IMC, LIMC, VLIMC)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.vatsim_atis') AND name = 'weather_category')
BEGIN
    ALTER TABLE dbo.vatsim_atis ADD weather_category VARCHAR(8) NULL;
    PRINT 'Added weather_category column';
END
GO

-- =====================================================
-- 2. ADD INDEX FOR WEATHER LOOKUPS
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_atis_weather' AND object_id = OBJECT_ID('dbo.vatsim_atis'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_atis_weather
    ON dbo.vatsim_atis (airport_icao, weather_category, fetched_utc DESC)
    INCLUDE (atis_code, flight_category, visibility_sm, ceiling_ft);
    PRINT 'Created IX_atis_weather index';
END
GO

-- =====================================================
-- 3. VIEW: Current Airport Weather from ATIS
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_atis_weather')
    DROP VIEW dbo.vw_current_atis_weather;
GO

CREATE VIEW dbo.vw_current_atis_weather AS
WITH LatestAtis AS (
    SELECT
        airport_icao,
        atis_code,
        atis_type,
        wind_dir_deg,
        wind_speed_kt,
        wind_gust_kt,
        visibility_sm,
        ceiling_ft,
        altimeter_inhg,
        flight_category,
        weather_category,
        fetched_utc,
        ROW_NUMBER() OVER (PARTITION BY airport_icao ORDER BY fetched_utc DESC) AS rn
    FROM dbo.vatsim_atis
    WHERE parse_status = 'PARSED'
      AND fetched_utc > DATEADD(HOUR, -2, GETUTCDATE())  -- Only recent ATIS
)
SELECT
    airport_icao,
    atis_code,
    atis_type,
    wind_dir_deg,
    wind_speed_kt,
    wind_gust_kt,
    visibility_sm,
    ceiling_ft,
    altimeter_inhg,
    flight_category,
    weather_category,
    fetched_utc,
    DATEDIFF(MINUTE, fetched_utc, GETUTCDATE()) AS age_mins
FROM LatestAtis
WHERE rn = 1;
GO

PRINT 'Created vw_current_atis_weather view';
GO

-- =====================================================
-- 4. UPDATE sp_ImportVatsimAtis TO ACCEPT WEATHER
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ImportVatsimAtis')
    DROP PROCEDURE dbo.sp_ImportVatsimAtis;
GO

CREATE PROCEDURE dbo.sp_ImportVatsimAtis
    @json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @inserted_count INT = 0;
    DECLARE @updated_count INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Parse JSON into temp table (now includes weather fields)
        SELECT
            JSON_VALUE(value, '$.airport_icao') AS airport_icao,
            JSON_VALUE(value, '$.callsign') AS callsign,
            JSON_VALUE(value, '$.atis_type') AS atis_type,
            JSON_VALUE(value, '$.atis_code') AS atis_code,
            JSON_VALUE(value, '$.frequency') AS frequency,
            JSON_VALUE(value, '$.atis_text') AS atis_text,
            CAST(JSON_VALUE(value, '$.controller_cid') AS INT) AS controller_cid,
            TRY_CAST(JSON_VALUE(value, '$.logon_time') AS DATETIME2) AS logon_utc,
            -- Weather fields
            TRY_CAST(JSON_VALUE(value, '$.wind_dir_deg') AS SMALLINT) AS wind_dir_deg,
            TRY_CAST(JSON_VALUE(value, '$.wind_speed_kt') AS SMALLINT) AS wind_speed_kt,
            TRY_CAST(JSON_VALUE(value, '$.wind_gust_kt') AS SMALLINT) AS wind_gust_kt,
            TRY_CAST(JSON_VALUE(value, '$.visibility_sm') AS DECIMAL(4,1)) AS visibility_sm,
            TRY_CAST(JSON_VALUE(value, '$.ceiling_ft') AS INT) AS ceiling_ft,
            TRY_CAST(JSON_VALUE(value, '$.altimeter_inhg') AS DECIMAL(5,2)) AS altimeter_inhg,
            JSON_VALUE(value, '$.flight_category') AS flight_category,
            JSON_VALUE(value, '$.weather_category') AS weather_category
        INTO #atis_import
        FROM OPENJSON(@json);

        -- Insert new ATIS records (only if text changed or new code)
        INSERT INTO dbo.vatsim_atis (
            airport_icao, callsign, atis_type, atis_code, frequency,
            atis_text, controller_cid, logon_utc, parse_status,
            wind_dir_deg, wind_speed_kt, wind_gust_kt,
            visibility_sm, ceiling_ft, altimeter_inhg,
            flight_category, weather_category
        )
        SELECT
            i.airport_icao, i.callsign, i.atis_type, i.atis_code, i.frequency,
            i.atis_text, i.controller_cid, i.logon_utc, 'PENDING',
            i.wind_dir_deg, i.wind_speed_kt, i.wind_gust_kt,
            i.visibility_sm, i.ceiling_ft, i.altimeter_inhg,
            i.flight_category, i.weather_category
        FROM #atis_import i
        WHERE NOT EXISTS (
            -- Check if identical ATIS already exists in last 5 minutes
            SELECT 1 FROM dbo.vatsim_atis a
            WHERE a.airport_icao = i.airport_icao
              AND a.callsign = i.callsign
              AND a.atis_text = i.atis_text
              AND a.fetched_utc > DATEADD(MINUTE, -5, GETUTCDATE())
        );

        SET @inserted_count = @@ROWCOUNT;

        DROP TABLE #atis_import;

        COMMIT TRANSACTION;

        -- Return stats
        SELECT @inserted_count AS inserted_count, @updated_count AS updated_count;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        DECLARE @error NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @severity INT = ERROR_SEVERITY();
        DECLARE @state INT = ERROR_STATE();

        RAISERROR(@error, @severity, @state);
    END CATCH
END;
GO

PRINT 'Updated sp_ImportVatsimAtis procedure with weather support';
GO

-- =====================================================
-- 5. FUNCTION: Get Current Weather Category
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('dbo.fn_GetCurrentWeatherCategory') AND type = 'FN')
    DROP FUNCTION dbo.fn_GetCurrentWeatherCategory;
GO

CREATE FUNCTION dbo.fn_GetCurrentWeatherCategory(
    @airport_icao VARCHAR(4)
)
RETURNS VARCHAR(8)
AS
BEGIN
    DECLARE @category VARCHAR(8);

    SELECT TOP 1 @category = weather_category
    FROM dbo.vatsim_atis
    WHERE airport_icao = @airport_icao
      AND parse_status = 'PARSED'
      AND weather_category IS NOT NULL
      AND fetched_utc > DATEADD(HOUR, -2, GETUTCDATE())
    ORDER BY fetched_utc DESC;

    -- Default to VMC if no recent ATIS
    RETURN ISNULL(@category, 'VMC');
END;
GO

PRINT 'Created fn_GetCurrentWeatherCategory function';
GO

-- =====================================================
-- 6. PROCEDURE: Get Suggested Rates for Airport (Enhanced)
-- =====================================================
-- Robust rate suggestion algorithm with:
--   1. Multi-level config matching (exact, partial, subset, fallback)
--   2. Weather impact calculation using airport rules
--   3. Confidence scoring (0-100)
--   4. Wind-based runway preference scoring
--   5. Fallback cascading through weather categories
-- =====================================================

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
    DECLARE @match_type VARCHAR(16) = NULL;  -- EXACT, PARTIAL_ARR, PARTIAL_DEP, SUBSET, WIND_BASED, CAPACITY_DEFAULT
    DECLARE @match_score INT = 0;            -- 0-100 match confidence
    DECLARE @arr_runways VARCHAR(32) = NULL;
    DECLARE @dep_runways VARCHAR(32) = NULL;
    DECLARE @vatsim_aar SMALLINT = NULL;
    DECLARE @vatsim_adr SMALLINT = NULL;
    DECLARE @rw_aar SMALLINT = NULL;
    DECLARE @rw_adr SMALLINT = NULL;
    DECLARE @effective_utc DATETIME2 = GETUTCDATE();
    DECLARE @rate_source VARCHAR(32) = NULL; -- Description of how rates were determined

    -- ===========================================
    -- WEATHER VARIABLES
    -- ===========================================
    DECLARE @wind_dir SMALLINT = NULL;
    DECLARE @wind_speed SMALLINT = NULL;
    DECLARE @wind_gust SMALLINT = NULL;
    DECLARE @visibility DECIMAL(4,1) = NULL;
    DECLARE @ceiling INT = NULL;
    DECLARE @weather_impact_cat INT = 0;     -- 0-3 from airport_weather_impact rules

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
    -- STEP 2: Get Current Runway Configuration
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
    -- STEP 3: Calculate Weather Impact Category
    -- Uses airport_weather_impact rules if available
    -- ===========================================
    IF EXISTS (SELECT 1 FROM dbo.airport_weather_impact WHERE airport_icao = @airport_icao OR airport_icao = 'ANY')
    BEGIN
        -- Wind impact
        SELECT @weather_impact_cat = MAX(wind_cat)
        FROM dbo.airport_weather_impact
        WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
          AND is_active = 1
          AND @wind_speed BETWEEN ISNULL(wspd_min, 0) AND ISNULL(wspd_max, 999)
          AND (@wind_gust IS NULL OR @wind_gust BETWEEN ISNULL(wgst_min, 0) AND ISNULL(wgst_max, 999));

        -- Ceiling impact (take max of wind and ceiling)
        SELECT @weather_impact_cat = ISNULL(
            (SELECT MAX(cig_cat) FROM dbo.airport_weather_impact
             WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
               AND is_active = 1
               AND @ceiling BETWEEN ISNULL(cig_min, 0) AND ISNULL(cig_max, 99999)),
            @weather_impact_cat);

        -- Visibility impact
        SELECT @weather_impact_cat = ISNULL(
            (SELECT MAX(vis_cat) FROM dbo.airport_weather_impact
             WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
               AND is_active = 1
               AND @visibility BETWEEN ISNULL(vis_min, 0) AND ISNULL(vis_max, 999)),
            @weather_impact_cat);

        -- Override weather category based on impact
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

    -- Create temp table to score all matching configs
    CREATE TABLE #ConfigMatch (
        config_id INT,
        config_name VARCHAR(32),
        match_type VARCHAR(16),
        match_score INT,
        arr_match BIT,
        dep_match BIT,
        config_arr_runways VARCHAR(32),
        config_dep_runways VARCHAR(32)
    );

    -- Insert all active configs for this airport with match scores
    INSERT INTO #ConfigMatch
    SELECT
        c.config_id,
        c.config_name,
        CASE
            -- Exact match (both arr and dep runways match exactly)
            WHEN s.arr_runways = @arr_runways AND s.dep_runways = @dep_runways THEN 'EXACT'
            -- Arrival runways exact match
            WHEN s.arr_runways = @arr_runways THEN 'PARTIAL_ARR'
            -- Departure runways exact match
            WHEN s.dep_runways = @dep_runways THEN 'PARTIAL_DEP'
            -- All current arr runways are subset of config arr runways
            WHEN @arr_runways IS NOT NULL AND CHARINDEX(@arr_runways, s.arr_runways) > 0 THEN 'SUBSET_ARR'
            -- All current dep runways are subset of config dep runways
            WHEN @dep_runways IS NOT NULL AND CHARINDEX(@dep_runways, s.dep_runways) > 0 THEN 'SUBSET_DEP'
            -- No runway match
            ELSE 'NONE'
        END AS match_type,
        CASE
            WHEN s.arr_runways = @arr_runways AND s.dep_runways = @dep_runways THEN 100
            WHEN s.arr_runways = @arr_runways THEN 80
            WHEN s.dep_runways = @dep_runways THEN 70
            WHEN @arr_runways IS NOT NULL AND CHARINDEX(@arr_runways, s.arr_runways) > 0 THEN 50
            WHEN @dep_runways IS NOT NULL AND CHARINDEX(@dep_runways, s.dep_runways) > 0 THEN 40
            ELSE 0
        END AS match_score,
        CASE WHEN s.arr_runways = @arr_runways THEN 1 ELSE 0 END AS arr_match,
        CASE WHEN s.dep_runways = @dep_runways THEN 1 ELSE 0 END AS dep_match,
        s.arr_runways,
        s.dep_runways
    FROM dbo.airport_config c
    JOIN dbo.vw_airport_config_summary s ON c.config_id = s.config_id
    WHERE c.airport_icao = @airport_icao
      AND c.is_active = 1;

    -- Select best matching config
    SELECT TOP 1
        @config_id = config_id,
        @config_name = config_name,
        @match_type = match_type,
        @match_score = match_score,
        @config_matched = CASE WHEN match_score > 0 THEN 1 ELSE 0 END
    FROM #ConfigMatch
    WHERE match_score > 0
    ORDER BY match_score DESC;

    -- ===========================================
    -- STEP 5: Wind-Based Config Selection (fallback)
    -- If no runway match, try to find config based on wind direction
    -- ===========================================
    IF @config_id IS NULL AND @wind_dir IS NOT NULL
    BEGIN
        -- Calculate preferred runway heading (into the wind)
        DECLARE @preferred_heading INT = (@wind_dir + 180) % 360;
        DECLARE @preferred_runway VARCHAR(4) =
            CASE
                WHEN @preferred_heading < 5 OR @preferred_heading >= 355 THEN '36'
                ELSE RIGHT('0' + CAST(ROUND(@preferred_heading / 10.0, 0) AS VARCHAR), 2)
            END;

        -- Find config with runway matching wind direction
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
    -- If still no match, use highest capacity config for current weather
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

        -- If no rate for current weather, try VMC
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

    DROP TABLE #ConfigMatch;

    -- ===========================================
    -- STEP 7: Get Rates with Fallback Cascade
    -- Try current weather, then fall back through categories
    -- ===========================================
    IF @config_id IS NOT NULL
    BEGIN
        -- Weather category fallback order
        DECLARE @weather_fallback TABLE (weather VARCHAR(8), priority INT);
        INSERT INTO @weather_fallback VALUES
            (@weather_category, 1),
            ('VMC', 2);  -- Always try VMC as ultimate fallback

        -- VATSIM AAR
        SELECT TOP 1 @vatsim_aar = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'VATSIM'
          AND r.rate_type = 'ARR'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        -- VATSIM ADR
        SELECT TOP 1 @vatsim_adr = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'VATSIM'
          AND r.rate_type = 'DEP'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        -- RW AAR
        SELECT TOP 1 @rw_aar = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'RW'
          AND r.rate_type = 'ARR'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        -- RW ADR
        SELECT TOP 1 @rw_adr = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'RW'
          AND r.rate_type = 'DEP'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        -- Build rate source description
        SET @rate_source = @match_type + ' match (' + CAST(@match_score AS VARCHAR) + '%)';
        IF @weather_category <> 'VMC'
            SET @rate_source = @rate_source + ', ' + @weather_category + ' weather';
    END

    -- ===========================================
    -- STEP 8: Adjust Confidence Based on Data Quality
    -- ===========================================
    -- Reduce confidence for stale ATIS
    IF @atis_age_mins > 60
        SET @match_score = @match_score * 0.7;
    ELSE IF @atis_age_mins > 30
        SET @match_score = @match_score * 0.9;

    -- Reduce confidence if no weather data extracted
    IF @has_atis = 1 AND @flight_category IS NULL
        SET @match_score = @match_score * 0.8;

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

PRINT 'Created enhanced sp_GetSuggestedRates procedure';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '089_atis_weather_columns.sql completed successfully';
PRINT '';
PRINT 'Columns added to vatsim_atis:';
PRINT '  - wind_dir_deg, wind_speed_kt, wind_gust_kt';
PRINT '  - visibility_sm, ceiling_ft, altimeter_inhg';
PRINT '  - flight_category, weather_category';
PRINT '';
PRINT 'Objects created:';
PRINT '  - IX_atis_weather index';
PRINT '  - vw_current_atis_weather view';
PRINT '  - fn_GetCurrentWeatherCategory function';
PRINT '  - sp_GetSuggestedRates procedure';
PRINT '';
PRINT 'Updated procedures:';
PRINT '  - sp_ImportVatsimAtis (now accepts weather fields)';
GO
