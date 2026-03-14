# SWIM Playbook API Guide

This guide describes how to retrieve playbook route data from the VATSWIM API. The playbook contains **3,829 pre-coordinated route plays** from FAA, DCC (vATCSCC), ECFMP (European), and CADENA (Americas) sources, each containing one or more routes with full facility traversal data.

**Base URL:** `https://perti.vatcscc.org/api/swim/v1`

**Authentication:** Optional for public plays. API key required for `local`/`private` visibility plays.

**Data freshness:** FAA-source plays are synced daily at 06:00Z by `refdata_sync_daemon.php`. DCC/ECFMP/CADENA plays are updated on import.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/playbook/plays` | List plays (paginated) |
| `GET` | `/playbook/plays?id={id}` | Get single play with all routes |
| `GET` | `/playbook/plays?name={name}` | Get single play by name with all routes |

---

## 1. List Plays (Paginated)

Returns play metadata without routes. Use filters and pagination to browse the catalog.

### Request

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?per_page=5&page=1"
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | `1` | Page number (1-1000) |
| `per_page` | int | `50` | Results per page (1-200) |
| `category` | string | — | Filter by category (see [Categories](#categories)) |
| `source` | string | — | Filter by source: `FAA`, `DCC`, `ECFMP`, `CADENA` |
| `artcc` | string | — | Filter by ARTCC in `facilities_involved` (aliases: `fir`, `acc`) |
| `search` | string | — | Search play name or description |
| `status` | string | `active` | Filter by status: `active`, `draft`, `archived` |
| `format` | string | `json` | Response format: `json` or `geojson` |
| `include` | string | — | `geometry` — adds GeoJSON route geometry (single-play mode only) |

### Response

```json
{
  "success": true,
  "data": [
    {
      "play_id": 10899,
      "play_name": "COWBOYS EAST",
      "display_name": null,
      "description": null,
      "category": "Regional Routes",
      "impacted_area": "ZFW",
      "facilities_involved": "ZFW",
      "scenario_type": null,
      "route_format": "standard",
      "source": "FAA",
      "status": "active",
      "airac_cycle": null,
      "route_count": 4,
      "visibility": "public",
      "metadata": {
        "created_by": "refdata_sync",
        "updated_by": null,
        "created_at": "2026-03-14 06:01:31",
        "updated_at": "2026-03-14 06:01:31"
      }
    }
  ],
  "pagination": {
    "total": 3829,
    "page": 1,
    "per_page": 5,
    "total_pages": 766,
    "has_more": true
  }
}
```

**Note:** The `routes` array is **not included** in list mode. Use single-play mode (`?id=` or `?name=`) to get routes.

---

## 2. Get Single Play with Routes

Returns full play metadata plus all routes with scope and traversal data.

### Request (by name)

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?name=COWBOYS+EAST"
```

