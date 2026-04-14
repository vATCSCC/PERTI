# PERTI Project Instructions for Claude

## Project Overview

**PERTI** (Plan, Execute, Review, Train, Improve) is a web-based air traffic flow management platform for the VATSIM virtual ATC network. It is operated by vATCSCC (Virtual Air Traffic Control System Command Center) and simulates real-world FAA ATCSCC traffic management functions.

**Live site**: `https://perti.vatcscc.org`

### Domain Glossary

| Term | Meaning |
|------|---------|
| **PERTI** | Plan, Execute, Review, Train, Improve - the operational planning cycle |
| **ADL** | Aggregate Demand List - real-time list of all active/planned flights with ETAs |
| **TMI** | Traffic Management Initiative - programs like GDPs, Ground Stops, reroutes |
| **GDP** | Ground Delay Program - assigns EDCTs to slow arrivals at congested airports |
| **GS** | Ground Stop - halts all departures to a specific airport |
| **GDT** | Ground Delay Table - visual display of GDP slot assignments |
| **NTML** | National Traffic Management Log - public record of TMI actions |
| **EDCT** | Expect Departure Clearance Time - assigned departure time under a GDP |
| **AAR/ADR** | Airport Acceptance Rate / Airport Departure Rate |
| **ATIS** | Automatic Terminal Information Service - runway/weather broadcast |
| **SUA** | Special Use Airspace (MOAs, restricted areas, etc.) |
| **TFR** | Temporary Flight Restriction |
| **SWIM** | System Wide Information Management - the project's public API layer |
| **CIFP** | Coded Instrument Flight Procedures (DPs/STARs) |
| **DP/STAR** | Departure Procedure / Standard Terminal Arrival Route |
| **FIR** | Flight Information Region (international airspace boundary) |
| **NOD** | NAS Operations Dashboard |
| **JATOC** | Joint Air Traffic Operations Center (incident management) |
| **CID** | VATSIM Certificate ID (user identifier) |
| **OOOI** | Out-Off-On-In (flight phase gate events) |
| **OpLevel** | Operational Level 1-4 (traffic impact severity) |
| **RBS** | Ration By Schedule (GDP slot assignment algorithm) |
| **ASPM82** | FAA Aviation System Performance Metrics 82-airport set |
| **OPSNET45** | FAA Operations Network 45-airport performance metric set |
| **CTP** | Collaborative Traffic Planning (oceanic/special event coordination) |
| **CDM** | Collaborative Decision Making (A-CDM airport milestones) |
| **ECFMP** | EUROCONTROL-style Flow Measures (VATSIM Europe integration) |
| **NAT** | North Atlantic Tracks (oceanic routing) |
| **TMR** | Traffic Management Review (post-event analysis) |

## Quick Start / Commands

```bash
# Install PHP dependencies
composer install

# Local development (PHP built-in server)
php -S localhost:8000

# Run database migrations
php scripts/run_migration.php <migration_file.sql>

# Run cron jobs manually
php cron/run_indexer.php
php cron/process_tmi_proposals.php
```

**Required PHP extensions**: `pdo`, `mysqli`, `sqlsrv`, `pdo_pgsql`, `openssl`, `curl`, `mbstring`, `json`

**No automated test suite** — testing is manual via the live site and API endpoints.

## Database & Infrastructure Access

**IMPORTANT**: You have full access to all project databases and Azure resources.

Credentials and connection details are documented in: `.claude/credentials.md`

Read this file when you need to:
- Query any database directly
- Access Azure resources via Kudu SSH
- Connect to MySQL, Azure SQL, or PostgreSQL databases
- Use API keys or OAuth credentials

### Database Architecture

The project uses 7 databases across 3 database engines:

