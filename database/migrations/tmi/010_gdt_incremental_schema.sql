-- ============================================================================
-- VATSIM_TMI GDT Incremental Migration (v6 - Azure SQL Compatible)
-- Adds GDT (Ground Delay Tools) support to existing TMI schema
-- ============================================================================
-- Version: 1.0.5
-- Date: 2026-01-21
-- Author: HP/Claude
--
-- Fix: INCLUDE clause must come BEFORE WHERE clause in filtered indexes
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Starting GDT Incremental Migration v6 ===';
PRINT '';

-- ============================================================================
-- PART 1: ALTER tmi_programs - Add missing GDT columns
-- ============================================================================
PRINT 'Part 1: Adding columns to tmi_programs...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'is_archived')
    ALTER TABLE dbo.tmi_programs ADD is_archived BIT NOT NULL DEFAULT 0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'gs_probability')
    ALTER TABLE dbo.tmi_programs ADD gs_probability NVARCHAR(16) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'gs_release_rate')
    ALTER TABLE dbo.tmi_programs ADD gs_release_rate INT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'fca_name')
    ALTER TABLE dbo.tmi_programs ADD fca_name NVARCHAR(64) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'fca_entry_time_offset')
    ALTER TABLE dbo.tmi_programs ADD fca_entry_time_offset INT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'transition_type')
    ALTER TABLE dbo.tmi_programs ADD transition_type NVARCHAR(16) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'superseded_by_id')
    ALTER TABLE dbo.tmi_programs ADD superseded_by_id INT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'compression_enabled')
    ALTER TABLE dbo.tmi_programs ADD compression_enabled BIT NOT NULL DEFAULT 1;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'last_compression_utc')
    ALTER TABLE dbo.tmi_programs ADD last_compression_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'popup_flights')
    ALTER TABLE dbo.tmi_programs ADD popup_flights INT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'earliest_r_slot_min')
    ALTER TABLE dbo.tmi_programs ADD earliest_r_slot_min INT NOT NULL DEFAULT 0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'completed_at')
    ALTER TABLE dbo.tmi_programs ADD completed_at DATETIME2(0) NULL;

PRINT 'Part 1 complete.';
GO

-- ============================================================================
-- PART 2: ALTER tmi_slots - Add missing GDT columns
-- ============================================================================
PRINT 'Part 2: Adding columns to tmi_slots...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'bin_date')
    ALTER TABLE dbo.tmi_slots ADD bin_date DATE NULL;
GO

UPDATE dbo.tmi_slots SET bin_date = CAST(slot_time_utc AS DATE) WHERE bin_date IS NULL;
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'assigned_dest')
    ALTER TABLE dbo.tmi_slots ADD assigned_dest NVARCHAR(4) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'original_eta_utc')
    ALTER TABLE dbo.tmi_slots ADD original_eta_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'slot_delay_min')
    ALTER TABLE dbo.tmi_slots ADD slot_delay_min INT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'bridge_reason')
    ALTER TABLE dbo.tmi_slots ADD bridge_reason NVARCHAR(32) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'is_popup_slot')
    ALTER TABLE dbo.tmi_slots ADD is_popup_slot BIT NOT NULL DEFAULT 0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'popup_lead_time_min')
    ALTER TABLE dbo.tmi_slots ADD popup_lead_time_min INT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'is_archived')
    ALTER TABLE dbo.tmi_slots ADD is_archived BIT NOT NULL DEFAULT 0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'archive_tier')
    ALTER TABLE dbo.tmi_slots ADD archive_tier TINYINT NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'archived_at')
    ALTER TABLE dbo.tmi_slots ADD archived_at DATETIME2(0) NULL;

PRINT 'Part 2 complete.';
GO

-- ============================================================================
-- PART 3: CREATE tmi_flight_control table
-- ============================================================================
PRINT 'Part 3: Creating tmi_flight_control table...';

