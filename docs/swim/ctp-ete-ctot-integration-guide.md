# CTP ETE/CTOT Integration Guide

**Version:** 1.4
**Date:** 2026-04-23
**Base URL:** `https://perti.vatcscc.org`

Three VATSWIM endpoints enable bidirectional communication between CTP Flow Control and PERTI:

| Endpoint | Direction | Auth | Purpose |
|----------|-----------|------|---------|
| `POST /api/swim/v1/ete.php` | CTP reads from PERTI | None (public) | Pull computed enroute times |
| `POST /api/swim/v1/ingest/ctot.php` | CTP writes to PERTI | API key required | Push CTOT slot assignments + route amendments |
| `POST /api/swim/v1/routes/resolve` | CTP reads from PERTI | API key required | Resolve route strings to distance + waypoints |

---

## How It Fits Into the Flow Control Request Lifecycle

Flow Control's request lifecycle has natural integration points for SWIM:

```
Pilot submits request
    │
    ▼
┌─────────────────────────────────────────────────┐
│  createFromFlight()                             │
│  ── SWIM: Pull ETE for the callsign ──────────► │  POST /api/swim/v1/ete.php
│     Use estimated_elapsed_time + ETA to set a   │  Send callsign + requested_tot as TOBT
│     smarter assigned_tot (instead of flat +20)  │
└─────────────────────────────────────────────────┘
    │
    ▼
Stakeholders assign routes (dep side + arr side)
    │
    ▼
┌─────────────────────────────────────────────────┐
│  Route assignment                               │
│  ── SWIM: Resolve route distance ────────────► │  POST /api/swim/v1/routes/resolve
│     Send dep route + oceanic track + arr route  │  Returns distance_nm per segment + total
│     as 3 batch entries (NA / OCA / EU)          │
└─────────────────────────────────────────────────┘
    │
    ▼
Both sides complete → released to controller
    │
    ▼
Controller acknowledges
    │
    ▼
┌─────────────────────────────────────────────────┐
│  acknowledge()                                  │
│  ── SWIM: Push CTOT + route amendment ────────► │  POST /api/swim/v1/ingest/ctot.php
│     Send assigned_tot as CTOT                   │  Send amendedFullRoute() as assigned_route
│     Send oceanic track + delay + segments       │  Triggers full ETA recalc cascade in PERTI
└─────────────────────────────────────────────────┘
    │
    ▼
Auto-complete after TOT + 30min
```

**What PERTI does with the data:**
- **ETE pull** — PERTI computes route-based ETE using aircraft performance tables, parsed route geometry, and unimpeded taxi reference for the departure airport. This replaces the flat +20 minute buffer with a real computed enroute time.
- **Route distance** — PERTI resolves route strings (fixes, airways, DP/STARs, oceanic coordinates) into a LINESTRING geometry using PostGIS, then computes geodesic distance in nautical miles. CTP sends three route segments in one batch call (NA departure side, oceanic track, EU arrival side) and gets back per-segment distances plus a computed total.
- **CTOT push** — PERTI stores the CTOT as an EDCT, recomputes ETA from the controlled takeoff time, updates all waypoint ETAs along the route, regenerates boundary crossing predictions, and syncs the results downstream to SWIM consumers.

---

## Authentication

The ETE endpoint is public — no authentication required.

The CTOT and Route Resolve endpoints require the CTP API key in one of two header formats:

```
X-API-Key: <your-swim-api-key>
```

or

```
Authorization: Bearer <your-swim-api-key>
```

---

## Endpoint 1: ETE Query (Pull)

Query PERTI for computed enroute times. Send callsigns with an optional TOBT (target off-block time) per flight. PERTI computes ETE/ETA using its route-based ETA engine and returns the results.

### Request

```
POST /api/swim/v1/ete.php
Content-Type: application/json
```

```json
{
  "flights": [
    { "callsign": "DLH401", "tobt": "2026-04-23T18:48:00Z" },
    { "callsign": "AFR168" }
  ]
}
```

| Field | Required | Type | Description |
|-------|----------|------|-------------|
| `callsign` | Yes | string | 2-12 alphanumeric characters |
| `tobt` | No | ISO 8601 | Target off-block time. If omitted, PERTI uses the flight's existing EOBT or ETD. |

- Maximum 50 flights per request.
- Flights must be active in PERTI (ingested from the VATSIM datafeed). Callsigns not found are returned in `unmatched`.

### Response

