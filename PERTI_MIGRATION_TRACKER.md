# PERTI Migration Tracker

This document tracks all files updated to consume from the PERTI unified namespace instead of having local constant definitions.

**Last updated:** 2026-02-08
**PERTI version:** v1.7.0

## Migration Status Legend
- [x] Completed
- [~] Reviewed, intentionally skipped (not a domain constant)
- [ ] Not started (low priority)

---

## Completed JS Migrations (19 files)

All migrations use the pattern: `PERTI > intermediate source > hardcoded fallback`

### Core Config Files

| # | File | Constants Wired | PERTI Source |
|---|------|----------------|-------------|
| 1 | `assets/js/lib/colors.js` | Weather colors, DCC region colors | `PERTI.WEATHER.CATEGORIES`, `PERTI.GEOGRAPHIC` |
| 2 | `assets/js/facility-hierarchy.js` | DCC_REGIONS, normalizeIcao/denormalizeIcao/resolveAlias | `PERTI.GEOGRAPHIC.DCC_REGIONS` + UI extensions, delegates to PERTI functions |
| 3 | `assets/js/config/rate-colors.js` | Weather colors (VMC/LVMC/IMC/LIMC/VLIMC) | `PERTI.WEATHER.CATEGORIES` |
| 4 | `assets/js/config/filter-colors.js` | carrier.colors, artcc.colors, artcc.labels | `PERTI.UI.CARRIER_COLORS`, `PERTI.UI.ARTCC_COLORS`, `PERTI.UI.ARTCC_LABELS` |
| 5 | `assets/js/config/constants.js` | REGIONAL_CARRIERS | `PERTI.FACILITY.REGIONAL_CARRIERS` |

### Feature Files

| # | File | Constants Wired | PERTI Source |
|---|------|----------------|-------------|
| 6 | `assets/js/plan.js` | ADV_US_FACILITY_CODES | `PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS` |
| 7 | `assets/js/demand.js` | DCC colors, DCC_REGION_ORDER, ARTCC_COLORS, carrier colors | `PERTI.GEOGRAPHIC`, `PERTI.UI` |
| 8 | `assets/js/splits.js` | artccCenters | `PERTI.GEOGRAPHIC.ARTCC_CENTERS` |
| 9 | `assets/js/gdt.js` | AIRLINE_CODE_MAP, airport ICAO normalization, GS demand K-prefix | `PERTI.FACILITY.AIRLINE_CODES`, `PERTI.denormalizeIcao`, `PERTI.normalizeIcao` |
| 10 | `assets/js/initiative_timeline.js` | tmiTypes, constraintTypes, vipTypes, spaceTypes, 4 facility lists | `PERTI.ATFM.*`, `PERTI.FACILITY.FACILITY_LISTS.*` |
| 11 | `assets/js/tmi-publish.js` | COORDINATION_REQUIRED_TYPES, FACILITY_NAME_MAP, CROSS_BORDER, NTML_QUALIFIERS, REASON_CATEGORIES, REASON_CAUSES | `PERTI.ATFM.*`, `PERTI.FACILITY.*` |
| 12 | `assets/js/sua.js` | TYPE_NAMES, GROUP_NAMES, LINE_TYPES, LAYER_GROUPS | `PERTI.SUA.*` |
| 13 | `assets/js/jatoc.js` | FACILITIES (4 lists), ROLES | `PERTI.FACILITY.FACILITY_LISTS`, `PERTI.COORDINATION.ROLES` |
| 14 | `assets/js/nod.js` | CENTER_COLORS | `PERTI.UI.ARTCC_COLORS` > `FILTER_CONFIG` > hardcoded |
| 15 | `assets/js/route-maplibre.js` | CENTER_COLORS, TRACON_TO_ARTCC, CARRIER_COLORS | `PERTI.UI` / `FacilityHierarchy` > `FILTER_CONFIG` > hardcoded |
| 16 | `assets/js/nod-demand-layer.js` | normalizeAirportCode, airport filter matching, **DEMAND_COLORS**, **ARTCC_CODES** | `PERTI.normalizeIcao`, `PERTI.UI.DEMAND_COLORS`, `PERTI.FACILITY.FACILITY_LISTS.ARTCC_ALL` |
| 17 | `assets/js/tmi_compliance.js` | ICAO denormalization, ARTCC GeoJSON ICAO lookup | `PERTI.denormalizeIcao`, `PERTI.normalizeArtcc` |
| 18 | `assets/js/tmi-gdp.js` | AIRPORT type detection, element_type 'APT' fix | `PERTI.isAirportICAO` |
| 19 | `assets/js/weather_impact.js` | CONFIG.colors (impact badge colors) | `PERTI.UI.WEATHER_IMPACT_COLORS` > hardcoded fallback |

