-- ============================================================================
-- Migration 029: Create tmi_flight_list table
-- Purpose: Track flights controlled by GS/GDP programs with GUFI support
-- Date: 2026-01-30
-- ============================================================================
--
-- KEY FEATURES:
--   - GUFI (Global Unique Flight Identifier) tracking
--   - Multi-TMI support: same flight can be controlled by multiple TMIs
--   - Compliance tracking per-flight
--   - EDCT/CTA assignment per program
--   - Dynamic updates as pilots connect to network
--
-- RELATIONSHIPS:
--   - program_id FK to tmi_programs
--   - Unique constraint on (program_id, flight_gufi) - one entry per flight per program
--
-- COMPLIANCE STATUSES:
--   - PENDING: Flight assigned but not yet departed
--   - COMPLIANT: Flight departed within EDCT window
--   - NON_COMPLIANT: Flight departed outside EDCT window
--   - EXEMPT: Flight exempted from TMI (exemption_code populated)
--   - CANCELLED: Flight cancelled or removed from network
--
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 029: Create tmi_flight_list table ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Create the table if it doesn't exist
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_flight_list') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_flight_list (
        list_id               INT IDENTITY(1,1) PRIMARY KEY,
        program_id            INT NOT NULL,

        -- Flight Identification
        flight_gufi           NVARCHAR(64) NOT NULL,        -- Global Unique Flight Identifier
        callsign              NVARCHAR(12) NOT NULL,
        flight_uid            BIGINT NULL,                   -- Link to VATSIM_ADL.flights

        -- Flight Details
        dep_airport           NVARCHAR(4) NOT NULL,
        arr_airport           NVARCHAR(4) NOT NULL,
        aircraft_type         NVARCHAR(8) NULL,
        filed_altitude        INT NULL,

        -- Original Schedule
        original_etd_utc      DATETIME2(0) NULL,            -- Original scheduled departure
        original_eta_utc      DATETIME2(0) NULL,            -- Original estimated arrival

        -- Controlled Times (assigned by TMI)
        edct_utc              DATETIME2(0) NULL,            -- Expected Departure Clearance Time
        cta_utc               DATETIME2(0) NULL,            -- Controlled Time of Arrival
        delay_minutes         INT NULL,                      -- Calculated delay from original ETD

        -- Slot Assignment (for GDP/RBS)
        slot_id               BIGINT NULL,                   -- FK to tmi_slots if assigned
        slot_time_utc         DATETIME2(0) NULL,            -- Slot time (denormalized for queries)

        -- Exemption Tracking
        is_exempt             BIT DEFAULT 0,
        exemption_code        NVARCHAR(32) NULL,            -- CARRIER, MILITARY, MEDEVAC, EQUIPMENT, etc.
        exemption_reason      NVARCHAR(256) NULL,

        -- Compliance Tracking
        compliance_status     NVARCHAR(20) DEFAULT 'PENDING',
        actual_departure_utc  DATETIME2(0) NULL,            -- When pilot actually departed
        actual_arrival_utc    DATETIME2(0) NULL,            -- When pilot actually arrived
        compliance_delta_min  INT NULL,                      -- Minutes early(-) or late(+) from EDCT

        -- Status
        flight_status         NVARCHAR(20) DEFAULT 'SCHEDULED',  -- SCHEDULED, AIRBORNE, ARRIVED, CANCELLED

        -- Audit
        added_at              DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at            DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        added_by              NVARCHAR(64) NULL,

        -- Foreign Key
        CONSTRAINT FK_tmi_flight_list_program FOREIGN KEY (program_id)
            REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE
    );

    PRINT 'Created table: tmi_flight_list';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_flight_list already exists - skipping';
END
GO

-- Unique constraint: one entry per flight per program (allows same flight in multiple TMIs)
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_flight_list')
    AND name = 'UX_tmi_flight_list_program_gufi'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_flight_list_program_gufi
    ON dbo.tmi_flight_list(program_id, flight_gufi);

    PRINT 'Created unique index UX_tmi_flight_list_program_gufi';
END
GO

-- Index for finding all TMIs controlling a specific flight
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_flight_list')
    AND name = 'IX_tmi_flight_list_gufi'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_flight_list_gufi
    ON dbo.tmi_flight_list(flight_gufi);

    PRINT 'Created index IX_tmi_flight_list_gufi';
END
GO

-- Index for flight list queries by program and compliance
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_flight_list')
    AND name = 'IX_tmi_flight_list_program_compliance'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_flight_list_program_compliance
    ON dbo.tmi_flight_list(program_id, compliance_status);

    PRINT 'Created index IX_tmi_flight_list_program_compliance';
END
GO

-- Index for callsign lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_flight_list')
    AND name = 'IX_tmi_flight_list_callsign'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_flight_list_callsign
    ON dbo.tmi_flight_list(callsign);

    PRINT 'Created index IX_tmi_flight_list_callsign';
END
GO

-- Index for EDCT queries
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_flight_list')
    AND name = 'IX_tmi_flight_list_edct'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_flight_list_edct
    ON dbo.tmi_flight_list(edct_utc)
    WHERE edct_utc IS NOT NULL;

    PRINT 'Created index IX_tmi_flight_list_edct';
END
GO

-- Index for slot lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_flight_list')
    AND name = 'IX_tmi_flight_list_slot'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_flight_list_slot
    ON dbo.tmi_flight_list(slot_id)
    WHERE slot_id IS NOT NULL;

    PRINT 'Created index IX_tmi_flight_list_slot';
END
GO

PRINT '';
PRINT '=== Migration 029 completed successfully ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
