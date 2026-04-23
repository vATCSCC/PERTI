# CTP Oceanic Slot Assignment Engine — Design Spec

> **Version:** 1.0
> **Date:** 2026-04-23
> **Status:** Draft
> **Depends on:** CTP ETE/CTOT API (PR #324, merged), CTP schema (migrations 045-058)

## 1. Overview

PERTI becomes the slot assignment engine for CTP oceanic events. Instead of flowcontrol (vatsimnetwork/flowcontrol) assigning flat +20min slot buffers, PERTI handles all flow calculations: slot generation per NAT track, multi-constraint advisory checks, timing chain computation (CTOT → OEP → CTA), and the full recalculation cascade.

**Flowcontrol's role:** Track/route definitions, constraint parameters, orchestrating when to request slots. Flowcontrol calls PERTI on-demand when a coordinator is ready to assign a flight.

**PERTI's role:** Computation engine. Generates slot grids, evaluates constraint feasibility, computes timing chains using sp_CalculateETA, assigns slots, runs the 9-step CTOT recalculation cascade.

### Architecture

```
flowcontrol                              PERTI
───────────                              ─────
push-tracks   ──POST──>   ctp_session_tracks (store track definitions)
push-constraints ──POST──>   ctp_facility_constraints (store rates)

request-slot  ──POST──>   Constraint Advisor
                          ┌──────────────────────────┐
                          │ 1. Track slot availability │ ← tmi_slots
                          │ 2. Dest arrival rate       │ ← ctp_facility_constraints
                          │ 3. FIR capacity            │ ← ctp_facility_constraints
                          │ 4. Fix throughput           │ ← ctp_facility_constraints
                          │ 5. Sector capacity          │ ← ctp_facility_constraints
                          │ 6. ECFMP regulations        │ ← tmi_flow_measures
                          └───────────┬────────────────┘
                                      │ ranked candidates
              <──response──           ▼
                              Timing Chain Calculator
                              (sp_CalculateETA pipeline)

confirm-slot  ──POST──>   Assign slot + 9-step CTOT cascade
              <──response──  { ctot, cta, slot_id, cascade_status }
```

### Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Architecture | Layered constraint advisor on GDP slot engine | 80% code reuse; proven algorithm; CTP volumes (~5K flights) don't need LP solver |
| Constraint model | All advisory (warn, never block) | VATSIM coordinators need authority to override; system provides visibility, not enforcement |
| Assignment flow | Two-step: request (ranked candidates) → confirm (assign + cascade) | Coordinators see constraint picture before committing |
| Slot anchor | slot_time = oceanic entry time at OEP fix | Natural for oceanic separation; CTOT and CTA derived arithmetically |
| Timing computation | Full sp_CalculateETA pipeline | Most robust: wind correction, aircraft performance, route geometry |
| Airborne handling | Freeze on wheels-up; no recalculation | Prevents cascading recalc from imperfect ETE estimates |
| Disconnect | Overrides freeze; slot released | VATSIM disconnects are common; slot should not be wasted |
| Config ownership | Flowcontrol pushes ALL config (tracks + constraints) | PERTI is computation engine; flowcontrol manages operational parameters |
| Missed slots | Ground-only detection; compression backfills freed slots | Reuses existing sp_TMI_RunCompression |

## 2. Program & Slot Model

Each CTP session spawns one `tmi_programs` entry per track. Each track's slot grid represents oceanic entry times at the OEP fix.

```
ctp_sessions (CTPE26, eastbound, 16:00-22:00Z)
    │
    ├── ctp_session_tracks (track_name='A', program_id=401)
    │       └── tmi_programs (program_type='CTP', ctl_element='TRACK_A', rate=10/hr)
    │               └── tmi_slots (60 slots: 16:00, 16:06, 16:12, ...)
    │
    ├── ctp_session_tracks (track_name='B', program_id=402)
    │       └── tmi_programs (program_type='CTP', ctl_element='TRACK_B', rate=12/hr)
    │               └── tmi_slots (72 slots: 16:00, 16:05, 16:10, ...)
    │
    └── ctp_session_tracks (track_name='SM1', program_id=403)
            └── tmi_programs (program_type='CTP', ctl_element='TRACK_SM1', rate=8/hr)
                    └── tmi_slots (48 slots: 16:00, 16:07, 16:15, ...)
```

**Slot semantics:** `tmi_slots.slot_time_utc` = oceanic entry time at OEP fix. Two flights on consecutive slots on the same track are guaranteed to be separated by `slot_interval_min` at the OEP — which is how oceanic separation works.

**Session activation flow:**

1. Flowcontrol pushes track definitions via `push-tracks`
2. Flowcontrol pushes constraint parameters via `push-constraints`
3. PERTI operator activates session in ctp.php (or via API)
4. For each track in `ctp_session_tracks`:
   - INSERT `tmi_programs` (program_type='CTP', ctl_element=track_name)
   - Call `sp_TMI_GenerateSlots` with track's max_acph and session's slot_interval
5. Slot grid is ready for on-demand assignment

**Prerequisite migration:** The CHECK constraint `CK_tmi_programs_program_type` must be ALTERed to include 'CTP'. Currently allows: GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP, BLANKET, COMPRESSION.

## 3. Constraint Advisor

All constraints are **advisory** — they produce warnings but never block assignment. The constraint advisor ranks candidates by fewest advisories.

### 3.1 Constraint Checks (run sequentially, cheapest first)

**1. Track Availability**
- Query: `SELECT FROM tmi_slots WHERE program_id = @track_program AND slot_status = 'OPEN' ORDER BY slot_time_utc ASC`
- Advisory if: No open slots on requested track

**2. Destination Arrival Rate**
- Query: Count flights with CTA within ±30min of candidate CTA arriving at same destination
- Source: `ctp_flight_control` (already-assigned flights)
- Config: `ctp_facility_constraints WHERE facility_type = 'airport'`
- Advisory if: Count >= max_acph for that airport

**3. FIR Capacity**
- Query: Count flights crossing each FIR in same hourly window
- Source: `ctp_flight_control` oceanic entry/exit FIR + timing
- Config: `ctp_facility_constraints WHERE facility_type = 'fir'`
- Advisory if: Any FIR at or over capacity

**4. Fix Throughput**
- Query: Count flights using constrained fix in same hourly window
- Source: `ctp_flight_control` oceanic entry/exit fixes
- Config: `ctp_facility_constraints WHERE facility_type = 'fix'`
- Advisory if: Any fix at or over limit

**5. Sector Capacity**
- Query: Count flights transiting sector in same hourly window
- Config: `ctp_facility_constraints WHERE facility_type = 'sector'`
- Advisory if: Sector at or over capacity

**6. ECFMP Regulations**
- Query: Any active `tmi_flow_measures` affecting flight's route/dest/FIRs
- Source: `tmi_flow_measures` (polled every 5min by ecfmp_poll_daemon)
- Advisory if: Active regulation affects this flight

### 3.2 Resolution Algorithm

```
function resolveSlot(flight, preferred_track, session):

    // All active tracks in the session; preferred_track sorted first
    eligible_tracks = [preferred_track] + getActiveTracksExcluding(session, preferred_track)
    candidates = []

    for each track in eligible_tracks:
        best_slot = getEarliestOpenSlot(track.program_id)
        if !best_slot: continue

        timing = computeTimingChain(flight, track, best_slot)

        advisories = []
        checkDestRate(flight.dest, timing.cta)         → append if over
        checkFIRCapacity(track, timing)                 → append if over
        checkFixThroughput(track, timing)               → append if over
        checkSectorCapacity(track, timing)              → append if over
        checkECFMP(flight, track, timing)               → append if active

        candidates[] = { track, slot, timing, advisories, advisory_count }

    // Rank: preferred track first, then by fewest advisories, then by earliest slot
    ranked = sort(candidates, by: [is_preferred DESC, advisory_count ASC, slot_time ASC])

    return {
        recommended: ranked[0],
        alternatives: ranked[1..5]
    }
```

## 4. Timing Chain Calculator

By the time a slot is requested, all three segment routes (NA, OCA, EU) are confirmed by flowcontrol stakeholders. PERTI computes ETEs once using the full sp_CalculateETA pipeline, then evaluates multiple candidate slots arithmetically.

### 4.1 Compute ETEs (once per distinct track route)

For each distinct track route (preferred + alternatives), compute segment ETEs:

```
Input from flowcontrol:
  callsign, origin, dest, aircraft_type,
  na_route, eu_route, tobt
  (oca_route comes from ctp_session_tracks.route_string per track)

For each distinct track:
  Full route = na_route + " " + track.route_string + " " + eu_route

  1. Update flight's route in adl_flight_plan with full amended route
  2. Trigger route parse via PostGIS (expand_route → waypoints + distances)
  3. Call sp_CalculateETA with departure_override = TOBT (seed estimate)
  4. Query adl_flight_waypoints for per-waypoint ETAs
  5. Extract segment ETEs from waypoint times:
     - na_ete  = waypoint_eta(oceanic_entry_fix) - departure_time
     - oca_ete = waypoint_eta(oceanic_exit_fix) - waypoint_eta(oceanic_entry_fix)
     - eu_ete  = arrival_eta - waypoint_eta(oceanic_exit_fix)
     - taxi_min from airport_taxi_reference for origin airport
  6. Cache {na_ete, oca_ete, eu_ete, taxi_min} keyed by track_name

Note: NA and EU segment ETEs are constant across tracks with the same entry/exit fixes.
Only the OCA segment ETE varies per track. Tracks sharing the same OCA route share
a cached result.
```

### 4.2 Evaluate Candidate Slots (arithmetic, no re-computation)

```
For each candidate slot_time on eligible tracks:
    ctot      = slot_time - na_ete - taxi_min
    off_time  = slot_time - na_ete
    oep_time  = slot_time                     (anchor)
    exit_time = slot_time + oca_ete
    cta       = slot_time + oca_ete + eu_ete

    → Run advisory checks at these computed times
    → Rank candidates
```

Segment ETEs don't change when the slot shifts — only the absolute times shift. One sp_CalculateETA call per distinct track supports evaluating all candidate slots on that track.

**OCA route source:** The `oca_route` is NOT sent in the request-slot call. It comes from `ctp_session_tracks.route_string` (pushed earlier via push-tracks). Flowcontrol only sends `na_route` and `eu_route` since those are flight-specific; the OCA route is track-specific and already stored.

### 4.4 Timing Chain Response

```json
{
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
  }
}
```

## 5. Slot Lifecycle & Freeze-on-Airborne

### 5.1 Status Model

| Status | Phase | Meaning |
|--------|-------|---------|
| NONE | — | No slot assigned yet |
| ASSIGNED | Ground | Has CTOT, awaiting departure |
| AT_RISK | Ground | Projected to miss CTOT by 5-15min |
| MISSED | Ground | >15min past CTOT, slot released, needs reassignment |
| FROZEN | Airborne | Departed — slot locked, no further changes |
| RELEASED | Any | Flight disconnected or manually released — slot freed |

### 5.2 Transitions

```
ASSIGNED ──departs──────> FROZEN
ASSIGNED ──misses───────> MISSED ──reassigned──> ASSIGNED
ASSIGNED ──disconnects──> RELEASED
ASSIGNED ──manual───────> RELEASED
FROZEN   ──disconnects──> RELEASED

RELEASED → tmi_slots.slot_status = 'OPEN'
         → available for compression backfill (sp_TMI_RunCompression)
```

### 5.3 Freeze-on-Airborne Rule

Once a flight departs (goes airborne), its slot assignment is **frozen**. No recalculation of slot_time, OEP time, or CTA. The slot is permanently consumed regardless of actual OEP arrival time.

**Rationale:** Prevents cascading recalculation if initial ETE estimates were imperfect. The slot is a planning tool — once the flight is airborne, the plan is committed.

### 5.4 Already-Airborne Flights

Flights already in the air when a slot is requested:
- Compute projected OEP from current position via sp_CalculateETA
- Assign nearest available slot at or after projected OEP
- Status immediately set to FROZEN (can't control departure)
- No CTOT issued — only slot reservation for accounting/metering

### 5.5 Disconnect Handling

Disconnect overrides freeze. If a pilot disconnects from VATSIM, their slot is released regardless of flight phase.

**Detection:** ADL daemon already detects disconnects (flight disappears from VATSIM datafeed). Add CTP-aware check to the daemon cycle:

```
For each active CTP session:
  For each flight WHERE slot_status IN ('ASSIGNED', 'FROZEN'):
    If flight no longer active in adl_flight_core:
      → slot_status = 'RELEASED'
      → tmi_slots.slot_status = 'OPEN'
      → Log SLOT_RELEASED/DISCONNECT to ctp_audit_log
```

**Reconnect:** Treated as new flight. Can request a new slot — no automatic restoration.

### 5.6 Missed Slot Detection

Ground-only. Runs in ADL daemon cycle (60s):

```
For each active CTP session:
  For each flight WHERE slot_status = 'ASSIGNED':
    Compute projected_departure = NOW + remaining_taxi (if pushing)
                                  or latest_gate_time (if at gate)
    Compute projected_oep = projected_departure + na_ete

    If projected_oep > slot_time + 15min:  → MISSED
    If projected_oep > slot_time + 5min:   → AT_RISK
```

MISSED flights: original slot released (OPEN for compression), flight needs manual reassignment via new request-slot call.

## 6. SWIM API Endpoints

Six new endpoints under `/api/swim/v1/ctp/`. All require SWIM API key with CTP authority.

**Session identification:** All endpoints accept `session_name` (string, e.g., "CTPE26") or `session_id` (int). The API resolves session name to ID internally via `ctp_sessions.session_name`.

### 6.1 `POST /api/swim/v1/ctp/request-slot`

Returns ranked candidates with advisory status. No assignment — coordinator reviews and calls confirm-slot.

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

**Response:**
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
        "cruise_speed_kts": 487
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
        "timing_chain": { "..." : "..." },
        "advisories": [],
        "advisory_count": 0
      }
    ]
  }
}
```

**Airborne variant:** If `is_airborne: true`, the response omits `ctot_utc` (can't control departure) and the recommended slot is based on projected OEP from current position.

### 6.2 `POST /api/swim/v1/ctp/confirm-slot`

Coordinator picks a candidate. PERTI assigns the slot and runs the 9-step CTOT recalculation cascade.

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "track": "B",
  "slot_time_utc": "2026-10-15T20:15:00Z"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "ASSIGNED",
    "ctot_utc": "2026-10-15T18:48:00Z",
    "cta_utc": "2026-10-16T02:32:00Z",
    "slot_id": 48291,
    "cascade_status": "completed"
  }
}
```

