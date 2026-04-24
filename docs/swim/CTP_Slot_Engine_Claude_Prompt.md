# CTP Slot Engine — Client Implementation Prompt

> **Usage:** Copy everything below the line into a new Claude session alongside your flowcontrol codebase. Claude will have full context to implement the PERTI integration.

---

## Context

You are implementing the client-side integration between **flowcontrol** (vatsimnetwork/flowcontrol) and **PERTI** (perti.vatcscc.org), which is the slot assignment engine for CTP (Collaborative Traffic Planning) oceanic events on VATSIM.

**PERTI's role:** Computation engine — generates slot grids per NAT track, evaluates multi-constraint advisories, computes timing chains (CTOT → OEP → CTA), assigns slots, and runs a 9-step recalculation cascade.

**Flowcontrol's role:** Operational orchestration — pushes track/constraint config, requests slots on demand when a coordinator is ready, confirms assignments, delivers CTOTs to pilots, and monitors session health.

PERTI already has all 6 API endpoints implemented and deployed. Your job is to build the flowcontrol client that calls them.

## Authentication

All requests to PERTI require an API key via one of these headers:
```
Authorization: Bearer sk_swim_...
X-API-Key: sk_swim_...
```
The key must have CTP write authority. Read-only endpoints (session-status) accept any valid key.

**Base URL:** `https://perti.vatcscc.org`

## API Specification

All responses are JSON. All timestamps are ISO 8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`).

### Error Format (all endpoints)
```json
{"success": false, "error": "Human-readable message", "code": "MACHINE_CODE"}
```
Common codes: `SESSION_NOT_FOUND` (404), `SESSION_NOT_ACTIVE` (409), `FLIGHT_NOT_FOUND` (404), `NO_TRACKS_CONFIGURED` (409), `SLOTS_NOT_READY` (409), `SLOT_TAKEN` (409), `SLOT_FROZEN` (409), `INVALID_REQUEST` (400).

### Endpoint 1: Push Tracks
```
POST /api/swim/v1/ctp/push-tracks.php
```
Pushes NAT track definitions for a session. Idempotent — re-pushing same track_name updates it.

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
**Required fields per track:** `track_name`, `route_string`, `oceanic_entry_fix`, `oceanic_exit_fix`
**Optional:** `is_active` (default: true), `max_acph` (default: 10)

**Response (200):**
```json
{"success": true, "data": {"tracks_received": 2, "tracks_created": 1, "tracks_updated": 1}}
```

### Endpoint 2: Push Constraints
```
POST /api/swim/v1/ctp/push-constraints.php
```
Pushes facility rate constraints. These feed the advisory system (warnings, never blocking). Idempotent per facility+type.

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
**Required per constraint:** `facility` (string), `facility_type` (one of: `airport`, `fir`, `fix`, `sector`), `maxAircraftPerHour` (int >= 1)

**Response (200):**
```json
{"success": true, "data": {"constraints_received": 6, "constraints_created": 4, "constraints_updated": 2}}
```

### Endpoint 3: Request Slot
```
POST /api/swim/v1/ctp/request-slot.php
```
Returns ranked slot candidates with advisory status. Does NOT assign — coordinator reviews, then calls confirm-slot.

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
**Required:** `session_name` (or `session_id`), `callsign` (2-12 chars, A-Z0-9), `origin`, `destination`, `aircraft_type`, `tobt`, `na_route`, `eu_route`
**Optional:** `preferred_track`, `is_airborne` (default: false)

**Important:** The oceanic (OCA) route is NOT sent. PERTI uses the `route_string` from push-tracks for each track. Only `na_route` (flight-specific NA segment) and `eu_route` (flight-specific EU segment) are sent.

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
        {"type": "DEST_RATE", "facility": "EGLL", "detail": "14/12 arrivals per hour", "severity": "WARN"}
      ],
      "advisory_count": 1
    },
    "alternatives": [
      {
        "track": "C",
        "slot_time_utc": "2026-10-15T20:10:00Z",
        "timing_chain": {"ctot_utc": "...", "oep_utc": "...", "cta_utc": "..."},
        "advisories": [],
        "advisory_count": 0
      }
    ]
  }
}
```

**Key response fields:**
- `recommended` — best candidate (preferred track first, fewest advisories, earliest slot). Can be `null` if no slots available.
- `alternatives` — up to 5 additional candidates on other tracks
- `timing_chain.ctot_utc` — the CTOT to deliver to the pilot
- `timing_chain.oep_utc` — oceanic entry time (= slot anchor)
- `timing_chain.cta_utc` — calculated arrival time
- `advisories` — all `WARN` severity, never block assignment. Types: `DEST_RATE`, `FIR_CAPACITY`, `FIX_THROUGHPUT`, `SECTOR_CAPACITY`, `ECFMP`
- If `is_airborne: true`, `ctot_utc` is omitted (departure already happened)

### Endpoint 4: Confirm Slot
```
POST /api/swim/v1/ctp/confirm-slot.php
```
Assigns a specific slot. PERTI runs the full CTOT recalculation cascade internally.

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "track": "B",
  "slot_time_utc": "2026-10-15T20:15:00Z"
}
```
**Required:** `session_name` (or `session_id`), `callsign`, `track`, `slot_time_utc` (exact value from request-slot response)

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
- If airborne: `status` = `"FROZEN"`, no `ctot_utc`
- If slot was taken between request and confirm: 409 `SLOT_TAKEN` — retry with fresh request-slot

### Endpoint 5: Release Slot
```
POST /api/swim/v1/ctp/release-slot.php
```
Cancels a slot assignment. Slot returns to OPEN for other flights.

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "reason": "COORDINATOR_RELEASE"
}
```
**Required:** `session_name` (or `session_id`), `callsign`
**Optional:** `reason` (default: `COORDINATOR_RELEASE`)

