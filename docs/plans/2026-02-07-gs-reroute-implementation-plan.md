# GS & Reroute Compliance Analysis - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enhance the TMI Compliance system to fully detect, parse, chain, and analyze Ground Stop (GS) and Reroute advisories as multi-advisory programs, and render comprehensive results in the JS frontend.

**Architecture:** ADVZY block parsing produces `GSProgram` / `RerouteProgram` objects (chains of advisories). The Python analyzer consumes programs instead of single TMIs. The JS frontend renders new card types for GS (rewritten with timeline + per-origin breakdown) and Reroute (new, with filed/flown split + route table).

**Tech Stack:** Python 3.x (dataclasses, re, datetime), PHP 8.2 (sqlsrv, regex), JavaScript (jQuery, Bootstrap 4, vanilla DOM)

**Worktree:** `C:/Temp/perti-worktrees/tmi-compliance-enhancements`
**Branch:** `feature/tmi-compliance-analysis-enhancements`
**Design doc:** `docs/plans/2026-02-07-gs-reroute-compliance-design.md`

---

## Task 1: Expand Compliance Enum and Add Reroute Constants

**Files:**
- Modify: `scripts/tmi_compliance/core/models.py:125-129` (Compliance enum)
- Modify: `scripts/tmi_compliance/core/models.py:140-144` (add reroute thresholds after spacing thresholds)

**Step 1: Expand the Compliance enum**

In `scripts/tmi_compliance/core/models.py`, replace the current 3-value `Compliance` enum with the 7-value version from the globalization DB schema:

```python
class Compliance(Enum):
    """Compliance status categories"""
    PENDING = 'PENDING'
    MONITORING = 'MONITORING'
    COMPLIANT = 'COMPLIANT'
    PARTIAL = 'PARTIAL'
    NON_COMPLIANT = 'NON-COMPLIANT'
    EXEMPT = 'EXEMPT'
    UNKNOWN = 'UNKNOWN'
```

**Step 2: Add reroute compliance thresholds**

After the `CROSSING_RADIUS_NM` constant (line 146), add:

```python
# Reroute compliance thresholds (fraction of required fixes matched)
REROUTE_COMPLIANT_THRESHOLD = 0.95    # 95%+ = COMPLIANT
REROUTE_PARTIAL_THRESHOLD = 0.50      # 50-94% = PARTIAL
                                       # <50% = NON_COMPLIANT
```

**Step 3: Verify existing code still works**

Search the analyzer for `Compliance.COMPLIANT`, `Compliance.EXEMPT`, `Compliance.NON_COMPLIANT` references. These existing values are unchanged, so no breakage. The value `'NON-COMPLIANT'` matches the existing string.

Run: `cd C:/Temp/perti-worktrees/tmi-compliance-enhancements && python -c "from scripts.tmi_compliance.core.models import Compliance, REROUTE_COMPLIANT_THRESHOLD; print('OK:', list(Compliance), REROUTE_COMPLIANT_THRESHOLD)"`

**Step 4: Commit**

```bash
git add scripts/tmi_compliance/core/models.py
git commit -m "feat(tmi): expand Compliance enum and add reroute thresholds"
```

---

## Task 2: Add GS Program Data Models

**Files:**
- Modify: `scripts/tmi_compliance/core/models.py` (append after `UserTMIDefinition` class, before `CrossingResult`)

**Step 1: Add GSAdvisory and GSProgram dataclasses**

Insert before the `CrossingResult` class (line ~577):

```python
@dataclass
class GSAdvisory:
    """A single Ground Stop advisory in a program chain"""
    advzy_number: str
    advisory_type: str              # 'INITIAL', 'EXTENSION', 'UPDATE', 'CNX'
    adl_time: Optional[datetime] = None  # When this advisory was issued
    gs_period_start: Optional[datetime] = None
    gs_period_end: Optional[datetime] = None
    cumulative_start: Optional[datetime] = None  # If CUMULATIVE PROGRAM PERIOD present
    cumulative_end: Optional[datetime] = None
    dep_facilities: List[str] = field(default_factory=list)
    dep_facility_tier: str = ''     # 1stTier, 2ndTier, Manual
    delay_prev: Optional[tuple] = None   # (total, max, avg)
    delay_new: Optional[tuple] = None
    prob_extension: str = ''
    impacting_condition: str = ''
    flt_incl: str = ''
    comments: str = ''
    raw_text: str = ''


@dataclass
class GSProgram:
    """
    A Ground Stop program — chain of related GS advisories for one airport.

    Lifecycle:
    1. CDM GROUND STOP (INITIAL) → creates program
    2. CDM GROUND STOP (EXTENSION/UPDATE) → extends or modifies
    3. CDM GS CNX (CANCELLATION) → closes program
    4. No CNX → program expires at last advisory's end time
    """
    airport: str                    # CTL ELEMENT (3-letter code)
    advisories: List[GSAdvisory] = field(default_factory=list)
    dep_facilities: List[str] = field(default_factory=list)  # Union of all DEP FACILITIES
    dep_facility_tier: str = ''     # Latest tier notation
    effective_start: Optional[datetime] = None  # Earliest start across all advisories
    effective_end: Optional[datetime] = None    # CNX time, or last advisory's end time
    ended_by: str = ''              # 'CNX', 'EXPIRATION'
    impacting_condition: str = ''   # Latest impacting condition
    prob_extension: str = ''        # Latest probability
    comments: List[str] = field(default_factory=list)  # All comments collected
    cnx_comments: str = ''          # CNX-specific comments (MIT/EDCT follow-up)

    def get_effective_window(self) -> tuple:
        """Return (start, end) effective window"""
        return (self.effective_start, self.effective_end)

    def is_cancelled(self) -> bool:
        return self.ended_by == 'CNX'
```