If the flight is airborne, status = "FROZEN" and ctot_utc is omitted.

**Internals:** Calls `CTOTCascade::apply()` (extracted from ctot.php) to run the full 9-step cascade: tmi_flight_control → adl_flight_times → sp_CalculateETA → waypoint ETAs → boundary crossings → swim_flights → rad_amendments → adl_flight_tmi → ctp_flight_control.

### 6.3 `POST /api/swim/v1/ctp/release-slot`

Cancel a slot assignment. Cannot release a FROZEN slot unless reason is DISCONNECT.

**Request:**
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "reason": "COORDINATOR_RELEASE"
}
```

**Response:**
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

Valid reasons: `COORDINATOR_RELEASE`, `DISCONNECT`, `MISSED_REASSIGN`.

### 6.4 `POST /api/swim/v1/ctp/push-tracks`

Flowcontrol pushes track definitions to PERTI.

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
      "is_active": true
    },
    {
      "track_name": "B",
      "route_string": "JOOPY 50N050W 51N040W 51N030W 51N020W LIMRI",
      "oceanic_entry_fix": "JOOPY",
      "oceanic_exit_fix": "LIMRI",
      "is_active": true
    }
  ]
}
```

**Response:**
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

Idempotent: re-pushing same track updates it. Tracks not in the push are left unchanged (not deleted).

