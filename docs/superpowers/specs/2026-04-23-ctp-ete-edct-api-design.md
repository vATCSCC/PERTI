# CTP ETE/CTOT Bidirectional API

**Date**: 2026-04-23
**Status**: Design
**Scope**: Two SWIM API endpoints for bidirectional CTP integration with immediate recalculation

---

## Overview

Two endpoints implement a bidirectional CTP flow:

1. **Step 1 — ETE Query**: CTP sends callsigns + TOBT (Target Off-Block Time). PERTI computes ETE and ETA using the canonical `sp_CalculateETA` engine (phase-based distance/speed with aircraft performance data), anchored to the provided TOBT. Returns computed times.

2. **Step 2 — CTOT Assignment**: CTP sends CTOT (Controlled Take-Off Time) + assigned route + assigned track. PERTI derives EOBT/EDCT from CTOT, stores in the TMI pipeline, and immediately recalculates all downstream fields (ETA, waypoint ETAs, boundary crossings).

```
CTP ──── callsigns + TOBT ────→ PERTI (compute ETE/ETA via sp_CalculateETA)
CTP ←── ETE + ETA + ETOT ────── PERTI

    ... CTP uses ETEs internally to assign slots ...

CTP ──── CTOT + route + track ──→ PERTI (store + immediate recalc cascade)
CTP ←── recalculated times ────── PERTI
```

Both endpoints use SWIM API key authentication under `api/swim/v1/`.

---

## Endpoint 1: ETE Query

### `POST /api/swim/v1/ete.php`

CTP provides TOBT per flight. PERTI computes ETE/ETA using the same ETA engine as all other PERTI consumers.

**Auth**: SWIM API key (read-only). Pattern: `swim_init_auth(true, false)`.

### Request

```json
{
  "flights": [
    { "callsign": "BAW123", "tobt": "2026-04-23T11:30:00Z" },
    { "callsign": "UAL456", "tobt": "2026-04-23T12:00:00Z" },
    { "callsign": "DAL789" }
  ]
}
```

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `flights` | object[] | Yes | 1-50 items |
| `flights[].callsign` | string | Yes | 2-12 chars alphanumeric |
| `flights[].tobt` | string | No | ISO 8601 datetime. If omitted, uses existing EOBT from flight record |

### ETE Computation

Uses the canonical `sp_CalculateETA` (V3.5) — the same phase-based distance/speed model used by all PERTI ETA calculations.

> **Prerequisite**: `sp_CalculateETA` currently only accepts `@flight_uid` and hardcodes `@now = SYSUTCDATETIME()`. It does NOT read `etd_utc` as a departure basis for prefiles. A new optional parameter `@departure_override DATETIME2(0) = NULL` must be added so we can anchor the computation to the CTP-provided TOBT instead of wall-clock time. When `@departure_override` is provided, all internal references to `@now` (departure anchor, TMI delay offset, ETA derivation) use the override instead.

```
1. Resolve departure time:
   TOBT = CTP-provided tobt   (or existing etd_utc if omitted)

2. Add taxi time:
   taxi_ref = airport_taxi_reference.unimpeded_taxi_sec (departure airport)
   ETOT = TOBT + taxi_ref      (Estimated Take-Off Time)

3. Compute flight time (sp_CalculateETA with @departure_override = ETOT):
   Aircraft performance = fn_GetAircraftPerformance(aircraft_type)
     → BADA > OpenAP > category defaults > 280/450/280 kts
   Route distance = route_total_nm > gcd_nm
   Wind = segment wind (climb/cruise/descent) from adl_flight_times

   climb_time  = toc_dist / (climb_speed + wind_climb) × 60
   cruise_time = cruise_dist / (cruise_speed + wind_cruise) × 60
   descent_time = tod_dist / (descent_speed + wind_descent) × 60
   ETE = climb_time + cruise_time + descent_time

4. Derive arrival:
   ETA = ETOT + ETE

5. Store computed values:
   swim_flights.target_off_block_time    = TOBT (if CTP-provided)
   swim_flights.estimated_takeoff_time   = ETOT (TOBT + taxi)
   swim_flights.computed_ete_minutes     = ETE  (computed enroute time)
   adl_flight_times.estimated_takeoff_time = ETOT
   adl_flight_times.computed_ete_minutes   = ETE
```

