-- =====================================================
-- VATUSA Event Statistics - Computed Columns
-- Migration: 075_vatusa_event_computed_columns.sql
-- Purpose: Convert static Excel-imported columns to
--          SQL computed columns for automatic calculation
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Converting static columns to computed columns...';
GO

-- =====================================================
-- 1. HOURLY TABLE: throughput = arrivals + departures
-- =====================================================

-- Drop the existing column and recreate as computed
ALTER TABLE dbo.vatusa_event_hourly DROP COLUMN throughput;
GO

ALTER TABLE dbo.vatusa_event_hourly
ADD throughput AS (ISNULL(arrivals, 0) + ISNULL(departures, 0)) PERSISTED;
GO

PRINT '  - hourly.throughput now computed';
GO

-- =====================================================
-- 2. CREATE VIEWS FOR COMPLEX CALCULATIONS
--    (Rolling averages, cumulative totals need views
--     because they depend on multiple rows)
-- =====================================================

-- Drop existing summary view if exists
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_vatusa_event_summary')
    DROP VIEW dbo.vw_vatusa_event_summary;
GO

-- View: Event summary with calculated aggregates from hourly data
CREATE VIEW dbo.vw_vatusa_event_summary AS
SELECT
    e.event_idx,
    e.event_name,
    e.event_type,
    e.event_code,
    e.start_utc,
    e.end_utc,
    e.duration_hours,
    e.day_of_week,
    e.season,
    e.year_num,
    e.month_num,

    -- Calculated from hourly data
    ISNULL(h.calc_arrivals, 0) AS calc_arrivals,
    ISNULL(h.calc_departures, 0) AS calc_departures,
    ISNULL(h.calc_operations, 0) AS calc_operations,
    ISNULL(h.calc_airport_count, 0) AS calc_airport_count,

    -- Original stored values for comparison
    e.total_arrivals AS stored_arrivals,
    e.total_departures AS stored_departures,
    e.total_operations AS stored_operations,
    e.airport_count AS stored_airport_count,

    -- Real-world comparison
    e.rw_total_arrivals,
    e.rw_total_departures,
    e.rw_total_operations,
    CASE
        WHEN e.rw_total_operations > 0
        THEN CAST(ISNULL(h.calc_operations, 0) * 100.0 / e.rw_total_operations AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_rw,
    e.pct_of_rw_total AS stored_pct_of_rw,

    -- TMR scores
    e.overall_score,
    e.staffing_score,
    e.tactical_score,

    -- Links
    e.tmr_link,
    e.timelapse_link,
    e.simaware_link,
    e.perti_plan_link,

    -- Airports list
    a.airports

FROM dbo.vatusa_event e
LEFT JOIN (
    SELECT
        event_idx,
        SUM(arrivals) AS calc_arrivals,
        SUM(departures) AS calc_departures,
        SUM(ISNULL(arrivals, 0) + ISNULL(departures, 0)) AS calc_operations,
        COUNT(DISTINCT airport_icao) AS calc_airport_count
    FROM dbo.vatusa_event_hourly
    GROUP BY event_idx
) h ON e.event_idx = h.event_idx
LEFT JOIN (
    SELECT
        event_idx,
        STRING_AGG(airport_icao, ', ') WITHIN GROUP (ORDER BY total_operations DESC) AS airports
    FROM dbo.vatusa_event_airport
    GROUP BY event_idx
) a ON e.event_idx = a.event_idx;
GO

PRINT 'Created vw_vatusa_event_summary view with calculated aggregates';
GO

-- =====================================================
-- 3. VIEW: Airport summary with hourly aggregates
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_vatusa_event_airport_stats')
    DROP VIEW dbo.vw_vatusa_event_airport_stats;
GO

CREATE VIEW dbo.vw_vatusa_event_airport_stats AS
SELECT
    a.event_idx,
    a.airport_icao,
    a.is_featured,

    -- Calculated from hourly
    h.calc_arrivals,
    h.calc_departures,
    h.calc_operations,
    h.peak_aar,
    h.peak_hour,
    h.hours_count,

    -- Stored values
    a.total_arrivals AS stored_arrivals,
    a.total_departures AS stored_departures,
    a.total_operations AS stored_operations,
    a.peak_vatsim_aar AS stored_peak_aar,
    a.peak_hour_utc AS stored_peak_hour,

    -- Real-world comparison
    a.rw_total_arrivals,
    a.rw_total_departures,
    CASE
        WHEN a.rw_total_arrivals + a.rw_total_departures > 0
        THEN CAST(h.calc_operations * 100.0 / (a.rw_total_arrivals + a.rw_total_departures) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_rw

FROM dbo.vatusa_event_airport a
LEFT JOIN (
    SELECT
        event_idx,
        airport_icao,
        SUM(arrivals) AS calc_arrivals,
        SUM(departures) AS calc_departures,
        SUM(ISNULL(arrivals, 0) + ISNULL(departures, 0)) AS calc_operations,
        MAX(arrivals) AS peak_aar,
        (SELECT TOP 1 hour_utc
         FROM dbo.vatusa_event_hourly h2
         WHERE h2.event_idx = h1.event_idx
           AND h2.airport_icao = h1.airport_icao
         ORDER BY arrivals DESC) AS peak_hour,
        COUNT(*) AS hours_count
    FROM dbo.vatusa_event_hourly h1
    GROUP BY event_idx, airport_icao
) h ON a.event_idx = h.event_idx AND a.airport_icao = h.airport_icao;
GO

PRINT 'Created vw_vatusa_event_airport_stats view';
GO

-- =====================================================
-- 4. VIEW: Hourly data with rolling calculations
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_vatusa_event_hourly_rolling')
    DROP VIEW dbo.vw_vatusa_event_hourly_rolling;
GO

CREATE VIEW dbo.vw_vatusa_event_hourly_rolling AS
SELECT
    h.id,
    h.event_idx,
    h.airport_icao,
    h.hour_utc,
    h.hour_offset,

    -- Raw values
    h.arrivals,
    h.departures,
    h.throughput,  -- Now computed column

    -- VATSIM rates
    h.vatsim_aar,
    h.vatsim_adr,
    h.vatsim_total,

    -- Real-world rates
    h.rw_aar,
    h.rw_adr,
    h.rw_total,

    -- Calculated percentages
    CASE WHEN h.rw_aar > 0 THEN CAST(h.arrivals * 100.0 / h.rw_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_aar,
    CASE WHEN h.rw_adr > 0 THEN CAST(h.departures * 100.0 / h.rw_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_adr,
    CASE WHEN h.rw_total > 0 THEN CAST(h.throughput * 100.0 / h.rw_total AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_total,

    -- 3-hour rolling average (current + 2 previous hours)
    AVG(CAST(h.arrivals AS DECIMAL(10,2))) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_utc
        ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
    ) AS calc_rolling_arr,

    AVG(CAST(h.departures AS DECIMAL(10,2))) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_utc
        ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
    ) AS calc_rolling_dep,

    AVG(CAST(h.throughput AS DECIMAL(10,2))) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_utc
        ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
    ) AS calc_rolling_throughput,

    -- Cumulative totals for this airport in this event
    SUM(h.arrivals) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_utc
        ROWS UNBOUNDED PRECEDING
    ) AS calc_cumulative_arr,

    SUM(h.departures) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_utc
        ROWS UNBOUNDED PRECEDING
    ) AS calc_cumulative_dep,

    SUM(h.throughput) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_utc
        ROWS UNBOUNDED PRECEDING
    ) AS calc_cumulative_total,

    -- Stored values for comparison
    h.rolling_arr AS stored_rolling_arr,
    h.rolling_dep AS stored_rolling_dep,
    h.rolling_throughput AS stored_rolling_throughput,
    h.event_airport_arr AS stored_cumulative_arr,
    h.event_airport_dep AS stored_cumulative_dep,
    h.event_airport_total AS stored_cumulative_total

