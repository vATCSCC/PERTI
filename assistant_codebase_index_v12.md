# vATCSCC PERTI — Codebase Index (v12)

Generated: 2026-01-03 UTC

This index is based on the `wwwroot.zip` snapshot provided in this chat, and is intended as a fast reference for navigation, call graphs, and high-signal files.

## 1) High-level architecture

### 1.1 Top-level layout

- `index.php` — landing/dashboard
- `plan.php`, `review.php`, `schedule.php`, `sheet.php` — planning workflow pages (MySQL-backed)
- `gdt.php` — **Ground Delay Tool** (GDT) page (FSM-style GDP interface)
- `reroutes.php`, `reroutes_index.php` — reroute authoring + monitoring (Azure SQL-backed)
- `route.php` — TSD-style live flight map + route plotting (Azure SQL-backed; Leaflet/MapLibre)
- `splits.php` — sector/position split configuration map (Azure SQL-backed; MapLibre)
- `jatoc.php` — JATOC AWO Incident Monitor (Azure SQL-backed; MapLibre)
- `nod.php` — **NAS Operations Dashboard** (consolidated monitoring; MapLibre)

### 1.2 Data stores

- **MySQL** (via `mysqli_*`): plans, schedules, configs, comments, initiative timelines, and `tmi_ground_stops` definitions.
- **Azure SQL** (via `sqlsrv_*`): ADL live flight state (`dbo.adl_flights`) + TMI workflows (GS/GDP/Reroute/Splits/History) + JATOC incidents + NOD advisories.

### 1.3 Background/refresh jobs

- `scripts/vatsim_adl_daemon.php` — pulls VATSIM feed every ~15s and calls `sp_Adl_RefreshFromVatsim` to refresh `dbo.adl_flights`.
- `scripts/refresh_vatsim_boundaries.php` — refreshes `assets/geojson/artcc.json` and `assets/geojson/tracon.json` from official VATSIM sources.
- `scripts/update_playbook_routes.py` — updates `assets/data/playbook_routes.csv` (playbook route reference).
- `nasr_navdata_updater.py` — NASR Navigation Data Updater for FAA AIRAC cycles

## 2) Boot / shared includes

- `load/config.php` — local configuration (DB creds, site config, ADL SQL constants).
- `load/connect.php` — initializes DB connections:
  - `$conn` (MySQL)
  - `$conn_adl` (Azure SQL / sqlsrv)
- `load/header.php`, `load/nav.php`, `load/footer.php` — shared layout and script/style includes.
- `includes/gdp_section.php` — GDP/GDT section component (reusable)
- `.htaccess` — rewrite rule for extensionless `/api/...` requests to resolve `.php` endpoints.

## Quick navigation

