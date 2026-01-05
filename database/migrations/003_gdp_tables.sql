-- ============================================================================
-- PERTI Ground Delay Program (GDP) - Database Migration (SQL Server / Azure SQL)
-- Version: 1.0
-- Date: December 2024
-- 
-- Creates:
--   1. gdp_log - GDP program configuration, state, and metrics
--   2. adl_slots_gdp - Slot allocation tracking (15-min bins)
--   3. adl_flights_gdp - Sandbox table for GDP simulation
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Table: gdp_log
-- Stores GDP program configuration and status (one row per GDP)
-- Status values: DRAFT, SIMULATED, ACTIVE, EXPIRED, PURGED
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'gdp_log')
BEGIN
    CREATE TABLE dbo.gdp_log (
        id INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Program identification
        program_id NVARCHAR(50) NOT NULL,           -- e.g., "GDP-KATL-001"
        ctl_element NVARCHAR(10) NOT NULL,          -- Destination airport (KATL)
        adv_number NVARCHAR(10) NULL,               -- Advisory number
        
        -- Program timing
        program_start_utc DATETIME2 NOT NULL,
        program_end_utc DATETIME2 NOT NULL,
        created_utc DATETIME2 DEFAULT GETUTCDATE(),
        modified_utc DATETIME2 NULL,
        
        -- Program parameters
        program_rate INT NOT NULL,                  -- Base arrivals per hour (simple mode)
        program_rates_hourly NVARCHAR(MAX) NULL,    -- JSON: {"14":40,"15":35,...} (detailed mode)
        reserve_rate INT DEFAULT 0,                 -- Base reserved slots per hour (for pop-ups)
        reserve_rates_hourly NVARCHAR(MAX) NULL,    -- JSON for detailed mode
        
        -- UDP parameters
        delay_limit_minutes INT DEFAULT 180,        -- Max delay cap
        
        -- Scope (space-delimited codes)
        scope_centers NVARCHAR(MAX) NULL,           -- ZTL ZJX ZMA...
        scope_airports NVARCHAR(MAX) NULL,          -- Origin airports filter
        scope_carriers NVARCHAR(MAX) NULL,          -- Carrier filter
        scope_aircraft_type NVARCHAR(20) NULL,      -- JET/PROP/ALL
        scope_tier NVARCHAR(50) NULL,               -- Tier selection
        scope_distance_nm INT NULL,                 -- Distance-based scope
        
        -- Exemption criteria (JSON)
        exemptions NVARCHAR(MAX) NULL,
        
        -- Status: DRAFT, SIMULATED, ACTIVE, EXPIRED, PURGED
        status NVARCHAR(20) DEFAULT 'DRAFT',
        
        -- Advisory/Causal
        impacting_condition NVARCHAR(200) NULL,
        probability_of_extension NVARCHAR(20) NULL,
        advisory_text NVARCHAR(MAX) NULL,
        
        -- Metrics (updated on each simulation/refresh)
        total_flights INT NULL,
        affected_flights INT NULL,
        exempt_flights INT NULL,
        total_delay_min INT NULL,
        max_delay_min INT NULL,
        avg_delay_min DECIMAL(10,2) NULL,
        flights_in_stack INT NULL,
        slot_utilization DECIMAL(5,2) NULL,         -- Percentage of slots filled
        
        -- Audit
        created_by NVARCHAR(100) NULL,
        modified_by NVARCHAR(100) NULL,
        
        CONSTRAINT UQ_gdp_log_program_id UNIQUE (program_id)
    );
    
    CREATE INDEX IX_gdp_log_element ON dbo.gdp_log(ctl_element, status);
    CREATE INDEX IX_gdp_log_status ON dbo.gdp_log(status);
    
    PRINT 'Created table: gdp_log';
END
ELSE
BEGIN
    PRINT 'Table gdp_log already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Table: adl_slots_gdp
