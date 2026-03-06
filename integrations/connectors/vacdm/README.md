# vACDM VATSWIM Connector

JavaScript/Node.js client library for integrating vACDM instances with the VATSWIM API.

## Endpoint

| Endpoint | Batch Limit | Auth Field | Description |
|----------|-------------|------------|-------------|
| `/api/swim/v1/ingest/cdm.php` | 500 | `cdm` | A-CDM milestone data |

## Quick Start

```javascript
const { VATSWIMConnector } = require('./vatswim-connector');

const connector = new VATSWIMConnector('swim_sys_your_key_here');

const result = await connector.sendCDMUpdates([{
    callsign: 'BAW123',
    airport: 'EGLL',
    tobt: '2026-03-06T14:30:00Z',
    tsat: '2026-03-06T14:35:00Z',
    ttot: '2026-03-06T14:40:00Z',
    readiness_state: 'READY'
}]);
```

## Authentication

Requires a **System** or **Partner tier** API key with `cdm` write authority.

Request an API key at: https://perti.vatcscc.org/swim-keys.php

## Integration Type

vACDM is **bidirectional**:
- **Push**: vACDM instances push CDM milestones to VATSWIM (this connector)
- **Poll**: VATSWIM polls active vACDM providers (server-side daemon)

## A-CDM Milestones

| Field | Description | SWIM Column |
|-------|-------------|-------------|
| `tobt` | Target Off-Block Time | `target_off_block_time` |
| `tsat` | Target Startup Approval Time | `target_startup_approval_time` |
| `ttot` | Target Takeoff Time | `target_takeoff_time` |
| `asat` | Actual Startup Approval Time | `actual_startup_approval_time` |
| `exot` | Expected Taxi Out Time (minutes) | `expected_taxi_out_time` |

## Readiness States

The `readiness_state` field follows the A-CDM lifecycle:

1. **PLANNING** - Flight plan filed, not yet at gate
2. **BOARDING** - At gate, passengers boarding
3. **READY** - Ready for pushback
4. **TAXIING** - Pushed back, taxiing to runway

## Provider Registration

vACDM instances are registered in VATSWIM's `tmi_flow_providers` table. Contact the vATCSCC team to register your vACDM instance as a CDM data provider.
