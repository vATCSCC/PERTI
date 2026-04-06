# VATSWIM Reference Library Design

**Date**: 2026-04-05
**Status**: Draft
**Author**: Claude + jpeterson

## Overview

A comprehensive public reference data API for VATSIM developers, exposing aviation reference data (airports, navigation, airspace, facilities, aircraft, routes, AIRAC metadata) through the existing VATSWIM API layer. Includes a browsable geographic hierarchy for progressive drill-down and static bulk download files for offline use.

**Target audience**: VATSIM plugin authors, virtual airline developers, pilot/ATC tool builders.

## Architecture

### Approach: REST API + Bulk Downloads (Direct Query)

All endpoints live under `/api/swim/v1/reference/{domain}/{resource}`.

Endpoints query source databases directly (PostGIS, VATSIM_REF, VATSIM_ADL, MySQL) rather than maintaining SWIM_API mirror tables. Rationale:

- Reference data is low-frequency, highly cacheable, and read-only
- Eliminates 15-18 mirror tables, sync pipelines, and dual-schema maintenance
- Response caching via `SwimResponse::tryCachedFormatted()` provides isolation without mirrors
- Consistent with existing SWIM endpoints that query PostGIS directly (`/routes/resolve`, `/gis/boundaries`)

Bulk download files are pre-generated static JSON/GeoJSON/CSV served from the App Service. Regenerated on AIRAC cycle change (~every 28 days). Total bulk size: ~105 MB raw, ~30 MB compressed.

### Cost

- **REST API**: $0 incremental (queries existing databases)
- **Bulk files**: $0 (served from App Service, well within 100 GB/month free egress)
- **Storage**: ~105 MB static files on existing App Service disk

### Authentication

All endpoints require a SWIM API key (consistent with existing SWIM pattern). Self-service key registration already exists.

### Caching Strategy

| Domain | Cache TTL | Rationale |
|--------|-----------|-----------|
| airports, navigation, airspace, facilities | 24h | AIRAC-stable, changes every 28 days |
| aircraft, airlines | 7 days | Rarely changes |
| routes (statistics) | 1h | Derived from recent flight data |
| airac | 24h | Changes only at cycle boundary |
| utilities | None | Pure computation |
| bulk | AIRAC cycle | ETag + Last-Modified for client caching |

### File Layout

```
api/swim/v1/reference/
  airports.php        -- airport lookups, profiles, facilities, runways, search
  navigation.php      -- fixes, airways, procedures (DP/STAR)
  airspace.php        -- boundaries, sectors, FIRs, point-in-polygon
  facilities.php      -- center/TRACON lists, tier adjacency, curated lists
  aircraft.php        -- types, families, performance
  airlines.php        -- operator codes, callsigns
  routes.php          -- popular routes, city-pair statistics
  airac.php           -- cycle metadata, changelogs, superseded data
  utilities.php       -- distance, bearing, route decode
  hierarchy.php       -- geographic tree navigation
  bulk.php            -- catalog and file downloads
  taxi-times.php      -- (existing, retained)
```

Supporting files:

```
scripts/reference/generate_bulk.php   -- AIRAC-triggered bulk file generation
data/bulk/                            -- pre-generated static download files
```

### Cross-Cutting Standards

- **Format support**: `?format=json|geojson|csv|xml` (where applicable to domain)
- **Pagination**: `?page=1&per_page=50`, standard `pagination` response object
- **Geometry opt-in**: `?include=geometry` on list endpoints (omitted by default for speed)
- **Error codes**: `MISSING_PARAM`, `NOT_FOUND`, `INVALID_PARAM`, `SERVICE_UNAVAILABLE`
- **CORS**: All endpoints support CORS via `perti_set_cors()`
- **Audit**: All requests logged via `SwimAudit`

---

## Domain 1: Geographic Hierarchy

```
/api/swim/v1/reference/hierarchy/
```

A browsable tree that consumers drill down through progressively. Each node includes its parent chain and immediate children.

### Tree Structure

