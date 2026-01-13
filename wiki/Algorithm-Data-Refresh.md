# Data Refresh Pipeline

The data refresh pipeline is the core process that ingests VATSIM flight data every ~15 seconds and orchestrates all downstream calculations. The main procedure `sp_Adl_RefreshFromVatsim_Normalized` executes 13+ processing steps within a target window of 5 seconds.

---

## For Traffic Managers

### What Happens Every 15 Seconds

When PERTI refreshes, it:

1. **Receives pilot data** from VATSIM API (~1,500-3,000 flights)
2. **Updates positions** for all active flights
3. **Calculates ETAs** for arrivals and departures
4. **Detects phase changes** (taxiing → airborne, etc.)
5. **Tracks OOOI events** (OUT/OFF/ON/IN times)
6. **Queues new routes** for parsing
7. **Logs trajectory history** based on operational priority

### Data Freshness

| Data Type | Latency | Update Trigger |
|-----------|---------|----------------|
| Position | ~15 seconds | Every refresh |
| ETA | ~15 seconds | Every refresh |
| Phase | ~15 seconds | Position change |
| OOOI Times | ~15 seconds | Zone transition |
| Route Geometry | 1-2 minutes | After route queued & parsed |

### When Data May Be Delayed

- **High traffic events**: Processing may take longer
- **Azure scaling**: Cold starts can add latency
- **VATSIM API issues**: Upstream delays
- **Route parsing backlog**: New routes take time to parse

---

## For Technical Operations

### Monitoring Refresh Health

#### Primary Health Query

```sql
-- Last 10 refresh cycles
SELECT TOP 10
    run_utc,
    pilots_received,
    new_flights,
    etas_calculated,
    trajectories_logged,
    zone_transitions,
    elapsed_ms
FROM dbo.adl_run_log
ORDER BY run_utc DESC;
```

**Expected Values:**
| Metric | Normal Range |
|--------|--------------|
| pilots_received | 1,000 - 4,000 |
| elapsed_ms | 1,000 - 5,000 |
| etas_calculated | 80-95% of pilots |
| trajectories_logged | 10-30% of pilots |

#### Step Timing Analysis

```sql
-- Detailed step timings
SELECT TOP 10
    run_utc,
    elapsed_ms AS total_ms,
    step1_json_ms,
    step2_core_ms,
    step3_position_ms,
    step4_flightplan_ms,
    step4b_etd_ms,
    step8_trajectory_ms,
    step9_zone_ms
FROM dbo.adl_run_log
ORDER BY run_utc DESC;
```

**Step Timing Targets:**
| Step | Target | Description |
|------|--------|-------------|
| step1_json_ms | < 500ms | JSON parsing |
| step2_core_ms | < 300ms | Core table merge |
| step3_position_ms | < 200ms | Position updates |
| step4_flightplan_ms | < 300ms | Flight plan merge |
| step8_trajectory_ms | < 500ms | ETA + trajectory |
| step9_zone_ms | < 500ms | Zone detection |

#### Alert Conditions

| Condition | Threshold | Action |
|-----------|-----------|--------|
| elapsed_ms > 10000 | Critical | Check step timings |
| pilots_received = 0 | Critical | Check VATSIM API |
| etas_calculated = 0 | Warning | Check sp_CalculateETABatch |
| zone_transitions = 0 | Info | May be normal (no transitions) |

### Common Issues

| Symptom | Likely Cause | Resolution |
|---------|--------------|------------|
| elapsed_ms > 10s | One step slow | Check step timings |
| No pilots_received | VATSIM API down | Check API status |
| No new_flights | All flights seen | Normal steady state |
| step9_zone_ms high | Spatial query slow | Check airport_geometry index |
| step8_trajectory_ms high | ETA calculation slow | Check aircraft_performance_profiles |

### Manual Refresh Trigger

```sql
-- For testing (requires JSON payload)
DECLARE @json NVARCHAR(MAX) = '{"pilots": [], "prefiles": []}';
EXEC dbo.sp_Adl_RefreshFromVatsim_Normalized @Json = @json;
```

---

## For Developers

### Procedure Steps

The main procedure executes these steps in sequence:

| Step | Name | Function | Tables Affected |
|------|------|----------|-----------------|
| 1 | Parse JSON | Extract pilots from JSON | #pilots |
| 1b | Enrich | Add airport data | #pilots |
| 2 | Core Upsert | Merge flight core data | adl_flight_core |
| 2a | Prefiles | Process VATSIM prefiles | adl_flight_core |
| 2b | Times Init | Create times rows | adl_flight_times |
| 3 | Position | Update positions | adl_flight_position |
| 4 | Flight Plan | Detect route changes | adl_flight_plan |
| 4b | ETD Calc | Calculate departure times | adl_flight_times |
| 4c | SimBrief | Parse SimBrief data | adl_flight_stepclimbs |
| 5 | Queue | Queue routes for parsing | adl_parse_queue |
| 5b | Route Dist | Update route distances | adl_flight_position |
| 6 | Aircraft | Update aircraft info | adl_flight_aircraft |
| 7 | Inactive | Mark stale flights | adl_flight_core |
| 8 | Trajectory | ETA + trajectory logging (V3.2: includes prefiles) | adl_flight_times, adl_flight_trajectory |
| 8b | Buckets | Update arrival buckets | adl_flight_times |
| 8c | Waypoint ETA | Calculate waypoint ETAs | adl_flight_waypoints |
| 9 | Zone | OOOI zone detection (V2.1: sets atd/ata times) | adl_zone_events, adl_flight_times |
| 10 | Boundary | ARTCC/Sector detection | (disabled pending optimization) |
| 11 | Crossings | Planned crossings | (disabled pending optimization) |
| 12 | Log Traj | Archive trajectory | adl_flight_trajectory |
| 13 | Snapshot | Phase snapshot for charts | adl_phase_snapshots |

