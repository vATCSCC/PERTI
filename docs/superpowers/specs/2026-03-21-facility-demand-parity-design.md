# Facility Demand Parity Design Spec

**Date**: 2026-03-21
**Status**: Approved
**Approach**: B — Pragmatic Parity

## Problem

The demand page (`demand.php`) has two modes: airport and facility (TRACON/ARTCC/FIR/Group). Airport mode is feature-complete with 11 chart views, true time axis, rate lines, flight drill-down, and summary breakdowns. Facility mode was built as a minimal MVP — it only supports 2 chart views (status + airport), uses a category axis, has no breakdown data, no drill-down, and a sparse info bar.

### Gap Summary

| Feature | Airport Mode | Facility Mode |
|---------|-------------|---------------|
| Chart axis | True time (`type: 'time'`) | Category (`type: 'category'`) |
| Chart views | 11 (status + 10 breakdowns) | 2 (status + airport) |
| API calls | 6 parallel (demand, rates, ATIS, config, scheduled configs, TMI programs) | 1 (facility.php) |
| Summary/breakdown data | 10 dimensions via summary.php | None |
| Info bar | Config, ATIS, phase breakdown, rates | Total counts only |
| Rate lines | AAR/ADR + TMI markers | None |
| Flight drill-down | Click bar → flight list modal | None |
| DataZoom | Horizontal + vertical | Horizontal only |

## Design

### 1. Backend: `api/demand/facility_summary.php` (NEW)

Mirrors `api/demand/summary.php` but scoped to facility boundaries instead of a single airport.

