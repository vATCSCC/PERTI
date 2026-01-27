# vATCSCC PERTI - Virtual Air Traffic Control System Command Center

## Overview

PERTI (Plan, Execute, Review, Train, and Improve) is a comprehensive web-based traffic flow management platform for VATSIM (Virtual Air Traffic Control Simulation). It provides professional-grade tools for virtual air traffic controllers to manage traffic flow, monitor incidents, and coordinate operations.

**Production URL:** https://vatcscc.azurewebsites.net

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
| **Splits** | `/splits.php` | Sector/position split configuration |
| **NTML** | `/ntml.php` | National Traffic Management Log quick entry |
| **Advisory Builder** | `/advisory-builder.php` | TFMS-style advisory creation |
| **ATFM Simulator** | `/simulator.php` | TMU training simulator (v17) |

### Configuration & Administration (Authentication Required)

| Page | URL | Description |
|------|-----|-------------|
| **Airport Configs** | `/airport_config.php` | Runway configurations and rate management |
| **Airspace Elements** | `/airspace-elements.php` | Custom airspace element management |
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

- **Active TMIs:** Real-time ground stops, GDPs, reroutes
- **Advisory Management:** DCC advisory creation and tracking
- **Flight Tracks:** Historical position tracking
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
- **Map Visualization:** MapLibre-based sector display with color coding
- **Strata Filtering:** Low/High/Superhigh sector layers

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
│   ├── adl/               # ADL flight data APIs
│   ├── data/              # Reference data APIs (including weather, SUA, TFR)
│   ├── jatoc/             # JATOC incident APIs
│   ├── mgt/               # Management CRUD APIs
│   ├── nod/               # NOD dashboard APIs
│   ├── routes/            # Public routes APIs
│   ├── splits/            # Splits APIs
│   ├── statsim/           # StatSim integration APIs
│   ├── tmi/               # TMI workflow APIs
│   └── user/              # User-specific APIs
│
├── assets/
│   ├── css/               # Stylesheets (including weather_radar.css)
│   ├── data/              # Navigation data (CSV)
│   │   ├── ARTCCs/        # Per-ARTCC sector data
│   │   └── backups/       # Navdata backups
│   ├── geojson/           # Map boundary files (including SUA.geojson)
│   ├── img/               # Images and icons
│   ├── js/                # JavaScript modules
│   └── vendor/            # Third-party libraries
│
├── database/
│   └── migrations/        # SQL migration scripts
│
├── docs/                  # Documentation
├── load/                  # Shared PHP includes
├── login/                 # VATSIM OAuth login
├── scripts/               # Background scripts
├── sessions/              # Session handling
└── sql/                   # Additional SQL scripts
```

---

## 🗄️ Databases

### MySQL
- Plans, schedules, configs, comments
- Initiative timelines
- Ground stop definitions

### Azure SQL (ADL)
- Live flight state (`dbo.adl_flights`)
- TMI workflows (GS/GDP/Reroute)
- Splits configurations
- JATOC incidents
- DCC advisories
- Flight history

---

## 📊 API Quick Reference

### ADL Flight Data
- `GET /api/adl/current.php` - Current flights snapshot
- `GET /api/adl/flight.php?id=xxx` - Single flight lookup
- `GET /api/adl/stats.php` - Flight statistics
- `GET /api/adl/snapshot_history.php` - Historical snapshots

### Airspace Element Demand (v17)

- `GET /api/adl/demand/fix.php` - Flights at a navigation fix
- `GET /api/adl/demand/airway.php` - Flights on an airway segment
- `GET /api/adl/demand/segment.php` - Flights between two fixes (airway or DCT)
- `GET /api/adl/demand/batch.php` - Multi-monitor time-bucketed demand
- `GET/POST/DELETE /api/adl/demand/monitors.php` - Demand monitor CRUD
- `GET /api/adl/demand/details.php` - Individual flights for a monitor

### TMI Operations

**New GS API (v15):**

- `POST /api/tmi/gs/create.php` - Create new GS
- `POST /api/tmi/gs/model.php` - Model GS scope
- `POST /api/tmi/gs/activate.php` - Activate GS
- `POST /api/tmi/gs/extend.php` - Extend GS
- `POST /api/tmi/gs/purge.php` - Purge GS
- `GET /api/tmi/gs/flights.php` - Get affected flights
- `GET /api/tmi/gs/demand.php` - Get demand data
- `GET /api/tmi/gs/list.php` - List programs
- `GET /api/tmi/gs/get.php` - Get single program

**Legacy APIs:**

- `POST /api/tmi/gs_*.php` - Ground Stop operations (legacy)
- `POST /api/tmi/gdp_*.php` - GDP operations

### JATOC
- `GET /api/jatoc/incidents.php` - List incidents
- `GET /api/jatoc/incident.php?id=xxx` - Get incident
- `POST /api/jatoc/incident.php` - Create incident
- `PUT /api/jatoc/incident.php?id=xxx` - Update incident

### NOD
- `GET /api/nod/tmi_active.php` - Active TMIs
- `GET /api/nod/advisories.php` - Advisories
- `GET /api/nod/tracks.php` - Flight tracks

### Splits
- `GET /api/splits/areas.php` - Area definitions
- `GET /api/splits/configs.php` - Configurations
- `GET /api/splits/active.php` - Active splits

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

## 🛠️ Maintenance Scripts

| Script | Purpose |
|--------|---------|
| `scripts/vatsim_adl_daemon.php` | Refreshes flight data from VATSIM every ~15s |
| `scripts/refresh_vatsim_boundaries.php` | Updates ARTCC/TRACON boundary GeoJSON |
| `scripts/update_playbook_routes.py` | Updates playbook route CSV from FAA |
| `nasr_navdata_updater.py` | Updates navigation data from FAA NASR |
| `scripts/statsim_scraper.js` | Scrapes StatSim historical data |

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

- **VATSIM API** - Live flight data
- **FAA NFDC** - NASR navigation data
- **Iowa Environmental Mesonet** - Weather radar (NEXRAD/MRMS)
- **VATSpy/SimAware** - Boundary data
- **FAA Playbook** - Route playbooks
- **VATUSA** - Events integration
- **FAA SUA** - Special Use Airspace data

---

## 📚 Documentation

For detailed technical documentation, see:
- `assistant_codebase_index_v17.md` - Comprehensive codebase index
- `docs/ADL_REFRESH_MIGRATION_GUIDE.md` - ADL refresh patterns
- `scripts/README.md` - Script documentation
- `scripts/README_boundaries.md` - Boundary refresh documentation
- Database migrations in `database/migrations/`

---

## ⚙️ Technology Stack

- **Backend:** PHP 8.2+
- **Frontend:** JavaScript (ES6+), jQuery, Bootstrap 4.5
- **Mapping:** MapLibre GL JS, Leaflet
- **Charts:** Chart.js
- **Databases:** MySQL, Azure SQL
- **Hosting:** Azure App Service
- **Auth:** VATSIM Connect (OAuth)
- **Weather:** IEM NEXRAD/MRMS tiles

---

## 🆕 Recent Updates (v17)

- **Demand Subsystem (NEW):**
  - `demand.php` — Airport demand analysis page
  - `api/demand/*.php` — Demand API endpoints (airports, rates, summary, override)
  - Weather-aware rate suggestions with AAR/ADR display
  - Manual rate override support with time windows

- **Airport Configuration & ATIS System (NEW):**
  - Normalized runway configuration schema (`airport_config`, `airport_config_runway`, `airport_config_rate`)
  - VATSIM ATIS import with weather extraction (wind, visibility, ceiling, category)
  - Runway-in-use detection from ATIS parsing
  - Flight-track-based runway detection as fallback
  - Multi-level rate suggestion algorithm with confidence scoring
  - Rate change audit trail (`airport_config_rate_history`)
  - Manual rate overrides (`manual_rate_override`)

- **ATFM Training Simulator (NEW v17):**
  - Web-based TMU training tool for GS/GDP/AFP/MIT/Reroute practice
  - Node.js flight engine with realistic physics simulation
  - Reference data: 3,989 O-D route patterns, 107 airports, 17 carriers
  - `simulator.php` — Main simulator page
  - `api/simulator/*.php` — Simulator API endpoints (navdata, engine, routes, traffic)
  - `simulator/engine/` — Node.js headless flight simulation

- **Airspace Element Demand (NEW v17):**
  - Query traffic at navigation fixes, airway segments, and route segments
  - Table-valued SQL functions for efficient demand analysis
  - `api/adl/demand/*.php` — Demand API endpoints (fix, airway, segment)
  - Support for both airway-based and direct (DCT) route queries

- **Config Modifiers & ATIS Priority (NEW v17):**
  - Structured modifier categories for runway configurations
  - Enhanced ATIS source selection: ARR+DEP > COMB > single
  - New views for effective ATIS determination

- **Previous v15/v16 Updates:**
  - GDT Ground Stop NTML Architecture with complete program lifecycle
  - ADL Schema Cleanup (unified `phase` column)
  - Weather Radar (IEM NEXRAD/MRMS integration)
  - SUA/TFR Display
  - Initiative Timeline (Gantt-style visualization)
  - ATIS Batch Import

---

## 📞 Contact

For issues or questions about PERTI, contact the vATCSCC development team.

---

*Last updated: 2026-01-16*
