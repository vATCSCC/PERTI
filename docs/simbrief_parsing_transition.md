# SimBrief Parsing Transition Summary

**Date:** January 7, 2026  
**Feature:** ETA Enhancement Item #7 - SimBrief Parsing  
**Status:** ✅ Complete and Deployed

---

## What Was Implemented

SimBrief/ICAO flight plan data extraction from VATSIM API remarks and route fields. The system now:

1. **Detects SimBrief-generated flight plans** via remarks patterns
2. **Parses ICAO Item 18 indicators** (DOF, REG, OPR, PBN, etc.)
3. **Extracts step climbs** from route strings (e.g., `WAYPOINT/N0460F360`)
4. **Derives runways** from SID/STAR procedure names

---

## Deployment Results

| Metric | Value |
|--------|-------|
| Flights processed | 463 |
| SimBrief detected | 384 (83%) |
| With step climbs | 157 (34%) |
| Processing time | 3.3 seconds |

### Step Climb Data Quality

| Metric | Initial Altitude | Final Altitude |
|--------|------------------|----------------|
| Min | FL170 | FL170 |
| Max | FL430 | FL430 |
| Average | FL349 | FL358 |

Average ~900 ft increase from initial to final confirms step climbs captured correctly.

---

## Database Objects Created

### Functions

| Function | Purpose |
|----------|---------|
| `fn_ParseICAORemarks` | Extract ICAO Item 18 indicators from remarks |
| `fn_ParseRouteStepClimbs` | Extract step climbs from route string |
| `fn_IsSimBriefFlight` | Detect SimBrief-generated flight plan |
| `fn_ExtractRunwayFromProcedure` | Extract runway from SID/STAR name |

### Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_ParseSimBriefData` | Parse single flight (with @debug option) |
| `sp_ParseSimBriefDataBatch` | Batch process multiple flights |

### Columns Added/Used (adl_flight_plan)

```sql
is_simbrief         BIT          -- SimBrief-generated flag
simbrief_id         NVARCHAR(32) -- SimBrief OFP ID (future use)
cost_index          INT          -- Cost Index (future use)
dep_runway          NVARCHAR(4)  -- Departure runway
dep_runway_source   NVARCHAR(16) -- DP_PARSE, TBFM, USER
arr_runway          NVARCHAR(4)  -- Arrival runway  
arr_runway_source   NVARCHAR(16) -- STAR_PARSE, TBFM, USER
initial_alt_ft      INT          -- Initial cruise altitude
final_alt_ft        INT          -- Final cruise altitude
stepclimb_count     INT          -- Number of step climbs
```

### Index Created

```sql
IX_flight_plan_simbrief ON adl_flight_plan (is_simbrief) WHERE is_simbrief = 1
```

---

## Files Created

| File | Location |
|------|----------|
| `sp_ParseSimBriefData.sql` | `adl/procedures/` |
| `048_simbrief_parsing.sql` | `adl/migrations/` |
| `simbrief_parsing_summary.md` | `docs/` |

---

## SimBrief Detection Logic

A flight is flagged as SimBrief (`is_simbrief = 1`) if remarks contain:

1. Explicit `SIMBRIEF` or `SB/`
2. `RMK/TCAS` (SimBrief always includes this)
3. Full ICAO pattern: `PBN/` + `DOF/` + `RMK/` together
4. `PBN/A1B1...` + `NAV/` pattern

---

## Step Climb Parsing

Extracts from route string patterns like:

| Pattern | Example | Parsed As |
|---------|---------|-----------|
| TAS + FL | `WAYPOINT/N0460F360` | 460 kts TAS, FL360 |
| Mach + FL | `WAYPOINT/M083F390` | Mach 0.83, FL390 |
| FL only | `WAYPOINT/F350` | FL350 |
| Metric | `WAYPOINT/K0850S1200` | 850 km/h, 1200m (converted) |

---

## Usage

```sql
-- Parse single flight with debug output
EXEC sp_ParseSimBriefData @flight_uid = 12345, @debug = 1;

-- Batch process unparsed flights only
EXEC sp_ParseSimBriefDataBatch @batch_size = 100, @only_unparsed = 1;

-- Reprocess ALL active flights
EXEC sp_ParseSimBriefDataBatch @batch_size = 500, @only_unparsed = 0;
```

