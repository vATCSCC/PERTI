# API Reference

This document provides a comprehensive reference for PERTI's RESTful API endpoints. These APIs enable integration with external systems and support the web interface.

---

## Overview

### Base URL

**Production:** `https://perti.vatcscc.org/api`

### Authentication

Most endpoints require an active session (VATSIM OAuth). Public endpoints are noted below.

### Response Format

All APIs return JSON. Standard response structure:

```json
{
  "success": true,
  "data": { ... },
  "message": "Optional status message"
}
```

Error responses:

```json
{
  "success": false,
  "error": "Error description",
  "code": 400
}
```

---

## ADL Flight Data APIs

These endpoints provide access to real-time and historical flight data from the Aeronautical Data Link (ADL) system.

### GET /api/adl/current.php

Returns current flight snapshot with filtering options.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `dest` | string | Filter by destination airport (ICAO) |
| `orig` | string | Filter by origin airport (ICAO) |
| `artcc` | string | Filter by ARTCC code |
| `airline` | string | Filter by airline ICAO code |
| `limit` | int | Maximum results (default: 1000) |

**Example:**

```
GET /api/adl/current.php?dest=KJFK&artcc=ZNY
```

**Response:**

```json
{
  "success": true,
  "timestamp": "2026-01-10T14:30:00Z",
  "count": 47,
  "flights": [
    {
      "callsign": "DAL123",
      "departure": "KATL",
      "arrival": "KJFK",
      "aircraft": "B739",
      "altitude": 35000,
      "groundspeed": 480,
      "latitude": 39.5234,
      "longitude": -74.1234,
      "phase": "cruise",
      "eta": "2026-01-10T15:45:00Z"
    }
  ]
}
```

### GET /api/adl/flight.php

Returns detailed information for a single flight.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Flight ID or callsign (required) |

### GET /api/adl/stats.php

Returns aggregate flight statistics.

**Access:** Authenticated

**Response includes:**
- Active flight count by phase
- Flights by ARTCC
- Airport arrival/departure counts
- System health metrics

### GET /api/adl/snapshot_history.php

Returns historical flight snapshots.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | datetime | Start time (ISO 8601) |
| `to` | datetime | End time (ISO 8601) |
| `airport` | string | Filter by airport |

---

## Traffic Management Initiative (TMI) APIs

APIs for managing Ground Stops, Ground Delay Programs, and other traffic initiatives.

### Ground Stop Operations

#### POST /api/tmi/gs/create.php

Creates a new Ground Stop in proposed status.

**Access:** Authenticated (DCC role)

**Request Body:**

```json
{
  "airport": "KJFK",
  "reason": "Weather",
  "scope": "tier1",
  "end_time": "2026-01-10T18:00:00Z",
  "notes": "Thunderstorms in terminal area"
}
```

#### POST /api/tmi/gs/model.php

Models the scope of a Ground Stop to identify affected flights.

**Access:** Authenticated (DCC role)

| Parameter | Type | Description |
|-----------|------|-------------|
| `gs_id` | int | Ground Stop ID |

#### POST /api/tmi/gs/activate.php

Activates a proposed Ground Stop, issuing EDCTs to affected flights.

**Access:** Authenticated (DCC role)

| Parameter | Type | Description |
|-----------|------|-------------|
| `gs_id` | int | Ground Stop ID |

#### POST /api/tmi/gs/extend.php

Extends an active Ground Stop end time.

**Access:** Authenticated (DCC role)

| Parameter | Type | Description |
|-----------|------|-------------|
| `gs_id` | int | Ground Stop ID |
| `new_end` | datetime | New end time |

#### POST /api/tmi/gs/purge.php

Cancels a Ground Stop, releasing affected flights.

**Access:** Authenticated (DCC role)

| Parameter | Type | Description |
|-----------|------|-------------|
| `gs_id` | int | Ground Stop ID |

#### GET /api/tmi/gs/flights.php

Returns flights affected by a Ground Stop.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `gs_id` | int | Ground Stop ID |

#### GET /api/tmi/gs/list.php

Returns all Ground Stop programs.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter: proposed, active, expired |
| `airport` | string | Filter by airport |

---

## GDT APIs (Unified TMI)

Ground Delay Tool unified API for managing GS, GDP, and AFP programs. Uses `VATSIM_TMI` database.

