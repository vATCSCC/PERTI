# GIS Boundaries API

PostGIS-powered spatial queries for route expansion, boundary analysis, trajectory crossing detection, and geographic lookups.

---

## Overview

The GIS API provides spatial analysis capabilities using PostgreSQL/PostGIS. It enables:

- **Route Expansion**: Parse route strings (e.g., "KDFW BNA KMCO") to waypoint coordinates
- **Boundary Detection**: Identify ARTCCs, TRACONs, and sectors traversed by routes
- **Trajectory Crossings**: Detect precise ARTCC/sector boundary crossings along flight paths
- **Boundary Adjacency**: Query boundary neighbor relationships and proximity tiers
- **Point-in-Polygon**: Determine which boundaries contain a given coordinate
- **GeoJSON Generation**: Convert routes to GeoJSON for mapping

**Base URL**: `/api/gis/boundaries.php`

**Version**: 2.0.0

---

## Quick Reference

### Route Expansion

| Action | Method | Description |
|--------|--------|-------------|
| `expand_route` | GET | Expand route string to waypoints and ARTCCs |
| `expand_routes` | GET/POST | Batch expand multiple routes |
| `expand_playbook` | GET | Expand playbook route code |
| `analyze_route` | GET/POST | Full analysis with sectors |
| `resolve_waypoint` | GET | Resolve fix to coordinates |
| `routes_geojson` | GET/POST | Convert routes to GeoJSON |

### Boundary Queries

| Action | Method | Description |
|--------|--------|-------------|
| `at_point` | GET | Point-in-polygon lookup |
| `route_artccs` | GET | ARTCCs from waypoints |
| `route_tracons` | GET | TRACONs from waypoints |
| `route_full` | GET | All boundaries from waypoints |
| `analyze_tmi_route` | POST | TMI route proposal analysis |
| `airport_artcc` | GET | Get ARTCC for airport |
| `artcc_airports` | GET | Get airports in ARTCC |

### Trajectory Crossings

| Action | Method | Description |
|--------|--------|-------------|
| `trajectory_crossings` | GET | ARTCC boundary crossings along trajectory |
| `sector_crossings` | GET | Sector boundary crossings along trajectory |
| `all_crossings` | GET | All boundary crossings (ARTCC + sectors) |
| `artccs_traversed` | GET | Simple list of ARTCCs crossed |
| `crossing_etas` | GET | ETAs for upcoming boundary crossings |

### Boundary Adjacency Network

| Action | Method | Description |
|--------|--------|-------------|
| `compute_adjacencies` | GET | Compute all boundary adjacencies |
| `boundary_neighbors` | GET | Get neighbors of a boundary |
| `adjacency_stats` | GET | Adjacency network statistics |
| `adjacency_edges` | GET | Export adjacency as edge list |
| `boundary_path` | GET | Find path between boundaries |
| `artcc_adjacency_map` | GET | ARTCC-to-ARTCC adjacency map |
| `sector_adjacency` | GET | Sector adjacency within ARTCC |

### Proximity Tiers

| Action | Method | Description |
|--------|--------|-------------|
| `proximity_tiers` | GET | Boundaries within N tiers |
| `proximity_distance` | GET | Tier distance between boundaries |
| `boundaries_at_tier` | GET | Boundaries at specific tier |
| `proximity_summary` | GET | Count summary per tier |
| `validate_tiers` | GET | Validate GIS vs ADL tier mappings |

### Service/Diagnostics

| Action | Method | Description |
|--------|--------|-------------|
| `health` | GET | Service health check |
| `diag` | GET | Diagnostic/debugging information |

---

## Route Expansion Endpoints

### Expand Route

Expand a route string to waypoint coordinates with ARTCC determination.

```
GET /api/gis/boundaries.php?action=expand_route&route={route_string}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `route` | string | Yes | Route string (e.g., "KDFW BNA KMCO") |

**Example Request**

```
GET /api/gis/boundaries.php?action=expand_route&route=KDFW BNA KMCO
```

**Example Response**

```json
{
  "success": true,
  "route": "KDFW BNA KMCO",
  "artccs": ["ZFW", "ZME", "ZJX"],
  "artccs_display": "ZFW/ZME/ZJX",
  "waypoints": [
    {"fix_id": "KDFW", "lat": 32.897, "lon": -97.038, "source": "airport"},
    {"fix_id": "BNA", "lat": 36.124, "lon": -86.678, "source": "navaid"},
    {"fix_id": "KMCO", "lat": 28.429, "lon": -81.309, "source": "airport"}
  ],
  "waypoint_count": 3,
  "distance_nm": 892.4,
  "geojson": {
    "type": "LineString",
    "coordinates": [[-97.038, 32.897], [-86.678, 36.124], [-81.309, 28.429]]
  }
}
```

**Route String Formats**

| Format | Example | Description |
|--------|---------|-------------|
| Direct | `KDFW BNA KMCO` | Space-separated fixes/airports |
| Airway | `KDFW J4 ABI KABQ` | Includes J/Q airway identifiers |
| Mixed | `KDFW..BNA..KMCO` | Dot-separated (normalized) |

---

### Expand Routes (Batch)

Expand multiple route strings in a single request.

```
GET /api/gis/boundaries.php?action=expand_routes&routes={json_array}
POST /api/gis/boundaries.php?action=expand_routes
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `routes` | JSON array | Yes | Array of route strings |

