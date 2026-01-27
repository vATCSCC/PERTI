# ACARS → VATSWIM Integration

This document describes how ACARS systems can push flight data to PERTI's VATSWIM API.

## Overview

PERTI receives ACARS messages from multiple sources to update OOOI times, position reports, and other flight data. The unified ACARS endpoint supports Hoppie, virtual airline systems (smartCARS, phpVMS, VAM), and simulator plugins.

## Endpoint

```
POST https://perti.vatcscc.org/api/swim/v1/ingest/acars
```

## Authentication

Use the **Authorization** header with a Bearer token:

```
Authorization: Bearer swim_sys_acars_your_key_here
```

## Rate Limits

- **System tier:** 30,000 requests per minute
- **Partner tier:** 3,000 requests per minute
- Batch up to **100 messages per request**

---

## Request Format

### Headers

```
Content-Type: application/json
Authorization: Bearer swim_sys_acars_your_key_here
```

### Body

```json
{
  "source": "hoppie",
  "messages": [
    {
      "type": "oooi",
      "callsign": "UAL123",
      "timestamp": "2026-01-27T14:30:00Z",
      "payload": {
        "event": "OUT",
        "departure_icao": "KJFK",
        "gate": "B32"
      }
    }
  ]
}
```

---

## Supported Sources

| Source | Description | OOOI Priority |
|--------|-------------|---------------|
| `hoppie` | Hoppie ACARS Network | 1 (highest) |
| `smartcars` | smartCARS Webhooks | 2 |
| `phpvms` | phpVMS 7 Module | 2 |
| `vam` | VAM Integration | 2 |
| `fs2crew` | FS2Crew ACARS | 3 |
| `pacx` | PACX ACARS | 3 |
| `simbrief` | SimBrief ACARS | - |
| `generic` | Generic ACARS | 5 |

---

## Message Types

### 1. OOOI Messages

OOOI (Out, Off, On, In) messages track flight milestones:

```json
{
  "type": "oooi",
  "callsign": "UAL123",
  "timestamp": "2026-01-27T14:30:00Z",
  "payload": {
    "event": "OUT",
    "departure_icao": "KJFK",
    "gate": "B32",
    "runway": null,
    "fuel_on_board_lbs": 45000
  }
}
```

| Event | Description | FIXM Column Updated |
|-------|-------------|---------------------|
| `OUT` | Pushback from gate (AOBT) | `actual_off_block_time` |
| `OFF` | Takeoff (ATOT) | `actual_time_of_departure` |
| `ON` | Landing (ALDT) | `actual_landing_time` |
| `IN` | Arrived at gate (AIBT) | `actual_in_block_time` |

### 2. Position Reports

```json
{
  "type": "position",
  "callsign": "UAL123",
  "timestamp": "2026-01-27T15:30:00Z",
  "payload": {
    "latitude": 41.2345,
    "longitude": -85.6789,
    "altitude_ft": 35000,
    "groundspeed_kts": 485,
    "heading_deg": 275,
    "next_waypoint": "CAMRN",
    "eta_next_waypoint": "2026-01-27T15:45:00Z"
  }
}
```

### 3. Progress Messages

```json
{
  "type": "progress",
  "callsign": "UAL123",
  "timestamp": "2026-01-27T16:00:00Z",
  "payload": {
    "eta_destination": "2026-01-27T18:30:00Z",
    "fuel_remaining_lbs": 32000,
    "fuel_used_lbs": 13000,
    "distance_remaining_nm": 450
  }
}
```

### 4. PDC (Pre-Departure Clearance)

For uplink PDC to pilots:

```json
{
  "type": "pdc",
  "callsign": "UAL123",
  "timestamp": "2026-01-27T14:00:00Z",
  "payload": {
    "direction": "uplink",
    "clearance": {
      "destination": "KLAX",
      "route": "GREKI5 DCT JFK J80 DJB...",
      "cleared_altitude_fl": 370,
      "initial_altitude_ft": 5000,
      "departure_runway": "31L",
      "sid": "GREKI5",
      "squawk": "4521",
      "departure_frequency": "121.900"
    }
  }
}
```

### 5. Telex Messages

```json
{
  "type": "telex",
  "callsign": "UAL123",
  "timestamp": "2026-01-27T16:30:00Z",
  "payload": {
    "direction": "downlink",
    "from": "UAL123",
    "to": "UAL_DISPATCH",
    "subject": "WEATHER UPDATE",
    "body": "CB activity reported along route..."
  }
}
```

---

## Response Format

### Success Response (HTTP 200)

```json
{
  "success": true,
  "data": {
    "processed": 5,
    "oooi_updated": 2,
    "position_updated": 2,
    "pdc_queued": 1,
    "logged": 5,
    "not_found": 0,
    "rejected": 0,
    "errors": 0,
    "error_details": []
  },
  "meta": {
    "source": "hoppie",
    "batch_size": 5
  },
  "timestamp": "2026-01-27T14:30:15Z"
}
```

### Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| `UNAUTHORIZED` | 401 | Invalid or missing API key |
| `MISSING_SOURCE` | 400 | source field is required |
| `INVALID_SOURCE` | 400 | Unknown source type |
| `MISSING_MESSAGES` | 400 | No messages array |
| `BATCH_TOO_LARGE` | 400 | More than 100 messages |

---

## OOOI Priority System

ACARS messages have **priority 1** for OOOI times. This means:

1. ACARS OOOI times will override times from simulator plugins (priority 3)
2. ACARS OOOI times will override times from ADL parsing (priority 6)
3. Dual-write ensures both legacy (`out_utc`, `off_utc`) and FIXM columns are updated

### Priority Order (Lower = Higher Priority)

| Priority | Sources |
|----------|---------|
| 1 | Hoppie ACARS |
| 2 | smartCARS, phpVMS, VAM |
| 3 | FS2Crew, PACX, Simulator plugins |
| 5 | Generic ACARS |
| 6 | ADL parsing (fallback) |

---

## Example cURL Requests

### OOOI Update

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/acars \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_sys_acars_your_key" \
  -d '{
    "source": "hoppie",
    "messages": [{
      "type": "oooi",
      "callsign": "UAL123",
      "timestamp": "2026-01-27T14:30:00Z",
      "payload": {
        "event": "OUT",
        "departure_icao": "KJFK",
        "gate": "B32"
      }
    }]
  }'
```

### Progress Update

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/acars \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_sys_acars_your_key" \
  -d '{
    "source": "smartcars",
    "messages": [{
      "type": "progress",
      "callsign": "DAL456",
      "timestamp": "2026-01-27T16:00:00Z",
      "payload": {
        "eta_destination": "2026-01-27T18:30:00Z",
        "fuel_remaining_lbs": 32000
      }
    }]
  }'
```

---

## Hoppie Integration

The ACARS endpoint is compatible with messages from the Hoppie ACARS network. The existing Hoppie bridge at `/integrations/hoppie-cpdlc/` can route messages to this endpoint.

### Hoppie OOOI Format

Hoppie OOOI messages follow this pattern:
```
/POS/KJFK/1430/OUT/GATE B32
/POS/KJFK/1445/OFF/RWY 31L
/POS/KLAX/1815/ON/RWY 24R
/POS/KLAX/1822/IN/GATE 45
```

---

## WebSocket Events

OOOI events trigger WebSocket notifications on these channels:

- `oooi.out` - Pushback from gate
- `oooi.off` - Takeoff
- `oooi.on` - Landing
- `oooi.in` - Arrived at gate
- `oooi.*` - All OOOI events

---

*Document version: 1.0.0 | Last updated: 2026-01-27*
