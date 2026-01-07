-- =====================================================
-- Phase 5E.2: Fix Boundary Log Schema + Multi-Sector Support
-- Migration: 053_fix_boundary_log_schema.sql
-- 
-- Changes:
--   - flight_id INT → flight_uid BIGINT
--   - Add separate columns for low/high/superhigh sectors
--   - Support multiple overlapping sectors per type
-- =====================================================

PRINT 'Phase 5E.2: Fix boundary log schema + multi-sector support...';
GO

-- =====================================================
-- Part 1: Fix adl_flight_boundary_log
-- =====================================================

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'adl_flight_boundary_log')
BEGIN
    IF EXISTS (SELECT 1 FROM sys.columns 
               WHERE object_id = OBJECT_ID('adl_flight_boundary_log') 
               AND name = 'flight_id' 
               AND system_type_id = 56) -- INT
    BEGIN
        PRINT 'Dropping and recreating adl_flight_boundary_log with correct schema...';
        
        DROP INDEX IF EXISTS IX_boundary_log_flight ON adl_flight_boundary_log;
        DROP INDEX IF EXISTS IX_boundary_log_boundary ON adl_flight_boundary_log;
        DROP INDEX IF EXISTS IX_boundary_log_entry ON adl_flight_boundary_log;
        DROP INDEX IF EXISTS IX_boundary_log_active ON adl_flight_boundary_log;
        
        IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_boundary_log_boundary')
            ALTER TABLE adl_flight_boundary_log DROP CONSTRAINT FK_boundary_log_boundary;
        
        DROP TABLE adl_flight_boundary_log;
    END
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'adl_flight_boundary_log')
BEGIN
    CREATE TABLE adl_flight_boundary_log (
        log_id BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid BIGINT NOT NULL,
        
        boundary_id INT NOT NULL,
        boundary_type VARCHAR(20) NOT NULL,
        boundary_code VARCHAR(50) NOT NULL,
        
        entry_time DATETIME2(0) NOT NULL,
        exit_time DATETIME2(0) NULL,
        
        entry_lat DECIMAL(10,6) NOT NULL,
        entry_lon DECIMAL(11,6) NOT NULL,
        entry_altitude INT NULL,
        
        exit_lat DECIMAL(10,6) NULL,
        exit_lon DECIMAL(11,6) NULL,
        exit_altitude INT NULL,
        
        duration_seconds INT NULL,
        created_at DATETIME2(0) DEFAULT GETUTCDATE(),
        
        CONSTRAINT FK_boundary_log_boundary FOREIGN KEY (boundary_id) 
            REFERENCES adl_boundary(boundary_id)
    );
    
    CREATE NONCLUSTERED INDEX IX_boundary_log_flight ON adl_flight_boundary_log(flight_uid);
    CREATE NONCLUSTERED INDEX IX_boundary_log_boundary ON adl_flight_boundary_log(boundary_id);
    CREATE NONCLUSTERED INDEX IX_boundary_log_entry ON adl_flight_boundary_log(entry_time);
    CREATE NONCLUSTERED INDEX IX_boundary_log_active ON adl_flight_boundary_log(exit_time) WHERE exit_time IS NULL;
    CREATE NONCLUSTERED INDEX IX_boundary_log_type ON adl_flight_boundary_log(boundary_type);
    
    PRINT 'Created adl_flight_boundary_log with flight_uid BIGINT';
END
GO

-- =====================================================
-- Part 2: Add/update columns on adl_flight_core
-- =====================================================

-- ARTCC (single value - flight can only be in one ARTCC)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_artcc')
    ALTER TABLE adl_flight_core ADD current_artcc VARCHAR(10) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_artcc_id')
    ALTER TABLE adl_flight_core ADD current_artcc_id INT NULL;
GO

-- TRACON (single value - use smallest containing)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_tracon')
    ALTER TABLE adl_flight_core ADD current_tracon VARCHAR(50) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_tracon_id')
    ALTER TABLE adl_flight_core ADD current_tracon_id INT NULL;
GO

-- LOW SECTORS (multiple possible - comma-separated codes, JSON IDs)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_low')
    ALTER TABLE adl_flight_core ADD current_sector_low VARCHAR(255) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_low_ids')
    ALTER TABLE adl_flight_core ADD current_sector_low_ids NVARCHAR(MAX) NULL;  -- JSON array
GO

-- HIGH SECTORS (multiple possible)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_high')
    ALTER TABLE adl_flight_core ADD current_sector_high VARCHAR(255) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_high_ids')
    ALTER TABLE adl_flight_core ADD current_sector_high_ids NVARCHAR(MAX) NULL;  -- JSON array
GO

-- SUPERHIGH SECTORS (multiple possible)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_superhigh')
    ALTER TABLE adl_flight_core ADD current_sector_superhigh VARCHAR(255) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_superhigh_ids')
    ALTER TABLE adl_flight_core ADD current_sector_superhigh_ids NVARCHAR(MAX) NULL;  -- JSON array
GO

-- Boundary update timestamp
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'boundary_updated_at')
    ALTER TABLE adl_flight_core ADD boundary_updated_at DATETIME2(0) NULL;
GO

-- Drop old single-sector columns if they exist (migration from old schema)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector')
BEGIN
    PRINT 'Dropping deprecated current_sector column...';
    ALTER TABLE adl_flight_core DROP COLUMN current_sector;
END
GO

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_id')
BEGIN
    PRINT 'Dropping deprecated current_sector_id column...';
    ALTER TABLE adl_flight_core DROP COLUMN current_sector_id;
END
GO

PRINT 'Phase 5E.2: Schema update complete';
PRINT 'Columns added: current_sector_low, current_sector_high, current_sector_superhigh (with _ids variants)';
GO
