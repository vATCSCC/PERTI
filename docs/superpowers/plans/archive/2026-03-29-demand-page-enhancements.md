# Demand Page Enhancements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add TMI visibility toggles, enhanced multi-filters, richer aggregate stats, and multi-airport comparison mode to the demand visualization page.

**Architecture:** Extend the existing `demand.js` monolith and `demand.php` template. Leverage `DemandChartCore` for multi-chart instantiation in comparison mode. All filtering is client-side using existing `summary.php` breakdown data — no new API endpoints or database changes needed.

**Tech Stack:** PHP 8.2 (template), Vanilla JS + jQuery 2.2.4, ECharts 5.4.3, Select2 4.1.0-rc.0 (already loaded via CDN), Bootstrap 4.5, i18n via `PERTII18n.t()`

**Spec:** `docs/superpowers/specs/2026-03-29-demand-page-enhancements-design.md`

---

## File Structure

### Files Modified

| File | Responsibility | Features |
|------|---------------|----------|
| `demand.php` (1,382 lines) | Page template — sidebar filters, legend card, chart area, flight summary HTML | 1,2,3,4 |
| `assets/js/demand.js` (~6,500 lines) | Chart logic, state management, data fetching, rendering | 1,2,3,4 |
| `assets/locales/en-US.json` (9,131 lines) | Primary English locale — `demand.*` keys at L686-1058 | 1,2,3,4 |
| `assets/locales/fr-CA.json` | French Canadian locale — mirrors en-US structure | 1,2,3,4 |
| `assets/locales/en-CA.json` | Canadian English overlay — ARTCC→FIR terminology | 2,4 |
| `assets/locales/en-EU.json` | European English overlay — ARTCC→ACC terminology | 2,4 |

### No New Files

All changes are modifications to existing files. No new JS modules, CSS files, or API endpoints.

### Key Code Landmarks in demand.js

| Location | What | Notes |
|----------|------|-------|
| L66-823 | `DemandChartCore` IIFE | Reusable chart factory, multi-instance safe |
| L826-831 | `window.DemandChart` alias | |
| L838-910 | `DEMAND_STATE` initialization | 57 top-level properties |
| L2783-2851 | `readUrlState()` / `writeUrlState()` | URL hash persistence |
| L2930-3085 | `loadDemandData()` | Main data fetch orchestrator |
| L3538-3563 | markLine attachment to `series[0]` | Where rate + NOW lines get added |
| L5029-5145 | Page-level `buildRateMarkLinesForChart()` | 0 params, reads DEMAND_STATE |
| L5152-5400+ | `buildTimeBoundedRateMarkLines()` | Scheduled config stair-step lines |
| L5580-5786 | `renderTmiTimeline()` | DOM-based horizontal bar |
| L6444-6538 | `loadFlightSummary()` | Summary data fetch + rendering |

### Key Code Landmarks in demand.php

| Location | What |
|----------|------|
| L853-1131 | Left sidebar (filters + legend card) |
| L1095-1129 | Rate Lines toggle section in legend card |
| L1134-1278 | Right content area (chart + flight summary) |
| L1196-1231 | Chart container + TMI timeline + loading overlay |
| L1234-1277 | Flight summary card (old 2-column layout) |

---

## Implementation Order

1. **Feature 1 — TMI Toggles** (Tasks 1-3): Self-contained, establishes markLine patterns
2. **Feature 2 — Enhanced Filters** (Tasks 4-7): Changes data flow (eager summary), foundation for Feature 3
3. **Feature 3 — Enhanced Stats** (Tasks 8-10): Depends on eager summary from Feature 2
4. **Feature 4 — Comparison Mode** (Tasks 11-16): Largest feature, depends on all others

---

## Feature 1: TMI Visibility Toggles

### Task 1: Add TMI Toggle HTML + State Properties + i18n Keys

**Files:**
- Modify: `demand.php:1095-1129` (legend card, after Rate Lines section)
- Modify: `assets/js/demand.js:838-910` (DEMAND_STATE)
- Modify: `assets/locales/en-US.json:1046-1058` (add new keys before closing `}`)
- Modify: `assets/locales/fr-CA.json` (matching keys)

- [ ] **Step 1: Add i18n keys to en-US.json**

In `assets/locales/en-US.json`, find the `demand.tmiMarker` block (around L1046) and add a new `tmiToggles` block after `tmiMarker`:

```json
    "tmiToggles": {
      "overlays": "TMI Overlays",
      "timeline": "TMI Timeline",
      "markers": "GS/GDP Markers"
    },
```

Insert this between the `tmiMarker` closing `}` and the `tmiTimeline` block.

- [ ] **Step 2: Add matching fr-CA.json keys**

In `assets/locales/fr-CA.json`, find the same location and add:

```json
    "tmiToggles": {
      "overlays": "Superpositions TMI",
      "timeline": "Chronologie TMI",
      "markers": "Marqueurs GS/GDP"
    },
```

- [ ] **Step 3: Add state properties to DEMAND_STATE**

In `assets/js/demand.js`, find the `showRwAdr: true,` line (L879) and add after it:

```javascript
    // TMI overlay visibility toggles
    showTmiTimeline: true,    // DOM timeline bar above chart
    showTmiMarkers: true,     // GS/GDP vertical markLines on chart
```

- [ ] **Step 4: Add TMI Overlays checkbox group to legend card**

In `demand.php`, find the closing `</div>` of the Rate Lines legend-group (after the RW ADR checkbox label, around L1128-1129). Add the following AFTER the Rate Lines `</div>` and BEFORE the legend card's closing `</div>`:

```php
                    <hr class="my-2">
                    <!-- TMI Overlay Toggles -->
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-layer-group mr-1"></i> <?= __('demand.tmiToggles.overlays') ?>
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                <input type="checkbox" id="tmi_toggle_timeline" checked style="margin-right: 4px;">
                                <span style="display: inline-block; width: 16px; height: 4px; background: #ffc107; margin-right: 4px; vertical-align: middle; border-radius: 1px;"></span>
                                <?= __('demand.tmiToggles.timeline') ?>
                            </label>
                            <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                <input type="checkbox" id="tmi_toggle_markers" checked style="margin-right: 4px;">
                                <span style="display: inline-block; width: 0; height: 12px; border-left: 2px solid #dc3545; margin-right: 4px; vertical-align: middle;"></span>
                                <?= __('demand.tmiToggles.markers') ?>
                            </label>
                        </div>
                    </div>
```

- [ ] **Step 5: Verify manually**

Load `demand.php` in browser. Confirm:
- Two new checkboxes appear in the Legend card below Rate Lines
- Both are checked by default
- Styling matches the rate lines section (same font-size, gaps, indicators)

- [ ] **Step 6: Commit**

```bash
git add demand.php assets/js/demand.js assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(demand): add TMI overlay toggle HTML, state props, and i18n keys"
```

---

### Task 2: TMI Toggle Event Handlers + Timeline Visibility

**Files:**
- Modify: `assets/js/demand.js` (event handlers near existing rate checkbox handlers)

- [ ] **Step 1: Find existing rate checkbox handlers**

In `demand.js`, search for `#rate_vatsim_aar` to find the rate toggle event handlers. They should be in the `$(document).ready()` or initialization section. The pattern uses `$('#rate_vatsim_aar').on('change', ...)`.

- [ ] **Step 2: Add TMI toggle event handlers after the rate checkbox handlers**

Add immediately after the last rate checkbox handler block:

```javascript
    // TMI overlay toggle handlers
    $('#tmi_toggle_timeline').on('change', function() {
        DEMAND_STATE.showTmiTimeline = this.checked;
        const $timeline = $('#demand_tmi_timeline');
        if (this.checked && DEMAND_STATE.tmiPrograms && DEMAND_STATE.tmiPrograms.length > 0) {
            $timeline.show();
        } else {
            $timeline.hide();
        }
    });

    $('#tmi_toggle_markers').on('change', function() {
        DEMAND_STATE.showTmiMarkers = this.checked;
        // Re-render chart to add/remove TMI marker lines
        if (DEMAND_STATE.lastDemandData) {
            if (DEMAND_STATE.chartView === 'status') {
                renderChart(DEMAND_STATE.lastDemandData);
            } else {
                renderBreakdownChart(DEMAND_STATE.chartView);
            }
        }
    });
```

- [ ] **Step 3: Guard renderTmiTimeline() with toggle check**

In `renderTmiTimeline()` (L5586), find the line:

```javascript
    const programs = DEMAND_STATE.tmiPrograms;
```

Add BEFORE it:

```javascript
    // Check toggle state
    if (!DEMAND_STATE.showTmiTimeline) {
        container.style.display = 'none';
        return;
    }
```

- [ ] **Step 4: Verify manually**

Load demand page, select an airport with TMI programs:
- Uncheck "TMI Timeline" → horizontal bar hides immediately
- Re-check → bar reappears
- Uncheck "GS/GDP Markers" → nothing yet (markers not built until Task 3)

- [ ] **Step 5: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): wire TMI toggle event handlers and timeline visibility"
```

---

### Task 3: Build TMI Marker MarkLines in buildRateMarkLinesForChart()

**Files:**
- Modify: `assets/js/demand.js:5029-5145` (page-level `buildRateMarkLinesForChart()`)

- [ ] **Step 1: Add TMI marker builder function**

Add this new function BEFORE `buildRateMarkLinesForChart()` (before L5029):

```javascript
/**
 * Build TMI GS/GDP vertical marker lines from tmiPrograms data.
 * Returns array of markLine data objects for ECharts xAxis markers.
 */
