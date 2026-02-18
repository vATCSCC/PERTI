# Holding Pattern Detection Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Detect holding patterns from flight trajectory data during TMI compliance analysis, surface as compliance metrics, interactive map visualization with orbit highlights and zone markers, and attribute delay to TMI programs.

**Architecture:** Hybrid turn-rate + spatial validation algorithm runs event-wide in the Python analyzer after trajectory preload. Fix matching prioritizes route waypoints over arbitrary nav fixes. Results flow through existing PHP API to JS frontend which renders orbit highlights (magenta trajectory segments), aggregate zone markers, and a holding summary panel.

**Tech Stack:** Python 3 (analyzer), PHP 8.2 (API), Vanilla JS + MapLibre GL (frontend), Azure SQL (trajectory data), CSS

**Worktree:** `C:/Temp/perti-worktrees/holding-detection` on branch `feature/holding-detection`

**Design doc:** `docs/plans/2026-02-16-holding-detection-design.md`

---

## Task 1: Data Models & Constants

**Files:**
- Modify: `scripts/tmi_compliance/core/models.py` (after line 337, before `AirportConfig`)

**Step 1: Add holding constants after the existing threshold constants**

After the `CROSSING_RADIUS_NM` constant (search for it in models.py), add:

```python
# Holding pattern detection thresholds
HOLD_MIN_HEADING_CHANGE_DEG = 270     # Min cumulative turn for one orbit
HOLD_MIN_DURATION_SEC = 120           # 2 min minimum hold duration
HOLD_MAX_RADIUS_NM = 5.0             # Max spatial containment radius
HOLD_FIX_MATCH_RADIUS_NM = 5.0       # Fix search radius for matching
HOLD_CIRCLING_ALT_AGL_FT = 2000      # Exclude circling approaches below this AGL
HOLD_CIRCLING_DIST_NM = 5.0          # Exclude approaches within this of destination
HOLD_GAP_RESET_SEC = 180             # Reset heading accumulator if gap > 3 min
HOLD_LOW_CONFIDENCE_INTERVAL_SEC = 120  # Flag if avg data interval sparser than this
```

**Step 2: Add HoldingEvent dataclass after DelayEntry (line ~337)**

```python
@dataclass
class HoldingEvent:
    """A detected holding pattern from trajectory analysis."""
    callsign: str
    flight_uid: int
    hold_start_utc: datetime
    hold_end_utc: datetime
    duration_sec: int
    orbit_count: int                         # Complete 360-degree turns
    center_lat: float
    center_lon: float
    avg_radius_nm: float
    avg_altitude_ft: float
    avg_groundspeed_kts: float
    turn_direction: str                      # 'R' or 'L'
    matched_fix: Optional[str] = None        # From fix matching
    fix_match_source: Optional[str] = None   # 'route', 'star', 'navfix'
    fix_distance_nm: float = 0.0
    fix_on_route: bool = False
    ntml_corroborated: bool = False
    low_confidence: bool = False
    dept: str = ''
    dest: str = ''
    tmi_attribution: Optional[str] = None    # 'gs', 'gdp', 'mit', or None
    tmi_program_id: Optional[str] = None     # ID of attributed TMI program

    @property
    def duration_min(self) -> float:
        return self.duration_sec / 60.0
```

**Step 3: Add HoldingFixSummary dataclass after HoldingEvent**

```python
@dataclass
class HoldingFixSummary:
    """Aggregate holding statistics at a single fix/location."""
    fix_name: Optional[str]
    center: list                             # [lon, lat]
    flight_count: int
    total_orbits: int
    avg_duration_sec: float
    peak_concurrent: int                     # Max flights holding simultaneously
    ntml_corroborated: bool
    time_range: list                         # [start_utc_iso, end_utc_iso]
    events: list                             # List of HoldingEvent dicts
```

**Step 4: Commit**

```bash
git add scripts/tmi_compliance/core/models.py
git commit -m "feat(holding): add HoldingEvent data models and detection constants"
```

---

## Task 2: Core Detection Algorithm

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py` (add module-level functions after `classify_facility()` at line ~223)

**Step 1: Add heading delta helper function**

Insert after `classify_facility()` (around line 223), before the class definition:

```python
def _heading_delta(h1: float, h2: float) -> float:
    """Signed heading change from h1 to h2, handling 360-degree wrap.
    Positive = clockwise (right turn), Negative = counter-clockwise (left turn).
    Returns value in range (-180, 180]."""
    d = (h2 - h1) % 360
    if d > 180:
        d -= 360
    return d
```

**Step 2: Add the main per-flight holding detection function**

```python
def detect_flight_holding(trajectory: list, dest_lat: float = None, dest_lon: float = None) -> list:
    """Detect holding patterns in a single flight's trajectory.

    Args:
        trajectory: List of point dicts with keys: timestamp, lat, lon, gs, alt
        dest_lat/dest_lon: Destination airport coords for circling approach filter

    Returns:
        List of dicts, each describing one holding event with keys:
        hold_start_utc, hold_end_utc, duration_sec, orbit_count,
        center_lat, center_lon, avg_radius_nm, avg_altitude_ft,
        avg_groundspeed_kts, turn_direction, low_confidence, point_indices
    """
    from .models import (HOLD_MIN_HEADING_CHANGE_DEG, HOLD_MIN_DURATION_SEC,
                         HOLD_MAX_RADIUS_NM, HOLD_GAP_RESET_SEC,
                         HOLD_CIRCLING_DIST_NM, HOLD_LOW_CONFIDENCE_INTERVAL_SEC)

    if len(trajectory) < 4:
        return []

    # Step 1: Build heading series between consecutive points
    headings = []
    for i in range(len(trajectory) - 1):
        p1, p2 = trajectory[i], trajectory[i + 1]
        dt = (p2['timestamp'] - p1['timestamp']).total_seconds()
        if dt <= 0 or dt > HOLD_GAP_RESET_SEC:
            headings.append({'idx': i, 'heading': None, 'dt': dt, 'gap': True})
            continue
        bearing = calculate_bearing(p1['lat'], p1['lon'], p2['lat'], p2['lon'])
        headings.append({'idx': i, 'heading': bearing, 'dt': dt, 'gap': False})

    # Step 2: Scan for cumulative heading change exceeding 360 degrees
    holding_events = []
    cum_heading = 0.0
    orbit_start_idx = None
    orbit_count = 0
    turn_sign_sum = 0.0  # Track dominant turn direction

    for i, h in enumerate(headings):
        if h['gap'] or h['heading'] is None:
            # Gap detected - check if we have a valid hold so far
            if orbit_count >= 1 and orbit_start_idx is not None:
                _finalize_hold(trajectory, headings, orbit_start_idx, i,
                               orbit_count, turn_sign_sum, holding_events,
                               dest_lat, dest_lon)
            cum_heading = 0.0
            orbit_start_idx = None
            orbit_count = 0
            turn_sign_sum = 0.0
            continue

        if orbit_start_idx is None:
            orbit_start_idx = i

        # Calculate heading delta to next heading (if available)
        if i + 1 < len(headings) and headings[i + 1]['heading'] is not None:
            delta = _heading_delta(h['heading'], headings[i + 1]['heading'])
        elif i > 0 and headings[i - 1]['heading'] is not None:
            delta = _heading_delta(headings[i - 1]['heading'], h['heading'])
        else:
            continue

        cum_heading += delta
        turn_sign_sum += delta

        # Check for orbit completion
        if abs(cum_heading) >= HOLD_MIN_HEADING_CHANGE_DEG:
            orbit_count += 1
            cum_heading = cum_heading % (360 if cum_heading > 0 else -360)

    # Finalize any remaining hold at end of trajectory
    if orbit_count >= 1 and orbit_start_idx is not None:
        _finalize_hold(trajectory, headings, orbit_start_idx, len(headings) - 1,
                       orbit_count, turn_sign_sum, holding_events,
                       dest_lat, dest_lon)

    return holding_events
