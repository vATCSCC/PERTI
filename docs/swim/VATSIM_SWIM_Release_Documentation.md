# VATSIM SWIM API — Release Documentation

**System Wide Information Management for VATSIM**

**Version:** 1.0.0  
**Release Date:** January 2026  
**Status:** Production Ready  
**Maintained by:** vATCSCC Development Team

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Architecture Overview](#2-architecture-overview)
3. [Getting Started](#3-getting-started)
4. [REST API Reference](#4-rest-api-reference)
5. [WebSocket Real-Time API](#5-websocket-real-time-api)
6. [Data Models](#6-data-models)
7. [Client SDKs](#7-client-sdks)
8. [Use Cases by Role](#8-use-cases-by-role)
9. [Data Authority & CDM Compliance](#9-data-authority--cdm-compliance)
10. [Database Reference](#10-database-reference)
11. [Configuration Reference](#11-configuration-reference)
12. [Troubleshooting](#12-troubleshooting)
13. [Appendices](#13-appendices)

---

## 1. Introduction

### 1.1 What is VATSIM SWIM?

VATSIM SWIM (System Wide Information Management) is a comprehensive data exchange platform that provides programmatic access to real-time and historical flight data from the VATSIM virtual air traffic control network. The API follows FAA SWIM standards and FIXM (Flight Information Exchange Model) conventions to deliver standardized flight information to external consumers.

SWIM serves as the authoritative data hub for the VATSIM ecosystem, enabling consistent Traffic Management Initiative (TMI) implementation, synchronized arrival/departure times, and seamless data exchange between all VATSIM systems.

### 1.2 Key Capabilities

| Capability | Description |
|------------|-------------|
| **REST API** | Query flight data, positions, TMI programs, and controlled flights |
| **WebSocket API** | Real-time event streaming for departures, arrivals, position updates, and TMI changes |
| **Data Ingestion** | Push flight data, track positions, and telemetry from authorized sources |
| **Multiple Output Formats** | JSON, FIXM, XML, GeoJSON, CSV, KML, NDJSON |
| **FIXM Compliance** | Optional FIXM 4.3.0 field naming via `?format=fixm` parameter |
| **Tiered Access** | Four access tiers with appropriate rate limits and permissions |
| **Client SDKs** | Official SDKs for Python, JavaScript/TypeScript, Java, and C# |

### 1.3 API Endpoints

| Base URL | Purpose |
|----------|---------|
| `https://perti.vatcscc.org/api/swim/v1` | REST API |
| `wss://perti.vatcscc.org/api/swim/v1/ws` | WebSocket |

### 1.4 Data Refresh Cycle

SWIM data is refreshed every **15 seconds** from the VATSIM network. The system processes approximately 2,000–6,000 active flights per cycle with sub-second query response times.

---

## 2. Architecture Overview

### 2.1 System Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              VATSIM NETWORK                                 │
│                        (Flight plans, Positions)                            │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼ (every 15 seconds)
┌─────────────────────────────────────────────────────────────────────────────┐
│                           INTERNAL SYSTEMS                                  │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                   VATSIM_ADL Database (Azure SQL)                   │   │
│  │                                                                     │   │
│  │  Normalized Tables:                                                 │   │
│  │  • dbo.adl_flight_core      - Identity, phase, timestamps          │   │
│  │  • dbo.adl_flight_position  - Lat/lon, altitude, speed             │   │
│  │  • dbo.adl_flight_plan      - Route, airports, procedures          │   │
│  │  • dbo.adl_flight_times     - ETAs, OOOI, controlled times         │   │
│  │  • dbo.adl_flight_tmi       - Ground stops, GDP, delays            │   │
│  │  • dbo.adl_flight_aircraft  - Equipment, weight class, airline     │   │
│  └──────────────────────────────┬──────────────────────────────────────┘   │
│                                 │                                           │
│                                 │ (sync via sp_Swim_BulkUpsert)            │
│                                 ▼                                           │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │               SWIM_API Database (Azure SQL Basic - $5/mo)           │   │
│  │                                                                     │   │
│  │  Denormalized Tables:                                               │   │
│  │  • dbo.swim_flights         - Complete flight records (75 columns) │   │
│  │  • dbo.swim_api_keys        - API key credentials and tiers        │   │
│  │  • dbo.swim_audit_log       - Request logging                      │   │
│  │  • dbo.swim_ground_stops    - Cached TMI programs                  │   │
│  └──────────────────────────────┬──────────────────────────────────────┘   │
└─────────────────────────────────┼───────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PUBLIC SWIM API                                   │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │   REST API              │    WebSocket Server                       │   │
│  │   /api/swim/v1/         │    /api/swim/v1/ws                        │   │
│  │   • flights             │    • flight.departed                      │   │
│  │   • flight              │    • flight.arrived                       │   │
│  │   • positions           │    • flight.created                       │   │
│  │   • metering/{airport}  │    • flight.positions                     │   │
│  │   • tmi/programs        │    • tmi.issued                           │   │
│  │   • tmi/controlled      │    • tmi.released                         │   │
│  │   • tmi/reroutes        │    • system.heartbeat                     │   │
│  │   • jatoc/incidents     │                                           │   │
│  │   • splits/presets      │                                           │   │
│  │   • ingest/adl          │                                           │   │
│  │   • ingest/track        │                                           │   │
│  │   • ingest/metering     │                                           │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                  │                                          │
└──────────────────────────────────┼──────────────────────────────────────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    ▼                              ▼
┌─────────────────────────────┐  ┌─────────────────────────────────────────┐
│       ATC SYSTEMS           │  │         THIRD-PARTY CONSUMERS           │
│  • vNAS                     │  │  • Virtual Airlines (AOC systems)       │
│  • CRC                      │  │  • Flight Trackers (SimAware, etc.)     │
│  • EuroScope                │  │  • Analytics Platforms                  │
│  • SimTraffic               │  │  • Discord Bots                         │
└─────────────────────────────┘  └─────────────────────────────────────────┘
```

### 2.2 Database Isolation Strategy

SWIM uses a dedicated Azure SQL Basic database (`SWIM_API`) to serve public API queries. This isolates external traffic from the internal `VATSIM_ADL` Serverless database, providing:

| Benefit | Description |
|---------|-------------|
| **Fixed Cost** | $5/month regardless of query volume (vs. variable Serverless costs) |
| **Performance Isolation** | API load cannot impact internal ADL processing |
| **Optimized Schema** | Denormalized `swim_flights` table eliminates JOINs for reads |

### 2.3 Data Flow

1. **VATSIM API → VATSIM_ADL**: Every 15 seconds via `sp_Adl_RefreshFromVatsim_Normalized`
2. **VATSIM_ADL → SWIM_API**: Every 2 minutes via PHP batch sync calling `sp_Swim_BulkUpsert`
3. **MySQL (PERTI) → SWIM_API**: Ground stops synced every 15 seconds
4. **SWIM_API → Consumers**: REST/WebSocket queries served from dedicated database

---

## 3. Getting Started

### 3.1 Obtaining an API Key

API keys are required for all SWIM API access. Use the self-service API Key Management Portal to request and manage your keys:

**API Key Portal:** [https://perti.vatcscc.org/swim-keys.php](https://perti.vatcscc.org/swim-keys.php)

The portal allows you to:
- Request new API keys (requires VATSIM authentication)
- View your existing keys and usage statistics
- Regenerate or revoke keys
- Update key descriptions and contact information

For questions or to request elevated access tiers (partner/system), contact:
- **Email:** dev@vatcscc.org  
- **Discord:** vATCSCC Server

### 3.2 API Key Tiers

| Tier | Prefix | Rate Limit | WebSocket Connections | Write Access | Use Case |
|------|--------|------------|----------------------|--------------|----------|
| **system** | `swim_sys_` | 30,000/min | 10,000 | Yes | Trusted systems (vNAS, CRC, SimTraffic) |
| **partner** | `swim_par_` | 3,000/min | 500 | Limited | Integration partners (Virtual Airlines) |
| **developer** | `swim_dev_` | 300/min | 50 | No | Development and testing |
| **public** | `swim_pub_` | 100/min | 5 | No | Public consumers |

### 3.3 Authentication

#### HTTP Header (Recommended)

```http
Authorization: Bearer swim_dev_your_key_here
```

#### Query Parameter (WebSocket)

```
wss://perti.vatcscc.org/api/swim/v1/ws?api_key=swim_dev_your_key_here
```

### 3.4 Quick Start Examples

#### cURL: Get Active Flights to JFK

```bash
curl -H "Authorization: Bearer swim_dev_your_key" \
  "https://perti.vatcscc.org/api/swim/v1/flights?dest_icao=KJFK&status=active"
```

#### Python: Monitor Airport Departures

```python
from swim_client import SWIMClient

client = SWIMClient('swim_dev_your_key')

@client.on('flight.departed')
def on_departure(event, timestamp):
    print(f"{event.callsign} departed {event.dep} → {event.arr}")

client.subscribe(['flight.departed'], airports=['KJFK', 'KLGA', 'KEWR'])
client.run()
```

#### JavaScript: Fetch GeoJSON Positions

```javascript
const response = await fetch(
  'https://perti.vatcscc.org/api/swim/v1/positions?artcc=ZNY',
  { headers: { 'Authorization': 'Bearer swim_dev_your_key' } }
);
const geojson = await response.json();
// Ready for MapLibre/Leaflet/Mapbox
```

---

## 4. REST API Reference

### 4.1 Common Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `format` | string | `legacy` (default) or `fixm` for FIXM 4.3.0 field names |
| `page` | int | Page number (default: 1) |
| `per_page` | int | Results per page (default: 100, max: 1000) |

### 4.2 Response Format

All responses follow this structure:

```json
{
  "success": true,
  "data": [ ... ],
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

Error responses:

```json
{
  "success": false,
  "error": {
    "code": "AUTH_FAILED",
    "message": "Invalid or expired API key"
  },
  "timestamp": "2026-01-16T18:30:00Z"
}
```

---

### 4.3 GET /api/swim/v1

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

---

### 4.4 GET /api/swim/v1/flights

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
| `phase` | string | Comma-separated flight phases (e.g., `ENROUTE,DESCENDING`) |
| `format` | string | Response format: `json` (default), `fixm`, `xml`, `geojson`, `csv`, `kml`, `ndjson` |

**Supported Formats:**

| Format | Content-Type | Description |
|--------|--------------|-------------|
| `json` | `application/json` | Standard JSON with snake_case fields (default) |
| `fixm` | `application/json` | JSON with FIXM 4.3.0 camelCase field names |
| `xml` | `application/xml` | XML format for enterprise/SOAP integrations |
| `geojson` | `application/geo+json` | GeoJSON FeatureCollection for mapping |
| `csv` | `text/csv` | CSV for spreadsheet/analytics export |
| `kml` | `application/vnd.google-earth.kml+xml` | KML for Google Earth visualization |
| `ndjson` | `application/x-ndjson` | Newline-delimited JSON for streaming |

**Example Request:**

```bash
curl -H "Authorization: Bearer swim_dev_test" \
  "https://perti.vatcscc.org/api/swim/v1/flights?dest_icao=KJFK&status=active&per_page=50"
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
        "aircraft_faa": "B738/L",
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
        "remarks": "/v/",
        "flight_rules": "I",
        "departure_artcc": "ZLA",
        "destination_artcc": "ZNY",
        "departure_tracon": "SCT",
        "destination_tracon": "N90",
        "departure_fix": "DOTSS",
        "departure_procedure": "DOTSS5",
        "arrival_fix": "LENDY",
        "arrival_procedure": "LENDY5",
        "departure_runway": "25R",
        "arrival_runway": "22L"
      },
      "position": {
        "latitude": 40.1234,
        "longitude": -74.5678,
        "altitude_ft": 35000,
        "heading": 85,
        "ground_speed_kts": 480,
        "true_airspeed_kts": 465,
        "vertical_rate_fpm": 0,
        "current_artcc": "ZNY",
        "current_tracon": null,
        "current_zone": null
      },
      "progress": {
        "phase": "ENROUTE",
        "is_active": true,
        "distance_remaining_nm": 125.4,
        "distance_flown_nm": 2156.8,
        "gcd_nm": 2145.2,
        "route_total_nm": 2282.2,
        "pct_complete": 94.5,
        "time_to_dest_min": 15.7
      },
      "times": {
        "etd": null,
        "etd_runway": null,
        "eta": "2026-01-16T18:45:00Z",
        "eta_runway": "2026-01-16T18:52:00Z",
        "eta_source": "calculated",
        "eta_method": "route_gcd_blend",
        "ete_minutes": 285,
        "out": "2026-01-16T14:05:00Z",
        "off": "2026-01-16T14:18:00Z",
        "on": null,
        "in": null,
        "ctd": null,
        "cta": null,
        "edct": null
      },
      "tmi": {
        "is_controlled": false,
        "ground_stop_held": false,
        "gs_release": null,
        "control_type": null,
        "control_program": null,
        "control_element": null,
        "is_exempt": false,
        "exempt_reason": null,
        "delay_minutes": null,
        "delay_status": null,
        "slot_time": null,
        "program_id": null,
        "slot_id": null
      },
      "_source": "vatcscc",
      "_first_seen": "2026-01-16T14:03:22Z",
      "_last_seen": "2026-01-16T18:30:15Z",
      "_logon_time": "2026-01-16T14:00:00Z",
      "_last_sync": "2026-01-16T18:30:00Z"
    }
  ],
  "pagination": {
    "total": 156,
    "page": 1,
    "per_page": 50,
    "total_pages": 4,
    "has_more": true
  },
  "timestamp": "2026-01-16T18:30:00Z"
}
```

---

### 4.5 GET /api/swim/v1/flight

Returns a single flight by GUFI or flight_key.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `gufi` | string | Globally Unique Flight Identifier |
| `flight_key` | string | ADL flight key |
| `format` | string | `legacy` or `fixm` |

**Example:**

```bash
curl -H "Authorization: Bearer swim_dev_test" \
  "https://perti.vatcscc.org/api/swim/v1/flight?gufi=VAT-20260116-UAL123-KLAX-KJFK"
```

---

### 4.6 GET /api/swim/v1/positions

Returns bulk flight positions in GeoJSON FeatureCollection format, suitable for direct rendering on web maps.

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

**Example Request:**

```bash
curl -H "Authorization: Bearer swim_dev_test" \
  "https://perti.vatcscc.org/api/swim/v1/positions?artcc=ZNY&bounds=-76,39,-72,42"
```

**Response:**

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
        "vertical_rate": 0,
        "distance_remaining_nm": 125.4,
        "tmi_status": "none"
      }
    }
  ],
  "metadata": {
    "count": 247,
    "timestamp": "2026-01-16T18:30:00Z",
    "source": "vatcscc",
    "bounds": {
      "min_lon": -76,
      "min_lat": 39,
      "max_lon": -72,
      "max_lat": 42
    }
  }
}
```

---

### 4.7 GET /api/swim/v1/tmi/programs

Returns active Traffic Management Initiatives (Ground Stops, Ground Delay Programs).

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `all` (default), `gs`, or `gdp` |
| `airport` | string | Airport ICAO filter |
| `artcc` | string | ARTCC filter |
| `include_history` | boolean | Include recently ended programs |

**Response:**

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
        "scope": {
          "origin_centers": ["ZDC", "ZBW", "ZOB"],
          "origin_airports": null
        },
        "advisory_number": "GS-JFK-001",
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
        "rates": {
          "program_rate": 40,
          "default_rate": 52
        },
        "delays": {
          "limit_minutes": 90,
          "average_minutes": 45,
          "maximum_minutes": 87
        },
        "flights": {
          "total": 156,
          "affected": 89,
          "exempt": 12
        },
        "times": {
          "start": "2026-01-16T15:00:00Z",
          "end": "2026-01-16T21:00:00Z"
        },
        "is_active": true
      }
    ],
    "summary": {
      "active_ground_stops": 1,
      "active_gdp_programs": 1,
      "total_controlled_airports": 2
    }
  },
  "timestamp": "2026-01-16T18:30:00Z"
}
```

---

### 4.8 GET /api/swim/v1/tmi/controlled

Returns flights currently under TMI control.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `airport` | string | Filter by affected airport |
| `type` | string | `gs`, `gdp`, or `all` |
| `include_exempt` | boolean | Include exempt flights |

---

### 4.9 POST /api/swim/v1/ingest/adl

**Requires:** `system` or `partner` tier with write access

Receives flight data from authoritative sources. Maximum batch size: 500 flights.

**Request Body:**

```json
{
  "flights": [
    {
      "callsign": "VPA123",
      "dept_icao": "KJFK",
      "dest_icao": "KLAX",
      "cid": 1234567,
      "aircraft_type": "B738",
      "route": "DCT JFK J584 ORD J64 LAX",
      "phase": "ENROUTE",
      "is_active": true,
      "latitude": 40.1234,
      "longitude": -98.5678,
      "altitude_ft": 35000,
      "heading_deg": 270,
      "groundspeed_kts": 450,
      "vertical_rate_fpm": 0,
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
  "timestamp": "2026-01-16T18:30:00Z",
  "meta": {
    "source": "virtual_airline",
    "batch_size": 1
  }
}
```

---

### 4.10 POST /api/swim/v1/ingest/track

**Requires:** `system` or `partner` tier with write access

Receives real-time track/position updates from ATC automation systems. Maximum batch size: 1000 tracks.

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

**Track Source Values:**

| Value | Description |
|-------|-------------|
| `radar` | Primary/secondary radar |
| `ads-b` | ADS-B surveillance |
| `mlat` | Multilateration |
| `mode-s` | Mode S transponder |
| `acars` | ACARS position report |

---

## 5. WebSocket Real-Time API

### 5.1 Connecting

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

### 5.2 Subscribing to Channels

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
| `flight.departed` | Aircraft wheels-up (OFF time detected) |
| `flight.arrived` | Aircraft wheels-down (IN time detected) |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Batched position updates (every 15 sec) |
| `flight.*` | All flight events |
| `tmi.issued` | New Ground Stop/GDP created |
| `tmi.released` | TMI ended/released |
| `tmi.*` | All TMI events |
| `system.heartbeat` | Server keepalive (30 sec interval) |

**Subscription Filters:**

| Filter | Type | Description |
|--------|------|-------------|
| `airports` | array | ICAO codes for departure/destination |
| `artccs` | array | ARTCC identifiers |
| `callsign_prefix` | array | Callsign prefixes (e.g., `["UAL", "DAL"]`) |
| `bbox` | object | Geographic bounding box `{north, south, east, west}` |

### 5.3 Event Formats

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
    "off_utc": "2026-01-16T18:30:00Z",
    "aircraft": "B738"
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
        "arr": "KJFK",
        "phase": "ENROUTE"
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

### 5.4 Client Actions

| Action | Request | Description |
|--------|---------|-------------|
| Ping | `{"action": "ping"}` | Server responds with `{"type": "pong"}` |
| Status | `{"action": "status"}` | Returns current subscriptions and stats |
| Unsubscribe | `{"action": "unsubscribe", "channels": [...]}` | Remove channel subscriptions |

### 5.5 Error Codes

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

## 6. Data Models

### 6.1 GUFI (Globally Unique Flight Identifier)

**Format:** `VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`

**Example:** `VAT-20260116-UAL123-KLAX-KJFK`

The GUFI is computed in the `SWIM_API` database as a persisted computed column on `dbo.swim_flights`:

```sql
gufi AS ('VAT-' + FORMAT(COALESCE(first_seen_utc, GETUTCDATE()), 'yyyyMMdd') 
        + '-' + callsign + '-' + ISNULL(fp_dept_icao, 'XXXX') 
        + '-' + ISNULL(fp_dest_icao, 'XXXX')) PERSISTED
```

### 6.2 Flight Phases

| Phase | Description |
|-------|-------------|
| `PREFLIGHT` | Connected, not yet departed |
| `DEPARTING` | Taxiing for departure |
| `CLIMBING` | Airborne, climbing |
| `ENROUTE` | Cruise altitude |
| `DESCENDING` | Descending to destination |
| `APPROACH` | On approach |
| `LANDED` | Wheels down, taxiing in |
| `ARRIVED` | At gate/parked |

### 6.3 TMI Control Types

| Type | Description |
|------|-------------|
| `GS` | Ground Stop |
| `GDP` | Ground Delay Program |
| `MIT` | Miles-in-Trail |
| `MINIT` | Minutes-in-Trail |
| `AFP` | Airspace Flow Program |

### 6.4 OOOI Times (Out/Off/On/In)

SWIM tracks four critical flight milestone times aligned with FAA CDM specifications:

| Field | CDM Reference | Description | Database Column |
|-------|--------------|-------------|-----------------|
| OUT | T13 | Actual Off-Block (pushback) | `out_utc` |
| OFF | T11 | Actual Takeoff (wheels-up) | `off_utc` |
| ON | T12 | Actual Landing (wheels-down) | `on_utc` |
| IN | T14 | Actual In-Block (gate arrival) | `in_utc` |

### 6.5 FIXM Field Mapping

The `?format=fixm` parameter returns FIXM 4.3.0 aligned field names:

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

## 7. Client SDKs

### 7.1 Python SDK

**Location:** `PERTI/sdk/python/`

**Installation:**

```bash
cd sdk/python
pip install -e .
# Or: pip install websockets
```

**Basic Usage:**

```python
from swim_client import SWIMClient

client = SWIMClient('swim_dev_your_key', debug=True)

@client.on('connected')
def on_connected(info, timestamp):
    print(f"Connected! Client ID: {info.client_id}")

@client.on('flight.departed')
def on_departure(event, timestamp):
    print(f"{event.callsign} departed {event.dep}")

@client.on('flight.arrived')  
def on_arrival(event, timestamp):
    print(f"{event.callsign} arrived at {event.arr}")

client.subscribe(['flight.departed', 'flight.arrived'])
client.run()
```

**Async Usage:**

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

**SDK Configuration:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `api_key` | required | API key for authentication |
| `url` | `wss://perti.vatcscc.org/...` | WebSocket URL |
| `reconnect` | `True` | Auto-reconnect on disconnect |
| `reconnect_interval` | `5.0` | Initial reconnect delay (seconds) |
| `max_reconnect_interval` | `60.0` | Maximum reconnect delay |
| `ping_interval` | `30.0` | Ping interval (seconds) |
| `debug` | `False` | Enable debug logging |

**Example Scripts:**

| Script | Description |
|--------|-------------|
| `examples/basic_example.py` | Simple connection and event handling |
| `examples/airport_monitor.py` | Monitor specific airport traffic |
| `examples/tmi_monitor.py` | Track TMI issuances and releases |
| `examples/discord_bot.py` | Discord integration example |
| `examples/data_export_pipeline.py` | Export flight data to CSV/JSON |

---

### 7.2 JavaScript/TypeScript SDK

**Location:** `PERTI/sdk/javascript/`

**Installation:**

```bash
npm install
```

**Usage:**

```typescript
import { SwimClient } from './src';

const client = new SwimClient('swim_dev_your_key');

client.on('flight.departed', (event) => {
  console.log(`${event.callsign} departed ${event.dep}`);
});

client.subscribe(['flight.departed', 'flight.arrived']);
client.connect();
```

---

### 7.3 Java SDK

**Location:** `PERTI/sdk/java/swim-client/`

**Maven:**

```xml
<dependency>
  <groupId>org.vatsim.swim</groupId>
  <artifactId>swim-client</artifactId>
  <version>1.0.0</version>
</dependency>
```

**Usage:**

```java
import org.vatsim.swim.SwimWebSocketClient;

SwimWebSocketClient client = new SwimWebSocketClient("swim_dev_your_key");

client.onDeparture(event -> {
    System.out.println(event.getCallsign() + " departed " + event.getDeparture());
});

client.subscribe(Arrays.asList("flight.departed", "flight.arrived"));
client.connect();
```

---

### 7.4 C# SDK

**Location:** `PERTI/sdk/csharp/SwimClient/`

**NuGet:**

```bash
dotnet add package VatsimSwim.Client
```

**Usage:**

```csharp
using VatsimSwim;

var client = new SwimWebSocketClient("swim_dev_your_key");

client.OnDeparture += (sender, e) => {
    Console.WriteLine($"{e.Callsign} departed {e.Departure}");
};

await client.SubscribeAsync(new[] { "flight.departed", "flight.arrived" });
await client.ConnectAsync();
```

---

## 8. Use Cases by Role

### 8.1 Virtual Airlines

Virtual Airlines can integrate SWIM to enhance their operations:

**Read Access (developer tier):**
- Real-time fleet tracking via WebSocket `flight.positions`
- Departure/arrival notifications for pilot PIREPs
- TMI awareness for operational planning
- Flight progress monitoring for dispatch

**Write Access (partner tier):**
- Push OOOI times from ACARS/AOC systems via `/ingest/adl`
- Update ETAs from dispatch systems
- Provide schedule data (STD/STA) per CDM T1-T4 specifications

**Example: Fleet Tracker**

```python
from swim_client import SWIMClient

AIRLINE_PREFIX = 'VPA'  # Your airline's callsign prefix

client = SWIMClient('swim_par_your_airline_key')

@client.on('flight.departed')
def track_departure(event, ts):
    if event.callsign.startswith(AIRLINE_PREFIX):
        # Log to your airline's dispatch system
        log_pirep_departure(event.callsign, event.dep, event.off_utc)

@client.on('flight.arrived')
def track_arrival(event, ts):
    if event.callsign.startswith(AIRLINE_PREFIX):
        log_pirep_arrival(event.callsign, event.arr, event.in_utc)

client.subscribe(
    ['flight.departed', 'flight.arrived'],
    callsign_prefix=[AIRLINE_PREFIX]
)
client.run()
```

---

### 8.2 ATC Client Developers

ATC client developers (CRC, EuroScope plugins, vNAS) can use SWIM for:

**Read Access:**
- Flight data for display rendering
- TMI status for tag annotations
- Position data for traffic displays

**Write Access (system tier):**
- Push track data from radar simulation
- Update handoff times and clearances
- Provide metering data from TBFM-style systems

**Example: CRC Plugin Data Feed**

```csharp
// Push track positions every second
var tracks = GetAllTrackedFlights().Select(f => new {
    callsign = f.Callsign,
    latitude = f.Position.Latitude,
    longitude = f.Position.Longitude,
    altitude_ft = f.Altitude,
    ground_speed_kts = f.GroundSpeed,
    heading_deg = f.Heading,
    track_source = "radar"
}).ToList();

await swimClient.PostAsync("/api/swim/v1/ingest/track", new { tracks });
```

---

### 8.3 Third-Party Application Developers

Build flight trackers, analytics tools, or integration services:

**Flight Tracking Application:**

```javascript
// Fetch positions for map display
async function updateMap() {
  const response = await fetch(
    'https://perti.vatcscc.org/api/swim/v1/positions',
    { headers: { 'Authorization': `Bearer ${API_KEY}` } }
  );
  const geojson = await response.json();
  
  // Update MapLibre source
  map.getSource('flights').setData(geojson);
}

// Update every 15 seconds
setInterval(updateMap, 15000);
```

**Discord Bot:**

```python
import discord
from swim_client import SWIMClient

bot = discord.Bot()
swim = SWIMClient('swim_dev_your_key')

@swim.on('tmi.issued')
async def announce_tmi(event, ts):
    channel = bot.get_channel(TMI_CHANNEL_ID)
    await channel.send(
        f"⚠️ **{event.program_type}** issued for **{event.airport}**\n"
        f"Reason: {event.reason}\n"
        f"Until: {event.end_time}"
    )

swim.subscribe(['tmi.issued', 'tmi.released'])
```

---

### 8.4 TMU/ATCSCC Staff

Traffic Management personnel can programmatically access TMI data:

**Monitor Controlled Flights:**

```python
import requests

def get_controlled_flights(airport):
    response = requests.get(
        f'https://perti.vatcscc.org/api/swim/v1/tmi/controlled',
        headers={'Authorization': f'Bearer {API_KEY}'},
        params={'airport': airport}
    )
    return response.json()['data']

# Get all flights affected by JFK ground stop
jfk_held = get_controlled_flights('KJFK')
for flight in jfk_held:
    print(f"{flight['callsign']}: delay {flight['delay_minutes']}min")
```

---

## 9. Data Authority & CDM Compliance

### 9.1 Data Source Hierarchy

SWIM implements FAA CDM (Collaborative Decision Making) specifications for data authority:

| Data Type | Primary Source | Can Override |
|-----------|---------------|--------------|
| Identity (`callsign`, `cid`) | VATSIM | No |
| Flight Plan | VATSIM | No |
| TMI (`gs_held`, `edct_utc`, `slot_time_utc`) | vATCSCC | No |
| Track Position | vNAS → CRC → EuroScope → simulator | Yes |
| OOOI Times | ACARS → Virtual Airline → simulator | Yes |
| Schedule (STD/STA) | Virtual Airline → SimBrief | Yes |
| Metering | SimTraffic → vATCSCC → vNAS | Yes |

### 9.2 Source Priority Rankings

When multiple sources provide the same data, priority determines which value is accepted:

**OOOI Times (T11-T14):**
1. `acars` / `hoppie` (priority 1)
2. `virtual_airline` (priority 2)
3. `simulator` (priority 3)
4. `vatcscc` (priority 4)

**Track Positions:**
1. `vnas` (priority 1)
2. `crc` (priority 2)
3. `euroscope` (priority 3)
4. `simulator` (priority 4)
5. `acars` (priority 5)

### 9.3 Field Merge Behaviors

| Behavior | Description | Example Fields |
|----------|-------------|----------------|
| `monotonic` | Reject older timestamps | `lat`, `lon`, `altitude_ft` |
| `variable` | Accept newer timestamps | `eta_utc`, `delay_minutes` |
| `priority_based` | Higher priority source wins | `out_utc`, `off_utc`, `on_utc`, `in_utc` |
| `immutable` | Only authoritative source can write | `gs_held`, `ctl_type`, `edct_utc` |
| `latest` | Last write wins | `fp_route`, `aircraft_type` |

### 9.4 CDM Time Field Reference

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
| `edct_utc` | - | Expected Departure Clearance Time | vATCSCC only |
| `ctd_utc` | - | Controlled Time of Departure | vATCSCC only |
| `cta_utc` | - | Controlled Time of Arrival | vATCSCC only |
| `tobt_utc` | - | Target Off-Block Time | vATCSCC only |

---

## 10. Database Reference

### 10.1 Connection Configuration

Database connections are established in `PERTI/load/connect.php`:

```php
// Primary Website Database (MySQL)
$conn_pdo    // PDO connection to MySQL (PERTI app data)
$conn_sqli   // MySQLi connection to MySQL

// ADL Database (Azure SQL Serverless) - Internal
$conn_adl    // sqlsrv connection to VATSIM_ADL

// SWIM API Database (Azure SQL Basic) - Public API
$conn_swim   // sqlsrv connection to SWIM_API
```

Connection credentials are defined in `PERTI/load/config.php`:

```php
// MySQL (PERTI)
define("SQL_HOST", "...");
define("SQL_DATABASE", "...");
define("SQL_USERNAME", "...");
define("SQL_PASSWORD", "...");

// Azure SQL - ADL (Internal)
define("ADL_SQL_HOST", "...");
define("ADL_SQL_DATABASE", "VATSIM_ADL");
define("ADL_SQL_USERNAME", "...");
define("ADL_SQL_PASSWORD", "...");

// Azure SQL - SWIM API (Public)
define("SWIM_SQL_HOST", "...");
define("SWIM_SQL_DATABASE", "SWIM_API");
define("SWIM_SQL_USERNAME", "...");
define("SWIM_SQL_PASSWORD", "...");
```

### 10.2 SWIM_API Database Schema

#### dbo.swim_flights

The primary denormalized flight table (75 columns):

```sql
CREATE TABLE dbo.swim_flights (
    -- Primary Key
    flight_uid BIGINT NOT NULL PRIMARY KEY,
    flight_key NVARCHAR(64) NULL,
    
    -- GUFI (Computed)
    gufi AS ('VAT-' + FORMAT(COALESCE(first_seen_utc, GETUTCDATE()), 'yyyyMMdd') 
            + '-' + callsign + '-' + ISNULL(fp_dept_icao, 'XXXX') 
            + '-' + ISNULL(fp_dest_icao, 'XXXX')) PERSISTED,
    
    -- Identity
    callsign NVARCHAR(16) NOT NULL,
    cid INT NULL,
    flight_id NVARCHAR(32) NULL,
    
    -- Position
    lat DECIMAL(9,6) NULL,
    lon DECIMAL(10,6) NULL,
    altitude_ft INT NULL,
    heading_deg SMALLINT NULL,
    groundspeed_kts INT NULL,
    vertical_rate_fpm INT NULL,
    
    -- Flight Plan
    fp_dept_icao CHAR(4) NULL,
    fp_dest_icao CHAR(4) NULL,
    fp_alt_icao CHAR(4) NULL,
    fp_altitude_ft INT NULL,
    fp_tas_kts INT NULL,
    fp_route NVARCHAR(MAX) NULL,
    fp_remarks NVARCHAR(MAX) NULL,
    fp_rule NCHAR(1) NULL,
    fp_dept_artcc NVARCHAR(8) NULL,
    fp_dest_artcc NVARCHAR(8) NULL,
    fp_dept_tracon NVARCHAR(64) NULL,
    fp_dest_tracon NVARCHAR(64) NULL,
    
    -- Procedures
    dfix NVARCHAR(8) NULL,              -- Departure fix
    dp_name NVARCHAR(16) NULL,          -- SID name
    afix NVARCHAR(8) NULL,              -- Arrival fix
    star_name NVARCHAR(16) NULL,        -- STAR name
    dep_runway NVARCHAR(4) NULL,
    arr_runway NVARCHAR(4) NULL,
    
    -- Progress
    phase NVARCHAR(16) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    dist_to_dest_nm DECIMAL(10,2) NULL,
    dist_flown_nm DECIMAL(10,2) NULL,
    pct_complete DECIMAL(5,2) NULL,
    gcd_nm DECIMAL(10,2) NULL,
    route_total_nm DECIMAL(10,2) NULL,
    
    -- Airspace
    current_artcc NVARCHAR(16) NULL,
    current_tracon NVARCHAR(32) NULL,
    current_zone NVARCHAR(16) NULL,
    
    -- Times
    first_seen_utc DATETIME2 NULL,
    last_seen_utc DATETIME2 NULL,
    logon_time_utc DATETIME2 NULL,
    eta_utc DATETIME2 NULL,
    eta_runway_utc DATETIME2 NULL,
    eta_source NVARCHAR(16) NULL,
    eta_method NVARCHAR(16) NULL,
    etd_utc DATETIME2 NULL,
    out_utc DATETIME2 NULL,
    off_utc DATETIME2 NULL,
    on_utc DATETIME2 NULL,
    in_utc DATETIME2 NULL,
    ete_minutes INT NULL,
    
    -- Controlled Times
    ctd_utc DATETIME2 NULL,
    cta_utc DATETIME2 NULL,
    edct_utc DATETIME2 NULL,
    
    -- TMI
    gs_held BIT NULL DEFAULT 0,
    gs_release_utc DATETIME2 NULL,
    ctl_type NVARCHAR(8) NULL,
    ctl_prgm NVARCHAR(32) NULL,
    ctl_element NVARCHAR(8) NULL,
    is_exempt BIT NULL DEFAULT 0,
    exempt_reason NVARCHAR(64) NULL,
    slot_time_utc DATETIME2 NULL,
    slot_status NVARCHAR(16) NULL,
    program_id INT NULL,
    slot_id BIGINT NULL,
    delay_minutes INT NULL,
    delay_status NVARCHAR(16) NULL,
    
    -- Aircraft
    aircraft_type NVARCHAR(8) NULL,
    aircraft_icao NVARCHAR(8) NULL,
    aircraft_faa NVARCHAR(16) NULL,
    weight_class NCHAR(1) NULL,
    wake_category NVARCHAR(8) NULL,
    engine_type NVARCHAR(8) NULL,
    airline_icao NVARCHAR(4) NULL,
    airline_name NVARCHAR(64) NULL,
    
    -- Sync Metadata
    last_sync_utc DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    sync_source NVARCHAR(16) NOT NULL DEFAULT 'ADL'
);
```

**Indexes:**

```sql
CREATE INDEX IX_swim_flights_active ON dbo.swim_flights (is_active, callsign);
CREATE INDEX IX_swim_flights_dept ON dbo.swim_flights (fp_dept_icao) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_dest ON dbo.swim_flights (fp_dest_icao) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_dest_artcc ON dbo.swim_flights (fp_dest_artcc) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_phase ON dbo.swim_flights (phase) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_tmi ON dbo.swim_flights (gs_held, ctl_type) WHERE is_active = 1;
CREATE INDEX IX_swim_flights_position ON dbo.swim_flights (lat, lon) WHERE is_active = 1 AND lat IS NOT NULL;
```

#### dbo.swim_api_keys

```sql
CREATE TABLE dbo.swim_api_keys (
    id INT IDENTITY(1,1) PRIMARY KEY,
    api_key NVARCHAR(128) NOT NULL UNIQUE,
    tier NVARCHAR(16) NOT NULL DEFAULT 'public',  -- system, partner, developer, public
    owner_name NVARCHAR(128) NULL,
    owner_email NVARCHAR(256) NULL,
    source_id NVARCHAR(32) NULL,                  -- Data source identifier
    can_write BIT NOT NULL DEFAULT 0,
    allowed_sources NVARCHAR(MAX) NULL,           -- JSON array
    ip_whitelist NVARCHAR(MAX) NULL,              -- JSON array
    expires_at DATETIME2 NULL,
    created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    last_used_at DATETIME2 NULL,
    is_active BIT NOT NULL DEFAULT 1,
    
    CONSTRAINT CHK_swim_api_keys_tier CHECK (tier IN ('system', 'partner', 'developer', 'public'))
);
```

#### dbo.swim_audit_log

```sql
CREATE TABLE dbo.swim_audit_log (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    api_key_id INT NULL,
    endpoint NVARCHAR(256) NOT NULL,
    method NVARCHAR(8) NOT NULL,
    ip_address NVARCHAR(64) NULL,
    user_agent NVARCHAR(512) NULL,
    request_time DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    response_code INT NULL,
    response_time_ms INT NULL,
    request_params NVARCHAR(MAX) NULL
);
```

### 10.3 VATSIM_ADL Normalized Tables

The internal `VATSIM_ADL` database uses normalized tables:

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `dbo.adl_flight_core` | Identity, phase, timestamps | `flight_uid`, `callsign`, `cid`, `phase`, `is_active` |
| `dbo.adl_flight_position` | Current position | `lat`, `lon`, `altitude_ft`, `heading_deg`, `groundspeed_kts` |
| `dbo.adl_flight_plan` | Flight plan data | `fp_dept_icao`, `fp_dest_icao`, `fp_route`, `fp_altitude_ft` |
| `dbo.adl_flight_times` | ETAs and OOOI | `eta_utc`, `out_utc`, `off_utc`, `on_utc`, `in_utc` |
| `dbo.adl_flight_tmi` | TMI status | `gs_held`, `ctl_type`, `slot_time_utc`, `delay_minutes` |
| `dbo.adl_flight_aircraft` | Equipment info | `aircraft_icao`, `weight_class`, `airline_icao` |

---

## 11. Configuration Reference

### 11.1 swim_config.php

Located at `PERTI/load/swim_config.php`:

```php
// API Version
define('SWIM_API_VERSION', '1.0.0');
define('SWIM_API_PREFIX', '/api/swim/v1');

// Rate Limits (requests per minute)
$SWIM_RATE_LIMITS = [
    'system'    => 30000,
    'partner'   => 3000,
    'developer' => 300,
    'public'    => 100
];

// API Key Prefixes
$SWIM_KEY_PREFIXES = [
    'system'    => 'swim_sys_',
    'partner'   => 'swim_par_',
    'developer' => 'swim_dev_',
    'public'    => 'swim_pub_'
];

// Cache TTL (seconds)
$SWIM_CACHE_TTL = [
    'flights_list'   => 5,
    'flight_single'  => 3,
    'positions'      => 2,
    'tmi_programs'   => 10,
    'stats'          => 60
];

// Pagination
define('SWIM_DEFAULT_PAGE_SIZE', 100);
define('SWIM_MAX_PAGE_SIZE', 1000);
define('SWIM_GEOJSON_PRECISION', 5);

// GUFI
define('SWIM_GUFI_PREFIX', 'VAT');
define('SWIM_GUFI_SEPARATOR', '-');

// Timestamp tolerance for merge decisions
define('SWIM_TIMESTAMP_TOLERANCE', 5);  // seconds
```

### 11.2 Data Sources

```php
$SWIM_DATA_SOURCES = [
    // Core sources
    'VATSIM'          => 'vatsim',
    'VATCSCC'         => 'vatcscc',
    
    // Track/position sources
    'VNAS'            => 'vnas',
    'CRC'             => 'crc',
    'EUROSCOPE'       => 'euroscope',
    
    // Pilot sources
    'SIMULATOR'       => 'simulator',
    
    // ACARS sources
    'ACARS'           => 'acars',
    'HOPPIE'          => 'hoppie',
    
    // Metering sources
    'SIMTRAFFIC'      => 'simtraffic',
    'TOPSKY'          => 'topsky',
    
    // External sources
    'SIMBRIEF'        => 'simbrief',
    'VIRTUAL_AIRLINE' => 'virtual_airline',
];
```

### 11.3 WebSocket Server Configuration

```php
$config = [
    'auth_enabled'          => true,
    'rate_limit_msg_per_sec' => 10,
    'heartbeat_interval'    => 30,
    'max_message_size'      => 65536,
    'allowed_origins'       => ['*'],
    'debug'                 => false
];

// Tier connection limits
$tierLimits = [
    'public'    => 5,
    'developer' => 50,
    'partner'   => 500,
    'system'    => 10000  // WebSocket connection limits (unchanged)
];
```

---

## 12. Troubleshooting

### 12.1 Common Error Codes

| HTTP Code | Error Code | Description | Resolution |
|-----------|------------|-------------|------------|
| 401 | `AUTH_FAILED` | Invalid or missing API key | Verify key is correct and active |
| 403 | `WRITE_FORBIDDEN` | Write access not permitted | Request partner/system tier |
| 429 | `RATE_LIMITED` | Rate limit exceeded | Reduce request frequency |
| 500 | `DB_ERROR` | Database error | Contact support |
| 503 | `SERVICE_UNAVAILABLE` | Database connection failed | Retry after delay |

### 12.2 WebSocket Disconnections

**Symptoms:** Connection drops frequently

**Solutions:**
1. Implement ping/pong every 30 seconds
2. Handle reconnection with exponential backoff
3. Check tier connection limits

```python
# Python reconnection example
client = SWIMClient(
    api_key,
    reconnect=True,
    reconnect_interval=5.0,
    max_reconnect_interval=60.0
)
```

### 12.3 Stale Data

**Symptoms:** Data appears outdated

**Explanation:** SWIM_API syncs from VATSIM_ADL every 2 minutes. Maximum data staleness is ~2.5 minutes.

**Verification:**
- Check `_last_sync` field in response
- Compare `timestamp` in response metadata

### 12.4 Debug Endpoints

Check API status:

```bash
curl -H "Authorization: Bearer swim_sys_internal" \
  "https://perti.vatcscc.org/api/swim/v1"
```

WebSocket server stats (system tier):

```bash
curl -H "Authorization: Bearer swim_sys_internal" \
  "https://perti.vatcscc.org/api/swim/v1/ws/stats"
```

---

## 13. Appendices

### 13.1 File Structure

```
PERTI/
├── api/swim/v1/
│   ├── index.php              # API index
│   ├── auth.php               # Authentication middleware
│   ├── flights.php            # Flights list endpoint
│   ├── flight.php             # Single flight endpoint
│   ├── positions.php          # GeoJSON positions
│   ├── ingest/
│   │   ├── adl.php            # ADL data ingestion
│   │   └── track.php          # Track position ingestion
│   ├── tmi/
│   │   ├── programs.php       # TMI programs endpoint
│   │   └── controlled.php     # Controlled flights endpoint
│   └── ws/
│       ├── WebSocketServer.php
│       ├── ClientConnection.php
│       └── SubscriptionManager.php
├── sdk/
│   ├── python/
│   │   ├── swim_client/
│   │   └── examples/
│   ├── javascript/
│   ├── java/
│   └── csharp/
├── scripts/
│   ├── swim_ws_server.php     # WebSocket daemon
│   ├── swim_ws_events.php     # Event detection
│   └── swim_sync.php          # Database sync
├── load/
│   ├── config.php             # Database credentials
│   ├── connect.php            # Connection management
│   └── swim_config.php        # SWIM configuration
├── database/migrations/swim/
│   ├── 001_swim_tables.sql
│   ├── 002_swim_api_database.sql
│   ├── 003_swim_api_database_fixed.sql
│   ├── 004_swim_bulk_upsert_sp.sql
│   └── 005_swim_add_telemetry_columns.sql
└── docs/swim/
    ├── VATSIM_SWIM_API_Documentation.md
    ├── VATSIM_SWIM_Design_Document_v1.md
    └── VATSIM_SWIM_FIXM_Field_Mapping.md
```

### 13.2 Glossary

| Term | Definition |
|------|------------|
| **ADL** | Aggregate Demand List - normalized flight data tables |
| **ARTCC** | Air Route Traffic Control Center |
| **CDM** | Collaborative Decision Making - FAA data sharing framework |
| **EDCT** | Expected Departure Clearance Time |
| **ETA** | Estimated Time of Arrival |
| **FIXM** | Flight Information Exchange Model - international standard |
| **GDP** | Ground Delay Program |
| **GS** | Ground Stop |
| **GUFI** | Globally Unique Flight Identifier |
| **OOOI** | Out-Off-On-In times (gate departure, takeoff, landing, gate arrival) |
| **SWIM** | System Wide Information Management |
| **TBFM** | Time Based Flow Management |
| **TMI** | Traffic Management Initiative |
| **TRACON** | Terminal Radar Approach Control |

### 13.3 Contact & Support

| Resource | Contact |
|----------|---------|
| **API Key Portal** | https://perti.vatcscc.org/swim-keys.php |
| **Documentation** | https://perti.vatcscc.org/docs/swim/ |
| **Email** | dev@vatcscc.org |
| **Discord** | vATCSCC Server |
| **Issue Tracker** | GitHub (internal) |

---

*Document Version: 1.0.0*  
*Last Updated: January 2026*  
*Maintained by: vATCSCC Development Team*
