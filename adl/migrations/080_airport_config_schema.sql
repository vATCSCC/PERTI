-- =====================================================
-- Airport Configuration Schema
-- Migration: 080_airport_config_schema.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Normalized airport runway configurations with
--          rates by weather category and NAM TAF impacts
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. MAIN CONFIGURATION TABLE
-- One row per airport config (e.g., DTW South Flow)
-- =====================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_config_rate')
    DROP TABLE dbo.airport_config_rate;
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_config_runway')
    DROP TABLE dbo.airport_config_runway;
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_config')
    DROP TABLE dbo.airport_config;
GO

CREATE TABLE dbo.airport_config (
    config_id       INT IDENTITY(1,1) PRIMARY KEY,
    airport_faa     VARCHAR(4) NOT NULL,           -- FAA code (DTW)
    airport_icao    VARCHAR(4) NOT NULL,           -- ICAO code (KDTW)
    config_name     VARCHAR(32) NOT NULL,          -- "South Flow", "North Flow"
    config_code     VARCHAR(16) NULL,              -- Optional short code "SF", "NF"

    is_active       BIT DEFAULT 1,
    created_utc     DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc     DATETIME2 NULL,

    CONSTRAINT UQ_airport_config UNIQUE (airport_icao, config_name),
    INDEX IX_airport_config_faa (airport_faa),
    INDEX IX_airport_config_icao (airport_icao)
);
GO

PRINT 'Created dbo.airport_config table';
GO

-- =====================================================
-- 2. RUNWAY ASSIGNMENT TABLE
-- Normalized: one row per runway per config
-- =====================================================

CREATE TABLE dbo.airport_config_runway (
    id              BIGINT IDENTITY(1,1) PRIMARY KEY,
    config_id       INT NOT NULL,
    runway_id       VARCHAR(32) NOT NULL,          -- "27L", "28R", "09" (supports extended patterns)
    runway_use      VARCHAR(4) NOT NULL,           -- 'ARR', 'DEP', 'BOTH'
    priority        TINYINT DEFAULT 1,             -- 1 = primary runway
    approach_type   VARCHAR(16) NULL,              -- "ILS", "VOR", "RNAV", "LDA"
    config_mode     VARCHAR(16) NULL,              -- "ARR", "DEP", "BALANCED", "MIXED" (European-style)
    notes           VARCHAR(64) NULL,              -- "WINTER", "CAT_II", "LAHSO", etc.

    CONSTRAINT FK_config_runway_config FOREIGN KEY (config_id)
        REFERENCES dbo.airport_config(config_id) ON DELETE CASCADE,
    CONSTRAINT CK_runway_use CHECK (runway_use IN ('ARR', 'DEP', 'BOTH')),
    CONSTRAINT UQ_config_runway UNIQUE (config_id, runway_id, runway_use),
    INDEX IX_config_runway_config (config_id)
);
GO

PRINT 'Created dbo.airport_config_runway table';
GO

-- =====================================================
-- 3. RATE TABLE
-- Normalized: one row per source/weather/type combination
-- =====================================================

CREATE TABLE dbo.airport_config_rate (
    id              BIGINT IDENTITY(1,1) PRIMARY KEY,
    config_id       INT NOT NULL,
    source          VARCHAR(8) NOT NULL,           -- 'VATSIM' or 'RW'
    weather         VARCHAR(8) NOT NULL,           -- 'VMC','LVMC','IMC','LIMC','VLIMC'
    rate_type       VARCHAR(4) NOT NULL,           -- 'ARR' or 'DEP'
    rate_value      SMALLINT NOT NULL,             -- Rate per hour

    CONSTRAINT FK_config_rate_config FOREIGN KEY (config_id)
        REFERENCES dbo.airport_config(config_id) ON DELETE CASCADE,
    CONSTRAINT CK_rate_source CHECK (source IN ('VATSIM', 'RW')),
    CONSTRAINT CK_rate_weather CHECK (weather IN ('VMC', 'LVMC', 'IMC', 'LIMC', 'VLIMC')),
    CONSTRAINT CK_rate_type CHECK (rate_type IN ('ARR', 'DEP')),
    CONSTRAINT UQ_config_rate UNIQUE (config_id, source, weather, rate_type),
    INDEX IX_config_rate_config (config_id)
);
GO

PRINT 'Created dbo.airport_config_rate table';
GO

-- =====================================================
-- 4. WEATHER IMPACT TABLE (NAM TAF Board Rules)
-- Weather condition thresholds and impact categories
-- =====================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_weather_impact')
    DROP TABLE dbo.airport_weather_impact;
GO