### Airport/ARTCC Code Normalization (v1.4.0+)

| File | Location | Old (K-only) | New (region-aware) |
|------|----------|-------------|-------------------|
| `gdt.js` | L543, L7657 | K-strip + AIRPORT_IATA_MAP | PERTI.denormalizeIcao > AIRPORT_IATA_MAP |
| `gdt.js` | L6347 | `airport = 'K' + airport` | PERTI.normalizeIcao > fallback |
| `tmi-publish.js` | L8952 | `a.startsWith('K') ? a : 'K' + a` | PERTI.normalizeIcao |
| `tmi-gdp.js` | L445 | `ctlElement.startsWith('K')` | PERTI.isAirportICAO |
| `tmi_compliance.js` | L1143 | `.replace(/^K/, '')` | PERTI.denormalizeIcao |
| `tmi_compliance.js` | L4140 | `'K' + code` | PERTI.normalizeArtcc > fallback |
| `playbook-cdr-search.js` | L418-457, L500 | `.replace(/^K/, '')` + duplicate fn | PERTI.denormalizeIcao + fn rewired to PERTI |
| `nod-demand-layer.js` | L1612 | duplicate `normalizeAirportCode()` | Rewired to PERTI.normalizeIcao |
| `nod-demand-layer.js` | L2291 | `'K' + filterCode` | PERTI.normalizeIcao > fallback |
| `jatoc.js` | L729 | `coords['K' + fac]` | PERTI.normalizeIcao |
| `nod.js` | L2796, L2827 | `fac.startsWith('K') ? fac : 'K' + fac` | PERTI.normalizeArtcc |
| `route-maplibre.js` | L6549, L6805 | `.replace(/^K/, '')` | PERTI.normalizeArtcc |

---

## Completed PHP Migrations (16 files)

### Centralized Constants File

| File | Purpose |
|------|---------|
| `load/perti_constants.php` | PHP mirror of perti.js domain constants (v1.1.0) |

Contains: `PERTI_TMI_TYPES`, `PERTI_TMI_STATUSES`, `PERTI_ADVISORY_TYPES`, `PERTI_IMPACTING_CONDITIONS`, `PERTI_DEFAULT_REASONS`, `PERTI_ARTCC_CONUS`, `PERTI_ARTCC_ALL`, `PERTI_INTL_ORGS`, `PERTI_NAMED_GROUPS`, `PERTI_REGION_PREFIXES`, `PERTI_PROGRAM_TYPES`, `PERTI_GDP_TYPES`, `PERTI_ENTRY_TYPES`, `PERTI_COORDINATED_ENTRY_TYPES`, `PERTI_COORDINATION_MODES`, `PERTI_MODELING_STATUSES`, plus `perti_detect_element_type()` function.

### PHP Files Wired to perti_constants.php

