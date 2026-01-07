-- Migration: SUA/TFR Activations Table (SQL Server)
-- Creates table for scheduling SUA activations and custom TFRs
-- Run this migration to enable SUA/TFR management in PERTI

CREATE TABLE sua_activations (
    id INT IDENTITY(1,1) PRIMARY KEY,
    sua_id NVARCHAR(64) NULL,                   -- Reference to SUA designator (e.g., "R2301A") or NULL for custom TFR
    sua_type NVARCHAR(32) NOT NULL,             -- P, R, W, A, MOA, NSA, ATCAA, IR, VR, SR, AR, OTHER, TFR
    tfr_subtype NVARCHAR(32) NULL,              -- For TFRs: HAZARD, VIP, SECURITY, HAWAII, EMERGENCY, EVENT, PRESSURE, SPACE, MASS_GATHERING, OTHER
    name NVARCHAR(255) NOT NULL,                -- Display name
    artcc NVARCHAR(8) NULL,                     -- Owning ARTCC

    -- Schedule
    start_utc DATETIME2 NOT NULL,
    end_utc DATETIME2 NOT NULL,

    -- Status: SCHEDULED, ACTIVE, EXPIRED, CANCELLED
    status NVARCHAR(16) NOT NULL DEFAULT 'SCHEDULED',

    -- For custom TFRs only
    geometry NVARCHAR(MAX) NULL,                -- GeoJSON geometry for custom TFRs
    lower_alt NVARCHAR(32) NULL,                -- e.g., "GND", "FL180"
    upper_alt NVARCHAR(32) NULL,                -- e.g., "FL350", "UNLTD"

    -- Notes
    remarks NVARCHAR(MAX) NULL,
    notam_number NVARCHAR(64) NULL,             -- Optional NOTAM reference

    -- Audit
    created_by NVARCHAR(64) NOT NULL,
    created_at DATETIME2 DEFAULT GETUTCDATE(),
    updated_at DATETIME2 DEFAULT GETUTCDATE()
);

-- Create indexes
CREATE INDEX IX_sua_activations_status ON sua_activations (status);
CREATE INDEX IX_sua_activations_time ON sua_activations (start_utc, end_utc);
CREATE INDEX IX_sua_activations_sua_id ON sua_activations (sua_id);
CREATE INDEX IX_sua_activations_type ON sua_activations (sua_type);

-- SUA Type Reference:
-- P = Prohibited Area
-- R = Restricted Area
-- W = Warning Area
-- A = Alert Area
-- MOA = Military Operations Area
-- NSA = National Security Area
-- ATCAA = ATC Assigned Airspace
-- IR = Instrument Route
-- VR = Visual Route
-- SR = Slow Route
-- AR = Air Refueling Route
-- OTHER = Unclassified SUA
-- TFR = Temporary Flight Restriction (use tfr_subtype for specific type)

-- TFR Subtype Reference (14 CFR):
-- HAZARD (91.137) = Disasters, wildfires, hurricanes, chemical spills
-- VIP (91.141) = Presidential/Vice Presidential movements
-- SECURITY (99.7) = Security events, military installations
-- HAWAII (91.138) = Hawaii-specific disaster areas
-- EMERGENCY (91.139) = Emergency air traffic rules
-- EVENT (91.145) = Airshows, sporting events, stadium TFRs
-- PRESSURE (91.144) = High barometric pressure
-- SPACE = Space launch/reentry operations
-- MASS_GATHERING = Large public events
-- OTHER = Unclassified TFR
