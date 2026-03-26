# Releases

A structured overview of PERTI platform releases, major features, and infrastructure milestones.

> **Current Version:** v19 | **Latest Activity:** March 2026 (v20 in development)

For full technical detail on every change, see [[Changelog]].

---

## Release Timeline

| Version | Released | Highlights |
|---------|----------|------------|
| **v20** | In Development | Historical Routes page, CTP playbook sync, AIRAC pipeline, security hardening |
| **v19** | March 2026 | SWIM data isolation, 25 mirror tables, 48 endpoint migrations |
| **v18** | February 2026 | GDP algorithm redesign, playbook system, NOD facility flows, i18n, Canadian sectors |
| **v17** | January 2026 | ATFM Training Simulator, airspace element demand, config modifiers |
| **v16** | January 2026 | Demand analysis system, airport configuration, ATIS integration |
| **v15** | December 2025 | Ground Stop NTML architecture, weather radar, SUA/TFR display |
| **v14** | October 2025 | ETA calculation, trajectory logging, zone detection, boundary crossings |
| **v13** | August 2025 | JATOC incident management, NOD dashboard, Discord integration |
| **v12** | June 2025 | Splits sector config, Route Plotter TSD, MapLibre migration |
| **v11** | April 2025 | GDP support, EDCT management, planning worksheets |
| **v10** | February 2025 | Initial platform release, VATSIM OAuth, basic planning tools |

---

## v20 (In Development)

*March 2026 — Active development on `main` branch*

v20 focuses on historical route analytics, cross-the-pond event support, international navdata, and platform security.

### Key Features