### Normalized Table Structure

The ADL uses a normalized schema for efficient updates:

```
adl_flight_core (identity, lifecycle)
    │
    ├── adl_flight_position (lat/lon, speed, altitude)
    ├── adl_flight_plan (route, procedures, distances)
    ├── adl_flight_times (ETA, OOOI, buckets)
    ├── adl_flight_aircraft (type, weight, airline)
    ├── adl_flight_tmi (EDCT, CTA, slot)
    └── adl_flight_waypoints (parsed route)
```

**Benefits:**
- Update only changed columns
- Efficient indexing per concern
- Parallel query execution
- Reduced lock contention

### JSON Structure (Input)

```json
{
  "pilots": [
    {
      "cid": 1234567,
      "callsign": "UAL123",
      "latitude": 40.6413,
      "longitude": -73.7781,
      "altitude": 35000,
      "groundspeed": 450,
      "heading": 270,
      "flight_plan": {
        "departure": "KJFK",
        "arrival": "KLAX",
        "route": "DEEZZ5 DEEZZ J80 FLM SUNST2",
        "remarks": "PBN/A1B1 DOF/260115",
        "altitude": "FL350",
        "cruise_tas": "N0450"
      }
    }
  ],
  "prefiles": [
    {
      "cid": 7654321,
      "callsign": "DAL456",
      "flight_plan": {...}
    }
  ]
}
```

### Phase Detection Logic

```sql
CASE
    WHEN lat IS NULL THEN 'prefile'
    WHEN groundspeed < 50 AND pct_complete > 85 THEN 'arrived'
    WHEN groundspeed < 50 THEN 'taxiing'
    WHEN altitude < 10000 AND pct_complete < 15 THEN 'departed'
    WHEN altitude < 10000 AND pct_complete > 85 THEN 'descending'
    ELSE 'enroute'
END
```

### Route Change Detection

Routes are tracked via hash comparison:

```sql
-- Route hash includes route + remarks
fp_hash = HASHBYTES('SHA2_256', route + '|' + remarks)

-- If hash changed, queue for re-parsing
IF target.fp_hash IS NULL OR target.fp_hash != source.route_hash
    SET parse_status = 'PENDING'
```

### Sub-Procedure Calls

The main procedure orchestrates sub-procedures:

```sql
-- ETA calculation (V3.2 - includes prefiles)
EXEC dbo.sp_ProcessTrajectoryBatch 
    @process_eta = 1, 
    @process_trajectory = 1,
    @eta_count = @eta_count OUTPUT,
    @traj_count = @traj_count OUTPUT;

-- SimBrief parsing
EXEC dbo.sp_ParseSimBriefDataBatch 
    @batch_size = 50, 
    @only_unparsed = 1;

-- Zone detection (V2.1 - sets atd_utc/ata_runway_utc)
EXEC dbo.sp_ProcessZoneDetectionBatch 
    @transitions_detected = @zone_transitions OUTPUT;

-- Waypoint ETAs
EXEC dbo.sp_CalculateWaypointETABatch 
    @waypoint_count = @waypoint_etas OUTPUT;
```

### Performance Optimizations

| Optimization | Implementation |
|--------------|----------------|
| **Batch processing** | Set-based operations, no cursors |
| **Index hints** | Clustered indexes on flight_uid |
| **Temp tables** | Pre-filter data before joins |
| **Parallel execution** | iTVFs instead of scalar UDFs |
| **Early termination** | Skip unchanged data |
| **Incremental updates** | Only ETD for flights without ETD |

### Output Stats

```sql
-- Return structure
SELECT
    pilots_received,
    new_flights,
    updated_flights,
    routes_queued,
    route_dists_updated,
    etds_calculated,
    simbrief_parsed,
    etas_calculated,
    waypoint_etas,
    trajectories_logged,
    zone_transitions,
    elapsed_ms,
    -- Per-step timings
    step1_json_ms,
    step2_core_ms,
    ... (all step timings)
```

### Error Handling

The procedure uses:
- `SET XACT_ABORT ON` - Roll back on errors
- `TRY/CATCH` in sub-procedures - Isolate failures
- Error logging to `adl_run_log` - For diagnostics

### Daemon Integration

The PHP daemon calls the procedure:

```php
// vatsim_adl_daemon.php
while (true) {
    $json = fetch_vatsim_api();
    $result = sqlsrv_query($conn_adl, 
        "EXEC sp_Adl_RefreshFromVatsim_Normalized @Json = ?",
        [$json]
    );
    log_stats(sqlsrv_fetch_array($result));
    sleep(15 - elapsed_time());
}
```

---

## Related Documentation

- [[Algorithm-ETA-Calculation]] - Step 8 details
- [[Algorithm-Trajectory-Tiering]] - Step 8 details
- [[Algorithm-Zone-Detection]] - Step 9 details
- [[Algorithm-Route-Parsing]] - Step 5 follow-up
- [[Daemons-and-Scripts]] - Daemon operation
- [[Troubleshooting]] - Common issues