### Request (by ID)

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?id=10899"
```

### Response

```json
{
  "success": true,
  "data": {
    "play_id": 10899,
    "play_name": "COWBOYS EAST",
    "display_name": null,
    "description": null,
    "category": "Regional Routes",
    "impacted_area": "ZFW",
    "facilities_involved": "ZFW",
    "scenario_type": null,
    "route_format": "standard",
    "source": "FAA",
    "status": "active",
    "airac_cycle": null,
    "route_count": 4,
    "visibility": "public",
    "metadata": {
      "created_by": "refdata_sync",
      "updated_by": null,
      "created_at": "2026-03-14 06:01:31",
      "updated_at": "2026-03-14 06:01:31"
    },
    "routes": [
      {
        "route_id": 800840,
        "route_string": "KDAL TNV IAH LCH UNKN",
        "origin": "KDAL",
        "origin_filter": null,
        "dest": "UNKN",
        "dest_filter": null,
        "scope": {
          "origin_airports": ["KDAL"],
          "origin_tracons": ["D10"],
          "origin_artccs": ["ZFW"],
          "dest_airports": ["UNKN"],
          "dest_tracons": [],
          "dest_artccs": []
        },
        "traversal": {
          "artccs": ["ZFW", "ZHU"],
          "tracons": ["D10", "ACT", "I90", "LCH"],
          "sectors_low": ["ZFW29", "ZFW96", "ZHU83", "ZHU86", "ZHU49", "ZHU36"],
          "sectors_high": ["ZFW46", "ZHU82", "ZHU46", "ZHU36", "ZHU68"],
          "sectors_superhigh": ["ZFW46", "ZHU82", "ZHU46", "ZHU68"]
        },
        "remarks": null,
        "sort_order": 0
      },
      {
        "route_id": 800841,
        "route_string": "KDAL TNV IAH VUH UNKN",
        "origin": "KDAL",
        "origin_filter": null,
        "dest": "UNKN",
        "dest_filter": null,
        "scope": {
          "origin_airports": ["KDAL"],
          "origin_tracons": ["D10"],
          "origin_artccs": ["ZFW"],
          "dest_airports": ["UNKN"],
          "dest_tracons": [],
          "dest_artccs": []
        },
        "traversal": {
          "artccs": ["ZFW", "ZHU"],
          "tracons": ["D10", "ACT", "I90"],
          "sectors_low": ["ZFW29", "ZFW96", "ZHU83", "ZHU86", "ZHU49", "ZHU87", "ZHU36", "ZHU43"],
          "sectors_high": ["ZFW46", "ZHU82", "ZHU46", "ZHU36", "ZHU68", "ZHU43"],
          "sectors_superhigh": ["ZFW46", "ZHU82", "ZHU46", "ZHU68"]
        },
        "remarks": null,
        "sort_order": 1
      },
      {
        "route_id": 800842,
        "route_string": "KDFW TNV IAH LCH UNKN",
        "origin": "KDFW",
        "origin_filter": null,
        "dest": "UNKN",
        "dest_filter": null,
        "scope": {
          "origin_airports": ["KDFW"],
          "origin_tracons": ["D10"],
          "origin_artccs": ["ZFW"],
          "dest_airports": ["UNKN"],
          "dest_tracons": [],
          "dest_artccs": []
        },
        "traversal": {
          "artccs": ["ZFW", "ZHU"],
          "tracons": ["D10", "ACT", "I90", "LCH"],
          "sectors_low": ["ZFW96", "ZHU83", "ZHU86", "ZHU49", "ZHU36"],
          "sectors_high": ["ZFW46", "ZHU82", "ZHU46", "ZHU36", "ZHU68"],
          "sectors_superhigh": ["ZFW46", "ZHU82", "ZHU46", "ZHU68"]
        },
        "remarks": null,
        "sort_order": 2
      },
      {
        "route_id": 800843,
        "route_string": "KDFW TNV IAH VUH UNKN",
        "origin": "KDFW",
        "origin_filter": null,
        "dest": "UNKN",
        "dest_filter": null,
        "scope": {
          "origin_airports": ["KDFW"],
          "origin_tracons": ["D10"],
          "origin_artccs": ["ZFW"],
          "dest_airports": ["UNKN"],
          "dest_tracons": [],
          "dest_artccs": []
        },
        "traversal": {
          "artccs": ["ZFW", "ZHU"],
          "tracons": ["D10", "ACT", "I90"],
          "sectors_low": ["ZFW29", "ZFW96", "ZHU83", "ZHU86", "ZHU49", "ZHU87", "ZHU36", "ZHU43"],
          "sectors_high": ["ZFW46", "ZHU82", "ZHU46", "ZHU36", "ZHU68", "ZHU43"],
          "sectors_superhigh": ["ZFW46", "ZHU82", "ZHU46", "ZHU68"]
        },
        "remarks": null,
        "sort_order": 3
      }
    ]
  }
}
```

---

## 3. GeoJSON Format

Add `format=geojson&include=geometry` to get routes as a GeoJSON FeatureCollection with resolved waypoint coordinates. Only available in single-play mode.

### Request

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?name=COWBOYS+EAST&format=geojson&include=geometry"
```

