-- =====================================================
-- ATIS Type Priority: Prefer ARR+DEP, fallback to COMB
-- Migration: 095
-- Description: Updates ATIS selection logic to:
--   1. Use BOTH ARR and DEP ATIS when both are current
--   2. Fall back to COMB (consolidated) ATIS if available
--   3. Only use single ARR or DEP ATIS as last resort
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 095: ATIS Type Priority ===';
PRINT '';

-- =====================================================
-- 1. VIEW: vw_current_atis_by_type
-- Returns all current ATIS records by airport and type
-- with age and staleness info
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_atis_by_type')
    DROP VIEW dbo.vw_current_atis_by_type;
GO

CREATE VIEW dbo.vw_current_atis_by_type AS
WITH RankedAtis AS (
    SELECT
        a.atis_id,
        a.airport_icao,
        a.callsign,
        a.atis_type,
        a.atis_code,
        a.frequency,
        a.atis_text,
        a.controller_cid,
        a.fetched_utc,
        a.logon_utc,
        a.parse_status,
        a.wind_dir_deg,
        a.wind_speed_kt,
        a.wind_gust_kt,
        a.visibility_sm,
        a.ceiling_ft,
        a.altimeter_inhg,
        a.flight_category,
        a.weather_category,
        DATEDIFF(MINUTE, a.fetched_utc, GETUTCDATE()) AS age_mins,
        ROW_NUMBER() OVER (
            PARTITION BY a.airport_icao, a.atis_type
            ORDER BY a.fetched_utc DESC
        ) AS rn
    FROM dbo.vatsim_atis a
    WHERE a.fetched_utc > DATEADD(HOUR, -2, GETUTCDATE())
)
SELECT
    atis_id,
    airport_icao,
    callsign,
    atis_type,
    atis_code,
    frequency,
    atis_text,
    controller_cid,
    fetched_utc,
    logon_utc,
    parse_status,
    wind_dir_deg,
    wind_speed_kt,
    wind_gust_kt,
    visibility_sm,
    ceiling_ft,
    altimeter_inhg,
    flight_category,
    weather_category,
    age_mins,
    CASE WHEN age_mins <= 30 THEN 1 ELSE 0 END AS is_current
FROM RankedAtis
WHERE rn = 1;
GO

PRINT 'Created vw_current_atis_by_type view';
GO

-- =====================================================
-- 2. VIEW: vw_effective_atis
-- Determines which ATIS source(s) to use per airport:
--   Priority 1: ARR + DEP (both current within 30 mins of each other)
--   Priority 2: COMB (consolidated)
--   Priority 3: Single ARR or DEP (rare)
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_effective_atis')
    DROP VIEW dbo.vw_effective_atis;
GO

