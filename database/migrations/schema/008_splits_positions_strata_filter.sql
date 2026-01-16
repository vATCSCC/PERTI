-- ============================================================================
-- Migration: Add strata_filter column to splits_positions
--
-- Purpose: Store per-position strata visibility preferences (low/high/superhigh)
--          for active configs (mirrors the column in splits_preset_positions)
-- Run this script on the Azure SQL database (VATSIM_ADL).
-- ============================================================================

-- Add strata_filter column to splits_positions
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.splits_positions')
    AND name = 'strata_filter'
)
BEGIN
    ALTER TABLE dbo.splits_positions
    ADD strata_filter NVARCHAR(100) NULL;
    -- Stores JSON like: '{"low":true,"high":true,"superhigh":false}'
    -- NULL means show all (backward compatible)

    PRINT 'Added column: strata_filter to dbo.splits_positions';
END
ELSE
BEGIN
    PRINT 'Column strata_filter already exists on dbo.splits_positions';
END
GO
