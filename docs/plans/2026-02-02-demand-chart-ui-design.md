# Demand Chart UI Improvements

## Overview

Fix legend/chart interaction and improve rate label display in the demand chart.

## Problem Statement

1. **Legend height variability**: When filtering by Origin ARTCC or other multi-item categories, the legend can have 15+ items. The variable legend height pushes the chart around and sometimes overlaps the x-axis label.

2. **AAR/ADR label issues**: Rate labels positioned at the right edge of rate lines get cut off, overlap each other (when showing 4 rates), and obscure the rightmost bars.

## Design

### 1. Legend System Overhaul

**New behavior:**

- **Fixed-height legend area** below the chart with max height of ~60px (fits 2-3 rows)
- **Multi-row layout** - items wrap naturally within the container
- **Scroll/arrows for overflow** - when items exceed max height, ECharts `type: 'scroll'` with constrained height
- **Toggle button inline** - "Hide Legend" link at end of legend area; when hidden, shows "Show Legend" in same spot
- **Shown by default** - toggle state persisted in localStorage

**Chart grid adjustment:**

- `grid.bottom` becomes fixed value (no longer varies based on legend row count)
- Ensures stable chart height regardless of legend content

### 2. AAR/ADR Rate Display

**Remove labels from rate lines:**

- Horizontal rate lines remain (solid for AAR, dashed for ADR)
- No more pill-badge labels at line ends
- Reduces `grid.right` padding from 70px to ~40px

**Rate values in chart header:**

Stacked vertically, right-aligned in header:

```
Refreshed: 10:22:26 PM | ADL: 13:19
VATSIM AAR 42 | RW AAR 38
VATSIM ADR 45 | RW ADR 40
```

- Top row: Refresh timestamp (existing)
- Middle row: Arrival rates (AAR) - VATSIM and RW side by side
- Bottom row: Departure rates (ADR) - VATSIM and RW side by side
- VATSIM rates: black text
- RW rates: cyan text (#00FFFF)
- Only shows rates that are toggled on in Rate Lines checkboxes
- Omits any rate that's null/unavailable

**Tooltip unchanged:**

- Continues showing rate values on hover for context

**Line styling unchanged:**

- Solid black: VATSIM AAR
- Dashed black: VATSIM ADR
- Solid cyan: RW AAR
- Dashed cyan: RW ADR

## Files to Modify

| File | Changes |
|------|---------|
| `demand.php` | Add CSS for legend toggle, rate display in header |
| `assets/js/demand.js` | Legend config (fixed height, scroll), remove rate line labels, add header rate display logic |

## Implementation Notes

1. Legend toggle state key: `demand_legend_visible` in localStorage
2. If full rate labels too wide, fallback to shorter format: `V-AAR 42 | RW-AAR 38`
3. Rate display updates on same refresh cycle as chart data
