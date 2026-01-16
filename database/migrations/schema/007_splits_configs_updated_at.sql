-- ============================================================================
-- Migration: Add updated_at column to splits_configs
--
-- Purpose: Track when configurations were last modified
-- Run this script on the Azure SQL database (VATSIM_ADL).
-- ============================================================================

-- Add updated_at column to splits_configs
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.splits_configs')
    AND name = 'updated_at'
)
BEGIN
    ALTER TABLE dbo.splits_configs
    ADD updated_at DATETIME2 NULL;

    -- Initialize existing rows with created_at value
    UPDATE dbo.splits_configs SET updated_at = created_at WHERE updated_at IS NULL;

    PRINT 'Added column: updated_at to dbo.splits_configs';
END
ELSE
BEGIN
    PRINT 'Column updated_at already exists on dbo.splits_configs';
END
GO
