# PERTI System Status Dashboard

> **Last Updated:** 2026-01-17
> **System Version:** v17 - Main Branch

---

## Quick Health Overview

| Component | Status | Description |
|-----------|--------|-------------|
| ADL Flight Processing | [OK] Active | Core flight data pipeline |
| Route Parsing | [OK] Active | Route expansion and waypoint extraction |
| ETA Calculation | [OK] Active | Trajectory prediction system |
| Zone Detection | [OK] Active | OOOI airport zone monitoring |
| Boundary Detection | [OK] Active | ARTCC/sector crossing detection |
| Weather Integration | [OK] Active | SIGMET/AIRMET monitoring |
| ATIS Import | [OK] Active | Runway assignment parsing with weather extraction |
| Event Statistics | [OK] Active | VATUSA event tracking |
| **Demand Analysis (NEW v16)** | [OK] Active | Airport demand/capacity visualization |
| **Airport Config (NEW v16)** | [OK] Active | Runway configuration & rate management |
| **Rate Suggestions (NEW v16)** | [OK] Active | Weather-aware AAR/ADR recommendations |
| **ATFM Simulator (NEW v17)** | [DEV] Phase 0 | TMU training simulator with Node.js flight engine |
| **SWIM API (NEW v17)** | [DEV] Phase 1 | System Wide Information Management API |
| **TMI Database (NEW v17)** | [OK] Deployed | Unified TMI database (VATSIM_TMI) |

---

## SWIM API Subsystem (NEW v17)

> **Documentation:** [docs/swim/](./swim/)  
> **Status:** Phase 0 - Infrastructure Migration (BLOCKING)

SWIM (System Wide Information Management) provides centralized flight data exchange across the VATSIM ecosystem.

### ⚠️ Infrastructure Migration Required

**Current Problem:** API endpoints query VATSIM_ADL Serverless directly = expensive under load ($500-7,500+/mo)

**Solution:** Create dedicated `SWIM_API` database (Azure SQL Basic, **$5/month fixed**)

```
┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
│    VATSIM_ADL       │      │     SWIM_API        │      │    Public API       │
│  (Serverless $$)   │─────▶│   (Basic $5/mo)     │─────▶│    Endpoints        │
│  Internal only      │ sync │  Dedicated for API  │      │                     │
└─────────────────────┘ 15s  └─────────────────────┘      └─────────────────────┘
```

### Blocking Tasks

| Task | Status | Notes |
|------|--------|-------|
| Create Azure SQL Basic `SWIM_API` | [ERR] Blocking | $5/mo fixed cost |
| Run `002_swim_api_database.sql` | [ERR] Blocking | Creates swim_flights table |
| Add `$conn_swim` to config | [ERR] Blocking | New connection string |
| Update endpoints to use SWIM_API | [ERR] Blocking | Change from `$conn_adl` |
| Schedule sync (every 15 sec) | [ERR] Blocking | After ADL refresh |

### API Endpoints

| Endpoint | Status | Description |
|----------|--------|-------------|
| `GET /api/swim/v1` | [OK] Working | API info/router |
| `GET /api/swim/v1/flights` | [WARN] Needs DB switch | List flights (1,100+ active) |
| `GET /api/swim/v1/flight` | [WARN] Needs DB switch | Single flight lookup |
| `GET /api/swim/v1/positions` | [WARN] Needs DB switch | Bulk positions (GeoJSON) |
| `GET /api/swim/v1/tmi/programs` | [ERR] 500 Error | Active TMI programs |
| `GET /api/swim/v1/tmi/controlled` | [WARN] Needs DB switch | TMI-controlled flights |
| `POST /api/swim/v1/ingest/adl` | [OK] Working | ADL data ingest |

### SWIM Files

| File | Location | Status |
|------|----------|--------|
| Configuration | `load/swim_config.php` | [WARN] Needs SWIM_API connection |
| Auth Middleware | `api/swim/v1/auth.php` | [WARN] Needs DB switch |
| Flights Endpoint | `api/swim/v1/flights.php` | [WARN] Needs DB switch |
| Flight Endpoint | `api/swim/v1/flight.php` | [WARN] Needs DB switch |
| Positions Endpoint | `api/swim/v1/positions.php` | [WARN] Needs DB switch |
| TMI Programs | `api/swim/v1/tmi/programs.php` | [ERR] 500 error |
| TMI Controlled | `api/swim/v1/tmi/controlled.php` | [WARN] Needs DB switch |
| ADL Ingest | `api/swim/v1/ingest/adl.php` | [OK] Complete |
| DB Migration (existing) | `database/migrations/swim/001_swim_tables.sql` | [OK] Deployed to VATSIM_ADL |
| DB Migration (new) | `database/migrations/swim/002_swim_api_database.sql` | [ERR] Not deployed |

