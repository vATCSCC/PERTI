-- =====================================================
-- ATIS and Runway-In-Use Schema
-- Migration: 085_atis_runway_schema.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Store VATSIM ATIS broadcasts and parsed
--          runway assignments for runway-in-use tracking
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. ATIS LOG TABLE
-- Stores raw ATIS broadcasts from controllers
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vatsim_atis')
BEGIN
    CREATE TABLE dbo.vatsim_atis (
        atis_id         BIGINT IDENTITY(1,1) PRIMARY KEY,
        airport_icao    VARCHAR(4) NOT NULL,            -- ICAO code (KJFK, KLAX)
        callsign        VARCHAR(16) NOT NULL,           -- Controller callsign (JFK_ATIS, JFK_D_ATIS)
        atis_type       VARCHAR(4) NOT NULL,            -- 'COMB', 'ARR', 'DEP' (combined, arrival, departure)
        atis_code       CHAR(1) NULL,                   -- Information letter (A-Z)
        frequency       VARCHAR(8) NULL,                -- Frequency (121.900)
        atis_text       NVARCHAR(MAX) NULL,             -- Full ATIS text
        controller_cid  INT NULL,                       -- VATSIM CID

        -- Timestamps
        fetched_utc     DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        logon_utc       DATETIME2 NULL,                 -- When controller logged on

        -- Parsing status
        parse_status    VARCHAR(16) DEFAULT 'PENDING',  -- PENDING, PARSED, FAILED
        parse_error     VARCHAR(256) NULL,

        INDEX IX_atis_airport (airport_icao, fetched_utc DESC),
        INDEX IX_atis_callsign (callsign),
        INDEX IX_atis_fetched (fetched_utc DESC),
        INDEX IX_atis_parse_status (parse_status) WHERE parse_status = 'PENDING'
    );
    PRINT 'Created dbo.vatsim_atis table';
END
ELSE
    PRINT 'Table dbo.vatsim_atis already exists';
GO

-- =====================================================
-- 2. RUNWAY IN USE TABLE
-- Parsed runway assignments from ATIS
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'runway_in_use')
BEGIN
    CREATE TABLE dbo.runway_in_use (
        id              BIGINT IDENTITY(1,1) PRIMARY KEY,
        atis_id         BIGINT NOT NULL,                -- Source ATIS record
        airport_icao    VARCHAR(4) NOT NULL,
        runway_id       VARCHAR(4) NOT NULL,            -- 27L, 09R, 04, etc.
        runway_use      VARCHAR(4) NOT NULL,            -- 'ARR', 'DEP', 'BOTH'
        approach_type   VARCHAR(16) NULL,               -- ILS, RNAV, VISUAL, VOR, etc.
        source_type     VARCHAR(4) NOT NULL,            -- 'COMB', 'ARR', 'DEP' (which ATIS)

        effective_utc   DATETIME2 NOT NULL,             -- When this became active
        superseded_utc  DATETIME2 NULL,                 -- When replaced (NULL = current)

        CONSTRAINT FK_runway_in_use_atis FOREIGN KEY (atis_id)
            REFERENCES dbo.vatsim_atis(atis_id) ON DELETE CASCADE,
        CONSTRAINT CK_riu_runway_use CHECK (runway_use IN ('ARR', 'DEP', 'BOTH')),

        INDEX IX_riu_airport_current (airport_icao, superseded_utc) WHERE superseded_utc IS NULL,
        INDEX IX_riu_airport_time (airport_icao, effective_utc DESC),
        INDEX IX_riu_atis (atis_id)
    );
    PRINT 'Created dbo.runway_in_use table';
END
ELSE
    PRINT 'Table dbo.runway_in_use already exists';
GO