### 6.5 `POST /api/swim/v1/ctp/push-constraints`

Flowcontrol pushes facility constraint parameters. Pushed periodically.

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

**Response:**
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

Idempotent: re-pushing same facility updates max_acph. Constraints not in the push are left unchanged.

### 6.6 `GET /api/swim/v1/ctp/session-status`

Current session state for flowcontrol dashboard polling.

**Request:** `GET ?session_id=CTPE26`

**Response:**
```json
{
  "success": true,
  "data": {
    "session_name": "CTPE26",
    "status": "ACTIVE",
    "tracks": [
      {
        "track_name": "A",
        "total_slots": 60,
        "assigned": 34,
        "frozen": 12,
        "open": 14,
        "utilization_pct": 76.7
      }
    ],
    "constraint_status": {
      "over_rate": [
        {"facility": "EGLL", "facility_type": "airport", "current": 14, "limit": 12}
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

## 7. Database Schema Changes

### 7.1 New Table: `ctp_session_tracks` (VATSIM_TMI)

```sql
CREATE TABLE ctp_session_tracks (
    session_track_id  INT IDENTITY(1,1) PRIMARY KEY,
    session_id        INT NOT NULL REFERENCES ctp_sessions(session_id),
    program_id        INT NULL REFERENCES tmi_programs(program_id),
    track_name        VARCHAR(16) NOT NULL,
    route_string      NVARCHAR(MAX) NOT NULL,
    oceanic_entry_fix VARCHAR(32) NOT NULL,
    oceanic_exit_fix  VARCHAR(32) NOT NULL,
    max_acph          INT NOT NULL DEFAULT 10,
    is_active         BIT NOT NULL DEFAULT 1,
    pushed_at         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    created_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT UQ_session_track UNIQUE (session_id, track_name)
);
```

### 7.2 New Table: `ctp_facility_constraints` (VATSIM_TMI)

```sql
CREATE TABLE ctp_facility_constraints (
    constraint_id   INT IDENTITY(1,1) PRIMARY KEY,
    session_id      INT NOT NULL REFERENCES ctp_sessions(session_id),
    facility_name   VARCHAR(32) NOT NULL,
    facility_type   VARCHAR(16) NOT NULL,
    max_acph        INT NOT NULL,
    effective_start DATETIME2(0) NULL,
    effective_end   DATETIME2(0) NULL,
    pushed_at       DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    source          VARCHAR(32) NOT NULL DEFAULT 'flowcontrol',
    created_at      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_facility_type CHECK (facility_type IN ('airport', 'fir', 'fix', 'sector')),
    CONSTRAINT UQ_session_facility UNIQUE (session_id, facility_name, facility_type)
);
```

### 7.3 ALTER: `tmi_programs` CHECK Constraint

```sql
ALTER TABLE tmi_programs DROP CONSTRAINT CK_tmi_programs_program_type;
ALTER TABLE tmi_programs ADD CONSTRAINT CK_tmi_programs_program_type
    CHECK (program_type IN ('GS','GDP-DAS','GDP-GAAP','GDP-UDP','AFP','BLANKET','COMPRESSION','CTP'));
