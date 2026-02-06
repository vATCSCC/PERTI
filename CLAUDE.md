# PERTI Project Instructions for Claude

## Project Overview

**PERTI** (Plan, Execute, Review, Train, Improve) is a web-based air traffic flow management platform for the VATSIM virtual ATC network. It is operated by vATCSCC (Virtual Air Traffic Control System Command Center) and simulates real-world FAA ATCSCC traffic management functions.

**Live site**: `https://perti.vatcscc.org`

### Domain Glossary

| Term | Meaning |
|------|---------|
| **PERTI** | Plan, Execute, Review, Train, Improve - the operational planning cycle |
| **ADL** | Aggregate Demand List - real-time list of all active/planned flights with ETAs |
| **TMI** | Traffic Management Initiative - programs like GDPs, Ground Stops, reroutes |
| **GDP** | Ground Delay Program - assigns EDCTs to slow arrivals at congested airports |
| **GS** | Ground Stop - halts all departures to a specific airport |
| **GDT** | Ground Delay Table - visual display of GDP slot assignments |
| **NTML** | National Traffic Management Log - public record of TMI actions |
| **EDCT** | Expect Departure Clearance Time - assigned departure time under a GDP |
| **AAR/ADR** | Airport Acceptance Rate / Airport Departure Rate |
| **ATIS** | Automatic Terminal Information Service - runway/weather broadcast |
| **SUA** | Special Use Airspace (MOAs, restricted areas, etc.) |
| **TFR** | Temporary Flight Restriction |
| **SWIM** | System Wide Information Management - the project's public API layer |
| **CIFP** | Coded Instrument Flight Procedures (DPs/STARs) |
| **DP/STAR** | Departure Procedure / Standard Terminal Arrival Route |
| **FIR** | Flight Information Region (international airspace boundary) |
| **NOD** | North Atlantic Organized Track system display |
| **JATOC** | Joint Air Traffic Operations Center (incident management) |
| **CID** | VATSIM Certificate ID (user identifier) |
| **OOOI** | Out-Off-On-In (flight phase gate events) |
| **OpLevel** | Operational Level 1-4 (traffic impact severity) |
| **RBS** | Ration By Schedule (GDP slot assignment algorithm) |

## Database & Infrastructure Access

**IMPORTANT**: You have full access to all project databases and Azure resources.

Credentials and connection details are documented in: `.claude/credentials.md`

Read this file when you need to:
- Query any database directly
- Access Azure resources via Kudu SSH
- Connect to MySQL, Azure SQL, or PostgreSQL databases
- Use API keys or OAuth credentials

### Database Architecture

The project uses 7 databases across 3 database engines:

| Database | Engine | Host | Purpose |
|----------|--------|------|---------|
| `perti_site` | MySQL 8 | vatcscc-perti.mysql.database.azure.com | Main web app (plans, users, configs, staffing) |
| `VATSIM_ADL` | Azure SQL | vatsim.database.windows.net | Flight data (normalized 8-table architecture) |
| `VATSIM_TMI` | Azure SQL | vatsim.database.windows.net | Traffic management initiatives (GDP/GS/reroutes) |
| `VATSIM_REF` | Azure SQL | vatsim.database.windows.net | Reference data (airports, airways, navdata) |
| `SWIM_API` | Azure SQL | vatsim.database.windows.net | Public API database (FIXM-aligned schema) |
| `VATSIM_GIS` | PostgreSQL/PostGIS | vatcscc-gis.postgres.database.azure.com | Spatial queries (boundary intersection, route geometry) |
| `VATSIM_STATS` | Azure SQL | vatsim.database.windows.net | Statistics & analytics |

Azure resource config is in: `load/azure_perti_config.json`

### Database Connection Pattern

Connections are managed in `load/connect.php` with lazy-loading getters:

```php
// MySQL (perti_site) - always available
$conn_pdo   // PDO connection
$conn_sqli  // MySQLi connection

// Azure SQL - use getter functions (lazy loaded via sqlsrv extension)
$conn = get_conn_adl();   // VATSIM_ADL
$conn = get_conn_swim();  // SWIM_API
$conn = get_conn_tmi();   // VATSIM_TMI
$conn = get_conn_ref();   // VATSIM_REF

// PostgreSQL (PostGIS) - use getter function (lazy loaded via PDO pgsql)
$conn = get_conn_gis();   // VATSIM_GIS
```

- MySQL uses **PDO** and **MySQLi** (both available)
- Azure SQL uses **sqlsrv** extension (not PDO)
- PostgreSQL uses **PDO pgsql** extension
- Use `PERTI\Lib\Database` class for parameterized queries (see `lib/Database.php`)

### Key Database Tables by Database

Column format: `column_name (type)` — PK = primary key, FK = foreign key reference.

#### perti_site (MySQL) - Main web app

**`users`** - VATSIM-authenticated users
`id PK, cid, first_name, last_name, last_session_ip, last_selfcookie, updated_at, created_at`

**`admin_users`** - Admin-level users (same columns as users)

**`p_plans`** - PERTI event plans
`id PK, event_name, event_date, event_start, event_banner, oplevel (int), hotline, event_end_date, event_end_time, updated_at, created_at`

**`p_configs`** - Airport configs per plan
`id PK, p_id FK→p_plans, airport, weather (int), arrive, depart, aar, adr, comments, updated_at, created_at`

**`config_data`** - Default airport rate configs
`id PK, airport, arr, dep, vmc_aar, lvmc_aar, imc_aar, limc_aar, vmc_adr, imc_adr, updated_at, created_at`

**`p_terminal_staffing`** / **`p_enroute_staffing`** - Staffing per plan
`id PK, p_id FK, facility_name, staffing_status (int), staffing_quantity (int), comments, updated_at, created_at`

**`p_dcc_staffing`** - DCC staffing per plan
`id PK, p_id FK, position_name, position_facility, personnel_name, personnel_ois, updated_at, created_at`

**`p_terminal_constraints`** / **`p_enroute_constraints`** - Operational constraints
`id PK, p_id FK, location, context, date, impact, updated_at, created_at`