**Valid reasons:** `COORDINATOR_RELEASE`, `DISCONNECT`, `MISSED_REASSIGN`

FROZEN slots can only be released with `reason: "DISCONNECT"`. Otherwise returns 409 `SLOT_FROZEN`.

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

### Endpoint 6: Session Status
```
GET /api/swim/v1/ctp/session-status.php?session_name=CTPE26
```
Read-only dashboard polling. Any valid API key works (no CTP write authority needed).

**Response (200):**
```json
{
  "success": true,
  "data": {
    "session_name": "CTPE26",
    "status": "ACTIVE",
    "slot_generation_status": "READY",
    "tracks": [
      {"track_name": "A", "total_slots": 60, "assigned": 34, "frozen": 12, "open": 14, "utilization_pct": 76.7},
      {"track_name": "B", "total_slots": 72, "assigned": 41, "frozen": 18, "open": 13, "utilization_pct": 81.9}
    ],
    "constraint_status": {
      "configured": [
        {"facility": "EGLL", "facility_type": "airport", "limit": 12},
        {"facility": "EGGX", "facility_type": "fir", "limit": 25}
      ],
      "ecfmp_active_regulations": 2
    },
    "flights": {
      "total": 487, "assigned": 312, "frozen": 89, "at_risk": 7,
      "missed": 3, "released": 15, "unassigned": 175
    }
  }
}
```

### WebSocket Events (Optional)
```
wss://perti.vatcscc.org:8090
```
Subscribe for real-time slot lifecycle events instead of polling:
```json
{"type": "ctp_slot_assigned", "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "slot_time": "2026-10-15T20:15:00Z", "ctot": "2026-10-15T18:48:00Z", "cta": "2026-10-16T02:32:00Z"}
{"type": "ctp_slot_frozen",   "session_name": "CTPE26", "callsign": "BAW117", "track": "B"}
{"type": "ctp_slot_at_risk",  "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "OEP_SLIPPING", "slip_min": 8.2}
{"type": "ctp_slot_missed",   "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "OEP_EXCEEDED"}
{"type": "ctp_slot_released", "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "DISCONNECT"}
```

## Slot Lifecycle (PERTI manages this automatically)

```
ASSIGNED ──departs──────> FROZEN     (slot locked, no changes)
ASSIGNED ──projected OEP slips 5-15min──> AT_RISK  (warning)
ASSIGNED ──projected OEP slips >15min──> MISSED   (slot freed, needs reassignment)
ASSIGNED ──disconnects──> RELEASED   (slot freed)
ASSIGNED ──manual───────> RELEASED   (slot freed)
FROZEN   ──disconnects──> RELEASED   (slot freed)
```

Flowcontrol does NOT need to manage transitions. PERTI detects disconnect/miss/airborne automatically. Flowcontrol only needs to:
1. Call release-slot for manual coordinator releases
2. Call request-slot + confirm-slot to reassign after a MISSED status

## What You Need to Build

### 1. API Client Service
Build an HTTP client that wraps all 6 endpoints. Handle:
- API key injection via header
- JSON request/response serialization
- Error response parsing (check `success` field, extract `code` for programmatic handling)
- Retry logic for `SLOT_TAKEN` (409) — re-call request-slot then confirm-slot

### 2. Session Setup Flow
When a CTP event is being configured:
- Call push-tracks with the track definitions from your track configuration
- Call push-constraints with facility rate limits from your constraint configuration
- Both are idempotent — safe to re-push anytime constraints or tracks change

### 3. Slot Assignment Flow
When a coordinator is ready to assign a flight:
1. Call request-slot with flight details → display recommended + alternatives to coordinator
2. Show advisories (all WARN, informational only) alongside each candidate
3. Coordinator picks a candidate → call confirm-slot with exact track + slot_time_utc
4. On success: extract `ctot_utc` from response → deliver to pilot via your communication channel
5. On `SLOT_TAKEN`: automatically retry from step 1

### 4. Dashboard Integration
- Poll session-status periodically (30-60s) OR subscribe to WebSocket for real-time updates
- Display per-track utilization (assigned/frozen/open counts, utilization %)
- Display flight status breakdown (assigned/frozen/at_risk/missed/released/unassigned)
- Highlight AT_RISK and MISSED flights for coordinator attention
- Show configured constraints and ECFMP regulation count

### 5. Exception Handling
- **Coordinator release:** Call release-slot with reason `COORDINATOR_RELEASE`
- **Reassignment after miss:** Call release-slot with reason `MISSED_REASSIGN`, then request-slot + confirm-slot
- **Disconnect:** Handled automatically by PERTI — you'll see `ctp_slot_released` WebSocket event or status change in session-status poll

## Session Identification
All endpoints accept either:
- `"session_name": "CTPE26"` — human-readable event name
- `"session_id": 42` — numeric ID

Use whichever your system stores. Both resolve identically.

## Integration Sequence Summary

```
SETUP (hours before event):
  1. push-tracks     → define NAT tracks A, B, C, ...
  2. push-constraints → set airport/FIR/fix/sector rate caps
  3. Session activated in PERTI UI → slot grid generated

OPERATIONS (during event):
  For each flight:
    4. request-slot   → get ranked candidates with advisories
    5. confirm-slot   → assign chosen slot, get CTOT
    6. Deliver CTOT to pilot

MONITORING:
    7. session-status (poll) or WebSocket (subscribe) → dashboard

EXCEPTIONS:
    8. release-slot   → manual release or pre-reassignment
    9. request-slot + confirm-slot → reassign after miss
```
