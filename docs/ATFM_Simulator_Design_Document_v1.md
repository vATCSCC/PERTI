# ATFM Training Simulator - Design Document v1

**Version:** 1.0  
**Date:** January 11, 2026  
**Status:** In Development (Phase 0)

---

## 1. Overview

The ATFM Training Simulator is a web-based training tool for National (DCC) and facility-level TMU personnel to practice issuing Traffic Management Initiatives (TMIs) against realistic traffic scenarios.

### 1.1 Goals

- Provide realistic traffic scenarios based on actual historical patterns
- Allow trainees to practice GS, GDP, AFP, MIT, and reroute decisions
- Score trainee performance against optimal outcomes
- Enable instructor-led and self-guided training modes

### 1.2 Target Users

- DCC TMU trainees
- Facility TMU trainees
- ATFM instructors

---

## 2. Architecture

### 2.1 Components

```
┌─────────────────────────────────────────────────────────────────┐
│                      PERTI Web Application                       │
├─────────────────────────────────────────────────────────────────┤
│  simulator.php         │  Frontend UI (MapLibre, timeline)      │
│  assets/js/simulator/  │  JavaScript controllers                │
│  api/simulator/        │  PHP API endpoints                     │
├─────────────────────────────────────────────────────────────────┤
│                    Flight Engine Service                         │
│  simulator/engine/     │  Node.js headless flight simulation    │
│  - AircraftModel.js    │  Flight physics (position, climb, etc) │
│  - SimulationController│  Multi-aircraft management             │
│  - NavDataClient.js    │  PERTI nav_fixes integration           │
├─────────────────────────────────────────────────────────────────┤
│                      Azure SQL Database                          │
│  sim_ref_*             │  Reference data (routes, demand)       │
│  sim_session_*         │  Session state (planned)               │
│  nav_fixes             │  Navigation data (existing)            │
│  nav_procedures        │  SIDs/STARs (existing)                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Data Flow

1. **Scenario Generation**: PHP controller queries `sim_ref_route_patterns` and `sim_ref_airport_demand` to generate realistic flight schedules
2. **Flight Simulation**: Node.js engine advances aircraft positions using physics model
3. **State Sync**: PHP polls engine or receives WebSocket updates
4. **TMI Application**: User issues TMIs through UI, PHP applies delays/reroutes
5. **Scoring**: Compare actual vs optimal outcomes

---

## 3. Database Schema

### 3.1 Reference Tables (Deployed ✅)

```sql
-- 17 carriers with IATA/ICAO mappings
CREATE TABLE sim_ref_carrier_lookup (
    carrier_id INT IDENTITY PRIMARY KEY,
    carrier_code NVARCHAR(3),      -- IATA code (AA, UA, DL)
    carrier_icao NVARCHAR(4),      -- ICAO code (AAL, UAL, DAL)
    carrier_name NVARCHAR(100)
);

-- 3,989 O-D route patterns with hourly distribution
CREATE TABLE sim_ref_route_patterns (
    route_id INT IDENTITY PRIMARY KEY,
    origin NVARCHAR(4),
    destination NVARCHAR(4),
    avg_daily_flights DECIMAL(8,2),
    primary_carrier_icao NVARCHAR(4),
    carrier_weights_json NVARCHAR(MAX),     -- {"AAL": 0.35, "UAL": 0.25}
    aircraft_mix_json NVARCHAR(MAX),        -- [["B738", 45], ["A320", 30]]
    dep_hour_pattern_json NVARCHAR(MAX),    -- {"6": 8.5, "7": 12.3, ...}
    flight_time_min INT,
    distance_nm INT,
    is_hub_route BIT
);

-- 107 airports with demand curves
CREATE TABLE sim_ref_airport_demand (
    airport_id NVARCHAR(4) PRIMARY KEY,
    airport_name NVARCHAR(100),
    avg_daily_departures DECIMAL(8,2),
    avg_daily_arrivals DECIMAL(8,2),
    pattern_type NVARCHAR(20),              -- HUB, FOCUS, SECONDARY, etc.
    hourly_dep_pattern_json NVARCHAR(MAX),
    hourly_arr_pattern_json NVARCHAR(MAX),
    peak_dep_hours NVARCHAR(100),
    peak_arr_hours NVARCHAR(100)
);
```

### 3.2 Session Tables (Planned)

```sql
-- Active simulation sessions
CREATE TABLE sim_sessions (
    session_id UNIQUEIDENTIFIER PRIMARY KEY,
    user_id INT,
    scenario_id INT,
    start_time_utc DATETIME2,
    current_sim_time DATETIME2,
    status NVARCHAR(20),        -- ACTIVE, PAUSED, COMPLETED
    time_compression INT,       -- 1x, 2x, 4x, etc.
    created_utc DATETIME2
);

-- Flights in each session
CREATE TABLE sim_flights (
    flight_uid BIGINT PRIMARY KEY,
    session_id UNIQUEIDENTIFIER,
    callsign NVARCHAR(10),
    aircraft_type NVARCHAR(4),
    origin NVARCHAR(4),
    destination NVARCHAR(4),
    -- Current state
    lat DECIMAL(9,6),
    lon DECIMAL(9,6),
    altitude_ft INT,
    ground_speed_kts INT,
    heading INT,
    -- Timing
    scheduled_dep DATETIME2,
    actual_dep DATETIME2,
    eta DATETIME2,
    -- TMI effects
    edct DATETIME2,
    delay_minutes INT,
    rerouted BIT
);

