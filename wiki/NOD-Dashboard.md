# NOD Dashboard

The NAS Operations Dashboard provides consolidated monitoring of active TMIs, facility flow configurations, and system status.

**URL:** `/nod.php`
**Access:** Public (view), Authenticated (edit)
**Version:** v18 (February 2026)

---

## Features

- Active TMI display (Ground Stops, GDPs, Reroutes)
- Rich TMI data cards with real-time metrics (v18)
- Map TMI status layer with severity visualization (v18)
- Facility flow configuration system (v18)
- Flow map rendering with 8 dedicated layers (v18)
- FEA (Flow Evaluation Area) integration with demand monitors (v18)
- DCC advisory management
- Operations level indicator
- Flight track visualization
- Weather integration

---

## Dashboard Panels

### Active TMIs

Displays all currently active Traffic Management Initiatives:
- Ground Stops with affected airports
- Ground Delay Programs with rates
- Active reroutes

### Advisories

DCC advisories for coordination and awareness:
- Create, edit, and expire advisories
- Category filtering
- Search functionality

### Operations Status

Current NAS operations level with active incident count.

---

## v18 Enhancements

### Phase 1: Enhanced TMI Sidebar with Rich Data Cards

The TMI sidebar was redesigned with rich data cards that surface key operational metrics inline, reducing the need to navigate to separate pages.

#### Ground Stop Cards

- Countdown timer showing remaining duration
- Flights held count
- Probability of extension indicator
- Origin centers list

#### GDP Cards

- Controlled and exempt flight counts
- Average and maximum delay values
- Compliance bar showing program adherence rate
- Direct link to GDT (Ground Delay Table) for the program

#### Reroute Cards

- Assigned and compliant flight counts
- Compliance bar showing route adherence rate

#### MIT/AFP Section

- Restriction details (miles-in-trail value, fix, type)
- Fix coordinates displayed for spatial reference

#### Delay Reports Section

- Severity-based coloring (green/yellow/red thresholds)
- Trend indicators showing delay direction (increasing, decreasing, stable)

#### Map TMI Status Layer

A dedicated map layer visualizes TMI status geospatially:
- **Airport rings** colored by severity level
- **Delay glow circles** sized proportionally to delay magnitude
- **MIT fix markers** plotted at restriction fix coordinates
- **GS pulse animation** on airports under active Ground Stops

---

### Phase 2: Facility Flow Configuration System

A full facility flow configuration system allows users to define, manage, and visualize flow elements for any ARTCC or TRACON facility.

#### Database Tables

Three new tables in VATSIM_ADL (Azure SQL):

**`facility_flow_configs`** - Flow configuration definitions
| Column | Type | Description |
|--------|------|-------------|
| `config_id` | int PK | Auto-increment primary key |
| `facility_code` | varchar | ARTCC or TRACON facility code |
| `config_name` | varchar | Human-readable config name |
| `is_active` | bit | Whether config is currently active |
| `created_by` | varchar | CID of creating user |

**`facility_flow_elements`** - Elements within a flow config
| Column | Type | Description |
|--------|------|-------------|
| `element_id` | int PK | Auto-increment primary key |
| `config_id` | int FK | References `facility_flow_configs` |
| `element_type` | varchar | `FIX`, `PROCEDURE`, `ROUTE`, or `GATE` |
| `element_name` | varchar | Name of the element (fix name, procedure code, etc.) |
| `color` | varchar | Hex color for map rendering |
| `line_weight` | int | Line weight for PROCEDURE/ROUTE elements |
| `is_visible` | bit | Visibility toggle |
| `fea_enabled` | bit | Whether FEA demand monitoring is active |

**`facility_flow_gates`** - Gate points for gate-type elements
| Column | Type | Description |
|--------|------|-------------|
| `gate_id` | int PK | Auto-increment primary key |
| `element_id` | int FK | References `facility_flow_elements` |
| `gate_name` | varchar | Gate identifier |
| `gate_type` | varchar | Gate classification |
| `lat` | float | Latitude |
| `lon` | float | Longitude |

#### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET/POST/PUT/DELETE` | `/api/nod/flows/configs.php` | Flow configuration CRUD |
| `GET/POST/PUT/DELETE` | `/api/nod/flows/elements.php` | Flow element CRUD |
| `GET/POST/PUT/DELETE` | `/api/nod/flows/gates.php` | Flow gate CRUD |
| `GET` | `/api/nod/flows/suggestions.php` | Element name suggestions (autocomplete) |

#### Sidebar Flows Tab

- Facility selector dropdown (ARTCC/TRACON codes)
- Config selector for the chosen facility
- Flow element list with inline controls:
  - **Color picker** for per-element color assignment
  - **Visibility toggle** to show/hide on map
  - **FEA toggle** to enable/disable demand monitoring per element
- Fix and procedure autocomplete powered by `nav_fixes` and `nav_procedures` tables

---

### Phase 3-4: Flow Map Rendering & FEA Integration

#### Map Layers

Eight dedicated map layers render flow configuration elements:

| Layer | Description |
|-------|-------------|
| Boundary | Facility boundary outlines |
| Procedure lines | DP/STAR procedure route lines |
| Route lines | Named route string LineStrings |
| Fix markers | Navigation fix point markers |
| Gate markers | Flow gate point markers |
| Demand labels | Numeric demand counts at element positions |
| Demand arcs | Arc segments showing demand distribution |
| FEA highlights | Highlighted elements with active FEA monitoring |

- Per-element line weight selector for `PROCEDURE` and `ROUTE` type elements
- Route GeoJSON `LineString` support in the demand layer for rendering route geometry
- `nod-demand-layer.js` module handles all demand overlay rendering

#### FEA Bridge API

The FEA (Flow Evaluation Area) bridge connects flow elements to the demand monitor system.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/nod/fea.php` | FEA bridge operations |

Supported actions via the `action` parameter:
- **`toggle`** - Enable or disable demand monitoring for an element
- **`bulk_create`** - Create demand monitors for all FEA-enabled elements in a config
- **`bulk_clear`** - Remove all demand monitors for a config

#### Demand Count Feedback

- Sidebar element cards display live demand counts when FEA is enabled
- Map demand labels update on the configured refresh interval
- Demand arcs visualize directional flow volume between elements

---

## Data Refresh

| Data Type | Refresh Interval |
|-----------|------------------|
| Active TMIs | 30 seconds |
| TMI data cards | 30 seconds |
| Advisories | 60 seconds |
| Ops level | 30 seconds |
| Flow configs | On change |
| FEA demand counts | 30 seconds |

---

## See Also

- [[JATOC]] - Incident monitoring
- [[GDT Ground Delay Tool]] - TMI management
- [[API Reference]] - NOD APIs
