-- ============================================================================
-- 019_swim_fixm_column_names.sql
-- SWIM_API Database: Migrate OOOI column names to FIXM-aligned naming
--
-- Purpose: Align column names with FIXM 4.3 / NAS Extension naming conventions
-- Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
--
-- Strategy: Add new FIXM-named columns alongside old OOOI columns.
-- Dual-write during 30-day transition, then drop old columns via 025 migration.
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 019: OOOI to FIXM-Aligned Column Names';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Core OOOI Times -> FIXM Names
-- ============================================================================

-- actual_off_block_time (AOBT) - replaces out_utc
-- FIXM: actualOffBlockTime (NAS/APAC Extension)
-- TFMS: AOBT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'actual_off_block_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added actual_off_block_time (FIXM: AOBT, replaces: out_utc)';
END
ELSE PRINT '= actual_off_block_time already exists';
GO

-- actual_time_of_departure (ATOT) - replaces off_utc
-- FIXM Core: actualTimeOfDeparture
-- TFMS: ATOT (wheels-up)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'actual_time_of_departure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added actual_time_of_departure (FIXM Core: ATOT, replaces: off_utc)';
END
ELSE PRINT '= actual_time_of_departure already exists';
GO

-- actual_landing_time (ALDT) - replaces on_utc
-- FIXM: actualLandingTime / actualTimeOfArrival
-- TFMS: ALDT (wheels-down)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'actual_landing_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_landing_time DATETIME2(0) NULL;
    PRINT '+ Added actual_landing_time (FIXM: ALDT, replaces: on_utc)';
END
ELSE PRINT '= actual_landing_time already exists';
GO

-- actual_in_block_time (AIBT) - replaces in_utc
-- FIXM: actualInBlockTime (NAS Extension)
-- TFMS: AIBT (gate arrival)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'actual_in_block_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_in_block_time DATETIME2(0) NULL;
    PRINT '+ Added actual_in_block_time (FIXM NAS: AIBT, replaces: in_utc)';
END
ELSE PRINT '= actual_in_block_time already exists';
GO

-- ============================================================================
-- SECTION 2: Estimated Times -> FIXM Names
-- ============================================================================

-- estimated_time_of_arrival (ETA) - replaces eta_utc
-- FIXM: estimatedTimeOfArrival
-- TFMS: ETA
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_time_of_arrival')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added estimated_time_of_arrival (FIXM: ETA, replaces: eta_utc)';
END
ELSE PRINT '= estimated_time_of_arrival already exists';
GO

-- estimated_off_block_time (EOBT) - replaces etd_utc
-- FIXM Core: estimatedOffBlockTime
-- TFMS: EOBT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_off_block_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_off_block_time (FIXM Core: EOBT, replaces: etd_utc)';
END
ELSE PRINT '= estimated_off_block_time already exists';
GO

-- estimated_runway_arrival_time - replaces eta_runway_utc
-- FIXM NAS: estimatedRunwayArrivalTime
-- TFMS: ELDT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_runway_arrival_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_runway_arrival_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_runway_arrival_time (replaces: eta_runway_utc)';
END
ELSE PRINT '= estimated_runway_arrival_time already exists';
GO

-- ============================================================================
-- SECTION 3: Controlled Times -> FIXM Names
-- ============================================================================

-- controlled_time_of_departure (CTD) - replaces ctd_utc
-- FIXM: controlledTimeOfDeparture
-- TFMS: CTD
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'controlled_time_of_departure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD controlled_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_departure (FIXM: CTD, replaces: ctd_utc)';
END
ELSE PRINT '= controlled_time_of_departure already exists';
GO

-- controlled_time_of_arrival (CTA) - replaces cta_utc
-- FIXM: controlledTimeOfArrival
-- TFMS: CTA
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'controlled_time_of_arrival')
BEGIN
    ALTER TABLE dbo.swim_flights ADD controlled_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_arrival (FIXM: CTA, replaces: cta_utc)';
END
ELSE PRINT '= controlled_time_of_arrival already exists';
GO

-- Note: edct_utc stays as-is (already FIXM-aligned abbreviation)
-- expected_departure_clearance_time would be too verbose

-- ============================================================================
-- SECTION 4: SimTraffic Times -> FIXM-like Names
-- ============================================================================

-- taxi_start_time - replaces taxi_time_utc
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'taxi_start_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD taxi_start_time DATETIME2(0) NULL;
    PRINT '+ Added taxi_start_time (replaces: taxi_time_utc)';
END
ELSE PRINT '= taxi_start_time already exists';
GO

-- departure_sequence_time - replaces sequence_time_utc
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'departure_sequence_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD departure_sequence_time DATETIME2(0) NULL;
    PRINT '+ Added departure_sequence_time (replaces: sequence_time_utc)';
END
ELSE PRINT '= departure_sequence_time already exists';
GO

