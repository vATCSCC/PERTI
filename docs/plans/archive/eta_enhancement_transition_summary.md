# ETA Enhancement Transition Summary
## Session: January 7, 2026

---

## Overview

This session completed three major ETA/trajectory improvements:
1. **SimBrief Parsing Integration** - Extract step climb data for better speed estimates
2. **Sector/Waypoint ETA Calculation** - ETA at each waypoint for sector entry prediction
3. **Route Distance Integration** - Use parsed route distance instead of GCD

---

## Completed Improvements

### #7 SimBrief Parsing (V8.5)

**Purpose:** Extract detailed flight plan data from SimBrief-generated ICAO remarks

**Results:**
- 37% of flights detected as SimBrief
- Step climb extraction working (1-10 climbs per flight)
- Cruise speed (TAS/Mach) integrated into ETA calculations

**Objects Created:**
| Object | Type | Purpose |
|--------|------|---------|
| `sp_ParseSimBriefData` | Procedure | Parse single flight's SimBrief data |
| `sp_ParseSimBriefDataBatch` | Procedure | Batch parse up to 50 flights/cycle |
| `fn_ExtractCostIndex` | Function | Extract cost index from remarks |
| `adl_flight_stepclimbs` | Table | Store step climb waypoints |
| `adl_flight_plan.is_simbrief` | Column | SimBrief detection flag |
| `adl_flight_plan.initial_alt_ft` | Column | Initial cruise altitude |
| `adl_flight_plan.final_alt_ft` | Column | Final cruise altitude |
| `adl_flight_plan.stepclimb_count` | Column | Number of step climbs |

**Files:**
- `adl/procedures/sp_ParseSimBriefData.sql` (v1.1)
- `adl/migrations/navdata/006_simbrief_refresh_integration.sql`

---

### #5 Sector/Waypoint ETA (V8.6)

**Purpose:** Calculate ETA at each waypoint for sector entry time prediction

**Results:**
- 7,500+ waypoints with calculated ETAs
- Sector entry predictions working via boundary intersection
- Demand forecasting views created

**Objects Created:**
| Object | Type | Purpose |
|--------|------|---------|
| `sp_CalculateWaypointETABatch` | Procedure | Calculate ETA at each waypoint |
| `fn_GetFlightSectorEntries` | Function | Get sector entries for a flight |
| `vw_flight_sector_entries` | View | All predicted sector entries |
| `vw_sector_demand_15min` | View | Sector demand by 15-min buckets |
| `sp_PostRefreshWaypointETA` | Procedure | Wrapper for post-refresh execution |

**Files:**
- `adl/procedures/sp_CalculateWaypointETA.sql`
- `adl/migrations/navdata/007_waypoint_eta_integration.sql`

**Integration:** Added Step 8c to `sp_Adl_RefreshFromVatsim_Normalized` (V8.6)

---

### #1 Route Distance Integration (V3)

**Purpose:** Use parsed route distance instead of great circle distance for more accurate ETAs

**Results:**
- 61% of flights use parsed route distance (100% of parsed flights)
- Remaining 39% are pending in parse queue (tiered priority system)
- Routes typically 5-15% longer than GCD

**Objects Created:**
| Object | Type | Purpose |
|--------|------|---------|
| `adl_flight_plan.route_dist_nm` | Column | Parsed route total distance |
| `sp_SyncRouteDistances` | Procedure | Sync route dist from waypoints |
| `fn_GetRouteDistance` | Function | Get best distance estimate |
| `vw_route_distance_analysis` | View | Route distance analysis |

**Files:**
- `adl/migrations/navdata/008_route_distance_integration.sql`
- `adl/procedures/sp_CalculateETABatch.sql` (V3)

---

## Current ETA Method Distribution

```sql
SELECT eta_method, COUNT(*) AS flights
FROM dbo.adl_flight_times ft
JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
WHERE c.is_active = 1
GROUP BY eta_method;
```

| Method | Flights | Description |
|--------|---------|-------------|
| V3_ROUTE_SB | 344 (53%) | Parsed route + SimBrief speeds |
| V3_ROUTE | 50 (8%) | Parsed route distance |
| V3_SB | 35 (5%) | SimBrief speeds (GCD distance) |
| V3 | 214 (33%) | Basic (GCD only) |

---

## Speed Source Distribution

```sql
SELECT speed_source, COUNT(*) AS flights, AVG(cruise_speed) AS avg_speed
FROM #eta_work  -- (run with @debug=1)
GROUP BY speed_source;
```

| Source | Flights | Avg Speed |
|--------|---------|-----------|
| SIMBRIEF_TAS | 234 | 467 kts |
| SIMBRIEF_MACH | 3 | 490 kts |
| AIRCRAFT_PERF | 372 | 438 kts |
| DEFAULT | 34 | 450 kts |

---

## Refresh Procedure Stats (V8.6)

The refresh procedure now returns these stats:
```
pilots_received, new_flights, updated_flights, routes_queued,
etds_calculated, simbrief_parsed, etas_calculated, waypoint_etas,
trajectories_logged, zone_transitions, boundary_transitions, elapsed_ms
```

