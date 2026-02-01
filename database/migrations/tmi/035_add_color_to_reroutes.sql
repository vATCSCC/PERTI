-- Migration: 035_add_color_to_reroutes.sql
-- Purpose: Add color column to tmi_reroutes table for customizing route display color
-- Date: 2026-02-01

-- Add color column to tmi_reroutes
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_reroutes')
    AND name = 'color'
)
BEGIN
    ALTER TABLE dbo.tmi_reroutes
    ADD color NVARCHAR(16) NULL DEFAULT '#f39c12';

    PRINT 'Added color column to tmi_reroutes';
END
ELSE
BEGIN
    PRINT 'Column color already exists on tmi_reroutes';
END
GO
