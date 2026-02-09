# FMDS vs PERTI: Functional Comparison & Adaptation Analysis

## 1. Executive Summary

### What is FMDS?

The **Flow Management Data and Services (FMDS)** program is the FAA's effort to replace the legacy Traffic Flow Management System (TFMS), a suite of automation tools developed since the late 1980s that supports traffic flow management across the National Airspace System (NAS). TFMS manages over 45,000 flights daily and is used at 114+ facilities (21 ARTCCs, 35 TRACONs, 17 ATCTs, the ATCSCC, and others).

FMDS was announced as a Challenge-Based Acquisition (ChBA) in September 2025, with Initial Operating Capability (IOC) targeted for Q1 FY2029 (December 2028). The program defines six core functional areas: Manage Data (F.1), Assess NAS State (F.2), Conduct Traffic Flow Management (F.3), Conduct Post-Event Analysis (F.4), Manage Display (F.5), and Maintain Operations (F.6). Key documents include the final Program Requirements Document (fPRD, 160 pages), Concept of Operations (ConOps, 91 pages), Functional Analysis Document (FAD, 186 pages), and Human Interface Guidelines (HIG, 49 pages).

### What is PERTI?

**PERTI** (Plan, Execute, Review, Train, Improve) is the VATSIM virtual ATC network's flow management platform, operated by vATCSCC (Virtual Air Traffic Control System Command Center). Built on PHP 8.2, Azure SQL, PostgreSQL/PostGIS, and MySQL, PERTI provides real-time flight data management, TMI modeling and implementation, demand/capacity analysis, spatial boundary processing, compliance monitoring, and multi-facility coordination -- all adapted for the VATSIM virtual ATC environment.

### Why This Comparison Matters

PERTI was designed independently to solve many of the same problems the FAA faces: balancing demand with capacity, coordinating TMIs across facilities, and providing integrated situational awareness. This document systematically maps FMDS's functional requirements against PERTI's existing capabilities to:

1. Identify where PERTI already provides equivalent functionality
2. Highlight gaps where FMDS capabilities could be adapted
3. Document areas where PERTI exceeds the FMDS baseline
4. Establish a roadmap for bringing FMDS concepts into PERTI

---

## 2. Methodology

This comparison was conducted through:

1. **Document-by-document review** of all 14 FMDS acquisition documents (fPRD, ConOps, FAD, HIG, SOO, Master Site List, Method of Evaluation, and supporting attachments)
2. **Codebase audit** of the PERTI repository, examining PHP API endpoints, Python analysis scripts, JavaScript frontend modules, SQL schemas, and daemon processes
3. **Functional mapping** against the FMDS hierarchy (F.0 through F.6 and all sub-functions) using FMDS requirement IDs (FMDS_fPRD_XXXX) as reference points
4. **Coverage classification** using the following scale:

| Rating | Definition |
|--------|-----------|
| **Full** | PERTI provides equivalent or better functionality |
| **Partial** | PERTI provides some aspects but lacks others |
| **None** | PERTI does not address this requirement |
| **N/A** | Not applicable to the VATSIM environment |

---

## 3. Functional Area Comparison

### F.1 -- Manage Data

*Ingests and processes external data sources including flight, weather, aeronautical, CDM, and space data to create a common model of NAS operations.*

#### F.1.1 -- Ingest Data

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.1.1.1 Ingest Flight Data** (fPRD_0100) -- Acquire NAS and international flight data (plans, surveillance, surface events) | PERTI ingests from VATSIM data feeds every 15 seconds via `scripts/vatsim_adl_daemon.php`. Processes pilot positions, flight plans, and surface events. Data staged in `adl_staging_pilots` / `adl_staging_prefiles`. | **Full** | VATSIM datafeed replaces ERAM/STARS/CSS-FD. SimBrief integration provides enhanced flight plan data. |
| **F.1.1.2 Ingest Schedule Data** (fPRD_0104) -- Acquire OAG schedule data | No direct OAG equivalent. PERTI uses VATUSA/VATCAN/VATSIM event data via `scripts/event_sync_daemon.php` and SimBrief-filed plans. Statistical simulation uses `sim_ref_*` tables for pattern modeling. | **Partial** | Virtual airlines don't have OAG equivalents; event-based scheduling partially substitutes. |
| **F.1.1.3 Ingest Weather Data** (fPRD_0102) -- METARs, TAFs, radar, satellite, lightning, TCFs, wind, echo tops, WAF grid | PERTI ingests real-world METARs/TAFs from aviationweather.gov. Radar data displayed via `assets/js/weather_radar.js`. Weather impact analysis via `assets/js/weather_impact.js` and `assets/js/weather_hazards.js`. No TCF/WAF grid processing. | **Partial** | Real-world weather is used as a proxy for VATSIM conditions. No convective forecast or WAF grid equivalent. |
| **F.1.1.4 Ingest Adaptation Data** (fPRD_0103) -- NASR/NFDC, ERAM boundaries, international ANSPs | PERTI ingests NASR data via `nasr_navdata_updater.py` and AIRAC updates via `airac_full_update.py`. Boundary data in PostGIS (`artcc_boundaries`, `tracon_boundaries`, `sector_boundaries`). International FIR data in `fir_boundaries`. | **Full** | Uses same FAA NASR/CIFP source data. PostGIS provides equivalent spatial capability. |
| **F.1.1.5 Ingest CDM Data** (fPRD_0105) -- Flight data from CDM members (OOOI, TOS, substitutions) | PERTI receives OOOI-equivalent data from pilot clients (vPilot, xPilot plugins in `integrations/pilot-clients/`). SimBrief provides pre-departure plan data. vATIS provides ATIS data. No TOS/substitution protocol. | **Partial** | No formal CDM member framework. Pilot client integrations provide a subset of CDM functionality. |
| **F.1.1.6 Ingest Space Data** (fPRD_0662) -- Space launch/reentry information | N/A | **N/A** | No space operations in VATSIM airspace. |
| **F.1.1.7 Ingest Sector Configuration Data** (fPRD_0663) -- From SFDPS-ERADP | Sector configurations managed via `splits_configs` / `splits_positions` tables and `assets/js/splits.js`. Manual configuration rather than automated ingest. | **Partial** | Manual rather than automated, but functional equivalent exists. |
| **F.1.1.8 Ingest Departure Sequencing Data** (fPRD_0664) -- From TFDM | No TFDM equivalent. Departure sequencing data derived from ADL position tracking and OOOI events. | **None** | Future opportunity: `integrations/vfds/` (Virtual FDS) could provide this. |

