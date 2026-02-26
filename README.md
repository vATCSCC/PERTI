# vATCSCC PERTI - Virtual Air Traffic Control System Command Center

## Overview

PERTI (Plan, Execute, Review, Train, and Improve) is a comprehensive web-based traffic flow management platform for VATSIM (Virtual Air Traffic Control Simulation). It provides professional-grade tools for virtual air traffic controllers to manage traffic flow, monitor incidents, and coordinate operations.

**Production URL:** https://perti.vatcscc.org

---

## 🗺️ Site Map & Functionality

### Public Pages

| Page | URL | Description |
|------|-----|-------------|
| **Home** | `/` | Landing page and dashboard |
| **JATOC** | `/jatoc.php` | AWO Incident Monitor (no login required) |
| **NOD** | `/nod.php` | NAS Operations Dashboard (no login required) |
| **Privacy Policy** | `/privacy.php` | Privacy policy |

### Traffic Management Tools (Authentication Required)

| Page | URL | Description |
|------|-----|-------------|
| **GDT** | `/gdt.php` | Ground Delay Tool - FSM-style GDP interface |
| **Route Plotter** | `/route.php` | TSD-style live flight map with route plotting & weather radar |
| **Demand** | `/demand.php` | Airport demand analysis with rate suggestions |
| **Reroutes** | `/reroutes.php` | Reroute authoring and monitoring |
| **Splits** | `/splits.php` | Sector/position split configuration with strata filtering |
| **Playbook** | `/playbook.php` | Pre-coordinated route play catalog (FAA/DCC/ECFMP/CANOC) |
| **TMI Publish** | `/tmi-publish.php` | NTML & advisory publishing to Discord |
| **Advisory Builder** | `/advisory-builder.php` | TFMS-style advisory creation |
| **ATFM Simulator** | `/simulator.php` | TMU training simulator |

### Configuration & Administration (Authentication Required)

| Page | URL | Description |
|------|-----|-------------|
| **Airport Configs** | `/airport_config.php` | Runway configurations and rate management |
| **System Status** | `/status.php` | System health dashboard and diagnostics |

### Planning & Scheduling (Authentication Required)

| Page | URL | Description |
|------|-----|-------------|
| **Plan** | `/plan.php` | Traffic management planning worksheets with initiative timeline |
| **Schedule** | `/schedule.php` | Staff scheduling |
| **Data Sheet** | `/data.php` or `/sheet.php` | Operational data sheets |
| **Review** | `/review.php` | Plan review and comments with StatSim integration |

---

## 🔧 Key Features

### JATOC - Joint Air Traffic Operations Command
*AWO Incident Monitor* - Publicly accessible at `/jatoc.php`

- **Incident Tracking:** Monitor ATC Zero, ATC Alert, ATC Limited, and Non-Responsive incidents
- **Operations Level:** Real-time 1/2/3 status with color-coded display
- **Map Visualization:** Interactive MapLibre map with ARTCC/TRACON boundaries
- **POTUS/Space Calendar:** Track special operations activities
- **Personnel Roster:** JATOC position assignments
- **Incident Search:** Multi-criteria historical search
- **VATUSA Events:** Integrated event display

### NOD - NAS Operations Dashboard
*Consolidated Monitoring* - Publicly accessible at `/nod.php`

- **Active TMIs:** Real-time ground stops, GDPs, reroutes with rich data cards
- **Facility Flows:** Per-facility flow configurations with FEA demand integration
- **Advisory Management:** DCC advisory creation and tracking
- **Map TMI Layer:** Airport rings by severity, delay glow circles, MIT fix markers
- **Weather Integration:** Radar overlay support
- **Discord Sync:** TMI synchronization with Discord channels

### GDT - Ground Delay Tool
*FSM-Style GDP Interface* - at `/gdt.php`

- **Rate Visualization:** FSM-style demand/capacity bar graphs
- **EDCT/CTA Allocation:** Controlled arrival time slot management
- **Program Parameters:** Scope, rate, duration configuration
- **Flight List:** Real-time compliance status
- **Preview/Simulate:** Impact analysis before application