### SWIM Database Architecture

| Database | Purpose | Tier | Cost | API Access |
|----------|---------|------|------|------------|
| **VATSIM_ADL** | Internal ADL processing | Serverless | Variable | ❌ No (internal only) |
| **SWIM_API** | Public API queries | Basic | $5/mo fixed | ✅ Yes |
| **MySQL (PERTI)** | Ground stops, site data | Existing | Already paid | ✅ Yes |

### Cost Comparison

| API Traffic | Direct VATSIM_ADL | Dedicated SWIM_API |
|-------------|-------------------|-------------------|
| 10K req/day | ~$15-45/mo | **$5/mo** |
| 100K req/day | ~$150-450/mo | **$5/mo** |
| 1M req/day | ~$1,500-4,500/mo | **$5/mo** |

---

## TMI Database Subsystem (NEW v17)

> **Documentation:** [docs/tmi/](./tmi/)  
> **Status:** ✅ Deployed & Live (January 17, 2026)

Unified Traffic Management Initiative database consolidating NTML entries, Advisories, GDT Programs, Reroutes, and Public Routes.

### Database Info

| Setting | Value |
|---------|-------|
| **Server** | `vatsim.database.windows.net` |
| **Database** | `VATSIM_TMI` |
| **Username** | `TMI_admin` |
| **Tier** | Basic (5 DTU, 2 GB) |
| **Cost** | ~$5/month |

### Database Architecture

```
Azure SQL Server: vatsim.database.windows.net
├── VATSIM_ADL    ($15/mo)  - Flight data
├── SWIM_API     ($5/mo)   - Public API  
└── VATSIM_TMI   ($5/mo)   - TMI data ✅ NEW
                 ─────────
                 Total: ~$25/mo
```

### Database Objects

| Object Type | Count | Status |
|-------------|-------|--------|
| Tables | 10 | ✅ Verified |
| Views | 6 | ✅ Verified |
| Stored Procedures | 4 | ✅ Verified |
| Indexes | 30+ | ✅ Verified |

### TMI API Endpoints (Live ✅)

| Endpoint | Status | Description |
|----------|--------|-------------|
| `GET /api/tmi/` | [OK] Live | API info and endpoints |
| `GET /api/tmi/active.php` | [OK] Live | All active TMI data |
| `GET/POST/PUT/DELETE /api/tmi/entries.php` | [OK] Live | NTML entries CRUD |
| `GET/POST/PUT/DELETE /api/tmi/programs.php` | [OK] Live | GDT programs CRUD |
| `GET/POST/PUT/DELETE /api/tmi/advisories.php` | [OK] Live | Advisories CRUD |
| `GET/POST/PUT/DELETE /api/tmi/public-routes.php` | [OK] Live | Public routes CRUD |
| `GET/POST/PUT/DELETE /api/tmi/reroutes.php` | [ERR] Not created | Reroutes CRUD |

### TMI Files

| File | Location | Status |
|------|----------|--------|
| API Helpers | `api/tmi/helpers.php` | [OK] Deployed |
| Index Endpoint | `api/tmi/index.php` | [OK] Deployed |
| Active Endpoint | `api/tmi/active.php` | [OK] Deployed |
| Entries Endpoint | `api/tmi/entries.php` | [OK] Deployed |
| Programs Endpoint | `api/tmi/programs.php` | [OK] Deployed |
| Advisories Endpoint | `api/tmi/advisories.php` | [OK] Deployed |
| Public Routes Endpoint | `api/tmi/public-routes.php` | [OK] Deployed |
| URL Rewriting | `api/tmi/.htaccess`, `web.config` | [OK] Deployed |
| Verification Script | `scripts/tmi/verify_deployment.php` | [OK] Deployed |
| Migration Script | `database/migrations/tmi/001_tmi_core_schema_azure_sql.sql` | [OK] Deployed |
| User Script | `database/migrations/tmi/002_create_tmi_user.sql` | [OK] Deployed |

### TMI Tables

| Table | Fields | Purpose |
|-------|--------|--------|
| `tmi_entries` | 35 | NTML log (MIT, MINIT, DELAY, CONFIG, APREQ, etc.) |
| `tmi_programs` | 47 | GS/GDP/AFP programs with rates, scope, exemptions |
| `tmi_slots` | 22 | GDP slot allocation (RBS algorithm) |
| `tmi_advisories` | 40 | Formal advisories (GS, GDP, AFP, Reroute, etc.) |
| `tmi_reroutes` | 45 | Reroute definitions with filtering |
| `tmi_reroute_flights` | 30 | Flight assignments to reroutes |
| `tmi_reroute_compliance_log` | 9 | Compliance history snapshots |
| `tmi_public_routes` | 21 | Public route display on map |
| `tmi_events` | 18 | Unified audit log |
| `tmi_advisory_sequences` | 2 | Advisory numbering by date |

