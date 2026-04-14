# Architecture

> **Version:** v19 | **Last Updated:** March 14, 2026

PERTI is a multi-tier web application that processes real-time VATSIM flight data and provides traffic flow management tools. The system uses 7 databases across 3 database engines (MySQL, Azure SQL, PostgreSQL/PostGIS) and runs on Azure App Service with PHP 8.2.

---

## System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        EXTERNAL DATA SOURCES                         │
├─────────────┬─────────────┬─────────────┬─────────────┬─────────────┤
│ VATSIM API  │ Aviation WX │ NOAA NOMADS │ FAA NASR    │ VATUSA API  │
│ (Live Data) │ (SIGMET)    │ (Wind)      │ (NavData)   │ (Events)    │
└──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┘
       │             │             │             │             │
       ▼             ▼             ▼             ▼             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                          IMPORT LAYER                                │
│  atis_daemon.py │ import_wx.php │ import_wind.php │ nasr_updater.py │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      AZURE SQL (VATSIM_ADL)                          │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐    │
│  │ adl_flights │ │ adl_trajectories │ │ adl_zones │ │ adl_weather │    │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘    │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   PROCESSING LAYER (Stored Procedures)               │
│  sp_ParseRoute │ sp_CalculateETA │ sp_ProcessZone │ sp_ProcessBoundary │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       MySQL (PERTI Application)                      │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐    │
│  │ Plans       │ │ Initiatives │ │ Ground Stops│ │ User Config │    │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘    │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                           API LAYER (PHP)                            │
│  /api/adl │ /api/tmi │ /api/jatoc │ /api/nod │ /api/demand │ ...   │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                            │
│  GDT │ Route Plotter │ Playbook │ JATOC │ NOD │ Plan │ Splits │ Demand │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Component Details

### Import Layer

Daemons that fetch and ingest external data:

| Daemon | Language | Interval | Purpose |
|--------|----------|----------|---------|
| `vatsim_adl_daemon.php` | PHP | 15s | Live flight data from VATSIM |
| `atis_daemon.py` | Python | 15s | ATIS text with runway/weather parsing |
| `parse_queue_daemon.php` | PHP | 5s | Route expansion queue processing |
| `import_weather_alerts.php` | PHP | 5min | SIGMET/AIRMET updates |
| `swim_tmi_sync_daemon.php` | PHP | 5min | TMI/CDM/flow data sync to SWIM_API |
| `refdata_sync_daemon.php` | PHP | Daily | Reference data sync (airports, CDRs, taxi times) |

### Database Architecture (7 Databases, 3 Engines)

The system spans 7 databases across 3 database engines:

| Database | Engine | Purpose |
|----------|--------|---------|
| `perti_site` | MySQL 8 | Main web app (plans, users, configs, staffing) |
| `VATSIM_ADL` | Azure SQL | Flight data (normalized 8-table architecture) |
| `VATSIM_TMI` | Azure SQL | Traffic management initiatives (GDP/GS/reroutes) |
| `VATSIM_REF` | Azure SQL | Reference data (airports, airways, navdata) |
| `SWIM_API` | Azure SQL | Public API database (FIXM-aligned schema) |
| `VATSIM_GIS` | PostgreSQL/PostGIS | Spatial queries (boundary intersection, route geometry) |
| `VATSIM_STATS` | Azure SQL | Statistics & analytics |

Database connections are managed in `load/connect.php` with lazy-loading getters:

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

### Azure SQL (ADL Database)

Stores real-time and historical flight data:

| Table | Purpose |
|-------|---------|
| `adl_flights` | Current flight state |
| `adl_flights_history` | Historical snapshots |
| `adl_trajectories` | Flight trajectory points |
| `adl_parse_queue` | Routes pending expansion |
| `adl_parsed_routes` | Expanded route waypoints |
| `adl_zones` | Airport zone boundaries |
| `adl_boundaries` | ARTCC/sector boundaries |
| `adl_weather_alerts` | Active SIGMETs/AIRMETs |
| `adl_atis` | ATIS data with weather |

### Processing Layer

SQL Server stored procedures for data processing:

| Category | Key Procedures |
|----------|----------------|
| **Route Parsing** | `sp_ParseRoute`, `sp_ParseQueue`, `sp_RouteDistanceBatch` |
| **ETA Calculation** | `sp_CalculateETA`, `sp_CalculateETABatch`, `sp_ProcessTrajectoryBatch` |
| **Zone Detection** | `sp_ProcessZoneDetectionBatch`, `sp_DetectZoneTransition` |
| **Boundary Crossing** | `sp_ProcessBoundaryDetectionBatch` |
| **Ground Stops** | `sp_GS_Create`, `sp_GS_Model`, `sp_GS_IssueEDCTs` |
| **Rate Management** | `sp_GetRateSuggestion`, `sp_ApplyManualRateOverride` |

### MySQL (Application Database — `perti_site`)

Stores user-facing application data:

| Table | Purpose |
|-------|---------|
| `p_plans` | Planning worksheets |
| `p_configs` | Airport configs per plan |
| `p_terminal_init` / `p_enroute_init` | TMI initiative definitions |
| `tmi_ground_stops` | Ground stop programs |
| `r_tmr_reports` | Traffic Management Review reports |
| `r_scores` / `r_comments` / `r_data` | Post-event review data |
| `jatoc_incidents` | JATOC incidents |
| `dcc_advisories` | DCC advisories |
| `splits_configs` / `splits_positions` | Sector configuration |
| `playbook_plays` / `playbook_routes` | Playbook play and route catalog |
| `playbook_changelog` | Playbook audit trail |
| `users` / `admin_users` | User accounts and preferences |

### API Layer

RESTful PHP endpoints organized by function:

| Endpoint Group | Purpose |
|----------------|---------|
| `/api/adl/` | Flight data queries |
| `/api/tmi/` | TMI operations (GS, GDP, reroutes) |
| `/api/jatoc/` | Incident CRUD |
| `/api/nod/` | Dashboard data |
| `/api/demand/` | Demand analysis |
| `/api/routes/` | Public route sharing |
| `/api/splits/` | Sector configuration |
| `/api/data/playbook/` | Playbook play/route data |
| `/api/mgt/playbook/` | Playbook CRUD management |
| `/api/data/` | Reference data (weather, SUA, TFR) |

---

## Authentication Flow

```
┌──────────┐     ┌──────────────┐     ┌─────────────────┐
│  User    │────▶│ /login/      │────▶│ VATSIM Connect  │
│  Browser │     │ index.php    │     │ OAuth Server    │
└──────────┘     └──────────────┘     └────────┬────────┘
                                               │
                                               ▼
┌──────────┐     ┌──────────────┐     ┌─────────────────┐
│  Session │◀────│ /login/      │◀────│ Authorization   │
│  Created │     │ callback.php │     │ Code + Token    │
└──────────┘     └──────────────┘     └─────────────────┘
```

Session variables set:
- `VATSIM_CID` - VATSIM Certificate ID
- `VATSIM_FIRST_NAME` / `VATSIM_LAST_NAME` - User name
- Role-based permissions from database

---

## Data Flow: Flight Processing

```
1. VATSIM API Response (JSON)
       │
       ▼
2. vatsim_adl_daemon.php
       │
       ├──▶ Delta detection: set change_flags per flight (V9.3.0)
       │
       ├──▶ sp_Adl_RefreshFromVatsim_Staged (@defer_expensive=1)
       │           │
       │           ├──▶ INSERT/UPDATE normalized 8-table flight data
       │           │    (heartbeat flights: timestamps only; changed: full processing)
       │           ├──▶ INSERT trajectory points (always, not filtered by change_flags)
       │           └──▶ Queue routes for parsing
       │
       ├──▶ Deferred ETA processing (time-budget permitting)
       │           │
       │           ├──▶ sp_ProcessTrajectoryBatch (ETA only)
       │           ├──▶ sp_CalculateETABatch (wind-adjusted ETA)
       │           └──▶ sp_CapturePhaseSnapshot
       │
       ▼
3. parse_queue_gis_daemon.php
       │
       ├──▶ PostGIS route parsing
       │           │
       │           ├──▶ adl_flight_waypoints (parsed waypoints)
       │           └──▶ adl_flight_plan.route_geometry
       │
       ▼
4. Additional Processing Daemons
       │
       ├──▶ boundary_gis_daemon.php (ARTCC/sector detection via PostGIS)
       ├──▶ crossing_gis_daemon.php (boundary crossing ETAs)
       └──▶ waypoint_eta_daemon.php (waypoint ETAs)
```