### Response

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "LineString",
        "coordinates": [
          [-96.8508767, 32.8459447],
          [-96.0582388, 30.2885277],
          [-95.345719, 29.956917],
          [-93.1055694, 30.1415138]
        ]
      },
      "properties": {
        "route_id": 800840,
        "route_string": "KDAL TNV IAH LCH UNKN",
        "origin": "KDAL",
        "dest": "UNKN",
        "distance_nm": 317.6
      }
    },
    {
      "type": "Feature",
      "geometry": {
        "type": "LineString",
        "coordinates": [
          [-96.8508767, 32.8459447],
          [-96.0582388, 30.2885277],
          [-95.3457194, 29.9569166],
          [-94.8677252, 29.2693344]
        ]
      },
      "properties": {
        "route_id": 800841,
        "route_string": "KDAL TNV IAH VUH UNKN",
        "origin": "KDAL",
        "dest": "UNKN",
        "distance_nm": 248.6
      }
    },
    {
      "type": "Feature",
      "geometry": {
        "type": "LineString",
        "coordinates": [
          [-97.0376947, 32.8972331],
          [-96.0582388, 30.2885277],
          [-95.345719, 29.956917],
          [-93.1055694, 30.1415138]
        ]
      },
      "properties": {
        "route_id": 800842,
        "route_string": "KDFW TNV IAH LCH UNKN",
        "origin": "KDFW",
        "dest": "UNKN",
        "distance_nm": 323.3
      }
    },
    {
      "type": "Feature",
      "geometry": {
        "type": "LineString",
        "coordinates": [
          [-97.0376947, 32.8972331],
          [-96.0582388, 30.2885277],
          [-95.3457194, 29.9569166],
          [-94.8677252, 29.2693344]
        ]
      },
      "properties": {
        "route_id": 800843,
        "route_string": "KDFW TNV IAH VUH UNKN",
        "origin": "KDFW",
        "dest": "UNKN",
        "distance_nm": 254.3
      }
    }
  ],
  "metadata": {
    "generated": "2026-03-14T20:11:37+00:00",
    "play_id": 10899,
    "play_name": "COWBOYS EAST",
    "count": 4,
    "source": "perti_playbook"
  }
}
```

Each feature is a route represented as a `LineString` geometry with resolved fix coordinates. The `distance_nm` property gives the total route distance in nautical miles.

**Note:** Without `include=geometry`, GeoJSON mode returns empty features with a hint to add the parameter.

---

## Filtering Examples

### By ARTCC

Find all plays involving New York Center:

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?artcc=ZNY&per_page=5"
```

Returns **2,960 plays** where `facilities_involved` contains `ZNY`. This includes WATRS routes, CTP plays, CADENA PASA, and more. The `fir` and `acc` aliases work identically:

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?fir=ZNY&per_page=5"
```

### By Source

Filter by data source — useful for isolating FAA official routes vs. DCC event plays:

```bash
# FAA Coded Departure Routes and playbook routes (~3,784 plays)
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?source=FAA&per_page=5"

# DCC (vATCSCC) event-specific plays (~45 plays)
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?source=DCC&per_page=5"

# ECFMP European flow management plays
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?source=ECFMP&per_page=5"

# CADENA Americas coordination plays
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?source=CADENA&per_page=5"
```

**Sample DCC results:**

| Play | Category | Routes |
|------|----------|--------|
| `BOS VIA MERIT` | Event | 1 |
| `CARIBBEAN_SNOWBIRD_SOUTH` | Snowbird | 2 |
| `CTPE18` | CTP | 22 |
| `CTPE19` | CTP | 15 |
| `CTPE19NE` | CTP | 10 |

### By Category

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?category=Regional+Routes&per_page=5"
```

**Sample results (800 plays):**

| Play | Source | Facilities | Routes |
|------|--------|------------|--------|
| `CANCUN ARRIVALS` | FAA | ZAB,ZAU,ZBW,ZDC,ZDV,ZFW,ZID,ZKC,ZLA,ZLC,ZME,ZMP,ZNY,ZOA,ZOB,ZSE,ZTL | 61 |
| `COWBOYS EAST` | FAA | ZFW | 4 |
| `COWBOYS WEST` | FAA | ZAB,ZDV,ZFW,ZLA,ZLC,ZOA,ZSE | 24 |
| `DC METRO NATS ESCAPE VIA GOATR` | FAA | ZDC,ZEU | 6 |

### By Search

