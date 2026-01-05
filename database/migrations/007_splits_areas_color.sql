-- ============================================================================
-- Migration: Add color column to splits_areas table
-- Date: 2025-12-23
-- Description: Allows areas to persist their display color (like positions/presets)
-- ============================================================================

-- Add color column to splits_areas (nullable, defaults handled in app)
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'dbo' 
    AND TABLE_NAME = 'splits_areas' 
    AND COLUMN_NAME = 'color'
)
BEGIN
    ALTER TABLE dbo.splits_areas
    ADD color NVARCHAR(20) NULL;
    
    PRINT 'Added color column to splits_areas';
END
ELSE
BEGIN
    PRINT 'Column color already exists on splits_areas';
END
GO