function buildTmiMarkerLines() {
    if (!DEMAND_STATE.showTmiMarkers || !DEMAND_STATE.tmiPrograms) {
        return [];
    }

    const programs = DEMAND_STATE.tmiPrograms;
    const lines = [];

    // TMI marker style definitions
    const TMI_MARKER_STYLES = {
        gs_start:  { color: '#dc3545', width: 2, type: 'solid', label: 'GS' },
        gs_end:    { color: '#dc3545', width: 2, type: 'solid', label: 'GS END' },
        gdp_start: { color: '#d4a574', width: 2, type: 'solid', label: 'GDP' },
        gdp_end:   { color: '#d4a574', width: 2, type: 'solid', label: 'GDP END' },
        cancelled: { color: '#6c757d', width: 2, type: [4, 4], label: 'CNX' },
        updated:   { color: '#495057', width: 1, type: [2, 3], label: 'UPD' },
    };

    programs.forEach(p => {
        const pType = (p.program_type || '').toUpperCase();
        const isGS = pType === 'GS';
        const isGDP = pType.startsWith('GDP');
        if (!isGS && !isGDP) return;

        const prefix = isGS ? 'gs' : 'gdp';

        // Start line
        if (p.start_utc) {
            const style = TMI_MARKER_STYLES[prefix + '_start'];
            lines.push({
                xAxis: new Date(p.start_utc).getTime(),
                lineStyle: { color: style.color, width: style.width, type: style.type },
                label: {
                    show: true,
                    formatter: style.label,
                    position: 'start',
                    fontSize: 9,
                    fontWeight: 'bold',
                    color: '#fff',
                    backgroundColor: style.color,
                    padding: [1, 4],
                    borderRadius: 2,
                    distance: 5,
                    offset: [0, 0],
                },
                _tmiMarker: true,
            });
        }

        // End/cancel line
        const endTime = p.purged_at || p.end_utc;
        if (endTime) {
            const isCancelled = !!p.purged_at && p.status === 'cancelled';
            const styleKey = isCancelled ? 'cancelled' : (prefix + '_end');
            const style = TMI_MARKER_STYLES[styleKey];
            lines.push({
                xAxis: new Date(endTime).getTime(),
                lineStyle: { color: style.color, width: style.width, type: style.type },
                label: {
                    show: true,
                    formatter: style.label,
                    position: 'start',
                    fontSize: 9,
                    fontWeight: 'bold',
                    color: '#fff',
                    backgroundColor: style.color,
                    padding: [1, 4],
                    borderRadius: 2,
                    distance: 5,
                    offset: [0, 0],
                },
                _tmiMarker: true,
            });
        }

        // Updated marker
        if (p.was_updated && p.updated_at) {
            const style = TMI_MARKER_STYLES.updated;
            lines.push({
                xAxis: new Date(p.updated_at).getTime(),
                lineStyle: { color: style.color, width: style.width, type: style.type },
                label: {
                    show: true,
                    formatter: style.label,
                    position: 'start',
                    fontSize: 8,
                    fontWeight: 'normal',
                    color: '#fff',
                    backgroundColor: style.color,
                    padding: [1, 3],
                    borderRadius: 2,
                    distance: 5,
                    offset: [0, 0],
                },
                _tmiMarker: true,
            });
        }
    });

    return lines;
}
```

- [ ] **Step 2: Integrate TMI markers into the markLine assembly**

In `renderChart()`, find the markLine assembly block (around L3538-3563). The current code is:

```javascript
    const timeMarkLineData = getCurrentTimeMarkLineForTimeAxis();
    const rateMarkLines = (DEMAND_STATE.scheduledConfigs && DEMAND_STATE.scheduledConfigs.length > 0)
        ? buildTimeBoundedRateMarkLines()
        : buildRateMarkLinesForChart();

    if (series.length > 0) {
        const markLineData = [];
        if (timeMarkLineData) {
            markLineData.push(timeMarkLineData);
        }
        if (rateMarkLines && rateMarkLines.length > 0) {
            markLineData.push(...rateMarkLines);
        }
```

Add TMI markers right after the rate lines block:

```javascript
        // Add TMI GS/GDP vertical markers
        const tmiMarkers = buildTmiMarkerLines();
        if (tmiMarkers && tmiMarkers.length > 0) {
            markLineData.push(...tmiMarkers);
        }
```

- [ ] **Step 3: Add label collision avoidance for all vertical markLines**

After the TMI markers are added (and before the `if (markLineData.length > 0)` check), add collision detection:

```javascript
        // Label collision avoidance: stagger labels for nearby vertical markers
        // Only applies to xAxis markers (TMI markers), not yAxis (rate lines)
        const verticalMarkers = markLineData.filter(m => m.xAxis !== undefined && m._tmiMarker);
        if (verticalMarkers.length > 1) {
            // Sort by x position
            verticalMarkers.sort((a, b) => a.xAxis - b.xAxis);
            // Group markers within 30-minute proximity
            const PROXIMITY_MS = 30 * 60 * 1000;
            let groupStart = 0;
            for (let i = 1; i <= verticalMarkers.length; i++) {
                const inGroup = i < verticalMarkers.length &&
                    (verticalMarkers[i].xAxis - verticalMarkers[groupStart].xAxis) < PROXIMITY_MS;
                if (!inGroup) {
                    // Process group from groupStart to i-1
                    const groupSize = i - groupStart;
                    if (groupSize > 1) {
                        for (let j = groupStart; j < i; j++) {
                            const idx = j - groupStart;
                            verticalMarkers[j].label.offset = [0, idx * -18];
                        }
                    }
                    groupStart = i;
                }
            }
        }
```

- [ ] **Step 4: Verify manually**

Load demand page, select an airport with active GS/GDP programs:
- Vertical lines should appear at program start/end times
- GS lines are red solid, GDP lines are amber solid
- Labels show "GS", "GS END", "GDP", "GDP END" with colored background badges
- When markers are close together, labels are vertically staggered (no overlap)
- Uncheck "GS/GDP Markers" toggle → vertical lines disappear, chart re-renders
- Re-check → lines reappear

- [ ] **Step 5: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): build TMI GS/GDP vertical marker lines with collision avoidance"
```

---

## Feature 2: Enhanced Filters

### Task 4: Add Filter HTML + State Properties + i18n Keys

**Files:**
- Modify: `demand.php:966-985` (between Direction toggle and `<hr>` before phase filters)
- Modify: `assets/js/demand.js:838-910` (DEMAND_STATE)
- Modify: `assets/locales/en-US.json`, `fr-CA.json`, `en-CA.json`, `en-EU.json`

- [ ] **Step 1: Add i18n keys to en-US.json**

In `assets/locales/en-US.json`, find the `demand.page` object. Add these keys inside it (alphabetically or grouped logically near the existing filter labels):

```json
      "allCarriers": "All Carriers",
      "allEquipment": "All Equipment",
      "allWeightClasses": "All Weight Classes",
      "carrierFilter": "Carrier",
      "destArtccFilter": "Dest ARTCC/FIR",
      "equipmentFilter": "Equipment",
      "originArtccFilter": "Origin ARTCC/FIR",
      "resetFilters": "Reset Filters",
      "weightClassFilter": "Weight Class",
```

- [ ] **Step 2: Add matching fr-CA.json keys**

```json
      "allCarriers": "Tous les transporteurs",
      "allEquipment": "Tous les equipements",
      "allWeightClasses": "Toutes les classes de poids",
      "carrierFilter": "Transporteur",
      "destArtccFilter": "ARTCC/FIR de destination",
      "equipmentFilter": "Equipement",
      "originArtccFilter": "ARTCC/FIR d'origine",
      "resetFilters": "Reinitialiser les filtres",
      "weightClassFilter": "Classe de poids",
```

- [ ] **Step 3: Add en-CA.json overlay keys**

In `assets/locales/en-CA.json`, add inside the `demand.page` object:

```json
      "originArtccFilter": "Origin FIR",
      "destArtccFilter": "Dest FIR"
```

- [ ] **Step 4: Add en-EU.json overlay keys**

In `assets/locales/en-EU.json`, add inside the `demand.page` object:

```json
      "originArtccFilter": "Origin ACC",
      "destArtccFilter": "Dest ACC"
```

- [ ] **Step 5: Add filter state properties to DEMAND_STATE**

In `assets/js/demand.js`, find `showTmiMarkers: true,` (added in Task 1) and add after it:

```javascript
    // Enhanced filter state (Feature 2)
    filterCarriers: [],        // Array of carrier codes, empty = all
    filterWeightClasses: [],   // Array of weight class letters, empty = all
    filterEquipment: [],       // Array of equipment type codes, empty = all
    filterOriginArtccs: [],    // Array of origin ARTCC codes, empty = all
    filterDestArtccs: [],      // Array of dest ARTCC codes, empty = all
    summaryData: null,         // Store raw summary.php response for filter population
```

- [ ] **Step 6: Add filter HTML to demand.php sidebar**

In `demand.php`, find the `<hr class="my-2">` before the phase filter section (L985). Add this new filter block BEFORE that `<hr>`:

```php
                    <hr class="my-2">

                    <!-- Enhanced Filters (Feature 2) -->
                    <div id="enhanced_filters_section">
                        <!-- Carrier Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.carrierFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_carrier" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Weight Class Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.weightClassFilter') ?></label>
                            <div class="d-flex flex-wrap" style="gap: 4px 10px;">
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="H" checked style="margin-right: 3px;">
                                    <span style="background:#dc2626;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> H
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="L" checked style="margin-right: 3px;">
                                    <span style="background:#3b82f6;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> L
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="S" checked style="margin-right: 3px;">
                                    <span style="background:#22c55e;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> S
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="+" checked style="margin-right: 3px;">
                                    <span style="background:#9333ea;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> +
                                </label>
                            </div>
                        </div>

                        <!-- Equipment Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.equipmentFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_equipment" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Origin ARTCC Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.originArtccFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_origin_artcc" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Dest ARTCC Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.destArtccFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_dest_artcc" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Reset Filters Link -->
                        <div class="text-center" id="reset_filters_container" style="display: none;">
                            <a href="#" id="reset_filters_link" class="small text-danger">
                                <i class="fas fa-times-circle mr-1"></i><?= __('demand.page.resetFilters') ?>
                            </a>
                        </div>
                    </div>
```

- [ ] **Step 7: Verify manually**

Load demand page. Confirm:
- New filter section appears between Direction toggle and Flight Status
- Select2 dropdowns are empty (will be populated after data loads in Task 6)
- Weight class checkboxes show colored dots
- Reset Filters link is hidden

- [ ] **Step 8: Commit**

```bash
git add demand.php assets/js/demand.js assets/locales/en-US.json assets/locales/fr-CA.json assets/locales/en-CA.json assets/locales/en-EU.json
git commit -m "feat(demand): add enhanced filter HTML, state properties, and i18n keys"
```

---

### Task 5: Move Summary Loading Into Promise.allSettled

**Files:**
- Modify: `assets/js/demand.js:2930-3085` (loadDemandData function)
- Modify: `assets/js/demand.js:6444-6538` (loadFlightSummary function)

- [ ] **Step 1: Add summary promise to the parallel fetch block**

In `loadDemandData()`, find the line where `tmiProgramsPromise` is defined (L2952). Add after it:

```javascript
    const summaryParams = new URLSearchParams({
        airport: airport,
        start: start.toISOString(),
        end: end.toISOString(),
        direction: DEMAND_STATE.direction,
        granularity: getGranularityMinutes(),
    });
    const summaryHeaders = {};
    if (DEMAND_STATE.summaryDataHash) {
        summaryHeaders['X-If-Data-Hash'] = DEMAND_STATE.summaryDataHash;
    }
    const summaryPromise = $.ajax({
        url: `api/demand/summary.php?${summaryParams.toString()}`,
        dataType: 'json',
        headers: summaryHeaders
    });
```

- [ ] **Step 2: Add summary to the Promise.allSettled array**

Change the Promise.allSettled call (L2954) from:

```javascript
    Promise.allSettled([demandPromise, ratesPromise, atisPromise, tmiConfigPromise, scheduledConfigsPromise, tmiProgramsPromise])
        .then(function(results) {
            const [demandResult, ratesResult, atisResult, tmiConfigResult, scheduledConfigsResult, tmiProgramsResult] = results;
```

To:

```javascript
    Promise.allSettled([demandPromise, ratesPromise, atisPromise, tmiConfigPromise, scheduledConfigsPromise, tmiProgramsPromise, summaryPromise])
        .then(function(results) {
            const [demandResult, ratesResult, atisResult, tmiConfigResult, scheduledConfigsResult, tmiProgramsResult, summaryResult] = results;
```

- [ ] **Step 3: Handle summary result in the then() block**

Find the TMI programs handler block (around L3047-3057). Add AFTER it (before the `// Render chart` comment):

```javascript
            // Handle summary data (parallel-loaded for filter population)
            if (summaryResult.status === 'fulfilled' && summaryResult.value) {
                const summaryResponse = summaryResult.value;
                if (summaryResponse.unchanged) {
                    DEMAND_STATE.summaryLoaded = true;
                } else if (summaryResponse.success) {
                    DEMAND_STATE.summaryData = summaryResponse;
                    DEMAND_STATE.originBreakdown = summaryResponse.origin_artcc_breakdown || {};
                    DEMAND_STATE.destBreakdown = summaryResponse.dest_artcc_breakdown || {};
                    DEMAND_STATE.weightBreakdown = summaryResponse.weight_breakdown || {};
                    DEMAND_STATE.carrierBreakdown = summaryResponse.carrier_breakdown || {};
                    DEMAND_STATE.equipmentBreakdown = summaryResponse.equipment_breakdown || {};
                    DEMAND_STATE.ruleBreakdown = summaryResponse.rule_breakdown || {};
                    DEMAND_STATE.depFixBreakdown = summaryResponse.dep_fix_breakdown || {};
                    DEMAND_STATE.arrFixBreakdown = summaryResponse.arr_fix_breakdown || {};
                    DEMAND_STATE.dpBreakdown = normalizeBreakdownByProcedure(summaryResponse.dp_breakdown || {}, 'dp');
                    DEMAND_STATE.starBreakdown = normalizeBreakdownByProcedure(summaryResponse.star_breakdown || {}, 'star');
                    DEMAND_STATE.summaryLoaded = true;
                    DEMAND_STATE.summaryDataHash = summaryResponse.data_hash || null;
                    updateTopOrigins(summaryResponse.top_origins || []);
                    updateTopCarriers(summaryResponse.top_carriers || []);
                    populateFilterDropdowns(summaryResponse);
                }
            }
```

Note: `populateFilterDropdowns()` will be implemented in Task 6.

- [ ] **Step 4: Update the render block to remove duplicate summary loading**

In the render block (around L3060-3069), change:

```javascript
            if (!demandUnchanged) {
                if (DEMAND_STATE.chartView === 'status') {
                    renderChart(demandResponse);
                    loadFlightSummary(false);
                } else {
                    loadFlightSummary(true);
                }
```

To:

```javascript
            if (!demandUnchanged) {
                if (DEMAND_STATE.chartView === 'status') {
                    renderChart(demandResponse);
                } else {
                    // Breakdown views need summary data — if already loaded from parallel fetch, render directly
                    if (DEMAND_STATE.summaryLoaded) {
                        renderBreakdownChart(DEMAND_STATE.chartView);
                    } else {
                        // Fallback: summary fetch still pending/failed, load sequentially
                        loadFlightSummary(true);
                    }
                }
```

- [ ] **Step 5: Add stub for populateFilterDropdowns()**

Add this function stub near `loadFlightSummary()` (around L6538):

```javascript
/**
 * Populate enhanced filter dropdowns from summary data.
 * Called after summary.php response is received.
 * @param {Object} summaryResponse - Raw summary.php API response
 */
function populateFilterDropdowns(summaryResponse) {
    // Will be implemented in Task 6
    console.log('[Demand] populateFilterDropdowns stub called');
}
```

- [ ] **Step 6: Verify manually**

Load demand page, open browser DevTools Console:
- Confirm no errors
- Confirm summary data still loads (check `[Demand] Summary API response` log)
- Confirm chart still renders correctly for both status and breakdown views
- Confirm `[Demand] populateFilterDropdowns stub called` appears in console

- [ ] **Step 7: Commit**

```bash
git add assets/js/demand.js
git commit -m "refactor(demand): move summary.php into parallel Promise.allSettled fetch"
```

---

### Task 6: Select2 Initialization + Filter Change Handlers + applyClientFilters

**Files:**
- Modify: `assets/js/demand.js` (multiple locations)

- [ ] **Step 1: Implement populateFilterDropdowns()**

Replace the stub from Task 5 with the full implementation:

```javascript
/**
 * Populate enhanced filter dropdowns from summary data.
 * Extracts unique values from breakdown data across all time bins.
 * @param {Object} resp - Raw summary.php API response
 */
function populateFilterDropdowns(resp) {
    // Extract unique carriers from carrier_breakdown
    const carriers = new Set();
    if (resp.carrier_breakdown) {
        Object.values(resp.carrier_breakdown).forEach(bin => {
            if (bin && typeof bin === 'object') {
                Object.keys(bin).forEach(k => carriers.add(k));
            }
        });
    }

    // Extract unique equipment from equipment_breakdown
    const equipment = new Set();
    if (resp.equipment_breakdown) {
        Object.values(resp.equipment_breakdown).forEach(bin => {
            if (bin && typeof bin === 'object') {
                Object.keys(bin).forEach(k => equipment.add(k));
            }
        });
    }

    // Extract unique origin ARTCCs
    const originArtccs = new Set();
    if (resp.origin_artcc_breakdown) {
        Object.values(resp.origin_artcc_breakdown).forEach(bin => {
            if (bin && typeof bin === 'object') {
                Object.keys(bin).forEach(k => originArtccs.add(k));
            }
        });
    }

    // Extract unique dest ARTCCs
    const destArtccs = new Set();
    if (resp.dest_artcc_breakdown) {
        Object.values(resp.dest_artcc_breakdown).forEach(bin => {
            if (bin && typeof bin === 'object') {
                Object.keys(bin).forEach(k => destArtccs.add(k));
            }
        });
    }

    // Populate carrier Select2
    const $carrier = $('#filter_carrier');
    const currentCarriers = $carrier.val() || [];
    $carrier.empty();
    [...carriers].sort().forEach(c => {
        $carrier.append(new Option(c, c, false, currentCarriers.includes(c)));
    });
    $carrier.trigger('change.select2');

    // Populate equipment Select2
    const $equip = $('#filter_equipment');
    const currentEquip = $equip.val() || [];
    $equip.empty();
    [...equipment].sort().forEach(e => {
        $equip.append(new Option(e, e, false, currentEquip.includes(e)));
    });
    $equip.trigger('change.select2');

    // Populate origin ARTCC Select2
    const $origin = $('#filter_origin_artcc');
    const currentOrigin = $origin.val() || [];
    $origin.empty();
    [...originArtccs].sort().forEach(a => {
        $origin.append(new Option(a, a, false, currentOrigin.includes(a)));
    });
    $origin.trigger('change.select2');

    // Populate dest ARTCC Select2
    const $dest = $('#filter_dest_artcc');
    const currentDest = $dest.val() || [];
    $dest.empty();
    [...destArtccs].sort().forEach(a => {
        $dest.append(new Option(a, a, false, currentDest.includes(a)));
    });
    $dest.trigger('change.select2');
}
```

- [ ] **Step 2: Initialize Select2 and bind change handlers**

Find the `$(document).ready()` or main initialization block where event handlers are set up. Add:

```javascript
    // Initialize enhanced filter Select2 dropdowns
    $('#filter_carrier').select2({
        placeholder: PERTII18n.t('demand.page.allCarriers'),
        allowClear: true,
        width: '100%',
        theme: 'default',
    }).on('change', function() {
        DEMAND_STATE.filterCarriers = $(this).val() || [];
        onEnhancedFilterChange();
    });

    $('#filter_equipment').select2({
        placeholder: PERTII18n.t('demand.page.allEquipment'),
        allowClear: true,
        width: '100%',
        theme: 'default',
    }).on('change', function() {
        DEMAND_STATE.filterEquipment = $(this).val() || [];
        onEnhancedFilterChange();
    });

    $('#filter_origin_artcc').select2({
        placeholder: PERTII18n.t('demand.page.originArtccFilter'),
        allowClear: true,
        width: '100%',
        theme: 'default',
    }).on('change', function() {
        DEMAND_STATE.filterOriginArtccs = $(this).val() || [];
        onEnhancedFilterChange();
    });

    $('#filter_dest_artcc').select2({
        placeholder: PERTII18n.t('demand.page.destArtccFilter'),
        allowClear: true,
        width: '100%',
        theme: 'default',
    }).on('change', function() {
        DEMAND_STATE.filterDestArtccs = $(this).val() || [];
        onEnhancedFilterChange();
    });

    // Weight class checkbox handlers
    $('.weight-class-filter').on('change', function() {
        const checked = [];
        $('.weight-class-filter:checked').each(function() { checked.push($(this).val()); });
        DEMAND_STATE.filterWeightClasses = checked.length === 4 ? [] : checked; // empty = all
        onEnhancedFilterChange();
    });

    // Reset filters link
    $('#reset_filters_link').on('click', function(e) {
        e.preventDefault();
        DEMAND_STATE.filterCarriers = [];
        DEMAND_STATE.filterWeightClasses = [];
        DEMAND_STATE.filterEquipment = [];
        DEMAND_STATE.filterOriginArtccs = [];
        DEMAND_STATE.filterDestArtccs = [];
        $('#filter_carrier').val(null).trigger('change');
        $('#filter_equipment').val(null).trigger('change');
        $('#filter_origin_artcc').val(null).trigger('change');
        $('#filter_dest_artcc').val(null).trigger('change');
        $('.weight-class-filter').prop('checked', true);
        onEnhancedFilterChange();
    });
```

- [ ] **Step 3: Implement onEnhancedFilterChange() and applyClientFilters()**

Add these functions near `populateFilterDropdowns()`:

```javascript
/**
 * Called when any enhanced filter changes. Shows/hides reset link,
 * re-renders chart with filtered data.
 */
function onEnhancedFilterChange() {
    // Show/hide reset link
    const hasActiveFilter =
        DEMAND_STATE.filterCarriers.length > 0 ||
        DEMAND_STATE.filterWeightClasses.length > 0 ||
        DEMAND_STATE.filterEquipment.length > 0 ||
        DEMAND_STATE.filterOriginArtccs.length > 0 ||
        DEMAND_STATE.filterDestArtccs.length > 0;
    $('#reset_filters_container').toggle(hasActiveFilter);

    // Update direction-aware ARTCC filter state
    updateArtccFilterState();

    // Re-render chart with filtered data
    if (DEMAND_STATE.lastDemandData) {
        if (DEMAND_STATE.chartView === 'status') {
            renderChart(DEMAND_STATE.lastDemandData);
        } else {
            renderBreakdownChart(DEMAND_STATE.chartView);
        }
    }

    writeUrlState();
}

/**
 * Direction-aware ARTCC filter: gray out irrelevant filter based on direction.
 */
function updateArtccFilterState() {
    const dir = DEMAND_STATE.direction;
    const $origin = $('#filter_origin_artcc');
    const $dest = $('#filter_dest_artcc');
    // dep-only: origin filter less relevant; arr-only: dest filter less relevant
    $origin.prop('disabled', dir === 'dep');
    $dest.prop('disabled', dir === 'arr');
}

/**
 * Apply client-side filters to demand time-bin data.
 * Uses summary breakdown data to compute filtered counts per bin.
 * Returns a modified copy of the demand data with adjusted phase counts.
 *
 * @param {Object} demandData - Raw API response from airport.php
 * @returns {Object} - Filtered copy with adjusted arrival/departure bin counts
 */
function applyClientFilters(demandData) {
    const hasFilter =
        DEMAND_STATE.filterCarriers.length > 0 ||
        DEMAND_STATE.filterWeightClasses.length > 0 ||
        DEMAND_STATE.filterEquipment.length > 0 ||
        DEMAND_STATE.filterOriginArtccs.length > 0 ||
        DEMAND_STATE.filterDestArtccs.length > 0;

    if (!hasFilter) return demandData;

    // Deep clone to avoid mutating original
    const filtered = JSON.parse(JSON.stringify(demandData));

    // For each time bin, calculate the fraction of flights matching active filters
    // using the breakdown data, then scale the phase counts proportionally.
    const scaleTimeBins = (bins, breakdowns) => {
        if (!bins || !Array.isArray(bins)) return bins;

        return bins.map(bin => {
            const binKey = normalizeTimeBin(bin.time_bin);
            let fraction = 1.0;

            // Apply each active filter dimension independently (multiplicative)
            if (DEMAND_STATE.filterCarriers.length > 0 && breakdowns.carrier) {
                const carrierBin = breakdowns.carrier[binKey] || {};
                const total = Object.values(carrierBin).reduce((s, v) => s + v, 0);
                const matched = DEMAND_STATE.filterCarriers.reduce((s, c) => s + (carrierBin[c] || 0), 0);
                fraction *= total > 0 ? matched / total : 0;
            }

            if (DEMAND_STATE.filterWeightClasses.length > 0 && breakdowns.weight) {
                const weightBin = breakdowns.weight[binKey] || {};
                const total = Object.values(weightBin).reduce((s, v) => s + v, 0);
                const matched = DEMAND_STATE.filterWeightClasses.reduce((s, w) => s + (weightBin[w] || 0), 0);
                fraction *= total > 0 ? matched / total : 0;
            }

            if (DEMAND_STATE.filterEquipment.length > 0 && breakdowns.equipment) {
                const equipBin = breakdowns.equipment[binKey] || {};
                const total = Object.values(equipBin).reduce((s, v) => s + v, 0);
                const matched = DEMAND_STATE.filterEquipment.reduce((s, e) => s + (equipBin[e] || 0), 0);
                fraction *= total > 0 ? matched / total : 0;
            }

            if (DEMAND_STATE.filterOriginArtccs.length > 0 && breakdowns.origin) {
                const originBin = breakdowns.origin[binKey] || {};
                const total = Object.values(originBin).reduce((s, v) => s + v, 0);
                const matched = DEMAND_STATE.filterOriginArtccs.reduce((s, a) => s + (originBin[a] || 0), 0);
                fraction *= total > 0 ? matched / total : 0;
            }

            if (DEMAND_STATE.filterDestArtccs.length > 0 && breakdowns.dest) {
                const destBin = breakdowns.dest[binKey] || {};
                const total = Object.values(destBin).reduce((s, v) => s + v, 0);
                const matched = DEMAND_STATE.filterDestArtccs.reduce((s, a) => s + (destBin[a] || 0), 0);
                fraction *= total > 0 ? matched / total : 0;
            }

            // Scale all phase counts in the breakdown
            if (bin.breakdown && fraction < 1.0) {
                const scaled = {};
                for (const [phase, count] of Object.entries(bin.breakdown)) {
                    scaled[phase] = Math.round(count * fraction);
                }
                bin.breakdown = scaled;
            }

            return bin;
        });
    };

    const breakdowns = {
        carrier: DEMAND_STATE.carrierBreakdown,
        weight: DEMAND_STATE.weightBreakdown,
        equipment: DEMAND_STATE.equipmentBreakdown,
        origin: DEMAND_STATE.originBreakdown,
        dest: DEMAND_STATE.destBreakdown,
    };

    if (filtered.arrivals) {
        filtered.arrivals = scaleTimeBins(filtered.arrivals, breakdowns);
    }
    if (filtered.departures) {
        filtered.departures = scaleTimeBins(filtered.departures, breakdowns);
    }

    return filtered;
}
```

- [ ] **Step 4: Integrate applyClientFilters into renderChart()**

In `renderChart()`, find where it reads arrivals and departures from the data (look for lines like `const arrivals = data.arrivals || []`). Add the filter application BEFORE the series construction:

```javascript
    // Apply client-side filters if any are active
    const filteredData = applyClientFilters(data);
    const arrivals = filteredData.arrivals || [];
    const departures = filteredData.departures || [];
```

Replace the existing `const arrivals = data.arrivals || []` and `const departures = data.departures || []` lines.

- [ ] **Step 5: Verify manually**

Load demand page, select an airport:
- Select2 dropdowns populate with carrier/equipment/ARTCC options after data loads
- Select a carrier → chart re-renders with reduced bar heights
- Check weight class checkboxes → chart updates
- Click "Reset Filters" → all filters clear, chart returns to unfiltered state

- [ ] **Step 6: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): implement client-side filters with Select2 and proportional scaling"
```

---

### Task 7: Filter URL State Persistence + Direction-Aware Behavior

**Files:**
- Modify: `assets/js/demand.js:2783-2851` (readUrlState/writeUrlState)

- [ ] **Step 1: Extend writeUrlState() with filter params**

In `writeUrlState()`, find the line `if (DEMAND_STATE.chartView !== 'status')` (L2846). Add BEFORE `history.replaceState`:

```javascript
    // Enhanced filter state
    if (DEMAND_STATE.filterCarriers.length > 0) {
        params.set('carriers', DEMAND_STATE.filterCarriers.join(','));
    }
    if (DEMAND_STATE.filterWeightClasses.length > 0) {
        params.set('weight', DEMAND_STATE.filterWeightClasses.join(','));
    }
    if (DEMAND_STATE.filterEquipment.length > 0) {
        params.set('equipment', DEMAND_STATE.filterEquipment.join(','));
    }
    if (DEMAND_STATE.filterOriginArtccs.length > 0) {
        params.set('origins', DEMAND_STATE.filterOriginArtccs.join(','));
    }
    if (DEMAND_STATE.filterDestArtccs.length > 0) {
        params.set('dests', DEMAND_STATE.filterDestArtccs.join(','));
    }