| # | File | Constants Wired | PERTI Source |
|---|------|----------------|-------------|
| 1 | `tmi-publish.php` | `$artccs`, `$intlOrgs` | `PERTI_ARTCC_CONUS`, `PERTI_INTL_ORGS` |
| 2 | `load/discord/DiscordMessageParser.php` | TMI type sync comment | Synced with `PERTI_TMI_TYPES` |
| 3 | `load/discord/TMIDiscord.php` | Default reason codes | `PERTI_DEFAULT_REASONS` |
| 4 | `api/tmi/advisories.php` | `$valid_types` | `PERTI_ADVISORY_TYPES` |
| 5 | `api/tiers/query.php` | `$NAMED_GROUPS`, `$REGION_PREFIXES` | `PERTI_NAMED_GROUPS`, `PERTI_REGION_PREFIXES` |
| 6 | `api/mgt/tmi/publish.php` | Subject mappings, `detectElementType()` deleted | `PERTI_ADVISORY_SUBJECTS`, `perti_detect_element_type()` |
| 7 | `api/mgt/tmi/coordinate.php` | `detectElementType()` deleted (bug fix) | `perti_detect_element_type()` |
| 8 | `api/tmi/programs.php` | `$valid_types` for program types | `PERTI_PROGRAM_TYPES` |
| 9 | `api/tmi/entries.php` | `$valid_types` for entry types | `PERTI_ENTRY_TYPES` |
| 10 | `api/mgt/tmi/cancel.php` | `$coordinatedTypes` | `PERTI_COORDINATED_ENTRY_TYPES` |
| 11 | `api/mgt/tmi/edit.php` | Include added (future use) | — |
| 12 | `api/mgt/tmi/active.php` | Include added (future use) | — |
| 13 | `api/gdt/programs/simulate.php` | Status validation | `PERTI_MODELING_STATUSES` |
| 14 | `api/gdt/programs/submit_proposal.php` | Coordination modes, status validation | `PERTI_COORDINATION_MODES`, `PERTI_MODELING_STATUSES` |
| 15 | `api/gdt/programs/publish.php` | DCC override status validation | `PERTI_MODELING_STATUSES` + `['PENDING_COORD']` |
| 16 | `api/gdt/programs/transition.php` | GDP type validation | `PERTI_GDP_TYPES` |

---

## Reviewed & Intentionally Skipped

These files were audited but their constants are UI-specific heuristics, not domain constants.

### JS

| File | Constant | Reason |
|------|----------|--------|
| `assets/js/tmi-active-display.js` | TRACON filter list (L201) | UI filter heuristic for checkbox display |
| `assets/js/tmi-active-display.js` | ENTRY_TYPES (L80-91) | UI-specific code+label pairs |
| `assets/js/tmi-gdp.js` | airportToArtcc (L431) | Checkbox auto-selection helper inside a function |
| `assets/js/public-routes.js` | usArtccCodes (L1204) | Validation Set preventing "K" prefix on ARTCC codes |
| `assets/js/jatoc.js` | `'K' + fac` (L666) | Intentionally local — GeoJSON property fuzzy matching |

### PHP

| File | Constant | Reason |
|------|----------|--------|
| `api/mgt/tmi/cancel.php` | Entity type validation (L62) | API endpoint-specific routing, not domain constant |
| `api/mgt/tmi/edit.php` | Entity type validation (L63) | API endpoint-specific routing, not domain constant |
| `api/mgt/tmi/active.php` | `$typeMap` (L982), `$statusMap` (L1168, L1389) | Display formatters local to response builder functions |
| `api/gdt/programs/publish.php` | `['APPROVED']` status check (L136) | Single-value validation specific to proposal flow |
| `api/swim/v1/ws/WebSocketServer.php` | `$tierLimits` (L51-56) | Config value, not a domain constant |
| `api/gis/boundaries.php` | `$adlTier1Expected` (L1056) | Test fixture/validation data |
| `load/discord/DiscordMessageParser.php` | TMI_* / STATUS_* class constants (L15-28) | PHP class constants can't reference arrays; OOP usage via `self::` is correct pattern |
| `api/gdt/common.php` | GDT_PROGRAM_TYPES / GDT_PROGRAM_STATUSES (L330-372) | GDT-domain types (GDP-DAS/GAAP/UDP subtypes) and statuses (PROPOSED/MODELING/etc.) differ from PERTI_TMI_TYPES |
| `load/services/GISService.php` | Boundary/facility type strings | No domain constants found; uses data-driven values from DB |

