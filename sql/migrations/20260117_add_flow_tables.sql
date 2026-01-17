-- ============================================================================
-- VATSIM_TMI: External Flow Management Integration Tables
-- Migration: 20260117_add_flow_tables
--
-- Purpose: Provider-agnostic schema for external flow management systems
--          (ECFMP, NavCanada, VATPAC, etc.)
--
-- Aligns with:
--   - FAA TFMS terminology (MIT, MINIT, MDI, GS, AFP)
--   - FIXM 4.3.0 field naming conventions
--   - Existing VATSIM_TMI patterns (tmi_entries, tmi_programs)
-- ============================================================================

-- ============================================================================
-- Table: tmi_flow_providers
-- Registry of external flow management data sources
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_flow_providers') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_flow_providers (
        provider_id         INT IDENTITY(1,1) PRIMARY KEY,
        provider_guid       UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,

        -- Provider identification
        provider_code       NVARCHAR(16) NOT NULL,              -- ECFMP, NAVCAN, VATPAC
        provider_name       NVARCHAR(64) NOT NULL,              -- Display name

        -- Integration settings
        api_base_url        NVARCHAR(256) NULL,                 -- e.g., https://ecfmp.vatsim.net/api/v1
        api_version         NVARCHAR(16) NULL,                  -- e.g., v1
        auth_type           NVARCHAR(16) DEFAULT 'NONE',        -- NONE, API_KEY, OAUTH
        auth_config_json    NVARCHAR(MAX) NULL,                 -- Encrypted auth configuration

        -- Regional coverage (FIXM: flightInformationRegion)
        region_codes_json   NVARCHAR(MAX) NULL,                 -- JSON: ["EUR", "NAT", "NAM"]
        fir_codes_json      NVARCHAR(MAX) NULL,                 -- JSON: ["EGTT", "EGPX", "CZQX"]

        -- Sync configuration
        sync_interval_sec   INT DEFAULT 300,                    -- Sync every 5 minutes
        sync_enabled        BIT DEFAULT 1,
        last_sync_utc       DATETIME2(0) NULL,
        last_sync_status    NVARCHAR(16) NULL,                  -- SUCCESS, FAILED, PARTIAL
        last_sync_message   NVARCHAR(256) NULL,

        -- Status
        is_active           BIT DEFAULT 1,
        priority            TINYINT DEFAULT 50,                 -- For conflict resolution (lower = higher priority)

        -- Audit
        created_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        CONSTRAINT UQ_flow_providers_code UNIQUE (provider_code)
    );

    PRINT 'Created table: dbo.tmi_flow_providers';
END
GO

-- Seed initial providers
IF NOT EXISTS (SELECT 1 FROM dbo.tmi_flow_providers WHERE provider_code = 'VATCSCC')
BEGIN
    INSERT INTO dbo.tmi_flow_providers (provider_code, provider_name, region_codes_json, fir_codes_json, priority)
    VALUES
        ('VATCSCC', 'VATSIM Command Center (USA)', '["NAM"]', '["KZAB","KZAU","KZBW","KZDC","KZDV","KZFW","KZHU","KZID","KZJX","KZKC","KZLA","KZLC","KZMA","KZME","KZMP","KZNY","KZOA","KZOB","KZSE","KZTL"]', 10);
END

IF NOT EXISTS (SELECT 1 FROM dbo.tmi_flow_providers WHERE provider_code = 'ECFMP')
BEGIN
    INSERT INTO dbo.tmi_flow_providers (provider_code, provider_name, api_base_url, api_version, region_codes_json, fir_codes_json, priority)
    VALUES
        ('ECFMP', 'EUROCONTROL Flow Management', 'https://ecfmp.vatsim.net/api/v1', 'v1', '["EUR","NAT"]', NULL, 20);
END
GO

-- ============================================================================
-- Table: tmi_flow_events
-- Special events (CTP, FNO, etc.) from external providers
-- FIXM: Maps to /flight/specialHandling and /atfm/event
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_flow_events') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_flow_events (
        event_id            INT IDENTITY(1,1) PRIMARY KEY,
        event_guid          UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,

        -- Provider reference
        provider_id         INT NOT NULL,
        external_id         NVARCHAR(64) NOT NULL,              -- Provider's event ID

        -- Event identification (FIXM: /flight/specialHandling)
        event_code          NVARCHAR(16) NULL,                  -- CTP2026, FNO2026 (vatcan_code equivalent)
        event_name          NVARCHAR(128) NOT NULL,
        event_type          NVARCHAR(32) DEFAULT 'SPECIAL',     -- SPECIAL, EXERCISE, VIP, EMERGENCY, OTHER

        -- Scope (FIXM: /flight/flightRouting/airspaceConstraint)
        fir_ids_json        NVARCHAR(MAX) NULL,                 -- JSON: ["EGTT", "EGPX"]

        -- Time (FIXM: /base/timeRange)
        start_utc           DATETIME2(0) NOT NULL,
        end_utc             DATETIME2(0) NOT NULL,

        -- TMI exemption flags (TFMS-aligned)
        gs_exempt           BIT DEFAULT 1,                      -- Event flights exempt from Ground Stops
        gdp_priority        BIT DEFAULT 1,                      -- Event flights get priority in GDP slots

        -- Status
        status              NVARCHAR(16) DEFAULT 'SCHEDULED',   -- SCHEDULED, ACTIVE, COMPLETED, CANCELLED
        participant_count   INT DEFAULT 0,

        -- Sync tracking
        synced_at           DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        raw_data_json       NVARCHAR(MAX) NULL,                 -- Original provider response

        -- Audit
        created_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_flow_events_provider FOREIGN KEY (provider_id)
            REFERENCES dbo.tmi_flow_providers(provider_id),
        CONSTRAINT UQ_flow_events_external UNIQUE (provider_id, external_id)
    );

    CREATE INDEX IX_flow_events_status ON dbo.tmi_flow_events(status, start_utc, end_utc);
    CREATE INDEX IX_flow_events_code ON dbo.tmi_flow_events(event_code) WHERE event_code IS NOT NULL;

    PRINT 'Created table: dbo.tmi_flow_events';
