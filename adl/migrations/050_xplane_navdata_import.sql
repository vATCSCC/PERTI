-- ============================================================================
-- ADL Migration 050: X-Plane Navigation Data Import
--
-- Imports navigation data from X-Plane/FlightGear into nav_fixes and airways
-- Run after using Import-XPlaneNavData.ps1 to generate CSV files
--
-- Usage:
--   EXEC sp_ImportXPlaneNavData
--     @fixes_csv = 'C:\path\to\xplane_fixes.csv',
--     @navaids_csv = 'C:\path\to\xplane_navaids.csv',
--     @airways_csv = 'C:\path\to\xplane_airways.csv',
--     @segments_csv = 'C:\path\to\xplane_airway_segments.csv'
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 050: X-Plane NavData Import ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Staging tables for bulk import
-- ============================================================================

IF OBJECT_ID('dbo.xplane_fixes_staging', 'U') IS NOT NULL
    DROP TABLE dbo.xplane_fixes_staging;
GO

CREATE TABLE dbo.xplane_fixes_staging (
    fix_name        NVARCHAR(16),
    fix_type        NVARCHAR(16),
    lat             DECIMAL(10,7),
    lon             DECIMAL(11,7),
    country_code    NVARCHAR(4),
    source          NVARCHAR(32)
);
GO

IF OBJECT_ID('dbo.xplane_navaids_staging', 'U') IS NOT NULL
    DROP TABLE dbo.xplane_navaids_staging;
GO

CREATE TABLE dbo.xplane_navaids_staging (
    fix_name        NVARCHAR(16),
    fix_type        NVARCHAR(16),
    lat             DECIMAL(10,7),
    lon             DECIMAL(11,7),
    elevation_ft    INT,
    freq_mhz        DECIMAL(7,3),
    mag_var         DECIMAL(5,2),
    source          NVARCHAR(32)
);
GO

IF OBJECT_ID('dbo.xplane_airways_staging', 'U') IS NOT NULL
    DROP TABLE dbo.xplane_airways_staging;
GO

CREATE TABLE dbo.xplane_airways_staging (
    airway_name     NVARCHAR(16),
    airway_type     NVARCHAR(16),
    fix_sequence    NVARCHAR(MAX),
    fix_count       INT,
    start_fix       NVARCHAR(16),
    end_fix         NVARCHAR(16),
    min_alt_ft      INT,
    max_alt_ft      INT,
    source          NVARCHAR(32)
);
GO

IF OBJECT_ID('dbo.xplane_segments_staging', 'U') IS NOT NULL
    DROP TABLE dbo.xplane_segments_staging;
GO

CREATE TABLE dbo.xplane_segments_staging (
    airway_name     NVARCHAR(16),
    from_fix        NVARCHAR(16),
    to_fix          NVARCHAR(16),
    min_alt_ft      INT,
    max_alt_ft      INT
);
GO

PRINT 'Created staging tables';
GO

-- ============================================================================
-- Main import procedure
-- ============================================================================

