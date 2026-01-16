-- ============================================================================
-- fn_AirwayDemandBucketed: Returns time-bucketed demand for an entire airway
--
-- Purpose:
--   Query traffic demand for all flights using any segment of an airway,
--   with results grouped into time buckets for visualization.
--
-- Parameters:
--   @airway_name    - Required: Airway identifier (e.g., 'J48', 'V1', 'Q100')
--   @bucket_minutes - Time bucket size in minutes (default 15)
--   @horizon_hours  - Projection horizon in hours (default 4, max 12)
--   @start_utc      - Start of time window (default: current UTC time)
--
-- Example usage:
--   -- All traffic on J48 in 15-minute buckets for 4 hours
--   SELECT * FROM dbo.fn_AirwayDemandBucketed('J48', 15, 4, NULL);
--
--   -- V1 traffic in 30-minute buckets for 6 hours
--   SELECT * FROM dbo.fn_AirwayDemandBucketed('V1', 30, 6, NULL);
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER FUNCTION dbo.fn_AirwayDemandBucketed (
    @airway_name     NVARCHAR(8),        -- Required: airway identifier
    @bucket_minutes  INT = 15,           -- Time bucket size (default 15)
    @horizon_hours   INT = 4,            -- Projection horizon (default 4, max 12)
    @start_utc       DATETIME2 = NULL    -- Optional: start time (default GETUTCDATE())
)
RETURNS TABLE
AS
RETURN (
    WITH
    -- Time boundaries
    TimeBounds AS (
        SELECT
            ISNULL(@start_utc, GETUTCDATE()) AS start_time,
            DATEADD(HOUR, CASE WHEN @horizon_hours > 12 THEN 12 ELSE @horizon_hours END,
                    ISNULL(@start_utc, GETUTCDATE())) AS end_time
    ),

    -- Find flights with waypoints on this airway in the time window
    AirwayFlights AS (
        SELECT
            w.flight_uid,
            w.fix_name,
            w.eta_utc,
            DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / @bucket_minutes AS bucket_num
        FROM dbo.adl_flight_waypoints w
        CROSS JOIN TimeBounds tb
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
        WHERE w.on_airway = @airway_name
          AND w.eta_utc >= tb.start_time
          AND w.eta_utc < tb.end_time
          AND c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
    ),

    -- Get first waypoint per flight per bucket (flight counted once per bucket)
    DistinctFlightBuckets AS (
        SELECT DISTINCT
            flight_uid,
            bucket_num,
            MIN(eta_utc) AS first_eta
        FROM AirwayFlights
        GROUP BY flight_uid, bucket_num
    ),

    -- Aggregate by bucket
    BucketCounts AS (
        SELECT
            bucket_num,
            COUNT(DISTINCT flight_uid) AS flight_count
        FROM DistinctFlightBuckets
        WHERE bucket_num >= 0
        GROUP BY bucket_num
    )

    -- Return results with bucket info
    SELECT
        bc.bucket_num,
        bc.flight_count,
        DATEADD(MINUTE, bc.bucket_num * @bucket_minutes, ISNULL(@start_utc, GETUTCDATE())) AS bucket_start,
        @bucket_minutes AS bucket_minutes,
        @airway_name AS airway_name
    FROM BucketCounts bc
);
GO

PRINT 'Created function dbo.fn_AirwayDemandBucketed';
GO


-- ============================================================================
-- fn_AirwaySegmentDemandBucketed: Returns time-bucketed demand for an airway segment
--
-- Purpose:
--   Query traffic demand for flights using a specific segment of an airway
--   (between two fixes), with results grouped into time buckets.
--
-- Parameters:
--   @airway_name    - Required: Airway identifier (e.g., 'J48')
--   @from_fix       - Required: Segment start fix (e.g., 'LANNA')
--   @to_fix         - Required: Segment end fix (e.g., 'MOL')
--   @bucket_minutes - Time bucket size in minutes (default 15)
--   @horizon_hours  - Projection horizon in hours (default 4, max 12)
--   @start_utc      - Start of time window (default: current UTC time)
--
-- Example usage:
--   -- J48 LANNA to MOL traffic in 15-minute buckets
--   SELECT * FROM dbo.fn_AirwaySegmentDemandBucketed('J48', 'LANNA', 'MOL', 15, 4, NULL);
--
-- Date: 2026-01-15
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_AirwaySegmentDemandBucketed (
    @airway_name     NVARCHAR(8),        -- Required: airway identifier
    @from_fix        NVARCHAR(64),       -- Required: segment start fix
    @to_fix          NVARCHAR(64),       -- Required: segment end fix
    @bucket_minutes  INT = 15,           -- Time bucket size (default 15)
    @horizon_hours   INT = 4,            -- Projection horizon (default 4, max 12)
    @start_utc       DATETIME2 = NULL    -- Optional: start time (default GETUTCDATE())
)
RETURNS TABLE
AS
RETURN (
    WITH
    -- Time boundaries
    TimeBounds AS (
        SELECT
            ISNULL(@start_utc, GETUTCDATE()) AS start_time,
            DATEADD(HOUR, CASE WHEN @horizon_hours > 12 THEN 12 ELSE @horizon_hours END,
                    ISNULL(@start_utc, GETUTCDATE())) AS end_time
    ),

    -- Find active flights that have BOTH endpoint fixes in their route
    -- Note: Don't require on_airway to be set for endpoint fixes, just check fix_name
    FlightsWithBothFixes AS (
        SELECT c.flight_uid
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
          -- Has from_fix in route
          AND EXISTS (
              SELECT 1 FROM dbo.adl_flight_waypoints w1
              WHERE w1.flight_uid = c.flight_uid
                AND w1.fix_name = @from_fix
          )
          -- Has to_fix in route
          AND EXISTS (
              SELECT 1 FROM dbo.adl_flight_waypoints w2
              WHERE w2.flight_uid = c.flight_uid
                AND w2.fix_name = @to_fix
          )
    ),

    -- Get entry time for each flight (at from_fix) in time window
    FlightEntryTimes AS (
        SELECT
            f.flight_uid,
            w.eta_utc AS entry_eta,
            DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / @bucket_minutes AS bucket_num
        FROM FlightsWithBothFixes f
        CROSS JOIN TimeBounds tb
        INNER JOIN dbo.adl_flight_waypoints w ON w.flight_uid = f.flight_uid
        WHERE w.fix_name = @from_fix
          AND w.eta_utc >= tb.start_time
          AND w.eta_utc < tb.end_time
    ),

    -- Aggregate by bucket
    BucketCounts AS (
        SELECT
            bucket_num,
            COUNT(DISTINCT flight_uid) AS flight_count
        FROM FlightEntryTimes
        WHERE bucket_num >= 0
        GROUP BY bucket_num
    )

    -- Return results with bucket info
    SELECT
        bc.bucket_num,
        bc.flight_count,
        DATEADD(MINUTE, bc.bucket_num * @bucket_minutes, ISNULL(@start_utc, GETUTCDATE())) AS bucket_start,
        @bucket_minutes AS bucket_minutes,
        @airway_name AS airway_name,
        @from_fix AS from_fix,
        @to_fix AS to_fix
    FROM BucketCounts bc
);
GO

PRINT 'Created function dbo.fn_AirwaySegmentDemandBucketed';
GO