---

## Remaining JS Files (Low Priority)

| File | Constants | Priority | Notes |
|------|-----------|----------|-------|
| `assets/js/lib/norad-codes.js` | NORAD_REGIONS, ROCC_SOCC, etc. | LOW | Standalone module, well-organized |
| `assets/js/lib/aircraft.js` | AIRCRAFT_MANUFACTURERS | LOW | Standalone module, well-organized |
| `assets/js/jatoc-facility-patch.js` | DCC_SERVICES_TYPE, etc. | LOW | Consumes from window.JATOC_FACILITY_DATA |
| `assets/js/advisory-builder.js` | Program format templates | LOW | Too coupled to format logic |
| `assets/js/fir-scope.js` / `fir-integration.js` | — | LOW | No constants (data-driven from API) |

---

## P0 Bug Fixes Applied

These critical issues from the initial audit have been resolved:

| Issue | File | Fix |
|-------|------|-----|
| Undefined `ADV_US_FACILITY_CODES` | plan.js | Wired to `PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS` |
| Wrong detection `typeof PERTIColors` | rate-colors.js | Changed to `typeof PERTI !== 'undefined'` |
| Weather color mismatch (3 of 5 differed) | rate-colors.js, perti.js | Standardized gradient: green/yellow/orange/red/dark-red |
| MVMC vs LVMC naming conflict | perti.js, rate-colors.js, colors.js | Standardized to **LVMC** (Low VMC) |
| `detectElementType()` returning `'AIRPORT'` | coordinate.php | Unified to `perti_detect_element_type()` returning `'APT'` per DB constraint |
| `tmi-gdp.js` comparing `element_type === 'AIRPORT'` | tmi-gdp.js | Changed to accept both `'APT'` and `'AIRPORT'` for backwards compat |

---

## Function Normalization (v1.4.0)

Airport/ARTCC code normalization functions moved to PERTI namespace. Replaces 13+ inline K-prefix hacks with region-aware conversions (Canada, Alaska, Hawaii, Pacific, PR, USVI).

### New PERTI Functions

| Function | Purpose | Example |
|----------|---------|---------|
| `PERTI.normalizeIcao(code)` | 3-letter FAA/IATA → 4-letter ICAO | JFK→KJFK, YYZ→CYYZ, ANC→PANC |
| `PERTI.denormalizeIcao(icao)` | 4-letter ICAO → 3-letter FAA/IATA | KJFK→JFK, CYYZ→YYZ, PANC→ANC |
| `PERTI.normalizeArtcc(code)` | Resolve aliases + strip K-prefix | KZBW→ZBW, ZYZ→CZYZ, ZMX→MMMX, ZEU→EGTT |
| `PERTI.resolveArtcc(code)` | Resolve ARTCC/FIR alias only (no K-strip) | ZYZ→CZYZ, CZE→CZEG, ZMR→MMMD |

---

## TMI Constants Expansion (v1.5.0)

### JS (perti.js additions)

| Namespace | New Constants |
|-----------|--------------|
| `PERTI.FACILITY` | `FACILITY_NAME_MAP` (50 entries), `CROSS_BORDER_FACILITIES` (9 entries) |
| `PERTI.ATFM` | `NTML_QUALIFIERS` (7 categories), `REASON_CATEGORIES` (5), `REASON_CAUSES` (hierarchical per category) |

### PHP (perti_constants.php v1.1.0)

