# Playbook

> **Hibernation Notice:** The Playbook feature is suspended during hibernation mode. Route data remains intact but real-time route expansion via the GIS API is unavailable while system resources are downscaled. The feature will resume normal operation when hibernation is exited.

The vATCSCC Playbook is a pre-coordinated route play catalog for traffic management. It stores collections of routes organized by scenario (weather, volume, construction) that can be quickly activated during events. Plays originate from multiple sources including FAA playbook data, DCC-authored routes, ECFMP flow measures, and CANOC advisories.

**URL:** `/playbook.php`
**Access:** Authenticated (read); DCC role (write)

---

## Overview

The Playbook page provides:

- **Play Catalog** — Searchable, filterable list of all pre-coordinated route plays
- **Route Detail** — Master-detail view showing routes for a selected play with origin/destination/route string
- **Map Visualization** — MapLibre GL map rendering all routes in a play with sector boundary overlays
- **Play Management** — Create, edit, duplicate, and archive plays (DCC users)
- **Bulk Paste** — Parse ECFMP/CANOC-format route blocks into structured play routes
- **Shareable Links** — Deep links to specific plays via `?play=NAME` URL parameter
- **Changelog** — Full audit trail of play modifications
- **Route Grouping & Coloring** — DCC Region auto-grouping with canonical color assignments
- **Facility Filter Dropdowns** — Optimized play list loading with ARTCC/TRACON facility filtering
- **Collapsible Edit Sections** — Streamlined edit modal with collapsible metadata sections
- **Advisory Parser** — Parse advisory text to extract route definitions
- **Route Analysis Tools** — Consolidation, compaction, and auto-filter generation
- **FIR Pattern Expansion** — International facility matching via ICAO prefix patterns
- **US ICAO ARTCC Normalization** — Automatic conversion of ICAO codes (KZAB to ZAB, PAZA to ZAN)

---

## Page Layout

The playbook uses a two-column master-detail layout:

| Section | Description |
|---------|-------------|
| **Map Hero** | Full-width MapLibre GL map at top showing selected play routes |
| **Catalog Header** | Title, search box, source filter pills, legacy toggle, create button |
| **Category Pills** | Dynamic category filter pills generated from distinct play categories |
| **Play List** (left) | Scrollable list of plays with name, category, route count |
| **Detail Panel** (right) | Selected play details: routes table, description, metadata |

---

## Filtering & Search

### Source Filter

Filter plays by originating source:

| Source | Description |
|--------|-------------|
| **All** | Show all sources (default) |
| **FAA** | FAA Playbook routes (imported from national data) |
| **DCC** | DCC-authored custom plays |
| **ECFMP** | EUROCONTROL-style flow measures from European divisions |
| **CANOC** | Canadian Network Operations Centre plays |

### Category Pills

Dynamic pills appear below the catalog header, showing each distinct category with play counts. Clicking a pill filters the list to that category. Categories are user-defined per play (e.g., "EAST_GATE", "WEST_GATE", "SOUTH_FLOW").

### Text Search

The search box filters plays by matching against play name, display name, and description.

### Legacy Toggle

The "Show Legacy" checkbox includes archived plays in the list. By default, archived plays are hidden.

---

## Play Management

### Creating a Play

1. Click the **+** button in the catalog header (requires DCC permission)
2. Fill in the Create Play modal:
   - **Play Name** — Unique identifier (e.g., `ZNY_WEST_SWAP`)
   - **Display Name** — Human-readable label (e.g., "ZNY West Gate SWAP")
   - **Category** — Grouping category (select existing or type new)
   - **Scenario Type** — `WEATHER`, `VOLUME`, `CONSTRUCTION`, or `GENERAL`
   - **Route Format** — `standard` (single routes) or `split` (segmented routes)
   - **Source** — `DCC`, `ECFMP`, or `CANOC`
   - **Status** — `active` or `draft`
   - **Description** — Free-text description
3. Add routes in the routes table (origin, origin filter, dest, dest filter, route string, remarks)
4. Click **Save**

### Editing a Play

