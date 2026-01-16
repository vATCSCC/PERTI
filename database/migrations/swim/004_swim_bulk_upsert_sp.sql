-- ============================================================================
-- Migration 004: SWIM Bulk Upsert Stored Procedure
-- Database: SWIM_API
-- Description: Fast JSON-based bulk upsert to replace row-by-row PHP sync
-- ============================================================================

-- Drop existing procedure if exists
IF OBJECT_ID('dbo.sp_Swim_BulkUpsert', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_Swim_BulkUpsert;
GO

CREATE PROCEDURE dbo.sp_Swim_BulkUpsert
    @Json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    DECLARE @inserted INT = 0;
    DECLARE @updated INT = 0;
    DECLARE @deleted INT = 0;
    DECLARE @start DATETIME2 = SYSUTCDATETIME();
    
    BEGIN TRY
        BEGIN TRANSACTION;
        
        -- Parse JSON into temp table for efficiency
        SELECT
            CAST(JSON_VALUE(j.value, '$.flight_uid') AS INT) AS flight_uid,
            JSON_VALUE(j.value, '$.flight_key') AS flight_key,
            JSON_VALUE(j.value, '$.gufi') AS gufi,
            JSON_VALUE(j.value, '$.callsign') AS callsign,
            CAST(JSON_VALUE(j.value, '$.cid') AS INT) AS cid,
            JSON_VALUE(j.value, '$.flight_id') AS flight_id,
            CAST(JSON_VALUE(j.value, '$.lat') AS DECIMAL(9,6)) AS lat,
            CAST(JSON_VALUE(j.value, '$.lon') AS DECIMAL(9,6)) AS lon,
            CAST(JSON_VALUE(j.value, '$.altitude_ft') AS INT) AS altitude_ft,
            CAST(JSON_VALUE(j.value, '$.heading_deg') AS SMALLINT) AS heading_deg,
            CAST(JSON_VALUE(j.value, '$.groundspeed_kts') AS SMALLINT) AS groundspeed_kts,
            CAST(JSON_VALUE(j.value, '$.vertical_rate_fpm') AS SMALLINT) AS vertical_rate_fpm,
            JSON_VALUE(j.value, '$.fp_dept_icao') AS fp_dept_icao,
            JSON_VALUE(j.value, '$.fp_dest_icao') AS fp_dest_icao,
            JSON_VALUE(j.value, '$.fp_alt_icao') AS fp_alt_icao,
            CAST(JSON_VALUE(j.value, '$.fp_altitude_ft') AS INT) AS fp_altitude_ft,
            CAST(JSON_VALUE(j.value, '$.fp_tas_kts') AS SMALLINT) AS fp_tas_kts,
            JSON_VALUE(j.value, '$.fp_route') AS fp_route,
            JSON_VALUE(j.value, '$.fp_remarks') AS fp_remarks,
            JSON_VALUE(j.value, '$.fp_rule') AS fp_rule,
            JSON_VALUE(j.value, '$.fp_dept_artcc') AS fp_dept_artcc,
            JSON_VALUE(j.value, '$.fp_dest_artcc') AS fp_dest_artcc,
            JSON_VALUE(j.value, '$.fp_dept_tracon') AS fp_dept_tracon,
            JSON_VALUE(j.value, '$.fp_dest_tracon') AS fp_dest_tracon,
            JSON_VALUE(j.value, '$.dfix') AS dfix,
            JSON_VALUE(j.value, '$.dp_name') AS dp_name,
            JSON_VALUE(j.value, '$.afix') AS afix,
            JSON_VALUE(j.value, '$.star_name') AS star_name,
            JSON_VALUE(j.value, '$.dep_runway') AS dep_runway,
            JSON_VALUE(j.value, '$.arr_runway') AS arr_runway,
            JSON_VALUE(j.value, '$.phase') AS phase,
            CAST(JSON_VALUE(j.value, '$.is_active') AS BIT) AS is_active,
            CAST(JSON_VALUE(j.value, '$.dist_to_dest_nm') AS DECIMAL(8,2)) AS dist_to_dest_nm,
            CAST(JSON_VALUE(j.value, '$.dist_flown_nm') AS DECIMAL(8,2)) AS dist_flown_nm,
            CAST(JSON_VALUE(j.value, '$.pct_complete') AS DECIMAL(5,2)) AS pct_complete,
            CAST(JSON_VALUE(j.value, '$.gcd_nm') AS DECIMAL(8,2)) AS gcd_nm,
            CAST(JSON_VALUE(j.value, '$.route_total_nm') AS DECIMAL(8,2)) AS route_total_nm,
            JSON_VALUE(j.value, '$.current_artcc') AS current_artcc,
            JSON_VALUE(j.value, '$.current_tracon') AS current_tracon,
            JSON_VALUE(j.value, '$.current_zone') AS current_zone,
            TRY_CAST(JSON_VALUE(j.value, '$.first_seen_utc') AS DATETIME2(0)) AS first_seen_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.last_seen_utc') AS DATETIME2(0)) AS last_seen_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.logon_time_utc') AS DATETIME2(0)) AS logon_time_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.eta_utc') AS DATETIME2(0)) AS eta_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.eta_runway_utc') AS DATETIME2(0)) AS eta_runway_utc,
            JSON_VALUE(j.value, '$.eta_source') AS eta_source,
            JSON_VALUE(j.value, '$.eta_method') AS eta_method,
            TRY_CAST(JSON_VALUE(j.value, '$.etd_utc') AS DATETIME2(0)) AS etd_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.out_utc') AS DATETIME2(0)) AS out_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.off_utc') AS DATETIME2(0)) AS off_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.on_utc') AS DATETIME2(0)) AS on_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.in_utc') AS DATETIME2(0)) AS in_utc,
            CAST(JSON_VALUE(j.value, '$.ete_minutes') AS SMALLINT) AS ete_minutes,
            TRY_CAST(JSON_VALUE(j.value, '$.ctd_utc') AS DATETIME2(0)) AS ctd_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.cta_utc') AS DATETIME2(0)) AS cta_utc,
            TRY_CAST(JSON_VALUE(j.value, '$.edct_utc') AS DATETIME2(0)) AS edct_utc,
            CAST(JSON_VALUE(j.value, '$.gs_held') AS BIT) AS gs_held,
            TRY_CAST(JSON_VALUE(j.value, '$.gs_release_utc') AS DATETIME2(0)) AS gs_release_utc,
            JSON_VALUE(j.value, '$.ctl_type') AS ctl_type,
            JSON_VALUE(j.value, '$.ctl_prgm') AS ctl_prgm,
            JSON_VALUE(j.value, '$.ctl_element') AS ctl_element,
            CAST(JSON_VALUE(j.value, '$.is_exempt') AS BIT) AS is_exempt,
            JSON_VALUE(j.value, '$.exempt_reason') AS exempt_reason,
            TRY_CAST(JSON_VALUE(j.value, '$.slot_time_utc') AS DATETIME2(0)) AS slot_time_utc,
            JSON_VALUE(j.value, '$.slot_status') AS slot_status,
            CAST(JSON_VALUE(j.value, '$.program_id') AS INT) AS program_id,
            CAST(JSON_VALUE(j.value, '$.slot_id') AS INT) AS slot_id,
            CAST(JSON_VALUE(j.value, '$.delay_minutes') AS SMALLINT) AS delay_minutes,
            JSON_VALUE(j.value, '$.delay_status') AS delay_status,
            JSON_VALUE(j.value, '$.aircraft_type') AS aircraft_type,
            JSON_VALUE(j.value, '$.aircraft_icao') AS aircraft_icao,
            JSON_VALUE(j.value, '$.aircraft_faa') AS aircraft_faa,
            JSON_VALUE(j.value, '$.weight_class') AS weight_class,
            JSON_VALUE(j.value, '$.wake_category') AS wake_category,
            JSON_VALUE(j.value, '$.engine_type') AS engine_type,
            JSON_VALUE(j.value, '$.airline_icao') AS airline_icao,
            JSON_VALUE(j.value, '$.airline_name') AS airline_name
        INTO #flights
        FROM OPENJSON(@Json) j;
        
        -- MERGE: Insert new, update existing
        MERGE dbo.swim_flights AS target
        USING #flights AS source ON target.flight_uid = source.flight_uid
        
        WHEN MATCHED THEN UPDATE SET
            flight_key = source.flight_key,
            gufi = source.gufi,
            callsign = source.callsign,
            cid = source.cid,
            flight_id = source.flight_id,
            lat = source.lat,
            lon = source.lon,
            altitude_ft = source.altitude_ft,
            heading_deg = source.heading_deg,
            groundspeed_kts = source.groundspeed_kts,
            vertical_rate_fpm = source.vertical_rate_fpm,
            fp_dept_icao = source.fp_dept_icao,
            fp_dest_icao = source.fp_dest_icao,
            fp_alt_icao = source.fp_alt_icao,
            fp_altitude_ft = source.fp_altitude_ft,
            fp_tas_kts = source.fp_tas_kts,
            fp_route = source.fp_route,
            fp_remarks = source.fp_remarks,
            fp_rule = source.fp_rule,
            fp_dept_artcc = source.fp_dept_artcc,
            fp_dest_artcc = source.fp_dest_artcc,
            fp_dept_tracon = source.fp_dept_tracon,
            fp_dest_tracon = source.fp_dest_tracon,
            dfix = source.dfix,
            dp_name = source.dp_name,
            afix = source.afix,
            star_name = source.star_name,
            dep_runway = source.dep_runway,
            arr_runway = source.arr_runway,
            phase = source.phase,
            is_active = source.is_active,
            dist_to_dest_nm = source.dist_to_dest_nm,
            dist_flown_nm = source.dist_flown_nm,
            pct_complete = source.pct_complete,
            gcd_nm = source.gcd_nm,
            route_total_nm = source.route_total_nm,
            current_artcc = source.current_artcc,
            current_tracon = source.current_tracon,
            current_zone = source.current_zone,
            first_seen_utc = source.first_seen_utc,
            last_seen_utc = source.last_seen_utc,
            logon_time_utc = source.logon_time_utc,
            eta_utc = source.eta_utc,
            eta_runway_utc = source.eta_runway_utc,
            eta_source = source.eta_source,
            eta_method = source.eta_method,
            etd_utc = source.etd_utc,
            out_utc = source.out_utc,
            off_utc = source.off_utc,
            on_utc = source.on_utc,
            in_utc = source.in_utc,
            ete_minutes = source.ete_minutes,
            ctd_utc = source.ctd_utc,
            cta_utc = source.cta_utc,
            edct_utc = source.edct_utc,
            gs_held = source.gs_held,
            gs_release_utc = source.gs_release_utc,
            ctl_type = source.ctl_type,
            ctl_prgm = source.ctl_prgm,
            ctl_element = source.ctl_element,
            is_exempt = source.is_exempt,
            exempt_reason = source.exempt_reason,
            slot_time_utc = source.slot_time_utc,
            slot_status = source.slot_status,
            program_id = source.program_id,
            slot_id = source.slot_id,
            delay_minutes = source.delay_minutes,
            delay_status = source.delay_status,
            aircraft_type = source.aircraft_type,
            aircraft_icao = source.aircraft_icao,
            aircraft_faa = source.aircraft_faa,
            weight_class = source.weight_class,
            wake_category = source.wake_category,
            engine_type = source.engine_type,
            airline_icao = source.airline_icao,
            airline_name = source.airline_name,
            last_sync_utc = SYSUTCDATETIME()
        
        WHEN NOT MATCHED BY TARGET THEN INSERT (
            flight_uid, flight_key, gufi, callsign, cid, flight_id,
            lat, lon, altitude_ft, heading_deg, groundspeed_kts, vertical_rate_fpm,
            fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_tas_kts,
            fp_route, fp_remarks, fp_rule,
            fp_dept_artcc, fp_dest_artcc, fp_dept_tracon, fp_dest_tracon,
            dfix, dp_name, afix, star_name, dep_runway, arr_runway,
            phase, is_active, dist_to_dest_nm, dist_flown_nm, pct_complete,
            gcd_nm, route_total_nm, current_artcc, current_tracon, current_zone,
            first_seen_utc, last_seen_utc, logon_time_utc,
            eta_utc, eta_runway_utc, eta_source, eta_method, etd_utc,
            out_utc, off_utc, on_utc, in_utc, ete_minutes,
            ctd_utc, cta_utc, edct_utc,
            gs_held, gs_release_utc, ctl_type, ctl_prgm, ctl_element,
            is_exempt, exempt_reason, slot_time_utc, slot_status,
            program_id, slot_id, delay_minutes, delay_status,
            aircraft_type, aircraft_icao, aircraft_faa, weight_class,
            wake_category, engine_type, airline_icao, airline_name,
            last_sync_utc
        ) VALUES (
            source.flight_uid, source.flight_key, source.gufi, source.callsign, source.cid, source.flight_id,
            source.lat, source.lon, source.altitude_ft, source.heading_deg, source.groundspeed_kts, source.vertical_rate_fpm,
            source.fp_dept_icao, source.fp_dest_icao, source.fp_alt_icao, source.fp_altitude_ft, source.fp_tas_kts,
            source.fp_route, source.fp_remarks, source.fp_rule,
            source.fp_dept_artcc, source.fp_dest_artcc, source.fp_dept_tracon, source.fp_dest_tracon,
            source.dfix, source.dp_name, source.afix, source.star_name, source.dep_runway, source.arr_runway,
            source.phase, source.is_active, source.dist_to_dest_nm, source.dist_flown_nm, source.pct_complete,
            source.gcd_nm, source.route_total_nm, source.current_artcc, source.current_tracon, source.current_zone,
            source.first_seen_utc, source.last_seen_utc, source.logon_time_utc,
            source.eta_utc, source.eta_runway_utc, source.eta_source, source.eta_method, source.etd_utc,
            source.out_utc, source.off_utc, source.on_utc, source.in_utc, source.ete_minutes,
            source.ctd_utc, source.cta_utc, source.edct_utc,
            source.gs_held, source.gs_release_utc, source.ctl_type, source.ctl_prgm, source.ctl_element,
            source.is_exempt, source.exempt_reason, source.slot_time_utc, source.slot_status,
            source.program_id, source.slot_id, source.delay_minutes, source.delay_status,
            source.aircraft_type, source.aircraft_icao, source.aircraft_faa, source.weight_class,
            source.wake_category, source.engine_type, source.airline_icao, source.airline_name,
            SYSUTCDATETIME()
        );
        
        -- Capture merge stats
        SET @inserted = (SELECT COUNT(*) FROM #flights f WHERE NOT EXISTS (SELECT 1 FROM dbo.swim_flights s WHERE s.flight_uid = f.flight_uid AND s.last_sync_utc < SYSUTCDATETIME()));
        SET @updated = (SELECT COUNT(*) FROM #flights) - @inserted;
        
        -- Delete stale flights (inactive for >2 hours and not in current sync)
        DELETE FROM dbo.swim_flights
        WHERE is_active = 0 
          AND last_sync_utc < DATEADD(HOUR, -2, SYSUTCDATETIME());
        
        SET @deleted = @@ROWCOUNT;
        
        DROP TABLE #flights;
        
        COMMIT TRANSACTION;
        
        -- Return stats
        SELECT 
            @inserted AS inserted,
            @updated AS updated,
            @deleted AS deleted,
            DATEDIFF(MILLISECOND, @start, SYSUTCDATETIME()) AS elapsed_ms;
            
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        IF OBJECT_ID('tempdb..#flights') IS NOT NULL DROP TABLE #flights;
        
        THROW;
    END CATCH;
END;
GO

PRINT 'Created sp_Swim_BulkUpsert';
GO