```
VATSIM Region (AMAS, EMEA, APAC)
  +-- Division (VATUSA, VATCAN, VATSIM UK, VATEUR, ...)
       +-- DCC Region (Eastern, Central, Western, ...) [US-specific, optional level]
            +-- Center / FIR (ZNY, EGTT, CZYZ, ...)
                 |-- Sub-area [where applicable]
                 |-- Sector (ZNY_42, ZNY_66, ...)
                 +-- TRACON (N90, C90, A80, ...)
                      +-- Airport (KJFK, KLGA, KEWR, ...)
                           +-- Runway (04L/22R, 13L/31R, ...)
```

### Endpoints

#### `GET /hierarchy`

Entry point. Returns top level (regions) with child counts.

Response shape:

```json
{
  "levels": ["region", "division", "dcc_region", "center", "tracon", "airport", "runway"],
  "roots": [
    { "code": "...", "name": "...", "type": "region", "children_count": 12 }
  ]
}
```

#### `GET /hierarchy/{type}/{code}`

Any node in the tree: its details, parent chain (breadcrumb), and immediate children.

Response shape:

```json
{
  "node": {
    "code": "...",
    "name": "...",
    "type": "center",
    "geometry": {},
    "detail_url": "/api/swim/v1/reference/facilities/centers/..."
  },
  "breadcrumb": [
    { "code": "...", "name": "...", "type": "region" },
    { "code": "...", "name": "...", "type": "division" }
  ],
  "children": {
    "tracons": [ { "code": "...", "name": "...", "type": "tracon", "children_count": 8 } ],
    "sectors": [ { "code": "...", "name": "...", "type": "sector", "strata": "high" } ]
  },
  "summary": {
    "total_tracons": 5,
    "total_sectors": 38,
    "total_airports": 147
  }
}
```

Children of mixed types (Center has TRACONs, sectors, sub-areas) are grouped by type.

#### `GET /hierarchy/{type}/{code}/children?type=airport`

All children of a specific type, supporting level-skipping. Example: all airports under a center (across all TRACONs). Paginated.

#### `GET /hierarchy/{type}/{code}/ancestors`

Full parent chain from any node to root.

#### `GET /hierarchy/search?q=...&type=center,tracon,airport`

Search across hierarchy levels by name or code.

### Data Sources

| Level | Source | Notes |
|-------|--------|-------|
| Region -> Division | Static reference table (new, ~100 rows) | Rarely changes |
| Division -> Centers | Static mapping + ICAO prefix rules | VATUSA -> K*/PA*/PH* FIRs |
| DCC Region -> Centers | Static mapping (US-only, ~4 regions) | |
| Center -> TRACONs | PostGIS `tracon_boundaries.parent_artcc` | Already populated |
| Center -> Sectors | PostGIS `sector_boundaries.parent_artcc` | Already populated |
| TRACON -> Airports | PostGIS `ST_Contains(tracon_geom, airport_point)` | Runtime spatial |
| Airport -> Runways | ADL `airport_geometry` | Already populated |

DCC Region is an optional level -- non-US divisions skip it. The tree adapts per division.

`detail_url` on every node links to the full endpoint in the appropriate domain (e.g., airport node links to `/reference/airports/{code}`). Hierarchy provides navigation; domain endpoints provide full detail.

Geometry is optional via `?include=geometry` to keep default responses lightweight.

---

## Domain 2: Airports

```
/api/swim/v1/reference/airports/
```

### Endpoints

#### `GET /airports/lookup?faa={lid}` or `?icao={code}`

Bidirectional FAA LID <-> ICAO conversion. Accepts either parameter, returns the mapping plus basic identification (name, city, state, country).

Source: PostGIS `airports`.

#### `GET /airports/{code}`

Full airport profile. Accepts either ICAO or FAA LID (auto-detected by length/pattern).

Fields: icao, faa_lid, name, city, state, country, lat, lon, elevation_ft, magnetic_variation, timezone, utc_offset, is_towered, airport_class, geometry (GeoJSON Point).

Source: PostGIS `airports` + ADL `apts`.

#### `GET /airports/{code}/facilities`

Responsible TRACON and Center for the airport. Returns tracon (code, name), center (code, name), and fir (code, name).

Source: PostGIS point-in-polygon via `get_boundaries_at_point()` using airport coordinates.

