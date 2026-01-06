-- ============================================================================
-- sp_Adl_RefreshFromVatsim_Normalized.sql
-- Bulk upsert from VATSIM JSON into normalized ADL tables
-- 
-- This replaces sp_Adl_RefreshFromVatsim for the new normalized schema.
-- Designed to be called by the existing vatsim_adl_daemon.php
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_Adl_RefreshFromVatsim_Normalized', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_Adl_RefreshFromVatsim_Normalized;
GO

CREATE PROCEDURE dbo.sp_Adl_RefreshFromVatsim_Normalized
    @Json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @now DATETIME2(0) = @start_time;
    DECLARE @pilot_count INT = 0;
    DECLARE @new_flights INT = 0;
    DECLARE @updated_flights INT = 0;
    DECLARE @routes_queued INT = 0;
    DECLARE @step_start DATETIME2(3);
    
    -- ========================================================================
    -- Step 1: Parse JSON into temp table
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    -- Parse pilots array from JSON
    SELECT
        CAST(p.cid AS INT) AS cid,
        CAST(p.callsign AS NVARCHAR(16)) AS callsign,
        CAST(p.latitude AS DECIMAL(10,7)) AS lat,
        CAST(p.longitude AS DECIMAL(11,7)) AS lon,
        CAST(p.altitude AS INT) AS altitude_ft,
        CAST(p.groundspeed AS INT) AS groundspeed_kts,
        CAST(p.heading AS SMALLINT) AS heading_deg,
        CAST(p.qnh_i_hg AS DECIMAL(5,2)) AS qnh_in_hg,
        CAST(p.qnh_mb AS INT) AS qnh_mb,
        CAST(p.[server] AS NVARCHAR(32)) AS flight_server,
        TRY_CAST(p.logon_time AS DATETIME2(0)) AS logon_time,
        -- Flight plan fields
        CAST(fp.flight_rules AS NCHAR(1)) AS fp_rule,
        CAST(fp.departure AS CHAR(4)) AS dept_icao,
        CAST(fp.arrival AS CHAR(4)) AS dest_icao,
        CAST(fp.alternate AS CHAR(4)) AS alt_icao,
        CAST(fp.route AS NVARCHAR(MAX)) AS route,
        CAST(fp.remarks AS NVARCHAR(MAX)) AS remarks,
        CAST(fp.altitude AS NVARCHAR(16)) AS altitude_filed_raw,
        CAST(fp.cruise_tas AS NVARCHAR(16)) AS tas_filed_raw,
        CAST(fp.deptime AS CHAR(4)) AS dep_time_z,
        CAST(fp.enroute_time AS NVARCHAR(8)) AS enroute_time_raw,
        CAST(fp.fuel_time AS NVARCHAR(8)) AS fuel_time_raw,
        CAST(fp.aircraft_faa AS NVARCHAR(32)) AS aircraft_faa,
        CAST(fp.aircraft_short AS NVARCHAR(8)) AS aircraft_short,
        -- Derived fields
        CAST(p.cid AS NVARCHAR) + '|' + CAST(p.callsign AS NVARCHAR(16)) + '|' + 
            ISNULL(CAST(fp.departure AS NVARCHAR(4)), '') + '|' + 
            ISNULL(CAST(fp.arrival AS NVARCHAR(4)), '') + '|' + 
            ISNULL(CAST(fp.deptime AS NVARCHAR(4)), '') AS flight_key,
        HASHBYTES('SHA2_256', ISNULL(CAST(fp.route AS NVARCHAR(MAX)), '') + '|' + ISNULL(CAST(fp.remarks AS NVARCHAR(MAX)), '')) AS route_hash
    INTO #pilots
    FROM OPENJSON(@Json, '$.pilots') 
    WITH (
        cid INT,
        callsign NVARCHAR(16),
        latitude FLOAT,
        longitude FLOAT,
        altitude INT,
        groundspeed INT,
        heading INT,
        qnh_i_hg FLOAT,
        qnh_mb INT,
        [server] NVARCHAR(32),
        logon_time NVARCHAR(32),
        flight_plan NVARCHAR(MAX) AS JSON
    ) AS p
    OUTER APPLY OPENJSON(p.flight_plan)
    WITH (
        flight_rules NVARCHAR(4),
        departure NVARCHAR(8),
        arrival NVARCHAR(8),
        alternate NVARCHAR(8),
        route NVARCHAR(MAX),
        remarks NVARCHAR(MAX),
        altitude NVARCHAR(16),
        cruise_tas NVARCHAR(16),
        deptime NVARCHAR(8),
        enroute_time NVARCHAR(8),
        fuel_time NVARCHAR(8),
        aircraft_faa NVARCHAR(32),
        aircraft_short NVARCHAR(8)
    ) AS fp;
    
    SET @pilot_count = @@ROWCOUNT;
    
    -- Create index for faster lookups
    CREATE CLUSTERED INDEX IX_pilots_key ON #pilots (flight_key);
    
    -- ========================================================================
    -- Step 2: Upsert adl_flight_core
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    -- Insert new flights
    INSERT INTO dbo.adl_flight_core (
        flight_key, cid, callsign, flight_id,
        phase, last_source, is_active,
        first_seen_utc, last_seen_utc, logon_time_utc,
        adl_date, adl_time, snapshot_utc
    )
    SELECT 
        p.flight_key, p.cid, p.callsign, p.flight_server,
        CASE 
            WHEN p.groundspeed_kts < 50 THEN 'taxiing'
            WHEN p.altitude_ft < 10000 THEN 'departed'
            ELSE 'enroute'
        END,
        'vatsim', 1,
        @now, @now, p.logon_time,
        CAST(@now AS DATE), CAST(@now AS TIME), @now
    FROM #pilots p
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_core c WHERE c.flight_key = p.flight_key
    );
    
    SET @new_flights = @@ROWCOUNT;
    
    -- Update existing flights
    UPDATE c
    SET c.last_seen_utc = @now,
        c.snapshot_utc = @now,
        c.last_source = 'vatsim',
        c.phase = CASE 
            WHEN p.groundspeed_kts < 50 THEN 'taxiing'
            WHEN p.altitude_ft < 10000 AND p.altitude_ft > (SELECT altitude_ft FROM dbo.adl_flight_position WHERE flight_uid = c.flight_uid) THEN 'departed'
            WHEN p.altitude_ft < 10000 THEN 'descending'
            ELSE 'enroute'
        END,
        c.flight_id = COALESCE(p.flight_server, c.flight_id)
    FROM dbo.adl_flight_core c
    INNER JOIN #pilots p ON c.flight_key = p.flight_key;
    
    SET @updated_flights = @@ROWCOUNT;
    
    -- Add flight_uid to temp table for subsequent joins
    ALTER TABLE #pilots ADD flight_uid BIGINT NULL;
    
    UPDATE p
    SET p.flight_uid = c.flight_uid
    FROM #pilots p
    INNER JOIN dbo.adl_flight_core c ON c.flight_key = p.flight_key;
    
    -- ========================================================================
    -- Step 3: Upsert adl_flight_position
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    MERGE dbo.adl_flight_position AS target
    USING (
        SELECT flight_uid, lat, lon, altitude_ft, groundspeed_kts, 
               heading_deg, qnh_in_hg, qnh_mb
        FROM #pilots WHERE flight_uid IS NOT NULL
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            lat = source.lat,
            lon = source.lon,
            position_geo = geography::Point(source.lat, source.lon, 4326),
            altitude_ft = source.altitude_ft,
            groundspeed_kts = source.groundspeed_kts,
            heading_deg = source.heading_deg,
            qnh_in_hg = source.qnh_in_hg,
            qnh_mb = source.qnh_mb,
            position_updated_utc = @now
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, lat, lon, position_geo, altitude_ft, groundspeed_kts,
                heading_deg, qnh_in_hg, qnh_mb, position_updated_utc)
        VALUES (source.flight_uid, source.lat, source.lon,
                geography::Point(source.lat, source.lon, 4326),
                source.altitude_ft, source.groundspeed_kts, source.heading_deg,
                source.qnh_in_hg, source.qnh_mb, @now);
    
    -- ========================================================================
    -- Step 4: Upsert adl_flight_plan (track route changes)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    -- Identify flights with route changes
    SELECT p.flight_uid, p.route, p.route_hash
    INTO #route_changes
    FROM #pilots p
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = p.flight_uid
    WHERE p.flight_uid IS NOT NULL
      AND p.route IS NOT NULL
      AND LEN(LTRIM(RTRIM(p.route))) > 0
      AND (fp.fp_hash IS NULL OR fp.fp_hash != p.route_hash);
    
    SET @routes_queued = @@ROWCOUNT;
    
    -- Upsert flight plans
    MERGE dbo.adl_flight_plan AS target
    USING (
        SELECT 
            flight_uid, fp_rule, dept_icao, dest_icao, alt_icao,
            route, remarks, route_hash,
            -- Parse altitude (handle FL350 or 35000)
            CASE 
                WHEN altitude_filed_raw LIKE 'FL%' THEN TRY_CAST(SUBSTRING(altitude_filed_raw, 3, 10) AS INT) * 100
                ELSE TRY_CAST(altitude_filed_raw AS INT)
            END AS altitude_ft,
            -- Parse TAS (handle N0450 or 450)
            CASE 
                WHEN tas_filed_raw LIKE 'N%' THEN TRY_CAST(SUBSTRING(tas_filed_raw, 2, 10) AS INT)
                ELSE TRY_CAST(tas_filed_raw AS INT)
            END AS tas_kts,
            dep_time_z,
            -- Parse enroute time (HHMM to minutes)
            CASE 
                WHEN LEN(enroute_time_raw) = 4 THEN TRY_CAST(LEFT(enroute_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(enroute_time_raw, 2) AS INT)
                ELSE TRY_CAST(enroute_time_raw AS INT)
            END AS enroute_minutes,
            -- Parse fuel time
            CASE 
                WHEN LEN(fuel_time_raw) = 4 THEN TRY_CAST(LEFT(fuel_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(fuel_time_raw, 2) AS INT)
                ELSE TRY_CAST(fuel_time_raw AS INT)
            END AS fuel_minutes,
            -- Parse aircraft type from H/B738/L format
            CASE 
                WHEN aircraft_faa LIKE '%/%' THEN PARSENAME(REPLACE(aircraft_faa, '/', '.'), 2)
                ELSE COALESCE(aircraft_short, aircraft_faa)
            END AS aircraft_type
        FROM #pilots 
        WHERE flight_uid IS NOT NULL
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            fp_rule = COALESCE(source.fp_rule, target.fp_rule),
            fp_dept_icao = COALESCE(source.dept_icao, target.fp_dept_icao),
            fp_dest_icao = COALESCE(source.dest_icao, target.fp_dest_icao),
            fp_alt_icao = COALESCE(source.alt_icao, target.fp_alt_icao),
            fp_route = COALESCE(source.route, target.fp_route),
            fp_remarks = COALESCE(source.remarks, target.fp_remarks),
            fp_altitude_ft = COALESCE(source.altitude_ft, target.fp_altitude_ft),
            fp_tas_kts = COALESCE(source.tas_kts, target.fp_tas_kts),
            fp_dept_time_z = COALESCE(source.dep_time_z, target.fp_dept_time_z),
            fp_enroute_minutes = COALESCE(source.enroute_minutes, target.fp_enroute_minutes),
            fp_fuel_minutes = COALESCE(source.fuel_minutes, target.fp_fuel_minutes),
            aircraft_type = COALESCE(source.aircraft_type, target.aircraft_type),
            -- Reset parse status if route changed
            fp_hash = CASE 
                WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash 
                THEN source.route_hash 
                ELSE target.fp_hash 
            END,
            fp_updated_utc = CASE 
                WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash 
                THEN @now 
                ELSE target.fp_updated_utc 
            END,
            parse_status = CASE 
                WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash 
                THEN 'PENDING' 
                ELSE target.parse_status 
            END,
            route_geometry = CASE 
                WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash 
                THEN NULL 
                ELSE target.route_geometry 
            END
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
                fp_route, fp_remarks, fp_altitude_ft, fp_tas_kts, fp_dept_time_z,
                fp_enroute_minutes, fp_fuel_minutes, aircraft_type,
                fp_hash, fp_updated_utc, parse_status)
        VALUES (source.flight_uid, source.fp_rule, source.dept_icao, source.dest_icao, source.alt_icao,
                source.route, source.remarks, source.altitude_ft, source.tas_kts, source.dep_time_z,
                source.enroute_minutes, source.fuel_minutes, source.aircraft_type,
                source.route_hash, @now, 'PENDING');
    
    -- ========================================================================
    -- Step 5: Queue routes for parsing
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    -- Insert/update parse queue for changed routes
    MERGE dbo.adl_parse_queue AS target
    USING (
        SELECT 
            rc.flight_uid,
            dbo.fn_GetParseTier(p.dept_icao, p.dest_icao, p.lat, p.lon) AS parse_tier
        FROM #route_changes rc
        INNER JOIN #pilots p ON p.flight_uid = rc.flight_uid
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            parse_tier = source.parse_tier,
            status = 'PENDING',
            queued_utc = @now,
            attempts = 0,
            error_message = NULL
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
        VALUES (source.flight_uid, source.parse_tier, 'PENDING', @now, @now);
    
    -- ========================================================================
    -- Step 6: Upsert adl_flight_aircraft
    -- ========================================================================
    MERGE dbo.adl_flight_aircraft AS target
    USING (
        SELECT 
            flight_uid,
            CASE 
                WHEN aircraft_faa LIKE '%/%' THEN PARSENAME(REPLACE(aircraft_faa, '/', '.'), 2)
                ELSE COALESCE(aircraft_short, aircraft_faa)
            END AS aircraft_icao,
            -- Weight class derivation
            CASE 
                WHEN COALESCE(aircraft_short, aircraft_faa) IN ('A388', 'A380', 'B748', 'B744', 'AN25') THEN 'J'
                WHEN COALESCE(aircraft_short, aircraft_faa) IN ('B77W', 'B77L', 'B772', 'B773', 'A359', 'A35K', 'A346', 'A345', 'A343', 'A342', 'A333', 'A332', 'B788', 'B789', 'B78X', 'B764', 'B763', 'B762', 'MD11', 'DC10', 'IL96', 'B752', 'B753') THEN 'H'
                WHEN COALESCE(aircraft_short, aircraft_faa) LIKE 'C1%' OR COALESCE(aircraft_short, aircraft_faa) LIKE 'C2%' OR COALESCE(aircraft_short, aircraft_faa) LIKE 'PA%' THEN 'S'
                ELSE 'L'
            END AS weight_class
        FROM #pilots 
        WHERE flight_uid IS NOT NULL AND (aircraft_faa IS NOT NULL OR aircraft_short IS NOT NULL)
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            aircraft_icao = source.aircraft_icao,
            weight_class = source.weight_class,
            wake_category = CASE source.weight_class WHEN 'J' THEN 'SUPER' WHEN 'H' THEN 'HEAVY' WHEN 'S' THEN 'LIGHT' ELSE 'MEDIUM' END,
            aircraft_updated_utc = @now
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, aircraft_icao, weight_class, wake_category, aircraft_updated_utc)
        VALUES (source.flight_uid, source.aircraft_icao, source.weight_class,
                CASE source.weight_class WHEN 'J' THEN 'SUPER' WHEN 'H' THEN 'HEAVY' WHEN 'S' THEN 'LIGHT' ELSE 'MEDIUM' END,
                @now);
    
    -- ========================================================================
    -- Step 7: Mark inactive flights (not seen in this refresh)
    -- ========================================================================
    UPDATE dbo.adl_flight_core
    SET is_active = 0,
        phase = 'arrived'
    WHERE is_active = 1
      AND last_seen_utc < DATEADD(MINUTE, -5, @now);
    
    -- ========================================================================
    -- Cleanup
    -- ========================================================================
    DROP TABLE IF EXISTS #route_changes;
    DROP TABLE IF EXISTS #pilots;
    
    -- ========================================================================
    -- Log run stats (optional - matches your existing adl_run_log pattern)
    -- ========================================================================
    DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
    
    -- Return stats
    SELECT 
        @pilot_count AS pilots_received,
        @new_flights AS new_flights,
        @updated_flights AS updated_flights,
        @routes_queued AS routes_queued,
        @elapsed_ms AS elapsed_ms;
    
END;
GO

PRINT 'sp_Adl_RefreshFromVatsim_Normalized created successfully.';
PRINT '';
PRINT 'To use with existing daemon, update vatsim_adl_daemon.php:';
PRINT '  Change: EXEC [dbo].[sp_Adl_RefreshFromVatsim] @Json = ?';
PRINT '  To:     EXEC [dbo].[sp_Adl_RefreshFromVatsim_Normalized] @Json = ?';
GO
