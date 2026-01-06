-- =====================================================
-- Phase 5E.1: Airspace Boundaries Schema
-- Migration: 049_boundaries_schema.sql
-- Description: Creates tables for ARTCC, sector, and TRACON boundaries
-- =====================================================

-- Main boundaries table - stores all boundary types with geography
CREATE TABLE adl_boundary (
    boundary_id INT IDENTITY(1,1) PRIMARY KEY,
    
    -- Boundary type classification
    boundary_type VARCHAR(20) NOT NULL,  -- 'ARTCC', 'SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH', 'TRACON'
    
    -- Common identification
    boundary_code VARCHAR(20) NOT NULL,   -- ICAOCODE for ARTCC, label for sectors/TRACON (e.g., 'ZDC', 'ZAB15', 'A11')
    boundary_name VARCHAR(100) NULL,      -- FIRname for ARTCC, label for others
    
    -- Parent relationships (for sectors/TRACONs)
    parent_artcc VARCHAR(10) NULL,        -- 'zdc', 'zab', etc. - links sector to ARTCC
    sector_number VARCHAR(10) NULL,       -- '15', 'A11', etc. - sector identifier within ARTCC
    
    -- ARTCC-specific properties
    icao_code VARCHAR(10) NULL,           -- ICAO identifier
    vatsim_region VARCHAR(20) NULL,       -- EMEA, AMAS, APAC
    vatsim_division VARCHAR(20) NULL,     -- VATUSA, VATEUD, etc.
    vatsim_subdivision VARCHAR(20) NULL,  -- ZDC, ADR, etc.
    is_oceanic BIT DEFAULT 0,             -- Oceanic airspace flag
    
    -- Altitude restrictions
    floor_altitude INT NULL,              -- Floor in FL (NULL = surface)
    ceiling_altitude INT NULL,            -- Ceiling in FL (NULL = unlimited)
    
    -- Label display coordinates
    label_lat DECIMAL(10,6) NULL,
    label_lon DECIMAL(11,6) NULL,
    
    -- Geometry storage - using geography for spatial queries
    boundary_geography GEOGRAPHY NOT NULL,
    
    -- Shape metrics (from GeoJSON)
    shape_length DECIMAL(15,10) NULL,
    shape_area DECIMAL(15,10) NULL,
    
    -- Source tracking
    source_object_id INT NULL,            -- Original OBJECTID from GeoJSON
    source_fid INT NULL,                  -- Original fid from ARTCC GeoJSON
    source_file VARCHAR(50) NULL,         -- Which file this came from
    
    -- Metadata
    is_active BIT DEFAULT 1,
    created_at DATETIME2 DEFAULT GETUTCDATE(),
    updated_at DATETIME2 DEFAULT GETUTCDATE(),
    
    -- Constraints
    CONSTRAINT CHK_boundary_type CHECK (boundary_type IN ('ARTCC', 'SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH', 'TRACON'))
);
GO

-- Indexes for efficient queries
CREATE INDEX IX_boundary_type ON adl_boundary(boundary_type);
CREATE INDEX IX_boundary_code ON adl_boundary(boundary_code);
CREATE INDEX IX_parent_artcc ON adl_boundary(parent_artcc) WHERE parent_artcc IS NOT NULL;
CREATE INDEX IX_boundary_active ON adl_boundary(is_active);

-- Spatial index for geography queries
CREATE SPATIAL INDEX SIDX_boundary_geography ON adl_boundary(boundary_geography);
GO


-- Flight boundary log - tracks boundary transitions
CREATE TABLE adl_flight_boundary_log (
    log_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    flight_id INT NOT NULL,               -- FK to adl_flight_core
    
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
    
    -- Foreign keys
    CONSTRAINT FK_boundary_log_flight FOREIGN KEY (flight_id) REFERENCES adl_flight_core(flight_id),
    CONSTRAINT FK_boundary_log_boundary FOREIGN KEY (boundary_id) REFERENCES adl_boundary(boundary_id)
);
GO

-- Indexes for boundary log queries
CREATE INDEX IX_boundary_log_flight ON adl_flight_boundary_log(flight_id);
CREATE INDEX IX_boundary_log_boundary ON adl_flight_boundary_log(boundary_id);
CREATE INDEX IX_boundary_log_entry ON adl_flight_boundary_log(entry_time);
CREATE INDEX IX_boundary_log_active ON adl_flight_boundary_log(exit_time) WHERE exit_time IS NULL;
GO


-- Add current boundary tracking to flight core
-- Note: Run this as ALTER if table already exists
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_artcc')
BEGIN
    ALTER TABLE adl_flight_core ADD current_artcc VARCHAR(10) NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_artcc_id')
BEGIN
    ALTER TABLE adl_flight_core ADD current_artcc_id INT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector')
BEGIN
    ALTER TABLE adl_flight_core ADD current_sector VARCHAR(20) NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_sector_id')
BEGIN
    ALTER TABLE adl_flight_core ADD current_sector_id INT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_tracon')
BEGIN
    ALTER TABLE adl_flight_core ADD current_tracon VARCHAR(20) NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'current_tracon_id')
BEGIN
    ALTER TABLE adl_flight_core ADD current_tracon_id INT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_flight_core') AND name = 'boundary_updated_at')
BEGIN
    ALTER TABLE adl_flight_core ADD boundary_updated_at DATETIME2 NULL;
END;
GO


-- Lookup view for quick boundary queries
CREATE OR ALTER VIEW vw_boundaries_summary AS
SELECT 
    boundary_type,
    COUNT(*) as boundary_count,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_count
FROM adl_boundary
GROUP BY boundary_type;
GO


-- View for active boundaries with simplified geometry info
CREATE OR ALTER VIEW vw_active_boundaries AS
SELECT 
    boundary_id,
    boundary_type,
    boundary_code,
    boundary_name,
    parent_artcc,
    sector_number,
    icao_code,
    vatsim_region,
    vatsim_division,
    is_oceanic,
    floor_altitude,
    ceiling_altitude,
    label_lat,
    label_lon,
    shape_area,
    boundary_geography.STArea() as computed_area_sqm,
    boundary_geography.STNumPoints() as vertex_count
FROM adl_boundary
WHERE is_active = 1;
GO


-- View for flight current boundary status
CREATE OR ALTER VIEW vw_flight_boundaries AS
SELECT 
    fc.flight_id,
    fc.callsign,
    fc.current_artcc,
    ab_artcc.boundary_name as artcc_name,
    fc.current_sector,
    ab_sector.boundary_name as sector_name,
    fc.current_tracon,
    ab_tracon.boundary_name as tracon_name,
    fc.boundary_updated_at
FROM adl_flight_core fc
LEFT JOIN adl_boundary ab_artcc ON fc.current_artcc_id = ab_artcc.boundary_id
LEFT JOIN adl_boundary ab_sector ON fc.current_sector_id = ab_sector.boundary_id
LEFT JOIN adl_boundary ab_tracon ON fc.current_tracon_id = ab_tracon.boundary_id;
GO


PRINT 'Phase 5E.1: Boundaries schema created successfully';
PRINT 'Tables created: adl_boundary, adl_flight_boundary_log';
PRINT 'Columns added to adl_flight_core: current_artcc, current_sector, current_tracon (with IDs)';
PRINT 'Views created: vw_boundaries_summary, vw_active_boundaries, vw_flight_boundaries';
GO
