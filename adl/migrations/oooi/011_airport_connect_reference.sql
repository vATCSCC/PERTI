-- =====================================================
-- Airport Connect-to-Push Reference
-- Migration: 011_airport_connect_reference.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Per-airport unimpeded connect-to-push time
--          reference table with self-refining recalculation
-- =====================================================
--
-- BACKGROUND (VATSIM-specific):
--
--   On VATSIM, first_seen_utc is the time a pilot connects to the network.
--   Pilots then spend time setting up the simulator, programming the FMC,
--   getting ATIS, requesting pushback, etc. before actually pushing back
--   from the gate (out_utc).
--
--   When first_seen is the only available departure time indicator (no
--   out_utc or off_utc), using it raw as "ready time" overestimates how
--   long a pilot was actually waiting. This table provides a per-airport
--   baseline for the unimpeded connect-to-push duration, analogous to
--   the airport_taxi_reference for unimpeded taxi-out time.
--
-- METHODOLOGY:
--
--   Adapted from the FAA ASPM p5-p15 percentile methodology used in
--   airport_taxi_reference (see 010_airport_taxi_reference.sql):
--
--   Unimpeded connect-to-push time is the average of all observed
--   first_seen-to-out_utc times falling between the 5th and 15th
--   percentiles of the distribution, computed over a 90-day rolling
--   window. This captures the fastest reasonable setup times.
--
--   Key differences from taxi reference:
--     - Metric: DATEDIFF(SECOND, first_seen_utc, out_utc) vs OUT-to-OFF
--     - Valid range: 60s to 7200s (1 min to 2 hours) vs 30s to 3600s
--     - Default: 900 seconds (15 min) vs 600s (10 min)
--     - Exclusions: GS-held and EDCT-delayed flights are excluded
--       because TMI holds artificially inflate connect-to-push time
--     - Data coverage: ~84% of flights have out_utc (vs ~15% with off_utc)
--
--   GS hold time adjustment:
--     ready_time = first_seen + unimpeded_connect_sec
--     hold_time = max(0, gs_end - max(ready_time, gs_start))
--
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. MAIN REFERENCE TABLE (per-airport)
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_connect_reference') AND type = 'U')
CREATE TABLE dbo.airport_connect_reference (
    airport_icao            VARCHAR(4) NOT NULL PRIMARY KEY,

    -- Unimpeded connect-to-push time (the key output)
    unimpeded_connect_sec   INT NOT NULL DEFAULT 900,       -- p5-p15 avg, or blended with default

    -- Statistical basis
    sample_size             INT NOT NULL DEFAULT 0,         -- # valid first_seen+out_utc obs in window
    window_days             INT NOT NULL DEFAULT 90,        -- Rolling window size used

    -- Distribution stats (for diagnostics and display)
    p05_connect_sec         INT NULL,                       -- 5th percentile
    p10_connect_sec         INT NULL,                       -- 10th percentile
    p15_connect_sec         INT NULL,                       -- 15th percentile
    p25_connect_sec         INT NULL,                       -- 25th percentile
    median_connect_sec      INT NULL,                       -- 50th percentile
    p75_connect_sec         INT NULL,                       -- 75th percentile
    p90_connect_sec         INT NULL,                       -- 90th percentile
    avg_connect_sec         INT NULL,                       -- Overall average
    min_connect_sec         INT NULL,                       -- Minimum observed
    max_connect_sec         INT NULL,                       -- Maximum observed
    stddev_connect_sec      INT NULL,                       -- Standard deviation

    -- Confidence indicator
    -- DEFAULT = no data, using 900s
    -- LOW     = 1-49 obs, blended toward default
    -- MEDIUM  = 50-199 obs, using observed p5-p15
    -- HIGH    = 200+ obs, highly reliable
    confidence              VARCHAR(8) NOT NULL DEFAULT 'DEFAULT',

    -- Metadata
    last_refreshed_utc      DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    created_utc             DATETIME2 NOT NULL DEFAULT GETUTCDATE()
);
GO