**Parameters**:
- `type` — tracon, artcc, group (string, required)
- `code` — facility code e.g. PCT, ZDC (string, required)
- `mode` — airport (default) or crossing (string)
- `direction` — arr, dep, both (string; `thru` only valid in crossing mode)
- `granularity` — integer minutes: `15`, `30`, `60` (matches summary.php format; **not** facility.php's string format)
- `start`, `end` — ISO 8601 time range
- `time_bin` — (optional) ISO 8601 timestamp for drill-down, returns individual flights

**Flight Selection Logic** (reuse patterns from facility.php):
- **ARTCC airport mode**: `WHERE c.dest_artcc = @code` (arrivals) / `c.dept_artcc = @code` (departures)
- **TRACON airport mode**: Match dest/dept against airports within the TRACON via `airport_geometry` TRACON boundary lookup
- **Group mode**: Union of constituent facility airports
- **Crossing mode**: Join `adl_flight_planned_crossings` for boundary crossings

**Implementation note**: Build a reusable CTE (`facility_flights`) that selects `flight_uid` + `direction` based on type/code/mode, then all 10 breakdown queries JOIN against this CTE. This avoids duplicating the facility selection logic 10 times. For crossing mode, the CTE uses `adl_flight_planned_crossings` instead of the airport-based filtering.

**Additional Joins** (beyond facility.php):
- `adl_flight_aircraft a ON a.flight_uid = c.flight_uid` — for carrier (carrier_icao), weight_class, equipment (aircraft_icao)
- `adl_flight_plan p` already joined — provides dep_fix, arr_fix, dp_name, star_name

**Returns** (same structure as summary.php):
```json
{
    "success": true,
    "facility": { "type": "artcc", "code": "ZDC", "name": "Washington Center" },
    "top_origins": [{"artcc": "ZNY", "count": 45}, ...],
    "top_carriers": [{"carrier": "AAL", "count": 30}, ...],
    "origin_artcc_breakdown": { "<time_bin>": [{"artcc": "ZNY", "count": 12, "phases": {...}}, ...] },
    "dest_artcc_breakdown": { "<time_bin>": [...] },
    "carrier_breakdown": { "<time_bin>": [...] },
    "weight_breakdown": { "<time_bin>": [...] },
    "equipment_breakdown": { "<time_bin>": [...] },
    "rule_breakdown": { "<time_bin>": [...] },
    "dep_fix_breakdown": { "<time_bin>": [...] },
    "arr_fix_breakdown": { "<time_bin>": [...] },
    "dp_breakdown": { "<time_bin>": [...] },
    "star_breakdown": { "<time_bin>": [...] },
    "data_hash": "md5_checksum"
}
```

**Hash computation**: Compute `md5(json_encode($response))` BEFORE adding the hash to the response object, then set `$response['data_hash'] = $hash`. This matches the pattern in summary.php where the hash does not include itself.

**Drill-down mode** (when `time_bin` provided):

Uses the same facility CTE to select flights, then filters to the specific time bin window (`time_bin` to `time_bin + granularity`). Returns individual flights:

```json
{
    "success": true,
    "flights": [
        {
            "callsign": "AAL123",
            "origin": "KJFK",
            "destination": "KLAX",
            "origin_artcc": "ZNY",
            "dest_artcc": "ZLA",
            "time": "2026-03-21T14:00:00Z",
            "direction": "arrival",
            "status": "enroute",
            "aircraft": "B738",
            "carrier": "AAL",
            "weight_class": "M",
            "flight_rules": "IFR",
            "dfix": "DEEZZ",
            "afix": "SANTA",
            "dp_name": "BRIDG5",
            "star_name": "GISPO3"
        }
    ]
}
```

**Drill-down SQL pattern for facility scope**:
```sql
-- For ARTCC airport mode (arrivals example):
SELECT c.callsign, p.dept AS origin, p.dest AS destination, ...
FROM adl_flight_core c
JOIN adl_flight_plan p ON p.flight_uid = c.flight_uid
JOIN adl_flight_times t ON t.flight_uid = c.flight_uid
JOIN adl_flight_aircraft a ON a.flight_uid = c.flight_uid
WHERE c.dest_artcc = @code
  AND t.eta_utc >= @time_bin_start AND t.eta_utc < @time_bin_end
  AND c.is_active = 1

-- For crossing mode: JOIN adl_flight_planned_crossings instead
```

**Caching**: APCu with same TTL as facility.php (30s for tracon/artcc, 60s for group). Hash-based change detection via `X-If-Data-Hash` header.

**Time range limits**: Same as facility.php — enforce 4-hour max for group mode, no limit for tracon/artcc.

### 2. Frontend: Time Axis Upgrade — `renderFacilityStatusChart()`

Replace the current category-axis implementation with the true time axis pattern from `renderChart()`:

**Key changes:**
- Use `generateAllTimeBins()` for gap-free time coverage
- Use `buildPhaseSeriesTimeAxis()` for `[timestamp, value]` series with centered bars
- Switch to `xAxis: { type: 'time' }` with AADC-style `HHmmZ` labels
- Add departure hatching pattern (decal) via `buildPhaseSeriesTimeAxis()` which already handles this
- Replace simple horizontal-only dataZoom with `getDataZoomConfig()` (horizontal + vertical)
- Add current time marker via `getCurrentTimeMarkLineForTimeAxis()`
- Capture/restore legend and dataZoom state using `captureLegendSelected()` / `captureDataZoomState()`
- Add click handler for drill-down (calls `showFacilityFlightDetails()`)

**Not included**: Rate lines and TMI program markers (airport-specific).

### 3. Frontend: Breakdown View Wiring — `renderFacilityChart()`

Expand the current 2-view dispatcher to handle all views:

```javascript
function renderFacilityChart(data) {
    if (!DEMAND_STATE.chart) return;
    if (DEMAND_STATE.chartView === 'airport') {
        renderAirportBreakdownChart(data);
    } else if (DEMAND_STATE.chartView === 'status') {
        renderFacilityStatusChart(data);
    } else {
        // Breakdown views — need summary data
        if (!DEMAND_STATE.summaryLoaded || !isCacheValid()) {
            loadFacilitySummary(true); // true = render after loading
        } else {
            // Use the same switch dispatch as airport mode in loadFlightSummary()
            switch (DEMAND_STATE.chartView) {
                case 'origin': renderOriginChart(); break;
                case 'dest': renderDestChart(); break;
                case 'carrier': renderCarrierChart(); break;
                case 'weight': renderWeightChart(); break;
                case 'equipment': renderEquipmentChart(); break;
                case 'rule': renderRuleChart(); break;
                case 'dep_fix': renderDepFixChart(); break;
                case 'arr_fix': renderArrFixChart(); break;
                case 'dp': renderDPChart(); break;
                case 'star': renderSTARChart(); break;
            }
        }
    }
}
```

**All 10 breakdown views are enabled for facility mode.** The existing `renderOriginChart()`, `renderCarrierChart()`, etc. read from `DEMAND_STATE.*Breakdown` properties — they work as-is once the data is populated by `loadFacilitySummary()`.

**Note on dep_fix/arr_fix/dp/star breakdowns**: For facility mode, these aggregate across all airports within the facility rather than a single airport. This is semantically meaningful — e.g., "What STARs are arriving flights using at airports within ZDC?" is a valid operational question. The data is correct; the interpretation is just broader.

### 4. Frontend: `loadFacilitySummary()` (NEW)

```javascript
function loadFacilitySummary(renderAfter) {
    const params = new URLSearchParams({
        type: DEMAND_STATE.demandType,
        code: DEMAND_STATE.facilityCode,
        mode: DEMAND_STATE.facilityMode,
        direction: DEMAND_STATE.direction,
        granularity: getGranularityMinutes(),  // Returns integer (15, 30, 60)
        start: DEMAND_STATE.currentStart,
        end: DEMAND_STATE.currentEnd,
    });

    const headers = {};
    if (DEMAND_STATE.summaryDataHash) {
        headers['X-If-Data-Hash'] = DEMAND_STATE.summaryDataHash;
    }

    $.ajax({
        url: `api/demand/facility_summary.php?${params}`,
        dataType: 'json',
        headers: headers
    }).done(function(response) {
        if (response.unchanged) {
            DEMAND_STATE.summaryLoaded = true;
            DEMAND_STATE.cacheTimestamp = Date.now();
            if (renderAfter && DEMAND_STATE.chartView !== 'status') {
                // Re-render current breakdown view from cache
                renderFacilityChart(DEMAND_STATE.lastFacilityData);
            }
            return;
        }
        if (response.success) {
            // Update sidebar panels
            updateTopOrigins(response.top_origins || []);
            updateTopCarriers(response.top_carriers || []);

            // Store all breakdown data — same DEMAND_STATE properties as airport mode
            DEMAND_STATE.originBreakdown = response.origin_artcc_breakdown || {};
            DEMAND_STATE.destBreakdown = response.dest_artcc_breakdown || {};
            DEMAND_STATE.carrierBreakdown = response.carrier_breakdown || {};
            DEMAND_STATE.weightBreakdown = response.weight_breakdown || {};
            DEMAND_STATE.equipmentBreakdown = response.equipment_breakdown || {};
            DEMAND_STATE.ruleBreakdown = response.rule_breakdown || {};
            DEMAND_STATE.depFixBreakdown = response.dep_fix_breakdown || {};
            DEMAND_STATE.arrFixBreakdown = response.arr_fix_breakdown || {};
            DEMAND_STATE.dpBreakdown = normalizeBreakdownByProcedure(response.dp_breakdown || {}, 'dp');
            DEMAND_STATE.starBreakdown = normalizeBreakdownByProcedure(response.star_breakdown || {}, 'star');

            DEMAND_STATE.summaryLoaded = true;
            DEMAND_STATE.summaryDataHash = response.data_hash;
            DEMAND_STATE.cacheTimestamp = Date.now();

            if (renderAfter && DEMAND_STATE.chartView !== 'status') {
                renderFacilityChart(DEMAND_STATE.lastFacilityData);
            }
        }
    });
}
```

### 5. Frontend: Info Bar — `updateFacilityInfoBar()`

Compute phase breakdown client-side from the facility data (which already contains per-bin `breakdown` objects). Use the same formula as `updateInfoBarStats()`:

- **Active** = departed + enroute + descending (airborne flights)
- **Scheduled** = taxiing (at origin, ready to depart)
- **Proposed** = prefile (filed but not yet taxiing)

```javascript
// In updateFacilityInfoBar(data):
const arrivals = data.data.arrivals || [];
const departures = data.data.departures || [];

let arrActive = 0, arrScheduled = 0, arrProposed = 0;
arrivals.forEach(d => {
    const b = d.breakdown || {};
    arrActive += (b.departed || 0) + (b.enroute || 0) + (b.descending || 0);
    arrScheduled += b.taxiing || 0;
    arrProposed += b.prefile || 0;
});

// Same for departures...
$('#demand_arr_active').text(arrActive);
$('#demand_arr_scheduled').text(arrScheduled);
$('#demand_arr_proposed').text(arrProposed);
```

This removes the need for any backend changes to facility.php — the phase data is already in the response.

Continue hiding config card and ATIS card (N/A for facilities).

### 6. Frontend: Drill-Down — `showFacilityFlightDetails()`

Same SweetAlert2 modal pattern as `showFlightDetails()`:
- Adjust timestamp back to bin start (subtract half interval, same logic)
- Build params with facility-scoped parameters:
  ```javascript
  const params = new URLSearchParams({
      type: DEMAND_STATE.demandType,
      code: DEMAND_STATE.facilityCode,
      mode: DEMAND_STATE.facilityMode,
      time_bin: actualTimeBin,
      direction: DEMAND_STATE.direction,
      granularity: getGranularityMinutes(),
  });
  ```
- Call `api/demand/facility_summary.php?${params}`
- Render with existing `buildFlightListHtml()`
- Attached as click handler on facility status chart and all breakdown charts

### Excluded by Design

| Feature | Reason |
|---------|--------|
| Rate lines (AAR/ADR) | Airport-specific metric; no equivalent for TRACON/ARTCC |
| ATIS card | Airport-specific |
| Config card | Runway config is airport-specific |
| TMI program markers | Target specific airports; aggregation adds complexity without clear UX value |
| Scheduled configs | Airport-specific rate schedule |
| Backend phase totals | Client already has phase data per bin; compute client-side |

### File Changes

| File | Type | Description |
|------|------|-------------|
| `api/demand/facility_summary.php` | NEW | Facility breakdown + drill-down endpoint (~500 lines) |
| `assets/js/demand.js` | MODIFY | Time axis upgrade, view wiring, loadFacilitySummary, drill-down, info bar (~200 lines modified, ~150 new) |

### Testing

Manual testing via live site with:
- **KDCA** (airport) — regression check, ensure no changes to airport mode
- **PCT** (TRACON) — verify all 12 views, drill-down, info bar phase breakdown
- **ZDC** (ARTCC) — verify all 12 views, drill-down, info bar phase breakdown
- **Crossing mode** — verify status chart works with time axis, verify drill-down
- **Edge cases** — empty facility (no flights), direction filtering, granularity changes