### Remaining TMI Work

| Task | Status | Priority |
|------|--------|----------|
| Create `reroutes.php` API endpoint | [ERR] Pending | High |
| Test CRUD operations | [WARN] Pending | High |
| Update existing `gs/*.php` to use `tmi_programs` | [WARN] Pending | High |
| Discord bot integration | [WARN] Pending | Medium |
| SWIM TMI endpoints (`/api/swim/v1/tmi/`) | [WARN] Pending | Medium |

---

## Stored Procedures

### Flight Processing & Route Parsing

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_ParseRoute` | [OK] Deployed | [sp_ParseRoute.sql](../adl/procedures/sp_ParseRoute.sql) | Parses flight routes into waypoint sequences |
| `sp_ParseQueue` | [OK] Deployed | [sp_ParseQueue.sql](../adl/procedures/sp_ParseQueue.sql) | Processes queued routes for expansion |
| `sp_ParseSimBriefData` | [OK] Deployed | [sp_ParseSimBriefData.sql](../adl/procedures/sp_ParseSimBriefData.sql) | Extracts SimBrief flight plan data |
| `fn_GetParseTier` | [OK] Deployed | [fn_GetParseTier.sql](../adl/procedures/fn_GetParseTier.sql) | Determines processing tier for routes |
| `sp_RouteDistanceBatch` | [OK] Deployed | [sp_RouteDistanceBatch.sql](../adl/procedures/sp_RouteDistanceBatch.sql) | Batch route distance calculation |

### ETA & Trajectory System

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_CalculateETA` | [OK] Deployed | [sp_CalculateETA.sql](../adl/procedures/sp_CalculateETA.sql) | Computes single flight ETA |
| `sp_CalculateETABatch` | [OK] Deployed | [sp_CalculateETABatch.sql](../adl/procedures/sp_CalculateETABatch.sql) | Batch ETA calculation |
| `sp_CalculateWaypointETA` | [OK] Deployed | [sp_CalculateWaypointETA.sql](../adl/procedures/sp_CalculateWaypointETA.sql) | ETA at specific waypoints |
| `sp_CalculateWaypointETABatch_Tiered` | [OK] NEW v17 | [sp_CalculateWaypointETABatch_Tiered.sql](../adl/procedures/sp_CalculateWaypointETABatch_Tiered.sql) | Tiered waypoint ETA processing (daemon) |
| `sp_ProcessTrajectoryBatch` | [OK] Deployed | [sp_ProcessTrajectoryBatch.sql](../adl/procedures/sp_ProcessTrajectoryBatch.sql) | Batch trajectory logging |
| `sp_LogTrajectory` | [OK] Deployed | [sp_LogTrajectory.sql](../adl/procedures/sp_LogTrajectory.sql) | Records trajectory snapshots |
| `fn_GetTrajectoryTier` | [OK] Deployed | [fn_GetTrajectoryTier.sql](../adl/procedures/fn_GetTrajectoryTier.sql) | Trajectory logging frequency |
| `fn_GetAircraftPerformance` | [OK] Deployed | [fn_GetAircraftPerformance.sql](../adl/procedures/fn_GetAircraftPerformance.sql) | Aircraft performance lookup |
| `fn_GetTierIntervalSeconds` | [OK] Deployed | [fn_GetTierIntervalSeconds.sql](../adl/procedures/fn_GetTierIntervalSeconds.sql) | Logging interval by tier |

### Zone & Boundary Detection

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_ProcessZoneDetectionBatch` | [OK] Deployed | [sp_ProcessZoneDetectionBatch.sql](../adl/procedures/sp_ProcessZoneDetectionBatch.sql) | Batch airport zone detection |
| `sp_DetectZoneTransition` | [OK] Deployed | [sp_DetectZoneTransition.sql](../adl/procedures/sp_DetectZoneTransition.sql) | Individual zone detection |
| `fn_DetectCurrentZone` | [OK] Deployed | [fn_DetectCurrentZone.sql](../adl/procedures/fn_DetectCurrentZone.sql) | Current zone identification |
| `sp_ProcessBoundaryDetectionBatch` | [WARN] Modified | [sp_ProcessBoundaryDetectionBatch.sql](../adl/procedures/sp_ProcessBoundaryDetectionBatch.sql) | Batch boundary crossing detection |
| `sp_ProcessBoundaryAndCrossings_Background` | [OK] NEW v17 | [sp_ProcessBoundaryAndCrossings_Background.sql](../adl/procedures/sp_ProcessBoundaryAndCrossings_Background.sql) | Background boundary/crossing processing (daemon) |
| `sp_ImportAirportGeometry` | [OK] Deployed | [sp_ImportAirportGeometry.sql](../adl/procedures/sp_ImportAirportGeometry.sql) | Airport zone geometry import |

### Data Synchronization

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_Adl_RefreshFromVatsim_Normalized` | [OK] Deployed | [sp_Adl_RefreshFromVatsim_Normalized.sql](../adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql) | VATSIM flight data sync |
| `fn_IsFlightRelevant` | [OK] Deployed | [fn_IsFlightRelevant.sql](../adl/procedures/fn_IsFlightRelevant.sql) | Flight relevance filter |
| `diagnostic_check` | [OK] Deployed | [diagnostic_check.sql](../adl/procedures/diagnostic_check.sql) | Health check queries |

