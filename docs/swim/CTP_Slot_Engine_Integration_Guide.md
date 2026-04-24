# CTP Slot Engine — Flowcontrol Integration Guide

> **Version:** 1.0 — 2026-04-23
> **Base URL:** `https://perti.vatcscc.org`
> **Auth:** `Authorization: Bearer {api_key}` or `X-API-Key: {api_key}` — key must have CTP write authority

This document describes how the flowcontrol system integrates with PERTI's CTP Oceanic Slot Assignment Engine. PERTI owns slot computation; flowcontrol owns operational orchestration.

## Integration Flow

```
1. Push tracks        POST /api/swim/v1/ctp/push-tracks.php
2. Push constraints   POST /api/swim/v1/ctp/push-constraints.php
3. For each flight:
   a. Request slot    POST /api/swim/v1/ctp/request-slot.php    → ranked candidates
   b. Confirm slot    POST /api/swim/v1/ctp/confirm-slot.php    → assign + CTOT cascade
4. Release if needed  POST /api/swim/v1/ctp/release-slot.php
5. Poll status        GET  /api/swim/v1/ctp/session-status.php
6. Subscribe WS       wss://perti.vatcscc.org:8090              → real-time events
```

Steps 1-2 are one-time setup (idempotent — safe to re-push). Step 3 repeats per flight. Step 5 is optional polling for dashboard state.

## Authentication

Every request must include an API key via one of:
- `Authorization: Bearer sk_swim_...`
- `X-API-Key: sk_swim_...`

The key must be active, unexpired, and have CTP write authority (`canWriteField('ctp')` = true). Read-only endpoints (session-status) require only a valid key.

All responses use `Content-Type: application/json`.

## Error Format

All errors return:
```json
{
  "success": false,
  "error": "Human-readable message",
  "code": "MACHINE_CODE"
}
```

Common codes: `SESSION_NOT_FOUND` (404), `SESSION_NOT_ACTIVE` (409), `FLIGHT_NOT_FOUND` (404), `NO_TRACKS_CONFIGURED` (409), `SLOTS_NOT_READY` (409), `SLOT_TAKEN` (409), `SLOT_FROZEN` (409), `INVALID_REQUEST` (400).

---

## Endpoint 1: Push Tracks

**`POST /api/swim/v1/ctp/push-tracks.php`**

Push NAT track definitions for a session. Idempotent — re-pushing the same track_name updates it. Tracks not in the push are left unchanged.