-- =====================================================
-- 3. ATIS HISTORY TABLE (Summarized)
-- Tracks configuration changes over time
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'atis_config_history')
BEGIN
    CREATE TABLE dbo.atis_config_history (
        id              BIGINT IDENTITY(1,1) PRIMARY KEY,
        airport_icao    VARCHAR(4) NOT NULL,

        -- Runway configuration summary
        arr_runways     VARCHAR(32) NULL,               -- Slash-separated: "27L/27R"
        dep_runways     VARCHAR(32) NULL,               -- Slash-separated: "28L/28R"
        approach_types  VARCHAR(64) NULL,               -- Comma-separated: "ILS 27L, VISUAL 27R"

        -- Timestamps
        effective_utc   DATETIME2 NOT NULL,
        superseded_utc  DATETIME2 NULL,
        duration_mins   AS DATEDIFF(MINUTE, effective_utc, ISNULL(superseded_utc, GETUTCDATE())),

        -- Source tracking
        atis_code       CHAR(1) NULL,
        source_atis_id  BIGINT NULL,

        INDEX IX_ach_airport_current (airport_icao, superseded_utc) WHERE superseded_utc IS NULL,
        INDEX IX_ach_airport_time (airport_icao, effective_utc DESC)
    );
    PRINT 'Created dbo.atis_config_history table';
END
ELSE
    PRINT 'Table dbo.atis_config_history already exists';
GO

-- =====================================================
-- 4. VIEW: Current Runways In Use
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_runways_in_use')
    DROP VIEW dbo.vw_current_runways_in_use;
GO

CREATE VIEW dbo.vw_current_runways_in_use AS
SELECT
    r.airport_icao,
    r.runway_id,
    r.runway_use,
    r.approach_type,
    r.source_type,
    r.effective_utc,
    a.atis_code,
    a.callsign,
    a.controller_cid,
    DATEDIFF(MINUTE, r.effective_utc, GETUTCDATE()) AS active_mins
FROM dbo.runway_in_use r
JOIN dbo.vatsim_atis a ON r.atis_id = a.atis_id
WHERE r.superseded_utc IS NULL;
GO

PRINT 'Created vw_current_runways_in_use view';
GO

-- =====================================================
-- 5. VIEW: Current Config Summary by Airport
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_current_airport_config')
    DROP VIEW dbo.vw_current_airport_config;
GO

CREATE VIEW dbo.vw_current_airport_config AS
SELECT
    airport_icao,
    STRING_AGG(CASE WHEN runway_use IN ('ARR', 'BOTH') THEN runway_id END, '/')
        WITHIN GROUP (ORDER BY runway_id) AS arr_runways,
    STRING_AGG(CASE WHEN runway_use IN ('DEP', 'BOTH') THEN runway_id END, '/')
        WITHIN GROUP (ORDER BY runway_id) AS dep_runways,
    STRING_AGG(CASE WHEN approach_type IS NOT NULL
                    THEN approach_type + ' ' + runway_id END, ', ') AS approach_info,
    MIN(effective_utc) AS config_since,
    MAX(atis_code) AS atis_code
FROM dbo.vw_current_runways_in_use
GROUP BY airport_icao;
GO

PRINT 'Created vw_current_airport_config view';
GO

-- =====================================================
-- 6. STORED PROCEDURE: Import ATIS Data
-- Called from Python daemon with JSON payload
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ImportVatsimAtis')
    DROP PROCEDURE dbo.sp_ImportVatsimAtis;
GO

