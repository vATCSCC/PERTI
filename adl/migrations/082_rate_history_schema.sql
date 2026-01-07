-- =====================================================
-- Rate History Tracking Schema
-- Migration: 082_rate_history_schema.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Track changes to airport config rates over time
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. RATE HISTORY TABLE
-- One row per rate change event
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_config_rate_history')
BEGIN
    CREATE TABLE dbo.airport_config_rate_history (
        history_id      BIGINT IDENTITY(1,1) PRIMARY KEY,
        config_id       INT NOT NULL,
        source          VARCHAR(8) NOT NULL,           -- 'VATSIM' or 'RW'
        weather         VARCHAR(8) NOT NULL,           -- 'VMC','LVMC','IMC','LIMC','VLIMC'
        rate_type       VARCHAR(4) NOT NULL,           -- 'ARR' or 'DEP'
        old_value       SMALLINT NULL,                 -- Previous rate value (NULL if new)
        new_value       SMALLINT NULL,                 -- New rate value (NULL if deleted)
        change_type     VARCHAR(8) NOT NULL,           -- 'INSERT', 'UPDATE', 'DELETE'
        changed_by_cid  INT NULL,                      -- VATSIM CID of user who made change
        changed_utc     DATETIME2 DEFAULT GETUTCDATE(),
        notes           VARCHAR(256) NULL,             -- Optional change notes

        CONSTRAINT FK_rate_history_config FOREIGN KEY (config_id)
            REFERENCES dbo.airport_config(config_id) ON DELETE CASCADE,
        INDEX IX_rate_history_config (config_id),
        INDEX IX_rate_history_changed (changed_utc DESC)
    );

    PRINT 'Created dbo.airport_config_rate_history table';
END
ELSE
BEGIN
    PRINT 'Table dbo.airport_config_rate_history already exists';
END
GO

-- =====================================================
-- 2. CONFIG HISTORY TABLE
-- Track changes to config metadata (name, runways, etc)
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_config_history')
BEGIN
    CREATE TABLE dbo.airport_config_history (
        history_id      BIGINT IDENTITY(1,1) PRIMARY KEY,
        config_id       INT NOT NULL,
        field_name      VARCHAR(32) NOT NULL,          -- 'config_name', 'arr_runways', etc
        old_value       VARCHAR(256) NULL,
        new_value       VARCHAR(256) NULL,
        change_type     VARCHAR(8) NOT NULL,           -- 'INSERT', 'UPDATE', 'DELETE'
        changed_by_cid  INT NULL,
        changed_utc     DATETIME2 DEFAULT GETUTCDATE(),
        notes           VARCHAR(256) NULL,

        CONSTRAINT FK_config_history_config FOREIGN KEY (config_id)
            REFERENCES dbo.airport_config(config_id) ON DELETE CASCADE,
        INDEX IX_config_history_config (config_id),
        INDEX IX_config_history_changed (changed_utc DESC)
    );

    PRINT 'Created dbo.airport_config_history table';
END
ELSE
BEGIN
    PRINT 'Table dbo.airport_config_history already exists';
END
GO

-- =====================================================
-- 3. HELPER VIEW: Recent Rate Changes
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_recent_rate_changes')
    DROP VIEW dbo.vw_recent_rate_changes;
GO

CREATE VIEW dbo.vw_recent_rate_changes AS
SELECT TOP 100
    h.history_id,
    h.config_id,
    c.airport_faa,
    c.airport_icao,
    c.config_name,
    h.source,
    h.weather,
    h.rate_type,
    h.old_value,
    h.new_value,
    h.change_type,
    h.changed_by_cid,
    h.changed_utc,
    h.notes,
    -- Change direction indicator
    CASE
        WHEN h.old_value IS NULL THEN 'NEW'
        WHEN h.new_value IS NULL THEN 'REMOVED'
        WHEN h.new_value > h.old_value THEN 'INCREASED'
        WHEN h.new_value < h.old_value THEN 'DECREASED'
        ELSE 'UNCHANGED'
    END AS change_direction,
    -- Change magnitude
    ABS(ISNULL(h.new_value, 0) - ISNULL(h.old_value, 0)) AS change_magnitude
FROM dbo.airport_config_rate_history h
JOIN dbo.airport_config c ON h.config_id = c.config_id
ORDER BY h.changed_utc DESC;
GO

PRINT 'Created vw_recent_rate_changes view';
GO

-- =====================================================
-- 4. STORED PROCEDURE: Log Rate Change
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_LogRateChange') AND type = 'P')
    DROP PROCEDURE dbo.sp_LogRateChange;
GO

CREATE PROCEDURE dbo.sp_LogRateChange
    @config_id INT,
    @source VARCHAR(8),
    @weather VARCHAR(8),
    @rate_type VARCHAR(4),
    @old_value SMALLINT = NULL,
    @new_value SMALLINT = NULL,
    @change_type VARCHAR(8),
    @changed_by_cid INT = NULL,
    @notes VARCHAR(256) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Only log if there's an actual change
    IF @old_value IS NULL AND @new_value IS NULL
        RETURN;

    IF @change_type = 'UPDATE' AND @old_value = @new_value
        RETURN;

    INSERT INTO dbo.airport_config_rate_history
        (config_id, source, weather, rate_type, old_value, new_value, change_type, changed_by_cid, notes)
    VALUES
        (@config_id, @source, @weather, @rate_type, @old_value, @new_value, @change_type, @changed_by_cid, @notes);
END;
GO

PRINT 'Created sp_LogRateChange stored procedure';
GO

-- =====================================================
-- 5. FUNCTION: Get Rate History for Config
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetRateHistory') AND type = 'TF')
    DROP FUNCTION dbo.fn_GetRateHistory;
GO

CREATE FUNCTION dbo.fn_GetRateHistory(
    @config_id INT,
    @days INT = 30
)
RETURNS TABLE
AS
RETURN
(
    SELECT
        history_id,
        source,
        weather,
        rate_type,
        old_value,
        new_value,
        change_type,
        changed_by_cid,
        changed_utc,
        notes
    FROM dbo.airport_config_rate_history
    WHERE config_id = @config_id
      AND changed_utc >= DATEADD(DAY, -@days, GETUTCDATE())
);
GO

PRINT 'Created fn_GetRateHistory function';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '082_rate_history_schema.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - airport_config_rate_history: Track rate value changes';
PRINT '  - airport_config_history: Track config metadata changes';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_recent_rate_changes: Last 100 rate changes with details';
PRINT '';
PRINT 'Stored procedures created:';
PRINT '  - sp_LogRateChange: Insert a rate change record';
PRINT '';
PRINT 'Functions created:';
PRINT '  - fn_GetRateHistory: Get rate history for a config';
GO