**Base Path:** `/api/gdt/`

### Program Operations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/gdt/programs/create.php` | POST | Create new GS/GDP/AFP program |
| `/api/gdt/programs/list.php` | GET | List programs with filtering |
| `/api/gdt/programs/get.php` | GET | Get single program with slots |
| `/api/gdt/programs/simulate.php` | POST | Generate slots and run RBS |
| `/api/gdt/programs/activate.php` | POST | Activate proposed program |
| `/api/gdt/programs/extend.php` | POST | Extend program end time |
| `/api/gdt/programs/purge.php` | POST | Cancel/purge program |
| `/api/gdt/programs/transition.php` | POST | Transition GS to GDP |

### Flight and Slot Operations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/gdt/flights/list.php` | GET | List flights for a program |
| `/api/gdt/slots/list.php` | GET | List slots for a program |
| `/api/gdt/demand/hourly.php` | GET | Get hourly demand/capacity |

See [[TMI API]] for full request/response documentation.

---

## GIS Boundaries API

PostGIS-powered spatial queries for route analysis and boundary detection.

**Base Path:** `/api/gis/`

### Route Expansion

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=expand_route` | GET | Expand route string to waypoints + ARTCCs |
| `boundaries.php?action=expand_routes` | GET/POST | Batch expand multiple routes |
| `boundaries.php?action=expand_playbook` | GET | Expand playbook code (PB.PLAY.ORIG.DEST) |
| `boundaries.php?action=analyze_route` | GET/POST | Full route analysis with sectors |
| `boundaries.php?action=resolve_waypoint` | GET | Resolve fix/airport to coordinates |
| `boundaries.php?action=routes_geojson` | GET/POST | Convert routes to GeoJSON FeatureCollection |

### Boundary Queries (from waypoints)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=at_point` | GET | Point-in-polygon boundary lookup |
| `boundaries.php?action=route_artccs` | GET | Get ARTCCs traversed by waypoints |
| `boundaries.php?action=route_tracons` | GET | Get TRACONs traversed by waypoints |
| `boundaries.php?action=route_full` | GET | Full boundary analysis from waypoints |
| `boundaries.php?action=analyze_tmi_route` | POST | TMI route analysis for coordination |

### Airport Queries

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=airport_artcc` | GET | Get ARTCC containing an airport |
| `boundaries.php?action=artcc_airports` | GET | Get airports within an ARTCC |

### Trajectory Crossings

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=trajectory_crossings` | GET | ARTCC boundary crossings along trajectory |
| `boundaries.php?action=sector_crossings` | GET | Sector boundary crossings along trajectory |
| `boundaries.php?action=all_crossings` | GET | All boundary crossings (ARTCC + sectors) |
| `boundaries.php?action=artccs_traversed` | GET | Simple list of ARTCCs crossed |
| `boundaries.php?action=crossing_etas` | GET | ETAs for upcoming boundary crossings |

### Boundary Adjacency Network

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=compute_adjacencies` | GET | Compute all boundary adjacencies |
| `boundaries.php?action=boundary_neighbors` | GET | Get neighbors of a boundary |
| `boundaries.php?action=adjacency_stats` | GET | Adjacency network statistics |
| `boundaries.php?action=adjacency_edges` | GET | Export adjacency as edge list |
| `boundaries.php?action=boundary_path` | GET | Find path between boundaries |
| `boundaries.php?action=artcc_adjacency_map` | GET | ARTCC-to-ARTCC adjacency map |
| `boundaries.php?action=sector_adjacency` | GET | Sector adjacency within ARTCC |

### Proximity Tiers

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=proximity_tiers` | GET | Boundaries within N tiers |
| `boundaries.php?action=proximity_distance` | GET | Tier distance between boundaries |
| `boundaries.php?action=boundaries_at_tier` | GET | Boundaries at specific tier |
| `boundaries.php?action=proximity_summary` | GET | Count summary per tier |
| `boundaries.php?action=validate_tiers` | GET | Validate GIS vs ADL tier mappings |

### Service/Diagnostics

| Endpoint | Method | Description |
|----------|--------|-------------|
| `boundaries.php?action=health` | GET | Service health check |
| `boundaries.php?action=diag` | GET | Connection diagnostics for debugging |

### Example: Expand Route

