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

## Azure SQL (VATSIM_TMI)

Dedicated database for unified TMI (Traffic Management Initiative) operations.

**Server:** `vatsim.database.windows.net`
**Credentials:** Contact PERTI administrator

### TMI Program Tables

| Table | Purpose |
|-------|---------|
| `tmi_programs` | Program registry - GS, GDP, AFP (replaces VATSIM_ADL.ntml) |
| `tmi_slots` | Arrival slot allocation with FSM-format naming |
| `tmi_flight_control` | Per-flight TMI control assignments (EDCTs, slots) |
| `tmi_events` | Audit/event history log |
| `tmi_popup_queue` | Pop-up flight detection queue |

#### tmi_programs (Program Registry)

| Column | Type | Description |
|--------|------|-------------|
| `program_id` | INT | Primary key (identity) |
| `program_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `ctl_element` | NVARCHAR(8) | Control element - airport (KJFK) or FCA |
| `element_type` | NVARCHAR(8) | APT, FCA, or FEA |
| `program_type` | NVARCHAR(16) | GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP, BLANKET, COMPRESSION |
| `program_name` | NVARCHAR(64) | Display name (e.g., "KJFK GDP #1") |
| `adv_number` | NVARCHAR(16) | Advisory number |
| `start_utc` | DATETIME2(0) | Program start time |
| `end_utc` | DATETIME2(0) | Program end time |
| `cumulative_start` | DATETIME2(0) | Original start (for extensions) |
| `cumulative_end` | DATETIME2(0) | Latest end (for extensions) |
| `status` | NVARCHAR(16) | PROPOSED, MODELING, ACTIVE, PAUSED, COMPLETED, PURGED, SUPERSEDED |
| `is_proposed` | BIT | Program in proposed state |
| `is_active` | BIT | Program currently active |
| `program_rate` | INT | Default arrivals/hour (AAR) |
| `reserve_rate` | INT | Reserved slots/hour for pop-ups |
| `delay_limit_min` | INT | Maximum assignable delay (default: 180) |
| `target_delay_mult` | DECIMAL(3,2) | Target delay multiplier (UDP) |
| `rates_hourly_json` | NVARCHAR(MAX) | Hourly rate profile JSON |
| `reserve_hourly_json` | NVARCHAR(MAX) | Pop-up reserve rates by hour |
| `scope_type` | NVARCHAR(16) | TIER, DISTANCE, CENTER, ALL |
| `scope_tier` | TINYINT | Scope tier (1, 2, 3) |
| `scope_distance_nm` | INT | Distance radius (if DISTANCE) |
| `scope_json` | NVARCHAR(MAX) | Scope definition (JSON) |
| `exemptions_json` | NVARCHAR(MAX) | Exemption rules (JSON) |
| `exempt_airborne` | BIT | Exempt airborne flights |
| `exempt_within_min` | INT | Exempt flights within minutes |
| `flt_incl_carrier` | NVARCHAR(512) | Include carrier filter |
| `flt_incl_type` | NVARCHAR(8) | Include aircraft type filter |
| `flt_incl_fix` | NVARCHAR(8) | Include arrival fix filter |
| `impacting_condition` | NVARCHAR(32) | WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER |
| `cause_text` | NVARCHAR(512) | Detailed cause description |
| `comments` | NVARCHAR(MAX) | Internal notes |
| `prob_extension` | NVARCHAR(8) | Extension probability |
| `revision_number` | INT | Revision number |
| `parent_program_id` | INT | FK to parent (for transitions) |
| `successor_program_id` | INT | FK to successor revision |
| `total_flights` | INT | Computed: total affected flights |
| `controlled_flights` | INT | Computed: flights under control |
| `exempt_flights` | INT | Computed: exempt flights |
| `airborne_flights` | INT | Computed: airborne flights |
| `avg_delay_min` | DECIMAL(8,2) | Computed: average delay |
| `max_delay_min` | INT | Computed: maximum delay |
| `total_delay_min` | BIGINT | Computed: total delay |
| `created_by` | NVARCHAR(64) | Creating user |
| `created_at` | DATETIME2(0) | Creation timestamp |
| `updated_at` | DATETIME2(0) | Last update timestamp |
| `activated_by` | NVARCHAR(64) | Activating user |
| `activated_at` | DATETIME2(0) | Activation timestamp |
| `purged_by` | NVARCHAR(64) | Purging user |
| `purged_at` | DATETIME2(0) | Purge timestamp |
| `model_time_utc` | DATETIME2(0) | Model time |
| `modified_by` | NVARCHAR(64) | Last modifier |
| `modified_utc` | DATETIME2(0) | Last modification time |

#### tmi_slots (Arrival Slots)

FSM-format slot naming: `ccc[c].ddddddL` (e.g., KJFK.091530A)

| Column | Type | Description |
|--------|------|-------------|
| `slot_id` | BIGINT | Primary key |
| `program_id` | INT | FK to tmi_programs |
| `slot_name` | NVARCHAR(20) | FSM format (e.g., "KJFK.091530A") |
| `slot_index` | INT | Sequential index within program |
| `slot_time_utc` | DATETIME2(0) | Slot arrival time |
| `slot_type` | NVARCHAR(16) | REGULAR, RESERVED, UNASSIGNED |
| `slot_status` | NVARCHAR(16) | OPEN, ASSIGNED, BRIDGED, HELD, CANCELLED, COMPRESSED |
| `bin_date` | DATE | Time bin date |
| `bin_hour` | TINYINT | Time bin hour (0-23) |
| `bin_quarter` | TINYINT | Time bin quarter (0, 15, 30, 45) |
| `assigned_flight_uid` | BIGINT | FK to adl_flight_core |
| `assigned_callsign` | NVARCHAR(12) | Flight callsign |
| `assigned_carrier` | NVARCHAR(8) | Carrier code |
| `assigned_origin` | NVARCHAR(4) | Origin airport |
| `original_eta_utc` | DATETIME2(0) | ETA before slot assignment |
| `slot_delay_min` | INT | Delay imposed by this slot |
| `sl_hold` | BIT | Held by carrier |
| `sl_hold_carrier` | NVARCHAR(8) | Carrier holding slot |
| `subbable` | BIT | Available for substitution |
| `bridge_from_slot_id` | BIGINT | Original slot in bridge chain |
| `bridge_to_slot_id` | BIGINT | Target slot in bridge chain |
| `bridge_reason` | NVARCHAR(32) | ECR, SCS, COMPRESSION |
| `is_popup_slot` | BIT | Assigned to pop-up flight |

#### tmi_flight_control (Flight Assignments)

| Column | Type | Description |
|--------|------|-------------|
| `control_id` | BIGINT | Primary key |
| `flight_uid` | BIGINT | FK to adl_flight_core |
| `callsign` | NVARCHAR(12) | Flight callsign |
| `program_id` | INT | FK to tmi_programs |
| `slot_id` | BIGINT | FK to tmi_slots |
| `ctd_utc` | DATETIME2(0) | Controlled Time of Departure |
| `cta_utc` | DATETIME2(0) | Controlled Time of Arrival |
| `octd_utc` | DATETIME2(0) | Original CTD (never changes) |
| `octa_utc` | DATETIME2(0) | Original CTA (never changes) |
| `aslot` | NVARCHAR(20) | Assigned slot name |
| `ctl_elem` | NVARCHAR(8) | Control element (airport/FCA) |
| `ctl_prgm` | NVARCHAR(64) | Control program name |
| `ctl_type` | NVARCHAR(8) | GDP, AFP, GS, DAS, GAAP, UDP, COMP, BLKT, ECR, ADPT, ABRG, CTOP |
| `ctl_exempt` | BIT | Flight is exempt |
| `ctl_exempt_reason` | NVARCHAR(32) | AIRBORNE, DISTANCE, CENTER, CARRIER, TYPE, EARLY, LATE, MANUAL |
| `program_delay_min` | INT | Assigned delay (minutes) |
| `delay_capped` | BIT | Hit delay limit |
| `orig_etd_utc` | DATETIME2(0) | Original ETD before control |
| `orig_eta_utc` | DATETIME2(0) | Original ETA before control |
| `gs_held` | BIT | Currently ground stopped |
| `gs_release_utc` | DATETIME2(0) | Scheduled GS release time |
| `is_popup` | BIT | Pop-up flight |
| `is_recontrol` | BIT | Re-controlled flight |
| `ecr_pending` | BIT | EDCT Change Request pending |
| `ecr_requested_cta` | DATETIME2(0) | Requested new CTA |
| `compliance_status` | NVARCHAR(16) | PENDING, COMPLIANT, EARLY, LATE, NO_SHOW |
| `actual_dep_utc` | DATETIME2(0) | Actual departure time |
| `compliance_delta_min` | INT | Minutes early(-) or late(+) |

#### tmi_events (Audit Log)

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | BIGINT | Primary key |
| `event_type` | NVARCHAR(32) | PROGRAM_CREATED, SLOT_ASSIGNED, FLIGHT_CONTROLLED, etc. |
| `event_subtype` | NVARCHAR(32) | Additional categorization |
| `program_id` | INT | FK to tmi_programs |
| `slot_id` | BIGINT | FK to tmi_slots |
| `flight_uid` | BIGINT | FK to adl_flight_core |
| `ctl_element` | NVARCHAR(8) | Airport/FCA |
| `callsign` | NVARCHAR(12) | Flight callsign |
| `details_json` | NVARCHAR(MAX) | Event details (JSON) |
| `old_value` | NVARCHAR(256) | Previous value |
| `new_value` | NVARCHAR(256) | New value |
| `description` | NVARCHAR(512) | Event description |
| `event_source` | NVARCHAR(16) | USER, SYSTEM, DAEMON, API, COMPRESSION |
| `event_user` | NVARCHAR(64) | Acting user |
| `event_utc` | DATETIME2(3) | Event timestamp |

#### tmi_popup_queue (Pop-up Detection)

| Column | Type | Description |
|--------|------|-------------|
| `queue_id` | BIGINT | Primary key |
| `flight_uid` | BIGINT | FK to adl_flight_core |
| `callsign` | NVARCHAR(12) | Flight callsign |
| `program_id` | INT | FK to tmi_programs |
| `detected_utc` | DATETIME2(0) | Detection timestamp |
| `flight_eta_utc` | DATETIME2(0) | ETA when detected |
| `lead_time_min` | INT | Minutes before ETA |
| `queue_status` | NVARCHAR(16) | PENDING, PROCESSING, ASSIGNED, EXEMPT, FAILED, EXPIRED |
| `assigned_slot_id` | BIGINT | FK to tmi_slots |
| `assignment_type` | NVARCHAR(16) | RESERVED, DAS, GAAP |

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