| Database | Engine | Host | Purpose |
|----------|--------|------|---------|
| `perti_site` | MySQL 8 | vatcscc-perti.mysql.database.azure.com | Main web app (plans, users, configs, staffing) |
| `VATSIM_ADL` | Azure SQL | vatsim.database.windows.net | Flight data (normalized 8-table architecture) |
| `VATSIM_TMI` | Azure SQL | vatsim.database.windows.net | Traffic management initiatives (GDP/GS/reroutes) |
| `VATSIM_REF` | Azure SQL | vatsim.database.windows.net | Reference data (airports, airways, navdata) |
| `SWIM_API` | Azure SQL | vatsim.database.windows.net | Public API database (FIXM-aligned schema) |
| `VATSIM_GIS` | PostgreSQL/PostGIS | vatcscc-gis.postgres.database.azure.com | Spatial queries (boundary intersection, route geometry) |
| `VATSIM_STATS` | Azure SQL | vatsim.database.windows.net | Statistics & analytics |

Azure resource config is in: `load/azure_perti_config.json`

### Database Connection Pattern

Connections are managed in `load/connect.php` with lazy-loading getters:

```php
// MySQL (perti_site) - always available
$conn_pdo   // PDO connection
$conn_sqli  // MySQLi connection

// Azure SQL - use getter functions (lazy loaded via sqlsrv extension)
$conn = get_conn_adl();   // VATSIM_ADL
$conn = get_conn_swim();  // SWIM_API
$conn = get_conn_tmi();   // VATSIM_TMI
$conn = get_conn_ref();   // VATSIM_REF

// PostgreSQL (PostGIS) - use getter function (lazy loaded via PDO pgsql)
$conn = get_conn_gis();   // VATSIM_GIS
```

- MySQL uses **PDO** and **MySQLi** (both available)
- Azure SQL uses **sqlsrv** extension (not PDO)
- PostgreSQL uses **PDO pgsql** extension
- Use `PERTI\Lib\Database` class for parameterized queries (see `lib/Database.php`)

#### `PERTI_MYSQL_ONLY` Optimization

Endpoints that only need MySQL can skip the 5 eager Azure SQL connections by defining `PERTI_MYSQL_ONLY` before including `connect.php`:

```php
include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
```

This skips `$conn_adl`, `$conn_swim`, `$conn_tmi`, `$conn_ref`, and `$conn_gis` initialization (~500-1000ms saved per request). The `$conn_*` globals remain `null` so code checking them will see falsy values.

**Applied to**: `api/data/plans/`, `api/data/sheet/`, `api/data/review/`, and most `api/mgt/` plan endpoints (~98 files).

**NEVER apply to** files that use Azure SQL connections (`$conn_adl`, `$conn_tmi`, `$conn_swim`, `$conn_ref`, `$conn_gis`). Always `grep` for these before adding the flag. Known Azure SQL users in the API layer:
- `api/mgt/config_data/` (bulk, post, update, delete) — uses `$conn_adl`
- `api/mgt/tmi/reroutes/`, `api/mgt/tmi/airport_configs.php` — uses `$conn_adl`, `$conn_tmi`
- `api/mgt/sua/` — uses `$conn_adl`
- `api/data/configs.php`, `api/data/tmi/reroute.php`, `api/data/sua/`, `api/data/rate_history.php`, `api/data/weather_impacts.php` — uses `$conn_adl`

### Key Tables (Quick Reference)

**For full schema details**, query the database directly or see `wiki/Database-Schema.md`.

#### perti_site (MySQL) — Planning & Users
- `p_plans` (239 records) — Event plans: id, event_name, event_date, event_start, oplevel, etc.
- `p_configs` — Airport configs per plan (airport, weather, aar, adr)
- `p_terminal_staffing` / `p_enroute_staffing` — Staffing per plan
- `p_terminal_init` / `p_enroute_init` — TMI initiative definitions
- `p_terminal_init_timeline` / `p_enroute_init_timeline` — Initiative timelines
- `config_data` — Default airport rate configs
- `users` (25 records) / `admin_users` — User accounts
- `route_playbook` / `route_cdr` — Playbook and CDR routes
- `r_tmr_reports` — Traffic Management Review reports
- `r_scores` / `r_comments` / `r_data` — Post-event review data