**Request:**

```http
GET /api/gis/boundaries.php?action=expand_route&route=KDFW BNA KMCO
```

**Response:**

```json
{
  "success": true,
  "route": "KDFW BNA KMCO",
  "artccs": ["ZFW", "ZME", "ZJX"],
  "artccs_display": "ZFW -> ZME -> ZJX",
  "waypoints": [
    {"seq": 1, "id": "KDFW", "lat": 32.897, "lon": -97.038},
    {"seq": 2, "id": "BNA", "lat": 36.124, "lon": -86.678},
    {"seq": 3, "id": "KMCO", "lat": 28.429, "lon": -81.309}
  ],
  "distance_nm": 812.5,
  "geojson": {"type": "LineString", "coordinates": [...]}
}
```

See [[GIS API]] for full request/response documentation.

---

## JATOC APIs

Joint Air Traffic Operations Command incident tracking.

### GET /api/jatoc/incidents.php

Returns list of incidents.

**Access:** Public (read), Authenticated (write)

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | active, resolved, all |
| `type` | string | atc_zero, atc_alert, atc_limited |
| `facility` | string | Filter by facility |
| `from` | datetime | Start date |
| `to` | datetime | End date |

### GET /api/jatoc/incident.php

Returns single incident details.

**Access:** Public

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | int | Incident ID (required) |

### POST /api/jatoc/incident.php

Creates a new incident.

**Access:** Authenticated (DCC role)

**Request Body:**

```json
{
  "facility": "ZNY",
  "type": "atc_limited",
  "ops_level": 2,
  "description": "Reduced staffing - expect delays",
  "expected_duration": 120
}
```

### PUT /api/jatoc/incident.php

Updates an existing incident.

**Access:** Authenticated (DCC role)

---

## NOD Dashboard APIs

NAS Operations Dashboard data endpoints.

### GET /api/nod/tmi_active.php

Returns all active Traffic Management Initiatives.

**Access:** Public

**Response includes:**
- Active Ground Stops
- Active GDPs
- Active reroutes
- Affected airports summary

### GET /api/nod/advisories.php

Returns DCC advisories.

**Access:** Public

| Parameter | Type | Description |
|-----------|------|-------------|
| `active` | bool | Only active advisories |
| `category` | string | Filter by category |

### GET /api/nod/tracks.php

Returns historical flight track data.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `callsign` | string | Flight callsign |
| `from` | datetime | Start time |
| `to` | datetime | End time |

---

## Demand Analysis APIs

Airport demand and capacity analysis.

### GET /api/demand/airports.php

Returns list of airports with demand data.

**Access:** Authenticated

### GET /api/demand/summary.php

Returns demand summary for specified airports.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `airports` | string | Comma-separated ICAO codes |
| `hours` | int | Hours to forecast (default: 6) |

**Response:**

```json
{
  "success": true,
  "airports": {
    "KJFK": {
      "current_aar": 44,
      "current_adr": 40,
      "demand": [
        {"hour": "14:00", "arrivals": 38, "departures": 35},
        {"hour": "15:00", "arrivals": 52, "departures": 42}
      ],
      "weather_category": "VFR",
      "suggested_rate": 44,
      "confidence": "high"
    }
  }
}
```

### GET /api/demand/rates.php

Returns rate data for an airport.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `airport` | string | ICAO code (required) |

### POST /api/demand/override.php

Applies manual rate override.

**Access:** Authenticated (DCC role)

**Request Body:**

```json
{
  "airport": "KJFK",
  "aar": 32,
  "adr": 30,
  "reason": "Runway closure",
  "valid_from": "2026-01-10T16:00:00Z",
  "valid_until": "2026-01-10T20:00:00Z"
}
```

---

## Airspace Element Demand APIs (v17)

Query traffic demand at navigation fixes, airway segments, and route segments. These endpoints call table-valued functions in Azure SQL for efficient demand analysis.

### GET /api/adl/demand/fix.php

Returns flights passing through a specific navigation fix within a time window.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `fix` | string | Navigation fix identifier (required) |
| `minutes` | int | Time window in minutes (default: 60, max: 720) |
| `dep_tracon` | string | Filter by departure TRACON (e.g., N90) |
| `arr_tracon` | string | Filter by arrival TRACON |
| `format` | string | 'list' (default) or 'count' |

