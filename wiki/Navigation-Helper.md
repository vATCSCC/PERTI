# Navigation Helper

Quick reference guide for finding documentation, code, and resources in PERTI.

---

## What Do You Want To Do?

### I want to...

| Task | Go To |
|------|-------|
| **Learn how PERTI works** | [[Home]] > [[Architecture]] > [[Data Flow]] |
| **Set up a local dev environment** | [[Getting Started]] > [[Configuration]] |
| **Deploy to production** | [[Deployment]] |
| **Understand the database schema** | [[Database Schema]] |
| **Use the APIs** | [[API Reference]] |
| **Troubleshoot an issue** | [[Troubleshooting]] > [[FAQ]] |
| **Contribute code** | [[Contributing]] > [[Code Style]] > [[Testing]] |

---

## By Feature / Tool

### Traffic Management (TMI)

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Ground Stops (GS) | [[GDT Ground Delay Tool]] | [[TMI API]] | `api/tmi/gs/` |
| Ground Delay Programs (GDP) | [[GDT Ground Delay Tool]] | [[TMI API]] | `api/tmi/gdp/`, `api/gdt/` |
| Reroutes | [[TMI API]] | `POST /api/tmi/reroutes.php` | `api/tmi/reroutes.php` |
| NTML (National Traffic Management Log) | [[TMI API]] | `GET /api/tmi/active.php` | `api/tmi/` |

### Flight Data (ADL)

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Current Flights | [[ADL API]] | `GET /api/adl/current.php` | `api/adl/current.php` |
| Flight Details | [[ADL API]] | `GET /api/adl/flight.php` | `api/adl/flight.php` |
| Route Parsing | [[Algorithm Route Parsing]] | - | `daemons/route_parser.php` |
| Trajectory Tracking | [[Algorithm Trajectory Tiering]] | - | `daemons/trajectory_daemon.php` |
| ETA Calculation | [[Algorithm ETA Calculation]] | - | `daemons/eta_daemon.php` |
| Demand Analysis | [[Demand Analysis Walkthrough]] | `GET /api/adl/demand/*` | `api/adl/demand/` |

### Airport Operations

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Demand Charts | [[Demand Analysis Walkthrough]] | `GET /api/demand/summary.php` | `api/demand/` |
| Rate Management | [[Database Schema]] | `POST /api/demand/override.php` | `api/demand/override.php` |
| ATIS Import | [[Database Schema]] | - | `daemons/atis_daemon.php` |
| Runway Configs | [[Database Schema]] | `GET /api/demand/configs.php` | `api/demand/configs.php` |

### Public Dashboards

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| JATOC (Incidents) | [[JATOC]] | [[JATOC API]] | `api/jatoc/` |
| NOD Dashboard | [[NOD Dashboard]] | `GET /api/nod/*` | `api/nod/` |
| Route Plotter | [[Route Plotter]] | - | `plotter.php` |

### Training & Simulation

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| ATFM Simulator | [[ATFM Training Simulator]] | `GET /api/simulator/*` | `api/simulator/`, `simulator/engine/` |

### SWIM (External Integration)

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Flight Data API | `docs/swim/README.md` | `GET /api/swim/v1/flights.php` | `api/swim/v1/` |
| Position Streaming | `docs/swim/README.md` | `GET /api/swim/v1/positions.php` | `api/swim/v1/` |
| WebSocket Feed | `docs/swim/README.md` | `/api/swim/v1/ws/` | `api/swim/v1/ws/` |
| API Keys | `docs/swim/README.md` | `POST /api/swim/v1/keys/provision.php` | `api/swim/v1/keys/` |

---

## By Database

### VATSIM_ADL (Azure SQL)

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Flight Core** | `adl_flight_core`, `adl_flight_position`, `adl_flight_plan`, `adl_flight_times`, `adl_flight_aircraft` | [[Database Schema]] |
| **Route Data** | `adl_parse_queue`, `adl_flight_waypoints`, `adl_flight_trajectory` | [[Algorithm Route Parsing]] |
| **Boundaries** | `adl_boundary`, `adl_flight_boundary_log`, `adl_flight_planned_crossings` | [[Database Schema]] |
| **TMI/NTML** | `ntml`, `ntml_info`, `ntml_slots`, `adl_flight_tmi` | [[TMI API]] |
| **Reroutes** | `tmi_reroutes`, `tmi_reroute_flights`, `tmi_reroute_compliance_log` | [[TMI API]] |
| **Airport Config** | `airport_config`, `airport_config_runway`, `airport_config_rate`, `runway_in_use`, `vatsim_atis` | [[Database Schema]] |
| **Modifiers** | `modifier_category`, `modifier_type`, `config_modifier` | [[Database Schema]] |
| **Demand Monitors** | `demand_monitors` | [[ADL API]] |
| **Simulator Reference** | `sim_ref_carrier_lookup`, `sim_ref_route_patterns`, `sim_ref_airport_demand` | [[ATFM Training Simulator]] |

