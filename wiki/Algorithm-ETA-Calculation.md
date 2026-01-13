# ETA Calculation Algorithm

The ETA (Estimated Time of Arrival) calculation engine provides accurate arrival time predictions for all active flights. The system uses aircraft performance data, route geometry, wind estimation, and TMI delay factors to compute ETAs every refresh cycle.

---

## For Traffic Managers

### What ETA Tells You

The ETA displayed in PERTI represents the **runway threshold crossing time** (wheels-on time), not gate arrival. Key indicators:

| Prefix | Meaning | Confidence |
|--------|---------|------------|
| **E** | Estimated | Standard calculation |
| **P** | Proposed | Pre-departure, using filed times |
| **C** | Controlled | Flight has EDCT/CTA assigned |
| **A** | Actual | Flight has arrived |

### Confidence Levels

ETAs include a confidence score (0.0 - 1.0) based on flight phase:

| Phase | Confidence | Why |
|-------|------------|-----|
| Final approach (<50nm, descending) | 0.95-0.97 | Position and speed well known |
| Descent phase | 0.92-0.94 | Route mostly flown |
| Enroute with SimBrief data | 0.92 | Detailed performance data available |
| Enroute (standard) | 0.88-0.90 | Default aircraft performance |
| Climbing | 0.82-0.85 | Still establishing profile |
| Taxiing | 0.75-0.78 | Departure time uncertain |
| Pre-filed | 0.65-0.70 | Based entirely on filed data |

> **Note:** Higher confidence values in each range apply when using parsed route distance (more accurate than GCD).

### How ETAs Are Used

- **GDP Planning**: ETAs populate demand charts to identify arrival congestion
- **TMI Scoping**: Determine which flights are affected by time-based tiers
- **Compliance Monitoring**: Compare actual arrivals against projected

### When ETAs Change

ETAs recalculate every ~15 seconds. Significant changes occur when:
- Flight departs (taxiing → airborne transition)
- Route amendments are detected
- Aircraft accelerates/decelerates significantly
- Altitude changes indicate climb/descent phase transition

---

## For Technical Operations

### Monitoring ETA Health

#### Key Metrics to Watch

```sql
-- ETA calculation statistics from last refresh
SELECT 
    eta_method,
    COUNT(*) AS flights,
    AVG(eta_confidence) AS avg_confidence
FROM dbo.adl_flight_times
WHERE eta_last_calc_utc > DATEADD(MINUTE, -5, GETUTCDATE())
GROUP BY eta_method;
```

**Expected `eta_method` Distribution (V3.2):**
| Method | Description | Expected % |
|--------|-------------|------------|
| V3_ROUTE_SB | SimBrief + parsed route | 10-20% |
| V3_ROUTE | Parsed route distance | 30-50% |
| V3_SB | SimBrief cruise data | 5-10% |
| V3 | Standard calculation | 30-50% |

> **V3.2 Note:** Prefiles now receive ETA calculations using GCD/route distance even without position data.

#### Common Issues

| Symptom | Likely Cause | Resolution |
|---------|--------------|------------|
| All ETAs NULL | Procedure not running | Check daemon status |
| Low confidence scores | Many pre-files | Normal during low traffic |
| ETAs far in future | GCD used instead of route | Route parsing backlog |
| ETA method = V3 (all) | SimBrief parsing disabled | Check sp_ParseSimBriefDataBatch |

#### Performance Troubleshooting

Target execution time: **< 500ms** for full batch

```sql
-- Check ETA step timing in refresh stats
SELECT 
    step8_trajectory_ms,
    etas_calculated
FROM (
    SELECT TOP 10 *
    FROM dbo.adl_run_log
    ORDER BY run_utc DESC
) recent;
```

If > 1000ms, check:
1. Index health on `adl_flight_times`
2. Aircraft performance profile coverage
3. Temporary table cleanup

### Key Tables

| Table | Purpose |
|-------|---------|
| `adl_flight_times` | Stores calculated ETA and related times |
| `aircraft_performance_profiles` | Climb/cruise/descent speeds by aircraft |
| `adl_flight_stepclimbs` | SimBrief step climb data |

---

## For Developers

### Algorithm Overview

ETA calculation uses a multi-phase approach:

```
Phase 1: Build work table with flight data
Phase 2: Load SimBrief step climb speeds (if available)
Phase 3: Lookup aircraft performance profiles
Phase 4: Calculate time-to-destination by flight phase
Phase 5: Apply TMI delays (EDCT/CTA)
Phase 6: Update adl_flight_times
```

### Core Calculation Logic

#### Distance Sources (V3.2 Priority Order)

1. **Route Distance to Destination** (`route_dist_to_dest_nm`) - Position-based remaining route distance
2. **Parsed Route Distance** (`route_dist_nm`) - Total route from waypoints
3. **Great Circle Distance** (`gcd_nm`) - Fallback, straight-line origin to destination
4. **Current Position to Destination** (`dist_to_dest_nm`) - GCD from current position

