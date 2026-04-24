# PERTI Codebase Dependency Map

> Generated: 2026-01-31 | Verified: 2026-02-25 | Updated: 2026-03-29
> Total PHP API Files: 400+ | Frontend Pages: 32+ | JS Files: 71+ | Scripts: 70+

---

## Table of Contents
1. [Architecture Overview](#1-architecture-overview)
2. [Core Bootstrap Chain](#2-core-bootstrap-chain)
3. [Frontend Page Dependencies](#3-frontend-page-dependencies)
4. [API Endpoint Hierarchy](#4-api-endpoint-hierarchy)
5. [Shared Library Dependencies](#5-shared-library-dependencies)
6. [Database Connection Map](#6-database-connection-map)
7. [External API Integrations](#7-external-api-integrations)
8. [Script & Daemon Dependencies](#8-script--daemon-dependencies)
9. [Full Dependency Trees](#9-full-dependency-trees)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              FRONTEND LAYER                                  │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐   │
│  │index.php│ │plan.php │ │route.php│ │demand.php│ │ nod.php │ │ gdt.php │   │
│  │cdm.php  │ │ctp.php  │ │navdata  │ │hist-rte  │ │playbook │ │ jatoc   │   │
│  └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘   │
│       │           │           │           │           │           │         │
│  ┌────┴───────────┴───────────┴───────────┴───────────┴───────────┴────┐   │
│  │                        JavaScript Layer                              │   │
│  │  plan.js | route.js | demand.js | nod.js | gdt.js | tmi-*.js        │   │
│  └─────────────────────────────────┬───────────────────────────────────┘   │
└────────────────────────────────────┼───────────────────────────────────────┘
                                     │ AJAX/fetch
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              API LAYER (368 endpoints)                       │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐   │
│  │ /api/adl│ │/api/data│ │/api/tmi │ │/api/mgt │ │/api/swim│ │/api/gdt │   │
│  │/api/ctp │ │/api/cdm │ │/api/gis │ │/api/rte │ │/api/dem │ │/api/dsc │   │
│  └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘   │
│       └───────────┴───────────┴─────┬─────┴───────────┴───────────┘         │
└─────────────────────────────────────┼───────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SHARED LIBRARIES                                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐    │
│  │  config.php  │  │  connect.php │  │  input.php   │  │  helpers.php │    │
│  └──────┬───────┘  └──────┬───────┘  └──────────────┘  └──────────────┘    │
│         └─────────────────┼─────────────────────────────────────────────    │
└───────────────────────────┼─────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DATABASE LAYER                                     │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐│
│  │VATSIM_ADL│ │VATSIM_TMI│ │VATSIM_REF│ │ SWIM_API │ │VATSIM_GIS│ │perti_sit││
│  │Azure SQL │ │Azure SQL │ │Azure SQL │ │Azure SQL │ │PostgreSQL│ │  MySQL  ││
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘ └─────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Core Bootstrap Chain

Every PHP file follows this dependency chain:

```
Depth 0: config.php (no dependencies)
         ├── Defines: env() helper, all DB credentials, Discord config
         │
         ▼
Depth 1: input.php (no dependencies)
         ├── Defines: get_input(), post_input(), get_int(), etc.
         │
         ▼
Depth 2: connect.php (requires config.php, input.php)
         ├── Establishes: $conn_pdo (MySQL), $_conn_cache (lazy SQL Server)
         ├── Provides: get_conn_adl(), get_conn_tmi(), get_conn_ref(),
         │             get_conn_gis(), get_conn_swim()
         │
         ▼
Depth 3: API Endpoint (requires connect.php)
         └── All /api/*.php files
```

### Bootstrap File Details

| File | Depth | Dependencies | Provides | Used By |
|------|-------|--------------|----------|---------|
| `load/input.php` | 0 | None | Input sanitization functions | All API endpoints with user input |
| `load/config.php` | 1 | input.php (optional) | `env()`, DB constants, Discord config | ~95% of PHP files |
| `load/connect.php` | 2 | config.php, input.php | Database connections (5 lazy + 1 eager) | All database-dependent files |

---

## 3. Frontend Page Dependencies

### 3.1 Page → JavaScript → API Dependency Tree

```
index.php (Plan List)
├── JS: assets/js/plan.js (minimal)
├── APIs Called:
│   ├── api/data/plans.l.php (GET) → Plan list HTML
│   ├── api/mgt/perti/post.php (POST) → Create plan
│   ├── api/mgt/perti/update.php (PUT) → Update plan
│   └── api/mgt/perti/delete.php (DELETE) → Delete plan
└── Depth: 2 (Page → JS → API)

plan.php (Plan Editor)
├── JS: assets/js/plan.js, initiative_timeline.js
├── APIs Called (15+ endpoints):
│   ├── api/data/plans/goals.php
│   ├── api/data/plans/term_inits.php
│   ├── api/data/plans/term_inits_timeline.php
│   ├── api/data/plans/term_staffing.php
│   ├── api/data/plans/term_constraints.php
│   ├── api/data/plans/term_planning.php
│   ├── api/data/plans/enroute_inits.php
│   ├── api/data/plans/enroute_inits_timeline.php
│   ├── api/data/plans/enroute_staffing.php
│   ├── api/data/plans/enroute_constraints.php
│   ├── api/data/plans/enroute_planning.php
│   ├── api/data/plans/dcc_staffing.php
│   ├── api/data/plans/configs.php
│   ├── api/data/plans/forecast.php
│   ├── api/data/plans/historical.php
│   └── api/mgt/* (CRUD for each section)
└── Depth: 3 (Page → JS → API → Database)

route.php (Route Visualization + Playbook/CDR Search)
├── JS: assets/js/route.js, route-maplibre.js, procs_enhanced.js, awys.js,
│       playbook-cdr-search.js, route-symbology.js, fir-scope.js
├── Libraries: Maplibre-GL, Leaflet, Turf.js
├── Data Files:
│   ├── assets/data/points.csv (navigation fixes)
│   ├── assets/data/cdrs.csv (CDR routes)
│   ├── assets/data/playbook_routes.csv
│   └── assets/data/awys.csv (airways)
├── APIs Called:
│   ├── api/gis/boundaries.php (route expansion)
│   ├── api/data/fixes.php
│   └── api/data/reroutes.php
└── Depth: 3

demand.php (Demand Analysis)
├── JS: assets/js/demand.js
├── Libraries: ECharts
├── APIs Called:
│   ├── api/demand/airport.php
│   ├── api/demand/airports.php
│   ├── api/demand/rates.php
│   ├── api/demand/atis.php
│   ├── api/demand/active_config.php
│   ├── api/demand/scheduled_configs.php
│   └── api/demand/summary.php
└── Depth: 3

nod.php (Network Operational Display)
├── JS: assets/js/nod.js, nod-demand-layer.js
├── Libraries: Maplibre-GL, D3.js
├── APIs Called:
│   ├── api/adl/current.php
│   ├── api/nod/advisories.php
│   ├── api/nod/jatoc.php
│   ├── api/nod/tmi_active.php
│   ├── api/nod/tracks.php
│   ├── api/splits/active.php
│   └── api/adl/demand/batch.php
└── Depth: 4 (Page → JS → API → Helper → Database)

gdt.php (Ground Delay Tool)
├── JS: assets/js/gdt.js, gdp.js, tmi-gdp.js
├── Libraries: Chart.js, ECharts, D3.js
├── Data Files: assets/data/apts.csv
├── APIs Called:
│   ├── api/gdt/programs/list.php
│   ├── api/gdt/programs/create.php
│   ├── api/gdt/programs/simulate.php
│   ├── api/gdt/programs/activate.php
│   ├── api/gdt/programs/publish.php
│   ├── api/gdt/flights/list.php
│   ├── api/gdt/demand/hourly.php
│   ├── api/adl/current.php
│   ├── api/tiers.php
│   └── api/mgt/tmi/advisory-number.php
└── Depth: 4

tmi-publish.php (TMI Publishing)
├── JS: assets/js/tmi-publish.js, tmi-gdp.js, tmi-active-display.js
├── APIs Called:
│   ├── api/mgt/tmi/advisory-number.php
│   ├── api/mgt/tmi/publish.php
│   ├── api/mgt/tmi/coordinate.php
│   ├── api/gdt/programs/submit_proposal.php
│   └── api/tmi/active.php
└── Depth: 5 (includes Discord integration)

splits.php (Sector Splits)
├── JS: assets/js/splits.js
├── Libraries: Maplibre-GL
├── APIs Called:
│   ├── api/splits/active.php
│   ├── api/splits/config.php
│   ├── api/splits/configs.php
│   ├── api/splits/sectors.php
│   ├── api/splits/areas.php
│   └── api/splits/scheduled.php
└── Depth: 3

sua.php (Special Use Airspace)
├── JS: assets/js/sua.js
├── Libraries: Maplibre-GL, Mapbox GL Draw
├── APIs Called:
│   ├── api/data/sua/sua_list.php
│   ├── api/data/sua/sua_geojson.php
│   ├── api/data/sua/activations.php
│   ├── api/mgt/sua/activate.php
│   ├── api/mgt/sua/tfr_create.php
│   └── api/mgt/sua/altrv_create.php
└── Depth: 3

jatoc.php (JATOC Integration)
├── JS: assets/js/jatoc.js
├── Libraries: Maplibre-GL
├── APIs Called:
│   ├── api/jatoc/config.php
│   ├── api/jatoc/faa_ops_plan.php
│   ├── api/jatoc/incidents.php
│   ├── api/jatoc/personnel.php
│   └── api/jatoc/vatusa_events.php
└── Depth: 3
```

### 3.2 Complete Frontend Dependency Matrix

| Page | JavaScript Files | Libraries | API Endpoints Called | Max Depth |
|------|------------------|-----------|---------------------|-----------|
| index.php | plan.js | jQuery, Bootstrap, SweetAlert2 | 4 | 2 |
| plan.php | plan.js, initiative_timeline.js | Summernote, DatetimePicker | 25+ | 3 |
| route.php | route.js, route-maplibre.js, procs_enhanced.js, awys.js, playbook-cdr-search.js, route-symbology.js, fir-scope.js | Maplibre-GL, Leaflet, Turf.js | 4 | 3 |
| demand.php | demand.js | ECharts | 7 | 3 |
| nod.php | nod.js, nod-demand-layer.js | Maplibre-GL, D3.js | 8 | 4 |
| gdt.php | gdt.js, gdp.js, tmi-gdp.js | Chart.js, ECharts, D3.js | 12 | 4 |
| tmi-publish.php | tmi-publish.js, tmi-gdp.js, tmi-active-display.js | - | 6 | 5 |
| splits.php | splits.js | Maplibre-GL | 6 | 3 |
| sua.php | sua.js | Maplibre-GL, Mapbox GL Draw | 6 | 3 |
| jatoc.php | jatoc.js | Maplibre-GL | 5 | 3 |
| advisory-builder.php | advisory-builder.js | - | 4 | 4 |
| review.php | review.js | - | 4 | 3 |
| schedule.php | schedule.js | - | 3 | 2 |
| sheet.php | sheet.js | - | 6 | 2 |
| reroutes.php | public-routes.js | - | 4 | 3 |
| simulator.php | (inline) | - | 4 | 3 |
| swim.php | (inline) | - | 5 | 3 |
| status.php | (inline) | Chart.js | 3 | 3 |

---

## 4. API Endpoint Hierarchy

### 4.1 API Categories (368 total endpoints)

```
/api/
├── adl/ (21 files)           → Flight data & demand analysis
│   ├── current.php           → Active flights
│   ├── flight.php            → Single flight lookup
│   ├── demand/               → Demand analysis (7 files)
│   └── ...
│
├── data/ (54 files)          → Read-only data endpoints
│   ├── plans/ (17 files)     → Plan section data
│   ├── sheet/ (4 files)      → Sheet data
│   ├── review/ (3 files)     → Review data
│   ├── sua/ (4 files)        → SUA data
│   ├── tmi/ (4 files)        → TMI data views
│   └── ...
│
├── mgt/ (95 files)           → CRUD management endpoints
│   ├── perti/ (3 files)      → Plan CRUD
│   ├── configs/ (4 files)    → Config CRUD
│   ├── terminal_inits/ (9 files)
│   ├── enroute_inits/ (8 files)
│   ├── tmi/ (15 files)       → TMI management
│   │   ├── reroutes/ (5 files)
│   │   └── ground_stops/ (1 file)
│   └── ... (60+ more)
│
├── tmi/ (29 files)           → TMI operations
│   ├── gs/ (10 files)        → Ground stop operations
│   └── ...
│
├── gdt/ (18 files)           → Ground Delay Tool
│   ├── programs/ (13 files)  → GDT program lifecycle
│   ├── flights/ (1 file)
│   ├── demand/ (1 file)
│   ├── slots/ (1 file)
│   └── common.php, index.php (2 files)
│
├── swim/v1/ (32 files)       → SWIM API
│   ├── ingest/ (6 files)     → Data ingestion
│   ├── tmi/ (9 files)        → TMI integration
│   │   └── flow/ (4 files)
│   ├── keys/ (2 files)       → API key management
│   └── ws/ (4 files)         → WebSocket
│
├── demand/ (11 files)        → Airport demand
├── splits/ (14 files)        → Sector splits
├── stats/ (13 files)         → Statistics
├── jatoc/ (11 files)         → JATOC integration
├── nod/ (6 files)            → NOD data
├── discord/ (5 files)        → Discord integration
├── admin/ (7 files)          → Admin utilities
├── analysis/ (5 files)       → Analysis tools
├── routes/ (4 files)         → Route management
├── simulator/ (4 files)      → Simulator
├── weather/ (3 files)        → Weather data
├── gis/ (1 file)             → GIS boundaries
├── user/ (4 files)           → User preferences
├── event-aar/ (1 file)       → Event AAR rates
└── cron.php                  → Scheduled task endpoints
```

### 4.2 API → Shared Library Dependencies

```
api/adl/*.php
├── Requires: load/connect.php
├── Uses: api/adl/AdlQueryHelper.php
├── Database: VATSIM_ADL (via get_conn_adl())
└── Depth: 3

api/tmi/*.php
├── Requires: load/connect.php
├── Uses: api/tmi/helpers.php (TmiResponse, TmiAuth, tmi_init)
├── Uses: load/discord/TMIDiscord.php (for publishing)
├── Database: VATSIM_TMI (via get_conn_tmi())
└── Depth: 4 (when Discord involved: 5)

api/gdt/*.php
├── Requires: load/connect.php
├── Uses: api/gdt/common.php
├── Database: VATSIM_ADL, VATSIM_TMI
└── Depth: 3

api/mgt/*.php
├── Requires: load/connect.php
├── Database: perti_site (MySQL), some use VATSIM_TMI
└── Depth: 2

api/swim/v1/*.php
├── Requires: load/connect.php, api/swim/v1/auth.php
├── Uses: load/swim_config.php
├── Database: VATSIM_ADL, SWIM database
└── Depth: 3

api/stats/*.php
├── Requires: load/connect.php
├── Uses: api/stats/StatsHelper.php
├── Database: VATSIM_STATS
└── Depth: 3

api/gis/*.php
├── Requires: load/connect.php
├── Uses: load/services/GISService.php
├── Database: VATSIM_GIS (PostgreSQL/PostGIS)
└── Depth: 3

api/splits/*.php
├── Requires: api/splits/connect_adl.php
├── Database: VATSIM_ADL
└── Depth: 2
```

---

## 5. Shared Library Dependencies

### 5.1 Library Dependency Graph

```
                    ┌─────────────────┐
                    │   config.php    │ Depth 0
                    │  (no deps)      │
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
              ▼              ▼              ▼
     ┌────────────┐  ┌────────────┐  ┌────────────┐
     │ input.php  │  │swim_config │  │ Constants  │ Depth 1
     │ (no deps)  │  │   .php     │  │ (inline)   │
     └──────┬─────┘  └────────────┘  └────────────┘
            │
            ▼
     ┌─────────────────┐
     │   connect.php   │ Depth 2
     │ (config, input) │
     └────────┬────────┘
              │
     ┌────────┼────────┬────────────┬───────────────┐
     │        │        │            │               │
     ▼        ▼        ▼            ▼               ▼
┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────────┐ ┌──────────────┐
│  ADL    │ │  TMI    │ │  Stats  │ │    GIS      │ │   Discord    │ Depth 3
│ Helper  │ │ Helper  │ │ Helper  │ │  Service    │ │   Discord    │
└────┬────┘ └────┬────┘ └────┬────┘ └──────┬──────┘ └──────┬───────┘
     │           │           │             │               │
     │           │           │             │        ┌──────┴──────┐
     │           │           │             │        │             │
     ▼           ▼           ▼             ▼        ▼             ▼
┌─────────┐ ┌─────────┐ ┌─────────┐ ┌──────────┐ ┌─────────┐ ┌─────────┐
│api/adl/*│ │api/tmi/*│ │api/stat*│ │api/gis/* │ │ Multi   │ │   TMI   │ Depth 4
│         │ │         │ │         │ │          │ │ Discord │ │ Discord │
└─────────┘ └─────────┘ └─────────┘ └──────────┘ └─────────┘ └────┬────┘
                                                                   │
                                                                   ▼
                                                            ┌─────────────┐
                                                            │TMI Endpoints│ Depth 5
                                                            │(with Discord│
                                                            │ publishing) │
                                                            └─────────────┘
```

### 5.2 Helper Class Details

| Helper | Location | Dependencies | Provides | Used By |
|--------|----------|--------------|----------|---------|
| `AdlQueryHelper` | api/adl/AdlQueryHelper.php | None | Flight query builders | 8+ ADL/demand endpoints |
| `TmiResponse` | api/tmi/helpers.php | None | JSON response formatting | 15+ TMI endpoints |
| `TmiAuth` | api/tmi/helpers.php | connect.php | Session/API auth | 15+ TMI endpoints |
| `StatsHelper` | api/stats/StatsHelper.php | Connection | Stats queries | 6+ stats endpoints |
| `GISService` | load/services/GISService.php | connect.php | PostGIS queries | GIS, boundary endpoints |
| `DiscordAPI` | load/discord/DiscordAPI.php | None | Discord REST client | TMIDiscord, MultiDiscord |
| `MultiDiscordAPI` | load/discord/MultiDiscordAPI.php | DiscordAPI | Multi-org posting | TMIDiscord |
| `TMIDiscord` | load/discord/TMIDiscord.php | DiscordAPI, Multi | TMI message formatting | TMI publishing |
| `DiscordMessageParser` | load/discord/DiscordMessageParser.php | None | Parse Discord messages | Webhook handlers |
| `DiscordWebhookHandler` | load/discord/DiscordWebhookHandler.php | DiscordAPI | Webhook processing | Discord bot integration |
| `tmi_init()` | api/tmi/helpers.php | connect.php | API init, auth check | All TMI endpoints |

---

## 6. Database Connection Map

### 6.1 Connection Functions

| Function | Database | Type | Lazy Load | Used By |
|----------|----------|------|-----------|---------|
| `$conn_pdo` | perti_site | MySQL PDO | No (immediate) | Plan management, user data |
| `get_conn_adl()` | VATSIM_ADL | Azure SQL | Yes | ADL, demand, stats, SWIM |
| `get_conn_tmi()` | VATSIM_TMI | Azure SQL | Yes | TMI, GDT, advisories |
| `get_conn_ref()` | VATSIM_REF | Azure SQL | Yes | Reference lookups |
| `get_conn_swim()` | SWIM_API | Azure SQL | Yes | SWIM endpoints |
| `get_conn_gis()` | VATSIM_GIS | PostgreSQL | Yes | GIS/PostGIS endpoints |

### 6.2 Database → Endpoint Mapping

```
perti_site (MySQL)
├── api/data/plans/*.php (17 endpoints)
├── api/mgt/perti/*.php
├── api/mgt/configs/*.php
├── api/mgt/*_staffing/*.php
├── api/mgt/*_planning/*.php
├── api/mgt/*_constraints/*.php
├── api/mgt/goals/*.php
├── api/mgt/schedule/*.php
├── api/user/*.php
└── Total: ~60 endpoints

VATSIM_ADL (Azure SQL)
├── api/adl/*.php (21 endpoints)
├── api/demand/*.php (11 endpoints)
├── api/stats/*.php (13 endpoints)
├── api/splits/*.php (14 endpoints)
├── api/swim/v1/*.php (flight data)
├── api/gdt/*.php (flight queries)
├── api/tiers/*.php
└── Total: ~75 endpoints

VATSIM_TMI (Azure SQL)
├── api/tmi/*.php (29 endpoints)
├── api/mgt/tmi/*.php (15 endpoints)
├── api/gdt/programs/*.php (13 endpoints)
├── api/nod/*.php (TMI data)
├── api/swim/v1/tmi/*.php
└── Total: ~65 endpoints

VATSIM_REF (Azure SQL)
├── api/data/fixes.php
├── api/util/icao_lookup.php
├── Playbook/procedure lookups
└── Total: ~5 endpoints

VATSIM_GIS (PostgreSQL)
├── api/gis/boundaries.php
├── api/adl/boundaries.php
├── Spatial analysis endpoints
└── Total: ~3 endpoints

VATSIM_STATS (Azure SQL)
├── api/stats/*.php (subset)
├── Note: Many stats endpoints query VATSIM_ADL tables
│   for real-time data (adl_flight_core, etc.)
└── Total: ~8 endpoints (4 use STATS_SQL_DSN directly)
```

---

## 7. External API Integrations

### 7.1 Outbound API Calls

```
Discord API
├── Called By:
│   ├── load/discord/DiscordAPI.php
│   ├── api/mgt/tmi/coordinate.php
│   ├── api/nod/discord-post.php
│   └── api/discord/webhook.php
├── Purpose: TMI publishing, notifications
└── Depth from frontend: 5

VATSIM Data API
├── Called By:
│   ├── scripts/vatsim_atis/vatsim_fetcher.py
│   ├── scripts/vatsim_adl_daemon.php
│   └── api/swim/v1/ingest/*.php
├── Purpose: Live flight data, ATIS
└── Depth: External (daemon/cron)

FAA NASR/Playbook
├── Called By:
│   ├── scripts/nasr_navdata_updater.py
│   └── scripts/update_playbook_routes.py
├── Purpose: Navigation data, routes
└── Depth: External (scheduled task)

Statsim.net
├── Called By: scripts/statsim/fetch_new_events.py
├── Purpose: VATUSA event data
└── Depth: External (scheduled task)

ECFMP API
├── Called By: scripts/ecfmp_poll_daemon.php
├── Purpose: EUROCONTROL-style flow measures
└── Depth: External (daemon, 5min)

vACDM API
├── Called By: scripts/vacdm_poll_daemon.php
├── Purpose: A-CDM airport milestones
└── Depth: External (daemon, 2min)

vIFF CDM API
├── Called By: scripts/viff_cdm_poll_daemon.php
├── Purpose: EU CDM milestone data
└── Depth: External (daemon, 30s, conditional)

CTP API
├── Called By: load/services/CTPApiClient.php
├── Purpose: Cross the Pond (oceanic/special events)
└── Depth: External (service layer)
```

### 7.2 Inbound API (SWIM)

```
/api/swim/v1/
├── Authentication: api/swim/v1/auth.php (API keys)
├── Endpoints:
│   ├── ingest/adl.php ← External ADL data
│   ├── ingest/track.php ← Position updates
│   ├── ingest/acars.php ← ACARS messages
│   ├── ingest/simtraffic.php ← SimTraffic data
│   ├── ingest/cdm.php ← CDM milestone data
│   ├── ingest/ctp.php ← CTP session data
│   ├── ingest/metering.php ← Metering data
│   ├── ingest/vnas/*.php ← vNAS data
│   ├── cdm/*.php ← CDM compliance, metrics, readiness
│   ├── connectors/*.php ← Connector health/status
│   ├── ctp/sessions.php ← CTP session management
│   ├── playbook/*.php ← Playbook route data
│   ├── reference/*.php ← Reference data
│   └── routes/*.php ← Route data
├── Rate Limits: Defined in swim_config.php
└── Depth: 3 (auth → endpoint → database)
```

---

## 8. Script & Daemon Dependencies

### 8.1 Daemon Dependency Tree

```
vatsim_adl_daemon.php
├── Requires: load/config.php
├── Database: VATSIM_ADL
├── Stored Procs: sp_Upsert_ADL_Flight
├── Frequency: Every 15 minutes
└── Depth: 2

archival_daemon.php
├── Requires: load/config.php
├── Database: VATSIM_ADL
├── Stored Procs:
│   ├── sp_Archive_CompletedFlights
│   ├── sp_Archive_Trajectory_ToWarm
│   ├── sp_Downsample_Trajectory_ToCold
│   └── sp_Purge_OldData
├── Frequency: Every 60-240 minutes
└── Depth: 2

monitoring_daemon.php
├── Requires: load/config.php
├── Database: VATSIM_ADL
├── Stored Procs: sp_GetFPMStats, sp_GetDBConnections
├── Frequency: Every 60 seconds
└── Depth: 2

vatsim_atis/atis_daemon.py
├── Requires: .env, vatsim_fetcher.py, atis_parser.py
├── Database: VATSIM_ADL
├── Stored Procs:
│   ├── sp_ImportVatsimAtis
│   ├── sp_ImportRunwaysInUse
│   └── sp_GetPendingAtis
├── Frequency: Continuous
└── Depth: 3
```

### 8.2 Scheduled Task Dependencies

```
airac_full_update.py
├── Calls:
│   ├── nasr_navdata_updater.py → FAA NASR download
│   ├── update_playbook_routes.py → Playbook scraping
│   └── Database imports
├── Database: VATSIM_REF, VATSIM_ADL
├── Frequency: Per AIRAC cycle (28 days)
└── Depth: 4

daily_event_update.py
├── Calls: fetch_new_events.py
├── Database: VATSIM_STATS
├── Frequency: Daily
└── Depth: 2

build_sector_boundaries.py
├── Input: CRC boundary files
├── Database: VATSIM_GIS
├── Frequency: On deployment
└── Depth: 2
```

---

## 9. Full Dependency Trees

### 9.1 Complete Flow: User Creates GDT Program

```
User clicks "Create GDT Program" on gdt.php
│
├─[Depth 1] gdt.php (frontend page)
│   └── Loads: gdt.js, gdp.js
│
├─[Depth 2] JavaScript: gdt.js
│   └── fetch('api/gdt/programs/create.php', {method: 'POST', ...})
│
├─[Depth 3] api/gdt/programs/create.php
│   ├── require: api/gdt/common.php
│   │   └── require: load/config.php, load/connect.php
│   ├── get_conn_adl() → VATSIM_ADL
│   ├── get_conn_tmi() → VATSIM_TMI
│   └── INSERT INTO gdt_programs
│
├─[Depth 4] Database Operations
│   ├── VATSIM_TMI: gdt_programs table
│   └── VATSIM_ADL: Flight queries
│
└─[Depth 5] (If publishing to Discord)
    ├── TMIDiscord::postNtmlEntry()
    │   ├── DiscordAPI::sendEmbed()
    │   └── Discord REST API call
    └── External: Discord servers
```

### 9.2 Complete Flow: TMI Advisory Publishing

```
User clicks "Publish Advisory" on tmi-publish.php
│
├─[Depth 1] tmi-publish.php
│   └── Loads: tmi-publish.js, tmi-gdp.js
│
├─[Depth 2] JavaScript: tmi-gdp.js
│   ├── fetch('api/mgt/tmi/advisory-number.php') → Get next number
│   └── fetch('api/gdt/programs/publish.php', {method: 'POST'})
│
├─[Depth 3] api/gdt/programs/publish.php
│   ├── require: api/gdt/common.php
│   │   └── require: load/connect.php
│   ├── get_conn_tmi() → VATSIM_TMI
│   └── Calls TMI publishing logic
│
├─[Depth 4] TMI Publishing
│   ├── api/tmi/helpers.php → TmiResponse, TmiAuth
│   ├── INSERT INTO tmi_entries
│   └── Trigger Discord notification
│
├─[Depth 5] Discord Integration
│   ├── load/discord/TMIDiscord.php
│   │   ├── buildNTMLMessageFromEntry()
│   │   └── postNtmlEntry()
│   ├── load/discord/MultiDiscordAPI.php
│   │   └── postNtmlEntryToOrgs()
│   └── load/discord/DiscordAPI.php
│       └── sendEmbed()
│
└─[Depth 6] External
    └── Discord API (message posted to channels)
```

### 9.3 Complete Flow: ADL Flight Query

```
User views NOD map on nod.php
│
├─[Depth 1] nod.php
│   └── Loads: nod.js, nod-demand-layer.js
│
├─[Depth 2] JavaScript: nod.js
│   └── fetch('api/adl/current.php')
│
├─[Depth 3] api/adl/current.php
│   ├── require: load/connect.php
│   │   └── require: load/config.php, load/input.php
│   ├── require: api/adl/AdlQueryHelper.php
│   ├── $helper = new AdlQueryHelper()
│   ├── $helper->buildCurrentFlightsQuery()
│   └── get_conn_adl() → Execute query
│
├─[Depth 4] Database
│   ├── VATSIM_ADL
│   └── Tables: adl_flight_core, adl_flight_position,
│       adl_flight_plan, adl_aircraft_type, adl_flight_times
│
└─[Depth 5] (If boundaries needed)
    ├── load/services/GISService.php
    ├── get_conn_gis() → VATSIM_GIS (PostgreSQL)
    └── PostGIS spatial queries
```

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| **Frontend Pages** | 20+ |
| **JavaScript Files** | 43 |
| **API Endpoints** | 368 |
| **Shared Libraries** | 18 |
| **Database Connections** | 6 (5 lazy + 1 eager) |
| **Daemon Scripts** | 8 |
| **Scheduled Tasks** | 10+ |
| **Migration Files** | 100+ |
| **Max Dependency Depth** | 6 (TMI → Discord) |
| **Average Depth** | 3-4 |

---

## Quick Reference: Finding Dependencies

**To find what calls an API:**
1. Search JavaScript files for the API path
2. Check other PHP files for `include`/`require`
3. Look in daemon/script files

**To find what an API depends on:**
1. Check `require`/`include` statements at top of file
2. Look for `get_conn_*()` calls for database dependencies
3. Check for helper class instantiation

**To trace a complete flow:**
1. Start from frontend page
2. Find JavaScript file loaded
3. Search for `fetch()` or `$.ajax()` calls
4. Follow API endpoint
5. Check shared library usage
6. Identify database tables/stored procs
