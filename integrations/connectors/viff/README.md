# VATSWIM Connector for vIFF

Push EU ATFCM data from vIFF to the VATSWIM (System Wide Information Management) API.

## Overview

The vIFF connector pushes two types of data to VATSWIM:

| Data Type | VATSWIM Endpoint | Description |
|-----------|-----------------|-------------|
| CDM Milestones | `POST /api/swim/v1/ingest/cdm.php` | CTOT, EOBT, TOBT, ATFCM status per flight |
| Flow Measures | `POST /api/swim/v1/tmi/flow/ingest.php` | ATFCM regulations and restrictions |

## Requirements

- Python 3.7+
- VATSWIM API key with write access (`swim_sys_` or `swim_par_` prefix, `can_write=1`)
- No external dependencies (uses stdlib `urllib`)

## Quick Start

```python
from vatswim_connector import VIFFConnector

connector = VIFFConnector("swim_sys_viff_production_001")

# Push CDM milestones
result = connector.push_cdm_milestones([{
    "callsign": "BAW123",
    "airport": "EGLL",
    "tobt": "2026-03-30T14:30:00Z",
    "tsat": "2026-03-30T14:35:00Z",
    "readiness_state": "READY",
    "source": "VIFF_CDM"
}])

# Push flow regulation
result = connector.push_flow_measures([{
    "external_id": "VIFF-REG-EGLL001",
    "ctl_element": "EGTT",
    "measure_type": "MDI",
    "measure_value": "120",
    "measure_unit": "SEC",
    "start_utc": "2026-03-30T14:00:00Z",
    "end_utc": "2026-03-30T18:00:00Z",
    "status": "ACTIVE"
}])
```

## Converting vIFF Native Format

The connector includes helpers to convert vIFF's native HHMM time format:

```python
# Convert /etfms/relevant response to CDM format
raw_flights = [{"callsign": "BAW123", "departure": "EGLL", "eobt": "1430", "isCdm": True, ...}]
cdm_updates = connector.convert_etfms_to_cdm(raw_flights)
result = connector.push_cdm_milestones(cdm_updates)

# Convert /etfms/restricted to flow measure format
restrictions = [{"callsign": "BAW123", "ctot": "1445", "mostPenalizingAirspace": "EGLL_ARR"}]
measures = connector.convert_restrictions_to_flow(restrictions, fir_code="EGTT")
result = connector.push_flow_measures(measures)
```

## Batch Limits

| Endpoint | Max Batch Size |
|----------|---------------|
| CDM Milestones | 500 per request |
| Flow Measures | 200 per request |

## API Key Tiers

| Tier | Prefix | Rate Limit | Write Access |
|------|--------|-----------|-------------|
| System | `swim_sys_` | 30,000/min | Yes |
| Partner | `swim_par_` | 3,000/min | Yes (if `can_write=1`) |

## Error Handling

The connector returns parsed JSON responses. Check `success` field:

```python
result = connector.push_cdm_milestones(updates)
if result.get("success"):
    print(f"Updated {result['data']['updated']} flights")
else:
    print(f"Error: {result.get('error')}")
```

Automatic retry is not built in (vIFF has its own retry logic). HTTP 429 and 5xx errors are returned as-is.
