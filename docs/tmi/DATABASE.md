# VATSIM_TMI Database Architecture (Complete)

**Version:** 2.1
**Date:** February 25, 2026
**Database:** `VATSIM_TMI` on `vatsim.database.windows.net`

---

## 1. Overview

The VATSIM_TMI database consolidates all Traffic Management Initiative data:
- NTML entries (MIT, MINIT, etc.)
- Advisories (GS, GDP, AFP, Reroutes, Operations Plans)
- GDT Programs (Ground Stop, Ground Delay Programs)
- GDP Slots
- Reroutes (including flight assignments and compliance)
- Public Routes (map display)

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           VATSIM_TMI DATABASE                                   │
│                    (Azure SQL on vatsim.database.windows.net)                   │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │ tmi_entries  │  │tmi_advisories│  │ tmi_programs │  │  tmi_slots   │       │
│  │   (NTML)     │  │  (Formal)    │  │  (GS/GDP)    │  │ (GDP Slots)  │       │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │ tmi_reroutes │  │tmi_reroute_  │  │tmi_public_   │  │  tmi_events  │       │
│  │              │  │   flights    │  │   routes     │  │  (Audit)     │       │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │tmi_flight_   │  │tmi_flight_   │  │tmi_proposals │  │tmi_proposal_ │       │
│  │  control     │  │    list      │  │              │  │  facilities  │       │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │tmi_reroute_  │  │tmi_airport_  │  │tmi_delay_    │  │tmi_discord_  │       │
│  │   routes     │  │   configs    │  │   entries    │  │    posts     │       │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │tmi_flow_     │  │tmi_flow_     │  │tmi_popup_    │  │tmi_advisory_ │       │
│  │  providers   │  │  measures    │  │   queue      │  │  sequences   │       │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                                                 │
│                              Cross-DB Reference:                                │
│                    VATSIM_ADL.dbo.adl_flight_tmi (flight assignments)          │
│                                                                                 │
└─────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Table Relationships

```
┌─────────────────────┐
│   tmi_programs      │◄──────────────────────┐
│   (GS/GDP)          │                       │
└─────────┬───────────┘                       │
          │                                   │
          │ 1:N                               │ FK
          ▼                                   │
┌─────────────────────┐                       │
│    tmi_slots        │                       │
│   (GDP Slots)       │                       │
└─────────────────────┘                       │
                                              │
┌─────────────────────┐                       │
│   tmi_reroutes      │◄──────────────────────┤
│                     │                       │
└─────────┬───────────┘                       │
          │                                   │
          │ 1:N                               │
          ▼                                   │
┌─────────────────────┐                       │
│ tmi_reroute_flights │                       │
│                     │                       │
└─────────┬───────────┘                       │
          │                                   │
          │ 1:N                               │
          ▼                                   │
┌─────────────────────┐                       │
│tmi_reroute_         │                       │
│ compliance_log      │                       │
└─────────────────────┘                       │
                                              │
┌─────────────────────┐                       │
│   tmi_advisories    │───────────────────────┘
│   (links to         │         program_id (optional)
│    programs)        │
└─────────────────────┘

┌─────────────────────┐
│   tmi_entries       │  (standalone NTML log)
│   (NTML)            │
└─────────────────────┘

┌─────────────────────┐
│ tmi_public_routes   │  (standalone map display)
│                     │
└─────────────────────┘

┌─────────────────────┐
│   tmi_events        │  (audit for all entities)
│                     │
└─────────────────────┘
```

---

## 3. Complete Table Schemas

### 3.1 `tmi_entries` - NTML Log