**Consistency guarantee**: ETE uses the exact same aircraft performance lookup, distance sources, wind adjustments, and phase modeling as `sp_CalculateETABatch`. The only difference is anchoring to CTP's TOBT (via `@departure_override`) instead of `@now`.

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
        "tobt": "2026-04-23T11:30:00Z",
        "etot": "2026-04-23T11:42:00Z",
        "estimated_elapsed_time": 412,
        "estimated_time_of_arrival": "2026-04-23T18:34:00Z",
        "taxi_time_minutes": 12,
        "eta_method": "V35_SEG_WIND",
        "eta_confidence": 0.70,
        "route_distance_nm": 3459,
        "aircraft_cruise_speed_kts": 490,
        "flight_phase": "PREFILED",
        "filed_route": "HAPIE DCT CYMON ...",
        "latitude": null,
        "longitude": null
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

| Response Field | Source | Notes |
|---------------|--------|-------|
| `callsign` | swim_flights.callsign | Direct |
| `flight_uid` | swim_flights.flight_uid | Direct |
| `gufi` | swim_flights.gufi | Direct |
| `departure_airport` | swim_flights.fp_dept_icao | FIXM alias |
| `arrival_airport` | swim_flights.fp_dest_icao | FIXM alias |
| `aircraft_type` | swim_flights.aircraft_type | Direct |
| `tobt` | CTP-provided or existing etd_utc | ISO 8601. Stored as `swim_flights.target_off_block_time` |
| `etot` | TOBT + taxi_ref | Computed, ISO 8601 |
| `estimated_elapsed_time` | sp_CalculateETA output | Minutes (climb+cruise+descent) |
| `estimated_time_of_arrival` | ETOT + ETE | ISO 8601 |
| `taxi_time_minutes` | airport_taxi_reference | Minutes |
| `eta_method` | sp_CalculateETA | V35_SEG_WIND / V35_ROUTE / V35 / etc. |
| `eta_confidence` | sp_CalculateETA | 0.65-0.97 |
| `route_distance_nm` | route_total_nm or gcd_nm | Nautical miles |
| `aircraft_cruise_speed_kts` | fn_GetAircraftPerformance | Knots |
| `flight_phase` | swim_flights.phase | Current phase |
| `filed_route` | swim_flights.fp_route | Full route string |
| `latitude` / `longitude` | swim_flights.lat/lon | Null if prefiled |

### Error Responses

| Code | Condition |
|------|-----------|
| 401 | Missing/invalid API key |
| 400 | Missing `flights`, not an array, empty, exceeds 50 |
| 400 | Individual record: missing callsign, invalid tobt datetime |
| 405 | Method not POST |
| 500 | Database error |

---

## Endpoint 2: CTOT Assignment

### `POST /api/swim/v1/ingest/ctot.php`

CTP assigns Controlled Take-Off Times and routes. PERTI derives EOBT/EDCT, stores in the TMI pipeline, and immediately recalculates all downstream fields.

**Auth**: SWIM API key with write permission + `ctp` authority group.
Pattern: `swim_init_auth(true, true)` then `$auth->canWriteField('ctp')`.

### Request

```json
{
  "assignments": [
    {
      "callsign": "BAW123",
      "ctot": "2026-04-23T12:45:00Z",
      "delay_minutes": 67,
      "delay_reason": "VOLUME",
      "program_name": "CTP_EAST26_GDP",
      "source_system": "CTP",
      "assigned_route": "HAPIE DCT CYMON NAT-A LIMRI ...",
      "assigned_track": "A",
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
| `assignments` | object[] | Yes | 1-50 items |
| `[].callsign` | string | Yes | 2-12 chars |
| `[].ctot` | string | Yes | ISO 8601 datetime — Controlled Take-Off Time (wheels-up) |
| `[].delay_minutes` | int | No | >= 0 |
| `[].delay_reason` | string | No | VOLUME, WEATHER, EQUIPMENT, RUNWAY, OTHER |
| `[].cta_utc` | string | No | ISO 8601 datetime — Controlled Time of Arrival |
| `[].program_name` | string | No | Control program identifier |
| `[].program_id` | int | No | FK to tmi_programs if applicable |
| `[].source_system` | string | No | Originating system (default: API key source_id) |
| `[].assigned_route` | string | No | TMU-assigned route (full route string) |
| `[].assigned_track` | string | No | Assigned NAT/oceanic track. Pattern: `^[A-Z]{1,2}\d?$` (e.g., A, B, SM1) |
| `[].route_segments` | object | No | Decomposed route segments |
| `[].route_segments.na` | string | No | NA segment (dep to oceanic entry) |
| `[].route_segments.oceanic` | string | No | Oceanic segment |
| `[].route_segments.eu` | string | No | EU segment (oceanic exit to arr) |

### CTOT → Derived Times

```
CTOT  (from CTP)              — Controlled Take-Off Time (wheels-up)
                                 Stored: swim_flights.target_takeoff_time