IF OBJECT_ID('dbo.tmi_flight_control', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_flight_control (
        control_id              BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid              BIGINT NOT NULL,
        callsign                NVARCHAR(12) NOT NULL,
        program_id              INT NULL,
        slot_id                 BIGINT NULL,
        ctd_utc                 DATETIME2(0) NULL,
        cta_utc                 DATETIME2(0) NULL,
        octd_utc                DATETIME2(0) NULL,
        octa_utc                DATETIME2(0) NULL,
        aslot                   NVARCHAR(20) NULL,
        ctl_elem                NVARCHAR(8) NULL,
        ctl_prgm                NVARCHAR(64) NULL,
        ctl_type                NVARCHAR(8) NULL,
        ctl_exempt              BIT NOT NULL DEFAULT 0,
        ctl_exempt_reason       NVARCHAR(32) NULL,
        program_delay_min       INT NULL,
        delay_capped            BIT NOT NULL DEFAULT 0,
        z_slot_delay            INT NULL,
        orig_etd_utc            DATETIME2(0) NULL,
        orig_eta_utc            DATETIME2(0) NULL,
        orig_ete_min            INT NULL,
        sl_hold                 BIT NOT NULL DEFAULT 0,
        sl_hold_carrier         NVARCHAR(8) NULL,
        subbable                BIT NOT NULL DEFAULT 1,
        gs_held                 BIT NOT NULL DEFAULT 0,
        gs_release_utc          DATETIME2(0) NULL,
        gs_release_sequence     INT NULL,
        is_popup                BIT NOT NULL DEFAULT 0,
        is_recontrol            BIT NOT NULL DEFAULT 0,
        popup_detected_utc      DATETIME2(0) NULL,
        popup_lead_time_min     INT NULL,
        ecr_pending             BIT NOT NULL DEFAULT 0,
        ecr_requested_cta       DATETIME2(0) NULL,
        ecr_requested_by        NVARCHAR(64) NULL,
        ecr_requested_utc       DATETIME2(0) NULL,
        ecr_approved            BIT NULL,
        ecr_approved_utc        DATETIME2(0) NULL,
        sub_from_flight_uid     BIGINT NULL,
        sub_to_flight_uid       BIGINT NULL,
        sub_reason              NVARCHAR(32) NULL,
        flight_status_at_ctl    NVARCHAR(16) NULL,
        dep_airport             NVARCHAR(4) NULL,
        arr_airport             NVARCHAR(4) NULL,
        dep_center              NVARCHAR(4) NULL,
        arr_center              NVARCHAR(4) NULL,
        compliance_status       NVARCHAR(16) NULL,
        actual_dep_utc          DATETIME2(0) NULL,
        actual_arr_utc          DATETIME2(0) NULL,
        compliance_delta_min    INT NULL,
        is_archived             BIT NOT NULL DEFAULT 0,
        archive_tier            TINYINT NULL,
        archived_at             DATETIME2(0) NULL,
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        control_assigned_utc    DATETIME2(0) NULL,
        control_released_utc    DATETIME2(0) NULL,
        CONSTRAINT FK_tmi_flight_control_program FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id),
        CONSTRAINT FK_tmi_flight_control_slot FOREIGN KEY (slot_id) REFERENCES dbo.tmi_slots(slot_id)
    );
    PRINT '  Created: tmi_flight_control table';
END
GO

-- Non-filtered indexes for tmi_flight_control
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_tmi_flight_control_flight_program' AND object_id = OBJECT_ID('dbo.tmi_flight_control'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_flight_control_flight_program ON dbo.tmi_flight_control(flight_uid, program_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_flight_control_flight' AND object_id = OBJECT_ID('dbo.tmi_flight_control'))
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_flight ON dbo.tmi_flight_control(flight_uid) INCLUDE (program_id, ctd_utc, cta_utc, aslot, ctl_type);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_flight_control_callsign' AND object_id = OBJECT_ID('dbo.tmi_flight_control'))
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_callsign ON dbo.tmi_flight_control(callsign) INCLUDE (program_id, ctd_utc, cta_utc);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_flight_control_program' AND object_id = OBJECT_ID('dbo.tmi_flight_control'))
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_program ON dbo.tmi_flight_control(program_id, cta_utc) INCLUDE (flight_uid, callsign, ctl_type, ctl_exempt, program_delay_min);
GO

-- Filtered indexes (no INCLUDE, simple WHERE)
BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_gs ON dbo.tmi_flight_control(program_id, gs_held, gs_release_utc) WHERE gs_held = 1;
    PRINT '  Created: IX_tmi_flight_control_gs';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_flight_control_gs already exists';
    ELSE THROW;
END CATCH
GO

-- Filtered indexes with INCLUDE: INCLUDE must come BEFORE WHERE
BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_popup ON dbo.tmi_flight_control(program_id, is_popup) INCLUDE (flight_uid, popup_detected_utc, popup_lead_time_min) WHERE is_popup = 1;
    PRINT '  Created: IX_tmi_flight_control_popup';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_flight_control_popup already exists';
    ELSE THROW;
END CATCH
GO

BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_ecr ON dbo.tmi_flight_control(ecr_pending) INCLUDE (program_id, flight_uid, ecr_requested_cta) WHERE ecr_pending = 1;
    PRINT '  Created: IX_tmi_flight_control_ecr';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_flight_control_ecr already exists';
    ELSE THROW;
END CATCH
GO

BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_arr_airport ON dbo.tmi_flight_control(arr_airport, cta_utc) INCLUDE (program_id, flight_uid, callsign, ctl_type) WHERE arr_airport IS NOT NULL;
    PRINT '  Created: IX_tmi_flight_control_arr_airport';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_flight_control_arr_airport already exists';
    ELSE THROW;
END CATCH
GO

PRINT 'Part 3 complete.';
GO

-- ============================================================================
-- PART 4: CREATE tmi_popup_queue table
-- ============================================================================
PRINT 'Part 4: Creating tmi_popup_queue table...';

