# SimTraffic → VATSWIM Push Integration

This document describes how SimTraffic can push flight timing data directly to PERTI's VATSWIM API.

## Overview

PERTI receives flight timing data from SimTraffic to update metering, departure sequence, and arrival times for flights on the VATSIM network. This integration uses a push model where SimTraffic sends updates to our API endpoint.

## Endpoint

```
POST https://perti.vatcscc.org/api/swim/v1/ingest/simtraffic
```

## Authentication

Use the **Authorization** header with a Bearer token:

```
Authorization: Bearer swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2
```

**Alternative:** Use the `X-API-Key` header if Bearer tokens are inconvenient:
```
X-API-Key: swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2
```

## Rate Limits

- **30,000 requests per minute** (system tier)
- Batch up to **500 flights per request** for efficiency

## Request Format

### Headers

```
Content-Type: application/json
Authorization: Bearer swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2
```

### Body

```json
{
  "mode": "push",
  "flights": [
    {
      "callsign": "UAL123",
      "departure_afld": "KORD",
      "arrival_afld": "KJFK",
      "departure": {
        "push_time": "2026-01-27T14:30:00Z",
        "taxi_time": "2026-01-27T14:35:00Z",
        "sequence_time": "2026-01-27T14:38:00Z",
        "holdshort_time": "2026-01-27T14:40:00Z",
        "runway_time": "2026-01-27T14:42:00Z",
        "takeoff_time": "2026-01-27T14:45:00Z",
        "edct": "2026-01-27T14:40:00Z"
      },
      "arrival": {
        "eta": "2026-01-27T17:15:00Z",
        "eta_mf": "2026-01-27T17:00:00Z",
        "eta_vertex": "2026-01-27T16:45:00Z",
        "on_time": "2026-01-27T17:18:00Z",
        "metering_fix": "CAMRN",
        "rwy_assigned": "31L"
      },
      "status": {
        "departed": true,
        "arrived": false,
        "in_artcc": "ZDC",
        "delay_value": 5
      }
    }
  ]
}
```

## Field Reference

### Flight Identification (Required)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `callsign` | string | **Yes** | Aircraft callsign (e.g., "UAL123") |
| `departure_afld` | string | Recommended | Departure ICAO (helps match flight) |
| `arrival_afld` | string | Recommended | Arrival ICAO (helps match flight) |
| `gufi` | string | Optional | Direct GUFI lookup if known |

### Departure Times

All times in **ISO 8601 UTC format** (e.g., `2026-01-27T14:30:00Z`)

| Field | Description | SWIM Column |
|-------|-------------|-------------|
| `departure.push_time` | Actual pushback time (T13 AOBT) | out_utc |
| `departure.taxi_time` | When taxi begins | taxi_time_utc |
| `departure.sequence_time` | Departure sequence assignment | sequence_time_utc |
| `departure.holdshort_time` | Hold short point entry | holdshort_time_utc |
| `departure.runway_time` | Runway entry time | runway_time_utc |
| `departure.takeoff_time` | Actual takeoff (T11 ATOT) | off_utc |
| `departure.edct` | Expected Departure Clearance Time | edct_utc |

### Arrival Times

| Field | Description | SWIM Column |
|-------|-------------|-------------|
| `arrival.eta` | Estimated arrival at runway | eta_utc, eta_runway_utc |
| `arrival.eta_mf` or `arrival.mft` | STA at meter fix | metering_time |
| `arrival.eta_vertex` or `arrival.vt` | STA at vertex/corner post | eta_vertex |
| `arrival.on_time` | Actual landing (T12 ALDT) | on_utc |
| `arrival.metering_fix` | Meter fix identifier (e.g., "CAMRN") | metering_point |
| `arrival.rwy_assigned` | Assigned arrival runway (e.g., "31L") | arr_runway |

### Status

| Field | Type | Description | SWIM Column |
|-------|------|-------------|-------------|
| `status.departed` | boolean | Flight has departed | phase='enroute' |
| `status.arrived` | boolean | Flight has landed | phase='arrived' |
| `status.in_artcc` | string | Current ARTCC (e.g., "ZDC") | current_artcc |
| `status.delay_value` | integer | TBFM delay in minutes | metering_delay |

## Response Format

### Success Response (HTTP 200)

```json
{
  "success": true,
  "data": {
    "processed": 50,
    "updated": 45,
    "not_found": 5,
    "errors": 0,
    "error_details": []
  },
  "meta": {
    "source": "simtraffic",
    "mode": "push",
    "batch_size": 50
  },
  "timestamp": "2026-01-27T15:30:00Z"
}
```

### Error Response

```json
{
  "error": true,
  "message": "Description of the error",
  "status": 400,
  "code": "ERROR_CODE"
}
```

### Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| `UNAUTHORIZED` | 401 | Invalid or missing API key |
| `NOT_AUTHORITATIVE` | 403 | API key lacks write permission |
| `MISSING_BODY` | 400 | Request body is empty |
| `INVALID_JSON` | 400 | JSON parse error |
| `MISSING_FLIGHTS` | 400 | No "flights" array in body |
| `BATCH_TOO_LARGE` | 400 | More than 500 flights |
| `SERVICE_UNAVAILABLE` | 503 | Database temporarily unavailable |

## Flight Matching

Flights are matched in PERTI's database by:

1. **GUFI** (if provided) - Direct lookup by globally unique flight identifier
2. **Callsign + Destination** - Most recent active flight with matching callsign and arrival airport
3. **Callsign only** - Most recent active flight with matching callsign

For best matching accuracy, include `departure_afld` and `arrival_afld` in each record.

## Recommended Push Strategy

### When to Push

Push updates when:
- A metering time is assigned or changed
- Flight departs (push_time, takeoff_time)
- ETA changes significantly (>1 minute)
- Flight lands (on_time)
- Runway assignment changes

### Batching Recommendations

- **Event-driven batches:** Collect updates for 5-10 seconds, then push in batch
- **Periodic sync:** Push all active flights every 2-5 minutes
- **Hybrid:** Event-driven for departures/arrivals + periodic for ETAs

### Example Integration Pattern

```pseudocode
batch = []
last_push = now()

on_flight_update(flight):
    batch.append(format_flight(flight))

    # Push immediately for arrivals/departures
    if flight.just_departed or flight.just_landed:
        push_batch(batch)
        batch = []
        last_push = now()

every 30 seconds:
    if len(batch) > 0 or (now() - last_push) > 120 seconds:
        push_batch(batch)
        batch = []
        last_push = now()
```

## Example cURL Request

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/simtraffic \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2" \
  -d '{
    "mode": "push",
    "flights": [
      {
        "callsign": "DAL456",
        "arrival_afld": "KATL",
        "arrival": {
          "eta": "2026-01-27T18:30:00Z",
          "eta_mf": "2026-01-27T18:15:00Z",
          "metering_fix": "ERLIN",
          "rwy_assigned": "26R"
        },
        "status": {
          "departed": true,
          "delay_value": 3
        }
      }
    ]
  }'
```

## Support

For integration questions or issues:
- **PERTI Admin:** Jeremy Peterson
- **VATCSCC Discord:** https://discord.gg/vatcscc
- **GitHub Issues:** https://github.com/vatcscc/perti/issues

---

*Document version: 1.0.0*
*Last updated: 2026-01-27*