> **V3.2 Prefile Support:** For prefiles without position data, the system uses `route_total_nm` or `gcd_nm` to estimate flight distance.

#### Speed Sources (Priority Order)

1. **SimBrief TAS** - From step climb data (`simbrief_cruise_kts`)
2. **SimBrief Mach** - Converted to TAS at altitude
3. **Aircraft Performance Profile** - From `fn_GetAircraftPerformance`
4. **Hardcoded Default** - 450 KTAS for jets

#### Time Calculation by Phase

```sql
-- Simplified phase-based calculation
CASE
    WHEN phase = 'arrived' THEN 0
    
    -- Final approach: use current groundspeed
    WHEN phase = 'descending' AND dist_to_dest < 50 THEN
        dist_to_dest / groundspeed * 60
    
    -- Descent: use descent performance
    WHEN phase = 'descending' THEN
        dist_to_dest / descent_speed * 60
    
    -- Cruise: cruise segment + descent segment
    WHEN phase IN ('enroute', 'cruise') THEN
        (dist_to_dest - tod_dist) / cruise_speed * 60
        + tod_dist / descent_speed * 60
    
    -- Climbing: climb + cruise + descent
    WHEN phase IN ('departed', 'climbing') THEN
        toc_dist / climb_speed * 60
        + (dist_to_dest - toc_dist - tod_dist) / cruise_speed * 60
        + tod_dist / descent_speed * 60
    
    -- Pre-departure: taxi + full flight + TMI delay
    ELSE
        15 + dist_to_dest / cruise_speed * 60 * 1.15 + tmi_delay
END
```

#### TOD/TOC Calculations

```sql
-- Top of Descent distance (3nm per 1000ft descent)
tod_dist_nm = (filed_altitude - dest_elevation) / 1000.0 * 3.0

-- Top of Climb distance (2nm per 1000ft climb)
toc_dist_nm = (filed_altitude - current_altitude) / 1000.0 * 2.0
```

### Procedure Signature

```sql
EXEC dbo.sp_CalculateETABatch 
    @eta_count = @count OUTPUT,  -- Returns number of ETAs calculated
    @debug = 0                    -- Set to 1 for detailed output
```

### Output Columns (adl_flight_times)

| Column | Type | Description |
|--------|------|-------------|
| `eta_utc` | DATETIME2(0) | Calculated ETA (runway time) |
| `eta_runway_utc` | DATETIME2(0) | Same as eta_utc |
| `eta_epoch` | BIGINT | Unix timestamp for API consumption |
| `eta_prefix` | CHAR(1) | E/P/C/A indicator |
| `eta_confidence` | DECIMAL(3,2) | 0.00-1.00 confidence score |
| `eta_method` | VARCHAR(16) | V3, V3_SB, V3_ROUTE, V3_ROUTE_SB |
| `eta_dist_source` | VARCHAR(8) | ROUTE or GCD |
| `tod_dist_nm` | DECIMAL(10,2) | Calculated TOD distance |
| `tod_eta_utc` | DATETIME2(0) | ETA at top of descent |
| `eta_wind_component_kts` | INT | Estimated wind (+ tailwind, - headwind) |
| `eta_tmi_delay_min` | INT | TMI delay applied |
| `eta_last_calc_utc` | DATETIME2(0) | When ETA was last calculated |

### Integration Points

```
VATSIM Refresh
    │
    ├─► sp_ProcessTrajectoryBatch (inline ETA for trajectory)
    │       └─► Basic ETA for tier decisions
    │
    └─► sp_CalculateETABatch (primary ETA calculation)
            ├─► Uses fn_GetAircraftPerformance
            ├─► Uses SimBrief step climb data
            └─► Updates adl_flight_times
```

### Performance Considerations

1. **Batch Processing**: All flights processed in single set-based operation
2. **Temp Tables**: Work table built once, reused across calculations
3. **Index Usage**: Ensure clustered index on `flight_uid` in flight_times
4. **No Cursors**: Pure set-based SQL for parallelization

### Version History

| Version | Date | Changes |
|---------|------|---------|
| V3.2 | 2026-01-13 | Prefile ETA support - LEFT JOIN on position, distance fallback chain |
| V1 | 2025-12 | Basic ETA calculation |
| V2 | 2026-01 | SimBrief step climb integration |
| V3 | 2026-01 | Parsed route distance integration |
| V3.1 | 2026-01 | Added eta_dist_source tracking |
| V3.2 | 2026-01-13 | Prefile ETA support using GCD/route distance |

---

## Related Documentation

- [[Acronyms#timing--scheduling]] - ETA/ETD terminology
- [[Algorithm-Route-Parsing]] - Route distance calculation
- [[Algorithm-Trajectory-Tiering]] - Uses ETA for tier decisions
- [[GDT-Ground-Delay-Tool]] - Primary consumer of ETA data

