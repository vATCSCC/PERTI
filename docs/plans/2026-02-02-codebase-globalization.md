# Codebase Globalization Implementation Plan

**Date:** 2026-02-02
**Status:** Draft
**Author:** Claude (AI-assisted design)
**Branch:** `feature/codebase-globalization`

## Executive Summary

This document details the plan to consolidate scattered definitions across the PERTI codebase into single sources of truth. The goal is to eliminate duplication, resolve conflicts, and establish authoritative modules for facility data, color schemes, carrier classifications, and normalization functions.

### Key Problems Identified

| Problem | Impact | Files Affected |
|---------|--------|----------------|
| **DCC Region colors conflict** | Northeast shows different blues on different pages | 4+ files |
| **PHL→ARTCC mapping wrong** | tmi-publish.js says ZDC, should be ZNY | 2 files |
| **Airport tiers duplicated** | CORE30/OEP35/ASPM77 lists copied in 3 files | ~400 lines |
| **Carrier lists duplicated** | Same arrays in nod.js, route-maplibre.js | ~100 lines |
| **No centralized ICAO normalization** | 4 different implementations | 4 files |

### Success Metrics

| Metric | Current | Target |
|--------|---------|--------|
| **Files with DCC region definitions** | 5 | 1 |
| **Files with carrier lists** | 3 | 1 |
| **Files with airport tier lists** | 3 | 1 |
| **ICAO normalization implementations** | 4 | 1 |
| **Lines of duplicated config** | ~900 | 0 |

---

## 1. Current State Analysis

### 1.1 Centralized Modules (Working Well)

| Module | Location | Status |
|--------|----------|--------|
| `PERTIDateTime` | `lib/datetime.js`, `lib/DateTime.php` | ✅ Fully centralized |
| `PERTIConstants` | `config/constants.js` | ✅ Mostly centralized |
| `PERTII18n` | `lib/i18n.js` | ✅ Working, rollout in progress |
| `PERTIColors` | `lib/colors.js` | ⚠️ Centralized but not fully adopted |
| `FacilityHierarchy` | `facility-hierarchy.js` | ⚠️ Most comprehensive, but others don't use it |

### 1.2 Duplicated Definitions

#### DCC Regions

| File | Structure | Canada Handling | Colors Match? |
|------|-----------|-----------------|---------------|
| `facility-hierarchy.js:86-143` | Rich objects | Single `CANADA` + `MEXICO` + `CARIBBEAN` | ✅ Canonical |
| `filter-colors.js` | `mapping` + `colors` | Split `CANADA_EAST`/`CANADA_WEST` | ✅ Same hex |
| `lib/colors.js:94-127` | `region` + `regionMapping` | Split `CANADA_EAST`/`CANADA_WEST` | ✅ Same hex |
| `nod.js:3181-3200` | Object with fallback | Uses FILTER_CONFIG fallback | ✅ Same hex |
| `route-maplibre.js:3130-3143` | Local definitions | US-only (5 regions) | ❌ **DIFFERENT HEX VALUES** |

**Conflict Detail - route-maplibre.js uses wrong colors:**
```javascript
// route-maplibre.js (WRONG)
WEST: '#e15759', MIDWEST: '#59a14f', SOUTHEAST: '#edc948', NORTHEAST: '#4e79a7'

// All other files (CORRECT)
WEST: '#dc3545', MIDWEST: '#28a745', SOUTHEAST: '#ffc107', NORTHEAST: '#007bff'
```

#### Airport→ARTCC Lookups

| File | Implementation | Correct? |
|------|----------------|----------|
| `facility-hierarchy.js` | Loads from `apts.csv` dynamically | ✅ Yes |
| `tmi-publish.js:4316-4370` | 75 airports hardcoded | ❌ PHL→ZDC is wrong |
| Python `facility_hierarchy.py:54-67` | Override system for PHL→ZNY | ✅ Yes |