### VATSIM_PERTI (MySQL)

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Users** | `users`, `user_preferences` | [[Getting Started]] |
| **Planning** | `plans`, `schedules`, `comments` | [[Creating PERTI Plans]] |
| **Splits** | `splits_areas`, `splits_configs` | [[Splits]] |
| **JATOC** | `incidents`, `incident_updates`, `incident_types` | [[JATOC]] |

---

## By API Module

| Module | Base Path | Key Endpoints | Wiki |
|--------|-----------|---------------|------|
| **ADL** | `/api/adl/` | `current.php`, `flight.php`, `stats.php`, `demand/*` | [[ADL API]] |
| **TMI** | `/api/tmi/` | `active.php`, `gs/*`, `gdp/*`, `reroutes.php` | [[TMI API]] |
| **Demand** | `/api/demand/` | `summary.php`, `rates.php`, `override.php`, `configs.php` | [[API Reference]] |
| **JATOC** | `/api/jatoc/` | `incidents.php`, `incident.php`, `config.php` | [[JATOC API]] |
| **NOD** | `/api/nod/` | `tmi_active.php`, `advisories.php`, `jatoc.php` | [[API Reference]] |
| **Splits** | `/api/splits/` | `areas.php`, `configs.php`, `active.php` | [[Splits]] |
| **Stats** | `/api/stats/` | `realtime.php`, `daily.php`, `airport.php`, `artcc.php` | [[API Reference]] |
| **SWIM v1** | `/api/swim/v1/` | `flights.php`, `positions.php`, `auth.php`, `tmi/*` | `docs/swim/` |
| **GDT** | `/api/gdt/` | `demand/*`, `flights/*`, `programs/*`, `slots/*` | [[GDT Ground Delay Tool]] |
| **Simulator** | `/api/simulator/` | `engine.php`, `traffic.php`, `navdata.php` | [[ATFM Training Simulator]] |
| **Data** | `/api/data/` | `weather.php`, `sua.php`, `tfr.php`, `routes.php` | [[API Reference]] |
| **Routes** | `/api/routes/` | `public.php`, `public_post.php` | [[API Reference]] |
| **Management** | `/api/mgt/` | CRUD for configs, comments, TMI elements | Internal |

---

## By Daemon / Background Process

| Daemon | Purpose | Cycle | Code |
|--------|---------|-------|------|
| `adl_refresh.php` | VATSIM data sync | 15s | `daemons/adl_refresh.php` |
| `route_parser.php` | Parse queued routes | 10s | `daemons/route_parser.php` |
| `eta_daemon.php` | Calculate ETAs | 15s | `daemons/eta_daemon.php` |
| `waypoint_eta_daemon.php` | Waypoint ETAs (tiered) | 15s | `daemons/waypoint_eta_daemon.php` |
| `boundary_daemon.php` | ARTCC/TRACON detection | 15s | `daemons/boundary_daemon.php` |
| `trajectory_daemon.php` | Position logging | 15s | `daemons/trajectory_daemon.php` |
| `atis_daemon.php` | VATSIM ATIS import | 60s | `daemons/atis_daemon.php` |
| `weather_daemon.php` | Weather data sync | 300s | `daemons/weather_daemon.php` |
| `cleanup_daemon.php` | Data retention | 3600s | `daemons/cleanup_daemon.php` |

See [[Daemons and Scripts]] for full documentation.

---

## By Stored Procedure

### ADL Core Procedures

| Procedure | Purpose | Called From |
|-----------|---------|-------------|
| `sp_Adl_RefreshFromVatsim_Normalized` | Main 13-step VATSIM data refresh | `adl_refresh.php` |
| `sp_ParseRouteBatch` | Route parsing with waypoint expansion | `route_parser.php` |
| `sp_CalculateETABatch` | ETA calculation | `eta_daemon.php` |
| `sp_WaypointETABatch` | Waypoint ETA calculation | `waypoint_eta_daemon.php` |
| `sp_BoundaryDetectionBatch` | ARTCC/TRACON detection | `boundary_daemon.php` |
| `sp_RouteDistanceBatch` | Route distance calculation | Refresh Step 5b |

