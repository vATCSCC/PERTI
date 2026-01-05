-- ============================================================================
-- PERTI Reroute Management System - Database Migration (SQL Server / Azure SQL)
-- Version: 1.0
-- Date: December 2024
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Table: tmi_reroutes
-- Stores reroute definitions
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroutes')
BEGIN
    CREATE TABLE tmi_reroutes (
        id INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Status & Identity
        -- 0=draft, 1=proposed, 2=active, 3=monitoring, 4=expired, 5=cancelled
        status TINYINT NOT NULL DEFAULT 0,
        name NVARCHAR(64) NOT NULL,
        adv_number NVARCHAR(16) NULL,
        
        -- Temporal Scope
        start_utc NVARCHAR(20) NULL,
        end_utc NVARCHAR(20) NULL,
        time_basis NVARCHAR(8) DEFAULT 'ETD',
        
        -- Protected Route Definition
        protected_segment NVARCHAR(MAX) NULL,
        protected_fixes NVARCHAR(MAX) NULL,
        avoid_fixes NVARCHAR(MAX) NULL,
        route_type NVARCHAR(8) DEFAULT 'FULL',
        
        -- Flight Filtering: Geographic Scope
        origin_airports NVARCHAR(MAX) NULL,
        origin_tracons NVARCHAR(MAX) NULL,
        origin_centers NVARCHAR(MAX) NULL,
        dest_airports NVARCHAR(MAX) NULL,
        dest_tracons NVARCHAR(MAX) NULL,
        dest_centers NVARCHAR(MAX) NULL,
        
        -- Flight Filtering: Route-Based
        departure_fix NVARCHAR(8) NULL,
        arrival_fix NVARCHAR(8) NULL,
        thru_centers NVARCHAR(MAX) NULL,
        thru_fixes NVARCHAR(MAX) NULL,
        use_airway NVARCHAR(MAX) NULL,
        
        -- Flight Filtering: Aircraft
        include_ac_cat NVARCHAR(16) DEFAULT 'ALL',
        include_ac_types NVARCHAR(MAX) NULL,
        include_carriers NVARCHAR(MAX) NULL,
        weight_class NVARCHAR(16) DEFAULT 'ALL',
        altitude_min INT NULL,
        altitude_max INT NULL,
        
        -- RVSM Filter
        rvsm_filter NVARCHAR(16) DEFAULT 'ALL',
        
        -- Exemptions
        exempt_airports NVARCHAR(MAX) NULL,
        exempt_carriers NVARCHAR(MAX) NULL,
        exempt_flights NVARCHAR(MAX) NULL,
        exempt_active_only BIT DEFAULT 0,
        
        -- Airborne Applicability
        airborne_filter NVARCHAR(16) DEFAULT 'NOT_AIRBORNE',
        
        -- Metadata & Advisory
        comments NVARCHAR(MAX) NULL,
        impacting_condition NVARCHAR(64) NULL,
        advisory_text NVARCHAR(MAX) NULL,
        
        -- Audit
        created_by INT NULL,
        created_utc DATETIME2 DEFAULT GETUTCDATE(),
        updated_utc DATETIME2 DEFAULT GETUTCDATE(),
        activated_utc DATETIME2 NULL
    );
    
    CREATE INDEX IX_tmi_reroutes_status ON tmi_reroutes(status);
    CREATE INDEX IX_tmi_reroutes_dates ON tmi_reroutes(start_utc, end_utc);
    CREATE INDEX IX_tmi_reroutes_name ON tmi_reroutes(name);
    
    PRINT 'Created table: tmi_reroutes';
END
ELSE
BEGIN
    PRINT 'Table tmi_reroutes already exists';
END
GO


