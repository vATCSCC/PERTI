-- ============================================================================
-- Migration 026: SWIM Data Isolation
-- ============================================================================
-- Purpose: Full SWIM API data isolation — every SWIM endpoint queries ONLY
--          SWIM_API database. Adds mirror tables, expands swim_flights,
--          rewrites sp_Swim_BulkUpsert with row-hash skip + change feed.
--
-- Database: SWIM_API (Azure SQL)
-- Author: Claude Opus 4.6 + jpeterson
-- Date: 2026-03-14
--
-- Sections:
--   1. ALTER TABLE swim_flights — new columns + row_hash
--   2. DROP unused swim_flights indexes (~14)
--   3. TMI mirror tables (12)
--   4. Flow mirror tables (4)
--   5. CDM mirror tables (4)
--   6. Reference mirror tables (4)
--   7. Infrastructure tables (3)
--   8. Views (11)
--   9. CREATE OR ALTER sp_Swim_BulkUpsert (row-hash + change feed)
--  10. Change feed cleanup SP
--  11. Verification queries
-- ============================================================================

-- ============================================================================
-- SECTION 1: ALTER TABLE swim_flights — New columns + row_hash
-- ============================================================================
-- Part 2 columns: ~34 new columns for flight.php parity
-- Plus row_hash for no-op update elimination

-- Core / Airspace
ALTER TABLE dbo.swim_flights ADD current_zone_airport NVARCHAR(8) NULL;
GO
ALTER TABLE dbo.swim_flights ADD current_sector_low NVARCHAR(16) NULL;
GO
ALTER TABLE dbo.swim_flights ADD current_sector_high NVARCHAR(16) NULL;
GO

-- Weather
ALTER TABLE dbo.swim_flights ADD weather_impact NVARCHAR(64) NULL;
GO
ALTER TABLE dbo.swim_flights ADD weather_alert_ids NVARCHAR(MAX) NULL;
GO

-- Position extended
ALTER TABLE dbo.swim_flights ADD altitude_assigned INT NULL;
GO
ALTER TABLE dbo.swim_flights ADD altitude_cleared INT NULL;
GO
ALTER TABLE dbo.swim_flights ADD track_deg SMALLINT NULL;
GO
ALTER TABLE dbo.swim_flights ADD qnh_in_hg DECIMAL(5,2) NULL;
GO
ALTER TABLE dbo.swim_flights ADD qnh_mb DECIMAL(6,1) NULL;
GO
ALTER TABLE dbo.swim_flights ADD route_dist_to_dest_nm DECIMAL(8,1) NULL;
GO
ALTER TABLE dbo.swim_flights ADD route_pct_complete DECIMAL(5,2) NULL;
GO
ALTER TABLE dbo.swim_flights ADD next_waypoint_name NVARCHAR(16) NULL;
GO
ALTER TABLE dbo.swim_flights ADD dist_to_next_waypoint_nm DECIMAL(8,1) NULL;
GO

-- Plan extended
ALTER TABLE dbo.swim_flights ADD fp_route_expanded NVARCHAR(MAX) NULL;
GO
ALTER TABLE dbo.swim_flights ADD fp_fuel_minutes INT NULL;
GO
ALTER TABLE dbo.swim_flights ADD dtrsn NVARCHAR(32) NULL;
GO
ALTER TABLE dbo.swim_flights ADD strsn NVARCHAR(32) NULL;
GO
ALTER TABLE dbo.swim_flights ADD waypoint_count INT NULL;
GO
ALTER TABLE dbo.swim_flights ADD parse_status NVARCHAR(16) NULL;
GO

-- Times extended
ALTER TABLE dbo.swim_flights ADD sta_utc DATETIME2 NULL;
GO
ALTER TABLE dbo.swim_flights ADD etd_runway_utc DATETIME2 NULL;
GO
ALTER TABLE dbo.swim_flights ADD etd_source NVARCHAR(16) NULL;
GO
ALTER TABLE dbo.swim_flights ADD octa_utc DATETIME2 NULL;
GO
ALTER TABLE dbo.swim_flights ADD ate_minutes DECIMAL(8,1) NULL;
GO
ALTER TABLE dbo.swim_flights ADD eta_confidence NVARCHAR(8) NULL;
GO
ALTER TABLE dbo.swim_flights ADD eta_wind_component_kts SMALLINT NULL;
GO

-- TMI extended
ALTER TABLE dbo.swim_flights ADD ctl_exempt BIT NULL;
GO
ALTER TABLE dbo.swim_flights ADD ctl_exempt_reason NVARCHAR(64) NULL;
GO
ALTER TABLE dbo.swim_flights ADD aslot NVARCHAR(16) NULL;
GO
ALTER TABLE dbo.swim_flights ADD delay_source NVARCHAR(16) NULL;
GO
ALTER TABLE dbo.swim_flights ADD is_popup BIT NULL;
GO
ALTER TABLE dbo.swim_flights ADD popup_detected_utc DATETIME2 NULL;
GO
ALTER TABLE dbo.swim_flights ADD absolute_delay_min INT NULL;
GO
ALTER TABLE dbo.swim_flights ADD schedule_variation_min INT NULL;
GO

-- Aircraft extended
ALTER TABLE dbo.swim_flights ADD cruise_tas_kts SMALLINT NULL;
GO
ALTER TABLE dbo.swim_flights ADD ceiling_ft INT NULL;
GO

-- Row hash for no-op update elimination
ALTER TABLE dbo.swim_flights ADD row_hash BINARY(20) NULL;
GO

-- ============================================================================
-- SECTION 2: DROP unused swim_flights indexes (~14)
-- ============================================================================
-- These indexes have low or zero usage and add write amplification.
-- Can be re-added individually if specific query patterns emerge.

-- VNAS indexes (5) — VNAS integration not yet active
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_vnas_sector' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_vnas_sector ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_handoff' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_handoff ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_beacon' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_beacon ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_vnas_sync' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_vnas_sync ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_vnas_facility' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_vnas_facility ON dbo.swim_flights;
GO

-- SimBrief/Flow (3) — niche queries
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_simbrief' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_simbrief ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_flow_event' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_flow_event ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_flow_measure' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_flow_measure ON dbo.swim_flights;
GO

-- Gate indexes (2) — rarely queried via SWIM API
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_departure_gate' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_departure_gate ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_arrival_gate' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_arrival_gate ON dbo.swim_flights;
GO

-- Procedure duplicates (2) — served by dept/dest covering indexes
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_sid' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_sid ON dbo.swim_flights;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_star' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_star ON dbo.swim_flights;
GO

-- Strata (1) — niche sector analysis
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_strata' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_strata ON dbo.swim_flights;
GO

-- SimTraffic sync (1) — internal sync tracking only
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_flights_simtraffic_sync' AND object_id = OBJECT_ID('dbo.swim_flights'))
    DROP INDEX IX_swim_flights_simtraffic_sync ON dbo.swim_flights;
GO

-- ============================================================================
-- SECTION 3: TMI Mirror Tables (from VATSIM_TMI + VATSIM_ADL)
-- ============================================================================
-- Design: No IDENTITY PKs (explicit insert from source), no FKs,
--         minimal indexes, synced_utc for sync tracking.

-- 3A: swim_ntml (from VATSIM_ADL.dbo.ntml — 53 cols)
-- Note: program_name is computed in source; stored as regular NVARCHAR here
IF OBJECT_ID('dbo.swim_ntml', 'U') IS NULL
CREATE TABLE dbo.swim_ntml (
    program_id          INT             NOT NULL PRIMARY KEY,
    program_guid        UNIQUEIDENTIFIER NULL,
    ctl_element         NVARCHAR(8)     NULL,
    element_type        NVARCHAR(16)    NULL,
    program_type        NVARCHAR(8)     NULL,
    program_name        NVARCHAR(32)    NULL,  -- computed in source, stored here
    adv_number          INT             NULL,
    start_utc           DATETIME2       NULL,
    end_utc             DATETIME2       NULL,
    cumulative_start    DATETIME2       NULL,
    cumulative_end      DATETIME2       NULL,
    model_time_utc      DATETIME2       NULL,
    status              NVARCHAR(16)    NULL,
    is_proposed         BIT             NULL,
    is_active           BIT             NULL,
    program_rate        INT             NULL,
    reserve_rate        INT             NULL,
    delay_limit_min     INT             NULL,
    target_delay_mult   DECIMAL(5,2)    NULL,
    rates_hourly_json   NVARCHAR(MAX)   NULL,
    reserve_hourly_json NVARCHAR(MAX)   NULL,
    scope_type          NVARCHAR(16)    NULL,
    scope_tier          INT             NULL,
    scope_distance_nm   INT             NULL,
    scope_json          NVARCHAR(MAX)   NULL,
    exemptions_json     NVARCHAR(MAX)   NULL,
    exempt_airborne     BIT             NULL,
    exempt_within_min   INT             NULL,
    flt_incl_carrier    NVARCHAR(MAX)   NULL,
    flt_incl_type       NVARCHAR(MAX)   NULL,
    flt_incl_fix        NVARCHAR(MAX)   NULL,
    impacting_condition NVARCHAR(128)   NULL,
    cause_text          NVARCHAR(256)   NULL,
    comments            NVARCHAR(MAX)   NULL,
    prob_extension      INT             NULL,
    revision_number     INT             NULL,
    parent_program_id   INT             NULL,
    successor_program_id INT            NULL,
    total_flights       INT             NULL,
    controlled_flights  INT             NULL,
    exempt_flights      INT             NULL,
    airborne_flights    INT             NULL,
    avg_delay_min       DECIMAL(8,1)    NULL,
    max_delay_min       INT             NULL,
    total_delay_min     INT             NULL,
    created_by          NVARCHAR(32)    NULL,
    created_utc         DATETIME2       NULL,
    modified_by         NVARCHAR(32)    NULL,
    modified_utc        DATETIME2       NULL,
    activated_utc       DATETIME2       NULL,
    activated_by        NVARCHAR(32)    NULL,
    purged_utc          DATETIME2       NULL,
    purged_by           NVARCHAR(32)    NULL,
    is_archived         BIT             DEFAULT 0,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME()
);
GO