CREATE TABLE dbo.airport_weather_impact (
    id              BIGINT IDENTITY(1,1) PRIMARY KEY,
    airport_icao    VARCHAR(4) NOT NULL,           -- ICAO code or 'ANY' for generic

    -- Wind conditions (direction range + speed/gust thresholds)
    wdir_min        SMALLINT NULL,                 -- >= wind direction (0-360)
    wdir_max        SMALLINT NULL,                 -- <= wind direction
    wspd_min        SMALLINT NULL,                 -- > wind speed (kts)
    wspd_max        SMALLINT NULL,                 -- <= wind speed
    wgst_min        SMALLINT NULL,                 -- > gust speed
    wgst_max        SMALLINT NULL,                 -- <= gust speed
    wind_cat        TINYINT NULL,                  -- Impact category (0-3)
    wind_runways    VARCHAR(16) NULL,              -- Affected runways, e.g., "04/22"
    wind_notes      VARCHAR(64) NULL,              -- Additional notes

    -- Ceiling conditions (in hundreds of feet)
    cig_min         SMALLINT NULL,                 -- >= ceiling
    cig_max         SMALLINT NULL,                 -- < ceiling
    cig_cat         TINYINT NULL,                  -- Impact category (0-3)
    cig_notes       VARCHAR(64) NULL,              -- e.g., "AAR=32"

    -- Visibility conditions (statute miles)
    vis_min         DECIMAL(4,2) NULL,             -- >= visibility
    vis_max         DECIMAL(4,2) NULL,             -- < visibility
    vis_cat         TINYINT NULL,                  -- Impact category (0-3)
    vis_notes       VARCHAR(64) NULL,

    -- Weather phenomena (independent of wind/cig/vis)
    wx_type         VARCHAR(16) NULL,              -- e.g., "TS", "SN", "FZRA", "SHRA"
    wx_cat          TINYINT NULL,                  -- Impact category (0-3)
    wx_notes        VARCHAR(64) NULL,

    source          VARCHAR(16) DEFAULT 'NAM_TAF', -- Data source
    is_active       BIT DEFAULT 1,
    created_utc     DATETIME2 DEFAULT GETUTCDATE(),

    INDEX IX_weather_impact_airport (airport_icao),
    INDEX IX_weather_impact_wx (wx_type)
);
GO

PRINT 'Created dbo.airport_weather_impact table';
GO

-- =====================================================
-- 5. HELPER VIEW: Config Summary with Runways
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_airport_config_summary')
    DROP VIEW dbo.vw_airport_config_summary;
GO

CREATE VIEW dbo.vw_airport_config_summary AS
SELECT
    c.config_id,
    c.airport_faa,
    c.airport_icao,
    c.config_name,
    c.config_code,
    c.is_active,
    c.created_utc,
    c.updated_utc,
    -- Runways as slash-separated strings
    STRING_AGG(CASE WHEN r.runway_use IN ('ARR','BOTH') THEN r.runway_id END, '/')
        WITHIN GROUP (ORDER BY r.priority) AS arr_runways,
    STRING_AGG(CASE WHEN r.runway_use IN ('DEP','BOTH') THEN r.runway_id END, '/')
        WITHIN GROUP (ORDER BY r.priority) AS dep_runways
FROM dbo.airport_config c
LEFT JOIN dbo.airport_config_runway r ON c.config_id = r.config_id
GROUP BY c.config_id, c.airport_faa, c.airport_icao, c.config_name,
         c.config_code, c.is_active, c.created_utc, c.updated_utc;
GO

PRINT 'Created vw_airport_config_summary view';
GO

-- =====================================================
-- 6. HELPER VIEW: Config with All Rates (Pivoted)
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_airport_config_rates')
    DROP VIEW dbo.vw_airport_config_rates;
GO

CREATE VIEW dbo.vw_airport_config_rates AS
SELECT
    c.config_id,
    c.airport_faa,
    c.airport_icao,
    c.config_name,
    c.config_code,
    -- VATSIM Arrival Rates
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'VMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS vatsim_vmc_aar,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'LVMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS vatsim_lvmc_aar,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'IMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS vatsim_imc_aar,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'LIMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS vatsim_limc_aar,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'VLIMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS vatsim_vlimc_aar,
    -- VATSIM Departure Rates
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'VMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS vatsim_vmc_adr,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'LVMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS vatsim_lvmc_adr,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'IMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS vatsim_imc_adr,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'LIMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS vatsim_limc_adr,
    MAX(CASE WHEN r.source = 'VATSIM' AND r.weather = 'VLIMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS vatsim_vlimc_adr,
    -- Real-World Arrival Rates
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'VMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS rw_vmc_aar,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'LVMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS rw_lvmc_aar,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'IMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS rw_imc_aar,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'LIMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS rw_limc_aar,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'VLIMC' AND r.rate_type = 'ARR' THEN r.rate_value END) AS rw_vlimc_aar,
    -- Real-World Departure Rates
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'VMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS rw_vmc_adr,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'LVMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS rw_lvmc_adr,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'IMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS rw_imc_adr,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'LIMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS rw_limc_adr,
    MAX(CASE WHEN r.source = 'RW' AND r.weather = 'VLIMC' AND r.rate_type = 'DEP' THEN r.rate_value END) AS rw_vlimc_adr
FROM dbo.airport_config c
LEFT JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
GROUP BY c.config_id, c.airport_faa, c.airport_icao, c.config_name, c.config_code;
GO

PRINT 'Created vw_airport_config_rates view';
GO