**PHL Edge Case:**
- Geographic containment: ZDC (Philadelphia is within ZDC's lateral boundaries)
- Operational control: ZNY (N90 TRACON reports to ZNY)
- **Correct answer: ZNY** (operational hierarchy takes precedence)

#### Carrier Classifications

| File | Lists Defined |
|------|---------------|
| `config/constants.js:157` | `REGIONAL_CARRIERS` only |
| `nod.js:3506-3512` | `MAJOR_CARRIERS`, `REGIONAL_CARRIERS`, `FREIGHT_CARRIERS`, `MILITARY_PREFIXES` |
| `route-maplibre.js:3345-3348` | Same 4 lists duplicated |

#### Airport Tiers

| File | Implementation |
|------|----------------|
| `nod.js:3424-3440` | `CORE30_AIRPORTS`, `OEP35_AIRPORTS`, `ASPM77_AIRPORTS` arrays |
| `route-maplibre.js:3187-3206` | Same arrays duplicated |
| `facility-hierarchy.js` | Loads from `apts.csv` (Core30, OEP35, ASPM77 columns) |

#### Aircraft Manufacturer Classification

| File | Implementation |
|------|----------------|
| `demand.js:961` | `AIRCRAFT_MANUFACTURERS` with rich data |
| `nod.js:3447-3470` | `AIRCRAFT_MANUFACTURER_PATTERNS`, `AIRCRAFT_MANUFACTURER_COLORS` |
| `route-maplibre.js:3228-3248` | Same patterns and colors duplicated |

#### ICAO Normalization

| File | Function | Notes |
|------|----------|-------|
| `nod-demand-layer.js:1611` | `normalizeAirportCode()` | Handles K/C/P prefixes |
| `procs_enhanced.js:91` | `normalizeAirport()` | Excludes Z-prefix codes |
| `gdt.js:546, 7661` | Inline checks | Fragmented |
| `playbook-cdr-search.js:498` | `normalizeAirportCode()` | Slightly different logic |

---

## 2. Design Decisions

### 2.1 Canada Region Handling

**Decision:** Use single `CANADA` region (not split EAST/WEST)

**Rationale:**
- `FacilityHierarchy` already defines it this way
- Operationally, VATSIM treats Canada as one division
- Can add sub-regions in metadata if needed later
- Reduces complexity

**Migration:** Update `filter-colors.js` and `lib/colors.js` to use single CANADA

### 2.2 Color Authority

**Decision:** `FacilityHierarchy.DCC_REGIONS` is the canonical source

**Rationale:**
- Has richest metadata (name, artccs[], color, bgColor, textClass)
- Already used by tmi-active-display.js and tmi-publish.js
- Most complete region coverage (includes Mexico, Caribbean)

**Migration:**
- `route-maplibre.js` must adopt these colors
- Other files should reference `FacilityHierarchy.DCC_REGIONS[region].color`

### 2.3 PHL ARTCC Assignment

**Decision:** PHL → ZNY (not ZDC)

**Rationale:**
- N90 TRACON controls PHL approach
- N90 reports to ZNY
- This matches Python `facility_hierarchy.py` overrides
- Operational hierarchy > geographic containment

**Migration:** Fix `tmi-publish.js:4323` to remove hardcoded mapping, use FacilityHierarchy

### 2.4 Module Structure

**Decision:** Extend `FacilityHierarchy` rather than creating new module

**Rationale:**
- Already has most comprehensive data
- Already loaded by key pages
- Avoids adding another dependency
- Single source of truth principle

---

## 3. Implementation Plan

### Phase 1: Fix Critical Conflicts (Week 1)

#### 1.1 Fix route-maplibre.js Colors
**Priority:** HIGH (visual inconsistency)

```javascript
// BEFORE (route-maplibre.js:3138)
const DCC_REGION_COLORS = {
    'WEST': '#e15759',           // Wrong red
    'MIDWEST': '#59a14f',        // Wrong green
    ...
};

// AFTER
const DCC_REGION_COLORS = (typeof FacilityHierarchy !== 'undefined')
    ? Object.fromEntries(
        Object.entries(FacilityHierarchy.DCC_REGIONS)
            .map(([key, val]) => [key, val.color])
      )
    : { /* fallback */ };
```

**Files to modify:**
- `assets/js/route-maplibre.js` (lines 3130-3143)

#### 1.2 Remove AIRPORT_TO_ARTCC from tmi-publish.js
**Priority:** HIGH (incorrect data)

```javascript
// BEFORE (tmi-publish.js:4316-4370)
const AIRPORT_TO_ARTCC = {
    'PHL': 'ZDC', 'KPHL': 'ZDC',  // WRONG
    ...
};

// AFTER
const AIRPORT_TO_ARTCC = FacilityHierarchy.AIRPORT_TO_ARTCC || {};
```

**Files to modify:**
- `assets/js/tmi-publish.js` (delete lines 4316-4370, use FacilityHierarchy)

### Phase 2: Consolidate Carrier Lists (Week 1-2)

#### 2.1 Extend constants.js
Add all carrier categories to `config/constants.js`:

```javascript
const PERTIConstants = {
    // ... existing

    // Carrier Classifications
    MAJOR_CARRIERS: ['AAL', 'UAL', 'DAL', 'SWA', 'JBU', 'ASA', 'HAL', 'NKS', 'FFT', 'AAY', 'VXP', 'SYX'],
    REGIONAL_CARRIERS: ['SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ', 'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX'],
    FREIGHT_CARRIERS: ['FDX', 'UPS', 'ABX', 'GTI', 'ATN', 'CLX', 'PAC', 'KAL', 'MTN', 'SRR', 'WCW', 'CAO'],
    MILITARY_PREFIXES: ['AIO', 'RCH', 'RRR', 'CNV', 'PAT', 'NAVY', 'ARMY', 'USAF', 'USCG', 'EXEC'],
};
```

#### 2.2 Update Consumers
**Files to modify:**
- `assets/js/nod.js` (lines 3506-3512) → use PERTIConstants
- `assets/js/route-maplibre.js` (lines 3345-3348) → use PERTIConstants

### Phase 3: Centralize Airport Tiers (Week 2)

#### 3.1 Add to FacilityHierarchy
Airport tier data should be derived from apts.csv (already loaded):

```javascript
// In facility-hierarchy.js, after loading apts.csv
const CORE30_AIRPORTS = Object.keys(AIRPORT_TO_ARTCC)
    .filter(apt => aptData[apt]?.Core30 === '1');
const OEP35_AIRPORTS = Object.keys(AIRPORT_TO_ARTCC)
    .filter(apt => aptData[apt]?.OEP35 === '1');
const ASPM77_AIRPORTS = Object.keys(AIRPORT_TO_ARTCC)
    .filter(apt => aptData[apt]?.ASPM77 === '1');
```

#### 3.2 Add Tier Colors and Helper
```javascript
const AIRPORT_TIER_COLORS = {
    'CORE30': '#dc3545',   // Red
    'OEP35': '#fd7e14',    // Orange
    'ASPM77': '#ffc107',   // Yellow
    'OTHER': '#6c757d',    // Gray
};

function getAirportTier(icao) {
    const apt = icao?.toUpperCase().replace(/^K/, '');
    if (CORE30_AIRPORTS.includes(apt) || CORE30_AIRPORTS.includes('K' + apt)) return 'CORE30';
    if (OEP35_AIRPORTS.includes(apt) || OEP35_AIRPORTS.includes('K' + apt)) return 'OEP35';
    if (ASPM77_AIRPORTS.includes(apt) || ASPM77_AIRPORTS.includes('K' + apt)) return 'ASPM77';
    return 'OTHER';
}
```

#### 3.3 Update Consumers
**Files to modify:**
- `assets/js/nod.js` (lines 3424-3440, 2037-2043)
- `assets/js/route-maplibre.js` (lines 3187-3221)

### Phase 4: Centralize ICAO Normalization (Week 2)

#### 4.1 Add to FacilityHierarchy
```javascript
/**
 * Normalize airport code to ICAO format
 * JFK -> KJFK, LAX -> KLAX, KJFK -> KJFK
 * YVR -> CYVR (Canadian), MMMX -> MMMX (Mexican)
 */
function normalizeIcao(code) {
    if (!code) return code;
    code = code.toUpperCase().trim();

    // Already 4 chars starting with K/C/P/M - likely ICAO
    if (code.length === 4 && /^[KCPM]/.test(code)) {
        return code;
    }

    // Canadian 3-letter (YVR, YYZ) -> CYVR, CYYZ
    if (code.length === 3 && code.startsWith('Y')) {
        return 'C' + code;
    }

    // US 3-letter (JFK, LAX) -> KJFK, KLAX
    if (code.length === 3 && /^[A-Z]{3}$/.test(code)) {
        return 'K' + code;
    }

    return code;
}
```

#### 4.2 Update Consumers
**Files to modify:**
- `assets/js/nod-demand-layer.js` (line 1611)
- `assets/js/procs_enhanced.js` (line 91)
- `assets/js/gdt.js` (lines 546, 7661)
- `assets/js/playbook-cdr-search.js` (line 498)

### Phase 5: Centralize Aircraft Manufacturer Data (Week 3)

#### 5.1 Create lib/aircraft.js
```javascript
const PERTIAircraft = (function() {
    'use strict';

    const MANUFACTURERS = {
        'AIRBUS': { pattern: /^A[1-3]\d{2}/, color: '#3498db', order: 1 },
        'BOEING': { pattern: /^B7[0-9]{2}|^B73[0-9]|^B78[0-9]/, color: '#e74c3c', order: 2 },
        'EMBRAER': { pattern: /^E[1-2]\d{2}|^ERJ/, color: '#2ecc71', order: 3 },
        'BOMBARDIER': { pattern: /^CRJ|^BD[0-9]{3}|^C[LS]\d{2}/, color: '#9b59b6', order: 4 },
        'MD_DC': { pattern: /^MD[0-9]{2}|^DC[0-9]{1,2}/, color: '#f39c12', order: 5 },
        'CESSNA': { pattern: /^C[0-9]{3}|^C5[0-9]{2}|^CIT/, color: '#1abc9c', order: 6 },
        'OTHER': { pattern: null, color: '#7f8c8d', order: 99 },
    };

    function getManufacturer(acType) { ... }
    function getManufacturerColor(acType) { ... }
    function stripSuffixes(acType) { ... }

    return { MANUFACTURERS, getManufacturer, getManufacturerColor, stripSuffixes };
})();
```

#### 5.2 Update Consumers
**Files to modify:**
- `assets/js/demand.js` (line 961)
- `assets/js/nod.js` (lines 1774, 3447-3470)
- `assets/js/route-maplibre.js` (lines 3228-3263)

### Phase 6: Unify Canada Region Handling (Week 3)

#### 6.1 Update filter-colors.js
Change from CANADA_EAST/CANADA_WEST to single CANADA:

```javascript
// BEFORE
mapping: {
    'CZYZ': 'CANADA_EAST', 'CZUL': 'CANADA_EAST', ...
    'CZWG': 'CANADA_WEST', 'CZEG': 'CANADA_WEST', ...
},
colors: {
    'CANADA_EAST': '#9b59b6',
    'CANADA_WEST': '#ff69b4',
}

// AFTER
mapping: {
    'CZYZ': 'CANADA', 'CZUL': 'CANADA', 'CZWG': 'CANADA', ...
},
colors: {
    'CANADA': '#6f42c1',  // Match FacilityHierarchy
}
```

#### 6.2 Update lib/colors.js
Same change to `region` and `regionMapping` objects.

---

## 4. Testing Plan

### 4.1 Visual Regression Testing
For each phase, verify:
- [ ] NOD map colors match new palette
- [ ] Route plot colors match new palette
- [ ] TMI displays show consistent region colors
- [ ] Legend items match actual rendered colors

### 4.2 Functional Testing
- [ ] `FacilityHierarchy.getParentArtcc('KPHL')` returns 'ZNY'
- [ ] `FacilityHierarchy.getRegionForFacility('ZNY')` returns 'NORTHEAST'
- [ ] `FacilityHierarchy.normalizeIcao('JFK')` returns 'KJFK'
- [ ] `FacilityHierarchy.normalizeIcao('YVR')` returns 'CYVR'
- [ ] `FacilityHierarchy.getAirportTier('KJFK')` returns 'CORE30'

### 4.3 Performance Testing
- Ensure no additional network requests (data already loaded)
- Verify no render performance regression on NOD map

---

## 5. Rollout Plan

| Phase | Scope | Risk | Rollback |
|-------|-------|------|----------|
| Phase 1 | Fix colors | Low | Revert commit |
| Phase 2 | Carrier lists | Low | Revert commit |
| Phase 3 | Airport tiers | Medium | Revert commit |
| Phase 4 | ICAO normalize | Low | Revert commit |
| Phase 5 | Aircraft data | Low | Revert commit |
| Phase 6 | Canada regions | Medium | Revert commit |

Each phase should be a separate commit for easy rollback.

---

## 6. File Change Summary

### Files to Create
- `assets/js/lib/aircraft.js` (new)

### Files to Modify
| File | Changes | Lines Affected |
|------|---------|----------------|
| `facility-hierarchy.js` | Add normalizeIcao, airport tiers | +50 |
| `config/constants.js` | Add carrier lists | +20 |
| `route-maplibre.js` | Remove local definitions | -200 |
| `nod.js` | Remove local definitions | -150 |
| `tmi-publish.js` | Remove AIRPORT_TO_ARTCC | -60 |
| `filter-colors.js` | Unify Canada | -10 |
| `lib/colors.js` | Unify Canada | -10 |
| `nod-demand-layer.js` | Use centralizedICAO | -15 |
| `procs_enhanced.js` | Use centralized ICAO | -10 |
| `gdt.js` | Use centralized ICAO | -5 |
| `playbook-cdr-search.js` | Use centralized ICAO | -10 |

**Net change:** ~-400 lines (removing duplication)

---

## 7. Open Questions

1. **Should FacilityHierarchy expose a `getDccRegionColor()` helper?**
   - Pro: Single function call instead of `FacilityHierarchy.DCC_REGIONS[region].color`
   - Con: More API surface

2. **Should we add TypeScript types for FacilityHierarchy?**
   - Would help catch errors
   - Not currently using TypeScript anywhere

3. **Should aircraft.js be a separate module or part of FacilityHierarchy?**
   - Aircraft data is somewhat unrelated to facility hierarchy
   - Separate module keeps concerns separated

---

## Appendix A: Color Palette Reference

### DCC Region Colors (Canonical)

| Region | Hex | RGB | Usage |
|--------|-----|-----|-------|
| WEST | `#dc3545` | rgb(220,53,69) | Red |
| SOUTH_CENTRAL | `#ec791b` | rgb(236,121,27) | Orange |
| SOUTHEAST | `#ffc107` | rgb(255,193,7) | Yellow |
| MIDWEST | `#28a745` | rgb(40,167,69) | Green |
| NORTHEAST | `#007bff` | rgb(0,123,255) | Blue |
| CANADA | `#6f42c1` | rgb(111,66,193) | Purple |
| MEXICO | `#8B4513` | rgb(139,69,19) | Brown |
| CARIBBEAN | `#e83e8c` | rgb(232,62,140) | Pink |

### Airport Tier Colors

| Tier | Hex | Description |
|------|-----|-------------|
| CORE30 | `#dc3545` | Top 30 busiest |
| OEP35 | `#fd7e14` | Operational Evolution Partnership |
| ASPM77 | `#ffc107` | Aviation System Performance Metrics |
| OTHER | `#6c757d` | All other airports |

---

## Appendix B: Files with Duplicated Code (To Clean Up)

```
assets/js/nod.js
  - Lines 3175-3354: DCC_REGIONS, DCC_REGION_COLORS, CENTER_COLORS
  - Lines 3424-3445: CORE30_AIRPORTS, OEP35_AIRPORTS, ASPM77_AIRPORTS
  - Lines 3447-3475: AIRCRAFT_MANUFACTURER_PATTERNS, COLORS, CONFIG_PATTERNS
  - Lines 3506-3515: MAJOR_CARRIERS, REGIONAL_CARRIERS, FREIGHT_CARRIERS, MILITARY_PREFIXES

assets/js/route-maplibre.js
  - Lines 3125-3341: DCC_REGIONS, DCC_REGION_COLORS, CENTER_COLORS
  - Lines 3185-3226: Airport tier lists and colors
  - Lines 3228-3290: Aircraft manufacturer patterns and colors
  - Lines 3345-3350: Carrier lists

assets/js/tmi-publish.js
  - Lines 4316-4370: AIRPORT_TO_ARTCC hardcoded lookup
```