#### F.1.2 -- Process Data

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.1.2.1 Process Flight Data** -- Validate, parse, match, save | Full processing pipeline: validation in daemon, route parsing via `adl/php/parse_queue_gis_daemon.php` with PostGIS, flight matching by callsign+CID, normalized storage in 8-table architecture (`adl_flight_core`, `adl_flight_plan`, `adl_flight_position`, `adl_flight_times`, `adl_flight_tmi`, `adl_flight_aircraft`, `adl_flight_trajectory`, `adl_flight_waypoints`). | **Full** | 8-table normalized architecture is more modern than TFMS's monolithic design. |
| **F.1.2.2 Process Schedule Data** -- Validate and parse | Event schedule data processed via `scripts/event_sync_daemon.php`. Flight plan schedule data parsed during ingestion. | **Partial** | No OAG-equivalent schedule processing. |
| **F.1.2.3 Process Weather Data** -- Validate | Weather data validated and processed for display. `api/data/weather.php` and `api/data/weather_impacts.php` handle weather data processing. | **Partial** | Simpler validation than FMDS's CSS-Wx processing. |
| **F.1.2.4 Process Adaptation Data** -- Validate, filter, merge, alias, prioritize | NASR updater handles validation and merging. `nav_fixes`, `nav_procedures`, `airways`, `airway_segments` tables in both VATSIM_REF and VATSIM_GIS. Airway deduplication handled in updater. | **Full** | Covers validation, filtering, merging, and aliasing. |
| **F.1.2.5 Process CDM Data** -- Validate, parse, generate responses | Limited CDM processing. SimBrief data parsed; pilot client data processed. No formal substitution/TOS response generation. | **Partial** | Could be expanded for virtual airline CDM-like participation. |

#### F.1.3 -- Derive Data

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.1.3.1 Derive Modeled Trajectories** -- Lateral/vertical/speed profiles, wind-adjusted speed, restriction effects, trajectory times, event list, conformance evaluation, ATC maneuver identification | PERTI generates trajectory predictions via `adl/php/waypoint_eta_daemon.php` using great-circle calculations, wind adjustment, and BADA/OpenAP performance data (`aircraft_performance_profiles`). Boundary crossings predicted via `adl/php/crossing_gis_daemon.php`. Route geometry stored as `route_geometry (geography)` in `adl_flight_plan`. No formal ATC maneuver identification (8 maneuver types). | **Partial** | PERTI has trajectory prediction but lacks FMDS's full 4D conformance evaluation and ATC maneuver classification (Hold, Coast, Vector, Ascend, Descend, Fly-At-New-Cruise, Direct-To-Fix, Wander). |
| **F.1.3.2 Derive Weather Data** -- Gridded and textual weather products | Weather data derived for display but not for trajectory modeling (no WAF grid processing). | **Partial** | Weather display works; weather-trajectory integration is limited. |

#### F.1.4 -- Provide Data

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.1.4.1-F.1.4.15 Provide Data** -- Flight, demand, capacity, adaptation, weather, notifications, route availability, TMI, CDM, NAS event log, NAS status, system status, space, analytics, FxA data | PERTI provides data via comprehensive REST APIs (`api/adl/`, `api/tmi/`, `api/data/`, `api/stats/`), SWIM API (`api/swim/v1/`), WebSocket (`api/swim/v1/ws/WebSocketServer.php`), and Discord integration. SWIM API keys managed via `swim_api_keys`. | **Full** | PERTI's SWIM API and WebSocket provide equivalent data provision. Route availability (RAPT) is a gap. |

---

### F.2 -- Assess NAS State

*Evaluates the current and projected state of the NAS and enables Traffic Managers to identify near- and long-term problems.*

#### F.2.1 -- Specify Resource Capacity

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.2.1.1 Specify Default Capacities** (fPRD_0673-0674, 1066) -- Airport, sector, fix default capacities from adaptation | Airport capacities: `config_data` table (VMS/LVMC/IMC AAR/ADR), `airport_config` / `airport_config_rate`. Sector capacity: `sector_boundaries` with altitude floors/ceilings. Fix capacity: `demand_monitors` with threshold definitions. | **Full** | Comprehensive default capacity system with weather-conditional rates. |
| **F.2.1.2 Specify User-Defined Capacities** (fPRD_0174-0175, 1067) -- Airport, sector, fix capacities from Traffic Manager input | `manual_rate_override` table for AAR/ADR overrides. `p_configs` for plan-specific rates. `api/demand/override.php` endpoint. Event-specific rates via `event-aar.php`. | **Full** | Supports per-event and ad-hoc rate overrides. |
| **F.2.1.3 Manage Departure Fix Usage** (fPRD_2005-2007) -- Fix rate, combining, availability | No departure fix usage management. PERTI monitors fix demand but doesn't manage fix rates or availability. | **None** | FMDS IDRP capability; future adaptation opportunity. |