#### `GET /airports/{code}/runways`

Runway configurations: id (e.g., "04L/22R"), length_ft, width_ft, surface.

Source: ADL `airport_geometry`.

#### `GET /airports/{code}/taxi-times`

Proxy to existing `/reference/taxi-times.php?airport={code}`. Maintains backward compatibility.

#### `GET /airports/{code}/connect-times`

Connect-to-push reference time: unimpeded_connect_sec, sample_size, confidence, period.

Source: ADL `airport_connect_reference`.

#### `GET /airports/search?q=...` or `?near=lat,lon&radius=50`

Search by partial name, city, code, or lat/lon proximity.

Parameters:
- `q` -- text search (name, city, code)
- `near` -- lat,lon pair for proximity search
- `radius` -- search radius in nm (default 25, max 250)
- `country` -- country code filter
- `class` -- airport class filter
- `min_runway_ft` -- minimum runway length
- Paginated, max 100 per page

Source: PostGIS `airports` with `ST_DWithin()` for proximity.

---

## Domain 3: Navigation

```
/api/swim/v1/reference/navigation/
```

### Endpoints

#### `GET /navigation/fixes`

List/search fixes.

Parameters:
- `name` -- exact match or prefix (e.g., `MERIT`, `MER*`)
- `type` -- `fix`, `navaid`, `vor`, `ndb`, `dme`, `waypoint`
- `near` -- lat,lon proximity (radius in nm, default 25)
- `artcc` -- fixes within an ARTCC boundary
- `country` -- country filter
- `include` -- `geometry` adds GeoJSON Point
- Paginated, max 200 per page

Source: PostGIS `nav_fixes` (392K records). Proximity uses `ST_DWithin()`.

#### `GET /navigation/fixes/{name}`

Fix detail. Returns an array because fix names are not globally unique (same name can exist in multiple countries). Each entry includes: fix_name, lat, lon, type, artcc, country, is_superseded, airac_cycle, geometry.

Source: PostGIS `nav_fixes`.

#### `GET /navigation/airways`

List airways.

Parameters:
- `name` -- exact or prefix (`J60`, `Q*`, `UL*`)
- `type` -- `J` (jet), `V` (victor), `Q`/`T` (RNAV), `L`/`M`/`N`/`U` (intl)
- `contains_fix` -- airways passing through a specific fix
- `artcc` -- airways with segments in this ARTCC
- Paginated

Source: PostGIS `airways` (22K segments).

#### `GET /navigation/airways/{name}`

Full airway with all segments and geometry.

Fields per segment: seq, fix_from, fix_to, course, distance_nm, mea_ft. Plus total_distance_nm and full LineString geometry.

Source: PostGIS `airways` + `airway_segments`.

#### `GET /navigation/airways/{name}/segment?from={fix}&to={fix}`

Partial airway between entry and exit fixes. Returns only the segments between the two fixes with expanded geometry and distance. This is the "expanded airway part" use case -- useful for route analysis when a flight plan references only a portion of an airway.

Source: PostGIS `expand_airway()` function.

#### `GET /navigation/procedures`

List DPs/STARs.

Parameters:
- `airport` -- ICAO code
- `type` -- `DP` or `STAR`
- `name` -- procedure name pattern
- `transition` -- filter by transition fix
- `transition_type` -- `fix`, `runway`, or both
- `source` -- `NASR`, `cifp_base`, `synthetic_base`
- Paginated

Source: PostGIS `nav_procedures` (77K records).

#### `GET /navigation/procedures/{computer_code}`

Full procedure detail by computer code (e.g., `PROC.FIX` for DP fix transition, `PROC.RWxx` for DP runway transition, `FIX.PROC` for STAR fix transition, `RWxx.PROC` for STAR runway transition).

Fields: computer_code, procedure_name, type, airport, transition, transition_type, waypoints array (seq, fix, lat, lon, type), source, airac_cycle, is_superseded, geometry (LineString).

Source: PostGIS `nav_procedures`.

#### `GET /navigation/procedures/airport/{icao}?type=DP`

All procedures for an airport, grouped by type. Each procedure lists its name and available transitions. Provides a discovery view without full waypoint detail.

