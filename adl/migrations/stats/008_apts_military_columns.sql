-- =====================================================
-- Add Military Columns to apts Table
-- Migration: 076_apts_military_columns.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Add NASR military ownership/use fields for
--          accurate military airport identification
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. ADD MILITARY COLUMNS TO APTS TABLE
-- =====================================================

-- OWNERSHIP_TYPE_CODE: Airport ownership type
-- MA = Military Army
-- MN = Military Navy
-- MR = Military Air Force
-- MC = Military Coast Guard
-- PU = Public
-- PR = Private
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'OWNERSHIP_TYPE_CODE')
BEGIN
    ALTER TABLE dbo.apts ADD OWNERSHIP_TYPE_CODE VARCHAR(2) NULL;
    PRINT 'Added OWNERSHIP_TYPE_CODE column';
END
GO

-- USE_CODE: Airport use type
-- PU = Public Use
-- PR = Private Use
-- MU = Military Use
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'USE_CODE')
BEGIN
    ALTER TABLE dbo.apts ADD USE_CODE VARCHAR(2) NULL;
    PRINT 'Added USE_CODE column';
END
GO

-- MIL_SVC_CODE: Military service branch
-- A = Army
-- N = Navy
-- F = Air Force
-- C = Coast Guard
-- G = National Guard
-- O = Other
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'MIL_SVC_CODE')
BEGIN
    ALTER TABLE dbo.apts ADD MIL_SVC_CODE VARCHAR(2) NULL;
    PRINT 'Added MIL_SVC_CODE column';
END
GO

-- MIL_LAND_RIGHTS_CODE: Military land rights/jurisdiction
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'MIL_LAND_RIGHTS_CODE')
BEGIN
    ALTER TABLE dbo.apts ADD MIL_LAND_RIGHTS_CODE VARCHAR(2) NULL;
    PRINT 'Added MIL_LAND_RIGHTS_CODE column';
END
GO

-- =====================================================
-- 2. ADD COMPUTED COLUMN FOR MILITARY DETECTION
-- =====================================================

-- IS_MILITARY: Computed column for easy military detection
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'IS_MILITARY')
BEGIN
    ALTER TABLE dbo.apts ADD IS_MILITARY AS (
        CASE WHEN
            -- Military ownership types
            OWNERSHIP_TYPE_CODE IN ('MA', 'MN', 'MR', 'MC')
            -- Military use code
            OR USE_CODE = 'MU'
            -- Has military service code
            OR MIL_SVC_CODE IS NOT NULL
            -- Military TWR type
            OR TWR_TYPE_CODE IN ('RAPCON', 'CERAP', 'RATCF', 'ARAC', 'NON-ATCT-MIL')
            -- Fallback: name pattern matching
            OR ARPT_NAME LIKE '%AFB%'
            OR ARPT_NAME LIKE '%AIR FORCE%'
            OR ARPT_NAME LIKE '%NAS %'
            OR ARPT_NAME LIKE '% NAS'
            OR ARPT_NAME LIKE '%NAVAL AIR%'
            OR ARPT_NAME LIKE '%MCAS%'
            OR ARPT_NAME LIKE '%MARINE CORPS%'
            OR ARPT_NAME LIKE '%AAF%'
            OR ARPT_NAME LIKE '%ARMY%'
            OR ARPT_NAME LIKE '%NATIONAL GUARD%'
            OR ARPT_NAME LIKE '%JRB%'
            OR ARPT_NAME LIKE '%JOINT RESERVE%'
            OR ARPT_NAME LIKE '%JOINT BASE%'
        THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END
    ) PERSISTED;
    PRINT 'Added IS_MILITARY computed column';
END
GO

-- =====================================================
-- 3. ADD INDEX FOR MILITARY QUERIES
-- Note: Can't use filtered index on computed column, so use regular index
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'IX_apts_military')
BEGIN
    CREATE NONCLUSTERED INDEX IX_apts_military ON dbo.apts (IS_MILITARY)
    INCLUDE (ICAO_ID, ARPT_NAME, RESP_ARTCC_ID);
    PRINT 'Added IX_apts_military index';
END
GO

-- =====================================================
-- 4. REFERENCE TABLE FOR MILITARY CODES
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'military_ownership_codes')
BEGIN
    CREATE TABLE dbo.military_ownership_codes (
        code VARCHAR(2) PRIMARY KEY,
        description VARCHAR(64) NOT NULL,
        is_military BIT NOT NULL DEFAULT 0
    );

    INSERT INTO dbo.military_ownership_codes (code, description, is_military) VALUES
        ('MA', 'Military Army', 1),
        ('MN', 'Military Navy', 1),
        ('MR', 'Military Air Force', 1),
        ('MC', 'Military Coast Guard', 1),
        ('PU', 'Public', 0),
        ('PR', 'Private', 0);

    PRINT 'Created military_ownership_codes reference table';
