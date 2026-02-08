-- =====================================================
-- Airport Taxi Time Reference
-- Migration: 010_airport_taxi_reference.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Per-airport unimpeded taxi time reference table
--          with self-refining recalculation from OOOI data
-- =====================================================
--
-- METHODOLOGY (FAA ASPM / MITRE DTE Standard):
--
--   Unimpeded taxi-out time is the average of all observed OUT-to-OFF
--   times falling between the 5th and 15th percentiles of the distribution,
--   computed over a 90-day rolling window.
--
--   This follows the FAA's ASPM "Departure Taxi Efficiency" methodology
--   documented in:
--     - ASPM Taxi Times: Definitions of Variables (aspm.faa.gov)
--     - MITRE CAASD "Measuring Flight Efficiency in the NAS" (2015)
--       Section: Taxi Efficiency, pp. 9-10
--     - Zhang & Wang, "Methods for determining unimpeded aircraft taxiing
--       time" (Chinese Journal of Aeronautics, 2017, 30(2): 523-537)
--
--   FAA uses per-airport, per-carrier, per-runway, per-weight-class,
--   per-weather-category stratification with 12-month lookback and
--   minimum sample size of 30.
--
--   VATSIM adaptation:
--     - Primary stratification: per-airport only (smaller sample sizes)
--     - Detail tracking: per-airport + dimension breakdowns for analysis
--       Dimensions tracked:
--         WEIGHT_CLASS   - S, L, H, B757, J (Super)
--         CARRIER        - Airline ICAO code (AAL, DAL, UAL, etc.)
--         ENGINE_CONFIG  - TWINJET, QUADJET, TRIJET, SINGLEJET, TURBOPROP, PISTON
--         DEST_REGION    - DOMESTIC (same first letter), INTERNATIONAL
--     - Rolling window: 90 days (faster refinement than FAA's 12 months)
--     - Minimum sample: 50 flights with valid OUT+OFF times
--     - Default: 600 seconds (10 minutes) when insufficient data
--     - Blending: linear blend from default toward observed as sample grows
--       toward 50; at sample_size >= 50 the observed value is used directly
--
--   GS delay for a flight is then:
--     gs_delay = max(0, actual_taxi_out - unimpeded_taxi_out)
--   where actual_taxi_out = OFF - OUT
--
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. MAIN REFERENCE TABLE (per-airport)
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_taxi_reference') AND type = 'U')
CREATE TABLE dbo.airport_taxi_reference (
    airport_icao        VARCHAR(4) NOT NULL PRIMARY KEY,

    -- Unimpeded taxi time (the key output)
    unimpeded_taxi_sec  INT NOT NULL DEFAULT 600,       -- p5-p15 avg, or blended with default

    -- Statistical basis
    sample_size         INT NOT NULL DEFAULT 0,         -- # valid OUT+OFF obs in window
    window_days         INT NOT NULL DEFAULT 90,        -- Rolling window size used

    -- Distribution stats (for diagnostics and display)
    p05_taxi_sec        INT NULL,                       -- 5th percentile
    p10_taxi_sec        INT NULL,                       -- 10th percentile
    p15_taxi_sec        INT NULL,                       -- 15th percentile
    p25_taxi_sec        INT NULL,                       -- 25th percentile
    median_taxi_sec     INT NULL,                       -- 50th percentile
    p75_taxi_sec        INT NULL,                       -- 75th percentile
    p90_taxi_sec        INT NULL,                       -- 90th percentile
    avg_taxi_sec        INT NULL,                       -- Overall average
    min_taxi_sec        INT NULL,                       -- Minimum observed
    max_taxi_sec        INT NULL,                       -- Maximum observed
    stddev_taxi_sec     INT NULL,                       -- Standard deviation

    -- Confidence indicator
    -- DEFAULT = no data, using 600s
    -- LOW     = 1-49 obs, blended toward default
    -- MEDIUM  = 50-199 obs, using observed p5-p15
    -- HIGH    = 200+ obs, highly reliable
    confidence          VARCHAR(8) NOT NULL DEFAULT 'DEFAULT',

    -- Metadata
    last_refreshed_utc  DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    created_utc         DATETIME2 NOT NULL DEFAULT GETUTCDATE()
);
GO

-- =====================================================
-- 2. DETAIL TABLE (per-airport + dimension breakdown)
-- Unified EAV-style table tracking multiple dimensions
-- for analysis of what factors affect taxi time.
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_taxi_reference_detail') AND type = 'U')
CREATE TABLE dbo.airport_taxi_reference_detail (
    airport_icao        VARCHAR(4) NOT NULL,
    dimension           VARCHAR(16) NOT NULL,           -- WEIGHT_CLASS, CARRIER, ENGINE_CONFIG, DEST_REGION
    dimension_value     VARCHAR(16) NOT NULL,           -- e.g. H, AAL, TWINJET, DOMESTIC

    unimpeded_taxi_sec  INT NOT NULL DEFAULT 600,       -- p5-p15 avg for this slice
    sample_size         INT NOT NULL DEFAULT 0,
    p05_taxi_sec        INT NULL,
    p15_taxi_sec        INT NULL,
    median_taxi_sec     INT NULL,
    avg_taxi_sec        INT NULL,

    last_refreshed_utc  DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

    CONSTRAINT PK_airport_taxi_detail PRIMARY KEY (airport_icao, dimension, dimension_value)
);
GO

-- Index for querying all dimensions for a given airport
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_taxi_detail_airport')
CREATE INDEX IX_taxi_detail_airport ON dbo.airport_taxi_reference_detail (airport_icao, dimension);
GO

-- =====================================================
-- 3. STORED PROCEDURE: sp_RefreshAirportTaxiReference
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RefreshAirportTaxiReference')
    DROP PROCEDURE dbo.sp_RefreshAirportTaxiReference;
GO

CREATE PROCEDURE dbo.sp_RefreshAirportTaxiReference
    @window_days INT = 90,
    @min_sample INT = 50,
    @default_taxi_sec INT = 600,
    @min_valid_taxi_sec INT = 30,
    @max_valid_taxi_sec INT = 3600,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @window_start DATETIME2 = DATEADD(DAY, -@window_days, GETUTCDATE());
    DECLARE @airports_updated INT = 0;
    DECLARE @detail_updated INT = 0;

    IF @debug = 1
        PRINT 'sp_RefreshAirportTaxiReference: window=' + CAST(@window_days AS VARCHAR)
            + ' days, min_sample=' + CAST(@min_sample AS VARCHAR)
            + ', default=' + CAST(@default_taxi_sec AS VARCHAR) + 's'
            + ', range=' + CAST(@min_valid_taxi_sec AS VARCHAR)
            + '-' + CAST(@max_valid_taxi_sec AS VARCHAR) + 's';

    -- ===================================================
    -- Step 1: Gather all valid taxi observations into temp
    -- ===================================================
    -- Valid = has both out_utc and off_utc, taxi time within bounds,
    -- flight departed within the rolling window
    CREATE TABLE #taxi_obs (
        airport_icao    VARCHAR(4) NOT NULL,
        weight_class    VARCHAR(8),
        carrier         VARCHAR(4),
        engine_config   VARCHAR(12),
        dest_region     VARCHAR(16),
        taxi_out_sec    INT NOT NULL
    );

    INSERT INTO #taxi_obs (airport_icao, weight_class, carrier, engine_config, dest_region, taxi_out_sec)
    SELECT
        p.fp_dept_icao,
        a.weight_class,
        a.airline_icao,
        -- Derive engine config from engine_type + engine_count
        CASE
            WHEN a.engine_type = 'J' AND a.engine_count = 1 THEN 'SINGLEJET'
            WHEN a.engine_type = 'J' AND a.engine_count = 2 THEN 'TWINJET'
            WHEN a.engine_type = 'J' AND a.engine_count = 3 THEN 'TRIJET'
            WHEN a.engine_type = 'J' AND a.engine_count = 4 THEN 'QUADJET'
            WHEN a.engine_type = 'T' THEN 'TURBOPROP'
            WHEN a.engine_type = 'P' THEN 'PISTON'
            ELSE NULL
        END,
        -- Derive destination region: domestic = same ICAO prefix letter
        CASE
            WHEN LEFT(p.fp_dept_icao, 1) = LEFT(p.fp_dest_icao, 1) THEN 'DOMESTIC'
            ELSE 'INTERNATIONAL'
        END,
        DATEDIFF(SECOND, t.out_utc, t.off_utc)
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
    INNER JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
    WHERE t.out_utc IS NOT NULL
      AND t.off_utc IS NOT NULL
      AND t.out_utc >= @window_start
      AND t.off_utc > t.out_utc
      AND DATEDIFF(SECOND, t.out_utc, t.off_utc) BETWEEN @min_valid_taxi_sec AND @max_valid_taxi_sec
      AND p.fp_dept_icao IS NOT NULL
      AND LEN(p.fp_dept_icao) = 4
      AND p.fp_dest_icao IS NOT NULL;

    DECLARE @obs_count INT = @@ROWCOUNT;

    IF @debug = 1
        PRINT '  Collected ' + CAST(@obs_count AS VARCHAR) + ' valid taxi observations';

    -- ===================================================
    -- Step 2: Per-airport aggregation with percentiles
    -- ===================================================
    CREATE TABLE #airport_stats (
        airport_icao        VARCHAR(4) NOT NULL PRIMARY KEY,
        sample_size         INT NOT NULL,
        p05_taxi_sec        INT NULL,
        p10_taxi_sec        INT NULL,
        p15_taxi_sec        INT NULL,
        p25_taxi_sec        INT NULL,
        median_taxi_sec     INT NULL,
        p75_taxi_sec        INT NULL,
        p90_taxi_sec        INT NULL,
        avg_taxi_sec        INT NULL,
        min_taxi_sec        INT NULL,
        max_taxi_sec        INT NULL,
        stddev_taxi_sec     INT NULL,
        p05_p15_avg_sec     INT NULL
    );

    ;WITH airport_agg AS (
        SELECT
            airport_icao,
            COUNT(*) as sample_size,
            AVG(taxi_out_sec) as avg_taxi_sec,
            MIN(taxi_out_sec) as min_taxi_sec,
            MAX(taxi_out_sec) as max_taxi_sec,
            CAST(STDEV(taxi_out_sec) AS INT) as stddev_taxi_sec
        FROM #taxi_obs
        GROUP BY airport_icao
    ),
    airport_pct AS (
        SELECT DISTINCT
            o.airport_icao,
            CAST(PERCENTILE_CONT(0.05) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p05,
            CAST(PERCENTILE_CONT(0.10) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p10,
            CAST(PERCENTILE_CONT(0.15) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p15,
            CAST(PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p25,
            CAST(PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p50,
            CAST(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p75,
            CAST(PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY o.taxi_out_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p90
        FROM #taxi_obs o
    )
    INSERT INTO #airport_stats
    SELECT
        a.airport_icao,
        a.sample_size,
        ap.p05, ap.p10, ap.p15, ap.p25, ap.p50, ap.p75, ap.p90,
        a.avg_taxi_sec, a.min_taxi_sec, a.max_taxi_sec, a.stddev_taxi_sec,
        NULL
    FROM airport_agg a
    INNER JOIN airport_pct ap ON a.airport_icao = ap.airport_icao;

    IF @debug = 1
        PRINT '  Computed stats for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports';

    -- ===================================================
    -- Step 3: Compute p5-p15 average (unimpeded taxi time)
    -- Average only observations between the 5th and 15th
    -- percentile values for each airport.
    -- ===================================================
    ;WITH p5_p15 AS (
        SELECT
            o.airport_icao,
            AVG(o.taxi_out_sec) as p05_p15_avg
        FROM #taxi_obs o
        INNER JOIN #airport_stats s ON o.airport_icao = s.airport_icao
        WHERE o.taxi_out_sec >= s.p05_taxi_sec
          AND o.taxi_out_sec <= s.p15_taxi_sec
        GROUP BY o.airport_icao
    )
    UPDATE s
    SET s.p05_p15_avg_sec = p.p05_p15_avg
    FROM #airport_stats s
    INNER JOIN p5_p15 p ON s.airport_icao = p.airport_icao;

    -- For airports where p5 == p15 (very tight distribution), fall back to p10
    UPDATE #airport_stats
    SET p05_p15_avg_sec = p10_taxi_sec
    WHERE p05_p15_avg_sec IS NULL AND p10_taxi_sec IS NOT NULL;

    -- ===================================================
    -- Step 4: UPSERT into airport_taxi_reference
    -- Apply blending for small sample sizes:
    --   sample >= 50: use observed p5-p15 directly
    --   0 < sample < 50: linear blend toward 600s default
    --   sample = 0: use 600s default
    -- ===================================================
    MERGE dbo.airport_taxi_reference AS tgt
    USING (
        SELECT
            airport_icao,
            CASE
                WHEN sample_size >= @min_sample
                    THEN COALESCE(p05_p15_avg_sec, p10_taxi_sec, avg_taxi_sec)
                WHEN sample_size > 0
                    THEN CAST(
                        (CAST(sample_size AS FLOAT) / @min_sample)
                            * COALESCE(p05_p15_avg_sec, p10_taxi_sec, avg_taxi_sec)
                        + (1.0 - CAST(sample_size AS FLOAT) / @min_sample)
                            * @default_taxi_sec
                        AS INT)
                ELSE @default_taxi_sec
            END AS unimpeded_taxi_sec,
            sample_size,
            p05_taxi_sec, p10_taxi_sec, p15_taxi_sec, p25_taxi_sec,
            median_taxi_sec, p75_taxi_sec, p90_taxi_sec,
            avg_taxi_sec, min_taxi_sec, max_taxi_sec, stddev_taxi_sec,
            CASE
                WHEN sample_size >= 200 THEN 'HIGH'
                WHEN sample_size >= @min_sample THEN 'MEDIUM'
                WHEN sample_size > 0 THEN 'LOW'
                ELSE 'DEFAULT'
            END AS confidence
        FROM #airport_stats
    ) AS src ON tgt.airport_icao = src.airport_icao
    WHEN MATCHED THEN UPDATE SET
        tgt.unimpeded_taxi_sec = src.unimpeded_taxi_sec,
        tgt.sample_size        = src.sample_size,
        tgt.window_days        = @window_days,
        tgt.p05_taxi_sec       = src.p05_taxi_sec,
        tgt.p10_taxi_sec       = src.p10_taxi_sec,
        tgt.p15_taxi_sec       = src.p15_taxi_sec,
        tgt.p25_taxi_sec       = src.p25_taxi_sec,
        tgt.median_taxi_sec    = src.median_taxi_sec,
        tgt.p75_taxi_sec       = src.p75_taxi_sec,
        tgt.p90_taxi_sec       = src.p90_taxi_sec,
        tgt.avg_taxi_sec       = src.avg_taxi_sec,
        tgt.min_taxi_sec       = src.min_taxi_sec,
        tgt.max_taxi_sec       = src.max_taxi_sec,
        tgt.stddev_taxi_sec    = src.stddev_taxi_sec,
        tgt.confidence         = src.confidence,
        tgt.last_refreshed_utc = GETUTCDATE()
    WHEN NOT MATCHED THEN INSERT (
        airport_icao, unimpeded_taxi_sec, sample_size, window_days,
        p05_taxi_sec, p10_taxi_sec, p15_taxi_sec, p25_taxi_sec,
        median_taxi_sec, p75_taxi_sec, p90_taxi_sec,
        avg_taxi_sec, min_taxi_sec, max_taxi_sec, stddev_taxi_sec,
        confidence, last_refreshed_utc, created_utc
    ) VALUES (
        src.airport_icao, src.unimpeded_taxi_sec, src.sample_size, @window_days,
        src.p05_taxi_sec, src.p10_taxi_sec, src.p15_taxi_sec, src.p25_taxi_sec,
        src.median_taxi_sec, src.p75_taxi_sec, src.p90_taxi_sec,
        src.avg_taxi_sec, src.min_taxi_sec, src.max_taxi_sec, src.stddev_taxi_sec,
        src.confidence, GETUTCDATE(), GETUTCDATE()
    );

    SET @airports_updated = @@ROWCOUNT;

    -- ===================================================
    -- Step 5: Per-airport dimension breakdowns
    -- Compute p5-p15 for each (airport, dimension, value)
    -- using a unified approach across all 4 dimensions.
    -- ===================================================

    -- Build a flattened dimension table from observations
    CREATE TABLE #dim_obs (
        airport_icao    VARCHAR(4) NOT NULL,
        dimension       VARCHAR(16) NOT NULL,
        dimension_value VARCHAR(16) NOT NULL,
        taxi_out_sec    INT NOT NULL
    );

    -- WEIGHT_CLASS dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'WEIGHT_CLASS', weight_class, taxi_out_sec
    FROM #taxi_obs
    WHERE weight_class IS NOT NULL AND weight_class <> '';

    -- CARRIER dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'CARRIER', carrier, taxi_out_sec
    FROM #taxi_obs
    WHERE carrier IS NOT NULL AND carrier <> '';

    -- ENGINE_CONFIG dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'ENGINE_CONFIG', engine_config, taxi_out_sec
    FROM #taxi_obs
    WHERE engine_config IS NOT NULL;

    -- DEST_REGION dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'DEST_REGION', dest_region, taxi_out_sec
    FROM #taxi_obs
    WHERE dest_region IS NOT NULL;

    IF @debug = 1
        PRINT '  Dimension observations: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' (DEST_REGION batch)';

    -- Aggregate per dimension slice (min 5 observations for detail rows)
    ;WITH dim_agg AS (
        SELECT
            airport_icao, dimension, dimension_value,
            COUNT(*) as sample_size,
            AVG(taxi_out_sec) as avg_taxi_sec
        FROM #dim_obs
        GROUP BY airport_icao, dimension, dimension_value
        HAVING COUNT(*) >= 5
    ),
    dim_pct AS (
        SELECT DISTINCT
            d.airport_icao, d.dimension, d.dimension_value,
            CAST(PERCENTILE_CONT(0.05) WITHIN GROUP (ORDER BY d.taxi_out_sec)
                OVER (PARTITION BY d.airport_icao, d.dimension, d.dimension_value) AS INT) AS p05,
            CAST(PERCENTILE_CONT(0.15) WITHIN GROUP (ORDER BY d.taxi_out_sec)
                OVER (PARTITION BY d.airport_icao, d.dimension, d.dimension_value) AS INT) AS p15,
            CAST(PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY d.taxi_out_sec)
                OVER (PARTITION BY d.airport_icao, d.dimension, d.dimension_value) AS INT) AS p50
        FROM #dim_obs d
        INNER JOIN dim_agg da ON d.airport_icao = da.airport_icao
            AND d.dimension = da.dimension
            AND d.dimension_value = da.dimension_value
    ),
    dim_unimpeded AS (
        SELECT
            d.airport_icao, d.dimension, d.dimension_value,
            AVG(d.taxi_out_sec) as p05_p15_avg
        FROM #dim_obs d
        INNER JOIN dim_pct dp ON d.airport_icao = dp.airport_icao
            AND d.dimension = dp.dimension
            AND d.dimension_value = dp.dimension_value
        WHERE d.taxi_out_sec >= dp.p05
          AND d.taxi_out_sec <= dp.p15
        GROUP BY d.airport_icao, d.dimension, d.dimension_value
    )
    MERGE dbo.airport_taxi_reference_detail AS tgt
    USING (
        SELECT
            da.airport_icao,
            da.dimension,
            da.dimension_value,
            COALESCE(du.p05_p15_avg, dp.p05, da.avg_taxi_sec) AS unimpeded_taxi_sec,
            da.sample_size,
            dp.p05 AS p05_taxi_sec,
            dp.p15 AS p15_taxi_sec,
            dp.p50 AS median_taxi_sec,
            da.avg_taxi_sec
        FROM dim_agg da
        INNER JOIN dim_pct dp ON da.airport_icao = dp.airport_icao
            AND da.dimension = dp.dimension
            AND da.dimension_value = dp.dimension_value
        LEFT JOIN dim_unimpeded du ON da.airport_icao = du.airport_icao
            AND da.dimension = du.dimension
            AND da.dimension_value = du.dimension_value
    ) AS src ON tgt.airport_icao = src.airport_icao
        AND tgt.dimension = src.dimension
        AND tgt.dimension_value = src.dimension_value
    WHEN MATCHED THEN UPDATE SET
        tgt.unimpeded_taxi_sec = src.unimpeded_taxi_sec,
        tgt.sample_size        = src.sample_size,
        tgt.p05_taxi_sec       = src.p05_taxi_sec,
        tgt.p15_taxi_sec       = src.p15_taxi_sec,
        tgt.median_taxi_sec    = src.median_taxi_sec,
        tgt.avg_taxi_sec       = src.avg_taxi_sec,
        tgt.last_refreshed_utc = GETUTCDATE()
    WHEN NOT MATCHED THEN INSERT (
        airport_icao, dimension, dimension_value, unimpeded_taxi_sec, sample_size,
        p05_taxi_sec, p15_taxi_sec, median_taxi_sec, avg_taxi_sec,
        last_refreshed_utc
    ) VALUES (
        src.airport_icao, src.dimension, src.dimension_value, src.unimpeded_taxi_sec,
        src.sample_size, src.p05_taxi_sec, src.p15_taxi_sec, src.median_taxi_sec,
        src.avg_taxi_sec, GETUTCDATE()
    );

    SET @detail_updated = @@ROWCOUNT;

    -- ===================================================
    -- Step 6: Clean up stale airports (no observations in
    -- 2x window) - reset to default rather than delete
    -- ===================================================
    UPDATE dbo.airport_taxi_reference
    SET unimpeded_taxi_sec = @default_taxi_sec,
        sample_size = 0,
        confidence = 'DEFAULT',
        last_refreshed_utc = GETUTCDATE()
    WHERE airport_icao NOT IN (SELECT DISTINCT airport_icao FROM #taxi_obs)
      AND last_refreshed_utc < DATEADD(DAY, -(@window_days * 2), GETUTCDATE());

    -- Remove stale detail rows (no observations in 2x window)
    DELETE FROM dbo.airport_taxi_reference_detail
    WHERE CONCAT(airport_icao, '|', dimension, '|', dimension_value) NOT IN (
        SELECT CONCAT(airport_icao, '|', dimension, '|', dimension_value) FROM #dim_obs
    )
    AND last_refreshed_utc < DATEADD(DAY, -(@window_days * 2), GETUTCDATE());

    -- Cleanup temp tables
    DROP TABLE #taxi_obs;
    DROP TABLE #airport_stats;
    DROP TABLE #dim_obs;

    -- Report
    DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE());

    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT '  Airports updated: ' + CAST(@airports_updated AS VARCHAR);
        PRINT '  Detail rows updated: ' + CAST(@detail_updated AS VARCHAR);
        PRINT '  Elapsed: ' + CAST(@elapsed_ms AS VARCHAR) + ' ms';

        PRINT '';
        PRINT '  === Top 20 airports by sample size ===';
        SELECT TOP 20
            airport_icao, unimpeded_taxi_sec, sample_size, confidence,
            p05_taxi_sec, p15_taxi_sec, median_taxi_sec, avg_taxi_sec
        FROM dbo.airport_taxi_reference
        ORDER BY sample_size DESC;

        PRINT '';
        PRINT '  === Dimension detail row counts ===';
        SELECT dimension, COUNT(*) as rows, SUM(sample_size) as total_obs
        FROM dbo.airport_taxi_reference_detail
        GROUP BY dimension
        ORDER BY dimension;
    END

    PRINT 'sp_RefreshAirportTaxiReference complete: '
        + CAST(@airports_updated AS VARCHAR) + ' airports, '
        + CAST(@detail_updated AS VARCHAR) + ' detail rows, '
        + CAST(@elapsed_ms AS VARCHAR) + ' ms';
END
GO

-- =====================================================
-- 4. REGISTER IN JOB SCHEDULER
-- Runs daily at 02:00 UTC
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_job_config WHERE job_name = 'AirportTaxiReference')
BEGIN
    INSERT INTO dbo.flight_stats_job_config
    (job_name, procedure_name, schedule_type, schedule_utc_hour, schedule_utc_minute, description)
    VALUES
    ('AirportTaxiReference', 'sp_RefreshAirportTaxiReference', 'DAILY', 2, 0,
     'Recalculates per-airport unimpeded taxi times from 90-day OOOI window (FAA p5-p15 method)');
END
GO

-- =====================================================
-- 5. INITIAL SEED: Run immediately to populate from
--    existing historical data
-- =====================================================

PRINT 'Running initial seed of airport_taxi_reference...';
EXEC dbo.sp_RefreshAirportTaxiReference @debug = 1;
GO

PRINT '';
PRINT '=== Airport Taxi Reference Migration Complete ===';
PRINT '';
PRINT 'Tables created:';
PRINT '  - dbo.airport_taxi_reference           (per-airport unimpeded taxi time)';
PRINT '  - dbo.airport_taxi_reference_detail     (per-airport + dimension breakdowns)';
PRINT '    Dimensions: WEIGHT_CLASS, CARRIER, ENGINE_CONFIG, DEST_REGION';
PRINT '';
PRINT 'Stored procedure:';
PRINT '  - dbo.sp_RefreshAirportTaxiReference    (daily recalculation)';
PRINT '';
PRINT 'Job registered: AirportTaxiReference (daily at 02:00 UTC)';
PRINT '';
PRINT 'Methodology: FAA ASPM p5-p15 average (unimpeded taxi time)';
PRINT '  - Source: MITRE "Measuring Flight Efficiency in the NAS" (2015)';
PRINT '  - Window: 90 days rolling';
PRINT '  - Min sample: 50 flights with valid OUT+OFF';
PRINT '  - Default: 600 seconds (10 min) when insufficient data';
PRINT '  - Blending: linear blend from default as sample approaches 50';
GO
