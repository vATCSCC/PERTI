-- ============================================================================
-- 040_swim_ete_etot_columns.sql
-- SWIM_API Database: Add ETOT and computed EET columns for CTP integration
--
-- estimated_takeoff_time: Computed ETOT = TOBT + taxi_ref (wheels-up estimate)
-- computed_ete_minutes:   Computed EET from sp_CalculateETA (distinct from
--                         pilot-filed ete_minutes which is user-reported)
--
-- These columns are written by the CTP API endpoints (ete.php, ctot.php)
-- and are NOT synced by swim_sync_daemon (not in its column list).
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Migration 040: ETOT and Computed EET Columns for CTP';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- estimated_takeoff_time (ETOT) — TOBT + taxi reference
-- FIXM: estimatedTakeoffTime
-- Distinct from target_takeoff_time (TTOT/CTOT = controlled wheels-up)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_takeoff_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_takeoff_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_takeoff_time (ETOT = TOBT + taxi)';
END
ELSE PRINT '= estimated_takeoff_time already exists';
GO

-- computed_ete_minutes — server-computed enroute time from sp_CalculateETA
-- Distinct from ete_minutes which is the PILOT-FILED enroute time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'computed_ete_minutes')
BEGIN
    ALTER TABLE dbo.swim_flights ADD computed_ete_minutes SMALLINT NULL;
    PRINT '+ Added computed_ete_minutes (sp_CalculateETA result, distinct from pilot-filed ete_minutes)';
END
ELSE PRINT '= computed_ete_minutes already exists';
GO

PRINT '';
PRINT '  Migration 040 Complete';
PRINT '  New columns: estimated_takeoff_time, computed_ete_minutes';
PRINT '  NOTE: Existing ete_minutes column is pilot-filed and is NOT touched';
GO
