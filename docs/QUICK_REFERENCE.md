# PERTI Quick Reference Index

Quick lookup for common codebase elements. Last updated: 2026-01-16

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

### Airspace Element Demand (v17)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/adl/demand/fix.php` | GET | Flights at a navigation fix |
| `/api/adl/demand/airway.php` | GET | Flights on an airway segment |
| `/api/adl/demand/segment.php` | GET | Flights between two fixes (airway/DCT) |
| `/api/adl/demand/batch.php` | GET | Multi-monitor time-bucketed demand |
| `/api/adl/demand/monitors.php` | GET/POST/DELETE | Demand monitor CRUD |
| `/api/adl/demand/details.php` | GET | Individual flights for a monitor |

### TMI Operations

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

### Airport Configuration

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/demand/airports.php` | GET | List airports with demand data |
| `/api/demand/summary.php` | GET | Demand summary |
| `/api/demand/rates.php` | GET | Airport rate data |
| `/api/demand/override.php` | POST | Manual rate override |

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

### PHP Daemons

| Daemon | File | Interval | Purpose |
|--------|------|----------|---------|
| Parse Queue | `adl/php/parse_queue_daemon.php` | 5s | Route parsing |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | 15s tiered | Waypoint ETA calc (v17) |
| Boundary | `adl/php/boundary_daemon.php` | 15s adaptive | Boundary detection (v17) |

### Python Daemons

| Daemon | File | Interval | Purpose |
|--------|------|----------|---------|
| ATIS | `scripts/vatsim_atis/atis_daemon.py` | 15s | VATSIM ATIS import |
| Events | `scripts/statsim/daily_event_update.py` | Daily | VATUSA event sync |

### Import Scripts

| Script | File | Schedule | Purpose |
|--------|------|----------|---------|
| Weather Alerts | `adl/php/import_weather_alerts.php` | 5 min | SIGMET/AIRMET |
| Wind Data | `adl/php/import_wind_data.php` | Hourly | NOAA RAP/GFS |
| Boundaries | `adl/php/import_boundaries.php` | On-demand | ARTCC/TRACON |

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

### TMI Tables

| Table | Purpose |
|-------|---------|
| `ground_stop_programs` | Ground Stop definitions |
| `ground_stop_flights` | Flights affected by GS with EDCTs |
| `gdp_programs` | GDP definitions |
| `gdp_slots` | GDP slot allocations |

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

### JavaScript Components

| Component | File | Purpose |
|-----------|------|---------|
| Demand Chart | `assets/js/demand/*.js` | Demand visualization |
| Map/Route | `assets/js/map/*.js` | MapLibre integration |
| Simulator | `assets/js/simulator/*.js` | Simulator UI controllers |

### Configuration

| File | Purpose |
|------|---------|
| `load/config.php` | Database and API config |
| `load/connect.php` | Database connections |

---

## Migration Sequences

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

### v15 Migrations (TMI)

| Range | Purpose |
|-------|---------|
| `tmi/001` | NTML schema |
| `tmi/002` | GS procedures |
| `tmi/003` | GS indexes |

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
