# Database Schema

> **Last updated:** February 10, 2026 (v18)

PERTI uses multiple databases across three engines: MySQL for application data, Azure SQL for flight/ADL and TMI data, and PostgreSQL/PostGIS for spatial queries.

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

### Review Tables

| Table | Purpose |
|-------|---------|
| `r_tmr_reports` | TMR (Traffic Management Review) reports - NTMO Guide-style post-event reviews with auto-save (v18) |

#### r_tmr_reports (v18)

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key (auto-increment) |
| `plan_id` | INT | FK to p_plans |
| `report_data` | JSON | Full report content (NTMO Guide structure) |
| `status` | VARCHAR | Report status (draft, submitted, finalized) |
| `created_by` | VARCHAR | CID of report creator |
| `created_at` | DATETIME | Creation timestamp |
| `updated_at` | DATETIME | Last update timestamp |

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

### NOD Facility Flow Tables (v18)

| Table | Purpose |
|-------|---------|
| `facility_flow_configs` | NOD facility flow configurations |
| `facility_flow_elements` | Flow configuration elements (fixes, procedures, routes, gates) |
| `facility_flow_gates` | Flow gate definitions with geographic coordinates |

#### facility_flow_configs

| Column | Type | Description |
|--------|------|-------------|
| `config_id` | INT | Primary key (identity) |
| `facility_code` | NVARCHAR(8) | Facility identifier (ARTCC/TRACON code) |
| `config_name` | NVARCHAR(64) | Configuration display name |
| `config_description` | NVARCHAR(512) | Configuration description |
| `is_active` | BIT | Configuration is currently active |
| `is_default` | BIT | Default configuration for the facility |
| `created_by` | NVARCHAR(64) | Creating user CID |
| `created_at` | DATETIME2(0) | Creation timestamp |
| `updated_at` | DATETIME2(0) | Last update timestamp |

#### facility_flow_elements

| Column | Type | Description |
|--------|------|-------------|
| `element_id` | INT | Primary key (identity) |
| `config_id` | INT | FK to facility_flow_configs |
| `element_type` | NVARCHAR(16) | FIX, PROCEDURE, ROUTE, or GATE |
| `element_name` | NVARCHAR(64) | Element display name |
| `element_data` | NVARCHAR(MAX) | Element definition data (JSON) |
| `color` | NVARCHAR(16) | Display color (hex) |
| `line_weight` | INT | Display line weight |
| `line_style` | NVARCHAR(16) | Display line style (solid, dashed, dotted) |
| `is_visible` | BIT | Element is visible on map |
| `fea_enabled` | BIT | Flow element analysis enabled |
| `display_order` | INT | Rendering order |
| `created_at` | DATETIME2(0) | Creation timestamp |
| `updated_at` | DATETIME2(0) | Last update timestamp |

#### facility_flow_gates

| Column | Type | Description |
|--------|------|-------------|
| `gate_id` | INT | Primary key (identity) |
| `element_id` | INT | FK to facility_flow_elements |
| `gate_name` | NVARCHAR(64) | Gate display name |
| `gate_type` | NVARCHAR(16) | Gate type classification |
| `lat` | DECIMAL(10,7) | Gate latitude |
| `lon` | DECIMAL(11,7) | Gate longitude |
| `geojson` | NVARCHAR(MAX) | Gate geometry (GeoJSON) |
| `created_at` | DATETIME2(0) | Creation timestamp |

### Airport Taxi Reference (v18)

| Table | Purpose |
|-------|---------|
| `airport_taxi_reference` | Airport taxi time reference data (3,628 airports) |
| `airport_taxi_reference_detail` | Taxi reference detail breakdowns (EAV pattern) |

Methodology: FAA ASPM p5-p15 average, 90-day rolling window, minimum 50 samples. Default value is 600 seconds (10 minutes), blended toward observed data as sample count grows. Refreshed daily via `dbo.sp_RefreshAirportTaxiReference` stored procedure run through the stats job framework.

#### airport_taxi_reference

| Column | Type | Description |
|--------|------|-------------|
| `airport_icao` | NVARCHAR(4) | Primary key - airport ICAO code |
| `taxi_out_seconds` | INT | Average taxi-out time (seconds) |
| `taxi_in_seconds` | INT | Average taxi-in time (seconds) |
| `sample_count` | INT | Number of observations in rolling window |
| `last_updated` | DATETIME2(0) | Last refresh timestamp |