CREATE VIEW dbo.vw_effective_atis AS
WITH AtisAvailability AS (
    -- Check what ATIS types are available per airport
    SELECT
        airport_icao,
        MAX(CASE WHEN atis_type = 'ARR' THEN 1 ELSE 0 END) AS has_arr,
        MAX(CASE WHEN atis_type = 'DEP' THEN 1 ELSE 0 END) AS has_dep,
        MAX(CASE WHEN atis_type = 'COMB' THEN 1 ELSE 0 END) AS has_comb,
        MAX(CASE WHEN atis_type = 'ARR' THEN age_mins END) AS arr_age_mins,
        MAX(CASE WHEN atis_type = 'DEP' THEN age_mins END) AS dep_age_mins,
        MAX(CASE WHEN atis_type = 'COMB' THEN age_mins END) AS comb_age_mins,
        MAX(CASE WHEN atis_type = 'ARR' THEN fetched_utc END) AS arr_fetched_utc,
        MAX(CASE WHEN atis_type = 'DEP' THEN fetched_utc END) AS dep_fetched_utc,
        MAX(CASE WHEN atis_type = 'COMB' THEN fetched_utc END) AS comb_fetched_utc
    FROM dbo.vw_current_atis_by_type
    GROUP BY airport_icao
),
SourceDecision AS (
    SELECT
        airport_icao,
        has_arr,
        has_dep,
        has_comb,
        arr_age_mins,
        dep_age_mins,
        comb_age_mins,
        -- Check if ARR and DEP are both available and within 30 mins of each other
        CASE
            WHEN has_arr = 1 AND has_dep = 1
                 AND ABS(DATEDIFF(MINUTE, arr_fetched_utc, dep_fetched_utc)) <= 30
            THEN 1
            ELSE 0
        END AS use_arr_dep_combo,
        -- Calculate effective source preference
        CASE
            -- Priority 1: Both ARR and DEP are current and close together
            WHEN has_arr = 1 AND has_dep = 1
                 AND ABS(DATEDIFF(MINUTE, arr_fetched_utc, dep_fetched_utc)) <= 30
            THEN 'ARR_DEP'
            -- Priority 2: COMB exists
            WHEN has_comb = 1
            THEN 'COMB'
            -- Priority 3: Only ARR available
            WHEN has_arr = 1 AND has_dep = 0
            THEN 'ARR_ONLY'
            -- Priority 4: Only DEP available
            WHEN has_dep = 1 AND has_arr = 0
            THEN 'DEP_ONLY'
            -- Edge case: ARR and DEP both exist but too far apart - use more recent
            WHEN has_arr = 1 AND has_dep = 1
            THEN CASE
                WHEN arr_age_mins <= dep_age_mins THEN 'ARR_ONLY'
                ELSE 'DEP_ONLY'
            END
            ELSE NULL
        END AS effective_source
    FROM AtisAvailability
)
SELECT
    sd.airport_icao,
    sd.has_arr,
    sd.has_dep,
    sd.has_comb,
    sd.arr_age_mins,
    sd.dep_age_mins,
    sd.comb_age_mins,
    sd.use_arr_dep_combo,
    sd.effective_source,
    -- Return the ATIS records to use
    arr.atis_id AS arr_atis_id,
    arr.callsign AS arr_callsign,
    arr.atis_code AS arr_atis_code,
    arr.frequency AS arr_frequency,
    arr.atis_text AS arr_atis_text,
    arr.controller_cid AS arr_controller_cid,
    arr.fetched_utc AS arr_fetched_utc,
    dep.atis_id AS dep_atis_id,
    dep.callsign AS dep_callsign,
    dep.atis_code AS dep_atis_code,
    dep.frequency AS dep_frequency,
    dep.atis_text AS dep_atis_text,
    dep.controller_cid AS dep_controller_cid,
    dep.fetched_utc AS dep_fetched_utc,
    comb.atis_id AS comb_atis_id,
    comb.callsign AS comb_callsign,
    comb.atis_code AS comb_atis_code,
    comb.frequency AS comb_frequency,
    comb.atis_text AS comb_atis_text,
    comb.controller_cid AS comb_controller_cid,
    comb.fetched_utc AS comb_fetched_utc,
    -- Weather from best source (prefer ARR for weather since it affects arrivals most)
    COALESCE(arr.wind_dir_deg, comb.wind_dir_deg, dep.wind_dir_deg) AS wind_dir_deg,
    COALESCE(arr.wind_speed_kt, comb.wind_speed_kt, dep.wind_speed_kt) AS wind_speed_kt,
    COALESCE(arr.wind_gust_kt, comb.wind_gust_kt, dep.wind_gust_kt) AS wind_gust_kt,
    COALESCE(arr.visibility_sm, comb.visibility_sm, dep.visibility_sm) AS visibility_sm,
    COALESCE(arr.ceiling_ft, comb.ceiling_ft, dep.ceiling_ft) AS ceiling_ft,
    COALESCE(arr.altimeter_inhg, comb.altimeter_inhg, dep.altimeter_inhg) AS altimeter_inhg,
    COALESCE(arr.flight_category, comb.flight_category, dep.flight_category) AS flight_category,
    COALESCE(arr.weather_category, comb.weather_category, dep.weather_category) AS weather_category,
    -- Effective age is the max age of sources being used
    CASE sd.effective_source
        WHEN 'ARR_DEP' THEN
            CASE WHEN sd.arr_age_mins > sd.dep_age_mins THEN sd.arr_age_mins ELSE sd.dep_age_mins END
        WHEN 'COMB' THEN sd.comb_age_mins
        WHEN 'ARR_ONLY' THEN sd.arr_age_mins
        WHEN 'DEP_ONLY' THEN sd.dep_age_mins
        ELSE NULL
    END AS effective_age_mins
