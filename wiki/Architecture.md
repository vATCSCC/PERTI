# Architecture

PERTI is a multi-tier web application that processes real-time VATSIM flight data and provides traffic flow management tools.

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

### MySQL (Application Database)

Stores user-facing application data:

| Table | Purpose |
|-------|---------|
| `plans` | Planning worksheets |
| `initiatives` | TMI initiative definitions |
| `ground_stops` | Ground stop programs |
| `incidents` | JATOC incidents |
| `advisories` | DCC advisories |
| `splits_*` | Sector configuration |
| `users` | User preferences |

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
       ├──▶ sp_Adl_RefreshFromVatsim_Normalized
       │           │
       │           ├──▶ INSERT/UPDATE adl_flights
       │           ├──▶ INSERT adl_flights_history (snapshots)
       │           └──▶ Queue routes for parsing
       │
       ▼
3. parse_queue_daemon.php
       │
       ├──▶ sp_ParseQueue
       │           │
       │           ├──▶ sp_ParseRoute (expand route string)
       │           ├──▶ INSERT adl_parsed_routes (waypoints)
       │           └──▶ sp_RouteDistanceBatch (calculate distances)
       │
       ▼
4. Stored Procedure Triggers
       │
       ├──▶ sp_CalculateETABatch (ETA calculation)
       ├──▶ sp_ProcessZoneDetectionBatch (OOOI events)
       └──▶ sp_ProcessBoundaryDetectionBatch (sector crossings)
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

## See Also

- [[Data Flow]] - Detailed data flow diagrams
- [[Database Schema]] - Complete schema documentation
- [[API Reference]] - API endpoint details