```

**Step 3: Add the hold finalization helper**

```python
def _finalize_hold(trajectory, headings, start_idx, end_idx, orbit_count,
                   turn_sign_sum, holding_events, dest_lat, dest_lon):
    """Validate and finalize a candidate holding event."""
    from .models import (HOLD_MIN_DURATION_SEC, HOLD_MAX_RADIUS_NM,
                         HOLD_CIRCLING_DIST_NM, HOLD_LOW_CONFIDENCE_INTERVAL_SEC)

    # Map heading indices back to trajectory indices
    t_start = headings[start_idx]['idx']
    t_end = min(headings[end_idx]['idx'] + 1, len(trajectory) - 1)

    hold_points = trajectory[t_start:t_end + 1]
    if len(hold_points) < 3:
        return

    # Duration check
    duration = (hold_points[-1]['timestamp'] - hold_points[0]['timestamp']).total_seconds()
    if duration < HOLD_MIN_DURATION_SEC:
        return

    # Spatial containment check - compute centroid and verify radius
    center_lat = sum(p['lat'] for p in hold_points) / len(hold_points)
    center_lon = sum(p['lon'] for p in hold_points) / len(hold_points)

    distances = [haversine_nm(center_lat, center_lon, p['lat'], p['lon'])
                 for p in hold_points]
    max_dist = max(distances)
    avg_radius = sum(distances) / len(distances)

    if max_dist > HOLD_MAX_RADIUS_NM:
        return

    # Circling approach filter
    if dest_lat is not None and dest_lon is not None:
        dist_to_dest = haversine_nm(center_lat, center_lon, dest_lat, dest_lon)
        avg_alt = sum(p['alt'] for p in hold_points if p['alt']) / max(
            sum(1 for p in hold_points if p['alt']), 1)
        if dist_to_dest < HOLD_CIRCLING_DIST_NM and avg_alt < 2000:
            return

    # Compute hold metrics
    avg_alt = sum(p['alt'] for p in hold_points if p['alt']) / max(
        sum(1 for p in hold_points if p['alt']), 1)
    gs_points = [p['gs'] for p in hold_points if p.get('gs_valid', p['gs'] > 0)]
    avg_gs = sum(gs_points) / len(gs_points) if gs_points else 0

    turn_direction = 'R' if turn_sign_sum > 0 else 'L'

    # Low confidence check
    avg_interval = duration / max(len(hold_points) - 1, 1)
    low_confidence = avg_interval > HOLD_LOW_CONFIDENCE_INTERVAL_SEC

    holding_events.append({
        'hold_start_utc': hold_points[0]['timestamp'],
        'hold_end_utc': hold_points[-1]['timestamp'],
        'duration_sec': int(duration),
        'orbit_count': orbit_count,
        'center_lat': center_lat,
        'center_lon': center_lon,
        'avg_radius_nm': round(avg_radius, 2),
        'avg_altitude_ft': round(avg_alt),
        'avg_groundspeed_kts': round(avg_gs),
        'turn_direction': turn_direction,
        'low_confidence': low_confidence,
        'point_indices': (t_start, t_end),
    })
```

**Step 4: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(holding): add core turn-rate + spatial holding detection algorithm"
```

---

## Task 3: Fix Matching with Route Waypoints

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py` (new instance methods in TMIComplianceAnalyzer, around line ~1405)

**Step 1: Add waypoint loading method to TMIComplianceAnalyzer**

Insert after `_detect_boundary_crossings()` (around line 1403):

```python
def _load_flight_waypoints(self, flight_uids: list) -> dict:
    """Load route waypoints for a batch of flights.
    Returns dict: {flight_uid: [{fix_name, lat, lon, sequence_num}, ...]}
    """
    if not flight_uids:
        return {}

    placeholders = ','.join(['%s'] * len(flight_uids))
    query = self.adl.format_query(
        f"""SELECT flight_uid, fix_name, lat, lon, sequence_num
            FROM dbo.adl_flight_waypoints
            WHERE flight_uid IN ({placeholders})
            AND fix_name IS NOT NULL AND lat IS NOT NULL
            ORDER BY flight_uid, sequence_num"""
    )
    cursor = self.adl_conn.cursor()
    cursor.execute(query, tuple(flight_uids))

    waypoints = {}
    for row in cursor.fetchall():
        uid = row[0]
        if uid not in waypoints:
            waypoints[uid] = []
        waypoints[uid].append({
            'fix_name': row[1],
            'lat': float(row[2]),
            'lon': float(row[3]),
            'sequence_num': row[4]
        })
    return waypoints
