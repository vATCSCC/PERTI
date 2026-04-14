# TMI Operations & Analytics Platform Design

## Goal

Build a comprehensive TMI logging, delay attribution, and facility statistics system that provides a complete audit trail of all TMI actions, per-flight delay accounting with cause attribution, and OpsNet/ASPM-style facility performance metrics.

## Architecture

Three layers built on a shared schema in VATSIM_TMI, with cross-database access to VATSIM_ADL for flight data:

- **Layer 1 (TMI Unified Log)**: Normalized 5-table audit trail capturing every TMI action across all entity types
- **Layer 2 (Delay Attribution)**: Per-flight, per-cause delay breakdown with a reference taxonomy
- **Layer 3 (Facility Statistics)**: OpsNet/ASPM-style hourly/daily metrics and airport on-time performance

## Context

### Current State

The TMI system has **74 API endpoints** across `api/tmi/`, `api/mgt/tmi/`, and `api/gdt/`, managing GDPs, Ground Stops, NTML entries (MIT, MINIT, STOP, APREQ, etc.), advisories, reroutes, and ECFMP flow measures.

**Logging gaps identified:**
- `tmi_events` only covers GDT program lifecycle (activate, cancel, revise, ECR, blanket, transition)
- NTML entry creation, advisory issuance, reroute operations, slot compression/reoptimization, GS extensions, and Discord publishing are NOT logged to any queryable audit table
- `tmi_events` has two conflicting schemas in migrations (001 vs 004) and PHP code uses mixed column names from both — the table is effectively broken
- Discord is the de facto "log of record" for most TMI actions, but it's not queryable
- No per-flight delay attribution or cause-of-delay tracking exists
- No OpsNet/ASPM-style facility performance metrics are computed

**Existing statistics infrastructure** (`flight_stats_*` in VATSIM_ADL) tracks flight counts, taxi times, and basic TMI impact but not cause-attributed delay or on-time performance metrics.

**Existing TMI tables** (28+ tables in VATSIM_TMI) are operational — they manage the live state of programs, slots, flights, coordination, and Discord posting. The new logging/analytics tables sit alongside them as an observability layer.

### Relationship to Existing Tables

| Existing Table | Relationship | Action |
|---|---|---|
| `tmi_events` | **Coexist, then sunset.** New `tmi_log_core` is the replacement. Existing GDT code continues writing to `tmi_events` during transition; new code writes to `tmi_log_core`. |
| `tmi_program_coordination_log` | **Coexist permanently.** Tightly coupled to proposal/approval workflow. Referenced via `coordination_log_id` in `tmi_log_references`. |
| `tmi_proposal_reactions` | **Coexist permanently.** Discord reaction audit trail. Not duplicated. |
| `tmi_reroute_compliance_log` | **Coexist permanently.** Reroute-specific compliance audit. Not duplicated. |
| `tmi_flight_control` | **Coexist permanently.** 55+ column operational table for per-flight TMI control state. Not duplicated — the log captures the action, `tmi_flight_control` tracks the live state. |
| `tmi_delay_entries` | **Coexist permanently.** Tracks D/D, E/D, A/D delay observations (magnitude + trend). Distinct from `tmi_delay_attribution` which tracks per-flight causal breakdown. |
| `tmi_flow_measures` | **Coexist permanently.** ECFMP flow measure sync. Log entries reference via `flow_measure_id`. |
| `flight_stats_*` (VATSIM_ADL) | **Coexist permanently.** Existing flight statistics. New `tmi_facility_stats_*` adds TMI-specific operational overlay. `tmi_ops_performance` adds ASPM-style metrics that don't exist today. |

### Cross-Database Pattern

VATSIM_TMI and VATSIM_ADL are on Azure SQL Basic tier which does NOT support cross-database references. The established pattern (used by `executeDeferredTMISync()` in the ADL daemon) is:
1. Query source DB in PHP
2. Compute in PHP
3. Insert results into target DB

The delay attribution and facility stats daemons follow this same pattern — read flight times from `$conn_adl`, compute attribution/metrics in PHP, write to `$conn_tmi`.

---

## Layer 1: TMI Unified Log

### Design Principles

- Every TMI action gets exactly one row in `tmi_log_core` (the spine)
- Satellite tables are populated only when that dimension applies
- UUID primary key (`log_id`) ties all tables together
- `log_seq` BIGINT IDENTITY provides monotonic ordering
- Both PHP helper functions AND stored procedures can write to the log
- The log is append-only — rows are never updated or deleted (corrections create new log entries with `supersedes_log_id`)

### Entity Types Captured

