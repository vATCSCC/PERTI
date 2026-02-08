-- =====================================================
-- ATIS Tiered Cleanup Schema
-- Migration: 087_atis_tiered_cleanup.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Implement tiered retention for ATIS records
--          based on airport importance
-- =====================================================
--
-- Tier Structure:
--   Tier 0: Never delete
--           - ASPM82 airports
--           - Featured airports during VATUSA events (T-1H to T+6H)
--
--   Tier 1: 30-day retention
--           - Canada/Mexico/LATAM/Caribbean major facilities
--
--   Tier 2: 7-day retention
--           - Global major facilities (Europe, Asia, Middle East, Africa, Oceania)
--
--   Tier 3: 24-hour retention
--           - US/CA/MX/LATAM/Caribbean non-major facilities
--
--   Tier 4: 1-hour retention
--           - Global non-major facilities
--
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. REFERENCE TABLE: Major Airports by Region
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'ref_major_airports')
BEGIN
    CREATE TABLE dbo.ref_major_airports (
        airport_icao    VARCHAR(4) PRIMARY KEY,
        region          VARCHAR(16) NOT NULL,   -- ASPM82, CA_MX_LATAM, GLOBAL
        tier            TINYINT NOT NULL,       -- 0, 1, or 2
        description     VARCHAR(64) NULL,
        created_utc     DATETIME2 DEFAULT GETUTCDATE()
    );

    -- Tier 0: ASPM82 Airports (FAA Aviation System Performance Metrics)
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('KABQ', 'ASPM82', 0, 'Albuquerque'),
    ('KALB', 'ASPM82', 0, 'Albany'),
    ('PANC', 'ASPM82', 0, 'Anchorage'),
    ('KATL', 'ASPM82', 0, 'Atlanta'),
    ('KAUS', 'ASPM82', 0, 'Austin'),
    ('KBDL', 'ASPM82', 0, 'Hartford'),
    ('KBHM', 'ASPM82', 0, 'Birmingham'),
    ('KBNA', 'ASPM82', 0, 'Nashville'),
    ('KBOS', 'ASPM82', 0, 'Boston'),
    ('KBUF', 'ASPM82', 0, 'Buffalo'),
    ('KBUR', 'ASPM82', 0, 'Burbank'),
    ('KBWI', 'ASPM82', 0, 'Baltimore'),
    ('KCHS', 'ASPM82', 0, 'Charleston'),
    ('KCLE', 'ASPM82', 0, 'Cleveland'),
    ('KCLT', 'ASPM82', 0, 'Charlotte'),
    ('KCMH', 'ASPM82', 0, 'Columbus'),
    ('KCVG', 'ASPM82', 0, 'Cincinnati'),
    ('KDAL', 'ASPM82', 0, 'Dallas Love'),
    ('KDCA', 'ASPM82', 0, 'Washington National'),
    ('KDEN', 'ASPM82', 0, 'Denver'),
    ('KDFW', 'ASPM82', 0, 'Dallas/Fort Worth'),
    ('KDTW', 'ASPM82', 0, 'Detroit'),
    ('KEWR', 'ASPM82', 0, 'Newark'),
    ('KFLL', 'ASPM82', 0, 'Fort Lauderdale'),
    ('PHNL', 'ASPM82', 0, 'Honolulu'),
    ('KHOU', 'ASPM82', 0, 'Houston Hobby'),
    ('KIAD', 'ASPM82', 0, 'Washington Dulles'),
    ('KIAH', 'ASPM82', 0, 'Houston Intercontinental'),
    ('KIND', 'ASPM82', 0, 'Indianapolis'),
    ('KJAX', 'ASPM82', 0, 'Jacksonville'),
    ('KJFK', 'ASPM82', 0, 'New York JFK'),
    ('KLAS', 'ASPM82', 0, 'Las Vegas'),
    ('KLAX', 'ASPM82', 0, 'Los Angeles'),
    ('KLGA', 'ASPM82', 0, 'New York LaGuardia'),
    ('KLGB', 'ASPM82', 0, 'Long Beach'),
    ('KMCI', 'ASPM82', 0, 'Kansas City'),
    ('KMCO', 'ASPM82', 0, 'Orlando'),
    ('KMDW', 'ASPM82', 0, 'Chicago Midway'),
    ('KMEM', 'ASPM82', 0, 'Memphis'),
    ('KMIA', 'ASPM82', 0, 'Miami'),
    ('KMKE', 'ASPM82', 0, 'Milwaukee'),
    ('KMSP', 'ASPM82', 0, 'Minneapolis'),
    ('KMSY', 'ASPM82', 0, 'New Orleans'),
    ('KOAK', 'ASPM82', 0, 'Oakland'),
    ('PHOG', 'ASPM82', 0, 'Maui'),
    ('KOMA', 'ASPM82', 0, 'Omaha'),
    ('KONT', 'ASPM82', 0, 'Ontario'),
    ('KORD', 'ASPM82', 0, 'Chicago O''Hare'),
    ('KPBI', 'ASPM82', 0, 'Palm Beach'),
    ('KPDX', 'ASPM82', 0, 'Portland'),
    ('KPHL', 'ASPM82', 0, 'Philadelphia'),
    ('KPHX', 'ASPM82', 0, 'Phoenix'),
    ('KPIT', 'ASPM82', 0, 'Pittsburgh'),
    ('KPVD', 'ASPM82', 0, 'Providence'),
    ('KRDU', 'ASPM82', 0, 'Raleigh-Durham'),
    ('KRIC', 'ASPM82', 0, 'Richmond'),
    ('KRSW', 'ASPM82', 0, 'Fort Myers'),
    ('KSAN', 'ASPM82', 0, 'San Diego'),
    ('KSAT', 'ASPM82', 0, 'San Antonio'),
    ('KSDF', 'ASPM82', 0, 'Louisville'),
    ('KSEA', 'ASPM82', 0, 'Seattle'),
    ('KSFO', 'ASPM82', 0, 'San Francisco'),
    ('KSJC', 'ASPM82', 0, 'San Jose'),
    ('TJSJ', 'ASPM82', 0, 'San Juan'),
    ('KSLC', 'ASPM82', 0, 'Salt Lake City'),
    ('KSMF', 'ASPM82', 0, 'Sacramento'),
    ('KSNA', 'ASPM82', 0, 'Orange County'),
    ('KSTL', 'ASPM82', 0, 'St. Louis'),
    ('KTEB', 'ASPM82', 0, 'Teterboro'),
    ('KTPA', 'ASPM82', 0, 'Tampa'),
    ('KTUS', 'ASPM82', 0, 'Tucson');

    -- Tier 1: Canada Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('CYYZ', 'CA_MX_LATAM', 1, 'Toronto Pearson'),
    ('CYVR', 'CA_MX_LATAM', 1, 'Vancouver'),
    ('CYUL', 'CA_MX_LATAM', 1, 'Montreal Trudeau'),
    ('CYOW', 'CA_MX_LATAM', 1, 'Ottawa'),
    ('CYYC', 'CA_MX_LATAM', 1, 'Calgary'),
    ('CYEG', 'CA_MX_LATAM', 1, 'Edmonton'),
    ('CYWG', 'CA_MX_LATAM', 1, 'Winnipeg'),
    ('CYHZ', 'CA_MX_LATAM', 1, 'Halifax'),
    ('CYQB', 'CA_MX_LATAM', 1, 'Quebec City'),
    ('CYYJ', 'CA_MX_LATAM', 1, 'Victoria');

    -- Tier 1: Mexico Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('MMMX', 'CA_MX_LATAM', 1, 'Mexico City'),
    ('MMUN', 'CA_MX_LATAM', 1, 'Cancun'),
    ('MMTJ', 'CA_MX_LATAM', 1, 'Tijuana'),
    ('MMGL', 'CA_MX_LATAM', 1, 'Guadalajara'),
    ('MMMY', 'CA_MX_LATAM', 1, 'Monterrey'),
    ('MMPR', 'CA_MX_LATAM', 1, 'Puerto Vallarta'),
    ('MMSD', 'CA_MX_LATAM', 1, 'Los Cabos'),
    ('MMCU', 'CA_MX_LATAM', 1, 'Chihuahua');

    -- Tier 1: Brazil Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('SBGR', 'CA_MX_LATAM', 1, 'Sao Paulo Guarulhos'),
    ('SBKP', 'CA_MX_LATAM', 1, 'Campinas'),
    ('SBRJ', 'CA_MX_LATAM', 1, 'Rio Santos Dumont'),
    ('SBGL', 'CA_MX_LATAM', 1, 'Rio Galeao'),
    ('SBSP', 'CA_MX_LATAM', 1, 'Sao Paulo Congonhas'),
    ('SBSV', 'CA_MX_LATAM', 1, 'Salvador'),
    ('SBCF', 'CA_MX_LATAM', 1, 'Belo Horizonte'),
    ('SBPA', 'CA_MX_LATAM', 1, 'Porto Alegre'),
    ('SBRF', 'CA_MX_LATAM', 1, 'Recife'),
    ('SBCT', 'CA_MX_LATAM', 1, 'Curitiba'),
    ('SBBR', 'CA_MX_LATAM', 1, 'Brasilia');

    -- Tier 1: South America Other Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('SAEZ', 'CA_MX_LATAM', 1, 'Buenos Aires Ezeiza'),
    ('SABE', 'CA_MX_LATAM', 1, 'Buenos Aires Aeroparque'),
    ('SCEL', 'CA_MX_LATAM', 1, 'Santiago Chile'),
    ('SCIE', 'CA_MX_LATAM', 1, 'Concepcion'),
    ('SKBO', 'CA_MX_LATAM', 1, 'Bogota'),
    ('SKCG', 'CA_MX_LATAM', 1, 'Cartagena'),
    ('SKRG', 'CA_MX_LATAM', 1, 'Medellin'),
    ('SPJC', 'CA_MX_LATAM', 1, 'Lima'),
    ('SEQM', 'CA_MX_LATAM', 1, 'Quito'),
    ('SEGU', 'CA_MX_LATAM', 1, 'Guayaquil'),
    ('SLLP', 'CA_MX_LATAM', 1, 'La Paz'),
    ('SUMU', 'CA_MX_LATAM', 1, 'Montevideo'),
    ('SVMI', 'CA_MX_LATAM', 1, 'Caracas');

    -- Tier 1: Caribbean & Central America Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('TNCM', 'CA_MX_LATAM', 1, 'St. Maarten'),
    ('MKJP', 'CA_MX_LATAM', 1, 'Kingston Jamaica'),
    ('TBPB', 'CA_MX_LATAM', 1, 'Barbados'),
    ('TFFR', 'CA_MX_LATAM', 1, 'Guadeloupe'),
    ('TFFF', 'CA_MX_LATAM', 1, 'Martinique'),
    ('TTPP', 'CA_MX_LATAM', 1, 'Trinidad'),
    ('MDSD', 'CA_MX_LATAM', 1, 'Santo Domingo'),
    ('MDPC', 'CA_MX_LATAM', 1, 'Punta Cana'),
    ('MHLM', 'CA_MX_LATAM', 1, 'San Pedro Sula'),
    ('MGGT', 'CA_MX_LATAM', 1, 'Guatemala City'),
    ('MROC', 'CA_MX_LATAM', 1, 'San Jose Costa Rica'),
    ('MPTO', 'CA_MX_LATAM', 1, 'Panama City'),
    ('MUHA', 'CA_MX_LATAM', 1, 'Havana'),
    ('MWCR', 'CA_MX_LATAM', 1, 'Grand Cayman'),
    ('TNCA', 'CA_MX_LATAM', 1, 'Aruba'),
    ('TNCB', 'CA_MX_LATAM', 1, 'Bonaire'),
    ('TNCC', 'CA_MX_LATAM', 1, 'Curacao');

    -- Tier 2: UK Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('EGLL', 'GLOBAL', 2, 'London Heathrow'),
    ('EGKK', 'GLOBAL', 2, 'London Gatwick'),
    ('EGSS', 'GLOBAL', 2, 'London Stansted'),
    ('EGGW', 'GLOBAL', 2, 'London Luton'),
    ('EGLC', 'GLOBAL', 2, 'London City'),
    ('EGCC', 'GLOBAL', 2, 'Manchester'),
    ('EGBB', 'GLOBAL', 2, 'Birmingham'),
    ('EGPH', 'GLOBAL', 2, 'Edinburgh'),
    ('EGPF', 'GLOBAL', 2, 'Glasgow');

    -- Tier 2: France Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('LFPG', 'GLOBAL', 2, 'Paris CDG'),
    ('LFPO', 'GLOBAL', 2, 'Paris Orly'),
    ('LFOB', 'GLOBAL', 2, 'Paris Beauvais'),
    ('LFML', 'GLOBAL', 2, 'Marseille'),
    ('LFLL', 'GLOBAL', 2, 'Lyon'),
    ('LFBD', 'GLOBAL', 2, 'Bordeaux'),
    ('LFMN', 'GLOBAL', 2, 'Nice');

    -- Tier 2: Germany Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('EDDF', 'GLOBAL', 2, 'Frankfurt'),
    ('EDDM', 'GLOBAL', 2, 'Munich'),
    ('EDDB', 'GLOBAL', 2, 'Berlin'),
    ('EDDL', 'GLOBAL', 2, 'Dusseldorf'),
    ('EDDH', 'GLOBAL', 2, 'Hamburg'),
    ('EDDK', 'GLOBAL', 2, 'Cologne'),
    ('EDDS', 'GLOBAL', 2, 'Stuttgart');

    -- Tier 2: Other Europe Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('LEMD', 'GLOBAL', 2, 'Madrid'),
    ('LEBL', 'GLOBAL', 2, 'Barcelona'),
    ('LEPA', 'GLOBAL', 2, 'Palma de Mallorca'),
    ('LEMG', 'GLOBAL', 2, 'Malaga'),
    ('LIRF', 'GLOBAL', 2, 'Rome Fiumicino'),
    ('LIMC', 'GLOBAL', 2, 'Milan Malpensa'),
    ('LIMF', 'GLOBAL', 2, 'Turin'),
    ('LIPZ', 'GLOBAL', 2, 'Venice'),
    ('EHAM', 'GLOBAL', 2, 'Amsterdam'),
    ('EBBR', 'GLOBAL', 2, 'Brussels'),
    ('LSZH', 'GLOBAL', 2, 'Zurich'),
    ('LSGG', 'GLOBAL', 2, 'Geneva'),
    ('LOWW', 'GLOBAL', 2, 'Vienna'),
    ('LKPR', 'GLOBAL', 2, 'Prague'),
    ('EPWA', 'GLOBAL', 2, 'Warsaw'),
    ('EPKK', 'GLOBAL', 2, 'Krakow'),
    ('EFHK', 'GLOBAL', 2, 'Helsinki'),
    ('ESSA', 'GLOBAL', 2, 'Stockholm'),
    ('ENGM', 'GLOBAL', 2, 'Oslo'),
    ('EKCH', 'GLOBAL', 2, 'Copenhagen'),
    ('EIDW', 'GLOBAL', 2, 'Dublin'),
    ('LPPT', 'GLOBAL', 2, 'Lisbon'),
    ('LPPR', 'GLOBAL', 2, 'Porto'),
    ('LGAV', 'GLOBAL', 2, 'Athens'),
    ('LTFM', 'GLOBAL', 2, 'Istanbul'),
    ('LTBA', 'GLOBAL', 2, 'Istanbul Ataturk'),
    ('UUEE', 'GLOBAL', 2, 'Moscow SVO'),
    ('UUDD', 'GLOBAL', 2, 'Moscow DME'),
    ('ULLI', 'GLOBAL', 2, 'St Petersburg');

    -- Tier 2: Asia Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('RJTT', 'GLOBAL', 2, 'Tokyo Haneda'),
    ('RJAA', 'GLOBAL', 2, 'Tokyo Narita'),
    ('RJBB', 'GLOBAL', 2, 'Osaka Kansai'),
    ('RJGG', 'GLOBAL', 2, 'Nagoya'),
    ('RJCC', 'GLOBAL', 2, 'Sapporo'),
    ('RJFF', 'GLOBAL', 2, 'Fukuoka'),
    ('RKSI', 'GLOBAL', 2, 'Seoul Incheon'),
    ('RKSS', 'GLOBAL', 2, 'Seoul Gimpo'),
    ('RCTP', 'GLOBAL', 2, 'Taipei Taoyuan'),
    ('RCSS', 'GLOBAL', 2, 'Taipei Songshan'),
    ('VHHH', 'GLOBAL', 2, 'Hong Kong'),
    ('VMMC', 'GLOBAL', 2, 'Macau'),
    ('WSSS', 'GLOBAL', 2, 'Singapore'),
    ('WIII', 'GLOBAL', 2, 'Jakarta'),
    ('WADD', 'GLOBAL', 2, 'Bali'),
    ('VTBS', 'GLOBAL', 2, 'Bangkok Suvarnabhumi'),
    ('VTBD', 'GLOBAL', 2, 'Bangkok Don Mueang'),
    ('VVNB', 'GLOBAL', 2, 'Hanoi'),
    ('VVTS', 'GLOBAL', 2, 'Ho Chi Minh'),
    ('RPLL', 'GLOBAL', 2, 'Manila'),
    ('WMKK', 'GLOBAL', 2, 'Kuala Lumpur'),
    ('ZBAA', 'GLOBAL', 2, 'Beijing Capital'),
    ('ZBAD', 'GLOBAL', 2, 'Beijing Daxing'),
    ('ZSPD', 'GLOBAL', 2, 'Shanghai Pudong'),
    ('ZSSS', 'GLOBAL', 2, 'Shanghai Hongqiao'),
    ('ZGGG', 'GLOBAL', 2, 'Guangzhou'),
    ('ZGSZ', 'GLOBAL', 2, 'Shenzhen'),
    ('VDPP', 'GLOBAL', 2, 'Phnom Penh'),
    ('VRMM', 'GLOBAL', 2, 'Male Maldives'),
    ('VABB', 'GLOBAL', 2, 'Mumbai'),
    ('VIDP', 'GLOBAL', 2, 'Delhi'),
    ('VOBL', 'GLOBAL', 2, 'Bangalore'),
    ('VOMM', 'GLOBAL', 2, 'Chennai'),
    ('VECC', 'GLOBAL', 2, 'Kolkata');

    -- Tier 2: Middle East Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('OMDB', 'GLOBAL', 2, 'Dubai'),
    ('OMDW', 'GLOBAL', 2, 'Dubai Al Maktoum'),
    ('OMAA', 'GLOBAL', 2, 'Abu Dhabi'),
    ('OERK', 'GLOBAL', 2, 'Riyadh'),
    ('OEJN', 'GLOBAL', 2, 'Jeddah'),
    ('OEDF', 'GLOBAL', 2, 'Dammam'),
    ('OTHH', 'GLOBAL', 2, 'Doha'),
    ('OBBI', 'GLOBAL', 2, 'Bahrain'),
    ('OKBK', 'GLOBAL', 2, 'Kuwait'),
    ('OOMS', 'GLOBAL', 2, 'Muscat'),
    ('LLBG', 'GLOBAL', 2, 'Tel Aviv'),
    ('OIIE', 'GLOBAL', 2, 'Tehran'),
    ('ORBI', 'GLOBAL', 2, 'Baghdad'),
    ('OJAM', 'GLOBAL', 2, 'Amman');

    -- Tier 2: Africa Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('FACT', 'GLOBAL', 2, 'Cape Town'),
    ('FAOR', 'GLOBAL', 2, 'Johannesburg'),
    ('HECA', 'GLOBAL', 2, 'Cairo'),
    ('GMMN', 'GLOBAL', 2, 'Casablanca'),
    ('DTTA', 'GLOBAL', 2, 'Tunis'),
    ('DAAG', 'GLOBAL', 2, 'Algiers'),
    ('DNMM', 'GLOBAL', 2, 'Lagos'),
    ('HKJK', 'GLOBAL', 2, 'Nairobi'),
    ('HAAB', 'GLOBAL', 2, 'Addis Ababa'),
    ('GOBD', 'GLOBAL', 2, 'Dakar'),
    ('FMEE', 'GLOBAL', 2, 'Mauritius');

    -- Tier 2: Oceania Major
    INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
    ('YSSY', 'GLOBAL', 2, 'Sydney'),
    ('YMML', 'GLOBAL', 2, 'Melbourne'),
    ('YBBN', 'GLOBAL', 2, 'Brisbane'),
    ('YPAD', 'GLOBAL', 2, 'Adelaide'),
    ('YPPH', 'GLOBAL', 2, 'Perth'),
    ('YSCB', 'GLOBAL', 2, 'Canberra'),
    ('NZAA', 'GLOBAL', 2, 'Auckland'),
    ('NZCH', 'GLOBAL', 2, 'Christchurch'),
    ('NZWN', 'GLOBAL', 2, 'Wellington'),
    ('NFFN', 'GLOBAL', 2, 'Fiji Nadi');

    PRINT 'Created ref_major_airports table with airport classifications';
