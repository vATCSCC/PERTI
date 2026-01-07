# vATCSCC PERTI — Codebase Index (v13)

Generated: 2026-01-06 UTC

This index provides a comprehensive reference for the PERTI Traffic Flow Management System codebase, including architecture, call graphs, database schemas, and high-signal files.

## Quick Navigation

- [1) High-level Architecture](#1-high-level-architecture)
- [2) Technology Stack](#2-technology-stack)
- [3) Boot / Shared Includes](#3-boot--shared-includes)
- [4) Database Architecture](#4-database-architecture)
- [5) Main Pages (PHP Entrypoints)](#5-main-pages-php-entrypoints)
- [6) Client-side Modules](#6-client-side-modules-assetsjs)
- [7) API Endpoints Index](#7-api-endpoints-index)
- [8) ADL Database Redesign](#8-adl-database-redesign-new-v13)
- [9) Stored Procedures](#9-stored-procedures)
- [10) Reference Data & GeoJSON](#10-reference-data-and-geojson)
- [11) Background Jobs & Daemons](#11-background-jobs-and-daemons)
- [12) Database Migrations](#12-database-migrations)
- [13) Ground Stop (GS) Subsystem](#13-ground-stop-gs-subsystem)
- [14) GDP / GDT Subsystem](#14-gdp--gdt-subsystem)
- [15) Reroute Subsystem](#15-reroute-subsystem)
- [16) Splits Subsystem](#16-splits-subsystem)
- [17) Live Flights TSD / route.php](#17-live-flights-tsd--routephp)
- [18) ADL History & Tracks](#18-adl-history-and-tracks)
- [19) Public Routes Subsystem](#19-public-routes-subsystem)
- [20) JATOC Subsystem](#20-jatoc-subsystem)
- [21) NOD Subsystem](#21-nod-nas-operations-dashboard)
- [22) Initiative Timeline Subsystem](#22-initiative-timeline-subsystem)
- [23) Weather Integration](#23-weather-integration-new-v13)
- [24) OOOI Zone Detection](#24-oooi-zone-detection-new-v13)
- [25) Boundary Detection](#25-boundary-detection-new-v13)
- [26) ETA Trajectory System](#26-eta-trajectory-system-new-v13)
- [27) Route Parsing & GIS](#27-route-parsing--gis-new-v13)
- [28) Advisory Builder](#28-advisory-builder-new-v13)
- [29) Demand Visualization](#29-demand-visualization-new-v13)
- [30) ADL Refresh Patterns](#30-adl-refresh-patterns)
- [31) StatSim Integration](#31-statsim-integration)
- [32) NASR Navigation Data Updater](#32-nasr-navigation-data-updater)
- [33) External Data Sources](#33-external-data-sources)
- [34) Practical Gotchas](#34-practical-gotchas)
- [35) Search Anchors](#35-search-anchors)
- [36) Azure SQL Database Reference](#36-vatsim_adl-azure-sql-database-reference)
- [37) MySQL Database Reference](#37-vatsim_perti-mysql-database-reference)
- [38) Changelog](#38-changelog)

---

## 1) High-level Architecture

### 1.1 Project Overview

**Project Name:** PERTI (Virtual Air Traffic Control System Command Center)
**Production URL:** https://vatcscc.azurewebsites.net
**Purpose:** Professional-grade web-based traffic flow management platform for VATSIM
**Target Users:** Virtual air traffic controllers, traffic managers, operations planners

### 1.2 Top-level Layout

| File | Purpose |
|------|---------|
| `index.php` | Landing/dashboard |
| `plan.php`, `review.php`, `schedule.php`, `sheet.php` | Planning workflow (MySQL-backed) |
| `gdt.php` | Ground Delay Tool (FSM-style GDP interface) |
| `reroutes.php`, `reroutes_index.php` | Reroute authoring + monitoring (Azure SQL) |
| `route.php` | TSD-style live flight map + route plotting (Azure SQL; MapLibre) |
| `splits.php` | Sector/position split configuration (Azure SQL; MapLibre) |
| `jatoc.php` | JATOC AWO Incident Monitor (Azure SQL; public) |
| `nod.php` | NAS Operations Dashboard (consolidated monitoring; public) |
| `demand.php` | Demand visualization (Azure SQL) |
| `sua.php` | Special Use Airspace display |
| `advisory-builder.php` | Route advisory generator |

### 1.3 Key Capabilities

- Real-time flight tracking with TSD-style visualization
- Ground Stop (GS) and Ground Delay Program (GDP) management
- Route planning, plotting, and advisory generation
- Airspace split configuration management
- Incident monitoring (JATOC - Joint Air Traffic Operations Command)
- System-wide operations dashboard (NOD)
- Traffic demand analysis and forecasting
- Weather integration and boundary tracking
- OOOI zone detection (Out/Off/On/In)
- ETA trajectory calculation

### 1.4 Data Stores

- **MySQL** (`mysqli_*`): Plans, schedules, configs, comments, initiative timelines, `tmi_ground_stops`
- **Azure SQL** (`sqlsrv_*`): ADL live flight state, TMI workflows (GS/GDP/Reroute/Splits), JATOC, NOD, weather, boundaries

---

## 2) Technology Stack

### Backend
- PHP 7.4+ (primary language)
- Azure SQL Server (SQL Server via sqlsrv extension)
- MySQL (via mysqli and PDO)
- Python (maintenance scripts)
- PowerShell (data import scripts)

### Frontend
- JavaScript (ES6+) and jQuery
- MapLibre GL JS (vector mapping)
- Leaflet.js (mapping library)
- Chart.js, D3.js, Apache ECharts (visualizations)
- Bootstrap 4.5 (UI framework)

### Infrastructure
- Azure App Service (hosting)
- Azure SQL Database (production data)
- GitHub Actions / Azure Pipelines (CI/CD)

---

## 3) Boot / Shared Includes

| File | Purpose |
|------|---------|
| `load/config.php` | Configuration (DB creds, OAuth, Discord) |
| `load/connect.php` | DB connections: `$conn` (MySQL), `$conn_adl` (Azure SQL) |
| `load/header.php` | HTML head/includes |
| `load/nav.php` | Navigation bar |
| `load/footer.php` | Footer |
| `load/breadcrumb.php` | Breadcrumb navigation |
| `load/gdp_section.php` | GDP/GDT component (reusable) |
| `.htaccess` | URL rewriting for extensionless API requests |

### Discord Integration (`load/discord/`)
- `DiscordAPI.php` — Discord API wrapper
- `DiscordMessageParser.php` — Message parsing
- `DiscordWebhookHandler.php` — Webhook handling

---

## 4) Database Architecture

### 4.1 Dual Database Design

PERTI uses **two separate databases**:

**MySQL Database (perti_site):**
- Planning & scheduling data
- User accounts and permissions
- Configuration settings
- Comments and reviews
- Initiative timelines
- Ground stop definitions

**Azure SQL Server Database (VATSIM_ADL):**
- Live flight state (normalized into 10+ tables)
- Flight history snapshots & trajectories
- Route planning & waypoint data
- TMI workflows (GS, GDP, Reroutes)
- Splits & position assignments
- JATOC incidents & NOD data
- Weather alerts & boundary detection
- OOOI zone events
- Navigation reference data (270K+ fixes)

### 4.2 ADL Normalization Strategy

Flight data split into purpose-specific tables (v8+ schema):
- `adl_flight_core` — Master flight registry with lifecycle state
- `adl_flight_position` — Real-time position, altitude, velocity
- `adl_flight_plan` — Flight plan with GIS geometry
- `adl_flight_waypoints` — Parsed route waypoints
- `adl_flight_stepclimbs` — Step climb records
- `adl_flight_times` — 40+ TFMS time fields
- `adl_flight_trajectory` — Position history (15-second intervals)
- `adl_flight_tmi` — TMI controls & restrictions
- `adl_flight_aircraft` — Aircraft info
- `adl_flight_changelog` — Audit trail
- `vw_adl_flights` — Backward-compatible view

### 4.3 Tiered Async Parsing

Routes parsed based on operational relevance:
| Tier | Interval | Description |
|------|----------|-------------|
| 0 | 15 sec | US/Canada flights |
| 1 | 30 sec | Europe/Atlantic |
| 2 | 1 min | Middle East/Africa |
| 3 | 2 min | Asia |
| 4 | 5 min | Oceania/Remote |

---

## 5) Main Pages (PHP Entrypoints)

| Page | Scripts Loaded | Purpose |
|------|----------------|---------|
| `index.php` | — | Landing/dashboard |
| `route.php` | `route.js`, `route-maplibre.js`, `awys.js`, `procs_enhanced.js` | TSD flight map |
| `gdt.php` | `gdt.js`, `gdp.js` | Ground Delay Tool |
| `plan.php` | `plan.js`, `initiative_timeline.js` | Planning worksheets |
| `review.php` | `review.js`, `statsim_rates.js` | Plan review |
| `schedule.php` | `schedule.js` | Staff scheduling |
| `sheet.php` | `sheet.js` | Operational data |
| `splits.php` | `splits.js` | Sector configuration |
| `jatoc.php` | `jatoc.js` | AWO incident monitor (public) |
| `nod.php` | `nod.js` | Operations dashboard (public) |
| `demand.php` | `demand.js` | Demand visualization |
| `sua.php` | `sua.js` | Airspace display |
| `reroutes.php` | `reroute.js` | Reroute management |
| `advisory-builder.php` | `advisory-builder.js` | Route advisory generator |
| `configs.php` | — | Configuration |
| `privacy.php` | — | Privacy policy |
| `logout.php` | — | Logout handler |

---

## 6) Client-side Modules (`assets/js/`)

### 6.1 Core Page Modules

| Module | Lines | Purpose | APIs Called |
|--------|-------|---------|-------------|
| `route.js` | ~8,222 | TSD flight map | `api/adl/current.php`, `api/adl/flight.php`, `api/data/routes.php` |
| `route-maplibre.js` | ~7,930 | MapLibre GL integration | Same as route.js |
| `splits.js` | ~7,277 | Splits configuration | `api/splits/*` |
| `gdt.js` | ~6,876 | Ground Delay Tool | `api/tmi/gdp_*.php` |
| `nod.js` | ~4,652 | Operations dashboard | `api/nod/*`, `api/adl/current.php` |
| `plan.js` | ~3,492 | Planning interface | `api/data/plans/*`, `api/mgt/*` |
| `public-routes.js` | ~1,831 | Route sharing | `api/routes/public*.php` |
| `gdp.js` | ~1,339 | GDP management | `api/tmi/gdp_*.php` |
| `initiative_timeline.js` | ~1,281 | Timeline visualization | `api/data/plans/*_timeline.php` |
| `jatoc.js` | ~1,171 | Incident monitor | `api/jatoc/*` |
| `advisory-builder.js` | — | Advisory generation | `api/routes/*` |
| `demand.js` | — | Demand visualization | `api/demand/*` |

### 6.2 Data Service Modules

| Module | Lines | Purpose |
|--------|-------|---------|
| `adl-service.js` | ~579 | Centralized ADL data with subscriber pattern |
| `adl-refresh-utils.js` | ~580 | Double-buffering utilities |

### 6.3 Mapping & Visualization

| Module | Lines | Purpose |
|--------|-------|---------|
| `route-symbology.js` | ~865 | TSD-style aircraft symbology |
| `airspace_display.js` | ~942 | Airspace boundary rendering |
| `weather_radar.js` | ~1,034 | Weather radar overlay |
| `weather_radar_integration.js` | ~419 | Weather integration utilities |
| `weather_hazards.js` | — | Hazard visualization |
| `weather_impact.js` | — | Weather impact analysis |

### 6.4 Navigation Data

| Module | Lines | Purpose |
|--------|-------|---------|
| `awys.js` | — | Airway handling |
| `procs.js` | — | SID/STAR procedures (generated) |
| `procs_enhanced.js` | ~1,126 | Enhanced procedures with caching |
| `playbook-cdr-search.js` | ~970 | Playbook/CDR search |

### 6.5 Utilities

| Module | Lines | Purpose |
|--------|-------|---------|
| `cycle.js` | ~177 | AIRAC cycle calculations |
| `jatoc-facility-patch.js` | ~486 | JATOC facility name corrections |
| `statsim_rates.js` | ~1,187 | FSM-style demand bar graphs |
| `leaflet.textpath.js` | — | Path label rendering |
| `theme.min.js` | — | Theme management |

---

## 7) API Endpoints Index

### 7.1 ADL Data (`api/adl/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `current.php` | GET | Live flight list with filters |
| `flight.php` | GET | Single flight details |
| `snapshot_history.php` | GET | Historical snapshots |
| `stats.php` | GET | ADL statistics |
| `waypoints.php` | GET | Parsed route waypoints |
| `boundaries.php` | GET | Boundary detection status |

### 7.2 Data/Reference (`api/data/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `configs.php` | GET | Configuration data |
| `fixes.php` | GET | Fix/waypoint lookup |
| `personnel.php` | GET | Personnel data |
| `reroutes.php` | GET | Reroute data |
| `routes.php` | GET | Route lookup |
| `schedule.php` | GET | Schedule data |
| `sigmets.php` | GET | SIGMET data |
| `sua.php` | GET | Special Use Airspace |
| `tfr.php` | GET | TFR data |
| `weather.php` | GET | Weather data |

### 7.3 Plans (`api/data/plans/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `configs.php` | GET | Plan configurations |
| `dcc_staffing.php` | GET | DCC staffing |
| `enroute_constraints.php` | GET | Enroute constraints |
| `enroute_inits.php` | GET | Enroute initiatives |
| `enroute_inits_timeline.php` | GET/POST/PUT/DELETE | Enroute timeline CRUD |
| `term_inits_timeline.php` | GET/POST/PUT/DELETE | Terminal timeline CRUD |
| `forecast.php` | GET | Traffic forecast |
| `goals.php` | GET | Operational goals |
| `historical.php` | GET | Historical data |

### 7.4 JATOC (`api/jatoc/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `incidents.php` | GET | List incidents |
| `incident.php` | GET/POST/PUT/DELETE | Single incident CRUD |
| `updates.php` | GET/POST | Incident updates |
| `oplevel.php` | GET/PUT | Operations level |
| `personnel.php` | GET/PUT | Roster |
| `daily_ops.php` | GET/PUT | POTUS/Space calendar |
| `report.php` | GET/POST | Report generation |
| `special_emphasis.php` | GET/POST | Special emphasis items |

### 7.5 NOD (`api/nod/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `tmi_active.php` | GET | Consolidated active TMIs |
| `advisories.php` | GET/POST/PUT/DELETE | Advisory CRUD |
| `advisory_import.php` | POST | Bulk advisory import |
| `discord.php` | GET/POST | Discord integration |
| `jatoc.php` | GET | JATOC summary |
| `tmu_oplevel.php` | GET/PUT | TMU operations level |
| `tracks.php` | GET | Flight track history |

### 7.6 Routes (`api/routes/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `public.php` | GET | List public routes |
| `public_post.php` | POST | Create public route |
| `public_update.php` | PUT | Update public route |
| `public_delete.php` | DELETE | Delete public route |

### 7.7 Splits (`api/splits/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `configs.php` | GET | List configurations |
| `config.php` | GET/POST/PUT/DELETE | Single config CRUD |
| `areas.php` | GET/POST/PUT/DELETE | Area management |
| `sectors.php` | GET | Sector data |
| `presets.php` | GET/POST/PUT/DELETE | Preset management |
| `active.php` | GET | Active splits |

### 7.8 TMI Operations (`api/tmi/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `gdp_apply.php` | POST | Apply GDP |
| `gdp_preview.php` | POST | Preview GDP |
| `gdp_purge.php` | POST | Purge GDP |
| `gdp_simulate.php` | POST | Simulate GDP |
| `gs_apply.php` | POST | Apply Ground Stop |
| `gs_preview.php` | POST | Preview GS |
| `gs_purge_all.php` | POST | Purge all GS |
| `gs_simulate.php` | POST | Simulate GS |
| `rr_assign.php` | POST | Assign reroute |
| `rr_compliance_history.php` | GET | Compliance history |
| `rr_export.php` | GET | Export reroutes |
| `rr_preview.php` | POST | Preview reroute |
| `rr_stats.php` | GET | Reroute statistics |

### 7.9 Demand (`api/demand/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `airport.php` | GET | Airport demand |
| `system.php` | GET | System overview |
| `flights.php` | GET | Flight drill-down |

### 7.10 Weather (`api/weather/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `sigmets.php` | GET | SIGMET data |
| `alerts.php` | GET | Weather alerts |

### 7.11 Management (`api/mgt/`)

CRUD endpoints for all plan/config/review data. Structure: `api/mgt/{entity}/{action}.php`

Entities: `comments`, `config_data`, `configs`, `dcc`, `enroute_*`, `forecast`, `goals`, `group_flights`, `historical`, `personnel`, `perti`, `schedule`, `scores`, `terminal_*`, `tmi/ground_stops`, `tmi/reroutes`

Actions: `post.php`, `update.php`, `delete.php`, `fill.php`, `activate.php`, `bulk.php`

---

## 8) ADL Database Redesign (NEW v13)

### 8.1 Overview

The ADL (ATCSCC Data Link) database was redesigned in v8 to support:
- Normalized flight data across 10+ purpose-specific tables
- GIS spatial queries using SQL Server GEOGRAPHY type
- Tiered async route parsing
- ETA trajectory calculations
- OOOI zone detection
- Weather impact analysis
- Boundary detection

### 8.2 Core Flight Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `adl_flight_core` | Master registry | `flight_key`, `callsign`, `dep_apt`, `arr_apt`, `lifecycle_state` |
| `adl_flight_position` | Real-time position | `latitude`, `longitude`, `altitude`, `groundspeed`, `heading` |
| `adl_flight_plan` | Flight plan | `route_string`, `route_geometry`, `filed_altitude`, `equipment` |
| `adl_flight_waypoints` | Parsed waypoints | `sequence`, `fix_name`, `fix_type`, `latitude`, `longitude` |
| `adl_flight_stepclimbs` | Step climbs | `waypoint`, `altitude`, `distance` |
| `adl_flight_times` | TFMS times | `P_time`, `E_time`, `A_time`, `departure_time`, `arrival_time` |
| `adl_flight_trajectory` | Position history | `timestamp`, `position`, `altitude`, `groundspeed` |
| `adl_flight_tmi` | TMI controls | `gs_status`, `gdp_status`, `reroute_status`, `edct` |
| `adl_flight_aircraft` | Aircraft info | `aircraft_type`, `wake_category`, `equipment_suffix` |
| `adl_flight_changelog` | Audit trail | `changed_field`, `old_value`, `new_value`, `timestamp` |

### 8.3 Backward Compatibility

`vw_adl_flights` — View that joins all normalized tables to match legacy `adl_flights` structure

### 8.4 Reference Tables

| Table | Records | Purpose |
|-------|---------|---------|
| `nav_fixes` | 270K+ | Navigation waypoints (fixes + navaids) |
| `nav_airways` | 1.2K | Airways with sequences |
| `nav_cdrs` | 2.5K | Coded Departure Routes |
| `nav_playbook` | 3K+ | FAA Playbook routes |
| `nav_procedures` | 10K+ | SIDs/STARs with expansions |
| `apts` | — | Airport reference (ICAO, DCC region, coordinates) |
| `ACD_Data` | — | Aircraft characteristics |
| `airlines` | — | Airline information |

### 8.5 Documentation

- `adl/README.md` — ADL implementation guide
- `adl/ARCHITECTURE.md` — Detailed architecture docs
- `adl/DAEMON_SETUP.md` — Daemon configuration
- `adl/NAVDATA_IMPORT.md` — Navigation data import

---

## 9) Stored Procedures

### 9.1 ADL Procedures (`adl/procedures/`)

| Procedure | Purpose |
|-----------|---------|
| `sp_Adl_RefreshFromVatsim_Normalized.sql` | Main refresh (normalized schema) |
| `sp_ParseRoute.sql` | Full GIS route parsing |
| `sp_ParseQueue.sql` | Queue management for async parsing |
| `sp_UpsertFlight.sql` | Flight data ingestion |
| `sp_CalculateETA.sql` | ETA calculation |
| `sp_LogTrajectory.sql` | Trajectory logging |
| `sp_ProcessTrajectoryBatch.sql` | Batch trajectory processing |
| `sp_DetectZoneTransition.sql` | OOOI zone transitions |
| `sp_ProcessZoneDetectionBatch.sql` | Zone batch processing |
| `sp_ImportAirportGeometry.sql` | Airport geometry import |
| `sp_ProcessBoundaryDetectionBatch.sql` | Boundary detection |

### 9.2 Function Procedures

| Function | Purpose |
|----------|---------|
| `fn_GetParseTier.sql` | Route parsing tier assignment |
| `fn_GetTokenType.sql` | Route token classification |
| `fn_GetTierIntervalSeconds.sql` | Tier refresh intervals |
| `fn_GetAircraftPerformance.sql` | Aircraft performance lookup |
| `fn_IsFlightRelevant.sql` | Flight relevance check |
| `fn_GetTrajectoryTier.sql` | Trajectory tier assignment |
| `fn_DetectCurrentZone.sql` | Current OOOI zone detection |

### 9.3 JATOC/NOD Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_jatoc_next_incident_number` | Generate YYMMDD### format |
| `sp_jatoc_next_report_number` | Generate YY-##### format |
| `sp_nod_next_advisory_number` | Generate advisory numbers |

---

## 10) Reference Data and GeoJSON

### 10.1 GeoJSON (`assets/geojson/`)

| File | Size | Description |
|------|------|-------------|
| `artcc.json` | 8.9 MB | ARTCC boundaries |
| `tracon.json` | 7.7 MB | TRACON boundaries |
| `high.json` | 233 KB | High altitude sectors |
| `low.json` | 291 KB | Low altitude sectors |
| `superhigh.json` | 184 KB | Super high sectors |
| `SUA.geojson` | 8.8 MB | Special Use Airspace |
| `SUA_transformed.geojson` | 21 MB | Transformed SUA |

### 10.2 CSV Reference Data (`assets/data/`)

| File | Description |
|------|-------------|
| `points.csv` | Fixes/waypoints |
| `navaids.csv` | Navaids |
| `awys.csv` | Airways |
| `cdrs.csv` | Coded Departure Routes |
| `playbook_routes.csv` | Playbook routes |
| `dp_full_routes.csv` | DP full route strings |
| `star_full_routes.csv` | STAR full route strings |
| `apts.csv` | Airport reference |
| `TierInfo.csv` | Airport tier info |

### 10.3 ARTCC Sector Data (`assets/data/ARTCCs/`)

Individual JSON files per ARTCC (ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX, ZKC, ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZSU, ZTL, ZUA)

---

## 11) Background Jobs and Daemons

### 11.1 Primary Daemon: VATSIM ADL Refresh

**File:** `scripts/vatsim_adl_daemon.php`

**Purpose:** Continuously polls VATSIM API and refreshes flight data

**Configuration:**
- Refresh interval: ~15 seconds
- Target: 3,000-6,000 flights per refresh
- Calls: `sp_Adl_RefreshFromVatsim_Normalized`
- Logs to: `adl_run_log` table

**Performance Targets:**
- Normal: < 5 seconds (3,000 flights)
- Peak: < 12 seconds (6,000 flights)

### 11.2 Queue Processor

**File:** `adl/php/parse_queue_daemon.php`

**Purpose:** Processes route parsing queue for async tiered parsing

### 11.3 VATSIM Ingest Daemon

**File:** `adl/php/vatsim_ingest_daemon.php`

**Purpose:** Alternative VATSIM ingestion method

### 11.4 Boundary Refresh

**File:** `scripts/refresh_vatsim_boundaries.php`

**Purpose:** Updates ARTCC/TRACON GeoJSON from VATSIM sources

### 11.5 Playbook Updater

**File:** `scripts/update_playbook_routes.py`

**Purpose:** Scrapes FAA Playbook and updates route CSV

### 11.6 StatSim Scraper

**File:** `scripts/statsim_scraper.js`

**Purpose:** Puppeteer-based StatSim event traffic scraper

### 11.7 Reference Data Importers

**Location:** `adl/reference_data/`

| Script | Purpose |
|--------|---------|
| `import_all.php` | Master import |
| `import_nav_fixes.php` | Points + navaids (270K) |
| `import_airways.php` | Airways (1.2K) |
| `import_cdrs.php` | CDRs (2.5K) |
| `import_playbook.php` | Playbook routes (3K) |
| `import_procedures.php` | DPs/STARs (10K+) |

---

## 12) Database Migrations

### 12.1 Azure SQL Migrations (`adl/migrations/`)

Organized into feature subdirectories:

| Subdirectory | Description |
|--------------|-------------|
| `core/` | Core ADL normalized schema (001-006) |
| `eta/` | ETA trajectory system (001-011) |
| `oooi/` | OOOI zone detection (001-008) |
| `weather/` | Weather alerts & impact (001-004) |
| `performance/` | Aircraft performance (001-003) |
| `navdata/` | Navigation data (001-005) |
| `boundaries/` | Boundary detection & import (001-007) |
| `cifp/` | CIFP procedure import (001-002) |
| `stats/` | Flight statistics (001-011) |

**Key Deployment Scripts:**
- `eta/009_deploy_eta_trajectory_system.sql`
- `eta/008_eta_trajectory_deploy.sql`
- `oooi/002_oooi_deploy.sql`

### 12.2 MySQL Migrations (`database/migrations/`)

Organized into feature subdirectories:

| Subdirectory | Description |
|--------------|-------------|
| `reroute/` | Reroute tables (001-002) |
| `gdp/` | GDP tables (001-002) |
| `jatoc/` | JATOC tables (001-003) |
| `advisories/` | DCC/NOD advisories (001-003) |
| `initiatives/` | Initiative timeline (001-004) |
| `sua/` | SUA activations (001-003) |
| `integration/` | External integrations (001-003) |
| `schema/` | General schema changes (001-005) |

---

## 13) Ground Stop (GS) Subsystem

### 13.1 Purpose

Manages Ground Stop programs where departures to a specific airport are held on the ground.

### 13.2 Key Tables

- `tmi_ground_stops` (MySQL) — GS definitions
- `adl_flights_gs` (Azure SQL) — Simulation sandbox
- `adl_tmi_state` — Active TMI flags

### 13.3 Call Graph

```
tmi.php → assets/js/tmi.js
  ├─ POST api/tmi/gs_simulate.php — seed sandbox
  ├─ POST api/tmi/gs_preview.php — calculate delays
  ├─ POST api/tmi/gs_apply.php — apply to live
  └─ POST api/tmi/gs_purge_local.php — clear sandbox
```

---

## 14) GDP / GDT Subsystem

### 14.1 Purpose

Ground Delay Programs meter arrivals via slot allocation. GDT provides FSM-style interface.

### 14.2 Key Tables

- `adl_flights_gdp` — GDP simulation sandbox
- `adl_slots_gdp` — Slot allocations (15-min bins)
- `gdp_log` — Program log

### 14.3 Call Graph

```
gdt.php → assets/js/gdt.js
  ├─ POST api/tmi/gdp_simulate.php — seed sandbox
  ├─ POST api/tmi/gdp_preview.php — calculate slots/delays
  ├─ POST api/tmi/gdp_apply.php — apply program
  └─ POST api/tmi/gdp_purge.php — end program
```

### 14.4 GDT Features

- FSM Chapter 5 styling
- Program types: GDP-DAS, GDP-GAAP, GDP-UDP
- Scope filtering by ARTCC/tier/distance
- Rate table with fill options
- Flight list and slots list modals
- CSV export

---

## 15) Reroute Subsystem

### 15.1 Purpose

Manages traffic reroutes with compliance tracking and route advisory generation.

### 15.2 Key Tables

- `tmi_reroutes` — Reroute definitions
- `tmi_reroute_flights` — Flight assignments
- `tmi_reroute_compliance_log` — Compliance history

### 15.3 Call Graph

```
reroutes.php → assets/js/reroute.js
  ├─ GET api/data/tmi/reroutes.php — list reroutes
  ├─ POST api/mgt/tmi/reroutes/post.php — create
  ├─ PUT api/mgt/tmi/reroutes/activate.php — activate
  ├─ POST api/tmi/rr_assign.php — assign flights
  └─ GET api/tmi/rr_compliance_history.php — history
```

---

## 16) Splits Subsystem

### 16.1 Purpose

Manages airspace sector split configurations with visual mapping and preset support.

### 16.2 Key Tables

- `splits_areas` — Reusable sector groupings
- `splits_configs` — Split configurations
- `splits_positions` — Position assignments
- `splits_presets` — Saved presets

### 16.3 Call Graph

```
splits.php → assets/js/splits.js
  ├─ GET api/splits/configs.php — list configs
  ├─ GET api/splits/areas.php — list areas
  ├─ GET api/splits/sectors.php — sector boundaries
  ├─ GET api/splits/presets.php — list presets
  └─ GET api/splits/active.php — active config
```

---

## 17) Live Flights TSD / route.php

### 17.1 Purpose

TSD-style live flight visualization with route plotting, filtering, and MapLibre support.

### 17.2 Key Features

- Dual rendering: Leaflet (legacy) / MapLibre GL (preferred)
- TSD-style aircraft symbology
- Route plotting with procedure expansion
- Playbook/CDR search
- Public route overlay
- Weather radar integration
- Advisory builder integration

### 17.3 Call Graph

```
route.php → assets/js/route-maplibre.js
  ├─ GET api/adl/current.php — live flights
  ├─ GET api/adl/flight.php — single flight
  ├─ GET api/adl/waypoints.php — parsed waypoints
  ├─ GET api/data/routes.php — route lookup
  └─ GET api/data/fixes.php — fix lookup
```

---

## 18) ADL History and Tracks

### 18.1 Tables

- `adl_flights_history` — Hot tier (recent)
- `adl_flights_history_cool` — Cool tier
- `adl_flights_history_cold` — Cold tier (archive)
- `adl_flight_trajectory` — Position snapshots
- `vw_adl_flights_history_30d` — 30-day view

### 18.2 Stored Procedures

- `sp_Adl_ArchiveHistory` — Tier migration
- `sp_Adl_GetFlightTrack` — Track retrieval
- `sp_LogTrajectory` — Trajectory logging

---

## 19) Public Routes Subsystem

### 19.1 Purpose

Globally shared route advisories with GeoJSON caching for map display.

### 19.2 Key Table

`public_routes` — Route definitions with `route_geojson` column for pre-computed geometry

### 19.3 APIs

- `api/routes/public.php` — GET list
- `api/routes/public_post.php` — POST create
- `api/routes/public_update.php` — PUT update
- `api/routes/public_delete.php` — DELETE remove

---

## 20) JATOC Subsystem

### 20.1 Purpose

AWO (Airway Operations) incident monitoring system tracking facility outages, alerts, and operational limitations.

### 20.2 Key Features

- Incident statuses: ATC_ZERO, ATC_ALERT, ATC_LIMITED, NON_RESPONSIVE
- Operations level management (1/2/3)
- MapLibre map with incident visualization
- Personnel roster
- POTUS/Space calendar integration

### 20.3 Key Tables

- `jatoc_incidents` — AWO incidents
- `jatoc_incident_updates` — Update history
- `jatoc_personnel` — Position roster
- `jatoc_ops_level` — Operations level
- `jatoc_sequences` — Number generators
- `jatoc_reports` — Closed incident reports

### 20.4 Call Graph

```
jatoc.php → assets/js/jatoc.js
  ├─ GET api/jatoc/incidents.php — list incidents
  ├─ GET/POST/PUT/DELETE api/jatoc/incident.php — CRUD
  ├─ GET/PUT api/jatoc/oplevel.php — operations level
  └─ GET/PUT api/jatoc/personnel.php — roster
```

---

## 21) NOD (NAS Operations Dashboard)

### 21.1 Purpose

Consolidated monitoring dashboard providing real-time overview of NAS status.

### 21.2 Key Features

**Map Display:**
- Live traffic visualization (TSD-style)
- Public reroutes overlay
- Active splits visualization
- JATOC incident markers
- Layer controls: ARTCC, TRACON, sectors, weather

**Right Panel:**
- **TMI Tab:** Ground Stops, GDPs, Reroutes, Public Routes, Discord TMIs
- **Advisories Tab:** FAA-style advisory database
- **JATOC Tab:** Active incidents summary

**Stats Bar:**
- Flight counts, TMI breakdown, active routes/advisories/incidents

### 21.3 Key Tables

- `dcc_advisories` — Advisory storage
- `dcc_advisory_sequences` — Daily advisory numbers
- `dcc_discord_tmi` — Discord TMI integration

### 21.4 Call Graph

```
nod.php → assets/js/nod.js
  ├─ GET api/nod/tmi_active.php — consolidated TMIs
  ├─ GET/POST/PUT/DELETE api/nod/advisories.php — advisory CRUD
  ├─ GET api/nod/jatoc.php — JATOC summary
  ├─ GET api/adl/current.php — live traffic
  └─ GET api/routes/public.php — public routes
```

---

## 22) Initiative Timeline Subsystem

### 22.1 Purpose

Interactive Gantt-style timeline visualization for Terminal and En Route initiatives.

### 22.2 Key Files

- `assets/js/initiative_timeline.js` (~1,281 lines)
- `assets/css/initiative_timeline.css`
- `api/data/plans/term_inits_timeline.php`
- `api/data/plans/enroute_inits_timeline.php`

### 22.3 Features

- Gantt-style horizontal timeline (16-hour default)
- Real-time "now" line indicator
- Color-coded entries by level
- Facility-based row grouping
- Modal-based CRUD

### 22.4 Level Categories

| Level | Category |
|-------|----------|
| CDW | Critical Decision Window |
| Possible, Probable, Expected, Active | TMI |
| Constraint_Terminal, Constraint_EnRoute | Constraint |
| Special_Event | Event |
| Space_Op | Space |
| VIP | VIP Movement |
| Staffing | Staffing |
| Misc | Miscellaneous |

### 22.5 TMI Types

GS, GDP, MIT, MINIT, CFR, APREQ, Reroute, AFP, FEA, FCA, CTOP, ICR, TBO, Metering, TBM, TBFM, Other

---

## 23) Weather Integration (NEW v13)

### 23.1 Purpose

Real-time weather display and flight impact analysis.

### 23.2 Data Sources

- **SIGMETs** — Significant meteorological information
- **AIRMETs** — Airmen's meteorological information
- **TFRs** — Temporary Flight Restrictions
- **Convective Weather** — Thunderstorm data
- **Radar Imagery** — Iowa Environmental Mesonet

### 23.3 Key Tables

- `weather_alerts` — SIGMET/AIRMET with polygon boundaries
- `weather_impact_log` — Flight impact tracking

### 23.4 Key Files

- `assets/js/weather_radar.js` (~1,034 lines)
- `assets/js/weather_radar_integration.js` (~419 lines)
- `assets/js/weather_hazards.js`
- `assets/js/weather_impact.js`
- `assets/css/weather_radar.css`
- `assets/css/weather_hazards.css`
- `assets/css/weather_impact.css`
- `adl/php/import_weather_alerts.php`

### 23.5 Features

- Real-time radar overlay on TSD
- Flight-to-weather proximity detection
- Weather impact logging
- Color-coded flight display
- Historical weather playback

### 23.6 Migrations

- 044: Weather alerts schema
- 045-048: Impact detection and enhancements

---

## 24) OOOI Zone Detection (NEW v13)

### 24.1 Purpose

Track aircraft ground phase: OUT (left gate), OFF (airborne), ON (landed), IN (at gate).

### 24.2 Current Status: ✅ OPERATIONAL (V3)

| Metric | Value |
|--------|-------|
| Complete OOOI cycles | 195+ |
| IN capture rate | 85%+ |
| Zone events logged | 37,000+ |
| Airports with geometry | 203 |
| OSM zones | 46,124 |

### 24.3 Key Tables

| Table | Purpose |
|-------|--------|
| `airport_geometry` | Airport zone boundaries (OSM-based) |
| `adl_zone_events` | Zone transition events |
| `adl_flight_times` | Extended zone timestamps |

### 24.4 Key Procedures

| Procedure | Purpose |
|-----------|--------|
| `fn_DetectCurrentZone` | Current zone detection |
| `sp_ProcessZoneDetectionBatch` | Batch processing (V3) |
| `sp_GenerateFallbackZones` | Fallback zone creation |
| `sp_ImportAirportGeometry` | OSM geometry import |

### 24.5 Zones

| Zone | Description |
|------|-------------|
| PARKING | At gate/ramp |
| GATE | Gate position |
| APRON | Apron area |
| TAXIWAY | Taxiway |
| TAXILANE | Taxilane |
| HOLD | Holding position |
| RUNWAY | On runway |
| AIRBORNE | In flight |

### 24.6 OOOI Times Captured

**Core Times:**
- `out_utc` — Left parking/gate
- `off_utc` — Became airborne (GS > 60 kts)
- `on_utc` — Touched down (GS < 200 kts)
- `in_utc` — Arrived at gate (GS < 5 kts)

**Extended Departure Times:**
- `parking_left_utc`, `taxiway_entered_utc`, `hold_entered_utc`
- `runway_entered_utc`, `takeoff_roll_utc`, `rotation_utc`

**Extended Arrival Times:**
- `approach_start_utc`, `touchdown_utc`, `rollout_end_utc`
- `taxiway_arr_utc`, `parking_entered_utc`

### 24.7 Version History

| Version | Detection Method | Key Features |
|---------|-----------------|---------------|
| V1 | BATCH | Basic zone detection |
| V2 | BATCH_V2 | Extended times, better OFF detection |
| **V3** | **BATCH_V3** | **Inactive flight catchup, 85%+ IN capture** |

### 24.8 Migrations

| Migration | Description |
|-----------|-------------|
| 040 | Airport geometry tables |
| 041 | Zone detection deployment |
| 042 | V2 batch processor |
| 043 | V3 batch processor (current) |

### 24.9 Import Scripts

- `adl/php/ImportOSM.ps1` — PowerShell OSM importer (RECOMMENDED)
- `adl/php/import_osm_airport_geometry.php` — PHP version

### 24.10 Documentation

- `adl/OOOI_Zone_Detection_Transition_Summary.md` — Complete transition summary
- `adl/migrations/oooi/008_oooi_verify_v2.sql` — Verification queries

---

## 25) Boundary Detection (NEW v13)

### 25.1 Purpose

Track which ARTCC/sector a flight is in and log boundary crossings.

### 25.2 Key Tables

- `boundary_crossing_log` — Boundary crossings

### 25.3 Key Procedures

- `sp_ProcessBoundaryDetectionBatch` — Batch processing

### 25.4 Key Files

- `adl/php/import_boundaries.php` — Boundary integration

### 25.5 Migrations

- 049-052: Boundary detection schema and procedures

---

## 26) ETA Trajectory System (NEW v13)

### 26.1 Purpose

Predict aircraft arrival times and track trajectories.

### 26.2 Key Tables

- `adl_flight_trajectory` — Position history (15-sec intervals)
- `eta_citypair_lookup` — City pair ETA lookup

### 26.3 Key Procedures

- `sp_CalculateETA` — ETA calculation
- `sp_LogTrajectory` — Trajectory logging
- `sp_ProcessTrajectoryBatch` — Batch processing
- `fn_GetAircraftPerformance` — Performance lookup
- `fn_GetTrajectoryTier` — Tier assignment

### 26.4 Features

- Aircraft performance profile lookup
- Distance/time calculations
- Trajectory batch processing
- ETA accuracy tracking

### 26.5 Migrations

- 030-031: ETA trajectory system

---

## 27) Route Parsing & GIS (NEW v13)

### 27.1 Purpose

Parse route strings into GIS geometries with waypoint extraction.

### 27.2 Token Types

| Type | Examples |
|------|----------|
| AIRPORT | KJFK, KLAX |
| FIX | MERIT, DIXIE |
| NAVAID | VOR, NDB |
| AIRWAY | J60, Q104 |
| SID/STAR | RNAV1, ILS |
| RADIAL | DCA090030 |
| LAT/LON | 4000N/07500W |

### 27.3 Key Procedures

- `sp_ParseRoute.sql` — Full GIS route parsing
- `sp_ParseQueue.sql` — Async queue management
- `fn_GetTokenType.sql` — Token classification
- `fn_GetParseTier.sql` — Tier assignment

### 27.4 GIS Features

- SQL Server GEOGRAPHY type
- Spatial indexes on position/route geometry
- Polygon containment queries
- Intersection and distance calculations

### 27.5 Output

- `adl_flight_plan.route_geometry` — LINESTRING geometry
- `adl_flight_waypoints` — Extracted waypoints with coordinates

---

## 28) Advisory Builder (NEW v13)

### 28.1 Purpose

TFMS-style route advisory generation and export.

### 28.2 Key Files

- `advisory-builder.php` — Main page
- `assets/js/advisory-builder.js` — JavaScript module

### 28.3 Features

- Multi-route plotting
- TFMS-style advisory text generation
- GeoJSON/KML/GeoPackage export
- Advisory versioning
- Discord posting integration

---

## 29) Demand Visualization (NEW v13)

### 29.1 Purpose

Traffic demand analysis and forecasting.

### 29.2 Key Files

- `demand.php` — Main page
- `assets/js/demand.js` — JavaScript module

### 29.3 APIs

- `api/demand/airport.php` — Airport demand
- `api/demand/system.php` — System overview
- `api/demand/flights.php` — Flight drill-down

### 29.4 Features

- Airport arrival/departure counts
- Time-based demand graphs
- Forecast vs actual comparison

---

## 30) ADL Refresh Patterns

### 30.1 Purpose

Double-buffering pattern to prevent UI "flashing" during periodic refreshes.

### 30.2 Key Files

- `assets/js/adl-service.js` (~579 lines) — Centralized data management
- `assets/js/adl-refresh-utils.js` (~580 lines) — Buffered refresh utilities
- `docs/ADL_REFRESH_MIGRATION_GUIDE.md` — Documentation

### 30.3 ADL Service Usage

```javascript
// Subscribe to updates
ADLService.subscribe('my-component', (data) => {
    renderTable(data.flights);
});

// Start auto-refresh
ADLService.startAutoRefresh(15000);

// Get current data (never null after first load)
const flights = ADLService.getFlights();
```

### 30.4 Pattern Principles

1. Never clear before replacement data arrives
2. Build complete content before swapping
3. Keep old state during API calls
4. Fail gracefully (keep displaying old data on error)

### 30.5 CSS Classes

- `.adl-refreshing` — Applied during fetch
- `.adl-stale-data` — Applied when data might be outdated

---

## 31) StatSim Integration

### 31.1 Purpose

Integration with SimTraffic/StatSim for event traffic data.

### 31.2 APIs

- `api/statsim/fetch.php` — Fetch StatSim data
- `api/statsim/plan_info.php` — Plan/route information
- `api/statsim/save_rates.php` — Save computed rates

### 31.3 Files

- `assets/js/statsim_rates.js` (~1,187 lines) — FSM-style demand graphs
- `scripts/statsim_scraper.js` — Puppeteer-based scraper

---

## 32) NASR Navigation Data Updater

### 32.1 Purpose

Automates updates from FAA NASR 28-day subscription packages.

### 32.2 Features

- Automatic AIRAC cycle detection
- Downloads from FAA NFDC
- Parses: Fixes, Navaids, Airports, Airways, CDRs, DPs, STARs
- Merges with existing data (preserves custom entries)
- Creates backups before updates

### 32.3 Output Files

- `assets/data/points.csv`, `navaids.csv`, `awys.csv`, `cdrs.csv`
- `assets/data/dp_full_routes.csv`, `star_full_routes.csv`
- `assets/js/awys.js`, `assets/js/procs.js`

---

## 33) External Data Sources

### 33.1 VATSIM

- **VATSIM API** (`data.vatsim.net/v3/`) — Live pilots, flight plans, controllers
- **VATSIM Connect** — OAuth authentication
- **VATSIM Boundaries** — ARTCC/TRACON polygons

### 33.2 FAA

- **NASR Data** — Navigation fixes, airways, procedures
- **Playbook Routes** — Published routes
- **CDRs** — Coded Departure Routes

### 33.3 Weather

- **Aviation Weather Center** — SIGMETs, AIRMETs, TFRs
- **Iowa Environmental Mesonet** — Radar imagery

### 33.4 Other

- **OpenStreetMap** — Airport geometry
- **StatSim** — Event traffic data
- **Discord API** — Bot integration

---

## 34) Practical Gotchas

1. **Azure SQL Serverless scaling** — 50+ second delays after idle period
2. **Route plotting performance** — Target sub-5-second for single routes
3. **VATSIM API rate limits** — Daemon uses 15-second intervals
4. **GeoJSON pre-computation** — Compute at save time, not render time
5. **UTF-8 encoding** — Use proper encoding when reading CSVs
6. **MapLibre vs Leaflet** — Check `localStorage.useMapLibre` for feature flag
7. **JATOC and NOD are public** — No auth for viewing, DCC role for editing
8. **ADL refresh timing** — Stored procedures must complete within 12 seconds
9. **Double-buffering** — Use ADL refresh patterns to prevent UI flashing
10. **Initiative Timeline UTC** — All datetime values in UTC
11. **Normalized schema** — Use `vw_adl_flights` view for legacy compatibility
12. **Tiered parsing** — Routes parsed async based on operational relevance

---

## 35) Search Anchors

| Pattern | Finds |
|---------|-------|
| `sp_Adl_` | ADL stored procedures |
| `sp_jatoc_` | JATOC stored procedures |
| `sp_nod_` | NOD stored procedures |
| `fn_` | SQL functions |
| `dbo.adl_flight_` | Normalized flight tables |
| `dbo.adl_flights` | Legacy flight table/view |
| `dbo.nav_` | Navigation reference tables |
| `dbo.jatoc_` | JATOC tables |
| `dbo.dcc_` | NOD/DCC tables |
| `conn_adl` | Azure SQL connection usage |
| `api/adl/` | ADL API endpoints |
| `api/jatoc/` | JATOC API endpoints |
| `api/nod/` | NOD API endpoints |
| `api/tmi/` | TMI API endpoints |
| `api/demand/` | Demand API endpoints |
| `MapLibre` or `maplibre` | MapLibre-specific code |
| `ADLService` | Centralized ADL data service |
| `ADLRefreshUtils` | Buffered refresh utilities |
| `InitiativeTimeline` | Initiative timeline class |
| `weather_` | Weather-related files |
| `boundary_` | Boundary detection |
| `zone_` | OOOI zone detection |
| `trajectory` | ETA trajectory |

---

## 36) VATSIM_ADL Azure SQL Database Reference

### Core Flight Tables (Normalized Schema)

| Table | Description |
|-------|-------------|
| `adl_flight_core` | Master flight registry |
| `adl_flight_position` | Real-time position |
| `adl_flight_plan` | Flight plan with GIS geometry |
| `adl_flight_waypoints` | Parsed route waypoints |
| `adl_flight_stepclimbs` | Step climb records |
| `adl_flight_times` | TFMS time fields |
| `adl_flight_trajectory` | Position history |
| `adl_flight_tmi` | TMI controls |
| `adl_flight_aircraft` | Aircraft info |
| `adl_flight_changelog` | Audit trail |

### Legacy/Compatibility

| Table/View | Description |
|------------|-------------|
| `adl_flights` | Legacy monolithic table |
| `vw_adl_flights` | Backward-compatible view |

### TMI Tables

| Table | Description |
|-------|-------------|
| `adl_flights_gdp` | GDP simulation sandbox |
| `adl_flights_gs` | GS simulation sandbox |
| `adl_slots_gdp` | GDP slot allocations |
| `adl_edct_overrides` | EDCT overrides |
| `gdp_log` | GDP program log |
| `adl_tmi_state` | Active TMI flags |
| `tmi_reroutes` | Reroute definitions |
| `tmi_reroute_flights` | Flight assignments |
| `tmi_reroute_compliance_log` | Compliance history |

### Reference Tables

| Table | Description |
|-------|-------------|
| `nav_fixes` | Navigation waypoints (270K+) |
| `nav_airways` | Airways |
| `nav_cdrs` | Coded Departure Routes |
| `nav_playbook` | Playbook routes |
| `nav_procedures` | SIDs/STARs |
| `apts` | Airport reference |
| `ACD_Data` | Aircraft characteristics |
| `airlines` | Airline information |
| `eta_citypair_lookup` | City pair ETA lookup |
| `tracons` | TRACON reference |

### Splits Tables

| Table | Description |
|-------|-------------|
| `splits_areas` | Reusable sector groupings |
| `splits_configs` | Split configurations |
| `splits_positions` | Position assignments |
| `splits_presets` | Saved presets |
| `splits_preset_positions` | Preset position mappings |

### JATOC Tables

| Table | Description |
|-------|-------------|
| `jatoc_incidents` | AWO incidents |
| `jatoc_incident_updates` | Update history |
| `jatoc_personnel` | Position roster |
| `jatoc_ops_level` | Operations level |
| `jatoc_daily_ops` | Daily ops calendar |
| `jatoc_special_emphasis` | Special emphasis items |
| `jatoc_sequences` | Number sequences |
| `jatoc_reports` | Incident reports |

### NOD/Advisory Tables

| Table | Description |
|-------|-------------|
| `dcc_advisories` | Advisory storage |
| `dcc_advisory_sequences` | Daily sequences |
| `dcc_discord_tmi` | Discord integration |
| `public_routes` | Public route advisories |

### Weather & Boundary Tables

| Table | Description |
|-------|-------------|
| `weather_alerts` | SIGMET/AIRMET with polygons |
| `weather_impact_log` | Flight impact tracking |
| `airport_geometry` | Airport zone boundaries |
| `adl_zone_events` | OOOI zone events |
| `boundary_crossing_log` | Boundary crossings |

### History Tables

| Table | Description |
|-------|-------------|
| `adl_flights_history` | Hot tier (recent) |
| `adl_flights_history_cool` | Cool tier |
| `adl_flights_history_cold` | Cold tier (archive) |

### Views

| View | Description |
|------|-------------|
| `vw_adl_flights` | Backward-compatible flight view |
| `vw_adl_flights_history_30d` | 30-day history |
| `vw_dcc_advisories_today` | Today's advisories |
| `vw_jatoc_active_incidents` | Active incidents |

### Utility Tables

| Table | Description |
|-------|-------------|
| `adl_run_log` | Daemon run log |
| `aircraft_type_lookup` | Aircraft type reference |
| `r_airport_totals` | Airport totals |
| `r_hourly_rates` | Hourly rates |

---

## 37) VATSIM_PERTI MySQL Database Reference

### Planning Tables

| Table | Description |
|-------|-------------|
| `p_plans` | Plans (main table) |
| `p_configs` | Plan configurations |
| `p_dcc_staffing` | DCC staffing |
| `p_forecast` | Traffic forecast |
| `p_historical` | Historical data |
| `p_op_goals` | Operational goals |
| `p_group_flights` | Group flights |

### Terminal Tables

| Table | Description |
|-------|-------------|
| `p_terminal_init` | Terminal initiatives |
| `p_terminal_init_times` | Initiative times |
| `p_terminal_init_timeline` | Timeline entries |
| `p_terminal_staffing` | Staffing |
| `p_terminal_planning` | Planning |
| `p_terminal_constraints` | Constraints |

### Enroute Tables

| Table | Description |
|-------|-------------|
| `p_enroute_init` | Enroute initiatives |
| `p_enroute_init_times` | Initiative times |
| `p_enroute_init_timeline` | Timeline entries |
| `p_enroute_staffing` | Staffing |
| `p_enroute_planning` | Planning |
| `p_enroute_constraints` | Constraints |

### Review Tables

| Table | Description |
|-------|-------------|
| `r_comments` | Review comments |
| `r_data` | Review data |
| `r_ops_data` | Operations data |
| `r_scores` | Review scores |

### User Tables

| Table | Description |
|-------|-------------|
| `users` | User accounts |
| `admin_users` | Admin accounts |
| `assigned` | Assignment data |
| `config_data` | Configuration data |

### TMI Tables

| Table | Description |
|-------|-------------|
| `tmi_ground_stops` | Ground stop definitions |

### Route Cache Tables

| Table | Description |
|-------|-------------|
| `route_cdr` | CDR route cache |
| `route_playbook` | Playbook route cache |

---

## 38) Flight Statistics System (NEW v13)

### 38.1 Purpose

Comprehensive flight statistics aggregation system for:
- Taxi time analysis (using OOOI zone data)
- Summary statistics (daily/hourly aggregates)
- Demand statistics (airport throughput)
- TMI decision support (impact analysis)
- Operational counts (ARTCC, aircraft type, route patterns)

### 38.2 Statistics Tables

| Table | Purpose | Retention |
|-------|---------|-----------|
| `flight_stats_daily` | Daily network summary | 180 days |
| `flight_stats_hourly` | Hourly time-series | 30 days |
| `flight_stats_airport` | Airport taxi times & throughput | 180 days |
| `flight_stats_citypair` | Route analytics | 180 days |
| `flight_stats_artcc` | ARTCC traffic volumes | 180 days |
| `flight_stats_tmi` | TMI impact analysis | 180 days |
| `flight_stats_aircraft` | Equipment usage | 180 days |
| `flight_stats_monthly_summary` | Monthly rollups | 2 years |
| `flight_stats_monthly_airport` | Monthly airport stats | 2 years |
| `flight_stats_yearly_summary` | Yearly summaries | Indefinite |

### 38.3 Tiered Retention

| Tier | Data Type | Retention | Purpose |
|------|-----------|-----------|---------|
| 0 | Hourly | 30 days | Recent trend analysis |
| 1 | Daily | 180 days | Operational analysis |
| 2 | Monthly | 2 years | Seasonal patterns |
| 3 | Yearly | Indefinite | Long-term trends |

### 38.4 Stored Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_GenerateFlightStats_Hourly` | Aggregate hourly stats |
| `sp_GenerateFlightStats_Daily` | Aggregate daily stats |
| `sp_GenerateFlightStats_Airport` | Airport taxi time stats |
| `sp_GenerateFlightStats_Citypair` | Route analytics |
| `sp_GenerateFlightStats_Aircraft` | Equipment stats |
| `sp_GenerateFlightStats_TMI` | TMI impact stats |
| `sp_CleanupFlightStats` | Apply retention policy |
| `sp_RollupFlightStats_Monthly` | Monthly rollups |
| `sp_ProcessFlightStatsJobs` | Daemon job processor |

### 38.5 API Endpoints (`api/stats/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `daily.php` | GET | Daily summary stats |
| `hourly.php` | GET | Hourly time-series |
| `airport.php` | GET | Airport taxi times |
| `citypair.php` | GET | Route analytics |
| `artcc.php` | GET | ARTCC traffic |
| `tmi.php` | GET | TMI impact |
| `realtime.php` | GET | Live stats (current state) |
| `status.php` | GET | Job status & health |

### 38.6 Migrations

| Migration | Description |
|-----------|-------------|
| 070 | Statistics tables + retention tiers |
| 071 | Aggregation stored procedures |
| 072 | Job configuration + scheduling |

### 38.7 Scheduling

Jobs configured in `flight_stats_job_config` table:
- **Hourly**: Every hour at :05 (sp_GenerateFlightStats_Hourly)
- **Daily**: 00:15 UTC (sp_GenerateFlightStats_Daily)
- **Monthly**: 1st of month at 01:30 UTC (sp_RollupFlightStats_Monthly)
- **Cleanup**: Daily at 03:45 UTC (sp_CleanupFlightStats)

For Azure SQL, call `sp_ProcessFlightStatsJobs` from PHP daemon or use Azure Elastic Jobs.

---

## 39) Changelog

### v14 (2026-01-06)
- **Flight Statistics System (#38):**
  - 10 statistics tables with tiered retention
  - Aggregation procedures for taxi times, demand, TMI impact
  - API endpoints (api/stats/*)
  - Job scheduling infrastructure
  - Migrations 070-072

### v13 (2026-01-06)
- **ADL Database Redesign Documentation:**
  - Comprehensive coverage of normalized schema (10+ flight tables)
  - Reference tables documentation (nav_fixes, nav_airways, nav_cdrs, etc.)
  - GIS capabilities and spatial indexing
  - Tiered async parsing system

- **New Subsystem Sections:**
  - Weather Integration (#23) — weather_radar.js, weather_hazards.js, alerts
  - OOOI Zone Detection (#24) — airport geometry, zone events
  - Boundary Detection (#25) — crossing logs, batch processing
  - ETA Trajectory System (#26) — trajectory logging, ETA calculation
  - Route Parsing & GIS (#27) — sp_ParseRoute, token types
  - Advisory Builder (#28) — advisory-builder.php/.js
  - Demand Visualization (#29) — demand.php, api/demand/*

- **Stored Procedures Section (#9):**
  - Complete listing of ADL procedures
  - Function procedures documentation
  - JATOC/NOD procedures

- **Updated API Endpoints (#7):**
  - Added api/adl/waypoints.php, boundaries.php
  - Added api/demand/* endpoints
  - Added api/weather/* endpoints
  - Expanded api/data/* coverage

- **Background Jobs (#11):**
  - parse_queue_daemon.php
  - vatsim_ingest_daemon.php
  - Reference data import pipeline (adl/reference_data/*)

- **Database Migrations (#12):**
  - Azure SQL migrations 001-061
  - OOOI migrations (040-043)
  - Weather migrations (044-048)
  - Boundary migrations (049-052)
  - CIFP migrations (060-061)

- **External Data Sources (#33):**
  - Complete documentation of VATSIM, FAA, weather, and other integrations

- **Azure SQL Database Reference (#36):**
  - Normalized flight tables
  - Reference tables (nav_*)
  - Weather & boundary tables
  - Zone detection tables

### v12 (2026-01-03)
- Initiative Timeline Subsystem
- Database migrations 014-016
- Initiative timeline APIs
- Updated VATSIM_ADL and VATSIM_PERTI tree references

### v11 (2026-01-03)
- NOD Subsystem (complete)
- ADL Refresh Patterns (double-buffering)
- GDT Page (standalone Ground Delay Tool)
- DCC/NOD advisory tables

### v10 (2025-12-30)
- JATOC Subsystem (complete)
- StatSim Integration
- NASR Navigation Data Updater

### v9 (2025-12-28)
- Public Routes Subsystem
- Route Symbology Module
- Playbook/CDR Search Module
- Splits presets and TRACON support

### v8 (2025-12-23)
- MapLibre Migration Complete
- TSD-style aircraft symbology
- Advisory Builder
- VATSIM Boundary Refresh Script

---

## Code Statistics

| Metric | Count |
|--------|-------|
| PHP API files | 100+ |
| JavaScript modules | 35+ |
| CSS stylesheets | 10+ |
| SQL migrations | 60+ |
| Stored procedures | 20+ |
| Azure SQL tables | 60+ |
| MySQL tables | 30+ |
| API endpoints | 120+ |
| Total PHP lines | 25K+ |
| GeoJSON files | 7+ (50+ MB) |
| CSV reference data | 10 files |
| Documentation files | 50+ |
