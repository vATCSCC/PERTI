# vNAS → VATSWIM Push Integration

This document describes how vNAS (Virtual National Airspace System) can push ATC automation data to PERTI's VATSWIM API.

## Overview

PERTI receives ATC automation data from vNAS to update track surveillance, automation tags, handoff state, and metering data for flights on the VATSIM network. This enables real-time coordination between vNAS ERAM/STARS systems and the PERTI SWIM platform.

## Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/swim/v1/ingest/vnas/track` | POST | Track/surveillance data (radar positions) |
| `/api/swim/v1/ingest/vnas/tags` | POST | Automation tags (assigned alt/speed/heading) |
| `/api/swim/v1/ingest/vnas/handoff` | POST | Sector handoff data |

## Authentication

Use the **Authorization** header with a Bearer token:

```
Authorization: Bearer swim_sys_vnas_zdc_a1b2c3d4e5f6
```

**Alternative:** Use the `X-API-Key` header if Bearer tokens are inconvenient:
```
X-API-Key: swim_sys_vnas_zdc_a1b2c3d4e5f6
```

## Rate Limits

- **30,000 requests per minute** (system tier)
- Track data: up to **1,000 tracks per request**
- Tags data: up to **500 tags per request**
- Handoff data: up to **200 handoffs per request**

---

## Track/Surveillance Data

### Endpoint

```
POST /api/swim/v1/ingest/vnas/track
```

### Request Format

```json
{
  "facility_id": "ZDC",
  "system_type": "ERAM",
  "timestamp": "2026-01-27T15:30:00.000Z",
  "tracks": [
    {
      "callsign": "UAL123",
      "gufi": "VAT-20260127-UAL123-KORD-KJFK",
      "beacon_code": "1234",
      "position": {
        "latitude": 40.6413,
        "longitude": -73.7781,
        "altitude_ft": 35000,
        "altitude_type": "barometric",
        "ground_speed_kts": 450,
        "track_deg": 270,
        "vertical_rate_fpm": -500
      },
      "track_quality": {
        "source": "radar",
        "mode_c": true,
        "mode_s": true,
        "ads_b": false,
        "position_quality": 9
      },
      "timestamp": "2026-01-27T15:30:00.000Z"
    }
  ]
}
```

### Field Reference

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `facility_id` | string | **Yes** | Source facility (e.g., "ZDC", "N90") |
| `system_type` | string | Recommended | "ERAM" or "STARS" |
| `tracks[].callsign` | string | **Yes** | Aircraft callsign |
| `tracks[].gufi` | string | Optional | Direct GUFI lookup |
| `tracks[].beacon_code` | string | Optional | Mode A/3 squawk code |
| `tracks[].position.latitude` | number | **Yes** | Latitude (-90 to 90) |
| `tracks[].position.longitude` | number | **Yes** | Longitude (-180 to 180) |
| `tracks[].position.altitude_ft` | integer | Optional | Altitude in feet MSL |
| `tracks[].position.ground_speed_kts` | integer | Optional | Ground speed in knots |
| `tracks[].position.track_deg` | integer | Optional | True track (0-360) |
| `tracks[].position.vertical_rate_fpm` | integer | Optional | Vertical rate (ft/min) |
| `tracks[].track_quality.mode_c` | boolean | Optional | Mode C validity |
| `tracks[].track_quality.mode_s` | boolean | Optional | Mode S validity |
| `tracks[].track_quality.ads_b` | boolean | Optional | ADS-B equipped |
| `tracks[].track_quality.position_quality` | integer | Optional | Quality 0-9 |

---

## Automation Tags

### Endpoint

```
POST /api/swim/v1/ingest/vnas/tags
```

### Request Format

```json
{
  "facility_id": "ZDC",
  "system_type": "ERAM",
  "tags": [
    {
      "callsign": "UAL123",
      "gufi": "VAT-20260127-UAL123-KORD-KJFK",
      "assigned_altitude": 35000,
      "interim_altitude": 28000,
      "assigned_speed": 280,
      "assigned_heading": 270,
      "scratchpad": "KJFK/31L",
      "scratchpad2": "GDP+15",
      "point_out_sector": "33",
      "coordination_status": "TRACKED",
      "conflict_alert": false,
      "msaw_alert": false,
      "timestamp": "2026-01-27T15:30:00Z"
    }
  ]
}
```

### Field Reference

