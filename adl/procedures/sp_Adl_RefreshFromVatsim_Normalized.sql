-- ============================================================================
-- sp_Adl_RefreshFromVatsim_Normalized V8.9.13 - Improved arrival detection (touchdown_utc + 10nm)
--
-- Changes from V8.9.11:
--   - DISABLED Step 8c (Waypoint ETA) - moved to waypoint_eta_daemon.php
--   - Uses sp_CalculateWaypointETABatch_Tiered for tiered processing
--   - Fixes scaling issue at high pilot counts (5-10s at 3000 pilots)
--
-- Changes from V8.9.10:
--   - Fixed DATEDIFF overflow: Changed to DATEDIFF_BIG for epoch calculations
--   - DATEDIFF returns INT which overflows beyond year 2038
--   - Also fixed in sp_ProcessTrajectoryBatch and sp_CalculateETABatch
--   - Added ETD validation: reject dates >2 days future or >1 day past
--
-- Changes from V8.9.9:
--   - DISABLED Step 10 (Boundary Detection) - causing query timeouts
--   - DISABLED Step 11 (Planned Crossings) - causing query timeouts
--   - These steps need optimization before re-enabling
--
-- Changes from V8.9.8:
--   - Re-enabled Step 10: Boundary Detection (ARTCC/TRACON)
--   - Re-enabled Step 11: Planned Crossings Calculation
--   - All steps 1-13 now active
--
-- Changes from V8.9.7:
--   - Step 7: Now sets ata_utc in adl_flight_times when marking flights arrived
--   - Uses last_seen_utc as the actual arrival time (best available data)
--   - Enables ETA accuracy measurement (predicted vs actual comparison)
--
-- Changes from V8.9.6:
--   - Step 2a: Now creates adl_flight_plan rows for prefiles (enables prefile ETAs)
--   - Step 8d: Added sp_CalculateETABatch call (sets eta_dist_source, eta_method)
--   - Prefiles now get GCD calculated from departure/arrival airports
--
-- Changes from V8.9.5:
--   - Added Step 5b: Route Distance calculation for active flights
--   - Uses sp_UpdateRouteDistancesBatch to populate route_dist_to_dest_nm
--   - Enables more accurate ETA calculations using parsed route distances
--
-- Changes from V8.9.4:
--   - DISABLED Steps 10 & 11 again - optimized sub-procedures not deployed
--   - Will re-enable after sub-procedures are tested individually
--
-- Changes from V8.9.2:
--   - Fixed Step 4b filter: only calculate ETD for flights WITHOUT an ETD
--
-- Changes from V8.8:
--   - Added step-by-step timing instrumentation for performance diagnosis
--   - Returns step*_ms columns showing each step's execution time
--
-- Changes from V8.7:
--   - Fixed phase detection: added 'arrived' phase (GS<50 + pct>85%)
--   - Added Step 2a: Process VATSIM prefiles from $.prefiles array
--   - Prefiles now tracked separately as phase='prefile'
--
-- Changes from V8.6:
--   - Added Step 11: Planned Crossings Calculation
--   - Detects regional flights and calculates boundary crossings
--   - Returns crossings_calculated count in stats
--
-- Full Step List:
--   1    - Parse JSON into temp table
--   1b   - Enrich with airport data
--   2    - Upsert adl_flight_core
--   2a   - Process VATSIM prefiles (NEW)
--   2b   - Create adl_flight_times rows
--   3    - Upsert adl_flight_position
--   4    - Detect route changes, upsert flight plans
--   4b   - ETD/STD Calculation (V8.4)
--   4c   - SimBrief/ICAO Flight Plan Parsing (V8.5)
--   5    - Queue routes for parsing
--   5b   - Route Distance calculation (V8.9.6) <-- NEW
--   6    - Upsert adl_flight_aircraft
--   7    - Mark inactive flights
--   8    - Process Trajectory & ETA Calculations
--   8b   - Update arrival buckets
--   8c   - Waypoint ETA Calculation (V8.6)
--   8d   - Batch ETA Calculation (V8.9.7) <-- NEW
--   9    - Zone Detection for OOOI
--   10   - Boundary Detection for ARTCC/Sector/TRACON
--   11   - Planned Crossings Calculation (V8.7) <-- NEW
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_Adl_RefreshFromVatsim_Normalized
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
    -- Planned crossings counter (V8.7)
    DECLARE @crossings_calculated INT = 0;

    -- Step timing instrumentation (V8.9)
    DECLARE @step_start DATETIME2(3);
    DECLARE @step1_ms INT = 0, @step1b_ms INT = 0, @step2_ms INT = 0, @step2a_ms INT = 0;
    DECLARE @step2b_ms INT = 0, @step3_ms INT = 0, @step4_ms INT = 0, @step4b_ms INT = 0;
    DECLARE @step4c_ms INT = 0, @step5_ms INT = 0, @step6_ms INT = 0, @step7_ms INT = 0;
    DECLARE @step8_ms INT = 0, @step8b_ms INT = 0, @step8c_ms INT = 0, @step8d_ms INT = 0, @step9_ms INT = 0;
    DECLARE @batch_eta_count INT = 0;  -- V8.9.7: Batch ETA counter
    DECLARE @step10_ms INT = 0, @step11_ms INT = 0, @step12_ms INT = 0, @step13_ms INT = 0;
    
    -- ========================================================================
    -- Step 1: Parse JSON into temp table
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

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

    SET @step1_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 1b: Enrich with airport data
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
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
    
    -- Validate coordinates before creating geography points
    -- Latitude must be -90 to 90, Longitude must be -180 to 180
    UPDATE #pilots
    SET
        gcd_nm = CASE
            WHEN dept_lat IS NOT NULL AND dest_lat IS NOT NULL
                 AND dept_lat BETWEEN -90 AND 90 AND dept_lon BETWEEN -180 AND 180
                 AND dest_lat BETWEEN -90 AND 90 AND dest_lon BETWEEN -180 AND 180
            THEN geography::Point(dept_lat, dept_lon, 4326).STDistance(
                 geography::Point(dest_lat, dest_lon, 4326)) / 1852.0
            ELSE NULL
        END,
        dist_to_dest_nm = CASE
            WHEN lat IS NOT NULL AND dest_lat IS NOT NULL
                 AND lat BETWEEN -90 AND 90 AND lon BETWEEN -180 AND 180
                 AND dest_lat BETWEEN -90 AND 90 AND dest_lon BETWEEN -180 AND 180
            THEN geography::Point(lat, lon, 4326).STDistance(
                 geography::Point(dest_lat, dest_lon, 4326)) / 1852.0
            ELSE NULL
        END,
        dist_flown_nm = CASE
            WHEN lat IS NOT NULL AND dept_lat IS NOT NULL
                 AND lat BETWEEN -90 AND 90 AND lon BETWEEN -180 AND 180
                 AND dept_lat BETWEEN -90 AND 90 AND dept_lon BETWEEN -180 AND 180
            THEN geography::Point(dept_lat, dept_lon, 4326).STDistance(
                 geography::Point(lat, lon, 4326)) / 1852.0
            ELSE NULL
        END
    WHERE lat IS NOT NULL
      AND lat BETWEEN -90 AND 90 AND lon BETWEEN -180 AND 180;
    
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

    SET @step1b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 2: Upsert adl_flight_core
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    INSERT INTO dbo.adl_flight_core (
        flight_key, cid, callsign, flight_id,
        phase, last_source, is_active,
        first_seen_utc, last_seen_utc, logon_time_utc,
        adl_date, adl_time, snapshot_utc
    )
    SELECT
        p.flight_key, p.cid, p.callsign, p.flight_server,
        CASE
            WHEN p.lat IS NULL THEN 'prefile'
            WHEN p.groundspeed_kts < 50 AND ISNULL(p.pct_complete, 0) > 85 THEN 'arrived'
            WHEN p.groundspeed_kts < 50 THEN 'taxiing'
            WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) < 15 THEN 'departed'
            WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) > 85 THEN 'descending'
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
    
    UPDATE c
    SET c.is_active = 1,  -- Reactivate if seen again
        c.last_seen_utc = @now,
        c.snapshot_utc = @now,
        c.last_source = 'vatsim',
        c.phase = CASE
            WHEN p.lat IS NULL THEN 'prefile'
            WHEN p.groundspeed_kts < 50 AND ISNULL(p.pct_complete, 0) > 85 THEN 'arrived'
            WHEN p.groundspeed_kts < 50 THEN 'taxiing'
            WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) < 15 THEN 'departed'
            WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) > 85 THEN 'descending'
            ELSE 'enroute'
        END,
        c.flight_id = COALESCE(p.flight_server, c.flight_id)
    FROM dbo.adl_flight_core c
    INNER JOIN #pilots p ON c.flight_key = p.flight_key;
    
    SET @updated_flights = @@ROWCOUNT;

    UPDATE p
    SET p.flight_uid = c.flight_uid
    FROM #pilots p
    INNER JOIN dbo.adl_flight_core c ON c.flight_key = p.flight_key;

    SET @step2_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 2a: Process VATSIM prefiles (filed but not yet connected)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    ;WITH prefiles AS (
        SELECT
            CAST(pf.cid AS INT) AS cid,
            CAST(pf.callsign AS NVARCHAR(16)) AS callsign,
            CAST(fp.departure AS CHAR(4)) AS dept_icao,
            CAST(fp.arrival AS CHAR(4)) AS dest_icao,
            CAST(fp.deptime AS CHAR(4)) AS dep_time_z,
            CAST(pf.cid AS NVARCHAR) + '|' + CAST(pf.callsign AS NVARCHAR(16)) + '|' +
                ISNULL(CAST(fp.departure AS NVARCHAR(4)), '') + '|' +
                ISNULL(CAST(fp.arrival AS NVARCHAR(4)), '') + '|' +
                ISNULL(CAST(fp.deptime AS NVARCHAR(4)), '') AS flight_key
        FROM OPENJSON(@Json, '$.prefiles')
        WITH (
            cid INT,
            callsign NVARCHAR(16),
            flight_plan NVARCHAR(MAX) AS JSON
        ) AS pf
        OUTER APPLY OPENJSON(pf.flight_plan)
        WITH (
            departure NVARCHAR(8),
            arrival NVARCHAR(8),
            deptime NVARCHAR(8)
        ) AS fp
        WHERE pf.callsign IS NOT NULL
    )
    INSERT INTO dbo.adl_flight_core (
        flight_key, cid, callsign,
        phase, last_source, is_active,
        first_seen_utc, last_seen_utc,
        adl_date, adl_time, snapshot_utc
    )
    SELECT
        pf.flight_key, pf.cid, pf.callsign,
        'prefile', 'vatsim', 1,
        @now, @now,
        CAST(@now AS DATE), CAST(@now AS TIME), @now
    FROM prefiles pf
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_core c WHERE c.flight_key = pf.flight_key
    )
    AND NOT EXISTS (
        SELECT 1 FROM #pilots p WHERE p.flight_key = pf.flight_key
    );

    -- Update last_seen for existing prefiles still in prefile state
    UPDATE c
    SET c.last_seen_utc = @now, c.snapshot_utc = @now
    FROM dbo.adl_flight_core c
    WHERE c.phase = 'prefile'
      AND c.is_active = 1
      AND EXISTS (
          SELECT 1 FROM OPENJSON(@Json, '$.prefiles')
          WITH (
              cid INT,
              callsign NVARCHAR(16),
              flight_plan NVARCHAR(MAX) AS JSON
          ) AS pf
          OUTER APPLY OPENJSON(pf.flight_plan)
          WITH (
              departure NVARCHAR(8),
              arrival NVARCHAR(8),
              deptime NVARCHAR(8)
          ) AS fp
          WHERE CAST(pf.cid AS NVARCHAR) + '|' + CAST(pf.callsign AS NVARCHAR(16)) + '|' +
                ISNULL(CAST(fp.departure AS NVARCHAR(4)), '') + '|' +
                ISNULL(CAST(fp.arrival AS NVARCHAR(4)), '') + '|' +
                ISNULL(CAST(fp.deptime AS NVARCHAR(4)), '') = c.flight_key
      );

    -- V8.9.7: Create flight_plan rows for prefiles (enables prefile ETA calculation)
    ;WITH prefile_plans AS (
        SELECT
            c.flight_uid,
            CAST(fp.departure AS CHAR(4)) AS dept_icao,
            CAST(fp.arrival AS CHAR(4)) AS dest_icao,
            CAST(fp.alternate AS CHAR(4)) AS alt_icao,
            CAST(fp.route AS NVARCHAR(MAX)) AS route,
            CAST(fp.remarks AS NVARCHAR(MAX)) AS remarks,
            CAST(fp.deptime AS CHAR(4)) AS dep_time_z,
            CAST(fp.flight_rules AS CHAR(1)) AS fp_rule,
            CASE
                WHEN fp.altitude LIKE 'FL%' THEN TRY_CAST(SUBSTRING(fp.altitude, 3, 10) AS INT) * 100
                WHEN fp.altitude LIKE 'F%' THEN TRY_CAST(SUBSTRING(fp.altitude, 2, 10) AS INT) * 100
                ELSE TRY_CAST(fp.altitude AS INT)
            END AS altitude_ft,
            CASE
                WHEN fp.cruise_tas LIKE 'N%' THEN TRY_CAST(SUBSTRING(fp.cruise_tas, 2, 10) AS INT)
                ELSE TRY_CAST(fp.cruise_tas AS INT)
            END AS tas_kts,
            CASE
                WHEN LEN(fp.enroute_time) = 4
                THEN TRY_CAST(LEFT(fp.enroute_time, 2) AS INT) * 60 + TRY_CAST(RIGHT(fp.enroute_time, 2) AS INT)
                ELSE TRY_CAST(fp.enroute_time AS INT)
            END AS enroute_minutes,
            HASHBYTES('MD5', ISNULL(fp.route, '')) AS route_hash
        FROM OPENJSON(@Json, '$.prefiles')
        WITH (
            cid INT,
            callsign NVARCHAR(16),
            flight_plan NVARCHAR(MAX) AS JSON
        ) AS pf
        OUTER APPLY OPENJSON(pf.flight_plan)
        WITH (
            departure NVARCHAR(8),
            arrival NVARCHAR(8),
            alternate NVARCHAR(8),
            deptime NVARCHAR(8),
            route NVARCHAR(MAX),
            remarks NVARCHAR(MAX),
            altitude NVARCHAR(16),
            cruise_tas NVARCHAR(16),
            enroute_time NVARCHAR(8),
            flight_rules NVARCHAR(4)
        ) AS fp
        INNER JOIN dbo.adl_flight_core c ON c.flight_key =
            CAST(pf.cid AS NVARCHAR) + '|' + CAST(pf.callsign AS NVARCHAR(16)) + '|' +
            ISNULL(CAST(fp.departure AS NVARCHAR(4)), '') + '|' +
            ISNULL(CAST(fp.arrival AS NVARCHAR(4)), '') + '|' +
            ISNULL(CAST(fp.deptime AS NVARCHAR(4)), '')
        WHERE c.phase = 'prefile' AND c.is_active = 1
          AND fp.departure IS NOT NULL AND fp.arrival IS NOT NULL
    )
    INSERT INTO dbo.adl_flight_plan (
        flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
        fp_route, fp_remarks, fp_altitude_ft, fp_tas_kts, fp_dept_time_z,
        fp_enroute_minutes, fp_hash, fp_updated_utc, parse_status,
        gcd_nm
    )
    SELECT
        pp.flight_uid, pp.fp_rule, pp.dept_icao, pp.dest_icao, pp.alt_icao,
        pp.route, pp.remarks, pp.altitude_ft, pp.tas_kts, pp.dep_time_z,
        pp.enroute_minutes, pp.route_hash, @now, 'PENDING',
        -- Calculate GCD between departure and arrival airports (with coordinate validation)
        CASE
            WHEN dept.LAT_DECIMAL IS NOT NULL AND dept.LONG_DECIMAL IS NOT NULL
                 AND dest.LAT_DECIMAL IS NOT NULL AND dest.LONG_DECIMAL IS NOT NULL
                 AND dept.LAT_DECIMAL BETWEEN -90 AND 90 AND dept.LONG_DECIMAL BETWEEN -180 AND 180
                 AND dest.LAT_DECIMAL BETWEEN -90 AND 90 AND dest.LONG_DECIMAL BETWEEN -180 AND 180
            THEN CAST(geography::Point(dept.LAT_DECIMAL, dept.LONG_DECIMAL, 4326).STDistance(
                      geography::Point(dest.LAT_DECIMAL, dest.LONG_DECIMAL, 4326)) / 1852.0 AS DECIMAL(10,2))
            ELSE NULL
        END
    FROM prefile_plans pp
    LEFT JOIN dbo.apts dept ON dept.ICAO_ID = pp.dept_icao
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = pp.dest_icao
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_plan fp WHERE fp.flight_uid = pp.flight_uid
    );

    SET @step2a_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 2b: Create adl_flight_times rows for new flights
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    INSERT INTO dbo.adl_flight_times (flight_uid)
    SELECT c.flight_uid
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM dbo.adl_flight_times ft WHERE ft.flight_uid = c.flight_uid);

    SET @step2b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 3: Upsert adl_flight_position
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    MERGE dbo.adl_flight_position AS target
    USING (
        SELECT flight_uid, lat, lon, altitude_ft, groundspeed_kts,
               heading_deg, qnh_in_hg, qnh_mb,
               dist_to_dest_nm, dist_flown_nm, pct_complete
        FROM #pilots
        WHERE flight_uid IS NOT NULL AND lat IS NOT NULL
          -- Validate coordinates for geography::Point (lat -90 to 90, lon -180 to 180)
          AND lat BETWEEN -90 AND 90 AND lon BETWEEN -180 AND 180
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

    SET @step3_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 4: Detect route changes and upsert flight plans
    -- V8.9.13: OPTIMIZED - Split into INSERT (new) + UPDATE (changed only)
    -- Previous: MERGE all 2400 pilots (1.5-2.5s)
    -- Now: INSERT ~50-100 new + UPDATE ~50-100 changed (~200-400ms)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- 4a. Identify flights needing flight_plan rows (new flights without fp record)
    SELECT p.flight_uid, p.route_hash
    INTO #fp_new
    FROM #pilots p
    WHERE p.flight_uid IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM dbo.adl_flight_plan fp WHERE fp.flight_uid = p.flight_uid);

    -- 4b. Identify flights with route changes (existing flights, hash mismatch)
    SELECT p.flight_uid, p.route_hash
    INTO #route_changes
    FROM #pilots p
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = p.flight_uid
    WHERE p.flight_uid IS NOT NULL
      AND p.route IS NOT NULL
      AND LEN(LTRIM(RTRIM(p.route))) > 0
      AND (fp.fp_hash IS NULL OR fp.fp_hash != p.route_hash);

    SET @routes_queued = @@ROWCOUNT;

    -- 4c. INSERT new flight plans (only for flights without existing fp record)
    INSERT INTO dbo.adl_flight_plan (
        flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
        fp_dept_artcc, fp_dept_tracon, fp_dest_artcc, fp_dest_tracon,
        fp_route, fp_remarks, fp_altitude_ft, fp_tas_kts, fp_dept_time_z,
        fp_enroute_minutes, fp_fuel_minutes, aircraft_type, aircraft_equip,
        gcd_nm, fp_hash, fp_updated_utc, parse_status, is_simbrief
    )
    SELECT
        p.flight_uid, p.fp_rule, p.dept_icao, p.dest_icao, p.alt_icao,
        p.dept_artcc, p.dept_tracon, p.dest_artcc, p.dest_tracon,
        p.route, p.remarks,
        CASE
            WHEN p.altitude_filed_raw LIKE 'FL%' THEN TRY_CAST(SUBSTRING(p.altitude_filed_raw, 3, 10) AS INT) * 100
            WHEN p.altitude_filed_raw LIKE 'F%' THEN TRY_CAST(SUBSTRING(p.altitude_filed_raw, 2, 10) AS INT) * 100
            ELSE TRY_CAST(p.altitude_filed_raw AS INT)
        END,
        CASE
            WHEN p.tas_filed_raw LIKE 'N%' THEN TRY_CAST(SUBSTRING(p.tas_filed_raw, 2, 10) AS INT)
            ELSE TRY_CAST(p.tas_filed_raw AS INT)
        END,
        p.dep_time_z,
        CASE
            WHEN LEN(p.enroute_time_raw) = 4
            THEN TRY_CAST(LEFT(p.enroute_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(p.enroute_time_raw, 2) AS INT)
            ELSE TRY_CAST(p.enroute_time_raw AS INT)
        END,
        CASE
            WHEN LEN(p.fuel_time_raw) = 4
            THEN TRY_CAST(LEFT(p.fuel_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(p.fuel_time_raw, 2) AS INT)
            ELSE TRY_CAST(p.fuel_time_raw AS INT)
        END,
        CASE
            WHEN p.aircraft_faa_raw LIKE '%/%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2)
            WHEN p.aircraft_faa_raw LIKE '%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2)
            ELSE COALESCE(p.aircraft_short, p.aircraft_faa_raw)
        END,
        p.aircraft_faa_raw,
        p.gcd_nm, p.route_hash, @now, 'PENDING', 0
    FROM #pilots p
    INNER JOIN #fp_new n ON n.flight_uid = p.flight_uid;

    -- 4d. UPDATE only flights with actual route changes (hash mismatch)
    UPDATE fp
    SET fp_rule = COALESCE(p.fp_rule, fp.fp_rule),
        fp_dept_icao = COALESCE(p.dept_icao, fp.fp_dept_icao),
        fp_dest_icao = COALESCE(p.dest_icao, fp.fp_dest_icao),
        fp_alt_icao = COALESCE(p.alt_icao, fp.fp_alt_icao),
        fp_dept_artcc = COALESCE(p.dept_artcc, fp.fp_dept_artcc),
        fp_dept_tracon = COALESCE(p.dept_tracon, fp.fp_dept_tracon),
        fp_dest_artcc = COALESCE(p.dest_artcc, fp.fp_dest_artcc),
        fp_dest_tracon = COALESCE(p.dest_tracon, fp.fp_dest_tracon),
        fp_route = COALESCE(p.route, fp.fp_route),
        fp_remarks = COALESCE(p.remarks, fp.fp_remarks),
        fp_altitude_ft = COALESCE(
            CASE
                WHEN p.altitude_filed_raw LIKE 'FL%' THEN TRY_CAST(SUBSTRING(p.altitude_filed_raw, 3, 10) AS INT) * 100
                WHEN p.altitude_filed_raw LIKE 'F%' THEN TRY_CAST(SUBSTRING(p.altitude_filed_raw, 2, 10) AS INT) * 100
                ELSE TRY_CAST(p.altitude_filed_raw AS INT)
            END, fp.fp_altitude_ft),
        fp_tas_kts = COALESCE(
            CASE
                WHEN p.tas_filed_raw LIKE 'N%' THEN TRY_CAST(SUBSTRING(p.tas_filed_raw, 2, 10) AS INT)
                ELSE TRY_CAST(p.tas_filed_raw AS INT)
            END, fp.fp_tas_kts),
        fp_dept_time_z = COALESCE(p.dep_time_z, fp.fp_dept_time_z),
        fp_enroute_minutes = COALESCE(
            CASE
                WHEN LEN(p.enroute_time_raw) = 4
                THEN TRY_CAST(LEFT(p.enroute_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(p.enroute_time_raw, 2) AS INT)
                ELSE TRY_CAST(p.enroute_time_raw AS INT)
            END, fp.fp_enroute_minutes),
        fp_fuel_minutes = COALESCE(
            CASE
                WHEN LEN(p.fuel_time_raw) = 4
                THEN TRY_CAST(LEFT(p.fuel_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(p.fuel_time_raw, 2) AS INT)
                ELSE TRY_CAST(p.fuel_time_raw AS INT)
            END, fp.fp_fuel_minutes),
        aircraft_type = COALESCE(
            CASE
                WHEN p.aircraft_faa_raw LIKE '%/%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2)
                WHEN p.aircraft_faa_raw LIKE '%/%' THEN PARSENAME(REPLACE(p.aircraft_faa_raw, '/', '.'), 2)
                ELSE COALESCE(p.aircraft_short, p.aircraft_faa_raw)
            END, fp.aircraft_type),
        aircraft_equip = COALESCE(p.aircraft_faa_raw, fp.aircraft_equip),
        gcd_nm = COALESCE(p.gcd_nm, fp.gcd_nm),
        fp_hash = rc.route_hash,
        fp_updated_utc = @now,
        parse_status = 'PENDING',
        route_geometry = NULL,
        is_simbrief = 0
    FROM dbo.adl_flight_plan fp
    INNER JOIN #route_changes rc ON rc.flight_uid = fp.flight_uid
    INNER JOIN #pilots p ON p.flight_uid = rc.flight_uid;

    DROP TABLE IF EXISTS #fp_new;

    SET @step4_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 4b: ETD/STD Calculation (V8.4) - V8.9.3: Only process flights without ETD
    -- Once a flight has an ETD, we don't recalculate (saves ~90% of processing)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

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
        -- V8.9.3: Only process flights that don't have an ETD yet
        INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = p.flight_uid
        WHERE p.flight_uid IS NOT NULL
          AND ft.etd_utc IS NULL  -- Only new flights without ETD
    ),
    ETDResolved AS (
        SELECT flight_uid, phase, deptime, deptime_minutes, fp_dof_utc, fp_enroute_minutes,
            CASE
                WHEN fp_dof_utc IS NOT NULL AND deptime IS NOT NULL AND LEN(deptime) = 4 AND deptime NOT LIKE '%[^0-9]%' THEN 'D'
                WHEN phase = 'prefile' THEN 'N'
                WHEN phase <> 'prefile' AND deptime IS NOT NULL AND LEN(deptime) = 4 AND deptime NOT LIKE '%[^0-9]%' THEN 'P'
                ELSE NULL
            END AS etd_source,
            -- V8.9.11: Validate ETD is within reasonable range (not >2 days future, not >1 day past)
            CASE WHEN calc_etd IS NOT NULL
                      AND calc_etd BETWEEN DATEADD(DAY, -1, @now) AND DATEADD(DAY, 2, @now)
                 THEN calc_etd ELSE NULL END AS etd_utc
        FROM (
            SELECT flight_uid, phase, deptime, deptime_minutes, fp_dof_utc, fp_enroute_minutes,
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
                END AS calc_etd
            FROM ETDCalc
        ) AS sub
    )
    UPDATE ft
    SET ft.etd_utc = er.etd_utc, ft.std_utc = er.etd_utc, ft.etd_source = er.etd_source,
        ft.etd_epoch = CASE WHEN er.etd_utc IS NOT NULL THEN DATEDIFF_BIG(SECOND, '1970-01-01', er.etd_utc) ELSE NULL END,
        ft.ete_minutes = er.fp_enroute_minutes,
        ft.departure_bucket_utc = CASE WHEN er.etd_utc IS NOT NULL
            THEN DATEADD(MINUTE, CASE WHEN DATEPART(MINUTE, er.etd_utc) < 15 THEN 0 WHEN DATEPART(MINUTE, er.etd_utc) < 30 THEN 15 WHEN DATEPART(MINUTE, er.etd_utc) < 45 THEN 30 ELSE 45 END, DATEADD(HOUR, DATEDIFF(HOUR, 0, er.etd_utc), 0))
            ELSE NULL END,
        ft.times_updated_utc = @now
    FROM dbo.adl_flight_times ft
    INNER JOIN ETDResolved er ON er.flight_uid = ft.flight_uid
    WHERE er.etd_utc IS NOT NULL;  -- Filter already applied in CTE

    SET @etd_count = @@ROWCOUNT;
    SET @step4b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 4c: SimBrief/ICAO Flight Plan Parsing (V8.5)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    IF OBJECT_ID('dbo.sp_ParseSimBriefDataBatch', 'P') IS NOT NULL
    BEGIN
        DECLARE @sb_result TABLE (flights_processed INT, simbrief_flights INT, flights_with_stepclimbs INT, flights_with_costindex INT, elapsed_ms INT);
        INSERT INTO @sb_result EXEC dbo.sp_ParseSimBriefDataBatch @batch_size = 50, @only_unparsed = 1;
        SELECT @simbrief_parsed = flights_processed FROM @sb_result;
    END

    SET @step4c_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 5: Queue routes for parsing
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
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

    SET @step5_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 5b: Update Route Distances for active flights (V8.9.6)
    -- Uses parsed routes to calculate route_dist_to_dest_nm (more accurate than GCD)
    -- ========================================================================
    DECLARE @step5b_ms INT = 0;
    DECLARE @route_dists_updated INT = 0;
    SET @step_start = SYSUTCDATETIME();

    IF OBJECT_ID('dbo.sp_UpdateRouteDistancesBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_UpdateRouteDistancesBatch @flights_updated = @route_dists_updated OUTPUT;
    END

    SET @step5b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 6: Upsert adl_flight_aircraft
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
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

    SET @step6_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 7: Mark inactive flights - distinguish arrived vs disconnected
    -- V8.9.13: Improved arrival detection
    --   Priority 1: touchdown_utc exists (zone detection confirmed landing)
    --   Priority 2: dist_to_dest_nm < 10nm (reduced from 50nm)
    --   Otherwise: disconnected (mid-flight disconnect)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- 7a. Flights with touchdown_utc - DEFINITIVE arrivals (zone detection caught landing)
    UPDATE t
    SET t.ata_utc = COALESCE(t.touchdown_utc, t.on_utc, c.last_seen_utc),
        t.ata_runway_utc = COALESCE(t.ata_runway_utc, t.touchdown_utc),
        t.eta_prefix = 'A',  -- Mark as Actual
        t.times_updated_utc = @now
    FROM dbo.adl_flight_times t
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND t.touchdown_utc IS NOT NULL;

    UPDATE c
    SET c.is_active = 0, c.phase = 'arrived'
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND t.touchdown_utc IS NOT NULL;

    -- 7b. Flights near destination (<10nm) without touchdown - PROBABLE arrivals
    UPDATE t
    SET t.ata_utc = c.last_seen_utc,
        t.ata_runway_utc = c.last_seen_utc,
        t.eta_prefix = 'A',
        t.times_updated_utc = @now
    FROM dbo.adl_flight_times t
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND t.touchdown_utc IS NULL
      AND p.dist_to_dest_nm < 10;

    UPDATE c
    SET c.is_active = 0, c.phase = 'arrived'
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND (t.touchdown_utc IS NULL OR t.flight_uid IS NULL)
      AND p.dist_to_dest_nm < 10;

    -- 7c. Flights far from destination (>=10nm) without touchdown - DISCONNECTED
    UPDATE c
    SET c.is_active = 0, c.phase = 'disconnected'
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND (t.touchdown_utc IS NULL OR t.flight_uid IS NULL)
      AND (p.dist_to_dest_nm >= 10 OR p.dist_to_dest_nm IS NULL);

    SET @step7_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 8: Process Trajectory & ETA Calculations
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    IF OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_ProcessTrajectoryBatch @process_eta = 1, @process_trajectory = 1, @eta_count = @eta_count OUTPUT, @traj_count = @traj_count OUTPUT;
    END

    SET @step8_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 8b: Update arrival buckets from calculated ETA
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    UPDATE ft SET ft.arrival_bucket_utc = CASE WHEN ft.eta_utc IS NOT NULL
        THEN DATEADD(MINUTE, CASE WHEN DATEPART(MINUTE, ft.eta_utc) < 15 THEN 0 WHEN DATEPART(MINUTE, ft.eta_utc) < 30 THEN 15 WHEN DATEPART(MINUTE, ft.eta_utc) < 45 THEN 30 ELSE 45 END, DATEADD(HOUR, DATEDIFF(HOUR, 0, ft.eta_utc), 0)) ELSE NULL END,
        ft.eta_epoch = CASE WHEN ft.eta_utc IS NOT NULL THEN DATEDIFF_BIG(SECOND, '1970-01-01', ft.eta_utc) ELSE NULL END
    FROM dbo.adl_flight_times ft WHERE ft.eta_utc IS NOT NULL AND ft.arrival_bucket_utc IS NULL;

    SET @step8b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 8c: Waypoint ETA Calculation (V8.6)
    -- DISABLED 2026-01-16: Moved to separate waypoint_eta_daemon.php
    -- Uses sp_CalculateWaypointETABatch_Tiered for tiered processing
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- DISABLED: Causing scaling issues at high pilot counts (5-10s at 3000 pilots)
    -- Now runs in separate daemon with tiered intervals
    -- IF OBJECT_ID('dbo.sp_CalculateWaypointETABatch', 'P') IS NOT NULL
    -- BEGIN
    --     EXEC dbo.sp_CalculateWaypointETABatch @waypoint_count = @waypoint_etas OUTPUT;
    -- END

    SET @step8c_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 8d: Batch ETA Calculation (V8.9.7)
    -- Uses sp_CalculateETABatch which sets eta_method and eta_dist_source
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    IF OBJECT_ID('dbo.sp_CalculateETABatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_CalculateETABatch @eta_count = @batch_eta_count OUTPUT;
    END

    SET @step8d_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 9: Zone Detection for OOOI
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();
    
    IF OBJECT_ID('dbo.sp_ProcessZoneDetectionBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_ProcessZoneDetectionBatch @transitions_detected = @zone_transitions OUTPUT;
    END

    SET @step9_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 10: Boundary Detection for ARTCC/Sector/TRACON
    -- TEMPORARILY DISABLED - causing timeouts (2026-01-14)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- DISABLED: Causing query timeouts
    -- IF OBJECT_ID('dbo.sp_ProcessBoundaryDetectionBatch', 'P') IS NOT NULL
    -- BEGIN
    --     EXEC dbo.sp_ProcessBoundaryDetectionBatch @transitions_detected = @boundary_transitions OUTPUT, @flights_processed = @boundary_flights OUTPUT;
    -- END

    SET @step10_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 11: Planned Crossings Calculation
    -- TEMPORARILY DISABLED - causing timeouts (2026-01-14)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- DISABLED: Causing query timeouts
    -- -- Detect regional flights first
    -- IF OBJECT_ID('dbo.sp_DetectRegionalFlight', 'P') IS NOT NULL
    -- BEGIN
    --     EXEC dbo.sp_DetectRegionalFlight @batch_mode = 1;
    -- END

    -- -- Calculate planned crossings (V2.0 set-based)
    -- IF OBJECT_ID('dbo.sp_CalculatePlannedCrossingsBatch', 'P') IS NOT NULL
    -- BEGIN
    --     DECLARE @crossing_result TABLE (
    --         processed_at DATETIME2, cycle INT, minute INT,
    --         tier1_new_recalc INT, tier2_tracon INT, tier3_artcc INT,
    --         tier4_level INT, tier5_intl INT, tier6_transit INT, tier7_outside INT,
    --         total_flights INT, crossings_calculated INT, elapsed_ms INT
    --     );
    --     INSERT INTO @crossing_result
    --     EXEC dbo.sp_CalculatePlannedCrossingsBatch @max_flights_per_batch = 200, @debug = 0;
    --     SELECT @crossings_calculated = crossings_calculated FROM @crossing_result;
    -- END

    SET @step11_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 12: Log Trajectory Positions (Archive System)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    EXEC dbo.sp_Log_Trajectory;

    SET @step12_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 13: Capture Phase Snapshot (for 24hr chart)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    IF OBJECT_ID('dbo.sp_CapturePhaseSnapshot', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_CapturePhaseSnapshot;
    END

    SET @step13_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

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
        @route_dists_updated AS route_dists_updated,
        @etd_count AS etds_calculated,
        @simbrief_parsed AS simbrief_parsed,
        @eta_count AS etas_calculated,
        @batch_eta_count AS batch_etas_calculated,  -- V8.9.7
        @waypoint_etas AS waypoint_etas,
        @traj_count AS trajectories_logged,
        @zone_transitions AS zone_transitions,
        @boundary_transitions AS boundary_transitions,
        @crossings_calculated AS crossings_calculated,
        @elapsed_ms AS elapsed_ms,
        -- Step timings (V8.9 instrumentation)
        @step1_ms AS step1_json_ms,
        @step1b_ms AS step1b_enrich_ms,
        @step2_ms AS step2_core_ms,
        @step2a_ms AS step2a_prefile_ms,
        @step2b_ms AS step2b_times_ms,
        @step3_ms AS step3_position_ms,
        @step4_ms AS step4_flightplan_ms,
        @step4b_ms AS step4b_etd_ms,
        @step4c_ms AS step4c_simbrief_ms,
        @step5_ms AS step5_queue_ms,
        @step5b_ms AS step5b_routedist_ms,
        @step6_ms AS step6_aircraft_ms,
        @step7_ms AS step7_inactive_ms,
        @step8_ms AS step8_trajectory_ms,
        @step8b_ms AS step8b_bucket_ms,
        @step8c_ms AS step8c_waypoint_ms,
        @step8d_ms AS step8d_batch_eta_ms,  -- V8.9.7
        @step9_ms AS step9_zone_ms,
        @step10_ms AS step10_boundary_ms,
        @step11_ms AS step11_crossings_ms,
        @step12_ms AS step12_log_ms,
        @step13_ms AS step13_snapshot_ms;
    
END;
GO

PRINT 'sp_Adl_RefreshFromVatsim_Normalized V8.9.13 created successfully';
PRINT 'V8.9.13: Improved arrival detection - prioritize touchdown_utc, reduce fallback to 10nm';
PRINT 'Steps 1-8b, 8d, 9, 12-13 active, target <5s performance';
GO
