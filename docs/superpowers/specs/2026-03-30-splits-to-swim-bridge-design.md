# Splits-to-SWIM Bridge Design

**Date**: 2026-03-30
**Status**: Approved
**Approach**: B — Full SWIM Mirror (security segregation)

## Overview

Bidirectional bridge for center facility sector split data through VATSWIM:

- **Inbound**: Generic SWIM API ingest endpoint for any facility tool (CRC, EuroScope, vNAS, etc.) to push split configurations into PERTI
- **Outbound**: SWIM REST API + WebSocket events for external consumers to query and subscribe to active/saved split data

All external reads go through SWIM_API mirror tables — never direct ADL access. This provides security segregation between operational data (VATSIM_ADL) and the public API surface (SWIM_API).

## Data Model

### VATSIM_ADL — Source Columns (New Migration)

Add two columns to `splits_configs`:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `source` | NVARCHAR(50) | `'perti'` | Origin system: `perti` (UI), `swim_api` (external push), or connector name |
| `source_id` | NVARCHAR(100) | NULL | External system's identifier for correlation/idempotency |

Existing UI-created configs backfill to `source='perti'`, `source_id=NULL`.

### SWIM_API — Mirror Tables (New Migration)

**`splits_configs_swim`** — All non-archived configs:

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK | Same ID as ADL source |
| `artcc` | NVARCHAR(4) | Facility code |
| `config_name` | NVARCHAR(100) | |
| `status` | NVARCHAR(20) | draft/scheduled/active/inactive |
| `start_time_utc` | DATETIME2 | |
| `end_time_utc` | DATETIME2 | |
| `sector_type` | NVARCHAR(10) | high/low |
| `source` | NVARCHAR(50) | Origin system |
| `source_id` | NVARCHAR(100) | External reference |
| `created_by` | NVARCHAR(50) | |
| `activated_at` | DATETIME2 | |
| `created_at` | DATETIME2 | |
| `updated_at` | DATETIME2 | |
| `synced_at` | DATETIME2 | Last sync timestamp |

**`splits_positions_swim`** — Positions for mirrored configs:

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK | Same ID as ADL source |
| `config_id` | INT FK | → splits_configs_swim |
| `position_name` | NVARCHAR(50) | |
| `sectors` | NVARCHAR(MAX) | JSON array of sector IDs |
| `color` | NVARCHAR(10) | Hex color |
| `sort_order` | INT | Display order |
| `frequency` | NVARCHAR(20) | |
| `controller_oi` | NVARCHAR(50) | VATSIM CID |
| `strata_filter` | NVARCHAR(100) | JSON `{"low":true,"high":true,"superhigh":false}` |
| `start_time_utc` | DATETIME2 | Position-specific override |
| `end_time_utc` | DATETIME2 | Position-specific override |

**`splits_presets_swim`** — Preset templates:

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK | Same ID as ADL source |
| `preset_name` | NVARCHAR(100) | |
| `artcc` | NVARCHAR(4) | |
| `description` | NVARCHAR(500) | |
| `created_at` | DATETIME2 | |
| `updated_at` | DATETIME2 | |
| `synced_at` | DATETIME2 | |

**`splits_preset_positions_swim`** — Positions within presets:

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK | Same ID as ADL source |
| `preset_id` | INT FK | → splits_presets_swim |
| `position_name` | NVARCHAR(50) | |
| `sectors` | NVARCHAR(MAX) | JSON array |
| `color` | NVARCHAR(10) | |
| `sort_order` | INT | |
| `frequency` | NVARCHAR(20) | |
| `strata_filter` | NVARCHAR(100) | JSON |

**`splits_areas_swim`** — Predefined sector groupings:

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK | Same ID as ADL source |
| `artcc` | NVARCHAR(4) | |
| `area_name` | NVARCHAR(100) | |
| `sectors` | NVARCHAR(MAX) | JSON array |
| `description` | NVARCHAR(500) | |
| `color` | NVARCHAR(10) | |
| `created_at` | DATETIME2 | |
| `updated_at` | DATETIME2 | |
| `synced_at` | DATETIME2 | |

**`splits_history_swim`** — Append-only audit log of state transitions:

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT IDENTITY PK | |
| `config_id` | INT | Source config ID |
| `facility` | NVARCHAR(4) | |
| `event_type` | NVARCHAR(20) | `activated`, `deactivated`, `modified`, `ingested` |
| `config_snapshot` | NVARCHAR(MAX) | JSON snapshot of config + positions at event time |
| `source` | NVARCHAR(50) | Origin system |
| `event_at` | DATETIME2 | When the transition occurred |
| `synced_at` | DATETIME2 | When SWIM received it |

Retention: 30 days (cleanup in sync daemon cycle).

## Inbound: SWIM Ingest Endpoint

### `POST /api/swim/v1/splits/ingest.php`

**Auth**: `swim_init_auth(true, true)` — requires valid API key with `can_write=1` (partner or system tier).

**Request payload** (JSON):

