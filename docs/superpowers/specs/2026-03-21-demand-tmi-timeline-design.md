# Demand TMI Timeline Bar

## Summary

Replace the vertical ECharts mark lines for GS/GDP programs on demand.php charts with a horizontal timeline bar rendered above the chart. Programs appear as colored time-range bars with appropriate visual treatment for active, completed, cancelled, and updated states.

## Current State

`buildTmiProgramMarkLines()` in `demand.js` renders GS/GDP programs as vertical mark lines on the ECharts demand chart. Each program produces up to 3 lines (start, update, end/cnx) with stacked labels. This clutters the chart and competes with demand bars, rate lines, and the NOW marker.

## Design

### Placement

The timeline bar sits **above the chart, below the view toggle controls**, inside `div.demand-chart-wrapper`. It is only visible when TMI programs exist for the current airport and time window.

### HTML Structure

```html
<div id="demand_tmi_timeline" class="demand-tmi-timeline" style="display: none;">
  <div class="tmi-timeline-track">
    <!-- Time tick marks along top edge -->
    <div class="tmi-timeline-ticks"></div>
    <!-- Program bars rendered here -->
    <div class="tmi-timeline-bars">
      <div class="tmi-bar tmi-bar-gs tmi-bar-active" style="left: 20%; width: 15%;"
           title="GS #42 JFK | 1400Z-1530Z | ACTIVE">
        <span class="tmi-bar-label">GS #42 JFK</span>
        <span class="tmi-bar-marker tmi-marker-update" style="left: 60%;"></span>
      </div>
    </div>
    <!-- NOW line -->
    <div class="tmi-timeline-now" style="left: 45%;"></div>
  </div>
</div>
```

### Program Types Shown

Only GS and GDP (all variants: GDP-DAS, GDP-GAAP, GDP-UDP). No AFP, MIT, or other TMI types.

### Visual Treatment by Status

| Status | Bar Style | End Treatment |
|--------|-----------|---------------|
| ACTIVE GS | Solid `#dc3545` (red), full opacity | Right edge at NOW or `end_utc` |
| ACTIVE GDP | Solid `#ffc107` (amber) for DAS, `#ff9800` for GAAP, `#ff5722` for UDP | Right edge at `end_utc` |
| COMPLETED | Same base color at 40% opacity | Right edge at `end_utc` |
| CANCELLED (PURGED) | Same base color at 40% opacity, diagonal hatch overlay | Right edge at `purged_at`, "CNX" label |
| Updated | Diamond marker (4px rotated square) at `updated_at` position on the bar | Normal end treatment |

### Color Map

```javascript
const TMI_TIMELINE_COLORS = {
    'GS':       { bg: '#dc3545', border: '#b02a37' },
    'GDP':      { bg: '#ffc107', border: '#d4a106' },
    'GDP-DAS':  { bg: '#ffc107', border: '#d4a106' },
    'GDP-GAAP': { bg: '#ff9800', border: '#e68900' },
    'GDP-UDP':  { bg: '#ff5722', border: '#e64a19' },
};
```

### Time Axis Alignment

The bar uses the same time window as the demand chart: `DEMAND_STATE.currentStart` and `DEMAND_STATE.currentEnd`. Program positions are calculated as percentages:

```javascript
const pctLeft = (startMs - chartStartMs) / (chartEndMs - chartStartMs) * 100;
const pctWidth = (endMs - startMs) / (chartEndMs - chartStartMs) * 100;
```

Programs partially outside the window are clamped to 0%/100%.

### NOW Line

A thin dashed red vertical line at the current time position, spanning the full height of the timeline bar. Uses the same percentage calculation.

### Tooltip

On hover, show: program type, ID, control element, start/end times (Zulu), status, avg delay if available. Use the existing `title` attribute for simplicity (no external tooltip library needed).

### Bar Labels

When the bar is wide enough (>80px rendered), show an inline label: `"GS #42 JFK"`. Otherwise, rely on the tooltip.

### Sizing

- Timeline bar height: 32px (single row) or 56px (two rows if programs overlap)
- Bar height: 20px with 2px gap
- Overlap detection: if two programs overlap in time, stack them on separate rows
- Max rows: 3 (additional programs hidden with "+N more" indicator)

### Refresh Lifecycle

The timeline renders/updates whenever `buildTmiProgramMarkLines()` was previously called — specifically in `renderDemandChart()` and `renderStatusChart()`. The new function `renderTmiTimeline()` replaces the mark line integration.

### What Gets Removed

- `buildTmiProgramMarkLines()` function body — gutted, returns empty array (keep function signature for safety)
- TMI mark line integration in both `renderDemandChart()` and `renderStatusChart()` — stop adding TMI lines to `markLineData`
- The label overlap detection logic (no longer needed)

### CSS

```css
.demand-tmi-timeline {
    position: relative;
    margin-bottom: 4px;
    background: #f8f9fa;
    border: 1px solid #d0d0d0;
    border-bottom: none;
    border-radius: 3px 3px 0 0;
    overflow: hidden;
    font-family: "Inconsolata", "SF Mono", monospace;
    font-size: 10px;
}

.tmi-timeline-track {
    position: relative;
    min-height: 32px;
    padding: 4px 0;
}

.tmi-bar {
    position: absolute;
    height: 20px;
    border-radius: 2px;
    border: 1px solid;
    cursor: default;
    overflow: hidden;
    white-space: nowrap;
    line-height: 20px;
    padding: 0 4px;
}

.tmi-bar-completed, .tmi-bar-cancelled {
    opacity: 0.4;
}

.tmi-bar-cancelled {
    background-image: repeating-linear-gradient(
        -45deg, transparent, transparent 3px,
        rgba(0,0,0,0.15) 3px, rgba(0,0,0,0.15) 6px
    );
}

.tmi-timeline-now {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 0;
    border-left: 2px dashed #dc3545;
    z-index: 10;
    pointer-events: none;
}
```

### i18n

New keys in `en-US.json` under `demand.tmiTimeline`:
- `demand.tmiTimeline.cnx` — "CNX"
- `demand.tmiTimeline.active` — "ACTIVE"
- `demand.tmiTimeline.completed` — "COMPLETED"
- `demand.tmiTimeline.cancelled` — "CANCELLED"

### Data Source

Same API as today: `api/demand/tmi_programs.php` — already fetched and stored in `DEMAND_STATE.tmiPrograms`. No new API calls needed.

## Files Modified

| File | Change |
|------|--------|
| `assets/js/demand.js` | Add `renderTmiTimeline()`, gut `buildTmiProgramMarkLines()`, remove TMI mark line integration from both chart render functions |
| `demand.php` | Add timeline container HTML above chart, add CSS |
| `assets/locales/en-US.json` | Add `demand.tmiTimeline.*` keys |

## Not In Scope

- Chart.js dependency (demand.php stays ECharts-only)
- Click-to-load-program (GDT has this; demand doesn't need it)
- Facility-level demand charts (TRACON/ARTCC) — timeline only shows for airport demand where TMI programs are fetched
- Scope conflict detection (GDT feature, not needed here)
