-- ============================================================================
-- PERTI SWIM (System Wide Information Management) API - Database Migration
-- Version: 1.0
-- Date: January 2026
-- Target: Azure SQL / SQL Server
-- 
-- Creates:
--   1. swim_api_keys - API key management
--   2. swim_audit_log - API request logging
--   3. swim_subscriptions - WebSocket subscription tracking
--   4. swim_flight_cache - Unified flight record cache
--   5. swim_webhook_endpoints - Webhook registration
--
-- NOTE: This migration uses the ACTUAL adl_flights column names as verified
--       from the VATSIM_ADL_tree.json schema export.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Table: swim_api_keys
-- Stores API keys for SWIM access with tiered permissions
-- Tiers: system, partner, developer, public
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_api_keys')
BEGIN
    CREATE TABLE dbo.swim_api_keys (
        id INT IDENTITY(1,1) PRIMARY KEY,
        api_key NVARCHAR(64) NOT NULL,
        tier NVARCHAR(20) NOT NULL,              -- system, partner, developer, public
        owner_name NVARCHAR(100) NOT NULL,
        owner_email NVARCHAR(255) NOT NULL,
        source_id NVARCHAR(50) NULL,             -- Data source identifier (vatcscc, vnas, etc.)
        can_write BIT NOT NULL DEFAULT 0,
        allowed_sources NVARCHAR(MAX) NULL,      -- JSON array of allowed source IDs for writes
        ip_whitelist NVARCHAR(MAX) NULL,         -- JSON array of allowed IP addresses
        description NVARCHAR(500) NULL,
        expires_at DATETIME2 NULL,
        created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        last_used_at DATETIME2 NULL,
        is_active BIT NOT NULL DEFAULT 1,
        
        CONSTRAINT UQ_swim_api_keys_key UNIQUE (api_key)
    );
    
    CREATE NONCLUSTERED INDEX IX_swim_api_keys_lookup
    ON dbo.swim_api_keys (api_key, is_active)
    INCLUDE (tier, source_id, can_write, expires_at);
    
    PRINT 'Created table: swim_api_keys';
END
ELSE
BEGIN
    PRINT 'Table swim_api_keys already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Table: swim_audit_log
-- Audit log for API requests
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_audit_log')
BEGIN
    CREATE TABLE dbo.swim_audit_log (
        id BIGINT IDENTITY(1,1) PRIMARY KEY,
        api_key_id INT NULL,
        endpoint NVARCHAR(255) NOT NULL,
        method NVARCHAR(10) NOT NULL,
        ip_address NVARCHAR(45) NOT NULL,
        user_agent NVARCHAR(500) NULL,
        request_body_hash NVARCHAR(64) NULL,
        response_status INT NULL,
        response_time_ms INT NULL,
        request_time DATETIME2 NOT NULL DEFAULT GETUTCDATE()
    );
    
    CREATE NONCLUSTERED INDEX IX_swim_audit_log_time
    ON dbo.swim_audit_log (request_time DESC)
    INCLUDE (api_key_id, endpoint, method);
    
    CREATE NONCLUSTERED INDEX IX_swim_audit_log_key
    ON dbo.swim_audit_log (api_key_id, request_time DESC);
    
    PRINT 'Created table: swim_audit_log';
END
ELSE
BEGIN
    PRINT 'Table swim_audit_log already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Table: swim_subscriptions
-- WebSocket subscription tracking
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_subscriptions')
BEGIN
    CREATE TABLE dbo.swim_subscriptions (
        id INT IDENTITY(1,1) PRIMARY KEY,
        api_key_id INT NOT NULL,
        connection_id NVARCHAR(64) NOT NULL,
        channels NVARCHAR(MAX) NOT NULL,         -- JSON array of subscribed channels
        filters NVARCHAR(MAX) NULL,              -- JSON object of filter criteria
        connected_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        last_ping_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        is_active BIT NOT NULL DEFAULT 1,
        
        CONSTRAINT FK_swim_subscriptions_api_key FOREIGN KEY (api_key_id)
            REFERENCES dbo.swim_api_keys (id)
    );
    
    CREATE NONCLUSTERED INDEX IX_swim_subscriptions_active
    ON dbo.swim_subscriptions (is_active, connection_id)
    INCLUDE (api_key_id, channels);
    
    PRINT 'Created table: swim_subscriptions';