#### F.2.2 -- Predict Resource Demand

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.2.2.1 Predict Deterministic Demand** (fPRD_0675-0676, 1068-1069, 2009) -- Airport, sector, fix, FxA, departure route demand | Airport demand: `api/demand/airport.php`. Fix demand: `api/adl/demand/fix.php` with time-window filtering. Segment demand: `api/adl/demand/segment.php`. Airway demand: `api/adl/demand/airway.php`. Batch demand: `api/adl/demand/batch.php`. Monitors: `api/adl/demand/monitors.php`. Visualization: `assets/js/demand.js` with Chart.js. | **Full** | Comprehensive demand prediction across all NAS resource types. |
| **F.2.2.2 Compute Smoothing Coefficients** (fPRD_1082) -- Historical error distributions for airports, sectors, fixes | Not implemented. PERTI uses deterministic demand counts without probabilistic smoothing. | **None** | IDP capability; requires historical error analysis infrastructure. |
| **F.2.2.3 Predict Probabilistic Demand** (fPRD_1070-1072) -- Apply smoothing coefficients to deterministic demand | Not implemented. | **None** | Depends on F.2.2.2. Key FMDS enhancement over TFMS. |

#### F.2.3 -- Compare Demand and Capacity

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **Compare Demand and Capacity** (fPRD_0161) -- Compare predicted demand to capacity thresholds | Demand charts (`assets/js/demand.js`) display demand vs. AAR/ADR capacity lines. DCI-equivalent alerts when demand exceeds capacity. Monitor alerts via `demand_monitors`. | **Full** | Visual comparison with threshold alerting. |

#### F.2.4 -- Predict Route Availability (RAPT)

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.2.4.1-F.2.4.3 Route Blockage/Timeline** (fPRD_0166-0167, 0879, 0877, 0876) -- WAF-grid-based route blockage scores, route timelines, PIG timers, trend indicators | Not implemented. No RAPT equivalent. PERTI displays weather radar overlaid on routes (`assets/js/route-maplibre.js` + `assets/js/weather_radar.js`) but does not compute route blockage scores. | **None** | Significant FMDS capability gap. Would require weather data integration with route geometry. |

#### F.2.5 -- Conduct NAS State Analysis

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.2.5.1 Calculate Real-Time Metrics** (fPRD_0171) | `api/stats/realtime.php`, `flight_phase_snapshot` periodic captures, `flight_stats_hourly`. | **Full** | |
| **F.2.5.2 Generate Real-Time Reports** (fPRD_0899, 0893, 0888, 0173) | Traffic management reports, flight list reports, monitor alert reports available via various API endpoints. Custom reports not as flexible as FMDS envisions. | **Partial** | Lacks FMDS's COTS-based custom report builder. |
| **F.2.5.3 Enable Real-Time Analysis** (fPRD_0140, 0129) -- TMI and flight history compilation | TMI event log: `tmi_events` table. Flight changelog: `adl_flight_changelog`. Full attribute history tracked. | **Full** | |
| **F.2.5.4 Query Adaptation Data** (fPRD_2008) -- Playbook, CDR, NFDC queries | CDR search: `route_cdr` table, `assets/js/playbook-cdr-search.js`. Playbook routes: `route_playbook` table. Fix lookup: `api/data/fixes.php`. Airspace elements: `api/data/airspace_elements/`. | **Full** | |

#### F.2.6 -- Manage Notifications

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.2.6.1 Create Notifications** (fPRD_0933, 0931, 0932, 0935-0937) -- Advisories, DCI, NAS events, general messages, slot modification, TMI parameter notifications | Advisories: `tmi_advisories` table, `api/tmi/advisories.php`. TMI publishing: `assets/js/tmi-publish.js`, Discord integration via `load/discord/MultiDiscordAPI.php`. DCI-equivalent demand alerts. NAS event notifications via Discord channels. | **Full** | Discord-based notification exceeds FMDS's internal-only notification system. |
| **F.2.6.2-F.2.6.3 Issue and Acknowledge** (fPRD_0195, 0164, 1073, 1074) | Multi-org Discord posting with reaction-based acknowledgment (`discord-bot/bot.js`). Coordination threads with facility approval tracking (`tmi_proposals`, `tmi_proposal_facilities`). | **Full** | Discord reactions provide acknowledgment equivalent. |

---

### F.3 -- Conduct Traffic Flow Management

*Provides tools to maintain the flow of air traffic by defining FxAs, conducting TMIs, and logging NAS events.*

#### F.3.1 -- Define FxAs (Flow Evaluation/Constraint Areas)

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **Define FxA** (fPRD_1039) -- Crossing lines, circles, polygons with altitude/aircraft type attributes | No formal FxA definition tool. PERTI uses fix-based and segment-based demand monitoring (`demand_monitors`) and TMI compliance flow cones (Python `analyzer.py` + JS `tmi_compliance.js`). Spatial polygons exist for boundaries but not user-defined FxAs. | **Partial** | Monitor-based approach covers core use case. User-defined polygon FxAs would be an enhancement. |