```

- [ ] **Step 2: Extend readUrlState() to restore filters**

In `readUrlState()`, add AFTER the existing `view` restoration block (after L2827):

```javascript
    // Restore enhanced filters
    if (params.has('carriers')) {
        DEMAND_STATE.filterCarriers = params.get('carriers').split(',').filter(Boolean);
    }
    if (params.has('weight')) {
        DEMAND_STATE.filterWeightClasses = params.get('weight').split(',').filter(Boolean);
        // Sync weight checkboxes
        $('.weight-class-filter').each(function() {
            $(this).prop('checked', DEMAND_STATE.filterWeightClasses.includes($(this).val()));
        });
    }
    if (params.has('equipment')) {
        DEMAND_STATE.filterEquipment = params.get('equipment').split(',').filter(Boolean);
    }
    if (params.has('origins')) {
        DEMAND_STATE.filterOriginArtccs = params.get('origins').split(',').filter(Boolean);
    }
    if (params.has('dests')) {
        DEMAND_STATE.filterDestArtccs = params.get('dests').split(',').filter(Boolean);
    }
```

- [ ] **Step 3: Restore Select2 values after filter dropdowns populate**

In `populateFilterDropdowns()`, after the dropdown options are populated, add at the end:

```javascript
    // Restore Select2 values from URL state (first load only)
    if (DEMAND_STATE.filterCarriers.length > 0) {
        $('#filter_carrier').val(DEMAND_STATE.filterCarriers).trigger('change.select2');
    }
    if (DEMAND_STATE.filterEquipment.length > 0) {
        $('#filter_equipment').val(DEMAND_STATE.filterEquipment).trigger('change.select2');
    }
    if (DEMAND_STATE.filterOriginArtccs.length > 0) {
        $('#filter_origin_artcc').val(DEMAND_STATE.filterOriginArtccs).trigger('change.select2');
    }
    if (DEMAND_STATE.filterDestArtccs.length > 0) {
        $('#filter_dest_artcc').val(DEMAND_STATE.filterDestArtccs).trigger('change.select2');
    }

    // Show reset link if any filter active
    const hasActiveFilter =
        DEMAND_STATE.filterCarriers.length > 0 ||
        DEMAND_STATE.filterWeightClasses.length > 0 ||
        DEMAND_STATE.filterEquipment.length > 0 ||
        DEMAND_STATE.filterOriginArtccs.length > 0 ||
        DEMAND_STATE.filterDestArtccs.length > 0;
    $('#reset_filters_container').toggle(hasActiveFilter);