**`p_terminal_init`** / **`p_enroute_init`** - Initiative entries
`id PK, p_id FK, title, context, updated_at, created_at`

**`p_terminal_init_timeline`** / **`p_enroute_init_timeline`** - Initiative timelines
`id PK, p_id FK, facility, area, tmi_type, tmi_type_other, cause, start_datetime, end_datetime, level, notes (text), is_global (tinyint), advzy_number, created_by, created_at, updated_at`

**`p_terminal_init_times`** / **`p_enroute_init_times`** - Initiative time entries
`id PK, init_id FK, time, probability (int), updated_at, created_at`

**`p_terminal_planning`** / **`p_enroute_planning`** - Planning comments
`id PK, p_id FK, facility_name, comments (text), updated_at, created_at`

**`p_op_goals`** - Planning goals
`id PK, p_id FK, comments (text), updated_at, created_at`

**`p_forecast`** - Demand forecasts
`id PK, p_id FK, date, summary (text), image_url, updated_at, created_at`

**`p_historical`** - Historical reference data
`id PK, p_id FK, title, date, summary (text), image_url, source_url, updated_at, created_at`

**`p_group_flights`** - Group flight entries
`id PK, p_id FK, entity, dep, arr, etd, eta, pilot_quantity (int), route, updated_at, created_at`

**`r_scores`** / **`r_comments`** / **`r_data`** / **`r_ops_data`** - Post-event review data

**`assigned`** - PERTI role assignments
`id PK, e_id, e_title, e_date, p_cid, e_cid, r_cid, t_cid, i_cid, updated_at, created_at`

**`route_cdr`** - Coded Departure Routes
`fid PK, cdr_id, cdr_code, rte_orig, rte_dest, rte_dep_fix, rte_string, rte_dep_artcc, rte_arr_artcc, rte_t_artcc, rte_coord_rqd, pb_name, rte_nav_eqpt, rte_string_perti`

**`route_playbook`** - Playbook routes
`fid PK, pb_id, pb_name, pb_category, pb_route_advisory, pb_route_advisory_fca`

**`tmi_ground_stops`** - Legacy ground stop records
`id PK, status, name, ctl_element, element_type, airports, start_utc, end_utc, prob_ext, origin_centers, origin_airports, comments, adv_number, advisory_text`

#### VATSIM_ADL (Azure SQL) - Flight Data

**Core 8-table normalized flight architecture** (all keyed on `flight_uid bigint`):

**`adl_flight_core`** - Main flight record
`flight_uid PK, flight_key, cid, callsign, flight_id, phase, last_source, is_active, first_seen_utc, last_seen_utc, logon_time_utc, adl_date, adl_time, snapshot_utc, flight_phase, last_trajectory_tier, is_relevant, current_zone, current_zone_airport, current_artcc, current_artcc_id, current_tracon, current_tracon_id, boundary_updated_at, current_sector_low, current_sector_high, current_sector_superhigh, crossing_tier, crossing_last_calc_utc, crossing_needs_recalc, level_flight_confirmed`

**`adl_flight_plan`** - Filed flight plan
`flight_uid FK, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_dept_tracon, fp_dept_artcc, dfix, dp_name, dtrsn, fp_dest_tracon, fp_dest_artcc, afix, star_name, strsn, approach, runway, fp_route, fp_route_expanded, route_geometry (geography), waypoints_json, waypoint_count, parse_status, parse_tier, dep_runway, arr_runway, is_simbrief, fp_altitude_ft, fp_tas_kts, gcd_nm, aircraft_type, aircraft_equip, artccs_traversed, tracons_traversed, route_total_nm, route_dist_nm`

**`adl_flight_position`** - Current position data
`flight_uid FK, lat, lon, altitude_ft, altitude_assigned, altitude_cleared, groundspeed_kts, true_airspeed_kts, mach, vertical_rate_fpm, heading_deg, track_deg, dist_to_dest_nm, dist_flown_nm, pct_complete, position_updated_utc, position_geo (geography), route_dist_to_dest_nm, next_waypoint_seq, next_waypoint_name`

**`adl_flight_times`** - All timing data (50+ time columns)
`flight_uid FK, std_utc, etd_utc, etd_runway_utc, etd_source, atd_utc, atd_runway_utc, ctd_utc, edct_utc, sta_utc, eta_utc, eta_runway_utc, eta_source, eta_tfms_utc, ata_utc, ata_runway_utc, cta_utc, etd_dfix_utc, eta_afix_utc, eta_meterfix_utc, center_entry_utc, center_exit_utc, arrival_bucket_utc, departure_bucket_utc, ete_minutes, delay_minutes, out_utc, off_utc, on_utc, in_utc, eta_confidence, tod_dist_nm, tod_eta_utc, toc_eta_utc, eta_method, eta_wind_adj_kts, actual_off_block_time, actual_time_of_departure, actual_landing_time, actual_in_block_time, estimated_time_of_arrival, estimated_off_block_time, estimated_runway_arrival_time, controlled_time_of_departure, controlled_time_of_arrival, ...`

**`adl_flight_tmi`** - TMI control assignments
`flight_uid FK, ctl_type, ctl_element, delay_status, delay_minutes, delay_source, ctd_utc, cta_utc, edct_utc, slot_time_utc, slot_status, is_exempt, exempt_reason, reroute_status, reroute_id, program_id, slot_id, ctl_prgm, program_delay_min, delay_capped, gs_held, gs_release_utc, is_popup, is_recontrol, ecr_pending`

**`adl_flight_aircraft`** - Aircraft performance data
`flight_uid FK, aircraft_icao, aircraft_faa, weight_class, engine_type, engine_count, wake_category, cruise_tas_kts, ceiling_ft, airline_icao, airline_name, aircraft_updated_utc`

**`adl_flight_trajectory`** - Position history
`trajectory_id PK, flight_uid FK, recorded_utc, lat, lon, altitude_ft, groundspeed_kts, vertical_rate_fpm, heading_deg, source, tier, flight_phase, dist_to_dest_nm`

**`adl_flight_waypoints`** - Parsed route waypoints
`waypoint_id PK, flight_uid FK, sequence_num, fix_name, lat, lon, fix_type, source, on_airway, planned_alt_ft, is_toc, is_tod, eta_utc, on_dp, on_star, leg_type, alt_restriction, cum_dist_nm, segment_dist_nm`