**Request:**
```json
{
  "session_name": "CTPE26",
  "tracks": [
    {
      "track_name": "A",
      "route_string": "MUSAK 50N060W 51N050W 52N040W 52N030W 52N020W GISTI",
      "oceanic_entry_fix": "MUSAK",
      "oceanic_exit_fix": "GISTI",
      "is_active": true,
      "max_acph": 10
    },
    {
      "track_name": "B",
      "route_string": "JOOPY 50N050W 51N040W 51N030W 51N020W LIMRI",
      "oceanic_entry_fix": "JOOPY",
      "oceanic_exit_fix": "LIMRI",
      "is_active": true,
      "max_acph": 12
    }
  ]
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `session_name` | string | Yes (or `session_id`) | e.g. "CTPE26" |
| `tracks[].track_name` | string | Yes | Single letter or short name |
| `tracks[].route_string` | string | Yes | Space-separated fixes/coordinates for the oceanic segment |
| `tracks[].oceanic_entry_fix` | string | Yes | First fix of oceanic segment (OEP) |
| `tracks[].oceanic_exit_fix` | string | Yes | Last fix of oceanic segment |
| `tracks[].is_active` | bool | No | Default: true |
| `tracks[].max_acph` | int | No | Max aircraft/hour for this track. Default: 10 |

**Response (200):**
```json
{
  "success": true,
  "data": {
    "tracks_received": 2,
    "tracks_created": 1,
    "tracks_updated": 1
  }
}
```

---

## Endpoint 2: Push Constraints

**`POST /api/swim/v1/ctp/push-constraints.php`**

Push facility constraint parameters. These feed the advisory system — advisories are warnings, never blocking. Idempotent per facility+type pair.

**Request:**
```json
{
  "session_name": "CTPE26",
  "constraints": [
    {"facility": "EGLL", "facility_type": "airport", "maxAircraftPerHour": 12},
    {"facility": "LFPG", "facility_type": "airport", "maxAircraftPerHour": 10},
    {"facility": "EGGX", "facility_type": "fir", "maxAircraftPerHour": 25},
    {"facility": "BIRD", "facility_type": "fir", "maxAircraftPerHour": 20},
    {"facility": "MALOT", "facility_type": "fix", "maxAircraftPerHour": 6},
    {"facility": "EGTT_S", "facility_type": "sector", "maxAircraftPerHour": 15}
  ]
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `session_name` | string | Yes (or `session_id`) | Session reference |
| `constraints[].facility` | string | Yes | Facility identifier (ICAO airport, FIR code, fix name, sector ID) |
| `constraints[].facility_type` | string | Yes | One of: `airport`, `fir`, `fix`, `sector` |
| `constraints[].maxAircraftPerHour` | int | Yes | Rate cap. Must be >= 1 |

> **Aliases accepted:** `facility_name` for `facility`, `max_acph` for `maxAircraftPerHour`.

**Response (200):**
```json
{
  "success": true,
  "data": {
    "constraints_received": 6,
    "constraints_created": 4,
    "constraints_updated": 2
  }
}
```

---

## Endpoint 3: Request Slot

**`POST /api/swim/v1/ctp/request-slot.php`**

Returns ranked slot candidates with advisory status. Does NOT assign — call confirm-slot to commit.

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "origin": "KJFK",
  "destination": "EGLL",
  "aircraft_type": "B77W",
  "preferred_track": "B",
  "tobt": "2026-10-15T18:30:00Z",
  "is_airborne": false,
  "na_route": "KJFK DCT HAPIE J584 JOOPY",
  "eu_route": "LIMRI UL9 BHD UL607 EGLL"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `session_name` | string | Yes (or `session_id`) | Session reference |
| `callsign` | string | Yes | 2-12 chars, A-Z0-9 |
| `origin` | string | Yes | ICAO departure airport |
| `destination` | string | Yes | ICAO arrival airport |
| `aircraft_type` | string | Yes | ICAO type designator |
| `preferred_track` | string | No | Preferred track letter. Ranked first if available |
| `tobt` | string | Yes | Target off-block time, ISO 8601 UTC |
| `is_airborne` | bool | No | True if flight already airborne. Default: false |
| `na_route` | string | Yes | North American segment route string |
| `eu_route` | string | Yes | European segment route string |

> **Note:** The oceanic (OCA) route is NOT sent in the request. PERTI uses the `route_string` from push-tracks for each track. `na_route` and `eu_route` are flight-specific; the OCA route is track-specific.

**Response (200):**
```json
{
  "success": true,
  "data": {
    "recommended": {
      "track": "B",
      "slot_time_utc": "2026-10-15T20:15:00Z",
      "timing_chain": {
        "ctot_utc": "2026-10-15T18:48:00Z",
        "off_utc": "2026-10-15T18:55:00Z",
        "oep_utc": "2026-10-15T20:15:00Z",
        "exit_utc": "2026-10-16T00:42:00Z",
        "cta_utc": "2026-10-16T02:32:00Z",
        "taxi_min": 7,
        "na_ete_min": 80,
        "oca_ete_min": 267,
        "eu_ete_min": 110,
        "total_ete_min": 464,
        "cruise_speed_kts": 487,
        "oceanic_entry_fix": "JOOPY",
        "oceanic_exit_fix": "LIMRI"
      },
      "advisories": [
        {
          "type": "DEST_RATE",
          "facility": "EGLL",
          "detail": "14/12 arrivals per hour",
          "severity": "WARN"
        }
      ],
      "advisory_count": 1
    },
    "alternatives": [
      {
        "track": "C",
        "slot_time_utc": "2026-10-15T20:10:00Z",
        "timing_chain": { "...": "..." },
        "advisories": [],
        "advisory_count": 0
      }
    ]
  }
}
```

**Airborne variant:** If `is_airborne: true`, the response omits `ctot_utc` (departure already happened). The recommended slot is based on projected OEP from current position.

**No open slots:** If all tracks are full, `recommended` is `null` and `alternatives` is `[]`.

### Timing Chain Fields

| Field | Meaning |
|-------|---------|
| `ctot_utc` | Calculated takeoff time (CTOT) — deliver to pilot |
| `off_utc` | Wheels-off time = ctot + taxi |
| `oep_utc` | Oceanic entry point time = slot anchor |
| `exit_utc` | Oceanic exit point time |
| `cta_utc` | Calculated time of arrival at destination |
| `taxi_min` | Taxi time from PERTI's airport taxi reference |
| `na_ete_min` | NA segment estimated time enroute (minutes) |
| `oca_ete_min` | Oceanic segment ETE (minutes) |
| `eu_ete_min` | EU segment ETE (minutes) |
| `total_ete_min` | Total gate-to-gate ETE |
| `cruise_speed_kts` | Estimated cruise speed used in computation |
| `oceanic_entry_fix` | OEP fix name for this track |
| `oceanic_exit_fix` | Oceanic exit fix name for this track |

### Advisory Types

All advisories are **WARN** severity — they inform the coordinator but never block assignment.

| Type | Meaning |
|------|---------|
| `DEST_RATE` | Destination airport arrival rate exceeded |
| `FIR_CAPACITY` | FIR aircraft count exceeds pushed constraint |
| `FIX_THROUGHPUT` | Fix throughput exceeds pushed constraint |
| `SECTOR_CAPACITY` | Sector capacity exceeded (future — V1 returns null) |
| `ECFMP` | Active ECFMP regulation affects this routing |

---

## Endpoint 4: Confirm Slot

**`POST /api/swim/v1/ctp/confirm-slot.php`**

Assign a specific slot from the candidates returned by request-slot. PERTI runs the 9-step CTOT recalculation cascade (updates flight times, ETAs, waypoints, boundary crossings, SWIM snapshot).

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "track": "B",
  "slot_time_utc": "2026-10-15T20:15:00Z"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `session_name` | string | Yes (or `session_id`) | Session reference |
| `callsign` | string | Yes | Must match a flight in PERTI's ADL |
| `track` | string | Yes | Track letter from the request-slot response |
| `slot_time_utc` | string | Yes | Exact slot time from the request-slot response, ISO 8601 |

**Response (200):**
```json
{
  "success": true,
  "data": {
    "status": "ASSIGNED",
    "ctot_utc": "2026-10-15T18:48:00Z",
    "cta_utc": "2026-10-16T02:32:00Z",
    "slot_id": 48291,
    "cascade_status": "complete"
  }
}
```

If `is_airborne` was true at request time: `status` = `"FROZEN"` and `ctot_utc` is omitted.

**Race condition:** If another flight was assigned that slot between request and confirm, returns 409 `SLOT_TAKEN`. Retry by calling request-slot again.

---

## Endpoint 5: Release Slot

**`POST /api/swim/v1/ctp/release-slot.php`**

Cancel a slot assignment. The tmi_slot returns to OPEN and becomes available for other flights.

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "reason": "COORDINATOR_RELEASE"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `session_name` | string | Yes (or `session_id`) | Session reference |
| `callsign` | string | Yes | Flight to release |
| `reason` | string | No | Default: `COORDINATOR_RELEASE` |

**Valid reasons:**
- `COORDINATOR_RELEASE` — manual release by coordinator
- `DISCONNECT` — pilot disconnected (also the only reason that can release a FROZEN slot)
- `MISSED_REASSIGN` — releasing a missed slot before reassigning

**Response (200):**
```json
{
  "success": true,
  "data": {
    "released_slot_time_utc": "2026-10-15T20:15:00Z",
    "released_track": "B",
    "slot_status": "OPEN"
  }
}
```

**FROZEN slots:** Cannot be released unless `reason` = `DISCONNECT`. Returns 409 `SLOT_FROZEN` otherwise.

---

## Endpoint 6: Session Status

**`GET /api/swim/v1/ctp/session-status.php?session_name=CTPE26`**

Read-only. Returns current session health for dashboard polling. Does not require CTP write authority — any valid API key works.

**Query params:** `session_name` (string) or `session_id` (int)

**Response (200):**
```json
{
  "success": true,
  "data": {
    "session_name": "CTPE26",
    "status": "ACTIVE",
    "slot_generation_status": "READY",
    "tracks": [
      {
        "track_name": "A",
        "total_slots": 60,
        "assigned": 34,
        "frozen": 12,
        "open": 14,
        "utilization_pct": 76.7
      },
      {
        "track_name": "B",
        "total_slots": 72,
        "assigned": 41,
        "frozen": 18,
        "open": 13,
        "utilization_pct": 81.9
      }
    ],
    "constraint_status": {
      "configured": [
        {"facility": "EGLL", "facility_type": "airport", "limit": 12},
        {"facility": "EGGX", "facility_type": "fir", "limit": 25}
      ],
      "ecfmp_active_regulations": 2
    },
    "flights": {
      "total": 487,
      "assigned": 312,
      "frozen": 89,
      "at_risk": 7,
      "missed": 3,
      "released": 15,
      "unassigned": 175
    }
  }
}
```

---

## Slot Lifecycle

Flowcontrol does not need to manage slot state transitions — PERTI handles them automatically. Understanding the lifecycle helps interpret session-status and WebSocket events.

```
ASSIGNED ──departs──────> FROZEN
ASSIGNED ──misses───────> MISSED  ──reassign via new request-slot──> ASSIGNED
ASSIGNED ──disconnects──> RELEASED
ASSIGNED ──manual───────> RELEASED
FROZEN   ──disconnects──> RELEASED

RELEASED → slot returns to OPEN (available for other flights)
```

| Status | Meaning |
|--------|---------|
| `ASSIGNED` | Has CTOT, awaiting departure |
| `AT_RISK` | Projected to miss OEP time by 5-15 minutes |
| `MISSED` | >15 min past OEP time — slot freed, needs reassignment |
| `FROZEN` | Flight departed — slot locked, no further changes |
| `RELEASED` | Disconnected or manually released — slot freed |

**Freeze-on-airborne:** Once airborne, the slot is permanently consumed. No recalculation.

**Disconnect overrides freeze:** If a pilot disconnects, the slot is released regardless of phase (even if FROZEN).

**Missed slot reassignment:** Call request-slot again for the same callsign. PERTI finds the next available slot. Then confirm-slot to assign.

---

## WebSocket Events (Optional)

Connect to `wss://perti.vatcscc.org:8090` for real-time slot lifecycle events instead of polling session-status.

**Event types:**
```json
{"type": "ctp_slot_assigned", "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "slot_time": "2026-10-15T20:15:00Z", "ctot": "2026-10-15T18:48:00Z", "cta": "2026-10-16T02:32:00Z"}
{"type": "ctp_slot_frozen",   "session_name": "CTPE26", "callsign": "BAW117", "track": "B"}
{"type": "ctp_slot_at_risk",  "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "OEP_SLIPPING", "slip_min": 8.2}
{"type": "ctp_slot_missed",   "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "OEP_EXCEEDED"}
{"type": "ctp_slot_released", "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "DISCONNECT"}
```

---

## Integration Sequence (Typical CTP Event)

### Phase 1: Setup (hours before event)

```
1. Create CTP session in PERTI UI (session_name: "CTPE26", direction: "eastbound", window: 16:00-22:00Z)
2. Push track definitions:
   POST /api/swim/v1/ctp/push-tracks.php
   Body: { session_name: "CTPE26", tracks: [{track_name: "A", ...}, {track_name: "B", ...}] }
3. Push facility constraints:
   POST /api/swim/v1/ctp/push-constraints.php
   Body: { session_name: "CTPE26", constraints: [{facility: "EGLL", facility_type: "airport", maxAircraftPerHour: 12}, ...] }
4. Activate session in PERTI UI → generates slot grid (tmi_programs + tmi_slots per track)
```

### Phase 2: Slot Assignment (during event)

For each flight needing a slot:
```
1. Request candidates:
   POST /api/swim/v1/ctp/request-slot.php
   Body: { session_name: "CTPE26", callsign: "BAW117", origin: "KJFK", destination: "EGLL",
           aircraft_type: "B77W", preferred_track: "B", tobt: "2026-10-15T18:30:00Z",
           na_route: "KJFK DCT HAPIE J584 JOOPY", eu_route: "LIMRI UL9 BHD UL607 EGLL" }

2. Review response — coordinator picks from recommended + alternatives

3. Confirm assignment:
   POST /api/swim/v1/ctp/confirm-slot.php
   Body: { session_name: "CTPE26", callsign: "BAW117", track: "B", slot_time_utc: "2026-10-15T20:15:00Z" }

4. Deliver CTOT to pilot via flowcontrol's communication channel
```

### Phase 3: Monitoring (during event)

```
- Poll GET /api/swim/v1/ctp/session-status.php?session_name=CTPE26 for dashboard
- OR subscribe to WebSocket for real-time events
- Re-push constraints periodically if rates change:
  POST /api/swim/v1/ctp/push-constraints.php (idempotent update)
```

### Phase 4: Exceptions

```
- Coordinator releases a slot:
  POST /api/swim/v1/ctp/release-slot.php
  Body: { session_name: "CTPE26", callsign: "BAW117", reason: "COORDINATOR_RELEASE" }

- Reassign after miss:
  POST /api/swim/v1/ctp/release-slot.php  (reason: "MISSED_REASSIGN")
  POST /api/swim/v1/ctp/request-slot.php  (same callsign, fresh candidates)
  POST /api/swim/v1/ctp/confirm-slot.php  (new slot)

- Disconnects are handled automatically by PERTI (slot released, event broadcast)
```

---

## Session Identification

All endpoints accept either:
- `"session_name": "CTPE26"` — human-readable name
- `"session_id": 42` — internal numeric ID

Use whichever is more convenient. Both resolve to the same session.

## Rate Limits

API keys are rate-limited per their tier configuration. During CTP events with high request-slot volume, ensure your key has sufficient rate allowance. Contact PERTI administrators if you need a tier upgrade.

## All Times Are UTC

Every timestamp field uses ISO 8601 UTC format: `YYYY-MM-DDTHH:MM:SSZ`. PERTI stores and returns all times in UTC.
