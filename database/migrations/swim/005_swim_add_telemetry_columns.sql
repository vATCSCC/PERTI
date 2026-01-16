-- ============================================================================
-- 005_swim_add_telemetry_columns.sql
-- SWIM_API Database: Add telemetry columns for AOC data
--
-- Adds true_airspeed_kts column if missing (vertical_rate_fpm should exist)
-- These fields are populated by AOC ingest, not VATSIM sync
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Adding Telemetry Columns to swim_flights';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- Add true_airspeed_kts if not exists
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'true_airspeed_kts')
BEGIN
    ALTER TABLE dbo.swim_flights ADD true_airspeed_kts SMALLINT NULL;
    PRINT '✓ Added true_airspeed_kts column';
END
ELSE
BEGIN
    PRINT '✓ true_airspeed_kts column already exists';
END
GO

-- Verify vertical_rate_fpm exists
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'vertical_rate_fpm')
BEGIN
    ALTER TABLE dbo.swim_flights ADD vertical_rate_fpm SMALLINT NULL;
    PRINT '✓ Added vertical_rate_fpm column';
END
ELSE
BEGIN
    PRINT '✓ vertical_rate_fpm column already exists';
END
GO

-- Verify OOOI time columns exist
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'out_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD out_utc DATETIME2(0) NULL;
    PRINT '✓ Added out_utc column';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'off_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD off_utc DATETIME2(0) NULL;
    PRINT '✓ Added off_utc column';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'on_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD on_utc DATETIME2(0) NULL;
    PRINT '✓ Added on_utc column';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'in_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD in_utc DATETIME2(0) NULL;
    PRINT '✓ Added in_utc column';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Telemetry columns verified/added successfully';
PRINT '';
PRINT '  These fields can now be populated via:';
PRINT '  - POST /api/swim/v1/ingest/adl    (full flight data)';
PRINT '  - POST /api/swim/v1/ingest/track  (position updates)';
PRINT '';
PRINT '  Fields supported:';
PRINT '  - vertical_rate_fpm   (climb/descent rate from flight sim)';
PRINT '  - true_airspeed_kts   (TAS from flight sim instruments)';
PRINT '  - out_utc, off_utc, on_utc, in_utc  (OOOI times from ACARS)';
PRINT '==========================================================================';
GO