END
ELSE
    PRINT 'ref_major_airports table already exists';
GO

-- Index for fast lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_ref_major_airports_tier')
    CREATE INDEX IX_ref_major_airports_tier ON dbo.ref_major_airports (tier);
GO

-- =====================================================
-- 2. FUNCTION: Get Airport Cleanup Tier
-- Returns the cleanup tier for a given airport
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetAtisCleanupTier') AND type = 'FN')
    DROP FUNCTION dbo.fn_GetAtisCleanupTier;
GO

CREATE FUNCTION dbo.fn_GetAtisCleanupTier(
    @airport_icao VARCHAR(4),
    @check_time DATETIME2 = NULL
)
RETURNS TINYINT
AS
BEGIN
    DECLARE @tier TINYINT = 4;  -- Default: global non-major (1 hour)
    DECLARE @prefix CHAR(1);

    -- Use current time if not specified
    IF @check_time IS NULL
        SET @check_time = GETUTCDATE();

    -- Check if airport is in major airports table
    SELECT @tier = tier
    FROM dbo.ref_major_airports
    WHERE airport_icao = @airport_icao;

    -- If found in reference table, use that tier
    IF @tier IS NOT NULL AND @tier <= 2
        RETURN @tier;

    -- Check if airport is featured during a VATUSA event (T-1H to T+6H)
    IF EXISTS (
        SELECT 1
        FROM dbo.vatusa_event_airport ea
        JOIN dbo.vatusa_event e ON ea.event_idx = e.event_idx
        WHERE ea.airport_icao = @airport_icao
          AND ea.is_featured = 1
          AND @check_time >= DATEADD(HOUR, -1, e.start_utc)
          AND @check_time <= DATEADD(HOUR, 6, e.end_utc)
    )
        RETURN 0;  -- Featured during event = Tier 0

    -- Determine tier by region prefix
    SET @prefix = LEFT(@airport_icao, 1);

    -- Tier 3: US/CA/MX/LATAM/Caribbean non-major (24 hours)
    IF @prefix IN ('K', 'P', 'C', 'M', 'S', 'T')
        RETURN 3;

    -- Tier 4: Global non-major (1 hour)
    RETURN 4;