1. Select a play from the catalog
2. Click the **Edit** button in the detail panel
3. Modify fields and routes in the edit modal
4. Click **Save** — changes are logged to the changelog

### Duplicating a Play

1. Select a play
2. Click **Duplicate**
3. A copy is created with `_MODIFIED` appended to the play name
4. The duplicate opens in edit mode for modification

### Bulk Paste

1. Click **Bulk Paste** in the edit modal
2. Paste ECFMP/CANOC format route text into the textarea
3. Click **Apply** — routes are parsed and added to the routes table
4. Source is auto-detected from the paste format

**FIR Pattern Expansion in Bulk Paste:** When pasting routes with FIR-scoped origin or destination patterns (e.g., `CZEG`), the bulk paste parser expands them to individual ARTCC codes based on FIR membership. For example, `CZEG` expands to `CZEG CZVR` and similar grouped patterns. ICAO prefix patterns (e.g., `K*` for US domestic, `C*` for Canadian) are also detected and expanded using the global FIR code registry.

**Token-Type Splitting:** The parser uses token-type analysis to distinguish between origin filters, route strings, and destination filters in pasted text. This handles mixed-format input from ECFMP, CANOC, and advisory text sources.

**US ICAO ARTCC Code Normalization:** US ICAO-format ARTCC codes are automatically normalized throughout the system. For example, `KZAB` becomes `ZAB`, `KZNY` becomes `ZNY`, and Alaska/Pacific codes like `PAZA` become `ZAN`. This normalization applies to bulk paste, filtering, and display.

### Deleting a Play

1. Select a play
2. Click **Delete** (with confirmation dialog)
3. Play and all associated routes are removed (CASCADE delete)
4. Deletion is logged to the changelog

---

## Shareable Links

Plays can be shared via URL using the `?play=NAME` parameter:

```
https://perti.vatcscc.org/playbook.php?play=ZNY_WEST_SWAP
```

When a shareable link is loaded, the page auto-selects and displays the referenced play.

---

## Map Visualization

The map hero area renders play routes using MapLibre GL JS:

- Routes are expanded to coordinates via the GIS API (`expand_route`)
- Each route is rendered as a colored line with waypoint markers
- Sector boundaries from the selected ARTCC overlay the map
- Multiple routes display simultaneously with color coding
- The map auto-fits bounds to show all routes in the selected play

Dependencies: `route-maplibre.js`, `route-symbology.js`, `playbook-cdr-search.js`, `playbook-dcc-loader.js`, `awys.js`, `procs_enhanced.js`, Turf.js

---

## Route Grouping & Coloring (PRs #143-148)

Routes within a play can be grouped by DCC Region with automatic canonical color assignments.

### DCC Region Auto-Grouping

When routes are loaded, the system can auto-group them by the originating DCC region (e.g., Eastern, Central, Western). Each group receives a distinct canonical color from a predefined palette for consistent visual identification on the map.

### Canonical Color System

| Feature | Description |
|---------|-------------|
| **Auto-assignment** | Colors assigned from a fixed palette based on group index |
| **Consistency** | Same group always gets the same color across sessions |
| **Override** | Individual route colors can be overridden in the edit modal |
| **Map rendering** | Grouped routes share color on the MapLibre GL map |

---

## Facility Filter Dropdowns (PRs #149-150)

### Optimized Play List Loading

The play catalog supports facility-based filtering via dropdown selectors for ARTCC and TRACON codes. The play list endpoint accepts `artcc` and `tracon` filter parameters, enabling fast narrowing of the catalog to plays relevant to a specific facility.

### Facility Filter UI

- ARTCC dropdown populated from distinct `origin_filter` / `dest_filter` values across all plays
- TRACON dropdown for terminal-level filtering
- Filters combine with text search and source pills for compound filtering

---

## FIR Pattern Expansion & International Matching (PRs #151-159)

### ICAO Prefix FIR Patterns

The system recognizes ICAO prefix patterns for international facility matching:

| Pattern | Expansion | Region |
|---------|-----------|--------|
| `K*` | All US domestic ARTCCs | United States |
| `C*` | All Canadian FIRs | Canada |
| `EG*` | UK FIRs (EGTT, EGPX) | United Kingdom |
| `LF*` | French FIRs | France |

