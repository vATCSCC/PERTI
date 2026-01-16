-- ============================================================================
-- SWIM API Dedicated Database Setup
-- 
-- Creates the SWIM_API database schema for public API queries.
-- This isolates API traffic from the expensive VATSIM_ADL Serverless database.
--
-- Target: Azure SQL Basic ($5/month)
-- 
-- Run this AFTER creating the SWIM_API database in Azure Portal:
--   az sql db create --name SWIM_API --server <server> --service-objective Basic
--
-- Version: 1.0.0
-- Date: 2026-01-16
-- ============================================================================

-- ============================================================================
-- SECTION 1: Core Flight Data Table
-- ============================================================================

-- Denormalized flight table optimized for read queries
-- Synced from VATSIM_ADL normalized tables every 15 seconds
CREATE TABLE dbo.swim_flights (
    -- Primary Key
    flight_uid BIGINT NOT NULL PRIMARY KEY,
    flight_key NVARCHAR(64) NULL,
    
    -- GUFI (Globally Unique Flight Identifier)
    gufi AS ('VAT-' + FORMAT(COALESCE(first_seen_utc, GETUTCDATE()), 'yyyyMMdd') + '-' + callsign + '-' + ISNULL(fp_dept_icao, 'XXXX') + '-' + ISNULL(fp_dest_icao, 'XXXX')) PERSISTED,
    
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

-- Indexes for common query patterns
CREATE INDEX IX_swim_flights_active ON dbo.swim_flights (is_active, callsign);
CREATE INDEX IX_swim_flights_dept ON dbo.swim_flights (fp_dept_icao) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_dest ON dbo.swim_flights (fp_dest_icao) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_dest_artcc ON dbo.swim_flights (fp_dest_artcc) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_phase ON dbo.swim_flights (phase) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_tmi ON dbo.swim_flights (gs_held, ctl_type) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_position ON dbo.swim_flights (lat, lon) WHERE is_active = 1 AND lat IS NOT NULL;

GO

-- ============================================================================
-- SECTION 2: API Infrastructure Tables
-- ============================================================================

-- API Keys
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
    request_params NVARCHAR(MAX) NULL,
    
    INDEX IX_swim_audit_time (request_time),
    INDEX IX_swim_audit_key (api_key_id, request_time)
);

GO

-- ============================================================================
-- SECTION 3: TMI Programs (cached from MySQL)
-- ============================================================================

-- Ground Stops (synced from MySQL tmi_ground_stops)
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
    last_sync_utc DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    
    INDEX IX_swim_gs_element (ctl_element),
    INDEX IX_swim_gs_active (status)
);

GO

-- ============================================================================
-- SECTION 4: Sync Procedure
-- ============================================================================