- [1) High-level architecture](#1-high-level-architecture)
- [2) Boot / shared includes](#2-boot--shared-includes)
- [3) Database schema quick index](#3-database-schema-quick-index)
- [4) Main pages (PHP entrypoints)](#4-main-pages-php-entrypoints)
- [5) Client-side modules (`assets/js/`)](#5-client-side-modules-assetsjs)
- [6) API endpoints index (`/api/...`)](#6-api-endpoints-index-api)
- [7) Database migrations and schema artifacts](#7-database-migrations-and-schema-artifacts)
- [8) Reference data and GeoJSON](#8-reference-data-and-geojson)
- [9) Operational scripts (`scripts/`)](#9-operational-scripts-scripts)
- [10) Ground Stop (GS) subsystem](#10-ground-stop-gs-subsystem--detailed-notes)
- [11) GDP / GDT subsystem](#11-gdp--gdt-ground-delay-program-subsystem--detailed-notes)
- [12) Reroute subsystem](#12-reroute-subsystem--detailed-notes)
- [13) Splits subsystem](#13-splits-subsystem--detailed-notes)
- [14) Live Flights TSD / route.php](#14-live-flights-tsd--routephp--detailed-notes)
- [15) ADL history snapshotting](#15-adl-history-snapshotting-and-tracks)
- [16) `sp_Adl_RefreshFromVatsim`](#16-stored-procedure-sp_adl_refreshfromvatsim--notes)
- [17) Public Routes subsystem](#17-public-routes-subsystem)
- [18) JATOC subsystem](#18-jatoc-subsystem)
- [19) StatSim subsystem](#19-statsim-subsystem)
- [20) NASR Navigation Data Updater](#20-nasr-navigation-data-updater)
- [21) NOD subsystem](#21-nod-nas-operations-dashboard-subsystem)
- [22) ADL Refresh Patterns](#22-adl-refresh-patterns)
- [23) Initiative Timeline subsystem (NEW v12)](#23-initiative-timeline-subsystem--new-v12)
- [24) Practical gotchas](#24-practical-gotchas-to-remember)
- [25) Search anchors](#25-search-anchors-fast-grep-patterns)
- [26) Changelog](#26-changelog)
- [27) VATSIM_ADL Azure SQL Database Reference](#27-vatsim_adl-azure-sql-database-reference)
- [28) VATSIM_PERTI MySQL Database Reference](#28-vatsim_perti-mysql-database-reference)

## 3) Database schema quick index

### 3.1 MySQL tables (perti_site schema)

- `users`
- `admin_users`
- `config_data`
- `p_plans`
- `p_dcc_staffing`
- `p_forecast`
- `p_historical`
- `p_configs`
- `p_terminal_init`
- `p_terminal_init_times`
- `p_terminal_init_timeline` **(NEW v12)**
- `p_terminal_staffing`
- `p_terminal_planning`
- `p_terminal_constraints`
- `p_enroute_init`
- `p_enroute_init_times`
- `p_enroute_init_timeline` **(NEW v12)**
- `p_enroute_staffing`
- `p_enroute_planning`
- `p_enroute_constraints`
- `p_group_flights`
- `p_op_goals`
- `r_scores`
- `r_comments`
- `r_data`
- `r_ops_data`
- `route_cdr`
- `route_playbook`
- `tmi_ground_stops`
- `assigned`

#### `tmi_ground_stops` columns (MySQL)

- `status:int:1`
- `name:varchar:64`
- `ctl_element:varchar:4`
- `element_type:varchar:8`
- `airports:text`
- `start_utc:varchar:20`
- `end_utc:varchar:20`
- `prob_ext:int:1`
- `origin_centers:text`
- `origin_airports:text`
- `flt_incl_carrier:varchar:64`
- `flt_incl_type:varchar:4`
- `dep_facilities:text`
- `comments:text`
- `adv_number:varchar:16`
- `advisory_text:text`

#### `p_terminal_init_timeline` / `p_enroute_init_timeline` columns (MySQL) **(NEW v12)**

- `id:int` (PK, auto-increment)
- `p_id:int` (FK to p_plans)
- `facility:varchar:255` — comma-separated facility codes
- `area:varchar:255` — area/sector (optional)
- `tmi_type:varchar:50` — GS, GDP, MIT, MINIT, CFR, APREQ, Reroute, AFP, FEA, FCA, CTOP, ICR, TBO, Metering, TBM, TBFM, Other
- `tmi_type_other:varchar:100` — custom type when tmi_type = 'Other'
- `cause:varchar:255` — cause/context
- `start_datetime:datetime` — start date/time in UTC
- `end_datetime:datetime` — end date/time in UTC
- `level:varchar:50` — CDW, Possible, Probable, Expected, Active, Advisory_Terminal, Advisory_EnRoute, Constraint_Terminal, Constraint_EnRoute, Special_Event, Space_Op, Staffing, VIP, Misc
- `notes:text`
- `created_at:datetime`
- `updated_at:datetime`
- `created_by:varchar:50`

### 3.2 Azure SQL / ADL tables (high-signal subset)

This project uses Azure SQL (via `sqlsrv_*` and `$conn_adl`) for live flight state + TMI workflows. Full table/column listing is in Section 27.

- **dbo.adl_flights** — Live flight snapshot table (refreshed from VATSIM; updated by GS/GDP/Reroute/SimTraffic workflows)
- **dbo.apts** — Airport reference table (ICAO → DCC region, ASPM77/OEP35/Core30, lat/lon, etc.)
- **dbo.adl_flights_gs** — GS simulation/sandbox table (seeded from adl_flights)
- **dbo.adl_flights_gdp** — GDP simulation/sandbox table (seeded from adl_flights)
- **dbo.adl_slots_gdp** — GDP slot allocations (15-min bins)
- **dbo.adl_edct_overrides** — EDCT override storage for manual delay assignments
- **dbo.gdp_log** — GDP program log (config/state/metrics)
- **dbo.tmi_reroutes / dbo.tmi_reroute_flights / dbo.tmi_reroute_compliance_log** — Reroute definitions, assignments, and compliance history
- **dbo.splits_areas / dbo.splits_configs / dbo.splits_positions / dbo.splits_presets / dbo.splits_preset_positions** — Airspace splits: reusable sector groupings, split configurations, presets, and active position assignments
- **dbo.adl_flights_history (+ cold/cool) & vw_adl_flights_history_30d** — Track/history storage for flight position and route snapshots
- **dbo.adl_tmi_state** — State flags for active TMIs (used by server workflows)
- **dbo.public_routes** — Globally shared route advisories with GeoJSON caching

### 3.3 JATOC Azure SQL Tables

- **dbo.jatoc_incidents** — AWO incidents with status tracking (ATC_ZERO, ATC_ALERT, ATC_LIMITED, NON_RESPONSIVE)
- **dbo.jatoc_incident_updates** — Incident update history/remarks log
- **dbo.jatoc_updates** — Additional updates table
- **dbo.jatoc_daily_ops** — Daily operational items (POTUS schedule, Space activities)
- **dbo.jatoc_special_emphasis** — Special emphasis items with priority/active flags
- **dbo.jatoc_personnel** — JATOC position roster (JATOC1-10, SUP)
- **dbo.jatoc_ops_level** — Current operations level (1/2/3)
- **dbo.jatoc_sequences** — Sequence generators for incident/report numbers
- **dbo.jatoc_reports** — Structured report storage for closed incidents
- **dbo.vw_jatoc_active_incidents** — View for active incidents

### 3.4 NOD/DCC Advisory Azure SQL Tables

- **dbo.dcc_advisories** — FAA-style advisory database (subject, body, validity period, scope)
- **dbo.dcc_advisory_sequences** — Daily advisory number sequences
- **dbo.dcc_discord_tmi** — Discord TMI integration storage (for bot integration)
- **dbo.vw_dcc_advisories_today** — View for today's active advisories

### 3.5 Supporting Azure SQL Tables

- **dbo.ACD_Data** — Aircraft characteristics database (ICAO code, FAA designator, weight class, engine type)
- **dbo.aircraft_type_lookup** — Aircraft type reference
- **dbo.eta_citypair_lookup** — City pair ETA lookup
- **dbo.tracons** — TRACON reference data
- **dbo.adl_run_log** — ADL daemon run log
- **dbo.r_airport_totals** — Airport totals for reporting
- **dbo.r_hourly_rates** — Hourly rate calculations

## 4) Main pages (PHP entrypoints)

Top-level PHP pages in `/wwwroot` and which `assets/js/*` files they load.

- `configs.php`
- `data.php` — scripts: `assets/js/sheet.js`
- `gdt.php` — scripts: `assets/js/gdt.js`
- `index.php`
- `jatoc.php` — scripts: `assets/js/jatoc.js`
- `logout.php`
- `nod.php` — scripts: `assets/js/nod.js`
- `plan.php` — scripts: `assets/js/plan.js`, `assets/js/initiative_timeline.js` **(NEW v12)**
- `plan_bu.php` — scripts: `assets/js/plan.js`
- `privacy.php`
- `reroutes.php` — scripts: `assets/js/reroute.js`
- `reroutes_index.php`
- `review.php` — scripts: `assets/js/review.js`
- `route.php` — scripts: `assets/js/awys.js`, `assets/js/leaflet.textpath.js`, `assets/js/procs_enhanced.js`, `assets/js/route-maplibre.js`, `assets/js/route.js`
- `route_bu.php` — scripts: `assets/js/awys.js`, `assets/js/leaflet.textpath.js`, `assets/js/route_bu.js`
- `schedule.php` — scripts: `assets/js/schedule.js`
- `sheet.php` — scripts: `assets/js/sheet.js`
- `splits.php` — scripts: `assets/js/splits.js`
- `tmi.php` — scripts: `assets/js/gdp.js`, `assets/js/gdt.js`, `assets/js/tmi.js`

Notes:
- `reroutes_index.php` contains **inline** JS (no dedicated `assets/js/*` module).
- `route.php` uses a **feature flag** (`localStorage.useMapLibre` or `?maplibre=true`) to switch between Leaflet and MapLibre.
- `jatoc.php` and `nod.php` are publicly accessible (no auth required) for monitoring.

## 5) Client-side modules (`assets/js/`)

### 5.1 Page entry modules (directly loaded by a PHP page)

#### `awys.js`
- Loaded by: `route.php, route_bu.php`
- Calls APIs: _none detected_

#### `gdp.js`
- Loaded by: `tmi.php`
- Size: ~1,339 lines
- Calls APIs:
  - `api/tmi/gdp_apply.php`
  - `api/tmi/gdp_preview.php`
  - `api/tmi/gdp_purge.php`
  - `api/tmi/gdp_purge_local.php`
  - `api/tmi/gdp_simulate.php`

#### `gdt.js`
- Loaded by: `tmi.php`, `gdt.php`
- Size: ~6,876 lines
- Calls APIs:
  - `/api/tmi/gdp_apply.php`
  - `/api/tmi/gdp_preview.php`
  - `/api/tmi/gdp_purge.php`
  - `/api/tmi/gdp_simulate.php`

#### `initiative_timeline.js` **(NEW v12)**
- Loaded by: `plan.php`
- Size: ~1,281 lines
- Purpose: Interactive timeline visualization for terminal and enroute initiatives
- Calls APIs:
  - `api/data/plans/term_inits_timeline.php`
  - `api/data/plans/enroute_inits_timeline.php`
- Features:
  - Gantt-style timeline with color-coded levels
  - Modal-based CRUD for timeline entries
  - Real-time "now" line indicator
  - Facility filtering and sorting
  - Support for TMI types (GS, GDP, MIT, Reroute, etc.)
  - Constraint and VIP movement visualization

#### `jatoc.js`
- Loaded by: `jatoc.php`
- Size: ~1,171 lines
- Calls APIs:
  - `api/jatoc/incidents.php`
  - `api/jatoc/incident.php`
  - `api/jatoc/daily_ops.php`
  - `api/jatoc/personnel.php`
  - `api/jatoc/oplevel.php`
  - `api/jatoc/updates.php`
  - `api/jatoc/report.php`
  - `api/jatoc/special_emphasis.php`
  - `api/jatoc/vatusa_events.php`
  - `api/jatoc/faa_ops_plan.php`

#### `nod.js`
- Loaded by: `nod.php`
- Size: ~4,652 lines
- Calls APIs:
  - `api/nod/tmi_active.php` — consolidated active TMIs
  - `api/nod/advisories.php` — advisory CRUD
  - `api/nod/jatoc.php` — JATOC summary
  - `api/nod/discord.php` — Discord integration
  - `api/nod/tracks.php` — flight track history
  - `api/nod/tmu_oplevel.php` — TMU operations level
  - `api/routes/public.php` — public routes
  - `api/adl/current.php` — live traffic
  - `api/splits/active.php` — active splits
  - `api/jatoc/incidents.php` — incident details

#### `plan.js`
- Loaded by: `plan.php, plan_bu.php`
- Size: ~3,492 lines
- Calls APIs: various `api/data/plans/*` and `api/mgt/*` endpoints

#### `public-routes.js`
- Loaded by: via `route.php` (integrated with route-maplibre.js)
- Size: ~1,831 lines
- Calls APIs:
  - `api/routes/public.php`
  - `api/routes/public_post.php`
  - `api/routes/public_update.php`
  - `api/routes/public_delete.php`

#### `reroute.js`
- Loaded by: `reroutes.php`
- Size: ~654 lines
- Calls APIs:
  - `api/data/tmi/reroute.php`
  - `api/data/tmi/reroutes.php`
  - `api/mgt/tmi/reroutes/*`
  - `api/tmi/rr_*`

#### `review.js`
- Loaded by: `review.php`
- Size: ~412 lines
- Calls APIs: `api/data/review/*`

#### `route.js`
- Loaded by: `route.php`
- Size: ~8,222 lines
- Calls APIs:
  - `api/adl/current.php`
  - `api/adl/flight.php`
  - `api/data/routes.php`
  - `api/data/fixes.php`

#### `route-maplibre.js`
- Loaded by: `route.php` (when MapLibre mode active)
- Size: ~7,930 lines
- Calls APIs: Same as route.js, optimized for MapLibre GL

#### `route_bu.js`
- Loaded by: `route_bu.php`
- Size: ~5,796 lines
- Calls APIs: Similar to route.js

#### `schedule.js`
- Loaded by: `schedule.php`
- Size: ~199 lines
- Calls APIs: `api/data/schedule.php`, `api/mgt/schedule/*`

#### `sheet.js`
- Loaded by: `sheet.php, data.php`
- Size: ~244 lines
- Calls APIs: `api/data/sheet/*`

#### `splits.js`
- Loaded by: `splits.php`
- Size: ~7,277 lines
- Calls APIs:
  - `api/splits/configs.php`
  - `api/splits/config.php`
  - `api/splits/areas.php`
  - `api/splits/sectors.php`
  - `api/splits/presets.php`
  - `api/splits/tracons.php`
  - `api/splits/active.php`

#### `statsim_rates.js`
- Loaded by: `review.php`
- Size: ~1,187 lines
- Calls APIs:
  - `api/statsim/fetch.php`
  - `api/statsim/plan_info.php`
  - `api/statsim/save_rates.php`

### 5.2 Utility/support modules

#### `adl-service.js`
- Size: ~579 lines
- Purpose: Centralized ADL data management with subscriber pattern
- Features: Double-buffering, rate limiting, shared data across components
- Used by: Available for any page needing ADL data

#### `adl-refresh-utils.js`
- Size: ~580 lines
- Purpose: Double-buffering utilities for seamless data refresh
- Features: Buffered fetcher, table updater, prevents UI flashing
- Used by: Multiple pages for refreshing ADL data

#### `airspace_display.js`
- Size: ~942 lines
- Purpose: Airspace boundary rendering utilities

#### `cycle.js`
- Size: ~177 lines
- Purpose: AIRAC cycle calculations

#### `jatoc-facility-patch.js`
- Size: ~486 lines
- Purpose: JATOC facility name corrections

#### `playbook-cdr-search.js`
- Size: ~970 lines
- Purpose: Playbook route and CDR search functionality

#### `procs.js`
- Purpose: DP/STAR procedure lookup (generated file)

#### `procs_enhanced.js`
- Size: ~1,126 lines
- Purpose: Enhanced procedure lookup with caching

#### `route-symbology.js`
- Size: ~865 lines
- Purpose: TSD-style aircraft symbology rendering

#### `weather_radar.js`
- Size: ~1,034 lines
- Purpose: Weather radar overlay integration

#### `weather_radar_integration.js`
- Size: ~419 lines
- Purpose: Weather radar layer integration utilities

## 6) API endpoints index (`/api/...`)

### 6.1 ADL Data (`api/adl/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `current.php` | GET | Live flight list with filters |
| `flight.php` | GET | Single flight details |
| `snapshot_history.php` | GET | Historical snapshots |
| `stats.php` | GET | ADL statistics |

### 6.2 Data/Reference (`api/data/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `configs.php` | GET | Configuration data |
| `fixes.php` | GET | Fix/waypoint lookup |
| `personnel.php` | GET | Personnel data |
| `parse_aixm_sua.php` | POST | SUA AIXM parser |
| `reroutes.php` | GET | Reroute data |
| `routes.php` | GET | Route lookup |
| `schedule.php` | GET | Schedule data |
| `sigmets.php` | GET | SIGMET data |
| `sua.php` | GET | Special Use Airspace |
| `tfr.php` | GET | TFR data |
| `weather.php` | GET | Weather data |

### 6.3 Plans (`api/data/plans/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `configs.php` | GET | Plan configurations |
| `dcc_staffing.php` | GET | DCC staffing |
| `enroute_constraints.php` | GET | Enroute constraints |
| `enroute_inits.php` | GET | Enroute initiatives |
| `enroute_inits_timeline.php` | GET/POST/PUT/DELETE | Enroute initiative timeline CRUD **(NEW v12)** |
| `enroute_planning.php` | GET | Enroute planning |
| `enroute_staffing.php` | GET | Enroute staffing |
| `forecast.php` | GET | Traffic forecast |
| `goals.php` | GET | Operational goals |
| `group_flights.php` | GET | Group flights |
| `historical.php` | GET | Historical data |
| `outlook.php` | GET | Outlook data |
| `term_constraints.php` | GET | Terminal constraints |
| `term_inits.php` | GET | Terminal initiatives |
| `term_inits_timeline.php` | GET/POST/PUT/DELETE | Terminal initiative timeline CRUD **(NEW v12)** |
| `term_planning.php` | GET | Terminal planning |
| `term_staffing.php` | GET | Terminal staffing |

### 6.4 JATOC (`api/jatoc/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `incidents.php` | GET | List incidents |
| `incident.php` | GET/POST/PUT/DELETE | Single incident CRUD |
| `updates.php` | GET/POST | Incident updates |
| `oplevel.php` | GET/PUT | Operations level |
| `personnel.php` | GET/PUT | Roster |
| `daily_ops.php` | GET/PUT | POTUS/Space calendar |
| `report.php` | GET/POST | Report generation |
| `special_emphasis.php` | GET/POST | Special emphasis items |
| `vatusa_events.php` | GET | VATUSA events |
| `faa_ops_plan.php` | GET | FAA ops plan |

### 6.5 NOD (`api/nod/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `tmi_active.php` | GET | Consolidated active TMIs (GS/GDP/Reroutes/Routes) |
| `advisories.php` | GET/POST/PUT/DELETE | Advisory CRUD |
| `advisory_import.php` | POST | Bulk advisory import |
| `discord.php` | GET/POST | Discord integration |
| `jatoc.php` | GET | JATOC summary for NOD |
| `tmu_oplevel.php` | GET/PUT | TMU operations level |
| `tracks.php` | GET | Flight track history |

### 6.6 Routes (`api/routes/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `public.php` | GET | List public routes |
| `public_post.php` | POST | Create public route |
| `public_update.php` | PUT | Update public route |
| `public_delete.php` | DELETE | Delete public route |

### 6.7 Splits (`api/splits/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `configs.php` | GET | List configurations |
| `config.php` | GET/POST/PUT/DELETE | Single config CRUD |
| `areas.php` | GET/POST/PUT/DELETE | Area management |
| `sectors.php` | GET | Sector data |
| `presets.php` | GET/POST/PUT/DELETE | Preset management |
| `tracons.php` | GET | TRACON data |
| `active.php` | GET | Active splits |
| `maps.php` | GET | Map data |
| `connect_adl.php` | GET | ADL connection info |

### 6.8 StatSim (`api/statsim/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `fetch.php` | GET | Fetch StatSim data |
| `plan_info.php` | GET | Plan/route information |
| `save_rates.php` | POST | Save computed rates |

### 6.9 TMI Operations (`api/tmi/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `gdp_apply.php` | POST | Apply GDP |
| `gdp_preview.php` | POST | Preview GDP |
| `gdp_purge.php` | POST | Purge GDP |
| `gdp_purge_local.php` | POST | Purge local GDP |
| `gdp_simulate.php` | POST | Simulate GDP |
| `gs_apply.php` | POST | Apply Ground Stop |
| `gs_apply_ctd.php` | POST | Apply GS with CTD |
| `gs_preview.php` | POST | Preview GS |
| `gs_purge_all.php` | POST | Purge all GS |
| `gs_purge_local.php` | POST | Purge local GS |
| `gs_simulate.php` | POST | Simulate GS |
| `rr_assign.php` | POST | Assign reroute |
| `rr_assign_manual.php` | POST | Manual reroute assign |
| `rr_compliance_history.php` | GET | Compliance history |
| `rr_compliance_override.php` | POST | Override compliance |
| `rr_compliance_refresh.php` | POST | Refresh compliance |
| `rr_export.php` | GET | Export reroutes |
| `rr_flight_search.php` | GET | Search flights |
| `rr_preview.php` | POST | Preview reroute |
| `rr_stats.php` | GET | Reroute statistics |
| `simtraffic_flight.php` | GET | SimTraffic flight data |

### 6.10 Management (`api/mgt/`)

CRUD endpoints for all plan/config/review data. Structure: `api/mgt/{entity}/{action}.php`

Entities: `comments`, `config_data`, `configs`, `dcc`, `enroute_constraints`, `enroute_inits`, `enroute_planning`, `enroute_staffing`, `event_data`, `forecast`, `goals`, `group_flights`, `historical`, `personnel`, `perti`, `schedule`, `scores`, `term_constraints`, `term_inits`, `term_planning`, `term_staffing`, `tmi/ground_stops`, `tmi/reroutes`

Actions: `post.php`, `update.php`, `delete.php` (and specialized ones like `fill.php`, `activate.php`, `bulk.php`)

## 7) Database migrations and schema artifacts

Located in `database/migrations/`:

| Migration | Description |
|-----------|-------------|
| `001_create_reroute_tables.sql` | Reroute tables (MySQL) |
| `001_create_reroute_tables_sqlserver.sql` | Reroute tables (Azure SQL) |
| `002_adl_history_stored_procedure.sql` | ADL history SP |
| `003_gdp_tables.sql` | GDP tables |
| `003_gdp_tables_PATCH.sql` | GDP patch |
| `004_public_routes.sql` | Public routes table |
| `004_splits_areas_color.sql` | Splits color column |
| `005_jatoc_tables.sql` | JATOC core tables |
| `005b_add_incident_numbers.sql` | JATOC incident numbers |
| `005c_jatoc_reports_table.sql` | JATOC reports |
| `006_nod_advisories.sql` | NOD advisories (alternate) |
| `006_dcc_advisories.sql` | DCC advisories (primary) |
| `007_add_plan_end_datetime.sql` | Plan end date/time columns |
| `007_initiative_timeline.sql` | Initiative timeline tables (MySQL) **(NEW v12)** |
| `008_initiative_timeline_alter.sql` | Initiative timeline alterations (Azure SQL) **(NEW v12)** |
| `008_initiative_timeline_alter_mysql.sql` | Initiative timeline alterations (MySQL) **(NEW v12)** |

## 8) Reference data and GeoJSON

### 8.1 GeoJSON (`assets/geojson/`)

- `artcc.json` — ARTCC boundaries (refreshed from VATSIM)
- `tracon.json` — TRACON boundaries (refreshed from VATSIM)
- `high.json` — High altitude sectors
- `low.json` — Low altitude sectors
- `superhigh.json` — Super high sectors
- `SUA.geojson` — Special Use Airspace boundaries

### 8.2 CSV Reference Data (`assets/data/`)

- `apts.csv` — Airport reference
- `awys.csv` — Airways
- `cdrs.csv` — Coded Departure Routes
- `dp_full_routes.csv` — DP full route strings
- `star_full_routes.csv` — STAR full route strings
- `navaids.csv` — Navaids
- `points.csv` — Fixes/waypoints
- `playbook_routes.csv` — Playbook routes
- `TierInfo.csv` — Airport tier info
- `T_T100D_SEGMENT_US_CARRIER_ONLY.csv` — Carrier segment data

### 8.3 ARTCC Sector Data (`assets/data/ARTCCs/`)

Individual JSON files per ARTCC (ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX, ZKC, ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZSU, ZTL, ZUA) containing sector boundary polygons.

## 9) Operational scripts (`scripts/`)

| Script | Description |
|--------|-------------|
| `vatsim_adl_daemon.php` | ADL refresh daemon (15s cycle) |
| `refresh_vatsim_boundaries.php` | VATSIM boundary refresh |
| `update_playbook_routes.py` | Playbook route updater |
| `build_sector_boundaries.py` | Sector boundary builder |
| `statsim_scraper.js` | Puppeteer-based StatSim scraper |
| `startup.sh` | Server startup script |

## 10) Ground Stop (GS) subsystem — detailed notes

### 10.1 Purpose

Manages Ground Stop programs where departures to a specific airport are held on the ground.

### 10.2 Key tables

- `tmi_ground_stops` (MySQL) — GS definitions
- `dbo.adl_flights_gs` (Azure SQL) — Simulation sandbox
- `dbo.adl_tmi_state` — Active TMI flags

### 10.3 UI → JS → API call graph

- `tmi.php` → `assets/js/tmi.js`
  - `POST api/tmi/gs_simulate.php` — seed sandbox
  - `POST api/tmi/gs_preview.php` — calculate delays
  - `POST api/tmi/gs_apply.php` — apply to live
  - `POST api/tmi/gs_purge_local.php` — clear sandbox

## 11) GDP / GDT (Ground Delay Program) subsystem — detailed notes

### 11.1 Purpose

Manages Ground Delay Programs where arrivals are metered via slot allocation to reduce airborne holding. GDT provides FSM-style interface.

### 11.2 Key tables

- `dbo.adl_flights_gdp` — GDP simulation sandbox
- `dbo.adl_slots_gdp` — Slot allocations (15-min bins)
- `dbo.gdp_log` — Program log (config/state/metrics)

### 11.3 UI → JS → API call graph

- `tmi.php` / `gdt.php` → `assets/js/gdt.js`
  - `POST api/tmi/gdp_simulate.php` — seed sandbox
  - `POST api/tmi/gdp_preview.php` — calculate slots/delays
  - `POST api/tmi/gdp_apply.php` — apply program
  - `POST api/tmi/gdp_purge.php` — end program

### 11.4 GDT-specific features

- FSM Chapter 5 styling
- Program types: GDP-DAS, GDP-GAAP, GDP-UDP
- Scope filtering by ARTCC/tier/distance
- Rate table with fill options
- Flight list and slots list modals
- CSV export

## 12) Reroute subsystem — detailed notes

### 12.1 Purpose

Manages traffic reroutes with compliance tracking and route advisory generation.

### 12.2 Key tables

- `dbo.tmi_reroutes` — Reroute definitions (41 columns)
- `dbo.tmi_reroute_flights` — Flight assignments (35 columns)
- `dbo.tmi_reroute_compliance_log` — Compliance history

### 12.3 UI → JS → API call graph

- `reroutes.php` → `assets/js/reroute.js`
  - `GET api/data/tmi/reroutes.php` — list reroutes
  - `POST api/mgt/tmi/reroutes/post.php` — create
  - `PUT api/mgt/tmi/reroutes/activate.php` — activate
  - `POST api/tmi/rr_assign.php` — assign flights
  - `GET api/tmi/rr_compliance_history.php` — history

## 13) Splits subsystem — detailed notes

### 13.1 Purpose

Manages airspace sector split configurations with visual mapping and preset support.

### 13.2 Key tables

- `dbo.splits_areas` — Reusable sector groupings
- `dbo.splits_configs` — Split configurations
- `dbo.splits_positions` — Position assignments
- `dbo.splits_presets` — Saved presets
- `dbo.splits_preset_positions` — Preset position mappings

### 13.3 UI → JS → API call graph

- `splits.php` → `assets/js/splits.js`
  - `GET api/splits/configs.php` — list configs
  - `GET api/splits/areas.php` — list areas
  - `GET api/splits/sectors.php` — sector boundaries
  - `GET api/splits/presets.php` — list presets
  - `GET api/splits/active.php` — active config

## 14) Live Flights TSD / route.php — detailed notes

### 14.1 Purpose

TSD-style live flight visualization with route plotting, filtering, and MapLibre support.

### 14.2 Key features

- Dual rendering: Leaflet (legacy) / MapLibre GL (preferred)
- TSD-style aircraft symbology
- Route plotting with procedure expansion
- Playbook/CDR search
- Public route overlay
- Weather radar integration

### 14.3 UI → JS → API call graph

- `route.php` → `assets/js/route.js` / `assets/js/route-maplibre.js`
  - `GET api/adl/current.php` — live flights
  - `GET api/adl/flight.php` — single flight
  - `GET api/data/routes.php` — route lookup
  - `GET api/data/fixes.php` — fix lookup

## 15) ADL history snapshotting and tracks

### 15.1 Tables

- `dbo.adl_flights_history` — Hot tier (recent)
- `dbo.adl_flights_history_cool` — Cool tier
- `dbo.adl_flights_history_cold` — Cold tier (archive)
- `dbo.vw_adl_flights_history_30d` — 30-day view

### 15.2 Stored procedures

- `sp_Adl_ArchiveHistory` — Tier migration
- `sp_Adl_GetFlightTrack` — Track retrieval

## 16) Stored procedure `sp_Adl_RefreshFromVatsim` — notes

Called by `vatsim_adl_daemon.php` every ~15 seconds. Must complete within 12 seconds to prevent cascading failures.

Key operations:
1. Parse VATSIM JSON feed
2. Update `dbo.adl_flights` with position/route changes
3. Calculate ETAs and route compliance
4. Trigger history snapshots
5. Update statistics

## 17) Public Routes subsystem

### 17.1 Purpose

Globally shared route advisories with GeoJSON caching for map display.

### 17.2 Key table

- `dbo.public_routes` (17 columns) — Route definitions with `route_geojson` column

### 17.3 APIs

- `api/routes/public.php` — GET list
- `api/routes/public_post.php` — POST create
- `api/routes/public_update.php` — PUT update
- `api/routes/public_delete.php` — DELETE remove

## 18) JATOC subsystem

### 18.1 Purpose

AWO (Airway Operations) incident monitoring system tracking facility outages, alerts, and operational limitations.

### 18.2 Key features

- Incident status tracking (ATC_ZERO, ATC_ALERT, ATC_LIMITED, NON_RESPONSIVE)
- Operations level management (1/2/3)
- MapLibre map with incident visualization
- Personnel roster
- POTUS/Space calendar integration

### 18.3 APIs

10 endpoints under `api/jatoc/` for incidents, updates, personnel, operations level, reports.

### 18.4 Stored procedures

- `sp_jatoc_next_incident_number` — Generates YYMMDD### format
- `sp_jatoc_next_report_number` — Generates YY-##### format

## 19) StatSim subsystem

### 19.1 Purpose

Integration with SimTraffic/StatSim service for flight time estimates and traffic simulation data.

### 19.2 APIs

- `api/statsim/fetch.php` — Fetch StatSim data
- `api/statsim/plan_info.php` — Get plan/route information
- `api/statsim/save_rates.php` — Save computed hourly rates

### 19.3 Related files

- `assets/js/statsim_rates.js` — FSM-style demand bar graphs (~1,187 lines)
- `scripts/statsim_scraper.js` — Puppeteer-based data scraper

## 20) NASR Navigation Data Updater

### 20.1 Purpose

`nasr_navdata_updater.py` automates updates of navigation data from FAA NASR 28-day subscription packages.

### 20.2 Features

- Automatic AIRAC cycle detection
- Downloads from FAA NFDC (nfdc.faa.gov)
- Parses: Fixes, Navaids, Airports, Airways, CDRs, DPs, STARs
- Merges with existing data (preserves custom entries)
- Creates backups before updates
- Generates JSON and text change reports

### 20.3 Output files

- `assets/data/points.csv`, `navaids.csv`, `awys.csv`, `cdrs.csv`
- `assets/data/dp_full_routes.csv`, `star_full_routes.csv`
- `assets/js/awys.js`, `assets/js/procs.js`

## 21) NOD (NAS Operations Dashboard) subsystem

### 21.1 Purpose

Consolidated monitoring dashboard providing real-time overview of NAS status for virtual ATC operations. Inspired by FAA's NOD system.

### 21.2 Key files

| File | Size | Description |
|------|------|-------------|
| `nod.php` | ~1,467 lines | Main page (public access) |
| `assets/js/nod.js` | ~4,652 lines | Main JavaScript module |
| `api/nod/tmi_active.php` | API | Consolidated active TMI data |
| `api/nod/advisories.php` | API | Advisory CRUD |
| `api/nod/advisory_import.php` | API | Bulk advisory import |
| `api/nod/discord.php` | API | Discord integration |
| `api/nod/jatoc.php` | API | JATOC summary |
| `api/nod/tmu_oplevel.php` | API | TMU operations level |
| `api/nod/tracks.php` | API | Flight track history |

### 21.3 Features

**Map Display:**
- Live traffic visualization (TSD-style)
- Public reroutes overlay with color coding
- Active splits visualization
- JATOC incident markers
- Layer controls: ARTCC, TRACON, High/Low sectors, weather radar, SIGMETs

**Right Panel (Collapsible):**
- **TMI Tab:** Ground Stops, GDPs, Active Reroutes, Public Routes, Discord TMIs
- **Advisories Tab:** FAA-style advisory database with create/edit/cancel
- **JATOC Tab:** Active incidents summary

**Stats Bar (Bottom):**
- Flight counts (airborne/ground)
- TMI breakdown (GS/GDP/RR)
- Active public routes
- Active advisories
- Active incidents
- Active positions

### 21.4 UI → JS → API call graph

- `nod.php` → `assets/js/nod.js`
  - `GET api/nod/tmi_active.php` — consolidated TMIs
  - `GET/POST/PUT/DELETE api/nod/advisories.php` — advisory CRUD
  - `GET api/nod/jatoc.php` — JATOC summary
  - `GET api/nod/discord.php?action=list` — Discord TMIs
  - `GET api/nod/tracks.php` — flight tracks
  - `GET api/adl/current.php` — live traffic
  - `GET api/splits/active.php` — active splits
  - `GET api/routes/public.php` — public routes

### 21.5 Database tables

| Table | Purpose |
|-------|---------|
| `dbo.dcc_advisories` | Advisory storage with FAA-compliant structure |
| `dbo.dcc_advisory_sequences` | Daily advisory number sequences |
| `dbo.dcc_discord_tmi` | Discord TMI integration storage |
| `dbo.vw_dcc_advisories_today` | View for today's active advisories |

### 21.6 Stored procedures

- `sp_nod_next_advisory_number` — Generates advisory numbers (e.g., "DCC 001")

### 21.7 Advisory types

Based on FAA ATCSCC advisory format standards:

| Header Type | TMI_Type | Description |
|-------------|----------|-------------|
| ROUTE RQD | Reroute | Route Required |
| CDM GROUND STOP | GS | Ground Stop |
| CDM GROUND DELAY PROGRAM | GDP | Ground Delay Program |
| INFORMATIONAL | OTHER | Information advisory |

### 21.8 Configuration

```javascript
window.NOD_CONFIG = {
    refreshInterval: 30000,       // Panel refresh (30s)
    trafficRefreshInterval: 15000, // Traffic refresh (15s)
    mapStyle: 'dark-matter-nolabels',
    mapCenter: [-98.5, 39.5],
    mapZoom: 4
};
```

### 21.9 Testing

- JATOC Demo Mode: Add `?jatoc_demo=1` to URL
- API Debug: `api/nod/jatoc.php?demo=1`

## 22) ADL Refresh Patterns

### 22.1 Purpose

Double-buffering pattern to prevent UI "flashing" or data gaps during periodic refreshes.

### 22.2 Key files

| File | Size | Description |
|------|------|-------------|
| `assets/js/adl-service.js` | ~579 lines | Centralized ADL data management |
| `assets/js/adl-refresh-utils.js` | ~580 lines | Buffered refresh utilities |
| `docs/ADL_REFRESH_MIGRATION_GUIDE.md` | Migration documentation |

### 22.3 ADL Service (`adl-service.js`)

Provides centralized ADL data management with subscriber pattern:

```javascript
// Subscribe to updates
ADLService.subscribe('my-component', (data) => {
    renderTable(data.flights);
});

// Start auto-refresh
ADLService.startAutoRefresh(15000);

// Manual refresh
ADLService.refresh();

// Get current data (never null after first load)
const flights = ADLService.getFlights();
```

Features:
- Single shared fetch for all consumers
- Double-buffered state (never empty during refresh)
- Subscriber pattern for multiple components
- Rate limiting (minimum 5s between refreshes)

### 22.4 Refresh Utils (`adl-refresh-utils.js`)

Provides utilities for buffered data fetching:

```javascript
const fetcher = ADLRefreshUtils.createBufferedFetcher({
    url: 'api/adl/current.php',
    onSuccess: (data) => renderTable(data),
    statusElementId: 'lastUpdateTime'
});

// Data persists between refreshes
setInterval(() => fetcher.refresh(), 15000);
```

### 22.5 Pattern principles

1. **Never clear before replacement data arrives**
2. **Build complete content before swapping**
3. **Keep old state during API calls**
4. **Fail gracefully** (keep displaying old data on error)

### 22.6 CSS classes

- `.adl-refreshing` — Applied during fetch (subtle pulse)
- `.adl-stale-data` — Applied when data might be outdated

### 22.7 Files updated with pattern

- `nod.js` — loadTraffic(), loadTMIData(), loadAdvisories(), loadJATOCData()
- `tmi.js` — refreshAdl()
- `route-maplibre.js` — fetchFlights()
- `route.js` — fetchFlights()
- `route_bu.js` — fetchFlights()
- `reroute.js` — refreshCompliance()
- `jatoc.js` — loadIncidents()
- `public-routes.js` — fetchRoutes()

## 23) Initiative Timeline subsystem — NEW v12

### 23.1 Purpose

Provides an interactive Gantt-style timeline visualization for Terminal and En Route initiatives within PERTI plans. Allows TMU coordinators to visualize and manage CDWs, TMIs, constraints, and special events across time.

### 23.2 Key files

| File | Size | Description |
|------|------|-------------|
| `assets/js/initiative_timeline.js` | ~1,281 lines | Main JavaScript class |
| `assets/css/initiative_timeline.css` | CSS | Timeline styling |
| `api/data/plans/term_inits_timeline.php` | ~287 lines | Terminal timeline API |
| `api/data/plans/enroute_inits_timeline.php` | ~287 lines | Enroute timeline API |

### 23.3 Features

**Timeline Visualization:**
- Gantt-style horizontal timeline with configurable time range (default 16 hours)
- Real-time "now" line indicator that updates automatically
- Color-coded entries by level (CDW, Possible, Probable, Expected, Active, etc.)
- Scrollable time window with navigation controls
- Facility-based row grouping with collapsible groups

**Level Definitions:**
| Level | Category | Description |
|-------|----------|-------------|
| CDW | cdw | Critical Decision Window |
| Possible | tmi | Possible TMI |
| Probable | tmi | Probable TMI |
| Expected | tmi | Expected TMI |
| Active | tmi | Active TMI |
| Advisory_Terminal | tmi | TMI Advisory (Terminal) |
| Advisory_EnRoute | tmi | Advisory (En Route) |
| Constraint_Terminal | constraint | Terminal Constraint |
| Constraint_EnRoute | constraint | En Route Constraint |
| Special_Event | event | Special Event |
| Space_Op | space | Space Operation |
| VIP | vip | VIP Movement |
| Staffing | staffing | Staffing Trigger |
| Misc | misc | Miscellaneous |

**TMI Types Supported:**
- GS, GDP, MIT, MINIT, CFR, APREQ, Reroute, AFP, FEA, FCA, CTOP, ICR, TBO, Metering, TBM, TBFM, Other

**Modal-Based CRUD:**
- Create new timeline entries with facility selection
- Edit existing entries with full field support
- Delete entries with confirmation
- Multi-facility selection (comma-separated)

### 23.4 UI → JS → API call graph

- `plan.php` → `assets/js/initiative_timeline.js`
  - `GET api/data/plans/term_inits_timeline.php?p_id=X` — load terminal timeline
  - `POST api/data/plans/term_inits_timeline.php` — create entry
  - `PUT api/data/plans/term_inits_timeline.php` — update entry
  - `DELETE api/data/plans/term_inits_timeline.php?id=X` — delete entry
  - Same pattern for `enroute_inits_timeline.php`

### 23.5 Database tables (MySQL)

| Table | Purpose |
|-------|---------|
| `p_terminal_init_timeline` | Terminal initiative timeline entries |
| `p_enroute_init_timeline` | Enroute initiative timeline entries |

### 23.6 Usage

The `InitiativeTimeline` class is instantiated in `plan.php`:

```javascript
const termTimeline = new InitiativeTimeline({
    type: 'terminal',
    containerId: 'terminal-timeline',
    planId: <?= $planId ?>,
    eventStart: '<?= $eventStart ?>',
    eventEnd: '<?= $eventEnd ?>',
    hasPerm: <?= $hasPerm ? 'true' : 'false' ?>
});
```

### 23.7 Styling

Timeline entries use category-based color coding defined in CSS:
- `.timeline-entry-cdw` — Yellow/amber for CDW
- `.timeline-entry-tmi` — Red for TMIs
- `.timeline-entry-constraint` — Orange for constraints
- `.timeline-entry-event` — Blue for special events
- `.timeline-entry-space` — Purple for space operations
- `.timeline-entry-vip` — Green for VIP movements
- `.timeline-entry-staffing` — Teal for staffing
- `.timeline-entry-misc` — Gray for miscellaneous

## 24) Practical gotchas to remember

1. **Azure SQL Serverless scaling** can cause 50+ second delays on first connection after idle period.
2. **Route plotting performance** is critical — target sub-5-second for single routes.
3. **VATSIM API rate limits** — daemon uses 15-second intervals.
4. **GeoJSON pre-computation** is essential for public routes — compute at save time, not render time.
5. **UTF-8 encoding** issues in legacy files — use proper encoding when reading CSVs.
6. **MapLibre vs Leaflet** — check `localStorage.useMapLibre` for feature flag state.
7. **JATOC and NOD are public** — no auth required for viewing, but editing requires DCC role.
8. **ADL refresh timing** — stored procedures must complete within 12 seconds.
9. **Double-buffering** — use ADL refresh patterns to prevent UI flashing.
10. **Initiative Timeline UTC** — all datetime values stored and displayed in UTC.

## 25) Search anchors (fast grep patterns)

| Pattern | Finds |
|---------|-------|
| `sp_Adl_` | Stored procedures for ADL |
| `sp_jatoc_` | JATOC stored procedures |
| `sp_nod_` | NOD stored procedures |
| `dbo.adl_flights` | Live flight table operations |
| `dbo.jatoc_` | JATOC table operations |
| `dbo.dcc_` | NOD/DCC table operations |
| `conn_adl` | Azure SQL connection usage |
| `api/jatoc/` | JATOC API endpoints |
| `api/nod/` | NOD API endpoints |
| `api/tmi/` | TMI API endpoints |
| `MapLibre` or `maplibre` | MapLibre-specific code |
| `localStorage.` | User preference storage |
| `flight_key` | Flight identifier joins |
| `ADLService` | Centralized ADL data service |
| `ADLRefreshUtils` | Buffered refresh utilities |
| `InitiativeTimeline` | Initiative timeline class |
| `_init_timeline` | Timeline table operations |

## 26) Changelog

- **v12 (2026-01-03):**
  - **Initiative Timeline Subsystem (NEW):**
    - `assets/js/initiative_timeline.js` — Interactive Gantt-style timeline (~1,281 lines)
    - `assets/css/initiative_timeline.css` — Timeline styling
    - `api/data/plans/term_inits_timeline.php` — Terminal timeline API (~287 lines)
    - `api/data/plans/enroute_inits_timeline.php` — Enroute timeline API (~287 lines)
    - MySQL tables: `p_terminal_init_timeline`, `p_enroute_init_timeline`
    - Integration with `plan.php` for CDW/TMI/constraint visualization
  - **Database Migrations:**
    - `007_initiative_timeline.sql` — Initiative timeline tables (MySQL)
    - `008_initiative_timeline_alter.sql` — Timeline alterations (Azure SQL)
    - `008_initiative_timeline_alter_mysql.sql` — Timeline alterations (MySQL)
  - **API Additions:**
    - `api/nod/tmu_oplevel.php` — TMU operations level endpoint (~107 lines)
  - **Updated files:**
    - `plan.php` — Now includes initiative timeline integration (~2,458 lines)
    - `nod.js` — Minor updates (~4,652 lines)
    - `splits.js` — Updates (~7,277 lines)
  - **Database tree updates:**
    - Updated VATSIM_ADL_tree.json with current schema
    - Updated VATSIM_PERTI_tree.json with perti_site schema including new timeline tables

- **v11 (2026-01-03):**
  - NOD Subsystem (complete NAS Operations Dashboard)
  - ADL Refresh Patterns (double-buffering)
  - GDT Page (standalone Ground Delay Tool)
  - DCC/NOD advisory tables

- **v10 (2025-12-30):**
  - JATOC Subsystem (complete AWO incident monitoring)
  - StatSim Integration
  - NASR Navigation Data Updater

- **v9 (2025-12-28):**
  - Public Routes Subsystem
  - Route Symbology Module
  - Playbook/CDR Search Module
  - GeoJSON Export
  - Splits presets and TRACON support

- **v8 (2025-12-23):**
  - MapLibre Migration Complete
  - TSD-style aircraft symbology
  - Advisory Builder
  - VATSIM Boundary Refresh Script

## 27) VATSIM_ADL Azure SQL Database Reference

_Full table/column listing available in VATSIM_ADL_tree.json project file._

### Tables (dbo schema)

| Table | Description |
|-------|-------------|
| `ACD_Data` | Aircraft characteristics database |
| `adl_edct_overrides` | EDCT override storage |
| `adl_flights` | Live flight state (main table) |
| `adl_flights_gdp` | GDP simulation sandbox |
| `adl_flights_gs` | GS simulation sandbox |
| `adl_flights_history` | Hot tier history |
| `adl_flights_history_cold` | Cold tier history |
| `adl_flights_history_cool` | Cool tier history |
| `adl_run_log` | Daemon run log |
| `adl_slots_gdp` | GDP slot allocations |
| `adl_tmi_state` | Active TMI flags |
| `aircraft_type_lookup` | Aircraft type reference |
| `apts` | Airport reference |
| `dcc_advisories` | NOD advisories |
| `dcc_advisory_sequences` | Advisory number sequences |
| `dcc_discord_tmi` | Discord TMI integration |
| `eta_citypair_lookup` | City pair ETA lookup |
| `gdp_log` | GDP program log |
| `jatoc_daily_ops` | Daily ops calendar |
| `jatoc_incident_updates` | Incident update log |
| `jatoc_incidents` | AWO incidents |
| `jatoc_ops_level` | Operations level |
| `jatoc_personnel` | Personnel roster |
| `jatoc_reports` | Incident reports |
| `jatoc_sequences` | Number sequences |
| `jatoc_special_emphasis` | Special emphasis items |
| `jatoc_updates` | Updates table |
| `public_routes` | Public route advisories |
| `r_airport_totals` | Airport totals |
| `r_hourly_rates` | Hourly rates |
| `splits_areas` | Split areas |
| `splits_configs` | Split configurations |
| `splits_positions` | Position assignments |
| `splits_preset_positions` | Preset position mappings |
| `splits_presets` | Saved presets |
| `tmi_reroute_compliance_log` | Compliance history |
| `tmi_reroute_flights` | Flight assignments |
| `tmi_reroutes` | Reroute definitions |
| `tracons` | TRACON reference |

### Views

| View | Description |
|------|-------------|
| `vw_adl_flights_history_30d` | 30-day history view |
| `vw_dcc_advisories_today` | Today's advisories |
| `vw_jatoc_active_incidents` | Active incidents |

## 28) VATSIM_PERTI MySQL Database Reference

_Full table/column listing available in VATSIM_PERTI_tree.json project file._

### Tables (perti_site schema)

| Table | Description |
|-------|-------------|
| `admin_users` | Admin user accounts |
| `assigned` | Assignment data |
| `config_data` | Configuration data |
| `p_configs` | Plan configurations |
| `p_dcc_staffing` | DCC staffing |
| `p_enroute_constraints` | Enroute constraints |
| `p_enroute_init` | Enroute initiatives |
| `p_enroute_init_timeline` | Enroute timeline entries **(NEW v12)** |
| `p_enroute_init_times` | Enroute initiative times |
| `p_enroute_planning` | Enroute planning |
| `p_enroute_staffing` | Enroute staffing |
| `p_forecast` | Traffic forecast |
| `p_group_flights` | Group flights |
| `p_historical` | Historical data |
| `p_op_goals` | Operational goals |
| `p_plans` | Plans (main table) |
| `p_terminal_constraints` | Terminal constraints |
| `p_terminal_init` | Terminal initiatives |
| `p_terminal_init_timeline` | Terminal timeline entries **(NEW v12)** |
| `p_terminal_init_times` | Terminal initiative times |
| `p_terminal_planning` | Terminal planning |
| `p_terminal_staffing` | Terminal staffing |
| `r_comments` | Review comments |
| `r_data` | Review data |
| `r_ops_data` | Operations data |
| `r_scores` | Review scores |
| `route_cdr` | CDR route cache |
| `route_playbook` | Playbook route cache |
| `tmi_ground_stops` | Ground stop definitions |
| `users` | User accounts |
