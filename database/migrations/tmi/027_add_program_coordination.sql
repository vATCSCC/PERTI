-- ============================================================================
-- Migration 027: Add coordination columns to tmi_programs
-- Purpose: Support GS/GDP coordination workflow via TMI Publishing
-- Date: 2026-01-30
-- ============================================================================
--
-- COLUMNS ADDED:
--   proposal_id              - Link to tmi_proposals for coordination tracking
--   proposal_status          - PENDING_COORD, APPROVED, DENIED, ACTIVATED
--   coordination_deadline_utc - "USER UPDATES MUST BE RECEIVED BY" time
--   coordination_facilities_json - JSON array of facilities for approval
--   flight_list_generated_at - When flight list was last generated
--   proposed_advisory_num    - ADVZY number for PROPOSED advisory
--   cancel_advisory_num      - ADVZY number for cancellation advisory
--   cancellation_reason      - Reason for cancellation
--   cancellation_edct_action - DISREGARD, DISREGARD_AFTER, AFP_ACTIVE
--   cancellation_edct_time   - For DISREGARD_AFTER option
--   cancellation_notes       - Additional cancellation notes
--   cancelled_by             - User who cancelled
--   cancelled_at             - When cancelled
--
-- NOTE: adv_number column already exists and is used for ACTUAL advisory
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 027: Add coordination columns to tmi_programs ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Add proposal_id column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'proposal_id'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD proposal_id INT NULL;

    PRINT 'Added proposal_id column';
END
ELSE
BEGIN
    PRINT 'proposal_id column already exists - skipping';
END
GO

-- Add proposal_status column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'proposal_status'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD proposal_status NVARCHAR(20) NULL;

    PRINT 'Added proposal_status column';
END
ELSE
BEGIN
    PRINT 'proposal_status column already exists - skipping';
END
GO

-- Add coordination_deadline_utc column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'coordination_deadline_utc'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD coordination_deadline_utc DATETIME2(0) NULL;

    PRINT 'Added coordination_deadline_utc column';
END
ELSE
BEGIN
    PRINT 'coordination_deadline_utc column already exists - skipping';
END
GO

-- Add coordination_facilities_json column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'coordination_facilities_json'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD coordination_facilities_json NVARCHAR(MAX) NULL;

    PRINT 'Added coordination_facilities_json column';
END
ELSE
BEGIN
    PRINT 'coordination_facilities_json column already exists - skipping';
END
GO

-- Add flight_list_generated_at column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'flight_list_generated_at'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD flight_list_generated_at DATETIME2(0) NULL;

    PRINT 'Added flight_list_generated_at column';
END
ELSE
BEGIN
    PRINT 'flight_list_generated_at column already exists - skipping';
END
GO

-- Add proposed_advisory_num column (ADVZY for PROPOSED advisory)
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'proposed_advisory_num'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD proposed_advisory_num NVARCHAR(16) NULL;

    PRINT 'Added proposed_advisory_num column';
END
ELSE
BEGIN
    PRINT 'proposed_advisory_num column already exists - skipping';
END
GO

-- Add cancel_advisory_num column (ADVZY for cancellation advisory)
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancel_advisory_num'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancel_advisory_num NVARCHAR(16) NULL;

    PRINT 'Added cancel_advisory_num column';
END
ELSE
BEGIN
    PRINT 'cancel_advisory_num column already exists - skipping';
END
GO

-- Add cancellation_reason column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancellation_reason'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancellation_reason NVARCHAR(64) NULL;

    PRINT 'Added cancellation_reason column';
END
ELSE
BEGIN
    PRINT 'cancellation_reason column already exists - skipping';
END
GO

-- Add cancellation_edct_action column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancellation_edct_action'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancellation_edct_action NVARCHAR(32) NULL;

    PRINT 'Added cancellation_edct_action column';
END
ELSE
BEGIN
    PRINT 'cancellation_edct_action column already exists - skipping';
END
GO

-- Add cancellation_edct_time column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancellation_edct_time'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancellation_edct_time DATETIME2(0) NULL;

    PRINT 'Added cancellation_edct_time column';
END
ELSE
BEGIN
    PRINT 'cancellation_edct_time column already exists - skipping';
END
GO

-- Add cancellation_notes column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancellation_notes'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancellation_notes NVARCHAR(MAX) NULL;

    PRINT 'Added cancellation_notes column';
END
ELSE
BEGIN
    PRINT 'cancellation_notes column already exists - skipping';
END
GO

-- Add cancelled_by column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancelled_by'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancelled_by NVARCHAR(64) NULL;

    PRINT 'Added cancelled_by column';
END
ELSE
BEGIN
    PRINT 'cancelled_by column already exists - skipping';
END
GO

-- Add cancelled_at column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'cancelled_at'
)
BEGIN
    ALTER TABLE dbo.tmi_programs
    ADD cancelled_at DATETIME2(0) NULL;

    PRINT 'Added cancelled_at column';
END
ELSE
BEGIN
    PRINT 'cancelled_at column already exists - skipping';
END
GO

-- Create index on proposal_id for lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'IX_tmi_programs_proposal_id'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_programs_proposal_id
    ON dbo.tmi_programs (proposal_id)
    WHERE proposal_id IS NOT NULL;

    PRINT 'Created index IX_tmi_programs_proposal_id';
END
GO

-- Create index on proposal_status for filtering
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'IX_tmi_programs_proposal_status'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_programs_proposal_status
    ON dbo.tmi_programs (proposal_status)
    WHERE proposal_status IS NOT NULL;

    PRINT 'Created index IX_tmi_programs_proposal_status';
END
GO

PRINT '';
PRINT '=== Migration 027 completed successfully ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
