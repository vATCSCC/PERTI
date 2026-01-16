-- ============================================================================
-- ETA Accuracy Analysis: Wind Adjustment Impact
--
-- Compares ETA prediction accuracy for flights WITH vs WITHOUT wind adjustments
-- Run this against VATSIM_ADL database
-- ============================================================================

SET NOCOUNT ON;

PRINT '============================================================================';
PRINT '  ETA ACCURACY ANALYSIS - WIND ADJUSTMENT IMPACT';
PRINT '  Generated: ' + CONVERT(VARCHAR, GETUTCDATE(), 120) + ' UTC';
PRINT '============================================================================';
PRINT '';

-- ============================================================================
-- 1. SAMPLE SIZE AND DATA AVAILABILITY
-- ============================================================================

PRINT '=== 1. DATA AVAILABILITY ===';
PRINT '';

-- Check how many flights have actual arrival times (completed flights)
DECLARE @total_flights INT, @with_ata INT, @with_wind INT, @with_both INT;

SELECT @total_flights = COUNT(*) FROM adl_flight_times;

SELECT @with_ata = COUNT(*)
FROM adl_flight_times
WHERE ata_utc IS NOT NULL
  OR ata_runway_utc IS NOT NULL;

SELECT @with_wind = COUNT(*)
FROM adl_flight_times
WHERE eta_wind_adj_kts IS NOT NULL
  AND ABS(eta_wind_adj_kts) > 0;

SELECT @with_both = COUNT(*)
FROM adl_flight_times
WHERE (ata_utc IS NOT NULL OR ata_runway_utc IS NOT NULL)
  AND eta_wind_adj_kts IS NOT NULL;

PRINT 'Total flights in adl_flight_times: ' + CAST(@total_flights AS VARCHAR);
PRINT 'Flights with actual arrival time:  ' + CAST(@with_ata AS VARCHAR);
PRINT 'Flights with wind adjustment:      ' + CAST(@with_wind AS VARCHAR);
PRINT 'Flights with both (analyzable):    ' + CAST(@with_both AS VARCHAR);
PRINT '';

-- ============================================================================
-- 2. CURRENT WIND ADJUSTMENT DISTRIBUTION
-- ============================================================================

PRINT '=== 2. WIND ADJUSTMENT DISTRIBUTION (Active Flights) ===';
PRINT '';

SELECT
    'Wind Adjustment Stats' AS metric,
    COUNT(*) AS total_flights,
    SUM(CASE WHEN ABS(ISNULL(eta_wind_adj_kts, 0)) > 5 THEN 1 ELSE 0 END) AS with_significant_wind,
    AVG(eta_wind_adj_kts) AS avg_wind_adj_kts,
    MIN(eta_wind_adj_kts) AS min_wind_adj_kts,
    MAX(eta_wind_adj_kts) AS max_wind_adj_kts,
    STDEV(eta_wind_adj_kts) AS stdev_wind_adj,
    AVG(eta_wind_confidence) AS avg_confidence
FROM adl_flight_times ft
JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
WHERE c.is_active = 1
  AND eta_wind_adj_kts IS NOT NULL;

-- Wind adjustment by tier
PRINT '';
PRINT 'Wind adjustment by confidence level:';

SELECT
    CASE
        WHEN eta_wind_confidence >= 0.90 THEN 'High (0.90+) - Grid-based'
        WHEN eta_wind_confidence >= 0.60 THEN 'Medium (0.60-0.89) - GS-based cruise'
        WHEN eta_wind_confidence >= 0.40 THEN 'Low (0.40-0.59) - GS-based other'
        ELSE 'None/Unknown'
    END AS confidence_tier,
    COUNT(*) AS flight_count,
    AVG(eta_wind_adj_kts) AS avg_wind_adj,
    AVG(ABS(eta_wind_adj_kts)) AS avg_abs_wind_adj,
    MIN(eta_wind_adj_kts) AS min_wind,
    MAX(eta_wind_adj_kts) AS max_wind
