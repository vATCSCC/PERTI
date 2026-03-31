# TMI Operations & Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a comprehensive TMI logging, delay attribution, and facility statistics system across 10 new tables in VATSIM_TMI, a PHP helper layer, 40+ endpoint wirings, two computation daemons, and a NAS Event Log page.

**Architecture:** Three layers in VATSIM_TMI: (1) Normalized 5-table audit trail (`tmi_log_core` + 4 satellites) capturing every TMI action, (2) per-flight delay attribution with cause taxonomy, (3) OpsNet/ASPM-style hourly/daily facility metrics. A PHP helper `log_tmi_action()` supports both sqlsrv (GDT endpoints) and PDO (publish.php) connections. Two new daemons compute delay attribution and facility statistics via cross-database PHP-mediated queries.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv + PDO), jQuery 2.2.4, Bootstrap 4.5, Chart.js

**Spec:** `docs/superpowers/specs/2026-03-30-tmi-operations-analytics-design.md`

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `database/migrations/tmi/055_tmi_operations_analytics.sql` | DDL for all 10 tables + seed data + indexes |
| `load/tmi_log.php` | `log_tmi_action()` helper — dual sqlsrv/PDO support |
| `scripts/tmi/delay_attribution_daemon.php` | Computes per-flight delay attribution from ADL flight times |
| `scripts/tmi/facility_stats_daemon.php` | Computes hourly/daily facility stats and ASPM metrics |
| `api/tmi/event-log.php` | REST API for querying the unified TMI log |
| `ntml-log.php` | NAS Event Log page (controller-facing chronological view) |
| `assets/js/ntml-log.js` | JS module for NAS Event Log page (filters, auto-refresh, expand/collapse) |

### Modified Files
| File | Change |
|------|--------|
| `api/gdt/common.php` | Add `require_once tmi_log.php` |
| `api/gdt/programs/activate.php` | Add `log_tmi_action()` call after activation |
| `api/gdt/programs/cancel.php` | Add `log_tmi_action()` call after cancellation |
| `api/gdt/programs/compress.php` | Add `log_tmi_action()` call after compression |
| `api/gdt/programs/reoptimize.php` | Add `log_tmi_action()` call after reoptimization |
| `api/gdt/programs/create.php` | Add `log_tmi_action()` call after creation |
| `api/gdt/programs/extend.php` | Add `log_tmi_action()` call after extension |
| `api/gdt/programs/revise.php` | Add `log_tmi_action()` call after revision |
| `api/gdt/programs/transition.php` | Add `log_tmi_action()` call after GS→GDP transition |
| `api/gdt/programs/purge.php` | Add `log_tmi_action()` call after purge |
| `api/gdt/programs/power_run.php` | Add `log_tmi_action()` call after simulation |
| `api/gdt/programs/simulate.php` | Add `log_tmi_action()` call after simulation |
| `api/gdt/programs/blanket.php` | Add `log_tmi_action()` call after blanket EDCT |
| `api/gdt/programs/ecr.php` | Add `log_tmi_action()` call after ECR |
| `api/gdt/programs/publish.php` | Add `log_tmi_action()` call after publish |
| `api/gdt/programs/submit_proposal.php` | Add `log_tmi_action()` call after proposal submission |
| `api/mgt/tmi/publish.php` | Add `log_tmi_action()` calls (PDO path) for entries, advisories, delays, configs |
| `api/mgt/tmi/promote.php` | Add `log_tmi_action()` call after promotion |
| `api/mgt/tmi/coordinate.php` | Add `log_tmi_action()` calls for submit/approve/deny/rescind |
| `api/mgt/tmi/cancel.php` | Add `log_tmi_action()` call after cancellation |
| `api/mgt/tmi/edit.php` | Add `log_tmi_action()` call after edit |
| `api/mgt/tmi/reroutes/post.php` | Add `log_tmi_action()` call after reroute creation |
| `api/mgt/tmi/reroutes/bulk.php` | Add `log_tmi_action()` call after bulk reroute |
| `api/mgt/tmi/reroutes/delete.php` | Add `log_tmi_action()` call after reroute deletion |
| `api/mgt/tmi/ground_stops/post.php` | Add `log_tmi_action()` call after GS creation |
| `api/tmi/gs/create.php` | Add `log_tmi_action()` call after GS creation |
| `api/tmi/gs/activate.php` | Add `log_tmi_action()` call after GS activation |
| `api/tmi/gs/extend.php` | Add `log_tmi_action()` call after GS extension |
| `api/tmi/gs/purge.php` | Add `log_tmi_action()` call after GS purge |
| `scripts/ecfmp_poll_daemon.php` | Add `log_tmi_action()` calls for flow measure events |
| `scripts/startup.sh` | Register two new daemons in downstream section |
| `load/nav.php` | Add NAS Event Log link to navigation |

---

### Task 1: SQL Migration — Create All 10 Tables

**Files:**
- Create: `database/migrations/tmi/055_tmi_operations_analytics.sql`

- [ ] **Step 1: Create the migration file with Layer 1 tables (tmi_log_core + 4 satellites)**

Create `database/migrations/tmi/055_tmi_operations_analytics.sql` with the following content (showing the first half — Layer 1 tables):

```sql
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
```

- [ ] **Step 2: Deploy the migration to VATSIM_TMI**

Connect to VATSIM_TMI using `jpeterson` admin credentials (adl_api_user lacks CREATE TABLE):

```
Server: vatsim.database.windows.net
Database: VATSIM_TMI
User: jpeterson
Password: Jhp21012
```

Run: Execute the full SQL migration file in SSMS or Azure Query Editor.

Expected: 10 tables created, 25 seed rows in `tmi_cause_taxonomy`, all PRINT messages showing "Created:" (not "Exists:").

- [ ] **Step 3: Verify tables exist**

Run this verification query against VATSIM_TMI:

```sql
SELECT t.name, p.rows
FROM sys.tables t
JOIN sys.partitions p ON t.object_id = p.object_id AND p.index_id IN (0,1)
WHERE t.name LIKE 'tmi_log_%'
   OR t.name LIKE 'tmi_cause_%'
   OR t.name LIKE 'tmi_delay_%'
   OR t.name LIKE 'tmi_facility_%'
   OR t.name LIKE 'tmi_ops_%'
ORDER BY t.name;
```

Expected: 10 rows — `tmi_cause_taxonomy` with 25 rows, all others with 0.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tmi/055_tmi_operations_analytics.sql
git commit -m "feat(tmi): add migration 055 — 10 tables for TMI operations & analytics

Layer 1: tmi_log_core + 4 satellite tables (scope, parameters, impact, references)
Layer 2: tmi_cause_taxonomy (25 seed rows) + tmi_delay_attribution
Layer 3: tmi_facility_stats_hourly/daily + tmi_ops_performance"
```

---

### Task 2: PHP Helper — `load/tmi_log.php`

**Files:**
- Create: `load/tmi_log.php`

This file provides `log_tmi_action()` which supports both `sqlsrv` connections (GDT endpoints via `$conn_tmi`) and PDO connections (publish.php via `$tmiConn`). The function detects connection type via `$conn instanceof PDO`.

- [ ] **Step 1: Create `load/tmi_log.php`**

```php
<?php
/**
 * TMI Unified Log Helper
 *
 * Provides log_tmi_action() for all TMI endpoints to write to the
 * tmi_log_core + satellite tables. Supports both sqlsrv and PDO connections.
 *
 * @package PERTI
 * @subpackage Load
 * @version 1.0.0
 * @date 2026-03-30
 */

/**
 * Log a TMI action to the unified log tables.
 *
 * @param mixed $conn  Database connection — sqlsrv resource OR PDO instance
 * @param array $core  Required. Keys: action_category, action_type, summary.
 *                     Optional: program_type, severity, source_system, event_utc,
 *                     user_cid, user_name, user_position, user_oi, session_id,
 *                     issuing_facility, issuing_org
 * @param array|null $scope     Optional tmi_log_scope fields
 * @param array|null $params    Optional tmi_log_parameters fields
 * @param array|null $impact    Optional tmi_log_impact fields
 * @param array|null $refs      Optional tmi_log_references fields
 * @return string|null The log_id UUID on success, null on failure
 */
