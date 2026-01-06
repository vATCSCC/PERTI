-- ============================================================================
-- 040_oooi_schema.sql
-- OOOI Zone Detection Schema
-- 
-- Creates tables for OSM airport geometry and zone transition tracking
-- Based on oooi_enhanced_design_v2.md
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  PERTI OOOI Zone Detection System - Schema Migration';
PRINT '  Version 1.0 - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';
GO

-- ============================================================================
-- Table 1: airport_geometry - OSM airport zones
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_geometry') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airport_geometry (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        airport_icao        NVARCHAR(4) NOT NULL,
        zone_type           NVARCHAR(16) NOT NULL,      -- PARKING/APRON/TAXILANE/TAXIWAY/HOLD/RUNWAY
        zone_name           NVARCHAR(32) NULL,          -- e.g., "A1", "RWY 28L", "TANGO"
        osm_id              BIGINT NULL,                -- OSM way/node ID
        geometry            GEOGRAPHY NOT NULL,         -- Polygon or buffered LineString
        geometry_wkt        NVARCHAR(MAX) NULL,         -- WKT for debugging
        center_lat          DECIMAL(10,7) NULL,
        center_lon          DECIMAL(11,7) NULL,
        heading_deg         SMALLINT NULL,              -- For runways
        length_ft           INT NULL,                   -- For runways
        width_ft            INT NULL,                   -- For runways/taxiways
        elevation_ft        INT NULL,                   -- Airport elevation
        is_active           BIT NOT NULL DEFAULT 1,
        source              NVARCHAR(16) NOT NULL DEFAULT 'OSM', -- OSM/MANUAL/FAA/FALLBACK
        import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_airport_geo_icao ON dbo.airport_geometry (airport_icao, zone_type);
    CREATE NONCLUSTERED INDEX IX_airport_geo_type ON dbo.airport_geometry (zone_type, airport_icao);
    
    -- Spatial index
    CREATE SPATIAL INDEX IX_airport_geo_spatial ON dbo.airport_geometry (geometry)
        USING GEOGRAPHY_AUTO_GRID
        WITH (CELLS_PER_OBJECT = 16);
    
    PRINT '  ? Created airport_geometry table';
END
ELSE
    PRINT '  - airport_geometry already exists';
GO

-- ============================================================================
-- Table 2: adl_zone_events - Zone transition history
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_zone_events') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_zone_events (
        event_id            BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,            -- FK to adl_flight_core
        
        -- Event Details
        event_utc           DATETIME2(0) NOT NULL,
        event_type          NVARCHAR(16) NOT NULL,      -- ENTER/EXIT/TRANSITION
        
        -- Zone Information
        airport_icao        NVARCHAR(4) NULL,           -- Which airport
        from_zone           NVARCHAR(16) NULL,          -- Previous zone (NULL if first)
        to_zone             NVARCHAR(16) NOT NULL,      -- New zone
        zone_name           NVARCHAR(32) NULL,          -- Specific zone name (e.g., "A1", "TWY K")
        
        -- Position at Event
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,
        altitude_ft         INT NULL,
        groundspeed_kts     INT NULL,
        heading_deg         SMALLINT NULL,
        vertical_rate_fpm   INT NULL,
        
        -- Detection Method
        detection_method    NVARCHAR(32) NOT NULL DEFAULT 'OSM_GEOMETRY',  -- OSM_GEOMETRY/DISTANCE/SPEED
        distance_to_zone_m  DECIMAL(10,2) NULL,         -- How close to zone boundary
        confidence          DECIMAL(3,2) NULL,          -- 0.00-1.00 confidence score
        
        -- Indexes
        INDEX IX_zone_events_flight (flight_uid, event_utc),
        INDEX IX_zone_events_time (event_utc DESC),
        INDEX IX_zone_events_zone (to_zone, event_utc),
        INDEX IX_zone_events_airport (airport_icao, event_utc DESC)
    );
    
    PRINT '  ? Created adl_zone_events table';
END
ELSE
    PRINT '  - adl_zone_events already exists';
GO

-- ============================================================================
-- Add zone tracking columns to adl_flight_core
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'current_zone')
    ALTER TABLE dbo.adl_flight_core ADD current_zone NVARCHAR(16) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'current_zone_airport')
    ALTER TABLE dbo.adl_flight_core ADD current_zone_airport NVARCHAR(4) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_zone_check_utc')
    ALTER TABLE dbo.adl_flight_core ADD last_zone_check_utc DATETIME2(0) NULL;

PRINT '  ? Added zone tracking columns to adl_flight_core';
GO

-- ============================================================================
-- Add extended zone times to adl_flight_times (if not already added)
-- ============================================================================

-- Departure zone times
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'parking_left_utc')
    ALTER TABLE dbo.adl_flight_times ADD parking_left_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'taxiway_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD taxiway_entered_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'hold_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD hold_entered_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'runway_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD runway_entered_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'takeoff_roll_utc')
    ALTER TABLE dbo.adl_flight_times ADD takeoff_roll_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'rotation_utc')
    ALTER TABLE dbo.adl_flight_times ADD rotation_utc DATETIME2(0) NULL;

-- Arrival zone times
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'approach_start_utc')
    ALTER TABLE dbo.adl_flight_times ADD approach_start_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'threshold_utc')
    ALTER TABLE dbo.adl_flight_times ADD threshold_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'touchdown_utc')
    ALTER TABLE dbo.adl_flight_times ADD touchdown_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'rollout_end_utc')
    ALTER TABLE dbo.adl_flight_times ADD rollout_end_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'taxiway_arr_utc')
    ALTER TABLE dbo.adl_flight_times ADD taxiway_arr_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'parking_entered_utc')
    ALTER TABLE dbo.adl_flight_times ADD parking_entered_utc DATETIME2(0) NULL;

PRINT '  ? Added extended zone time columns to adl_flight_times';
GO

-- ============================================================================
-- Table 3: airport_geometry_import_log - Track OSM imports
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airport_geometry_import_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airport_geometry_import_log (
        import_id           INT IDENTITY(1,1) PRIMARY KEY,
        airport_icao        NVARCHAR(4) NOT NULL,
        import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        source              NVARCHAR(16) NOT NULL,      -- OSM/FAA/MANUAL
        zones_imported      INT NOT NULL DEFAULT 0,
        runways_count       INT NULL,
        taxiways_count      INT NULL,
        parking_count       INT NULL,
        success             BIT NOT NULL DEFAULT 1,
        error_message       NVARCHAR(MAX) NULL,
        osm_query           NVARCHAR(MAX) NULL,
        
        INDEX IX_import_log_airport (airport_icao, import_utc DESC)
    );
    
    PRINT '  ? Created airport_geometry_import_log table';
END
ELSE
    PRINT '  - airport_geometry_import_log already exists';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Schema migration complete';
PRINT '';
PRINT '  Tables created/modified:';
PRINT '    ? airport_geometry - OSM zone polygons';
PRINT '    ? adl_zone_events - Zone transition history';
PRINT '    ? airport_geometry_import_log - Import tracking';
PRINT '    ? adl_flight_core - Zone tracking columns';
PRINT '    ? adl_flight_times - Extended zone time columns';
PRINT '==========================================================================';
GO