### Ground Stop / GDT (NEW v15)

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_GS_Create` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Create proposed ground stop |
| `sp_GS_Model` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Model GS (identify affected flights) |
| `sp_GS_IssueEDCTs` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Activate ground stop |
| `sp_GS_Extend` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Extend GS end time |
| `sp_GS_Purge` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Cancel/purge ground stop |
| `sp_GS_GetFlights` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Get affected flights |
| `sp_GS_DetectPopups` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Detect pop-up flights |
| `fn_HaversineNM` | [OK] Deployed | [002_gs_procedures.sql](../adl/migrations/tmi/002_gs_procedures.sql) | Great circle distance |

### Airport Configuration & Rate Management (NEW v16)

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_GetRateSuggestion` | [OK] Deployed | [085_rate_suggestion_proc.sql](../adl/migrations/085_rate_suggestion_proc.sql) | Multi-level rate suggestion algorithm |
| `sp_ImportAtis` | [OK] Deployed | [086_atis_import_proc.sql](../adl/migrations/086_atis_import_proc.sql) | ATIS batch import with weather extraction |
| `sp_DetectRunwayFromTracks` | [OK] Deployed | [087_runway_detect_proc.sql](../adl/migrations/087_runway_detect_proc.sql) | Flight-track-based runway detection |
| `sp_ApplyManualRateOverride` | [OK] Deployed | [088_manual_override_proc.sql](../adl/migrations/088_manual_override_proc.sql) | Manual rate override management |
| `sp_GetAirportConfig` | [OK] Deployed | [089_config_api_procs.sql](../adl/migrations/089_config_api_procs.sql) | Airport configuration lookup |
| `sp_SyncRunwayInUse` | [OK] Deployed | [090_runway_sync_proc.sql](../adl/migrations/090_runway_sync_proc.sql) | Runway-in-use synchronization |
| `fn_GetWeatherCategory` | [OK] Deployed | [084_weather_category_fn.sql](../adl/migrations/084_weather_category_fn.sql) | Weather category classification |

### Airspace Element Demand Functions (NEW v17)

| Function | Status | Location | Description |
|----------|--------|----------|-------------|
| `fn_FixDemand` | [OK] Deployed | [002_fn_FixDemand.sql](../adl/migrations/demand/002_fn_FixDemand.sql) | Flights at a navigation fix |
| `fn_AirwaySegmentDemand` | [OK] Deployed | [003_fn_AirwaySegmentDemand.sql](../adl/migrations/demand/003_fn_AirwaySegmentDemand.sql) | Flights on an airway segment |
| `fn_RouteSegmentDemand` | [OK] Deployed | [004_fn_RouteSegmentDemand.sql](../adl/migrations/demand/004_fn_RouteSegmentDemand.sql) | Flights between fixes (airway or DCT) |

### Removed Procedures

| Procedure | Status | Notes |
|-----------|--------|-------|
| `sp_UpsertFlight` | [X] Removed | Replaced by normalized refresh |

---

## PHP Daemons & Scripts

### Active Daemons

| Daemon | Status | Location | Interval | Purpose |
|--------|--------|----------|----------|---------|
| Parse Queue Daemon | [WARN] Modified | [parse_queue_daemon.php](../adl/php/parse_queue_daemon.php) | 5s (configurable) | Continuous route parsing |
| Waypoint ETA Daemon | [OK] NEW v17 | [waypoint_eta_daemon.php](../adl/php/waypoint_eta_daemon.php) | 15s (tiered) | Waypoint ETA calculation |
| Boundary Daemon | [OK] NEW v17 | [boundary_daemon.php](../adl/php/boundary_daemon.php) | 15s (adaptive) | ARTCC/TRACON boundary detection |

**Parse Queue Usage:**
```bash
# Continuous loop mode
php parse_queue_daemon.php --loop

# Single run with custom batch size
php parse_queue_daemon.php --batch=100

# Custom interval (seconds)
php parse_queue_daemon.php --loop --interval=10
```