#### VATSIM_ADL (Azure SQL) — Flight Data (1.6M+ flights)

**Core 8-table normalized architecture** (keyed on `flight_uid bigint`):

- `adl_flight_core` — flight identity, phase, active status, current position zone/ARTCC/sector
- `adl_flight_plan` — filed route, aircraft type, parse status, route geometry, waypoint count
- `adl_flight_position` — lat/lon, altitude, speed, heading, distance to dest, position_geo
- `adl_flight_times` — 50+ time columns: STD/ETD/ATD/ETA/ATA, OOOI, EDCT, bucket times, confidence
- `adl_flight_tmi` — TMI control: program_id, slot_id, delay, compliance, reroute status
- `adl_flight_aircraft` — ICAO/FAA type, weight class, engine, wake category, airline
- `adl_flight_trajectory` — position history (1M+ records): lat/lon/alt/speed/heading per timestamp
- `adl_flight_waypoints` — parsed route waypoints (9.3M records): fix_name, lat/lon, ETAs, distances
- `adl_flight_planned_crossings` — boundary crossing predictions (20.5M records): boundary/entry/exit times
- Legacy: `adl_flights` (monolithic, 150+ cols), `adl_flights_gdp`, `adl_flights_gs`
- Supporting: `adl_boundary` (3,033 polygons), `adl_parse_queue`, `adl_edct_overrides`, `adl_flight_changelog`, `adl_flight_archive`, staging tables
- Reference: `airlines` (228), `apts`, `nav_fixes` (269K), `nav_procedures` (10K), `airways` (1,515), `ACD_Data`, BADA performance tables
- Airport config: `airport_config`/`_runway`/`_rate`/`_history`, `airport_taxi_reference`, `airport_connect_reference`, `airport_geometry`
- ARTCC: `artcc_facilities`, `artcc_adjacencies`, tier tables, `sector_boundaries`
- Stats: `flight_stats_daily/hourly/weekly/monthly/yearly/airport/artcc/carrier/citypair`
- Events: `division_events`, `perti_events`, `event_position_log`
- Discord: `discord_channels/messages/reactions`, `dcc_advisories`
- Other: `splits_*`, `sua_*`, `jatoc_*`, `scheduler_state`, `demand_monitors`

#### VATSIM_TMI (Azure SQL) — Traffic Management (172 programs, 1,020 advisories, 268 reroutes)
- `tmi_programs` — GDP/GS/AFP programs: rates, scope, delays, status, coordination
- `tmi_slots` — GDP time slots: slot_time_utc, assigned flight, CTD/CTA, delay
- `tmi_flight_control` / `tmi_flight_list` — per-flight TMI control and program membership
- `tmi_advisories` — NTML advisory messages with Discord integration
- `tmi_entries` — MIT, AFP, restriction log entries
- `tmi_reroutes` / `tmi_reroute_routes` / `tmi_reroute_flights` — reroute definitions and compliance
- `tmi_proposals` / `tmi_proposal_facilities` — multi-facility coordination
- `tmi_public_routes` — published route visualizations
- `tmi_events` — event audit log
- `tmi_discord_posts`, `tmi_popup_queue`, `tmi_flow_*` (ECFMP integration)

#### VATSIM_REF (Azure SQL) — Reference Data (269K fixes, 10K procedures, 41K CDRs, 56K playbook routes)
- `nav_fixes`, `nav_procedures`, `airways`, `airway_segments`, `area_centers`
- `coded_departure_routes`, `playbook_routes`, `oceanic_fir_bounds`

#### SWIM_API (Azure SQL) — Public FIXM-aligned API
- `swim_flights` — denormalized flight snapshot (120+ columns) for API consumers
- `swim_api_keys`, `swim_audit_log`, `swim_ground_stops`
- Views: `vw_swim_active_flights`, `vw_swim_flights_oooi_compat`, `vw_swim_tmi_controlled`

