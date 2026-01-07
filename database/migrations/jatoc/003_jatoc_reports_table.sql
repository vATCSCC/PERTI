-- JATOC Reports Table
-- Stores structured report data for incidents

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_reports')
BEGIN
    CREATE TABLE dbo.jatoc_reports (
        id INT IDENTITY(1,1) PRIMARY KEY,
        incident_id INT NOT NULL,
        report_number VARCHAR(12) NOT NULL,
        
        -- Snapshot of incident data at report time
        facility NVARCHAR(8) NOT NULL,
        facility_type NVARCHAR(16) NULL,
        status NVARCHAR(32) NOT NULL,
        trigger_code CHAR(1) NULL,
        trigger_desc NVARCHAR(64) NULL,
        
        -- Timeline
        incident_start_utc DATETIME2 NOT NULL,
        incident_closeout_utc DATETIME2 NULL,
        report_generated_utc DATETIME2 DEFAULT SYSUTCDATETIME(),
        
        -- Content
        initial_remarks NVARCHAR(MAX) NULL,
        summary NVARCHAR(MAX) NULL,
        
        -- Structured data (JSON)
        updates_json NVARCHAR(MAX) NULL,          -- Array of all updates
        timeline_json NVARCHAR(MAX) NULL,         -- Key events timeline
        full_report_json NVARCHAR(MAX) NULL,      -- Complete structured report
        
        -- Metadata
        created_by NVARCHAR(64) NULL,
        updated_by NVARCHAR(64) NULL,
        created_at DATETIME2 DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2 DEFAULT SYSUTCDATETIME(),
        
        FOREIGN KEY (incident_id) REFERENCES dbo.jatoc_incidents(id),
        CONSTRAINT UQ_jatoc_reports_number UNIQUE (report_number)
    );
    
    CREATE INDEX IX_jatoc_reports_incident ON dbo.jatoc_reports(incident_id);
    CREATE INDEX IX_jatoc_reports_number ON dbo.jatoc_reports(report_number);
    
    PRINT 'Created jatoc_reports table';
END
GO
