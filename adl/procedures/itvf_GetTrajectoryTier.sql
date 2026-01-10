-- ============================================================================
-- itvf_GetTrajectoryTier.sql
-- INLINE TABLE-VALUED FUNCTION (Performance Optimized)
--
-- Replaces scalar fn_GetTrajectoryTier for better parallelism and set-based
-- execution. With 8 vCores, this enables ~8x speedup on trajectory calculations.
--
-- Returns tier 0-7 based on:
-- - Flight phase (critical phases = Tier 0)
-- - Approaching events (TOD/TOC, boundaries = Tier 1)
-- - Oceanic/transit (Tier 2)
-- - Ground operations (Tier 3)
-- - Stable cruise (Tier 4-6)
-- - Irrelevant flights (Tier 7 = no logging)
--
-- Usage:
--   CROSS APPLY dbo.itvf_GetTrajectoryTier(
--       dept, dest, lat, lon, alt, gs, vr, dist_dest, dist_orig, filed_alt, phase
--   ) t
--   WHERE t.tier < 7
--
-- Part of the ETA & Trajectory Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.itvf_GetTrajectoryTier', 'IF') IS NOT NULL
    DROP FUNCTION dbo.itvf_GetTrajectoryTier;
GO

CREATE FUNCTION dbo.itvf_GetTrajectoryTier(
    @dept_icao          CHAR(4),
    @dest_icao          CHAR(4),
    @current_lat        DECIMAL(10,7),
    @current_lon        DECIMAL(11,7),
    @altitude_ft        INT,
    @groundspeed_kts    INT,
    @vertical_rate_fpm  INT,
    @dist_to_dest_nm    DECIMAL(10,2),
    @dist_from_origin_nm DECIMAL(10,2),
    @filed_altitude_ft  INT,
    @phase              NVARCHAR(16)
)
RETURNS TABLE
AS
RETURN (
    -- Use a subquery to compute derived values once
    SELECT
        CASE
            -- ================================================================
            -- TIER 7: Not Relevant
            -- ================================================================
            -- Inline relevance check (replaces fn_IsFlightRelevant call)
            WHEN NOT (
                -- Origin in covered region
                @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%' OR
                @dept_icao LIKE 'C%' OR @dept_icao LIKE 'MM%' OR
                @dept_icao LIKE 'M[GHNRPSB]%' OR @dept_icao LIKE 'T%' OR @dept_icao LIKE 'S%' OR
                -- Destination in covered region
                @dest_icao LIKE 'K%' OR @dest_icao LIKE 'P%' OR
                @dest_icao LIKE 'C%' OR @dest_icao LIKE 'MM%' OR
                @dest_icao LIKE 'M[GHNRPSB]%' OR @dest_icao LIKE 'T%' OR @dest_icao LIKE 'S%' OR
                -- Position in covered airspace (simplified check)
                (@current_lat IS NOT NULL AND @current_lon IS NOT NULL AND (
                    (@current_lat BETWEEN 15 AND 72 AND @current_lon BETWEEN -180 AND -50) OR
                    (@current_lat BETWEEN 5 AND 35 AND @current_lon BETWEEN -100 AND -55) OR
                    (@current_lat BETWEEN -56 AND 15 AND @current_lon BETWEEN -85 AND -30) OR
                    (@current_lat BETWEEN 20 AND 45 AND @current_lon BETWEEN -80 AND -40) OR
                    (@current_lat BETWEEN 15 AND 60 AND @current_lon BETWEEN -180 AND -130) OR
                    (@current_lat BETWEEN 40 AND 60 AND @current_lon BETWEEN -60 AND -30) OR
                    (@current_lat BETWEEN 15 AND 30 AND @current_lon BETWEEN -180 AND -150)
                ))
            ) THEN 7

            -- ================================================================
            -- TIER 0: Critical Phases (15 seconds)
            -- ================================================================

            -- Initial climb: <50nm from origin, climbing, <FL180
            WHEN calc.dist_from_origin < 50
                 AND calc.vertical_rate > 300
                 AND calc.altitude < 18000
            THEN 0

            -- Final approach: <15nm from destination, descending, <10,000ft
            WHEN calc.dist_to_dest < 15
                 AND calc.vertical_rate < -300
                 AND calc.altitude < 10000
            THEN 0

            -- Go-around detection: climbing rapidly near airport
            WHEN calc.dist_to_dest < 5
                 AND calc.vertical_rate > 1000
                 AND calc.altitude < 5000
            THEN 0

            -- Runway operations: high speed on ground near airport
            WHEN calc.groundspeed BETWEEN 40 AND 180
                 AND calc.altitude < 500
                 AND (calc.dist_from_origin < 5 OR calc.dist_to_dest < 5)
            THEN 0

            -- Very close to either airport
            WHEN calc.dist_from_origin < 3 OR calc.dist_to_dest < 3
            THEN 0

            -- ================================================================
            -- TIER 1: Approaching Events (30 seconds)
            -- ================================================================

            -- Approaching TOD (within 5 minutes) - uses pre-computed time_to_tod
            WHEN calc.time_to_tod_min IS NOT NULL
                 AND calc.time_to_tod_min > 0
                 AND calc.time_to_tod_min <= 5
            THEN 1

            -- Approaching destination (< 100nm)
            WHEN calc.dist_to_dest < 100 THEN 1

            -- Speed anomaly in cruise: significant vertical rate at altitude
            WHEN calc.altitude > 25000 AND ABS(calc.vertical_rate) > 500 THEN 1

            -- Climbing phase (not initial)
            WHEN calc.vertical_rate > 300 AND calc.altitude >= 18000 THEN 1

            -- Descending phase (not final)
            WHEN calc.vertical_rate < -300
                 AND calc.altitude >= 10000
                 AND calc.dist_to_dest >= 15
            THEN 1

            -- Phase indicates transition
            WHEN @phase IN ('departed', 'climbing', 'descending') THEN 1

            -- Approaching TOD within 10 minutes (blocking check for cruise demotion)
            WHEN calc.time_to_tod_min IS NOT NULL
                 AND calc.time_to_tod_min > 0
                 AND calc.time_to_tod_min <= 10
            THEN 1

            -- ================================================================
            -- TIER 3: Ground Operations (2 minutes)
            -- ================================================================

            -- Taxiing
            WHEN calc.groundspeed BETWEEN 5 AND 35 AND calc.altitude < 500 THEN 3

            -- Taxiing phase
            WHEN @phase = 'taxiing' THEN 3

            -- ================================================================
            -- TIER 2: Oceanic / Transit (1 minute)
            -- ================================================================

            -- Oceanic with moderate distance remaining, stable
            WHEN calc.is_oceanic = 1
                 AND calc.dist_to_dest > 100
                 AND ABS(calc.vertical_rate) < 500
            THEN 2

            -- ================================================================
            -- TIER 5: Extended Oceanic / Sim Pause (10 minutes)
            -- ================================================================

            -- Sim pause (stationary in air)
            WHEN calc.groundspeed < 50 AND calc.altitude > 10000 THEN 5

            -- Extended stable oceanic cruise (with TOD blocking check)
            WHEN calc.is_oceanic = 1
                 AND ABS(calc.vertical_rate) < 100
                 AND calc.altitude > 30000
                 AND calc.dist_to_dest > 500
                 AND (calc.time_to_tod_min IS NULL OR calc.time_to_tod_min > 15)
            THEN 5

            -- ================================================================
            -- TIER 6: Ultra-Long Oceanic (30 minutes)
            -- ================================================================

            -- Ultra-long oceanic (very far from land)
            WHEN calc.is_oceanic = 1
                 AND ABS(calc.vertical_rate) < 50
                 AND calc.altitude > 35000
                 AND calc.dist_to_dest > 1000
                 AND calc.dist_from_origin > 1000
                 AND (calc.time_to_tod_min IS NULL OR calc.time_to_tod_min > 30)
            THEN 6

            -- ================================================================
            -- TIER 4: Default (Stable Cruise, Prefile, etc.) - 5 minutes
            -- ================================================================
            ELSE 4

        END AS tier
    FROM (
        -- Subquery to compute derived values ONCE
        SELECT
            -- Null-safe values
            ISNULL(@altitude_ft, 0) AS altitude,
            ISNULL(@groundspeed_kts, 0) AS groundspeed,
            ISNULL(@vertical_rate_fpm, 0) AS vertical_rate,
            ISNULL(@dist_to_dest_nm, 9999) AS dist_to_dest,
            ISNULL(@dist_from_origin_nm, 0) AS dist_from_origin,
            ISNULL(@filed_altitude_ft, 35000) AS filed_altitude,

            -- TOD distance (3nm per 1000ft descent)
            ISNULL(@filed_altitude_ft, 35000) / 1000.0 * 3.0 AS tod_dist_nm,

            -- Time to TOD in minutes
            CASE
                WHEN ISNULL(@groundspeed_kts, 0) > 0
                     AND ISNULL(@dist_to_dest_nm, 9999) > (ISNULL(@filed_altitude_ft, 35000) / 1000.0 * 3.0)
                THEN (ISNULL(@dist_to_dest_nm, 9999) - (ISNULL(@filed_altitude_ft, 35000) / 1000.0 * 3.0))
                     / @groundspeed_kts * 60.0
                ELSE NULL
            END AS time_to_tod_min,

            -- Oceanic detection
            CASE
                -- North Atlantic
                WHEN @current_lat BETWEEN 35 AND 65 AND @current_lon BETWEEN -60 AND -10 THEN 1
                -- North Pacific
                WHEN @current_lat BETWEEN 20 AND 60 AND @current_lon BETWEEN -180 AND -140 THEN 1
                -- Central Pacific (Hawaii)
                WHEN @current_lat BETWEEN 15 AND 35 AND @current_lon BETWEEN -180 AND -150 THEN 1
                ELSE 0
            END AS is_oceanic
    ) calc
);
GO

PRINT 'Created inline table-valued function dbo.itvf_GetTrajectoryTier';
PRINT 'Replaces fn_GetTrajectoryTier with inlined fn_IsFlightRelevant';
PRINT 'Enables parallel execution on 8 vCores';
GO
