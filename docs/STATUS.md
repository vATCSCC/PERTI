# PERTI System Status Dashboard

> **Last Updated:** 2026-01-10
> **System Version:** v16 - Main Branch

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

**Usage:**
```bash
# Continuous loop mode
php parse_queue_daemon.php --loop

# Single run with custom batch size
php parse_queue_daemon.php --batch=100

# Custom interval (seconds)
php parse_queue_daemon.php --loop --interval=10
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