```sql
CREATE TABLE dbo.tmi_entries (
    -- Primary Key
    entry_id                INT IDENTITY(1,1) PRIMARY KEY,
    entry_guid              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Entry Classification
    determinant_code        NVARCHAR(10) NOT NULL,          -- e.g., '05B01'
    protocol_type           TINYINT NOT NULL,               -- 01-08
    entry_type              NVARCHAR(16) NOT NULL,          -- MIT, MINIT, DELAY, CONFIG, APREQ, CONTINGENCY, MISC, REROUTE
    
    -- Scope / Affected Elements
    ctl_element             NVARCHAR(8) NULL,               -- Airport (KJFK) or fix (LENDY)
    element_type            NVARCHAR(8) NULL,               -- APT, FIX, ARTCC, TRACON
    requesting_facility     NVARCHAR(8) NULL,               -- ZNY, JFK, N90
    providing_facility      NVARCHAR(8) NULL,               -- ZDC, ZBW
    
    -- Restriction Details
    restriction_value       SMALLINT NULL,                  -- MIT miles or MINIT minutes
    restriction_unit        NVARCHAR(8) NULL,               -- MIT, MINIT
    condition_text          NVARCHAR(500) NULL,             -- e.g., 'JFK via LENDY'
    qualifiers              NVARCHAR(200) NULL,             -- HEAVY, PER_FIX, AS_ONE, EACH
    exclusions              NVARCHAR(200) NULL,             -- LIFEGUARD, MEDEVAC
    reason_code             NVARCHAR(16) NULL,              -- VOLUME, WEATHER, EQUIPMENT, RUNWAY, STAFFING, OTHER
    reason_detail           NVARCHAR(200) NULL,             -- Specific reason text
    
    -- Time Parameters
    valid_from              DATETIME2(0) NULL,
    valid_until             DATETIME2(0) NULL,
    
    -- Status Workflow
    status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
                            -- DRAFT, PROPOSED, APPROVED, SCHEDULED, ACTIVE, CANCELLED, EXPIRED, SUPERSEDED
    
    -- Source Tracking
    source_type             NVARCHAR(16) NOT NULL,          -- PERTI, DISCORD, TYPEFORM, API
    source_id               NVARCHAR(100) NULL,             -- Discord message ID, TypeForm response ID
    source_channel          NVARCHAR(64) NULL,              -- Discord channel ID
    
    -- Discord Sync
    discord_message_id      NVARCHAR(64) NULL,              -- Posted message ID
    discord_posted_at       DATETIME2(0) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Raw Input Preservation
    raw_input               NVARCHAR(MAX) NULL,             -- Original text for parsing audit
    parsed_data             NVARCHAR(MAX) NULL,             -- JSON of all parsed fields
    
    -- Metadata
    created_by              NVARCHAR(64) NULL,              -- CID or Discord user ID
    created_by_name         NVARCHAR(128) NULL,             -- Display name
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    cancelled_by            NVARCHAR(64) NULL,
    cancelled_at            DATETIME2(0) NULL,
    cancel_reason           NVARCHAR(256) NULL,
    
    -- Deduplication
    content_hash            NVARCHAR(64) NULL,              -- SHA256 of normalized content
    supersedes_entry_id     INT NULL,
    
    CONSTRAINT FK_entries_supersedes FOREIGN KEY (supersedes_entry_id) 
        REFERENCES dbo.tmi_entries(entry_id),
    CONSTRAINT UQ_entries_guid UNIQUE (entry_guid)
);
```

**Indexes:**
- `IX_entries_status (status, valid_from, valid_until)`
- `IX_entries_determinant (determinant_code)`
- `IX_entries_entry_type (entry_type)`
- `IX_entries_facility_req (requesting_facility)`
- `IX_entries_facility_prov (providing_facility)`
- `IX_entries_ctl_element (ctl_element)`
- `IX_entries_source (source_type, source_id)`
- `IX_entries_discord (discord_message_id)`
- `IX_entries_hash (content_hash)`
- `IX_entries_created (created_at DESC)`
- `IX_entries_active (status, valid_until) WHERE status = 'ACTIVE'`

---

### 3.2 `tmi_advisories` - Formal Advisories

