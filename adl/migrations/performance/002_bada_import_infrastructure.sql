-- ============================================================================
-- 046_bada_import_infrastructure.sql
-- Infrastructure for EUROCONTROL BADA data import
-- 
-- BADA provides performance data at each flight level, which enables:
-- - More accurate climb/descent modeling
-- - Altitude-specific speeds
-- - Proper crossover altitude handling
--
-- Date: 2026-01-07
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Creating BADA Import Infrastructure ===';
PRINT 'Timestamp: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- STEP 1: Create altitude-specific performance table (PTF data)
-- This stores the full BADA PTF performance tables
-- ============================================================================

IF OBJECT_ID('dbo.aircraft_performance_ptf', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.aircraft_performance_ptf (
        ptf_id              INT IDENTITY(1,1) PRIMARY KEY,
        aircraft_icao       NVARCHAR(8) NOT NULL,
        flight_level        INT NOT NULL,           -- FL in 100s (e.g., 350 = FL350)
        
        -- Climb performance at this FL
        climb_tas_kts       INT NULL,               -- True airspeed during climb
        climb_rocd_fpm      INT NULL,               -- Rate of climb (ft/min)
        climb_fuel_kg_min   DECIMAL(8,2) NULL,      -- Fuel flow during climb
        
        -- Cruise performance at this FL
        cruise_tas_kts      INT NULL,               -- Cruise TAS
        cruise_fuel_kg_min  DECIMAL(8,2) NULL,      -- Cruise fuel flow
        
        -- Descent performance at this FL
        descent_tas_kts     INT NULL,               -- TAS during descent
        descent_rocd_fpm    INT NULL,               -- Rate of descent (ft/min, negative)
        descent_fuel_kg_min DECIMAL(8,2) NULL,      -- Fuel flow during descent
        
        -- Metadata
        mass_category       NCHAR(1) NULL,          -- L=low, N=nominal, H=high mass
        bada_revision       NVARCHAR(8) NULL,       -- e.g., '3.12', '4.2'
        source              NVARCHAR(32) NOT NULL DEFAULT 'BADA',
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        -- Composite index for fast lookups
        INDEX IX_ptf_aircraft_fl (aircraft_icao, flight_level),
        
        -- Ensure unique FL per aircraft/mass
        CONSTRAINT UQ_ptf_aircraft_fl_mass UNIQUE (aircraft_icao, flight_level, mass_category)
    );
    
    PRINT 'Created table: aircraft_performance_ptf';
END
ELSE
    PRINT 'Table aircraft_performance_ptf already exists';
GO

-- ============================================================================
-- STEP 2: Create OPF summary table (aircraft coefficients & limits)
-- This stores key parameters from OPF files
-- ============================================================================

IF OBJECT_ID('dbo.aircraft_performance_opf', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.aircraft_performance_opf (
        opf_id              INT IDENTITY(1,1) PRIMARY KEY,
        aircraft_icao       NVARCHAR(8) NOT NULL UNIQUE,
        
        -- Aircraft identification
        manufacturer        NVARCHAR(64) NULL,
        model_name          NVARCHAR(64) NULL,
        engine_type         NVARCHAR(16) NULL,      -- Jet, Turboprop, Piston
        wake_category       NCHAR(1) NULL,          -- H, M, L, J
        num_engines         TINYINT NULL,
        
        -- Mass limits (kg)
        mass_ref_kg         INT NULL,               -- Reference mass
        mass_min_kg         INT NULL,               -- Operating empty weight
        mass_max_kg         INT NULL,               -- MTOW
        mass_payload_kg     INT NULL,               -- Max payload
        
        -- Speed limits
        vmo_kts             INT NULL,               -- Max operating speed (CAS)
        mmo                 DECIMAL(3,2) NULL,      -- Max operating Mach
        
        -- Altitude limits
        max_altitude_ft     INT NULL,               -- Service ceiling
        
        -- Stall speeds (KCAS) by configuration
        vstall_cr           INT NULL,               -- Clean/cruise
        vstall_ic           INT NULL,               -- Initial climb
        vstall_to           INT NULL,               -- Takeoff
        vstall_ap           INT NULL,               -- Approach
        vstall_ld           INT NULL,               -- Landing
        
        -- Buffet coefficient (for high altitude performance)
        buffet_gradient     DECIMAL(6,4) NULL,
        
        -- Metadata
        bada_revision       NVARCHAR(8) NULL,
        source              NVARCHAR(32) NOT NULL DEFAULT 'BADA',
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    
    PRINT 'Created table: aircraft_performance_opf';
END
ELSE
    PRINT 'Table aircraft_performance_opf already exists';
GO

-- ============================================================================
-- STEP 3: Create APF table (airline procedure speeds)
-- Speed schedules for climb, cruise, descent
-- ============================================================================

IF OBJECT_ID('dbo.aircraft_performance_apf', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.aircraft_performance_apf (
        apf_id              INT IDENTITY(1,1) PRIMARY KEY,
        aircraft_icao       NVARCHAR(8) NOT NULL UNIQUE,
        
        -- Climb speed schedule
        climb_cas_1_kts     INT NULL,               -- Below 10,000 ft (typically 250)
        climb_cas_2_kts     INT NULL,               -- 10,000 to crossover
        climb_mach          DECIMAL(3,2) NULL,      -- Above crossover altitude
        climb_crossover_ft  INT NULL,               -- Altitude where CAS->Mach transition
        
        -- Cruise speed schedule  
        cruise_cas_kts      INT NULL,               -- Cruise CAS (below crossover)
        cruise_mach         DECIMAL(3,2) NULL,      -- Cruise Mach number
        cruise_crossover_ft INT NULL,               -- Cruise crossover altitude
        
        -- Descent speed schedule
        descent_mach        DECIMAL(3,2) NULL,      -- High altitude descent Mach
        descent_cas_1_kts   INT NULL,               -- Descent CAS (10,000-crossover)
        descent_cas_2_kts   INT NULL,               -- Below 10,000 ft (typically 250)
        descent_crossover_ft INT NULL,              -- Descent crossover altitude
        
        -- Approach/landing
        approach_cas_kts    INT NULL,               -- Final approach speed
        
        -- Metadata
        bada_revision       NVARCHAR(8) NULL,
        source              NVARCHAR(32) NOT NULL DEFAULT 'BADA',
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    
    PRINT 'Created table: aircraft_performance_apf';
END
ELSE
    PRINT 'Table aircraft_performance_apf already exists';
GO

-- ============================================================================
-- STEP 4: Add BADA-specific columns to existing profiles table
-- ============================================================================

-- Add crossover altitude if not exists
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'climb_crossover_ft')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD climb_crossover_ft INT NULL;
    PRINT 'Added column: climb_crossover_ft';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'descent_crossover_ft')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD descent_crossover_ft INT NULL;
    PRINT 'Added column: descent_crossover_ft';
END

-- Add max altitude
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'max_altitude_ft')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD max_altitude_ft INT NULL;
    PRINT 'Added column: max_altitude_ft';
END

-- Add VMO/MMO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'vmo_kts')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD vmo_kts INT NULL;
    PRINT 'Added column: vmo_kts';
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'mmo')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD mmo DECIMAL(3,2) NULL;
    PRINT 'Added column: mmo';
