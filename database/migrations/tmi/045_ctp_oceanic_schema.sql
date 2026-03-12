-- ============================================================================
-- VATSIM_TMI Migration 045: CTP Oceanic Slot Management Schema
--
-- Purpose: Tables for managing non-event flights during CTP (Cross the Pond)
--          events. Supports three-perspective model (NA/Oceanic/EU),
--          route segment editing, EDCT assignment, and compliance monitoring.
--
-- Tables:
--   1. ctp_sessions          - CTP event management sessions
--   2. ctp_flight_control    - Per-flight CTP management
--   3. ctp_audit_log         - Action audit trail
--   4. ctp_route_templates   - CTP-specific route templates (NAT tracks, etc.)
--
-- Aligns with:
--   - Existing tmi_programs/tmi_flight_control patterns
--   - tmi_flow_events (CTP event linkage)
--   - adl_flight_planned_crossings (oceanic detection)
-- ============================================================================

USE VATSIM_TMI;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== VATSIM_TMI Migration 045: CTP Oceanic Slot Management ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Table 1: ctp_sessions
-- CTP event management sessions (one per CTP event direction)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_sessions') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ctp_sessions (
        session_id              INT IDENTITY(1,1) PRIMARY KEY,

        -- Event linkage
        flow_event_id           INT NULL,                           -- FK to tmi_flow_events
        program_id              INT NULL,                           -- FK to tmi_programs (created when slots generated)

        -- Session identification
        session_name            NVARCHAR(64) NOT NULL,              -- e.g. "CTP2026W-NON-EVENT"
        direction               NVARCHAR(8) NOT NULL,               -- WESTBOUND, EASTBOUND, BOTH

        -- Constrained FIRs (oceanic boundaries)
        constrained_firs        NVARCHAR(MAX) NULL,                 -- JSON: ["CZQX","BIRD","EGGX","LPPO"]

        -- Constraint time window
        constraint_window_start DATETIME2(0) NOT NULL,              -- When oceanic constraint active
        constraint_window_end   DATETIME2(0) NOT NULL,

        -- Slot parameters
        slot_interval_min       INT NOT NULL DEFAULT 5,             -- Minutes between slots
        max_slots_per_hour      INT NULL,                           -- Rate cap (NULL = unlimited)

        -- Route validation rules
        validation_rules_json   NVARCHAR(MAX) NULL,                 -- JSON: entry/exit points, altitude range, etc.

        -- Organization management
        managing_orgs           NVARCHAR(MAX) NULL,                 -- JSON: ["VATCSCC","CANOC","ECFMP"]
        perspective_orgs_json   NVARCHAR(MAX) NULL,                 -- JSON: {"NA":["DCC","CANOC"],"OCEANIC":["GANDER","SHANWICK"],"EU":["ECFMP"],"GLOBAL":["DCC","CANOC","ECFMP"]}

        -- Status
        status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',  -- DRAFT, ACTIVE, MONITORING, COMPLETED, CANCELLED

        -- Stats (updated periodically)
        total_flights           INT NOT NULL DEFAULT 0,
        slotted_flights         INT NOT NULL DEFAULT 0,
        modified_flights        INT NOT NULL DEFAULT 0,
        excluded_flights        INT NOT NULL DEFAULT 0,

        -- Audit
        created_by              NVARCHAR(16) NULL,                  -- VATSIM CID
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        -- Constraints
        CONSTRAINT CK_ctp_sessions_direction CHECK (direction IN ('WESTBOUND', 'EASTBOUND', 'BOTH')),
        CONSTRAINT CK_ctp_sessions_status CHECK (status IN ('DRAFT', 'ACTIVE', 'MONITORING', 'COMPLETED', 'CANCELLED')),
        CONSTRAINT FK_ctp_sessions_flow_event FOREIGN KEY (flow_event_id)
            REFERENCES dbo.tmi_flow_events(event_id),
        CONSTRAINT FK_ctp_sessions_program FOREIGN KEY (program_id)
            REFERENCES dbo.tmi_programs(program_id)
    );

    CREATE NONCLUSTERED INDEX IX_ctp_sessions_status
        ON dbo.ctp_sessions(status)
        WHERE status IN ('DRAFT', 'ACTIVE', 'MONITORING');

    CREATE NONCLUSTERED INDEX IX_ctp_sessions_flow_event
        ON dbo.ctp_sessions(flow_event_id)
        WHERE flow_event_id IS NOT NULL;

    PRINT 'Created table: dbo.ctp_sessions';
END
ELSE
    PRINT 'Table dbo.ctp_sessions already exists - skipping';
GO

