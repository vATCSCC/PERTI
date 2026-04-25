# CTP Slot Program Runbook

Operational guide for creating, configuring, and running a CTP (Cross the Pond) oceanic slot program in PERTI. Covers both internal PERTI operations and external stakeholder integration (FlowControl, Nattrak, SWIM consumers).

---

## Table of Contents

1. [Overview](#1-overview)
2. [Prerequisites](#2-prerequisites)
3. [Phase 1: Session Creation (PERTI)](#3-phase-1-session-creation-perti)
4. [Phase 2: Booking Sync (Automated)](#4-phase-2-booking-sync-automated)
5. [Phase 3: Track & Constraint Configuration (FlowControl)](#5-phase-3-track--constraint-configuration-flowcontrol)
6. [Phase 4: Slot Generation (PERTI / FlowControl)](#6-phase-4-slot-generation-perti--flowcontrol)
7. [Phase 5: Session Activation (PERTI)](#7-phase-5-session-activation-perti)
8. [Phase 6: Flight Detection (PERTI)](#8-phase-6-flight-detection-perti)
9. [Phase 7: Slot Assignment (FlowControl)](#9-phase-7-slot-assignment-flowcontrol)
10. [Phase 8: Monitoring & Operations](#10-phase-8-monitoring--operations)
11. [Phase 9: Session Completion](#11-phase-9-session-completion)
12. [Troubleshooting](#12-troubleshooting)
13. [API Quick Reference](#13-api-quick-reference)
14. [Database Reference](#14-database-reference)

---

## 1. Overview

### Architecture

```
Nattrak (Bookings)  ──►  PERTI ADL Daemon  ──►  ctp_event_bookings
                                                        │
PERTI UI (ctp.php)  ──►  Internal API  ──►  ctp_sessions ◄── SWIM API ◄���─ FlowControl
                                                │
                                      ctp_session_tracks ──► tmi_programs ──► tmi_slots
                                                │
                                      ctp_facility_constraints
                                                │
                              ctp_flight_control ◄──── detect.php (flight discovery)
                                      │
                              CTPSlotEngine  ──► CTOTCascade  ──► EDCT delivery
                                      │
                              WebSocket  ──►  SWIM consumers
```

### Key Databases

| Database | Tables | Purpose |
|----------|--------|---------|
| VATSIM_TMI | `ctp_sessions`, `ctp_session_tracks`, `ctp_flight_control`, `ctp_facility_constraints`, `ctp_audit_log`, `tmi_programs`, `tmi_slots`, `tmi_flight_control` | Session management, slot grid, flight control |
| VATSIM_ADL | `ctp_event_bookings`, `adl_flight_tmi`, `adl_flight_times`, `adl_flight_core` | Booking data, flight tagging, EDCT propagation |
| SWIM_API | `swim_flights` | Public flight data with EDCT |

### Actors

| Actor | Role | Interface |
|-------|------|-----------|
| **PERTI Admin** | Creates session, activates, monitors | CTP UI (`ctp.php`), Internal API (`/api/ctp/`) |
| **FlowControl (FC)** | Pushes tracks/constraints, assigns slots | SWIM API (`/api/swim/v1/ctp/`) |
| **ADL Daemon** | Syncs bookings, tags flights | Automated (60s/60min cycles) |
| **SWIM Consumers** | Receive slot assignments via WebSocket | WebSocket + REST API |

---

## 2. Prerequisites

### Configuration (Azure App Settings)

Set these in Azure App Service configuration **before** the event:

| Setting | Example | Purpose |
|---------|---------|---------|
| `CTP_EVENT_CODE` | `CTPE26` | Event identifier for booking matching |
| `CTP_API_URL` | `https://nattrak.vatsim.net` | Nattrak API base URL |
| `CTP_API_KEY` | `(secret)` | Nattrak API key for booking import |
| `CTP_SESSION_ID` | `1` | Active CTP session ID (set after creation) |
| `CTP_NATTRAK_EVENT_ID` | `1` | Nattrak event ID for booking endpoint (defaults to `1` if not set) |

### SWIM API Key

FlowControl needs a SWIM API key with **CTP write authority**:
- Key must have `canWriteField('ctp')` permission
- Managed in `swim_api_keys` table
- Auth header: `Authorization: Bearer <key>` or `X-API-Key: <key>`

### System Requirements

- All 25 daemons running (not in hibernation mode)
- ADL daemon processing flights (`vatsim_adl_daemon.php`)
- SWIM WebSocket server running (`swim_ws_server.php`)
- PostGIS available for route distance computation and boundary crossings

---

## 3. Phase 1: Session Creation (PERTI)

**Who**: PERTI Admin
**When**: Days/weeks before the event

### Via CTP UI

Navigate to `https://perti.vatcscc.org/ctp.php` and use the session creation form.

### Via Internal API

```http
POST /api/ctp/sessions/create.php
Content-Type: application/json

{
  "session_name": "CTPE26",
  "direction": "WESTBOUND",
  "constraint_window_start": "2026-10-25T11:00:00Z",
  "constraint_window_end": "2026-10-25T20:00:00Z",
  "slot_interval_min": 5,
  "constrained_firs": ["CZQX", "BIRD", "EGGX", "LPPO"],
  "managing_orgs": ["VATNA", "VATEUR"]
}
```

**Response**: `201 Created` with `session_id`

### Key Decisions

| Parameter | Guidance |
|-----------|----------|
| `direction` | `WESTBOUND` (EU→NA), `EASTBOUND` (NA→EU), or `BOTH` |
| `constraint_window_start/end` | The oceanic entry time window — NOT departure times |
| `slot_interval_min` | 5 min is standard; 3 min for high-density events |
| `constrained_firs` | Oceanic FIRs where spacing is enforced (CZQX = Gander, BIRD = Reykjavik, EGGX = Shanwick, LPPO = Santa Maria) |

### Post-Creation

1. Note the `session_id` — needed for all subsequent steps
2. Set `CTP_SESSION_ID` Azure App Setting to this value
3. Session starts in `DRAFT` status

---

## 4. Phase 2: Booking Sync (Automated)

**Who**: ADL Daemon (automated)
**When**: Starts automatically once `CTP_EVENT_CODE` + `CTP_API_KEY` are configured

### How It Works

The ADL daemon calls `syncNattrakBookings()` every 60 minutes:

1. Fetches CSV from Nattrak API: `GET /api/events/{id}/bookings/import/nattrak`
2. Parses booking data (CID, departure, arrival, track, route, takeoff time, FL)
3. Upserts into `dbo.ctp_event_bookings` (VATSIM_ADL)

### Flight Matching

`executeCTPBookingMatch()` runs every 60 seconds in the ADL daemon:

1. Matches active flights to bookings by CID + departure + arrival airports
2. Tags matched flights with `flow_event_code` in `adl_flight_tmi`
3. Updates `matched_flight_uid` and `matched_at` in bookings table

### Verification

```sql
-- Check booking sync status (VATSIM_ADL)
SELECT COUNT(*) AS total_bookings,
       SUM(CASE WHEN matched_flight_uid IS NOT NULL THEN 1 ELSE 0 END) AS matched
FROM dbo.ctp_event_bookings
WHERE event_code = 'CTPE26';
```

---

## 5. Phase 3: Track & Constraint Configuration (FlowControl)

**Who**: FlowControl (external) via SWIM API
**When**: Hours before the event, after NAT tracks are published

### Step 3a: Push NAT Tracks

FlowControl pushes the day's NAT track definitions:

```http
POST /api/swim/v1/ctp/push-tracks.php
Authorization: Bearer <swim_api_key>
Content-Type: application/json

{
  "session_name": "CTPE26",
  "tracks": [
    {
      "track_name": "A",
      "route_string": "PIKIL 5530N 5620N 5710N 5750N LIMRI XETBO",
      "oceanic_entry_fix": "PIKIL",
      "oceanic_exit_fix": "XETBO",
      "max_acph": 12,
      "is_active": true
    },
    {
      "track_name": "B",
      "route_string": "DOTTY 5430N 5520N 5610N 5650N MALOT GISTI",
      "oceanic_entry_fix": "DOTTY",
      "oceanic_exit_fix": "GISTI",
      "max_acph": 10,
      "is_active": true
    },
    {
      "track_name": "RR",
      "route_string": "RANDOM",
      "oceanic_entry_fix": "VARIOUS",
      "oceanic_exit_fix": "VARIOUS",
      "max_acph": 8,
      "is_active": true
    }
  ]
}
```

**Notes**:
- Include an `"RR"` (Random Route) track for flights not on named NAT tracks
- `route_distance_nm` is auto-computed from the route string if omitted
- Tracks can be updated by re-pushing with the same `track_name`
- This creates/updates rows in `ctp_session_tracks`

### Step 3b: Push Facility Constraints

FlowControl pushes capacity limits for airports, FIRs, and fixes:

```http
POST /api/swim/v1/ctp/push-constraints.php
Authorization: Bearer <swim_api_key>
Content-Type: application/json

{
  "session_name": "CTPE26",
  "constraints": [
    { "facility_name": "EGLL", "facility_type": "airport", "max_acph": 15 },
    { "facility_name": "KJFK", "facility_type": "airport", "max_acph": 12 },
    { "facility_name": "CZQX", "facility_type": "fir", "max_acph": 40 },
    { "facility_name": "EGGX", "facility_type": "fir", "max_acph": 35 },
    { "facility_name": "PIKIL", "facility_type": "fix", "max_acph": 15 },
    { "facility_name": "DOTTY", "facility_type": "fix", "max_acph": 12 }
  ]
}
```

**Notes**:
- Field aliases accepted: `facility` or `facility_name`, `maxAircraftPerHour` or `max_acph`
- Duplicate facility constraints are auto-aggregated (summed) — if FC sends two constraints for the same fix from different tracks, PERTI sums the `max_acph` values
- Constraints are advisory (WARN severity) — they don't block slot assignment but flag advisories
- Valid `facility_type` values: `airport`, `fir`, `fix`, `sector`

### Alternative: PERTI UI

Tracks and constraints can also be configured via the CTP page:
- **Throughput configs**: `/api/ctp/throughput/create.php`
- **Track constraints**: `/api/ctp/planning/track_constraints.php`
- **Planning scenarios**: `/api/ctp/planning/` endpoints for block-based planning

---

## 6. Phase 4: Slot Generation (PERTI / FlowControl)

**Who**: FlowControl or PERTI Admin
**When**: After tracks are pushed, before session activation

### Generate Slot Grid

```http
POST /api/swim/v1/ctp/generate-slots.php
Authorization: Bearer <swim_api_key>
Content-Type: application/json

{
  "session_name": "CTPE26"
}
```

### What Happens

For each active track in `ctp_session_tracks`:

1. Creates a `tmi_programs` row (type=`CTP`, rate=`max_acph`, window=session constraint window)
2. Links `ctp_session_tracks.program_id` → new `tmi_programs.program_id`
3. Calls `sp_TMI_GenerateSlots @program_id` to create the slot grid in `tmi_slots`
4. Updates `ctp_sessions.slot_generation_status` to `READY`

### Verification

```http
GET /api/swim/v1/ctp/session-status.php?session_name=CTPE26
```

Response includes per-track slot counts:
```json
{
  "tracks": [
    { "track_name": "A", "total_slots": 108, "assigned": 0, "open": 108 },
    { "track_name": "B", "total_slots": 90, "assigned": 0, "open": 90 }
  ]
}
```

### Slot Grid Math

- Slots = `(constraint_window_end - constraint_window_start) / slot_interval_min`
- Example: 9-hour window with 5-min intervals = **108 slots per track**
- Each slot has a `slot_time_utc` representing the oceanic entry point (OEP) time

---

## 7. Phase 5: Session Activation (PERTI)

**Who**: PERTI Admin
**When**: After tracks, constraints, and slots are configured

### Activate

```http
POST /api/ctp/sessions/activate.php
Content-Type: application/json

{
  "session_id": 1
}
```

**Prerequisites checked**:
- Session must have `constrained_firs` configured
- Session must be in `DRAFT` or `MONITORING` status

**Result**: Session moves to `ACTIVE` status, SWIM event `ctp.session.activated` broadcast.

### Activation Checklist

Before activating, verify:

- [ ] All NAT tracks pushed and visible in session-status
- [ ] Slot grid generated (`slot_generation_status = 'READY'`)
- [ ] Facility constraints pushed
- [ ] Nattrak booking sync running (bookings appearing in `ctp_event_bookings`)
- [ ] `CTP_SESSION_ID` Azure App Setting matches session_id
- [ ] SWIM API key issued to FlowControl

---

## 8. Phase 6: Flight Detection (PERTI)

**Who**: PERTI Admin (triggered manually, can be repeated)
**When**: Starting ~2 hours before constraint window, repeat periodically

### Detect Oceanic Flights

```http
POST /api/ctp/flights/detect.php
Content-Type: application/json

{
  "session_id": 1,
  "include_event_flights": false
}
```

### What Happens

1. Queries `adl_flight_planned_crossings` for flights crossing constrained FIRs within the time window
2. Filters out already-tracked flights and event participants (if excluded)
3. Creates `ctp_flight_control` rows for each new flight
4. Resolves NAT tracks from filed routes using `NATTrackResolver`
5. Populates oceanic entry/exit FIR, fix, and timing data

### Repeat Detection

Run detection periodically as new flights file:
- **T-2h**: First detection sweep
- **T-1h**: Second sweep to catch late filers
- **T-30min**: Final sweep
- **During event**: Run as needed for newly connected flights

---

## 9. Phase 7: Slot Assignment (FlowControl)

**Who**: FlowControl (external) via SWIM API
**When**: During the active event

### Step 7a: Request Slot Candidates

FlowControl requests ranked slot candidates for a flight:

```http
POST /api/swim/v1/ctp/request-slot.php
Authorization: Bearer <swim_api_key>
Content-Type: application/json

{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "origin": "EGLL",
  "destination": "KJFK",
  "aircraft_type": "B77W",
  "track": "A",
  "tobt": "2026-10-25T10:30:00Z"
}
```

**Response** (ranked candidates):
```json
{
  "recommended": {
    "track": "A",
    "slot_time_utc": "2026-10-25T12:15:00Z",
    "slot_id": 4523,
    "timing_chain": {
      "ctot_utc": "2026-10-25T10:42:00Z",
      "off_utc": "2026-10-25T10:52:00Z",
      "oep_utc": "2026-10-25T12:15:00Z",
      "exit_utc": "2026-10-25T16:42:00Z",
      "cta_utc": "2026-10-25T18:30:00Z",
      "taxi_min": 10,
      "na_ete_min": 83,
      "oca_ete_min": 267,
      "eu_ete_min": 108,
      "total_ete_min": 458,
      "cruise_speed_kts": 487,
      "oceanic_entry_fix": "PIKIL",
      "oceanic_exit_fix": "XETBO"
    },
    "advisories": [],
    "advisory_count": 0
  },
  "alternatives": [
    {
      "track": "B",
      "slot_time_utc": "2026-10-25T12:20:00Z",
      "slot_id": 4601,
      "timing_chain": {
        "ctot_utc": "2026-10-25T10:47:00Z",
        "oep_utc": "2026-10-25T12:20:00Z",
        "cta_utc": "2026-10-25T18:35:00Z"
      },
      "advisories": [{ "type": "FIR_CAPACITY", "facility": "EGGX", "detail": "35/35 flights in FIR", "severity": "WARN", "current": 35, "limit": 35 }],
      "advisory_count": 1
    }
  ]
}
```

**Key parameters**:
- `track`: If specified, only evaluates that track (fast, single-track mode). If omitted, evaluates all tracks (slower, multi-track mode).
- `entry_fix` / `exit_fix`: For random routes — specifies the oceanic entry/exit fixes
- `tobt`: Target Off-Block Time — used to compute CTOT and timing chain

### Step 7b: Confirm Slot

FlowControl confirms the chosen slot:

```http
POST /api/swim/v1/ctp/confirm-slot.php
Authorization: Bearer <swim_api_key>
Content-Type: application/json

{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "track": "A",
  "slot_time_utc": "2026-10-25T12:15:00Z",
  "tobt": "2026-10-25T10:30:00Z",
  "na_route": "EGLL SID PIKIL",
  "eu_route": "XETBO STAR KJFK"
}
```

**What happens**:
1. Atomically claims the slot (UPDATE tmi_slots with OUTPUT)
2. Computes timing chain: CTOT → OEP → oceanic transit → exit → CTA
3. Runs CTOT cascade (Steps 1-2, 6-9; skips expensive Steps 3-5 for CTP)
4. Updates `ctp_flight_control` with slot assignment
5. Broadcasts `ctp_slot_assigned` WebSocket event

**Response**:
```json
{
  "status": "ASSIGNED",
  "ctot_utc": "2026-10-25T10:42:00Z",
  "cta_utc": "2026-10-25T18:30:00Z",
  "slot_id": 4523,
  "cascade_status": "complete"
}
```

### Step 7c: Release Slot (if needed)

```http
POST /api/swim/v1/ctp/release-slot.php
Authorization: Bearer <swim_api_key>
Content-Type: application/json

{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "reason": "COORDINATOR_RELEASE"
}
```

**Valid reasons**: `COORDINATOR_RELEASE`, `DISCONNECT`, `MISSED_REASSIGN`, `FORCE`

**Force release** (for frozen/airborne slots):
```json
{
  "session_name": "CTPE26",
  "callsign": "BAW117",
  "reason": "FORCE",
  "force": true
}
```

Release reverses the full CTOT cascade — clears EDCT from `tmi_flight_control`, `adl_flight_times`, `swim_flights`, and `adl_flight_tmi`.

---

## 10. Phase 8: Monitoring & Operations

### Session Status Dashboard

```http
GET /api/swim/v1/ctp/session-status.php?session_name=CTPE26
```

Returns:
- Per-track utilization (total/assigned/open/frozen slots)
- Constraint compliance (current vs. limit for airports/FIRs/fixes)
- Flight status breakdown (total/matched/assigned/pending/frozen)

### CTP UI

`https://perti.vatcscc.org/ctp.php` — MapLibre-based operational display:
- Flight positions on oceanic map
- Track overlays with color-coded utilization
- Real-time slot assignment/release events via WebSocket
- Compliance tracking (early/on-time/late/no-show)

### WebSocket Events

SWIM WebSocket (`wss://perti.vatcscc.org/api/swim/v1/ws/`) broadcasts:
- `ctp_slot_assigned` — Flight assigned to slot
- `ctp_slot_released` — Slot released
- `ctp.session.activated` — Session activated
- `ctp.session.completed` — Session completed/cancelled

### Key Monitoring Queries

```sql
-- Track utilization (VATSIM_TMI)
SELECT t.track_name,
       COUNT(s.slot_id) AS total_slots,
       SUM(CASE WHEN s.slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
       SUM(CASE WHEN s.slot_status = 'OPEN' THEN 1 ELSE 0 END) AS [open]
FROM dbo.ctp_session_tracks t
JOIN dbo.tmi_slots s ON s.program_id = t.program_id
WHERE t.session_id = 1
GROUP BY t.track_name;

-- Flight compliance summary (VATSIM_TMI)
SELECT slot_status, COUNT(*) AS cnt
FROM dbo.ctp_flight_control
WHERE session_id = 1
GROUP BY slot_status;

-- Constraint check: destination arrivals per hour (VATSIM_TMI)
SELECT fc.arr_airport, COUNT(*) AS arrivals_this_hour
FROM dbo.ctp_flight_control fc
JOIN dbo.tmi_flight_control tc ON tc.flight_uid = fc.flight_uid
WHERE fc.session_id = 1 AND fc.slot_status IN ('ASSIGNED','FROZEN')
  AND tc.cta_utc BETWEEN DATEADD(HOUR, -1, SYSUTCDATETIME()) AND SYSUTCDATETIME()
GROUP BY fc.arr_airport;
```

---

## 11. Phase 9: Session Completion

**Who**: PERTI Admin
**When**: After the constraint window closes and all flights are handled

```http
POST /api/ctp/sessions/complete.php
Content-Type: application/json

{
  "session_id": 1,
  "status": "COMPLETED"
}
```

**What happens**:
1. Calculates final statistics (total, slotted, modified, excluded flights)
2. Updates session status to `COMPLETED`
3. Broadcasts `ctp.session.completed` SWIM event
4. Writes final audit log entry

### Post-Event Cleanup

1. Clear `CTP_EVENT_CODE` Azure App Setting (stops booking sync)
2. Clear `CTP_SESSION_ID` Azure App Setting
3. Archive audit log if needed (`ctp_audit_log` table)
4. Run TMR (Traffic Management Review) if applicable

---

## 12. Troubleshooting

### Slot Assignment Fails

| Symptom | Cause | Fix |
|---------|-------|-----|
| `SLOT_TAKEN` | Another FC confirmed the same slot | Request new candidates |
| `FLIGHT_NOT_FOUND` | Flight not in `swim_flights` (not active) | Wait for ADL daemon to ingest the flight |
| `ETE_UNAVAILABLE` | No waypoint or track distance data | Ensure track has `route_distance_nm` set |
| `SESSION_NOT_ACTIVE` | Session still in DRAFT | Activate the session first |
| `NO_TRACKS_CONFIGURED` | No active tracks for session | Push tracks via push-tracks.php |

### Release Fails

| Symptom | Cause | Fix |
|---------|-------|-----|
| `NO_SLOT` | No active slot found for callsign | Check callsign spelling, verify in ctp_flight_control |
| `SLOT_FROZEN` | Airborne flight can't be released normally | Use `force: true` or `reason: "DISCONNECT"` |
| EDCT still showing after release | Old bug (pre-PR #340) | Deploy latest code — `CTOTCascade::reverse()` now clears all 4 databases |

### Booking Sync Not Working

1. Check `CTP_EVENT_CODE`, `CTP_API_KEY`, `CTP_API_URL` are all set
2. Check ADL daemon logs: `tail /home/LogFiles/vatsim_adl.log | grep -i nattrak`
3. Nattrak API returns JSON-wrapped CSV: `{"csv": "..."}` — parser handles both formats

### Slow Slot Requests

- **Multi-track mode** (no track specified): Evaluates all tracks (~38 queries after optimization). Takes 2-5s.
- **Single-track mode** (track specified): ~10 queries, <1s.
- Always specify `track` when FC knows the desired track.

---

## 13. API Quick Reference

### SWIM API (External — FlowControl)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/swim/v1/ctp/sessions.php` | GET | List sessions |
| `/api/swim/v1/ctp/session-status.php` | GET | Track utilization dashboard |
| `/api/swim/v1/ctp/push-tracks.php` | POST | Push NAT track definitions |
| `/api/swim/v1/ctp/push-constraints.php` | POST | Push facility constraints |
| `/api/swim/v1/ctp/generate-slots.php` | POST | Generate slot grid |
| `/api/swim/v1/ctp/request-slot.php` | POST | Get ranked slot candidates |
| `/api/swim/v1/ctp/confirm-slot.php` | POST | Confirm slot assignment |
| `/api/swim/v1/ctp/release-slot.php` | POST | Release slot assignment |

### Internal API (PERTI Admin)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/ctp/sessions/create.php` | POST | Create session |
| `/api/ctp/sessions/activate.php` | POST | Activate session |
| `/api/ctp/sessions/update.php` | POST | Update session config |
| `/api/ctp/sessions/complete.php` | POST | Complete/cancel session |
| `/api/ctp/flights/detect.php` | POST | Detect oceanic flights |
| `/api/ctp/throughput/create.php` | POST | Create throughput config |
| `/api/ctp/planning/track_constraints.php` | GET/POST/DELETE | Track constraint CRUD |
| `/api/ctp/planning/apply_to_session.php` | POST | Apply planning scenario |
| `/api/ctp/flights/assign_edct.php` | POST | Manual EDCT assignment |
| `/api/ctp/flights/remove_edct.php` | POST | Remove manual EDCT |
| `/api/ctp/flights/exclude.php` | POST | Exclude flight from CTP |

---

## 14. Database Reference

### Core Tables (VATSIM_TMI)

| Table | Key | Purpose |
|-------|-----|---------|
| `ctp_sessions` | `session_id` | Session configuration and status |
| `ctp_session_tracks` | `session_track_id` | Track definitions linked to tmi_programs |
| `ctp_flight_control` | `ctp_control_id` | Per-flight slot assignment and compliance |
| `ctp_facility_constraints` | `constraint_id` | Airport/FIR/fix capacity limits |
| `ctp_audit_log` | `log_id` | Immutable audit trail |
| `ctp_route_templates` | `template_id` | Oceanic route templates |
| `ctp_planning_track_constraints` | `constraint_id` | Planner-defined track caps |
| `tmi_programs` | `program_id` | One program per track (type='CTP') |
| `tmi_slots` | `slot_id` | Individual time slots per program |
| `tmi_flight_control` | `control_id` | EDCT/CTOT control records |

### Supporting Tables (VATSIM_ADL)

| Table | Key | Purpose |
|-------|-----|---------|
| `ctp_event_bookings` | auto | Nattrak booking data |
| `adl_flight_tmi` | `flight_uid` | Flight TMI control (flow_event_code tagging) |
| `adl_flight_times` | `flight_uid` | EDCT propagation (etd_utc, std_utc) |
| `adl_flight_planned_crossings` | auto | Boundary crossing predictions for detection |

### Session Status Flow

```
DRAFT  ──►  ACTIVE  ──►  MONITORING  ──►  COMPLETED
  │                                           ▲
  └──────────────────────────────────────►  CANCELLED
```

### Slot Status Flow (per flight)

```
NONE  ──►  ASSIGNED  ──►  FROZEN (airborne)
  ▲            │               │
  │            ▼               ▼
  └────── RELEASED ◄──────────┘
              │
              ▼
         AT_RISK ──► MISSED
```

### Key Relationships

```
ctp_sessions (1) ──► (N) ctp_session_tracks (1) ──► (1) tmi_programs (1) ──► (N) tmi_slots
ctp_sessions (1) ──► (N) ctp_flight_control (N) ──► (1) tmi_slots
ctp_sessions (1) ──► (N) ctp_facility_constraints
ctp_flight_control.flight_uid ──► adl_flight_core.flight_uid
ctp_flight_control.slot_id ──► tmi_slots.slot_id
```