---

## Mapping Stack

PERTI uses **MapLibre GL JS** for primary mapping:

| Component | Purpose |
|-----------|---------|
| MapLibre GL JS | WebGL-based vector map rendering |
| Custom tile sources | ARTCC/TRACON/sector boundaries |
| IEM Tile Server | NEXRAD/MRMS weather radar |
| GeoJSON layers | SUA, TFR, flight tracks |

**Coordinate System:** WGS84 (EPSG:4326)

---

## External Integrations

| Service | Purpose | Update Frequency |
|---------|---------|------------------|
| VATSIM Data API | Live flight positions | 15 seconds |
| VATSIM Connect | OAuth authentication | On-demand |
| Iowa Environmental Mesonet | Weather radar tiles | Real-time |
| FAA NASR | Navigation data | 28-day cycle |
| FAA AWC | SIGMETs, AIRMETs | 5 minutes |
| VATUSA API | Events, membership | Daily |
| Discord Webhooks | TMI notifications | On change |

---

## Internationalization (i18n) Architecture

_Added in v18._

PERTI includes a full client-side internationalization system for user-facing strings. PHP API error messages remain hardcoded English; i18n currently covers the JavaScript frontend.

### Core Components

| File | Module | Purpose |
|------|--------|---------|
| `assets/js/lib/i18n.js` | `PERTII18n` | Core translation module with `t()`, `tp()`, `formatNumber()`, `formatDate()` |
| `assets/locales/index.js` | Locale Loader | Auto-detects locale on page load, initializes translations |
| `assets/locales/en-US.json` | Translation Dictionary | 7,276 translation keys in nested JSON, auto-flattened to dot notation |
| `assets/js/lib/dialog.js` | `PERTIDialog` | SweetAlert2 wrapper with automatic i18n key resolution |

### Translation API

```javascript
// Simple translation
PERTII18n.t('common.save')                                // "Save"
PERTII18n.t('error.loadFailed', { resource: 'flights' })  // "Failed to load flights"

// Pluralization
PERTII18n.tp('flight', count)  // "1 flight" or "5 flights"

// Dialog wrapper (resolves i18n keys automatically)
PERTIDialog.success('dialog.success.saved');
PERTIDialog.error('common.error', 'error.loadFailed', { resource: 'flights' });
PERTIDialog.confirm('dialog.confirmDelete.title', 'dialog.confirmDelete.text');
PERTIDialog.toast('common.copied', 'success');
```

### Locale Detection Priority

1. URL parameter (`?locale=en-US`)
2. localStorage (`PERTI_LOCALE`)
3. Browser language (`navigator.language`)
4. Fallback: `en-US`

### Coverage

The i18n system is integrated across 30 PHP pages and 45 JavaScript modules (69% of all JS modules):

- **JS modules fully using i18n (45 modules)**: `gdt.js` (1,800+ keys), `demand.js` (450+ keys), `splits.js`, `jatoc.js`, `schedule.js`, `review.js`, `sua.js`, `weather_impact.js`, `weather_hazards.js`, `tmi-publish.js`, `dialog.js`, `phase-colors.js`, `filter-colors.js`, `playbook.js`, `fir-scope.js`, `fir-integration.js`, `route-maplibre.js`, `nod.js`, `tmi_compliance.js`, `gdp.js`, `cdm.js`, `plan.js`, `sheet.js`, `reroute.js`, `public-routes.js`, and 20+ more
- **JS modules with no i18n (20 modules)**: Data-only modules (`awys.js`, `cycle.js`, `procs.js`, `facility-hierarchy.js`, etc.) - no user-facing strings
- **PHP pages**: All 30 pages auto-include i18n via `load/header.php`

**Supported locales**: `en-US` (full), `fr-CA` (near-complete French Canadian), `en-CA` (overlay), `en-EU` (overlay)

---

