# PERTI Internationalization (i18n) Tracking

> Last updated: 2026-02-02

## Overview

This document tracks the progress of globalizing the PERTI codebase for internationalization support.

## Status Summary

| Phase | Description | Status |
|-------|-------------|--------|
| 1 | Core i18n module | ✅ Complete |
| 2 | Config file updates | ✅ Complete |
| 3 | Dialog migration | 🔄 In Progress |
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

| File | Total | Migrated | Remaining | Status |
|------|-------|----------|-----------|--------|
| `tmi-publish.js` | 128 | 88 | 40 | 🔄 In Progress (69%) |
| `plan.js` | 100 | 0 | 100 | ⏳ Pending |
| `gdt.js` | 28 | 0 | 28 | ⏳ Pending |
| `tmi-active-display.js` | 22 | 0 | 22 | ⏳ Pending |
| `review.js` | 18 | 0 | 18 | ⏳ Pending |
| `sua.js` | 14 | 0 | 14 | ⏳ Pending |
| `schedule.js` | 10 | 0 | 10 | ⏳ Pending |
| `sheet.js` | 8 | 0 | 8 | ⏳ Pending |
| `tmi_compliance.js` | 8 | 0 | 8 | ⏳ Pending |
| `public-routes.js` | 7 | 0 | 7 | ⏳ Pending |
| `demand.js` | 4 | 0 | 4 | ⏳ Pending |
| `route-maplibre.js` | 3 | 0 | 3 | ⏳ Pending |
| `advisory-builder.js` | 2 | 0 | 2 | ⏳ Pending |
| `gdp.js` | 1 | 0 | 1 | ⏳ Pending |
| `initiative_timeline.js` | 1 | 0 | 1 | ⏳ Pending |
| `tmi-gdp.js` | 1 | 0 | 1 | ⏳ Pending |
| **TOTAL** | **355** | **88** | **267** | |

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

## Locale String Categories

| Category | Key Prefix | Count | Notes |
|----------|------------|-------|-------|
| Common buttons | `common.*` | 30+ | OK, Cancel, Save, etc. |
| Status labels | `status.*` | 13 | Active, Pending, etc. |
| Flight phases | `phase.*` | 8 | Arrived, Enroute, etc. |
| TMI types | `tmi.*` | 15 | GDP, GS, EDCT, etc. |
| Weight classes | `weightClass.*` | 5 | Super, Heavy, Large, Small |
| Dialog messages | `dialog.*` | 20+ | Loading, Confirm, etc. |
| Error messages | `error.*` | 15+ | Load failed, Network error, etc. |
| TMI Publish page | `tmiPublish.*` | 30+ | Page-specific strings |
| GDT page | `gdt.*` | 10+ | Page-specific strings |

---

## Notes

- All changes maintain backward compatibility (fallback to hardcoded strings if i18n not loaded)
- Carrier names and ARTCC codes are proper nouns and not translated
- Aviation-specific terms (EDCT, GDP, GS) remain in English abbreviations