EOBT  = CTOT - taxi_ref       — Estimated Off-Block Time (pushback)
                                 Stored: swim_flights.estimated_off_block_time,
                                         swim_flights.controlled_time_of_departure,
                                         swim_flights.edct_utc,
                                         tmi_flight_control.ctd_utc,
                                         adl_flight_times.etd_utc
EDCT  = EOBT                  — Expected Departure Clearance Time (= gate departure)
ETE   = sp_CalculateETA(@flight_uid, @departure_override=CTOT)
                               — Enroute time from CTOT (climb+cruise+descent)
ETA   = CTOT + ETE            — Estimated Time of Arrival
delay = CTOT - original_etot  — Delay in minutes vs original schedule
```

`taxi_ref` comes from `airport_taxi_reference` for the departure airport (default 600s / 10 min if no reference).

### Immediate Recalculation Cascade

For each flight, the API synchronously executes:

```
API Request
  │
  ├─1→ tmi_flight_control (VATSIM_TMI) — canonical TMI record
  │      INSERT or UPDATE with ctl_type='CTP'
  │      Columns: flight_uid, callsign,
  │               ctd_utc = derived EOBT (gate departure, NOT CTOT),
  │               octd_utc (preserved), orig_etd_utc,
  │               program_delay_min, ctl_type, ctl_elem,
  │               dep_airport, arr_airport, control_assigned_utc
  │      NOTE: tmi_flight_control has no CTOT column. ctd_utc = gate departure.
  │            CTOT is only stored in swim_flights.target_takeoff_time.
  │
  ├─2→ adl_flight_times (VATSIM_ADL) — update departure times
  │      SET etd_utc = derived EOBT, std_utc = derived EOBT,
  │          estimated_takeoff_time = CTOT
  │
  ├─3→ sp_CalculateETA (VATSIM_ADL) — recalculate ETA from CTOT
  │      EXEC sp_CalculateETA @flight_uid, @departure_override = CTOT
  │      Recomputes: eta_utc, eta_runway_utc, eta_method, eta_confidence,
  │                  eta_dist_source, tod_dist_nm, tod_eta_utc
  │      Uses CTOT as departure anchor via @departure_override (not @now)
  │      Then: computed_ete_minutes = DATEDIFF(MINUTE, CTOT, recalculated eta_utc)
  │            → stored in adl_flight_times.computed_ete_minutes
  │
  ├─4→ Waypoint ETA recalc (VATSIM_ADL) — inline SQL, NOT the batch SP
  │      sp_CalculateWaypointETABatch_Tiered cannot target a single flight
  │      (it only accepts @tier + @max_flights parameters).
  │      Instead, use inline SQL:
  │        UPDATE adl_flight_waypoints
  │        SET eta_utc = DATEADD(SECOND,
  │              distance_from_dep_nm / @effective_speed_kts * 3600,
  │              @ctot)
  │        WHERE flight_uid = @flight_uid
  │      @effective_speed_kts from fn_GetAircraftPerformance + wind adjustment
  │
  ├─5→ Boundary crossing recalc (VATSIM_GIS via GISService)
  │      Recalculates adl_flight_planned_crossings
  │      GISService::calculateCrossingEtas() accepts optional $currentTime
  │      parameter — pass CTOT as the departure anchor
  │
  ├─6→ swim_flights (SWIM_API) — push all recalculated times
  │      UPDATE:
  │        target_takeoff_time = CTOT (wheels-up, FIXM: TTOT),
  │        controlled_time_of_departure = derived EOBT (gate, FIXM: CTD),
  │        estimated_off_block_time = derived EOBT,
  │        estimated_takeoff_time = CTOT (ETOT = CTOT when controlled),
  │        edct_utc = derived EOBT,
  │        estimated_time_of_arrival = new ETA,
  │        computed_ete_minutes = recalculated EET from step 3,
  │        controlled_time_of_arrival = CTA (if provided),
  │        original_edct (set once), delay_minutes, ctl_type
  │
  ├─7→ rad_amendments (VATSIM_TMI) — if assigned_route provided
  │      INSERT: callsign, origin, destination, original_route, assigned_route,
  │              status='ISSUED', tmi_id_label, source
  │
  ├─8→ adl_flight_tmi (VATSIM_ADL) — route + TMI sync
  │      UPDATE: rad_amendment_id, rad_assigned_route (if route provided)
  │      UPDATE: ctd_utc = derived EOBT, program_delay_min, ctl_type
  │
  └─9→ ctp_flight_control (VATSIM_TMI) — if route_segments/track provided
         UPDATE: seg_na_route, seg_oceanic_route, seg_eu_route,
                 seg_*_status='VALIDATED', edct_utc = derived EOBT,
                 tmi_control_id,
                 assigned_nat_track (if assigned_track provided)
         NOTE: assigned_nat_track column must be added via migration
               (does not exist in current schema from migration 045)
