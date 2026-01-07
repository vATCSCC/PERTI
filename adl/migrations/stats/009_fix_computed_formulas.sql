-- =====================================================
-- VATUSA Event Statistics - Fix Computed Formulas
-- Migration: 076_fix_computed_formulas.sql
-- Purpose: Correct the view formulas to match actual Excel
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Fixing computed column formulas to match Excel...';
GO

-- =====================================================
-- KEY FINDINGS FROM EXCEL FORMULAS:
--
-- 1. Throughput = Departures + Arrivals  ✓ (already correct)
--
-- 2. "Rolling Airport Arrivals" in Excel is actually a
--    CUMULATIVE SUM (running total), NOT a rolling average
--    Formula: =IF(same event+airport as prev row, Arrivals + prev_rolling, Arrivals)
--
-- 3. "Event Airport Arrivals" = SUMIFS total for that airport in that event
--    (static value, same for all rows of same event+airport)
--
-- 4. Percentage formulas:
--    % VATSIM AAR = Arrivals / VATSIM_AAR  (not throughput)
--    % VATSIM ADR = Departures / VATSIM_ADR
--    % VATSIM Total = Throughput / VATSIM_Total
--    % RW AAR = Arrivals / RW_AAR
--    % RW ADR = Departures / RW_ADR
--    % RW Total = Throughput / RW_Total
-- =====================================================

-- Drop and recreate the hourly view with correct formulas
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
    h.throughput,  -- Computed column: arrivals + departures

    -- VATSIM rates (capacity)
    h.vatsim_aar,
    h.vatsim_adr,
    h.vatsim_total,

    -- Real-world rates
    h.rw_aar,
    h.rw_adr,
    h.rw_total,

    -- =====================================================
    -- PERCENTAGE CALCULATIONS (matching Excel formulas)
    -- Excel: =Arrivals/[VATSIM AAR] (returns ratio, not percent)
    -- We multiply by 100 for display as percentage
    -- =====================================================

    -- % of VATSIM capacity
    CASE WHEN h.vatsim_aar > 0 THEN CAST(h.arrivals * 100.0 / h.vatsim_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_aar,
    CASE WHEN h.vatsim_adr > 0 THEN CAST(h.departures * 100.0 / h.vatsim_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_adr,
    CASE WHEN h.vatsim_total > 0 THEN CAST(h.throughput * 100.0 / h.vatsim_total AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_total,

    -- % of real-world traffic
    CASE WHEN h.rw_aar > 0 THEN CAST(h.arrivals * 100.0 / h.rw_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_aar,
    CASE WHEN h.rw_adr > 0 THEN CAST(h.departures * 100.0 / h.rw_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_adr,
    CASE WHEN h.rw_total > 0 THEN CAST(h.throughput * 100.0 / h.rw_total AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_total,

    -- Stored percentage values from Excel (for comparison)
    h.pct_vatsim_aar AS stored_pct_vatsim_aar,
    h.pct_vatsim_adr AS stored_pct_vatsim_adr,
    h.pct_vatsim_total AS stored_pct_vatsim_total,
    h.pct_rw_aar AS stored_pct_rw_aar,
    h.pct_rw_adr AS stored_pct_rw_adr,
    h.pct_rw_total AS stored_pct_rw_total,

    -- =====================================================
    -- "ROLLING" CALCULATIONS (Excel: cumulative running sum)
    -- Excel formula: =IF(same event+airport, Arrivals + prev_row_rolling, Arrivals)
    -- This is a CUMULATIVE SUM, not a rolling average
    -- =====================================================

    -- NOTE: ORDER BY hour_offset (not hour_utc) for correct chronological order
    -- hour_offset: -1=2300Z, 0=0000Z, 1=0100Z, etc.
    SUM(h.arrivals) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_offset
        ROWS UNBOUNDED PRECEDING
    ) AS calc_rolling_arr,

    SUM(h.departures) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_offset
        ROWS UNBOUNDED PRECEDING
    ) AS calc_rolling_dep,

    SUM(h.throughput) OVER (
        PARTITION BY h.event_idx, h.airport_icao
        ORDER BY h.hour_offset
        ROWS UNBOUNDED PRECEDING
    ) AS calc_rolling_throughput,

    -- Stored rolling values from Excel
    h.rolling_arr AS stored_rolling_arr,
    h.rolling_dep AS stored_rolling_dep,
    h.rolling_throughput AS stored_rolling_throughput,

    -- =====================================================
    -- "EVENT AIRPORT" TOTALS (Excel: SUMIFS for entire event/airport)
    -- Excel: =SUMIFS(Arrivals, Index, [Index], Airport, [Airport])
    -- Static total for this airport in this event (same for all hours)
    -- =====================================================

    SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_total,

    -- Stored event airport totals from Excel
    h.event_airport_arr AS stored_event_airport_arr,
    h.event_airport_dep AS stored_event_airport_dep,
    h.event_airport_total AS stored_event_airport_total,

    -- =====================================================
    -- "EVENT" TOTALS (Excel: SUMIFS for entire event, all airports)
    -- Excel: =SUMIFS(Arrivals, Index, [Index])
    -- =====================================================

    SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS calc_event_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx) AS calc_event_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS calc_event_total,

    -- =====================================================
    -- PERCENTAGE OF AIRPORT/EVENT (Excel formulas)
    -- % of Airport Arrivals (Hourly) = Arrivals / Event Airport Arrivals
    -- % of Total Arrivals (Hourly) = Arrivals / Event Arrivals
    -- =====================================================

    CASE
        WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST(h.arrivals * 100.0 / SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_airport_arr,

    CASE
        WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST(h.departures * 100.0 / SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_airport_dep,

    CASE
        WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx) > 0
        THEN CAST(h.arrivals * 100.0 / SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_event_arr,

    CASE
        WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx) > 0
        THEN CAST(h.departures * 100.0 / SUM(h.departures) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_event_dep,

    -- Rolling percentage (cumulative / total)
    -- Excel: Rolling Airport Arrivals (%) = Rolling Airport Arrivals / Event Airport Arrivals
    CASE
        WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST(
            SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) * 100.0
            / SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao)
            AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_rolling_pct_arr,

    CASE
        WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST(
            SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) * 100.0
            / SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao)
            AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_rolling_pct_dep

FROM dbo.vatusa_event_hourly h;
GO

PRINT 'Recreated vw_vatusa_event_hourly_rolling with correct Excel formulas';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '076_fix_computed_formulas.sql completed';
PRINT '';
PRINT 'Fixes applied:';
PRINT '  1. calc_rolling_* now matches Excel (cumulative sum, not avg)';
PRINT '  2. calc_event_airport_* = SUMIFS total for airport in event';
PRINT '  3. calc_event_* = SUMIFS total for entire event';
PRINT '  4. Percentage columns now match Excel formulas';
PRINT '';
PRINT 'Excel formula reference:';
PRINT '  - Throughput = Arrivals + Departures';
PRINT '  - Rolling Airport Arrivals = cumulative sum (running total)';
PRINT '  - Event Airport Arrivals = SUMIFS(Arrivals, Index, [Index], Airport, [Airport])';
PRINT '  - % VATSIM AAR = Arrivals / VATSIM_AAR';
PRINT '  - Rolling Airport Arrivals (%) = Rolling / Event Airport Total';
GO
