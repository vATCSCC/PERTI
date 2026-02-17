# Data Flow

This page describes how data flows through the PERTI system from external sources to the user interface.

---

## Three Database Engines

PERTI uses three database engines, each chosen for its strengths:

| Engine | Database(s) | Purpose |
|--------|-------------|---------|
| **MySQL 8** | `perti_site` | Plans, users, staffing, configs, review data |
| **Azure SQL** | `VATSIM_ADL`, `VATSIM_TMI`, `SWIM_API`, `VATSIM_REF`, `VATSIM_STATS` | Flight data, TMI programs, API layer, reference data, statistics |
| **PostgreSQL/PostGIS** | `VATSIM_GIS` | Spatial queries, boundary polygons, route geometry parsing |

Connections are managed in `load/connect.php` with lazy-loading getters. MySQL is always available via `$conn_pdo` / `$conn_sqli`. Azure SQL connections (`get_conn_adl()`, `get_conn_tmi()`, etc.) and PostGIS (`get_conn_gis()`) are loaded on demand.

---

## High-Level Data Flow

```
External Sources --> Import Layer --> 7 Databases --> Processing Daemons --> API Layer --> Frontend
```

---

## Flight Data Pipeline

### 1. VATSIM API Ingestion

```
VATSIM Data API (every 15s)
       |
       v
vatsim_adl_daemon.php
       |
       ├──▶ Delta detection: compare pilots vs previous cycle (V9.3.0)
       │    Set change_flags bitmask per flight (0=heartbeat, 1=pos, 2=plan, 4=new)
       |
       v
sp_Adl_RefreshFromVatsim_Staged (V9.3.0, @defer_expensive=1)
       |
       |-->  adl_flight_core + 7 related tables (normalized)
       |     (heartbeat flights: timestamps only; changed flights: full processing)
       |-->  adl_flight_trajectory (position points - always captured, not filtered)
       |-->  adl_flight_changelog (field-level changes)
       '-->  adl_parse_queue (routes to parse)
       |
       v
Deferred ETA processing (if time budget remains)
       |
       |-->  sp_ProcessTrajectoryBatch (basic ETA)
       |-->  sp_CalculateETABatch (wind-adjusted ETA, every N cycles)
       '-->  sp_CapturePhaseSnapshot (phase counts)
```

The normalized 8-table architecture splits flight data across purpose-specific tables:

- `adl_flight_core` -- Main flight record (phase, activity, zone)
- `adl_flight_plan` -- Filed flight plan and route geometry
- `adl_flight_position` -- Current position and progress
- `adl_flight_times` -- 50+ time columns (ETD, ETA, OOOI, EDCT, etc.)
- `adl_flight_tmi` -- TMI control assignments
- `adl_flight_aircraft` -- Aircraft type and performance data
- `adl_flight_trajectory` -- Position history
- `adl_flight_waypoints` -- Parsed route waypoints

### 2. Route Parsing

```
adl_parse_queue
       |
       v
parse_queue_gis_daemon.php (every 10s batch)
       |
       v
PostGIS spatial route parsing
       |
       |-->  adl_flight_waypoints (parsed waypoints)
       |-->  adl_flight_plan.route_geometry (PostGIS geography)
       '-->  adl_flight_plan.waypoints_json
```

Route parsing uses PostGIS for spatial fix matching and airway expansion. Flights are parsed in tiered priority order based on phase and proximity.

### 3. ETA & Trajectory Processing

Trajectory points are captured in two places:
- **Step 8 of the SP** (always runs): Tiered position logging based on flight priority
- **Deferred ETA** (time-budget permitting): Basic and wind-adjusted ETA calculations

Additional waypoint-level ETAs are calculated by a separate daemon:

```
adl_flight_core + adl_flight_waypoints
       |
       v
waypoint_eta_daemon.php (tiered intervals)
       |
       |-->  adl_flight_times (50+ time columns)
       '-->  adl_flight_trajectory (position history)
```

Tiered processing intervals:
- **Tier 0** (15s): Active flights within 60nm of destination
- **Tier 1** (30s): Active flights en route
- **Tier 2** (60s): Prefiled flights departing within 2h
- **Tier 3** (2min): Prefiled flights departing within 6h
- **Tier 4** (5min): All other flights

### 4. Zone & Boundary Detection

```
adl_flight_core + adl_flight_position
       |
       v
boundary_gis_daemon.php (every 15s)       PostGIS spatial queries
       |
       |-->  adl_flight_core.current_artcc / current_tracon / current_sector
       |-->  adl_zone_events (OOOI events)
       |
       v
crossing_gis_daemon.php (tiered intervals)
       |
       '-->  adl_flight_planned_crossings (boundary crossing predictions)
```

Boundary detection uses PostGIS polygon intersection against ARTCC, TRACON, and sector boundaries stored in `VATSIM_GIS`. Crossing predictions estimate future boundary entry/exit times using parsed waypoints and ETA data.

---

## SWIM Sync Pipeline

