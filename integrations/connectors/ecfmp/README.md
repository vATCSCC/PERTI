# ECFMP VATSWIM Connector

Python client library for consuming ECFMP flow control data via the VATSWIM API.

## Architecture

ECFMP data flows server-side:
```
ECFMP API -> VATSWIM poll daemon -> tmi_flow_measures/events -> SWIM API
```

This connector reads the processed data from VATSWIM's REST endpoints. It does **not** push data to VATSWIM.

## Endpoints (READ-only)

| Endpoint | Auth | Description |
|----------|------|-------------|
| `/api/swim/v1/tmi/flow/events.php` | Any valid key | Active flow events |
| `/api/swim/v1/tmi/flow/measures.php` | Any valid key | Active flow measures |
| `/api/swim/v1/tmi/flow/providers.php` | Any valid key | Registered providers |

## Quick Start

```python
from vatswim_connector import VATSWIMConnector

connector = VATSWIMConnector("swim_dev_your_key_here")

# Get active flow events
events = connector.get_flow_events()

# Get active flow measures
measures = connector.get_flow_measures()

# Get registered providers
providers = connector.get_flow_providers()
```

## Authentication

Any valid SWIM API key works for reading flow data. Minimum tier: **Developer** (`swim_dev_`).

Request an API key at: https://perti.vatcscc.org/swim-keys.php

## Measure Types

ECFMP flow measures map to PERTI TMI concepts:

| ECFMP Measure Type | Description |
|---------------------|-------------|
| `MINIMUM_DEPARTURE_INTERVAL` | MDI between departures (minutes) |
| `AVERAGE_DEPARTURE_INTERVAL` | ADI target (minutes) |
| `PER_HOUR` | Flights per hour rate cap |
| `MILES_IN_TRAIL` | MIT spacing requirement |
| `MAX_IAS` | Maximum indicated airspeed |
| `MAX_MACH` | Maximum Mach number |
| `IAS_REDUCTION` | Speed reduction from normal |
| `MACH_REDUCTION` | Mach reduction from normal |
| `GROUND_STOP` | Full ground stop |
| `PROHIBIT` | Traffic prohibition |

## Registering as a Flow Provider

To register your FIR/facility as a flow data provider in VATSWIM, contact the vATCSCC team. Providers are managed in the `tmi_flow_providers` table.
