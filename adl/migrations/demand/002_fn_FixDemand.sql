-- ============================================================================
-- fn_FixDemand: Returns flights passing through a specific fix in a time window
--
-- Purpose:
--   Query airspace element demand at specific navigation fixes.
--   Supports filtering by departure/arrival TRACON for facility-specific queries.
--
-- Parameters:
--   @fix_name      - Required: Navigation fix identifier (e.g., 'MERIT', 'LANNA')
--   @minutes_ahead - Time window in minutes from start time (default 60)
--   @start_utc     - Start of time window (default: current UTC time)
--   @dep_tracon    - Optional: Filter by departure TRACON (e.g., 'N90')
--   @arr_tracon    - Optional: Filter by arrival TRACON
--
-- Example usage:
--   -- N90 departures over MERIT in next 45 minutes
--   SELECT * FROM dbo.fn_FixDemand('MERIT', 45, NULL, 'N90', NULL);
--
--   -- All traffic over MERIT in next hour
--   SELECT COUNT(*) FROM dbo.fn_FixDemand('MERIT', 60, NULL, NULL, NULL);
--
--   -- Arrivals to N90 passing MERIT
--   SELECT * FROM dbo.fn_FixDemand('MERIT', 60, NULL, NULL, 'N90');
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER FUNCTION dbo.fn_FixDemand (
    @fix_name       NVARCHAR(64),           -- Required: fix identifier (e.g., 'MERIT')
    @minutes_ahead  INT = 60,               -- Time window in minutes (default 60)
    @start_utc      DATETIME2 = NULL,       -- Optional: start time (default GETUTCDATE())
    @dep_tracon     NVARCHAR(4) = NULL,     -- Optional: departure TRACON filter (e.g., 'N90')
    @arr_tracon     NVARCHAR(4) = NULL      -- Optional: arrival TRACON filter
)
RETURNS TABLE
AS
RETURN (
    SELECT
        w.flight_uid,
        c.callsign,
        fp.fp_dept_icao AS departure,
        fp.fp_dest_icao AS destination,
        fp.fp_dept_tracon,
        fp.fp_dest_tracon,
        fp.aircraft_type,
        w.fix_name,
        w.eta_utc AS eta_at_fix,
        DATEDIFF(MINUTE, GETUTCDATE(), w.eta_utc) AS minutes_until_fix,
        w.on_airway,
        w.sequence_num,
        c.phase
    FROM dbo.adl_flight_waypoints w
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = w.flight_uid
    WHERE w.fix_name = @fix_name
      AND w.eta_utc >= ISNULL(@start_utc, GETUTCDATE())
      AND w.eta_utc < DATEADD(MINUTE, @minutes_ahead, ISNULL(@start_utc, GETUTCDATE()))
      AND c.is_active = 1
      AND c.phase NOT IN ('arrived', 'disconnected')
      AND (@dep_tracon IS NULL OR fp.fp_dept_tracon = @dep_tracon)
      AND (@arr_tracon IS NULL OR fp.fp_dest_tracon = @arr_tracon)
);
GO

PRINT 'Created function dbo.fn_FixDemand';
GO