**`adl_flight_planned_crossings`** - Boundary crossing predictions
`crossing_id PK, flight_uid FK, crossing_source, boundary_id, boundary_code, boundary_type, crossing_type, crossing_order, entry_fix_name, exit_fix_name, planned_entry_utc, planned_exit_utc, entry_lat, entry_lon`

**Legacy tables** (pre-normalization, still used by some features):
- `adl_flights` - Legacy monolithic flight table (150+ columns)
- `adl_flights_gdp` - GDP-scoped legacy flight copy
- `adl_flights_gs` - Ground stop-scoped legacy flight copy

**Supporting ADL tables:**

- `adl_boundary` - ARTCC/TRACON/sector boundary polygons with geography columns
- `adl_boundary_grid` - Pre-computed lat/lon grid → boundary lookup for fast detection
- `adl_flight_changelog` - Field-level change history (flight_uid, field_name, old_value, new_value)
- `adl_flight_archive` - Archived completed flights
- `adl_flight_legs` - Multi-leg itinerary linking
- `adl_flight_stepclimbs` - Step climb waypoints
- `adl_flight_boundary_log` - Boundary entry/exit events
- `adl_flight_weather_impact` - Weather impact detections
- `adl_parse_queue` - Route parsing queue (flight_uid, parse_tier, status, attempts)
- `adl_edct_overrides` - Manual EDCT overrides (flight_key, ctd_utc, ctl_type, ctl_element)
- `adl_staging_pilots` / `adl_staging_prefiles` - Ingest staging tables
- `adl_slots_gdp` - Legacy GDP slot table
- `adl_refresh_perf` - Ingest performance metrics
- `adl_zone_events` - Airport zone entry/exit events
- `adl_region_group` / `adl_region_group_members` / `adl_region_airports` - Region grouping

**Reference data in ADL:**

- `airlines` (airline_id PK, icao, iata, name, callsign, country, is_virtual, is_active)
- `apts` - Legacy airport table (ARPT_ID, ICAO_ID, ARPT_NAME, LAT_DECIMAL, LONG_DECIMAL, RESP_ARTCC_ID, etc.)
- `nav_fixes` (fix_id PK, fix_name, fix_type, lat, lon, artcc_id, position_geo)
- `nav_procedures` (procedure_id PK, procedure_type, airport_icao, procedure_name, computer_code, transition_name, full_route, runways)
- `nav_procedure_legs` (leg_id PK, procedure_id FK, sequence_num, fix_name, leg_type, alt_restriction, altitude_1_ft)
- `airways` / `airway_segments` - Airway definitions with segments
- `area_centers` - ARTCC/TRACON center points
- `coded_departure_routes` / `playbook_routes` - CDR and playbook routes
- `oceanic_fir_bounds` - FIR bounding boxes for oceanic tier filtering
- `fir_boundaries` / `fir_reference` - FIR boundary polygons and reference data
- `ACD_Data` - FAA Aircraft Characteristics Database (40+ columns: ICAO_Code, weight, speed, wingspan, etc.)
- `aircraft_performance_profiles` / `aircraft_performance_opf` / `aircraft_performance_apf` / `aircraft_performance_ptf` - BADA performance data
- `cifp_legs_staging` / `cifp_procedures_staging` - CIFP import staging
- `eta_citypair_lookup` - City-pair ETA lookup table

**Airport configuration:**

- `airport_config` (config_id PK, airport_faa, airport_icao, config_name, config_code, is_active)
- `airport_config_runway` (config_id FK, runway_id, runway_use, priority, approach_type)
- `airport_config_rate` (config_id FK, source, weather, rate_type, rate_value)
- `airport_config_history` / `airport_config_rate_history` - Change history
- `airport_geometry` - Airport zones (runways, taxiways, parking from OSM)
- `airport_grouping` / `airport_grouping_member` - Airport grouping definitions
- `airport_weather_impact` - Weather impact thresholds per airport
- `detected_runway_config` - Auto-detected runway configurations
- `runway_in_use` - Current runway-in-use from ATIS
- `runway_heading_ref` - Runway heading reference
- `manual_rate_override` - Manual AAR/ADR overrides
- `atis_config_history` - ATIS-derived runway config history
- `config_modifier` / `modifier_type` / `modifier_category` - Config modifier framework

**ARTCC/facility structure:**

- `artcc_facilities` (facility_id PK, facility_code, facility_name, facility_type, center_lat, center_lon)
- `artcc_adjacencies` - Adjacent facility relationships
- `artcc_tier_types` / `artcc_tier_groups` / `artcc_tier_group_members` - Tier hierarchy
- `facility_tier_configs` / `facility_tier_config_members` - Per-facility tier configurations
- `sector_boundaries` - Sector boundary polygons with geography
- `major_tracon` - Major TRACON reference

**Statistics (in ADL):**

- `flight_stats_daily` / `flight_stats_hourly` / `flight_stats_weekly` / `flight_stats_monthly_summary` / `flight_stats_yearly_summary`
- `flight_stats_airport` / `flight_stats_artcc` / `flight_stats_carrier` / `flight_stats_citypair` / `flight_stats_aircraft` / `flight_stats_tmi`
- `flight_stats_hourly_patterns` / `flight_stats_monthly_airport`
- `flight_stats_retention_tiers` / `flight_stats_run_log` / `flight_stats_job_config`
- `flight_phase_snapshot` - Periodic phase count snapshots

**Events:**

- `division_events` (event_id PK, source, external_id, event_name, start_utc, end_utc, airports_json, routes_json)
- `perti_events` (event_id PK, event_name, event_type, start_utc, end_utc, featured_airports, logging_enabled, status)
- `event_position_log` - Controller position captures during events

**Discord integration:**

- `discord_channels` / `discord_messages` / `discord_reactions` / `discord_sent_messages` / `discord_webhook_log` / `discord_rate_limits`
- `dcc_advisories` / `dcc_advisory_sequences` / `dcc_discord_tmi` - DCC advisory system