| Constant | Entries | Used By |
|----------|---------|---------|
| `PERTI_TMI_TYPES` | 16 | DiscordMessageParser, TMIDiscord |
| `PERTI_TMI_STATUSES` | 4 | — |
| `PERTI_ADVISORY_TYPES` | 18 | advisories.php |
| `PERTI_IMPACTING_CONDITIONS` | 5 | TMIDiscord |
| `PERTI_DEFAULT_REASONS` | 3 | TMIDiscord |
| `PERTI_ARTCC_CONUS` | 22 | tmi-publish.php, tiers/query.php |
| `PERTI_ARTCC_ALL` | 24 | tmi-publish.php |
| `PERTI_INTL_ORGS` | 4 | tmi-publish.php |
| `PERTI_NAMED_GROUPS` | 10 | tiers/query.php |
| `PERTI_REGION_PREFIXES` | 9 | tiers/query.php |
| `PERTI_PROGRAM_TYPES` | 7 | programs.php |
| `PERTI_GDP_TYPES` | 3 | transition.php |
| `PERTI_ENTRY_TYPES` | 8 | entries.php |
| `PERTI_COORDINATED_ENTRY_TYPES` | 7 | cancel.php |
| `PERTI_COORDINATION_MODES` | 3 | submit_proposal.php |
| `PERTI_MODELING_STATUSES` | 2 | simulate.php, submit_proposal.php, publish.php |

### PHP Function

| Function | Purpose |
|----------|---------|
| `perti_detect_element_type($element)` | Returns `'APT'`/`'ARTCC'`/`'FCA'`/`'FIX'`/`'AIRWAY'`/`'MULTI'`/`'OTHER'`/`null` |

---

## PERTI Constants Reference (v1.5.0)

### JS Namespaces
- `PERTI.ATFM` - TMI types, constraint types, VIP/space types, **NTML qualifiers, reason categories/causes**
- `PERTI.FACILITY` - Facility lists, airline codes, regional carriers, **facility name map, cross-border facilities**
- `PERTI.WEATHER` - Weather categories (VMC/LVMC/IMC/LIMC/VLIMC)
- `PERTI.STATUS` - Operational statuses
- `PERTI.COORDINATION` - Advisory types, roles, hotlines
- `PERTI.GEOGRAPHIC` - DCC regions, ARTCC centers/topology, VATSIM divisions
- `PERTI.UI` - Demand colors, status colors, CARRIER_COLORS, ARTCC_COLORS, ARTCC_LABELS
- `PERTI.SUA` - SUA type names, groups, line types, layer mappings
- `PERTI.ROUTE` - Route token/segment types, oceanic tracks, procedure formats
- `PERTI.CODING` - Regex patterns for aviation identifiers

### Key Helper Functions

**Geographic:**
- `PERTI.getDCCRegion(artcc)` - Get DCC region for ARTCC
- `PERTI.getDCCColor(region)` - Get color for DCC region
- `PERTI.getARTCCCenter(artcc)` - Get [lng, lat] coordinates
- `PERTI.getARTCCNeighbors(artcc)` - Get tier 1 neighbors
- `PERTI.getRegionOrder(region)` - Get display order
- `PERTI.getCONUSARTCCs()` - Get CONUS ARTCC list
- `PERTI.getAllARTCCs()` - Get all US ARTCCs

**UI:**
- `PERTI.getCarrierColor(icao)` - Get airline display color
- `PERTI.getARTCCColor(code)` - Get ARTCC display color
- `PERTI.getARTCCLabel(code)` - Get ARTCC human-readable label

**Airport/ARTCC Normalization (v1.4.0):**
- `PERTI.normalizeIcao(code)` - FAA/IATA 3-letter → ICAO 4-letter (region-aware)
- `PERTI.denormalizeIcao(icao)` - ICAO 4-letter → FAA/IATA 3-letter (region-aware)
- `PERTI.normalizeArtcc(code)` - Resolve aliases + strip K-prefix from ARTCC/FIR codes
- `PERTI.resolveArtcc(code)` - Resolve ARTCC/FIR alias to canonical code (no K-strip)

**Facility/ATFM:**
- `PERTI.getAirlineCode(icao)` - Convert ICAO to IATA
- `PERTI.isCoordinationRequired(tmiType)` - Check if coordination needed