FROM dbo.vatusa_event_hourly h;
GO

PRINT 'Created vw_vatusa_event_hourly_rolling view with rolling/cumulative calculations';
GO

-- =====================================================
-- 5. STORED PROCEDURE: Refresh event aggregates
--    Call this after importing new hourly data
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RefreshEventAggregates')
    DROP PROCEDURE dbo.sp_RefreshEventAggregates;
GO

CREATE PROCEDURE dbo.sp_RefreshEventAggregates
    @event_idx VARCHAR(64) = NULL  -- NULL = refresh all
AS
BEGIN
    SET NOCOUNT ON;

    -- Update event totals from hourly data
    UPDATE e
    SET
        total_arrivals = h.total_arr,
        total_departures = h.total_dep,
        total_operations = h.total_ops,
        airport_count = h.apt_count,
        updated_utc = GETUTCDATE()
    FROM dbo.vatusa_event e
    INNER JOIN (
        SELECT
            event_idx,
            SUM(arrivals) AS total_arr,
            SUM(departures) AS total_dep,
            SUM(ISNULL(arrivals, 0) + ISNULL(departures, 0)) AS total_ops,
            COUNT(DISTINCT airport_icao) AS apt_count
        FROM dbo.vatusa_event_hourly
        GROUP BY event_idx
    ) h ON e.event_idx = h.event_idx
    WHERE @event_idx IS NULL OR e.event_idx = @event_idx;

    PRINT 'Updated event aggregates: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' events';

    -- Update airport totals from hourly data
    UPDATE a
    SET
        total_arrivals = h.total_arr,
        total_departures = h.total_dep,
        total_operations = h.total_ops,
        peak_vatsim_aar = h.peak_aar,
        peak_hour_utc = h.peak_hour
    FROM dbo.vatusa_event_airport a
    INNER JOIN (
        SELECT
            event_idx,
            airport_icao,
            SUM(arrivals) AS total_arr,
            SUM(departures) AS total_dep,
            SUM(ISNULL(arrivals, 0) + ISNULL(departures, 0)) AS total_ops,
            MAX(arrivals) AS peak_aar,
            (SELECT TOP 1 hour_utc
             FROM dbo.vatusa_event_hourly h2
             WHERE h2.event_idx = h1.event_idx
               AND h2.airport_icao = h1.airport_icao
             ORDER BY arrivals DESC) AS peak_hour
        FROM dbo.vatusa_event_hourly h1
        GROUP BY event_idx, airport_icao
    ) h ON a.event_idx = h.event_idx AND a.airport_icao = h.airport_icao
    WHERE @event_idx IS NULL OR a.event_idx = @event_idx;

    PRINT 'Updated airport aggregates: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports';