### Global FIR Code Registry

A centralized FIR code registry maps ICAO codes to their component ARTCCs and provides canonical metadata (name, region, division). This registry powers:

- FIR pattern expansion in bulk paste
- FIR pattern expansion in route rendering
- Dynamic `areaCenters` from GeoJSON for map visualization
- Facility filter dropdown population

### FIR Exclusions

FIR scope filtering supports exclusion patterns (prefixed with `!`) to exclude specific ARTCCs from an expanded FIR group. For example, `CZEG !CZVR` includes all CZEG member ARTCCs except those in CZVR.

### ARTCC Member-Based Scope Filtering

Routes can be filtered by ARTCC membership within a FIR. When a FIR code is used as a scope filter, only routes whose origin or destination ARTCCs are members of that FIR are included.

---

## Pseudo-Fix Audit & TRACON Fixes (PRs #154-162)

### 218 TRACON Pseudo-Fixes

The system includes 218 TRACON pseudo-fixes (205 US + 13 Canadian) that represent TRACON entry/exit points for route analysis. These are used for:

- Route segment matching when real navigation fixes are not available
- TRACON-level demand analysis
- Route advisory scope definition

### Pseudo-Fix Audit

A pseudo-fix audit tool validates that all pseudo-fixes referenced in playbook routes resolve to known navigation data. Unresolved pseudo-fixes are flagged for review.

---

## Route Analysis Tools (PR #163)

Three route analysis tools assist with play management:

### Consolidation

Identifies routes within a play that share the same origin-destination pair and can be merged or deduplicated. Highlights potential overlaps and suggests consolidation actions.

### Compaction

Reduces route verbosity by removing redundant intermediate waypoints that fall on published airways. Produces shorter, cleaner route strings while preserving the actual flight path.

### Auto-Filters

Automatically generates origin and destination filter values based on route patterns within a play. Analyzes the set of routes and suggests ARTCC-level or TRACON-level filter values that best describe the play's scope.

---

## Route Analysis Panel (Shared Module)

The Route Analysis Panel (`route-analysis-panel.js`) provides detailed en-route analysis for individual playbook routes. It uses a two-mode resolution system:

### Mode 1 (Client-Side)
When the route is plotted on the map or has frozen geometry stored in `route_geometry`, waypoints with coordinates are sent directly to the GIS analysis API. This avoids server-side re-resolution and produces the most accurate results.

### Mode 2 (Server-Side Fallback)
When no client-resolved waypoints are available, the route string is sent to PostGIS for server-side expansion via `expand_route_with_artccs()`.

### Analysis Output
- **Facility Traversal Table** — Lists each ARTCC, TRACON, and sector traversed with distance (nm), time (min), and entry/exit UTC times
- **Fix Analysis Table** — Cumulative distance and ETA at each waypoint along the route
- **Segment Analysis Table** — Per-segment distances, times, and ground speed between consecutive waypoints
- **Summary** — Total distance (nm), total time, departure time reference

### Features
- Configurable cruise speed (kts) and wind component for time calculations
- Departure time input for absolute UTC ETA computation
- Time format toggle (HH:MM vs HH:MM:SS)
- Facility type filters (ARTCC, FIR, TRACON, sectors)
- Export to clipboard, TXT, CSV, and XLSX formats
- Standalone route picker for ad-hoc route analysis (origin/dest/route string)
- Pseudo-fix filtering (UNKN, VARIOUS tokens are auto-skipped)
- Distance rounded to 1 decimal place (NM)

---

## Frozen Route Geometry

Routes can store a frozen geometry envelope in the `route_geometry` column. This preserves the route's geographic representation independently of AIRAC cycle changes — if a fix is renamed or removed in a future cycle, the frozen geometry still provides the original coordinates.

### Geometry Envelope Format
```json
{
  "geojson": { "type": "LineString", "coordinates": [[lon, lat], ...] },
  "waypoints": [
    { "fix_name": "MERIT", "lat": 40.123, "lon": -73.456, "source": "nav_fix" },
    ...
  ],
  "distance_nm": 1234.5,
  "frozen_at": "2026-03-17T12:00:00Z"
}
```

