-- ============================================================================
-- MIGRATE: Legacy adl_flights_history to Normalized Schema
--
-- Processes 213.8M legacy snapshots through the same tier rules as live data
-- Results in properly sampled trajectory data matching current processing
--
-- Source: adl_flights_history (Dec 9, 2025 - Jan 6, 2026)
-- Targets:
--   - adl_flight_core (437K unique flights)
--   - adl_flight_trajectory (sampled positions based on tier)
--
-- Estimated runtime: 2-4 hours for full migration
-- ============================================================================

SET NOCOUNT ON;
SET XACT_ABORT ON;

PRINT '=== Legacy Data Migration to Normalized Schema ==='
PRINT 'Started: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)
PRINT ''

-- ============================================================================
-- PHASE 1: Migrate Unique Flights to adl_flight_core
-- ============================================================================

PRINT '--- PHASE 1: Migrating unique flights to adl_flight_core ---'

DECLARE @flights_inserted INT = 0;
DECLARE @flights_skipped INT = 0;

-- Get the last flight_uid to generate new ones after it
DECLARE @max_flight_uid BIGINT;
SELECT @max_flight_uid = ISNULL(MAX(flight_uid), 0) FROM dbo.adl_flight_core;
PRINT 'Current max flight_uid: ' + CAST(@max_flight_uid AS VARCHAR);

-- Insert unique flights that don't already exist
INSERT INTO dbo.adl_flight_core (
    flight_key, cid, callsign, flight_id, phase, last_source, is_active,
    first_seen_utc, last_seen_utc, logon_time_utc,
    adl_date, adl_time, snapshot_utc
)
SELECT DISTINCT
    h.flight_key,
    h.cid,
    h.callsign,
    h.flight_id,
    'disconnected' AS phase,  -- Historical flights are disconnected
    'legacy' AS last_source,
    0 AS is_active,
    MIN(h.snapshot_utc) OVER (PARTITION BY h.flight_key) AS first_seen_utc,
    MAX(h.snapshot_utc) OVER (PARTITION BY h.flight_key) AS last_seen_utc,
    NULL AS logon_time_utc,
    CAST(MIN(h.snapshot_utc) OVER (PARTITION BY h.flight_key) AS DATE) AS adl_date,
    CAST(MIN(h.snapshot_utc) OVER (PARTITION BY h.flight_key) AS TIME) AS adl_time,
    MAX(h.snapshot_utc) OVER (PARTITION BY h.flight_key) AS snapshot_utc
FROM dbo.adl_flights_history h
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.adl_flight_core c WHERE c.flight_key = h.flight_key
)
GROUP BY h.flight_key, h.cid, h.callsign, h.flight_id;

SET @flights_inserted = @@ROWCOUNT;
PRINT 'Flights inserted: ' + CAST(@flights_inserted AS VARCHAR);

-- ============================================================================
-- PHASE 2: Create Flight UID Mapping
-- ============================================================================

PRINT ''
PRINT '--- PHASE 2: Creating flight_uid mapping ---'

-- Create temp table to map flight_key to flight_uid
IF OBJECT_ID('tempdb..#flight_map') IS NOT NULL DROP TABLE #flight_map;

SELECT flight_key, flight_uid
INTO #flight_map
FROM dbo.adl_flight_core
WHERE flight_key IN (SELECT DISTINCT flight_key FROM dbo.adl_flights_history);

CREATE UNIQUE INDEX IX_flight_map ON #flight_map(flight_key);

DECLARE @mapped_flights INT;
SELECT @mapped_flights = COUNT(*) FROM #flight_map;
PRINT 'Flights mapped: ' + CAST(@mapped_flights AS VARCHAR);

-- ============================================================================
-- PHASE 3: Migrate Sampled Trajectory Data
--
-- Uses ROW_NUMBER() with tier-based sampling to select positions
-- at the appropriate intervals matching current tier rules
-- ============================================================================