END
GO

-- ============================================================================
-- Table: tmi_flow_event_participants
-- Pilots registered for events (pre-filed)
-- FIXM: Maps to /flight/flightIdentification
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_flow_event_participants') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_flow_event_participants (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        event_id            INT NOT NULL,

        -- Pilot identification (FIXM: /flight/flightIdentification)
        pilot_cid           INT NULL,                           -- VATSIM CID
        callsign            NVARCHAR(12) NULL,                  -- Pre-registered callsign

        -- Route (FIXM: /flight/departure/aerodrome, /flight/arrival/aerodrome)
        dep_aerodrome       NCHAR(4) NULL,
        arr_aerodrome       NCHAR(4) NULL,

        -- External reference
        external_id         NVARCHAR(64) NULL,                  -- Provider's participant ID

        -- Flight matching (populated at runtime)
        flight_uid          BIGINT NULL,                        -- FK to adl_flight_core
        matched_at          DATETIME2(0) NULL,

        -- Sync tracking
        synced_at           DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_flow_participants_event FOREIGN KEY (event_id)
            REFERENCES dbo.tmi_flow_events(event_id) ON DELETE CASCADE
    );

    CREATE INDEX IX_flow_participants_cid ON dbo.tmi_flow_event_participants(pilot_cid) WHERE pilot_cid IS NOT NULL;
    CREATE INDEX IX_flow_participants_route ON dbo.tmi_flow_event_participants(dep_aerodrome, arr_aerodrome);
    CREATE INDEX IX_flow_participants_flight ON dbo.tmi_flow_event_participants(flight_uid) WHERE flight_uid IS NOT NULL;

    PRINT 'Created table: dbo.tmi_flow_event_participants';
END
GO