#### F.3.2 -- Conduct TMIs

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.3.2.1.1 Define Strategic Reroute** (fPRD_0917) | Full reroute management: `tmi_reroutes`, `tmi_reroute_routes`, `api/mgt/tmi/reroutes/`. ICAO equipment eligibility not implemented. | **Partial** | Reroute definition is full; equipment-based eligibility filtering is missing. |
| **F.3.2.1.2 Define Tactical Reroute** (fPRD_1038, 1075, 1076) | Route amendments possible via manual entry. No automated route option generation or constraint-avoidance routing. | **Partial** | Manual rerouting works; automated alternative generation (IDRP) is absent. |
| **F.3.2.1.3 Define GS** (fPRD_0918) | Full GS lifecycle: `api/tmi/gs/create.php`, `model.php`, `activate.php`, `extend.php`, `purge.php`. Parameters stored in `tmi_programs`. | **Full** | Complete GS implementation including modeling and flight impact analysis. |
| **F.3.2.1.4 Define GDP** (fPRD_0919) | Full GDP definition: `api/tmi/gdp_preview.php`, `gdp_apply.php`, `gdp_simulate.php`. Parameters in `tmi_programs`, slots in `tmi_slots`. | **Full** | |
| **F.3.2.1.5 Define AFP** (fPRD_0920) | Not implemented as a distinct program type. Could be modeled as a GDP with FxA scope. | **None** | AFP requires FxA-scoped demand, which PERTI lacks. |
| **F.3.2.1.6 Define CTOP** (fPRD_0922) | Not implemented. CTOP requires Trajectory Options Set (TOS) processing. | **None** | Complex TMI type requiring CDM/TOS infrastructure. |
| **F.3.2.1.7 Define COMP** (fPRD_0921) -- Compression | GDP compression flag exists: `compression_enabled` in `tmi_programs`. Adaptive compression logic not fully implemented. | **Partial** | Schema supports it; algorithm needs implementation. |
| **F.3.2.1.8 Define BLKT** (fPRD_0722) -- Blanket | Not implemented. | **None** | Lower-priority TMI type. |
| **F.3.2.1.9 Define TMI Interactions** (fPRD_0721) | Multi-TMI interaction modeling not implemented. Programs operate independently. | **None** | Key FMDS ITM capability. |
| **F.3.2.1.10 Define MIT** (fPRD_1057) | MIT compliance analysis: `scripts/tmi_compliance/core/analyzer.py` performs haversine distance calculations, traffic sector computation, flow cone analysis. TMI entries: `tmi_entries` with `restriction_value` and `restriction_unit`. | **Full** | MIT monitoring and compliance are well-implemented. |
| **F.3.2.1.11 Define MINIT** (fPRD_1059) | MINIT compliance in `analyzer.py` with time-based spacing analysis. | **Full** | |
| **F.3.2.1.12 Define STOP** (fPRD_1058) | STOP restrictions via `tmi_entries` with `protocol_type` = 'STOP'. | **Full** | |
| **F.3.2.1.13 Define APREQ** (fPRD_1060) | APREQ entries can be created in `tmi_entries`. No automated approval workflow beyond Discord coordination. | **Partial** | Definition exists; automated ERAM-like approval flow is absent. |
| **F.3.2.1.14 Define Time-Based Management** (fPRD_1065) | No TBFM equivalent. | **None** | TBFM is a separate system; PERTI doesn't replicate metering. |
| **F.3.2.1.15 Define Departure Sequencing** (fPRD_2170) | Not implemented. `integrations/vfds/` provides stub for future development. | **None** | TFDM-dependent capability. |
| **F.3.2.1.16 Define TXT** (fPRD_0725) | Text restrictions via `tmi_entries` with `entry_type` = 'TXT'. | **Full** | |
| **F.3.2.1.17 Define ALT** (fPRD_0724) | ALT restrictions via `tmi_entries`. | **Full** | |
| **F.3.2.1.18 Define SPD** (fPRD_0723) | SPD restrictions via `tmi_entries`. | **Full** | |

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.3.2.2 Validate TMI Parameters** (fPRD_0187) | Validation in API endpoints (`common.php` helpers, parameter checks in each TMI API). | **Full** | |
| **F.3.2.3 Model TMIs** (fPRD_0727-0750) -- Compute assignments, impact metrics | GDP modeling: `gdp_preview.php` (RBS slot assignment preview), `gdp_simulate.php`. GS modeling: `gs/model.php` (affected flights, controlled/exempt/airborne counts). Reroute impact: compliance rate tracking in `tmi_reroutes`. No AFP/CTOP/multi-TMI modeling. | **Partial** | GDP and GS modeling are strong. AFP, CTOP, and multi-TMI interaction modeling are gaps. |
| **F.3.2.4 Coordinate Restrictions** (fPRD_0906-0914) -- Automated inter-facility coordination for MIT, MINIT, STOP, APREQ, TBM, DSP, TXT, ALT, SPD | Discord-based coordination: `tmi_proposals` with per-facility approval tracking, `tmi_proposal_facilities`, `tmi_proposal_reactions`. `api/mgt/tmi/coordinate.php` handles coordination workflow. Discord bot (`discord-bot/bot.js`) processes reaction-based approvals. | **Full** | Discord-based coordination is arguably more accessible than FMDS's internal automation. |
| **F.3.2.5 Implement TMIs** (fPRD_0752, 0185, 0939) -- Assign EDCTs, route assignments, departure windows | GDP EDCT assignment: `gdp_apply.php` applies sandbox to live. GS hold: `gs/activate.php`. Reroute assignment: `api/mgt/tmi/reroutes/activate.php`. Updates `adl_flight_tmi` with control records. | **Full** | |
| **F.3.2.6 Monitor TMIs** (fPRD_0764-0771) -- Route conformance, EDCT compliance | Reroute compliance: `tmi_reroute_compliance_log`, compliance rate in `tmi_reroutes`. EDCT compliance: `tmi_flight_control.compliance_status`, `compliance_delta_min`. TMI compliance analysis: `scripts/tmi_compliance/core/analyzer.py` + `assets/js/tmi_compliance.js`. | **Full** | Comprehensive compliance monitoring with visual analysis. |
| **F.3.2.7 Cancel TMIs** (fPRD_0740) | GDP purge: `api/tmi/gdp_purge.php`. GS purge: `api/tmi/gs/purge.php`. TMI cancellation: `api/mgt/tmi/cancel.php`. | **Full** | |
| **F.3.2.8 Maintain TMIs** -- Pop-ups, compression, slot modifications, EDCT updates | Pop-up processing: `tmi_popup_queue`, automatic detection of new flights entering program scope. Compression: schema support (`compression_enabled`), partial algorithm. Slot modifications: limited (no CDM substitution protocol). EDCT updates: `adl_edct_overrides` for manual changes. | **Partial** | Pop-up detection works. CDM-style substitutions and adaptive compression algorithms need work. |

