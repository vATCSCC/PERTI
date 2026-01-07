-- =====================================================
-- Phase 5E.2: Fix Boundary Log Schema
-- Migration: 053_fix_boundary_log_schema.sql
-- Description: Changes flight_id INT to flight_uid BIGINT for consistency
-- =====================================================

PRINT 'Phase 5E.2: Fix boundary log schema...';
GO

-- Check if table exists and needs modification
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'adl_flight_boundary_log')
BEGIN
    -- Check current column type
    IF EXISTS (SELECT 1 FROM sys.columns 
               WHERE object_id = OBJECT_ID('adl_flight_boundary_log') 
               AND name = 'flight_id' 
               AND system_type_id = 56) -- INT
    BEGIN
        PRINT 'Dropping and recreating adl_flight_boundary_log with correct schema...';
        
        -- Drop indexes first
        DROP INDEX IF EXISTS IX_boundary_log_flight ON adl_flight_boundary_log;
        DROP INDEX IF EXISTS IX_boundary_log_boundary ON adl_flight_boundary_log;
        DROP INDEX IF EXISTS IX_boundary_log_entry ON adl_flight_boundary_log;
        DROP INDEX IF EXISTS IX_boundary_log_active ON adl_flight_boundary_log;
        
        -- Drop FK constraint
        IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_boundary_log_boundary')
            ALTER TABLE adl_flight_boundary_log DROP CONSTRAINT FK_boundary_log_boundary;
        
        -- Drop and recreate table
        DROP TABLE adl_flight_boundary_log;
    END
END
GO

-- Create table with correct schema if it doesn't exist
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'adl_flight_boundary_log')
BEGIN
    CREATE TABLE adl_flight_boundary_log (
        log_id BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid BIGINT NOT NULL,           -- Changed from flight_id INT
        
        -- Boundary entered
        boundary_id INT NOT NULL,
        boundary_type VARCHAR(20) NOT NULL,
        boundary_code VARCHAR(50) NOT NULL,   -- Widened to match adl_boundary
        
        -- Entry/exit tracking
        entry_time DATETIME2(0) NOT NULL,
        exit_time DATETIME2(0) NULL,          -- NULL if still in boundary
        
        -- Position at entry
        entry_lat DECIMAL(10,6) NOT NULL,
        entry_lon DECIMAL(11,6) NOT NULL,
        entry_altitude INT NULL,
        
        -- Position at exit (populated when exiting)
        exit_lat DECIMAL(10,6) NULL,
        exit_lon DECIMAL(11,6) NULL,
        exit_altitude INT NULL,
        
        -- Duration tracking
        duration_seconds INT NULL,            -- Computed on exit
        
        -- Metadata
        created_at DATETIME2(0) DEFAULT GETUTCDATE(),
        
        -- FK to boundary (no FK to flight_core to avoid constraint issues)
        CONSTRAINT FK_boundary_log_boundary FOREIGN KEY (boundary_id) 
            REFERENCES adl_boundary(boundary_id)
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_boundary_log_flight ON adl_flight_boundary_log(flight_uid);
    CREATE NONCLUSTERED INDEX IX_boundary_log_boundary ON adl_flight_boundary_log(boundary_id);
    CREATE NONCLUSTERED INDEX IX_boundary_log_entry ON adl_flight_boundary_log(entry_time);
    CREATE NONCLUSTERED INDEX IX_boundary_log_active ON adl_flight_boundary_log(exit_time) WHERE exit_time IS NULL;
    CREATE NONCLUSTERED INDEX IX_boundary_log_type ON adl_flight_boundary_log(boundary_type);
    
    PRINT 'Created adl_flight_boundary_log with flight_uid BIGINT';
END
GO

-- Verify/add columns to adl_flight_core if not present
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_artcc')
    ALTER TABLE adl_flight_core ADD current_artcc VARCHAR(10) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_artcc_id')
    ALTER TABLE adl_flight_core ADD current_artcc_id INT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector')
    ALTER TABLE adl_flight_core ADD current_sector VARCHAR(50) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_id')
    ALTER TABLE adl_flight_core ADD current_sector_id INT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_tracon')
    ALTER TABLE adl_flight_core ADD current_tracon VARCHAR(50) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_tracon_id')
    ALTER TABLE adl_flight_core ADD current_tracon_id INT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'boundary_updated_at')
    ALTER TABLE adl_flight_core ADD boundary_updated_at DATETIME2(0) NULL;
GO

PRINT 'Phase 5E.2: Boundary log schema fix complete';
GO