FROM adl_flight_times ft
JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
WHERE c.is_active = 1
  AND eta_wind_adj_kts IS NOT NULL
GROUP BY
    CASE
        WHEN eta_wind_confidence >= 0.90 THEN 'High (0.90+) - Grid-based'
        WHEN eta_wind_confidence >= 0.60 THEN 'Medium (0.60-0.89) - GS-based cruise'
        WHEN eta_wind_confidence >= 0.40 THEN 'Low (0.40-0.59) - GS-based other'
        ELSE 'None/Unknown'
    END
ORDER BY MIN(ISNULL(eta_wind_confidence, 0)) DESC;

-- ============================================================================
-- 3. ETA ERROR ANALYSIS (For Completed Flights)
-- ============================================================================

PRINT '';
PRINT '=== 3. ETA ERROR ANALYSIS (Completed Flights) ===';
PRINT '';

-- Create temp table for completed flight analysis
IF OBJECT_ID('tempdb..#eta_analysis') IS NOT NULL DROP TABLE #eta_analysis;

SELECT
    ft.flight_uid,
    fp.fp_dest_icao,
    ft.eta_utc,
    COALESCE(ft.ata_runway_utc, ft.ata_utc) AS actual_arrival,
    ft.eta_wind_adj_kts,
    ft.eta_wind_confidence,
    ft.eta_confidence,
    -- Calculate ETA error in minutes (positive = late, negative = early)
    DATEDIFF(SECOND, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) / 60.0 AS eta_error_min,
    -- Categorize wind adjustment
    CASE
        WHEN ft.eta_wind_adj_kts IS NULL THEN 'No Wind Data'
        WHEN ABS(ft.eta_wind_adj_kts) < 5 THEN 'Minimal (<5 kts)'
        WHEN ft.eta_wind_adj_kts >= 5 THEN 'Tailwind (5+ kts)'
        WHEN ft.eta_wind_adj_kts <= -5 THEN 'Headwind (5+ kts)'
        ELSE 'Unknown'
    END AS wind_category,
    -- Flight distance for context
    ft.eta_route_dist_nm
INTO #eta_analysis
FROM adl_flight_times ft
JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
WHERE ft.eta_utc IS NOT NULL
  AND (ft.ata_utc IS NOT NULL OR ft.ata_runway_utc IS NOT NULL)
  AND COALESCE(ft.ata_runway_utc, ft.ata_utc) > ft.eta_utc - INTERVAL HOUR(2)  -- Sanity check
  AND ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) < 120;  -- Within 2 hours

-- Check sample size
DECLARE @sample_size INT;
SELECT @sample_size = COUNT(*) FROM #eta_analysis;
PRINT 'Analyzable completed flights: ' + CAST(@sample_size AS VARCHAR);
PRINT '';