-- TMIs issued during session
CREATE TABLE sim_tmis (
    tmi_id INT IDENTITY PRIMARY KEY,
    session_id UNIQUEIDENTIFIER,
    tmi_type NVARCHAR(20),      -- GS, GDP, AFP, MIT, REROUTE
    issued_time DATETIME2,
    params_json NVARCHAR(MAX),
    affected_flights INT
);
```

---

## 4. Flight Engine

### 4.1 Core Components

| File | Purpose |
|------|---------|
| `AircraftModel.js` | Flight physics - position, altitude, speed, heading |
| `SimulationController.js` | Multi-simulation/aircraft management |
| `NavDataClient.js` | Fetches nav_fixes from PERTI database |
| `flightMath.js` | Great circle, TAS/IAS, wind calculations |
| `flightConstants.js` | Aviation constants and thresholds |

### 4.2 HTTP API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/simulation/create` | Create new simulation |
| POST | `/simulation/:id/aircraft` | Spawn aircraft |
| POST | `/simulation/:id/tick` | Advance time by N seconds |
| POST | `/simulation/:id/command` | Issue ATC command |
| GET | `/simulation/:id/aircraft` | Get all aircraft state |
| DELETE | `/simulation/:id` | Delete simulation |

### 4.3 ATC Commands

| Command | Parameters | Description |
|---------|------------|-------------|
| FH | heading | Fly heading |
| TL/TR | heading | Turn left/right to heading |
| CM | altitude | Climb and maintain |
| DM | altitude | Descend and maintain |
| SP | speed | Maintain speed |
| DIRECT | fix | Proceed direct to fix |
| RESUME | - | Resume own navigation |

---

## 5. Implementation Phases

### Phase 0: Core Flight Engine ✅ IN PROGRESS

**Status:** Code complete, needs integration into PERTI codebase

- [x] Flight physics (AircraftModel.js)
- [x] Multi-simulation management (SimulationController.js)
- [x] HTTP API server (index.js)
- [x] Aircraft performance data (20 types)
- [x] Math utilities (flightMath.js)
- [ ] Integration into PERTI/simulator/
- [ ] NavDataClient connection to PERTI API
- [ ] Basic test flight

### Phase 1: Core Simulator + GS/GDP (4-5 weeks)

- [ ] simulator.php main page
- [ ] MapLibre flight display
- [ ] Scenario generation from sim_ref_* tables
- [ ] Ground Stop implementation
- [ ] GDP implementation
- [ ] Time compression (2x, 4x, 8x)

### Phase 2: Extended TMIs + Behavior (4-5 weeks)

- [ ] AFP implementation
- [ ] MIT implementation
- [ ] Reroutes
- [ ] Pilot compliance variation
- [ ] Weather injection

### Phase 3: Scoring + Tutorial (3-4 weeks)

- [ ] Delay scoring algorithm
- [ ] Throughput metrics
- [ ] Tutorial scenarios
- [ ] Achievement system

### Phase 4: Instructor Tools (3-4 weeks)

- [ ] Scenario builder
- [ ] Live injection
- [ ] Class management
- [ ] Performance reports

### Phase 5: Historical Integration (4-6 weeks)

- [ ] Replay actual VATSIM events
- [ ] Compare trainee vs actual TMU decisions
- [ ] Pattern recognition

---

## 6. File Locations

### Current (Wrong - needs moving)
```
VATSIM PERTI/atfm-flight-engine/   ← WRONG LOCATION
├── src/
│   ├── index.js
│   ├── SimulationController.js
│   ├── aircraft/AircraftModel.js
│   ├── math/flightMath.js
│   ├── constants/flightConstants.js
│   └── navigation/NavDataClient.js
├── config/aircraftTypes.json
├── package.json
└── README.md
```

### Target (Inside PERTI git repo)
```
VATSIM PERTI/PERTI/
├── simulator/                    ← NEW DIRECTORY
│   ├── engine/                   ← Node.js flight engine
│   │   ├── src/
│   │   ├── config/
│   │   └── package.json
│   └── scenarios/                ← Scenario definitions
├── simulator.php                 ← Main page
├── assets/js/simulator/          ← Frontend controllers
│   ├── SimulatorController.js
│   ├── FlightDisplay.js
│   └── TMIPanel.js
└── api/simulator/                ← PHP API endpoints
    ├── session.php
    ├── tick.php
    └── tmi.php
```

---

## 7. Reference Data Summary

### Deployed to Azure SQL (vatsim.database.windows.net/VATSIM_ADL)

| Table | Rows | Description |
|-------|------|-------------|
| sim_ref_carrier_lookup | 17 | US carriers with IATA/ICAO codes |
| sim_ref_route_patterns | 3,989 | O-D routes with hourly patterns |
| sim_ref_airport_demand | 107 | Airport demand curves |

### Data Sources

- **BTS On-Time Performance (Form 234)**: 20.6M flight records (2022-2024)
- **BTS T-100 Domestic Segment**: Aircraft type mappings
- **openScope**: Aircraft performance data (MIT license)

---

## 8. Related Documents

| Document | Location | Description |
|----------|----------|-------------|
| Transition Summary | `ATFM_Simulator_Transition_2026-01-11.md` | Session history |
| Flight Engine Transition | `ATFM_Flight_Engine_Transition.md` | Latest session details |
| BTS SQL Scripts | `BTS/sql/050_sim_ref_tables.sql` | Table creation |
| OpenScope Source | `openscope/` | MIT-licensed flight sim |

---

## 9. Changelog

| Date | Version | Changes |
|------|---------|---------|
| 2026-01-11 | 1.0 | Initial design document |
| 2026-01-11 | - | BTS data processed (3,989 routes, 107 airports) |
| 2026-01-11 | - | SQL reference tables deployed |
| 2026-01-11 | - | Flight engine code complete (wrong location) |