```json
{
  "success": true,
  "data": {
    "flights": [
      {
        "callsign": "DLH401",
        "flight_uid": 2716936,
        "gufi": null,
        "departure_airport": "KJFK",
        "arrival_airport": "EDDF",
        "aircraft_type": "A359",
        "tobt": "2026-04-23T18:48:00Z",
        "etot": "2026-04-23T18:55:00Z",
        "estimated_elapsed_time": 409,
        "estimated_time_of_arrival": "2026-04-24T01:44:00Z",
        "taxi_time_minutes": 7,
        "eta_method": "V35_ROUTE",
        "eta_confidence": 0.92,
        "route_distance_nm": 3046.5,
        "aircraft_cruise_speed_kts": 487,
        "flight_phase": "prefile",
        "filed_route": "GREKI6 GREKI N251A JOOPY MUSAK URTAK RIKAL NATB LIMRI XETBO BAKUR L603 BOMBI T180 UNOKO",
        "latitude": null,
        "longitude": null
      }
    ],
    "errors": [],
    "unmatched": ["AFR168"]
  },
  "meta": {
    "total_requested": 2,
    "total_matched": 1,
    "total_errors": 0,
    "total_unmatched": 1
  },
  "timestamp": "2026-04-23T19:43:18+00:00"
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `callsign` | string | Echoed back |
| `flight_uid` | int | PERTI internal flight ID |
| `gufi` | string or null | Globally Unique Flight Identifier |
| `departure_airport` | string | ICAO departure airport |
| `arrival_airport` | string | ICAO arrival airport |
| `aircraft_type` | string or null | ICAO aircraft type designator |
| `tobt` | ISO 8601 | Target off-block time used for computation |
| `etot` | ISO 8601 | Estimated takeoff time (TOBT + unimpeded taxi) |
| `estimated_elapsed_time` | int or null | Minutes from ETOT to ETA |
| `estimated_time_of_arrival` | ISO 8601 or null | Computed ETA |
| `taxi_time_minutes` | int | Unimpeded taxi reference for departure airport |
| `eta_method` | string or null | Computation method (e.g., `V35_ROUTE`, `V35_SEG_WIND`, `V3_ROUTE`, `V35`, `BATCH_V1`) |
| `eta_confidence` | float or null | 0.0-1.0 confidence score |
| `route_distance_nm` | float or null | Geodesic route distance in nautical miles |
| `aircraft_cruise_speed_kts` | int or null | Cruise speed from performance tables (KTAS) |
| `flight_phase` | string | Current phase: `prefile`, `departed`, `enroute`, `descending`, `arrived`, `taxiing` |
| `filed_route` | string or null | Pilot-filed route string |
| `latitude` | float or null | Current latitude (null if prefiled) |
| `longitude` | float or null | Current longitude (null if prefiled) |

### Error Handling

Flights that fail validation or computation appear in `errors`:

```json
{
  "errors": [
    {
      "callsign": "DLH401",
      "error": "No TOBT provided and no existing ETD available for this flight."
    }
  ]
}
```

Callsigns not found in PERTI appear in `unmatched` as a flat string array.

---

## Endpoint 2: CTOT Assignment (Push)

Push CTOT (Controlled Take-Off Time) slot assignments and route amendments from CTP to PERTI via SWIM. Each assignment triggers a full recalculation cascade: ETA recomputation, waypoint time updates, boundary crossing predictions, and downstream data sync.

### Request

```
POST /api/swim/v1/ingest/ctot.php
Content-Type: application/json
X-API-Key: <your-swim-api-key>
```

```json
{
  "assignments": [
    {
      "callsign": "DLH401",
      "ctot": "2026-04-23T19:10:00Z",
      "assigned_route": "GREKI6 GREKI N251A JOOPY MUSAK URTAK RIKAL NATB LIMRI XETBO BAKUR L603 BOMBI T180 UNOKO",
      "assigned_track": "B",
      "route_segments": {
        "na": "GREKI6 GREKI N251A JOOPY",
        "oceanic": "MUSAK URTAK RIKAL NATB LIMRI XETBO",
        "eu": "BAKUR L603 BOMBI T180 UNOKO"
      },
      "delay_minutes": 15,
      "program_name": "CTP-E26-WB",
      "cta_utc": "2026-04-24T02:01:00Z"
    }
  ]
}
```

### Request Fields

| Field | Required | Type | Description |
|-------|----------|------|-------------|
| `callsign` | Yes | string | 2-12 alphanumeric characters |
| `ctot` | Yes | ISO 8601 | Controlled takeoff time (wheels-up) |
| `assigned_route` | No | string | Full route string. Creates a route amendment record in PERTI. |
| `assigned_track` | No | string | NAT track code. Must match pattern `^[A-Z]{1,2}\d?$` (e.g., `A`, `B`, `SM1`, `SN2`) |
| `route_segments` | No | object | Route broken into segments: `{ "na": "...", "oceanic": "...", "eu": "..." }` |
| `delay_minutes` | No | int | Assigned delay in minutes |
| `delay_reason` | No | string | Reason for the delay |
| `program_name` | No | string | CTP program label (e.g., `CTP-E26-WB`) |
| `program_id` | No | int | TMI program ID if linked to a PERTI TMI program |
| `cta_utc` | No | ISO 8601 | Controlled time of arrival |
| `source_system` | No | string | Defaults to the API key's source identifier |

- Maximum 50 assignments per request.
- PERTI derives EOBT (estimated off-block time) automatically: `EOBT = CTOT - unimpeded_taxi`.

### Response

```json
{
  "success": true,
  "data": {
    "results": [
      {
        "callsign": "DLH401",
        "status": "created",
        "flight_uid": 2716936,
        "control_id": 4201,
        "ctot": "2026-04-23T19:10:00Z",
        "eobt": "2026-04-23T19:03:00Z",
        "edct_utc": "2026-04-23T19:03:00Z",
        "estimated_time_of_arrival": "2026-04-24T02:01:00Z",
        "estimated_elapsed_time": 411,
        "eta_method": "V35_ROUTE",
        "delay_minutes": 15,
        "route_amendment_id": 1042,
        "assigned_track": "B",
        "recalc_status": "complete"
      }
    ],
    "errors": [],
    "unmatched": []
  },
  "meta": {
    "total_submitted": 1,
    "created": 1,
    "updated": 0,
    "skipped": 0,
    "total_errors": 0,
    "unmatched": 0
  },
  "timestamp": "2026-04-23T19:45:00+00:00"
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `callsign` | string | Echoed back |
| `status` | string | `created`, `updated`, or `skipped` |
| `flight_uid` | int | PERTI internal flight ID |
| `control_id` | int or null | TMI flight control record ID |
| `ctot` | ISO 8601 | Echoed CTOT |
| `eobt` | ISO 8601 | Derived EOBT (CTOT minus taxi) |
| `edct_utc` | ISO 8601 | EDCT stored in PERTI (same as EOBT for CTP) |
| `estimated_time_of_arrival` | ISO 8601 or null | Recomputed ETA |
| `estimated_elapsed_time` | int or null | Minutes from CTOT to ETA |
| `eta_method` | string or null | ETA computation method (e.g., `V35_ROUTE`, `V35_SEG_WIND`) |
| `delay_minutes` | int or null | Echoed delay |
| `route_amendment_id` | int or null | ID of created route amendment (only if `assigned_route` was provided) |
| `assigned_track` | string or null | Echoed NAT track |
| `recalc_status` | string | `complete` or `skipped_idempotent` |

### Status Values

| Status | Meaning |
|--------|---------|
| `created` | New CTOT assignment — full recalculation performed |
| `updated` | Existing CTOT changed — full recalculation performed |
| `skipped` | Same CTOT already assigned — no recalculation needed |

### Idempotency

Sending the same CTOT for a flight that already has it returns `status: "skipped"` with `recalc_status: "skipped_idempotent"`. No database writes or recalculations occur. This makes it safe to retry requests.

---

## Endpoint 3: Route Distance (Pull)

Resolve route strings into waypoints with lat/lon coordinates and compute geodesic distance in nautical miles. PERTI expands airways, DP/STARs, oceanic coordinates, and fixes via PostGIS, then measures the resulting LINESTRING using `ST_Length(geography)`.

Supports both single route (GET) and batch (POST, up to 50 routes).

### Single Route (GET)

```
GET /api/swim/v1/routes/resolve?route_string=GREKI6+GREKI+N251A+JOOPY+MUSAK+URTAK+RIKAL+NATB+LIMRI+XETBO+BAKUR+L603+BOMBI+T180+UNOKO&origin=KJFK&dest=EDDF
X-API-Key: <your-swim-api-key>
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `route_string` | Yes | Space-delimited route (fixes, airways, procedures, oceanic coords) |
| `origin` | No | ICAO departure airport (prepended to route if not already first token) |
| `dest` | No | ICAO arrival airport (appended to route if not already last token) |

### Batch (POST)

```
POST /api/swim/v1/routes/resolve
Content-Type: application/json
X-API-Key: <your-swim-api-key>
```

For CTP integration, send each route segment (NA, oceanic, EU) as a separate batch entry.
Each segment returns its own `total_distance_nm`; sum all three for the full route distance.

```json
{
  "routes": [
    {
      "route_string": "GREKI6 GREKI N251A JOOPY",
      "origin": "KJFK"
    },
    {
      "route_string": "MUSAK URTAK RIKAL NATB LIMRI XETBO"
    },
    {
      "route_string": "BAKUR L603 BOMBI T180 UNOKO",
      "dest": "EDDF"
    }
  ]
}
```

This maps directly to the Flow Control data model:
- Entry 0 = `departureSide->route` (NA segment) with `origin`
- Entry 1 = `oceanicTrack->waypoints` (OCA segment) — no origin/dest bookends
- Entry 2 = `arrivalSide->route` (EU segment) with `dest`

Maximum 50 routes per batch request.

### Response

Single route (GET) returns one result object. Batch (POST) returns an array:

```json
{
  "success": true,
  "data": {
    "count": 3,
    "routes": [
      {
        "route_string": "GREKI6 GREKI N251A JOOPY",
        "expanded_route": "KJFK GREKI JOOPY",
        "origin": "KJFK",
        "dest": null,
        "total_distance_nm": 482.1,
        "waypoint_count": 3,
        "waypoints": [
          { "seq": 1, "fix": "KJFK", "lat": 40.639751, "lon": -73.778925, "type": "airport" },
          { "seq": 2, "fix": "GREKI", "lat": 41.480008, "lon": -73.314161, "type": "nav_fix" },
          { "seq": 3, "fix": "JOOPY", "lat": 48.500000, "lon": -52.000000, "type": "nav_fix" }
        ],
        "artccs_traversed": ["ZNY", "ZBW"]
      },
      {
        "route_string": "MUSAK URTAK RIKAL NATB LIMRI XETBO",
        "expanded_route": "MUSAK URTAK RIKAL LIMRI XETBO",
        "origin": null,
        "dest": null,
        "total_distance_nm": 1847.3,
        "waypoint_count": 5,
        "waypoints": [
          { "seq": 1, "fix": "MUSAK", "lat": 48.000000, "lon": -52.000000, "type": "nav_fix" },
          { "seq": 2, "fix": "URTAK", "lat": 58.466667, "lon": -58.000000, "type": "nav_fix" },
          { "seq": 3, "fix": "RIKAL", "lat": 51.800000, "lon": -54.533333, "type": "nav_fix" },
          { "seq": 4, "fix": "LIMRI", "lat": 52.000000, "lon": -15.000000, "type": "nav_fix" },
          { "seq": 5, "fix": "XETBO", "lat": 52.000000, "lon": -14.000000, "type": "nav_fix" }
        ],
        "artccs_traversed": []
      },
      {
        "route_string": "BAKUR L603 BOMBI T180 UNOKO",
        "expanded_route": "BAKUR BOMBI UNOKO EDDF",
        "origin": null,
        "dest": "EDDF",
        "total_distance_nm": 717.1,
        "waypoint_count": 4,
        "waypoints": [
          { "seq": 1, "fix": "BAKUR", "lat": 52.241558, "lon": -5.680225, "type": "nav_fix" },
          { "seq": 2, "fix": "BOMBI", "lat": null, "lon": null, "type": "airway_L603" },
          { "seq": 3, "fix": "UNOKO", "lat": null, "lon": null, "type": "airway_T180" },
          { "seq": 4, "fix": "EDDF", "lat": 50.033333, "lon": 8.570556, "type": "airport" }
        ],
        "artccs_traversed": []
      }
    ]
  },
  "timestamp": "2026-04-23T20:15:00+00:00"
}
```

**Segment distance summary** (computed client-side):

| Segment | Route Portion | Distance |
|---------|---------------|----------|
| NA (departure) | KJFK → JOOPY | 482.1 nm |
| OCA (oceanic) | MUSAK → XETBO | 1,847.3 nm |
| EU (arrival) | BAKUR → EDDF | 717.1 nm |
| **Total** | | **3,046.5 nm** |

> **Note:** `artccs_traversed` only includes US ARTCC facility codes. Oceanic and European segments return empty arrays because PERTI's spatial data covers US domestic airspace only.

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `route_string` | string | Echoed input route |
| `expanded_route` | string | Fully expanded route (airways resolved to individual fixes) |
| `origin` | string or null | Echoed origin airport |
| `dest` | string or null | Echoed destination airport |
| `total_distance_nm` | float | Geodesic route distance in nautical miles (1 decimal place) |
| `waypoint_count` | int | Number of resolved waypoints |
| `waypoints` | array | Each waypoint: `seq`, `fix`, `lat`, `lon`, `type` |
| `artccs_traversed` | array | ARTCC facility codes the route passes through |

### Waypoint Types

| Type | Description |
|------|-------------|
| `airport` | Airport matched by ICAO code (e.g., `KJFK`, `EDDF`) |
| `airport_faa` | Airport matched by FAA 3-letter code (e.g., `JFK`, `DFW`) |
| `airport_k` | Airport matched by K-prefix conversion (e.g., `JFK` → `KJFK`) |
| `nav_fix` | Named waypoint, VOR, NDB, or intersection (e.g., `GREKI`, `JOOPY`) |
| `airway_{name}` | Waypoint resolved from an airway segment — type includes the airway name (e.g., `airway_N251A`, `airway_L603`) |
| `coordinate` | Oceanic lat/lon coordinate (e.g., `50N050W`, `51N040W`) |
| `procedure` | Waypoint from a DP or STAR expansion |
| `area_center` | ARTCC or TRACON pseudo-fix (e.g., `ZNY`, `ZBW`) |

### Route String Format

Route strings use space-delimited tokens. PERTI recognizes:
- **Fixes/navaids**: `GREKI`, `BNN`, `YAHOO`
- **Airways**: `N251A`, `L603`, `T180`, `UL9` — expanded into individual fixes
- **DP/STARs**: `GREKI6`, `HAPIE4` — expanded using CIFP procedure data
- **Oceanic coordinates**: `50N050W`, `51N040W` — parsed to lat/lon
- **Origin/dest ICAO**: `KJFK`, `EDDF` — resolved as airports

---

## Prerequisites

1. **Flights must exist in PERTI.** PERTI ingests flights from the VATSIM datafeed. Only flights that PERTI has seen can be queried or assigned. The `unmatched` array in both endpoints tells you which callsigns were not found.

2. **For `route_segments` and `assigned_track` to persist to CTP flight control records**, the flight must have been imported via CTP session import (`/api/swim/v1/ingest/ctp.php`) first. If no CTP flight control record exists, the CTOT assignment still succeeds (Steps 1-8 complete) but Step 9 is silently skipped.

3. **Timing:** Call ETE first to get computed enroute times for your optimizer, then call CTOT to push the resulting slot assignments back. Both endpoints can be called at any point during the flight lifecycle (prefiled through departing).

---

## Error Responses

All error responses use a flat structure (no nested `error` object, no `success` key):

```json
{
  "error": true,
  "message": "Request body must contain a \"flights\" array",
  "status": 400,
  "code": "INVALID_REQUEST"
}
```

| HTTP Status | Code | Endpoint | Cause |
|-------------|------|----------|-------|
| 400 | `INVALID_REQUEST` | ETE, CTOT | Missing/malformed request body or fields |
| 400 | `INVALID_BODY` | Route Resolve | Missing or non-array `routes` body |
| 400 | `EMPTY_ROUTES` | Route Resolve | Empty `routes` array |
| 400 | `BATCH_LIMIT_EXCEEDED` | Route Resolve | More than 50 routes in batch |
| 400 | `MISSING_PARAMETER` | Route Resolve | Missing `route_string` in a batch entry |
| 400 | `EXPANSION_FAILED` | Route Resolve | PostGIS could not expand route |
| 401 | `UNAUTHORIZED` | CTOT, Route Resolve | Missing or invalid API key |
| 403 | `FORBIDDEN` | CTOT | Valid key but insufficient write permissions |
| 405 | `METHOD_NOT_ALLOWED` | CTOT, Route Resolve | Wrong HTTP method |
| 500 | `INTERNAL_ERROR` | Route Resolve | PostGIS batch expansion failed |
| 503 | `SERVICE_UNAVAILABLE` | All | Database connection unavailable |

---

## Quick Start Example

### Step 1: Query ETE for your flight list

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ete.php \
  -H "Content-Type: application/json" \
  -d '{
    "flights": [
      { "callsign": "DLH401", "tobt": "2026-04-23T18:48:00Z" },
      { "callsign": "AUA762", "tobt": "2026-04-23T19:30:00Z" }
    ]
  }'
```

### Step 2: Push CTOT assignments after optimization

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/ctot.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <your-swim-api-key>" \
  -d '{
    "assignments": [
      {
        "callsign": "DLH401",
        "ctot": "2026-04-23T19:10:00Z",
        "assigned_track": "B",
        "delay_minutes": 15,
        "program_name": "CTP-E26-WB"
      },
      {
        "callsign": "AUA762",
        "ctot": "2026-04-23T19:45:00Z",
        "assigned_track": "A",
        "delay_minutes": 10,
        "program_name": "CTP-E26-WB"
      }
    ]
  }'