Source: PostGIS `nav_procedures`.

---

## Domain 4: Airspace

```
/api/swim/v1/reference/airspace/
```

### Endpoints

#### `GET /airspace/boundaries?type=artcc`

List boundaries by type.

Parameters:
- `type` -- `artcc`, `tracon`, `sector` (required)
- `strata` -- for sectors: `low`, `high`, `superhigh`
- `artcc` -- filter sectors/TRACONs by parent ARTCC
- `include` -- `geometry` adds GeoJSON polygon (omitted by default for list views)
- Paginated

Source: PostGIS `artcc_boundaries`, `tracon_boundaries`, `sector_boundaries`.

#### `GET /airspace/boundaries/{type}/{code}`

Single boundary with full detail and geometry.

Fields: code, name, type, hierarchy_type, is_oceanic, floor_ft, ceiling_ft, parent_artcc, geometry (GeoJSON Polygon), area_sq_nm.

Optional: `?simplify=0.01` -- geometry simplification via `ST_Simplify()` (tolerance in degrees) for lightweight polygons.

#### `GET /airspace/at-point?lat=...&lon=...&alt=...`

Point-in-polygon: all boundaries containing a coordinate.

Parameters:
- `lat` -- required
- `lon` -- required
- `alt` -- optional, altitude in feet (for sector floor/ceiling filtering)

Returns: artcc, tracon, and sectors array (filtered by altitude if provided).

Source: PostGIS `get_boundaries_at_point()` (existing function).

#### `GET /airspace/firs?pattern=EG..`

FIR listing with ICAO prefix pattern matching.

Parameters:
- `pattern` -- ICAO prefix pattern (`EG..` matches UK FIRs, `ED..` German, `K*` US)
- `region` -- VATSIM region filter
- `is_oceanic` -- boolean filter
- `include` -- `geometry`

Pattern is translated to SQL `LIKE` or `SIMILAR TO`.

Source: PostGIS `artcc_boundaries` where `hierarchy_type = 'FIR'`.

#### `GET /airspace/sectors?artcc=ZNY&strata=high`

Search/list sectors.

Parameters:
- `artcc` -- parent ARTCC (required or use other filter)
- `strata` -- `low`, `high`, `superhigh`
- `include` -- `geometry`
- Paginated

Source: PostGIS `sector_boundaries`.

---

## Domain 5: Facilities

```
/api/swim/v1/reference/facilities/
```

### Endpoints

#### `GET /facilities/centers`

List all centers/ARTCCs.

Parameters:
- `region` -- VATSIM region filter
- `division` -- VATSIM division filter
- `dcc_region` -- DCC region filter
- `include` -- `geometry`, `summary` (child counts)

#### `GET /facilities/centers/{code}`

Center detail: code, name, fir_code, division, dcc_region, total_tracons, total_sectors, total_airports, geometry.

#### `GET /facilities/centers/{code}/tiers?depth=2`

Tier N adjacency.

Parameters:
- `depth` -- 1 = direct neighbors, 2 = neighbors of neighbors, max 4

Response includes tiered arrays. Tier 1 entries include `shared_boundary_nm`. Tier 2+ entries include `via` (path of intermediate centers).

Works internationally: e.g., `/facilities/centers/EGTT/tiers?depth=1` returns neighboring FIRs.

Source: PostGIS `boundary_adjacency` (if populated) or runtime `ST_Intersects()` between boundary polygons.

#### `GET /facilities/centers/{code}/sectors?strata=high`

All sectors within a center, filterable by strata. With optional geometry.

#### `GET /facilities/tracons`

List all TRACONs.

Parameters:
- `artcc` -- filter by parent ARTCC
- `include` -- `geometry`, `airports`

#### `GET /facilities/tracons/{code}`

TRACON detail: code, name, parent_artcc, airports array, geometry.

#### `GET /facilities/dcc-regions`

DCC region listing with center membership. Returns array of regions, each with code, name, and centers array.

Source: Static reference data.

#### `GET /facilities/lists/{list_name}`

Curated airport lists.

Valid list names: `oep35`, `core30`, `aspm82`, `opsnet45`.

Returns: list metadata (name, description) and airports array (icao, faa_lid, name).

