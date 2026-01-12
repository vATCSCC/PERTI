# vATCSCC PERTI — Codebase Index (v17)

Generated: 2026-01-11 UTC

This index is a comprehensive reference for navigation, call graphs, and high-signal files.

## Quick navigation

- [1) High-level architecture](#1-high-level-architecture)
- [2) Boot / shared includes](#2-boot--shared-includes)
- [3) Database schema quick index](#3-database-schema-quick-index)
- [4) Main pages (PHP entrypoints)](#4-main-pages-php-entrypoints)
- [5) Client-side modules (`assets/js/`)](#5-client-side-modules-assetsjs)
- [6) API endpoints index (`/api/...`)](#6-api-endpoints-index-api)
- [7) Database migrations and schema artifacts](#7-database-migrations-and-schema-artifacts)
- [8) Reference data and GeoJSON](#8-reference-data-and-geojson)
- [9) Operational scripts (`scripts/`)](#9-operational-scripts-scripts)
- [10) Ground Stop (GS) subsystem](#10-ground-stop-gs-subsystem--detailed-notes)
- [11) GDP / GDT subsystem](#11-gdp--gdt-ground-delay-program-subsystem--detailed-notes)
- [12) Reroute subsystem](#12-reroute-subsystem--detailed-notes)
- [13) Splits subsystem](#13-splits-subsystem--detailed-notes)
- [14) Live Flights TSD / route.php](#14-live-flights-tsd--routephp--detailed-notes)
- [15) ADL history snapshotting](#15-adl-history-snapshotting-and-tracks)
- [16) `sp_Adl_RefreshFromVatsim`](#16-stored-procedure-sp_adl_refreshfromvatsim--notes)
- [17) Public Routes subsystem](#17-public-routes-subsystem)
- [18) JATOC subsystem](#18-jatoc-subsystem)
- [19) StatSim subsystem](#19-statsim-subsystem)
- [20) NASR Navigation Data Updater](#20-nasr-navigation-data-updater)
- [21) NOD subsystem](#21-nod-nas-operations-dashboard-subsystem)
- [22) ADL Refresh Patterns](#22-adl-refresh-patterns)
- [23) Initiative Timeline subsystem](#23-initiative-timeline-subsystem)
- [24) Weather Radar subsystem](#24-weather-radar-subsystem)
- [25) SUA/TFR subsystem](#25-suatfr-subsystem)
- [26) Demand subsystem](#26-demand-subsystem)
- [27) Airport Configuration & ATIS subsystem](#27-airport-configuration--atis-subsystem)
- [28) ATFM Training Simulator (NEW v17)](#28-atfm-training-simulator-new-v17)
- [29) Practical gotchas](#29-practical-gotchas-to-remember)
- [30) Search anchors](#30-search-anchors-fast-grep-patterns)
- [31) Changelog](#31-changelog)
- [32) VATSIM_ADL Azure SQL Database Reference](#32-vatsim_adl-azure-sql-database-reference)
- [33) VATSIM_PERTI MySQL Database Reference](#33-vatsim_perti-mysql-database-reference)

---

## 1) High-level architecture

### 1.1 Top-level layout

- `index.php` — landing/dashboard
- `plan.php`, `review.php`, `schedule.php`, `sheet.php` — planning workflow pages (MySQL-backed)
- `tmi.php` — TMI tools page (GS + GDP; Azure SQL ADL-backed) **(not in current snapshot)**
- `gdt.php` — **Ground Delay Tool** (GDT) page (FSM-style GDP interface)
- `reroutes.php`, `reroutes_index.php` — reroute authoring + monitoring (Azure SQL-backed)
- `route.php` — TSD-style live flight map + route plotting (Azure SQL-backed; Leaflet/MapLibre)
- `splits.php` — sector/position split configuration map (Azure SQL-backed; MapLibre)
- `jatoc.php` — JATOC AWO Incident Monitor (Azure SQL-backed; MapLibre)
- `nod.php` — **NAS Operations Dashboard** (consolidated monitoring; MapLibre)
- `simulator.php` — **ATFM Training Simulator** (PLANNED v17)

### 1.2 Data stores

- **MySQL** (via `mysqli_*`): plans, schedules, configs, comments, initiative timelines, and `tmi_ground_stops` definitions.
- **Azure SQL** (via `sqlsrv_*`): ADL live flight state (`dbo.adl_flights`) + TMI workflows (GS/GDP/Reroute/Splits/History) + JATOC incidents + NOD advisories + Simulator reference data.

### 1.3 Background/refresh jobs

- `scripts/vatsim_adl_daemon.php` — pulls VATSIM feed every ~15s and calls `sp_Adl_RefreshFromVatsim` to refresh `dbo.adl_flights`.
- `scripts/refresh_vatsim_boundaries.php` — refreshes `assets/geojson/artcc.json` and `assets/geojson/tracon.json` from official VATSIM sources.
- `scripts/update_playbook_routes.py` — updates `assets/data/playbook_routes.csv` (playbook route reference).
- `nasr_navdata_updater.py` — NASR Navigation Data Updater for FAA AIRAC cycles.

---

## 2) Boot / shared includes

- `load/config.php` — local configuration (DB creds, site config, ADL SQL constants).
- `load/connect.php` — initializes DB connections:
  - `$conn` (MySQL)
  - `$conn_adl` (Azure SQL / sqlsrv)
- `load/header.php`, `load/nav.php`, `load/footer.php` — shared layout and script/style includes.
- `includes/gdp_section.php` — GDP/GDT section component (reusable)
- `.htaccess` — rewrite rule for extensionless `/api/...` requests to resolve `.php` endpoints.

---

## 3) Database schema quick index

### 3.1 MySQL tables (perti_site schema)

- `users`
- `admin_users`
- `config_data`
- `p_plans`
- `p_dcc_staffing`
- `p_forecast`
- `p_historical`
- `p_configs`
- `p_terminal_init`
- `p_terminal_init_times`
- `p_terminal_init_timeline`
- `p_terminal_staffing`
- `p_terminal_planning`
- `p_terminal_constraints`
- `p_enroute_init`
- `p_enroute_init_times`
- `p_enroute_init_timeline`
- `p_enroute_staffing`
- `p_enroute_planning`
- `p_enroute_constraints`
- `p_group_flights`
- `p_op_goals`
- `r_scores`
- `r_comments`
- `r_data`
- `r_ops_data`
- `route_cdr`
- `route_playbook`
- `tmi_ground_stops`
- `assigned`

### 3.2 Azure SQL / ADL tables (high-signal subset)

This project uses Azure SQL (via `sqlsrv_*` and `$conn_adl`) for live flight state + TMI workflows. Full table/column listing is in Section 32.

- **dbo.adl_flights** — Live flight data (refreshed every ~15s)
- **dbo.adl_flights_gs** — Ground Stop applied flight state
- **dbo.adl_flights_history** — Historical flight snapshots
- **dbo.adl_run_log** — Daemon run log

### 3.3 TMI Azure SQL Tables

- **dbo.tmi_reroutes** — Reroute definitions
- **dbo.tmi_reroute_flights** — Flight assignments
- **dbo.tmi_reroute_compliance_log** — Compliance history

### 3.4 Simulator Reference Tables (NEW v17)

- **dbo.sim_ref_carrier_lookup** — 17 carriers with IATA/ICAO mappings
- **dbo.sim_ref_route_patterns** — 3,989 O-D routes with hourly patterns
- **dbo.sim_ref_airport_demand** — 107 airports with demand curves
- **dbo.sim_ref_import_log** — Import tracking

---

## 4) Main pages (PHP entrypoints)

| Page | Scripts | Size (lines) |
|------|---------|--------------|
| `configs.php` | — | ~15K |
| `data.php` | `sheet.js` | ~19K |
| `gdt.php` | `gdt.js` | ~98K |
| `index.php` | — | ~23K |
| `jatoc.php` | `jatoc.js` | ~93K |
| `nod.php` | `nod.js` | ~58K |
| `plan.php` | `plan.js`, `initiative_timeline.js` | ~115K |
| `reroutes.php` | `reroute.js` | ~22K |
| `reroutes_index.php` | inline JS | ~12K |
| `review.php` | `review.js`, `statsim_rates.js` | ~30K |
| `route.php` | `route.js`, `route-maplibre.js`, `weather_radar.js` | ~137K |
| `schedule.php` | `schedule.js` | ~8K |
| `sheet.php` | `sheet.js` | ~21K |
| `splits.php` | `splits.js` | ~103K |
| `simulator.php` | `simulator/*.js` | PLANNED v17 |

---

## 28) ATFM Training Simulator (NEW v17)

### Overview

Semi-stochastic ATFM training simulator for National TMU (DCC) and facility-level TMU trainees to practice issuing TMIs against realistic traffic scenarios.

### Status: Phase 0 - In Progress

| Phase | Status | Description |
|-------|--------|-------------|
| Data Import | ✅ COMPLETE | BTS data processed (20.6M flights → reference tables) |
| SQL Deployment | ✅ COMPLETE | sim_ref_* tables deployed to Azure SQL |
| Flight Engine | ✅ CODE COMPLETE | Node.js physics engine (needs relocation) |
| Integration | 🔄 PENDING | Move engine to PERTI/simulator/, create PHP wrapper |
| Frontend | 📋 PLANNED | simulator.php page with MapLibre display |

### Database Tables (Deployed)

```sql
-- 17 carriers with IATA/ICAO mappings
sim_ref_carrier_lookup (carrier_id, carrier_code, carrier_icao, carrier_name)

-- 3,989 O-D routes with hourly patterns  
sim_ref_route_patterns (
    route_id, origin, destination, avg_daily_flights,
    primary_carrier_icao, carrier_weights_json, aircraft_mix_json,
    dep_hour_pattern_json, flight_time_min, distance_nm, is_hub_route
)

-- 107 airports with demand curves
sim_ref_airport_demand (
    airport_id, airport_name, avg_daily_departures, avg_daily_arrivals,
    pattern_type, hourly_dep_pattern_json, hourly_arr_pattern_json,
    peak_dep_hours, peak_arr_hours
)
```

### Flight Engine Components (Code Complete - Wrong Location)

**Current Location:** `VATSIM PERTI/atfm-flight-engine/` (WRONG)
**Target Location:** `VATSIM PERTI/PERTI/simulator/engine/`

| File | Purpose | Lines |
|------|---------|-------|
| `src/index.js` | Express HTTP API server (port 3001) | ~300 |
| `src/SimulationController.js` | Multi-simulation management | ~350 |
| `src/aircraft/AircraftModel.js` | Flight physics engine | ~450 |
| `src/math/flightMath.js` | Great circle, TAS/IAS, wind | ~250 |
| `src/constants/flightConstants.js` | Aviation constants | ~100 |
| `src/navigation/NavDataClient.js` | PERTI nav_fixes integration | ~300 |
| `config/aircraftTypes.json` | 20 aircraft performance profiles | - |

### Flight Engine HTTP API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/simulation/create` | Create new simulation |
| GET | `/simulation` | List all simulations |
| POST | `/simulation/:id/aircraft` | Spawn aircraft |
| GET | `/simulation/:id/aircraft` | Get all aircraft state |
| POST | `/simulation/:id/tick` | Advance time by N seconds |
| POST | `/simulation/:id/command` | Issue ATC command |
| DELETE | `/simulation/:id` | Delete simulation |

### ATC Commands Supported

| Command | Alias | Parameters | Description |
|---------|-------|------------|-------------|
| FH | FLY_HEADING | heading | Fly specific heading |
| TL | TURN_LEFT | heading | Turn left to heading |
| TR | TURN_RIGHT | heading | Turn right to heading |
| CM | CLIMB | altitude | Climb and maintain |
| DM | DESCEND | altitude | Descend and maintain |
| SP | SPEED | speed | Maintain speed (knots) |
| D | DIRECT | fix | Proceed direct to fix |
| RESUME | - | - | Resume own navigation |

### Planned File Structure (Target)

```
PERTI/
├── simulator/                      ← NEW DIRECTORY
│   ├── engine/                     ← Node.js flight engine
│   │   ├── src/
│   │   │   ├── index.js
│   │   │   ├── SimulationController.js
│   │   │   ├── aircraft/AircraftModel.js
│   │   │   ├── math/flightMath.js
│   │   │   ├── constants/flightConstants.js
│   │   │   └── navigation/NavDataClient.js
│   │   ├── config/aircraftTypes.json
│   │   └── package.json
│   └── scenarios/                  ← Scenario definitions
├── simulator.php                   ← Main page
├── assets/js/simulator/            ← Frontend controllers
│   ├── SimulatorController.js
│   ├── FlightDisplay.js
│   └── TMIPanel.js
└── api/simulator/                  ← PHP API endpoints
    ├── session.php
    ├── tick.php
    └── tmi.php
```

### Data Sources

| Source | Records | Purpose |
|--------|---------|---------|
| BTS On-Time Performance | 20.6M flights (2022-2024) | Route patterns, timing |
| BTS T-100 Domestic | - | Aircraft type mappings |
| openScope | 100+ aircraft | Performance profiles (MIT license) |
| PERTI nav_fixes | ~200K | Navigation waypoints |
| PERTI nav_procedures | ~15K | SIDs/STARs |

### Related Documents

| Document | Location |
|----------|----------|
| Design Document | `docs/ATFM_Simulator_Design_Document_v1.md` |
| Transition Summary | `../ATFM_Simulator_Transition_2026-01-11.md` |
| Flight Engine Transition | `../ATFM_Flight_Engine_Transition.md` |
| BTS SQL Scripts | `../BTS/sql/050_sim_ref_tables.sql` |

### Next Steps

1. **Move flight engine** to `PERTI/simulator/engine/`
2. **Create simulator.php** main page with MapLibre display
3. **Wire NavDataClient** to PERTI's `/api/data/fixes.php`
4. **Build scenario generator** using sim_ref_* tables
5. **Implement TMI application** (GS, GDP effects on flights)

---

## 29) Practical gotchas to remember

1. **Azure SQL Serverless**: May have cold-start delays; first query after idle can be slow
2. **Stored procedure timeouts**: Complex ADL refresh can timeout; use connection timeout settings
3. **MapLibre vs Leaflet**: Feature flag controls which library; some features differ
4. **OAuth sessions**: VATSIM OAuth tokens expire; handle refresh gracefully
5. **GeoJSON size**: Large boundary files can impact load time; use compression
6. **Weather radar tiles**: IEM may rate-limit; use multiple hosts
7. **AIRAC cycles**: Navigation data updates every 28 days; schedule updates accordingly
8. **Node.js in Azure**: App Service supports Node.js but may need IISNode configuration

---

## 30) Search anchors (fast grep patterns)

```bash
# Find API endpoint
grep -r "api/tmi" assets/js/

# Find database queries
grep -r "sqlsrv_query\|mysqli_query" api/

# Find stored procedure calls
grep -r "EXEC\s*sp_" api/

# Find MapLibre usage
grep -r "maplibregl\|MapLibre" assets/js/

# Find simulator references
grep -r "simulator\|sim_ref" .
```

---

## 31) Changelog

- **v17 (2026-01-11):**
  - **ATFM Training Simulator - Phase 0:**
    - BTS data import complete (20.6M flights → 3,989 routes, 107 airports, 17 carriers)
    - SQL reference tables deployed: `sim_ref_carrier_lookup`, `sim_ref_route_patterns`, `sim_ref_airport_demand`
    - Node.js flight engine code complete (AircraftModel, SimulationController, HTTP API)
    - Flight physics: position updates, climb/descent, turns, FMS waypoint following
    - ATC commands: heading, altitude, speed, direct-to
    - 20 aircraft performance profiles from openScope
    - **PENDING:** Relocate engine from `../atfm-flight-engine/` to `PERTI/simulator/engine/`
  - **Documentation:**
    - `docs/ATFM_Simulator_Design_Document_v1.md` — Complete design reference
    - `ATFM_Flight_Engine_Transition.md` — Session transition details

- **v16 (2026-01-10):**
  - Demand Subsystem
  - Airport Configuration & ATIS Schema
  - Rate suggestion algorithm with multi-level fallback

- **v15 (2026-01-10):**
  - GDT Ground Stop NTML Architecture
  - New GS stored procedures and views
  - ADL Schema Cleanup

- **v14 (2026-01-07):**
  - Weather Radar Subsystem
  - SUA/TFR Subsystem

---

## 32) VATSIM_ADL Azure SQL Database Reference

_Full table/column listing available in VATSIM_ADL_tree.json project file._

### Tables

| Table | Description |
|-------|-------------|
| `ACD_Data` | Aircraft characteristics |
| `adl_flights` | Live flight data |
| `adl_flights_gs` | Ground stop applied state |
| `adl_flights_history` | Historical snapshots |
| `adl_run_log` | Daemon run log |
| `aircraft_type_lookup` | Aircraft type reference |
| `apts` | Airport data |
| `dcc_advisories` | DCC advisories |
| `dcc_advisory_references` | Advisory cross-refs |
| `dcc_discord_tmi` | Discord TMI storage |
| `eta_citypair_lookup` | City pair ETAs |
| `jatoc_daily_ops` | JATOC daily operations |
| `jatoc_incidents` | Incident records |
| `jatoc_incident_updates` | Incident updates |
| `jatoc_personnel` | Personnel roster |
| `jatoc_reports` | Incident reports |
| `nav_fixes` | Navigation waypoints |
| `nav_procedures` | SIDs/STARs |
| `nav_procedure_legs` | Procedure leg details |
| `nav_airways` | Airway definitions |
| `ntml` | National Traffic Management Log |
| `ntml_info` | TMI event log / audit trail |
| `ntml_slots` | Arrival slot allocation |
| `public_routes` | Public route definitions |
| `sim_ref_carrier_lookup` | Simulator carrier reference (NEW v17) |
| `sim_ref_route_patterns` | Simulator route patterns (NEW v17) |
| `sim_ref_airport_demand` | Simulator airport demand (NEW v17) |
| `sim_ref_import_log` | Simulator import tracking (NEW v17) |
| `splits_areas` | Split area definitions |
| `splits_configs` | Split configurations |
| `splits_positions` | Position assignments |
| `splits_presets` | Preset configurations |
| `tmi_reroute_compliance_log` | Compliance history |
| `tmi_reroute_flights` | Flight assignments |
| `tmi_reroutes` | Reroute definitions |
| `tracons` | TRACON reference |
| `airport_config` | Runway configuration definitions |
| `airport_config_runway` | Normalized runway assignments |
| `airport_config_rate` | Rate tables by weather category |
| `vatsim_atis` | Raw ATIS broadcasts with weather |
| `runway_in_use` | Parsed runway assignments |
| `manual_rate_override` | Controller rate overrides |

### Views

| View | Description |
|------|-------------|
| `vw_adl_flights` | Live flight unified view |
| `vw_adl_flights_history_30d` | 30-day history view |
| `vw_dcc_advisories_today` | Today's advisories |
| `vw_jatoc_active_incidents` | Active incidents |
| `vw_GDT_FlightList` | GDT flight list |
| `vw_GDT_DemandByQuarter` | 15-min demand bins |
| `vw_GDT_DemandByHour` | Hourly demand |
| `vw_GDT_DemandByCenter` | Demand by ARTCC |
| `vw_NTML_Active` | Active TMI programs |
| `vw_NTML_Today` | Today's TMI programs |

---

## 33) VATSIM_PERTI MySQL Database Reference

_Full table/column listing available in VATSIM_PERTI_tree.json project file._

### Tables (perti_site schema)

| Table | Description |
|-------|-------------|
| `admin_users` | Admin user accounts |
| `assigned` | Assignment data |
| `config_data` | Configuration data |
| `p_configs` | Plan configurations |
| `p_dcc_staffing` | DCC staffing |
| `p_enroute_constraints` | Enroute constraints |
| `p_enroute_init` | Enroute initiatives |
| `p_enroute_init_timeline` | Enroute timeline entries |
| `p_enroute_init_times` | Enroute initiative times |
| `p_enroute_planning` | Enroute planning |
| `p_enroute_staffing` | Enroute staffing |
| `p_forecast` | Traffic forecast |
| `p_group_flights` | Group flights |
| `p_historical` | Historical data |
| `p_op_goals` | Operational goals |
| `p_plans` | Plans (main table) |
| `p_terminal_constraints` | Terminal constraints |
| `p_terminal_init` | Terminal initiatives |
| `p_terminal_init_timeline` | Terminal timeline entries |
| `p_terminal_init_times` | Terminal initiative times |
| `p_terminal_planning` | Terminal planning |
| `p_terminal_staffing` | Terminal staffing |
| `r_comments` | Review comments |
| `r_data` | Review data |
| `r_ops_data` | Operations data |
| `r_scores` | Review scores |
| `route_cdr` | CDR route cache |
| `route_playbook` | Playbook route cache |
| `tmi_ground_stops` | Ground stop definitions |
| `users` | User accounts |
