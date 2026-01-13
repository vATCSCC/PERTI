# TMI Status Demand Chart Integration - Transition Summary v2

## Date: January 13, 2026

## Problem Statement
GS/GDP status colors were not showing in the demand chart when simulating a Ground Stop. The demand chart shows flight phases (arrived, enroute, prefile, etc.) but didn't show TMI control statuses (PROPOSED_GS, SIMULATED_GS, ACTUAL_GS, EXEMPT).

## Root Cause
The demand API (`/api/demand/airport.php`) aggregated flights by `phase` from `adl_flight_core` table, but TMI control status is stored in `adl_flight_tmi` table which wasn't being joined. When using CTD (Controlled Time) as the time basis, the chart should show TMI status breakdown instead of flight phase breakdown.

## Completed Work - Full Implementation

### 1. Backend: AdlQueryHelper.php Enhancement ✅
**File:** `/PERTI/api/adl/AdlQueryHelper.php`

Added `buildTmiDemandAggregationQuery()` method that:
- JOINs `adl_flight_tmi` and `ntml` (program) tables
- Uses CTA (Controlled Time of Arrival) as primary time column with ETA fallback
- Aggregates by TMI status: `proposed_gs`, `simulated_gs`, `actual_gs`, `proposed_gdp`, `simulated_gdp`, `actual_gdp`, `exempt`, `uncontrolled`
- Also includes phase breakdown for uncontrolled flights
- Supports optional `program_id` filter

### 2. Backend: Demand API Enhancement ✅
**File:** `/PERTI/api/demand/airport.php`

Added support for new parameters:
- `time_basis` - 'eta' (default) or 'ctd' 
- `program_id` - optional filter to specific TMI program

When `time_basis=ctd`, the API returns TMI status breakdown in addition to phase breakdown.

### 3. Frontend: Phase Colors Configuration ✅
**File:** `/PERTI/assets/js/config/phase-colors.js`

Added:
- `uncontrolled` color: `#94a3b8` (Light Gray)
- `uncontrolled` label: "Uncontrolled"
- Updated `PHASE_STACK_ORDER` to include `uncontrolled`
- Updated `PHASE_BADGE_CLASSES` for `uncontrolled`

TMI Status Colors (FAA-style):
- `proposed_gs`: `#ca8a04` (Dark Yellow/Gold)
- `simulated_gs`: `#fef08a` (Light Yellow)
- `actual_gs`: `#eab308` (Yellow - EDCT issued)
- `proposed_gdp`: `#78350f` (Dark Brown)
- `simulated_gdp`: `#d4a574` (Light Brown/Tan)
- `actual_gdp`: `#92400e` (Brown)
- `exempt`: `#6b7280` (Gray)

### 4. Frontend: DemandChartCore Enhancement ✅
**File:** `/PERTI/assets/js/demand.js`

Updated `DemandChartCore.createChart()` to support:
- `timeBasis` option in constructor and load method ('eta' or 'ctd')
- `programId` option for TMI program filtering
- Passes `time_basis` and `program_id` to API calls
- State tracking for `timeBasis` and `programId`
- `update()` method triggers reload when time basis changes

Key changes in `load()` method:
```javascript
var params = new URLSearchParams({
    airport: airport,
    granularity: state.granularity,
    direction: state.direction,
    start: start.toISOString(),
    end: end.toISOString(),
    time_basis: state.timeBasis
});

if (state.programId) {
    params.append('program_id', state.programId);
}
```

### 5. Frontend: GDT Integration ✅
**File:** `/PERTI/assets/js/gdt.js`

Updated `loadGsDemandData()` to:
- Read `gs_model_time_basis` selector value
- Map CTD/CTA values to 'ctd' for the API
- Pass `timeBasis` and `programId` to demand chart load
- Added event handler to reload demand chart when time basis selector changes

