# Playbook

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

## Integration with Route Plotter

DCC plays can be expanded and visualized on the Route Plotter (`route.php`):

1. On the Route Plotter, search for a playbook play
2. Select a play to load its routes onto the map
3. The `playbook-dcc-loader.js` module handles route expansion via the GIS API
4. Routes render with the same styling as playbook page

---

## API Endpoints

### Read Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/data/playbook/list.php` | GET | List plays with filtering (category, status, source, search, artcc, pagination) |
| `/api/data/playbook/get.php` | GET | Get single play with routes (by `id` or `name`) |
| `/api/data/playbook/categories.php` | GET | Distinct categories with counts, plus available sources |
| `/api/data/playbook/changelog.php` | GET | Playbook audit trail |

### Write Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/mgt/playbook/save.php` | POST | Create or update play with routes |
| `/api/mgt/playbook/delete.php` | POST | Delete play (CASCADE to routes) |
| `/api/mgt/playbook/route.php` | POST/DELETE | Add or remove individual routes |

See [[API Reference]] for full endpoint documentation.

---

## Database Schema

Playbook tables reside in **perti_site** MySQL:

| Table | Purpose |
|-------|---------|
| `playbook_plays` | Play definitions (name, category, source, scenario, status, org_code) |
| `playbook_routes` | Routes per play (origin, dest, route string, filters, remarks) |
| `playbook_changelog` | Audit trail (action, field, old/new values, user, timestamp) |

### Key Columns

**`playbook_plays`**: `play_id` PK, `play_name` (unique), `display_name`, `category`, `source` (FAA/DCC/ECFMP/CANOC), `scenario_type` (WEATHER/VOLUME/CONSTRUCTION/GENERAL), `route_format` (standard/split), `status` (active/draft/archived), `route_count`, `org_code`

**`playbook_routes`**: `route_id` PK, `play_id` FK (CASCADE), `origin`, `dest`, `origin_filter`, `dest_filter`, `route_string`, `remarks`

See [[Database Schema]] for full column definitions.

---

## Frontend Architecture

| File | Purpose |
|------|---------|
| `playbook.php` | Page layout with map hero, catalog, detail panel, edit modal |
| `assets/js/playbook.js` | Core playbook module (catalog, detail, CRUD, search, filter) |
| `assets/js/playbook-cdr-search.js` | CDR/playbook route search component |
| `assets/js/playbook-dcc-loader.js` | DCC play loader with GIS route expansion |
| `assets/css/playbook.css` | Playbook-specific styles |

### Permission Model

The PHP page sets `window.PERTI_PLAYBOOK_PERM` based on the user's session. When `true`, the create/edit/delete UI elements are visible. When `false`, the page is read-only.

---

## Migrations

| Migration | Purpose |
|-----------|---------|
| `database/migrations/playbook/001_create_playbook_tables.sql` | Create `playbook_plays`, `playbook_routes`, `playbook_changelog` |
| `database/migrations/playbook/002_add_source_enum.sql` | Add `ECFMP` and `CANOC` to source enum |
| `database/migrations/playbook/003_add_org_code.sql` | Add `org_code` for multi-organization support |
| `database/migrations/playbook/004_add_route_remarks.sql` | Add `remarks` column to `playbook_routes` |

---

## See Also

- [[Route Plotter]] - Route visualization with playbook integration
- [[API Reference]] - Playbook API documentation
- [[Database Schema]] - Full table definitions
- [[Splits]] - Sector boundary data used in map overlays
- [[Changelog]] - Version history
