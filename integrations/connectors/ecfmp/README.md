# ECFMP VATSWIM Connector

Bidirectional Python client for consuming and pushing ECFMP flow control data via the VATSWIM API.

## Architecture

```
Read path:
  ECFMP API -> VATSWIM poll daemon -> tmi_flow_measures/events -> SWIM API

Push path:
  Provider -> VATSWIMConnector.push_flow_measures() -> /api/swim/v1/tmi/flow/ingest.php
```

## Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/swim/v1/tmi/flow/events.php` | GET | Any valid key | Active flow events |
| `/api/swim/v1/tmi/flow/measures.php` | GET | Any valid key | Active flow measures |
| `/api/swim/v1/tmi/flow/providers.php` | GET | Any valid key | Registered providers |
| `/api/swim/v1/tmi/flow/ingest.php` | POST | `swim_par_` or `swim_sys_` with `can_write=1` | Push flow measures |

## Quick Start

### Reading flow data

Any valid SWIM API key works for reading. Minimum tier: **Developer** (`swim_dev_`).

```python
from vatswim_connector import VATSWIMConnector

connector = VATSWIMConnector("swim_dev_your_key_here")

# Get active flow events
events = connector.get_flow_events()

# Get active flow measures (including historical)
measures = connector.get_flow_measures(active_only=False)

# Get registered providers
providers = connector.get_flow_providers()
```

### Pushing flow measures

Push requires a **Partner** (`swim_par_`) or **System** (`swim_sys_`) key with `can_write=1`.

```python
from vatswim_connector import VATSWIMConnector

connector = VATSWIMConnector("swim_par_your_key_here")

# Push a new MDI measure
result = connector.push_flow_measures([{
    "external_id": "67890",
    "ident": "EGLL_MDI_01",
    "ctl_element": "EGTT",
    "element_type": "FIR",
    "measure_type": "MDI",
    "measure_value": "120",
    "measure_unit": "SEC",
    "reason": "Weather at EGLL causing reduced acceptance rate",
    "filters_json": "{\"ades\":[\"EGLL\"],\"level_above\":240}",
    "start_utc": "2026-03-30T14:00:00Z",
    "end_utc": "2026-03-30T18:00:00Z",
    "status": "ACTIVE",
}])

# Withdraw a measure
result = connector.withdraw_flow_measure("67890", reason="Weather improved")
```

## Authentication

| Key prefix | Tier | Read | Push |
|------------|------|------|------|
| `swim_dev_` | Developer | Yes | No |
| `swim_par_` | Partner | Yes | Yes (requires `can_write=1`) |
| `swim_sys_` | System | Yes | Yes (requires `can_write=1`) |

Request an API key at: https://perti.vatcscc.org/swim-keys.php

## Methods

### Read methods

| Method | Description |
|--------|-------------|
| `get_flow_events(active_only=True)` | Get ECFMP flow events |
| `get_flow_measures(active_only=True)` | Get ECFMP flow measures |
| `get_flow_providers()` | Get registered flow data providers |
| `check_health()` | Check VATSWIM connector health |

### Push methods

| Method | Description |
|--------|-------------|
| `push_flow_measures(measures)` | Push up to 200 flow measures per batch |
| `withdraw_flow_measure(external_id, reason)` | Withdraw a single measure by external ID |

## Push payload fields

| Field | Required | Description |
|-------|----------|-------------|
| `external_id` | Yes | Provider's unique measure ID |
| `ident` | No | Human-readable identifier (e.g., "EGLL_MDI_01") |
| `ctl_element` | Yes | FIR or facility code (e.g., "EGTT") |
| `element_type` | No | "FIR" (default) or "FACILITY" |
| `measure_type` | Yes | MDI, MIT, RATE, GS, REROUTE, OTHER |
| `measure_value` | No | Numeric value (e.g., "120") |
| `measure_unit` | No | SEC, PER_HOUR, NM, KTS, MACH |
| `reason` | No | Reason for the measure |
| `filters_json` | No | JSON string of applicability filters |
| `mandatory_route_json` | No | JSON string for REROUTE type |
| `start_utc` | Yes | ISO 8601 start time |
| `end_utc` | Yes | ISO 8601 end time |
| `status` | No | NOTIFIED, ACTIVE, EXPIRED, WITHDRAWN |
| `withdrawn_at` | No | ISO 8601 withdrawal time |

## Measure Types

ECFMP flow measures map to PERTI TMI concepts:

| Measure Type | Description |
|--------------|-------------|
| `MDI` | Minimum Departure Interval (seconds) |
| `MIT` | Miles In Trail spacing requirement |
| `RATE` | Flights per hour rate cap |
| `GS` | Full ground stop |
| `REROUTE` | Mandatory reroute with route definition |
| `OTHER` | Other flow control measure |

## CLI usage

```bash
# Read active flow data (default)
python vatswim_connector.py YOUR_API_KEY

# Push a test measure
python vatswim_connector.py YOUR_API_KEY push

# Withdraw a measure
python vatswim_connector.py YOUR_API_KEY withdraw test_001
```

## Registering as a Flow Provider

To register your FIR/facility as a flow data provider in VATSWIM, contact the vATCSCC team. Providers are managed in the `tmi_flow_providers` table.
