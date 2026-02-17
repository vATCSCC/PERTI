# Holding Pattern Detection for TMI Compliance Analysis

**Date**: 2026-02-16
**Status**: Approved
**Scope**: Event-wide holding detection with compliance metrics, map visualization, and delay attribution

## Overview

Detect holding patterns from flight trajectory data during TMI compliance analysis. Currently, holding information comes only from NTML text parsing. This feature adds automatic trajectory-based detection that surfaces as compliance metrics, interactive map visualization, and delay attribution to TMI programs.

## Detection Algorithm (Python — Hybrid Turn-Rate + Spatial Validation)

### Location

New function `detect_all_holding_patterns()` in `scripts/tmi_compliance/core/analyzer.py`, called after `_preload_trajectories()` but before TMI-specific analysis.

### Input

Existing `_trajectory_cache[callsign]` — list of `{timestamp, lat, lon, gs, alt}` points already loaded.

### Algorithm (per flight)

1. **Heading series**: Walk consecutive trajectory points, compute bearing between each pair using existing `calculate_bearing()`. Produces time series of `(timestamp, heading, lat, lon, alt, gs)`.

2. **Turn-rate scan**: For each point, compute heading delta to next point (handling 360 wrap). Accumulate heading change over a sliding window. When cumulative change crosses +/-360, that's one complete orbit.

3. **Spatial validation**: For candidate orbit windows, compute centroid of all points. Check all points fall within ~5nm of centroid via `haversine_nm()`. Reject if spatial extent too large (vectoring, not holding).

4. **Orbit grouping**: Merge consecutive orbits into a single holding event. Hold ends when heading change <90 over 3 minutes or flight leaves spatial envelope.

5. **Fix matching** (priority order):
   - **Route waypoints** (`adl_flight_waypoints`): orbit center within 3nm of any waypoint on the flight's parsed route
   - **STAR fixes**: fixes on published STARs for the flight's destination
   - **nav_fixes fallback**: nearest nav fix within 5nm (lower confidence)
   - **NTML cross-reference**: corroborate with parsed `+Holding` entries for same fix/time

### Output Per Detected Hold

```python
HoldingEvent = {
    'callsign': str,
    'flight_uid': int,
    'hold_start_utc': datetime,
    'hold_end_utc': datetime,
    'duration_sec': int,
    'orbit_count': int,
    'center_lat': float,
    'center_lon': float,
    'avg_radius_nm': float,
    'avg_altitude_ft': float,
    'avg_groundspeed_kts': float,
    'turn_direction': 'R' | 'L',
    'matched_fix': str | None,
    'fix_match_source': 'route' | 'star' | 'navfix' | None,
    'fix_distance_nm': float,
    'fix_on_route': bool,
    'ntml_corroborated': bool,
    'low_confidence': bool,
    'dept': str,
    'dest': str,
}
```

### Tunable Constants

```python
HOLD_MIN_HEADING_CHANGE_DEG = 270    # Min cumulative turn for one orbit
HOLD_MIN_DURATION_SEC = 120          # 2 min minimum
HOLD_MAX_RADIUS_NM = 5.0            # Spatial containment
HOLD_FIX_MATCH_RADIUS_NM = 5.0      # Fix search radius
HOLD_CIRCLING_ALT_AGL_FT = 2000     # Exclude circling approaches below this
HOLD_CIRCLING_DIST_NM = 5.0         # Exclude approaches within this of dest
HOLD_GAP_RESET_SEC = 180            # Reset accumulator if gap > 3 min
HOLD_LOW_CONFIDENCE_INTERVAL_SEC = 120  # Flag if data sparser than this
```

## Data Flow & Integration

### Pipeline Position

```
run.py -> analyzer.analyze()
  +-- _preload_trajectories()          # Existing
  +-- detect_all_holding_patterns()    # NEW - scans every flight
  |     +-- _detect_flight_holding(points) -> List[HoldingEvent]
  |     +-- _match_holding_fixes(events) -> enriches with fix names
  |     +-- _correlate_ntml_holding(events, ntml_entries) -> sets corroborated
  +-- analyze_mit()                    # Existing
  +-- analyze_gs()                     # Existing
  +-- analyze_reroutes()               # Existing
  +-- _build_results()                 # Existing - now includes holding
```

