# PERTI Internationalization (i18n) Tracking

> Last updated: 2026-03-12
>
> **SYSTEM STATUS: HIBERNATED** (since March 9, 2026). Core ADL ingest daemon only. i18n system is fully deployed and functional; no active development during hibernation.

## Overview

This document tracks the progress of globalizing the PERTI codebase for internationalization support.

## Status Summary

| Metric | Value |
|--------|-------|
| Translation keys (en-US) | 7,276 |
| Supported locales | 4 (en-US, fr-CA, en-CA, en-EU) |
| JS modules using i18n | 45 of 65 (69%) |
| PHP pages with i18n | 30 (all via header.php auto-include) |

| Phase | Description | Status |
|-------|-------------|--------|
| 1 | Core i18n module | ✅ Complete |
| 2 | Config file updates | ✅ Complete |
| 3 | Dialog migration | 🔄 In Progress (37% - splits done, tmi-publish 84%) |
| 4 | Error message migration | ⏳ Pending |
| 5 | Date/time localization | ⏳ Pending |

---

## Phase 1: Core Infrastructure ✅

### New Files Created

| File | Purpose | Status |
|------|---------|--------|
| `assets/js/lib/i18n.js` | Core translation module (`PERTII18n`) | ✅ |
| `assets/js/lib/dialog.js` | Swal.fire wrapper (`PERTIDialog`) | ✅ |
| `assets/locales/en-US.json` | English locale strings | ✅ |
| `assets/locales/index.js` | Locale loader with auto-detection | ✅ |

### Usage in HTML Pages

```html
<!-- Add these scripts in order -->
<script src="/assets/js/lib/i18n.js"></script>
<script src="/assets/locales/index.js"></script>
<script src="/assets/js/lib/dialog.js"></script>
```

### API Reference

```javascript
// Basic translation
PERTII18n.t('common.save')  // "Save"

// With interpolation
PERTII18n.t('error.loadFailed', { resource: 'flights' })  // "Failed to load flights"

// Pluralization
PERTII18n.tp('flight', 1)  // "1 flight"
PERTII18n.tp('flight', 5)  // "5 flights"

// Dialog helpers
PERTIDialog.success('dialog.success.saved')
PERTIDialog.error('error.loadFailed', null, { resource: 'data' })
PERTIDialog.confirm('dialog.confirmDelete.title', 'dialog.confirmDelete.text')
```

---

## Phase 2: Config File Updates ✅

| File | Changes | Status |
|------|---------|--------|
| `assets/js/config/phase-colors.js` | Added `PHASE_LABEL_KEYS`, updated `getPhaseLabel()` | ✅ |
| `assets/js/config/filter-colors.js` | Added `FILTER_I18N_KEYS`, updated `getFilterLabel()` | ✅ |

---

## Phase 3: Dialog Migration 🔄

### Swal.fire Occurrences by File

