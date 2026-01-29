-- ============================================================================
-- Migration: Add coordination_status to tmi_public_routes
-- Purpose: Make TMI coordination authoritative over route publishing
-- Date: 2026-01-29
-- ============================================================================

USE VATSIM_TMI;
GO

-- Add coordination_status column to track TMI approval state
-- Values: NULL (no coordination needed), PENDING, APPROVED, DENIED
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_public_routes')
    AND name = 'coordination_status'
)
BEGIN
    ALTER TABLE dbo.tmi_public_routes
    ADD coordination_status NVARCHAR(20) NULL;

    PRINT 'Added coordination_status column to tmi_public_routes';
END
ELSE
BEGIN
    PRINT 'coordination_status column already exists in tmi_public_routes';
END
GO

-- Add coordination_proposal_id to link routes back to their proposal
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_public_routes')
    AND name = 'coordination_proposal_id'
)
BEGIN
    ALTER TABLE dbo.tmi_public_routes
    ADD coordination_proposal_id INT NULL;

    PRINT 'Added coordination_proposal_id column to tmi_public_routes';
END
GO

-- Add index for coordination status lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_public_routes')
    AND name = 'IX_tmi_public_routes_coordination_status'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_public_routes_coordination_status
    ON dbo.tmi_public_routes (coordination_status)
    WHERE coordination_status IS NOT NULL;

    PRINT 'Created index IX_tmi_public_routes_coordination_status';
END
GO

-- Add check constraint for valid coordination_status values
IF NOT EXISTS (
    SELECT * FROM sys.check_constraints
    WHERE object_id = OBJECT_ID('CK_tmi_public_routes_coordination_status')
)
BEGIN
    ALTER TABLE dbo.tmi_public_routes
    ADD CONSTRAINT CK_tmi_public_routes_coordination_status
    CHECK (coordination_status IS NULL OR coordination_status IN ('PENDING', 'APPROVED', 'DENIED', 'EXPIRED'));

    PRINT 'Added check constraint for coordination_status';
END
GO

PRINT 'Migration 022_add_coordination_status_to_routes completed successfully';
