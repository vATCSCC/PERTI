-- ============================================================================
-- Migration 079: Event AAR/ADR from Airport Config
--
-- Looks up ATIS/weather data for event periods, determines config,
-- and fills in VATSIM and RW AAR/ADR rates.
-- ============================================================================

-- ============================================================================
-- PROCEDURE: sp_FillEventAARFromConfig
--
-- For each event airport:
-- 1. Check for ATIS data during event period
-- 2. Determine runway config (from ATIS or default)
-- 3. Look up weather category (from ATIS or default to VMC)
-- 4. Fill in VATSIM and RW rates from airport_config_rate
-- ============================================================================
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
        -- ATIS analysis
        atis_records INT DEFAULT 0,
        atis_config VARCHAR(50),
        -- Weather determination
        weather_cat VARCHAR(10) DEFAULT 'VMC',
        -- Matched config
        matched_config_id INT,
        matched_config_name VARCHAR(100),
        -- VATSIM rates
        vatsim_aar INT,
        vatsim_adr INT,
        -- RW rates
        rw_aar INT,
        rw_adr INT,
        -- Calculated averages
        calc_avg_aar DECIMAL(6,2),
        calc_avg_adr DECIMAL(6,2),
        -- Source
        rate_source VARCHAR(20)
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

    -- Check for ATIS history coverage
    UPDATE r
    SET atis_records = (
        SELECT COUNT(*)
        FROM dbo.atis_config_history a
        WHERE a.airport_icao = r.airport_icao
          AND a.effective_utc BETWEEN r.start_utc AND r.end_utc
    )
    FROM #results r;

    -- Get ATIS-based config if available
    UPDATE r
    SET atis_config = (
        SELECT TOP 1 a.arr_runways
        FROM dbo.atis_config_history a
        WHERE a.airport_icao = r.airport_icao
          AND a.effective_utc BETWEEN r.start_utc AND r.end_utc
        ORDER BY a.effective_utc DESC
    )
    FROM #results r
    WHERE r.atis_records > 0;

    -- Match to airport config
    -- Priority: ATIS config > any config for airport
    UPDATE r
    SET
        matched_config_id = COALESCE(
            -- Match ATIS-based config
            (SELECT TOP 1 c.config_id
             FROM dbo.airport_config c
             WHERE c.airport_icao = r.airport_icao
               AND (c.config_name = r.atis_config
                    OR c.config_name LIKE r.atis_config + '%'
                    OR c.config_name LIKE '%' + r.atis_config + '%')
             ORDER BY
                CASE WHEN c.config_name = r.atis_config THEN 0 ELSE 1 END,
                LEN(c.config_name)),
            -- Fallback: highest capacity config
            (SELECT TOP 1 c.config_id
             FROM dbo.airport_config c
             JOIN dbo.airport_config_rate rr ON c.config_id = rr.config_id
             WHERE c.airport_icao = r.airport_icao
               AND rr.source = 'VATSIM'
               AND rr.weather = 'VMC'
               AND rr.rate_type = 'ARR'
             ORDER BY rr.rate_value DESC)
        ),
        rate_source = CASE
            WHEN r.atis_records > 0 THEN 'ATIS'
            ELSE 'CONFIG_DEFAULT'
        END
    FROM #results r;

    -- Get config name
    UPDATE r
    SET matched_config_name = c.config_name
    FROM #results r
    JOIN dbo.airport_config c ON r.matched_config_id = c.config_id;

    -- Look up VATSIM rates
    UPDATE r
    SET
        vatsim_aar = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'VATSIM'
              AND rr.weather = r.weather_cat
              AND rr.rate_type = 'ARR'
        ),
        vatsim_adr = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'VATSIM'
              AND rr.weather = r.weather_cat
              AND rr.rate_type = 'DEP'
        )
    FROM #results r
    WHERE r.matched_config_id IS NOT NULL;

    -- Look up RW (real-world) rates
    UPDATE r
    SET
        rw_aar = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'RW'
              AND rr.weather = r.weather_cat
              AND rr.rate_type = 'ARR'
        ),
        rw_adr = (
            SELECT TOP 1 rr.rate_value
            FROM dbo.airport_config_rate rr
            WHERE rr.config_id = r.matched_config_id
              AND rr.source = 'RW'
              AND rr.weather = r.weather_cat
              AND rr.rate_type = 'DEP'
        )
    FROM #results r
    WHERE r.matched_config_id IS NOT NULL;

    -- Calculate average rates from totals
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

    IF @dry_run = 1
    BEGIN
        -- Show what would be updated
        SELECT
            event_idx,
            airport_icao,
            total_arrivals,
            total_departures,
            CAST(duration_hours AS DECIMAL(5,2)) as hours,
            atis_records,
            atis_config,
            weather_cat,
            matched_config_name,
            vatsim_aar,
            vatsim_adr,
            rw_aar,
            rw_adr,
            calc_avg_aar,
            calc_avg_adr,
            rate_source
        FROM #results
        ORDER BY event_idx, airport_icao;

        SELECT
            COUNT(*) as total_airports,
            SUM(CASE WHEN atis_records > 0 THEN 1 ELSE 0 END) as with_atis,
            SUM(CASE WHEN matched_config_id IS NOT NULL THEN 1 ELSE 0 END) as with_config,
            SUM(CASE WHEN vatsim_aar IS NOT NULL THEN 1 ELSE 0 END) as with_vatsim_rate,
            SUM(CASE WHEN rw_aar IS NOT NULL THEN 1 ELSE 0 END) as with_rw_rate
        FROM #results;
    END
    ELSE
    BEGIN
        -- Update the actual table
        UPDATE ea
        SET
            avg_vatsim_aar = r.calc_avg_aar,
            avg_vatsim_adr = r.calc_avg_adr,
            peak_vatsim_aar = r.vatsim_aar,  -- Use config rate as peak capacity
            aar_source = r.rate_source
        FROM dbo.vatusa_event_airport ea
        JOIN #results r ON ea.event_idx = r.event_idx
                       AND ea.airport_icao = r.airport_icao
        WHERE r.vatsim_aar IS NOT NULL;

        SELECT
            @@ROWCOUNT as airports_updated,
            (SELECT COUNT(*) FROM #results WHERE atis_records > 0) as with_atis_data,
            (SELECT COUNT(*) FROM #results WHERE rate_source = 'CONFIG_DEFAULT') as using_defaults;
    END

    DROP TABLE #results;
END
GO

PRINT 'Migration 079 complete: sp_FillEventAARFromConfig created';
GO
