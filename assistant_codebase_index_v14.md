# vATCSCC PERTI — Codebase Index (v14)

Generated: 2026-01-07 UTC

This index is based on the `wwwroot-2026-01-03-9.zip` snapshot and is intended as a fast reference for navigation, call graphs, and high-signal files.

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
- [23) Initiative Timeline subsystem](#23-initiative-timeline-subsystem)
- [24) Weather Radar subsystem (NEW v14)](#24-weather-radar-subsystem--new-v14)
- [25) SUA/TFR subsystem (NEW v14)](#25-suatfr-subsystem--new-v14)
- [26) Practical gotchas](#26-practical-gotchas-to-remember)
- [27) Search anchors](#27-search-anchors-fast-grep-patterns)
- [28) Changelog](#28-changelog)
- [29) VATSIM_ADL Azure SQL Database Reference](#29-vatsim_adl-azure-sql-database-reference)
- [30) VATSIM_PERTI MySQL Database Reference](#30-vatsim_perti-mysql-database-reference)

---

## 1) High-level architecture

### 1.1 Top-level layout

- `index.php` — landing/dashboard
- `plan.php`, `review.php`, `schedule.php`, `sheet.php` — planning workflow pages (MySQL-backed)
- `tmi.php` — TMI tools page (GS + GDP; Azure SQL ADL-backed) **(not in current snapshot)**
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
- `nasr_navdata_updater.py` — NASR Navigation Data Updater for FAA AIRAC cycles.

---

## 2) Boot / shared includes

- `load/config.php` — local configuration (DB creds, site config, ADL SQL constants).
- `load/connect.php` — initializes DB connections:
  - `$conn` (MySQL)
  - `$conn_adl` (Azure SQL / sqlsrv)
- `load/header.php`, `load/nav.php`, `load/footer.php` — shared layout and script/style includes.
- `includes/gdp_section.php` — GDP/GDT section component (reusable)
- `.htaccess` — rewrite rule for extensionless `/api/...` requests to resolve `.php` endpoints.

---

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
- `p_terminal_init_timeline`
- `p_terminal_staffing`
- `p_terminal_planning`
- `p_terminal_constraints`
- `p_enroute_init`
- `p_enroute_init_times`
- `p_enroute_init_timeline`
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

#### `p_terminal_init_timeline` / `p_enroute_init_timeline` columns (MySQL)

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

This project uses Azure SQL (via `sqlsrv_*` and `$conn_adl`) for live flight state + TMI workflows. Full table/column listing is in Section 29.

- **dbo.adl_flights** — Live flight data (refreshed every ~15s)
- **dbo.adl_flights_gs** — Ground Stop applied flight state
- **dbo.adl_flights_history** — Historical flight snapshots
- **dbo.adl_run_log** — Daemon run log

### 3.3 TMI Azure SQL Tables

- **dbo.tmi_reroutes** — Reroute definitions
- **dbo.tmi_reroute_flights** — Flight assignments
- **dbo.tmi_reroute_compliance_log** — Compliance history

### 3.4 NOD/DCC Azure SQL Tables

- **dbo.dcc_advisories** — DCC advisory records
- **dbo.dcc_advisory_references** — Advisory cross-references
- **dbo.dcc_discord_tmi** — Discord TMI integration storage (for bot integration)
- **dbo.vw_dcc_advisories_today** — View for today's active advisories

### 3.5 Supporting Azure SQL Tables

- **dbo.ACD_Data** — Aircraft characteristics database (ICAO code, FAA designator, weight class, engine type)
- **dbo.aircraft_type_lookup** — Aircraft type reference
- **dbo.eta_citypair_lookup** — City pair ETA lookup
- **dbo.tracons** — TRACON reference data
- **dbo.r_airport_totals** — Airport totals for reporting
- **dbo.r_hourly_rates** — Hourly rate calculations

---

## 4) Main pages (PHP entrypoints)

Top-level PHP pages in `/wwwroot` and which `assets/js/*` files they load.

| Page | Scripts | Size (lines) |
|------|---------|--------------|
| `configs.php` | — | ~15K |
| `data.php` | `sheet.js` | ~19K |
| `gdt.php` | `gdt.js` | ~98K |
| `index.php` | — | ~23K |
| `jatoc.php` | `jatoc.js` | ~93K |
| `logout.php` | — | ~1K |
| `nod.php` | `nod.js` | ~58K |
| `plan.php` | `plan.js`, `initiative_timeline.js` | ~115K |
| `privacy.php` | — | ~6K |
| `reroutes.php` | `reroute.js` | ~22K |
| `reroutes_index.php` | inline JS | ~12K |
| `review.php` | `review.js`, `statsim_rates.js` | ~30K |
| `route.php` | `awys.js`, `leaflet.textpath.js`, `procs_enhanced.js`, `route-maplibre.js`, `route.js`, `weather_radar.js` | ~137K |
| `schedule.php` | `schedule.js` | ~8K |
| `sheet.php` | `sheet.js` | ~21K |
| `splits.php` | `splits.js` | ~103K |

Notes:
- `reroutes_index.php` contains **inline** JS (no dedicated `assets/js/*` module).
- `route.php` uses a **feature flag** (`localStorage.useMapLibre` or `?maplibre=true`) to switch between Leaflet and MapLibre.
- `jatoc.php` and `nod.php` are publicly accessible (no auth required) for monitoring.
- `route.php` now includes weather radar integration.

---

## 5) Client-side modules (`assets/js/`)

### 5.1 Page entry modules (directly loaded by a PHP page)

#### `awys.js`
- Loaded by: `route.php`
- Size: ~0 lines (placeholder/empty)

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

#### `initiative_timeline.js`
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
- Size: ~4,896 lines
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
- Loaded by: `plan.php`
- Size: ~3,491 lines
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
- Size: ~8,319 lines
- Calls APIs:
  - `api/adl/current.php`
  - `api/adl/flight.php`
  - `api/data/routes.php`
  - `api/data/fixes.php`

#### `route-maplibre.js`
- Loaded by: `route.php` (when MapLibre mode active)
- Size: ~7,930 lines
- Calls APIs: Same as route.js, optimized for MapLibre GL

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
- Size: ~7,360 lines
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

#### `weather_radar.js` **(NEW v14)**
- Loaded by: `route.php`
- Size: ~1,034 lines
- Purpose: IEM NEXRAD/MRMS weather radar integration for TSD map
- Features:
  - Multiple radar products (N0Q, EET, MRMS HSR, precipitation)
  - Color tables: NWS Standard, FAA ATC (HF-STD-010A), Scope, High Contrast
  - Animation support with 12 historical frames (~1 hour)
  - Auto-refresh every 5 minutes
  - MapLibre GL TMS tile integration
- Data source: Iowa Environmental Mesonet (mesonet.agron.iastate.edu)

#### `weather_radar_integration.js` **(NEW v14)**
- Loaded by: `route.php`
- Size: ~419 lines
- Purpose: Integration layer connecting WeatherRadar module to route.php UI
- Features: Control panel binding, state persistence

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
- Size: ~0 lines (deprecated/emptied)
- Purpose: Legacy SID/STAR procedure data

#### `procs_enhanced.js`
- Size: ~1,126 lines
- Purpose: Enhanced procedure rendering for MapLibre

#### `route-symbology.js`
- Size: ~865 lines
- Purpose: TSD-style aircraft symbology and route rendering

---

## 6) API endpoints index (`/api/...`)

### 6.1 ADL APIs (`/api/adl/`)

- `current.php` — Returns current ADL flight data
- `flight.php` — Single flight details
- `snapshot_history.php` — Historical snapshot access
- `stats.php` — Flight statistics

### 6.2 TMI APIs (`/api/tmi/`)

- `gdp_apply.php` — Apply GDP to live flights
- `gdp_preview.php` — Preview GDP impact
- `gdp_purge.php` — Purge GDP
- `gdp_simulate.php` — Run GDP simulation
- `gdp_purge_local.php` — Local GDP purge
- `gs_apply.php` — Apply Ground Stop
- `gs_apply_ctd.php` — Apply GS with CTD
- `gs_preview.php` — Preview GS impact
- `gs_purge_all.php` — Purge all GS
- `gs_purge_local.php` — Local GS purge
- `gs_simulate.php` — Simulate GS
- `rr_*.php` — Reroute management endpoints

### 6.3 JATOC APIs (`/api/jatoc/`)

- `incidents.php` — List incidents
- `incident.php` — Single incident CRUD
- `daily_ops.php` — Daily operations
- `personnel.php` — Personnel roster
- `oplevel.php` — Operations level
- `updates.php` — Incident updates
- `report.php` — Incident reports
- `special_emphasis.php` — Special emphasis items
- `vatusa_events.php` — VATUSA events integration
- `faa_ops_plan.php` — FAA ops plan

### 6.4 NOD APIs (`/api/nod/`)

- `tmi_active.php` — Active TMIs summary
- `advisories.php` — Advisory CRUD
- `advisory_import.php` — Import advisories **(NEW v14)**
- `jatoc.php` — JATOC summary for NOD
- `discord.php` — Discord integration
- `tracks.php` — Flight track history
- `tmu_oplevel.php` — TMU operations level

### 6.5 Splits APIs (`/api/splits/`)

- `configs.php` — Split configurations list
- `config.php` — Single config CRUD
- `areas.php` — Area definitions
- `sectors.php` — Sector data
- `presets.php` — Preset configurations
- `tracons.php` — TRACON data
- `active.php` — Active splits
- `scheduled.php` — Scheduled splits
- `maps.php` — Map data
- `debug.php` — Debug endpoints

### 6.6 Routes APIs (`/api/routes/`)

- `public.php` — Public routes list
- `public_post.php` — Create public route
- `public_update.php` — Update public route
- `public_delete.php` — Delete public route

### 6.7 Data APIs (`/api/data/`)

- `plans/*` — Plan data management
- `review/*` — Review data
- `routes.php` — Route data
- `fixes.php` — Fix/waypoint data
- `schedule.php` — Schedule data
- `sheet/*` — Sheet data
- `sigmets.php` — SIGMET data
- `sua.php` — Special Use Airspace data **(NEW v14)**
- `tfr.php` — Temporary Flight Restrictions **(NEW v14)**
- `weather.php` — Weather data **(NEW v14)**
- `parse_aixm_sua.php` — AIXM SUA parser **(NEW v14)**
- `plans/term_inits_timeline.php` — Terminal timeline API
- `plans/enroute_inits_timeline.php` — Enroute timeline API

### 6.8 StatSim APIs (`/api/statsim/`)

- `fetch.php` — Fetch StatSim data
- `plan_info.php` — Plan info for StatSim
- `save_rates.php` — Save rate calculations

---

## 7) Database migrations and schema artifacts

- `migrations/001_create_reroute_tables.sql` — Initial reroute tables (MySQL)
- `migrations/001_create_reroute_tables_sqlserver.sql` — Reroute tables (SQL Server)
- `migrations/002_adl_history_stored_procedure.sql` — ADL history SP
- `migrations/003_gdp_tables.sql` — GDP tables
- `migrations/003_gdp_tables_PATCH.sql` — GDP patch
- `migrations/004_public_routes.sql` — Public routes table
- `migrations/004_splits_areas_color.sql` — Splits area colors
- `migrations/005_jatoc_tables.sql` — JATOC tables
- `migrations/005b_add_incident_numbers.sql` — Incident numbers
- `migrations/005c_jatoc_reports_table.sql` — JATOC reports
- `migrations/006_dcc_advisories.sql` — DCC advisory tables
- `migrations/006_nod_advisories.sql` — NOD advisory tables
- `migrations/007_add_plan_end_datetime.sql` — Plan end datetime
- `migrations/007_initiative_timeline.sql` — Initiative timeline tables (MySQL)
- `migrations/008_initiative_timeline_alter.sql` — Timeline alterations (Azure SQL)
- `migrations/008_initiative_timeline_alter_mysql.sql` — Timeline alterations (MySQL)

---

## 8) Reference data and GeoJSON

- `assets/geojson/artcc.json` — ARTCC boundaries (from VATSIM)
- `assets/geojson/tracon.json` — TRACON boundaries (from VATSIM)
- `assets/geojson/high.json` — High altitude sectors
- `assets/geojson/low.json` — Low altitude sectors
- `assets/geojson/superhigh.json` — Super high altitude sectors **(NEW v14)**
- `assets/geojson/SUA.geojson` — Special Use Airspace boundaries **(NEW v14)**
- `assets/data/playbook_routes.csv` — Playbook route reference
- `assets/data/cdrs.csv` — CDR route reference
- `assets/data/apts.csv` — Airport data
- `assets/data/awys.csv` — Airway data
- `assets/data/points.csv` — Navigation points
- `assets/data/navaids.csv` — Navigation aids
- `assets/data/dp_full_routes.csv` — Departure procedures
- `assets/data/star_full_routes.csv` — Arrival procedures
- `assets/data/TierInfo.csv` — ARTCC tier information

---

## 9) Operational scripts (`scripts/`)

- `vatsim_adl_daemon.php` — Main ADL refresh daemon (runs every ~15s)
- `refresh_vatsim_boundaries.php` — Boundary GeoJSON refresh
- `update_playbook_routes.py` — Playbook route updates
- `build_sector_boundaries.py` — Sector boundary builder
- `statsim_scraper.js` — StatSim data scraper (Puppeteer)
- `startup.sh` — Daemon startup script

---

## 10) Ground Stop (GS) subsystem — Detailed notes

### Key files
- `tmi.php` — Main GS UI (not in current snapshot)
- `api/tmi/gs_*.php` — GS API endpoints
- `api/data/tmi/ground_stop.php` — Single GS data
- `api/data/tmi/ground_stops.php` — GS list
- `api/mgt/tmi/ground_stops/post.php` — Create GS

### Flow
1. User creates GS definition in MySQL `tmi_ground_stops`
2. Preview shows affected flights from `dbo.adl_flights`
3. Apply copies affected flights to `dbo.adl_flights_gs` with EDCT
4. ADL daemon maintains GS state during refresh cycles

---

## 11) GDP / GDT (Ground Delay Program) subsystem — Detailed notes

### Key files
- `gdt.php` — FSM-style GDP interface (~98K)
- `assets/js/gdt.js` — GDT JavaScript (~6,876 lines)
- `assets/js/gdp.js` — GDP utilities (~1,339 lines)
- `includes/gdp_section.php` — Reusable GDP component
- `api/tmi/gdp_*.php` — GDP API endpoints

### Features
- FSM-style rate visualization
- EDCT/CTA slot allocation
- Program parameters (scope, rate, duration)
- Flight list with compliance status
- Real-time preview and simulation

---

## 12) Reroute subsystem — Detailed notes

### Key files
- `reroutes.php` — Reroute authoring UI
- `reroutes_index.php` — Reroute monitoring dashboard
- `assets/js/reroute.js` — Reroute JavaScript
- `api/tmi/rr_*.php` — Reroute APIs
- `api/data/tmi/reroute.php`, `reroutes.php` — Reroute data

### Tables (Azure SQL)
- `dbo.tmi_reroutes` — Reroute definitions
- `dbo.tmi_reroute_flights` — Flight assignments
- `dbo.tmi_reroute_compliance_log` — Compliance history

### Flow
1. Create reroute with constraints (O/D, route, time window)
2. Preview matching flights
3. Assign flights to reroute
4. Monitor compliance via dashboard

---

## 13) Splits subsystem — Detailed notes

### Key files
- `splits.php` — Main splits UI (~103K)
- `assets/js/splits.js` — Splits JavaScript (~7,360 lines)
- `api/splits/*.php` — Splits APIs

### Features
- MapLibre-based sector visualization
- Area grouping and configuration
- Preset management
- Active position assignments
- TRACON support
- Strata filtering (low/high/superhigh)

---

## 14) Live Flights TSD / route.php — Detailed notes

### Key files
- `route.php` — Main TSD UI (~137K)
- `assets/js/route.js` — Leaflet mode (~8,319 lines)
- `assets/js/route-maplibre.js` — MapLibre mode (~7,930 lines)
- `assets/js/route-symbology.js` — Aircraft symbols (~865 lines)
- `assets/js/procs_enhanced.js` — SID/STAR rendering (~1,126 lines)
- `assets/js/public-routes.js` — Public routes (~1,831 lines)
- `assets/js/playbook-cdr-search.js` — Route search (~970 lines)
- `assets/js/weather_radar.js` — Weather radar (~1,034 lines) **(NEW v14)**

### Features
- Real-time VATSIM flight display with TSD symbology
- Multi-route plotting with DP/STAR resolution
- Public routes sharing
- Advisory builder for TFMS-style text
- Export formats: GeoJSON, KML, GeoPackage
- Playbook/CDR search
- Weather radar overlay (IEM NEXRAD) **(NEW v14)**

---

## 15) ADL history snapshotting and tracks

### Key components
- `api/adl/snapshot_history.php` — Access historical snapshots
- `api/nod/tracks.php` — Flight track history
- `dbo.adl_flights_history` — Historical flight positions
- `sp_Adl_RefreshFromVatsim` — Populates history during refresh

---

## 16) Stored Procedure `sp_Adl_RefreshFromVatsim` — Notes

Located in Azure SQL VATSIM_ADL database.

### Responsibilities
1. Parse incoming VATSIM JSON flight data
2. Update `dbo.adl_flights` with current positions
3. Calculate ETAs based on route and groundspeed
4. Maintain boundary crossing estimates
5. Log flight history snapshots
6. Preserve GS/GDP applied states

---

## 17) Public Routes subsystem

### Key files
- `assets/js/public-routes.js` (~1,831 lines)
- `api/routes/public*.php` — CRUD endpoints

### Features
- Globally shared route advisories
- ARTCC/TRACON attribution
- Expiration management
- Route geometry storage

---

## 18) JATOC subsystem

### Key files
- `jatoc.php` — AWO Incident Monitor (~93K)
- `assets/js/jatoc.js` (~1,171 lines)
- `assets/js/jatoc-facility-patch.js` (~486 lines)
- `api/jatoc/*.php` — JATOC APIs

### Features
- Incident tracking (ATC Zero/Alert/Limited)
- Operations level (1/2/3) display
- Personnel roster
- POTUS/Space calendar
- VATUSA events integration
- MapLibre visualization

---

## 19) StatSim subsystem

### Key files
- `scripts/statsim_scraper.js` — Puppeteer scraper
- `assets/js/statsim_rates.js` (~1,187 lines)
- `api/statsim/*.php` — StatSim APIs

### Purpose
Scrapes SimTraffic/StatSim data for historical rate analysis and planning comparison.

---

## 20) NASR Navigation Data Updater

### Key file
- `nasr_navdata_updater.py` — Main updater script

### Updates
- `assets/data/points.csv` — Navigation points
- `assets/data/navaids.csv` — Navigation aids
- `assets/data/awys.csv` — Airways
- `assets/data/dp_full_routes.csv` — Departure procedures
- `assets/data/star_full_routes.csv` — Arrival procedures

### Source
FAA NASR (28-day AIRAC cycle)

---

## 21) NOD (NAS Operations Dashboard) subsystem

### Key files
- `nod.php` — Main NOD UI (~58K)
- `assets/js/nod.js` (~4,896 lines)
- `api/nod/*.php` — NOD APIs

### Features
- Consolidated TMI monitoring
- Advisory management
- JATOC integration
- Discord TMI sync
- Flight track display
- Operations level status
- Weather radar integration **(Enhanced v14)**

---

## 22) ADL Refresh Patterns

### Key files
- `assets/js/adl-service.js` (~579 lines)
- `assets/js/adl-refresh-utils.js` (~580 lines)

### Patterns
- **Double-buffering**: Fetch new data into hidden buffer, swap on complete
- **Subscriber pattern**: Components register for data updates
- **Rate limiting**: Prevent API overload
- **State preservation**: Maintain selections across refreshes

---

## 23) Initiative Timeline subsystem

### Key files
- `assets/js/initiative_timeline.js` (~1,281 lines)
- `assets/css/initiative_timeline.css` (~17K)
- `api/data/plans/term_inits_timeline.php`
- `api/data/plans/enroute_inits_timeline.php`

### Features
- Gantt-style interactive timeline
- TMI type categorization
- Color-coded severity levels
- Real-time "now" indicator
- Modal CRUD interface
- Facility filtering

---

## 24) Weather Radar subsystem — NEW v14

### Key files
- `assets/js/weather_radar.js` (~1,034 lines)
- `assets/js/weather_radar_integration.js` (~419 lines)
- `assets/css/weather_radar.css` (~8.6K)
- `api/data/weather.php` (~12K)

### Features
- **Products**: Base Reflectivity (N0Q), Echo Tops (EET), MRMS HSR, 1hr/24hr Precipitation
- **Color tables**: NWS Standard, FAA ATC (HF-STD-010A), Scope (monochrome), High Contrast
- **Animation**: 12 frames (~1 hour history), 5-minute intervals
- **Auto-refresh**: Every 5 minutes
- **Integration**: MapLibre GL TMS tiles

### Data Source
Iowa Environmental Mesonet (mesonet.agron.iastate.edu)
- TMS tile endpoints with load balancing across 4 hosts
- 5-minute cache for real-time, 14-day cache for historical

---

## 25) SUA/TFR subsystem — NEW v14

### Key files
- `api/data/sua.php` (~2.2K) — Special Use Airspace API
- `api/data/tfr.php` (~1.6K) — Temporary Flight Restrictions API
- `api/data/parse_aixm_sua.php` (~18K) — AIXM SUA parser
- `assets/geojson/SUA.geojson` — SUA boundary data

### Features
- FAA SUA boundary data (MOAs, Restricted Areas, Warning Areas)
- TFR integration
- AIXM format parsing
- GeoJSON output for map display

---

## 26) Practical gotchas to remember

1. **Azure SQL Serverless**: May have cold-start delays; first query after idle can be slow
2. **Stored procedure timeouts**: Complex ADL refresh can timeout; use connection timeout settings
3. **MapLibre vs Leaflet**: Feature flag controls which library; some features differ
4. **OAuth sessions**: VATSIM OAuth tokens expire; handle refresh gracefully
5. **GeoJSON size**: Large boundary files can impact load time; use compression
6. **Weather radar tiles**: IEM may rate-limit; use multiple hosts
7. **AIRAC cycles**: Navigation data updates every 28 days; schedule updates accordingly

---

## 27) Search anchors (fast grep patterns)

```bash
# Find API endpoint
grep -r "api/tmi" assets/js/

# Find database queries
grep -r "sqlsrv_query\|mysqli_query" api/

# Find stored procedure calls
grep -r "EXEC\s*sp_" api/

# Find MapLibre usage
grep -r "maplibregl\|MapLibre" assets/js/

# Find weather radar
grep -r "WeatherRadar\|weather_radar" assets/js/
```

---

## 28) Changelog

- **v14 (2026-01-07):**
  - **Weather Radar Subsystem:**
    - `assets/js/weather_radar.js` — IEM NEXRAD/MRMS integration (~1,034 lines)
    - `assets/js/weather_radar_integration.js` — UI integration (~419 lines)
    - `assets/css/weather_radar.css` — Radar control styling
    - Multiple radar products: N0Q, EET, MRMS HSR, precipitation
    - Color tables: NWS, FAA ATC, Scope, High Contrast
    - Animation with 12 historical frames
  - **SUA/TFR Subsystem:**
    - `api/data/sua.php` — Special Use Airspace API
    - `api/data/tfr.php` — TFR API
    - `api/data/parse_aixm_sua.php` — AIXM parser
    - `assets/geojson/SUA.geojson` — SUA boundaries
    - `assets/geojson/superhigh.json` — Super high sectors
  - **API Additions:**
    - `api/nod/advisory_import.php` — Advisory import endpoint
    - `api/data/weather.php` — Weather data API
  - **CSS Additions:**
    - `assets/css/info-bar.css` — Info bar styling

- **v13 (2026-01-05):**
  - Demand Visualization system (PLANNED)
  - Weather/forecast tables (PLANNED)
  - Analog situation finder (PLANNED)

- **v12 (2026-01-03):**
  - Initiative Timeline Subsystem
  - Timeline MySQL tables and APIs
  - Integration with plan.php

- **v11 (2026-01-03):**
  - NOD Subsystem (complete)
  - ADL Refresh Patterns
  - GDT Page

- **v10 (2025-12-30):**
  - JATOC Subsystem (complete)
  - StatSim Integration
  - NASR Navigation Data Updater

---

## 29) VATSIM_ADL Azure SQL Database Reference

_Full table/column listing available in VATSIM_ADL_tree.json project file._

### Tables

| Table | Description |
|-------|-------------|
| `ACD_Data` | Aircraft characteristics |
| `adl_flights` | Live flight data |
| `adl_flights_gs` | Ground stop applied state |
| `adl_flights_history` | Historical snapshots |
| `adl_run_log` | Daemon run log |
| `aircraft_type_lookup` | Aircraft type reference |
| `apts` | Airport data |
| `dcc_advisories` | DCC advisories |
| `dcc_advisory_references` | Advisory cross-refs |
| `dcc_discord_tmi` | Discord TMI storage |
| `eta_citypair_lookup` | City pair ETAs |
| `jatoc_daily_ops` | JATOC daily operations |
| `jatoc_incidents` | Incident records |
| `jatoc_incident_updates` | Incident updates |
| `jatoc_personnel` | Personnel roster |
| `jatoc_reports` | Incident reports |
| `public_routes` | Public route definitions |
| `r_airport_totals` | Airport totals |
| `r_hourly_rates` | Hourly rates |
| `splits_areas` | Split area definitions |
| `splits_configs` | Split configurations |
| `splits_positions` | Position assignments |
| `splits_presets` | Preset configurations |
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

---

## 30) VATSIM_PERTI MySQL Database Reference

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
| `p_enroute_init_timeline` | Enroute timeline entries |
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
| `p_terminal_init_timeline` | Terminal timeline entries |
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
