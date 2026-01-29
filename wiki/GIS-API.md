# GIS Boundaries API

PostGIS-powered spatial queries for route expansion, boundary analysis, and geographic lookups.

---

## Overview

The GIS API provides spatial analysis capabilities using PostgreSQL/PostGIS. It enables:

- **Route Expansion**: Parse route strings (e.g., "KDFW BNA KMCO") to waypoint coordinates
- **Boundary Detection**: Identify ARTCCs, TRACONs, and sectors traversed by routes
- **Point-in-Polygon**: Determine which boundaries contain a given coordinate
- **GeoJSON Generation**: Convert routes to GeoJSON for mapping

**Base URL**: `/api/gis/boundaries.php`

**Version**: 1.1.0

---

## Quick Reference

| Action | Method | Description |
|--------|--------|-------------|
| `expand_route` | GET | Expand route string to waypoints and ARTCCs |
| `expand_routes` | GET/POST | Batch expand multiple routes |
| `expand_playbook` | GET | Expand playbook route code |
| `analyze_route` | GET/POST | Full analysis with sectors |
| `resolve_waypoint` | GET | Resolve fix to coordinates |
| `routes_geojson` | GET/POST | Convert routes to GeoJSON |
| `at_point` | GET | Point-in-polygon lookup |
| `route_artccs` | GET | ARTCCs from waypoints |
| `route_tracons` | GET | TRACONs from waypoints |
| `route_full` | GET | All boundaries from waypoints |
| `analyze_tmi_route` | POST | TMI route proposal analysis |
| `airport_artcc` | GET | Get ARTCC for airport |
| `artcc_airports` | GET | Get airports in ARTCC |
| `health` | GET | Service health check |

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

*Last updated: 2026-01-29*
