# Trajectory Tiering Algorithm

The trajectory logging system uses an 8-tier priority system (0-7) to intelligently capture flight position history. Higher-priority tiers (lower numbers) capture positions more frequently during operationally significant moments, while lower-priority flights are sampled less often to conserve storage.

---

## For Traffic Managers

### What Trajectory Data Shows

Trajectory history allows you to:
- **Replay flight paths** for post-event analysis
- **Review flight profiles** for climb/descent patterns
- **Analyze holding patterns** during weather events
- **Verify compliance** with reroute instructions

### Why Some Flights Have More Detail

The system automatically prioritizes tracking based on operational significance:

| Situation | Priority | Capture Rate |
|-----------|----------|--------------|
| Takeoff/Landing | Highest | Every 15 seconds |
| Approaching destination | High | Every 30 seconds |
| Transitioning altitude | High | Every 30 seconds |
| Stable cruise over US | Medium | Every 5 minutes |
| Oceanic cruise | Lower | Every 10-30 minutes |
| Outside covered airspace | None | Not logged |

### Covered Airspace

Flights are tracked if they have origin, destination, or current position in:
- **United States** (K*, P* airports)
- **Canada** (C* airports)
- **Mexico** (MM* airports)
- **Central America** (MG, MH, MN, MR, MP, MS, MB airports)
- **Caribbean** (T* airports)
- **South America** (S* airports)

European-to-Europe flights, for example, are **not logged** (Tier 7) unless they transit Americas airspace.

---

## For Technical Operations

### Tier Definitions

| Tier | Interval | Criteria | Daily Rows (est) |
|------|----------|----------|------------------|
| **0** | 15 sec | Critical phases: takeoff, landing, go-around | ~40,000 |
| **1** | 30 sec | Approaching events: TOD, destination <100nm, climbing/descending | ~60,000 |
| **2** | 1 min | Oceanic cruise (NAT, PAC) | ~20,000 |
| **3** | 2 min | Ground operations (taxi) | ~15,000 |
| **4** | 5 min | Stable cruise (US domestic) | ~80,000 |
| **5** | 10 min | Extended oceanic, sim pause detection | ~20,000 |
| **6** | 30 min | Ultra-long oceanic (>1000nm from land) | ~3,000 |
| **7** | Never | Irrelevant (outside covered airspace) | 0 |

**Total estimated daily rows:** ~248,000 (based on 2,000 active flights)

### Monitoring Tier Distribution

```sql
-- Current tier distribution
SELECT 
    last_trajectory_tier AS tier,
    COUNT(*) AS flights,
    dbo.fn_GetTierIntervalSeconds(last_trajectory_tier) AS interval_sec
FROM dbo.adl_flight_core
WHERE is_active = 1
GROUP BY last_trajectory_tier
ORDER BY last_trajectory_tier;
```

**Expected Distribution:**
- Tier 0-1: 10-15% (critical phases)
- Tier 4: 50-60% (stable cruise)
- Tier 5-6: 5-10% (oceanic)
- Tier 7: 10-20% (irrelevant)

### Common Issues

| Symptom | Cause | Resolution |
|---------|-------|------------|
| All flights Tier 7 | fn_IsFlightRelevant broken | Check function exists |
| Too many Tier 0 | Distance calculations wrong | Verify dist_to_dest_nm populated |
| Storage growing fast | Tier thresholds too aggressive | Review tier criteria |
| Missing transitions | Interval too long | Check tier promotion logic |

### Storage Management

```sql
-- Trajectory storage by age
SELECT 
    CAST(recorded_utc AS DATE) AS date,
    COUNT(*) AS rows,
    COUNT(*) * 60 / 1024.0 AS size_kb_estimate
FROM dbo.adl_flight_trajectory
WHERE recorded_utc > DATEADD(DAY, -7, GETUTCDATE())
GROUP BY CAST(recorded_utc AS DATE)
ORDER BY date DESC;
```

**Storage estimates:**
- Hot tier (7 days): ~1.7M rows, ~100 MB
- Warm tier (30 days): ~7.5M rows, ~450 MB
- Cold tier (90 days): ~22M rows, ~1.3 GB

---

## For Developers

### Tier Assignment Logic

The `fn_GetTrajectoryTier` function evaluates flights in priority order:

```
1. Check relevance (fn_IsFlightRelevant) → If 0, return Tier 7
2. Check Tier 0 conditions (critical phases)
3. Check Tier 1 conditions (approaching events)
4. Check Tier 2 conditions (oceanic)
5. Check Tier 3 conditions (ground ops)
6. Check Tier 4 conditions (stable cruise)
7. Check Tier 5 conditions (extended oceanic)
8. Check Tier 6 conditions (ultra-long oceanic)
9. Default: Tier 4
```

### Tier 0 Triggers (15-second logging)