CREATE PROCEDURE dbo.sp_ImportVatsimAtis
    @json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @inserted_count INT = 0;
    DECLARE @updated_count INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Parse JSON into temp table
        SELECT
            JSON_VALUE(value, '$.airport_icao') AS airport_icao,
            JSON_VALUE(value, '$.callsign') AS callsign,
            JSON_VALUE(value, '$.atis_type') AS atis_type,
            JSON_VALUE(value, '$.atis_code') AS atis_code,
            JSON_VALUE(value, '$.frequency') AS frequency,
            JSON_VALUE(value, '$.atis_text') AS atis_text,
            CAST(JSON_VALUE(value, '$.controller_cid') AS INT) AS controller_cid,
            TRY_CAST(JSON_VALUE(value, '$.logon_time') AS DATETIME2) AS logon_utc
        INTO #atis_import
        FROM OPENJSON(@json);

        -- Insert new ATIS records (only if text changed or new code)
        INSERT INTO dbo.vatsim_atis (
            airport_icao, callsign, atis_type, atis_code, frequency,
            atis_text, controller_cid, logon_utc, parse_status
        )
        SELECT
            i.airport_icao, i.callsign, i.atis_type, i.atis_code, i.frequency,
            i.atis_text, i.controller_cid, i.logon_utc, 'PENDING'
        FROM #atis_import i
        WHERE NOT EXISTS (
            -- Check if identical ATIS already exists in last 5 minutes
            SELECT 1 FROM dbo.vatsim_atis a
            WHERE a.airport_icao = i.airport_icao
              AND a.callsign = i.callsign
              AND a.atis_text = i.atis_text
              AND a.fetched_utc > DATEADD(MINUTE, -5, GETUTCDATE())
        );

        SET @inserted_count = @@ROWCOUNT;

        DROP TABLE #atis_import;

        COMMIT TRANSACTION;

        -- Return stats
        SELECT @inserted_count AS inserted_count, @updated_count AS updated_count;

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

PRINT 'Created sp_ImportVatsimAtis procedure';
GO

-- =====================================================
-- 7. STORED PROCEDURE: Import Parsed Runways
-- Called after ATIS parsing with runway assignments
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ImportRunwaysInUse')
    DROP PROCEDURE dbo.sp_ImportRunwaysInUse;
GO

CREATE PROCEDURE dbo.sp_ImportRunwaysInUse
    @atis_id BIGINT,
    @runways_json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @airport_icao VARCHAR(4);
    DECLARE @atis_code CHAR(1);
    DECLARE @source_type VARCHAR(4);
    DECLARE @now DATETIME2 = GETUTCDATE();

    -- Get ATIS details
    SELECT @airport_icao = airport_icao,
           @atis_code = atis_code,
           @source_type = atis_type
    FROM dbo.vatsim_atis
    WHERE atis_id = @atis_id;

    IF @airport_icao IS NULL
    BEGIN
        RAISERROR('ATIS record not found: %I64d', 16, 1, @atis_id);
        RETURN;
    END

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Mark previous runways as superseded for this airport/source type
        UPDATE dbo.runway_in_use
        SET superseded_utc = @now
        WHERE airport_icao = @airport_icao
          AND source_type = @source_type
          AND superseded_utc IS NULL;

        -- Insert new runway assignments
        INSERT INTO dbo.runway_in_use (
            atis_id, airport_icao, runway_id, runway_use,
            approach_type, source_type, effective_utc
        )
        SELECT
            @atis_id,
            @airport_icao,
            JSON_VALUE(value, '$.runway_id'),
            JSON_VALUE(value, '$.runway_use'),
            JSON_VALUE(value, '$.approach_type'),
            @source_type,
            @now
        FROM OPENJSON(@runways_json);

        -- Update ATIS parse status
        UPDATE dbo.vatsim_atis
        SET parse_status = 'PARSED'
        WHERE atis_id = @atis_id;

        -- Update config history if configuration changed
        DECLARE @arr_runways VARCHAR(32);
        DECLARE @dep_runways VARCHAR(32);
        DECLARE @approach_types VARCHAR(64);

        SELECT
            @arr_runways = STRING_AGG(CASE WHEN runway_use IN ('ARR', 'BOTH') THEN runway_id END, '/'),
            @dep_runways = STRING_AGG(CASE WHEN runway_use IN ('DEP', 'BOTH') THEN runway_id END, '/'),
            @approach_types = STRING_AGG(CASE WHEN approach_type IS NOT NULL
                                              THEN approach_type + ' ' + runway_id END, ', ')
        FROM dbo.runway_in_use
        WHERE atis_id = @atis_id;

        -- Check if this is a new configuration
        IF NOT EXISTS (
            SELECT 1 FROM dbo.atis_config_history
            WHERE airport_icao = @airport_icao
              AND superseded_utc IS NULL
              AND ISNULL(arr_runways, '') = ISNULL(@arr_runways, '')
              AND ISNULL(dep_runways, '') = ISNULL(@dep_runways, '')
        )
        BEGIN
            -- Close previous config
            UPDATE dbo.atis_config_history
            SET superseded_utc = @now
            WHERE airport_icao = @airport_icao
              AND superseded_utc IS NULL;

            -- Insert new config
            INSERT INTO dbo.atis_config_history (
                airport_icao, arr_runways, dep_runways, approach_types,
                effective_utc, atis_code, source_atis_id
            )
            VALUES (
                @airport_icao, @arr_runways, @dep_runways, @approach_types,
                @now, @atis_code, @atis_id
            );
        END

        COMMIT TRANSACTION;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        -- Mark ATIS as failed
        UPDATE dbo.vatsim_atis
        SET parse_status = 'FAILED',
            parse_error = LEFT(ERROR_MESSAGE(), 256)
        WHERE atis_id = @atis_id;

        DECLARE @error NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR(@error, 16, 1);
    END CATCH