-- =====================================================
-- 2. DETAIL TABLE (per-airport + dimension breakdown)
-- Unified EAV-style table tracking multiple dimensions
-- for analysis of what factors affect connect-to-push time.
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_connect_reference_detail') AND type = 'U')
CREATE TABLE dbo.airport_connect_reference_detail (
    airport_icao            VARCHAR(4) NOT NULL,
    dimension               VARCHAR(16) NOT NULL,           -- WEIGHT_CLASS, CARRIER, ENGINE_CONFIG, DEST_REGION
    dimension_value         VARCHAR(16) NOT NULL,           -- e.g. H, AAL, TWINJET, DOMESTIC

    unimpeded_connect_sec   INT NOT NULL DEFAULT 900,       -- p5-p15 avg for this slice
    sample_size             INT NOT NULL DEFAULT 0,
    p05_connect_sec         INT NULL,
    p15_connect_sec         INT NULL,
    median_connect_sec      INT NULL,
    avg_connect_sec         INT NULL,

    last_refreshed_utc      DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

    CONSTRAINT PK_airport_connect_detail PRIMARY KEY (airport_icao, dimension, dimension_value)
);
GO

-- Index for querying all dimensions for a given airport
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_connect_detail_airport')
CREATE INDEX IX_connect_detail_airport ON dbo.airport_connect_reference_detail (airport_icao, dimension);
GO

-- =====================================================
-- 3. STORED PROCEDURE: sp_RefreshAirportConnectReference
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RefreshAirportConnectReference')
    DROP PROCEDURE dbo.sp_RefreshAirportConnectReference;
GO