```

- [ ] **Step 4: Wire direction change to update ARTCC filter state**

Find the direction toggle handler (search for `demand_direction` change handler). Add `updateArtccFilterState();` inside it after the direction state update.

- [ ] **Step 5: Verify manually**

- Set carrier filter to "AAL" → URL hash updates to include `&carriers=AAL`
- Copy URL, open in new tab → filter is restored, chart shows filtered data
- Change direction to "arr" → dest ARTCC filter is grayed out

- [ ] **Step 6: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): persist enhanced filters in URL hash and add direction-aware behavior"
```

---

## Feature 3: Enhanced Flight Summary — 6-Card Grid

### Task 8: Replace Flight Summary HTML with 6-Card Grid + i18n Keys

**Files:**
- Modify: `demand.php:1234-1277` (replace flight summary card body)
- Modify: `assets/locales/en-US.json`, `fr-CA.json`

- [ ] **Step 1: Add i18n keys to en-US.json**

In `assets/locales/en-US.json`, inside the `demand` object, add a new `summary` sub-object (place it after the `setConfig` block and before `status`):

```json
    "summary": {
      "avgDelay": "Avg Delay",
      "compliance": "Compliance",
      "exceededBy": "AAR exceeded by {count}",
      "exempt": "Exempt",
      "gdpControlled": "GDP Controlled",
      "gsStopped": "GS Stopped",
      "maxDelay": "Max Delay",
      "noData": "No data",
      "peakHour": "Peak Hour",
      "tmiControl": "TMI Control",
      "topArrFixes": "Top Arr Fixes",
      "topCarriers": "Top Carriers",
      "topDepFixes": "Top Dep Fixes",
      "topOrigins": "Top Origins",
      "weightMix": "Weight Mix",
      "withinCapacity": "Within capacity"
    },
```

- [ ] **Step 2: Add matching fr-CA.json keys**

```json
    "summary": {
      "avgDelay": "Retard moy.",
      "compliance": "Conformite",
      "exceededBy": "AAR depasse de {count}",
      "exempt": "Exempte",
      "gdpControlled": "GDP controle",
      "gsStopped": "GS arrete",
      "maxDelay": "Retard max.",
      "noData": "Aucune donnee",
      "peakHour": "Heure de pointe",
      "tmiControl": "Controle TMI",
      "topArrFixes": "Top Fixes ARR",
      "topCarriers": "Top Transporteurs",
      "topDepFixes": "Top Fixes DEP",
      "topOrigins": "Top Origines",
      "weightMix": "Classe de poids",
      "withinCapacity": "Dans la capacite"
    },
```

- [ ] **Step 3: Replace the flight summary card body HTML**

In `demand.php`, replace the ENTIRE flight summary card (L1234-1277) — from `<!-- Flight Summary Card` to its closing `</div>` — with:

```php
            <!-- Enhanced Flight Summary Card — 6-Card Grid -->
            <div class="card shadow-sm mt-3 tbfm-chart-card">
                <div class="card-header tbfm-card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-list mr-1"></i> <?= __('demand.page.flightSummary') ?>
                        <span class="badge badge-light ml-2" id="demand_flight_count" style="color: #2c3e50;">0 <?= __('demand.page.flights') ?></span>
                    </span>
                    <button class="btn btn-sm btn-outline-light" id="demand_toggle_flights" type="button" title="<?= __('demand.page.toggleFlightsTooltip') ?>">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="card-body p-2" id="demand_flight_summary" style="display: none;">
                    <div class="row" id="summary_card_grid">
                        <!-- Card 1: Peak Hour -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-clock mr-1"></i> <?= __('demand.summary.peakHour') ?>
                                </div>
                                <div style="padding: 6px 8px;" id="summary_peak_hour">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 2: TMI Control -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-hand-paper mr-1"></i> <?= __('demand.summary.tmiControl') ?>
                                </div>
                                <div style="padding: 6px 8px;" id="summary_tmi_control">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 3: Weight Mix -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-balance-scale mr-1"></i> <?= __('demand.summary.weightMix') ?>
                                </div>
                                <div style="padding: 6px 8px;" id="summary_weight_mix">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 4: Top Origins -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-map-marker-alt mr-1"></i> <?= __('demand.summary.topOrigins') ?>
                                </div>
                                <div style="padding: 4px 8px;" id="summary_top_origins">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 5: Top Carriers -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-plane mr-1"></i> <?= __('demand.summary.topCarriers') ?>
                                </div>
                                <div style="padding: 4px 8px;" id="summary_top_carriers">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 6: Top Fixes -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-thumbtack mr-1"></i> <span id="summary_fixes_title"><?= __('demand.summary.topArrFixes') ?></span>
                                </div>
                                <div style="padding: 4px 8px;" id="summary_top_fixes">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
```

- [ ] **Step 4: Verify manually**

Load demand page. Confirm:
- 6-card grid layout with 3 columns on desktop
- Each card has dark header with icon + title
- All cards show "--" placeholder (rendering in Task 9)
- Toggle button still works (collapse/expand)

- [ ] **Step 5: Commit**

```bash
git add demand.php assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(demand): replace flight summary with 6-card grid HTML and i18n keys"
```

---

### Task 9: Card Rendering Functions (Peak Hour, TMI Control, Weight Mix)

**Files:**
- Modify: `assets/js/demand.js` (add rendering functions, update call sites)

- [ ] **Step 1: Add renderSummaryCards() orchestrator**

Add this function near `loadFlightSummary()`:

```javascript
/**
 * Render all 6 enhanced summary cards.
 * Called after demand + summary data are loaded.
 */
function renderSummaryCards() {
    renderPeakHourCard();
    renderTmiControlCard();
    renderWeightMixCard();
    renderTopOriginsCard();
    renderTopCarriersCard();
    renderTopFixesCard();

    // Auto-expand summary if any card has data
    const $summary = $('#demand_flight_summary');
    const $icon = $('#demand_toggle_flights i');
    if (!$summary.is(':visible') && DEMAND_STATE.summaryLoaded) {
        $summary.slideDown(200);
        $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
    }
}
```

- [ ] **Step 2: Implement renderPeakHourCard()**

```javascript
/**
 * Render Peak Hour card: finds the time bin with highest total demand.
 */
function renderPeakHourCard() {
    const container = document.getElementById('summary_peak_hour');
    if (!container) return;

    const data = DEMAND_STATE.lastDemandData;
    if (!data) { container.innerHTML = '<span class="text-muted small">--</span>'; return; }

    // Apply client filters
    const filtered = applyClientFilters(data);
    const arrivals = filtered.arrivals || [];
    const departures = filtered.departures || [];
    const direction = DEMAND_STATE.direction;

    // Sum totals per bin
    const binTotals = {};
    const sumBreakdown = (bin) => bin.breakdown ? Object.values(bin.breakdown).reduce((s, v) => s + v, 0) : 0;

    if (direction === 'arr' || direction === 'both') {
        arrivals.forEach(bin => {
            const key = normalizeTimeBin(bin.time_bin);
            binTotals[key] = (binTotals[key] || { arr: 0, dep: 0 });
            binTotals[key].arr = sumBreakdown(bin);
        });
    }
    if (direction === 'dep' || direction === 'both') {
        departures.forEach(bin => {
            const key = normalizeTimeBin(bin.time_bin);
            binTotals[key] = binTotals[key] || { arr: 0, dep: 0 };
            binTotals[key].dep = sumBreakdown(bin);
        });
    }

    // Find peak
    let peakKey = null, peakTotal = 0;
    for (const [key, val] of Object.entries(binTotals)) {
        const total = val.arr + val.dep;
        if (total > peakTotal) { peakTotal = total; peakKey = key; }
    }

    if (!peakKey) { container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>'; return; }

    const peakDate = new Date(peakKey);
    const granMin = getGranularityMinutes();
    const endDate = new Date(peakDate.getTime() + granMin * 60000);
    const fmt = (d) => d.getUTCHours().toString().padStart(2, '0') + ':' + d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
    const peak = binTotals[peakKey];

    // Check AAR exceedance
    let aarBadge = '';
    const proRate = granMin / 60;
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates && DEMAND_STATE.rateData.rates.vatsim_aar) {
        const aar = Math.round(DEMAND_STATE.rateData.rates.vatsim_aar * proRate);
        if (peak.arr > aar) {
            aarBadge = '<div style="margin-top:4px;padding:3px 6px;background:#fff3cd;border-radius:2px;color:#856404;font-size:9px;font-weight:600;">' +
                PERTII18n.t('demand.summary.exceededBy', { count: peak.arr - aar }) + '</div>';
        } else {
            aarBadge = '<div style="margin-top:4px;padding:3px 6px;background:#d4edda;border-radius:2px;color:#155724;font-size:9px;font-weight:600;">' +
                PERTII18n.t('demand.summary.withinCapacity') + '</div>';
        }
    }

    container.innerHTML =
        '<div style="font-size:18px;font-weight:700;color:#dc2626;font-family:monospace;">' + fmt(peakDate) + '–' + fmt(endDate) + '</div>' +
        '<div style="color:#666;font-size:11px;">' + peak.arr + ' arr | ' + peak.dep + ' dep</div>' +
        aarBadge;
}
```

- [ ] **Step 3: Implement renderTmiControlCard()**

