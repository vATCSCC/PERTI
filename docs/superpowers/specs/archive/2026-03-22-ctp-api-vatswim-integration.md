# CTP API ↔ VATSWIM Integration Specification

**Date**: 2026-03-22
**Status**: Draft — Pending Review
**Scope**: Integration between `vatsimnetwork/ctp-api` (.NET 10) and PERTI's VATSWIM API layer
**Prior Art**: `docs/CTP_EXTERNAL_REPO_AUDIT_AND_INTEGRATION.md` (2026-03-21)

---

## 1. Context

### 1.1 What Changed Since the March 21 Audit

The March 21 audit covered `vatsimnetwork/ctp-slot-planner` (React UI prototype) and `vatsimnetwork/ctp-simulator` (C# algorithm library). Since then, a **third repo** has appeared:

**`vatsimnetwork/ctp-api`** (`feature/database` branch, 8 commits, Mar 11-22)

This is an ASP.NET Core 10 Web API that wraps `ctp-simulator` as a git submodule and adds:
- PostgreSQL 16 persistence via Entity Framework Core (TPT inheritance)
- REST API for VATSIMEvents, Slots, ThroughputPoints
- API key authentication via external auth service
- Docker Compose deployment (api + postgres)
- CSV seed endpoint for loading test event data

### 1.2 CTP API Current State (Verified 2026-03-22)

**Entities** (from `ctp-simulator/DataStructures.cs`, persisted via EF Core migration `20260318183332_InitialCreate`):

| Entity | Table | Key Columns |
|--------|-------|-------------|
| `VATSIMEvent` | `VATSIMEvents` | Id, Title, Date, DepartureTimeWindow, RouteRevision, SlotRevision, CalculationParameters_* (owned) |
| `ThroughputPoint` (abstract) | `ThroughputPoints` | Id, Identifier, MaximumAircraftPerHour, MaximumSlots, SlotsAllocated, SimulationAnalysisStartTime |
| `Location : ThroughputPoint` | `Locations` | Latitude, Longitude, VATSIMEventId |
| `Airport : Location` | `Airports` | DepartureTimeWindowStart, NumberOfVotes, VATSIMEventId1 |
| `RouteSegment : ThroughputPoint` | `RouteSegments` | RouteString, RouteSegmentGroup, RouteSegmentTags (text[]), VATSIMEventId |
| `Sector : ThroughputPoint` | `Sectors` | Coordinates (JSONB), Min/MaxLatitude, Min/MaxLongitude, VATSIMEventId |
| `Slot` | `Slots` | DepartureTime, ProjectedArrivalTime, DepartureAirportId, ArrivalAirportId, ThroughputPointId, VATSIMEventId |

**Junction tables**: `RouteSegmentLocations`, `RouteSegmentSectors`, `SlotRouteSegments`

**What the Slot entity does NOT have** (verified against migration):
- No Callsign / CID / pilot identity
- No AircraftType / weight class
- No NATTrack / track resolution
- No ComplianceStatus
- No EDCT (separate from DepartureTime)
- No RouteStatus / modified route
- No audit trail

**API endpoints** (4 controllers):
- `GET/POST/PUT/DELETE /api/slots`
- `GET/POST/PUT/DELETE /api/VATSIMEvent` (eager-loads Airports, Waypoints, RouteSegments, Sectors)
- `GET/POST/PUT/DELETE /api/ThroughputPoints`
- `POST /api/seed` (CSV bulk load)

**Authentication**: `X-API-Key` header → HTTP call to external auth service at `AuthServiceURL`

### 1.3 PERTI CTP Infrastructure (Verified 2026-03-22)

PERTI has a **complete, production-deployed CTP system**:

**Database** (9 tables across VATSIM_TMI + SWIM_API):
- `ctp_sessions` — event sessions with org perspectives, constrained FIRs, slot parameters
- `ctp_flight_control` — per-flight: 3-segment routes, EDCT, compliance, NAT track, swim_push_version
- `ctp_audit_log` — action audit with JSON diffs
- `ctp_route_templates` — NAT tracks + route templates with origin/dest filters
- `ctp_track_throughput_config` — throughput constraints (tracks/origins/destinations/max_acph)
- `ctp_planning_scenarios` / `ctp_planning_traffic_blocks` / `ctp_planning_track_assignments` — planning simulator
- `ctp_track_constraints` — track-level constraints
- `swim_nat_track_metrics` — 15-min binned NAT track occupancy (SWIM_API)
- `swim_nat_track_throughput` — throughput utilization bins (SWIM_API)

**API** (35+ endpoints under `/api/ctp/`):
- Sessions: list, get, create, update, activate, complete (6)
- Flights: list, get, detect, validate_route, modify_route, assign_edct, assign_edct_batch, remove_edct, compliance, exclude, routes_geojson (11)
- Routes: suggest, templates (2)
- Throughput: list, create, update, delete, preview (5)
- Planning: scenarios, scenario_save/delete/clone, block_save/delete, assignment_save/delete, compute, apply_to_session, track_constraints (11)
- Reference: demand, stats, audit_log, changelog, boundaries (5)

**SWIM integration** (deployed, running):
- `swim_flights` columns: `resolved_nat_track`, `nat_track_resolved_at`, `nat_track_source`
- `swim_sync_ctp_to_swim()` — 2-min daemon cycle with 3 sub-steps (flight sync, metrics, throughput)
- `swim_nat_track_metrics` / `swim_nat_track_throughput` — pre-computed for external consumers
- `GET /api/swim/v1/tmi/nat_tracks/status` — live track snapshot + occupancy
- `GET /api/swim/v1/tmi/nat_tracks/metrics` — historical binned metrics
- WebSocket push for CTP events

**Services**:
- `NATTrackResolver.php` — hybrid token + sequence NAT track resolution
- `NATTrackFunctions.php` — natTrak API integration + CTP merge + MySQL cache
- Compliance monitoring in ADL daemon (120s cycle)
- EDCT pipeline: CTP → TMI → ADL → SWIM (automatic)

**Frontend**: `ctp.php` + `ctp.js` + `ctp.css` — full management UI

---

## 2. Integration Architecture

### 2.1 Design Principle

**PERTI is the operational platform. CTP API provides simulation/optimization. VATSWIM is the data exchange layer.**

The CTP API's unique value is the `SlotDistributionCreator` greedy bottleneck-first algorithm (see March 21 audit, Section 3.3). PERTI provides everything else: flight data, NAT track resolution, compliance, audit, visualization, multi-org coordination.

### 2.2 Chosen Approach: Option A (SWIM Middleware) + Option C (WebSocket Feed)

```
                                 ┌────────────────────────────────┐
                                 │        CTP API (.NET 10)       │
                                 │                                │
                                 │  VATSIMEvent ──→ Simulator     │
                                 │  ThroughputPoint  ──→ Slots    │
                                 │                                │
                                 │  1. Reads flights via WS/REST  │
                                 │  2. Runs slot optimization     │
                                 │  3. Pushes results via REST    │
                                 └───────┬──────────────┬─────────┘
                                    READ │              │ WRITE
                                         │              │
                              ┌──────────▼──────────────▼─────────┐
                              │          VATSWIM (SWIM_API)        │
                              │                                    │
                              │  /api/swim/v1/flights (read)       │
                              │  /api/swim/v1/ws/ (subscribe)      │
                              │  /api/swim/v1/ingest/ctp (write)  ◄── NEW
                              │  /api/swim/v1/tmi/nat_tracks/*     │
                              │                                    │
                              │  Authority rules, merge, rate limit│
                              └───────┬───────────────┬────────────┘
                                      │               │
                     Existing delta   │               │  Existing CTP
                     sync (2-min)     │               │  sync (2-min)
                                      │               │
                              ┌───────▼───┐   ┌──────▼──────────┐
                              │ VATSIM_ADL│   │  VATSIM_TMI     │
                              │           │   │  ctp_sessions    │
                              │ Flights   │   │  ctp_flight_ctrl │
                              │ Positions │   │  ctp_audit_log   │
                              │ ETAs      │   │  throughput_cfg  │
                              └───────────┘   └─────────────────┘
```

### 2.3 Why This Approach

| Criterion | Option A+C | Option B (Direct DB) | Pure Option C (Webhook) |
|-----------|-----------|---------------------|------------------------|
| Latency | Seconds (WS) + 2min (sync) | 30s | Seconds |
| Coupling | Loose (API contract) | Tight (schema) | Loose |
| CTP API changes needed | Add CID to Slot | Add 10+ columns + tables | Add receiver endpoint |
| PERTI changes needed | 1 new ingest endpoint | New sync daemon + PG connection | New webhook dispatcher |
| Auth | Existing SWIM keys | New PG credentials | HMAC signatures |
| Maintenance | SWIM team owns contract | Both teams break on schema changes | Both teams |
| Existing infra reuse | WebSocket + ingest + auth + merge | None | WebSocket exists |

---

## 3. Data Flow Specification

### 3.1 Flow 1: Flight Data Feed (PERTI → CTP API)

CTP API needs real-time flight data for the airports/FIRs relevant to a CTP event. Two delivery mechanisms:

#### 3.1.1 WebSocket Subscription (Real-Time)

CTP API connects to the existing SWIM WebSocket server:

```
wss://perti.vatcscc.org/api/swim/v1/ws/?api_key=swim_sys_ctp_XXXXXXXX
```

**Subscribe message**:
```json
{
  "action": "subscribe",
  "channel": "flights",
  "filters": {
    "constrained_firs": ["CZQX", "BIRD", "EGGX", "LPPO"],
    "phase": ["PREFILED", "DEPARTING", "EN_ROUTE"],
    "dep_window_start": "2026-10-19T10:00:00Z",
    "dep_window_end": "2026-10-19T18:00:00Z"
  }
}
```

**Push payload** (per flight update):
```json
{
  "event": "flight.update",
  "timestamp": "2026-10-19T14:30:05Z",
  "flight": {
    "flight_uid": 12345678,
    "gufi": "VAT-20261019-BAW117-EGLL-KJFK",
    "callsign": "BAW117",
    "cid": 1234567,
    "aircraft_icao": "B77W",
    "weight_class": "H",
    "fp_dept_icao": "EGLL",
    "fp_dest_icao": "KJFK",
    "fp_route": "BPK UN57 MALOT NATA 5320N 5330N 5340N DOTTY J584 KJFK",
    "fp_altitude_ft": 37000,
    "lat": 52.31,
    "lon": -20.45,
    "altitude_ft": 37000,
    "groundspeed_kts": 480,
    "heading_deg": 268,
    "estimated_time_of_arrival": "2026-10-19T20:15:00Z",
    "phase": "EN_ROUTE",
    "resolved_nat_track": "NATA",
    "edct_utc": null,
    "controlled_time_of_departure": null,
    "slot_time_utc": null
  }
}
```

**Connection limits**: System tier allows 10,000 connections. CTP API needs 1.

#### 3.1.2 REST Polling (Fallback / Initial Load)

For initial bulk load or WebSocket reconnection:

```
GET /api/swim/v1/flights?format=fixm
  &constrained_firs=CZQX,BIRD,EGGX
  &phase=PREFILED,DEPARTING,EN_ROUTE
  &dep_window_start=2026-10-19T10:00:00Z
  &dep_window_end=2026-10-19T18:00:00Z
  &limit=1000

Authorization: Bearer swim_sys_ctp_XXXXXXXX
```

**Rate**: System tier = 30,000 req/min. Polling every 30s = 2 req/min.

### 3.2 Flow 2: Slot Assignment Results (CTP API → PERTI)

After the CTP API runs its `SlotDistributionCreator` algorithm and produces slot assignments, it pushes results to PERTI via a new ingest endpoint.

#### 3.2.1 New Endpoint: `POST /api/swim/v1/ingest/ctp`

**Authentication**: SWIM API key (system tier, write permission)

**Request**:
```json
{
  "event_id": "CTP2026W",
  "session_id": 1,
  "source": "ctp-api",
  "source_version": "1.0.0",
  "slots": [
    {
      "callsign": "BAW117",
      "cid": 1234567,
      "dep_airport": "EGLL",
      "arr_airport": "KJFK",
      "departure_time": "2026-10-19T12:30:00Z",
      "projected_arrival_time": "2026-10-19T20:15:00Z",
      "route_segments": [
        {
          "segment": "NA",
          "route_string": "BPK UN57 MALOT",
          "group": "EMEA"
        },
        {
          "segment": "OCEANIC",
          "route_string": "MALOT 5320N 5330N 5340N 5350N DOTTY",
          "group": "NAT",
          "track_name": "NATA"
        },
        {
          "segment": "EU",
          "route_string": "DOTTY J584 KJFK",
          "group": "AMAS"
        }
      ],
      "throughput_point_id": "NATA",
      "slot_delay_min": 15,
      "original_etd": "2026-10-19T12:15:00Z"
    }
  ],
  "optimization_metadata": {
    "algorithm": "greedy_bottleneck_first",
    "mode": "MaximizeSlots",
    "total_slots": 450,
    "total_airports": 38,
    "total_route_segments": 358,
    "computation_time_ms": 2340,
    "revision": 1
  }
}
```

**Processing logic**:

1. **Validate**: Check API key has `ctp_write` permission. Validate `session_id` exists in `ctp_sessions` with `status IN ('ACTIVE', 'DRAFT')`.

2. **Match flights**: For each slot, find the flight in `ctp_flight_control`:
   ```sql
   SELECT ctp_control_id, flight_uid
   FROM dbo.ctp_flight_control
   WHERE session_id = @session_id
     AND callsign = @callsign
     AND dep_airport = @dep_airport
     AND arr_airport = @arr_airport
   ```
   If not found by callsign+airports, try by CID+airports.
   If still not found, add to `not_found` list.

3. **Apply assignments**: For each matched flight:
   ```sql
   UPDATE dbo.ctp_flight_control SET
     edct_utc = @departure_time,
     edct_status = 'ASSIGNED',
     edct_assigned_by = @source,
     edct_assigned_at = SYSUTCDATETIME(),
     original_etd_utc = @original_etd,
     slot_delay_min = @slot_delay_min,
     seg_na_route = @seg_na_route,
     seg_na_status = CASE WHEN @seg_na_route IS NOT NULL THEN 'VALIDATED' ELSE seg_na_status END,
     seg_oceanic_route = @seg_oceanic_route,
     seg_oceanic_status = CASE WHEN @seg_oceanic_route IS NOT NULL THEN 'VALIDATED' ELSE seg_oceanic_status END,
     seg_eu_route = @seg_eu_route,
     seg_eu_status = CASE WHEN @seg_eu_route IS NOT NULL THEN 'VALIDATED' ELSE seg_eu_status END,
     resolved_nat_track = @track_name,
     nat_track_resolved_at = SYSUTCDATETIME(),
     nat_track_source = 'CTP_API',
     swim_push_version = swim_push_version + 1,
     updated_at = SYSUTCDATETIME()
   WHERE ctp_control_id = @ctp_control_id
   ```

4. **Create TMI bridge** (if session has `program_id`):
   ```sql
   -- Link to tmi_flight_control for EDCT pipeline
   INSERT INTO dbo.tmi_flight_control (program_id, flight_uid, edct_utc, ...)
   VALUES (@program_id, @flight_uid, @departure_time, ...)
   ```

5. **Audit log**: Write bulk audit entry:
   ```sql
   INSERT INTO dbo.ctp_audit_log (session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by)
   VALUES (@session_id, @ctp_control_id, 'EDCT_BATCH_ASSIGN', 'GLOBAL',
           '{"source":"ctp-api","slot_count":450,"algorithm":"greedy_bottleneck_first"}',
           @source)
   ```

6. **Immediate SWIM push**: Update `swim_flights` for all matched flights (NAT track + EDCT if program_id linked). Skip daemon wait.

7. **WebSocket notify**:
   ```json
   {"event": "ctp.slots.optimized", "session_id": 1, "count": 450, "source": "ctp-api"}
   ```

**Response**:
```json
{
  "success": true,
  "processed": 450,
  "matched": 438,
  "assigned": 435,
  "skipped": 3,
  "not_found": 12,
  "errors": 0,
  "skip_reasons": [
    {"callsign": "UAL999", "reason": "already_assigned", "existing_edct": "2026-10-19T12:00:00Z"},
    {"callsign": "DAL456", "reason": "flight_excluded"},
    {"callsign": "AAL789", "reason": "session_mismatch"}
  ],
  "not_found_flights": [
    {"callsign": "SWA111", "dep_airport": "KDAL", "arr_airport": "EGLL"}
  ],
  "metadata": {
    "session_id": 1,
    "swim_pushed": 435,
    "tmi_bridged": 435,
    "audit_logged": true
  }
}
```

**Error codes**:
| HTTP | Code | Meaning |
|------|------|---------|
| 400 | `MISSING_PARAM` | Required field missing |
| 400 | `EMPTY_SLOTS` | Slots array empty |
| 401 | `UNAUTHORIZED` | Invalid/missing API key |
| 403 | `INSUFFICIENT_PERMISSION` | API key lacks `ctp_write` |
| 404 | `SESSION_NOT_FOUND` | session_id doesn't exist |
| 409 | `SESSION_NOT_ACTIVE` | Session status not ACTIVE/DRAFT |
| 422 | `VALIDATION_ERROR` | Slot data validation failure |
| 429 | `RATE_LIMITED` | Rate limit exceeded |

### 3.3 Flow 3: Event/Session Sync (Bidirectional)

CTP API creates events → PERTI needs corresponding sessions. Two options:

#### 3.3.1 Option A: PERTI Creates Sessions, CTP API Reads Them (Recommended)

The CTP planning workflow starts in PERTI:
1. Planner creates CTP session in PERTI (`POST /api/ctp/sessions/create.php`)
2. PERTI auto-detects flights from ADL (`POST /api/ctp/flights/detect.php`)
3. CTP API reads session data via SWIM REST:
   ```
   GET /api/swim/v1/tmi/nat_tracks/status?session_id=1
   ```
4. CTP API reads flights via WebSocket (see Flow 1)
5. CTP API loads airport/route data from SWIM or its own CSV seed
6. CTP API runs optimization
7. CTP API pushes results back (see Flow 2)

**Advantage**: No session sync needed. PERTI is the single source of truth.

#### 3.3.2 Option B: CTP API Creates Events, Syncs to PERTI

If the CTP API needs to be the event creation point:

**New endpoint**: `POST /api/swim/v1/ingest/ctp/event`

```json
{
  "event_id": "CTP2026W",
  "title": "Cross the Pond Westbound 2026",
  "date": "2026-10-19",
  "direction": "WESTBOUND",
  "departure_window_start": "2026-10-19T10:00:00Z",
  "departure_window_end": "2026-10-19T18:00:00Z",
  "constrained_firs": ["CZQX", "BIRD", "EGGX", "LPPO"],
  "airports": [
    {"identifier": "EGLL", "max_acph": 38, "votes": 2513},
    {"identifier": "KJFK", "max_acph": 44, "votes": 2504}
  ],
  "route_segments": [
    {"identifier": "NATA", "group": "NAT", "route_string": "MALOT 5320N 5330N 5340N DOTTY", "max_acph": 25}
  ]
}
```

This creates a `ctp_sessions` record + `ctp_track_throughput_config` entries.

**Recommendation**: Start with Option A (simpler). Add Option B only if the CTP API team needs to drive event creation.

### 3.4 Flow 4: Reference Data (PERTI → CTP API)

CTP API needs waypoint coordinates, NAT track definitions, and airport data that PERTI already has.

| Data Need | SWIM Endpoint | Notes |
|-----------|---------------|-------|
| Active NAT tracks | `GET /api/swim/v1/tmi/nat_tracks/status` | Merged natTrak + CTP templates |
| Waypoint coordinates | `GET /api/data/fixes.php?fixes=MALOT,DOTTY,...` | 269K fixes with lat/lon |
| Airport data | `GET /api/swim/v1/flights?dep_airport=EGLL&limit=0` | Airport metadata in response headers |
| Throughput configs | `GET /api/ctp/throughput/list.php?session_id=1` | Requires session auth |
| Flight demand | `GET /api/ctp/demand.php?session_id=1&group_by=nat_track` | Requires session auth |

These are all existing endpoints. No new work needed.

---

## 4. Authentication & Authorization

### 4.1 CTP API → SWIM (Reading Data)

| Method | Auth | Tier | Provisioning |
|--------|------|------|-------------|
| REST polling | `Authorization: Bearer swim_sys_ctp_...` | system (30K req/min) | Manual insert into `swim_api_keys` |
| WebSocket | `?api_key=swim_sys_ctp_...` | system (10K connections) | Same key |

**API Key record**:
```sql
INSERT INTO dbo.swim_api_keys (
  api_key, key_name, tier, permissions, is_active, created_at
) VALUES (
  'swim_sys_ctp_' + CONVERT(VARCHAR(36), NEWID()),
  'CTP API - vatsimnetwork/ctp-api',
  'system',
  'read,ctp_write',
  1,
  SYSUTCDATETIME()
);
```

### 4.2 CTP API → SWIM (Writing Slot Assignments)

Same API key with `ctp_write` permission. The ingest endpoint checks:
```php
if (!swim_check_permission($api_key, 'ctp_write')) {
    SwimResponse::error(403, 'INSUFFICIENT_PERMISSION', 'API key lacks ctp_write permission');
}
```

### 4.3 SWIM Authority Rules Update

Add CTP API as a recognized source in `load/swim_config.php`:

```php
// In $SWIM_SOURCE_PRIORITY['track']
'ctp_api' => 3,  // Between CRC (2) and EuroScope (3), or dedicated rank

// In $SWIM_FIELD_MERGE_BEHAVIOR — CTP-specific fields
'resolved_nat_track'      => 'variable',  // CTP API can update
'nat_track_resolved_at'   => 'variable',
'nat_track_source'        => 'variable',

// New authority rule for CTP slot data
'ctp_slots' => ['CTP_API', true],  // CTP API primary, others can override
```

---

## 5. CTP API Changes Required

The CTP API team needs to make these changes to support integration:

### 5.1 Required: Add Pilot Identity to Slot

The `Slot` entity currently has no way to identify which VATSIM pilot gets the slot. This is the critical missing link.

```csharp
// Add to Slot entity (DataStructures.cs in ctp-simulator)
public class Slot
{
    // Existing
    public uint Id { get; set; }
    public DateTime DepartureTime { get; set; }
    public DateTime ProjectedArrivalTime { get; set; }
    public int DepartureAirportId { get; set; }
    public int ArrivalAirportId { get; set; }

    // NEW — required for SWIM integration
    public string? Callsign { get; set; }       // VATSIM callsign (e.g., "BAW117")
    public int? CID { get; set; }               // VATSIM CID (e.g., 1234567)
    public string? AircraftType { get; set; }    // ICAO type (e.g., "B77W")
}
```

**EF Migration**: Add nullable columns to `Slots` table. These are populated after slot distribution when pilots are assigned to slots.

### 5.2 Recommended: Add SWIM Push Capability

After optimization runs, CTP API pushes results to SWIM:

```csharp
// New service: SwimPushService.cs
public class SwimPushService
{
    private readonly HttpClient _httpClient;
    private readonly string _swimApiKey;

    public async Task<SwimIngestResponse> PushSlotAssignments(
        string eventId,
        int sessionId,
        List<Slot> slots,
        OptimizationMetadata metadata)
    {
        var payload = new {
            event_id = eventId,
            session_id = sessionId,
            source = "ctp-api",
            source_version = "1.0.0",
            slots = slots.Select(s => new {
                callsign = s.Callsign,
                cid = s.CID,
                dep_airport = s.DepartureAirport.Identifier,
                arr_airport = s.ArrivalAirport.Identifier,
                departure_time = s.DepartureTime,
                projected_arrival_time = s.ProjectedArrivalTime,
                route_segments = s.RouteSegments.Select(rs => new {
                    segment = rs.RouteSegmentGroup switch {
                        "AMAS" => "NA", "EMEA" => "EU", "NAT" => "OCEANIC", _ => rs.RouteSegmentGroup
                    },
                    route_string = rs.RouteString,
                    group = rs.RouteSegmentGroup,
                    track_name = rs.RouteSegmentGroup == "NAT" ? rs.Identifier : null
                }),
                throughput_point_id = s.ThroughputPoint?.Identifier,
                slot_delay_min = (int)(s.DepartureTime - s.DepartureAirport.DepartureTimeWindowStart).TotalMinutes
            }),
            optimization_metadata = metadata
        };

        var response = await _httpClient.PostAsJsonAsync(
            "https://perti.vatcscc.org/api/swim/v1/ingest/ctp",
            payload);

        return await response.Content.ReadFromJsonAsync<SwimIngestResponse>();
    }
}
```

### 5.3 Recommended: Add WebSocket Client for Flight Feed

```csharp
// New service: SwimFlightFeedService.cs
// Connects to SWIM WebSocket, subscribes to flight updates,
// updates local CTP API state for real-time demand awareness
```

### 5.4 Optional: Add PERTI Session ID Mapping

```csharp
// Add to VATSIMEvent
public int? PERTISessionId { get; set; }  // Maps to ctp_sessions.session_id
```

---

## 6. PERTI Changes Required

### 6.1 New: CTP Ingest Endpoint

**File**: `api/swim/v1/ingest/ctp.php`

**Implementation outline** (see Section 3.2 for full contract):

```php
<?php
include("../../../../load/config.php");
include("../../../../load/connect.php");
include("../../../../load/swim_config.php");

// Auth
$auth = swim_init_auth(true);
if (!swim_check_permission($auth, 'ctp_write')) {
    SwimResponse::error(403, 'INSUFFICIENT_PERMISSION');
}

// Parse body
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['slots'])) {
    SwimResponse::error(400, 'EMPTY_SLOTS');
}

$session_id = intval($body['session_id'] ?? 0);
$source = $body['source'] ?? 'ctp-api';

// Validate session
$conn_tmi = get_conn_tmi();
$sess = sqlsrv_query($conn_tmi,
    "SELECT session_id, status, program_id FROM dbo.ctp_sessions WHERE session_id = ?",
    [$session_id]);
$session = sqlsrv_fetch_array($sess, SQLSRV_FETCH_ASSOC);
if (!$session) { SwimResponse::error(404, 'SESSION_NOT_FOUND'); }
if (!in_array($session['status'], ['ACTIVE', 'DRAFT'])) {
    SwimResponse::error(409, 'SESSION_NOT_ACTIVE');
}

// Process slots
$results = process_ctp_slot_assignments($conn_tmi, get_conn_swim(), $session, $body['slots'], $source);

// Audit log (bulk)
ctp_audit_log_bulk($conn_tmi, $session_id, 'EDCT_BATCH_ASSIGN', 'GLOBAL', [
    'source' => $source,
    'slot_count' => count($body['slots']),
    'matched' => $results['matched'],
    'assigned' => $results['assigned'],
    'algorithm' => $body['optimization_metadata']['algorithm'] ?? null
], $source);

// WebSocket push
ctp_push_swim_event('ctp.slots.optimized', [
    'session_id' => $session_id,
    'count' => $results['assigned'],
    'source' => $source
]);

SwimResponse::success($results);
```

### 6.2 New: SWIM API Key for CTP API

Manual one-time insert (see Section 4.1). No code change needed.

### 6.3 Modify: `load/swim_config.php`

Add `ctp_api` to source priority rankings and `ctp_write` to permission definitions.

### 6.4 Modify: WebSocket Subscription Filters

The existing WebSocket server may need a new `constrained_firs` filter type. Check if the `SubscriptionManager.php` already supports FIR-based filtering. If not, add it.

---

## 7. Field Mapping Reference

### 7.1 CTP API Slot → PERTI ctp_flight_control

| CTP API Slot Field | PERTI Column | Transform |
|---|---|---|
| `DepartureTime` | `edct_utc` | Direct DateTime |
| `ProjectedArrivalTime` | `oceanic_exit_utc` | Approximate (PERTI tracks entry+exit separately) |
| `DepartureAirport.Identifier` | `dep_airport` | Direct string match |
| `ArrivalAirport.Identifier` | `arr_airport` | Direct string match |
| `Callsign` | `callsign` | Direct string match (lookup key) |
| `CID` | (lookup via `adl_flight_core.cid`) | Secondary lookup key |
| `RouteSegments[group=AMAS]` | `seg_na_route` | Extract `RouteString` |
| `RouteSegments[group=NAT]` | `seg_oceanic_route` | Extract `RouteString` |
| `RouteSegments[group=EMEA]` | `seg_eu_route` | Extract `RouteString` |
| `RouteSegments[group=NAT].Identifier` | `resolved_nat_track` | Map identifier to NAT name |
| `ThroughputPoint.MaximumAircraftPerHour` | `ctp_track_throughput_config.max_acph` | Session-level config, not per-slot |
| `ThroughputPoint.SlotsAllocated` | (computed from `ctp_flight_control` counts) | Not stored per-row |

### 7.2 PERTI swim_flights → CTP API (Flight Feed)

| SWIM Field (FIXM) | CTP API Usage | Notes |
|---|---|---|
| `flight_uid` | Internal tracking | Stable identifier |
| `callsign` | Slot assignment key | Required |
| `cid` | Pilot identity | Required for CTP signup matching |
| `aircraft_icao` | Performance lookup | Maps to CTP `AircraftType` |
| `fp_dept_icao` | Airport matching | Maps to CTP `DepartureAirport.Identifier` |
| `fp_dest_icao` | Airport matching | Maps to CTP `ArrivalAirport.Identifier` |
| `fp_route` | Route segment parsing | CTP needs to decompose into 3 segments |
| `estimated_time_of_arrival` | Arrival projection | Baseline before optimization |
| `groundspeed_kts` | Simulator input | Better than constant 300kt |
| `phase` | Flight status | PREFILED/DEPARTING/EN_ROUTE/ARRIVED |
| `resolved_nat_track` | Track assignment | Already resolved by PERTI |

### 7.3 CTP RouteSegmentGroup → PERTI Segment Mapping

| CTP `RouteSegmentGroup` | PERTI `segment` | Description |
|---|---|---|
| `AMAS` | `NA` | Americas continental |
| `NAT` | `OCEANIC` | North Atlantic tracks |
| `EMEA` | `EU` | Europe/Middle East/Africa |

---

## 8. Implementation Sequence

### Phase 1: Connectivity (Effort: 1-2 days)

| Step | Owner | Task |
|------|-------|------|
| 1.1 | PERTI | Create SWIM API key for CTP API (`swim_sys_ctp_*`) |
| 1.2 | PERTI | Verify WebSocket FIR-based filtering works |
| 1.3 | CTP team | Connect to SWIM WebSocket, verify flight data receipt |
| 1.4 | CTP team | Implement REST polling fallback for initial load |

### Phase 2: Ingest Endpoint (Effort: 2-3 days)

| Step | Owner | Task |
|------|-------|------|
| 2.1 | PERTI | Build `api/swim/v1/ingest/ctp.php` endpoint |
| 2.2 | PERTI | Add `ctp_write` permission to swim_config |
| 2.3 | PERTI | Add `ctp_api` source to authority rules |
| 2.4 | PERTI | Write integration tests (mock slot payloads) |

### Phase 3: CTP API Enrichment (Effort: 2-3 days, CTP team)

| Step | Owner | Task |
|------|-------|------|
| 3.1 | CTP team | Add Callsign/CID/AircraftType to Slot entity |
| 3.2 | CTP team | Build `SwimPushService` for post-optimization push |
| 3.3 | CTP team | Map RouteSegmentGroup to PERTI segment names |
| 3.4 | CTP team | Handle push response (retry on 5xx, log not_found) |

### Phase 4: Event Sync (Effort: 1-2 days, if needed)

| Step | Owner | Task |
|------|-------|------|
| 4.1 | Both | Agree on event creation flow (PERTI-first or CTP-first) |
| 4.2 | PERTI | Build event ingest endpoint if CTP-first chosen |
| 4.3 | CTP team | Add `PERTISessionId` mapping to VATSIMEvent |

### Phase 5: End-to-End Testing (Effort: 1-2 days)

| Step | Owner | Task |
|------|-------|------|
| 5.1 | Both | Test with 25W CSV data (38 airports, 358 routes) |
| 5.2 | Both | Verify full cycle: detect → optimize → push → verify in PERTI UI |
| 5.3 | Both | Load test: 500 concurrent slots pushed |
| 5.4 | Both | Verify SWIM metrics update after push |

---

## 9. Risk Register

| Risk | Impact | Likelihood | Mitigation |
|------|--------|-----------|------------|
| CTP API team doesn't add Callsign to Slot | Integration blocked | Medium | Can work around with dep_airport+arr_airport+departure_time compound key, but fragile |
| CTP API's external auth service is unreachable from PERTI | Can't validate CTP API keys | Low | SWIM uses its own API key system, not CTP's auth service |
| Slot count exceeds throughput configs | Over-allocation | Medium | Ingest endpoint validates against `ctp_track_throughput_config` and warns |
| WebSocket connection drops during CTP event | CTP API loses flight feed | Medium | REST polling fallback; reconnection with exponential backoff |
| CTP API pushes stale optimization (based on old flight data) | Wrong EDCTs | Low | `revision` field in metadata; PERTI can reject if revision < current |
| Duplicate push (CTP API retries on timeout) | Double assignments | Medium | Idempotent ingest: `ON CONFLICT (session_id, callsign, dep_airport)` skip |

---

## 10. Future Enhancements (Out of Scope for V1)

1. **Bidirectional optimization**: PERTI runs its own planning compute → compares with CTP API results → presents both to planner
2. **Real-time reoptimization**: CTP API re-runs optimizer as flights depart, pushes updated slots for remaining flights
3. **Sankey visualization**: Adopt CTP Slot Planner's D3 Sankey component in PERTI's CTP UI (see March 21 audit, Section 2.4)
4. **Wind-adjusted ETAs**: Feed NOAA GFS wind data to CTP API's trajectory simulator (currently uses constant 300kt)
5. **BADA performance integration**: Replace CTP API's constant speed with PERTI's aircraft performance data via SWIM
6. **Voting data feed**: CTP signup/voting data pushed to PERTI for `Airport.NumberOfVotes` (currently external to both systems)

---

## Appendix A: Existing PERTI CTP Files (Verified)

```
# Main page + frontend
ctp.php                                         # CTP management UI
assets/js/ctp.js                                # CTP JavaScript module (IIFE)
assets/css/ctp.css                              # CTP styling

# API endpoints (35 files)
api/ctp/common.php                              # Auth, audit, SWIM push helpers
api/ctp/sessions/{list,get,create,update,activate,complete}.php
api/ctp/flights/{list,get,detect,validate_route,modify_route,assign_edct,assign_edct_batch,remove_edct,compliance,exclude,routes_geojson}.php
api/ctp/routes/{suggest,templates}.php
api/ctp/throughput/{list,create,update,delete,preview}.php
api/ctp/planning/{scenarios,scenario_save,scenario_delete,scenario_clone,block_save,block_delete,assignment_save,assignment_delete,compute,apply_to_session,track_constraints}.php
api/ctp/{demand,stats,audit_log,changelog,boundaries}.php

# SWIM NAT track endpoints
api/swim/v1/tmi/nat_tracks/{status,metrics}.php

# Services
load/services/NATTrackResolver.php              # Hybrid token+sequence resolution
load/services/NATTrackFunctions.php             # natTrak API + CTP merge + cache

# Sync daemon
scripts/swim_sync_daemon.php                    # Line 164: swim_sync_ctp_to_swim()
scripts/swim_sync.php                           # Lines 797-1045: 4 CTP sync functions

# Database migrations
database/migrations/tmi/045_ctp_oceanic_schema.sql
database/migrations/tmi/046_ctp_audit_enhance.sql
database/migrations/tmi/048_ctp_nat_track_throughput.sql
database/migrations/tmi/049_ctp_track_constraints.sql
database/migrations/swim/030_swim_nat_track_metrics.sql
database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql
database/migrations/schema/010_nat_track_cache.sql
database/migrations/postgis/ctp_oceanic_validation.sql

# Documentation
docs/CTP_EXTERNAL_REPO_AUDIT_AND_INTEGRATION.md
docs/superpowers/specs/2026-03-12-ctp-e26-integration-design.md
docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md
docs/superpowers/plans/2026-03-21-ctp-swim-nat-track-throughput.md
docs/ctp-sample-scenario-april2026.md
```

## Appendix B: CTP API Files (Verified, `feature/database` branch)

```
# Core
Program.cs                                      # Startup, middleware, EF config
Context/AppDbContext.cs                          # EF DbContext, TPT mapping, converters
Middleware/ApiKeyMiddleware.cs                   # External auth service validation

# Controllers
Controllers/SlotsController.cs                  # CRUD /api/slots
Controllers/VATSIMEventController.cs            # CRUD /api/VATSIMEvent
Controllers/ThroughputPointsController.cs       # CRUD /api/ThroughputPoints
Controllers/SeedController.cs                   # POST /api/seed (CSV bulk load)

# Database
Migrations/20260318183332_InitialCreate.cs      # Full schema (TPT tables + junctions)
Database/database-init.sql                      # Skeleton SQL (superseded by migration)

# Submodule
ctp-simulator/CTPSimulator/DataStructures.cs    # Entity models
ctp-simulator/CTPSimulator/SlotDistributionCreator.cs  # Optimization algorithm
ctp-simulator/CTPSimulator/Simulator.cs         # Trajectory calculator (has P0 bugs)

# Infrastructure
Dockerfile                                      # Multi-stage .NET 10
docker-compose.yml                              # api + postgres:16
.env.example                                    # Auth URL + PG creds
```