**Example Request (POST)**

```json
{
  "routes": [
    "KDFW BNA KMCO",
    "KJFK CYYZ",
    "KLAX J80 KPHX"
  ]
}
```

**Example Response**

```json
{
  "success": true,
  "routes": [
    {
      "route": "KDFW BNA KMCO",
      "artccs": ["ZFW", "ZME", "ZJX"],
      "waypoints": [...],
      "distance_nm": 892.4
    },
    {
      "route": "KJFK CYYZ",
      "artccs": ["ZNY", "ZOB"],
      "waypoints": [...],
      "distance_nm": 365.2
    },
    {
      "route": "KLAX J80 KPHX",
      "artccs": ["ZLA"],
      "waypoints": [...],
      "distance_nm": 298.7
    }
  ],
  "count": 3,
  "artccs_all": ["ZFW", "ZME", "ZJX", "ZNY", "ZOB", "ZLA"]
}
```

---

### Expand Playbook Route

Expand a CDR/Playbook route code to its full route definition.

```
GET /api/gis/boundaries.php?action=expand_playbook&code={pb_code}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `code` | string | Yes | Playbook code (e.g., "PB.ROD.KSAN.KJFK") |

**Example Request**

```
GET /api/gis/boundaries.php?action=expand_playbook&code=PB.ROD.KSAN.KJFK
```

**Example Response**

```json
{
  "success": true,
  "pb_code": "PB.ROD.KSAN.KJFK",
  "route_string": "KSAN TRM J80 ABQ TUCSON J4 KJFK",
  "artccs": ["ZLA", "ZAB", "ZNY"],
  "artccs_display": "ZLA/ZAB/ZNY",
  "waypoints": [...],
  "waypoint_count": 12,
  "geojson": {...}
}
```

---

### Analyze Route (Full)

Comprehensive route analysis including ARTCCs, sectors, and TRACONs.

```
GET /api/gis/boundaries.php?action=analyze_route&route={route}&altitude={alt}
POST /api/gis/boundaries.php?action=analyze_route
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `route` | string | Yes | - | Route string |
| `altitude` | int | No | 35000 | Cruise altitude (feet) |

**Example Request**

```
GET /api/gis/boundaries.php?action=analyze_route&route=KDFW BNA KMCO&altitude=35000
```

**Example Response**

```json
{
  "success": true,
  "route": "KDFW BNA KMCO",
  "artccs": ["ZFW", "ZME", "ZJX"],
  "sectors_low": [],
  "sectors_high": ["ZFW45", "ZME22", "ZJX18"],
  "sectors_superhi": ["ZFW91"],
  "tracons": ["D10", "BNA", "MCO"],
  "waypoint_count": 3,
  "distance_nm": 892.4,
  "geojson": {...},
  "altitude": 35000
}
```

**Altitude Tiers**

| Tier | Altitude Range | Sector Table |
|------|----------------|--------------|
| Low | 0 - 17,999 ft | `sectors_low` |
| High | 18,000 - 40,999 ft | `sectors_high` |
| SuperHigh | 41,000+ ft | `sectors_superhigh` |

---

### Resolve Waypoint

Resolve a fix, airport, or ARTCC identifier to coordinates.

```
GET /api/gis/boundaries.php?action=resolve_waypoint&fix={identifier}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `fix` | string | Yes | Fix identifier (e.g., "BNA", "KDFW", "ZFW") |

**Example Response**

```json
{
  "success": true,
  "fix": "BNA",
  "lat": 36.124,
  "lon": -86.678,
  "source": "navaid"
}
```

**Resolution Order**

The API resolves identifiers in this priority:
1. Airports (ICAO codes)
2. Nav fixes (VORs, NDBs, waypoints)
3. ARTCC center points

---

### Routes to GeoJSON

Convert route strings to a GeoJSON FeatureCollection for mapping.

```
GET /api/gis/boundaries.php?action=routes_geojson&routes={json_array}
POST /api/gis/boundaries.php?action=routes_geojson
```

**Example Request (POST)**

```json
{
  "routes": ["KDFW BNA KMCO", "KJFK KLAX"]
}
```

**Example Response**

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "properties": {
        "route": "KDFW BNA KMCO",
        "artccs": "ZFW/ZME/ZJX",
        "distance_nm": 892.4
      },
      "geometry": {
        "type": "LineString",
        "coordinates": [[-97.038, 32.897], [-86.678, 36.124], [-81.309, 28.429]]
      }
    },
    {
      "type": "Feature",
      "properties": {
        "route": "KJFK KLAX",
        "artccs": "ZNY/ZID/ZKC/ZDV/ZLA",
        "distance_nm": 2145.8
      },
      "geometry": {...}
    }
  ]
}
```

