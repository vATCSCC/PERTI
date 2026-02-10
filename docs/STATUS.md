# PERTI System Status Dashboard

> **Last Updated:** 2026-02-10
> **System Version:** v18 - Main Branch

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
| Demand Analysis | [OK] Active | Airport demand/capacity visualization |
| Airport Config | [OK] Active | Runway configuration & rate management |
| Rate Suggestions | [OK] Active | Weather-aware AAR/ADR recommendations |
| ATFM Simulator | [DEV] Phase 0 | TMU training simulator with Node.js flight engine |
| SWIM API | [OK] Active | System Wide Information Management API |
| TMI Database | [OK] Deployed | Unified TMI database (VATSIM_TMI) |
| TMI Publisher | [OK] v1.6.0 | Unified NTML/Advisory publishing with multi-Discord |
| **TMR Reports (NEW v18)** | [OK] Active | NTMO Guide-style post-event review reports |
| **NOD TMI Cards (NEW v18)** | [OK] Active | Rich TMI sidebar cards with map status layer |
| **NOD Facility Flows (NEW v18)** | [OK] Active | Facility flow configs, elements, gates, FEA |
| **i18n System (NEW v18)** | [OK] Active | 450+ keys, 13 JS modules, locale auto-detection |
| **PERTI_MYSQL_ONLY (NEW v18)** | [OK] Active | ~98 endpoints skip Azure SQL (~500-1000ms faster) |
| **PostgreSQL GIS (NEW v18)** | [OK] Active | PostGIS spatial queries (boundaries, routes) |

---

## SWIM API Subsystem

> **Documentation:** [docs/swim/](./swim/)
> **Status:** ✅ Active - Infrastructure Deployed

SWIM (System Wide Information Management) provides centralized flight data exchange across the VATSIM ecosystem.

### Architecture

```
┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
│    VATSIM_ADL       │      │     SWIM_API        │      │    Public API       │
│ (Hyperscale $$)    │─────▶│   (Basic $5/mo)     │─────▶│    Endpoints        │
│  Internal only      │ sync │  Dedicated for API  │      │                     │
└─────────────────────┘ 2min └─────────────────────┘      └─────────────────────┘
```

### Infrastructure Status

| Task | Status | Notes |
|------|--------|-------|
| Create Azure SQL Basic `SWIM_API` | [OK] Deployed | $5/mo fixed cost |
| Run `002_swim_api_database.sql` | [OK] Deployed | swim_flights table created |
| Add `$conn_swim` to config | [OK] Deployed | Connection string configured |
| SWIM sync daemon | [OK] Active | `scripts/swim_sync_daemon.php` (2min interval) |
| WebSocket server | [OK] Active | `scripts/swim_ws_server.php` (port 8090) |

### API Endpoints

| Endpoint | Status | Description |
|----------|--------|-------------|
| `GET /api/swim/v1` | [OK] Working | API info/router |
| `GET /api/swim/v1/flights` | [OK] Working | List flights (1,100+ active) |
| `GET /api/swim/v1/flight` | [OK] Working | Single flight lookup |
| `GET /api/swim/v1/positions` | [OK] Working | Bulk positions (GeoJSON) |
| `GET /api/swim/v1/health` | [OK] Working | Health check |
| `GET /api/swim/v1/metering` | [OK] Working | Metering data |
| `GET /api/swim/v1/tmi/programs` | [OK] Working | Active TMI programs |
| `GET /api/swim/v1/tmi/advisories` | [OK] Working | Active advisories |
| `GET /api/swim/v1/tmi/entries` | [OK] Working | NTML entries |
| `GET /api/swim/v1/tmi/controlled` | [OK] Working | TMI-controlled flights |
| `GET /api/swim/v1/tmi/reroutes` | [OK] Working | Active reroutes |
| `GET /api/swim/v1/tmi/routes` | [OK] Working | Public routes |
| `GET /api/swim/v1/reference/taxi-times` | [OK] Working | Airport taxi reference |
| `POST /api/swim/v1/ingest/adl` | [OK] Working | ADL data ingest |
| `POST /api/swim/v1/ingest/acars` | [OK] Working | ACARS data ingest |
| `POST /api/swim/v1/ingest/metering` | [OK] Working | Metering data ingest |
| `POST /api/swim/v1/ingest/simtraffic` | [OK] Working | SimTraffic data ingest |
| `POST /api/swim/v1/keys/provision` | [OK] Working | API key provisioning |