-- ----------------------------------------------------------------------------
-- Table: tmi_reroute_flights
-- Tracks individual flights assigned to reroutes
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroute_flights')
BEGIN
    CREATE TABLE tmi_reroute_flights (
        id INT IDENTITY(1,1) PRIMARY KEY,
        reroute_id INT NOT NULL,
        flight_key NVARCHAR(64) NOT NULL,
        callsign NVARCHAR(16) NOT NULL,
        
        -- Flight Context (captured at assignment)
        dep_icao NCHAR(4) NULL,
        dest_icao NCHAR(4) NULL,
        ac_type NVARCHAR(8) NULL,
        filed_altitude INT NULL,
        
        -- Route Capture
        route_at_assign NVARCHAR(MAX) NULL,
        assigned_route NVARCHAR(MAX) NULL,
        
        -- Route Tracking (updated on each refresh)
        current_route NVARCHAR(MAX) NULL,
        current_route_utc DATETIME2 NULL,
        final_route NVARCHAR(MAX) NULL,
        
        -- Position Tracking
        last_lat DECIMAL(9,6) NULL,
        last_lon DECIMAL(10,6) NULL,
        last_altitude INT NULL,
        last_position_utc DATETIME2 NULL,
        
        -- Compliance Status
        -- PENDING, MONITORING, COMPLIANT, PARTIAL, NON_COMPLIANT, EXEMPT, UNKNOWN
        compliance_status NVARCHAR(16) DEFAULT 'PENDING',
        protected_fixes_crossed NVARCHAR(MAX) NULL,
        avoid_fixes_crossed NVARCHAR(MAX) NULL,
        compliance_pct DECIMAL(5,2) NULL,
        compliance_notes NVARCHAR(MAX) NULL,
        
        -- Timing
        assigned_utc DATETIME2 DEFAULT GETUTCDATE(),
        departed_utc DATETIME2 NULL,
        arrived_utc DATETIME2 NULL,
        
        -- Metrics (route impact)
        route_distance_original_nm INT NULL,
        route_distance_assigned_nm INT NULL,
        route_delta_nm INT NULL,
        ete_original_min INT NULL,
        ete_assigned_min INT NULL,
        ete_delta_min INT NULL,
        
        -- Manual Override
        manual_status BIT DEFAULT 0,
        override_by INT NULL,
        override_utc DATETIME2 NULL,
        override_reason NVARCHAR(MAX) NULL,
        
        CONSTRAINT FK_reroute_flights_reroute FOREIGN KEY (reroute_id) 
            REFERENCES tmi_reroutes(id) ON DELETE CASCADE
    );
    
    CREATE INDEX IX_tmi_reroute_flights_reroute ON tmi_reroute_flights(reroute_id);
    CREATE INDEX IX_tmi_reroute_flights_flight_key ON tmi_reroute_flights(flight_key);
    CREATE INDEX IX_tmi_reroute_flights_callsign ON tmi_reroute_flights(callsign);
    CREATE INDEX IX_tmi_reroute_flights_compliance ON tmi_reroute_flights(compliance_status);
    
    PRINT 'Created table: tmi_reroute_flights';
END
ELSE
BEGIN
    PRINT 'Table tmi_reroute_flights already exists';
END
GO


-- ----------------------------------------------------------------------------
-- Table: tmi_reroute_compliance_log
-- Historical compliance snapshots for tracking over time
-- ----------------------------------------------------------------------------

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroute_compliance_log')
BEGIN
    CREATE TABLE tmi_reroute_compliance_log (
        id BIGINT IDENTITY(1,1) PRIMARY KEY,
        reroute_flight_id INT NOT NULL,
        
        -- Snapshot
        snapshot_utc DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        compliance_status NVARCHAR(16) NULL,
        compliance_pct DECIMAL(5,2) NULL,
        
        -- Position at snapshot
        lat DECIMAL(9,6) NULL,
        lon DECIMAL(10,6) NULL,
        altitude INT NULL,
        
        -- Route at snapshot
        route_string NVARCHAR(MAX) NULL,
        fixes_crossed NVARCHAR(MAX) NULL,
        
        CONSTRAINT FK_compliance_log_flight FOREIGN KEY (reroute_flight_id) 
            REFERENCES tmi_reroute_flights(id) ON DELETE CASCADE
    );
    
    CREATE INDEX IX_tmi_reroute_compliance_log_flight_time 
        ON tmi_reroute_compliance_log(reroute_flight_id, snapshot_utc);
    
    PRINT 'Created table: tmi_reroute_compliance_log';
END
ELSE
BEGIN
    PRINT 'Table tmi_reroute_compliance_log already exists';
END
GO


-- ----------------------------------------------------------------------------
-- Status reference (for documentation)
-- ----------------------------------------------------------------------------
-- tmi_reroutes.status:
--   0 = draft       (not visible to others, work in progress)
--   1 = proposed    (visible, awaiting activation)
--   2 = active      (reroute is in effect, flights being assigned)
--   3 = monitoring  (reroute period ended, still tracking compliance)
--   4 = expired     (completed, historical record)
--   5 = cancelled   (was active but cancelled early)
--
-- tmi_reroute_flights.compliance_status:
--   PENDING       = Flight assigned but not yet departed
--   MONITORING    = Flight airborne, compliance being tracked
--   COMPLIANT     = Crossed all protected fixes, avoided all avoid fixes
--   PARTIAL       = Crossed some protected fixes OR used some avoid fixes
--   NON_COMPLIANT = Did not follow reroute
--   EXEMPT        = Flight was exempted from reroute
--   UNKNOWN       = Cannot determine (data issues)

PRINT 'Migration complete!';
GO