**Step 2: Verify import**

Run: `cd C:/Temp/perti-worktrees/tmi-compliance-enhancements && python -c "from scripts.tmi_compliance.core.models import GSProgram, GSAdvisory; print('OK')"`

**Step 3: Commit**

```bash
git add scripts/tmi_compliance/core/models.py
git commit -m "feat(tmi): add GSProgram and GSAdvisory data models"
```

---

## Task 3: Add Reroute Program Data Models

**Files:**
- Modify: `scripts/tmi_compliance/core/models.py` (append after GSProgram)

**Step 1: Add RouteEntry, RerouteAdvisory, and RerouteProgram dataclasses**

Insert after `GSProgram`:

```python
@dataclass
class RouteEntry:
    """A single route entry from a reroute advisory's route table"""
    origins: List[str] = field(default_factory=list)  # Airport/ARTCC codes
    destination: str = ''
    route_string: str = ''         # Full route with >fix< markers
    required_fixes: List[str] = field(default_factory=list)  # Extracted from >...< segment


@dataclass
class RerouteAdvisory:
    """A single Reroute advisory in a program chain"""
    advzy_number: str
    advisory_type: str       # 'INITIAL', 'UPDATE', 'EXTENSION', 'CANCELLATION'
    route_type: str = ''     # ROUTE, FEA, FCA, AFP, ICR
    action: str = ''         # RQD, RMD, PLN, FYI
    adl_time: Optional[datetime] = None
    valid_start: Optional[datetime] = None
    valid_end: Optional[datetime] = None
    time_type: str = ''      # 'ETD' or 'ETA'
    routes: List[RouteEntry] = field(default_factory=list)
    origins: List[str] = field(default_factory=list)
    destinations: List[str] = field(default_factory=list)
    facilities: List[str] = field(default_factory=list)
    modifications: str = ''  # e.g. "ARRIVAL CHANGED TO SNFLD3"
    replaces_advzy: str = '' # Extracted from "REPLACES ADVZY NNN"
    associated_restrictions: str = ''  # e.g. "20 MIT OVER BUGSY"
    prob_extension: str = ''
    exemptions: str = ''     # e.g. "AR AND Y ROUTES, Q75 EXEMPT"
    comments: str = ''
    raw_text: str = ''


@dataclass
class RerouteProgram:
    """
    A Reroute program — chain of related reroute advisories.

    Lifecycle:
    1. ROUTE RQD / ROUTE RMD / FEA FYI (INITIAL) → creates program
    2. "REPLACES ADVZY NNN" (UPDATE/EXTENSION) → chains to existing
    3. REROUTE CANCELLATION → closes program
    4. No cancellation → expires at last advisory's VALID end time

    Type/action can change mid-lifecycle (e.g., RQD → RMD).
    """
    name: str = ''                         # e.g. MCO_NO_GRNCH_PRICY
    tmi_id: str = ''                       # e.g. RRDCC506
    route_type: str = ''                   # ROUTE, FEA, FCA, AFP, ICR (latest)
    action: str = ''                       # RQD, RMD, PLN, FYI (latest from chain)
    advisories: List[RerouteAdvisory] = field(default_factory=list)
    constrained_area: str = ''             # e.g. ZJX
    reason: str = ''                       # e.g. WEATHER
    effective_start: Optional[datetime] = None  # From first advisory's VALID start
    effective_end: Optional[datetime] = None    # CNX time, or last advisory's VALID end
    ended_by: str = ''                     # 'CANCELLATION', 'EXPIRATION'
    current_routes: List[RouteEntry] = field(default_factory=list)  # From latest non-cancelled advisory
    origins: List[str] = field(default_factory=list)
    destinations: List[str] = field(default_factory=list)
    facilities: List[str] = field(default_factory=list)
    exemptions: str = ''                   # e.g. "AR AND Y ROUTES, Q75 EXEMPT"
    associated_restrictions: str = ''

    def is_mandatory(self) -> bool:
        """RQD is mandatory, everything else is not"""
        return self.action == 'RQD'

    def get_assessment_mode(self) -> str:
        """Determine analysis mode from action"""
        if self.action == 'RQD':
            return 'full_compliance'
        elif self.action == 'RMD':
            return 'usage_tracking'
        elif self.action == 'PLN':
            return 'future_planning'
        else:  # FYI
            return 'tracking_only'
```

**Step 2: Verify import**

Run: `cd C:/Temp/perti-worktrees/tmi-compliance-enhancements && python -c "from scripts.tmi_compliance.core.models import RerouteProgram, RerouteAdvisory, RouteEntry; print('OK')"`

**Step 3: Commit**

```bash
git add scripts/tmi_compliance/core/models.py
git commit -m "feat(tmi): add RerouteProgram, RerouteAdvisory, RouteEntry data models"
```

---

## Task 4: Parser — Add GS CNX Parsing and Skip NTML GS/Reroute Lines

**Files:**
- Modify: `scripts/tmi_compliance/core/ntml_parser.py:15-18` (imports)
- Modify: `scripts/tmi_compliance/core/ntml_parser.py:83-182` (classify_line — add CDM GS CNX)
- Modify: `scripts/tmi_compliance/core/ntml_parser.py:185-298` (parse_advzy_ground_stop — enhance)
- Add new function `parse_advzy_gs_cnx` after `parse_advzy_ground_stop`
- Modify: `scripts/tmi_compliance/core/ntml_parser.py:738-763` (main parse loop — skip NTML GS/reroute, dispatch GS CNX)

**Step 1: Update imports in ntml_parser.py**

Add new model imports to the import block at line 15-18:

