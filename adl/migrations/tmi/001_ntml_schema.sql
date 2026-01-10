-- ============================================================================
-- NTML (National Traffic Management Log) Schema
-- 
-- Migration for unified GS/GDP program management
-- 
-- Tables Created:
--   dbo.ntml              - Program registry (GS, GDP, AFP, etc.)
--   dbo.ntml_info         - Program event log / audit trail
--   dbo.ntml_slots        - Arrival slot allocation (GDP only)
--
-- Tables Altered:
--   dbo.adl_flight_tmi    - Enhanced with GS/GDP assignment fields
--
-- Run Order: After ADL core migrations
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== NTML Schema Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. NTML - National Traffic Management Log (Program Registry)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ntml') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ntml (
        -- Primary Key
        program_id          INT IDENTITY(1,1) PRIMARY KEY,
        program_guid        UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- PROGRAM IDENTIFICATION
        -- ═══════════════════════════════════════════════════════════════════
        
        ctl_element         NVARCHAR(8) NOT NULL,            -- Airport (KJFK) or FCA (FCA001)
        element_type        NVARCHAR(8) NOT NULL DEFAULT 'APT',  -- APT, FCA
        program_type        NVARCHAR(16) NOT NULL,           -- GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP, CTOP
        program_name        AS (ctl_element + '-' + program_type + '-' + 
                               FORMAT(start_utc, 'MMddHHmm')),  -- Computed: KJFK-GS-01091530
        adv_number          NVARCHAR(16) NULL,               -- Advisory number (ADVZY 001)
        
        -- ═══════════════════════════════════════════════════════════════════
        -- PROGRAM TIMES
        -- ═══════════════════════════════════════════════════════════════════
        
        start_utc           DATETIME2(0) NOT NULL,
        end_utc             DATETIME2(0) NOT NULL,
        cumulative_start    DATETIME2(0) NULL,               -- For extensions (original start)
        cumulative_end      DATETIME2(0) NULL,               -- For extensions (latest end)
        model_time_utc      DATETIME2(0) NULL,               -- ADL snapshot used for modeling
        
        -- ═══════════════════════════════════════════════════════════════════
        -- PROGRAM STATUS
        -- ═══════════════════════════════════════════════════════════════════
        
        status              NVARCHAR(16) NOT NULL DEFAULT 'PROPOSED',
                            -- PROPOSED, ACTIVE, COMPLETED, PURGED, SUPERSEDED
        is_proposed         BIT DEFAULT 1,
        is_active           BIT DEFAULT 0,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- GDP RATE PARAMETERS (NULL for GS)
        -- ═══════════════════════════════════════════════════════════════════
        
        program_rate        INT NULL,                        -- Default arrivals/hour
        reserve_rate        INT NULL,                        -- Reserved slots/hour for pop-ups
        delay_limit_min     INT DEFAULT 180,                 -- Maximum assignable delay
        target_delay_mult   DECIMAL(3,2) DEFAULT 1.0,        -- Target delay multiplier
        
        -- Detailed hourly rates (JSON objects keyed by hour 0-23)
        rates_hourly_json   NVARCHAR(MAX) NULL,              -- {"0":30,"1":30,...}
        reserve_hourly_json NVARCHAR(MAX) NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- SCOPE DEFINITION (JSON)
        -- ═══════════════════════════════════════════════════════════════════
        
        -- Scope: which flights are affected
        scope_type          NVARCHAR(16) NULL,               -- TIER, DISTANCE, MANUAL
        scope_tier          TINYINT NULL,                    -- 1, 2, 3
        scope_distance_nm   INT NULL,                        -- For distance-based
        scope_json          NVARCHAR(MAX) NULL,              -- Full scope definition
        
        -- ═══════════════════════════════════════════════════════════════════
        -- EXEMPTIONS (JSON)
        -- ═══════════════════════════════════════════════════════════════════
        
        exemptions_json     NVARCHAR(MAX) NULL,              -- Exemption rules
        exempt_airborne     BIT DEFAULT 0,                   -- Exempt all airborne flights
        exempt_within_min   INT NULL,                        -- Exempt flights departing within X min
        
        -- ═══════════════════════════════════════════════════════════════════
        -- FLIGHT FILTERS
        -- ═══════════════════════════════════════════════════════════════════
        
        flt_incl_carrier    NVARCHAR(512) NULL,              -- Carrier filter (space-delimited)
        flt_incl_type       NVARCHAR(8) NULL,                -- ALL, JET, PROP
        flt_incl_fix        NVARCHAR(8) NULL,                -- Arrival fix filter
        
        -- ═══════════════════════════════════════════════════════════════════
        -- IMPACTING CONDITION / CAUSE
        -- ═══════════════════════════════════════════════════════════════════
        
        impacting_condition NVARCHAR(64) NULL,               -- WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER
        cause_text          NVARCHAR(512) NULL,
        comments            NVARCHAR(MAX) NULL,
        prob_extension      NVARCHAR(8) NULL,                -- LOW, MEDIUM, HIGH
        
        -- ═══════════════════════════════════════════════════════════════════
        -- REVISION TRACKING
        -- ═══════════════════════════════════════════════════════════════════
        
        revision_number     INT DEFAULT 0,
        parent_program_id   INT NULL,                        -- FK to previous revision / predecessor
        successor_program_id INT NULL,                       -- FK to successor (e.g., GS->GDP)
        
        -- ═══════════════════════════════════════════════════════════════════
        -- COMPUTED METRICS (populated after simulation/activation)
        -- ═══════════════════════════════════════════════════════════════════
        
        total_flights       INT NULL,
        controlled_flights  INT NULL,
        exempt_flights      INT NULL,
        airborne_flights    INT NULL,
        avg_delay_min       DECIMAL(8,2) NULL,
        max_delay_min       INT NULL,
        total_delay_min     BIGINT NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- AUDIT FIELDS
        -- ═══════════════════════════════════════════════════════════════════
        
        created_by          NVARCHAR(64) NULL,
        created_utc         DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        modified_by         NVARCHAR(64) NULL,
        modified_utc        DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        activated_utc       DATETIME2(0) NULL,
        activated_by        NVARCHAR(64) NULL,
        purged_utc          DATETIME2(0) NULL,
        purged_by           NVARCHAR(64) NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- CONSTRAINTS
        -- ═══════════════════════════════════════════════════════════════════
        
        CONSTRAINT FK_ntml_parent FOREIGN KEY (parent_program_id)
            REFERENCES dbo.ntml(program_id),
        CONSTRAINT FK_ntml_successor FOREIGN KEY (successor_program_id)
            REFERENCES dbo.ntml(program_id),
        CONSTRAINT CK_ntml_program_type CHECK (program_type IN 
            ('GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP', 'CTOP', 'COMPRESSION', 'BLANKET')),
        CONSTRAINT CK_ntml_status CHECK (status IN 
            ('PROPOSED', 'ACTIVE', 'COMPLETED', 'PURGED', 'SUPERSEDED'))
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_ntml_element ON dbo.ntml (ctl_element, status);
    CREATE NONCLUSTERED INDEX IX_ntml_active ON dbo.ntml (is_active, start_utc, end_utc) WHERE is_active = 1;
    CREATE NONCLUSTERED INDEX IX_ntml_guid ON dbo.ntml (program_guid);
    CREATE NONCLUSTERED INDEX IX_ntml_type_status ON dbo.ntml (program_type, status);
    CREATE NONCLUSTERED INDEX IX_ntml_created ON dbo.ntml (created_utc DESC);
    
    PRINT 'Created table dbo.ntml';
