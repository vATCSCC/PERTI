# SimTraffic-VATSWIM Bidirectional Webhook Bridge

**Date**: 2026-03-30
**Status**: Design
**Replaces**: Per-flight polling model (`simtraffic_swim_poll.php`)

## Overview

Replace the current per-flight REST polling pattern (5 req/sec, 120s cycle, 50-flight batches) with a bidirectional event-driven architecture. SimTraffic pushes full gate-to-gate lifecycle events to PERTI via REST webhooks and/or WebSocket. PERTI pushes TMI control events (EDCTs, ground stops, reroutes) back to SimTraffic via the same two channels.

**Dual transport**: REST webhooks provide guaranteed at-least-once delivery. WebSocket provides low-latency real-time streaming. Either channel can be used independently; together they give reliability + speed.

## Event Model

### Inbound: SimTraffic -> PERTI (Lifecycle Events)

| Event Type | Trigger | Key Data |
|---|---|---|
| `flight.filed` | Flight plan filed | callsign, route, dep/arr, aircraft type, ETD |
| `flight.preflight` | Pushback request / TOBT set | TOBT, gate, status |
| `flight.taxi` | Taxi start | taxi start time, runway assigned |
| `flight.departed` | Wheels off | ATOT, runway, departure sequence time |
| `flight.enroute` | ARTCC handoff / position update | current ARTCC, altitude, metering data |
| `flight.metering` | Meter fix crossing / delay update | meter fix, STA, delay, sequence position |
| `flight.approach` | Within ~50nm of destination | ETA, runway, approach type |
| `flight.landed` | Wheels on | ALDT, runway |
| `flight.arrived` | In-gate | AIBT, gate, taxi-in time |
| `flight.cancelled` | Flight cancelled / removed | reason |

### Outbound: PERTI -> SimTraffic (TMI Control Events)

| Event Type | Trigger | Key Data |
|---|---|---|
| `tmi.edct_assigned` | GDP slot assignment | callsign, EDCT, program, reason |
| `tmi.edct_revised` | EDCT updated | callsign, old/new EDCT, reason |
| `tmi.edct_cancelled` | EDCT removed | callsign, reason |
| `tmi.ground_stop` | GS issued | airport, start/end, reason |
| `tmi.ground_stop_lifted` | GS ended | airport, end time |
| `tmi.reroute` | Reroute advisory | callsign, new route, reason |
| `tmi.gate_hold` | A-CDM gate hold | callsign, TSAT, reason |
| `tmi.gate_release` | A-CDM pushback approved | callsign, TTOT |

### Event Envelope

Every event uses a standard envelope:

```json
{
  "event_id": "evt_a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "event_type": "flight.departed",
  "timestamp": "2026-03-30T18:45:00.000Z",
  "source": "simtraffic",
  "data": {
    "callsign": "UAL123",
    "gufi": "VAT-20260330-UAL123-KORD-KJFK",
    "departure_afld": "KORD",
    "arrival_afld": "KJFK",
    "departure": {
      "takeoff_time": "2026-03-30T18:45:00Z",
      "runway": "10C",
      "sequence_time": "2026-03-30T18:42:00Z"
    }
  }
}
```

Batch payloads wrap multiple events:

```json
{
  "batch_id": "batch_...",
  "events": [ ... ],
  "count": 50
}
```

## Transport Architecture

### REST Webhooks (Guaranteed Delivery)

```
SimTraffic                                    PERTI
---------                                    -----
POST /api/swim/v1/webhooks/simtraffic  -->  Webhook Receiver
  (lifecycle events, batched)                 |-- HMAC-SHA256 verify
                                              |-- Idempotency (event_id dedup)
                                              |-- processSimTrafficFlight() per event
                                              |-- Log to swim_webhook_events
                                              '-- Return 200 {accepted, duplicates}

POST https://hooks.simtraffic.net/vatswim <-- Webhook Sender (delivery daemon)
  (TMI events, batched)                       |-- Retry queue (3 attempts, exponential)
                                              |-- HMAC-SHA256 signed
                                              '-- Dead letter after 3 failures
```