**Other ADL tables:**

- `splits_configs` / `splits_positions` / `splits_areas` / `splits_presets` / `splits_preset_positions` - Sector split management
- `sua_definitions` / `sua_activations` / `sua_aggregate_members` - SUA data
- `swim_api_keys` - SWIM API key management (copy in ADL)
- `public_routes` - Publicly visible reroute routes
- `demand_monitors` - Fix/segment demand monitor definitions
- `scheduler_state` - Daemon scheduler state
- `gdp_log` - Legacy GDP program log
- `ntml` / `ntml_slots` / `ntml_info` - NTML program records (legacy)
- `r_airport_totals` / `r_hourly_rates` - Review ops data
- `ref_artcc_adjacency` / `ref_major_airports` - Reference lookups
- `sim_ref_route_patterns` / `sim_ref_airport_demand` / `sim_ref_carrier_lookup` / `sim_ref_import_log` - Statistical simulation reference
- `jatoc_incidents` / `jatoc_reports` / `jatoc_updates` / `jatoc_personnel` / `jatoc_ops_level` / `jatoc_daily_ops` / `jatoc_special_emphasis` / `jatoc_user_roles` / `jatoc_sequences` - JATOC incident management
- `adl_archive_config` / `adl_archive_log` - Archival system config
- `adl_tmi_state` / `adl_tmi_trajectory` - TMI-specific state and trajectory
- `adl_trajectory_archive` / `adl_trajectory_compressed` - Trajectory storage tiers
- `adl_changelog_batch` / `adl_changelog_change_types` - Changelog batching
- `bada_import_log` / `bada_import_staging` - BADA data import
- `military_ownership_codes` / `military_service_codes` - Military reference codes

#### VATSIM_TMI (Azure SQL) - Traffic Management

**`tmi_programs`** - TMI programs (GDP, GS, AFP, reroutes)
`program_id PK, program_guid, ctl_element, element_type, program_type, program_name, adv_number, start_utc, end_utc, status, is_proposed, is_active, program_rate, reserve_rate, delay_limit_min, rates_hourly_json, scope_json, exemptions_json, impacting_condition, cause_text, total_flights, controlled_flights, exempt_flights, avg_delay_min, max_delay_min, total_delay_min, compression_enabled, scope_type, scope_tier, flt_incl_carrier, flt_incl_type, proposal_id, proposal_status, coordination_deadline_utc, created_by, created_at, updated_at, activated_at, purged_at, cancelled_by, cancelled_at`

**`tmi_slots`** - GDP time slots
`slot_id PK, program_id FK, slot_name, slot_index, slot_time_utc, slot_type, slot_status, bin_hour, bin_quarter, assigned_flight_uid, assigned_callsign, assigned_carrier, assigned_origin, assigned_at, ctd_utc, cta_utc, sl_hold, subbable, bridge_from_slot_id, bridge_to_slot_id, original_eta_utc, slot_delay_min, is_popup_slot, is_archived`

**`tmi_flight_control`** - Per-flight TMI control records
`control_id PK, flight_uid, callsign, program_id, slot_id, ctd_utc, cta_utc, octd_utc, octa_utc, ctl_elem, ctl_prgm, ctl_type, ctl_exempt, program_delay_min, delay_capped, sl_hold, subbable, gs_held, gs_release_utc, is_popup, is_recontrol, ecr_pending, compliance_status, actual_dep_utc, actual_arr_utc, compliance_delta_min, dep_airport, arr_airport, dep_center, arr_center`

**`tmi_flight_list`** - Flight lists for programs
`list_id PK, program_id FK, flight_gufi, callsign, flight_uid, dep_airport, arr_airport, original_etd_utc, original_eta_utc, edct_utc, cta_utc, delay_minutes, slot_id, is_exempt, compliance_status`

**`tmi_advisories`** - TMI advisory messages
`advisory_id PK, advisory_guid, advisory_number, advisory_type, ctl_element, element_type, scope_facilities, program_id, program_rate, delay_cap, effective_from, effective_until, subject, body_text, reason_code, reroute_id, reroute_name, reroute_string, mit_miles, mit_type, mit_fix, status, source_type, discord_message_id, created_by, created_at`

**`tmi_events`** - TMI event log
`event_id PK, entity_type, entity_id, entity_guid, program_id, flight_uid, slot_id, event_type, event_detail, field_name, old_value, new_value, event_data_json, source_type, actor_id, event_utc`

**`tmi_proposals`** - Coordination proposals
`proposal_id PK, proposal_guid, entry_id, entry_type, requesting_facility, providing_facility, ctl_element, entry_data_json, approval_deadline_utc, status, requires_unanimous, facilities_approved, facilities_denied, discord_channel_id, discord_message_id, program_id`

**`tmi_proposal_facilities`** / **`tmi_proposal_reactions`** - Proposal approval tracking

**`tmi_entries`** - TMI log entries (MIT, AFP, restrictions)
`entry_id PK, entry_guid, determinant_code, protocol_type, entry_type, ctl_element, requesting_facility, providing_facility, restriction_value, restriction_unit, reason_code, valid_from, valid_until, status, source_type, discord_message_id`

**`tmi_reroutes`** - Active reroute definitions
`reroute_id PK, reroute_guid, status, name, adv_number, start_utc, end_utc, protected_segment, protected_fixes, avoid_fixes, route_type, origin_airports/tracons/centers, dest_airports/tracons/centers, departure_fix, arrival_fix, comments, total_assigned, compliant_count, compliance_rate`

**`tmi_reroute_routes`** - Reroute route strings per O/D pair
`route_id PK, reroute_id FK, origin, destination, route_string, origin_filter, dest_filter`

**`tmi_reroute_flights`** / **`tmi_reroute_compliance_log`** - Reroute flight tracking & compliance

**`tmi_reroute_drafts`** - User reroute drafts

**`tmi_public_routes`** - Published public route visualizations
`route_id PK, route_guid, status, name, adv_number, route_string, advisory_text, color, line_weight, line_style, valid_start_utc, valid_end_utc, route_geojson, coordination_status`

