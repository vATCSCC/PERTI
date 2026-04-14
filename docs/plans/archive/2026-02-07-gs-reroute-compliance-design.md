# Ground Stop & Reroute Compliance Analysis Design

**Date:** 2026-02-07
**Branch:** `feature/tmi-compliance-analysis-enhancements`
**Based on:** `feature/corridor-analysis`

## Overview

Enhance the TMI Compliance system to fully detect, parse, and analyze Ground Stop (GS) and Reroute advisories. This is an end-to-end enhancement across parsing, analysis, and display layers.

### Core Rules

1. **GS and reroutes are ADVZY-block-only.** Any NTML single-line entries referencing GS or reroutes must be ignored.
2. **GS and reroutes are programs** — one or more advisories chained together by airport (GS) or name/TMI ID (reroute) to determine the actual active window.
3. **Advisory Actions** (RQD, RMD, PLN, FYI) determine the enforcement level / mandatory-ness.
4. **Route Types** (ROUTE, FEA, FCA, AFP, ICR, etc.) classify the kind of advisory.

---

## Section 1: Parsing Layer

### 1.1 NTML Line Filtering

Add explicit skip logic for GS and reroute NTML single-line entries:

```python
# In ntml_parser.py parse logic
if tmi_type == TMIType.GS and source == 'ntml_line':
    skip  # GS only from ADVZY blocks
if tmi_type == TMIType.REROUTE and source == 'ntml_line':
    skip  # Reroutes only from ADVZY blocks
```

Same filtering in PHP `tmi_config.php` — `parse_ntml_line()` should not produce GS or REROUTE TMIs.

### 1.2 Ground Stop Advisory Types

| Header Keyword | Advisory Type | Action |
|---|---|---|
| `CDM GROUND STOP` | GS Start / Extension | Create or extend GS program |
| `CDM GS CNX` | GS Cancellation | Close GS program |

**Extension detection:** A new `CDM GROUND STOP` advisory for the same airport where the period overlaps or extends the previous one is an extension, not a new program. The `CUMULATIVE PROGRAM PERIOD` field (when present) confirms this.

**Cancellation:** `CDM GS CNX` closes the program. The `GS CNX PERIOD` shows the actual active window. The effective end time becomes the CNX `ADL TIME`.

**Expiration:** If no CNX advisory arrives, the GS expires at its last advisory's `GROUND STOP PERIOD` end time.

### 1.3 GS Program Data Model

```python
@dataclass
class GSProgram:
    airport: str                    # CTL ELEMENT
    advisories: list[GSAdvisory]    # Ordered chain
    dep_facilities: list[str]       # Union of all DEP FACILITIES
    dep_facility_tier: str          # 1stTier, 2ndTier, Manual
    effective_start: datetime       # Earliest start across all advisories
    effective_end: datetime         # CNX time, or last advisory's end time
    ended_by: str                   # 'CNX', 'EXPIRATION'
    impacting_condition: str        # Latest impacting condition
    prob_extension: str             # Latest probability
    comments: list[str]             # All comments collected
    cnx_comments: str               # CNX-specific comments (MIT/EDCT follow-up)

@dataclass
class GSAdvisory:
    advzy_number: str
    advisory_type: str              # 'INITIAL', 'EXTENSION', 'UPDATE', 'CNX'
    adl_time: datetime              # When this advisory was issued
    gs_period_start: datetime
    gs_period_end: datetime
    cumulative_start: datetime      # If CUMULATIVE PROGRAM PERIOD present
    cumulative_end: datetime
    dep_facilities: list[str]       # DEP FACILITIES for this advisory
    dep_facility_tier: str          # Tier notation
    delay_prev: tuple               # (total, max, avg)
    delay_new: tuple
    prob_extension: str
    impacting_condition: str
    comments: str
```

**Fields to extract from GS Start/Extension:**
- `CTL ELEMENT` → airport
- `GROUND STOP PERIOD` → start/end times
- `CUMULATIVE PROGRAM PERIOD` → cumulative start/end (VATSIM format)
- `ADL TIME` → issued time
- `DEP FACILITIES INCLUDED` → ARTCC codes with tier notation (1stTier, 2ndTier, Manual)
- `FLT INCL` → flight inclusion criteria
- `PROBABILITY OF EXTENSION` → LOW/MEDIUM/HIGH
- `IMPACTING CONDITION` → cause text
- `PREVIOUS/NEW TOTAL, MAXIMUM, AVERAGE DELAYS` → delay stats
- `COMMENTS` → multi-line operational context

**Fields to extract from GS CNX:**
- `CTL ELEMENT` → airport (for matching to original GS)
- `ADL TIME` → when cancellation was published (becomes effective end)
- `GS CNX PERIOD` → actual active window
- `COMMENTS` → follow-up instructions (MIT, EDCT, reroute references)