### SWIM Database Architecture

| Database | Purpose | Tier | Cost | API Access |
|----------|---------|------|------|------------|
| **VATSIM_ADL** | Internal ADL processing | Hyperscale Serverless | ~$3,200/mo | ❌ No (internal only) |
| **SWIM_API** | Public API queries | Basic | $5/mo fixed | ✅ Yes |
| **MySQL (PERTI)** | Ground stops, site data | General Purpose | ~$134/mo | ✅ Yes |

---

## TMI Database Subsystem

> **Documentation:** [docs/tmi/](./tmi/)
> **Status:** ✅ Deployed & Live (January 17, 2026)

Unified Traffic Management Initiative database consolidating NTML entries, Advisories, GDT Programs, Reroutes, Public Routes, Coordination Proposals, and Delay Reports.

### Database Info

| Setting | Value |
|---------|-------|
| **Server** | `vatsim.database.windows.net` |
| **Database** | `VATSIM_TMI` |
| **Tier** | Basic (5 DTU, 2 GB) |
| **Cost** | ~$5/month |

### Full Database Architecture (February 2026)

```
Azure SQL Server: vatsim.database.windows.net
├── VATSIM_ADL   (~$3,200/mo)  - Flight data (Hyperscale Serverless 3/16 vCores)
├── SWIM_API     ($5/mo)       - Public API (Basic)
├── VATSIM_REF   ($5/mo)       - Reference data (Basic)
├── VATSIM_TMI   ($5/mo)       - TMI data (Basic)
└── VATSIM_STATS ($0 paused)   - Statistics (GP Serverless, paused)

MySQL Server: vatcscc-perti.mysql.database.azure.com
└── perti_site   (~$134/mo)    - Web app data (General Purpose D2ds_v4)

PostgreSQL Server: vatcscc-gis.postgres.database.azure.com
└── vatcscc_gis  (~$58/mo)     - Spatial data (Burstable B2s, PostGIS)
                 ───────────
                 Total: ~$3,500/mo (7 databases, 3 engines)
```

### Database Objects

| Object Type | Count | Status |
|-------------|-------|--------|
| Tables | 20+ | ✅ Verified |
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
| `GET/POST/PUT/DELETE /api/tmi/reroutes.php` | [OK] Live | Reroutes CRUD |
| `GET/POST /api/tmi/gs/*.php` | [OK] Live | Full GS lifecycle (create, activate, extend, purge, flights, demand) |
| `GET/POST /api/tmi/gdp_*.php` | [OK] Live | GDP preview, apply, simulate, purge |
| `POST /api/mgt/tmi/coordinate.php` | [OK] Live | Multi-facility coordination |
| `POST /api/mgt/tmi/publish.php` | [OK] Live | Discord NTML/Advisory publishing |

### TMI Tables

| Table | Fields | Purpose |
|-------|--------|--------|
| `tmi_entries` | 35 | NTML log (MIT, MINIT, DELAY, CONFIG, APREQ, etc.) |
| `tmi_programs` | 47 | GS/GDP/AFP programs with rates, scope, exemptions |
| `tmi_slots` | 25 | GDP slot allocation (RBS algorithm) |
| `tmi_flight_control` | 30 | Per-flight TMI control records |
| `tmi_flight_list` | 15 | Flight lists for programs |
| `tmi_advisories` | 40 | Formal advisories (GS, GDP, AFP, Reroute, etc.) |
| `tmi_reroutes` | 45 | Reroute definitions with filtering |
| `tmi_reroute_routes` | 10 | Reroute route strings per O/D pair |
| `tmi_reroute_flights` | 30 | Flight assignments to reroutes |
| `tmi_reroute_compliance_log` | 9 | Compliance history snapshots |
| `tmi_reroute_drafts` | 15 | User reroute drafts |
| `tmi_public_routes` | 21 | Public route display on map |
| `tmi_proposals` | 20 | Multi-facility coordination proposals |
| `tmi_proposal_facilities` | 8 | Proposal approval tracking |
| `tmi_airport_configs` | 12 | TMI airport config snapshots |
| `tmi_delay_entries` | 15 | Delay reports with severity/trend |
| `tmi_discord_posts` | 12 | Discord posting queue (multi-org) |
| `tmi_popup_queue` | 8 | Popup flight detection queue |
| `tmi_events` | 18 | Unified audit log |
| `tmi_advisory_sequences` | 2 | Advisory numbering by date |

