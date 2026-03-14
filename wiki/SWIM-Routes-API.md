# SWIM Routes API

The SWIM Routes API provides access to reference route data: **Coded Departure Routes (CDRs)**, **National Playbook plays**, and **route analysis tools**. CDR and playbook data are served from the isolated `SWIM_API` database (mirrored from internal sources daily). Analysis and throughput endpoints require API key authentication.

**Base URL**: `https://perti.vatcscc.org/api/swim/v1`

**Authentication**: CDR and playbook list/detail endpoints are public. Analysis and throughput endpoints require an API key via `X-API-Key` or `Authorization: Bearer` header.

**CORS**: `Access-Control-Allow-Origin: *` -- callable from any origin.

**Format**: JSON only.

---

## Coded Departure Routes

### GET /api/swim/v1/routes/cdrs

Returns paginated CDRs. Data is served from the isolated `SWIM_API` database (`swim_coded_departure_routes`), with automatic fallback to `VATSIM_REF` if the SWIM mirror is unavailable. CDRs are pre-coordinated reroutes between airport pairs, used for traffic management rerouting and playbook operations.

**Source**: FAA NASR `cdrs.csv` (~41,000 routes), mirrored to SWIM_API daily at 06:00Z

**Parameters:**

| Name | Type | Required | Description | Example |
|------|------|----------|-------------|---------|
| `origin` | string | No | Filter by departure airport (ICAO, case-insensitive) | `KJFK` |
| `dest` | string | No | Filter by arrival airport (ICAO, case-insensitive) | `KORD` |
| `code` | string | No | CDR code prefix match | `JFKORD` |
| `search` | string | No | Free-text search across CDR code and route string | `GREKI` |
| `artcc` | string | No | Filter by departure OR arrival ARTCC (aliases: `fir`, `acc`) | `ZNY` |
| `dep_artcc` | string | No | Filter by departure ARTCC only (aliases: `dep_fir`, `dep_acc`) | `ZNY` |
| `arr_artcc` | string | No | Filter by arrival ARTCC only (aliases: `arr_fir`, `arr_acc`) | `ZAU` |
| `include` | string | No | Include additional data: `geometry` adds GeoJSON route geometry | `geometry` |
| `page` | int | No | Page number (default 1, min 1, max 5000) | `2` |
| `per_page` | int | No | Results per page (default 50, max 200) | `100` |

All filter parameters can be combined. For example, `?origin=KJFK&artcc=ZAU` returns CDRs departing JFK that involve ZAU airspace.