---

## Boundary Query Endpoints

### Boundaries at Point

Get all boundaries containing a specific coordinate.

```
GET /api/gis/boundaries.php?action=at_point&lat={lat}&lon={lon}&alt={alt}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `lat` | float | Yes | Latitude |
| `lon` | float | Yes | Longitude |
| `alt` | int | No | Altitude in feet |

**Example Request**

```
GET /api/gis/boundaries.php?action=at_point&lat=32.897&lon=-97.038&alt=35000
```

**Example Response**

```json
{
  "success": true,
  "data": {
    "artcc": "ZFW",
    "tracon": "D10",
    "sector_high": "ZFW45"
  },
  "query": {
    "lat": 32.897,
    "lon": -97.038,
    "alt": 35000
  }
}
```

---

### Route ARTCCs (from Waypoints)

Get ARTCCs traversed by a series of waypoint coordinates.

```
GET /api/gis/boundaries.php?action=route_artccs&waypoints={json_array}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `waypoints` | JSON | Yes | Array of coordinate objects |

**Waypoint Formats Supported**

```json
// Format 1: {lat, lon}
[{"lat": 32.897, "lon": -97.038}, {"lat": 28.429, "lon": -81.309}]

// Format 2: {lat, lng}
[{"lat": 32.897, "lng": -97.038}, {"lat": 28.429, "lng": -81.309}]

// Format 3: {latitude, longitude}
[{"latitude": 32.897, "longitude": -97.038}]

// Format 4: GeoJSON [lon, lat]
[[-97.038, 32.897], [-81.309, 28.429]]
```

**Example Response**

```json
{
  "success": true,
  "artccs": [
    {"artcc_code": "ZFW", "artcc_name": "Fort Worth Center"},
    {"artcc_code": "ZME", "artcc_name": "Memphis Center"},
    {"artcc_code": "ZJX", "artcc_name": "Jacksonville Center"}
  ],
  "artcc_codes": ["ZFW", "ZME", "ZJX"],
  "count": 3,
  "waypoint_count": 2
}
```

---

### Route TRACONs (from Waypoints)

Get TRACONs traversed by a series of waypoint coordinates.

```
GET /api/gis/boundaries.php?action=route_tracons&waypoints={json_array}
```

**Example Response**

```json
{
  "success": true,
  "tracons": [
    {"tracon_code": "D10", "tracon_name": "Dallas-Fort Worth TRACON"},
    {"tracon_code": "MCO", "tracon_name": "Orlando TRACON"}
  ],
  "tracon_codes": ["D10", "MCO"],
  "count": 2
}
```

---

### Route Full (All Boundaries)

Get all boundary types (ARTCCs, TRACONs, sectors) from waypoint coordinates.

