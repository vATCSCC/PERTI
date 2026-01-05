-- JATOC Database Schema for Azure SQL
-- Migration 005: JATOC Incident Monitor Tables
-- Fixed: Separate batches for ALTER TABLE and CREATE INDEX

-- First, add the columns if they don't exist (using dynamic SQL to avoid parse-time validation)
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_incidents')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('jatoc_incidents') AND name = 'incident_number')
BEGIN
    ALTER TABLE dbo.jatoc_incidents ADD incident_number VARCHAR(12) NULL;
    PRINT 'Added incident_number column';
END
GO

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_incidents')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('jatoc_incidents') AND name = 'report_number')
BEGIN
    ALTER TABLE dbo.jatoc_incidents ADD report_number VARCHAR(12) NULL;
    PRINT 'Added report_number column';
END
GO

-- Now create indexes (in separate batch after columns exist)
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('jatoc_incidents') AND name = 'incident_number')
   AND NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_jatoc_incidents_incnum')
BEGIN
    CREATE UNIQUE INDEX IX_jatoc_incidents_incnum ON dbo.jatoc_incidents(incident_number) WHERE incident_number IS NOT NULL;
    PRINT 'Created incident_number index';
END
GO

IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('jatoc_incidents') AND name = 'report_number')
   AND NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_jatoc_incidents_rptnum')
BEGIN
    CREATE UNIQUE INDEX IX_jatoc_incidents_rptnum ON dbo.jatoc_incidents(report_number) WHERE report_number IS NOT NULL;
    PRINT 'Created report_number index';
END
GO

-- Sequence table for incident/report numbers
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_sequences')
BEGIN
    CREATE TABLE dbo.jatoc_sequences (
        seq_name VARCHAR(32) PRIMARY KEY,
        seq_date DATE NOT NULL,
        seq_value INT NOT NULL DEFAULT 0
    );
    PRINT 'Created jatoc_sequences table';
END
GO

-- Stored procedure to generate incident number
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_jatoc_next_incident_number')
    DROP PROCEDURE dbo.sp_jatoc_next_incident_number;
GO

CREATE PROCEDURE dbo.sp_jatoc_next_incident_number
    @incident_number VARCHAR(12) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @today DATE = CAST(SYSUTCDATETIME() AS DATE);
    DECLARE @seq INT;
    DECLARE @date_prefix VARCHAR(6) = FORMAT(@today, 'yyMMdd');
    
    BEGIN TRANSACTION;
    
    -- Try to increment existing row for today
    UPDATE dbo.jatoc_sequences WITH (TABLOCKX)
    SET seq_value = seq_value + 1
    WHERE seq_name = 'incident' AND seq_date = @today;
    
    IF @@ROWCOUNT = 0
    BEGIN
        -- New day - check if row exists at all
        IF EXISTS (SELECT 1 FROM dbo.jatoc_sequences WHERE seq_name = 'incident')
        BEGIN
            -- Reset for new day
            UPDATE dbo.jatoc_sequences SET seq_value = 1, seq_date = @today WHERE seq_name = 'incident';
        END
        ELSE
        BEGIN
            -- First ever
            INSERT INTO dbo.jatoc_sequences (seq_name, seq_date, seq_value) VALUES ('incident', @today, 1);
        END
    END
    
    SELECT @seq = seq_value FROM dbo.jatoc_sequences WHERE seq_name = 'incident';
    
    COMMIT;
    
    SET @incident_number = @date_prefix + RIGHT('000' + CAST(@seq AS VARCHAR), 3);
END
GO

PRINT 'Created sp_jatoc_next_incident_number';
GO

-- Stored procedure to generate report number
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_jatoc_next_report_number')
    DROP PROCEDURE dbo.sp_jatoc_next_report_number;
GO

CREATE PROCEDURE dbo.sp_jatoc_next_report_number
    @report_number VARCHAR(12) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @year_start DATE = DATEFROMPARTS(YEAR(SYSUTCDATETIME()), 1, 1);
    DECLARE @seq INT;
    DECLARE @year_prefix VARCHAR(2) = FORMAT(SYSUTCDATETIME(), 'yy');
    
    BEGIN TRANSACTION;
    
    -- Check if we're in the same year
    IF EXISTS (SELECT 1 FROM dbo.jatoc_sequences WHERE seq_name = 'report' AND seq_date >= @year_start)
    BEGIN
        UPDATE dbo.jatoc_sequences WITH (TABLOCKX)
        SET seq_value = seq_value + 1
        WHERE seq_name = 'report';
    END
    ELSE
    BEGIN
        -- New year - reset or create
        IF EXISTS (SELECT 1 FROM dbo.jatoc_sequences WHERE seq_name = 'report')
        BEGIN
            UPDATE dbo.jatoc_sequences SET seq_value = 1, seq_date = @year_start WHERE seq_name = 'report';
        END
        ELSE
        BEGIN
            INSERT INTO dbo.jatoc_sequences (seq_name, seq_date, seq_value) VALUES ('report', @year_start, 1);
        END
    END
    
    SELECT @seq = seq_value FROM dbo.jatoc_sequences WHERE seq_name = 'report';
    
    COMMIT;
    
    SET @report_number = @year_prefix + '-' + RIGHT('00000' + CAST(@seq AS VARCHAR), 5);
END
GO

PRINT 'Created sp_jatoc_next_report_number';
GO

-- Verify columns exist
SELECT 
    c.name AS column_name,
    t.name AS data_type
FROM sys.columns c
JOIN sys.types t ON c.user_type_id = t.user_type_id
WHERE c.object_id = OBJECT_ID('jatoc_incidents')
  AND c.name IN ('incident_number', 'report_number');
GO