END
ELSE
BEGIN
    PRINT 'Table dbo.ntml already exists - skipping';
END
GO

-- ============================================================================
-- 2. NTML_INFO - Program Event Log / Audit Trail
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ntml_info') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ntml_info (
        event_id            BIGINT IDENTITY(1,1) PRIMARY KEY,
        program_id          INT NOT NULL,
        flight_uid          BIGINT NULL,
        slot_id             BIGINT NULL,
        
        -- Event classification
        event_type          NVARCHAR(32) NOT NULL,
        -- Program events:
        --   PROGRAM_CREATED, PROGRAM_MODELED, PROGRAM_ACTIVATED, PROGRAM_REVISED,
        --   PROGRAM_EXTENDED, PROGRAM_COMPRESSED, PROGRAM_PURGED, GS_TO_GDP
        -- Flight events:
        --   FLIGHT_CONTROLLED, FLIGHT_EXEMPTED, FLIGHT_POPUP, FLIGHT_RECONTROL,
        --   FLIGHT_CANCELLED, FLIGHT_RELEASED
        -- Slot events (GDP):
        --   SLOT_ASSIGNED, SLOT_BRIDGED, SLOT_HELD, SLOT_RELEASED
        -- ECR events:
        --   ECR_REQUESTED, ECR_APPROVED, ECR_DENIED
        -- Substitution events:
        --   SUB_REQUESTED, SUB_APPROVED, SUB_DENIED
        
        event_subtype       NVARCHAR(32) NULL,               -- Additional classification
        event_details_json  NVARCHAR(MAX) NULL,              -- Structured details
        event_message       NVARCHAR(512) NULL,              -- Human-readable message
        
        -- Actor
        performed_by        NVARCHAR(64) NULL,
        performed_utc       DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT FK_ntml_info_program FOREIGN KEY (program_id)
            REFERENCES dbo.ntml(program_id) ON DELETE CASCADE
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_ntml_info_program ON dbo.ntml_info (program_id, performed_utc DESC);
    CREATE NONCLUSTERED INDEX IX_ntml_info_flight ON dbo.ntml_info (flight_uid, performed_utc DESC) WHERE flight_uid IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_ntml_info_type ON dbo.ntml_info (event_type, performed_utc DESC);
    
    PRINT 'Created table dbo.ntml_info';
