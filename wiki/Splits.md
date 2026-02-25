# Splits

The Splits tool manages sector/position configurations for traffic management coordination. It provides ARTCC-level sector grouping, position assignment, scheduled split activation, and interactive map visualization with strata filtering.

**URL:** `/splits.php`
**Access:** Authenticated

---

## Features

- Area/sector grouping definitions per ARTCC or FIR
- Configuration presets with draft, active, and scheduled states
- Active split assignments with personnel tables
- Scheduled splits with automatic activation via the scheduler daemon
- Interactive MapLibre GL sector map with strata filtering (low/high/superhigh)
- Region grouping for multi-ARTCC operations
- Sector map integrated on plan pages (Splits tab)
- Support for 23 US ARTCCs and 10+ international FIRs (Canadian, Mexican, Caribbean)

---

## Concepts

### Areas

Logical groupings of sectors within an ARTCC (e.g., "West Area", "Arrival", "North High"). Areas are defined per facility and stored in `splits_areas` in VATSIM_ADL. Each area can have a display color for map visualization.

### Positions

Individual controller positions within an area. Positions are stored in `splits_positions` and include a `strata_filter` column (`low`, `high`, `superhigh`, or `all`) to control which sector altitude layer the position covers.

### Configurations

Saved combinations of area-to-position assignments for a facility. Configurations have a `status` field: `draft`, `active`, or `scheduled`. A scheduled configuration includes `start_time_utc` and `end_time_utc` for automatic activation.

### Active Splits

The currently active position assignments for a facility. Only one configuration per ARTCC can be active at a time. Activation replaces the previous active configuration.

### Presets

Reusable configuration templates that can be quickly loaded. Stored in `splits_presets` and `splits_preset_positions`.

---

## Strata Filtering

Filter sectors by altitude stratum on the map display:

| Stratum | Altitude Range | Description |
|---------|---------------|-------------|
| **Low** | Surface to FL240 | Low-altitude sectors (approach/departure corridors) |
| **High** | FL240 to FL350 | High-altitude sectors (main en-route) |
| **Superhigh** | FL350+ | Ultra-high sectors (oceanic, RVSM) |

The strata filter applies to both the sector map overlay and the position table. Positions can be assigned to specific strata, allowing controllers to be assigned only low or only high sectors.

---

## Scheduled Splits

Configurations can be scheduled for future activation:

1. Create or edit a configuration
2. Set status to "scheduled"
3. Set `start_time_utc` and `end_time_utc`
4. The scheduler daemon (`scripts/scheduler_daemon.php`, 60s interval) activates the configuration at the scheduled time and deactivates it at the end time

The scheduled splits layer on the map shows upcoming configurations with their time windows. Scheduled configurations appear in the splits sidebar with countdown timers.

---

## Sector Map

The splits page includes an interactive MapLibre GL map showing sector boundaries for the selected facility. Sector data comes from multiple sources:

1. **CRC VideoMaps** — GeoJSON extracted from the Common Reference Center pack
2. **Custom GeoJSON** — Per-facility sector boundaries in `assets/data/sectors/`
3. **PostGIS** — Sector boundary polygons from `VATSIM_GIS.sector_boundaries`

### Canadian FIR Sectors

Sector boundaries for 7 Canadian FIRs are stored as GeoJSON files organized by FIR and strata:

| FIR | Code | Sectors |
|-----|------|---------|
| Toronto | CZYZ | Low, high, superhigh |
| Winnipeg | CZWG | Low, high, superhigh |
| Edmonton | CZEG | Low, high, superhigh |
| Montreal | CZUL | Low, high, superhigh |
| Vancouver | CZVR | Low, high, superhigh |
| Moncton | CZQM | 5 low + 27 high = 32 sectors |
| Gander | CZQX | 3 low + 39 high = 42 sectors |

Combined files (`canadian_low.geojson`, `canadian_high.geojson`, `canadian_superhigh.geojson`) aggregate all Canadian FIR sectors for cross-border visualization.