```javascript
/**
 * Render TMI Control card: GDP controlled, GS stopped, exempt, avg delay.
 */
function renderTmiControlCard() {
    const container = document.getElementById('summary_tmi_control');
    if (!container) return;

    const data = DEMAND_STATE.lastDemandData;
    if (!data) { container.innerHTML = '<span class="text-muted small">--</span>'; return; }

    const filtered = applyClientFilters(data);
    const allBins = [...(filtered.arrivals || []), ...(filtered.departures || [])];

    // Sum TMI-related phases across all bins
    let gdpCount = 0, gsCount = 0, exemptCount = 0;
    allBins.forEach(bin => {
        if (!bin.breakdown) return;
        gdpCount += (bin.breakdown.actual_gdp || 0) + (bin.breakdown.simulated_gdp || 0);
        gsCount += (bin.breakdown.actual_gs || 0) + (bin.breakdown.simulated_gs || 0);
        exemptCount += (bin.breakdown.exempt || 0);
    });

    // Avg/max delay from TMI programs
    let avgDelay = '--', maxDelay = '--', compliance = '--';
    const programs = DEMAND_STATE.tmiPrograms;
    if (programs && programs.length > 0) {
        const gdpPrograms = programs.filter(p => (p.program_type || '').toUpperCase().startsWith('GDP'));
        if (gdpPrograms.length > 0) {
            const delays = gdpPrograms.map(p => p.avg_delay_minutes).filter(d => d != null);
            const maxDelays = gdpPrograms.map(p => p.max_delay_minutes).filter(d => d != null);
            if (delays.length > 0) avgDelay = Math.round(delays.reduce((s, d) => s + d, 0) / delays.length) + ' min';
            if (maxDelays.length > 0) maxDelay = Math.max(...maxDelays) + ' min';
        }
    }

    container.innerHTML =
        '<div><span style="font-weight:600;">' + PERTII18n.t('demand.summary.gdpControlled') + ':</span> ' + gdpCount + '</div>' +
        '<div><span style="font-weight:600;">' + PERTII18n.t('demand.summary.gsStopped') + ':</span> ' + gsCount + '</div>' +
        '<div><span style="font-weight:600;">' + PERTII18n.t('demand.summary.exempt') + ':</span> ' + exemptCount + '</div>' +
        '<div style="margin-top:4px;font-weight:600;">' + PERTII18n.t('demand.summary.avgDelay') + ': <span style="color:#dc2626;font-family:monospace;">' + avgDelay + '</span></div>' +
        '<div style="font-weight:600;">' + PERTII18n.t('demand.summary.maxDelay') + ': <span style="font-family:monospace;">' + maxDelay + '</span></div>';
}
```

- [ ] **Step 4: Implement renderWeightMixCard()**

```javascript
/**
 * Render Weight Mix card: horizontal bars for H/L/S/+ percentages.
 */
function renderWeightMixCard() {
    const container = document.getElementById('summary_weight_mix');
    if (!container) return;

    const breakdown = DEMAND_STATE.weightBreakdown;
    if (!breakdown || Object.keys(breakdown).length === 0) {
        container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>';
        return;
    }

    // Aggregate weight counts across all time bins
    const totals = {};
    Object.values(breakdown).forEach(bin => {
        if (bin && typeof bin === 'object') {
            for (const [wc, count] of Object.entries(bin)) {
                totals[wc] = (totals[wc] || 0) + count;
            }
        }
    });

    const grand = Object.values(totals).reduce((s, v) => s + v, 0);
    if (grand === 0) { container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>'; return; }

    const WEIGHT_COLORS = { 'H': '#dc2626', 'L': '#3b82f6', 'S': '#22c55e', '+': '#9333ea' };
    const order = ['H', 'L', 'S', '+'];

    let html = '';
    order.forEach(wc => {
        const count = totals[wc] || 0;
        const pct = grand > 0 ? Math.round(count / grand * 100) : 0;
        const color = WEIGHT_COLORS[wc] || '#6b7280';
        html +=
            '<div style="display:flex;justify-content:space-between;font-size:11px;"><span>' + wc + '</span><span style="font-weight:600;">' + pct + '% (' + count + ')</span></div>' +
            '<div style="background:#e5e7eb;height:6px;border-radius:3px;margin:2px 0 4px;"><div style="background:' + color + ';height:100%;width:' + pct + '%;border-radius:3px;"></div></div>';
    });

    container.innerHTML = html;
}
```

- [ ] **Step 5: Call renderSummaryCards() from data load**

In the summary result handler inside `loadDemandData()` (added in Task 5), find `updateTopOrigins(summaryResponse.top_origins || [])` and `updateTopCarriers(summaryResponse.top_carriers || [])`. Replace BOTH calls with:

```javascript
                    renderSummaryCards();
```

Also in the `loadFlightSummary()` function (which is still used as fallback), replace the `updateTopOrigins`/`updateTopCarriers` calls with `renderSummaryCards()`.

- [ ] **Step 6: Verify manually**

Load demand page, select an airport:
- Peak Hour card shows time range with highest demand
- TMI Control card shows GDP/GS/exempt counts
- Weight Mix card shows horizontal bars for H/L/S/+
- All cards update when changing direction or applying filters

- [ ] **Step 7: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): implement Peak Hour, TMI Control, and Weight Mix summary cards"
```

---

### Task 10: Top Origins/Carriers/Fixes Cards + Click-to-Filter

**Files:**
- Modify: `assets/js/demand.js`

- [ ] **Step 1: Implement renderTopOriginsCard()**

```javascript
/**
 * Render Top Origins card with clickable ARTCC codes.
 */
function renderTopOriginsCard() {
    const container = document.getElementById('summary_top_origins');
    if (!container) return;

    const summaryData = DEMAND_STATE.summaryData;
    const origins = summaryData ? (summaryData.top_origins || []) : [];

    if (origins.length === 0) {
        container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>';
        return;
    }

    let html = '';
    origins.slice(0, 5).forEach((item, i) => {
        const code = item.artcc || item.origin_artcc || item[0] || '';
        const count = item.count || item[1] || 0;
        const weight = i === 0 ? 'font-weight:700;' : '';
        html += '<div style="display:flex;justify-content:space-between;padding:2px 0;' + (i < 4 ? 'border-bottom:1px solid #f0f0f0;' : '') + '">' +
            '<a href="#" class="summary-origin-click" data-artcc="' + code + '" style="' + weight + 'color:#2c3e50;text-decoration:none;" title="Click to filter">' + code + '</a>' +
            '<span style="font-family:monospace;">' + count + '</span></div>';
    });
    container.innerHTML = html;

    // Bind click-to-filter
    $(container).find('.summary-origin-click').on('click', function(e) {
        e.preventDefault();
        const artcc = $(this).data('artcc');
        if (artcc) {
            DEMAND_STATE.filterOriginArtccs = [artcc];
            $('#filter_origin_artcc').val([artcc]).trigger('change');
            onEnhancedFilterChange();
        }
    });
}
```

- [ ] **Step 2: Implement renderTopCarriersCard()**

```javascript
/**
 * Render Top Carriers card with clickable carrier codes.
 */
function renderTopCarriersCard() {
    const container = document.getElementById('summary_top_carriers');
    if (!container) return;

    const summaryData = DEMAND_STATE.summaryData;
    const carriers = summaryData ? (summaryData.top_carriers || []) : [];

    if (carriers.length === 0) {
        container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>';
        return;
    }

    let html = '';
    carriers.slice(0, 5).forEach((item, i) => {
        const code = item.carrier || item[0] || '';
        const count = item.count || item[1] || 0;
        const weight = i === 0 ? 'font-weight:700;' : '';
        html += '<div style="display:flex;justify-content:space-between;padding:2px 0;' + (i < 4 ? 'border-bottom:1px solid #f0f0f0;' : '') + '">' +
            '<a href="#" class="summary-carrier-click" data-carrier="' + code + '" style="' + weight + 'color:#2c3e50;text-decoration:none;" title="Click to filter">' + code + '</a>' +
            '<span style="font-family:monospace;">' + count + '</span></div>';
    });
    container.innerHTML = html;

    // Bind click-to-filter
    $(container).find('.summary-carrier-click').on('click', function(e) {
        e.preventDefault();
        const carrier = $(this).data('carrier');
        if (carrier) {
            DEMAND_STATE.filterCarriers = [carrier];
            $('#filter_carrier').val([carrier]).trigger('change');
            onEnhancedFilterChange();
        }
    });
}
```

- [ ] **Step 3: Implement renderTopFixesCard()**

```javascript
/**
 * Render Top Fixes card (arrival or departure based on direction).
 */
function renderTopFixesCard() {
    const container = document.getElementById('summary_top_fixes');
    const titleEl = document.getElementById('summary_fixes_title');
    if (!container) return;

    const direction = DEMAND_STATE.direction;
    const isDepOnly = direction === 'dep';

    // Update card title
    if (titleEl) {
        titleEl.textContent = isDepOnly
            ? PERTII18n.t('demand.summary.topDepFixes')
            : PERTII18n.t('demand.summary.topArrFixes');
    }

    // Get fix breakdown
    const breakdown = isDepOnly ? DEMAND_STATE.depFixBreakdown : DEMAND_STATE.arrFixBreakdown;
    if (!breakdown || Object.keys(breakdown).length === 0) {
        container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>';
        return;
    }

    // Aggregate across bins and sort by count
    const totals = {};
    Object.values(breakdown).forEach(bin => {
        if (bin && typeof bin === 'object') {
            for (const [fix, count] of Object.entries(bin)) {
                totals[fix] = (totals[fix] || 0) + count;
            }
        }
    });

    const sorted = Object.entries(totals).sort((a, b) => b[1] - a[1]).slice(0, 5);

    if (sorted.length === 0) {
        container.innerHTML = '<span class="text-muted small">' + PERTII18n.t('demand.summary.noData') + '</span>';
        return;
    }

    let html = '';
    sorted.forEach(([fix, count], i) => {
        const weight = i === 0 ? 'font-weight:700;' : '';
        html += '<div style="display:flex;justify-content:space-between;padding:2px 0;' + (i < sorted.length - 1 ? 'border-bottom:1px solid #f0f0f0;' : '') + '">' +
            '<span style="' + weight + '">' + fix + '</span>' +
            '<span style="font-family:monospace;">' + count + '</span></div>';
    });
    container.innerHTML = html;
}
```

- [ ] **Step 4: Remove old updateTopOrigins() and updateTopCarriers() calls**

Search for all calls to `updateTopOrigins()` and `updateTopCarriers()` in demand.js. These old functions wrote to `#demand_top_origins` and `#demand_top_carriers` (IDs that no longer exist in the new HTML). Either remove the function bodies or redirect them to the new card functions. The simplest approach is to make them no-ops:

```javascript
// Legacy — replaced by renderSummaryCards()
function updateTopOrigins() { /* no-op, handled by renderTopOriginsCard */ }
function updateTopCarriers() { /* no-op, handled by renderTopCarriersCard */ }
```

- [ ] **Step 5: Verify manually**

Load demand page, select an airport:
- Top Origins card shows top 5 origin ARTCCs with counts
- Top Carriers card shows top 5 carriers with counts
- Top Fixes card shows arrival fixes (switches to dep fixes when direction=dep)
- Click an ARTCC code → origin filter activates with that ARTCC
- Click a carrier code → carrier filter activates with that carrier

- [ ] **Step 6: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): implement Top Origins, Carriers, Fixes cards with click-to-filter"
```

---

## Feature 4: Multi-Chart Comparison Mode

### Task 11: Comparison Toggle HTML + State + i18n + CSS

**Files:**
- Modify: `demand.php:875-881` (below airport selector)
- Modify: `demand.php:1196-1223` (chart container area)
- Modify: `assets/js/demand.js:838-910` (DEMAND_STATE)
- Modify: `assets/locales/en-US.json`, `fr-CA.json`, `en-CA.json`, `en-EU.json`

- [ ] **Step 1: Add i18n keys to en-US.json**

Inside `demand` object, add a new `compare` sub-object:

```json
    "compare": {
      "addAirport": "Add Airport",
      "aggregate": "Aggregate",
      "enable": "Compare",
      "maxReached": "Maximum 4 airports",
      "remove": "Remove {airport}"
    },
```

- [ ] **Step 2: Add matching fr-CA.json keys**

```json
    "compare": {
      "addAirport": "Ajouter un aeroport",
      "aggregate": "Agregat",
      "enable": "Comparer",
      "maxReached": "Maximum 4 aeroports",
      "remove": "Retirer {airport}"
    },
```

- [ ] **Step 3: Add comparison state properties to DEMAND_STATE**

After the `summaryData: null,` line (added in Task 4), add:

```javascript
    // Comparison mode state (Feature 4)
    comparisonMode: false,
    comparisonAirports: [],       // Array of ICAO strings, max 4
    comparisonCharts: new Map(),   // ICAO → ECharts instance
    comparisonData: new Map(),     // ICAO → { demandData, summaryData, tmiPrograms, rateData, atisData, dataHash, summaryDataHash }
```

- [ ] **Step 4: Add comparison toggle HTML below airport selector**

In `demand.php`, find the airport selector `</div>` (closing the `form-group` around L881). Add AFTER it:

```php
                    <!-- Comparison Mode Toggle -->
                    <div class="form-group mb-2" id="compare_toggle_container">
                        <div class="d-flex align-items-center" style="gap: 8px;">
                            <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.8rem;">
                                <input type="checkbox" id="compare_mode_toggle" style="margin-right: 4px;">
                                <i class="fas fa-columns mr-1 text-muted"></i> <?= __('demand.compare.enable') ?>
                            </label>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="compare_add_btn" style="display: none; font-size: 0.7rem;">
                                + <?= __('demand.compare.addAirport') ?>
                            </button>
                        </div>
                        <!-- Chip bar for selected airports -->
                        <div id="compare_chip_bar" class="d-flex flex-wrap mt-1" style="gap: 4px; display: none !important;"></div>
                        <small class="text-danger" id="compare_max_msg" style="display: none;"><?= __('demand.compare.maxReached') ?></small>
                    </div>
