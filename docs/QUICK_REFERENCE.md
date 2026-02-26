# PERTI Quick Reference Index

Quick lookup for common codebase elements. Last updated: 2026-02-25

---

## API Endpoints Index

### ADL Flight Data

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/adl/current.php` | GET | Current flights snapshot |
| `/api/adl/flight.php` | GET | Single flight lookup |
| `/api/adl/stats.php` | GET | Flight statistics |
| `/api/adl/snapshot_history.php` | GET | Historical snapshots |
| `/api/adl/trajectory.php` | GET | Flight trajectory points |
| `/api/adl/crossings.php` | GET | Boundary crossing data |

### Airspace Element Demand

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/adl/demand/fix.php` | GET | Flights at a navigation fix |
| `/api/adl/demand/airway.php` | GET | Flights on an airway segment |
| `/api/adl/demand/segment.php` | GET | Flights between two fixes (airway/DCT) |
| `/api/adl/demand/batch.php` | GET | Multi-monitor time-bucketed demand |
| `/api/adl/demand/monitors.php` | GET/POST/DELETE | Demand monitor CRUD |
| `/api/adl/demand/details.php` | GET | Individual flights for a monitor |

### TMI Operations (Legacy GS API)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/tmi/gs/create.php` | POST | Create Ground Stop |
| `/api/tmi/gs/model.php` | POST | Model GS scope |
| `/api/tmi/gs/activate.php` | POST | Activate GS |
| `/api/tmi/gs/extend.php` | POST | Extend GS |
| `/api/tmi/gs/purge.php` | POST | Cancel GS |
| `/api/tmi/gs/list.php` | GET | List GS programs |
| `/api/tmi/gs/flights.php` | GET | Get affected flights |
| `/api/tmi/gdp/create.php` | POST | Create GDP |

### TMI Database API (v17 - VATSIM_TMI)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/tmi/` | GET | API info and endpoints |
| `/api/tmi/active.php` | GET | All active TMI data |
| `/api/tmi/entries.php` | GET/POST/PUT/DELETE | NTML entries CRUD |
| `/api/tmi/programs.php` | GET/POST/PUT/DELETE | GDT programs CRUD |
| `/api/tmi/advisories.php` | GET/POST/PUT/DELETE | Advisories CRUD |
| `/api/tmi/public-routes.php` | GET/POST/PUT/DELETE | Public routes CRUD |
| `/api/tmi/reroutes.php` | GET/POST/PUT/DELETE | Reroutes CRUD |

### Airport Configuration

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/demand/airports.php` | GET | List airports with demand data |
| `/api/demand/summary.php` | GET | Demand summary |
| `/api/demand/rates.php` | GET | Airport rate data |
| `/api/demand/override.php` | POST | Manual rate override |

### NOD Flow APIs (v18)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/nod/flows/configs.php` | GET/POST/PUT/DELETE | Flow configuration CRUD |
| `/api/nod/flows/elements.php` | GET/POST/PUT/DELETE | Flow element CRUD |
| `/api/nod/flows/gates.php` | GET/POST/PUT/DELETE | Flow gate CRUD |
| `/api/nod/flows/suggestions.php` | GET | Element autocomplete |
| `/api/nod/fea.php` | POST | FEA bridge (demand monitor toggle, bulk ops) |

### TMR APIs (v18)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/data/review/tmr_report.php` | GET/POST/PUT/DELETE | TMR report CRUD (auto-save) |
| `/api/data/review/tmr_tmis.php` | GET | Historical TMI lookup |
| `/api/data/review/tmr_export.php` | GET | Discord-formatted TMR export |

### Playbook (v18)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/data/playbook/list.php` | GET | List plays (category/source filters) |
| `/api/data/playbook/get.php` | GET | Get single play with routes |
| `/api/data/playbook/categories.php` | GET | Play categories |
| `/api/data/playbook/changelog.php` | GET | Play change history |
| `/api/mgt/playbook/save.php` | POST | Create/update play |
| `/api/mgt/playbook/route.php` | POST | Add/update play routes |
| `/api/mgt/playbook/delete.php` | DELETE | Delete play |

### Splits Management

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/splits/areas.php` | GET | Area definitions |
| `/api/splits/configs.php` | GET | Configurations |
| `/api/splits/active.php` | GET | Active splits |
| `/api/splits/presets.php` | GET | Split presets |
| `/api/splits/maps.php` | GET | Sector map data |
| `/api/splits/scheduled.php` | GET | Scheduled splits |
| `/api/splits/scheduler.php` | POST | Schedule management |

### ATFM Simulator

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/simulator/navdata.php` | GET | Navigation data for routing |
| `/api/simulator/routes.php` | GET | Route pattern data |
| `/api/simulator/engine.php` | GET/POST | Engine control |
| `/api/simulator/traffic.php` | GET/POST | Traffic generation |

