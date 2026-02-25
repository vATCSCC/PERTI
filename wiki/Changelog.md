# Changelog

This document tracks significant changes to PERTI across versions.

---

## Version 18 (Current)

*Released: February 2026*

### New Features

#### Traffic Management Review (TMR) Report System (PR #18)

- **TMR Report** - Guided review workflow based on NTMO Guide structure
- Sidebar navigation with sections: triggers, overview, airport conditions, weather, TMIs, equipment, personnel, findings
- TMR report CRUD API with auto-save (`api/data/review/tmr_report.php`)
- Historical TMI lookup from VATSIM_TMI database (`api/data/review/tmr_tmis.php`)
- Bulk NTML paste parser for rapid TMI entry
- Discord-formatted TMR export (`api/data/review/tmr_export.php`)
- Embedded demand charts (DemandChartCore) per plan airport
- Database migration: `r_tmr_reports` table

#### NOD TMI Enhancements & Facility Flows (PRs #19-21)

- **Phase 1:** Enhanced TMI sidebar with rich data cards
  - GS cards: countdown timer, flights held, prob extension, origin centers
  - GDP cards: controlled/exempt counts, avg/max delay, compliance bar, GDT link
  - Reroute cards: assigned/compliant counts, compliance bar
  - MIT/AFP section with restriction details and fix coordinates
  - Delay Reports section with severity coloring and trend indicators
  - Map TMI status layer: airport rings by severity, delay glow circles, MIT fix markers, GS pulse animation
- **Phase 2:** Facility flow configuration system
  - 3 new tables: `facility_flow_configs`, `facility_flow_elements`, `facility_flow_gates`
  - CRUD APIs for configs, elements, gates, and suggestions
  - Flows tab in NOD sidebar with facility/config selectors
  - Flow element management (fixes, procedures, routes, gates)
  - Inline color picker, visibility toggle, FEA toggle per element
  - Fix/procedure autocomplete from nav_fixes/nav_procedures
  - 8 map layers: boundary, procedure/route lines, fix markers
- **Phase 3-4:** Flow map rendering & FEA integration
  - Per-element line weight selector for PROCEDURE/ROUTE elements
  - FEA bridge API (`api/nod/fea.php`): demand monitor toggle, bulk create/clear
  - Demand count feedback on sidebar and map labels
  - Route GeoJSON LineString support in demand layer

#### Internationalization (i18n) System (PR #18)

- Core translation module (`PERTII18n`) with `t()`, `tp()`, `formatNumber()`, `formatDate()`
- Locale loader with auto-detection (URL param, localStorage, browser language)
- SweetAlert2 dialog wrapper (`PERTIDialog`) with i18n key resolution
- 450+ translation keys in `assets/locales/en-US.json`
- Integrated across 13 JS modules: demand, gdt, jatoc, nod, plan, review, schedule, sheet, splits, sua, weather_impact, reroute, tmi-publish

### Performance Improvements

#### PERTI_MYSQL_ONLY Optimization (PR #17)

- `define('PERTI_MYSQL_ONLY', true)` before `include connect.php` skips 5 Azure SQL connections
- Applied to ~98 plan/sheet/review PHP endpoints, saving ~500-1000ms per request
- Lazy-loaded database connection getters (`get_conn_adl()`, `get_conn_tmi()`, etc.)
- Removed closing `?>` tag from `connect.php` (PSR-12)

#### Frontend Parallelization (PR #17)

- `plan.js`: 16 sequential AJAX calls replaced with `Promise.all()` batch
- `sheet.js`: 5 calls parallelized
- `review.js`: 3 calls parallelized

### Codebase Cleanup (PR #16)

- Removed 13 unused files (4,829 lines deleted):
  - `reroutes.php` (replaced by `route.php` + `tmi-publish.php`)
  - `advisory-builder.php` and `advisory-builder.js`
  - `reroute.js` (orphaned, replaced by new module)
  - Legacy admin migration scripts (`migrate_public_routes.php`, `migrate_reroutes.php`, etc.)
  - `test_star_parsing.php`

#### vATCSCC Playbook System (PRs #75-84)