```

- [ ] **Step 5: Add comparison chart grid container**

In `demand.php`, find the chart container div (L1216):

```html
<div id="demand_chart" class="demand-chart-container" style="display: none;"></div>
```

Add BEFORE it:

```php
                        <!-- Comparison grid (hidden by default, replaces single chart in comparison mode) -->
                        <div id="demand_chart_grid" style="display: none; gap: 8px;"></div>
```

- [ ] **Step 6: Add CSS for comparison grid**

In the inline `<style>` block at the top of `demand.php` (around L28-640), add at the end of the style block:

```css
/* Comparison Mode Grid */
#demand_chart_grid {
    display: none;
}
#demand_chart_grid.active {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
#demand_chart_grid.single-col {
    grid-template-columns: 1fr;
}
.compare-panel {
    border: 2px solid #2c3e50;
    border-radius: 4px;
    background: #f8f9fa;
    overflow: hidden;
}
.compare-panel-header {
    background: #ecf0f1;
    border-bottom: 1px solid #bdc3c7;
    padding: 4px 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.compare-panel-header .airport-code {
    font-weight: 700;
    font-size: 0.85rem;
    color: #2c3e50;
}
.compare-panel-header .airport-meta {
    font-size: 0.65rem;
    color: #666;
    font-family: 'Roboto Mono', monospace;
}
.compare-panel-chart {
    height: 340px;
}
.compare-panel-chart.side-by-side {
    height: 380px;
}
.compare-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: #2c3e50;
    color: #fff;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
}
.compare-chip .chip-remove {
    cursor: pointer;
    opacity: 0.7;
    font-size: 0.6rem;
}
.compare-chip .chip-remove:hover {
    opacity: 1;
}
/* Stats tab strip for comparison mode */
.summary-tab-strip {
    display: flex;
    gap: 4px;
    margin-bottom: 8px;
}
.summary-tab {
    padding: 2px 10px;
    border: 1px solid #bdc3c7;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    background: #fff;
    color: #2c3e50;
}
.summary-tab.active {
    background: #2c3e50;
    color: #fff;
    border-color: #2c3e50;
}
```

- [ ] **Step 7: Verify manually**

Load demand page:
- "Compare" checkbox visible below airport selector
- Grid container and chip bar are hidden
- CSS classes are defined (inspect in DevTools)

- [ ] **Step 8: Commit**

```bash
git add demand.php assets/js/demand.js assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(demand): add comparison mode HTML, state, CSS, and i18n keys"
```

---

### Task 12: Comparison Mode Toggle Logic + Chart Grid Management

**Files:**
- Modify: `assets/js/demand.js`

- [ ] **Step 1: Implement enterComparisonMode() and exitComparisonMode()**

Add these functions near the end of the file (before the `$(document).ready()` block):

```javascript
/**
 * Enter comparison mode: current airport becomes first comparison airport.
 */
function enterComparisonMode() {
    DEMAND_STATE.comparisonMode = true;
    const current = DEMAND_STATE.selectedAirport;
    if (current && !DEMAND_STATE.comparisonAirports.includes(current)) {
        DEMAND_STATE.comparisonAirports.push(current);
    }

    // Show comparison UI
    $('#compare_add_btn').show();
    $('#compare_chip_bar').css('display', '').show();
    renderComparisonChips();

    // Switch from single chart to grid
    $('#demand_chart').hide();
    $('#demand_tmi_timeline').hide();
    const $grid = $('#demand_chart_grid');
    $grid.addClass('active');

    // Build panels and load data
    rebuildComparisonPanels();
    loadAllComparisonData();

    writeUrlState();
}

/**
 * Exit comparison mode: revert to single-airport view.
 */
function exitComparisonMode() {
    // Keep first airport as selected
    const firstAirport = DEMAND_STATE.comparisonAirports[0] || DEMAND_STATE.selectedAirport;

    // Dispose all comparison chart instances
    DEMAND_STATE.comparisonCharts.forEach((chart, icao) => {
        if (chart && chart.dispose) chart.dispose();
    });
    DEMAND_STATE.comparisonCharts.clear();
    DEMAND_STATE.comparisonData.clear();
    DEMAND_STATE.comparisonAirports = [];
    DEMAND_STATE.comparisonMode = false;

    // Hide comparison UI
    $('#compare_add_btn').hide();
    $('#compare_chip_bar').hide();
    $('#compare_max_msg').hide();
    const $grid = $('#demand_chart_grid');
    $grid.removeClass('active').empty();

    // Restore single chart
    $('#demand_chart').show();

    // Select the first airport
    if (firstAirport) {
        DEMAND_STATE.selectedAirport = firstAirport;
        $('#demand_airport').val(firstAirport).trigger('change');
    }

    writeUrlState();
}
```

- [ ] **Step 2: Implement rebuildComparisonPanels()**

```javascript
/**
 * Build/rebuild comparison grid panels. Creates DOM elements and ECharts instances.
 */
function rebuildComparisonPanels() {
    const $grid = $('#demand_chart_grid');
    $grid.empty();

    const airports = DEMAND_STATE.comparisonAirports;
    const count = airports.length;

    // Dispose old chart instances
    DEMAND_STATE.comparisonCharts.forEach((chart) => {
        if (chart && chart.dispose) chart.dispose();
    });
    DEMAND_STATE.comparisonCharts.clear();

    // Adjust grid layout
    $grid.toggleClass('single-col', count === 1);

    // Determine chart height class
    const heightClass = count <= 2 ? 'side-by-side' : '';

    airports.forEach(icao => {
        const panelId = 'compare_panel_' + icao;
        const chartId = 'compare_chart_' + icao;
        const timelineId = 'compare_tmi_' + icao;

        const html =
            '<div class="compare-panel" id="' + panelId + '">' +
                '<div class="compare-panel-header">' +
                    '<span class="airport-code">' + icao + '</span>' +
                    '<span class="airport-meta" id="compare_meta_' + icao + '">--</span>' +
                '</div>' +
                '<div id="' + timelineId + '" class="demand-tmi-timeline" style="display:none;">' +
                    '<div class="tmi-timeline-track" id="compare_tmi_track_' + icao + '"></div>' +
                '</div>' +
                '<div id="' + chartId + '" class="compare-panel-chart ' + heightClass + '"></div>' +
            '</div>';

        $grid.append(html);

        // Initialize ECharts instance for this panel
        const chartDom = document.getElementById(chartId);
        if (chartDom) {
            const chart = echarts.init(chartDom);
            DEMAND_STATE.comparisonCharts.set(icao, chart);

            // Wire datazoom sync
            chart.on('datazoom', function(params) {
                syncDataZoom(icao, params);
            });
        }
    });

    // Add "Add Airport" placeholder if under limit
    if (count < 4) {
        $grid.append(
            '<div class="compare-panel" style="border-style:dashed;border-color:#bdc3c7;display:flex;align-items:center;justify-content:center;min-height:200px;cursor:pointer;" id="compare_add_panel">' +
                '<div style="text-align:center;color:#aaa;">' +
                    '<div style="font-size:24px;">+</div>' +
                    '<div style="font-size:10px;">' + PERTII18n.t('demand.compare.addAirport') + '</div>' +
                '</div>' +
            '</div>'
        );
        $('#compare_add_panel').on('click', function() {
            $('#demand_airport').select2('open');
        });
    }
}
```

- [ ] **Step 3: Implement renderComparisonChips()**

```javascript
/**
 * Render airport chip/tag bar for comparison mode.
 */
function renderComparisonChips() {
    const $bar = $('#compare_chip_bar');
    $bar.empty();

    DEMAND_STATE.comparisonAirports.forEach(icao => {
        const chip = $('<span class="compare-chip">' + icao +
            ' <span class="chip-remove" data-icao="' + icao + '" title="' +
            PERTII18n.t('demand.compare.remove', { airport: icao }) + '">&times;</span></span>');
        $bar.append(chip);
    });

    // Bind remove handlers
    $bar.find('.chip-remove').on('click', function() {
        const icao = $(this).data('icao');
        removeComparisonAirport(icao);
    });

    // Show/hide max message
    $('#compare_max_msg').toggle(DEMAND_STATE.comparisonAirports.length >= 4);
    $('#compare_add_btn').prop('disabled', DEMAND_STATE.comparisonAirports.length >= 4);
}

/**
 * Remove an airport from comparison.
 */
function removeComparisonAirport(icao) {
    const idx = DEMAND_STATE.comparisonAirports.indexOf(icao);
    if (idx === -1) return;

    DEMAND_STATE.comparisonAirports.splice(idx, 1);

    // Dispose chart instance
    const chart = DEMAND_STATE.comparisonCharts.get(icao);
    if (chart && chart.dispose) chart.dispose();
    DEMAND_STATE.comparisonCharts.delete(icao);
    DEMAND_STATE.comparisonData.delete(icao);

    // If no airports left, exit comparison mode
    if (DEMAND_STATE.comparisonAirports.length === 0) {
        $('#compare_mode_toggle').prop('checked', false);
        exitComparisonMode();
        return;
    }

    renderComparisonChips();
    rebuildComparisonPanels();
    loadAllComparisonData();
    writeUrlState();
}
```

- [ ] **Step 4: Wire comparison mode toggle and airport add handlers**

Add to the initialization section:

```javascript
    // Comparison mode toggle
    $('#compare_mode_toggle').on('change', function() {
        if (this.checked) {
            enterComparisonMode();
        } else {
            exitComparisonMode();
        }
    });

    // Add airport button
    $('#compare_add_btn').on('click', function() {
        $('#demand_airport').select2('open');
    });
```

Also modify the airport selector change handler. Find where `$('#demand_airport').on('change', ...)` is handled. Add a comparison mode branch:

```javascript
    // Inside the existing airport change handler, add at the top:
    if (DEMAND_STATE.comparisonMode) {
        const newAirport = $(this).val();
        if (newAirport && !DEMAND_STATE.comparisonAirports.includes(newAirport) &&
            DEMAND_STATE.comparisonAirports.length < 4) {
            DEMAND_STATE.comparisonAirports.push(newAirport);
            renderComparisonChips();
            rebuildComparisonPanels();
            loadAllComparisonData();
            writeUrlState();
        }
        return; // Don't trigger single-airport load
    }
