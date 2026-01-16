-- ============================================================================
-- fn_ViaDemandBucketed: Returns time-bucketed demand for filtered traffic via a fix/airway
--
-- Purpose:
--   Query traffic demand filtered by origin/destination (airport, TRACON, or ARTCC)
--   passing through a specific fix or airway, with results grouped into time buckets.
--
-- Parameters:
--   @filter_type   - Filter type: 'airport', 'tracon', or 'artcc'
--   @filter_code   - Filter code (e.g., 'KJFK', 'N90', 'ZDC')
--   @direction     - Direction filter: 'arr', 'dep', or 'both'
--   @via_value     - Fix name or airway identifier to pass through
--   @via_type      - Type of via: 'fix' or 'airway'
--   @bucket_minutes - Time bucket size in minutes (default 15)
--   @horizon_hours  - Projection horizon in hours (default 4, max 12)
--   @start_utc      - Start of time window (default: current UTC time)
--
-- Example usage:
--   -- KBOS arrivals via MERIT in 15-minute buckets for 4 hours
--   SELECT * FROM dbo.fn_ViaDemandBucketed('airport', 'KBOS', 'arr', 'MERIT', 'fix', 15, 4, NULL);
--
--   -- N90 departures via WAVEY
--   SELECT * FROM dbo.fn_ViaDemandBucketed('tracon', 'N90', 'dep', 'WAVEY', 'fix', 15, 4, NULL);
--
--   -- ZDC traffic (both directions) via J48 airway
--   SELECT * FROM dbo.fn_ViaDemandBucketed('artcc', 'ZDC', 'both', 'J48', 'airway', 15, 4, NULL);
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER FUNCTION dbo.fn_ViaDemandBucketed (
    @filter_type     NVARCHAR(8),        -- 'airport', 'tracon', 'artcc'
    @filter_code     NVARCHAR(8),        -- KJFK, N90, ZDC, etc.
    @direction       NVARCHAR(4),        -- 'arr', 'dep', 'both'
    @via_value       NVARCHAR(64),       -- Fix name or airway identifier
    @via_type        NVARCHAR(8),        -- 'fix' or 'airway'
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

    -- Filter flights by origin/destination based on filter_type and direction
    FilteredFlights AS (
        SELECT DISTINCT
            c.flight_uid
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
          -- Airport filter
          AND (
              @filter_type != 'airport'
              OR (
                  (@direction = 'dep' AND fp.fp_dept_icao = @filter_code)
                  OR (@direction = 'arr' AND fp.fp_dest_icao = @filter_code)
                  OR (@direction = 'both' AND (fp.fp_dept_icao = @filter_code OR fp.fp_dest_icao = @filter_code))
              )
          )
          -- TRACON filter
          AND (
              @filter_type != 'tracon'
              OR (
                  (@direction = 'dep' AND fp.fp_dept_tracon = @filter_code)
                  OR (@direction = 'arr' AND fp.fp_dest_tracon = @filter_code)
                  OR (@direction = 'both' AND (fp.fp_dept_tracon = @filter_code OR fp.fp_dest_tracon = @filter_code))
              )
          )
          -- ARTCC filter
          AND (
              @filter_type != 'artcc'
              OR (
                  (@direction = 'dep' AND fp.fp_dept_artcc = @filter_code)
                  OR (@direction = 'arr' AND fp.fp_dest_artcc = @filter_code)
                  OR (@direction = 'both' AND (fp.fp_dept_artcc = @filter_code OR fp.fp_dest_artcc = @filter_code))
              )
          )
    ),

    -- Find flights that pass through the via point (fix or airway)
    ViaFlights AS (
        SELECT
            ff.flight_uid,
            w.eta_utc,
            w.fix_name,
            w.on_airway,
            -- Calculate bucket number
            DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / @bucket_minutes AS bucket_num
        FROM FilteredFlights ff
        CROSS JOIN TimeBounds tb
        INNER JOIN dbo.adl_flight_waypoints w ON w.flight_uid = ff.flight_uid
        WHERE w.eta_utc >= tb.start_time
          AND w.eta_utc < tb.end_time
          -- Via fix filter
          AND (
              (@via_type = 'fix' AND w.fix_name = @via_value)
              OR (@via_type = 'airway' AND (',' + ISNULL(w.on_airway, '') + ',') LIKE '%,' + @via_value + ',%')
          )
    ),

    -- Get distinct flight per bucket (flight may cross via point once per bucket)
    DistinctFlightBuckets AS (
        SELECT DISTINCT
            flight_uid,
            bucket_num,
            MIN(eta_utc) AS first_eta,
            fix_name
        FROM ViaFlights
        GROUP BY flight_uid, bucket_num, fix_name
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
        @filter_type AS filter_type,
        @filter_code AS filter_code,
        @direction AS direction,
        @via_value AS via_value,
        @via_type AS via_type
    FROM BucketCounts bc
);
GO

PRINT 'Created function dbo.fn_ViaDemandBucketed';
GO
