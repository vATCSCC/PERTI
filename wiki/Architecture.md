# Architecture

> **Version:** v18 | **Last Updated:** February 10, 2026

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
│  GDT │ Route Plotter │ JATOC │ NOD │ Plan │ Splits │ Demand         │
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
       ├──▶ sp_Adl_RefreshFromVatsim_Staged (@defer_expensive=1)
       │           │
       │           ├──▶ INSERT/UPDATE normalized 8-table flight data
       │           ├──▶ INSERT trajectory points (always, even when deferred)
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
| `assets/locales/en-US.json` | Translation Dictionary | 450+ keys in nested JSON, auto-flattened to dot notation |
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

The i18n system is integrated across 13 JavaScript modules. Key modules with full coverage include `tmi-publish.js` (50+ keys), `dialog.js`, `phase-colors.js`, and `filter-colors.js`. Modules not yet migrated include `demand.js`, `route-maplibre.js`, `gdt.js`, `nod.js`, `jatoc.js`, `splits.js`, `reroute.js`, `schedule.js`, `review.js`, `sheet.js`, `sua.js`, and `weather_impact.js`. Only `en-US` is currently supported; the infrastructure supports additional locales.

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

### Frontend API Parallelization

Page-load API calls are batched using `Promise.all()` to eliminate sequential request waterfalls:

| Page | Parallel Calls | Module |
|------|---------------|--------|
| Plan page | 16 API calls | `plan.js` |
| Sheet page | 5 API calls | `sheet.js` |
| Review page | 3 API calls | `review.js` |

This reduces perceived load time significantly on pages that fetch data from many independent endpoints.

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

## See Also

- [[Data Flow]] - Detailed data flow diagrams
- [[Database Schema]] - Complete schema documentation
- [[API Reference]] - API endpoint details