-- hold_short_time - replaces holdshort_time_utc
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'hold_short_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD hold_short_time DATETIME2(0) NULL;
    PRINT '+ Added hold_short_time (replaces: holdshort_time_utc)';
END
ELSE PRINT '= hold_short_time already exists';
GO

-- runway_entry_time - replaces runway_time_utc
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'runway_entry_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD runway_entry_time DATETIME2(0) NULL;
    PRINT '+ Added runway_entry_time (replaces: runway_time_utc)';
END
ELSE PRINT '= runway_entry_time already exists';
GO

-- ============================================================================
-- SECTION 5: Backfill existing data to new columns
-- ============================================================================

PRINT '';
PRINT 'Backfilling existing data to new FIXM columns...';

UPDATE dbo.swim_flights SET
    actual_off_block_time = out_utc,
    actual_time_of_departure = off_utc,
    actual_landing_time = on_utc,
    actual_in_block_time = in_utc,
    estimated_time_of_arrival = eta_utc,
    estimated_off_block_time = etd_utc,
    estimated_runway_arrival_time = eta_runway_utc,
    controlled_time_of_departure = ctd_utc,
    controlled_time_of_arrival = cta_utc,
    taxi_start_time = taxi_time_utc,
    departure_sequence_time = sequence_time_utc,
    hold_short_time = holdshort_time_utc,
    runway_entry_time = runway_time_utc
WHERE 1=1;

PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- SECTION 6: Create indexes on new columns
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_actual_departure')
BEGIN
    CREATE INDEX IX_swim_flights_actual_departure
    ON dbo.swim_flights (actual_time_of_departure)
    WHERE actual_time_of_departure IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_actual_departure';
END
ELSE PRINT '= Index IX_swim_flights_actual_departure already exists';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_estimated_arrival')
BEGIN
    CREATE INDEX IX_swim_flights_estimated_arrival
    ON dbo.swim_flights (estimated_time_of_arrival)
    WHERE estimated_time_of_arrival IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_estimated_arrival';
END
ELSE PRINT '= Index IX_swim_flights_estimated_arrival already exists';
GO

-- ============================================================================
-- SECTION 7: Create backward-compatibility view (optional)
-- ============================================================================

IF EXISTS (SELECT 1 FROM sys.views WHERE object_id = OBJECT_ID('dbo.vw_swim_flights_oooi_compat'))
    DROP VIEW dbo.vw_swim_flights_oooi_compat;
GO

CREATE VIEW dbo.vw_swim_flights_oooi_compat AS
SELECT
    *,
    -- Legacy OOOI aliases pointing to new FIXM columns
    actual_off_block_time AS aobt,
    actual_time_of_departure AS atot,
    actual_landing_time AS aldt,
    actual_in_block_time AS aibt
FROM dbo.swim_flights;
GO

PRINT '+ Created backward-compatibility view vw_swim_flights_oooi_compat';
GO

-- ============================================================================
-- SECTION 8: Summary
-- ============================================================================

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 019 Complete: OOOI to FIXM-Aligned Column Names';
PRINT '';
PRINT '  NEW COLUMNS (FIXM-aligned):';
PRINT '    OOOI Times:';
PRINT '      actual_off_block_time      (was: out_utc)';
PRINT '      actual_time_of_departure   (was: off_utc)';
PRINT '      actual_landing_time        (was: on_utc)';
PRINT '      actual_in_block_time       (was: in_utc)';
PRINT '';
PRINT '    Estimated Times:';
PRINT '      estimated_time_of_arrival    (was: eta_utc)';
PRINT '      estimated_off_block_time     (was: etd_utc)';
PRINT '      estimated_runway_arrival_time (was: eta_runway_utc)';
PRINT '';
PRINT '    Controlled Times:';
PRINT '      controlled_time_of_departure (was: ctd_utc)';
PRINT '      controlled_time_of_arrival   (was: cta_utc)';
PRINT '';
PRINT '    SimTraffic Times:';
PRINT '      taxi_start_time            (was: taxi_time_utc)';
PRINT '      departure_sequence_time    (was: sequence_time_utc)';
PRINT '      hold_short_time            (was: holdshort_time_utc)';
PRINT '      runway_entry_time          (was: runway_time_utc)';
PRINT '';
PRINT '  OLD COLUMNS (preserved during transition):';
PRINT '    out_utc, off_utc, on_utc, in_utc';
PRINT '    eta_utc, etd_utc, eta_runway_utc';
PRINT '    ctd_utc, cta_utc';
PRINT '    taxi_time_utc, sequence_time_utc, holdshort_time_utc, runway_time_utc';
PRINT '';
PRINT '  NEXT STEPS:';
PRINT '    1. Update PHP sync scripts to dual-write both columns';
PRINT '    2. Update API endpoints to read new columns';
PRINT '    3. After 30-day transition, run 025_swim_drop_oooi_columns.sql';
PRINT '==========================================================================';
GO