```

**Step 2: Add STAR fix loading method**

```python
def _load_star_fixes(self, dest_icao: str) -> list:
    """Load all fixes on published STARs for a destination airport.
    Returns list of {fix_name, lat, lon}.
    """
    query = self.adl.format_query(
        """SELECT DISTINCT nf.fix_name, nf.lat, nf.lon
           FROM dbo.nav_procedure_legs npl
           JOIN dbo.nav_procedures np ON npl.procedure_id = np.procedure_id
           JOIN dbo.nav_fixes nf ON npl.fix_name = nf.fix_name
           WHERE np.airport_icao = %s AND np.procedure_type = 'STAR'
           AND nf.lat IS NOT NULL"""
    )
    cursor = self.adl_conn.cursor()
    cursor.execute(query, (dest_icao,))
    return [{'fix_name': r[0], 'lat': float(r[1]), 'lon': float(r[2])}
            for r in cursor.fetchall()]
```

**Step 3: Add fix matching method**

```python
def _match_holding_fix(self, event: dict, flight_uid: int, dest_icao: str,
                       flight_waypoints: dict, star_cache: dict) -> dict:
    """Match a holding event's center to the best fix.
    Priority: route waypoints > STAR fixes > any nav_fix.

    Modifies event dict in place, adding:
    matched_fix, fix_match_source, fix_distance_nm, fix_on_route
    """
    from .models import HOLD_FIX_MATCH_RADIUS_NM

    center_lat = event['center_lat']
    center_lon = event['center_lon']
    best_fix = None
    best_dist = HOLD_FIX_MATCH_RADIUS_NM
    best_source = None

    # Priority 1: Flight's own route waypoints
    wps = flight_waypoints.get(flight_uid, [])
    for wp in wps:
        d = haversine_nm(center_lat, center_lon, wp['lat'], wp['lon'])
        if d < best_dist:
            best_fix = wp['fix_name']
            best_dist = d
            best_source = 'route'

    # Priority 2: STAR fixes for destination (only if no route match within 3nm)
    if best_source != 'route' or best_dist > 3.0:
        if dest_icao not in star_cache:
            star_cache[dest_icao] = self._load_star_fixes(dest_icao)
        for sf in star_cache[dest_icao]:
            d = haversine_nm(center_lat, center_lon, sf['lat'], sf['lon'])
            if d < best_dist:
                best_fix = sf['fix_name']
                best_dist = d
                best_source = 'star'

    # Priority 3: Any nav_fix (fallback)
    if best_source is None:
        # Use preloaded fix_coords if available, else query
        for fix_name, coords in self.fix_coords.items():
            d = haversine_nm(center_lat, center_lon, coords['lat'], coords['lon'])
            if d < best_dist:
                best_fix = fix_name
                best_dist = d
                best_source = 'navfix'

        # If fix_coords doesn't cover the area, do a targeted query
        if best_source is None:
            nearby = self._query_nearby_fixes(center_lat, center_lon,
                                              HOLD_FIX_MATCH_RADIUS_NM)
            for nf in nearby:
                d = haversine_nm(center_lat, center_lon, nf['lat'], nf['lon'])
                if d < best_dist:
                    best_fix = nf['fix_name']
                    best_dist = d
                    best_source = 'navfix'

    event['matched_fix'] = best_fix
    event['fix_match_source'] = best_source
    event['fix_distance_nm'] = round(best_dist, 2) if best_fix else 0
    event['fix_on_route'] = (best_source == 'route')
    return event
```

**Step 4: Add nearby fix query helper**

```python
def _query_nearby_fixes(self, lat: float, lon: float, radius_nm: float) -> list:
    """Query nav_fixes within a bounding box around a point."""
    # Approximate degree offset for bounding box (~60nm per degree lat)
    deg_offset = radius_nm / 60.0
    query = self.adl.format_query(
        """SELECT fix_name, lat, lon FROM dbo.nav_fixes
           WHERE lat BETWEEN %s AND %s AND lon BETWEEN %s AND %s"""
    )
    cursor = self.adl_conn.cursor()
    cursor.execute(query, (lat - deg_offset, lat + deg_offset,
                           lon - deg_offset, lon + deg_offset))
    return [{'fix_name': r[0], 'lat': float(r[1]), 'lon': float(r[2])}
            for r in cursor.fetchall()]
```

**Step 5: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(holding): add fix matching with route waypoint priority"
```

---

## Task 4: NTML Correlation & Delay Attribution

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py` (new methods in TMIComplianceAnalyzer)

**Step 1: Add NTML holding correlation method**

```python
def _correlate_ntml_holding(self, holding_events: list, delay_entries: list) -> None:
    """Cross-reference detected holds with NTML +Holding entries.
    Modifies holding_events in place, setting ntml_corroborated=True when matched.
    """
    from .models import HoldingStatus

    ntml_holds = [d for d in delay_entries
                  if d.holding_status == HoldingStatus.HOLDING and d.holding_fix]

    if not ntml_holds:
        return

    for event in holding_events:
        if not event.get('matched_fix'):
            continue
        for ntml in ntml_holds:
            if ntml.holding_fix.upper() == event['matched_fix'].upper():
                # Check time overlap: NTML entry time should be within hold window
                # NTML entries have timestamp but not always precise start/end
                # Consider corroborated if same fix name matches
                event['ntml_corroborated'] = True
                break
```

**Step 2: Add TMI delay attribution method**

```python
def _attribute_holding_to_tmi(self, holding_events: list, event_config) -> None:
    """Attribute each holding event to the most likely TMI program.
    Modifies events in place, setting tmi_attribution and tmi_program_id.

    Priority: GS > GDP > MIT (based on likelihood of causing holds).
    """
    for hold in holding_events:
        hold_start = hold['hold_start_utc']
        hold_end = hold['hold_end_utc']
        dest = hold.get('dest', '')
        center_lat = hold['center_lat']
        center_lon = hold['center_lon']

        # Check Ground Stops first (strongest signal)
        for gs in getattr(event_config, 'gs_programs', []):
            if not gs.advisories:
                continue
            gs_dest = gs.airport
            if dest and dest.upper().endswith(gs_dest.upper()):
                # Check time overlap
                gs_start = gs.advisories[0].start_utc if gs.advisories else None
                gs_end = gs.advisories[-1].end_utc if gs.advisories else None
                if gs_start and gs_end and hold_start <= gs_end and hold_end >= gs_start:
                    hold['tmi_attribution'] = 'gs'
                    hold['tmi_program_id'] = f"GS_{gs_dest}"
                    break

        if hold.get('tmi_attribution'):
            continue

        # Check MIT programs (hold near measurement fix)
        for tmi in getattr(event_config, 'tmis', []):
            if tmi.tmi_type.name not in ('MIT', 'MINIT'):
                continue
            fix_name = tmi.fix if hasattr(tmi, 'fix') else tmi.measurement_point
            if hold.get('matched_fix') and fix_name:
                if hold['matched_fix'].upper() == fix_name.upper():
                    # Verify time overlap
                    if (tmi.start_utc and tmi.end_utc and
                            hold_start <= tmi.end_utc and hold_end >= tmi.start_utc):
                        hold['tmi_attribution'] = 'mit'
                        hold['tmi_program_id'] = f"MIT_{fix_name}"
                        break

        # If still unattributed, it stays None (unattributed)
