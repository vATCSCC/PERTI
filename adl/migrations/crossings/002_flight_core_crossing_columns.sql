-- ============================================================================
-- Flight Core Crossing Columns
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Add crossing-related columns to adl_flight_core
-- ============================================================================

-- ============================================================================
-- 1. Crossing Calculation Tracking Columns
-- ============================================================================

-- Crossing tier (determines update frequency)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'crossing_tier')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD crossing_tier TINYINT NULL;
    PRINT 'Added column: crossing_tier';
END
GO

-- Last crossing calculation timestamp
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'crossing_last_calc_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD crossing_last_calc_utc DATETIME2(0) NULL;
    PRINT 'Added column: crossing_last_calc_utc';
END
GO

-- Flag for event-triggered recalculation
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'crossing_needs_recalc')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD crossing_needs_recalc BIT NOT NULL DEFAULT 0;
    PRINT 'Added column: crossing_needs_recalc';
END
GO

-- Region flags bitmask (1=departs, 2=arrives, 4=transits)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'crossing_region_flags')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD crossing_region_flags TINYINT NULL;
    PRINT 'Added column: crossing_region_flags';
END
GO

-- ============================================================================
-- 2. Level Flight Detection (with smoothing)
-- Triggers recalc after climb AND descent phases stabilize
-- ============================================================================

-- Consecutive samples with |vertical_rate| < 200 fpm
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'level_flight_samples')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD level_flight_samples TINYINT NOT NULL DEFAULT 0;
    PRINT 'Added column: level_flight_samples';
END
GO

-- Confirmed level flight (set after 3 consecutive samples)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'level_flight_confirmed')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD level_flight_confirmed BIT NOT NULL DEFAULT 0;
    PRINT 'Added column: level_flight_confirmed';
END
GO

-- Track last vertical phase for detecting phase transitions
-- Values: NULL=unknown, 'C'=climbing, 'D'=descending, 'L'=level
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'last_vertical_phase')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD last_vertical_phase CHAR(1) NULL;
    PRINT 'Added column: last_vertical_phase';
END
GO

-- ============================================================================
-- 3. Indexes for Tiered Batch Processing
-- ============================================================================

-- Index for Tier 1: New flights needing calculation
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_new' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_new
    ON dbo.adl_flight_core(crossing_last_calc_utc)
    INCLUDE (flight_uid, crossing_tier, crossing_region_flags)
    WHERE is_active = 1 AND crossing_last_calc_utc IS NULL;
    PRINT 'Created index: IX_crossing_new';
END
GO

-- Index for event-triggered recalc
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_needs_recalc' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_needs_recalc
    ON dbo.adl_flight_core(crossing_needs_recalc)
    INCLUDE (flight_uid, crossing_tier, crossing_region_flags)
    WHERE is_active = 1 AND crossing_needs_recalc = 1;
    PRINT 'Created index: IX_crossing_needs_recalc';
END
GO

-- Index for tier-based batch selection
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_tier_batch' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_tier_batch
    ON dbo.adl_flight_core(crossing_tier, crossing_last_calc_utc)
    INCLUDE (flight_uid, crossing_region_flags, current_artcc, current_tracon)
    WHERE is_active = 1;
    PRINT 'Created index: IX_crossing_tier_batch';
END
GO

-- Index for regional flight filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_regional' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_regional
    ON dbo.adl_flight_core(crossing_region_flags, crossing_tier)
    INCLUDE (flight_uid, current_artcc, current_tracon, level_flight_confirmed)
    WHERE is_active = 1 AND crossing_region_flags > 0;
    PRINT 'Created index: IX_crossing_regional';
END
GO

-- ============================================================================
-- 4. Initialize existing flights
-- Set them to need calculation on next batch
-- ============================================================================
UPDATE dbo.adl_flight_core
SET crossing_needs_recalc = 1
WHERE is_active = 1
  AND crossing_last_calc_utc IS NULL;

DECLARE @updated INT = @@ROWCOUNT;
PRINT 'Marked ' + CAST(@updated AS VARCHAR(10)) + ' active flights for initial crossing calculation';
GO

PRINT '============================================================================';
PRINT 'Flight Core Crossing Columns v1.0 - Complete';
PRINT '============================================================================';
GO
