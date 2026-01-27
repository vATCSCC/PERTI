-- ============================================================================
-- 021_vnas_integration_schema.sql
-- SWIM_API Database: vNAS (Virtual National Airspace System) Integration Schema
--
-- Purpose: Enable bi-directional data exchange between vNAS (ERAM/STARS) and VATSWIM
--          for track surveillance, automation tags, handoffs, flight strips, D-ATIS,
--          and TBFM-style metering.
--
-- Reference: vNAS_VATSWIM_Integration.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  SWIM Migration 021: vNAS Integration Schema';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Automation Tags (ERAM/STARS)
-- Controller-assigned values for altitude, speed, heading
-- ============================================================================

PRINT '';
PRINT '-- Section 1: Automation Tags --';
GO

-- assigned_altitude_ft - Controller-assigned altitude
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'assigned_altitude_ft')
BEGIN
    ALTER TABLE dbo.swim_flights ADD assigned_altitude_ft INT NULL;
    PRINT '+ Added assigned_altitude_ft (vNAS: automation.assigned_altitude)';
END
ELSE PRINT '= assigned_altitude_ft already exists';
GO

-- interim_altitude_ft - ERAM interim altitude (temporary descent/climb)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'interim_altitude_ft')
BEGIN
    ALTER TABLE dbo.swim_flights ADD interim_altitude_ft INT NULL;
    PRINT '+ Added interim_altitude_ft (ERAM-specific interim altitude)';
END
ELSE PRINT '= interim_altitude_ft already exists';
GO

-- assigned_speed_kts - Controller-assigned airspeed (IAS)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'assigned_speed_kts')
BEGIN
    ALTER TABLE dbo.swim_flights ADD assigned_speed_kts INT NULL;
    PRINT '+ Added assigned_speed_kts (vNAS: automation.assigned_speed)';
END
ELSE PRINT '= assigned_speed_kts already exists';
GO

-- assigned_heading_deg - Controller-assigned heading (magnetic)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'assigned_heading_deg')
BEGIN
    ALTER TABLE dbo.swim_flights ADD assigned_heading_deg INT NULL;
    PRINT '+ Added assigned_heading_deg (vNAS: automation.assigned_heading)';
END
ELSE PRINT '= assigned_heading_deg already exists';
GO

-- assigned_mach - Controller-assigned mach number (for high altitude)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'assigned_mach')
BEGIN
    ALTER TABLE dbo.swim_flights ADD assigned_mach DECIMAL(3,2) NULL;
    PRINT '+ Added assigned_mach (vNAS: automation.assigned_mach)';
END
ELSE PRINT '= assigned_mach already exists';
GO

-- ============================================================================
-- SECTION 2: Scratchpad and Coordination Status
-- ============================================================================

PRINT '';
PRINT '-- Section 2: Scratchpad and Coordination --';
GO

-- scratchpad - Primary scratchpad field (ERAM: 8 chars, STARS: 3 chars)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scratchpad')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scratchpad NVARCHAR(16) NULL;
    PRINT '+ Added scratchpad (vNAS: automation.scratchpad)';
END
ELSE PRINT '= scratchpad already exists';
GO

-- scratchpad2 - Secondary scratchpad (ERAM-specific)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scratchpad2')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scratchpad2 NVARCHAR(16) NULL;
    PRINT '+ Added scratchpad2 (ERAM: secondary scratchpad)';
END
ELSE PRINT '= scratchpad2 already exists';
GO

-- scratchpad3 - Tertiary scratchpad (ERAM-specific)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scratchpad3')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scratchpad3 NVARCHAR(16) NULL;
    PRINT '+ Added scratchpad3 (ERAM: tertiary scratchpad)';
END
ELSE PRINT '= scratchpad3 already exists';
GO

-- point_out_sector - Sector receiving point-out coordination
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'point_out_sector')
BEGIN
    ALTER TABLE dbo.swim_flights ADD point_out_sector NVARCHAR(16) NULL;
    PRINT '+ Added point_out_sector (vNAS: coordination.point_out)';
