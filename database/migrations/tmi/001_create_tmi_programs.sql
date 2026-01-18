-- ============================================================================
-- VATSIM_TMI Migration 001: Create tmi_programs table
-- TMI Program Registry - GS, GDP (DAS/GAAP/UDP), AFP
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
-- 
-- Retention Policy:
--   - Active/Completed: Hot storage indefinitely
--   - Purged: Hot for 5 years, then cold storage indefinitely
-- ============================================================================

USE VATSIM_TMI;
GO

-- Drop if exists (for development - remove in production)
IF OBJECT_ID('dbo.tmi_programs', 'U') IS NOT NULL
    DROP TABLE dbo.tmi_programs;
GO

CREATE TABLE dbo.tmi_programs (
    -- Primary Key
    program_id              INT IDENTITY(1,1) PRIMARY KEY,
    program_guid            UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Program Identification
    ctl_element             NVARCHAR(8) NOT NULL,           -- Airport (KJFK) or FCA (FCA001)
    element_type            NVARCHAR(8) NOT NULL,           -- 'APT', 'FCA', 'FEA'
    program_type            NVARCHAR(16) NOT NULL,          -- 'GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP'
    program_name            NVARCHAR(64) NULL,              -- Display name (e.g., "KJFK GDP #1")
    adv_number              NVARCHAR(16) NULL,              -- Advisory number (e.g., "001")
    
    -- Program Times (UTC)
    start_utc               DATETIME2(0) NOT NULL,
    end_utc                 DATETIME2(0) NOT NULL,
    cumulative_start_utc    DATETIME2(0) NULL,              -- For extensions (original start)
    cumulative_end_utc      DATETIME2(0) NULL,              -- For extensions (latest end)
    
    -- Program Status
    status                  NVARCHAR(16) NOT NULL DEFAULT 'PROPOSED',
                            -- PROPOSED, MODELING, ACTIVE, PAUSED, COMPLETED, PURGED, SUPERSEDED
    is_proposed             BIT NOT NULL DEFAULT 1,
    is_active               BIT NOT NULL DEFAULT 0,
    is_archived             BIT NOT NULL DEFAULT 0,         -- For retention management
    
    -- Program Rates (GDP/AFP only)
    program_rate            INT NULL,                        -- Default arrivals/hour (AAR)
    reserve_rate            INT NULL,                        -- Reserved slots/hour for pop-ups (GAAP/UDP)
    delay_limit_min         INT NOT NULL DEFAULT 180,        -- Maximum assignable delay (minutes)
    target_delay_mult       DECIMAL(3,2) NOT NULL DEFAULT 1.0, -- Target delay multiplier (UDP)
    earliest_r_slot_min     INT NOT NULL DEFAULT 0,          -- Earliest R-slot assignment (minutes before)
    
    -- Hourly Rate Profiles (JSON for flexibility)
    rates_hourly_json       NVARCHAR(MAX) NULL,              -- {"00":30,"01":30,...,"23":30}
    reserve_hourly_json     NVARCHAR(MAX) NULL,              -- Pop-up reserve rates by hour
    
    -- Scope Definition
    scope_type              NVARCHAR(16) NULL,               -- 'TIER', 'DISTANCE', 'CENTER', 'ALL'
    scope_distance_nm       INT NULL,                        -- Distance radius (if DISTANCE)
    scope_centers_json      NVARCHAR(MAX) NULL,              -- ["ZNY","ZDC","ZBW"] (if CENTER/TIER)
    scope_tiers_json        NVARCHAR(MAX) NULL,              -- Tier definitions
    
    -- Include/Exclude Filters
    include_arr_fix         NVARCHAR(8) NULL,                -- Filter by arrival fix
    include_aircraft_type   NVARCHAR(8) NULL,                -- Filter by aircraft type category
    include_carrier         NVARCHAR(8) NULL,                -- Filter by carrier
    exemptions_json         NVARCHAR(MAX) NULL,              -- Exemption rules JSON
    
    -- Impact/Cause
    impacting_condition     NVARCHAR(32) NULL,               -- WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER
    cause_text              NVARCHAR(512) NULL,              -- Detailed cause description
    comments                NVARCHAR(MAX) NULL,              -- Internal notes
    
    -- GS-Specific Fields
    gs_probability          NVARCHAR(16) NULL,               -- Probability type for GS
    gs_release_rate         INT NULL,                        -- Release rate when GS lifts
    
    -- AFP/FCA-Specific Fields  
    fca_name                NVARCHAR(64) NULL,               -- FCA display name
    fca_entry_time_offset   INT NULL,                        -- Entry time offset (minutes)
    
    -- Transition Tracking (GS→GDP)
    parent_program_id       INT NULL,                        -- FK to GS when transitioning to GDP
    transition_type         NVARCHAR(16) NULL,               -- 'GS_TO_GDP', 'REVISION', 'EXTENSION'
    
    -- Revision Tracking
    revision_number         INT NOT NULL DEFAULT 0,
    superseded_by_id        INT NULL,                        -- FK to newer revision
    
    -- Compression Settings
    compression_enabled     BIT NOT NULL DEFAULT 1,
    adaptive_compression    BIT NOT NULL DEFAULT 0,          -- Auto-adaptive (FSM-style)
    last_compression_utc    DATETIME2(0) NULL,
    
    -- Calculated Metrics (populated after simulation/run)
    total_flights           INT NULL,
    controlled_flights      INT NULL,
    exempt_flights          INT NULL,
    popup_flights           INT NULL,
    avg_delay_min           DECIMAL(8,2) NULL,
    max_delay_min           INT NULL,
    total_delay_min         INT NULL,
    
    -- Audit Fields
    created_by              NVARCHAR(64) NULL,
    created_utc             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    modified_by             NVARCHAR(64) NULL,
    modified_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    activated_utc           DATETIME2(0) NULL,
    completed_utc           DATETIME2(0) NULL,
    purged_utc              DATETIME2(0) NULL,
    purged_by               NVARCHAR(64) NULL,
    
    -- Constraints
    CONSTRAINT CK_tmi_programs_element_type CHECK (element_type IN ('APT', 'FCA', 'FEA')),
    CONSTRAINT CK_tmi_programs_program_type CHECK (program_type IN ('GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP', 'BLANKET', 'COMPRESSION')),
    CONSTRAINT CK_tmi_programs_status CHECK (status IN ('PROPOSED', 'MODELING', 'ACTIVE', 'PAUSED', 'COMPLETED', 'PURGED', 'SUPERSEDED')),
    CONSTRAINT CK_tmi_programs_scope_type CHECK (scope_type IS NULL OR scope_type IN ('TIER', 'DISTANCE', 'CENTER', 'ALL')),
    CONSTRAINT CK_tmi_programs_times CHECK (end_utc > start_utc),
    
    -- Foreign Keys (self-referential)
    CONSTRAINT FK_tmi_programs_parent FOREIGN KEY (parent_program_id)
        REFERENCES dbo.tmi_programs(program_id),
    CONSTRAINT FK_tmi_programs_superseded FOREIGN KEY (superseded_by_id)
        REFERENCES dbo.tmi_programs(program_id)
);
GO