#### F.3.3 -- Manage NAS Event Log

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **Log NAS Events** (fPRD_0774-0786, 1025) -- Deicing, outages, delays, SWAP, PIREPs, shift summaries, DCI resolutions, SAA, runways, TMI implementations, critiques, misc events | TMI event logging: `tmi_events` table with comprehensive event types. NTML-equivalent: `api/tmi/advisories.php` for advisory publishing. DCC advisory system: `dcc_advisories`. Runway config logging: `airport_config_history`. SUA tracking: `sua_activations`. No formal shift summary log. | **Partial** | TMI and advisory logging are strong. Formal NTML-style shift logging and some event types (deicing, PIREPs, SWAP) are gaps. |

---

### F.4 -- Conduct Post-Event Analysis

*Employs historical data to improve algorithms, enable replay, and generate reports of past data.*

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.4.1 Analyze Historical Data** (fPRD_1078-1080) -- Average ground times, CDM quality assessment, error distributions | Airport taxi reference: `airport_taxi_reference` table with 3,628 airports, using 90-day rolling P5-P15 averages. `sp_RefreshAirportTaxiReference` stored procedure. GS delay analysis using taxi reference. No CDM quality assessment or error distribution computation. | **Partial** | Taxi time analysis is well-developed. Error distributions (for IDP) are not computed. |
| **F.4.2 Replay TFM Data** (fPRD_0200-0202) -- Request, retrieve, control replay of historical data | `review.php` provides post-event review interface. `api/data/review/` endpoints. Flight trajectory archive: `adl_trajectory_archive` + `adl_trajectory_compressed`. No interactive replay with TMI trial modeling. | **Partial** | Data archival supports review but lacks FMDS's full replay capability with TMI re-modeling. |
| **F.4.3 Retrieve Archived TFM Data** (fPRD_0203) | `adl_flight_archive` for completed flights. Trajectory data in tiered storage (`adl_flight_trajectory` → `adl_trajectory_archive` → `adl_trajectory_compressed`). Blob storage archival via `scripts/adl_archive_daemon.php`. | **Full** | Tiered archival with blob storage is well-implemented. |
| **F.4.4 Generate Archived Data Reports** (fPRD_0797) | Statistics tables: `flight_stats_daily`, `flight_stats_hourly`, `flight_stats_airport`, `flight_stats_artcc`, `flight_stats_carrier`, `flight_stats_citypair`, `flight_stats_tmi`. API: `api/stats/`. | **Full** | Comprehensive statistical reporting. |

---

### F.5 -- Manage Display

*Manages the user interface display and configuration settings.*

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.5.1 Display Data** (fPRD_0206-0218, 0221, 0812, 0815, 0915, 0941-0944, 1077) -- Flight, demand, capacity, adaptation, weather, notifications, route availability, reports, FxAs, TMIs, NAS event log, NAS status, space, replay, archived reports | **Flight data**: `api/adl/current.php`, `api/adl/flight.php`, MapLibre visualization (`assets/js/route-maplibre.js`). **Demand**: `assets/js/demand.js` with Chart.js bar charts. **Capacity**: AAR/ADR in demand charts. **Adaptation**: airspace elements viewer (`airspace-elements.php`). **Weather**: radar + impact + hazards overlays. **Notifications**: advisory display, TMI publish. **TMI**: `assets/js/tmi-active-display.js`, GDT (`gdt.php`). **NAS status**: `status.php`. No route availability display, space data, or interactive replay. | **Partial** | Most display requirements met. Gaps: route availability (RAPT), space data, integrated replay, time-slider for past/present/predicted views. |
| **F.5.2 Capture UI Activity** (fPRD_0223) -- Screen recording for post-event analysis | Not implemented. | **None** | Low priority for VATSIM. |
| **F.5.3 Maintain User Display Settings** (fPRD_0222, 0259, 0261, 0916) -- Save/recall/delete config | Per-page settings stored in browser localStorage. No server-side preference persistence across workstations. | **Partial** | Browser-local only; FMDS requires server-side Pref Sets portable across devices. |

---

### F.6 -- Maintain Operations

*Records and archives operational data, maintains adaptation, monitors the system, manages access, and supports testing/training.*

