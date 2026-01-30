-- ============================================================================
-- Migration 028: Add program_id to tmi_proposals
-- Purpose: Link GS/GDP coordination proposals to their source programs
-- Date: 2026-01-30
-- ============================================================================
--
-- COLUMNS ADDED:
--   program_id            - FK to tmi_programs for GS/GDP proposals
--   program_snapshot_json - Snapshot of program state at proposal time
--
-- This allows tmi_proposals (already used for MIT, STOP, etc.) to also
-- handle GS/GDP coordination by linking to the modeled program in GDT.
--
-- For GS/GDP entry types:
--   entry_type = 'GS' or 'GDP'
--   program_id = tmi_programs.program_id (the modeled program)
--
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 028: Add program_id to tmi_proposals ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Add program_id column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'program_id'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals
    ADD program_id INT NULL;

    PRINT 'Added program_id column';
END
ELSE
BEGIN
    PRINT 'program_id column already exists - skipping';
END
GO

-- Add program_snapshot_json column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'program_snapshot_json'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals
    ADD program_snapshot_json NVARCHAR(MAX) NULL;

    PRINT 'Added program_snapshot_json column';
END
ELSE
BEGIN
    PRINT 'program_snapshot_json column already exists - skipping';
END
GO

-- Create index on program_id for lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'IX_tmi_proposals_program_id'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_proposals_program_id
    ON dbo.tmi_proposals (program_id)
    WHERE program_id IS NOT NULL;

    PRINT 'Created index IX_tmi_proposals_program_id';
END
GO

-- FK constraint skipped - TMI_admin lacks permissions on tmi_proposals
-- Can add manually later if needed:
-- ALTER TABLE dbo.tmi_proposals ADD CONSTRAINT FK_tmi_proposals_program
--     FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id);
GO

PRINT '';
PRINT '=== Migration 028 completed successfully ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
