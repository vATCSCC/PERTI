-- ============================================================================
-- SWIM API Dedicated Database Setup - Azure SQL Basic Compatible
-- 
-- Target: Azure SQL Basic ($5/month)
-- Date: 2026-01-16
-- 
-- NOTE: Sync is handled by PHP (not cross-database SQL)
-- ============================================================================

-- ============================================================================
-- SECTION 1: Core Flight Data Table
-- ============================================================================

CREATE TABLE dbo.swim_flights (
    -- Primary Key
    flight_uid BIGINT NOT NULL PRIMARY KEY,
    flight_key NVARCHAR(64) NULL,
    
    -- GUFI (calculated by PHP during sync)
    gufi NVARCHAR(64) NULL,
    
    -- Identity
    callsign NVARCHAR(16) NOT NULL,
    cid INT NULL,
    flight_id NVARCHAR(32) NULL,
    
    -- Position
    lat DECIMAL(9,6) NULL,
    lon DECIMAL(10,6) NULL,
    altitude_ft INT NULL,
    heading_deg SMALLINT NULL,
    groundspeed_kts INT NULL,
    vertical_rate_fpm INT NULL,
    
    -- Flight Plan
    fp_dept_icao CHAR(4) NULL,
    fp_dest_icao CHAR(4) NULL,
    fp_alt_icao CHAR(4) NULL,
    fp_altitude_ft INT NULL,
    fp_tas_kts INT NULL,
    fp_route NVARCHAR(MAX) NULL,
    fp_remarks NVARCHAR(MAX) NULL,
    fp_rule NCHAR(1) NULL,
    fp_dept_artcc NVARCHAR(8) NULL,
    fp_dest_artcc NVARCHAR(8) NULL,
    fp_dept_tracon NVARCHAR(64) NULL,
    fp_dest_tracon NVARCHAR(64) NULL,
    
    -- Procedures
    dfix NVARCHAR(8) NULL,
    dp_name NVARCHAR(16) NULL,
    afix NVARCHAR(8) NULL,
    star_name NVARCHAR(16) NULL,
    dep_runway NVARCHAR(4) NULL,
    arr_runway NVARCHAR(4) NULL,
    
    -- Progress
    phase NVARCHAR(16) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    dist_to_dest_nm DECIMAL(10,2) NULL,
    dist_flown_nm DECIMAL(10,2) NULL,
    pct_complete DECIMAL(5,2) NULL,
    gcd_nm DECIMAL(10,2) NULL,
    route_total_nm DECIMAL(10,2) NULL,
    
    -- Airspace
    current_artcc NVARCHAR(16) NULL,
    current_tracon NVARCHAR(32) NULL,
    current_zone NVARCHAR(16) NULL,
    
    -- Times
    first_seen_utc DATETIME2 NULL,
    last_seen_utc DATETIME2 NULL,
    logon_time_utc DATETIME2 NULL,
    eta_utc DATETIME2 NULL,
    eta_runway_utc DATETIME2 NULL,
    eta_source NVARCHAR(16) NULL,
    eta_method NVARCHAR(16) NULL,
    etd_utc DATETIME2 NULL,
    out_utc DATETIME2 NULL,
    off_utc DATETIME2 NULL,
    on_utc DATETIME2 NULL,
    in_utc DATETIME2 NULL,
    ete_minutes INT NULL,
    
    -- Controlled Times
    ctd_utc DATETIME2 NULL,
    cta_utc DATETIME2 NULL,
    edct_utc DATETIME2 NULL,
    
    -- TMI
    gs_held BIT NULL DEFAULT 0,
    gs_release_utc DATETIME2 NULL,
    ctl_type NVARCHAR(8) NULL,
    ctl_prgm NVARCHAR(32) NULL,
    ctl_element NVARCHAR(8) NULL,
    is_exempt BIT NULL DEFAULT 0,
    exempt_reason NVARCHAR(64) NULL,
    slot_time_utc DATETIME2 NULL,
    slot_status NVARCHAR(16) NULL,
    program_id INT NULL,
    slot_id BIGINT NULL,
    delay_minutes INT NULL,
    delay_status NVARCHAR(16) NULL,
    
    -- Aircraft
    aircraft_type NVARCHAR(8) NULL,
    aircraft_icao NVARCHAR(8) NULL,
    aircraft_faa NVARCHAR(16) NULL,
    weight_class NCHAR(1) NULL,
    wake_category NVARCHAR(8) NULL,
    engine_type NVARCHAR(8) NULL,
    airline_icao NVARCHAR(4) NULL,
    airline_name NVARCHAR(64) NULL,
    
    -- Sync Metadata
    last_sync_utc DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    sync_source NVARCHAR(16) NOT NULL DEFAULT 'ADL'
);
GO

-- Indexes for common query patterns
CREATE INDEX IX_swim_flights_active ON dbo.swim_flights (is_active, callsign);
CREATE INDEX IX_swim_flights_dept ON dbo.swim_flights (fp_dept_icao) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_dest ON dbo.swim_flights (fp_dest_icao) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_dest_artcc ON dbo.swim_flights (fp_dest_artcc) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_phase ON dbo.swim_flights (phase) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_tmi ON dbo.swim_flights (gs_held, ctl_type) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_position ON dbo.swim_flights (lat, lon) WHERE is_active = 1 AND lat IS NOT NULL;
CREATE INDEX IX_swim_flights_gufi ON dbo.swim_flights (gufi) WHERE gufi IS NOT NULL;
GO

