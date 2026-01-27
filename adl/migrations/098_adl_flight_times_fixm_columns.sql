-- ============================================================================
-- 098_adl_flight_times_fixm_columns.sql
-- VATSIM_ADL Database: Add FIXM-aligned time columns to adl_flight_times
--
-- Purpose: Align normalized table column names with FIXM 4.3 / NAS Extension
-- Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
--
-- Note: Targets the normalized adl_flight_times table.
--       Migration 097 handled the denormalized adl_flights table.
--
-- IMPORTANT: Run this on VATSIM_ADL database, NOT SWIM_API!
-- ============================================================================

USE VATSIM_ADL;
GO

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 098: Add FIXM-Aligned Time Columns to adl_flight_times';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- Only proceed if adl_flight_times table exists
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flight_times')
BEGIN
    PRINT 'ERROR: adl_flight_times table does not exist!';
    PRINT 'This migration requires the normalized adl_flight_times table.';
    RETURN;
END
GO

PRINT 'Found adl_flight_times table - proceeding with FIXM column additions...';
GO

-- ============================================================================
-- SECTION 1: Core OOOI / Actual Times -> FIXM Names
-- ============================================================================

-- actual_off_block_time (AOBT) - Gate departure / pushback
-- Maps to: atd_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'actual_off_block_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD actual_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added actual_off_block_time (FIXM: AOBT)';
END
ELSE PRINT '= actual_off_block_time already exists';
GO

-- actual_time_of_departure (ATOT) - Wheels up
-- Maps to: atd_runway_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'actual_time_of_departure')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD actual_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added actual_time_of_departure (FIXM Core: ATOT)';
END
ELSE PRINT '= actual_time_of_departure already exists';
GO

-- actual_landing_time (ALDT) - Wheels down
-- Maps to: ata_runway_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'actual_landing_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD actual_landing_time DATETIME2(0) NULL;
    PRINT '+ Added actual_landing_time (FIXM: ALDT)';
END
ELSE PRINT '= actual_landing_time already exists';
GO

-- actual_in_block_time (AIBT) - Gate arrival
-- Maps to: ata_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'actual_in_block_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD actual_in_block_time DATETIME2(0) NULL;
    PRINT '+ Added actual_in_block_time (FIXM NAS: AIBT)';
END
ELSE PRINT '= actual_in_block_time already exists';
GO

-- ============================================================================
-- SECTION 2: Estimated Times -> FIXM Names
-- ============================================================================

-- estimated_time_of_arrival (ETA)
-- Maps to: eta_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'estimated_time_of_arrival')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD estimated_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added estimated_time_of_arrival (FIXM: ETA)';
END
ELSE PRINT '= estimated_time_of_arrival already exists';
GO

-- estimated_off_block_time (EOBT)
-- Maps to: etd_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'estimated_off_block_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD estimated_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_off_block_time (FIXM Core: EOBT)';
END
ELSE PRINT '= estimated_off_block_time already exists';
GO

-- estimated_runway_arrival_time (ELDT)
-- Maps to: eta_runway_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'estimated_runway_arrival_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD estimated_runway_arrival_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_runway_arrival_time';
END
ELSE PRINT '= estimated_runway_arrival_time already exists';
GO

-- ============================================================================
-- SECTION 3: Controlled Times -> FIXM Names
-- ============================================================================

-- controlled_time_of_departure (CTD)
-- Maps to: ctd_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'controlled_time_of_departure')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD controlled_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_departure (FIXM: CTD)';
END
ELSE PRINT '= controlled_time_of_departure already exists';
GO

-- controlled_time_of_arrival (CTA)
-- Maps to: cta_utc (existing legacy column)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'controlled_time_of_arrival')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD controlled_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_arrival (FIXM: CTA)';
END
ELSE PRINT '= controlled_time_of_arrival already exists';
GO

-- ============================================================================
-- SECTION 4: SimTraffic Departure Sequence Times -> FIXM-like Names
-- ============================================================================

-- taxi_start_time - When taxi begins after pushback
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'taxi_start_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD taxi_start_time DATETIME2(0) NULL;
    PRINT '+ Added taxi_start_time';
END
ELSE PRINT '= taxi_start_time already exists';
GO

-- departure_sequence_time - Departure sequencing time assignment
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'departure_sequence_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD departure_sequence_time DATETIME2(0) NULL;
    PRINT '+ Added departure_sequence_time';
END
ELSE PRINT '= departure_sequence_time already exists';
GO

-- hold_short_time - Hold short point entry time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'hold_short_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD hold_short_time DATETIME2(0) NULL;
    PRINT '+ Added hold_short_time';
END
ELSE PRINT '= hold_short_time already exists';
GO

-- runway_entry_time - Runway entry time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'runway_entry_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD runway_entry_time DATETIME2(0) NULL;
    PRINT '+ Added runway_entry_time';
END
ELSE PRINT '= runway_entry_time already exists';
GO

-- ============================================================================
-- SECTION 5: Metering Times -> FIXM Names
-- ============================================================================

-- actual_metering_time - Actual time at meter fix (for compliance analysis)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'actual_metering_time')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD actual_metering_time DATETIME2(0) NULL;
    PRINT '+ Added actual_metering_time';
END
ELSE PRINT '= actual_metering_time already exists';
GO

-- ============================================================================
-- SECTION 6: Backfill existing data to new columns
-- ============================================================================

PRINT '';
PRINT 'Backfilling existing data to new FIXM columns...';

UPDATE dbo.adl_flight_times SET
    -- OOOI / Actual times
    actual_off_block_time = atd_utc,
    actual_time_of_departure = atd_runway_utc,
    actual_landing_time = ata_runway_utc,
    actual_in_block_time = ata_utc,
    -- Estimated times
    estimated_time_of_arrival = eta_utc,
    estimated_off_block_time = etd_utc,
    estimated_runway_arrival_time = eta_runway_utc,
    -- Controlled times
    controlled_time_of_departure = ctd_utc,
    controlled_time_of_arrival = cta_utc,
    -- Metering times
    actual_metering_time = eta_meterfix_utc
WHERE 1=1;

PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
GO

-- ============================================================================
-- SECTION 7: Summary
-- ============================================================================

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 098 Complete: FIXM-Aligned Time Columns in adl_flight_times';
PRINT '';
PRINT '  NEW COLUMNS (mapped from legacy):';
PRINT '    OOOI Times:';
PRINT '      actual_off_block_time      <- atd_utc';
PRINT '      actual_time_of_departure   <- atd_runway_utc';
PRINT '      actual_landing_time        <- ata_runway_utc';
PRINT '      actual_in_block_time       <- ata_utc';
PRINT '';
PRINT '    Estimated Times:';
PRINT '      estimated_time_of_arrival    <- eta_utc';
PRINT '      estimated_off_block_time     <- etd_utc';
PRINT '      estimated_runway_arrival_time <- eta_runway_utc';
PRINT '';
PRINT '    Controlled Times:';
PRINT '      controlled_time_of_departure <- ctd_utc';
PRINT '      controlled_time_of_arrival   <- cta_utc';
PRINT '';
PRINT '    SimTraffic Times (NEW - no legacy equivalent):';
PRINT '      taxi_start_time, departure_sequence_time,';
PRINT '      hold_short_time, runway_entry_time';
PRINT '';
PRINT '    Metering Times:';
PRINT '      actual_metering_time <- eta_meterfix_utc';
PRINT '==========================================================================';
GO