```

- [ ] **Step 5: Auto-exit comparison for non-airport demand types**

In the demand type change handler (search for `$('#demand_type').on('change'`), add:

```javascript
        // Exit comparison mode for non-airport types
        if (DEMAND_STATE.comparisonMode && val !== 'airport') {
            $('#compare_mode_toggle').prop('checked', false);
            exitComparisonMode();
        }
        // Hide comparison toggle for non-airport types
        $('#compare_toggle_container').toggle(val === 'airport');
```

- [ ] **Step 6: Verify manually**

Load demand page, select an airport:
- Check "Compare" → grid appears with current airport panel
- Airport dropdown now adds to comparison (not replaces)
- Chip bar shows airport tags with × buttons
- Click × → removes airport, grid updates
- Uncheck "Compare" → returns to single chart

- [ ] **Step 7: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): implement comparison mode toggle, grid panels, and chip management"
```

---

### Task 13: Per-Airport Data Fetching + Rendering

**Files:**
- Modify: `assets/js/demand.js`

- [ ] **Step 1: Implement loadAllComparisonData()**

```javascript
/**
 * Fetch data for all comparison airports in parallel.
 */
function loadAllComparisonData() {
    const airports = DEMAND_STATE.comparisonAirports;
    if (airports.length === 0) return;

    // Build time range params (same as single mode)
    const now = new Date();
    let start, end;
    if (DEMAND_STATE.timeRangeMode === 'custom' && DEMAND_STATE.customStart && DEMAND_STATE.customEnd) {
        start = new Date(DEMAND_STATE.customStart);
        end = new Date(DEMAND_STATE.customEnd);
    } else {
        start = new Date(now.getTime() + DEMAND_STATE.timeRangeStart * 3600000);
        end = new Date(now.getTime() + DEMAND_STATE.timeRangeEnd * 3600000);
    }
    DEMAND_STATE.currentStart = start.toISOString();
    DEMAND_STATE.currentEnd = end.toISOString();

    // Fetch all airports in parallel
    const fetchPromises = airports.map(icao => {
        const params = new URLSearchParams({
            airport: icao,
            start: start.toISOString(),
            end: end.toISOString(),
            direction: DEMAND_STATE.direction,
            granularity: getGranularityMinutes(),
        });

        const existing = DEMAND_STATE.comparisonData.get(icao) || {};

        const demandHeaders = {};
        if (existing.dataHash) demandHeaders['X-If-Data-Hash'] = existing.dataHash;
        const summaryHeaders = {};
        if (existing.summaryDataHash) summaryHeaders['X-If-Data-Hash'] = existing.summaryDataHash;

        return Promise.allSettled([
            $.ajax({ url: `api/demand/airport.php?${params.toString()}`, dataType: 'json', headers: demandHeaders }),
            $.ajax({ url: `api/demand/summary.php?${params.toString()}`, dataType: 'json', headers: summaryHeaders }),
            $.getJSON(`api/demand/tmi_programs.php?airport=${encodeURIComponent(icao)}&start=${encodeURIComponent(start.toISOString())}&end=${encodeURIComponent(end.toISOString())}`),
            $.getJSON(`api/demand/rates.php?airport=${encodeURIComponent(icao)}`),
        ]).then(results => ({ icao, results }));
    });

    Promise.allSettled(fetchPromises).then(outerResults => {
        outerResults.forEach(outer => {
            if (outer.status !== 'fulfilled') return;
            const { icao, results } = outer.value;
            const [demandR, summaryR, tmiR, rateR] = results;

            const data = DEMAND_STATE.comparisonData.get(icao) || {};

            // Demand data
            if (demandR.status === 'fulfilled' && demandR.value) {
                if (demandR.value.unchanged) {
                    // Use cached
                } else if (demandR.value.success) {
                    data.demandData = demandR.value;
                    data.dataHash = demandR.value.data_hash || null;
                }
            }

            // Summary data
            if (summaryR.status === 'fulfilled' && summaryR.value) {
                if (summaryR.value.unchanged) {
                    // Use cached
                } else if (summaryR.value.success) {
                    data.summaryData = summaryR.value;
                    data.summaryDataHash = summaryR.value.data_hash || null;
                }
            }

            // TMI programs
            if (tmiR.status === 'fulfilled' && tmiR.value && tmiR.value.success) {
                data.tmiPrograms = tmiR.value.programs || [];
            }

            // Rate data
            if (rateR.status === 'fulfilled' && rateR.value && rateR.value.success) {
                data.rateData = rateR.value;
            }

            DEMAND_STATE.comparisonData.set(icao, data);

            // Render this airport's panel
            renderComparisonPanel(icao);
        });

        // Update info bar with aggregate stats
        updateComparisonInfoBar();
    });
}
```

- [ ] **Step 2: Implement renderComparisonPanel()**

```javascript
/**
 * Render a single comparison panel (chart + timeline + meta).
 * Uses the per-airport data context, NOT DEMAND_STATE globals.
 */
function renderComparisonPanel(icao) {
    const ctx = DEMAND_STATE.comparisonData.get(icao);
    if (!ctx || !ctx.demandData) return;

    const chart = DEMAND_STATE.comparisonCharts.get(icao);
    if (!chart) return;

    const data = ctx.demandData;
    const direction = DEMAND_STATE.direction;

    // Apply client filters using the global filter state but per-airport breakdown data
    // (filters are shared across panels)
    const filteredData = applyClientFilters(data);
    const arrivals = filteredData.arrivals || [];
    const departures = filteredData.departures || [];

    // Build time bins
    const timeBinSet = new Set();
    arrivals.forEach(d => timeBinSet.add(normalizeTimeBin(d.time_bin)));
    departures.forEach(d => timeBinSet.add(normalizeTimeBin(d.time_bin)));
    const timeBins = [...timeBinSet].sort().map(t => new Date(t).getTime());

    // Build phase series (same logic as renderChart but parameterized)
    const phaseOrder = DemandChartCore.PHASE_ORDER;
    const series = [];

    if (direction === 'arr' || direction === 'both') {
        const arrByBin = {};
        arrivals.forEach(d => { arrByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });
        phaseOrder.forEach(phase => {
            const suffix = direction === 'both' ? ' (A)' : '';
            series.push(buildPhaseSeriesTimeAxis(FSM_PHASE_LABELS[phase] + suffix, timeBins, arrByBin, phase, 'arrivals', direction));
        });
    }
    if (direction === 'dep' || direction === 'both') {
        const depByBin = {};
        departures.forEach(d => { depByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });
        phaseOrder.forEach(phase => {
            const suffix = direction === 'both' ? ' (D)' : '';
            series.push(buildPhaseSeriesTimeAxis(FSM_PHASE_LABELS[phase] + suffix, timeBins, depByBin, phase, 'departures', direction));
        });
    }

    // Build markLines (rate lines + TMI markers for this airport's data)
    const markLineData = [];
    const timeMarker = getCurrentTimeMarkLineForTimeAxis();
    if (timeMarker) markLineData.push(timeMarker);

    // Rate lines from this airport's rate data
    if (ctx.rateData && ctx.rateData.rates) {
        const rates = ctx.rateData.rates;
        const proRate = getGranularityMinutes() / 60;
        const addRateLine = (value, label, color, lineType) => {
            if (!value) return;
            const proRated = Math.round(value * proRate * 10) / 10;
            markLineData.push({
                yAxis: proRated,
                lineStyle: { color: color, width: 2, type: lineType },
                label: { show: true, formatter: label + ' ' + proRated, position: 'end', fontSize: 9, color: '#fff', backgroundColor: color, padding: [1, 4], borderRadius: 2 },
            });
        };
        if ((direction === 'both' || direction === 'arr') && DEMAND_STATE.showVatsimAar) addRateLine(rates.vatsim_aar, 'AAR', '#000', 'solid');
        if ((direction === 'both' || direction === 'dep') && DEMAND_STATE.showVatsimAdr) addRateLine(rates.vatsim_adr, 'ADR', '#000', [4, 4]);
    }

    // TMI markers for this airport
    if (DEMAND_STATE.showTmiMarkers && ctx.tmiPrograms && ctx.tmiPrograms.length > 0) {
        // Temporarily swap tmiPrograms for buildTmiMarkerLines()
        const savedPrograms = DEMAND_STATE.tmiPrograms;
        DEMAND_STATE.tmiPrograms = ctx.tmiPrograms;
        const tmiLines = buildTmiMarkerLines();
        DEMAND_STATE.tmiPrograms = savedPrograms;
        markLineData.push(...tmiLines);
    }

    if (series.length > 0 && markLineData.length > 0) {
        series[0].markLine = { silent: true, symbol: ['none', 'none'], data: markLineData };
    }

    // Build chart options (compact for comparison)
    const intervalMs = getGranularityMinutes() * 60000;
    const options = {
        animation: false,
        grid: { left: 40, right: 10, top: 10, bottom: 30 },
        xAxis: {
            type: 'time',
            min: new Date(DEMAND_STATE.currentStart).getTime(),
            max: new Date(DEMAND_STATE.currentEnd).getTime(),
            axisLabel: { fontSize: 9, formatter: '{HH}:{mm}Z' },
        },
        yAxis: { type: 'value', axisLabel: { fontSize: 9 } },
        tooltip: { trigger: 'axis' },
        series: series,
        dataZoom: [{ type: 'inside' }],
    };

    chart.setOption(options, true);

    // Update panel meta (AAR/ADR)
    const $meta = $('#compare_meta_' + icao);
    if (ctx.rateData && ctx.rateData.rates) {
        const r = ctx.rateData.rates;
        $meta.text('AAR ' + (r.vatsim_aar || '--') + ' | ADR ' + (r.vatsim_adr || '--'));
    }

    // Render per-panel TMI timeline
    if (DEMAND_STATE.showTmiTimeline && ctx.tmiPrograms && ctx.tmiPrograms.length > 0) {
        renderComparisonTmiTimeline(icao, ctx.tmiPrograms);
    }
}
```

- [ ] **Step 3: Implement renderComparisonTmiTimeline()**

```javascript
/**
 * Render a compact TMI timeline for a comparison panel.
 */
function renderComparisonTmiTimeline(icao, programs) {
    const container = document.getElementById('compare_tmi_' + icao);
    const track = document.getElementById('compare_tmi_track_' + icao);
    if (!container || !track) return;

    const filtered = programs.filter(p => {
        const t = (p.program_type || '').toUpperCase();
        return t === 'GS' || t.startsWith('GDP');
    });

    if (filtered.length === 0) { container.style.display = 'none'; return; }

    container.style.display = '';
    const chartStartMs = new Date(DEMAND_STATE.currentStart).getTime();
    const chartEndMs = new Date(DEMAND_STATE.currentEnd).getTime();
    const range = chartEndMs - chartStartMs;
    if (range <= 0) return;

    const toPct = (ms) => Math.max(0, Math.min(100, (ms - chartStartMs) / range * 100));
    const COLORS = {
        'GS': { bg: '#dc3545' }, 'GDP': { bg: '#ffc107' },
        'GDP-DAS': { bg: '#ffc107' }, 'GDP-GAAP': { bg: '#ff9800' }, 'GDP-UDP': { bg: '#ff5722' },
    };

    track.innerHTML = '';
    track.style.height = '20px';
    track.style.position = 'relative';

    filtered.forEach(p => {
        const startMs = new Date(p.start_utc).getTime();
        const endMs = new Date(p.end_utc || p.purged_at || new Date()).getTime();
        const pType = (p.program_type || '').toUpperCase();
        const color = (COLORS[pType] || { bg: '#6c757d' }).bg;

        const bar = document.createElement('div');
        bar.style.cssText = 'position:absolute;top:2px;height:16px;border-radius:2px;font-size:8px;color:#fff;line-height:16px;padding:0 4px;overflow:hidden;white-space:nowrap;';
        bar.style.left = toPct(startMs) + '%';
        bar.style.width = Math.max(0.5, toPct(endMs) - toPct(startMs)) + '%';
        bar.style.background = color;
        bar.textContent = pType;
        track.appendChild(bar);
    });
}
```

- [ ] **Step 4: Hook comparison into auto-refresh**

In the auto-refresh handler (search for `startAutoRefresh()`), find where it calls `loadDemandData()`. Add a comparison branch:

```javascript
        if (DEMAND_STATE.comparisonMode) {
            loadAllComparisonData();
        } else if (DEMAND_STATE.demandType === 'airport') {
            loadDemandData();
        } else {
            loadFacilityDemand();
        }
```

- [ ] **Step 5: Verify manually**

- Enter comparison mode with 2 airports → both panels show charts
- Charts display correct demand data independently
- TMI timeline bars appear per-panel
- Rate lines show per-panel AAR/ADR
- Auto-refresh updates all panels

- [ ] **Step 6: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): implement comparison data fetch and per-panel rendering"
```

---

### Task 14: DataZoom Sync + Info Bar Adaptation

**Files:**
- Modify: `assets/js/demand.js`

- [ ] **Step 1: Implement syncDataZoom()**

```javascript
/**
 * Sync datazoom across all comparison panels.
 * Uses a flag to prevent cascade loops.
 */
let _syncingZoom = false;
function syncDataZoom(sourceIcao, params) {
    if (_syncingZoom) return;
    _syncingZoom = true;

    try {
        const sourceChart = DEMAND_STATE.comparisonCharts.get(sourceIcao);
        if (!sourceChart) return;

        // Read the current zoom state from the source chart
        const option = sourceChart.getOption();
        const dz = option.dataZoom && option.dataZoom[0];
        if (!dz) return;

        // Apply to all other charts
        DEMAND_STATE.comparisonCharts.forEach((chart, icao) => {
            if (icao === sourceIcao) return;
            chart.dispatchAction({
                type: 'dataZoom',
                start: dz.start,
                end: dz.end,
            });
        });
    } finally {
        setTimeout(() => { _syncingZoom = false; }, 50);
    }
}
```

- [ ] **Step 2: Implement updateComparisonInfoBar()**

```javascript
/**
 * Update info bar for comparison mode: aggregate stats, hide per-airport cards.
 */
function updateComparisonInfoBar() {
    if (!DEMAND_STATE.comparisonMode) return;

    // Airport card: show chip list instead of single airport
    const airports = DEMAND_STATE.comparisonAirports;
    $('#demand_selected_airport').text(airports.join(' / '));
    $('#demand_airport_name').text(PERTII18n.t('demand.compare.aggregate'));

    // Hide config and ATIS cards (too airport-specific)
    $('#demand_config_card').hide();
    $('#demand_atis_card').hide();

    // Aggregate arrival/departure totals
    let totalArr = 0, totalDep = 0;
    DEMAND_STATE.comparisonData.forEach((ctx) => {
        if (!ctx.demandData) return;
        const filtered = applyClientFilters(ctx.demandData);
        const sumBins = (bins) => (bins || []).reduce((s, bin) => {
            return s + (bin.breakdown ? Object.values(bin.breakdown).reduce((a, b) => a + b, 0) : 0);
        }, 0);
        totalArr += sumBins(filtered.arrivals);
        totalDep += sumBins(filtered.departures);
    });

    $('#demand_arr_total').text(totalArr);
    $('#demand_dep_total').text(totalDep);
}
```

- [ ] **Step 3: Restore info bar on exit**

In `exitComparisonMode()`, add after the grid is cleared:

```javascript
    // Restore info bar cards
    $('#demand_config_card').show();
    $('#demand_atis_card').show();