### Route Plotter (TSD)
*Live Flight Visualization* - at `/route.php`

- **Live Flights:** Real-time VATSIM flight display with TSD symbology
- **Weather Radar:** IEM NEXRAD/MRMS overlay with multiple color tables
- **Route Plotting:** Multi-route plotting with DP/STAR resolution
- **Public Routes:** Globally shared route advisories
- **Advisory Builder:** Generate TFMS-style route advisories
- **Export:** GeoJSON, KML, GeoPackage export formats
- **Playbook/CDR Search:** FAA playbook and CDR route lookup
- **SUA/TFR Display:** Special Use Airspace and TFR boundaries

### Reroutes
*Reroute Management* - at `/reroutes.php`

- **Reroute Authoring:** Create reroute definitions with constraints
- **Flight Matching:** Preview and assign affected flights
- **Compliance Tracking:** Monitor route compliance status
- **Export:** CSV/JSON export of assignments

### Splits
*Sector Configuration* - at `/splits.php`

- **Area Management:** Define and save sector groupings
- **Configuration Presets:** Reusable split configurations
- **Active Splits:** Real-time position assignments
- **Scheduled Splits:** Auto-activate/deactivate at specified UTC times
- **Map Visualization:** MapLibre-based sector display with color coding
- **Strata Filtering:** Low/High/Superhigh sector layers
- **International Coverage:** 23 US ARTCCs and 7 Canadian FIRs (CZYZ, CZWG, CZEG, CZUL, CZVR, CZQM, CZQX)

### Playbook
*Route Play Catalog* - at `/playbook.php`

- **Play Management:** Pre-coordinated route plays organized by scenario
- **Multi-Source:** FAA national playbook, DCC custom plays, ECFMP, CANOC
- **Bulk Paste:** Auto-structured import from ECFMP/CANOC format
- **Shareable Links:** `?play=PLAY_NAME` URL parameter for direct access
- **Route Visualization:** Integrated map display of play routes

### Plan
*Planning Worksheets* - at `/plan.php`

- **Initiative Timeline:** Interactive Gantt-style TMI visualization
- **Terminal/Enroute Planning:** Separate worksheets for different airspace
- **Constraints Management:** Track operational constraints
- **Group Flights:** Coordinate large traffic events
- **StatSim Integration:** Historical rate comparison

---

## 📁 Directory Structure

```
PERTI/
├── api/                    # API endpoints
│   ├── adl/               # ADL flight data APIs (current, demand, waypoints)
│   ├── data/              # Reference data APIs (weather, SUA, TFR, playbook)
│   ├── jatoc/             # JATOC incident APIs
│   ├── mgt/               # Management CRUD APIs (plans, TMI, playbook)
│   ├── nod/               # NOD dashboard APIs (TMIs, flows, FEA)
│   ├── splits/            # Splits APIs (configs, scheduler, maps)
│   ├── swim/              # SWIM public API (FIXM-aligned, v1)
│   ├── stats/             # Statistics APIs (realtime, hourly, daily)
│   ├── tmi/               # TMI workflow APIs (GS/GDP lifecycle)
│   └── simulator/         # ATFM simulator APIs
│
├── assets/
│   ├── css/               # Stylesheets
│   ├── data/              # Navigation data (CSV: fixes, airways, playbook)
│   ├── geojson/           # Map boundary files (SUA, sectors)
│   ├── img/               # Images and icons
│   ├── js/                # JavaScript modules
│   ├── locales/           # i18n translation files (en-US, fr-CA, en-CA, en-EU)
│   └── vendor/            # Third-party libraries
│
├── adl/                   # ADL subsystem (daemons, migrations, analysis)
├── database/
│   └── migrations/        # SQL migration scripts
│
├── discord-bot/           # Node.js Discord Gateway bot
├── docs/                  # Documentation (swim/, stats/, tmi/)
├── lib/                   # Core PHP utility classes
├── load/                  # Shared PHP includes & configuration
├── login/                 # VATSIM OAuth login
├── scripts/               # Background daemons & utilities
├── sdk/                   # Multi-language client SDKs
├── services/              # Wind data services (NOAA GFS)
├── sessions/              # Session handling
└── wiki/                  # GitHub wiki source (46 pages)
```

