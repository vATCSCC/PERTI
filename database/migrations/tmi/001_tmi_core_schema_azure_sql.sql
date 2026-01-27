-- =============================================================================
-- VATSIM_TMI Complete Schema Migration - Azure SQL
-- Server: vatsim.database.windows.net
-- Database: VATSIM_TMI
-- Version: 2.0 (Consolidated TMI Database)
-- Date: 2026-01-17
-- =============================================================================
-- 
-- TABLES INCLUDED:
--   1. tmi_entries           - NTML log entries
--   2. tmi_advisories        - Formal advisories
--   3. tmi_programs          - GS/GDP programs
--   4. tmi_slots             - GDP slot allocation
--   5. tmi_reroutes          - Reroute definitions
--   6. tmi_reroute_flights   - Flight assignments to reroutes
--   7. tmi_reroute_compliance_log - Compliance history
--   8. tmi_public_routes     - Public route display
--   9. tmi_events            - Unified audit log
--  10. tmi_advisory_sequences - Advisory numbering
--
-- =============================================================================

-- =============================================================================
-- DROP EXISTING OBJECTS (for clean deployment)
-- =============================================================================

-- Drop views first
IF OBJECT_ID('dbo.vw_tmi_active_entries', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_active_entries;
IF OBJECT_ID('dbo.vw_tmi_active_advisories', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_active_advisories;
IF OBJECT_ID('dbo.vw_tmi_active_programs', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_active_programs;
IF OBJECT_ID('dbo.vw_tmi_active_reroutes', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_active_reroutes;
IF OBJECT_ID('dbo.vw_tmi_active_public_routes', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_active_public_routes;
IF OBJECT_ID('dbo.vw_tmi_recent_entries', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_recent_entries;
GO

-- Drop procedures
IF OBJECT_ID('dbo.sp_GetNextAdvisoryNumber', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_GetNextAdvisoryNumber;
IF OBJECT_ID('dbo.sp_LogTmiEvent', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_LogTmiEvent;
IF OBJECT_ID('dbo.sp_UpdateEntryStatus', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_UpdateEntryStatus;
IF OBJECT_ID('dbo.sp_ExpireOldEntries', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_ExpireOldEntries;
IF OBJECT_ID('dbo.sp_CreateEntry', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_CreateEntry;
IF OBJECT_ID('dbo.sp_GetActivePublicRoutes', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_GetActivePublicRoutes;
GO

-- Drop tables (order matters due to FKs)
IF OBJECT_ID('dbo.tmi_reroute_compliance_log', 'U') IS NOT NULL DROP TABLE dbo.tmi_reroute_compliance_log;
IF OBJECT_ID('dbo.tmi_reroute_flights', 'U') IS NOT NULL DROP TABLE dbo.tmi_reroute_flights;
IF OBJECT_ID('dbo.tmi_public_routes', 'U') IS NOT NULL DROP TABLE dbo.tmi_public_routes;
IF OBJECT_ID('dbo.tmi_slots', 'U') IS NOT NULL DROP TABLE dbo.tmi_slots;
IF OBJECT_ID('dbo.tmi_events', 'U') IS NOT NULL DROP TABLE dbo.tmi_events;
IF OBJECT_ID('dbo.tmi_advisories', 'U') IS NOT NULL DROP TABLE dbo.tmi_advisories;
IF OBJECT_ID('dbo.tmi_reroutes', 'U') IS NOT NULL DROP TABLE dbo.tmi_reroutes;
IF OBJECT_ID('dbo.tmi_programs', 'U') IS NOT NULL DROP TABLE dbo.tmi_programs;
IF OBJECT_ID('dbo.tmi_entries', 'U') IS NOT NULL DROP TABLE dbo.tmi_entries;
IF OBJECT_ID('dbo.tmi_advisory_sequences', 'U') IS NOT NULL DROP TABLE dbo.tmi_advisory_sequences;
GO

PRINT 'Dropped existing objects';
GO

-- =============================================================================
-- TABLE 1: tmi_entries (NTML Log)
-- =============================================================================

CREATE TABLE dbo.tmi_entries (
    entry_id                INT IDENTITY(1,1) PRIMARY KEY,
    entry_guid              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Entry Classification
    determinant_code        NVARCHAR(10) NOT NULL,
    protocol_type           TINYINT NOT NULL,
    entry_type              NVARCHAR(16) NOT NULL,
    
    -- Scope
    ctl_element             NVARCHAR(8) NULL,
    element_type            NVARCHAR(8) NULL,
    requesting_facility     NVARCHAR(64) NULL,
    providing_facility      NVARCHAR(64) NULL,
    
    -- Restriction Details
    restriction_value       SMALLINT NULL,
    restriction_unit        NVARCHAR(8) NULL,
    condition_text          NVARCHAR(500) NULL,
    qualifiers              NVARCHAR(200) NULL,
    exclusions              NVARCHAR(200) NULL,
    reason_code             NVARCHAR(16) NULL,
    reason_detail           NVARCHAR(200) NULL,
    
    -- Time Parameters
    valid_from              DATETIME2(0) NULL,
    valid_until             DATETIME2(0) NULL,
    
    -- Status
    status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    
    -- Source Tracking
    source_type             NVARCHAR(16) NOT NULL,
    source_id               NVARCHAR(100) NULL,
    source_channel          NVARCHAR(64) NULL,
    
    -- Discord Sync
    discord_message_id      NVARCHAR(64) NULL,
    discord_posted_at       DATETIME2(0) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Raw Input
    raw_input               NVARCHAR(MAX) NULL,
    parsed_data             NVARCHAR(MAX) NULL,
    
    -- Metadata
    created_by              NVARCHAR(64) NULL,
    created_by_name         NVARCHAR(128) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    cancelled_by            NVARCHAR(64) NULL,
    cancelled_at            DATETIME2(0) NULL,
    cancel_reason           NVARCHAR(256) NULL,
    
    -- Deduplication
    content_hash            NVARCHAR(64) NULL,
    supersedes_entry_id     INT NULL,
    
    CONSTRAINT UQ_entries_guid UNIQUE (entry_guid)
);
GO

-- Add self-referencing FK after table exists
ALTER TABLE dbo.tmi_entries ADD CONSTRAINT FK_entries_supersedes 
    FOREIGN KEY (supersedes_entry_id) REFERENCES dbo.tmi_entries(entry_id);
GO

-- Indexes
CREATE NONCLUSTERED INDEX IX_entries_status ON dbo.tmi_entries (status, valid_from, valid_until);
CREATE NONCLUSTERED INDEX IX_entries_determinant ON dbo.tmi_entries (determinant_code);
CREATE NONCLUSTERED INDEX IX_entries_entry_type ON dbo.tmi_entries (entry_type);
CREATE NONCLUSTERED INDEX IX_entries_facility_req ON dbo.tmi_entries (requesting_facility) WHERE requesting_facility IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_entries_facility_prov ON dbo.tmi_entries (providing_facility) WHERE providing_facility IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_entries_ctl_element ON dbo.tmi_entries (ctl_element) WHERE ctl_element IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_entries_source ON dbo.tmi_entries (source_type, source_id);
CREATE NONCLUSTERED INDEX IX_entries_discord ON dbo.tmi_entries (discord_message_id) WHERE discord_message_id IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_entries_hash ON dbo.tmi_entries (content_hash) WHERE content_hash IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_entries_created ON dbo.tmi_entries (created_at DESC);
CREATE NONCLUSTERED INDEX IX_entries_active ON dbo.tmi_entries (status, valid_until) WHERE status = 'ACTIVE';
GO

PRINT 'Created table: tmi_entries';
GO

-- =============================================================================
-- TABLE 2: tmi_programs (GS/GDP Programs)
-- =============================================================================

CREATE TABLE dbo.tmi_programs (
    program_id              INT IDENTITY(1,1) PRIMARY KEY,
    program_guid            UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Program Identification
    ctl_element             NVARCHAR(8) NOT NULL,
    element_type            NVARCHAR(8) NOT NULL,
    program_type            NVARCHAR(16) NOT NULL,
    program_name            NVARCHAR(32) NULL,
    adv_number              NVARCHAR(16) NULL,
    
    -- Program Times
    start_utc               DATETIME2(0) NOT NULL,
    end_utc                 DATETIME2(0) NOT NULL,
    cumulative_start        DATETIME2(0) NULL,
    cumulative_end          DATETIME2(0) NULL,
    
    -- Program Status
    status                  NVARCHAR(16) NOT NULL DEFAULT 'PROPOSED',
    is_proposed             BIT DEFAULT 1,
    is_active               BIT DEFAULT 0,
    
    -- Rates (GDP)
    program_rate            INT NULL,
    reserve_rate            INT NULL,
    delay_limit_min         INT DEFAULT 180,
    target_delay_mult       DECIMAL(3,2) DEFAULT 1.0,
    
    -- Hourly Rates (JSON)
    rates_hourly_json       NVARCHAR(MAX) NULL,
    reserve_hourly_json     NVARCHAR(MAX) NULL,
    
    -- Scope (JSON)
    scope_json              NVARCHAR(MAX) NULL,
    exemptions_json         NVARCHAR(MAX) NULL,
    
    -- Filters
    arrival_fix_filter      NVARCHAR(8) NULL,
    aircraft_type_filter    NVARCHAR(16) DEFAULT 'ALL',
    carrier_filter          NVARCHAR(MAX) NULL,
    
    -- Impact/Cause
    impacting_condition     NVARCHAR(64) NULL,
    cause_text              NVARCHAR(512) NULL,
    comments                NVARCHAR(MAX) NULL,
    
    -- Revision Tracking
    revision_number         INT DEFAULT 0,
    parent_program_id       INT NULL,
    
    -- Metrics
    total_flights           INT NULL,
    controlled_flights      INT NULL,
    exempt_flights          INT NULL,
    avg_delay_min           DECIMAL(8,2) NULL,
    max_delay_min           INT NULL,
    total_delay_min         BIGINT NULL,
    
    -- Substitution Settings
    subs_enabled            BIT DEFAULT 1,
    adaptive_compression    BIT DEFAULT 0,
    
    -- Source Tracking
    source_type             NVARCHAR(16) NULL,
    source_id               NVARCHAR(100) NULL,
    discord_message_id      NVARCHAR(256) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Audit
    created_by              NVARCHAR(64) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    activated_by            NVARCHAR(64) NULL,
    activated_at            DATETIME2(0) NULL,
    purged_by               NVARCHAR(64) NULL,
    purged_at               DATETIME2(0) NULL,
    
    CONSTRAINT UQ_programs_guid UNIQUE (program_guid)
);
GO

ALTER TABLE dbo.tmi_programs ADD CONSTRAINT FK_programs_parent 
    FOREIGN KEY (parent_program_id) REFERENCES dbo.tmi_programs(program_id);
GO

CREATE NONCLUSTERED INDEX IX_programs_element ON dbo.tmi_programs (ctl_element, status);
CREATE NONCLUSTERED INDEX IX_programs_active ON dbo.tmi_programs (is_active, start_utc, end_utc);
CREATE NONCLUSTERED INDEX IX_programs_type ON dbo.tmi_programs (program_type);
GO

PRINT 'Created table: tmi_programs';
GO

-- =============================================================================
-- TABLE 3: tmi_advisories (Formal Advisories)
-- =============================================================================

CREATE TABLE dbo.tmi_advisories (
    advisory_id             INT IDENTITY(1,1) PRIMARY KEY,
    advisory_guid           UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Advisory Identification
    advisory_number         NVARCHAR(16) NOT NULL,
    advisory_type           NVARCHAR(32) NOT NULL,
    
    -- Scope
    ctl_element             NVARCHAR(8) NULL,
    element_type            NVARCHAR(8) NULL,
    scope_facilities        NVARCHAR(MAX) NULL,
    
    -- Link to GDT Program
    program_id              INT NULL,
    
    -- Program Parameters
    program_rate            SMALLINT NULL,
    delay_cap               SMALLINT NULL,
    
    -- Time Parameters
    effective_from          DATETIME2(0) NULL,
    effective_until         DATETIME2(0) NULL,
    
    -- Content
    subject                 NVARCHAR(256) NOT NULL,
    body_text               NVARCHAR(MAX) NOT NULL,
    reason_code             NVARCHAR(16) NULL,
    reason_detail           NVARCHAR(200) NULL,
    
    -- Reroute Specifics
    reroute_id              INT NULL,
    reroute_name            NVARCHAR(64) NULL,
    reroute_area            NVARCHAR(32) NULL,
    reroute_string          NVARCHAR(MAX) NULL,
    reroute_from            NVARCHAR(256) NULL,
    reroute_to              NVARCHAR(256) NULL,
    
    -- MIT Specifics
    mit_miles               SMALLINT NULL,
    mit_type                NVARCHAR(8) NULL,
    mit_fix                 NVARCHAR(32) NULL,
    
    -- Status
    status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    is_proposed             BIT DEFAULT 1,
    
    -- Source Tracking
    source_type             NVARCHAR(16) NOT NULL,
    source_id               NVARCHAR(100) NULL,
    
    -- Discord Sync
    discord_message_id      NVARCHAR(256) NULL,
    discord_posted_at       DATETIME2(0) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Metadata
    created_by              NVARCHAR(64) NULL,
    created_by_name         NVARCHAR(128) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    approved_by             NVARCHAR(64) NULL,
    approved_at             DATETIME2(0) NULL,
    cancelled_by            NVARCHAR(64) NULL,
    cancelled_at            DATETIME2(0) NULL,
    cancel_reason           NVARCHAR(256) NULL,
    
    -- Revision Tracking
    revision_number         TINYINT DEFAULT 1,
    supersedes_advisory_id  INT NULL,
    
    CONSTRAINT UQ_advisories_guid UNIQUE (advisory_guid),
    CONSTRAINT FK_advisories_program FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id)
);
GO

ALTER TABLE dbo.tmi_advisories ADD CONSTRAINT FK_advisories_supersedes 
    FOREIGN KEY (supersedes_advisory_id) REFERENCES dbo.tmi_advisories(advisory_id);
GO

CREATE NONCLUSTERED INDEX IX_advisories_status ON dbo.tmi_advisories (status, effective_from, effective_until);
CREATE NONCLUSTERED INDEX IX_advisories_type ON dbo.tmi_advisories (advisory_type);
CREATE NONCLUSTERED INDEX IX_advisories_number ON dbo.tmi_advisories (advisory_number);
CREATE NONCLUSTERED INDEX IX_advisories_element ON dbo.tmi_advisories (ctl_element) WHERE ctl_element IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_advisories_created ON dbo.tmi_advisories (created_at DESC);
CREATE NONCLUSTERED INDEX IX_advisories_active ON dbo.tmi_advisories (status, effective_until) WHERE status = 'ACTIVE';
GO

PRINT 'Created table: tmi_advisories';
GO

-- =============================================================================
-- TABLE 4: tmi_slots (GDP Slot Allocation)
-- =============================================================================

CREATE TABLE dbo.tmi_slots (
    slot_id                 BIGINT IDENTITY(1,1) PRIMARY KEY,
    program_id              INT NOT NULL,
    
    -- Slot Identification
    slot_name               NVARCHAR(16) NOT NULL,
    slot_index              INT NOT NULL,
    slot_time_utc           DATETIME2(0) NOT NULL,
    
    -- Type and Status
    slot_type               NVARCHAR(16) NOT NULL,
    slot_status             NVARCHAR(16) NOT NULL DEFAULT 'OPEN',
    
    -- Bin Tracking
    bin_hour                TINYINT NOT NULL,
    bin_quarter             TINYINT NOT NULL,
    
    -- Assignment (flight_uid references VATSIM_ADL)
    assigned_flight_uid     BIGINT NULL,
    assigned_callsign       NVARCHAR(12) NULL,
    assigned_carrier        NVARCHAR(8) NULL,
    assigned_origin         NVARCHAR(4) NULL,
    assigned_at             DATETIME2(0) NULL,
    
    -- Control Times
    ctd_utc                 DATETIME2(0) NULL,
    cta_utc                 DATETIME2(0) NULL,
    
    -- Slot Management
    sl_hold                 BIT DEFAULT 0,
    sl_hold_carrier         NVARCHAR(8) NULL,
    sl_hold_expires_utc     DATETIME2(0) NULL,
    subbable                BIT DEFAULT 1,
    
    -- Bridging
    bridge_from_slot_id     BIGINT NULL,
    bridge_to_slot_id       BIGINT NULL,
    
    -- Audit
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    CONSTRAINT FK_slots_program FOREIGN KEY (program_id) 
        REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE
);
GO

CREATE NONCLUSTERED INDEX IX_slots_program_time ON dbo.tmi_slots (program_id, slot_time_utc);
CREATE NONCLUSTERED INDEX IX_slots_flight ON dbo.tmi_slots (assigned_flight_uid) WHERE assigned_flight_uid IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_slots_status ON dbo.tmi_slots (program_id, slot_status, slot_type);
CREATE NONCLUSTERED INDEX IX_slots_callsign ON dbo.tmi_slots (assigned_callsign) WHERE assigned_callsign IS NOT NULL;
GO

PRINT 'Created table: tmi_slots';
GO

-- =============================================================================
-- TABLE 5: tmi_reroutes (Reroute Definitions)
-- =============================================================================

CREATE TABLE dbo.tmi_reroutes (
    reroute_id              INT IDENTITY(1,1) PRIMARY KEY,
    reroute_guid            UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Status & Identity
    status                  TINYINT NOT NULL DEFAULT 0,
    name                    NVARCHAR(64) NOT NULL,
    adv_number              NVARCHAR(16) NULL,
    
    -- Temporal Scope
    start_utc               DATETIME2(0) NULL,
    end_utc                 DATETIME2(0) NULL,
    time_basis              NVARCHAR(8) DEFAULT 'ETD',
    
    -- Route Definition
    protected_segment       NVARCHAR(MAX) NULL,
    protected_fixes         NVARCHAR(MAX) NULL,
    avoid_fixes             NVARCHAR(MAX) NULL,
    route_type              NVARCHAR(8) DEFAULT 'FULL',
    
    -- Geographic Scope
    origin_airports         NVARCHAR(MAX) NULL,
    origin_tracons          NVARCHAR(MAX) NULL,
    origin_centers          NVARCHAR(MAX) NULL,
    dest_airports           NVARCHAR(MAX) NULL,
    dest_tracons            NVARCHAR(MAX) NULL,
    dest_centers            NVARCHAR(MAX) NULL,
    
    -- Route-Based Filters
    departure_fix           NVARCHAR(8) NULL,
    arrival_fix             NVARCHAR(8) NULL,
    thru_centers            NVARCHAR(MAX) NULL,
    thru_fixes              NVARCHAR(MAX) NULL,
    use_airway              NVARCHAR(MAX) NULL,
    
    -- Aircraft Filters
    include_ac_cat          NVARCHAR(16) DEFAULT 'ALL',
    include_ac_types        NVARCHAR(MAX) NULL,
    include_carriers        NVARCHAR(MAX) NULL,
    weight_class            NVARCHAR(16) DEFAULT 'ALL',
    altitude_min            INT NULL,
    altitude_max            INT NULL,
    rvsm_filter             NVARCHAR(16) DEFAULT 'ALL',
    
    -- Exemptions
    exempt_airports         NVARCHAR(MAX) NULL,
    exempt_carriers         NVARCHAR(MAX) NULL,
    exempt_flights          NVARCHAR(MAX) NULL,
    exempt_active_only      BIT DEFAULT 0,
    airborne_filter         NVARCHAR(16) DEFAULT 'NOT_AIRBORNE',
    
    -- Metadata
    comments                NVARCHAR(MAX) NULL,
    impacting_condition     NVARCHAR(64) NULL,
    advisory_text           NVARCHAR(MAX) NULL,
    
    -- Display Settings
    color                   CHAR(7) DEFAULT '#e74c3c',
    line_weight             TINYINT DEFAULT 3,
    line_style              NVARCHAR(16) DEFAULT 'solid',
    route_geojson           NVARCHAR(MAX) NULL,
    
    -- Metrics
    total_assigned          INT NULL,
    compliant_count         INT NULL,
    non_compliant_count     INT NULL,
    compliance_rate         DECIMAL(5,2) NULL,
    
    -- Source Tracking
    source_type             NVARCHAR(16) NULL,
    source_id               NVARCHAR(100) NULL,
    discord_message_id      NVARCHAR(256) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Audit
    created_by              NVARCHAR(64) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    activated_at            DATETIME2(0) NULL,
    
    CONSTRAINT UQ_reroutes_guid UNIQUE (reroute_guid)
);
GO

CREATE NONCLUSTERED INDEX IX_reroutes_status ON dbo.tmi_reroutes (status);
CREATE NONCLUSTERED INDEX IX_reroutes_dates ON dbo.tmi_reroutes (start_utc, end_utc);
CREATE NONCLUSTERED INDEX IX_reroutes_name ON dbo.tmi_reroutes (name);
GO

-- Add FK from advisories to reroutes (now that reroutes exists)
ALTER TABLE dbo.tmi_advisories ADD CONSTRAINT FK_advisories_reroute 
    FOREIGN KEY (reroute_id) REFERENCES dbo.tmi_reroutes(reroute_id);
GO

PRINT 'Created table: tmi_reroutes';
GO

-- =============================================================================
-- TABLE 6: tmi_reroute_flights (Flight Assignments to Reroutes)
-- =============================================================================

CREATE TABLE dbo.tmi_reroute_flights (
    id                      INT IDENTITY(1,1) PRIMARY KEY,
    reroute_id              INT NOT NULL,
    flight_key              NVARCHAR(64) NOT NULL,
    callsign                NVARCHAR(16) NOT NULL,
    flight_uid              BIGINT NULL,
    
    -- Flight Context
    dep_icao                NCHAR(4) NULL,
    dest_icao               NCHAR(4) NULL,
    ac_type                 NVARCHAR(8) NULL,
    filed_altitude          INT NULL,
    
    -- Route Capture
    route_at_assign         NVARCHAR(MAX) NULL,
    assigned_route          NVARCHAR(MAX) NULL,
    current_route           NVARCHAR(MAX) NULL,
    current_route_utc       DATETIME2(0) NULL,
    final_route             NVARCHAR(MAX) NULL,
    
    -- Position Tracking
    last_lat                DECIMAL(9,6) NULL,
    last_lon                DECIMAL(10,6) NULL,
    last_altitude           INT NULL,
    last_position_utc       DATETIME2(0) NULL,
    
    -- Compliance Status
    compliance_status       NVARCHAR(16) DEFAULT 'PENDING',
    protected_fixes_crossed NVARCHAR(MAX) NULL,
    avoid_fixes_crossed     NVARCHAR(MAX) NULL,
    compliance_pct          DECIMAL(5,2) NULL,
    compliance_notes        NVARCHAR(MAX) NULL,
    
    -- Timing
    assigned_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    departed_utc            DATETIME2(0) NULL,
    arrived_utc             DATETIME2(0) NULL,
    
    -- Metrics
    route_distance_orig_nm  INT NULL,
    route_distance_new_nm   INT NULL,
    route_delta_nm          INT NULL,
    ete_original_min        INT NULL,
    ete_assigned_min        INT NULL,
    ete_delta_min           INT NULL,
    
    -- Manual Override
    manual_status           BIT DEFAULT 0,
    override_by             NVARCHAR(64) NULL,
    override_utc            DATETIME2(0) NULL,
    override_reason         NVARCHAR(MAX) NULL,
    
    CONSTRAINT FK_reroute_flights_reroute FOREIGN KEY (reroute_id) 
        REFERENCES dbo.tmi_reroutes(reroute_id) ON DELETE CASCADE
);
GO

CREATE NONCLUSTERED INDEX IX_reroute_flights_reroute ON dbo.tmi_reroute_flights (reroute_id);
CREATE NONCLUSTERED INDEX IX_reroute_flights_flight_key ON dbo.tmi_reroute_flights (flight_key);
CREATE NONCLUSTERED INDEX IX_reroute_flights_callsign ON dbo.tmi_reroute_flights (callsign);
CREATE NONCLUSTERED INDEX IX_reroute_flights_compliance ON dbo.tmi_reroute_flights (compliance_status);
GO

PRINT 'Created table: tmi_reroute_flights';
GO

-- =============================================================================
-- TABLE 7: tmi_reroute_compliance_log
-- =============================================================================

CREATE TABLE dbo.tmi_reroute_compliance_log (
    log_id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    reroute_flight_id       INT NOT NULL,
    
    snapshot_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    compliance_status       NVARCHAR(16) NULL,
    compliance_pct          DECIMAL(5,2) NULL,
    
    lat                     DECIMAL(9,6) NULL,
    lon                     DECIMAL(10,6) NULL,
    altitude                INT NULL,
    
    route_string            NVARCHAR(MAX) NULL,
    fixes_crossed           NVARCHAR(MAX) NULL,
    
    CONSTRAINT FK_compliance_log_flight FOREIGN KEY (reroute_flight_id) 
        REFERENCES dbo.tmi_reroute_flights(id) ON DELETE CASCADE
);
GO

CREATE NONCLUSTERED INDEX IX_compliance_log_flight_time 
    ON dbo.tmi_reroute_compliance_log (reroute_flight_id, snapshot_utc);
GO

PRINT 'Created table: tmi_reroute_compliance_log';
GO

-- =============================================================================
-- TABLE 8: tmi_public_routes (Public Route Display)
-- =============================================================================

CREATE TABLE dbo.tmi_public_routes (
    route_id                INT IDENTITY(1,1) PRIMARY KEY,
    route_guid              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    status                  TINYINT NOT NULL DEFAULT 1,
    
    name                    NVARCHAR(64) NOT NULL,
    adv_number              NVARCHAR(16) NULL,
    
    -- Links
    advisory_id             INT NULL,
    reroute_id              INT NULL,
    
    -- Route Content
    route_string            NVARCHAR(MAX) NOT NULL,
    advisory_text           NVARCHAR(MAX) NULL,
    
    -- Display Settings
    color                   CHAR(7) NOT NULL DEFAULT '#e74c3c',
    line_weight             TINYINT NOT NULL DEFAULT 3,
    line_style              NVARCHAR(16) NOT NULL DEFAULT 'solid',
    
    -- Validity Period
    valid_start_utc         DATETIME2(0) NOT NULL,
    valid_end_utc           DATETIME2(0) NOT NULL,
    
    -- Filters / Scope
    constrained_area        NVARCHAR(64) NULL,
    reason                  NVARCHAR(256) NULL,
    origin_filter           NVARCHAR(MAX) NULL,
    dest_filter             NVARCHAR(MAX) NULL,
    facilities              NVARCHAR(MAX) NULL,
    
    -- Geometry
    route_geojson           NVARCHAR(MAX) NULL,
    
    -- Metadata
    created_by              NVARCHAR(64) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    CONSTRAINT FK_public_routes_advisory FOREIGN KEY (advisory_id) REFERENCES dbo.tmi_advisories(advisory_id),
    CONSTRAINT FK_public_routes_reroute FOREIGN KEY (reroute_id) REFERENCES dbo.tmi_reroutes(reroute_id),
    CONSTRAINT UQ_public_routes_guid UNIQUE (route_guid)
);
GO

CREATE NONCLUSTERED INDEX IX_public_routes_status ON dbo.tmi_public_routes (status, valid_start_utc, valid_end_utc);
CREATE NONCLUSTERED INDEX IX_public_routes_name ON dbo.tmi_public_routes (name);
GO

PRINT 'Created table: tmi_public_routes';
GO

-- =============================================================================
-- TABLE 9: tmi_events (Unified Audit Log)
-- =============================================================================

CREATE TABLE dbo.tmi_events (
    event_id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    
    entity_type             NVARCHAR(16) NOT NULL,
    entity_id               INT NOT NULL,
    entity_guid             UNIQUEIDENTIFIER NULL,
    
    program_id              INT NULL,
    flight_uid              BIGINT NULL,
    slot_id                 BIGINT NULL,
    reroute_id              INT NULL,
    
    event_type              NVARCHAR(32) NOT NULL,
    event_detail            NVARCHAR(64) NULL,
    
    field_name              NVARCHAR(64) NULL,
    old_value               NVARCHAR(MAX) NULL,
    new_value               NVARCHAR(MAX) NULL,
    event_data_json         NVARCHAR(MAX) NULL,
    
    source_type             NVARCHAR(16) NOT NULL,
    source_id               NVARCHAR(100) NULL,
    
    actor_id                NVARCHAR(64) NULL,
    actor_name              NVARCHAR(128) NULL,
    actor_ip                NVARCHAR(45) NULL,
    
    event_utc               DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_events_entity ON dbo.tmi_events (entity_type, entity_id);
CREATE NONCLUSTERED INDEX IX_events_time ON dbo.tmi_events (event_utc DESC);
CREATE NONCLUSTERED INDEX IX_events_type ON dbo.tmi_events (event_type);
CREATE NONCLUSTERED INDEX IX_events_program ON dbo.tmi_events (program_id, event_utc) WHERE program_id IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_events_flight ON dbo.tmi_events (flight_uid, event_utc) WHERE flight_uid IS NOT NULL;
GO

PRINT 'Created table: tmi_events';
GO

-- =============================================================================
-- TABLE 10: tmi_advisory_sequences (Advisory Numbering)
-- =============================================================================

CREATE TABLE dbo.tmi_advisory_sequences (
    seq_date                DATE PRIMARY KEY,
    seq_number              SMALLINT NOT NULL DEFAULT 0
);
GO

PRINT 'Created table: tmi_advisory_sequences';
GO

-- =============================================================================
-- VIEWS
-- =============================================================================

CREATE VIEW dbo.vw_tmi_active_entries AS
SELECT * FROM dbo.tmi_entries
WHERE status = 'ACTIVE' AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME());
GO

CREATE VIEW dbo.vw_tmi_active_advisories AS
SELECT * FROM dbo.tmi_advisories
WHERE status = 'ACTIVE' AND (effective_until IS NULL OR effective_until > SYSUTCDATETIME());
GO

CREATE VIEW dbo.vw_tmi_active_programs AS
SELECT * FROM dbo.tmi_programs
WHERE is_active = 1 AND end_utc > SYSUTCDATETIME();
GO

CREATE VIEW dbo.vw_tmi_active_reroutes AS
SELECT * FROM dbo.tmi_reroutes
WHERE status = 2 AND end_utc > SYSUTCDATETIME();
GO

CREATE VIEW dbo.vw_tmi_active_public_routes AS
SELECT * FROM dbo.tmi_public_routes
WHERE status = 1 AND valid_end_utc > SYSUTCDATETIME();
GO

CREATE VIEW dbo.vw_tmi_recent_entries AS
SELECT * FROM dbo.tmi_entries
WHERE created_at > DATEADD(HOUR, -24, SYSUTCDATETIME());
GO

PRINT 'Created views';
GO

-- =============================================================================
-- STORED PROCEDURES
-- =============================================================================

-- Get next advisory number
CREATE PROCEDURE dbo.sp_GetNextAdvisoryNumber
    @next_number NVARCHAR(16) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @today DATE = CAST(SYSUTCDATETIME() AS DATE);
    DECLARE @current_seq INT;
    
    MERGE dbo.tmi_advisory_sequences AS target
    USING (SELECT @today AS seq_date) AS source
    ON target.seq_date = source.seq_date
    WHEN MATCHED THEN UPDATE SET seq_number = seq_number + 1
    WHEN NOT MATCHED THEN INSERT (seq_date, seq_number) VALUES (@today, 1);
    
    SELECT @current_seq = seq_number FROM dbo.tmi_advisory_sequences WHERE seq_date = @today;
    SET @next_number = CONCAT('ADVZY ', RIGHT('000' + CAST(@current_seq AS VARCHAR), 3));
END;
GO

-- Log TMI event
CREATE PROCEDURE dbo.sp_LogTmiEvent
    @entity_type NVARCHAR(16),
    @entity_id INT,
    @entity_guid UNIQUEIDENTIFIER = NULL,
    @event_type NVARCHAR(32),
    @event_detail NVARCHAR(64) = NULL,
    @field_name NVARCHAR(64) = NULL,
    @old_value NVARCHAR(MAX) = NULL,
    @new_value NVARCHAR(MAX) = NULL,
    @source_type NVARCHAR(16),
    @source_id NVARCHAR(100) = NULL,
    @actor_id NVARCHAR(64) = NULL,
    @actor_name NVARCHAR(128) = NULL,
    @actor_ip NVARCHAR(45) = NULL,
    @program_id INT = NULL,
    @flight_uid BIGINT = NULL,
    @slot_id BIGINT = NULL,
    @reroute_id INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    INSERT INTO dbo.tmi_events (
        entity_type, entity_id, entity_guid, event_type, event_detail,
        field_name, old_value, new_value,
        source_type, source_id, actor_id, actor_name, actor_ip,
        program_id, flight_uid, slot_id, reroute_id
    ) VALUES (
        @entity_type, @entity_id, @entity_guid, @event_type, @event_detail,
        @field_name, @old_value, @new_value,
        @source_type, @source_id, @actor_id, @actor_name, @actor_ip,
        @program_id, @flight_uid, @slot_id, @reroute_id
    );
END;
GO

-- Auto-expire and auto-activate based on time
CREATE PROCEDURE dbo.sp_ExpireOldEntries
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    
    -- Expire NTML entries
    UPDATE dbo.tmi_entries SET status = 'EXPIRED', updated_at = @now
    WHERE status = 'ACTIVE' AND valid_until IS NOT NULL AND valid_until < @now;
    
    -- Activate scheduled NTML entries
    UPDATE dbo.tmi_entries SET status = 'ACTIVE', updated_at = @now
    WHERE status = 'SCHEDULED' AND valid_from IS NOT NULL AND valid_from <= @now
      AND (valid_until IS NULL OR valid_until > @now);
    
    -- Expire advisories
    UPDATE dbo.tmi_advisories SET status = 'EXPIRED', updated_at = @now
    WHERE status = 'ACTIVE' AND effective_until IS NOT NULL AND effective_until < @now;
    
    -- Activate scheduled advisories
    UPDATE dbo.tmi_advisories SET status = 'ACTIVE', updated_at = @now
    WHERE status = 'SCHEDULED' AND effective_from IS NOT NULL AND effective_from <= @now
      AND (effective_until IS NULL OR effective_until > @now);
    
    -- Complete GDT programs
    UPDATE dbo.tmi_programs SET status = 'COMPLETED', is_active = 0, updated_at = @now
    WHERE is_active = 1 AND end_utc < @now;
    
    -- Expire public routes
    UPDATE dbo.tmi_public_routes SET status = 2, updated_at = @now
    WHERE status = 1 AND valid_end_utc < @now;
    
    -- Expire reroutes (status 2=active -> 4=expired)
    UPDATE dbo.tmi_reroutes SET status = 4, updated_at = @now
    WHERE status = 2 AND end_utc IS NOT NULL AND end_utc < @now;
END;
GO

-- Get active public routes
CREATE PROCEDURE dbo.sp_GetActivePublicRoutes
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2 = SYSUTCDATETIME();
    
    -- Auto-expire first
    UPDATE dbo.tmi_public_routes SET status = 2, updated_at = @now
    WHERE status = 1 AND valid_end_utc < @now;
    
    -- Return active routes
    SELECT * FROM dbo.tmi_public_routes
    WHERE status = 1 AND valid_start_utc <= @now AND valid_end_utc >= @now
    ORDER BY created_at DESC;
END;
GO

PRINT 'Created stored procedures';
GO

-- =============================================================================
-- VERIFICATION
-- =============================================================================

SELECT 'Tables' AS object_type, name FROM sys.tables WHERE is_ms_shipped = 0 ORDER BY name;
SELECT 'Views' AS object_type, name FROM sys.views WHERE is_ms_shipped = 0 ORDER BY name;
SELECT 'Procedures' AS object_type, name FROM sys.procedures WHERE is_ms_shipped = 0 ORDER BY name;

PRINT '';
PRINT '=============================================================================';
PRINT 'VATSIM_TMI schema deployment complete!';
PRINT '=============================================================================';
PRINT '';
PRINT 'Tables created:';
PRINT '  1. tmi_entries           - NTML log entries';
PRINT '  2. tmi_programs          - GS/GDP programs';
PRINT '  3. tmi_advisories        - Formal advisories';
PRINT '  4. tmi_slots             - GDP slot allocation';
PRINT '  5. tmi_reroutes          - Reroute definitions';
PRINT '  6. tmi_reroute_flights   - Flight assignments';
PRINT '  7. tmi_reroute_compliance_log - Compliance history';
PRINT '  8. tmi_public_routes     - Public route display';
PRINT '  9. tmi_events            - Unified audit log';
PRINT ' 10. tmi_advisory_sequences - Advisory numbering';
PRINT '';
GO
