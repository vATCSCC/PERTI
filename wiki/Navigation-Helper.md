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
| Route Parsing | [[Algorithm Route Parsing]] | - | `adl/php/parse_queue_gis_daemon.php` |
| Trajectory Tracking | [[Algorithm Trajectory Tiering]] | - | `scripts/vatsim_adl_daemon.php` (Step 8) |
| ETA Calculation | [[Algorithm ETA Calculation]] | - | `adl/php/waypoint_eta_daemon.php` |
| Boundary Detection | [[Algorithm Zone Detection]] | - | `adl/php/boundary_gis_daemon.php` |
| Demand Analysis | [[Demand Analysis Walkthrough]] | `GET /api/adl/demand/*` | `api/adl/demand/` |

### Airport Operations

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Demand Charts | [[Demand Analysis Walkthrough]] | `GET /api/adl/demand/*` | `api/adl/demand/` |
| Rate Management | [[Database Schema]] | - | `api/mgt/config_data/` |
| ATIS Import | [[Database Schema]] | - | `scripts/vatsim_adl_daemon.php` (ATIS embedded) |
| Runway Configs | [[Database Schema]] | `GET /api/data/configs.php` | `api/data/configs.php` |

### Playbook & Route Tools (v18)

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Playbook Catalog | [[Playbook]] | `GET /api/data/playbook/*` | `api/data/playbook/`, `api/mgt/playbook/` |
| Route Plotter | [[Route Plotter]] | [[GIS API]] | `route.php`, `assets/js/route-maplibre.js` |
| CDR Search | [[Route Plotter]] | - | `assets/js/playbook-cdr-search.js` |

### Sector Management (v18)

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| Splits Configuration | [[Splits]] | `GET /api/splits/*` | `api/splits/`, `splits.php` |
| Scheduled Splits | [[Splits]] | `GET /api/splits/scheduled.php` | `scripts/scheduler_daemon.php` |
| Canadian FIR Sectors | [[Splits]] | - | `assets/data/*.geojson` |
| Strata Filtering | [[Splits]] | `?strata=low,high,superhigh` | `api/splits/sectors.php` |

### Public Dashboards

| Feature | Wiki Page | API Reference | Code Location |
|---------|-----------|---------------|---------------|
| JATOC (Incidents) | [[JATOC]] | [[JATOC API]] | `api/jatoc/` |
| NOD Dashboard | [[NOD Dashboard]] | `GET /api/nod/*` | `api/nod/` |
| Route Plotter | [[Route Plotter]] | - | `route.php` |

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

### VATSIM_REF (Azure SQL) - Navigation Reference Data

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Navigation** | `nav_fixes`, `airways`, `airway_segments` | [[Database Schema]] - Waypoints and airways |
| **Routes** | `playbook_routes`, `playbook_route_fixes` | [[Database Schema]] - CDR/Playbook routes |
| **Boundaries** | `artcc_boundaries`, `tracon_boundaries` | [[Database Schema]] - Boundary reference data |
| **Airports** | `airports`, `runways` | [[Database Schema]] - Airport reference data |

### VATSIM_GIS (PostgreSQL/PostGIS) - Spatial Analysis

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Navigation** | `nav_fixes`, `airways`, `airway_segments` | [[Database Schema]] - Waypoints and airways |
| **Boundaries** | `artcc_boundaries`, `sector_boundaries`, `tracon_boundaries` | [[Database Schema]] - PostGIS geometries |
| **Adjacency Network** | `boundary_adjacency`, `boundary_proximity` | [[Database Schema]] - Boundary graph relationships |
| **Airports** | `airports`, `area_centers` | [[Database Schema]] - Airport data |
| **Routes** | `playbook_routes` | [[Database Schema]] - CDR/Playbook routes |

