# Database Schema

PERTI uses two databases: MySQL for application data and Azure SQL for flight/ADL data.

---

## MySQL (PERTI Application)

### Core Tables

| Table | Purpose |
|-------|---------|
| `users` | User preferences and settings |
| `plans` | Planning worksheets |
| `schedules` | Staff scheduling |
| `comments` | Plan review comments |

### TMI Tables

| Table | Purpose |
|-------|---------|
| `initiatives` | TMI initiative definitions |
| `ground_stops` | Ground stop programs (legacy) |
| `gdp_programs` | Ground delay programs |
| `reroutes` | Reroute definitions |

### JATOC Tables

| Table | Purpose |
|-------|---------|
| `incidents` | ATC incidents |
| `incident_updates` | Incident timeline |
| `incident_types` | Incident categories |

### Configuration Tables

| Table | Purpose |
|-------|---------|
| `splits_areas` | Sector area definitions |
| `splits_configs` | Saved configurations |
| `advisories` | DCC advisories |

---

## Azure SQL (VATSIM_ADL)

### Flight Tables

| Table | Purpose |
|-------|---------|
| `adl_flights` | Current flight state |
| `adl_flights_history` | Historical snapshots |
| `adl_trajectories` | Position history |
| `adl_parse_queue` | Routes awaiting parsing |
| `adl_parsed_routes` | Expanded route waypoints |

### TMI Tables (NTML) - v17

| Table | Purpose |
|-------|---------|
| `ntml` | National Traffic Management Log - unified GS/GDP program registry |
| `ntml_info` | Program event log / audit trail |
| `ntml_slots` | Arrival slot allocation (GDP only) |
| `adl_flight_tmi` | Flight-level TMI assignments (EDCTs, slots, exemptions) |

#### ntml (Program Registry)

