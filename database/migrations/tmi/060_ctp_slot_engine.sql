-- Migration 060: CTP Oceanic Slot Assignment Engine
-- Database: VATSIM_TMI
-- Depends on: 045_ctp_oceanic_schema.sql, 058_ctp_nat_track.sql
-- Design spec: docs/superpowers/specs/2026-04-23-ctp-oceanic-slot-engine-design.md

-- ============================================================================
-- 1. New table: ctp_session_tracks
--    Links CTP sessions to tmi_programs (one program per track).
--    Flowcontrol pushes track definitions via SWIM API.
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'ctp_session_tracks')
BEGIN
    CREATE TABLE dbo.ctp_session_tracks (
        session_track_id  INT IDENTITY(1,1) PRIMARY KEY,
        session_id        INT NOT NULL,
        program_id        INT NULL,
        track_name        VARCHAR(16) NOT NULL,
        route_string      NVARCHAR(MAX) NOT NULL,
        oceanic_entry_fix VARCHAR(32) NOT NULL,
        oceanic_exit_fix  VARCHAR(32) NOT NULL,
        max_acph          INT NOT NULL DEFAULT 10,
        is_active         BIT NOT NULL DEFAULT 1,
        pushed_at         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        created_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_ctp_st_session FOREIGN KEY (session_id)
            REFERENCES dbo.ctp_sessions(session_id),
        CONSTRAINT FK_ctp_st_program FOREIGN KEY (program_id)
            REFERENCES dbo.tmi_programs(program_id),
        CONSTRAINT UQ_ctp_session_track UNIQUE (session_id, track_name)
    );

    CREATE INDEX IX_ctp_session_tracks_session
        ON dbo.ctp_session_tracks (session_id);

    PRINT 'Created table: ctp_session_tracks';
END
ELSE
    PRINT 'Table ctp_session_tracks already exists — skipped';
GO

-- ============================================================================
-- 2. New table: ctp_facility_constraints
--    Flowcontrol-pushed facility constraint parameters (all advisory).
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'ctp_facility_constraints')
BEGIN
    CREATE TABLE dbo.ctp_facility_constraints (
        constraint_id   INT IDENTITY(1,1) PRIMARY KEY,
        session_id      INT NOT NULL,
        facility_name   VARCHAR(32) NOT NULL,
        facility_type   VARCHAR(16) NOT NULL,
        max_acph        INT NOT NULL,
        effective_start DATETIME2(0) NULL,
        effective_end   DATETIME2(0) NULL,
        pushed_at       DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        source          VARCHAR(32) NOT NULL DEFAULT 'flowcontrol',
        created_at      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_ctp_fc_session FOREIGN KEY (session_id)
            REFERENCES dbo.ctp_sessions(session_id),
        CONSTRAINT CK_ctp_facility_type CHECK (
            facility_type IN ('airport', 'fir', 'fix', 'sector')
        ),
        CONSTRAINT UQ_ctp_session_facility UNIQUE (session_id, facility_name, facility_type)
    );

    CREATE INDEX IX_ctp_facility_constraints_session
        ON dbo.ctp_facility_constraints (session_id);

    PRINT 'Created table: ctp_facility_constraints';
END
ELSE
    PRINT 'Table ctp_facility_constraints already exists — skipped';
GO

-- ============================================================================
-- 3. ALTER tmi_programs: add CTP to program_type CHECK constraint
-- ============================================================================

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_tmi_programs_program_type')
BEGIN
    ALTER TABLE dbo.tmi_programs DROP CONSTRAINT CK_tmi_programs_program_type;
    ALTER TABLE dbo.tmi_programs ADD CONSTRAINT CK_tmi_programs_program_type
        CHECK (program_type IN (
            'GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP',
            'AFP', 'BLANKET', 'COMPRESSION', 'CTP'
        ));
    PRINT 'Updated CHECK constraint CK_tmi_programs_program_type to include CTP';
END
ELSE
    PRINT 'CHECK constraint CK_tmi_programs_program_type not found — skipped';
GO

-- ============================================================================
-- 4. ALTER ctp_flight_control: slot assignment columns
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'ctp_flight_control' AND COLUMN_NAME = 'slot_status'
)
BEGIN
    ALTER TABLE dbo.ctp_flight_control ADD
        slot_status         VARCHAR(16) NOT NULL DEFAULT 'NONE',
        slot_id             BIGINT NULL,
        projected_oep_utc   DATETIME2(0) NULL,
        is_airborne         BIT NOT NULL DEFAULT 0,
        miss_reason         VARCHAR(32) NULL,
        reassignment_count  INT NOT NULL DEFAULT 0;

    PRINT 'Added slot columns to ctp_flight_control';
END
ELSE
    PRINT 'ctp_flight_control.slot_status already exists — skipped';
GO

-- FK and CHECK after columns exist
IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ctp_fc_slot'
)
BEGIN
    ALTER TABLE dbo.ctp_flight_control ADD CONSTRAINT FK_ctp_fc_slot
        FOREIGN KEY (slot_id) REFERENCES dbo.tmi_slots(slot_id);
    PRINT 'Added FK_ctp_fc_slot';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints WHERE name = 'CK_ctp_slot_status'
)
BEGIN
    ALTER TABLE dbo.ctp_flight_control ADD CONSTRAINT CK_ctp_slot_status
        CHECK (slot_status IN (
            'NONE', 'ASSIGNED', 'AT_RISK', 'MISSED', 'FROZEN', 'RELEASED'
        ));
    PRINT 'Added CHECK constraint CK_ctp_slot_status';
END
GO

-- Filtered index for active slot assignments
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes WHERE name = 'IX_ctp_fc_slot_status'
)
BEGIN
    CREATE INDEX IX_ctp_fc_slot_status
        ON dbo.ctp_flight_control (session_id, slot_status)
        WHERE slot_status != 'NONE';
    PRINT 'Created index IX_ctp_fc_slot_status';
END
GO

-- ============================================================================
-- 5. ALTER ctp_sessions: slot generation tracking
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'ctp_sessions' AND COLUMN_NAME = 'slot_generation_status'
)
BEGIN
    ALTER TABLE dbo.ctp_sessions ADD
        slot_generation_status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
        activation_checklist_json NVARCHAR(MAX) NULL;

    PRINT 'Added slot_generation_status to ctp_sessions';
END
ELSE
    PRINT 'ctp_sessions.slot_generation_status already exists — skipped';
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints WHERE name = 'CK_ctp_slot_gen_status'
)
BEGIN
    ALTER TABLE dbo.ctp_sessions ADD CONSTRAINT CK_ctp_slot_gen_status
        CHECK (slot_generation_status IN (
            'PENDING', 'GENERATING', 'READY', 'ERROR'
        ));
    PRINT 'Added CHECK constraint CK_ctp_slot_gen_status';
END
GO

PRINT '--- Migration 060 complete ---';