CREATE PROCEDURE dbo.sp_RefreshAirportConnectReference
    @window_days INT = 90,
    @min_sample INT = 50,
    @default_connect_sec INT = 900,
    @min_valid_connect_sec INT = 60,
    @max_valid_connect_sec INT = 7200,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @window_start DATETIME2 = DATEADD(DAY, -@window_days, GETUTCDATE());
    DECLARE @airports_updated INT = 0;
    DECLARE @detail_updated INT = 0;

    IF @debug = 1
        PRINT 'sp_RefreshAirportConnectReference: window=' + CAST(@window_days AS VARCHAR)
            + ' days, min_sample=' + CAST(@min_sample AS VARCHAR)
            + ', default=' + CAST(@default_connect_sec AS VARCHAR) + 's'
            + ', range=' + CAST(@min_valid_connect_sec AS VARCHAR)
            + '-' + CAST(@max_valid_connect_sec AS VARCHAR) + 's';

    -- ===================================================
    -- Step 1: Gather all valid connect-to-push observations
    -- ===================================================
    -- Valid = has both first_seen_utc and out_utc, duration within bounds,
    -- flight departed within rolling window, NOT held by GS or EDCT
    CREATE TABLE #connect_obs (
        airport_icao    VARCHAR(4) NOT NULL,
        weight_class    VARCHAR(8),
        carrier         VARCHAR(4),
        engine_config   VARCHAR(12),
        dest_region     VARCHAR(16),
        connect_sec     INT NOT NULL
    );

    INSERT INTO #connect_obs (airport_icao, weight_class, carrier, engine_config, dest_region, connect_sec)
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
        DATEDIFF(SECOND, c.first_seen_utc, t.out_utc)
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
    INNER JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
    LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
    WHERE c.first_seen_utc IS NOT NULL
      AND t.out_utc IS NOT NULL
      AND t.out_utc >= @window_start
      AND t.out_utc > c.first_seen_utc
      AND DATEDIFF(SECOND, c.first_seen_utc, t.out_utc) BETWEEN @min_valid_connect_sec AND @max_valid_connect_sec
      AND p.fp_dept_icao IS NOT NULL
      AND LEN(p.fp_dept_icao) = 4
      AND p.fp_dest_icao IS NOT NULL
      -- Exclude GS-held flights (connect-to-push inflated by ground stop hold)
      AND (tmi.gs_held IS NULL OR tmi.gs_held = 0)
      -- Exclude EDCT-delayed flights (connect-to-push inflated by GDP delay)
      AND tmi.edct_utc IS NULL;

    DECLARE @obs_count INT = @@ROWCOUNT;

    IF @debug = 1
        PRINT '  Collected ' + CAST(@obs_count AS VARCHAR) + ' valid connect-to-push observations';

    -- ===================================================
    -- Step 2: Per-airport aggregation with percentiles
    -- ===================================================
    CREATE TABLE #airport_stats (
        airport_icao            VARCHAR(4) NOT NULL PRIMARY KEY,
        sample_size             INT NOT NULL,
        p05_connect_sec         INT NULL,
        p10_connect_sec         INT NULL,
        p15_connect_sec         INT NULL,
        p25_connect_sec         INT NULL,
        median_connect_sec      INT NULL,
        p75_connect_sec         INT NULL,
        p90_connect_sec         INT NULL,
        avg_connect_sec         INT NULL,
        min_connect_sec         INT NULL,
        max_connect_sec         INT NULL,
        stddev_connect_sec      INT NULL,
        p05_p15_avg_sec         INT NULL
    );

    ;WITH airport_agg AS (
        SELECT
            airport_icao,
            COUNT(*) as sample_size,
            AVG(connect_sec) as avg_connect_sec,
            MIN(connect_sec) as min_connect_sec,
            MAX(connect_sec) as max_connect_sec,
            CAST(STDEV(connect_sec) AS INT) as stddev_connect_sec
        FROM #connect_obs
        GROUP BY airport_icao
    ),
    airport_pct AS (
        SELECT DISTINCT
            o.airport_icao,
            CAST(PERCENTILE_CONT(0.05) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p05,
            CAST(PERCENTILE_CONT(0.10) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p10,
            CAST(PERCENTILE_CONT(0.15) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p15,
            CAST(PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p25,
            CAST(PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p50,
            CAST(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p75,
            CAST(PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY o.connect_sec)
                OVER (PARTITION BY o.airport_icao) AS INT) AS p90
        FROM #connect_obs o
    )
    INSERT INTO #airport_stats
    SELECT
        a.airport_icao,
        a.sample_size,
        ap.p05, ap.p10, ap.p15, ap.p25, ap.p50, ap.p75, ap.p90,
        a.avg_connect_sec, a.min_connect_sec, a.max_connect_sec, a.stddev_connect_sec,
        NULL
    FROM airport_agg a
    INNER JOIN airport_pct ap ON a.airport_icao = ap.airport_icao;

    IF @debug = 1
        PRINT '  Computed stats for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports';

    -- ===================================================
    -- Step 3: Compute p5-p15 average (unimpeded connect time)
    -- Average only observations between the 5th and 15th
    -- percentile values for each airport.
    -- ===================================================
    ;WITH p5_p15 AS (
        SELECT
            o.airport_icao,
            AVG(o.connect_sec) as p05_p15_avg
        FROM #connect_obs o
        INNER JOIN #airport_stats s ON o.airport_icao = s.airport_icao
        WHERE o.connect_sec >= s.p05_connect_sec
          AND o.connect_sec <= s.p15_connect_sec
        GROUP BY o.airport_icao
    )
    UPDATE s
    SET s.p05_p15_avg_sec = p.p05_p15_avg
    FROM #airport_stats s
    INNER JOIN p5_p15 p ON s.airport_icao = p.airport_icao;

    -- For airports where p5 == p15 (very tight distribution), fall back to p10
    UPDATE #airport_stats
    SET p05_p15_avg_sec = p10_connect_sec
    WHERE p05_p15_avg_sec IS NULL AND p10_connect_sec IS NOT NULL;

    -- ===================================================
    -- Step 4: UPSERT into airport_connect_reference
    -- Apply blending for small sample sizes:
    --   sample >= 50: use observed p5-p15 directly
    --   0 < sample < 50: linear blend toward 900s default
    --   sample = 0: use 900s default
    -- ===================================================
    MERGE dbo.airport_connect_reference AS tgt
    USING (
        SELECT
            airport_icao,
            CASE
                WHEN sample_size >= @min_sample
                    THEN COALESCE(p05_p15_avg_sec, p10_connect_sec, avg_connect_sec)
                WHEN sample_size > 0
                    THEN CAST(
                        (CAST(sample_size AS FLOAT) / @min_sample)
                            * COALESCE(p05_p15_avg_sec, p10_connect_sec, avg_connect_sec)
                        + (1.0 - CAST(sample_size AS FLOAT) / @min_sample)
                            * @default_connect_sec
                        AS INT)
                ELSE @default_connect_sec
            END AS unimpeded_connect_sec,
            sample_size,
            p05_connect_sec, p10_connect_sec, p15_connect_sec, p25_connect_sec,
            median_connect_sec, p75_connect_sec, p90_connect_sec,
            avg_connect_sec, min_connect_sec, max_connect_sec, stddev_connect_sec,
            CASE
                WHEN sample_size >= 200 THEN 'HIGH'
                WHEN sample_size >= @min_sample THEN 'MEDIUM'
                WHEN sample_size > 0 THEN 'LOW'
                ELSE 'DEFAULT'
            END AS confidence
        FROM #airport_stats
    ) AS src ON tgt.airport_icao = src.airport_icao
    WHEN MATCHED THEN UPDATE SET
        tgt.unimpeded_connect_sec = src.unimpeded_connect_sec,
        tgt.sample_size           = src.sample_size,
        tgt.window_days           = @window_days,
        tgt.p05_connect_sec       = src.p05_connect_sec,
        tgt.p10_connect_sec       = src.p10_connect_sec,
        tgt.p15_connect_sec       = src.p15_connect_sec,
        tgt.p25_connect_sec       = src.p25_connect_sec,
        tgt.median_connect_sec    = src.median_connect_sec,
        tgt.p75_connect_sec       = src.p75_connect_sec,
        tgt.p90_connect_sec       = src.p90_connect_sec,
        tgt.avg_connect_sec       = src.avg_connect_sec,
        tgt.min_connect_sec       = src.min_connect_sec,
        tgt.max_connect_sec       = src.max_connect_sec,
        tgt.stddev_connect_sec    = src.stddev_connect_sec,
        tgt.confidence            = src.confidence,
        tgt.last_refreshed_utc    = GETUTCDATE()
    WHEN NOT MATCHED THEN INSERT (
        airport_icao, unimpeded_connect_sec, sample_size, window_days,
        p05_connect_sec, p10_connect_sec, p15_connect_sec, p25_connect_sec,
        median_connect_sec, p75_connect_sec, p90_connect_sec,
        avg_connect_sec, min_connect_sec, max_connect_sec, stddev_connect_sec,
        confidence, last_refreshed_utc, created_utc
    ) VALUES (
        src.airport_icao, src.unimpeded_connect_sec, src.sample_size, @window_days,
        src.p05_connect_sec, src.p10_connect_sec, src.p15_connect_sec, src.p25_connect_sec,
        src.median_connect_sec, src.p75_connect_sec, src.p90_connect_sec,
        src.avg_connect_sec, src.min_connect_sec, src.max_connect_sec, src.stddev_connect_sec,
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
        connect_sec     INT NOT NULL
    );

    -- WEIGHT_CLASS dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'WEIGHT_CLASS', weight_class, connect_sec
    FROM #connect_obs
    WHERE weight_class IS NOT NULL AND weight_class <> '';

    -- CARRIER dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'CARRIER', carrier, connect_sec
    FROM #connect_obs
    WHERE carrier IS NOT NULL AND carrier <> '';

    -- ENGINE_CONFIG dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'ENGINE_CONFIG', engine_config, connect_sec
    FROM #connect_obs
    WHERE engine_config IS NOT NULL;

    -- DEST_REGION dimension
    INSERT INTO #dim_obs
    SELECT airport_icao, 'DEST_REGION', dest_region, connect_sec
    FROM #connect_obs
    WHERE dest_region IS NOT NULL;

    IF @debug = 1
        PRINT '  Dimension observations: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' (DEST_REGION batch)';

    -- Aggregate per dimension slice (min 5 observations for detail rows)
    ;WITH dim_agg AS (
        SELECT
            airport_icao, dimension, dimension_value,
            COUNT(*) as sample_size,
            AVG(connect_sec) as avg_connect_sec
        FROM #dim_obs
        GROUP BY airport_icao, dimension, dimension_value
        HAVING COUNT(*) >= 5
    ),
    dim_pct AS (
        SELECT DISTINCT
            d.airport_icao, d.dimension, d.dimension_value,
            CAST(PERCENTILE_CONT(0.05) WITHIN GROUP (ORDER BY d.connect_sec)
                OVER (PARTITION BY d.airport_icao, d.dimension, d.dimension_value) AS INT) AS p05,
            CAST(PERCENTILE_CONT(0.15) WITHIN GROUP (ORDER BY d.connect_sec)
                OVER (PARTITION BY d.airport_icao, d.dimension, d.dimension_value) AS INT) AS p15,
            CAST(PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY d.connect_sec)
                OVER (PARTITION BY d.airport_icao, d.dimension, d.dimension_value) AS INT) AS p50
        FROM #dim_obs d
        INNER JOIN dim_agg da ON d.airport_icao = da.airport_icao
            AND d.dimension = da.dimension
            AND d.dimension_value = da.dimension_value
    ),
    dim_unimpeded AS (
        SELECT
            d.airport_icao, d.dimension, d.dimension_value,
            AVG(d.connect_sec) as p05_p15_avg
        FROM #dim_obs d
        INNER JOIN dim_pct dp ON d.airport_icao = dp.airport_icao
            AND d.dimension = dp.dimension
            AND d.dimension_value = dp.dimension_value
        WHERE d.connect_sec >= dp.p05
          AND d.connect_sec <= dp.p15
        GROUP BY d.airport_icao, d.dimension, d.dimension_value
    )
    MERGE dbo.airport_connect_reference_detail AS tgt
    USING (
        SELECT
            da.airport_icao,
            da.dimension,
            da.dimension_value,
            COALESCE(du.p05_p15_avg, dp.p05, da.avg_connect_sec) AS unimpeded_connect_sec,
            da.sample_size,
            dp.p05 AS p05_connect_sec,
            dp.p15 AS p15_connect_sec,
            dp.p50 AS median_connect_sec,
            da.avg_connect_sec
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
        tgt.unimpeded_connect_sec = src.unimpeded_connect_sec,
        tgt.sample_size           = src.sample_size,
        tgt.p05_connect_sec       = src.p05_connect_sec,
        tgt.p15_connect_sec       = src.p15_connect_sec,
        tgt.median_connect_sec    = src.median_connect_sec,
        tgt.avg_connect_sec       = src.avg_connect_sec,
        tgt.last_refreshed_utc    = GETUTCDATE()
    WHEN NOT MATCHED THEN INSERT (
        airport_icao, dimension, dimension_value, unimpeded_connect_sec, sample_size,
        p05_connect_sec, p15_connect_sec, median_connect_sec, avg_connect_sec,
        last_refreshed_utc
    ) VALUES (
        src.airport_icao, src.dimension, src.dimension_value, src.unimpeded_connect_sec,
        src.sample_size, src.p05_connect_sec, src.p15_connect_sec, src.median_connect_sec,
        src.avg_connect_sec, GETUTCDATE()
    );

    SET @detail_updated = @@ROWCOUNT;

    -- ===================================================
    -- Step 6: Clean up stale airports (no observations in
    -- 2x window) - reset to default rather than delete
    -- ===================================================
    UPDATE dbo.airport_connect_reference
    SET unimpeded_connect_sec = @default_connect_sec,
        sample_size = 0,
        confidence = 'DEFAULT',
        last_refreshed_utc = GETUTCDATE()
    WHERE airport_icao NOT IN (SELECT DISTINCT airport_icao FROM #connect_obs)
      AND last_refreshed_utc < DATEADD(DAY, -(@window_days * 2), GETUTCDATE());

    -- Remove stale detail rows (no observations in 2x window)
    DELETE FROM dbo.airport_connect_reference_detail
    WHERE CONCAT(airport_icao, '|', dimension, '|', dimension_value) NOT IN (
        SELECT CONCAT(airport_icao, '|', dimension, '|', dimension_value) FROM #dim_obs
    )
    AND last_refreshed_utc < DATEADD(DAY, -(@window_days * 2), GETUTCDATE());

    -- Cleanup temp tables
    DROP TABLE #connect_obs;
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
            airport_icao, unimpeded_connect_sec, sample_size, confidence,
            p05_connect_sec, p15_connect_sec, median_connect_sec, avg_connect_sec
        FROM dbo.airport_connect_reference
        ORDER BY sample_size DESC;

        PRINT '';
        PRINT '  === Dimension detail row counts ===';
        SELECT dimension, COUNT(*) as rows, SUM(sample_size) as total_obs
        FROM dbo.airport_connect_reference_detail
        GROUP BY dimension
        ORDER BY dimension;
    END

    PRINT 'sp_RefreshAirportConnectReference complete: '
        + CAST(@airports_updated AS VARCHAR) + ' airports, '
        + CAST(@detail_updated AS VARCHAR) + ' detail rows, '
        + CAST(@elapsed_ms AS VARCHAR) + ' ms';
END
GO

-- =====================================================
-- 4. REGISTER IN JOB SCHEDULER
-- Runs daily at 02:15 UTC (after taxi reference at 02:00)
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_job_config WHERE job_name = 'AirportConnectReference')
BEGIN
    INSERT INTO dbo.flight_stats_job_config
    (job_name, procedure_name, schedule_type, schedule_utc_hour, schedule_utc_minute, description)
    VALUES
    ('AirportConnectReference', 'sp_RefreshAirportConnectReference', 'DAILY', 2, 15,
     'Recalculates per-airport unimpeded connect-to-push times from 90-day window (p5-p15 method)');
END
GO

-- =====================================================
-- 5. INITIAL SEED: Run immediately to populate from
--    existing historical data
-- =====================================================

PRINT 'Running initial seed of airport_connect_reference...';
EXEC dbo.sp_RefreshAirportConnectReference @debug = 1;
GO

PRINT '';
PRINT '=== Airport Connect Reference Migration Complete ===';
PRINT '';
PRINT 'Tables created:';
PRINT '  - dbo.airport_connect_reference           (per-airport unimpeded connect-to-push time)';
PRINT '  - dbo.airport_connect_reference_detail     (per-airport + dimension breakdowns)';
PRINT '    Dimensions: WEIGHT_CLASS, CARRIER, ENGINE_CONFIG, DEST_REGION';
PRINT '';
PRINT 'Stored procedure:';
PRINT '  - dbo.sp_RefreshAirportConnectReference    (daily recalculation)';
PRINT '';
PRINT 'Job registered: AirportConnectReference (daily at 02:15 UTC)';
PRINT '';
PRINT 'Methodology: FAA ASPM p5-p15 average adapted for VATSIM connect-to-push';
PRINT '  - Metric: DATEDIFF(SECOND, first_seen_utc, out_utc)';
PRINT '  - Window: 90 days rolling';
PRINT '  - Min sample: 50 flights with valid first_seen+out_utc';
PRINT '  - Default: 900 seconds (15 min) when insufficient data';
PRINT '  - Blending: linear blend from default as sample approaches 50';
PRINT '  - Exclusions: GS-held and EDCT-delayed flights';
GO