-- Indexes for common queries
CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_programs_guid 
    ON dbo.tmi_programs(program_guid);

CREATE NONCLUSTERED INDEX IX_tmi_programs_element_status 
    ON dbo.tmi_programs(ctl_element, status)
    INCLUDE (program_type, start_utc, end_utc);

CREATE NONCLUSTERED INDEX IX_tmi_programs_active 
    ON dbo.tmi_programs(is_active, start_utc, end_utc)
    WHERE is_active = 1;

CREATE NONCLUSTERED INDEX IX_tmi_programs_status_time 
    ON dbo.tmi_programs(status, start_utc, end_utc)
    INCLUDE (ctl_element, program_type);

-- Index for retention management (archival queries)
CREATE NONCLUSTERED INDEX IX_tmi_programs_retention 
    ON dbo.tmi_programs(status, purged_utc, is_archived)
    WHERE status = 'PURGED';
GO

-- ============================================================================
-- Trigger: Update modified_utc on UPDATE
-- ============================================================================
CREATE OR ALTER TRIGGER trg_tmi_programs_modified
ON dbo.tmi_programs
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE t
    SET modified_utc = SYSUTCDATETIME()
    FROM dbo.tmi_programs t
    INNER JOIN inserted i ON t.program_id = i.program_id;
END;
GO

PRINT 'Migration 001: tmi_programs table created successfully';
GO