```

### 7.4 ALTER: `ctp_flight_control` (new columns)

```sql
ALTER TABLE ctp_flight_control ADD
    slot_status         VARCHAR(16) NOT NULL DEFAULT 'NONE',
    slot_id             INT NULL REFERENCES tmi_slots(slot_id),
    projected_oep_utc   DATETIME2(0) NULL,
    is_airborne         BIT NOT NULL DEFAULT 0,
    miss_reason         VARCHAR(32) NULL,
    reassignment_count  INT NOT NULL DEFAULT 0;

ALTER TABLE ctp_flight_control ADD CONSTRAINT CK_ctp_slot_status
    CHECK (slot_status IN ('NONE','ASSIGNED','AT_RISK','MISSED','FROZEN','RELEASED'));
```

### 7.5 ALTER: `ctp_sessions` (new columns)

```sql
ALTER TABLE ctp_sessions ADD
    slot_generation_status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    activation_checklist_json NVARCHAR(MAX) NULL;

ALTER TABLE ctp_sessions ADD CONSTRAINT CK_slot_gen_status
    CHECK (slot_generation_status IN ('PENDING','GENERATING','READY','ERROR'));
```

### 7.6 ALTER: `ctp_audit_log` (new action types)

Existing `action_type` column — add these values to application code (no CHECK constraint exists):
- `SLOT_ASSIGNED` — slot confirmed for flight
- `SLOT_RELEASED` — slot manually released or disconnect
- `SLOT_FROZEN` — flight departed, slot locked
- `SLOT_MISSED` — flight missed CTOT threshold

## 8. Shared Services

### 8.1 `CTOTCascade.php` (new, extracted from ctot.php)

The 9-step CTOT recalculation cascade currently lives inline in `api/swim/v1/ingest/ctot.php`. Extract to `load/services/CTOTCascade.php` so both endpoints can use it:

```php
class CTOTCascade {
    public static function apply(
        int $flight_uid,
        string $ctot_utc,
        ?string $route_string = null,
        ?string $track = null,
        ?string $program_name = null,
        ?int $program_id = null
    ): array {
        // Step 1: tmi_flight_control (VATSIM_TMI)
        // Step 2: adl_flight_times (VATSIM_ADL)
        // Step 3: sp_CalculateETA with @departure_override (VATSIM_ADL)
        // Step 4: Waypoint ETA recalc (VATSIM_ADL)
        // Step 5: Boundary crossing recalc (VATSIM_GIS)
        // Step 6: swim_flights push (SWIM_API)
        // Step 7: rad_amendments if route (VATSIM_TMI)
        // Step 8: adl_flight_tmi sync (VATSIM_ADL)
        // Step 9: ctp_flight_control update (VATSIM_TMI)

        return ['status' => 'completed', 'steps_run' => 9];
    }
}
```

Called by:
- `api/swim/v1/ingest/ctot.php` (existing external CTOT push — refactored to use shared service)
- `api/swim/v1/ctp/confirm-slot` (new internal slot assignment)

### 8.2 `CTPSlotEngine.php` (new)

Core slot engine service:

```php
class CTPSlotEngine {
    public function requestSlot(array $params): array;      // → ranked candidates
    public function confirmSlot(array $params): array;      // → assign + cascade
    public function releaseSlot(array $params): array;      // → free slot
    public function generateSlotGrid(int $sessionId): void; // → create tmi_programs + slots per track
    public function checkConstraints(array $timing, int $sessionId): array; // → advisory list
    public function computeTimingChain(array $flight, array $track, string $slotTime): array;
}
```

### 8.3 `CTPConstraintAdvisor.php` (new)

Constraint checking logic, separated from slot engine for testability:

```php
class CTPConstraintAdvisor {
    public function evaluate(int $sessionId, string $dest, array $timing): array;
    public function checkDestRate(int $sessionId, string $airport, string $ctaUtc): ?array;
    public function checkFIRCapacity(int $sessionId, string $fir, string $startUtc, string $endUtc): ?array;
    public function checkFixThroughput(int $sessionId, string $fix, string $transitUtc): ?array;
    public function checkSectorCapacity(int $sessionId, string $sector, string $transitUtc): ?array;
    public function checkECFMP(string $dest, array $firs): ?array;
}
```

## 9. Integration with Existing Infrastructure

### 9.1 GDP Slot Engine (direct reuse)

| Component | CTP Usage |
|-----------|-----------|
| `sp_TMI_GenerateSlots` | Generates per-track slot grids at session activation |
| `sp_TMI_RunCompression` | Backfills freed slots (disconnect, missed, manual release) |
| `tmi_programs` | One entry per track (program_type='CTP') |
| `tmi_slots` | Slot storage — same table, same status lifecycle |
| `tmi_flight_control` | Per-flight TMI control record |

**Not reused** from GDP:
- `sp_TMI_AssignFlightsFPFS` — CTP uses on-demand assignment, not batch FPFS
- `sp_TMI_ReoptimizeProgram` — No periodic re-optimization; slots assigned on request
- `sp_TMI_AdjustReserves` — No reserve slots for CTP (all slots available from start)

### 9.2 ADL Daemon Extension

Add CTP-aware checks to `executeDeferredTMISync()` in `scripts/vatsim_adl_daemon.php` (60s cycle):

1. **Disconnect detection:** For each active CTP session, check assigned/frozen flights against adl_flight_core. If disconnected → release slot.
2. **Miss detection:** For each active CTP session, check assigned ground flights. If projected OEP > slot_time + 15min → MISSED. If +5min → AT_RISK.
3. **Airborne detection:** For each assigned ground flight, check if phase changed to EN_ROUTE/CLIMB → FROZEN.

### 9.3 SWIM WebSocket Events (new)

Add CTP event types to swim_ws_server event bus via `/tmp/swim_ws_events.json`:

```json
{"type": "ctp_slot_assigned", "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "slot_time": "2026-10-15T20:15:00Z", "ctot": "2026-10-15T18:48:00Z", "cta": "2026-10-16T02:32:00Z"}
{"type": "ctp_slot_released", "session_name": "CTPE26", "callsign": "BAW117", "track": "B", "reason": "DISCONNECT"}
{"type": "ctp_slot_frozen", "session_name": "CTPE26", "callsign": "BAW117", "track": "B"}
{"type": "ctp_slot_missed", "session_name": "CTPE26", "callsign": "BAW117", "track": "B"}
```

Flowcontrol can subscribe for real-time updates instead of polling session-status.

### 9.4 CTP UI (ctp.php) — Read-Only Constraint Display

Add a read-only panel to ctp.php showing pushed constraints:

| Facility | Type | Max ACPH | Current | Status | Last Pushed |
|----------|------|----------|---------|--------|-------------|
| EGLL | airport | 12 | 14 | OVER | 2 min ago |
| EGGX | fir | 25 | 18 | OK | 2 min ago |
| MALOT | fix | 6 | 6 | AT LIMIT | 2 min ago |

Plus track slot utilization overview (similar to session-status response).

### 9.5 What's NOT Touched

- `gdt.php` / `gdt.js` — GDT stays GDP-only
- `tmi_advisories` — CTP doesn't publish NTML advisories
- `EDCTDelivery.php` — CTP CTOTs delivered via SWIM API response; flowcontrol handles pilot communication
- `tmi_reroutes` / `tmi_public_routes` — CTP route amendments managed in ctp_flight_control, not the TMI reroute system

## 10. Error Handling

| Scenario | Response |
|----------|----------|
| Session not found | 404, code: `SESSION_NOT_FOUND` |
| Session not ACTIVE | 409, code: `SESSION_NOT_ACTIVE` |
| Flight not in ADL | 404, code: `FLIGHT_NOT_FOUND` |
| No tracks configured | 409, code: `NO_TRACKS_CONFIGURED` |
| Slot grid not generated | 409, code: `SLOTS_NOT_READY` |
| No open slots on any track | 200, recommended: null, alternatives: [] |
| Slot already taken (race condition) | 409, code: `SLOT_TAKEN` — retry with request-slot |
| Cannot release FROZEN slot | 409, code: `SLOT_FROZEN` (unless reason=DISCONNECT) |
| sp_CalculateETA failure | 500, code: `ETA_COMPUTATION_FAILED` — fallback to distance/speed estimate |
| Constraint push for inactive session | 409, code: `SESSION_NOT_ACTIVE` |

## 11. File Inventory

### New Files

| File | Purpose |
|------|---------|
| `load/services/CTOTCascade.php` | Shared 9-step recalc cascade |
| `load/services/CTPSlotEngine.php` | Core slot engine service |
| `load/services/CTPConstraintAdvisor.php` | Constraint checking logic |
| `api/swim/v1/ctp/request-slot.php` | Request ranked slot candidates |
| `api/swim/v1/ctp/confirm-slot.php` | Confirm slot assignment |
| `api/swim/v1/ctp/release-slot.php` | Release slot |
| `api/swim/v1/ctp/push-tracks.php` | Receive track definitions |
| `api/swim/v1/ctp/push-constraints.php` | Receive facility constraints |
| `api/swim/v1/ctp/session-status.php` | Session state query |
| `database/migrations/tmi/060_ctp_slot_engine.sql` | Schema changes (all in one migration) |

### Modified Files

| File | Change |
|------|--------|
| `api/swim/v1/ingest/ctot.php` | Refactor to use CTOTCascade.php |
| `scripts/vatsim_adl_daemon.php` | Add CTP disconnect/miss/airborne detection |
| `ctp.php` | Add read-only constraint display panel |
| `assets/js/ctp.js` | Add constraint display, slot status indicators |
| `scripts/swim_ws_server.php` | No change needed — generic event bus, CTP events written to event file by new endpoints |
