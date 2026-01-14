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

## See Also

- [[ADL API]] - Detailed ADL API documentation
- [[TMI API]] - Traffic Management Initiative details
- [[Architecture]] - System architecture overview