```
GET /api/gis/boundaries.php?action=route_full&waypoints={json}&altitude={alt}&sectors={0|1}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `waypoints` | JSON | Yes | - | Array of coordinates |
| `altitude` | int | No | 35000 | Altitude for sector filtering |
| `sectors` | 0/1 | No | 1 | Include sector breakdown |

**Example Response**

```json
{
  "success": true,
  "boundaries": {
    "artccs": [{"code": "ZFW"}, {"code": "ZME"}, {"code": "ZJX"}],
    "tracons": [{"code": "D10"}, {"code": "MCO"}],
    "sectors_low": [],
    "sectors_high": [{"code": "ZFW45"}, {"code": "ZME22"}],
    "sectors_superhigh": []
  },
  "summary": {
    "artcc_count": 3,
    "artcc_codes": ["ZFW", "ZME", "ZJX"],
    "tracon_count": 2,
    "sector_low_count": 0,
    "sector_high_count": 2,
    "sector_superhigh_count": 0
  },
  "query": {
    "altitude": 35000,
    "include_sectors": true,
    "waypoint_count": 3
  }
}
```

---

## TMI Analysis Endpoint

### Analyze TMI Route

Analyze a route for Traffic Management Initiative coordination.

```
POST /api/gis/boundaries.php?action=analyze_tmi_route
```

**Request Body**

```json
{
  "route_geojson": {
    "type": "LineString",
    "coordinates": [[-97.038, 32.897], [-86.678, 36.124], [-81.309, 28.429]]
  },
  "origin": "KDFW",
  "destination": "KMCO",
  "altitude": 35000
}
```

**Example Response**

```json
{
  "success": true,
  "analysis": {
    "facilities_traversed": ["ZFW", "ZME", "ZJX"],
    "coordination_required": ["ZME"],
    "departure_artcc": "ZFW",
    "arrival_artcc": "ZJX",
    "distance_nm": 892.4
  },
  "facilities_string": "ZFW/ZME/ZJX",
  "query": {
    "origin": "KDFW",
    "destination": "KMCO",
    "altitude": 35000
  }
}
```

---

## Airport Lookup Endpoints

### Get Airport ARTCC

Get the ARTCC that contains an airport.

```
GET /api/gis/boundaries.php?action=airport_artcc&icao={code}
```

**Example**

```
GET /api/gis/boundaries.php?action=airport_artcc&icao=KDFW
```

**Response**

```json
{
  "success": true,
  "icao": "KDFW",
  "artcc": "ZFW"
}
```

---

### Get Airports in ARTCC

Get all airports within an ARTCC boundary.

```
GET /api/gis/boundaries.php?action=artcc_airports&artcc={code}
```

**Example**

```
GET /api/gis/boundaries.php?action=artcc_airports&artcc=ZFW
```

**Response**

```json
{
  "success": true,
  "artcc": "ZFW",
  "airports": [
    {"icao": "KDFW", "name": "Dallas/Fort Worth International"},
    {"icao": "KDAL", "name": "Dallas Love Field"},
    {"icao": "KIAH", "name": "George Bush Intercontinental"},
    {"icao": "KHOU", "name": "William P. Hobby"}
  ],
  "count": 47
}
```

---

## Trajectory Crossing Endpoints

These endpoints analyze flight trajectories to detect precise boundary crossings.

### Trajectory ARTCC Crossings

Get precise ARTCC boundary crossing points along a trajectory.

```
GET /api/gis/boundaries.php?action=trajectory_crossings&waypoints={json}
GET /api/gis/boundaries.php?action=artcc_crossings&waypoints={json}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `waypoints` | JSON | Yes | Array of coordinate objects |
| `debug` | 0/1 | No | Include debug information |

**Example Request**

```
GET /api/gis/boundaries.php?action=trajectory_crossings&waypoints=[{"lat":32.897,"lon":-97.038},{"lat":36.124,"lon":-86.678},{"lat":28.429,"lon":-81.309}]
```

**Example Response**

```json
{
  "success": true,
  "crossings": [
    {
      "from_artcc": "ZFW",
      "to_artcc": "ZME",
      "crossing_lat": 34.512,
      "crossing_lon": -91.234,
      "segment_index": 0
    },
    {
      "from_artcc": "ZME",
      "to_artcc": "ZJX",
      "crossing_lat": 32.456,
      "crossing_lon": -84.567,
      "segment_index": 1
    }
  ],
  "count": 2,
  "waypoint_count": 3
}
```

---

### Sector Crossings

Get sector boundary crossings along a trajectory.

```
GET /api/gis/boundaries.php?action=sector_crossings&waypoints={json}&type={sector_type}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `waypoints` | JSON | Yes | - | Array of coordinate objects |
| `type` | string | No | HIGH | Sector type: LOW, HIGH, or SUPERHIGH |

**Example Response**

```json
{
  "success": true,
  "crossings": [
    {
      "from_sector": "ZFW45",
      "to_sector": "ZFW46",
      "crossing_lat": 33.456,
      "crossing_lon": -96.123
    }
  ],
  "sector_type": "HIGH",
  "count": 1,
  "waypoint_count": 3
}
```

---

### All Crossings (ARTCC + Sectors)

Get all boundary crossings (ARTCC and sectors) along a trajectory.

```
GET /api/gis/boundaries.php?action=all_crossings&waypoints={json}
```

**Example Response**

```json
{
  "success": true,
  "crossings": [
    {
      "boundary_type": "ARTCC",
      "from": "ZFW",
      "to": "ZME",
      "crossing_lat": 34.512,
      "crossing_lon": -91.234
    },
    {
      "boundary_type": "SECTOR_HIGH",
      "from": "ZFW45",
      "to": "ZME22",
      "crossing_lat": 34.512,
      "crossing_lon": -91.234
    }
  ],
  "count": 2,
  "waypoint_count": 3
}
```

---

### ARTCCs Traversed (Simple List)

Get a simple ordered list of ARTCCs crossed by a trajectory.

```
GET /api/gis/boundaries.php?action=artccs_traversed&waypoints={json}
```

**Example Response**

```json
{
  "success": true,
  "artccs": ["ZFW", "ZME", "ZJX"],
  "artccs_display": "ZFW/ZME/ZJX",
  "count": 3,
  "waypoint_count": 3
}
```

---

### Crossing ETAs

Calculate ETAs for upcoming boundary crossings based on current position and groundspeed.

```
GET /api/gis/boundaries.php?action=crossing_etas&waypoints={json}&lat={lat}&lon={lon}&groundspeed={gs}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `waypoints` | JSON | Yes | - | Array of coordinate objects |
| `lat` | float | Yes | - | Current latitude |
| `lon` | float | Yes | - | Current longitude |
| `dist_flown` | float | No | 0 | Distance already flown (nm) |
| `groundspeed` | int | No | 450 | Ground speed (knots) |

