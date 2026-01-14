-- =====================================================
-- Fix Stale ATIS Combination
-- Migration: 094
-- Description: Ensures ARR and DEP ATIS are only combined
--              when BOTH are recent. Filters out stale runway
--              records from views.
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 094: Fix Stale ATIS Combination ===';
PRINT '';

-- =====================================================
-- 1. Update vw_current_runways_in_use
-- Only show runways where the underlying ATIS is recent (< 2 hours)
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_runways_in_use')
    DROP VIEW dbo.vw_current_runways_in_use;
GO

CREATE VIEW dbo.vw_current_runways_in_use AS
SELECT
    r.airport_icao,
    r.runway_id,
    r.runway_use,
    r.approach_type,
    r.source_type,
    r.effective_utc,
    a.atis_code,
    a.atis_type,
    a.callsign,
    a.controller_cid,
    a.fetched_utc AS atis_fetched_utc,
    DATEDIFF(MINUTE, r.effective_utc, GETUTCDATE()) AS active_mins,
    DATEDIFF(MINUTE, a.fetched_utc, GETUTCDATE()) AS atis_age_mins
FROM dbo.runway_in_use r
JOIN dbo.vatsim_atis a ON r.atis_id = a.atis_id
WHERE r.superseded_utc IS NULL
  -- Only include if ATIS is less than 2 hours old
  AND a.fetched_utc > DATEADD(HOUR, -2, GETUTCDATE());
GO

PRINT 'Updated vw_current_runways_in_use - added ATIS age filter (2 hours max)';
GO

-- =====================================================
-- 2. Update vw_current_airport_config
-- Only combine ARR and DEP runways when both source types are recent
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_airport_config')
    DROP VIEW dbo.vw_current_airport_config;
GO

CREATE VIEW dbo.vw_current_airport_config AS
WITH RecentRunways AS (
    -- Get all recent runways
    SELECT
        airport_icao,
        runway_id,
        runway_use,
        approach_type,
        source_type,
        effective_utc,
        atis_code,
        atis_fetched_utc,
        atis_age_mins
    FROM dbo.vw_current_runways_in_use
),
SourceTypeAges AS (
    -- Calculate the age of each source type per airport
    SELECT
        airport_icao,
        source_type,
        MAX(atis_fetched_utc) AS latest_atis_utc,
        MIN(atis_age_mins) AS source_age_mins
    FROM RecentRunways
    GROUP BY airport_icao, source_type
),
ValidCombinations AS (
    -- For airports with both ARR and DEP ATIS, check if they're within 2 hours of each other
    SELECT
        arr.airport_icao,
        arr.latest_atis_utc AS arr_atis_utc,
        dep.latest_atis_utc AS dep_atis_utc,
        ABS(DATEDIFF(MINUTE, arr.latest_atis_utc, dep.latest_atis_utc)) AS time_diff_mins,
        CASE
            -- Both sources exist and are within 2 hours of each other
            WHEN arr.latest_atis_utc IS NOT NULL
                 AND dep.latest_atis_utc IS NOT NULL
                 AND ABS(DATEDIFF(MINUTE, arr.latest_atis_utc, dep.latest_atis_utc)) <= 120
            THEN 1
            ELSE 0
        END AS can_combine_arr_dep
    FROM (SELECT DISTINCT airport_icao FROM RecentRunways) a
    LEFT JOIN SourceTypeAges arr ON a.airport_icao = arr.airport_icao AND arr.source_type = 'ARR'
    LEFT JOIN SourceTypeAges dep ON a.airport_icao = dep.airport_icao AND dep.source_type = 'DEP'
)
SELECT
    rr.airport_icao,
    -- Arrival runways: Include from ARR source, or from COMB source
    -- If separate ARR/DEP ATIS exist but are too far apart, only use the more recent one's data
    STRING_AGG(
        CASE
            -- Combined ATIS - always use
            WHEN rr.source_type = 'COMB' AND rr.runway_use IN ('ARR', 'BOTH') THEN rr.runway_id
            -- ARR source - use if no DEP source, or if combination is valid
            WHEN rr.source_type = 'ARR' AND rr.runway_use IN ('ARR', 'BOTH')
                 AND (vc.can_combine_arr_dep = 1 OR vc.dep_atis_utc IS NULL) THEN rr.runway_id
            -- DEP source with BOTH use - only if combination is valid
            WHEN rr.source_type = 'DEP' AND rr.runway_use = 'BOTH'
                 AND vc.can_combine_arr_dep = 1 THEN rr.runway_id
            ELSE NULL
        END, '/'
    ) WITHIN GROUP (ORDER BY rr.runway_id) AS arr_runways,
    -- Departure runways: Include from DEP source, or from COMB source
    STRING_AGG(
        CASE
            -- Combined ATIS - always use
            WHEN rr.source_type = 'COMB' AND rr.runway_use IN ('DEP', 'BOTH') THEN rr.runway_id
            -- DEP source - use if no ARR source, or if combination is valid
            WHEN rr.source_type = 'DEP' AND rr.runway_use IN ('DEP', 'BOTH')
                 AND (vc.can_combine_arr_dep = 1 OR vc.arr_atis_utc IS NULL) THEN rr.runway_id
            -- ARR source with BOTH use - only if combination is valid
            WHEN rr.source_type = 'ARR' AND rr.runway_use = 'BOTH'
                 AND vc.can_combine_arr_dep = 1 THEN rr.runway_id
            ELSE NULL
        END, '/'
    ) WITHIN GROUP (ORDER BY rr.runway_id) AS dep_runways,
    -- Approach info
    STRING_AGG(
        CASE WHEN rr.approach_type IS NOT NULL
             THEN rr.approach_type + ' ' + rr.runway_id END, ', '
    ) AS approach_info,
    MIN(rr.effective_utc) AS config_since,
    MAX(rr.atis_code) AS atis_code,
    -- Debug/status info
    MAX(vc.can_combine_arr_dep) AS arr_dep_combined,
    MAX(vc.time_diff_mins) AS arr_dep_time_diff_mins
FROM RecentRunways rr
LEFT JOIN ValidCombinations vc ON rr.airport_icao = vc.airport_icao
GROUP BY rr.airport_icao;
GO

PRINT 'Updated vw_current_airport_config - ARR/DEP combination requires both to be recent';
GO

-- =====================================================
-- 3. Verify the changes
-- =====================================================

PRINT '';
PRINT '=== Verification ===';

-- Show airports with split ATIS that would be affected
SELECT
    airport_icao,
    source_type,
    COUNT(*) AS runway_count,
    MIN(atis_age_mins) AS atis_age_mins
FROM dbo.vw_current_runways_in_use
WHERE source_type IN ('ARR', 'DEP')
GROUP BY airport_icao, source_type
ORDER BY airport_icao, source_type;

PRINT '';
PRINT 'Migration 094 completed successfully.';
GO
