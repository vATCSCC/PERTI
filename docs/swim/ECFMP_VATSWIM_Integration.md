# ECFMP VATSWIM Integration Guide

> Integration guide for consuming ECFMP (European Collaborative Flow Management Programme) data via the VATSWIM API.

## Overview

ECFMP provides flow control measures for European FIRs on VATSIM. VATSWIM polls the ECFMP API server-side and exposes the processed data through REST endpoints. External consumers read this data from VATSWIM rather than polling ECFMP directly.

### Architecture

```
ECFMP API ──poll (300s)──> ecfmp_poll_daemon.php ──> tmi_flow_measures
                                                      tmi_flow_events
                                                      tmi_flow_providers
                                                           ↓
Consumers <────────────── GET /api/swim/v1/tmi/flow/* ────┘
```

**Key point:** ECFMP integration is **read-only** from the consumer's perspective. VATSWIM handles all polling, transformation, and storage.

## Endpoints

All endpoints are READ-only and accept any valid SWIM API key.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/swim/v1/tmi/flow/events.php` | Active flow events |
| GET | `/api/swim/v1/tmi/flow/measures.php` | Active flow measures |
| GET | `/api/swim/v1/tmi/flow/providers.php` | Registered flow providers |

### Query Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `active` | `0\|1` | `1` | Filter to active events/measures only |
| `fir` | string | - | Filter by FIR code (e.g., `EGTT`) |
| `provider` | string | - | Filter by provider code (e.g., `ECFMP`) |

## Flow Events

Flow events represent time-bounded flow management scenarios (e.g., "EGLL Arrival Congestion").

```bash
curl -H "Authorization: Bearer swim_dev_your_key" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/flow/events.php?active=1"
```

### Response

```json
{
  "success": true,
  "data": [
    {
      "event_id": 42,
      "provider_code": "ECFMP",
      "ecfmp_event_id": 12345,
      "name": "EGLL Arrival Congestion",
      "start_utc": "2026-03-06T14:00:00Z",
      "end_utc": "2026-03-06T18:00:00Z",
      "fir_codes": ["EGTT", "EGPX"],
      "status": "active",
      "created_at": "2026-03-06T13:45:00Z"
    }
  ]
}
```

## Flow Measures

Flow measures are the specific restrictions applied during flow events.

```bash
curl -H "Authorization: Bearer swim_dev_your_key" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/flow/measures.php?active=1"
```

### Response

```json
{
  "success": true,
  "data": [
    {
      "measure_id": 99,
      "event_id": 42,
      "ecfmp_measure_id": 67890,
      "measure_type": "MINIMUM_DEPARTURE_INTERVAL",
      "value": 10,
      "unit": "minutes",
      "affected_firs": ["EGTT"],
      "filters": {
        "airport_arrival": ["EGLL"],
        "level_above": 240
      },
      "start_utc": "2026-03-06T14:00:00Z",
      "end_utc": "2026-03-06T18:00:00Z",
      "status": "active"
    }
  ]
}
```

## Measure Types

ECFMP flow measures are mapped to PERTI TMI concepts:

| Measure Type | Description | Unit |
|-------------|-------------|------|
| `MINIMUM_DEPARTURE_INTERVAL` | MDI between departures | minutes |
| `AVERAGE_DEPARTURE_INTERVAL` | ADI target | minutes |
| `PER_HOUR` | Maximum flights per hour | flights/hour |
| `MILES_IN_TRAIL` | MIT spacing | nautical miles |
| `MAX_IAS` | Maximum indicated airspeed | knots |
| `MAX_MACH` | Maximum Mach number | mach |
| `IAS_REDUCTION` | IAS reduction from normal | knots |
| `MACH_REDUCTION` | Mach reduction from normal | mach |
| `GROUND_STOP` | Full ground stop | boolean |
| `PROHIBIT` | Traffic prohibition | boolean |

### Measure Filters

Measures can be scoped using filters:

| Filter | Type | Description |
|--------|------|-------------|
| `airport_arrival` | string[] | Arrival airports affected |
| `airport_departure` | string[] | Departure airports affected |
| `level_above` | integer | Minimum flight level (e.g., FL240) |
| `level_below` | integer | Maximum flight level |
| `member_event` | integer | ECFMP event ID |
| `member_not_event` | integer | Exclude event ID |
| `waypoint` | string[] | Waypoints that must be in the route |

## Provider Registry

Flow data providers are tracked in VATSWIM's `tmi_flow_providers` table. Currently registered providers:

| Provider | Code | Sync Interval | Description |
|----------|------|---------------|-------------|
| vATCSCC | `VATCSCC` | Internal | Local TMI programs (GDP/GS/AFP) |
| ECFMP | `ECFMP` | 300s | European flow management |

### Becoming a Flow Provider

To register a new flow data provider (e.g., for a regional VATSIM facility):

1. Implement a REST API that returns flow events and measures in ECFMP-compatible format
2. Contact the vATCSCC development team with your API URL
3. Your provider will be added to `tmi_flow_providers` with appropriate sync configuration

## Poll Daemon Details

VATSWIM's `ecfmp_poll_daemon.php` runs server-side:

- **Poll interval:** 300 seconds (5 minutes)
- **External API:** `https://ecfmp.vatsim.net/api/v1`
- **Circuit breaker:** 6 errors in 60s triggers 180s cooldown
- **Data stored in:** `tmi_flow_events`, `tmi_flow_measures`
- **Started by:** `scripts/startup.sh` (runs during both normal and hibernation mode)

The daemon fetches all active events and measures, diffs against cached data, and only writes changes to the database.

## Client SDK

A Python client library for reading ECFMP data from VATSWIM is available at [`integrations/connectors/ecfmp/`](../../integrations/connectors/ecfmp/).

```python
from vatswim_connector import VATSWIMConnector

connector = VATSWIMConnector("swim_dev_your_key_here")

events = connector.get_flow_events()
measures = connector.get_flow_measures()
providers = connector.get_flow_providers()
```
