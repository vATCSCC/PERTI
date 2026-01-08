-- ============================================================================
-- Planned Boundary Crossings Schema
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Core tables for pre-computed flight boundary crossings
-- ============================================================================

-- ============================================================================
-- 1. Custom Airspace Elements Table
-- Stores user-defined airspace elements (volumes, points, lines)
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_airspace_element')
BEGIN
    CREATE TABLE dbo.adl_airspace_element (
        element_id          INT IDENTITY(1,1) PRIMARY KEY,
        element_name        NVARCHAR(64) NOT NULL,
        element_type        VARCHAR(16) NOT NULL,       -- VOLUME, POINT, LINE
        element_subtype     VARCHAR(32) NULL,           -- SECTOR, TRACON, ARTCC, FIX, NAVAID, AIRWAY, STAR, SID, CUSTOM

        -- Reference to existing boundary (if not custom)
        reference_boundary_id INT NULL,
        reference_fix_name    NVARCHAR(64) NULL,
        reference_airway      VARCHAR(8) NULL,

        -- Custom geometry (if not referencing existing)
        geometry            GEOGRAPHY NULL,
        definition_json     NVARCHAR(MAX) NULL,

        -- Point-specific: radius for proximity analysis
        radius_nm           DECIMAL(8,2) NULL,

        -- Altitude constraints
        floor_fl            INT NULL,
        ceiling_fl          INT NULL,

        -- Metadata
        category            NVARCHAR(64) NULL,
        description         NVARCHAR(512) NULL,
        created_by          NVARCHAR(64) NULL,
        created_at          DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        updated_at          DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        is_active           BIT NOT NULL DEFAULT 1,

        CONSTRAINT UQ_element_name UNIQUE (element_name),
        CONSTRAINT CK_element_type CHECK (element_type IN ('VOLUME', 'POINT', 'LINE'))
    );

    PRINT 'Created table: adl_airspace_element';
END
ELSE
    PRINT 'Table adl_airspace_element already exists';
GO

-- Indexes for adl_airspace_element
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_element_type' AND object_id = OBJECT_ID('dbo.adl_airspace_element'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_element_type
    ON dbo.adl_airspace_element(element_type, is_active)
    INCLUDE (element_name, element_subtype);
    PRINT 'Created index: IX_element_type';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_element_category' AND object_id = OBJECT_ID('dbo.adl_airspace_element'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_element_category
    ON dbo.adl_airspace_element(category)
    WHERE category IS NOT NULL;
    PRINT 'Created index: IX_element_category';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_element_ref_boundary' AND object_id = OBJECT_ID('dbo.adl_airspace_element'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_element_ref_boundary
    ON dbo.adl_airspace_element(reference_boundary_id)
    WHERE reference_boundary_id IS NOT NULL;
    PRINT 'Created index: IX_element_ref_boundary';
END
GO

-- Spatial index (only if geometry column has data)
IF NOT EXISTS (SELECT * FROM sys.spatial_indexes WHERE name = 'SIDX_element_geo' AND object_id = OBJECT_ID('dbo.adl_airspace_element'))
BEGIN
    CREATE SPATIAL INDEX SIDX_element_geo
    ON dbo.adl_airspace_element(geometry)
    USING GEOGRAPHY_AUTO_GRID
    WITH (CELLS_PER_OBJECT = 16);
    PRINT 'Created spatial index: SIDX_element_geo';
END
GO