```

Steps 1-9 happen synchronously per flight. Batch size capped at 50.

### Concurrency

CTP is expected to send many small batches in quick succession (10+ per minute during slot assignment). Considerations:

- **No global lock** — each request operates on independent flight_uids; concurrent requests touching different flights are safe
- **Same-flight collision** — if two concurrent requests assign CTOT for the same callsign, last-write-wins on `tmi_flight_control` (same as existing CTP ingest behavior)
- **Database load** — 50 flights × 9 steps × 10 req/min = ~4,500 SQL operations/min across 4 databases. This is within normal daemon load (~3,000 ops/min for ADL refresh alone)
- **PostGIS bottleneck** — boundary crossing recalc (step 5) is the slowest step (~100-200ms/flight on B2s tier). For 50 flights, expect ~5-10s per request for this step. Concurrent requests are safe since each flight's crossings are independent rows

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
        "ctot": "2026-04-23T12:45:00Z",
        "eobt": "2026-04-23T12:33:00Z",
        "edct_utc": "2026-04-23T12:33:00Z",
        "estimated_time_of_arrival": "2026-04-23T19:37:00Z",
        "estimated_elapsed_time": 412,
        "eta_method": "V35_SEG_WIND",
        "delay_minutes": 67,
        "route_amendment_id": 789,
        "assigned_track": "A",
        "recalc_status": "complete"
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

Result `status` values: `created`, `updated`, `skipped` (idempotent same-CTOT), `error`.

### Idempotency

- Same callsign + same derived EOBT as existing `tmi_flight_control.ctd_utc` → `skipped` (no recalc)
- Same callsign + different CTOT → `updated` (octd_utc preserved, full recalc)
- New callsign (no existing control) → `created` (full recalc)

### Route Handling

When `assigned_route` is provided:

1. **Stored separately from filed route** — never touches `adl_flight_plan.fp_route` or `swim_flights.fp_route`
2. **Written to `rad_amendments`** — status='ISSUED', tracks the TMU/ATC-assigned route
3. **Written to `adl_flight_tmi.rad_assigned_route`** — visible to ADL consumers
4. **Compliance is manual/future** — no automated compliance daemon exists. When the pilot accepts and re-files via VATSIM, the updated route flows through the normal ADL ingest pipeline.

When `assigned_track` is provided:
- Stored in `ctp_flight_control.assigned_nat_track` (**new column — requires migration**)
- Pattern validated: `^[A-Z]{1,2}\d?$` (A, B, SM1, etc.)

When `route_segments` is provided (CTP-specific):
- Stored in `ctp_flight_control` segment columns (seg_na_route, seg_oceanic_route, seg_eu_route)
- Status set to 'VALIDATED'

### Error Responses

| Code | Condition |
|------|-----------|
| 401 | Missing/invalid API key |
| 403 | API key lacks write permission or `ctp` authority |
| 400 | Missing `assignments`, not an array, empty, exceeds 50 |
| 400 | Individual record: missing callsign or ctot, invalid datetime, invalid track format |
| 405 | Method not POST |
| 500 | Database error |

---

## SWIM Authority Configuration

No new authority groups needed. Uses existing `ctp` authority:

```php
// load/swim_config.php (line 185)
'ctp' => ['CTP_API', true],   // CTP_API primary, override allowed
```

The CTOT ingest checks `canWriteField('ctp')` at the API level, then uses **server-side direct SQL** for TMI/ADL/SWIM writes. This is the same pattern as `ingest/ctp.php` — the CTP authority check gates access, but the actual writes bypass field-level authority since the server code is the trusted actor.

**Why not use `tmi` authority**: `$SWIM_DATA_AUTHORITY['tmi'] = ['VATCSCC', false]` — immutable, VATCSCC-only. The server-side bridge pattern is the correct approach.

---

## FIXM 4.3.0 Alignment

| FIXM Concept | API Field Name | swim_flights Column | Notes |
|-------------|----------------|---------------------|-------|
| Target Off-Block Time (TOBT) | `tobt` | `target_off_block_time` | From migration 014 |
| Estimated Off-Block Time (EOBT) | `eobt` / `estimated_off_block_time` | `estimated_off_block_time` | = CTOT - taxi_ref |
| Estimated Take-Off Time (ETOT) | `etot` | `estimated_takeoff_time` | **New column**. EOBT + taxi_ref |
| Controlled Take-Off Time (CTOT) | `ctot` | `target_takeoff_time` | Wheels-up. From migration 014 |
| Controlled Time of Departure (CTD) | `controlled_time_of_departure` | `controlled_time_of_departure` | = EOBT (gate departure) |
| Estimated Elapsed Time (EET) | `estimated_elapsed_time` | `computed_ete_minutes` | **New column**. sp_CalculateETA result. Distinct from filed `ete_minutes` |
| Estimated Time of Arrival (ETA) | `estimated_time_of_arrival` | `estimated_time_of_arrival` | |
| Controlled Time of Arrival (CTA) | `cta_utc` | `controlled_time_of_arrival` | |
| EDCT | `edct_utc` | `edct_utc` | = EOBT (gate departure) |
| Original EDCT | `original_edct` | `original_edct` | Set once |

---

## ETE Computation Details

### Method: `sp_CalculateETA` V3.5 (Segment Wind Integration)

The ETE endpoint calls the same computation engine as all PERTI ETA calculations. This ensures consistency across the system.

### Aircraft Performance Priority

| Rank | Source | Table |
|------|--------|-------|
| 1 | BADA (EUROCONTROL) | `aircraft_performance_ptf` / `_apf` |
| 2 | OpenAP (TU Delft) | `aircraft_performance_profiles` (source='OPENAP') |
| 3 | Manual seed | `aircraft_performance_profiles` (source='SEED') |
| 4 | Category defaults | Weight class + engine type (e.g., `_DEF_JH`) |
| 5 | Hardcoded | 280 KIAS climb / 450 KTAS cruise / 280 KIAS descent |

### Distance Priority

| Rank | Source | Column |
|------|--------|--------|
| 1 | Parsed route distance | `adl_flight_plan.route_total_nm` |
| 2 | Great Circle Distance | `adl_flight_plan.gcd_nm` |

### Phase Model

```
TOC distance = (filed_alt - dep_elev) / 1000 × 2.0 nm
TOD distance = (filed_alt - dest_elev) / 1000 × 3.0 nm

