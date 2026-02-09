/*
    ADL Raw Data Lake - Query Views

    Step 3: Create views for common analysis patterns.

    These views encapsulate typical query patterns and enable efficient
    data retrieval with automatic partition pruning.

    Important: Always include [year], [month], [day] filters for efficiency!
    Synapse charges $5/TB scanned, so partition pruning is essential.

    Author: Claude (AI-assisted implementation)
    Date: 2026-02-02
*/

USE ADL_Archive;
GO

-- =============================================================================
-- Daily Flight Summary View
-- =============================================================================
-- Aggregates trajectory data by flight for a given day.
-- Use for flight counts, duration analysis, airport pair statistics.

CREATE OR ALTER VIEW dbo.v_daily_flight_summary
AS
SELECT
    [year],
    [month],
    [day],
    flight_date,
    flight_uid,
    callsign,
    dept_icao,
    dest_icao,
    COUNT(*) AS trajectory_points,
    MIN(timestamp_utc) AS first_seen_utc,
    MAX(timestamp_utc) AS last_seen_utc,
    DATEDIFF(MINUTE, MIN(timestamp_utc), MAX(timestamp_utc)) AS duration_minutes,
    MIN(altitude_ft) AS min_altitude_ft,
    MAX(altitude_ft) AS max_altitude_ft,
    AVG(groundspeed_kts) AS avg_groundspeed_kts
FROM dbo.v_trajectory_archive
GROUP BY
    [year], [month], [day], flight_date,
    flight_uid, callsign, dept_icao, dest_icao;
GO

-- =============================================================================
-- Hourly Traffic View
-- =============================================================================
-- Traffic counts by hour for a given day.
-- Useful for traffic flow analysis and peak hour detection.

CREATE OR ALTER VIEW dbo.v_hourly_traffic
AS
SELECT
    [year],
    [month],
    [day],
    flight_date,
    DATEPART(HOUR, timestamp_utc) AS hour_utc,
    COUNT(DISTINCT flight_uid) AS active_flights,
    COUNT(*) AS trajectory_points,
    AVG(altitude_ft) AS avg_altitude_ft,
    AVG(groundspeed_kts) AS avg_groundspeed_kts
FROM dbo.v_trajectory_archive
GROUP BY
    [year], [month], [day], flight_date,
    DATEPART(HOUR, timestamp_utc);
GO

-- =============================================================================
-- Airport Daily Stats View
-- =============================================================================
-- Departure and arrival counts by airport per day.
-- Supports both departure and arrival analysis.

CREATE OR ALTER VIEW dbo.v_airport_daily_stats
AS
WITH departures AS (
    SELECT
        [year], [month], [day], flight_date,
        dept_icao AS icao,
        COUNT(DISTINCT flight_uid) AS departures
    FROM dbo.v_trajectory_archive
    WHERE dept_icao IS NOT NULL AND dept_icao != ''
    GROUP BY [year], [month], [day], flight_date, dept_icao
),
arrivals AS (
    SELECT
        [year], [month], [day], flight_date,
        dest_icao AS icao,
        COUNT(DISTINCT flight_uid) AS arrivals
    FROM dbo.v_trajectory_archive
    WHERE dest_icao IS NOT NULL AND dest_icao != ''
    GROUP BY [year], [month], [day], flight_date, dest_icao
)
SELECT
    COALESCE(d.[year], a.[year]) AS [year],
    COALESCE(d.[month], a.[month]) AS [month],
    COALESCE(d.[day], a.[day]) AS [day],
    COALESCE(d.flight_date, a.flight_date) AS flight_date,
    COALESCE(d.icao, a.icao) AS icao,
    ISNULL(d.departures, 0) AS departures,
    ISNULL(a.arrivals, 0) AS arrivals,
    ISNULL(d.departures, 0) + ISNULL(a.arrivals, 0) AS total_operations
FROM departures d
FULL OUTER JOIN arrivals a
    ON d.[year] = a.[year]
    AND d.[month] = a.[month]
    AND d.[day] = a.[day]
    AND d.icao = a.icao;
GO

-- =============================================================================
-- Sample Query Functions (Stored Procedures)
-- =============================================================================

-- Get flight trajectory
CREATE OR ALTER PROCEDURE dbo.sp_get_flight_trajectory
    @callsign VARCHAR(10),
    @flight_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        flight_uid,
        callsign,
        dept_icao,
        dest_icao,
        timestamp_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        heading_deg,
        vertical_rate_fpm
    FROM dbo.v_trajectory_archive
    WHERE [year] = YEAR(@flight_date)
      AND [month] = MONTH(@flight_date)
      AND [day] = DAY(@flight_date)
      AND callsign = @callsign
    ORDER BY timestamp_utc;
END
GO

-- Get airport pair flights
CREATE OR ALTER PROCEDURE dbo.sp_get_airport_pair_flights
    @dept_icao VARCHAR(4),
    @dest_icao VARCHAR(4),
    @start_date DATE,
    @end_date DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @end_date = ISNULL(@end_date, @start_date);

    SELECT
        flight_date,
        flight_uid,
        callsign,
        first_seen_utc,
        last_seen_utc,
        duration_minutes,
        trajectory_points
    FROM dbo.v_daily_flight_summary
    WHERE flight_date BETWEEN @start_date AND @end_date
      AND dept_icao = @dept_icao
      AND dest_icao = @dest_icao
    ORDER BY first_seen_utc;
END
GO

-- Get daily statistics
CREATE OR ALTER PROCEDURE dbo.sp_get_daily_stats
    @flight_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    -- Flight counts
    SELECT
        COUNT(DISTINCT flight_uid) AS total_flights,
        COUNT(DISTINCT callsign) AS unique_callsigns,
        COUNT(DISTINCT dept_icao) AS departure_airports,
        COUNT(DISTINCT dest_icao) AS arrival_airports,
        COUNT(*) AS trajectory_points
    FROM dbo.v_trajectory_archive
    WHERE [year] = YEAR(@flight_date)
      AND [month] = MONTH(@flight_date)
      AND [day] = DAY(@flight_date);

    -- Top departure airports
    SELECT TOP 10
        dept_icao,
        COUNT(DISTINCT flight_uid) AS departures
    FROM dbo.v_trajectory_archive
    WHERE [year] = YEAR(@flight_date)
      AND [month] = MONTH(@flight_date)
      AND [day] = DAY(@flight_date)
      AND dept_icao IS NOT NULL AND dept_icao != ''
    GROUP BY dept_icao
    ORDER BY departures DESC;

    -- Hourly traffic
    SELECT
        DATEPART(HOUR, timestamp_utc) AS hour_utc,
        COUNT(DISTINCT flight_uid) AS active_flights
    FROM dbo.v_trajectory_archive
    WHERE [year] = YEAR(@flight_date)
      AND [month] = MONTH(@flight_date)
      AND [day] = DAY(@flight_date)
    GROUP BY DATEPART(HOUR, timestamp_utc)
    ORDER BY hour_utc;
END
GO

PRINT 'Views and stored procedures created successfully.';
PRINT '';
PRINT 'Example queries:';
PRINT '  EXEC dbo.sp_get_flight_trajectory ''AAL123'', ''2026-01-31'';';
PRINT '  EXEC dbo.sp_get_airport_pair_flights ''KJFK'', ''KLAX'', ''2026-01-15'', ''2026-01-31'';';
PRINT '  EXEC dbo.sp_get_daily_stats ''2026-01-31'';';
GO
