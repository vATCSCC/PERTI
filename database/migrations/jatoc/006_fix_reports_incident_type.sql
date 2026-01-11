-- Migration 006: Fix jatoc_reports incident_type column
-- Migration 004 failed to add incident_type to jatoc_reports due to batch parsing issue
-- This migration completes that step

-- ============================================================================
-- Step 1: Add incident_type column to jatoc_reports if not exists
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_reports') AND name = 'incident_type'
)
BEGIN
    ALTER TABLE dbo.jatoc_reports ADD incident_type NVARCHAR(32) NULL;
    PRINT 'Added incident_type column to jatoc_reports';
END
GO

-- ============================================================================
-- Step 2: Copy data from status column (separate batch after column exists)
-- ============================================================================
UPDATE dbo.jatoc_reports
SET incident_type = status
WHERE incident_type IS NULL AND status IS NOT NULL;

PRINT 'Copied status to incident_type in jatoc_reports';
GO

PRINT 'Migration 006 complete: Fixed jatoc_reports incident_type column';
GO
