-- ============================================================================
-- 097_adl_fixm_time_columns.sql
-- VATSIM_ADL Database: Add FIXM-aligned time column aliases to adl_flights
--
-- Purpose: Align column names with FIXM 4.3 / NAS Extension naming conventions
-- Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
--
-- Note: Targets the denormalized adl_flights table (production schema).
--       The normalized adl_flight_times table may not be deployed yet.
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
PRINT '  FIXM Migration 097: Add FIXM-Aligned Time Columns to ADL';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- Only proceed if adl_flights table exists
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flights')
BEGIN
    PRINT 'ERROR: adl_flights table does not exist!';
    PRINT 'This migration requires the denormalized adl_flights table.';
    RETURN;
END
GO

PRINT 'Found adl_flights table - proceeding with FIXM column additions...';
GO

-- ============================================================================
-- SECTION 1: Core OOOI Times -> FIXM Names
-- ============================================================================

-- actual_off_block_time (AOBT) - Gate departure / pushback
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'actual_off_block_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD actual_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added actual_off_block_time (FIXM: AOBT)';
END
ELSE PRINT '= actual_off_block_time already exists';
GO

-- actual_time_of_departure (ATOT) - Wheels up
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'actual_time_of_departure')
BEGIN
    ALTER TABLE dbo.adl_flights ADD actual_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added actual_time_of_departure (FIXM Core: ATOT)';
END
ELSE PRINT '= actual_time_of_departure already exists';
GO

-- actual_landing_time (ALDT) - Wheels down
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'actual_landing_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD actual_landing_time DATETIME2(0) NULL;
    PRINT '+ Added actual_landing_time (FIXM: ALDT)';
END
ELSE PRINT '= actual_landing_time already exists';
GO

-- actual_in_block_time (AIBT) - Gate arrival
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'actual_in_block_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD actual_in_block_time DATETIME2(0) NULL;
    PRINT '+ Added actual_in_block_time (FIXM NAS: AIBT)';
END
ELSE PRINT '= actual_in_block_time already exists';
GO

-- ============================================================================
-- SECTION 2: Estimated Times -> FIXM Names
-- ============================================================================

-- estimated_time_of_arrival
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'estimated_time_of_arrival')
BEGIN
    ALTER TABLE dbo.adl_flights ADD estimated_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added estimated_time_of_arrival (FIXM: ETA)';
END
ELSE PRINT '= estimated_time_of_arrival already exists';
GO

-- estimated_off_block_time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'estimated_off_block_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD estimated_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_off_block_time (FIXM Core: EOBT)';
END
ELSE PRINT '= estimated_off_block_time already exists';
GO

-- estimated_runway_arrival_time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'estimated_runway_arrival_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD estimated_runway_arrival_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_runway_arrival_time';
END
ELSE PRINT '= estimated_runway_arrival_time already exists';
GO

-- ============================================================================
-- SECTION 3: Controlled Times -> FIXM Names
-- ============================================================================

-- controlled_time_of_departure
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'controlled_time_of_departure')
BEGIN
    ALTER TABLE dbo.adl_flights ADD controlled_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_departure (FIXM: CTD)';
END
ELSE PRINT '= controlled_time_of_departure already exists';
GO

-- controlled_time_of_arrival
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'controlled_time_of_arrival')
BEGIN
    ALTER TABLE dbo.adl_flights ADD controlled_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_arrival (FIXM: CTA)';
END
ELSE PRINT '= controlled_time_of_arrival already exists';
GO

-- ============================================================================
-- SECTION 4: SimTraffic Times -> FIXM-like Names
-- ============================================================================

-- taxi_start_time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'taxi_start_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD taxi_start_time DATETIME2(0) NULL;
    PRINT '+ Added taxi_start_time';
END
ELSE PRINT '= taxi_start_time already exists';
GO

-- departure_sequence_time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'departure_sequence_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD departure_sequence_time DATETIME2(0) NULL;
    PRINT '+ Added departure_sequence_time';
END
ELSE PRINT '= departure_sequence_time already exists';
GO