END
ELSE PRINT '= point_out_sector already exists';
GO

-- coordination_status - Track coordination state (UNTRACKED, TRACKED, ASSOCIATED, SUSPENDED)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'coordination_status')
BEGIN
    ALTER TABLE dbo.swim_flights ADD coordination_status NVARCHAR(16) NULL;
    PRINT '+ Added coordination_status (vNAS: track.coordination_status)';
END
ELSE PRINT '= coordination_status already exists';
GO

-- ============================================================================
-- SECTION 3: Track Quality and Surveillance Data
-- ============================================================================

PRINT '';
PRINT '-- Section 3: Track Quality and Surveillance --';
GO

-- beacon_code - Mode A/3 transponder squawk code
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'beacon_code')
BEGIN
    ALTER TABLE dbo.swim_flights ADD beacon_code CHAR(4) NULL;
    PRINT '+ Added beacon_code (vNAS: track.beacon_code / squawk)';
END
ELSE PRINT '= beacon_code already exists';
GO

-- mode_c_valid - Mode C altitude reporting validity
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'mode_c_valid')
BEGIN
    ALTER TABLE dbo.swim_flights ADD mode_c_valid BIT NULL DEFAULT 0;
    PRINT '+ Added mode_c_valid (vNAS: track.mode_c)';
END
ELSE PRINT '= mode_c_valid already exists';
GO

-- mode_s_valid - Mode S data link validity
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'mode_s_valid')
BEGIN
    ALTER TABLE dbo.swim_flights ADD mode_s_valid BIT NULL DEFAULT 0;
    PRINT '+ Added mode_s_valid (vNAS: track.mode_s)';
END
ELSE PRINT '= mode_s_valid already exists';
GO

-- ads_b_equipped - ADS-B equipped indicator
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'ads_b_equipped')
BEGIN
    ALTER TABLE dbo.swim_flights ADD ads_b_equipped BIT NULL DEFAULT 0;
    PRINT '+ Added ads_b_equipped (vNAS: track.ads_b)';
END
ELSE PRINT '= ads_b_equipped already exists';
GO

-- track_quality - Overall track quality (0-9, higher is better)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'track_quality')
BEGIN
    ALTER TABLE dbo.swim_flights ADD track_quality TINYINT NULL;
    PRINT '+ Added track_quality (vNAS: track.position_quality)';
END
ELSE PRINT '= track_quality already exists';
GO

-- ============================================================================
-- SECTION 4: Handoff State
-- ============================================================================

PRINT '';
PRINT '-- Section 4: Handoff State --';
GO

-- handoff_status - Current handoff status (NONE, INITIATED, ACCEPTED, REJECTED, RECALLED)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'handoff_status')
BEGIN
    ALTER TABLE dbo.swim_flights ADD handoff_status NVARCHAR(16) NULL;
    PRINT '+ Added handoff_status (vNAS: handoff.status)';
END
ELSE PRINT '= handoff_status already exists';
GO

-- controlling_sector - Currently controlling sector (e.g., ZDC_33_CTR)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'controlling_sector')
BEGIN
    ALTER TABLE dbo.swim_flights ADD controlling_sector NVARCHAR(32) NULL;
    PRINT '+ Added controlling_sector (vNAS: handoff.from_sector)';
END
ELSE PRINT '= controlling_sector already exists';
GO

-- next_sector - Next sector receiving handoff
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'next_sector')
BEGIN
    ALTER TABLE dbo.swim_flights ADD next_sector NVARCHAR(32) NULL;
    PRINT '+ Added next_sector (vNAS: handoff.to_sector)';
END
ELSE PRINT '= next_sector already exists';
GO

-- handoff_initiated_utc - When handoff was initiated
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'handoff_initiated_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD handoff_initiated_utc DATETIME2(0) NULL;
    PRINT '+ Added handoff_initiated_utc (vNAS: handoff.initiated_at)';