**Example Request**

```
GET /api/gis/boundaries.php?action=crossing_etas&waypoints=[...]&lat=33.5&lon=-95.2&groundspeed=480
```

**Example Response**

```json
{
  "success": true,
  "crossing_etas": [
    {
      "boundary": "ZFW/ZME",
      "eta_utc": "2026-01-30T15:45:00Z",
      "minutes_until": 23,
      "distance_nm": 184
    },
    {
      "boundary": "ZME/ZJX",
      "eta_utc": "2026-01-30T16:32:00Z",
      "minutes_until": 70,
      "distance_nm": 560
    }
  ],
  "count": 2,
  "query": {
    "current_position": {"lat": 33.5, "lon": -95.2},
    "dist_flown_nm": 0,
    "groundspeed_kts": 480,
    "waypoint_count": 3
  }
}
```

---

## Boundary Adjacency Network

Endpoints for querying the boundary adjacency graph - which boundaries share borders.

### Compute Adjacencies

Compute all boundary adjacencies. **Heavy operation** - run after importing new boundaries.

```
GET /api/gis/boundaries.php?action=compute_adjacencies
```

**Response**

```json
{
  "success": true,
  "message": "Adjacencies computed",
  "results": [
    {"type": "ARTCC", "inserted": 142, "elapsed_ms": 1250},
    {"type": "TRACON", "inserted": 89, "elapsed_ms": 450},
    {"type": "SECTOR_HIGH", "inserted": 1247, "elapsed_ms": 3200}
  ],
  "summary": {
    "total_pairs_inserted": 1478,
    "total_elapsed_ms": 4900
  }
}
```

---

### Boundary Neighbors

Get all boundaries adjacent to a given boundary.

```
GET /api/gis/boundaries.php?action=boundary_neighbors&type={type}&code={code}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `type` | string | Yes | ARTCC, TRACON, SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH |
| `code` | string | Yes | Boundary code (e.g., KZFW, N90, ZFW45) |
| `adjacency` | string | No | Filter: POINT, LINE, or POLY |

**Adjacency Classes**

| Class | Description | Tier Value |
|-------|-------------|------------|
| POINT | Corner touch only | 0.5 (half tier) |
| LINE | Shared border segment | 1.0 (full tier) |
| POLY | Overlapping area | 1.0 (full tier) |

**Example Request**

```
GET /api/gis/boundaries.php?action=boundary_neighbors&type=ARTCC&code=KZFW
```

**Example Response**

```json
{
  "success": true,
  "boundary_type": "ARTCC",
  "boundary_code": "KZFW",
  "filter_adjacency": null,
  "neighbors": [
    {"neighbor_type": "ARTCC", "neighbor_code": "KZME", "adjacency_class": "LINE"},
    {"neighbor_type": "ARTCC", "neighbor_code": "KZKC", "adjacency_class": "LINE"},
    {"neighbor_type": "ARTCC", "neighbor_code": "KZAB", "adjacency_class": "LINE"},
    {"neighbor_type": "ARTCC", "neighbor_code": "KZHU", "adjacency_class": "LINE"}
  ],
  "count": 4
}
```

---

### Adjacency Statistics

Get summary statistics of the adjacency network.

```
GET /api/gis/boundaries.php?action=adjacency_stats
```

**Response**

```json
{
  "success": true,
  "stats": [
    {"boundary_type": "ARTCC", "total_boundaries": 21, "total_adjacencies": 142, "avg_neighbors": 6.8},
    {"boundary_type": "TRACON", "total_boundaries": 45, "total_adjacencies": 89, "avg_neighbors": 2.0},
    {"boundary_type": "SECTOR_HIGH", "total_boundaries": 312, "total_adjacencies": 1247, "avg_neighbors": 4.0}
  ],
  "count": 3
}
```

---

### Adjacency Edges (Export)

Export adjacency network as edge list for graph analysis tools.

```
GET /api/gis/boundaries.php?action=adjacency_edges&types={json}&min_adjacency={class}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `types` | JSON | No | all | Filter by boundary types |
| `min_adjacency` | string | No | LINE | Minimum adjacency class: POINT, LINE, POLY |