**Waypoint ETA Daemon Usage:**
```bash
# Run continuously with tiered processing
php waypoint_eta_daemon.php --loop

# Process only tier 0 (imminent crossings)
php waypoint_eta_daemon.php --tier=0

# Custom interval and batch size
php waypoint_eta_daemon.php --loop --interval=15 --flights=500
```

**Boundary Daemon Usage:**
```bash
# Run continuously
php boundary_daemon.php --loop

# Custom batch size
php boundary_daemon.php --loop --flights=200 --crossings=100
```

### Import Scripts

| Script | Status | Location | Schedule | Purpose |
|--------|--------|----------|----------|---------|
| Weather Alert Import | [OK] Active | [import_weather_alerts.php](../adl/php/import_weather_alerts.php) | Every 5 min | SIGMET/AIRMET updates |
| Boundary Import | [OK] Active | [import_boundaries.php](../adl/php/import_boundaries.php) | On-demand | ARTCC/TRACON boundaries |
| Wind Data Import | [OK] Active | [import_wind_data.php](../adl/php/import_wind_data.php) | Hourly | NOAA RAP/GFS wind data |
| OSM Airport Geometry | [OK] Active | [import_osm_airport_geometry.php](../adl/php/import_osm_airport_geometry.php) | On-demand | Airport zone boundaries |
| OSM Web Import | [OK] Active | [import_osm_web.php](../adl/php/import_osm_web.php) | On-demand | Web-based OSM helper |

### Removed Scripts

| Script | Status | Notes |
|--------|--------|-------|
| `AdlFlightUpsert.php` | [X] Removed | Functionality consolidated |
| `vatsim_ingest_daemon.php` | [X] Removed | Replaced by external sync |

---

## Python Daemons & Utilities

### VATSIM ATIS System

| Component | Status | Location | Purpose |
|-----------|--------|----------|---------|
| ATIS Daemon | [OK] Active | [atis_daemon.py](../scripts/vatsim_atis/atis_daemon.py) | Primary ATIS import (15s interval) |
| VATSIM Fetcher | [OK] Active | [vatsim_fetcher.py](../scripts/vatsim_atis/vatsim_fetcher.py) | VATSIM API client |
| ATIS Parser | [OK] Active | [atis_parser.py](../scripts/vatsim_atis/atis_parser.py) | ATIS text parsing |
| Config Loader | [OK] Active | [config_loader.py](../scripts/vatsim_atis/config_loader.py) | PHP config loader |

**Usage:**
```bash
# Run once
python atis_daemon.py --once

# Filter specific airports
python atis_daemon.py --airports KJFK,KLAX,KATL

# Continuous mode (default)
python atis_daemon.py
```

### Event Statistics

| Component | Status | Location | Purpose |
|-----------|--------|----------|---------|
| Daily Event Update | [OK] Active | [daily_event_update.py](../scripts/statsim/daily_event_update.py) | Daily VATUSA sync |
| Event Fetcher | [OK] Active | [fetch_new_events.py](../scripts/statsim/fetch_new_events.py) | Event data fetcher |
| Historical Import | [OK] Active | [import_historical_events.py](../scripts/statsim/import_historical_events.py) | Historical event import |
| Event Stats | [OK] Active | [vatusa_event_stats.py](../scripts/statsim/vatusa_event_stats.py) | Statistics processor |

### Navigation & Boundary Tools

| Script | Status | Location | Purpose |
|--------|--------|----------|---------|
| NASR Updater | [OK] Active | [nasr_navdata_updater.py](../scripts/nasr_navdata_updater.py) | FAA NASR data refresh |
| Playbook Routes | [OK] Active | [update_playbook_routes.py](../scripts/update_playbook_routes.py) | FAA playbook route updater |
| Sector Boundaries | [OK] Active | [build_sector_boundaries.py](../scripts/build_sector_boundaries.py) | Sector boundary builder |

### Removed Utilities

| Script | Status | Notes |
|--------|--------|-------|
| `check_schema.py` | [X] Removed | Deployment utility cleanup |
| `deploy_archive.py` | [X] Removed | Deployment utility cleanup |
| `deploy_refresh_sp.py` | [X] Removed | Deployment utility cleanup |
| `fix_archive_columns.py` | [X] Removed | Deployment utility cleanup |
| `fix_track_proc.py` | [X] Removed | Deployment utility cleanup |
| `verify_deployment.py` | [X] Removed | Deployment utility cleanup |

---

## PowerShell Import Utilities