**Chaining logic:**
1. First `CDM GROUND STOP` for an airport → create program, type=INITIAL
2. Subsequent `CDM GROUND STOP` for same airport where period overlaps or extends → chain as EXTENSION/UPDATE
3. `CDM GS CNX` for same airport → close program, effective_end = CNX `ADL TIME`
4. No CNX → program expires at last advisory's `GROUND STOP PERIOD` end time

### 1.4 Reroute Advisory Types

| Header Pattern | Route Type + Action | Advisory Type |
|---|---|---|
| `ROUTE RQD` | ROUTE + RQD | Initial / Update |
| `ROUTE RMD` | ROUTE + RMD | Initial / Update |
| `FEA FYI` | FEA + FYI | Initial / Update |
| `FCA RQD` | FCA + RQD | Initial / Update |
| `REROUTE CANCELLATION` | — | Cancellation |

**Update detection:** "REPLACES ADVZY NNN" or "REPLACES/EXTENDS ADVZY NNN" in comments/remarks. Same NAME or TMI ID links advisories.

**Cancellation:** Separate advisory with `REROUTE CANCELLATION` header. Body says `[NAME] HAS BEEN CANCELLED`.

**Type/action can change** mid-lifecycle (e.g., RQD → RMD).

### 1.5 Reroute Program Data Model

```python
@dataclass
class RerouteProgram:
    name: str                         # e.g. MCO_NO_GRNCH_PRICY
    tmi_id: str                       # e.g. RRDCC506
    route_type: str                   # ROUTE, FEA, FCA, AFP, ICR, etc.
    action: str                       # RQD, RMD, PLN, FYI (latest from chain)
    advisories: list[RerouteAdvisory] # Ordered chain
    constrained_area: str             # e.g. ZJX
    reason: str                       # e.g. WEATHER
    effective_start: datetime         # From first advisory's VALID start
    effective_end: datetime           # CNX time, or last advisory's VALID end
    ended_by: str                     # 'CANCELLATION', 'EXPIRATION'
    current_routes: list[RouteEntry]  # From latest non-cancelled advisory
    origins: list[str]                # Latest advisory's origins
    destinations: list[str]
    facilities: list[str]
    exemptions: str                   # e.g. "AR AND Y ROUTES, Q75 EXEMPT"

@dataclass
class RerouteAdvisory:
    advzy_number: str
    advisory_type: str       # 'INITIAL', 'UPDATE', 'EXTENSION', 'CANCELLATION'
    route_type: str          # ROUTE, FEA, FCA, AFP, ICR
    action: str              # RQD, RMD, PLN, FYI
    adl_time: datetime
    valid_start: datetime
    valid_end: datetime
    time_type: str           # 'ETD' or 'ETA'
    routes: list[RouteEntry]
    modifications: str       # e.g. "ARRIVAL CHANGED TO SNFLD3"
    replaces_advzy: str      # Extracted from "REPLACES ADVZY NNN"
    associated_restrictions: str  # e.g. "20 MIT OVER BUGSY"
    prob_extension: str
    comments: str

@dataclass
class RouteEntry:
    origins: list[str]       # Airport/ARTCC codes
    destination: str
    route_string: str        # Full route with >fix< markers
    required_fixes: list[str] # Extracted from >...< segment
```

**Chaining logic:**
1. First `ROUTE RQD/RMD/FEA/etc.` for a NAME → create program, type=INITIAL
2. Advisory with "REPLACES [ADVZY] NNN" matching same NAME or TMI ID → chain as UPDATE/EXTENSION
3. `REROUTE CANCELLATION` mentioning NAME → close program
4. No cancellation → program expires at last advisory's VALID end time

### 1.6 Route Table Parsing Improvements

Current parsing uses fragile spacing heuristics. Improve with:
- Column-position detection from the `ORIG  DEST  ROUTE` header line
- Robust `>fix fix fix<` marker extraction as `required_fixes` separate from full route
- Multi-destination route table support
- Continuation line handling for long routes

---

## Section 2: Analysis Layer

### 2.1 Expanded Compliance Enum

Align Python enum with DB schema from globalization branch:

```python
class Compliance(Enum):
    PENDING = 'PENDING'
    MONITORING = 'MONITORING'
    COMPLIANT = 'COMPLIANT'
    PARTIAL = 'PARTIAL'
    NON_COMPLIANT = 'NON_COMPLIANT'
    EXEMPT = 'EXEMPT'
    UNKNOWN = 'UNKNOWN'
```

### 2.2 Ground Stop Analysis (`_analyze_gs_compliance`)

**Input:** `GSProgram` (not a single TMI)

**Process:**