### Completed TMI Work (v17-v18)

| Task | Status | Version |
|------|--------|---------|
| Reroutes API endpoint | [OK] Deployed | v17 |
| GS lifecycle endpoints (create/activate/extend/purge) | [OK] Deployed | v15 |
| Discord Gateway bot for coordination reactions | [OK] Deployed | v17 |
| Multi-org Discord posting | [OK] Deployed | v17 |
| SWIM TMI endpoints (`/api/swim/v1/tmi/`) | [OK] Deployed | v17 |
| GS compliance analysis | [OK] Deployed | v18 |
| Delay report system | [OK] Deployed | v18 |

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

**All daemons started via `scripts/startup.sh` at App Service boot.**

| Daemon | Status | Location | Interval | Purpose |
|--------|--------|----------|----------|---------|
| ADL Ingest | [OK] Active | `scripts/vatsim_adl_daemon.php` | 15s | Flight data ingestion + ATIS |
| Parse Queue (GIS) | [OK] Active | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Route parsing with PostGIS |
| Boundary (GIS) | [OK] Active | `adl/php/boundary_gis_daemon.php` | 15s | Spatial boundary detection |
| Crossing Calc | [OK] Active | `adl/php/crossing_gis_daemon.php` | Tiered | Boundary crossing ETA prediction |
| Waypoint ETA | [OK] Active | `adl/php/waypoint_eta_daemon.php` | Tiered | Waypoint ETA calculation |
| SWIM WebSocket | [OK] Active | `scripts/swim_ws_server.php` | Persistent | Real-time events (port 8090) |
| SWIM Sync | [OK] Active | `scripts/swim_sync_daemon.php` | 2min | Sync ADL → SWIM_API |
| SimTraffic Poll | [OK] Active | `scripts/simtraffic_swim_poll.php` | 2min | SimTraffic time data |
| Reverse Sync | [OK] Active | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic → ADL |
| Scheduler | [OK] Active | `scripts/scheduler_daemon.php` | 60s | Splits/routes auto-activation |
| Archival | [OK] Active | `scripts/archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge |
| Monitoring | [OK] Active | `scripts/monitoring_daemon.php` | 60s | System metrics collection |
| Discord Queue | [OK] Active | `scripts/tmi/process_discord_queue.php` | Continuous | Async TMI Discord posting |
| Event Sync | [OK] Active | `scripts/event_sync_daemon.php` | 6h | VATUSA/VATCAN/VATSIM event sync |

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
| ADL Flight Ingestion | 15 seconds | PHP Daemon | VATSIM flight data + ATIS processing |
| Parse Queue (GIS) | 10s batch | PHP Daemon | Route parsing with PostGIS geometry |
| Boundary Detection (GIS) | 15 seconds | PHP Daemon | ARTCC/TRACON spatial detection |
| Crossing Calculation | Tiered | PHP Daemon | Boundary crossing ETA prediction |
| Waypoint ETA | Tiered | PHP Daemon | Waypoint ETA calculation |
| SWIM Sync | 2 minutes | PHP Daemon | ADL → SWIM_API sync |
| SimTraffic Poll | 2 minutes | PHP Daemon | SimTraffic time data polling |
| Scheduler | 60 seconds | PHP Daemon | Splits/routes auto-activation |
| Archival | 1-4 hours | PHP Daemon | Trajectory tiering, changelog purge |
| Monitoring | 60 seconds | PHP Daemon | System metrics collection |
| Discord Queue | Continuous | PHP Daemon | TMI Discord posting |
| Event Sync | 6 hours | PHP Daemon | VATUSA/VATCAN/VATSIM events |
| Weather Alert Import | 5 minutes | Cron/Scheduler | SIGMET/AIRMET updates |
| Navigation Data Refresh | On-demand | Manual | FAA NASR/AIRAC data update |

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
|                          IMPORT LAYER (14 Daemons)                      |
+-------------+-------------+-------------+-------------+----------------+
| adl_daemon  | import_wx   | import_wind | nasr_updater| event_sync     |
| atis_daemon | boundary_gis| crossing_gis| waypoint_eta| simtraffic     |
+------+------+------+------+------+------+------+------+-------+--------+
       |             |             |             |              |
       +-------------+-------------+-------------+--------------+
                                   |
            +----------------------+----------------------+
            v                      v                      v
+---------------------+ +--------------------+ +-------------------+
| Azure SQL           | | PostgreSQL/PostGIS | | MySQL             |
| VATSIM_ADL (~$3.2K) | | VATSIM_GIS (~$58)  | | perti_site (~$134)|
| VATSIM_TMI ($5)     | | Boundary detection | | Plans, users      |
| SWIM_API ($5)       | | Route geometry     | | TMR reports       |
| VATSIM_REF ($5)     | | Fix spatial lookup | | NOD flow configs  |
+---------------------+ +--------------------+ +-------------------+
            |                      |                      |
            +----------------------+----------------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                       PROCESSING LAYER                                  |
+-------------------------------------------------------------------------+
|  PostGIS spatial queries  |  Azure SQL stored procs |  PHP processing |
|  Boundary intersection    |  sp_CalculateETA*       |  Route parsing  |
|  Route geometry           |  sp_ProcessZone*        |  i18n (450+ keys)|
+-------------------------------------------------------------------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                         API LAYER (PHP)                                 |
+-------------+-------------+-------------+-------------+----------------+
|  /api/adl   |  /api/tmi   |  /api/jatoc |  /api/nod   |  /api/swim     |
|  /api/demand|  /api/splits|  /api/data  |  /api/nod/  |  /api/stats    |
|             |             |             |  flows/*    |                |
+-------------+-------------+-------------+-------------+----------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                      OUTPUT / INTEGRATIONS                              |
+-------------------------------------------------------------------------+
|  Discord (multi-org webhooks + Gateway bot)  |  SWIM WebSocket (8090) |
+-------------------------------------------------------------------------+
```