END
ELSE PRINT '= handoff_initiated_utc already exists';
GO

-- handoff_accepted_utc - When handoff was accepted
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'handoff_accepted_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD handoff_accepted_utc DATETIME2(0) NULL;
    PRINT '+ Added handoff_accepted_utc (vNAS: handoff.accepted_at)';
END
ELSE PRINT '= handoff_accepted_utc already exists';
GO

-- boundary_fix - Fix at sector boundary where handoff occurs
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'boundary_fix')
BEGIN
    ALTER TABLE dbo.swim_flights ADD boundary_fix NVARCHAR(8) NULL;
    PRINT '+ Added boundary_fix (vNAS: handoff.boundary_fix)';
END
ELSE PRINT '= boundary_fix already exists';
GO

-- ============================================================================
-- SECTION 5: Flight Strip Data
-- ============================================================================

PRINT '';
PRINT '-- Section 5: Flight Strip Data --';
GO

-- strip_bay - Strip bay assignment (DEPARTURE, ARRIVAL, ENROUTE, PENDING, etc.)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'strip_bay')
BEGIN
    ALTER TABLE dbo.swim_flights ADD strip_bay NVARCHAR(16) NULL;
    PRINT '+ Added strip_bay (vNAS: strip.strip_bay)';
END
ELSE PRINT '= strip_bay already exists';
GO

-- strip_bay_position - Position within strip bay (sequence number)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'strip_bay_position')
BEGIN
    ALTER TABLE dbo.swim_flights ADD strip_bay_position INT NULL;
    PRINT '+ Added strip_bay_position (vNAS: strip.bay_position)';
END
ELSE PRINT '= strip_bay_position already exists';
GO

-- strip_coordination_time - Coordination time shown on strip
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'strip_coordination_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD strip_coordination_time DATETIME2(0) NULL;
    PRINT '+ Added strip_coordination_time (vNAS: strip.coordination_time)';
END
ELSE PRINT '= strip_coordination_time already exists';
GO

-- strip_coordination_fix - Fix associated with coordination
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'strip_coordination_fix')
BEGIN
    ALTER TABLE dbo.swim_flights ADD strip_coordination_fix NVARCHAR(8) NULL;
    PRINT '+ Added strip_coordination_fix (vNAS: strip.coordination_fix)';
END
ELSE PRINT '= strip_coordination_fix already exists';
GO

-- strip_annotations - Controller annotations on strip
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'strip_annotations')
BEGIN
    ALTER TABLE dbo.swim_flights ADD strip_annotations NVARCHAR(256) NULL;
    PRINT '+ Added strip_annotations (vNAS: strip.annotations)';
END
ELSE PRINT '= strip_annotations already exists';
GO

-- ============================================================================
-- SECTION 6: Alert Status (ERAM/STARS conflict detection)
-- ============================================================================

PRINT '';
PRINT '-- Section 6: Alert Status --';
GO

-- conflict_alert - Conflict alert active (CA)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'conflict_alert')
BEGIN
    ALTER TABLE dbo.swim_flights ADD conflict_alert BIT NULL DEFAULT 0;
    PRINT '+ Added conflict_alert (vNAS: alerts.conflict_alert)';
END
ELSE PRINT '= conflict_alert already exists';
GO

-- msaw_alert - Minimum Safe Altitude Warning active
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'msaw_alert')
BEGIN
    ALTER TABLE dbo.swim_flights ADD msaw_alert BIT NULL DEFAULT 0;
    PRINT '+ Added msaw_alert (vNAS: alerts.msaw_alert)';
END
ELSE PRINT '= msaw_alert already exists';
GO

-- ca_alert - Conflict Alert active (legacy naming)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'ca_alert')
BEGIN
    ALTER TABLE dbo.swim_flights ADD ca_alert BIT NULL DEFAULT 0;
    PRINT '+ Added ca_alert (vNAS: alerts.ca_alert)';
END
ELSE PRINT '= ca_alert already exists';
GO

-- ============================================================================
-- SECTION 7: vNAS Sync Tracking
-- ============================================================================