```python
from .models import (
    TMI, TMIType, MITModifier, DelayEntry, DelayType, DelayTrend, HoldingStatus,
    AirportConfig, CancelEntry, TrafficDirection, TrafficFilter, AircraftType, AltitudeFilter,
    ComparisonOp, ThruType, ThruFilter, ScopeLogic, create_thru_filter, SkippedLine,
    GSProgram, GSAdvisory, RerouteProgram, RerouteAdvisory, RouteEntry
)
```

**Step 2: Add CDM GS CNX detection in classify_line**

In `classify_line()`, add a check before the generic `advzy_header` match (before line 108):

```python
    # ADVZY GS CNX header: "vATCSCC ADVZY 003 SFO/ZOA 12/07/2025 CDM GS CNX"
    if re.match(r'^vATCSCC\s+ADVZY\s+\d+', line, re.IGNORECASE):
        if 'CDM GS CNX' in line.upper() or 'GS CNX' in line.upper():
            return ("advzy_gs_cnx", {"line": line})
        return ("advzy_header", {"line": line})
```

This replaces the existing single check at line 108.

**Step 3: Enhance parse_advzy_ground_stop to extract more fields**

Rewrite `parse_advzy_ground_stop` (lines 185-298) to:
- Extract `CUMULATIVE PROGRAM PERIOD` (VATSIM format)
- Extract full `DEP FACILITIES INCLUDED` list (not just first 3-letter match)
- Extract `dep_facility_tier` (1stTier, 2ndTier, Manual)
- Extract `PROBABILITY OF EXTENSION`
- Extract `IMPACTING CONDITION`
- Extract `FLT INCL`
- Extract `PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS` / `NEW TOTAL, MAXIMUM, AVERAGE DELAYS`
- Extract multi-line `COMMENTS`
- Return a `GSAdvisory` object alongside the `TMI` (for program chaining)
- Extract the advisory number from the header line

The function signature changes to return `Tuple[Optional[TMI], Optional[GSAdvisory], int]`.

**Step 4: Create parse_advzy_gs_cnx function**

New function after `parse_advzy_ground_stop`:

```python
def parse_advzy_gs_cnx(lines: List[str], start_idx: int, event_start: datetime, event_end: datetime) -> Tuple[Optional[GSAdvisory], int]:
    """
    Parse a CDM GS CNX (Ground Stop Cancellation) advisory block.

    Format:
        vATCSCC ADVZY 003 SFO/ZOA 12/07/2025 CDM GS CNX
        CTL ELEMENT: SFO
        ELEMENT TYPE: APT
        ADL TIME: 0310Z
        GS CNX PERIOD: 07/0310 - 07/0330
        COMMENTS: ...

    Returns:
        Tuple of (GSAdvisory or None, lines consumed)
    """
```

This extracts: CTL ELEMENT, ADL TIME, GS CNX PERIOD, COMMENTS. Returns a `GSAdvisory` with `advisory_type='CNX'`.

**Step 5: Add NTML GS/Reroute skip logic to main parse loop**

In the main parse loop (around line 738-763), add explicit skip logic for NTML single-line GS and reroute entries. Before the existing ADVZY Ground Stop dispatch:

```python
        # === ADVZY-ONLY RULE: Skip NTML single-line GS and reroute entries ===
        # GS and reroutes come ONLY from multi-line ADVZY blocks.
        # NTML lines like "LAS GS (NCT) 0230Z-0315Z" must be ignored.
        if line_type in ('mit', 'stop', 'cfr', 'minit', 'unknown'):
            upper_line = line.upper()
            # Skip NTML GS lines (e.g., "LAS GS (NCT) 0230Z-0315Z")
            if re.search(r'\bGS\b', upper_line) and 'GROUND STOP' not in upper_line:
                logger.debug(f"Skipping NTML GS line (ADVZY-only): {line[:60]}...")
                i += 1
                continue
            # Skip NTML reroute lines
            if re.search(r'\bRE-?ROUTE\b|\bROUTE\s+RQD\b|\bFEA\s+FYI\b', upper_line):
                logger.debug(f"Skipping NTML reroute line (ADVZY-only): {line[:60]}...")
                i += 1
                continue
```

**Step 6: Add CDM GS CNX dispatch in main parse loop**

After the existing GS ADVZY dispatch (line 749), add:

```python
        # Handle ADVZY GS CNX (cancellation)
        if line_type == 'advzy_gs_cnx':
            cnx_advisory, lines_consumed = parse_advzy_gs_cnx(lines, i, event_start, event_end)
            if cnx_advisory:
                # Store for program chaining (handled after all lines parsed)
                gs_cnx_advisories.append(cnx_advisory)
                logger.debug(f"Parsed ADVZY GS CNX: {cnx_advisory.advzy_number}")
            i += lines_consumed
            continue
```

Initialize `gs_cnx_advisories = []` before the main loop.

**Step 7: Commit**

```bash
git add scripts/tmi_compliance/core/ntml_parser.py
git commit -m "feat(tmi): add GS CNX parsing and NTML GS/reroute skip logic"
```

---

## Task 5: Parser — Add GS Program Chaining

**Files:**
- Modify: `scripts/tmi_compliance/core/ntml_parser.py` (add `build_gs_programs` function and integrate into `parse_ntml_to_tmis`)

**Step 1: Create build_gs_programs function**

Add a new function that takes a list of parsed GS TMIs and GS CNX advisories, and chains them into `GSProgram` objects:

```python
def build_gs_programs(gs_tmis: List[TMI], gs_advisories: List[GSAdvisory],
                      gs_cnx_advisories: List[GSAdvisory]) -> List[GSProgram]:
    """
    Chain GS advisories into programs by airport.

    Logic:
    1. Group GS TMIs + advisories by airport (CTL ELEMENT)
    2. Sort by issued time within each airport
    3. First advisory → INITIAL, subsequent → EXTENSION/UPDATE
    4. Match CNX advisories by airport → close program
    5. No CNX → EXPIRATION at last advisory's end time
    """
```

