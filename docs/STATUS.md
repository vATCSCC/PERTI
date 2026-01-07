# PERTI System Status Dashboard

> **Last Updated:** 2026-01-07
> **System Version:** Main Branch (ae131f5)

---

## Quick Health Overview

| Component | Status | Description |
|-----------|--------|-------------|
| ADL Flight Processing | 🟢 Active | Core flight data pipeline |
| Route Parsing | 🟢 Active | Route expansion and waypoint extraction |
| ETA Calculation | 🟢 Active | Trajectory prediction system |
| Zone Detection | 🟢 Active | OOOI airport zone monitoring |
| Boundary Detection | 🟢 Active | ARTCC/sector crossing detection |
| Weather Integration | 🟢 Active | SIGMET/AIRMET monitoring |
| ATIS Import | 🟢 Active | Runway assignment parsing |
| Event Statistics | 🟢 Active | VATUSA event tracking |

---

## Stored Procedures

### Flight Processing & Route Parsing

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_ParseRoute` | 🟢 Deployed | [sp_ParseRoute.sql](../adl/procedures/sp_ParseRoute.sql) | Parses flight routes into waypoint sequences |
| `sp_ParseQueue` | 🟢 Deployed | [sp_ParseQueue.sql](../adl/procedures/sp_ParseQueue.sql) | Processes queued routes for expansion |
| `sp_ParseSimBriefData` | 🟢 Deployed | [sp_ParseSimBriefData.sql](../adl/procedures/sp_ParseSimBriefData.sql) | Extracts SimBrief flight plan data |
| `fn_GetParseTier` | 🟢 Deployed | [fn_GetParseTier.sql](../adl/procedures/fn_GetParseTier.sql) | Determines processing tier for routes |
| `sp_RouteDistanceBatch` | 🟢 Deployed | [sp_RouteDistanceBatch.sql](../adl/procedures/sp_RouteDistanceBatch.sql) | Batch route distance calculation |

### ETA & Trajectory System

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_CalculateETA` | 🟢 Deployed | [sp_CalculateETA.sql](../adl/procedures/sp_CalculateETA.sql) | Computes single flight ETA |
| `sp_CalculateETABatch` | 🟢 Deployed | [sp_CalculateETABatch.sql](../adl/procedures/sp_CalculateETABatch.sql) | Batch ETA calculation |
| `sp_CalculateWaypointETA` | 🟢 Deployed | [sp_CalculateWaypointETA.sql](../adl/procedures/sp_CalculateWaypointETA.sql) | ETA at specific waypoints |
| `sp_ProcessTrajectoryBatch` | 🟢 Deployed | [sp_ProcessTrajectoryBatch.sql](../adl/procedures/sp_ProcessTrajectoryBatch.sql) | Batch trajectory logging |
| `sp_LogTrajectory` | 🟢 Deployed | [sp_LogTrajectory.sql](../adl/procedures/sp_LogTrajectory.sql) | Records trajectory snapshots |
| `fn_GetTrajectoryTier` | 🟢 Deployed | [fn_GetTrajectoryTier.sql](../adl/procedures/fn_GetTrajectoryTier.sql) | Trajectory logging frequency |
| `fn_GetAircraftPerformance` | 🟢 Deployed | [fn_GetAircraftPerformance.sql](../adl/procedures/fn_GetAircraftPerformance.sql) | Aircraft performance lookup |
| `fn_GetTierIntervalSeconds` | 🟢 Deployed | [fn_GetTierIntervalSeconds.sql](../adl/procedures/fn_GetTierIntervalSeconds.sql) | Logging interval by tier |

