# ATFM Training Simulator

The ATFM (Air Traffic Flow Management) Training Simulator is a web-based training tool for National (DCC) and facility-level TMU personnel to practice issuing Traffic Management Initiatives (TMIs) against realistic traffic scenarios.

---

## Overview

### Purpose

- Provide realistic traffic scenarios based on actual historical patterns
- Allow trainees to practice GS, GDP, AFP, MIT, and reroute decisions
- Score trainee performance against optimal outcomes
- Enable instructor-led and self-guided training modes

### Target Users

- DCC TMU trainees
- Facility TMU trainees
- ATFM instructors

---

## Architecture

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
└─────────────────────────────────────────────────────────────────┘
```

---

## Features

### Traffic Management Initiatives

The simulator supports practicing the following TMI types:

| TMI Type | Description |
|----------|-------------|
| **GS** | Ground Stop - Hold all departures to an airport |
| **GDP** | Ground Delay Program - Assign EDCTs to spread arrivals |
| **AFP** | Airspace Flow Program - Control flow through an FCA |
| **MIT** | Miles-In-Trail - Spacing requirements between aircraft |
| **Reroute** | Redirect traffic around constraints |

### Simulation Capabilities

- **Realistic Traffic Patterns**: Based on 20.6M flight records from BTS data
- **Time Compression**: Run simulations at 2x, 4x, 8x speed
- **Weather Injection**: Simulate weather impacts on capacity
- **Pilot Compliance**: Variable compliance modeling
- **Scoring**: Compare trainee decisions against optimal outcomes

---

## Reference Data

The simulator uses reference data derived from actual flight statistics:

| Table | Records | Description |
|-------|---------|-------------|
| `sim_ref_carrier_lookup` | 17 | US carriers with IATA/ICAO codes |
| `sim_ref_route_patterns` | 3,989 | O-D routes with hourly patterns |
| `sim_ref_airport_demand` | 107 | Airport demand curves |

### Data Sources

- **BTS On-Time Performance (Form 234)**: 20.6M flight records (2022-2024)
- **BTS T-100 Domestic Segment**: Aircraft type mappings
- **openScope**: Aircraft performance data (MIT license)

---

## Flight Engine

The Node.js flight engine provides realistic flight simulation:

### Core Components

| Component | Purpose |
|-----------|---------|
| `AircraftModel.js` | Flight physics - position, altitude, speed, heading |
| `SimulationController.js` | Multi-simulation/aircraft management |
| `NavDataClient.js` | Fetches nav_fixes from PERTI database |
| `flightMath.js` | Great circle, TAS/IAS, wind calculations |
| `flightConstants.js` | Aviation constants and thresholds |

### ATC Commands

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

## API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/simulator/navdata.php` | GET | Navigation data for flight routing |
| `/api/simulator/engine.php` | GET/POST | Engine control (start/stop/state) |
| `/api/simulator/routes.php` | GET | Route pattern data |
| `/api/simulator/traffic.php` | GET/POST | Traffic generation and state |

See [[API Reference#atfm-training-simulator-apis]] for detailed documentation.

---

## Development Status

The simulator is currently in **Phase 0** (Core Flight Engine).

### Implementation Phases

| Phase | Status | Description |
|-------|--------|-------------|
| **Phase 0** | In Progress | Core flight engine, reference data |
| **Phase 1** | Planned | Core simulator + GS/GDP |
| **Phase 2** | Planned | Extended TMIs + pilot behavior |
| **Phase 3** | Planned | Scoring + tutorials |
| **Phase 4** | Planned | Instructor tools |
| **Phase 5** | Planned | Historical event replay |

---

## Getting Started

### For Trainees

1. Navigate to `/simulator.php`
2. Select a training scenario or create custom
3. Start the simulation
4. Issue TMIs as conditions develop
5. Review scoring and feedback

### For Instructors

1. Access instructor panel
2. Create or select scenarios
3. Monitor trainee sessions
4. Inject events (weather, traffic surges)
5. Generate performance reports

---

## Related Documentation

- [[API Reference]] - API documentation
- [[GDT Ground Delay Tool]] - Live GDT operations
- [[Acronyms]] - Terminology reference
- `docs/ATFM_Simulator_Design_Document_v1.md` - Full design specification

---

## See Also

- [[Demand Analysis Walkthrough]] - Understanding demand/capacity
- [[Creating PERTI Plans]] - Planning workflows
- [[Changelog]] - Version history