## Traffic Management Review (TMR)

_Added in v18._

The Traffic Management Review system provides structured post-event analysis based on the FAA NTMO Guide format. TMR reports replace the earlier freeform review workflow with a guided, section-by-section assessment.

### Report Structure

TMR reports use sidebar navigation with the following sections:

| Section | Purpose |
|---------|---------|
| **Triggers** | What triggered the event or TMI activity |
| **Overview** | High-level summary of the operational period |
| **Airport Conditions** | Runway configs, AAR/ADR, weather impacts |
| **Weather** | Weather conditions affecting operations |
| **TMIs** | Traffic Management Initiatives issued during the period |
| **Equipment** | Equipment status and outages |
| **Personnel** | Staffing levels and noteworthy actions |
| **Findings** | Lessons learned, recommendations |

### API Endpoints

| Endpoint | Purpose |
|----------|---------|
| `api/data/review/tmr_report.php` | CRUD API with auto-save for TMR report sections |
| `api/data/review/tmr_tmis.php` | Historical TMI lookup for populating the TMIs section |

### Features

- **Auto-save**: Report sections are saved automatically as users edit
- **Bulk NTML paste parser**: Paste raw NTML log text to auto-populate the TMI section
- **Discord export**: Export completed TMR reports for posting to Discord channels
- **Historical TMI lookup**: Query past TMI programs and advisories by date range

### Database

TMR reports are stored in the `r_tmr_reports` table in the `perti_site` MySQL database.

---

## NOD Facility Flow Configuration

_Added in v18._

The NOD (North Atlantic Organized Track display) includes a facility flow configuration system for defining and monitoring traffic flow elements at ARTCC boundaries. This powers the FEA (Flow Evaluation Area) demand monitoring on the NOD map.

### Database Tables (Azure SQL — VATSIM_ADL)

| Table | Purpose |
|-------|---------|
| `facility_flow_configs` | Top-level flow configurations per facility |
| `facility_flow_elements` | Individual flow elements (fixes, procedures, routes, gates) |
| `facility_flow_gates` | Gate definitions with geographic coordinates |

### Flow Element Types

| Type | Description |
|------|-------------|
| **Fix** | Navigation fixes used as metering or boundary crossing points |
| **Procedure** | DPs/STARs that define flow paths |
| **Route** | Named routes or airway segments |
| **Gate** | Geographic gate lines for counting traffic flow |

### API Endpoints

CRUD APIs are provided for all three entity types, plus:

| Endpoint | Purpose |
|----------|---------|
| `api/nod/fea.php` | FEA bridge API for demand monitoring integration |
| Config/Element/Gate APIs | Full CRUD for flow configuration management |
| Suggestion API | Auto-suggest flow elements based on facility boundaries |

### Map Layers

The NOD map renders 8 layers for flow visualization:

1. Boundary polygons (ARTCC/FIR outlines)
2. Procedure lines (DP/STAR route traces)
3. Route lines (named routes and airways)
4. Fix markers (navigation fix positions)
5. Gate lines (geographic counting gates)
6. Demand heat overlay
7. Traffic flow arrows
8. Label annotations

---

## Performance Optimizations

_Added in v18._

### PERTI_MYSQL_ONLY Connection Optimization

Endpoints that only need the MySQL `perti_site` database can skip the 5 eager Azure SQL connection initializations by defining a constant before including `connect.php`:

```php
include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
```

This skips the initialization of `$conn_adl`, `$conn_swim`, `$conn_tmi`, `$conn_ref`, and `$conn_gis`, saving approximately **500-1000ms per request**. The optimization has been applied to **~98 endpoints** across:

- `api/data/plans/` — Plan data endpoints
- `api/data/sheet/` — Planning sheet data
- `api/data/review/` — Review data
- Most `api/mgt/` plan management endpoints

The `$conn_*` globals remain `null` when skipped, so any code checking them sees falsy values. Endpoints that use Azure SQL connections (`$conn_adl`, `$conn_tmi`, `$conn_swim`, `$conn_ref`, `$conn_gis`) must NOT use this flag.

### PERTI_SWIM_ONLY Connection Optimization

_Added in v19._