**Batching**: Up to 50 events or 5 seconds, whichever comes first.

**Retry schedule**: 10s / 30s / 90s exponential backoff. After 3 failures, event moves to `dead` status.

**Signature**:
```
X-VATSWIM-Signature: sha256=<hmac>
X-VATSWIM-Timestamp: <unix_epoch>

Verification:
  1. Reject if timestamp > 300s old (replay protection)
  2. HMAC-SHA256(shared_secret, timestamp + "." + raw_body)
  3. Constant-time comparison
  4. Reject on mismatch -> 401

Inbound uses X-SimTraffic-Signature / X-SimTraffic-Timestamp (same algorithm).
```

**Idempotency**: `event_id` (UUID) deduplicated against `swim_webhook_events` within a 24h window.

### WebSocket (Low-Latency Streaming)

```
SimTraffic WS Client  <------>  PERTI swim_ws_server.php (port 8090)
                                  |-- Auth: ?api_key=swim_sys_simtraffic_...
                                  |-- Subscribe channels: tmi.*
                                  |-- Publish channels: simtraffic.lifecycle.*
                                  '-- Heartbeat: 30s ping/pong
```

**Auth**: The WS server already supports API key authentication via query param `api_key` (validated against `swim_api_keys` table, checks tier). SimTraffic uses its existing system-tier key. No code changes needed for WS auth.

**Channel names** (uses existing SubscriptionManager wildcard matching):
- `simtraffic.lifecycle.*` — SimTraffic publishes lifecycle events
- `simtraffic.lifecycle.departed`, `simtraffic.lifecycle.metering`, etc. — specific event types
- `tmi.*` — SimTraffic subscribes to all TMI events
- `tmi.edct.*`, `tmi.ground_stop.*` — specific TMI event families

**Internal IPC**: Outbound TMI events are pushed to the WS server via the existing file-based IPC mechanism (`POST /api/swim/v1/ws/publish` writes to `/tmp/swim_ws_events.json`, WS server reads and broadcasts). No direct `publishEvent()` calls from external code.

### Channel Relationship

- REST is the **source of truth** — every event goes through REST webhooks
- WebSocket is **supplementary** — same events, lower latency, not guaranteed
- If WS is connected, events arrive instantly via WS and are confirmed/deduped when the REST webhook fires
- If WS disconnects, no data loss — REST webhooks continue independently
- SimTraffic can use either or both
- Both channels use the same `event_id` for deduplication

### Dual-Channel Dedup Logic

**Inbound event arrives via REST webhook**:
1. `WebhookReceiver::verify()` — HMAC + timestamp check
2. `WebhookReceiver::dedup()` — check `event_id` in `swim_webhook_events`
3. If duplicate (already arrived via WS) — skip DB write, return `duplicate`
4. If new — call `processSimTrafficFlight()`, log event, return `accepted`

**Inbound event arrives via WS**:
1. WS handler calls `processSimTrafficFlight()` (same function as ingest endpoint)
2. Logs to `swim_webhook_events` with `source_channel = 'ws'` for dedup tracking
3. Later REST webhook arrival caught by dedup

**Outbound TMI event**:
1. `webhook_delivery_daemon` sends via REST (guaranteed)
2. Simultaneously publishes to WS via IPC (`/api/swim/v1/ws/publish` endpoint)
3. SimTraffic deduplicates on their end using `event_id`

## Database Changes

### New Table: `swim_webhook_subscriptions` (SWIM_API)