END
ELSE
BEGIN
    PRINT 'Table dbo.ntml_info already exists - skipping';
END
GO

-- ============================================================================
-- 3. NTML_SLOTS - Arrival Slot Allocation (GDP only)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ntml_slots') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ntml_slots (
        slot_id             BIGINT IDENTITY(1,1) PRIMARY KEY,
        program_id          INT NOT NULL,
        
        -- Slot identification (FSM-style: KJFK.091530A)
        slot_name           NVARCHAR(16) NOT NULL,
        slot_index          INT NOT NULL,
        slot_time_utc       DATETIME2(0) NOT NULL,
        
        -- Slot type and status
        slot_type           NVARCHAR(16) NOT NULL DEFAULT 'REGULAR',
                            -- REGULAR, RESERVED, UNASSIGNED
        slot_status         NVARCHAR(16) NOT NULL DEFAULT 'OPEN',
                            -- OPEN, ASSIGNED, BRIDGED, HELD, CANCELLED
        
        -- Bin tracking (15-min granularity)
        bin_hour            TINYINT NOT NULL,
        bin_quarter         TINYINT NOT NULL,                -- 0, 15, 30, 45
        
        -- Assignment
        assigned_flight_uid BIGINT NULL,
        assigned_callsign   NVARCHAR(12) NULL,
        assigned_carrier    NVARCHAR(8) NULL,
        assigned_origin     NVARCHAR(4) NULL,
        assigned_utc        DATETIME2(0) NULL,
        
        -- Slot management
        sl_hold             BIT DEFAULT 0,
        sl_hold_carrier     NVARCHAR(8) NULL,
        subbable            BIT DEFAULT 1,
        
        -- Bridging (for SCS/ECR)
        bridge_from_slot    BIGINT NULL,
        bridge_to_slot      BIGINT NULL,
        
        -- Audit
        created_utc         DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        modified_utc        DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT FK_ntml_slots_program FOREIGN KEY (program_id)
            REFERENCES dbo.ntml(program_id) ON DELETE CASCADE,
        CONSTRAINT FK_ntml_slots_flight FOREIGN KEY (assigned_flight_uid)
            REFERENCES dbo.adl_flight_core(flight_uid),
        CONSTRAINT CK_ntml_slots_type CHECK (slot_type IN ('REGULAR', 'RESERVED', 'UNASSIGNED')),
        CONSTRAINT CK_ntml_slots_status CHECK (slot_status IN ('OPEN', 'ASSIGNED', 'BRIDGED', 'HELD', 'CANCELLED'))
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_ntml_slots_program_time ON dbo.ntml_slots (program_id, slot_time_utc);
    CREATE NONCLUSTERED INDEX IX_ntml_slots_flight ON dbo.ntml_slots (assigned_flight_uid) WHERE assigned_flight_uid IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_ntml_slots_status ON dbo.ntml_slots (program_id, slot_status, slot_type);
    CREATE UNIQUE NONCLUSTERED INDEX IX_ntml_slots_name ON dbo.ntml_slots (program_id, slot_name);
    
    PRINT 'Created table dbo.ntml_slots';
END
ELSE
BEGIN
    PRINT 'Table dbo.ntml_slots already exists - skipping';
END
GO

-- ============================================================================
-- 4. ALTER adl_flight_tmi - Add GS/GDP Assignment Fields
-- ============================================================================

PRINT 'Enhancing dbo.adl_flight_tmi...';

-- Program reference
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'program_id')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD program_id INT NULL;
    PRINT '  Added program_id';
END