```json
{
  "facility": "ZNY",
  "config_name": "ZNY Evening Config",
  "sector_type": "high",
  "start_time_utc": "2026-03-30T22:00:00Z",
  "end_time_utc": "2026-03-31T06:00:00Z",
  "source_id": "crc-session-abc123",
  "positions": [
    {
      "position_name": "NY_E_CTR",
      "sectors": ["ZNY07", "ZNY08", "ZNY09"],
      "frequency": "132.55",
      "controller_oi": "1234567",
      "color": "#4dabf7",
      "strata_filter": {"low": true, "high": true, "superhigh": false}
    }
  ]
}
```

**Validation**:
- `facility` required, must exist in `artcc_facilities`
- `positions` required, non-empty array
- Each position must have `position_name` and `sectors` (non-empty array)
- `sector_type` must be `high` or `low`
- `start_time_utc` / `end_time_utc` must be valid ISO 8601 if provided

**Flow**:
1. SwimAuth validates API key (must be write-capable)
2. Validate payload structure and field values
3. Check idempotency: if `source_id` already exists for this facility + `source='swim_api'`, upsert (replace existing config) rather than create duplicate
4. Write to VATSIM_ADL `splits_configs` with `source='swim_api'`, `source_id` from payload
5. Write positions to `splits_positions`
6. Trigger scheduler: `UPDATE scheduler_state SET next_run_at = GETUTCDATE()`
7. Fire WebSocket event (`splits.ingested` or `splits.updated`)
8. Return `201 Created` with config ID

**Data authority**: API-ingested configs (`source='swim_api'`) can only be updated/replaced by the same API key or a higher-tier key. UI-created configs (`source='perti'`) are not modifiable via the ingest endpoint.

**Response**:
```json
{
  "config_id": 42,
  "status": "scheduled",
  "source": "swim_api",
  "source_id": "crc-session-abc123",
  "positions_count": 1
}
```

## Outbound: SWIM REST Endpoints

All endpoints under `/api/swim/v1/splits/`. All require a valid SWIM API key (any tier, read-only). All support `?format=json|fixm|xml` via `SwimFormat`. All read from SWIM_API mirror tables only.

### Common Query Parameters

| Param | Values | Default | Description |
|-------|--------|---------|-------------|
| `facility` | ARTCC code (e.g., `ZNY`) | all | Filter by facility |
| `strata` | `superhigh`, `high`, `low`, `all` | `all` | Filter by altitude stratum |
| `format` | `json`, `fixm`, `xml` | `json` | Response format |

### `GET /api/swim/v1/splits/active.php`

Active split configurations across all facilities.

- Reads from `splits_configs_swim` WHERE `status = 'active'`
- Filters by `sector_type` matching `strata` param
- Filters positions by `strata_filter` JSON field
- Includes nested positions array
- Response caching: `SwimResponse::successCached()` with 30s TTL

### `GET /api/swim/v1/splits/facility.php`

Active splits for a single facility (required `?facility=` param).

- Same as `active.php` but filtered to one ARTCC
- Optional `?include_scheduled=1` to also return upcoming configs
- Use case: "What's staffed at ZNY right now?"

### `GET /api/swim/v1/splits/configs.php`

All saved configurations (any status).

- Query params: `?status=draft|scheduled|active|inactive|archived`
- Returns full config + positions regardless of lifecycle state
- Use case: facility tool browsing/loading previously created configurations

### `GET /api/swim/v1/splits/presets.php`

Reusable preset templates.

- Reads from `splits_presets_swim` + `splits_preset_positions_swim`
- Filters by `?facility=` and `?strata=`
- Use case: facility tool fetching available templates to quick-apply

### `GET /api/swim/v1/splits/areas.php`

Predefined sector area groupings.

- Reads from `splits_areas_swim`
- Filters by `?facility=`
- Returns area name, sectors array, color, description

### `GET /api/swim/v1/splits/history.php`

Recent split state transitions.

- Reads from `splits_history_swim`
- Query params: `?facility=`, `?since=ISO8601`, `?limit=50` (default 50, max 500)
- Returns configs that transitioned within the time window
- Includes JSON snapshot of config state at transition time
- Use case: "What changed in the last 2 hours?"

### API Index Update

Replace placeholder in `/api/swim/v1/index.php`:

```
GET  /api/swim/v1/splits/active      — Active split configurations
GET  /api/swim/v1/splits/facility    — Active splits for a specific facility
GET  /api/swim/v1/splits/configs     — All saved configurations (any status)
GET  /api/swim/v1/splits/presets     — Reusable preset templates
GET  /api/swim/v1/splits/areas       — Predefined sector area groupings
GET  /api/swim/v1/splits/history     — Recent split state transitions
POST /api/swim/v1/splits/ingest      — Push split configuration from external tool
```

## Sync Pipeline

### Tier 1 — Operational Sync (Every 5 Minutes)

Added to `swim_tmi_sync_daemon.php` as `syncSplitsToSwim()`, running in the existing Tier 1 cycle:

1. Read from VATSIM_ADL: `SELECT * FROM splits_configs WHERE status NOT IN ('archived') AND updated_at > @last_sync_watermark`
2. For each changed config, read positions: `SELECT * FROM splits_positions WHERE config_id IN (...)`
3. UPSERT into `splits_configs_swim` + `splits_positions_swim` (DELETE + INSERT within transaction)
4. Delete from SWIM mirrors any configs now archived or deleted in ADL
5. Detect state transitions (status changed since last sync) and append to `splits_history_swim`
6. Update watermark

### Tier 2 — Reference Sync (Daily 0601-0801Z)

Presets and areas sync on the existing daily reference cycle:

1. Full sync `splits_presets` + `splits_preset_positions` → `splits_presets_swim` + `splits_preset_positions_swim`
2. Full sync `splits_areas` → `splits_areas_swim`
3. These change rarely; full replacement (TRUNCATE + INSERT) is acceptable

### Retention

- `splits_history_swim`: 30-day retention, purged in sync cycle
- Mirror tables: no retention — reflect current ADL state

## WebSocket Events

Three new event types published via `/tmp/swim_ws_events.json` (atomic write pattern):

### `splits.activated`

Fired when a config transitions to active (by scheduler daemon or immediate activation).

```json
{
  "type": "splits.activated",
  "data": {
    "config_id": 42,
    "facility": "ZNY",
    "config_name": "ZNY Evening Config",
    "sector_type": "high",
    "source": "swim_api",
    "start_time_utc": "2026-03-30T22:00:00Z",
    "end_time_utc": "2026-03-31T06:00:00Z",
    "positions": [
      {
        "position_name": "NY_E_CTR",
        "sectors": ["ZNY07", "ZNY08", "ZNY09"],
        "frequency": "132.55",
        "controller_oi": "1234567"
      }
    ]
  }
}
```

### `splits.updated`

Fired when an active config is modified (positions changed, controller swap, etc.). Same payload shape as `splits.activated` with full current state.

### `splits.deactivated`

Fired when a config transitions to inactive/expired.

```json
{
  "type": "splits.deactivated",
  "data": {
    "config_id": 42,
    "facility": "ZNY",
    "config_name": "ZNY Evening Config",
    "deactivated_at": "2026-03-31T06:00:00Z",
    "reason": "expired"
  }
}
```

Reasons: `expired` (end_time reached), `manual` (user deactivated), `replaced` (new config superseded).

### Event Sources

Two trigger points:

1. **Ingest endpoint** (`/api/swim/v1/splits/ingest.php`) — fires `splits.updated` immediately when external tool pushes data; fires `splits.activated` if the config is auto-activated
2. **Scheduler daemon** (`scripts/scheduler_daemon.php`) — fires `splits.activated` on auto-activation and `splits.deactivated` on expiration

### WebSocket Subscription

Clients subscribe via `splits.*` (all events) or specific types (`splits.activated`). Follows existing subscription pattern in `WebSocketServer.php`.

## Auth & Data Authority

### Read Access

All `GET /api/swim/v1/splits/*` endpoints: any valid SWIM API key (all tiers: public, developer, partner, system).

### Write Access

`POST /api/swim/v1/splits/ingest`: requires `can_write=1` (partner or system tier).

### Data Authority Rules

| Source | Can Create | Can Update Own | Can Update Others |
|--------|-----------|---------------|-------------------|
| `perti` (UI) | Yes (via UI) | Yes (via UI) | No |
| `swim_api` (external) | Yes (via ingest) | Yes (same source_id) | No |
| `system` tier key | Yes | Yes | Yes (override) |

- API-ingested configs (`source='swim_api'`) are only modifiable via the ingest endpoint by the same `source_id` or a system-tier key
- UI-created configs (`source='perti'`) are not modifiable via the ingest endpoint
- System-tier keys can override any config (admin escape hatch)

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `database/migrations/splits/010_splits_source_columns.sql` | Add `source` + `source_id` to ADL `splits_configs` |
| `database/migrations/swim/XXX_splits_swim_mirrors.sql` | Create 7 SWIM mirror tables |
| `api/swim/v1/splits/ingest.php` | Inbound ingest endpoint |
| `api/swim/v1/splits/active.php` | Active splits query |
| `api/swim/v1/splits/facility.php` | Single facility query |
| `api/swim/v1/splits/configs.php` | All configs query |
| `api/swim/v1/splits/presets.php` | Preset templates query |
| `api/swim/v1/splits/areas.php` | Area groupings query |
| `api/swim/v1/splits/history.php` | State transition history |

### Modified Files

| File | Change |
|------|--------|
| `scripts/swim_tmi_sync_daemon.php` | Add `syncSplitsToSwim()` function + Tier 1/2 calls |
| `scripts/swim_ws_events.php` | Add splits event detection + publishing |
| `scripts/scheduler_daemon.php` | Fire WebSocket events on activation/deactivation |
| `api/swim/v1/index.php` | Update API index with splits endpoints |
| `api/swim/v1/ws/WebSocketServer.php` | Register `splits.*` subscription channels |
| `api/splits/configs.php` | Write `source='perti'` on UI-created configs |
| `api/splits/ingest.php` (internal) | N/A — ingest is SWIM-only |