```sql
-- Initial climb
@dist_from_origin_nm < 50 AND @vertical_rate_fpm > 300 AND @altitude_ft < 18000

-- Final approach
@dist_to_dest_nm < 15 AND @vertical_rate_fpm < -300 AND @altitude_ft < 10000

-- Go-around
@dist_to_dest_nm < 5 AND @vertical_rate_fpm > 1000 AND @altitude_ft < 5000

-- Runway operations
@groundspeed_kts BETWEEN 40 AND 180 AND @altitude_ft < 500 AND near_airport

-- Very close to airport
@dist_from_origin_nm < 3 OR @dist_to_dest_nm < 3
```

### Tier 1 Triggers (30-second logging)

```sql
-- Approaching TOD (within 5 minutes)
@time_to_tod_min <= 5 AND @time_to_tod_min > 0

-- Approaching destination
@dist_to_dest_nm < 100

-- Altitude transition in cruise
@altitude_ft > 25000 AND ABS(@vertical_rate_fpm) > 500

-- Any climbing/descending phase
@phase IN ('departed', 'climbing', 'descending')
```

### Tier Demotion Blocking

The system **prevents inappropriate demotion** to lower tiers:

```sql
-- Example: Don't demote from Tier 1 to Tier 4 if approaching TOD
IF @time_to_tod_min IS NOT NULL AND @time_to_tod_min <= 10
    RETURN 1;  -- Stay at Tier 1, don't demote to Tier 4
```

This ensures operationally significant events aren't missed due to the flight being in "stable cruise" moments before a transition.

### Relevance Function (fn_IsFlightRelevant)

```sql
-- Checks origin airport prefix
IF @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%' -- US
    RETURN 1;
IF @dept_icao LIKE 'C%'  -- Canada
    RETURN 1;
IF @dept_icao LIKE 'MM%' -- Mexico
    RETURN 1;
-- ... etc

-- Checks destination airport prefix
-- ... same patterns

-- Checks current position bounding box
IF @current_lat BETWEEN 15 AND 72 
   AND @current_lon BETWEEN -180 AND -50  -- North America
    RETURN 1;
```

### Procedure Integration

```sql
-- sp_ProcessTrajectoryBatch uses iTVFs for parallel execution
;WITH FlightTiers AS (
    SELECT
        c.flight_uid,
        tier.tier AS current_tier,
        interval_calc.interval_seconds
    FROM dbo.adl_flight_core c
    CROSS APPLY dbo.itvf_GetTrajectoryTier(...) tier
    CROSS APPLY dbo.itvf_GetTierIntervalSeconds(c.last_trajectory_tier) interval_calc
    WHERE c.is_active = 1 AND c.is_relevant = 1
)
INSERT INTO dbo.adl_flight_trajectory (...)
SELECT ...
FROM FlightTiers ft
WHERE ft.current_tier < 7  -- Don't log Tier 7
  AND (
      ft.last_trajectory_utc IS NULL
      OR DATEDIFF(SECOND, ft.last_trajectory_utc, @now) >= ft.interval_seconds
  );
```

### Output Table: adl_flight_trajectory

| Column | Type | Description |
|--------|------|-------------|
| `trajectory_id` | BIGINT | Primary key |
| `flight_uid` | BIGINT | Foreign key to flight_core |
| `recorded_utc` | DATETIME2(0) | When position was logged |
| `lat` | DECIMAL(10,7) | Latitude |
| `lon` | DECIMAL(11,7) | Longitude |
| `altitude_ft` | INT | Altitude in feet |
| `groundspeed_kts` | INT | Groundspeed in knots |
| `heading_deg` | SMALLINT | Heading (0-359) |
| `vertical_rate_fpm` | INT | Vertical rate (+ climb, - descent) |
| `tier` | TINYINT | Tier at time of logging |
| `tier_reason` | VARCHAR(32) | Why this tier was assigned |
| `flight_phase` | VARCHAR(16) | Phase at time of logging |
| `dist_to_dest_nm` | DECIMAL(10,2) | Distance remaining |
| `dist_from_origin_nm` | DECIMAL(10,2) | Distance flown |
| `source` | VARCHAR(16) | Data source (vatsim, simulated) |

### Performance Optimization

The V2.0 implementation uses **inline table-valued functions (iTVFs)** instead of scalar UDFs:

| Approach | Execution | Parallelism |
|----------|-----------|-------------|
| Scalar UDF | Row-by-row | Single-threaded |
| iTVF | Set-based | Multi-threaded (8 vCores) |

Expected speedup: **4-8x** for trajectory processing step.

---

## Related Documentation

- [[Algorithm-ETA-Calculation]] - Uses trajectory tier for ETA decisions
- [[Algorithm-Zone-Detection]] - Complements trajectory with OOOI events
- [[Database-Schema]] - Table definitions
- [[Troubleshooting]] - Performance issues