- **Playbook Page** (`/playbook.php`) - Pre-coordinated route play catalog and management
- Play CRUD with FAA and DCC source categories (standard/split route formats)
- MapLibre GL route visualization with sector boundary overlays
- Shareable playbook links with `?play=NAME` URL parameter
- Play duplication with `_MODIFIED` suffix for creating variants
- Bulk paste with ECFMP/CANOC source auto-detection
- DCC play expansion on route.php with GIS route geometry
- Client-side filtering by region, category, and status
- Route remarks field for TMU annotations
- Playbook changelog with full audit trail
- Database: `playbook_plays`, `playbook_routes`, `playbook_changelog` tables in perti_site MySQL

#### Canadian FIR Sector Expansion (PRs #79-84)

- **CZYZ** (Toronto) - Low, high, superhigh sector boundaries
- **CZWG** (Winnipeg) - Sector boundaries with ESE conversion
- **CZEG** (Edmonton) - Sector boundaries
- **CZUL** (Montreal) - Sector boundaries
- **CZVR** (Vancouver) - Sector boundaries
- **CZQM** (Moncton) - 5 low + 27 high = 32 sectors
- **CZQX** (Gander) - 3 low + 39 high = 42 sectors
- Generalized ESE-to-GeoJSON converter handling terminal keywords (RADIO, UNICOM, NO-CONTROL, TRANSITION) and LF-prefix airports
- Total sector boundaries: 1,379 (1,002 US + 377 Canadian)

#### Splits Enhancements (PRs #73, #84)

- **Scheduled splits layer** with low/high/superhigh strata filtering
- Sector map visualization on `splits.php` and plan page Splits tab
- Personnel tables for staffing assignments
- Region grouping for multi-ARTCC operations
- Splits tab added to PERTI plan pages

#### Ops Plan & Plan Page Enhancements (PRs #67-73)

- **Structured FAA-format Ops Plan** with formatted output sections
- Sortable columns in plan page tables (staffing, configs, initiatives)
- ARTCC grouping in plan tables for multi-facility events
- Ops Plan tab with structured output for DCC operations
- Initiative timeline improvements (facility word-wrap, rotated time axis labels)
- Auto-select inferred config modifiers, stacked ARR/DEP display

#### Multi-Organization Support (PRs #68-72)

- Org-scoped facility authorization for TMI and JATOC endpoints
- Multi-org Discord posting via `tmi_discord_posts` queue
- CANOC and ECFMP flow measure sources integrated
- Org-aware i18n locale with `{commandCenter}` template resolution
- `PERTI_ORG` config loaded before locale system

#### TMI Publisher Enhancements (PR #69)

- TMI Publisher visible in nav for all authenticated users
- Compact layout with monospace fonts for NTML formatting
- Org-scoped Discord notification with role mentions

#### Additional i18n Coverage

- **fr-CA** locale (near-complete French Canadian translation)
- **en-CA** and **en-EU** locale overlays
- i18n integrated across 28 PHP pages and 13+ JS modules
- BC Canadian airports added to demand page

### Bug Fixes