```

**Step 3: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(holding): add NTML correlation and TMI delay attribution"
```

---

## Task 5: Wire Into Analysis Pipeline

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py` (modify `__init__`, `analyze()`, `_calculate_summary()`)

**Step 1: Add holding cache to `__init__()` (around line 240)**

After `self._taxi_references = {}`, add:

```python
self._holding_events = []           # List of HoldingEvent-like dicts
self._flight_waypoints_cache = {}   # {flight_uid: [waypoints]}
self._star_fixes_cache = {}         # {dest_icao: [fixes]}
```

**Step 2: Add event-wide holding detection method**

```python
def _detect_all_holding_patterns(self) -> list:
    """Scan all flights in trajectory cache for holding patterns.
    Called once after _preload_trajectories(), before TMI-specific analysis.
    Returns list of enriched holding event dicts.
    """
    import logging
    logger = logging.getLogger(__name__)

    all_events = []
    flights_with_holds = 0

    # Get destination airport coordinates for circling approach filter
    dest_coords = {}
    for cs, meta in self._trajectory_metadata.items():
        dest = meta.get('dest', '')
        if dest and dest in self.fix_coords:
            # fix_coords may have airport coords if loaded
            pass
        # We'll use a simpler approach - skip circling filter if no coords

    # Load waypoints for all flights that have trajectories
    flight_uids = [meta['flight_uid'] for meta in self._trajectory_metadata.values()
                   if meta.get('flight_uid')]
    self._flight_waypoints_cache = self._load_flight_waypoints(flight_uids)

    for callsign, trajectory in self._trajectory_cache.items():
        if callsign in self._low_quality_flights:
            continue

        meta = self._trajectory_metadata.get(callsign, {})
        dest = meta.get('dest', '')

        # Get destination coords for circling filter
        dest_lat = dest_lon = None
        # Try to find dest airport in known coordinates
        if dest:
            apt_key = dest if dest in self.fix_coords else None
            if apt_key:
                dest_lat = self.fix_coords[apt_key]['lat']
                dest_lon = self.fix_coords[apt_key]['lon']

        raw_events = detect_flight_holding(trajectory, dest_lat, dest_lon)

        if raw_events:
            flights_with_holds += 1
            flight_uid = meta.get('flight_uid', 0)
            dept = meta.get('dept', '')

            for evt in raw_events:
                # Enrich with flight metadata
                evt['callsign'] = callsign
                evt['flight_uid'] = flight_uid
                evt['dept'] = dept
                evt['dest'] = dest

                # Fix matching
                self._match_holding_fix(evt, flight_uid, dest,
                                        self._flight_waypoints_cache,
                                        self._star_fixes_cache)

                all_events.append(evt)

    logger.info(f"Holding detection: {len(all_events)} events across "
                f"{flights_with_holds} flights (scanned {len(self._trajectory_cache)})")

    return all_events
```

**Step 3: Add holding summary builder**

```python
def _build_holding_summary(self, events: list) -> dict:
    """Build aggregate holding summary grouped by fix."""
    from collections import defaultdict

    if not events:
        return {
            'summary': {
                'total_flights_holding': 0,
                'total_hold_events': 0,
                'total_hold_duration_sec': 0,
                'avg_hold_duration_sec': 0,
                'hold_fixes': [],
                'delay_attribution': {
                    'total_hold_delay_sec': 0,
                    'attributed': {
                        'gs': {'flights': 0, 'total_sec': 0},
                        'gdp': {'flights': 0, 'total_sec': 0},
                        'mit': {'flights': 0, 'total_sec': 0},
                    },
                    'unattributed': {'flights': 0, 'total_sec': 0},
                },
            },
            'events': [],
        }

    # Group by matched fix (or center coords if no fix)
    fix_groups = defaultdict(list)
    for evt in events:
        key = evt.get('matched_fix') or f"{evt['center_lat']:.3f},{evt['center_lon']:.3f}"
        fix_groups[key].append(evt)

    hold_fixes = []
    for fix_key, group in fix_groups.items():
        # Calculate peak concurrent
        # Sort all start/end times, sweep to find max overlap
        time_events = []
        for evt in group:
            time_events.append((evt['hold_start_utc'], 1))
            time_events.append((evt['hold_end_utc'], -1))
        time_events.sort(key=lambda x: x[0])

        concurrent = 0
        peak = 0
        for _, delta in time_events:
            concurrent += delta
            peak = max(peak, concurrent)

        hold_fixes.append({
            'fix_name': group[0].get('matched_fix'),
            'center': [group[0]['center_lon'], group[0]['center_lat']],
            'flight_count': len(set(e['callsign'] for e in group)),
            'total_orbits': sum(e['orbit_count'] for e in group),
            'avg_duration_sec': sum(e['duration_sec'] for e in group) / len(group),
            'peak_concurrent': peak,
            'ntml_corroborated': any(e.get('ntml_corroborated') for e in group),
            'time_range': [
                min(e['hold_start_utc'] for e in group).isoformat() + 'Z',
                max(e['hold_end_utc'] for e in group).isoformat() + 'Z',
            ],
        })

    # Delay attribution totals
    attr = {'gs': {'flights': set(), 'total_sec': 0},
            'gdp': {'flights': set(), 'total_sec': 0},
            'mit': {'flights': set(), 'total_sec': 0}}
    unattr_flights = set()
    unattr_sec = 0

    for evt in events:
        a = evt.get('tmi_attribution')
        if a and a in attr:
            attr[a]['flights'].add(evt['callsign'])
            attr[a]['total_sec'] += evt['duration_sec']
        else:
            unattr_flights.add(evt['callsign'])
            unattr_sec += evt['duration_sec']

    unique_flights = set(e['callsign'] for e in events)
    total_dur = sum(e['duration_sec'] for e in events)

    # Serialize events for JSON output
    serialized_events = []
    for evt in events:
        se = dict(evt)
        se['hold_start_utc'] = evt['hold_start_utc'].isoformat() + 'Z'
        se['hold_end_utc'] = evt['hold_end_utc'].isoformat() + 'Z'
        se.pop('point_indices', None)  # Internal only
        serialized_events.append(se)

    return {
        'summary': {
            'total_flights_holding': len(unique_flights),
            'total_hold_events': len(events),
            'total_hold_duration_sec': total_dur,
            'avg_hold_duration_sec': round(total_dur / len(events), 1),
            'hold_fixes': sorted(hold_fixes,
                                 key=lambda x: x['flight_count'], reverse=True),
            'delay_attribution': {
                'total_hold_delay_sec': total_dur,
                'attributed': {
                    k: {'flights': len(v['flights']), 'total_sec': v['total_sec']}
                    for k, v in attr.items()
                },
                'unattributed': {
                    'flights': len(unattr_flights),
                    'total_sec': unattr_sec
                },
            },
        },
        'events': serialized_events,
    }
