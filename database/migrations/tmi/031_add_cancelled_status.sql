-- =============================================================================
-- Migration 031: Add CANCELLED to tmi_programs status constraint
-- Purpose: Allow programs to be cancelled via cancel.php API
-- Date: 2026-01-30
-- =============================================================================
--
-- ISSUE: cancel.php sets status = 'CANCELLED' but the CHECK constraint
-- (if present) only allows: PROPOSED, MODELING, ACTIVE, PAUSED, COMPLETED,
-- PURGED, SUPERSEDED
--
-- FIX: Drop and recreate constraint to include CANCELLED
--
-- =============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 031: Add CANCELLED to status constraint ==='
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Drop existing constraint if it exists
IF EXISTS (
    SELECT * FROM sys.check_constraints
    WHERE object_id = OBJECT_ID('dbo.CK_tmi_programs_status')
    AND parent_object_id = OBJECT_ID('dbo.tmi_programs')
)
BEGIN
    ALTER TABLE dbo.tmi_programs DROP CONSTRAINT CK_tmi_programs_status;
    PRINT 'Dropped existing CK_tmi_programs_status constraint';
END
ELSE
BEGIN
    PRINT 'CK_tmi_programs_status constraint does not exist - skipping drop';
END
GO

-- Add updated constraint with CANCELLED included
IF NOT EXISTS (
    SELECT * FROM sys.check_constraints
    WHERE object_id = OBJECT_ID('dbo.CK_tmi_programs_status')
    AND parent_object_id = OBJECT_ID('dbo.tmi_programs')
)
BEGIN
    ALTER TABLE dbo.tmi_programs ADD CONSTRAINT CK_tmi_programs_status
        CHECK (status IN ('PROPOSED', 'MODELING', 'ACTIVE', 'PAUSED', 'COMPLETED', 'PURGED', 'SUPERSEDED', 'CANCELLED', 'PENDING_COORD'));
    PRINT 'Added CK_tmi_programs_status constraint with CANCELLED and PENDING_COORD';
END
GO

-- Verify the constraint
SELECT
    cc.name AS constraint_name,
    cc.definition AS constraint_definition
FROM sys.check_constraints cc
WHERE cc.parent_object_id = OBJECT_ID('dbo.tmi_programs')
AND cc.name = 'CK_tmi_programs_status';
GO

PRINT '';
PRINT '=== Migration 031 completed successfully ==='
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
