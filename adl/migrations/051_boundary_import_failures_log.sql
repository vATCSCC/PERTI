-- Migration 051: Boundary Import Failures Log
-- Purpose: Track which boundaries fail to import and why

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_boundary_import_log')
BEGIN
    CREATE TABLE adl_boundary_import_log (
        log_id INT IDENTITY(1,1) PRIMARY KEY,
        import_run_id UNIQUEIDENTIFIER NOT NULL,
        boundary_type VARCHAR(20) NOT NULL,
        boundary_code VARCHAR(50) NOT NULL,
        boundary_name NVARCHAR(255) NULL,
        source_file VARCHAR(100) NULL,
        status VARCHAR(20) NOT NULL, -- 'SUCCESS', 'FAILED', 'SKIPPED'
        error_message NVARCHAR(MAX) NULL,
        error_code VARCHAR(50) NULL,
        wkt_length INT NULL,
        geometry_type VARCHAR(20) NULL,
        point_count INT NULL,
        created_at DATETIME2 DEFAULT GETUTCDATE(),
        
        INDEX IX_import_log_run (import_run_id),
        INDEX IX_import_log_status (status),
        INDEX IX_import_log_type (boundary_type)
    );
    
    PRINT 'Created adl_boundary_import_log table';
END
ELSE
BEGIN
    PRINT 'adl_boundary_import_log table already exists';
END
GO