| Script | Status | Location | Purpose |
|--------|--------|----------|---------|
| Import-CIFPToAzure | [OK] Active | [Import-CIFPToAzure.ps1](../adl/php/Import-CIFPToAzure.ps1) | CIFP procedure import |
| Import-OSMAirportGeometry | [OK] Active | [Import-OSMAirportGeometry.ps1](../adl/php/Import-OSMAirportGeometry.ps1) | OSM boundary import |
| Import-OSMAirportGeometry-Parallel | [OK] Active | [Import-OSMAirportGeometry-Parallel.ps1](../adl/php/Import-OSMAirportGeometry-Parallel.ps1) | Parallel OSM import |
| Import-NavDataToAzure | [OK] Active | [Import-NavDataToAzure.ps1](../adl/php/Import-NavDataToAzure.ps1) | Navigation data import |
| Import-NavDataToAzure-Fast | [OK] Active | [Import-NavDataToAzure-Fast.ps1](../adl/php/Import-NavDataToAzure-Fast.ps1) | Fast bulk navdata import |
| Import-WeatherAlerts | [OK] Active | [Import-WeatherAlerts.ps1](../adl/php/Import-WeatherAlerts.ps1) | Weather alert import |
| Import-XPlaneNavData | [OK] Active | [Import-XPlaneNavData.ps1](../adl/php/Import-XPlaneNavData.ps1) | X-Plane navdata import |
| ImportOSM | [OK] Active | [ImportOSM.ps1](../adl/php/ImportOSM.ps1) | General OSM import |

---

## Database Migrations

### ADL Core System (Azure SQL)

**Location:** [adl/migrations/](../adl/migrations/)