-- 3B: swim_ntml_info (from VATSIM_ADL.dbo.ntml_info — 9 cols)
IF OBJECT_ID('dbo.swim_ntml_info', 'U') IS NULL
CREATE TABLE dbo.swim_ntml_info (
    event_id            BIGINT          NOT NULL PRIMARY KEY,
    program_id          INT             NOT NULL,
    flight_uid          BIGINT          NULL,
    slot_id             BIGINT          NULL,
    event_type          NVARCHAR(32)    NULL,
    event_subtype       NVARCHAR(32)    NULL,
    event_details_json  NVARCHAR(MAX)   NULL,
    event_message       NVARCHAR(MAX)   NULL,
    performed_by        NVARCHAR(32)    NULL,
    performed_utc       DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_ntml_info_program (program_id)
);
GO

-- 3C: swim_ntml_slots (from VATSIM_ADL.dbo.ntml_slots — 25 cols)
IF OBJECT_ID('dbo.swim_ntml_slots', 'U') IS NULL
CREATE TABLE dbo.swim_ntml_slots (
    slot_id             BIGINT          NOT NULL PRIMARY KEY,
    program_id          INT             NOT NULL,
    slot_name           NVARCHAR(16)    NULL,
    slot_index          INT             NULL,
    slot_time_utc       DATETIME2       NULL,
    slot_type           NVARCHAR(16)    NULL,
    slot_status         NVARCHAR(16)    NULL,
    bin_hour            SMALLINT        NULL,
    bin_quarter         SMALLINT        NULL,
    assigned_flight_uid BIGINT          NULL,
    assigned_callsign   NVARCHAR(16)    NULL,
    assigned_carrier    NVARCHAR(8)     NULL,
    assigned_origin     NVARCHAR(8)     NULL,
    assigned_utc        DATETIME2       NULL,
    sl_hold             BIT             NULL,
    sl_hold_carrier     NVARCHAR(8)     NULL,
    subbable            BIT             NULL,
    bridge_from_slot    BIGINT          NULL,
    bridge_to_slot      BIGINT          NULL,
    created_utc         DATETIME2       NULL,
    modified_utc        DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_ntml_slots_program (program_id),
    INDEX IX_swim_ntml_slots_time (slot_time_utc)
);
GO

