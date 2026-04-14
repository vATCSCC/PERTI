# Demand Page Enhancements — Design Spec

**Date**: 2026-03-29
**Scope**: 4 features for `demand.php` + `assets/js/demand.js`
**Approach**: Extend existing demand.js monolith (Approach 1) — leverage `DemandChartCore` for multi-chart

### Codebase Reference (Verified 2026-03-29)
- `DemandChartCore`: IIFE at demand.js L66-823, alias `window.DemandChart` at L826-831
- `DEMAND_STATE`: 57 top-level properties, initialized at L838-910
- Rate line defaults: `showVatsimAar: true`, `showVatsimAdr: true`, `showRwAar: true`, `showRwAdr: true`
- Two `buildRateMarkLinesForChart()` functions: DemandChartCore version (L293, 3 params) and **page-level version (L5029, 0 params, reads DEMAND_STATE)**. The page-level version is the one used by `renderChart()` and the one we extend.
- `summary.php` is loaded on every data refresh (separate AJAX call after `Promise.allSettled`, L3065-3068), NOT lazy-loaded on UI expand. The optimization for Feature 2 is moving it INTO the Promise.allSettled block for true parallelism.
- `DemandChartCore.createChart()` creates fully independent instances (own state, own fetch, own resize handler, no global conflicts). However, all page-level orchestration (TMI timeline, info bar, rate display, summary rendering) is coupled to the `DEMAND_STATE` singleton. Comparison mode requires per-airport state maps and parameterized rendering functions.

---

## Feature 1: TMI Visibility Toggles

### Problem
The TMI timeline bar and GS/GDP event markers have no visibility control. TMU users cannot hide them when focusing on raw demand. Additionally, vertical marker lines for GS/GDP start/end/update times do not exist on the chart itself — only the horizontal timeline bar is rendered.

### Design

**Two new checkbox groups in the Legend card sidebar**, below the existing "Rate Lines" section:

```
─── TMI Overlays ───
☑ TMI Timeline       (horizontal program bar above chart)
☑ GS/GDP Markers     (vertical markLines on chart)
```

#### TMI Timeline Toggle
- Controls visibility of `#demand_tmi_timeline` DOM element via show/hide
- State: `DEMAND_STATE.showTmiTimeline` (default `true`)
- No chart re-render needed — pure DOM visibility toggle

#### GS/GDP Marker Lines (New Feature)
Adds ECharts `markLine` entries on the first series, using the same pattern as the NOW marker and rate lines.

**Data source**: `DEMAND_STATE.tmiPrograms` (already fetched from `api/demand/tmi_programs.php`)

**Line types**:
| Event | Line Style | Color | Label |
|-------|-----------|-------|-------|
| GS start | 2px solid | `#dc3545` (red) | "GS" |
| GS end | 2px solid | `#dc3545` (red) | "GS END" |
| GDP start | 2px solid | `#d4a574` (amber) | "GDP" |
| GDP end | 2px solid | `#d4a574` (amber) | "GDP END" |
| Cancelled | 2px dashed | `#6c757d` (gray) | "CNX" |
| Updated | 1px dotted | `#495057` (dark gray) | "UPD" |

**Label collision avoidance**: When two or more markers fall within the same ~30px horizontal band (calculated as `|x1 - x2| < 30px` based on chart pixel width / time range), their labels use **vertical staggering** via ECharts `markLine.label.distance` with increasing offsets: `[0, -20, -40, -60]`. Labels alternate between top-positioned and progressively offset upward. The collision detection runs once during the page-level `buildRateMarkLinesForChart()` (L5029) by sorting all markLine x-positions and grouping nearby ones. This applies to ALL markLine labels (rate lines + TMI markers + NOW marker) for consistent non-overlapping display.

**State**: `DEMAND_STATE.showTmiMarkers` (default `true`)

**Integration point**: Built inside the **page-level** `buildRateMarkLinesForChart()` (L5029, reads from DEMAND_STATE) alongside existing rate line construction. This is the function used by `renderChart()` — NOT the DemandChartCore version at L293 which takes explicit parameters. The page-level function already returns an array of markLine data objects that get attached to `series[0].markLine`; TMI markers are appended to this array when `showTmiMarkers` is true.

#### i18n Keys
- `demand.page.tmiOverlays` — "TMI Overlays"
- `demand.page.tmiTimelineToggle` — "TMI Timeline"
- `demand.page.tmiMarkers` — "GS/GDP Markers"

