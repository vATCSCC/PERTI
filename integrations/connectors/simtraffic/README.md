# SimTraffic VATSWIM Connector

Python client library for integrating SimTraffic with the VATSWIM API.

## Endpoint

| Endpoint | Batch Limit | Auth Field | Description |
|----------|-------------|------------|-------------|
| `/api/swim/v1/ingest/simtraffic.php` | 500 | `metering` | Departure/arrival timing, metering data |

## Quick Start

```python
from vatswim_connector import VATSWIMConnector

connector = VATSWIMConnector("swim_sys_your_key_here")

result = connector.send_flight_times([{
    "callsign": "UAL123",
    "departure": {"takeoff_time": "2026-03-06T14:45:00Z"},
    "arrival": {"eta": "2026-03-06T17:15:00Z", "metering_fix": "CAMRN"}
}])
```

## Authentication

Requires a **System tier** API key (`swim_sys_` prefix) with `metering` write authority.

Request an API key at: https://perti.vatcscc.org/swim-keys.php

## Integration Type

SimTraffic is **bidirectional**:
- **Push mode**: SimTraffic sends timing data to VATSWIM (this connector)
- **Poll mode**: VATSWIM polls the SimTraffic API for flight times (server-side daemon)

## Field Mapping

| SimTraffic Field | SWIM Field | CDM Reference |
|------------------|------------|---------------|
| `departure.push_time` | `out_utc` | T13 AOBT |
| `departure.takeoff_time` | `off_utc` | T11 ATOT |
| `arrival.eta` | `eta_runway_utc` | - |
| `arrival.eta_mf` | `metering_time` | - |
| `arrival.on_time` | `on_utc` | T12 ALDT |
| `arrival.metering_fix` | `metering_point` | - |

## Data Authority

SimTraffic is the **Priority 1** source for metering and flight timing data.