Search across play names and descriptions:

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?search=WATRS&per_page=5"
```

### Combined Filters

Filters can be combined:

```bash
# FAA plays involving ZNY in Regional Routes category
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?source=FAA&artcc=ZNY&category=Regional+Routes"
```

---

## Data Reference

### Play Fields

| Field | Type | Description |
|-------|------|-------------|
| `play_id` | int | Unique play identifier |
| `play_name` | string | Play name (e.g. `WATRS`, `COWBOYS EAST`) |
| `display_name` | string? | Human-readable name (e.g. `CADENA PASA - Avoid Houston Center`) |
| `description` | string? | Play description text |
| `category` | string? | Play category (see [Categories](#categories)) |
| `impacted_area` | string? | Impacted ARTCC/FIR areas |
| `facilities_involved` | string? | Comma-separated list of involved facilities (ARTCCs/FIRs) |
| `scenario_type` | string? | Scenario classification |
| `route_format` | string | `standard` or `split` (split = separate origin/dest segments) |
| `source` | string | Data source: `FAA`, `DCC`, `ECFMP`, `CADENA` |
| `status` | string | `active`, `draft`, or `archived` |
| `airac_cycle` | string? | AIRAC cycle identifier |
| `route_count` | int | Number of routes in this play |
| `visibility` | string | `public`, `local`, `private_users`, `private_org` |
| `metadata.created_by` | string? | Creator (CID or `refdata_sync`/`import`) |
| `metadata.updated_by` | string? | Last editor CID |
| `metadata.created_at` | datetime | Creation timestamp (UTC) |
| `metadata.updated_at` | datetime | Last update timestamp (UTC) |

### Route Fields

| Field | Type | Description |
|-------|------|-------------|
| `route_id` | int | Unique route identifier |
| `route_string` | string | Full route string (e.g. `KDAL TNV IAH LCH UNKN`) |
| `origin` | string? | Origin airport ICAO (parsed from route) |
| `origin_filter` | string? | Origin filter pattern (for scope-based matching) |
| `dest` | string? | Destination airport ICAO (parsed from route) |
| `dest_filter` | string? | Destination filter pattern |
| `remarks` | string? | TMU annotations |
| `sort_order` | int | Display order within the play |

### Route Scope

The `scope` object describes which airports/facilities this route applies to:

| Field | Type | Description |
|-------|------|-------------|
| `scope.origin_airports` | string[] | Origin airport ICAO codes |
| `scope.origin_tracons` | string[] | Origin TRACON identifiers |
| `scope.origin_artccs` | string[] | Origin ARTCC identifiers |
| `scope.dest_airports` | string[] | Destination airport ICAO codes |
| `scope.dest_tracons` | string[] | Destination TRACON identifiers |
| `scope.dest_artccs` | string[] | Destination ARTCC identifiers |

### Route Traversal

The `traversal` object describes which facilities the route passes through, computed by PostGIS spatial analysis:

| Field | Type | Description |
|-------|------|-------------|
| `traversal.artccs` | string[] | ARTCCs traversed, in order |
| `traversal.tracons` | string[] | TRACONs traversed, in order |
| `traversal.sectors_low` | string[] | Low-altitude sectors traversed |
| `traversal.sectors_high` | string[] | High-altitude sectors traversed |
| `traversal.sectors_superhigh` | string[] | Super-high-altitude sectors traversed |

**Example:** The route `KDAL TNV IAH LCH UNKN` traverses:
- **ARTCCs:** ZFW, ZHU
- **TRACONs:** D10, ACT, I90, LCH
- **Low sectors:** ZFW29, ZFW96, ZHU83, ZHU86, ZHU49, ZHU36
- **High sectors:** ZFW46, ZHU82, ZHU46, ZHU36, ZHU68
- **Superhigh sectors:** ZFW46, ZHU82, ZHU46, ZHU68

### GeoJSON Properties (with `include=geometry`)

When using `format=geojson&include=geometry`, each feature includes:

| Field | Type | Description |
|-------|------|-------------|
| `geometry.type` | string | Always `LineString` |
| `geometry.coordinates` | number[][] | Array of `[longitude, latitude]` pairs |
| `properties.route_id` | int | Route identifier |
| `properties.route_string` | string | Full route string |
| `properties.origin` | string | Origin airport |
| `properties.dest` | string | Destination airport |
| `properties.distance_nm` | float | Total route distance in nautical miles |

### Categories

| Category | Description | Example Play |
|----------|-------------|-------------|
| `Regional Routes` | Multi-facility route plays | `WATRS` (348 routes), `CANCUN ARRIVALS` (61 routes) |
| `CADENA PASA` | Americas pre-coordinated avoidance routes | `PASA AVOID KZHU` (8 routes) |
| `CTP` | Cross The Pond event routes | `CTPE19` (15 routes) |
| `East to West Transcon` | Transcontinental eastbound | Various |
| `West to East Transcon` | Transcontinental westbound | Various |
| `Event` | Specific event plays | `BOS VIA MERIT` |
| `Snowbird` | Seasonal migration routes | `CARIBBEAN_SNOWBIRD_SOUTH` |
| `Airports` | Airport-specific route sets | Various |
| `Airway Closures` | Routes around closed airways | Various |
| `Contingency` | Contingency routing | Various |
| `Special Ops` | Special operations routing | Various |
| `EU_AR` | European arrival routes (ECFMP) | Various |
| `EU_RR` | European reroutes (ECFMP) | Various |
| `Default` | Uncategorized | Various |

### Sources

| Source | Count | Description |
|--------|-------|-------------|
| `FAA` | ~3,784 | FAA Coded Departure Routes and playbook routes (synced daily) |
| `DCC` | ~45 | vATCSCC event-specific plays (manual import) |
| `ECFMP` | Varies | European CFMU flow management plays |
| `CADENA` | Varies | CADENA Americas coordination plays |

---

## Large Play Example: WATRS

The WATRS (West Atlantic Route System) play demonstrates the API's capability with large route sets:

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?name=WATRS"
```