**Example Response**

```json
{
  "success": true,
  "min_adjacency": "LINE",
  "filter_types": null,
  "edges": [
    {"source": "ARTCC:KZFW", "target": "ARTCC:KZME", "adjacency": "LINE"},
    {"source": "ARTCC:KZFW", "target": "ARTCC:KZKC", "adjacency": "LINE"}
  ],
  "count": 142
}
```

---

### Boundary Path

Find shortest traversal path between two boundaries using BFS.

```
GET /api/gis/boundaries.php?action=boundary_path&src_type={type}&src_code={code}&tgt_type={type}&tgt_code={code}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `src_type` | string | Yes | - | Source boundary type |
| `src_code` | string | Yes | - | Source boundary code |
| `tgt_type` | string | Yes | - | Target boundary type |
| `tgt_code` | string | Yes | - | Target boundary code |
| `max_hops` | int | No | 10 | Maximum path length |
| `same_type` | 0/1 | No | 0 | Only traverse same boundary type |

**Example Request**

```
GET /api/gis/boundaries.php?action=boundary_path&src_type=ARTCC&src_code=KZFW&tgt_type=ARTCC&tgt_code=KZNY
```

**Example Response**

```json
{
  "success": true,
  "source": {"type": "ARTCC", "code": "KZFW"},
  "target": {"type": "ARTCC", "code": "KZNY"},
  "path_found": true,
  "path": ["KZFW", "KZME", "KZID", "KZOB", "KZNY"],
  "hops": 5
}
```

---

### ARTCC Adjacency Map

Get complete ARTCC-to-ARTCC adjacency map.

```
GET /api/gis/boundaries.php?action=artcc_adjacency_map&line_only={0|1}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `line_only` | 0/1 | No | 1 | Exclude point-only adjacencies |

**Example Response**

```json
{
  "success": true,
  "line_only": true,
  "artcc_map": {
    "KZFW": ["KZME", "KZKC", "KZAB", "KZHU"],
    "KZME": ["KZFW", "KZTL", "KZID", "KZKC", "KZHU"],
    "KZNY": ["KZDC", "KZOB", "KZBW"]
  },
  "artcc_count": 21
}
```

---

### Sector Adjacency

Get sector adjacency within an ARTCC.

```
GET /api/gis/boundaries.php?action=sector_adjacency&artcc={code}&sector_type={type}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `artcc` | string | Yes | - | ARTCC code |
| `sector_type` | string | No | HIGH | LOW, HIGH, or SUPERHIGH |

**Example Response**

```json
{
  "success": true,
  "artcc": "KZFW",
  "sector_type": "HIGH",
  "adjacencies": [
    {"sector": "ZFW45", "neighbors": ["ZFW46", "ZFW47", "ZFW44"]},
    {"sector": "ZFW46", "neighbors": ["ZFW45", "ZFW47", "ZFW48"]}
  ],
  "count": 12
}
```

---

## Proximity Tier Endpoints

Query boundaries by proximity tiers. LINE adjacency = 1 full tier, POINT adjacency = 0.5 tier.

### Proximity Tiers

Get all boundaries within N tiers of a given boundary.

```
GET /api/gis/boundaries.php?action=proximity_tiers&type={type}&code={code}&max_tier={n}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `type` | string | Yes | - | Boundary type |
| `code` | string | Yes | - | Boundary code |
| `max_tier` | float | No | 5.0 | Maximum tier distance |
| `same_type` | 0/1 | No | 0 | Only same boundary type |

**Example Request**

```
GET /api/gis/boundaries.php?action=proximity_tiers&type=ARTCC&code=KZFW&max_tier=2&same_type=1
```

**Example Response**

```json
{
  "success": true,
  "origin": {"type": "ARTCC", "code": "KZFW"},
  "max_tier": 2.0,
  "same_type_only": true,
  "tiers": {
    "1": [
      {"boundary_type": "ARTCC", "boundary_code": "KZME", "tier": 1.0},
      {"boundary_type": "ARTCC", "boundary_code": "KZKC", "tier": 1.0}
    ],
    "2": [
      {"boundary_type": "ARTCC", "boundary_code": "KZTL", "tier": 2.0},
      {"boundary_type": "ARTCC", "boundary_code": "KZID", "tier": 2.0}
    ]
  },
  "boundaries": [...],
  "total_count": 12
}
```

---

### Proximity Distance

Get tier distance between two specific boundaries.