- Fix: PERTI_MYSQL_ONLY removed from `config_data/` endpoints that use Azure SQL (PR #17 hotfix)
- Fix: GS compliance analysis shows human-readable phase labels
- Fix: i18n locale loader loads full JSON instead of partial
- Fix: Missing MapLibre GL and i18n dependencies on review and demand pages
- Fix: NOD loads i18n scripts required by merged main branch code
- Fix: i18n key inconsistencies resolved in en-US.json
- Fix: jQuery `.toggle()` on `<tr>` sets `display:block` — use explicit `display:table-row` (PR #76)
- Fix: Playbook login/session handling and navbar visibility
- Fix: Canadian FIR misclassification (oceanic vs domestic) for CZQM/CZQX
- Fix: Initiative timeline facility word-wrap on `/` characters
- Fix: Homepage table column alignment with `table-layout:fixed`
- Fix: Duplicate i18n PHP include causing class redeclaration

### Infrastructure Changes

- VATSIM_ADL migrated from General Purpose Serverless to Hyperscale Serverless (16 vCores, min 3)
- PostgreSQL GIS database (vatcscc-gis) deployed: Burstable B2s, PostGIS, PostgreSQL 16
- MySQL upgraded from Burstable to General Purpose D2ds_v4
- Geo-replica server and VATSIM_Data database decommissioned
- Monthly costs increased from ~$670 to ~$3,500 (primarily Hyperscale compute)

---

## Version 17

*Released: January 2026*

### New Features

#### ATFM Training Simulator

- **Simulator Page** (`/simulator.php`) - TMU training environment
- Node.js flight engine with realistic physics simulation
- Practice GS/GDP/AFP/MIT/Reroute TMI decisions
- Reference data: 3,989 O-D route patterns, 107 airports, 17 carriers
- Web-based interface with MapLibre visualization

#### Airspace Element Demand

- Query traffic demand at navigation fixes, airway segments, and route segments
- Table-valued SQL functions for efficient demand analysis
- Support for both airway-based and direct (DCT) route queries
- TRACON-based filtering for facility-specific queries

#### Config Modifiers System

- Structured modifier categories for runway configurations
- Categories: PARALLEL_OPS, APPROACH_TYPE, TRAFFIC_BIAS, VISIBILITY_CAT, SPECIAL_OPS, TIME_RESTRICT, WEATHER_OPS, NAMED
- Migrated from free-text modifiers to normalized schema

#### ATIS Type Priority Logic

- Enhanced ATIS source selection: ARR+DEP > COMB > single
- Views for effective ATIS determination across split ATIS scenarios
- Improved weather data sourcing for rate suggestions

### API Additions (v17)

- `GET /api/simulator/navdata.php` - Navigation data for routing
- `GET/POST /api/simulator/engine.php` - Engine control
- `GET /api/simulator/routes.php` - Route pattern data
- `GET/POST /api/simulator/traffic.php` - Traffic generation
- `GET /api/adl/demand/fix.php` - Flights at a navigation fix
- `GET /api/adl/demand/airway.php` - Flights on an airway segment
- `GET /api/adl/demand/segment.php` - Flights between two fixes (airway or DCT)

### Database Changes (v17)

- New tables: `sim_ref_carrier_lookup`, `sim_ref_route_patterns`, `sim_ref_airport_demand`
- New tables: `modifier_category`, `modifier_type`, `config_modifier`
- New functions: `fn_FixDemand`, `fn_AirwaySegmentDemand`, `fn_RouteSegmentDemand`
- New views: `vw_current_atis_by_type`, `vw_effective_atis`, `vw_config_with_modifiers`, `vw_runway_with_modifiers`
- New indexes: `IX_waypoint_fix_eta`, `IX_waypoint_airway_eta` (filtered indexes for demand queries)
- Migrations 092-095: Config modifiers, ATIS type priority
- Migrations demand/001-004: Airspace demand indexes and functions
- Data sourced from BTS On-Time Performance (20.6M flight records)

---

## Version 16

*Released: January 2026*

### New Features

#### Demand Analysis System

- **Demand Page** (`/demand.php`) - Airport demand visualization
- Weather-aware rate suggestions with confidence scoring
- Manual rate override support with time windows
- Multi-level rate suggestion algorithm

#### Airport Configuration & ATIS

- Normalized runway configuration schema
- VATSIM ATIS import with weather extraction
- Runway-in-use detection from ATIS parsing
- Flight-track-based runway detection as fallback
- Rate change audit trail

### API Additions

- `GET /api/demand/airports.php` - Airport list
- `GET /api/demand/summary.php` - Demand summary
- `GET /api/demand/rates.php` - Rate data
- `POST /api/demand/override.php` - Manual override

### Database Changes

- Migrations 079-091 (Airport config, ATIS, rates)
- New tables: `airport_config`, `airport_config_runway`, `airport_config_rate`
- New tables: `runway_in_use`, `manual_rate_override`, `rate_history`

---

## Version 15

*Released: December 2025*

### New Features

#### GDT Ground Stop NTML Architecture

- Complete program lifecycle management
- Stored procedure-based workflow
- Pop-up flight detection
- EDCT issuance and management

#### Weather Radar Integration

- IEM NEXRAD/MRMS tile integration
- Multiple color table options
- Configurable opacity and layers

#### SUA/TFR Display

- Special Use Airspace boundaries on map
- TFR visualization
- Active/inactive filtering

#### Initiative Timeline

- Gantt-style TMI visualization
- Interactive timeline navigation
- Multiple initiative tracking

### API Changes

- New GS API endpoints (`/api/tmi/gs/*`)
- Enhanced flight data responses
- WebSocket support preparation

### Database Changes

- NTML schema (`tmi/001_ntml_schema.sql`)
- GS procedures (`tmi/002_gs_procedures.sql`)
- GDT views (`tmi/003_gdt_views.sql`)
- Phase column unification in ADL

---

## Version 14

*Released: October 2025*

### New Features

- ETA calculation with aircraft performance
- Trajectory logging and visualization
- Zone detection (OOOI) implementation
- Boundary crossing detection

### Improvements

- Route parsing accuracy enhancements
- SimBrief flight plan integration
- Performance optimizations for large events

---

## Version 13

*Released: August 2025*

### New Features

- JATOC incident management
- NOD dashboard
- Public route sharing
- Discord webhook integration

### API Changes

- JATOC CRUD endpoints
- NOD data endpoints
- Public routes API

---

## Version 12

*Released: June 2025*

### New Features

- Splits sector configuration
- Route Plotter TSD interface
- Weather alert integration

### Improvements

- MapLibre GL JS migration
- Performance improvements
- Mobile responsiveness

---

## Version 11

*Released: April 2025*

### New Features

- Ground Delay Program (GDP) support
- EDCT management
- Enhanced planning worksheets

---

## Version 10

*Released: February 2025*

### New Features

- Initial PERTI platform release
- VATSIM OAuth integration
- Basic planning tools
- Ground Stop prototype

---

## Migration Notes

### Upgrading to v18

1. Apply NOD flow migrations: `database/migrations/nod/001_facility_flow_tables.sql`, `002_flow_element_fea_linkage.sql`
2. Apply TMR migration: `r_tmr_reports` table in perti_site MySQL
3. Apply playbook migrations: `database/migrations/playbook/001-004` in perti_site MySQL
4. Import Canadian sector GeoJSON files to `assets/data/` directory
5. Deploy new API endpoints: `api/nod/flows/*`, `api/nod/fea.php`, `api/data/review/tmr_*.php`, `api/data/playbook/*`, `api/mgt/playbook/*`
6. Ensure `assets/locales/en-US.json`, `fr-CA.json`, and `assets/js/lib/i18n.js` are deployed
7. Verify `PERTI_MYSQL_ONLY` is NOT set in any files that use Azure SQL connections

### Upgrading to v17

1. Apply migrations 092-095 to Azure SQL (config modifiers, ATIS priority)
2. Apply migrations demand/001-004 to Azure SQL (airspace demand functions)
3. Deploy new API endpoints (api/adl/demand/*)
4. Install Node.js simulator engine if using ATFM Simulator

### Upgrading to v16

1. Apply migrations 079-091 to Azure SQL
2. Update ATIS daemon to latest version
3. Configure demand page access
4. Review rate suggestion settings

### Upgrading to v15

1. Apply TMI migrations (001-003)
2. Update GDT frontend components
3. Configure weather tile sources
4. Test GS workflow end-to-end

---

## Deprecations

### v18

- `reroutes.php` removed (use `route.php` + `tmi-publish.php`)
- `advisory-builder.php` / `advisory-builder.js` removed (functionality in tmi-publish)
- Legacy admin migration scripts removed
- Hardcoded English strings in JS (use `PERTII18n.t()` for new strings)

### v16

- Legacy GDP endpoints (use new GDT API)
- Old demand calculation views (replaced by procedures)

### v15

- `flight_status` column (replaced by `phase`)
- Legacy ground stop tables (migrated to NTML)

---

## Upcoming (Planned)

### v19 (Planned)

- ATFM Simulator Phase 2 (Enhanced GDP slot management, AFP support)
- TMI Historical Import (NTML compact parser, ADVZY parser)
- Reroute compliance automation
- StatSim v2 integration
- Airspace demand visualization UI enhancements
- Additional i18n locale support

---

## See Also

- [[Getting Started]] - Setup guide
- [[Deployment]] - Deployment procedures
- [[Architecture]] - System overview