| `action_category` | Entity Types | Source |
|---|---|---|
| `PROGRAM` | GDP, GS, AFP | GDT endpoints, SPs |
| `ENTRY` | MIT, MINIT, STOP, APREQ, CFR, TBM, TXT, ALT, SPD | tmi-publish.js → publish.php |
| `ADVISORY` | GDP, GS, GDP_CNX, GS_CNX, OPS_PLAN, HOTLINE, SWAP, FREE_FORM, REROUTE, FCA, INFO | tmi-publish.js, GDT endpoints |
| `REROUTE` | Strategic, Tactical | Reroute management endpoints |
| `DELAY_REPORT` | D/D, E/D, A/D | tmi-publish.js (DELAY type) |
| `CONFIG_CHANGE` | VMC/IMC, runway, rate changes | tmi-publish.js (CONFIG type) |
| `FLOW_MEASURE` | MIT, MDI, RATE, GS, REROUTE (ECFMP) | ecfmp_poll_daemon.php |
| `SLOT` | ECR, substitution, popup, blanket | GDT slot operations |
| `COORDINATION` | Proposal submit, approve, deny, rescind, promote | coordinate.php, promote.php |
| `SYSTEM` | Daemon events, automated actions | Daemons, SPs |

### Action Types

| `action_type` | Used With | Description |
|---|---|---|
| `CREATE` | All | Entity created (may be PROPOSED/MODELING/DRAFT) |
| `ACTIVATE` | PROGRAM, ENTRY, FLOW_MEASURE | Made operationally active |
| `CANCEL` | PROGRAM, ENTRY, ADVISORY, REROUTE, FLOW_MEASURE | Cancelled/withdrawn |
| `EXTEND` | PROGRAM | End time extended |
| `REVISE` | PROGRAM | New revision created |
| `COMPRESS` | PROGRAM | GDP slot compression |
| `REOPTIMIZE` | PROGRAM | GDP re-optimization |
| `PURGE` | PROGRAM | Removed from system (flight list deleted) |
| `PUBLISH` | PROGRAM, ENTRY, ADVISORY | Published to Discord/SWIM |
| `PROMOTE` | ENTRY, ADVISORY | Promoted from staging to production |
| `TRANSITION` | PROGRAM | GS→GDP transition |
| `ECR` | SLOT | Edit Controlled Time |
| `BLANKET` | SLOT | Blanket EDCT issuance |
| `SUBSTITUTE` | SLOT | Flight substitution |
| `OVERRIDE` | COORDINATION | DCC override |
| `SUBMIT` | COORDINATION | Proposal submitted |
| `APPROVE` | COORDINATION | Facility approved |
| `DENY` | COORDINATION | Facility denied |
| `RESCIND` | COORDINATION | Proposal rescinded |
| `EXPIRE` | PROGRAM, ENTRY, FLOW_MEASURE | Auto-expired |
| `WITHDRAW` | FLOW_MEASURE | ECFMP withdrawal |
| `SIMULATE` | PROGRAM | GDP simulation run |
| `UPDATE` | All | Generic metadata update |

### Table: `tmi_log_core`

Every TMI action gets one row. Merged with authoring (always present).

```sql
CREATE TABLE dbo.tmi_log_core (
    log_id              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    log_seq             BIGINT IDENTITY(1,1) NOT NULL,

    -- Classification
    action_category     NVARCHAR(32) NOT NULL,
    action_type         NVARCHAR(32) NOT NULL,
    program_type        NVARCHAR(32) NULL,
    severity            NVARCHAR(16) NOT NULL DEFAULT 'INFO',
    source_system       NVARCHAR(32) NOT NULL,

    -- Summary
    summary             NVARCHAR(512) NOT NULL,
    event_utc           DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),

    -- Authoring
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
```

### Table: `tmi_log_scope`

What airspace, facilities, airports, and filters the TMI action affects.

```sql
CREATE TABLE dbo.tmi_log_scope (
    log_id              UNIQUEIDENTIFIER NOT NULL,

    -- Primary target
    ctl_element         NVARCHAR(64) NULL,
    element_type        NVARCHAR(16) NULL,
    facility            NVARCHAR(64) NULL,
    traffic_flow        NVARCHAR(32) NULL,
    via_fix             NVARCHAR(64) NULL,

    -- Scope filters
    scope_airports      NVARCHAR(MAX) NULL,
    scope_tiers         NVARCHAR(MAX) NULL,
    scope_altitude      NVARCHAR(128) NULL,
    scope_aircraft_type NVARCHAR(MAX) NULL,
    scope_carriers      NVARCHAR(MAX) NULL,
    scope_equipment     NVARCHAR(256) NULL,
    exclusions          NVARCHAR(MAX) NULL,
    flt_incl_type       NVARCHAR(16) NULL,

    -- Affected facilities
    affected_facilities NVARCHAR(MAX) NULL,
    dep_facilities      NVARCHAR(MAX) NULL,
    dep_scope           NVARCHAR(64) NULL,

    -- ECFMP filters
    filters_json        NVARCHAR(MAX) NULL,

    CONSTRAINT PK_tmi_log_scope PRIMARY KEY (log_id),
    CONSTRAINT FK_log_scope_core FOREIGN KEY (log_id) REFERENCES dbo.tmi_log_core(log_id),
    INDEX IX_scope_ctl (ctl_element, element_type),
    INDEX IX_scope_facility (facility)
);
```

