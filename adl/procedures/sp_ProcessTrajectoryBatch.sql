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
    
    -- Update relevance
    UPDATE c SET c.is_relevant = dbo.fn_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon)
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.is_active = 1 AND c.is_relevant IS NULL;
    
    -- Trajectory logging
    IF @process_trajectory = 1
    BEGIN
        INSERT INTO dbo.adl_flight_trajectory (flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm, tier, tier_reason, flight_phase, dist_to_dest_nm, dist_from_origin_nm, source)
        SELECT c.flight_uid, @now, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, p.heading_deg, p.vertical_rate_fpm,
            dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase),
            'BATCH', c.phase, p.dist_to_dest_nm, p.dist_flown_nm, 'vatsim'
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.is_relevant = 1 AND p.lat IS NOT NULL
          AND dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase) < 7
          AND (c.last_trajectory_utc IS NULL OR DATEDIFF(SECOND, c.last_trajectory_utc, @now) >= dbo.fn_GetTierIntervalSeconds(ISNULL(c.last_trajectory_tier, 4)));
        SET @traj_count = @@ROWCOUNT;
        
        UPDATE c SET c.last_trajectory_tier = dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase), c.last_trajectory_utc = @now
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.is_relevant = 1 AND (c.last_trajectory_utc IS NULL OR c.last_trajectory_utc < @now);
    END
    
    -- ETA calculation (using ELEV not Elev_ft)
    -- Updates eta_utc, eta_runway_utc (same value), and eta_epoch for API/UI consumption
    IF @process_eta = 1
    BEGIN
        -- Use a CTE to calculate the ETA value once, then apply to all fields
        ;WITH EtaCalc AS (
            SELECT
                ft.flight_uid,
                DATEADD(MINUTE, CAST(
                    CASE WHEN c.phase = 'arrived' THEN 0
                         WHEN c.phase = 'descending' THEN p.dist_to_dest_nm / NULLIF(p.groundspeed_kts, 0) * 60
                         ELSE (p.dist_to_dest_nm - (ISNULL(fp.fp_altitude_ft,35000) - ISNULL(CAST(a.ELEV AS INT),0))/1000.0*3.0) / 450.0 * 60
                              + (ISNULL(fp.fp_altitude_ft,35000) - ISNULL(CAST(a.ELEV AS INT),0))/1000.0*3.0 / 280.0 * 60
                    END AS INT), @now) AS calc_eta,
                CASE WHEN c.phase = 'arrived' THEN 'A' ELSE 'E' END AS calc_prefix,
                CASE c.phase WHEN 'arrived' THEN 1.0 WHEN 'descending' THEN 0.92 WHEN 'enroute' THEN 0.88 ELSE 0.75 END AS calc_confidence,
                (ISNULL(fp.fp_altitude_ft,35000) - ISNULL(CAST(a.ELEV AS INT),0))/1000.0*3.0 AS calc_tod_dist
            FROM dbo.adl_flight_times ft
            JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.apts a ON a.ICAO_ID = fp.fp_dest_icao
            WHERE c.is_active = 1 AND p.lat IS NOT NULL
        )
        UPDATE ft SET
            ft.eta_utc = e.calc_eta,
            ft.eta_runway_utc = e.calc_eta,  -- Same as eta_utc for now
            ft.eta_epoch = DATEDIFF(SECOND, '1970-01-01', e.calc_eta),  -- Unix epoch for sorting/filtering
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

PRINT 'Created sp_ProcessTrajectoryBatch';