#### Files Changed
- `demand.php`: Legend card HTML (new checkbox group)
- `demand.js`: State properties, `buildRateMarkLinesForChart()` extension, toggle event handlers
- `assets/locales/en-US.json`, `fr-CA.json`, `en-CA.json`, `en-EU.json`

---

## Feature 2: Enhanced Filters

### Problem
Current filters are limited to single-select dropdowns for airport category, ARTCC, and tier. TMU users need to filter demand by carrier, weight class, equipment type, and origin/destination ARTCC to analyze specific traffic flows.

### Design

Three new filter groups in the sidebar, positioned below the Direction toggle and above the `<hr>` before phase filters.

#### 2a. Carrier/Airline Filter
- **UI**: Select2 multi-select dropdown (Select2 already loaded via CDN)
- **Population**: Dynamically populated from `summary.php` carrier breakdown — extract unique carrier codes from the response
- **State**: `DEMAND_STATE.filterCarriers` (array of strings, empty = all)
- **Placeholder**: "All Carriers"

#### 2b. Weight Class / Equipment Filter
- **Weight class**: Inline checkbox group with colored indicator dots:
  - `☑ H` (red) `☑ L` (blue) `☑ S` (green) `☑ +` (purple)
  - All checked by default
- **Equipment type**: Select2 multi-select, populated from `summary.php` equipment breakdown
- **State**: `DEMAND_STATE.filterWeightClasses` (array, empty = all), `DEMAND_STATE.filterEquipment` (array, empty = all)

#### 2c. Origin/Destination ARTCC Multi-Select
- **UI**: Two Select2 multi-select dropdowns stacked:
  - "Origin ARTCC/FIR" — filters arrivals by originating ARTCC
  - "Dest ARTCC/FIR" — filters departures by destination ARTCC
- **Population**: From `summary.php` `origin_artcc_breakdown` and `dest_artcc_breakdown`
- **Direction-aware**: When direction=arr, only origin filter is relevant (dest filter grayed). When direction=dep, only dest filter is relevant. When direction=both, both active on their respective stacks.
- **State**: `DEMAND_STATE.filterOriginArtccs` (array), `DEMAND_STATE.filterDestArtccs` (array)

#### Filter Application Architecture

All filters apply **client-side** at the series construction stage. No new API calls needed — `summary.php` already returns per-bin breakdowns for all dimensions.

```
Raw API data → applyClientFilters(data, state) → buildPhaseSeriesTimeAxis() → ECharts
```

`applyClientFilters()` takes the time-bin data and the summary breakdown data, and produces a filtered copy where counts are adjusted to reflect only matching flights. The function uses the breakdown data (carrier per bin, weight per bin, etc.) to compute the fraction of flights matching the active filters, then scales the phase counts proportionally.

**Summary data loading change**: Currently `summary.php` is loaded on every data refresh but as a separate sequential AJAX call after the main `Promise.allSettled` block completes (L3065-3068 in `loadDemandData()`). Since filters now depend on this data being available at render time, summary loading is moved INTO the main `Promise.allSettled` block for true parallel fetching alongside `airport.php`, `rates.php`, `atis.php`, `active_config.php`, `scheduled_configs.php`, and `tmi_programs.php`.

#### Filter Reset
- A "Reset Filters" link appears below the filter section when any filter is active
- Clears all new filters in one click, triggers re-render

#### URL State Persistence
All filter values included in URL hash: `#...&carriers=AAL,DAL&weight=H,L&origins=ZNY,ZBW`

#### i18n Keys
- `demand.page.carrierFilter` — "Carrier"
- `demand.page.weightClassFilter` — "Weight Class"
- `demand.page.equipmentFilter` — "Equipment"
- `demand.page.originArtccFilter` — "Origin ARTCC/FIR"
- `demand.page.destArtccFilter` — "Dest ARTCC/FIR"
- `demand.page.resetFilters` — "Reset Filters"
- `demand.page.allCarriers` — "All Carriers"
- `demand.page.allEquipment` — "All Equipment"
- `demand.page.allWeightClasses` — "All Weight Classes"

#### Files Changed
- `demand.php`: Filter sidebar HTML (3 new form-groups, reset link)
- `demand.js`: State properties, `applyClientFilters()` function, Select2 initialization, filter change handlers, eager summary loading, URL state read/write
- `assets/locales/en-US.json`, `fr-CA.json`, `en-CA.json`, `en-EU.json`

---

## Feature 3: Enhanced Flight Summary — 3-Column Card Grid