**Step 2: Integrate into ParseResult**

Add `gs_programs: List[GSProgram]` and `reroute_programs: List[RerouteProgram]` fields to `ParseResult`:

```python
@dataclass
class ParseResult:
    tmis: List[TMI]
    skipped_lines: List[SkippedLine]
    gs_programs: List[GSProgram] = field(default_factory=list)
    reroute_programs: List[RerouteProgram] = field(default_factory=list)
```

**Step 3: Call build_gs_programs at end of parse_ntml_to_tmis**

After the main parse loop, before returning the `ParseResult`, call:

```python
    # Build GS programs from parsed TMIs and advisories
    gs_programs = build_gs_programs(
        [t for t in tmis if t.tmi_type == TMIType.GS],
        gs_advisories,  # collected during parsing
        gs_cnx_advisories
    )
```

**Step 4: Verify chaining works with example**

Run a quick test with the DEN VATSIM example from the design doc:
```
ADVZY 001: CDM GROUND STOP     GS PERIOD 0110Z-0140Z  CUMULATIVE 0110Z-0140Z
ADVZY 002: CDM GROUND STOP 002 GS PERIOD 0135Z-0210Z  CUMULATIVE 0110Z-0210Z
```

Expected: 1 GSProgram with 2 advisories, effective_start=0110Z, effective_end=0210Z, ended_by='EXPIRATION'.

**Step 5: Commit**

```bash
git add scripts/tmi_compliance/core/ntml_parser.py
git commit -m "feat(tmi): add GS program chaining logic"
```

---

## Task 6: Parser — Add Reroute ADVZY Parsing and Program Chaining

**Files:**
- Modify: `scripts/tmi_compliance/core/ntml_parser.py` (add `parse_advzy_reroute`, `parse_advzy_reroute_cancellation`, `build_reroute_programs`, update classify_line and main loop)

**Step 1: Add reroute ADVZY type detection in classify_line**

Update `classify_line` to detect reroute-specific headers:

```python
    # ADVZY reroute headers
    if re.match(r'^vATCSCC\s+ADVZY\s+\d+', line, re.IGNORECASE):
        upper = line.upper()
        if 'CDM GS CNX' in upper or 'GS CNX' in upper:
            return ("advzy_gs_cnx", {"line": line})
        if 'REROUTE CANCELLATION' in upper:
            return ("advzy_reroute_cnx", {"line": line})
        if 'GROUND STOP' in upper:
            return ("advzy_header", {"line": line})
        # ROUTE RQD, ROUTE RMD, FEA FYI, FCA RQD, etc.
        if any(kw in upper for kw in ['ROUTE RQD', 'ROUTE RMD', 'FEA FYI', 'FEA RQD',
                                       'FCA RQD', 'FCA RMD', 'ICR RQD']):
            return ("advzy_reroute", {"line": line})
        return ("advzy_header", {"line": line})
```

**Step 2: Create parse_advzy_reroute function**

New function that parses a full reroute ADVZY block and returns a `RerouteAdvisory`:

```python
def parse_advzy_reroute(lines: List[str], start_idx: int, event_start: datetime,
                         event_end: datetime) -> Tuple[Optional[TMI], Optional[RerouteAdvisory], int]:
    """
    Parse a reroute ADVZY block (ROUTE RQD, ROUTE RMD, FEA FYI, etc.)

    Extracts: NAME, CONSTRAINED AREA, REASON, INCLUDE TRAFFIC, FACILITIES,
    VALID time window, route table (ORIG/DEST/ROUTE with >fix< markers),
    TMI ID, REMARKS (including REPLACES ADVZY NNN), MODIFICATIONS,
    ASSOCIATED RESTRICTIONS, PROBABILITY OF EXTENSION, EXEMPTIONS.
    """
```

Key extraction patterns:
- Header → route_type + action (e.g., "ROUTE RQD" → route_type='ROUTE', action='RQD')
- `NAME:` → name
- `CONSTRAINED AREA:` → constrained_area
- `REASON:` → reason
- `INCLUDE TRAFFIC:` → time_type ('ETD'/'ETA'), origins/destinations
- `FACILITIES INCLUDED:` → facilities list
- `VALID:` → valid_start, valid_end (parsing "ETD HHMM-HHMM" or "ETA HHMM-HHMM")
- `TMI ID:` → tmi_id
- Route table: column-position detection from `ORIG DEST ROUTE` header, multi-line routes, `>fix<` marker extraction
- `REMARKS:` → check for "REPLACES ADVZY NNN" or "REPLACES/EXTENDS ADVZY NNN"
- `MODIFICATIONS:` → modifications text
- `ASSOCIATED RESTRICTIONS:` → associated_restrictions
- `EXEMPTIONS:` → exemptions text
- `PROBABILITY OF EXTENSION:` → prob_extension

**Step 3: Create parse_advzy_reroute_cancellation function**

```python
def parse_advzy_reroute_cancellation(lines: List[str], start_idx: int,
                                       event_start: datetime) -> Tuple[Optional[RerouteAdvisory], int]:
    """
    Parse a REROUTE CANCELLATION ADVZY block.

    Format:
        vATCSCC ADVZY 100 DCC 06/22/2025 REROUTE CANCELLATION
        [NAME] HAS BEEN CANCELLED ...
    """
```

**Step 4: Create build_reroute_programs function**