END
ELSE
BEGIN
    PRINT 'Table swim_subscriptions already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Table: swim_flight_cache
-- Cached unified flight records for SWIM API
-- Used for merging data from multiple sources
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_flight_cache')
BEGIN
    CREATE TABLE dbo.swim_flight_cache (
        id BIGINT IDENTITY(1,1) PRIMARY KEY,
        gufi NVARCHAR(100) NOT NULL,             -- Globally Unique Flight Identifier
        flight_key NVARCHAR(64) NOT NULL,        -- ADL flight_key
        callsign NVARCHAR(16) NOT NULL,
        dept_icao CHAR(4) NOT NULL,
        dest_icao CHAR(4) NOT NULL,
        status NVARCHAR(20) NOT NULL DEFAULT 'active',  -- active, completed, cancelled
        
        -- Unified record as JSON
        unified_record NVARCHAR(MAX) NOT NULL,
        
        -- Version tracking for optimistic concurrency
        version INT NOT NULL DEFAULT 1,
        
        -- Source tracking timestamps
        adl_updated_at DATETIME2 NULL,
        track_updated_at DATETIME2 NULL,
        metering_updated_at DATETIME2 NULL,
        telemetry_updated_at DATETIME2 NULL,
        simbrief_updated_at DATETIME2 NULL,
        airline_updated_at DATETIME2 NULL,
        
        created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        
        CONSTRAINT UQ_swim_flight_cache_gufi UNIQUE (gufi)
    );
    
    CREATE NONCLUSTERED INDEX IX_swim_flight_cache_flight_key
    ON dbo.swim_flight_cache (flight_key)
    INCLUDE (gufi, status);
    
    CREATE NONCLUSTERED INDEX IX_swim_flight_cache_status_airports
    ON dbo.swim_flight_cache (status, dept_icao, dest_icao)
    INCLUDE (gufi, callsign);
    
    PRINT 'Created table: swim_flight_cache';
END
ELSE
BEGIN
    PRINT 'Table swim_flight_cache already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Table: swim_webhook_endpoints
-- Registered webhook endpoints for push notifications
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_webhook_endpoints')
BEGIN
    CREATE TABLE dbo.swim_webhook_endpoints (
        id INT IDENTITY(1,1) PRIMARY KEY,
        api_key_id INT NOT NULL,
        endpoint_url NVARCHAR(500) NOT NULL,
        events NVARCHAR(MAX) NOT NULL,           -- JSON array of event types to send
        secret NVARCHAR(64) NOT NULL,            -- Signing secret for HMAC
        description NVARCHAR(255) NULL,
        retry_count INT NOT NULL DEFAULT 3,
        timeout_seconds INT NOT NULL DEFAULT 30,
        last_delivery_at DATETIME2 NULL,
        last_failure_at DATETIME2 NULL,
        failure_count INT NOT NULL DEFAULT 0,
        created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        is_active BIT NOT NULL DEFAULT 1,
        
        CONSTRAINT FK_swim_webhook_endpoints_api_key FOREIGN KEY (api_key_id)
            REFERENCES dbo.swim_api_keys (id)
    );
    
    PRINT 'Created table: swim_webhook_endpoints';
END
ELSE
BEGIN
    PRINT 'Table swim_webhook_endpoints already exists - skipping';
END
GO

-- ----------------------------------------------------------------------------
-- Insert default API keys for development/testing
-- NOTE: Replace these with production keys before deployment
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM dbo.swim_api_keys WHERE tier = 'system' AND owner_name = 'vATCSCC Internal')
BEGIN
    INSERT INTO dbo.swim_api_keys 
        (api_key, tier, owner_name, owner_email, source_id, can_write, description)
    VALUES
        ('swim_sys_vatcscc_internal_001', 'system', 'vATCSCC Internal', 'dev@vatcscc.org', 'vatcscc', 1, 'Internal system key for vATCSCC/PERTI'),
        ('swim_dev_test_001', 'developer', 'Test Developer', 'dev@vatcscc.org', NULL, 0, 'Development/testing API key');
    
    PRINT 'Inserted default API keys';