### Problem
The current flight summary shows only "Top Origins" and "Top Carriers" in basic tables. TMU users need richer operational stats: peak demand vs capacity, TMI control metrics, weight mix, and fix distribution.

### Design

Replace the 2-column layout with a **6-card grid** using `col-md-4` (3 columns desktop, stacks mobile). Same collapsible wrapper with TBFM dark header. Auto-expanded when data exists.

#### Card 1: Peak Hour
- **Source**: Client-side scan of `DEMAND_STATE.lastDemandData` time bins for highest total
- **Display**:
  - Large monospace time range (e.g., `14:00–15:00Z`)
  - "52 arr | 38 dep" count line
  - Warning badge: amber "AAR exceeded by 8" if peak arrivals > AAR (from `DEMAND_STATE.rateData`), green "Within capacity" otherwise
- **Color**: Red text if exceeded, green if within

#### Card 2: TMI Control
- **Source**: Phase breakdown sums across all bins (`actual_gs`, `simulated_gs`, `actual_gdp`, `simulated_gdp`, `exempt`)
- **Display**:
  - GDP controlled count
  - GS stopped count
  - Exempt count
  - Average delay (minutes) — computed from GDP program data if available
  - Max delay
  - Compliance % (if GDP active)

#### Card 3: Weight Mix
- **Source**: `summary.php` weight breakdown, aggregated across all bins
- **Display**:
  - H / L / S / + rows with percentage text + horizontal progress bar
  - Color-coded: Heavy=`#dc2626`, Large=`#3b82f6`, Small=`#22c55e`, Super=`#9333ea`
  - Count in parentheses: "H 28% (42)"

#### Card 4: Top Origins
- **Source**: `summary.php` `top_origins` (top 5)
- **Display**: Compact rows — ARTCC code left, count right, top row highlighted
- **Interactive**: Click an ARTCC code to apply it as an origin filter (ties into Feature 2)

#### Card 5: Top Carriers
- **Source**: `summary.php` `top_carriers` (top 5)
- **Display**: Same compact format
- **Interactive**: Click a carrier to apply as carrier filter

#### Card 6: Top Arrival/Departure Fixes
- **Source**: `summary.php` `arr_fix_breakdown` or `dep_fix_breakdown`, aggregated and sorted (top 5)
- **Display**: Same compact format
- **Contextual**: Card title and data switch based on direction filter:
  - direction=arr or both → "Top Arr Fixes" (arr_fix_breakdown)
  - direction=dep → "Top Dep Fixes" (dep_fix_breakdown)

#### Card Component Pattern
All 6 cards share the same structure:
- TBFM dark mini-header: icon + title text, `background: #2c3e50`, white text, 9px uppercase
- White body with 6-8px padding
- Border: `1px solid #bdc3c7`, `border-radius: 4px`
- Update on every data refresh (same cycle as chart)
- Respect active client-side filters from Feature 2
- Show `--` placeholders when no data

#### i18n Keys
- `demand.summary.peakHour` — "Peak Hour"
- `demand.summary.tmiControl` — "TMI Control"
- `demand.summary.weightMix` — "Weight Mix"
- `demand.summary.topOrigins` — "Top Origins"
- `demand.summary.topCarriers` — "Top Carriers"
- `demand.summary.topArrFixes` — "Top Arr Fixes"
- `demand.summary.topDepFixes` — "Top Dep Fixes"
- `demand.summary.gdpControlled` — "GDP Controlled"
- `demand.summary.gsStopped` — "GS Stopped"
- `demand.summary.exempt` — "Exempt"
- `demand.summary.avgDelay` — "Avg Delay"
- `demand.summary.maxDelay` — "Max Delay"
- `demand.summary.compliance` — "Compliance"
- `demand.summary.exceededBy` — "AAR exceeded by {count}"
- `demand.summary.withinCapacity` — "Within capacity"
- `demand.summary.noData` — "No data"

#### Files Changed
- `demand.php`: Replace flight summary HTML (remove old 2-col, add 6-card grid)
- `demand.js`: 6 card rendering functions, eager summary data loading, click-to-filter handlers
- `assets/locales/en-US.json`, `fr-CA.json`, `en-CA.json`, `en-EU.json`

---

## Feature 4: Multi-Chart Comparison Mode

### Problem
TMU users managing multiple airports (e.g., NY metroplex: KJFK/KEWR/KLGA/KTEB) cannot compare demand patterns side-by-side. They must manually switch between airports.

