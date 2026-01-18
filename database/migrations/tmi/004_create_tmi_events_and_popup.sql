-- ============================================================================
-- VATSIM_TMI Migration 004: Create tmi_events table
-- Audit/Event History Log for TMI Operations
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
--
-- Event Types:
--   PROGRAM_CREATED, PROGRAM_MODELED, PROGRAM_ACTIVATED, PROGRAM_REVISED,
--   PROGRAM_EXTENDED, PROGRAM_COMPRESSED, PROGRAM_PAUSED, PROGRAM_PURGED,
--   SLOT_ASSIGNED, SLOT_BRIDGED, SLOT_HELD, SLOT_RELEASED,
--   FLIGHT_CONTROLLED, FLIGHT_EXEMPTED, FLIGHT_ECR, FLIGHT_SUBSTITUTED,
--   POPUP_DETECTED, POPUP_ASSIGNED, GS_RELEASED
--
-- Retention: Same as tmi_programs (5 years hot, then cold)
-- ============================================================================

USE VATSIM_TMI;
GO

IF OBJECT_ID('dbo.tmi_events', 'U') IS NOT NULL
    DROP TABLE dbo.tmi_events;
GO

CREATE TABLE dbo.tmi_events (
    -- Primary Key
    event_id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    
    -- Event Identification
    event_type              NVARCHAR(32) NOT NULL,
    event_subtype           NVARCHAR(32) NULL,              -- Additional categorization
    
    -- Related Entities
    program_id              INT NULL,                       -- FK to tmi_programs
    slot_id                 BIGINT NULL,                    -- FK to tmi_slots
    flight_uid              BIGINT NULL,                    -- FK to adl_flight_core
    control_id              BIGINT NULL,                    -- FK to tmi_flight_control
    
    -- Event Context
    ctl_element             NVARCHAR(8) NULL,               -- Airport/FCA
    callsign                NVARCHAR(12) NULL,
    
    -- Event Details (JSON for flexibility)
    details_json            NVARCHAR(MAX) NULL,
    
    -- Before/After Values (for change tracking)
    old_value               NVARCHAR(256) NULL,
    new_value               NVARCHAR(256) NULL,
    
    -- Event Description
    description             NVARCHAR(512) NULL,
    
    -- User/System Attribution
    event_source            NVARCHAR(16) NOT NULL DEFAULT 'USER',
                            -- USER, SYSTEM, DAEMON, API, COMPRESSION
    event_user              NVARCHAR(64) NULL,
    event_ip                NVARCHAR(45) NULL,
    
    -- Timestamp
    event_utc               DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    -- Retention
    is_archived             BIT NOT NULL DEFAULT 0,
    
    -- Constraints
    CONSTRAINT CK_tmi_events_source CHECK (event_source IN ('USER', 'SYSTEM', 'DAEMON', 'API', 'COMPRESSION'))
);
GO

-- Indexes for event queries
CREATE NONCLUSTERED INDEX IX_tmi_events_program 
    ON dbo.tmi_events(program_id, event_utc DESC)
    WHERE program_id IS NOT NULL;

CREATE NONCLUSTERED INDEX IX_tmi_events_flight 
    ON dbo.tmi_events(flight_uid, event_utc DESC)
    WHERE flight_uid IS NOT NULL;

CREATE NONCLUSTERED INDEX IX_tmi_events_type 
    ON dbo.tmi_events(event_type, event_utc DESC)
    INCLUDE (program_id, ctl_element);

CREATE NONCLUSTERED INDEX IX_tmi_events_element 
    ON dbo.tmi_events(ctl_element, event_utc DESC)
    WHERE ctl_element IS NOT NULL;

CREATE NONCLUSTERED INDEX IX_tmi_events_time 
    ON dbo.tmi_events(event_utc DESC)
    INCLUDE (event_type, program_id, ctl_element);

-- Retention
CREATE NONCLUSTERED INDEX IX_tmi_events_retention 
    ON dbo.tmi_events(is_archived, event_utc)
    WHERE is_archived = 0;
GO

PRINT 'Migration 004: tmi_events table created successfully';
GO

-- ============================================================================
-- Migration 005: Create Popup Detection Queue Table
-- Tracks flights detected as pop-ups pending slot assignment
-- ============================================================================

IF OBJECT_ID('dbo.tmi_popup_queue', 'U') IS NOT NULL
    DROP TABLE dbo.tmi_popup_queue;
GO

CREATE TABLE dbo.tmi_popup_queue (
    -- Primary Key
    queue_id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    
    -- Flight Reference
    flight_uid              BIGINT NOT NULL,
    callsign                NVARCHAR(12) NOT NULL,
    
    -- Program Reference
    program_id              INT NOT NULL,
    
    -- Detection Info
    detected_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    flight_eta_utc          DATETIME2(0) NOT NULL,          -- ETA when detected
    lead_time_min           INT NOT NULL,                   -- Minutes before ETA when detected
    
    -- Flight Details (for assignment)
    dep_airport             NVARCHAR(4) NULL,
    arr_airport             NVARCHAR(4) NULL,
    dep_center              NVARCHAR(4) NULL,
    aircraft_type           NVARCHAR(8) NULL,
    carrier                 NVARCHAR(8) NULL,
    
    -- Processing Status
    queue_status            NVARCHAR(16) NOT NULL DEFAULT 'PENDING',
                            -- PENDING, PROCESSING, ASSIGNED, EXEMPT, FAILED, EXPIRED
    assigned_slot_id        BIGINT NULL,
    assigned_utc            DATETIME2(0) NULL,
    assignment_type         NVARCHAR(16) NULL,              -- RESERVED, DAS, GAAP
    
    -- Processing Notes
    process_notes           NVARCHAR(256) NULL,
    
    -- Audit
    created_utc             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    processed_utc           DATETIME2(0) NULL,
    
    -- Constraints
    CONSTRAINT CK_tmi_popup_status CHECK (queue_status IN ('PENDING', 'PROCESSING', 'ASSIGNED', 'EXEMPT', 'FAILED', 'EXPIRED')),
    
    -- Foreign Keys
    CONSTRAINT FK_tmi_popup_program FOREIGN KEY (program_id)
        REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE,
    CONSTRAINT FK_tmi_popup_slot FOREIGN KEY (assigned_slot_id)
        REFERENCES dbo.tmi_slots(slot_id)
);
GO

-- Indexes
CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_popup_flight_program 
    ON dbo.tmi_popup_queue(flight_uid, program_id);

CREATE NONCLUSTERED INDEX IX_tmi_popup_pending 
    ON dbo.tmi_popup_queue(program_id, queue_status, detected_utc)
    WHERE queue_status = 'PENDING';

CREATE NONCLUSTERED INDEX IX_tmi_popup_program 
    ON dbo.tmi_popup_queue(program_id, flight_eta_utc)
    INCLUDE (flight_uid, callsign, queue_status);
GO

PRINT 'Migration 005: tmi_popup_queue table created successfully';
GO
