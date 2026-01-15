-- ============================================================================
-- fn_BatchDemandBucketed: Returns time-bucketed demand counts for multiple monitors
--
-- Purpose:
--   Efficiently query traffic demand for multiple fixes/segments in a single call,
--   returning counts grouped into time buckets for visualization.
--
-- Parameters:
--   @monitors_json   - Required: JSON array of monitor definitions
--   @bucket_minutes  - Time bucket size in minutes (default 15)
--   @horizon_hours   - Projection horizon in hours (default 4, max 12)
--   @start_utc       - Start of time window (default: current UTC time)
--
-- Monitor JSON format:
--   [
--     { "type": "fix", "fix": "MERIT" },
--     { "type": "segment", "from": "CAM", "to": "GONZZ" }
--   ]
--
-- Example usage:
--   DECLARE @monitors NVARCHAR(MAX) = '[{"type":"fix","fix":"MERIT"},{"type":"segment","from":"CAM","to":"GONZZ"}]';
--   SELECT * FROM dbo.fn_BatchDemandBucketed(@monitors, 15, 4, NULL);
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER FUNCTION dbo.fn_BatchDemandBucketed (
    @monitors_json   NVARCHAR(MAX),         -- Required: JSON array of monitors
    @bucket_minutes  INT = 15,              -- Time bucket size (default 15)
    @horizon_hours   INT = 4,               -- Projection horizon (default 4, max 12)
    @start_utc       DATETIME2 = NULL       -- Optional: start time (default GETUTCDATE())
)
RETURNS TABLE
AS
RETURN (
    WITH
    -- Parse the JSON monitors array
    ParsedMonitors AS (
        SELECT
            ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS monitor_idx,
            JSON_VALUE(value, '$.type') AS monitor_type,
            JSON_VALUE(value, '$.fix') AS fix_name,
            JSON_VALUE(value, '$.from') AS from_fix,
            JSON_VALUE(value, '$.to') AS to_fix
        FROM OPENJSON(@monitors_json)
    ),

    -- Time boundaries
    TimeBounds AS (
        SELECT
            ISNULL(@start_utc, GETUTCDATE()) AS start_time,
            DATEADD(HOUR, CASE WHEN @horizon_hours > 12 THEN 12 ELSE @horizon_hours END,
                    ISNULL(@start_utc, GETUTCDATE())) AS end_time
    ),

    -- Fix monitors: get flights through each fix
    FixDemand AS (
        SELECT
            m.monitor_idx,
            'fix' AS monitor_type,
            m.fix_name,
            NULL AS from_fix,
            NULL AS to_fix,
            w.flight_uid,
            w.eta_utc,
            -- Calculate bucket number
            DATEDIFF(MINUTE, tb.start_time, w.eta_utc) / @bucket_minutes AS bucket_num
        FROM ParsedMonitors m
        CROSS JOIN TimeBounds tb
        INNER JOIN dbo.adl_flight_waypoints w ON w.fix_name = m.fix_name
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
        WHERE m.monitor_type = 'fix'
          AND w.eta_utc >= tb.start_time
          AND w.eta_utc < tb.end_time
          AND c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
    ),

    -- Segment monitors: get flights through both fixes
    SegmentFlightsFrom AS (
        SELECT
            m.monitor_idx,
            m.from_fix,
            m.to_fix,
            w.flight_uid,
            w.eta_utc AS from_eta,
            w.sequence_num AS from_seq
        FROM ParsedMonitors m
        CROSS JOIN TimeBounds tb
        INNER JOIN dbo.adl_flight_waypoints w ON w.fix_name = m.from_fix
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
        WHERE m.monitor_type = 'segment'
          AND w.eta_utc >= tb.start_time
          AND w.eta_utc < tb.end_time
          AND c.is_active = 1
          AND c.phase NOT IN ('arrived', 'disconnected')
    ),

    SegmentDemand AS (
        SELECT
            sf.monitor_idx,
            'segment' AS monitor_type,
            NULL AS fix_name,
            sf.from_fix,
            sf.to_fix,
            sf.flight_uid,
            sf.from_eta AS eta_utc,
            DATEDIFF(MINUTE, tb.start_time, sf.from_eta) / @bucket_minutes AS bucket_num
        FROM SegmentFlightsFrom sf
        CROSS JOIN TimeBounds tb
        -- Must also have the to_fix in route
        WHERE EXISTS (
            SELECT 1
            FROM dbo.adl_flight_waypoints w2
            WHERE w2.flight_uid = sf.flight_uid
              AND w2.fix_name = sf.to_fix
        )
    ),

    -- Combine fix and segment demand
    AllDemand AS (
        SELECT monitor_idx, monitor_type, fix_name, from_fix, to_fix, flight_uid, eta_utc, bucket_num
        FROM FixDemand
        UNION ALL
        SELECT monitor_idx, monitor_type, fix_name, from_fix, to_fix, flight_uid, eta_utc, bucket_num
        FROM SegmentDemand
    ),

    -- Aggregate by monitor and bucket
    BucketCounts AS (
        SELECT
            monitor_idx,
            monitor_type,
            fix_name,
            from_fix,
            to_fix,
            bucket_num,
            COUNT(DISTINCT flight_uid) AS flight_count
        FROM AllDemand
        GROUP BY monitor_idx, monitor_type, fix_name, from_fix, to_fix, bucket_num
    )

    -- Return results with monitor info and coordinates
    SELECT
        bc.monitor_idx,
        bc.monitor_type,
        bc.fix_name,
        bc.from_fix,
        bc.to_fix,
        bc.bucket_num,
        bc.flight_count,
        -- Fix coordinates (for fix monitors)
        nf.lat AS fix_lat,
        nf.lon AS fix_lon,
        -- Segment coordinates (for segment monitors)
        nf_from.lat AS from_lat,
        nf_from.lon AS from_lon,
        nf_to.lat AS to_lat,
        nf_to.lon AS to_lon,
        -- Bucket time info
        DATEADD(MINUTE, bc.bucket_num * @bucket_minutes, ISNULL(@start_utc, GETUTCDATE())) AS bucket_start,
        @bucket_minutes AS bucket_minutes
    FROM BucketCounts bc
    LEFT JOIN dbo.nav_fixes nf ON bc.monitor_type = 'fix' AND nf.fix_name = bc.fix_name
    LEFT JOIN dbo.nav_fixes nf_from ON bc.monitor_type = 'segment' AND nf_from.fix_name = bc.from_fix
    LEFT JOIN dbo.nav_fixes nf_to ON bc.monitor_type = 'segment' AND nf_to.fix_name = bc.to_fix
);
GO

PRINT 'Created function dbo.fn_BatchDemandBucketed';
GO
