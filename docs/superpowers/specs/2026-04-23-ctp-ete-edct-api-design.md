# CTP ETE Query & Generic EDCT Ingest API

**Date**: 2026-04-23
**Status**: Design
**Scope**: Two new SWIM API endpoints for bidirectional CTP integration

---

## Overview

Two new endpoints enable CTP (Collaborative Traffic Planning) to:
1. **Query computed ETEs** for specific flights by callsign list
2. **Push EDCTs/CTDs** (with optional assigned routes) back into PERTI's canonical TMI pipeline

Both endpoints live under the existing SWIM API layer (`api/swim/v1/`) and use SWIM API key authentication.

---

## Endpoint 1: ETE Query

### `POST /api/swim/v1/ete.php`

Returns computed Estimated Time Enroute for requested flights.

**Auth**: SWIM API key (read-only). Pattern: `swim_init_auth(true, false)`.

**Why POST**: Callsign lists can exceed URL length limits for GET.

### Request

```json
{
  "callsigns": ["BAW123", "UAL456", "DAL789"]
}
```

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `callsigns` | string[] | Yes | 1-500 items, each 2-12 chars alphanumeric |

### Response

```json
{
  "success": true,
  "data": {
    "flights": [
      {
        "callsign": "BAW123",
        "flight_uid": 12345678,
        "gufi": "GUFI-abc-123",
        "departure_airport": "KJFK",
        "arrival_airport": "EGLL",
        "aircraft_type": "B77W",
        "estimated_elapsed_time": 412,
        "estimated_time_of_arrival": "2026-04-23T18:30:00Z",
        "estimated_off_block_time": "2026-04-23T11:38:00Z",
        "flight_phase": "ENROUTE",
        "latitude": 51.234,
        "longitude": -30.567,
        "altitude_ft": 37000,
        "ground_speed_kts": 485,
        "filed_route": "HAPIE DCT CYMON ...",
        "distance_to_destination_nm": 1842,
        "distance_flown_nm": 1490
      }
    ],
    "unmatched": ["FAKE99"]
  },
  "meta": {
    "total_requested": 3,
    "total_matched": 2,
    "total_unmatched": 1,
    "timestamp": "2026-04-23T14:00:00Z"
  }
}
```

### ETE Computation

Computed from SWIM flight data (not the pilot-filed `fp_enroute_minutes`):

```sql
SELECT
    callsign, flight_uid, gufi,
    fp_dept_icao, fp_dest_icao, aircraft_type,
    DATEDIFF(MINUTE, estimated_off_block_time, estimated_time_of_arrival)
        AS computed_ete_minutes,
    estimated_time_of_arrival, estimated_off_block_time,
    phase, lat, lon, altitude_ft, groundspeed_kts,
    fp_route, dist_to_dest_nm, dist_flown_nm
FROM dbo.swim_flights
WHERE callsign IN (?, ?, ...)
  AND is_active = 1
```

- Uses FIXM-aligned column names (migration 019) matching existing `flights.php` query pattern
- `estimated_off_block_time` is PERTI-computed from OOOI data, not pilot-filed
- `estimated_time_of_arrival` is computed by the ETA engine (multi-method)
- Returns `null` for `estimated_elapsed_time` if either time column is NULL
- Response field names follow FIXM conventions matching `formatFlightRecordFIXM()` output

### SQL Column-to-Response Mapping

| swim_flights column | Response field | Notes |
|---------------------|---------------|-------|
| `callsign` | `callsign` | Direct |
| `flight_uid` | `flight_uid` | Direct |
| `gufi` | `gufi` | Direct |
| `fp_dept_icao` | `departure_airport` | FIXM alias |
| `fp_dest_icao` | `arrival_airport` | FIXM alias |
| `aircraft_type` | `aircraft_type` | Direct |
| `DATEDIFF(MINUTE, estimated_off_block_time, estimated_time_of_arrival)` | `estimated_elapsed_time` | Computed, minutes |
| `estimated_time_of_arrival` | `estimated_time_of_arrival` | ISO 8601 |
| `estimated_off_block_time` | `estimated_off_block_time` | ISO 8601 |
| `phase` | `flight_phase` | FIXM alias |
| `lat` | `latitude` | FIXM alias |
| `lon` | `longitude` | FIXM alias |
| `altitude_ft` | `altitude_ft` | Direct |
| `groundspeed_kts` | `ground_speed_kts` | FIXM alias |
| `fp_route` | `filed_route` | FIXM alias |
| `dist_to_dest_nm` | `distance_to_destination_nm` | FIXM alias |
| `dist_flown_nm` | `distance_flown_nm` | FIXM alias |