#### airport_taxi_reference_detail

Entity-Attribute-Value (EAV) pattern for breakdowns by weight class, carrier, engine configuration, and destination region.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key (identity) |
| `airport_icao` | NVARCHAR(4) | FK to airport_taxi_reference |
| `detail_type` | NVARCHAR(16) | WEIGHT_CLASS, CARRIER, ENGINE_CONFIG, or DEST_REGION |
| `detail_value` | NVARCHAR(32) | Type-specific value (e.g., "H" for heavy, "AAL" for carrier) |
| `taxi_out_seconds` | INT | Average taxi-out time for this segment |
| `taxi_in_seconds` | INT | Average taxi-in time for this segment |
| `sample_count` | INT | Number of observations for this segment |

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

### TMI Advisory & Entry Tables (v18)

| Table | Purpose |
|-------|---------|
| `tmi_advisories` | TMI advisory messages (ADVZY, NTML postings) |
| `tmi_entries` | TMI log entries (MIT, AFP, restrictions) |
| `tmi_flight_list` | Flight lists for programs (per-program flight roster) |
| `tmi_public_routes` | Published public route visualizations |

#### tmi_advisories

| Column | Type | Description |
|--------|------|-------------|
| `advisory_id` | INT | Primary key (identity) |
| `advisory_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `advisory_number` | NVARCHAR(16) | Advisory number (e.g., ADVZY 001) |
| `advisory_type` | NVARCHAR(16) | Advisory type classification |
| `ctl_element` | NVARCHAR(8) | Control element (airport/FCA) |
| `element_type` | NVARCHAR(8) | APT, FCA, or FEA |
| `scope_facilities` | NVARCHAR(MAX) | Affected facilities |
| `program_id` | INT | FK to tmi_programs (if program-related) |
| `program_rate` | INT | Program rate (if applicable) |
| `delay_cap` | INT | Delay cap (if applicable) |
| `effective_from` | DATETIME2(0) | Effective start |
| `effective_until` | DATETIME2(0) | Effective end |
| `subject` | NVARCHAR(256) | Advisory subject line |
| `body_text` | NVARCHAR(MAX) | Full advisory text |
| `reason_code` | NVARCHAR(32) | Reason code |
| `reroute_id` | INT | FK to tmi_reroutes (if reroute advisory) |
| `reroute_name` | NVARCHAR(64) | Reroute name |
| `reroute_string` | NVARCHAR(MAX) | Reroute string |
| `mit_miles` | INT | MIT distance (if MIT advisory) |
| `mit_type` | NVARCHAR(16) | MIT type |
| `mit_fix` | NVARCHAR(8) | MIT fix |
| `status` | NVARCHAR(16) | Advisory status |
| `source_type` | NVARCHAR(16) | Source type (USER, SYSTEM) |
| `discord_message_id` | NVARCHAR(32) | Discord message ID (if posted) |
| `created_by` | NVARCHAR(64) | Creating user |
| `created_at` | DATETIME2(0) | Creation timestamp |

#### tmi_entries

| Column | Type | Description |
|--------|------|-------------|
| `entry_id` | INT | Primary key (identity) |
| `entry_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `determinant_code` | NVARCHAR(16) | Determinant code |
| `protocol_type` | NVARCHAR(16) | Protocol type |
| `entry_type` | NVARCHAR(16) | Entry type (MIT, AFP, restriction) |
| `ctl_element` | NVARCHAR(8) | Control element |
| `requesting_facility` | NVARCHAR(8) | Requesting facility |
| `providing_facility` | NVARCHAR(8) | Providing facility |
| `restriction_value` | INT | Restriction value |
| `restriction_unit` | NVARCHAR(8) | Restriction unit (NM, MIN) |
| `reason_code` | NVARCHAR(32) | Reason code |
| `valid_from` | DATETIME2(0) | Valid from |
| `valid_until` | DATETIME2(0) | Valid until |
| `status` | NVARCHAR(16) | Entry status |
| `source_type` | NVARCHAR(16) | Source type |
| `discord_message_id` | NVARCHAR(32) | Discord message ID |

#### tmi_flight_list