```

### Step 3: Compute route distance per segment (NA / OCA / EU)

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/routes/resolve \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <your-swim-api-key>" \
  -d '{
    "routes": [
      { "route_string": "GREKI6 GREKI N251A JOOPY", "origin": "KJFK" },
      { "route_string": "MUSAK URTAK RIKAL NATB LIMRI XETBO" },
      { "route_string": "BAKUR L603 BOMBI T180 UNOKO", "dest": "EDDF" }
    ]
  }'
```

Response returns `total_distance_nm` for each segment. Sum all three for total route distance (NA: 482.1 + OCA: 1847.3 + EU: 717.1 = 3046.5 nm).

---

## CTP Codebase Changes Required

This section details the exact files to create and modify in the `vatsimnetwork/flowcontrol` Laravel codebase to integrate SWIM ETE/CTOT exchange.

### Overview of Changes

| Action | File | Purpose |
|--------|------|---------|
| Create | `app/Services/SwimService.php` | HTTP client for PERTI SWIM API |
| Modify | `.env` | Add SWIM connection vars |
| Modify | `config/services.php` | Add `swim` config block |
| Modify | `app/Services/RequestService.php` | Call ETE on create, push CTOT on acknowledge |
| Modify | `app/Services/RequestAssignmentService.php` | Query route distance when stakeholder assigns route |