```sql
CREATE TABLE dbo.tmi_advisories (
    -- Primary Key
    advisory_id             INT IDENTITY(1,1) PRIMARY KEY,
    advisory_guid           UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Advisory Identification
    advisory_number         NVARCHAR(16) NOT NULL,          -- e.g., 'ADVZY 001'
    advisory_type           NVARCHAR(32) NOT NULL,          
                            -- GS, GDP, AFP, CTOP, REROUTE, OPS_PLAN, GENERAL, CDR, SWAP, FEA, FCA, ICR, TOS, MIT
    
    -- Advisory Scope
    ctl_element             NVARCHAR(8) NULL,               -- KJFK, ZNY, FCA001
    element_type            NVARCHAR(8) NULL,               -- APT, ARTCC, FCA
    scope_facilities        NVARCHAR(MAX) NULL,             -- JSON array of affected centers
    
    -- Link to GDT Program (for GS/GDP/AFP)
    program_id              INT NULL,                       -- FK to tmi_programs
    
    -- Program Parameters (for GS/GDP/AFP)
    program_rate            SMALLINT NULL,                  -- Arrivals/hour for GDP
    delay_cap               SMALLINT NULL,                  -- Max delay minutes
    
    -- Time Parameters
    effective_from          DATETIME2(0) NULL,
    effective_until         DATETIME2(0) NULL,
    
    -- Advisory Content
    subject                 NVARCHAR(256) NOT NULL,
    body_text               NVARCHAR(MAX) NOT NULL,
    reason_code             NVARCHAR(16) NULL,
    reason_detail           NVARCHAR(200) NULL,
    
    -- Reroute Specifics
    reroute_id              INT NULL,                       -- FK to tmi_reroutes
    reroute_name            NVARCHAR(64) NULL,
    reroute_area            NVARCHAR(32) NULL,              -- EAST, WEST, NORTH, SOUTH
    reroute_string          NVARCHAR(MAX) NULL,             -- Full route text
    reroute_from            NVARCHAR(256) NULL,             -- Origin criteria
    reroute_to              NVARCHAR(256) NULL,             -- Destination criteria
    
    -- MIT Specifics
    mit_miles               SMALLINT NULL,
    mit_type                NVARCHAR(8) NULL,               -- MIT, MINIT
    mit_fix                 NVARCHAR(32) NULL,
    
    -- Status Workflow
    status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    is_proposed             BIT DEFAULT 1,
    
    -- Source Tracking
    source_type             NVARCHAR(16) NOT NULL,
    source_id               NVARCHAR(100) NULL,
    
    -- Discord Sync
    discord_message_id      NVARCHAR(256) NULL,             -- May have multiple (comma-sep)
    discord_posted_at       DATETIME2(0) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Metadata
    created_by              NVARCHAR(64) NULL,
    created_by_name         NVARCHAR(128) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    approved_by             NVARCHAR(64) NULL,
    approved_at             DATETIME2(0) NULL,
    cancelled_by            NVARCHAR(64) NULL,
    cancelled_at            DATETIME2(0) NULL,
    cancel_reason           NVARCHAR(256) NULL,
    
    -- Revision Tracking
    revision_number         TINYINT DEFAULT 1,
    supersedes_advisory_id  INT NULL,
    
    CONSTRAINT FK_advisories_supersedes FOREIGN KEY (supersedes_advisory_id)
        REFERENCES dbo.tmi_advisories(advisory_id),
    CONSTRAINT FK_advisories_program FOREIGN KEY (program_id)
        REFERENCES dbo.tmi_programs(program_id),
    CONSTRAINT UQ_advisories_guid UNIQUE (advisory_guid)
);
```

---

### 3.3 `tmi_programs` - GS/GDP Programs