### Results JSON Structure

```python
results['holding'] = {
    'summary': {
        'total_flights_holding': int,
        'total_hold_events': int,
        'total_hold_duration_sec': int,
        'avg_hold_duration_sec': float,
        'hold_fixes': [
            {
                'fix_name': str | None,
                'center': [lon, lat],
                'flight_count': int,
                'total_orbits': int,
                'avg_duration_sec': float,
                'peak_concurrent': int,
                'ntml_corroborated': bool,
                'time_range': [start_utc, end_utc],
            }
        ],
        'delay_attribution': {
            'total_hold_delay_sec': int,
            'attributed': {
                'gs': {'flights': int, 'total_sec': int},
                'gdp': {'flights': int, 'total_sec': int},
                'mit': {'flights': int, 'total_sec': int},
            },
            'unattributed': {'flights': int, 'total_sec': int},
        },
    },
    'events': [HoldingEvent, ...],
}
```

No changes to trajectory JSON output — JS uses hold event timestamps to identify segments.

## JavaScript Visualization

### Layer 1: Individual Orbit Highlights

- Slice flight trajectory by `hold_start_utc`/`hold_end_utc` timestamps
- Render in magenta/purple (3px) with glow, on top of normal density trails
- Label at orbit center: callsign, duration, orbit count
- Click popup: full hold details including fix match source, NTML badge

### Layer 2: Aggregate Hold Zone Markers

- Group events by matched fix (or proximity cluster if no fix)
- Circle marker at fix center, sized by flight count
- Purple fill, opacity scales with severity
- Click popup: list all flights that held, sortable by duration

### Layer 3: Holding Summary Panel

- Collapsible panel in left sidebar below existing compliance sections
- Header: "Holding Detected" with badge count
- Per-fix rows: fix name, flight count, total/avg duration, peak concurrent
- Expandable: individual flights per fix
- Timeline bar: when holds were active relative to event

### Toggle

Checkbox in "Label Displays" panel: "Show holding patterns" (default checked).

## Delay Attribution

### Per-Flight

- Hold delay = `hold_end_utc - hold_start_utc` (direct trajectory measurement)
- Multiple holds per flight summed for total airborne delay

### TMI Attribution

Cross-reference each hold event with active TMI programs:
- **GS**: Ground Stop active for flight's destination during hold
- **GDP**: Flight has EDCT/CTA and hold occurs within ~60nm of destination
- **MIT**: Hold occurs near MIT measurement fix with active MIT program
- **Unattributed**: No matching TMI — vectoring, weather, or undeclared spacing

## Edge Cases

| Case | Handling |
|------|----------|
| Procedure turns | Single 180 reversal filtered by 270 min heading change + spatial check |
| Circling approaches | Excluded: <2000ft AGL and <5nm from destination |
| Disconnected pilots | Trajectory gap >3min resets accumulator; split holds detected separately |
| Racetrack holds | Cumulative heading change works for racetracks (360/lap); 5nm radius accommodates ~4nm legs |
| 360 for spacing | Detected as single-orbit event (intentional — still delay) |
| Low-res archive data | Holds <8min may be missed at 5-min intervals; flagged `low_confidence: true` |

## Files Modified

| File | Changes |
|------|---------|
| `scripts/tmi_compliance/core/analyzer.py` | New holding detection functions, fix matching, NTML correlation |
| `scripts/tmi_compliance/core/models.py` | `HoldingEvent` dataclass, constants |
| `assets/js/tmi_compliance.js` | Orbit highlights, zone markers, summary panel, toggle |
| `assets/css/tmi-compliance.css` | Styles for holding layers and panel |
| `assets/locales/en-US.json` | i18n keys for holding UI strings |