```

**Step 4: Wire into `analyze()` method**

In the `analyze()` method (line ~526), after `_preload_trajectories()` call (line ~587) and after the reference data loading block (lines ~590-596), add:

```python
# Holding pattern detection (event-wide, before TMI-specific analysis)
self._holding_events = self._detect_all_holding_patterns()

# NTML correlation
if hasattr(self, '_delay_entries') and self._delay_entries:
    self._correlate_ntml_holding(self._holding_events, self._delay_entries)
elif hasattr(event_config, 'delay_entries'):
    self._correlate_ntml_holding(self._holding_events, event_config.delay_entries)

# TMI delay attribution
self._attribute_holding_to_tmi(self._holding_events, event_config)
```

Note: You'll need to check where `delay_entries` is populated from the NTML parse. It may be on the event config object passed to `analyze()`. Trace the data flow in `run.py` to find the right attribute name.

**Step 5: Add holding results to the results dict**

In `analyze()`, just before `return results` (around line 705), add:

```python
# Holding results
results['holding'] = self._build_holding_summary(self._holding_events)
```

Also add `'holding': {}` to the initial results dict definition (around line 537).

**Step 6: Extend `_calculate_summary()` (around line 3255)**

After the GS summary block, add:

```python
# Holding summary
holding = results.get('holding', {}).get('summary', {})
summary['holding'] = {
    'total_flights_holding': holding.get('total_flights_holding', 0),
    'total_hold_events': holding.get('total_hold_events', 0),
    'total_hold_duration_min': round(holding.get('total_hold_duration_sec', 0) / 60, 1),
    'avg_hold_duration_min': round(holding.get('avg_hold_duration_sec', 0) / 60, 1),
}
```

**Step 7: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(holding): wire detection into analysis pipeline with summary builder"
```

---

## Task 6: PHP Results Formatting

**Files:**
- Modify: `api/analysis/tmi_compliance.php` (in `format_results()` function, after GS results around line 522)

**Step 1: Add holding results to the formatted output array**

In `format_results()`, find the initial `$formatted` array (around line 422) and add:

```php
'holding' => null,
```

**Step 2: Add holding formatting block after GS results (around line 522)**

```php
// Holding pattern results
if (isset($results['holding']) && !empty($results['holding'])) {
    $formatted['holding'] = $results['holding'];
}
```

The holding data is already well-structured from Python (summary + events), so it flows through without much transformation. The JS frontend will consume it directly.

**Step 3: Commit**

```bash
git add api/analysis/tmi_compliance.php
git commit -m "feat(holding): pass holding results through PHP API to frontend"
```

---

## Task 7: JavaScript Holding Summary Panel

**Files:**
- Modify: `assets/js/tmi_compliance.js`

**Step 1: Add holding data to state management**

Near the top of the `TMICompliance` object (around line 6-62), add to the state:

```javascript
holdingData: null,           // Holding detection results
holdingLayerVisible: true,   // Toggle state for map layers
```

**Step 2: Store holding data when results load**

In the `loadResults` or results processing function, after the existing result types are processed, add:

```javascript
// Store holding results
if (data.holding) {
    TMICompliance.holdingData = data.holding;
}
```

**Step 3: Add holding section to the master list panel**

In `renderProgressiveLayout()` (around line 6970, after the existing TMI type sections), add a holding section:

```javascript
// Holding patterns section
if (TMICompliance.holdingData && TMICompliance.holdingData.summary.total_hold_events > 0) {
    const hs = TMICompliance.holdingData.summary;
    html += '<div class="tmi-list-group">';
    html += '<div class="tmi-list-group-label">HOLDING PATTERNS</div>';

    // One item per fix
    (hs.hold_fixes || []).forEach(function(fix, idx) {
        const fixLabel = fix.fix_name || 'Unknown Fix';
        const isSelected = TMICompliance._selectedHoldingFix === idx;
        html += '<div class="tmi-list-item' + (isSelected ? ' selected' : '') + '"'
            + ' onclick="TMICompliance.selectHoldingFix(' + idx + ')">';
        html += '<div class="tmi-list-item-identity">'
            + '<span class="tmi-type-badge holding">HPT</span> '
            + fixLabel + '</div>';
        html += '<div class="tmi-list-item-meta">'
            + fix.flight_count + ' flights, '
            + Math.round(fix.avg_duration_sec / 60) + 'min avg'
            + (fix.ntml_corroborated ? ' <i class="fas fa-check-circle" title="NTML corroborated"></i>' : '')
            + '</div>';
        html += '</div>';
    });

    html += '</div>';
}
```

**Step 4: Add holding detail panel renderer**

