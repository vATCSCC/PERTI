# VATSIM SWIM API Documentation

**Version:** 1.0.0  
**Last Updated:** January 16, 2026  
**Status:** Production Ready  
**API Base URL:** `https://perti.vatcscc.org/api/swim/v1`  
**WebSocket URL:** `wss://perti.vatcscc.org/api/swim/v1/ws`

---

## Table of Contents

1. [Overview](#1-overview)
2. [Authentication](#2-authentication)
3. [REST API Endpoints](#3-rest-api-endpoints)
4. [WebSocket Real-Time API](#4-websocket-real-time-api)
5. [Data Models](#5-data-models)
6. [Python SDK](#6-python-sdk)
7. [Configuration Reference](#7-configuration-reference)
8. [Database Schema](#8-database-schema)
9. [Deployment & Operations](#9-deployment--operations)
10. [Implementation Status](#10-implementation-status)

---

## 1. Overview

### 1.1 What is VATSIM SWIM?

VATSIM SWIM (System Wide Information Management) is a comprehensive API that provides access to real-time and historical flight data from the VATSIM virtual air traffic control network. The API follows FAA SWIM and FIXM (Flight Information Exchange Model) standards to provide standardized flight data to external consumers.

### 1.2 Key Features

- **REST API** for querying flight data, positions, and traffic management initiatives (TMIs)
- **WebSocket API** for real-time event streaming (departures, arrivals, position updates)
- **FIXM-aligned field naming** with optional legacy format support
- **GeoJSON output** for position data
- **Tiered access control** with API key authentication
- **Python SDK** for easy client integration

### 1.3 Architecture Overview

```
┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐
│  VATSIM Network │─────▶│   ADL Daemon    │─────▶│   SWIM_API DB   │
│   (live data)   │      │  (15s refresh)  │      │  (Azure SQL)    │
└─────────────────┘      └────────┬────────┘      └────────┬────────┘
                                  │                        │
                                  │ events                 │ queries
                                  ▼                        ▼
                         ┌─────────────────┐      ┌─────────────────┐
                         │   WebSocket     │      │    REST API     │
                         │    Server       │      │   Endpoints     │
                         └────────┬────────┘      └────────┬────────┘
                                  │                        │
                    ┌─────────────┴────────────────────────┴─────────────┐
                    │                  SWIM Clients                       │
                    │  (vNAS, CRC, SimAware, Virtual Airlines, etc.)     │
                    └─────────────────────────────────────────────────────┘
```

### 1.4 Cost Structure

| Component | Monthly Cost |
|-----------|-------------|
| SWIM_API Database (Azure SQL Basic) | $5 |
| WebSocket Server (self-hosted) | $0 |
| **Total** | **$5/month** |

---

## 2. Authentication

### 2.1 API Keys

All API requests require authentication via Bearer token or query parameter.

**Header Authentication (Recommended):**
```
Authorization: Bearer swim_dev_your_key_here
```

**Query Parameter (WebSocket):**
```
wss://perti.vatcscc.org/api/swim/v1/ws?api_key=swim_dev_your_key_here
```

### 2.2 API Key Tiers

| Tier | Prefix | Rate Limit | Max WS Connections | Write Access |
|------|--------|------------|-------------------|--------------|
| **system** | `swim_sys_` | 30,000/min | 10,000 | Yes |
| **partner** | `swim_par_` | 3,000/min | 500 | Limited |
| **developer** | `swim_dev_` | 300/min | 50 | No |
| **public** | `swim_pub_` | 100/min | 5 | No |

### 2.3 Creating API Keys

API keys are stored in the `dbo.swim_api_keys` table in VATSIM_ADL database.

```sql
INSERT INTO dbo.swim_api_keys (api_key, tier, owner_name, owner_email, description)
VALUES (
    'swim_dev_' + LOWER(CONVERT(VARCHAR(36), NEWID())),
    'developer',
    'Developer Name',
    'email@example.com',
    'API key for development testing'
);
```

### 2.4 Key Validation

The system validates keys against the database with a 5-minute cache TTL:
- Checks `is_active = 1`
- Checks `expires_at` is null or in the future
- Updates `last_used_at` on successful authentication

---

## 3. REST API Endpoints

### 3.1 API Index

**Endpoint:** `GET /api/swim/v1`

Returns API information and available endpoints.

**Response:**
```json
{
  "success": true,
  "data": {
    "name": "VATSIM SWIM API",
    "version": "1.0.0",
    "description": "System Wide Information Management for VATSIM",
    "documentation": "https://perti.vatcscc.org/docs/swim/",
    "endpoints": {
      "flights": {
        "GET /api/swim/v1/flights": "List flights with filters",
        "GET /api/swim/v1/flight": "Get single flight by GUFI or flight_key"
      },
      "positions": {
        "GET /api/swim/v1/positions": "Bulk flight positions (GeoJSON)"
      },
      "tmi": {
        "GET /api/swim/v1/tmi/programs": "Active TMI programs (GS/GDP)",
        "GET /api/swim/v1/tmi/controlled": "Flights under TMI control"
      }
    }
  }
}
```

### 3.2 Flights List

**Endpoint:** `GET /api/swim/v1/flights`

Returns paginated list of flights with optional filtering.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | `active` (default), `completed`, or `all` |
| `dept_icao` | string | Comma-separated departure airports (e.g., `KJFK,KLGA`) |
| `dest_icao` | string | Comma-separated destination airports |
| `artcc` | string | Comma-separated ARTCCs (e.g., `ZNY,ZBW`) |
| `callsign` | string | Callsign pattern with `*` wildcards (e.g., `UAL*`) |
| `tmi_controlled` | boolean | `true` to filter TMI-controlled flights |
| `phase` | string | Flight phase filter (e.g., `ENROUTE,DESCENDING`) |
| `format` | string | `legacy` (default) or `fixm` for FIXM field names |
| `page` | int | Page number (default: 1) |
| `per_page` | int | Results per page (default: 100, max: 1000) |

**Example Request:**
```bash
curl -H "Authorization: Bearer swim_dev_test" \
  "https://perti.vatcscc.org/api/swim/v1/flights?dest_icao=KJFK&status=active"
```

**Response (Legacy Format):**
```json
{
  "success": true,
  "data": [
    {
      "gufi": "VAT-20260116-UAL123-KLAX-KJFK",
      "flight_uid": 12345,
      "flight_key": "UAL123_KLAX_KJFK_20260116",
      "identity": {
        "callsign": "UAL123",
        "cid": 1234567,
        "aircraft_type": "B738",
        "aircraft_icao": "B738",
        "weight_class": "L",
        "wake_category": "M",
        "airline_icao": "UAL",
        "airline_name": "United Airlines"
      },
      "flight_plan": {
        "departure": "KLAX",
        "destination": "KJFK",
        "alternate": "KEWR",
        "cruise_altitude": 35000,
        "cruise_speed": 450,
        "route": "DOTSS5 KAYOH J146 ABQ J80 HVE J100 STL J24 JHW LENDY5",
        "flight_rules": "I",
        "departure_artcc": "ZLA",
        "destination_artcc": "ZNY",
        "arrival_fix": "LENDY",
        "arrival_procedure": "LENDY5"
      },
      "position": {
        "latitude": 40.1234,
        "longitude": -74.5678,
        "altitude_ft": 35000,
        "heading": 85,
        "ground_speed_kts": 480,
        "vertical_rate_fpm": 0,
        "current_artcc": "ZNY"
      },
      "progress": {
        "phase": "ENROUTE",
        "is_active": true,
        "distance_remaining_nm": 125.4,
        "pct_complete": 95.2,
        "time_to_dest_min": 15.7
      },
      "times": {
        "eta": "2026-01-16T18:45:00Z",
        "eta_runway": "2026-01-16T18:52:00Z",
        "out": "2026-01-16T14:05:00Z",
        "off": "2026-01-16T14:18:00Z"
      },
      "tmi": {
        "is_controlled": false,
        "ground_stop_held": false
      }
    }
  ],
  "pagination": {
    "total": 156,
    "page": 1,
    "per_page": 100,
    "total_pages": 2,
    "has_more": true
  },
  "timestamp": "2026-01-16T18:30:00Z"
}
```

### 3.3 Single Flight

**Endpoint:** `GET /api/swim/v1/flight`

Returns a single flight by GUFI or flight_key.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `gufi` | string | Globally Unique Flight Identifier |
| `flight_key` | string | ADL flight key |
| `format` | string | `legacy` or `fixm` |

### 3.4 Positions (GeoJSON)

**Endpoint:** `GET /api/swim/v1/positions`

Returns bulk flight positions in GeoJSON FeatureCollection format.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `dept_icao` | string | Departure airport filter |
| `dest_icao` | string | Destination airport filter |
| `artcc` | string | ARTCC filter |
| `bounds` | string | Bounding box: `minLon,minLat,maxLon,maxLat` |
| `tmi_controlled` | boolean | TMI-controlled flights only |
| `phase` | string | Flight phase filter |
| `include_route` | boolean | Include route string in properties |

**Example Response:**
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "id": 12345,
      "geometry": {
        "type": "Point",
        "coordinates": [-74.5678, 40.1234, 35000]
      },
      "properties": {
        "flight_uid": 12345,
        "callsign": "UAL123",
        "aircraft": "B738",
        "departure": "KLAX",
        "destination": "KJFK",
        "phase": "ENROUTE",
        "altitude": 35000,
        "heading": 85,
        "groundspeed": 480,
        "distance_remaining_nm": 125.4,
        "tmi_status": "none"
      }
    }
  ],
  "metadata": {
    "count": 2847,
    "timestamp": "2026-01-16T18:30:00Z",
    "source": "vatcscc"
  }
}
```

### 3.5 TMI Programs

**Endpoint:** `GET /api/swim/v1/tmi/programs`

Returns active Traffic Management Initiatives (Ground Stops, GDPs).

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `all` (default), `gs`, or `gdp` |
| `airport` | string | Airport ICAO filter |
| `artcc` | string | ARTCC filter |
| `include_history` | boolean | Include recently ended programs |

**Example Response:**
```json
{
  "success": true,
  "data": {
    "ground_stops": [
      {
        "type": "ground_stop",
        "airport": "KJFK",
        "airport_name": "John F Kennedy Intl",
        "artcc": "ZNY",
        "reason": "Thunderstorms",
        "probability_of_extension": 60,
        "times": {
          "start": "2026-01-16T17:00:00Z",
          "end": "2026-01-16T19:00:00Z"
        },
        "is_active": true
      }
    ],
    "gdp_programs": [
      {
        "type": "gdp",
        "program_id": "GDP_KEWR_20260116",
        "airport": "KEWR",
        "airport_name": "Newark Liberty Intl",
        "artcc": "ZNY",
        "reason": "Volume",
        "rates": {"program_rate": 40},
        "delays": {
          "limit_minutes": 90,
          "average_minutes": 45,
          "maximum_minutes": 87
        },
        "flights": {
          "total": 156,
          "affected": 89
        },
        "is_active": true
      }
    ],
    "summary": {
      "active_ground_stops": 1,
      "active_gdp_programs": 1,
      "total_controlled_airports": 2
    }
  }
}
```

### 3.6 TMI Controlled Flights

**Endpoint:** `GET /api/swim/v1/tmi/controlled`

Returns flights currently under TMI control.

### 3.7 ADL Flight Ingest

**Endpoint:** `POST /api/swim/v1/ingest/adl`

Receives flight data from authoritative sources. Requires write access (system or partner tier).

**Maximum batch size:** 500 flights per request

**Request Body:**
```json
{
  "flights": [
    {
      "callsign": "UAL123",
      "dept_icao": "KJFK",
      "dest_icao": "KLAX",
      "cid": 1234567,
      "aircraft_type": "B738",
      "route": "DCT JFK J584 ORD J64 LAX",
      "phase": "ENROUTE",
      "is_active": true,
      "latitude": 40.1234,
      "longitude": -74.5678,
      "altitude_ft": 35000,
      "heading_deg": 270,
      "groundspeed_kts": 450,
      "vertical_rate_fpm": -500,
      "out_utc": "2026-01-16T14:05:00Z",
      "off_utc": "2026-01-16T14:18:00Z",
      "eta_utc": "2026-01-16T18:45:00Z",
      "tmi": {
        "ctl_type": "GDP",
        "slot_time_utc": "2026-01-16T18:30:00Z",
        "delay_minutes": 45
      }
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "processed": 1,
    "created": 0,
    "updated": 1,
    "errors": 0,
    "error_details": []
  },
  "timestamp": "2026-01-16T12:00:00Z",
  "meta": {
    "source": "vatcscc",
    "batch_size": 1
  }
}
```

### 3.8 Track Position Ingest

**Endpoint:** `POST /api/swim/v1/ingest/track`

Receives real-time track/position updates from authoritative sources (vNAS, CRC, EuroScope, AOC systems).

**Maximum batch size:** 1000 tracks per request (higher limit for frequent position updates)

**Request Body:**
```json
{
  "tracks": [
    {
      "callsign": "UAL123",
      "latitude": 40.6413,
      "longitude": -73.7781,
      "altitude_ft": 35000,
      "ground_speed_kts": 450,
      "heading_deg": 270,
      "vertical_rate_fpm": -500,
      "squawk": "1200",
      "track_source": "radar"
    }
  ]
}
```

**Track Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `callsign` | string | Yes | Aircraft callsign |
| `latitude` | number | Yes | Latitude (-90 to 90) |
| `longitude` | number | Yes | Longitude (-180 to 180) |
| `altitude_ft` | integer | No | Altitude in feet MSL |
| `ground_speed_kts` | integer | No | Ground speed in knots |
| `heading_deg` | integer | No | Heading (0-360) |
| `vertical_rate_fpm` | integer | No | Vertical rate (+ = climb, - = descend) |
| `squawk` | string | No | Transponder code (4 digits) |
| `track_source` | string | No | `radar`, `ads-b`, `mlat`, `mode-s`, `acars` |
| `timestamp` | datetime | No | Observation time (ISO 8601) |

**Response:**
```json
{
  "success": true,
  "data": {
    "processed": 100,
    "updated": 95,
    "not_found": 5,
    "errors": 0,
    "error_details": []
  },
  "timestamp": "2026-01-16T12:00:00Z",
  "meta": {
    "source": "vnas",
    "batch_size": 100
  }
}
```

**Notes:**
- Tracks are matched by callsign to existing active flights
- Flights not found are skipped (not_found count) but not considered errors
- Use this endpoint for high-frequency position updates from radar/ADS-B systems

---

## 4. WebSocket Real-Time API

### 4.1 Connection

Connect to the WebSocket server with your API key:

```javascript
const ws = new WebSocket('wss://perti.vatcscc.org/api/swim/v1/ws?api_key=YOUR_KEY');
```

**Connection Response:**
```json
{
  "type": "connected",
  "data": {
    "client_id": "c_abc123def456",
    "server_time": "2026-01-16T18:30:00Z",
    "version": "1.0.0"
  }
}
```

### 4.2 Subscribing to Events

Send a subscribe message to receive specific event types:

```json
{
  "action": "subscribe",
  "channels": ["flight.departed", "flight.arrived", "tmi.issued"],
  "filters": {
    "airports": ["KJFK", "KLGA", "KEWR"],
    "artccs": ["ZNY"]
  }
}
```

**Available Channels:**

| Channel | Description |
|---------|-------------|
| `flight.created` | New pilot connected to network |
| `flight.departed` | Aircraft wheels-up (OFF time set) |
| `flight.arrived` | Aircraft wheels-down (IN time set) |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Batched position updates |
| `flight.*` | All flight events |
| `tmi.issued` | New Ground Stop/GDP created |
| `tmi.released` | TMI ended/released |
| `tmi.*` | All TMI events |
| `system.heartbeat` | Server keepalive (30s interval) |

**Subscription Filters:**

| Filter | Type | Description |
|--------|------|-------------|
| `airports` | array | ICAO codes to filter by departure/destination |
| `artccs` | array | ARTCC IDs to filter |
| `callsign_prefix` | array | Callsign prefixes (e.g., `["UAL", "DAL"]`) |
| `bbox` | object | Geographic bounding box `{north, south, east, west}` |

### 4.3 Event Formats

**flight.departed:**
```json
{
  "type": "flight.departed",
  "timestamp": "2026-01-16T18:30:15.123Z",
  "data": {
    "callsign": "UAL123",
    "flight_uid": 12345,
    "dep": "KLAX",
    "arr": "KJFK",
    "off_utc": "2026-01-16T18:30:00Z"
  }
}
```

**flight.arrived:**
```json
{
  "type": "flight.arrived",
  "timestamp": "2026-01-16T22:45:30.456Z",
  "data": {
    "callsign": "UAL123",
    "flight_uid": 12345,
    "dep": "KLAX",
    "arr": "KJFK",
    "in_utc": "2026-01-16T22:45:00Z"
  }
}
```

**flight.positions (batched):**
```json
{
  "type": "flight.positions",
  "timestamp": "2026-01-16T18:30:15.123Z",
  "data": {
    "count": 2847,
    "positions": [
      {
        "callsign": "UAL123",
        "flight_uid": 12345,
        "latitude": 40.1234,
        "longitude": -74.5678,
        "altitude_ft": 35000,
        "groundspeed_kts": 480,
        "heading_deg": 85,
        "vertical_rate_fpm": 0,
        "current_artcc": "ZNY",
        "dep": "KLAX",
        "arr": "KJFK"
      }
    ]
  }
}
```

**tmi.issued:**
```json
{
  "type": "tmi.issued",
  "timestamp": "2026-01-16T17:00:00.000Z",
  "data": {
    "program_id": "GS_KJFK_20260116",
    "program_type": "GROUND_STOP",
    "airport": "KJFK",
    "start_time": "2026-01-16T17:00:00Z",
    "end_time": "2026-01-16T19:00:00Z",
    "reason": "Thunderstorms"
  }
}
```

**system.heartbeat:**
```json
{
  "type": "system.heartbeat",
  "timestamp": "2026-01-16T18:30:00Z",
  "data": {
    "connected_clients": 47,
    "uptime_seconds": 86400
  }
}
```

### 4.4 Client Actions

**Ping:**
```json
{"action": "ping"}
```
Response: `{"type": "pong", "timestamp": "..."}`

**Status:**
```json
{"action": "status"}
```
Returns current subscriptions and message counts.

**Unsubscribe:**
```json
{
  "action": "unsubscribe",
  "channels": ["flight.positions"]
}
```

### 4.5 Error Handling

```json
{
  "type": "error",
  "code": "AUTH_FAILED",
  "message": "Invalid or missing API key"
}
```

**Error Codes:**

| Code | Description |
|------|-------------|
| `AUTH_FAILED` | Invalid or expired API key |
| `CONNECTION_LIMIT` | Tier connection limit reached |
| `RATE_LIMITED` | Too many messages per second |
| `INVALID_JSON` | Malformed JSON message |
| `INVALID_CHANNEL` | Unknown channel name |
| `INVALID_FILTER` | Invalid filter specification |
| `MESSAGE_TOO_LARGE` | Message exceeds 64KB limit |

---

## 5. Data Models

### 5.1 GUFI (Globally Unique Flight Identifier)

Format: `VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`

Example: `VAT-20260116-UAL123-KLAX-KJFK`

**Generation:**
```php
function swim_generate_gufi($callsign, $dept_icao, $dest_icao, $date = null) {
    if ($date === null) $date = gmdate('Ymd');
    return implode('-', ['VAT', $date, strtoupper($callsign), 
                         strtoupper($dept_icao), strtoupper($dest_icao)]);
}
```

### 5.2 Flight Phases

| Phase | Description |
|-------|-------------|
| `PREFLIGHT` | Connected, not yet departed |
| `DEPARTING` | Taxiing for departure |
| `CLIMBING` | Airborne, climbing |
| `ENROUTE` | Cruise altitude |
| `DESCENDING` | Descending to destination |
| `APPROACH` | On approach |
| `LANDED` | Wheels down, taxiing |
| `ARRIVED` | At gate/parked |

### 5.3 TMI Control Types

| Type | Description |
|------|-------------|
| `GS` | Ground Stop |
| `GDP` | Ground Delay Program |
| `MIT` | Miles-in-Trail |
| `MINIT` | Minutes-in-Trail |
| `AFP` | Airspace Flow Program |

### 5.4 AOC Telemetry Fields

Virtual Airlines and flight simulator integrations can push telemetry data via the ADL ingest endpoint.

**OOOI Times (Out/Off/On/In):**

| Field | Description | Source |
|-------|-------------|--------|
| `out_utc` | Gate departure (pushback) | AOC/ACARS |
| `off_utc` | Wheels up (takeoff) | AOC/ACARS |
| `on_utc` | Wheels down (landing) | AOC/ACARS |
| `in_utc` | Gate arrival | AOC/ACARS |

**FMC Times:**

| Field | Description | Source |
|-------|-------------|--------|
| `eta_utc` | Estimated time of arrival | FMC/AOC |
| `etd_utc` | Estimated time of departure | FMC/AOC |

**Position Telemetry:**

| Field | Description | Source |
|-------|-------------|--------|
| `vertical_rate_fpm` | Climb/descent rate (ft/min) | Flight sim |
| `latitude` | Current position | Flight sim |
| `longitude` | Current position | Flight sim |
| `altitude_ft` | Current altitude (MSL) | Flight sim |
| `heading_deg` | Current heading | Flight sim |
| `groundspeed_kts` | Ground speed | Flight sim |

**Example: Virtual Airline PIREP Integration**
```json
{
  "flights": [
    {
      "callsign": "VPA123",
      "dept_icao": "KJFK",
      "dest_icao": "KLAX",
      "cid": 1234567,
      "out_utc": "2026-01-16T14:05:00Z",
      "off_utc": "2026-01-16T14:18:00Z",
      "latitude": 40.1234,
      "longitude": -98.5678,
      "altitude_ft": 35000,
      "groundspeed_kts": 485,
      "vertical_rate_fpm": 0,
      "eta_utc": "2026-01-16T18:45:00Z"
    }
  ]
}
```

**Data Authority Rules:**

The telemetry data source must be authorized to write the field:
- `SIMULATOR` source: Authoritative for `telemetry` fields, can override
- `VIRTUAL_AIRLINE` source: Authoritative for `airline` fields
- Other sources cannot write telemetry fields unless explicitly allowed

### 5.5 FIXM Field Mapping

The API supports both legacy and FIXM-aligned field names via the `?format=fixm` parameter.

| Legacy Name | FIXM Name |
|-------------|-----------|
| `callsign` | `aircraft_identification` |
| `departure` | `departure_aerodrome` |
| `destination` | `arrival_aerodrome` |
| `cruise_altitude` | `cruising_level` |
| `heading` | `track` |
| `ground_speed_kts` | `ground_speed` |
| `altitude_ft` | `altitude` |
| `phase` | `flight_status` |
| `wake_category` | `wake_turbulence` |
| `airline_icao` | `operator_icao` |
| `out` | `actual_off_block_time` |
| `off` | `actual_time_of_departure` |
| `on` | `actual_landing_time` |
| `in` | `actual_in_block_time` |

---

## 6. Python SDK

### 6.1 Installation

```bash
cd PERTI/sdk/python
pip install -e .
```

Or install directly:
```bash
pip install websockets
```

### 6.2 Basic Usage

```python
from swim_client import SWIMClient

# Create client
client = SWIMClient('swim_dev_your_key', debug=True)

# Handle events with decorators
@client.on('connected')
def on_connected(info, timestamp):
    print(f"Connected! Client ID: {info.client_id}")

@client.on('flight.departed')
def on_departure(event, timestamp):
    print(f"{event.callsign} departed {event.dep}")

@client.on('flight.arrived')
def on_arrival(event, timestamp):
    print(f"{event.callsign} arrived at {event.arr}")

@client.on('system.heartbeat')
def on_heartbeat(data, timestamp):
    print(f"Heartbeat: {data.connected_clients} clients")

# Subscribe to channels
client.subscribe([
    'flight.departed',
    'flight.arrived',
    'system.heartbeat'
])

# Run (blocking)
client.run()
```

### 6.3 Filtering

```python
# Subscribe with airport filter
client.subscribe(
    channels=['flight.departed', 'flight.arrived'],
    airports=['KJFK', 'KLGA', 'KEWR'],
    artccs=['ZNY']
)

# Subscribe with bounding box
client.subscribe(
    channels=['flight.positions'],
    bbox={'north': 42.0, 'south': 39.0, 'east': -72.0, 'west': -76.0}
)
```

### 6.4 Async Usage

```python
import asyncio
from swim_client import SWIMClient

async def main():
    client = SWIMClient('swim_dev_your_key')
    
    @client.on('flight.departed')
    def on_departure(event, timestamp):
        print(f"{event.callsign} departed")
    
    client.subscribe(['flight.departed'])
    
    await client.connect()
    await client.run_async()

asyncio.run(main())
```

### 6.5 SDK Configuration

| Parameter | Default | Description |
|-----------|---------|-------------|
| `api_key` | required | API key for authentication |
| `url` | wss://perti.vatcscc.org/... | WebSocket URL |
| `reconnect` | True | Auto-reconnect on disconnect |
| `reconnect_interval` | 5.0 | Initial reconnect delay (seconds) |
| `max_reconnect_interval` | 60.0 | Maximum reconnect delay |
| `ping_interval` | 30.0 | Ping interval (seconds) |
| `debug` | False | Enable debug logging |

---

## 7. Configuration Reference

### 7.1 swim_config.php

Located at `PERTI/load/swim_config.php`

**API Version:**
```php
define('SWIM_API_VERSION', '1.0.0');
define('SWIM_API_PREFIX', '/api/swim/v1');
```

**Rate Limits:**
```php
$SWIM_RATE_LIMITS = [
    'system'    => 30000,
    'partner'   => 3000,
    'developer' => 300,
    'public'    => 100
];
```

**Key Prefixes:**
```php
$SWIM_KEY_PREFIXES = [
    'system'    => 'swim_sys_',
    'partner'   => 'swim_par_',
    'developer' => 'swim_dev_',
    'public'    => 'swim_pub_'
];
```

**Data Sources:**
```php
$SWIM_DATA_SOURCES = [
    // Core sources
    'VATSIM'          => 'vatsim',           // Identity and flight plans
    'VATCSCC'         => 'vatcscc',          // ADL, TMI, demand

    // Track/position sources
    'VNAS'            => 'vnas',             // Track data, ATC automation
    'CRC'             => 'crc',              // Track data, tags
    'EUROSCOPE'       => 'euroscope',        // Track data

    // ACARS sources
    'ACARS'           => 'acars',            // Generic ACARS (OOOI times)
    'HOPPIE'          => 'hoppie',           // Hoppie ACARS

    // Metering sources
    'SIMTRAFFIC'      => 'simtraffic',       // TBFM-style metering
    'TOPSKY'          => 'topsky',           // TopSky EuroScope AMAN

    // External sources
    'SIMBRIEF'        => 'simbrief',         // OFP data
    'SIMULATOR'       => 'simulator',        // Pilot sim telemetry
    'VIRTUAL_AIRLINE' => 'virtual_airline',  // VA AOC systems (schedules, CDM)

    // Future
    'VFDS'            => 'vfds',             // vFlightDataSystems
];
```

**Source Priority Rankings (per FAA CDM spec):**

| Data Type | Priority Order (highest first) |
|-----------|-------------------------------|
| Track Position | vNAS → CRC → EuroScope → simulator → ACARS |
| OOOI Times | ACARS → Virtual Airline → simulator → VATCSCC |
| Schedule (STD/STA) | Virtual Airline → SimBrief → VATCSCC |
| Metering | SimTraffic → VATCSCC → vNAS → TopSky |
| General Times | SimTraffic → VATCSCC → vNAS → vFDS → SimBrief → simulator |

**CDM Time Fields (FAA ADL T-Field Reference):**

| Field | CDM Ref | Description | Authority |
|-------|---------|-------------|-----------|
| `std_utc` | - | Scheduled Time of Departure | VA/SimBrief |
| `sta_utc` | - | Scheduled Time of Arrival | VA/SimBrief |
| `lrtd_utc` | T1 | Airline Runway Time of Departure | Virtual Airline |
| `lrta_utc` | T2 | Airline Runway Time of Arrival | Virtual Airline |
| `lgtd_utc` | T3 | Airline Gate Time of Departure | Virtual Airline |
| `lgta_utc` | T4 | Airline Gate Time of Arrival | Virtual Airline |
| `ertd_utc` | T7 | Earliest Runway Time of Departure | Virtual Airline |
| `erta_utc` | T8 | Earliest Runway Time of Arrival | Virtual Airline |
| `out_utc` | T13 | Actual Off-Block (AOBT) | ACARS/VA/sim |
| `off_utc` | T11 | Actual Takeoff (ATOT) | ACARS/VA/sim |
| `on_utc` | T12 | Actual Landing (ALDT) | ACARS/VA/sim |
| `in_utc` | T14 | Actual In-Block (AIBT) | ACARS/VA/sim |
| `edct_utc` | - | Expected Departure Clearance Time | VATCSCC only |
| `ctd_utc` | - | Controlled Time of Departure | VATCSCC only |
| `cta_utc` | - | Controlled Time of Arrival | VATCSCC only |
| `tobt_utc` | - | Target Off-Block Time | VATCSCC only |

**Merge Behaviors:**

| Behavior | Description |
|----------|-------------|
| `priority_based` | Higher priority source always wins (OOOI times) |
| `immutable` | Only authoritative source can write (TMI, schedules) |
| `variable` | Accepts newer timestamps (ETAs, metering) |
| `monotonic` | Rejects older timestamps (position data) |

**Cache TTL:**
```php
$SWIM_CACHE_TTL = [
    'flights_list'   => 5,
    'flight_single'  => 3,
    'positions'      => 2,
    'tmi_programs'   => 10,
    'stats'          => 60
];
```

**Pagination:**
```php
define('SWIM_DEFAULT_PAGE_SIZE', 100);
define('SWIM_MAX_PAGE_SIZE', 1000);
define('SWIM_GEOJSON_PRECISION', 5);
```

### 7.2 WebSocket Server Configuration

```php
$config = [
    'auth_enabled' => true,
    'rate_limit_msg_per_sec' => 10,
    'heartbeat_interval' => 30,
    'max_message_size' => 65536,
    'allowed_origins' => ['*'],
    'debug' => false
];
```

**Tier Connection Limits:**
```php
// WebSocket connection limits (unchanged)
$tierLimits = [
    'public' => 5,
    'developer' => 50,
    'partner' => 500,
    'system' => 10000
];
```

---

## 8. Database Schema

### 8.1 swim_api_keys

Stores API key credentials and permissions.

```sql
CREATE TABLE dbo.swim_api_keys (
    id INT IDENTITY(1,1) PRIMARY KEY,
    api_key NVARCHAR(64) NOT NULL UNIQUE,
    tier NVARCHAR(20) NOT NULL,  -- system, partner, developer, public
    owner_name NVARCHAR(100) NOT NULL,
    owner_email NVARCHAR(255),
    source_id NVARCHAR(50) NULL,
    can_write BIT NOT NULL DEFAULT 0,
    allowed_sources NVARCHAR(MAX) NULL,  -- JSON array
    ip_whitelist NVARCHAR(MAX) NULL,     -- JSON array
    description NVARCHAR(500) NULL,
    expires_at DATETIME2 NULL,
    created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    last_used_at DATETIME2 NULL,
    is_active BIT NOT NULL DEFAULT 1
);
```

### 8.2 swim_audit_log

Request logging for monitoring and debugging.

```sql
CREATE TABLE dbo.swim_audit_log (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    api_key_id INT NULL,
    endpoint NVARCHAR(255) NOT NULL,
    method NVARCHAR(10) NOT NULL,
    ip_address NVARCHAR(45) NOT NULL,
    user_agent NVARCHAR(500) NULL,
    response_status INT NULL,
    response_time_ms INT NULL,
    request_time DATETIME2 NOT NULL DEFAULT GETUTCDATE()
);
```

### 8.3 swim_subscriptions

WebSocket subscription tracking.

```sql
CREATE TABLE dbo.swim_subscriptions (
    id INT IDENTITY(1,1) PRIMARY KEY,
    api_key_id INT NOT NULL,
    connection_id NVARCHAR(64) NOT NULL,
    channels NVARCHAR(MAX) NOT NULL,     -- JSON array
    filters NVARCHAR(MAX) NULL,          -- JSON object
    connected_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    last_ping_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    is_active BIT NOT NULL DEFAULT 1,
    FOREIGN KEY (api_key_id) REFERENCES swim_api_keys(id)
);
```

### 8.4 swim_webhook_endpoints

Webhook registration for push notifications.

```sql
CREATE TABLE dbo.swim_webhook_endpoints (
    id INT IDENTITY(1,1) PRIMARY KEY,
    api_key_id INT NOT NULL,
    endpoint_url NVARCHAR(500) NOT NULL,
    events NVARCHAR(MAX) NOT NULL,       -- JSON array
    secret NVARCHAR(64) NOT NULL,        -- HMAC signing secret
    retry_count INT NOT NULL DEFAULT 3,
    timeout_seconds INT NOT NULL DEFAULT 30,
    last_delivery_at DATETIME2 NULL,
    failure_count INT NOT NULL DEFAULT 0,
    created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    is_active BIT NOT NULL DEFAULT 1,
    FOREIGN KEY (api_key_id) REFERENCES swim_api_keys(id)
);
```

---

## 9. Deployment & Operations

### 9.1 File Structure

```
PERTI/
├── api/swim/v1/
│   ├── index.php              # API index
│   ├── auth.php               # Authentication middleware
│   ├── flights.php            # Flights endpoint
│   ├── flight.php             # Single flight endpoint
│   ├── positions.php          # GeoJSON positions
│   ├── ingest/                # Data ingestion endpoints
│   │   ├── adl.php
│   │   └── track.php
│   ├── tmi/                   # TMI endpoints
│   │   ├── programs.php
│   │   └── controlled.php
│   └── ws/                    # WebSocket components
│       ├── WebSocketServer.php
│       ├── ClientConnection.php
│       ├── SubscriptionManager.php
│       └── swim-ws-client.js
├── scripts/
│   ├── swim_ws_server.php     # WebSocket daemon
│   ├── swim_ws_events.php     # Event detection
│   └── startup.sh             # Azure startup script
├── sdk/python/
│   ├── swim_client/
│   │   ├── __init__.py
│   │   ├── client.py
│   │   └── events.py
│   └── examples/
│       ├── basic_example.py
│       └── airport_monitor.py
├── load/
│   └── swim_config.php        # Configuration
└── database/migrations/swim/
    ├── 001_swim_tables.sql
    └── 002_swim_api_database.sql
```

### 9.2 Starting WebSocket Server

```bash
# Start daemon
nohup php /home/site/wwwroot/scripts/swim_ws_server.php --debug > /home/LogFiles/swim_ws.log 2>&1 &

# Check status
tail -f /home/LogFiles/swim_ws.log

# Restart
pkill -f swim_ws_server
rm -f /home/site/wwwroot/scripts/swim_ws.lock
nohup php /home/site/wwwroot/scripts/swim_ws_server.php --debug > /home/LogFiles/swim_ws.log 2>&1 &
```

### 9.3 Apache WebSocket Proxy

In `startup.sh`:
```bash
# Enable proxy modules
a2enmod proxy proxy_http proxy_wstunnel

# Add to Apache config
<Location /api/swim/v1/ws>
    ProxyPass ws://localhost:8090/
    ProxyPassReverse ws://localhost:8090/
</Location>
```

### 9.4 Monitoring

**Check Connected Clients:**
```bash
curl -H "Authorization: Bearer swim_sys_internal" \
  "https://perti.vatcscc.org/api/swim/v1/ws/stats"
```

**Audit Log Cleanup:**
```sql
EXEC dbo.sp_Swim_CleanupAuditLog @days_to_keep = 90;
```

---

## 10. Implementation Status

### 10.1 Phase Summary

| Phase | Status | Progress |
|-------|--------|----------|
| Phase 0: Infrastructure | ✅ COMPLETE | 100% |
| Phase 1: REST API | ✅ COMPLETE | 100% |
| Phase 2: WebSocket | ✅ COMPLETE | 100% |
| Phase 3: SDKs | 🔨 IN PROGRESS | Python done |

### 10.2 Completed Features

- ✅ Azure SQL Basic database (`SWIM_API`) - $5/month
- ✅ API key authentication with tier-based rate limits
- ✅ All REST endpoints (flights, positions, TMI)
- ✅ OpenAPI/Swagger documentation
- ✅ Postman collection
- ✅ FIXM field naming support
- ✅ GeoJSON position output
- ✅ Ratchet WebSocket server (port 8090)
- ✅ External WSS via Apache proxy
- ✅ Real-time event detection
- ✅ Database authentication with key caching
- ✅ Tier-based connection limits
- ✅ Python SDK with async support

### 10.3 Pending Features

| Feature | Priority | Notes |
|---------|----------|-------|
| C# SDK | As needed | Build when consumers request |
| Java SDK | As needed | Build when consumers request |
| Message compression | Low | Performance optimization |
| Historical replay | Low | Past event retrieval |
| Metrics dashboard | Low | Usage tracking |
| Redis caching | Deferred | File IPC adequate |

### 10.4 Contact

- **Email:** dev@vatcscc.org
- **Discord:** vATCSCC Server
- **Documentation:** https://perti.vatcscc.org/docs/swim/

---

*Document generated from PERTI codebase analysis - January 2026*
