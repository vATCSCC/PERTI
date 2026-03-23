# PERTI Computational Reference

> **System Status: HIBERNATED** (since March 22, 2026, SWIM exempt) — See `HIBERNATION_RUNBOOK.md` for exit procedure.

Detailed technical reference for every computational system, algorithm, formula, and data pipeline in PERTI. This document supplements `DEPLOYMENT_GUIDE.md` with the implementation details needed to understand, maintain, and extend each subsystem.

---

## Table of Contents

1. [ADL Data Ingestion Pipeline](#1-adl-data-ingestion-pipeline)
2. [Route Parsing System](#2-route-parsing-system)
3. [Boundary Detection System](#3-boundary-detection-system)
4. [Boundary Crossing Prediction](#4-boundary-crossing-prediction)
5. [Waypoint ETA Calculation](#5-waypoint-eta-calculation)
6. [GDP/Ground Delay Program System](#6-gdpground-delay-program-system)
7. [Ground Stop System](#7-ground-stop-system)
8. [TMI Advisory & Discord Publishing](#8-tmi-advisory--discord-publishing)
9. [TMI Compliance Analysis](#9-tmi-compliance-analysis)
10. [SWIM API & WebSocket System](#10-swim-api--websocket-system)
11. [Statistics & Analytics Pipeline](#11-statistics--analytics-pipeline)
12. [Airport Configuration & ATIS Processing](#12-airport-configuration--atis-processing)
13. [Demand Visualization](#13-demand-visualization)
14. [Archival & Data Retention](#14-archival--data-retention)
15. [Cost & Compute Implications](#15-cost--compute-implications)

---

## 1. ADL Data Ingestion Pipeline

**Script**: `scripts/vatsim_adl_daemon.php`
**Interval**: Every 15 seconds
**Data source**: `https://data.vatsim.net/v3/vatsim-data.json`
**Peak load**: 3,000-6,000 flights per cycle
**Current SP version**: V9.4.0 (with Route Distance V2.2)

### 1.1 Main Loop Architecture

```
┌─────────────────────────────────────────────────┐
│ MAIN LOOP (every 15 seconds)                    │
├─────────────────────────────────────────────────┤
│ 1. Fetch VATSIM JSON (~2-4MB, gzipped)          │
│ 2. PHP-side JSON parsing (V9.0 staged)          │
│ 3. Insert to staging tables (bulk literal)      │
│ 4. Call SP: sp_Adl_RefreshFromVatsim_Staged     │
│ 5. Process deferred work (time-budgeted)        │
│ 6. ATIS processing (tiered)                     │
│ 7. Sleep remaining time to hit 15s interval     │
└─────────────────────────────────────────────────┘
```

### 1.2 Step 1: VATSIM Data Fetch

Uses cURL with gzip compression, TCP Fast Open, and Nagle's algorithm disabled for low latency.

**Input**: VATSIM v3 JSON API
**Output**: Raw JSON string (~2-4MB)
**Typical latency**: 200-500ms

The JSON contains:
- `pilots[]` — Active connected pilots with position, flight plan, callsign
- `prefiles[]` — Filed flight plans not yet connected
- `atis[]` — ATIS broadcasts from controller clients
- `controllers[]` — Active ATC positions

### 1.3 Step 2: PHP-Side JSON Parsing (V9.0 Staged Architecture)

Instead of passing the entire JSON to SQL Server's OPENJSON (which consumed 3-5s of SQL compute), PERTI parses JSON in PHP and inserts structured data into staging tables.

**Pilot parsing** (`parseVatsimPilots()`):

```pseudo
FOR each pilot in vatsimData.pilots:
    flight_key = "{cid}|{callsign}|{dept}|{dest}|{deptime}"
    route_hash = SHA256("{route}|{remarks}")    // Binary, for change detection
    airline_icao = first 3 chars of callsign IF matches /^[A-Z]{3}[0-9]/

    Collect fields: cid, callsign, lat, lon, altitude_ft, groundspeed_kts,
                    heading_deg, qnh, logon_time, flight_plan fields...

    Truncate all strings to their max column widths
```

**Prefile parsing** (`parseVatsimPrefiles()`):

```pseudo
FOR each prefile in vatsimData.prefiles:
    Skip if no callsign
    flight_key = "{cid}|{callsign}|{dept}|{dest}|{deptime}"
    route_hash = MD5("{route}")    // MD5 for prefiles (lighter weight)
```

### 1.4 Step 3: Staging Table Insert (V9.2 Bulk Literal Mode)

Inserts use bulk literal VALUES (no parameterized queries) to reduce round-trips:

```pseudo
TRUNCATE adl_staging_pilots
TRUNCATE adl_staging_prefiles

// Batch size: 1000 rows per INSERT statement (bulk literal mode)
FOR each batch of 1000 pilots:
    INSERT INTO adl_staging_pilots (cid, callsign, lat, lon, ...)
    VALUES (1482851, 'UAL2175', 39.3048, -74.1346, 34173, 372, ...),
           (1471199, 'DAL2187', 28.4303, -82.3868, 36176, 419, ...),
           ...

// Reduces ~43 round trips to ~3 round trips for ~3000 pilots
```

**Performance**: ~300-500ms for 3000 pilots (down from ~2000ms with parameterized batches).

### 1.5 Step 4: Stored Procedure — `sp_Adl_RefreshFromVatsim_Staged`

The main SP processes staged data through 13 steps. Each step returns timing data for performance monitoring.

| Step | Name | What It Does | Typical Time |
|------|------|-------------|-------------|
| **1** | JSON Parse | Read from staging tables (V9.0: no OPENJSON needed) | 50-100ms |
| **1b** | Enrich | Match airline codes, compute flight_key hash, aircraft lookup | 200-400ms |
| **2** | Core MERGE | MERGE into `adl_flight_core` (new/update/heartbeat) | 300-600ms |
| **2a** | Prefile | Process prefiles into core (separate MERGE) | 50-150ms |
| **2b** | Times | Update `adl_flight_times` (ETD/ETA calculations) | 200-400ms |
| **3** | Position | MERGE into `adl_flight_position` (lat/lon/alt/gs/hdg) | 200-400ms |
| **4** | Flight Plan | MERGE into `adl_flight_plan` (route, aircraft, altitude) | 200-400ms |
| **4b** | ETD | Calculate estimated departure times | 100-200ms |
| **4c** | SimBrief | Detect SimBrief flight plans from remarks field | 50-100ms |
| **5** | Parse Queue | Enqueue changed routes for parsing | 100-200ms |
| **5b** | Route Dist | Two-pass LINESTRING route distance calculation | 200-400ms |
| **6** | Aircraft | MERGE into `adl_flight_aircraft` (type, weight class, engine) | 100-200ms |
| **7** | Inactive | Mark disappeared flights as inactive | 100-200ms |
| **8** | Trajectory | INSERT into `adl_flight_trajectory` (position history) | 300-600ms |
| **8b** | Bucket | Assign arrival/departure time buckets | 50-100ms |
| **8c** | Waypoint | Update next waypoint progress | 100-200ms |
| **8d** | Batch ETA | Wind-adjusted ETA calculation (deferred if over budget) | 0-800ms |
| **9** | Zone | Airport zone detection (OOOI events) | 0-200ms |
| **10** | Boundary | ARTCC/TRACON boundary detection (if not using GIS daemon) | 0-500ms |
| **11** | Crossings | Boundary crossing prediction (if not using GIS daemon) | 0-300ms |
| **12** | Log | Performance logging, metrics capture | 10-50ms |
| **13** | Snapshot | Periodic full ADL snapshot (every 5 min) | 0-500ms |

**Total SP time**: 2,000-5,000ms typical (target: under 10,000ms)

#### Delta Detection (Bitmask Filter)

The SP uses a delta detection bitmask to skip unchanged data:

```sql
-- Steps are only executed when their bitmask bit is set
-- Bitmask computation:
--   Bit 0 (1):  Step 1b - Enrichment (always runs)
--   Bit 1 (2):  Step 2  - Core MERGE
--   Bit 2 (4):  Step 3  - Position
--   Bit 3 (8):  Step 4  - Flight Plan
--   Bit 4 (16): Step 6  - Aircraft
--   Bit 5 (32): Step 8  - Trajectory (ALWAYS runs - ephemeral data)

-- Delta check: compare route_hash between staging and existing
-- If route_hash unchanged AND position within 0.01 degrees: skip flight plan step
```

This provides ~30-40% reduction in compute per cycle.

#### Route Distance Calculation (V2.2 Two-Pass LINESTRING)

```sql
-- Step 5b: Calculate route distance using parsed waypoints
-- Two-pass approach:
--   Pass 1: Build geography LINESTRING from waypoints
--   Pass 2: Measure STLength() in meters, convert to nautical miles

UPDATE fp SET
    route_total_nm = CASE
        WHEN fp.route_geometry IS NOT NULL
        THEN fp.route_geometry.STLength() / 1852.0  -- meters to NM
        ELSE fp.gcd_nm  -- fallback to great circle distance
    END,
    route_dist_nm = CASE
        WHEN fp.route_geometry IS NOT NULL AND pos.dist_flown_nm IS NOT NULL
        THEN (fp.route_geometry.STLength() / 1852.0) - pos.dist_flown_nm
        ELSE NULL
    END
FROM adl_flight_plan fp
JOIN adl_flight_position pos ON pos.flight_uid = fp.flight_uid
WHERE fp.waypoint_count >= 2
```

### 1.6 Step 5: Deferred Work (Time Budget System, V9.2)

After the SP returns, the daemon checks the remaining time budget:

```pseudo
cycle_budget_ms = 15000  // 15-second cycle
sp_elapsed_ms = result.elapsed_ms
remaining_ms = cycle_budget_ms - sp_elapsed_ms - 2000  // 2s safety margin

IF remaining_ms > 300:
    Run basic ETA (sp_ProcessTrajectoryBatch @process_eta=1)
IF remaining_ms > 500 AND (cycle % deferred_eta_interval == 0):
    Run batch ETA with wind integration (sp_CalculateETABatch)
IF remaining_ms > 100:
    Run legacy trajectory log (sp_Log_Trajectory)
IF remaining_ms > 100 AND (5 min since last snapshot):
    Run phase snapshot (sp_CapturePhaseSnapshot)
IF remaining_ms > 2000 AND (50 cycles since last gap check):
    Run gap detection & OOOI backfill
ELSE IF remaining_ms <= 0:
    Skip all deferred work, log warning
```

### 1.7 Step 6: ATIS Processing (Tiered)

ATIS data is fetched from the same VATSIM JSON and processed at tiered intervals:

| Tier | Airports | Interval | Criteria |
|------|----------|----------|----------|
| 0 | METAR window + ASPM82 | 15s | ASPM82 airports within 5 min of hour (METAR update), OR ASPM82 with bad weather |
| 1 | ASPM82 normal | 1 min | 71 FAA performance airports in normal weather |
| 2 | Regional | 5 min | Non-ASPM82 US (K*/P*), Canada (C*), Mexico/Central America (M*), South America (S*), Caribbean (T*) |
| 3 | Other staffed | 30 min | All other airports with active ATIS |
| 4 | Clear weather | 60 min | Non-priority airports (tier 3+) in clear weather |

**ATIS parsing** extracts:
- Active runways (arrival/departure/both)
- Weather conditions (VMC/LVMC/IMC/LIMC — mapped from FAA VFR/MVFR/IFR/LIFR)
- Airport configuration (runway combination)
- Wind information

### 1.8 Compute Cost Implications

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| SP executions per day | 5,760 (every 15s) | Continuous vCore usage; dominates ADL compute |
| Average vCore usage | 3-6 vCores sustained | Hyperscale Serverless Gen5 min 3 vCores in production |
| Peak vCore usage | 8-16 vCores (during events) | Auto-scales; DTU-based tiers throttle at peaks |
| Staging table inserts | ~3000 rows x 5760/day = 17.3M rows/day | I/O cost is primary driver |
| Trajectory inserts | ~3000 x 5760/day = 17.3M rows/day | Largest table growth |
| Network transfer | ~2-4MB x 5760 = 11-23GB/day from VATSIM API | Free (outbound) |

> **Important**: DTU-based tiers (S0-S3) impose hard compute caps. The SP's 8-table MERGE pattern with concurrent daemon queries (parse queue, boundary detection, crossing prediction, SWIM sync) creates sustained compute load that frequently exceeds DTU limits on S2 (50 DTU) at 3,000+ concurrent flights. vATCSCC moved to Hyperscale Serverless because DTU throttling caused missed 15-second VATSIM feeds and cascading delays in dependent daemons. See Section 15.3 for scaling guidance.

---

## 2. Route Parsing System

**Script**: `adl/php/parse_queue_gis_daemon.php`
**Interval**: Continuous, 10-second batch cycles
**Batch size**: 50 routes per cycle

### 2.1 Architecture

```
┌────────────────────────────┐     ┌─────────────────────────┐
│ ADL Ingest SP              │     │ parse_queue_gis_daemon   │
│ Detects route changes      │────>│ Reads from parse queue   │
│ Inserts to adl_parse_queue │     │ Parses via PostGIS       │
│                            │     │ Writes results to ADL    │
└────────────────────────────┘     └─────────────────────────┘
```

### 2.2 Queue Processing Algorithm

```pseudo
LOOP every 10 seconds:
    1. Reset stuck items (PROCESSING > 5 min old → PENDING)
    2. Backfill orphaned flights (parse_status=PENDING but not in queue)
    3. Claim batch of 50 routes (with UPDLOCK, READPAST for concurrency)
       ORDER BY parse_tier ASC, queued_utc ASC
    4. FOR each route in batch:
       a. Try PostGIS parsing first (parseRouteGIS)
       b. On failure, fall back to ADL SP (parseRouteADL)
       c. Write results to ADL tables
       d. Mark queue item COMPLETE or FAILED
    5. Log stats: GIS success count, ADL fallback count, failures
```

### 2.3 PostGIS Route Parsing (`GISService::expandRoute`)

The PostGIS function `expand_route_with_artccs()` parses a route string into waypoints:

```pseudo
INPUT: "RBV Q430 BYRDD J48 MOL FLASK OZZZI2"  // Real DAL2190 KJFK→KATL route

Step 1: Tokenize route string by spaces
Step 2: FOR each token:
    - If it matches a fix name in nav_fixes → add as waypoint
    - If it matches an airway name in airways → expand all segments
    - If "DCT" → skip (direct routing between adjacent fixes)
    - If departure airport → resolve to airport coordinates
    - If arrival airport → resolve to airport coordinates

Step 3: Build LineString geometry from waypoints
Step 4: Intersect LineString with artcc_boundaries to get traversed ARTCCs
Step 5: Calculate total route distance (ST_Length in meters / 1852 = NM)

OUTPUT: {
    waypoints: [{fix_name: "RBV", lat: 40.2024, lon: -74.4950, type: "nav_fix"},
                {fix_name: "FLASK", lat: 37.0178, lon: -80.3163, type: "nav_fix"}, ...],
    artccs: ["ZNY", "ZDC", "ZTL"],
    distance_nm: 333.6,
    geojson: {...}   // LineString for map display
}
```

### 2.4 ADL Stored Procedure Fallback (`sp_ParseRoute`)

If PostGIS is unavailable, falls back to SQL Server's `sp_ParseRoute` which:
1. Splits route string by spaces
2. Looks up each token in `nav_fixes` table
3. Expands airway segments from `airway_segments` table
4. Builds geography LINESTRING
5. Uses geography.STIntersects() against `adl_boundary` for ARTCC detection

**Performance comparison**:
- PostGIS: ~50-100ms per route
- ADL SP: ~150-300ms per route (2-3x slower due to geography CLR overhead)

### 2.5 Result Storage

After parsing, the daemon writes to ADL:

```sql
-- Update adl_flight_plan with parsed results
UPDATE dbo.adl_flight_plan SET
    fp_route_expanded = 'KJFK MERIT PONEE LITOL KATL',  -- Expanded fix names
    parse_status = 'COMPLETE',
    waypoint_count = 5,
    route_total_nm = 760.3,
    waypoints_json = '[{"fix_id":"MERIT","lat":40.52,"lon":-73.44,...}]',
    route_geometry = geography::STGeomFromText('LINESTRING(-73.78 40.64, -73.44 40.52, ...)', 4326),
    dp_name = 'DEEZZ5',     -- Extracted SID name
    star_name = 'PECHY2',   -- Extracted STAR name
    dfix = 'MERIT',          -- Departure fix
    afix = 'LITOL'           -- Arrival fix
WHERE flight_uid = @flight_uid

-- Delete old waypoints and insert new ones
DELETE FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid
INSERT INTO dbo.adl_flight_waypoints (flight_uid, sequence_num, fix_name, lat, lon, fix_type, ...)
VALUES (@flight_uid, 1, 'KJFK', 40.6413, -73.7781, 'APT', ...),
       (@flight_uid, 2, 'MERIT', 40.52, -73.44, 'FIX', ...),
       ...
```

### 2.6 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| Routes parsed per day | ~2,000-5,000 (new/changed routes) | Minimal — PostGIS B1ms handles easily |
| PostGIS CPU per parse | ~50-100ms | B1ms has 2 vCores, can do ~20 parses/sec |
| ADL writes per parse | 2-3 queries (plan update, waypoint delete, waypoint insert) | ~$0.10/day |

---

## 3. Boundary Detection System

**Script**: `adl/php/boundary_gis_daemon.php`
**Interval**: Every 15 seconds
**Processing**: Tiered, max 100 flights per cycle (`DEFAULT_MAX_FLIGHTS=100`)

### 3.1 Algorithm

For each active flight needing boundary detection, determines which ARTCC, TRACON, and sectors the flight is currently inside. Uses tiered processing (not all flights every cycle):

**Boundary Detection Tiers** (matches `sp_ProcessBoundaryAndCrossings_Background`):
| Tier | Criteria | Frequency |
|------|----------|-----------|
| 1 | New flights (no `current_artcc_id`) | Every cycle |
| 2 | Grid cell changed (moved >0.5° lat or lon) | Every cycle |
| 3 | Below FL180 (terminal airspace) | Every 2 cycles |
| 4 | Enroute (FL180-FL450) | Every 5 cycles |
| 5 | High altitude (FL450+) | Every 10 cycles |

```pseudo
LOOP every 15 seconds:
    1. Select flights needing boundary check (tiered, max 100 per cycle)
    2. FOR each batch of flights:
       a. Send positions to PostGIS as JSON array
       b. PostGIS function: get_boundaries_at_point(lat, lon, altitude)
          - ST_Contains(artcc_boundaries.geom, ST_SetSRID(ST_MakePoint(lon, lat), 4326))
          - Returns: artcc_code, tracon_code, sector_low, sector_high, sector_superhigh
       c. Compare results with current values in adl_flight_core
       d. If changed: UPDATE adl_flight_core SET current_artcc = ..., boundary_updated_at = NOW()
       e. Log boundary transitions to adl_flight_boundary_log
```

### 3.2 PostGIS Point-in-Polygon

```sql
-- PostGIS function for boundary detection
SELECT
    a.artcc_code,
    t.tracon_code,
    s_low.sector_code AS sector_low,
    s_high.sector_code AS sector_high
FROM artcc_boundaries a
LEFT JOIN tracon_boundaries t ON ST_Contains(t.geom, point_geom)
LEFT JOIN sector_boundaries s_low ON ST_Contains(s_low.geom, point_geom)
    AND s_low.floor_altitude <= altitude_ft AND s_low.ceiling_altitude > altitude_ft
    AND s_low.sector_type = 'LOW'
LEFT JOIN sector_boundaries s_high ON ST_Contains(s_high.geom, point_geom)
    AND s_high.floor_altitude <= altitude_ft AND s_high.ceiling_altitude > altitude_ft
    AND s_high.sector_type = 'HIGH'
WHERE ST_Contains(a.geom, point_geom)
LIMIT 1
```

### 3.3 Grid Optimization

To avoid checking all ~1000 boundary polygons for each flight, a pre-computed grid lookup table (`adl_boundary_grid`) maps 0.5-degree lat/lon cells to candidate boundaries:

```sql
-- Pre-computed: which boundaries intersect each grid cell
-- Grid cells are 0.5° x 0.5° (~30nm at mid-latitude, defined as GRID_SIZE constant)
SELECT boundary_id, boundary_code, boundary_type
FROM adl_boundary_grid
WHERE grid_lat = CAST(FLOOR(flight_lat / 0.5) AS SMALLINT)
  AND grid_lon = CAST(FLOOR(flight_lon / 0.5) AS SMALLINT)
```

This eliminates ~95% of polygon checks per flight.

### 3.4 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| Flights processed per cycle | Up to 100 (tiered, prioritized by staleness) | PostGIS point-in-polygon: ~1ms each |
| PostGIS CPU per cycle | ~0.1-0.5 seconds | B1ms (2 vCores) handles easily |
| Boundary changes per cycle | ~10-50 (flights crossing boundaries) | Minimal write cost |

---

## 4. Boundary Crossing Prediction

**Script**: `adl/php/crossing_gis_daemon.php`
**Interval**: Tiered (15s to 5min based on flight phase)

### 4.1 Algorithm

Predicts when each flight will cross ARTCC boundaries in the future, based on parsed route and current position.

```pseudo
FOR each active flight with parsed route (waypoint_count >= 2):
    1. Get current position (lat, lon, groundspeed, heading)
    2. Get remaining waypoints ahead of current position
    3. Build projected route LineString from current position through remaining waypoints
    4. Intersect projected route with ARTCC boundaries (PostGIS)
    5. FOR each intersection point:
       a. Calculate distance from current position to intersection (ST_Length)
       b. Calculate ETA at intersection:
          time_to_crossing = distance_nm / groundspeed_kts * 60  (minutes)
          crossing_eta = now() + time_to_crossing
       c. Identify entry/exit ARTCC codes
    6. MERGE results into adl_flight_planned_crossings
```

### 4.2 PostGIS Intersection

```sql
-- Line-polygon intersection for crossing prediction
SELECT
    b.artcc_code,
    b.boundary_type,
    ST_AsText(ST_Intersection(route_line, b.geom)) AS crossing_point,
    ST_Length(ST_MakeLine(current_point, ST_Intersection(route_line, b.geom))::geography) / 1852.0 AS distance_nm
FROM artcc_boundaries b
WHERE ST_Intersects(route_line, b.geom)
ORDER BY distance_nm
```

### 4.3 Tiered Processing

Base interval: 30 seconds. Tiers are cycle multipliers.

| Tier | Interval | Criteria |
|------|----------|----------|
| 0 | 30s (every cycle) | Flights within 30nm of next waypoint (imminent) |
| 1 | 60s (every 2 cycles) | Enroute flights 30-100nm from next waypoint |
| 2 | 2min (every 4 cycles) | All other enroute flights |
| 3 | 4min (every 8 cycles) | Climbing/descending flights |
| 4 | 8min (every 16 cycles) | Prefiles and taxiing |

### 4.4 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| Flights processed per minute | ~100-300 (tiered) | PostGIS intersection: ~5-20ms each |
| PostGIS CPU utilization | ~10-30% of B1ms | Comfortable at current scale |

---

## 5. Waypoint ETA Calculation

**Script**: `adl/php/waypoint_eta_daemon.php`
**Interval**: Tiered (same tier definitions as crossing daemon, but base interval 15s instead of 30s)
**SP**: `dbo.sp_CalculateWaypointETABatch_Tiered`

### 5.1 Algorithm

Calculates estimated time of arrival at each remaining waypoint along a flight's route.

```pseudo
FOR each active flight with parsed route and position:
    1. Determine current position and next waypoint
    2. Calculate distance to each remaining waypoint using cumulative segment distances
    3. FOR each remaining waypoint:
       a. segment_dist_nm = cum_dist_nm[waypoint] - dist_flown_nm
       b. IF wind data available:
          effective_gs = groundspeed_kts + wind_component_kts
          // wind_component = wind_speed * cos(wind_dir - track)
       c. ELSE:
          effective_gs = groundspeed_kts
       d. time_to_waypoint_hrs = segment_dist_nm / effective_gs
       e. eta_utc = current_utc + time_to_waypoint_hrs * 3600
    4. UPDATE adl_flight_waypoints SET eta_utc = calculated_eta
    5. Update next_waypoint_seq and next_waypoint_name in adl_flight_position
```

### 5.2 Wind Adjustment

When wind data is available (from NOAA GFS via `services/wind/`):

```pseudo
// Wind component calculation
headwind_component = wind_speed_kts * cos(wind_direction - flight_track)
effective_groundspeed = true_airspeed - headwind_component

// If tailwind: effective_gs > TAS (faster)
// If headwind: effective_gs < TAS (slower)
```

### 5.3 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| Waypoint updates per minute | ~5,000-15,000 waypoints | ~$0.05/day ADL write cost |
| PostGIS distance calculations | 0 (uses pre-computed cum_dist_nm) | No PostGIS cost |

---

## 6. GDP/Ground Delay Program System

**Database**: VATSIM_TMI
**Algorithm**: CASA-FPFS + RBD hybrid (all 4 phases complete, migrations 037-041)
**Current SP version**: V9.4.0 (with Route Distance V2.2)
**Key stored procedures**: `sp_TMI_CreateProgram`, `sp_TMI_GenerateSlots`, `sp_TMI_AssignFlightsRBS`, `sp_TMI_CompressProgram`, `sp_TMI_ReoptimizeProgram`

### 6.1 GDP Lifecycle

```
PROPOSED → MODELING → ACTIVE → PURGED/CANCELLED
                              → SUPERSEDED (by new GDP)
```

### 6.2 Slot Generation Algorithm (`sp_TMI_GenerateSlots`)

```pseudo
INPUT: program_id, rates_hourly_json (optional)
READS: program_rate, reserve_rate, start_utc, end_utc from tmi_programs

// Calculate slot interval
slot_interval_sec = 3600 / program_rate
// Example: rate=30/hr → slot every 120 seconds (2 minutes)

// For GAAP/UDP: calculate reserve slot spacing
IF program_type IN (GDP-GAAP, GDP-UDP) AND reserve_rate > 0:
    reserve_interval = program_rate / reserve_rate
    // Example: rate=30, reserve=5 → every 6th slot is reserved

// Generate slot timeline
current_time = start_utc
slot_index = 1
suffix_counter = 0
last_minute = -1

WHILE current_time < end_utc:
    // Handle multiple slots in same minute (suffix A, B, C...)
    slot_minute = HOUR(current_time) * 100 + MINUTE(current_time)
    IF slot_minute == last_minute:
        suffix_counter++
    ELSE:
        suffix_counter = 0
        last_minute = slot_minute

    suffix_char = CHR(65 + suffix_counter)  // A=65, B=66...

    // Slot name format: KJFK.091530A (airport.DDHHmmSuffix)
    slot_name = ctl_element + "." + FORMAT(current_time, "ddHHmm") + suffix_char

    // Determine slot type
    IF reserve_interval > 0 AND (slot_index MOD reserve_interval) == 0:
        slot_type = "RESERVED"
    ELSE:
        slot_type = "REGULAR"

    // Bin assignment (for GDT display grouping)
    bin_date = DATE(current_time)          // DATE portion for multi-day GDPs
    bin_hour = HOUR(current_time)
    bin_quarter = (MINUTE(current_time) / 15) * 15  // 0, 15, 30, 45

    INSERT INTO tmi_slots (program_id, slot_name, slot_index, slot_time_utc,
                           slot_type, slot_status, bin_date, bin_hour, bin_quarter)

    current_time += slot_interval_sec seconds
    slot_index++

OUTPUT: slot_count (total slots generated)
```

**Example**: Rate 30/hr from 14:00Z to 18:00Z = 120 slots, each 2 minutes apart.

### 6.3 RBS (Ration By Schedule) Algorithm (`sp_TMI_AssignFlightsRBS`)

The RBS algorithm assigns flights to arrival slots based on their scheduled arrival time.

```pseudo
INPUT: program_id, flights (TVP of flight_uid, callsign, eta_utc, ...)

// Phase 1: Clear existing assignments
DELETE FROM tmi_flight_control WHERE program_id = @program_id
UPDATE tmi_slots SET slot_status = 'OPEN', assigned_flight_uid = NULL WHERE program_id = @program_id

// Phase 2: Process flights in ETA order (earliest first)
CURSOR: SELECT * FROM @flights ORDER BY eta_utc, flight_uid

FOR each flight in ETA order:
    IF flight.is_exempt:
        // Record as exempt, no slot assigned
        INSERT INTO tmi_flight_control (flight_uid, ctl_exempt = 1, exempt_reason)
        exempt_count++
        CONTINUE

    // Find next available slot at or after flight's ETA
    slot = SELECT TOP 1 FROM tmi_slots
           WHERE program_id = @program_id
             AND slot_status = 'OPEN'
             AND slot_type = 'REGULAR'
             AND slot_time_utc >= flight.eta_utc
           ORDER BY slot_time_utc

    // Overflow: try RESERVED slots for GAAP/UDP
    IF slot IS NULL AND program_type IN (GDP-GAAP, GDP-UDP):
        slot = SELECT TOP 1 FROM tmi_slots
               WHERE program_id = @program_id AND slot_status = 'OPEN'
                 AND slot_time_utc >= flight.eta_utc
               ORDER BY slot_time_utc

    IF slot IS NOT NULL:
        // Calculate delay
        delay_min = DATEDIFF(MINUTE, flight.eta_utc, slot.slot_time_utc)
        delay_capped = 0

        // Cap delay at program limit
        IF delay_min > program.delay_limit_min:
            delay_min = program.delay_limit_min
            delay_capped = 1

        // Calculate Controlled Time of Arrival (CTA) = slot time
        cta_utc = slot.slot_time_utc

        // Calculate Controlled Time of Departure (CTD) = EDCT
        // CTD = CTA - (original ETA - original ETD) = original ETD + delay
        IF flight.etd_utc IS NOT NULL:
            ete_min = DATEDIFF(MINUTE, flight.etd_utc, flight.eta_utc)
            ctd_utc = DATEADD(MINUTE, -ete_min, cta_utc)
        ELSE:
            ctd_utc = NULL

        // Assign flight to slot
        UPDATE tmi_slots SET
            slot_status = 'ASSIGNED',
            assigned_flight_uid = flight.flight_uid,
            assigned_callsign = flight.callsign,
            original_eta_utc = flight.eta_utc,
            slot_delay_min = delay_min
        WHERE slot_id = slot.slot_id

        // Create flight control record
        INSERT INTO tmi_flight_control (
            flight_uid, callsign, program_id, slot_id,
            ctd_utc, cta_utc, orig_eta_utc, orig_etd_utc,
            program_delay_min, delay_capped, ctl_elem, ctl_type
        )

        assigned_count++

OUTPUT: assigned_count, exempt_count
```

### 6.4 Delay Formula

```
delay_minutes = slot_time_utc - original_eta_utc

EDCT (Expected Departure Clearance Time) = original_etd_utc + delay_minutes
  OR equivalently:
EDCT = slot_time_utc - enroute_time_estimate

CTA (Controlled Time of Arrival) = slot_time_utc

If delay_minutes > delay_limit_min:
    delay_minutes = delay_limit_min  (capped)
    delay_capped = true
```

### 6.5 Program Statistics

After RBS assignment, the SP calculates program-level stats:

```sql
UPDATE tmi_programs SET
    total_flights = (SELECT COUNT(*) FROM tmi_flight_control WHERE program_id = @program_id),
    controlled_flights = (SELECT COUNT(*) WHERE ctl_exempt = 0),
    exempt_flights = (SELECT COUNT(*) WHERE ctl_exempt = 1),
    avg_delay_min = (SELECT AVG(program_delay_min) WHERE ctl_exempt = 0),
    max_delay_min = (SELECT MAX(program_delay_min) WHERE ctl_exempt = 0),
    total_delay_min = (SELECT SUM(program_delay_min) WHERE ctl_exempt = 0)
```

### 6.6 GDP Compression

```pseudo
// Compression removes unnecessary delay by shifting flights to earlier slots
// when actual departures or cancellations create earlier slots

FOR each slot with assigned flight (from end to start):
    IF there exists an earlier OPEN slot:
        current_delay = slot.delay_min
        earlier_slot_delay = DATEDIFF(MINUTE, flight.eta_utc, earlier_slot.slot_time_utc)

        IF earlier_slot_delay < current_delay:
            // Move flight to earlier slot
            SWAP assignment from current_slot to earlier_slot
            // Recalculate EDCT
```

### 6.7 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| Active GDPs at once | 0-3 typically | Minimal — slot generation is one-time per GDP |
| RBS assignment | ~100-500 flights per GDP | ~100-500ms per run |
| Slot count per GDP | 30-360 (1hr at 30/hr to 12hr at 30/hr) | Minimal storage |
| TMI database DTU usage | 2-5 DTU during GDP operations | Basic tier (5 DTU) sufficient |

---

## 7. Ground Stop System

**API endpoints**: `api/tmi/gs/create.php`, `activate.php`, `extend.php`, `purge.php`
**Database**: VATSIM_TMI

### 7.1 Ground Stop Lifecycle

```
PROPOSED → ACTIVE → EXTENDED → PURGED/CANCELLED
                              → EXPIRED (auto, past end_utc)
```

### 7.2 Ground Stop Mechanics

```pseudo
CREATE Ground Stop:
    1. Create tmi_programs record (program_type = 'GS')
    2. Set start_utc, end_utc, ctl_element (airport)
    3. No slots generated (GS has no arrival slots)

ACTIVATE Ground Stop:
    1. Call sp_GS_IssueEDCTs(@program_id, @activated_by)
    2. SP finds all flights destined for ctl_element with ETD in [start_utc, end_utc]
    3. Issues EDCTs (gs_held = 1) on tmi_flight_control records
    4. Clear any conflicting GDP EDCTs (ground stop overrides GDP)
    5. Post advisory to Discord

EXTEND Ground Stop:
    1. Update end_utc to new end time
    2. Re-evaluate flights: capture newly affected flights
    3. Post extension advisory

PURGE Ground Stop:
    1. Set status = 'PURGED'
    2. For held flights: set gs_release_utc = NOW(), gs_held = 0
    3. If GDP exists: re-run RBS to assign released flights to GDP slots
    4. Post purge advisory
```

### 7.3 GS Delay Calculation

Ground Stop delay is measured differently from GDP:

```pseudo
// For flights held by a Ground Stop:
gs_delay = MAX(0, (actual_off_utc - original_etd_utc))

// Using OOOI events (more accurate):
gs_delay = MAX(0, (OFF_utc - OUT_utc) - unimpeded_taxi_time(airport))

// unimpeded_taxi_time comes from airport_taxi_reference table
// (daily-computed p5-p15 percentile of taxi times, 90-day rolling window)
```

---

## 8. TMI Advisory & Discord Publishing

**Scripts**: `load/discord/MultiDiscordAPI.php`, `scripts/tmi/process_discord_queue.php`
**Database**: VATSIM_TMI (`tmi_advisories`, `tmi_discord_posts`)

### 8.1 Advisory Number Generation

Uses `sp_GetNextAdvisoryNumber` with atomic MERGE on `tmi_advisory_sequences` table (not the advisories table itself). The sequence table tracks a per-day counter.

```sql
-- sp_GetNextAdvisoryNumber: Atomic advisory number via MERGE + OUTPUT
-- Table: tmi_advisory_sequences (seq_date DATE PK, seq_number INT)

CREATE PROCEDURE dbo.sp_GetNextAdvisoryNumber
    @next_number NVARCHAR(16) OUTPUT
AS
    DECLARE @today DATE = CAST(SYSUTCDATETIME() AS DATE);
    DECLARE @current_seq INT;

    MERGE dbo.tmi_advisory_sequences WITH (HOLDLOCK) AS target
    USING (SELECT @today AS seq_date) AS source
    ON target.seq_date = source.seq_date
    WHEN MATCHED THEN UPDATE SET seq_number = seq_number + 1
    WHEN NOT MATCHED THEN INSERT (seq_date, seq_number) VALUES (@today, 1);

    SELECT @current_seq = seq_number FROM dbo.tmi_advisory_sequences WHERE seq_date = @today;
    SET @next_number = 'ADVZY ' + RIGHT('000' + CAST(@current_seq AS VARCHAR), 3);
    -- Output: "ADVZY 001", "ADVZY 002", etc.
```

The `AdvisoryNumber` PHP class (`api/tmi/AdvisoryNumber.php`) provides `peek()` (read without incrementing) and `reserve()` (read and increment) methods.

### 8.2 Discord Queue Processing

The `process_discord_queue.php` daemon processes pending posts asynchronously:

```pseudo
LOOP continuously:
    1. SELECT batch of 50 pending posts from tmi_discord_posts
       WHERE status = 'PENDING' ORDER BY created_utc
    2. FOR each post:
       a. Determine target channel(s) based on entity_type and org_code
       b. Format message using Discord embed format
       c. POST to Discord API (https://discord.com/api/v10/channels/{id}/messages)
       d. Rate limit: 10 posts/sec (100ms delay between posts; Discord API limit: 50/sec per channel)
       e. On success: UPDATE status = 'SENT', message_id = response.id
       f. On failure: UPDATE status = 'FAILED', retry_count++
       g. On rate limit: Sleep for retry_after seconds
    3. Sleep 100ms between batches
```

### 8.3 Cross-Border Auto-Detection

When `TMI_CROSS_BORDER_AUTO_DETECT` is enabled:

```pseudo
// Determine which organizations should receive a TMI posting
affected_regions = []

FOR each facility mentioned in TMI (ctl_element, scope):
    IF facility starts with 'Z' (US ARTCC): add 'US'
    IF facility starts with 'CZ' (Canadian FIR): add 'CA'
    IF facility in ECFMP list: add 'EU'

IF affected_regions contains multiple regions:
    // Suggest posting to all relevant org Discord servers
    suggested_orgs = DISCORD_ORGANIZATIONS where region IN affected_regions
```

---

## 9. TMI Compliance Analysis

**Python**: `scripts/tmi_compliance/core/analyzer.py`
**JavaScript**: `assets/js/tmi_compliance.js`

### 9.1 Compliance Measurement

TMI compliance measures whether flights departed within their EDCT window:

```pseudo
// EDCT compliance window: -5 minutes to +0 minutes (FAA standard)
// A flight is "compliant" if actual departure is within this window

FOR each controlled flight:
    actual_departure = off_utc OR (out_utc + unimpeded_taxi_time)
    edct = ctd_utc  // Expected Departure Clearance Time

    delta = actual_departure - edct  (in minutes)

    IF delta >= -5 AND delta <= 0:
        compliance_status = 'COMPLIANT'
    ELIF delta < -5:
        compliance_status = 'EARLY'
    ELIF delta > 0:
        compliance_status = 'LATE'

    // Special handling for Ground Stops
    IF gs_held = 1:
        actual_departure = off_utc  // Use actual off time
        IF gs_release_utc IS NOT NULL:
            // Compare against GS release time, not EDCT
            expected = gs_release_utc + unimpeded_taxi_time
            delta = actual_departure - expected
```

### 9.2 GS Time Source Priority

The analyzer uses a priority chain for determining actual departure time:

```pseudo
1. off_utc (actual takeoff - most accurate)
2. out_utc + taxi_reference_time(airport) (gate departure + taxi)
3. first_seen_utc + connect_reference_time(airport) (first VATSIM appearance + connect time)
```

### 9.3 Measurement Points

Each TMI has measurement points (fixes/airports) where compliance is measured:

```pseudo
// measurement_point = display label (e.g., "KJFK Arrivals")
// fix = raw fix name for GIS intersection (e.g., "KJFK" or "CAMRN")

// For airport programs: measurement at destination airport
// For FCA programs: measurement at the fix defining the FCA
// For reroutes: measurement along the protected segment
```

---

## 10. SWIM API & WebSocket System

### 10.1 SWIM Sync Daemon

**Script**: `scripts/swim_sync_daemon.php`
**Interval**: Every 2 minutes

```pseudo
LOOP every 120 seconds:
    1. Connect to VATSIM_ADL and SWIM_API databases
    2. SELECT all active flights from ADL normalized tables
       (JOIN core + plan + position + times + aircraft + tmi)
    3. MERGE into swim_flights (120+ column denormalized table)
       - INSERT new flights
       - UPDATE changed flights (position, times, tmi status)
       - DELETE inactive flights (is_active = 0 for > 30 min)
    4. Every 6 hours: Run data retention cleanup
       - DELETE swim_flights WHERE last_seen_utc < NOW() - 24 hours
       - DELETE swim_audit_log WHERE request_time < NOW() - 30 days
    5. Write heartbeat file for ADL daemon fallback detection
```

### 10.2 SWIM WebSocket Server

**Script**: `scripts/swim_ws_server.php`
**Port**: 8090
**Library**: Ratchet (cboden/ratchet) + ReactPHP event loop

```pseudo
Server Architecture:
    - WebSocketServer class extends Ratchet MessageComponentInterface
    - SubscriptionManager tracks client subscriptions
    - ClientConnection wraps each connected client

Connection Lifecycle:
    1. Client connects via ws://host:8090
    2. Client authenticates: {"type": "auth", "api_key": "..."}
       → Validate against swim_api_keys table
    3. Client subscribes: {"type": "subscribe", "channel": "flights", "filters": {...}}
       → Filters: airport, artcc, callsign, bbox, etc.
    4. Server pushes events: {"type": "flight_update", "data": {...}}
       → After each ADL refresh, changed flights are broadcast to matching subscribers

Event Types:
    - flight_update: Position/time/status changes
    - flight_new: New flight appeared
    - flight_removed: Flight went inactive
    - tmi_update: TMI program changes
```

### 10.3 SWIM API Authentication

```pseudo
// API key validation (api/swim/v1/auth.php)
api_key = REQUEST_HEADER('X-API-Key') OR GET_PARAM('api_key')

SELECT * FROM swim_api_keys
WHERE api_key = @api_key
  AND is_active = 1
  AND (expires_at IS NULL OR expires_at > NOW())

IF valid:
    // Check tier limits
    IF tier = 'standard': rate_limit = 60 req/min
    IF tier = 'premium': rate_limit = 300 req/min
    IF tier = 'internal': rate_limit = unlimited

    // Log to audit
    INSERT INTO swim_audit_log (api_key_id, endpoint, method, ip, response_code, response_time_ms)
```

### 10.4 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| SWIM sync per cycle | 3,000-6,000 flight MERGE | ~$0.20/day on Basic tier |
| WebSocket connections | 0-50 typical | ~5MB RAM per 10 connections |
| API requests per day | 1,000-10,000 | Covered by App Service plan |

---

## 11. Statistics & Analytics Pipeline

**Stored procedures**: `adl/migrations/stats/002_flight_stats_procedures.sql` (base), `003_flight_stats_agent_jobs.sql` (scheduled jobs)
**Tables**: `flight_stats_hourly`, `flight_stats_daily`, `flight_stats_weekly`, etc.

### 11.1 Aggregation Schedule

```pseudo
// sp_ProcessFlightStatsJobs runs from the ADL daemon every 15 minutes
// Checks job schedules and runs due jobs:

Hourly (on the hour):
    - Aggregate flight counts by airport, phase, carrier
    - INSERT INTO flight_stats_hourly

Daily (at 06:00Z):
    - Summarize yesterday's flights
    - INSERT INTO flight_stats_daily, flight_stats_airport, flight_stats_carrier

Weekly (Sunday 06:00Z):
    - INSERT INTO flight_stats_weekly

Monthly (1st of month):
    - INSERT INTO flight_stats_monthly_summary
```

### 11.2 Key Metrics Computed

```sql
-- Per airport, per hour:
total_departures, total_arrivals, total_enroute,
avg_delay_min, max_delay_min,
avg_taxi_out_min, avg_taxi_in_min,
cancellation_rate, diversion_rate

-- Per carrier:
flight_count, on_time_pct, avg_delay_min, route_count

-- Per city pair:
avg_block_time_min, avg_enroute_time_min, avg_distance_nm
```

### 11.3 Compute Cost

| Metric | Value | Cost Impact |
|--------|-------|-------------|
| Hourly aggregation | 24 runs/day, ~500ms each | Minimal |
| Daily aggregation | 1 run/day, ~5-10s | Minimal |
| Stats table growth | ~1000 rows/day | Negligible storage |

---

## 12. Airport Configuration & ATIS Processing

### 12.1 ATIS Parsing

```pseudo
// From scripts/atis_parser.php (called by ADL daemon)

INPUT: ATIS text string from VATSIM controller client
// Example: "KJFK INFO A 1251Z. ILS RWY 4L APCH IN USE. RWYS 4L, 4R, 31R IN USE."

Step 1: Extract information identifier (A, B, C...)
Step 2: Parse runway information using regex patterns:
    - "RWY(S)? (\d+[LRC]?)(,\s*(\d+[LRC]?))*"
    - Classify as arrival, departure, or both
Step 3: Determine weather category (FAA flight rule mapping):
    - VMC  ← VFR:   visibility > 5SM, ceiling > 3000ft
    - LVMC ← MVFR:  visibility 3-5SM or ceiling 1000-3000ft
    - IMC  ← IFR:   visibility 1-3SM or ceiling 500-1000ft
    - LIMC ← LIFR:  visibility < 1SM or ceiling < 500ft
    - VLIMC ← VLIFR: very low IFR (sub-category of LIFR)
    Note: All 5 categories are used in airport_config rates (AAR/ADR per
    weather tier). The ATIS parser currently classifies 4 FAA categories
    (VFR/MVFR/IFR/LIFR); VLIFR sub-classification is pending.
Step 4: Match against airport_config presets:
    - Compare active runways to known configurations
    - Auto-detect configuration name (e.g., "West Plan", "South Flow")
```

### 12.2 Airport Taxi Reference

Daily computed from actual flight data:

```sql
-- Airport taxi reference calculation (daily at 02:15Z)
-- Uses 90-day rolling window, FAA p5-p15 unimpeded percentile method

INSERT INTO airport_taxi_reference (airport, unimpeded_taxi_sec, sample_count)
SELECT
    fp_dept_icao,
    -- p5-p15 percentile: take 5th-15th percentile of taxi times
    -- (removes very fast taxis and normal delays, leaving "unimpeded" baseline)
    PERCENTILE_CONT(0.10) WITHIN GROUP (ORDER BY taxi_out_sec) AS unimpeded_taxi_sec,
    COUNT(*) AS sample_count
FROM (
    SELECT fp_dept_icao,
           DATEDIFF(SECOND, out_utc, off_utc) AS taxi_out_sec
    FROM adl_flight_times t
    JOIN adl_flight_core c ON c.flight_uid = t.flight_uid
    WHERE t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
      AND t.out_utc > DATEADD(DAY, -90, SYSUTCDATETIME())
      AND DATEDIFF(SECOND, t.out_utc, t.off_utc) BETWEEN 60 AND 3600  -- 1-60 min valid range
) sub
GROUP BY fp_dept_icao
HAVING COUNT(*) >= 10  -- Minimum sample size
```

---

## 13. Demand Visualization

**JavaScript**: `assets/js/demand.js`
**API**: `api/adl/demand/fix.php`, `segment.php`, `airway.php`

### 13.1 Fix Demand Calculation

Demand is calculated using table-valued functions (TVFs), not inline SQL. The PHP endpoint calls the TVF:

```php
// api/adl/demand/fix.php
$sql = "SELECT * FROM dbo.fn_FixDemand(?, ?, NULL, ?, ?) ORDER BY eta_at_fix";
```

The TVF `dbo.fn_FixDemand` counts flights passing through a fix in time buckets:

```sql
-- TVF returns: fix_name, flight_uid, callsign, eta_at_fix, altitude, carrier, etc.
-- PHP-side aggregation groups into 15-minute buckets for Chart.js display

-- Related TVFs:
--   dbo.fn_RouteSegmentDemand(@fix1, @fix2, @start, @end) — segment demand
--   dbo.fn_BatchDemandBucketed(@monitors_json, @start, @end, @bucket) — multi-monitor
--   dbo.fn_AirwayDemandBucketed(@airway, @start, @end, @bucket) — airway demand
--   dbo.fn_AirwaySegmentDemandBucketed(@airway, @from, @to, @start, @end, @bucket)
--   dbo.fn_ViaDemandBucketed(@origin, @dest, @via_fix, ...) — via-fix demand
```

### 13.2 Client-Side Chart Rendering

```javascript
// demand.js builds Chart.js stacked bar charts using DemandChartCore
// X-axis: 15-minute time buckets
// Y-axis: flight count (stacked by flight phase)
// Phase colors defined in DemandChartCore.PHASE_COLORS (shared with other charts)
// Charts are created per-monitor with batch loading from fn_BatchDemandBucketed
```

---

## 14. Archival & Data Retention

**Script**: `scripts/archival_daemon.php`
**Schedule**: Every 60 min off-peak (04:00-10:00Z), every 4h otherwise

### 14.1 Trajectory Tiering

```pseudo
// Three-tier trajectory storage:
// 1. adl_flight_trajectory — Live (full resolution, < 1h)
// 2. adl_trajectory_archive — Downsampled (every 60s, 1h-30d)
// 3. Azure Blob Storage — Cold archive (> 30d, daily dump)

ARCHIVAL CYCLE (5 steps):
    Step 1/5: sp_Archive_CompletedFlights
       Archive completed flight records to adl_flight_archive

    Step 2/5: sp_ArchiveTrajectory_TmiAware (@archive_threshold_hours = 1)
       Move trajectories > 1 hour old to archive table
       Extracts high-resolution TMI data to adl_tmi_trajectory BEFORE downsampling
       Downsamples remaining data to 60s intervals in adl_trajectory_archive

    Step 3/5: sp_Downsample_Trajectory_ToCold
       Compress warm-tier data (>7d) to cold tier with further downsampling

    Step 4/5: sp_Purge_OldData
       Purge changelog entries > 7 days
       Purge completed parse queue entries > 1 day
       General data retention cleanup

    Step 5/5: sp_PurgeTmiTrajectory (03:00-06:00 UTC only)
       90-day retention purge for TMI trajectory data
       Skipped outside off-peak window
```

### 14.2 Blob Archive (Daily)

```pseudo
// adl_archive_daemon.php runs daily at configured hour (default 10:00Z)
// Requires ADL_ARCHIVE_STORAGE_CONN environment variable

IF current_hour == archive_hour:
    1. Export trajectories > 30 days to CSV
    2. Compress to gzip
    3. Upload to Azure Blob Storage container
    4. Delete from adl_trajectory_archive WHERE > 30 days
    5. Log archive stats
```

---

## 15. Cost & Compute Implications

### 15.1 Actual vATCSCC Production Resources (as of March 2026)

Queried directly from the live Azure subscription (`59acfcdf-fb3d-4e2b-a058-b189e3f20d7a`, Resource Group `VATSIM_RG`):

| Resource | Actual SKU | Configuration | Est. Monthly Cost |
|----------|-----------|---------------|-------------------|
| **App Service** | P1v2 (PremiumV2) | 1 vCPU, 3.5GB RAM, 1 worker | ~$80 |
| **VATSIM_ADL** | Hyperscale Serverless Gen5 | Min 3 / Max 16 vCores, auto-pause disabled | ~$1,000-2,500 (usage-based) |
| **SWIM_API** | Basic (5 DTU) | 2GB max size | ~$4.90 |
| **VATSIM_TMI** | Basic (5 DTU) | 2GB max size | ~$4.90 |
| **VATSIM_REF** | Basic (5 DTU) | 2GB max size | ~$4.90 |
| **VATSIM_STATS** | GP Serverless Gen5 | Min 0.5 / Max 1 vCore, auto-pauses after 60min | ~$5-150 (usage-based) |
| **MySQL perti_site** | Standard_D2ds_v4 (GP) | 2 vCores, 20GB storage, 360 IOPS | ~$122 |
| **PostgreSQL VATSIM_GIS** | Standard_B2s (Burstable) | 2 vCores, 32GB storage | ~$25 |
| **Storage accounts** | LRS/RAGRS/ZRS (6 accounts) | pertiadlarchive, pertisyndatalake, vatcsccadlraw, vatsimadlarchive, vatsimdatastorage, vatsimswimdata | ~$5-15 |
| **Data Factory** | VATSIM-ADL-HISTORY | For historical data pipelines | ~$0-10 |
| **Logic App** | STATS-LOADER-SCHEDULER | Scheduler (consumption plan) | ~$0-1 |
| **Synapse Analytics** | perti-synapse (serverless SQL) | On-demand querying of archived data | ~$0-5 (per-query) |
| **Total (production)** | | | **~$1,250-2,900/mo** |

> **Note**: The Hyperscale Serverless ADL tier dominates costs. vATCSCC uses this tier because the 15-second ingest cycle demands consistent low-latency queries across 3,000-6,000 flights with 8 normalized tables, trajectory writes, and boundary detection. Organizations with lower traffic can use significantly cheaper tiers (see Section 15.3).

### 15.2 Per-Subsystem Cost Breakdown

#### 15.2.1 ADL Ingest Pipeline (largest cost driver)

| Component | Operation | Per-Cycle Cost | Cycles/Day | Daily Cost | Monthly Cost |
|-----------|-----------|---------------|-----------|------------|-------------|
| **VATSIM fetch** | cURL 2-4MB JSON | ~$0 (App Service fixed) | 5,760 | $0 | $0 |
| **PHP parsing** | JSON → staging rows | ~$0 (App Service fixed) | 5,760 | $0 | $0 |
| **Staging INSERT** | Bulk literal 3,000 rows | 0.3-0.5 vCore-sec | 5,760 | ~0.5 vCore-hr | ~$0.35 |
| **SP Step 1 (Parse)** | Read staging tables | 0.5-1.0 vCore-sec | 5,760 | ~1.0 vCore-hr | ~$0.70 |
| **SP Step 1b (Enrich)** | Airline lookup, flight_key | 0.5-1.5 vCore-sec | 5,760 | ~1.2 vCore-hr | ~$0.85 |
| **SP Step 2 (Core MERGE)** | MERGE 8,000 rows into adl_flight_core | 1.0-3.0 vCore-sec | 5,760 | ~2.5 vCore-hr | ~$1.75 |
| **SP Step 3 (Plan MERGE)** | Flight plan data | 0.5-2.0 vCore-sec | 5,760 | ~1.5 vCore-hr | ~$1.05 |
| **SP Step 4 (Position)** | Lat/lon, altitude, speed | 0.5-1.5 vCore-sec | 5,760 | ~1.0 vCore-hr | ~$0.70 |
| **SP Step 5 (Times)** | 50+ time columns | 0.5-2.0 vCore-sec | 5,760 | ~1.5 vCore-hr | ~$1.05 |
| **SP Step 6 (Aircraft)** | BADA performance lookup | 0.2-0.5 vCore-sec | 5,760 | ~0.5 vCore-hr | ~$0.35 |
| **SP Step 7 (Route Hash)** | Delta detection for route changes | 0.1-0.3 vCore-sec | 5,760 | ~0.3 vCore-hr | ~$0.21 |
| **SP Step 8 (Route Dist)** | Two-pass LINESTRING distance calc | 0.5-2.0 vCore-sec | 5,760 | ~1.5 vCore-hr | ~$1.05 |
| **SP Steps 9-13** | Cleanup, stats, snapshots | 0.5-1.0 vCore-sec | 5,760 | ~1.0 vCore-hr | ~$0.70 |
| **Deferred ETA** | Time-budgeted ETA processing | 0-5.0 vCore-sec | ~2,000 | ~1.5 vCore-hr | ~$1.05 |
| **ATIS (tiered)** | Parse ATIS broadcasts | 0.1-0.5 vCore-sec | ~3,000 | ~0.3 vCore-hr | ~$0.21 |
| **ADL subtotal** | | | | **~13 vCore-hrs** | **~$10/mo** |

> The **per-operation** Azure SQL compute cost is tiny. The real cost is the **base compute reservation**: a serverless Hyperscale DB with min 3 vCores running 24/7 costs ~$1,000/mo regardless of actual query load. DTU-based tiers (S0-S3) cost less per month but impose hard compute caps that cause throttling during the sustained 15-second ingest cycle with concurrent daemon queries. vATCSCC found that DTU throttling caused missed VATSIM feeds and cascading delays — the serverless premium buys reliability.

#### 15.2.2 Route Parsing (PostGIS)

| Component | Per-Operation | Operations/Day | Daily Cost | Monthly Cost |
|-----------|-------------|----------------|------------|-------------|
| PostGIS `expand_route_with_artccs()` | 20-200ms, ~0.01 vCore-sec | ~5,000 routes | ~0.015 vCore-hr | ~$0.01 |
| ADL SP fallback (`sp_ParseRoute`) | 50-500ms, ~0.05 vCore-sec | ~500 fallbacks | ~0.007 vCore-hr | ~$0.01 |
| Waypoint INSERT to ADL | 5-10ms per flight (20 waypoints) | ~5,000 | ~0.01 vCore-hr | ~$0.01 |
| **Parsing subtotal** | | | | **~$0.03/mo** |

#### 15.2.3 Boundary Detection (PostGIS)

| Component | Per-Operation | Operations/Day | Daily Cost | Monthly Cost |
|-----------|-------------|----------------|------------|-------------|
| Point-in-polygon (tiered) | 5-20ms each | ~15,000 flights | ~0.05 vCore-hr | ~$0.04 |
| Grid lookup optimization | 1-2ms (cache hit) | ~100,000 checks | ~0.03 vCore-hr | ~$0.02 |
| **Boundary subtotal** | | | | **~$0.06/mo** |

#### 15.2.4 Crossing Prediction (PostGIS)

| Component | Per-Operation | Operations/Day | Daily Cost | Monthly Cost |
|-----------|-------------|----------------|------------|-------------|
| Line-polygon intersection | 5-20ms per flight | ~8,000 | ~0.03 vCore-hr | ~$0.02 |
| ETA calculation at intersection | <1ms | ~20,000 crossings | negligible | ~$0 |
| **Crossing subtotal** | | | | **~$0.02/mo** |

#### 15.2.5 GDP/GS Operations (VATSIM_TMI)

| Component | Per-Operation | Frequency | Cost per Event | Monthly Cost |
|-----------|-------------|-----------|---------------|-------------|
| Slot generation | 50-200ms for 120 slots | 1-5 GDPs/week | ~$0.001 | ~$0.02 |
| RBS assignment | 100-500ms for 500 flights | 1-5 GDPs/week | ~$0.001 | ~$0.02 |
| GS flight evaluation | 50-200ms for 200 flights | 0-3 GS/week | ~$0.001 | ~$0.01 |
| Advisory number generation | <10ms | 10-50/week | negligible | ~$0 |
| Program metrics update | 20-50ms | per GDP/GS action | negligible | ~$0 |
| **TMI subtotal** | | | | **~$0.05/mo** |

> TMI operations are event-driven and infrequent. The Basic tier (5 DTU, ~$4.90/mo) is more than sufficient; you're paying for availability, not compute.

#### 15.2.6 SWIM API Sync & WebSocket

| Component | Per-Operation | Frequency | Daily Cost | Monthly Cost |
|-----------|-------------|-----------|------------|-------------|
| SWIM sync (denormalize flights) | 2-5s for 6,000 flights | Every 2 min | ~0.2 vCore-hr | ~$0.14 |
| WebSocket broadcast | <1ms per client | Every 15s | negligible | ~$0 |
| API key auth check | <1ms | Per API request | negligible | ~$0 |
| **SWIM subtotal** | | | | **~$4/mo** (base tier cost) |

#### 15.2.7 Statistics Aggregation

| Component | Per-Operation | Frequency | Monthly Cost |
|-----------|-------------|-----------|-------------|
| Hourly rollup | 500ms-2s | 24x/day | ~$0.05 |
| Daily rollup | 5-10s | 1x/day | ~$0.01 |
| Weekly/monthly rollup | 10-30s | 4-1x/month | ~$0.001 |
| **Stats subtotal** | | | **~$1.50/mo** (compute) + $0-150 (serverless base) |

### 15.3 Recommended Tiers by Deployment Scale

#### Minimum Viable (Development / Low Traffic)

Target: 0-1,000 concurrent flights, single developer.

| Resource | Recommended Tier | Monthly Cost | Limitations |
|----------|-----------------|-------------|-------------|
| App Service | B1 (1 vCPU, 1.75GB) | ~$13 | 15-25 PHP-FPM workers; daemons may compete for CPU |
| VATSIM_ADL | Free tier (auto-pause) | ~$0 | 32GB max, auto-pauses after 60min idle, 30-60s resume time. 15s ingest cycle will keep it awake during activity |
| VATSIM_TMI | Free tier (auto-pause) | ~$0 | GDP/GS operations will experience cold-start delays |
| VATSIM_REF | Free tier (auto-pause) | ~$0 | Reference lookups slow after idle periods |
| SWIM_API | Free tier (auto-pause) | ~$0 | API consumers experience cold starts |
| VATSIM_STATS | Free tier (auto-pause) | ~$0 | Stats dashboard loads slowly |
| MySQL perti_site | B1ms (1 vCore) | ~$12 | Adequate for website traffic |
| PostgreSQL VATSIM_GIS | B1ms (1 vCore) | ~$12 | Route parsing will be slower |
| **Total** | | **~$37/mo** | Functional for development and light operations |

#### Standard (Production / Moderate Traffic)

Target: 1,000-3,000 concurrent flights, weekly event operations.

| Resource | Recommended Tier | Monthly Cost | Headroom |
|----------|-----------------|-------------|----------|
| App Service | P1v2 (1 vCPU, 3.5GB) | ~$80 | 40 PHP-FPM workers, comfortable for 15 daemons |
| VATSIM_ADL | GP Serverless Gen5 (min 0.5, max 4 vCores) | ~$150-400 | Auto-scales with event traffic; auto-pauses during quiet periods to reduce cost |
| VATSIM_TMI | Basic (5 DTU) | ~$5 | More than enough for event-driven TMI operations |
| VATSIM_REF | Basic (5 DTU) | ~$5 | Mostly read-only reference data |
| SWIM_API | Basic (5 DTU) | ~$5 | Sufficient for <100 API consumers |
| VATSIM_STATS | Free or Basic | ~$0-5 | Free auto-pauses; Basic for always-on dashboard |
| MySQL perti_site | B1ms (1 vCore) | ~$12 | Website traffic is low |
| PostgreSQL VATSIM_GIS | B2s (2 vCores) | ~$25 | Route parsing + boundary detection headroom |
| **Total** | | **~$282-537/mo** | Reliable for weekly events up to 3,000 flights |

> **Why not DTU tiers for ADL?** The 15-second ingest cycle with 8-table MERGEs, concurrent parse/boundary/crossing daemons, and trajectory writes creates sustained compute demand that regularly exceeds DTU caps. vATCSCC experienced missed VATSIM feeds and cascading daemon delays on S2 (50 DTU) at ~3,000 concurrent flights. Serverless vCore billing is more expensive per-hour but avoids throttling entirely — and auto-pause during quiet periods can offset costs for organizations that don't run 24/7.

#### High Scale (High-Traffic Production)

Target: 3,000-15,000 concurrent flights, continuous 24/7 monitoring.

| Resource | Recommended Tier | Monthly Cost | Headroom |
|----------|-----------------|-------------|----------|
| App Service | P2v2 (2 vCPU, 7GB) | ~$160 | More CPU for concurrent daemons + web traffic |
| VATSIM_ADL | Hyperscale Serverless Gen5 (min 3, max 16) | ~$1,000-2,500 | Auto-scales with load; handles extreme spikes without throttling |
| VATSIM_TMI | S0 (10 DTU) | ~$15 | Faster GDP/GS during high-traffic events |
| VATSIM_REF | Basic (5 DTU) | ~$5 | Still mostly read-only |
| SWIM_API | S0 (10 DTU) | ~$15 | Handles 100+ concurrent API consumers |
| VATSIM_STATS | GP Serverless (0.5-1 vCore) | ~$5-150 | Complex analytics queries |
| MySQL perti_site | D2ds_v4 (2 vCores) | ~$122 | Overkill for website; could use B2s (~$25) |
| PostgreSQL VATSIM_GIS | B2s (2 vCores, 32GB) | ~$25 | Adequate for spatial queries |
| Storage (3 accounts) | LRS | ~$10-15 | Trajectory archive, data lake, blob storage |
| **Total** | | **~$1,360-3,010/mo** | This is what vATCSCC runs for 3,000-6,000 daily flights |

> **Why Hyperscale Serverless?** At 3,000+ concurrent flights with 24/7 operations, the compute demand is continuous — there are no idle periods for auto-pause savings. Hyperscale Serverless with min 3 vCores guarantees baseline capacity while allowing burst to 16 vCores during events. The min-vCore floor costs ~$1,000/mo but eliminates the throttling and missed feeds that plagued DTU-based tiers.

### 15.4 Data Volume Projections (Real Observations)

Based on actual VATSIM network traffic patterns:

| Metric | Minimum (off-peak) | Average | Maximum (major event) |
|--------|-------------------|---------|----------------------|
| Concurrent flights | 300-500 (0200-0800Z) | 2,500-3,500 | 6,000-8,000 (Cross the Pond, WorldFlight) |
| VATSIM JSON size | 0.8MB (gzipped) | 2.5MB | 5.2MB |
| New flights/day | 4,000 (weekday) | 8,000 | 15,000 (event day) |
| Trajectory records/day | 5M (low) | 17.3M | 35M (high) |
| Waypoints parsed/day | 2,000 | 5,000 | 12,000 |
| ATIS broadcasts/cycle | 50 | 200 | 500 |

| Data Type | Daily Volume | Monthly Volume | Retention | Storage Impact |
|-----------|-------------|---------------|-----------|---------------|
| Trajectory records | 5-35M rows (0.6-4GB) | 150-1,050M rows (18-120GB) | 24h live, 30d archive, blob cold | ADL primary cost driver |
| Flight records (core) | 4,000-15,000 new/day | 120K-450K/month | Permanent (7d active → archive) | ~50MB/mo |
| Waypoint records | 40K-300K/day | 1.2-9M/month | Overwritten per flight | ~200MB/mo |
| Changelog records | 500K-5M/day | 15-150M/month | 7-day retention | Self-cleaning |
| Parse queue | 2,000-12,000/day | 60-360K/month | 1-day retention | Self-cleaning |
| Stats aggregates | 500-2,000 rows/day | 15-60K/month | Permanent | ~5MB/mo |
| TMI control records | 0-5,000/day (event only) | 0-30K/month | Per-program lifetime | ~1MB/mo |

**Cumulative data totals** (as of March 2026):

| Table | Total Rows | Notes |
|-------|-----------|-------|
| `adl_flight_core` (total flights) | 1,625,115 | All-time tracked flights |
| `adl_flight_waypoints` (total waypoints) | 9,295,153 | Parsed route waypoints |
| `adl_flight_planned_crossings` (total crossings) | 20,548,518 | Boundary crossing predictions |
| `nav_fixes` (reference fixes) | 268,998 | Global navigation fix database |

### 15.5 Scaling Decision Matrix

| Concurrent Flights | ADL Tier | App Service | PostGIS | Total Monthly | Notes |
|-------------------|----------|------------|---------|---------------|-------|
| **< 500** | Free (auto-pause) | B1 ($13) | B1ms ($12) | **~$37** | Auto-pause delays; fine for dev |
| **500-1,500** | GP Serverless (0.5-2 vCore, $50-200) | B1 ($13) | B1ms ($12) | **~$90-240** | Auto-pauses when idle; scales for events |
| **1,500-3,000** | GP Serverless (0.5-4 vCore, $150-400) | P1v2 ($80) | B1ms ($12) | **~$260-510** | Reliable for weekly events |
| **3,000-6,000** | HS Serverless (min 2, max 8 vCore, $600-1,200) | P1v2 ($80) | B2s ($25) | **~$730-1,330** | Comfortable production tier |
| **6,000-10,000** | HS Serverless (min 3, max 16 vCore, $1,000-2,500) | P2v2 ($160) | B2s ($25) | **~$1,210-2,710** | Large event capacity (vATCSCC tier) |
| **10,000-15,000** | HS Serverless (min 4, max 24 vCore) | P2v2 ($160) | B4ms ($50) | **~$1,800-3,500+** | Sustained high load |
| **15,000+** | HS Serverless + read replica | P3v2 ($320) | GP ($200) | **~$3,000+** | Would require architecture changes |

> All rows include Basic (5 DTU, ~$5 each) for TMI, REF, SWIM_API, and B1ms ($12) for MySQL. Storage accounts add ~$5-15/mo.
>
> **Why no DTU tiers (S0-S3)?** DTU tiers impose hard compute caps. PERTI's 15-second ingest cycle with 8-table MERGEs, plus concurrent daemon queries, creates sustained compute demand that exceeds DTU limits unpredictably. On S2 (50 DTU), vATCSCC experienced missed VATSIM feeds at ~3,000 flights and cascading delays in boundary detection and crossing prediction daemons. vCore-based serverless billing costs more per compute-hour but eliminates throttling — the most critical requirement for a real-time flight tracking system. For organizations operating only during scheduled events (not 24/7), serverless auto-pause recovers significant cost during idle periods.

### 15.6 Cost Optimization Techniques Already Implemented

1. **V9.0 Staged Refresh** (current: V9.4.0 with Route Distance V2.2): PHP-side JSON parsing shifted ~50% of SP compute to fixed-cost App Service PHP, reducing DTU pressure on Azure SQL. Saved ~5 DTU continuous load on ADL.

2. **Delta Detection Bitmask**: Skips unchanged data in SP steps 1b/2/3/4/6, reducing compute by ~30-40%. This translates to ~1-2 fewer vCores of sustained demand, saving ~$100-200/mo on serverless billing.

3. **Geography Pre-computation**: Eliminated ~8,500 `Point()` CLR calls per cycle (~12% faster). CLR geography functions are the most expensive SQL Server operations per call.

4. **Covering Index** (`IX_waypoints_route_calc`): Eliminated 315K key lookups per cycle, Step B from 1643ms to 381ms. Reduces per-cycle vCore-seconds by ~15%, compounding across 5,760 daily cycles.

5. **PostGIS Offload**: Route parsing and boundary detection moved from Azure SQL to cheaper PostgreSQL B2s ($25/mo vs $75+/mo for equivalent SQL capacity). Saves ~25% of ADL DTU usage.

6. **Lazy Database Loading** (`PERTI_MYSQL_ONLY`): Pages that only need MySQL skip 5 Azure SQL connections (~500-1000ms saved per request, ~98 API endpoints optimized).

7. **ATIS Tiering**: Instead of processing all airports every 15s, uses 5-tier system (15s for ASPM82 airports, 60min for inactive) reducing ATIS processing by ~90%.

8. **Deferred Processing**: ETA and snapshot steps are deferred when cycle time budget is exceeded, preventing missed VATSIM feeds. Essential on any tier — keeps the 15-second cycle tight even during peak events.

9. **Trajectory Archival**: Three-tier storage (live → 60s downsampled → blob cold) keeps ADL table size manageable. Without archival, trajectory table would grow ~120GB/month.

10. **Serverless Auto-Pause**: VATSIM_STATS auto-pauses after 60min idle (saves ~$150/mo vs always-on GP), and Free-tier databases for development save ~$20/mo.

### 15.7 Hypothetical Cost at Various Scales

For organizations considering PERTI for networks of different sizes:

| Scenario | Flight Volume | Recommended Infra | Monthly Cost | Annual Cost |
|----------|-------------|-------------------|-------------|-------------|
| **Small vACC** (1 country, low traffic) | 100-500 flights/day | Free ADL + B1 App + B1ms DBs | $37-90 | $444-1,080 |
| **Medium vACC** (regional, weekly events) | 500-3,000 flights/day | GP Serverless ADL (0.5-4 vCore) + P1v2 App | $260-510 | $3,120-6,120 |
| **Large vACC** (continental, daily ops) | 3,000-8,000 flights/day | HS Serverless ADL (min 2-3) + P1v2/P2v2 App + B2s GIS | $730-2,710 | $8,760-32,520 |
| **Network-wide** (global, like vATCSCC) | 8,000-15,000 flights/day | HS Serverless ADL (min 3, max 16+) + P2v2 | $1,210-3,500 | $14,520-42,000 |

> **Cost sensitivity**: ~75-85% of the total cost at scale is Azure SQL compute for VATSIM_ADL. Optimizing the stored procedure (Sections 1.5-1.8) has the highest ROI.
