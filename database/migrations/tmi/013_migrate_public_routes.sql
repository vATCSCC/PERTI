-- ============================================================================
-- Migration: Public Routes from VATSIM_ADL to VATSIM_TMI
--
-- Migrates data from VATSIM_ADL.dbo.public_routes to VATSIM_TMI.dbo.tmi_public_routes
-- Run this script ONCE after confirming tmi_public_routes table exists
--
-- Prerequisites:
--   - VATSIM_TMI.dbo.tmi_public_routes table created (via 001_tmi_core_schema_azure_sql.sql)
--   - VATSIM_ADL.dbo.public_routes contains existing data
--
-- @version 1.0.0
-- @date 2026-01-21
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- Step 1: Check if source table exists and has data
-- ============================================================================
PRINT 'Checking source table VATSIM_ADL.dbo.public_routes...';

DECLARE @source_count INT;
SELECT @source_count = COUNT(*) FROM VATSIM_ADL.dbo.public_routes;
PRINT 'Source table has ' + CAST(@source_count AS NVARCHAR(10)) + ' rows';

IF @source_count = 0
BEGIN
    PRINT 'No data to migrate. Exiting.';
    -- No RETURN in T-SQL outside procedure, just skip the rest
END
ELSE
BEGIN
    -- ============================================================================
    -- Step 2: Check target table
    -- ============================================================================
    PRINT 'Checking target table VATSIM_TMI.dbo.tmi_public_routes...';

    DECLARE @target_count INT;
    SELECT @target_count = COUNT(*) FROM dbo.tmi_public_routes;
    PRINT 'Target table has ' + CAST(@target_count AS NVARCHAR(10)) + ' existing rows';

    -- ============================================================================
    -- Step 3: Migrate data (INSERT only new records)
    -- ============================================================================
    PRINT 'Starting migration...';

    -- Use a transaction for safety
    BEGIN TRANSACTION;

    BEGIN TRY
        -- Insert records that don't already exist (based on name + valid_start_utc)
        INSERT INTO dbo.tmi_public_routes (
            status,
            name,
            adv_number,
            route_string,
            advisory_text,
            color,
            line_weight,
            line_style,
            valid_start_utc,
            valid_end_utc,
            constrained_area,
            reason,
            origin_filter,
            dest_filter,
            facilities,
            route_geojson,
            created_by,
            created_at,
            updated_at
        )
        SELECT
            COALESCE(src.status, 1) AS status,
            src.name,
            src.adv_number,
            src.route_string,
            src.advisory_text,
            COALESCE(src.color, '#e74c3c') AS color,
            COALESCE(src.line_weight, 3) AS line_weight,
            COALESCE(src.line_style, 'solid') AS line_style,
            src.valid_start_utc,
            src.valid_end_utc,
            src.constrained_area,
            src.reason,
            src.origin_filter,
            src.dest_filter,
            src.facilities,
            src.route_geojson,
            src.created_by,
            COALESCE(src.created_utc, SYSUTCDATETIME()) AS created_at,
            COALESCE(src.updated_utc, SYSUTCDATETIME()) AS updated_at
        FROM VATSIM_ADL.dbo.public_routes src
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.tmi_public_routes tgt
            WHERE tgt.name = src.name
              AND tgt.valid_start_utc = src.valid_start_utc
        );

        DECLARE @migrated INT = @@ROWCOUNT;
        PRINT 'Migrated ' + CAST(@migrated AS NVARCHAR(10)) + ' new routes';

        -- Verify migration
        DECLARE @final_count INT;
        SELECT @final_count = COUNT(*) FROM dbo.tmi_public_routes;
        PRINT 'Target table now has ' + CAST(@final_count AS NVARCHAR(10)) + ' total rows';

        COMMIT TRANSACTION;
        PRINT 'Migration completed successfully!';

    END TRY
    BEGIN CATCH
        ROLLBACK TRANSACTION;
        PRINT 'ERROR: Migration failed!';
        PRINT 'Error Number: ' + CAST(ERROR_NUMBER() AS NVARCHAR(10));
        PRINT 'Error Message: ' + ERROR_MESSAGE();
        THROW;
    END CATCH;
END;
GO

-- ============================================================================
-- Step 4: Verification queries (run manually to verify)
-- ============================================================================
/*
-- Compare counts
SELECT 'VATSIM_ADL.public_routes' AS source, COUNT(*) AS count FROM VATSIM_ADL.dbo.public_routes
UNION ALL
SELECT 'VATSIM_TMI.tmi_public_routes' AS source, COUNT(*) AS count FROM VATSIM_TMI.dbo.tmi_public_routes;

-- Check active routes
SELECT TOP 10
    route_id, name, status, valid_start_utc, valid_end_utc, created_at
FROM VATSIM_TMI.dbo.tmi_public_routes
WHERE status = 1
ORDER BY created_at DESC;

-- Check for any data issues
SELECT
    CASE WHEN route_string IS NULL THEN 'NULL route_string' ELSE 'OK' END AS check_result,
    COUNT(*) AS count
FROM VATSIM_TMI.dbo.tmi_public_routes
GROUP BY CASE WHEN route_string IS NULL THEN 'NULL route_string' ELSE 'OK' END;
*/

PRINT 'Migration script complete.';
GO