```sql
CREATE TABLE dbo.swim_webhook_subscriptions (
    id                   INT IDENTITY(1,1) PRIMARY KEY,
    source_id            VARCHAR(32)   NOT NULL,  -- 'simtraffic'
    direction            VARCHAR(8)    NOT NULL,  -- 'inbound' | 'outbound'
    callback_url         VARCHAR(512)  NOT NULL,  -- target URL
    shared_secret        VARCHAR(128)  NOT NULL,  -- HMAC signing key
    event_types          VARCHAR(MAX)  NULL,       -- JSON array or '*' for all
    is_active            BIT           NOT NULL DEFAULT 1,
    created_utc          DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_utc          DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
    last_success_utc     DATETIME2     NULL,
    last_failure_utc     DATETIME2     NULL,
    consecutive_failures INT           NOT NULL DEFAULT 0
);

CREATE INDEX IX_webhook_subs_source ON dbo.swim_webhook_subscriptions (source_id, direction, is_active);
```

Managed manually (INSERT rows for SimTraffic). API-based registration (`register.php`) is available but not required for initial setup.

### New Table: `swim_webhook_events` (SWIM_API)

```sql
CREATE TABLE dbo.swim_webhook_events (
    event_id         VARCHAR(64)   NOT NULL PRIMARY KEY,  -- UUID, idempotency key
    event_type       VARCHAR(64)   NOT NULL,
    direction        VARCHAR(8)    NOT NULL,  -- 'inbound' | 'outbound'
    source_id        VARCHAR(32)   NOT NULL,  -- 'simtraffic'
    source_channel   VARCHAR(8)    NOT NULL DEFAULT 'rest',  -- 'rest' | 'ws'
    payload          NVARCHAR(MAX) NULL,      -- JSON event body
    status           VARCHAR(16)   NOT NULL DEFAULT 'pending',
    attempts         INT           NOT NULL DEFAULT 0,
    next_retry_utc   DATETIME2     NULL,
    created_utc      DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
    delivered_utc    DATETIME2     NULL,
    flight_uid       BIGINT        NULL,
    callsign         VARCHAR(16)   NULL
);

-- Outbound delivery queue (two indexes — SQL Server filtered indexes cannot use OR/IN)
CREATE INDEX IX_webhook_events_pending
    ON dbo.swim_webhook_events (next_retry_utc)
    INCLUDE (event_id, event_type, source_id, payload, attempts)
    WHERE status = 'pending';

CREATE INDEX IX_webhook_events_sent
    ON dbo.swim_webhook_events (next_retry_utc)
    INCLUDE (event_id, event_type, source_id, payload, attempts)
    WHERE status = 'sent';

-- Purge by age
CREATE INDEX IX_webhook_events_created
    ON dbo.swim_webhook_events (created_utc);

-- Inbound dedup lookup (24h window)
CREATE INDEX IX_webhook_events_dedup
    ON dbo.swim_webhook_events (event_id, created_utc)
    WHERE direction = 'inbound';
```

**No changes to `swim_flights`** — the existing SimTraffic columns from migration 017 already cover all lifecycle fields. The webhook receiver writes to the same columns the polling daemon does today.

## Component Architecture

### New Files

```
api/swim/v1/webhooks/
  simtraffic.php              -- Inbound webhook receiver (POST)
  register.php                -- Webhook subscription management (GET/POST/DELETE)

lib/webhooks/
  WebhookReceiver.php         -- HMAC verification, idempotency, event routing
  WebhookSender.php           -- Outbound dispatch, retry logic, signing
  WebhookEventBuilder.php     -- Builds event payloads from TMI/CDM actions

scripts/
  webhook_delivery_daemon.php -- Processes outbound event queue (10s cycle)
```

### Modified Files