<details>
<summary><strong>Historical Routes Page</strong> (PR #229, follow-up PRs #231, #233-234, #237)</summary>

A full route history search and analysis page with MapLibre map visualization.

- **Search & Filtering**: Aircraft type, operator, DCC region, alliance, time period quick-selects, date range
- **Map Visualization**: Route frequency coloring (spectral palette), ARTCC/TRACON boundary overlays, airport markers, map tier selector (L/H/SH)
- **Route Analysis**: Click-to-select route highlighting, route info dialog with dep/arr fix stats, facility grouping, collapsible filter panel with chip pills
- **Data Features**: Show All toggle, antimeridian normalization, callsign normalization, flight detail columns, per-page pagination
- **UI**: Monospace styling, Select2 tag filters, dark theme, collapsible panels

Built on MySQL star schema: `dim_route`, `dim_aircraft_type`, `dim_operator`, `dim_time`, `route_history_facts` (RANGE partitioned by month).
</details>

<details>
<summary><strong>CTP Pull-Based Playbook Sync</strong> (PR #244)</summary>

Pull-based synchronization for Cross The Pond event playbook routes, adding an alternative to the existing push model.

- Pull-based sync architecture for CTP NAT track constraints and playbook routes
- Corrected require paths for push endpoint compatibility (4 levels, not 3)
- Go-live checklist documented for production deployment
</details>

<details>
<summary><strong>Playbook Facility Counts</strong> (PR #235, PRs #238-240, #242)</summary>

Facility-level route count analysis added to the playbook route analysis panel.

- **Facility counts section** in route analysis panel showing per-facility route counts with sector coverage
- **SWIM API endpoints**: Standalone `facility-counts` endpoint + integrated counts in single-play response
- **Sector coverage**: Multi-strata support (sectors can count toward multiple strata: low/high/superhigh)
- **Dark theme** styling to match the route analysis panel
- OpenAPI documentation for new endpoints
</details>

<details>
<summary><strong>AIRAC 2603 Pipeline & International CIFP</strong> (PRs #105, #198, #202, #204)</summary>

Full AIRAC cycle update pipeline with international procedure support.

- **AIRAC 2603 navdata update** with supersession tracking (`is_superseded`, `superseded_cycle`, `superseded_reason`) across 5 tables (REF + ADL + PostGIS)
- **International CIFP integration**: X-Plane 12 / Navigraph CIFP files (ARINC 424 format) — 9,561 international airports yielding 31,250 DP + 28,237 STAR rows
- **Navdata changelog page** with source tracking and pagination
- **Playbook route changelog** support for audit trail
- **PostGIS sync**: UPSERT for `nav_procedures` (preserves 90K CIFP rows) and `playbook_routes`
</details>

<details>
<summary><strong>Security Hardening</strong> (PRs #225-228)</summary>

Comprehensive security audit and remediation across the platform.

- **SQL injection fix** + authentication added to all mutation API endpoints (PR #227)
- **XSS prevention**: HTML output escaping applied across 16 API data endpoints (PR #228)
- **CORS whitelist**: Eliminated wildcard CORS, replaced with explicit origin whitelist (PRs #225-226)
- **Discord auth**: Added authentication to Discord webhook endpoints
</details>

<details>
<summary><strong>PostGIS Route Expansion Enhancements</strong> (PR #230, direct commits in March 2026)</summary>

Enhanced PostGIS route expansion with procedure support and coordinate parsing.

- `expand_route()` now resolves STAR and DP procedures inline during route expansion
- Consecutive waypoint deduplication at procedure boundaries
- Coordinate waypoint parsing restored for 5 aviation coordinate formats (PR #230)

</details>

<details>
<summary><strong>i18n Completion</strong> (commits in March 2026)</summary>

Internationalization coverage expanded to near-complete levels.

- **fr-CA** locale brought to 100% coverage
- Replaced ~170 hardcoded strings across 3 JS modules
- Orphaned and redundant keys removed from `en-EU` and `en-CA`
- Complete page title and locale coverage audit
- Facility count mode toggle keys added
</details>

<details>
<summary><strong>SWIM API Enhancements</strong></summary>

- **Route resolve endpoint** for VATSWIM API with batch mode support
- **Facility counts endpoint** with sector coverage data
- **Audit log**: Response code and response time tracking in `swim_audit_log`
- Updated VATSWIM API documentation with verified examples (PR #236)
</details>

---

## v19

*Released: March 2026*

> System entered hibernation mode March 22, 2026 — SWIM daemons and API endpoints remain active.

v19 completed the SWIM data isolation project, making the public API fully independent from internal databases.

### Summary

The entire SWIM API layer was migrated to query exclusively from the `SWIM_API` database. Internal databases (`VATSIM_TMI`, `VATSIM_ADL`, `VATSIM_REF`, `perti_site`) are never accessed directly by API request handlers.

<details>
<summary><strong>SWIM Data Isolation — Full Details</strong> (Migration 026)</summary>

#### Schema Changes
- `swim_flights` expansion: +34 new columns, `row_hash BINARY(20)` for change detection, 14 unused indexes dropped (36 to 22)
- 25 new mirror tables: 10 TMI + 4 flow + 4 CDM + 4 reference + 3 infrastructure
- 14 SWIM views (8 active/recent TMI + 3 CDM + 3 existing)
- `sp_Swim_BulkUpsert` updated: row-hash skip eliminates ~60-70% of no-op updates

#### New Daemons
- `swim_tmi_sync_daemon.php` — Syncs 14 TMI/CDM/flow tables every 5 minutes (watermark-based delta detection + OPENJSON MERGE)
- `refdata_sync_daemon.php` — Syncs CDRs (~41K), playbook routes (~55K), airports, taxi reference daily at 06:00Z

#### Endpoint Migration
- 48 SWIM endpoints migrated to SWIM_API-only queries
- `PERTI_SWIM_ONLY` optimization: skips MySQL/ADL/TMI/REF/GIS connections (~500-1000ms saved per request)
- Removed ADL/TMI/REF/MySQL fallback paths from auth, flights, positions, CDRs, plays, keys endpoints
- TMI endpoints switched from `$conn_tmi` to `$conn_swim` with `swim_tmi_*` mirror tables (10 files)
- CDM endpoints switched to SWIM mirror reads with CDMService v2.0 (5 files + CDMService)

#### Bug Fixes
- WebSocket position timestamp: `pos.updated_at` to `pos.position_updated_utc`
- TMI sync advisory_number type mismatch: `INT` to `NVARCHAR(16)` (source data is 'ADVZY 001' format)
- TMI sync flight_control watermark: `modified_utc` to `updated_at` (actual source column name)
</details>

---

## v18

*Released: February 2026*

The largest release to date, v18 introduced the GDP algorithm redesign, playbook management system, NAS Operations Dashboard facility flows, internationalization across the full platform, and Canadian FIR sector boundaries.

### Key Features

<details>
<summary><strong>GDP Algorithm Redesign</strong> — 4-phase implementation (Migrations 037-041)</summary>

Complete rewrite of the Ground Delay Program slot assignment algorithm.

- **Phase 1** (Migration 037): Bug fixes, compression endpoint (`compress.php`), batch optimization
- **Phase 2** (Migration 038): FPFS+RBD (First-Planned First-Served + Ration By Distance) slot assignment algorithm, adaptive reserves, FlightListType TVP rebuild
- **Phase 3** (Migration 039): `sp_TMI_ReoptimizeProgram` orchestrator, `reoptimize.php` endpoint
- **Phase 4** (Migration 041): Reversal metrics, anti-gaming flags, GDT UI (compress/reopt/observability panels)
- TMI-to-ADL sync via `executeDeferredTMISync()` in ADL daemon (60s cycle, multi-program precedence)
</details>

<details>
<summary><strong>vATCSCC Playbook System</strong> (core: PRs #75-84; enhancements: PRs #143-163; plus related fixes)</summary>

Full pre-coordinated route play catalog and management system.

- **Playbook Page** (`/playbook.php`): Play CRUD with FAA and DCC source categories
- **MapLibre GL** route visualization with sector boundary overlays
- **Shareable links** with `?play=NAME` URL parameter
- **Bulk paste** with ECFMP/CANOC source auto-detection
- **Boolean search** with route config toolbar and region coloring
- **Floating catalog overlay** with checkbox multi-select and filter badges
- **Route grouping** by DCC Region with canonical colors
- **Route analysis tools**: LINESTRING traversal, traversed facility computation, detail panel split
- **FIR pattern expansion**: Global FIR code registry, ICAO prefix matching, token-type splitting
- **Multi-line remarks** and description support
- Database: `playbook_plays`, `playbook_routes`, `playbook_changelog` tables
</details>

<details>
<summary><strong>NOD TMI Enhancements & Facility Flows</strong> (PRs #19-23, #25, #27, #29-30, #34, #46)</summary>

Major expansion of the NAS Operations Dashboard.

**Phase 1 — TMI Sidebar:**
- GS cards: countdown timer, flights held, prob extension, origin centers
- GDP cards: controlled/exempt counts, avg/max delay, compliance bar, GDT link
- Reroute cards: assigned/compliant counts, compliance bar
- MIT/AFP section with restriction details and fix coordinates
- Map TMI status layer: airport rings by severity, delay glow circles, MIT fix markers, GS pulse animation

**Phase 2 — Facility Flows:**
- 3 new tables: `facility_flow_configs`, `facility_flow_elements`, `facility_flow_gates`
- CRUD APIs for configs, elements, gates, and suggestions
- Flow element management (fixes, procedures, routes, gates)
- Inline color picker, visibility toggle, FEA toggle per element
- 8 map layers: boundary, procedure/route lines, fix markers

**Phase 3-4 — FEA Integration:**
- FEA bridge API (`api/nod/fea.php`): demand monitor toggle, bulk create/clear
- Demand count feedback on sidebar and map labels
</details>

<details>
<summary><strong>Traffic Management Review (TMR) Report System</strong> (PR #18)</summary>

Guided post-event review workflow based on the FAA NTMO Guide structure.

- Sidebar navigation: triggers, overview, airport conditions, weather, TMIs, equipment, personnel, findings
- TMR report CRUD API with auto-save
- Historical TMI lookup from VATSIM_TMI database
- Bulk NTML paste parser for rapid TMI entry
- Discord-formatted TMR export
- Embedded demand charts (DemandChartCore) per plan airport
</details>

<details>
<summary><strong>Internationalization (i18n) System</strong> (PRs #53, #58, #111, #112)</summary>

Full internationalization infrastructure across the platform.

- Core translation module (`PERTII18n`) with `t()`, `tp()`, `formatNumber()`, `formatDate()`
- Locale loader with auto-detection (URL param, localStorage, browser language)
- SweetAlert2 dialog wrapper (`PERTIDialog`) with i18n key resolution
- **Locales**: en-US (7,276 keys), fr-CA (7,560 keys — 100% coverage), en-CA (557 overlay), en-EU (509 overlay)
- Integrated across 45 of 65 JS modules and all 30 PHP pages
</details>

<details>
<summary><strong>Canadian FIR Sector Expansion</strong> (PRs #79-84)</summary>

Comprehensive Canadian airspace boundary data.

- **CZYZ** (Toronto): Low, high, superhigh sector boundaries
- **CZWG** (Winnipeg): Sector boundaries with ESE conversion
- **CZEG** (Edmonton): Sector boundaries
- **CZUL** (Montreal): Sector boundaries
- **CZVR** (Vancouver): Sector boundaries
- **CZQM** (Moncton): 5 low + 27 high = 32 sectors
- **CZQX** (Gander): 3 low + 39 high = 42 sectors
- Generalized ESE-to-GeoJSON converter handling terminal keywords and LF-prefix airports
- Total sector boundaries: 1,379 (1,002 US + 377 Canadian)
</details>

<details>
<summary><strong>Multi-Organization Support</strong> (PRs #68-72)</summary>

Support for multiple ATC organizations beyond vATCSCC.

- Org-scoped facility authorization for TMI and JATOC endpoints
- Multi-org Discord posting via `tmi_discord_posts` queue
- CANOC and ECFMP flow measure sources integrated
- Org-aware i18n locale with `{commandCenter}` template resolution
</details>

<details>
<summary><strong>Ops Plan & Splits Enhancements</strong> (PRs #67-73, #84)</summary>

- Structured FAA-format Ops Plan with formatted output sections
- Sortable columns and ARTCC grouping in plan page tables
- Splits map layers with low/high/superhigh strata filtering
- Scheduled splits visualization on `splits.php` and plan page Splits tab
- Personnel tables for staffing assignments and region grouping
</details>

### Performance Improvements

<details>
<summary><strong>PERTI_MYSQL_ONLY & Frontend Parallelization</strong> (PR #17)</summary>

- `define('PERTI_MYSQL_ONLY', true)` skips 5 Azure SQL connections on ~98 plan/sheet/review endpoints (~500-1000ms saved per request)
- Lazy-loaded database connection getters (`get_conn_adl()`, `get_conn_tmi()`, etc.)
- `plan.js`: 16 sequential AJAX calls replaced with `Promise.all()` batch
- `sheet.js`: 5 calls parallelized
- `review.js`: 3 calls parallelized
</details>

### Infrastructure Changes

- VATSIM_ADL migrated from General Purpose Serverless to Hyperscale Serverless (16 vCores, min 3)
- PostgreSQL GIS database (vatcscc-gis) deployed: Burstable B2s, PostGIS, PostgreSQL 16
- MySQL upgraded from Burstable to General Purpose D2ds_v4
- Geo-replica server and VATSIM_Data database decommissioned

---

## v17

*Released: January 2026*

v17 introduced the ATFM Training Simulator, airspace element demand analysis, and the config modifiers system.

### Key Features

<details>
<summary><strong>ATFM Training Simulator</strong></summary>

Web-based TMU training environment at `/simulator.php`.

- Node.js flight engine with realistic physics simulation
- Practice GS/GDP/AFP/MIT/Reroute TMI decisions
- Reference data: 3,989 O-D route patterns, 107 airports, 17 carriers (sourced from BTS On-Time Performance — 20.6M flight records)
- MapLibre GL visualization with real-time flight movement
</details>

<details>
<summary><strong>Airspace Element Demand</strong></summary>

Traffic demand analysis at the fix, airway, and route segment level.

- Table-valued SQL functions: `fn_FixDemand`, `fn_AirwaySegmentDemand`, `fn_RouteSegmentDemand`
- Support for both airway-based and direct (DCT) route queries
- TRACON-based filtering for facility-specific queries
- Filtered indexes: `IX_waypoint_fix_eta`, `IX_waypoint_airway_eta`
</details>

<details>
<summary><strong>Config Modifiers System</strong></summary>

Structured runway configuration modifier categories replacing free-text modifiers.

- Categories: PARALLEL_OPS, APPROACH_TYPE, TRAFFIC_BIAS, VISIBILITY_CAT, SPECIAL_OPS, TIME_RESTRICT, WEATHER_OPS, NAMED
- Normalized schema: `modifier_category`, `modifier_type`, `config_modifier`
- New views: `vw_config_with_modifiers`, `vw_runway_with_modifiers`
</details>

<details>
<summary><strong>ATIS Type Priority Logic</strong></summary>

- Enhanced ATIS source selection: ARR+DEP > COMB > single
- Views: `vw_current_atis_by_type`, `vw_effective_atis`
- Improved weather data sourcing for rate suggestions
</details>

### New API Endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET /api/simulator/navdata.php` | Navigation data for routing |
| `GET/POST /api/simulator/engine.php` | Engine control |
| `GET /api/simulator/routes.php` | Route pattern data |
| `GET/POST /api/simulator/traffic.php` | Traffic generation |
| `GET /api/adl/demand/fix.php` | Flights at a navigation fix |
| `GET /api/adl/demand/airway.php` | Flights on an airway segment |
| `GET /api/adl/demand/segment.php` | Flights between two fixes |

---

## v16

*Released: January 2026*

v16 delivered the demand analysis system with weather-aware rate suggestions and the normalized airport configuration schema.

### Key Features

<details>
<summary><strong>Demand Analysis System</strong></summary>

Airport demand visualization and forecasting at `/demand.php`.

- Weather-aware rate suggestions with confidence scoring
- Manual rate override support with time windows
- Multi-level rate suggestion algorithm
- Airport demand charting with arrival/departure breakdown
</details>

<details>
<summary><strong>Airport Configuration & ATIS</strong></summary>

- Normalized runway configuration schema: `airport_config`, `airport_config_runway`, `airport_config_rate`
- VATSIM ATIS import with weather extraction
- Runway-in-use detection from ATIS parsing
- Flight-track-based runway detection as fallback
- Rate change audit trail via `rate_history`
</details>

---

## v15

*Released: December 2025*

v15 established the NTML-based Ground Stop architecture and added weather and airspace visualization layers.

### Key Features

<details>
<summary><strong>Ground Stop NTML Architecture</strong></summary>

- Complete GS program lifecycle management via stored procedures
- Pop-up flight detection for flights departing during ground stops
- EDCT issuance and management
- New API endpoints: `/api/tmi/gs/*`
</details>

<details>
<summary><strong>Weather Radar Integration</strong></summary>

- IEM NEXRAD/MRMS tile integration on map pages
- Multiple color table options for different weather products
- Configurable opacity and layer toggles
</details>

<details>
<summary><strong>SUA/TFR Display & Initiative Timeline</strong></summary>

- Special Use Airspace boundaries and TFR visualization on map
- Active/inactive SUA filtering
- Gantt-style TMI timeline with interactive navigation
</details>

---

## v14

*Released: October 2025*

v14 built the core flight data processing pipeline — ETA calculation, trajectory logging, and spatial awareness.

### Key Features

- **ETA calculation** with aircraft performance data (BADA/OpenAP)
- **Trajectory logging** and visualization on map
- **Zone detection** (OOOI — Out/Off/On/In gate events)
- **Boundary crossing detection** for ARTCC/TRACON/sector transitions
- Route parsing accuracy enhancements
- SimBrief flight plan integration

---

## v13

*Released: August 2025*

v13 introduced incident management and the real-time operations dashboard.

### Key Features

- **JATOC** incident management system (`/jatoc.php`) with CRUD APIs
- **NOD Dashboard** (`/nod.php`) for NAS-wide operational awareness
- **Public route sharing** for TMI visualization
- **Discord webhook integration** for automated TMI notifications

---

## v12

*Released: June 2025*

v12 migrated the map stack and added sector configuration tools.

### Key Features

- **Splits** sector configuration system (`/splits.php`)
- **Route Plotter** TSD interface (`/route.php`) with MapLibre GL JS
- **Weather alert integration** for operational awareness
- **MapLibre GL JS migration** from previous map library
- Mobile responsiveness improvements

---

## v11

*Released: April 2025*

v11 introduced traffic management initiative support.

### Key Features

- **Ground Delay Program (GDP)** support with slot assignment
- **EDCT management** for controlled departure times
- **Enhanced planning worksheets** for event preparation

---

## v10

*Released: February 2025*

The initial public release of the PERTI platform.

### Key Features

- VATSIM Connect OAuth integration for user authentication
- Basic planning tools (plans, configs, staffing)
- Ground Stop prototype
- Foundation for the ADL flight data pipeline

---

## Planned (Post-v20)

| Feature | Description |
|---------|-------------|
| CDM Adaptation | Hybrid FAA/EUROCONTROL/AMNAC collaborative decision-making model (6 phases) |
| GDP Daemon Reopt | Automatic 2-5 minute reoptimization cycle for active GDP programs |
| TMI Historical Import | NTML compact + ADVZY parsers for historical TMI data |
| Change Feed API | Delta endpoint (`GET /api/swim/v1/changes?since_seq=<n>&limit=<m>`) for external consumers |
| Reroute Compliance | Automated reroute compliance monitoring and alerting |
| StatSim v2 | Next-generation statistical simulation integration |

---

## Infrastructure Milestones

| Date | Milestone |
|------|-----------|
| March 2026 | AIRAC 2603 deployed with international CIFP (59,487 procedures) |
| March 2026 | Security hardening: SQL injection, XSS, CORS audit complete |
| March 2026 | SWIM data isolation (v19) — public API fully independent from internal databases |
| February 2026 | PostgreSQL/PostGIS database deployed (vatcscc-gis, B2s tier) — 7th database, 3rd engine |
| February 2026 | VATSIM_ADL migrated to Hyperscale Serverless (16 vCores) |
| February 2026 | MySQL upgraded from Burstable to General Purpose D2ds_v4 |

---

## See Also

- [[Changelog]] — Detailed technical changelog with migration notes
- [[Architecture]] — System design overview
- [[Deployment]] — Deployment procedures
- [[Getting Started]] — Setup guide