| Field | Value |
|-------|-------|
| Routes | **348** |
| Category | Regional Routes |
| Facilities | ZBW, ZDC, ZJX, ZMA, ZNY, ZTL |
| Response size | ~291 KB |

Sample route from WATRS:

```
KACY -> KATL: KACY SIE B24 LYNUS SILLY HOBOH Y488 STERN Y493 TUBBS DIZNY Y436 DEDDY HOTHH.JJEDI4 KATL
  ARTCCs traversed: ZDC, ZNY, ZJX, ZTL
  TRACONs: ACY, CHS, AGS, MCN, AHN, ATL
  Sectors: 10 low, 11 high, 9 superhigh
```

---

## SDK Examples

### Python

```python
import requests

BASE = "https://perti.vatcscc.org/api/swim/v1"

# List plays for ZNY
resp = requests.get(f"{BASE}/playbook/plays", params={
    "artcc": "ZNY",
    "source": "FAA",
    "per_page": 10
})
plays = resp.json()["data"]
for play in plays:
    print(f"{play['play_name']} ({play['route_count']} routes)")

# Get single play with routes
resp = requests.get(f"{BASE}/playbook/plays", params={"name": "COWBOYS EAST"})
play = resp.json()["data"]
for route in play["routes"]:
    artccs = ", ".join(route["traversal"]["artccs"])
    print(f"  {route['origin']} -> {route['dest']}: {route['route_string']}")
    print(f"    Traverses: {artccs}")

# GeoJSON for mapping
resp = requests.get(f"{BASE}/playbook/plays", params={
    "name": "COWBOYS EAST",
    "format": "geojson",
    "include": "geometry"
})
geojson = resp.json()
for feature in geojson["features"]:
    props = feature["properties"]
    coords = feature["geometry"]["coordinates"]
    print(f"  {props['route_string']} ({props['distance_nm']} nm, {len(coords)} waypoints)")
```

### JavaScript

```javascript
const BASE = "https://perti.vatcscc.org/api/swim/v1";

// List plays
const resp = await fetch(`${BASE}/playbook/plays?artcc=ZNY&per_page=10`);
const { data: plays, pagination } = await resp.json();
console.log(`${pagination.total} plays found for ZNY`);

// Get routes for a specific play
const playResp = await fetch(`${BASE}/playbook/plays?name=WATRS`);
const { data: play } = await playResp.json();
console.log(`${play.play_name}: ${play.routes.length} routes`);

play.routes.forEach(route => {
    console.log(`  ${route.origin} -> ${route.dest}: ${route.traversal.artccs.join(", ")}`);
});

// GeoJSON for MapLibre/Leaflet
const geoResp = await fetch(
    `${BASE}/playbook/plays?name=COWBOYS+EAST&format=geojson&include=geometry`
);
const geojson = await geoResp.json();
// map.addSource('playbook', { type: 'geojson', data: geojson });
```

### curl

```bash
# Browse all plays (page 1)
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?per_page=20"

# Search for CTP plays
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?search=CTP&source=DCC"

# Get WATRS routes as GeoJSON
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?name=WATRS&format=geojson&include=geometry"

# Pipe to jq for pretty-printing
curl -s "https://perti.vatcscc.org/api/swim/v1/playbook/plays?name=COWBOYS+EAST" | jq '.data.routes[] | {origin, dest, route: .route_string, artccs: .traversal.artccs}'
```

---

## Error Responses

| Status | Code | Description |
|--------|------|-------------|
| `404` | `NOT_FOUND` | Play not found (when `id` or `name` specified) |
| `405` | `METHOD_NOT_ALLOWED` | Only GET is supported |
| `503` | `SERVICE_UNAVAILABLE` | SWIM database unavailable |

```json
{
  "error": true,
  "message": "Play not found with name: NONEXISTENT",
  "status": 404,
  "code": "NOT_FOUND"
}
```

---

## See Also

- [[SWIM API]] - Full SWIM API reference
- [[Architecture]] - System architecture
- [OpenAPI Spec](https://perti.vatcscc.org/docs/swim/) - Interactive Swagger UI
- [Playbook Page](https://perti.vatcscc.org/playbook) - Visual playbook browser