### Traversed Facility Columns
Computed via PostGIS boundary intersection at save time or by the backfill script:
- `traversed_artccs` — Comma-separated ARTCC codes
- `traversed_tracons` — Comma-separated TRACON codes
- `traversed_sectors_low` — Low-altitude sectors
- `traversed_sectors_high` — High-altitude sectors
- `traversed_sectors_superhigh` — Super-high sectors

### Backfill Script
`scripts/playbook/backfill_geometry.php` — HTTP-triggered batch script that computes frozen geometry and traversed facilities for all routes where `route_geometry IS NULL`. State tracked in MySQL `playbook_backfill_state` table. Uses the same PostGIS `expand_route_with_artccs()` pipeline as the real-time save path.

### Coordinate Token Parsing (PostGIS Migration 008)
PostGIS `resolve_waypoint()` supports aviation coordinate formats as a fallback when a token doesn't match any database fix:
- **ICAO compact**: `4520N07350W` (lat 45°20'N, lon 073°50'W)
- **NAT slash**: `45/73` (45°N, 73°W)
- **NAT half-degree**: `H4573` (45°30'N, 73°30'W)
- **ARINC trailing**: `4573N` (45°N, 73°W, northern hemisphere)
- **ARINC middle**: `45N73` (45°N, 73°W)

---

## PostGIS Spatial Validation

Route expansion through PostGIS includes validation to reject bad airway data and oversized waypoint jumps:

### `expand_airway()` Validation

| Check | Threshold | Purpose |
|-------|-----------|---------|
| **Intra-segment distance** | 2000 km (~1080 nm) | Rejects airway segments where the from-fix and to-fix are impossibly far apart (wrong hemisphere data) |
| **Inter-segment gap** | 500 km | Rejects adjacent segments that do not connect (to-point of segment N far from from-point of segment N+1) |
| **Context proximity** | 2500 km (~1350 nm) | When a previous waypoint is known, rejects matches where the fix is in the wrong hemisphere |

### `expand_route()` Validation

| Check | Threshold | Purpose |
|-------|-----------|---------|
| **Max waypoint distance** | 7400 km (~4000 nm) | Caps the maximum distance between any two consecutive resolved waypoints |

These validations reject approximately 4,330 bad airway segments (wrong hemisphere fixes such as A315 matching DARKE in Nepal, A574 matching ABA in Australia, UB881 matching BTO in Indonesia).

---

## International CIFP Procedure Support

Route expansion resolves international departure procedures (DPs) and standard terminal arrival routes (STARs) sourced from X-Plane 12 / Navigraph CIFP data files (ARINC 424 format).

### Coverage

- **32,565** international DP rows and **29,497** international STAR rows across **9,561** international airports
- US airports (K/PA/PH/PG/PW/PM prefixes) are excluded — those use FAA NASR data

### Procedure Expansion in Routes

When `expand_route()` encounters a DP or STAR token, it resolves the procedure to its constituent waypoints using the `nav_procedures` table. PostGIS includes both NASR-sourced (US) and CIFP-sourced (international) procedures. The `is_superseded` filter ensures only current-cycle procedures are used.

### Transition-Specific Computer Codes

Procedures use transition-aware computer codes with a `transition_type` column (`fix`, `runway`, or `NULL`):

| Type | DP Format | STAR Format | Example |
|------|-----------|-------------|---------|
| Fix transition | `PROC.FIX` | `FIX.PROC` | `MERIT3.MERIT` / `MERIT.CAMRN4` |
| Runway transition | `PROC.RWxx` | `RWxx.PROC` | `MERIT3.RW22L` / `RW04R.CAMRN4` |
| Base (no transition) | `PROC` | `PROC` | `MERIT3` / `CAMRN4` |

---

## Collapsible Edit Sections & Advisory Parser (PRs #151-152)

### Collapsible Edit Modal

The play edit modal uses collapsible sections for metadata fields, reducing visual clutter when editing routes. Sections include:

- **Basic Info** (name, display name, category) - expanded by default
- **Classification** (scenario type, source, status) - collapsed by default
- **Description & Remarks** - collapsed by default, supports multi-line text

### Advisory Parser

An advisory text parser extracts route definitions from advisory text blocks. Paste an advisory and the parser identifies route strings, origin/destination pairs, and scope information.

### Multi-Line Remarks & Descriptions

Route remarks and play descriptions support multi-line text input. Line breaks are preserved in storage and display.

---

## ATC-Zero Import & Daily Backup (PRs #148-149)

### ATC-Zero Import Script

An import script loads ATC-Zero incident data for correlation with playbook activations. This supports post-event analysis of whether appropriate plays were activated during facility outages.

### Daily Backup Daemon

A daily backup process exports the current playbook state (plays + routes) to a timestamped JSON file. This provides a recovery point independent of database backups.

---

## Integration with Route Plotter

DCC plays can be expanded and visualized on the Route Plotter (`route.php`):

1. On the Route Plotter, search for a playbook play
2. Select a play to load its routes onto the map
3. The `playbook-dcc-loader.js` module handles route expansion via the GIS API
4. Routes render with the same styling as playbook page

---

## API Endpoints

### Internal (PERTI) Read Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/data/playbook/list.php` | GET | Session | List plays with filtering (category, status, source, search, artcc, pagination) |
| `/api/data/playbook/get.php` | GET | Session | Get single play with routes (by `id` or `name`) |
| `/api/data/playbook/categories.php` | GET | None | Distinct categories with counts, plus available sources |
| `/api/data/playbook/changelog.php` | GET | Session | Playbook audit trail |
| `/api/data/playbook/analysis.php` | POST | None | Route analysis (facility traversal, fix/segment analysis, distance, time via PostGIS). Accepts `route_waypoints`, `route_string`, `cruise_kts`, `facility_types` |

### Internal (PERTI) Write Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/mgt/playbook/save.php` | POST | Session | Create or update play with routes |
| `/api/mgt/playbook/delete.php` | POST | Session | Delete play (CASCADE to routes) |
| `/api/mgt/playbook/route.php` | POST/DELETE | Session | Add or remove individual routes |

### SWIM API (External) Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/swim/v1/playbook/plays` | GET | Public | List plays or get single play by `id` or `name` (serves from SWIM_API mirror) |
| `/api/swim/v1/playbook/analysis` | GET | API Key | Route analysis (proxies to internal analysis API) |
| `/api/swim/v1/playbook/throughput` | GET/POST | API Key | CTP route throughput data |
| `/api/swim/v1/routes/cdrs` | GET | Public | Coded Departure Routes (~41K routes, serves from SWIM_API mirror) |

See [[API Reference]] for internal endpoint details and [[SWIM Routes API]] for external SWIM endpoint documentation.

---

## Database Schema

Playbook tables reside in **perti_site** MySQL (authoritative source):

| Table | Purpose |
|-------|---------|
| `playbook_plays` | Play definitions (name, category, source, scenario, status, org_code, visibility, CTP scope) |
| `playbook_routes` | Routes per play (origin, dest, route string, filters, traversed facilities, frozen geometry, remarks) |
| `playbook_changelog` | Audit trail (action, field, old/new values, user, timestamp, session context) |
| `playbook_route_groups` | Per-play route grouping with color assignments |
| `playbook_route_throughput` | CTP throughput data per route |
| `playbook_play_acl` | Per-play access control list (visibility permissions) |

SWIM API mirrors in **SWIM_API** Azure SQL (isolated external data layer, synced daily at 06:00Z):

| Table | Purpose |
|-------|---------|
| `swim_playbook_plays` | Mirror of `playbook_plays` (~3,800 rows) |
| `swim_playbook_routes` | Mirror of `playbook_routes` (~268,000 rows) |
| `swim_coded_departure_routes` | Mirror of `coded_departure_routes` from VATSIM_REF (~41,000 rows) |
| `vw_swim_refdata_sync_status` | Monitoring view (row counts + minutes since last sync) |

### Key Columns

