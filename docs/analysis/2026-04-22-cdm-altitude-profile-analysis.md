# CDM Altitude Profile Analysis: How vIFF/CDM Computes Planned vs Flown Profiles

**Date**: 2026-04-22
**Purpose**: Deep-dive into how `cdm.vatsimspain.es` computes and visualizes planned vs flown altitude profiles, and how PERTI can implement the same capability.

---

## 1. What the CDM System Does

The VATSIM Spain CDM system (by rpuig2001) provides a web dashboard at `cdm.vatsimspain.es` that shows, for each flight:

- **Planned altitude profile**: A chart of flight levels at each route waypoint (departure through climb, cruise, descent, arrival)
- **Flown altitude profile**: Actual position reports overlaid on the planned route
- **Restriction bands**: Red overlay rectangles showing airspace volume altitude constraints
- **Interactive map**: Leaflet map with planned (blue) and flown (yellow) polylines

### Example: UAE30Y (OMDB-CYYZ)
- 81 planned waypoints with computed altitudes (FL0 at departure, climbing through FL150/199/257/289/340 to FL390 cruise, descent to FL0 at arrival)
- 42 actual position reports [lat, lon, FL] from VATSIM datafeed
- Restriction bands showing regulated airspace volumes with altitude constraints

### Example: HRT168 (KMIA-CYYZ)
- 17 planned waypoints (FL0 → FL390 cruise → FL303 descent → FL0)
- 21 actual position reports showing climb from FL56 through FL392
- Simpler profile (no SID altitude detail)

---

## 2. CDM Server-Side: How Planned Altitudes Are Computed

The server generates a `pathData` array with `{name, lat, lon, speed, alt}` for each waypoint. The altitude computation pipeline (from the CDM documentation at `cdm.vatsimspain.es/docs.html`):

### 2.1 Input Data
- VATSIM flight plan: route string, cruise TAS, filed altitude, departure time, enroute time
- Aircraft type → EuroScope performance profile (climb/descent rates)
- SID/STAR procedures from `procedures.txt`
- Altitude restrictions from `profile_restrictions.txt`

### 2.2 Algorithm: Minute-by-Minute 4D Path Generation

**Step 1: Time baseline**
- Use T/OBT (Target Off-Block Time) if present, else EOBT
- Add EXOT (Expected Taxi-Out Time, default 15 min if not sent by CDM)

**Step 2: Speed profile**
- 20% speed reduction from departure to first waypoint
- 20% speed reduction from last waypoint to arrival
- Add extra distance from `sidStarDistances.txt` for SID/STAR segments
- Compare computed enroute time vs filed `enroute_time` → if mismatch, re-run using groundspeed to align

**Step 3: Altitude profile (the key algorithm)**
- **Climb profile**: Use EuroScope aircraft performance model (climb rate per aircraft type) from ground to cruise altitude
- **Cruise**: Maintain filed flight level
- **Descent profile**: Use EuroScope performance model (descent rate per aircraft type) to compute top-of-descent and descent path
- **SID/STAR restrictions**: Override computed altitudes at specific waypoints per SID/STAR procedure data
- **Profile restrictions**: Override further with `profile_restrictions.txt` entries (format: `DEP:DEST:WAYPOINT:FL`)

**Step 4: Point generation**
- Generate minute-by-minute points along the route with interpolated position, speed, and altitude
- Check each point against airspace volume geometry (airblocks.geojson) for entry/exit detection

### 2.3 Data Sources (vIFF-Capacity-Availability-Document repo)

**`profile_restrictions.txt`** (~1,949 lines globally):
```
# Format: ADEP:ADES:WAYPOINT:FLIGHT_LEVEL
*:ESSA:NILUG:160       # Any departure to Stockholm via NILUG must be at FL160
*:EBOS:COA:70          # Any departure to Ostend via COA at FL70
EHEH:EBBR,EBMB:ELSIK:60  # Specific dep/arr pair via ELSIK at FL60
LSZH:*:KOLUL:120       # Zurich departures to anywhere via KOLUL at FL120
```
- Restriction only applies if filed flight level >= restriction FL
- Overrides SID/STAR altitude restrictions
- Supports wildcards (`*`) and comma-separated airport lists

**`procedures.txt`** (~3,261 bytes):
```
EBAW:SID:C,D,B,E,F,G:STAR:A
EBBR:SID:G,K,L,N,V,T,X,F,E,P,R,T,Y,U,W,M,Q:STAR:A,B
```

