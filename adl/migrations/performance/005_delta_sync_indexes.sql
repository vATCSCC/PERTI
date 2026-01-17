-- ============================================================================
-- 005_delta_sync_indexes.sql
-- Indexes to support SWIM delta sync and position skip-unchanged optimization
--
-- Created: 2026-01-17
-- Purpose: Enable efficient delta queries for SWIM sync (V3.0)
--
-- These indexes support the WHERE clauses in:
--   - fetch_adl_flights_delta() in swim_sync.php
--   - Position skip-unchanged optimization in sp_Adl_RefreshFromVatsim_Staged
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT 'Creating delta sync indexes...';
GO

-- ============================================================================
-- Index 1: Position update timestamp
-- Supports: pos.position_updated_utc > @lastSync in delta query
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_position')
    AND name = 'IX_position_updated_utc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_position_updated_utc
    ON dbo.adl_flight_position (position_updated_utc)
    INCLUDE (flight_uid)
    WHERE position_updated_utc IS NOT NULL;

    PRINT 'Created IX_position_updated_utc on adl_flight_position';
END
ELSE
BEGIN
    PRINT 'IX_position_updated_utc already exists';
END
GO

-- ============================================================================
-- Index 2: Times update timestamp
-- Supports: t.times_updated_utc > @lastSync in delta query
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_times')
    AND name = 'IX_times_updated_utc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_times_updated_utc
    ON dbo.adl_flight_times (times_updated_utc)
    INCLUDE (flight_uid)
    WHERE times_updated_utc IS NOT NULL;

    PRINT 'Created IX_times_updated_utc on adl_flight_times';
END
ELSE
BEGIN
    PRINT 'IX_times_updated_utc already exists';
END
GO

-- ============================================================================
-- Index 3: TMI update timestamp
-- Supports: tmi.tmi_updated_utc > @lastSync in delta query
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi')
    AND name = 'IX_tmi_updated_utc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_updated_utc
    ON dbo.adl_flight_tmi (tmi_updated_utc)
    INCLUDE (flight_uid)
    WHERE tmi_updated_utc IS NOT NULL;

    PRINT 'Created IX_tmi_updated_utc on adl_flight_tmi';
END
ELSE
BEGIN
    PRINT 'IX_tmi_updated_utc already exists';
END
GO

-- ============================================================================
-- Index 4: Core first_seen for new flight detection
-- Supports: c.first_seen_utc > @lastSync in delta query
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_core')
    AND name = 'IX_core_first_seen_utc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_core_first_seen_utc
    ON dbo.adl_flight_core (first_seen_utc)
    INCLUDE (flight_uid, is_active)
    WHERE is_active = 1;

    PRINT 'Created IX_core_first_seen_utc on adl_flight_core';
END
ELSE
BEGIN
    PRINT 'IX_core_first_seen_utc already exists';
END
GO

-- ============================================================================
-- Index 5: Position lat/lon for skip-unchanged optimization
-- Supports: efficient comparison of position changes in Step 3
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_position')
    AND name = 'IX_position_latlon'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_position_latlon
    ON dbo.adl_flight_position (flight_uid)
    INCLUDE (lat, lon, altitude_ft, groundspeed_kts);

    PRINT 'Created IX_position_latlon on adl_flight_position';
END
ELSE
BEGIN
    PRINT 'IX_position_latlon already exists';
END
GO

PRINT 'Delta sync indexes created successfully';
PRINT '';
PRINT 'Summary:';
PRINT '  - IX_position_updated_utc: Supports delta sync position filter';
PRINT '  - IX_times_updated_utc: Supports delta sync times filter';
PRINT '  - IX_tmi_updated_utc: Supports delta sync TMI filter';
PRINT '  - IX_core_first_seen_utc: Supports new flight detection';
PRINT '  - IX_position_latlon: Supports position skip-unchanged optimization';
GO
