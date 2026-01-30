-- ============================================================================
-- Migration 030: Create tmi_program_coordination_log table
-- Purpose: Audit log for GS/GDP program coordination actions
-- Date: 2026-01-30
-- ============================================================================
--
-- PURPOSE:
--   Track all coordination-related actions on GS/GDP programs:
--   - Proposal submissions
--   - Facility approvals/denials
--   - DCC overrides
--   - Activations
--   - Modifications/Extensions
--   - Cancellations
--   - Flight list regenerations
--
-- ACTION TYPES:
--   - PROPOSAL_SUBMITTED
--   - PROPOSAL_APPROVED
--   - PROPOSAL_DENIED
--   - PROPOSAL_EXPIRED
--   - DCC_OVERRIDE_APPROVE
--   - DCC_OVERRIDE_DENY
--   - PROGRAM_ACTIVATED
--   - PROGRAM_MODIFIED
--   - PROGRAM_EXTENDED
--   - PROGRAM_CANCELLED
--   - FLIGHT_LIST_GENERATED
--   - ADVISORY_PUBLISHED
--   - DISCORD_POSTED
--
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 030: Create tmi_program_coordination_log table ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Create the table if it doesn't exist
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_program_coordination_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_program_coordination_log (
        log_id                INT IDENTITY(1,1) PRIMARY KEY,
        program_id            INT NOT NULL,
        proposal_id           INT NULL,

        -- Action Details
        action_type           NVARCHAR(50) NOT NULL,
        action_detail         NVARCHAR(256) NULL,
        action_data_json      NVARCHAR(MAX) NULL,          -- Additional structured data

        -- Facility Tracking (for approval/denial actions)
        facility_code         NVARCHAR(8) NULL,
        facility_response     NVARCHAR(16) NULL,           -- APPROVED, DENIED

        -- Advisory Tracking
        advisory_number       NVARCHAR(16) NULL,           -- ADVZY number if published
        advisory_type         NVARCHAR(16) NULL,           -- PROPOSED, ACTUAL, CANCEL

        -- Discord Tracking
        discord_message_id    NVARCHAR(64) NULL,
        discord_channel_id    NVARCHAR(64) NULL,

        -- Actor Information
        performed_by          NVARCHAR(64) NULL,           -- User CID or 'SYSTEM'
        performed_by_name     NVARCHAR(128) NULL,
        performed_by_ip       NVARCHAR(45) NULL,

        -- Timestamp
        performed_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        -- Foreign Key
        CONSTRAINT FK_tmi_prog_coord_log_program FOREIGN KEY (program_id)
            REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE
    );

    PRINT 'Created table: tmi_program_coordination_log';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_program_coordination_log already exists - skipping';
END
GO

-- Index for program history queries
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_program_coordination_log')
    AND name = 'IX_tmi_prog_coord_log_program'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_prog_coord_log_program
    ON dbo.tmi_program_coordination_log(program_id, performed_at DESC);

    PRINT 'Created index IX_tmi_prog_coord_log_program';
END
GO

-- Index for proposal history queries
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_program_coordination_log')
    AND name = 'IX_tmi_prog_coord_log_proposal'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_prog_coord_log_proposal
    ON dbo.tmi_program_coordination_log(proposal_id, performed_at DESC)
    WHERE proposal_id IS NOT NULL;

    PRINT 'Created index IX_tmi_prog_coord_log_proposal';
END
GO

-- Index for action type filtering
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_program_coordination_log')
    AND name = 'IX_tmi_prog_coord_log_action'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_prog_coord_log_action
    ON dbo.tmi_program_coordination_log(action_type, performed_at DESC);

    PRINT 'Created index IX_tmi_prog_coord_log_action';
END
GO

-- Index for facility response tracking
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_program_coordination_log')
    AND name = 'IX_tmi_prog_coord_log_facility'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_prog_coord_log_facility
    ON dbo.tmi_program_coordination_log(facility_code, performed_at DESC)
    WHERE facility_code IS NOT NULL;

    PRINT 'Created index IX_tmi_prog_coord_log_facility';
END
GO

-- Index for recent activity (timeline view)
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_program_coordination_log')
    AND name = 'IX_tmi_prog_coord_log_recent'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_prog_coord_log_recent
    ON dbo.tmi_program_coordination_log(performed_at DESC);

    PRINT 'Created index IX_tmi_prog_coord_log_recent';
END
GO

PRINT '';
PRINT '=== Migration 030 completed successfully ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
