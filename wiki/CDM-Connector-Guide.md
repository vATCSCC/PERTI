# CDM Connector Guide

> **Collaborative Decision Making** -- connecting A-CDM milestone data sources to VATSWIM.

VATSWIM serves as the canonical aggregation point for CDM data across both North American (PERTI) and European (vIFF, vACDM) airspace. External CDM systems push or poll milestone data into SWIM, where it is normalized into FIXM-aligned columns on the `swim_flights` table and exposed to consumers via the REST API and WebSocket.

This guide covers:
- How CDM data flows into VATSWIM
- How to connect a new CDM source (push or poll)
- The A-CDM milestone model
- API endpoint reference for CDM ingest
- How the vIFF and vACDM integrations work as reference implementations

---

## A-CDM Milestone Model

VATSWIM implements an A-CDM (Airport Collaborative Decision Making) milestone model aligned with both FAA CDM and EUROCONTROL A-CDM concepts.

### Milestone Timeline

```
EOBT ──> TOBT ──> TSAT ──> TTOT/CTOT ──> AOBT ──> ATOT
  │        │        │          │             │        │
  │        │        │          │             │        └─ Actual Takeoff
  │        │        │          │             └─ Actual Off-Block (pushback)
  │        │        │          └─ Target/Controlled Takeoff Time
  │        │        └─ Target Startup Approval Time
  │        └─ Target Off-Block Time (pilot intent)
  └─ Estimated Off-Block Time (from flight plan)
```

### SWIM Column Mapping

| Milestone | SWIM Column | Type | Source Migration |
|-----------|-------------|------|-----------------|
| EOBT | `estimated_time_of_departure` | datetime2 | 014 |
| TOBT | `target_off_block_time` | datetime2 | 014 |
| TSAT | `target_startup_approval_time` | datetime2 | 014 |
| TTOT | `target_takeoff_time` | datetime2 | 014 |
| TLDT | `target_landing_time` | datetime2 | 014 |
| CTOT/CTD | `controlled_time_of_departure` | datetime2 | 014/019 |
| ASAT | `actual_startup_approval_time` | datetime2 | 023 |
| EXOT | `expected_taxi_out_time` | int (minutes) | 023 |
| AOBT | `actual_off_block_time` | datetime2 | 019 |
| ATOT | `actual_time_of_departure` | datetime2 | 019 |

Additional CDM tracking columns:

| Column | Type | Purpose |
|--------|------|---------|
| `cdm_source` | nvarchar(32) | Source identifier (e.g., `VACDM`, `VIFF_CDM`) |
| `cdm_updated_at` | datetime2 | Last CDM data update timestamp |
| `eu_atfcm_status` | nvarchar(16) | EU ATFCM status (REA/FLS/SIR/EXCLUDED) |
| `flow_measure_ident` | nvarchar(32) | Applicable flow regulation name |

---

## Data Flow Architecture

```
                    ┌──────────────┐
                    │  CDM Sources  │
                    └──────┬───────┘
                           │
          ┌────────────────┼────────────────┐
          │                │                │
    ┌─────▼─────┐   ┌─────▼─────┐   ┌─────▼──────┐
    │   vACDM   │   │   vIFF    │   │ CDM Plugin │
    │  (push)   │   │  (poll)   │   │   (push)   │
    └─────┬─────┘   └─────┬─────┘   └─────┬──────┘
          │                │                │
          │  POST /ingest  │  Direct DB     │  POST /ingest
          │  /cdm          │  write         │  /cdm
          │                │                │
    ┌─────▼────────────────▼────────────────▼──────┐
    │          SWIM_API.swim_flights                │
    │  (A-CDM milestone columns, FIXM-aligned)     │
    └──────────────────┬───────────────────────────┘
                       │
          ┌────────────┼────────────────┐
          │            │                │
    ┌─────▼─────┐ ┌───▼────┐  ┌───────▼────────┐
    │ REST API  │ │   WS   │  │ SWIM Sync      │
    │ GET /cdm  │ │ :8090  │  │ (back to ADL)  │
    └───────────┘ └────────┘  └────────────────┘
```

