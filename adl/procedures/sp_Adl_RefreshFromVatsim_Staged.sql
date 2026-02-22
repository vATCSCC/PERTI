-- ============================================================================
-- sp_Adl_RefreshFromVatsim_Staged V9.4.0 - Geography Pre-computation
--
-- V9.4.0 Changes:
--   - Pre-compute apts.position_geo eliminates ~8500 geography::Point() CLR
--     constructions per cycle (uses migration 007_apts_position_geo.sql)
--   - Pre-compute pilot position_geo in #pilots temp table, reuse in Steps 3a/3b
--   - Combined Step 1b dual airport UPDATEs into single UPDATE with 2 LEFT JOINs
--   - Estimated savings: ~280-550ms per cycle (geometry-heavy workloads)
--   - Falls back gracefully if apts.position_geo is NULL (recomputes from lat/lon)
--
-- V9.3.0 Changes (kept):
--   - PHP daemon detects unchanged flights (change_flags bitmask in staging)
--   - Heartbeat flights (change_flags=0) skip geography, position, plan, aircraft
--   - Heartbeat flights only get timestamps updated (is_active, last_seen_utc)
--   - Trajectory logging (Step 8) is NOT filtered - all flights get trajectory points
--   - Two-layer threshold: PHP exact match (processing) + SQL V9.1 (disk write I/O)
--
-- change_flags bitmask:
--   Bit 0 (1): POSITION_CHANGED
--   Bit 1 (2): PLAN_CHANGED
--   Bit 2 (4): NEW_FLIGHT
--   Value 0:   Heartbeat (identical to previous cycle)
--   Default 15: Full processing (backward-compatible)
--
-- V9.2.0 Changes (kept):
--   - @defer_expensive defers ETA/snapshot steps, trajectory always captured
--
-- V9.1.0 Changes (kept):
--   - Position write threshold: 0.0001deg lat/lon, 50ft alt, 2kts gs
--
-- V9.0.0 Changes (kept):
--   - Reads from staging tables instead of OPENJSON
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_Adl_RefreshFromVatsim_Staged
    @batch_id UNIQUEIDENTIFIER,
    @skip_zone_detection BIT = 0,  -- Set to 1 when zone_daemon.php is running
    @defer_expensive BIT = 0       -- When 1: trajectory always captured, ETA/snapshot deferred to daemon
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
    DECLARE @eta_count INT = 0;
    DECLARE @traj_count INT = 0;
    DECLARE @etd_count INT = 0;
    DECLARE @simbrief_parsed INT = 0;
    DECLARE @waypoint_etas INT = 0;
    DECLARE @zone_transitions INT = 0;
    DECLARE @boundary_transitions INT = 0;
    DECLARE @boundary_flights INT = 0;
    DECLARE @crossings_calculated INT = 0;

    -- Step timing instrumentation
    DECLARE @step_start DATETIME2(3);
    DECLARE @step1_ms INT = 0, @step1b_ms INT = 0, @step2_ms INT = 0, @step2a_ms INT = 0;
    DECLARE @step2b_ms INT = 0, @step3_ms INT = 0, @step4_ms INT = 0, @step4b_ms INT = 0;
    DECLARE @step4c_ms INT = 0, @step5_ms INT = 0, @step6_ms INT = 0, @step7_ms INT = 0;
    DECLARE @step8_ms INT = 0, @step8b_ms INT = 0, @step8c_ms INT = 0, @step8d_ms INT = 0, @step9_ms INT = 0;
    DECLARE @batch_eta_count INT = 0;
    DECLARE @step10_ms INT = 0, @step11_ms INT = 0, @step12_ms INT = 0, @step13_ms INT = 0;

    -- ========================================================================
    -- Step 1: Read from staging table into temp table (replaces OPENJSON)
    -- This is ~30x faster than OPENJSON parsing
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    SELECT
        s.cid,
        s.callsign,
        s.lat,
        s.lon,
        s.altitude_ft,
        s.groundspeed_kts,
        s.heading_deg,
        s.qnh_in_hg,
        s.qnh_mb,
        s.flight_server,
        s.logon_time,
        s.fp_rule,
        s.dept_icao,
        s.dest_icao,
        s.alt_icao,
        s.route,
        s.remarks,
        s.altitude_filed_raw,
        s.tas_filed_raw,
        s.dep_time_z,
        s.enroute_time_raw,
        s.fuel_time_raw,
        s.aircraft_faa_raw,
        s.aircraft_short,
        s.fp_dof_raw,
        s.flight_key,
        s.route_hash,
        s.airline_icao,
        s.change_flags,
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
        CAST(NULL AS BIGINT) AS flight_uid,
        CAST(NULL AS geography) AS position_geo  -- V9.4.0: pre-computed in Step 1b
    INTO #pilots
    FROM dbo.adl_staging_pilots s
    WHERE s.batch_id = @batch_id;

    SET @pilot_count = @@ROWCOUNT;

    -- Indexes for performance
    CREATE CLUSTERED INDEX IX_pilots_key ON #pilots (flight_key);
    CREATE NONCLUSTERED INDEX IX_pilots_dept ON #pilots (dept_icao) WHERE dept_icao IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_pilots_dest ON #pilots (dest_icao) WHERE dest_icao IS NOT NULL;

    SET @step1_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 1b: Enrich with airport data (V9.4.0 - geography pre-computation)
    -- Part 1: Combined airport lat/lon + ARTCC/TRACON lookup (was 2 UPDATEs)
    -- Part 2: Pre-compute pilot position_geo once for reuse in Steps 3a/3b
    -- Part 3: Distance calculations using pre-computed geography objects
    -- Part 4: Percent complete calculation
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- Part 1: Combined airport enrichment (was 2 separate UPDATEs in V9.3.0)
    UPDATE p
    SET p.dept_lat   = dept.LAT_DECIMAL,
        p.dept_lon   = dept.LONG_DECIMAL,
        p.dept_artcc = dept.RESP_ARTCC_ID,
        p.dept_tracon = COALESCE(dept.Approach_ID, dept.Departure_ID, dept.Approach_Departure_ID),
        p.dest_lat   = dest.LAT_DECIMAL,
        p.dest_lon   = dest.LONG_DECIMAL,
        p.dest_artcc = dest.RESP_ARTCC_ID,
        p.dest_tracon = COALESCE(dest.Approach_ID, dest.Departure_ID, dest.Approach_Departure_ID)
    FROM #pilots p
    LEFT JOIN dbo.apts dept ON dept.ICAO_ID = p.dept_icao
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = p.dest_icao
    WHERE p.dept_icao IS NOT NULL OR p.dest_icao IS NOT NULL;

    -- Part 2: Pre-compute pilot position_geo (V9.4.0)
    -- Built once here, reused in Steps 3a/3b (eliminates 2 more Point constructions)
    -- V9.3.0 filter: only changed/new flights need geography
    UPDATE #pilots
    SET position_geo = geography::Point(lat, lon, 4326)
    WHERE (change_flags & 5) > 0  -- POSITION_CHANGED or NEW_FLIGHT
      AND lat IS NOT NULL
      AND lat BETWEEN -90 AND 90 AND lon BETWEEN -180 AND 180;

    -- Part 3: Distance calculations using pre-computed geography (V9.4.0)
    -- Before: 6 geography::Point() calls per flight (2 dept, 2 dest, 2 position)
    -- After:  0 Point calls (all pre-computed: apts.position_geo + #pilots.position_geo)
    UPDATE p
    SET
        gcd_nm = CASE
            WHEN dept.position_geo IS NOT NULL AND dest.position_geo IS NOT NULL
            THEN dept.position_geo.STDistance(dest.position_geo) / 1852.0
            -- Fallback if apts.position_geo not populated (pre-migration compat)
            WHEN p.dept_lat IS NOT NULL AND p.dest_lat IS NOT NULL
                 AND p.dept_lat BETWEEN -90 AND 90 AND p.dept_lon BETWEEN -180 AND 180
                 AND p.dest_lat BETWEEN -90 AND 90 AND p.dest_lon BETWEEN -180 AND 180
            THEN geography::Point(p.dept_lat, p.dept_lon, 4326).STDistance(
                 geography::Point(p.dest_lat, p.dest_lon, 4326)) / 1852.0
            ELSE NULL
        END,
        dist_to_dest_nm = CASE
            WHEN p.position_geo IS NOT NULL AND dest.position_geo IS NOT NULL
            THEN p.position_geo.STDistance(dest.position_geo) / 1852.0
            -- Fallback
            WHEN p.position_geo IS NOT NULL AND p.dest_lat IS NOT NULL
                 AND p.dest_lat BETWEEN -90 AND 90 AND p.dest_lon BETWEEN -180 AND 180
            THEN p.position_geo.STDistance(
                 geography::Point(p.dest_lat, p.dest_lon, 4326)) / 1852.0
            ELSE NULL
        END,
        dist_flown_nm = CASE
            WHEN p.position_geo IS NOT NULL AND dept.position_geo IS NOT NULL
            THEN dept.position_geo.STDistance(p.position_geo) / 1852.0
            -- Fallback
            WHEN p.position_geo IS NOT NULL AND p.dept_lat IS NOT NULL
                 AND p.dept_lat BETWEEN -90 AND 90 AND p.dept_lon BETWEEN -180 AND 180
            THEN geography::Point(p.dept_lat, p.dept_lon, 4326).STDistance(
                 p.position_geo) / 1852.0
            ELSE NULL
        END
    FROM #pilots p
    LEFT JOIN dbo.apts dept ON dept.ICAO_ID = p.dept_icao
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = p.dest_icao
    WHERE (p.change_flags & 5) > 0  -- POSITION_CHANGED or NEW_FLIGHT
      AND p.position_geo IS NOT NULL;

    -- Part 4: Percent complete (unchanged logic)
    UPDATE #pilots
    SET pct_complete = CASE
        WHEN gcd_nm > 10 AND dist_flown_nm IS NOT NULL
        THEN CASE
            WHEN (dist_flown_nm / gcd_nm) * 100.0 > 100.0 THEN 100.0
            ELSE CAST((dist_flown_nm / gcd_nm) * 100.0 AS DECIMAL(5,2))
        END
        ELSE NULL
    END
    WHERE (change_flags & 5) > 0  -- POSITION_CHANGED or NEW_FLIGHT
      AND gcd_nm IS NOT NULL;

    SET @step1b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 2: Upsert adl_flight_core (V9.3.0 - split heartbeat / full)
    -- Heartbeat flights (change_flags=0) only get timestamps updated.
    -- Changed flights get full phase recalculation.
    -- New flight INSERT is unfiltered (only fires for missing flight_key).
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    -- 2a. INSERT new flights (unfiltered - only fires for flights NOT in adl_flight_core)
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

    -- 2b. Heartbeat: timestamps only (no phase recalc, no CASE expression)
    -- These flights have pct_complete=NULL (Step 1b skipped), so they must NOT
    -- enter the phase CASE expression which depends on pct_complete.
    UPDATE c
    SET c.is_active = 1,
        c.last_seen_utc = @now,
        c.snapshot_utc = @now
    FROM dbo.adl_flight_core c
    INNER JOIN #pilots p ON c.flight_key = p.flight_key
    WHERE p.change_flags = 0;

    -- 2c. Changed: full update with phase recalculation
    UPDATE c
    SET c.is_active = 1,
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
    INNER JOIN #pilots p ON c.flight_key = p.flight_key
    WHERE p.change_flags > 0;

    SET @updated_flights = @@ROWCOUNT;

    UPDATE p
    SET p.flight_uid = c.flight_uid
    FROM #pilots p
    INNER JOIN dbo.adl_flight_core c ON c.flight_key = p.flight_key;

    SET @step2_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 2a: Process prefiles from staging table (replaces OPENJSON)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

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
    FROM dbo.adl_staging_prefiles pf
    WHERE pf.batch_id = @batch_id
      AND NOT EXISTS (
          SELECT 1 FROM dbo.adl_flight_core c WHERE c.flight_key = pf.flight_key
      )
      AND NOT EXISTS (
          SELECT 1 FROM #pilots p WHERE p.flight_key = pf.flight_key
      );

    -- Update last_seen for existing prefiles
    UPDATE c
    SET c.last_seen_utc = @now, c.snapshot_utc = @now
    FROM dbo.adl_flight_core c
    WHERE c.phase = 'prefile'
      AND c.is_active = 1
      AND EXISTS (
          SELECT 1 FROM dbo.adl_staging_prefiles pf
          WHERE pf.batch_id = @batch_id AND pf.flight_key = c.flight_key
      );

    -- Create flight_plan rows for prefiles
    INSERT INTO dbo.adl_flight_plan (
        flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
        fp_route, fp_remarks, fp_altitude_ft, fp_tas_kts, fp_dept_time_z,
        fp_enroute_minutes, fp_hash, fp_updated_utc, parse_status,
        gcd_nm
    )
    SELECT
        c.flight_uid,
        pf.fp_rule,
        pf.dept_icao,
        pf.dest_icao,
        pf.alt_icao,
        pf.route,
        pf.remarks,
        CASE
            WHEN pf.altitude_filed_raw LIKE 'FL%' THEN TRY_CAST(SUBSTRING(pf.altitude_filed_raw, 3, 10) AS INT) * 100
            WHEN pf.altitude_filed_raw LIKE 'F%' THEN TRY_CAST(SUBSTRING(pf.altitude_filed_raw, 2, 10) AS INT) * 100
            ELSE TRY_CAST(pf.altitude_filed_raw AS INT)
        END,
        CASE
            WHEN pf.tas_filed_raw LIKE 'N%' THEN TRY_CAST(SUBSTRING(pf.tas_filed_raw, 2, 10) AS INT)
            ELSE TRY_CAST(pf.tas_filed_raw AS INT)
        END,
        pf.dep_time_z,
        CASE
            WHEN LEN(pf.enroute_time_raw) = 4
            THEN TRY_CAST(LEFT(pf.enroute_time_raw, 2) AS INT) * 60 + TRY_CAST(RIGHT(pf.enroute_time_raw, 2) AS INT)
            ELSE TRY_CAST(pf.enroute_time_raw AS INT)
        END,
        pf.route_hash,
        @now,
        'PENDING',
        -- V9.4.0: Use pre-computed airport geography
        CASE
            WHEN dept.position_geo IS NOT NULL AND dest.position_geo IS NOT NULL
            THEN CAST(dept.position_geo.STDistance(dest.position_geo) / 1852.0 AS DECIMAL(10,2))
            -- Fallback if apts.position_geo not populated
            WHEN dept.LAT_DECIMAL IS NOT NULL AND dept.LONG_DECIMAL IS NOT NULL
                 AND dest.LAT_DECIMAL IS NOT NULL AND dest.LONG_DECIMAL IS NOT NULL
                 AND dept.LAT_DECIMAL BETWEEN -90 AND 90 AND dept.LONG_DECIMAL BETWEEN -180 AND 180
                 AND dest.LAT_DECIMAL BETWEEN -90 AND 90 AND dest.LONG_DECIMAL BETWEEN -180 AND 180
            THEN CAST(geography::Point(dept.LAT_DECIMAL, dept.LONG_DECIMAL, 4326).STDistance(
                      geography::Point(dest.LAT_DECIMAL, dest.LONG_DECIMAL, 4326)) / 1852.0 AS DECIMAL(10,2))
            ELSE NULL
        END
    FROM dbo.adl_staging_prefiles pf
    INNER JOIN dbo.adl_flight_core c ON c.flight_key = pf.flight_key
    LEFT JOIN dbo.apts dept ON dept.ICAO_ID = pf.dept_icao
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = pf.dest_icao
    WHERE pf.batch_id = @batch_id
      AND c.phase = 'prefile'
      AND c.is_active = 1
      AND pf.dept_icao IS NOT NULL
      AND pf.dest_icao IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM dbo.adl_flight_plan fp WHERE fp.flight_uid = c.flight_uid
      );

    SET @step2a_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 2b: Create adl_flight_times rows (unchanged)
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    INSERT INTO dbo.adl_flight_times (flight_uid)
    SELECT c.flight_uid
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM dbo.adl_flight_times ft WHERE ft.flight_uid = c.flight_uid);

    SET @step2b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 3: Upsert adl_flight_position (V9.3.0 - delta filtered + V9.1 threshold)
    -- Two-layer optimization:
    --   Layer 1 (PHP): Exact match — heartbeat flights skip entirely
    --   Layer 2 (SQL): V9.1 threshold — skip disk write for sub-meaningful jitter
    -- ========================================================================
    SET @step_start = SYSUTCDATETIME();

    DECLARE @positions_inserted INT = 0;
    DECLARE @positions_updated INT = 0;

    -- 3a. INSERT new positions (NEW_FLIGHT or POSITION_CHANGED, no existing record)
    -- V9.4.0: Uses pre-computed position_geo from Step 1b
    INSERT INTO dbo.adl_flight_position (
        flight_uid, lat, lon, position_geo, altitude_ft, groundspeed_kts,
        heading_deg, qnh_in_hg, qnh_mb, dist_to_dest_nm, dist_flown_nm,
        pct_complete, position_updated_utc
    )
    SELECT
        p.flight_uid, p.lat, p.lon,
        p.position_geo,  -- V9.4.0: pre-computed in Step 1b
        p.altitude_ft, p.groundspeed_kts, p.heading_deg,
        p.qnh_in_hg, p.qnh_mb, p.dist_to_dest_nm, p.dist_flown_nm,
        p.pct_complete, @now
    FROM #pilots p
    WHERE p.flight_uid IS NOT NULL
      AND p.lat IS NOT NULL
      AND (p.change_flags & 5) > 0  -- POSITION_CHANGED or NEW_FLIGHT
      AND p.position_geo IS NOT NULL  -- V9.4.0: pre-computed ensures valid coords
      AND NOT EXISTS (
          SELECT 1 FROM dbo.adl_flight_position pos
          WHERE pos.flight_uid = p.flight_uid
      );

    SET @positions_inserted = @@ROWCOUNT;

    -- 3b. UPDATE only positions that changed (delta filtered + V9.1 write threshold)
    -- V9.4.0: Uses pre-computed position_geo from Step 1b
    UPDATE pos
    SET
        lat = p.lat,
        lon = p.lon,
        position_geo = p.position_geo,  -- V9.4.0: pre-computed in Step 1b
        altitude_ft = p.altitude_ft,
        groundspeed_kts = p.groundspeed_kts,
        heading_deg = p.heading_deg,
        qnh_in_hg = p.qnh_in_hg,
        qnh_mb = p.qnh_mb,
        dist_to_dest_nm = p.dist_to_dest_nm,
        dist_flown_nm = p.dist_flown_nm,
        pct_complete = p.pct_complete,
        position_updated_utc = @now
    FROM dbo.adl_flight_position pos
    INNER JOIN #pilots p ON p.flight_uid = pos.flight_uid
    WHERE p.lat IS NOT NULL
      AND (p.change_flags & 1) > 0  -- POSITION_CHANGED (PHP exact match passed)
      AND p.position_geo IS NOT NULL  -- V9.4.0: pre-computed ensures valid coords
      AND (
          -- V9.1 write threshold: skip disk write for sub-meaningful jitter
          ABS(pos.lat - p.lat) > 0.0001
          OR ABS(pos.lon - p.lon) > 0.0001
          OR ABS(ISNULL(pos.altitude_ft, 0) - ISNULL(p.altitude_ft, 0)) > 50
          OR ABS(ISNULL(pos.groundspeed_kts, 0) - ISNULL(p.groundspeed_kts, 0)) > 2
      );

    SET @positions_updated = @@ROWCOUNT;
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

    -- 4b. Identify flights with route changes (V9.3.0: only PLAN_CHANGED flights)
    SELECT p.flight_uid, p.route_hash
    INTO #route_changes
    FROM #pilots p
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = p.flight_uid
    WHERE p.flight_uid IS NOT NULL
      AND (p.change_flags & 2) > 0  -- PLAN_CHANGED
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
    -- Step 4b: ETD/STD Calculation (unchanged from V8.9.12)
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
        INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = p.flight_uid
        WHERE p.flight_uid IS NOT NULL
          AND ft.etd_utc IS NULL
    ),
    ETDResolved AS (
        SELECT flight_uid, phase, deptime, deptime_minutes, fp_dof_utc, fp_enroute_minutes,
            CASE
                WHEN fp_dof_utc IS NOT NULL AND deptime IS NOT NULL AND LEN(deptime) = 4 AND deptime NOT LIKE '%[^0-9]%' THEN 'D'
                WHEN phase = 'prefile' THEN 'N'
                WHEN phase <> 'prefile' AND deptime IS NOT NULL AND LEN(deptime) = 4 AND deptime NOT LIKE '%[^0-9]%' THEN 'P'
                ELSE NULL
            END AS etd_source,
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
    WHERE er.etd_utc IS NOT NULL;

    SET @etd_count = @@ROWCOUNT;
    SET @step4b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Step 4c: SimBrief/ICAO Flight Plan Parsing (unchanged)
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
    -- Step 5: Queue routes for parsing (unchanged)
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
    -- Step 5b: Update Route Distances (unchanged)
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
    -- Step 6: Upsert adl_flight_aircraft (V9.3.0: only new/plan-changed flights)
    -- Aircraft type comes from flight plan, so only changes when plan changes.
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
        WHERE p.flight_uid IS NOT NULL AND (p.change_flags & 6) > 0  -- PLAN_CHANGED or NEW_FLIGHT
          AND (p.aircraft_faa_raw IS NOT NULL OR p.aircraft_short IS NOT NULL)
    ) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET aircraft_icao = COALESCE(source.aircraft_icao, target.aircraft_icao), aircraft_faa = COALESCE(LEFT(source.aircraft_faa, 8), target.aircraft_faa), weight_class = COALESCE(source.weight_class, target.weight_class), engine_type = COALESCE(source.engine_type, target.engine_type), engine_count = COALESCE(source.engine_count, target.engine_count), cruise_tas_kts = COALESCE(source.cruise_tas_kts, target.cruise_tas_kts), ceiling_ft = COALESCE(source.ceiling_ft, target.ceiling_ft), wake_category = COALESCE(source.wake_category, target.wake_category), airline_icao = COALESCE(source.airline_icao, target.airline_icao), airline_name = COALESCE(source.airline_name, target.airline_name), aircraft_updated_utc = @now
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, aircraft_icao, aircraft_faa, weight_class, engine_type, engine_count, cruise_tas_kts, ceiling_ft, wake_category, airline_icao, airline_name, aircraft_updated_utc)
        VALUES (source.flight_uid, LEFT(source.aircraft_icao, 8), LEFT(source.aircraft_faa, 8), source.weight_class, source.engine_type, source.engine_count, source.cruise_tas_kts, source.ceiling_ft, source.wake_category, source.airline_icao, source.airline_name, @now);

    SET @step6_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Steps 7-13: Unchanged from V8.9.12
    -- ========================================================================

    -- Step 7: Mark inactive flights
    -- Fix: Only mark as 'arrived' if actually near destination (<50nm)
    -- Flights that disconnect far from destination are marked 'disconnected'
    SET @step_start = SYSUTCDATETIME();

    -- 7a. Flights near destination (<50nm) - mark as actually arrived
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
      AND p.dist_to_dest_nm < 50;  -- Actually near destination

    UPDATE c
    SET c.is_active = 0, c.phase = 'arrived'
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND p.dist_to_dest_nm < 50;

    -- 7b. Flights far from destination (>=50nm) - mark as disconnected, no ATA
    UPDATE c
    SET c.is_active = 0, c.phase = 'disconnected'
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(MINUTE, -5, @now)
      AND (p.dist_to_dest_nm >= 50 OR p.dist_to_dest_nm IS NULL);

    SET @step7_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 8: Process Trajectory & ETA
    -- Trajectory points are ephemeral (unique per timestamp) - always capture
    -- ETA is recalculable from live data - defer when @defer_expensive = 1
    SET @step_start = SYSUTCDATETIME();

    IF OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL
    BEGIN
        IF @defer_expensive = 1
            -- Trajectory only: capture position points, defer ETA to daemon
            EXEC dbo.sp_ProcessTrajectoryBatch @process_eta = 0, @process_trajectory = 1, @eta_count = @eta_count OUTPUT, @traj_count = @traj_count OUTPUT;
        ELSE
            -- Full processing: trajectory + ETA together
            EXEC dbo.sp_ProcessTrajectoryBatch @process_eta = 1, @process_trajectory = 1, @eta_count = @eta_count OUTPUT, @traj_count = @traj_count OUTPUT;
    END

    SET @step8_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 8b: Update arrival buckets
    SET @step_start = SYSUTCDATETIME();

    UPDATE ft SET ft.arrival_bucket_utc = CASE WHEN ft.eta_utc IS NOT NULL
        THEN DATEADD(MINUTE, CASE WHEN DATEPART(MINUTE, ft.eta_utc) < 15 THEN 0 WHEN DATEPART(MINUTE, ft.eta_utc) < 30 THEN 15 WHEN DATEPART(MINUTE, ft.eta_utc) < 45 THEN 30 ELSE 45 END, DATEADD(HOUR, DATEDIFF(HOUR, 0, ft.eta_utc), 0)) ELSE NULL END,
        ft.eta_epoch = CASE WHEN ft.eta_utc IS NOT NULL THEN DATEDIFF_BIG(SECOND, '1970-01-01', ft.eta_utc) ELSE NULL END
    FROM dbo.adl_flight_times ft WHERE ft.eta_utc IS NOT NULL AND ft.arrival_bucket_utc IS NULL;

    SET @step8b_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 8c: Waypoint ETA (DISABLED - runs in separate daemon)
    SET @step_start = SYSUTCDATETIME();
    SET @step8c_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 8d: Batch ETA Calculation (deferred when @defer_expensive = 1)
    SET @step_start = SYSUTCDATETIME();

    IF @defer_expensive = 0 AND OBJECT_ID('dbo.sp_CalculateETABatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_CalculateETABatch @eta_count = @batch_eta_count OUTPUT;
    END

    SET @step8d_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 9: Zone Detection (skipped if zone_daemon.php is running)
    SET @step_start = SYSUTCDATETIME();

    IF @skip_zone_detection = 0 AND OBJECT_ID('dbo.sp_ProcessZoneDetectionBatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_ProcessZoneDetectionBatch @transitions_detected = @zone_transitions OUTPUT;
    END

    SET @step9_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 10: Boundary Detection (DISABLED)
    SET @step_start = SYSUTCDATETIME();
    SET @step10_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 11: Planned Crossings (DISABLED)
    SET @step_start = SYSUTCDATETIME();
    SET @step11_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 12: Log Trajectory (deferred when @defer_expensive = 1)
    -- Redundant with Step 8 trajectory when it runs; legacy 60s-interval bulk log
    SET @step_start = SYSUTCDATETIME();
    IF @defer_expensive = 0
    BEGIN
        EXEC dbo.sp_Log_Trajectory;
    END
    SET @step12_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- Step 13: Phase Snapshot (deferred when @defer_expensive = 1)
    SET @step_start = SYSUTCDATETIME();
    IF @defer_expensive = 0 AND OBJECT_ID('dbo.sp_CapturePhaseSnapshot', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_CapturePhaseSnapshot;
    END
    SET @step13_ms = DATEDIFF(MILLISECOND, @step_start, SYSUTCDATETIME());

    -- ========================================================================
    -- Cleanup and return stats
    -- ========================================================================

    -- V9.3.0: Count heartbeat flights for monitoring
    DECLARE @heartbeat_count INT = 0;
    SELECT @heartbeat_count = COUNT(*) FROM #pilots WHERE change_flags = 0;

    DROP TABLE IF EXISTS #route_changes;
    DROP TABLE IF EXISTS #pilots;

    DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());

    SELECT
        @pilot_count AS pilots_received,
        @heartbeat_count AS heartbeat_flights,
        @new_flights AS new_flights,
        @updated_flights AS updated_flights,
        @positions_inserted AS positions_inserted,
        @positions_updated AS positions_updated,
        @routes_queued AS routes_queued,
        @route_dists_updated AS route_dists_updated,
        @etd_count AS etds_calculated,
        @simbrief_parsed AS simbrief_parsed,
        @eta_count AS etas_calculated,
        @batch_eta_count AS batch_etas_calculated,
        @waypoint_etas AS waypoint_etas,
        @traj_count AS trajectories_logged,
        @zone_transitions AS zone_transitions,
        @boundary_transitions AS boundary_transitions,
        @crossings_calculated AS crossings_calculated,
        @elapsed_ms AS elapsed_ms,
        -- Step timings
        @step1_ms AS step1_json_ms,  -- Now "staging read" not JSON parse
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
        @step8d_ms AS step8d_batch_eta_ms,
        @step9_ms AS step9_zone_ms,
        @step10_ms AS step10_boundary_ms,
        @step11_ms AS step11_crossings_ms,
        @step12_ms AS step12_log_ms,
        @step13_ms AS step13_snapshot_ms;

END;
GO

PRINT 'sp_Adl_RefreshFromVatsim_Staged V9.4.0 created successfully';
PRINT 'V9.4.0: Geography pre-computation - ~8500 fewer Point() CLR constructions per cycle';
PRINT 'V9.3.0: Delta detection - heartbeat flights skip geography, position, plan, aircraft';
PRINT 'V9.2.0: @defer_expensive defers ETA/snapshot, trajectory always captured';
PRINT 'V9.1.0: Position write threshold (0.0001deg, 50ft, 2kts) retained as secondary filter';
PRINT 'V9.0.0: Reads from staging tables instead of OPENJSON';
GO