### Table: `tmi_log_parameters`

TMI-specific operational values, times, cause, and text content.

```sql
CREATE TABLE dbo.tmi_log_parameters (
    log_id              UNIQUEIDENTIFIER NOT NULL,

    -- Time window
    effective_start_utc DATETIME2 NULL,
    effective_end_utc   DATETIME2 NULL,

    -- Rates and values
    rate_value          INT NULL,
    rate_unit           NVARCHAR(16) NULL,
    spacing_type        NVARCHAR(32) NULL,
    program_rate        INT NULL,
    rates_hourly_json   NVARCHAR(MAX) NULL,
    rates_quarter_json  NVARCHAR(MAX) NULL,
    delay_cap           INT NULL,

    -- Cause
    cause_category      NVARCHAR(32) NULL,
    cause_detail        NVARCHAR(256) NULL,
    impacting_condition NVARCHAR(128) NULL,
    prob_extension      NVARCHAR(16) NULL,

    -- Delay report fields (from tmi_delay_entries)
    delay_type          NVARCHAR(8) NULL,
    delay_minutes       INT NULL,
    delay_trend         NVARCHAR(16) NULL,
    holding_status      NVARCHAR(16) NULL,
    holding_fix         NVARCHAR(32) NULL,
    aircraft_holding    INT NULL,

    -- Config change fields (from tmi_airport_configs)
    weather_conditions  NVARCHAR(8) NULL,
    arrival_runways     NVARCHAR(MAX) NULL,
    departure_runways   NVARCHAR(MAX) NULL,
    config_name         NVARCHAR(64) NULL,

    -- GS-specific
    gs_probability      NVARCHAR(16) NULL,
    gs_release_rate     INT NULL,

    -- Cancellation
    cancellation_reason NVARCHAR(256) NULL,
    cancellation_edct_action NVARCHAR(32) NULL,
    cancellation_notes  NVARCHAR(MAX) NULL,

    -- TBM-specific
    meter_point         NVARCHAR(64) NULL,
    freeze_horizon      INT NULL,

    -- Compression/optimization
    compression_enabled BIT NULL,

    -- Text content
    ntml_formatted      NVARCHAR(MAX) NULL,
    remarks             NVARCHAR(MAX) NULL,
    detail_json         NVARCHAR(MAX) NULL,
    qualifiers          NVARCHAR(MAX) NULL,

    CONSTRAINT PK_tmi_log_parameters PRIMARY KEY (log_id),
    CONSTRAINT FK_log_params_core FOREIGN KEY (log_id) REFERENCES dbo.tmi_log_core(log_id),
    INDEX IX_params_effective (effective_start_utc, effective_end_utc)
        WHERE effective_start_utc IS NOT NULL
);
```

### Table: `tmi_log_impact`

Operational metrics snapshot at the time of the action.

```sql
CREATE TABLE dbo.tmi_log_impact (
    log_id              UNIQUEIDENTIFIER NOT NULL,

    -- Flight counts
    total_flights       INT NULL,
    controlled_flights  INT NULL,
    exempt_flights      INT NULL,
    airborne_flights    INT NULL,
    popup_flights       INT NULL,

    -- Delay metrics
    avg_delay_min       DECIMAL(8,1) NULL,
    max_delay_min       DECIMAL(8,1) NULL,
    total_delay_min     DECIMAL(12,1) NULL,

    -- Cumulative (for revisions)
    cumulative_total_delay DECIMAL(12,1) NULL,
    cumulative_max_delay   DECIMAL(8,1) NULL,

    -- Demand/capacity at time of action
    demand_rate         INT NULL,
    capacity_rate       INT NULL,

    -- Fairness (from GDP Phase 4)
    reversal_count      INT NULL,
    reversal_pct        DECIMAL(5,2) NULL,
    gaming_flags_count  INT NULL,

    -- Reroute compliance
    compliance_rate     DECIMAL(5,2) NULL,

    -- Free text
    comments            NVARCHAR(MAX) NULL,

    CONSTRAINT PK_tmi_log_impact PRIMARY KEY (log_id),
    CONSTRAINT FK_log_impact_core FOREIGN KEY (log_id) REFERENCES dbo.tmi_log_core(log_id)
);
```