### Error Responses

| Code | Condition |
|------|-----------|
| 401 | Missing/invalid API key |
| 400 | Missing `callsigns`, not an array, empty, exceeds 500 |
| 405 | Method not POST |
| 500 | Database error |

---

## Endpoint 2: EDCT Ingest

### `POST /api/swim/v1/ingest/edct.php`

Ingests EDCTs/CTDs from external systems into PERTI's canonical TMI pipeline.

**Auth**: SWIM API key with write permission + `ctp` authority group.
Pattern: `swim_init_auth(true, true)` then `$auth->canWriteField('ctp')`.

### Request

```json
{
  "edcts": [
    {
      "callsign": "BAW123",
      "edct_utc": "2026-04-23T12:45:00Z",
      "delay_minutes": 67,
      "delay_reason": "VOLUME",
      "cta_utc": "2026-04-23T19:52:00Z",
      "program_name": "CTP_EAST26_GDP",
      "source_system": "CTP",
      "assigned_route": "HAPIE DCT CYMON NAT-A LIMRI ...",
      "route_segments": {
        "na": "HAPIE DCT CYMON",
        "oceanic": "CYMON NAT-A LIMRI",
        "eu": "LIMRI DCT NUMPO ..."
      }
    }
  ]
}
```

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `edcts` | object[] | Yes | 1-500 items |
| `edcts[].callsign` | string | Yes | 2-12 chars |
| `edcts[].edct_utc` | string | Yes | ISO 8601 datetime |
| `edcts[].delay_minutes` | int | No | >= 0 |
| `edcts[].delay_reason` | string | No | e.g., VOLUME, WEATHER, EQUIPMENT, RUNWAY, OTHER |
| `edcts[].cta_utc` | string | No | ISO 8601 datetime |
| `edcts[].program_name` | string | No | Control program identifier |
| `edcts[].program_id` | int | No | FK to tmi_programs if applicable |
| `edcts[].source_system` | string | No | Originating system (default: API key source_id) |
| `edcts[].assigned_route` | string | No | TMU-assigned route (full route string) |
| `edcts[].route_segments` | object | No | Decomposed route segments |
| `edcts[].route_segments.na` | string | No | NA segment (dep to oceanic entry) |
| `edcts[].route_segments.oceanic` | string | No | Oceanic segment |
| `edcts[].route_segments.eu` | string | No | EU segment (oceanic exit to arr) |

### Response

```json
{
  "success": true,
  "data": {
    "results": [
      {
        "callsign": "BAW123",
        "status": "created",
        "flight_uid": 12345678,
        "control_id": 456,
        "edct_utc": "2026-04-23T12:45:00Z",
        "route_amendment_id": 789
      }
    ],
    "unmatched": ["FAKE99"]
  },
  "meta": {
    "total_submitted": 2,
    "created": 1,
    "updated": 0,
    "skipped": 0,
    "unmatched": 1,
    "timestamp": "2026-04-23T14:00:00Z"
  }
}
```

Result `status` values: `created`, `updated`, `skipped` (idempotent same-EDCT), `error`.

### Canonical Write Path

The EDCT ingest follows the same architectural pattern as `ingest/ctp.php`:

```
API Request
  │
  ├─1→ tmi_flight_control (VATSIM_TMI) — canonical source-of-truth
  │      INSERT or UPDATE with ctl_type='CTP'
  │      Columns: flight_uid, callsign, ctd_utc, octd_utc, orig_etd_utc,
  │               program_delay_min, ctl_type, ctl_elem, dep_airport, arr_airport,
  │               control_assigned_utc
  │
  ├─2→ swim_flights (SWIM_API) — immediate SWIM visibility
  │      UPDATE: controlled_time_of_departure, controlled_time_of_arrival,
  │              original_edct, edct_utc, delay_minutes, ctl_type
  │      (Server-side direct SQL, bypasses field authority checks)
  │
  ├─3→ rad_amendments (VATSIM_TMI) — if assigned_route provided
  │      INSERT: callsign, origin, destination, original_route, assigned_route,
  │              status='ISSUED', tmi_id_label, source
  │
  ├─4→ adl_flight_tmi.rad_assigned_route (VATSIM_ADL) — if assigned_route provided
  │      UPDATE: rad_amendment_id, rad_assigned_route
  │
  └─5→ ctp_flight_control (VATSIM_TMI) — if route_segments provided
         UPDATE: seg_na_route, seg_oceanic_route, seg_eu_route,
                 seg_*_status='VALIDATED', edct_utc, tmi_control_id
```