```sql
CREATE TABLE dbo.tmi_programs (
    program_id              INT IDENTITY(1,1) PRIMARY KEY,
    program_guid            UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Program Identification
    ctl_element             NVARCHAR(8) NOT NULL,           -- Airport (KJFK) or FCA (FCA001)
    element_type            NVARCHAR(8) NOT NULL,           -- APT, FCA
    program_type            NVARCHAR(16) NOT NULL,          -- GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP-DAS, AFP-GAAP, AFP-UDP
    program_name            NVARCHAR(32) NULL,              -- Display name (e.g., "KJFK GDP #1")
    adv_number              NVARCHAR(16) NULL,              -- Advisory number (e.g., "ADVZY 001")
    
    -- Program Times
    start_utc               DATETIME2(0) NOT NULL,
    end_utc                 DATETIME2(0) NOT NULL,
    cumulative_start        DATETIME2(0) NULL,              -- For extensions (original start)
    cumulative_end          DATETIME2(0) NULL,              -- For extensions (latest end)
    
    -- Program Status
    status                  NVARCHAR(16) NOT NULL DEFAULT 'PROPOSED',
                            -- PROPOSED, ACTIVE, COMPLETED, PURGED, SUPERSEDED
    is_proposed             BIT DEFAULT 1,
    is_active               BIT DEFAULT 0,
    
    -- Rates (GDP only)
    program_rate            INT NULL,                       -- Default arrivals/hour
    reserve_rate            INT NULL,                       -- Reserved slots/hour for pop-ups
    delay_limit_min         INT DEFAULT 180,                -- Maximum assignable delay
    target_delay_mult       DECIMAL(3,2) DEFAULT 1.0,       -- Target delay multiplier (UDP)
    
    -- Detailed Hourly Rates (JSON)
    rates_hourly_json       NVARCHAR(MAX) NULL,             -- {"00":30,"01":30,...,"23":30}
    reserve_hourly_json     NVARCHAR(MAX) NULL,
    
    -- Scope Parameters (JSON)
    scope_json              NVARCHAR(MAX) NULL,             -- Scope definition
    exemptions_json         NVARCHAR(MAX) NULL,             -- Exemption rules
    
    -- Include/Filter Options (per FSM)
    arrival_fix_filter      NVARCHAR(8) NULL,               -- ALL or specific fix
    aircraft_type_filter    NVARCHAR(16) DEFAULT 'ALL',     -- ALL, JET, PROP, TURBOPROP
    carrier_filter          NVARCHAR(MAX) NULL,             -- JSON array of carriers
    
    -- Impact/Cause
    impacting_condition     NVARCHAR(64) NULL,              -- WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER
    cause_text              NVARCHAR(512) NULL,
    comments                NVARCHAR(MAX) NULL,
    
    -- Revision Tracking
    revision_number         INT DEFAULT 0,
    parent_program_id       INT NULL,                       -- FK to previous revision
    
    -- Metrics (populated after simulation/activation)
    total_flights           INT NULL,
    controlled_flights      INT NULL,
    exempt_flights          INT NULL,
    avg_delay_min           DECIMAL(8,2) NULL,
    max_delay_min           INT NULL,
    total_delay_min         BIGINT NULL,
    
    -- Substitution Settings (per FSM)
    subs_enabled            BIT DEFAULT 1,                  -- Slot substitution enabled
    adaptive_compression    BIT DEFAULT 0,                  -- Auto-compression enabled
    
    -- Source Tracking
    source_type             NVARCHAR(16) NULL,
    source_id               NVARCHAR(100) NULL,
    
    -- Discord Sync
    discord_message_id      NVARCHAR(256) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Audit
    created_by              NVARCHAR(64) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    activated_by            NVARCHAR(64) NULL,
    activated_at            DATETIME2(0) NULL,
    purged_by               NVARCHAR(64) NULL,
    purged_at               DATETIME2(0) NULL,
    
    CONSTRAINT FK_programs_parent FOREIGN KEY (parent_program_id)
        REFERENCES dbo.tmi_programs(program_id),
    CONSTRAINT UQ_programs_guid UNIQUE (program_guid)
);
```

---

### 3.4 `tmi_slots` - GDP Slot Allocation

```sql
CREATE TABLE dbo.tmi_slots (
    slot_id                 BIGINT IDENTITY(1,1) PRIMARY KEY,
    program_id              INT NOT NULL,
    
    -- Slot Identification
    slot_name               NVARCHAR(16) NOT NULL,          -- e.g., "KJFK.091530A"
    slot_index              INT NOT NULL,                   -- Sequential index
    slot_time_utc           DATETIME2(0) NOT NULL,          -- Slot arrival time
    
    -- Slot Type and Status
    slot_type               NVARCHAR(16) NOT NULL,          -- REGULAR, RESERVED, UNASSIGNED
    slot_status             NVARCHAR(16) NOT NULL DEFAULT 'OPEN',
                            -- OPEN, ASSIGNED, BRIDGED, HELD, CANCELLED
    
    -- Bin Tracking (15-min granularity)
    bin_hour                TINYINT NOT NULL,
    bin_quarter             TINYINT NOT NULL,               -- 0, 15, 30, 45
    
    -- Assignment (flight_uid references VATSIM_ADL.dbo.adl_flight_core)
    assigned_flight_uid     BIGINT NULL,                    -- FK to VATSIM_ADL
    assigned_callsign       NVARCHAR(12) NULL,
    assigned_carrier        NVARCHAR(8) NULL,
    assigned_origin         NVARCHAR(4) NULL,
    assigned_at             DATETIME2(0) NULL,
    
    -- Computed Control Times
    ctd_utc                 DATETIME2(0) NULL,              -- Controlled Time of Departure
    cta_utc                 DATETIME2(0) NULL,              -- Controlled Time of Arrival
    
    -- Slot Management
    sl_hold                 BIT DEFAULT 0,                  -- Held by carrier for substitution
    sl_hold_carrier         NVARCHAR(8) NULL,
    sl_hold_expires_utc     DATETIME2(0) NULL,
    subbable                BIT DEFAULT 1,                  -- Available for substitution
    
    -- Bridging (for SCS/ECR)
    bridge_from_slot_id     BIGINT NULL,                    -- Original slot in bridge chain
    bridge_to_slot_id       BIGINT NULL,                    -- Target slot in bridge chain
    
    -- Audit
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    CONSTRAINT FK_slots_program FOREIGN KEY (program_id)
        REFERENCES dbo.tmi_programs(program_id) ON DELETE CASCADE
);
```