| File | Swal.fire | PERTIDialog | Status |
|------|-----------|-------------|--------|
| `splits.js` | 0 | 52 | ✅ Fully migrated |
| `tmi-publish.js` | 26 | 134 | 🔄 In Progress (84%) |
| `plan.js` | 100 | 0 | ⏳ Pending |
| `gdt.js` | 49 | 0 | ⏳ Pending |
| `tmi-active-display.js` | 22 | 0 | ⏳ Pending |
| `review.js` | 18 | 0 | ⏳ Pending |
| `tmi_compliance.js` | 15 | 0 | ⏳ Pending |
| `sua.js` | 14 | 0 | ⏳ Pending |
| `route-maplibre.js` | 13 | 0 | ⏳ Pending |
| `schedule.js` | 12 | 0 | ⏳ Pending |
| `playbook.js` | 11 | 0 | ⏳ Pending (new v18) |
| `tmr_report.js` | 11 | 0 | ⏳ Pending (new v18) |
| `demand.js` | 10 | 0 | ⏳ Pending |
| `sheet.js` | 8 | 0 | ⏳ Pending |
| `public-routes.js` | 7 | 0 | ⏳ Pending |
| `gdp.js` | 1 | 0 | ⏳ Pending |
| `initiative_timeline.js` | 1 | 0 | ⏳ Pending |
| `tmi-gdp.js` | 1 | 0 | ⏳ Pending |
| `lib/deeplink.js` | 1 | 0 | ⏳ Pending |
| ~~`advisory-builder.js`~~ | — | — | ❌ Removed (v18 PR #16) |
| **TOTAL** | **320** | **186** | **37% migrated** |

> **Note:** `jatoc.js`, `reroute.js`, `weather_impact.js`, `weather_hazards.js` use `PERTII18n.t()` for all strings and have zero `Swal.fire` calls (built with i18n from the start). `lib/dialog.js` has 4 internal `Swal.fire` calls (the PERTIDialog wrapper implementation itself — not counted above).

### Migration Pattern

**Before:**
```javascript
Swal.fire({
    title: 'Enable Production Mode?',
    text: 'Entries will post directly to LIVE Discord channels!',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Enable Production',
});
```

**After:**
```javascript
PERTIDialog.show({
    titleKey: 'tmiPublish.enableProdMode.title',
    textKey: 'tmiPublish.enableProdMode.text',
    icon: 'warning',
    showCancelButton: true,
    confirmKey: 'tmiPublish.enableProdMode.confirm',
});
```

---

## Phase 4: Error Messages ⏳

| Category | Files | Status |
|----------|-------|--------|
| JavaScript console errors | 27 | ⏳ Pending |
| PHP API responses | 147 | ⏳ Pending |

---

## Phase 5: Date/Time Localization ⏳

- Extend `PERTIDateTime` module with locale-aware formatting
- Note: Aviation times remain in UTC/Zulu format

---

## Locale Coverage

| Locale | Keys | Type | Notes |
|--------|------|------|-------|
| `en-US` | 7,276 | Full | Primary locale |
| `fr-CA` | 7,560 | Full | Canadian French |
| `en-CA` | 557 | Overlay | Canadian English (differences from en-US) |
| `en-EU` | 509 | Overlay | European English (differences from en-US) |

## Locale String Categories

| Category | Key Prefix | Approx Count | Notes |
|----------|------------|--------------|-------|
| Common buttons | `common.*` | 30+ | OK, Cancel, Save, etc. |
| Status labels | `status.*` | 13 | Active, Pending, etc. |
| Flight phases | `phase.*` | 8 | Arrived, Enroute, etc. |
| TMI types | `tmi.*` | 15 | GDP, GS, EDCT, etc. |
| Weight classes | `weightClass.*` | 5 | Super, Heavy, Large, Small |
| Dialog messages | `dialog.*` | 20+ | Loading, Confirm, etc. |
| Error messages | `error.*` | 15+ | Load failed, Network error, etc. |
| TMI Publish page | `tmiPublish.*` | 30+ | Page-specific strings |
| GDT page | `gdt.*` | 10+ | Page-specific strings |

*Total: 7,276 keys in en-US (auto-flattened from nested JSON structure).*

---

## JS Module i18n Adoption

**45 of 65 JS modules (69%)** use `PERTII18n.t()` for user-facing strings.

### Fully migrated modules (built with i18n or fully converted)
`demand.js`, `jatoc.js`, `splits.js`, `reroute.js`, `schedule.js`, `review.js`, `sua.js`, `weather_impact.js`, `weather_hazards.js`, `tmi-publish.js`, `dialog.js`, `phase-colors.js`, `filter-colors.js`

### Mostly migrated (minor gaps)
`gdt.js`, `nod.js`, `route-maplibre.js`, `tmi_compliance.js`, `weather_radar.js`

### PHP Page Coverage

All 30 PHP pages auto-include i18n scripts via `load/header.php`. Pages include:
`index.php`, `plan.php`, `sheet.php`, `route.php`, `review.php`, `schedule.php`, `demand.php`, `splits.php`, `gdt.php`, `nod.php`, `jatoc.php`, `sua.php`, `swim.php`, `tmi-publish.php`, `nav.php`, `footer.php`, and 14 others.

## Notes

- All changes maintain backward compatibility (fallback to hardcoded strings if i18n not loaded)
- Carrier names and ARTCC codes are proper nouns and not translated
- Aviation-specific terms (EDCT, GDP, GS) remain in English abbreviations