-- ============================================================================
-- Table 2: ctp_flight_control
-- Per-flight CTP management (the main working table)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_flight_control') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ctp_flight_control (
        ctp_control_id          BIGINT IDENTITY(1,1) PRIMARY KEY,
        session_id              INT NOT NULL,
        flight_uid              BIGINT NOT NULL,                    -- FK to adl_flight_core
        callsign                NVARCHAR(12) NOT NULL,

        -- Link to tmi_flight_control (when EDCT assigned)
        tmi_control_id          BIGINT NULL,

        -- Snapshot (denormalized for fast table display)
        dep_airport             NVARCHAR(4) NULL,
        arr_airport             NVARCHAR(4) NULL,
        dep_artcc               NVARCHAR(8) NULL,
        arr_artcc               NVARCHAR(8) NULL,
        aircraft_type           NVARCHAR(8) NULL,
        filed_route             NVARCHAR(MAX) NULL,
        filed_altitude          INT NULL,

        -- Oceanic crossing info (from adl_flight_planned_crossings)
        oceanic_entry_fir       NVARCHAR(8) NULL,                   -- First constrained FIR
        oceanic_exit_fir        NVARCHAR(8) NULL,                   -- Last constrained FIR
        oceanic_entry_fix       NVARCHAR(16) NULL,                  -- Entry waypoint
        oceanic_exit_fix        NVARCHAR(16) NULL,                  -- Exit waypoint
        oceanic_entry_utc       DATETIME2(0) NULL,                  -- Predicted entry time
        oceanic_exit_utc        DATETIME2(0) NULL,                  -- Predicted exit time

        -- Route (full)
        route_status            NVARCHAR(16) NOT NULL DEFAULT 'FILED',  -- FILED, MODIFIED, VALIDATED, REJECTED
        modified_route          NVARCHAR(MAX) NULL,                 -- Full TMU-assigned route (all segments concatenated)
        modified_altitude       INT NULL,
        route_geojson           NVARCHAR(MAX) NULL,                 -- Pre-computed full route for map
        route_validation_json   NVARCHAR(MAX) NULL,                 -- Cached validation result

        -- Route segments (three-perspective decomposition)
        seg_na_route            NVARCHAR(MAX) NULL,                 -- NA: dep -> oceanic entry fix
        seg_oceanic_route       NVARCHAR(MAX) NULL,                 -- Oceanic: entry fix -> exit fix
        seg_eu_route            NVARCHAR(MAX) NULL,                 -- EU: oceanic exit fix -> arr

        seg_na_status           NVARCHAR(16) NOT NULL DEFAULT 'FILED',      -- FILED, MODIFIED, VALIDATED
        seg_oceanic_status      NVARCHAR(16) NOT NULL DEFAULT 'FILED',
        seg_eu_status           NVARCHAR(16) NOT NULL DEFAULT 'FILED',

        seg_na_modified_by      NVARCHAR(16) NULL,
        seg_na_modified_at      DATETIME2(0) NULL,
        seg_oceanic_modified_by NVARCHAR(16) NULL,
        seg_oceanic_modified_at DATETIME2(0) NULL,
        seg_eu_modified_by      NVARCHAR(16) NULL,
        seg_eu_modified_at      DATETIME2(0) NULL,

        -- EDCT
        edct_status             NVARCHAR(16) NOT NULL DEFAULT 'NONE',   -- NONE, ASSIGNED, DELIVERED, COMPLIANT, NON_COMPLIANT
        edct_utc                DATETIME2(0) NULL,                  -- Assigned EDCT
        original_etd_utc        DATETIME2(0) NULL,                  -- Original ETD before EDCT
        slot_delay_min          INT NULL,
        edct_assigned_by        NVARCHAR(16) NULL,
        edct_assigned_at        DATETIME2(0) NULL,

        -- Compliance
        actual_dep_utc          DATETIME2(0) NULL,
        compliance_delta_min    INT NULL,                           -- Actual - assigned (negative = early)
        compliance_status       NVARCHAR(16) NULL,                  -- EARLY, ON_TIME, LATE, NO_SHOW, PENDING

        -- Flags
        is_event_flight         BIT NOT NULL DEFAULT 0,             -- Detected as event participant
        is_excluded             BIT NOT NULL DEFAULT 0,             -- TMU excluded
        is_priority             BIT NOT NULL DEFAULT 0,             -- Priority handling
        notes                   NVARCHAR(500) NULL,

        -- SWIM push tracking
        swim_push_version       INT NOT NULL DEFAULT 0,
        swim_pushed_at          DATETIME2(0) NULL,

        -- Audit
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        -- Constraints
        CONSTRAINT CK_ctp_fc_route_status CHECK (route_status IN ('FILED', 'MODIFIED', 'VALIDATED', 'REJECTED')),
        CONSTRAINT CK_ctp_fc_seg_na_status CHECK (seg_na_status IN ('FILED', 'MODIFIED', 'VALIDATED')),
        CONSTRAINT CK_ctp_fc_seg_oceanic_status CHECK (seg_oceanic_status IN ('FILED', 'MODIFIED', 'VALIDATED')),
        CONSTRAINT CK_ctp_fc_seg_eu_status CHECK (seg_eu_status IN ('FILED', 'MODIFIED', 'VALIDATED')),
        CONSTRAINT CK_ctp_fc_edct_status CHECK (edct_status IN ('NONE', 'ASSIGNED', 'DELIVERED', 'COMPLIANT', 'NON_COMPLIANT')),
        CONSTRAINT CK_ctp_fc_compliance CHECK (compliance_status IS NULL OR compliance_status IN ('EARLY', 'ON_TIME', 'LATE', 'NO_SHOW', 'PENDING')),
        CONSTRAINT FK_ctp_fc_session FOREIGN KEY (session_id)
            REFERENCES dbo.ctp_sessions(session_id),
        CONSTRAINT UQ_ctp_fc_session_flight UNIQUE (session_id, flight_uid)
    );

    -- Primary list query covering index
    CREATE NONCLUSTERED INDEX IX_ctp_fc_list
        ON dbo.ctp_flight_control(session_id, edct_status)
        INCLUDE (callsign, dep_airport, arr_airport, oceanic_entry_utc, edct_utc, route_status, is_excluded);

    -- Lookup by flight
    CREATE NONCLUSTERED INDEX IX_ctp_fc_flight
        ON dbo.ctp_flight_control(flight_uid)
        INCLUDE (session_id, callsign, edct_status, edct_utc);

    -- Sort by oceanic entry time
    CREATE NONCLUSTERED INDEX IX_ctp_fc_entry_time
        ON dbo.ctp_flight_control(session_id, oceanic_entry_utc)
        INCLUDE (callsign, dep_airport, arr_airport, edct_status, edct_utc);

    -- EDCT monitoring
    CREATE NONCLUSTERED INDEX IX_ctp_fc_edct
        ON dbo.ctp_flight_control(session_id, edct_utc)
        WHERE edct_status IN ('ASSIGNED', 'DELIVERED')
        INCLUDE (callsign, flight_uid, compliance_status);

    -- Route status filter
    CREATE NONCLUSTERED INDEX IX_ctp_fc_route_status
        ON dbo.ctp_flight_control(session_id, route_status)
        INCLUDE (callsign, seg_na_status, seg_oceanic_status, seg_eu_status);

    -- Excluded flights filter
    CREATE NONCLUSTERED INDEX IX_ctp_fc_excluded
        ON dbo.ctp_flight_control(session_id, is_excluded)
        WHERE is_excluded = 1;

    -- Airport filters
    CREATE NONCLUSTERED INDEX IX_ctp_fc_dep_airport
        ON dbo.ctp_flight_control(session_id, dep_airport)
        INCLUDE (callsign, arr_airport, oceanic_entry_utc);

    CREATE NONCLUSTERED INDEX IX_ctp_fc_arr_airport
        ON dbo.ctp_flight_control(session_id, arr_airport)
        INCLUDE (callsign, dep_airport, oceanic_entry_utc);

    -- Oceanic FIR filter
    CREATE NONCLUSTERED INDEX IX_ctp_fc_oceanic_entry_fir
        ON dbo.ctp_flight_control(session_id, oceanic_entry_fir)
        WHERE oceanic_entry_fir IS NOT NULL;

    PRINT 'Created table: dbo.ctp_flight_control';