-- Main sync procedure - call every 15 seconds after ADL refresh
-- NOTE: This procedure assumes linked server or cross-database access to VATSIM_ADL
-- Adjust the database reference as needed for your Azure setup
CREATE OR ALTER PROCEDURE dbo.sp_Swim_SyncFromAdl
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @rows_affected INT = 0;
    
    BEGIN TRY
        -- Merge flights from VATSIM_ADL normalized tables
        -- NOTE: Replace [VATSIM_ADL].dbo with your actual database reference
        MERGE dbo.swim_flights AS target
        USING (
            SELECT 
                c.flight_uid,
                c.flight_key,
                c.callsign,
                c.cid,
                c.flight_id,
                pos.lat,
                pos.lon,
                pos.altitude_ft,
                pos.heading_deg,
                pos.groundspeed_kts,
                pos.vertical_rate_fpm,
                fp.fp_dept_icao,
                fp.fp_dest_icao,
                fp.fp_alt_icao,
                fp.fp_altitude_ft,
                fp.fp_tas_kts,
                fp.fp_route,
                fp.fp_remarks,
                fp.fp_rule,
                fp.fp_dept_artcc,
                fp.fp_dest_artcc,
                fp.fp_dept_tracon,
                fp.fp_dest_tracon,
                fp.dfix,
                fp.dp_name,
                fp.afix,
                fp.star_name,
                fp.dep_runway,
                fp.arr_runway,
                c.phase,
                c.is_active,
                pos.dist_to_dest_nm,
                pos.dist_flown_nm,
                pos.pct_complete,
                fp.gcd_nm,
                fp.route_total_nm,
                c.current_artcc,
                c.current_tracon,
                c.current_zone,
                c.first_seen_utc,
                c.last_seen_utc,
                c.logon_time_utc,
                t.eta_utc,
                t.eta_runway_utc,
                t.eta_source,
                t.eta_method,
                t.etd_utc,
                t.out_utc,
                t.off_utc,
                t.on_utc,
                t.in_utc,
                t.ete_minutes,
                t.ctd_utc,
                t.cta_utc,
                t.edct_utc,
                tmi.gs_held,
                tmi.gs_release_utc,
                tmi.ctl_type,
                tmi.ctl_prgm,
                tmi.ctl_element,
                tmi.is_exempt,
                tmi.exempt_reason,
                tmi.slot_time_utc,
                tmi.slot_status,
                tmi.program_id,
                tmi.slot_id,
                tmi.delay_minutes,
                tmi.delay_status,
                fp.aircraft_type,
                ac.aircraft_icao,
                ac.aircraft_faa,
                ac.weight_class,
                ac.wake_category,
                ac.engine_type,
                ac.airline_icao,
                ac.airline_name
            FROM [VATSIM_ADL].dbo.adl_flight_core c
            LEFT JOIN [VATSIM_ADL].dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
            LEFT JOIN [VATSIM_ADL].dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN [VATSIM_ADL].dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            LEFT JOIN [VATSIM_ADL].dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
            LEFT JOIN [VATSIM_ADL].dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
            WHERE c.is_active = 1 
               OR c.last_seen_utc > DATEADD(HOUR, -2, GETUTCDATE())
        ) AS source ON target.flight_uid = source.flight_uid
        
        WHEN MATCHED THEN UPDATE SET
            flight_key = source.flight_key,
            callsign = source.callsign,
            cid = source.cid,
            flight_id = source.flight_id,
            lat = source.lat,
            lon = source.lon,
            altitude_ft = source.altitude_ft,
            heading_deg = source.heading_deg,
            groundspeed_kts = source.groundspeed_kts,
            vertical_rate_fpm = source.vertical_rate_fpm,
            fp_dept_icao = source.fp_dept_icao,
            fp_dest_icao = source.fp_dest_icao,
            fp_alt_icao = source.fp_alt_icao,
            fp_altitude_ft = source.fp_altitude_ft,
            fp_tas_kts = source.fp_tas_kts,
            fp_route = source.fp_route,
            fp_remarks = source.fp_remarks,
            fp_rule = source.fp_rule,
            fp_dept_artcc = source.fp_dept_artcc,
            fp_dest_artcc = source.fp_dest_artcc,
            fp_dept_tracon = source.fp_dept_tracon,
            fp_dest_tracon = source.fp_dest_tracon,
            dfix = source.dfix,
            dp_name = source.dp_name,
            afix = source.afix,
            star_name = source.star_name,
            dep_runway = source.dep_runway,
            arr_runway = source.arr_runway,
            phase = source.phase,
            is_active = source.is_active,
            dist_to_dest_nm = source.dist_to_dest_nm,
            dist_flown_nm = source.dist_flown_nm,
            pct_complete = source.pct_complete,
            gcd_nm = source.gcd_nm,
            route_total_nm = source.route_total_nm,
            current_artcc = source.current_artcc,
            current_tracon = source.current_tracon,
            current_zone = source.current_zone,
            first_seen_utc = source.first_seen_utc,
            last_seen_utc = source.last_seen_utc,
            logon_time_utc = source.logon_time_utc,
            eta_utc = source.eta_utc,
            eta_runway_utc = source.eta_runway_utc,
            eta_source = source.eta_source,
            eta_method = source.eta_method,
            etd_utc = source.etd_utc,
            out_utc = source.out_utc,
            off_utc = source.off_utc,
            on_utc = source.on_utc,
            in_utc = source.in_utc,
            ete_minutes = source.ete_minutes,
            ctd_utc = source.ctd_utc,
            cta_utc = source.cta_utc,
            edct_utc = source.edct_utc,
            gs_held = source.gs_held,
            gs_release_utc = source.gs_release_utc,
            ctl_type = source.ctl_type,
            ctl_prgm = source.ctl_prgm,
            ctl_element = source.ctl_element,
            is_exempt = source.is_exempt,
            exempt_reason = source.exempt_reason,
            slot_time_utc = source.slot_time_utc,
            slot_status = source.slot_status,
            program_id = source.program_id,
            slot_id = source.slot_id,
            delay_minutes = source.delay_minutes,
            delay_status = source.delay_status,
            aircraft_type = source.aircraft_type,
            aircraft_icao = source.aircraft_icao,
            aircraft_faa = source.aircraft_faa,
            weight_class = source.weight_class,
            wake_category = source.wake_category,
            engine_type = source.engine_type,
            airline_icao = source.airline_icao,
            airline_name = source.airline_name,
            last_sync_utc = GETUTCDATE()
            
        WHEN NOT MATCHED BY TARGET THEN INSERT (
            flight_uid, flight_key, callsign, cid, flight_id,
            lat, lon, altitude_ft, heading_deg, groundspeed_kts, vertical_rate_fpm,
            fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_tas_kts,
            fp_route, fp_remarks, fp_rule, fp_dept_artcc, fp_dest_artcc,
            fp_dept_tracon, fp_dest_tracon, dfix, dp_name, afix, star_name,
            dep_runway, arr_runway, phase, is_active, dist_to_dest_nm,
            dist_flown_nm, pct_complete, gcd_nm, route_total_nm,
            current_artcc, current_tracon, current_zone,
            first_seen_utc, last_seen_utc, logon_time_utc,
            eta_utc, eta_runway_utc, eta_source, eta_method, etd_utc,
            out_utc, off_utc, on_utc, in_utc, ete_minutes,
            ctd_utc, cta_utc, edct_utc,
            gs_held, gs_release_utc, ctl_type, ctl_prgm, ctl_element,
            is_exempt, exempt_reason, slot_time_utc, slot_status,
            program_id, slot_id, delay_minutes, delay_status,
            aircraft_type, aircraft_icao, aircraft_faa, weight_class,
            wake_category, engine_type, airline_icao, airline_name,
            last_sync_utc
        ) VALUES (
            source.flight_uid, source.flight_key, source.callsign, source.cid, source.flight_id,
            source.lat, source.lon, source.altitude_ft, source.heading_deg, source.groundspeed_kts, source.vertical_rate_fpm,
            source.fp_dept_icao, source.fp_dest_icao, source.fp_alt_icao, source.fp_altitude_ft, source.fp_tas_kts,
            source.fp_route, source.fp_remarks, source.fp_rule, source.fp_dept_artcc, source.fp_dest_artcc,
            source.fp_dept_tracon, source.fp_dest_tracon, source.dfix, source.dp_name, source.afix, source.star_name,
            source.dep_runway, source.arr_runway, source.phase, source.is_active, source.dist_to_dest_nm,
            source.dist_flown_nm, source.pct_complete, source.gcd_nm, source.route_total_nm,
            source.current_artcc, source.current_tracon, source.current_zone,
            source.first_seen_utc, source.last_seen_utc, source.logon_time_utc,
            source.eta_utc, source.eta_runway_utc, source.eta_source, source.eta_method, source.etd_utc,
            source.out_utc, source.off_utc, source.on_utc, source.in_utc, source.ete_minutes,
            source.ctd_utc, source.cta_utc, source.edct_utc,
            source.gs_held, source.gs_release_utc, source.ctl_type, source.ctl_prgm, source.ctl_element,
            source.is_exempt, source.exempt_reason, source.slot_time_utc, source.slot_status,
            source.program_id, source.slot_id, source.delay_minutes, source.delay_status,
            source.aircraft_type, source.aircraft_icao, source.aircraft_faa, source.weight_class,
            source.wake_category, source.engine_type, source.airline_icao, source.airline_name,
            GETUTCDATE()
        );
        
        SET @rows_affected = @@ROWCOUNT;
        
        -- Remove stale flights (inactive for >2 hours)
        DELETE FROM dbo.swim_flights 
        WHERE is_active = 0 
          AND last_sync_utc < DATEADD(HOUR, -2, GETUTCDATE());
        
        -- Log sync completion
        PRINT 'SWIM Sync completed: ' + CAST(@rows_affected AS VARCHAR) + ' flights synced in ' 
            + CAST(DATEDIFF(MILLISECOND, @start_time, GETUTCDATE()) AS VARCHAR) + 'ms';
            
    END TRY
    BEGIN CATCH
        PRINT 'SWIM Sync Error: ' + ERROR_MESSAGE();
        THROW;
    END CATCH
END;
GO

-- ============================================================================
-- SECTION 5: Cleanup Procedure
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

-- ============================================================================
-- SECTION 6: Helpful Views
-- ============================================================================

-- Active flights view
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

-- TMI controlled flights view
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
-- NOTES
-- ============================================================================

/*
DEPLOYMENT STEPS:

1. Create the database in Azure Portal:
   az sql db create --name SWIM_API --server <your-server> --resource-group <rg> --service-objective Basic

2. Run this script against the new SWIM_API database

3. Set up cross-database access to VATSIM_ADL:
   - Option A: Linked server (if on different servers)
   - Option B: Same-server cross-database query (if same server)
   - Option C: Elastic query (if different servers in Azure)

4. Schedule sp_Swim_SyncFromAdl to run every 15 seconds:
   - Option A: SQL Agent job (if available)
   - Option B: Azure Automation runbook
   - Option C: Azure Function with timer trigger
   - Option D: Call from PHP after ADL refresh completes

5. Update swim_config.php with SWIM_API connection string

6. Update API endpoints to use $conn_swim instead of $conn_adl

COST:
- Azure SQL Basic: $5/month (fixed)
- Storage: Included in Basic tier
- Compute: Included in Basic tier
- TOTAL: $5/month regardless of query volume
*/