---

## 🗄️ Databases

7 databases across 3 engines:

### MySQL (`perti_site`)
- Plans, schedules, configs, comments
- Initiative timelines, playbook plays
- User sessions and role assignments

### Azure SQL (5 databases)
- **VATSIM_ADL** — Normalized 8-table flight architecture (`adl_flight_core`, `adl_flight_plan`, `adl_flight_position`, `adl_flight_times`, `adl_flight_tmi`, `adl_flight_aircraft`, `adl_flight_trajectory`, `adl_flight_waypoints`), airport configs, splits, JATOC, statistics
- **VATSIM_TMI** — TMI programs (GS/GDP/AFP/reroute lifecycle), slots, advisories, coordination
- **VATSIM_REF** — Reference data (navigation fixes, airways, procedures, playbook routes)
- **SWIM_API** — Public FIXM-aligned API (`swim_flights`, API keys, audit log)
- **VATSIM_STATS** — Statistics & analytics (may be paused on free tier)

### PostgreSQL/PostGIS (`VATSIM_GIS`)
- ARTCC/TRACON/sector boundary polygons with spatial geometry
- Airport points, navigation fixes, airway segments with PostGIS support
- Boundary adjacency and spatial utility tables

---

## 📊 API Quick Reference

### ADL Flight Data
- `GET /api/adl/current.php` - Current flights snapshot
- `GET /api/adl/flight.php?id=xxx` - Single flight lookup
- `GET /api/adl/stats.php` - Flight statistics
- `GET /api/adl/snapshot_history.php` - Historical snapshots

### Airspace Element Demand

- `GET /api/adl/demand/fix.php` - Flights at a navigation fix
- `GET /api/adl/demand/airway.php` - Flights on an airway segment
- `GET /api/adl/demand/segment.php` - Flights between two fixes (airway or DCT)
- `GET /api/adl/demand/batch.php` - Multi-monitor time-bucketed demand
- `GET/POST/DELETE /api/adl/demand/monitors.php` - Demand monitor CRUD
- `GET /api/adl/demand/details.php` - Individual flights for a monitor

### TMI Operations

**Ground Stop API:**

- `POST /api/tmi/gs/create.php` - Create new GS
- `POST /api/tmi/gs/model.php` - Model GS scope
- `POST /api/tmi/gs/activate.php` - Activate GS
- `POST /api/tmi/gs/extend.php` - Extend GS
- `POST /api/tmi/gs/purge.php` - Purge GS
- `GET /api/tmi/gs/flights.php` - Get affected flights
- `GET /api/tmi/gs/demand.php` - Get demand data
- `GET /api/tmi/gs/list.php` - List programs
- `GET /api/tmi/gs/get.php` - Get single program

**GDP/Reroute/Advisory APIs:**

- `POST /api/tmi/gdp_*.php` - GDP operations
- `GET/POST /api/tmi/reroutes.php` - Reroute management
- `GET/POST /api/tmi/advisories.php` - Advisory management
- `GET/POST /api/tmi/entries.php` - TMI log entries (MIT, AFP, restrictions)
- `GET/POST /api/tmi/public-routes.php` - Public route management

### Playbook

- `GET /api/data/playbook/list.php` - List plays (with category/source filters)
- `GET /api/data/playbook/get.php` - Get single play with routes
- `GET /api/data/playbook/categories.php` - Play categories
- `GET /api/data/playbook/changelog.php` - Play change history
- `POST /api/mgt/playbook/save.php` - Create/update play
- `POST /api/mgt/playbook/route.php` - Add/update play routes
- `DELETE /api/mgt/playbook/delete.php` - Delete play