-- ============================================================================
-- 2. Planned Crossings Table
-- Pre-computed boundary crossings for each flight
-- Optimized for fast bulk inserts and time-range queries
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flight_planned_crossings')
BEGIN
    CREATE TABLE dbo.adl_flight_planned_crossings (
        crossing_id         BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,

        -- What is being crossed
        crossing_source     VARCHAR(16) NOT NULL,       -- BOUNDARY, ELEMENT
        boundary_id         INT NULL,
        element_id          INT NULL,

        -- Denormalized for fast queries (avoid joins)
        boundary_code       VARCHAR(50) NULL,
        boundary_type       VARCHAR(20) NULL,

        -- Crossing details
        crossing_type       VARCHAR(8) NOT NULL,        -- ENTRY, EXIT, CROSS, WITHIN
        crossing_order      SMALLINT NOT NULL,

        -- Location/timing
        entry_waypoint_seq  INT NULL,
        exit_waypoint_seq   INT NULL,
        entry_fix_name      NVARCHAR(64) NULL,
        exit_fix_name       NVARCHAR(64) NULL,

        planned_entry_utc   DATETIME2(0) NULL,
        planned_exit_utc    DATETIME2(0) NULL,

        entry_lat           DECIMAL(10,7) NULL,
        entry_lon           DECIMAL(11,7) NULL,

        -- Calculation metadata
        calculated_at       DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        calculation_tier    TINYINT NULL,

        CONSTRAINT CK_crossing_source CHECK (crossing_source IN ('BOUNDARY', 'ELEMENT')),
        CONSTRAINT CK_crossing_type CHECK (crossing_type IN ('ENTRY', 'EXIT', 'CROSS', 'WITHIN'))
    );

    PRINT 'Created table: adl_flight_planned_crossings';
END
ELSE
    PRINT 'Table adl_flight_planned_crossings already exists';
GO

-- Primary query index: by flight
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_flight' AND object_id = OBJECT_ID('dbo.adl_flight_planned_crossings'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_flight
    ON dbo.adl_flight_planned_crossings(flight_uid)
    INCLUDE (boundary_code, boundary_type, planned_entry_utc, crossing_order);
    PRINT 'Created index: IX_crossing_flight';
END
GO

-- Time-based queries: "what flights cross X in next Y minutes"
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_boundary_time' AND object_id = OBJECT_ID('dbo.adl_flight_planned_crossings'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_boundary_time
    ON dbo.adl_flight_planned_crossings(boundary_id, planned_entry_utc)
    INCLUDE (flight_uid, boundary_code, boundary_type, crossing_type)
    WHERE boundary_id IS NOT NULL;
    PRINT 'Created index: IX_crossing_boundary_time';
END
GO

-- Element-based queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_element_time' AND object_id = OBJECT_ID('dbo.adl_flight_planned_crossings'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_element_time
    ON dbo.adl_flight_planned_crossings(element_id, planned_entry_utc)
    INCLUDE (flight_uid, crossing_type)
    WHERE element_id IS NOT NULL;
    PRINT 'Created index: IX_crossing_element_time';
END
GO

-- Workload forecast queries: by type and time
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_type_time' AND object_id = OBJECT_ID('dbo.adl_flight_planned_crossings'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_type_time
    ON dbo.adl_flight_planned_crossings(boundary_type, planned_entry_utc)
    INCLUDE (boundary_code, flight_uid)
    WHERE boundary_type IS NOT NULL;
    PRINT 'Created index: IX_crossing_type_time';
END
GO

-- Cleanup index: by calculated_at for retention
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_crossing_calc_time' AND object_id = OBJECT_ID('dbo.adl_flight_planned_crossings'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_crossing_calc_time
    ON dbo.adl_flight_planned_crossings(calculated_at);
    PRINT 'Created index: IX_crossing_calc_time';
END
GO

-- ============================================================================
-- 3. Region Groups Table
-- Defines priority regions for tiered processing
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_region_group')
BEGIN
    CREATE TABLE dbo.adl_region_group (
        region_id           INT IDENTITY(1,1) PRIMARY KEY,
        region_code         VARCHAR(32) NOT NULL,
        region_name         NVARCHAR(128) NULL,
        mega_polygon        GEOGRAPHY NULL,
        artcc_codes         NVARCHAR(MAX) NULL,         -- JSON array
        created_at          DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT UQ_region_code UNIQUE (region_code)
    );

    PRINT 'Created table: adl_region_group';
END
ELSE
    PRINT 'Table adl_region_group already exists';
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_region_group_members')
BEGIN
    CREATE TABLE dbo.adl_region_group_members (
        region_id           INT NOT NULL,
        boundary_id         INT NOT NULL,
        boundary_code       VARCHAR(50) NULL,           -- Denormalized for fast lookup

        CONSTRAINT PK_region_members PRIMARY KEY (region_id, boundary_id)
    );

    PRINT 'Created table: adl_region_group_members';
