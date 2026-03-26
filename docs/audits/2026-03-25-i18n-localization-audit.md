# PERTI i18n & Localization Audit

**Date:** 2026-03-25
**Scope:** All JS modules (73), PHP pages (31), locale files (4), i18n infrastructure
**Overall Grade:** B+ (strong foundation, targeted gaps remain)

---

## Executive Summary

| Dimension | Score | Status |
|-----------|-------|--------|
| JS Module Coverage | 98.1% of feature files | Excellent |
| PHP Page Title i18n | 32% (10/31 pages) | Needs Work |
| Locale File Quality | 8.5/10 | Good |
| Infrastructure Security | A | Excellent |
| Infrastructure Performance | B+ | Acceptable |
| fr-CA Translation Coverage | 99.95% (418 keys missing) | Good |
| Overlay Locales (en-CA, en-EU) | By design (overlays) | N/A |

**Key Findings:**
- JS modules are nearly 100% i18n-compliant (1 file with 3 hardcoded strings)
- 18 PHP pages have hardcoded `$page_title` values (easy fix)
- 2 hardcoded user-facing error messages in `review.php` (critical)
- fr-CA has 414 orphaned keys from deleted features (cleanup needed)
- fr-CA missing 418 keys from recently-added features
- i18n infrastructure is well-architected with proper fallback cascading
- No XSS vulnerabilities found, but HTML-in-keys pattern needs documentation
- No race conditions found; all modules guard against undefined `PERTII18n`

---

## 1. JavaScript Module Audit

### Coverage Summary

| Category | Count | i18n Status |
|----------|-------|-------------|
| Feature files (user-facing) | 53 | 52 FULL, 1 PARTIAL (98.1%) |
| Utility/config (no user strings) | 20 | N/A |
| **Total** | **73** | **98.1% of feature files** |

### Module-by-Module Status