PRINT '';
PRINT '-- Section 7: vNAS Sync Tracking --';
GO

-- vnas_source_facility - Source facility (e.g., ZDC, ZNY, N90)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'vnas_source_facility')
BEGIN
    ALTER TABLE dbo.swim_flights ADD vnas_source_facility NVARCHAR(8) NULL;
    PRINT '+ Added vnas_source_facility (vNAS: facility_id)';
END
ELSE PRINT '= vnas_source_facility already exists';
GO

-- vnas_source_system - Source system type (ERAM, STARS)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'vnas_source_system')
BEGIN
    ALTER TABLE dbo.swim_flights ADD vnas_source_system NVARCHAR(8) NULL;
    PRINT '+ Added vnas_source_system (vNAS: system_type)';
END
ELSE PRINT '= vnas_source_system already exists';
GO

-- vnas_sync_utc - Last vNAS sync timestamp
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'vnas_sync_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD vnas_sync_utc DATETIME2(0) NULL;
    PRINT '+ Added vnas_sync_utc (Last vNAS API sync time)';
END
ELSE PRINT '= vnas_sync_utc already exists';
GO

-- ============================================================================
-- SECTION 8: New Tables
-- ============================================================================

PRINT '';
PRINT '-- Section 8: New Tables --';
GO

-- ============================================================================
-- Table: swim_handoff_log - Handoff transition history
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.swim_handoff_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_handoff_log (
        handoff_id          BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,
        gufi                NVARCHAR(64) NOT NULL,
        callsign            NVARCHAR(16) NOT NULL,

        -- Handoff details
        handoff_type        NVARCHAR(16) NOT NULL,  -- AUTOMATED, MANUAL, POINT_OUT
        from_facility       NVARCHAR(8) NOT NULL,
        from_sector         NVARCHAR(32) NOT NULL,
        to_facility         NVARCHAR(8) NOT NULL,
        to_sector           NVARCHAR(32) NOT NULL,
        boundary_fix        NVARCHAR(8) NULL,

        -- Status and times
        status              NVARCHAR(16) NOT NULL,  -- INITIATED, ACCEPTED, REJECTED, RECALLED
        initiated_utc       DATETIME2(0) NOT NULL,
        accepted_utc        DATETIME2(0) NULL,
        completed_utc       DATETIME2(0) NULL,

        -- Metadata
        source_system       NVARCHAR(8) NULL,       -- ERAM, STARS
        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),

        INDEX IX_handoff_flight (flight_uid, initiated_utc),
        INDEX IX_handoff_facility (from_facility, to_facility, initiated_utc),
        INDEX IX_handoff_callsign (callsign, initiated_utc DESC)
    );
    PRINT '+ Created table swim_handoff_log';
END
ELSE PRINT '= Table swim_handoff_log already exists';
GO

-- ============================================================================
-- Table: swim_atis_correlation - D-ATIS snapshots with runway configs
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.swim_atis_correlation') AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_atis_correlation (
        atis_id             INT IDENTITY(1,1) PRIMARY KEY,
        airport             CHAR(4) NOT NULL,
        atis_code           CHAR(1) NOT NULL,
        effective_utc       DATETIME2(0) NOT NULL,
        expires_utc         DATETIME2(0) NULL,

        -- Runway configuration
        dep_runways         NVARCHAR(64) NULL,      -- JSON array: ["31L", "31R"]
        arr_runways         NVARCHAR(64) NULL,      -- JSON array: ["31L", "22L"]

        -- Weather snapshot
        ceiling_ft          INT NULL,
        visibility_sm       DECIMAL(4,2) NULL,
        wind_direction      INT NULL,
        wind_speed_kts      INT NULL,
        wind_gusts_kts      INT NULL,
        altimeter           DECIMAL(5,2) NULL,

        -- Approaches
        approaches_in_use   NVARCHAR(256) NULL,     -- JSON array: ["ILS 31L", "ILS 22L"]

        -- NOTAMs snapshot
        notams              NVARCHAR(MAX) NULL,     -- JSON array of relevant NOTAMs

        -- Source
        source              NVARCHAR(16) NOT NULL,  -- vnas, vatis, manual
        source_facility     NVARCHAR(8) NULL,

        -- Metadata
        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        is_current          BIT NOT NULL DEFAULT 1,

        INDEX IX_atis_airport (airport, is_current, effective_utc DESC),
        INDEX IX_atis_effective (effective_utc DESC)
    );
    PRINT '+ Created table swim_atis_correlation';