**`playbook_plays`**: `play_id` PK, `play_name` (unique), `display_name`, `category`, `source` (FAA/DCC/ECFMP/CANOC/CADENA/FAA_HISTORICAL), `scenario_type` (WEATHER/VOLUME/CONSTRUCTION/GENERAL), `route_format` (standard/split), `status` (active/draft/archived), `visibility` (public/local/private_users/private_org), `route_count`, `org_code`, `ctp_scope`, `ctp_session_id`, `description` (text), `remarks` (text)

**`playbook_routes`**: `route_id` PK, `play_id` FK (CASCADE), `origin`, `dest`, `origin_filter`, `dest_filter`, `route_string`, `origin_airports`, `origin_tracons`, `origin_artccs`, `dest_airports`, `dest_tracons`, `dest_artccs`, `traversed_artccs`, `traversed_tracons`, `traversed_sectors_low/high/superhigh`, `route_geometry` (frozen JSON envelope), `remarks` (text), `sort_order`

See [[Database Schema]] for full column definitions.

---

## Frontend Architecture

| File | Purpose |
|------|---------|
| `playbook.php` | Page layout with map hero, catalog, detail panel, edit modal |
| `assets/js/playbook.js` | Core playbook module (catalog, detail, CRUD, search, filter, route grouping, analysis bridge) |
| `assets/js/route-analysis-panel.js` | Shared route analysis module (facility traversal, fix/segment analysis, export) |
| `assets/js/playbook-cdr-search.js` | CDR/playbook route search component |
| `assets/js/playbook-dcc-loader.js` | DCC play loader with GIS route expansion and FIR pattern expansion |
| `assets/js/fir-scope.js` | FIR boundary scope with global FIR code registry |
| `assets/js/fir-integration.js` | FIR data integration and ICAO prefix pattern expansion |
| `assets/css/playbook.css` | Playbook-specific styles |
| `assets/css/route-analysis.css` | Route analysis panel styles |

### Permission Model

The PHP page sets `window.PERTI_PLAYBOOK_PERM` based on the user's session. When `true`, the create/edit/delete UI elements are visible. When `false`, the page is read-only.

---

## Migrations

| Migration | Purpose |
|-----------|---------|
| `database/migrations/playbook/001_create_playbook_tables.sql` | Create `playbook_plays`, `playbook_routes`, `playbook_changelog` |
| `database/migrations/playbook/002_add_ecfmp_canoc_sources.sql` | Add `ECFMP` and `CANOC` to source enum |
| `database/migrations/playbook/003_fix_canadian_fir_classification.sql` | Fix Canadian FIR classification |
| `database/migrations/playbook/004_add_route_remarks.sql` | Add `remarks` column to `playbook_routes` |
| `database/migrations/playbook/005_add_traversed_artccs.sql` | Add `traversed_artccs` column for en-route ARTCC filtering |
| `database/migrations/playbook/006_add_route_remarks.sql` | Add remarks + `CADENA` source enum |
| `database/migrations/playbook/007_widen_facilities_columns.sql` | Widen `facilities_involved` and `impacted_area` to 2000 chars |
| `database/migrations/playbook/008_add_historical_source.sql` | Add `FAA_HISTORICAL` source enum |
| `database/migrations/playbook/009_route_groups.sql` | Create `playbook_route_groups` table |
| `database/migrations/playbook/010_widen_remarks.sql` | Widen remarks to TEXT for multi-line |
| `database/migrations/playbook/011_visibility_acl.sql` | Add visibility column + `playbook_play_acl` table |
| `database/migrations/playbook/012_analysis_throughput.sql` | Create `playbook_route_throughput` + CTP scope columns |
| `database/migrations/playbook/013_add_route_geometry.sql` | Add `route_geometry` TEXT column for frozen geometry envelope |
| `database/migrations/postgis/008_coordinate_waypoints.sql` | Add `parse_coordinate_token()`, update `resolve_waypoint()` and `expand_route()` for coordinate tokens |

---

## See Also

- [[Route Plotter]] - Route visualization with playbook integration
- [[API Reference]] - Playbook API documentation
- [[Database Schema]] - Full table definitions
- [[Splits]] - Sector boundary data used in map overlays
- [[Changelog]] - Version history