Steps 1-4 happen synchronously in the API request. Step 5 only if `route_segments` is provided and a `ctp_flight_control` record exists.

The TMI-to-ADL sync daemon (`executeDeferredTMISync()` in `vatsim_adl_daemon.php`, 60s cycle) handles propagation from `tmi_flight_control` to `adl_flight_tmi` for all other TMI columns (ctd_utc, program_delay_min, gs_held, etc.).

### Flight Matching

Follows the `ingest/ctp.php` pattern with three tiers:

1. **Callsign + airports** in `ctp_flight_control` (if CTP session context)
2. **Callsign only** in `swim_flights` (active flights)
3. **Unmatched** — returned in `unmatched` array, not an error

For generic (non-CTP) consumers, match against `swim_flights` by callsign where `is_active = 1`.

### Idempotency

- Same callsign + same `edct_utc` as existing `tmi_flight_control.ctd_utc` → `skipped`
- Same callsign + different `edct_utc` → `updated` (tmi_flight_control.ctd_utc updated, octd_utc preserved)
- New callsign (no existing control) → `created`

### Route Handling

When `assigned_route` is provided:

1. **Stored separately from filed route** — never touches `adl_flight_plan.fp_route` or `swim_flights.fp_route`
2. **Written to `rad_amendments`** — status='ISSUED', tracks the TMU/ATC-requested route
3. **Written to `adl_flight_tmi.rad_assigned_route`** — makes it visible to ADL consumers
4. **Compliance is manual/future** — no automated compliance daemon exists today. When the pilot accepts and re-files, the VATSIM datafeed route will update naturally through the normal ADL ingest pipeline.

When `route_segments` is provided (CTP-specific):
- Stored in `ctp_flight_control` segment columns (seg_na_route, seg_oceanic_route, seg_eu_route)
- Status set to 'VALIDATED'
- Existing `ctp_flight_control` record must exist (matched via callsign + session)

### Error Responses

| Code | Condition |
|------|-----------|
| 401 | Missing/invalid API key |
| 403 | API key lacks write permission or `ctp` authority |
| 400 | Missing `edcts`, not an array, empty, exceeds 500 |
| 400 | Individual record: missing callsign or edct_utc, invalid datetime |
| 405 | Method not POST |
| 500 | Database error |

---

## SWIM Authority Configuration

No new authority groups needed. Uses existing `ctp` authority:

```php
// load/swim_config.php (line 185)
'ctp' => ['CTP_API', true],   // CTP_API primary, override allowed
```

The EDCT ingest checks `canWriteField('ctp')` at the API level, then uses **server-side direct SQL** to write TMI/SWIM data. This is the same pattern as `ingest/ctp.php` — the CTP authority check gates access, but the actual TMI writes bypass field-level authority since the server code is the trusted actor.

**Why not use `tmi` authority**: `$SWIM_DATA_AUTHORITY['tmi'] = ['VATCSCC', false]` — immutable, VATCSCC-only. External systems cannot write TMI fields via the authority system. The server-side bridge pattern is the correct architectural approach.

---

## FIXM 4.3.0 Alignment

All response field names follow established FIXM conventions already used by `flights.php`:

| FIXM Concept | API Field Name | swim_flights Column |
|-------------|----------------|---------------------|
| Estimated Elapsed Time | `estimated_elapsed_time` | Computed: `DATEDIFF(MINUTE, estimated_off_block_time, estimated_time_of_arrival)` |
| Estimated Time of Arrival | `estimated_time_of_arrival` | `estimated_time_of_arrival` |
| Estimated Off-Block Time | `estimated_off_block_time` | `estimated_off_block_time` |
| Controlled Time of Departure | `controlled_time_of_departure` | `controlled_time_of_departure` |
| Controlled Time of Arrival | `controlled_time_of_arrival` | `controlled_time_of_arrival` |
| EDCT | `edct_utc` | `edct_utc` |
| Original EDCT | `original_edct` | `original_edct` |