END;
GO

PRINT 'Created sp_ImportRunwaysInUse procedure';
GO

-- =====================================================
-- 8. STORED PROCEDURE: Get Pending ATIS for Parsing
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GetPendingAtis')
    DROP PROCEDURE dbo.sp_GetPendingAtis;
GO

CREATE PROCEDURE dbo.sp_GetPendingAtis
    @limit INT = 100
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP (@limit)
        atis_id,
        airport_icao,
        callsign,
        atis_type,
        atis_code,
        atis_text
    FROM dbo.vatsim_atis
    WHERE parse_status = 'PENDING'
      AND atis_text IS NOT NULL
      AND LEN(atis_text) > 10
    ORDER BY fetched_utc ASC;
END;
GO

PRINT 'Created sp_GetPendingAtis procedure';
GO

-- =====================================================
-- 9. CLEANUP PROCEDURE: Remove Old ATIS Records
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_CleanupOldAtis')
    DROP PROCEDURE dbo.sp_CleanupOldAtis;
GO

CREATE PROCEDURE dbo.sp_CleanupOldAtis
    @retention_days INT = 7
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff DATETIME2 = DATEADD(DAY, -@retention_days, GETUTCDATE());
    DECLARE @deleted_atis INT;
    DECLARE @deleted_history INT;

    -- Delete old ATIS records (cascades to runway_in_use)
    DELETE FROM dbo.vatsim_atis
    WHERE fetched_utc < @cutoff;

    SET @deleted_atis = @@ROWCOUNT;

    -- Keep config history longer (30 days)
    DELETE FROM dbo.atis_config_history
    WHERE superseded_utc < DATEADD(DAY, -30, GETUTCDATE());

    SET @deleted_history = @@ROWCOUNT;

    SELECT @deleted_atis AS deleted_atis,
           @deleted_history AS deleted_history;
END;
GO

PRINT 'Created sp_CleanupOldAtis procedure';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '085_atis_runway_schema.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - vatsim_atis: Raw ATIS broadcasts from controllers';
PRINT '  - runway_in_use: Parsed runway assignments';
PRINT '  - atis_config_history: Configuration change history';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_current_runways_in_use: Active runway assignments';
PRINT '  - vw_current_airport_config: Summary by airport';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_ImportVatsimAtis: Import raw ATIS data';
PRINT '  - sp_ImportRunwaysInUse: Import parsed runways';
PRINT '  - sp_GetPendingAtis: Get ATIS needing parsing';
PRINT '  - sp_CleanupOldAtis: Remove old records';
GO