```python
def build_reroute_programs(reroute_tmis: List[TMI], reroute_advisories: List[RerouteAdvisory],
                            reroute_cnx_advisories: List[RerouteAdvisory]) -> List[RerouteProgram]:
    """
    Chain reroute advisories into programs by NAME or TMI ID.

    Logic:
    1. Group by NAME (primary) or TMI ID (fallback)
    2. Sort by advisory number within each group
    3. First → INITIAL, "REPLACES ADVZY NNN" → UPDATE/EXTENSION
    4. REROUTE CANCELLATION matching NAME → close
    5. No cancellation → EXPIRATION at last advisory's VALID end
    6. Update route_type and action to latest non-cancelled advisory
    """
```

**Step 5: Integrate into main parse loop**

Dispatch `advzy_reroute` and `advzy_reroute_cnx` line types, collect advisories, and call `build_reroute_programs` at end.

**Step 6: Commit**

```bash
git add scripts/tmi_compliance/core/ntml_parser.py
git commit -m "feat(tmi): add reroute ADVZY parsing and program chaining"
```

---

## Task 7: Parser — Update PHP tmi_config.php

**Files:**
- Modify: `api/analysis/tmi_config.php:424-540` (parse_advzy_block function)

**Step 1: Add CDM GS CNX detection**

In `parse_advzy_block` (line 432), add detection for GS CNX before existing GS check:

```php
if (stripos($header, 'CDM GS CNX') !== false || stripos($header, 'GS CNX') !== false) {
    $advzy_type = 'GS_CNX';
} elseif (stripos($header, 'GROUND STOP') !== false) {
```

**Step 2: Add REROUTE CANCELLATION detection**

```php
} elseif (stripos($header, 'REROUTE CANCELLATION') !== false) {
    $advzy_type = 'REROUTE_CNX';
```

**Step 3: Add more reroute type detection**

Expand the reroute detection to capture route_type and action:

```php
} elseif (preg_match('/\b(ROUTE|FEA|FCA|ICR)\s+(RQD|RMD|PLN|FYI)\b/i', $header, $typeMatch)) {
    $advzy_type = 'REROUTE';
    $tmi['route_type'] = strtoupper($typeMatch[1]);
    $tmi['action'] = strtoupper($typeMatch[2]);
    $is_mandatory = ($typeMatch[2] === 'RQD');
```

**Step 4: Add GS CNX field extraction**

For `$advzy_type === 'GS_CNX'`, extract: CTL ELEMENT, ADL TIME, GS CNX PERIOD, COMMENTS.

**Step 5: Add REROUTE CNX field extraction**

For `$advzy_type === 'REROUTE_CNX'`, extract the cancelled name from body text.

**Step 6: Enhance GS field extraction**

Add extraction for: CUMULATIVE PROGRAM PERIOD, full DEP FACILITIES list, PROBABILITY OF EXTENSION, IMPACTING CONDITION, delay stats, multi-line COMMENTS.

**Step 7: Enhance reroute field extraction**

Add extraction for: REMARKS (REPLACES ADVZY), MODIFICATIONS, ASSOCIATED RESTRICTIONS, EXEMPTIONS, route_type, action.

**Step 8: Add program chaining in PHP**

After parsing all blocks, add a `build_programs()` function that chains TMIs into programs (same logic as Python).

**Step 9: Commit**

```bash
git add api/analysis/tmi_config.php
git commit -m "feat(tmi): add GS CNX, reroute CNX, and program chaining to PHP parser"
```

---

## Task 8: Analyzer — Rewrite GS Compliance Analysis

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py:14-18` (imports)
- Modify: `scripts/tmi_compliance/core/analyzer.py:446-466` (dispatch to use programs)
- Modify: `scripts/tmi_compliance/core/analyzer.py:1686-1757` (_analyze_gs_compliance — rewrite)

**Step 1: Update imports**

Add `GSProgram, GSAdvisory` to the import block.

**Step 2: Add program-based analysis method**

Create `_analyze_gs_program(self, program: GSProgram) -> Optional[Dict]`:

```python
def _analyze_gs_program(self, program: GSProgram) -> Optional[Dict]:
    """
    Analyze Ground Stop compliance for a program (chain of advisories).

    Enhancements over old _analyze_gs_compliance:
    1. Uses effective window from program (not single TMI)
    2. Maps DEP FACILITIES to origin airports via facility hierarchy
    3. Uses OOOI times (atd_utc/off_utc) not just first_seen for departure detection
    4. Phase tracking - tags each flight with advisory phase
    5. Delay impact - calculates hold time for compliant flights
    6. Per-origin breakdown
    7. Not-in-scope flights (to GS airport but from unlisted facility)
    """
```

Key changes:
- Accept `GSProgram` instead of `TMI`
- Use `program.effective_start` / `program.effective_end`
- Map `program.dep_facilities` → airport list using existing `facility_hierarchy.py`
- Use `atd_utc` or `off_utc` from flight data (OOOI times), falling back to `first_seen`
- Add `NOT_IN_SCOPE` status for flights from unlisted facilities
- Add phase tracking: for each flight, determine which advisory phase it fell in
- Calculate hold time for compliant flights
- Return `program_timeline`, `per_origin_breakdown`, `avg_hold_time_min`, `phase_violations`, `cnx_comments`

**Step 3: Update dispatch logic**

Replace the GS dispatch block (lines 461-466) to use programs from ParseResult:

```python
# Ground Stop Analysis — use programs if available
gs_programs = getattr(self.event, 'gs_programs', [])
if gs_programs:
    for program in gs_programs:
        result = self._analyze_gs_program(program)
        if result:
            key = f"GS_{program.airport}"
            results['gs_results'][key] = result
else:
    # Fallback to old single-TMI approach
    for tmi in gs_tmis:
        result = self._analyze_gs_compliance(tmi)
        if result:
            key = f"GS_{tmi.provider}_{','.join(tmi.destinations)}_ALL"
            results['gs_results'][key] = result
