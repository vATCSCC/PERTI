-- ============================================================================
-- sp_ProcessTrajectoryBatch V2.0 - Performance Optimized with iTVFs
--
-- Changes from V1:
--   - Replaced scalar UDFs with inline table-valued functions (iTVFs)
--   - fn_IsFlightRelevant -> itvf_IsFlightRelevant
--   - fn_GetTrajectoryTier -> itvf_GetTrajectoryTier (computed ONCE per flight)
--   - fn_GetTierIntervalSeconds -> itvf_GetTierIntervalSeconds
--   - Enables parallel execution on all 8 vCores
--   - Expected speedup: ~4-8x for trajectory processing
--
-- Dependencies:
--   - dbo.itvf_IsFlightRelevant
--   - dbo.itvf_GetTrajectoryTier
--   - dbo.itvf_GetTierIntervalSeconds
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_ProcessTrajectoryBatch
    @process_eta BIT = 1,
    @process_trajectory BIT = 1,
    @eta_count INT = NULL OUTPUT,
    @traj_count INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @eta_count = 0;
    SET @traj_count = 0;

    -- ========================================================================
    -- Update relevance using iTVF (enables parallelism)
    -- ========================================================================
    UPDATE c
    SET c.is_relevant = r.is_relevant
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    CROSS APPLY dbo.itvf_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon) r
    WHERE c.is_active = 1 AND c.is_relevant IS NULL;

    -- ========================================================================
    -- Trajectory logging using iTVFs
    -- Key optimization: Compute tier ONCE per flight, use it in both
    -- INSERT filter and as the stored value
    -- ========================================================================
    IF @process_trajectory = 1
    BEGIN
        -- Use CTE to compute tier ONCE per flight (instead of 4 scalar UDF calls)
        ;WITH FlightTiers AS (
            SELECT
                c.flight_uid,
                c.phase,
                c.last_trajectory_utc,
                c.last_trajectory_tier,
                p.lat,
                p.lon,
                p.altitude_ft,
                p.groundspeed_kts,
                p.heading_deg,
                p.vertical_rate_fpm,
                p.dist_to_dest_nm,
                p.dist_flown_nm,
                -- Compute tier ONCE using iTVF
                tier.tier AS current_tier,
                -- Compute interval ONCE using iTVF
                interval_calc.interval_seconds
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            -- iTVF for tier calculation (replaces 2+ scalar UDF calls)
            CROSS APPLY dbo.itvf_GetTrajectoryTier(
                fp.fp_dept_icao,
                fp.fp_dest_icao,
                p.lat,
                p.lon,
                p.altitude_ft,
                p.groundspeed_kts,
                ISNULL(p.vertical_rate_fpm, 0),
                p.dist_to_dest_nm,
                p.dist_flown_nm,
                fp.fp_altitude_ft,
                c.phase
            ) tier
            -- iTVF for interval lookup (replaces scalar UDF call)
            CROSS APPLY dbo.itvf_GetTierIntervalSeconds(ISNULL(c.last_trajectory_tier, 4)) interval_calc
            WHERE c.is_active = 1
              AND c.is_relevant = 1
              AND p.lat IS NOT NULL
        )
        INSERT INTO dbo.adl_flight_trajectory (
            flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts,
            heading_deg, vertical_rate_fpm, tier, tier_reason, flight_phase,
            dist_to_dest_nm, dist_from_origin_nm, source
        )
        SELECT
            ft.flight_uid,
            @now,
            ft.lat,
            ft.lon,
            ft.altitude_ft,
            ft.groundspeed_kts,
            ft.heading_deg,
            ft.vertical_rate_fpm,
            ft.current_tier,           -- Use pre-computed tier
            'BATCH',
            ft.phase,
            ft.dist_to_dest_nm,
            ft.dist_flown_nm,
            'vatsim'
        FROM FlightTiers ft
        WHERE ft.current_tier < 7      -- Use pre-computed tier (no second UDF call!)
          AND (
              ft.last_trajectory_utc IS NULL
              OR DATEDIFF(SECOND, ft.last_trajectory_utc, @now) >= ft.interval_seconds
          );

        SET @traj_count = @@ROWCOUNT;

        -- Update flight_core with new tier (using same CTE pattern)
        ;WITH FlightTierUpdate AS (
            SELECT
                c.flight_uid,
                tier.tier AS current_tier
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            CROSS APPLY dbo.itvf_GetTrajectoryTier(
                fp.fp_dept_icao,
                fp.fp_dest_icao,
                p.lat,
                p.lon,
                p.altitude_ft,
                p.groundspeed_kts,
                ISNULL(p.vertical_rate_fpm, 0),
                p.dist_to_dest_nm,
                p.dist_flown_nm,
                fp.fp_altitude_ft,
                c.phase
            ) tier
            WHERE c.is_active = 1
              AND c.is_relevant = 1
              AND (c.last_trajectory_utc IS NULL OR c.last_trajectory_utc < @now)
        )
        UPDATE c
        SET c.last_trajectory_tier = ftu.current_tier,
            c.last_trajectory_utc = @now
        FROM dbo.adl_flight_core c
        JOIN FlightTierUpdate ftu ON ftu.flight_uid = c.flight_uid;
    END

    -- ========================================================================
    -- ETA calculation (unchanged - no scalar UDFs here)
    -- Updates eta_utc, eta_runway_utc, and eta_epoch for API/UI consumption
    -- ========================================================================
    IF @process_eta = 1
    BEGIN
        ;WITH EtaCalc AS (
            SELECT
                ft.flight_uid,
                DATEADD(MINUTE, CAST(
                    CASE
                        WHEN c.phase = 'arrived' THEN 0
                        WHEN c.phase = 'descending'
                            THEN p.dist_to_dest_nm / NULLIF(p.groundspeed_kts, 0) * 60
                        ELSE
                            (p.dist_to_dest_nm - (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0) / 450.0 * 60
                            + (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0 / 280.0 * 60
                    END AS INT
                ), @now) AS calc_eta,
                CASE WHEN c.phase = 'arrived' THEN 'A' ELSE 'E' END AS calc_prefix,
                CASE c.phase
                    WHEN 'arrived' THEN 1.0
                    WHEN 'descending' THEN 0.92
                    WHEN 'enroute' THEN 0.88
                    ELSE 0.75
                END AS calc_confidence,
                (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0 AS calc_tod_dist
            FROM dbo.adl_flight_times ft
            JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.apts a ON a.ICAO_ID = fp.fp_dest_icao
            WHERE c.is_active = 1 AND p.lat IS NOT NULL
        )
        UPDATE ft
        SET ft.eta_utc = e.calc_eta,
            ft.eta_runway_utc = e.calc_eta,
            ft.eta_epoch = DATEDIFF_BIG(SECOND, '1970-01-01', e.calc_eta),
            ft.eta_prefix = e.calc_prefix,
            ft.eta_confidence = e.calc_confidence,
            ft.eta_last_calc_utc = @now,
            ft.tod_dist_nm = e.calc_tod_dist
        FROM dbo.adl_flight_times ft
        JOIN EtaCalc e ON e.flight_uid = ft.flight_uid;

        SET @eta_count = @@ROWCOUNT;
    END
END
GO

PRINT 'Created sp_ProcessTrajectoryBatch V2.0 (iTVF optimized)';
PRINT 'Replaced scalar UDFs with iTVFs for parallel execution';
PRINT 'Expected improvement: Steps 8+8c from ~1.3s to ~0.2-0.3s';
GO
