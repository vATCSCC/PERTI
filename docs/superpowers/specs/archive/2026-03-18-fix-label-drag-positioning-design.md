# Fix Label Drag Positioning Design

**Date**: 2026-03-18
**Status**: Approved
**Scope**: Bug fix — `assets/js/route-maplibre.js`, `assets/js/route-symbology.js`, `assets/js/route-analysis-panel.js`

## Problem

Dragging fix/point labels on the route and playbook maps produces erratic positioning. Labels jump, snap to unexpected positions, and don't stay where the user drags them.

### Root Cause

The `route-fixes-labels` symbol layer uses `text-variable-anchor` (8 positions) with `text-radial-offset: 0.5`. When a label is dragged:

1. The drag handler updates the label's GeoJSON coordinate to the dragged position
2. `updateRouteLabelDisplay()` calls `setData()` on the source, triggering full symbol placement re-evaluation
3. MapLibre re-picks among the 8 anchor positions based on current collision state
4. The label renders at `draggedCoord + 0.5em` in whichever anchor direction MapLibre chose — not where the user dragged it
5. On each drag frame, a different anchor may be chosen, causing visible jumping

Additional issues:
- `text-allow-overlap: false` + `text-optional: true` means dragged labels can vanish if they collide with other labels
- Leader lines calculate from the coordinate, but the visual label is offset by variable-anchor + radial-offset, so they don't connect correctly

### Constraint

`text-variable-anchor` is **not data-driven** in MapLibre GL JS (confirmed via [MapLibre Style Spec](https://maplibre.org/maplibre-style-spec/layers/)). It cannot be conditionally disabled per-feature within a single layer.

## Solution: Two-Layer Split

### New Source and Layer

Add `route-fix-labels-moved` GeoJSON source and `route-fixes-labels-moved` symbol layer for dragged labels:

```js
// Source
graphic_map.addSource('route-fix-labels-moved', {
    type: 'geojson',
    data: { type: 'FeatureCollection', features: [] }
});

// Layer
graphic_map.addLayer({
    id: 'route-fixes-labels-moved',
    type: 'symbol',
    source: 'route-fix-labels-moved',
    layout: {
        'text-field': ['get', 'name'],
        'text-font': ['Noto Sans Bold'],
        'text-size': /* same zoom interpolation as unmoved layer */,
        'text-transform': 'uppercase',
        'text-anchor': 'center',       // Fixed anchor — renders exactly at coordinate
        // NO text-variable-anchor
        // NO text-radial-offset
        'text-allow-overlap': true,     // User-placed labels always visible
        'text-ignore-placement': false, // Still push away unmoved labels
        'text-padding': 2,
        'text-max-width': 50,
    },
    paint: {
        'text-color': ['get', 'color'],
        'text-halo-color': '#000000',
        'text-halo-width': 3,
        'text-halo-blur': 0,
    },
    minzoom: 5,
});
```

### Existing Layer (Unchanged)

`route-fixes-labels` keeps `text-variable-anchor`, `text-radial-offset: 0.5`, `text-allow-overlap: false`, `text-optional: true`. Only receives unmoved features.

### updateRouteLabelDisplay() Changes

Currently builds one `labelFeatures` array. Change to partition into two arrays based on whether `labelOffsets[uniqueKey]` exists:

- `labelFeaturesUnmoved` → `route-fix-labels` source (existing)
- `labelFeaturesMoved` → `route-fix-labels-moved` source (new)

Update the source `setData()` calls to push each array to its respective source.

### Drag Handler Changes

Add `mousedown` handler on `route-fixes-labels-moved` identical to the existing one on `route-fixes-labels`. Both feed into the same `draggingLabel` / `labelOffsets` state.

Add `mouseenter`/`mouseleave` cursor handlers on `route-fixes-labels-moved`.

### Layer Toggle Group

Line 2338 — add `'route-fixes-labels-moved'` to the `route_labels` layer group:

```js
'route_labels': {
    layerIds: ['route-fixes-circles', 'route-fixes-labels', 'route-fixes-labels-moved', 'route-fix-leaders-lines'],
    ...
}
```

### Symbology Integration

`route-symbology.js` line 364 — add to the `labelLayerIds` array:

```js
const labelLayerIds = ['route-fixes-labels', 'route-fixes-labels-moved'];
```

This ensures user-configured text size, halo width, color, and visibility apply to both layers.

### Route Analysis Panel Dimming

`route-analysis-panel.js` — three locations (lines 685-686, 697-698, 891-892) set `text-opacity` on `route-fixes-labels`. Add matching calls for `route-fixes-labels-moved` at each location.

### Leader Lines

No logic change needed. `getLeaderLineStart()` calculates from the label's coordinate. With `text-anchor: 'center'` on the moved layer, the visual center IS the coordinate — the calculation is now accurate.

## Files Changed

| File | Change |
|------|--------|
| `assets/js/route-maplibre.js` | Add source/layer, partition features in `updateRouteLabelDisplay()`, add drag handlers, update layer toggle group |
| `assets/js/route-symbology.js` | Add `'route-fixes-labels-moved'` to `labelLayerIds` array |
| `assets/js/route-analysis-panel.js` | Add opacity dimming for `'route-fixes-labels-moved'` at 3 locations |

## What Doesn't Change

- `route-fix-points` source/layer (circles) — unchanged
- `route-fix-leaders` source/layer (leader lines) — unchanged
- State: `routeFixesByRouteId`, `routeLabelsVisible`, `labelOffsets` — unchanged
- `toggleRouteLabelsForRoute()`, `toggleAllLabels()`, `resetLabelPositions()` — unchanged (they call `updateRouteLabelDisplay()`)
- Initial render (lines 2921-3009) — labels start unmoved, all go to existing layer; moved layer starts empty
