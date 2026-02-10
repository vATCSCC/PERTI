# NOD TMI & Facility Flows Design

**Date**: 2026-02-09
**Branch**: `feature/nod-tmi`
**Status**: Design

## Overview

Enhance the NAS Operations Dashboard (NOD) with three capabilities:

1. **Enhanced TMI sidebar** — richer cards for existing TMI types, new MIT/AFP and delay report sections
2. **TMI map overlays** — airport status rings, MIT fix markers, delay heatmap
3. **Facility flow configurations** — TMU users define and visualize arrival/departure flows for any facility, with one-click FEA (Flow Evaluation Area) demand monitoring

The facility flow system lets a TMU controller select a facility (e.g., A80 Atlanta TRACON), configure its arrival fixes, departure fixes, gates, procedures, and routes, then instantly convert any element into a demand monitor to see live traffic counts on the map.

## Architecture

### Data Flow

```
                           ┌──────────────────┐
                           │  VATSIM_ADL       │
                           │  facility_flow_*  │
                           │  tables           │
                           └────────┬──────────┘
                                    │
            ┌───────────────────────┼───────────────────────┐
            │                       │                       │
   api/nod/flows/          api/nod/tmi_active.php    api/nod/fea.php
   configs.php                (enhanced)              (FEA bridge)
   elements.php                     │                       │
   gates.php                        │                       │
   suggestions.php                  │                demand_monitors
            │                       │                  table
            └───────────┬───────────┴───────────┬───────────┘
                        │                       │
                     nod.js                nod-demand-layer.js
                  (Flows tab,              (FEA rendering,
                   TMI cards,               demand counts)
                   map layers)
```

### Database Schema

Three new tables in VATSIM_ADL.

#### `facility_flow_configs`

Top-level configuration — one per facility per user (or shared).

| Column | Type | Description |
|--------|------|-------------|
| `config_id` | INT PK IDENTITY | |
| `facility_code` | VARCHAR(10) | e.g., `A80`, `ZTL`, `KATL` |
| `facility_type` | VARCHAR(10) | `AIRPORT`, `TRACON`, `ARTCC` |
| `config_name` | VARCHAR(100) | e.g., `A80 Standard`, `KATL South Flow` |
| `created_by` | INT | CID of creator |
| `is_shared` | BIT DEFAULT 0 | Visible to all TMU users |
| `is_default` | BIT DEFAULT 0 | Auto-load when facility selected |
| `map_center_lat` | DECIMAL(9,6) | Saved map center |
| `map_center_lon` | DECIMAL(9,6) | |
| `map_zoom` | DECIMAL(4,2) | |
| `boundary_layers` | NVARCHAR(MAX) | JSON: `{"tracon": true, "artcc": false, "sectors": ["N","S"]}` |
| `created_at` | DATETIME2 | |
| `updated_at` | DATETIME2 | |

#### `facility_flow_elements`

Individual elements within a config.

| Column | Type | Description |
|--------|------|-------------|
| `element_id` | INT PK IDENTITY | |
| `config_id` | INT FK | Parent config |
| `element_type` | VARCHAR(20) | `FIX`, `PROCEDURE`, `GATE`, `AIRWAY_SEGMENT`, `ROUTE` |
| `element_name` | VARCHAR(100) | Display name |
| `fix_name` | VARCHAR(16) | `nav_fixes.fix_name` (for FIX type) |
| `procedure_id` | INT | `nav_procedures.procedure_id` (for PROCEDURE type) |
| `route_string` | VARCHAR(1000) | Route string (for ROUTE type) |
| `route_geojson` | NVARCHAR(MAX) | Pre-expanded route geometry |
| `direction` | VARCHAR(10) | `ARRIVAL`, `DEPARTURE`, `BOTH` |
| `gate_id` | INT FK NULL | Parent gate (for grouped fixes) |
| `sort_order` | INT DEFAULT 0 | |
| `color` | VARCHAR(7) | Hex color, e.g., `#ff6b35` |
| `line_weight` | INT DEFAULT 2 | |
| `line_style` | VARCHAR(10) | `solid`, `dashed`, `dotted` |
| `label_format` | VARCHAR(50) | e.g., `{name}`, `{name} ({count})` |
| `icon` | VARCHAR(30) | Fix icon type |
| `is_visible` | BIT DEFAULT 1 | |
| `demand_monitor_id` | INT NULL | Link to `demand_monitors` table |
| `auto_fea` | BIT DEFAULT 0 | Auto-create FEA when config loads |
| `created_at` | DATETIME2 | |
| `updated_at` | DATETIME2 | |