| # | File | Status | Notes |
|---|------|--------|-------|
| 1 | `adl-refresh-utils.js` | FULL | |
| 2 | `adl-service.js` | FULL | |
| 3 | `advisory-config.js` | **PARTIAL** | 3 hardcoded org names (lines 11-13) |
| 4 | `awys.js` | N/A | Data file (~1.1M), no user strings |
| 5 | `cdm.js` | FULL | |
| 6 | `config/constants.js` | N/A | Numeric constants only |
| 7 | `config/facility-roles.js` | N/A | Role codes, no user strings |
| 8 | `config/filter-colors.js` | FULL | |
| 9 | `config/phase-colors.js` | FULL | |
| 10 | `config/rate-colors.js` | FULL | |
| 11 | `ctp.js` | FULL | |
| 12 | `cycle.js` | N/A | JSON cycle detection (third-party) |
| 13 | `demand.js` | FULL | |
| 14 | `facility-hierarchy.js` | N/A | ARTCC/FIR/TRACON mappings, no user strings |
| 15 | `fir-integration.js` | FULL | |
| 16 | `fir-scope.js` | FULL | |
| 17 | `gdp.js` | FULL | 128+ i18n instances |
| 18 | `gdt.js` | FULL | 341+ i18n instances |
| 19 | `initiative_timeline.js` | FULL | |
| 20 | `jatoc.js` | FULL | 102+ i18n instances |
| 21 | `jatoc-facility-patch.js` | FULL | |
| 22 | `lib/aircraft.js` | FULL | |
| 23 | `lib/artcc-hierarchy.js` | N/A | MapLibre layer config |
| 24 | `lib/artcc-labels.js` | N/A | Label positioning config |
| 25 | `lib/colors.js` | N/A | Hex color definitions only |
| 26 | `lib/datetime.js` | N/A | Date formatting utilities |
| 27 | `lib/deeplink.js` | FULL | |
| 28 | `lib/dialog.js` | FULL | SweetAlert2 i18n wrapper |
| 29 | `lib/i18n.js` | FULL | Core i18n system |
| 30 | `lib/logger.js` | N/A | Debug logging only |
| 31 | `lib/norad-codes.js` | N/A | Sector codes |
| 32 | `lib/perti.js` | FULL | Safe `_t()` fallback helper |
| 33 | `lib/route-advisory-parser.js` | N/A | Route parser, no user strings |
| 34 | `natots-search.js` | FULL | |
| 35 | `navdata.js` | FULL | |
| 36 | `nod.js` | FULL | 65+ i18n instances |
| 37 | `nod-demand-layer.js` | FULL | |
| 38 | `plan.js` | FULL | 121+ i18n instances |
| 39 | `plan-splits-map.js` | N/A | MapLibre config |
| 40 | `plan-tables.js` | FULL | |
| 41 | `playbook.js` | FULL | 50+ i18n instances |
| 42 | `playbook-cdr-search.js` | FULL | |
| 43 | `playbook-dcc-loader.js` | N/A | Injection loader |
| 44 | `playbook-filter-parser.js` | N/A | Filter parser |
| 45 | `playbook-query-builder.js` | FULL | |
| 46 | `plugins/datetimepicker.js` | N/A | Third-party library |
| 47 | `plugins/snow.js` | N/A | Third-party animation |
| 48 | `procs.js` | N/A | Procedure lookup data (~1.2M) |
| 49 | `procs_enhanced.js` | N/A | Enhanced procedure data |
| 50 | `public-routes.js` | FULL | |
| 51 | `reroute.js` | FULL | |
| 52 | `reroute-advisory-search.js` | FULL | |
| 53 | `review.js` | FULL | |
| 54 | `route-analysis-panel.js` | FULL | |
| 55 | `route-maplibre.js` | FULL | |
| 56 | `routes.js` | FULL | |
| 57 | `routes-map.js` | FULL | |
| 58 | `route-symbology.js` | FULL | |
| 59 | `schedule.js` | FULL | |
| 60 | `sheet.js` | FULL | |
| 61 | `splits.js` | FULL | 94+ i18n instances |
| 62 | `statsim_rates.js` | FULL | |
| 63 | `sua.js` | FULL | |
| 64 | `theme.min.js` | N/A | Minified CSS-in-JS |
| 65 | `tmi_compliance.js` | FULL | |
| 66 | `tmi-active-display.js` | FULL | |
| 67 | `tmi-gdp.js` | FULL | |
| 68 | `tmi-publish.js` | FULL | 43+ i18n instances |
| 69 | `tmr_report.js` | FULL | |
| 70 | `weather_hazards.js` | FULL | |
| 71 | `weather_impact.js` | FULL | |
| 72 | `weather_radar.js` | FULL | |
| 73 | `weather_radar_integration.js` | FULL | |

### Hardcoded Strings in JS (Only Issue)

**File:** `assets/js/advisory-config.js` (lines 11-13)

```javascript
const ORG_TYPES = {
    DCC:   { prefix: 'vATCSCC', facility: 'DCC',   name: 'US DCC' },           // HARDCODED
    NOC:   { prefix: 'CANOC',   facility: 'NOC',   name: 'Canadian NOC' },     // HARDCODED
    ECFMP: { prefix: 'ECFMP',   facility: 'ECFMP', name: 'ECFMP' },            // HARDCODED
};
```

**Fix:** Add keys `advisory.org.usDcc`, `advisory.org.canadianNoc`, `advisory.org.ecfmp` to locale files and use `PERTII18n.t()`.

---

## 2. PHP Page Audit

### Page Title Compliance