```
GET /api/gis/boundaries.php?action=proximity_distance&src_type={type}&src_code={code}&tgt_type={type}&tgt_code={code}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `src_type` | string | Yes | - | Source boundary type |
| `src_code` | string | Yes | - | Source boundary code |
| `tgt_type` | string | Yes | - | Target boundary type |
| `tgt_code` | string | Yes | - | Target boundary code |
| `max_tier` | float | No | 10.0 | Maximum search distance |
| `same_type` | 0/1 | No | 0 | Only same boundary type |

**Example Response**

```json
{
  "success": true,
  "source": {"type": "ARTCC", "code": "KZFW"},
  "target": {"type": "ARTCC", "code": "KZNY"},
  "tier_distance": 4.0,
  "reachable": true,
  "is_neighbor": false,
  "is_corner_neighbor": false
}
```

---

### Boundaries at Tier

Get boundaries at a specific tier or tier range.

```
GET /api/gis/boundaries.php?action=boundaries_at_tier&type={type}&code={code}&tier={n}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `type` | string | Yes | Boundary type |
| `code` | string | Yes | Boundary code |
| `tier` | float | Yes* | Exact tier (or use tier_min) |
| `tier_min` | float | Yes* | Minimum tier (alternative to tier) |
| `tier_max` | float | No | Maximum tier (for ranges) |
| `same_type` | 0/1 | No | Only same boundary type |

**Example Response**

```json
{
  "success": true,
  "origin": {"type": "ARTCC", "code": "KZFW"},
  "tier_min": 1.0,
  "tier_max": 1.0,
  "boundaries": [
    {"boundary_type": "ARTCC", "boundary_code": "KZME"},
    {"boundary_type": "ARTCC", "boundary_code": "KZKC"},
    {"boundary_type": "ARTCC", "boundary_code": "KZAB"},
    {"boundary_type": "ARTCC", "boundary_code": "KZHU"}
  ],
  "count": 4
}
```

---

### Proximity Summary

Get boundary count summary per tier.

```
GET /api/gis/boundaries.php?action=proximity_summary&type={type}&code={code}&max_tier={n}
```

**Example Response**

```json
{
  "success": true,
  "origin": {"type": "ARTCC", "code": "KZFW"},
  "max_tier": 5.0,
  "same_type_only": false,
  "summary": [
    {"tier": 0.5, "count": 2},
    {"tier": 1.0, "count": 4},
    {"tier": 1.5, "count": 3},
    {"tier": 2.0, "count": 6},
    {"tier": 2.5, "count": 4},
    {"tier": 3.0, "count": 5}
  ],
  "total_boundaries": 24
}
```

---

### Validate Tiers

Validate GIS proximity tiers against ADL manual tier mappings.

```
GET /api/gis/boundaries.php?action=validate_tiers
```

**Response**

```json
{
  "success": true,
  "validation": "GIS Proximity Tier 1 vs ADL Manual 1st Tier",
  "summary": {
    "total_artccs": 20,
    "exact_matches": 18,
    "mismatches": 2,
    "match_percentage": 90.0
  },
  "results": {
    "ZFW": {
      "status": "MATCH",
      "adl_count": 4,
      "gis_count": 4,
      "adl_neighbors": ["ZAB", "ZHU", "ZKC", "ZME"],
      "gis_neighbors": ["ZAB", "ZHU", "ZKC", "ZME"],
      "adl_only": [],
      "gis_only": []
    }
  },
  "notes": [
    "GIS uses ICAO codes (KZFW), ADL uses FAA codes (ZFW)",
    "GIS may find additional international adjacencies (Mexican FIRs)",
    "LINE adjacency = shared border (full tier), POINT = corner touch (half tier)"
  ]
}
```

---

## Health Check

### Service Status

```
GET /api/gis/boundaries.php?action=health
```

**Response**

```json
{
  "success": true,
  "status": "ok",
  "service": "GIS Boundaries API",
  "database": "PostGIS",
  "connected": true,
  "timestamp": "2026-01-29T15:30:00+00:00"
}
```

---

## Diagnostic Endpoint

### Connection Diagnostics

Debug PostGIS connection issues. Runs before GIS service initialization for troubleshooting.

```
GET /api/gis/boundaries.php?action=diag
```

**Response**

