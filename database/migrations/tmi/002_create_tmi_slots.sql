-- ============================================================================
-- VATSIM_TMI Migration 002: Create tmi_slots table
-- Arrival Slot Allocation - FSM-format slot naming (KJFK.091530A)
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
--
-- Slot Naming Convention (per FADT Spec):
--   GDP: ccc[c].ddddddL   (e.g., KJFK.091530A)
--   AFP: FCAccc.ddddddL   (e.g., FCA027.091530A)
--   Where: dddddd = day-hour-minute, L = unique suffix letter
--
-- Retention Policy:
--   - Hot: 90 days
--   - Cool: 1 year  
--   - Cold: Indefinite
-- ============================================================================

USE VATSIM_TMI;
GO

IF OBJECT_ID('dbo.tmi_slots', 'U') IS NOT NULL
    DROP TABLE dbo.tmi_slots;
GO

CREATE TABLE dbo.tmi_slots (
    -- Primary Key
    slot_id                 BIGINT IDENTITY(1,1) PRIMARY KEY,
    
    -- Program Reference
    program_id              INT NOT NULL,
    
    -- Slot Identification (FSM format)
    slot_name               NVARCHAR(20) NOT NULL,          -- e.g., "KJFK.091530A" or "FCA027.181530B"
    slot_index              INT NOT NULL,                   -- Sequential index within program
    slot_time_utc           DATETIME2(0) NOT NULL,          -- Slot arrival/entry time
    
    -- Slot Type and Status
    slot_type               NVARCHAR(16) NOT NULL DEFAULT 'REGULAR',
                            -- REGULAR: Normal slot from RBS
                            -- RESERVED: Reserved for pop-ups (GAAP/UDP)
                            -- UNASSIGNED: Available for re-assignment
    slot_status             NVARCHAR(16) NOT NULL DEFAULT 'OPEN',
                            -- OPEN: Available for assignment
                            -- ASSIGNED: Flight assigned
                            -- BRIDGED: Part of substitution bridge
                            -- HELD: Held by carrier (not available)
                            -- CANCELLED: Slot cancelled
                            -- COMPRESSED: Removed by compression
    
    -- Time Bin Tracking (15-minute granularity for demand analysis)
    bin_date                DATE NOT NULL,
    bin_hour                TINYINT NOT NULL,               -- 0-23
    bin_quarter             TINYINT NOT NULL,               -- 0, 15, 30, 45
    
    -- Flight Assignment
    assigned_flight_uid     BIGINT NULL,                    -- FK to VATSIM_ADL.adl_flight_core
    assigned_callsign       NVARCHAR(12) NULL,
    assigned_carrier        NVARCHAR(8) NULL,
    assigned_origin         NVARCHAR(4) NULL,
    assigned_dest           NVARCHAR(4) NULL,               -- For AFP (FCA entry point destination)
    assigned_utc            DATETIME2(0) NULL,
    
    -- Calculated Delay
    original_eta_utc        DATETIME2(0) NULL,              -- ETA before slot assignment
    slot_delay_min          INT NULL,                       -- Delay imposed by this slot
    
    -- Slot Hold (Carrier substitution management)
    sl_hold                 BIT NOT NULL DEFAULT 0,         -- Held by carrier
    sl_hold_carrier         NVARCHAR(8) NULL,               -- Carrier holding slot
    sl_hold_until_utc       DATETIME2(0) NULL,              -- Hold expiration
    subbable                BIT NOT NULL DEFAULT 1,         -- Available for substitution
    
    -- Bridging (Slot Credit Substitution)
    bridge_from_slot_id     BIGINT NULL,                    -- Original slot in bridge chain
    bridge_to_slot_id       BIGINT NULL,                    -- Target slot in bridge chain
    bridge_reason           NVARCHAR(32) NULL,              -- 'ECR', 'SCS', 'COMPRESSION'
    
    -- Pop-up Tracking (GAAP/UDP)
    is_popup_slot           BIT NOT NULL DEFAULT 0,         -- Assigned to pop-up flight
    popup_lead_time_min     INT NULL,                       -- Lead time when pop-up detected
    
    -- Retention Management
    is_archived             BIT NOT NULL DEFAULT 0,
    archive_tier            TINYINT NULL,                   -- 1=Hot, 2=Cool, 3=Cold
    archived_utc            DATETIME2(0) NULL,
    
    -- Audit
    created_utc             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    modified_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    -- Constraints
    CONSTRAINT CK_tmi_slots_type CHECK (slot_type IN ('REGULAR', 'RESERVED', 'UNASSIGNED')),
    CONSTRAINT CK_tmi_slots_status CHECK (slot_status IN ('OPEN', 'ASSIGNED', 'BRIDGED', 'HELD', 'CANCELLED', 'COMPRESSED')),
    CONSTRAINT CK_tmi_slots_bin_hour CHECK (bin_hour >= 0 AND bin_hour <= 23),
    CONSTRAINT CK_tmi_slots_bin_quarter CHECK (bin_quarter IN (0, 15, 30, 45)),
    
    -- Foreign Keys
    CONSTRAINT FK_tmi_slots_program FOREIGN KEY (program_id)
        REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE,
    CONSTRAINT FK_tmi_slots_bridge_from FOREIGN KEY (bridge_from_slot_id)
        REFERENCES dbo.tmi_slots(slot_id),
    CONSTRAINT FK_tmi_slots_bridge_to FOREIGN KEY (bridge_to_slot_id)
        REFERENCES dbo.tmi_slots(slot_id)
);
GO

-- Primary lookup indexes
CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_slots_name 
    ON dbo.tmi_slots(slot_name);

CREATE NONCLUSTERED INDEX IX_tmi_slots_program_time 
    ON dbo.tmi_slots(program_id, slot_time_utc)
    INCLUDE (slot_type, slot_status, assigned_flight_uid);

CREATE NONCLUSTERED INDEX IX_tmi_slots_program_index 
    ON dbo.tmi_slots(program_id, slot_index);

-- Flight lookup
CREATE NONCLUSTERED INDEX IX_tmi_slots_flight 
    ON dbo.tmi_slots(assigned_flight_uid)
    WHERE assigned_flight_uid IS NOT NULL;

-- Status queries
CREATE NONCLUSTERED INDEX IX_tmi_slots_status 
    ON dbo.tmi_slots(program_id, slot_status, slot_type)
    INCLUDE (slot_time_utc, assigned_callsign);

-- Bin/demand analysis
CREATE NONCLUSTERED INDEX IX_tmi_slots_bin 
    ON dbo.tmi_slots(program_id, bin_date, bin_hour, bin_quarter);

-- Open slots for assignment
CREATE NONCLUSTERED INDEX IX_tmi_slots_open 
    ON dbo.tmi_slots(program_id, slot_time_utc)
    WHERE slot_status = 'OPEN'
    INCLUDE (slot_type, slot_index);

-- Retention/archival
CREATE NONCLUSTERED INDEX IX_tmi_slots_retention 
    ON dbo.tmi_slots(is_archived, created_utc, archive_tier)
    WHERE is_archived = 0;
GO

-- ============================================================================
-- Trigger: Update modified_utc on UPDATE
-- ============================================================================
CREATE OR ALTER TRIGGER trg_tmi_slots_modified
ON dbo.tmi_slots
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE t
    SET modified_utc = SYSUTCDATETIME()
    FROM dbo.tmi_slots t
    INNER JOIN inserted i ON t.slot_id = i.slot_id;
END;
GO

PRINT 'Migration 002: tmi_slots table created successfully';
GO