```
ADL Normalized Tables
       |
       v
swim_sync_daemon.php (every 2min)
       |
       v
SWIM_API.swim_flights (denormalized snapshot)
       |
       |-->  REST API (/api/swim/v1/)
       '-->  WebSocket (swim_ws_server.php port 8090)
```

The SWIM sync daemon denormalizes the 8-table flight architecture into a single wide `swim_flights` table for efficient API consumption. The WebSocket server pushes real-time flight updates to connected clients.

---

## ATIS Data Flow

```
VATSIM ATIS API
       |
       v
atis_daemon.py (every 15s, embedded in vatsim_adl_daemon.php)
       |
       v
ATIS parsing and correlation
       |
       |-->  runway_in_use (detected runways)
       |-->  atis_config_history (configuration changes)
       '-->  Weather extraction (wind, visibility, ceiling)
```

---

## Weather Data Flow

```
Aviation Weather Center
       |
       v
import_weather_alerts.php (every 5 min)
       |
       v
adl_weather_alerts (SIGMETs, AIRMETs)
```

---

## TMI Data Flow

### Full TMI Lifecycle

```
User creates TMI (tmi-publish.php)
       |
       v
POST /api/tmi/ (programs, entries, advisories)
       |
       |-->  VATSIM_TMI database
       |        |-->  tmi_programs (GDP, GS, AFP, reroutes)
       |        |-->  tmi_slots (GDP time slots)
       |        |-->  tmi_flight_control (per-flight control records)
       |        |-->  tmi_advisories (advisory messages)
       |        '-->  tmi_entries (MIT, AFP, restrictions)
       |
       |-->  Discord notification (process_discord_queue.php)
       |        '-->  tmi_discord_posts (posting queue)
       |
       '-->  NTML advisory generation
                '-->  tmi_advisories (advisory_type, advisory_number)
```

### Ground Stop Workflow

```
User creates GS (GDT interface)
       |
       v
POST /api/tmi/gs/create.php
       |
       v
tmi_programs (status: proposed)
       |
       v
/api/tmi/gs/model.php --> Identify affected flights
       |
       v
/api/tmi/gs/activate.php --> tmi_flight_control (EDCTs assigned)
       |
       v
process_discord_queue.php --> Discord webhook notification
```

### Multi-Facility Coordination

```
TMI proposal created
       |
       v
tmi_proposals + tmi_proposal_facilities
       |
       v
Discord coordination thread (discord-bot/bot.js)
       |
       v
Reactions collected --> tmi_proposal_reactions
       |
       v
POST /api/mgt/tmi/coordinate.php --> Approval/denial
```

---

## Archival Pipeline

```
adl_flight_trajectory (live positions)
       |
       v
archival_daemon.php (1-4 hour intervals)
       |
       |-->  adl_trajectory_archive (downsampled)
       |-->  adl_trajectory_compressed (long-term storage)
       '-->  Changelog batch purge (adl_changelog_batch)
```

The archival daemon manages data lifecycle across three trajectory tiers:
- **Live** (`adl_flight_trajectory`): Full-resolution, recent flights
- **Archive** (`adl_trajectory_archive`): Downsampled, intermediate retention
- **Compressed** (`adl_trajectory_compressed`): Long-term storage

Completed flight data is also moved to `adl_flight_archive` on a daily schedule by `adl_archive_daemon.php`.

---

## API Request Flow

```
Browser Request
       |
       v
nginx + PHP-FPM (40 workers)
       |
       v
/api/{module}/{endpoint}.php
       |
       |-->  MySQL (plans, configs, staffing, review)
       |-->  Azure SQL (flights, TMIs, SWIM, reference)
       '-->  PostgreSQL/PostGIS (spatial queries)
              |
              v
         JSON Response
```

---

## Real-Time Updates

| Data Type | Update Interval | Source |
|-----------|-----------------|--------|
| Flight positions | 15 seconds | VATSIM API via `vatsim_adl_daemon.php` |
| ATIS | 15 seconds | VATSIM API via `vatsim_adl_daemon.php` |
| Route parsing | 10 seconds (batch) | `parse_queue_gis_daemon.php` |
| Boundary detection | 15 seconds | `boundary_gis_daemon.php` (PostGIS) |
| Crossing predictions | Tiered (15s - 5min) | `crossing_gis_daemon.php` |
| Waypoint ETAs | Tiered (15s - 5min) | `waypoint_eta_daemon.php` |
| SWIM sync | 2 minutes | `swim_sync_daemon.php` |
| Weather alerts | 5 minutes | Aviation Weather Center |
| TMI status | On change | User actions |
| Discord queue | Continuous | `process_discord_queue.php` |
| Event sync | 6 hours | `event_sync_daemon.php` |
| Archival | 1 - 4 hours | `archival_daemon.php` |

---

## See Also

- [[Architecture]] - System architecture overview
- [[Database Schema]] - Table definitions
- [[Daemons and Scripts]] - Background processes
