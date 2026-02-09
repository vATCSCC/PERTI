# Codebase Globalization — Dependency Tree

> **Purpose**: Maps every centralized source file to its consumers, load order requirements, and fallback behavior. Use this as a reference when modifying any globalization source or consumer file.
>
> **Last audited**: 2026-02-08 (v1.6.0)

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Load Order — header.php](#2-load-order--headerphp)
3. [JS Dependency Tree — perti.js](#3-js-dependency-tree--pertijs)
4. [JS Dependency Tree — facility-hierarchy.js](#4-js-dependency-tree--facility-hierarchyjs)
5. [JS Dependency Tree — colors.js](#5-js-dependency-tree--colorsjs)
6. [CSS Dependency Tree — perti-colors.css](#6-css-dependency-tree--perti-colorscss)
7. [PHP Dependency Tree — perti_constants.php](#7-php-dependency-tree--perti_constantsphp)
8. [Null-Safety Contract](#8-null-safety-contract)
9. [Page-Level Load Matrix](#9-page-level-load-matrix)

---

## 1. Architecture Overview

Three centralized source layers, each with a different mechanism:

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CENTRALIZED SOURCES                         │
├──────────────────┬──────────────────────┬───────────────────────────┤
│  JS Namespace    │  CSS Custom Props    │  PHP Constants            │
│  perti.js        │  perti-colors.css    │  perti_constants.php      │
│  colors.js       │  (~210 tokens)       │  (17 constants + 1 fn)   │
│  facility-       │                      │                           │
│  hierarchy.js    │                      │                           │
├──────────────────┼──────────────────────┼───────────────────────────┤
│  Loaded via      │  Loaded via          │  Loaded via               │
│  <script> in     │  <link> in           │  require_once per-file    │
│  header.php      │  header.php          │  (no shared include)      │
├──────────────────┼──────────────────────┼───────────────────────────┤
│  Fallback:       │  Fallback:           │  Fallback:                │
│  typeof check +  │  CSS resolves at     │  N/A (fatal if missing)   │
│  hardcoded value │  computed-value time │                           │
└──────────────────┴──────────────────────┴───────────────────────────┘
```

---

## 2. Load Order — header.php

### CSS (lines 62–66)

```
① perti-colors.css     ← DEFINES all custom properties (~210 tokens)
② theme.css            ← Consumes ~536 var() references
③ perti_theme.css      ← Consumes ~55 var() references
④ tmi-compliance.css   ← Consumes ~229 var() references (+ 9 local tokens)
⑤ mobile.css           ← Consumes ~39 var() references (+ 4 local tokens)
```

Page-specific CSS is loaded later via individual PHP pages:
- `initiative_timeline.css` (plan.php) — 111 var() refs
- `tmi-publish.css` (tmi-publish.php) — 85 var() refs + 6 local tokens
- `info-bar.css` (multiple pages) — 50 var() refs + 14 local tokens
- `weather_radar.css` (route.php, gdt.php) — 19 var() refs
- `weather_hazards.css` (route.php) — 34 var() refs
- `weather_impact.css` (route.php) — 33 var() refs

### JS (lines 82–84)

```
① perti.js              ← DEFINES window.PERTI namespace (2,200+ lines)
② colors.js             ← DEFINES PERTIColors (consumes PERTI.GEOGRAPHIC)
③ facility-hierarchy.js  ← DEFINES FacilityHierarchy (consumes PERTI.*)
```

All three load in `<head>` via header.php, available to every page's `<body>` scripts.

---

## 3. JS Dependency Tree — perti.js

**Source**: `assets/js/lib/perti.js`
**Global export**: `window.PERTI`

### Namespace Sections Consumed

```
PERTI
├── .FACILITY
│   ├── .FACILITY_NAME_MAP          → tmi-publish.js
│   ├── .CROSS_BORDER_FACILITIES    → tmi-publish.js
│   ├── .FACILITY_LISTS
│   │   ├── .ARTCC_CONUS            → tmi-publish.js (×2), plan.js
│   │   └── .ARTCC_ALL              → nod-demand-layer.js
│   ├── .AIRLINE_CODES              → gdt.js
│   └── .REGIONAL_CARRIERS          → config/constants.js
│
├── .UI
│   ├── .ARTCC_COLORS               → demand.js, nod.js, route-maplibre.js, filter-colors.js
│   ├── .ARTCC_LABELS               → filter-colors.js
│   ├── .CARRIER_COLORS             → demand.js, route-maplibre.js, filter-colors.js
│   ├── .DEMAND_COLORS              → nod-demand-layer.js
│   └── .WEATHER_IMPACT_COLORS      → weather_impact.js
│
├── .GEOGRAPHIC
│   ├── .DCC_REGIONS                → colors.js, facility-hierarchy.js, nod.js
│   ├── .ARTCC_TO_DCC               → colors.js, filter-colors.js
│   ├── .DCC_REGION_ORDER           → demand.js
│   ├── .ARTCC_CENTERS              → splits.js
│   └── .AIRPORT_ARTCC_OVERLAP      → tmi-gdp.js
│
├── .ATFM
│   ├── .NTML_QUALIFIERS            → tmi-publish.js
│   ├── .REASON_CATEGORIES          → tmi-publish.js
│   ├── .REASON_CAUSES              → tmi-publish.js
│   └── .COORDINATION_REQUIRED_TYPES → tmi-publish.js
│
├── .WEATHER
│   └── .CATEGORIES                 → rate-colors.js, colors.js
│
├── .SUA
│   └── .TYPE_NAMES (+ others)      → sua.js
│
├── .normalizeIcao()                → nod-demand-layer.js, tmi-publish.js, jatoc.js,
│                                     playbook-cdr-search.js, facility-hierarchy.js
├── .denormalizeIcao()              → gdt.js (×2), tmi_compliance.js, playbook-cdr-search.js
├── .normalizeArtcc()               → nod.js (×2), route-maplibre.js (×2), tmi_compliance.js
├── .isAirportICAO()                → tmi-gdp.js
├── .getDCCColor()                  → demand.js, colors.js
├── .getDCCRegion()                 → demand.js, colors.js
├── .getCarrierColor()              → demand.js
├── .resolveArtcc()                 → facility-hierarchy.js
├── .IATA_TO_ICAO                   → facility-hierarchy.js
└── (total: 20 consumer JS files)
```

---

## 4. JS Dependency Tree — facility-hierarchy.js

**Source**: `assets/js/facility-hierarchy.js`
**Global export**: `window.FacilityHierarchy`
**Depends on**: `PERTI` (optional, with typeof guard + fallbacks)

### Properties Consumed

```
FacilityHierarchy
├── .ARTCCS                    → tmi-active-display.js
├── .DCC_REGIONS               → tmi-active-display.js
├── .FACILITY_GROUPS           → tmi-active-display.js, route-maplibre.js, plan.js
├── .ARTCC_TO_REGION           → tmi-active-display.js
├── .FACILITY_HIERARCHY        → tmi-active-display.js
├── .TRACON_TO_ARTCC           → tmi-active-display.js, route-maplibre.js
├── .AIRPORT_TO_TRACON         → tmi-active-display.js
├── .AIRPORT_TO_ARTCC          → tmi-active-display.js
├── .ALL_TRACONS               → tmi-active-display.js
├── .AIRPORT_TIER_COLORS       → nod.js, route-maplibre.js
├── .AIRPORT_GROUPS            → nod.js
├── .MAJOR_CARRIERS            → nod.js
├── .REGIONAL_CARRIERS         → nod.js, config/constants.js
├── .FREIGHT_CARRIERS          → nod.js
├── .MILITARY_PREFIXES         → nod.js
├── .OPERATOR_GROUP_COLORS     → nod.js, route-maplibre.js
├── .normalizeIcao()           → procs_enhanced.js, public-routes.js,
│                                route-maplibre.js, nod-demand-layer.js
├── .getAirportTier()          → nod.js, route-maplibre.js
├── .getOperatorGroup()        → nod.js, route-maplibre.js
├── .expandSelection()         → tmi-active-display.js
├── .getRegion()               → tmi-active-display.js
├── .getRegionColor()          → demand.js
└── .load()                    → tmi-active-display.js
```

---

## 5. JS Dependency Tree — colors.js

**Source**: `assets/js/lib/colors.js`
**Global export**: `window.PERTIColors`
**Depends on**: `PERTI` (optional, with alias + typeof guard)

### Properties Consumed

```
PERTIColors
├── .region                    → (DCC region color map)
├── .regionMapping             → (ARTCC-to-DCC mapping)
├── .weather                   → (weather category colors)
├── .getDCCColor()             → (convenience function)
├── .getDCCRegion()            → (convenience function)
├── .getWeatherColor()         → (convenience function)
└── .airspace                  → route-maplibre.js, splits.js, sua.js
```

---

## 6. CSS Dependency Tree — perti-colors.css

**Source**: `assets/css/perti-colors.css`
**Mechanism**: CSS custom properties on `:root`
**Tokens defined**: ~212

### Consumer File → Token Groups Used

| Consumer File | Token Categories |
|---------------|-----------------|
| `theme.css` (536 refs) | `--brand-*`, `--light-bg-*`, `--light-text-*`, `--light-border-*`, `--status-*`, `--body-text`, `--theme-text-*` |
| `perti_theme.css` (55 refs) | `--brand-*`, `--gradient-*`, `--scrollbar-*`, `--status-*`, `--body-text`, `--dropdown-hover-bg` |
| `tmi-compliance.css` (229 refs) | `--light-*`, `--dark-*`, `--brand-primary`, `--tmi-*`, `--status-*` |
| `initiative_timeline.css` (111 refs) | `--gray-*`, `--level-*`, `--section-*`, `--status-*`, `--input-focus-*`, `--text-on-bright` |
| `tmi-publish.css` (85 refs) | `--gray-*`, `--gradient-*`, `--discord-purple-rgb`, `--alert-info-*`, `--status-*` |
| `info-bar.css` (50 refs) | `--slate-*`, `--weather-*`, `--atis-badge-*`, `--age-*`, `--status-active-*` |
| `mobile.css` (39 refs) | `--dark-bg-*`, `--nav-accent-cyan`, `--gray-*` |
| `weather_hazards.css` (34 refs) | `--hazard-*`, `--gray-*`, `--text-on-dark-*`, `--bg-dark-elevated`, `--border-dark` |
| `weather_impact.css` (33 refs) | `--gray-*`, `--impact-*`, `--hazard-*-start`, `--text-on-dark-*`, `--bg-panel-dark` |
| `weather_radar.css` (19 refs) | `--gray-*`, `--text-on-dark-*`, `--status-info-base` |

### Token Groups (summary)

| Group | Count | Primary Consumers |
|-------|-------|-------------------|
| Brand (`--brand-*`) | 9 | theme, perti_theme, tmi-compliance |
| Status (`--status-*`) | 12 | theme, perti_theme, initiative_timeline |
| Light BG (`--light-bg-*`) | 6 | theme, tmi-compliance |
| Dark BG (`--dark-bg-*`) | 6 | mobile, tmi-compliance |
| Gray scale (`--gray-*`) | 9 | initiative_timeline, tmi-publish, mobile, weather_* |
| Slate scale (`--slate-*`) | 7 | info-bar |
| Gradient (`--gradient-*`) | 22 | perti_theme, tmi-publish |
| Hazard (`--hazard-*`) | 10 | weather_hazards, weather_impact |
| Level (`--level-*`) | 14+ | initiative_timeline |
| Section (`--section-*`) | 27 | initiative_timeline |
| TMI (`--tmi-*`) | 7 | tmi-compliance |
| Legacy aliases | 31 | various (backwards-compatible mappings) |

### JS-Consumed Tokens (via getComputedStyle)

Some tokens are consumed by JavaScript (Chart.js configs, MapLibre popup styling) rather than CSS files:
- `--chart-axis-text`, `--chart-grid-line`, `--chart-label`
- `--map-popup-*`, `--map-control-*`
- `--weather-panel-*`
- `--tmi-flow-cone-fill`, `--tmi-flow-cone-border`, `--tmi-stream-highlight`

---

## 7. PHP Dependency Tree — perti_constants.php

**Source**: `load/perti_constants.php`
**Include guard**: `PERTI_CONSTANTS_LOADED` (define)
**Dependencies**: NONE (fully self-contained)

### Constants → Consumers

```
perti_constants.php
├── PERTI_TMI_TYPES (16 types)          → (no direct consumers yet; reserved for validation)
├── PERTI_TMI_STATUSES (4 statuses)     → (no direct consumers yet)
├── PERTI_ADVISORY_TYPES (23 types)     → api/tmi/advisories.php
├── PERTI_IMPACTING_CONDITIONS (5)      → (no direct consumers yet)
├── PERTI_DEFAULT_REASONS               → load/discord/TMIDiscord.php
├── PERTI_ARTCC_CONUS (22 ARTCCs)       → tmi-publish.php
├── PERTI_ARTCC_ALL (29 ARTCCs)         → (no direct consumers yet)
├── PERTI_INTL_ORGS (4 orgs)            → tmi-publish.php
├── PERTI_NAMED_GROUPS (10 groups)      → api/tiers/query.php
├── PERTI_REGION_PREFIXES (8 regions)   → api/tiers/query.php
├── PERTI_PROGRAM_TYPES (7 types)       → api/tmi/programs.php
├── PERTI_GDP_TYPES (3 subtypes)        → api/gdt/programs/transition.php
├── PERTI_ENTRY_TYPES (8 types)         → api/tmi/entries.php
├── PERTI_COORDINATED_ENTRY_TYPES (7)   → api/mgt/tmi/cancel.php
├── PERTI_COORDINATION_MODES (3 modes)  → api/gdt/programs/submit_proposal.php
├── PERTI_MODELING_STATUSES (2)         → api/gdt/programs/simulate.php, publish.php
├── perti_detect_element_type()         → api/mgt/tmi/publish.php, coordinate.php
│
└── Include-only (no runtime usage):
    ├── api/mgt/tmi/edit.php            (future-proofing)
    ├── api/mgt/tmi/active.php          (future-proofing)
    └── load/discord/DiscordMessageParser.php (comment-linked only)
```

### Intentionally Skipped PHP Files

| File | Reason |
|------|--------|
| `api/gdt/common.php` | GDT_PROGRAM_TYPES has per-type metadata (has_slots, has_rates) not in PERTI. GDT_PROGRAM_STATUSES has 8 values vs PERTI's 4 — different lifecycle scope. |
| `load/discord/DiscordMessageParser.php` | PHP class constants must be compile-time evaluatable. Cannot reference external arrays. Comments link to PERTI as source of truth. |

---

## 8. Null-Safety Contract

All JS consumer references MUST follow this pattern:

```javascript
// For namespace properties:
const X = (typeof PERTI !== 'undefined' && PERTI.SECTION && PERTI.SECTION.PROPERTY)
    ? PERTI.SECTION.PROPERTY
    : fallbackValue;

// For functions:
const result = (typeof PERTI !== 'undefined' && PERTI.functionName)
    ? PERTI.functionName(arg)
    : inlineFallback(arg);

// For FacilityHierarchy:
const X = (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.PROPERTY)
    || fallbackValue;
```

**Rules**:
1. Every intermediate property in the chain must be checked (e.g., `PERTI.UI && PERTI.UI.ARTCC_COLORS`)
2. A hardcoded fallback matching the original value must always be present
3. Never use bare `PERTI.X.Y` or `FacilityHierarchy.X` without `typeof` guard
4. Spreading (`[...PERTI.X.Y]`) requires the deepest property to be verified first

---

## 9. Page-Level Load Matrix

Shows which globalization sources are available on each page.

| Page | perti.js | colors.js | facility-hierarchy.js | perti-colors.css | Consumer Scripts |
|------|----------|-----------|----------------------|-----------------|-----------------|
| `gdt.php` | Yes | Yes | Yes | Yes | demand.js, rate-colors.js, phase-colors.js, gdt.js |
| `demand.php` | Yes | Yes | Yes | Yes | demand.js, rate-colors.js, filter-colors.js |
| `nod.php` | Yes | Yes | Yes | Yes | nod.js, nod-demand-layer.js, filter-colors.js |
| `tmi-publish.php` | Yes | Yes | Yes | Yes | tmi-publish.js, tmi-active-display.js, tmi-gdp.js |
| `splits.php` | Yes | Yes | Yes | Yes | splits.js |
| `jatoc.php` | Yes | Yes | Yes | Yes | jatoc.js, jatoc-facility-patch.js |
| `route.php` | Yes | Yes | Yes | Yes | route-maplibre.js, filter-colors.js, procs_enhanced.js |
| `plan.php` | Yes | Yes | Yes | Yes | plan.js, initiative_timeline.js |
| `review.php` | Yes | Yes | Yes | Yes | filter-colors.js, phase-colors.js |
| `sua.php` | Yes | Yes | Yes | Yes | sua.js |
| `reroutes.php` | Yes | Yes | Yes | Yes | reroute.js (no PERTI refs) |
| `status.php` | Yes | Yes | Yes | Yes | phase-colors.js |
| `advisory-builder.php` | Yes | Yes | Yes | Yes | advisory-config.js, advisory-builder.js |
| `index.php` | Yes | Yes | Yes | Yes | (no PERTI consumer scripts) |

All pages include `header.php` → all pages get perti.js, colors.js, facility-hierarchy.js, and perti-colors.css.

---

## Quick Reference: Modifying a Source

### If you change `perti.js`:
- Check all 20 consumer files in the tree above
- Ensure no property was removed that consumers depend on
- Fallback values in consumers should be updated to match

### If you change `perti-colors.css`:
- Check all 10 consumer CSS files
- Search for the specific `var(--token-name)` across all `.css` files
- Ensure the token still exists and has the expected value

### If you change `perti_constants.php`:
- Check all 16 consumer PHP files
- Search for the specific `PERTI_CONSTANT_NAME` across all `.php` files
- Constants are used at runtime; removing one causes a PHP fatal error

### If you change `facility-hierarchy.js`:
- Check all consumer files in the tree above
- This file itself depends on PERTI; verify that dependency still works