---

## Verification Queries

```sql
-- Check SimBrief detection summary
SELECT 
    is_simbrief,
    COUNT(*) AS flights,
    AVG(stepclimb_count) AS avg_steps
FROM dbo.adl_flight_plan fp
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
WHERE c.is_active = 1
GROUP BY is_simbrief;

-- Sample step climbs
SELECT TOP 20
    c.callsign,
    sc.step_sequence,
    sc.waypoint_fix,
    sc.flight_level AS FL,
    sc.speed_kts,
    sc.speed_mach,
    sc.raw_text
FROM dbo.adl_flight_stepclimbs sc
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = sc.flight_uid
WHERE c.is_active = 1
ORDER BY c.callsign, sc.step_sequence;

-- Altitude ranges
SELECT 
    COUNT(*) AS flights_with_altitudes,
    MIN(initial_alt_ft) AS min_initial,
    MAX(final_alt_ft) AS max_final,
    AVG(initial_alt_ft) AS avg_initial,
    AVG(final_alt_ft) AS avg_final
FROM dbo.adl_flight_plan fp
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
WHERE c.is_active = 1
  AND fp.initial_alt_ft IS NOT NULL;
```

---

## Integration Notes

### Not Yet Integrated

The batch parsing is standalone - not yet called automatically. Consider:

1. **Add to refresh cycle** - Call after route parsing
2. **Add to parse daemon** - Process alongside route parsing
3. **Trigger-based** - Parse when remarks change

### ETA Calculation Enhancement

Step climb data can improve ETA:
- Use `initial_alt_ft` for climb time estimation
- Use step climb waypoints for altitude profile prediction
- Use `final_alt_ft` for optimal cruise speed lookup from aircraft performance

---

## ETA Enhancement Project Status

| Item | Description | Status |
|------|-------------|--------|
| ✅ #6 | ETA Consolidation | Complete |
| ✅ #3 | Aircraft Performance (OpenAP) | Complete |
| ✅ #4 | OOOI Zone Detection | Complete |
| ✅ #7 | SimBrief Parsing | **Complete** |
| ⏳ #1 | Route Distance | **Next** |
| ⏳ #2 | Wind Data | Ready |
| ⏳ #5 | Sector ETA | Ready |

---

## Next Session: Route Distance (#1)

**Goal:** Use parsed route geometry for more accurate distance remaining calculation.

**Current State:**
- `dist_to_dest_nm` uses great circle distance from current position to destination
- Parsed routes exist in `adl_flight_waypoints` with lat/lon for each fix
- Route geometry stored in `route_geometry` (GEOGRAPHY LineString)

**Enhancement:**
- Calculate distance along remaining route segments
- Account for routing deviations from great circle
- Use for more accurate ETA calculation

**Key Tables:**
- `adl_flight_waypoints` - Parsed route with sequence and coordinates
- `adl_flight_plan` - Has `route_geometry` GEOGRAPHY column
- `adl_flight_position` - Current position with `dist_to_dest_nm`

**Approach Options:**
1. Calculate sum of remaining segment distances from waypoints
2. Use GEOGRAPHY `STLength()` on remaining portion of route_geometry
3. Project current position onto route and calculate remaining distance

---

## Session Start Prompt

```
Continue working on the ETA/Trajectory Enhancement Project.

Previous session completed SimBrief Parsing (#7):
- 384 flights detected as SimBrief (83%)
- 157 flights with step climbs extracted
- Functions: fn_ParseICAORemarks, fn_ParseRouteStepClimbs, fn_IsSimBriefFlight
- Procedures: sp_ParseSimBriefData, sp_ParseSimBriefDataBatch

Next task: Route Distance (#1) - Use parsed route geometry for more accurate 
distance remaining instead of great circle.

Current implementation uses great circle from position to destination.
Enhancement: Calculate distance along actual parsed route segments.

Please start by:
1. Checking how routes are currently parsed (sp_ParseRoute)
2. Understanding the adl_flight_waypoints structure
3. Designing approach to calculate remaining route distance
4. Creating sp_CalculateRouteDistance or integrating into ETA batch
```