-- 3D: swim_tmi_programs (from VATSIM_TMI.dbo.tmi_programs — 46 cols)
IF OBJECT_ID('dbo.swim_tmi_programs', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_programs (
    program_id          INT             NOT NULL PRIMARY KEY,
    program_guid        UNIQUEIDENTIFIER NULL,
    ctl_element         NVARCHAR(8)     NULL,
    element_type        NVARCHAR(16)    NULL,
    program_type        NVARCHAR(8)     NULL,
    program_name        NVARCHAR(64)    NULL,
    adv_number          INT             NULL,
    start_utc           DATETIME2       NULL,
    end_utc             DATETIME2       NULL,
    cumulative_start    DATETIME2       NULL,
    cumulative_end      DATETIME2       NULL,
    status              NVARCHAR(16)    NULL,
    is_proposed         BIT             NULL,
    is_active           BIT             NULL,
    program_rate        INT             NULL,
    reserve_rate        INT             NULL,
    delay_limit_min     INT             NULL,
    target_delay_mult   DECIMAL(5,2)    NULL,
    rates_hourly_json   NVARCHAR(MAX)   NULL,
    reserve_hourly_json NVARCHAR(MAX)   NULL,
    scope_json          NVARCHAR(MAX)   NULL,
    exemptions_json     NVARCHAR(MAX)   NULL,
    arrival_fix_filter  NVARCHAR(256)   NULL,
    aircraft_type_filter NVARCHAR(256)  NULL,
    carrier_filter      NVARCHAR(256)   NULL,
    impacting_condition NVARCHAR(128)   NULL,
    cause_text          NVARCHAR(256)   NULL,
    comments            NVARCHAR(MAX)   NULL,
    revision_number     INT             NULL,
    parent_program_id   INT             NULL,
    total_flights       INT             NULL,
    controlled_flights  INT             NULL,
    exempt_flights      INT             NULL,
    avg_delay_min       DECIMAL(8,1)    NULL,
    max_delay_min       INT             NULL,
    total_delay_min     INT             NULL,
    subs_enabled        BIT             NULL,
    adaptive_compression BIT            NULL,
    source_type         NVARCHAR(16)    NULL,
    source_id           NVARCHAR(64)    NULL,
    discord_message_id  NVARCHAR(32)    NULL,
    discord_channel_id  NVARCHAR(32)    NULL,
    created_by          NVARCHAR(32)    NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    activated_by        NVARCHAR(32)    NULL,
    activated_at        DATETIME2       NULL,
    purged_by           NVARCHAR(32)    NULL,
    purged_at           DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME()
);
GO

-- 3E: swim_tmi_entries (from VATSIM_TMI.dbo.tmi_entries — 32 cols)
IF OBJECT_ID('dbo.swim_tmi_entries', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_entries (
    entry_id            INT             NOT NULL PRIMARY KEY,
    entry_guid          UNIQUEIDENTIFIER NULL,
    determinant_code    NVARCHAR(16)    NULL,
    protocol_type       NVARCHAR(16)    NULL,
    entry_type          NVARCHAR(16)    NULL,
    ctl_element         NVARCHAR(8)     NULL,
    element_type        NVARCHAR(16)    NULL,
    requesting_facility NVARCHAR(8)     NULL,
    providing_facility  NVARCHAR(8)     NULL,
    restriction_value   NVARCHAR(32)    NULL,
    restriction_unit    NVARCHAR(16)    NULL,
    condition_text      NVARCHAR(MAX)   NULL,
    qualifiers          NVARCHAR(MAX)   NULL,
    exclusions          NVARCHAR(MAX)   NULL,
    reason_code         NVARCHAR(16)    NULL,
    reason_detail       NVARCHAR(256)   NULL,
    valid_from          DATETIME2       NULL,
    valid_until         DATETIME2       NULL,
    status              NVARCHAR(16)    NULL,
    source_type         NVARCHAR(16)    NULL,
    source_id           NVARCHAR(64)    NULL,
    source_channel      NVARCHAR(32)    NULL,
    discord_message_id  NVARCHAR(32)    NULL,
    discord_posted_at   DATETIME2       NULL,
    discord_channel_id  NVARCHAR(32)    NULL,
    raw_input           NVARCHAR(MAX)   NULL,
    parsed_data         NVARCHAR(MAX)   NULL,
    created_by          NVARCHAR(32)    NULL,
    created_by_name     NVARCHAR(64)    NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    cancelled_by        NVARCHAR(32)    NULL,
    cancelled_at        DATETIME2       NULL,
    cancel_reason       NVARCHAR(256)   NULL,
    content_hash        NVARCHAR(64)    NULL,
    supersedes_entry_id INT             NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_entries_status (status, valid_until)
);
GO

-- 3F: swim_tmi_advisories (from VATSIM_TMI.dbo.tmi_advisories — 40 cols)
IF OBJECT_ID('dbo.swim_tmi_advisories', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_advisories (
    advisory_id         INT             NOT NULL PRIMARY KEY,
    advisory_guid       UNIQUEIDENTIFIER NULL,
    advisory_number     INT             NULL,
    advisory_type       NVARCHAR(16)    NULL,
    ctl_element         NVARCHAR(8)     NULL,
    element_type        NVARCHAR(16)    NULL,
    scope_facilities    NVARCHAR(256)   NULL,
    program_id          INT             NULL,
    program_rate        INT             NULL,
    delay_cap           INT             NULL,
    effective_from      DATETIME2       NULL,
    effective_until     DATETIME2       NULL,
    subject             NVARCHAR(256)   NULL,
    body_text           NVARCHAR(MAX)   NULL,
    reason_code         NVARCHAR(16)    NULL,
    reason_detail       NVARCHAR(256)   NULL,
    reroute_id          INT             NULL,
    reroute_name        NVARCHAR(64)    NULL,
    reroute_area        NVARCHAR(128)   NULL,
    reroute_string      NVARCHAR(MAX)   NULL,
    reroute_from        NVARCHAR(64)    NULL,
    reroute_to          NVARCHAR(64)    NULL,
    mit_miles           INT             NULL,
    mit_type            NVARCHAR(16)    NULL,
    mit_fix             NVARCHAR(8)     NULL,
    status              NVARCHAR(16)    NULL,
    is_proposed         BIT             NULL,
    source_type         NVARCHAR(16)    NULL,
    source_id           NVARCHAR(64)    NULL,
    discord_message_id  NVARCHAR(32)    NULL,
    discord_posted_at   DATETIME2       NULL,
    discord_channel_id  NVARCHAR(32)    NULL,
    created_by          NVARCHAR(32)    NULL,
    created_by_name     NVARCHAR(64)    NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    approved_by         NVARCHAR(32)    NULL,
    approved_at         DATETIME2       NULL,
    cancelled_by        NVARCHAR(32)    NULL,
    cancelled_at        DATETIME2       NULL,
    cancel_reason       NVARCHAR(256)   NULL,
    revision_number     INT             NULL,
    supersedes_advisory_id INT          NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_advisories_status (status, effective_until)
);
GO

-- 3G: swim_tmi_reroutes (from VATSIM_TMI.dbo.tmi_reroutes — 52 cols)
IF OBJECT_ID('dbo.swim_tmi_reroutes', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_reroutes (
    reroute_id          INT             NOT NULL PRIMARY KEY,
    reroute_guid        UNIQUEIDENTIFIER NULL,
    status              INT             NULL,
    name                NVARCHAR(64)    NULL,
    adv_number          INT             NULL,
    start_utc           DATETIME2       NULL,
    end_utc             DATETIME2       NULL,
    time_basis          NVARCHAR(16)    NULL,
    protected_segment   NVARCHAR(256)   NULL,
    protected_fixes     NVARCHAR(MAX)   NULL,
    avoid_fixes         NVARCHAR(MAX)   NULL,
    route_type          NVARCHAR(16)    NULL,
    origin_airports     NVARCHAR(MAX)   NULL,
    origin_tracons      NVARCHAR(MAX)   NULL,
    origin_centers      NVARCHAR(MAX)   NULL,
    dest_airports       NVARCHAR(MAX)   NULL,
    dest_tracons        NVARCHAR(MAX)   NULL,
    dest_centers        NVARCHAR(MAX)   NULL,
    departure_fix       NVARCHAR(16)    NULL,
    arrival_fix         NVARCHAR(16)    NULL,
    thru_centers        NVARCHAR(MAX)   NULL,
    thru_fixes          NVARCHAR(MAX)   NULL,
    use_airway          NVARCHAR(32)    NULL,
    include_ac_cat      NVARCHAR(32)    NULL,
    include_ac_types    NVARCHAR(MAX)   NULL,
    include_carriers    NVARCHAR(MAX)   NULL,
    weight_class        NVARCHAR(16)    NULL,
    altitude_min        INT             NULL,
    altitude_max        INT             NULL,
    rvsm_filter         NVARCHAR(16)    NULL,
    exempt_airports     NVARCHAR(MAX)   NULL,
    exempt_carriers     NVARCHAR(MAX)   NULL,
    exempt_flights      NVARCHAR(MAX)   NULL,
    exempt_active_only  BIT             NULL,
    airborne_filter     NVARCHAR(16)    NULL,
    comments            NVARCHAR(MAX)   NULL,
    impacting_condition NVARCHAR(128)   NULL,
    advisory_text       NVARCHAR(MAX)   NULL,
    color               NVARCHAR(16)    NULL,
    line_weight         INT             NULL,
    line_style          NVARCHAR(16)    NULL,
    route_geojson       NVARCHAR(MAX)   NULL,
    total_assigned      INT             NULL,
    compliant_count     INT             NULL,
    non_compliant_count INT             NULL,
    compliance_rate     DECIMAL(5,2)    NULL,
    source_type         NVARCHAR(16)    NULL,
    source_id           NVARCHAR(64)    NULL,
    discord_message_id  NVARCHAR(32)    NULL,
    discord_channel_id  NVARCHAR(32)    NULL,
    created_by          NVARCHAR(32)    NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    activated_at        DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_reroutes_status (status, end_utc)
);
GO

-- 3H: swim_tmi_reroute_routes (from VATSIM_TMI.dbo.tmi_reroute_routes — 10 cols)
IF OBJECT_ID('dbo.swim_tmi_reroute_routes', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_reroute_routes (
    route_id            INT             NOT NULL PRIMARY KEY,
    reroute_id          INT             NOT NULL,
    origin              NVARCHAR(64)    NULL,
    destination         NVARCHAR(64)    NULL,
    route_string        NVARCHAR(MAX)   NULL,
    sort_order          INT             NULL,
    origin_filter       NVARCHAR(128)   NULL,
    dest_filter         NVARCHAR(128)   NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_reroute_routes_reroute (reroute_id)
);
GO

-- 3I: swim_tmi_reroute_flights (from VATSIM_TMI.dbo.tmi_reroute_flights — 33 cols)
IF OBJECT_ID('dbo.swim_tmi_reroute_flights', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_reroute_flights (
    id                  INT             NOT NULL PRIMARY KEY,
    reroute_id          INT             NOT NULL,
    flight_key          NVARCHAR(64)    NULL,
    callsign            NVARCHAR(16)    NULL,
    flight_uid          BIGINT          NULL,
    dep_icao            NVARCHAR(8)     NULL,
    dest_icao           NVARCHAR(8)     NULL,
    ac_type             NVARCHAR(8)     NULL,
    filed_altitude      INT             NULL,
    route_at_assign     NVARCHAR(MAX)   NULL,
    assigned_route      NVARCHAR(MAX)   NULL,
    current_route       NVARCHAR(MAX)   NULL,
    current_route_utc   DATETIME2       NULL,
    final_route         NVARCHAR(MAX)   NULL,
    last_lat            DECIMAL(10,7)   NULL,
    last_lon            DECIMAL(11,7)   NULL,
    last_altitude       INT             NULL,
    last_position_utc   DATETIME2       NULL,
    compliance_status   NVARCHAR(16)    NULL,
    protected_fixes_crossed NVARCHAR(MAX) NULL,
    avoid_fixes_crossed NVARCHAR(MAX)   NULL,
    compliance_pct      DECIMAL(5,2)    NULL,
    compliance_notes    NVARCHAR(MAX)   NULL,
    assigned_at         DATETIME2       NULL,
    departed_utc        DATETIME2       NULL,
    arrived_utc         DATETIME2       NULL,
    route_distance_orig_nm DECIMAL(8,1) NULL,
    route_distance_new_nm  DECIMAL(8,1) NULL,
    route_delta_nm      DECIMAL(8,1)    NULL,
    ete_original_min    DECIMAL(8,1)    NULL,
    ete_assigned_min    DECIMAL(8,1)    NULL,
    ete_delta_min       DECIMAL(8,1)    NULL,
    manual_status       NVARCHAR(16)    NULL,
    override_by         NVARCHAR(32)    NULL,
    override_utc        DATETIME2       NULL,
    override_reason     NVARCHAR(256)   NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_reroute_flights_reroute (reroute_id),
    INDEX IX_swim_tmi_reroute_flights_flight (flight_uid)
);
GO

-- 3J: swim_tmi_reroute_compliance_log (from VATSIM_TMI — 9 cols)
IF OBJECT_ID('dbo.swim_tmi_reroute_compliance_log', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_reroute_compliance_log (
    log_id              BIGINT          NOT NULL PRIMARY KEY,
    reroute_flight_id   INT             NOT NULL,
    snapshot_utc        DATETIME2       NULL,
    compliance_status   NVARCHAR(16)    NULL,
    compliance_pct      DECIMAL(5,2)    NULL,
    lat                 DECIMAL(10,7)   NULL,
    lon                 DECIMAL(11,7)   NULL,
    altitude            INT             NULL,
    route_string        NVARCHAR(MAX)   NULL,
    fixes_crossed       NVARCHAR(MAX)   NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_compliance_log_flight (reroute_flight_id)
);
GO

-- 3K: swim_tmi_public_routes (from VATSIM_TMI — 25 cols)
IF OBJECT_ID('dbo.swim_tmi_public_routes', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_public_routes (
    route_id            INT             NOT NULL PRIMARY KEY,
    route_guid          UNIQUEIDENTIFIER NULL,
    status              INT             NULL,
    name                NVARCHAR(64)    NULL,
    adv_number          INT             NULL,
    advisory_id         INT             NULL,
    reroute_id          INT             NULL,
    route_string        NVARCHAR(MAX)   NULL,
    advisory_text       NVARCHAR(MAX)   NULL,
    color               NVARCHAR(16)    NULL,
    line_weight         INT             NULL,
    line_style          NVARCHAR(16)    NULL,
    valid_start_utc     DATETIME2       NULL,
    valid_end_utc       DATETIME2       NULL,
    constrained_area    NVARCHAR(128)   NULL,
    reason              NVARCHAR(256)   NULL,
    origin_filter       NVARCHAR(256)   NULL,
    dest_filter         NVARCHAR(256)   NULL,
    facilities          NVARCHAR(256)   NULL,
    route_geojson       NVARCHAR(MAX)   NULL,
    created_by          NVARCHAR(32)    NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_public_routes_status (status, valid_end_utc)
);
GO

-- 3L: swim_tmi_flight_control (from VATSIM_TMI — key subset ~31 cols)
IF OBJECT_ID('dbo.swim_tmi_flight_control', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_flight_control (
    control_id          BIGINT          NOT NULL PRIMARY KEY,
    flight_uid          BIGINT          NOT NULL,
    callsign            NVARCHAR(16)    NULL,
    program_id          INT             NULL,
    slot_id             BIGINT          NULL,
    ctd_utc             DATETIME2       NULL,
    cta_utc             DATETIME2       NULL,
    octd_utc            DATETIME2       NULL,
    octa_utc            DATETIME2       NULL,
    aslot               NVARCHAR(16)    NULL,
    ctl_elem            NVARCHAR(8)     NULL,
    ctl_prgm            NVARCHAR(16)    NULL,
    ctl_type            NVARCHAR(8)     NULL,
    ctl_exempt          BIT             NULL,
    ctl_exempt_reason   NVARCHAR(64)    NULL,
    program_delay_min   INT             NULL,
    delay_capped        BIT             NULL,
    gs_held             BIT             NULL,
    gs_release_utc      DATETIME2       NULL,
    is_popup            BIT             NULL,
    popup_detected_utc  DATETIME2       NULL,
    popup_lead_time_min INT             NULL,
    sl_hold             BIT             NULL,
    subbable            BIT             NULL,
    compliance_status   NVARCHAR(16)    NULL,
    actual_dep_utc      DATETIME2       NULL,
    actual_arr_utc      DATETIME2       NULL,
    compliance_delta_min INT            NULL,
    dep_airport         NVARCHAR(8)     NULL,
    arr_airport         NVARCHAR(8)     NULL,
    is_archived         BIT             NULL,
    created_utc         DATETIME2       NULL,
    modified_utc        DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_tmi_flight_control_flight (flight_uid),
    INDEX IX_swim_tmi_flight_control_program (program_id)
);
GO

-- ============================================================================
-- SECTION 4: Flow Mirror Tables (from VATSIM_TMI)
-- ============================================================================

-- 4A: swim_tmi_flow_providers (19 cols)
IF OBJECT_ID('dbo.swim_tmi_flow_providers', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_flow_providers (
    provider_id         INT             NOT NULL PRIMARY KEY,
    provider_guid       UNIQUEIDENTIFIER NULL,
    provider_code       NVARCHAR(16)    NULL,
    provider_name       NVARCHAR(64)    NULL,
    api_base_url        NVARCHAR(256)   NULL,
    api_version         NVARCHAR(16)    NULL,
    auth_type           NVARCHAR(16)    NULL,
    auth_config_json    NVARCHAR(MAX)   NULL,
    region_codes_json   NVARCHAR(MAX)   NULL,
    fir_codes_json      NVARCHAR(MAX)   NULL,
    sync_interval_sec   INT             NULL,
    sync_enabled        BIT             NULL,
    last_sync_utc       DATETIME2       NULL,
    last_sync_status    NVARCHAR(16)    NULL,
    last_sync_message   NVARCHAR(256)   NULL,
    is_active           BIT             NULL,
    priority            INT             NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME()
);
GO

-- 4B: swim_tmi_flow_events (18 cols)
IF OBJECT_ID('dbo.swim_tmi_flow_events', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_flow_events (
    event_id            INT             NOT NULL PRIMARY KEY,
    event_guid          UNIQUEIDENTIFIER NULL,
    provider_id         INT             NOT NULL,
    external_id         NVARCHAR(64)    NULL,
    event_code          NVARCHAR(32)    NULL,
    event_name          NVARCHAR(128)   NULL,
    event_type          NVARCHAR(16)    NULL,
    fir_ids_json        NVARCHAR(MAX)   NULL,
    start_utc           DATETIME2       NULL,
    end_utc             DATETIME2       NULL,
    gs_exempt           BIT             NULL,
    gdp_priority        INT             NULL,
    status              NVARCHAR(16)    NULL,
    participant_count   INT             NULL,
    synced_at           DATETIME2       NULL,
    raw_data_json       NVARCHAR(MAX)   NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_flow_events_status (status, end_utc),
    INDEX IX_swim_flow_events_provider (provider_id)
);
GO

-- 4C: swim_tmi_flow_event_participants (10 cols)
IF OBJECT_ID('dbo.swim_tmi_flow_event_participants', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_flow_event_participants (
    id                  INT             NOT NULL PRIMARY KEY,
    event_id            INT             NOT NULL,
    pilot_cid           INT             NULL,
    callsign            NVARCHAR(16)    NULL,
    dep_aerodrome       NVARCHAR(8)     NULL,
    arr_aerodrome       NVARCHAR(8)     NULL,
    external_id         NVARCHAR(64)    NULL,
    flight_uid          BIGINT          NULL,
    matched_at          DATETIME2       NULL,
    synced_at           DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_flow_participants_event (event_id)
);
GO

-- 4D: swim_tmi_flow_measures (24 cols)
IF OBJECT_ID('dbo.swim_tmi_flow_measures', 'U') IS NULL
CREATE TABLE dbo.swim_tmi_flow_measures (
    measure_id          INT             NOT NULL PRIMARY KEY,
    measure_guid        UNIQUEIDENTIFIER NULL,
    provider_id         INT             NOT NULL,
    external_id         NVARCHAR(64)    NULL,
    ident               NVARCHAR(32)    NULL,
    revision            INT             NULL,
    event_id            INT             NULL,
    ctl_element         NVARCHAR(8)     NULL,
    element_type        NVARCHAR(16)    NULL,
    measure_type        NVARCHAR(16)    NULL,
    measure_value       NVARCHAR(32)    NULL,
    measure_unit        NVARCHAR(16)    NULL,
    reason              NVARCHAR(256)   NULL,
    filters_json        NVARCHAR(MAX)   NULL,
    exemptions_json     NVARCHAR(MAX)   NULL,
    mandatory_route_json NVARCHAR(MAX)  NULL,
    start_utc           DATETIME2       NULL,
    end_utc             DATETIME2       NULL,
    status              NVARCHAR(16)    NULL,
    withdrawn_at        DATETIME2       NULL,
    synced_at           DATETIME2       NULL,
    raw_data_json       NVARCHAR(MAX)   NULL,
    created_at          DATETIME2       NULL,
    updated_at          DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_flow_measures_status (status, end_utc),
    INDEX IX_swim_flow_measures_provider (provider_id)
);
GO

-- ============================================================================
-- SECTION 5: CDM Mirror Tables (from VATSIM_TMI)
-- ============================================================================

-- 5A: swim_cdm_messages (24 cols)
IF OBJECT_ID('dbo.swim_cdm_messages', 'U') IS NULL
CREATE TABLE dbo.swim_cdm_messages (
    message_id          INT             NOT NULL PRIMARY KEY,
    message_guid        UNIQUEIDENTIFIER NULL,
    flight_uid          BIGINT          NULL,
    callsign            NVARCHAR(16)    NULL,
    cid                 INT             NULL,
    message_type        NVARCHAR(32)    NULL,
    message_body        NVARCHAR(MAX)   NULL,
    message_data_json   NVARCHAR(MAX)   NULL,
    delivery_channel    NVARCHAR(16)    NULL,
    delivery_status     NVARCHAR(16)    NULL,
    delivery_attempts   INT             NULL,
    last_attempt_utc    DATETIME2       NULL,
    max_retries         INT             NULL,
    ack_type            NVARCHAR(16)    NULL,
    ack_reason          NVARCHAR(128)   NULL,
    ack_channel         NVARCHAR(16)    NULL,
    program_id          INT             NULL,
    slot_id             BIGINT          NULL,
    created_utc         DATETIME2       NULL,
    sent_utc            DATETIME2       NULL,
    ack_utc             DATETIME2       NULL,
    expires_utc         DATETIME2       NULL,
    is_hibernation_queued BIT           NULL,
    processed_utc       DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_cdm_messages_flight (flight_uid),
    INDEX IX_swim_cdm_messages_status (delivery_status)
);
GO

-- 5B: swim_cdm_pilot_readiness (17 cols)
IF OBJECT_ID('dbo.swim_cdm_pilot_readiness', 'U') IS NULL
CREATE TABLE dbo.swim_cdm_pilot_readiness (
    readiness_id        INT             NOT NULL PRIMARY KEY,
    readiness_guid      UNIQUEIDENTIFIER NULL,
    flight_uid          BIGINT          NOT NULL,
    callsign            NVARCHAR(16)    NULL,
    cid                 INT             NULL,
    readiness_state     NVARCHAR(16)    NULL,
    previous_state      NVARCHAR(16)    NULL,
    reported_tobt       DATETIME2       NULL,
    computed_tobt       DATETIME2       NULL,
    source              NVARCHAR(16)    NULL,
    source_detail       NVARCHAR(64)    NULL,
    dep_airport         NVARCHAR(8)     NULL,
    arr_airport         NVARCHAR(8)     NULL,
    reported_utc        DATETIME2       NULL,
    superseded_utc      DATETIME2       NULL,
    is_hibernation_queued BIT           NULL,
    processed_utc       DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_cdm_readiness_flight (flight_uid),
    INDEX IX_swim_cdm_readiness_current (superseded_utc)
);
GO

-- 5C: swim_cdm_compliance_live (18 cols)
IF OBJECT_ID('dbo.swim_cdm_compliance_live', 'U') IS NULL
CREATE TABLE dbo.swim_cdm_compliance_live (
    compliance_id       INT             NOT NULL PRIMARY KEY,
    flight_uid          BIGINT          NOT NULL,
    callsign            NVARCHAR(16)    NULL,
    program_id          INT             NULL,
    slot_id             BIGINT          NULL,
    compliance_type     NVARCHAR(32)    NULL,
    compliance_status   NVARCHAR(16)    NULL,
    risk_level          NVARCHAR(8)     NULL,
    expected_value      NVARCHAR(32)    NULL,
    actual_value        NVARCHAR(32)    NULL,
    delta_minutes       DECIMAL(8,1)    NULL,
    tolerance_min       INT             NULL,
    tolerance_max       INT             NULL,
    evaluated_utc       DATETIME2       NULL,
    is_final            BIT             NULL,
    finalized_utc       DATETIME2       NULL,
    is_hibernation_queued BIT           NULL,
    processed_utc       DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_cdm_compliance_flight (flight_uid),
    INDEX IX_swim_cdm_compliance_status (compliance_status, is_final)
);
GO

-- 5D: swim_cdm_airport_status (20 cols)
IF OBJECT_ID('dbo.swim_cdm_airport_status', 'U') IS NULL
CREATE TABLE dbo.swim_cdm_airport_status (
    status_id           INT             NOT NULL PRIMARY KEY,
    airport_icao        NVARCHAR(8)     NOT NULL,
    snapshot_utc        DATETIME2       NULL,
    total_departures_next_hour INT      NULL,
    ready_count         INT             NULL,
    gate_held_count     INT             NULL,
    taxiing_count       INT             NULL,
    boarding_count      INT             NULL,
    planning_count      INT             NULL,
    avg_taxi_time_sec   INT             NULL,
    baseline_taxi_sec   INT             NULL,
    avg_gate_hold_min   DECIMAL(5,1)    NULL,
    departures_last_hour INT            NULL,
    arrivals_last_hour  INT             NULL,
    weather_category    NVARCHAR(8)     NULL,
    aar                 INT             NULL,
    adr                 INT             NULL,
    is_controlled       BIT             NULL,
    controlling_program_id INT          NULL,
    is_hibernation_snapshot BIT         NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_cdm_airport_status_airport (airport_icao, snapshot_utc)
);
GO

-- ============================================================================
-- SECTION 6: Reference Mirror Tables
-- ============================================================================

-- 6A: swim_airports (from VATSIM_ADL.dbo.apts — 11 cols)
IF OBJECT_ID('dbo.swim_airports', 'U') IS NULL
CREATE TABLE dbo.swim_airports (
    icao_id             VARCHAR(4)      NOT NULL PRIMARY KEY,
    arpt_name           NVARCHAR(64)    NULL,
    lat_decimal         DECIMAL(10,7)   NULL,
    long_decimal        DECIMAL(11,7)   NULL,
    resp_artcc_id       NVARCHAR(8)     NULL,
    approach_id         NVARCHAR(64)    NULL,
    departure_id        NVARCHAR(64)    NULL,
    approach_departure_id NVARCHAR(64)  NULL,
    state               NVARCHAR(4)     NULL,
    resp_fir_id         NVARCHAR(8)     NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_airports_artcc (resp_artcc_id)
);
GO

-- 6B: swim_airport_taxi_reference (from VATSIM_ADL — 17 cols)
IF OBJECT_ID('dbo.swim_airport_taxi_reference', 'U') IS NULL
CREATE TABLE dbo.swim_airport_taxi_reference (
    airport_icao        VARCHAR(4)      NOT NULL PRIMARY KEY,
    unimpeded_taxi_sec  INT             DEFAULT 600,
    sample_size         INT             NULL,
    window_days         INT             NULL,
    p05_taxi_sec        INT             NULL,
    p10_taxi_sec        INT             NULL,
    p15_taxi_sec        INT             NULL,
    p25_taxi_sec        INT             NULL,
    median_taxi_sec     INT             NULL,
    p75_taxi_sec        INT             NULL,
    p90_taxi_sec        INT             NULL,
    avg_taxi_sec        INT             NULL,
    min_taxi_sec        INT             NULL,
    max_taxi_sec        INT             NULL,
    stddev_taxi_sec     INT             NULL,
    confidence          VARCHAR(8)      NULL,
    last_refreshed_utc  DATETIME2       NULL,
    created_utc         DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME()
);
GO

-- 6C: swim_airport_taxi_reference_detail (from VATSIM_ADL — 7 cols)
IF OBJECT_ID('dbo.swim_airport_taxi_reference_detail', 'U') IS NULL
CREATE TABLE dbo.swim_airport_taxi_reference_detail (
    airport_icao        VARCHAR(4)      NOT NULL,
    dimension           VARCHAR(32)     NOT NULL,
    dimension_value     VARCHAR(32)     NOT NULL,
    unimpeded_taxi_sec  INT             NULL,
    sample_size         INT             NULL,
    p05_taxi_sec        INT             NULL,
    p15_taxi_sec        INT             NULL,
    median_taxi_sec     INT             NULL,
    avg_taxi_sec        INT             NULL,
    last_refreshed_utc  DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    CONSTRAINT PK_swim_taxi_detail PRIMARY KEY (airport_icao, dimension, dimension_value)
);
GO

-- 6D: swim_playbook_route_throughput (from MySQL perti_site — 14 cols)
IF OBJECT_ID('dbo.swim_playbook_route_throughput', 'U') IS NULL
CREATE TABLE dbo.swim_playbook_route_throughput (
    throughput_id       INT             NOT NULL PRIMARY KEY,
    route_id            INT             NULL,
    play_id             INT             NULL,
    source              NVARCHAR(50)    NULL,
    planned_count       INT             NULL,
    slot_count          INT             NULL,
    peak_rate_hr        INT             NULL,
    avg_rate_hr         DECIMAL(6,1)    NULL,
    period_start        DATETIME2       NULL,
    period_end          DATETIME2       NULL,
    metadata_json       NVARCHAR(MAX)   NULL,
    updated_by          NVARCHAR(20)    NULL,
    updated_at          DATETIME2       NULL,
    created_at          DATETIME2       NULL,
    synced_utc          DATETIME2       DEFAULT SYSUTCDATETIME(),
    INDEX IX_swim_throughput_route (route_id),
    INDEX IX_swim_throughput_play (play_id)
);
GO

-- ============================================================================
-- SECTION 7: Infrastructure Tables
-- ============================================================================

-- 7A: swim_change_feed — monotonic sequence for multi-consumer replay
IF OBJECT_ID('dbo.swim_change_feed', 'U') IS NULL
CREATE TABLE dbo.swim_change_feed (
    seq                 BIGINT IDENTITY(1,1) PRIMARY KEY,
    event_type          NVARCHAR(20)    NOT NULL,  -- flight_update, flight_insert, flight_delete, tmi_update, refdata_update
    entity_type         NVARCHAR(50)    NOT NULL,  -- swim_flights, swim_tmi_entries, etc.
    entity_id           NVARCHAR(100)   NOT NULL,  -- flight_uid, entry_id, etc.
    changed_cols        NVARCHAR(MAX)   NULL,      -- JSON array of changed column names (NULL = all)
    event_utc           DATETIME2       NOT NULL DEFAULT SYSUTCDATETIME(),
    INDEX IX_change_feed_event_utc (event_utc),
    INDEX IX_change_feed_entity (entity_type, entity_id)
);
GO

-- 7B: swim_sync_watermarks — per-consumer replay state
IF OBJECT_ID('dbo.swim_sync_watermarks', 'U') IS NULL
CREATE TABLE dbo.swim_sync_watermarks (
    consumer_id         NVARCHAR(100)   NOT NULL PRIMARY KEY,
    last_seq            BIGINT          NOT NULL DEFAULT 0,
    last_sync_utc       DATETIME2       NULL,
    consumer_type       NVARCHAR(20)    NULL,  -- internal, external
    updated_at          DATETIME2       DEFAULT SYSUTCDATETIME()
);
GO

-- Seed default consumers
INSERT INTO dbo.swim_sync_watermarks (consumer_id, last_seq, consumer_type)
VALUES
    ('swim_sync_daemon', 0, 'internal'),
    ('ws_server', 0, 'internal');
GO

-- 7C: swim_sync_state — sync progress tracking for TMI sync daemon
IF OBJECT_ID('dbo.swim_sync_state', 'U') IS NULL
CREATE TABLE dbo.swim_sync_state (
    table_name          NVARCHAR(100)   NOT NULL PRIMARY KEY,
    last_sync_utc       DATETIME2       NULL,
    last_row_count      INT             NULL,
    last_duration_ms    INT             NULL,
    sync_mode           NVARCHAR(10)    NULL,  -- delta, full
    error_count         INT             DEFAULT 0,
    last_error          NVARCHAR(MAX)   NULL,
    updated_at          DATETIME2       DEFAULT SYSUTCDATETIME()
);
GO

-- ============================================================================
-- SECTION 8: Views (8 TMI active/recent + 3 CDM)
-- ============================================================================
-- These mirror the source views in VATSIM_TMI, adapted for SWIM mirror tables.

-- 8A: Active TMI entries
CREATE OR ALTER VIEW dbo.vw_swim_active_entries AS
SELECT *
FROM dbo.swim_tmi_entries
WHERE status = 'ACTIVE'
  AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME());
GO

-- 8B: Active NTML programs (matches TMI migration 005 override logic)
CREATE OR ALTER VIEW dbo.vw_swim_active_programs AS
SELECT *
FROM dbo.swim_ntml
WHERE status IN ('PROPOSED', 'MODELING', 'ACTIVE', 'PAUSED')
  AND is_archived = 0;
GO

-- 8C: Active advisories
CREATE OR ALTER VIEW dbo.vw_swim_active_advisories AS
SELECT *
FROM dbo.swim_tmi_advisories
WHERE status = 'ACTIVE'
  AND (effective_until IS NULL OR effective_until > SYSUTCDATETIME());
GO

-- 8D: Active reroutes
CREATE OR ALTER VIEW dbo.vw_swim_active_reroutes AS
SELECT *
FROM dbo.swim_tmi_reroutes
WHERE status = 2
  AND end_utc > SYSUTCDATETIME();
GO

-- 8E: Active public routes
CREATE OR ALTER VIEW dbo.vw_swim_active_public_routes AS
SELECT *
FROM dbo.swim_tmi_public_routes
WHERE status = 1
  AND valid_end_utc > SYSUTCDATETIME();
GO

-- 8F: Active flow events
CREATE OR ALTER VIEW dbo.vw_swim_active_flow_events AS
SELECT e.*,
       p.provider_code,
       p.provider_name
FROM dbo.swim_tmi_flow_events e
JOIN dbo.swim_tmi_flow_providers p ON p.provider_id = e.provider_id
WHERE e.status IN ('SCHEDULED', 'ACTIVE')
  AND e.end_utc > SYSUTCDATETIME()
  AND p.is_active = 1;
GO

-- 8G: Active flow measures
CREATE OR ALTER VIEW dbo.vw_swim_active_flow_measures AS
SELECT m.*,
       p.provider_code,
       p.provider_name,
       e.event_code,
       e.event_name
FROM dbo.swim_tmi_flow_measures m
JOIN dbo.swim_tmi_flow_providers p ON p.provider_id = m.provider_id
LEFT JOIN dbo.swim_tmi_flow_events e ON e.event_id = m.event_id
WHERE m.status IN ('NOTIFIED', 'ACTIVE')
  AND m.end_utc > SYSUTCDATETIME()
  AND p.is_active = 1;
GO

-- 8H: Recent entries (last 24 hours)
CREATE OR ALTER VIEW dbo.vw_swim_recent_entries AS
SELECT *
FROM dbo.swim_tmi_entries
WHERE created_at > DATEADD(HOUR, -24, SYSUTCDATETIME());
GO

-- 8I: CDM current readiness (non-superseded)
CREATE OR ALTER VIEW dbo.vw_swim_cdm_current_readiness AS
SELECT *
FROM dbo.swim_cdm_pilot_readiness
WHERE superseded_utc IS NULL;
GO

-- 8J: CDM pending messages
CREATE OR ALTER VIEW dbo.vw_swim_cdm_pending_messages AS
SELECT *
FROM dbo.swim_cdm_messages
WHERE delivery_status = 'PENDING'
  AND (expires_utc IS NULL OR expires_utc > SYSUTCDATETIME())
  AND delivery_attempts < ISNULL(max_retries, 3);
GO

-- 8K: CDM at-risk flights
CREATE OR ALTER VIEW dbo.vw_swim_cdm_at_risk_flights AS
SELECT *
FROM dbo.swim_cdm_compliance_live
WHERE compliance_status = 'AT_RISK'
  AND is_final = 0;
GO

-- ============================================================================
-- SECTION 9: CREATE OR ALTER sp_Swim_BulkUpsert
-- ============================================================================
-- Rewrite: OPENJSON WITH (~121 columns), row_hash skip, change feed emission.
-- Replaces migration 004 SP (77 cols, unconditional update).
-- Row hash uses SHA1 on ~19 key volatile columns to skip no-op updates.

CREATE OR ALTER PROCEDURE dbo.sp_Swim_BulkUpsert
    @Json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @inserted INT = 0, @updated INT = 0, @deleted INT = 0, @skipped INT = 0;
    DECLARE @total INT = 0;
    DECLARE @start DATETIME2 = SYSUTCDATETIME();
    DECLARE @merge_output TABLE (action NVARCHAR(10), flight_uid BIGINT);

    BEGIN TRY
        BEGIN TRANSACTION;

        -- =====================================================================
        -- Step 1: Parse JSON into temp table with OPENJSON WITH
        -- =====================================================================
        SELECT
            j.*,
            -- Row hash on ~19 key volatile columns
            HASHBYTES('SHA1', CONCAT(
                ISNULL(CAST(j.lat AS VARCHAR(20)), ''), '|',
                ISNULL(CAST(j.lon AS VARCHAR(20)), ''), '|',
                ISNULL(CAST(j.altitude_ft AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.groundspeed_kts AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.heading_deg AS VARCHAR(10)), ''), '|',
                ISNULL(j.phase, ''), '|',
                ISNULL(CAST(j.is_active AS VARCHAR(1)), ''), '|',
                ISNULL(j.current_artcc, ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.eta_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.etd_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.out_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.off_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.on_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.in_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.ctd_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.cta_utc, 126), ''), '|',
                ISNULL(j.ctl_type, ''), '|',
                ISNULL(CAST(j.delay_minutes AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.gs_held AS VARCHAR(1)), ''), '|',
                ISNULL(LEFT(j.fp_route, 200), '')
            )) AS row_hash
        INTO #flights
        FROM OPENJSON(@Json) WITH (
            -- Identity (6)
            flight_uid          INT,
            flight_key          NVARCHAR(64),
            gufi                NVARCHAR(64),
            callsign            NVARCHAR(16),
            cid                 INT,
            flight_id           NVARCHAR(16),
            -- Position (17)
            lat                 DECIMAL(9,6),
            lon                 DECIMAL(9,6),
            altitude_ft         INT,
            heading_deg         SMALLINT,
            groundspeed_kts     SMALLINT,
            vertical_rate_fpm   SMALLINT,
            true_airspeed_kts   SMALLINT,
            mach_number         DECIMAL(4,3),
            altitude_assigned   INT,
            altitude_cleared    INT,
            track_deg           SMALLINT,
            qnh_in_hg          DECIMAL(5,2),
            qnh_mb             DECIMAL(6,1),
            route_dist_to_dest_nm DECIMAL(8,1),
            route_pct_complete  DECIMAL(5,2),
            next_waypoint_name  NVARCHAR(16),
            dist_to_next_waypoint_nm DECIMAL(8,1),
            -- Flight plan (27)
            fp_dept_icao        NVARCHAR(8),
            fp_dest_icao        NVARCHAR(8),
            fp_alt_icao         NVARCHAR(8),
            fp_altitude_ft      INT,
            fp_tas_kts          SMALLINT,
            fp_route            NVARCHAR(MAX),
            fp_remarks          NVARCHAR(MAX),
            fp_rule             NVARCHAR(4),
            fp_dept_artcc       NVARCHAR(8),
            fp_dest_artcc       NVARCHAR(8),
            fp_dept_tracon      NVARCHAR(8),
            fp_dest_tracon      NVARCHAR(8),
            dfix                NVARCHAR(8),
            dp_name             NVARCHAR(16),
            afix                NVARCHAR(8),
            star_name           NVARCHAR(16),
            dep_runway          NVARCHAR(8),
            arr_runway          NVARCHAR(8),
            equipment_qualifier NVARCHAR(8),
            approach_procedure  NVARCHAR(16),
            fp_route_expanded   NVARCHAR(MAX),
            fp_fuel_minutes     INT,
            dtrsn               NVARCHAR(32),
            strsn               NVARCHAR(32),
            waypoint_count      INT,
            parse_status        NVARCHAR(16),
            simbrief_ofp_id     NVARCHAR(32),
            -- State / airspace (15)
            phase               NVARCHAR(16),
            is_active           BIT,
            dist_to_dest_nm     DECIMAL(8,2),
            dist_flown_nm       DECIMAL(8,2),
            pct_complete        DECIMAL(5,2),
            gcd_nm              DECIMAL(8,2),
            route_total_nm      DECIMAL(8,2),
            current_artcc       NVARCHAR(8),
            current_tracon      NVARCHAR(8),
            current_zone        NVARCHAR(8),
            current_zone_airport NVARCHAR(8),
            current_sector_low  NVARCHAR(16),
            current_sector_high NVARCHAR(16),
            weather_impact      NVARCHAR(64),
            weather_alert_ids   NVARCHAR(MAX),
            -- Times (24)
            first_seen_utc      DATETIME2(0),
            last_seen_utc       DATETIME2(0),
            logon_time_utc      DATETIME2(0),
            eta_utc             DATETIME2(0),
            eta_runway_utc      DATETIME2(0),
            eta_source          NVARCHAR(16),
            eta_method          NVARCHAR(16),
            etd_utc             DATETIME2(0),
            out_utc             DATETIME2(0),
            off_utc             DATETIME2(0),
            on_utc              DATETIME2(0),
            in_utc              DATETIME2(0),
            ete_minutes         SMALLINT,
            ctd_utc             DATETIME2(0),
            cta_utc             DATETIME2(0),
            edct_utc            DATETIME2(0),
            sta_utc             DATETIME2(0),
            etd_runway_utc      DATETIME2(0),
            etd_source          NVARCHAR(16),
            octd_utc            DATETIME2(0),
            octa_utc            DATETIME2(0),
            ate_minutes         DECIMAL(8,1),
            eta_confidence      NVARCHAR(8),
            eta_wind_component_kts SMALLINT,
            -- TMI control (21)
            gs_held             BIT,
            gs_release_utc      DATETIME2(0),
            ctl_type            NVARCHAR(8),
            ctl_prgm            NVARCHAR(16),
            ctl_element         NVARCHAR(8),
            is_exempt           BIT,
            exempt_reason       NVARCHAR(64),
            slot_time_utc       DATETIME2(0),
            slot_status         NVARCHAR(16),
            program_id          INT,
            slot_id             INT,
            delay_minutes       SMALLINT,
            delay_status        NVARCHAR(16),
            ctl_exempt          BIT,
            ctl_exempt_reason   NVARCHAR(64),
            aslot               NVARCHAR(16),
            delay_source        NVARCHAR(16),
            is_popup            BIT,
            popup_detected_utc  DATETIME2(0),
            absolute_delay_min  INT,
            schedule_variation_min INT,
            -- Aircraft (11)
            aircraft_type       NVARCHAR(16),
            aircraft_icao       NVARCHAR(8),
            aircraft_faa        NVARCHAR(8),
            weight_class        NVARCHAR(4),
            wake_category       NVARCHAR(4),
            engine_type         NVARCHAR(4),
            airline_icao        NVARCHAR(8),
            airline_name        NVARCHAR(64),
            engine_count        SMALLINT,
            cruise_tas_kts      SMALLINT,
            ceiling_ft          INT
        ) AS j;

        SET @total = (SELECT COUNT(*) FROM #flights);

        -- =====================================================================
        -- Step 2: MERGE with row-hash comparison
        -- =====================================================================
        MERGE dbo.swim_flights AS t
        USING #flights AS s ON t.flight_uid = s.flight_uid

        WHEN MATCHED AND (t.row_hash IS NULL OR t.row_hash <> s.row_hash) THEN UPDATE SET
            -- Identity
            t.flight_key = s.flight_key, t.gufi = s.gufi,
            t.callsign = s.callsign, t.cid = s.cid, t.flight_id = s.flight_id,
            -- Position
            t.lat = s.lat, t.lon = s.lon, t.altitude_ft = s.altitude_ft,
            t.heading_deg = s.heading_deg, t.groundspeed_kts = s.groundspeed_kts,
            t.vertical_rate_fpm = s.vertical_rate_fpm,
            t.true_airspeed_kts = s.true_airspeed_kts, t.mach_number = s.mach_number,
            t.altitude_assigned = s.altitude_assigned, t.altitude_cleared = s.altitude_cleared,
            t.track_deg = s.track_deg, t.qnh_in_hg = s.qnh_in_hg, t.qnh_mb = s.qnh_mb,
            t.route_dist_to_dest_nm = s.route_dist_to_dest_nm,
            t.route_pct_complete = s.route_pct_complete,
            t.next_waypoint_name = s.next_waypoint_name,
            t.dist_to_next_waypoint_nm = s.dist_to_next_waypoint_nm,
            -- Flight plan
            t.fp_dept_icao = s.fp_dept_icao, t.fp_dest_icao = s.fp_dest_icao,
            t.fp_alt_icao = s.fp_alt_icao, t.fp_altitude_ft = s.fp_altitude_ft,
            t.fp_tas_kts = s.fp_tas_kts, t.fp_route = s.fp_route,
            t.fp_remarks = s.fp_remarks, t.fp_rule = s.fp_rule,
            t.fp_dept_artcc = s.fp_dept_artcc, t.fp_dest_artcc = s.fp_dest_artcc,
            t.fp_dept_tracon = s.fp_dept_tracon, t.fp_dest_tracon = s.fp_dest_tracon,
            t.dfix = s.dfix, t.dp_name = s.dp_name,
            t.afix = s.afix, t.star_name = s.star_name,
            t.dep_runway = s.dep_runway, t.arr_runway = s.arr_runway,
            t.equipment_qualifier = s.equipment_qualifier,
            t.approach_procedure = s.approach_procedure,
            t.fp_route_expanded = s.fp_route_expanded,
            t.fp_fuel_minutes = s.fp_fuel_minutes,
            t.dtrsn = s.dtrsn, t.strsn = s.strsn,
            t.waypoint_count = s.waypoint_count, t.parse_status = s.parse_status,
            t.simbrief_ofp_id = s.simbrief_ofp_id,
            -- FIXM aliases (from same JSON data)
            t.sid = s.dp_name, t.star = s.star_name,
            t.departure_point = s.dfix, t.arrival_point = s.afix,
            t.alternate_aerodrome = s.fp_alt_icao,
            t.departure_runway = s.dep_runway, t.arrival_runway = s.arr_runway,
            t.current_airspace = s.current_artcc,
            t.current_sector = s.current_sector_low,
            t.estimated_time_of_departure = s.etd_utc,
            t.original_ctd = s.octd_utc,
            t.controlled_time_of_departure = s.ctd_utc,
            t.controlled_time_of_arrival = s.cta_utc,
            t.slot_time = s.slot_time_utc,
            t.control_type = s.ctl_type,
            t.control_element = s.ctl_element,
            t.program_name = s.ctl_prgm,
            t.delay_value = s.delay_minutes,
            t.ground_stop_held = s.gs_held,
            t.exempt_indicator = s.is_exempt,
            -- State / airspace
            t.phase = s.phase, t.is_active = s.is_active,
            t.dist_to_dest_nm = s.dist_to_dest_nm, t.dist_flown_nm = s.dist_flown_nm,
            t.pct_complete = s.pct_complete, t.gcd_nm = s.gcd_nm,
            t.route_total_nm = s.route_total_nm,
            t.current_artcc = s.current_artcc, t.current_tracon = s.current_tracon,
            t.current_zone = s.current_zone, t.current_zone_airport = s.current_zone_airport,
            t.current_sector_low = s.current_sector_low,
            t.current_sector_high = s.current_sector_high,
            t.weather_impact = s.weather_impact, t.weather_alert_ids = s.weather_alert_ids,
            -- Times
            t.first_seen_utc = s.first_seen_utc, t.last_seen_utc = s.last_seen_utc,
            t.logon_time_utc = s.logon_time_utc,
            t.eta_utc = s.eta_utc, t.eta_runway_utc = s.eta_runway_utc,
            t.eta_source = s.eta_source, t.eta_method = s.eta_method,
            t.etd_utc = s.etd_utc,
            t.out_utc = s.out_utc, t.off_utc = s.off_utc,
            t.on_utc = s.on_utc, t.in_utc = s.in_utc,
            t.ete_minutes = s.ete_minutes,
            t.ctd_utc = s.ctd_utc, t.cta_utc = s.cta_utc, t.edct_utc = s.edct_utc,
            t.sta_utc = s.sta_utc, t.etd_runway_utc = s.etd_runway_utc,
            t.etd_source = s.etd_source,
            t.octa_utc = s.octa_utc,
            t.ate_minutes = s.ate_minutes,
            t.eta_confidence = s.eta_confidence,
            t.eta_wind_component_kts = s.eta_wind_component_kts,
            -- TMI control
            t.gs_held = s.gs_held, t.gs_release_utc = s.gs_release_utc,
            t.ctl_type = s.ctl_type, t.ctl_prgm = s.ctl_prgm, t.ctl_element = s.ctl_element,
            t.is_exempt = s.is_exempt, t.exempt_reason = s.exempt_reason,
            t.slot_time_utc = s.slot_time_utc, t.slot_status = s.slot_status,
            t.program_id = s.program_id, t.slot_id = s.slot_id,
            t.delay_minutes = s.delay_minutes, t.delay_status = s.delay_status,
            t.ctl_exempt = s.ctl_exempt, t.ctl_exempt_reason = s.ctl_exempt_reason,
            t.aslot = s.aslot, t.delay_source = s.delay_source,
            t.is_popup = s.is_popup, t.popup_detected_utc = s.popup_detected_utc,
            t.absolute_delay_min = s.absolute_delay_min,
            t.schedule_variation_min = s.schedule_variation_min,
            -- Aircraft
            t.aircraft_type = s.aircraft_type, t.aircraft_icao = s.aircraft_icao,
            t.aircraft_faa = s.aircraft_faa, t.weight_class = s.weight_class,
            t.wake_category = s.wake_category, t.engine_type = s.engine_type,
            t.airline_icao = s.airline_icao, t.airline_name = s.airline_name,
            t.engine_count = s.engine_count,
            t.cruise_tas_kts = s.cruise_tas_kts, t.ceiling_ft = s.ceiling_ft,
            -- Metadata
            t.row_hash = s.row_hash,
            t.last_sync_utc = SYSUTCDATETIME()

        WHEN NOT MATCHED BY TARGET THEN INSERT (
            flight_uid, flight_key, gufi, callsign, cid, flight_id,
            lat, lon, altitude_ft, heading_deg, groundspeed_kts, vertical_rate_fpm,
            true_airspeed_kts, mach_number, altitude_assigned, altitude_cleared,
            track_deg, qnh_in_hg, qnh_mb,
            route_dist_to_dest_nm, route_pct_complete,
            next_waypoint_name, dist_to_next_waypoint_nm,
            fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_tas_kts,
            fp_route, fp_remarks, fp_rule,
            fp_dept_artcc, fp_dest_artcc, fp_dept_tracon, fp_dest_tracon,
            dfix, dp_name, afix, star_name, dep_runway, arr_runway,
            equipment_qualifier, approach_procedure,
            fp_route_expanded, fp_fuel_minutes, dtrsn, strsn,
            waypoint_count, parse_status, simbrief_ofp_id,
            sid, star, departure_point, arrival_point,
            alternate_aerodrome, departure_runway, arrival_runway,
            current_airspace, current_sector,
            estimated_time_of_departure, original_ctd,
            controlled_time_of_departure, controlled_time_of_arrival,
            slot_time, control_type, control_element, program_name,
            delay_value, ground_stop_held, exempt_indicator,
            phase, is_active, dist_to_dest_nm, dist_flown_nm, pct_complete,
            gcd_nm, route_total_nm, current_artcc, current_tracon, current_zone,
            current_zone_airport, current_sector_low, current_sector_high,
            weather_impact, weather_alert_ids,
            first_seen_utc, last_seen_utc, logon_time_utc,
            eta_utc, eta_runway_utc, eta_source, eta_method, etd_utc,
            out_utc, off_utc, on_utc, in_utc, ete_minutes,
            ctd_utc, cta_utc, edct_utc,
            sta_utc, etd_runway_utc, etd_source, octa_utc,
            ate_minutes, eta_confidence, eta_wind_component_kts,
            gs_held, gs_release_utc, ctl_type, ctl_prgm, ctl_element,
            is_exempt, exempt_reason, slot_time_utc, slot_status,
            program_id, slot_id, delay_minutes, delay_status,
            ctl_exempt, ctl_exempt_reason, aslot, delay_source,
            is_popup, popup_detected_utc, absolute_delay_min, schedule_variation_min,
            aircraft_type, aircraft_icao, aircraft_faa, weight_class,
            wake_category, engine_type, airline_icao, airline_name,
            engine_count, cruise_tas_kts, ceiling_ft,
            row_hash, last_sync_utc
        ) VALUES (
            s.flight_uid, s.flight_key, s.gufi, s.callsign, s.cid, s.flight_id,
            s.lat, s.lon, s.altitude_ft, s.heading_deg, s.groundspeed_kts, s.vertical_rate_fpm,
            s.true_airspeed_kts, s.mach_number, s.altitude_assigned, s.altitude_cleared,
            s.track_deg, s.qnh_in_hg, s.qnh_mb,
            s.route_dist_to_dest_nm, s.route_pct_complete,
            s.next_waypoint_name, s.dist_to_next_waypoint_nm,
            s.fp_dept_icao, s.fp_dest_icao, s.fp_alt_icao, s.fp_altitude_ft, s.fp_tas_kts,
            s.fp_route, s.fp_remarks, s.fp_rule,
            s.fp_dept_artcc, s.fp_dest_artcc, s.fp_dept_tracon, s.fp_dest_tracon,
            s.dfix, s.dp_name, s.afix, s.star_name, s.dep_runway, s.arr_runway,
            s.equipment_qualifier, s.approach_procedure,
            s.fp_route_expanded, s.fp_fuel_minutes, s.dtrsn, s.strsn,
            s.waypoint_count, s.parse_status, s.simbrief_ofp_id,
            s.dp_name, s.star_name, s.dfix, s.afix,            -- FIXM aliases
            s.fp_alt_icao, s.dep_runway, s.arr_runway,          -- FIXM aliases
            s.current_artcc, s.current_sector_low,               -- FIXM aliases
            s.etd_utc, s.octd_utc,                              -- FIXM aliases
            s.ctd_utc, s.cta_utc,                               -- FIXM aliases
            s.slot_time_utc, s.ctl_type, s.ctl_element, s.ctl_prgm, -- FIXM aliases
            s.delay_minutes, s.gs_held, s.is_exempt,             -- FIXM aliases
            s.phase, s.is_active, s.dist_to_dest_nm, s.dist_flown_nm, s.pct_complete,
            s.gcd_nm, s.route_total_nm, s.current_artcc, s.current_tracon, s.current_zone,
            s.current_zone_airport, s.current_sector_low, s.current_sector_high,
            s.weather_impact, s.weather_alert_ids,
            s.first_seen_utc, s.last_seen_utc, s.logon_time_utc,
            s.eta_utc, s.eta_runway_utc, s.eta_source, s.eta_method, s.etd_utc,
            s.out_utc, s.off_utc, s.on_utc, s.in_utc, s.ete_minutes,
            s.ctd_utc, s.cta_utc, s.edct_utc,
            s.sta_utc, s.etd_runway_utc, s.etd_source, s.octa_utc,
            s.ate_minutes, s.eta_confidence, s.eta_wind_component_kts,
            s.gs_held, s.gs_release_utc, s.ctl_type, s.ctl_prgm, s.ctl_element,
            s.is_exempt, s.exempt_reason, s.slot_time_utc, s.slot_status,
            s.program_id, s.slot_id, s.delay_minutes, s.delay_status,
            s.ctl_exempt, s.ctl_exempt_reason, s.aslot, s.delay_source,
            s.is_popup, s.popup_detected_utc, s.absolute_delay_min, s.schedule_variation_min,
            s.aircraft_type, s.aircraft_icao, s.aircraft_faa, s.weight_class,
            s.wake_category, s.engine_type, s.airline_icao, s.airline_name,
            s.engine_count, s.cruise_tas_kts, s.ceiling_ft,
            s.row_hash, SYSUTCDATETIME()
        )
        OUTPUT $action, ISNULL(inserted.flight_uid, deleted.flight_uid)
        INTO @merge_output(action, flight_uid);

        -- =====================================================================
        -- Step 3: Count results
        -- =====================================================================
        SELECT @inserted = SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END),
               @updated = SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END)
        FROM @merge_output;

        SET @skipped = @total - ISNULL(@inserted, 0) - ISNULL(@updated, 0);

        -- =====================================================================
        -- Step 4: Emit to change feed (append-only)
        -- =====================================================================
        INSERT INTO dbo.swim_change_feed (event_type, entity_type, entity_id)
        SELECT
            CASE WHEN action = 'INSERT' THEN 'flight_insert' ELSE 'flight_update' END,
            'swim_flights',
            CAST(flight_uid AS NVARCHAR(100))
        FROM @merge_output;

        -- =====================================================================
        -- Step 5: Delete stale flights (inactive >2 hours)
        -- =====================================================================
        DELETE FROM dbo.swim_flights
        WHERE is_active = 0
          AND last_sync_utc < DATEADD(HOUR, -2, SYSUTCDATETIME());

        SET @deleted = @@ROWCOUNT;

        -- Note: Deleted flight UIDs are not individually tracked in change feed.
        -- WS server detects deletions via absence in position updates.

        DROP TABLE #flights;

        COMMIT TRANSACTION;

        -- Return stats
        SELECT
            ISNULL(@inserted, 0) AS inserted,
            ISNULL(@updated, 0)  AS updated,
            @deleted             AS deleted,
            @skipped             AS skipped,
            @total               AS total,
            DATEDIFF(MILLISECOND, @start, SYSUTCDATETIME()) AS elapsed_ms;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        IF OBJECT_ID('tempdb..#flights') IS NOT NULL DROP TABLE #flights;
        THROW;
    END CATCH;
END;
GO

PRINT 'Created sp_Swim_BulkUpsert v2.0 (row-hash skip + change feed)';
GO

-- ============================================================================
-- SECTION 10: Change Feed Cleanup SP
-- ============================================================================
-- Runs daily off-peak. Retains 30 days or up to the minimum consumer watermark.

CREATE OR ALTER PROCEDURE dbo.sp_Swim_CleanupChangeFeed
    @RetentionDays INT = 30
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @min_seq BIGINT;
    DECLARE @cutoff_utc DATETIME2;
    DECLARE @deleted INT;

    -- Find the minimum watermark across all consumers
    SELECT @min_seq = MIN(last_seq) FROM dbo.swim_sync_watermarks;

    -- Also enforce time-based retention
    SET @cutoff_utc = DATEADD(DAY, -@RetentionDays, SYSUTCDATETIME());

    -- Delete events that are BOTH:
    -- 1. Below the minimum consumer watermark (all consumers have consumed them)
    -- 2. Older than the retention window
    DELETE FROM dbo.swim_change_feed
    WHERE seq < ISNULL(@min_seq, 0)
      AND event_utc < @cutoff_utc;

    SET @deleted = @@ROWCOUNT;

    SELECT @deleted AS deleted_events,
           @min_seq AS min_consumer_seq,
           @cutoff_utc AS cutoff_utc;
END;
GO

PRINT 'Created sp_Swim_CleanupChangeFeed';
GO

-- ============================================================================
-- SECTION 11: Verification Queries
-- ============================================================================
-- Run these after deploying migration to verify schema.

-- Verify new swim_flights columns
SELECT 'swim_flights new columns' AS [check],
       COUNT(*) AS col_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'dbo'
  AND TABLE_NAME = 'swim_flights'
  AND COLUMN_NAME IN (
    'current_zone_airport', 'current_sector_low', 'current_sector_high',
    'weather_impact', 'weather_alert_ids',
    'altitude_assigned', 'altitude_cleared', 'track_deg', 'qnh_in_hg', 'qnh_mb',
    'route_dist_to_dest_nm', 'route_pct_complete', 'next_waypoint_name', 'dist_to_next_waypoint_nm',
    'fp_route_expanded', 'fp_fuel_minutes', 'dtrsn', 'strsn', 'waypoint_count', 'parse_status',
    'sta_utc', 'etd_runway_utc', 'etd_source', 'octa_utc', 'ate_minutes', 'eta_confidence',
    'eta_wind_component_kts',
    'ctl_exempt', 'ctl_exempt_reason', 'aslot', 'delay_source',
    'is_popup', 'popup_detected_utc', 'absolute_delay_min', 'schedule_variation_min',
    'cruise_tas_kts', 'ceiling_ft', 'row_hash'
  );
GO

-- Verify new tables created
SELECT 'mirror tables' AS [check],
       TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'dbo'
  AND TABLE_NAME IN (
    'swim_ntml', 'swim_ntml_info', 'swim_ntml_slots',
    'swim_tmi_programs', 'swim_tmi_entries', 'swim_tmi_advisories',
    'swim_tmi_reroutes', 'swim_tmi_reroute_routes', 'swim_tmi_reroute_flights',
    'swim_tmi_reroute_compliance_log', 'swim_tmi_public_routes', 'swim_tmi_flight_control',
    'swim_tmi_flow_providers', 'swim_tmi_flow_events',
    'swim_tmi_flow_event_participants', 'swim_tmi_flow_measures',
    'swim_cdm_messages', 'swim_cdm_pilot_readiness',
    'swim_cdm_compliance_live', 'swim_cdm_airport_status',
    'swim_airports', 'swim_airport_taxi_reference',
    'swim_airport_taxi_reference_detail', 'swim_playbook_route_throughput',
    'swim_change_feed', 'swim_sync_watermarks', 'swim_sync_state'
  )
ORDER BY TABLE_NAME;
GO

-- Verify views created
SELECT 'views' AS [check],
       TABLE_NAME
FROM INFORMATION_SCHEMA.VIEWS
WHERE TABLE_SCHEMA = 'dbo'
  AND TABLE_NAME LIKE 'vw_swim_%'
ORDER BY TABLE_NAME;
GO

-- Verify SP exists
SELECT 'sp_Swim_BulkUpsert' AS [check],
       OBJECT_ID('dbo.sp_Swim_BulkUpsert', 'P') AS object_id,
       CASE WHEN OBJECT_ID('dbo.sp_Swim_BulkUpsert', 'P') IS NOT NULL THEN 'EXISTS' ELSE 'MISSING' END AS status;
GO

-- Verify index count on swim_flights (should be ~22 after drops)
SELECT 'swim_flights indexes' AS [check],
       COUNT(*) AS index_count
FROM sys.indexes
WHERE object_id = OBJECT_ID('dbo.swim_flights')
  AND type > 0;
GO

PRINT '== Migration 026 complete: SWIM Data Isolation ==';
GO
