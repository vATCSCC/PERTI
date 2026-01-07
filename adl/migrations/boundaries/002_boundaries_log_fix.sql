-- =====================================================
-- Phase 5E.1: Fix - Create adl_flight_boundary_log
-- Migration: 049b_boundaries_log_fix.sql
-- Description: Creates boundary log table without FK to flight_core
-- =====================================================

-- Flight boundary log - tracks boundary transitions
-- Note: FK to adl_flight_core omitted due to missing PK on that table
CREATE TABLE adl_flight_boundary_log (
    log_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    flight_id INT NOT NULL,               -- References adl_flight_core (no FK constraint)
    
    -- Boundary entered
    boundary_id INT NOT NULL,             -- FK to adl_boundary
    boundary_type VARCHAR(20) NOT NULL,
    boundary_code VARCHAR(20) NOT NULL,
    
    -- Entry/exit tracking
    entry_time DATETIME2 NOT NULL,
    exit_time DATETIME2 NULL,             -- NULL if still in boundary
    
    -- Position at entry
    entry_lat DECIMAL(10,6) NOT NULL,
    entry_lon DECIMAL(11,6) NOT NULL,
    entry_altitude INT NULL,
    
    -- Position at exit (populated when exiting)
    exit_lat DECIMAL(10,6) NULL,
    exit_lon DECIMAL(11,6) NULL,
    exit_altitude INT NULL,
    
    -- Metadata
    created_at DATETIME2 DEFAULT GETUTCDATE(),
    
    -- Foreign key to boundary only
    CONSTRAINT FK_boundary_log_boundary FOREIGN KEY (boundary_id) REFERENCES adl_boundary(boundary_id)
);
GO

-- Indexes for boundary log queries
CREATE INDEX IX_boundary_log_flight ON adl_flight_boundary_log(flight_id);
CREATE INDEX IX_boundary_log_boundary ON adl_flight_boundary_log(boundary_id);
CREATE INDEX IX_boundary_log_entry ON adl_flight_boundary_log(entry_time);
CREATE INDEX IX_boundary_log_active ON adl_flight_boundary_log(exit_time) WHERE exit_time IS NULL;
GO

PRINT 'adl_flight_boundary_log table created successfully';
GO