-- ============================================================================
-- Table: tmi_flow_measures
-- Flow measures from external providers (MIT, MINIT, MDI, GS, etc.)
-- TFMS/FIXM-aligned structure
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_flow_measures') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_flow_measures (
        measure_id          INT IDENTITY(1,1) PRIMARY KEY,
        measure_guid        UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,

        -- Provider reference
        provider_id         INT NOT NULL,
        external_id         NVARCHAR(64) NOT NULL,              -- Provider's measure ID

        -- Measure identification (TFMS-aligned)
        ident               NVARCHAR(16) NOT NULL,              -- EGTT22A, CZYZ-MIT-01
        revision            INT DEFAULT 1,

        -- Link to event (optional)
        event_id            INT NULL,                           -- FK to tmi_flow_events

        -- Control element (align with tmi_programs)
        ctl_element         NVARCHAR(8) NULL,                   -- Airport/FIR/Fix
        element_type        NVARCHAR(8) NULL,                   -- APT, FIR, FCA, FIX

        -- Measure type (TFMS/FIXM normalized)
        -- MIT = Miles-In-Trail, MINIT = Minutes-In-Trail, MDI = Minimum Departure Interval
        -- RATE = Departure Rate Cap, GS = Ground Stop, AFP = Airspace Flow Program
        -- REROUTE = Mandatory Reroute, OTHER = Other restriction
        measure_type        NVARCHAR(16) NOT NULL,

        -- Measure value (TFMS-aligned)
        measure_value       INT NULL,                           -- Miles, minutes, seconds, rate
        measure_unit        NVARCHAR(8) NULL,                   -- NM, MIN, SEC, PER_HOUR

        -- Reason (FIXM: /atfm/reason)
        reason              NVARCHAR(256) NULL,

        -- Scope filters (FIXM: /atfm/flowElement)
        -- JSON structure:
        -- {
        --   "adep": ["KJFK", "KEWR"],           -- departureAerodrome
        --   "ades": ["EGLL", "EGKK"],           -- arrivalAerodrome
        --   "adep_fir": ["KZNY"],               -- Departure FIR
        --   "ades_fir": ["EGTT"],               -- Arrival FIR
        --   "waypoints": ["MERIT", "GANDER"],   -- routePoint
        --   "airways": ["NAT-A", "NAT-B"],      -- airway
        --   "levels": {"min": 290, "max": 410}, -- flightLevel
        --   "aircraft_type": ["A320", "B738"],  -- aircraftType
        --   "member_event": [123],              -- Event IDs (ECFMP)
        --   "member_not_event": [456]           -- Exclude event IDs
        -- }
        filters_json        NVARCHAR(MAX) NULL,

        -- Exemptions (FIXM-style)
        -- {
        --   "event_flights": true,
        --   "carriers": ["BAW", "VIR"],
        --   "aircraft_types": ["MEDEVAC"],
        --   "special_handling": ["STATE", "HEAD"]
        -- }
        exemptions_json     NVARCHAR(MAX) NULL,

        -- Mandatory route (FIXM: /flight/routeConstraint)
        mandatory_route_json NVARCHAR(MAX) NULL,                -- JSON array of fixes/airways

        -- Time (FIXM: /base/timeRange) - align with tmi_programs naming
        start_utc           DATETIME2(0) NOT NULL,
        end_utc             DATETIME2(0) NOT NULL,

        -- Status (align with tmi_programs)
        status              NVARCHAR(16) DEFAULT 'ACTIVE',      -- NOTIFIED, ACTIVE, EXPIRED, WITHDRAWN
        withdrawn_at        DATETIME2(0) NULL,

        -- Sync tracking
        synced_at           DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        raw_data_json       NVARCHAR(MAX) NULL,                 -- Original provider response

        -- Audit
        created_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_flow_measures_provider FOREIGN KEY (provider_id)
            REFERENCES dbo.tmi_flow_providers(provider_id),
        CONSTRAINT FK_flow_measures_event FOREIGN KEY (event_id)
            REFERENCES dbo.tmi_flow_events(event_id),
        CONSTRAINT UQ_flow_measures_external UNIQUE (provider_id, external_id)
    );

    CREATE INDEX IX_flow_measures_active ON dbo.tmi_flow_measures(start_utc, end_utc, status)
        WHERE status IN ('NOTIFIED', 'ACTIVE');
    CREATE INDEX IX_flow_measures_ident ON dbo.tmi_flow_measures(ident);
    CREATE INDEX IX_flow_measures_type ON dbo.tmi_flow_measures(measure_type);
    CREATE INDEX IX_flow_measures_event ON dbo.tmi_flow_measures(event_id) WHERE event_id IS NOT NULL;

    PRINT 'Created table: dbo.tmi_flow_measures';
END
GO

-- ============================================================================
-- Views: Active flow data
-- ============================================================================

-- Active flow events
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_tmi_active_flow_events')
    DROP VIEW dbo.vw_tmi_active_flow_events;
GO

CREATE VIEW dbo.vw_tmi_active_flow_events AS
SELECT
    e.event_id,
    e.event_guid,
    p.provider_code,
    p.provider_name,
    e.external_id,
    e.event_code,
    e.event_name,
    e.event_type,
    e.fir_ids_json,
    e.start_utc,
    e.end_utc,
    e.gs_exempt,
    e.gdp_priority,
    e.status,
    e.participant_count,
    e.synced_at,
    e.created_at
FROM dbo.tmi_flow_events e
JOIN dbo.tmi_flow_providers p ON e.provider_id = p.provider_id
WHERE e.status IN ('SCHEDULED', 'ACTIVE')
  AND e.end_utc > SYSUTCDATETIME()
  AND p.is_active = 1;
GO

PRINT 'Created view: dbo.vw_tmi_active_flow_events';
GO

-- Active flow measures
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_tmi_active_flow_measures')
    DROP VIEW dbo.vw_tmi_active_flow_measures;
GO

CREATE VIEW dbo.vw_tmi_active_flow_measures AS
SELECT
    m.measure_id,
    m.measure_guid,
    p.provider_code,
    p.provider_name,
    m.external_id,
    m.ident,
    m.revision,
    e.event_id,
    e.event_code,
    e.event_name,
    m.ctl_element,
    m.element_type,
    m.measure_type,
    m.measure_value,
    m.measure_unit,
    m.reason,
    m.filters_json,
    m.exemptions_json,
    m.mandatory_route_json,
    m.start_utc,
    m.end_utc,
    m.status,
    m.synced_at,
    m.created_at
FROM dbo.tmi_flow_measures m
JOIN dbo.tmi_flow_providers p ON m.provider_id = p.provider_id
LEFT JOIN dbo.tmi_flow_events e ON m.event_id = e.event_id
WHERE m.status IN ('NOTIFIED', 'ACTIVE')
  AND m.end_utc > SYSUTCDATETIME()
  AND p.is_active = 1;
GO

PRINT 'Created view: dbo.vw_tmi_active_flow_measures';
GO

-- ============================================================================
-- Migration complete
-- ============================================================================
PRINT '======================================================================';
PRINT 'Migration 20260117_add_flow_tables completed successfully';
PRINT '======================================================================';
GO