#### VATSIM_GIS (PostgreSQL/PostGIS) — Spatial (1,004 ARTCC + 1,023 TRACON + 37K airports + 535K fixes)
- `artcc_boundaries`, `tracon_boundaries`, `sector_boundaries` — polygons with `geom` geometry
- `airports` — airport points with PostGIS geometry
- Mirrored REF data: `nav_fixes`, `nav_procedures`, `airways`, `area_centers`, CDRs, playbook routes
- Utilities: `boundary_adjacency`, `facility_reference`, `tmi_density_cache`

#### VATSIM_STATS (Azure SQL) — may be paused (free tier). Stats tables live in VATSIM_ADL under `flight_stats_*`.

### Migration Files

Migrations under `database/migrations/` by area: `tmi/`, `swim/`, `schema/`, `postgis/`, `gdp/`, `initiatives/`, `jatoc/`, `reroute/`, `sua/`, `advisories/`, `vatsim_stats/`, `adl/`.
ADL-specific in `adl/migrations/`: `core/`, `boundaries/`, `crossings/`, `demand/`, `eta/`, `navdata/`, `changelog/`, `cifp/`.

## Project Structure

### Top-Level Directories

```
/                          Root (PHP pages served directly)
/api/                      PHP REST API endpoints
/api-docs/                 OpenAPI spec (openapi.yaml) and docs index
/adl/                      ADL subsystem (daemons, migrations, analysis)
/apache/                   Apache websocket config
/assets/                   Frontend assets (JS, CSS, images)
/cron/                     Cron PHP scripts (TMI proposals, indexer)
/data/                     Data files (indexes, tmi_compliance output)
/database/                 Database migrations and schema tools
/discord-bot/              Node.js Discord Gateway bot
/docs/                     API documentation (swim/, stats/)
/files/                    File storage (logs, user uploads)
/integrations/             External system integrations
/lib/                      Core PHP utility classes
/load/                     Configuration, includes, shared PHP
/login/                    VATSIM OAuth login flow
/scripts/                  Daemons, utilities, maintenance scripts
/sdk/                      Multi-language client SDKs (C++, C#, Java, JS, PHP, Python)
/services/                 Wind data fetching (NOAA GFS via Python)
/sessions/                 Session handler
/simulator/                ATC simulator module
/sql/                      SQL migration scripts
/wiki/                     GitHub wiki (45+ pages: architecture, algorithms, APIs, troubleshooting)
```

### Top-Level PHP Pages

**Planning**: `index.php` (plan listing), `plan.php` (plan detail), `schedule.php`, `sheet.php`, `review.php`
**Operations**: `demand.php` (demand charts), `splits.php` (sector splits), `route.php` (MapLibre map), `gdt.php` (GDP table), `nod.php` (NAS dashboard), `playbook.php` (route plays)
**TMI**: `tmi-publish.php` (Discord publishing), `sua.php` (SUA display), `airport_config.php`, `event-aar.php`
**CDM/CTP**: `cdm.php` (collaborative decision making), `ctp.php` (collaborative traffic planning)
**JATOC**: `jatoc.php` (incident management)
**SWIM**: `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`
**Data/Nav**: `navdata.php` (navigation data display), `historical-routes.php` (route history analysis)
**System**: `status.php`, `simulator.php`, `healthcheck.php`, `data.php`, `login/`, `logout.php`
**Static**: `transparency.php`, `privacy.php`, `fmds-comparison.php`, `hibernation.php`

### API Endpoints (`/api/`)