**Example:**

```http
GET /api/adl/demand/fix?fix=MERIT&minutes=45&dep_tracon=N90
```

### GET /api/adl/demand/airway.php

Returns flights on an airway segment between two fixes. Requires the flight to have filed via the specified airway.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `airway` | string | Airway identifier (required, e.g., J48, V1, Q100) |
| `from_fix` | string | Segment start fix (required) |
| `to_fix` | string | Segment end fix (required) |
| `minutes` | int | Time window in minutes (default: 60, max: 720) |
| `format` | string | 'list' (default) or 'count' |

**Example:**

```http
GET /api/adl/demand/airway?airway=J48&from_fix=LANNA&to_fix=MOL&minutes=180
```

### GET /api/adl/demand/segment.php

Returns flights passing through two fixes in sequence, regardless of whether they filed via an airway or direct (DCT). More flexible than the airway endpoint for VATSIM where pilots often file direct routes.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `from_fix` | string | First fix (required) |
| `to_fix` | string | Second fix (required) |
| `minutes` | int | Time window in minutes (default: 60, max: 720) |
| `format` | string | 'list' (default) or 'count' |

**Example:**

```http
GET /api/adl/demand/segment?from_fix=CAM&to_fix=GONZZ&minutes=180
```

**Response fields:**

| Field | Description |
|-------|-------------|
| `entry_eta` | ETA at the first fix |
| `exit_eta` | ETA at the second fix |
| `segment_minutes` | Time to traverse the segment |
| `direction` | 'forward' or 'reverse' based on sequence order |
| `on_airway` | Airway identifier if filed via an airway |

### GET /api/adl/demand/batch.php

Returns time-bucketed demand counts for multiple monitors in a single call. Efficiently queries traffic at multiple fixes/segments with results grouped into time buckets.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `monitors` | JSON | Array of monitor definitions (required) |
| `bucket_minutes` | int | Time bucket size (default: 15, min: 5, max: 60) |
| `horizon_hours` | int | Projection horizon (default: 4, max: 12) |

**Monitor types:** `fix`, `segment`, `airway`, `airway_segment`, `via_fix`

**Example:**

```http
GET /api/adl/demand/batch?monitors=[{"type":"fix","fix":"MERIT"}]&bucket_minutes=15
```

### /api/adl/demand/monitors.php

CRUD operations for shared demand monitors that persist across sessions.

**Access:** Authenticated

| Method | Description |
|--------|-------------|
| GET | List all active monitors |
| POST | Create a new monitor |
| DELETE | Remove a monitor (by `id` or `monitor_key`) |

### GET /api/adl/demand/details.php

Returns individual flights captured by a demand monitor.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | Monitor type (required) |
| `fix/from/to/airway` | string | Type-specific identifiers |
| `minutes_ahead` | int | Time window (default: 60, max: 720) |
| `airline` | string | Filter by airline prefix |
| `aircraft_category` | string | HEAVY, LARGE, or SMALL |

---

## Public Routes APIs

Shared route advisories for coordination.

### GET /api/routes/public.php

Returns published public routes.

**Access:** Public

| Parameter | Type | Description |
|-----------|------|-------------|
| `active` | bool | Only active routes |
| `region` | string | Filter by region |

### POST /api/routes/public_post.php

Publishes a new public route.

**Access:** Authenticated

---

## Splits APIs

Sector configuration management.

### GET /api/splits/areas.php

Returns area definitions.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `artcc` | string | Filter by ARTCC |

### GET /api/splits/configs.php

Returns saved configurations.

**Access:** Authenticated

### GET /api/splits/active.php

Returns currently active splits.

**Access:** Authenticated

---

## Reference Data APIs

### GET /api/data/weather.php

Returns current weather data.

**Access:** Public

| Parameter | Type | Description |
|-----------|------|-------------|
| `airport` | string | ICAO code |
| `type` | string | metar, taf, sigmet |

### GET /api/data/sua.php

Returns Special Use Airspace data.

**Access:** Public

| Parameter | Type | Description |
|-----------|------|-------------|
| `active` | bool | Only active SUAs |
| `bounds` | string | Geographic bounds (lat1,lon1,lat2,lon2) |

### GET /api/data/tfr.php

Returns Temporary Flight Restrictions.

**Access:** Public

---