IF @sample_size > 0
BEGIN
    -- Overall ETA accuracy metrics
    PRINT 'Overall ETA Accuracy:';
    SELECT
        COUNT(*) AS sample_size,
        ROUND(AVG(eta_error_min), 2) AS mean_error_min,
        ROUND(AVG(ABS(eta_error_min)), 2) AS mae_min,
        ROUND(SQRT(AVG(eta_error_min * eta_error_min)), 2) AS rmse_min,
        ROUND(STDEV(eta_error_min), 2) AS stdev_min,
        -- Percentiles
        ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY eta_error_min) OVER (), 2) AS median_error,
        ROUND(PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY eta_error_min) OVER (), 2) AS p25_error,
        ROUND(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY eta_error_min) OVER (), 2) AS p75_error
    FROM #eta_analysis;

    -- ETA accuracy BY wind category
    PRINT '';
    PRINT 'ETA Accuracy by Wind Category:';
    SELECT
        wind_category,
        COUNT(*) AS flights,
        ROUND(AVG(eta_error_min), 2) AS mean_error_min,
        ROUND(AVG(ABS(eta_error_min)), 2) AS mae_min,
        ROUND(SQRT(AVG(eta_error_min * eta_error_min)), 2) AS rmse_min,
        ROUND(STDEV(eta_error_min), 2) AS stdev_min,
        ROUND(AVG(eta_wind_adj_kts), 1) AS avg_wind_adj
    FROM #eta_analysis
    GROUP BY wind_category
    ORDER BY COUNT(*) DESC;

    -- ETA accuracy BY wind confidence
    PRINT '';
    PRINT 'ETA Accuracy by Wind Confidence:';
    SELECT
        CASE
            WHEN eta_wind_confidence >= 0.90 THEN 'High (Grid-based)'
            WHEN eta_wind_confidence >= 0.60 THEN 'Medium (GS cruise)'
            WHEN eta_wind_confidence >= 0.40 THEN 'Low (GS other)'
            WHEN eta_wind_confidence IS NOT NULL THEN 'Very Low'
            ELSE 'No Wind Calc'
        END AS confidence_tier,
        COUNT(*) AS flights,
        ROUND(AVG(eta_error_min), 2) AS mean_error_min,
        ROUND(AVG(ABS(eta_error_min)), 2) AS mae_min,
        ROUND(SQRT(AVG(eta_error_min * eta_error_min)), 2) AS rmse_min,
        SUM(CASE WHEN ABS(eta_error_min) <= 2 THEN 1 ELSE 0 END) * 100.0 / COUNT(*) AS pct_within_2min,
        SUM(CASE WHEN ABS(eta_error_min) <= 5 THEN 1 ELSE 0 END) * 100.0 / COUNT(*) AS pct_within_5min
    FROM #eta_analysis
    GROUP BY
        CASE
            WHEN eta_wind_confidence >= 0.90 THEN 'High (Grid-based)'
            WHEN eta_wind_confidence >= 0.60 THEN 'Medium (GS cruise)'
            WHEN eta_wind_confidence >= 0.40 THEN 'Low (GS other)'
            WHEN eta_wind_confidence IS NOT NULL THEN 'Very Low'
            ELSE 'No Wind Calc'
        END
    ORDER BY MIN(ISNULL(eta_wind_confidence, -1)) DESC;
END
ELSE
BEGIN
    PRINT 'Insufficient completed flight data for accuracy analysis.';
    PRINT 'This is expected if:';
    PRINT '  - VATSIM does not provide actual arrival times';
    PRINT '  - Flights are still in progress';
    PRINT '  - ATA tracking is not enabled';
END

-- ============================================================================
-- 4. THEORETICAL IMPACT ANALYSIS (Using Current Data)
-- ============================================================================

PRINT '';
PRINT '=== 4. THEORETICAL WIND IMPACT ANALYSIS ===';
PRINT '';
PRINT 'Analyzing how wind adjustments WOULD affect ETA for current flights:';
PRINT '';

-- Calculate expected time impact of wind adjustments
SELECT
    'Expected Wind Impact' AS analysis,
    COUNT(*) AS total_flights,
    SUM(CASE WHEN ABS(ISNULL(eta_wind_adj_kts, 0)) > 5 THEN 1 ELSE 0 END) AS flights_with_significant_wind,
    -- For flights with wind data, calculate expected time impact
    -- Using average remaining distance and wind-adjusted vs non-adjusted speed
    AVG(CASE
        WHEN p.dist_to_dest_nm > 50 AND ABS(ft.eta_wind_adj_kts) > 5 AND perf.cruise_speed > 0
        THEN (p.dist_to_dest_nm / NULLIF(perf.cruise_speed, 0) -
              p.dist_to_dest_nm / NULLIF(perf.cruise_speed + ft.eta_wind_adj_kts, 0)) * 60
        ELSE 0
    END) AS avg_time_impact_min,
    -- Min/max impacts
    MIN(CASE
        WHEN p.dist_to_dest_nm > 50 AND ABS(ft.eta_wind_adj_kts) > 5 AND perf.cruise_speed > 0
        THEN (p.dist_to_dest_nm / NULLIF(perf.cruise_speed, 0) -
              p.dist_to_dest_nm / NULLIF(perf.cruise_speed + ft.eta_wind_adj_kts, 0)) * 60
        ELSE NULL
    END) AS max_earlier_arrival_min,
    MAX(CASE
        WHEN p.dist_to_dest_nm > 50 AND ABS(ft.eta_wind_adj_kts) > 5 AND perf.cruise_speed > 0
        THEN (p.dist_to_dest_nm / NULLIF(perf.cruise_speed, 0) -
              p.dist_to_dest_nm / NULLIF(perf.cruise_speed + ft.eta_wind_adj_kts, 0)) * 60
        ELSE NULL
    END) AS max_later_arrival_min