#### `facility_flow_gates`

Named groupings of fixes.

| Column | Type | Description |
|--------|------|-------------|
| `gate_id` | INT PK IDENTITY | |
| `config_id` | INT FK | Parent config |
| `gate_name` | VARCHAR(50) | e.g., `North Arrivals` |
| `direction` | VARCHAR(10) | `ARRIVAL`, `DEPARTURE`, `BOTH` |
| `color` | VARCHAR(7) | |
| `label_format` | VARCHAR(50) | |
| `sort_order` | INT DEFAULT 0 | |
| `demand_monitor_ids` | VARCHAR(500) | Comma-separated monitor IDs for all member fixes |
| `auto_fea` | BIT DEFAULT 0 | |
| `created_at` | DATETIME2 | |
| `updated_at` | DATETIME2 | |

### API Endpoints

#### Facility Flow Config API — `api/nod/flows/`

**`configs.php`**

| Method | Params | Description |
|--------|--------|-------------|
| GET | `?facility_code=A80` | List configs for facility |
| GET | `?config_id=12` | Single config with all elements and gates |
| POST | `{facility_code, facility_type, config_name, ...}` | Create config |
| PUT | `?config_id=12 {config_name, boundary_layers, ...}` | Update config |
| DELETE | `?config_id=12` | Delete config |

GET by `config_id` returns pre-built GeoJSON for all elements (server-side assembly to avoid N+1 fix lookups on client).

**`elements.php`**

| Method | Params | Description |
|--------|--------|-------------|
| GET | `?config_id=12` | All elements for config |
| POST | `{config_id, element_type, element_name, ...}` | Add element |
| PUT | `?element_id=45 {color, label_format, ...}` | Update element |
| DELETE | `?element_id=45` | Remove element |
| POST | `?action=bulk [{...}, ...]` | Bulk add |
| POST | `?action=reorder {element_ids: [...]}` | Reorder |

For `ROUTE` type elements, the POST handler calls existing route expansion logic server-side to validate the route string and store the expanded geometry as GeoJSON in `route_geojson`.

**`gates.php`**

| Method | Params | Description |
|--------|--------|-------------|
| GET | `?config_id=12` | All gates for config |
| POST | `{config_id, gate_name, direction, color, ...}` | Create gate |
| PUT | `?gate_id=7 {gate_name, color, ...}` | Update gate |
| DELETE | `?gate_id=7` | Delete gate |
| POST | `?action=assign {gate_id: 7, element_ids: [...]}` | Assign fixes to gate |

**`suggestions.php`**

```
GET ?facility_code=A80&facility_type=TRACON
```

Returns suggested arrival/departure fixes and procedures:

```json
{
  "arrival_fixes": [
    {"fix_name": "ERLIN", "lat": 33.89, "lon": -84.12,
     "source": "star_initial", "procedures": ["ERLIN2", "ERLIN3"]}
  ],
  "departure_fixes": [
    {"fix_name": "VARNM", "lat": 33.62, "lon": -84.41,
     "source": "dp_final", "procedures": ["VARNM3", "VARNM4"]}
  ],
  "procedures": [
    {"procedure_id": 1234, "procedure_type": "STAR",
     "procedure_name": "ERLIN3", "runways": "8L,8R,9L,9R"}
  ],
  "common_fixes": [
    {"fix_name": "ERLIN", "direction": "arrival", "flight_count_30d": 842}
  ]
}
```

Sources:
- `arrival_fixes`: initial fixes from STARs at the airport (`nav_procedures` + `nav_procedure_legs` sequence 1)
- `departure_fixes`: terminal fixes from DPs (last leg)
- `common_fixes`: aggregate `afix`/`dfix` counts from `adl_flight_plan` (30-day window)

#### Enhanced TMI API — `api/nod/tmi_active.php` (modified)

New fields added to existing response:

- `ground_stops[].flights_held` — count from `adl_flight_tmi` where `gs_held = 1`
- `ground_stops[].avg_hold_minutes` — average hold duration
- `gdps[].controlled_count`, `gdps[].exempt_count`, `gdps[].compliance_rate`, `gdps[].total_delay_minutes` — from `tmi_programs`
- `reroutes[].assigned_count`, `reroutes[].compliant_count` — from `tmi_reroutes`