| Path | Purpose |
|------|---------|
| `/api/adl/` | Flight data: `current`, `flight`, `ingest`, `waypoints`, `boundaries`, `demand/*`, diagnostics |
| `/api/data/` | Reference/planning: `plans/*` (configs, staffing, initiatives, timelines), fixes, routes, weather, SUA, reroutes, review, crossings, `cdm/*` |
| `/api/tmi/` | TMI programs: GDP (`gdp_preview/apply/simulate/purge`), GS (`gs/*` lifecycle), advisories, entries, reroutes, public-routes |
| `/api/mgt/` | Management: `perti/` (plan CRUD), `tmi/` (TMI management, coordination, reroute drafts, ground stops), `historical/*` |
| `/api/splits/` | Sector splits: config CRUD, sectors, maps, scheduler |
| `/api/stats/` | Statistics: realtime, hourly, daily, flight phase history |
| `/api/swim/v1/` | SWIM API: REST flights/positions/metering, `ingest/*`, `keys/*`, `tmi/*`, `cdm/*`, `connectors/*`, `ctp/*`, `playbook/*`, `reference/*`, `routes/*`, `ws/` WebSocket |
| `/api/jatoc/` | Incident management: auth, config, validators |
| `/api/gdt/` | GDT endpoints: program management, slot operations, advisories, compress, reoptimize |
| `/api/ctp/` | CTP integration: audit_log, boundaries, changelog, demand, sessions |
| `/api/demand/` | Demand management: monitors, thresholds, analysis |
| `/api/gis/` | GIS spatial queries: boundaries, intersections, route expansion |
| `/api/routes/` | Route management: analysis, history, geometry |
| `/api/discord/` | Discord integration: webhooks, message management |
| `/api/events/` | Event management: sync, scheduling |
| Other | `admin/`, `analysis/`, `event-aar/`, `nod/`, `session/`, `simulator/`, `statsim/`, `system/`, `tiers/`, `user/`, `util/`, `weather/` |

### Frontend Architecture

**Stack**: Vanilla JS + jQuery 2.2.4 + Bootstrap 4.5 + Chart.js + MapLibre GL

**CSS** (`assets/css/`): `theme.css`, `perti_theme.css`, `perti-colors.css`, `mobile.css`, `weather_radar.css`, `weather_impact.css`, `weather_hazards.css`, `initiative_timeline.css`, `tmi-publish.css`, `tmi-compliance.css`, `info-bar.css`, `playbook.css`, `ctp.css`, `navdata.css`, `route-analysis.css`, `routes.css`

**JavaScript** (`assets/js/`) — 71+ modules, 45 using i18n:

- Core: `lib/datetime.js`, `lib/logger.js`, `lib/colors.js`, `lib/dialog.js`, `lib/i18n.js`, `lib/aircraft.js`, `lib/artcc-hierarchy.js`, `lib/artcc-labels.js`, `lib/deeplink.js`, `lib/norad-codes.js`, `lib/perti.js`, `lib/route-advisory-parser.js`
- Config: `config/constants.js`, `config/rate-colors.js`, `config/phase-colors.js`, `config/facility-roles.js`, `config/filter-colors.js`
- Feature: `adl-service.js`, `demand.js`, `gdp.js`, `tmi-gdp.js`, `tmi-publish.js`, `tmi_compliance.js`, `tmi-active-display.js`, `splits.js`, `schedule.js`, `review.js`, `plan.js`, `plan-tables.js`, `plan-splits-map.js`, `gdt.js`, `nod.js`, `nod-demand-layer.js`, `playbook.js`, `cdm.js`, `ctp.js`, `navdata.js`, `tmr_report.js`, `statsim_rates.js`, `advisory-config.js`
- Map: `route-maplibre.js`, `route-symbology.js`, `route-analysis-panel.js`, `routes.js`, `routes-map.js`, `fir-scope.js`, `fir-integration.js`, `sua.js`, `public-routes.js`
- Data: `facility-hierarchy.js`, `procs.js`, `procs_enhanced.js`, `cycle.js`, `awys.js`, `reroute.js`, `reroute-advisory-search.js`, `playbook-cdr-search.js`, `playbook-dcc-loader.js`, `playbook-filter-parser.js`, `playbook-query-builder.js`, `natots-search.js`, `adl-refresh-utils.js`
- Weather: `weather_radar.js`, `weather_impact.js`, `weather_hazards.js`, `weather_radar_integration.js`
- JATOC: `jatoc-facility-patch.js`