IF OBJECT_ID('dbo.tmi_popup_queue', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_popup_queue (
        queue_id                BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid              BIGINT NOT NULL,
        callsign                NVARCHAR(12) NOT NULL,
        program_id              INT NOT NULL,
        detected_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        flight_eta_utc          DATETIME2(0) NOT NULL,
        lead_time_min           INT NOT NULL,
        dep_airport             NVARCHAR(4) NULL,
        arr_airport             NVARCHAR(4) NULL,
        dep_center              NVARCHAR(4) NULL,
        aircraft_type           NVARCHAR(8) NULL,
        carrier                 NVARCHAR(8) NULL,
        queue_status            NVARCHAR(16) NOT NULL DEFAULT 'PENDING',
        assigned_slot_id        BIGINT NULL,
        assigned_utc            DATETIME2(0) NULL,
        assignment_type         NVARCHAR(16) NULL,
        process_notes           NVARCHAR(256) NULL,
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        processed_at            DATETIME2(0) NULL,
        CONSTRAINT CK_tmi_popup_status CHECK (queue_status IN ('PENDING', 'PROCESSING', 'ASSIGNED', 'EXEMPT', 'FAILED', 'EXPIRED')),
        CONSTRAINT FK_tmi_popup_program FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE,
        CONSTRAINT FK_tmi_popup_slot FOREIGN KEY (assigned_slot_id) REFERENCES dbo.tmi_slots(slot_id)
    );
    PRINT '  Created: tmi_popup_queue table';
END
GO

-- Non-filtered indexes
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_tmi_popup_flight_program' AND object_id = OBJECT_ID('dbo.tmi_popup_queue'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_popup_flight_program ON dbo.tmi_popup_queue(flight_uid, program_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_popup_program' AND object_id = OBJECT_ID('dbo.tmi_popup_queue'))
    CREATE NONCLUSTERED INDEX IX_tmi_popup_program ON dbo.tmi_popup_queue(program_id, flight_eta_utc) INCLUDE (flight_uid, callsign, queue_status);
GO

-- Filtered index
BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_popup_pending ON dbo.tmi_popup_queue(program_id, queue_status, detected_utc) WHERE queue_status = 'PENDING';
    PRINT '  Created: IX_tmi_popup_pending';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_popup_pending already exists';
    ELSE THROW;
END CATCH
GO

PRINT 'Part 4 complete.';
GO

-- ============================================================================
-- PART 5: CREATE FlightListType user-defined table type
-- ============================================================================
PRINT 'Part 5: Creating FlightListType...';

IF TYPE_ID('dbo.FlightListType') IS NULL
BEGIN
    CREATE TYPE dbo.FlightListType AS TABLE (
        flight_uid          BIGINT NOT NULL,
        callsign            NVARCHAR(12) NOT NULL,
        eta_utc             DATETIME2(0) NOT NULL,
        etd_utc             DATETIME2(0) NULL,
        dep_airport         NVARCHAR(4) NULL,
        arr_airport         NVARCHAR(4) NULL,
        dep_center          NVARCHAR(4) NULL,
        arr_center          NVARCHAR(4) NULL,
        carrier             NVARCHAR(8) NULL,
        aircraft_type       NVARCHAR(8) NULL,
        flight_status       NVARCHAR(16) NULL,
        is_exempt           BIT NULL,
        exempt_reason       NVARCHAR(32) NULL,
        PRIMARY KEY (flight_uid)
    );
    PRINT '  Created: FlightListType';
END
GO

PRINT 'Part 5 complete.';
GO

-- ============================================================================
-- PART 6: Add new indexes to existing tables
-- ============================================================================
PRINT 'Part 6: Adding indexes to existing tables...';

-- Non-filtered index
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_slots_bin' AND object_id = OBJECT_ID('dbo.tmi_slots'))
    CREATE NONCLUSTERED INDEX IX_tmi_slots_bin ON dbo.tmi_slots(program_id, bin_date, bin_hour, bin_quarter);
GO

-- Filtered indexes
BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_slots_retention ON dbo.tmi_slots(is_archived, created_at, archive_tier) WHERE is_archived = 0;
    PRINT '  Created: IX_tmi_slots_retention';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_slots_retention already exists';
    ELSE THROW;
END CATCH
GO

-- Filtered index with INCLUDE: INCLUDE before WHERE
BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_slots_open ON dbo.tmi_slots(program_id, slot_time_utc) INCLUDE (slot_type, slot_index) WHERE slot_status = 'OPEN';
    PRINT '  Created: IX_tmi_slots_open';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_slots_open already exists';
    ELSE THROW;
END CATCH
GO

BEGIN TRY
    CREATE NONCLUSTERED INDEX IX_tmi_programs_retention ON dbo.tmi_programs(status, purged_at, is_archived) WHERE status = 'PURGED';
    PRINT '  Created: IX_tmi_programs_retention';
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 1913 PRINT '  Index IX_tmi_programs_retention already exists';
    ELSE THROW;
END CATCH
GO

PRINT 'Part 6 complete.';
PRINT '';
PRINT '=== GDT Incremental Migration v6 Complete ===';
PRINT 'Next: Run 011_create_gdt_views_v2.sql';
GO
