# Codebase Globalization Reference

**Branch:** `feature/codebase-globalization`
**Date started:** 2026-02-02
**Last updated:** 2026-02-08

This document is the canonical reference for the codebase globalization effort. It catalogs everything that was done, what remains, and the consistency issues discovered and resolved during audit.

---

## Table of Contents

1. [Goal](#1-goal)
2. [What Was Built](#2-what-was-built)
3. [Commit History](#3-commit-history)
4. [PERTI Namespace Catalog](#4-perti-namespace-catalog)
5. [Migrated Files](#5-migrated-files)
6. [Resolved Issues](#6-resolved-issues)
7. [Final Audit (v1.7.0)](#7-final-audit-v170)
8. [Remaining Gaps](#8-remaining-gaps)
9. [Related Documents](#9-related-documents)

---

## 1. Goal

Consolidate scattered domain constant definitions across the PERTI codebase into a single source of truth (`assets/js/lib/perti.js`), eliminating duplication, resolving conflicts (e.g., DCC region color mismatches, PHL ARTCC mapping), and establishing authoritative modules for facility data, color schemes, carrier classifications, and normalization functions.

### Original Problems (All Resolved)

| Problem | Resolution |
|---------|------------|
| DCC Region colors conflict | Unified in `PERTI.GEOGRAPHIC.DCC_REGIONS` |
| PHL ARTCC mapping wrong in tmi-publish.js | Fixed to ZNY via apts.csv authority |
| Airport tiers duplicated in 3 files | Consolidated in `PERTI.FACILITY` |
| Carrier lists duplicated in 3 files | Consolidated in `PERTI.FACILITY` |
| 4 different ICAO normalization implementations | Centralized in `FacilityHierarchy.normalizeICAO()` |
| Weather naming conflict (MVMC vs LVMC) | Standardized to LVMC (17 vs 1 codebase occurrences) |
| Carrier colors duplicated across 4 files | Added `PERTI.UI.CARRIER_COLORS` (~75 airlines) |
| ARTCC colors duplicated across 3 files | Added `PERTI.UI.ARTCC_COLORS` (22 US + 9 Canadian) |
| rate-colors.js wrong PERTI detection | Fixed from `typeof PERTIColors` to `typeof PERTI` |
| plan.js undefined `ADV_US_FACILITY_CODES` | Wired to `PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS` |

---

## 2. What Was Built

### New Files Created

| File | Lines | Purpose |
|------|-------|---------|
| `assets/js/lib/perti.js` | ~2,200 | Core unified namespace v1.7.0 - single source of truth for all domain constants |
| `assets/js/lib/norad-codes.js` | 756 | NORAD/AMIS military reference codes |
| `docs/plans/2026-02-02-codebase-globalization.md` | 440 | Original implementation plan |
| `docs/plans/2026-02-02-perti-namespace-design.md` | 948 | Full namespace design specification |
| `docs/plans/2026-02-02-norad-codes-design.md` | 270 | NORAD codes design document |
| `docs/refs/globalization-dependency-tree.md` | ~350 | Full dependency tree: load order, consumer mapping, null-safety contract |
| `PERTI_MIGRATION_TRACKER.md` | ~380 | Per-file migration tracking with line numbers and audit results |
| `load/perti_constants.php` | ~200 | PHP mirror of domain constants (v1.1.0) |

### Files Modified (19 JS + 16 PHP consumer files migrated, 9 CSS files tokenized)

| File | What Changed |
|------|-------------|
| `assets/js/lib/colors.js` | Weather colors + DCC region colors wired to `PERTI.WEATHER.CATEGORIES` / `PERTI.GEOGRAPHIC` |
| `assets/js/facility-hierarchy.js` | DCC_REGIONS built from `PERTI.GEOGRAPHIC.DCC_REGIONS` + UI metadata overlay |
| `assets/js/config/rate-colors.js` | Fixed detection to `typeof PERTI`; weather colors wired to `PERTI.WEATHER.CATEGORIES`; LVMC standardization |
| `assets/js/config/filter-colors.js` | carrier.colors, artcc.colors, artcc.labels wired to `PERTI.UI.*` |
| `assets/js/config/constants.js` | REGIONAL_CARRIERS wired to `PERTI.FACILITY.REGIONAL_CARRIERS` |
| `assets/js/plan.js` | ADV_US_FACILITY_CODES wired to `PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS` |
| `assets/js/demand.js` | getDCCRegionColor, DCC_REGION_ORDER, ARTCC_COLORS, getCarrierColor wired to PERTI |
| `assets/js/splits.js` | artccCenters wired to `PERTI.GEOGRAPHIC.ARTCC_CENTERS` |
| `assets/js/gdt.js` | AIRLINE_CODE_MAP + ICAO normalization wired to `PERTI.FACILITY.AIRLINE_CODES`, `PERTI.normalizeIcao`, `PERTI.denormalizeIcao` |
| `assets/js/initiative_timeline.js` | 8 arrays (4 TMI types + 4 facility lists) wired to `PERTI.ATFM.*` / `PERTI.FACILITY.*` |
| `assets/js/tmi-publish.js` | COORDINATION_REQUIRED_TYPES + FACILITY_NAME_MAP + NTML_QUALIFIERS + REASON_CATEGORIES/CAUSES wired to `PERTI.ATFM.*`, `PERTI.FACILITY.*` |
| `assets/js/sua.js` | TYPE_NAMES, GROUP_NAMES, LINE_TYPES, LAYER_GROUPS wired to `PERTI.SUA.*` |
| `assets/js/jatoc.js` | FACILITIES (4 lists) + ROLES wired to `PERTI.FACILITY.*` / `PERTI.COORDINATION.ROLES` |
| `assets/js/nod.js` | CENTER_COLORS wired to `PERTI.UI.ARTCC_COLORS > FILTER_CONFIG > hardcoded` |
| `assets/js/route-maplibre.js` | CENTER_COLORS, TRACON_TO_ARTCC, CARRIER_COLORS all wired to PERTI/FacilityHierarchy |
| `assets/js/nod-demand-layer.js` | normalizeAirportCode wired to `PERTI.normalizeIcao`; DEMAND_COLORS wired to `PERTI.UI.DEMAND_COLORS`; ARTCC_CODES wired to `PERTI.FACILITY.FACILITY_LISTS.ARTCC_ALL` |
| `assets/js/tmi_compliance.js` | ICAO denormalization + ARTCC GeoJSON lookup wired to `PERTI.denormalizeIcao`, `PERTI.normalizeArtcc` |
| `assets/js/tmi-gdp.js` | AIRPORT type detection wired to `PERTI.isAirportICAO`; `'APT'` bug fix |
| `assets/js/playbook-cdr-search.js` | K-prefix strip functions wired to `PERTI.denormalizeIcao` |
| `assets/js/weather_impact.js` | CONFIG.colors wired to `PERTI.UI.WEATHER_IMPACT_COLORS` with fallback |

### CSS Files Tokenized (v1.6.0)

| File | What Changed |
|------|-------------|
| `assets/css/perti-colors.css` | Added 6 new tokens: `--impact-direct`, `--impact-clear`, `--nav-accent-cyan`, `--theme-text-gray`, `--theme-text-gray-dark`, `--theme-text-muted` |
| `assets/css/weather_impact.css` | 41→6 hardcoded colors; hazard borders → `--hazard-*-start`, impact severity → `--impact-*`, grays → `--gray-*` |
| `assets/css/mobile.css` | 43→13 hardcoded colors; nav gradient → `--dark-bg-*`, accent → `--nav-accent-cyan`, grays → `--gray-*` |
| `assets/css/theme.css` | 1,119→583 hardcoded colors; 15 base colors bulk-replaced (536 instances); remaining are `#fff` + computed darken/lighten |

### PHP Files Modified

| File | What Changed |
|------|-------------|
| `tmi-publish.php` | `$artccs` + `$intlOrgs` wired to `PERTI_ARTCC_CONUS`, `PERTI_INTL_ORGS` |
| `load/discord/TMIDiscord.php` | Default reason codes wired to `PERTI_DEFAULT_REASONS` |
| `api/tmi/advisories.php` | `$valid_types` wired to `PERTI_ADVISORY_TYPES` |
| `api/tiers/query.php` | Named groups + region prefixes wired to `PERTI_NAMED_GROUPS`, `PERTI_REGION_PREFIXES` |
| `api/mgt/tmi/publish.php` | Subject mappings wired; local `detectElementType()` deleted |
| `api/mgt/tmi/coordinate.php` | Local `detectElementType()` deleted (bug fix: was returning 'AIRPORT' not 'APT') |
| `api/tmi/programs.php` | Program type validation wired to `PERTI_PROGRAM_TYPES` |
| `api/tmi/entries.php` | Entry type validation wired to `PERTI_ENTRY_TYPES` |
| `api/mgt/tmi/cancel.php` | Coordinated types wired to `PERTI_COORDINATED_ENTRY_TYPES` |
| `api/gdt/programs/simulate.php` | Status validation wired to `PERTI_MODELING_STATUSES` |
| `api/gdt/programs/submit_proposal.php` | Coordination modes + statuses wired to `PERTI_COORDINATION_MODES`, `PERTI_MODELING_STATUSES` |
| `api/gdt/programs/publish.php` | DCC override statuses wired to `PERTI_MODELING_STATUSES` |
| `api/gdt/programs/transition.php` | GDP type validation wired to `PERTI_GDP_TYPES` |

---

## 3. Commit History

14 committed + uncommitted migration work on `feature/codebase-globalization`:

### Committed (Phases 1-7)

| Phase | Commit | Description |
|-------|--------|-------------|
| Docs | `00c1988` | Add codebase globalization implementation plan |
| Fix | `d99c60b` | Correct PHL TRACON reference, use apts.csv as authority |
| **Phase 1** | `4a7fecc` | Centralize DCC region colors and airport-to-ARTCC lookups |
| Fix | `49d6936` | Add missing Australia ICAO prefixes (YS, YP, YC) |
| Feat | `90ff12f` | Add international FIR lookup via ICAO prefix mapping |
| **Phase 2** | `1e01561` | Centralize carrier classifications and airport tiers |
| Refactor | `aa8f661` | Hardcode airport tier lists instead of dynamic loading |
| **Phase 3** | `11885a2` | Centralize ICAO normalization in FacilityHierarchy |
| **Phase 5** | `92495bf` | Centralize aircraft manufacturer data (PERTIAircraft) |
| **Phase 5b** | `3880764` | Add aircraft config + wake turbulence to PERTIAircraft |
| **Phase 5c** | `1bc8b13` | Add flight rules (DVFR/SVFR) + operator group colors |
| **Phase 6** | `564df85` | Unify Canada regions from EAST/WEST split to single CANADA |
| **Phase 7** | `9461e57` | Add NORAD/AMIS codes reference module |
| **Phase 4** | `7cf18c1` | Use centralized ICAO normalization in nod-demand-layer |

### Uncommitted (v1.3.0→v1.5.0 expansion)

Pending commit — ~35 files with the following changes:
- **P0 bug fixes**: rate-colors.js detection fix, plan.js undefined variable fix, weather color standardization (LVMC), `detectElementType()` 'AIRPORT' vs 'APT' bug fix
- **perti.js v1.3.0→v1.5.0**: Added `CARRIER_COLORS`, `ARTCC_COLORS`, `ARTCC_LABELS`, `FACILITY_NAME_MAP`, `CROSS_BORDER_FACILITIES`, `NTML_QUALIFIERS`, `REASON_CATEGORIES`, `REASON_CAUSES`, ICAO/ARTCC normalization functions (v1.4.0), helper functions
- **JS consumer migration**: 18 JS files wired to PERTI namespace with fallback chains
- **PHP centralization**: Created `load/perti_constants.php` (16 constant groups + `perti_detect_element_type()`), wired 16 PHP consumer files
- **Hookify rule**: `.claude/hookify.enforce-perti-namespace.local.md` — warns on new inline aviation constants

---

## 4. PERTI Namespace Catalog

`perti.js` v1.5.0 exports 10 namespaces and ~37 helper functions:

### Namespaces

| Namespace | Contents |
|-----------|----------|
| `PERTI.ATFM` | TMI_TYPES (16 types), TMI_UI_TYPES, COORDINATION_REQUIRED_TYPES, INITIATIVE_SCOPE, DELAY_PROGRAMS, SLOT_TYPES, CDR_STATUS, CONSTRAINT_TYPES, VIP_TYPES, SPACE_TYPES, **NTML_QUALIFIERS** (7 categories), **REASON_CATEGORIES** (5), **REASON_CAUSES** (hierarchical) |
| `PERTI.FACILITY` | AIRCRAFT_CATEGORIES, WAKE_TURBULENCE, EQUIPMENT_SUFFIX, FLIGHT_RULES, AIRLINE_TYPES, REGIONAL_CARRIERS, AIRPORT_HUB_TYPES, SPECIAL_FLIGHTS, FACILITY_LISTS (ARTCC_CONUS/ARTCC_ALL/TRACON/ATCT/FIR_CANADA/FIR_EUROPE/FIR_GLOBAL), AIRLINE_CODES, **FACILITY_NAME_MAP** (50 entries), **CROSS_BORDER_FACILITIES** (9 entries) |
| `PERTI.WEATHER` | CATEGORIES (VMC/LVMC/IMC/LIMC/VLIMC with colors), SIGMET_TYPES, AIRMET_TYPES, PIREP_TYPES, TURBULENCE_INTENSITY, ICING_INTENSITY, CLOUD_TYPES, VISIBILITY_PHENOMENA, PRECIPITATION_TYPES |
| `PERTI.STATUS` | OPERATIONAL_STATUS, TMU_OPS_LEVEL, FACILITY_STATUS, RUNWAY_STATUS, NOTAM_PRIORITY, FLIGHT_STATUS |
| `PERTI.COORDINATION` | HOTLINES, COORDINATION_TYPES, COMMUNICATION_TYPES, ADVISORY_TYPES, ADVISORY_ACTIONS, IMPACTING_CONDITIONS, DELAY_ASSIGNMENT_MODES, INTL_ORGS, ROLES (DCC/ECFMP/CTP/WF/FACILITY) |
| `PERTI.GEOGRAPHIC` | DCC_REGIONS (7 regions + colors + ARTCC lists), DCC_REGION_ORDER, ARTCC_TO_DCC (inverse map), ARTCC_CENTERS (24 centers with lng/lat), ARTCC_TOPOLOGY (neighbor graph), VATSIM_REGIONS (3), VATSIM_DIVISIONS (27), AIRSPACE_TYPES (80+ types), EARTH, DISTANCE, BOUNDS |
| `PERTI.UI` | DEMAND_COLORS, WEATHER_IMPACT_COLORS, STATUS_COLORS, **CARRIER_COLORS** (~75 airlines), **ARTCC_COLORS** (22 US + 9 Canadian), **ARTCC_LABELS** (31 codes) |
| `PERTI.SUA` | TYPE_NAMES (44 entries), GROUPS (8), LINE_TYPES (10), LAYER_GROUPS (mapping types to groups) |
| `PERTI.ROUTE` | TOKEN_TYPES, SEGMENT_TYPES, OCEANIC_TRACKS, KEYWORDS, PLAYBOOK_FORMAT, PROCEDURE_FORMAT, ICAO_ROUTE_FIELD, EXPANSION_STATUS |
| `PERTI.CODING` | IDENTIFIER_PATTERNS (string patterns), PATTERNS (compiled regex), AIRCRAFT_TYPE_PATTERNS, ROUTE_PATTERNS, WEIGHT_CLASS_CODES |

### Helper Functions

**Geographic:** `getDCCRegion()`, `getDCCColor()`, `getARTCCsForRegion()`, `getARTCCCenter()`, `getARTCCNeighbors()`, `getRegionOrder()`, `getCONUSARTCCs()`, `getAllARTCCs()`

**VATSIM:** `getVATSIMDivision()`, `getVATSIMRegion()`, `hasDCCRegions()`

**ATFM:** `getTMIType()`, `getTMIScope()`, `isCoordinationRequired()`

**Weather:** `getWeatherCategory()`, `getSigmetType()`, `getAirmetType()`

**Status:** `getFlightStatus()`, `isFlightActive()`

**Airspace:** `getAirspaceType()`, `isSUA()`, `isTFR()`

**Facility:** `getAirlineCode()`

**UI (v1.3.0):** `getCarrierColor()`, `getARTCCColor()`, `getARTCCLabel()`

**SUA Display:** `getSUALayerGroup()`, `getSUATypeName()`, `isSUALineType()`

**Coding/Pattern:** `isAirway()`, `isAirportICAO()`, `isFix()`, `isARTCC()`, `isTRACON()`, `isProcedure()`, `isMilitaryCallsign()`, `classifyAircraftType()`, `parseRouteSegment()`

**ICAO/ARTCC Normalization (v1.4.0):** `normalizeIcao()`, `denormalizeIcao()`, `normalizeArtcc()`, `resolveArtcc()`

---

## 5. Migrated Files

All consumer JS files use a consistent pattern: `PERTI > intermediate source > hardcoded fallback`.

### Migration Pattern

```javascript
const CONSTANT = (typeof PERTI !== 'undefined' && PERTI.SECTION)
    ? PERTI.SECTION.CONSTANT
    : fallbackValue;
```

### File-by-File Status

| File | Constants Wired | Priority Chain | Status |
|------|----------------|----------------|--------|
| `lib/colors.js` | Weather colors, DCC region colors | PERTI.WEATHER > PERTI.GEOGRAPHIC > hardcoded | DONE |
| `facility-hierarchy.js` | DCC_REGIONS | PERTI.GEOGRAPHIC > hardcoded + UI extensions | DONE |
| `config/rate-colors.js` | Weather colors (VMC/LVMC/IMC/LIMC/VLIMC) | PERTI.WEATHER.CATEGORIES > hardcoded gradient | DONE |
| `config/filter-colors.js` | carrier.colors, artcc.colors, artcc.labels | PERTI.UI > hardcoded | DONE |
| `config/constants.js` | REGIONAL_CARRIERS | PERTI.FACILITY > FacilityHierarchy > hardcoded | DONE |
| `plan.js` | ADV_US_FACILITY_CODES | PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS > hardcoded | DONE |
| `demand.js` | DCC colors, DCC_REGION_ORDER, ARTCC_COLORS, carrier colors | PERTI > FILTER_CONFIG > hardcoded | DONE |
| `splits.js` | artccCenters | PERTI.GEOGRAPHIC.ARTCC_CENTERS > hardcoded | DONE |
| `gdt.js` | AIRLINE_CODE_MAP | PERTI.FACILITY.AIRLINE_CODES > hardcoded | DONE |
| `initiative_timeline.js` | tmiTypes, constraintTypes, vipTypes, spaceTypes, 4 facility lists | PERTI.ATFM / PERTI.FACILITY > hardcoded | DONE |
| `tmi-publish.js` | COORDINATION_REQUIRED_TYPES | PERTI.ATFM > hardcoded | DONE |
| `sua.js` | TYPE_NAMES, GROUP_NAMES, LINE_TYPES, LAYER_GROUPS | PERTI.SUA > hardcoded | DONE |
| `jatoc.js` | FACILITIES (4 lists), ROLES | PERTI.FACILITY / PERTI.COORDINATION > hardcoded | DONE |
| `nod.js` | CENTER_COLORS | PERTI.UI.ARTCC_COLORS > FILTER_CONFIG > hardcoded | DONE |
| `route-maplibre.js` | CENTER_COLORS, TRACON_TO_ARTCC, CARRIER_COLORS | PERTI.UI / FacilityHierarchy > FILTER_CONFIG > hardcoded | DONE |
| `nod-demand-layer.js` | DEMAND_COLORS | PERTI.UI.DEMAND_COLORS (.hex) > hardcoded | DONE |
| `weather_impact.js` | CONFIG.colors | PERTI.UI.WEATHER_IMPACT_COLORS > hardcoded | DONE |

### Files Reviewed but NOT Migrated (intentionally)

| File | Constant | Reason Left As-Is |
|------|----------|-------------------|
| `tmi-active-display.js` | TRACON filter list (L201) | UI filter heuristic, not a domain constant |
| `tmi-active-display.js` | ENTRY_TYPES (L80-91) | UI-specific code+label pairs, not shared |
| `tmi-gdp.js` | airportToArtcc (L431) | UI convenience mapping inside a function for checkbox auto-selection |
| `public-routes.js` | usArtccCodes (L1204) | Validation Set for route parsing (prevents "ZBW" getting "K" prefix) |

---

## 6. Resolved Issues

### P0 - Critical (All Fixed)

| Issue | Resolution |
|-------|------------|
| `plan.js:2394` undefined `ADV_US_FACILITY_CODES` | Wired to `PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS` with hardcoded fallback |
| Weather colors mismatch (rate-colors.js vs perti.js) | Standardized gradient palette: green `#22c55e` (VMC) -> yellow `#eab308` (LVMC) -> orange `#f97316` (IMC) -> red `#ef4444` (LIMC) -> dark red `#991b1b` (VLIMC) |
| Dual weather color definitions | perti.js `WEATHER.CATEGORIES` is authoritative; consumers wire to it |
| MVMC vs LVMC naming | Standardized to **LVMC** (Low VMC) across all files |

### P1 - Important (All Fixed)

| Issue | Resolution |
|-------|------------|
| rate-colors.js wrong detection (`PERTIColors`) | Fixed to `typeof PERTI !== 'undefined'` |
| plan.js / route-maplibre.js no PERTI integration | Both now wire to PERTI as highest priority |
| Carrier colors duplicated in 4 files | All 4 files wire to `PERTI.UI.CARRIER_COLORS` |
| ARTCC colors duplicated in 3 files | All 3 files wire to `PERTI.UI.ARTCC_COLORS` |

### P2 - Moderate (Partially Addressed)

| Issue | Status |
|-------|--------|
| Undocumented facility-hierarchy.js extensions | Still needs inline comments |
| Inconsistent detection patterns | Standardized to `typeof PERTI !== 'undefined'` across all 15 files |

### PHP Side (All Fixed in v1.5.0)

| Issue | Resolution |
|-------|------------|
| Duplicate `detectElementType()` | Unified to `perti_detect_element_type()` in `load/perti_constants.php` returning `'APT'` per DB constraint |
| No PHP constants file | Created `load/perti_constants.php` with 16 constant groups + unified function |
| TMI type constants scattered | All wired to centralized `PERTI_TMI_TYPES`, `PERTI_ADVISORY_TYPES`, etc. |
| GDT program/entry types scattered | Created `PERTI_PROGRAM_TYPES`, `PERTI_ENTRY_TYPES`, `PERTI_GDP_TYPES`, etc. |

---

## 7. Final Audit (v1.7.0)

Comprehensive four-agent audit performed 2026-02-08. All issues found were fixed.

### Audit Results

| Layer | Files | Reference Sites | Issues Found | Issues Fixed | Status |
|-------|-------|----------------|-------------|-------------|--------|
| JS PERTI refs | 23 | ~75 | 15 (5 HIGH, 7 MED, 3 LOW) | 15/15 | **PASS** |
| CSS tokens | 10 | ~1,000 var() | 2 undefined tokens | 2/2 | **PASS** |
| PHP constants | 16 | 17 constants + 1 fn | 0 | — | **PASS** |
| Load order | header.php + 13 pages | — | 3 (perti.js/colors.js missing, CSS order) | 3/3 | **PASS** |

### Critical Issues Fixed

1. **perti.js was not loaded on any page** — added `<script>` in header.php (line 83)
2. **colors.js was not loaded on any page** — added `<script>` in header.php (line 84)
3. **CSS load order wrong** — swapped `perti-colors.css` before `theme.css`
4. **2 undefined CSS tokens** — added `--light-bg-elevated` and `--light-bg-primary` to perti-colors.css

### JS Null-Safety Issues Fixed

5 HIGH: bare `FacilityHierarchy.*` in nod.js, route-maplibre.js, tmi-active-display.js; bare `PERTI.UI.CARRIER_COLORS.OTHER` in demand.js; spreading undefined in tmi-publish.js

7 MEDIUM: missing intermediate property checks in rate-colors.js, colors.js, demand.js, gdt.js, splits.js, plan.js

---

## 8. Remaining Gaps (all reviewed, intentionally left)

### JS - Low-Priority Files

| File | Constants | Status |
|------|-----------|--------|
| `norad-codes.js` | NORAD_REGIONS, ROCC_SOCC, etc. | SKIP — standalone module, well-organized |
| `aircraft.js` | AIRCRAFT_MANUFACTURERS | SKIP — standalone module, well-organized |
| `jatoc-facility-patch.js` | DCC_SERVICES_TYPE, etc. | SKIP — consumes from window.JATOC_FACILITY_DATA |
| `advisory-builder.js` | Program format templates | SKIP — too coupled to format logic |
| `fir-scope.js` / `fir-integration.js` | — | SKIP — no constants (data-driven from API) |

### PHP - Low-Priority Files

| File | Constants | Status |
|------|-----------|--------|
| `DiscordMessageParser.php` | TMI_*/STATUS_* class constants (L15-28) | SKIP — PHP class constants can't reference arrays; `self::` OOP pattern is correct |
| `api/gdt/common.php` | GDT_PROGRAM_TYPES/STATUSES (L330-372) | SKIP — GDT-domain types (GDP-DAS/GAAP/UDP subtypes) and statuses differ from PERTI_TMI_TYPES |
| `load/services/GISService.php` | Boundary/facility type strings | SKIP — no domain constants found; data-driven |

### CSS Globalization Status

| Category | Files | Hardcoded Before | Hardcoded After | Reduction |
|----------|-------|-----------------|----------------|-----------|
| PERTI feature CSS (6 files) | perti_theme, initiative_timeline, tmi-publish, info-bar, weather_radar, weather_hazards | — | 0 | Fully tokenized |
| weather_impact.css | 1 | 41 | 6 | 85% |
| mobile.css | 1 | 43 | 13 | 70% |
| theme.css (Bootstrap 4.5.2) | 1 | 1,119 | 583 | 48% (base colors done; computed variants left) |
| datetimepicker.css | 1 | 104 | 104 | SKIP (vendor plugin) |

---

## 9. Related Documents

| Document | Location |
|----------|----------|
| Implementation Plan | `docs/plans/2026-02-02-codebase-globalization.md` |
| Namespace Design | `docs/plans/2026-02-02-perti-namespace-design.md` |
| NORAD Codes Design | `docs/plans/2026-02-02-norad-codes-design.md` |
| **Dependency Tree** | `docs/refs/globalization-dependency-tree.md` |
| Migration Tracker | `PERTI_MIGRATION_TRACKER.md` (root) |
| I18N Tracking | `docs/I18N_TRACKING.md` |
