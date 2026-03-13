-- ============================================================================
-- Migration 022: Add ARTCC columns to coded_departure_routes
--
-- Adds dep_artcc and arr_artcc columns to enable ARTCC-based filtering
-- in the SWIM CDR endpoint. Applied to both VATSIM_REF and VATSIM_ADL.
--
-- Run on: VATSIM_REF (authoritative) and VATSIM_ADL (synced copy)
-- Date: 2026-03-13
-- ============================================================================

SET NOCOUNT ON;

-- ============================================================================
-- 1. Add columns if they don't exist
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.coded_departure_routes')
    AND name = 'dep_artcc'
)
BEGIN
    ALTER TABLE dbo.coded_departure_routes ADD dep_artcc NVARCHAR(4) NULL;
    PRINT 'Added column dep_artcc';
END
ELSE
    PRINT 'Column dep_artcc already exists';

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.coded_departure_routes')
    AND name = 'arr_artcc'
)
BEGIN
    ALTER TABLE dbo.coded_departure_routes ADD arr_artcc NVARCHAR(4) NULL;
    PRINT 'Added column arr_artcc';
END
ELSE
    PRINT 'Column arr_artcc already exists';

-- ============================================================================
-- 2. Create indexes for ARTCC filtering
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.coded_departure_routes')
    AND name = 'IX_cdr_dep_artcc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_cdr_dep_artcc
    ON dbo.coded_departure_routes(dep_artcc)
    WHERE dep_artcc IS NOT NULL;
    PRINT 'Created index IX_cdr_dep_artcc';
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.coded_departure_routes')
    AND name = 'IX_cdr_arr_artcc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_cdr_arr_artcc
    ON dbo.coded_departure_routes(arr_artcc)
    WHERE arr_artcc IS NOT NULL;
    PRINT 'Created index IX_cdr_arr_artcc';
END

-- Origin+Dest covering index for pair lookups
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.coded_departure_routes')
    AND name = 'IX_cdr_origin_dest'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_cdr_origin_dest
    ON dbo.coded_departure_routes(origin_icao, dest_icao);
    PRINT 'Created index IX_cdr_origin_dest';
END

-- ============================================================================
-- 3. Populate ARTCC columns from apts table
--    Note: apts table is in VATSIM_ADL. If running on VATSIM_ADL, use direct
--    join. If on VATSIM_REF, cross-database joins may not work on Azure SQL
--    Basic tier -- use refdata_sync_daemon.php temp table approach instead.
-- ============================================================================

-- Try direct join first (works if apts is in same database)
IF EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID('dbo.apts') AND type = 'U')
BEGIN
    PRINT 'Populating dep_artcc from local apts table...';
    UPDATE c
    SET c.dep_artcc = a.RESP_ARTCC_ID
    FROM dbo.coded_departure_routes c
    INNER JOIN dbo.apts a ON c.origin_icao = a.ICAO_ID
    WHERE c.dep_artcc IS NULL AND a.RESP_ARTCC_ID IS NOT NULL;

    PRINT 'Populating arr_artcc from local apts table...';
    UPDATE c
    SET c.arr_artcc = a.RESP_ARTCC_ID
    FROM dbo.coded_departure_routes c
    INNER JOIN dbo.apts a ON c.dest_icao = a.ICAO_ID
    WHERE c.arr_artcc IS NULL AND a.RESP_ARTCC_ID IS NOT NULL;

    DECLARE @dep_count INT, @arr_count INT;
    SELECT @dep_count = COUNT(*) FROM dbo.coded_departure_routes WHERE dep_artcc IS NOT NULL;
    SELECT @arr_count = COUNT(*) FROM dbo.coded_departure_routes WHERE arr_artcc IS NOT NULL;
    PRINT 'CDRs with dep_artcc: ' + CAST(@dep_count AS VARCHAR);
    PRINT 'CDRs with arr_artcc: ' + CAST(@arr_count AS VARCHAR);
END
ELSE
BEGIN
    -- apts not in this database; try cross-database join (same server)
    PRINT 'apts table not in this database. Trying cross-database join with VATSIM_ADL...';

    BEGIN TRY
        UPDATE c
        SET c.dep_artcc = a.RESP_ARTCC_ID
        FROM dbo.coded_departure_routes c
        INNER JOIN VATSIM_ADL.dbo.apts a ON c.origin_icao = a.ICAO_ID
        WHERE c.dep_artcc IS NULL AND a.RESP_ARTCC_ID IS NOT NULL;

        UPDATE c
        SET c.arr_artcc = a.RESP_ARTCC_ID
        FROM dbo.coded_departure_routes c
        INNER JOIN VATSIM_ADL.dbo.apts a ON c.dest_icao = a.ICAO_ID
        WHERE c.arr_artcc IS NULL AND a.RESP_ARTCC_ID IS NOT NULL;

        DECLARE @dep_count2 INT, @arr_count2 INT;
        SELECT @dep_count2 = COUNT(*) FROM dbo.coded_departure_routes WHERE dep_artcc IS NOT NULL;
        SELECT @arr_count2 = COUNT(*) FROM dbo.coded_departure_routes WHERE arr_artcc IS NOT NULL;
        PRINT 'CDRs with dep_artcc: ' + CAST(@dep_count2 AS VARCHAR);
        PRINT 'CDRs with arr_artcc: ' + CAST(@arr_count2 AS VARCHAR);
    END TRY
    BEGIN CATCH
        PRINT 'Cross-database join failed: ' + ERROR_MESSAGE();
        PRINT 'ARTCC columns will be populated by refdata_sync_daemon after next import.';
    END CATCH
END

PRINT 'Migration 022 complete.';