IF OBJECT_ID('dbo.sp_ImportXPlaneNavData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ImportXPlaneNavData;
GO

CREATE PROCEDURE dbo.sp_ImportXPlaneNavData
    @fixes_csv      NVARCHAR(500) = NULL,
    @navaids_csv    NVARCHAR(500) = NULL,
    @airways_csv    NVARCHAR(500) = NULL,
    @segments_csv   NVARCHAR(500) = NULL,
    @clear_existing BIT = 0,
    @debug          BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
    DECLARE @fixes_count INT = 0;
    DECLARE @navaids_count INT = 0;
    DECLARE @airways_count INT = 0;
    DECLARE @segments_count INT = 0;

    PRINT '================================================';
    PRINT 'X-Plane Navigation Data Import';
    PRINT 'Started at: ' + CONVERT(VARCHAR, @start_time, 120);
    PRINT '================================================';

    -- ========================================================================
    -- Clear existing X-Plane data if requested
    -- ========================================================================
    IF @clear_existing = 1
    BEGIN
        PRINT '';
        PRINT 'Clearing existing X-Plane data...';

        DELETE FROM dbo.airway_segments WHERE airway_id IN (
            SELECT airway_id FROM dbo.airways WHERE source = 'XPLANE'
        );
        DELETE FROM dbo.airways WHERE source = 'XPLANE';
        DELETE FROM dbo.nav_fixes WHERE source = 'XPLANE';

        PRINT '  Cleared existing XPLANE records';
    END

    -- ========================================================================
    -- Import Fixes (Waypoints)
    -- ========================================================================
    IF @fixes_csv IS NOT NULL
    BEGIN
        PRINT '';
        PRINT 'Importing fixes from: ' + @fixes_csv;

        TRUNCATE TABLE dbo.xplane_fixes_staging;

        -- Bulk import CSV
        DECLARE @bulk_sql NVARCHAR(MAX) = N'
            BULK INSERT dbo.xplane_fixes_staging
            FROM ''' + @fixes_csv + '''
            WITH (
                FORMAT = ''CSV'',
                FIRSTROW = 2,
                FIELDTERMINATOR = '','',
                ROWTERMINATOR = ''\n'',
                TABLOCK,
                CODEPAGE = ''65001''
            )';

        BEGIN TRY
            EXEC sp_executesql @bulk_sql;

            SELECT @fixes_count = COUNT(*) FROM dbo.xplane_fixes_staging;
            PRINT '  Loaded ' + CAST(@fixes_count AS VARCHAR) + ' fixes to staging';

            -- Merge into nav_fixes (avoid duplicates)
            INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, country_code, source, effective_date)
            SELECT
                s.fix_name,
                s.fix_type,
                s.lat,
                s.lon,
                s.country_code,
                'XPLANE',
                GETUTCDATE()
            FROM dbo.xplane_fixes_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM dbo.nav_fixes n
                WHERE n.fix_name = s.fix_name
                  AND ABS(n.lat - s.lat) < 0.01
                  AND ABS(n.lon - s.lon) < 0.01
            );

            SET @fixes_count = @@ROWCOUNT;
            PRINT '  Inserted ' + CAST(@fixes_count AS VARCHAR) + ' new fixes into nav_fixes';

            -- Update position_geo for new records
            UPDATE dbo.nav_fixes
            SET position_geo = geography::Point(lat, lon, 4326)
            WHERE source = 'XPLANE' AND position_geo IS NULL;

        END TRY
        BEGIN CATCH
            PRINT '  ERROR importing fixes: ' + ERROR_MESSAGE();
        END CATCH
    END

    -- ========================================================================
    -- Import Navaids (VOR, NDB, DME)
    -- ========================================================================
    IF @navaids_csv IS NOT NULL
    BEGIN
        PRINT '';
        PRINT 'Importing navaids from: ' + @navaids_csv;

        TRUNCATE TABLE dbo.xplane_navaids_staging;

        SET @bulk_sql = N'
            BULK INSERT dbo.xplane_navaids_staging
            FROM ''' + @navaids_csv + '''
            WITH (
                FORMAT = ''CSV'',
                FIRSTROW = 2,
                FIELDTERMINATOR = '','',
                ROWTERMINATOR = ''\n'',
                TABLOCK,
                CODEPAGE = ''65001''
            )';

        BEGIN TRY
            EXEC sp_executesql @bulk_sql;

            SELECT @navaids_count = COUNT(*) FROM dbo.xplane_navaids_staging;
            PRINT '  Loaded ' + CAST(@navaids_count AS VARCHAR) + ' navaids to staging';

            -- Merge into nav_fixes
            INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, freq_mhz, mag_var, elevation_ft, source, effective_date)
            SELECT
                s.fix_name,
                s.fix_type,
                s.lat,
                s.lon,
                s.freq_mhz,
                s.mag_var,
                s.elevation_ft,
                'XPLANE',
                GETUTCDATE()
            FROM dbo.xplane_navaids_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM dbo.nav_fixes n
                WHERE n.fix_name = s.fix_name
                  AND n.fix_type = s.fix_type
                  AND ABS(n.lat - s.lat) < 0.01
            );

            SET @navaids_count = @@ROWCOUNT;
            PRINT '  Inserted ' + CAST(@navaids_count AS VARCHAR) + ' new navaids into nav_fixes';

            -- Update position_geo
            UPDATE dbo.nav_fixes
            SET position_geo = geography::Point(lat, lon, 4326)
            WHERE source = 'XPLANE' AND position_geo IS NULL;

        END TRY
        BEGIN CATCH
            PRINT '  ERROR importing navaids: ' + ERROR_MESSAGE();
        END CATCH
    END

    -- ========================================================================
    -- Import Airways
    -- ========================================================================
    IF @airways_csv IS NOT NULL
    BEGIN
        PRINT '';
        PRINT 'Importing airways from: ' + @airways_csv;

        TRUNCATE TABLE dbo.xplane_airways_staging;

        SET @bulk_sql = N'
            BULK INSERT dbo.xplane_airways_staging
            FROM ''' + @airways_csv + '''
            WITH (
                FORMAT = ''CSV'',
                FIRSTROW = 2,
                FIELDTERMINATOR = '','',
                ROWTERMINATOR = ''\n'',
                TABLOCK,
                CODEPAGE = ''65001''
            )';

        BEGIN TRY
            EXEC sp_executesql @bulk_sql;

            SELECT @airways_count = COUNT(*) FROM dbo.xplane_airways_staging;
            PRINT '  Loaded ' + CAST(@airways_count AS VARCHAR) + ' airways to staging';

            -- Merge into airways (replace existing with same name)
            MERGE dbo.airways AS target
            USING dbo.xplane_airways_staging AS source
            ON target.airway_name = source.airway_name
            WHEN MATCHED AND target.source = 'XPLANE' THEN
                UPDATE SET
                    airway_type = source.airway_type,
                    fix_sequence = source.fix_sequence,
                    fix_count = source.fix_count,
                    start_fix = source.start_fix,
                    end_fix = source.end_fix,
                    min_alt_ft = source.min_alt_ft,
                    max_alt_ft = source.max_alt_ft,
                    effective_date = GETUTCDATE()
            WHEN NOT MATCHED THEN
                INSERT (airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix, min_alt_ft, max_alt_ft, source, effective_date)
                VALUES (source.airway_name, source.airway_type, source.fix_sequence, source.fix_count, source.start_fix, source.end_fix, source.min_alt_ft, source.max_alt_ft, 'XPLANE', GETUTCDATE());

            SET @airways_count = @@ROWCOUNT;
            PRINT '  Merged ' + CAST(@airways_count AS VARCHAR) + ' airways';

        END TRY
        BEGIN CATCH
            PRINT '  ERROR importing airways: ' + ERROR_MESSAGE();
        END CATCH
    END

    -- ========================================================================
    -- Import Airway Segments
    -- ========================================================================
    IF @segments_csv IS NOT NULL
    BEGIN
        PRINT '';
        PRINT 'Importing airway segments from: ' + @segments_csv;

        TRUNCATE TABLE dbo.xplane_segments_staging;

        SET @bulk_sql = N'
            BULK INSERT dbo.xplane_segments_staging
            FROM ''' + @segments_csv + '''
            WITH (
                FORMAT = ''CSV'',
                FIRSTROW = 2,
                FIELDTERMINATOR = '','',
                ROWTERMINATOR = ''\n'',
                TABLOCK,
                CODEPAGE = ''65001''
            )';

        BEGIN TRY
            EXEC sp_executesql @bulk_sql;

            SELECT @segments_count = COUNT(*) FROM dbo.xplane_segments_staging;
            PRINT '  Loaded ' + CAST(@segments_count AS VARCHAR) + ' segments to staging';

            -- Delete existing X-Plane segments for airways we're updating
            DELETE FROM dbo.airway_segments
            WHERE airway_id IN (
                SELECT a.airway_id
                FROM dbo.airways a
                INNER JOIN dbo.xplane_segments_staging s ON a.airway_name = s.airway_name
                WHERE a.source = 'XPLANE'
            );

            -- Insert new segments (join nav_fixes to get coordinates)
            INSERT INTO dbo.airway_segments (
                airway_id, airway_name, sequence_num,
                from_fix, to_fix,
                from_lat, from_lon, to_lat, to_lon,
                min_alt_ft, max_alt_ft
            )
            SELECT
                a.airway_id,
                s.airway_name,
                ROW_NUMBER() OVER (PARTITION BY s.airway_name ORDER BY s.from_fix),
                s.from_fix,
                s.to_fix,
                nf1.lat,
                nf1.lon,
                nf2.lat,
                nf2.lon,
                s.min_alt_ft,
                s.max_alt_ft
            FROM dbo.xplane_segments_staging s
            INNER JOIN dbo.airways a ON a.airway_name = s.airway_name
            LEFT JOIN dbo.nav_fixes nf1 ON nf1.fix_name = s.from_fix
            LEFT JOIN dbo.nav_fixes nf2 ON nf2.fix_name = s.to_fix;

            SET @segments_count = @@ROWCOUNT;
            PRINT '  Inserted ' + CAST(@segments_count AS VARCHAR) + ' airway segments';

        END TRY
        BEGIN CATCH
            PRINT '  ERROR importing segments: ' + ERROR_MESSAGE();
        END CATCH
    END

    -- ========================================================================
    -- Summary
    -- ========================================================================
    DECLARE @elapsed_sec INT = DATEDIFF(SECOND, @start_time, SYSUTCDATETIME());

    PRINT '';
    PRINT '================================================';
    PRINT 'Import complete in ' + CAST(@elapsed_sec AS VARCHAR) + ' seconds';
    PRINT '';
    PRINT 'Summary:';
    PRINT '  Fixes:    ' + CAST(@fixes_count AS VARCHAR);
    PRINT '  Navaids:  ' + CAST(@navaids_count AS VARCHAR);
    PRINT '  Airways:  ' + CAST(@airways_count AS VARCHAR);
    PRINT '  Segments: ' + CAST(@segments_count AS VARCHAR);
    PRINT '';

    -- Show totals
    SELECT
        'nav_fixes' AS [Table],
        COUNT(*) AS [Total Records],
        COUNT(CASE WHEN source = 'XPLANE' THEN 1 END) AS [X-Plane Records]
    FROM dbo.nav_fixes
    UNION ALL
    SELECT
        'airways',
        COUNT(*),
        COUNT(CASE WHEN source = 'XPLANE' THEN 1 END)
    FROM dbo.airways;

    PRINT '================================================';