```

**Step 4: Update EventConfig to carry programs**

In `models.py`, add to `EventConfig`:
```python
    gs_programs: List['GSProgram'] = field(default_factory=list)
    reroute_programs: List['RerouteProgram'] = field(default_factory=list)
```

**Step 5: Wire programs from ParseResult into EventConfig**

In the analysis API (`api/analysis/tmi_compliance.php` or wherever the Python script is invoked), pass the programs from the parser to the analyzer.

**Step 6: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py scripts/tmi_compliance/core/models.py
git commit -m "feat(tmi): rewrite GS compliance analysis to use program model"
```

---

## Task 9: Analyzer — Rewrite Reroute Compliance Analysis

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py:1759-2010` (_analyze_reroute_compliance — rewrite)
- Modify: `scripts/tmi_compliance/core/analyzer.py:468-473` (dispatch to use programs)
- Modify: `scripts/tmi_compliance/core/analyzer.py:2210-2256` (summary calculation)

**Step 1: Add program-based analysis method**

Create `_analyze_reroute_program(self, program: RerouteProgram) -> Optional[Dict]`:

```python
def _analyze_reroute_program(self, program: RerouteProgram) -> Optional[Dict]:
    """
    Analyze Reroute compliance for a program (chain of advisories).

    Enhancements over old _analyze_reroute_compliance:
    1. Action-based assessment mode (RQD=full, RMD=soft, PLN/FYI=tracking)
    2. Uses program's effective window and current routes
    3. Per-OD pair matching (match flight to its specific route)
    4. Required segment extraction from >fix< markers
    5. 95% threshold for COMPLIANT (not 50%)
    6. Expanded compliance statuses: COMPLIANT, PARTIAL, NON_COMPLIANT, EXEMPT
    7. Exemption handling from advisory text
    8. Sequence validation for fix order
    """
```

Key changes:
- Accept `RerouteProgram` instead of `TMI`
- Check `program.get_assessment_mode()`:
  - `'full_compliance'` (RQD): Full scoring with COMPLIANT/PARTIAL/NON_COMPLIANT
  - `'usage_tracking'` (RMD): Softer → COMPLIANT/PARTIAL/MONITORING
  - `'future_planning'` (PLN): All PENDING
  - `'tracking_only'` (FYI): All MONITORING
- Use `REROUTE_COMPLIANT_THRESHOLD` (0.95) and `REROUTE_PARTIAL_THRESHOLD` (0.50)
- Per-OD pair: for each flight, find matching route from `program.current_routes` based on origin/destination
- Validate fix sequence order (not just presence)
- Final status = worst of filed and flown
- Parse exemption text and check flight routes against exempt airways
- Output: `program_history`, `route_type`, `action`, `per_od_breakdown`, `filed_compliance_pct`, `flown_compliance_pct`, `exemption_text`, `exempted_flights`, `associated_restrictions`

**Step 2: Update reroute dispatch**

```python
# Reroute Analysis — use programs if available
reroute_programs = getattr(self.event, 'reroute_programs', [])
if reroute_programs:
    for program in reroute_programs:
        result = self._analyze_reroute_program(program)
        if result:
            key = program.name or f"REROUTE_{program.route_type}_{program.action}"
            results['reroute_results'][key] = result
else:
    # Fallback to old single-TMI approach
    for tmi in reroute_tmis:
        result = self._analyze_reroute_compliance(tmi)
        ...
```

**Step 3: Update summary calculation**

Update the summary block (lines 2210-2256) to handle expanded compliance statuses (PARTIAL, MONITORING) and the new program-level metadata.

**Step 4: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(tmi): rewrite reroute compliance analysis with program model and 95% threshold"
```

---

## Task 10: Frontend — Rewrite renderGsCard with Timeline + Per-Origin

**Files:**
- Modify: `assets/js/tmi_compliance.js:1631-1730` (renderGsCard — rewrite)

**Step 1: Rewrite renderGsCard**

Replace the existing `renderGsCard` function (lines 1631-1730+) with enhanced version:

```javascript
renderGsCard: function(r) {
    // Header: airport, facility, effective window, impacting condition, ended-by badge
    // Program timeline: visual Gantt bar showing initial + extensions + CNX
    // Stats row: Total, Exempt, Compliant, Violations, Not-in-Scope
    // Per-origin facility breakdown (collapsible)
    // Phase attribution for violations
    // Flight detail tables (collapsible)
    // Delay impact: average hold time
    // CNX comments (prominent)
}
```

Key UI elements:
- **Ended-by badge**: Green "CNX" or yellow "EXPIRED"
- **Timeline bar**: CSS flexbox showing advisory phases proportionally on time axis
- **Per-origin breakdown**: Collapsible section with table per DEP FACILITY showing compliance rate
- **Phase attribution**: Each violation tagged with which advisory phase
- **Avg hold time**: Stat box showing delay imposed on compliant flights
- **CNX comments**: Blue info box when present (often contains MIT/EDCT follow-up instructions)

Fallback: if `program_timeline` not present (old data format), render the existing simple view.

**Step 2: Update renderGsDetailV2 similarly**

The V2 progressive layout detail panel also needs the same enhancements.

**Step 3: Commit**

```bash
git add assets/js/tmi_compliance.js
git commit -m "feat(tmi): rewrite GS card with program timeline and per-origin breakdown"
```

---

## Task 11: Frontend — Create renderRerouteCard (New)

**Files:**
- Modify: `assets/js/tmi_compliance.js` (add new renderRerouteCard function)

**Step 1: Create renderRerouteCard function**

Add a new function after `renderGsCard`:

```javascript
renderRerouteCard: function(r) {
    // Header: route type + action badge (color-coded), name, constrained area,
    //         time window, reason, TMI ID
    // Action badge colors: RQD=red, RMD=yellow, FYI=blue, PLN=gray
    // Program history: advisory chain (collapsible)
    // Stats row: Total, Compliant, Partial, Non-Compliant, Exempt
    // Filed vs flown split: side-by-side compliance percentages
    // Route table: per OD pair with >required segment< highlighted
    // Flight detail table: callsign, origin, dest, filed %, flown %, final status
    // Exemption text (if present)
    // Associated restrictions (if present)
}
```

**Step 2: Create renderRerouteDetailV2 for progressive layout**

Corresponding function for the V2 detail panel.

**Step 3: Add action badge color utility**

```javascript
getActionBadgeClass: function(action) {
    switch ((action || '').toUpperCase()) {
        case 'RQD': return 'badge-danger';
        case 'RMD': return 'badge-warning';
        case 'FYI': return 'badge-info';
        case 'PLN': return 'badge-secondary';
        default: return 'badge-light';
    }
}
```

**Step 4: Commit**

```bash
git add assets/js/tmi_compliance.js
git commit -m "feat(tmi): create reroute compliance card renderer"
```

---

## Task 12: Frontend — Wire Reroute Cards into Results Layout

**Files:**
- Modify: `assets/js/tmi_compliance.js:589-598` (renderResults — add reroute section)
- Modify: `assets/js/tmi_compliance.js:5499-5543` (renderListPanelV2 — add reroute list items)
- Modify: `assets/js/tmi_compliance.js:5549-5618` (getAllTmisForList — add reroute entries)
- Modify: `assets/js/tmi_compliance.js:5699-5728` (renderDetailPanelV2 — add REROUTE dispatch)
- Modify: `assets/js/tmi_compliance.js:5507-5508` (filter grouping — add REROUTE to advisories)

**Step 1: Add reroute section to renderResults (classic layout)**

After the GS results section (line 598), before APREQ:

```javascript
        // Reroute Results
        const rerouteResults = this.results.reroute_results || {};
        const rerouteArray = Array.isArray(rerouteResults) ? rerouteResults : Object.values(rerouteResults);
        if (rerouteArray.length > 0) {
            html += '<h6 class="text-warning mb-3 mt-4"><i class="fas fa-route"></i> Reroutes</h6>';
            for (const r of rerouteArray) {
                html += this.renderRerouteCard(r);
            }
        }
```

**Step 2: Add reroute to getAllTmisForList (V2 layout)**

After the GS block (line 5598), before APREQ:

```javascript
        // Reroutes
        const rerouteResults = r.reroute_results || {};
        const rerouteArray = Array.isArray(rerouteResults) ? rerouteResults : Object.values(rerouteResults);
        rerouteArray.forEach((rr, i) => {
            const action = rr.action || (rr.mandatory ? 'RQD' : 'FYI');
            const routeType = rr.route_type || 'ROUTE';
            tmis.push({
                id: `reroute_${i}`,
                type: 'REROUTE',
                identifier: rr.name || 'Reroute',
                typeValue: `${routeType} ${action}`,
                metric: `${rr.total_flights || 0}`,
                metricValue: rr.total_flights || 0,
                nonCompliant: (rr.filed_non_compliant || []).length,
                startTime: rr.start,
                data: rr,
            });
        });
```

**Step 3: Add REROUTE to list grouping filter**

In `renderListPanelV2` (line 5507-5508), update the advisory filter:

```javascript
const advisories = allTmis.filter(t => ['GS', 'GDP', 'REROUTE'].includes(t.type));
```

**Step 4: Add REROUTE dispatch in renderDetailPanelV2**

In `renderDetailPanelV2` (line 5719-5725), add:

```javascript
        } else if (tmi.type === 'REROUTE') {
            html += this.renderRerouteDetailV2(data);
```

**Step 5: Commit**

```bash
git add assets/js/tmi_compliance.js
git commit -m "feat(tmi): wire reroute cards into both classic and progressive layouts"
```

---

## Task 13: Frontend — Add Reroute CSS Styles

**Files:**
- Modify: `assets/css/tmi-compliance.css`

**Step 1: Add reroute card styles**

```css
/* Reroute card styles */
.reroute-card { border-left: 3px solid #ffc107; }
.reroute-card .action-badge { font-weight: 600; font-size: 0.75rem; padding: 0.15rem 0.5rem; }
.reroute-card .route-table { font-family: monospace; font-size: 0.8rem; }
.reroute-card .route-table .required-segment { background: rgba(255, 193, 7, 0.15); font-weight: 600; }
.reroute-card .compliance-split { display: flex; gap: 1rem; }
.reroute-card .compliance-split .filed, .reroute-card .compliance-split .flown {
    flex: 1; text-align: center; padding: 0.5rem; border-radius: 4px;
}
.reroute-card .program-history { font-size: 0.8rem; }
```

**Step 2: Add GS timeline styles**

```css
/* GS program timeline */
.gs-timeline { display: flex; height: 24px; border-radius: 4px; overflow: hidden; margin: 0.5rem 0; }
.gs-timeline .phase { position: relative; min-width: 20px; }
.gs-timeline .phase-initial { background: #dc3545; }
.gs-timeline .phase-extension { background: #fd7e14; }
.gs-timeline .phase-cnx { background: #28a745; width: 4px; flex: 0 0 4px; }
.gs-per-origin { font-size: 0.85rem; }
.gs-cnx-comments { background: rgba(23, 162, 184, 0.1); border-left: 3px solid #17a2b8; padding: 0.5rem; margin-top: 0.5rem; font-size: 0.85rem; }
```