1. **Determine effective window** from program: `effective_start` → `effective_end`
2. **Map DEP FACILITIES to origin airports** using facility hierarchy (ARTCC → airports within that center that have flights to the GS destination)
3. **Query flights** destined to GS airport during the effective window
4. **Classify each flight:**

| Status | Condition |
|---|---|
| `EXEMPT` | Airborne before GS issued (use `atd_utc`/`off_utc` from OOOI, not just `first_seen`) |
| `COMPLIANT` | Held on ground during GS, departed after effective end |
| `NON_COMPLIANT` | Departed during active GS window from in-scope origin |
| `NOT_IN_SCOPE` | Flight to GS airport but from unlisted facility |

5. **Phase tracking** — tag each flight with which advisory phase it occurred in (initial, extension 1, extension 2...)
6. **Delay impact** — for compliant flights, calculate hold time (difference between planned departure and actual departure after GS ended)

**Output additions:**
- `program_timeline`: list of advisory phases with start/end times
- `per_origin_breakdown`: compliance stats grouped by origin facility
- `avg_hold_time_min`: average delay imposed on compliant flights
- `phase_violations`: violations broken down by program phase
- `cnx_comments`: cancellation follow-up text

### 2.3 Reroute Analysis (`_analyze_reroute_compliance`)

**Input:** `RerouteProgram` (uses latest active routes, respects cancellation timing)

**Process:**

1. **Determine assessment mode** from `action`:

| Action | Assessment | Statuses Used |
|---|---|---|
| `RQD` | Full compliance scoring | COMPLIANT, PARTIAL, NON_COMPLIANT, EXEMPT |
| `RMD` | Usage tracking, softer assessment | COMPLIANT, PARTIAL, MONITORING |
| `PLN` | No assessment (future) | PENDING |
| `FYI` | Tracking only | MONITORING |

2. **Query flights** matching origin/destination pairs during VALID window, filtered by `time_type` (ETD or ETA)
3. **Per-OD pair route matching** — match each flight to its specific route from the route table based on origin and destination
4. **Required segment extraction** — the `>fix fix fix<` portion is the compliance-critical segment
5. **Filed compliance check:**
   - Check if required fixes appear in filed route
   - Validate sequence order
   - 95%+ = COMPLIANT, 50-94% = PARTIAL, <50% = NON_COMPLIANT
6. **Flown compliance check:**
   - Load trajectory data
   - Calculate closest approach distance to each required fix
   - Fix counted as "crossed" if within crossing radius
   - Validate sequence order of crossings
   - Same thresholds: 95%+ / 50-94% / <50%
7. **Exemption handling** — parse exemption text (e.g., "AR AND Y ROUTES EXEMPT") and check flight routes against exempt airways
8. **Final status** = worst of filed and flown status (if filed COMPLIANT but flown PARTIAL → PARTIAL)

**Output additions:**
- `program_history`: advisory chain with changes
- `route_type`: ROUTE, FEA, FCA, etc.
- `action`: RQD, RMD, PLN, FYI
- `per_od_breakdown`: compliance stats per origin-destination pair
- `filed_compliance_pct` / `flown_compliance_pct`: separate percentages
- `exemption_text`: raw exemption string
- `exempted_flights`: flights matching exemption criteria
- `associated_restrictions`: linked MIT/other restrictions from advisory

### 2.4 Compliance Thresholds

```python
REROUTE_COMPLIANT_THRESHOLD = 0.95    # 95%+ = COMPLIANT
REROUTE_PARTIAL_THRESHOLD = 0.50      # 50-94% = PARTIAL
                                       # <50% = NON_COMPLIANT
```

---

## Section 3: Frontend Display

### 3.1 Ground Stop Card (`renderGsCard` — rewrite)

**Layout:**
- **Header:** Airport, facility, effective window, impacting condition, ended-by badge (CNX/EXPIRED)
- **Program timeline:** Visual Gantt bar showing initial + extensions + CNX point on a time axis
- **Stats row:** Total, Exempt, Compliant, Violations, Not-in-Scope (color-coded badges)
- **Per-origin facility breakdown:** Collapsible section showing compliance rate per DEP FACILITY
- **Phase attribution:** Each violation tagged with which advisory phase it occurred in
- **Flight detail tables:** Collapsible columns for Violations, Exempt, Compliant — each flight shows callsign, origin, departure time, time-into-GS, phase
- **Delay impact:** Average hold time for compliant flights
- **CNX comments:** Prominently displayed when present (often contains MIT/EDCT follow-up)

### 3.2 Reroute Card (`renderRerouteCard` — new function)