| Category | Migrations | Status | Description |
|----------|------------|--------|-------------|
| **core/** | 6 files | [OK] Deployed | Foundation tables and views |
| **eta/** | 11 files | [OK] Deployed | ETA & trajectory system |
| **oooi/** | 8 files | [OK] Deployed | OOOI zone detection |
| **boundaries/** | 6 files | [OK] Deployed | Sector boundary management |
| **weather/** | 4 files | [OK] Deployed | Weather integration |
| **navdata/** | 5 files | [OK] Deployed | Navigation data |
| **cifp/** | 2 files | [OK] Deployed | CIFP procedures |
| **performance/** | 3 files | [OK] Deployed | Aircraft performance |
| **stats/** | 5 files | [OK] Deployed | Flight statistics |
| **changelog/** | 7 files | [OK] Deployed | Change tracking triggers |
| **demand/** | 4 files | [OK] Deployed | Airspace element demand functions (NEW v17) |

### Airport Configuration & ATIS (NEW v16)

| File | Status | Description |
|------|--------|-------------|
| [079_event_aar_from_flights.sql](../adl/migrations/079_event_aar_from_flights.sql) | [OK] Deployed | Event AAR calculation from flight data |
| [080_airport_config_schema.sql](../adl/migrations/080_airport_config_schema.sql) | [OK] Deployed | Normalized runway configuration tables |
| [081_atis_weather_columns.sql](../adl/migrations/081_atis_weather_columns.sql) | [OK] Deployed | ATIS weather extraction columns |
| [082_runway_in_use_table.sql](../adl/migrations/082_runway_in_use_table.sql) | [OK] Deployed | Runway-in-use tracking table |
| [083_detected_runway_config.sql](../adl/migrations/083_detected_runway_config.sql) | [OK] Deployed | Flight-track runway detection |
| [084_weather_category_fn.sql](../adl/migrations/084_weather_category_fn.sql) | [OK] Deployed | Weather category classification function |
| [085_rate_suggestion_proc.sql](../adl/migrations/085_rate_suggestion_proc.sql) | [OK] Deployed | Multi-level rate suggestion algorithm |
| [086_atis_import_proc.sql](../adl/migrations/086_atis_import_proc.sql) | [OK] Deployed | ATIS batch import procedure |
| [087_runway_detect_proc.sql](../adl/migrations/087_runway_detect_proc.sql) | [OK] Deployed | Runway detection from flight tracks |
| [088_manual_override_proc.sql](../adl/migrations/088_manual_override_proc.sql) | [OK] Deployed | Manual rate override management |
| [089_config_api_procs.sql](../adl/migrations/089_config_api_procs.sql) | [OK] Deployed | Airport config API procedures |
| [090_runway_sync_proc.sql](../adl/migrations/090_runway_sync_proc.sql) | [OK] Deployed | Runway-in-use synchronization |
| [091_rate_history_table.sql](../adl/migrations/091_rate_history_table.sql) | [OK] Deployed | Rate change audit trail |
| [092_config_modifiers_schema.sql](../adl/migrations/092_config_modifiers_schema.sql) | [OK] Deployed | Structured modifier system for runway configs |
| [093_config_modifiers_data_migration.sql](../adl/migrations/093_config_modifiers_data_migration.sql) | [OK] Deployed | Data migration for config modifiers |
| [094_fix_stale_atis_combination.sql](../adl/migrations/094_fix_stale_atis_combination.sql) | [OK] Deployed | ARR/DEP ATIS age validation |
| [095_atis_type_priority.sql](../adl/migrations/095_atis_type_priority.sql) | [OK] Deployed | ATIS source priority logic (ARR+DEP > COMB > single) |

### PERTI MySQL Migrations

**Location:** [database/migrations/](../database/migrations/)

| Category | Migrations | Status | Description |
|----------|------------|--------|-------------|
| **schema/** | 5 files | [OK] Deployed | Core database schema |
| **advisories/** | 3 files | [OK] Deployed | DCC/NOD advisory management |
| **gdp/** | 2 files | [OK] Deployed | Ground Delay Program tables |
| **initiatives/** | 4 files | [OK] Deployed | Initiative planning |
| **jatoc/** | 3 files | [OK] Deployed | Incident tracking |
| **reroute/** | 2 files | [OK] Deployed | Reroute definitions |
| **integration/** | 3 files | [OK] Deployed | External integrations |
| **sua/** | 3 files | [OK] Deployed | Special Use Airspace |

---

## Scheduled Tasks

| Task | Frequency | Type | Description |
|------|-----------|------|-------------|
| VATSIM ATIS Import | 15 seconds | Python Daemon | Fetch and parse runway assignments |
| Parse Queue Processing | 5 seconds | PHP Daemon | Route expansion pipeline |
| Weather Alert Import | 5 minutes | Cron/Scheduler | SIGMET/AIRMET updates |
| Daily Event Update | Daily | Cron | VATUSA event synchronization |
| Navigation Data Refresh | On-demand | Manual | FAA NASR data update |
| Boundary Import | On-demand | Manual | ARTCC/TRACON geometry refresh |

---

## CI/CD Pipeline

### Azure Pipelines

**Configuration:** [azure-pipelines.yml](../azure-pipelines.yml)

| Stage | Status | Description |
|-------|--------|-------------|
| Build | [OK] Active | PHP 8.2 setup, Composer install |
| Package | [OK] Active | Archive and artifact upload |
| Deploy | [OK] Active | Azure Web App deployment |

**Trigger:** Commits to `main` branch
**Target:** Azure Web App (vatcscc)

### GitHub Workflows

| Workflow | Status | Location |
|----------|--------|----------|
| Main Deployment | [OK] Active | [main_vatcscc.yml](../.github/workflows/main_vatcscc.yml) |
| Azure WebApp | [OK] Active | [azure-webapp-vatcscc.yml](../.github/workflows/azure-webapp-vatcscc.yml) |

---

## Data Flow Architecture

```
+-------------------------------------------------------------------------+
|                         EXTERNAL DATA SOURCES                           |
+-------------+-------------+-------------+-------------+----------------+
| VATSIM API  | Aviation WX | NOAA NOMADS | FAA NASR    | VATUSA API     |
| (Live Data) | (SIGMET)    | (Wind)      | (NavData)   | (Events)       |
+------+------+------+------+------+------+------+------+-------+--------+
       |             |             |             |              |
       v             v             v             v              v
+-------------------------------------------------------------------------+
|                          IMPORT LAYER                                    |
+-------------+-------------+-------------+-------------+----------------+
| atis_daemon | import_wx   | import_wind | nasr_updater| fetch_events   |
| (Python)    | (PHP)       | (PHP)       | (Python)    | (Python)       |
+------+------+------+------+------+------+------+------+-------+--------+
       |             |             |             |              |
       +-------------+-------------+-------------+--------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                      AZURE SQL (VATSIM_ADL)                             |
+-------------------------------------------------------------------------+
|  adl_flights  |  adl_trajectories  |  adl_parse_queue  |  adl_zones    |
|  adl_weather  |  adl_boundaries    |  adl_statistics   |  adl_atis     |
+-------------------------------------------------------------------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                       PROCESSING LAYER (Stored Procedures)              |
+-------------------------------------------------------------------------+
|  sp_ParseRoute*          |  sp_CalculateETA*       |  sp_ProcessZone*  |
|  sp_ProcessBoundary*     |  sp_LogTrajectory*      |  fn_IsRelevant    |
+-------------------------------------------------------------------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                       MySQL (PERTI Application)                         |
+-------------------------------------------------------------------------+
|  Plans & Schedules  |  Initiatives  |  Ground Stops  |  User Config    |
+-------------------------------------------------------------------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                         API LAYER (PHP)                                 |
+-------------+-------------+-------------+-------------+----------------+
|  /api/adl   |  /api/tmi   |  /api/jatoc |  /api/nod   |  /api/routes   |
|  /api/demand (NEW v16)    |  /api/splits|  /api/data  |  /api/statsim  |
+-------------+-------------+-------------+-------------+----------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                      INTEGRATIONS                                       |
+-------------------------------------------------------------------------+
|                    Discord Webhooks (TMI Sync)                          |
+-------------------------------------------------------------------------+
```

---

## Recent Changes

### Modified Files (Uncommitted)

| File | Status | Notes |
|------|--------|-------|
| `.claude/settings.local.json` | [WARN] Modified | Local settings update |
| `api/adl/AdlQueryHelper.php` | [WARN] Modified | Query improvements |
| `assets/js/nod.js` | [WARN] Modified | NOD enhancements |

### New Files (v17)

| File | Status | Notes |
|------|--------|-------|
| `simulator.php` | [DEV] In Progress | ATFM Training Simulator main page |
| `api/simulator/navdata.php` | [DEV] In Progress | Navigation data API |
| `api/simulator/engine.php` | [DEV] In Progress | Flight engine control API |
| `api/simulator/routes.php` | [DEV] In Progress | Route pattern data API |
| `api/simulator/traffic.php` | [DEV] In Progress | Traffic generation API |
| `simulator/engine/` | [DEV] In Progress | Node.js flight engine |
| `adl/migrations/sim_ref_*.sql` | [OK] Created | Simulator reference data tables |
| `docs/ATFM_Simulator_Design_Document_v1.md` | [OK] Created | Simulator design reference |
| `api/adl/demand/fix.php` | [OK] Created | Fix demand API endpoint |
| `api/adl/demand/airway.php` | [OK] Created | Airway segment demand API |
| `api/adl/demand/segment.php` | [OK] Created | Route segment demand API |
| `adl/migrations/demand/*.sql` | [OK] Created | Airspace demand indexes & functions |

### New Files (v16)

| File | Status | Notes |
|------|--------|-------|
| `demand.php` | [OK] Created | Airport demand analysis page |
| `api/demand/airports.php` | [OK] Created | Airport list API |
| `api/demand/airport.php` | [OK] Created | Single airport demand details |
| `api/demand/rates.php` | [OK] Created | Rate data API |
| `api/demand/summary.php` | [OK] Created | Demand summary API |
| `api/demand/override.php` | [OK] Created | Manual rate override API |
| `api/demand/configs.php` | [OK] Created | Available runway configs API |
| `api/demand/atis.php` | [OK] Created | ATIS info with runway config |
| `adl/migrations/080-091_*.sql` | [OK] Created | Airport config & ATIS schema |
| `assets/js/demand.js` | [OK] Created | Demand page frontend |

### New Files (v15)

| File | Status | Notes |
|------|--------|-------|
| `adl/migrations/tmi/001_ntml_schema.sql` | [OK] Created | NTML tables schema |
| `adl/migrations/tmi/002_gs_procedures.sql` | [OK] Created | GS stored procedures |
| `adl/migrations/tmi/003_gdt_views.sql` | [OK] Created | GDT views |
| `adl/migrations/core/007_remove_flight_status.sql` | [OK] Created | Schema cleanup |
| `api/tmi/gs/*.php` | [OK] Created | 10 new GS API endpoints |
| `docs/GDT_Unified_Design_Document_v1.md` | [OK] Created | GDT design reference |
| `docs/GDT_GS_Transition_Summary_20260110.md` | [OK] Created | Implementation summary |

### Recent Commits

| Commit | Description |
|--------|-------------|
| `ef29cef` | Fix ATIS parser syntax error |
| `0d38c3f` | Update demand, daemon, and ATIS systems |
| `1ea19ae` | Add runway config detection and update demand system |
| `8f5870d` | Update demand.js |
| `3561e80` | Update status page |

---

## Legend

| Icon | Meaning |
|------|---------|
| [OK] | Active / Deployed / Healthy |
| [WARN] | Modified / Pending / Warning |
| [ERR] | Error / Failed / Critical |
| [X] | Removed / Deprecated |
| [?] | Unknown / Not Monitored |

---

## Quick Commands

### Start Parse Queue Daemon
```bash
php adl/php/parse_queue_daemon.php --loop
```

### Start ATIS Daemon
```bash
python scripts/vatsim_atis/atis_daemon.py
```

### Import Weather Alerts
```bash
php adl/php/import_weather_alerts.php --type=sigmet --verbose
```

### Update Navigation Data
```bash
python scripts/nasr_navdata_updater.py
```

### Run Diagnostic Check
```sql
EXEC diagnostic_check;
```

---

*Generated by PERTI System Documentation*