**Step 3: Commit**

```bash
git add assets/css/tmi-compliance.css
git commit -m "feat(tmi): add reroute and GS timeline CSS styles"
```

---

## Task 14: Integration — Update Python Analysis API Entry Point

**Files:**
- Modify: `api/analysis/tmi_compliance.php` (or whichever PHP file invokes the Python analyzer)
- Modify: `scripts/tmi_compliance/core/analyzer.py` entry point (the `analyze()` method setup)

**Step 1: Ensure ParseResult programs flow to EventConfig**

In the code that constructs `EventConfig` from the PHP-parsed TMI config, add:

```python
event.gs_programs = parse_result.gs_programs
event.reroute_programs = parse_result.reroute_programs
```

If the Python analyzer is called directly with raw NTML text, ensure it uses `parse_ntml_to_tmis` which now returns programs.

**Step 2: Test end-to-end data flow**

Verify that:
1. PHP saves config → Python reads config → parse_ntml_to_tmis → gs_programs/reroute_programs populated
2. Analyzer uses programs when available, falls back to TMI-level analysis
3. Results JSON contains enhanced GS and reroute data
4. JS frontend renders the enhanced cards

**Step 3: Commit**

```bash
git add api/analysis/tmi_compliance.php scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(tmi): wire program models through analysis API entry point"
```

---

## Task 15: Summary Header Updates

**Files:**
- Modify: `assets/js/tmi_compliance.js:5387-5497` (renderSummaryHeaderV2)
- Modify: `assets/js/tmi_compliance.js:420-430` (classic summary section)

**Step 1: Add reroute summary to V2 header**

The summary header should show reroute stats alongside GS and MIT:

```javascript
// Reroute summary card
const rr = summary.reroute || {};
if (rr.total_reroutes > 0) {
    html += `
        <div class="summary-card">
            <div class="label">Reroutes</div>
            <div class="value">${rr.total_reroutes}</div>
            <div class="detail">${rr.mandatory_count} mandatory, ${rr.total_flights} flights</div>
            <div class="compliance">Filed: ${rr.filed_compliance_pct}% | Flown: ${rr.flown_compliance_pct}%</div>
        </div>
    `;
}
```

**Step 2: Add reroute to classic summary**

In the classic layout summary area, add a reroute stat badge.

**Step 3: Commit**

```bash
git add assets/js/tmi_compliance.js
git commit -m "feat(tmi): add reroute summary to compliance headers"
```

---

## Task 16: End-to-End Validation

**Step 1: Test with GS lifecycle example**

Use the DCA 02/07/2026 GS lifecycle from the design doc. Paste the advisory chain into the NTML input and verify:
- Parser produces 1 GSProgram with 4 advisories (INITIAL, UPDATE, EXTENSION, CNX)
- Effective window: 0149Z → 0316Z
- ended_by: CNX
- GS card shows timeline with 3 phases + CNX point

**Step 2: Test with reroute lifecycle example**

Use the MCO 06/22/2025 reroute lifecycle. Verify:
- Parser produces 1 RerouteProgram with 3 advisories (INITIAL, UPDATE, CANCELLATION)
- Effective window: 1900Z → 2108Z (CNX time from ADVZY 100)
- Reroute card shows program history, route table, action badge

**Step 3: Test reroute type change**

Use the 10/14/2025 type change example (RQD → RMD). Verify:
- Single RerouteProgram with action updated to RMD
- Assessment mode changes from full_compliance to usage_tracking

**Step 4: Test NTML skip rules**

Paste mixed NTML content with both single-line GS references and ADVZY blocks. Verify:
- NTML single-line GS/reroute entries are skipped
- Only ADVZY blocks produce GS/reroute results

**Step 5: Test backward compatibility**

Run analysis with old-format data (no programs). Verify:
- Falls back to TMI-level analysis
- Existing GS card renders correctly
- No JS errors for missing reroute_results

**Step 6: Commit any fixes**

```bash
git add -A
git commit -m "fix(tmi): address issues found during end-to-end validation"
```

---

## Implementation Notes

### File Modification Summary

| File | Lines Changed (est.) | Nature |
|------|---------------------|--------|
| `scripts/tmi_compliance/core/models.py` | +120 | New dataclasses, expanded enum, thresholds |
| `scripts/tmi_compliance/core/ntml_parser.py` | +350, ~50 modified | New parse functions, chaining, NTML skip |
| `scripts/tmi_compliance/core/analyzer.py` | +250, ~80 modified | New program analysis methods, dispatch |
| `assets/js/tmi_compliance.js` | +300, ~100 modified | New renderRerouteCard, rewrite GS card, wiring |
| `api/analysis/tmi_config.php` | +100, ~30 modified | GS CNX, reroute CNX, enhanced extraction |
| `assets/css/tmi-compliance.css` | +40 | New reroute and GS timeline styles |

### Backward Compatibility

- Old `_analyze_gs_compliance(TMI)` and `_analyze_reroute_compliance(TMI)` are kept as fallbacks
- JS `renderGsCard` checks for `program_timeline` presence to decide enhanced vs simple rendering
- `ParseResult.__iter__` still works for `tmis, skipped = parse_result` unpacking

### Risk Areas

1. **Route table parsing** — The `>fix<` marker extraction and column-position detection are the most fragile parts. Test with diverse real-world examples.
2. **GS program chaining** — Edge case: two GS programs for the same airport on the same day that DON'T overlap (sequential, not extensions). Need clear separation logic based on time gap.
3. **Reroute chaining by name** — Name matching must be exact. Some advisories may use slightly different name formats.
4. **Facility hierarchy mapping** — `dep_facilities` → airport list requires the facility hierarchy cache to be loaded. Ensure graceful fallback.