### VATSIM_TMI (Azure SQL) - Unified TMI Database

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Programs** | `tmi_programs` | [[Database Schema]] - GS, GDP, AFP program registry |
| **Slots** | `tmi_slots` | [[Database Schema]] - FSM-format arrival slots |
| **Flight Control** | `tmi_flight_control` | [[Database Schema]] - Per-flight EDCTs/CTAs |
| **Events** | `tmi_events` | [[Database Schema]] - Audit log |
| **Pop-ups** | `tmi_popup_queue` | [[Database Schema]] - Pop-up detection queue |

### VATSIM_ADL (Azure SQL)

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Flight Core** | `adl_flight_core`, `adl_flight_position`, `adl_flight_plan`, `adl_flight_times`, `adl_flight_aircraft` | [[Database Schema]] |
| **Route Data** | `adl_parse_queue`, `adl_flight_waypoints`, `adl_flight_trajectory` | [[Algorithm Route Parsing]] |
| **Boundaries** | `adl_boundary`, `adl_flight_boundary_log`, `adl_flight_planned_crossings` | [[Database Schema]] |
| **TMI/NTML (Legacy)** | `ntml`, `ntml_info`, `ntml_slots`, `adl_flight_tmi` | [[TMI API]] - Being migrated to VATSIM_TMI |
| **Reroutes** | `tmi_reroutes`, `tmi_reroute_flights`, `tmi_reroute_compliance_log` | [[TMI API]] |
| **Airport Config** | `airport_config`, `airport_config_runway`, `airport_config_rate`, `runway_in_use`, `vatsim_atis` | [[Database Schema]] |
| **Modifiers** | `modifier_category`, `modifier_type`, `config_modifier` | [[Database Schema]] |
| **Demand Monitors** | `demand_monitors` | [[ADL API]] |
| **Simulator Reference** | `sim_ref_carrier_lookup`, `sim_ref_route_patterns`, `sim_ref_airport_demand` | [[ATFM Training Simulator]] |

### perti_site (MySQL)

| Table Group | Tables | Documentation |
|-------------|--------|---------------|
| **Users** | `users`, `admin_users` | [[Getting Started]] |
| **Planning** | `p_plans`, `p_configs`, `p_op_goals`, `p_forecast` | [[Creating PERTI Plans]] |
| **Staffing** | `p_terminal_staffing`, `p_enroute_staffing`, `p_dcc_staffing` | [[Creating PERTI Plans]] |
| **Initiatives** | `p_terminal_init_timeline`, `p_enroute_init_timeline` | [[Creating PERTI Plans]] |
| **Playbook** | `playbook_plays`, `playbook_routes`, `playbook_changelog` | [[Playbook]] |
| **Review** | `r_scores`, `r_comments`, `r_data`, `r_ops_data` | [[Database Schema]] |
| **CDR/Routes** | `route_cdr`, `route_playbook` | [[Database Schema]] |

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
| **GDT** | `/api/gdt/` | `programs/*`, `flights/*`, `slots/*`, `demand/*` | [[GDT Ground Delay Tool]] - Uses VATSIM_TMI |
| **GIS** | `/api/gis/` | `boundaries.php`, `trajectory.php`, `adjacency.php`, `proximity.php` | [[GIS API]] - Route expansion, boundaries, adjacency network |
| **Simulator** | `/api/simulator/` | `engine.php`, `traffic.php`, `navdata.php` | [[ATFM Training Simulator]] |
| **Data** | `/api/data/` | `weather.php`, `sua.php`, `tfr.php`, `routes.php` | [[API Reference]] |
| **Routes** | `/api/routes/` | `public.php`, `public_post.php` | [[API Reference]] |
| **Management** | `/api/mgt/` | CRUD for configs, comments, TMI elements | Internal |

---

## By Daemon / Background Process

All 15 daemons are started via `scripts/startup.sh` at App Service boot.

