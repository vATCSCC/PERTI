-- ============================================================================
-- VATSIM_TMI Migration 003: Create tmi_flight_control table
-- Per-Flight TMI Control Assignments (CTD, CTA, Slot, etc.)
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
--
-- This table tracks TMI control assignments for individual flights.
-- References VATSIM_ADL.adl_flight_core via flight_uid.
--
-- CTL_TYPE values (per FSM/FADT spec):
--   GDP, AFP, GS, DAS, GAAP, UDP, COMP, BLKT, ECR, ADPT, ABRG
--
-- Retention Policy:
--   - Hot: 90 days
--   - Cool: 1 year
--   - Cold: Indefinite
-- ============================================================================

USE VATSIM_TMI;
GO

IF OBJECT_ID('dbo.tmi_flight_control', 'U') IS NOT NULL
    DROP TABLE dbo.tmi_flight_control;
GO

CREATE TABLE dbo.tmi_flight_control (
    -- Primary Key
    control_id              BIGINT IDENTITY(1,1) PRIMARY KEY,
    
    -- Flight Reference (from VATSIM_ADL)
    flight_uid              BIGINT NOT NULL,                -- FK to adl_flight_core
    callsign                NVARCHAR(12) NOT NULL,
    
    -- Active Control Assignment
    program_id              INT NULL,                       -- FK to tmi_programs
    slot_id                 BIGINT NULL,                    -- FK to tmi_slots
    
    -- Control Times (FSM-standard, UTC)
    ctd_utc                 DATETIME2(0) NULL,              -- Controlled Time of Departure
    cta_utc                 DATETIME2(0) NULL,              -- Controlled Time of Arrival/Entry
    octd_utc                DATETIME2(0) NULL,              -- Original CTD (never changes after initial)
    octa_utc                DATETIME2(0) NULL,              -- Original CTA (never changes after initial)
    
    -- Slot Identification
    aslot                   NVARCHAR(20) NULL,              -- Assigned slot name (KJFK.091530A)
    
    -- Control Metadata (per FSM spec)
    ctl_elem                NVARCHAR(8) NULL,               -- Control element (airport/FCA)
    ctl_prgm                NVARCHAR(64) NULL,              -- Control program name
    ctl_type                NVARCHAR(8) NULL,               -- GDP, AFP, GS, DAS, GAAP, UDP, COMP, BLKT, ECR, ADPT, ABRG
    
    -- Exemption Status
    ctl_exempt              BIT NOT NULL DEFAULT 0,         -- Was exempt when TMI issued
    ctl_exempt_reason       NVARCHAR(32) NULL,              -- AIRBORNE, DISTANCE, CENTER, CARRIER, TYPE, EARLY, LATE
    
    -- Delay Tracking
    program_delay_min       INT NULL,                       -- Assigned delay (minutes)
    delay_capped            BIT NOT NULL DEFAULT 0,         -- Hit delay limit
    z_slot_delay            INT NULL,                       -- Z-slot delay (unassigned slot delay)
    
    -- Original Estimates (for delay calculation)
    orig_etd_utc            DATETIME2(0) NULL,              -- Original ETD before control
    orig_eta_utc            DATETIME2(0) NULL,              -- Original ETA before control
    orig_ete_min            INT NULL,                       -- Original en route time
    
    -- Slot Management
    sl_hold                 BIT NOT NULL DEFAULT 0,         -- Slot held for substitution
    sl_hold_carrier         NVARCHAR(8) NULL,
    subbable                BIT NOT NULL DEFAULT 1,         -- Can be substituted
    
    -- Ground Stop Specific
    gs_held                 BIT NOT NULL DEFAULT 0,         -- Currently ground stopped
    gs_release_utc          DATETIME2(0) NULL,              -- Scheduled GS release time
    gs_release_sequence     INT NULL,                       -- Release sequence number
    
    -- Pop-up / Re-control Tracking
    is_popup                BIT NOT NULL DEFAULT 0,         -- Flight appeared after TMI start
    is_recontrol            BIT NOT NULL DEFAULT 0,         -- Previously controlled, re-assigned
    popup_detected_utc      DATETIME2(0) NULL,
    popup_lead_time_min     INT NULL,                       -- Lead time when detected
    
    -- ECR (EDCT Change Request) Tracking
    ecr_pending             BIT NOT NULL DEFAULT 0,
    ecr_requested_cta       DATETIME2(0) NULL,
    ecr_requested_by        NVARCHAR(64) NULL,
    ecr_requested_utc       DATETIME2(0) NULL,
    ecr_approved            BIT NULL,
    ecr_approved_utc        DATETIME2(0) NULL,
    
    -- Substitution History
    sub_from_flight_uid     BIGINT NULL,                    -- Substituted from this flight
    sub_to_flight_uid       BIGINT NULL,                    -- Substituted to this flight
    sub_reason              NVARCHAR(32) NULL,
    
    -- Flight Status at Control Time
    flight_status_at_ctl    NVARCHAR(16) NULL,              -- PROPOSED, SCHEDULED, ACTIVE, DEPARTED, AIRBORNE
    dep_airport             NVARCHAR(4) NULL,
    arr_airport             NVARCHAR(4) NULL,
    dep_center              NVARCHAR(4) NULL,               -- Departure ARTCC
    arr_center              NVARCHAR(4) NULL,               -- Arrival ARTCC
    
    -- Compliance Tracking (for VATSIM)
    compliance_status       NVARCHAR(16) NULL,              -- PENDING, COMPLIANT, EARLY, LATE, NO_SHOW
    actual_dep_utc          DATETIME2(0) NULL,              -- Actual departure time
    actual_arr_utc          DATETIME2(0) NULL,              -- Actual arrival/entry time
    compliance_delta_min    INT NULL,                       -- Minutes early(-) or late(+)
    
    -- Retention Management
    is_archived             BIT NOT NULL DEFAULT 0,
    archive_tier            TINYINT NULL,                   -- 1=Hot, 2=Cool, 3=Cold
    archived_utc            DATETIME2(0) NULL,
    
    -- Audit
    created_utc             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    modified_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    control_assigned_utc    DATETIME2(0) NULL,              -- When control was first assigned
    control_released_utc    DATETIME2(0) NULL,              -- When control was released
    
    -- Constraints
    CONSTRAINT CK_tmi_flight_control_type CHECK (ctl_type IS NULL OR ctl_type IN 
        ('GDP', 'AFP', 'GS', 'DAS', 'GAAP', 'UDP', 'COMP', 'BLKT', 'ECR', 'ADPT', 'ABRG', 'CTOP')),
    CONSTRAINT CK_tmi_flight_control_exempt CHECK (ctl_exempt_reason IS NULL OR ctl_exempt_reason IN 
        ('AIRBORNE', 'DISTANCE', 'CENTER', 'CARRIER', 'TYPE', 'EARLY', 'LATE', 'MANUAL', 'OTHER')),
    CONSTRAINT CK_tmi_flight_control_compliance CHECK (compliance_status IS NULL OR compliance_status IN 
        ('PENDING', 'COMPLIANT', 'EARLY', 'LATE', 'NO_SHOW')),
    
    -- Foreign Keys
    CONSTRAINT FK_tmi_flight_control_program FOREIGN KEY (program_id)
        REFERENCES dbo.tmi_programs(program_id),
    CONSTRAINT FK_tmi_flight_control_slot FOREIGN KEY (slot_id)
        REFERENCES dbo.tmi_slots(slot_id)
);
GO