---

## Stored Procedures Index

### Flight Processing

| Procedure | File | Purpose |
|-----------|------|---------|
| `sp_ParseRoute` | `adl/procedures/sp_ParseRoute.sql` | Parse flight routes into waypoints |
| `sp_ParseQueue` | `adl/procedures/sp_ParseQueue.sql` | Process queued routes |
| `sp_RouteDistanceBatch` | `adl/procedures/sp_RouteDistanceBatch.sql` | Batch route distance calc |

### ETA & Trajectory

| Procedure | File | Purpose |
|-----------|------|---------|
| `sp_CalculateETA` | `adl/procedures/sp_CalculateETA.sql` | Single flight ETA |
| `sp_CalculateETABatch` | `adl/procedures/sp_CalculateETABatch.sql` | Batch ETA calculation |
| `sp_CalculateWaypointETA` | `adl/procedures/sp_CalculateWaypointETA.sql` | Waypoint ETAs |
| `sp_CalculateWaypointETABatch_Tiered` | `adl/procedures/sp_CalculateWaypointETABatch_Tiered.sql` | Tiered waypoint ETA (v17) |

### Boundary Detection

| Procedure | File | Purpose |
|-----------|------|---------|
| `sp_ProcessBoundaryDetectionBatch` | `adl/procedures/sp_ProcessBoundaryDetectionBatch.sql` | Boundary crossing detection |
| `sp_ProcessBoundaryAndCrossings_Background` | `adl/procedures/sp_ProcessBoundaryAndCrossings_Background.sql` | Background boundary daemon (v17) |
| `fn_DetectCurrentZone` | `adl/procedures/fn_DetectCurrentZone.sql` | Current zone identification |

### Ground Stop / TMI

| Procedure | File | Purpose |
|-----------|------|---------|
| `sp_GS_Create` | `adl/migrations/tmi/002_gs_procedures.sql` | Create proposed GS |
| `sp_GS_Model` | `adl/migrations/tmi/002_gs_procedures.sql` | Model GS scope |
| `sp_GS_IssueEDCTs` | `adl/migrations/tmi/002_gs_procedures.sql` | Activate GS |
| `sp_GS_Extend` | `adl/migrations/tmi/002_gs_procedures.sql` | Extend GS |
| `sp_GS_Purge` | `adl/migrations/tmi/002_gs_procedures.sql` | Cancel GS |

### Airspace Demand Functions (v17)

| Function | File | Purpose |
|----------|------|---------|
| `fn_FixDemand` | `adl/migrations/demand/002_fn_FixDemand.sql` | Flights at a fix |
| `fn_AirwaySegmentDemand` | `adl/migrations/demand/003_fn_AirwaySegmentDemand.sql` | Flights on airway segment |
| `fn_RouteSegmentDemand` | `adl/migrations/demand/004_fn_RouteSegmentDemand.sql` | Flights between fixes |

### Rate & Config

| Procedure | File | Purpose |
|-----------|------|---------|
| `sp_GetRateSuggestion` | `adl/migrations/085_rate_suggestion_proc.sql` | Multi-level rate suggestion |
| `sp_ImportAtis` | `adl/migrations/086_atis_import_proc.sql` | ATIS batch import |
| `sp_ApplyManualRateOverride` | `adl/migrations/088_manual_override_proc.sql` | Manual rate override |

---

## Daemons & Scripts Index

### PHP Daemons (15 total, all started via startup.sh)

| Daemon | File | Interval | Purpose |
|--------|------|----------|---------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s | Flight data + ATIS |
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Route parsing with PostGIS |
| Boundary (GIS) | `adl/php/boundary_gis_daemon.php` | 15s | Spatial boundary detection |
| Crossing Calc | `adl/php/crossing_gis_daemon.php` | Tiered | Boundary crossing ETAs |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered | Waypoint ETA calc |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent | Real-time events (port 8090) |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min | ADL → SWIM_API sync |
| SimTraffic Poll | `scripts/simtraffic_swim_poll.php` | 2min | SimTraffic time data |
| Reverse Sync | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic → ADL |
| Scheduler | `scripts/scheduler_daemon.php` | 60s | Splits/routes auto-activate |
| Archival | `scripts/archival_daemon.php` | 1-4h | Trajectory tiering, purge |
| Monitoring | `scripts/monitoring_daemon.php` | 60s | System metrics |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous | TMI Discord posting |
| Event Sync | `scripts/event_sync_daemon.php` | 6h | Event sync (VATUSA/VATCAN) |
| ADL Archive | `scripts/adl_archive_daemon.php` | Daily 10:00Z | Trajectory archival (conditional) |

### Import Scripts