| Column | Type | Description |
|--------|------|-------------|
| `list_id` | BIGINT | Primary key (identity) |
| `program_id` | INT | FK to tmi_programs |
| `flight_gufi` | NVARCHAR(64) | Flight GUFI |
| `callsign` | NVARCHAR(12) | Flight callsign |
| `flight_uid` | BIGINT | FK to adl_flight_core |
| `dep_airport` | NVARCHAR(4) | Departure airport |
| `arr_airport` | NVARCHAR(4) | Arrival airport |
| `original_etd_utc` | DATETIME2(0) | Original ETD |
| `original_eta_utc` | DATETIME2(0) | Original ETA |
| `edct_utc` | DATETIME2(0) | Assigned EDCT |
| `cta_utc` | DATETIME2(0) | Controlled time of arrival |
| `delay_minutes` | INT | Assigned delay |
| `slot_id` | BIGINT | FK to tmi_slots |
| `is_exempt` | BIT | Flight is exempt |
| `compliance_status` | NVARCHAR(16) | Compliance status |

#### tmi_public_routes

| Column | Type | Description |
|--------|------|-------------|
| `route_id` | INT | Primary key (identity) |
| `route_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `status` | NVARCHAR(16) | Route status (ACTIVE, EXPIRED) |
| `name` | NVARCHAR(64) | Route display name |
| `adv_number` | NVARCHAR(16) | Advisory number |
| `route_string` | NVARCHAR(MAX) | Route string |
| `advisory_text` | NVARCHAR(MAX) | Advisory text |
| `color` | NVARCHAR(16) | Map display color |
| `line_weight` | INT | Map line weight |
| `line_style` | NVARCHAR(16) | Map line style |
| `valid_start_utc` | DATETIME2(0) | Validity start |
| `valid_end_utc` | DATETIME2(0) | Validity end |
| `route_geojson` | NVARCHAR(MAX) | Route geometry (GeoJSON) |
| `coordination_status` | NVARCHAR(16) | Coordination status |

### TMI Coordination & Proposal Tables (v18)

| Table | Purpose |
|-------|---------|
| `tmi_proposals` | Multi-facility coordination proposals |
| `tmi_proposal_facilities` | Facilities involved in proposal approval |
| `tmi_proposal_reactions` | Facility approval/denial reactions |

#### tmi_proposals

| Column | Type | Description |
|--------|------|-------------|
| `proposal_id` | INT | Primary key (identity) |
| `proposal_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `entry_id` | INT | FK to tmi_entries |
| `entry_type` | NVARCHAR(16) | Entry type being proposed |
| `requesting_facility` | NVARCHAR(8) | Facility requesting coordination |
| `providing_facility` | NVARCHAR(8) | Facility providing coordination |
| `ctl_element` | NVARCHAR(8) | Control element |
| `entry_data_json` | NVARCHAR(MAX) | Full proposal data (JSON) |
| `approval_deadline_utc` | DATETIME2(0) | Deadline for facility responses |
| `status` | NVARCHAR(16) | PENDING, APPROVED, DENIED, EXPIRED, WITHDRAWN |
| `requires_unanimous` | BIT | Requires all facilities to approve |
| `facilities_approved` | INT | Count of approvals |
| `facilities_denied` | INT | Count of denials |
| `discord_channel_id` | NVARCHAR(32) | Coordination thread channel |
| `discord_message_id` | NVARCHAR(32) | Coordination message |
| `program_id` | INT | FK to tmi_programs (if promoted) |

#### tmi_proposal_facilities

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `proposal_id` | INT | FK to tmi_proposals |
| `facility_code` | NVARCHAR(8) | Facility code |
| `role` | NVARCHAR(16) | REQUESTING, PROVIDING, AFFECTED |
| `status` | NVARCHAR(16) | PENDING, APPROVED, DENIED |
| `responded_at` | DATETIME2(0) | Response timestamp |
| `responded_by` | NVARCHAR(64) | Responding user |

#### tmi_proposal_reactions

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `proposal_id` | INT | FK to tmi_proposals |
| `facility_code` | NVARCHAR(8) | Reacting facility |
| `reaction_type` | NVARCHAR(16) | APPROVE, DENY |
| `user_id` | NVARCHAR(64) | Reacting user |
| `reacted_at` | DATETIME2(0) | Reaction timestamp |

### TMI Reroute Support Tables (v18)

| Table | Purpose |
|-------|---------|
| `tmi_reroute_routes` | Reroute route strings per origin/destination pair |
| `tmi_reroute_drafts` | User-saved reroute drafts |