END
ELSE
    PRINT 'Table adl_region_group_members already exists';
GO

-- Index for reverse lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_region_member_boundary' AND object_id = OBJECT_ID('dbo.adl_region_group_members'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_region_member_boundary
    ON dbo.adl_region_group_members(boundary_id)
    INCLUDE (region_id, boundary_code);
    PRINT 'Created index: IX_region_member_boundary';
END
GO

-- ============================================================================
-- 4. Regional Airport Lookup Table
-- Fast lookup for departure/arrival region detection
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_region_airports')
BEGIN
    CREATE TABLE dbo.adl_region_airports (
        icao_prefix         VARCHAR(4) NOT NULL,        -- First 1-4 chars of ICAO
        region_id           INT NOT NULL,
        match_type          VARCHAR(8) NOT NULL,        -- PREFIX, EXACT

        CONSTRAINT PK_region_airports PRIMARY KEY (icao_prefix, region_id)
    );

    -- Pre-populate with known prefixes for priority region
    -- Region ID 1 = US/CA/MX/LATAM/CAR
    INSERT INTO dbo.adl_region_airports (icao_prefix, region_id, match_type) VALUES
    -- United States CONUS
    ('K', 1, 'PREFIX'),
    -- US Alaska
    ('PA', 1, 'PREFIX'),
    -- US Hawaii
    ('PH', 1, 'PREFIX'),
    -- US Pacific Territories (Guam, etc.)
    ('PG', 1, 'PREFIX'),
    -- US Pacific Islands
    ('PW', 1, 'PREFIX'),    -- Wake Island
    ('PM', 1, 'PREFIX'),    -- Midway
    -- US Caribbean Territories
    ('TJ', 1, 'PREFIX'),    -- Puerto Rico
    ('TI', 1, 'PREFIX'),    -- US Virgin Islands
    -- Canada
    ('C', 1, 'PREFIX'),
    -- Mexico
    ('MM', 1, 'PREFIX'),
    -- Central America
    ('MG', 1, 'PREFIX'),    -- Guatemala
    ('MH', 1, 'PREFIX'),    -- Honduras
    ('MN', 1, 'PREFIX'),    -- Nicaragua
    ('MR', 1, 'PREFIX'),    -- Costa Rica
    ('MP', 1, 'PREFIX'),    -- Panama
    ('MS', 1, 'PREFIX'),    -- El Salvador
    ('MB', 1, 'PREFIX'),    -- Belize (Turks & Caicos actually)
    -- Caribbean
    ('MK', 1, 'PREFIX'),    -- Jamaica
    ('MU', 1, 'PREFIX'),    -- Cuba
    ('MD', 1, 'PREFIX'),    -- Dominican Republic
    ('MY', 1, 'PREFIX'),    -- Bahamas
    ('MH', 1, 'PREFIX'),    -- Haiti (shares with Honduras - use MY for Bahamas distinction)
    ('TN', 1, 'PREFIX'),    -- Netherlands Antilles (Curacao, Aruba, etc.)
    ('TT', 1, 'PREFIX'),    -- Trinidad & Tobago
    ('TF', 1, 'PREFIX'),    -- French Caribbean (Guadeloupe, Martinique)
    ('TB', 1, 'PREFIX'),    -- Barbados
    ('TL', 1, 'PREFIX'),    -- St Lucia
    ('TA', 1, 'PREFIX'),    -- Antigua
    ('TK', 1, 'PREFIX'),    -- St Kitts
    ('TV', 1, 'PREFIX'),    -- British Virgin Islands
    ('TU', 1, 'PREFIX'),    -- Bahamas (some)
    -- LATAM - Caribbean/Gulf bordering
    ('SK', 1, 'PREFIX'),    -- Colombia
    ('SV', 1, 'PREFIX');    -- Venezuela

    PRINT 'Created and populated table: adl_region_airports';
END
ELSE
    PRINT 'Table adl_region_airports already exists';
GO

PRINT '============================================================================';
PRINT 'Planned Crossings Schema v1.0 - Complete';
PRINT '============================================================================';
GO