**Example Request:**

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KORD&per_page=2
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "cdr_id": 63801,
      "cdr_code": "JFKORD1K",
      "full_route": "KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD",
      "origin_icao": "KJFK",
      "dest_icao": "KORD",
      "dep_artcc": "ZNY",
      "arr_artcc": "ZAU",
      "direction": null,
      "altitude_min_ft": null,
      "altitude_max_ft": null,
      "is_active": true,
      "source": "cdrs.csv"
    },
    {
      "cdr_id": 63802,
      "cdr_code": "JFKORD1N",
      "full_route": "KJFK GAYEL Q818 WOZEE NOSIK ZOHAN OBSTR WYNDE3 KORD",
      "origin_icao": "KJFK",
      "dest_icao": "KORD",
      "dep_artcc": "ZNY",
      "arr_artcc": "ZAU",
      "direction": null,
      "altitude_min_ft": null,
      "altitude_max_ft": null,
      "is_active": true,
      "source": "cdrs.csv"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 2,
    "total": 23,
    "total_pages": 12,
    "has_more": true
  },
  "metadata": {
    "generated": "2026-03-14T03:39:16+00:00",
    "source": "vatsim_ref.coded_departure_routes"
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `cdr_id` | int | Unique CDR identifier |
| `cdr_code` | string | CDR code (e.g., `JFKORD1K`) |
| `full_route` | string | Full route string including departure, fixes, airways, and arrival |
| `origin_icao` | string | Departure airport ICAO code |
| `dest_icao` | string | Arrival airport ICAO code |
| `dep_artcc` | string | Responsible ARTCC for the departure airport (e.g., `ZNY`) |
| `arr_artcc` | string | Responsible ARTCC for the arrival airport (e.g., `ZAU`) |
| `direction` | string | Route direction indicator (if available) |
| `altitude_min_ft` | int | Minimum altitude in feet (if specified) |
| `altitude_max_ft` | int | Maximum altitude in feet (if specified) |
| `is_active` | bool | Whether the CDR is currently active |
| `source` | string | Data source identifier |

**Error Responses:**

| Code | When |
|------|------|
| 405 | Non-GET method used |
| 503 | VATSIM_REF database unavailable |

---

## Playbook Plays

### GET /api/swim/v1/playbook/plays

Returns National Playbook plays with their associated routes. Each play represents a named traffic management scenario containing one or more reroutes. Data is served from the isolated `SWIM_API` database (`swim_playbook_plays` + `swim_playbook_routes`), with automatic fallback to MySQL if the SWIM mirror is unavailable.

The endpoint operates in two modes:

- **List mode** (default): Returns paginated play metadata. Routes are not included to keep responses compact.
- **Single-play mode** (`?id=<play_id>`): Returns one play with its full route detail, including per-route scope (origin/destination airports, TRACONs, ARTCCs) and facility traversal data.

**Source**: FAA National Playbook + DCC/ECFMP/CANOC plays (~3,800 plays, ~268,000 routes across 9 categories), mirrored to SWIM_API daily at 06:00Z

**Parameters:**

| Name | Type | Required | Description | Example |
|------|------|----------|-------------|---------|
| `id` | int | No | Fetch a single play by ID (enables full route detail) | `9255` |
| `name` | string | No | Fetch a single play by name (alternative to `id`) | `ORD EAST 1` |
| `category` | string | No | FAA category filter | `Airports` |
| `source` | string | No | Data source filter | `FAA` |
| `search` | string | No | Free-text search across play name and routes | `ORD EAST` |
| `artcc` | string | No | Filter by ARTCC involved in any route (aliases: `fir`, `acc`) | `ZAU` |
| `status` | string | No | Play status filter | `active` |
| `format` | string | No | Response format: `summary` (no routes) or `full` | `full` |
| `include` | string | No | Include additional data: `geometry` adds GeoJSON route geometry | `geometry` |
| `page` | int | No | Page number (default 1) | `2` |
| `per_page` | int | No | Results per page (default 50, max 200) | `100` |

### List Mode

Returns play metadata without routes. Use this for browsing, searching, and filtering.

**Example Request:**

```
GET /api/swim/v1/playbook/plays?category=Airports&artcc=ZAU&per_page=2
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "play_id": 8767,
      "play_name": "ATL NO CHPPR",
      "display_name": null,
      "description": null,
      "category": "Airports",
      "impacted_area": "ZAB/ZAU/ZBW/ZDC/ZDV/ZFW/ZID/ZKC/ZLA/ZLC/ZME/ZMP/ZNY/ZOA/ZOB/ZSE/ZTL",
      "facilities_involved": "ZAB,ZAU,ZBW,ZDC,ZDV,ZFW,ZID,ZKC,ZLA,ZLC,ZME,ZMP,ZNY,ZOA,ZOB,ZSE,ZTL",
      "scenario_type": null,
      "route_format": "standard",
      "source": "FAA",
      "status": "active",
      "airac_cycle": null,
      "route_count": 45,
      "visibility": "public",
      "metadata": {
        "created_by": "refdata_sync",
        "updated_by": null,
        "created_at": "2026-03-13 19:59:44",
        "updated_at": "2026-03-13 19:59:44"
      }
    },
    {
      "play_id": 8768,
      "play_name": "ATL NO CHPPR GLAVN",
      "display_name": null,
      "description": null,
      "category": "Airports",
      "impacted_area": "ZAB/ZAU/ZBW/ZDC/ZDV/ZFW/ZID/ZJX/ZKC/ZLA/ZLC/ZMA/ZME/ZMP/ZNY/ZOA/ZOB/ZSE/ZTL",
      "facilities_involved": "ZAB,ZAU,ZBW,ZDC,ZDV,ZFW,ZID,ZJX,ZKC,ZLA,ZLC,ZMA,ZME,ZMP,ZNY,ZOA,ZOB,ZSE,ZTL",
      "scenario_type": null,
      "route_format": "standard",
      "source": "FAA",
      "status": "active",
      "airac_cycle": null,
      "route_count": 46,
      "visibility": "public",
      "metadata": {
        "created_by": "refdata_sync",
        "updated_by": null,
        "created_at": "2026-03-13 19:59:44",
        "updated_at": "2026-03-13 19:59:44"
      }
    }
  ],
  "pagination": {
    "total": 961,
    "page": 1,
    "per_page": 2,
    "total_pages": 481,
    "has_more": true
  },
  "timestamp": "2026-03-14T03:39:20+00:00"
}
```

**List Mode Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `play_id` | int | Unique play identifier (use with `?id=` for full detail) |
| `play_name` | string | Play name (e.g., `ORD EAST 1`, `ATL NO CHPPR`) |
| `display_name` | string | Optional display override |
| `description` | string | Play description (if provided) |
| `category` | string | FAA category (see table below) |
| `impacted_area` | string | Slash-separated list of impacted ARTCCs |
| `facilities_involved` | string | Comma-separated list of all facilities |
| `scenario_type` | string | Scenario classification (if set) |
| `route_format` | string | Format type (`standard`) |
| `source` | string | Data source (`FAA`) |
| `status` | string | Play status (`active`) |
| `airac_cycle` | string | AIRAC cycle identifier (if set) |
| `route_count` | int | Number of routes in this play |
| `visibility` | string | Visibility level (`public`) |
| `metadata` | object | Creation/update timestamps and attribution |

### Single-Play Mode

Fetch a specific play with full route detail using `?id=<play_id>`. Each route includes **scope** (which airports/TRACONs/ARTCCs the route connects) and **traversal** (which airspace sectors the route passes through).

**Example Request:**

```
GET /api/swim/v1/playbook/plays?id=9255
```

**Response** (ORD EAST 1 -- 11 routes, truncated to 3 for brevity):

```json
{
  "success": true,
  "data": {
    "play_id": 9255,
    "play_name": "ORD EAST 1",
    "display_name": null,
    "description": null,
    "category": "Airports",
    "impacted_area": "ZAU/ZBW/ZNY/ZOB/ZUL/ZYZ",
    "facilities_involved": "ZAU,ZBW,ZNY,ZOB,ZUL,ZYZ",
    "scenario_type": null,
    "route_format": "standard",
    "source": "FAA",
    "status": "active",
    "airac_cycle": null,
    "route_count": 11,
    "visibility": "public",
    "metadata": {
      "created_by": "refdata_sync",
      "updated_by": null,
      "created_at": "2026-03-13 19:59:45",
      "updated_at": "2026-03-13 19:59:45"
    },
    "routes": [
      {
        "route_id": 659596,
        "route_string": "CYOW YOW LETAK NOSIK ZOHAN GRB SHIKY.FYTTE7 KORD",
        "origin": "CYOW",
        "origin_filter": null,
        "dest": "KORD",
        "dest_filter": null,
        "scope": {
          "origin_airports": ["CYOW"],
          "origin_tracons": [],
          "origin_artccs": ["ZUL"],
          "dest_airports": ["KORD"],
          "dest_tracons": ["C90"],
          "dest_artccs": ["ZAU"]
        },
        "traversal": {
          "artccs": [],
          "tracons": [],
          "sectors_low": [],
          "sectors_high": [],
          "sectors_superhigh": []
        },
        "remarks": null,
        "sort_order": 0
      },
      {
        "route_id": 659602,
        "route_string": "KJFK JFK.DEEZZ5 CANDR J60 DANNR RAV Q62 WATSN WATSN4 KORD",
        "origin": "KJFK",
        "origin_filter": null,
        "dest": "KORD",
        "dest_filter": null,
        "scope": {
          "origin_airports": ["KJFK"],
          "origin_tracons": ["N90"],
          "origin_artccs": ["ZNY"],
          "dest_airports": ["KORD"],
          "dest_tracons": ["C90"],
          "dest_artccs": ["ZAU"]
        },
        "traversal": {
          "artccs": [],
          "tracons": [],
          "sectors_low": [],
          "sectors_high": [],
          "sectors_superhigh": []
        },
        "remarks": null,
        "sort_order": 6
      },
      {
        "route_id": 659605,
        "route_string": "ZBW NOVON KENLU NOSIK ZOHAN GRB SHIKY.FYTTE7 KORD",
        "origin": null,
        "origin_filter": null,
        "dest": "KORD",
        "dest_filter": null,
        "scope": {
          "origin_airports": [],
          "origin_tracons": [],
          "origin_artccs": ["ZBW"],
          "dest_airports": ["KORD"],
          "dest_tracons": ["C90"],
          "dest_artccs": ["ZAU"]
        },
        "traversal": {
          "artccs": [],
          "tracons": [],
          "sectors_low": [],
          "sectors_high": [],
          "sectors_superhigh": []
        },
        "remarks": null,
        "sort_order": 9
      }
    ]
  },
  "timestamp": "2026-03-14T03:39:48+00:00"
}
```

**Route Fields (single-play mode only):**

| Field | Type | Description |
|-------|------|-------------|
| `route_id` | int | Unique route identifier |
| `route_string` | string | Full route string (departure, fixes, airways, STARs, arrival) |
| `origin` | string | Origin airport ICAO (null for ARTCC-scoped routes like `ZBW ...`) |
| `origin_filter` | string | Origin filter expression (if applicable) |
| `dest` | string | Destination airport ICAO |
| `dest_filter` | string | Destination filter expression (if applicable) |
| `scope` | object | Airspace scope for origin and destination (see below) |
| `traversal` | object | Airspace facilities traversed by the route (see below) |
| `remarks` | string | Operational remarks (if any) |
| `sort_order` | int | Display ordering within the play |

**Scope Object:**

Defines which facilities the route connects. Some routes originate from a specific airport; others apply to all departures from an entire ARTCC.

| Field | Type | Description |
|-------|------|-------------|
| `scope.origin_airports` | array | Origin airport(s) ICAO codes (e.g., `["KJFK"]`) |
| `scope.origin_tracons` | array | Origin TRACON(s) (e.g., `["N90"]`) |
| `scope.origin_artccs` | array | Origin ARTCC(s) (e.g., `["ZNY"]`) |
| `scope.dest_airports` | array | Destination airport(s) ICAO codes |
| `scope.dest_tracons` | array | Destination TRACON(s) (e.g., `["C90"]`) |
| `scope.dest_artccs` | array | Destination ARTCC(s) (e.g., `["ZAU"]`) |

**Traversal Object:**

Lists the airspace sectors and facilities the route passes through en route (between origin and destination scope). Useful for understanding which facilities need to coordinate.

| Field | Type | Description |
|-------|------|-------------|
| `traversal.artccs` | array | ARTCCs traversed (in route order) |
| `traversal.tracons` | array | TRACONs traversed |
| `traversal.sectors_low` | array | Low-altitude sectors traversed |
| `traversal.sectors_high` | array | High-altitude sectors traversed |
| `traversal.sectors_superhigh` | array | Super-high sectors traversed (FL350+) |

### FAA Playbook Categories

| Category | Description |
|----------|-------------|
| Airports | Airport-specific reroutes (e.g., ORD EAST, ATL NO CHPPR) |
| East to West Transcon | Transcontinental eastbound-to-westbound routes |
| West to East Transcon | Transcontinental westbound-to-eastbound routes |
| Regional Routes | Regional reroute plays (e.g., FLORIDA TO NE, LAKE ERIE) |
| Snowbird | Seasonal north-south migration routes |
| Space Ops | Cape Canaveral launch reroutes |
| Special Ops | Special operations reroutes |
| SUA Activity | Reroutes for Special Use Airspace activity |
| Equipment | Equipment-specific routing plays |

---

## Route Analysis

### GET /api/swim/v1/playbook/analysis

Computes facility traversal, distances, and estimated time segments for a playbook route using PostGIS spatial analysis. Proxies to the internal analysis API.

**Auth**: Required (API key with read access)

**Parameters:**

| Name | Type | Required | Description | Example |
|------|------|----------|-------------|---------|
| `route_id` | int | No | Playbook route ID to analyze | `659602` |
| `route_string` | string | No | Route string (alternative to `route_id`) | `KJFK JFK.DEEZZ5 CANDR J60 DANNR RAV Q62 WATSN WATSN4 KORD` |
| `origin` | string | Yes* | Origin airport ICAO (*required when using `route_string`) | `KJFK` |
| `dest` | string | Yes* | Destination airport ICAO (*required when using `route_string`) | `KORD` |
| `climb_kts` | int | No | Climb speed in knots TAS (default 280) | `280` |
| `cruise_kts` | int | No | Cruise speed in knots TAS (default 460) | `460` |
| `descent_kts` | int | No | Descent speed in knots TAS (default 250) | `250` |
| `wind_component_kts` | int | No | Wind component in knots (positive=headwind, default 0) | `-20` |
| `facility_types` | string | No | Comma-separated facility types (default `ARTCC,FIR`) | `ARTCC,FIR,TRACON` |

**Example Request:**

```
GET /api/swim/v1/playbook/analysis?route_id=659602&cruise_kts=480
```

**Response** includes ordered facility traversal with entry/exit distances, time segments, and total route distance.

---

## Route Throughput (CTP Integration)

### GET /api/swim/v1/playbook/throughput

Returns throughput data for playbook routes, used for CTP (Collaborative Traffic Planning) capacity tracking.

**Auth**: Required (API key with read access)

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `play_id` | int | No | Get throughput data for all routes in a play |
| `route_id` | int | No | Get throughput data for a specific route |

### POST /api/swim/v1/playbook/throughput

Ingests per-route throughput metrics from CTP.

**Auth**: Required (API key with write access)

**Request Body:**

```json
{
  "route_id": 659602,
  "play_id": 9255,
  "throughput": {
    "planned_count": 45,
    "slot_count": 42,
    "peak_rate": 20
  }
}
```

---

## Route Geometry (GIS)

Both endpoints support an optional `include=geometry` parameter that adds GeoJSON route geometry, expanded waypoints, and distance calculations via PostGIS.

### CDR Geometry

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KORD&per_page=1&include=geometry
```

When `include=geometry` is specified, each CDR gains a `geometry` object:

```json
{
  "cdr_id": 63801,
  "cdr_code": "JFKORD1K",
  "full_route": "KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD",
  "origin_icao": "KJFK",
  "dest_icao": "KORD",
  "dep_artcc": "ZNY",
  "arr_artcc": "ZAU",
  "geometry": {
    "type": "LineString",
    "coordinates": [[-73.7789, 40.6397], [-73.532, 40.844], ...]
  },
  "waypoints": [
    {"name": "KJFK", "lat": 40.6397, "lon": -73.7789},
    {"name": "GAYEL", "lat": 40.844, "lon": -73.532},
    {"name": "WOZEE", "lat": 41.517, "lon": -79.139}
  ],
  "distance_nm": 642.3,
  "artccs_traversed": ["ZNY", "ZOB", "ZAU"]
}
```

### Playbook Geometry

```
GET /api/swim/v1/playbook/plays?id=9255&include=geometry
```

When `include=geometry` is specified on a single-play request, each route gains the same geometry fields:

```json
{
  "route_id": 659602,
  "route_string": "KJFK JFK.DEEZZ5 CANDR J60 DANNR RAV Q62 WATSN WATSN4 KORD",
  "origin": "KJFK",
  "dest": "KORD",
  "scope": { "..." },
  "traversal": { "..." },
  "geometry": {
    "type": "LineString",
    "coordinates": [[-73.7789, 40.6397], [-74.123, 41.052], ...]
  },
  "waypoints": [
    {"name": "KJFK", "lat": 40.6397, "lon": -73.7789},
    {"name": "CANDR", "lat": 41.052, "lon": -74.123}
  ],
  "distance_nm": 642.3,
  "artccs_traversed": ["ZNY", "ZOB", "ZAU"]
}
```

**Geometry Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `geometry` | GeoJSON | Route path as a GeoJSON `LineString` (WGS84, EPSG:4326) |
| `waypoints` | array | Ordered array of resolved waypoints with `name`, `lat`, `lon` |
| `distance_nm` | float | Total route distance in nautical miles |
| `artccs_traversed` | array | ARTCCs the route passes through (in route order) |

**Notes:**
- Geometry is computed on-the-fly via PostGIS. Requests with `include=geometry` are slower than standard queries.
- Routes that cannot be resolved (unknown fixes) will have `geometry: null`.
- For bulk geometry requests, keep `per_page` low (10-20) to avoid timeouts.

---

## Pagination

Both endpoints return a `pagination` object:

| Field | Type | Description |
|-------|------|-------------|
| `page` | int | Current page number |
| `per_page` | int | Results per page |
| `total` | int | Total matching results |
| `total_pages` | int | Total number of pages |
| `has_more` | bool | Whether more pages exist after this one |

To iterate through all results, increment `page` until `has_more` is `false`.

---

## Data Freshness & Architecture

Both CDR and playbook data are reimported daily at **06:00Z** by the `refdata_sync_daemon.php` daemon. After reimport, Phase 3 of the daemon mirrors the data into the `SWIM_API` database (`swim_coded_departure_routes`, `swim_playbook_plays`, `swim_playbook_routes`). This isolation ensures SWIM API consumers do not put load on internal operational databases.

The authoritative source files update with each AIRAC cycle (every 28 days). Consumers should cache results appropriately -- reference data rarely changes between AIRAC cycles.

The `metadata.generated` field in each response indicates when the response was built (not when the data was last imported). The `metadata.source` field indicates which database served the response (`swim_api.*` or the fallback internal source).

**Sync monitoring**: The `vw_swim_refdata_sync_status` view in SWIM_API shows row counts and minutes since last sync for all three tables.

---

## Common Use Cases

### Find all CDRs between two airports

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KORD
```

Returns 23 CDRs between JFK and O'Hare.

### Find CDRs involving a specific ARTCC

```
GET /api/swim/v1/routes/cdrs?artcc=ZNY
```

Returns CDRs where ZNY is either the departure or arrival ARTCC (9,657 results). Use `dep_artcc` or `arr_artcc` for one-sided filtering.

### Search CDRs by route fix

```
GET /api/swim/v1/routes/cdrs?search=GREKI
```

Returns all CDRs whose code or route string contains "GREKI" (543 results).

### Find playbook plays for a specific airport scenario

```
GET /api/swim/v1/playbook/plays?search=ORD EAST
```

Returns 11 plays matching "ORD EAST" (ORD EAST 1 through ORD EAST 11).

### Get a single play by ID

```
GET /api/swim/v1/playbook/plays?id=9255
```

Returns ORD EAST 1 with all 11 routes, each including scope and traversal objects.

### Get a single play by name

```
GET /api/swim/v1/playbook/plays?name=ORD+EAST+1
```

Same result as `?id=9255`. Name lookup is case-insensitive and also matches the normalized form (spaces replaced with underscores).

### Get all plays involving an ARTCC

```
GET /api/swim/v1/playbook/plays?artcc=ZAU
```

Returns 961 plays that include ZAU in their facility list.

### Get route geometry for map display

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KORD&include=geometry&per_page=5
```

Returns CDRs with GeoJSON LineStrings suitable for rendering on a MapLibre/Leaflet map.

### Download all CDRs (paginated)

```bash
page=1
while true; do
  response=$(curl -s "https://perti.vatcscc.org/api/swim/v1/routes/cdrs?page=$page&per_page=200")
  echo "$response" >> cdrs_all.json
  has_more=$(echo "$response" | jq -r '.pagination.has_more')
  if [ "$has_more" != "true" ]; then break; fi
  page=$((page + 1))
done
```

---

## Related Resources

- **OpenAPI Spec**: [docs/swim/openapi.yaml](../blob/main/docs/swim/openapi.yaml) -- machine-readable API specification
- **SWIM Documentation Portal**: [swim-docs.php](https://perti.vatcscc.org/swim-docs.php)
- **Playbook UI**: [playbook.php](https://perti.vatcscc.org/playbook.php) -- interactive playbook browser
- **Route Plotter**: [route.php](https://perti.vatcscc.org/route.php) -- MapLibre-based route visualization
- [[API Reference]] -- full API index
- [[Playbook]] -- internal playbook feature documentation