| FMDS Requirement | PERTI Capability | Coverage | Notes |
|---|---|---|---|
| **F.6.1 Record Data** (fPRD_0224-0232, 0823-0831, 0924-0948) -- Record all data types | Comprehensive recording: flight data in 8-table schema, TMI data in `tmi_events`, demand in stats tables, weather data cached, adaptation versioned, UI activity not recorded. | **Full** | All operationally relevant data types are recorded. |
| **F.6.2 Archive Data** (fPRD_0233-0241, 0838-0846, 0927, 0950-0952, 2160) -- Long-term archival | `scripts/archival_daemon.php` handles trajectory tiering. `scripts/adl_archive_daemon.php` daily blob archival. `flight_stats_retention_tiers` manages retention policies. | **Full** | Tiered archival with configurable retention. |
| **F.6.3 Maintain Adaptation** (fPRD_0242-0247, 2061-2064, 0852) -- Access, diff, conflict detection, modify, test, deploy | `nasr_navdata_updater.py` for NASR updates. `airac_full_update.py` for AIRAC cycles. `ref_sync_log` tracks sync status. No formal diff/conflict detection or staged deployment workflow. | **Partial** | Automated updates work; lacks FMDS's formal dev-vs-ops dataset management. |
| **F.6.4 Monitor & Control** (fPRD_0248-0254, 2163) -- System monitoring, diagnostics, recovery | `scripts/monitoring_daemon.php` collects system metrics. `status.php` system status page. `healthcheck.php` for Azure. Daemon health monitoring in startup scripts. | **Partial** | Basic monitoring exists; lacks FMDS's comprehensive M&C with component-level control. |
| **F.6.5 Analyze System Performance** (fPRD_0255-0258) -- Diagnostics, utilization, response time reports | `adl_refresh_perf` tracks ingestion performance. `api/adl/diagnose.php` diagnostic endpoints. No formal response time or utilization reporting. | **Partial** | |
| **F.6.6 Identify Access Management** (fPRD_0625, 0940-0949, 1018-1031, 2068) -- RBAC, authentication | VATSIM OAuth (`login/`), session-based auth (`sessions/handler.php`). Admin users in `admin_users`. SWIM API key management (`swim_api_keys`). Discord bot uses API key auth. No formal RBAC system with role creation/assignment. | **Partial** | Authentication works; formal RBAC (create/assign/delete roles) is limited. |
| **F.6.7 Simulate Scenarios** (fPRD_2019-2025, 0885-0892) -- Define, maintain, process, control scenarios with closed-loop simulation | `simulator.php` and `api/simulator/` provide basic ATC simulation. Statistical simulation reference tables (`sim_ref_*`). No full closed-loop TFM scenario simulation with replay. | **Partial** | Basic simulation exists; FMDS's full scenario simulation with scripted training is significantly more comprehensive. |
| **F.6.8 Support Testing and Training** (fPRD_0894-0896, 2026-2029) -- Message injection, open/closed loop testing, scripted training, store/review scenarios | Limited testing support. No message injection, scripted training scenarios, or student run-through recording. | **None** | FMDS's training system is far more comprehensive than anything PERTI provides. |

---

## 4. Coverage Summary

| Functional Area | Full | Partial | None | N/A | Total | Coverage % |
|---|---|---|---|---|---|---|
| **F.1 Manage Data** | 7 | 8 | 1 | 1 | 17 | 65% |
| **F.2 Assess NAS State** | 8 | 2 | 3 | 0 | 13 | 69% |
| **F.3 Conduct TFM** | 14 | 7 | 6 | 0 | 27 | 65% |
| **F.4 Post-Event Analysis** | 2 | 2 | 0 | 0 | 4 | 75% |
| **F.5 Manage Display** | 0 | 2 | 1 | 0 | 3 | 33% |
| **F.6 Maintain Operations** | 2 | 5 | 1 | 0 | 8 | 56% |
| **TOTAL** | **33** | **26** | **12** | **1** | **72** | **64%** |

**Overall functional coverage: ~64%** (Full + 50% of Partial = ~82% weighted)

The strongest areas are TMI implementation (GDP, GS, restrictions), data management, demand prediction, and notification/coordination. The weakest areas are advanced display features, route availability prediction, probabilistic demand, and training/testing infrastructure.

---

## 5. Where PERTI Exceeds FMDS

PERTI provides several capabilities that FMDS does not describe or that go beyond FMDS's scope:

### 5.1 Discord-Based Multi-Facility Coordination

FMDS describes automated coordination methods (fPRD_0906-0914) but within its internal automation system. PERTI's Discord-based coordination system (`load/discord/MultiDiscordAPI.php`, `discord-bot/bot.js`) provides:
- **Multi-organization support** -- Coordination across independent virtual ATC organizations (VATUSA, VATCAN, etc.)
- **Reaction-based approval** -- Facility representatives approve/deny TMI proposals via Discord reactions, tracked in `tmi_proposal_reactions`
- **Public transparency** -- TMI advisories and coordination visible to the entire virtual ATC community
- **Rich media** -- Advisory messages with formatted embeds, compliance charts, and visual aids

### 5.2 TMI Compliance Analysis Engine

While FMDS requires TMI monitoring (F.3.2.6), PERTI's compliance analysis goes deeper:
- **Python backend** (`scripts/tmi_compliance/core/analyzer.py`): Haversine-based spacing calculations, traffic sector computation, flow cone analysis with bearing clustering
- **JavaScript frontend** (`assets/js/tmi_compliance.js`, ~280KB): Approach bearing → 45-degree clustering → per-stream distance-bin centerline → Gaussian smoothing → monotonic convergence → buffer polygon visualization
- **Multi-type analysis**: MIT, MINIT, GS compliance with real-time and historical data

### 5.3 PostGIS Spatial Processing

FMDS references geographic boundaries and airspace, but PERTI implements sophisticated spatial processing:
- **Real-time boundary detection** (`adl/php/boundary_gis_daemon.php`): Every 15 seconds, determines which ARTCC/TRACON/sector each flight occupies using PostGIS `ST_Contains`
- **Crossing prediction** (`adl/php/crossing_gis_daemon.php`): Predicts when flights will enter/exit boundaries with time estimates
- **Route parsing with geometry** (`adl/php/parse_queue_gis_daemon.php`): Converts route strings to PostGIS geography with waypoint resolution
- **Pre-computed grid lookup** (`adl_boundary_grid`): Fast lat/lon → boundary mapping without full polygon intersection

### 5.4 Airport Taxi Reference System

PERTI's airport taxi reference (`airport_taxi_reference`, 3,628 airports) uses a methodology inspired by the FAA ASPM program:
- 90-day rolling window with P5-P15 average (excluding outliers)
- Minimum 50 samples before trusting observed data
- Blended default-to-observed transition as sample size grows
- Per-airport unimpeded taxi time for GS delay analysis

### 5.5 SWIM API with WebSocket

PERTI's SWIM API (`api/swim/v1/`) provides FIXM-aligned data access that goes beyond FMDS's data provision requirements:
- **REST API** with API key authentication and tiered access
- **WebSocket server** (`api/swim/v1/ws/WebSocketServer.php`) for real-time event streaming
- **Topic-based subscriptions** via `SubscriptionManager.php`
- **Flight data in FIXM-compatible format** via `swim_flights` denormalized view