| Daemon | Purpose | Cycle | Code |
|--------|---------|-------|------|
| ADL Ingest | VATSIM data sync + ATIS | 15s | `scripts/vatsim_adl_daemon.php` |
| Parse Queue (GIS) | Route parsing via PostGIS | 10s batch | `adl/php/parse_queue_gis_daemon.php` |
| Boundary Detection (GIS) | ARTCC/TRACON detection | 15s | `adl/php/boundary_gis_daemon.php` |
| Crossing Calculation | Boundary crossing ETAs | Tiered | `adl/php/crossing_gis_daemon.php` |
| Waypoint ETA | Waypoint-level ETAs | Tiered | `adl/php/waypoint_eta_daemon.php` |
| SWIM WebSocket | Real-time flight events | Persistent | `scripts/swim_ws_server.php` |
| SWIM Sync | ADL → SWIM_API sync | 2min | `scripts/swim_sync_daemon.php` |
| SimTraffic Poll | SimTraffic time data | 2min | `scripts/simtraffic_swim_poll.php` |
| Reverse Sync | SimTraffic → ADL sync | 2min | `scripts/swim_adl_reverse_sync_daemon.php` |
| Scheduler | Splits/routes activation | 60s | `scripts/scheduler_daemon.php` |
| Archival | Trajectory tiering | 1-4h | `scripts/archival_daemon.php` |
| Monitoring | System metrics | 60s | `scripts/monitoring_daemon.php` |
| Discord Queue | TMI Discord posting | Continuous | `scripts/tmi/process_discord_queue.php` |
| Event Sync | VATUSA/VATCAN events | 6h | `scripts/event_sync_daemon.php` |
| ADL Archive | Trajectory blob storage | Daily 10:00Z | `scripts/adl_archive_daemon.php` (conditional) |

See [[Daemons and Scripts]] for full documentation.

---

## By Stored Procedure

### ADL Core Procedures

| Procedure | Purpose | Called From |
|-----------|---------|-------------|
| `sp_Adl_RefreshFromVatsim_Staged` | Main 13-step VATSIM data refresh (V9.3.0) | `vatsim_adl_daemon.php` |
| `sp_ProcessTrajectoryBatch` | Basic ETA calculation | Deferred from ADL ingest |
| `sp_CalculateETABatch` | Wind-adjusted ETA batch | Deferred from ADL ingest |
| `sp_CapturePhaseSnapshot` | Flight phase count snapshot | Deferred from ADL ingest |
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

### Wiki Pages (46 total)

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
- [[GIS API]] - GIS boundaries and route expansion API
- [[JATOC API]] - Incident API

**Features**
- [[GDT Ground Delay Tool]] - GDP/GS management
- [[Route Plotter]] - TSD-style flight map
- [[Playbook]] - Pre-coordinated route play catalog (v18)
- [[JATOC]] - Incident monitoring
- [[NOD Dashboard]] - NAS operations dashboard
- [[Splits]] - Sector configuration with strata filtering (v18)
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
- [[AIRAC Update]] - Navigation data update guide
- [[FMDS Comparison]] - FMDS vs PERTI functional analysis
- [[Navigation Helper]] - This page (quick lookup)

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
| Where's the VATSIM sync code? | `scripts/vatsim_adl_daemon.php` + `sp_Adl_RefreshFromVatsim_Staged` (V9.3.0) |
| How do Ground Stops work? | `tmi_programs` table (VATSIM_TMI) with `program_type='GS'` |
| Where are reroutes defined? | `tmi_reroutes` table |
| What's the demand monitor table? | `demand_monitors` |
| How do I get real-time stats? | `GET /api/stats/realtime.php` |

### Key File Locations

| File | Purpose |
|------|---------|
| `status.php` | System status dashboard |
| `demand.php` | Airport demand analysis page |
| `route.php` | Route plotter / TSD map |
| `playbook.php` | Playbook route play catalog (v18) |
| `gdt.php` | Ground Delay Tool interface |
| `tmi-publish.php` | TMI publishing to Discord (NTML/advisories) |
| `simulator.php` | ATFM Training Simulator |
| `airport_config.php` | Airport configuration management |
| `load/config.php` | Application configuration |
| `load/connect.php` | Database connections |

---

*Last updated: 2026-02-25*