### Table: `tmi_log_references`

Links to existing TMI entities, lifecycle chain, source tracking, and Discord message linkage.

```sql
CREATE TABLE dbo.tmi_log_references (
    log_id              UNIQUEIDENTIFIER NOT NULL,

    -- Entity FKs
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

    -- Lifecycle chain
    parent_log_id       UNIQUEIDENTIFIER NULL,
    supersedes_log_id   UNIQUEIDENTIFIER NULL,
    supersedes_entry_id INT NULL,

    -- Advisory linkage
    advisory_number     NVARCHAR(32) NULL,
    cancel_advisory_num NVARCHAR(32) NULL,
    revision_number     INT NULL,

    -- Source tracking
    source_type         NVARCHAR(16) NULL,
    source_id           NVARCHAR(128) NULL,
    source_channel      NVARCHAR(64) NULL,
    content_hash        NVARCHAR(64) NULL,

    -- Discord message tracking
    discord_message_id  NVARCHAR(64) NULL,
    discord_channel_id  NVARCHAR(64) NULL,
    discord_channel_purpose NVARCHAR(32) NULL,

    -- Coordination log reference
    coordination_log_id INT NULL,

    CONSTRAINT PK_tmi_log_references PRIMARY KEY (log_id),
    CONSTRAINT FK_log_refs_core FOREIGN KEY (log_id) REFERENCES dbo.tmi_log_core(log_id),
    INDEX IX_refs_program (program_id) WHERE program_id IS NOT NULL,
    INDEX IX_refs_entry (entry_id) WHERE entry_id IS NOT NULL,
    INDEX IX_refs_advisory (advisory_id) WHERE advisory_id IS NOT NULL,
    INDEX IX_refs_reroute (reroute_id) WHERE reroute_id IS NOT NULL,
    INDEX IX_refs_flow (flow_measure_id) WHERE flow_measure_id IS NOT NULL,
    INDEX IX_refs_parent (parent_log_id) WHERE parent_log_id IS NOT NULL,
    INDEX IX_refs_flight (flight_uid) WHERE flight_uid IS NOT NULL
);
```

---

## Layer 2: Delay Attribution

### Design Principles

- Every flight that experiences measurable delay gets one or more rows in `tmi_delay_attribution`
- A single flight can have multiple delay causes (e.g., 30 min GDP delay + 5 min excess taxi)
- Delay phases reflect what PERTI can actually measure, but the schema supports future expansion
- The `tmi_cause_taxonomy` table provides a controlled vocabulary for delay causes
- Attribution is recomputed as flights progress — `is_current` flag marks the latest computation
- Cross-DB pattern: daemon reads `adl_flight_times` from VATSIM_ADL, computes in PHP, writes to VATSIM_TMI

### Data Availability (Audit Finding)

PERTI does NOT have reliable "scheduled" departure/arrival times (`std_utc`/`sta_utc` are rarely populated). Delay baselines vary by phase:

| Phase | Baseline Available | Actual Available | Confidence | Notes |
|---|---|---|---|---|
| GATE | `edct_utc` or `etd_utc` | `out_utc` | HIGH for EDCT flights, LOW for uncontrolled | No true "scheduled" gate time exists for VATSIM flights |
| TAXI_OUT | `airport_taxi_reference.unimpeded_taxi_sec` | `off_utc - out_utc` | HIGH (201 airports with OOOI) | Strongest measurement — FAA ASPM methodology |
| AIRBORNE | `ete_minutes` (estimated) | `on_utc - off_utc` | MEDIUM | ETE is estimated, not scheduled |
| TAXI_IN | None currently | `in_utc - on_utc` | PLACEHOLDER | No `airport_taxi_in_reference` exists yet — future expansion |
| TOTAL | `etd_utc`/`eta_utc` at first-seen | `ata_utc` | HIGH | Always computable for completed flights |

All phases are included in the schema for future expandability. The daemon initially computes TMI_HOLD (EDCT-based), TAXI_EXCESS, and TOTAL; other phases are populated as data sources improve.

### Table: `tmi_cause_taxonomy`

Reference table defining the controlled vocabulary for delay causes.

```sql
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
```

**Seed data:**