**Third-party** (CDN): jQuery 2.2.4, Bootstrap 4.5, SweetAlert2, Select2, Summernote, FontAwesome 5.15.4, Chart.js, MapLibre GL JS

### PHP Utility Classes

**`lib/`**: `Database.php` (parameterized queries for MySQLi/sqlsrv), `Response.php` (JSON API helpers), `Session.php`, `DateTime.php`, `ArtccNormalizer.php` (ARTCC code normalization), `Changelog.php` (flight change tracking)

**`load/`** (35 files — key ones listed):

- Config: `config.php` (env config, gitignored), `connect.php` (DB connections), `input.php` (PHP 8.2+ input), `swim_config.php`, `azure_perti_config.json`, `perti_constants.php`, `cache.php`
- Layout: `header.php`, `nav.php`, `nav_public.php`, `footer.php`, `breadcrumb.php`
- Feature: `gdp_section.php`, `hibernation.php`, `i18n.php`, `org_context.php`, `playbook_visibility.php`, `coordination_log.php`
- Reference: `aircraft_families.php`, `airport_aliases.php`

**`load/discord/`**: `DiscordAPI.php`, `MultiDiscordAPI.php`, `TMIDiscord.php`, `DiscordMessageParser.php`, `DiscordWebhookHandler.php`
**`load/services/`**: `GISService.php` (PostGIS spatial queries), `CDMService.php`, `CTPApiClient.php`, `CTPPlaybookSync.php`, `EDCTDelivery.php`, `NATTrackFunctions.php`, `NATTrackResolver.php`

### Discord Bot (`discord-bot/`)

Node.js Gateway bot (`bot.js`) for TMI coordination reactions. Calls `api/mgt/tmi/coordinate.php` via REST. Multi-org support via `DISCORD_ORGANIZATIONS` config.

### Integrations (`integrations/`)

Flight sim plugins (MSFS/X-Plane/P3D), virtual airline modules (phpVMS7/smartCARS/VAM), pilot client plugins (vPilot/xPilot), ATC integrations (Hoppie CPDLC, vATIS, vFDS).

## Background Jobs & Daemons

**IMPORTANT**: Use PHP daemons with `scripts/startup.sh`, NOT Azure Functions.

All daemons are started at App Service boot via `scripts/startup.sh`. Some run always, others are conditional on hibernation mode or GIS mode (`USE_GIS_DAEMONS` env var).

**Always-on daemons** (run even in hibernation):