**Indexes:**
- `IX_slots_program_time (program_id, slot_time_utc)`
- `IX_slots_flight (assigned_flight_uid)`
- `IX_slots_status (program_id, slot_status, slot_type)`
- `IX_slots_callsign (assigned_callsign)`

---

### 3.5 `tmi_reroutes` - Reroute Definitions

```sql
CREATE TABLE dbo.tmi_reroutes (
    reroute_id              INT IDENTITY(1,1) PRIMARY KEY,
    reroute_guid            UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Status & Identity
    status                  TINYINT NOT NULL DEFAULT 0,     -- 0=draft, 1=proposed, 2=active, 3=monitoring, 4=expired, 5=cancelled
    name                    NVARCHAR(64) NOT NULL,
    adv_number              NVARCHAR(16) NULL,
    
    -- Temporal Scope
    start_utc               DATETIME2(0) NULL,
    end_utc                 DATETIME2(0) NULL,
    time_basis              NVARCHAR(8) DEFAULT 'ETD',      -- ETD, ETA
    
    -- Protected Route Definition
    protected_segment       NVARCHAR(MAX) NULL,             -- Route segment to use
    protected_fixes         NVARCHAR(MAX) NULL,             -- JSON array of fixes to cross
    avoid_fixes             NVARCHAR(MAX) NULL,             -- JSON array of fixes to avoid
    route_type              NVARCHAR(8) DEFAULT 'FULL',     -- FULL, PARTIAL
    
    -- Flight Filtering: Geographic Scope
    origin_airports         NVARCHAR(MAX) NULL,             -- JSON array
    origin_tracons          NVARCHAR(MAX) NULL,
    origin_centers          NVARCHAR(MAX) NULL,
    dest_airports           NVARCHAR(MAX) NULL,
    dest_tracons            NVARCHAR(MAX) NULL,
    dest_centers            NVARCHAR(MAX) NULL,
    
    -- Flight Filtering: Route-Based
    departure_fix           NVARCHAR(8) NULL,
    arrival_fix             NVARCHAR(8) NULL,
    thru_centers            NVARCHAR(MAX) NULL,
    thru_fixes              NVARCHAR(MAX) NULL,
    use_airway              NVARCHAR(MAX) NULL,
    
    -- Flight Filtering: Aircraft
    include_ac_cat          NVARCHAR(16) DEFAULT 'ALL',
    include_ac_types        NVARCHAR(MAX) NULL,
    include_carriers        NVARCHAR(MAX) NULL,
    weight_class            NVARCHAR(16) DEFAULT 'ALL',     -- ALL, HEAVY, LARGE, SMALL
    altitude_min            INT NULL,
    altitude_max            INT NULL,
    
    -- RVSM Filter
    rvsm_filter             NVARCHAR(16) DEFAULT 'ALL',     -- ALL, RVSM, NON_RVSM
    
    -- Exemptions
    exempt_airports         NVARCHAR(MAX) NULL,
    exempt_carriers         NVARCHAR(MAX) NULL,
    exempt_flights          NVARCHAR(MAX) NULL,
    exempt_active_only      BIT DEFAULT 0,
    
    -- Airborne Applicability
    airborne_filter         NVARCHAR(16) DEFAULT 'NOT_AIRBORNE',  -- ALL, AIRBORNE_ONLY, NOT_AIRBORNE
    
    -- Metadata & Advisory
    comments                NVARCHAR(MAX) NULL,
    impacting_condition     NVARCHAR(64) NULL,
    advisory_text           NVARCHAR(MAX) NULL,
    
    -- Display Settings (for map)
    color                   CHAR(7) DEFAULT '#e74c3c',      -- Hex color
    line_weight             TINYINT DEFAULT 3,
    line_style              NVARCHAR(16) DEFAULT 'solid',
    route_geojson           NVARCHAR(MAX) NULL,             -- Pre-computed GeoJSON
    
    -- Metrics
    total_assigned          INT NULL,
    compliant_count         INT NULL,
    non_compliant_count     INT NULL,
    compliance_rate         DECIMAL(5,2) NULL,
    
    -- Source Tracking
    source_type             NVARCHAR(16) NULL,
    source_id               NVARCHAR(100) NULL,
    discord_message_id      NVARCHAR(256) NULL,
    discord_channel_id      NVARCHAR(64) NULL,
    
    -- Audit
    created_by              NVARCHAR(64) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    activated_at            DATETIME2(0) NULL,
    
    CONSTRAINT UQ_reroutes_guid UNIQUE (reroute_guid)
);
```