New arrays in response:

```json
{
  "mits": [{
    "entry_id": 101,
    "entry_type": "MIT",
    "ctl_element": "MERIT",
    "restriction_value": 20,
    "restriction_unit": "NM",
    "requesting_facility": "ZNY",
    "providing_facility": "ZTL",
    "reason_code": "VOLUME",
    "valid_from": "2026-02-09T13:00:00Z",
    "valid_until": "2026-02-09T18:00:00Z",
    "fix_lat": 40.52,
    "fix_lon": -73.89,
    "status": "ACTIVE"
  }],
  "afps": [{
    "entry_id": 102,
    "entry_type": "AFP",
    "ctl_element": "ZNY_WEST",
    "restriction_value": 40,
    "restriction_unit": "RATE"
  }],
  "delays": [{
    "delay_id": 55,
    "airport": "KJFK",
    "delay_type": "ARRIVAL",
    "delay_minutes": 45,
    "delay_trend": "INCREASING",
    "holding_status": "HOLDING",
    "holding_fix": "CAMRN",
    "reason": "VOLUME/WEATHER",
    "program_id": 12,
    "timestamp_utc": "2026-02-09T15:30:00Z"
  }],
  "summary": {
    "total_mits": 2,
    "total_afps": 1,
    "total_delays": 3,
    "max_delay_minutes": 67
  }
}
```

MIT/AFP data from `tmi_entries` joined to `nav_fixes` for lat/lon. Delays from `tmi_delay_entries`.

#### FEA Bridge API — `api/nod/fea.php`

| Method | Params | Description |
|--------|--------|-------------|
| POST | `{source_type: "flow_element", element_id: 45}` | Create demand monitor from flow element |
| POST | `{source_type: "tmi_entry", entry_id: 101}` | Create demand monitor from MIT/AFP |
| POST | `{source_type: "bulk", config_id: 12}` | Create monitors for all elements in config |
| DELETE | `?source_type=flow_element&element_id=45` | Remove linked monitor |
| DELETE | `?source_type=config&config_id=12` | Remove all monitors for config |

Element-to-monitor type mapping:

| Element Type | Monitor Type | Parameters |
|-------------|-------------|------------|
| FIX | `via_fix` | `{filter: {type: "airport", code: facility_airport, direction: element.direction}, via: fix_name}` |
| GATE | multiple `via_fix` | One per member fix, grouped |
| ROUTE | `segment` | Full `route_geojson` stored on monitor for map rendering |
| PROCEDURE | `via_fix` | Terminal fix of the procedure |
| AIRWAY_SEGMENT | `airway_segment` | First/last fix of segment |

## UI Design

### Enhanced TMI Cards

Professional, data-dense card design with FontAwesome icons (no emoji).

#### Ground Stop Card

```
┌─ GS ─────────────────────────┐
│ KJFK Ground Stop             │
│ 1430Z - 1600Z    47m left    │  countdown timer
│ Prob Extension: 60%          │
│ Origins: ZNY, ZBW, ZDC       │
│ Flights held: 23             │  live from adl_flight_tmi
│ Avg hold time: 34m           │
│ [map icon] [list icon]       │  view on map, flight list
└──────────────────────────────┘
```