### JATOC
- `GET /api/jatoc/incidents.php` - List incidents
- `GET /api/jatoc/incident.php?id=xxx` - Get incident
- `POST /api/jatoc/incident.php` - Create incident
- `PUT /api/jatoc/incident.php?id=xxx` - Update incident

### NOD
- `GET /api/nod/tmi_active.php` - Active TMIs
- `GET /api/nod/advisories.php` - Advisories
- `GET /api/nod/tracks.php` - Flight tracks
- `GET /api/nod/fea.php` - Flow Evaluation Area demand
- `GET /api/nod/flows/elements.php` - Facility flow elements

### Splits
- `GET /api/splits/areas.php` - Area definitions
- `GET /api/splits/configs.php` - Configurations
- `GET /api/splits/active.php` - Active splits
- `GET /api/splits/presets.php` - Split presets
- `GET /api/splits/maps.php` - Sector map data
- `GET /api/splits/scheduled.php` - Scheduled splits
- `POST /api/splits/scheduler.php` - Schedule management

### Public Routes
- `GET /api/routes/public.php` - List public routes
- `POST /api/routes/public_post.php` - Create route

### Weather/Airspace
- `GET /api/data/weather.php` - Weather data
- `GET /api/data/sua.php` - Special Use Airspace
- `GET /api/data/tfr.php` - Temporary Flight Restrictions

---

## 🔐 Authentication

PERTI uses VATSIM Connect (OAuth) for authentication. Users log in via `/login/` which redirects to VATSIM's OAuth server and returns to `/login/callback.php`.

Session data is stored in PHP sessions and includes:
- `VATSIM_CID` - VATSIM CID
- `VATSIM_FIRST_NAME` / `VATSIM_LAST_NAME` - User name

**Note:** JATOC and NOD viewing is public; editing requires DCC role assignment.

---

## 🛠️ Background Daemons & Scripts

All 15 background daemons run inside the Azure App Service container, started at boot via `scripts/startup.sh`.

**Core Daemons:**

| Daemon | Script | Interval |
|--------|--------|----------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s |
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch |
| Boundary Detection | `adl/php/boundary_gis_daemon.php` | 15s |
| Crossing Calculation | `adl/php/crossing_gis_daemon.php` | Tiered |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min |
| Scheduler | `scripts/scheduler_daemon.php` | 60s |
| Archival | `scripts/archival_daemon.php` | 1-4h |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous |
| Monitoring | `scripts/monitoring_daemon.php` | 60s |
| Event Sync | `scripts/event_sync_daemon.php` | 6h |

**Maintenance Scripts:**

| Script | Purpose |
|--------|---------|
| `airac_full_update.py` | Full AIRAC cycle data update (28-day cycle) |
| `scripts/refresh_vatsim_boundaries.php` | Updates ARTCC/TRACON boundary GeoJSON |
| `nasr_navdata_updater.py` | Updates navigation data from FAA NASR |

---

## 📝 Configuration

Main configuration in `load/config.php`:
- Database credentials
- VATSIM OAuth settings
- ADL SQL connection settings
- Site configuration

Example config template: `load/config.example.php`

---

## 🔗 External Data Sources

- **VATSIM API** - Live flight data (positions, flight plans, prefiles)
- **FAA NFDC** - NASR navigation data (fixes, airways, airports, procedures)
- **Iowa Environmental Mesonet** - Weather radar (NEXRAD/MRMS)
- **VATSpy/SimAware** - ARTCC/TRACON boundary data
- **FAA Playbook** - Route playbooks and CDRs
- **VATUSA** - Events integration (US division)
- **VATCAN** - Events integration (Canadian division)
- **ECFMP** - European flow measures (EUROCONTROL-style)
- **FAA SUA** - Special Use Airspace data
- **NOAA GFS** - Wind data for ETA calculations

---

## 📚 Documentation