END
GO

-- ----------------------------------------------------------------------------
-- Stored Procedure: sp_Swim_GetFlightByGufi
-- Get unified flight record by GUFI, with fallback to adl_flights
-- Uses VERIFIED column names from adl_flights schema
-- ----------------------------------------------------------------------------

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_Swim_GetFlightByGufi')
BEGIN
    DROP PROCEDURE dbo.sp_Swim_GetFlightByGufi;
END
GO

CREATE PROCEDURE dbo.sp_Swim_GetFlightByGufi
    @gufi NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Try cache first
    SELECT 
        gufi,
        flight_key,
        callsign,
        dept_icao,
        dest_icao,
        status,
        unified_record,
        version,
        updated_at
    FROM dbo.swim_flight_cache
    WHERE gufi = @gufi;
    
    -- If not in cache, parse GUFI and look up in ADL
    IF @@ROWCOUNT = 0
    BEGIN
        DECLARE @callsign NVARCHAR(16);
        DECLARE @dept CHAR(4);
        DECLARE @dest CHAR(4);
        DECLARE @parts TABLE (idx INT IDENTITY(1,1), part NVARCHAR(100));
        
        -- Split GUFI by hyphen (format: VAT-YYYYMMDD-CALLSIGN-DEPT-DEST)
        INSERT INTO @parts (part)
        SELECT value FROM STRING_SPLIT(@gufi, '-');
        
        SELECT @callsign = part FROM @parts WHERE idx = 3;
        SELECT @dept = part FROM @parts WHERE idx = 4;
        SELECT @dest = part FROM @parts WHERE idx = 5;
        
        -- Query adl_flights with ACTUAL column names
        SELECT 
            @gufi AS gufi,
            flight_key,
            callsign,
            fp_dept_icao AS dept_icao,           -- Actual column name
            fp_dest_icao AS dest_icao,           -- Actual column name
            CASE WHEN is_active = 1 THEN 'active' ELSE 'completed' END AS status,
            NULL AS unified_record,
            1 AS version,
            last_seen_utc AS updated_at          -- Actual column name
        FROM dbo.adl_flights
        WHERE callsign = @callsign
          AND fp_dept_icao = @dept               -- Actual column name
          AND fp_dest_icao = @dest               -- Actual column name
          AND is_active = 1;
    END
END
GO

PRINT 'Created stored procedure: sp_Swim_GetFlightByGufi';
GO

-- ----------------------------------------------------------------------------
-- Stored Procedure: sp_Swim_GetActiveFlights
-- Get active flights for SWIM API with filtering
-- Uses VERIFIED column names from adl_flights schema
-- ----------------------------------------------------------------------------

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_Swim_GetActiveFlights')
BEGIN
    DROP PROCEDURE dbo.sp_Swim_GetActiveFlights;
END
GO