END
ELSE
    PRINT 'Table dbo.ctp_flight_control already exists - skipping';
GO

-- Update trigger for ctp_flight_control
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_sessions') AND type = 'U')
   AND EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_flight_control') AND type = 'U')
BEGIN
    EXEC('
    CREATE OR ALTER TRIGGER trg_ctp_flight_control_modified
    ON dbo.ctp_flight_control
    AFTER UPDATE
    AS
    BEGIN
        SET NOCOUNT ON;
        UPDATE t
        SET updated_at = SYSUTCDATETIME()
        FROM dbo.ctp_flight_control t
        INNER JOIN inserted i ON t.ctp_control_id = i.ctp_control_id;
    END;
    ');
    PRINT 'Created trigger: trg_ctp_flight_control_modified';
END
GO

-- ============================================================================
-- Table 3: ctp_audit_log
-- Action audit trail for CTP operations
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_audit_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ctp_audit_log (
        log_id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
        session_id              INT NOT NULL,
        ctp_control_id          BIGINT NULL,                        -- Flight-specific actions

        -- Action
        action_type             NVARCHAR(32) NOT NULL,              -- ROUTE_MODIFY, EDCT_ASSIGN, EDCT_REMOVE, FLIGHT_EXCLUDE, etc.
        segment                 NVARCHAR(8) NULL,                   -- NA, OCEANIC, EU (for route actions)
        action_detail_json      NVARCHAR(MAX) NULL,                 -- Before/after snapshot

        -- Audit
        performed_by            NVARCHAR(16) NOT NULL,              -- VATSIM CID
        performed_at            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        -- Constraints
        CONSTRAINT FK_ctp_audit_session FOREIGN KEY (session_id)
            REFERENCES dbo.ctp_sessions(session_id),
        CONSTRAINT FK_ctp_audit_flight FOREIGN KEY (ctp_control_id)
            REFERENCES dbo.ctp_flight_control(ctp_control_id)
    );

    CREATE NONCLUSTERED INDEX IX_ctp_audit_session
        ON dbo.ctp_audit_log(session_id, performed_at DESC)
        INCLUDE (action_type, ctp_control_id);

    CREATE NONCLUSTERED INDEX IX_ctp_audit_flight
        ON dbo.ctp_audit_log(ctp_control_id, performed_at DESC)
        WHERE ctp_control_id IS NOT NULL;

    PRINT 'Created table: dbo.ctp_audit_log';
END
ELSE
    PRINT 'Table dbo.ctp_audit_log already exists - skipping';
GO

-- ============================================================================
-- Table 4: ctp_route_templates
-- CTP-specific route templates (NAT tracks, random routes, custom routings)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_route_templates') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ctp_route_templates (
        template_id             INT IDENTITY(1,1) PRIMARY KEY,
        session_id              INT NULL,                           -- NULL = global template, non-NULL = session-specific

        -- Template identification
        segment                 NVARCHAR(8) NOT NULL,               -- NA, OCEANIC, EU
        template_name           NVARCHAR(64) NOT NULL,              -- e.g. "NAT-A", "Random Route KJFK-EGLL-1"

        -- Applicability filters
        origin_filter           NVARCHAR(MAX) NULL,                 -- JSON: airports/ARTCCs/FIRs
        dest_filter             NVARCHAR(MAX) NULL,                 -- JSON: same
        for_event_flights       BIT NULL,                           -- NULL=both, 1=event only, 0=non-event only

        -- Route
        route_string            NVARCHAR(MAX) NOT NULL,             -- The suggested route
        altitude_range          NVARCHAR(32) NULL,                  -- e.g. "350-410"

        -- Ordering
        priority                INT NOT NULL DEFAULT 50,            -- Lower = higher priority in suggestions
        is_active               BIT NOT NULL DEFAULT 1,

        -- Audit
        created_by              NVARCHAR(16) NULL,
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        -- Constraints
        CONSTRAINT CK_ctp_rt_segment CHECK (segment IN ('NA', 'OCEANIC', 'EU')),
        CONSTRAINT FK_ctp_rt_session FOREIGN KEY (session_id)
            REFERENCES dbo.ctp_sessions(session_id)
    );

    CREATE NONCLUSTERED INDEX IX_ctp_rt_session
        ON dbo.ctp_route_templates(session_id, segment, is_active)
        WHERE is_active = 1;

    CREATE NONCLUSTERED INDEX IX_ctp_rt_global
        ON dbo.ctp_route_templates(segment, is_active)
        WHERE session_id IS NULL AND is_active = 1;

    PRINT 'Created table: dbo.ctp_route_templates';
END
ELSE
    PRINT 'Table dbo.ctp_route_templates already exists - skipping';
GO

-- ============================================================================
-- Update trigger for ctp_sessions
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_sessions') AND type = 'U')
BEGIN
    EXEC('
    CREATE OR ALTER TRIGGER trg_ctp_sessions_modified
    ON dbo.ctp_sessions
    AFTER UPDATE
    AS
    BEGIN
        SET NOCOUNT ON;
        UPDATE t
        SET updated_at = SYSUTCDATETIME()
        FROM dbo.ctp_sessions t
        INNER JOIN inserted i ON t.session_id = i.session_id;
    END;
    ');
    PRINT 'Created trigger: trg_ctp_sessions_modified';
END
GO

-- ============================================================================
-- Migration complete
-- ============================================================================
PRINT '';
PRINT '====================================================================';
PRINT 'Migration 045: CTP Oceanic Slot Management schema completed';
PRINT 'Tables created: ctp_sessions, ctp_flight_control, ctp_audit_log, ctp_route_templates';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '====================================================================';
GO
