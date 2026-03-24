# Consuming VATSWIM Route Data

Use VATSWIM's pre-processed route data to get resolved waypoints, GeoJSON geometry, distances, and facility traversals without building your own navdata database or route parser.

**API Base URL:** `https://perti.vatcscc.org/api/swim/v1`

---

## What You Get

VATSWIM's route processing pipeline resolves raw route strings like `KJFK GREKI JUDDS CAM J9 MLF ANJLL4 KLAX` into:

| Output | Description |
|--------|-------------|
| **Waypoints** | Ordered lat/lon coordinates for every fix on the route |
| **GeoJSON LineString** | Ready-to-render geometry for MapLibre / Leaflet / Mapbox |
| **Distance** | Calculated route distance in nautical miles |
| **ARTCC Traversal** | Ordered list of ATC centers the route passes through |
| **TRACON/Sector Traversal** | Terminal and sector boundary crossings |

This eliminates the need to maintain your own copy of 384K nav fixes, 17K airways, 90K airway segments, or write disambiguation logic for duplicate fix names across regions.

---

## Endpoint Overview

| Endpoint | Auth | Method | What It Returns |
|----------|------|--------|-----------------|
| [`/playbook/traversal`](#1-route-traversal-primary-endpoint) | No | POST | Waypoints, geometry, distance, ARTCCs, TRACONs, sectors for **any route string** |
| [`/routes/cdrs`](#2-coded-departure-routes) | No | GET | Pre-cataloged CDR routes with geometry |
| [`/playbook/plays`](#3-playbook-plays) | No | GET | FAA playbook routes with frozen geometry |
| [`/flights`](#4-flight-data) | API Key | GET | Active flight route strings, distances, SID/STAR, progress |
| [`/positions`](#5-positions) | API Key | GET | GeoJSON Point positions with optional route strings |
| [`/tmi/routes`](#6-tmi-routes) | No | GET | Active TMI reroute visualizations with geometry |

---

## 1. Route Traversal (Primary Endpoint)

**`POST /api/swim/v1/playbook/traversal`** — No auth required

Submit up to 100 route strings and get back resolved waypoints, geometry, and facility traversals. This is the endpoint to use if you have your own route strings and want VATSWIM to process them.

### Request

```json
{
  "routes": [
    "KJFK GREKI JUDDS CAM BUGSY POLTY J9 MLF ANJLL4 KLAX",
    "KATL FRDMM4 BUURT HOBTT KARIT DCT SPS J86 TBC SUNSS5 KLAS"
  ],
  "fields": ["artccs", "tracons", "sectors", "geometry", "distance", "waypoints"]
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `routes` | string[] | Route strings to process (max 100) |
| `fields` | string[] | Which outputs to include (omit for all) |

**Valid `fields` values:** `artccs`, `tracons`, `sectors`, `geometry`, `distance`, `waypoints`

### Response

```json
{
  "count": 2,
  "results": [
    {
      "route_string": "KJFK GREKI JUDDS CAM BUGSY POLTY J9 MLF ANJLL4 KLAX",
      "artccs": ["ZNY", "ZBW", "CZUL", "CZYZ", "ZMP", "ZLC", "ZLA"],
      "tracons": ["N90", "L30"],
      "sectors": {
        "low": [],
        "high": ["ZNY_66", "ZBW_38", "ZMP_21", "ZLC_15", "ZLA_37"],
        "superhigh": []
      },
      "distance_nm": 2615.6,
      "waypoints": [
        {"seq": 1, "id": "KJFK", "lat": 40.6399281, "lon": -73.7786922, "type": "airport"},
        {"seq": 2, "id": "GREKI", "lat": 41.4800083, "lon": -73.3141611, "type": "nav_fix"},
        {"seq": 3, "id": "JUDDS", "lat": 41.6346722, "lon": -73.1082444, "type": "nav_fix"},
        {"seq": 4, "id": "CAM", "lat": 42.9942888, "lon": -73.3440189, "type": "nav_fix"},
        {"seq": 5, "id": "MLF", "lat": 38.3603556, "lon": -113.0132328, "type": "nav_fix"},
        {"seq": 6, "id": "KLAX", "lat": 33.9425011, "lon": -118.4079971, "type": "airport"}
      ],
      "geometry": {
        "type": "LineString",
        "coordinates": [
          [-73.7786922, 40.6399281],
          [-73.3141611, 41.4800083],
          [-73.1082444, 41.6346722],
          [-73.3440189, 42.9942888],
          [-113.0132328, 38.3603556],
          [-118.4079971, 33.9425011]
        ]
      }
    }
  ]
}
```

### Usage Example (Python)

```python
import requests

resp = requests.post(
    "https://perti.vatcscc.org/api/swim/v1/playbook/traversal",
    json={
        "routes": ["KJFK MERIT J584 RBV J230 WETRO DCT KMCO"],
        "fields": ["geometry", "distance", "waypoints", "artccs"]
    }
)
result = resp.json()["results"][0]

# Plot with any GeoJSON-compatible library
geojson = result["geometry"]       # {"type": "LineString", "coordinates": [...]}
distance = result["distance_nm"]   # 894.2
artccs = result["artccs"]          # ["ZNY", "ZDC", "ZJX"]
waypoints = result["waypoints"]    # [{seq, id, lat, lon, type}, ...]
```

### Usage Example (JavaScript / MapLibre)

```javascript
const resp = await fetch('https://perti.vatcscc.org/api/swim/v1/playbook/traversal', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    routes: ['KJFK GREKI JUDDS CAM J9 MLF ANJLL4 KLAX'],
    fields: ['geometry', 'waypoints', 'distance']
  })
});
const { results } = await resp.json();

// Add to MapLibre
map.addSource('route', {
  type: 'geojson',
  data: { type: 'Feature', geometry: results[0].geometry }
});
map.addLayer({
  id: 'route-line', type: 'line', source: 'route',
  paint: { 'line-color': '#0080ff', 'line-width': 2 }
});

// Add waypoint markers
results[0].waypoints.forEach(wp => {
  new maplibregl.Marker().setLngLat([wp.lon, wp.lat]).addTo(map);
});
```

---

## 2. Coded Departure Routes

**`GET /api/swim/v1/routes/cdrs`** — No auth required

Query the FAA's ~47K Coded Departure Routes catalog with optional geometry expansion.

### Parameters

| Param | Example | Description |
|-------|---------|-------------|
| `origin` | `KJFK` | Filter by departure airport (ICAO) |
| `dest` | `KLAX` | Filter by arrival airport (ICAO) |
| `code` | `JFKLAX1D` | Exact CDR code lookup |
| `dep_artcc` | `ZNY` | Filter by departure ARTCC |
| `arr_artcc` | `ZLA` | Filter by arrival ARTCC |
| `include` | `geometry` | Include resolved geometry + waypoints |

### Real Response (KJFK → KLAX)

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KLAX&include=geometry
```

```json
{
  "success": true,
  "data": [
    {
      "cdr_code": "JFKLAX1D",
      "full_route": "KJFK GREKI JUDDS CAM BUGSY POLTY UBTIX ULAMO STNRD COLDD EXHOS SWTHN DNW FFU J9 MLF WINEN Q73 HAKMN ANJLL4 KLAX",
      "origin_icao": "KJFK",
      "dest_icao": "KLAX",
      "dep_artcc": "ZNY",
      "arr_artcc": "ZLA",
      "geometry": {
        "type": "LineString",
        "coordinates": [
          [-73.7786922, 40.6399281], [-73.3141611, 41.4800083],
          [-73.1082444, 41.6346722], [-73.3440189, 42.9942888],
          [-74.1414111, 44.7226528], [-75.8110333, 45.899175],
          [-77.8333333, 47.0], [-81.516667, 47.9],
          [-87.05, 47.0166667], [-92.2966667, 46.7444444],
          [-102.7087306, 46.6849528], [-105.2145278, 46.2328861],
          [-110.3354744, 43.8282781], [-111.9405315, 40.2748946],
          [-112.1191056, 39.9707833], [-113.0132328, 38.3603556],
          [-113.5, 37.9333333], [-113.7118222, 37.0480278],
          [-113.9009722, 36.6568694], [-114.0365417, 36.4110139],
          [-114.2858778, 36.0854778], [-114.865275, 35.6648667],
          [-115.0797333, 35.5078639], [-118.4079971, 33.9425011]
        ]
      },
      "distance_nm": 2615.6,
      "artccs_traversed": ["ZNY", "ZBW", "CZUL", "CZYZ", "ZMP", "ZLC", "ZLA"]
    },
    {
      "cdr_code": "JFKLAX1K",
      "full_route": "KJFK GAYEL Q818 WOZEE KENPA CESNA ONL EKR HVE PROMT Q88 HAKMN ANJLL4 KLAX",
      "distance_nm": 2297.2,
      "artccs_traversed": ["ZNY", "ZOB", "CZYZ", "ZMP", "ZDV", "ZLC", "ZLA"]
    }
  ]
}
```

---

## 3. Playbook Plays

**`GET /api/swim/v1/playbook/plays`** — No auth required

FAA playbook routes with pre-computed ("frozen") geometry. ~56K routes organized into plays.

### Parameters

| Param | Description |
|-------|-------------|
| `origin` | Origin airport ICAO filter |
| `dest` | Destination airport ICAO filter |
| `artcc` | Impacted ARTCC filter |
| `category` | Play category (e.g., `CADENA PASA`) |
| `include` | `geometry` — include route geometry |
| `format` | `geojson` — return as GeoJSON FeatureCollection |

### Frozen Geometry Format

Each playbook route can have a `route_geometry` envelope containing pre-resolved spatial data:

```json
{
  "geojson": {
    "type": "LineString",
    "coordinates": [[-73.778, 40.639], [-73.314, 41.480], ...]
  },
  "waypoints": [
    {"fix": "KJFK", "lat": 40.6399, "lon": -73.7787},
    {"fix": "GREKI", "lat": 41.4800, "lon": -73.3142},
    {"fix": "CAM", "lat": 42.9943, "lon": -73.3440}
  ],
  "distance_nm": 847.3
}
```

---

## 4. Flight Data

**`GET /api/swim/v1/flights`** — Requires `X-API-Key` header

Returns active flight plans with route text, SID/STAR identification, distances, and progress. Does **not** include geometry or resolved waypoints (use the traversal endpoint for that).

### Route-Related Fields

Real example — BAW545 (London Heathrow → Hamburg, queried from production ADL):

```json
{
  "flight_plan": {
    "route_text": "BPK Q295 SOMVA DCT MAVAS DCT VALAM DCT GASTU DCT OSTOR T904 RIBSO RIBSO3P",
    "departure_aerodrome": "EGLL",
    "arrival_aerodrome": "EDDH",
    "alternate_aerodrome": null,
    "departure_airspace": null,
    "arrival_airspace": null,
    "sid": null,
    "star": "RIBSO3P",
    "departure_point": null,
    "arrival_point": "RIBSO"
  },
  "progress": {
    "great_circle_distance": 367.0,
    "total_flight_distance": 367.0,
    "distance_to_destination": null,
    "distance_flown": null,
    "percent_complete": null
  }
}
```

### Combining Flights + Traversal

To render routes for active flights, fetch route strings from `/flights` then batch-process them through `/playbook/traversal`:

```python
import requests

API_KEY = "your_key"
headers = {"X-API-Key": API_KEY}

# 1. Get active flights
flights = requests.get(
    "https://perti.vatcscc.org/api/swim/v1/flights?phase=en_route",
    headers=headers
).json()["data"]

# 2. Extract route strings
route_strings = [f["flight_plan"]["route_text"] for f in flights if f["flight_plan"]["route_text"]]

# 3. Batch resolve geometry (100 routes per request)
for i in range(0, len(route_strings), 100):
    batch = route_strings[i:i+100]
    resolved = requests.post(
        "https://perti.vatcscc.org/api/swim/v1/playbook/traversal",
        json={"routes": batch, "fields": ["geometry", "distance"]}
    ).json()["results"]

    for route in resolved:
        # route["geometry"] -> GeoJSON LineString
        # route["distance_nm"] -> total distance
        pass
```

---

## 5. Positions

**`GET /api/swim/v1/positions`** — Requires `X-API-Key` header

Returns a GeoJSON FeatureCollection of aircraft positions as Point features. Add `include_route=true` to include the raw route string in each feature's properties.

### Real Response

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "Point",
        "coordinates": [7.20709, 43.66047, 22]
      },
      "properties": {
        "callsign": "EJU634N",
        "aircraft": "A320",
        "departure": "LFPO",
        "destination": "LFMN",
        "phase": "arrived",
        "current_artcc": "LFMM-E",
        "altitude": 22,
        "groundspeed": 0,
        "distance_remaining_nm": 0.4,
        "gcd_nm": 364.6,
        "pct_complete": 99.9,
        "route_string": "LATRA DCT LAMUT DCT UTUVA DCT LERGA DCT LIQID DCT LATAM DCT TUNUR DCT NISAR"
      }
    }
  ]
}
```

---

## 6. TMI Routes

**`GET /api/swim/v1/tmi/routes`** — No auth required

Active Traffic Management Initiative route visualizations with stored GeoJSON geometry and styling.

### Parameters

| Param | Description |
|-------|-------------|
| `filter` | `active` (default), `all`, `future`, `past` |
| `format` | `geojson` — return as FeatureCollection |
| `facility` | Filter by ARTCC code |

### Response Fields

Each route includes GeoJSON geometry and visualization styling. TMI routes are only present when a Traffic Management Initiative is actively publishing reroutes.

```json
{
  "route_string": "...",
  "route_geojson": {"type": "LineString", "coordinates": [[lon, lat], ...]},
  "origin_airports": ["KJFK", "KEWR"],
  "dest_airports": ["KLAS"],
  "facilities": ["ZNY", "ZOB", "ZDV", "ZLC", "ZLA"],
  "color": "#FF6600",
  "line_weight": 3,
  "line_style": "solid",
  "notes": "..."
}
```

---

## Output Formats

All endpoints support multiple output formats via the `format` query parameter:

| Format | Content-Type | Description |
|--------|-------------|-------------|
| `json` (default) | `application/json` | Standard JSON |
| `geojson` | `application/geo+json` | GeoJSON FeatureCollection |
| `csv` | `text/csv` | Comma-separated values |
| `kml` | `application/vnd.google-earth.kml+xml` | Google Earth format |
| `xml` | `application/xml` | XML |
| `ndjson` | `application/x-ndjson` | Newline-delimited JSON (streaming) |

---

## WebSocket (Real-Time)

**`wss://perti.vatcscc.org/api/swim/v1/ws`** — Requires API key in connection params