## ATFM Training Simulator APIs

Training simulator for TMU personnel practice.

### GET /api/simulator/navdata.php

Returns navigation data for flight routing.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `bounds` | string | Geographic bounds (lat1,lon1,lat2,lon2) |
| `type` | string | Filter: waypoint, navaid, airport |

### GET /api/simulator/routes.php

Returns route pattern reference data.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `origin` | string | Origin airport (ICAO) |
| `destination` | string | Destination airport (ICAO) |
| `carrier` | string | Filter by carrier ICAO |

**Response:**

```json
{
  "success": true,
  "routes": [
    {
      "route_id": 1234,
      "origin": "KATL",
      "destination": "KJFK",
      "avg_daily_flights": 12.5,
      "primary_carrier_icao": "DAL",
      "flight_time_min": 135,
      "distance_nm": 762
    }
  ]
}
```

### GET/POST /api/simulator/engine.php

Controls the flight simulation engine.

**Access:** Authenticated

**GET Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `session_id` | string | Simulation session ID |
| `action` | string | status, aircraft |

**POST Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `action` | string | create, tick, command |
| `session_id` | string | Simulation session ID |
| `data` | object | Action-specific parameters |

### GET/POST /api/simulator/traffic.php

Generates and manages simulated traffic.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `session_id` | string | Simulation session ID |
| `airport` | string | Target airport for traffic |
| `hours` | int | Hours of traffic to generate |

---

## Rate Limiting

API requests are subject to rate limiting:

| Tier | Requests/Minute | Notes |
|------|-----------------|-------|
| Public | 60 | Unauthenticated endpoints |
| Authenticated | 300 | Standard users |
| DCC Role | 600 | Traffic management personnel |

Exceeded limits return HTTP 429 with retry information.

---

## Error Codes

| Code | Description |
|------|-------------|
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Authentication required |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource does not exist |
| 429 | Too Many Requests - Rate limit exceeded |
| 500 | Internal Server Error |

---

## SWIM v1 APIs

System Wide Information Management - external flight data integration.

### Authentication

#### POST /api/swim/v1/auth.php

Validates API key and returns session token.

**Access:** API Key

**Request Body:**

```json
{
  "api_key": "swim_xxxxxxxxxxxx"
}
```

**Response:**

```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIs...",
  "expires_at": "2026-01-10T16:00:00Z"
}
```

### Flight Data

#### GET /api/swim/v1/flights.php

Returns current flights with optional filters.

**Access:** API Key

| Parameter | Type | Description |
|-----------|------|-------------|
| `dest` | string | Destination airport (ICAO) |
| `orig` | string | Origin airport (ICAO) |
| `artcc` | string | Current ARTCC |
| `phase` | string | Flight phase (prefile, departed, enroute, arrived) |
| `limit` | int | Maximum results (default: 500) |

#### GET /api/swim/v1/flight.php

Returns single flight details.

**Access:** API Key

| Parameter | Type | Description |
|-----------|------|-------------|
| `callsign` | string | Flight callsign (required) |
| `flight_uid` | int | Flight UID (alternative to callsign) |

#### GET /api/swim/v1/positions.php

Returns position data for active flights.

**Access:** API Key

| Parameter | Type | Description |
|-----------|------|-------------|
| `bounds` | string | Geographic bounds (lat1,lon1,lat2,lon2) |
| `artcc` | string | Filter by ARTCC |
| `since` | datetime | Positions updated since (ISO 8601) |

### API Key Management

#### POST /api/swim/v1/keys/provision.php

Provisions a new SWIM API key.

**Access:** Authenticated (Admin role)

**Request Body:**

```json
{
  "name": "My Integration",
  "organization": "vZNY",
  "contact_email": "admin@example.com",
  "rate_limit": 300
}
```

#### POST /api/swim/v1/keys/revoke.php

Revokes an existing API key.

**Access:** Authenticated (Admin role)

| Parameter | Type | Description |
|-----------|------|-------------|
| `key_id` | int | Key ID to revoke |

### TMI via SWIM

#### GET /api/swim/v1/tmi/programs.php

Returns active TMI programs (GS, GDP, AFP).

**Access:** API Key

#### GET /api/swim/v1/tmi/advisories.php

Returns active advisories.

**Access:** API Key

#### GET /api/swim/v1/tmi/controlled.php

