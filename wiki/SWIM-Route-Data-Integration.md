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
| [`/routes/resolve`](#1-route-resolution-primary-endpoint) | API Key | GET/POST | Waypoints, distance, ARTCCs for **any route string** (single or batch up to 50) |
| [`/playbook/traversal`](#2-route-traversal) | No | POST | Waypoints, geometry, distance, ARTCCs, TRACONs, sectors for **any route string** |
| [`/routes/cdrs`](#3-coded-departure-routes) | No | GET | Pre-cataloged CDR routes with geometry |
| [`/playbook/plays`](#4-playbook-plays) | No | GET | FAA playbook routes with frozen geometry |
| [`/flights`](#5-flight-data) | API Key | GET | Active flight route strings, distances, SID/STAR, progress |
| [`/positions`](#6-positions) | API Key | GET | GeoJSON Point positions with optional route strings |
| [`/tmi/routes`](#7-tmi-routes) | No | GET | Active TMI reroute visualizations with geometry |

---

## 1. Route Resolution (Primary Endpoint)

**`GET /api/swim/v1/routes/resolve`** — API key required
**`POST /api/swim/v1/routes/resolve`** — API key required (batch up to 50 routes)

The fastest way to resolve route strings into waypoints with coordinates and distances. Uses PostGIS `expand_route()` for airway expansion, oceanic coordinate parsing, fix disambiguation, and distance calculation.

### Single Route (GET)

```bash
curl -H "Authorization: Bearer YOUR_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/routes/resolve?route_string=GAYEL+Q818+WOZEE+KENPA+OBSTR+WYNDE3&origin=KJFK&dest=KORD"
```

### Batch Mode (POST)

```json
{
  "routes": [
    {"route_string": "GAYEL Q818 WOZEE KENPA OBSTR WYNDE3", "origin": "KJFK", "dest": "KORD"},
    {"route_string": "GREKI JUDDS CAM NOVON KENPA OBSTR WYNDE3", "origin": "KJFK", "dest": "KORD"}
  ]
}
```

### Response

```json
{
  "success": true,
  "data": {
    "route_string": "GAYEL Q818 WOZEE KENPA OBSTR WYNDE3",
    "expanded_route": "KJFK GAYEL MSLIN STOMP BUFFY CFB VIEEW KELIE WOZEE KENPA OBSTR KORD",
    "origin": "KJFK",
    "dest": "KORD",
    "total_distance_nm": 758.6,
    "waypoint_count": 12,
    "waypoints": [
      {"seq": 1, "fix": "KJFK", "lat": 40.639928, "lon": -73.778692, "type": "nav_fix"},
      {"seq": 2, "fix": "GAYEL", "lat": 41.406692, "lon": -74.357144, "type": "nav_fix"},
      {"seq": 3, "fix": "MSLIN", "lat": 41.491894, "lon": -74.553967, "type": "airway_Q818"},
      {"seq": 4, "fix": "STOMP", "lat": 41.596328, "lon": -74.796608, "type": "airway_Q818"},
      {"seq": 5, "fix": "BUFFY", "lat": 41.941108, "lon": -75.6126, "type": "airway_Q818"},
      {"seq": 6, "fix": "CFB", "lat": 42.15749, "lon": -76.136472, "type": "airway_Q818"},
      {"seq": 7, "fix": "VIEEW", "lat": 42.439464, "lon": -77.025917, "type": "airway_Q818"},
      {"seq": 8, "fix": "KELIE", "lat": 42.660367, "lon": -77.744736, "type": "airway_Q818"},
      {"seq": 9, "fix": "WOZEE", "lat": 42.933792, "lon": -78.738789, "type": "airway_Q818"},
      {"seq": 10, "fix": "KENPA", "lat": 44.795, "lon": -82.393333, "type": "nav_fix"},
      {"seq": 11, "fix": "OBSTR", "lat": 43.695056, "lon": -85.214869, "type": "nav_fix"},
      {"seq": 12, "fix": "KORD", "lat": 41.9786, "lon": -87.9048, "type": "nav_fix"}
    ],
    "artccs_traversed": ["ZNY", "ZOB", "CZYZ", "ZMP", "ZAU"]
  }
}
```

### Usage Example (Python)

```python
import requests

KEY = "swim_dev_your_key"
BASE = "https://perti.vatcscc.org/api/swim/v1"

# Single route
resp = requests.get(
    f"{BASE}/routes/resolve",
    headers={"Authorization": f"Bearer {KEY}"},
    params={"route_string": "GAYEL Q818 WOZEE KENPA OBSTR WYNDE3", "origin": "KJFK", "dest": "KORD"}
)
result = resp.json()["data"]
print(f"{result['total_distance_nm']} nm, {result['waypoint_count']} waypoints")
print(f"ARTCCs: {', '.join(result['artccs_traversed'])}")

# Batch resolve (up to 50 routes)
resp = requests.post(
    f"{BASE}/routes/resolve",
    headers={"Authorization": f"Bearer {KEY}", "Content-Type": "application/json"},
    json={
        "routes": [
            {"route_string": "GAYEL Q818 WOZEE KENPA OBSTR WYNDE3", "origin": "KJFK", "dest": "KORD"},
            {"route_string": "GREKI JUDDS CAM NOVON KENPA OBSTR WYNDE3", "origin": "KJFK", "dest": "KORD"}
        ]
    }
)
for route in resp.json()["data"]["routes"]:
    print(f"  {route['expanded_route']}: {route['total_distance_nm']} nm, "
          f"ARTCCs: {', '.join(route['artccs_traversed'])}")
# Output:
#   KJFK GAYEL MSLIN ... OBSTR KORD: 758.6 nm, ARTCCs: ZNY, ZOB, CZYZ, ZMP, ZAU
#   KJFK GREKI JUDDS CAM NOVON KENPA OBSTR KORD: 852.8 nm, ARTCCs: ZNY, ZBW, CZYZ, ZMP, ZAU
```

### Usage Example (JavaScript / MapLibre)

```javascript
const KEY = 'your_api_key';
const BASE = 'https://perti.vatcscc.org/api/swim/v1';

// Batch resolve
const resp = await fetch(`${BASE}/routes/resolve`, {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${KEY}`, 'Content-Type': 'application/json' },
  body: JSON.stringify({
    routes: [
      { route_string: 'GAYEL Q818 WOZEE KENPA OBSTR WYNDE3', origin: 'KJFK', dest: 'KORD' }
    ]
  })
});
const { data } = await resp.json();

// Build GeoJSON from waypoints
const coordinates = data.routes[0].waypoints.map(wp => [wp.lon, wp.lat]);
map.addSource('route', {
  type: 'geojson',
  data: { type: 'Feature', geometry: { type: 'LineString', coordinates } }
});
map.addLayer({
  id: 'route-line', type: 'line', source: 'route',
  paint: { 'line-color': '#0080ff', 'line-width': 2 }
});
```

### Key Differences from `/playbook/traversal`

| | `/routes/resolve` | `/playbook/traversal` |
|---|---|---|
| **Auth** | API key required | API key required |
| **Batch limit** | 50 routes | 100 routes |
| **Returns** | Waypoints, distance, ARTCCs | Waypoints, geometry, distance, ARTCCs, TRACONs, sectors |
| **GeoJSON** | Build from waypoints | Native LineString geometry |
| **Best for** | Fast waypoint + distance resolution | Full facility traversal analysis |

Use `/routes/resolve` when you need waypoints and distances quickly. Use `/playbook/traversal` when you need GeoJSON geometry, TRACON traversal, or sector-level detail.

---

## 2. Route Traversal

**`POST /api/swim/v1/playbook/traversal`** — API key required

Submit up to 100 route strings and get back resolved waypoints, geometry, and facility traversals. This is the endpoint to use if you have your own route strings and want VATSWIM to process them.

### Request

```json
{
  "routes": [
    "KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD",
    "KJFK GREKI JUDDS CAM NOVON KENPA OBSTR WYNDE3 KORD"
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
  "success": true,
  "data": {
    "count": 1,
    "results": [
      {
        "route_string": "KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD",
        "artccs": ["ZNY", "ZOB", "CZYZ", "ZMP", "ZAU"],
        "tracons": ["LGA", "JFK", "EWR", "N90", "SWF", "AVP", "BGM", "ELM", "ROC", "BUF", "TOR", "APN", "LAN", "AZO", "C90"],
        "sectors": {
          "low": ["ZNY56", "ZNY36", "ZBW05", "ZNY35", "ZNY51", "ZNY50", "ZOB31", "ZOB33", "CZYZGR", "CZYZES", "CZYZSD", "CZYZXU", "CZYZKF", "CZYZVV", "ZMP01", "ZMP02", "ZAU22", "ZAU27", "ZAU26", "ZAU82", "ZAU62"],
          "high": ["ZNY56", "ZNY42", "ZNY34", "ZOB37", "CZYZCE", "CZYZOV", "CZYZMI", "ZMP46", "ZMP12", "ZAU24", "ZAU25", "ZAU60", "ZAU83"],
          "superhigh": ["ZNY56", "ZNY42", "ZNY34", "ZOB39", "CZYZLU", "CZYZHU", "ZMP46", "ZAU24", "ZAU25", "ZAU60", "ZAU83"]
        },
        "distance_nm": 758.6,
        "waypoints": [
          {"id": "KJFK", "lat": 40.6399281, "lon": -73.7786922, "seq": 1, "type": "nav_fix"},
          {"id": "GAYEL", "lat": 41.4066917, "lon": -74.3571444, "seq": 2, "type": "nav_fix"},
          {"id": "MSLIN", "lat": 41.4918944, "lon": -74.5539667, "seq": 3, "type": "airway_Q818"},
          {"id": "STOMP", "lat": 41.5963278, "lon": -74.7966083, "seq": 4, "type": "airway_Q818"},
          {"id": "BUFFY", "lat": 41.9411083, "lon": -75.6126, "seq": 5, "type": "airway_Q818"},
          {"id": "CFB", "lat": 42.1574904, "lon": -76.1364716, "seq": 6, "type": "airway_Q818"},
          {"id": "VIEEW", "lat": 42.4394639, "lon": -77.0259167, "seq": 7, "type": "airway_Q818"},
          {"id": "KELIE", "lat": 42.6603667, "lon": -77.7447361, "seq": 8, "type": "airway_Q818"},
          {"id": "WOZEE", "lat": 42.9337917, "lon": -78.7387889, "seq": 9, "type": "airway_Q818"},
          {"id": "KENPA", "lat": 44.795, "lon": -82.3933333, "seq": 10, "type": "nav_fix"},
          {"id": "OBSTR", "lat": 43.6950556, "lon": -85.2148694, "seq": 11, "type": "nav_fix"},
          {"id": "KORD", "lat": 41.9786, "lon": -87.9048, "seq": 12, "type": "nav_fix"}
        ],
        "geometry": {
          "type": "LineString",
          "coordinates": [
            [-73.7786922, 40.6399281],
            [-74.3571444, 41.4066917],
            [-74.5539667, 41.4918944],
            [-74.7966083, 41.5963278],
            [-75.6126, 41.9411083],
            [-76.1364716, 42.1574904],
            [-77.0259167, 42.4394639],
            [-77.7447361, 42.6603667],
            [-78.7387889, 42.9337917],
            [-82.3933333, 44.795],
            [-85.2148694, 43.6950556],
            [-87.9048, 41.9786]
          ]
        }
      }
    ]
  }
}
```

### Usage Example (Python)

```python
import requests

resp = requests.post(
    "https://perti.vatcscc.org/api/swim/v1/playbook/traversal",
    headers={"X-API-Key": "YOUR_API_KEY"},
    json={
        "routes": ["KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD"],
        "fields": ["geometry", "distance", "waypoints", "artccs"]
    }
)
result = resp.json()["data"]["results"][0]

# Plot with any GeoJSON-compatible library
geojson = result["geometry"]       # {"type": "LineString", "coordinates": [...]}
distance = result["distance_nm"]   # 758.6
artccs = result["artccs"]          # ["ZNY", "ZOB", "CZYZ", "ZMP", "ZAU"]
waypoints = result["waypoints"]    # [{seq, id, lat, lon, type}, ...]
```

### Usage Example (JavaScript / MapLibre)

```javascript
const resp = await fetch('https://perti.vatcscc.org/api/swim/v1/playbook/traversal', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-API-Key': 'YOUR_API_KEY' },
  body: JSON.stringify({
    routes: ['KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD'],
    fields: ['geometry', 'waypoints', 'distance']
  })
});
const { data: { results } } = await resp.json();

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

## 3. Coded Departure Routes

**`GET /api/swim/v1/routes/cdrs`** — No auth required

Query the FAA's ~41K Coded Departure Routes catalog with optional geometry expansion.

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

## 4. Playbook Plays

**`GET /api/swim/v1/playbook/plays`** — No auth required

FAA playbook routes with pre-computed ("frozen") geometry. ~268K routes organized into ~3,800 plays.

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

## 5. Flight Data

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

### Combining Flights + Route Resolution

To resolve routes for active flights, fetch route strings from `/flights` then batch-resolve them through `/routes/resolve`:

```python
import requests

API_KEY = "your_key"
headers = {"Authorization": f"Bearer {API_KEY}"}

# 1. Get active flights
flights = requests.get(
    "https://perti.vatcscc.org/api/swim/v1/flights?phase=en_route",
    headers=headers
).json()["data"]

# 2. Build route items with origin/dest
route_items = []
for f in flights:
    route_text = f.get("flight_plan", {}).get("route_text")
    if route_text:
        route_items.append({
            "route_string": route_text,
            "origin": f["flight_plan"].get("departure_aerodrome"),
            "dest": f["flight_plan"].get("arrival_aerodrome")
        })

# 3. Batch resolve (50 routes per request)
for i in range(0, len(route_items), 50):
    batch = route_items[i:i+50]
    resolved = requests.post(
        "https://perti.vatcscc.org/api/swim/v1/routes/resolve",
        headers={**headers, "Content-Type": "application/json"},
        json={"routes": batch}
    ).json()["data"]["routes"]

    for route in resolved:
        if "error" in route:
            continue
        # route["waypoints"] -> [{seq, fix, lat, lon, type}, ...]
        # route["total_distance_nm"] -> total distance
        # route["artccs_traversed"] -> ["ZNY", "ZOB", ...]
        pass
```

> **Tip:** Use `/routes/resolve` for fast waypoint + distance resolution. Use `/playbook/traversal` when you need GeoJSON geometry or TRACON/sector-level traversal detail.

---

## 6. Positions

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

## 7. TMI Routes

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
| Public (no key) | — | CDRs, Playbook, TMI Routes, Traversal |
| Developer | 300 req/min | + Flights, Positions, Route Resolution, WebSocket |
| Partner | 3,000 req/min | All endpoints including write |
| System | 30,000 req/min | Core integrations (vNAS, SimTraffic) |

Request an API key at `https://perti.vatcscc.org/swim-keys.php`.

---

## Quick Decision Guide

| You have... | You want... | Use this endpoint |
|-------------|-------------|-------------------|
| A route string | Waypoints + distance + ARTCCs (fast) | `GET /routes/resolve?route_string=X&origin=Y&dest=Z` |
| Multiple route strings | Batch waypoints + distances | `POST /routes/resolve` (up to 50) |
| A route string | Full geometry + TRACONs + sectors | `POST /playbook/traversal` |
| An O/D pair | Pre-built CDR routes with geometry | `GET /routes/cdrs?origin=X&dest=Y&include=geometry` |
| Nothing specific | Browse FAA playbook routes | `GET /playbook/plays?include=geometry` |
| Active flights | Route strings to process | `GET /flights` then `POST /routes/resolve` |
| Active flights | Current positions on a map | `GET /positions` |
| TMI situation | Reroute visualizations | `GET /tmi/routes?format=geojson` |

---

## Rate Limits and Best Practices

- **Batch your traversal calls.** Send up to 100 routes per POST instead of one at a time.
- **Cache geometry.** Route geometry rarely changes for the same route string. Cache by route string hash.
- **Use `fields` selectively.** If you only need geometry, don't request sectors. Each field adds PostGIS computation.
- **Prefer CDRs/Playbook for known routes.** These have pre-computed ("frozen") geometry that returns instantly vs. live PostGIS expansion.
- **Use WebSocket for real-time.** Don't poll `/flights` — subscribe to `flight.created` and `flight.position` events.
