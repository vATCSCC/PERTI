-- ============================================================================
-- Demand Page Covering Indexes
-- Eliminates key lookups for airport demand breakdown queries
--
-- The demand page queries filter on fp_dest_icao (arrivals) or fp_dept_icao
-- (departures) then SELECT breakdown columns (artcc, rule, fix, procedure,
-- aircraft_type). Without INCLUDE columns, SQL Server must do expensive key
-- lookups back to the clustered index for each matching row.
--
-- Also adds INCLUDE columns to time indexes for COALESCE(eta_runway_utc, eta_utc)
-- patterns used in all demand time binning.
--
-- Must DROP + CREATE since ALTER INDEX cannot add INCLUDE columns.
-- Run during low-traffic window.
--
-- Date: 2026-03-21
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Demand Page Covering Indexes ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. IX_fp_dest - Arrival queries filter on fp_dest_icao
-- Covers: origin ARTCC, rule, arrival fix, STAR, equipment breakdowns
-- ============================================================================

IF EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_fp_dest'
           AND object_id = OBJECT_ID('dbo.adl_flight_plan'))
BEGIN
    DROP INDEX IX_fp_dest ON dbo.adl_flight_plan;
    PRINT 'Dropped existing IX_fp_dest';
END
GO

CREATE NONCLUSTERED INDEX IX_fp_dest
ON dbo.adl_flight_plan (fp_dest_icao)
INCLUDE (fp_dept_icao, fp_dept_artcc, fp_dest_artcc, fp_rule, afix, star_name, aircraft_type)
WHERE fp_dest_icao IS NOT NULL;

PRINT 'Created IX_fp_dest with INCLUDE columns';
GO

-- ============================================================================
-- 2. IX_fp_dept - Departure queries filter on fp_dept_icao
-- Covers: dest ARTCC, rule, departure fix, DP, equipment breakdowns
-- ============================================================================

IF EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_fp_dept'
           AND object_id = OBJECT_ID('dbo.adl_flight_plan'))
BEGIN
    DROP INDEX IX_fp_dept ON dbo.adl_flight_plan;
    PRINT 'Dropped existing IX_fp_dept';
END
GO

CREATE NONCLUSTERED INDEX IX_fp_dept
ON dbo.adl_flight_plan (fp_dept_icao)
INCLUDE (fp_dest_icao, fp_dept_artcc, fp_dest_artcc, fp_rule, dfix, dp_name, aircraft_type)
WHERE fp_dept_icao IS NOT NULL;

PRINT 'Created IX_fp_dept with INCLUDE columns';
GO

-- ============================================================================
-- 3. IX_times_eta - Arrival time filtering with runway fallback
-- Covers: COALESCE(eta_runway_utc, eta_utc) pattern in demand queries
-- ============================================================================

IF EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_times_eta'
           AND object_id = OBJECT_ID('dbo.adl_flight_times'))
BEGIN
    DROP INDEX IX_times_eta ON dbo.adl_flight_times;
    PRINT 'Dropped existing IX_times_eta';
END
GO

CREATE NONCLUSTERED INDEX IX_times_eta
ON dbo.adl_flight_times (eta_utc)
INCLUDE (eta_runway_utc, etd_utc, etd_runway_utc)
WHERE eta_utc IS NOT NULL;

PRINT 'Created IX_times_eta with INCLUDE columns';
GO

-- ============================================================================
-- 4. IX_times_etd - Departure time filtering with runway fallback
-- Covers: COALESCE(etd_runway_utc, etd_utc) pattern in demand queries
-- ============================================================================

IF EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_times_etd'
           AND object_id = OBJECT_ID('dbo.adl_flight_times'))
BEGIN
    DROP INDEX IX_times_etd ON dbo.adl_flight_times;
    PRINT 'Dropped existing IX_times_etd';
END
GO

CREATE NONCLUSTERED INDEX IX_times_etd
ON dbo.adl_flight_times (etd_utc)
INCLUDE (etd_runway_utc, eta_utc, eta_runway_utc)
WHERE etd_utc IS NOT NULL;

PRINT 'Created IX_times_etd with INCLUDE columns';
GO

PRINT '';
PRINT '=== Demand Page Covering Indexes Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