```

- [ ] **Step 4: Handle resize for comparison panels**

Add a window resize handler for comparison mode:

```javascript
$(window).on('resize', function() {
    if (DEMAND_STATE.comparisonMode) {
        DEMAND_STATE.comparisonCharts.forEach(chart => {
            if (chart && chart.resize) chart.resize();
        });
    }
});
```

- [ ] **Step 5: Verify manually**

- Pan/zoom one chart → all others sync
- Info bar shows aggregate totals
- Config and ATIS cards hidden in comparison mode
- Exit comparison → cards reappear
- Resize window → all comparison panels resize

- [ ] **Step 6: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): add datazoom sync, info bar adaptation, and resize handling"
```

---

### Task 15: Comparison Stats Tab Strip + URL State

**Files:**
- Modify: `assets/js/demand.js`

- [ ] **Step 1: Add stats tab strip rendering**

In `renderSummaryCards()`, add at the beginning:

```javascript
    // In comparison mode, add tab strip above cards
    if (DEMAND_STATE.comparisonMode) {
        let tabHtml = '<div class="summary-tab-strip" id="summary_tab_strip">';
        DEMAND_STATE.comparisonAirports.forEach((icao, i) => {
            tabHtml += '<span class="summary-tab' + (i === 0 ? ' active' : '') + '" data-icao="' + icao + '">' + icao + '</span>';
        });
        tabHtml += '</div>';

        const $grid = $('#summary_card_grid');
        $grid.find('.summary-tab-strip').remove();
        $grid.prepend(tabHtml);

        // Tab click handler
        $grid.find('.summary-tab').on('click', function() {
            $grid.find('.summary-tab').removeClass('active');
            $(this).addClass('active');
            const icao = $(this).data('icao');
            renderSummaryCardsForAirport(icao);
        });

        // Render first airport's stats
        renderSummaryCardsForAirport(DEMAND_STATE.comparisonAirports[0]);
        return; // Don't render default single-airport cards
    }
```

- [ ] **Step 2: Implement renderSummaryCardsForAirport()**

```javascript
/**
 * Render summary cards for a specific airport in comparison mode.
 * Temporarily swaps DEMAND_STATE globals to use per-airport data.
 */
function renderSummaryCardsForAirport(icao) {
    const ctx = DEMAND_STATE.comparisonData.get(icao);
    if (!ctx) return;

    // Save global state
    const saved = {
        lastDemandData: DEMAND_STATE.lastDemandData,
        rateData: DEMAND_STATE.rateData,
        tmiPrograms: DEMAND_STATE.tmiPrograms,
        summaryData: DEMAND_STATE.summaryData,
        weightBreakdown: DEMAND_STATE.weightBreakdown,
        arrFixBreakdown: DEMAND_STATE.arrFixBreakdown,
        depFixBreakdown: DEMAND_STATE.depFixBreakdown,
        summaryLoaded: DEMAND_STATE.summaryLoaded,
    };

    // Swap in per-airport data
    DEMAND_STATE.lastDemandData = ctx.demandData;
    DEMAND_STATE.rateData = ctx.rateData;
    DEMAND_STATE.tmiPrograms = ctx.tmiPrograms;
    DEMAND_STATE.summaryData = ctx.summaryData;
    if (ctx.summaryData) {
        DEMAND_STATE.weightBreakdown = ctx.summaryData.weight_breakdown || {};
        DEMAND_STATE.arrFixBreakdown = ctx.summaryData.arr_fix_breakdown || {};
        DEMAND_STATE.depFixBreakdown = ctx.summaryData.dep_fix_breakdown || {};
        DEMAND_STATE.summaryLoaded = true;
    }

    // Render cards (they read from DEMAND_STATE)
    renderPeakHourCard();
    renderTmiControlCard();
    renderWeightMixCard();
    renderTopOriginsCard();
    renderTopCarriersCard();
    renderTopFixesCard();

    // Restore global state
    Object.assign(DEMAND_STATE, saved);
}
```

- [ ] **Step 3: Extend writeUrlState() with comparison params**

In `writeUrlState()`, add before `history.replaceState`:

```javascript
    // Comparison mode
    if (DEMAND_STATE.comparisonMode && DEMAND_STATE.comparisonAirports.length > 0) {
        params.set('compare', DEMAND_STATE.comparisonAirports.join(','));
        params.delete('airport'); // comparison uses 'compare' param instead
    }
```

- [ ] **Step 4: Extend readUrlState() to restore comparison**

In `readUrlState()`, add after the existing `view` restoration:

```javascript
    // Restore comparison mode
    if (params.has('compare')) {
        const airports = params.get('compare').split(',').filter(Boolean);
        if (airports.length > 0) {
            DEMAND_STATE.comparisonAirports = airports.slice(0, 4);
            DEMAND_STATE.comparisonMode = true;
            DEMAND_STATE.selectedAirport = airports[0];
            // Defer actual mode entry until after airport list loads
            setTimeout(() => {
                $('#compare_mode_toggle').prop('checked', true);
                enterComparisonMode();
            }, 500);
        }
    }
```

- [ ] **Step 5: Wire shared filter changes to update all comparison panels**

In `onEnhancedFilterChange()`, add a comparison mode branch:

```javascript
    // In comparison mode, re-render all panels
    if (DEMAND_STATE.comparisonMode) {
        DEMAND_STATE.comparisonAirports.forEach(icao => renderComparisonPanel(icao));
        updateComparisonInfoBar();
        // Re-render active stats tab
        const activeTab = $('#summary_tab_strip .summary-tab.active').data('icao');
        if (activeTab) renderSummaryCardsForAirport(activeTab);
        return;
    }
```

Add this at the START of the function, before the single-mode chart re-render.

- [ ] **Step 6: Wire chart view toggle to comparison mode**

Find the chart view toggle handler (search for `demand_chart_view` change). Add:

```javascript
        if (DEMAND_STATE.comparisonMode) {
            DEMAND_STATE.comparisonAirports.forEach(icao => renderComparisonPanel(icao));
            return;
        }
```

- [ ] **Step 7: Verify manually**

- Enter comparison mode with 3 airports → tab strip appears above summary cards
- Click airport tab → summary cards update for that airport
- URL shows `#compare=KJFK,KEWR,KLGA`
- Copy URL, open in new tab → comparison mode restores with correct airports
- Apply a filter → all panels update

- [ ] **Step 8: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): add comparison stats tabs, URL state, and shared filter sync"
```

---

### Task 16: Final Polish — Direction/Granularity Sync + Edge Cases

**Files:**
- Modify: `assets/js/demand.js`

- [ ] **Step 1: Sync direction and granularity changes to comparison**

In the direction toggle handler, add after the state update:

```javascript
        if (DEMAND_STATE.comparisonMode) {
            loadAllComparisonData();
            return;
        }
```

In the granularity toggle handler, add the same:

```javascript
        if (DEMAND_STATE.comparisonMode) {
            loadAllComparisonData();
            return;
        }
```

In the time range change handler, add the same pattern.

- [ ] **Step 2: Guard comparison-incompatible operations**

In `loadDemandData()`, add at the very top:

```javascript
    // In comparison mode, delegate to comparison loader
    if (DEMAND_STATE.comparisonMode) {
        loadAllComparisonData();
        return;
    }
```

- [ ] **Step 3: Handle edge case — selecting airport while not in comparison mode after exit**

In the airport change handler, make sure the comparison mode check (added in Task 12) comes FIRST before any other logic:

```javascript
    // Must be the FIRST check in the handler
    if (DEMAND_STATE.comparisonMode) {
        // ... add airport logic from Task 12
        return;
    }
    // ... existing single-airport logic continues
```

- [ ] **Step 4: Clean up comparison state on page unload**

Add to the initialization block:

```javascript
    // Clean up comparison charts on page unload
    $(window).on('beforeunload', function() {
        DEMAND_STATE.comparisonCharts.forEach(chart => {
            if (chart && chart.dispose) chart.dispose();
        });
    });
```

- [ ] **Step 5: Full integration test**

1. Load demand page, select KJFK
2. Check "Compare", confirm KJFK panel appears
3. Select KEWR → 2 panels side by side (380px height)
4. Select KLGA → 3 panels in 2×2 grid (340px height)
5. Select KTEB → 4 panels, + button disabled
6. Change direction to "arr" → all 4 panels re-render
7. Change granularity to 15-min → all panels update
8. Apply carrier filter "AAL" → all panels filter
9. Click KEWR tab in summary → summary cards show KEWR stats
10. Remove KLGA via × → grid rebuilds to 3 panels
11. Uncheck "Compare" → single KJFK chart restored
12. Copy comparison URL, open new tab → restores correctly

- [ ] **Step 6: Commit**

```bash
git add assets/js/demand.js
git commit -m "feat(demand): comparison mode direction/granularity sync and edge case handling"
```

---

## Self-Review

### Spec Coverage Check

| Spec Requirement | Task(s) | Status |
|-----------------|---------|--------|
| TMI Timeline toggle (DOM show/hide) | Task 1 (HTML+state), Task 2 (handler) | Covered |
| GS/GDP vertical marker lines | Task 3 (markLine builder) | Covered |
| Label collision avoidance (all markLines) | Task 3 (staggering logic) | Covered |
| Carrier Select2 multi-filter | Task 4 (HTML), Task 6 (init+handler) | Covered |
| Weight class checkboxes | Task 4 (HTML), Task 6 (handler) | Covered |
| Equipment Select2 multi-filter | Task 4 (HTML), Task 6 (init+handler) | Covered |
| Origin/Dest ARTCC multi-filter | Task 4 (HTML), Task 6 (init+handler) | Covered |
| Direction-aware ARTCC graying | Task 6 (updateArtccFilterState), Task 7 (wiring) | Covered |
| Client-side proportional filter | Task 6 (applyClientFilters) | Covered |
| Filter URL persistence | Task 7 | Covered |
| Reset Filters link | Task 4 (HTML), Task 6 (handler) | Covered |
| Summary into Promise.allSettled | Task 5 | Covered |
| Peak Hour card | Task 8 (HTML), Task 9 (render) | Covered |
| TMI Control card | Task 8 (HTML), Task 9 (render) | Covered |
| Weight Mix card | Task 8 (HTML), Task 9 (render) | Covered |
| Top Origins card (clickable) | Task 8 (HTML), Task 10 (render+click) | Covered |
| Top Carriers card (clickable) | Task 8 (HTML), Task 10 (render+click) | Covered |
| Top Fixes card (direction-aware) | Task 8 (HTML), Task 10 (render) | Covered |
| Comparison toggle + chip bar | Task 11 (HTML), Task 12 (logic) | Covered |
| 2×2 grid layout | Task 11 (CSS), Task 12 (panels) | Covered |
| Per-airport data fetch | Task 13 | Covered |
| Per-panel chart rendering | Task 13 | Covered |
| DataZoom sync | Task 14 | Covered |
| Info bar adaptation (aggregate) | Task 14 | Covered |
| Stats tab strip | Task 15 | Covered |
| Comparison URL state | Task 15 | Covered |
| Direction/granularity sync | Task 16 | Covered |
| Exit comparison cleanup | Task 12 | Covered |
| Auto-exit for non-airport types | Task 12 | Covered |
| i18n keys (en-US, fr-CA, en-CA, en-EU) | Tasks 1, 4, 8, 11 | Covered |

### Placeholder Scan

No "TBD", "TODO", "implement later", or "similar to Task N" found. All code blocks are complete.

### Type Consistency Check

- `DEMAND_STATE.showTmiTimeline` — boolean, used in Tasks 1, 2, 3 consistently
- `DEMAND_STATE.showTmiMarkers` — boolean, used in Tasks 1, 2, 3 consistently
- `DEMAND_STATE.filterCarriers` — string array, used in Tasks 4, 6, 7 consistently
- `DEMAND_STATE.comparisonAirports` — string array, used in Tasks 11-16 consistently
- `DEMAND_STATE.comparisonCharts` — Map, used in Tasks 11-16 consistently
- `DEMAND_STATE.comparisonData` — Map, used in Tasks 11-16 consistently
- `DEMAND_STATE.summaryData` — object, used in Tasks 5, 10, 15 consistently
- `buildTmiMarkerLines()` — referenced in Task 3 (definition) and Task 13 (comparison use) with same name
- `applyClientFilters()` — referenced in Tasks 6, 9, 13, 14 consistently
- `renderSummaryCards()` — referenced in Tasks 9, 10, 15 consistently
- `onEnhancedFilterChange()` — referenced in Tasks 6, 7, 15, 16 consistently
- `populateFilterDropdowns()` — referenced in Tasks 5, 6, 7 consistently
- `normalizeTimeBin()` — existing function, used in Tasks 6, 9, 13 consistently