Returns flights under TMI control (with EDCTs).

**Access:** API Key

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Filter by specific program |
| `airport` | string | Filter by control element |

### WebSocket

#### /api/swim/v1/ws/

Real-time position and event streaming via WebSocket.

**Connection:** `wss://perti.vatcscc.org/api/swim/v1/ws/`

**Subscription Message:**

```json
{
  "action": "subscribe",
  "channels": ["positions", "tmi_events"],
  "filters": {
    "artcc": "ZNY",
    "dest": "KJFK"
  }
}
```

See `docs/swim/README.md` for full SWIM documentation.

---

## Statistics APIs

Real-time and historical statistics.

### GET /api/stats/realtime.php

Returns current system statistics.

**Access:** Authenticated

**Response:**

```json
{
  "success": true,
  "timestamp": "2026-01-10T14:30:00Z",
  "flights": {
    "active": 1247,
    "by_phase": {
      "prefile": 89,
      "taxiing": 23,
      "departed": 156,
      "enroute": 834,
      "descending": 112,
      "arrived": 33
    }
  },
  "queue": {
    "pending": 45,
    "processing": 3,
    "avg_parse_ms": 142
  },
  "tmi": {
    "active_gs": 1,
    "active_gdp": 2,
    "controlled_flights": 87
  }
}
```

### GET /api/stats/daily.php

Returns daily aggregated statistics.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `date` | date | Date (YYYY-MM-DD), defaults to today |
| `days` | int | Number of days (for trends) |

### GET /api/stats/hourly.php

Returns hourly statistics breakdown.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `date` | date | Date (YYYY-MM-DD) |
| `hours` | int | Hours to include (default: 24) |

### GET /api/stats/airport.php

Returns statistics for a specific airport.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `airport` | string | ICAO code (required) |
| `hours` | int | Lookback hours (default: 6) |

**Response:**

```json
{
  "success": true,
  "airport": "KJFK",
  "arrivals": {
    "last_hour": 38,
    "next_hour": 42,
    "avg_delay_min": 8.5
  },
  "departures": {
    "last_hour": 35,
    "next_hour": 40
  },
  "current_config": "ILS 22L | ILS 22R | DEP 31L",
  "weather_category": "VMC"
}
```

### GET /api/stats/artcc.php

Returns statistics for an ARTCC.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `artcc` | string | ARTCC code (required) |

### GET /api/stats/citypair.php

Returns statistics for city pair routes.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `origin` | string | Origin ICAO |
| `destination` | string | Destination ICAO |
| `date` | date | Date for statistics |

### GET /api/stats/tmi.php

Returns TMI program statistics.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | datetime | Start date |
| `to` | datetime | End date |
| `type` | string | Filter by program type (GS, GDP) |

### GET /api/stats/flight_phase_history.php

Returns historical flight phase distribution.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `hours` | int | Lookback hours (default: 24) |

---

## Reroute APIs

Reroute management and compliance tracking.

### GET /api/tmi/reroutes.php

Returns reroute definitions.

**Access:** Authenticated

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | int | Filter: 0=draft, 1=proposed, 2=active, 3=monitoring, 4=expired, 5=cancelled |
| `active_only` | bool | Only active reroutes |

### POST /api/tmi/reroutes.php

Creates a new reroute.

**Access:** Authenticated (DCC role)

**Request Body:**

```json
{
  "name": "ZNY WEST GATE",
  "protected_segment": "MERIT..HAAYS..NEION",
  "origin_centers": "ZDC ZOB",
  "dest_airports": "KJFK KEWR KLGA KTEB",
  "start_utc": "2026-01-10T14:00:00Z",
  "end_utc": "2026-01-10T22:00:00Z",
  "time_basis": "ETD",
  "comments": "Weather avoidance routing"
}
```

### GET /api/tmi/reroutes.php?id={reroute_id}

Returns reroute details with assigned flights.

**Access:** Authenticated

**Response includes:**
- Reroute definition
- Assigned flights with compliance status
- Compliance statistics (compliant, partial, non-compliant)

---

## See Also

- [[ADL API]] - Detailed ADL API documentation
- [[TMI API]] - Traffic Management Initiative details
- [[Architecture]] - System architecture overview
- [[Navigation Helper]] - Find the right documentation quickly