Subscribe to real-time flight events. Position events include current coordinates but not route geometry. Use `flight.created` events to capture new route strings, then batch-resolve via the traversal endpoint.

```javascript
const ws = new WebSocket('wss://perti.vatcscc.org/api/swim/v1/ws?key=YOUR_KEY');

ws.onopen = () => {
  ws.send(JSON.stringify({
    type: 'subscribe',
    channels: ['flight.created', 'flight.position'],
    filters: { artccs: ['ZNY', 'ZDC'] }
  }));
};

ws.onmessage = (event) => {
  const msg = JSON.parse(event.data);
  if (msg.channel === 'flight.created') {
    // msg.data.route contains the raw route string
    // Queue it for traversal resolution
  }
};
```

---

## Authentication

| Tier | Rate Limit | Access |
|------|-----------|--------|
| Public (no key) | 60 req/min | CDRs, Playbook, TMI Routes, Traversal |
| Developer | 120 req/min | + Flights, Positions, WebSocket |
| Partner | 300 req/min | All endpoints |

Request an API key at `https://perti.vatcscc.org/swim-keys.php`.

---

## Quick Decision Guide

| You have... | You want... | Use this endpoint |
|-------------|-------------|-------------------|
| A route string | Geometry + waypoints + distance | `POST /playbook/traversal` |
| An O/D pair | Pre-built CDR routes with geometry | `GET /routes/cdrs?origin=X&dest=Y&include=geometry` |
| Nothing specific | Browse FAA playbook routes | `GET /playbook/plays?include=geometry` |
| Active flights | Route strings to process | `GET /flights` then `POST /playbook/traversal` |
| Active flights | Current positions on a map | `GET /positions` |
| TMI situation | Reroute visualizations | `GET /tmi/routes?format=geojson` |

---

## Rate Limits and Best Practices

- **Batch your traversal calls.** Send up to 100 routes per POST instead of one at a time.
- **Cache geometry.** Route geometry rarely changes for the same route string. Cache by route string hash.
- **Use `fields` selectively.** If you only need geometry, don't request sectors. Each field adds PostGIS computation.
- **Prefer CDRs/Playbook for known routes.** These have pre-computed ("frozen") geometry that returns instantly vs. live PostGIS expansion.
- **Use WebSocket for real-time.** Don't poll `/flights` — subscribe to `flight.created` and `flight.position` events.
