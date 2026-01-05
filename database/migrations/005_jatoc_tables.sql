-- JATOC Database Schema for Azure SQL
-- Migration 005: JATOC Incident Monitor Tables
-- Updated with incident_number and report_number support

-- Incidents table
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_incidents')
BEGIN
    CREATE TABLE dbo.jatoc_incidents (
        id INT IDENTITY(1,1) PRIMARY KEY,
        incident_number VARCHAR(12) NULL,          -- Format: YYMMDD### (e.g., 251230001)
        report_number VARCHAR(12) NULL,            -- Format: YY-##### (e.g., 25-00001)
        facility NVARCHAR(8) NOT NULL,
        facility_type NVARCHAR(16) NULL,           -- ARTCC, TRACON, ATCT, COMBINED
        status NVARCHAR(32) NOT NULL,              -- ATC_ZERO, ATC_ALERT, ATC_LIMITED, NON_RESPONSIVE, OTHER
        trigger_code CHAR(1) NULL,
        trigger_desc NVARCHAR(64) NULL,
        paged BIT DEFAULT 0,
        start_utc DATETIME2 NOT NULL,
        update_utc DATETIME2 NULL,
        closeout_utc DATETIME2 NULL,
        remarks NVARCHAR(MAX) NULL,
        created_by NVARCHAR(64) NULL,
        updated_by NVARCHAR(64) NULL,
        incident_status NVARCHAR(16) DEFAULT 'ACTIVE',  -- PENDING, ACTIVE, CLOSED
        created_at DATETIME2 DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2 DEFAULT SYSUTCDATETIME()
    );
    
    CREATE INDEX IX_jatoc_incidents_status ON dbo.jatoc_incidents(incident_status);
    CREATE INDEX IX_jatoc_incidents_facility ON dbo.jatoc_incidents(facility);
    CREATE INDEX IX_jatoc_incidents_start ON dbo.jatoc_incidents(start_utc DESC);
    CREATE UNIQUE INDEX IX_jatoc_incidents_incnum ON dbo.jatoc_incidents(incident_number) WHERE incident_number IS NOT NULL;
    CREATE UNIQUE INDEX IX_jatoc_incidents_rptnum ON dbo.jatoc_incidents(report_number) WHERE report_number IS NOT NULL;
END
GO

-- Add columns if table exists but columns don't
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_incidents')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('jatoc_incidents') AND name = 'incident_number')
BEGIN
    ALTER TABLE dbo.jatoc_incidents ADD incident_number VARCHAR(12) NULL;
    ALTER TABLE dbo.jatoc_incidents ADD report_number VARCHAR(12) NULL;
    CREATE UNIQUE INDEX IX_jatoc_incidents_incnum ON dbo.jatoc_incidents(incident_number) WHERE incident_number IS NOT NULL;
    CREATE UNIQUE INDEX IX_jatoc_incidents_rptnum ON dbo.jatoc_incidents(report_number) WHERE report_number IS NOT NULL;
END
GO

-- Incident updates/history table
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_incident_updates')
BEGIN
    CREATE TABLE dbo.jatoc_incident_updates (
        id INT IDENTITY(1,1) PRIMARY KEY,
        incident_id INT NOT NULL,
        update_type NVARCHAR(32) NOT NULL,         -- REMARK, STATUS_CHANGE, PAGED, ESCALATION, REPORT_CREATED
        remarks NVARCHAR(MAX) NULL,
        created_by NVARCHAR(64) NULL,
        created_utc DATETIME2 DEFAULT SYSUTCDATETIME(),
        FOREIGN KEY (incident_id) REFERENCES dbo.jatoc_incidents(id) ON DELETE CASCADE
    );
    
    CREATE INDEX IX_jatoc_updates_incident ON dbo.jatoc_incident_updates(incident_id);
END
GO