### 5.6 Virtual Airline and Pilot Client Integration

FMDS serves institutional users (FAA facilities, CDM airlines). PERTI extends to individual participants:
- **Flight simulator plugins** (MSFS, X-Plane, P3D) for direct data exchange
- **Pilot client plugins** (vPilot, xPilot) for SimBrief import and position reporting
- **Virtual airline modules** (phpVMS, smartCARS, VAM) for organizational participation
- **vATIS integration** for automated runway and weather correlation

### 5.7 Normalized Flight Data Architecture

PERTI's 8-table normalized flight architecture (`adl_flight_core`, `adl_flight_plan`, `adl_flight_position`, `adl_flight_times`, `adl_flight_tmi`, `adl_flight_aircraft`, `adl_flight_trajectory`, `adl_flight_waypoints`) is more modern than TFMS's legacy monolithic design. This architecture enables:
- Independent update cycles for position, timing, and TMI data
- Efficient querying of specific data domains
- Change tracking via `adl_flight_changelog` with field-level granularity

---

## 6. VATSIM-Specific Considerations

Several factors make the VATSIM environment materially different from real-world NAS operations, affecting how FMDS concepts can be adapted:

### 6.1 Traffic Volume and Predictability

| Factor | Real-World NAS | VATSIM |
|---|---|---|
| Daily flights | ~45,000 | ~2,000-5,000 (varies greatly) |
| Schedule predictability | High (OAG data, airline schedules) | Low (individual pilot decisions) |
| Event-driven peaks | Weather/holiday | Organized events, time-of-day patterns |
| CDM participation | 40+ airlines | None (individual pilots) |
| Flight plan reliability | Moderate (amendments tracked) | Lower (SimBrief plans may not match actual) |

**Impact**: Probabilistic demand prediction (IDP) may be less effective due to lower traffic volumes and less predictable scheduling. Event-based demand forecasting may be more appropriate.

### 6.2 Infrastructure Differences

| Factor | FMDS | PERTI |
|---|---|---|
| Architecture | Cloud-native IaaS, microservices | Azure App Service (Linux), PHP monolith with daemon pattern |
| Sites | 114 physical facilities | 1 web application, N Discord organizations |
| Availability requirement | 99.99% | Best-effort (free-tier constraints) |
| Users | 600 concurrent (peak) | ~50-200 concurrent (peak events) |
| Data sources | ERAM, STARS, TFDM, TBFM, CSS-FD, CSS-Wx | VATSIM data feed, aviationweather.gov |
| Authentication | PIV/CAC + RBAC | VATSIM OAuth + session |

**Impact**: PERTI doesn't need FMDS's disaster recovery, multi-site failover, or 99.99% availability. However, architectural improvements (API modularization, better caching) would benefit scaling.

### 6.3 Operational Scope

| Factor | FMDS | PERTI |
|---|---|---|
| TMI authority | Legally binding (EDCTs enforceable) | Advisory (compliance is voluntary) |
| Coordination | Formal inter-facility procedures | Discord-based consensus |
| Training | Formal certification requirements | Community-driven training |
| Post-event accountability | FAA oversight, congressional scrutiny | Internal improvement cycle |

**Impact**: PERTI's TMI compliance is inherently advisory. Features like EDCT enforcement, formal slot substitutions, and CDM member data quality tracking have reduced importance.

### 6.4 Weather

VATSIM does not simulate weather effects on aircraft performance. Weather on VATSIM is informational only -- it affects runway selection and ATIS but not aircraft trajectories or separation requirements. This means:
- **RAPT/route blockage** has limited value (no convective avoidance necessary)
- **Weather-based capacity reduction** is a training/realism exercise rather than operational necessity
- **Wind-adjusted trajectory modeling** has value for ETA prediction but not safety

---

## 7. Recommended Adaptation Roadmap

Based on the gap analysis and VATSIM-specific considerations, the following adaptations are prioritized by impact and feasibility:

### Tier 1: High Impact, High Feasibility (0-6 months)

| Initiative | FMDS Reference | PERTI Impact | Effort |
|---|---|---|---|
| **Airspace Flow Program (AFP)** | F.3.2.1.5 | Enables FxA-scoped demand programs beyond airport GDPs | Medium -- extend existing GDP infrastructure with FxA scope |
| **Integrated Situation Display** | F.5.1, HIG | Consolidate demand, TMI, weather, and flight data into a single view | High -- significant frontend redesign but high operational value |
| **Formal NTML/NAS Event Log** | F.3.3 | Structured logging of shift events, TMI critiques, operational decisions | Low -- mostly database schema and simple UI |
| **Server-side Display Preferences** | F.5.3 | Allow users to save/recall configurations across devices | Low -- database table + API endpoint |

### Tier 2: High Impact, Medium Feasibility (6-12 months)

| Initiative | FMDS Reference | PERTI Impact | Effort |
|---|---|---|---|
| **Adaptive Compression** | F.3.2.8.2 | Automatically optimize GDP slots as tactical situation changes | Medium -- algorithm development against existing slot infrastructure |
| **TMI Interaction Modeling** | F.3.2.1.9 | Model combined effects of multiple overlapping TMIs | High -- requires cross-program impact computation |
| **Post-Event Replay** | F.4.2 | Replay historical data with TMI trial modeling capability | High -- requires temporal data access layer and replay UI |
| **Route Availability Visualization** | F.2.4 | Display weather-based route status timelines (simplified RAPT) | Medium -- overlay weather radar on route corridors with status scoring |

### Tier 3: Medium Impact, Higher Effort (12-24 months)