```json
{
  "success": true,
  "diagnostic": {
    "php_version": "8.2.0",
    "pdo_drivers": ["mysql", "pgsql", "sqlsrv"],
    "pdo_pgsql_loaded": true,
    "pgsql_loaded": true,
    "connect_loaded": true,
    "gis_constants_defined": {
      "GIS_SQL_HOST": true,
      "GIS_SQL_PORT": true,
      "GIS_SQL_DATABASE": true,
      "GIS_SQL_USERNAME": true,
      "GIS_SQL_PASSWORD": true
    },
    "gis_host": "vatcscc-gis.postgres.database.azure.com",
    "gis_database": "VATSIM_GIS",
    "config_path_check": "EXISTS",
    "direct_connection": "SUCCESS",
    "server_version": "16.0",
    "gis_service_available": true,
    "server_time": "2026-01-30 12:00:00 UTC"
  }
}
```

**Diagnostic Fields**

| Field | Description |
|-------|-------------|
| `pdo_pgsql_loaded` | PHP PDO PostgreSQL extension status |
| `gis_constants_defined` | Required config constants check |
| `direct_connection` | Direct PDO connection test result |
| `gis_service_available` | GISService singleton availability |

---

## Error Responses

All endpoints return consistent error responses:

```json
{
  "success": false,
  "error": "Human-readable error message",
  "error_code": "MACHINE_READABLE_CODE",
  "message": "Additional details (optional)",
  "hint": "Suggestion for fixing the issue (optional)"
}
```

**Common Error Codes**

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `MISSING_PARAMS` | 400 | Required parameters not provided |
| `MISSING_ROUTE` | 400 | Route parameter required but missing |
| `MISSING_WAYPOINTS` | 400 | Waypoints parameter required but missing |
| `MISSING_FIX` | 400 | Fix parameter required but missing |
| `INVALID_ICAO` | 400 | Invalid ICAO airport code format |
| `INVALID_ARTCC` | 400 | Invalid ARTCC code format |
| `NOT_FOUND` | 404 | Requested resource not found |
| `EXPANSION_FAILED` | 404 | Route expansion could not be completed |
| `ANALYSIS_FAILED` | 404 | Route analysis could not be completed |
| `SERVICE_UNAVAILABLE` | 503 | PostGIS connection unavailable |
| `SERVER_ERROR` | 500 | Unexpected server error |

---

## PostGIS Functions Reference

The API uses these PostgreSQL/PostGIS functions:

**Route Expansion Functions**

| Function | Description |
|----------|-------------|
| `resolve_waypoint(fix_id)` | Resolve fix to coordinates with source |
| `expand_route(route_string)` | Parse route and expand airways |
| `expand_route_with_artccs(route_string)` | Expand route with ARTCC determination |
| `expand_playbook_route(pb_code)` | Expand playbook route code |
| `get_route_artccs(waypoints)` | Get ARTCCs for waypoint array |
| `expand_route_with_boundaries(route, altitude)` | Full boundary analysis |
| `expand_routes_batch(routes[])` | Batch expansion |
| `routes_to_geojson_collection(routes[])` | Convert to GeoJSON |

**Batch Boundary Detection Functions** (for daemon processing)

| Function | Description |
|----------|-------------|
| `get_artcc_at_point(lat, lon)` | Single-point ARTCC lookup (prefers non-oceanic) |
| `detect_boundaries_batch(flights_jsonb)` | Row-by-row batch boundary detection |
| `detect_boundaries_batch_optimized(flights_jsonb)` | Set-based batch detection (faster for >100 flights) |
| `detect_sector_for_flight(lat, lon, altitude)` | Get sectors containing a flight at altitude |

---

## Database Tables

The GIS API queries these PostGIS tables:

| Table | Description |
|-------|-------------|
| `nav_fixes` | Navigation waypoints (VORs, NDBs, fixes) |
| `airways` | Airway definitions |
| `airway_segments` | Airway segments with geometry |
| `airports` | Airport data with coordinates |
| `area_centers` | ARTCC center points |
| `artcc_boundaries` | ARTCC polygon boundaries |
| `tracon_boundaries` | TRACON polygon boundaries |
| `sector_boundaries` | Sector polygons (low/high/superhigh) |
| `playbook_routes` | CDR/Playbook route definitions |

See [[Database Schema]] for full table documentation.

---

## Performance Notes

Based on observed metrics (see metrics analysis):

| Operation | Typical Response | CPU Impact |
|-----------|------------------|------------|
| Single route expand | 150-300ms | 5-15% |
| Route with ARTCCs | 300-500ms | 10-25% |
| Full analysis (sectors) | 800-1500ms | 25-66% |
| Batch (3 routes) | 800-1200ms | 30-50% |

**Recommendations**:
- Cache resolved waypoints at application level
- Use batch endpoints for multiple routes
- Avoid sector analysis unless needed (use `&sectors=0`)

---

## See Also

- [[API Reference]] - Complete API index
- [[Database Schema]] - Database documentation
- [[Acronyms]] - GIS terminology definitions
- [[Navigation-Helper]] - Documentation index

---

*Last updated: 2026-01-30*