**SUA Display:**
- `PERTI.getSUALayerGroup(suaType)` - Get layer group for SUA type
- `PERTI.getSUATypeName(suaType)` - Get display name for SUA type
- `PERTI.isSUALineType(suaType)` - Check if SUA renders as line

**Pattern Matching:**
- `PERTI.isAirway(str)`, `PERTI.isAirportICAO(str)`, `PERTI.isFix(str)`
- `PERTI.isARTCC(str)`, `PERTI.isTRACON(str)`, `PERTI.isProcedure(str)`
- `PERTI.isMilitaryCallsign(str)`, `PERTI.classifyAircraftType(type)`
- `PERTI.parseRouteSegment(segment)`

---

### Airport Tier Updates (v1.6.0)

| Date | Change | Files Affected |
|------|--------|----------------|
| 2026-02-08 | ASPM77 → ASPM82 (82 airports) | `facility-hierarchy.js`, `lib/perti.js` |
| 2026-02-08 | Add OPSNET45 (45 airports) | `facility-hierarchy.js` |

**Details:**
- Renamed `AIRPORT_GROUPS.ASPM77` → `AIRPORT_GROUPS.ASPM82`
- Added `AIRPORT_GROUPS.OPSNET45` (new FAA grouping)
- Updated `FACILITY_LISTS.ATCT` to match ASPM82 list
- Removed: ALB, CHS, RIC
- Added: APA, ASE, BJC, BOI, DAY, GYY, HPN, ISP, MHT, OXR, PSP, RFD, SWF, VNY
- Hierarchy: Core30 → OEP35 → OPSNET45 → ASPM82

---

## CSS Globalization (v1.6.0)

Hardcoded CSS color values replaced with CSS custom properties from `assets/css/perti-colors.css`.

### Token Definitions Added to perti-colors.css

| Token | Value | Purpose |
|-------|-------|---------|
| `--impact-direct` | `#FF4444` | Direct weather impact severity |
| `--impact-clear` | `#4CAF50` | Clear/no-impact indicator |
| `--nav-accent-cyan` | `#4dd0e1` | Mobile nav accent color |
| `--theme-text-gray` | `#737491` | Bootstrap theme gray text |
| `--theme-text-gray-dark` | `#4a4b65` | Bootstrap theme dark gray text |
| `--theme-text-muted` | `#9e9fb4` | Bootstrap theme muted text |

### CSS Files Tokenized

| File | Colors Before | Colors After | Notes |
|------|--------------|-------------|-------|
| `perti_theme.css` | (previously done) | — | PERTI-specific overrides, already tokenized |
| `initiative_timeline.css` | (previously done) | — | Timeline component, already tokenized |
| `tmi-publish.css` | (previously done) | — | TMI publisher styles, already tokenized |
| `info-bar.css` | (previously done) | — | Info bar component, already tokenized |
| `weather_radar.css` | (previously done) | — | Weather radar overlay, already tokenized |
| `weather_hazards.css` | (previously done) | — | Weather hazard display, already tokenized |
| `weather_impact.css` | 41 | 6 | Hazard borders → `--hazard-*-start`, impact → `--impact-direct`/`--impact-clear`, grays → `--gray-*` |
| `mobile.css` | 43 | 13 | Nav gradient → `--dark-bg-*`, accent → `--nav-accent-cyan`, grays → `--gray-*` |
| `theme.css` | 1,119 | 583 | Compiled Bootstrap 4.5.2; 15 base colors bulk-replaced (536 instances); remaining are `#fff` + computed darken/lighten variants |

### CSS Files Skipped

| File | Colors | Reason |
|------|--------|--------|
| `plugins/datetimepicker.css` | 104 | Third-party vendor plugin; tokenizing would complicate updates |

---

## Final Audit & Fixes (v1.7.0)

Comprehensive audit performed 2026-02-08. Four parallel agents audited JS null-safety, CSS token definitions, PHP constant references, and script/CSS load order.

### Critical Fixes