| cause_category | cause_subcategory | is_tmi_attributed | is_facility_attributed |
|---|---|---|---|
| TMI | GDP | 1 | 1 |
| TMI | GS | 1 | 1 |
| TMI | AFP | 1 | 1 |
| TMI | MIT | 1 | 1 |
| TMI | MINIT | 1 | 1 |
| TMI | STOP | 1 | 1 |
| TMI | REROUTE | 1 | 1 |
| WEATHER | CONVECTIVE | 0 | 0 |
| WEATHER | LOW_CEILING | 0 | 0 |
| WEATHER | WIND | 0 | 0 |
| WEATHER | SNOW_ICE | 0 | 0 |
| WEATHER | LOW_VISIBILITY | 0 | 0 |
| WEATHER | OTHER | 0 | 0 |
| VOLUME | DEMAND_EXCEEDS_CAPACITY | 0 | 1 |
| VOLUME | STAFFING | 0 | 1 |
| VOLUME | EVENT_TRAFFIC | 0 | 1 |
| EQUIPMENT | RUNWAY_CLOSURE | 0 | 1 |
| EQUIPMENT | NAVAID_OUTAGE | 0 | 1 |
| EQUIPMENT | SYSTEM_OUTAGE | 0 | 1 |
| RUNWAY | CONFIGURATION_CHANGE | 0 | 1 |
| RUNWAY | CAPACITY_REDUCTION | 0 | 1 |
| OTHER | UNATTRIBUTED | 0 | 0 |
| OTHER | PILOT | 0 | 0 |
| OTHER | MILITARY | 0 | 0 |
| OTHER | SECURITY | 0 | 0 |

### Table: `tmi_delay_attribution`

Per-flight, per-cause delay breakdown.

```sql
CREATE TABLE dbo.tmi_delay_attribution (
    attribution_id      BIGINT IDENTITY(1,1) PRIMARY KEY,

    -- Flight identity
    flight_uid          BIGINT NOT NULL,
    callsign            NVARCHAR(16) NOT NULL,
    dep_icao            NVARCHAR(8) NULL,
    arr_icao            NVARCHAR(8) NULL,

    -- Delay measurement
    delay_phase         NVARCHAR(16) NOT NULL,
    delay_minutes       DECIMAL(8,1) NOT NULL,
    baseline_utc        DATETIME2 NULL,
    actual_utc          DATETIME2 NULL,

    -- Attribution
    cause_id            INT NOT NULL,
    cause_category      NVARCHAR(32) NOT NULL,
    cause_subcategory   NVARCHAR(32) NOT NULL,

    -- What caused it
    attributed_program_id   INT NULL,
    attributed_entry_id     INT NULL,
    attributed_log_id       UNIQUEIDENTIFIER NULL,
    attributed_facility     NVARCHAR(64) NULL,
    attributed_org          NVARCHAR(64) NULL,

    -- Flight context
    arr_facility        NVARCHAR(64) NULL,
    dep_facility        NVARCHAR(64) NULL,
    aircraft_type       NVARCHAR(8) NULL,
    carrier             NVARCHAR(8) NULL,

    -- Computation metadata
    computation_method  NVARCHAR(32) NOT NULL,
    computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    confidence          NVARCHAR(16) NULL,
    is_current          BIT NOT NULL DEFAULT 1,
    superseded_by       BIGINT NULL,

    CONSTRAINT FK_delay_cause FOREIGN KEY (cause_id) REFERENCES dbo.tmi_cause_taxonomy(cause_id),
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
```

**`delay_phase` values:**
- `GATE` — gate hold delay (EDCT wait, pushback delay)
- `TAXI_OUT` — excess taxi-out vs unimpeded reference
- `AIRBORNE` — excess airborne time vs estimated
- `TAXI_IN` — excess taxi-in (future — no unimpeded reference yet)
- `TOTAL` — total end-to-end delay

**`computation_method` values:**
- `EDCT_DIFF` — EDCT minus original ETD (TMI-attributed delay)
- `TAXI_REFERENCE` — actual taxi minus unimpeded reference
- `ETE_DIFF` — actual enroute time minus estimated
- `OOOI_DIFF` — OOOI phase comparison
- `MANUAL` — manually attributed
- `DAEMON` — computed by attribution daemon

**`confidence` values:**
- `HIGH` — solid baseline and actual times available
- `MEDIUM` — estimated baseline or partial OOOI
- `LOW` — inferred or placeholder
- `PLACEHOLDER` — schema populated for future expansion, data not yet reliable

---

## Layer 3: Facility Delay Statistics

### Design Principles

- Hourly and daily aggregation of delay metrics per facility
- Complements (does not replace) existing `flight_stats_*` tables in VATSIM_ADL
- Adds TMI-specific operational context that flight stats tables don't capture
- `tmi_ops_performance` provides ASPM-style airport on-time metrics
- All tables populated by daemon/SP on a rolling basis
- Fields that can't be populated now (due to data availability) remain NULL — no column pruning

### Table: `tmi_facility_stats_hourly`

Per-facility, per-hour operational metrics.