### Design

#### 4a. Comparison Mode Toggle

**UI in filter sidebar**, directly below the airport selector:

```
[Airport Selector dropdown          ▼]
☐ Compare   [+ Add Airport]
```

- **Unchecked** (default): Current single-airport behavior
- **Checked**: Comparison mode:
  - Current airport becomes the first comparison airport
  - Airport dropdown becomes "Add airport" — selecting adds to the comparison list
  - Chip/tag bar appears: `[KJFK ×] [KEWR ×] [KLGA ×]`
  - `×` removes an airport from comparison
  - `+ Add Airport` button disabled at 4 airports
  - Unchecking "Compare" exits to single-airport mode (keeps first airport)

**State**:
- `DEMAND_STATE.comparisonMode` (bool, default `false`)
- `DEMAND_STATE.comparisonAirports` (array of ICAO strings, max 4)
- `DEMAND_STATE.comparisonCharts` (Map of ICAO → ECharts instance)
- `DEMAND_STATE.comparisonData` (Map of ICAO → { demandData, summaryData, tmiPrograms, rateData, atisData, dataHash, summaryDataHash })

**Architectural note**: `DemandChartCore.createChart()` supports fully independent multi-instance creation (no global state conflicts). However, the page-level orchestration — `renderChart()`, `renderTmiTimeline()`, `updateInfoBar()`, `updateTopOrigins()`, `updateTopCarriers()`, `buildRateMarkLinesForChart()`, and all breakdown renderers — currently reads directly from `DEMAND_STATE` singleton properties (`lastDemandData`, `rateData`, `tmiPrograms`, etc.). For comparison mode, these functions must be parameterized to accept a data context object instead of reading globals. The `comparisonData` map serves as the per-airport data context.

#### 4b. Chart Grid Layout

**Container**: `#demand_chart_grid` replaces `#demand_chart` when in comparison mode. Dynamically creates child divs.

| Count | Layout | Panel Height |
|-------|--------|-------------|
| 1 | Full width (current) | 480px |
| 2 | Side-by-side 50/50 | 380px |
| 3 | 2×2 grid (one empty slot with `+ Add` button) | 340px |
| 4 | 2×2 grid | 340px |
| 5+ (future) | Full-width horizontal strips | 250px |

**Each panel contains**:
1. Compact header bar: `KJFK` (bold, 0.85rem) + airport name (muted, 0.65rem) + `AAR 44 | ADR 38` (monospace) + weather badge
2. TMI timeline bar (if `showTmiTimeline` true and airport has programs) — compact height (20px vs 28px)
3. ECharts instance — independent, using same chart options pattern as single mode
4. No per-panel legend

**Shared legend**: One legend below the grid controls all charts. Toggling a legend item calls `setOption({ legend: { selected } })` on all chart instances.

**Grid CSS**: CSS Grid with `grid-template-columns: 1fr 1fr` and `gap: 8px`. Single airport uses `grid-template-columns: 1fr`.

#### 4c. Synchronized Behavior

**Time axis sync**:
- All panels share the same time range from the sidebar
- DataZoom sync: On `datazoom` event from any chart, read the `startValue`/`endValue` and call `setOption({ dataZoom: [{ startValue, endValue }] })` on all other instances
- Debounced (50ms) to avoid cascade loops — use a `_syncingZoom` flag

**Filter sync**:
- All sidebar filters apply to ALL panels simultaneously
- Chart view toggle (Status/Origin/Dest...) applies to all panels

