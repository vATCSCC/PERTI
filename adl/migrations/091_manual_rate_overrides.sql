-- =====================================================
-- Manual Rate Overrides
-- Migration: 091_manual_rate_overrides.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Allow controllers to set manual AAR/ADR rates
--          for specific time windows
--
-- DEPENDENCIES:
--   - 089_atis_weather_columns.sql (sp_GetSuggestedRates)
-- =====================================================

SET NOCOUNT ON;
GO

PRINT '=== Migration 091: Manual Rate Overrides ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- =====================================================
-- 1. MANUAL RATE OVERRIDE TABLE
-- Stores user-defined rate overrides for time windows
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'manual_rate_override')
BEGIN
    CREATE TABLE dbo.manual_rate_override (
        override_id     INT IDENTITY(1,1) PRIMARY KEY,
        airport_icao    VARCHAR(4) NOT NULL,

        -- Time window (UTC)
        start_utc       DATETIME2(0) NOT NULL,
        end_utc         DATETIME2(0) NOT NULL,

        -- Rates (NULL = don't override, use suggested)
        aar             SMALLINT NULL,          -- Arrival rate override
        adr             SMALLINT NULL,          -- Departure rate override

        -- Optional config selection
        config_id       INT NULL,               -- Optional: force specific config

        -- Metadata
        reason          NVARCHAR(255) NULL,     -- Why override was set
        created_by      NVARCHAR(64) NULL,      -- User who created it
        created_utc     DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        modified_utc    DATETIME2(0) NULL,

        -- Status
        is_active       BIT NOT NULL DEFAULT 1,

        -- Constraints
        CONSTRAINT CK_override_time_range CHECK (end_utc > start_utc),
        CONSTRAINT CK_override_has_rate CHECK (aar IS NOT NULL OR adr IS NOT NULL OR config_id IS NOT NULL)
    );

    -- Index for lookups by airport and time
    CREATE NONCLUSTERED INDEX IX_override_airport_time
        ON dbo.manual_rate_override (airport_icao, start_utc, end_utc)
        WHERE is_active = 1;

    -- Index for cleanup of expired overrides
    CREATE NONCLUSTERED INDEX IX_override_end_time
        ON dbo.manual_rate_override (end_utc)
        WHERE is_active = 1;

    PRINT 'Created table dbo.manual_rate_override';
END
ELSE
BEGIN
    PRINT 'Table dbo.manual_rate_override already exists - skipping';
END
GO

-- =====================================================
-- 2. VIEW: Current Active Overrides
-- Shows overrides that are currently in effect
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_rate_overrides')
    DROP VIEW dbo.vw_current_rate_overrides;
GO

CREATE VIEW dbo.vw_current_rate_overrides AS
SELECT
    o.override_id,
    o.airport_icao,
    o.start_utc,
    o.end_utc,
    o.aar,
    o.adr,
    o.config_id,
    c.config_name,
    o.reason,
    o.created_by,
    o.created_utc,
    -- Time info
    DATEDIFF(MINUTE, GETUTCDATE(), o.start_utc) AS starts_in_mins,
    DATEDIFF(MINUTE, GETUTCDATE(), o.end_utc) AS ends_in_mins,
    DATEDIFF(MINUTE, o.start_utc, o.end_utc) AS duration_mins,
    -- Status flags
    CASE WHEN GETUTCDATE() BETWEEN o.start_utc AND o.end_utc THEN 1 ELSE 0 END AS is_current,
    CASE WHEN o.start_utc > GETUTCDATE() THEN 1 ELSE 0 END AS is_future
FROM dbo.manual_rate_override o
LEFT JOIN dbo.airport_config c ON o.config_id = c.config_id
WHERE o.is_active = 1
  AND o.end_utc > GETUTCDATE();  -- Not expired
GO

PRINT 'Created view dbo.vw_current_rate_overrides';
GO

-- =====================================================
-- 3. PROCEDURE: Get Active Override for Airport
-- Returns the current override if one exists
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GetActiveRateOverride')
    DROP PROCEDURE dbo.sp_GetActiveRateOverride;
GO

CREATE PROCEDURE dbo.sp_GetActiveRateOverride
    @airport_icao VARCHAR(4),
    @check_time DATETIME2 = NULL  -- NULL = current time
AS
BEGIN
    SET NOCOUNT ON;

    IF @check_time IS NULL
        SET @check_time = GETUTCDATE();

    SELECT TOP 1
        o.override_id,
        o.airport_icao,
        o.start_utc,
        o.end_utc,
        o.aar,
        o.adr,
        o.config_id,
        c.config_name,
        o.reason,
        o.created_by,
        o.created_utc,
        DATEDIFF(MINUTE, @check_time, o.end_utc) AS remaining_mins
    FROM dbo.manual_rate_override o
    LEFT JOIN dbo.airport_config c ON o.config_id = c.config_id
    WHERE o.airport_icao = @airport_icao
      AND o.is_active = 1
      AND @check_time BETWEEN o.start_utc AND o.end_utc
    ORDER BY o.created_utc DESC;  -- Most recent override wins
END;
GO

PRINT 'Created procedure dbo.sp_GetActiveRateOverride';
GO

-- =====================================================
-- 4. PROCEDURE: Set Rate Override
-- Creates or updates a rate override for an airport
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_SetRateOverride')
    DROP PROCEDURE dbo.sp_SetRateOverride;
GO

CREATE PROCEDURE dbo.sp_SetRateOverride
    @airport_icao VARCHAR(4),
    @start_utc DATETIME2,
    @end_utc DATETIME2,
    @aar SMALLINT = NULL,
    @adr SMALLINT = NULL,
    @config_id INT = NULL,
    @reason NVARCHAR(255) = NULL,
    @created_by NVARCHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Validate input
    IF @end_utc <= @start_utc
    BEGIN
        RAISERROR('End time must be after start time', 16, 1);
        RETURN;
    END

    IF @aar IS NULL AND @adr IS NULL AND @config_id IS NULL
    BEGIN
        RAISERROR('Must specify at least one of: aar, adr, or config_id', 16, 1);
        RETURN;
    END

    -- Deactivate any overlapping overrides for this airport
    UPDATE dbo.manual_rate_override
    SET is_active = 0,
        modified_utc = GETUTCDATE()
    WHERE airport_icao = @airport_icao
      AND is_active = 1
      AND (
          -- Overlapping time windows
          (@start_utc BETWEEN start_utc AND end_utc)
          OR (@end_utc BETWEEN start_utc AND end_utc)
          OR (start_utc BETWEEN @start_utc AND @end_utc)
      );

    -- Insert new override
    INSERT INTO dbo.manual_rate_override (
        airport_icao, start_utc, end_utc, aar, adr, config_id, reason, created_by
    )
    VALUES (
        @airport_icao, @start_utc, @end_utc, @aar, @adr, @config_id, @reason, @created_by
    );

    -- Return the new override
    SELECT
        SCOPE_IDENTITY() AS override_id,
        @airport_icao AS airport_icao,
        @start_utc AS start_utc,
        @end_utc AS end_utc,
        @aar AS aar,
        @adr AS adr,
        @config_id AS config_id,
        @reason AS reason,
        'created' AS status;
END;
GO

PRINT 'Created procedure dbo.sp_SetRateOverride';
GO

-- =====================================================
-- 5. PROCEDURE: Cancel Rate Override
-- Deactivates an override (soft delete)
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_CancelRateOverride')
    DROP PROCEDURE dbo.sp_CancelRateOverride;
GO

CREATE PROCEDURE dbo.sp_CancelRateOverride
    @override_id INT = NULL,
    @airport_icao VARCHAR(4) = NULL  -- Cancel all active for airport
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @affected INT = 0;

    IF @override_id IS NOT NULL
    BEGIN
        UPDATE dbo.manual_rate_override
        SET is_active = 0,
            modified_utc = GETUTCDATE()
        WHERE override_id = @override_id
          AND is_active = 1;

        SET @affected = @@ROWCOUNT;
    END
    ELSE IF @airport_icao IS NOT NULL
    BEGIN
        UPDATE dbo.manual_rate_override
        SET is_active = 0,
            modified_utc = GETUTCDATE()
        WHERE airport_icao = @airport_icao
          AND is_active = 1;

        SET @affected = @@ROWCOUNT;
    END

    SELECT @affected AS overrides_cancelled;
END;
GO

PRINT 'Created procedure dbo.sp_CancelRateOverride';
GO

-- =====================================================
-- 6. PROCEDURE: Cleanup Expired Overrides
-- Removes old override records (run periodically)
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_CleanupExpiredOverrides')
    DROP PROCEDURE dbo.sp_CleanupExpiredOverrides;
GO

CREATE PROCEDURE dbo.sp_CleanupExpiredOverrides
    @retention_days INT = 7
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff DATETIME2 = DATEADD(DAY, -@retention_days, GETUTCDATE());

    DELETE FROM dbo.manual_rate_override
    WHERE end_utc < @cutoff
      AND is_active = 0;

    SELECT @@ROWCOUNT AS overrides_deleted;
END;
GO

PRINT 'Created procedure dbo.sp_CleanupExpiredOverrides';
GO

-- =====================================================
-- 7. UPDATE sp_GetSuggestedRates to Check Overrides
-- =====================================================

-- Drop and recreate with override logic
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
    DECLARE @config_name VARCHAR(64) = NULL;  -- Increased from 32 to 64
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
    DECLARE @rate_source VARCHAR(64) = NULL;  -- Increased from 32 to 64

    -- Manual override variables
    DECLARE @has_override BIT = 0;
    DECLARE @override_id INT = NULL;
    DECLARE @override_aar SMALLINT = NULL;
    DECLARE @override_adr SMALLINT = NULL;
    DECLARE @override_reason NVARCHAR(255) = NULL;
    DECLARE @override_end_utc DATETIME2 = NULL;

    -- Detected runway variables
    DECLARE @detected_arr_runway VARCHAR(4) = NULL;
    DECLARE @detected_dep_runway VARCHAR(4) = NULL;
    DECLARE @detected_age_mins INT = NULL;

    -- Weather variables
    DECLARE @wind_dir SMALLINT = NULL;
    DECLARE @wind_speed SMALLINT = NULL;
    DECLARE @wind_gust SMALLINT = NULL;
    DECLARE @visibility DECIMAL(4,1) = NULL;
    DECLARE @ceiling INT = NULL;
    DECLARE @weather_impact_cat INT = 0;

    -- ===========================================
    -- STEP 0: Check for Manual Override FIRST
    -- Manual overrides take priority over everything
    -- ===========================================
    SELECT TOP 1
        @has_override = 1,
        @override_id = o.override_id,
        @override_aar = o.aar,
        @override_adr = o.adr,
        @override_reason = o.reason,
        @override_end_utc = o.end_utc,
        @config_id = ISNULL(o.config_id, @config_id)
    FROM dbo.manual_rate_override o
    WHERE o.airport_icao = @airport_icao
      AND o.is_active = 1
      AND GETUTCDATE() BETWEEN o.start_utc AND o.end_utc
    ORDER BY o.created_utc DESC;

    -- If override has a config_id, get the config name
    IF @has_override = 1 AND @config_id IS NOT NULL
    BEGIN
        SELECT @config_name = config_name
        FROM dbo.airport_config
        WHERE config_id = @config_id;
    END

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
    -- STEP 2.5: Get Detected Config from Flight Tracks
    -- ===========================================
    IF EXISTS (SELECT 1 FROM sys.views WHERE name = 'vw_current_detected_config')
    BEGIN
        SELECT TOP 1
            @detected_arr_runway = arr_runway_primary,
            @detected_dep_runway = dep_runway_primary,
            @detected_age_mins = age_mins,
            @config_id = CASE WHEN @has_atis = 0 AND @has_override = 0 THEN matched_config_id ELSE @config_id END,
            @config_name = CASE WHEN @has_atis = 0 AND @has_override = 0 THEN matched_config_name ELSE @config_name END,
            @match_score = CASE WHEN @has_atis = 0 AND @has_override = 0 THEN match_score ELSE @match_score END,
            @match_type = CASE WHEN @has_atis = 0 AND @has_override = 0 THEN 'DETECTED_TRACKS' ELSE @match_type END,
            @config_matched = CASE WHEN @has_atis = 0 AND @has_override = 0 AND matched_config_id IS NOT NULL THEN 1 ELSE @config_matched END
        FROM dbo.vw_current_detected_config
        WHERE airport_icao = @airport_icao
          AND detection_confidence >= 50;
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
    -- Skip if we already have a config from override or detected
    -- ===========================================
    IF @config_id IS NULL
    BEGIN
        CREATE TABLE #ConfigMatch (
            config_id INT,
            config_name VARCHAR(64),
            match_type VARCHAR(16),
            match_score INT,
            arr_match BIT,
            dep_match BIT,
            config_arr_runways VARCHAR(32),
            config_dep_runways VARCHAR(32)
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
            END AS match_score,
            CASE WHEN s.arr_runways = @arr_runways THEN 1 ELSE 0 END AS arr_match,
            CASE WHEN s.dep_runways = @dep_runways THEN 1 ELSE 0 END AS dep_match,
            s.arr_runways,
            s.dep_runways
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
    -- STEP 7: Get Rates with Fallback Cascade
    -- ===========================================
    IF @config_id IS NOT NULL
    BEGIN
        DECLARE @weather_fallback TABLE (weather VARCHAR(8), priority INT);
        INSERT INTO @weather_fallback VALUES
            (@weather_category, 1),
            ('VMC', 2);

        SELECT TOP 1 @vatsim_aar = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'VATSIM'
          AND r.rate_type = 'ARR'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SELECT TOP 1 @vatsim_adr = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'VATSIM'
          AND r.rate_type = 'DEP'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SELECT TOP 1 @rw_aar = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'RW'
          AND r.rate_type = 'ARR'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SELECT TOP 1 @rw_adr = r.rate_value
        FROM dbo.airport_config_rate r
        JOIN @weather_fallback wf ON r.weather = wf.weather
        WHERE r.config_id = @config_id
          AND r.source = 'RW'
          AND r.rate_type = 'DEP'
          AND r.rate_value IS NOT NULL
        ORDER BY wf.priority;

        SET @rate_source = ISNULL(@match_type, 'CONFIG') + ' (' + CAST(@match_score AS VARCHAR) + '%)';
        IF @weather_category <> 'VMC'
            SET @rate_source = @rate_source + ', ' + @weather_category;
    END

    -- ===========================================
    -- STEP 8: Apply Manual Overrides
    -- Override rates if manual override is active
    -- ===========================================
    IF @has_override = 1
    BEGIN
        IF @override_aar IS NOT NULL
            SET @vatsim_aar = @override_aar;
        IF @override_adr IS NOT NULL
            SET @vatsim_adr = @override_adr;

        SET @match_type = 'MANUAL';
        SET @match_score = 100;
        SET @config_matched = 1;
        SET @rate_source = 'Manual override';
        IF @override_reason IS NOT NULL
            SET @rate_source = @rate_source + ': ' + LEFT(@override_reason, 40);
    END

    -- ===========================================
    -- STEP 9: Adjust Confidence Based on Data Quality
    -- ===========================================
    IF @has_override = 0  -- Don't reduce confidence for manual overrides
    BEGIN
        IF @atis_age_mins > 60
            SET @match_score = @match_score * 0.7;
        ELSE IF @atis_age_mins > 30
            SET @match_score = @match_score * 0.9;

        IF @has_atis = 1 AND @flight_category IS NULL
            SET @match_score = @match_score * 0.8;
    END

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
        CASE
            WHEN @has_override = 1 THEN 0  -- Manual override = confirmed rates
            WHEN @has_atis = 0 OR @config_matched = 0 OR @match_score < 50 THEN 1
            ELSE 0
        END AS is_suggested,
        @rate_source AS rate_source,
        @effective_utc AS effective_utc,
        -- Override info (new fields)
        @has_override AS has_override,
        @override_id AS override_id,
        @override_reason AS override_reason,
        @override_end_utc AS override_end_utc;
END;
GO

PRINT 'Updated sp_GetSuggestedRates with manual override support';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '091_manual_rate_overrides.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - manual_rate_override: Stores user-defined rate overrides';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_current_rate_overrides: Shows active and upcoming overrides';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_GetActiveRateOverride: Get current override for airport';
PRINT '  - sp_SetRateOverride: Create/update rate override';
PRINT '  - sp_CancelRateOverride: Deactivate an override';
PRINT '  - sp_CleanupExpiredOverrides: Remove old records';
PRINT '';
PRINT 'Updated procedures:';
PRINT '  - sp_GetSuggestedRates: Now checks for manual overrides first';
GO