PRINT ''
PRINT '--- PHASE 3: Migrating trajectory data with tier sampling ---'
PRINT 'This phase processes in batches to avoid transaction log issues...'

DECLARE @batch_size INT = 100000;
DECLARE @batch_num INT = 0;
DECLARE @total_inserted INT = 0;
DECLARE @rows_in_batch INT = 1;
DECLARE @min_history_id BIGINT = 0;
DECLARE @max_history_id BIGINT;

SELECT @max_history_id = MAX(history_id) FROM dbo.adl_flights_history;
PRINT 'Processing history_id range: 0 to ' + CAST(@max_history_id AS VARCHAR);

WHILE @rows_in_batch > 0
BEGIN
    SET @batch_num = @batch_num + 1;

    -- Process batch: sample positions based on tier intervals
    ;WITH RankedPositions AS (
        SELECT
            m.flight_uid,
            h.snapshot_utc AS recorded_utc,
            h.lat,
            h.lon,
            h.altitude AS altitude_ft,
            h.groundspeed AS groundspeed_kts,
            NULL AS vertical_rate_fpm,  -- Not in legacy data
            h.heading_deg,
            NULL AS track_deg,
            'legacy' AS source,
            h.phase AS flight_phase,
            h.history_id,
            -- Calculate pseudo-tier based on available data
            CASE
                -- Critical: near airports
                WHEN h.lat IS NOT NULL AND h.groundspeed > 40 AND h.altitude < 500 THEN 0
                -- Approaching: descending or < 100nm from destination
                WHEN h.phase IN ('descending', 'departed', 'climbing') THEN 1
                -- Ground ops
                WHEN h.groundspeed BETWEEN 5 AND 35 AND h.altitude < 500 THEN 3
                -- Stable cruise
                WHEN h.altitude > 20000 AND h.groundspeed > 200 THEN 4
                -- Default
                ELSE 2
            END AS calc_tier,
            ROW_NUMBER() OVER (
                PARTITION BY m.flight_uid,
                -- Group by time buckets based on tier
                CASE
                    WHEN h.groundspeed > 40 AND h.altitude < 500 THEN
                        DATEADD(SECOND, (DATEDIFF(SECOND, '2000-01-01', h.snapshot_utc) / 15) * 15, '2000-01-01')  -- 15s
                    WHEN h.phase IN ('descending', 'departed', 'climbing') THEN
                        DATEADD(SECOND, (DATEDIFF(SECOND, '2000-01-01', h.snapshot_utc) / 30) * 30, '2000-01-01')  -- 30s
                    WHEN h.groundspeed BETWEEN 5 AND 35 AND h.altitude < 500 THEN
                        DATEADD(SECOND, (DATEDIFF(SECOND, '2000-01-01', h.snapshot_utc) / 120) * 120, '2000-01-01') -- 2min
                    WHEN h.altitude > 20000 AND h.groundspeed > 200 THEN
                        DATEADD(SECOND, (DATEDIFF(SECOND, '2000-01-01', h.snapshot_utc) / 300) * 300, '2000-01-01') -- 5min
                    ELSE
                        DATEADD(SECOND, (DATEDIFF(SECOND, '2000-01-01', h.snapshot_utc) / 60) * 60, '2000-01-01')   -- 1min
                END
                ORDER BY h.snapshot_utc
            ) AS rn
        FROM dbo.adl_flights_history h
        INNER JOIN #flight_map m ON m.flight_key = h.flight_key
        WHERE h.history_id > @min_history_id
          AND h.history_id <= @min_history_id + @batch_size * 10  -- Process 10x batch size for sampling
          AND h.lat IS NOT NULL
          AND h.lon IS NOT NULL
    )
    INSERT INTO dbo.adl_flight_trajectory (
        flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts,
        vertical_rate_fpm, heading_deg, track_deg, source,
        tier, tier_reason, flight_phase
    )
    SELECT TOP (@batch_size)
        flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts,
        vertical_rate_fpm, heading_deg, track_deg, source,
        calc_tier AS tier,
        CASE calc_tier
            WHEN 0 THEN 'LEGACY_CRITICAL'
            WHEN 1 THEN 'LEGACY_TRANSITION'
            WHEN 2 THEN 'LEGACY_STANDARD'
            WHEN 3 THEN 'LEGACY_GROUND'
            WHEN 4 THEN 'LEGACY_CRUISE'
            ELSE 'LEGACY_UNKNOWN'
        END AS tier_reason,
        flight_phase
    FROM RankedPositions
    WHERE rn = 1  -- Only first position in each time bucket
    ORDER BY history_id;

    SET @rows_in_batch = @@ROWCOUNT;
    SET @total_inserted = @total_inserted + @rows_in_batch;

    -- Update min_history_id for next batch
    SELECT @min_history_id = ISNULL(MAX(history_id), @max_history_id)
    FROM dbo.adl_flights_history
    WHERE history_id > @min_history_id AND history_id <= @min_history_id + @batch_size * 10;

    IF @batch_num % 10 = 0
    BEGIN
        PRINT 'Batch ' + CAST(@batch_num AS VARCHAR) +
              ': Inserted ' + CAST(@rows_in_batch AS VARCHAR) +
              ' (Total: ' + FORMAT(@total_inserted, 'N0') + ')' +
              ' - Progress: ' + CAST(CAST(@min_history_id * 100.0 / @max_history_id AS INT) AS VARCHAR) + '%';
    END

    -- Stop if we've reached the end
    IF @min_history_id >= @max_history_id
        SET @rows_in_batch = 0;