### Zone & Boundary Detection

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_ProcessZoneDetectionBatch` | 🟢 Deployed | [sp_ProcessZoneDetectionBatch.sql](../adl/procedures/sp_ProcessZoneDetectionBatch.sql) | Batch airport zone detection |
| `sp_DetectZoneTransition` | 🟢 Deployed | [sp_DetectZoneTransition.sql](../adl/procedures/sp_DetectZoneTransition.sql) | Individual zone detection |
| `fn_DetectCurrentZone` | 🟢 Deployed | [fn_DetectCurrentZone.sql](../adl/procedures/fn_DetectCurrentZone.sql) | Current zone identification |
| `sp_ProcessBoundaryDetectionBatch` | 🟡 Modified | [sp_ProcessBoundaryDetectionBatch.sql](../adl/procedures/sp_ProcessBoundaryDetectionBatch.sql) | Batch boundary crossing detection |
| `sp_ImportAirportGeometry` | 🟢 Deployed | [sp_ImportAirportGeometry.sql](../adl/procedures/sp_ImportAirportGeometry.sql) | Airport zone geometry import |

### Data Synchronization

| Procedure | Status | Location | Description |
|-----------|--------|----------|-------------|
| `sp_Adl_RefreshFromVatsim_Normalized` | 🟢 Deployed | [sp_Adl_RefreshFromVatsim_Normalized.sql](../adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql) | VATSIM flight data sync |
| `fn_IsFlightRelevant` | 🟢 Deployed | [fn_IsFlightRelevant.sql](../adl/procedures/fn_IsFlightRelevant.sql) | Flight relevance filter |
| `diagnostic_check` | 🟢 Deployed | [diagnostic_check.sql](../adl/procedures/diagnostic_check.sql) | Health check queries |

### Removed Procedures

| Procedure | Status | Notes |
|-----------|--------|-------|
| `sp_UpsertFlight` | ❌ Removed | Replaced by normalized refresh |

---

## PHP Daemons & Scripts

### Active Daemons

| Daemon | Status | Location | Interval | Purpose |
|--------|--------|----------|----------|---------|
| Parse Queue Daemon | 🟡 Modified | [parse_queue_daemon.php](../adl/php/parse_queue_daemon.php) | 5s (configurable) | Continuous route parsing |

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
| Weather Alert Import | 🟢 Active | [import_weather_alerts.php](../adl/php/import_weather_alerts.php) | Every 5 min | SIGMET/AIRMET updates |
| Boundary Import | 🟢 Active | [import_boundaries.php](../adl/php/import_boundaries.php) | On-demand | ARTCC/TRACON boundaries |
| Wind Data Import | 🟢 Active | [import_wind_data.php](../adl/php/import_wind_data.php) | Hourly | NOAA RAP/GFS wind data |
| OSM Airport Geometry | 🟢 Active | [import_osm_airport_geometry.php](../adl/php/import_osm_airport_geometry.php) | On-demand | Airport zone boundaries |
| OSM Web Import | 🟢 Active | [import_osm_web.php](../adl/php/import_osm_web.php) | On-demand | Web-based OSM helper |

### Removed Scripts

| Script | Status | Notes |
|--------|--------|-------|
| `AdlFlightUpsert.php` | ❌ Removed | Functionality consolidated |
| `vatsim_ingest_daemon.php` | ❌ Removed | Replaced by external sync |

---

## Python Daemons & Utilities

### VATSIM ATIS System

| Component | Status | Location | Purpose |
|-----------|--------|----------|---------|
| ATIS Daemon | 🟢 Active | [atis_daemon.py](../scripts/vatsim_atis/atis_daemon.py) | Primary ATIS import (15s interval) |
| VATSIM Fetcher | 🟢 Active | [vatsim_fetcher.py](../scripts/vatsim_atis/vatsim_fetcher.py) | VATSIM API client |
| ATIS Parser | 🟢 Active | [atis_parser.py](../scripts/vatsim_atis/atis_parser.py) | ATIS text parsing |
| Config Loader | 🟢 Active | [config_loader.py](../scripts/vatsim_atis/config_loader.py) | PHP config loader |

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
| Daily Event Update | 🟢 Active | [daily_event_update.py](../scripts/statsim/daily_event_update.py) | Daily VATUSA sync |
| Event Fetcher | 🟢 Active | [fetch_new_events.py](../scripts/statsim/fetch_new_events.py) | Event data fetcher |
| Historical Import | 🟢 Active | [import_historical_events.py](../scripts/statsim/import_historical_events.py) | Historical event import |
| Event Stats | 🟢 Active | [vatusa_event_stats.py](../scripts/statsim/vatusa_event_stats.py) | Statistics processor |

### Navigation & Boundary Tools

| Script | Status | Location | Purpose |
|--------|--------|----------|---------|
| NASR Updater | 🟢 Active | [nasr_navdata_updater.py](../scripts/nasr_navdata_updater.py) | FAA NASR data refresh |
| Playbook Routes | 🟢 Active | [update_playbook_routes.py](../scripts/update_playbook_routes.py) | FAA playbook route updater |
| Sector Boundaries | 🟢 Active | [build_sector_boundaries.py](../scripts/build_sector_boundaries.py) | Sector boundary builder |

### Removed Utilities

| Script | Status | Notes |
|--------|--------|-------|
| `check_schema.py` | ❌ Removed | Deployment utility cleanup |
| `deploy_archive.py` | ❌ Removed | Deployment utility cleanup |
| `deploy_refresh_sp.py` | ❌ Removed | Deployment utility cleanup |
| `fix_archive_columns.py` | ❌ Removed | Deployment utility cleanup |
| `fix_track_proc.py` | ❌ Removed | Deployment utility cleanup |
| `verify_deployment.py` | ❌ Removed | Deployment utility cleanup |

---

## PowerShell Import Utilities

| Script | Status | Location | Purpose |
|--------|--------|----------|---------|
| Import-CIFPToAzure | 🟢 Active | [Import-CIFPToAzure.ps1](../adl/php/Import-CIFPToAzure.ps1) | CIFP procedure import |
| Import-OSMAirportGeometry | 🟢 Active | [Import-OSMAirportGeometry.ps1](../adl/php/Import-OSMAirportGeometry.ps1) | OSM boundary import |
| Import-OSMAirportGeometry-Parallel | 🟢 Active | [Import-OSMAirportGeometry-Parallel.ps1](../adl/php/Import-OSMAirportGeometry-Parallel.ps1) | Parallel OSM import |
| Import-NavDataToAzure | 🟢 Active | [Import-NavDataToAzure.ps1](../adl/php/Import-NavDataToAzure.ps1) | Navigation data import |
| Import-NavDataToAzure-Fast | 🟢 Active | [Import-NavDataToAzure-Fast.ps1](../adl/php/Import-NavDataToAzure-Fast.ps1) | Fast bulk navdata import |
| Import-WeatherAlerts | 🟢 Active | [Import-WeatherAlerts.ps1](../adl/php/Import-WeatherAlerts.ps1) | Weather alert import |
| Import-XPlaneNavData | 🟢 Active | [Import-XPlaneNavData.ps1](../adl/php/Import-XPlaneNavData.ps1) | X-Plane navdata import |
| ImportOSM | 🟢 Active | [ImportOSM.ps1](../adl/php/ImportOSM.ps1) | General OSM import |

---

## Database Migrations

### ADL Core System (Azure SQL)

**Location:** [adl/migrations/](../adl/migrations/)

| Category | Migrations | Status | Description |
|----------|------------|--------|-------------|
| **core/** | 6 files | 🟢 Deployed | Foundation tables and views |
| **eta/** | 11 files | 🟢 Deployed | ETA & trajectory system |
| **oooi/** | 8 files | 🟢 Deployed | OOOI zone detection |
| **boundaries/** | 6 files | 🟢 Deployed | Sector boundary management |
| **weather/** | 4 files | 🟢 Deployed | Weather integration |
| **navdata/** | 5 files | 🟢 Deployed | Navigation data |
| **cifp/** | 2 files | 🟢 Deployed | CIFP procedures |
| **performance/** | 3 files | 🟢 Deployed | Aircraft performance |
| **stats/** | 5 files | 🟢 Deployed | Flight statistics |
| **changelog/** | 7 files | 🟢 Deployed | Change tracking triggers |

### Pending Migrations

| File | Status | Description |
|------|--------|-------------|
| [079_event_aar_from_flights.sql](../adl/migrations/079_event_aar_from_flights.sql) | 🟡 Pending | Event AAR calculation from flight data |

### PERTI MySQL Migrations

**Location:** [database/migrations/](../database/migrations/)

| Category | Migrations | Status | Description |
|----------|------------|--------|-------------|
| **schema/** | 5 files | 🟢 Deployed | Core database schema |
| **advisories/** | 3 files | 🟢 Deployed | DCC/NOD advisory management |
| **gdp/** | 2 files | 🟢 Deployed | Ground Delay Program tables |
| **initiatives/** | 4 files | 🟢 Deployed | Initiative planning |
| **jatoc/** | 3 files | 🟢 Deployed | Incident tracking |
| **reroute/** | 2 files | 🟢 Deployed | Reroute definitions |
| **integration/** | 3 files | 🟢 Deployed | External integrations |
| **sua/** | 3 files | 🟢 Deployed | Special Use Airspace |

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
| Build | 🟢 Active | PHP 8.2 setup, Composer install |
| Package | 🟢 Active | Archive and artifact upload |
| Deploy | 🟢 Active | Azure Web App deployment |

**Trigger:** Commits to `main` branch
**Target:** Azure Web App (vatcscc)

### GitHub Workflows

| Workflow | Status | Location |
|----------|--------|----------|
| Main Deployment | 🟢 Active | [main_vatcscc.yml](../.github/workflows/main_vatcscc.yml) |
| Azure WebApp | 🟢 Active | [azure-webapp-vatcscc.yml](../.github/workflows/azure-webapp-vatcscc.yml) |

---

## Data Flow Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         EXTERNAL DATA SOURCES                           │
├─────────────┬─────────────┬─────────────┬─────────────┬────────────────┤
│ VATSIM API  │ Aviation WX │ NOAA NOMADS │ FAA NASR    │ VATUSA API     │
│ (Live Data) │ (SIGMET)    │ (Wind)      │ (NavData)   │ (Events)       │
└──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┴───────┬────────┘
       │             │             │             │              │
       ▼             ▼             ▼             ▼              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                          IMPORT LAYER                                    │
├─────────────┬─────────────┬─────────────┬─────────────┬────────────────┤
│ atis_daemon │ import_wx   │ import_wind │ nasr_updater│ fetch_events   │
│ (Python)    │ (PHP)       │ (PHP)       │ (Python)    │ (Python)       │
└──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┴───────┬────────┘
       │             │             │             │              │
       └─────────────┴─────────────┴─────────────┴──────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      AZURE SQL (VATSIM_ADL)                             │
├─────────────────────────────────────────────────────────────────────────┤
│  adl_flights  │  adl_trajectories  │  adl_parse_queue  │  adl_zones    │
│  adl_weather  │  adl_boundaries    │  adl_statistics   │  adl_atis     │
└─────────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                       PROCESSING LAYER (Stored Procedures)              │
├─────────────────────────────────────────────────────────────────────────┤
│  sp_ParseRoute*          │  sp_CalculateETA*       │  sp_ProcessZone*  │
│  sp_ProcessBoundary*     │  sp_LogTrajectory*      │  fn_IsRelevant    │
└─────────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                       MySQL (PERTI Application)                         │
├─────────────────────────────────────────────────────────────────────────┤
│  Plans & Schedules  │  Initiatives  │  Ground Stops  │  User Config    │
└─────────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         API LAYER (PHP)                                 │
├─────────────┬─────────────┬─────────────┬─────────────┬────────────────┤
│  /api/adl   │  /api/tmi   │  /api/jatoc │  /api/nod   │  /api/routes   │
└─────────────┴─────────────┴─────────────┴─────────────┴────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      INTEGRATIONS                                       │
├─────────────────────────────────────────────────────────────────────────┤
│                    Discord Webhooks (TMI Sync)                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Recent Changes

### Modified Files (Uncommitted)

| File | Status | Notes |
|------|--------|-------|
| `.claude/settings.local.json` | 🟡 Modified | Local settings update |
| `adl/php/parse_queue_daemon.php` | 🟡 Modified | Daemon improvements |
| `adl/procedures/sp_ProcessBoundaryDetectionBatch.sql` | 🟡 Modified | Batch processing refinement |

### Recent Commits

| Commit | Description |
|--------|-------------|
| `ae131f5` | Refine boundary detection batch processing |
| `a39dca9` | Remove __pycache__ from version control |
| `106d679` | Add codebase index documentation |
| `4fd3509` | Add archive deployment and utility scripts |
| `3010925` | Add boundary optimization, wind data, and changelog infrastructure |

---

## Legend

| Icon | Meaning |
|------|---------|
| 🟢 | Active / Deployed / Healthy |
| 🟡 | Modified / Pending / Warning |
| 🔴 | Error / Failed / Critical |
| ❌ | Removed / Deprecated |
| ⚪ | Unknown / Not Monitored |

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
