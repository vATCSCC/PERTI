# Route Symbology Filter Icons Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add facility filter support with new endpoint icons, filter fan connectors, improved hitboxes, and playbook interoperability.

**Architecture:** Parser extracts `(-TOKEN)` groups from route text, renderer creates filter endpoints with prohibition-overlay icons and dense-dotted fan connectors on a new MapLibre layer, playbook.js injects DB filter fields into route strings after mandatory wrapping.

**Tech Stack:** MapLibre GL JS 4.5.0, vanilla JS, PHP, jQuery 2.2.4

**Spec:** `docs/superpowers/specs/2026-03-18-route-symbology-filter-icons-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `assets/js/route-maplibre.js` | Modify | Filter parser, 9 icons, filter fan layer, filter endpoint gen, bbox hitbox |
| `assets/js/route-symbology.js` | Modify | `filterFan` defaults, `applyToMapLibre()` for new layer, panel bindings |
| `assets/js/playbook.js` | Modify | Filter injection in `plotOnMap()` + `openInRoutePage()` |
| `route.php` | Modify | Help dialog, symbology panel filter fan section |
| `assets/locales/en-US.json` | Modify | New i18n keys for filter help + symbology |
| `assets/locales/fr-CA.json` | Modify | French translations for new keys |

---

## Chunk 1: Core Parser + Icons + Fan Layer

### Task 1: Filter Token Parser

**Files:** Modify `assets/js/route-maplibre.js`

- [ ] **Step 1:** Add `extractFilterGroups(routeText)` function near line 730 (before `detectFacilityType`). Scans for `(...)` blocks where all tokens start with `-`. Returns `{ cleanText, filters: [{ code, side }] }`. Side determined by position relative to `>` markers.

- [ ] **Step 2:** Integrate into `processAndDisplayRoutes()` around line 2515 (default path). Call `extractFilterGroups()` on each route line before expansion. Store extracted filters per route for later endpoint/fan generation.

- [ ] **Step 3:** Commit: `feat: add filter token parser for (-TOKEN) syntax`

### Task 2: Endpoint Icons

**Files:** Modify `assets/js/route-maplibre.js:2029-2081`

- [ ] **Step 1:** Replace `airport-origin` and `airport-dest` SVGs with airplane silhouettes (upward/downward pointing). Keep 20x20 canvas, white fill, SDF-compatible.

- [ ] **Step 2:** Add 3 filter variant icons: `airport-filter`, `tracon-filter`, `artcc-filter` — base shape + circle-slash prohibition overlay.

- [ ] **Step 3:** Update `icon-image` case expression (lines 2069-2081): Add 3 filter cases BEFORE the 6 base cases. Guard base cases with `['!=', ['get', 'isFiltered'], true]`.

- [ ] **Step 4:** Commit: `feat: add airplane silhouette + filter prohibition icons`

### Task 3: Filter Fan Layer + Endpoint Rendering

**Files:** Modify `assets/js/route-maplibre.js`

- [ ] **Step 1:** Add `routes-filter-fan` layer after `routes-fan` (line 1951). Filter: `['==', ['get', 'isFilterFan'], true]`, paint: `line-width: 1.0, line-dasharray: [1, 2], line-opacity: 0.5`.

- [ ] **Step 2:** In the route rendering pipeline (around lines 2869-2937), add filter endpoint feature generation. For each extracted filter: resolve coordinates via the existing waypoint resolution, create endpoint feature with `isFiltered: true`, create fan connector segment with `isFilterFan: true`.

- [ ] **Step 3:** Commit: `feat: add filter fan layer and filter endpoint rendering`

---

## Chunk 2: Hitbox + Playbook + Symbology + i18n

### Task 4: Bbox Hitbox

**Files:** Modify `assets/js/route-maplibre.js:3286,3321`

- [ ] **Step 1:** Line 3286: Add `'routes-filter-fan'` to cursor handler array.
- [ ] **Step 2:** Line 3321: Replace `e.point` with bbox `[[e.point.x-5, e.point.y-5], [e.point.x+5, e.point.y+5]]`, add `'routes-filter-fan'` to layers array.
- [ ] **Step 3:** Commit: `feat: improve route click hitbox with 5px bbox tolerance`

### Task 5: Playbook Filter Injection

**Files:** Modify `assets/js/playbook.js`

- [ ] **Step 1:** Add `buildFilterGroup(filterStr)` helper near top of IIFE.
- [ ] **Step 2:** In `plotOnMap()`, inject filter groups AFTER mandatory wrapping (after line 2444). Map `selected` routes to inject `origin_filter`/`dest_filter`.
- [ ] **Step 3:** In `openInRoutePage()`, inject filter groups during parts assembly (lines 2503-2521).
- [ ] **Step 4:** Commit: `feat: inject playbook filter data into route strings`

### Task 6: Symbology Panel + route-symbology.js

**Files:** Modify `assets/js/route-symbology.js`, `route.php`

- [ ] **Step 1:** Add `filterFan` to `DEFAULT_SYMBOLOGY` (after `fan`, line 32).
- [ ] **Step 2:** Add `routes-filter-fan` block in `applyToMapLibre()` (after line 325).
- [ ] **Step 3:** Add `'filterFan'` to `initSettingsPanel()` loop (line 450) — alias to `'filter-fan'` element IDs.
- [ ] **Step 4:** Add filter fan HTML section in `route.php` after line 2173 (before global overrides).
- [ ] **Step 5:** Commit: `feat: add filter fan symbology controls`

### Task 7: Help Dialog + i18n

**Files:** Modify `route.php:1553-1566`, `assets/locales/en-US.json`, `assets/locales/fr-CA.json`

- [ ] **Step 1:** Add filter syntax help item in route.php help panel after line 1560.
- [ ] **Step 2:** Add i18n keys to en-US.json: `route.page.facilityFilters`, `route.page.facilityFiltersDesc`, `route.page.filterFanRadial`.
- [ ] **Step 3:** Add French translations to fr-CA.json.
- [ ] **Step 4:** Commit: `docs: add filter syntax help and i18n translations`

### Task 8: Final Verification

- [ ] **Step 1:** Review all changes for consistency, missing references, or broken patterns.
- [ ] **Step 2:** Verify filter parser handles edge cases: empty `()`, single filter `(-KDFW)`, mixed valid/invalid, no filters.
- [ ] **Step 3:** Create branch, commit, push, PR.