### Step 1: Add Environment Variables

Add to `.env`:

```env
SWIM_BASE_URL=https://perti.vatcscc.org
SWIM_CTP_API_KEY=<your-swim-api-key>
```

### Step 2: Add Config Block

In `config/services.php`, add the `swim` key after the existing `ctp` block (line 48):

```php
'swim' => [
    'base_url' => env('SWIM_BASE_URL', 'https://perti.vatcscc.org'),
    'api_key' => env('SWIM_CTP_API_KEY'),
],
```

### Step 3: Create `app/Services/SwimService.php`

This service handles both ETE queries and CTOT pushes. It uses model methods and relationships that already exist in the flowcontrol codebase: `Request::amendedFullRoute()` (line 191 of `Request.php`), `Request::oceanicTrack` (BelongsTo, line 88), `Request::departureSide` / `arrivalSide` (HasOne, lines 137/142), and `RequestSide::$fillable['route']`.

```php
<?php

namespace App\Services;

use App\Models\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SwimService
{
    /**
     * Query PERTI for computed ETE/ETA for one or more callsigns.
     * ETE endpoint is public — no API key required.
     *
     * @param array<int, array{callsign: string, tobt?: string}> $flights
     * @return array{flights: array, errors: array, unmatched: array}|null
     */
    public function queryEte(array $flights): ?array
    {
        $response = Http::timeout(10)
            ->post(config('services.swim.base_url') . '/api/swim/v1/ete.php', [
                'flights' => $flights,
            ]);

        if ($response->failed()) {
            Log::warning('SWIM ETE query failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $json = $response->json();

        // Success responses have {"success": true, "data": {...}}
        // Error responses have {"error": true, "message": "..."} (no "success" key)
        if (!($json['success'] ?? false)) {
            Log::warning('SWIM ETE query returned error', $json);
            return null;
        }

        return $json['data'];
    }

    /**
     * Push CTOT assignment + route amendment to PERTI via SWIM.
     * Requires the CTP API key (system tier, write-enabled).
     *
     * Builds the assignment payload from existing Request model fields:
     * - callsign, assigned_tot → required
     * - amendedFullRoute() → assigned_route (dep + track + arr concatenated)
     * - oceanicTrack->name → assigned_track (e.g., "A", "B", "SM1")
     * - departureSide->route, arrivalSide->route → route_segments
     *
     * @return array{results: array, errors: array, unmatched: array}|null
     */
    public function pushCtot(Request $request): ?array
    {
        $assignment = [
            'callsign' => $request->callsign,
            'ctot' => $request->assigned_tot->toIso8601String(),
            'program_name' => $request->flowProgram?->name,
        ];

        // Include full amended route if stakeholders assigned dep/arr routes
        $fullRoute = $request->amendedFullRoute();
        if ($fullRoute) {
            $assignment['assigned_route'] = $fullRoute;
        }

        // Include NAT track letter if assigned
        if ($request->oceanicTrack) {
            $assignment['assigned_track'] = $request->oceanicTrack->name;
        }

        // Include route segments broken into na/oceanic/eu
        $depRoute = $request->departureSide?->route;
        $arrRoute = $request->arrivalSide?->route;
        $trackWaypoints = $request->oceanicTrack?->waypoints;
        if ($depRoute || $arrRoute || $trackWaypoints) {
            $assignment['route_segments'] = array_filter([
                'na' => $depRoute,
                'oceanic' => $trackWaypoints,
                'eu' => $arrRoute,
            ]);
        }

        // Calculate delay (difference between requested and assigned TOT)
        if ($request->requested_tot && $request->assigned_tot) {
            $delayMinutes = $request->requested_tot->diffInMinutes($request->assigned_tot);
            if ($delayMinutes > 0) {
                $assignment['delay_minutes'] = $delayMinutes;
            }
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'X-API-Key' => config('services.swim.api_key'),
            ])
            ->post(config('services.swim.base_url') . '/api/swim/v1/ingest/ctot.php', [
                'assignments' => [$assignment],
            ]);

        if ($response->failed()) {
            Log::warning('SWIM CTOT push failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'callsign' => $request->callsign,
            ]);
            return null;
        }

        $json = $response->json();
        if (!($json['success'] ?? false)) {
            Log::warning('SWIM CTOT push returned error', $json);
            return null;
        }

        return $json['data'];
    }

    /**
     * Resolve route strings to waypoints + geodesic distance via PERTI PostGIS.
     * Requires the CTP API key (same key used for CTOT push).
     *
     * @param array<int, array{route_string: string, origin?: string, dest?: string}> $routes
     * @return array{count: int, routes: array}|null
     */
    public function resolveRoutes(array $routes): ?array
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'X-API-Key' => config('services.swim.api_key'),
            ])
            ->post(config('services.swim.base_url') . '/api/swim/v1/routes/resolve', [
                'routes' => $routes,
            ]);

        if ($response->failed()) {
            Log::warning('SWIM route resolve failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $json = $response->json();
        if (!($json['success'] ?? false)) {
            Log::warning('SWIM route resolve returned error', $json);
            return null;
        }

        return $json['data'];
    }

    /**
     * Convenience: resolve a single route and return distance in NM.
     *
     * @return float|null Distance in nautical miles, or null on failure
     */
    public function getRouteDistanceNm(string $routeString, ?string $origin = null, ?string $dest = null): ?float
    {
        $result = $this->resolveRoutes([
            ['route_string' => $routeString, 'origin' => $origin, 'dest' => $dest],
        ]);

        if (!$result || empty($result['routes'])) {
            return null;
        }

        return $result['routes'][0]['total_distance_nm'] ?? null;
    }

    /**
     * Resolve all three CTP route segments in a single batch call.
     * Returns per-segment distances (NA, OCA, EU) plus total.
     *
     * Maps directly to Flow Control's data model:
     *   - departureSide->route → NA segment
     *   - oceanicTrack->waypoints → OCA segment
     *   - arrivalSide->route → EU segment
     *
     * @return array{na: ?float, oca: ?float, eu: ?float, total: float}|null
     */
    public function getSegmentDistances(
        Request $request
    ): ?array {
        $routes = [];
        $segmentKeys = []; // Track which index maps to which segment

        if ($request->departureSide?->route) {
            $segmentKeys[] = 'na';
            $routes[] = [
                'route_string' => $request->departureSide->route,
                'origin' => $request->departure_airport,
            ];
        }

        if ($request->oceanicTrack?->waypoints) {
            $segmentKeys[] = 'oca';
            $routes[] = [
                'route_string' => $request->oceanicTrack->waypoints,
            ];
        }

        if ($request->arrivalSide?->route) {
            $segmentKeys[] = 'eu';
            $routes[] = [
                'route_string' => $request->arrivalSide->route,
                'dest' => $request->arrival_airport,
            ];
        }

        if (empty($routes)) {
            return null;
        }

        $result = $this->resolveRoutes($routes);
        if (!$result || empty($result['routes'])) {
            return null;
        }

        $distances = ['na' => null, 'oca' => null, 'eu' => null, 'total' => 0.0];
        foreach ($result['routes'] as $i => $route) {
            $key = $segmentKeys[$i] ?? null;
            $nm = $route['total_distance_nm'] ?? null;
            if ($key && $nm !== null) {
                $distances[$key] = $nm;
                $distances['total'] += $nm;
            }
        }

        return $distances;
    }
}
```