Border: red (#dc3545). Countdown computed client-side from `end_utc`.

#### GDP Card

```
┌─ GDP ────────────────────────┐
│ KEWR GDP  AAR: 30            │
│ 1200Z - 2000Z    3h 12m     │
│ Avg delay: 45m  Max: 90m     │
│ Controlled: 67  Exempt: 12   │
│ Compliance: 82%              │
│ [map icon] [GDT] [slots]    │
└──────────────────────────────┘
```

Border: amber (#ffc107). GDT links to GDT page for that program.

#### Reroute Card

```
┌─ REROUTE ────────────────────┐
│ ZNY North Reroute  ADV-023   │
│ 1400Z - 2200Z                │
│ Protected: MERIT..GREKI      │
│ Assigned: 34  Compliant: 28  │
│ Compliance: 82% ████████░░   │  progress bar
│ [map icon] [route] [report]  │
└──────────────────────────────┘
```

Border: cyan (#17a2b8). Route toggles GeoJSON on map. Report opens compliance report.

#### MIT Card (new)

```
┌─ MIT ────────────────────────┐
│ 20 MIT MERIT (ZNY > ZTL)     │
│ 1300Z - 1800Z    2h 15m     │
│ Reason: Volume                │
│ [map icon] [chart icon]     │  view fix, create FEA
└──────────────────────────────┘
```

Border: cyan (#17a2b8). Chart icon creates a demand monitor for the MIT fix.

#### Delay Report Card (new)

```
┌─ DELAY ──────────────────────┐
│ KJFK  Arrival Delay          │
│ 45 min avg  (arrow) Incr.    │  trend arrow (fa-arrow-up)
│ Holding: CAMRN               │
│ Reason: Volume/Weather        │
│ Program: KJFK GDP             │  linked program
│ [map icon]                   │
└──────────────────────────────┘
```

Color-coded by severity: green (<15m), yellow (15-45m), orange (45-90m), red (>90m). Trend from sequential `tmi_delay_entries`.

### Facility Flows Tab

Fourth tab in the NOD right sidebar.

```
┌─────────────────────────────┐
│ [TMI] [Advy] [JATOC] [Flows]│
├─────────────────────────────┤
│ Facility: [A80 - Atlanta  v]│  Select2 searchable
│ Config:   [South Flow     v]│  saved configs
│ [+ New] [Save] [Delete]     │
├─────────────────────────────┤
│ > Boundaries                 │  collapsible
│   [x] TRACON outline        │
│   [ ] ARTCC (ZTL)           │
│   [ ] Sectors               │
├─────────────────────────────┤
│ > Arrival Fixes (4)     [+] │
│   * ERLIN   [color][fea][eye]│  color picker, FEA toggle, visibility
│   * DALAS   [color][fea][eye]│
│   * RPTOR   [color][fea][eye]│
│   * FLCON   [color][fea][eye]│
├─────────────────────────────┤
│ > Departure Fixes (3)   [+] │
│   * VARNM   [color][fea][eye]│
│   * LOGEN   [color][fea][eye]│
│   * CUTTN   [color][fea][eye]│
├─────────────────────────────┤
│ > Gates (2)              [+] │
│   > North Arrivals (2)      │  expandable
│     ERLIN, DALAS            │
│   > West Departures (1)     │
│     VARNM                   │
├─────────────────────────────┤
│ > Procedures (2)         [+] │
│   - RPTOR3 STAR  [color][eye]│
│   - VARNM3 DP   [color][eye]│
├─────────────────────────────┤
│ > Routes (1)             [+] │
│   - KATL.VARNM3.VARNM..    │
│     SMLTZ [color][fea][eye] │
├─────────────────────────────┤
│ [Monitor All as FEA]        │  bulk demand monitors
│ [Clear FEAs]                │
└─────────────────────────────┘
```

Icons are FontAwesome: `fa-palette` (color), `fa-chart-bar` (FEA), `fa-eye`/`fa-eye-slash` (visibility), `fa-plus` (add), `fa-trash` (delete).

### Map Overlays

#### Layer Z-Order (updated, new layers marked with *)

1. Weather radar
2. TRACON boundaries
3. *Facility flow boundaries (selected facility outline)
4. Active splits
5. JATOC incidents
6. Sector boundaries
7. ARTCC boundaries
8. *Facility flow procedure lines (STAR/DP paths)
9. *Facility flow route lines (route geometries)
10. Public routes
11. *MIT fix markers (diamond + restriction label)
12. *Facility flow fix markers (arrival/departure fixes, gates)
13. *Airport TMI status rings
14. Flight routes
15. Waypoints
16. Traffic (always on top)

#### Airport TMI Status Rings

Airports with active TMIs get a colored ring:

- **Ground Stop**: Red (#dc3545), pulsing opacity 0.6-1.0 every 800ms
- **GDP**: Amber (#ffc107), solid
- **MIT inbound**: Cyan (#17a2b8), dashed
- **No TMI**: No ring

Multiple TMIs: outermost ring = highest severity (GS > GDP > MIT).

Optional delay glow sublayer: background circle sized by `delay_minutes`, color by severity scale, opacity 0.15 with blur.

#### MIT Fix Markers

Diamond marker at fix location, label showing restriction value (e.g., `20 MIT`), cyan color. Tooltip on hover with full details.

#### Facility Flow Elements

**Fixes**: outer ring (gate or element color, 7px, 30% opacity) + inner dot (4px, solid). Label below with text halo for readability.

**Procedures/Routes**: glow line behind (element color, +3px width, 20% opacity) + core line (configured weight/style/color). Route FEAs show demand count at line center via `symbol-placement: line-center`.

**Route FEA rendering**: when a route element has an active demand monitor, the demand layer renders the full `route_geojson` LineString geometry (not just a two-point segment). This ensures the complete route path is visible on the map.

#### Map Layer Toggles

Two new entries in the Map Layers toolbar:
```
[x] Facility Flows   [opacity slider]
[x] TMI Status        [opacity slider]
```

### Card-to-Map Interaction

- **Card action buttons** (`fa-map-marker-alt`): pan/zoom to location, 2-second pulse highlight, open MapLibre popup
- **Map click on TMI feature**: scroll sidebar to matching card, highlight with brief border flash
- Bidirectional linking keeps map and sidebar in sync

## FEA Integration

### Core Principle

Any geographic element on the NOD — flow fix, gate, route, procedure, MIT fix — is one click from becoming a demand monitor.

### Element-to-Monitor Mapping

| Element | Monitor Type | Monitor Parameters |
|---------|-------------|-------------------|
| Fix | `via_fix` | airport + direction + fix_name |
| Gate | multiple `via_fix` | one per member fix |
| Route | `segment` | full `route_geojson` for map rendering |
| Procedure | `via_fix` | terminal fix of procedure |
| Airway Segment | `airway_segment` | first/last fix |
| MIT (TMI) | `via_fix` | MIT fix + direction from facility context |
| GDP (TMI) | airport arrival | airport + arrival direction |

### Demand Count Feedback

When FEAs are active, demand data flows back into the flow display:

- Fix labels update: `ERLIN (12)` — using `{count}` placeholder in `label_format`
- Gate labels aggregate: `North Arrivals (28)` — sum of member fix counts
- Sidebar rows show count badge, color-coded by threshold
- Route labels show count at line midpoint

### Bulk Operations

- **Monitor All as FEA**: creates demand monitors for every visible element in the active config
- **Clear FEAs**: removes all monitors linked to the active config

## Internationalization

All user-facing strings use `PERTII18n.t()`. All timestamps use `PERTII18n.formatDate()`. All numbers use `PERTII18n.formatNumber()`.

### New i18n Keys

```json
{
  "nod": {
    "flows": {
      "title": "Facility Flows",
      "selectFacility": "Select facility",
      "selectConfig": "Select configuration",
      "newConfig": "New Configuration",
      "saveConfig": "Save",
      "deleteConfig": "Delete",
      "boundaries": "Boundaries",
      "arrivalFixes": "Arrival Fixes",
      "departureFixes": "Departure Fixes",
      "gates": "Gates",
      "procedures": "Procedures",
      "routes": "Routes",
      "monitorAllFea": "Monitor All as FEA",
      "clearFeas": "Clear FEAs",
      "addFix": "Add fix",
      "addGate": "Add gate",
      "addProcedure": "Add procedure",
      "addRoute": "Add route",
      "fixPlaceholder": "Enter fix name...",
      "routePlaceholder": "Enter route string...",
      "gateName": "Gate name",
      "direction": {
        "arrival": "Arrival",
        "departure": "Departure",
        "both": "Both"
      },
      "feaActive": "Monitoring as FEA",
      "feaCreate": "Monitor as FEA",
      "confirmDelete": "Delete this configuration?"
    },
    "tmi": {
      "flightsHeld": "{count} flights held",
      "avgHoldTime": "Avg hold: {minutes} min",
      "countdown": "{time} remaining",
      "probExtension": "Prob. extension: {pct}%",
      "controlled": "Controlled: {count}",
      "exempt": "Exempt: {count}",
      "compliance": "Compliance: {pct}%",
      "avgDelay": "Avg delay: {minutes} min",
      "maxDelay": "Max delay: {minutes} min",
      "viewOnMap": "View on map",
      "flightList": "Flight list",
      "showRoute": "Show route",
      "mit": "MIT",
      "afp": "AFP",
      "delayReport": "Delay Report",
      "delayTrend": {
        "increasing": "Increasing",
        "decreasing": "Decreasing",
        "stable": "Stable"
      },
      "severity": {
        "low": "Low",
        "moderate": "Moderate",
        "high": "High",
        "severe": "Severe"
      }
    }
  }
}
```

## Implementation Phases

### Phase 1: TMI Sidebar Enhancements + Map Status Layer

**Scope**: Enhance existing TMI cards, add MIT/AFP and delay sections, add airport TMI status rings.

**Backend**:
- Modify `api/nod/tmi_active.php` — join `adl_flight_tmi` for GS/GDP flight counts, add `mits[]`/`afps[]` from `tmi_entries` joined to `nav_fixes`, add `delays[]` from `tmi_delay_entries`

**Frontend** (`nod.js`):
- Updated card templates with countdown timers, flight counts, compliance bars, action buttons
- New `renderMITSection()` and `renderDelaySection()`
- New `tmi-status-source` + `tmi-mit-source` MapLibre layers
- Card-to-map and map-to-card bidirectional click handlers
- GS pulse animation

**i18n**: Add `nod.tmi.*` keys to `en-US.json`

**Migration**: None (reads existing tables only)

### Phase 2: Facility Flow Configuration (DB + API + UI)

**Scope**: Schema, API, sidebar Flows tab with full CRUD.

**Backend**:
- Migration: `database/migrations/nod/001_facility_flow_configs.sql` — create three tables
- New `api/nod/flows/` directory: `configs.php`, `elements.php`, `gates.php`, `suggestions.php`
- Route expansion integration for ROUTE elements

**Frontend** (`nod.js`):
- Flows tab in sidebar
- Facility selector (Select2 from `facility-hierarchy.js`)
- Config CRUD, element list with add/edit/delete per type
- Gate creation and fix-to-gate assignment
- Fix autocomplete from suggestions API
- Route input with validation

**i18n**: Add `nod.flows.*` keys to `en-US.json`

### Phase 3: Flow Map Rendering + Styling

**Scope**: Render flow elements on MapLibre, per-element styling, boundary integration.

**Frontend** (`nod.js`):
- `flow-elements-source` GeoJSON source with all layer types
- `loadFlowConfig()` builds GeoJSON from config API response
- Boundary layer rendering from existing boundary endpoints
- Inline color picker, visibility toggle, label format editor
- Line weight/style controls
- Map auto-zoom on config load
- Layer toggle in Map Layers toolbar

**Backend**: `configs.php` GET returns pre-built GeoJSON for all elements

### Phase 4: FEA Integration

**Scope**: Bridge flow elements and TMI entries to demand monitoring.

**Backend**:
- New `api/nod/fea.php`
- Migration: `database/migrations/nod/002_flow_element_fea_linkage.sql` — add `demand_monitor_id` column

**Frontend**:
- FEA toggle (`fa-chart-bar`) on element rows and MIT/GDP cards
- Bulk "Monitor All as FEA" / "Clear FEAs"
- Demand count feedback into element labels and sidebar badges
- Extend `nod-demand-layer.js` for route LineString geometries
- Gate aggregate counts

### Phase Dependencies

```
Phase 1 ─────────────────────────┐
                                  │
Phase 2 ──── Phase 3 ──── Phase 4│
                                  │
Phases 1 & 2 are independent     │
Phase 3 requires Phase 2         │
Phase 4 requires Phases 2 & 3    │
```

## Files to Create/Modify

### New Files

| File | Description |
|------|-------------|
| `database/migrations/nod/001_facility_flow_configs.sql` | Schema for 3 new tables |
| `database/migrations/nod/002_flow_element_fea_linkage.sql` | Add demand_monitor_id column |
| `api/nod/flows/configs.php` | Flow config CRUD |
| `api/nod/flows/elements.php` | Element CRUD |
| `api/nod/flows/gates.php` | Gate CRUD |
| `api/nod/flows/suggestions.php` | Auto-suggest fixes/procedures |
| `api/nod/fea.php` | FEA bridge API |

### Modified Files

| File | Changes |
|------|---------|
| `nod.php` | Add Flows tab HTML, TMI status layer toggle, new CSS |
| `assets/js/nod.js` | Flows tab logic, enhanced TMI cards, new map layers, card-map interaction |
| `assets/js/nod-demand-layer.js` | Route LineString rendering, FEA count feedback |
| `api/nod/tmi_active.php` | Add flight counts, MITs, AFPs, delays |
| `assets/locales/en-US.json` | Add `nod.flows.*` and `nod.tmi.*` keys |