**`tmi_airport_configs`** - TMI airport config snapshots
`config_id PK, config_guid, airport, timestamp_utc, conditions, arrival_runways, departure_runways, aar, adr, source_type, event_id`

**`tmi_delay_entries`** - Delay reports
`delay_id PK, delay_guid, delay_type, airport, facility, timestamp_utc, delay_minutes, delay_trend, holding_status, holding_fix, reason, program_id`

**`tmi_discord_posts`** - Discord message posting queue
`post_id PK, entity_type, entity_id, org_code, channel_purpose, channel_id, message_id, status, retry_count, direction, approval_status`

**`tmi_popup_queue`** - Popup flight detection queue

**`tmi_program_coordination_log`** - Multi-facility coordination log

**`tmi_flow_providers`** / **`tmi_flow_measures`** / **`tmi_flow_events`** / **`tmi_flow_event_participants`** - External flow control integration (ECFMP etc.)

**Views:** `vw_tmi_active_advisories`, `vw_tmi_active_entries`, `vw_reroute_drafts_active`

#### VATSIM_REF (Azure SQL) - Reference Data

- `nav_fixes` (fix_id PK, fix_name, fix_type, lat, lon, artcc_id, position_geo)
- `nav_procedures` (procedure_id PK, procedure_type, airport_icao, procedure_name, computer_code, transition_name, full_route, runways)
- `airways` (airway_id PK, airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix)
- `airway_segments` (segment_id PK, airway_id FK, airway_name, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, segment_geo)
- `area_centers` (center_id PK, center_code, center_type, center_name, lat, lon, parent_artcc, position_geo)
- `coded_departure_routes` (cdr_id PK, cdr_code, full_route, origin_icao, dest_icao, direction, is_active)
- `playbook_routes` (playbook_id PK, play_name, full_route, origin_airports, dest_airports, origin_artccs, dest_artccs)
- `oceanic_fir_bounds` (fir_id PK, fir_code, fir_name, fir_type, min_lat, max_lat, min_lon, max_lon)
- `ref_sync_log` - Sync tracking

#### SWIM_API (Azure SQL) - Public FIXM-aligned API

**`swim_flights`** - Denormalized flight snapshot for API consumers (120+ columns)
Key columns: `flight_uid, flight_key, gufi, callsign, cid, lat, lon, altitude_ft, groundspeed_kts, fp_dept_icao, fp_dest_icao, fp_route, phase, is_active, eta_utc, eta_runway_utc, etd_utc, out_utc, off_utc, on_utc, in_utc, edct_utc, gs_held, ctl_type, ctl_prgm, aircraft_type, weight_class, current_artcc, current_tracon, metering_point, metering_time, metering_status, sequence_number, arrival_stream`

**`swim_api_keys`** - API key management
`id PK, api_key, tier, owner_name, owner_email, source_id, can_write, allowed_sources, ip_whitelist, expires_at, is_active, owner_cid`

**`swim_audit_log`** - API request audit log
`id PK, api_key_id FK, endpoint, method, ip_address, request_time, response_code, response_time_ms`

**`swim_ground_stops`** - Ground stop data for API consumers

**Views:** `vw_swim_active_flights`, `vw_swim_flights_oooi_compat`, `vw_swim_tmi_controlled`

#### VATSIM_GIS (PostgreSQL/PostGIS) - Spatial

**`artcc_boundaries`** - ARTCC boundary polygons
`boundary_id PK, artcc_code, fir_name, icao_code, vatsim_region, vatsim_division, floor_altitude, ceiling_altitude, is_oceanic, label_lat, label_lon, geom (geometry NOT NULL), sector`

**`tracon_boundaries`** - TRACON boundary polygons
`tracon_id PK, tracon_code, tracon_name, parent_artcc, sector_code, floor_altitude, ceiling_altitude, geom (geometry NOT NULL)`

**`sector_boundaries`** - Sector boundary polygons
`sector_id PK, sector_code, sector_name, parent_artcc, sector_type, floor_altitude, ceiling_altitude, geom (geometry NOT NULL)`

**`airports`** - Airport points with PostGIS geometry
`airport_id PK, arpt_id, icao_id, arpt_name, lat, lon, elev, resp_artcc_id, computer_id, artcc_name, twr_type_code, dcc_region, aspm77, oep35, core30, tower, approach, departure, approach_id, geom (geometry)`

**Reference data (mirrored from REF):**

- `nav_fixes` (fix_id, fix_name, fix_type, lat, lon, geom)
- `nav_procedures` (procedure_id, procedure_type, airport_icao, procedure_name)
- `airways` / `airway_segments` - With segment_geom geometry columns
- `area_centers` - With geom geometry
- `coded_departure_routes` / `playbook_routes`

**Spatial utilities:**

- `boundary_adjacency` - Pre-computed boundary adjacency relationships
- `facility_reference` - ARTCC reference with tier1/tier2 artccs and major airports
- `tmi_density_cache` - Cached spatial TMI analysis results

**Views:** `airports_by_artcc`, `airports_by_tracon`, `major_airports`, `military_airports`, `towered_airports`, `artcc_code_mapping`, `boundary_stats`

#### VATSIM_STATS (Azure SQL) - Statistics & Analytics

*Note: Database may be paused on free tier. Resume via Azure Portal if needed.*

Statistics tables are primarily located in VATSIM_ADL under the `flight_stats_*` prefix (see ADL section above). The VATSIM_STATS database contains additional analytics views and aggregations.

### Migration Files

Migrations are organized under `database/migrations/` by feature area:
- `tmi/` - TMI schema (programs, slots, procedures, views)
- `swim/` - SWIM API schema (FIXM tables, telemetry, keys)
- `schema/` - ADL schema changes, splits, ACD data
- `postgis/` - PostGIS boundary tables
- `gdp/` - GDP-specific tables
- `initiatives/` - Plan initiative timeline
- `jatoc/` - Incident reporting
- `reroute/` - Reroute tables
- `sua/` - Special Use Airspace
- `advisories/` - DCC/NOD advisories
- `vatsim_stats/` - Statistics schema
- `adl/` - ADL event tables

