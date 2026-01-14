-- =====================================================
-- Fix Config Modifier Schema
-- Migration: 093a
-- Description: Fixes runway_id column size and clears
--              partial migration data for re-run
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 093a: Fix config_modifier schema ===';
PRINT '';

-- =====================================================
-- Step 1: Clear partial migration data
-- =====================================================
PRINT '=== Step 1: Clear partial migration data ===';

DELETE FROM dbo.config_modifier;
PRINT 'Cleared config_modifier table: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 2: Widen runway_id column
-- =====================================================
PRINT '';
PRINT '=== Step 2: Widen runway_id column from VARCHAR(8) to VARCHAR(16) ===';

-- Drop the unique constraint first
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UQ_config_modifier' AND object_id = OBJECT_ID('dbo.config_modifier'))
BEGIN
    ALTER TABLE dbo.config_modifier DROP CONSTRAINT UQ_config_modifier;
    PRINT 'Dropped UQ_config_modifier constraint';
END

-- Drop the index that includes runway_id
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_config_modifier_config' AND object_id = OBJECT_ID('dbo.config_modifier'))
BEGIN
    DROP INDEX IX_config_modifier_config ON dbo.config_modifier;
    PRINT 'Dropped IX_config_modifier_config index';
END

-- Alter the column
ALTER TABLE dbo.config_modifier ALTER COLUMN runway_id VARCHAR(16) NULL;
PRINT 'Altered runway_id to VARCHAR(16)';

-- Recreate the unique constraint
ALTER TABLE dbo.config_modifier ADD CONSTRAINT UQ_config_modifier UNIQUE (config_id, runway_id, modifier_code);
PRINT 'Recreated UQ_config_modifier constraint';

-- Recreate the index
CREATE NONCLUSTERED INDEX IX_config_modifier_config
    ON dbo.config_modifier(config_id) INCLUDE (runway_id, modifier_code, variant_value);
PRINT 'Recreated IX_config_modifier_config index';

PRINT '';
PRINT 'Migration 093a completed. Now re-run migration 093.';
GO
