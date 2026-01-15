-- ============================================================================
-- fn_RouteSegmentDemand: Returns flights passing through two fixes in sequence
--
-- Purpose:
--   Query traffic demand between two fixes, regardless of whether they filed
--   via an airway or direct (DCT). More practical for VATSIM where pilots
--   often file direct routes rather than airways.
--
-- Parameters:
--   @from_fix      - Required: First fix (e.g., 'CAM')
--   @to_fix        - Required: Second fix (e.g., 'GONZZ')
--   @minutes_ahead - Time window in minutes from start time (default 60)
--   @start_utc     - Start of time window (default: current UTC time)
--
-- Example usage:
--   -- Traffic between CAM and GONZZ in next 3 hours
--   SELECT * FROM dbo.fn_RouteSegmentDemand('CAM', 'GONZZ', 180, NULL);
--
--   -- Count flights
--   SELECT COUNT(*) FROM dbo.fn_RouteSegmentDemand('LANNA', 'MOL', 60, NULL);
--
-- Note:
--   Unlike fn_AirwaySegmentDemand, this does NOT require on_airway to be set.
--   It finds flights where both fixes appear in the parsed route.
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER FUNCTION dbo.fn_RouteSegmentDemand (
    @from_fix       NVARCHAR(64),           -- Required: first fix (e.g., 'CAM')
    @to_fix         NVARCHAR(64),           -- Required: second fix (e.g., 'GONZZ')
    @minutes_ahead  INT = 60,               -- Time window in minutes (default 60)
    @start_utc      DATETIME2 = NULL        -- Optional: start time (default GETUTCDATE())
)
RETURNS TABLE
AS
RETURN (
    WITH FlightsWithFromFix AS (
        -- Find flights with the first fix in the time window
        SELECT w.flight_uid, w.eta_utc AS from_eta, w.sequence_num AS from_seq, w.on_airway
        FROM dbo.adl_flight_waypoints w
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
        WHERE w.fix_name = @from_fix
          AND w.eta_utc >= ISNULL(@start_utc, GETUTCDATE())
          AND w.eta_utc < DATEADD(MINUTE, @minutes_ahead, ISNULL(@start_utc, GETUTCDATE()))
          AND c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
    ),
    FlightsWithBothFixes AS (
        -- Join with flights that also have the second fix
        SELECT
            f.flight_uid,
            f.from_eta,
            f.from_seq,
            f.on_airway AS from_airway,
            w2.eta_utc AS to_eta,
            w2.sequence_num AS to_seq,
            w2.on_airway AS to_airway
        FROM FlightsWithFromFix f
        INNER JOIN dbo.adl_flight_waypoints w2 ON w2.flight_uid = f.flight_uid
        WHERE w2.fix_name = @to_fix
    )
    SELECT
        f.flight_uid,
        c.callsign,
        fp.fp_dept_icao AS departure,
        fp.fp_dest_icao AS destination,
        fp.fp_dept_tracon,
        fp.fp_dest_tracon,
        fp.aircraft_type,
        @from_fix AS segment_from,
        @to_fix AS segment_to,
        f.from_eta AS entry_eta,
        f.to_eta AS exit_eta,
        DATEDIFF(MINUTE, f.from_eta, f.to_eta) AS segment_minutes,
        DATEDIFF(MINUTE, GETUTCDATE(), f.from_eta) AS minutes_until_entry,
        -- Direction: positive = from_fix before to_fix, negative = reverse
        CASE WHEN f.from_seq < f.to_seq THEN 'forward' ELSE 'reverse' END AS direction,
        COALESCE(f.from_airway, f.to_airway) AS on_airway,
        c.phase
    FROM FlightsWithBothFixes f
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = f.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = f.flight_uid
);
GO

PRINT 'Created function dbo.fn_RouteSegmentDemand';
GO
