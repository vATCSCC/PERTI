-- ============================================================================
-- PERTI Reroute Management - TMI Database Migration
-- Version: 1.0
-- Date: January 2026
--
-- Creates reroute tables in VATSIM_TMI database (migrated from VATSIM_ADL)
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Reroute Tables Migration to VATSIM_TMI ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. tmi_reroutes - Main reroute definitions
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroutes')
BEGIN
    CREATE TABLE dbo.tmi_reroutes (
        -- Primary key (use reroute_id for consistency with other TMI tables)
        reroute_id INT IDENTITY(1,1) PRIMARY KEY,
        reroute_guid UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,

        -- Status & Identity
        -- 0=draft, 1=proposed, 2=active, 3=monitoring, 4=expired, 5=cancelled
        status TINYINT NOT NULL DEFAULT 0,
        name NVARCHAR(64) NOT NULL,
        adv_number NVARCHAR(16) NULL,

        -- Temporal Scope
        start_utc DATETIME2(0) NULL,
        end_utc DATETIME2(0) NULL,
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

        -- Visualization (map display)
        color NVARCHAR(16) DEFAULT '#3498db',
        line_weight INT DEFAULT 3,
        line_style NVARCHAR(16) DEFAULT 'solid',
        route_geojson NVARCHAR(MAX) NULL,

        -- Metadata & Advisory
        comments NVARCHAR(MAX) NULL,
        impacting_condition NVARCHAR(64) NULL,
        advisory_text NVARCHAR(MAX) NULL,

        -- Source tracking
        source_type NVARCHAR(16) NULL,          -- API, DISCORD, MANUAL
        source_id NVARCHAR(64) NULL,
        discord_message_id NVARCHAR(32) NULL,
        discord_channel_id NVARCHAR(32) NULL,

        -- Statistics (computed)
        total_assigned INT DEFAULT 0,
        compliant_count INT DEFAULT 0,
        partial_count INT DEFAULT 0,
        non_compliant_count INT DEFAULT 0,
        exempt_count INT DEFAULT 0,
        compliance_rate DECIMAL(5,2) NULL,

        -- Audit
        created_by NVARCHAR(64) NULL,
        created_at DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        activated_utc DATETIME2(0) NULL,

        -- Constraints
        CONSTRAINT CK_tmi_reroutes_status CHECK (status IN (0, 1, 2, 3, 4, 5)),
        CONSTRAINT CK_tmi_reroutes_route_type CHECK (route_type IN ('FULL', 'PARTIAL'))
    );

    CREATE INDEX IX_tmi_reroutes_status ON dbo.tmi_reroutes(status);
    CREATE INDEX IX_tmi_reroutes_dates ON dbo.tmi_reroutes(start_utc, end_utc);
    CREATE INDEX IX_tmi_reroutes_name ON dbo.tmi_reroutes(name);
    CREATE INDEX IX_tmi_reroutes_guid ON dbo.tmi_reroutes(reroute_guid);
    CREATE INDEX IX_tmi_reroutes_active ON dbo.tmi_reroutes(status, start_utc, end_utc) WHERE status IN (2, 3);

    PRINT 'Created table: dbo.tmi_reroutes';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_reroutes already exists';
END
GO


-- ============================================================================
-- 2. tmi_reroute_flights - Flight assignments
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroute_flights')
BEGIN
    CREATE TABLE dbo.tmi_reroute_flights (
        flight_id BIGINT IDENTITY(1,1) PRIMARY KEY,
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
        current_route_utc DATETIME2(0) NULL,
        final_route NVARCHAR(MAX) NULL,

        -- Position Tracking
        last_lat DECIMAL(9,6) NULL,
        last_lon DECIMAL(10,6) NULL,
        last_altitude INT NULL,
        last_position_utc DATETIME2(0) NULL,

        -- Compliance Status
        -- PENDING, MONITORING, COMPLIANT, PARTIAL, NON_COMPLIANT, EXEMPT, UNKNOWN
        compliance_status NVARCHAR(16) DEFAULT 'PENDING',
        protected_fixes_crossed NVARCHAR(MAX) NULL,
        avoid_fixes_crossed NVARCHAR(MAX) NULL,
        compliance_pct DECIMAL(5,2) NULL,
        compliance_notes NVARCHAR(MAX) NULL,

        -- Timing
        assigned_utc DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        departed_utc DATETIME2(0) NULL,
        arrived_utc DATETIME2(0) NULL,

        -- Metrics (route impact)
        route_distance_original_nm INT NULL,
        route_distance_assigned_nm INT NULL,
        route_delta_nm INT NULL,
        ete_original_min INT NULL,
        ete_assigned_min INT NULL,
        ete_delta_min INT NULL,

        -- Manual Override
        manual_status BIT DEFAULT 0,
        override_by NVARCHAR(64) NULL,
        override_utc DATETIME2(0) NULL,
        override_reason NVARCHAR(MAX) NULL,

        CONSTRAINT FK_tmi_reroute_flights_reroute FOREIGN KEY (reroute_id)
            REFERENCES dbo.tmi_reroutes(reroute_id) ON DELETE CASCADE
    );

    CREATE INDEX IX_tmi_reroute_flights_reroute ON dbo.tmi_reroute_flights(reroute_id);
    CREATE INDEX IX_tmi_reroute_flights_flight_key ON dbo.tmi_reroute_flights(flight_key);
    CREATE INDEX IX_tmi_reroute_flights_callsign ON dbo.tmi_reroute_flights(callsign);
    CREATE INDEX IX_tmi_reroute_flights_compliance ON dbo.tmi_reroute_flights(compliance_status);

    PRINT 'Created table: dbo.tmi_reroute_flights';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_reroute_flights already exists';
END
GO


-- ============================================================================
-- 3. tmi_reroute_compliance_log - Historical compliance snapshots
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroute_compliance_log')
BEGIN
    CREATE TABLE dbo.tmi_reroute_compliance_log (
        log_id BIGINT IDENTITY(1,1) PRIMARY KEY,
        reroute_flight_id BIGINT NOT NULL,

        -- Snapshot
        snapshot_utc DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        compliance_status NVARCHAR(16) NULL,
        compliance_pct DECIMAL(5,2) NULL,

        -- Position at snapshot
        lat DECIMAL(9,6) NULL,
        lon DECIMAL(10,6) NULL,
        altitude INT NULL,

        -- Route at snapshot
        route_string NVARCHAR(MAX) NULL,
        fixes_crossed NVARCHAR(MAX) NULL,

        CONSTRAINT FK_tmi_compliance_log_flight FOREIGN KEY (reroute_flight_id)
            REFERENCES dbo.tmi_reroute_flights(flight_id) ON DELETE CASCADE
    );

    CREATE INDEX IX_tmi_reroute_compliance_log_flight_time
        ON dbo.tmi_reroute_compliance_log(reroute_flight_id, snapshot_utc);

    PRINT 'Created table: dbo.tmi_reroute_compliance_log';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_reroute_compliance_log already exists';
END
GO


PRINT '';
PRINT '=== Reroute Tables Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