END
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'military_service_codes')
BEGIN
    CREATE TABLE dbo.military_service_codes (
        code VARCHAR(2) PRIMARY KEY,
        description VARCHAR(64) NOT NULL
    );

    INSERT INTO dbo.military_service_codes (code, description) VALUES
        ('A', 'Army'),
        ('N', 'Navy'),
        ('F', 'Air Force'),
        ('C', 'Coast Guard'),
        ('G', 'National Guard'),
        ('O', 'Other');

    PRINT 'Created military_service_codes reference table';
END
GO

-- =====================================================
-- 5. UPDATE SP_REFRESHAIRPORTGROUPINGS TO USE IS_MILITARY
-- This updates the stored procedure created in 075 to use the new column
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RefreshAirportGroupings')
BEGIN
    -- Drop and recreate with IS_MILITARY support
    DROP PROCEDURE dbo.sp_RefreshAirportGroupings;
END
GO

CREATE PROCEDURE dbo.sp_RefreshAirportGroupings
    @grouping_code VARCHAR(32) = NULL   -- NULL = refresh all groupings
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @members_added INT = 0;

    -- Clear existing members for the specified grouping(s)
    IF @grouping_code IS NOT NULL
        DELETE FROM dbo.airport_grouping_member
        WHERE grouping_id IN (SELECT grouping_id FROM dbo.airport_grouping WHERE grouping_code = @grouping_code);
    ELSE
        TRUNCATE TABLE dbo.airport_grouping_member;

    -- Populate each active grouping
    DECLARE @gid INT, @gcode VARCHAR(32), @cat VARCHAR(16);
    DECLARE @artcc VARCHAR(4), @tracon VARCHAR(4);
    DECLARE @req_major BIT, @excl_core30 BIT, @req_commercial BIT;
    DECLARE @tracon_type VARCHAR(16);

    DECLARE grouping_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT grouping_id, grouping_code, category,
               filter_artcc, filter_tracon,
               require_major_tier, exclude_core30, require_commercial,
               filter_tracon_type
        FROM dbo.airport_grouping
        WHERE is_active = 1
          AND (@grouping_code IS NULL OR grouping_code = @grouping_code);

    OPEN grouping_cursor;
    FETCH NEXT FROM grouping_cursor INTO @gid, @gcode, @cat, @artcc, @tracon,
                                         @req_major, @excl_core30, @req_commercial, @tracon_type;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Insert matching airports
        INSERT INTO dbo.airport_grouping_member (grouping_id, airport_icao, matched_by)
        SELECT
            @gid,
            a.ICAO_ID,
            CASE
                WHEN @tracon_type = 'MIL_TRACON' THEN 'MILITARY'
                WHEN @tracon_type IS NOT NULL THEN
                    CASE
                        WHEN EXISTS (SELECT 1 FROM dbo.major_tracon mt
                                     WHERE mt.tracon_id IN (a.Approach_ID, a.Consolidated_Approach_ID,
                                                            a.Secondary_Approach_ID, a.Approach_Departure_ID))
                        THEN 'MAJOR_TRACON'
                        ELSE 'MINOR_TRACON'
                    END
                WHEN a.Core30 = 'TRUE' THEN 'CORE30'
                WHEN a.OEP35 = 'TRUE' THEN 'OEP35'
                WHEN a.ASPM82 = 'TRUE' THEN 'ASPM82'
                ELSE 'COMMERCIAL'
            END
        FROM dbo.apts a
        WHERE
            -- Must have ICAO code
            a.ICAO_ID IS NOT NULL
            AND LEN(a.ICAO_ID) = 4

            -- ARTCC filter (if specified)
            AND (@artcc IS NULL OR a.RESP_ARTCC_ID = @artcc)

            -- TRACON filter (if specified) - check approach facility columns
            AND (@tracon IS NULL OR
                 a.Approach_ID = @tracon OR
                 a.Consolidated_Approach_ID = @tracon OR
                 a.Secondary_Approach_ID = @tracon OR
                 a.Approach_Departure_ID = @tracon)

            -- Commercial service filter (has tower)
            AND (@req_commercial = 0 OR a.TWR_TYPE_CODE IN ('ATCT', 'ATCT-TRACON'))

            -- MINOR category: exclude Core30
            AND (@excl_core30 = 0 OR a.Core30 <> 'TRUE')

            -- TRACON type filter (for ARTCC TRACON groupings)
            AND (@tracon_type IS NULL OR (
                CASE @tracon_type
                    WHEN 'MAJOR_TRACON' THEN
                        -- Airport must be served by a major TRACON
                        CASE WHEN EXISTS (
                            SELECT 1 FROM dbo.major_tracon mt
                            WHERE mt.tracon_id IN (a.Approach_ID, a.Consolidated_Approach_ID,
                                                   a.Secondary_Approach_ID, a.Approach_Departure_ID)
                        ) THEN 1 ELSE 0 END
                    WHEN 'MINOR_TRACON' THEN
                        -- Airport must have a TRACON but NOT a major one
                        CASE WHEN (
                            -- Has some TRACON
                            (a.Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_ID))) BETWEEN 2 AND 4 AND a.Approach_ID NOT LIKE 'Z%')
                            OR (a.Consolidated_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Consolidated_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Secondary_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Secondary_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Approach_Departure_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_Departure_ID))) BETWEEN 2 AND 4)
                        ) AND NOT EXISTS (
                            -- But NOT a major TRACON
                            SELECT 1 FROM dbo.major_tracon mt
                            WHERE mt.tracon_id IN (a.Approach_ID, a.Consolidated_Approach_ID,
                                                   a.Secondary_Approach_ID, a.Approach_Departure_ID)
                        ) THEN 1 ELSE 0 END
                    WHEN 'ALL_TRACON' THEN
                        -- Airport must have some TRACON (any)
                        CASE WHEN (
                            (a.Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_ID))) BETWEEN 2 AND 4 AND a.Approach_ID NOT LIKE 'Z%')
                            OR (a.Consolidated_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Consolidated_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Secondary_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Secondary_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Approach_Departure_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_Departure_ID))) BETWEEN 2 AND 4)
                        ) THEN 1 ELSE 0 END
                    WHEN 'MIL_TRACON' THEN
                        -- Use IS_MILITARY computed column (includes ownership codes + name patterns)
                        CASE WHEN a.IS_MILITARY = 1 THEN 1 ELSE 0 END
                    ELSE 1
                END = 1
            ))

            -- MAJOR category: must match Core30, OEP35, OPSNET45, or ASPM82 (in fallback order)
            AND (@req_major = 0 OR (
                -- First try Core30
                a.Core30 = 'TRUE'
                OR (
                    -- If no Core30 airports match the facility filter, try OEP35
                    NOT EXISTS (
                        SELECT 1 FROM dbo.apts x
                        WHERE x.Core30 = 'TRUE'
                          AND (@artcc IS NULL OR x.RESP_ARTCC_ID = @artcc)
                          AND (@tracon IS NULL OR x.Approach_ID = @tracon OR x.Consolidated_Approach_ID = @tracon OR x.Approach_Departure_ID = @tracon)
                          AND x.TWR_TYPE_CODE IN ('ATCT', 'ATCT-TRACON')
                    )
                    AND a.OEP35 = 'TRUE'
                )
                OR (
                    -- If no Core30 or OEP35 airports match, try OPSNET45/ASPM82
                    NOT EXISTS (
                        SELECT 1 FROM dbo.apts x
                        WHERE (x.Core30 = 'TRUE' OR x.OEP35 = 'TRUE')
                          AND (@artcc IS NULL OR x.RESP_ARTCC_ID = @artcc)
                          AND (@tracon IS NULL OR x.Approach_ID = @tracon OR x.Consolidated_Approach_ID = @tracon OR x.Approach_Departure_ID = @tracon)
                          AND x.TWR_TYPE_CODE IN ('ATCT', 'ATCT-TRACON')
                    )
                    AND a.ASPM82 = 'TRUE'
                )
            ));

        SET @members_added = @members_added + @@ROWCOUNT;

        FETCH NEXT FROM grouping_cursor INTO @gid, @gcode, @cat, @artcc, @tracon,
                                             @req_major, @excl_core30, @req_commercial, @tracon_type;
    END

    CLOSE grouping_cursor;
    DEALLOCATE grouping_cursor;

    -- Update timestamps
    UPDATE dbo.airport_grouping
    SET updated_utc = GETUTCDATE()
    WHERE is_active = 1
      AND (@grouping_code IS NULL OR grouping_code = @grouping_code);

    PRINT 'Refreshed airport groupings: ' + CAST(@members_added AS VARCHAR) + ' members added';
    PRINT 'Execution time: ' + CAST(DATEDIFF(MILLISECOND, @start_time, GETUTCDATE()) AS VARCHAR) + 'ms';
END;
GO

PRINT 'Updated sp_RefreshAirportGroupings to use IS_MILITARY column';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '076_apts_military_columns.sql completed successfully';
PRINT '';
PRINT 'Columns added to apts table:';
PRINT '  - OWNERSHIP_TYPE_CODE: MA, MN, MR, MC (military) or PU, PR (civilian)';
PRINT '  - USE_CODE: PU (public), PR (private), MU (military)';
PRINT '  - MIL_SVC_CODE: A, N, F, C, G, O (military service branch)';
PRINT '  - MIL_LAND_RIGHTS_CODE: Military jurisdiction code';
PRINT '  - IS_MILITARY: Computed column (combines all detection methods)';
PRINT '';
PRINT 'Reference tables created:';
PRINT '  - military_ownership_codes';
PRINT '  - military_service_codes';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Run nasr_navdata_updater.py to import military fields';
PRINT '  2. Re-run sp_RefreshAirportGroupings to populate military groupings';
GO
