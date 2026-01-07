-- ============================================================================
-- sp_Adl_RefreshFromVatsim_Normalized V8.6 - Waypoint ETA Integration
-- 
-- Changes from V8.5:
--   - Added Step 8c: Waypoint ETA Calculation
--   - Calculates ETA at each waypoint for sector entry prediction
--   - Returns waypoint_etas count in stats
--
-- Full Step List:
--   1    - Parse JSON into temp table
--   1b   - Enrich with airport data
--   2    - Upsert adl_flight_core
--   2b   - Create adl_flight_times rows
--   3    - Upsert adl_flight_position
--   4    - Detect route changes, upsert flight plans
--   4b   - ETD/STD Calculation (V8.4)
--   4c   - SimBrief/ICAO Flight Plan Parsing (V8.5)
--   5    - Queue routes for parsing
--   6    - Upsert adl_flight_aircraft
--   7    - Mark inactive flights
--   8    - Process Trajectory & ETA Calculations
--   8b   - Update arrival buckets
--   8c   - Waypoint ETA Calculation (V8.6) <-- NEW
--   9    - Zone Detection for OOOI
--   10   - Boundary Detection for ARTCC/Sector/TRACON
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
    DECLARE @today DATE = CAST(@now AS DATE);
    DECLARE @pilot_count INT = 0;
    DECLARE @new_flights INT = 0;
    DECLARE @updated_flights INT = 0;
    DECLARE @routes_queued INT = 0;
    -- ETA/Trajectory counters
    DECLARE @eta_count INT = 0;
    DECLARE @traj_count INT = 0;
    -- ETD counter (V8.4)
    DECLARE @etd_count INT = 0;
    -- SimBrief counter (V8.5)
    DECLARE @simbrief_parsed INT = 0;
    -- Waypoint ETA counter (V8.6)
    DECLARE @waypoint_etas INT = 0;
    -- Zone detection counter
    DECLARE @zone_transitions INT = 0;
    -- Boundary detection counters (V8.3)
    DECLARE @boundary_transitions INT = 0;
    DECLARE @boundary_flights INT = 0;
    
    -- ========================================================================
    -- Step 1: Parse JSON into temp table
    -- ========================================================================
    
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
        CAST(fp.aircraft_faa AS NVARCHAR(32)) AS aircraft_faa_raw,
        CAST(fp.aircraft_short AS NVARCHAR(8)) AS aircraft_short,
        CAST(fp.dof AS NVARCHAR(16)) AS fp_dof_raw,
        -- Derived: flight_key
        CAST(p.cid AS NVARCHAR) + '|' + CAST(p.callsign AS NVARCHAR(16)) + '|' + 
            ISNULL(CAST(fp.departure AS NVARCHAR(4)), '') + '|' + 
            ISNULL(CAST(fp.arrival AS NVARCHAR(4)), '') + '|' + 
            ISNULL(CAST(fp.deptime AS NVARCHAR(4)), '') AS flight_key,
        -- Derived: route_hash
        HASHBYTES('SHA2_256', ISNULL(CAST(fp.route AS NVARCHAR(MAX)), '') + '|' + ISNULL(CAST(fp.remarks AS NVARCHAR(MAX)), '')) AS route_hash,
        -- Derived: airline_icao
        CASE 
            WHEN LEN(p.callsign) >= 4 AND p.callsign LIKE '[A-Z][A-Z][A-Z][0-9]%'
            THEN LEFT(p.callsign, 3)
            ELSE NULL
        END AS airline_icao,
        -- Placeholders for enrichment
        CAST(NULL AS DECIMAL(10,7)) AS dept_lat,
        CAST(NULL AS DECIMAL(11,7)) AS dept_lon,
        CAST(NULL AS NVARCHAR(8)) AS dept_artcc,
        CAST(NULL AS NVARCHAR(64)) AS dept_tracon,
        CAST(NULL AS DECIMAL(10,7)) AS dest_lat,
        CAST(NULL AS DECIMAL(11,7)) AS dest_lon,
        CAST(NULL AS NVARCHAR(8)) AS dest_artcc,
        CAST(NULL AS NVARCHAR(64)) AS dest_tracon,
        CAST(NULL AS DECIMAL(10,2)) AS gcd_nm,
        CAST(NULL AS DECIMAL(10,2)) AS dist_to_dest_nm,
        CAST(NULL AS DECIMAL(10,2)) AS dist_flown_nm,
        CAST(NULL AS DECIMAL(5,2)) AS pct_complete,
        CAST(NULL AS BIGINT) AS flight_uid
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
        aircraft_short NVARCHAR(8),
        dof NVARCHAR(16)
    ) AS fp;
    
    SET @pilot_count = @@ROWCOUNT;
    
    -- Indexes for performance
    CREATE CLUSTERED INDEX IX_pilots_key ON #pilots (flight_key);
    CREATE NONCLUSTERED INDEX IX_pilots_dept ON #pilots (dept_icao) WHERE dept_icao IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_pilots_dest ON #pilots (dest_icao) WHERE dest_icao IS NOT NULL;
    
    -- ========================================================================
    -- Step 1b: Enrich with airport data
    -- ========================================================================
    
    UPDATE p
    SET p.dept_lat = a.LAT_DECIMAL,
        p.dept_lon = a.LONG_DECIMAL,
        p.dept_artcc = a.RESP_ARTCC_ID,
        p.dept_tracon = COALESCE(a.Approach_ID, a.Departure_ID, a.Approach_Departure_ID)
    FROM #pilots p
    INNER JOIN dbo.apts a ON a.ICAO_ID = p.dept_icao
    WHERE p.dept_icao IS NOT NULL;
    
    UPDATE p
    SET p.dest_lat = a.LAT_DECIMAL,
        p.dest_lon = a.LONG_DECIMAL,
        p.dest_artcc = a.RESP_ARTCC_ID,
        p.dest_tracon = COALESCE(a.Approach_ID, a.Departure_ID, a.Approach_Departure_ID)
    FROM #pilots p
    INNER JOIN dbo.apts a ON a.ICAO_ID = p.dest_icao
    WHERE p.dest_icao IS NOT NULL;
    
    UPDATE #pilots
    SET 
        gcd_nm = CASE 
            WHEN dept_lat IS NOT NULL AND dest_lat IS NOT NULL 
            THEN geography::Point(dept_lat, dept_lon, 4326).STDistance(
                 geography::Point(dest_lat, dest_lon, 4326)) / 1852.0
            ELSE NULL
        END,
        dist_to_dest_nm = CASE 
            WHEN lat IS NOT NULL AND dest_lat IS NOT NULL 
            THEN geography::Point(lat, lon, 4326).STDistance(
                 geography::Point(dest_lat, dest_lon, 4326)) / 1852.0
            ELSE NULL
        END,
        dist_flown_nm = CASE 
            WHEN lat IS NOT NULL AND dept_lat IS NOT NULL 
            THEN geography::Point(dept_lat, dept_lon, 4326).STDistance(
                 geography::Point(lat, lon, 4326)) / 1852.0
            ELSE NULL
        END
    WHERE lat IS NOT NULL;
    
    UPDATE #pilots
    SET pct_complete = CASE 
        WHEN gcd_nm > 10 AND dist_flown_nm IS NOT NULL
        THEN CASE 
            WHEN (dist_flown_nm / gcd_nm) * 100.0 > 100.0 THEN 100.0
            ELSE CAST((dist_flown_nm / gcd_nm) * 100.0 AS DECIMAL(5,2))
        END
        ELSE NULL
    END
    WHERE gcd_nm IS NOT NULL;
    
    -- ========================================================================
    -- Step 2: Upsert adl_flight_core
    -- ========================================================================
    
    INSERT INTO dbo.adl_flight_core (
        flight_key, cid, callsign, flight_id,
        phase, flight_status, last_source, is_active,
        first_seen_utc, last_seen_utc, logon_time_utc,
        adl_date, adl_time, snapshot_utc
    )
    SELECT 
        p.flight_key, p.cid, p.callsign, p.flight_server,
        CASE 
            WHEN p.lat IS NULL THEN 'prefile'
            WHEN p.groundspeed_kts < 50 THEN 'taxiing'
            WHEN p.altitude_ft < 10000 THEN 'departed'
            ELSE 'enroute'
        END,
        CASE 
            WHEN p.lat IS NULL THEN 'PROPOSED'
            WHEN p.groundspeed_kts < 50 AND ISNULL(p.pct_complete, 0) < 10 THEN 'DEPARTING'
            WHEN ISNULL(p.pct_complete, 0) >= 90 THEN 'ARRIVING'
            ELSE 'ACTIVE'
        END,
        'vatsim', 1,
        @now, @now, p.logon_time,
        CAST(@now AS DATE), CAST(@now AS TIME), @now
    FROM #pilots p
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_core c WHERE c.flight_key = p.flight_key
    );
    
    SET @new_flights = @@ROWCOUNT;
    
    UPDATE c
    SET c.last_seen_utc = @now,
        c.snapshot_utc = @now,
        c.last_source = 'vatsim',
        c.phase = CASE 
            WHEN p.lat IS NULL THEN 'prefile'
            WHEN p.groundspeed_kts < 50 THEN 'taxiing'
            WHEN p.altitude_ft < 10000 AND p.altitude_ft > ISNULL(pos.altitude_ft, 0) THEN 'departed'
            WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) > 50 THEN 'descending'
            ELSE 'enroute'
        END,
        c.flight_status = CASE 
            WHEN p.lat IS NULL THEN 'PROPOSED'
            WHEN p.groundspeed_kts < 50 AND ISNULL(p.pct_complete, 0) < 10 THEN 'DEPARTING'
            WHEN ISNULL(p.pct_complete, 0) >= 90 THEN 'ARRIVING'
            ELSE 'ACTIVE'
        END,
        c.flight_id = COALESCE(p.flight_server, c.flight_id)
    FROM dbo.adl_flight_core c
    INNER JOIN #pilots p ON c.flight_key = p.flight_key
    LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid;
    
    SET @updated_flights = @@ROWCOUNT;
    
    UPDATE p
    SET p.flight_uid = c.flight_uid
    FROM #pilots p
    INNER JOIN dbo.adl_flight_core c ON c.flight_key = p.flight_key;
    
    -- ========================================================================
    -- Step 2b: Create adl_flight_times rows for new flights
    -- ========================================================================
    
    INSERT INTO dbo.adl_flight_times (flight_uid)
    SELECT c.flight_uid
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM dbo.adl_flight_times ft WHERE ft.flight_uid = c.flight_uid);
    
    -- ========================================================================
    -- Step 3: Upsert adl_flight_position
    -- ========================================================================
    
    MERGE dbo.adl_flight_position AS target
    USING (
        SELECT flight_uid, lat, lon, altitude_ft, groundspeed_kts, 
               heading_deg, qnh_in_hg, qnh_mb,
               dist_to_dest_nm, dist_flown_nm, pct_complete
        FROM #pilots 
        WHERE flight_uid IS NOT NULL AND lat IS NOT NULL
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
            dist_to_dest_nm = source.dist_to_dest_nm,
            dist_flown_nm = source.dist_flown_nm,
            pct_complete = source.pct_complete,
            position_updated_utc = @now
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, lat, lon, position_geo, altitude_ft, groundspeed_kts,
                heading_deg, qnh_in_hg, qnh_mb, dist_to_dest_nm, dist_flown_nm, 
                pct_complete, position_updated_utc)
        VALUES (source.flight_uid, source.lat, source.lon, 
                geography::Point(source.lat, source.lon, 4326),
                source.altitude_ft, source.groundspeed_kts, source.heading_deg,
                source.qnh_in_hg, source.qnh_mb, source.dist_to_dest_nm, 
                source.dist_flown_nm, source.pct_complete, @now);
    
    -- ========================================================================
    -- Step 4: Detect route changes and upsert flight plans
    -- ========================================================================
    
    SELECT p.flight_uid, p.route_hash
    INTO #route_changes
    FROM #pilots p
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = p.flight_uid
    WHERE p.flight_uid IS NOT NULL
      AND p.route IS NOT NULL
      AND LEN(LTRIM(RTRIM(p.route))) > 0
      AND (fp.fp_hash IS NULL OR fp.fp_hash != p.route_hash);
    
    SET @routes_queued = @@ROWCOUNT;
    
    MERGE dbo.adl_flight_plan AS target
    USING (
        SELECT 
            p.flight_uid, p.fp_rule, p.dept_icao, p.dest_icao, p.alt_icao,
            p.dept_artcc, p.dept_tracon, p.dest_artcc, p.dest_tracon,
            p.route, p.remarks, p.route_hash, p.gcd_nm,
            p.aircraft_faa_raw AS aircraft_equip,
            CASE 
                WHEN p.altitude_filed_raw LIKE 'FL%' THEN TRY_CAST(SUBSTRING(p.altitude_filed_raw, 3, 10) AS INT) * 100
                WHEN p.altitude_filed_raw LIKE 'F%' THEN TRY_CAST(SUBSTRING(p.altitude_filed_raw, 2, 10) AS INT) * 100
                ELSE TRY_CAST(p.altitude_filed_raw AS INT)
            END AS altitude_ft,
            CASE 
                WHEN p.tas_filed_raw LIKE 'N%' THEN TRY_CAST(SUBSTRING(p.tas_filed_raw, 2, 10) AS INT)
                ELSE TRY_CAST(p.tas_filed_raw AS INT)
            END AS tas_kts,
            p.dep_time_z,
            CASE 
                WHEN LEN(p.enroute_time_raw) = 4 
                THEN TRY_CAST(LEFT(p.enroute_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(p.enroute_time_raw, 2) AS INT)
                ELSE TRY_CAST(p.enroute_time_raw AS INT)
            END AS enroute_minutes,
            CASE 
                WHEN LEN(p.fuel_time_raw) = 4 
                THEN TRY_CAST(LEFT(p.fuel_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(p.fuel_time_raw, 2) AS INT)
                ELSE TRY_CAST(p.fuel_time_raw AS INT)
            END AS fuel_minutes,
            CASE
                WHEN p.aircraft_faa_raw LIKE '%/%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2)
                WHEN p.aircraft_faa_raw LIKE '%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2)
                ELSE COALESCE(p.aircraft_short, p.aircraft_faa_raw)
            END AS aircraft_type
        FROM #pilots p
        WHERE p.flight_uid IS NOT NULL
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            fp_rule = COALESCE(source.fp_rule, target.fp_rule),
            fp_dept_icao = COALESCE(source.dept_icao, target.fp_dept_icao),
            fp_dest_icao = COALESCE(source.dest_icao, target.fp_dest_icao),
            fp_alt_icao = COALESCE(source.alt_icao, target.fp_alt_icao),
            fp_dept_artcc = COALESCE(source.dept_artcc, target.fp_dept_artcc),
            fp_dept_tracon = COALESCE(source.dept_tracon, target.fp_dept_tracon),
            fp_dest_artcc = COALESCE(source.dest_artcc, target.fp_dest_artcc),
            fp_dest_tracon = COALESCE(source.dest_tracon, target.fp_dest_tracon),
            fp_route = COALESCE(source.route, target.fp_route),
            fp_remarks = COALESCE(source.remarks, target.fp_remarks),
            fp_altitude_ft = COALESCE(source.altitude_ft, target.fp_altitude_ft),
            fp_tas_kts = COALESCE(source.tas_kts, target.fp_tas_kts),
            fp_dept_time_z = COALESCE(source.dep_time_z, target.fp_dept_time_z),
            fp_enroute_minutes = COALESCE(source.enroute_minutes, target.fp_enroute_minutes),
            fp_fuel_minutes = COALESCE(source.fuel_minutes, target.fp_fuel_minutes),
            aircraft_type = COALESCE(source.aircraft_type, target.aircraft_type),
            aircraft_equip = COALESCE(source.aircraft_equip, target.aircraft_equip),
            gcd_nm = COALESCE(source.gcd_nm, target.gcd_nm),
            fp_hash = CASE WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash THEN source.route_hash ELSE target.fp_hash END,
            fp_updated_utc = CASE WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash THEN @now ELSE target.fp_updated_utc END,
            parse_status = CASE WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash THEN 'PENDING' ELSE target.parse_status END,
            route_geometry = CASE WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash THEN NULL ELSE target.route_geometry END,
            -- Reset SimBrief flag on route change (V8.5)
            is_simbrief = CASE WHEN target.fp_hash IS NULL OR target.fp_hash != source.route_hash THEN 0 ELSE target.is_simbrief END
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
                fp_dept_artcc, fp_dept_tracon, fp_dest_artcc, fp_dest_tracon,
                fp_route, fp_remarks, fp_altitude_ft, fp_tas_kts, fp_dept_time_z,
                fp_enroute_minutes, fp_fuel_minutes, aircraft_type, aircraft_equip,
                gcd_nm, fp_hash, fp_updated_utc, parse_status, is_simbrief)
        VALUES (source.flight_uid, source.fp_rule, source.dept_icao, source.dest_icao, source.alt_icao,
                source.dept_artcc, source.dept_tracon, source.dest_artcc, source.dest_tracon,
                source.route, source.remarks, source.altitude_ft, source.tas_kts, source.dep_time_z,
                source.enroute_minutes, source.fuel_minutes, source.aircraft_type, source.aircraft_equip,
                source.gcd_nm, source.route_hash, @now, 'PENDING', 0);
    
    -- ========================================================================
    -- Step 4b: ETD/STD Calculation (V8.4)
    -- ========================================================================
    
    ;WITH ETDCalc AS (
        SELECT 
            p.flight_uid, c.phase, fp.fp_dept_time_z AS deptime, fp.fp_enroute_minutes,
            fp.fp_remarks AS remarks, p.fp_dof_raw,
            COALESCE(
                CASE
                    WHEN p.fp_dof_raw IS NULL OR LTRIM(RTRIM(p.fp_dof_raw)) = '' THEN NULL
                    WHEN LEN(LTRIM(RTRIM(p.fp_dof_raw))) = 10 THEN TRY_CONVERT(DATE, LTRIM(RTRIM(p.fp_dof_raw)))
                    WHEN LEN(LTRIM(RTRIM(p.fp_dof_raw))) = 8 AND LTRIM(RTRIM(p.fp_dof_raw)) NOT LIKE '%[^0-9]%'
                    THEN TRY_CONVERT(DATE, LTRIM(RTRIM(p.fp_dof_raw)), 112)
                    WHEN LEN(LTRIM(RTRIM(p.fp_dof_raw))) = 6 AND LTRIM(RTRIM(p.fp_dof_raw)) NOT LIKE '%[^0-9]%'
                    THEN TRY_CONVERT(DATE, CONCAT('20', LTRIM(RTRIM(p.fp_dof_raw))), 112)
                    ELSE NULL
                END,
                CASE
                    WHEN fp.fp_remarks IS NULL OR CHARINDEX('DOF/', UPPER(fp.fp_remarks)) = 0 THEN NULL
                    ELSE COALESCE(
                        CASE WHEN SUBSTRING(fp.fp_remarks, CHARINDEX('DOF/', UPPER(fp.fp_remarks)) + 4, 8) NOT LIKE '%[^0-9]%'
                             THEN TRY_CONVERT(DATE, SUBSTRING(fp.fp_remarks, CHARINDEX('DOF/', UPPER(fp.fp_remarks)) + 4, 8), 112) ELSE NULL END,
                        CASE WHEN SUBSTRING(fp.fp_remarks, CHARINDEX('DOF/', UPPER(fp.fp_remarks)) + 4, 6) NOT LIKE '%[^0-9]%'
                             THEN TRY_CONVERT(DATE, CONCAT('20', SUBSTRING(fp.fp_remarks, CHARINDEX('DOF/', UPPER(fp.fp_remarks)) + 4, 6)), 112) ELSE NULL END
                    )
                END
            ) AS fp_dof_utc,
            CASE WHEN fp.fp_dept_time_z IS NOT NULL AND LEN(fp.fp_dept_time_z) = 4 AND fp.fp_dept_time_z NOT LIKE '%[^0-9]%'
                 THEN CONVERT(INT, LEFT(fp.fp_dept_time_z, 2)) * 60 + CONVERT(INT, RIGHT(fp.fp_dept_time_z, 2)) ELSE NULL END AS deptime_minutes
        FROM #pilots p
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = p.flight_uid
        WHERE p.flight_uid IS NOT NULL
    ),
    ETDResolved AS (
        SELECT flight_uid, phase, deptime, deptime_minutes, fp_dof_utc, fp_enroute_minutes,
            CASE
                WHEN fp_dof_utc IS NOT NULL AND deptime IS NOT NULL AND LEN(deptime) = 4 AND deptime NOT LIKE '%[^0-9]%' THEN 'D'
                WHEN phase = 'prefile' THEN 'N'
                WHEN phase <> 'prefile' AND deptime IS NOT NULL AND LEN(deptime) = 4 AND deptime NOT LIKE '%[^0-9]%' THEN 'P'
                ELSE NULL
            END AS etd_source,
            CASE
                WHEN deptime IS NULL OR LEN(deptime) <> 4 OR deptime LIKE '%[^0-9]%' THEN NULL
                WHEN fp_dof_utc IS NOT NULL
                THEN DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(fp_dof_utc AS DATETIME2(0)))
                WHEN phase IN ('prefile', 'departed', 'taxiing')
                THEN CASE 
                    WHEN DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(@today AS DATETIME2(0))) >= @now
                    THEN DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(@today AS DATETIME2(0)))
                    ELSE DATEADD(DAY, 1, DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(@today AS DATETIME2(0))))
                END
                ELSE CASE 
                    WHEN DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(@today AS DATETIME2(0))) <= @now
                    THEN DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(@today AS DATETIME2(0)))
                    ELSE DATEADD(DAY, -1, DATEADD(MINUTE, CONVERT(INT, LEFT(deptime, 2)) * 60 + CONVERT(INT, RIGHT(deptime, 2)), CAST(@today AS DATETIME2(0))))
                END
            END AS etd_utc
        FROM ETDCalc
    )
    UPDATE ft
    SET ft.etd_utc = er.etd_utc, ft.std_utc = er.etd_utc, ft.etd_source = er.etd_source,
        ft.etd_epoch = CASE WHEN er.etd_utc IS NOT NULL THEN DATEDIFF(SECOND, '1970-01-01', er.etd_utc) ELSE NULL END,
        ft.ete_minutes = er.fp_enroute_minutes,
        ft.departure_bucket_utc = CASE WHEN er.etd_utc IS NOT NULL
            THEN DATEADD(MINUTE, CASE WHEN DATEPART(MINUTE, er.etd_utc) < 15 THEN 0 WHEN DATEPART(MINUTE, er.etd_utc) < 30 THEN 15 WHEN DATEPART(MINUTE, er.etd_utc) < 45 THEN 30 ELSE 45 END, DATEADD(HOUR, DATEDIFF(HOUR, 0, er.etd_utc), 0))
            ELSE NULL END,
        ft.times_updated_utc = @now
    FROM dbo.adl_flight_times ft
    INNER JOIN ETDResolved er ON er.flight_uid = ft.flight_uid
    WHERE er.etd_utc IS NOT NULL AND (ft.etd_utc IS NULL OR ft.etd_source NOT IN ('A', 'T', 'S'));
    
    SET @etd_count = @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 4c: SimBrief/ICAO Flight Plan Parsing (V8.5)
    -- ========================================================================
    
    IF OBJECT_ID('dbo.sp_ParseSimBriefDataBatch', 'P') IS NOT NULL
    BEGIN
        DECLARE @sb_result TABLE (flights_processed INT, simbrief_flights INT, flights_with_stepclimbs INT, elapsed_ms INT);
        INSERT INTO @sb_result EXEC dbo.sp_ParseSimBriefDataBatch @batch_size = 50, @only_unparsed = 1;
        SELECT @simbrief_parsed = flights_processed FROM @sb_result;
    END
    
    -- ========================================================================
    -- Step 5: Queue routes for parsing
    -- ========================================================================
    
    MERGE dbo.adl_parse_queue AS target
    USING (
        SELECT rc.flight_uid, rc.route_hash, COALESCE(dbo.fn_GetParseTier(p.dept_icao, p.dest_icao, p.lat, p.lon), 4) AS parse_tier
        FROM #route_changes rc INNER JOIN #pilots p ON p.flight_uid = rc.flight_uid
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET parse_tier = source.parse_tier, route_hash = source.route_hash, status = 'PENDING', queued_utc = @now, started_utc = NULL, completed_utc = NULL, attempts = 0, error_message = NULL
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, parse_tier, route_hash, status, queued_utc, next_eligible_utc)
        VALUES (source.flight_uid, source.parse_tier, source.route_hash, 'PENDING', @now, @now);
    
    -- ========================================================================
    -- Step 6: Upsert adl_flight_aircraft
    -- ========================================================================
    
    MERGE dbo.adl_flight_aircraft AS target
    USING (
        SELECT
            p.flight_uid,
            CASE WHEN p.aircraft_faa_raw LIKE '%/%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2) WHEN p.aircraft_faa_raw LIKE '%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2) ELSE COALESCE(p.aircraft_short, p.aircraft_faa_raw) END AS aircraft_icao,
            p.aircraft_faa_raw AS aircraft_faa,
            CASE WHEN acd.FAA_Weight = 'Super' THEN 'J' WHEN acd.FAA_Weight = 'Heavy' THEN 'H' WHEN acd.FAA_Weight = 'Large' THEN 'L' WHEN acd.FAA_Weight IN ('Small', 'Small+') THEN 'S'
                 WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) IN ('A388', 'A380', 'B748', 'B744', 'AN25', 'A225') THEN 'J'
                 WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) IN ('B77W', 'B77L', 'B772', 'B773', 'A359', 'A35K', 'A346', 'A345', 'A343', 'A342', 'A333', 'A332', 'A339', 'B788', 'B789', 'B78X', 'B764', 'B763', 'B762', 'MD11', 'DC10', 'IL96', 'B752', 'B753', 'A310', 'A306') THEN 'H'
                 WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'C1%' OR COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'C2%' OR COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'PA%' OR COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'SR2%' THEN 'S'
                 ELSE 'L' END AS weight_class,
            CASE acd.Physical_Class_Engine WHEN 'Jet' THEN 'J' WHEN 'Turboprop' THEN 'T' WHEN 'Piston' THEN 'P' WHEN 'Turboshaft' THEN 'T' WHEN 'Electric' THEN 'E'
                 ELSE CASE WHEN p.aircraft_faa_raw LIKE 'H/%' THEN 'J' WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'C1%' THEN 'P' WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'PA%' THEN 'P' ELSE 'J' END END AS engine_type,
            acd.Num_Engines AS engine_count,
            CAST(acd.Approach_Speed_knot * 2 AS INT) AS cruise_tas_kts,
            CAST(NULL AS INT) AS ceiling_ft,
            CASE WHEN acd.ICAO_WTC LIKE 'Super%' OR acd.ICAO_WTC = 'J' THEN 'J' WHEN acd.ICAO_WTC LIKE 'Heavy%' OR acd.ICAO_WTC = 'H' THEN 'H' WHEN acd.ICAO_WTC LIKE 'Light%' OR acd.ICAO_WTC = 'L' THEN 'L' WHEN acd.ICAO_WTC LIKE 'Medium%' OR acd.ICAO_WTC = 'M' THEN 'M'
                 WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) IN ('A388', 'A380', 'B748', 'B744', 'AN25', 'A225') THEN 'J'
                 WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) IN ('B77W', 'B77L', 'B772', 'B773', 'A359', 'A35K', 'A346', 'A345', 'A343', 'A342', 'A333', 'A332', 'A339', 'B788', 'B789', 'B78X', 'B764', 'B763', 'B762', 'MD11', 'DC10', 'IL96', 'B752', 'B753', 'A310', 'A306') THEN 'H'
                 WHEN COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'C1%' OR COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'C2%' OR COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'PA%' OR COALESCE(p.aircraft_short, p.aircraft_faa_raw) LIKE 'SR2%' THEN 'L'
                 ELSE 'M' END AS wake_category,
            p.airline_icao, al.name AS airline_name
        FROM #pilots p
        LEFT JOIN dbo.ACD_Data acd ON acd.ICAO_Code = CASE WHEN p.aircraft_faa_raw LIKE '%/%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2) WHEN p.aircraft_faa_raw LIKE '%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2) ELSE COALESCE(p.aircraft_short, p.aircraft_faa_raw) END
        LEFT JOIN dbo.airlines al ON al.icao = p.airline_icao
        WHERE p.flight_uid IS NOT NULL AND (p.aircraft_faa_raw IS NOT NULL OR p.aircraft_short IS NOT NULL)
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET aircraft_icao = COALESCE(source.aircraft_icao, target.aircraft_icao), aircraft_faa = COALESCE(LEFT(source.aircraft_faa, 8), target.aircraft_faa), weight_class = COALESCE(source.weight_class, target.weight_class), engine_type = COALESCE(source.engine_type, target.engine_type), engine_count = COALESCE(source.engine_count, target.engine_count), cruise_tas_kts = COALESCE(source.cruise_tas_kts, target.cruise_tas_kts), ceiling_ft = COALESCE(source.ceiling_ft, target.ceiling_ft), wake_category = COALESCE(source.wake_category, target.wake_category), airline_icao = COALESCE(source.airline_icao, target.airline_icao), airline_name = COALESCE(source.airline_name, target.airline_name), aircraft_updated_utc = @now
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, aircraft_icao, aircraft_faa, weight_class, engine_type, engine_count, cruise_tas_kts, ceiling_ft, wake_category, airline_icao, airline_name, aircraft_updated_utc)
        VALUES (source.flight_uid, LEFT(source.aircraft_icao, 8), LEFT(source.aircraft_faa, 8), source.weight_class, source.engine_type, source.engine_count, source.cruise_tas_kts, source.ceiling_ft, source.wake_category, source.airline_icao, source.airline_name, @now);
    
    -- ========================================================================
    -- Step 7: Mark inactive flights
    -- ========================================================================
    
    UPDATE dbo.adl_flight_core SET is_active = 0, phase = 'arrived', flight_status = 'COMPLETED' WHERE is_active = 1 AND last_seen_utc < DATEADD(MINUTE, -5, @now);
    
    -- ========================================================================
    -- Step 8: Process Trajectory & ETA Calculations
    -- ========================================================================
    
    IF OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_ProcessTrajectoryBatch @process_eta = 1, @process_trajectory = 1, @eta_count = @eta_count OUTPUT, @traj_count = @traj_count OUTPUT;
    END
    
    -- ========================================================================
    -- Step 8b: Update arrival buckets from calculated ETA
    -- ========================================================================
    
    UPDATE ft SET ft.arrival_bucket_utc = CASE WHEN ft.eta_utc IS NOT NULL
        THEN DATEADD(MINUTE, CASE WHEN DATEPART(MINUTE, ft.eta_utc) < 15 THEN 0 WHEN DATEPART(MINUTE, ft.eta_utc) < 30 THEN 15 WHEN DATEPART(MINUTE, ft.eta_utc) < 45 THEN 30 ELSE 45 END, DATEADD(HOUR, DATEDIFF(HOUR, 0, ft.eta_utc), 0)) ELSE NULL END,
        ft.eta_epoch = CASE WHEN ft.eta_utc IS NOT NULL THEN DATEDIFF(SECOND, '1970-01-01', ft.eta_utc) ELSE NULL END
    FROM dbo.adl_flight_times ft WHERE ft.eta_utc IS NOT NULL AND ft.arrival_bucket_utc IS NULL;
    
    -- ========================================================================
    -- Step 8c: Waypoint ETA Calculation (V8.6)
    -- ========================================================================
    
    IF OBJECT_ID('dbo.sp_CalculateWaypointETABatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_CalculateWaypointETABatch @waypoint_count = @waypoint_etas OUTPUT;
    END
    
    -- ========================================================================
    -- Step 9: Zone Detection for OOOI
    -- ========================================================================
    
    IF OBJECT_ID('dbo.sp_ProcessZoneDetectionBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_ProcessZoneDetectionBatch @transitions_detected = @zone_transitions OUTPUT;
    END
    
    -- ========================================================================
    -- Step 10: Boundary Detection for ARTCC/Sector/TRACON
    -- ========================================================================
    
    IF OBJECT_ID('dbo.sp_ProcessBoundaryDetectionBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_ProcessBoundaryDetectionBatch @transitions_detected = @boundary_transitions OUTPUT, @flights_processed = @boundary_flights OUTPUT;
    END
    
    -- ========================================================================
    -- Cleanup and return stats
    -- ========================================================================
    
    DROP TABLE IF EXISTS #route_changes;
    DROP TABLE IF EXISTS #pilots;
    
    DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
    
    SELECT 
        @pilot_count AS pilots_received,
        @new_flights AS new_flights,
        @updated_flights AS updated_flights,
        @routes_queued AS routes_queued,
        @etd_count AS etds_calculated,
        @simbrief_parsed AS simbrief_parsed,
        @eta_count AS etas_calculated,
        @waypoint_etas AS waypoint_etas,
        @traj_count AS trajectories_logged,
        @zone_transitions AS zone_transitions,
        @boundary_transitions AS boundary_transitions,
        @elapsed_ms AS elapsed_ms;
    
END;
GO

PRINT 'sp_Adl_RefreshFromVatsim_Normalized V8.6 created successfully';
PRINT 'Added: Step 8c - Waypoint ETA Calculation';
PRINT 'Returns: waypoint_etas count in stats';
GO