END

PRINT ''
PRINT 'PHASE 3 Complete. Total trajectory points inserted: ' + FORMAT(@total_inserted, 'N0');

-- ============================================================================
-- PHASE 4: Migrate Flight Plan Data
-- ============================================================================

PRINT ''
PRINT '--- PHASE 4: Migrating flight plan data ---'

-- Get the latest flight plan for each flight
INSERT INTO dbo.adl_flight_plan (
    flight_uid, fp_dept_icao, fp_dest_icao, fp_alt_icao,
    fp_dept_tracon, fp_dept_artcc, fp_dest_tracon, fp_dest_artcc,
    fp_remarks, fp_updated_utc
)
SELECT
    m.flight_uid,
    h.fp_dept_icao,
    h.fp_dest_icao,
    h.fp_alt_icao,
    h.fp_dept_tracon,
    h.fp_dept_artcc,
    h.fp_dest_tracon,
    h.fp_dest_artcc,
    h.fp_remarks,
    h.snapshot_utc AS fp_updated_utc
FROM (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY flight_key ORDER BY snapshot_utc DESC) AS rn
    FROM dbo.adl_flights_history
    WHERE fp_dept_icao IS NOT NULL
) h
INNER JOIN #flight_map m ON m.flight_key = h.flight_key
WHERE h.rn = 1
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_flight_plan p WHERE p.flight_uid = m.flight_uid);

DECLARE @plans_inserted INT = @@ROWCOUNT;
PRINT 'Flight plans inserted: ' + CAST(@plans_inserted AS VARCHAR);

-- ============================================================================
-- PHASE 5: Summary
-- ============================================================================

PRINT ''
PRINT '=== Migration Summary ==='
PRINT 'Flights migrated to adl_flight_core: ' + CAST(@flights_inserted AS VARCHAR)
PRINT 'Trajectory points migrated: ' + FORMAT(@total_inserted, 'N0')
PRINT 'Flight plans migrated: ' + CAST(@plans_inserted AS VARCHAR)
PRINT ''

-- Show tier distribution of migrated data
PRINT '--- Tier Distribution of Migrated Trajectory ---'
SELECT tier, tier_reason, COUNT(*) AS points
FROM dbo.adl_flight_trajectory
WHERE source = 'legacy'
GROUP BY tier, tier_reason
ORDER BY tier;

PRINT ''
PRINT 'Migration completed: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)

-- Cleanup
DROP TABLE #flight_map;
GO