-- =====================================================
-- 7. FUNCTION: Get Config Rate by Weather
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetConfigRate') AND type = 'FN')
    DROP FUNCTION dbo.fn_GetConfigRate;
GO

CREATE FUNCTION dbo.fn_GetConfigRate(
    @config_id INT,
    @source VARCHAR(8),
    @weather VARCHAR(8),
    @rate_type VARCHAR(4)
)
RETURNS SMALLINT
AS
BEGIN
    DECLARE @rate SMALLINT;

    SELECT @rate = rate_value
    FROM dbo.airport_config_rate
    WHERE config_id = @config_id
      AND source = @source
      AND weather = @weather
      AND rate_type = @rate_type;

    RETURN @rate;
END;
GO

PRINT 'Created fn_GetConfigRate function';
GO

-- =====================================================
-- 8. FUNCTION: Calculate Weather Impact
-- Returns max impact category for given conditions
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetWeatherImpact') AND type = 'FN')
    DROP FUNCTION dbo.fn_GetWeatherImpact;
GO

CREATE FUNCTION dbo.fn_GetWeatherImpact(
    @airport_icao VARCHAR(4),
    @wind_dir SMALLINT,
    @wind_spd SMALLINT,
    @wind_gst SMALLINT,
    @ceiling SMALLINT,       -- In hundreds of feet
    @visibility DECIMAL(4,2),
    @wx_phenomena VARCHAR(16)
)
RETURNS TINYINT
AS
BEGIN
    DECLARE @max_impact TINYINT = 0;
    DECLARE @wind_impact TINYINT = 0;
    DECLARE @cig_impact TINYINT = 0;
    DECLARE @vis_impact TINYINT = 0;
    DECLARE @wx_impact TINYINT = 0;

    -- Check wind impact (airport-specific first, then generic)
    SELECT TOP 1 @wind_impact = ISNULL(wind_cat, 0)
    FROM dbo.airport_weather_impact
    WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
      AND is_active = 1
      AND wind_cat IS NOT NULL
      AND (@wind_dir >= ISNULL(wdir_min, 0) AND @wind_dir <= ISNULL(wdir_max, 360))
      AND ((@wind_spd > ISNULL(wspd_min, -1) AND @wind_spd <= ISNULL(wspd_max, 999))
           OR (@wind_gst > ISNULL(wgst_min, -1) AND @wind_gst <= ISNULL(wgst_max, 999)))
    ORDER BY CASE WHEN airport_icao = @airport_icao THEN 0 ELSE 1 END, wind_cat DESC;

    -- Check ceiling impact
    SELECT TOP 1 @cig_impact = ISNULL(cig_cat, 0)
    FROM dbo.airport_weather_impact
    WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
      AND is_active = 1
      AND cig_cat IS NOT NULL
      AND @ceiling >= ISNULL(cig_min, 0)
      AND @ceiling < ISNULL(cig_max, 999)
    ORDER BY CASE WHEN airport_icao = @airport_icao THEN 0 ELSE 1 END, cig_cat DESC;

    -- Check visibility impact
    SELECT TOP 1 @vis_impact = ISNULL(vis_cat, 0)
    FROM dbo.airport_weather_impact
    WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
      AND is_active = 1
      AND vis_cat IS NOT NULL
      AND @visibility >= ISNULL(vis_min, 0)
      AND @visibility < ISNULL(vis_max, 999)
    ORDER BY CASE WHEN airport_icao = @airport_icao THEN 0 ELSE 1 END, vis_cat DESC;

    -- Check weather phenomena impact
    IF @wx_phenomena IS NOT NULL
    BEGIN
        SELECT TOP 1 @wx_impact = ISNULL(wx_cat, 0)
        FROM dbo.airport_weather_impact
        WHERE (airport_icao = @airport_icao OR airport_icao = 'ANY')
          AND is_active = 1
          AND wx_cat IS NOT NULL
          AND CHARINDEX(wx_type, @wx_phenomena) > 0
        ORDER BY CASE WHEN airport_icao = @airport_icao THEN 0 ELSE 1 END, wx_cat DESC;
    END

    -- Return maximum impact
    SET @max_impact = @wind_impact;
    IF @cig_impact > @max_impact SET @max_impact = @cig_impact;
    IF @vis_impact > @max_impact SET @max_impact = @vis_impact;
    IF @wx_impact > @max_impact SET @max_impact = @wx_impact;

    RETURN @max_impact;
END;
GO

PRINT 'Created fn_GetWeatherImpact function';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '080_airport_config_schema.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - airport_config: Main configuration table (airport + config name)';
PRINT '  - airport_config_runway: Normalized runway assignments';
PRINT '  - airport_config_rate: Rates by source/weather/type';
PRINT '  - airport_weather_impact: NAM TAF Board weather impact rules';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_airport_config_summary: Configs with runways as strings';
PRINT '  - vw_airport_config_rates: Configs with all rates pivoted';
PRINT '';
PRINT 'Functions created:';
PRINT '  - fn_GetConfigRate: Get specific rate value';
PRINT '  - fn_GetWeatherImpact: Calculate max impact from weather conditions';
GO