**Independent per-panel**:
- TMI timeline bars (each airport has its own programs)
- Rate lines (each airport has its own AAR/ADR from its own `rates.php` response)
- Tooltip (hover one chart, only that chart's tooltip shows)
- Y-axis scaling (each chart scales independently to its own data range)

#### 4d. Data Fetching

Each airport triggers its own parallel API calls:

```javascript
// For each airport in comparisonAirports:
Promise.allSettled([
  fetch(`api/demand/airport.php?airport=${icao}&...`),
  fetch(`api/demand/summary.php?airport=${icao}&...`),
  fetch(`api/demand/tmi_programs.php?airport=${icao}&...`),
  fetch(`api/demand/rates.php?airport=${icao}&...`)
])
```

All airports fetched in a single outer `Promise.allSettled()` for maximum parallelism. Hash-based caching works per-airport: `comparisonData[icao].dataHash`.

Auto-refresh (15s) refreshes all airports simultaneously.

#### 4e. Info Bar Adaptation

In comparison mode:
- **Airport card**: Shows chip list of selected airports instead of single airport display
- **Config card**: Hides (too airport-specific; per-airport rates visible in panel headers)
- **ATIS card**: Hides (same reason)
- **Arrival/Departure stat cards**: Show **aggregate** totals across all comparison airports
- **Refresh card**: Unchanged

#### 4f. Enhanced Stats in Comparison Mode

- 6-card summary grid shows stats for one airport at a time
- Airport tab strip above the cards: `[KJFK] [KEWR] [KLGA]` — click to switch
- First airport is selected by default
- Peak Hour card gains a comparative note: "KEWR peaks at 15Z" when viewing KJFK stats

#### 4g. URL State

Comparison state persists in URL hash:
```
#compare=KJFK,KEWR,KLGA&granularity=hourly&direction=both&...
```

Restoring a comparison URL enters comparison mode with all airports. Single airport mode uses existing `#airport=KJFK` format.

#### 4h. Exiting Comparison

- Uncheck "Compare" → exits to single-airport mode, keeps first airport selected
- Remove all airports via `×` buttons → auto-exits comparison mode
- Selecting a different demand type (TRACON/ARTCC/Group) → auto-exits comparison mode

#### i18n Keys
- `demand.compare.enable` — "Compare"
- `demand.compare.addAirport` — "Add Airport"
- `demand.compare.maxReached` — "Maximum 4 airports"
- `demand.compare.remove` — "Remove {airport}"
- `demand.compare.aggregate` — "Aggregate"

#### Files Changed
- `demand.php`: Comparison toggle HTML, chip bar, chart grid container, info bar conditionals, stats tab strip
- `demand.js`: Comparison state, multi-chart instantiation/teardown, parallel data fetching, zoom sync, info bar adaptation, stats tab switching, URL state read/write, exit/cleanup logic
- `assets/css/demand-comparison.css` (or inline in `demand.php`): Grid layout, panel headers, chip bar, tab strip
- `assets/locales/en-US.json`, `fr-CA.json`, `en-CA.json`, `en-EU.json`

---

## Implementation Order

1. **Feature 1 — TMI Toggles**: Lowest risk, self-contained. Establishes the markLine pattern that Feature 4 will reuse per-panel.
2. **Feature 2 — Enhanced Filters**: Requires eager summary loading (changes data flow). Foundation for Feature 3's interactive click-to-filter.
3. **Feature 3 — Enhanced Stats**: Depends on eager summary data from Feature 2. Replaces existing HTML.
4. **Feature 4 — Comparison Mode**: Largest feature, depends on all others being stable. Multi-chart instantiation, data orchestration, zoom sync.

## Files Summary

| File | Features | Changes |
|------|----------|---------|
| `demand.php` | 1,2,3,4 | Legend toggles, filter HTML, stats grid, comparison toggle/grid |
| `assets/js/demand.js` | 1,2,3,4 | State, markLines, filters, stats rendering, comparison orchestration |
| `assets/locales/en-US.json` | 1,2,3,4 | ~35 new keys |
| `assets/locales/fr-CA.json` | 1,2,3,4 | ~35 new keys (French translations) |
| `assets/locales/en-CA.json` | 2,4 | ~5 overlay keys (ARTCC→FIR terminology) |
| `assets/locales/en-EU.json` | 2,4 | ~5 overlay keys (ARTCC→ACC terminology) |

No new API endpoints needed. No database changes. No new PHP files.

## Audit Corrections (2026-03-29)

Issues found during codebase verification and corrected in this spec:

1. ~~DEMAND_STATE has 93 properties~~ → **57 top-level properties** (L838-910)
2. ~~`showRwAar`/`showRwAdr` default to `false`~~ → **Default `true`** (L878-879)
3. ~~summary.php is lazy-loaded on UI expand~~ → **Loaded every refresh, just not in Promise.allSettled** (L3065-3068)
4. ~~Single `buildRateMarkLinesForChart()`~~ → **Two versions**: DemandChartCore (L293, 3 params) and page-level (L5029, reads globals). We extend the page-level version.
5. ~~Comparison is "just instantiate N DemandChartCore"~~ → **DemandChartCore multi-instance is clean, but page-level rendering functions are singleton-coupled**. Comparison mode requires parameterizing `renderChart()`, `renderTmiTimeline()`, `buildRateMarkLinesForChart()` etc. to accept a data context instead of reading `DEMAND_STATE` directly.