Additional ADL-specific migrations in `adl/migrations/` organized by:
- `core/` - Core 8-table flight schema
- `boundaries/` - Boundary detection
- `crossings/` - Boundary crossing predictions
- `demand/` - Fix/segment demand functions
- `eta/` - ETA trajectory calculation
- `navdata/` - Waypoint/procedure imports
- `changelog/` - Flight change tracking triggers
- `cifp/` - CIFP procedure legs

## Project Structure

### Top-Level Directories

```
/                          Root (PHP pages served directly)
/api/                      PHP REST API endpoints
/adl/                      ADL subsystem (daemons, migrations, analysis)
/assets/                   Frontend assets (JS, CSS, images)
/database/                 Database migrations and schema tools
/discord-bot/              Node.js Discord Gateway bot
/docs/                     API documentation (swim/, stats/)
/integrations/             External system integrations
/lib/                      Core PHP utility classes
/load/                     Configuration, includes, shared PHP
/login/                    VATSIM OAuth login flow
/scripts/                  Daemons, utilities, maintenance scripts
/services/                 Service layer classes
/sessions/                 Session handler
/simulator/                ATC simulator module
```

### Top-Level PHP Pages

| File | Purpose |
|------|---------|
| `index.php` | Home page - PERTI plan listing and management |
| `plan.php` | Individual PERTI plan detail (terminal/enroute staffing, constraints, initiatives) |
| `schedule.php` | Event schedule viewer |
| `demand.php` | ADL demand charts (fix/segment demand visualization) |
| `splits.php` | Sector split configuration tool |
| `reroutes.php` | Reroute management interface |
| `reroutes_index.php` | Public reroute index |
| `route.php` | Route visualization with MapLibre |
| `review.php` | Post-event review/scoring |
| `sheet.php` | Planning sheet view |
| `gdt.php` | Ground Delay Table display |
| `nod.php` | North Atlantic Organized Track display |
| `status.php` | System status page |
| `swim.php` | SWIM API info page |
| `swim-doc.php` | SWIM API documentation viewer |
| `jatoc.php` | Joint Air Traffic Operations Center (incident management) |
| `tmi-publish.php` | TMI publishing to Discord (NTML/advisories) |
| `advisory-builder.php` | Advisory message builder |
| `airspace-elements.php` | Airspace element viewer |
| `sua.php` | Special Use Airspace display |
| `event-aar.php` | Event-specific AAR configuration |
| `airport_config.php` | Airport configuration editor |
| `data.php` | Data API router |
| `simulator.php` | ATC simulator interface |
| `transparency.php` | Transparency/about page |
| `privacy.php` | Privacy policy |
| `healthcheck.php` | Azure health check endpoint |

### API Endpoints (`/api/`)

**ADL (Flight Data)** - `/api/adl/`:
- `current.php` - Get current active flights
- `flight.php` - Individual flight details
- `ingest.php` - Flight data ingestion endpoint
- `waypoints.php` - Flight waypoint data
- `boundaries.php` - Boundary detection data
- `import_boundaries.php` - Boundary import trigger
- `snapshot_history.php` - Historical ADL snapshots
- `cleanup-queue.php` - Parse queue cleanup
- `atis-debug.php` - ATIS debugging
- `diagnose.php`, `diagnostic.php` - Diagnostic endpoints
- `timing-analysis.php` - ETA timing analysis
- `demand/fix.php` - Fix-based demand
- `demand/segment.php` - Route segment demand
- `demand/airway.php` - Airway demand
- `demand/details.php` - Demand detail drill-down
- `demand/debug.php` - Demand debugging

**Data (Reference/Planning)** - `/api/data/`:
- `plans.l.php` - List all plans
- `plans/configs.php` - Airport configs for a plan
- `plans/forecast.php` - Demand forecasts
- `plans/goals.php` - Planning goals
- `plans/historical.php` - Historical data
- `plans/outlook.php` - Traffic outlook
- `plans/term_inits.php`, `enroute_inits.php` - Terminal/enroute initiatives
- `plans/term_inits_timeline.php`, `enroute_inits_timeline.php` - Initiative timelines
- `plans/term_planning.php`, `enroute_planning.php` - Planning data
- `plans/term_staffing.php`, `enroute_staffing.php`, `dcc_staffing.php` - Staffing data
- `plans/term_constraints.php`, `enroute_constraints.php` - Constraints
- `plans/group_flights.php` - Flight grouping data
- `fixes.php` - Navigation fix lookup
- `routes.php` - Route data
- `reroutes.php` - Reroute reference data
- `schedule.php` - Schedule data
- `weather.php` - Weather data
- `weather_impacts.php` - Weather impact analysis
- `sua.php` - SUA data, `sua/activations.php` - SUA activations
- `sigmets.php` - SIGMET data
- `tfr.php` - TFR data
- `personnel.php` - Personnel data
- `rate_history.php` - Rate change history
- `review/data.php`, `review/scores.php`, `review/comments.php` - Review data
- `sheet/configs.php`, `sheet/term_staffing.php`, etc. - Sheet view data
- `airspace_elements/list.php`, `get.php`, `lookup.php` - Airspace elements
- `crossings/forecast.php` - Boundary crossing forecasts

**TMI (Traffic Management)** - `/api/tmi/`:
- `gdp_preview.php` - Preview GDP impact before applying
- `gdp_apply.php` - Apply GDP (create slots, assign EDCTs)
- `gdp_simulate.php` - Simulate GDP
- `gdp_purge.php`, `gdp_purge_local.php` - Purge GDP data
- `gs_preview.php` - Preview Ground Stop
- `gs_simulate.php` - Simulate Ground Stop

**Splits** - `/api/splits/`:
- `index.php` - List split configs
- `config.php` - CRUD for split configurations
- `sectors.php` - Sector data
- `maps.php` - Sector map data
- `scheduler.php` - Split scheduling
- `sample.php`, `debug.php`, `test.php` - Development/debug

**Stats** - `/api/stats/`:
- `realtime.php` - Real-time statistics
- `hourly.php` - Hourly aggregates
- `daily.php` - Daily aggregates
- `flight_phase_history.php` - Phase transition history
- `status.php` - Stats system status
- `StatsHelper.php` - Shared stats utilities