-- Slot reference  
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'slot_id')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD slot_id BIGINT NULL;
    PRINT '  Added slot_id';
END

-- Assigned arrival slot name (FSM-style: KJFK.091530A)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'aslot')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD aslot NVARCHAR(16) NULL;
    PRINT '  Added aslot';
END

-- Original control times (never change after initial assignment)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'octd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD octd_utc DATETIME2(0) NULL;
    PRINT '  Added octd_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'octa_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD octa_utc DATETIME2(0) NULL;
    PRINT '  Added octa_utc';
END

-- Control program name
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ctl_prgm')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctl_prgm NVARCHAR(32) NULL;
    PRINT '  Added ctl_prgm';
END

-- Exemption tracking
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ctl_exempt')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctl_exempt BIT DEFAULT 0;
    PRINT '  Added ctl_exempt';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ctl_exempt_reason')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctl_exempt_reason NVARCHAR(32) NULL;
    PRINT '  Added ctl_exempt_reason';
END

-- Program delay
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'program_delay_min')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD program_delay_min INT NULL;
    PRINT '  Added program_delay_min';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'delay_capped')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD delay_capped BIT DEFAULT 0;
    PRINT '  Added delay_capped';
END

-- Slot management
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'sl_hold')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD sl_hold BIT DEFAULT 0;
    PRINT '  Added sl_hold';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'subbable')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD subbable BIT DEFAULT 1;
    PRINT '  Added subbable';
END

-- Ground Stop specific
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'gs_held')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD gs_held BIT DEFAULT 0;
    PRINT '  Added gs_held';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'gs_release_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD gs_release_utc DATETIME2(0) NULL;
    PRINT '  Added gs_release_utc';
END

-- Pop-up / Re-control tracking
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'is_popup')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD is_popup BIT DEFAULT 0;
    PRINT '  Added is_popup';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'is_recontrol')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD is_recontrol BIT DEFAULT 0;
    PRINT '  Added is_recontrol';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'popup_detected_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD popup_detected_utc DATETIME2(0) NULL;
    PRINT '  Added popup_detected_utc';
END

-- ECR (EDCT Change Request) tracking
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ecr_pending')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ecr_pending BIT DEFAULT 0;
    PRINT '  Added ecr_pending';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ecr_requested_cta')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ecr_requested_cta DATETIME2(0) NULL;
    PRINT '  Added ecr_requested_cta';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ecr_requested_by')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ecr_requested_by NVARCHAR(64) NULL;
    PRINT '  Added ecr_requested_by';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ecr_requested_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ecr_requested_utc DATETIME2(0) NULL;
    PRINT '  Added ecr_requested_utc';
END

-- Cancel flags (per FAA ADL spec)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'ux_cancelled')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ux_cancelled BIT DEFAULT 0;
    PRINT '  Added ux_cancelled';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'fx_cancelled')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD fx_cancelled BIT DEFAULT 0;
    PRINT '  Added fx_cancelled';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'rz_removed')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD rz_removed BIT DEFAULT 0;
    PRINT '  Added rz_removed';
END

-- Assignment timestamp
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'assigned_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD assigned_utc DATETIME2(0) NULL;
    PRINT '  Added assigned_utc';
END
GO

-- Add foreign keys (deferred to avoid circular dependency issues)
IF NOT EXISTS (SELECT * FROM sys.foreign_keys WHERE name = 'FK_adl_flight_tmi_ntml')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi 
    ADD CONSTRAINT FK_adl_flight_tmi_ntml FOREIGN KEY (program_id)
        REFERENCES dbo.ntml(program_id);
    PRINT '  Added FK_adl_flight_tmi_ntml';
END

IF NOT EXISTS (SELECT * FROM sys.foreign_keys WHERE name = 'FK_adl_flight_tmi_slot')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi 
    ADD CONSTRAINT FK_adl_flight_tmi_slot FOREIGN KEY (slot_id)
        REFERENCES dbo.ntml_slots(slot_id);
    PRINT '  Added FK_adl_flight_tmi_slot';
END
GO

-- Add indexes
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'IX_tmi_program')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_program ON dbo.adl_flight_tmi (program_id, ctl_type) WHERE program_id IS NOT NULL;
    PRINT '  Created index IX_tmi_program';
END

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND name = 'IX_tmi_gs_held')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_gs_held ON dbo.adl_flight_tmi (gs_held, gs_release_utc) WHERE gs_held = 1;
    PRINT '  Created index IX_tmi_gs_held';
END
GO

PRINT '';
PRINT '=== NTML Schema Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