---

### 3.6 `tmi_reroute_flights` - Flight Assignments to Reroutes

```sql
CREATE TABLE dbo.tmi_reroute_flights (
    id                      INT IDENTITY(1,1) PRIMARY KEY,
    reroute_id              INT NOT NULL,
    flight_key              NVARCHAR(64) NOT NULL,          -- GUFI or callsign+date
    callsign                NVARCHAR(16) NOT NULL,
    flight_uid              BIGINT NULL,                    -- FK to VATSIM_ADL (if available)
    
    -- Flight Context (captured at assignment)
    dep_icao                NCHAR(4) NULL,
    dest_icao               NCHAR(4) NULL,
    ac_type                 NVARCHAR(8) NULL,
    filed_altitude          INT NULL,
    
    -- Route Capture
    route_at_assign         NVARCHAR(MAX) NULL,             -- Route when assigned
    assigned_route          NVARCHAR(MAX) NULL,             -- Route they should fly
    
    -- Route Tracking (updated on each refresh)
    current_route           NVARCHAR(MAX) NULL,
    current_route_utc       DATETIME2(0) NULL,
    final_route             NVARCHAR(MAX) NULL,
    
    -- Position Tracking
    last_lat                DECIMAL(9,6) NULL,
    last_lon                DECIMAL(10,6) NULL,
    last_altitude           INT NULL,
    last_position_utc       DATETIME2(0) NULL,
    
    -- Compliance Status
    compliance_status       NVARCHAR(16) DEFAULT 'PENDING',
                            -- PENDING, MONITORING, COMPLIANT, PARTIAL, NON_COMPLIANT, EXEMPT, UNKNOWN
    protected_fixes_crossed NVARCHAR(MAX) NULL,             -- JSON array
    avoid_fixes_crossed     NVARCHAR(MAX) NULL,             -- JSON array
    compliance_pct          DECIMAL(5,2) NULL,
    compliance_notes        NVARCHAR(MAX) NULL,
    
    -- Timing
    assigned_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    departed_utc            DATETIME2(0) NULL,
    arrived_utc             DATETIME2(0) NULL,
    
    -- Metrics (route impact)
    route_distance_orig_nm  INT NULL,
    route_distance_new_nm   INT NULL,
    route_delta_nm          INT NULL,
    ete_original_min        INT NULL,
    ete_assigned_min        INT NULL,
    ete_delta_min           INT NULL,
    
    -- Manual Override
    manual_status           BIT DEFAULT 0,
    override_by             NVARCHAR(64) NULL,
    override_utc            DATETIME2(0) NULL,
    override_reason         NVARCHAR(MAX) NULL,
    
    CONSTRAINT FK_reroute_flights_reroute FOREIGN KEY (reroute_id) 
        REFERENCES dbo.tmi_reroutes(reroute_id) ON DELETE CASCADE
);
```

---

### 3.7 `tmi_reroute_compliance_log` - Compliance History