For detailed technical documentation, see:
- `wiki/` - GitHub wiki (46 pages: architecture, algorithms, APIs, troubleshooting)
- `docs/STATUS.md` - System status and feature tracking
- `docs/QUICK_REFERENCE.md` - Quick reference card
- `docs/ADL_REFRESH_MIGRATION_GUIDE.md` - ADL refresh patterns
- `docs/swim/` - SWIM API documentation
- `scripts/README.md` - Script documentation
- Database migrations in `database/migrations/`

---

## ⚙️ Technology Stack

- **Backend:** PHP 8.2+, Python 3.x (utilities), Node.js (Discord bot, simulator engine)
- **Frontend:** JavaScript (ES6+), jQuery 2.2.4, Bootstrap 4.5
- **Mapping:** MapLibre GL JS
- **Charts:** Chart.js
- **Databases:** MySQL 8, Azure SQL (Hyperscale Serverless), PostgreSQL/PostGIS
- **Hosting:** Azure App Service (Linux, P1v2)
- **Auth:** VATSIM Connect (OAuth 2.0)
- **Weather:** IEM NEXRAD/MRMS tiles
- **i18n:** 4 locales (en-US, fr-CA, en-CA, en-EU) with 450+ translation keys

---

## 🆕 Recent Updates (v18)

- **Playbook Route Catalog (NEW):**
  - `playbook.php` — Pre-coordinated route play management
  - `api/data/playbook/*.php` — Play listing, categories, changelog
  - `api/mgt/playbook/*.php` — Play CRUD (save, route, delete)
  - Multi-source: FAA national, DCC custom, ECFMP, CANOC plays
  - Bulk paste parser for ECFMP/CANOC format routes
  - Shareable links: `?play=PLAY_NAME` URL parameter
  - Duplicate play with `_MODIFIED` suffix

- **Canadian FIR Sectors & International Expansion:**
  - 7 Canadian FIRs: CZYZ, CZWG, CZEG, CZUL, CZVR, CZQM, CZQX
  - 1,379 total sector boundaries (23 US ARTCCs + Canadian FIRs)
  - Splits map visualization with per-FIR sector data

- **Scheduled Splits & Strata Filtering:**
  - Scheduled splits with auto-activate/deactivate at UTC times
  - `api/splits/scheduled.php` / `scheduler.php` — Schedule management
  - Strata filtering: low (SFC-FL230), high (FL230-FL370), superhigh (FL370+)

- **NOD Facility Flows:**
  - Facility Flow Configurations with FEA demand integration
  - `api/nod/flows/elements.php` — Flow element data
  - `api/nod/fea.php` — Flow Evaluation Area demand
  - 8 map layer types with visual controls per element

- **TMI Publish & Discord Integration:**
  - `tmi-publish.php` — NTML & advisory publishing to Discord
  - Multi-organization Discord posting (multi-server)
  - TMR (Traffic Management Review) reports with demand charts

- **Internationalization (i18n):**
  - 450+ translation keys across 4 locales (en-US, fr-CA, en-CA, en-EU)
  - Locale auto-detection (URL > localStorage > browser > en-US fallback)
  - `PERTIDialog` wrapper with automatic i18n key resolution

- **Previous v17 Updates:**
  - Airport demand analysis (`demand.php`) with weather-aware rate suggestions
  - Airport configuration & ATIS system (runway detection, rate audit trail)
  - ATFM Training Simulator (`simulator.php`) with Node.js flight engine
  - Airspace element demand (fix, airway, segment queries)
  - Config modifiers & ATIS priority

- **Previous v15/v16 Updates:**
  - GDT Ground Stop NTML Architecture with complete program lifecycle
  - ADL Schema Cleanup (unified `phase` column)
  - Weather Radar (IEM NEXRAD/MRMS integration)
  - SUA/TFR Display
  - Initiative Timeline (Gantt-style visualization)

---

## 📞 Contact

For issues or questions about PERTI, contact the vATCSCC development team.

---

*Last updated: 2026-02-25*