-- Stores individual arrival slots for each GDP (many rows per program)
-- Slot types: REGULAR, RESERVED (for pop-up traffic)
-- Slot status: OPEN, ASSIGNED, CANCELLED, COMPRESSED
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_slots_gdp')
BEGIN
    CREATE TABLE dbo.adl_slots_gdp (
        id INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Program reference
        program_id NVARCHAR(50) NOT NULL,
        
        -- Slot identification
        slot_time_utc DATETIME2 NOT NULL,           -- Arrival slot time (CTA)
        slot_index INT NOT NULL,                    -- Sequential slot number (1, 2, 3...)
        bin_hour INT NULL,                          -- Hour bucket (14, 15, 16...)
        bin_quarter INT NULL,                       -- Quarter within hour (0, 15, 30, 45)
        
        -- Assignment
        assigned_flight_key NVARCHAR(100) NULL,     -- FK to adl_flights.flight_key
        assigned_callsign NVARCHAR(20) NULL,
        assigned_carrier NVARCHAR(10) NULL,
        assigned_origin NVARCHAR(10) NULL,          -- Origin airport
        assigned_utc DATETIME2 NULL,                -- When assignment was made
        
        -- Slot type (UDP support)
        slot_type NVARCHAR(20) DEFAULT 'REGULAR',   -- REGULAR, RESERVED
        
        -- Slot status
        slot_status NVARCHAR(20) DEFAULT 'OPEN',    -- OPEN, ASSIGNED, CANCELLED, COMPRESSED
        
        -- Compression tracking
        original_flight_key NVARCHAR(100) NULL,     -- Original assignment before compression
        original_callsign NVARCHAR(20) NULL,
        compression_count INT DEFAULT 0,            -- Times this slot was reassigned
        last_compressed_utc DATETIME2 NULL,
        
        -- Timestamps
        created_utc DATETIME2 DEFAULT GETUTCDATE(),
        modified_utc DATETIME2 NULL,
        
        CONSTRAINT UQ_adl_slots_gdp UNIQUE (program_id, slot_index)
    );
    
    CREATE INDEX IX_adl_slots_gdp_time ON dbo.adl_slots_gdp(program_id, slot_time_utc);
    CREATE INDEX IX_adl_slots_gdp_flight ON dbo.adl_slots_gdp(assigned_flight_key);
    CREATE INDEX IX_adl_slots_gdp_status ON dbo.adl_slots_gdp(program_id, slot_status);
    CREATE INDEX IX_adl_slots_gdp_bin ON dbo.adl_slots_gdp(program_id, bin_hour, bin_quarter);
    
    PRINT 'Created table: adl_slots_gdp';
END
ELSE
BEGIN
    PRINT 'Table adl_slots_gdp already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Table: adl_flights_gdp
-- Sandbox table for GDP simulation (mirrors adl_flights_gs structure)
-- Used for preview/simulate workflow before applying to live adl_flights
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flights_gdp')
BEGIN
    -- Create by copying structure from adl_flights_gs (which mirrors adl_flights)
    -- Plus GDP-specific columns
    SELECT TOP 0 *
    INTO dbo.adl_flights_gdp
    FROM dbo.adl_flights_gs;
    
    -- Add GDP-specific columns
    ALTER TABLE dbo.adl_flights_gdp ADD
        gdp_program_id NVARCHAR(50) NULL,
        gdp_slot_index INT NULL,
        gdp_slot_time_utc DATETIME2 NULL,
        gdp_original_eta_utc DATETIME2 NULL,        -- ETA before GDP control
        gdp_scope_id INT NULL,
        gdp_scope_user NVARCHAR(64) NULL,
        gdp_scope_created_utc DATETIME2 NULL;
    
    -- Create unique index on flight_key (one entry per flight in sandbox)
    CREATE UNIQUE INDEX UQ_adl_flights_gdp_flight_key 
        ON dbo.adl_flights_gdp(flight_key) 
        WHERE flight_key IS NOT NULL;
    
    -- Create index on GDP program for filtering
    CREATE INDEX IX_adl_flights_gdp_program ON dbo.adl_flights_gdp(gdp_program_id);
    
    -- Create index on destination airport (GDP filters by arrival)
    CREATE INDEX IX_adl_flights_gdp_dest ON dbo.adl_flights_gdp(fp_dest_icao);
    
    -- Create index on ETA for slot allocation ordering
    CREATE INDEX IX_adl_flights_gdp_eta ON dbo.adl_flights_gdp(eta_runway_utc);
    
    PRINT 'Created table: adl_flights_gdp (copied from adl_flights_gs structure)';
END
ELSE
BEGIN
    PRINT 'Table adl_flights_gdp already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Add GDP columns to adl_flights if not present
-- These columns track GDP assignment on live flights
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gdp_program_id')
BEGIN
    ALTER TABLE dbo.adl_flights ADD gdp_program_id NVARCHAR(50) NULL;
    PRINT 'Added column gdp_program_id to adl_flights';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gdp_slot_index')
BEGIN
    ALTER TABLE dbo.adl_flights ADD gdp_slot_index INT NULL;
    PRINT 'Added column gdp_slot_index to adl_flights';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'gdp_slot_time_utc')
BEGIN
    ALTER TABLE dbo.adl_flights ADD gdp_slot_time_utc DATETIME2 NULL;
    PRINT 'Added column gdp_slot_time_utc to adl_flights';
END
GO

-- ----------------------------------------------------------------------------
-- Verify migration completed
-- ----------------------------------------------------------------------------

SELECT 
    'gdp_log' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'gdp_log'
UNION ALL
SELECT 
    'adl_slots_gdp' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'adl_slots_gdp'
UNION ALL
SELECT 
    'adl_flights_gdp' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'adl_flights_gdp';

PRINT '============================================================================';
PRINT 'GDP Migration Complete';
PRINT '============================================================================';
GO
