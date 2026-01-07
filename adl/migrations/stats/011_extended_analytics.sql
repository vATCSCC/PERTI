-- =====================================================
-- VATUSA Event Statistics - Extended Analytics
-- Migration: 078_extended_analytics.sql
-- Purpose: Add comprehensive analytics metrics
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Adding extended analytics metrics...';
GO

-- Drop and recreate the hourly view with ALL metrics
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

    -- =====================================================
    -- RAW VALUES
    -- =====================================================
    h.arrivals,
    h.departures,
    h.throughput,
    h.vatsim_aar,
    h.vatsim_adr,
    h.vatsim_total AS stored_vatsim_total,
    h.rw_aar,
    h.rw_adr,
    h.rw_total AS stored_rw_total,

    -- =====================================================
    -- CALCULATED TOTALS
    -- =====================================================
    CASE WHEN h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL
         THEN h.vatsim_aar + h.vatsim_adr ELSE NULL END AS calc_vatsim_total,
    CASE WHEN h.rw_aar IS NOT NULL AND h.rw_adr IS NOT NULL
         THEN h.rw_aar + h.rw_adr ELSE NULL END AS calc_rw_total,

    -- =====================================================
    -- CATEGORY BUCKETS (VL/L/M/H/VH)
    -- <20=VL, <40=L, <60=M, <100=H, else=VH
    -- =====================================================
    CASE WHEN h.vatsim_aar IS NULL THEN 'U' WHEN h.vatsim_aar < 20 THEN 'VL' WHEN h.vatsim_aar < 40 THEN 'L' WHEN h.vatsim_aar < 60 THEN 'M' WHEN h.vatsim_aar < 100 THEN 'H' ELSE 'VH' END AS calc_vatsim_aar_category,
    CASE WHEN h.vatsim_adr IS NULL THEN 'U' WHEN h.vatsim_adr < 20 THEN 'VL' WHEN h.vatsim_adr < 40 THEN 'L' WHEN h.vatsim_adr < 60 THEN 'M' WHEN h.vatsim_adr < 100 THEN 'H' ELSE 'VH' END AS calc_vatsim_adr_category,
    CASE WHEN h.rw_aar IS NULL THEN 'U' WHEN h.rw_aar < 20 THEN 'VL' WHEN h.rw_aar < 40 THEN 'L' WHEN h.rw_aar < 60 THEN 'M' WHEN h.rw_aar < 100 THEN 'H' ELSE 'VH' END AS calc_rw_aar_category,
    CASE WHEN h.rw_adr IS NULL THEN 'U' WHEN h.rw_adr < 20 THEN 'VL' WHEN h.rw_adr < 40 THEN 'L' WHEN h.rw_adr < 60 THEN 'M' WHEN h.rw_adr < 100 THEN 'H' ELSE 'VH' END AS calc_rw_adr_category,

    -- Traffic intensity category
    CASE WHEN h.throughput IS NULL THEN 'U' WHEN h.throughput < 20 THEN 'Light' WHEN h.throughput < 50 THEN 'Moderate' WHEN h.throughput < 100 THEN 'Heavy' ELSE 'Extreme' END AS calc_traffic_intensity,

    -- =====================================================
    -- PERCENTAGE OF CAPACITY
    -- =====================================================
    CASE WHEN h.vatsim_aar > 0 THEN CAST(h.arrivals * 100.0 / h.vatsim_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_aar,
    CASE WHEN h.vatsim_adr > 0 THEN CAST(h.departures * 100.0 / h.vatsim_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_adr,
    CASE WHEN h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL AND (h.vatsim_aar + h.vatsim_adr) > 0
         THEN CAST(h.throughput * 100.0 / (h.vatsim_aar + h.vatsim_adr) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_total,
    CASE WHEN h.rw_aar > 0 THEN CAST(h.arrivals * 100.0 / h.rw_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_aar,
    CASE WHEN h.rw_adr > 0 THEN CAST(h.departures * 100.0 / h.rw_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_adr,
    CASE WHEN h.rw_aar IS NOT NULL AND h.rw_adr IS NOT NULL AND (h.rw_aar + h.rw_adr) > 0
         THEN CAST(h.throughput * 100.0 / (h.rw_aar + h.rw_adr) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_total,

    -- Stored percentages
    h.pct_vatsim_aar AS stored_pct_vatsim_aar,
    h.pct_vatsim_adr AS stored_pct_vatsim_adr,
    h.pct_vatsim_total AS stored_pct_vatsim_total,
    h.pct_rw_aar AS stored_pct_rw_aar,
    h.pct_rw_adr AS stored_pct_rw_adr,
    h.pct_rw_total AS stored_pct_rw_total,

    -- =====================================================
    -- DIFFERENCES (RW - VATSIM)
    -- =====================================================
    CASE WHEN h.rw_aar IS NOT NULL AND h.vatsim_aar IS NOT NULL THEN h.rw_aar - h.vatsim_aar ELSE NULL END AS calc_diff_aar,
    CASE WHEN h.rw_adr IS NOT NULL AND h.vatsim_adr IS NOT NULL THEN h.rw_adr - h.vatsim_adr ELSE NULL END AS calc_diff_adr,
    CASE WHEN h.rw_aar IS NOT NULL AND h.rw_adr IS NOT NULL AND h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL
         THEN (h.rw_aar + h.rw_adr) - (h.vatsim_aar + h.vatsim_adr) ELSE NULL END AS calc_diff_total,

    -- =====================================================
    -- CAPACITY HEADROOM (unused capacity)
    -- =====================================================
    CASE WHEN h.vatsim_aar IS NOT NULL THEN h.vatsim_aar - h.arrivals ELSE NULL END AS calc_headroom_arr,
    CASE WHEN h.vatsim_adr IS NOT NULL THEN h.vatsim_adr - h.departures ELSE NULL END AS calc_headroom_dep,
    CASE WHEN h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL
         THEN (h.vatsim_aar + h.vatsim_adr) - h.throughput ELSE NULL END AS calc_headroom_total,

    -- Capacity flags
    CASE WHEN h.vatsim_aar > 0 AND h.arrivals >= h.vatsim_aar * 0.9 THEN 1 ELSE 0 END AS is_at_capacity_arr,
    CASE WHEN h.vatsim_adr > 0 AND h.departures >= h.vatsim_adr * 0.9 THEN 1 ELSE 0 END AS is_at_capacity_dep,
    CASE WHEN h.arrivals > ISNULL(h.vatsim_aar, 999999) THEN 1 ELSE 0 END AS is_overflow_arr,
    CASE WHEN h.departures > ISNULL(h.vatsim_adr, 999999) THEN 1 ELSE 0 END AS is_overflow_dep,

    -- Utilization bands
    CASE
        WHEN h.vatsim_aar IS NULL OR h.vatsim_aar = 0 THEN 'N/A'
        WHEN h.arrivals * 100.0 / h.vatsim_aar > 100 THEN '>100%'
        WHEN h.arrivals * 100.0 / h.vatsim_aar >= 75 THEN '75-100%'
        WHEN h.arrivals * 100.0 / h.vatsim_aar >= 50 THEN '50-75%'
        WHEN h.arrivals * 100.0 / h.vatsim_aar >= 25 THEN '25-50%'
        ELSE '0-25%'
    END AS calc_utilization_band_arr,

    CASE
        WHEN h.vatsim_adr IS NULL OR h.vatsim_adr = 0 THEN 'N/A'
        WHEN h.departures * 100.0 / h.vatsim_adr > 100 THEN '>100%'
        WHEN h.departures * 100.0 / h.vatsim_adr >= 75 THEN '75-100%'
        WHEN h.departures * 100.0 / h.vatsim_adr >= 50 THEN '50-75%'
        WHEN h.departures * 100.0 / h.vatsim_adr >= 25 THEN '25-50%'
        ELSE '0-25%'
    END AS calc_utilization_band_dep,

    -- =====================================================
    -- CUMULATIVE / ROLLING (running sum)
    -- =====================================================
    SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) AS calc_rolling_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) AS calc_rolling_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) AS calc_rolling_throughput,

    h.rolling_arr AS stored_rolling_arr,
    h.rolling_dep AS stored_rolling_dep,
    h.rolling_throughput AS stored_rolling_throughput,

    -- =====================================================
    -- EVENT AIRPORT TOTALS (SUMIFS equivalent)
    -- =====================================================
    SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_total,

    h.event_airport_arr AS stored_event_airport_arr,
    h.event_airport_dep AS stored_event_airport_dep,
    h.event_airport_total AS stored_event_airport_total,

    -- =====================================================
    -- EVENT TOTALS (all airports)
    -- =====================================================
    SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS calc_event_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx) AS calc_event_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS calc_event_total,

    -- =====================================================
    -- % OF AIRPORT (this hour / airport total)
    -- =====================================================
    CASE WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
         THEN CAST(h.arrivals * 100.0 / SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_of_airport_arr,
    CASE WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
         THEN CAST(h.departures * 100.0 / SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_of_airport_dep,
    CASE WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
         THEN CAST(h.throughput * 100.0 / SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_of_airport_throughput,

    -- =====================================================
    -- % OF EVENT (this hour / event total)
    -- =====================================================
    CASE WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx) > 0
         THEN CAST(h.arrivals * 100.0 / SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_of_event_arr,
    CASE WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx) > 0
         THEN CAST(h.departures * 100.0 / SUM(h.departures) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_of_event_dep,
    CASE WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx) > 0
         THEN CAST(h.throughput * 100.0 / SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_of_event_throughput,

    -- =====================================================
    -- AIRPORT SHARE OF EVENT
    -- =====================================================
    CASE WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx) > 0
         THEN CAST(SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) * 100.0 / SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2)) ELSE NULL END AS calc_airport_share_arr,
    CASE WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx) > 0
         THEN CAST(SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) * 100.0 / SUM(h.departures) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2)) ELSE NULL END AS calc_airport_share_dep,
    CASE WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx) > 0
         THEN CAST(SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) * 100.0 / SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2)) ELSE NULL END AS calc_airport_share_total,

    -- =====================================================
    -- ROLLING PERCENTAGE (cumulative / total)
    -- =====================================================
    CASE WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
         THEN CAST(SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) * 100.0
                   / SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2)) ELSE NULL END AS calc_rolling_pct_arr,
    CASE WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
         THEN CAST(SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) * 100.0
                   / SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2)) ELSE NULL END AS calc_rolling_pct_dep,
    CASE WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
         THEN CAST(SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) * 100.0
                   / SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2)) ELSE NULL END AS calc_rolling_pct_throughput,

    -- =====================================================
    -- HOUR-OVER-HOUR DELTA
    -- =====================================================
    h.arrivals - LAG(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_arrivals,
    h.departures - LAG(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_departures,
    h.throughput - LAG(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_throughput,
    h.vatsim_aar - LAG(h.vatsim_aar) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_vatsim_aar,
    h.vatsim_adr - LAG(h.vatsim_adr) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_vatsim_adr,
    h.rw_aar - LAG(h.rw_aar) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_rw_aar,
    h.rw_adr - LAG(h.rw_adr) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) AS delta_rw_adr,

    -- =====================================================
    -- PEAK HOUR FLAGS
    -- =====================================================
    CASE WHEN h.arrivals = MAX(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) THEN 1 ELSE 0 END AS is_peak_arr_hour,
    CASE WHEN h.departures = MAX(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) THEN 1 ELSE 0 END AS is_peak_dep_hour,
    CASE WHEN h.throughput = MAX(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) THEN 1 ELSE 0 END AS is_peak_throughput_hour,

    -- Peak values for reference
    MAX(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS peak_arrivals,
    MAX(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS peak_departures,
    MAX(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS peak_throughput,

    -- =====================================================
    -- HOURLY RANK (within airport & event)
    -- =====================================================
    RANK() OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.arrivals DESC) AS rank_arr,
    RANK() OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.departures DESC) AS rank_dep,
    RANK() OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.throughput DESC) AS rank_throughput,

    -- =====================================================
    -- AIRPORT RANK THIS HOUR (across all airports)
    -- =====================================================
    RANK() OVER (PARTITION BY h.event_idx, h.hour_offset ORDER BY h.arrivals DESC) AS airport_rank_arr_this_hour,
    RANK() OVER (PARTITION BY h.event_idx, h.hour_offset ORDER BY h.departures DESC) AS airport_rank_dep_this_hour,
    RANK() OVER (PARTITION BY h.event_idx, h.hour_offset ORDER BY h.throughput DESC) AS airport_rank_throughput_this_hour,

    -- =====================================================
    -- HOURLY AVERAGES
    -- =====================================================
    CAST(AVG(h.arrivals * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_arr,
    CAST(AVG(h.departures * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_dep,
    CAST(AVG(h.throughput * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_throughput,
    CAST(AVG(h.vatsim_aar * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_vatsim_aar,
    CAST(AVG(h.vatsim_adr * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_vatsim_adr,
    CAST(AVG(h.rw_aar * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_rw_aar,
    CAST(AVG(h.rw_adr * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(8,2)) AS avg_hourly_rw_adr,

    -- =====================================================
    -- VARIANCE FROM AVERAGE
    -- =====================================================
    h.arrivals - CAST(AVG(h.arrivals * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS INT) AS variance_from_avg_arr,
    h.departures - CAST(AVG(h.departures * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS INT) AS variance_from_avg_dep,
    h.throughput - CAST(AVG(h.throughput * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao) AS INT) AS variance_from_avg_throughput,

    -- =====================================================
    -- 3-HOUR MOVING AVERAGE (true rolling average)
    -- =====================================================
    CAST(AVG(h.arrivals * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS BETWEEN 2 PRECEDING AND CURRENT ROW) AS DECIMAL(8,2)) AS moving_avg_3hr_arr,
    CAST(AVG(h.departures * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS BETWEEN 2 PRECEDING AND CURRENT ROW) AS DECIMAL(8,2)) AS moving_avg_3hr_dep,
    CAST(AVG(h.throughput * 1.0) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS BETWEEN 2 PRECEDING AND CURRENT ROW) AS DECIMAL(8,2)) AS moving_avg_3hr_throughput,

    -- =====================================================
    -- TREND DIRECTION
    -- =====================================================
    CASE
        WHEN h.arrivals - LAG(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) > 5 THEN 'Rising'
        WHEN h.arrivals - LAG(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) < -5 THEN 'Falling'
        ELSE 'Stable'
    END AS trend_arr,
    CASE
        WHEN h.departures - LAG(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) > 5 THEN 'Rising'
        WHEN h.departures - LAG(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) < -5 THEN 'Falling'
        ELSE 'Stable'
    END AS trend_dep,
    CASE
        WHEN h.throughput - LAG(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) > 10 THEN 'Rising'
        WHEN h.throughput - LAG(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset) < -10 THEN 'Falling'
        ELSE 'Stable'
    END AS trend_throughput,

    -- =====================================================
    -- ARRIVAL/DEPARTURE BALANCE
    -- =====================================================
    CASE WHEN h.departures > 0 THEN CAST(h.arrivals * 1.0 / h.departures AS DECIMAL(6,2)) ELSE NULL END AS arr_dep_ratio,
    ABS(h.arrivals - h.departures) AS arr_dep_imbalance,
    CASE
        WHEN h.arrivals > h.departures * 1.25 THEN 'Arrival-Heavy'
        WHEN h.departures > h.arrivals * 1.25 THEN 'Departure-Heavy'
        ELSE 'Balanced'
    END AS balance_category,

    -- =====================================================
    -- TIME POSITION METRICS
    -- =====================================================
    CASE
        WHEN h.hour_offset = MIN(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) THEN 1
        ELSE 0
    END AS is_first_hour,
    CASE
        WHEN h.hour_offset = MAX(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) THEN 1
        ELSE 0
    END AS is_last_hour,

    -- Event phase
    CASE
        WHEN h.hour_offset <= MIN(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) + 1 THEN 'Ramp-up'
        WHEN h.hour_offset >= MAX(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) - 1 THEN 'Wind-down'
        ELSE 'Core'
    END AS event_phase,

    -- Progress through event (%)
    CASE
        WHEN MAX(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) - MIN(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST((h.hour_offset - MIN(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao)) * 100.0
                  / (MAX(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao) - MIN(h.hour_offset) OVER (PARTITION BY h.event_idx, h.airport_icao))
                  AS DECIMAL(6,2))
        ELSE 100.00
    END AS pct_event_complete,

    -- Hour count
    COUNT(*) OVER (PARTITION BY h.event_idx, h.airport_icao) AS total_hours_in_event

FROM dbo.vatusa_event_hourly h;
GO

PRINT 'Created vw_vatusa_event_hourly_rolling with extended analytics';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '078_extended_analytics.sql completed';
PRINT '';
PRINT 'NEW METRICS ADDED:';
PRINT '';
PRINT 'Categories:';
PRINT '  - calc_vatsim_aar_category, calc_vatsim_adr_category';
PRINT '  - calc_rw_aar_category, calc_rw_adr_category';
PRINT '  - calc_traffic_intensity (Light/Moderate/Heavy/Extreme)';
PRINT '';
PRINT 'Capacity:';
PRINT '  - calc_headroom_arr/dep/total (unused capacity)';
PRINT '  - is_at_capacity_arr/dep (>=90% utilization)';
PRINT '  - is_overflow_arr/dep (exceeded capacity)';
PRINT '  - calc_utilization_band_arr/dep (0-25%, 25-50%, etc.)';
PRINT '';
PRINT 'Deltas:';
PRINT '  - delta_arrivals/departures/throughput (hour-over-hour)';
PRINT '  - delta_vatsim_aar/adr, delta_rw_aar/adr';
PRINT '';
PRINT 'Peaks & Ranks:';
PRINT '  - is_peak_arr/dep/throughput_hour';
PRINT '  - peak_arrivals/departures/throughput';
PRINT '  - rank_arr/dep/throughput (within airport)';
PRINT '  - airport_rank_arr/dep/throughput_this_hour (across airports)';
PRINT '';
PRINT 'Averages:';
PRINT '  - avg_hourly_arr/dep/throughput/vatsim_aar/adr/rw_aar/adr';
PRINT '  - variance_from_avg_arr/dep/throughput';
PRINT '  - moving_avg_3hr_arr/dep/throughput';
PRINT '';
PRINT 'Trends:';
PRINT '  - trend_arr/dep/throughput (Rising/Stable/Falling)';
PRINT '';
PRINT 'Balance:';
PRINT '  - arr_dep_ratio, arr_dep_imbalance';
PRINT '  - balance_category (Arrival-Heavy/Balanced/Departure-Heavy)';
PRINT '';
PRINT 'Time Position:';
PRINT '  - is_first_hour, is_last_hour';
PRINT '  - event_phase (Ramp-up/Core/Wind-down)';
PRINT '  - pct_event_complete';
PRINT '  - total_hours_in_event';
GO