```sql
CREATE TABLE dbo.tmi_facility_stats_hourly (
    stat_id             BIGINT IDENTITY(1,1) PRIMARY KEY,

    -- Dimensions
    facility            NVARCHAR(64) NOT NULL,
    facility_type       NVARCHAR(16) NOT NULL,
    airport_icao        NVARCHAR(8) NULL,
    hour_utc            DATETIME2 NOT NULL,

    -- Operations counts
    total_operations    INT NOT NULL DEFAULT 0,
    total_arrivals      INT NOT NULL DEFAULT 0,
    total_departures    INT NOT NULL DEFAULT 0,
    total_overflights   INT NOT NULL DEFAULT 0,

    -- On-time performance (15 min threshold)
    ontime_arrivals     INT NOT NULL DEFAULT 0,
    delayed_arrivals    INT NOT NULL DEFAULT 0,
    ontime_departures   INT NOT NULL DEFAULT 0,
    delayed_departures  INT NOT NULL DEFAULT 0,

    -- Delay minutes by cause category
    delay_min_tmi       DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_weather   DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_volume    DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_equipment DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_runway    DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_other     DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_total     DECIMAL(12,1) NOT NULL DEFAULT 0,

    -- Delay minutes by phase
    delay_min_gate      DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_taxi_out  DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_airborne  DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_taxi_in   DECIMAL(12,1) NOT NULL DEFAULT 0,

    -- Average delays
    avg_arr_delay_min   DECIMAL(8,1) NULL,
    avg_dep_delay_min   DECIMAL(8,1) NULL,
    max_arr_delay_min   DECIMAL(8,1) NULL,
    max_dep_delay_min   DECIMAL(8,1) NULL,

    -- TMI activity in this hour
    active_programs     INT NOT NULL DEFAULT 0,
    active_restrictions INT NOT NULL DEFAULT 0,
    active_reroutes     INT NOT NULL DEFAULT 0,

    -- Capacity context
    aar_configured      INT NULL,
    adr_configured      INT NULL,
    weather_condition   NVARCHAR(8) NULL,

    -- Computation
    computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),

    INDEX IX_fsh_facility_hour UNIQUE CLUSTERED (facility, hour_utc),
    INDEX IX_fsh_airport_hour (airport_icao, hour_utc) WHERE airport_icao IS NOT NULL,
    INDEX IX_fsh_hour (hour_utc DESC)
);
```

### Table: `tmi_facility_stats_daily`

Per-facility, per-day rollups.

```sql
CREATE TABLE dbo.tmi_facility_stats_daily (
    stat_id             BIGINT IDENTITY(1,1) PRIMARY KEY,

    -- Dimensions
    facility            NVARCHAR(64) NOT NULL,
    facility_type       NVARCHAR(16) NOT NULL,
    airport_icao        NVARCHAR(8) NULL,
    date_utc            DATE NOT NULL,

    -- Operations
    total_operations    INT NOT NULL DEFAULT 0,
    total_arrivals      INT NOT NULL DEFAULT 0,
    total_departures    INT NOT NULL DEFAULT 0,

    -- On-time percentages
    ontime_arr_pct      DECIMAL(5,2) NULL,
    ontime_dep_pct      DECIMAL(5,2) NULL,

    -- Delay by cause
    delay_min_tmi       DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_weather   DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_volume    DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_equipment DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_runway    DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_other     DECIMAL(12,1) NOT NULL DEFAULT 0,
    delay_min_total     DECIMAL(12,1) NOT NULL DEFAULT 0,

    -- Average delays
    avg_arr_delay_min   DECIMAL(8,1) NULL,
    avg_dep_delay_min   DECIMAL(8,1) NULL,

    -- TMI summary
    programs_issued     INT NOT NULL DEFAULT 0,
    restrictions_issued INT NOT NULL DEFAULT 0,
    advisories_issued   INT NOT NULL DEFAULT 0,

    -- Peak hour
    peak_demand_hour    DATETIME2 NULL,
    peak_demand_rate    INT NULL,
    peak_delay_hour     DATETIME2 NULL,
    peak_delay_min      DECIMAL(8,1) NULL,

    -- Computation
    computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),

    INDEX IX_fsd_facility_date UNIQUE CLUSTERED (facility, date_utc),
    INDEX IX_fsd_airport_date (airport_icao, date_utc) WHERE airport_icao IS NOT NULL,
    INDEX IX_fsd_date (date_utc DESC)
);
```

### Table: `tmi_ops_performance`

ASPM-style airport on-time performance and delay distribution.