**Total sector boundaries:** 1,379 (1,002 US + 377 Canadian)

Sector GeoJSON was generated from ESE (Enhanced Sector Extensions) files using a generalized converter that handles terminal keywords (`RADIO`, `UNICOM`, `NO-CONTROL`, `TRANSITION`) and LF-prefix airports.

---

## Plan Page Integration

The Splits tab on PERTI plan pages (`plan.php`) shows the sector map and active/scheduled configurations for the event's ARTCC(s). This allows planners to see staffing configurations alongside initiatives and field configs.

---

## Database Tables

All splits tables reside in **VATSIM_ADL** (Azure SQL):

| Table | Purpose |
|-------|---------|
| `splits_configs` | Saved configurations with status, ARTCC, start/end times |
| `splits_positions` | Position entries within a configuration (sector assignments, strata) |
| `splits_areas` | Sector area grouping definitions with display colors |
| `splits_presets` | Reusable configuration templates |
| `splits_preset_positions` | Preset position definitions |

Key columns on `splits_configs`:
- `id` (INT PK), `artcc` (NVARCHAR), `config_name`, `status` (draft/active/scheduled)
- `start_time_utc`, `end_time_utc` (DATETIME2 — for scheduled splits)
- `created_at`, `updated_at`

Key columns on `splits_positions`:
- `id` (INT PK), `config_id` (FK), `position_name`, `sector_ids` (NVARCHAR)
- `strata_filter` (NVARCHAR — `low`, `high`, `superhigh`, `all`)
- `personnel_name`, `personnel_ois`

---

## API Endpoints

**Base Path:** `/api/splits/`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `index.php` | GET | List available sector maps from CRC index for a facility |
| `sectors.php` | GET | Return sector GeoJSON boundaries for a facility (CRC, custom, or demo) |
| `maps.php` | GET | List available sector-related videomaps for a facility |
| `areas.php` | GET/POST/PUT/PATCH/DELETE | CRUD for sector area group definitions |
| `configs.php` | GET/POST/PUT/DELETE | CRUD for split configurations |
| `presets.php` | GET/POST/PUT/DELETE | CRUD for reusable configuration presets |
| `active.php` | GET | Return currently active splits for a facility |
| `scheduled.php` | GET/PUT/DELETE | List and manage scheduled configurations |
| `tracons.php` | GET | Return TRACON data for a facility |
| `scheduler.php` | GET/POST | Scheduler state and manual trigger |
| `connect_adl.php` | — | Standalone Azure SQL connection helper |
| `config.php` | — | Splits configuration constants (paths, ARTCC/FIR lists, map centers) |

### Key Parameters

**`sectors.php`:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `facility` | string | ARTCC/FIR code (required, e.g., `ZOB`, `CZEG`) |
| `filter` | string | Strata filter: `all`, `high`, `low`, `ultra` (default: `all`) |
| `demo` | int | `1` to force demo/sample data |

**`areas.php`:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `artcc` | string | Filter by ARTCC code |
| `id` | int | Area ID (for PUT/PATCH/DELETE) |

---

## Supported Facilities

### US ARTCCs (23)

ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX, ZKC, ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZTL, ZUA

### Canadian FIRs (7)

CZEG (Edmonton), CZUL (Montreal), CZWG (Winnipeg), CZVR (Vancouver), CZYZ (Toronto), CZQM (Moncton), CZQX (Gander)

### Other International FIRs

MMFR (Mexico North), MMID (Mazatlan), MMFO (Mexico South), TJZS (San Juan), MDCS (Santo Domingo), MKJK (Kingston), MUFH (Havana), TTZP (Piarco)

---

## See Also

- [[Architecture]] - System overview
- [[API Reference]] - Splits APIs
- [[Playbook]] - Playbook route catalog (uses sector boundaries on map)
- [[Database Schema]] - Full table definitions
