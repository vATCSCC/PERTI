-- ============================================================================
-- Migration 080: Event AAR/ADR - No Defaults
--
-- Only fills in AAR/ADR when we can accurately determine runway config from:
-- 1. ATIS history data
-- 2. Flight plan runway assignments
-- 3. Weather data to determine VMC/IMC
--
-- If we can't determine config accurately, leave fields blank.
-- ============================================================================

-- Drop the old procedure and replace with stricter version
IF OBJECT_ID('dbo.sp_FillEventAARFromConfig', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_FillEventAARFromConfig;
GO

CREATE PROCEDURE dbo.sp_FillEventAARFromConfig
    @event_idx VARCHAR(100) = NULL,  -- NULL = process all events with NULL rates
    @dry_run BIT = 0                 -- 1 = show results without updating
AS
BEGIN
    SET NOCOUNT ON;

    -- Results table
    CREATE TABLE #results (
        event_idx VARCHAR(100),
        airport_icao VARCHAR(4),
        start_utc DATETIME2,
        end_utc DATETIME2,
        total_arrivals INT,
        total_departures INT,
        duration_hours DECIMAL(10,2),
        -- Data source analysis
        atis_records INT DEFAULT 0,
        atis_arr_runways VARCHAR(50),
        atis_dep_runways VARCHAR(50),
        flight_plan_records INT DEFAULT 0,
        fp_arr_runways VARCHAR(100),  -- Comma-separated list of runways from flight plans
        fp_dep_runways VARCHAR(100),
        weather_records INT DEFAULT 0,
        weather_category VARCHAR(10),  -- VMC, IMC, LVMC, etc.
        -- Determined config (only if we have data)
        determined_config VARCHAR(100),
        config_source VARCHAR(30),  -- 'ATIS', 'FLIGHT_PLAN', 'WEATHER+FLIGHT', NULL
        -- Matched config
        matched_config_id INT,
        matched_config_name VARCHAR(100),
        -- Rates (only filled if config determined)
        vatsim_aar INT,
        vatsim_adr INT,
        rw_aar INT,
        rw_adr INT,
        -- Calculated averages (always calculated)
        calc_avg_aar DECIMAL(6,2),
        calc_avg_adr DECIMAL(6,2),
        -- Why we couldn't determine config
        skip_reason VARCHAR(100)
    );

    -- Get events to process
    INSERT INTO #results (
        event_idx, airport_icao, start_utc, end_utc,
        total_arrivals, total_departures, duration_hours
    )
    SELECT
        ea.event_idx,
        ea.airport_icao,
        e.start_utc,
        e.end_utc,
        ea.total_arrivals,
        ea.total_departures,
        e.duration_hours
    FROM dbo.vatusa_event_airport ea
    JOIN dbo.vatusa_event e ON ea.event_idx = e.event_idx
    WHERE ea.peak_vatsim_aar IS NULL
      AND (@event_idx IS NULL OR ea.event_idx = @event_idx);

    -- =========================================================================
    -- 1. Check for ATIS history data during event period
    -- =========================================================================
    UPDATE r
    SET
        atis_records = (
            SELECT COUNT(*)
            FROM dbo.atis_config_history a
            WHERE a.airport_icao = r.airport_icao
              AND a.effective_utc BETWEEN r.start_utc AND r.end_utc
        ),
        atis_arr_runways = (
            SELECT TOP 1 a.arr_runways
            FROM dbo.atis_config_history a
            WHERE a.airport_icao = r.airport_icao
              AND a.effective_utc BETWEEN r.start_utc AND r.end_utc
            ORDER BY a.effective_utc DESC
        ),
        atis_dep_runways = (
            SELECT TOP 1 a.dep_runways
            FROM dbo.atis_config_history a
            WHERE a.airport_icao = r.airport_icao
              AND a.effective_utc BETWEEN r.start_utc AND r.end_utc
            ORDER BY a.effective_utc DESC
        )
    FROM #results r;

    -- =========================================================================
    -- 2. Check for flight plan runway assignments during event period
    -- =========================================================================
    UPDATE r
    SET flight_plan_records = sub.fp_count,
        fp_arr_runways = sub.arr_runways,
        fp_dep_runways = sub.dep_runways
    FROM #results r
    CROSS APPLY (
        SELECT
            COUNT(*) as fp_count,
            -- Get most common arrival runway
            (SELECT TOP 1 arr_runway
             FROM dbo.adl_flight_plan fp
             JOIN dbo.adl_flight_times ft ON fp.flight_uid = ft.flight_uid
             WHERE fp.fp_dest_icao = r.airport_icao
               AND ft.ata_runway_utc BETWEEN r.start_utc AND r.end_utc
               AND fp.arr_runway IS NOT NULL
               AND LEN(fp.arr_runway) > 0
             GROUP BY arr_runway
             ORDER BY COUNT(*) DESC) as arr_runways,
            -- Get most common departure runway
            (SELECT TOP 1 dep_runway
             FROM dbo.adl_flight_plan fp
             JOIN dbo.adl_flight_times ft ON fp.flight_uid = ft.flight_uid
             WHERE fp.fp_dept_icao = r.airport_icao
               AND ft.atd_runway_utc BETWEEN r.start_utc AND r.end_utc
               AND fp.dep_runway IS NOT NULL
               AND LEN(fp.dep_runway) > 0
             GROUP BY dep_runway
             ORDER BY COUNT(*) DESC) as dep_runways
        FROM dbo.adl_flight_plan fp
        JOIN dbo.adl_flight_times ft ON fp.flight_uid = ft.flight_uid
        WHERE (fp.fp_dest_icao = r.airport_icao OR fp.fp_dept_icao = r.airport_icao)
          AND (ft.ata_runway_utc BETWEEN r.start_utc AND r.end_utc
               OR ft.atd_runway_utc BETWEEN r.start_utc AND r.end_utc)
          AND (fp.arr_runway IS NOT NULL OR fp.dep_runway IS NOT NULL)
    ) sub;

    -- =========================================================================
    -- 3. Determine weather category from available data
    -- =========================================================================
    -- Check airport_weather_impact if available
    UPDATE r
    SET
        weather_records = (
            SELECT COUNT(*)
            FROM dbo.airport_weather_impact w
            WHERE w.airport_icao = r.airport_icao
              AND w.is_active = 1
        ),
        -- Determine weather category from visibility/ceiling
        weather_category = CASE
            WHEN EXISTS (
                SELECT 1 FROM dbo.airport_weather_impact w
                WHERE w.airport_icao = r.airport_icao
                  AND w.is_active = 1
                  AND (w.vis_cat >= 3 OR w.cig_cat >= 3)  -- IMC conditions
            ) THEN 'IMC'
            WHEN EXISTS (
                SELECT 1 FROM dbo.airport_weather_impact w
                WHERE w.airport_icao = r.airport_icao
                  AND w.is_active = 1
                  AND (w.vis_cat >= 2 OR w.cig_cat >= 2)  -- LVMC conditions
            ) THEN 'LVMC'
            ELSE NULL  -- Can't determine weather
        END
    FROM #results r;

    -- =========================================================================
    -- 4. Determine config ONLY if we have actual data
    -- =========================================================================
    UPDATE r
    SET
        -- Determine config from ATIS (highest priority)
        determined_config = CASE
            WHEN r.atis_records > 0 AND r.atis_arr_runways IS NOT NULL
            THEN r.atis_arr_runways + ISNULL(' / ' + r.atis_dep_runways, '')
            -- Use flight plan runway data if ATIS not available
            WHEN r.flight_plan_records > 0 AND r.fp_arr_runways IS NOT NULL
            THEN r.fp_arr_runways
            ELSE NULL
        END,
        config_source = CASE
            WHEN r.atis_records > 0 AND r.atis_arr_runways IS NOT NULL THEN 'ATIS'
            WHEN r.flight_plan_records > 0 AND r.fp_arr_runways IS NOT NULL THEN 'FLIGHT_PLAN'
            ELSE NULL
        END,
        -- Default to VMC if we can't determine weather (most VATSIM events are VMC)
        -- But only if we have a config
        weather_category = CASE
            WHEN r.weather_category IS NOT NULL THEN r.weather_category
            WHEN r.atis_records > 0 OR r.flight_plan_records > 0 THEN 'VMC'
            ELSE NULL
        END,
        -- Record why we skipped
        skip_reason = CASE
            WHEN r.atis_records = 0 AND r.flight_plan_records = 0
            THEN 'No ATIS or flight plan data for period'
            WHEN r.atis_arr_runways IS NULL AND r.fp_arr_runways IS NULL
            THEN 'No runway assignment data found'
            ELSE NULL
        END
    FROM #results r;

    -- =========================================================================
    -- 5. Match to airport config (only if we determined one)
    -- =========================================================================
    UPDATE r
    SET matched_config_id = (
        SELECT TOP 1 c.config_id
        FROM dbo.airport_config c
        WHERE c.airport_icao = r.airport_icao
          AND (
              -- Exact match
              c.config_name = r.determined_config
              -- Or starts with the runway we detected
              OR c.config_name LIKE r.fp_arr_runways + '%'
              OR c.config_name LIKE r.atis_arr_runways + '%'
          )
        ORDER BY
            CASE WHEN c.config_name = r.determined_config THEN 0 ELSE 1 END,
            LEN(c.config_name)
    )
    FROM #results r
    WHERE r.determined_config IS NOT NULL;

    -- Get config name
    UPDATE r
    SET matched_config_name = c.config_name
    FROM #results r
    JOIN dbo.airport_config c ON r.matched_config_id = c.config_id;

    -- Update skip reason if no matching config
    UPDATE #results
    SET skip_reason = 'No matching airport config found for: ' + determined_config
    WHERE determined_config IS NOT NULL
      AND matched_config_id IS NULL;

    -- =========================================================================
    -- 6. Look up rates (only if we have a matched config)
    -- =========================================================================
    UPDATE r
    SET
        vatsim_aar = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'VATSIM'
              AND rr.weather = ISNULL(r.weather_category, 'VMC')
              AND rr.rate_type = 'ARR'
        ),
        vatsim_adr = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'VATSIM'
              AND rr.weather = ISNULL(r.weather_category, 'VMC')
              AND rr.rate_type = 'DEP'
        ),
        rw_aar = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'RW'
              AND rr.weather = ISNULL(r.weather_category, 'VMC')
              AND rr.rate_type = 'ARR'
        ),
        rw_adr = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'RW'
              AND rr.weather = ISNULL(r.weather_category, 'VMC')
              AND rr.rate_type = 'DEP'
        )
    FROM #results r
    WHERE r.matched_config_id IS NOT NULL;

    -- =========================================================================
    -- 7. Calculate average rates from totals (always, for reference)
    -- =========================================================================
    UPDATE #results
    SET
        calc_avg_aar = CASE
            WHEN duration_hours > 0
            THEN CAST(total_arrivals / duration_hours AS DECIMAL(6,2))
            ELSE NULL
        END,
        calc_avg_adr = CASE
            WHEN duration_hours > 0
            THEN CAST(total_departures / duration_hours AS DECIMAL(6,2))
            ELSE NULL
        END;

    -- =========================================================================
    -- Output results
    -- =========================================================================
    IF @dry_run = 1
    BEGIN
        -- Show all results
        SELECT
            event_idx,
            airport_icao,
            total_arrivals,
            total_departures,
            CAST(duration_hours AS DECIMAL(5,2)) as hours,
            atis_records,
            atis_arr_runways,
            flight_plan_records,
            fp_arr_runways,
            weather_category,
            config_source,
            determined_config,
            matched_config_name,
            vatsim_aar,
            vatsim_adr,
            rw_aar,
            rw_adr,
            calc_avg_aar,
            calc_avg_adr,
            skip_reason
        FROM #results
        ORDER BY
            CASE WHEN vatsim_aar IS NOT NULL THEN 0 ELSE 1 END,
            event_idx, airport_icao;

        -- Summary
        SELECT
            COUNT(*) as total_airports,
            SUM(CASE WHEN atis_records > 0 THEN 1 ELSE 0 END) as with_atis,
            SUM(CASE WHEN flight_plan_records > 0 THEN 1 ELSE 0 END) as with_flight_plans,
            SUM(CASE WHEN matched_config_id IS NOT NULL THEN 1 ELSE 0 END) as with_matched_config,
            SUM(CASE WHEN vatsim_aar IS NOT NULL THEN 1 ELSE 0 END) as will_update,
            SUM(CASE WHEN vatsim_aar IS NULL THEN 1 ELSE 0 END) as will_skip
        FROM #results;
    END
    ELSE
    BEGIN
        -- Only update where we have accurate data
        UPDATE ea
        SET
            avg_vatsim_aar = r.calc_avg_aar,
            avg_vatsim_adr = r.calc_avg_adr,
            peak_vatsim_aar = r.vatsim_aar,
            aar_source = r.config_source
        FROM dbo.vatusa_event_airport ea
        JOIN #results r ON ea.event_idx = r.event_idx
                       AND ea.airport_icao = r.airport_icao
        WHERE r.vatsim_aar IS NOT NULL;

        SELECT
            @@ROWCOUNT as airports_updated,
            (SELECT COUNT(*) FROM #results WHERE config_source = 'ATIS') as from_atis,
            (SELECT COUNT(*) FROM #results WHERE config_source = 'FLIGHT_PLAN') as from_flight_plan,
            (SELECT COUNT(*) FROM #results WHERE vatsim_aar IS NULL) as skipped_no_data;
    END

    DROP TABLE #results;
END
GO

PRINT 'Migration 080 complete: sp_FillEventAARFromConfig updated (no defaults)';
GO
