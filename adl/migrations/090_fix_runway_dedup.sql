-- ============================================================================
-- Migration 090: Fix runway deduplication in batch import
-- Date: 2026-01-14
-- Description: When multiple ATIS records for the same airport are in the batch,
--              runways were being inserted multiple times. Fix by only using
--              the latest ATIS per airport and adding DISTINCT to the insert.
-- ============================================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ImportRunwaysInUseBatch')
    DROP PROCEDURE dbo.sp_ImportRunwaysInUseBatch;
GO

CREATE PROCEDURE dbo.sp_ImportRunwaysInUseBatch
    @json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2 = GETUTCDATE();
    DECLARE @parsed_count INT = 0;
    DECLARE @skipped_count INT = 0;
    DECLARE @runway_count INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Parse the batch JSON into temp table
        SELECT
            CAST(JSON_VALUE(value, '$.atis_id') AS BIGINT) AS atis_id,
            JSON_QUERY(value, '$.runways') AS runways_json
        INTO #batch
        FROM OPENJSON(@json);

        -- Get ATIS details for all records in batch
        -- Use ROW_NUMBER to identify the latest ATIS per airport/type
        SELECT
            b.atis_id,
            b.runways_json,
            a.airport_icao,
            a.atis_code,
            a.atis_type AS source_type,
            a.fetched_utc,
            CASE WHEN b.runways_json IS NULL OR b.runways_json = '[]' THEN 0 ELSE 1 END AS has_runways,
            ROW_NUMBER() OVER (PARTITION BY a.airport_icao, a.atis_type ORDER BY a.fetched_utc DESC) AS rn
        INTO #atis_batch_all
        FROM #batch b
        JOIN dbo.vatsim_atis a ON a.atis_id = b.atis_id;

        -- Only keep the latest ATIS per airport/source_type
        SELECT *
        INTO #atis_batch
        FROM #atis_batch_all
        WHERE rn = 1;

        -- Mark previous runways as superseded for all airports in batch
        UPDATE r
        SET r.superseded_utc = @now
        FROM dbo.runway_in_use r
        JOIN (SELECT DISTINCT airport_icao, source_type FROM #atis_batch WHERE has_runways = 1) ab
            ON r.airport_icao = ab.airport_icao AND r.source_type = ab.source_type
        WHERE r.superseded_utc IS NULL;

        -- Insert new runway assignments - DISTINCT to prevent duplicates
        INSERT INTO dbo.runway_in_use (
            atis_id, airport_icao, runway_id, runway_use,
            approach_type, source_type, effective_utc
        )
        SELECT DISTINCT
            ab.atis_id,
            ab.airport_icao,
            JSON_VALUE(rwy.value, '$.runway_id'),
            JSON_VALUE(rwy.value, '$.runway_use'),
            JSON_VALUE(rwy.value, '$.approach_type'),
            ab.source_type,
            @now
        FROM #atis_batch ab
        CROSS APPLY OPENJSON(ab.runways_json) rwy
        WHERE ab.has_runways = 1;

        SET @runway_count = @@ROWCOUNT;

        -- Update parse status for ALL ATIS in original batch (not just latest)
        UPDATE a
        SET a.parse_status = CASE WHEN ab.has_runways = 1 THEN 'PARSED' ELSE 'SKIPPED' END
        FROM dbo.vatsim_atis a
        JOIN #atis_batch_all ab ON ab.atis_id = a.atis_id;

        SET @parsed_count = (SELECT COUNT(*) FROM #atis_batch WHERE has_runways = 1);
        SET @skipped_count = (SELECT COUNT(*) FROM #atis_batch WHERE has_runways = 0);

        -- Extract runway data to a temp table for config aggregation
        SELECT DISTINCT
            ab.airport_icao,
            JSON_VALUE(rwy.value, '$.runway_id') AS runway_id,
            JSON_VALUE(rwy.value, '$.runway_use') AS runway_use,
            JSON_VALUE(rwy.value, '$.approach_type') AS approach_type
        INTO #runway_data
        FROM #atis_batch ab
        CROSS APPLY OPENJSON(ab.runways_json) rwy
        WHERE ab.has_runways = 1;

        -- Update config history for changed configurations
        INSERT INTO dbo.atis_config_history (airport_icao, arr_runways, dep_runways, approach_types, effective_utc)
        SELECT
            ap.airport_icao,
            (SELECT STRING_AGG(runway_id, '/') WITHIN GROUP (ORDER BY runway_id)
             FROM #runway_data r WHERE r.airport_icao = ap.airport_icao AND r.runway_use IN ('ARR', 'BOTH')) AS arr_runways,
            (SELECT STRING_AGG(runway_id, '/') WITHIN GROUP (ORDER BY runway_id)
             FROM #runway_data r WHERE r.airport_icao = ap.airport_icao AND r.runway_use IN ('DEP', 'BOTH')) AS dep_runways,
            (SELECT STRING_AGG(approach_type, '/') WITHIN GROUP (ORDER BY approach_type)
             FROM #runway_data r WHERE r.airport_icao = ap.airport_icao AND r.approach_type IS NOT NULL) AS approach_types,
            @now
        FROM (SELECT DISTINCT airport_icao FROM #runway_data) ap
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.atis_config_history h
            WHERE h.airport_icao = ap.airport_icao
              AND ISNULL(h.arr_runways, '') = ISNULL(
                  (SELECT STRING_AGG(runway_id, '/') WITHIN GROUP (ORDER BY runway_id)
                   FROM #runway_data r WHERE r.airport_icao = ap.airport_icao AND r.runway_use IN ('ARR', 'BOTH')), '')
              AND ISNULL(h.dep_runways, '') = ISNULL(
                  (SELECT STRING_AGG(runway_id, '/') WITHIN GROUP (ORDER BY runway_id)
                   FROM #runway_data r WHERE r.airport_icao = ap.airport_icao AND r.runway_use IN ('DEP', 'BOTH')), '')
              AND h.superseded_utc IS NULL
        );

        DROP TABLE #runway_data;
        DROP TABLE #batch;
        DROP TABLE #atis_batch;
        DROP TABLE #atis_batch_all;

        COMMIT TRANSACTION;

        -- Return stats
        SELECT @parsed_count AS parsed, @skipped_count AS skipped, @runway_count AS runways;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        DECLARE @error NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @severity INT = ERROR_SEVERITY();
        DECLARE @state INT = ERROR_STATE();

        RAISERROR(@error, @severity, @state);
    END CATCH
END;
GO

-- Clean up existing duplicates
;WITH Duplicates AS (
    SELECT
        ROW_NUMBER() OVER (
            PARTITION BY airport_icao, runway_id, runway_use, source_type
            ORDER BY effective_utc DESC
        ) AS rn,
        *
    FROM dbo.runway_in_use
    WHERE superseded_utc IS NULL
)
UPDATE Duplicates
SET superseded_utc = GETUTCDATE()
WHERE rn > 1;

PRINT 'Fixed sp_ImportRunwaysInUseBatch - Added deduplication';
PRINT 'Also cleaned up existing duplicate runway records';
GO
