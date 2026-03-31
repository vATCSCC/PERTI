-- ============================================================================
-- Migration: Add source tracking columns to splits_configs
--
-- Purpose: Track which system created each split configuration.
--   - source: 'perti' (UI wizard), 'swim_api' (external push), or connector name
--   - source_id: External system's identifier for correlation/idempotency
--
-- Related: VATSWIM Splits Bridge (docs/superpowers/specs/2026-03-30-splits-to-swim-bridge-design.md)
-- ============================================================================

-- Add source column (default 'perti' for backward compat with UI-created configs)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.splits_configs') AND name = 'source'
)
BEGIN
    ALTER TABLE dbo.splits_configs ADD [source] NVARCHAR(50) NOT NULL DEFAULT 'perti';
    PRINT 'Added column: splits_configs.source';
END
ELSE
BEGIN
    PRINT 'Column splits_configs.source already exists';
END
GO

-- Add source_id column (nullable - only set for external API pushes)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.splits_configs') AND name = 'source_id'
)
BEGIN
    ALTER TABLE dbo.splits_configs ADD [source_id] NVARCHAR(100) NULL;
    PRINT 'Added column: splits_configs.source_id';
END
ELSE
BEGIN
    PRINT 'Column splits_configs.source_id already exists';
END
GO

-- Index on source_id for idempotent upsert lookups (facility + source + source_id)
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.splits_configs') AND name = 'IX_splits_configs_source_lookup'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_splits_configs_source_lookup
    ON dbo.splits_configs ([source], [source_id], [artcc])
    INCLUDE ([status])
    WHERE [source_id] IS NOT NULL;
    PRINT 'Created index: IX_splits_configs_source_lookup';
END
ELSE
BEGIN
    PRINT 'Index IX_splits_configs_source_lookup already exists';
END
GO
