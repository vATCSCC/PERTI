# Algorithms & Processing Overview

This documentation describes the core algorithms and data processing systems that power PERTI. The system processes VATSIM flight data every 15 seconds through a sophisticated pipeline that calculates ETAs, tracks flight phases, parses routes, and logs trajectory history.

---

## System Architecture

```
VATSIM API (every 15 seconds)
         │
         ▼
┌──────────────────────────────────────────────────────────────────────┐
│           sp_Adl_RefreshFromVatsim_Staged (V9.3.0)                   │
│  • Delta detection → Normalize tables → Defer ETAs → Log trajectories│
└──────────────────────────────────────────────────────────────────────┘
         │
         ├──────────────┬──────────────┬──────────────┬────────────────┐
         ▼              ▼              ▼              ▼                ▼
   ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐     ┌──────────┐
   │   ETA    │  │Trajectory│  │   OOOI   │  │  Route   │     │ Boundary │
   │  Engine  │  │  Logger  │  │ Detector │  │  Parser  │     │ Detector │
   └──────────┘  └──────────┘  └──────────┘  └──────────┘     └──────────┘
```

---

## Core Algorithm Documentation

| Algorithm | Purpose | Primary Users |
|-----------|---------|---------------|
| [[Algorithm-ETA-Calculation]] | Estimates arrival times using aircraft performance, route geometry, and TMI delays | Traffic Managers, GDP Planning |
| [[Algorithm-Trajectory-Tiering]] | Determines logging frequency based on flight phase and relevance | System Performance, Historical Analysis |
| [[Algorithm-Zone-Detection]] | Detects OOOI phases using airport geometry and speed-based fallbacks | OOOI Tracking, Ground Operations |
| [[Algorithm-Route-Parsing]] | Parses flight plan routes into waypoints with GIS coordinates | Route Plotting, Demand Analysis |
| [[Algorithm-Data-Refresh]] | Main pipeline processing VATSIM data every 15 seconds | System Operations, Integration |

---

## Performance Targets

| Metric | Target | Description |
|--------|--------|-------------|
| **Refresh Cycle** | < 15 seconds | Complete data refresh within VATSIM API window (SP + deferred ETA) |
| **ETA Accuracy** | ± 5 minutes | For flights > 100nm from destination |
| **Zone Detection** | < 0.5 seconds | Batch processing all relevant flights |
| **Route Parsing** | < 200ms/route | Including airway expansion and GIS resolution |

---

## Data Flow Summary

### Input Sources
- **VATSIM API** - Real-time pilot positions, flight plans, prefiles
- **FAA NASR** - Navigation fixes, airways, airports, procedures
- **OSM Overpass** - Airport geometry for zone detection

### Output Consumers
- **TSD/Route Plotter** - Flight positions and routes
- **GDT/TMI Tools** - ETAs for GDP/GS planning
- **NOD Dashboard** - Operational status
- **Demand Visualization** - Traffic forecasting

---

## Quick Reference

### Key Stored Procedures

| Procedure | Function |
|-----------|----------|
| `sp_Adl_RefreshFromVatsim_Staged` | Main refresh orchestrator (V9.3.0, delta detection + deferred ETA) |
| `sp_CalculateETABatch` | Consolidated ETA calculation |
| `sp_ProcessTrajectoryBatch` | Trajectory logging with tier evaluation |
| `sp_ProcessZoneDetectionBatch` | OOOI zone detection |
| `sp_ParseRoute` | Route parsing and GIS resolution |

### Key Functions

| Function | Returns |
|----------|---------|
| `fn_GetTrajectoryTier` | Tier 0-7 based on flight state |
| `fn_IsFlightRelevant` | 1 if flight is in covered airspace |
| `fn_DetectCurrentZone` | Zone type (PARKING/TAXIWAY/RUNWAY/etc) |
| `fn_GetAircraftPerformance` | Climb/cruise/descent speeds for aircraft |

---

## Related Documentation

- [[Acronyms]] - Timing acronym reference (ETA, OOOI, etc.)
- [[Database-Schema]] - Table structure reference
- [[API-Reference]] - API endpoints consuming this data
- [[Troubleshooting]] - Common issues and solutions

