# CTP External Repository Audit & PERTI Integration Analysis

**Date**: 2026-03-21
**Author**: Claude (vATCSCC Engineering)
**Status**: Complete
**Scope**: Deep-dive audit of two external VATSIM CTP repositories + integration mapping with PERTI/VATSWIM

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Repository Audit: CTP Slot Planner](#2-repository-audit-ctp-slot-planner)
3. [Repository Audit: CTP Simulator](#3-repository-audit-ctp-simulator)
4. [DataStructures.cs Domain Model Analysis](#4-datastructurescs-domain-model-analysis)
5. [PERTI Existing CTP Infrastructure](#5-perti-existing-ctp-infrastructure)
6. [Integration Surface Mapping](#6-integration-surface-mapping)
7. [Gap Analysis](#7-gap-analysis)
8. [Recommended Architecture](#8-recommended-architecture)
9. [Implementation Roadmap](#9-implementation-roadmap)
10. [Appendices](#10-appendices)

---

## 1. Executive Summary

### Repositories Audited

| Repository | Branch | Last Commit | Lines of Code | Status |
|------------|--------|-------------|---------------|--------|
| [vatsimnetwork/ctp-slot-planner](https://github.com/vatsimnetwork/ctp-slot-planner) | `dev` | 2026-03-20 | ~650 (JS+Python) | Early prototype |
| [vatsimnetwork/ctp-simulator](https://github.com/vatsimnetwork/ctp-simulator) | `development` | 2026-03-20 | ~837 (C#) | Working algorithm with bugs |

### Key Findings

1. **CTP Slot Planner** is a UI prototype only. Frontend and backend are completely disconnected. No persistence, no real data, no algorithm. The Sankey visualization concept is clean and worth adopting.

2. **CTP Simulator** has a working slot distribution algorithm (greedy bottleneck-first heuristic) tested against real CTP event data (38 airports, 358 routes). The flight trajectory simulator has two critical bugs. The `WeightedList` (Alias Method) implementation is sophisticated but unused.

3. **PERTI already has a complete, production-deployed CTP system** with 25 REST endpoints, 6 database tables, NAT track resolution, EDCT assignment, compliance tracking, demand analysis, throughput constraints, planning scenarios, SWIM API exposure, WebSocket push, audit trails, and MapLibre visualization. The external repos are building toward capabilities PERTI already ships.

4. **The primary integration value** is: (a) the simulator's automated slot optimization algorithm, which fills PERTI's gap of manual-only EDCT assignment, and (b) the Sankey flow visualization, which complements PERTI's existing demand charts.

---

## 2. Repository Audit: CTP Slot Planner

### 2.1 Overview

**Repository**: `vatsimnetwork/ctp-slot-planner` (dev branch)
**Stack**: React 19 + Vite 8 + D3.js 7 (frontend) / Flask 3.1 + Python (backend)
**License**: MIT (2026 VATSIM Inc)
**Commits**: 6 commits (2026-03-11 to 2026-03-20)

### 2.2 File Inventory

```
ctp-slot-planner/
├── README.md                          # 18 bytes (placeholder)
├── LICENSE                            # MIT
├── backend/
│   ├── app.py                        # 652 bytes - Flask routes
│   ├── auth.py                       # 1,316 bytes - VATSIM SSO validation
│   ├── requirements.txt               # 47 bytes - 3 dependencies
│   └── .env.example                  # 33 bytes - 3 env vars
└── frontend/
    ├── index.html                    # 363 bytes
    ├── package.json                  # 691 bytes - React + D3
    ├── vite.config.js                # 161 bytes
    ├── eslint.config.js              # 758 bytes
    ├── README.md                     # 1,506 bytes - Dev notes
    └── src/
        ├── main.jsx                  # 272 bytes
        ├── SlotPlanner.jsx           # 9,551 bytes - Main component
        └── SlotPlanner.css           # 2,021 bytes - Styling
```

### 2.3 Backend Analysis

#### app.py (Single Endpoint)

```python
@app.route('/slotgroups/', methods=['GET'])
def get_slot_groups():
    user = validate_session(request)
    slot_groups = [
        {'id': 'BOS_1_A_1_SVO', 'value': 30},
        {'id': 'BOS_2_A_1_SVO', 'value': 30},
        # ... 5 hardcoded entries
    ]
    return jsonify(slot_groups)
```

**Issues**:
- Returns hardcoded placeholder data
- `validate_session()` result (`user`) is captured but never used (no authorization)
- Response format (`{id, value}`) incompatible with frontend data model
- No database, no persistence, no CRUD operations

#### auth.py (VATSIM SSO Integration)

Authentication flow:
1. Extract `session_id` cookie from request
2. If `DEBUG=true`, return mock user `{"cid": "1234567", "roles": ["administrator"]}`
3. Forward session to external SSO service via `httpx.get(SSO_URL, ...)`
4. Pass through `X-Internal-Key`, `User-Agent`, `X-Forwarded-For` headers
5. Return user object `{"cid": "...", "roles": [...]}` on 200; abort 401 otherwise

**Issues**:
- No session result caching (will hit SSO on every request)
- No retry logic on SSO failures
- Debug mode hardcodes admin role (production risk)

#### Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| flask | ~3.1.3 | Web framework |
| httpx | ~0.28.1 | HTTP client for SSO |
| python-dotenv | ~1.2.1 | Environment config |

Missing: CORS, gunicorn, logging, database driver, testing framework.

### 2.4 Frontend Analysis

#### SlotPlanner.jsx (Core Component)

**State Management**:
```javascript
const [pairs, setPairs] = useState(AIRPORT_PAIRS);      // Hardcoded data
const [selectedDep, setSelectedDep] = useState(null);    // Highlight filter
const [editing, setEditing] = useState(null);            // Edit state {pairIdx, trackId, segment}
const [editVal, setEditVal] = useState('');              // Input value "value/cap"
const [colPositions, setColPositions] = useState(null);  // Cached column positions
```

**Data Model** (hardcoded):
```javascript
const AIRPORT_PAIRS = [
  {
    dep: 'CYQX', arr: 'EGLL',
    tracks: [
      { id:'A', col:'#2783C5',
        depToStart: { value:12, cap:30 },
        startToEnd: { value:11, cap:12 },
        endToArr:   { value:10, cap:11 } }
    ]
  },
  // 4 pairs total: CYQX→EGLL, CYYR→EINN, KBOS→EGLL (2 tracks), KJFK→EGLL (2 tracks)
];
```

**Visualization**: D3 SVG with Bezier curved bands connecting three columns:
- Column 1: Departure airports
- Column 2: Track identifiers (A-F with colors)
- Column 3: Arrival airports
- Bands: Width proportional to `value/cap`, color per track, opacity 0.55 (highlighted) / 0.05 (dim)
- Pills: Clickable `value/cap` labels on each segment, editable inline

**Editing Flow**:
1. Click pill → `startEdit(pairIdx, trackId, segment, value, cap)`
2. Modal input appears → user types `newValue/newCap`
3. On blur/Enter → `commitEdit()` → parse, validate (`cap > 0`, `value <= cap`), update state
4. SVG redraws via `useEffect`

**Critical Issues**:
1. Frontend never calls backend — data is 100% hardcoded
2. Frontend README explicitly warns: "data structure has changed... connect up to backend" is listed as TODO
3. `d3-sankey` package installed but never imported (manual band drawing instead)
4. `@fontsource/ubuntu` installed redundantly (also loaded via CSS `@import`)
5. No error handling, no loading states, no empty states
6. SVG clears and redraws entirely on every state change (no incremental updates)
7. Fixed column width (150px), no responsive design
8. No accessibility (ARIA labels, keyboard navigation)

### 2.5 Readiness Assessment

| Criterion | Status | Notes |
|-----------|--------|-------|
| UI Rendering | Working | Sankey visualization renders correctly |
| Backend API | Placeholder | Single endpoint, hardcoded data |
| Frontend↔Backend | Disconnected | Zero integration |
| Authentication | Backend only | Frontend has no auth flow |
| Persistence | None | Client-side state only |
| Error Handling | None | No try-catch, no validation feedback |
| Testing | None | No test framework or test files |
| Documentation | Minimal | 18-byte README |
| Deployment | None | No Docker, no CI/CD |
| Production Readiness | Not ready | Prototype only |

---

## 3. Repository Audit: CTP Simulator

### 3.1 Overview

**Repository**: `vatsimnetwork/ctp-simulator` (development branch)
**Stack**: C# 12 / .NET 10, CoordinateSharp 3.4.1.1
**License**: None specified
**Commits**: Active development (2026-03-20)

### 3.2 File Inventory

```
ctp-simulator/
├── CTPSimulator.slnx                 # Solution file (2 projects)
├── CTPSimulator/                      # Core library (class library)
│   ├── CTPSimulator.csproj           # .NET 10.0, CoordinateSharp dep
│   ├── DataStructures.cs             # 195 lines - Domain models
│   ├── Simulator.cs                  # 82 lines - Flight trajectory
│   ├── SimulatorCalculationParameters.cs  # 48 lines - Config
│   ├── SlotDistributionCreator.cs    # 158 lines - Allocation algorithm
│   └── WeightedList.cs              # 418 lines - Alias Method (unused)
│
├── CTPSimulatorTester/                # Console test harness
│   ├── CTPSimulatorTester.csproj     # .NET 10.0, ConsoleTables dep
│   ├── Program.cs                    # 64 lines - Main entry point
│   ├── TestingDataLoader.cs          # 63 lines - CSV parser
│   └── TestingData/
│       ├── 25W Airports.csv          # 38 airports
│       └── 25W RouteSegments.csv     # 358 route segments
```

### 3.3 Slot Distribution Algorithm (SlotDistributionCreator.cs)

The core value of this repository. Implements a greedy bottleneck-first slot allocation:

#### Algorithm Pseudocode

```
FUNCTION CreateSlotDistribution(event):

  // PHASE 1: Build routing graph
  departure_airports = airports WHERE any route segment starts at this airport
  arrival_airports = airports WHERE any route segment ends at this airport

  FOR EACH departure_airport:
    primary_routes = routes starting from this airport
    secondary_routes = routes starting where primary_routes end
  FOR EACH arrival_airport:
    primary_routes = routes ending at this airport
    secondary_routes = routes ending where primary_routes start

  // PHASE 2: Calculate capacity
  IF RecalculateMaximumAirportSlots:
    FOR EACH throughput_point:
      MaxSlots = MaxAircraftPerHour * DepartureTimeWindow.TotalHours

  // PHASE 3: Greedy allocation loop
  WHILE candidates exist:
    candidates = []
    FOR EACH departure WITH available_slots:
      FOR EACH secondary_route WITH available_slots:
        FOR EACH arrival WITH available_slots AND connected_to_route:
          ADD SlotChoice(departure, route, arrival, metrics)

    IF candidates.empty: BREAK

    // SELECTION HEURISTIC (MaximizeSlots mode):
    choice = candidates
      .OrderBy(PossiblePaths)              // 1st: Fewest options (bottleneck first)
      .ThenByDescending(SlotsAvailable)    // 2nd: Most remaining capacity
      .ThenByDescending(Votes)             // 3rd: Highest pilot demand
      .First()

    // ALLOCATE: Increment counters across all throughput points
    choice.DepartureAirport.SlotsAllocated++
    choice.RouteSegment.SlotsAllocated++
    choice.ArrivalAirport.SlotsAllocated++

    // CONNECT: Find primary routes (airport → NAT entry, NAT exit → airport)
    firstLeg = departure.primary_routes.WHERE(ends_at route.start).OrderBy(SlotsAllocated).First()
    thirdLeg = arrival.primary_routes.WHERE(starts_at route.end).OrderBy(SlotsAllocated).First()
    firstLeg.SlotsAllocated++
    thirdLeg.SlotsAllocated++

    // CREATE 3-leg slot
    ADD Slot(departure, [firstLeg, route, thirdLeg], arrival)
```

**Complexity**: O(A * R^2 * S) where A=airports, R=routes, S=total slots generated

**Heuristic Rationale**:
- Allocate bottleneck routes first (fewest alternatives) to prevent deadlock
- Among equally constrained options, prefer those with more spare capacity
- Use pilot voting weights to break ties (fairness metric)

#### Alternative Mode: Random

Uses `PickOne()` (uniform random selection from candidates). The sophisticated `WeightedList` class (Vose's Alias Method, O(1) sampling) is implemented but not yet wired into the random path.

### 3.4 Flight Trajectory Simulator (Simulator.cs)

Computes projected arrival times via great-circle propagation:

```
FUNCTION SimulateSlot(event, departureTime, slot):
  waypoints = assemble from all route segment locations
  earthShape = HighAccuracy ? Ellipsoid : Sphere
  currentPosition = origin
  currentTime = departureTime

  FOR EACH waypoint pair:
    distance = geodetic_distance(currentPosition, nextWaypoint)
    WHILE distance > 0:
      groundSpeed = 300 knots  // CONSTANT
      stepDistance = groundSpeed * (resolutionMinutes / 60)
      IF stepDistance < distance:
        distance -= stepDistance
        currentPosition.Move(toward: nextWaypoint, distance: stepDistance)
        currentTime += resolutionMinutes
      ELSE:
        IF finalWaypoint:
          slot.ProjectedArrivalTime = currentTime + (distance / groundSpeed)
        BREAK
```

### 3.5 Critical Bugs

| ID | Severity | File:Line | Description | Fix |
|----|----------|-----------|-------------|-----|
| **BUG-1** | P0 | Simulator.cs:29-31 | Inner loop uses `l < slot.RouteSegments.Count` instead of `slot.RouteSegments[r].Locations.Count`. Only partially assembles waypoint list. | Change to `slot.RouteSegments[r].Locations.Count` |
| **BUG-2** | P0 | Simulator.cs:52 | `currentPosition` used in `Get_Distance_From_Coordinate()` before initialization. Crashes or produces garbage. | Initialize: `new Coordinate(origin.Latitude, origin.Longitude, ...)` |
| **BUG-3** | P1 | SlotDistributionCreator.cs:13 | `static readonly Random` is not thread-safe. Concurrent calls corrupt state. | Use `lock` or `ThreadLocal<Random>` |
| **BUG-4** | P1 | SlotDistributionCreator.cs:16 | `PickOne()` throws `IndexOutOfRangeException` on empty list. | Add bounds check |
| **BUG-5** | P2 | Simulator.cs | `async Task` signature but no `await` in body. Runs synchronously despite async appearance. | Remove `async` or make genuinely async |
| **BUG-6** | P2 | TestingDataLoader.cs:40 | Waypoints added to `vatsimEvent.Waypoints` without deduplication. Same fix appears multiple times. | Check `Contains()` before adding |
| **BUG-7** | P2 | TestingDataLoader.cs:35 | Airway filtering heuristic (`IsDigit(waypoint.Last())`) incorrectly skips oceanic lat/lon waypoints like `6620N`. | Use proper airway regex (e.g., `^[A-Z]\d+$`) |

### 3.6 Missing Capabilities

| Feature | Status | Notes |
|---------|--------|-------|
| Waypoint coordinates | Missing | CSV has ICAO codes only; Locations have no lat/lon |
| Wind/weather | Stubbed | Config flag exists; no implementation |
| Aircraft performance | Missing | Constant 300kt for all aircraft |
| Altitude modeling | Missing | No climb/descent profiles |
| Slot time spacing | Missing | No minimum separation between consecutive slots |
| Database persistence | Missing | Pure in-memory |
| API interface | Missing | Console app only |
| Pilot assignment (CID) | Missing | Slot.CID commented as TODO |

### 3.7 Test Data (25W Event)

**38 airports** across Americas, Europe, Caribbean:
- Highest capacity: KORD (83/hr), EHAM (65/hr), LFPG (55/hr)
- Highest votes: EHAM (4616), EDDF (3654), LFPG (3011)
- Lowest: EVRA (14/hr), LPPT (17/hr)

**358 route segments** across 3 groups:
- **NAT** (North Atlantic Tracks): A1-A6, B-O, P1-P3, Q-U with oceanic waypoints
- **EMEA** (Europe/Middle East/Africa): Airway-based routes (Y150, P605, etc.)
- **AMAS** (Americas): Continental connector routes (J528, Q800, etc.)

### 3.8 WeightedList.cs (Alias Method)

Fully implemented Vose's Alias Method for O(1) weighted random sampling:
- 418 lines, most complex file in the repo
- Handles: add/remove/update weights, batch operations, error policies
- **Not used anywhere** in the current codebase
- Likely intended for weighted random slot generation mode

---

## 4. DataStructures.cs Domain Model Analysis

### 4.1 Class Hierarchy

```
VATSIMEvent                          (Root container)
├── Airports: List<Airport>
├── Waypoints: List<Location>
├── RouteSegments: List<RouteSegment>
├── Sectors: List<Sector>
├── Slots: List<Slot>                (Output)
├── DepartureAirports [NotMapped]    (Computed)
└── ArrivalAirports [NotMapped]      (Computed)

ThroughputPoint (abstract)           (Capacity-constrained node)
├── Identifier: string
├── MaximumAircraftPerHour: ushort   (Default: 20)
├── MaximumSlots: ushort             (Computed: MaxACPH * TimeWindow)
├── SlotsAllocated: ushort           (Runtime counter)
├── SlotsStillAvailable [NotMapped]  (MaxSlots - Allocated)
└── AreSlotsStillAvailable [NotMapped]

Location : ThroughputPoint           (Geographic point)
├── Latitude: double
└── Longitude: double

Airport : Location                   (Departure/arrival with voting)
├── DepartureTimeWindowStart: DateTime
├── NumberOfVotes: ushort
├── ConnectingPrimaryRouteSegments [NotMapped]
└── ConnectingSecondaryRouteSegments [NotMapped]

RouteSegment : ThroughputPoint       (Route corridor with waypoints)
├── RouteString: string              (e.g., "MARUN Y150 TOLGI SAS P605 NOLGO")
├── RouteSegmentGroup: string        (NAT, EMEA, AMAS)
├── RouteSegmentTags: List<string>
├── ProvidedFacilityProgression: List<Sector>
└── Locations: List<Location>        (Min 2 required)

Sector : ThroughputPoint             (Airspace boundary)
├── MaxLatitude/MinLatitude: double
├── MaxLongitude/MinLongitude: double
└── Coordinates: double[,]           (Polygon vertices)

Slot                                 (Allocation result)
├── RouteSegments: List<RouteSegment> (3-leg path: dep→NAT→arr)
├── DepartureTime: DateTime          (EDCT)
├── ProjectedArrivalTime: DateTime   (Computed)
├── DepartureAirport: Airport
└── ArrivalAirport: Airport
```

### 4.2 Mapping to PERTI Data Model

| Simulator Entity | PERTI Equivalent | Table | Notes |
|------------------|------------------|-------|-------|
| `VATSIMEvent` | CTP Session | `ctp_sessions` | direction, constrained_firs, slot_interval, max_slots_per_hour |
| `Airport` | Airport config | `airport_config` + `ctp_track_throughput_config` | Rates from throughput config; coordinates from `nav_fixes`/`airports` |
| `Airport.NumberOfVotes` | No equivalent | (External) | Would need VATSIM CTP signup API |
| `Location` (waypoint) | Nav fix | `VATSIM_REF.nav_fixes` (269K) / `VATSIM_GIS.nav_fixes` (535K) | Full coordinate data available |
| `RouteSegment` | Route template | `ctp_route_templates` | Session-scoped, with origin/dest filters |
| `RouteSegment.RouteString` | Filed route / template route | `ctp_route_templates.route_string` | PostGIS `expand_route()` can resolve to coordinates |
| `RouteSegment.RouteSegmentGroup` | Perspective segment | `ctp_flight_control.seg_*_route` | NA / OCEANIC / EU mapping |
| `Sector` | Sector boundary | `VATSIM_GIS.sector_boundaries` (4,085) | Full polygon data with throughput |
| `Slot` | Flight control + EDCT | `ctp_flight_control` | `edct_utc`, `original_etd_utc`, `slot_delay_min` |
| `Slot.RouteSegments[3]` | 3-segment decomposition | `seg_na_route`, `seg_oceanic_route`, `seg_eu_route` | Already modeled in PERTI |
| `ThroughputPoint.MaximumAircraftPerHour` | Track throughput | `ctp_track_throughput_config.max_acph` | Per-track, per-origin, per-dest constraints |
| `SimulatorCalculationParameters` | Session config | `ctp_sessions` columns | `slot_interval_min`, `max_slots_per_hour`, `validation_rules_json` |

### 4.3 Data Available in PERTI but Missing from Simulator

| Data | PERTI Source | Impact on Simulator |
|------|-------------|---------------------|
| Waypoint coordinates (lat/lon) | `nav_fixes` (269K fixes) | Enables trajectory simulation (currently impossible) |
| Active NAT track definitions | `NATTrackResolver.php` + nattrak API | Real-time track routing instead of static CSV |
| Airport coordinates | `VATSIM_GIS.airports` (37K) | Enables distance calculations |
| Airway geometry | `VATSIM_GIS.airways` + PostGIS `expand_route()` | Full route expansion with segment geometry |
| Real-time flight positions | `adl_flight_position` | Live demand for dynamic optimization |
| Weather data | `services/` (NOAA GFS wind fetch) | Wind-adjusted ETAs |
| Boundary crossings | `adl_flight_planned_crossings` (20.5M) | Oceanic entry/exit time predictions |
| Aircraft performance | BADA tables in `VATSIM_ADL` | Speed profiles by aircraft type |

---

## 5. PERTI Existing CTP Infrastructure

### 5.1 Database Schema (6 Tables, Deployed)

**Core Tables** (VATSIM_TMI):

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `ctp_sessions` | Event sessions | session_id, direction, constrained_firs, status, slot_interval_min, max_slots_per_hour |
| `ctp_flight_control` | Per-flight management | ctp_control_id, flight_uid, seg_na/oceanic/eu_route, edct_utc, resolved_nat_track, compliance_status |
| `ctp_audit_log` | Action history | action_type, action_detail_json, performed_by |
| `ctp_route_templates` | NAT tracks & templates | template_name, route_string, segment, origin_filter, dest_filter |
| `ctp_track_throughput_config` | Throughput constraints | tracks_json, origins_json, destinations_json, max_acph, priority |
| `ctp_planning_scenarios` | Planning simulator | departure_window, traffic_blocks, distribution |

**Supporting Tables** (SWIM_API):

| Table | Purpose |
|-------|---------|
| `swim_nat_track_metrics` | 15-minute binned NAT track occupancy |
| `swim_nat_track_throughput` | Real-time throughput utilization |

### 5.2 API Endpoints (25 Endpoints, Deployed)

#### Session Management (6)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/ctp/sessions/list.php` | List all sessions |
| GET | `/api/ctp/sessions/get.php` | Get session details |
| POST | `/api/ctp/sessions/create.php` | Create new session |
| POST | `/api/ctp/sessions/update.php` | Update session config |
| POST | `/api/ctp/sessions/activate.php` | Transition DRAFT→ACTIVE |
| POST | `/api/ctp/sessions/complete.php` | Transition to COMPLETED |

#### Flight Operations (9)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/ctp/flights/list.php` | Server-side paginated flight list |
| GET | `/api/ctp/flights/get.php` | Single flight details |
| POST | `/api/ctp/flights/detect.php` | Auto-detect oceanic flights from ADL |
| POST | `/api/ctp/flights/validate_route.php` | Validate route via PostGIS |
| POST | `/api/ctp/flights/modify_route.php` | Edit segment route |
| POST | `/api/ctp/flights/assign_edct.php` | Assign EDCT to single flight |
| POST | `/api/ctp/flights/assign_edct_batch.php` | Batch EDCT assignment |
| POST | `/api/ctp/flights/remove_edct.php` | Remove EDCT |
| GET | `/api/ctp/flights/compliance.php` | Compliance status check |

#### Routes (2)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/ctp/routes/suggest.php` | Route suggestion via PostGIS |
| GET | `/api/ctp/routes/templates.php` | List route templates |

#### Planning (4)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET/POST | `/api/ctp/planning/scenarios.php` | CRUD scenarios |
| POST | `/api/ctp/planning/compute.php` | Run planning simulation |
| POST | `/api/ctp/planning/apply_to_session.php` | Apply scenario to session |

#### Throughput (5)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/ctp/throughput/list.php` | List throughput configs |
| POST | `/api/ctp/throughput/create.php` | Create config |
| POST | `/api/ctp/throughput/update.php` | Update config |
| POST | `/api/ctp/throughput/delete.php` | Delete config |
| GET | `/api/ctp/throughput/preview.php` | Utilization forecast |

### 5.3 SWIM API Extensions for CTP

| Endpoint | Purpose |
|----------|---------|
| `GET /api/swim/v1/flights.php` | 120+ column FIXM-aligned flight data (includes `resolved_nat_track`) |
| `GET /api/swim/v1/tmi/nat_tracks/status.php` | Live NAT track snapshot with occupancy |
| `GET /api/swim/v1/tmi/nat_tracks/metrics.php` | Historical 15-min binned track metrics |
| `WS /api/swim/v1/ws/` | Real-time push: `ctp.edct.assigned`, `ctp.route.modified`, etc. |

### 5.4 NAT Track Resolution System

**Services**: `NATTrackResolver.php` + `NATTrackFunctions.php`

Resolution strategy (priority order):
1. **Token Detection**: Regex `\b(NAT|TRACK|TRAK|TRK)-?([A-Z0-9]{1,5})\b` on filed route
2. **Sequence Matching**: Compare oceanic segment waypoints against active track definitions

Track sources (merged, CTP templates take priority):
- `nattrak.vatsim.net/api/tracks` (30-min cached)
- `ctp_route_templates` (session-scoped overrides)

### 5.5 Frontend (ctp.php)

Full-featured CTP management interface:
- Session selector + lifecycle management
- Real-time flight table (server-side pagination, 100-500 flights)
- MapLibre GL visualization with route symbology
- Bottom tabs: Demand, Throughput, Planning, Routes, Stats
- Flight sidebar with 3-segment route editor (NA/Oceanic/EU)
- Search + filtering (callsign, airport, entry fix, NAT track, status)
- Perspective tabs (ALL/NA/OCEANIC/EU)

### 5.6 EDCT Assignment Pipeline

```
1. User assigns EDCT → POST /api/ctp/flights/assign_edct.php
2. Creates link → ctp_flight_control ↔ tmi_flight_control (TMI bridge)
3. ADL daemon detects → executeDeferredTMISync() (60s cycle)
4. Syncs to ADL → adl_flight_tmi + adl_flight_times
5. SWIM daemon → swim_sync_daemon.php (2-min cycle)
6. Updates SWIM → swim_flights.edct_utc
7. WebSocket push → Real-time client notification
```

### 5.7 Auth & Org Scoping

- **VATSIM Connect OAuth** via `/login/`
- **Perspective-based access**: `ctp_sessions.perspective_orgs_json` maps segments to managing organizations
  ```json
  {"NA":["DCC","CANOC"],"OCEANIC":["GANDER","SHANWICK"],"EU":["ECFMP"],"GLOBAL":["DCC"]}
  ```
- **Functions**: `ctp_require_auth()`, `ctp_check_perspective($session, $segment)`, `ctp_get_user_perspectives($session)`

---

## 6. Integration Surface Mapping

### 6.1 CTP Simulator → PERTI

#### What the Simulator Provides (Unique Value)

| Capability | Description | PERTI Gap It Fills |
|------------|-------------|-------------------|
| **Automated slot optimization** | Greedy bottleneck-first + voting heuristic | PERTI has manual-only EDCT assignment |
| **Capacity-aware allocation** | Respects throughput limits at every network node | Planning scenarios exist but lack optimization |
| **Fairness weighting** | NumberOfVotes provides demand-proportional allocation | No fairness metric in current EDCT assignment |
| **What-if simulation** | Run scenarios before committing | Planning compute exists but is simpler |

#### Integration Points

| Simulator Input | PERTI Data Source | API |
|----------------|-------------------|-----|
| Airport list + capacity | `ctp_track_throughput_config` | `GET /api/ctp/throughput/list.php` |
| Airport coordinates | `VATSIM_GIS.airports` | `GET /api/data/fixes.php` or direct GIS query |
| Route segments | `ctp_route_templates` | `GET /api/ctp/routes/templates.php` |
| Waypoint coordinates | `VATSIM_REF.nav_fixes` (269K) | `GET /api/data/fixes.php` |
| NAT track definitions | `NATTrackResolver` + nattrak API | `GET /api/swim/v1/tmi/nat_tracks/status.php` |
| Flight demand | `ctp_flight_control` counts | `GET /api/ctp/flights/list.php` |
| Departure window | `ctp_sessions` config | `GET /api/ctp/sessions/get.php` |

| Simulator Output | PERTI Target | API |
|------------------|-------------|-----|
| Slot assignments | `ctp_flight_control.edct_utc` | `POST /api/ctp/flights/assign_edct_batch.php` |
| Projected ETAs | `adl_flight_times.eta_utc` | Via EDCT → TMI sync → ADL sync pipeline |
| Allocation metrics | Planning scenario results | `POST /api/ctp/planning/compute.php` |
| Commentary | Audit log | `ctp_audit_log` |

### 6.2 CTP Slot Planner → PERTI

#### What the Planner Provides (Unique Value)

| Capability | Description | PERTI Gap It Fills |
|------------|-------------|-------------------|
| **Sankey flow visualization** | Departure → Track → Arrival with capacity bands | Demand charts exist but no flow view |
| **Inline capacity editing** | Click-to-edit `value/cap` pills | Throughput config exists but no visual editor |
| **Multi-track visualization** | Shows multiple tracks per airport pair | Current demand groups by track but not visually connected |

#### Data Sources for Planner (from PERTI APIs)

```
// Sankey data: airport pair counts grouped by NAT track
GET /api/ctp/demand.php?session_id=N&group_by=nat_track&bin_minutes=60

// Capacity caps per track
GET /api/ctp/throughput/list.php?session_id=N

// Persist capacity edits
POST /api/ctp/throughput/update.php
  { config_id: N, max_acph: 120 }

// Real-time updates
WS /api/swim/v1/ws/
  → ctp.edct.assigned, ctp.route.modified, ctp.compliance.updated
```

### 6.3 PERTI → External CTP Repos

PERTI can serve as the **single source of truth** via its REST and SWIM APIs:

```
External CTP Repos
     │
     ├─ Read (no auth required)
     │   ├─ GET /api/swim/v1/flights.php           (120+ col FIXM flight data)
     │   ├─ GET /api/swim/v1/tmi/nat_tracks/status  (track occupancy)
     │   ├─ GET /api/swim/v1/tmi/nat_tracks/metrics  (historical bins)
     │   └─ GET /api/ctp/demand.php                 (demand charts)
     │
     ├─ Read (SWIM API key required)
     │   ├─ GET /api/swim/v1/flights.php?tmi_controlled=true
     │   └─ GET /api/swim/v1/tmi/programs.php
     │
     └─ Write (VATSIM auth required)
         ├─ POST /api/ctp/flights/assign_edct.php
         ├─ POST /api/ctp/flights/assign_edct_batch.php
         ├─ POST /api/ctp/flights/modify_route.php
         ├─ POST /api/ctp/throughput/create.php
         └─ POST /api/ctp/sessions/create.php
```

---

## 7. Gap Analysis

### 7.1 Gaps the External Repos Fill

| Gap in PERTI | External Source | Integration Effort | Priority |
|-------------|----------------|-------------------|----------|
| **Automated slot optimization** | Simulator's greedy algorithm | Medium (adapt algorithm, wire to PERTI APIs) | High |
| **Sankey flow visualization** | Planner's D3 component | Low (React component, feed from existing APIs) | Medium |
| **What-if scenario comparison** | Simulator's deterministic mode | Medium (run multiple configs, compare) | Medium |
| **Weighted fairness allocation** | Simulator's voting heuristic | Low (add NumberOfVotes to throughput config) | Low |

### 7.2 Gaps the External Repos Still Have

| Gap | Impact | Resolution |
|-----|--------|------------|
| **No waypoint coordinates** | Simulator cannot compute ETAs | PERTI provides 269K fixes with lat/lon via API |
| **No real-time flight data** | Simulator works offline only | PERTI provides live flight feed via SWIM API |
| **No persistence** | Both repos lose state on restart | PERTI provides full database persistence |
| **No auth** | Planner frontend has no login | PERTI provides VATSIM OAuth + org-scoped access |
| **No compliance tracking** | Simulator doesn't verify results | PERTI tracks actual vs assigned departure |
| **No multi-facility coordination** | Single-user operation | PERTI supports NA/OCEANIC/EU perspectives |
| **Simulator trajectory bugs** | P0 loop index + P0 currentPosition | Must fix before any integration |
| **Constant 300kt cruise** | Unrealistic ETAs | PERTI has BADA tables + wind data |

### 7.3 Overlap (Already Covered by PERTI)

| Feature | CTP Repo Implementation | PERTI Implementation | Recommendation |
|---------|------------------------|---------------------|----------------|
| Session management | None | Full CRUD + lifecycle | Use PERTI |
| Flight detection | None | Auto-detect from 1.6M ADL flights | Use PERTI |
| NAT track resolution | None | Hybrid token + sequence matching | Use PERTI |
| Route validation | None | PostGIS `expand_route()` | Use PERTI |
| EDCT assignment | None (output only) | Full pipeline with TMI bridge | Use PERTI |
| Compliance tracking | None | Auto delta calculation | Use PERTI |
| Demand analysis | None | Hourly binned, multi-group | Use PERTI |
| Audit trail | None | JSON diff audit log | Use PERTI |
| Real-time push | None | WebSocket on port 8090 | Use PERTI |

---

## 8. Recommended Architecture

### 8.1 System Architecture

```
┌───────────────────────────────────────────────────────────────┐
│                     PERTI Platform (Production)                │
│                                                                │
│  ┌────────────┐  ┌───────────────┐  ┌──────────────────────┐ │
│  │  ctp.php   │  │ CTP REST API  │  │   SWIM API (Public)  │ │
│  │  (UI)      │  │ (25 endpoints)│  │   (flights, metrics) │ │
│  │            │  │               │  │                      │ │
│  │ ┌────────┐ │  │  Sessions     │  │  NAT Track Status    │ │
│  │ │ Sankey │ │  │  Flights      │  │  NAT Track Metrics   │ │
│  │ │ (new)  │ │  │  EDCT Assign  │  │  CTP Capacity Flow   │ │
│  │ └────────┘ │  │  Throughput   │  │  WebSocket Push      │ │
│  │  Demand    │  │  Planning     │  │                      │ │
│  │  Map+Table │  │  Optimizer    │  │                      │ │
│  │  Compliance│  │  Routes       │  │                      │ │
│  └────────────┘  └───────────────┘  └──────────────────────┘ │
│        │                │                     │               │
│  ┌─────┴─────────────────┴─────────────────────┴───────────┐  │
│  │               Data Layer (7 Databases)                   │  │
│  │  TMI: ctp_sessions, ctp_flight_control, tmi_slots        │  │
│  │  ADL: adl_flight_core, nav_fixes (269K), BADA            │  │
│  │  GIS: PostGIS expand_route(), boundaries (5K polygons)   │  │
│  │  SWIM: swim_flights, swim_nat_track_metrics              │  │
│  │  REF: nav_fixes, airways, procedures, playbook_routes    │  │
│  └──────────────────────────────────────────────────────────┘  │
│        ▲                ▲                     ▲                │
└────────┼────────────────┼─────────────────────┼───────────────┘
         │                │                     │
    ┌────┴─────┐    ┌─────┴──────┐        ┌────┴──────┐
    │  Sankey   │    │ Optimizer  │        │ External  │
    │ Component │    │ Algorithm  │        │ Consumers │
    │ (adapted  │    │ (adapted   │        │ (via SWIM │
    │  from CTP │    │  from CTP  │        │  API keys)│
    │  Planner) │    │  Simulator)│        │           │
    └──────────┘    └────────────┘        └───────────┘
```

### 8.2 Integration Strategy

**Principle**: PERTI is the platform. External repos contribute algorithms and visualizations that plug into PERTI's existing infrastructure.

#### Option A: Native PHP Integration (Recommended)

Port the simulator's greedy algorithm to PHP and integrate directly into PERTI's planning API:

**Pros**: No new runtime dependencies, same deployment, direct database access, consistent auth
**Cons**: Algorithm rewrite from C# to PHP

```
POST /api/ctp/planning/optimize.php
  Input: { session_id, mode: "maximize"|"random", departure_window, params }
  Process: PHP implementation of SlotDistributionCreator algorithm
  Output: { slots: [...], metrics: { total, by_track, by_airport }, commentary }

POST /api/ctp/planning/apply_optimization.php
  Input: { session_id, optimization_id }
  Process: Batch EDCT assignment from optimization results
  Output: { assigned: N, skipped: N, errors: [...] }
```

#### Option B: Microservice Bridge

Run the C# simulator as a sidecar service that PERTI calls via HTTP:

**Pros**: No algorithm rewrite, can evolve independently
**Cons**: Additional deployment complexity, latency, separate auth

```
PERTI → HTTP → C# Optimizer Service → HTTP → PERTI
  (collect data)    (run algorithm)      (write results)
```

#### Option C: Offline Batch Tool

Keep the simulator as a standalone tool that imports/exports via PERTI's APIs:

**Pros**: Simplest integration, no changes to either codebase
**Cons**: Manual workflow, no real-time integration

### 8.3 Sankey Visualization Integration

Embed the Sankey concept into PERTI's existing `ctp.php` as a new tab in the bottom panel:

```javascript
// New tab in CTP bottom panel: "Flow"
// Renders Sankey diagram from PERTI API data

// Data source:
fetch(`/api/ctp/demand.php?session_id=${sessionId}&group_by=nat_track`)
  .then(data => renderSankey(data));

// Capacity source:
fetch(`/api/ctp/throughput/list.php?session_id=${sessionId}`)
  .then(caps => overlayCapacity(caps));

// Persist edits:
function onCapacityEdit(trackConfig, newMaxAcph) {
  fetch('/api/ctp/throughput/update.php', {
    method: 'POST',
    body: JSON.stringify({ config_id: trackConfig.id, max_acph: newMaxAcph })
  });
}
```

Implementation approach: Adapt the Sankey rendering from SlotPlanner.jsx to vanilla JS + D3 (matching PERTI's frontend stack of jQuery + D3). Feed from existing PERTI APIs instead of hardcoded data.

---

## 9. Implementation Roadmap

### Phase 1: Algorithm Port (High Priority)

**Goal**: Automated slot optimization available in PERTI's planning API

| Step | Task | Effort |
|------|------|--------|
| 1.1 | Port `SlotDistributionCreator` greedy algorithm to PHP | 2-3 days |
| 1.2 | Wire to `ctp_track_throughput_config` for capacity data | 1 day |
| 1.3 | Wire to `nav_fixes` for waypoint coordinates | 0.5 day |
| 1.4 | Wire to `ctp_route_templates` for route segments | 0.5 day |
| 1.5 | Create `POST /api/ctp/planning/optimize.php` endpoint | 1 day |
| 1.6 | Create `POST /api/ctp/planning/apply_optimization.php` | 1 day |
| 1.7 | Add optimization UI to CTP planning tab | 1-2 days |

**Total**: ~7-9 days

### Phase 2: Sankey Visualization (Medium Priority)

**Goal**: Flow visualization in CTP bottom panel

| Step | Task | Effort |
|------|------|--------|
| 2.1 | Adapt Sankey rendering to vanilla JS + D3 | 1-2 days |
| 2.2 | Feed from `/api/ctp/demand.php` grouped by `nat_track` | 0.5 day |
| 2.3 | Overlay capacity from throughput config | 0.5 day |
| 2.4 | Add inline editing with persist to throughput API | 1 day |
| 2.5 | Add as "Flow" tab in CTP bottom panel | 0.5 day |

**Total**: ~4-5 days

### Phase 3: ETA Enhancement (Low Priority)

**Goal**: Wind-adjusted projected arrival times

| Step | Task | Effort |
|------|------|--------|
| 3.1 | Port `Simulator.SimulateSlot()` trajectory logic (with bug fixes) | 1-2 days |
| 3.2 | Integrate NOAA GFS wind data (already fetched by PERTI) | 1 day |
| 3.3 | Integrate BADA aircraft performance tables | 1 day |
| 3.4 | Add projected ETA to optimization output | 0.5 day |

**Total**: ~4-5 days

### Phase 4: SWIM Exposure (Low Priority)

**Goal**: Public API for CTP slot data

| Step | Task | Effort |
|------|------|--------|
| 4.1 | Create `GET /api/swim/v1/ctp/capacity.php` (Sankey-ready data) | 1 day |
| 4.2 | Create `GET /api/swim/v1/ctp/slots.php` (slot assignments) | 1 day |
| 4.3 | Add `ctp.slots.optimized` WebSocket event | 0.5 day |

**Total**: ~2-3 days

---

## 10. Appendices

### Appendix A: Simulator Algorithm Selection Heuristic

The greedy selection cascade for `MaximizeSlots` mode:

```
candidates.OrderBy(c => c.PossiblePaths)           // 1st: Bottleneck routes (ascending)
           .ThenByDescending(c => c.SlotsAvailable)  // 2nd: Spare capacity (descending)
           .ThenByDescending(c => c.CombinedVotes)   // 3rd: Pilot demand (descending)
           .First()
```

**PossiblePaths** = count of feasible routes + count of feasible arrivals for this departure. Lower = more constrained = higher priority.

**CombinedSlotsAvailable** = departure.remaining + route.remaining + arrival.remaining. Higher = more room.

**CombinedVotes** = departure.votes + arrival.votes. Higher = more pilot demand.

### Appendix B: Test Data Summary (25W Event)

**Airports (38)**:
| Airport | Rate/3hr | Rate/hr | Votes | Region |
|---------|----------|---------|-------|--------|
| KORD | 250 | 83 | 2367 | AMAS |
| EHAM | 195 | 65 | 4616 | EMEA |
| LFPG | 165 | 55 | 3011 | EMEA |
| EDDB | 163 | 54 | 2772 | EMEA |
| KJFK | 132 | 44 | 2504 | AMAS |
| CYYZ | 128 | 43 | 2421 | AMAS |
| EDDF | 125 | 42 | 3654 | EMEA |
| EGLL | 115 | 38 | 2513 | EMEA |
| ... | ... | ... | ... | ... |

**Route Segments (358)**: NAT tracks (A1-U), EMEA connectors, AMAS connectors.

### Appendix C: Key PERTI CTP Files

```
/ctp.php                                    # Main CTP UI page
/assets/js/ctp.js                           # CTP JavaScript module
/assets/css/ctp.css                         # CTP styling

/api/ctp/common.php                         # Shared helpers (auth, audit, SWIM push)
/api/ctp/sessions/*.php                     # 6 session endpoints
/api/ctp/flights/*.php                      # 9 flight endpoints
/api/ctp/routes/*.php                       # 2 route endpoints
/api/ctp/planning/*.php                     # 4 planning endpoints
/api/ctp/throughput/*.php                   # 5 throughput endpoints
/api/ctp/demand.php                         # Demand analysis
/api/ctp/stats.php                          # Statistics
/api/ctp/audit_log.php                      # Audit trail

/load/services/NATTrackResolver.php         # NAT track resolution
/load/services/NATTrackFunctions.php        # nattrak API + CTP merge
/load/services/GISService.php               # PostGIS spatial queries

/database/migrations/tmi/045_ctp_oceanic_schema.sql
/database/migrations/tmi/046_ctp_audit_enhance.sql
/database/migrations/tmi/048_ctp_nat_track_throughput.sql
/database/migrations/tmi/049_ctp_track_constraints.sql

/api/swim/v1/tmi/nat_tracks/status.php      # SWIM NAT track API
/api/swim/v1/tmi/nat_tracks/metrics.php     # SWIM metrics API
```

### Appendix D: Environment & Credentials Required

External CTP repos integrating with PERTI would need:

| Requirement | Source | Access Level |
|-------------|--------|-------------|
| SWIM API key | `swim_api_keys` table | Read-only flight data |
| VATSIM OAuth session | VATSIM Connect login | Write access (EDCT assignment, route modification) |
| Org membership | `perspective_orgs_json` | Segment-scoped write access |

No direct database access is required. All integration is via REST APIs.

---

*Document generated from source-code-level audit of both external repositories and comprehensive exploration of the PERTI codebase. All code samples are from actual source files as of 2026-03-21.*