```javascript
function loadGsDemandData(airport) {
    // ... normalization code ...
    
    // Get time basis from selector
    var timeBasisEl = document.getElementById("gs_model_time_basis");
    var timeBasis = timeBasisEl ? timeBasisEl.value : "eta";
    if (timeBasis === "ctd" || timeBasis === "cta") {
        timeBasis = "ctd";
    } else {
        timeBasis = "eta";
    }

    GS_DEMAND_CHART.load(airport, {
        direction: GS_DEMAND_DIRECTION,
        granularity: GS_DEMAND_GRANULARITY,
        timeBasis: timeBasis,
        programId: GS_CURRENT_PROGRAM_ID || null
    });
}
```

Also added time basis change handler to reload demand chart:
```javascript
timeBasisEl.addEventListener("change", function() {
    // ... existing model graph rendering ...
    
    // Also reload the demand chart with new time basis
    if (airport && GS_DEMAND_CHART) {
        loadGsDemandData(airport);
    }
});
```

## Testing

Test scenarios:
1. Create GS at any airport with SIMULATED status
2. Open Power Run modal
3. Set Time Basis selector to "CTD" (Controlled Time of Departure)
4. Verify demand chart shows yellow (simulated_gs) bars for controlled flights
5. Toggle Time Basis between ETA and CTD
6. Verify chart updates correctly with different color schemes

Expected behavior:
- **Time Basis = ETA**: Chart shows flight phases (arrived, enroute, prefile, etc.)
- **Time Basis = CTD**: Chart shows TMI statuses (proposed_gs, simulated_gs, actual_gs, exempt, uncontrolled)

## Key Files Reference

| File | Purpose | Status |
|------|---------|--------|
| `/PERTI/api/adl/AdlQueryHelper.php` | SQL query builder - TMI query added | ✅ Complete |
| `/PERTI/api/demand/airport.php` | Demand API - time_basis support added | ✅ Complete |
| `/PERTI/assets/js/config/phase-colors.js` | Color config - TMI colors defined | ✅ Complete |
| `/PERTI/assets/js/demand.js` | DemandChartCore - timeBasis support | ✅ Complete |
| `/PERTI/assets/js/gdt.js` | GDT page - loadGsDemandData() updated | ✅ Complete |

## Database Tables Involved

- `adl_flight_core` - Flight phase, callsign, flight_uid
- `adl_flight_plan` - Origin/destination airports
- `adl_flight_times` - ETA/ETD times
- `adl_flight_tmi` - TMI control data (ctl_type, cta_utc, ctd_utc, program_id, ctl_exempt)
- `ntml` - Program definitions (program_id, status: PROPOSED/SIMULATED/ACTUAL)

## API Usage Example

```
GET /api/demand/airport.php?airport=EDDF&time_basis=ctd&granularity=15min&program_id=123
```

Response includes:
```json
{
  "success": true,
  "time_basis": "ctd",
  "data": {
    "arrivals": [{
      "time_bin": "2026-01-13T20:00:00Z",
      "total": 46,
      "breakdown": {
        "proposed_gs": 0,
        "simulated_gs": 12,
        "actual_gs": 0,
        "proposed_gdp": 0,
        "simulated_gdp": 0,
        "actual_gdp": 0,
        "exempt": 2,
        "uncontrolled": 32,
        "arrived": 5,
        "enroute": 20,
        "departed": 7,
        "taxiing": 0,
        "prefile": 0,
        "descending": 0,
        "disconnected": 0
      }
    }]
  }
}
```

## Summary

The TMI Status Demand Chart integration is now complete. When users toggle the Time Basis selector to CTD/CTA in the GDT Power Run modal:
- The demand chart automatically reloads with TMI status coloring
- Controlled flights show as yellow (GS) or brown (GDP) bars
- Exempt flights show as gray
- Uncontrolled flights show as light gray
- The chart accurately reflects the simulation state

All backend and frontend changes have been implemented and are ready for testing.