```sql
CREATE TABLE dbo.tmi_reroute_compliance_log (
    log_id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    reroute_flight_id       INT NOT NULL,
    
    -- Snapshot
    snapshot_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    compliance_status       NVARCHAR(16) NULL,
    compliance_pct          DECIMAL(5,2) NULL,
    
    -- Position at snapshot
    lat                     DECIMAL(9,6) NULL,
    lon                     DECIMAL(10,6) NULL,
    altitude                INT NULL,
    
    -- Route at snapshot
    route_string            NVARCHAR(MAX) NULL,
    fixes_crossed           NVARCHAR(MAX) NULL,
    
    CONSTRAINT FK_compliance_log_flight FOREIGN KEY (reroute_flight_id) 
        REFERENCES dbo.tmi_reroute_flights(id) ON DELETE CASCADE
);
```

---

### 3.8 `tmi_public_routes` - Public Route Display

```sql
CREATE TABLE dbo.tmi_public_routes (
    route_id                INT IDENTITY(1,1) PRIMARY KEY,
    route_guid              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    
    -- Status
    status                  TINYINT NOT NULL DEFAULT 1,     -- 0=inactive, 1=active, 2=expired
    
    -- Route Identification
    name                    NVARCHAR(64) NOT NULL,
    adv_number              NVARCHAR(16) NULL,
    
    -- Link to Advisory/Reroute (optional)
    advisory_id             INT NULL,                       -- FK to tmi_advisories
    reroute_id              INT NULL,                       -- FK to tmi_reroutes
    
    -- Route Content
    route_string            NVARCHAR(MAX) NOT NULL,         -- The actual route
    advisory_text           NVARCHAR(MAX) NULL,             -- Full advisory text
    
    -- Display Settings
    color                   CHAR(7) NOT NULL DEFAULT '#e74c3c',
    line_weight             TINYINT NOT NULL DEFAULT 3,
    line_style              NVARCHAR(16) NOT NULL DEFAULT 'solid',  -- solid, dashed, dotted
    
    -- Validity Period
    valid_start_utc         DATETIME2(0) NOT NULL,
    valid_end_utc           DATETIME2(0) NOT NULL,
    
    -- Filters / Scope
    constrained_area        NVARCHAR(64) NULL,              -- e.g., "ZNY"
    reason                  NVARCHAR(256) NULL,             -- e.g., "WEATHER/TRAFFIC MANAGEMENT"
    origin_filter           NVARCHAR(MAX) NULL,             -- JSON array
    dest_filter             NVARCHAR(MAX) NULL,             -- JSON array
    facilities              NVARCHAR(MAX) NULL,             -- e.g., "ZBW/ZNY/ZDC"
    
    -- Route Geometry (cached)
    route_geojson           NVARCHAR(MAX) NULL,             -- Pre-computed GeoJSON LineString
    
    -- Metadata
    created_by              NVARCHAR(64) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    CONSTRAINT FK_public_routes_advisory FOREIGN KEY (advisory_id)
        REFERENCES dbo.tmi_advisories(advisory_id),
    CONSTRAINT FK_public_routes_reroute FOREIGN KEY (reroute_id)
        REFERENCES dbo.tmi_reroutes(reroute_id),
    CONSTRAINT UQ_public_routes_guid UNIQUE (route_guid)
);
```

---

### 3.9 `tmi_events` - Unified Audit Log

```sql
CREATE TABLE dbo.tmi_events (
    event_id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    
    -- Entity Reference
    entity_type             NVARCHAR(16) NOT NULL,          -- ENTRY, ADVISORY, PROGRAM, SLOT, REROUTE, ROUTE
    entity_id               INT NOT NULL,
    entity_guid             UNIQUEIDENTIFIER NULL,
    
    -- Secondary References (for flight-level events)
    program_id              INT NULL,
    flight_uid              BIGINT NULL,
    slot_id                 BIGINT NULL,
    reroute_id              INT NULL,
    
    -- Event Details
    event_type              NVARCHAR(32) NOT NULL,
                            -- CREATE, UPDATE, DELETE, STATUS_CHANGE,
                            -- PROGRAM_ACTIVATED, PROGRAM_REVISED, PROGRAM_EXTENDED, PROGRAM_COMPRESSED, PROGRAM_PURGED,
                            -- FLIGHT_ASSIGNED, FLIGHT_EXEMPTED, FLIGHT_POPUP, FLIGHT_RECONTROL,
                            -- SLOT_ASSIGNED, SLOT_BRIDGED, SLOT_HELD, SLOT_RELEASED,
                            -- ECR_REQUESTED, ECR_APPROVED, ECR_DENIED,
                            -- REROUTE_ACTIVATED, REROUTE_COMPLIANCE_CHECK
    event_detail            NVARCHAR(64) NULL,              -- e.g., 'DRAFT→ACTIVE'
    
    -- Changes (for UPDATE events)
    field_name              NVARCHAR(64) NULL,
    old_value               NVARCHAR(MAX) NULL,
    new_value               NVARCHAR(MAX) NULL,
    
    -- Full event data (JSON for complex events)
    event_data_json         NVARCHAR(MAX) NULL,
    
    -- Source
    source_type             NVARCHAR(16) NOT NULL,          -- PERTI, DISCORD, API, SCHEDULER
    source_id               NVARCHAR(100) NULL,
    
    -- Actor
    actor_id                NVARCHAR(64) NULL,
    actor_name              NVARCHAR(128) NULL,
    actor_ip                NVARCHAR(45) NULL,
    
    -- Timestamp
    event_utc               DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
);
```