#### tmi_reroute_routes

| Column | Type | Description |
|--------|------|-------------|
| `route_id` | INT | Primary key (identity) |
| `reroute_id` | INT | FK to tmi_reroutes |
| `origin` | NVARCHAR(4) | Origin airport |
| `destination` | NVARCHAR(4) | Destination airport |
| `route_string` | NVARCHAR(MAX) | Route string for this O/D pair |
| `origin_filter` | NVARCHAR(MAX) | Origin filter criteria |
| `dest_filter` | NVARCHAR(MAX) | Destination filter criteria |

#### tmi_reroute_drafts

| Column | Type | Description |
|--------|------|-------------|
| `draft_id` | INT | Primary key (identity) |
| `reroute_id` | INT | FK to tmi_reroutes (if editing existing) |
| `user_id` | NVARCHAR(64) | Draft owner CID |
| `draft_data` | NVARCHAR(MAX) | Draft reroute data (JSON) |
| `status` | NVARCHAR(16) | DRAFT, SUBMITTED |
| `created_at` | DATETIME2(0) | Creation timestamp |
| `updated_at` | DATETIME2(0) | Last update timestamp |

### TMI Airport & Delay Tables (v18)

| Table | Purpose |
|-------|---------|
| `tmi_airport_configs` | TMI airport configuration snapshots |
| `tmi_delay_entries` | Delay reports and trends |
| `tmi_discord_posts` | Discord message posting queue (multi-org) |

#### tmi_airport_configs