**SWIM API** - `/api/swim/v1/ws/`:
- `WebSocketServer.php` - WebSocket server for real-time events
- `ClientConnection.php` - WS client management
- `SubscriptionManager.php` - Topic subscription management

**Other**:
- `api/jatoc/` - JATOC auth, config, datetime, validators, vatusa_events, faa_ops_plan
- `api/event-aar/list.php` - Event AAR listing
- `api/nod/tracks.php` - NAT track data
- `api/simulator/navdata.php` - Simulator navdata
- `api/weather/refresh.php` - Weather data refresh
- `api/mgt/tmi/reroutes/bulk.php` - Bulk reroute management
- `api/cron.php` - Cron trigger endpoint

### Frontend Architecture

**Stack**: Vanilla JS + jQuery 2.2.4 + Bootstrap 4.5 + Chart.js + MapLibre GL

**CSS** (`assets/css/`):
- `theme.css` - Base theme
- `perti_theme.css` - PERTI-specific overrides
- `perti-colors.css` - Color variables
- `mobile.css` - Responsive styles
- `weather_radar.css`, `weather_impact.css`, `weather_hazards.css` - Weather displays
- `initiative_timeline.css` - Timeline component
- `tmi-publish.css` - TMI publisher styles
- `tmi-compliance.css` - Compliance report styles
- `info-bar.css` - Info bar component

**JavaScript** (`assets/js/`):

Core utilities:
- `lib/datetime.js` - Date/time utilities (Zulu time handling)
- `lib/logger.js` - Client-side logging
- `lib/colors.js` - Color utilities
- `lib/dialog.js` - Modal dialog utilities
- `lib/i18n.js` - Internationalization support
- `config/constants.js` - Shared constants
- `config/rate-colors.js` - Rate color scales
- `config/phase-colors.js` - Flight phase colors
- `config/filter-colors.js` - Filter color mapping

Feature modules:
- `adl-service.js` - ADL data fetching service
- `adl-refresh-utils.js` - ADL auto-refresh logic
- `demand.js` - Demand chart visualization
- `gdp.js` - GDP interface logic
- `tmi-gdp.js` - TMI GDP management
- `tmi-active-display.js` - Active TMI display
- `tmi-publish.js` - TMI Discord publishing
- `tmi_compliance.js` - TMI compliance analysis
- `advisory-builder.js` - Advisory message builder
- `advisory-config.js` - Advisory configuration
- `splits.js` - Sector split management
- `schedule.js` - Event schedule
- `review.js` - Post-event review
- `sheet.js` - Planning sheet
- `plan.js` - PERTI plan page logic
- `gdt.js` - Ground Delay Table
- `nod.js` - North Atlantic display
- `nod-demand-layer.js` - NOD demand overlay

Map/spatial:
- `route-maplibre.js` - MapLibre GL route visualization
- `route-symbology.js` - Route display symbols
- `fir-scope.js` - FIR boundary scope
- `fir-integration.js` - FIR data integration
- `airspace_display.js` - Airspace element display
- `sua.js` - SUA display

Data/reference:
- `facility-hierarchy.js` - ARTCC/TRACON/Tower hierarchy data
- `procs.js`, `procs_enhanced.js` - DP/STAR procedure display
- `cycle.js` - AIRAC cycle utilities
- `awys.js` - Airway data
- `reroute.js` - Reroute display
- `public-routes.js` - Public routes management
- `playbook-cdr-search.js` - Playbook/CDR search
- `statsim_rates.js` - Statistical simulation rates

Weather:
- `weather_radar.js` - Weather radar overlay
- `weather_radar_integration.js` - Radar data integration
- `weather_impact.js` - Weather impact visualization
- `weather_hazards.js` - Weather hazard display

Other:
- `jatoc.js`, `jatoc-facility-patch.js` - JATOC interface
- `initiative_timeline.js` - Initiative timeline display
- `theme.min.js` - Theme utilities
- `plugins/datetimepicker.js` - Date/time picker plugin
- `plugins/snow.js` - Seasonal snow effect

**Third-party libraries** (CDN):
- jQuery 2.2.4, jQuery UI 1.12.1
- Bootstrap 4.5.0
- SweetAlert2 (Swal) - Toast notifications
- Select2 - Enhanced dropdowns
- Summernote - Rich text editor
- FontAwesome 5.15.4 - Icons
- Chart.js (loaded per-page) - Charts
- MapLibre GL JS (loaded per-page) - Maps

### PHP Utility Classes

**`lib/`**:
- `Database.php` (`PERTI\Lib\Database`) - Parameterized query helpers for MySQLi and sqlsrv
- `Response.php` (`PERTI\Lib\Response`) - JSON API response helpers
- `Session.php` (`PERTI\Lib\Session`) - Session management
- `DateTime.php` (`PERTI\Lib\DateTime`) - Date/time utilities

**`load/`** (shared includes):
- `config.php` - Environment config (gitignored, see `config.example.php`)
- `connect.php` - Database connections (lazy-loaded getters)
- `input.php` - Safe input handling for PHP 8.2+
- `header.php` - HTML head (CSS/JS CDN includes)
- `nav.php` - Navigation bar
- `nav_public.php` - Public navigation (non-authenticated)
- `footer.php` - Page footer
- `breadcrumb.php` - Breadcrumb navigation
- `gdp_section.php` - GDP section partial
- `swim_config.php` - SWIM configuration
- `coordination_log.php` - Coordination logging
- `azure_config.json` - Azure resource configuration
- `azure_perti_config.json` - PERTI-specific Azure config

**`load/discord/`**:
- `DiscordAPI.php` - Single-server Discord API client
- `MultiDiscordAPI.php` - Multi-organization Discord posting
- `TMIDiscord.php` - TMI-specific Discord logic
- `DiscordMessageParser.php` - Discord message formatting
- `DiscordWebhookHandler.php` - Webhook processing

**`load/services/`**:
- `GISService.php` - PostGIS spatial query service

### Discord Bot (`discord-bot/`)

Node.js Gateway bot for real-time reaction processing on TMI coordination threads.

