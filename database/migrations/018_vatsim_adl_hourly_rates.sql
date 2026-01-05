-- =====================================================
-- Hourly Traffic Rates Schema for VATSIM_ADL Database
-- Database: VATSIM_ADL
-- Schema: dbo
-- =====================================================

-- Airport totals table - stores aggregate data per airport per plan
IF NOT EXISTS (SELECT * FROM VATSIM_ADL.sys.objects WHERE object_id = OBJECT_ID(N'VATSIM_ADL.dbo.r_airport_totals') AND type = 'U')
CREATE TABLE VATSIM_ADL.dbo.r_airport_totals (
    id INT IDENTITY(1,1) PRIMARY KEY,
    plan_id INT NOT NULL,
    icao VARCHAR(4) NOT NULL,
    name NVARCHAR(200),
    
    -- Statsim actual traffic counts
    statsim_arr INT,           -- Total arrivals from Statsim
    statsim_dep INT,           -- Total departures from Statsim
    
    -- VATSIM planned rates (sum of hourly)
    vatsim_aar INT,            -- Total VATSIM AAR
    vatsim_adr INT,            -- Total VATSIM ADR
    
    -- Real-world reference rates (sum of hourly)
    rw_aar INT,                -- Total RW AAR
    rw_adr INT,                -- Total RW ADR
    
    -- Metadata
    statsim_url NVARCHAR(500),
    created_at DATETIME2 DEFAULT GETUTCDATE(),
    updated_at DATETIME2,
    
    INDEX IX_r_airport_totals_plan (plan_id),
    INDEX IX_r_airport_totals_icao (icao),
    CONSTRAINT UQ_r_airport_totals_plan_icao UNIQUE (plan_id, icao)
);

-- Hourly rates table - stores per-hour data for each airport
IF NOT EXISTS (SELECT * FROM VATSIM_ADL.sys.objects WHERE object_id = OBJECT_ID(N'VATSIM_ADL.dbo.r_hourly_rates') AND type = 'U')
CREATE TABLE VATSIM_ADL.dbo.r_hourly_rates (
    id INT IDENTITY(1,1) PRIMARY KEY,
    plan_id INT NOT NULL,
    icao VARCHAR(4) NOT NULL,
    
    -- Time identification
    hour_timestamp BIGINT,     -- Unix timestamp (ms)
    hour_date VARCHAR(10),     -- YYYY-MM-DD
    hour_time VARCHAR(5),      -- HH:00
    
    -- Statsim actual counts for this hour
    statsim_arr INT,           -- Arrivals this hour
    statsim_dep INT,           -- Departures this hour
    
    -- VATSIM rates for this hour
    vatsim_aar INT,            -- VATSIM AAR for this hour
    vatsim_adr INT,            -- VATSIM ADR for this hour
    
    -- Real-world reference rates for this hour
    rw_aar INT,                -- RW AAR for this hour
    rw_adr INT,                -- RW ADR for this hour
    
    INDEX IX_r_hourly_rates_plan (plan_id),
    INDEX IX_r_hourly_rates_icao (icao),
    INDEX IX_r_hourly_rates_plan_icao (plan_id, icao),
    INDEX IX_r_hourly_rates_timestamp (hour_timestamp)
);

-- =====================================================
-- Sample queries
-- =====================================================

-- Get all data for a plan
-- SELECT * FROM VATSIM_ADL.dbo.r_airport_totals WHERE plan_id = 123;
-- SELECT * FROM VATSIM_ADL.dbo.r_hourly_rates WHERE plan_id = 123 ORDER BY icao, hour_timestamp;

-- Get hourly data for specific airport
-- SELECT * FROM VATSIM_ADL.dbo.r_hourly_rates WHERE plan_id = 123 AND icao = 'KJFK' ORDER BY hour_timestamp;
