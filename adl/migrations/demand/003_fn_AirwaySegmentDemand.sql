-- ============================================================================
-- fn_AirwaySegmentDemand: Returns flights on an airway between two fixes
--
-- Purpose:
--   Query traffic demand on a specific segment of an airway.
--   Returns flights that will pass through BOTH bounding fixes on the airway.
--
-- Parameters:
--   @airway_name   - Required: Airway identifier (e.g., 'J48', 'V1', 'Q100')
--   @from_fix      - Required: Segment start fix (e.g., 'LANNA')
--   @to_fix        - Required: Segment end fix (e.g., 'MOL')
--   @minutes_ahead - Time window in minutes from start time (default 60)
--   @start_utc     - Start of time window (default: current UTC time)
--
-- Example usage:
--   -- Traffic on J48 between LANNA and MOL in next 3 hours
--   SELECT * FROM dbo.fn_AirwaySegmentDemand('J48', 'LANNA', 'MOL', 180, NULL);
--
--   -- Count of flights on J60 segment
--   SELECT COUNT(*) FROM dbo.fn_AirwaySegmentDemand('J60', 'MERIT', 'HAARP', 120, NULL);
--
-- Note:
--   Flight must have both @from_fix and @to_fix in their route with on_airway
--   matching @airway_name. This ensures we only count flights actually using
--   the requested airway segment, not flights that just happen to pass both fixes.
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER FUNCTION dbo.fn_AirwaySegmentDemand (
    @airway_name    NVARCHAR(8),            -- Required: airway identifier (e.g., 'J48')
    @from_fix       NVARCHAR(64),           -- Required: segment start fix (e.g., 'LANNA')
    @to_fix         NVARCHAR(64),           -- Required: segment end fix (e.g., 'MOL')
    @minutes_ahead  INT = 60,               -- Time window in minutes (default 60)
    @start_utc      DATETIME2 = NULL        -- Optional: start time (default GETUTCDATE())
)
RETURNS TABLE
AS
RETURN (
    WITH FlightsOnAirway AS (
        -- Find flights with waypoints on this airway in the time window
        SELECT DISTINCT w.flight_uid
        FROM dbo.adl_flight_waypoints w
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
        WHERE w.on_airway = @airway_name
          AND w.eta_utc >= ISNULL(@start_utc, GETUTCDATE())
          AND w.eta_utc < DATEADD(MINUTE, @minutes_ahead, ISNULL(@start_utc, GETUTCDATE()))
          AND c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
    ),
    FlightsWithBothFixes AS (
        -- Filter to flights that pass through BOTH bounding fixes on this airway
        SELECT f.flight_uid
        FROM FlightsOnAirway f
        WHERE EXISTS (
            SELECT 1 FROM dbo.adl_flight_waypoints w1
            WHERE w1.flight_uid = f.flight_uid
              AND w1.on_airway = @airway_name
              AND w1.fix_name = @from_fix
        )
        AND EXISTS (
            SELECT 1 FROM dbo.adl_flight_waypoints w2
            WHERE w2.flight_uid = f.flight_uid
              AND w2.on_airway = @airway_name
              AND w2.fix_name = @to_fix
        )
    ),
    FlightSegmentTimes AS (
        -- Get entry/exit times for matching flights
        SELECT
            w.flight_uid,
            MIN(CASE WHEN w.fix_name = @from_fix THEN w.eta_utc END) AS entry_eta,
            MIN(CASE WHEN w.fix_name = @to_fix THEN w.eta_utc END) AS exit_eta
        FROM dbo.adl_flight_waypoints w
        INNER JOIN FlightsWithBothFixes f ON f.flight_uid = w.flight_uid
        WHERE w.on_airway = @airway_name
          AND w.fix_name IN (@from_fix, @to_fix)
        GROUP BY w.flight_uid
    )
    SELECT
        fst.flight_uid,
        c.callsign,
        fp.fp_dept_icao AS departure,
        fp.fp_dest_icao AS destination,
        fp.fp_dept_tracon,
        fp.fp_dest_tracon,
        fp.aircraft_type,
        @airway_name AS airway,
        @from_fix AS segment_from,
        @to_fix AS segment_to,
        fst.entry_eta,
        fst.exit_eta,
        DATEDIFF(MINUTE, fst.entry_eta, fst.exit_eta) AS segment_minutes,
        DATEDIFF(MINUTE, GETUTCDATE(), fst.entry_eta) AS minutes_until_entry,
        c.phase
    FROM FlightSegmentTimes fst
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fst.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = fst.flight_uid
    WHERE fst.entry_eta IS NOT NULL
      AND fst.exit_eta IS NOT NULL
);
GO

PRINT 'Created function dbo.fn_AirwaySegmentDemand';
GO
