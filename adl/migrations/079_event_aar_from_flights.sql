-- ============================================================================
-- Migration 079: Event AAR/ADR Calculation from Flight Data
--
-- Creates a procedure to analyze flight history data during event periods
-- to determine runway configuration and update AAR/ADR values.
-- ============================================================================

-- ============================================================================
-- PROCEDURE: sp_CalculateEventAARFromFlights
--
-- Analyzes historical flight data to determine runway configuration during
-- an event, then looks up the corresponding AAR/ADR rates.
-- ============================================================================
IF OBJECT_ID('dbo.sp_CalculateEventAARFromFlights', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CalculateEventAARFromFlights;
GO

CREATE PROCEDURE dbo.sp_CalculateEventAARFromFlights
    @event_idx VARCHAR(100) = NULL,  -- NULL = process all STATSIM events with NULL AAR
    @dry_run BIT = 0                 -- 1 = show results without updating
AS
BEGIN
    SET NOCOUNT ON;

    -- Temp table to hold analysis results
    CREATE TABLE #event_analysis (
        event_idx VARCHAR(100),
        airport_icao VARCHAR(4),
        start_utc DATETIME2,
        end_utc DATETIME2,
        total_arrivals INT,
        total_departures INT,
        duration_hours DECIMAL(10,2),
        -- Flight analysis
        primary_arr_runway VARCHAR(10),
        primary_dep_runway VARCHAR(10),
        arr_heading_02 INT,
        arr_heading_20 INT,
        arr_heading_13 INT,
        arr_heading_31 INT,
        arr_heading_other INT,
        -- Weather inference (based on arrivals - more restrictive)
        inferred_weather VARCHAR(10),
        -- Matched config
        matched_config_name VARCHAR(50),
        matched_arr_rate INT,
        matched_dep_rate INT,
        -- Calculated values
        calc_avg_aar DECIMAL(6,2),
        calc_avg_adr DECIMAL(6,2),
        calc_peak_aar INT,
        has_flight_data BIT DEFAULT 0
    );

    -- Get events to process
    INSERT INTO #event_analysis (
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
    WHERE e.source = 'STATSIM'
      AND ea.peak_vatsim_aar IS NULL
      AND (@event_idx IS NULL OR ea.event_idx = @event_idx);

    -- Analyze flight data for each event/airport
    DECLARE @curr_event VARCHAR(100), @curr_airport VARCHAR(4);
    DECLARE @start DATETIME2, @end DATETIME2;
    DECLARE @arr_02 INT, @arr_20 INT, @arr_13 INT, @arr_31 INT, @arr_other INT;
    DECLARE @flight_count INT;

    DECLARE event_cursor CURSOR FOR
        SELECT event_idx, airport_icao, start_utc, end_utc
        FROM #event_analysis;

    OPEN event_cursor;
    FETCH NEXT FROM event_cursor INTO @curr_event, @curr_airport, @start, @end;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Analyze flight headings at low altitude
        SELECT
            @arr_02 = SUM(CASE WHEN heading_group = '02' AND op_type = 'ARR' THEN cnt ELSE 0 END),
            @arr_20 = SUM(CASE WHEN heading_group = '20' AND op_type = 'ARR' THEN cnt ELSE 0 END),
            @arr_13 = SUM(CASE WHEN heading_group = '13' AND op_type = 'ARR' THEN cnt ELSE 0 END),
            @arr_31 = SUM(CASE WHEN heading_group = '31' AND op_type = 'ARR' THEN cnt ELSE 0 END),
            @arr_other = SUM(CASE WHEN heading_group = 'other' THEN cnt ELSE 0 END),
            @flight_count = SUM(cnt)
        FROM (
            SELECT
                CASE
                    WHEN heading_deg BETWEEN 10 AND 40 THEN '02'
                    WHEN heading_deg BETWEEN 190 AND 220 THEN '20'
                    WHEN heading_deg BETWEEN 120 AND 150 THEN '13'
                    WHEN heading_deg BETWEEN 300 AND 330 THEN '31'
                    ELSE 'other'
                END as heading_group,
                CASE WHEN phase IN ('departed', 'departing', 'climbing') THEN 'DEP' ELSE 'ARR' END as op_type,
                COUNT(DISTINCT callsign) as cnt
            FROM dbo.adl_flights_history
            WHERE snapshot_utc BETWEEN @start AND @end
              AND (fp_dest_icao = @curr_airport OR fp_dept_icao = @curr_airport)
              AND altitude < 4000
            GROUP BY
                CASE
                    WHEN heading_deg BETWEEN 10 AND 40 THEN '02'
                    WHEN heading_deg BETWEEN 190 AND 220 THEN '20'
                    WHEN heading_deg BETWEEN 120 AND 150 THEN '13'
                    WHEN heading_deg BETWEEN 300 AND 330 THEN '31'
                    ELSE 'other'
                END,
                CASE WHEN phase IN ('departed', 'departing', 'climbing') THEN 'DEP' ELSE 'ARR' END
        ) flight_data;

        -- Update analysis table
        UPDATE #event_analysis
        SET
            arr_heading_02 = ISNULL(@arr_02, 0),
            arr_heading_20 = ISNULL(@arr_20, 0),
            arr_heading_13 = ISNULL(@arr_13, 0),
            arr_heading_31 = ISNULL(@arr_31, 0),
            arr_heading_other = ISNULL(@arr_other, 0),
            has_flight_data = CASE WHEN @flight_count > 0 THEN 1 ELSE 0 END,
            -- Determine primary arrival runway
            primary_arr_runway = CASE
                WHEN ISNULL(@arr_02, 0) >= ISNULL(@arr_20, 0)
                 AND ISNULL(@arr_02, 0) >= ISNULL(@arr_13, 0)
                 AND ISNULL(@arr_02, 0) >= ISNULL(@arr_31, 0) THEN '02'
                WHEN ISNULL(@arr_20, 0) >= ISNULL(@arr_13, 0)
                 AND ISNULL(@arr_20, 0) >= ISNULL(@arr_31, 0) THEN '20'
                WHEN ISNULL(@arr_31, 0) >= ISNULL(@arr_13, 0) THEN '31'
                ELSE '13'
            END,
            -- Assume VMC for VATSIM events (most common)
            inferred_weather = 'VMC'
        WHERE event_idx = @curr_event AND airport_icao = @curr_airport;

        FETCH NEXT FROM event_cursor INTO @curr_event, @curr_airport, @start, @end;
    END

    CLOSE event_cursor;
    DEALLOCATE event_cursor;

    -- Match to airport configs and get rates
    UPDATE ea
    SET
        matched_config_name = COALESCE(
            -- Try to match specific runway config
            (SELECT TOP 1 c.config_name
             FROM dbo.airport_config c
             WHERE c.airport_icao = ea.airport_icao
               AND c.config_name LIKE ea.primary_arr_runway + '%'
             ORDER BY LEN(c.config_name)),
            -- Fallback to any config for the airport
            (SELECT TOP 1 c.config_name
             FROM dbo.airport_config c
             WHERE c.airport_icao = ea.airport_icao)
        ),
        matched_arr_rate = COALESCE(
            (SELECT TOP 1 r.rate_value
             FROM dbo.airport_config c
             JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
             WHERE c.airport_icao = ea.airport_icao
               AND c.config_name LIKE ea.primary_arr_runway + '%'
               AND r.source = 'VATSIM'
               AND r.weather = ea.inferred_weather
               AND r.rate_type = 'ARR'
             ORDER BY r.rate_value DESC),
            -- Fallback to max VMC rate
            (SELECT MAX(r.rate_value)
             FROM dbo.airport_config c
             JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
             WHERE c.airport_icao = ea.airport_icao
               AND r.source = 'VATSIM'
               AND r.weather = 'VMC'
               AND r.rate_type = 'ARR')
        ),
        matched_dep_rate = COALESCE(
            (SELECT TOP 1 r.rate_value
             FROM dbo.airport_config c
             JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
             WHERE c.airport_icao = ea.airport_icao
               AND c.config_name LIKE ea.primary_arr_runway + '%'
               AND r.source = 'VATSIM'
               AND r.weather = ea.inferred_weather
               AND r.rate_type = 'DEP'
             ORDER BY r.rate_value DESC),
            (SELECT MAX(r.rate_value)
             FROM dbo.airport_config c
             JOIN dbo.airport_config_rate r ON c.config_id = r.config_id
             WHERE c.airport_icao = ea.airport_icao
               AND r.source = 'VATSIM'
               AND r.weather = 'VMC'
               AND r.rate_type = 'DEP')
        ),
        -- Calculate average rates from totals
        calc_avg_aar = CASE WHEN ea.duration_hours > 0
                            THEN CAST(ea.total_arrivals / ea.duration_hours AS DECIMAL(6,2))
                            ELSE NULL END,
        calc_avg_adr = CASE WHEN ea.duration_hours > 0
                            THEN CAST(ea.total_departures / ea.duration_hours AS DECIMAL(6,2))
                            ELSE NULL END
    FROM #event_analysis ea;

    -- Calculate peak AAR (use max of actual avg or inferred config rate, capped at config rate)
    UPDATE #event_analysis
    SET calc_peak_aar = CASE
        WHEN has_flight_data = 1 THEN
            -- Use minimum of: actual average * 1.5 (peak estimate) or config rate
            CASE WHEN calc_avg_aar * 1.5 < matched_arr_rate
                 THEN CAST(calc_avg_aar * 1.5 AS INT)
                 ELSE matched_arr_rate
            END
        ELSE
            -- No flight data: use config rate as reference
            matched_arr_rate
        END;

    -- Show results
    IF @dry_run = 1
    BEGIN
        SELECT
            event_idx,
            airport_icao,
            total_arrivals,
            total_departures,
            duration_hours,
            has_flight_data,
            primary_arr_runway,
            arr_heading_02, arr_heading_20, arr_heading_13, arr_heading_31,
            matched_config_name,
            matched_arr_rate,
            matched_dep_rate,
            calc_avg_aar,
            calc_avg_adr,
            calc_peak_aar
        FROM #event_analysis
        ORDER BY event_idx;
    END
    ELSE
    BEGIN
        -- Update the actual table
        UPDATE ea
        SET
            avg_vatsim_aar = a.calc_avg_aar,
            avg_vatsim_adr = a.calc_avg_adr,
            peak_vatsim_aar = a.calc_peak_aar
        FROM dbo.vatusa_event_airport ea
        JOIN #event_analysis a ON ea.event_idx = a.event_idx
                              AND ea.airport_icao = a.airport_icao
        WHERE a.calc_peak_aar IS NOT NULL;

        SELECT
            COUNT(*) as airports_updated,
            SUM(CASE WHEN has_flight_data = 1 THEN 1 ELSE 0 END) as with_flight_data,
            SUM(CASE WHEN has_flight_data = 0 THEN 1 ELSE 0 END) as using_config_defaults
        FROM #event_analysis
        WHERE calc_peak_aar IS NOT NULL;
    END

    DROP TABLE #event_analysis;
END
GO

-- ============================================================================
-- Add source column to track how AAR was determined
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_NAME = 'vatusa_event_airport' AND COLUMN_NAME = 'aar_source')
BEGIN
    ALTER TABLE dbo.vatusa_event_airport
    ADD aar_source VARCHAR(20) NULL;  -- 'EXCEL', 'FLIGHT_DATA', 'CONFIG_DEFAULT'
END
GO

PRINT 'Migration 079 complete: sp_CalculateEventAARFromFlights created';
GO