```sql
CREATE TABLE dbo.tmi_ops_performance (
    perf_id             BIGINT IDENTITY(1,1) PRIMARY KEY,

    -- Dimensions
    airport_icao        NVARCHAR(8) NOT NULL,
    date_utc            DATE NOT NULL,

    -- ASPM A0/A14/D0/D14 metrics
    arr_a0              INT NULL,
    arr_a14             INT NULL,
    arr_delayed         INT NULL,
    arr_total           INT NULL,
    dep_d0              INT NULL,
    dep_d14             INT NULL,
    dep_delayed         INT NULL,
    dep_total           INT NULL,

    -- Arrival delay distribution buckets
    arr_delay_0_15      INT NULL,
    arr_delay_16_30     INT NULL,
    arr_delay_31_60     INT NULL,
    arr_delay_61_120    INT NULL,
    arr_delay_121_plus  INT NULL,

    -- Departure delay distribution buckets
    dep_delay_0_15      INT NULL,
    dep_delay_16_30     INT NULL,
    dep_delay_31_60     INT NULL,
    dep_delay_61_120    INT NULL,
    dep_delay_121_plus  INT NULL,

    -- Taxi performance
    avg_taxi_out_min    DECIMAL(8,1) NULL,
    avg_taxi_in_min     DECIMAL(8,1) NULL,
    unimpeded_taxi_out  DECIMAL(8,1) NULL,
    unimpeded_taxi_in   DECIMAL(8,1) NULL,
    excess_taxi_out_min DECIMAL(8,1) NULL,
    excess_taxi_in_min  DECIMAL(8,1) NULL,

    -- Gate delay
    avg_gate_delay_min  DECIMAL(8,1) NULL,

    -- Computation
    computed_utc        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),

    INDEX IX_ops_airport_date UNIQUE CLUSTERED (airport_icao, date_utc),
    INDEX IX_ops_date (date_utc DESC)
);
```

---

## PHP Helper Layer

### `log_tmi_action()` — Central logging function

A single PHP helper function that all TMI endpoints call. Lives in a new file `load/tmi_log.php`.

```php
function log_tmi_action(array $core, array $scope = null, array $params = null,
                        array $impact = null, array $refs = null): string
```

- Returns the `log_id` UUID
- Inserts into `tmi_log_core` (required)
- Conditionally inserts into satellite tables when arrays are non-null
- Uses `$conn_tmi` (VATSIM_TMI connection)
- Populates authoring fields from session context automatically

### SP-level logging

For actions that happen inside stored procedures (activate, cancel, assign flights), add `INSERT INTO tmi_log_core` statements directly in the SP, or call a new `sp_LogTmiAction` stored procedure.

### Migration from `tmi_events`

During transition:
1. New code writes to `tmi_log_core` via `log_tmi_action()`
2. Existing GDT code continues writing to `tmi_events` (no breaking changes)
3. A SQL view `vw_tmi_events_compat` can UNION both tables for consumers that need everything
4. Eventually migrate GDT code to use `log_tmi_action()` and sunset `tmi_events`

---

## NAS Event Log Page

### Purpose

A controller-facing page (`ntml-log.php` or integrated into an existing page) that displays a chronological log of all TMI actions, filterable for both real-time shift awareness and post-event TMR analysis.

### Filters

- Time range (preset: last 1h, 2h, 4h, 8h, shift, custom)
- Facility (ARTCC, TRACON, or airport)
- Organization (vatcscc, canoc, ecfmp, all)
- Action category (PROGRAM, ENTRY, ADVISORY, etc.)
- Program type (GDP, GS, MIT, etc.)
- Severity (INFO, ADVISORY, URGENT, CRITICAL)

### Display

- Chronological table with expandable rows
- Collapsed view: `event_utc | severity icon | action_category | program_type | ctl_element | summary | issuing_facility`
- Expanded view: scope, parameters, impact metrics, lifecycle chain, Discord message link
- Auto-refresh option for live shift monitoring (30s poll or WebSocket)
- Export to CSV/PDF for TMR reports

### API Endpoint

`GET /api/tmi/event-log.php` with query parameters for all filters. Returns paginated JSON.

---

## Computation Daemons

### Delay Attribution Daemon

New daemon script (e.g., `scripts/tmi/delay_attribution_daemon.php`). Runs on a tiered schedule:
- Active flights with EDCT: every 60s (recompute as flight progresses)
- Completed flights: once at completion, then finalized

**Logic:**
1. Query `tmi_flight_control` for flights with assigned delays (GDP/GS controlled)
2. Batch-fetch `adl_flight_times` OOOI data from `$conn_adl`
3. Batch-fetch `airport_taxi_reference` for relevant airports
4. Compute delay phases:
   - TMI_HOLD: `edct_utc - etd_utc` (when EDCT assigned)
   - TAXI_EXCESS: `(off_utc - out_utc) - unimpeded_taxi_sec` (when OOOI available)
   - AIRBORNE: `(on_utc - off_utc) - ete_minutes * 60` (when OOOI available)
   - TOTAL: `ata_utc - eta_utc` (at first-seen baseline)