END;
GO

PRINT 'Created procedure dbo.sp_ImportXPlaneNavData';
GO

-- ============================================================================
-- Quick import helper (for manual CSV path entry)
-- ============================================================================

IF OBJECT_ID('dbo.sp_ImportXPlaneFromFolder', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ImportXPlaneFromFolder;
GO

CREATE PROCEDURE dbo.sp_ImportXPlaneFromFolder
    @folder_path    NVARCHAR(500),
    @clear_existing BIT = 0
AS
BEGIN
    DECLARE @fixes_csv NVARCHAR(500) = @folder_path + '\xplane_fixes.csv';
    DECLARE @navaids_csv NVARCHAR(500) = @folder_path + '\xplane_navaids.csv';
    DECLARE @airways_csv NVARCHAR(500) = @folder_path + '\xplane_airways.csv';
    DECLARE @segments_csv NVARCHAR(500) = @folder_path + '\xplane_airway_segments.csv';

    EXEC dbo.sp_ImportXPlaneNavData
        @fixes_csv = @fixes_csv,
        @navaids_csv = @navaids_csv,
        @airways_csv = @airways_csv,
        @segments_csv = @segments_csv,
        @clear_existing = @clear_existing;
END;
GO

PRINT 'Created procedure dbo.sp_ImportXPlaneFromFolder';
GO

PRINT '';
PRINT '=== Migration 050 Complete ===';
PRINT '';
PRINT 'Usage:';
PRINT '  1. Run Import-XPlaneNavData.ps1 to generate CSV files';
PRINT '  2. Execute: EXEC sp_ImportXPlaneFromFolder @folder_path = ''C:\path\to\nav_import''';
PRINT '';
GO