### Step 4: Modify `app/Services/RequestService.php`

Two methods need changes. The existing file has these methods at:
- `createFromFlight()` — line 25
- `acknowledge()` — line 84

#### 4a. Add ETE query to `createFromFlight()`

Currently `createFromFlight()` sets `assigned_tot` using a flat +20 minute buffer (line 38):

```php
'assigned_tot' => $this->calculateAssignedTot($requestedTot, false),
```

After the `Request::create()` call (after line 41), add an ETE query to enrich the request with computed enroute time. This data can be used to display ETE/ETA to stakeholders or to replace the flat buffer with a computed TOT:

```php
return DB::transaction(function () use ($flight, $requestedTot, $submitterId): Request {
    $request = Request::create([
        // ... existing fields unchanged ...
    ]);

    // --- NEW: Query SWIM for computed ETE ---
    try {
        $swim = app(SwimService::class);
        $eteResult = $swim->queryEte([
            [
                'callsign' => $flight->callsign,
                'tobt' => $requestedTot->toIso8601String(),
            ],
        ]);

        if ($eteResult && !empty($eteResult['flights'])) {
            $ete = $eteResult['flights'][0];
            // Available fields from PERTI:
            //   $ete['estimated_elapsed_time']    → int, minutes from takeoff to landing
            //   $ete['estimated_time_of_arrival']  → string, ISO 8601 ETA
            //   $ete['route_distance_nm']          → float, geodesic route distance
            //   $ete['aircraft_cruise_speed_kts']  → int, cruise speed from perf tables
            //   $ete['taxi_time_minutes']          → int, unimpeded taxi for departure airport
            //   $ete['eta_method']                 → string, e.g. "V35_ROUTE"
            //   $ete['eta_confidence']             → float, 0.0-1.0
            //
            // Example: store ETE on the request for stakeholder display
            // $request->update(['swim_ete_minutes' => $ete['estimated_elapsed_time']]);
        }
    } catch (\Throwable $e) {
        Log::warning('SWIM ETE query failed during request creation', [
            'callsign' => $flight->callsign,
            'error' => $e->getMessage(),
        ]);
    }
    // --- END NEW ---

    $direction = $this->flowPrograms->detectDirection($request);
    // ... rest of method unchanged ...
});
```