---

## Recent Changes (v18)

### New Files (v18)

| File | Status | Notes |
|------|--------|-------|
| `assets/js/lib/i18n.js` | [OK] Deployed | Core i18n translation module (PERTII18n) |
| `assets/js/lib/dialog.js` | [OK] Deployed | SweetAlert2 wrapper with i18n (PERTIDialog) |
| `assets/locales/en-US.json` | [OK] Deployed | 450+ translation keys |
| `assets/locales/index.js` | [OK] Deployed | Locale auto-detection loader |
| `assets/js/nod-demand-layer.js` | [OK] Deployed | NOD demand overlay rendering |
| `api/data/review/tmr_report.php` | [OK] Deployed | TMR report CRUD with auto-save |
| `api/data/review/tmr_tmis.php` | [OK] Deployed | Historical TMI lookup |
| `api/data/review/tmr_export.php` | [OK] Deployed | Discord-formatted TMR export |
| `api/nod/flows/configs.php` | [OK] Deployed | Flow configuration CRUD |
| `api/nod/flows/elements.php` | [OK] Deployed | Flow element CRUD |
| `api/nod/flows/gates.php` | [OK] Deployed | Flow gate CRUD |
| `api/nod/flows/suggestions.php` | [OK] Deployed | Element autocomplete |
| `api/nod/fea.php` | [OK] Deployed | FEA bridge API |
| `database/migrations/nod/001_facility_flow_tables.sql` | [OK] Deployed | NOD flow tables |
| `database/migrations/nod/002_flow_element_fea_linkage.sql` | [OK] Deployed | FEA linkage |

### Removed Files (v18 - PR #16)

| File | Notes |
|------|-------|
| `reroutes.php` | Replaced by `route.php` + `tmi-publish.php` |
| `advisory-builder.php` / `advisory-builder.js` | Functionality in tmi-publish |
| `reroute.js` (orphaned) | Replaced by new module |
| `migrate_public_routes.php` | Legacy admin migration |
| `migrate_reroutes.php` | Legacy admin migration |
| `test_star_parsing.php` | Test script |
| 7 other legacy scripts | 4,829 total lines deleted |

### Recent Commits (v18)

| Commit | Description |
|--------|-------------|
| `d85a77e` | Merge PR #21: feature/nod-tmi |
| `aa184c0` | Fix i18n: load full locale JSON in locale loader |
| `6b6549f` | Fix: add missing MapLibre GL and i18n dependencies |
| `65a1ec4` | Merge PR #20: feature/nod-tmi |
| `7b81624` | Fix NOD: load i18n scripts required by merged main branch |

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

*Generated by PERTI System Documentation — Last Updated February 10, 2026*