function log_tmi_action($conn, array $core, ?array $scope = null,
                        ?array $params = null, ?array $impact = null,
                        ?array $refs = null): ?string
{
    if (!$conn) {
        error_log('[tmi_log] No connection provided');
        return null;
    }

    $is_pdo = ($conn instanceof PDO);

    try {
        // Generate UUID for log_id
        $log_id = _tmi_log_uuid($conn, $is_pdo);
        if (!$log_id) {
            error_log('[tmi_log] Failed to generate UUID');
            return null;
        }

        // Auto-populate authoring from session if not provided
        if (empty($core['user_cid']) && isset($_SESSION['VATSIM_CID'])) {
            $core['user_cid'] = $_SESSION['VATSIM_CID'];
        }
        if (empty($core['user_name']) && isset($_SESSION['VATSIM_NAME'])) {
            $core['user_name'] = $_SESSION['VATSIM_NAME'];
        }
        if (empty($core['session_id']) && session_id()) {
            $core['session_id'] = session_id();
        }
        if (empty($core['source_system'])) {
            $core['source_system'] = 'PERTI_WEB';
        }

        // Insert core row (required)
        $core_sql = "INSERT INTO dbo.tmi_log_core
            (log_id, action_category, action_type, program_type, severity,
             source_system, summary, event_utc,
             user_cid, user_name, user_position, user_oi,
             session_id, issuing_facility, issuing_org)
            VALUES (?, ?, ?, ?, ?,
                    ?, ?, ISNULL(?, SYSUTCDATETIME()),
                    ?, ?, ?, ?,
                    ?, ?, ?)";
        $core_params = [
            $log_id,
            $core['action_category'],
            $core['action_type'],
            $core['program_type'] ?? null,
            $core['severity'] ?? 'INFO',
            $core['source_system'],
            $core['summary'],
            $core['event_utc'] ?? null,
            $core['user_cid'] ?? null,
            $core['user_name'] ?? null,
            $core['user_position'] ?? null,
            $core['user_oi'] ?? null,
            $core['session_id'] ?? null,
            $core['issuing_facility'] ?? null,
            $core['issuing_org'] ?? null
        ];
        _tmi_log_exec($conn, $is_pdo, $core_sql, $core_params);

        // Insert scope row (optional)
        if ($scope) {
            $scope_sql = "INSERT INTO dbo.tmi_log_scope
                (log_id, ctl_element, element_type, facility, traffic_flow,
                 via_fix, scope_airports, scope_tiers, scope_altitude,
                 scope_aircraft_type, scope_carriers, scope_equipment,
                 exclusions, flt_incl_type, affected_facilities,
                 dep_facilities, dep_scope, filters_json)
                VALUES (?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?)";
            $scope_params = [
                $log_id,
                $scope['ctl_element'] ?? null,
                $scope['element_type'] ?? null,
                $scope['facility'] ?? null,
                $scope['traffic_flow'] ?? null,
                $scope['via_fix'] ?? null,
                $scope['scope_airports'] ?? null,
                $scope['scope_tiers'] ?? null,
                $scope['scope_altitude'] ?? null,
                $scope['scope_aircraft_type'] ?? null,
                $scope['scope_carriers'] ?? null,
                $scope['scope_equipment'] ?? null,
                $scope['exclusions'] ?? null,
                $scope['flt_incl_type'] ?? null,
                $scope['affected_facilities'] ?? null,
                $scope['dep_facilities'] ?? null,
                $scope['dep_scope'] ?? null,
                $scope['filters_json'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $scope_sql, $scope_params);
        }

        // Insert parameters row (optional)
        if ($params) {
            $params_sql = "INSERT INTO dbo.tmi_log_parameters
                (log_id, effective_start_utc, effective_end_utc,
                 rate_value, rate_unit, spacing_type, program_rate,
                 rates_hourly_json, rates_quarter_json, delay_cap,
                 cause_category, cause_detail, impacting_condition, prob_extension,
                 delay_type, delay_minutes, delay_trend,
                 holding_status, holding_fix, aircraft_holding,
                 weather_conditions, arrival_runways, departure_runways, config_name,
                 gs_probability, gs_release_rate,
                 cancellation_reason, cancellation_edct_action, cancellation_notes,
                 meter_point, freeze_horizon, compression_enabled,
                 ntml_formatted, remarks, detail_json, qualifiers)
                VALUES (?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?)";
            $params_vals = [
                $log_id,
                $params['effective_start_utc'] ?? null,
                $params['effective_end_utc'] ?? null,
                $params['rate_value'] ?? null,
                $params['rate_unit'] ?? null,
                $params['spacing_type'] ?? null,
                $params['program_rate'] ?? null,
                $params['rates_hourly_json'] ?? null,
                $params['rates_quarter_json'] ?? null,
                $params['delay_cap'] ?? null,
                $params['cause_category'] ?? null,
                $params['cause_detail'] ?? null,
                $params['impacting_condition'] ?? null,
                $params['prob_extension'] ?? null,
                $params['delay_type'] ?? null,
                $params['delay_minutes'] ?? null,
                $params['delay_trend'] ?? null,
                $params['holding_status'] ?? null,
                $params['holding_fix'] ?? null,
                $params['aircraft_holding'] ?? null,
                $params['weather_conditions'] ?? null,
                $params['arrival_runways'] ?? null,
                $params['departure_runways'] ?? null,
                $params['config_name'] ?? null,
                $params['gs_probability'] ?? null,
                $params['gs_release_rate'] ?? null,
                $params['cancellation_reason'] ?? null,
                $params['cancellation_edct_action'] ?? null,
                $params['cancellation_notes'] ?? null,
                $params['meter_point'] ?? null,
                $params['freeze_horizon'] ?? null,
                $params['compression_enabled'] ?? null,
                $params['ntml_formatted'] ?? null,
                $params['remarks'] ?? null,
                $params['detail_json'] ?? null,
                $params['qualifiers'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $params_sql, $params_vals);
        }

        // Insert impact row (optional)
        if ($impact) {
            $impact_sql = "INSERT INTO dbo.tmi_log_impact
                (log_id, total_flights, controlled_flights, exempt_flights,
                 airborne_flights, popup_flights,
                 avg_delay_min, max_delay_min, total_delay_min,
                 cumulative_total_delay, cumulative_max_delay,
                 demand_rate, capacity_rate,
                 reversal_count, reversal_pct, gaming_flags_count,
                 compliance_rate, comments)
                VALUES (?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?)";
            $impact_params = [
                $log_id,
                $impact['total_flights'] ?? null,
                $impact['controlled_flights'] ?? null,
                $impact['exempt_flights'] ?? null,
                $impact['airborne_flights'] ?? null,
                $impact['popup_flights'] ?? null,
                $impact['avg_delay_min'] ?? null,
                $impact['max_delay_min'] ?? null,
                $impact['total_delay_min'] ?? null,
                $impact['cumulative_total_delay'] ?? null,
                $impact['cumulative_max_delay'] ?? null,
                $impact['demand_rate'] ?? null,
                $impact['capacity_rate'] ?? null,
                $impact['reversal_count'] ?? null,
                $impact['reversal_pct'] ?? null,
                $impact['gaming_flags_count'] ?? null,
                $impact['compliance_rate'] ?? null,
                $impact['comments'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $impact_sql, $impact_params);
        }

        // Insert references row (optional)
        if ($refs) {
            $refs_sql = "INSERT INTO dbo.tmi_log_references
                (log_id, program_id, entry_id, advisory_id, reroute_id,
                 slot_id, flight_uid, proposal_id, flow_measure_id,
                 delay_entry_id, airport_config_id,
                 parent_log_id, supersedes_log_id, supersedes_entry_id,
                 advisory_number, cancel_advisory_num, revision_number,
                 source_type, source_id, source_channel, content_hash,
                 discord_message_id, discord_channel_id, discord_channel_purpose,
                 coordination_log_id)
                VALUES (?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?)";
            $refs_params = [
                $log_id,
                $refs['program_id'] ?? null,
                $refs['entry_id'] ?? null,
                $refs['advisory_id'] ?? null,
                $refs['reroute_id'] ?? null,
                $refs['slot_id'] ?? null,
                $refs['flight_uid'] ?? null,
                $refs['proposal_id'] ?? null,
                $refs['flow_measure_id'] ?? null,
                $refs['delay_entry_id'] ?? null,
                $refs['airport_config_id'] ?? null,
                $refs['parent_log_id'] ?? null,
                $refs['supersedes_log_id'] ?? null,
                $refs['supersedes_entry_id'] ?? null,
                $refs['advisory_number'] ?? null,
                $refs['cancel_advisory_num'] ?? null,
                $refs['revision_number'] ?? null,
                $refs['source_type'] ?? null,
                $refs['source_id'] ?? null,
                $refs['source_channel'] ?? null,
                $refs['content_hash'] ?? null,
                $refs['discord_message_id'] ?? null,
                $refs['discord_channel_id'] ?? null,
                $refs['discord_channel_purpose'] ?? null,
                $refs['coordination_log_id'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $refs_sql, $refs_params);
        }

        return $log_id;

    } catch (Exception $e) {
        error_log('[tmi_log] Failed to log action: ' . $e->getMessage());
        return null;
    }
}

/**
 * Generate a UUID via SQL Server NEWID().
 * @internal
 */
function _tmi_log_uuid($conn, bool $is_pdo): ?string
{
    if ($is_pdo) {
        $stmt = $conn->query("SELECT CAST(NEWID() AS NVARCHAR(36))");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    $stmt = sqlsrv_query($conn, "SELECT CAST(NEWID() AS NVARCHAR(36))");
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    return $row ? $row[0] : null;
}

/**
 * Execute a parameterized INSERT via the correct driver.
 * @internal
 */
function _tmi_log_exec($conn, bool $is_pdo, string $sql, array $params): void
{
    if ($is_pdo) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return;
    }

    // sqlsrv path
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $msg = $errors ? $errors[0]['message'] : 'Unknown error';
        throw new RuntimeException('[tmi_log] sqlsrv_query failed: ' . $msg);
    }
    sqlsrv_free_stmt($stmt);
}
```

- [ ] **Step 2: Verify syntax by including it**

Add a temporary test by accessing any GDT endpoint and checking logs for errors. The file should be parseable by PHP without errors.

Run: `php -l load/tmi_log.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add load/tmi_log.php
git commit -m "feat(tmi): add log_tmi_action() helper with dual sqlsrv/PDO support

Supports both sqlsrv (GDT endpoints) and PDO (publish.php) connections.
Inserts into tmi_log_core + optional satellite tables (scope, params, impact, refs).
Auto-populates authoring fields from session context."
```

---

### Task 3: Wire GDT Program Endpoints

**Files:**
- Modify: `api/gdt/common.php` (add require_once)
- Modify: `api/gdt/programs/activate.php`
- Modify: `api/gdt/programs/cancel.php`
- Modify: `api/gdt/programs/create.php`
- Modify: `api/gdt/programs/compress.php`
- Modify: `api/gdt/programs/reoptimize.php`
- Modify: `api/gdt/programs/extend.php`
- Modify: `api/gdt/programs/revise.php`
- Modify: `api/gdt/programs/transition.php`
- Modify: `api/gdt/programs/purge.php`
- Modify: `api/gdt/programs/power_run.php`
- Modify: `api/gdt/programs/simulate.php`
- Modify: `api/gdt/programs/blanket.php`
- Modify: `api/gdt/programs/ecr.php`
- Modify: `api/gdt/programs/publish.php`
- Modify: `api/gdt/programs/submit_proposal.php`

All GDT endpoints use `$conn_tmi` (sqlsrv) via `common.php`. The pattern is identical for each: add a `log_tmi_action($conn_tmi, ...)` call just before the final `respond_json()` call.

- [ ] **Step 1: Add require_once to `api/gdt/common.php`**

After line 66 (`require_once connect.php`), add:

```php
require_once(__DIR__ . '/../../load/tmi_log.php');
```

- [ ] **Step 2: Wire `activate.php`**

In `api/gdt/programs/activate.php`, add before the final `respond_json(200, ...)` call (before line 237):

```php
// Log to TMI unified log
log_tmi_action($conn_tmi, [
    'action_category' => 'PROGRAM',
    'action_type'     => 'ACTIVATE',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => 'GDP activated: ' . ($program['ctl_element'] ?? '') . ' (' . ($program['program_type'] ?? '') . ')',
    'user_cid'        => $auth_cid,
    'user_name'       => $activated_by,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element'  => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
    'facility'     => $program['ctl_element'] ?? null,
], [
    'effective_start_utc' => $program['start_utc'] ?? null,
    'effective_end_utc'   => $program['end_utc'] ?? null,
    'program_rate'        => $program['program_rate'] ?? null,
    'rates_hourly_json'   => $program['rates_hourly_json'] ?? null,
    'rates_quarter_json'  => $program['rates_quarter_json'] ?? null,
    'cause_category'      => $program['impacting_condition'] ?? null,
], [
    'total_flights'      => $total_flights,
    'controlled_flights' => $controlled_count,
    'exempt_flights'     => $exempt_count,
    'airborne_flights'   => $airborne_count,
    'avg_delay_min'      => $avg_delay,
    'max_delay_min'      => $max_delay,
    'total_delay_min'    => $total_delay,
], [
    'program_id'      => $program_id,
    'advisory_number' => $adv_number,
]);
```

- [ ] **Step 3: Wire `cancel.php`**

Read `api/gdt/programs/cancel.php` to find the `respond_json()` call. Add before it:

```php
log_tmi_action($conn_tmi, [
    'action_category' => 'PROGRAM',
    'action_type'     => 'CANCEL',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => 'GDP cancelled: ' . ($program['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element' => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], [
    'cancellation_reason'      => $cancel_reason ?? null,
    'cancellation_edct_action' => $edct_action ?? null,
    'cancellation_notes'       => $cancel_notes ?? null,
], null, [
    'program_id'         => $program_id,
    'cancel_advisory_num' => $cancel_adv_number ?? null,
]);
```

- [ ] **Step 4: Wire `create.php`**

Read `api/gdt/programs/create.php` to find the `respond_json()` call. Add before it:

```php
log_tmi_action($conn_tmi, [
    'action_category' => 'PROGRAM',
    'action_type'     => 'CREATE',
    'program_type'    => $payload['program_type'] ?? null,
    'summary'         => 'GDP created: ' . ($payload['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
], [
    'ctl_element' => $payload['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], [
    'effective_start_utc' => $payload['start_utc'] ?? null,
    'effective_end_utc'   => $payload['end_utc'] ?? null,
    'program_rate'        => $payload['program_rate'] ?? null,
], null, [
    'program_id' => $program_id,
]);
```

- [ ] **Step 5: Wire remaining GDT program endpoints**

For each of the following endpoints, read the file, find the success `respond_json()` call, and add a `log_tmi_action()` call just before it. Follow the same pattern as above, adapting `action_type` and `summary`:

| File | action_type | summary prefix |
|------|-------------|---------------|
| `compress.php` | `COMPRESS` | `GDP compressed:` |
| `reoptimize.php` | `REOPTIMIZE` | `GDP reoptimized:` |
| `extend.php` | `EXTEND` | `GDP extended:` |
| `revise.php` | `REVISE` | `GDP revised:` |
| `transition.php` | `TRANSITION` | `GS→GDP transition:` |
| `purge.php` | `PURGE` | `GDP purged:` |
| `power_run.php` | `SIMULATE` | `GDP power run:` |
| `simulate.php` | `SIMULATE` | `GDP simulation:` |
| `publish.php` | `PUBLISH` | `GDP published:` |
| `submit_proposal.php` | `SUBMIT` (category: `COORDINATION`) | `Proposal submitted:` |

For `blanket.php` and `ecr.php`, use `action_category = 'SLOT'`:

| File | action_category | action_type | summary prefix |
|------|----------------|-------------|---------------|
| `blanket.php` | `SLOT` | `BLANKET` | `Blanket EDCT issued:` |
| `ecr.php` | `SLOT` | `ECR` | `ECR applied:` |

Each follows this template (adapting variables based on what's available in each file):

```php
log_tmi_action($conn_tmi, [
    'action_category' => '<CATEGORY>',
    'action_type'     => '<TYPE>',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => '<summary prefix> ' . ($program['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element' => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], null, null, [
    'program_id' => $program_id,
]);
```

- [ ] **Step 6: Verify by activating a test GDP**

1. Create a test GDP on a quiet airport via `api/gdt/programs/create.php`
2. Activate it via `api/gdt/programs/activate.php`
3. Query VATSIM_TMI: `SELECT * FROM tmi_log_core ORDER BY log_seq DESC`
4. Verify 2 rows (CREATE + ACTIVATE) with correct action_category, action_type, program_type
5. Verify satellite tables have matching rows: `SELECT * FROM tmi_log_references WHERE program_id = <test_id>`
6. Cancel/purge the test GDP

- [ ] **Step 7: Commit**

```bash
git add api/gdt/common.php api/gdt/programs/*.php
git commit -m "feat(tmi): wire all GDT program endpoints to TMI unified log

16 GDT endpoints now call log_tmi_action() before respond_json().
Covers: create, activate, cancel, compress, reoptimize, extend, revise,
transition, purge, power_run, simulate, blanket, ecr, publish, submit_proposal."
```

---

### Task 4: Wire TMI Publish API (`api/mgt/tmi/publish.php`)

**Files:**
- Modify: `api/mgt/tmi/publish.php`

This endpoint uses its own PDO connection (`$tmiConn`), NOT `$conn_tmi` from connect.php. The `log_tmi_action()` function handles PDO via the `$conn instanceof PDO` check.

- [ ] **Step 1: Add require_once at top of publish.php**

After the existing requires (around line 85 area, after `require_once coordination_log.php`), add:

```php
require_once(__DIR__ . '/../../../load/tmi_log.php');
```

- [ ] **Step 2: Add logging after each entry is saved to database**

Inside the `foreach ($entries as $index => $entry)` loop, after the database save succeeds (after `$result['databaseId'] = $databaseId;` at line ~284), add logging calls.

Insert after line 285 (`tmi_debug_log("Entry {$index} saved to database", ...)`):

```php
// Log to TMI unified log
if ($databaseId && $tmiConn) {
    try {
        $actionCategory = 'ENTRY';
        $actionType = 'CREATE';
        if ($isAdvisory) {
            $actionCategory = 'ADVISORY';
        } elseif ($entrySubType === 'DELAY') {
            $actionCategory = 'DELAY_REPORT';
        } elseif ($entrySubType === 'CONFIG') {
            $actionCategory = 'CONFIG_CHANGE';
        } elseif ($entrySubType === 'CANCEL') {
            $actionType = 'CANCEL';
        }

        $entryData = $entry['data'] ?? [];
        $ctlElement = strtoupper($entryData['ctl_element'] ?? '');

        log_tmi_action($tmiConn, [
            'action_category' => $actionCategory,
            'action_type'     => $actionType,
            'program_type'    => $entrySubType,
            'summary'         => $actionCategory . ' ' . $actionType . ': '
                               . $entrySubType . ' ' . $ctlElement,
            'source_system'   => $production ? 'PERTI_WEB' : 'PERTI_STAGING',
            'user_cid'        => $userCid,
            'user_name'       => $userName,
            'issuing_org'     => $org_code ?? null,
        ], [
            'ctl_element'  => $ctlElement ?: null,
            'element_type' => !empty($ctlElement) ? 'AIRPORT' : null,
            'facility'     => $entryData['facility'] ?? null,
            'via_fix'      => $entryData['via_fix'] ?? null,
            'scope_airports' => $entryData['scope_airports'] ?? null,
        ], [
            'effective_start_utc' => $entryData['effective_start'] ?? null,
            'effective_end_utc'   => $entryData['effective_end'] ?? null,
            'rate_value'          => $entryData['rate_value'] ?? null,
            'rate_unit'           => $entryData['rate_unit'] ?? null,
            'spacing_type'        => $entryData['spacing_type'] ?? null,
            'cause_category'      => $entryData['cause_category'] ?? null,
            'cause_detail'        => $entryData['cause_detail'] ?? null,
            'impacting_condition' => $entryData['impacting_condition'] ?? null,
            'delay_type'          => $entryData['delay_type'] ?? null,
            'delay_minutes'       => $entryData['delay_minutes'] ?? null,
            'delay_trend'         => $entryData['delay_trend'] ?? null,
            'weather_conditions'  => $entryData['weather_conditions'] ?? null,
            'arrival_runways'     => $entryData['arrival_runways'] ?? null,
            'departure_runways'   => $entryData['departure_runways'] ?? null,
            'ntml_formatted'      => $messageContent,
            'remarks'             => $entryData['remarks'] ?? null,
        ], null, [
            'entry_id'    => $isAdvisory ? null : $databaseId,
            'advisory_id' => $isAdvisory ? $databaseId : null,
            'source_type' => $production ? 'production' : 'staging',
        ]);
    } catch (Exception $e) {
        tmi_debug_log("TMI log failed for entry {$index}: " . $e->getMessage());
    }
}
```

- [ ] **Step 3: Verify by publishing a test NTML entry**

1. On the PERTI site, navigate to tmi-publish.php
2. Create and publish a test MIT entry to staging
3. Query: `SELECT TOP 5 * FROM tmi_log_core ORDER BY log_seq DESC`
4. Verify the ENTRY/CREATE row exists with correct program_type, summary, user_cid
5. Query: `SELECT * FROM tmi_log_parameters WHERE log_id = '<log_id>'`
6. Verify scope and parameters populated

- [ ] **Step 4: Commit**

```bash
git add api/mgt/tmi/publish.php
git commit -m "feat(tmi): wire publish.php to TMI unified log (PDO path)

Logs ENTRY/CREATE, ADVISORY/CREATE, DELAY_REPORT/CREATE, CONFIG_CHANGE/CREATE,
and ENTRY/CANCEL actions. Uses PDO connection ($tmiConn) for tmi_log_action()."
```

---

### Task 5: Wire TMI Management Endpoints

**Files:**
- Modify: `api/mgt/tmi/promote.php`
- Modify: `api/mgt/tmi/coordinate.php`
- Modify: `api/mgt/tmi/cancel.php`
- Modify: `api/mgt/tmi/edit.php`
- Modify: `api/mgt/tmi/reroutes/post.php`
- Modify: `api/mgt/tmi/reroutes/bulk.php`
- Modify: `api/mgt/tmi/reroutes/delete.php`
- Modify: `api/mgt/tmi/ground_stops/post.php`

These endpoints use varying connection patterns. Read each file to determine whether it uses PDO (`$tmiConn`) or sqlsrv (`$conn_tmi`), and which variables hold the entity data.

- [ ] **Step 1: Read each endpoint to identify connection type and available variables**

For each file in the list above:
1. Read the first 40 lines to see what's included
2. Search for `respond_json` or `json_encode` success responses
3. Note the connection variable used for TMI queries
4. Note available entity data variables

- [ ] **Step 2: Add require_once and log_tmi_action() to each endpoint**

For endpoints that include `load/config.php` + `load/connect.php`, add:
```php
require_once(__DIR__ . '/../../../load/tmi_log.php');
```

For endpoints that create their own PDO (like publish.php), add:
```php
require_once(__DIR__ . '/../../../load/tmi_log.php');
```

Then add a `log_tmi_action()` call before the success response in each file:

| File | action_category | action_type | Connection |
|------|----------------|-------------|-----------|
| `promote.php` | ENTRY or ADVISORY | PROMOTE | Check file |
| `coordinate.php` (submit) | COORDINATION | SUBMIT | Check file |
| `coordinate.php` (approve) | COORDINATION | APPROVE | Check file |
| `coordinate.php` (deny) | COORDINATION | DENY | Check file |
| `coordinate.php` (rescind) | COORDINATION | RESCIND | Check file |
| `cancel.php` | PROGRAM or ENTRY | CANCEL | Check file |
| `edit.php` | PROGRAM or ENTRY | UPDATE | Check file |
| `reroutes/post.php` | REROUTE | CREATE | Check file |
| `reroutes/bulk.php` | REROUTE | CREATE | Check file |
| `reroutes/delete.php` | REROUTE | CANCEL | Check file |
| `ground_stops/post.php` | PROGRAM | CREATE | Check file |

- [ ] **Step 3: Verify by testing a coordination action**

1. Submit a test proposal via `coordinate.php`
2. Query: `SELECT TOP 5 * FROM tmi_log_core WHERE action_category = 'COORDINATION' ORDER BY log_seq DESC`
3. Verify SUBMIT row exists

- [ ] **Step 4: Commit**

```bash
git add api/mgt/tmi/promote.php api/mgt/tmi/coordinate.php api/mgt/tmi/cancel.php \
       api/mgt/tmi/edit.php api/mgt/tmi/reroutes/*.php api/mgt/tmi/ground_stops/post.php
git commit -m "feat(tmi): wire TMI management endpoints to unified log

Covers: promote, coordinate (submit/approve/deny/rescind), cancel, edit,
reroutes (post/bulk/delete), ground_stops/post."
```

---

### Task 6: Wire GS Endpoints and Daemons

**Files:**
- Modify: `api/tmi/gs/create.php`
- Modify: `api/tmi/gs/activate.php`
- Modify: `api/tmi/gs/extend.php`
- Modify: `api/tmi/gs/purge.php`
- Modify: `scripts/ecfmp_poll_daemon.php`

- [ ] **Step 1: Read GS endpoint patterns**

Read `api/tmi/gs/common.php` (if it exists) or `api/tmi/gs/create.php` to understand the connection pattern and includes.

- [ ] **Step 2: Wire GS endpoints**

For each GS endpoint, add:
```php
require_once(__DIR__ . '/../../../load/tmi_log.php');
```

And a `log_tmi_action()` call before the success response:

| File | action_type | summary |
|------|-------------|---------|
| `gs/create.php` | CREATE | `GS created: <airport>` |
| `gs/activate.php` | ACTIVATE | `GS activated: <airport>` |
| `gs/extend.php` | EXTEND | `GS extended: <airport>` |
| `gs/purge.php` | PURGE | `GS purged: <airport>` |

All use `action_category = 'PROGRAM'` and `program_type = 'GS'`.

- [ ] **Step 3: Wire `ecfmp_poll_daemon.php`**

Read `scripts/ecfmp_poll_daemon.php` and find where new flow measures are inserted and where status changes are detected. Add `require_once` for `load/tmi_log.php` and add logging calls:

For new flow measures:
```php
log_tmi_action($conn_tmi, [
    'action_category' => 'FLOW_MEASURE',
    'action_type'     => 'CREATE',
    'summary'         => 'ECFMP flow measure: ' . ($measure['ident'] ?? ''),
    'source_system'   => 'ECFMP_DAEMON',
    'issuing_org'     => $measure['fir'] ?? null,
], null, null, null, [
    'flow_measure_id' => $measure_id,
    'source_type'     => 'ecfmp',
    'source_id'       => $measure['id'] ?? null,
]);
```

For status changes (ACTIVATE, EXPIRE, WITHDRAW):
```php
log_tmi_action($conn_tmi, [
    'action_category' => 'FLOW_MEASURE',
    'action_type'     => strtoupper($new_status),
    'summary'         => 'ECFMP measure ' . strtolower($new_status) . ': ' . ($measure['ident'] ?? ''),
    'source_system'   => 'ECFMP_DAEMON',
], null, null, null, [
    'flow_measure_id' => $measure_id,
]);
```

- [ ] **Step 4: Commit**

```bash
git add api/tmi/gs/*.php scripts/ecfmp_poll_daemon.php
git commit -m "feat(tmi): wire GS endpoints and ECFMP daemon to unified log

GS lifecycle: create, activate, extend, purge.
ECFMP: flow measure create, activate, expire, withdraw."
```

---

### Task 7: Delay Attribution Daemon

**Files:**
- Create: `scripts/tmi/delay_attribution_daemon.php`

- [ ] **Step 1: Create the daemon**

```php
<?php
/**
 * TMI Delay Attribution Daemon
 *
 * Computes per-flight delay attribution by comparing EDCT/ETE baselines
 * against actual OOOI times. Reads from VATSIM_ADL, writes to VATSIM_TMI.
 *
 * Usage:
 *   php delay_attribution_daemon.php --loop [--interval=60] [--debug]
 *
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 * @date 2026-03-30
 */

$opts = getopt('', ['loop', 'interval:', 'debug', 'once']);
$loop_mode = isset($opts['loop']);
$interval = isset($opts['interval']) ? (int)$opts['interval'] : 60;
$debug = isset($opts['debug']);
$once = isset($opts['once']);

require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

function delay_log(string $msg, string $level = 'INFO'): void {
    $ts = gmdate('Y-m-d H:i:s');
    echo "[{$ts} UTC] [{$level}] {$msg}\n";
}

delay_log("Delay attribution daemon starting (interval={$interval}s, loop=" . ($loop_mode ? 'yes' : 'no') . ")");

// Load cause taxonomy for lookups
function load_cause_map($conn_tmi): array {
    $sql = "SELECT cause_id, cause_category, cause_subcategory FROM dbo.tmi_cause_taxonomy WHERE is_active = 1";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    $map = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = $row['cause_category'] . '/' . $row['cause_subcategory'];
        $map[$key] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $map;
}

function run_attribution_cycle($conn_adl, $conn_tmi, bool $debug): int {
    $cause_map = load_cause_map($conn_tmi);
    $processed = 0;

    // Step 1: Find TMI-controlled flights with OOOI data that need attribution
    // Flights with EDCT that have departed (out_utc set) or arrived (in_utc set)
    $adl_sql = "
        SELECT
            fc.flight_uid, fc.callsign, fc.program_id, fc.program_type,
            fc.program_delay_min, fc.ctl_element,
            ft.etd_utc, ft.edct_utc, ft.out_utc, ft.off_utc,
            ft.on_utc, ft.in_utc, ft.eta_utc, ft.ata_utc,
            ft.ete_minutes, ft.first_seen_utc,
            fcore.dep_icao, fcore.arr_icao, fcore.dep_artcc, fcore.arr_artcc,
            fa.icao_type, fa.carrier_code
        FROM dbo.adl_flight_tmi fc
        JOIN dbo.adl_flight_times ft ON fc.flight_uid = ft.flight_uid
        JOIN dbo.adl_flight_core fcore ON fc.flight_uid = fcore.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft fa ON fc.flight_uid = fa.flight_uid
        WHERE fc.program_id IS NOT NULL
          AND fc.program_delay_min > 0
          AND ft.out_utc IS NOT NULL
          AND fcore.is_active = 1
    ";
    $adl_stmt = sqlsrv_query($conn_adl, $adl_sql);
    if ($adl_stmt === false) {
        delay_log('Failed to query ADL flights: ' . (sqlsrv_errors()[0]['message'] ?? 'unknown'), 'ERROR');
        return 0;
    }

    $flights = [];
    while ($row = sqlsrv_fetch_array($adl_stmt, SQLSRV_FETCH_ASSOC)) {
        $flights[] = $row;
    }
    sqlsrv_free_stmt($adl_stmt);

    if (empty($flights)) {
        if ($debug) delay_log('No flights needing attribution');
        return 0;
    }

    delay_log('Found ' . count($flights) . ' flights for attribution');

    // Step 2: Batch-fetch taxi references for relevant airports
    $airports = array_unique(array_filter(array_column($flights, 'dep_icao')));
    $taxi_refs = [];
    if (!empty($airports)) {
        $placeholders = implode(',', array_fill(0, count($airports), '?'));
        $taxi_sql = "SELECT airport_icao, unimpeded_taxi_sec FROM dbo.airport_taxi_reference WHERE airport_icao IN ({$placeholders})";
        $taxi_stmt = sqlsrv_query($conn_adl, $taxi_sql, array_values($airports));
        if ($taxi_stmt) {
            while ($row = sqlsrv_fetch_array($taxi_stmt, SQLSRV_FETCH_ASSOC)) {
                $taxi_refs[$row['airport_icao']] = (int)$row['unimpeded_taxi_sec'];
            }
            sqlsrv_free_stmt($taxi_stmt);
        }
    }

    // Step 3: Compute attribution for each flight
    $batch = [];
    foreach ($flights as $f) {
        $uid = $f['flight_uid'];

        // TMI_HOLD: EDCT minus original ETD
        if ($f['edct_utc'] && $f['etd_utc']) {
            $edct_ts = ($f['edct_utc'] instanceof DateTime) ? $f['edct_utc']->getTimestamp() : strtotime($f['edct_utc']);
            $etd_ts = ($f['etd_utc'] instanceof DateTime) ? $f['etd_utc']->getTimestamp() : strtotime($f['etd_utc']);
            $hold_min = round(($edct_ts - $etd_ts) / 60, 1);
            if ($hold_min > 0) {
                $cause_key = 'TMI/' . ($f['program_type'] ?? 'GDP');
                $cause = $cause_map[$cause_key] ?? $cause_map['OTHER/UNATTRIBUTED'];
                $batch[] = [
                    $uid, $f['callsign'], $f['dep_icao'], $f['arr_icao'],
                    'GATE', $hold_min,
                    $f['etd_utc'], $f['edct_utc'],
                    $cause['cause_id'], $cause['cause_category'], $cause['cause_subcategory'],
                    $f['program_id'], null, null,
                    $f['ctl_element'], null,
                    $f['arr_artcc'], $f['dep_artcc'],
                    $f['icao_type'], $f['carrier_code'],
                    'EDCT_DIFF', 'HIGH'
                ];
            }
        }

        // TAXI_EXCESS: actual taxi minus unimpeded reference
        if ($f['off_utc'] && $f['out_utc'] && isset($taxi_refs[$f['dep_icao']])) {
            $off_ts = ($f['off_utc'] instanceof DateTime) ? $f['off_utc']->getTimestamp() : strtotime($f['off_utc']);
            $out_ts = ($f['out_utc'] instanceof DateTime) ? $f['out_utc']->getTimestamp() : strtotime($f['out_utc']);
            $actual_taxi = $off_ts - $out_ts;
            $unimpeded = $taxi_refs[$f['dep_icao']];
            $excess_min = round(($actual_taxi - $unimpeded) / 60, 1);
            if ($excess_min > 1.0) {
                $cause = $cause_map['OTHER/UNATTRIBUTED'] ?? ['cause_id' => 22, 'cause_category' => 'OTHER', 'cause_subcategory' => 'UNATTRIBUTED'];
                $batch[] = [
                    $uid, $f['callsign'], $f['dep_icao'], $f['arr_icao'],
                    'TAXI_OUT', $excess_min,
                    null, null,
                    $cause['cause_id'], $cause['cause_category'], $cause['cause_subcategory'],
                    null, null, null,
                    $f['dep_icao'], null,
                    $f['arr_artcc'], $f['dep_artcc'],
                    $f['icao_type'], $f['carrier_code'],
                    'TAXI_REFERENCE', 'HIGH'
                ];
            }
        }

        $processed++;
    }

    // Step 4: Mark old attributions as superseded
    if (!empty($batch)) {
        $uids = array_unique(array_column($batch, 0));
        $uid_list = implode(',', $uids);
        $supersede_sql = "UPDATE dbo.tmi_delay_attribution SET is_current = 0 WHERE flight_uid IN ({$uid_list}) AND is_current = 1";
        $s_stmt = sqlsrv_query($conn_tmi, $supersede_sql);
        if ($s_stmt) sqlsrv_free_stmt($s_stmt);

        // Step 5: Batch insert new attributions
        foreach (array_chunk($batch, 100) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = array_merge($params, $row);
            }
            $insert_sql = "INSERT INTO dbo.tmi_delay_attribution
                (flight_uid, callsign, dep_icao, arr_icao,
                 delay_phase, delay_minutes, baseline_utc, actual_utc,
                 cause_id, cause_category, cause_subcategory,
                 attributed_program_id, attributed_entry_id, attributed_log_id,
                 attributed_facility, attributed_org,
                 arr_facility, dep_facility, aircraft_type, carrier,
                 computation_method, confidence)
                VALUES " . implode(', ', $values);
            $i_stmt = sqlsrv_query($conn_tmi, $insert_sql, $params);
            if ($i_stmt === false) {
                delay_log('Insert failed: ' . (sqlsrv_errors()[0]['message'] ?? 'unknown'), 'ERROR');
            } else {
                sqlsrv_free_stmt($i_stmt);
            }
        }

        delay_log("Attributed " . count($batch) . " delay records for {$processed} flights");
    }

    return $processed;
}

// Main loop
do {
    try {
        $conn_adl = get_conn_adl();
        $conn_tmi = get_conn_tmi();
        $count = run_attribution_cycle($conn_adl, $conn_tmi, $debug);
        if ($debug) delay_log("Cycle complete: {$count} flights processed");
    } catch (Exception $e) {
        delay_log('Cycle error: ' . $e->getMessage(), 'ERROR');
    }

    if ($once) break;
    if ($loop_mode) sleep($interval);
} while ($loop_mode);

delay_log('Daemon exiting');
```

- [ ] **Step 2: Test with --once flag**

Run: `php scripts/tmi/delay_attribution_daemon.php --once --debug`

Expected: Output showing flight count and attribution results (or "No flights needing attribution" if no active GDPs).

- [ ] **Step 3: Commit**

```bash
git add scripts/tmi/delay_attribution_daemon.php
git commit -m "feat(tmi): add delay attribution daemon

Computes per-flight GATE (EDCT hold) and TAXI_OUT (excess taxi) delays.
Reads OOOI from VATSIM_ADL, writes to tmi_delay_attribution in VATSIM_TMI.
Supports --loop, --interval, --once, --debug flags."
```

---

### Task 8: Facility Stats Daemon

**Files:**
- Create: `scripts/tmi/facility_stats_daemon.php`

- [ ] **Step 1: Create the daemon**

```php
<?php
/**
 * TMI Facility Statistics Daemon
 *
 * Computes hourly and daily facility statistics from flight data and
 * delay attributions. Reads from VATSIM_ADL + VATSIM_TMI, writes to
 * tmi_facility_stats_hourly/daily and tmi_ops_performance in VATSIM_TMI.
 *
 * Usage:
 *   php facility_stats_daemon.php --loop [--interval=3600] [--debug]
 *
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 * @date 2026-03-30
 */

$opts = getopt('', ['loop', 'interval:', 'debug', 'once', 'hours:']);
$loop_mode = isset($opts['loop']);
$interval = isset($opts['interval']) ? (int)$opts['interval'] : 3600;
$debug = isset($opts['debug']);
$once = isset($opts['once']);
$lookback_hours = isset($opts['hours']) ? (int)$opts['hours'] : 2;

require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

function stats_log(string $msg, string $level = 'INFO'): void {
    $ts = gmdate('Y-m-d H:i:s');
    echo "[{$ts} UTC] [{$level}] {$msg}\n";
}

stats_log("Facility stats daemon starting (interval={$interval}s, lookback={$lookback_hours}h)");

function run_stats_cycle($conn_adl, $conn_tmi, int $lookback_hours, bool $debug): int {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $stats_count = 0;

    // Process each completed hour in the lookback window
    for ($h = $lookback_hours; $h >= 1; $h--) {
        $hour_start = clone $now;
        $hour_start->modify("-{$h} hours");
        $hour_start->setTime((int)$hour_start->format('H'), 0, 0);
        $hour_end = clone $hour_start;
        $hour_end->modify('+1 hour');

        $hour_str = $hour_start->format('Y-m-d H:i:s');
        $hour_end_str = $hour_end->format('Y-m-d H:i:s');

        if ($debug) stats_log("Processing hour: {$hour_str}");

        // Query arrivals and departures for this hour from ADL
        $flight_sql = "
            SELECT
                fcore.arr_icao, fcore.dep_icao, fcore.arr_artcc, fcore.dep_artcc,
                ft.ata_utc, ft.off_utc, ft.out_utc, ft.on_utc, ft.in_utc,
                ft.eta_utc, ft.etd_utc, ft.edct_utc,
                fa.icao_type, fa.carrier_code
            FROM dbo.adl_flight_core fcore
            JOIN dbo.adl_flight_times ft ON fcore.flight_uid = ft.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft fa ON fcore.flight_uid = fa.flight_uid
            WHERE (ft.ata_utc >= ? AND ft.ata_utc < ?)
               OR (ft.off_utc >= ? AND ft.off_utc < ?)
        ";
        $f_stmt = sqlsrv_query($conn_adl, $flight_sql, [$hour_str, $hour_end_str, $hour_str, $hour_end_str]);
        if ($f_stmt === false) {
            stats_log("Failed to query flights for {$hour_str}", 'ERROR');
            continue;
        }

        // Aggregate by airport
        $airport_stats = [];
        while ($f = sqlsrv_fetch_array($f_stmt, SQLSRV_FETCH_ASSOC)) {
            $arr = $f['arr_icao'];
            $dep = $f['dep_icao'];

            // Count arrival
            if ($arr && $f['ata_utc']) {
                $ata_ts = ($f['ata_utc'] instanceof DateTime) ? $f['ata_utc']->getTimestamp() : strtotime($f['ata_utc']);
                $hr_start_ts = $hour_start->getTimestamp();
                $hr_end_ts = $hour_end->getTimestamp();
                if ($ata_ts >= $hr_start_ts && $ata_ts < $hr_end_ts) {
                    if (!isset($airport_stats[$arr])) $airport_stats[$arr] = _empty_stats();
                    $airport_stats[$arr]['total_arrivals']++;
                    $airport_stats[$arr]['total_operations']++;

                    // On-time: arrival within 15 min of ETA
                    if ($f['eta_utc']) {
                        $eta_ts = ($f['eta_utc'] instanceof DateTime) ? $f['eta_utc']->getTimestamp() : strtotime($f['eta_utc']);
                        $arr_delay = ($ata_ts - $eta_ts) / 60;
                        if ($arr_delay <= 15) {
                            $airport_stats[$arr]['ontime_arrivals']++;
                        } else {
                            $airport_stats[$arr]['delayed_arrivals']++;
                        }
                        if ($arr_delay > ($airport_stats[$arr]['max_arr_delay'] ?? 0)) {
                            $airport_stats[$arr]['max_arr_delay'] = round($arr_delay, 1);
                        }
                        $airport_stats[$arr]['arr_delay_sum'] += max(0, $arr_delay);
                        $airport_stats[$arr]['arr_delay_count']++;
                    }
                }
            }

            // Count departure
            if ($dep && $f['off_utc']) {
                $off_ts = ($f['off_utc'] instanceof DateTime) ? $f['off_utc']->getTimestamp() : strtotime($f['off_utc']);
                $hr_start_ts = $hour_start->getTimestamp();
                $hr_end_ts = $hour_end->getTimestamp();
                if ($off_ts >= $hr_start_ts && $off_ts < $hr_end_ts) {
                    if (!isset($airport_stats[$dep])) $airport_stats[$dep] = _empty_stats();
                    $airport_stats[$dep]['total_departures']++;
                    $airport_stats[$dep]['total_operations']++;

                    // On-time: departure within 15 min of ETD/EDCT
                    $baseline_ts = null;
                    if ($f['edct_utc']) {
                        $baseline_ts = ($f['edct_utc'] instanceof DateTime) ? $f['edct_utc']->getTimestamp() : strtotime($f['edct_utc']);
                    } elseif ($f['etd_utc']) {
                        $baseline_ts = ($f['etd_utc'] instanceof DateTime) ? $f['etd_utc']->getTimestamp() : strtotime($f['etd_utc']);
                    }
                    if ($baseline_ts) {
                        $dep_delay = ($off_ts - $baseline_ts) / 60;
                        if ($dep_delay <= 15) {
                            $airport_stats[$dep]['ontime_departures']++;
                        } else {
                            $airport_stats[$dep]['delayed_departures']++;
                        }
                    }
                }
            }
        }
        sqlsrv_free_stmt($f_stmt);

        // UPSERT into tmi_facility_stats_hourly
        foreach ($airport_stats as $icao => $s) {
            $avg_arr = $s['arr_delay_count'] > 0 ? round($s['arr_delay_sum'] / $s['arr_delay_count'], 1) : null;
            $upsert_sql = "
                MERGE dbo.tmi_facility_stats_hourly AS t
                USING (SELECT ? AS facility, ? AS hour_utc) AS s
                ON t.facility = s.facility AND t.hour_utc = s.hour_utc
                WHEN MATCHED THEN UPDATE SET
                    total_operations = ?, total_arrivals = ?, total_departures = ?,
                    ontime_arrivals = ?, delayed_arrivals = ?,
                    ontime_departures = ?, delayed_departures = ?,
                    avg_arr_delay_min = ?, max_arr_delay_min = ?,
                    computed_utc = SYSUTCDATETIME()
                WHEN NOT MATCHED THEN INSERT
                    (facility, facility_type, airport_icao, hour_utc,
                     total_operations, total_arrivals, total_departures,
                     ontime_arrivals, delayed_arrivals,
                     ontime_departures, delayed_departures,
                     avg_arr_delay_min, max_arr_delay_min)
                VALUES (?, 'AIRPORT', ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?);
            ";
            $u_stmt = sqlsrv_query($conn_tmi, $upsert_sql, [
                $icao, $hour_str,
                $s['total_operations'], $s['total_arrivals'], $s['total_departures'],
                $s['ontime_arrivals'], $s['delayed_arrivals'],
                $s['ontime_departures'], $s['delayed_departures'],
                $avg_arr, $s['max_arr_delay'],
                $icao, $icao, $hour_str,
                $s['total_operations'], $s['total_arrivals'], $s['total_departures'],
                $s['ontime_arrivals'], $s['delayed_arrivals'],
                $s['ontime_departures'], $s['delayed_departures'],
                $avg_arr, $s['max_arr_delay']
            ]);
            if ($u_stmt === false) {
                stats_log("UPSERT failed for {$icao}/{$hour_str}: " . (sqlsrv_errors()[0]['message'] ?? ''), 'ERROR');
            } else {
                sqlsrv_free_stmt($u_stmt);
                $stats_count++;
            }
        }
    }

    stats_log("Wrote {$stats_count} hourly stat rows");
    return $stats_count;
}

function _empty_stats(): array {
    return [
        'total_operations' => 0, 'total_arrivals' => 0, 'total_departures' => 0,
        'total_overflights' => 0,
        'ontime_arrivals' => 0, 'delayed_arrivals' => 0,
        'ontime_departures' => 0, 'delayed_departures' => 0,
        'max_arr_delay' => 0, 'arr_delay_sum' => 0, 'arr_delay_count' => 0,
    ];
}

// Main loop
do {
    try {
        $conn_adl = get_conn_adl();
        $conn_tmi = get_conn_tmi();
        run_stats_cycle($conn_adl, $conn_tmi, $lookback_hours, $debug);
    } catch (Exception $e) {
        stats_log('Cycle error: ' . $e->getMessage(), 'ERROR');
    }

    if ($once) break;
    if ($loop_mode) sleep($interval);
} while ($loop_mode);

stats_log('Daemon exiting');
```

- [ ] **Step 2: Test with --once flag**

Run: `php scripts/tmi/facility_stats_daemon.php --once --debug --hours=4`

Expected: Output showing hourly stats written for airports with recent traffic.

- [ ] **Step 3: Commit**

```bash
git add scripts/tmi/facility_stats_daemon.php
git commit -m "feat(tmi): add facility stats daemon

Computes hourly airport statistics from ADL flight data.
MERGE/UPSERT into tmi_facility_stats_hourly.
Supports --loop, --interval, --once, --debug, --hours flags."
```

---

### Task 9: Register Daemons in startup.sh

**Files:**
- Modify: `scripts/startup.sh`

- [ ] **Step 1: Add daemon entries in the downstream section**

In `scripts/startup.sh`, in the downstream daemons section (after the vACDM daemon block around line 293, before the `else` on line 304), add:

```bash
    # Start the TMI delay attribution daemon
    # Computes per-flight delay from EDCT/OOOI baselines, writes to VATSIM_TMI
    # 60-second cycle for active controlled flights
    echo "Starting delay_attribution_daemon.php (cycle every 60s)..."
    nohup php "${WWWROOT}/scripts/tmi/delay_attribution_daemon.php" --loop --interval=60 >> /home/LogFiles/delay_attribution.log 2>&1 &
    DELAY_ATTR_PID=$!
    echo "  delay_attribution_daemon.php started (PID: $DELAY_ATTR_PID)"

    # Start the TMI facility statistics daemon
    # Computes hourly/daily facility stats from flight data + delay attributions
    # Hourly cycle with 2-hour lookback
    echo "Starting facility_stats_daemon.php (cycle every 3600s)..."
    nohup php "${WWWROOT}/scripts/tmi/facility_stats_daemon.php" --loop --interval=3600 --hours=2 >> /home/LogFiles/facility_stats.log 2>&1 &
    FACILITY_STATS_PID=$!
    echo "  facility_stats_daemon.php started (PID: $FACILITY_STATS_PID)"
```

- [ ] **Step 2: Update the status echo block**

In the "All daemons started" echo block (around line 352), add the new PIDs:

```bash
    echo "  delay_attr=$DELAY_ATTR_PID, facility_stats=$FACILITY_STATS_PID"
```

And in the hibernation message (around line 307), add to the skipped list:

```bash
    echo "  Skipped: GIS parse/boundary/crossing, waypoint ETA, scheduler, event sync, CDM, vACDM, delay attribution, facility stats"
```

- [ ] **Step 3: Commit**

```bash
git add scripts/startup.sh
git commit -m "feat(tmi): register delay attribution and facility stats daemons

Both run in downstream section (skipped during hibernation).
Delay attribution: 60s cycle. Facility stats: 3600s cycle, 2h lookback."
```

---

### Task 10: NAS Event Log API and Page

**Files:**
- Create: `api/tmi/event-log.php`
- Create: `ntml-log.php`
- Create: `assets/js/ntml-log.js`
- Modify: `load/nav.php`

- [ ] **Step 1: Create the API endpoint `api/tmi/event-log.php`**

```php
<?php
/**
 * TMI Event Log API
 *
 * GET /api/tmi/event-log.php
 *
 * Returns paginated, filterable TMI unified log entries.
 *
 * Query parameters:
 *   hours (int, default 4): lookback window
 *   start/end (datetime): explicit time range
 *   category (string): filter by action_category
 *   type (string): filter by action_type
 *   program_type (string): filter by program_type
 *   facility (string): filter by issuing_facility
 *   org (string): filter by issuing_org
 *   severity (string): filter by severity
 *   page (int, default 1): page number
 *   per_page (int, default 100): results per page
 *
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-03-30
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . '/../../load/config.php');
include(__DIR__ . '/../../load/input.php');
include(__DIR__ . '/../../load/connect.php');

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'TMI database unavailable']);
    exit;
}

// Parse filters
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 4;
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;
$category = $_GET['category'] ?? null;
$type = $_GET['type'] ?? null;
$program_type = $_GET['program_type'] ?? null;
$facility = $_GET['facility'] ?? null;
$org = $_GET['org'] ?? null;
$severity = $_GET['severity'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(500, max(1, (int)($_GET['per_page'] ?? 100)));

// Build WHERE clause
$where = [];
$params = [];

if ($start && $end) {
    $where[] = 'c.event_utc >= ? AND c.event_utc <= ?';
    $params[] = $start;
    $params[] = $end;
} else {
    $where[] = 'c.event_utc >= DATEADD(HOUR, -?, SYSUTCDATETIME())';
    $params[] = $hours;
}

if ($category) { $where[] = 'c.action_category = ?'; $params[] = $category; }
if ($type) { $where[] = 'c.action_type = ?'; $params[] = $type; }
if ($program_type) { $where[] = 'c.program_type = ?'; $params[] = $program_type; }
if ($facility) { $where[] = 'c.issuing_facility = ?'; $params[] = $facility; }
if ($org) { $where[] = 'c.issuing_org = ?'; $params[] = $org; }
if ($severity) { $where[] = 'c.severity = ?'; $params[] = $severity; }

$where_sql = implode(' AND ', $where);

// Count total
$count_sql = "SELECT COUNT(*) AS cnt FROM dbo.tmi_log_core c WHERE {$where_sql}";
$count_stmt = sqlsrv_query($conn_tmi, $count_sql, $params);
$total = 0;
if ($count_stmt) {
    $row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
    $total = $row['cnt'] ?? 0;
    sqlsrv_free_stmt($count_stmt);
}

// Fetch page with satellite data
$offset = ($page - 1) * $per_page;
$data_sql = "
    SELECT
        c.*,
        s.ctl_element, s.element_type, s.facility, s.traffic_flow,
        p.effective_start_utc, p.effective_end_utc, p.rate_value, p.rate_unit,
        p.cause_category AS param_cause_category, p.cause_detail,
        p.ntml_formatted, p.cancellation_reason,
        i.total_flights, i.controlled_flights, i.avg_delay_min, i.max_delay_min, i.total_delay_min,
        r.program_id, r.entry_id, r.advisory_id, r.advisory_number,
        r.discord_message_id, r.discord_channel_id
    FROM dbo.tmi_log_core c
    LEFT JOIN dbo.tmi_log_scope s ON c.log_id = s.log_id
    LEFT JOIN dbo.tmi_log_parameters p ON c.log_id = p.log_id
    LEFT JOIN dbo.tmi_log_impact i ON c.log_id = i.log_id
    LEFT JOIN dbo.tmi_log_references r ON c.log_id = r.log_id
    WHERE {$where_sql}
    ORDER BY c.log_seq DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$data_params = array_merge($params, [$offset, $per_page]);
$data_stmt = sqlsrv_query($conn_tmi, $data_sql, $data_params);

$entries = [];
if ($data_stmt) {
    while ($row = sqlsrv_fetch_array($data_stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }
        $entries[] = $row;
    }
    sqlsrv_free_stmt($data_stmt);
}

echo json_encode([
    'success' => true,
    'data' => $entries,
    'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'pages' => ceil($total / $per_page),
    ],
    'filters' => [
        'hours' => $hours,
        'category' => $category,
        'type' => $type,
        'program_type' => $program_type,
        'facility' => $facility,
        'org' => $org,
        'severity' => $severity,
    ],
], JSON_PRETTY_PRINT);
```

- [ ] **Step 2: Create `ntml-log.php` page**

Create `ntml-log.php` in the project root. This is a standard PERTI page with header/nav/footer. It provides a filterable chronological log view.

```php
<?php
if (session_status() == PHP_SESSION_NONE) session_start();
include("load/config.php");
include("load/input.php");
include("load/connect.php");
$pageTitle = "NAS Event Log";
$pageId = "ntml-log";
include("load/header.php");
include("load/nav.php");
?>

<div class="container-fluid mt-3">
    <div class="row mb-3">
        <div class="col-12">
            <h4>NAS Event Log</h4>
            <p class="text-muted">Chronological log of all TMI actions across the NAS.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-3">
        <div class="col-md-2">
            <label>Time Range</label>
            <select id="log-hours" class="form-control form-control-sm">
                <option value="1">Last 1h</option>
                <option value="2">Last 2h</option>
                <option value="4" selected>Last 4h</option>
                <option value="8">Last 8h</option>
                <option value="24">Last 24h</option>
            </select>
        </div>
        <div class="col-md-2">
            <label>Category</label>
            <select id="log-category" class="form-control form-control-sm">
                <option value="">All</option>
                <option value="PROGRAM">Program</option>
                <option value="ENTRY">Entry</option>
                <option value="ADVISORY">Advisory</option>
                <option value="REROUTE">Reroute</option>
                <option value="DELAY_REPORT">Delay Report</option>
                <option value="CONFIG_CHANGE">Config Change</option>
                <option value="FLOW_MEASURE">Flow Measure</option>
                <option value="SLOT">Slot</option>
                <option value="COORDINATION">Coordination</option>
                <option value="SYSTEM">System</option>
            </select>
        </div>
        <div class="col-md-2">
            <label>Facility</label>
            <input type="text" id="log-facility" class="form-control form-control-sm" placeholder="e.g. KJFK">
        </div>
        <div class="col-md-2">
            <label>Organization</label>
            <select id="log-org" class="form-control form-control-sm">
                <option value="">All</option>
                <option value="vatcscc">vATCSCC</option>
                <option value="canoc">CANOC</option>
                <option value="ecfmp">ECFMP</option>
            </select>
        </div>
        <div class="col-md-2">
            <label>&nbsp;</label>
            <div>
                <button id="log-refresh" class="btn btn-sm btn-primary">Refresh</button>
                <label class="ml-2"><input type="checkbox" id="log-auto"> Auto (30s)</label>
            </div>
        </div>
        <div class="col-md-2">
            <label>&nbsp;</label>
            <div>
                <span id="log-count" class="text-muted"></span>
            </div>
        </div>
    </div>

    <!-- Log Table -->
    <div class="row">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-sm table-striped" id="log-table">
                    <thead>
                        <tr>
                            <th style="width:140px">Time (UTC)</th>
                            <th style="width:30px"></th>
                            <th style="width:100px">Category</th>
                            <th style="width:90px">Type</th>
                            <th style="width:80px">Program</th>
                            <th style="width:80px">Element</th>
                            <th>Summary</th>
                            <th style="width:80px">Facility</th>
                            <th style="width:80px">User</th>
                        </tr>
                    </thead>
                    <tbody id="log-body">
                        <tr><td colspan="9" class="text-center text-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="row">
        <div class="col-12 text-center">
            <button id="log-prev" class="btn btn-sm btn-outline-secondary" disabled>Previous</button>
            <span id="log-page-info" class="mx-2"></span>
            <button id="log-next" class="btn btn-sm btn-outline-secondary" disabled>Next</button>
        </div>
    </div>
</div>

<script src="assets/js/ntml-log.js"></script>

<?php include("load/footer.php"); ?>
```

- [ ] **Step 3: Create `assets/js/ntml-log.js`**

```javascript
/**
 * NAS Event Log — client-side module
 * Fetches from /api/tmi/event-log.php with filter controls.
 */
(function() {
    'use strict';

    var API = 'api/tmi/event-log.php';
    var currentPage = 1;
    var autoTimer = null;

    var severityIcons = {
        'INFO': '<span class="text-muted" title="Info">&#9679;</span>',
        'ADVISORY': '<span class="text-warning" title="Advisory">&#9679;</span>',
        'URGENT': '<span class="text-danger" title="Urgent">&#9679;</span>',
        'CRITICAL': '<span class="text-danger font-weight-bold" title="Critical">&#9679;</span>'
    };

    function getFilters() {
        return {
            hours: $('#log-hours').val(),
            category: $('#log-category').val(),
            facility: $('#log-facility').val().trim().toUpperCase(),
            org: $('#log-org').val(),
            page: currentPage,
            per_page: 100
        };
    }

    function loadLog() {
        var params = getFilters();
        var qs = $.param(params);

        $.getJSON(API + '?' + qs, function(resp) {
            if (!resp || !resp.success) {
                $('#log-body').html('<tr><td colspan="9" class="text-center text-danger">Failed to load</td></tr>');
                return;
            }

            var entries = resp.data || [];
            var pag = resp.pagination || {};
            $('#log-count').text(pag.total + ' entries');

            if (entries.length === 0) {
                $('#log-body').html('<tr><td colspan="9" class="text-center text-muted">No events found</td></tr>');
                updatePagination(pag);
                return;
            }

            var html = '';
            entries.forEach(function(e) {
                var time = (e.event_utc || '').substring(0, 19).replace('T', ' ');
                var icon = severityIcons[e.severity] || severityIcons['INFO'];
                html += '<tr class="log-row" data-logid="' + e.log_id + '">'
                    + '<td class="small">' + time + '</td>'
                    + '<td>' + icon + '</td>'
                    + '<td><span class="badge badge-secondary">' + (e.action_category || '') + '</span></td>'
                    + '<td class="small">' + (e.action_type || '') + '</td>'
                    + '<td class="small">' + (e.program_type || '') + '</td>'
                    + '<td class="small font-weight-bold">' + (e.ctl_element || '') + '</td>'
                    + '<td class="small">' + escHtml(e.summary || '') + '</td>'
                    + '<td class="small">' + (e.issuing_facility || '') + '</td>'
                    + '<td class="small">' + (e.user_name || e.user_cid || '') + '</td>'
                    + '</tr>';

                // Expandable detail row (hidden by default)
                html += '<tr class="log-detail" style="display:none" data-logid="' + e.log_id + '">'
                    + '<td colspan="9" class="small bg-light">'
                    + buildDetail(e)
                    + '</td></tr>';
            });

            $('#log-body').html(html);
            updatePagination(pag);
        }).fail(function() {
            $('#log-body').html('<tr><td colspan="9" class="text-center text-danger">Request failed</td></tr>');
        });
    }

    function buildDetail(e) {
        var parts = [];
        if (e.effective_start_utc) parts.push('<b>Start:</b> ' + e.effective_start_utc);
        if (e.effective_end_utc) parts.push('<b>End:</b> ' + e.effective_end_utc);
        if (e.rate_value) parts.push('<b>Rate:</b> ' + e.rate_value + ' ' + (e.rate_unit || ''));
        if (e.total_flights) parts.push('<b>Flights:</b> ' + e.total_flights + ' (ctl: ' + (e.controlled_flights || 0) + ')');
        if (e.avg_delay_min) parts.push('<b>Avg delay:</b> ' + e.avg_delay_min + ' min');
        if (e.max_delay_min) parts.push('<b>Max delay:</b> ' + e.max_delay_min + ' min');
        if (e.param_cause_category) parts.push('<b>Cause:</b> ' + e.param_cause_category + (e.cause_detail ? ' - ' + e.cause_detail : ''));
        if (e.cancellation_reason) parts.push('<b>Cancel reason:</b> ' + e.cancellation_reason);
        if (e.program_id) parts.push('<b>Program:</b> #' + e.program_id);
        if (e.advisory_number) parts.push('<b>Advisory:</b> ' + e.advisory_number);
        if (e.ntml_formatted) parts.push('<hr><pre class="mb-0 small">' + escHtml(e.ntml_formatted).substring(0, 500) + '</pre>');
        return parts.length ? parts.join(' &middot; ') : '<em>No additional details</em>';
    }

    function updatePagination(pag) {
        var total = pag.pages || 1;
        $('#log-page-info').text('Page ' + (pag.page || 1) + ' of ' + total);
        $('#log-prev').prop('disabled', (pag.page || 1) <= 1);
        $('#log-next').prop('disabled', (pag.page || 1) >= total);
    }

    function escHtml(s) {
        return $('<span>').text(s).html();
    }

    // Event handlers
    $(document).ready(function() {
        loadLog();

        $('#log-refresh').on('click', function() { currentPage = 1; loadLog(); });
        $('#log-hours, #log-category, #log-org').on('change', function() { currentPage = 1; loadLog(); });
        $('#log-facility').on('keyup', function(e) { if (e.key === 'Enter') { currentPage = 1; loadLog(); } });
        $('#log-prev').on('click', function() { currentPage = Math.max(1, currentPage - 1); loadLog(); });
        $('#log-next').on('click', function() { currentPage++; loadLog(); });

        // Row expand/collapse
        $(document).on('click', '.log-row', function() {
            var id = $(this).data('logid');
            var detail = $('.log-detail[data-logid="' + id + '"]');
            detail.toggle();
        });

        // Auto-refresh toggle
        $('#log-auto').on('change', function() {
            if (this.checked) {
                autoTimer = setInterval(function() { loadLog(); }, 30000);
            } else {
                clearInterval(autoTimer);
                autoTimer = null;
            }
        });
    });
})();
```

- [ ] **Step 4: Add navigation link**

In `load/nav.php`, find the TMI section and add:

```php
<a class="dropdown-item" href="ntml-log.php">NAS Event Log</a>
```

- [ ] **Step 5: Verify by loading the page**

1. Navigate to `https://perti.vatcscc.org/ntml-log.php`
2. Verify the page loads with filter controls
3. If any TMI actions have been logged (from Tasks 3-6), verify they appear in the table
4. Test expanding a row to see detail
5. Test changing filters (hours, category)

- [ ] **Step 6: Commit**

```bash
git add api/tmi/event-log.php ntml-log.php assets/js/ntml-log.js load/nav.php
git commit -m "feat(tmi): add NAS Event Log API and page

GET /api/tmi/event-log.php - paginated, filterable TMI log query.
ntml-log.php - chronological log view with expandable rows,
auto-refresh, category/facility/org filters."
```