SWIM API endpoints define `PERTI_SWIM_ONLY` before including `connect.php` to skip all non-SWIM database connections:

```php
define('PERTI_SWIM_ONLY', true);
require_once __DIR__ . '/../../../load/connect.php';
```

This skips `$conn_pdo`, `$conn_sqli` (MySQL), `$conn_adl`, `$conn_tmi`, `$conn_ref`, and `$conn_gis` — only `$conn_swim` is eagerly connected. Saves approximately **500-1000ms per SWIM API request**.

Applied automatically to all SWIM endpoints via `api/swim/v1/auth.php`, which defines `PERTI_SWIM_ONLY` before loading `connect.php`. Endpoints that need TMI or ADL connections for write operations (e.g., `cdm/readiness.php` POST) use the lazy-loading getters `get_conn_tmi()` / `get_conn_adl()` on demand.

### Frontend API Parallelization

Page-load API calls are batched using `Promise.all()` to eliminate sequential request waterfalls:

| Page | Parallel Calls | Module |
|------|---------------|--------|
| Plan page | 16 API calls | `plan.js` |
| Sheet page | 5 API calls | `sheet.js` |
| Review page | 3 API calls | `review.js` |

This reduces perceived load time significantly on pages that fetch data from many independent endpoints.

---

## SWIM Data Isolation

_Added in v19._

All SWIM API endpoints query exclusively from the `SWIM_API` database. Internal databases (`VATSIM_TMI`, `VATSIM_ADL`, `VATSIM_REF`, `perti_site`) are never accessed directly by API request handlers. Data flows into SWIM_API via sync daemons:

```
┌──────────────────────────────────────────────────────────────────────┐
│                    INTERNAL DATABASES                                 │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐    │
│  │ VATSIM_ADL │  │ VATSIM_TMI │  │ VATSIM_REF │  │   MySQL    │    │
│  └─────┬──────┘  └─────┬──────┘  └─────┬──────┘  └─────┬──────┘    │
│        │               │               │               │            │
│  ┌─────▼──────────────▼───────────────▼───────────────▼──────┐     │
│  │              SYNC DAEMONS (app-level watermark sync)        │     │
│  │  swim_sync_daemon (2min) │ swim_tmi_sync_daemon (5min)     │     │
│  │  refdata_sync_daemon (daily 06:00Z)                         │     │
│  └─────────────────────────┬──────────────────────────────────┘     │
│                             │                                        │
│                             ▼                                        │
│             ┌──────────────────────────────────┐                    │
│             │   SWIM_API (Azure SQL Basic)      │                    │
│             │ swim_flights (219+ cols)          │                    │
│             │ swim_tmi_* (10 mirror tables)     │                    │
│             │ swim_cdm_* (4 mirror tables)      │                    │
│             │ swim_airports, swim_airport_*     │                    │
│             │ swim_change_feed (event log)      │                    │
│             └──────────────┬───────────────────┘                    │
│                             │                                        │
└─────────────────────────────┼────────────────────────────────────────┘
                              │
                              ▼
              ┌──────────────────────────────┐
              │      SWIM API ENDPOINTS       │
              │  flights, positions, flight   │
              │  tmi/*, cdm/*, reference/*    │
              │  playbook/*, routes/*, keys/* │
              │  WebSocket (port 8090)        │
              └──────────────────────────────┘
```

### Sync Daemons

| Daemon | Script | Interval | Source | Targets |
|--------|--------|----------|--------|---------|
| Flight Sync | `swim_sync_daemon.php` | 2 min | VATSIM_ADL | `swim_flights` (row-hash skip for DTU optimization) |
| TMI Sync | `swim_tmi_sync_daemon.php` | 5 min | VATSIM_TMI + VATSIM_ADL | `swim_ntml`, `swim_tmi_programs`, `swim_tmi_entries`, `swim_tmi_advisories`, `swim_tmi_reroutes`, `swim_tmi_flow_*`, `swim_cdm_*`, `swim_tmi_flight_control` |
| Reference Sync | `refdata_sync_daemon.php` | Daily 06:00Z | VATSIM_ADL + MySQL | `swim_airports`, `swim_airport_taxi_reference`, `swim_playbook_route_throughput`, `swim_coded_departure_routes` |