---

## Database Dependencies

### Existing Tables Used (no schema changes)

| Table | Database | Purpose |
|-------|----------|---------|
| `swim_flights` | SWIM_API | ETE query source, immediate EDCT push target |
| `tmi_flight_control` | VATSIM_TMI | Canonical EDCT/CTD storage |
| `rad_amendments` | VATSIM_TMI | Route amendment storage |
| `adl_flight_tmi` | VATSIM_ADL | ADL-side TMI data (rad_assigned_route, rad_amendment_id) |
| `ctp_flight_control` | VATSIM_TMI | CTP-specific route segment storage |

### CHECK Constraint Note

`tmi_flight_control.ctl_type` has a CHECK constraint (migration 003) that originally did not include 'CTP'. The existing `ingest/ctp.php` endpoint uses `ctl_type = 'CTP'`, meaning the constraint has been modified on the live database. If not already done, the constraint must be updated to include 'CTP':

```sql
ALTER TABLE dbo.tmi_flight_control DROP CONSTRAINT CK_tmi_flight_control_type;
ALTER TABLE dbo.tmi_flight_control ADD CONSTRAINT CK_tmi_flight_control_type
    CHECK (ctl_type IS NULL OR ctl_type IN
        ('GDP', 'AFP', 'GS', 'DAS', 'GAAP', 'UDP', 'COMP', 'BLKT', 'ECR', 'ADPT', 'ABRG', 'CTOP', 'CTP'));
```

---

## File Structure

```
api/swim/v1/
├── ete.php                    # NEW - ETE query endpoint
├── ingest/
│   ├── edct.php               # NEW - Generic EDCT ingest
│   ├── ctp.php                # Existing CTP slot ingest (reference)
│   ├── adl.php                # Existing ADL ingest (reference)
│   └── ...
├── auth.php                   # Existing - SwimAuth, SwimResponse
├── flights.php                # Existing - flight query (reference)
└── ...
```

Both new files follow the established SWIM endpoint patterns:
- `require_once __DIR__ . '/auth.php'` (for `ete.php`) or `require_once __DIR__ . '/../auth.php'` (for `ingest/edct.php`) — auth.php bootstraps config, connect, and provides SwimAuth/SwimResponse
- Use `swim_get_json_body()` for POST body parsing
- Use `SwimResponse::success()` / `SwimResponse::error()` for responses
- Use `sqlsrv_query()` for Azure SQL (not PDO)
- Access databases via `global $conn_swim` (SWIM_API) and `get_conn_tmi()` / `get_conn_adl()` for cross-DB writes

---

## Non-Goals

- **No auto-delivery**: EDCTs are stored but not automatically pushed to pilots. Operator manually triggers delivery via existing EDCTDelivery channels.
- **No automated route compliance**: The `rad_amendments` status remains 'ISSUED' until manually resolved or a future compliance daemon is built. When the pilot re-files via VATSIM, the updated route flows naturally through the ADL ingest.
- **No new database tables**: All storage uses existing tables and columns.
- **No new authority groups**: Uses existing `ctp` authority from `swim_config.php`.
- **No WebSocket push**: May be added later; initial version is REST-only.

---

## Testing Strategy

1. **ETE endpoint**: POST with known active callsigns, verify computed ETE matches `DATEDIFF(MINUTE, estimated_off_block_time, estimated_time_of_arrival)`. Verify unmatched callsigns appear in `unmatched` array.

2. **EDCT ingest**: POST with test callsign, verify:
   - `tmi_flight_control` record created with correct `ctd_utc`, `ctl_type='CTP'`
   - `swim_flights` updated with `controlled_time_of_departure`, `edct_utc`
   - If `assigned_route` provided: `rad_amendments` record created, `adl_flight_tmi.rad_assigned_route` updated
   - If same EDCT re-submitted: response status is `skipped`
   - If different EDCT submitted: response status is `updated`, `octd_utc` preserved

3. **Auth**: Verify read-only keys can access ETE but not EDCT ingest. Verify keys without `ctp` authority get 403 on EDCT ingest.
