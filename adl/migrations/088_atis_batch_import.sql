-- ============================================================================
-- Migration 088: Batch ATIS Runway Import
-- Date: 2026-01-10
-- Description: Add batch import procedure for ATIS runways to eliminate
--              per-ATIS DB round-trips during parsing
-- ============================================================================

-- =====================================================
-- 1. BATCH IMPORT PROCEDURE
-- Processes multiple ATIS records in a single call
-- Expected JSON format:
-- [
--   {"atis_id": 123, "runways": [{"runway_id": "27L", "runway_use": "ARR", "approach_type": "ILS"}]},
--   {"atis_id": 124, "runways": []},  -- No runways found, will mark as SKIPPED
--   ...
-- ]
-- =====================================================

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
        SELECT
            b.atis_id,
            b.runways_json,
            a.airport_icao,
            a.atis_code,
            a.atis_type AS source_type,
            CASE WHEN b.runways_json IS NULL OR b.runways_json = '[]' THEN 0 ELSE 1 END AS has_runways
        INTO #atis_batch
        FROM #batch b
        JOIN dbo.vatsim_atis a ON a.atis_id = b.atis_id;

        -- Mark previous runways as superseded for all airports in batch
        UPDATE r
        SET r.superseded_utc = @now
        FROM dbo.runway_in_use r
        JOIN (SELECT DISTINCT airport_icao, source_type FROM #atis_batch WHERE has_runways = 1) ab
            ON r.airport_icao = ab.airport_icao AND r.source_type = ab.source_type
        WHERE r.superseded_utc IS NULL;

        -- Insert new runway assignments for all ATIS with runways
        INSERT INTO dbo.runway_in_use (
            atis_id, airport_icao, runway_id, runway_use,
            approach_type, source_type, effective_utc
        )
        SELECT
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

        -- Update parse status: PARSED for those with runways, SKIPPED for those without
        UPDATE a
        SET a.parse_status = CASE WHEN ab.has_runways = 1 THEN 'PARSED' ELSE 'SKIPPED' END
        FROM dbo.vatsim_atis a
        JOIN #atis_batch ab ON ab.atis_id = a.atis_id;

        SET @parsed_count = (SELECT COUNT(*) FROM #atis_batch WHERE has_runways = 1);
        SET @skipped_count = (SELECT COUNT(*) FROM #atis_batch WHERE has_runways = 0);

        -- Update config history for changed configurations
        -- Group by airport to get combined runway config
        ;WITH RunwayData AS (
            SELECT DISTINCT
                ab.airport_icao,
                JSON_VALUE(rwy.value, '$.runway_id') AS runway_id,
                JSON_VALUE(rwy.value, '$.runway_use') AS runway_use,
                JSON_VALUE(rwy.value, '$.approach_type') AS approach_type
            FROM #atis_batch ab
            CROSS APPLY OPENJSON(ab.runways_json) rwy
            WHERE ab.has_runways = 1
        ),
        NewConfigs AS (
            SELECT
                airport_icao,
                STRING_AGG(CASE WHEN runway_use IN ('ARR', 'BOTH') THEN runway_id END, '/')
                    WITHIN GROUP (ORDER BY runway_id) AS arr_runways,
                STRING_AGG(CASE WHEN runway_use IN ('DEP', 'BOTH') THEN runway_id END, '/')
                    WITHIN GROUP (ORDER BY runway_id) AS dep_runways,
                STRING_AGG(approach_type, '/')
                    WITHIN GROUP (ORDER BY approach_type) AS approach_types
            FROM RunwayData
            GROUP BY airport_icao
        )
        INSERT INTO dbo.atis_config_history (airport_icao, arr_runways, dep_runways, approach_types, effective_utc)
        SELECT
            nc.airport_icao,
            nc.arr_runways,
            nc.dep_runways,
            nc.approach_types,
            @now
        FROM NewConfigs nc
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.atis_config_history h
            WHERE h.airport_icao = nc.airport_icao
              AND ISNULL(h.arr_runways, '') = ISNULL(nc.arr_runways, '')
              AND ISNULL(h.dep_runways, '') = ISNULL(nc.dep_runways, '')
              AND h.superseded_utc IS NULL
        );

        DROP TABLE #batch;
        DROP TABLE #atis_batch;

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

PRINT 'Created sp_ImportRunwaysInUseBatch - Batch ATIS runway import';
PRINT 'Eliminates per-ATIS DB round-trips during parsing';
PRINT 'Expected input: JSON array of {atis_id, runways: [...]}';
GO