END

-- Add approach speed
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'approach_speed_kias')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD approach_speed_kias INT NULL;
    PRINT 'Added column: approach_speed_kias';
END

-- Add BADA revision tracking
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.aircraft_performance_profiles') AND name = 'bada_revision')
BEGIN
    ALTER TABLE dbo.aircraft_performance_profiles ADD bada_revision NVARCHAR(8) NULL;
    PRINT 'Added column: bada_revision';
END
GO

-- ============================================================================
-- STEP 5: Create staging table for PTF import
-- ============================================================================

IF OBJECT_ID('dbo.bada_import_staging', 'U') IS NOT NULL
    DROP TABLE dbo.bada_import_staging;

CREATE TABLE dbo.bada_import_staging (
    staging_id      INT IDENTITY(1,1) PRIMARY KEY,
    file_name       NVARCHAR(64) NOT NULL,
    file_type       NVARCHAR(8) NOT NULL,           -- 'PTF', 'OPF', 'APF'
    aircraft_icao   NVARCHAR(8) NULL,
    line_number     INT NULL,
    raw_line        NVARCHAR(512) NULL,
    parsed_json     NVARCHAR(MAX) NULL,             -- Parsed values as JSON
    import_status   NVARCHAR(16) DEFAULT 'PENDING', -- PENDING, PROCESSED, ERROR
    error_message   NVARCHAR(256) NULL,
    imported_utc    DATETIME2(0) NULL,
    created_utc     DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
);