climb_time   = toc_dist / (climb_speed + wind_climb) × 60 min
cruise_time  = (route_dist - toc_dist - tod_dist) / (cruise_speed + wind_cruise) × 60 min
descent_time = tod_dist / (descent_speed + wind_descent) × 60 min

ETE = climb_time + cruise_time + descent_time
```

Wind adjustments applied when |wind| > 5 kts (noise filter).

### Confidence Scoring

| Scenario | Confidence |
|----------|-----------|
| Prefiled flight (TOBT-anchored) | 0.65-0.70 |
| Gate/taxiing with performance data | 0.70-0.75 |
| Enroute with segment wind | 0.88-0.92 |
| Descent < 50nm | 0.95-0.97 |

---

## Database Dependencies

### Existing Tables Used (no schema changes needed)

| Table | Database | Purpose |
|-------|----------|---------|
| `swim_flights` | SWIM_API | ETE query source, CTOT push target, TOBT storage |
| `adl_flight_times` | VATSIM_ADL | ETA recalculation, departure time updates |
| `adl_flight_plan` | VATSIM_ADL | Route distance, GCD for ETE computation |
| `adl_flight_waypoints` | VATSIM_ADL | Waypoint ETA recalculation |
| `adl_flight_planned_crossings` | VATSIM_ADL | Boundary crossing recalculation |
| `airport_taxi_reference` | VATSIM_ADL | Taxi time for CTOT→EOBT derivation |
| `aircraft_performance_profiles` | VATSIM_ADL | Cruise/climb/descent speeds |
| `tmi_flight_control` | VATSIM_TMI | Canonical CTOT/CTD storage |
| `rad_amendments` | VATSIM_TMI | Route amendment storage |
| `adl_flight_tmi` | VATSIM_ADL | ADL-side TMI + RAD data |
| `ctp_flight_control` | VATSIM_TMI | CTP route segments + track |

### Stored Procedures Called

| Procedure | Database | Called By |
|-----------|----------|-----------|
| `sp_CalculateETA` | VATSIM_ADL | Both endpoints (ETE query + CTOT recalc). Requires new `@departure_override` parameter. |
| `fn_GetAircraftPerformance` | VATSIM_ADL | Via sp_CalculateETA + waypoint ETA inline SQL |
| `sp_CalculatePlannedCrossings` | VATSIM_ADL | CTOT recalc (step 5, single-flight boundary crossing recalc) |

> **Note**: `sp_CalculateWaypointETABatch_Tiered` **cannot** target a single flight (accepts only `@tier` + `@max_flights`, no `@flight_uid`). Waypoint ETA recalc (step 4) uses **inline SQL** instead.

### PostGIS Functions Called

| Function | Database | Called By |
|----------|----------|-----------|
| `calculateCrossingEtas()` | VATSIM_GIS | CTOT recalc (step 5, via GISService) |

### CHECK Constraint Note

`tmi_flight_control.ctl_type` CHECK constraint (migration 003) must include 'CTP'. The existing `ingest/ctp.php` uses `ctl_type = 'CTP'`, so this has been modified on the live database. Verify or apply:

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
├── ete.php                    # NEW - ETE query with TOBT
├── ingest/
│   ├── ctot.php               # NEW - CTOT assignment + recalc
│   ├── ctp.php                # Existing CTP slot ingest (reference)
│   ├── adl.php                # Existing ADL ingest (reference)
│   └── ...
├── auth.php                   # Existing - SwimAuth, SwimResponse
├── flights.php                # Existing - flight query (reference)
└── ...
```