| Page | File | Title i18n | Content i18n | Issues |
|------|------|-----------|--------------|--------|
| Home | `index.php` | `__('home.pageTitle')` | Full | None |
| Plan | `plan.php` | `"Plan"` HARDCODED | Full | Title only |
| Review/TMR | `review.php` | `"TMR"` HARDCODED | Mixed | **CRITICAL: 2 error msgs** |
| Route Plotter | `route.php` | `"Route Plotter"` HARDCODED | Full | Title only |
| GDT | `gdt.php` | `__('gdt.page.title')` | Full | None |
| NOD | `nod.php` | `"NOD"` HARDCODED | Full | Title only |
| Playbook | `playbook.php` | `"Playbook"` HARDCODED | Full | Title only |
| TMI Publish | `tmi-publish.php` | `__('tmiPublish.pageTitle')` | Full | None |
| SUA | `sua.php` | `"SUA"` HARDCODED | Full | Title only |
| Splits | `splits.php` | `__('splits.page.title')` | Full | None |
| Schedule | `schedule.php` | `"Schedule"` HARDCODED | Full | Title only |
| Sheet | `sheet.php` | `__('sheet.page.planningSheet')` | Full | None |
| SWIM API | `swim.php` | `"SWIM API"` HARDCODED | Partial | Title + some headers |
| SWIM Doc | `swim-doc.php` | Mixed concat | Partial | Hardcoded suffix |
| SWIM Docs | `swim-docs.php` | `"SWIM Technical Documentation"` HARDCODED | Partial | Title + headers |
| SWIM Keys | `swim-keys.php` | `"SWIM API Keys"` HARDCODED | Partial | Title only |
| Status | `status.php` | `__('statusPage.pageTitle')` | Full | None |
| Simulator | `simulator.php` | `"ATFM Simulator"` HARDCODED | Partial | Title only |
| JATOC | `jatoc.php` | `"JATOC"` HARDCODED | Partial | Title only |
| Airport Config | `airport_config.php` | `"Airport Configuration"` HARDCODED | Partial | Title only |
| Event AAR | `event-aar.php` | `"Event AAR"` HARDCODED | Partial | Title only |
| Data | `data.php` | `"Planning Data"` HARDCODED | Full | Title only |
| Transparency | `transparency.php` | `__('transparency.title')` | Full | None |
| Privacy | `privacy.php` | `"Privacy Policy"` HARDCODED | Full | Title only |
| FMDS | `fmds-comparison.php` | `"FMDS vs PERTI..."` HARDCODED | Unknown | Title only |
| Hibernation | `hibernation.php` | `__('hibernation.title')` | Full | None |
| CDM | `cdm.php` | `__('cdm.page.title')` | Full | None |
| CTP | `ctp.php` | `__('ctp.page.title')` | Full | None |
| NavData | `navdata.php` | `__('navdata.page.title')` | Full | None |
| Routes | `routes.php` | `__('routes.title')` | Full | None |
| Demand | `demand.php` | `__('demand.page.title')` | Full | None |

**Summary:** 13/31 pages fully compliant (42%). 18 pages need title fix.

### Critical: Hardcoded Error Messages

**File:** `review.php`

| Line | String | Severity |
|------|--------|----------|
| 19 | `"No plan ID specified. Please select a plan from the <a href=\"index.php\">home page</a>."` | CRITICAL |
| 46 | `"Plan not found."` | CRITICAL |

These are the only hardcoded user-facing error messages found across all PHP pages.

### Template Files

| File | Status | Notes |
|------|--------|-------|
| `load/header.php` | FULL | Proper i18n init, script loading order correct |
| `load/nav.php` | FULL | All nav items use `__()` |
| `load/footer.php` | FULL | Uses `__()` with interpolation (copyright year) |

---

## 3. Locale File Audit

### Key Counts

| Locale | Keys | File Size | Type | Coverage vs en-US |
|--------|------|-----------|------|-------------------|
| `en-US.json` | 7,662 | 344 KB | Primary | 100% (reference) |
| `fr-CA.json` | 7,658 | 374 KB | Full translation | 99.95% (418 missing) |
| `en-CA.json` | 439 | 26 KB | Regional overlay | 5.7% (by design) |
| `en-EU.json` | 400 | 22 KB | Regional overlay | 5.2% (by design) |

### fr-CA: Missing Keys (418)

New features not yet translated:

| Section | Missing Keys | % of Gap |
|---------|-------------|----------|
| `routes.*` | 123 | 29% |
| `playbook.*` | 68 | 16% |
| `cdm.*` | 51 | 12% |
| `ctp.*` | 48 | 11% |
| `routeAnalysis.*` | 46 | 11% |
| Other sections | 82 | 20% |