END;
GO

PRINT 'Created fn_GetAtisCleanupTier function';
GO

-- =====================================================
-- 3. UPDATED CLEANUP PROCEDURE: Tiered Retention
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_CleanupOldAtis')
    DROP PROCEDURE dbo.sp_CleanupOldAtis;
GO

CREATE PROCEDURE dbo.sp_CleanupOldAtis
    @dry_run BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2 = GETUTCDATE();
    DECLARE @deleted_tier1 INT = 0;
    DECLARE @deleted_tier2 INT = 0;
    DECLARE @deleted_tier3 INT = 0;
    DECLARE @deleted_tier4 INT = 0;
    DECLARE @deleted_history INT = 0;

    -- Tier retention cutoffs
    DECLARE @cutoff_tier1 DATETIME2 = DATEADD(DAY, -30, @now);    -- 30 days
    DECLARE @cutoff_tier2 DATETIME2 = DATEADD(DAY, -7, @now);     -- 7 days
    DECLARE @cutoff_tier3 DATETIME2 = DATEADD(HOUR, -24, @now);   -- 24 hours
    DECLARE @cutoff_tier4 DATETIME2 = DATEADD(HOUR, -1, @now);    -- 1 hour

    IF @dry_run = 1
    BEGIN
        -- Preview mode: count what would be deleted
        SELECT
            @deleted_tier1 = COUNT(CASE WHEN dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 1
                                        AND fetched_utc < @cutoff_tier1 THEN 1 END),
            @deleted_tier2 = COUNT(CASE WHEN dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 2
                                        AND fetched_utc < @cutoff_tier2 THEN 1 END),
            @deleted_tier3 = COUNT(CASE WHEN dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 3
                                        AND fetched_utc < @cutoff_tier3 THEN 1 END),
            @deleted_tier4 = COUNT(CASE WHEN dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 4
                                        AND fetched_utc < @cutoff_tier4 THEN 1 END)
        FROM dbo.vatsim_atis;

        SELECT
            @deleted_history = COUNT(*)
        FROM dbo.atis_config_history
        WHERE superseded_utc < DATEADD(DAY, -30, @now);
    END
    ELSE
    BEGIN
        -- Execute cleanup

        -- Tier 1: 30-day retention (CA/MX/LATAM major)
        DELETE FROM dbo.vatsim_atis
        WHERE dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 1
          AND fetched_utc < @cutoff_tier1;
        SET @deleted_tier1 = @@ROWCOUNT;

        -- Tier 2: 7-day retention (Global major)
        DELETE FROM dbo.vatsim_atis
        WHERE dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 2
          AND fetched_utc < @cutoff_tier2;
        SET @deleted_tier2 = @@ROWCOUNT;

        -- Tier 3: 24-hour retention (US/CA/MX/LATAM/CAR non-major)
        DELETE FROM dbo.vatsim_atis
        WHERE dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 3
          AND fetched_utc < @cutoff_tier3;
        SET @deleted_tier3 = @@ROWCOUNT;

        -- Tier 4: 1-hour retention (Global non-major)
        DELETE FROM dbo.vatsim_atis
        WHERE dbo.fn_GetAtisCleanupTier(airport_icao, fetched_utc) = 4
          AND fetched_utc < @cutoff_tier4;
        SET @deleted_tier4 = @@ROWCOUNT;

        -- Cleanup config history (30 days for superseded)
        DELETE FROM dbo.atis_config_history
        WHERE superseded_utc < DATEADD(DAY, -30, @now);
        SET @deleted_history = @@ROWCOUNT;
    END

    -- Return results
    SELECT
        @dry_run AS dry_run,
        @deleted_tier1 AS deleted_tier1_30d,
        @deleted_tier2 AS deleted_tier2_7d,
        @deleted_tier3 AS deleted_tier3_24h,
        @deleted_tier4 AS deleted_tier4_1h,
        @deleted_tier1 + @deleted_tier2 + @deleted_tier3 + @deleted_tier4 AS deleted_atis_total,
        @deleted_history AS deleted_history,
        (SELECT COUNT(*) FROM dbo.vatsim_atis) AS remaining_atis,
        (SELECT COUNT(*) FROM dbo.atis_config_history) AS remaining_history;
