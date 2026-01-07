-- =====================================================
-- VATUSA Event Statistics - Complete Computed Formulas
-- Migration: 077_complete_computed_formulas.sql
-- Purpose: Add ALL missing Excel formula calculations
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Adding all missing Excel formula calculations...';
GO

-- Drop and recreate the hourly view with ALL formulas
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
    -- RAW VALUES (from table)
    -- =====================================================
    h.arrivals,
    h.departures,
    h.throughput,  -- Computed column: arrivals + departures

    -- VATSIM rates (capacity)
    h.vatsim_aar,
    h.vatsim_adr,
    h.vatsim_total AS stored_vatsim_total,

    -- Real-world rates
    h.rw_aar,
    h.rw_adr,
    h.rw_total AS stored_rw_total,

    -- =====================================================
    -- CALCULATED TOTALS (Excel cols 19, 22)
    -- Excel: =IF(OR(ISBLANK([AAR]),ISBLANK([ADR])),"",AAR+ADR)
    -- =====================================================
    CASE
        WHEN h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL
        THEN h.vatsim_aar + h.vatsim_adr
        ELSE NULL
    END AS calc_vatsim_total,

    CASE
        WHEN h.rw_aar IS NOT NULL AND h.rw_adr IS NOT NULL
        THEN h.rw_aar + h.rw_adr
        ELSE NULL
    END AS calc_rw_total,

    -- =====================================================
    -- CATEGORY BUCKETS (Excel cols 23, 24)
    -- Excel: =IF(ISBLANK([AAR]),"U",IF([AAR]<20,"VL",IF([AAR]<40,"L",IF([AAR]<60,"M",IF([AAR]<100,"H","VH")))))
    -- =====================================================
    CASE
        WHEN h.vatsim_aar IS NULL THEN 'U'
        WHEN h.vatsim_aar < 20 THEN 'VL'
        WHEN h.vatsim_aar < 40 THEN 'L'
        WHEN h.vatsim_aar < 60 THEN 'M'
        WHEN h.vatsim_aar < 100 THEN 'H'
        ELSE 'VH'
    END AS calc_vatsim_aar_category,

    CASE
        WHEN h.rw_aar IS NULL THEN 'U'
        WHEN h.rw_aar < 20 THEN 'VL'
        WHEN h.rw_aar < 40 THEN 'L'
        WHEN h.rw_aar < 60 THEN 'M'
        WHEN h.rw_aar < 100 THEN 'H'
        ELSE 'VH'
    END AS calc_rw_aar_category,

    -- =====================================================
    -- PERCENTAGE OF CAPACITY (Excel cols 25-30)
    -- Excel: =Arrivals/[VATSIM AAR] (returns ratio)
    -- We multiply by 100 for percentage display
    -- =====================================================
    CASE WHEN h.vatsim_aar > 0 THEN CAST(h.arrivals * 100.0 / h.vatsim_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_aar,
    CASE WHEN h.vatsim_adr > 0 THEN CAST(h.departures * 100.0 / h.vatsim_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_adr,
    CASE WHEN h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL AND (h.vatsim_aar + h.vatsim_adr) > 0
         THEN CAST(h.throughput * 100.0 / (h.vatsim_aar + h.vatsim_adr) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_vatsim_total,

    CASE WHEN h.rw_aar > 0 THEN CAST(h.arrivals * 100.0 / h.rw_aar AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_aar,
    CASE WHEN h.rw_adr > 0 THEN CAST(h.departures * 100.0 / h.rw_adr AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_adr,
    CASE WHEN h.rw_aar IS NOT NULL AND h.rw_adr IS NOT NULL AND (h.rw_aar + h.rw_adr) > 0
         THEN CAST(h.throughput * 100.0 / (h.rw_aar + h.rw_adr) AS DECIMAL(6,2)) ELSE NULL END AS calc_pct_rw_total,

    -- Stored percentage values from Excel
    h.pct_vatsim_aar AS stored_pct_vatsim_aar,
    h.pct_vatsim_adr AS stored_pct_vatsim_adr,
    h.pct_vatsim_total AS stored_pct_vatsim_total,
    h.pct_rw_aar AS stored_pct_rw_aar,
    h.pct_rw_adr AS stored_pct_rw_adr,
    h.pct_rw_total AS stored_pct_rw_total,

    -- =====================================================
    -- DIFFERENCE COLUMNS (Excel cols 31-33)
    -- Excel: =RW_AAR - VATSIM_AAR
    -- =====================================================
    CASE
        WHEN h.rw_aar IS NOT NULL AND h.vatsim_aar IS NOT NULL
        THEN h.rw_aar - h.vatsim_aar
        ELSE NULL
    END AS calc_diff_aar,

    CASE
        WHEN h.rw_adr IS NOT NULL AND h.vatsim_adr IS NOT NULL
        THEN h.rw_adr - h.vatsim_adr
        ELSE NULL
    END AS calc_diff_adr,

    CASE
        WHEN h.rw_aar IS NOT NULL AND h.rw_adr IS NOT NULL
             AND h.vatsim_aar IS NOT NULL AND h.vatsim_adr IS NOT NULL
        THEN (h.rw_aar + h.rw_adr) - (h.vatsim_aar + h.vatsim_adr)
        ELSE NULL
    END AS calc_diff_total,

    -- =====================================================
    -- "ROLLING" CALCULATIONS (Excel cols 43-45: cumulative sum)
    -- ORDER BY hour_offset for correct chronological order
    -- =====================================================
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
    -- EVENT AIRPORT TOTALS (Excel cols 49-51: SUMIFS)
    -- Static total for this airport in this event
    -- =====================================================
    SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS calc_event_airport_total,

    -- Stored event airport totals from Excel
    h.event_airport_arr AS stored_event_airport_arr,
    h.event_airport_dep AS stored_event_airport_dep,
    h.event_airport_total AS stored_event_airport_total,

    -- =====================================================
    -- EVENT TOTALS (Excel cols 52-54: SUMIFS all airports)
    -- =====================================================
    SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS calc_event_arr,
    SUM(h.departures) OVER (PARTITION BY h.event_idx) AS calc_event_dep,
    SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS calc_event_total,

    -- =====================================================
    -- % OF AIRPORT (HOURLY) (Excel cols 34-36)
    -- Excel: =Arrivals/[Event Airport Arrivals]
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
        WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST(h.throughput * 100.0 / SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_airport_throughput,

    -- =====================================================
    -- % OF TOTAL (HOURLY) (Excel cols 37-39)
    -- Excel: =Arrivals/[Event Arrivals]
    -- =====================================================
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

    CASE
        WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx) > 0
        THEN CAST(h.throughput * 100.0 / SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_pct_of_event_throughput,

    -- =====================================================
    -- % OF TOTAL (AIRPORT) (Excel cols 40-42)
    -- Excel: =[Event Airport Arrivals]/[Event Arrivals]
    -- This is airport's share of entire event
    -- =====================================================
    CASE
        WHEN SUM(h.arrivals) OVER (PARTITION BY h.event_idx) > 0
        THEN CAST(SUM(h.arrivals) OVER (PARTITION BY h.event_idx, h.airport_icao) * 100.0
                  / SUM(h.arrivals) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_airport_share_arr,

    CASE
        WHEN SUM(h.departures) OVER (PARTITION BY h.event_idx) > 0
        THEN CAST(SUM(h.departures) OVER (PARTITION BY h.event_idx, h.airport_icao) * 100.0
                  / SUM(h.departures) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_airport_share_dep,

    CASE
        WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx) > 0
        THEN CAST(SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) * 100.0
                  / SUM(h.throughput) OVER (PARTITION BY h.event_idx) AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_airport_share_total,

    -- =====================================================
    -- ROLLING PERCENTAGE (Excel cols 46-48)
    -- Excel: =[Rolling Airport Arrivals]/[Event Airport Arrivals]
    -- =====================================================
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
    END AS calc_rolling_pct_dep,

    CASE
        WHEN SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao) > 0
        THEN CAST(
            SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao ORDER BY h.hour_offset ROWS UNBOUNDED PRECEDING) * 100.0
            / SUM(h.throughput) OVER (PARTITION BY h.event_idx, h.airport_icao)
            AS DECIMAL(6,2))
        ELSE NULL
    END AS calc_rolling_pct_throughput

FROM dbo.vatusa_event_hourly h;
GO

PRINT 'Created vw_vatusa_event_hourly_rolling with ALL Excel formulas';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '077_complete_computed_formulas.sql completed';
PRINT '';
PRINT 'All 44 Excel formula columns now have SQL equivalents:';
PRINT '';
PRINT 'NEW calculations added:';
PRINT '  - calc_vatsim_total, calc_rw_total (AAR + ADR)';
PRINT '  - calc_vatsim_aar_category, calc_rw_aar_category (VL/L/M/H/VH)';
PRINT '  - calc_diff_aar, calc_diff_adr, calc_diff_total (RW - VATSIM)';
PRINT '  - calc_pct_of_airport_throughput (Throughput / Event Airport Total)';
PRINT '  - calc_pct_of_event_throughput (Throughput / Event Total)';
PRINT '  - calc_airport_share_arr/dep/total (Airport share of event)';
PRINT '  - calc_rolling_pct_throughput (Rolling / Event Airport Total)';
PRINT '';
PRINT 'EXISTING calculations retained:';
PRINT '  - calc_rolling_arr/dep/throughput (cumulative sum)';
PRINT '  - calc_event_airport_arr/dep/total (SUMIFS)';
PRINT '  - calc_event_arr/dep/total (SUMIFS all airports)';
PRINT '  - calc_pct_vatsim_aar/adr/total';
PRINT '  - calc_pct_rw_aar/adr/total';
PRINT '  - calc_pct_of_airport_arr/dep';
PRINT '  - calc_pct_of_event_arr/dep';
PRINT '  - calc_rolling_pct_arr/dep';
GO