END
ELSE PRINT '= Table swim_atis_correlation already exists';
GO

-- ============================================================================
-- SECTION 9: Indexes for vNAS queries
-- ============================================================================

PRINT '';
PRINT '-- Section 9: Indexes --';
GO

-- Index for sector-based queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_vnas_sector')
BEGIN
    CREATE INDEX IX_swim_flights_vnas_sector
    ON dbo.swim_flights (controlling_sector)
    WHERE is_active = 1;
    PRINT '+ Created index IX_swim_flights_vnas_sector';
END
ELSE PRINT '= Index IX_swim_flights_vnas_sector already exists';
GO

-- Index for handoff queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_handoff')
BEGIN
    CREATE INDEX IX_swim_flights_handoff
    ON dbo.swim_flights (handoff_status)
    WHERE handoff_status IS NOT NULL;
    PRINT '+ Created index IX_swim_flights_handoff';
END
ELSE PRINT '= Index IX_swim_flights_handoff already exists';
GO

-- Index for beacon/squawk lookups
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_beacon')
BEGIN
    CREATE INDEX IX_swim_flights_beacon
    ON dbo.swim_flights (beacon_code)
    WHERE beacon_code IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_beacon';
END
ELSE PRINT '= Index IX_swim_flights_beacon already exists';
GO

-- Index for vNAS sync queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_vnas_sync')
BEGIN
    CREATE INDEX IX_swim_flights_vnas_sync
    ON dbo.swim_flights (vnas_sync_utc)
    WHERE vnas_sync_utc IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_vnas_sync';
END
ELSE PRINT '= Index IX_swim_flights_vnas_sync already exists';
GO

-- Index for facility-based queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_vnas_facility')
BEGIN
    CREATE INDEX IX_swim_flights_vnas_facility
    ON dbo.swim_flights (vnas_source_facility)
    WHERE vnas_source_facility IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_vnas_facility';
END
ELSE PRINT '= Index IX_swim_flights_vnas_facility already exists';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 021 Complete: vNAS Integration Schema';
PRINT '';
PRINT '  Automation Tags:';
PRINT '    assigned_altitude_ft, interim_altitude_ft, assigned_speed_kts,';
PRINT '    assigned_heading_deg, assigned_mach';
PRINT '';
PRINT '  Scratchpad & Coordination:';
PRINT '    scratchpad, scratchpad2, scratchpad3, point_out_sector,';
PRINT '    coordination_status';
PRINT '';
PRINT '  Track Quality:';
PRINT '    beacon_code, mode_c_valid, mode_s_valid, ads_b_equipped, track_quality';
PRINT '';
PRINT '  Handoff State:';
PRINT '    handoff_status, controlling_sector, next_sector,';
PRINT '    handoff_initiated_utc, handoff_accepted_utc, boundary_fix';
PRINT '';
PRINT '  Strip Data:';
PRINT '    strip_bay, strip_bay_position, strip_coordination_time,';
PRINT '    strip_coordination_fix, strip_annotations';
PRINT '';
PRINT '  Alerts:';
PRINT '    conflict_alert, msaw_alert, ca_alert';
PRINT '';
PRINT '  vNAS Sync:';
PRINT '    vnas_source_facility, vnas_source_system, vnas_sync_utc';
PRINT '';
PRINT '  New Tables:';
PRINT '    swim_handoff_log, swim_atis_correlation';
PRINT '==========================================================================';
GO