**Indexes:**
- `IX_events_entity (entity_type, entity_id)`
- `IX_events_time (event_utc DESC)`
- `IX_events_type (event_type)`
- `IX_events_program (program_id, event_utc)`
- `IX_events_flight (flight_uid, event_utc)`

---

### 3.10 `tmi_advisory_sequences` - Advisory Numbering

```sql
CREATE TABLE dbo.tmi_advisory_sequences (
    seq_date                DATE PRIMARY KEY,
    seq_number              SMALLINT NOT NULL DEFAULT 0
);
```

---

## 4. Cross-Database Reference: VATSIM_ADL

The `adl_flight_tmi` table stays in VATSIM_ADL (tight coupling to flight data):

```sql
-- In VATSIM_ADL.dbo.adl_flight_tmi
-- References VATSIM_TMI.dbo.tmi_programs via program_id
-- References VATSIM_TMI.dbo.tmi_slots via slot_id
-- References VATSIM_TMI.dbo.tmi_reroutes via reroute_id

-- Cross-DB query example:
SELECT f.*, p.program_name, p.program_type
FROM VATSIM_ADL.dbo.adl_flight_tmi f
JOIN VATSIM_TMI.dbo.tmi_programs p ON f.program_id = p.program_id
WHERE p.is_active = 1;
```

---

## 5. Entity Counts Summary

| Table | Est. Records/Day | Est. Records/Year | Notes |
|-------|------------------|-------------------|-------|
| `tmi_entries` | 100-500 | 50K-200K | NTML log |
| `tmi_advisories` | 50-100 | 20K-40K | Formal advisories |
| `tmi_programs` | 5-20 | 2K-8K | GS/GDP programs |
| `tmi_slots` | 500-5000 | 200K-2M | GDP slots (many per program) |
| `tmi_reroutes` | 5-20 | 2K-8K | Reroute definitions |
| `tmi_reroute_flights` | 100-1000 | 50K-400K | Flight assignments |
| `tmi_reroute_compliance_log` | 500-5000 | 200K-2M | Compliance snapshots |
| `tmi_public_routes` | 10-50 | 4K-20K | Map display |
| `tmi_events` | 1000-5000 | 400K-2M | Audit log |

**Total estimated storage:** ~5-10GB/year

---

## 6. Views

```sql
-- Active NTML entries
CREATE VIEW vw_tmi_active_entries AS
SELECT * FROM dbo.tmi_entries
WHERE status = 'ACTIVE' AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME());

-- Active advisories
CREATE VIEW vw_tmi_active_advisories AS
SELECT * FROM dbo.tmi_advisories
WHERE status = 'ACTIVE' AND (effective_until IS NULL OR effective_until > SYSUTCDATETIME());

-- Active GDT programs
CREATE VIEW vw_tmi_active_programs AS
SELECT * FROM dbo.tmi_programs
WHERE is_active = 1 AND end_utc > SYSUTCDATETIME();

-- Active reroutes
CREATE VIEW vw_tmi_active_reroutes AS
SELECT * FROM dbo.tmi_reroutes
WHERE status = 2 AND end_utc > SYSUTCDATETIME();

-- Active public routes
CREATE VIEW vw_tmi_active_public_routes AS
SELECT * FROM dbo.tmi_public_routes
WHERE status = 1 AND valid_end_utc > SYSUTCDATETIME();
```

---

*Last Updated: January 17, 2026*