5. Look up active TMI programs to attribute cause
6. Insert/update `tmi_delay_attribution` rows

### Facility Stats Daemon

New daemon script (e.g., `scripts/tmi/facility_stats_daemon.php`). Runs hourly.

**Logic:**
1. For each hour in the computation window:
2. Query completed flights from `$conn_adl` (arrivals and departures in that hour)
3. Query active TMI programs/entries from `$conn_tmi` for that hour
4. Query `tmi_delay_attribution` for attributed delays in that hour
5. Aggregate into `tmi_facility_stats_hourly` rows
6. Roll up into `tmi_facility_stats_daily` at end of day
7. Compute `tmi_ops_performance` at end of day for ASPM82 airports

---

## Wiring: Which Endpoints Write to the Log

Every TMI mutation endpoint needs a `log_tmi_action()` call. The complete list:

### GDT Endpoints (`api/gdt/`)

| Endpoint | action_category | action_type |
|---|---|---|
| `programs/create.php` | PROGRAM | CREATE |
| `programs/activate.php` | PROGRAM | ACTIVATE |
| `programs/cancel.php` | PROGRAM | CANCEL |
| `programs/purge.php` | PROGRAM | PURGE |
| `programs/revise.php` | PROGRAM | REVISE |
| `programs/extend.php` | PROGRAM | EXTEND |
| `programs/transition.php` | PROGRAM | TRANSITION |
| `programs/compress.php` | PROGRAM | COMPRESS |
| `programs/reoptimize.php` | PROGRAM | REOPTIMIZE |
| `programs/power_run.php` | PROGRAM | SIMULATE |
| `programs/simulate.php` | PROGRAM | SIMULATE |
| `programs/blanket.php` | SLOT | BLANKET |
| `programs/ecr.php` | SLOT | ECR |
| `programs/publish.php` | PROGRAM | PUBLISH |
| `programs/submit_proposal.php` | COORDINATION | SUBMIT |

### TMI Management Endpoints (`api/mgt/tmi/`)

| Endpoint | action_category | action_type |
|---|---|---|
| `publish.php` (NTML entry) | ENTRY | CREATE |
| `publish.php` (advisory) | ADVISORY | CREATE |
| `publish.php` (DELAY) | DELAY_REPORT | CREATE |
| `publish.php` (CONFIG) | CONFIG_CHANGE | CREATE |
| `publish.php` (CANCEL) | ENTRY | CANCEL |
| `promote.php` | ENTRY or ADVISORY | PROMOTE |
| `coordinate.php` (submit) | COORDINATION | SUBMIT |
| `coordinate.php` (reaction) | COORDINATION | APPROVE or DENY |
| `coordinate.php` (rescind) | COORDINATION | RESCIND |
| `cancel.php` | PROGRAM or ENTRY | CANCEL |
| `edit.php` | PROGRAM or ENTRY | UPDATE |
| `reroutes/post.php` | REROUTE | CREATE |
| `reroutes/bulk.php` | REROUTE | CREATE |
| `reroutes/delete.php` | REROUTE | CANCEL |
| `ground_stops/post.php` | PROGRAM | CREATE |

### TMI Operational Endpoints (`api/tmi/`)

| Endpoint | action_category | action_type |
|---|---|---|
| `gs/create.php` | PROGRAM | CREATE |
| `gs/activate.php` | PROGRAM | ACTIVATE |
| `gs/extend.php` | PROGRAM | EXTEND |
| `gs/purge.php` | PROGRAM | PURGE |

### Daemons

| Daemon | action_category | action_type |
|---|---|---|
| `ecfmp_poll_daemon.php` (new measure) | FLOW_MEASURE | CREATE |
| `ecfmp_poll_daemon.php` (status change) | FLOW_MEASURE | ACTIVATE, EXPIRE, or WITHDRAW |
| `process_tmi_proposals.php` (expired) | COORDINATION | EXPIRE |

### Stored Procedures

| SP | action_category | action_type |
|---|---|---|
| `sp_TMI_ActivateProgram` | PROGRAM | ACTIVATE |
| `sp_TMI_CancelProgram` | PROGRAM | CANCEL |
| `sp_TMI_AssignFlights_FPFS_RBD` | PROGRAM | UPDATE (flight list assigned) |
| `sp_TMI_IssueBlanketEDCT` | SLOT | BLANKET |
| `sp_TMI_EditControlledTime` | SLOT | ECR |
| `sp_TMI_TransitionGStoGDP` | PROGRAM | TRANSITION |
| `sp_TMI_ReviseProgram` | PROGRAM | REVISE |

Note: SP-level logging can either INSERT directly or defer to the PHP caller. Recommend PHP-level logging where possible (richer context), with SP logging as fallback for actions that bypass PHP.