### Connection Methods

| Method | Pattern | When to Use |
|--------|---------|------------|
| **Push (HTTP)** | POST to `/api/swim/v1/ingest/cdm` | Source can send data on change (webhooks, events) |
| **Poll (daemon)** | Daemon fetches source REST API, writes directly to DB | Source only exposes a read API (no push capability) |

---

## Option 1: Push via CDM Ingest API

This is the recommended method for CDM sources that can initiate outbound HTTP requests.

### Endpoint

```
POST https://perti.vatcscc.org/api/swim/v1/ingest/cdm
```

### Authentication

Requires a **partner** or **system** tier API key with `cdm` field authorization:

```
Authorization: Bearer swim_par_YOUR_CDM_KEY
Content-Type: application/json
```

### Request Payload

```json
{
  "updates": [
    {
      "callsign": "BAW123",
      "gufi": "VAT-20260305-BAW123-EGLL-KJFK",
      "airport": "EGLL",
      "tobt": "2026-03-05T14:30:00Z",
      "tsat": "2026-03-05T14:35:00Z",
      "ttot": "2026-03-05T14:40:00Z",
      "asat": "2026-03-05T14:36:00Z",
      "exot": 5,
      "readiness_state": "READY",
      "source": "VACDM"
    }
  ]
}
```

### Field Reference

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `callsign` | string | Yes* | Aircraft callsign (e.g., `BAW123`) |
| `gufi` | string | Yes* | SWIM GUFI (e.g., `VAT-20260305-BAW123-EGLL-KJFK`) |
| `airport` | string | No | Departure airport ICAO (improves flight matching) |
| `tobt` | ISO 8601 | No | Target Off-Block Time |
| `tsat` | ISO 8601 | No | Target Startup Approval Time |
| `ttot` | ISO 8601 | No | Target Takeoff Time |
| `tldt` | ISO 8601 | No | Target Landing Time (arrival-side) |
| `asat` | ISO 8601 | No | Actual Startup Approval Time |
| `exot` | integer | No | Expected taxi-out time in minutes (0-120) |
| `readiness_state` | string | No | Pilot readiness: `PLANNING`, `BOARDING`, `READY`, `TAXIING`, `CANCELLED` |
| `source` | string | No | Override source identifier (defaults to API key source) |

*At least one of `callsign` or `gufi` is required. Providing both improves match accuracy.

### Flight Matching

The ingest endpoint matches incoming records to existing `swim_flights` rows using a cascade:

1. **GUFI** (strongest) -- exact match on the `gufi` column
2. **Callsign + Airport** -- callsign + departure airport, most recent active flight
3. **Callsign only** (fallback) -- callsign, most recent active flight