Add `use Illuminate\Support\Facades\Log;` to the imports at the top of `RequestService.php` (it's not currently imported).

#### 4b. Add CTOT push to `acknowledge()`

The current `acknowledge()` method (line 84) only updates the status:

```php
public function acknowledge(Request $request, int $userId): void
{
    $request->update([
        'status' => RequestStatus::Acknowledged,
        'controller_acknowledged_at' => now(),
    ]);
}
```

Add a CTOT push after the status update. This is fire-and-forget — a SWIM failure must not block the acknowledge action:

```php
public function acknowledge(Request $request, int $userId): void
{
    $request->update([
        'status' => RequestStatus::Acknowledged,
        'controller_acknowledged_at' => now(),
    ]);

    // --- NEW: Push CTOT + route amendment to PERTI via SWIM ---
    try {
        app(SwimService::class)->pushCtot($request);
    } catch (\Throwable $e) {
        Log::error('SWIM CTOT push exception on acknowledge', [
            'callsign' => $request->callsign,
            'error' => $e->getMessage(),
        ]);
    }
    // --- END NEW ---
}
```

### Step 5: Query Segment Distances in `app/Services/RequestAssignmentService.php`

When both sides complete their route assignments, query PERTI for per-segment distances (NA / OCA / EU). The `RequestSide` model already has a `distance_nm` field in its `$fillable` array.

`SwimService::getSegmentDistances()` resolves all three segments in a single batch call and returns `{ na: float, oca: float, eu: float, total: float }`:

```php
use App\Services\SwimService;

// After both sides are assigned (or when either side updates their route):
try {
    $distances = app(SwimService::class)->getSegmentDistances($request);

    if ($distances) {
        // Store per-segment distance on each RequestSide
        if ($distances['na'] !== null && $request->departureSide) {
            $request->departureSide->update(['distance_nm' => $distances['na']]);
        }
        if ($distances['eu'] !== null && $request->arrivalSide) {
            $request->arrivalSide->update(['distance_nm' => $distances['eu']]);
        }

        // Available for display:
        //   $distances['na']    → NA departure segment (nm)
        //   $distances['oca']   → Oceanic track segment (nm)
        //   $distances['eu']    → EU arrival segment (nm)
        //   $distances['total'] → Sum of all segments (nm)
    }
} catch (\Throwable $e) {
    Log::warning('SWIM segment distance query failed', [
        'callsign' => $request->callsign,
        'error' => $e->getMessage(),
    ]);
}
```

### Step 6 (Optional): Batch ETE Refresh

To refresh ETE for all active requests at once (e.g., on a scheduled job or Filament action), chunk into batches of 50:

```php
use App\Enums\RequestStatus;
use App\Models\Request;

$activeRequests = Request::where('status', RequestStatus::ActionRequired)
    ->orWhere('status', RequestStatus::AwaitingRelease)
    ->get();

$flights = $activeRequests->map(fn ($r) => [
    'callsign' => $r->callsign,
    'tobt' => $r->requested_tot?->toIso8601String(),
])->filter(fn ($f) => $f['callsign'])->values()->toArray();

foreach (array_chunk($flights, 50) as $batch) {
    $result = app(SwimService::class)->queryEte($batch);
    if (!$result) continue;
    foreach ($result['flights'] as $ete) {
        // Update request with fresh ETE data
        // e.g., display ETA to stakeholders, recalculate assigned_tot
    }
}
```

### Data Flow Summary

```
Pilot files flight plan on VATSIM
    → PERTI ingests from datafeed (15s cycle)
    → Pilot submits request in Flow Control

createFromFlight()
    → Flow Control calls POST /api/swim/v1/ete.php
    → PERTI returns ETE/ETA/distance/speed computed from filed route
    → Flow Control stores ETE for stakeholder display / smarter TOT

Stakeholders assign dep route + arr route + oceanic track
    → Flow Control calls POST /api/swim/v1/routes/resolve (3 batch entries)
    → PERTI returns total_distance_nm for each segment:
        NA (dep side): 482.1 nm | OCA (track): 1847.3 nm | EU (arr side): 717.1 nm
    → Flow Control stores distance_nm on each RequestSide
    → Total route distance = sum of segments (3046.5 nm)
    → Controller acknowledges

acknowledge()
    → Flow Control calls POST /api/swim/v1/ingest/ctot.php
    → Sends: assigned_tot (CTOT), amendedFullRoute(), track letter, delay
    → PERTI stores EDCT, recomputes all ETAs, updates boundary crossings
    → Downstream SWIM consumers see the updated flight data
```

---

## Contact

Questions or issues: Jeremy Peterson (CANOC)