FROM SourceDecision sd
LEFT JOIN dbo.vw_current_atis_by_type arr
    ON sd.airport_icao = arr.airport_icao AND arr.atis_type = 'ARR'
LEFT JOIN dbo.vw_current_atis_by_type dep
    ON sd.airport_icao = dep.airport_icao AND dep.atis_type = 'DEP'
LEFT JOIN dbo.vw_current_atis_by_type comb
    ON sd.airport_icao = comb.airport_icao AND comb.atis_type = 'COMB';
GO

PRINT 'Created vw_effective_atis view';
GO

-- =====================================================
-- 3. UPDATE vw_current_runways_in_use
-- Filter runways based on effective source decision
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_runways_in_use')
    DROP VIEW dbo.vw_current_runways_in_use;
GO

CREATE VIEW dbo.vw_current_runways_in_use AS
SELECT
    r.airport_icao,
    r.runway_id,
    r.runway_use,
    r.approach_type,
    r.source_type,
    r.effective_utc,
    a.atis_code,
    a.atis_type,
    a.callsign,
    a.controller_cid,
    a.fetched_utc AS atis_fetched_utc,
    DATEDIFF(MINUTE, r.effective_utc, GETUTCDATE()) AS active_mins,
    DATEDIFF(MINUTE, a.fetched_utc, GETUTCDATE()) AS atis_age_mins
FROM dbo.runway_in_use r
JOIN dbo.vatsim_atis a ON r.atis_id = a.atis_id
JOIN dbo.vw_effective_atis ea ON r.airport_icao = ea.airport_icao
WHERE r.superseded_utc IS NULL
  -- Only include if ATIS is less than 2 hours old
  AND a.fetched_utc > DATEADD(HOUR, -2, GETUTCDATE())
  -- Filter based on effective source decision
  AND (
      -- If using ARR+DEP combo, include both
      (ea.effective_source = 'ARR_DEP' AND r.source_type IN ('ARR', 'DEP'))
      -- If using COMB, only include COMB
      OR (ea.effective_source = 'COMB' AND r.source_type = 'COMB')
      -- If using ARR only, only include ARR
      OR (ea.effective_source = 'ARR_ONLY' AND r.source_type = 'ARR')
      -- If using DEP only, only include DEP
      OR (ea.effective_source = 'DEP_ONLY' AND r.source_type = 'DEP')
  );
GO

PRINT 'Updated vw_current_runways_in_use - uses effective source decision';
GO

-- =====================================================
-- 4. UPDATE vw_current_airport_config
-- Simplified - just aggregates from vw_current_runways_in_use
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_airport_config')
    DROP VIEW dbo.vw_current_airport_config;
GO

CREATE VIEW dbo.vw_current_airport_config AS
SELECT
    rr.airport_icao,
    STRING_AGG(
        CASE WHEN rr.runway_use IN ('ARR', 'BOTH') THEN rr.runway_id END, '/'
    ) WITHIN GROUP (ORDER BY rr.runway_id) AS arr_runways,
    STRING_AGG(
        CASE WHEN rr.runway_use IN ('DEP', 'BOTH') THEN rr.runway_id END, '/'
    ) WITHIN GROUP (ORDER BY rr.runway_id) AS dep_runways,
    STRING_AGG(
        CASE WHEN rr.approach_type IS NOT NULL
             THEN rr.approach_type + ' ' + rr.runway_id END, ', '
    ) AS approach_info,
    MIN(rr.effective_utc) AS config_since,
    MAX(rr.atis_code) AS atis_code,
    ea.effective_source,
    ea.effective_age_mins