-- Daily operational items
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_daily_ops')
BEGIN
    CREATE TABLE dbo.jatoc_daily_ops (
        id INT IDENTITY(1,1) PRIMARY KEY,
        item_type NVARCHAR(32) NOT NULL,           -- POTUS, SPACE
        content NVARCHAR(MAX) NULL,
        effective_date DATE NOT NULL,
        updated_by NVARCHAR(64) NULL,
        updated_at DATETIME2 DEFAULT SYSUTCDATETIME()
    );
    
    CREATE UNIQUE INDEX IX_jatoc_daily_ops_type_date ON dbo.jatoc_daily_ops(item_type, effective_date);
END
GO

-- Special emphasis items
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_special_emphasis')
BEGIN
    CREATE TABLE dbo.jatoc_special_emphasis (
        id INT IDENTITY(1,1) PRIMARY KEY,
        content NVARCHAR(512) NOT NULL,
        priority INT DEFAULT 0,
        active BIT DEFAULT 1,
        effective_start DATE NULL,
        effective_end DATE NULL,
        created_by NVARCHAR(64) NULL,
        created_at DATETIME2 DEFAULT SYSUTCDATETIME()
    );
END
GO

-- Personnel roster
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_personnel')
BEGIN
    CREATE TABLE dbo.jatoc_personnel (
        id INT IDENTITY(1,1) PRIMARY KEY,
        element NVARCHAR(16) NOT NULL UNIQUE,      -- Position code (JATOC1, JATOC2, etc.)
        initials NVARCHAR(8) NULL,
        name NVARCHAR(64) NULL,
        updated_by NVARCHAR(64) NULL,
        updated_at DATETIME2 DEFAULT SYSUTCDATETIME()
    );
    
    -- Insert default positions
    INSERT INTO dbo.jatoc_personnel (element) VALUES 
        ('JATOC1'), ('JATOC2'), ('JATOC3'), ('JATOC4'), ('JATOC5'),
        ('JATOC6'), ('JATOC7'), ('JATOC8'), ('JATOC9'), ('JATOC10'), ('SUP');
END
GO

-- Operations level
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_ops_level')
BEGIN
    CREATE TABLE dbo.jatoc_ops_level (
        id INT IDENTITY(1,1) PRIMARY KEY,
        ops_level INT NOT NULL DEFAULT 1,          -- 1, 2, or 3
        reason NVARCHAR(256) NULL,
        set_by NVARCHAR(64) NULL,
        set_at DATETIME2 DEFAULT SYSUTCDATETIME()
    );
    
    INSERT INTO dbo.jatoc_ops_level (ops_level, reason, set_by) VALUES (1, 'Initial state', 'System');
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
    
    -- Lock and get/increment sequence
    BEGIN TRANSACTION;
    
    UPDATE dbo.jatoc_sequences WITH (TABLOCKX)
    SET seq_value = seq_value + 1, seq_date = @today
    WHERE seq_name = 'incident' AND seq_date = @today;
    
    IF @@ROWCOUNT = 0
    BEGIN
        -- New day or first ever - reset or create
        IF EXISTS (SELECT 1 FROM dbo.jatoc_sequences WHERE seq_name = 'incident')
            UPDATE dbo.jatoc_sequences SET seq_value = 1, seq_date = @today WHERE seq_name = 'incident';
        ELSE
            INSERT INTO dbo.jatoc_sequences (seq_name, seq_date, seq_value) VALUES ('incident', @today, 1);
    END
    
    SELECT @seq = seq_value FROM dbo.jatoc_sequences WHERE seq_name = 'incident';
    
    COMMIT;
    
    SET @incident_number = @date_prefix + RIGHT('000' + CAST(@seq AS VARCHAR), 3);
END
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
        -- New year - reset
        IF EXISTS (SELECT 1 FROM dbo.jatoc_sequences WHERE seq_name = 'report')
            UPDATE dbo.jatoc_sequences SET seq_value = 1, seq_date = @year_start WHERE seq_name = 'report';
        ELSE
            INSERT INTO dbo.jatoc_sequences (seq_name, seq_date, seq_value) VALUES ('report', @year_start, 1);
    END
    
    SELECT @seq = seq_value FROM dbo.jatoc_sequences WHERE seq_name = 'report';
    
    COMMIT;
    
    SET @report_number = @year_prefix + '-' + RIGHT('00000' + CAST(@seq AS VARCHAR), 5);
END
GO