| # | Severity | Issue | Fix |
|---|----------|-------|-----|
| 1 | **CRITICAL** | `perti.js` not loaded on any page — all PERTI.* silently falling back | Added `<script>` tag in `header.php` before `facility-hierarchy.js` |
| 2 | **CRITICAL** | `colors.js` not loaded — PERTIColors references falling back | Added `<script>` tag in `header.php` after `perti.js` |
| 3 | **CRITICAL** | CSS load order: `theme.css` loaded before `perti-colors.css` | Swapped order — definitions now load first |
| 4 | **CRITICAL** | 2 undefined CSS tokens in `tmi-compliance.css` | Added `--light-bg-elevated` and `--light-bg-primary` to `perti-colors.css` |

### JS Null-Safety Fixes

| # | Severity | File | Issue | Fix |
|---|----------|------|-------|-----|
| 5 | HIGH | `demand.js:5856` | Bare `PERTI.UI.CARRIER_COLORS.OTHER` | Added `PERTI.UI && PERTI.UI.CARRIER_COLORS` check |
| 6 | HIGH | `tmi-publish.js:3636` | Spreading `undefined` on COORDINATION_REQUIRED_TYPES | Added deep `.COORDINATION_REQUIRED_TYPES` check |
| 7 | HIGH | `nod.js:3347,3496–3500` | 6 bare `FacilityHierarchy.*` references | Added `typeof FacilityHierarchy` guards via `_FH` alias |
| 8 | HIGH | `route-maplibre.js:3218–3373` | 4 bare `FacilityHierarchy.*` references | Added `typeof` guards on all 4 sites |
| 9 | HIGH | `tmi-active-display.js:70–389` | 12 bare `FacilityHierarchy.*` references | Rewired getters through `_FH()` helper |
| 10 | MED | `rate-colors.js:99` | Missing `_PERTI_RC.WEATHER` check | Added `&& .WEATHER && .WEATHER.CATEGORIES` |
| 11 | MED | `colors.js:105,124` | Missing `_PERTI.GEOGRAPHIC` check | Added intermediate `.GEOGRAPHIC` checks |
| 12 | MED | `demand.js:915,1062,1110` | 3 missing deep checks | Added `.ARTCC_COLORS`, `.getDCCRegion`, `.DCC_REGION_ORDER` |
| 13 | MED | `gdt.js:80` | Missing `.AIRLINE_CODES` check | Added `&& PERTI.FACILITY.AIRLINE_CODES` |
| 14 | MED | `splits.js:96` | Missing `.ARTCC_CENTERS` check | Added `&& PERTI.GEOGRAPHIC.ARTCC_CENTERS` |
| 15 | MED | `plan.js:2388` | Missing `.ARTCC_CONUS` deep check | Added `&& .FACILITY_LISTS.ARTCC_CONUS` |

### Audit Results Summary

| Layer | Files Checked | Result |
|-------|--------------|--------|
| **JS PERTI refs** | 23 files, ~75 reference sites | All 15 issues fixed; 23/23 PASS |
| **CSS tokens** | 10 consumer files, ~1,000 var() refs | 2 undefined tokens fixed; 10/10 PASS |
| **PHP constants** | 16 consumer files | 16/16 PASS, 0 issues |
| **Load order** | header.php + 13 page files | perti.js/colors.js added; CSS order fixed |

### Dependency Tree

See `docs/refs/globalization-dependency-tree.md` for the complete dependency tree.

---

## Notes

1. **Script Load Order**: `header.php` loads `perti.js` → `colors.js` → `facility-hierarchy.js` (all before consumer scripts)
2. **Fallback Pattern**: All JS migrations include hardcoded fallbacks for standalone operation
3. **PHP Include Guard**: `perti_constants.php` uses `PERTI_CONSTANTS_LOADED` define to prevent double-include
4. **Testing**: Pages should work without perti.js loaded (graceful degradation)
5. **Hookify**: `.claude/hookify.enforce-perti-namespace.local.md` warns on new inline aviation constants