| Daemon | Script | Interval | Purpose |
|--------|--------|----------|---------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s | Flight data ingestion + ATIS + deferred ETA processing |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent | Real-time events on port 8090 |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min | Sync ADL to SWIM_API |
| SWIM TMI Sync | `scripts/swim_tmi_sync_daemon.php` | 5min | TMI/CDM/reference data sync to SWIM mirrors |
| SimTraffic Poll | `scripts/simtraffic_swim_poll.php` | 2min | SimTraffic time data polling |
| Reverse Sync | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic data back to ADL |
| Archival | `scripts/archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge |
| Monitoring | `scripts/monitoring_daemon.php` | 60s | System metrics collection |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous | Async TMI Discord posting (batch=50) |
| ECFMP Poll | `scripts/ecfmp_poll_daemon.php` | 5min | ECFMP flow measure polling |
| vIFF CDM Poll | `scripts/viff_cdm_poll_daemon.php` | 30s | EU CDM milestone data (conditional: `VIFF_CDM_ENABLED`) |
| Playbook Export | `scripts/playbook/export_playbook.php` | Daily | Daily playbook backup |
| Refdata Sync | `scripts/refdata_sync_daemon.php` | Daily 06:00Z | CDR + playbook reference reimport |
| ADL Archive | `scripts/adl_archive_daemon.php` | Daily 10:00Z | Trajectory archival to blob storage |

**Conditional daemons** (skipped in hibernation):

| Daemon | Script | Interval | Purpose |
|--------|--------|----------|---------|
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Route parsing with PostGIS |
| Boundary Detection (GIS) | `adl/php/boundary_gis_daemon.php` | 15s | Spatial boundary detection |
| Crossing Calculation | `adl/php/crossing_gis_daemon.php` | Tiered | Boundary crossing ETA prediction |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered | Waypoint ETA calculation |
| Scheduler | `scripts/scheduler_daemon.php` | 60s | Splits/routes auto-activation |
| Event Sync | `scripts/event_sync_daemon.php` | 6h | VATUSA/VATCAN/VATSIM event sync |
| CDM | `scripts/cdm_daemon.php` | 60s | A-CDM milestone computation |
| vACDM Poll | `scripts/vacdm_poll_daemon.php` | 2min | vACDM instance polling |

**Legacy fallback daemons** (when `USE_GIS_DAEMONS=0`):

| Daemon | Script | Interval | Purpose |
|--------|--------|----------|---------|
| Parse Queue (ADL) | `adl/php/parse_queue_daemon.php` | 5s batch | Route parsing without PostGIS |
| Boundary Detection (ADL) | `adl/php/boundary_daemon.php` | 30s | Boundary detection without PostGIS |

**Startup job**: `scripts/indexer/run_indexer.php` runs once at boot (30s delay).

### Tiered Processing

Several daemons use tiered intervals based on flight priority:
- **Tier 0** (15s): Active flights within 60nm of destination
- **Tier 1** (30s): Active flights en route
- **Tier 2** (60s): Prefiled flights departing within 2h
- **Tier 3** (2min): Prefiled flights departing within 6h
- **Tier 4** (5min): All other flights

## Deployment & CI/CD

### GitHub Actions (`.github/workflows/`)

- `azure-webapp-vatcscc.yml` - Deploy to Azure App Service on push to `main`
  - PHP 8.2, Composer install, rsync deploy package
  - Deploys to Azure Web App `vatcscc` via publish profile
  - Excludes `sdk/`, `.git/`, `.github/`, most `docs/` (keeps `docs/swim/` and `docs/stats/`)
- `site-monitor.yml` - Site monitoring workflow
- `wind-fetch.yml` - Wind data fetch workflow

### Azure App Service

- **Linux container** with nginx + PHP-FPM
- Custom startup: `scripts/startup.sh` → configures nginx, starts all daemons, then PHP-FPM foreground
- PHP-FPM tuned to 40 workers (P1v2 tier, 3.5GB RAM)
- ODBC Driver 18 installed via `startup.sh` (root level) for Python pyodbc
- Logs: `/home/LogFiles/<daemon>.log`

### Authentication

- **VATSIM Connect OAuth** for user login (OAuth 2.0)
- Session-based auth via `sessions/handler.php`
- Users table in `perti_site` MySQL
- Discord bot uses API key auth (`X-API-Key` header)
- SWIM API uses API keys from `swim_api_keys` table

## Code Conventions

- Database connections use PDO with prepared statements (MySQL) or sqlsrv with parameterized queries (Azure SQL)
- Use `PERTI\Lib\Database` class for new queries when possible
- API endpoints follow REST conventions in `/api/{resource}/{action}.php`
- Config values defined as PHP constants in `load/config.php`
- Environment-specific values use `env()` helper (supports Azure App Settings)
- Frontend uses jQuery AJAX for API calls, SweetAlert2 for notifications
- All times are UTC/Zulu
- Feature flags defined in `config.php` (e.g., `DISCORD_MULTI_ORG_ENABLED`, `TMI_STAGING_REQUIRED`)
- **Internationalization (i18n)**: Use `assets/js/lib/i18n.js` for all user-facing strings in JavaScript. PHP-side strings should use the i18n patterns documented below.

## Gotchas

- **`?>` in `//` comments terminates PHP mode.** `// some text ?> rest` causes `rest` to be emitted as HTML. Never use `?>` inside comments.
- **`connect.php` has a closing `?>` tag** (end of file) that outputs a trailing newline. You must start sessions BEFORE including `connect.php`, or the newline sends headers and prevents `session_start()`.
- **GDT API auth pattern**: `common.php` includes `sessions/handler.php` at module level (before config/connect) so session is available for `gdt_require_auth()`. Follow this pattern for similar auth-gated endpoints.
- **Azure SQL uses `sqlsrv`**, not PDO — don't mix up `sqlsrv_query()` / `sqlsrv_fetch_array()` with PDO methods.
- **Legacy vs normalized flight tables**: `adl_flights` (monolithic) still used by some pages. New code should use the 8-table normalized architecture (`adl_flight_core`, `adl_flight_plan`, etc.).