Flights that are not yet in `swim_flights` (ADL hasn't synced them yet) will return `not_found`. Retry on the next cycle; the flight will appear after the next ADL sync (every 2 minutes).

### GUFI Format

The SWIM GUFI (Globally Unique Flight Identifier) follows this format:

```
VAT-{YYYYMMDD}-{CALLSIGN}-{DEPT_ICAO}-{DEST_ICAO}
```

Example: `VAT-20260315-BAW123-EGLL-KJFK`

Use the departure date (UTC) from the EOBT. If you have departure and destination airports, always include the GUFI for strongest matching.

### Response

```json
{
  "success": true,
  "data": {
    "processed": 10,
    "updated": 8,
    "not_found": 2,
    "readiness_updated": 5,
    "errors": 0,
    "error_details": []
  },
  "meta": {
    "source": "vacdm",
    "batch_size": 10
  }
}
```

### Batch Limits

Maximum **500 records** per request. For larger datasets, split into multiple requests.

### Error Codes

| Code | Meaning |
|------|---------|
| 400 `MISSING_BODY` | No request body |
| 400 `MISSING_UPDATES` | Missing `updates` array |
| 400 `BATCH_TOO_LARGE` | More than 500 records |
| 403 `NOT_AUTHORITATIVE` | API key lacks `cdm` field authorization |

---

## Option 2: Polling Daemon (Direct DB Write)

For CDM sources that expose a read-only REST API (no push/webhook capability), VATSWIM runs a polling daemon that fetches data and writes directly to `swim_flights`.

### Architecture

```
External CDM API                    VATSWIM
┌──────────────┐    HTTP GET    ┌──────────────────────┐
│  CDM Source   │ ◄──────────── │  poll daemon (PHP)   │
│  REST API     │ ──────────► │  ↓ parse + transform │
└──────────────┘    JSON       │  ↓ flight matching   │
                                │  ↓ sqlsrv UPDATE     │
                                │  swim_flights        │
                                └──────────────────────┘
```

### Daemon Pattern

All VATSWIM polling daemons follow the `ecfmp_poll_daemon.php` pattern:

| Component | Description |
|-----------|-------------|
| CLI args | `--loop`, `--interval=N`, `--debug` |
| Circuit breaker | File-based, 6 errors in 60s triggers 180s cooldown |
| Cache | MD5-hash change detection to skip unchanged records |
| PID file | Singleton enforcement via `sys_get_temp_dir()` |
| Heartbeat | JSON status file for monitoring |
| Logging | `[timestamp UTC] [LEVEL] message` to stdout |
| Startup | Registered in `scripts/startup.sh` with feature flag |

### Creating a New Polling Daemon

1. Create `scripts/{source}_poll_daemon.php` following the ECFMP pattern
2. Create `lib/connectors/sources/{Source}Connector.php` descriptor
3. Register in `lib/connectors/ConnectorRegistry.php`
4. Add source to `load/swim_config.php` (data sources, priority, field merge, authority)
5. Add feature flag constants to `load/config.php`
6. Add daemon start block to `scripts/startup.sh`

### Flight Matching (3-Tier GUFI Cascade)

For polling daemons that have callsign, departure, destination, and EOBT available, use the 3-tier cascade:

```
Tier 1 (GUFI -- strongest):
  Construct GUFI: VAT-{date from EOBT}-{CALLSIGN}-{DEPT}-{DEST}
  Use swim_generate_gufi() from load/swim_config.php
  WHERE gufi = ? AND is_active = 1

Tier 2 (Callsign + Departure + Destination):
  SELECT TOP 1 ... WHERE callsign = ? AND fp_dept_icao = ?
    AND fp_dest_icao = ? AND is_active = 1
    ORDER BY last_sync_utc DESC

Tier 3 (Callsign + Departure):
  SELECT TOP 1 ... WHERE callsign = ? AND fp_dept_icao = ?
    AND is_active = 1
    ORDER BY last_sync_utc DESC
```

Tier 1 prevents cross-day callsign collisions. Falls through tiers on miss. If all miss: log and skip (flight will appear next ADL sync cycle).

---

## Data Authority & Source Priority

VATSWIM uses a multi-source merge system with field-level authority rules. CDM fields are governed by the `cdm` authority group.

### Source Priority (CDM fields)

| Source | Priority | Description |
|--------|----------|-------------|
| `vacdm` | 1 | vACDM instances (primary, A-CDM airports) |
| `viff_cdm` | 1 | vIFF ATFCM System (primary, EU ATFCM airports) |
| `cdm_plugin` | 2 | CDM Plugin (departure sequencing) |
| `vatcscc` | 3 | PERTI manual/automated CDM |

Equal-priority sources (vACDM and vIFF) serve non-overlapping airports. If they ever overlap, the most recent update wins (`variable` merge behavior).

### Merge Behavior

| Merge Type | CDM Fields | Rule |
|------------|-----------|------|
| `variable` | TOBT, TSAT, TTOT, EXOT, CTOT, eu_atfcm_status | Accept newer timestamp |
| `once` | ASAT | Accept first write only (pushback approval is a one-time event) |

### Registering a New CDM Source

Add your source to these locations in `load/swim_config.php`:

```php
// 1. Data source mapping
$SWIM_DATA_SOURCES['YOUR_CDM'] = 'your_cdm';

// 2. CDM priority (lower number = higher priority)
$SWIM_SOURCE_PRIORITY['cdm']['your_cdm'] = 2;

// 3. Field merge behavior (if adding new columns)
$SWIM_FIELD_MERGE_BEHAVIOR['your_new_field'] = 'variable';

// 4. Field authority mapping (if adding new columns)
$SWIM_FIELD_AUTHORITY_MAP['your_new_field'] = 'cdm';
```

---

## Reference Implementations

### vACDM (Push-Based)

vACDM instances push A-CDM milestones to the CDM ingest endpoint. This is the simplest integration pattern.

| Aspect | Detail |
|--------|--------|
| **Connector** | `lib/connectors/sources/VACDMConnector.php` |
| **Type** | Bidirectional (push ingest + optional poll) |
| **Endpoint** | `POST /api/swim/v1/ingest/cdm` |
| **Auth** | Partner-tier API key with `cdm` authority |
| **Data fields** | TOBT, TSAT, TTOT, ASAT, EXOT, readiness_state |
| **Batch limit** | 500 records per request |
| **Poll daemon** | `scripts/vacdm_poll_daemon.php` (optional, discovers from `tmi_flow_providers`) |

### vIFF ATFCM System (Poll-Based)

The vIFF System (`viff-system.network`) is the European ATFCM backend for VATSIM, powering the EuroScope CDM plugin used by 32+ vACCs. It manages A-CDM milestones, server-side capacity regulations (CAD), and ECFMP flow measures for EU airspace.

Since vIFF has no push/webhook mechanism, VATSWIM polls its REST API.

| Aspect | Detail |
|--------|--------|
| **Connector** | `lib/connectors/sources/VIFFConnector.php` |
| **Type** | Poll |
| **Daemon** | `scripts/viff_cdm_poll_daemon.php` |
| **Poll interval** | 30 seconds |
| **External API** | `https://viff-system.network` |
| **Auth** | `x-api-key` header |
| **Feature flag** | `VIFF_CDM_ENABLED` (Azure App Setting) |
| **Provider code** | `VIFF` in `tmi_flow_providers` |

#### vIFF Endpoints Polled

| Endpoint | Data | Notes |
|----------|------|-------|
| `GET /etfms/relevant` | All CDM flights: callsign, departure, arrival, EOBT, TOBT, taxi, CTOT, AOBT, ATOT, ATFCM status | Primary data source |
| `GET /etfms/restricted` | CTOT restrictions: callsign, CTOT (HHMM), regulation name | Merged into flight data |
| `GET /ifps/allStatus` | ATFCM statuses: callsign, status code | Supplementary status data |

#### vIFF Time Format

vIFF uses **HHMM** (4-digit) time format in API responses (e.g., `"1836"` = 18:36 UTC). The daemon auto-detects format length and converts to ISO 8601 with midnight rollover handling.

#### TSAT/TTOT Derivation

The vIFF API provides `tobt`, `taxi`, and `ctot` but not `tsat`/`ttot` directly. The daemon derives them:

| Scenario | TTOT | TSAT |
|----------|------|------|
| Regulated (CTOT present) | CTOT | CTOT - taxi minutes |
| Unregulated (TOBT + taxi) | TOBT + taxi | TOBT |
| Insufficient data | Not written | Not written |

#### EU ATFCM Status Values

| Status | Meaning |
|--------|---------|
| `REA` | Ready for departure (Slot Improvement eligible) |
| `FLS-CDM` | Flight suspended -- CDM issue |
| `FLS-GS` | Flight suspended -- Ground Stop |
| `FLS-MR` | Flight suspended -- Mandatory Route violation |
| `COMPLY` | Airborne within CTOT window |
| `AIRB` | Airborne |
| `SIR` | Slot Improvement Request active |
| `EXCLUDED` | Excluded from ATFCM regulations |

---

## Connector Health Monitoring

All CDM connectors are registered in the `ConnectorRegistry` and exposed via the health API:

```bash
# Lightweight health check (any API key)
curl -H "Authorization: Bearer YOUR_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/connectors/health"

# Detailed status (system/partner key required)
curl -H "Authorization: Bearer swim_sys_YOUR_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/connectors/status"
```

Health states:

| Status | Meaning |
|--------|---------|
| `OK` | Connector operational, circuit breaker closed |
| `DEGRADED` | Some providers have open circuit breakers |
| `DISABLED` | Feature flag off or hibernation (except SWIM-exempt connectors) |

---

## Reading CDM Data

Once CDM data is ingested, consumers access it through standard SWIM endpoints.

### REST API

```bash
# Get flights with CDM data at a specific airport
curl -H "Authorization: Bearer YOUR_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/flights?dept_icao=EGLL&status=active"
```

CDM fields appear in the response under FIXM-aligned names:

```json
{
  "callsign": "BAW123",
  "target_off_block_time": "2026-03-15T14:30:00+00:00",
  "target_startup_approval_time": "2026-03-15T14:35:00+00:00",
  "target_takeoff_time": "2026-03-15T14:40:00+00:00",
  "controlled_time_of_departure": "2026-03-15T14:45:00+00:00",
  "expected_taxi_out_time": 5,
  "eu_atfcm_status": "REA",
  "cdm_source": "VIFF_CDM",
  "cdm_updated_at": "2026-03-15T14:32:00+00:00"
}
```

### CDM-Specific Endpoints

| Endpoint | Description |
|----------|-------------|
| `GET /cdm/status` | Full CDM status for a flight (milestones, readiness, compliance) |
| `GET /cdm/readiness` | Pilot readiness state |
| `POST /cdm/readiness` | Update readiness state |
| `GET /cdm/compliance` | TMI compliance for controlled flights |
| `GET /cdm/metrics` | CDM effectiveness metrics |
| `GET /cdm/airport-status` | A-CDM airport operational picture |

See [[SWIM API]] for full endpoint documentation.

### WebSocket

CDM milestone changes are broadcast on the WebSocket (`wss://perti.vatcscc.org/api/swim/v1/ws/`). Subscribe to `flight.positions` events and filter for flights with CDM data.

---

## Glossary

| Term | Definition |
|------|-----------|
| **A-CDM** | Airport Collaborative Decision Making (EUROCONTROL concept) |
| **ATFCM** | Air Traffic Flow and Capacity Management (EU equivalent of TFMS) |
| **CAD** | Capacity Availability Document (community-contributed airspace capacity data) |
| **CDM** | Collaborative Decision Making |
| **CTOT** | Calculated Time Over Target / Controlled Time of Departure (EU regulation-assigned) |
| **EDCT** | Expect Departure Clearance Time (FAA GDP-assigned) |
| **EOBT** | Estimated Off-Block Time (from flight plan) |
| **EXOT** | Expected Taxi-Out Time (minutes) |
| **FIXM** | Flight Information Exchange Model (FAA/EUROCONTROL standard) |
| **GUFI** | Globally Unique Flight Identifier |
| **TOBT** | Target Off-Block Time (pilot's intended pushback time) |
| **TSAT** | Target Startup Approval Time (ATC-approved startup time) |
| **TTOT** | Target Takeoff Time |
| **vACDM** | Virtual Airport CDM (VATSIM A-CDM implementation) |
| **vIFF** | Virtual IFR Flight Following ATFCM system by Roger Puig |

---

## Related Resources

- **[[SWIM API]]** -- Full SWIM endpoint documentation (includes CDM section)
- **[SWIM Documentation Portal](https://perti.vatcscc.org/swim-docs.php)** -- Connector guides, integration docs
- **[OpenAPI Spec](../blob/main/docs/swim/openapi.yaml)** -- Machine-readable API specification
- **[vIFF System](https://viff-system.network)** -- EU ATFCM backend for VATSIM
- **[vIFF CDM Wiki](https://github.com/rpuig2001/CDM/wiki)** -- vIFF documentation and ATFCM processes
- **Contact**: dev@vatcscc.org