Both new files follow established SWIM endpoint patterns:
- `require_once __DIR__ . '/auth.php'` (for `ete.php`) or `require_once __DIR__ . '/../auth.php'` (for `ingest/ctot.php`)
- `swim_get_json_body()` for POST body parsing
- `SwimResponse::success()` / `SwimResponse::error()` for responses
- `sqlsrv_query()` for Azure SQL (not PDO)
- `global $conn_swim` (SWIM_API), `get_conn_tmi()`, `get_conn_adl()`, `get_conn_gis()` for cross-DB operations

---

## Prerequisites (Schema Changes Required)

These modifications are needed before the API endpoints can be implemented:

1. **`sp_CalculateETA` — add `@departure_override` parameter**
   - Current signature: `sp_CalculateETA @flight_uid BIGINT`
   - Required: `sp_CalculateETA @flight_uid BIGINT, @departure_override DATETIME2(0) = NULL`
   - All internal `@now` references must use `ISNULL(@departure_override, SYSUTCDATETIME())`
   - Default NULL preserves backward compatibility (all existing callers unaffected)

2. **`swim_flights` — add `estimated_takeoff_time` and `computed_ete_minutes` columns**
   - `ALTER TABLE dbo.swim_flights ADD estimated_takeoff_time DATETIME2(0) NULL` — ETOT (EOBT + taxi)
   - `ALTER TABLE dbo.swim_flights ADD computed_ete_minutes SMALLINT NULL` — computed EET from sp_CalculateETA
   - Note: existing `ete_minutes` column is the **pilot-filed** ETE and must NOT be overwritten