| Column | Type | Description |
|--------|------|-------------|
| `program_id` | INT | Primary key (identity) |
| `program_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `ctl_element` | NVARCHAR(8) | Control element - airport (KJFK) or FCA |
| `element_type` | NVARCHAR(8) | APT or FCA |
| `program_type` | NVARCHAR(16) | GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP, CTOP |
| `program_name` | COMPUTED | Auto: KJFK-GS-01091530 |
| `adv_number` | NVARCHAR(16) | Advisory number (ADVZY 001) |
| `start_utc` | DATETIME2(0) | Program start time |
| `end_utc` | DATETIME2(0) | Program end time |
| `status` | NVARCHAR(16) | PROPOSED, ACTIVE, COMPLETED, PURGED, SUPERSEDED |
| `program_rate` | INT | Default arrivals/hour (GDP) |
| `reserve_rate` | INT | Reserved slots/hour for pop-ups |
| `delay_limit_min` | INT | Maximum assignable delay (default: 180) |
| `scope_type` | NVARCHAR(16) | TIER, DISTANCE, MANUAL |
| `scope_tier` | TINYINT | Scope tier (1, 2, 3) |
| `exemptions_json` | NVARCHAR(MAX) | Exemption rules (JSON) |
| `impacting_condition` | NVARCHAR(64) | WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER |
| `total_flights` | INT | Computed: total affected flights |
| `controlled_flights` | INT | Computed: flights under control |
| `avg_delay_min` | DECIMAL(8,2) | Computed: average delay |

#### ntml_info (Event Log)

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | BIGINT | Primary key |
| `program_id` | INT | FK to ntml |
| `flight_uid` | BIGINT | FK to adl_flight_core (if flight event) |
| `slot_id` | BIGINT | FK to ntml_slots (if slot event) |
| `event_type` | NVARCHAR(32) | PROGRAM_CREATED, FLIGHT_CONTROLLED, SLOT_ASSIGNED, etc. |
| `event_message` | NVARCHAR(512) | Human-readable message |
| `performed_by` | NVARCHAR(64) | User who performed action |
| `performed_utc` | DATETIME2(0) | Event timestamp |

#### ntml_slots (GDP Slots)

| Column | Type | Description |
|--------|------|-------------|
| `slot_id` | BIGINT | Primary key |
| `program_id` | INT | FK to ntml |
| `slot_name` | NVARCHAR(16) | FSM-style name (KJFK.091530A) |
| `slot_index` | INT | Slot sequence number |
| `slot_time_utc` | DATETIME2(0) | Slot arrival time |
| `slot_type` | NVARCHAR(16) | REGULAR, RESERVED, UNASSIGNED |
| `slot_status` | NVARCHAR(16) | OPEN, ASSIGNED, BRIDGED, HELD, CANCELLED |
| `assigned_flight_uid` | BIGINT | FK to adl_flight_core |
| `assigned_callsign` | NVARCHAR(12) | Flight callsign |

#### adl_flight_tmi (Flight Assignments)

| Column | Type | Description |
|--------|------|-------------|
| `flight_uid` | BIGINT | FK to adl_flight_core |
| `program_id` | INT | FK to ntml |
| `slot_id` | BIGINT | FK to ntml_slots |
| `aslot` | NVARCHAR(16) | Assigned arrival slot name |
| `edct_utc` | DATETIME2(0) | Expected Departure Clearance Time |
| `cta_utc` | DATETIME2(0) | Controlled Time of Arrival |
| `octd_utc` | DATETIME2(0) | Original Controlled Time of Departure |
| `octa_utc` | DATETIME2(0) | Original Controlled Time of Arrival |
| `ctl_prgm` | NVARCHAR(32) | Control program name |
| `ctl_type` | NVARCHAR(8) | GS, GDP, AFP, CTOP |
| `ctl_exempt` | BIT | Flight is exempt |
| `ctl_exempt_reason` | NVARCHAR(32) | Exemption reason |
| `program_delay_min` | INT | Assigned program delay |
| `gs_held` | BIT | Flight held by Ground Stop |
| `gs_release_utc` | DATETIME2(0) | GS release time |
| `is_popup` | BIT | Pop-up flight |
| `ecr_pending` | BIT | EDCT Change Request pending |

### Reroute Tables - v17

| Table | Purpose |
|-------|---------|
| `tmi_reroutes` | Reroute definitions with scope/filters |
| `tmi_reroute_flights` | Flight assignments and compliance |
| `tmi_reroute_compliance_log` | Historical compliance snapshots |

#### tmi_reroutes

| Column | Type | Description |
|--------|------|-------------|
| `reroute_id` | INT | Primary key |
| `reroute_guid` | UNIQUEIDENTIFIER | External reference |
| `status` | TINYINT | 0=draft, 1=proposed, 2=active, 3=monitoring, 4=expired, 5=cancelled |
| `name` | NVARCHAR(64) | Reroute name (e.g., "ZNY WEST GATE") |
| `adv_number` | NVARCHAR(16) | Advisory number |
| `start_utc` | DATETIME2(0) | Effective start |
| `end_utc` | DATETIME2(0) | Effective end |
| `time_basis` | NVARCHAR(8) | ETD or ETA |
| `protected_segment` | NVARCHAR(MAX) | Required route segment |
| `protected_fixes` | NVARCHAR(MAX) | Required fixes (JSON array) |
| `avoid_fixes` | NVARCHAR(MAX) | Fixes to avoid (JSON array) |
| `route_type` | NVARCHAR(8) | FULL or PARTIAL |
| `origin_airports` | NVARCHAR(MAX) | Origin airport filter |
| `origin_centers` | NVARCHAR(MAX) | Origin ARTCC filter |
| `dest_airports` | NVARCHAR(MAX) | Destination airport filter |
| `dest_centers` | NVARCHAR(MAX) | Destination ARTCC filter |
| `include_ac_cat` | NVARCHAR(16) | Aircraft category (ALL, JET, PROP) |
| `weight_class` | NVARCHAR(16) | Weight class filter |
| `exempt_airports` | NVARCHAR(MAX) | Exempt origins |
| `exempt_carriers` | NVARCHAR(MAX) | Exempt carriers |
| `airborne_filter` | NVARCHAR(16) | NOT_AIRBORNE, ALL, AIRBORNE_ONLY |
| `total_assigned` | INT | Flights assigned |
| `compliant_count` | INT | Fully compliant flights |
| `compliance_rate` | DECIMAL(5,2) | Compliance percentage |

#### tmi_reroute_flights

| Column | Type | Description |
|--------|------|-------------|
| `flight_id` | BIGINT | Primary key |
| `reroute_id` | INT | FK to tmi_reroutes |
| `flight_key` | NVARCHAR(64) | Flight identifier |
| `callsign` | NVARCHAR(16) | Flight callsign |
| `dep_icao` | NCHAR(4) | Departure airport |
| `dest_icao` | NCHAR(4) | Destination airport |
| `route_at_assign` | NVARCHAR(MAX) | Route when assigned |
| `assigned_route` | NVARCHAR(MAX) | Required route |
| `current_route` | NVARCHAR(MAX) | Current filed route |
| `compliance_status` | NVARCHAR(16) | PENDING, COMPLIANT, PARTIAL, NON_COMPLIANT, EXEMPT |
| `compliance_pct` | DECIMAL(5,2) | Compliance percentage |
| `protected_fixes_crossed` | NVARCHAR(MAX) | Which protected fixes crossed |
| `route_delta_nm` | INT | Route distance change |
| `ete_delta_min` | INT | Flight time change |

### Reference Tables

| Table | Purpose |
|-------|---------|
| `airports` | Airport data |
| `navaids` | Navigation aids |
| `waypoints` | Fix/waypoint data |
| `airways` | Airway definitions |
| `sids` | Standard Instrument Departures |
| `stars` | Standard Terminal Arrivals |

### Boundary Tables

| Table | Purpose |
|-------|---------|
| `artcc_boundaries` | ARTCC geographic boundaries |
| `sector_boundaries` | Sector boundaries |
| `tracon_boundaries` | TRACON boundaries |

### Weather Tables

| Table | Purpose |
|-------|---------|
| `adl_weather_alerts` | Active SIGMETs/AIRMETs |
| `adl_atis` | ATIS data |
| `wind_data` | Upper wind forecasts |

### Airport Configuration (v16)

| Table | Purpose |
|-------|---------|
| `airport_config` | Runway configurations |
| `airport_config_runway` | Runways per config |
| `airport_config_rate` | Rates per config |
| `runway_in_use` | Current runway assignments |
| `manual_rate_override` | Manual rate overrides |
| `rate_history` | Rate change audit trail |
| `vatsim_atis` | Raw ATIS broadcasts with weather |

### Config Modifiers (v17)

| Table | Purpose |
|-------|---------|
| `modifier_category` | Modifier categories (PARALLEL_OPS, APPROACH_TYPE, etc.) |
| `modifier_type` | Modifier definitions (SIMOS, ILS, CAT_II, etc.) |
| `config_modifier` | Links modifiers to configs/runways |

### ATIS Views (v17)

| View | Purpose |
|------|---------|
| `vw_current_atis_by_type` | Current ATIS records by airport and type |
| `vw_effective_atis` | Effective ATIS source decision (ARR+DEP > COMB) |
| `vw_current_runways_in_use` | Active runway assignments |
| `vw_current_airport_config` | Current config summary |
| `vw_config_with_modifiers` | Configs with aggregated modifiers |
| `vw_runway_with_modifiers` | Runways with aggregated modifiers |

### ATFM Simulator Reference (v17)

| Table | Purpose |
|-------|---------|
| `sim_ref_carrier_lookup` | 17 US carriers with IATA/ICAO codes |
| `sim_ref_route_patterns` | 3,989 O-D routes with hourly patterns |
| `sim_ref_airport_demand` | 107 airports with demand curves |

### Airspace Demand Functions (v17)

| Function | Purpose |
|----------|---------|
| `fn_FixDemand` | Flights at a navigation fix in a time window |
| `fn_AirwaySegmentDemand` | Flights on an airway segment between two fixes |
| `fn_RouteSegmentDemand` | Flights between two fixes (airway or direct) |

### Demand Monitors (v17)

| Table | Purpose |
|-------|---------|
| `demand_monitors` | Shared demand monitor definitions (fix, segment, airway, via_fix types) |

---

## Key Relationships

### Flight → Route

```
adl_flights.id ──▶ adl_parsed_routes.flight_id
```

### Ground Stop → Flights

```
ground_stop_programs.id ──▶ ground_stop_flights.program_id
```

### Airport → Configuration

```
airports.icao ──▶ airport_config.airport
airport_config.id ──▶ airport_config_runway.config_id
airport_config.id ──▶ airport_config_rate.config_id
```

---

## Indexes

Critical indexes for performance:

| Table | Index | Purpose |
|-------|-------|---------|
| `adl_flights` | `idx_destination` | Arrival queries |
| `adl_flights` | `idx_departure` | Departure queries |
| `adl_flights` | `idx_phase` | Phase filtering |
| `adl_flights_history` | `idx_snapshot_utc` | Historical queries |
| `adl_parsed_routes` | `idx_flight_seq` | Route ordering |
| `adl_flight_waypoints` | `IX_waypoint_fix_eta` | Fix demand queries (v17) |
| `adl_flight_waypoints` | `IX_waypoint_airway_eta` | Airway segment queries (v17) |

---

## Migrations

Migrations are located in:
- `database/migrations/` - MySQL
- `adl/migrations/` - Azure SQL

Apply in numerical order within each category.

---

## See Also

- [[Architecture]] - System overview
- [[Data Flow]] - Data pipelines
- [[Deployment]] - Database setup
