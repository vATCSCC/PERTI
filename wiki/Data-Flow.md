# Data Flow

This page describes how data flows through the PERTI system from external sources to the user interface.

---

## High-Level Data Flow

```
External Sources → Import Layer → Databases → Processing → API Layer → Frontend
```

---

## Flight Data Pipeline

### 1. VATSIM API Ingestion

```
VATSIM Data API (every 15s)
       │
       ▼
vatsim_adl_daemon.php
       │
       ▼
sp_Adl_RefreshFromVatsim_Normalized
       │
       ├──▶ adl_flights (current state)
       ├──▶ adl_flights_history (snapshots)
       └──▶ adl_parse_queue (routes to parse)
```

### 2. Route Parsing

```
adl_parse_queue
       │
       ▼
parse_queue_daemon.php (every 5s)
       │
       ▼
sp_ParseRoute
       │
       ├──▶ adl_parsed_routes (waypoints)
       └──▶ sp_RouteDistanceBatch (distances)
```

### 3. ETA & Trajectory Processing

```
adl_flights (with parsed routes)
       │
       ▼
sp_CalculateETABatch
       │
       ├──▶ ETA updates in adl_flights
       └──▶ adl_trajectories (position history)
```

### 4. Zone & Boundary Detection

```
adl_flights (positions)
       │
       ├──▶ sp_ProcessZoneDetectionBatch
       │         └──▶ OOOI events (Out/Off/On/In)
       │
       └──▶ sp_ProcessBoundaryDetectionBatch
                 └──▶ Sector crossing events
```

---

## ATIS Data Flow

```
VATSIM ATIS API
       │
       ▼
atis_daemon.py (every 15s)
       │
       ▼
sp_ImportAtis
       │
       ├──▶ adl_atis (raw ATIS)
       ├──▶ runway_in_use (detected runways)
       └──▶ Weather extraction (wind, visibility, ceiling)
```

---

## Weather Data Flow

```
Aviation Weather Center
       │
       ▼
import_weather_alerts.php (every 5 min)
       │
       ▼
adl_weather_alerts (SIGMETs, AIRMETs)
```

---

## TMI Data Flow

### Ground Stop Workflow

```
User creates GS (GDT interface)
       │
       ▼
POST /api/tmi/gs/create.php
       │
       ▼
sp_GS_Create → ground_stop_programs (proposed)
       │
       ▼
sp_GS_Model → Identify affected flights
       │
       ▼
sp_GS_IssueEDCTs → ground_stop_flights (EDCTs assigned)
       │
       ▼
Discord webhook notification
```

---

## API Request Flow

```
Browser Request
       │
       ▼
Apache/IIS → PHP Router
       │
       ▼
/api/{module}/{endpoint}.php
       │
       ├──▶ MySQL (plans, configs)
       └──▶ Azure SQL (flights, TMIs)
              │
              ▼
         JSON Response
```

---

## Real-Time Updates

| Data Type | Update Interval | Source |
|-----------|-----------------|--------|
| Flight positions | 15 seconds | VATSIM API |
| ATIS | 15 seconds | VATSIM API |
| Weather alerts | 5 minutes | AWC |
| Route parsing | 5 seconds | Internal queue |
| TMI status | On change | User actions |

---

## See Also

- [[Architecture]] - System architecture overview
- [[Database Schema]] - Table definitions
- [[Daemons and Scripts]] - Background processes