3. **`adl_flight_times` — add `estimated_takeoff_time` and `computed_ete_minutes` columns**
   - `ALTER TABLE dbo.adl_flight_times ADD estimated_takeoff_time DATETIME2(0) NULL` — ETOT
   - `ALTER TABLE dbo.adl_flight_times ADD computed_ete_minutes SMALLINT NULL` — computed EET
   - Note: existing `ete_minutes` column in adl_flight_times is the **pilot-filed** value

4. **`ctp_flight_control` — add `assigned_nat_track` column**
   - Migration 045 created this table but has no NAT track column
   - Add: `ALTER TABLE ctp_flight_control ADD assigned_nat_track VARCHAR(4) NULL`
   - Pattern: `^[A-Z]{1,2}\d?$` (e.g., A, B, SM1)

5. **`tmi_flight_control.ctl_type` CHECK constraint — verify 'CTP' is included**
   - Migration 003 CHECK does not include 'CTP', but `ingest/ctp.php` uses it
   - Verify live DB has the updated constraint; if not, apply the ALTER from the CHECK Constraint Note section below

---

## Non-Goals

- **No auto-delivery**: CTOTs/EDCTs are stored but not automatically pushed to pilots. Operator manually triggers via EDCTDelivery channels.
- **No automated route compliance**: `rad_amendments` status remains 'ISSUED' until manually resolved. Pilot re-file updates route naturally via ADL ingest.
- **No new database tables**: Uses existing tables. Schema changes for new columns needed (see Prerequisites above).
- **No new authority groups**: Uses existing `ctp` authority from `swim_config.php`.
- **No WebSocket push**: May be added later; initial version is REST-only.
- **No trajectory simulation**: ETE uses the existing phase-based distance/speed model, not physics-based trajectory prediction. This is consistent with all other PERTI ETA consumers.

---

## Testing Strategy

1. **ETE endpoint**: POST with known active callsigns + TOBT, verify:
   - Computed ETE matches `sp_CalculateETA` output for same flight
   - ETOT = TOBT + taxi_ref for the departure airport
   - ETA = ETOT + ETE
   - TOBT is stored on swim_flights record
   - Unmatched callsigns in `unmatched` array
   - Omitted TOBT falls back to existing etd_utc

2. **CTOT assignment**: POST with test callsign + CTOT, verify:
   - `tmi_flight_control` record created with `ctd_utc` = derived EOBT (NOT CTOT), `ctl_type='CTP'`
   - `swim_flights.target_takeoff_time` = CTOT (wheels-up)
   - Derived EOBT = CTOT - taxi_ref stored in `adl_flight_times.etd_utc`
   - `sp_CalculateETA` recalculated ETA from CTOT via `@departure_override`
   - Waypoint ETAs updated in `adl_flight_waypoints` (inline SQL)
   - Boundary crossings updated in `adl_flight_planned_crossings`
   - `swim_flights` updated with all recalculated times
   - Response includes recalculated `estimated_time_of_arrival` and `estimated_elapsed_time`

3. **Route assignment**: CTOT + assigned_route, verify:
   - `rad_amendments` record created, status='ISSUED'
   - `adl_flight_tmi.rad_assigned_route` updated
   - `adl_flight_plan.fp_route` NOT touched
   - `assigned_track` stored in `ctp_flight_control.assigned_nat_track`

4. **Idempotency**: Same CTOT re-submitted → `skipped`, no recalc. Different CTOT → `updated`, full recalc.

5. **Auth**: Read-only keys can access ETE but not CTOT. Keys without `ctp` authority get 403 on CTOT.