FROM dbo.vw_current_runways_in_use rr
JOIN dbo.vw_effective_atis ea ON rr.airport_icao = ea.airport_icao
GROUP BY rr.airport_icao, ea.effective_source, ea.effective_age_mins;
GO

PRINT 'Updated vw_current_airport_config - simplified aggregation';
GO

-- =====================================================
-- 5. UPDATE sp_GetSuggestedRates
-- Use effective ATIS source for weather data
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
    DECLARE @effective_source VARCHAR(16) = NULL;
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

    -- Override variables
    DECLARE @has_override BIT = 0;
    DECLARE @override_id INT = NULL;
    DECLARE @override_reason VARCHAR(255) = NULL;
    DECLARE @override_end_utc DATETIME2 = NULL;

    -- Weather variables
    DECLARE @wind_dir SMALLINT = NULL;
    DECLARE @wind_speed SMALLINT = NULL;
    DECLARE @wind_gust SMALLINT = NULL;
    DECLARE @visibility DECIMAL(4,1) = NULL;
    DECLARE @ceiling INT = NULL;
    DECLARE @weather_impact_cat INT = 0;

    -- ===========================================
    -- STEP 0: Check for Active Manual Override
    -- ===========================================
    SELECT TOP 1
        @has_override = 1,
        @override_id = override_id,
        @override_reason = reason,
        @override_end_utc = end_utc,
        @vatsim_aar = COALESCE(aar, @vatsim_aar),
        @vatsim_adr = COALESCE(adr, @vatsim_adr),
        @config_id = COALESCE(config_id, @config_id)
    FROM dbo.manual_rate_override
    WHERE airport_icao = @airport_icao
      AND is_active = 1
      AND start_utc <= GETUTCDATE()
      AND end_utc > GETUTCDATE()
    ORDER BY start_utc DESC;

    -- ===========================================
    -- STEP 1: Get Effective ATIS Data
    -- Uses priority: ARR+DEP > COMB > single
    -- ===========================================
    SELECT
        @has_atis = CASE WHEN effective_source IS NOT NULL THEN 1 ELSE 0 END,
        @effective_source = effective_source,
        @atis_age_mins = effective_age_mins,
        @weather_category = ISNULL(weather_category, 'VMC'),
        @flight_category = flight_category,
        @wind_dir = wind_dir_deg,
        @wind_speed = wind_speed_kt,
        @wind_gust = wind_gust_kt,
        @visibility = visibility_sm,
        @ceiling = ceiling_ft,
        -- Use most recent fetched time for effective_utc
        @effective_utc = COALESCE(arr_fetched_utc, comb_fetched_utc, dep_fetched_utc, GETUTCDATE()),
        -- Get ATIS code (prefer ARR, then COMB, then DEP)
        @atis_code = COALESCE(arr_atis_code, comb_atis_code, dep_atis_code)
    FROM dbo.vw_effective_atis
    WHERE airport_icao = @airport_icao;

    -- ===========================================
    -- STEP 2: Get Current Runway Configuration
    -- ===========================================
    IF @has_atis = 1
    BEGIN
        SELECT
            @arr_runways = arr_runways,
            @dep_runways = dep_runways
        FROM dbo.vw_current_airport_config
        WHERE airport_icao = @airport_icao;
    END

    -- ===========================================
    -- STEP 2.5: Try Detected Config (fallback)
    -- ===========================================
    IF @arr_runways IS NULL AND @dep_runways IS NULL
    BEGIN
        SELECT TOP 1
            @arr_runways = COALESCE(arr_runway_primary, '') +
                          CASE WHEN arr_runway_secondary IS NOT NULL THEN '/' + arr_runway_secondary ELSE '' END,
            @dep_runways = COALESCE(dep_runway_primary, '') +
                          CASE WHEN dep_runway_secondary IS NOT NULL THEN '/' + dep_runway_secondary ELSE '' END,
            @config_id = matched_config_id,
            @config_name = matched_config_name,
            @match_type = 'DETECTED',
            @match_score = CAST(detection_confidence * match_score / 100 AS INT)
        FROM dbo.detected_runway_config
        WHERE airport_icao = @airport_icao
          AND window_end_utc > DATEADD(MINUTE, -30, GETUTCDATE())
          AND detection_confidence >= 50
        ORDER BY window_end_utc DESC;

        IF @config_id IS NOT NULL
            SET @config_matched = 1;
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
    IF @config_id IS NULL AND (@arr_runways IS NOT NULL OR @dep_runways IS NOT NULL)
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
            END AS match_type,
            CASE
                WHEN s.arr_runways = @arr_runways AND s.dep_runways = @dep_runways THEN 100
                WHEN s.arr_runways = @arr_runways THEN 80
                WHEN s.dep_runways = @dep_runways THEN 70
                WHEN @arr_runways IS NOT NULL AND CHARINDEX(@arr_runways, s.arr_runways) > 0 THEN 50
                WHEN @dep_runways IS NOT NULL AND CHARINDEX(@dep_runways, s.dep_runways) > 0 THEN 40
                ELSE 0
            END AS match_score
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
        DECLARE @preferred_runway VARCHAR(4) =
            CASE
                WHEN @preferred_heading < 5 OR @preferred_heading >= 355 THEN '36'
                ELSE RIGHT('0' + CAST(ROUND(@preferred_heading / 10.0, 0) AS VARCHAR), 2)
            END;

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
    -- STEP 7: Get Rates with Weather Fallback
    -- ===========================================
    IF @config_id IS NOT NULL AND @has_override = 0
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
    END

    -- ===========================================
    -- STEP 8: Apply Override Rates (if any)
    -- ===========================================
    IF @has_override = 1
    BEGIN
        -- Get config name if override forces a config
        IF @config_id IS NOT NULL AND @config_name IS NULL
        BEGIN
            SELECT @config_name = config_name
            FROM dbo.airport_config
            WHERE config_id = @config_id;

            SET @config_matched = 1;
            SET @match_type = 'OVERRIDE';
            SET @match_score = 100;
        END
    END

    -- ===========================================
    -- STEP 9: Adjust Confidence Based on Data Quality
    -- ===========================================
    IF @atis_age_mins > 60
        SET @match_score = CAST(@match_score * 0.1 AS INT);
    ELSE IF @atis_age_mins > 30
        SET @match_score = CAST(@match_score * 0.7 AS INT);

    IF @has_atis = 1 AND @flight_category IS NULL
        SET @match_score = CAST(@match_score * 0.8 AS INT);

    -- Build rate source description
    SET @rate_source = ISNULL(@match_type, 'NONE') + ' (' + CAST(@match_score AS VARCHAR) + '%)';
    IF @effective_source IS NOT NULL
        SET @rate_source = @rate_source + ', ' + @effective_source;
    IF @weather_category <> 'VMC'
        SET @rate_source = @rate_source + ', ' + @weather_category;
    IF @has_override = 1
        SET @rate_source = 'OVERRIDE: ' + @rate_source;

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
        @effective_utc AS effective_utc,
        @has_override AS has_override,
        @override_id AS override_id,
        @override_reason AS override_reason,
        @override_end_utc AS override_end_utc;
END;
GO

PRINT 'Updated sp_GetSuggestedRates - uses effective ATIS source';
GO

-- =====================================================
-- 6. Verification
-- =====================================================

PRINT '';
PRINT '=== Verification ===';
PRINT '';

-- Show current ATIS availability by airport
SELECT
    airport_icao,
    effective_source,
    has_arr,
    has_dep,
    has_comb,
    arr_age_mins,
    dep_age_mins,
    comb_age_mins,
    effective_age_mins
FROM dbo.vw_effective_atis
ORDER BY airport_icao;

PRINT '';
PRINT 'Migration 095 completed successfully.';
PRINT '';
PRINT 'ATIS Priority Logic:';
PRINT '  1. ARR + DEP (when both current and within 30 mins)';
PRINT '  2. COMB (consolidated ATIS)';
PRINT '  3. Single ARR or DEP (last resort)';
GO