-- Primary lookups
CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_flight_control_flight_program 
    ON dbo.tmi_flight_control(flight_uid, program_id);

CREATE NONCLUSTERED INDEX IX_tmi_flight_control_flight 
    ON dbo.tmi_flight_control(flight_uid)
    INCLUDE (program_id, ctd_utc, cta_utc, aslot, ctl_type);

CREATE NONCLUSTERED INDEX IX_tmi_flight_control_callsign 
    ON dbo.tmi_flight_control(callsign)
    INCLUDE (program_id, ctd_utc, cta_utc);

-- Program-based queries
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_program 
    ON dbo.tmi_flight_control(program_id, cta_utc)
    INCLUDE (flight_uid, callsign, ctl_type, ctl_exempt, program_delay_min);

CREATE NONCLUSTERED INDEX IX_tmi_flight_control_program_status 
    ON dbo.tmi_flight_control(program_id, ctl_exempt, gs_held)
    INCLUDE (flight_uid, callsign, ctd_utc, cta_utc);

-- Slot lookup
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_slot 
    ON dbo.tmi_flight_control(slot_id)
    WHERE slot_id IS NOT NULL;

-- Pop-up detection
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_popup 
    ON dbo.tmi_flight_control(program_id, is_popup)
    WHERE is_popup = 1
    INCLUDE (flight_uid, popup_detected_utc, popup_lead_time_min);

-- Ground stop tracking
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_gs 
    ON dbo.tmi_flight_control(program_id, gs_held, gs_release_utc)
    WHERE gs_held = 1;

-- ECR pending
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_ecr 
    ON dbo.tmi_flight_control(ecr_pending)
    WHERE ecr_pending = 1
    INCLUDE (program_id, flight_uid, ecr_requested_cta);

-- Compliance monitoring
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_compliance 
    ON dbo.tmi_flight_control(program_id, compliance_status)
    WHERE compliance_status IS NOT NULL
    INCLUDE (flight_uid, ctd_utc, actual_dep_utc, compliance_delta_min);

-- Retention/archival
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_retention 
    ON dbo.tmi_flight_control(is_archived, created_utc, archive_tier)
    WHERE is_archived = 0;

-- Airport queries
CREATE NONCLUSTERED INDEX IX_tmi_flight_control_arr_airport 
    ON dbo.tmi_flight_control(arr_airport, cta_utc)
    WHERE arr_airport IS NOT NULL
    INCLUDE (program_id, flight_uid, callsign, ctl_type);
GO

-- ============================================================================
-- Trigger: Update modified_utc on UPDATE
-- ============================================================================
CREATE OR ALTER TRIGGER trg_tmi_flight_control_modified
ON dbo.tmi_flight_control
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE t
    SET modified_utc = SYSUTCDATETIME()
    FROM dbo.tmi_flight_control t
    INNER JOIN inserted i ON t.control_id = i.control_id;
END;
GO

PRINT 'Migration 003: tmi_flight_control table created successfully';
GO