Source: Static reference data (FAA-defined lists, change rarely).

---

## Domain 6: Aircraft

```
/api/swim/v1/reference/aircraft/
```

### Endpoints

#### `GET /aircraft/types`

List/search aircraft types.

Parameters:
- `search` -- free text (manufacturer, model, ICAO code)
- `manufacturer` -- manufacturer filter
- `weight_class` -- `S`, `L`, `H`, `SUPER`
- `wake_category` -- `L`, `M`, `H`, `J`
- `engine_type` -- `jet`, `turboprop`, `piston`
- `family` -- family key filter (e.g., `a320fam`)
- Paginated

Source: ADL `ACD_Data`.

#### `GET /aircraft/types/{icao}`

Full type detail: icao_code, name, manufacturer, weight_class, wake_category, engine_type, engine_count, family, family_name. Plus performance fields from BADA (approach_speed_kt, ceiling_fl, typical_cruise_mach, range_nm) where available.

Source: ADL `ACD_Data` + BADA tables.

#### `GET /aircraft/families`

List all aircraft families with member type codes and counts.

Source: `load/aircraft_families.php` (`$AIRCRAFT_FAMILIES` array).

#### `GET /aircraft/families/{key}`

Family detail with all member types and their full details.

#### `GET /aircraft/performance/{icao}`

Performance envelope from BADA data: climb (typical_rate_fpm), cruise (mach, tas_kt, ceiling_fl, optimal_fl), descent (typical_rate_fpm), fuel (cruise_kg_per_hr), range_nm, source version.

Source: ADL BADA tables (`bada_opf`, `bada_apf`, `bada_ptf`).

---

## Domain 7: Airlines

```
/api/swim/v1/reference/airlines/
```

### Endpoints

#### `GET /airlines`

List all airlines. Parameters: `search`, `country`, paginated.

Source: ADL `airlines` (228 records).

#### `GET /airlines/{icao}`

Airline detail: icao, iata, name, callsign, country, is_virtual.

---

## Domain 8: Routes

```
/api/swim/v1/reference/routes/
```

### Endpoints

#### `GET /routes/popular?origin={icao}&dest={icao}`

Most popular routes between a city pair. Returns ranked route strings with frequency, average flight time, average altitude, last_seen date. Plus total_flights_sampled and sample period.

Source: MySQL `route_history_facts` + `dim_route`.

#### `GET /routes/statistics?origin={icao}&dest={icao}`

Aggregate statistics for a city pair (no individual routes): total flights, average flight time, common altitudes, common aircraft types, busiest hours UTC.

Source: MySQL `route_history_facts` + dimension tables.

---

## Domain 9: AIRAC

```
/api/swim/v1/reference/airac/
```

### Endpoints

#### `GET /airac/current`

Current AIRAC cycle metadata: cycle number, effective_date, expiry_date, next_cycle, next_effective, days_remaining, data_sources.

Source: Computed from known AIRAC schedule + REF metadata.

#### `GET /airac/changelog?cycle={cycle}`

What changed in a specific AIRAC cycle.

Parameters:
- `cycle` -- AIRAC cycle number (default: current)
- `type` -- filter: `fix_added`, `fix_removed`, `procedure_added`, `procedure_modified`, `airway_changed`
- `airport` -- filter changes by airport
- Paginated (changelogs can be large)

Returns: summary counts + paginated changes array.

Source: REF `navdata_changelogs`.

#### `GET /airac/superseded?type=procedure`

List currently superseded items.

Parameters:
- `type` -- `fix`, `procedure`, `airway`
- Paginated

Source: REF/PostGIS tables where `is_superseded = 1`.

---

## Domain 10: Utilities

```
/api/swim/v1/reference/utilities/
```

### Endpoints

#### `GET /utilities/distance?from={lat,lon|code}&to={lat,lon|code}`

Great circle distance. Accepts raw lat,lon pairs or fix/airport codes (auto-resolved).

Returns: from (lat, lon, label), to (lat, lon, label), distance_nm, distance_km, initial_bearing, final_bearing.

Pure computation (Vincenty formula). No database hit unless codes need resolution.