END;
GO

PRINT 'Created sp_CleanupOldAtis procedure with tiered retention';
GO

-- =====================================================
-- 4. VIEW: Current ATIS Stats by Tier
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_atis_stats_by_tier')
    DROP VIEW dbo.vw_atis_stats_by_tier;
GO

CREATE VIEW dbo.vw_atis_stats_by_tier AS
SELECT
    dbo.fn_GetAtisCleanupTier(a.airport_icao, a.fetched_utc) AS cleanup_tier,
    COUNT(*) AS atis_count,
    COUNT(CASE WHEN a.parse_status = 'PENDING' THEN 1 END) AS pending,
    COUNT(CASE WHEN a.parse_status = 'PARSED' THEN 1 END) AS parsed,
    COUNT(CASE WHEN a.parse_status = 'FAILED' THEN 1 END) AS failed,
    MIN(a.fetched_utc) AS oldest_record,
    MAX(a.fetched_utc) AS newest_record
FROM dbo.vatsim_atis a
GROUP BY dbo.fn_GetAtisCleanupTier(a.airport_icao, a.fetched_utc);
GO

PRINT 'Created vw_atis_stats_by_tier view';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '087_atis_tiered_cleanup.sql completed successfully';
PRINT '';
PRINT 'Tier Structure:';
PRINT '  Tier 0: Never delete (ASPM82 + Event featured airports)';
PRINT '  Tier 1: 30-day retention (CA/MX/LATAM/Caribbean majors)';
PRINT '  Tier 2: 7-day retention (Global majors)';
PRINT '  Tier 3: 24-hour retention (Americas non-major)';
PRINT '  Tier 4: 1-hour retention (Global non-major)';
PRINT '';
PRINT 'Objects created:';
PRINT '  - ref_major_airports: Reference table with ~200+ major airports';
PRINT '  - fn_GetAtisCleanupTier: Function to determine cleanup tier';
PRINT '  - sp_CleanupOldAtis: Updated procedure with tiered cleanup';
PRINT '  - vw_atis_stats_by_tier: View for monitoring by tier';
GO