**Layout:**
- **Header:** Route type + action badge (color-coded: RQD=red, RMD=yellow, FYI=blue, PLN=gray), name, constrained area, time window, reason, TMI ID
- **Program history:** Advisory chain showing what changed (collapsible)
- **Stats row:** Total, Compliant, Partial, Non-Compliant, Exempt (using expanded status set)
- **Filed vs flown split:** Separate compliance percentages displayed side by side
- **Route table:** Required routes per OD pair with `>required segment<` highlighted
- **Flight detail table:** Per-flight with callsign, origin, destination, filed %, flown %, final status
- **Exemption text:** Displayed prominently when present
- **Associated restrictions:** Linked MIT/other restrictions from advisory

### 3.3 Integration into Results Layout

The existing `renderResults()` / `renderProgressiveLayout()` currently renders:
1. MIT/MINIT cards
2. GS cards
3. APREQ cards

Add reroute cards after GS cards:
1. MIT/MINIT cards
2. GS cards (enhanced)
3. **Reroute cards (new)**
4. APREQ cards

---

## Section 4: Implementation Phases

### Phase 1: Parsing & Data Models
- Add `GSProgram`, `GSAdvisory`, `RerouteProgram`, `RerouteAdvisory`, `RouteEntry` to `models.py`
- Update `ntml_parser.py`: skip NTML lines for GS/reroute, parse CDM GS CNX blocks, improve reroute ADVZY parsing, build program chains
- Update PHP `tmi_config.php` in parallel with same logic
- Expand `Compliance` enum

### Phase 2: Analysis Engine
- Rewrite `_analyze_gs_compliance()` to accept `GSProgram`, add scope filtering, phase tracking, delay impact
- Rewrite `_analyze_reroute_compliance()` to accept `RerouteProgram`, add action-based assessment, sequence validation, 95% threshold, exemption handling

### Phase 3: Frontend Display
- Rewrite `renderGsCard()` with timeline, per-origin breakdown, phase attribution
- Create `renderRerouteCard()` from scratch
- Wire reroute rendering into `renderResults()` / `renderProgressiveLayout()`

### Phase 4: Testing & Validation
- Test with real VATSIM advisory examples (GS start, extension, CNX sequences)
- Test with reroute lifecycle examples (initial, update, cancellation)
- Validate compliance calculations against known outcomes

---

## Reference: Advisory Examples

### GS Lifecycle (DCA 02/07/2026)
```
ADVZY 017: CDM GROUND STOP     GS PERIOD 0149Z-0300Z  (initial)
ADVZY 018: CDM GROUND STOP     GS PERIOD 0205Z-0300Z  (update - remarks change)
ADVZY 020: CDM GROUND STOP     GS PERIOD 0231Z-0400Z  (extension)
ADVZY 023: CDM GS CNX          ADL TIME 0316Z          (cancellation)
Effective: 0149Z → 0316Z
```

### GS Lifecycle (DEN VATSIM 11/09/2024)
```
ADVZY 001: CDM GROUND STOP     GS PERIOD 0110Z-0140Z  CUMULATIVE 0110Z-0140Z
ADVZY 002: CDM GROUND STOP 002 GS PERIOD 0135Z-0210Z  CUMULATIVE 0110Z-0210Z (extension)
Effective: 0110Z → 0210Z (expired, no CNX)
```

### GS CNX Format (VATSIM)
```
vATCSCC ADVZY 003 SFO/ZOA 12/07/2025 CDM GS CNX
CTL ELEMENT: SFO
ELEMENT TYPE: APT
ADL TIME: 0310Z
GS CNX PERIOD: 07/0310 - 07/0330
COMMENTS:
```

### Reroute Lifecycle (MCO 06/22/2025)
```
ADVZY 075: ROUTE RQD  MCO_NO_GRNCH_PRICY  ETD 1900-2200  GTOUT1 arrival
ADVZY 076: ROUTE RQD  "REPLACES ADVZY 075"  arrival changed to SNFLD3
ADVZY 100: REROUTE CANCELLATION  "MCO_NO_GRNCH_PRICY HAS BEEN CANCELLED"
Effective: 1900Z → 2108Z
```

### Reroute Extension (DFW 05/22/2025)
```
ADVZY 047: ROUTE RQD  DFW_SEEVR_1_SOUTH_FLOW_MODIFIED  ETD 1715-2100
ADVZY 064: ROUTE RQD  "REPLACES/EXTENDS ADVZY 047"  ETD 1715-2300, routes modified
Effective: 1715Z → 2300Z
```

### Reroute Type Change (10/14/2025)
```
ADVZY 060: ROUTE RQD  CAN_AGLIN_WEST_3_PARTIAL  ETD 1500-2000
ADVZY 063: ROUTE RMD  "REPLACES ADVZY 060"  (changed from required to recommended)
ADVZY 093: ROUTE RMD  "REPLACES ADVZY 063"  ETD 1500-2300 (extended)
Effective: 1500Z → 2300Z, action changed RQD→RMD
```
