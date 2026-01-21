-- ============================================================================
-- PERTI Reroute Routes & Advisory Sequence - TMI Database Migration
-- Version: 1.0
-- Date: January 2026
--
-- Creates:
--   - tmi_reroute_routes: Individual origin/destination route pairs
--   - tmi_advisory_sequence: Daily advisory number tracking
--   - sp_TMI_GetNextAdvisoryNumber: Stored procedure for auto-sequencing
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Reroute Routes & Advisory Sequence Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. tmi_reroute_routes - Individual origin/destination route pairs
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroute_routes')
BEGIN
    CREATE TABLE dbo.tmi_reroute_routes (
        -- Primary key
        route_id INT IDENTITY(1,1) PRIMARY KEY,

        -- Parent reroute reference
        reroute_id INT NOT NULL,

        -- Origin/Destination (can be single airport or space-delimited group)
        -- Examples: 'JFK', 'EWR LGA', 'PHL BWI DCA'
        origin NVARCHAR(64) NOT NULL,
        destination NVARCHAR(64) NOT NULL,

        -- The route string for this origin/dest pair
        -- Supports mandatory segment markers: >FIX and FIX<
        route_string NVARCHAR(MAX) NOT NULL,

        -- Display order in route table
        sort_order INT DEFAULT 0,

        -- Audit
        created_at DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        -- Foreign key constraint
        CONSTRAINT FK_tmi_reroute_routes_reroute FOREIGN KEY (reroute_id)
            REFERENCES dbo.tmi_reroutes(reroute_id) ON DELETE CASCADE
    );

    -- Indexes
    CREATE INDEX IX_tmi_reroute_routes_reroute ON dbo.tmi_reroute_routes(reroute_id);
    CREATE INDEX IX_tmi_reroute_routes_sort ON dbo.tmi_reroute_routes(reroute_id, sort_order);

    PRINT 'Created table: dbo.tmi_reroute_routes';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_reroute_routes already exists';
END
GO


-- ============================================================================
-- 2. tmi_advisory_sequence - Daily advisory number tracking
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_advisory_sequence')
BEGIN
    CREATE TABLE dbo.tmi_advisory_sequence (
        -- Date as primary key (one row per day)
        seq_date DATE PRIMARY KEY,

        -- Last used advisory number for this date
        last_number INT NOT NULL DEFAULT 0,

        -- Audit
        updated_at DATETIME2(0) DEFAULT SYSUTCDATETIME()
    );

    PRINT 'Created table: dbo.tmi_advisory_sequence';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_advisory_sequence already exists';
END
GO


-- ============================================================================
-- 3. sp_TMI_GetNextAdvisoryNumber - Get and increment daily advisory number
-- ============================================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_TMI_GetNextAdvisoryNumber')
BEGIN
    DROP PROCEDURE dbo.sp_TMI_GetNextAdvisoryNumber;
END
GO

CREATE PROCEDURE dbo.sp_TMI_GetNextAdvisoryNumber
    @advisory_number NVARCHAR(3) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @today DATE = CAST(GETUTCDATE() AS DATE);
    DECLARE @next_num INT;

    -- Use MERGE for atomic upsert
    MERGE dbo.tmi_advisory_sequence AS target
    USING (SELECT @today AS seq_date) AS source
    ON target.seq_date = source.seq_date
    WHEN MATCHED THEN
        UPDATE SET
            last_number = last_number + 1,
            updated_at = SYSUTCDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (seq_date, last_number, updated_at)
        VALUES (@today, 1, SYSUTCDATETIME());

    -- Get the current number
    SELECT @next_num = last_number
    FROM dbo.tmi_advisory_sequence
    WHERE seq_date = @today;

    -- Format as 3-digit string (001, 002, etc.)
    SET @advisory_number = RIGHT('000' + CAST(@next_num AS VARCHAR(3)), 3);
END
GO

PRINT 'Created procedure: dbo.sp_TMI_GetNextAdvisoryNumber';
GO


-- ============================================================================
-- 4. sp_TMI_PeekAdvisoryNumber - Get next number without incrementing
-- ============================================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_TMI_PeekAdvisoryNumber')
BEGIN
    DROP PROCEDURE dbo.sp_TMI_PeekAdvisoryNumber;
END
GO

CREATE PROCEDURE dbo.sp_TMI_PeekAdvisoryNumber
    @advisory_number NVARCHAR(3) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @today DATE = CAST(GETUTCDATE() AS DATE);
    DECLARE @next_num INT;

    -- Get current number + 1, or 1 if no entry exists
    SELECT @next_num = ISNULL(last_number, 0) + 1
    FROM dbo.tmi_advisory_sequence
    WHERE seq_date = @today;

    IF @next_num IS NULL
        SET @next_num = 1;

    -- Format as 3-digit string
    SET @advisory_number = RIGHT('000' + CAST(@next_num AS VARCHAR(3)), 3);
END
GO

PRINT 'Created procedure: dbo.sp_TMI_PeekAdvisoryNumber';
GO


-- ============================================================================
-- 5. Cleanup old sequence entries (optional maintenance)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_TMI_CleanupAdvisorySequence')
BEGIN
    DROP PROCEDURE dbo.sp_TMI_CleanupAdvisorySequence;
END
GO

CREATE PROCEDURE dbo.sp_TMI_CleanupAdvisorySequence
    @days_to_keep INT = 30
AS
BEGIN
    SET NOCOUNT ON;

    DELETE FROM dbo.tmi_advisory_sequence
    WHERE seq_date < DATEADD(DAY, -@days_to_keep, CAST(GETUTCDATE() AS DATE));

    SELECT @@ROWCOUNT AS rows_deleted;
END
GO

PRINT 'Created procedure: dbo.sp_TMI_CleanupAdvisorySequence';
GO


PRINT '';
PRINT '=== Reroute Routes & Advisory Sequence Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