PRINT 'Created staging table: bada_import_staging';
GO

-- ============================================================================
-- STEP 6: Create import log table
-- ============================================================================

IF OBJECT_ID('dbo.bada_import_log', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.bada_import_log (
        log_id          INT IDENTITY(1,1) PRIMARY KEY,
        import_batch    UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        bada_revision   NVARCHAR(8) NOT NULL,
        file_type       NVARCHAR(8) NOT NULL,
        files_processed INT NOT NULL DEFAULT 0,
        records_inserted INT NOT NULL DEFAULT 0,
        records_updated INT NOT NULL DEFAULT 0,
        errors_count    INT NOT NULL DEFAULT 0,
        started_utc     DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        completed_utc   DATETIME2(0) NULL,
        status          NVARCHAR(16) NOT NULL DEFAULT 'RUNNING',
        notes           NVARCHAR(512) NULL
    );
    
    PRINT 'Created table: bada_import_log';
END
GO

-- ============================================================================
-- STEP 7: Create procedure to sync PTF data to summary profiles
-- This extracts key values from altitude-specific data
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_SyncBADA_ToProfiles
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Extract summary performance from PTF data for each aircraft
    -- Use FL350 as reference cruise altitude for jets, FL250 for turboprops
    
    ;WITH ProfileSummary AS (
        SELECT 
            p.aircraft_icao,
            
            -- Climb: use FL200-250 average
            AVG(CASE WHEN p.flight_level BETWEEN 200 AND 250 THEN p.climb_rocd_fpm END) AS climb_rate_fpm,
            AVG(CASE WHEN p.flight_level BETWEEN 100 AND 200 THEN p.climb_tas_kts END) AS climb_speed_kias,
            
            -- Cruise: use FL350-390 for jets
            AVG(CASE WHEN p.flight_level BETWEEN 350 AND 390 THEN p.cruise_tas_kts END) AS cruise_speed_ktas,
            
            -- Descent: use FL200-250 average
            AVG(CASE WHEN p.flight_level BETWEEN 200 AND 250 THEN ABS(p.descent_rocd_fpm) END) AS descent_rate_fpm,
            AVG(CASE WHEN p.flight_level BETWEEN 100 AND 200 THEN p.descent_tas_kts END) AS descent_speed_kias,
            
            -- Optimal FL: highest FL with positive climb rate
            MAX(CASE WHEN p.climb_rocd_fpm > 300 THEN p.flight_level END) AS optimal_fl,
            
            MAX(p.bada_revision) AS bada_revision
            
        FROM dbo.aircraft_performance_ptf p
        WHERE p.mass_category = 'N'
        GROUP BY p.aircraft_icao
    )
    MERGE dbo.aircraft_performance_profiles AS target
    USING ProfileSummary AS source
    ON target.aircraft_icao = source.aircraft_icao
    WHEN MATCHED AND target.source IN ('BADA', 'DEFAULT', 'SEED') THEN
        UPDATE SET
            climb_rate_fpm = ISNULL(source.climb_rate_fpm, target.climb_rate_fpm),
            climb_speed_kias = ISNULL(source.climb_speed_kias, target.climb_speed_kias),
            cruise_speed_ktas = ISNULL(source.cruise_speed_ktas, target.cruise_speed_ktas),
            descent_rate_fpm = ISNULL(source.descent_rate_fpm, target.descent_rate_fpm),
            descent_speed_kias = ISNULL(source.descent_speed_kias, target.descent_speed_kias),
            optimal_fl = ISNULL(source.optimal_fl, target.optimal_fl),
            bada_revision = source.bada_revision,
            source = 'BADA',
            created_utc = SYSUTCDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (aircraft_icao, climb_rate_fpm, climb_speed_kias, cruise_speed_ktas,
                descent_rate_fpm, descent_speed_kias, optimal_fl, bada_revision, source, created_utc)
        VALUES (source.aircraft_icao, source.climb_rate_fpm, source.climb_speed_kias,
                source.cruise_speed_ktas, source.descent_rate_fpm, source.descent_speed_kias,
                source.optimal_fl, source.bada_revision, 'BADA', SYSUTCDATETIME());
    
    SELECT @@ROWCOUNT AS profiles_synced;
END
GO

PRINT 'Created procedure: sp_SyncBADA_ToProfiles';
GO

-- ============================================================================
-- STEP 8: Create enhanced performance lookup function
-- Returns altitude-specific performance when available
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_GetAircraftPerformanceAtFL(
    @aircraft_icao      NVARCHAR(8),
    @flight_level       INT,
    @phase              NVARCHAR(16)    -- 'climb', 'cruise', 'descent'
)
RETURNS @result TABLE (
    tas_kts             INT,
    rocd_fpm            INT,
    fuel_kg_min         DECIMAL(8,2),
    source              NVARCHAR(32)
)
AS
BEGIN
    DECLARE @tas INT, @rocd INT, @fuel DECIMAL(8,2);
    DECLARE @source NVARCHAR(32) = 'EXACT_FL';
    
    -- Try exact FL match first
    IF @phase = 'climb'
    BEGIN
        SELECT @tas = climb_tas_kts, @rocd = climb_rocd_fpm, @fuel = climb_fuel_kg_min
        FROM dbo.aircraft_performance_ptf
        WHERE aircraft_icao = @aircraft_icao AND flight_level = @flight_level AND mass_category = 'N';
    END
    ELSE IF @phase = 'cruise'
    BEGIN
        SELECT @tas = cruise_tas_kts, @fuel = cruise_fuel_kg_min, @rocd = 0
        FROM dbo.aircraft_performance_ptf
        WHERE aircraft_icao = @aircraft_icao AND flight_level = @flight_level AND mass_category = 'N';
    END
    ELSE IF @phase = 'descent'
    BEGIN
        SELECT @tas = descent_tas_kts, @rocd = descent_rocd_fpm, @fuel = descent_fuel_kg_min
        FROM dbo.aircraft_performance_ptf
        WHERE aircraft_icao = @aircraft_icao AND flight_level = @flight_level AND mass_category = 'N';
    END
    
    -- If no exact match, interpolate between nearest FLs
    IF @tas IS NULL
    BEGIN
        SET @source = 'INTERPOLATED';
        
        -- Get nearest lower and higher FL
        DECLARE @fl_low INT, @fl_high INT;
        DECLARE @tas_low INT, @tas_high INT, @rocd_low INT, @rocd_high INT;
        
        SELECT @fl_low = MAX(flight_level)
        FROM dbo.aircraft_performance_ptf
        WHERE aircraft_icao = @aircraft_icao AND flight_level < @flight_level AND mass_category = 'N';
        
        SELECT @fl_high = MIN(flight_level)
        FROM dbo.aircraft_performance_ptf
        WHERE aircraft_icao = @aircraft_icao AND flight_level > @flight_level AND mass_category = 'N';
        
        IF @fl_low IS NOT NULL AND @fl_high IS NOT NULL AND @phase = 'climb'
        BEGIN
            SELECT @tas_low = climb_tas_kts, @rocd_low = climb_rocd_fpm
            FROM dbo.aircraft_performance_ptf WHERE aircraft_icao = @aircraft_icao AND flight_level = @fl_low;
            
            SELECT @tas_high = climb_tas_kts, @rocd_high = climb_rocd_fpm
            FROM dbo.aircraft_performance_ptf WHERE aircraft_icao = @aircraft_icao AND flight_level = @fl_high;
            
            -- Linear interpolation
            SET @tas = @tas_low + (@tas_high - @tas_low) * (@flight_level - @fl_low) / NULLIF(@fl_high - @fl_low, 0);
            SET @rocd = @rocd_low + (@rocd_high - @rocd_low) * (@flight_level - @fl_low) / NULLIF(@fl_high - @fl_low, 0);
        END
    END
    
    -- Fallback to summary profile
    IF @tas IS NULL
    BEGIN
        SET @source = 'SUMMARY';
        
        SELECT 
            @tas = CASE @phase 
                WHEN 'climb' THEN climb_speed_kias 
                WHEN 'cruise' THEN cruise_speed_ktas 
                WHEN 'descent' THEN descent_speed_kias 
            END,
            @rocd = CASE @phase 
                WHEN 'climb' THEN climb_rate_fpm 
                WHEN 'cruise' THEN 0 
                WHEN 'descent' THEN -descent_rate_fpm 
            END
        FROM dbo.aircraft_performance_profiles
        WHERE aircraft_icao = @aircraft_icao;
    END
    
    IF @tas IS NOT NULL
        INSERT INTO @result VALUES (@tas, @rocd, @fuel, @source);
    
    RETURN;
END
GO

PRINT 'Created function: fn_GetAircraftPerformanceAtFL';
GO

-- ============================================================================
-- STEP 9: Create view for easy performance lookup
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_AircraftPerformanceSummary
AS
SELECT 
    p.aircraft_icao,
    p.climb_rate_fpm,
    p.climb_speed_kias,
    p.climb_speed_mach,
    p.cruise_speed_ktas,
    p.cruise_mach,
    p.descent_rate_fpm,
    p.descent_speed_kias,
    p.optimal_fl,
    p.weight_class,
    p.engine_type,
    p.source,
    p.bada_revision,
    CASE 
        WHEN p.source = 'BADA' THEN 1
        WHEN p.source = 'SEED' THEN 2
        WHEN p.source = 'DEFAULT' THEN 3
        ELSE 4
    END AS source_priority,
    (SELECT COUNT(*) FROM dbo.aircraft_performance_ptf ptf WHERE ptf.aircraft_icao = p.aircraft_icao) AS ptf_records
FROM dbo.aircraft_performance_profiles p;
GO

PRINT 'Created view: vw_AircraftPerformanceSummary';
GO

-- ============================================================================
-- Verification
-- ============================================================================

PRINT '';
PRINT '=== BADA Infrastructure Verification ===';
PRINT '';

SELECT 
    'aircraft_performance_ptf' AS table_name,
    (SELECT COUNT(*) FROM dbo.aircraft_performance_ptf) AS row_count
UNION ALL
SELECT 'aircraft_performance_opf', (SELECT COUNT(*) FROM dbo.aircraft_performance_opf)
UNION ALL
SELECT 'aircraft_performance_apf', (SELECT COUNT(*) FROM dbo.aircraft_performance_apf)
UNION ALL
SELECT 'bada_import_staging', (SELECT COUNT(*) FROM dbo.bada_import_staging)
UNION ALL
SELECT 'bada_import_log', (SELECT COUNT(*) FROM dbo.bada_import_log);

PRINT '';
PRINT '=== BADA Import Infrastructure Complete ===';
PRINT 'Timestamp: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