```javascript
selectHoldingFix: function(fixIdx) {
    TMICompliance._selectedHoldingFix = fixIdx;
    TMICompliance._selectedTmiKey = null; // Deselect any TMI
    TMICompliance.renderHoldingDetail(fixIdx);
},

renderHoldingDetail: function(fixIdx) {
    const holding = TMICompliance.holdingData;
    if (!holding) return;

    const fix = holding.summary.hold_fixes[fixIdx];
    const events = holding.events.filter(function(e) {
        return (fix.fix_name && e.matched_fix === fix.fix_name) ||
               (!fix.fix_name && Math.abs(e.center_lat - fix.center[1]) < 0.01);
    });

    const panel = document.querySelector('.tmi-detail-panel');
    if (!panel) return;

    let html = '<div class="tmi-detail-header">';
    html += '<div class="tmi-identity">';
    html += '<span class="tmi-type-badge holding">HPT</span> ';
    html += 'Holding at ' + (fix.fix_name || 'Unknown');
    html += '</div>';
    html += '</div>';

    // Overview stats
    html += '<div class="tmi-detail-overview">';
    html += '<div class="stat"><div class="stat-value">' + fix.flight_count + '</div>'
        + '<div class="stat-label">Flights</div></div>';
    html += '<div class="stat"><div class="stat-value">' + fix.total_orbits + '</div>'
        + '<div class="stat-label">Total Orbits</div></div>';
    html += '<div class="stat"><div class="stat-value">'
        + Math.round(fix.avg_duration_sec / 60) + 'm</div>'
        + '<div class="stat-label">Avg Duration</div></div>';
    html += '<div class="stat"><div class="stat-value">' + fix.peak_concurrent + '</div>'
        + '<div class="stat-label">Peak Concurrent</div></div>';
    html += '</div>';

    // NTML corroboration badge
    if (fix.ntml_corroborated) {
        html += '<div class="holding-ntml-badge">'
            + '<i class="fas fa-check-circle"></i> NTML Corroborated</div>';
    }

    // Flight list
    html += TMICompliance.renderExpandableSectionV2(
        'holding-flights-' + fixIdx, 'Flights', events.length, function() {
            let tableHtml = '<table class="tmi-pairs-table">';
            tableHtml += '<thead><tr><th>Callsign</th><th>Dep</th><th>Dest</th>'
                + '<th>Start</th><th>Duration</th><th>Orbits</th>'
                + '<th>Fix Source</th><th>Direction</th></tr></thead><tbody>';
            events.forEach(function(e) {
                const startTime = new Date(e.hold_start_utc).toISOString().substr(11, 5);
                const durMin = Math.round(e.duration_sec / 60);
                const sourceLabel = e.fix_match_source === 'route' ? 'Route'
                    : e.fix_match_source === 'star' ? 'STAR'
                    : e.fix_match_source === 'navfix' ? 'Nearby' : '-';
                tableHtml += '<tr>'
                    + '<td><strong>' + e.callsign + '</strong></td>'
                    + '<td>' + (e.dept || '-') + '</td>'
                    + '<td>' + (e.dest || '-') + '</td>'
                    + '<td>' + startTime + 'Z</td>'
                    + '<td>' + durMin + 'min</td>'
                    + '<td>' + e.orbit_count + '</td>'
                    + '<td>' + sourceLabel + '</td>'
                    + '<td>' + e.turn_direction + '</td>'
                    + '</tr>';
            });
            tableHtml += '</tbody></table>';
            return tableHtml;
        }
    );

    // Delay attribution
    const attr = holding.summary.delay_attribution;
    if (attr && attr.total_hold_delay_sec > 0) {
        html += TMICompliance.renderExpandableSectionV2(
            'holding-delay-' + fixIdx, 'Delay Attribution', '', function() {
                let dhtml = '<div class="holding-delay-summary">';
                const totalMin = Math.round(attr.total_hold_delay_sec / 60);
                dhtml += '<div class="stat"><div class="stat-value">' + totalMin + 'm</div>'
                    + '<div class="stat-label">Total Hold Delay</div></div>';
                if (attr.attributed.gs.flights > 0) {
                    dhtml += '<div class="stat"><div class="stat-value">'
                        + attr.attributed.gs.flights + '</div>'
                        + '<div class="stat-label">GS-Attributed</div></div>';
                }
                if (attr.attributed.mit.flights > 0) {
                    dhtml += '<div class="stat"><div class="stat-value">'
                        + attr.attributed.mit.flights + '</div>'
                        + '<div class="stat-label">MIT-Attributed</div></div>';
                }
                if (attr.unattributed.flights > 0) {
                    dhtml += '<div class="stat"><div class="stat-value">'
                        + attr.unattributed.flights + '</div>'
                        + '<div class="stat-label">Unattributed</div></div>';
                }
                dhtml += '</div>';
                return dhtml;
            }
        );
    }

    // Map section
    html += TMICompliance.renderMapSection('holding-' + fixIdx, fix, events);

    panel.innerHTML = html;

    // Render map if section is expanded
    TMICompliance._renderHoldingMap('holding-' + fixIdx, fix, events);
},
```

**Step 5: Commit**

```bash
git add assets/js/tmi_compliance.js
git commit -m "feat(holding): add holding summary panel and detail view to JS frontend"
```

---

## Task 8: JavaScript Map Visualization

**Files:**
- Modify: `assets/js/tmi_compliance.js` (map rendering section)

**Step 1: Add holding orbit highlight rendering**

Add method to render individual flight orbit highlights as magenta trajectory segments:

```javascript
_renderHoldingOrbits: function(map, mapId, events, allTrajectories) {
    const sourceId = 'holding-orbits-' + mapId;
    const layerId = 'holding-orbits-layer-' + mapId;
    const glowId = 'holding-orbits-glow-' + mapId;

    // Build GeoJSON features from hold events
    var features = [];
    events.forEach(function(evt) {
        var traj = allTrajectories[evt.callsign];
        if (!traj || !traj.coordinates) return;

        // Find trajectory points within hold time window
        var startEpoch = new Date(evt.hold_start_utc).getTime() / 1000;
        var endEpoch = new Date(evt.hold_end_utc).getTime() / 1000;
        var holdCoords = traj.coordinates.filter(function(c) {
            return c[2] >= startEpoch && c[2] <= endEpoch;
        });

        if (holdCoords.length < 2) return;

        features.push({
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: holdCoords.map(function(c) { return [c[0], c[1]]; })
            },
            properties: {
                callsign: evt.callsign,
                duration_min: Math.round(evt.duration_sec / 60),
                orbits: evt.orbit_count,
                fix: evt.matched_fix || 'Unknown',
                direction: evt.turn_direction
            }
        });
    });

    var geojson = { type: 'FeatureCollection', features: features };

    // Remove existing layers if present
    if (map.getSource(sourceId)) {
        map.removeLayer(glowId);
        map.removeLayer(layerId);
        map.removeSource(sourceId);
    }

    map.addSource(sourceId, { type: 'geojson', data: geojson });

    // Glow layer
    map.addLayer({
        id: glowId,
        type: 'line',
        source: sourceId,
        paint: {
            'line-color': '#c050e0',
            'line-width': 7,
            'line-opacity': 0.25,
            'line-blur': 3
        }
    });

    // Main line
    map.addLayer({
        id: layerId,
        type: 'line',
        source: sourceId,
        paint: {
            'line-color': '#a020d0',
            'line-width': 3,
            'line-opacity': 0.85
        }
    });
},
```