CREATE PROCEDURE dbo.sp_Swim_GetActiveFlights
    @dept_icao NVARCHAR(100) = NULL,  -- Comma-separated list
    @dest_icao NVARCHAR(100) = NULL,  -- Comma-separated list
    @artcc NVARCHAR(100) = NULL,      -- Comma-separated list (uses fp_dest_artcc)
    @tmi_controlled BIT = NULL,       -- Filter to TMI-controlled flights
    @page INT = 1,
    @per_page INT = 100
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @offset INT = (@page - 1) * @per_page;
    
    -- Using VERIFIED adl_flights column names
    SELECT 
        flight_key,
        callsign,
        cid,
        aircraft_type,
        aircraft_faa,
        aircraft_short,
        aircraft_icao,
        ac_cat,
        weight_class,
        fp_dept_icao AS departure,               -- Actual column
        fp_dest_icao AS destination,             -- Actual column
        fp_dest_artcc AS artcc,                  -- Actual column
        fp_altitude_ft AS cruise_altitude,       -- Actual column
        fp_tas_kts AS cruise_speed,              -- Actual column
        fp_route AS route,                       -- Actual column
        phase,                                   -- Actual column (not current_phase)
        lat AS latitude,                         -- Actual column
        lon AS longitude,                        -- Actual column
        altitude_ft,                             -- Actual column
        heading_deg AS heading,                  -- Actual column
        groundspeed_kts AS ground_speed,         -- Actual column
        eta_runway_utc AS eta,                   -- Actual column
        gcd_nm AS distance_nm,                   -- Actual column
        ete_minutes,                             -- Actual column
        gs_flag,                                 -- Actual column
        ctl_type,                                -- Actual column
        ctl_program,                             -- Actual column
        gdp_program_id,                          -- Actual column
        gdp_slot_time_utc,                       -- Actual column
        first_seen_utc,                          -- Actual column
        last_seen_utc                            -- Actual column
    FROM dbo.adl_flights
    WHERE is_active = 1
      AND (@dept_icao IS NULL OR fp_dept_icao IN (SELECT value FROM STRING_SPLIT(@dept_icao, ',')))
      AND (@dest_icao IS NULL OR fp_dest_icao IN (SELECT value FROM STRING_SPLIT(@dest_icao, ',')))
      AND (@artcc IS NULL OR fp_dest_artcc IN (SELECT value FROM STRING_SPLIT(@artcc, ',')))
      AND (@tmi_controlled IS NULL OR (@tmi_controlled = 1 AND (gs_flag = 1 OR ctl_type IS NOT NULL)))
    ORDER BY callsign
    OFFSET @offset ROWS
    FETCH NEXT @per_page ROWS ONLY;
END
GO

PRINT 'Created stored procedure: sp_Swim_GetActiveFlights';
GO

-- ----------------------------------------------------------------------------
-- Stored Procedure: sp_Swim_CleanupAuditLog
-- Remove old audit log entries
-- ----------------------------------------------------------------------------

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_Swim_CleanupAuditLog')
BEGIN
    DROP PROCEDURE dbo.sp_Swim_CleanupAuditLog;
END
GO

CREATE PROCEDURE dbo.sp_Swim_CleanupAuditLog
    @days_to_keep INT = 90
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @cutoff DATETIME2 = DATEADD(DAY, -@days_to_keep, GETUTCDATE());
    DECLARE @deleted INT;
    
    DELETE FROM dbo.swim_audit_log
    WHERE request_time < @cutoff;
    
    SET @deleted = @@ROWCOUNT;
    
    SELECT @deleted AS deleted_rows;
END
GO

PRINT 'Created stored procedure: sp_Swim_CleanupAuditLog';
GO

-- ----------------------------------------------------------------------------
-- Verification
-- ----------------------------------------------------------------------------

SELECT 
    'swim_api_keys' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'swim_api_keys'
UNION ALL
SELECT 
    'swim_audit_log' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'swim_audit_log'
UNION ALL
SELECT 
    'swim_subscriptions' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'swim_subscriptions'
UNION ALL
SELECT 
    'swim_flight_cache' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'swim_flight_cache'
UNION ALL
SELECT 
    'swim_webhook_endpoints' AS table_name, 
    COUNT(*) AS column_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'swim_webhook_endpoints';

PRINT '============================================================================';
PRINT 'SWIM Migration Complete';
PRINT '';
PRINT 'Tables created:';
PRINT '  - swim_api_keys';
PRINT '  - swim_audit_log';
PRINT '  - swim_subscriptions';
PRINT '  - swim_flight_cache';
PRINT '  - swim_webhook_endpoints';
PRINT '';
PRINT 'Stored procedures created:';
PRINT '  - sp_Swim_GetFlightByGufi';
PRINT '  - sp_Swim_GetActiveFlights';
PRINT '  - sp_Swim_CleanupAuditLog';
PRINT '';
PRINT 'adl_flights column references verified against VATSIM_ADL_tree.json:';
PRINT '  - fp_dept_icao, fp_dest_icao, fp_dest_artcc (not dept_icao, dest_icao)';
PRINT '  - lat, lon (not latitude, longitude)';
PRINT '  - altitude_ft, heading_deg, groundspeed_kts';
PRINT '  - phase (not current_phase)';
PRINT '  - gs_flag, ctl_type, gdp_program_id (TMI columns)';
PRINT '  - first_seen_utc, last_seen_utc (not created_at, updated_at)';
PRINT '============================================================================';
GO