FROM adl_flight_times ft
JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
LEFT JOIN aircraft_performance_profiles perf ON perf.icao_type = fp.fp_aircraft_type
WHERE c.is_active = 1
  AND c.phase IN ('enroute', 'cruise', 'climbing');

-- Distribution of wind impacts
PRINT '';
PRINT 'Wind impact distribution (time saved/lost in minutes):';

SELECT
    CASE
        WHEN time_impact_min IS NULL THEN 'No calculation'
        WHEN time_impact_min < -10 THEN 'Saves >10 min (strong tailwind)'
        WHEN time_impact_min < -5 THEN 'Saves 5-10 min'
        WHEN time_impact_min < -2 THEN 'Saves 2-5 min'
        WHEN time_impact_min <= 2 THEN 'Minimal impact (±2 min)'
        WHEN time_impact_min <= 5 THEN 'Adds 2-5 min'
        WHEN time_impact_min <= 10 THEN 'Adds 5-10 min'
        ELSE 'Adds >10 min (strong headwind)'
    END AS impact_category,
    COUNT(*) AS flight_count,
    ROUND(AVG(time_impact_min), 2) AS avg_impact_min,
    ROUND(AVG(eta_wind_adj_kts), 1) AS avg_wind_adj_kts,
    ROUND(AVG(dist_to_dest_nm), 0) AS avg_dist_remaining
FROM (
    SELECT
        ft.flight_uid,
        ft.eta_wind_adj_kts,
        p.dist_to_dest_nm,
        CASE
            WHEN p.dist_to_dest_nm > 50 AND ABS(ft.eta_wind_adj_kts) > 5 AND perf.cruise_speed > 0
            THEN (p.dist_to_dest_nm / NULLIF(perf.cruise_speed, 0) -
                  p.dist_to_dest_nm / NULLIF(perf.cruise_speed + ft.eta_wind_adj_kts, 0)) * 60
            ELSE NULL
        END AS time_impact_min
    FROM adl_flight_times ft
    JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
    JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
    JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
    LEFT JOIN aircraft_performance_profiles perf ON perf.icao_type = fp.fp_aircraft_type
    WHERE c.is_active = 1
      AND c.phase IN ('enroute', 'cruise', 'climbing')
) analysis
GROUP BY
    CASE
        WHEN time_impact_min IS NULL THEN 'No calculation'
        WHEN time_impact_min < -10 THEN 'Saves >10 min (strong tailwind)'
        WHEN time_impact_min < -5 THEN 'Saves 5-10 min'
        WHEN time_impact_min < -2 THEN 'Saves 2-5 min'
        WHEN time_impact_min <= 2 THEN 'Minimal impact (±2 min)'
        WHEN time_impact_min <= 5 THEN 'Adds 2-5 min'
        WHEN time_impact_min <= 10 THEN 'Adds 5-10 min'
        ELSE 'Adds >10 min (strong headwind)'
    END
ORDER BY MIN(ISNULL(time_impact_min, 0));