-- hold_short_time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'hold_short_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD hold_short_time DATETIME2(0) NULL;
    PRINT '+ Added hold_short_time';
END
ELSE PRINT '= hold_short_time already exists';
GO

-- runway_entry_time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'runway_entry_time')
BEGIN
    ALTER TABLE dbo.adl_flights ADD runway_entry_time DATETIME2(0) NULL;
    PRINT '+ Added runway_entry_time';
END
ELSE PRINT '= runway_entry_time already exists';
GO

-- ============================================================================
-- SECTION 5: Backfill existing data to new columns
-- Check what source columns exist and backfill accordingly
-- ============================================================================

PRINT '';
PRINT 'Backfilling existing data to new FIXM columns...';

-- Check if source columns exist and backfill
DECLARE @sql NVARCHAR(MAX) = 'UPDATE dbo.adl_flights SET ';
DECLARE @hasUpdates BIT = 0;

-- OOOI times - check for out_utc or similar columns
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'out_utc')
BEGIN
    SET @sql = @sql + 'actual_off_block_time = out_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'off_utc')
BEGIN
    SET @sql = @sql + 'actual_time_of_departure = off_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'on_utc')
BEGIN
    SET @sql = @sql + 'actual_landing_time = on_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'in_utc')
BEGIN
    SET @sql = @sql + 'actual_in_block_time = in_utc, ';
    SET @hasUpdates = 1;
END

-- Estimated times
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'eta_utc')
BEGIN
    SET @sql = @sql + 'estimated_time_of_arrival = eta_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'etd_utc')
BEGIN
    SET @sql = @sql + 'estimated_off_block_time = etd_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'eta_runway_utc')
BEGIN
    SET @sql = @sql + 'estimated_runway_arrival_time = eta_runway_utc, ';
    SET @hasUpdates = 1;
END

-- Controlled times
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'ctd_utc')
BEGIN
    SET @sql = @sql + 'controlled_time_of_departure = ctd_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'cta_utc')
BEGIN
    SET @sql = @sql + 'controlled_time_of_arrival = cta_utc, ';
    SET @hasUpdates = 1;
END

-- SimTraffic times
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'taxi_time_utc')
BEGIN
    SET @sql = @sql + 'taxi_start_time = taxi_time_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'sequence_time_utc')
BEGIN
    SET @sql = @sql + 'departure_sequence_time = sequence_time_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'holdshort_time_utc')
BEGIN
    SET @sql = @sql + 'hold_short_time = holdshort_time_utc, ';
    SET @hasUpdates = 1;
END

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights') AND name = 'runway_time_utc')
BEGIN
    SET @sql = @sql + 'runway_entry_time = runway_time_utc, ';
    SET @hasUpdates = 1;
END

-- Execute backfill if we have updates
IF @hasUpdates = 1
BEGIN
    -- Remove trailing comma and add WHERE clause
    SET @sql = LEFT(@sql, LEN(@sql) - 1) + ' WHERE 1=1';
    EXEC sp_executesql @sql;
    PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
END
ELSE
BEGIN
    PRINT 'No source columns found for backfill - new columns will be populated by sync scripts';
END
GO

-- ============================================================================
-- SECTION 6: Summary
-- ============================================================================

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 097 Complete: FIXM-Aligned Time Columns in ADL';
PRINT '';
PRINT '  NEW COLUMNS:';
PRINT '    OOOI Times:';
PRINT '      actual_off_block_time, actual_time_of_departure,';
PRINT '      actual_landing_time, actual_in_block_time';
PRINT '';
PRINT '    Estimated Times:';
PRINT '      estimated_time_of_arrival, estimated_off_block_time,';
PRINT '      estimated_runway_arrival_time';
PRINT '';
PRINT '    Controlled Times:';
PRINT '      controlled_time_of_departure, controlled_time_of_arrival';
PRINT '';
PRINT '    SimTraffic Times:';
PRINT '      taxi_start_time, departure_sequence_time,';
PRINT '      hold_short_time, runway_entry_time';
PRINT '==========================================================================';
GO
