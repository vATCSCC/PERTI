-- ============================================================================
-- VATUSA Event Statistics - Master Import Script
-- Generated: 2026-01-07 05:44:36
-- 
-- Run this file with sqlcmd to execute all import files in order:
--   sqlcmd -S your_server -d VATSIM_ADL -i 074_vatusa_event_import_RUN_ALL.sql
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Starting VATUSA Event Import ===';
PRINT '';
GO

:r 074_vatusa_event_import_1_events.sql
:r 074_vatusa_event_import_2_airports.sql
:r 074_vatusa_event_import_3_hourly_01.sql

PRINT '';
PRINT '=== Import Complete ===';
GO

-- Final verification
SELECT 'vatusa_event' AS [Table], COUNT(*) AS [Count] FROM dbo.vatusa_event
UNION ALL
SELECT 'vatusa_event_airport', COUNT(*) FROM dbo.vatusa_event_airport
UNION ALL
SELECT 'vatusa_event_hourly', COUNT(*) FROM dbo.vatusa_event_hourly;
GO