-- ============================================================================
-- 5. WIND GRID DATA STATUS
-- ============================================================================

PRINT '';
PRINT '=== 5. WIND GRID DATA STATUS ===';
PRINT '';

IF OBJECT_ID('dbo.wind_grid', 'U') IS NOT NULL
BEGIN
    SELECT
        'Wind Grid Status' AS metric,
        COUNT(*) AS total_records,
        COUNT(DISTINCT CONCAT(lat, ',', lon)) AS unique_grid_points,
        COUNT(DISTINCT pressure_hpa) AS pressure_levels,
        MIN(valid_time_utc) AS earliest_forecast,
        MAX(valid_time_utc) AS latest_forecast,
        MAX(fetched_utc) AS last_fetched,
        DATEDIFF(HOUR, MAX(fetched_utc), GETUTCDATE()) AS hours_since_fetch
    FROM dbo.wind_grid;

    PRINT '';
    PRINT 'If hours_since_fetch > 6, wind grid data may be stale.';
    PRINT 'Grid-based wind calculations will fall back to GS-based estimates.';
END
ELSE
BEGIN
    PRINT 'wind_grid table not found. Only GS-based wind estimation available.';
END

-- ============================================================================
-- 6. SAMPLE FLIGHTS WITH WIND IMPACT
-- ============================================================================

PRINT '';
PRINT '=== 6. SAMPLE FLIGHTS WITH SIGNIFICANT WIND IMPACT ===';
PRINT '';

SELECT TOP 20
    fp.fp_callsign,
    fp.fp_dept_icao + '->' + fp.fp_dest_icao AS route,
    fp.fp_aircraft_type,
    p.altitude_ft,
    p.groundspeed_kts AS gs_kts,
    COALESCE(perf.cruise_speed, 450) AS exp_tas,
    p.groundspeed_kts - COALESCE(perf.cruise_speed, 450) AS gs_vs_tas,
    ft.eta_wind_adj_kts AS wind_adj,
    ft.eta_wind_confidence AS conf,
    CAST(p.dist_to_dest_nm AS INT) AS dist_nm,
    CASE
        WHEN p.dist_to_dest_nm > 50 AND perf.cruise_speed > 0
        THEN CAST((p.dist_to_dest_nm / NULLIF(perf.cruise_speed, 0) -
              p.dist_to_dest_nm / NULLIF(perf.cruise_speed + ft.eta_wind_adj_kts, 0)) * 60 AS DECIMAL(5,1))
        ELSE 0
    END AS time_impact_min,
    FORMAT(ft.eta_utc, 'HH:mm') AS eta
FROM adl_flight_times ft
JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
LEFT JOIN aircraft_performance_profiles perf ON perf.icao_type = fp.fp_aircraft_type
WHERE c.is_active = 1
  AND c.phase IN ('enroute', 'cruise')
  AND ABS(ft.eta_wind_adj_kts) > 10
  AND p.dist_to_dest_nm > 100
ORDER BY ABS(ft.eta_wind_adj_kts) DESC;

PRINT '';
PRINT '============================================================================';
PRINT '  ANALYSIS COMPLETE';
PRINT '============================================================================';
PRINT '';
PRINT 'Key Takeaways:';
PRINT '- Wind adjustments affect ETA by modifying effective cruise speed';
PRINT '- Tailwinds (positive adj) = faster arrival = earlier ETA';
PRINT '- Headwinds (negative adj) = slower arrival = later ETA';
PRINT '- Impact scales with remaining distance (more significant for long-haul)';
PRINT '';
PRINT 'Confidence Levels:';
PRINT '- 0.90+: Grid-based wind from NOAA GFS data (most accurate)';
PRINT '- 0.60-0.89: GS-based estimation during cruise (good)';
PRINT '- 0.40-0.59: GS-based estimation other phases (moderate)';
PRINT '- <0.40: No wind data available';
GO