- `bot.js` - Main bot (Discord.js, listens for reactions in coordination channel threads)
- `cleanup-coordination.js` - Coordination thread cleanup utility
- Calls `api/mgt/tmi/coordinate.php` via REST when reactions are added
- Multi-org Discord support via `DISCORD_ORGANIZATIONS` config

### Integrations (`integrations/`)

**Flight Simulator Plugins** (`integrations/flight-sim/`):
- `msfs/` - Microsoft Flight Simulator plugin (C++, SimConnect)
- `xplane/` - X-Plane plugin (C, DataRefs)
- `p3d/` - Prepar3D plugin (C++, SimConnect)

**Virtual Airline Modules** (`integrations/virtual-airlines/`):
- `phpvms7/` - phpVMS 7 module (Laravel service provider)
- `smartcars/` - smartCARS webhook integration
- `vam/` - Virtual Airlines Manager integration

**Pilot Client Plugins** (`integrations/pilot-clients/`):
- `vpilot/` - vPilot plugin (C#, SimBrief import)
- `xpilot/` - xPilot plugin (Python)

**ATC Integrations**:
- `hoppie-cpdlc/` - Hoppie ACARS/CPDLC bridge
- `vatis/` - vATIS ATIS monitoring (runway correlation, weather extraction)
- `vfds/` - Virtual FDS integration (EDST, TDLS, departure sequencing)

## Background Jobs & Daemons

**IMPORTANT**: Use PHP daemons with `scripts/startup.sh`, NOT Azure Functions.

All daemons are started at App Service boot via `scripts/startup.sh` and run continuously:

| Daemon | Script | Interval | Purpose |
|--------|--------|----------|---------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s | Flight data ingestion + ATIS processing |
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Route parsing with PostGIS |
| Boundary Detection (GIS) | `adl/php/boundary_gis_daemon.php` | 15s | Spatial boundary detection |
| Crossing Calculation | `adl/php/crossing_gis_daemon.php` | Tiered | Boundary crossing ETA prediction |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered | Waypoint ETA calculation |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent | Real-time events on port 8090 |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min | Sync ADL to SWIM_API |
| SimTraffic Poll | `scripts/simtraffic_swim_poll.php` | 2min | SimTraffic time data polling |
| Reverse Sync | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic data back to ADL |
| Scheduler | `scripts/scheduler_daemon.php` | 60s | Splits/routes auto-activation |
| Archival | `scripts/archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge |
| Monitoring | `scripts/monitoring_daemon.php` | 60s | System metrics collection |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous | Async TMI Discord posting |
| Event Sync | `scripts/event_sync_daemon.php` | 6h | VATUSA/VATCAN/VATSIM event sync |
| ADL Archive | `scripts/adl_archive_daemon.php` | Daily 10:00Z | Trajectory archival to blob storage |

### Tiered Processing

Several daemons use tiered intervals based on flight priority:
- **Tier 0** (15s): Active flights within 60nm of destination
- **Tier 1** (30s): Active flights en route
- **Tier 2** (60s): Prefiled flights departing within 2h
- **Tier 3** (2min): Prefiled flights departing within 6h
- **Tier 4** (5min): All other flights

## Deployment & CI/CD

### GitHub Actions (`.github/workflows/`)

- `azure-webapp-vatcscc.yml` - Deploy to Azure App Service on push to `main`
  - PHP 8.2, Composer install, rsync deploy package
  - Deploys to Azure Web App `vatcscc` via publish profile
  - Excludes `sdk/`, `.git/`, `.github/`, most `docs/` (keeps `docs/swim/` and `docs/stats/`)
- `site-monitor.yml` - Site monitoring workflow
- `wind-fetch.yml` - Wind data fetch workflow

### Azure App Service

- **Linux container** with nginx + PHP-FPM
- Custom startup: `scripts/startup.sh` → configures nginx, starts all daemons, then PHP-FPM foreground
- PHP-FPM tuned to 40 workers (P1v2 tier, 3.5GB RAM)
- ODBC Driver 18 installed via `startup.sh` (root level) for Python pyodbc
- Logs: `/home/LogFiles/<daemon>.log`

### Authentication

- **VATSIM Connect OAuth** for user login (OAuth 2.0)
- Session-based auth via `sessions/handler.php`
- Users table in `perti_site` MySQL
- Discord bot uses API key auth (`X-API-Key` header)
- SWIM API uses API keys from `swim_api_keys` table

## Code Conventions

- Database connections use PDO with prepared statements (MySQL) or sqlsrv with parameterized queries (Azure SQL)
- Use `PERTI\Lib\Database` class for new queries when possible
- API endpoints follow REST conventions in `/api/{resource}/{action}.php`
- Config values defined as PHP constants in `load/config.php`
- Environment-specific values use `env()` helper (supports Azure App Settings)
- Frontend uses jQuery AJAX for API calls, SweetAlert2 for notifications
- All times are UTC/Zulu
- Feature flags defined in `config.php` (e.g., `DISCORD_MULTI_ORG_ENABLED`, `TMI_STAGING_REQUIRED`)

## Git Worktrees

**IMPORTANT**: Always use `C:/Temp/perti-worktrees/` for git worktrees.

The main repository path (OneDrive) is too long for Windows' 260-character limit, causing worktree creation to fail when using project-local directories like `.worktrees/`.

```bash
# Correct - use short temp path
git worktree add C:/Temp/perti-worktrees/<branch-name> -b feature/<branch-name>

# Incorrect - will fail due to path length
git worktree add .worktrees/<branch-name> -b feature/<branch-name>
```

Current worktrees can be listed with `git worktree list`.

## Python Scripts

Several utilities use Python 3.x:
- `nasr_navdata_updater.py` - FAA NASR navdata import
- `airac_full_update.py` - Full AIRAC cycle data update
- `scripts/build_sector_boundaries.py` - Build sector boundary polygons
- `scripts/playbook/` - CDR/playbook route parser
- `scripts/statsim/` - Statistical simulation and VATUSA event import
- `scripts/vatsim_atis/` - VATSIM ATIS fetcher daemon
- `scripts/bada/` - BADA aircraft performance data parsers
- `scripts/openap/` - OpenAP performance data import