**Step 2: Add holding zone markers rendering**

```javascript
_renderHoldingZones: function(map, mapId, holdFixes) {
    var sourceId = 'holding-zones-' + mapId;
    var circleLayerId = 'holding-zones-circle-' + mapId;
    var labelLayerId = 'holding-zones-label-' + mapId;

    var features = holdFixes.map(function(fix) {
        return {
            type: 'Feature',
            geometry: {
                type: 'Point',
                coordinates: fix.center  // [lon, lat]
            },
            properties: {
                fix_name: fix.fix_name || 'Unknown',
                flight_count: fix.flight_count,
                total_orbits: fix.total_orbits,
                avg_duration_min: Math.round(fix.avg_duration_sec / 60),
                peak_concurrent: fix.peak_concurrent,
                ntml: fix.ntml_corroborated
            }
        };
    });

    var geojson = { type: 'FeatureCollection', features: features };

    if (map.getSource(sourceId)) {
        map.removeLayer(labelLayerId);
        map.removeLayer(circleLayerId);
        map.removeSource(sourceId);
    }

    map.addSource(sourceId, { type: 'geojson', data: geojson });

    // Circle markers sized by flight count
    map.addLayer({
        id: circleLayerId,
        type: 'circle',
        source: sourceId,
        paint: {
            'circle-radius': ['interpolate', ['linear'], ['get', 'flight_count'],
                1, 10, 5, 18, 10, 26, 20, 35],
            'circle-color': '#a020d0',
            'circle-opacity': ['interpolate', ['linear'], ['get', 'flight_count'],
                1, 0.3, 10, 0.6],
            'circle-stroke-color': '#7010a0',
            'circle-stroke-width': 2
        }
    });

    // Labels
    map.addLayer({
        id: labelLayerId,
        type: 'symbol',
        source: sourceId,
        layout: {
            'text-field': ['concat', ['get', 'fix_name'], '\n',
                ['to-string', ['get', 'flight_count']], ' flt'],
            'text-size': 11,
            'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
            'text-offset': [0, 0],
            'text-allow-overlap': true
        },
        paint: {
            'text-color': '#ffffff',
            'text-halo-color': '#4a0070',
            'text-halo-width': 1.5
        }
    });

    // Click handler for zone markers
    map.on('click', circleLayerId, function(e) {
        var props = e.features[0].properties;
        new maplibregl.Popup()
            .setLngLat(e.lngLat)
            .setHTML(
                '<div class="holding-zone-popup">'
                + '<strong>' + props.fix_name + '</strong><br>'
                + props.flight_count + ' flights held<br>'
                + props.total_orbits + ' total orbits<br>'
                + props.avg_duration_min + 'min avg duration<br>'
                + 'Peak concurrent: ' + props.peak_concurrent
                + (props.ntml ? '<br><em>NTML corroborated</em>' : '')
                + '</div>'
            )
            .addTo(map);
    });

    // Cursor change on hover
    map.on('mouseenter', circleLayerId, function() {
        map.getCanvas().style.cursor = 'pointer';
    });
    map.on('mouseleave', circleLayerId, function() {
        map.getCanvas().style.cursor = '';
    });
},
```

**Step 3: Add the combined map renderer that calls both**

```javascript
_renderHoldingMap: function(mapId, fix, events) {
    // This is called when the map section is expanded
    // Reuse existing map initialization pattern from renderMapSection
    var mapContainer = document.getElementById('map-' + mapId);
    if (!mapContainer) return;

    // Check if map already initialized
    if (TMICompliance._maps && TMICompliance._maps[mapId]) {
        var map = TMICompliance._maps[mapId];
        TMICompliance._addHoldingLayers(map, mapId, fix, events);
        return;
    }

    // Map will be initialized by existing renderMapSection logic
    // We hook into the map load event to add holding layers
    // Store fix/events for when map loads
    TMICompliance._pendingHoldingLayers = TMICompliance._pendingHoldingLayers || {};
    TMICompliance._pendingHoldingLayers[mapId] = { fix: fix, events: events };
},

_addHoldingLayers: function(map, mapId, fix, events) {
    var holding = TMICompliance.holdingData;
    if (!holding) return;

    // Load trajectories for this map
    TMICompliance._loadTrajectoriesForMap(mapId, function(trajectories) {
        // Render orbit highlights for events at this fix
        TMICompliance._renderHoldingOrbits(map, mapId, events, trajectories);

        // Render zone marker for this fix
        TMICompliance._renderHoldingZones(map, mapId, [fix]);

        // Fit bounds to include holding area
        if (fix.center) {
            map.flyTo({
                center: fix.center,
                zoom: 8,
                duration: 1000
            });
        }
    });
},
```

**Step 4: Add layer toggle for holding patterns**

In the map layer controls section (around line 3946), add a holding toggle button:

```javascript
// Add to layer control button generation
if (TMICompliance.holdingData && TMICompliance.holdingData.summary.total_hold_events > 0) {
    html += '<button class="layer-btn active" data-layer="holding" '
        + 'onclick="TMICompliance.toggleHoldingLayer(this, \'' + mapId + '\')">'
        + '<i class="fas fa-sync-alt"></i> Holding</button>';
}
```

```javascript
toggleHoldingLayer: function(btn, mapId) {
    var map = TMICompliance._maps && TMICompliance._maps[mapId];
    if (!map) return;

    var visible = btn.classList.toggle('active');
    var layers = [
        'holding-orbits-layer-' + mapId,
        'holding-orbits-glow-' + mapId,
        'holding-zones-circle-' + mapId,
        'holding-zones-label-' + mapId
    ];
    layers.forEach(function(layerId) {
        if (map.getLayer(layerId)) {
            map.setLayoutProperty(layerId, 'visibility',
                visible ? 'visible' : 'none');
        }
    });
},
```