END;
GO

PRINT 'Created sp_RefreshEventAggregates procedure';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '075_vatusa_event_computed_columns.sql completed';
PRINT '';
PRINT 'Changes made:';
PRINT '  1. hourly.throughput - now computed column (arrivals + departures)';
PRINT '';
PRINT 'Views created (use these for calculated values):';
PRINT '  - vw_vatusa_event_summary: Event totals calculated from hourly';
PRINT '  - vw_vatusa_event_airport_stats: Airport totals from hourly';
PRINT '  - vw_vatusa_event_hourly_rolling: Rolling avgs & cumulative totals';
PRINT '';
PRINT 'Procedure created:';
PRINT '  - sp_RefreshEventAggregates: Updates stored aggregates from hourly';
PRINT '';
PRINT 'Usage:';
PRINT '  -- Query with calculated values:';
PRINT '  SELECT * FROM vw_vatusa_event_summary;';
PRINT '  SELECT * FROM vw_vatusa_event_hourly_rolling WHERE event_idx = ''...'';';
PRINT '';
PRINT '  -- Refresh stored aggregates after importing new data:';
PRINT '  EXEC sp_RefreshEventAggregates;';
PRINT '  EXEC sp_RefreshEventAggregates @event_idx = ''202511142359T...'';';
GO