| Initiative | FMDS Reference | PERTI Impact | Effort |
|---|---|---|---|
| **Probabilistic Demand (IDP)** | F.2.2.2-F.2.2.3 | Improve demand prediction accuracy using historical error analysis | High -- requires statistical analysis infrastructure and historical data accumulation |
| **CTOP Implementation** | F.3.2.1.6 | Enable multi-element programs with trajectory option sets | Very High -- requires TOS protocol, multi-FxA scope, complex assignment algorithms |
| **Departure Fix Management** | F.2.1.3 | Manage departure fix rates, combining, and availability | Medium -- extends existing demand monitoring |
| **Training Scenario System** | F.6.7-F.6.8 | Scripted training with closed-loop simulation | Very High -- requires full simulation environment with scenario management |

### Tier 4: Lower Priority / VATSIM-Specific Adaptation (Ongoing)

| Initiative | FMDS Reference | PERTI Impact | Effort |
|---|---|---|---|
| **4D Conformance Evaluation** | F.1.3.1.2 | Detect and classify ATC maneuvers from trajectory data | High effort, moderate value for VATSIM |
| **FxA Definition Tool** | F.3.1 | User-defined polygonal flow evaluation areas | Medium effort, adds analytical flexibility |
| **CDM-like Pilot Engagement** | F.1.1.5 | Formalized pilot data sharing for demand improvement | Medium effort, requires community buy-in |
| **RBAC Enhancement** | F.6.6 | Full role-based access control with role management | Medium effort, needed as organization scales |

---

## Appendix A: FMDS Document Index

| # | Document | Pages | Key Content |
|---|---|---|---|
| 1 | Announcement of ChBA | 6 | Acquisition structure, 3-phase process, deadlines |
| 2 | Attachment 1: SOO | 8 | 3 core objectives, 16 technical objectives, 10-year PoP |
| 3 | Attachment 2: fPRD | 160 | Hundreds of FMDS_fPRD_XXXX requirements across F.1-F.6 |
| 4 | Attachment 5: ConOps | 91 | As-is vs to-be operations, 6 operational scenarios, user roles |
| 5 | Attachment 6: FAD | 186 | 200+ leaf functions, FFBDs, N2 diagrams, data dictionary |
| 6 | Attachment 7: HIG | 49 | UI/UX design principles, color coding, font requirements, 78-item checklist |
| 7 | Attachment 8: Master Site List | - | 114 sites (34 towers, 21 ARTCCs, 21 TRACONs, etc.) |
| 8 | Attachment 9: NDA/NUA | 3 | Non-disclosure agreement for SSI documents |
| 9 | Attachment 10: Employee Screening | - | Blank template |
| 10 | Attachment 11: Business Declaration | 1 | FAA business declaration form |
| 11 | Attachment 12: Method of Evaluation | 4 | Meets/Does Not Meet on 4 criteria |
| 12 | Attachment L1: Cover Page Template | - | Concept paper template |
| 13 | Attachment L2: Minimum Org Requirements | 1 | 8 mandatory questions including TRL 8 requirement |
| 14 | Attachment L3: Prior Experience Reference | 1 | Reference template |

## Appendix B: PERTI File Reference Index

### TMI System
- `api/tmi/gdp_preview.php` -- GDP impact preview with arrival-time filtering
- `api/tmi/gdp_apply.php` -- Apply GDP from sandbox to live ADL
- `api/tmi/gdp_simulate.php` -- GDP simulation
- `api/tmi/gs/` -- Full GS lifecycle (create, model, activate, extend, purge, flights, demand, list, get)
- `api/tmi/gs/common.php` -- Shared GS utilities (DB helpers, auth, input parsing)
- `api/tmi/gs_preview.php` -- Ground Stop preview with departure filtering
- `api/tmi/advisories.php` -- TMI advisory management
- `api/tmi/entries.php` -- TMI restriction entries (MIT, MINIT, STOP, APREQ, etc.)
- `api/mgt/tmi/coordinate.php` -- Multi-facility coordination workflow
- `api/mgt/tmi/promote.php` -- TMI promotion (staging to active)
- `scripts/tmi_compliance/core/analyzer.py` -- Python TMI compliance engine

### Flight Data Management
- `scripts/vatsim_adl_daemon.php` -- 15-second flight data ingestion
- `adl/php/parse_queue_gis_daemon.php` -- Route parsing with PostGIS
- `adl/php/boundary_gis_daemon.php` -- Spatial boundary detection
- `adl/php/crossing_gis_daemon.php` -- Boundary crossing prediction
- `adl/php/waypoint_eta_daemon.php` -- Waypoint ETA calculation

### Demand & Capacity
- `api/adl/demand/fix.php` -- Fix-based demand
- `api/adl/demand/segment.php` -- Route segment demand
- `api/adl/demand/airway.php` -- Airway demand
- `api/demand/airport.php` -- Airport demand
- `api/demand/rates.php` -- AAR/ADR rates
- `api/demand/override.php` -- Manual rate overrides
- `assets/js/demand.js` -- Demand chart visualization

### Display & Visualization
- `assets/js/route-maplibre.js` -- MapLibre route visualization
- `assets/js/tmi-active-display.js` -- Active TMI display
- `assets/js/tmi_compliance.js` -- TMI compliance visualization (~280KB)
- `assets/js/weather_radar.js` -- Weather radar overlay
- `assets/js/gdt.js` -- Ground Delay Table
- `assets/js/splits.js` -- Sector split management

### External APIs
- `api/swim/v1/` -- SWIM API (FIXM-aligned)
- `api/swim/v1/ws/WebSocketServer.php` -- Real-time WebSocket

### Infrastructure
- `load/discord/MultiDiscordAPI.php` -- Multi-org Discord posting
- `discord-bot/bot.js` -- Discord Gateway bot for coordination
- `scripts/startup.sh` -- Daemon orchestration
- `scripts/archival_daemon.php` -- Trajectory tiering and archival