---

## Files Modified This Session

### Procedures
| File | Version | Changes |
|------|---------|---------|
| `sp_Adl_RefreshFromVatsim_Normalized.sql` | V8.6 | Added Step 8c (waypoint ETAs) |
| `sp_CalculateETABatch.sql` | V3 | Route distance integration |
| `sp_ParseSimBriefData.sql` | v1.1 | Cost index extraction |
| `sp_CalculateWaypointETA.sql` | V1 | NEW - Waypoint ETA calculation |

### Migrations
| File | Purpose |
|------|---------|
| `006_simbrief_refresh_integration.sql` | SimBrief batch parsing |
| `007_waypoint_eta_integration.sql` | Waypoint ETA views |
| `008_route_distance_integration.sql` | Route distance column + sync |

---

## Deployment Commands

```sql
-- Deploy all changes (in order)
:r "adl\procedures\sp_ParseSimBriefData.sql"
:r "adl\migrations\navdata\006_simbrief_refresh_integration.sql"
:r "adl\procedures\sp_CalculateWaypointETA.sql"
:r "adl\migrations\navdata\007_waypoint_eta_integration.sql"
:r "adl\migrations\navdata\008_route_distance_integration.sql"
:r "adl\procedures\sp_CalculateETABatch.sql"
:r "adl\procedures\sp_Adl_RefreshFromVatsim_Normalized.sql"
```

---

## Verification Queries

### Check ETA Methods
```sql
SELECT eta_method, COUNT(*) AS flights, AVG(eta_confidence) AS avg_conf
FROM dbo.adl_flight_times ft
JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
WHERE c.is_active = 1
GROUP BY eta_method ORDER BY flights DESC;
```

### Check SimBrief Detection
```sql
SELECT 
    SUM(CASE WHEN is_simbrief = 1 THEN 1 ELSE 0 END) AS simbrief,
    SUM(CASE WHEN is_simbrief = 0 THEN 1 ELSE 0 END) AS non_simbrief,
    SUM(CASE WHEN is_simbrief IS NULL THEN 1 ELSE 0 END) AS not_parsed
FROM dbo.adl_flight_plan fp
JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
WHERE c.is_active = 1;
```

### Check Waypoint ETAs
```sql
SELECT 
    COUNT(*) AS total_waypoints,
    SUM(CASE WHEN eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_eta
FROM dbo.adl_flight_waypoints w
JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
WHERE c.is_active = 1;
```

### Check Route Distance Coverage
```sql
SELECT 
    fp.parse_status,
    COUNT(*) AS flights,
    SUM(CASE WHEN fp.route_dist_nm IS NOT NULL THEN 1 ELSE 0 END) AS with_route_dist
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.is_active = 1
GROUP BY fp.parse_status;
```

### Sample Sector Entry Predictions
```sql
SELECT TOP 20 *
FROM dbo.vw_flight_sector_entries
WHERE minutes_to_entry BETWEEN 0 AND 120
  AND boundary_code LIKE 'K%'
ORDER BY entry_eta;
```

---

## Next Task: Wind Data Integration (#2)

### Goal
Integrate real wind data to improve ETA accuracy by accounting for headwinds/tailwinds.

### Current State
- ETA calculation estimates wind from groundspeed vs TAS difference
- No actual wind data integration
- `eta_wind_component_kts` column exists but uses estimated value

### Potential Approaches
1. **NOAA GFS/RAP data** - Free, global coverage, 3-hour updates
2. **Aviation Weather Center** - SIGMET/AIRMET/winds aloft
3. **Pilot-reported winds** - Derive from GS vs filed TAS across flights

### Design Considerations
- Wind varies by altitude and location
- Need interpolation for flight path
- Balance accuracy vs complexity
- Consider caching/refresh frequency

### Related Tables
- `adl_flight_position.groundspeed_kts` - Current groundspeed
- `adl_flight_plan.fp_tas_kts` - Filed TAS
- `adl_flight_times.eta_wind_component_kts` - Estimated wind effect

### Suggested Starting Point
```sql
-- Analyze current wind estimation accuracy
SELECT 
    c.callsign,
    p.groundspeed_kts,
    fp.fp_tas_kts,
    p.groundspeed_kts - fp.fp_tas_kts AS implied_wind,
    ft.eta_wind_component_kts AS estimated_wind,
    p.altitude_ft,
    p.heading_deg
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1 
  AND c.phase = 'enroute'
  AND fp.fp_tas_kts > 0
ORDER BY ABS(p.groundspeed_kts - fp.fp_tas_kts) DESC;
```

---

## Session Statistics

- **Duration:** ~2 hours
- **Improvements completed:** 3 of 7
- **New procedures:** 4
- **New views:** 4
- **Tables modified:** 2 (adl_flight_plan, adl_flight_waypoints)

---

## Remaining Improvements

| # | Feature | Priority | Notes |
|---|---------|----------|-------|
| **2** | **Wind Data** | **Low** | **Next task** |
| 3 | Aircraft Performance | Medium | Better defaults by type |