-- ============================================================================
-- SECTION 2: API Infrastructure Tables
-- ============================================================================

CREATE TABLE dbo.swim_api_keys (
    id INT IDENTITY(1,1) PRIMARY KEY,
    api_key NVARCHAR(128) NOT NULL UNIQUE,
    tier NVARCHAR(16) NOT NULL DEFAULT 'public',
    owner_name NVARCHAR(128) NULL,
    owner_email NVARCHAR(256) NULL,
    source_id NVARCHAR(32) NULL,
    can_write BIT NOT NULL DEFAULT 0,
    allowed_sources NVARCHAR(MAX) NULL,
    ip_whitelist NVARCHAR(MAX) NULL,
    expires_at DATETIME2 NULL,
    created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    last_used_at DATETIME2 NULL,
    is_active BIT NOT NULL DEFAULT 1,
    
    CONSTRAINT CHK_swim_api_keys_tier CHECK (tier IN ('system', 'partner', 'developer', 'public'))
);
GO

-- Default API keys
INSERT INTO dbo.swim_api_keys (api_key, tier, owner_name, source_id, can_write)
VALUES 
    ('swim_sys_vatcscc_internal_001', 'system', 'vATCSCC Internal', 'vatcscc', 1),
    ('swim_dev_test_001', 'developer', 'Development Testing', 'dev', 0);
GO

-- Audit Log
CREATE TABLE dbo.swim_audit_log (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    api_key_id INT NULL,
    endpoint NVARCHAR(256) NOT NULL,
    method NVARCHAR(8) NOT NULL,
    ip_address NVARCHAR(64) NULL,
    user_agent NVARCHAR(512) NULL,
    request_time DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    response_code INT NULL,
    response_time_ms INT NULL,
    request_params NVARCHAR(MAX) NULL
);
GO

CREATE INDEX IX_swim_audit_time ON dbo.swim_audit_log (request_time);
CREATE INDEX IX_swim_audit_key ON dbo.swim_audit_log (api_key_id, request_time);
GO

-- ============================================================================
-- SECTION 3: TMI Programs Cache
-- ============================================================================

CREATE TABLE dbo.swim_ground_stops (
    id INT PRIMARY KEY,
    name NVARCHAR(128) NULL,
    ctl_element NVARCHAR(8) NOT NULL,
    element_type NVARCHAR(16) NULL,
    airports NVARCHAR(MAX) NULL,
    start_utc DATETIME2 NULL,
    end_utc DATETIME2 NULL,
    prob_ext INT NULL,
    origin_centers NVARCHAR(MAX) NULL,
    origin_airports NVARCHAR(MAX) NULL,
    comments NVARCHAR(MAX) NULL,
    adv_number NVARCHAR(32) NULL,
    advisory_text NVARCHAR(MAX) NULL,
    status INT NOT NULL DEFAULT 1,
    last_sync_utc DATETIME2 NOT NULL DEFAULT GETUTCDATE()
);
GO

CREATE INDEX IX_swim_gs_element ON dbo.swim_ground_stops (ctl_element);
CREATE INDEX IX_swim_gs_active ON dbo.swim_ground_stops (status);
GO

-- ============================================================================
-- SECTION 4: Views
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_swim_active_flights AS
SELECT 
    flight_uid,
    gufi,
    callsign,
    fp_dept_icao,
    fp_dest_icao,
    fp_dest_artcc,
    phase,
    lat,
    lon,
    altitude_ft,
    groundspeed_kts,
    eta_runway_utc,
    gs_held,
    ctl_type,
    aircraft_type,
    weight_class
FROM dbo.swim_flights
WHERE is_active = 1;
GO

CREATE OR ALTER VIEW dbo.vw_swim_tmi_controlled AS
SELECT 
    flight_uid,
    gufi,
    callsign,
    fp_dept_icao,
    fp_dest_icao,
    phase,
    gs_held,
    ctl_type,
    ctl_prgm,
    slot_time_utc,
    delay_minutes,
    is_exempt,
    exempt_reason
FROM dbo.swim_flights
WHERE is_active = 1
  AND (gs_held = 1 OR ctl_type IS NOT NULL);
GO

-- ============================================================================
-- SECTION 5: Cleanup Procedures
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Swim_CleanupAuditLog
    @retention_days INT = 7
AS
BEGIN
    SET NOCOUNT ON;
    
    DELETE FROM dbo.swim_audit_log
    WHERE request_time < DATEADD(DAY, -@retention_days, GETUTCDATE());
    
    PRINT 'Deleted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' audit log entries older than ' 
        + CAST(@retention_days AS VARCHAR) + ' days';
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_Swim_CleanupStaleFlights
    @hours_inactive INT = 2
AS
BEGIN
    SET NOCOUNT ON;
    
    DELETE FROM dbo.swim_flights
    WHERE is_active = 0 
      AND last_sync_utc < DATEADD(HOUR, -@hours_inactive, GETUTCDATE());
    
    PRINT 'Deleted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' stale flights older than ' 
        + CAST(@hours_inactive AS VARCHAR) + ' hours';
END;
GO

-- ============================================================================
-- DONE - Sync handled by PHP via swim_sync.php
-- ============================================================================

PRINT 'SWIM_API database setup complete!';
PRINT 'Next: Grant user access and deploy PHP sync script.';
GO