### Airport Config Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_GetSuggestedRates` | Multi-level rate suggestion algorithm |
| `sp_SetRateOverride` | Apply manual rate override |
| `sp_ImportVatsimAtis` | Import raw ATIS broadcasts |
| `sp_ImportRunwaysInUseBatch` | Parse runway assignments from ATIS |
| `sp_DetectRunwaysFromFlights` | Detect runway config from flight tracks |

### TMI Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_GS_ModelFlights` | Identify flights affected by Ground Stop |
| `sp_GS_ApplyEDCTs` | Issue EDCTs for Ground Stop |
| `sp_GDP_BuildSlots` | Create GDP arrival slot structure |
| `sp_GDP_AssignFlights` | Assign flights to GDP slots |

---

## Documentation Index

### Wiki Pages (38 total)

**Getting Started**
- [[Home]] - Main wiki landing page
- [[Getting Started]] - Installation and setup
- [[Configuration]] - Environment configuration
- [[Deployment]] - Production deployment

**Architecture**
- [[Architecture]] - System design overview
- [[Data Flow]] - Data pipeline documentation
- [[Database Schema]] - Table and field reference

**Algorithms**
- [[Algorithms Overview]] - Algorithm index
- [[Algorithm ETA Calculation]] - ETA methodology
- [[Algorithm Trajectory Tiering]] - Position logging tiers
- [[Algorithm Zone Detection]] - OOOI detection
- [[Algorithm Route Parsing]] - Route expansion
- [[Algorithm Data Refresh]] - VATSIM sync process

**API Documentation**
- [[API Reference]] - Complete API reference
- [[ADL API]] - Flight data API details
- [[TMI API]] - Traffic management API
- [[JATOC API]] - Incident API

**Features**
- [[GDT Ground Delay Tool]] - GDP/GS management
- [[Route Plotter]] - TSD-style flight map
- [[JATOC]] - Incident monitoring
- [[NOD Dashboard]] - NAS operations dashboard
- [[Splits]] - Sector configuration
- [[ATFM Training Simulator]] - TMU training

**Walkthroughs**
- [[Creating PERTI Plans]] - Planning guide
- [[Route Plotting Walkthrough]] - Map usage guide
- [[Demand Analysis Walkthrough]] - Demand tools guide

**Operations**
- [[Daemons and Scripts]] - Background processes
- [[Maintenance]] - System maintenance
- [[Troubleshooting]] - Common issues

**Development**
- [[Contributing]] - Contribution guide
- [[Code Style]] - Coding standards
- [[Testing]] - Test procedures

**Reference**
- [[Acronyms]] - Terminology glossary
- [[FAQ]] - Frequently asked questions
- [[Changelog]] - Version history

### Extended Documentation (docs/)

| Path | Contents |
|------|----------|
| `docs/tmi/` | TMI system (18 files) - Architecture, API, Database |
| `docs/swim/` | SWIM system (20 files) - API, SDK, Field mapping |
| `docs/` | Design documents, migration guides, status reports |
| `docs/QUICK_REFERENCE.md` | Comprehensive code index |

---

## Quick Lookup

### Common Questions

| Question | Answer |
|----------|--------|
| What's the main flight table? | `adl_flight_core` (Azure SQL) |
| Where are rates stored? | `airport_config_rate` |
| How do I query flight ETAs? | `adl_flight_times.eta_utc` |
| Where's the VATSIM sync code? | `daemons/adl_refresh.php` + `sp_Adl_RefreshFromVatsim_Normalized` |
| How do Ground Stops work? | `ntml` table with `program_type='GS'` |
| Where are reroutes defined? | `tmi_reroutes` table |
| What's the demand monitor table? | `demand_monitors` |
| How do I get real-time stats? | `GET /api/stats/realtime.php` |

### Key File Locations

| File | Purpose |
|------|---------|
| `status.php` | System status dashboard |
| `demand.php` | Airport demand analysis page |
| `plotter.php` | Route plotter / TSD map |
| `gdt.php` | Ground Delay Tool interface |
| `ntml.php` | National Traffic Management Log |
| `simulator.php` | ATFM Training Simulator |
| `configs.php` | Airport configuration management |
| `load/config.php` | Application configuration |
| `load/connect.php` | Database connections |

---

*Last updated: 2026-01-21*
