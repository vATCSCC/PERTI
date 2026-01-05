-- Migration: SUA Definitions Table (SQL Server)
-- Creates table for storing SUA/TFR definition metadata
-- This complements the GeoJSON file with structured queryable data

CREATE TABLE sua_definitions (
    id INT IDENTITY(1,1) PRIMARY KEY,

    -- Identification
    sua_id NVARCHAR(64) NOT NULL UNIQUE,      -- Unique identifier (e.g., "R2301A", "MOA_WHISKEY_DELTA")

    -- Classification
    sua_group NVARCHAR(32) NOT NULL,           -- REGULATORY, MILITARY, ROUTES, SPECIAL, DC_AREA, SURFACE_OPS, AWACS, OTHER
    sua_type NVARCHAR(32) NOT NULL,            -- P, R, W, A, MOA, etc. (see type reference below)
    sua_subtype NVARCHAR(32) NULL,             -- For types with subtypes (TFR, AR, DZ, SS)

    -- Display Information
    name NVARCHAR(255) NOT NULL,               -- Primary display name
    area_name NVARCHAR(255) NULL,              -- Parent area name (e.g., "WHISKEY MOA COMPLEX")
    area_reference NVARCHAR(128) NULL,         -- Reference designator if part of aggregate

    -- Altitudes
    floor_alt NVARCHAR(32) NULL,               -- Lower altitude (e.g., "GND", "FL180", "3000 MSL")
    ceiling_alt NVARCHAR(32) NULL,             -- Upper altitude (e.g., "FL350", "UNLTD", "17999 MSL")

    -- Render Properties
    geometry_type NVARCHAR(16) NOT NULL,       -- 'polygon' or 'line'
    border_style NVARCHAR(16) NOT NULL DEFAULT 'solid',  -- 'solid' or 'dashed'
    color NVARCHAR(32) NULL,                   -- Display color (hex or name)

    -- Location
    artcc NVARCHAR(8) NULL,                    -- Controlling ARTCC
    state NVARCHAR(64) NULL,                   -- State(s) if applicable

    -- Metadata
    source NVARCHAR(64) NULL,                  -- Data source (FAA, custom, etc.)
    effective_date DATE NULL,                  -- When definition became effective
    expiration_date DATE NULL,                 -- When definition expires (for TFRs)

    -- Status
    is_active BIT NOT NULL DEFAULT 1,          -- Whether definition is currently valid

    -- Timestamps
    created_at DATETIME2 DEFAULT GETUTCDATE(),
    updated_at DATETIME2 DEFAULT GETUTCDATE()
);

-- Indexes for common queries
CREATE INDEX IX_sua_definitions_group ON sua_definitions (sua_group);
CREATE INDEX IX_sua_definitions_type ON sua_definitions (sua_type);
CREATE INDEX IX_sua_definitions_artcc ON sua_definitions (artcc);
CREATE INDEX IX_sua_definitions_active ON sua_definitions (is_active);
CREATE INDEX IX_sua_definitions_area ON sua_definitions (area_name);

-- SUA Group Reference:
-- REGULATORY   = FAA regulatory airspace (P, R, W, A, NSA)
-- MILITARY     = Military operations (MOA, ATCAA, ALTRV, etc.)
-- ROUTES       = Air routes and tracks (AR, IR, VR, SR, MTR)
-- SPECIAL      = Special use (TFR, DZ, SPACE, SS, CARF, TSA)
-- DC_AREA      = DC metropolitan special airspace (SFRA, FRZ, ADIZ)
-- SURFACE_OPS  = Surface operations (SURFACE_GRID)
-- AWACS        = AWACS boundaries and areas
-- OTHER        = Unclassified

-- SUA Type Reference:
-- P        = Prohibited Area
-- R        = Restricted Area
-- W        = Warning Area
-- A        = Alert Area
-- MOA      = Military Operations Area
-- NSA      = National Security Area
-- ATCAA    = ATC Assigned Airspace
-- ALTRV    = Altitude Reservation
-- AR       = Air Refueling Route/Track/Anchor
-- IR       = IFR Military Training Route
-- VR       = VFR Military Training Route
-- SR       = Slow Route
-- MTR      = Military Training Route (generic)
-- TFR      = Temporary Flight Restriction
-- DZ       = Drop Zone / Parachute Jump Area
-- SPACE    = Space Launch/Reentry Operations
-- SS       = Supersonic Corridor
-- CARF     = Central Altitude Reservation Function
-- TSA      = Temporary Segregated Area
-- SFRA     = Special Flight Rules Area
-- FRZ      = Flight Restricted Zone
-- ADIZ     = Air Defense Identification Zone
-- AWACS    = AWACS Operations Area
-- ANG      = Air National Guard
-- SURFACE_GRID = Surface Operations Grid
-- NMS      = National Marine Sanctuary
-- WSRP     = Weather Service Radar Protection
-- OTHER    = Unclassified