#### `GET /utilities/bearing?from={lat,lon|code}&to={lat,lon|code}`

Bearing between two points. Same input flexibility as distance.

#### `GET /utilities/decode-route?route={route_string}&origin={icao}&dest={icao}`

Convenience proxy to existing `/routes/resolve`. Provides a discoverable URL within the reference library namespace.

---

## Bulk Downloads

```
/api/swim/v1/reference/bulk/
```

### Endpoints

#### `GET /bulk/catalog`

List available bulk download files with metadata: key, name, record count, format, size_bytes, compressed_bytes, url, generated_utc, airac_cycle.

#### `GET /bulk/{dataset}?format=json`

Download a complete dataset. Formats: `json`, `geojson` (geometry datasets), `csv`.

Available datasets: `airports`, `fixes`, `airways`, `procedures`, `boundaries_artcc`, `boundaries_tracon`, `boundaries_sector`, `cdrs`, `aircraft`, `airlines`, `hierarchy`.

### Implementation

Pre-generated static files served from `/data/bulk/`. Nginx serves these directly with gzip compression.

**Generation script**: `scripts/reference/generate_bulk.php`
- Runs post-AIRAC-update (triggered by AIRAC import pipeline)
- Queries all source databases, serializes to JSON/GeoJSON/CSV
- Writes to `/data/bulk/` with metadata file
- Estimated runtime: 2-5 minutes
- Supports CLI (`--force`) and web (`?run=1`) modes

**Caching**: `ETag` and `Last-Modified` headers for client-side caching. Nginx handles `Accept-Encoding: gzip` natively.

### Estimated File Sizes

| Dataset | Records | Raw JSON | Compressed |
|---------|---------|----------|------------|
| airports | 37,527 | ~7 MB | ~2 MB |
| fixes | 392,549 | ~40 MB | ~8 MB |
| airways | 22,662 | ~5 MB | ~2 MB |
| procedures | 77,312 | ~15 MB | ~4 MB |
| boundaries_artcc | 1,003 | ~1.3 MB | ~0.5 MB |
| boundaries_tracon | 1,203 | ~1.2 MB | ~0.5 MB |
| boundaries_sector | 4,085 | ~3.0 MB | ~1.0 MB |
| cdrs | 41,138 | ~12 MB | ~4 MB |
| aircraft | ~500 | <0.1 MB | <0.1 MB |
| airlines | 228 | <0.1 MB | <0.1 MB |
| hierarchy | ~850 | ~0.2 MB | <0.1 MB |
| **Total** | | **~85 MB** | **~22 MB** |

Sizes are estimates based on actual PostGIS row counts and average GeoJSON serialization lengths measured from the production database.

---

## New Static Reference Data

The hierarchy's top 3 levels (Region -> Division -> DCC Region -> Centers) require a small static reference dataset that does not currently exist in any database. Options:

1. **PHP config file** (`load/hierarchy_reference.php`) -- array mapping regions to divisions to DCC regions to center codes. ~100 rows. Simple, versionable in git, no DB dependency.
2. **JSON file** (`assets/data/hierarchy.json`) -- same data in JSON. Consumable by both PHP and the bulk export.
3. **New DB table** in SWIM_API or MySQL -- queryable but adds migration overhead for data that changes very rarely.

Recommendation: **Option 2 (JSON file)**. Editable, versionable, consumable by both the API and the bulk export without database dependency. PHP endpoints load and cache it.

---

## Summary of New Artifacts

| Type | Count | Description |
|------|-------|-------------|
| PHP endpoint files | 11 | One per domain under `api/swim/v1/reference/` |
| Bulk generation script | 1 | `scripts/reference/generate_bulk.php` |
| Static data directory | 1 | `data/bulk/` (generated files) |
| Hierarchy reference JSON | 1 | `assets/data/hierarchy.json` |
| PostGIS functions | 2-3 | Tier adjacency, FIR pattern matching (if not covered by existing) |
| New database tables | 0 | All data exists in source databases |
| New SWIM mirror tables | 0 | Direct query approach |

Estimated total: ~5,000-7,000 lines of PHP + ~500-1,000 lines SQL + ~2,000-3,000 lines OpenAPI YAML.
