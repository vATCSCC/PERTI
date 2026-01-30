-- ============================================================================
-- Migration: Add origin/destination filters to tmi_reroute_routes
-- Purpose: Support per-route filtering (e.g., -KJFK -KPHL to exclude airports)
-- Date: 2026-01-30
-- ============================================================================

USE VATSIM_TMI;
GO

-- Add origin_filter column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_reroute_routes')
    AND name = 'origin_filter'
)
BEGIN
    ALTER TABLE dbo.tmi_reroute_routes
    ADD origin_filter NVARCHAR(128) NULL;

    PRINT 'Added origin_filter column to tmi_reroute_routes';
END
ELSE
BEGIN
    PRINT 'origin_filter column already exists in tmi_reroute_routes';
END
GO

-- Add dest_filter column
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_reroute_routes')
    AND name = 'dest_filter'
)
BEGIN
    ALTER TABLE dbo.tmi_reroute_routes
    ADD dest_filter NVARCHAR(128) NULL;

    PRINT 'Added dest_filter column to tmi_reroute_routes';
END
ELSE
BEGIN
    PRINT 'dest_filter column already exists in tmi_reroute_routes';
END
GO

PRINT 'Migration 026_reroute_routes_filters completed successfully';