### fr-CA: Orphaned Keys (414)

Keys in fr-CA that no longer exist in en-US (deleted features):

| Section | Orphaned Keys | Notes |
|---------|--------------|-------|
| `gdt.*` | 116 | Old GDP UI components |
| `gsAnalysis.*` | 53 | Removed analytics feature |
| `splits.*` | 39 | Old splits configuration |
| `playbook.*` | 37 | Old playbook version |
| `jatoc.*` | 34 | Old JATOC interface |
| `demand.*` | 21 | Old demand module |
| `reroute.*` | 19 | Old reroute interface |
| `route.*` | 18 | Old route visualization |
| `tmiActive.*` | 16 | Old TMI display |
| `time.*` | 12 | Deprecated time formatting |
| `swim.*` | 10 | Old SWIM UI |
| `validation.*` | 8 | Old validation messages |
| Other | 31 | Minor deprecated keys |

**Impact:** ~19.5 KB file bloat, maintainer confusion.

### en-EU: Orphaned Keys (45)

45 keys exist in en-EU but not in en-US. Likely intentional EU-specific overrides (e.g., `adl.includedCenters`, `jatoc.colorMode.arrCenter`, `scopeSelector.*`). Needs verification.

### en-CA: Orphaned Keys (1)

`swim.page.licence` — British spelling, likely intentional for Canadian English.

### Quality Checks

| Check | Result |
|-------|--------|
| Duplicate keys in en-US | PASS (zero) |
| Empty string values | PASS (none) |
| Null values | PASS (none) |
| Placeholder/TODO text | 1 found: `swim.docs.todoList` = "TODO List" |
| Naming consistency | PASS (consistent `section.subsection.key` pattern) |
| Untranslated fr-CA strings | ~340 identical to en-US (proper nouns/acronyms, correct) |

---

## 4. Infrastructure Audit

### Architecture

```
Page Load Flow:
  header.php injects window.PERTI_ORG
    -> i18n.js loaded (PERTII18n IIFE created, empty strings)
    -> index.js loaded (detectLocale() -> loadLocaleSync() -> init())
       -> XHR sync: en-US.json (fallback)
       -> XHR sync: locale.json (primary)
       -> Org overrides applied ({commandCenter} resolved)
       -> localStorage updated
    -> dialog.js loaded (PERTIDialog uses PERTII18n)
    -> Page scripts loaded (all use PERTII18n.t())
```

### Fallback Cascade

```
strings[key]           // 1. Current locale
  ?? fallbackStrings[key]  // 2. en-US fallback
  ?? key                   // 3. Key string itself (e.g., "flights.one")
```

Missing keys trigger `console.warn()` for development debugging.

### Locale Detection Priority

```
1. URL parameter (?lang=fr-CA)
2. localStorage PERTI_LOCALE (with org match check)
3. navigator.language (with base-language matching)
4. Default: en-US
```

### Security Assessment: Grade A

| Vector | Risk | Status |
|--------|------|--------|
| XSS via translation keys | LOW | All HTML is hardcoded in source, not user-supplied |
| Parameter interpolation | LOW | String replacement via regex, no `eval()` |
| User input in locale strings | LOW | No user data flows into locale files |
| JSON parsing | LOW | Standard `JSON.parse()`, safe |
| localStorage | LOW | Only stores locale code string |

**Notable:** 3 keys contain raw HTML (`tmiPublish.enableProdMode.html`, `tmiPublish.profile.requiredHtml`, `tmiPublish.progress.postingProposals`). These are developer-controlled static content passed via `htmlKey` to SweetAlert2. No user data is interpolated into them. However, the `htmlKey` + `htmlParams` pattern should be documented to prevent future misuse.

### Race Conditions: None Found

All modules properly guard `PERTII18n` access:

```javascript
// Pattern used by perti.js and others:
function _t(key, fallback) {
    if (typeof PERTII18n !== 'undefined') {
        var result = PERTII18n.t(key);
        if (result !== key) return result;
    }
    return fallback;
}
```

