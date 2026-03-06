# vACDM VATSWIM Integration Guide

> Integration guide for connecting vACDM (virtual Airport Collaborative Decision Making) instances with VATSWIM.

## Overview

vACDM instances push A-CDM departure milestone data to VATSWIM, enabling centralized departure readiness tracking across the VATSIM network. VATSWIM also polls registered vACDM providers for real-time updates.

### Architecture

```
vACDM Instance ──push──> POST /api/swim/v1/ingest/cdm.php ──> swim_flights + sp_CDM_UpdateReadiness
                                                                       ↕
VATSWIM daemon <──poll── vACDM Provider API                     tmi_flow_providers
```

## Endpoint

| Method | Path | Auth | Batch |
|--------|------|------|-------|
| POST | `/api/swim/v1/ingest/cdm.php` | `swim_sys_` or `swim_par_` with `cdm` authority | 500 |

## A-CDM Milestones

VATSWIM supports the standard A-CDM milestone framework:

| Milestone | Field | Type | Description |
|-----------|-------|------|-------------|
| TOBT | `tobt` | ISO 8601 datetime | Target Off-Block Time |
| TSAT | `tsat` | ISO 8601 datetime | Target Startup Approval Time |
| TTOT | `ttot` | ISO 8601 datetime | Target Takeoff Time |
| ASAT | `asat` | ISO 8601 datetime | Actual Startup Approval Time |
| EXOT | `exot` | Integer (minutes) | Expected Taxi Out Time |

### SWIM Database Mapping

| CDM Field | SWIM Column |
|-----------|-------------|
| `tobt` | `target_off_block_time` |
| `tsat` | `target_startup_approval_time` |
| `ttot` | `target_takeoff_time` |
| `asat` | `actual_startup_approval_time` |
| `exot` | `expected_taxi_out_time` |

## Readiness States

The `readiness_state` field tracks the departure readiness lifecycle:

| State | Description | Transition |
|-------|-------------|------------|
| `PLANNING` | Flight plan filed, pilot not yet at gate | Initial state |
| `BOARDING` | At gate, passengers boarding | After gate assignment |
| `READY` | Ready for pushback, all checks complete | After boarding complete |
| `TAXIING` | Pushed back, taxiing to runway | After pushback approved |
| `CANCELLED` | Flight cancelled | Terminal state |

VATSWIM calls `sp_CDM_UpdateReadiness` in VATSIM_TMI to update the readiness state, which feeds the GDT (Ground Delay Table) and compliance tracking.

## Payload Format

```json
{
  "updates": [
    {
      "callsign": "BAW123",
      "gufi": "VAT-20260306-BAW123-EGLL-KJFK",
      "airport": "EGLL",
      "tobt": "2026-03-06T14:30:00Z",
      "tsat": "2026-03-06T14:35:00Z",
      "ttot": "2026-03-06T14:40:00Z",
      "asat": "2026-03-06T14:36:00Z",
      "exot": 5,
      "readiness_state": "READY",
      "source": "VACDM"
    }
  ]
}
```

### Field Requirements

| Field | Required | Notes |
|-------|----------|-------|
| `callsign` | Yes | Aircraft callsign for flight lookup |
| `gufi` | No | Direct GUFI lookup (faster if known) |
| `airport` | No | Departure airport ICAO (helps with disambiguation) |
| `tobt` | No | At least one milestone must be present |
| `tsat` | No | |
| `ttot` | No | |
| `asat` | No | |
| `exot` | No | Minutes (integer) |
| `readiness_state` | No | Must be a valid state (see above) |
| `source` | No | Defaults to your API key's source_id |

## Example Request

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/cdm.php" \
  -H "Authorization: Bearer swim_sys_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{
    "updates": [
      {
        "callsign": "BAW123",
        "airport": "EGLL",
        "tobt": "2026-03-06T14:30:00Z",
        "tsat": "2026-03-06T14:35:00Z",
        "readiness_state": "READY"
      }
    ]
  }'
```

### Response

```json
{
  "success": true,
  "processed": 1,
  "updated": 1,
  "not_found": 0,
  "readiness_updated": 1,
  "errors": []
}
```

## Multi-Airport Support

vACDM instances can serve multiple airports. Each update includes the `airport` field to identify which airport's CDM process the milestone belongs to.

When polling, VATSWIM discovers vACDM providers via the `tmi_flow_providers` table, which stores:
- Provider API URL
- Supported airports
- Sync interval and status
- Authentication credentials

## Provider Registration

To register your vACDM instance as a VATSWIM CDM provider:

1. Contact the vATCSCC development team
2. Provide your vACDM API endpoint URL
3. List the airports your instance serves
4. You'll receive a `provider_id` in the `tmi_flow_providers` table

Once registered, VATSWIM's `vacdm_poll_daemon.php` will poll your API every 120 seconds for updates.

## Rate Limits and Batching

- **Maximum batch size:** 500 CDM updates per request
- **Rate limit:** 30,000/min (system), 3,000/min (partner)
- **Recommended cadence:** Push updates when milestones change, not on a fixed timer
- **Deduplication:** VATSWIM skips updates where all fields match current values

## Circuit Breaker

The poll daemon uses a per-provider circuit breaker:
- **Window:** 60 seconds
- **Max errors:** 6 errors within the window
- **Cooldown:** 180 seconds (3 minutes)

If your provider API returns repeated errors, polling will pause for 3 minutes before retrying.

## Client SDK

A JavaScript/Node.js client library is available at [`integrations/connectors/vacdm/`](../../integrations/connectors/vacdm/).

```javascript
const { VATSWIMConnector } = require('./vatswim-connector');
const connector = new VATSWIMConnector('swim_sys_your_key_here');

await connector.sendCDMUpdates([{
    callsign: 'BAW123',
    airport: 'EGLL',
    tobt: '2026-03-06T14:30:00Z',
    readiness_state: 'READY'
}]);
```