| Field | Type | Description |
|-------|------|-------------|
| `assigned_altitude` | integer | Controller-assigned altitude (ft) |
| `interim_altitude` | integer | ERAM interim altitude (ft) |
| `assigned_speed` | integer | Assigned IAS (kts) |
| `assigned_mach` | number | Assigned Mach (e.g., 0.82) |
| `assigned_heading` | integer | Assigned heading (magnetic) |
| `scratchpad` | string | Primary scratchpad (8 chars ERAM, 3 chars STARS) |
| `scratchpad2` | string | Secondary scratchpad (ERAM) |
| `scratchpad3` | string | Tertiary scratchpad (ERAM) |
| `point_out_sector` | string | Point-out target sector |
| `coordination_status` | string | UNTRACKED/TRACKED/ASSOCIATED/SUSPENDED |
| `conflict_alert` | boolean | Conflict alert active |
| `msaw_alert` | boolean | MSAW alert active |

---

## Handoff Data

### Endpoint

```
POST /api/swim/v1/ingest/vnas/handoff
```

### Request Format

```json
{
  "facility_id": "ZDC",
  "handoffs": [
    {
      "callsign": "UAL123",
      "gufi": "VAT-20260127-UAL123-KORD-KJFK",
      "handoff_type": "AUTOMATED",
      "from_sector": "ZDC_33_CTR",
      "to_sector": "ZNY_42_CTR",
      "from_facility": "ZDC",
      "to_facility": "ZNY",
      "status": "INITIATED",
      "initiated_at": "2026-01-27T15:30:00Z",
      "accepted_at": null,
      "boundary_fix": "SWANN"
    }
  ]
}
```

### Field Reference

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `handoff_type` | string | **Yes** | AUTOMATED/MANUAL/POINT_OUT |
| `from_sector` | string | **Yes** | Transferring sector (e.g., "ZDC_33_CTR") |
| `to_sector` | string | **Yes** | Accepting sector |
| `status` | string | **Yes** | INITIATED/ACCEPTED/REJECTED/RECALLED/COMPLETED |
| `initiated_at` | string | **Yes** | ISO 8601 timestamp |
| `accepted_at` | string | Optional | When accepted |
| `boundary_fix` | string | Optional | Fix at sector boundary |

---

## Response Format

### Success Response (HTTP 200)

```json
{
  "success": true,
  "data": {
    "processed": 50,
    "updated": 48,
    "not_found": 2,
    "errors": 0,
    "error_details": []
  },
  "meta": {
    "source": "vnas",
    "facility": "ZDC",
    "system": "ERAM",
    "batch_size": 50
  },
  "timestamp": "2026-01-27T15:30:00Z"
}
```

### Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| `UNAUTHORIZED` | 401 | Invalid or missing API key |
| `NOT_AUTHORITATIVE` | 403 | API key lacks write permission |
| `MISSING_FACILITY` | 400 | facility_id is required |
| `INVALID_SYSTEM_TYPE` | 400 | system_type must be ERAM or STARS |
| `BATCH_TOO_LARGE` | 400 | Exceeds batch size limit |

---

## Example cURL Requests

### Track Update

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/vnas/track \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_sys_vnas_zdc_a1b2c3d4e5f6" \
  -d '{
    "facility_id": "ZDC",
    "system_type": "ERAM",
    "tracks": [{
      "callsign": "UAL123",
      "beacon_code": "4521",
      "position": {
        "latitude": 39.8561,
        "longitude": -77.0369,
        "altitude_ft": 35000,
        "ground_speed_kts": 485
      },
      "track_quality": {
        "mode_c": true,
        "mode_s": true,
        "position_quality": 9
      }
    }]
  }'
```

### Tags Update

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/vnas/tags \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_sys_vnas_zdc_a1b2c3d4e5f6" \
  -d '{
    "facility_id": "ZDC",
    "system_type": "ERAM",
    "tags": [{
      "callsign": "UAL123",
      "assigned_altitude": 35000,
      "assigned_speed": 280,
      "scratchpad": "KJFK/31L",
      "coordination_status": "TRACKED"
    }]
  }'
```

### Handoff Update

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/vnas/handoff \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_sys_vnas_zdc_a1b2c3d4e5f6" \
  -d '{
    "facility_id": "ZDC",
    "handoffs": [{
      "callsign": "UAL123",
      "handoff_type": "AUTOMATED",
      "from_sector": "ZDC_33_CTR",
      "to_sector": "ZNY_42_CTR",
      "status": "INITIATED",
      "initiated_at": "2026-01-27T15:30:00Z",
      "boundary_fix": "SWANN"
    }]
  }'
```

---

## Data Authority

vNAS has **priority 1** for track data in VATSWIM. This means:
- vNAS track updates will override data from lower-priority sources
- Track quality and beacon code from vNAS are considered authoritative

---

*Document version: 1.0.0 | Last updated: 2026-01-27*
