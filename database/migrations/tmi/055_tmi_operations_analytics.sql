-- ============================================================================
-- VATSIM_TMI Migration 055: TMI Operations & Analytics Platform
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-30
-- Author: HP/Claude
--
-- Creates 10 tables across 3 layers:
--   Layer 1 (TMI Unified Log): tmi_log_core, tmi_log_scope,
--     tmi_log_parameters, tmi_log_impact, tmi_log_references
--   Layer 2 (Delay Attribution): tmi_cause_taxonomy, tmi_delay_attribution
--   Layer 3 (Facility Statistics): tmi_facility_stats_hourly,
--     tmi_facility_stats_daily, tmi_ops_performance
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- Layer 1: TMI Unified Log
-- ============================================================================

PRINT 'Creating Layer 1: TMI Unified Log tables...';
GO

-- Core spine table — every TMI action gets one row
IF OBJECT_ID('dbo.tmi_log_core', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_log_core (
        log_id              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        log_seq             BIGINT IDENTITY(1,1) NOT NULL,
        action_category     NVARCHAR(32) NOT NULL,
        action_type         NVARCHAR(32) NOT NULL,
        program_type        NVARCHAR(32) NULL,
        severity            NVARCHAR(16) NOT NULL DEFAULT 'INFO',
        source_system       NVARCHAR(32) NOT NULL,
        summary             NVARCHAR(512) NOT NULL,
        event_utc           DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        user_cid            NVARCHAR(16) NULL,
        user_name           NVARCHAR(128) NULL,
        user_position       NVARCHAR(64) NULL,
        user_oi             NVARCHAR(8) NULL,
        session_id          NVARCHAR(128) NULL,
        issuing_facility    NVARCHAR(64) NULL,
        issuing_org         NVARCHAR(64) NULL,
        CONSTRAINT PK_tmi_log_core PRIMARY KEY NONCLUSTERED (log_id),
        INDEX IX_log_seq CLUSTERED (log_seq),
        INDEX IX_log_event_utc (event_utc DESC),
        INDEX IX_log_category (action_category, action_type, event_utc DESC),
        INDEX IX_log_program_type (program_type, event_utc DESC) WHERE program_type IS NOT NULL,
        INDEX IX_log_facility (issuing_facility, event_utc DESC) WHERE issuing_facility IS NOT NULL,
        INDEX IX_log_org (issuing_org, event_utc DESC) WHERE issuing_org IS NOT NULL
    );
    PRINT '  Created: tmi_log_core';
END
ELSE
    PRINT '  Exists: tmi_log_core';
GO

-- Scope satellite — what airspace/facilities/filters the action affects
IF OBJECT_ID('dbo.tmi_log_scope', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_log_scope (
        log_id              UNIQUEIDENTIFIER NOT NULL,
        ctl_element         NVARCHAR(64) NULL,
        element_type        NVARCHAR(16) NULL,
        facility            NVARCHAR(64) NULL,
        traffic_flow        NVARCHAR(32) NULL,
        via_fix             NVARCHAR(64) NULL,
        scope_airports      NVARCHAR(MAX) NULL,
        scope_tiers         NVARCHAR(MAX) NULL,
        scope_altitude      NVARCHAR(128) NULL,
        scope_aircraft_type NVARCHAR(MAX) NULL,
        scope_carriers      NVARCHAR(MAX) NULL,
        scope_equipment     NVARCHAR(256) NULL,
        exclusions          NVARCHAR(MAX) NULL,
        flt_incl_type       NVARCHAR(16) NULL,
        affected_facilities NVARCHAR(MAX) NULL,
        dep_facilities      NVARCHAR(MAX) NULL,
        dep_scope           NVARCHAR(64) NULL,
        filters_json        NVARCHAR(MAX) NULL,
        CONSTRAINT PK_tmi_log_scope PRIMARY KEY (log_id),
        CONSTRAINT FK_log_scope_core FOREIGN KEY (log_id)
            REFERENCES dbo.tmi_log_core(log_id),
        INDEX IX_scope_ctl (ctl_element, element_type),
        INDEX IX_scope_facility (facility)
    );
    PRINT '  Created: tmi_log_scope';
END
ELSE
    PRINT '  Exists: tmi_log_scope';
GO

-- Parameters satellite — operational values, times, cause, text content
IF OBJECT_ID('dbo.tmi_log_parameters', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_log_parameters (
        log_id              UNIQUEIDENTIFIER NOT NULL,
        effective_start_utc DATETIME2 NULL,
        effective_end_utc   DATETIME2 NULL,
        rate_value          INT NULL,
        rate_unit           NVARCHAR(16) NULL,
        spacing_type        NVARCHAR(32) NULL,
        program_rate        INT NULL,
        rates_hourly_json   NVARCHAR(MAX) NULL,
        rates_quarter_json  NVARCHAR(MAX) NULL,
        delay_cap           INT NULL,
        cause_category      NVARCHAR(32) NULL,
        cause_detail        NVARCHAR(256) NULL,
        impacting_condition NVARCHAR(128) NULL,
        prob_extension      NVARCHAR(16) NULL,
        delay_type          NVARCHAR(8) NULL,
        delay_minutes       INT NULL,
        delay_trend         NVARCHAR(16) NULL,
        holding_status      NVARCHAR(16) NULL,
        holding_fix         NVARCHAR(32) NULL,
        aircraft_holding    INT NULL,
        weather_conditions  NVARCHAR(8) NULL,
        arrival_runways     NVARCHAR(MAX) NULL,
        departure_runways   NVARCHAR(MAX) NULL,
        config_name         NVARCHAR(64) NULL,
        gs_probability      NVARCHAR(16) NULL,
        gs_release_rate     INT NULL,
        cancellation_reason NVARCHAR(256) NULL,
        cancellation_edct_action NVARCHAR(32) NULL,
        cancellation_notes  NVARCHAR(MAX) NULL,
        meter_point         NVARCHAR(64) NULL,
        freeze_horizon      INT NULL,
        compression_enabled BIT NULL,
        ntml_formatted      NVARCHAR(MAX) NULL,
        remarks             NVARCHAR(MAX) NULL,
        detail_json         NVARCHAR(MAX) NULL,
        qualifiers          NVARCHAR(MAX) NULL,
        CONSTRAINT PK_tmi_log_parameters PRIMARY KEY (log_id),
        CONSTRAINT FK_log_params_core FOREIGN KEY (log_id)
            REFERENCES dbo.tmi_log_core(log_id),
        INDEX IX_params_effective (effective_start_utc, effective_end_utc)
            WHERE effective_start_utc IS NOT NULL
    );
    PRINT '  Created: tmi_log_parameters';
END
ELSE
    PRINT '  Exists: tmi_log_parameters';
GO

-- Impact satellite — operational metrics snapshot at time of action
IF OBJECT_ID('dbo.tmi_log_impact', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_log_impact (
        log_id              UNIQUEIDENTIFIER NOT NULL,
        total_flights       INT NULL,
        controlled_flights  INT NULL,
        exempt_flights      INT NULL,
        airborne_flights    INT NULL,
        popup_flights       INT NULL,
        avg_delay_min       DECIMAL(8,1) NULL,
        max_delay_min       DECIMAL(8,1) NULL,
        total_delay_min     DECIMAL(12,1) NULL,
        cumulative_total_delay DECIMAL(12,1) NULL,
        cumulative_max_delay   DECIMAL(8,1) NULL,
        demand_rate         INT NULL,
        capacity_rate       INT NULL,
        reversal_count      INT NULL,
        reversal_pct        DECIMAL(5,2) NULL,
        gaming_flags_count  INT NULL,
        compliance_rate     DECIMAL(5,2) NULL,
        comments            NVARCHAR(MAX) NULL,
        CONSTRAINT PK_tmi_log_impact PRIMARY KEY (log_id),
        CONSTRAINT FK_log_impact_core FOREIGN KEY (log_id)
            REFERENCES dbo.tmi_log_core(log_id)
    );
    PRINT '  Created: tmi_log_impact';
END
ELSE
    PRINT '  Exists: tmi_log_impact';
GO

-- References satellite — entity FKs, lifecycle chain, Discord linkage
IF OBJECT_ID('dbo.tmi_log_references', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_log_references (
        log_id              UNIQUEIDENTIFIER NOT NULL,
        program_id          INT NULL,
        entry_id            INT NULL,
        advisory_id         INT NULL,
        reroute_id          INT NULL,
        slot_id             BIGINT NULL,
        flight_uid          BIGINT NULL,
        proposal_id         INT NULL,
        flow_measure_id     INT NULL,
        delay_entry_id      INT NULL,
        airport_config_id   INT NULL,
        parent_log_id       UNIQUEIDENTIFIER NULL,
        supersedes_log_id   UNIQUEIDENTIFIER NULL,
        supersedes_entry_id INT NULL,
        advisory_number     NVARCHAR(32) NULL,
        cancel_advisory_num NVARCHAR(32) NULL,
        revision_number     INT NULL,
        source_type         NVARCHAR(16) NULL,
        source_id           NVARCHAR(128) NULL,
        source_channel      NVARCHAR(64) NULL,
        content_hash        NVARCHAR(64) NULL,
        discord_message_id  NVARCHAR(64) NULL,
        discord_channel_id  NVARCHAR(64) NULL,
        discord_channel_purpose NVARCHAR(32) NULL,
        coordination_log_id INT NULL,
        CONSTRAINT PK_tmi_log_references PRIMARY KEY (log_id),
        CONSTRAINT FK_log_refs_core FOREIGN KEY (log_id)
            REFERENCES dbo.tmi_log_core(log_id),
        INDEX IX_refs_program (program_id) WHERE program_id IS NOT NULL,
        INDEX IX_refs_entry (entry_id) WHERE entry_id IS NOT NULL,
        INDEX IX_refs_advisory (advisory_id) WHERE advisory_id IS NOT NULL,
        INDEX IX_refs_reroute (reroute_id) WHERE reroute_id IS NOT NULL,
        INDEX IX_refs_flow (flow_measure_id) WHERE flow_measure_id IS NOT NULL,
        INDEX IX_refs_parent (parent_log_id) WHERE parent_log_id IS NOT NULL,
        INDEX IX_refs_flight (flight_uid) WHERE flight_uid IS NOT NULL
    );
    PRINT '  Created: tmi_log_references';
END
ELSE
    PRINT '  Exists: tmi_log_references';
GO

PRINT 'Layer 1 complete.';
GO

-- ============================================================================
-- Layer 2: Delay Attribution
-- ============================================================================

PRINT 'Creating Layer 2: Delay Attribution tables...';
GO

-- Cause taxonomy reference table
IF OBJECT_ID('dbo.tmi_cause_taxonomy', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_cause_taxonomy (
        cause_id            INT IDENTITY(1,1) PRIMARY KEY,
        cause_category      NVARCHAR(32) NOT NULL,
        cause_subcategory   NVARCHAR(32) NOT NULL,
        opsnet_code         NVARCHAR(8) NULL,
        aspm_category       NVARCHAR(32) NULL,
        description         NVARCHAR(256) NOT NULL,
        is_tmi_attributed   BIT NOT NULL DEFAULT 0,
        is_facility_attributed BIT NOT NULL DEFAULT 0,
        display_order       INT NOT NULL DEFAULT 0,
        is_active           BIT NOT NULL DEFAULT 1,
        INDEX IX_cause_category (cause_category, cause_subcategory)
    );
    PRINT '  Created: tmi_cause_taxonomy';

    -- Seed data
    INSERT INTO dbo.tmi_cause_taxonomy
        (cause_category, cause_subcategory, description, is_tmi_attributed, is_facility_attributed, display_order)
    VALUES
        ('TMI', 'GDP', 'Ground Delay Program', 1, 1, 1),
        ('TMI', 'GS', 'Ground Stop', 1, 1, 2),
        ('TMI', 'AFP', 'Airspace Flow Program', 1, 1, 3),
        ('TMI', 'MIT', 'Miles-in-Trail restriction', 1, 1, 4),
        ('TMI', 'MINIT', 'Minutes-in-Trail restriction', 1, 1, 5),
        ('TMI', 'STOP', 'Ground Stop restriction', 1, 1, 6),
        ('TMI', 'REROUTE', 'Reroute directive', 1, 1, 7),
        ('WEATHER', 'CONVECTIVE', 'Convective weather (thunderstorms)', 0, 0, 10),
        ('WEATHER', 'LOW_CEILING', 'Low ceiling conditions', 0, 0, 11),
        ('WEATHER', 'WIND', 'Wind-related delays', 0, 0, 12),
        ('WEATHER', 'SNOW_ICE', 'Snow/ice conditions', 0, 0, 13),
        ('WEATHER', 'LOW_VISIBILITY', 'Low visibility conditions', 0, 0, 14),
        ('WEATHER', 'OTHER', 'Other weather conditions', 0, 0, 15),
        ('VOLUME', 'DEMAND_EXCEEDS_CAPACITY', 'Demand exceeds capacity', 0, 1, 20),
        ('VOLUME', 'STAFFING', 'Staffing limitations', 0, 1, 21),
        ('VOLUME', 'EVENT_TRAFFIC', 'Event-related traffic surge', 0, 1, 22),
        ('EQUIPMENT', 'RUNWAY_CLOSURE', 'Runway closure', 0, 1, 30),
        ('EQUIPMENT', 'NAVAID_OUTAGE', 'Navigation aid outage', 0, 1, 31),
        ('EQUIPMENT', 'SYSTEM_OUTAGE', 'System outage', 0, 1, 32),
        ('RUNWAY', 'CONFIGURATION_CHANGE', 'Runway configuration change', 0, 1, 40),
        ('RUNWAY', 'CAPACITY_REDUCTION', 'Runway capacity reduction', 0, 1, 41),
        ('OTHER', 'UNATTRIBUTED', 'Unattributed delay', 0, 0, 50),
        ('OTHER', 'PILOT', 'Pilot-related delay', 0, 0, 51),
        ('OTHER', 'MILITARY', 'Military operations', 0, 0, 52),
        ('OTHER', 'SECURITY', 'Security-related delay', 0, 0, 53);
    PRINT '  Seeded: tmi_cause_taxonomy (25 rows)';
END
ELSE
    PRINT '  Exists: tmi_cause_taxonomy';
GO

-- Per-flight delay attribution
IF OBJECT_ID('dbo.tmi_delay_attribution', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_delay_attribution (
        attribution_id      BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,
        callsign            NVARCHAR(16) NOT NULL,
        dep_icao            NVARCHAR(8) NULL,
        arr_icao            NVARCHAR(8) NULL,
        delay_phase         NVARCHAR(16) NOT NULL,
        delay_minutes       DECIMAL(8,1) NOT NULL,
        baseline_utc        DATETIME2 NULL,
        actual_utc          DATETIME2 NULL,
        cause_id            INT NOT NULL,
        cause_category      NVARCHAR(32) NOT NULL,
        cause_subcategory   NVARCHAR(32) NOT NULL,
        attributed_program_id   INT NULL,
        attributed_entry_id     INT NULL,
        attributed_log_id       UNIQUEIDENTIFIER NULL,
        attributed_facility     NVARCHAR(64) NULL,
        attributed_org          NVARCHAR(64) NULL,
        arr_facility        NVARCHAR(64) NULL,
        dep_facility        NVARCHAR(64) NULL,
        aircraft_type       NVARCHAR(8) NULL,
        carrier             NVARCHAR(8) NULL,
        computation_method  NVARCHAR(32) NOT NULL,
        computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        confidence          NVARCHAR(16) NULL,
        is_current          BIT NOT NULL DEFAULT 1,
        superseded_by       BIGINT NULL,
        CONSTRAINT FK_delay_cause FOREIGN KEY (cause_id)
            REFERENCES dbo.tmi_cause_taxonomy(cause_id),
        INDEX IX_delay_flight (flight_uid, is_current, delay_phase),
        INDEX IX_delay_program (attributed_program_id, computed_utc DESC)
            WHERE attributed_program_id IS NOT NULL,
        INDEX IX_delay_facility (attributed_facility, computed_utc DESC)
            WHERE attributed_facility IS NOT NULL,
        INDEX IX_delay_arr (arr_icao, computed_utc DESC),
        INDEX IX_delay_dep (dep_icao, computed_utc DESC),
        INDEX IX_delay_cause (cause_category, cause_subcategory, computed_utc DESC),
        INDEX IX_delay_phase (delay_phase, computed_utc DESC)
    );
    PRINT '  Created: tmi_delay_attribution';
END
ELSE
    PRINT '  Exists: tmi_delay_attribution';
GO

PRINT 'Layer 2 complete.';
GO

-- ============================================================================
-- Layer 3: Facility Delay Statistics
-- ============================================================================

PRINT 'Creating Layer 3: Facility Statistics tables...';
GO

-- Hourly facility metrics
IF OBJECT_ID('dbo.tmi_facility_stats_hourly', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_facility_stats_hourly (
        stat_id             BIGINT IDENTITY(1,1) PRIMARY KEY,
        facility            NVARCHAR(64) NOT NULL,
        facility_type       NVARCHAR(16) NOT NULL,
        airport_icao        NVARCHAR(8) NULL,
        hour_utc            DATETIME2 NOT NULL,
        total_operations    INT NOT NULL DEFAULT 0,
        total_arrivals      INT NOT NULL DEFAULT 0,
        total_departures    INT NOT NULL DEFAULT 0,
        total_overflights   INT NOT NULL DEFAULT 0,
        ontime_arrivals     INT NOT NULL DEFAULT 0,
        delayed_arrivals    INT NOT NULL DEFAULT 0,
        ontime_departures   INT NOT NULL DEFAULT 0,
        delayed_departures  INT NOT NULL DEFAULT 0,
        delay_min_tmi       DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_weather   DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_volume    DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_equipment DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_runway    DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_other     DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_total     DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_gate      DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_taxi_out  DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_airborne  DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_taxi_in   DECIMAL(12,1) NOT NULL DEFAULT 0,
        avg_arr_delay_min   DECIMAL(8,1) NULL,
        avg_dep_delay_min   DECIMAL(8,1) NULL,
        max_arr_delay_min   DECIMAL(8,1) NULL,
        max_dep_delay_min   DECIMAL(8,1) NULL,
        active_programs     INT NOT NULL DEFAULT 0,
        active_restrictions INT NOT NULL DEFAULT 0,
        active_reroutes     INT NOT NULL DEFAULT 0,
        aar_configured      INT NULL,
        adr_configured      INT NULL,
        weather_condition   NVARCHAR(8) NULL,
        computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        INDEX IX_fsh_facility_hour UNIQUE CLUSTERED (facility, hour_utc),
        INDEX IX_fsh_airport_hour (airport_icao, hour_utc)
            WHERE airport_icao IS NOT NULL,
        INDEX IX_fsh_hour (hour_utc DESC)
    );
    PRINT '  Created: tmi_facility_stats_hourly';
END
ELSE
    PRINT '  Exists: tmi_facility_stats_hourly';
GO

-- Daily facility rollups
IF OBJECT_ID('dbo.tmi_facility_stats_daily', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_facility_stats_daily (
        stat_id             BIGINT IDENTITY(1,1) PRIMARY KEY,
        facility            NVARCHAR(64) NOT NULL,
        facility_type       NVARCHAR(16) NOT NULL,
        airport_icao        NVARCHAR(8) NULL,
        date_utc            DATE NOT NULL,
        total_operations    INT NOT NULL DEFAULT 0,
        total_arrivals      INT NOT NULL DEFAULT 0,
        total_departures    INT NOT NULL DEFAULT 0,
        ontime_arr_pct      DECIMAL(5,2) NULL,
        ontime_dep_pct      DECIMAL(5,2) NULL,
        delay_min_tmi       DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_weather   DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_volume    DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_equipment DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_runway    DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_other     DECIMAL(12,1) NOT NULL DEFAULT 0,
        delay_min_total     DECIMAL(12,1) NOT NULL DEFAULT 0,
        avg_arr_delay_min   DECIMAL(8,1) NULL,
        avg_dep_delay_min   DECIMAL(8,1) NULL,
        programs_issued     INT NOT NULL DEFAULT 0,
        restrictions_issued INT NOT NULL DEFAULT 0,
        advisories_issued   INT NOT NULL DEFAULT 0,
        peak_demand_hour    DATETIME2 NULL,
        peak_demand_rate    INT NULL,
        peak_delay_hour     DATETIME2 NULL,
        peak_delay_min      DECIMAL(8,1) NULL,
        computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        INDEX IX_fsd_facility_date UNIQUE CLUSTERED (facility, date_utc),
        INDEX IX_fsd_airport_date (airport_icao, date_utc)
            WHERE airport_icao IS NOT NULL,
        INDEX IX_fsd_date (date_utc DESC)
    );
    PRINT '  Created: tmi_facility_stats_daily';
END
ELSE
    PRINT '  Exists: tmi_facility_stats_daily';
GO

-- ASPM-style airport on-time performance
IF OBJECT_ID('dbo.tmi_ops_performance', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_ops_performance (
        perf_id             BIGINT IDENTITY(1,1) PRIMARY KEY,
        airport_icao        NVARCHAR(8) NOT NULL,
        date_utc            DATE NOT NULL,
        arr_a0              INT NULL,
        arr_a14             INT NULL,
        arr_delayed         INT NULL,
        arr_total           INT NULL,
        dep_d0              INT NULL,
        dep_d14             INT NULL,
        dep_delayed         INT NULL,
        dep_total           INT NULL,
        arr_delay_0_15      INT NULL,
        arr_delay_16_30     INT NULL,
        arr_delay_31_60     INT NULL,
        arr_delay_61_120    INT NULL,
        arr_delay_121_plus  INT NULL,
        dep_delay_0_15      INT NULL,
        dep_delay_16_30     INT NULL,
        dep_delay_31_60     INT NULL,
        dep_delay_61_120    INT NULL,
        dep_delay_121_plus  INT NULL,
        avg_taxi_out_min    DECIMAL(8,1) NULL,
        avg_taxi_in_min     DECIMAL(8,1) NULL,
        unimpeded_taxi_out  DECIMAL(8,1) NULL,
        unimpeded_taxi_in   DECIMAL(8,1) NULL,
        excess_taxi_out_min DECIMAL(8,1) NULL,
        excess_taxi_in_min  DECIMAL(8,1) NULL,
        avg_gate_delay_min  DECIMAL(8,1) NULL,
        computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        INDEX IX_ops_airport_date UNIQUE CLUSTERED (airport_icao, date_utc),
        INDEX IX_ops_date (date_utc DESC)
    );
    PRINT '  Created: tmi_ops_performance';
END
ELSE
    PRINT '  Exists: tmi_ops_performance';
GO

PRINT 'Layer 3 complete.';
GO

PRINT 'Migration 055: TMI Operations & Analytics Platform complete';
GO