### Mirror Tables in SWIM_API

| Category | Tables | Source DB |
|----------|--------|-----------|
| TMI Programs | `swim_ntml`, `swim_tmi_programs`, `swim_tmi_entries`, `swim_tmi_advisories` | VATSIM_TMI + VATSIM_ADL |
| TMI Reroutes | `swim_tmi_reroutes`, `swim_tmi_reroute_routes`, `swim_tmi_reroute_flights`, `swim_tmi_reroute_compliance_log` | VATSIM_TMI |
| TMI Flow | `swim_tmi_flow_providers`, `swim_tmi_flow_events`, `swim_tmi_flow_event_participants`, `swim_tmi_flow_measures` | VATSIM_TMI |
| CDM | `swim_cdm_messages`, `swim_cdm_pilot_readiness`, `swim_cdm_compliance_live`, `swim_cdm_airport_status` | VATSIM_TMI |
| Reference | `swim_airports`, `swim_airport_taxi_reference`, `swim_airport_taxi_reference_detail`, `swim_playbook_route_throughput` | VATSIM_ADL + MySQL |
| Infrastructure | `swim_change_feed`, `swim_sync_watermarks`, `swim_sync_state` | Internal |

### Change Feed

The `swim_change_feed` table provides a monotonic event log for multi-consumer replay. Consumers track their position via `swim_sync_watermarks`. This enables WebSocket fan-out and future external delta streaming without direct database polling.

### Write Operations

CDM write operations (readiness updates, message queuing) still write directly to `VATSIM_TMI` via lazy-loaded connections. The reverse sync daemon (`swim_adl_reverse_sync_daemon.php`) propagates changes back to `VATSIM_ADL`.

---

## Infrastructure & Costs

### Azure Resources

| Resource | Tier / SKU | Monthly Cost (approx.) |
|----------|-----------|----------------------|
| `VATSIM_ADL` (Azure SQL) | Hyperscale Serverless 3/16 vCores | ~$3,200/mo |
| `perti_site` (MySQL Flexible) | D2ds_v4 General Purpose | ~$134/mo |
| `VATSIM_GIS` (PostgreSQL Flexible) | Burstable B1ms | ~$58/mo |
| Azure App Service | P1v2 (3.5GB RAM) | ~$108/mo |
| **Total** | | **~$3,500/mo** |

The largest cost driver is the VATSIM_ADL Hyperscale Serverless database, which scales between 3 and 16 vCores based on demand. The PostgreSQL GIS database was added to offload spatial queries (boundary intersection, route geometry) from Azure SQL.

---

## Hibernation Mode

> **Status:** ACTIVE since March 22, 2026 (SWIM exempt)

During hibernation, the system operates in reduced capacity. SWIM pages and API endpoints remain operational during hibernation. All SWIM sync daemons continue running.

| Component | Normal | Hibernated |
|-----------|--------|------------|
| ADL Ingest Daemon | Active (15s cycle) | Active (core only) |
| Route Parsing | Active | Suspended |
| Boundary Detection | Active | Suspended |
| Crossing Calculation | Active | Suspended |
| Waypoint ETA | Active | Suspended |
| SWIM Sync | Active (2min) | Active (SWIM exempt) |
| TMI Sync | Active (5min) | Active (SWIM exempt) |
| Web Pages | Full access | Redirect to /hibernation |
| SWIM Pages | Full access | Active (SWIM exempt) |
| SWIM API | Active | Active (SWIM exempt) |
| Azure SQL (ADL) | Hyperscale 3-16 vCores | Min 1, Max 4 |
| MySQL | D2ds_v4 | B1ms (downscaled) |
| PostgreSQL/PostGIS | B2s | B2s (kept for TMI Compliance) |

**Monthly cost during hibernation:** ~$50-80 (vs ~$3,500 normal)

**Backfill status:** Phase 3 of 6 in progress (crossing calculations)

See `docs/operations/HIBERNATION_RUNBOOK.md` for entry/exit procedures.

---

## See Also

- [[Data Flow]] - Detailed data flow diagrams
- [[Database Schema]] - Complete schema documentation
- [[API Reference]] - API endpoint details