**Step 5: Commit**

```bash
git add assets/js/tmi_compliance.js
git commit -m "feat(holding): add orbit highlight and zone marker map visualization"
```

---

## Task 9: CSS Styles

**Files:**
- Modify: `assets/css/tmi-compliance.css` (add after existing sections)

**Step 1: Add holding-specific styles at end of file (after line 2035)**

```css
/* ============================================================
   HOLDING PATTERN DETECTION
   ============================================================ */

/* Holding type badge */
.tmi-type-badge.holding {
    background-color: #a020d0;
    color: #fff;
}

/* NTML corroboration badge */
.holding-ntml-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    margin: 8px 0;
    background: rgba(40, 167, 69, 0.1);
    border: 1px solid rgba(40, 167, 69, 0.3);
    border-radius: 4px;
    color: #28a745;
    font-size: 0.85rem;
}

.holding-ntml-badge .fa-check-circle {
    color: #28a745;
}

/* Holding delay attribution summary */
.holding-delay-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    padding: 8px 0;
}

.holding-delay-summary .stat {
    text-align: center;
    min-width: 80px;
}

.holding-delay-summary .stat-value {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--v2-text-primary, #1a1a2e);
}

.holding-delay-summary .stat-label {
    font-size: 0.75rem;
    color: var(--v2-text-muted, #8e8ea0);
    margin-top: 2px;
}

/* Holding zone popup (MapLibre) */
.holding-zone-popup {
    font-size: 0.85rem;
    line-height: 1.5;
    padding: 4px;
}

.holding-zone-popup strong {
    color: #a020d0;
}

/* Holding list item meta with corroboration icon */
.tmi-list-item-meta .fa-check-circle {
    color: #28a745;
    margin-left: 4px;
}

/* Fix match source labels in flight table */
.tmi-pairs-table td {
    vertical-align: middle;
}
```

**Step 2: Commit**

```bash
git add assets/css/tmi-compliance.css
git commit -m "feat(holding): add CSS styles for holding detection UI"
```

---

## Task 10: Internationalization Keys

**Files:**
- Modify: `assets/locales/en-US.json` (inside `tmiCompliance` object, around line 2048)

**Step 1: Add holding-related i18n keys**

Inside the `"tmiCompliance"` object, after the existing keys, add:

```json
"holdingTitle": "Holding Patterns",
"holdingBadge": "HPT",
"holdingAtFix": "Holding at {fix}",
"holdingFlights": "{count} flights",
"holdingAvgDuration": "{min}min avg",
"holdingTotalOrbits": "Total Orbits",
"holdingPeakConcurrent": "Peak Concurrent",
"holdingNtmlCorroborated": "NTML Corroborated",
"holdingDelayAttribution": "Delay Attribution",
"holdingTotalDelay": "Total Hold Delay",
"holdingGsAttributed": "GS-Attributed",
"holdingMitAttributed": "MIT-Attributed",
"holdingUnattributed": "Unattributed",
"holdingFixSource": "Fix Source",
"holdingFixRoute": "Route",
"holdingFixStar": "STAR",
"holdingFixNearby": "Nearby",
"holdingDirection": "Direction",
"holdingOrbits": "Orbits",
"holdingDuration": "Duration",
"holdingNoData": "No holding patterns detected"
```

Note: These keys are available for future i18n migration of the JS code. The initial JS implementation can use hardcoded English strings (matching existing patterns in the codebase) and migrate to `PERTII18n.t()` later.

**Step 2: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(holding): add i18n keys for holding detection UI"
```

---

## Task 11: Manual Verification

**No automated test suite exists.** Verify manually:

**Step 1: Run analysis on a known event with holding**

The "West Coast with Love" event (Feb 15, 2026) at KTPA clearly shows holding (MXY340 and others). Find its plan ID and run:

```bash
cd C:/Temp/perti-worktrees/holding-detection
python scripts/tmi_compliance/run.py --plan_id <PLAN_ID> --output data/tmi_compliance/test_holding.json
```

**Step 2: Verify Python output**

Check the output JSON file:
- `results.holding.summary.total_hold_events > 0`
- `results.holding.events` contains entries with:
  - Reasonable `duration_sec` (>120)
  - `orbit_count >= 1`
  - `matched_fix` is populated (preferably with `fix_match_source: 'route'`)
  - `center_lat`/`center_lon` near Tampa approach area
- MXY340 specifically should have a holding event detected

**Step 3: Verify via live site**

Deploy to staging or test locally:
1. Open TMI compliance page for the event
2. Verify "HOLDING PATTERNS" section appears in the left sidebar
3. Click a holding fix — verify detail panel shows flights, stats, map
4. Verify map shows magenta orbit highlights and purple zone markers
5. Toggle "Holding" layer button on/off

**Step 4: Edge case checks**

- Run on an event with NO holding — verify `holding.summary.total_hold_events === 0` and no UI errors
- Check that circling approaches near destination airports are filtered out
- Verify NTML corroboration badge appears when NTML mentions `+Holding` at the same fix

**Step 5: Commit any fixes from verification**

```bash
git add -A
git commit -m "fix(holding): address issues found during manual verification"
```

---

## Task Summary

| # | Task | Files | Est. Complexity |
|---|------|-------|-----------------|
| 1 | Data models & constants | `models.py` | Small |
| 2 | Core detection algorithm | `analyzer.py` (module-level) | Medium |
| 3 | Fix matching with route waypoints | `analyzer.py` (instance methods) | Medium |
| 4 | NTML correlation & delay attribution | `analyzer.py` (instance methods) | Small |
| 5 | Wire into analysis pipeline | `analyzer.py` (analyze/summary) | Medium |
| 6 | PHP results formatting | `tmi_compliance.php` | Small |
| 7 | JS holding summary panel | `tmi_compliance.js` | Medium |
| 8 | JS map visualization | `tmi_compliance.js` | Medium |
| 9 | CSS styles | `tmi-compliance.css` | Small |
| 10 | i18n keys | `en-US.json` | Small |
| 11 | Manual verification | All | Medium |

**Dependencies**: Tasks 1→2→3→4→5→6 are sequential (Python backend). Tasks 7→8 are sequential (JS frontend). Task 9 and 10 are independent. Task 11 requires all others complete.