```
lib/connectors/sources/SimTrafficConnector.php
  -- Add webhook endpoints to getEndpoints()
  -- Update description/metadata to reflect webhook model

load/services/EDCTDelivery.php
  -- Add Channel 5: SimTraffic webhook
  -- Calls WebhookEventBuilder to create tmi.edct_assigned/revised/cancelled events
  -- INSERT into swim_webhook_events (status=pending, direction=outbound)

scripts/swim_ws_server.php
  -- Add inbound message handler for simtraffic.lifecycle.* channel publishes
  -- Route WS-received lifecycle events through processSimTrafficFlight()
  -- Log to swim_webhook_events for dedup

api/swim/v1/ingest/simtraffic.php
  -- Add deprecation response header: X-Deprecated: Use /api/swim/v1/webhooks/simtraffic
  -- Keep fully functional as fallback

load/swim_config.php
  -- Add webhook constants:
     WEBHOOK_RETRY_INTERVALS = [10, 30, 90]
     WEBHOOK_BATCH_SIZE = 50
     WEBHOOK_BATCH_WINDOW_SEC = 5
     WEBHOOK_SIGNING_ALGO = 'sha256'
     WEBHOOK_DEDUP_WINDOW_HOURS = 24
     WEBHOOK_EVENT_RETENTION_DAYS = 30

scripts/archival_daemon.php
  -- Add swim_webhook_events purge clause (30-day retention)
```

### Data Flow: Inbound Lifecycle Event (REST)

```
SimTraffic POST /api/swim/v1/webhooks/simtraffic.php
  -> WebhookReceiver::verify()
       HMAC-SHA256 signature check (X-SimTraffic-Signature header)
       Timestamp freshness check (< 300s)
  -> WebhookReceiver::dedup()
       SELECT event_id FROM swim_webhook_events WHERE event_id = ? AND created_utc > DATEADD(hour, -24, ...)
       If exists -> skip, count as duplicate
  -> Log to swim_webhook_events (direction=inbound, source_channel=rest, status=delivered)
  -> For each event in batch:
       processSimTrafficFlight($conn_swim, $record, 'simtraffic')
       (same function used by existing ingest/simtraffic.php endpoint)
       Updates swim_flights FIXM columns
  -> Return 200 {"accepted": N, "duplicates": N, "errors": N}
  -> Reverse sync daemon propagates swim_flights changes to ADL (unchanged, 120s cycle)
```

### Data Flow: Inbound Lifecycle Event (WebSocket)

```
SimTraffic WS client publishes to simtraffic.lifecycle.{event_type}
  -> swim_ws_server onMessage handler detects simtraffic.lifecycle.* prefix
  -> Calls processSimTrafficFlight() with event data
  -> INSERT into swim_webhook_events (direction=inbound, source_channel=ws, status=delivered)
  -> Return ACK frame to SimTraffic
```

### Data Flow: Outbound TMI Event

```
GDP SP assigns EDCT -> API calls EDCTDelivery::deliverEDCT()
  -> Channel 5: WebhookEventBuilder::edctAssigned(flight_uid, callsign, edct_utc, ...)
       Builds event envelope with UUID event_id
  -> INSERT into swim_webhook_events (status=pending, direction=outbound)
  -> Also pushes to WS via IPC:
       POST /api/swim/v1/ws/publish (internal, X-Internal-Key auth)
       -> /tmp/swim_ws_events.json -> WS server broadcasts to tmi.edct.* subscribers

webhook_delivery_daemon.php (10s cycle):
  -> SELECT pending/retry-ready events from swim_webhook_events WHERE direction = 'outbound'
  -> Batch up to 50 events
  -> WebhookSender::dispatch():
       Look up callback_url + shared_secret from swim_webhook_subscriptions
       Circuit breaker check (reuse existing CircuitBreaker.php: 60s window, 6 errors, 180s cooldown)
       Sign payload: HMAC-SHA256(secret, timestamp + "." + body)
       POST to callback_url with X-VATSWIM-Signature + X-VATSWIM-Timestamp headers
  -> On 2xx: UPDATE status = 'delivered', delivered_utc = NOW
  -> On 4xx/5xx: attempts++, next_retry_utc = NOW + backoff[attempts]
  -> After 3 failures: status = 'dead', log alert
```

### Polling Daemon Demotion

`simtraffic_swim_poll.php` is NOT removed. It becomes a **reconciliation fallback**:

- Interval changed from 120s to 600s (10 minutes)
- Only picks up flights where `simtraffic_sync_utc` is NULL or older than 5 minutes AND the flight is active
- Catches any events SimTraffic's webhook sender missed
- Same API calls, same `ingest_simtraffic_to_swim()` function, just less frequent

## Rate Limiting

| Direction | Channel | Limit |
|---|---|---|
| Inbound webhook | REST | 1,000 req/min (dedicated webhook limit) |
| Inbound WS | WebSocket | 100 messages/sec per connection |
| Outbound webhook | REST | Self-limited by daemon cycle (10s) + batch size (50) |
| Outbound WS | WebSocket | Via IPC publish endpoint (internal, no external limit) |

## Circuit Breaker (Outbound)

Reuses existing `lib/connectors/CircuitBreaker.php`:
- Rolling error window: 60 seconds
- Trip threshold: 6 errors within window
- Cooldown: 180 seconds (3 minutes)
- State file: `/tmp/perti_simtraffic_webhook_state.json`

When circuit is open, events remain `pending` in `swim_webhook_events`. When closed, daemon drains the backlog.

## Hibernation Behavior

| Component | Hibernation Behavior |
|---|---|
| Inbound REST webhook | **Accepted** — events written to swim_webhook_events + swim_flights. Reverse sync to ADL is paused. |
| Inbound WS | **Accepted** — connection stays up, events processed same as REST. |
| Outbound REST webhook | **Paused** — events queue as `pending`. Delivery daemon skips cycle when HIBERNATION_MODE=1. Backlog drains on wake. |
| Outbound WS | **Suppressed** — no TMI activity during hibernation. IPC publish calls are skipped. |
| Reconciliation polling | **Paused** — same as other conditional daemons. |

## Security

- **HMAC-SHA256 signature** on all webhook payloads (both directions)
- **Timestamp replay protection** (300s window)
- **API key auth** for WS connections (existing swim_api_keys system-tier validation)
- **Shared secrets** stored in `swim_webhook_subscriptions` table (not in code)
- **Auth fallback** on inbound webhook: if HMAC headers are absent, fall back to `swim_init_auth()` API key check (backward compatibility during SimTraffic transition)

## Monitoring

- Connector health endpoint (`/api/swim/v1/connectors/health.php`) updated to report:
  - Webhook pending queue depth
  - Last successful inbound/outbound delivery timestamps
  - Consecutive failure count
  - Circuit breaker state (closed/open)
- `swim_webhook_subscriptions.consecutive_failures` tracks per-subscription health

## Purge

- `swim_webhook_events`: 30-day retention, purged by `scripts/archival_daemon.php`
- `swim_webhook_subscriptions`: No auto-purge (manual management)

## Migration

Single new migration: `database/migrations/swim/034_swim_webhook_tables.sql`

Creates both tables + indexes + initial SimTraffic subscription rows (inbound + outbound).

## Summary

| Layer | Inbound (SimTraffic -> PERTI) | Outbound (PERTI -> SimTraffic) |
|---|---|---|
| REST Webhook | `POST /api/swim/v1/webhooks/simtraffic.php` | `webhook_delivery_daemon.php` -> SimTraffic callback URL |
| WebSocket | SimTraffic publishes to `simtraffic.lifecycle.*` channels | PERTI pushes to `tmi.*` channels via IPC |
| Dedup | `event_id` in `swim_webhook_events` (24h window) | SimTraffic deduplicates by `event_id` |
| Fallback | Polling daemon demoted to 10-min reconciliation | Dead letter after 3 retries |

**New files**: 6 (2 API endpoints, 3 lib classes, 1 daemon)
**Modified files**: 6 (connector, EDCTDelivery, WS server, ingest endpoint, swim_config, archival daemon)
**New tables**: 2 (`swim_webhook_subscriptions`, `swim_webhook_events`)
**New migration**: 1 (`025_swim_webhook_tables.sql`)