| Column | Type | Description |
|--------|------|-------------|
| `config_id` | INT | Primary key (identity) |
| `config_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `airport` | NVARCHAR(4) | Airport code |
| `timestamp_utc` | DATETIME2(0) | Config snapshot timestamp |
| `conditions` | NVARCHAR(16) | Weather conditions (VMC, IFR, etc.) |
| `arrival_runways` | NVARCHAR(MAX) | Active arrival runways |
| `departure_runways` | NVARCHAR(MAX) | Active departure runways |
| `aar` | INT | Airport Acceptance Rate |
| `adr` | INT | Airport Departure Rate |
| `source_type` | NVARCHAR(16) | Source (ATIS, MANUAL, AUTO) |
| `event_id` | INT | FK to tmi_events (triggering event) |

#### tmi_delay_entries

| Column | Type | Description |
|--------|------|-------------|
| `delay_id` | INT | Primary key (identity) |
| `delay_guid` | UNIQUEIDENTIFIER | External reference GUID |
| `delay_type` | NVARCHAR(16) | Delay classification |
| `airport` | NVARCHAR(4) | Affected airport |
| `facility` | NVARCHAR(8) | Reporting facility |
| `timestamp_utc` | DATETIME2(0) | Report timestamp |
| `delay_minutes` | INT | Average delay (minutes) |
| `delay_trend` | NVARCHAR(16) | INCREASING, STABLE, DECREASING |
| `holding_status` | NVARCHAR(16) | Holding status |
| `holding_fix` | NVARCHAR(8) | Holding fix (if applicable) |
| `reason` | NVARCHAR(256) | Delay reason |
| `program_id` | INT | FK to tmi_programs (if TMI-related) |

#### tmi_discord_posts

| Column | Type | Description |
|--------|------|-------------|
| `post_id` | INT | Primary key (identity) |
| `entity_type` | NVARCHAR(16) | Entity type (PROGRAM, ADVISORY, ENTRY) |
| `entity_id` | INT | Entity ID |
| `org_code` | NVARCHAR(8) | Discord organization code |
| `channel_purpose` | NVARCHAR(32) | Channel purpose (NTML, ADVZY, etc.) |
| `channel_id` | NVARCHAR(32) | Discord channel ID |
| `message_id` | NVARCHAR(32) | Discord message ID |
| `status` | NVARCHAR(16) | PENDING, SENT, FAILED, RETRY |
| `retry_count` | INT | Number of retry attempts |
| `direction` | NVARCHAR(8) | OUTBOUND or INBOUND |
| `approval_status` | NVARCHAR(16) | Approval status (for coordination) |

---

## Azure SQL (VATSIM_REF)

Reference data database for navigation and airspace definitions.

**Server:** `vatsim.database.windows.net`
**Database:** `VATSIM_REF`

### Navigation Reference Tables

| Table | Purpose |
|-------|---------|
| `nav_fixes` | Navigation fixes/waypoints (VORs, NDBs, RNAV fixes) |
| `airways` | Airway definitions (J, Q, V, T routes) |
| `airway_segments` | Airway segment waypoints with sequence |
| `nav_procedures` | SIDs and STARs |
| `coded_departure_routes` | CDRs for traffic management |
| `playbook_routes` | Playbook route definitions |
| `area_centers` | ARTCC center reference points |
| `oceanic_fir_bounds` | Oceanic FIR boundaries |
| `ref_sync_log` | AIRAC update synchronization log |

#### nav_fixes

| Column | Type | Description |
|--------|------|-------------|
| `fix_id` | INT | Primary key |
| `fix_name` | NVARCHAR(8) | Fix identifier (e.g., BNA, MERIT) |
| `lat` | DECIMAL(10,7) | Latitude |
| `lon` | DECIMAL(11,7) | Longitude |
| `fix_type` | NVARCHAR(16) | VOR, NDB, RNAV, INTERSECTION, WAYPOINT |
| `navaid_class` | NVARCHAR(16) | For navaids: HIGH, LOW, TERMINAL |
| `frequency` | DECIMAL(7,3) | Navaid frequency (MHz/kHz) |
| `artcc` | NVARCHAR(4) | Containing ARTCC |
| `state` | NVARCHAR(2) | US state code |
| `country` | NVARCHAR(2) | Country code |

#### airways

| Column | Type | Description |
|--------|------|-------------|
| `airway_id` | INT | Primary key |
| `airway_name` | NVARCHAR(8) | Airway identifier (J48, Q100, V1) |
| `airway_type` | NVARCHAR(4) | J (jet), Q (RNAV jet), V (victor), T (RNAV low) |
| `base_altitude` | INT | Minimum enroute altitude |
| `top_altitude` | INT | Maximum altitude |

#### playbook_routes

| Column | Type | Description |
|--------|------|-------------|
| `playbook_id` | INT | Primary key |
| `play_name` | NVARCHAR(32) | Play code (e.g., ROD, SWAP) |
| `full_route` | NVARCHAR(MAX) | Complete route string |
| `origin_airports` | NVARCHAR(MAX) | Origin airport codes |
| `origin_tracons` | NVARCHAR(MAX) | Origin TRACON codes |
| `origin_artccs` | NVARCHAR(MAX) | Origin ARTCC codes |
| `dest_airports` | NVARCHAR(MAX) | Destination airport codes |
| `dest_tracons` | NVARCHAR(MAX) | Destination TRACON codes |
| `dest_artccs` | NVARCHAR(MAX) | Destination ARTCC codes |
| `altitude_min_ft` | INT | Minimum altitude |
| `altitude_max_ft` | INT | Maximum altitude |
| `is_active` | BIT | Route is currently active |
| `source` | NVARCHAR(16) | Data source (FAA, CUSTOM) |
| `effective_date` | DATE | AIRAC effective date |

---

## PostgreSQL (VATSIM_GIS)

Dedicated PostGIS-enabled database for spatial route analysis and boundary queries.

**Server:** `vatcscc-gis.postgres.database.azure.com`
**Database:** `VATSIM_GIS`
**Engine:** PostgreSQL 16 with PostGIS 3.4+

### Navigation Data Tables

| Table | Purpose |
|-------|---------|
| `nav_fixes` | Navigation fixes/waypoints with coordinates |
| `airways` | Airway definitions (J, Q, V, T routes) |
| `airway_segments` | Airway segment waypoints with sequence |
| `airports` | Airport data with ICAO/IATA codes |
| `area_centers` | ARTCC/TRACON center reference points |
| `playbook_routes` | CDR/Playbook route definitions |

#### nav_fixes

| Column | Type | Description |
|--------|------|-------------|
| `fix_name` | VARCHAR(8) | Fix identifier (e.g., BNA, MERIT) |
| `lat` | DECIMAL(10,7) | Latitude |
| `lon` | DECIMAL(11,7) | Longitude |
| `fix_type` | VARCHAR(16) | VOR, NDB, RNAV, INTERSECTION |

#### airway_segments

| Column | Type | Description |
|--------|------|-------------|
| `airway_id` | INT | FK to airways |
| `sequence_num` | INT | Segment order on airway |
| `from_fix` | VARCHAR(8) | Starting fix |
| `to_fix` | VARCHAR(8) | Ending fix |
| `from_lat` / `from_lon` | DECIMAL | Start coordinates |
| `to_lat` / `to_lon` | DECIMAL | End coordinates |

### Boundary Tables (PostGIS Geometry)

| Table | Purpose |
|-------|---------|
| `artcc_boundaries` | ARTCC/FIR geographic boundaries |
| `sector_boundaries` | Sector boundaries (LOW, HIGH, SUPERHIGH) |
| `tracon_boundaries` | TRACON approach control boundaries |

#### artcc_boundaries

| Column | Type | Description |
|--------|------|-------------|
| `artcc_code` | VARCHAR(4) | ARTCC identifier (ZNY, ZLA) |
| `fir_name` | VARCHAR(64) | Full FIR name |
| `icao_code` | VARCHAR(4) | ICAO code |
| `floor_altitude` | INT | Floor altitude (feet) |
| `ceiling_altitude` | INT | Ceiling altitude (feet) |
| `is_oceanic` | BOOLEAN | Oceanic airspace flag |
| `geom` | GEOMETRY | PostGIS polygon geometry (SRID 4326) |

#### sector_boundaries

| Column | Type | Description |
|--------|------|-------------|
| `sector_code` | VARCHAR(16) | Sector identifier (ZNY_42) |
| `sector_name` | VARCHAR(64) | Sector display name |
| `parent_artcc` | VARCHAR(4) | Parent ARTCC code |
| `sector_type` | VARCHAR(16) | LOW, HIGH, or SUPERHIGH |
| `floor_altitude` | INT | Floor altitude |
| `ceiling_altitude` | INT | Ceiling altitude |
| `geom` | GEOMETRY | PostGIS polygon geometry |

#### tracon_boundaries

| Column | Type | Description |
|--------|------|-------------|
| `tracon_code` | VARCHAR(16) | TRACON identifier (N90, A80) |
| `tracon_name` | VARCHAR(64) | TRACON name |
| `parent_artcc` | VARCHAR(4) | Parent ARTCC |
| `floor_altitude` | INT | Floor altitude |
| `ceiling_altitude` | INT | Ceiling altitude |
| `geom` | GEOMETRY | PostGIS polygon geometry |

### PostGIS Functions

Route expansion and spatial analysis functions.

| Function | Purpose |
|----------|---------|
| `resolve_waypoint(fix)` | Resolve fix/airport to coordinates |
| `expand_airway(airway, from, to)` | Expand airway segment to waypoints |
| `expand_route(route_string)` | Parse and expand full route |
| `expand_route_with_artccs(route)` | Expand route + get traversed ARTCCs |
| `get_route_artccs(route)` | Lightweight ARTCC list from route |
| `expand_route_with_boundaries(route, alt)` | Full boundary analysis |
| `expand_playbook_route(pb_code)` | Expand playbook route (PB.PLAY.ORIG.DEST) |
| `analyze_route_from_waypoints(waypoints)` | Analyze pre-expanded waypoints |
| `expand_routes_batch(routes[])` | Batch expand multiple routes |
| `expand_routes_with_geojson(routes[])` | Batch expand with GeoJSON output |
| `expand_routes_full(routes[], alt)` | Full batch analysis with sectors |
| `routes_to_geojson_collection(routes[])` | Convert routes to GeoJSON FeatureCollection |

Batch boundary detection functions (for daemon processing).

| Function | Purpose |
|----------|---------|
| `get_artcc_at_point(lat, lon)` | Single-point ARTCC lookup (prefers non-oceanic, smallest area) |
| `detect_boundaries_batch(flights_jsonb)` | Row-by-row batch boundary detection for multiple flights |
| `detect_boundaries_batch_optimized(flights_jsonb)` | Set-based batch detection (faster for >100 flights) |
| `detect_sector_for_flight(lat, lon, alt)` | Get sector(s) containing a flight at given altitude |

### Boundary Adjacency Network

Precomputed adjacency relationships between airspace boundaries.

| Table | Purpose |
|-------|---------|
| `boundary_adjacency` | Boundary neighbor relationships (graph edges) |

#### boundary_adjacency

| Column | Type | Description |
|--------|------|-------------|
| `adjacency_id` | SERIAL | Primary key |
| `source_type` | VARCHAR(20) | Source boundary type (ARTCC, TRACON, SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH) |
| `source_code` | VARCHAR(50) | Source boundary code |
| `source_name` | VARCHAR(100) | Source boundary name |
| `target_type` | VARCHAR(20) | Target boundary type |
| `target_code` | VARCHAR(50) | Target boundary code |
| `target_name` | VARCHAR(100) | Target boundary name |
| `adjacency_class` | ENUM | POINT (corner touch), LINE (shared border), POLY (overlap) |
| `shared_length_nm` | FLOAT | Length of shared boundary (NULL for POINT) |
| `shared_points` | INT | Number of shared points (for POINT type) |
| `intersection_geom` | GEOMETRY | The actual shared geometry |
| `computed_at` | TIMESTAMPTZ | When adjacency was computed |

**Adjacency Classes**

| Class | Description | Tier Value |
|-------|-------------|------------|
| POINT | Corner touch only (0-dimensional) | 0.5 tier |
| LINE | Shared border segment (1-dimensional) | 1.0 tier |
| POLY | Overlapping area (2-dimensional, rare) | 1.0 tier |

### PostGIS Indexes

| Index | Table | Purpose |
|-------|-------|---------|
| `idx_nav_fixes_fix_name` | nav_fixes | Fix lookup |
| `idx_airways_name` | airways | Airway name lookup |
| `idx_airway_segments_lookup` | airway_segments | Segment lookup |
| `idx_airports_icao_lookup` | airports | Airport ICAO lookup |
| `idx_artcc_geom_gist` | artcc_boundaries | Spatial index (GIST) |
| `idx_sector_geom_gist` | sector_boundaries | Spatial index (GIST) |
| `idx_tracon_geom_gist` | tracon_boundaries | Spatial index (GIST) |
| `idx_adj_source` | boundary_adjacency | Source boundary lookup |
| `idx_adj_target` | boundary_adjacency | Target boundary lookup |
| `idx_adj_class` | boundary_adjacency | Adjacency class filter |
| `idx_adj_geom` | boundary_adjacency | Spatial index (GIST) |

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
- `database/migrations/` - MySQL and Azure SQL feature migrations
- `adl/migrations/` - ADL-specific schema changes

Apply in numerical order within each category.

### v18 Migrations

| Migration | Database | Purpose |
|-----------|----------|---------|
| `database/migrations/nod/001_facility_flow_tables.sql` | VATSIM_ADL | NOD facility flow configs, elements, and gates |
| `database/migrations/nod/002_flow_element_fea_linkage.sql` | VATSIM_ADL | Flow element FEA (Flow Element Analysis) linkage |
| `database/migrations/tmr/` | perti_site (MySQL) | TMR report table (`r_tmr_reports`) |
| `adl/migrations/oooi/010_airport_taxi_reference.sql` | VATSIM_ADL | Airport taxi reference and detail tables, stored procedure |

### Earlier Migration Directories

| Directory | Feature Area |
|-----------|-------------|
| `database/migrations/tmi/` | TMI programs, slots, procedures, views |
| `database/migrations/swim/` | SWIM API schema (FIXM tables, telemetry, keys) |
| `database/migrations/schema/` | ADL schema changes, splits, ACD data |
| `database/migrations/postgis/` | PostGIS boundary tables |
| `database/migrations/gdp/` | GDP-specific tables |
| `database/migrations/initiatives/` | Plan initiative timeline |
| `database/migrations/jatoc/` | Incident reporting |
| `database/migrations/reroute/` | Reroute tables |
| `database/migrations/sua/` | Special Use Airspace |
| `database/migrations/advisories/` | DCC/NOD advisories |
| `database/migrations/vatsim_stats/` | Statistics schema |
| `database/migrations/adl/` | ADL event tables |
| `adl/migrations/core/` | Core 8-table flight schema |
| `adl/migrations/boundaries/` | Boundary detection |
| `adl/migrations/crossings/` | Boundary crossing predictions |
| `adl/migrations/demand/` | Fix/segment demand functions |
| `adl/migrations/eta/` | ETA trajectory calculation |
| `adl/migrations/navdata/` | Waypoint/procedure imports |
| `adl/migrations/changelog/` | Flight change tracking triggers |
| `adl/migrations/cifp/` | CIFP procedure legs |

---

## See Also

- [[Architecture]] - System overview
- [[Data Flow]] - Data pipelines
- [[Deployment]] - Database setup