| Script | File | Schedule | Purpose |
|--------|------|----------|---------|
| Weather Alerts | `adl/php/import_weather_alerts.php` | 5 min | SIGMET/AIRMET |
| Wind Data | `adl/php/import_wind_data.php` | Hourly | NOAA RAP/GFS |
| Boundaries | `adl/php/import_boundaries.php` | On-demand | ARTCC/TRACON |
| NASR Update | `scripts/nasr_navdata_updater.py` | AIRAC cycle | FAA navdata |
| AIRAC Full | `scripts/airac_full_update.py` | 28 days | Full AIRAC update |

---

## Database Tables Index

### Core Flight Tables (Azure SQL)

| Table | Purpose |
|-------|---------|
| `adl_flight_core` | Primary flight identifiers and state |
| `adl_flight_position` | Current position, altitude, speed |
| `adl_flight_plan` | Flight plan details, route string |
| `adl_flight_waypoints` | Parsed route waypoints with ETAs |
| `adl_flight_history` | Historical flight snapshots |

### TMI Tables (VATSIM_ADL - Legacy)

| Table | Purpose |
|-------|---------|
| `ground_stop_programs` | Ground Stop definitions |
| `ground_stop_flights` | Flights affected by GS with EDCTs |
| `gdp_programs` | GDP definitions |
| `gdp_slots` | GDP slot allocations |

### TMI Tables (VATSIM_TMI)

| Table | Purpose |
|-------|---------|
| `tmi_entries` | NTML log (MIT, MINIT, DELAY, etc.) |
| `tmi_programs` | GS/GDP/AFP programs |
| `tmi_slots` | GDP slot allocation |
| `tmi_flight_control` | Per-flight TMI control records |
| `tmi_advisories` | Formal advisories |
| `tmi_reroutes` | Reroute definitions |
| `tmi_reroute_routes` | Reroute route strings per O/D pair |
| `tmi_reroute_flights` | Flight assignments to reroutes |
| `tmi_reroute_compliance_log` | Compliance history |
| `tmi_public_routes` | Public route display |
| `tmi_proposals` | Multi-facility coordination |
| `tmi_airport_configs` | TMI airport config snapshots |
| `tmi_delay_entries` | Delay reports (v18) |
| `tmi_discord_posts` | Discord posting queue |
| `tmi_events` | Unified audit log |
| `tmi_advisory_sequences` | Advisory numbering |

### NOD Flow Tables (v18)

| Table | Purpose |
|-------|---------|
| `facility_flow_configs` | Flow configuration definitions |
| `facility_flow_elements` | Elements (FIX/PROCEDURE/ROUTE/GATE) |
| `facility_flow_gates` | Gate definitions with coordinates |

### MySQL Tables (v18)

| Table | Purpose |
|-------|---------|
| `r_tmr_reports` | TMR review reports (auto-save) |

### Airport Configuration (v16)

| Table | Purpose |
|-------|---------|
| `airport_config` | Runway configurations |
| `airport_config_runway` | Runways per config |
| `airport_config_rate` | Rates per config/weather |
| `vatsim_atis` | Raw ATIS with weather data |
| `manual_rate_override` | Manual rate overrides |

### Config Modifiers (v17)

| Table | Purpose |
|-------|---------|
| `modifier_category` | Modifier categories |
| `modifier_type` | Modifier definitions |
| `config_modifier` | Config/runway modifier links |

### Simulator Reference (v17)

| Table | Records | Purpose |
|-------|---------|---------|
| `sim_ref_carrier_lookup` | 17 | US carriers |
| `sim_ref_route_patterns` | 3,989 | O-D routes |
| `sim_ref_airport_demand` | 107 | Airport demand curves |

### Demand Monitors (v17)

| Table | Purpose |
|-------|---------|
| `demand_monitors` | Shared demand monitor definitions |

---

## Key Views Index

### ATIS Views (v17)

| View | Purpose |
|------|---------|
| `vw_current_atis_by_type` | Current ATIS by airport/type |
| `vw_effective_atis` | Effective ATIS source (ARR+DEP > COMB) |
| `vw_current_runways_in_use` | Active runway assignments |
| `vw_current_airport_config` | Current config summary |

### Config Views

| View | Purpose |
|------|---------|
| `vw_config_with_modifiers` | Configs with aggregated modifiers |
| `vw_runway_with_modifiers` | Runways with aggregated modifiers |

---

## Key File Locations

### Frontend Pages