**`airblocks.geojson`** (~4.5MB):
- GeoJSON FeatureCollections defining airspace sectors
- Each feature has: MinFL, MaxFL, capacity, lateral boundary polygon
- Used for occupancy/entry counting AND restriction band visualization

---

## 3. CDM Client-Side: How the Chart Works

### 3.1 Technology Stack
- **Chart.js** (v3.x+) for altitude profile chart
- **Leaflet.js** with OpenStreetMap for map
- **Vanilla JavaScript** for projection algorithm
- **Dark theme** CSS (#1e232b background)

### 3.2 Dual-Axis Chart Architecture

```javascript
// Two X-axes: category (planned waypoints) + linear (projected flown points)
scales: {
    xCat: { type: 'category', position: 'top', labels: waypointNames },
    xLin: { type: 'linear', min: 0, max: pathData.length - 1, display: false },
    y:    { beginAtZero: true, title: 'Flight Level (FL)' }
}

// Two datasets
datasets: [
    { label: 'Planned Profile', data: altitudes, xAxisID: 'xCat', borderColor: '#6aa6ff' },
    { label: 'Flown Profile',   data: flownChartPoints, xAxisID: 'xLin', borderColor: '#f6c343' }
]
```

### 3.3 Projection Algorithm (Flown → Planned Route)

The key innovation: mapping actual position reports onto the planned route's x-axis.

```javascript
// Step 1: Convert lat/lon to equirectangular XY using departure as reference
function toXY(lat, lon, lat0Rad) {
    const R = 6371000; // Earth radius meters
    const x = R * (lon * Math.PI/180) * Math.cos(lat0Rad);
    const y = R * (lat * Math.PI/180);
    return [x, y];
}

// Step 2: For each flown point, find nearest planned route segment
function projectPointToSegment(px, py, ax, ay, bx, by) {
    const vx = bx - ax, vy = by - ay;
    const wx = px - ax, wy = py - ay;
    const vv = vx*vx + vy*vy;
    let t = vv > 0 ? (wx*vx + wy*vy) / vv : 0;
    t = Math.max(0, Math.min(1, t)); // clamp to segment
    const qx = ax + t*vx, qy = ay + t*vy;
    const dx = px - qx, dy = py - qy;
    return { t, d2: dx*dx + dy*dy }; // parametric position + squared distance
}

// Step 3: Convert to continuous chart x-coordinate
// x = segment_index + parametric_t (e.g., 3.45 = 45% through segment 3-4)
flownChartPoints.push({ x: best.seg + best.t, y: fp.alt });
```

### 3.4 Restriction Band Plugin

Custom Chart.js plugin `restrictionBandsWithHoverTV`:
- Intersects regulation geometry polygons with planned route segments
- Renders semi-transparent red rectangles (opacity 0.18) spanning affected waypoint range and altitude band
- Provides hover tooltips showing traffic volume name and FL range

---

## 4. PERTI Gap Analysis: What We Have vs What We Need

### 4.1 What PERTI Already Has (Advantages Over CDM)

| Capability | PERTI Status | Notes |
|-----------|-------------|-------|
| **Aircraft performance profiles** | **BADA + OpenAP + 200+ seed types** | Climb rates, descent rates, crossover altitudes, cruise Mach - MORE detailed than CDM's EuroScope profiles |
| **Trajectory capture** | **15-second resolution** with altitude_ft, vertical_rate_fpm | CDM has NO trajectory storage - it uses live EuroScope position data |
| **Waypoint storage** | **planned_alt_ft, is_toc, is_tod, constraint_type** columns exist | Schema supports altitude profiles but columns are NOT populated |
| **Step climb parsing** | **adl_flight_stepclimbs** table | Parses STEP CLIMB instructions from route/remarks |
| **Flight math utilities** | **TOD distance, required vertical speed, ISA temp, speed conversions** | In `simulator/engine/src/math/flightMath.js` |
| **Route parsing** | **PostGIS expand_route()** with SID/STAR resolution | Already resolves procedure names to waypoint sequences |
| **nav_procedures** | **73K+ NASR + 4K intl procedures** | Full SID/STAR route strings but NO altitude legs |
| **Chart.js** | **Already in use** for demand charts | Same charting library as CDM |
| **MapLibre GL** | **Already in use** for route maps | More capable than CDM's Leaflet.js |

### 4.2 What PERTI Needs to Add

| Gap | Priority | Effort | Description |
|-----|----------|--------|-------------|
| **Altitude profile engine** | HIGH | Medium | Algorithm to synthesize smooth climb/cruise/descent profile using performance data |
| **Populate waypoint altitudes** | HIGH | Medium | Extend route parser to compute `planned_alt_ft` at each waypoint during parsing |
| **SID/STAR altitude legs** | MEDIUM | High | Parse ARINC 424 CIFP altitude/speed restrictions per procedure leg |
| **Profile visualization** | HIGH | Low | Chart.js dual-axis altitude chart (CDM code is directly portable) |
| **Flown profile projection** | HIGH | Low | Projection algorithm is ~40 lines of JS, directly portable |
| **Restriction band overlay** | LOW | Low | Optional: overlay airspace altitude constraints |
| **Profile restrictions data** | LOW | Low | Could consume vIFF profile_restrictions.txt |

### 4.3 PERTI's Structural Advantages

1. **Better performance data**: BADA provides FL-specific climb/descent rates (ROCD per flight level), far more granular than EuroScope's single climb/descent rate. OpenAP provides real ADS-B-derived performance.

2. **Historical trajectory storage**: PERTI stores 15-second trajectory data with `altitude_ft` and `vertical_rate_fpm`, enabling actual "flown" profile comparison days/weeks after a flight. CDM can only show flown data while the flight is active.

3. **Existing schema ready**: The `adl_flight_waypoints` table already has `planned_alt_ft`, `is_toc`, `is_tod`, `constraint_type` columns - they just need to be populated.

4. **PostGIS integration**: Route geometry already computed for spatial analysis - altitude profile can reuse distance calculations.

---

## 5. Implementation Approach for PERTI

### Phase 1: Altitude Profile Computation Engine

**New stored procedure or PHP function**: `computeAltitudeProfile($flight_uid)`

**Algorithm** (modeled on CDM + enhanced with BADA data):

```
Input: waypoints[], aircraft_type, filed_altitude, departure_airport, arrival_airport

1. Look up aircraft performance:
   - climb_rate_fpm, descent_rate_fpm (from aircraft_performance_profiles)
   - climb_speed_kias, descent_speed_kias
   - climb_crossover_ft, descent_crossover_ft
   - For BADA: FL-specific ROCD values (more accurate)

2. Compute distances between consecutive waypoints (already done in route parsing)

3. Build climb profile:
   - Start at airport elevation (0 or field elevation)
   - At each waypoint, compute altitude based on:
     altitude += climb_rate * (distance / groundspeed)
   - Cap at filed cruise altitude
   - Mark TOC waypoint (is_toc = 1)

4. Build descent profile (work backwards from destination):
   - Compute TOD distance: (cruise_alt - field_elevation) / descent_rate * groundspeed
   - Walk backwards from destination waypoint
   - Mark TOD waypoint (is_tod = 1)

5. Apply SID/STAR altitude constraints (if available):
   - If waypoint is within SID, enforce altitude restrictions
   - If waypoint is within STAR, enforce altitude restrictions
   - constraint_type: AT, AT_OR_ABOVE, AT_OR_BELOW

6. Apply step climbs (from adl_flight_stepclimbs):
   - Insert altitude changes at specified waypoints

7. UPDATE adl_flight_waypoints SET planned_alt_ft = computed_value
```

### Phase 2: Visualization (Chart.js + MapLibre)

**New JavaScript module**: `assets/js/altitude-profile.js`

**Components**:
1. Dual-axis Chart.js chart (planned + flown profiles)
2. Projection algorithm (directly portable from CDM)
3. MapLibre integration (altitude color-coding on route polyline)

**Data endpoint**: `api/adl/altitude-profile.php?flight_uid=X`
```json
{
    "planned": [
        {"name": "KMIA", "lat": 25.795, "lon": -80.290, "alt_fl": 0, "dist_nm": 0},
        {"name": "DUCEN", "lat": 29.276, "lon": -81.323, "alt_fl": 390, "dist_nm": 245}
    ],
    "flown": [
        [25.805, -80.204, 56, "2026-04-22T20:36:15Z"],
        [25.855, -80.158, 99, "2026-04-22T20:36:30Z"]
    ],
    "performance": {
        "aircraft": "B77W", "climb_rate_fpm": 2800, "descent_rate_fpm": 2200,
        "cruise_fl": 390, "source": "BADA"
    }
}
```

### Phase 3: Integration with Existing Systems

1. **Parse queue daemon**: Add altitude profile computation after waypoint parsing
2. **Waypoint ETA daemon**: Use altitude-aware speed (TAS varies with altitude)
3. **TMI compliance**: Compare actual trajectory altitude against planned profile
4. **SWIM API**: Expose altitude profile via `api/swim/v1/flights/{uid}/profile`

---

## 6. Technical Details: CDM Algorithm Deep Dive

### 6.1 ETFMS Processing Cadence
- **IFPS reprocessing**: Every 2 minutes (route parsing, 4D path generation)
- **ETFMS regulation**: Every 1 minute (CTOT computation)
- **Flight removal**: 10 minutes after disconnect

### 6.2 4D Path: How Minute-Points Are Generated

From the documentation:
1. Compute total route distance from waypoint lat/lon pairs
2. Apply speed schedule: reduced near departure/arrival, cruise speed enroute
3. Generate one point per minute along the route
4. At each point: interpolate lat/lon from route geometry, compute altitude from performance profile
5. Check each point against airspace volumes for occupancy/entry counting

### 6.3 Capacity Validation (3 Modules)

| Module | Metric | Constraint | Delay Step | Max Delay |
|--------|--------|-----------|------------|-----------|
| Airspace Occupancy | Aircraft in sector per minute | Sector capacity | +5 min | 3 hours |
| Airspace Entries | Entries per 20-min bucket | E60/20 bucket limit | +5 min | 3 hours |
| Destination Capacity | Arrivals within +/-1h of ETA | Airport arrival rate | Find gap | 3 hours |

### 6.4 ECFMP Integration
- Per-hour rate → separation in minutes
- Minimum departure interval enforcement
- Average departure interval smoothing

---

## 7. Data Quality Notes

### What CDM Does NOT Do
- No wind field integration (uses filed TAS as-is, with GS reconciliation)
- No weight-based performance (uses type-based averages)
- Simplified climb/descent (not FL-specific ROCD like BADA)
- No continuous descent approaches (CDA) modeling
- Profile restrictions are static text files, not dynamic

### What PERTI Could Do Better
- **FL-specific climb/descent rates** from BADA PTF data (ROCD varies by altitude)
- **Wind-adjusted profiles** using NOAA GFS wind grid data (already available)
- **Weight-class differentiation** (heavy vs light for same aircraft type)
- **Historical trajectory comparison** (compare planned vs flown days later)
- **TMI-aware profiles** (show EDCT impact on departure time and fuel/altitude)

---

## 8. Source Verification

All claims in this document are sourced from:

| Claim | Source | Verification |
|-------|--------|-------------|
| pathData structure | WebFetch of cdm.vatsimspain.es pilot pages | UAE30Y: 81 waypoints, HRT168: 17 waypoints |
| flownPathRaw structure | WebFetch of same pages | UAE30Y: 42 points, HRT168: 21 points |
| Projection algorithm | Extracted JavaScript from page source | Complete code reproduced above |
| Chart.js dual-axis | Extracted JavaScript | Chart configuration reproduced |
| ETFMS algorithm | WebFetch of docs.html page | 3-module validation documented |
| profile_restrictions.txt format | WebFetch of GitHub raw URL | ~1,949 lines globally |
| PERTI performance tables | Source code exploration of scripts/bada/, scripts/openap/ | Schema and parsers verified |
| PERTI waypoint schema | Migration files in adl/migrations/core/ | planned_alt_ft column exists |
| PERTI trajectory schema | Migration files | altitude_ft, vertical_rate_fpm confirmed |
| EuroScope profile source | CDM docs.html | "EuroScope profile per aircraft type" |
| Minute-by-minute generation | CDM docs.html | "Minute-by-minute points along route" |

---

## 9. Repository References

- **CDM EuroScope Plugin**: https://github.com/rpuig2001/CDM (C++, 10K LOC)
- **vIFF Capacity Data**: https://github.com/rpuig2001/vIFF-Capacity-Availability-Document (JS/data)
- **CDM Web Dashboard**: https://cdm.vatsimspain.es/dashboard/ (closed source, PHP)
- **CDM Documentation**: https://cdm.vatsimspain.es/docs.html
- **InitialClimbPlugin**: https://github.com/rpuig2001/InitialCimbPlugin (altitude validation)

---

## 10. Post-Investigation Audit

### Verification Methodology
Each claim was cross-referenced against actual source files or fetched web content. No claim relies solely on an agent's description without primary source verification.

### CDM-Side Claims: All Verified
| Claim | Method | Result |
|-------|--------|--------|
| "EuroScope profile per aircraft type" | Re-fetched docs.html, searched exact phrase | VERBATIM MATCH |
| "Minute-by-minute points along route" | Re-fetched docs.html, searched exact phrase | VERBATIM MATCH |
| "20% speed reduction dep -> first waypoint" | Re-fetched docs.html, searched exact phrase | VERBATIM MATCH |
| "sidStarDistances.txt" referenced | Re-fetched docs.html | VERBATIM MATCH |
| 5-min delay increments | Re-fetched docs.html, "delay flight by 5 min" | VERBATIM MATCH |
| 3 constraint modules | Re-fetched docs.html | CONFIRMED (Occ, Entries, Destination) |
| pathData has 81 waypoints (UAE30Y) | WebFetch of pilot page | CONFIRMED from extracted JS |
| pathData has 17 waypoints (HRT168) | WebFetch of pilot page | CONFIRMED from full array extraction |
| Projection algorithm uses equirectangular | Extracted JS source code | CONFIRMED (toXY function) |
| Chart.js dual-axis (xCat + xLin) | Extracted JS source code | CONFIRMED |
| Web dashboard is PHP | URL pattern (.php extension) | REASONABLE INFERENCE |
| CDM dashboard is closed source | Exhaustive GitHub search found no dashboard repo | REASONABLE INFERENCE |

### PERTI-Side Claims: All Verified
| Claim | Method | Result |
|-------|--------|--------|
| `planned_alt_ft` column in waypoints | Read migration 003 line 43 | CONFIRMED: `INT NULL` |
| `is_toc` column exists | Read migration 003 line 49 | CONFIRMED: `BIT NOT NULL DEFAULT 0` |
| `is_tod` column exists | Read migration 003 line 50 | CONFIRMED: `BIT NOT NULL DEFAULT 0` |
| `constraint_type` column exists | Read migration 003 line 52 | CONFIRMED: `NVARCHAR(16) NULL` |
| `altitude_ft` in trajectory table | Read migration 002 | CONFIRMED: `INT NULL` |
| `vertical_rate_fpm` in trajectory | Read migration 002 | CONFIRMED: `INT NULL` |
| `topOfDescentDistance()` function | Read flightMath.js lines 228-232 | CONFIRMED |
| `requiredVerticalSpeed()` function | Read flightMath.js lines 214-223 | CONFIRMED |
| `getIsaTemp()` function | Read flightMath.js lines 168-173 | CONFIRMED |
| BADA PTF parser has FL-specific ROCD | Read bada_ptf_parser.py lines 70,75 | CONFIRMED |
| OpenAP import has climb/descent rates | Read openap_import.py (MS_TO_FPM=196.85) | CONFIRMED |
| `climb_crossover_ft` column | Grep found in migration 002 line 168 | CONFIRMED (ALTER TABLE ADD) |
| `descent_crossover_ft` column | Grep found in migration 002 line 174 | CONFIRMED (ALTER TABLE ADD) |
| Waypoint ETA daemon does NOT compute altitude | Read waypoint_eta_daemon.php | CONFIRMED: ETA only |
| Performance seed has 200+ aircraft | Read migration 001 header | CONFIRMED from INSERT pattern |

### Corrections Applied
1. **No corrections needed** - all claims verified against primary sources
2. **Clarification**: The document correctly states the waypoint ETA daemon does NOT compute altitude (it was never claimed otherwise)
3. **Note**: The "closed source, PHP" label for the CDM dashboard is a reasonable inference from URL patterns and exhaustive GitHub search, not a confirmed fact

### Potential Limitations
1. The CDM docs page provides limited detail on the actual climb/descent rate algorithm - it references "EuroScope profile" without specifying the mathematical model
2. We cannot see the CDM server-side code that generates `pathData` - the waypoint altitude values are pre-computed and delivered as JSON in the HTML
3. The difference between UAE30Y (detailed climb profile, 81 waypoints) and HRT168 (simplified profile, 17 waypoints) suggests the server uses different resolution based on SID/STAR availability or route complexity - this is an inference, not a confirmed behavior