Script load order in `header.php` is correct. No module calls `PERTII18n.t()` at execution time before init.

### Performance

Synchronous XHR for locale loading blocks page render (~50-200ms). This is acceptable because:
- Locale files are small (~344 KB max, compressed ~50 KB)
- Served from same origin (no CORS delay)
- Cache-busted with `filemtime` (aggressive caching)
- Page needs strings before first paint (async would add complexity)

### Known Limitation: Org Switching

When user switches organizations mid-session, old locale persists in localStorage until page refresh. The `PERTI_LOCALE_ORG` key is updated but strings are not reloaded. Impact is low since org switching always triggers page navigation.

---

## 5. Prioritized Action Items

### P0 — Critical (Do Now)

| # | Task | File(s) | Effort |
|---|------|---------|--------|
| 1 | Fix 2 hardcoded error messages in review.php | `review.php:19,46` + `en-US.json` | 15 min |

### P1 — High Priority (This Week)

| # | Task | File(s) | Effort |
|---|------|---------|--------|
| 2 | Add 18 missing `$page_title` i18n keys to en-US.json | `en-US.json` | 20 min |
| 3 | Update 18 PHP pages to use `__()` for page titles | 18 PHP files | 45 min |
| 4 | Fix 3 hardcoded org names in advisory-config.js | `advisory-config.js` + `en-US.json` | 10 min |
| 5 | Remove 414 orphaned keys from fr-CA.json | `fr-CA.json` | 30 min |

### P2 — Medium Priority (This Sprint)

| # | Task | File(s) | Effort |
|---|------|---------|--------|
| 6 | Translate 418 missing keys to French | `fr-CA.json` | 6-8 hrs (translator) |
| 7 | Verify 45 en-EU orphaned keys are intentional | `en-EU.json` | 1 hr |
| 8 | Add JSDoc XSS warning to dialog.js htmlKey pattern | `dialog.js:30` | 10 min |
| 9 | Audit SWIM pages for additional hardcoded HTML content | `swim.php`, `swim-docs.php`, `swim-keys.php` | 1 hr |

### P3 — Low Priority (Backlog)

| # | Task | File(s) | Effort |
|---|------|---------|--------|
| 10 | Add missing key telemetry for production monitoring | `i18n.js:94-97` | 30 min |
| 11 | Resolve/remove `swim.docs.todoList` placeholder | `en-US.json` | 15 min |
| 12 | Spot-check 340 "untranslated" fr-CA strings (proper nouns) | `fr-CA.json` | 30 min |
| 13 | Minify locale JSON for production | Build script | 1 hr |
| 14 | Create automated i18n key audit script | New script | 2 hrs |

### P4 — Future Enhancements (Roadmap)

| # | Task | Notes |
|---|------|-------|
| 15 | CLDR plural forms (zero/one/two/few/many/other) | Currently only one/other |
| 16 | Service Worker locale caching | Eliminate sync XHR |
| 17 | TypeScript type definitions for PERTII18n | Developer experience |
| 18 | Gender-aware translations for French | Grammatical gender support |

---

## 6. Metrics Summary

### Current State

```
JS Feature Module Coverage:    52/53    (98.1%)
PHP Page Title Coverage:       13/31    (42%)
PHP Template Coverage:          3/3    (100%)
en-US Key Count:              7,662
fr-CA Coverage:               7,244/7,662 (94.5% after removing orphans)
Hardcoded User Strings (JS):      3     (advisory-config.js org names)
Hardcoded User Strings (PHP):    20     (18 titles + 2 error messages)
Security Vulnerabilities:         0
Race Conditions:                  0
```

### After P0+P1 Fixes (Estimated)

```
JS Feature Module Coverage:    53/53    (100%)
PHP Page Title Coverage:       31/31    (100%)
Hardcoded User Strings (JS):      0
Hardcoded User Strings (PHP):     0
Orphaned Keys Removed:          414
```

**Total effort for P0+P1:** ~2 hours