## Internationalization (i18n)

**All new user-facing JS strings MUST use `PERTII18n.t()`** — never hardcode English. PHP API responses remain English-only.

| File | Purpose |
|------|---------|
| `assets/js/lib/i18n.js` | Core: `t()`, `tp()`, `formatNumber()`, `formatDate()` |
| `assets/locales/index.js` | Locale loader (auto-detect, init on page load) |
| `assets/locales/en-US.json` | 7,276 translation keys |
| `assets/js/lib/dialog.js` | `PERTIDialog` wrapper with i18n key resolution |

```javascript
PERTII18n.t('common.save')                                // Simple
PERTII18n.t('error.loadFailed', { resource: 'flights' })   // Interpolation
PERTII18n.tp('flight', count)                              // Pluralization
PERTIDialog.success('dialog.success.saved');                // Dialog with i18n
```

Add keys to `en-US.json` as nested objects — auto-flattened to dot notation.

**Coverage**: 45/65 JS modules (69%), all 30 PHP pages (via `header.php`), zero hardcoded strings in modern modules.
**Locales**: `en-US` (7,276 keys), `fr-CA` (7,560), `en-CA` (557 overlay), `en-EU` (509 overlay).
**Detection**: URL param → localStorage `PERTI_LOCALE` → `navigator.language` → `en-US`.

## Git Worktrees

**IMPORTANT**: Always use `C:/Temp/perti-worktrees/` for git worktrees.

The main repository path (OneDrive) is too long for Windows' 260-character limit, causing worktree creation to fail when using project-local directories like `.worktrees/`.

```bash
# Correct - use short temp path
git worktree add C:/Temp/perti-worktrees/<branch-name> -b feature/<branch-name>

# Incorrect - will fail due to path length
git worktree add .worktrees/<branch-name> -b feature/<branch-name>
```

Current worktrees can be listed with `git worktree list`.

## Python Scripts

Several utilities use Python 3.x:
- `nasr_navdata_updater.py` - FAA NASR navdata import
- `airac_full_update.py` - Full AIRAC cycle data update
- `scripts/build_sector_boundaries.py` - Build sector boundary polygons
- `scripts/playbook/` - CDR/playbook route parser
- `scripts/statsim/` - Statistical simulation and VATUSA event import
- `scripts/vatsim_atis/` - VATSIM ATIS fetcher daemon
- `scripts/bada/` - BADA aircraft performance data parsers
- `scripts/openap/` - OpenAP performance data import

## Hibernation Mode

**Status**: ACTIVE (re-entered 2026-03-30).

When `HIBERNATION_MODE` is enabled (`load/config.php` default `true` + Azure App Setting `1`), only ADL ingest daemon runs, pages redirect to `/hibernation`, SWIM API returns 503, Azure resources downscaled. Currently enabled (`load/config.php` default `true`, App Setting `1`).

**Key files**: `load/config.php`, `load/hibernation.php`, `hibernation.php`, `scripts/startup.sh`
**Gotcha**: Azure App Setting `HIBERNATION_MODE=false` (string) is truthy in PHP — use `0` or delete the setting.
**Procedures**: See `docs/operations/HIBERNATION_RUNBOOK.md`.