| Page | File | Purpose |
|------|------|---------|
| Demand Analysis | `demand.php` | Airport demand visualization |
| Route Plotter | `route.php` | TSD-style flight display |
| GDT | `gdt.php` | Ground Delay Tool |
| ATFM Simulator | `simulator.php` | TMU training tool |
| NOD Dashboard | `nod.php` | NAS Operations Dashboard |
| JATOC | `jatoc.php` | Incident monitoring |
| Splits | `splits.php` | Sector configuration |
| Plan | `plan.php` | Planning worksheets |
| Transparency | `transparency.php` | Infrastructure transparency page |
| Airport Configs | `airport_config.php` | Runway configuration |
| Airspace Elements | `airspace-elements.php` | Custom airspace elements |
| TMI Publisher | `tmi-publish.php` | TMI publishing to Discord |
| Playbook | `playbook.php` | Route play catalog (FAA/DCC/ECFMP/CANOC) |
| System Status | `status.php` | System health dashboard |

### JavaScript Components

| Component | File | Purpose |
|-----------|------|---------|
| Demand Chart | `assets/js/demand/*.js` | Demand visualization |
| Map/Route | `assets/js/map/*.js` | MapLibre integration |
| Simulator | `assets/js/simulator/*.js` | Simulator UI controllers |

### i18n System (v18)

| File | Purpose |
|------|---------|
| `assets/js/lib/i18n.js` | Core translation module (PERTII18n) |
| `assets/js/lib/dialog.js` | SweetAlert2 wrapper with i18n (PERTIDialog) |
| `assets/locales/en-US.json` | 450+ translation keys |
| `assets/locales/index.js` | Locale auto-detection loader |

### Configuration

| File | Purpose |
|------|---------|
| `load/config.php` | Database and API config |
| `load/connect.php` | Database connections ($conn_adl, $conn_swim, $conn_tmi, $conn_ref, $conn_gis) |

### TMI API Files (v17)

| File | Purpose |
|------|---------|
| `api/tmi/helpers.php` | Common functions, auth, response handling |
| `api/tmi/index.php` | API info endpoint |
| `api/tmi/active.php` | All active TMI data |
| `api/tmi/entries.php` | NTML entries CRUD |
| `api/tmi/programs.php` | GDT programs CRUD |
| `api/tmi/advisories.php` | Advisories CRUD |
| `api/tmi/public-routes.php` | Public routes CRUD |

---

## Migration Sequences

### v18 Migrations

| File | Purpose |
|------|---------|
| `database/migrations/nod/001_facility_flow_tables.sql` | NOD flow configs, elements, gates |
| `database/migrations/nod/002_flow_element_fea_linkage.sql` | FEA linkage for flow elements |
| `r_tmr_reports` table (MySQL) | TMR review reports |
| `adl/migrations/oooi/010_airport_taxi_reference.sql` | Airport taxi reference data |

### v17 Migrations

| File | Purpose |
|------|---------|
| `092_modifier_category.sql` | Modifier categories |
| `093_modifier_type.sql` | Modifier types |
| `094_config_modifier.sql` | Config modifier links |
| `095_atis_type_priority.sql` | ATIS type priority views |
| `demand/001_indexes.sql` | Demand query indexes |
| `demand/002_fn_FixDemand.sql` | Fix demand function |
| `demand/003_fn_AirwaySegmentDemand.sql` | Airway segment function |
| `demand/004_fn_RouteSegmentDemand.sql` | Route segment function |

### v16 Migrations

| Range | Purpose |
|-------|---------|
| `079-083` | Airport config schema |
| `084` | Weather category function |
| `085-090` | Rate suggestion/override |
| `091` | Weather radar integration |

### v15 Migrations (TMI - VATSIM_ADL)

| Range | Purpose |
|-------|---------|
| `tmi/001` | NTML schema |
| `tmi/002` | GS procedures |
| `tmi/003` | GS indexes |

### v17 Migrations (TMI - VATSIM_TMI)

| File | Purpose |
|------|---------|
| `database/migrations/tmi/001_tmi_core_schema_azure_sql.sql` | Full TMI schema (10 tables, 6 views, 4 procedures) |
| `database/migrations/tmi/002_create_tmi_user.sql` | TMI_admin user creation |

---

## Common Acronyms

| Acronym | Definition |
|---------|------------|
| ADL | Aggregate Demand List |
| GS | Ground Stop |
| GDP | Ground Delay Program |
| EDCT | Expected Departure Clearance Time |
| AAR | Airport Arrival Rate |
| ADR | Airport Departure Rate |
| TMI | Traffic Management Initiative |
| ATFM | Air Traffic Flow Management |
| DCT | Direct (route without airway) |
| FCA | Flow Constrained Area |

---

## See Also

- [STATUS.md](STATUS.md) - Component status tracking
- [wiki/API-Reference.md](../wiki/API-Reference.md) - Full API documentation
- [wiki/Database-Schema.md](../wiki/Database-Schema.md) - Schema reference
- [wiki/Changelog.md](../wiki/Changelog.md) - Version history
