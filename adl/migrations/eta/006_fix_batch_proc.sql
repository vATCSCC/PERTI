-- Quick fix: Recreate sp_ProcessTrajectoryBatch with correct ELEV column
IF OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ProcessTrajectoryBatch;
GO

CREATE PROCEDURE dbo.sp_ProcessTrajectoryBatch
    @process_eta        BIT = 1,
    @process_trajectory BIT = 1,
    @eta_count          INT = NULL OUTPUT,
    @traj_count         INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @eta_count = 0;
    SET @traj_count = 0;
    
    -- Update relevance flags for new flights
    UPDATE c
    SET c.is_relevant = dbo.fn_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon)
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.is_relevant IS NULL;
    
    -- Process Trajectory Logging
    IF @process_trajectory = 1
    BEGIN
        INSERT INTO dbo.adl_flight_trajectory (
            flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts,
            heading_deg, vertical_rate_fpm, tier, tier_reason, flight_phase,
            dist_to_dest_nm, dist_from_origin_nm, source
        )
        SELECT 
            c.flight_uid,
            @now,
            p.lat,
            p.lon,
            p.altitude_ft,
            p.groundspeed_kts,
            p.heading_deg,
            p.vertical_rate_fpm,
            dbo.fn_GetTrajectoryTier(
                fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, 
                p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0),
                p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase
            ),
            'BATCH',
            c.phase,
            p.dist_to_dest_nm,
            p.dist_flown_nm,
            'vatsim'
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.is_relevant = 1
          AND p.lat IS NOT NULL
          AND dbo.fn_GetTrajectoryTier(
                fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, 
                p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0),
                p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase
              ) < 7
          AND (
              c.last_trajectory_utc IS NULL
              OR DATEDIFF(SECOND, c.last_trajectory_utc, @now) >= 
                 dbo.fn_GetTierIntervalSeconds(ISNULL(c.last_trajectory_tier, 4))
          );
        
        SET @traj_count = @@ROWCOUNT;
        
        -- Update tracking
        UPDATE c
        SET c.last_trajectory_tier = dbo.fn_GetTrajectoryTier(
                fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, 
                p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0),
                p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase
            ),
            c.last_trajectory_utc = @now
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.is_relevant = 1
          AND (c.last_trajectory_utc IS NULL OR c.last_trajectory_utc < @now);
    END
    
    -- Process ETA Calculations (simplified - uses ELEV not Elev_ft)
    IF @process_eta = 1
    BEGIN
        ;WITH eta_calc AS (
            SELECT 
                c.flight_uid,
                c.phase,
                p.groundspeed_kts,
                p.dist_to_dest_nm,
                fp.fp_altitude_ft,
                -- TOD distance: 3nm per 1000ft (using ELEV column)
                (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0 AS tod_dist,
                -- Time to destination
                CASE 
                    WHEN c.phase = 'arrived' THEN 0
                    WHEN c.phase IN ('descending') AND p.dist_to_dest_nm < 50 
                        THEN p.dist_to_dest_nm / NULLIF(p.groundspeed_kts, 0) * 60
                    WHEN c.phase IN ('descending') 
                        THEN p.dist_to_dest_nm / 280.0 * 60
                    WHEN p.dist_to_dest_nm <= (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0
                        THEN p.dist_to_dest_nm / 280.0 * 60
                    ELSE 
                        (p.dist_to_dest_nm - (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0) / 450.0 * 60
                        + (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(CAST(a.ELEV AS INT), 0)) / 1000.0 * 3.0 / 280.0 * 60
                END AS time_to_dest_min,
                -- Confidence by phase
                CASE c.phase
                    WHEN 'arrived' THEN 1.0
                    WHEN 'descending' THEN 0.92
                    WHEN 'enroute' THEN 0.88
                    WHEN 'departed' THEN 0.82
                    WHEN 'taxiing' THEN 0.75
                    ELSE 0.65
                END AS confidence,
                -- Prefix
                CASE 
                    WHEN c.phase = 'arrived' THEN 'A'
                    WHEN t.edct_utc IS NOT NULL THEN 'C'
                    WHEN c.phase IN ('prefile', 'unknown') THEN 'P'
                    ELSE 'E'
                END AS eta_prefix
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_tmi t ON t.flight_uid = c.flight_uid
            LEFT JOIN dbo.apts a ON a.ICAO_ID = fp.fp_dest_icao
            WHERE c.is_active = 1
              AND p.lat IS NOT NULL
              AND fp.fp_dest_icao IS NOT NULL
        )
        UPDATE ft
        SET 
            ft.eta_utc = CASE 
                WHEN ec.phase = 'arrived' THEN ft.ata_runway_utc
                ELSE DATEADD(MINUTE, CAST(ec.time_to_dest_min AS INT), @now)
            END,
            ft.eta_runway_utc = CASE 
                WHEN ec.phase = 'arrived' THEN ft.ata_runway_utc
                ELSE DATEADD(MINUTE, CAST(ec.time_to_dest_min AS INT), @now)
            END,
            ft.eta_prefix = ec.eta_prefix,
            ft.eta_route_dist_nm = ec.dist_to_dest_nm,
            ft.eta_confidence = ec.confidence,
            ft.eta_last_calc_utc = @now,
            ft.tod_dist_nm = ec.tod_dist,
            ft.times_updated_utc = @now
        FROM dbo.adl_flight_times ft
        JOIN eta_calc ec ON ec.flight_uid = ft.flight_uid
        WHERE ec.time_to_dest_min IS NOT NULL;
        
        SET @eta_count = @@ROWCOUNT;
        
        -- Insert missing times rows
        INSERT INTO dbo.adl_flight_times (flight_uid, eta_utc, eta_runway_utc, eta_prefix, eta_confidence, eta_last_calc_utc)
        SELECT 
            c.flight_uid,
            DATEADD(MINUTE, CAST(p.dist_to_dest_nm / 450.0 * 60 AS INT), @now),
            DATEADD(MINUTE, CAST(p.dist_to_dest_nm / 450.0 * 60 AS INT), @now),
            'E',
            0.70,
            @now
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND ft.flight_uid IS NULL
          AND p.dist_to_dest_nm IS NOT NULL
          AND p.dist_to_dest_nm > 0;
        
        SET @eta_count = @eta_count + @@ROWCOUNT;
    END
END
GO

PRINT '? Created sp_ProcessTrajectoryBatch (fixed ELEV column)';
GO

-- Quick verification
SELECT 
    'sp_ProcessTrajectoryBatch' AS [Procedure],
    CASE WHEN OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL THEN '?' ELSE '?' END AS [Status];
GO
