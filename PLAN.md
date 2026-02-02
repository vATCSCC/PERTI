# PERTI Globalization/Internationalization Implementation Plan

## Overview

This plan outlines the implementation of internationalization (i18n) support for the PERTI codebase. The goal is to externalize all user-facing strings, centralize date/number formatting, and enable future multi-language support.

## Current State Analysis

### Existing Patterns
- **Module pattern**: IIFE modules with global exports (`PERTILogger`, `PERTIDateTime`)
- **Config pattern**: Global constants in `assets/js/config/` (`FILTER_CONFIG`, `PHASE_COLORS`)
- **UI Dialogs**: 355+ Swal.fire calls with inline strings across 16 files
- **Date handling**: `PERTIDateTime` module exists but locale-specific code scattered

### Scope
| Category | Files | Occurrences | Priority |
|----------|-------|-------------|----------|
| Swal.fire dialogs | 16 | 355+ | HIGH |
| Native alert() | 27 | 80+ | HIGH |
| Status/Label maps | 7 | ~200 | MEDIUM |
| Error messages | 27 JS + 147 PHP | 400+ | MEDIUM |
| Date formatting | 13 | 50+ | LOW* |
| Number formatting | 13 | 30+ | LOW |

*Date/number are LOW because aviation uses UTC/standardized formats

---

## Implementation Phases

### Phase 1: Core i18n Module (Foundation)

**Create `assets/js/lib/i18n.js`**

```javascript
const PERTII18n = (function() {
    'use strict';

    let currentLocale = 'en-US';
    let strings = {};

    // Load locale strings (sync for initial load)
    function setLocale(locale) {
        currentLocale = locale;
    }

    function loadStrings(localeStrings) {
        strings = localeStrings;
    }

    // Translation function with interpolation
    // Usage: t('dialog.confirmDelete', { name: 'GDP-123' })
    // String: "Are you sure you want to delete {name}?"
    function t(key, params = {}) {
        let str = strings[key] || key;
        Object.keys(params).forEach(param => {
            str = str.replace(new RegExp(`\\{${param}\\}`, 'g'), params[param]);
        });
        return str;
    }

    // Pluralization helper
    // Usage: tp('flight', count) -> "1 flight" or "5 flights"
    function tp(key, count, params = {}) {
        const pluralKey = count === 1 ? `${key}.one` : `${key}.other`;
        return t(pluralKey, { count, ...params });
    }

    return {
        setLocale,
        loadStrings,
        t,
        tp,
        getLocale: () => currentLocale,
    };
})();
```

**Create `assets/locales/en-US.json`**

```json
{
  "common": {
    "ok": "OK",
    "cancel": "Cancel",
    "close": "Close",
    "save": "Save",
    "delete": "Delete",
    "confirm": "Confirm",
    "yes": "Yes",
    "no": "No",
    "loading": "Loading...",
    "error": "Error",
    "success": "Success",
    "warning": "Warning"
  },
  "status": {
    "active": "Active",
    "pending": "Pending",
    "cancelled": "Cancelled",
    "expired": "Expired",
    "draft": "Draft",
    "unknown": "Unknown"
  },
  "phase": {
    "arrived": "Arrived",
    "departed": "Departed",
    "enroute": "Enroute",
    "taxiing": "Taxiing",
    "prefile": "Prefile",
    "descending": "Descending",
    "disconnected": "Disconnected"
  },
  "dialog": {
    "confirmDelete": "Are you sure you want to delete this?",
    "confirmCancel": "Are you sure you want to cancel?",
    "yesDelete": "Yes, delete it",
    "yesCancel": "Yes, cancel it"
  },
  "error": {
    "loadFailed": "Failed to load {resource}",
    "saveFailed": "Failed to save {resource}",
    "networkError": "Network error: {message}",
    "invalidInput": "Invalid input"
  },
  "tmi": {
    "gdp": "Ground Delay Program",
    "gs": "Ground Stop",
    "edct": "EDCT",
    "reroute": "Reroute"
  }
}
```

**Files to create:**
- `assets/js/lib/i18n.js` - Core module
- `assets/locales/en-US.json` - English strings
- `assets/locales/index.js` - Locale loader

---

### Phase 2: Migrate Config Label Maps

**Update existing config files to use i18n keys**

**`assets/js/config/phase-colors.js`** changes:
```javascript
// Before
const PHASE_LABELS = {
    'arrived': 'Arrived',
    'departed': 'Departed',
};

// After
const PHASE_LABELS = {
    'arrived': 'phase.arrived',  // i18n key
    'departed': 'phase.departed',
};

// Helper that resolves i18n
function getPhaseLabel(phase) {
    const key = PHASE_LABELS[phase] || 'status.unknown';
    return PERTII18n.t(key);
}
```

**Files to update:**
- `assets/js/config/phase-colors.js` - Phase labels
- `assets/js/config/filter-colors.js` - Weight class, carrier labels
- `assets/js/sua.js` - TYPE_NAMES, GROUP_NAMES
- `assets/js/nod.js` - Status labels

---

### Phase 3: Migrate Swal.fire Dialogs (Largest Effort)

**Strategy: Create wrapper function**

```javascript
// In lib/i18n.js or lib/dialog.js
function showDialog(options) {
    const resolved = {
        ...options,
        title: options.titleKey ? PERTII18n.t(options.titleKey, options.titleParams) : options.title,
        text: options.textKey ? PERTII18n.t(options.textKey, options.textParams) : options.text,
        confirmButtonText: options.confirmKey ? PERTII18n.t(options.confirmKey) : (options.confirmButtonText || PERTII18n.t('common.ok')),
        cancelButtonText: options.cancelKey ? PERTII18n.t(options.cancelKey) : (options.cancelButtonText || PERTII18n.t('common.cancel')),
    };
    return Swal.fire(resolved);
}
```

**Migration pattern:**
```javascript
// Before
Swal.fire({
    title: 'Enable Production Mode?',
    text: 'Entries will post to LIVE channels!',
    confirmButtonText: 'Enable Production',
});

// After
showDialog({
    titleKey: 'dialog.enableProdMode.title',
    textKey: 'dialog.enableProdMode.text',
    confirmKey: 'dialog.enableProdMode.confirm',
});
```

**Files to update (by priority):**
1. `tmi-publish.js` (128 occurrences) - Highest traffic
2. `plan.js` (100 occurrences)
3. `gdt.js` (28 occurrences)
4. `tmi-active-display.js` (22 occurrences)
5. `review.js` (18 occurrences)
6. `sua.js` (14 occurrences)
7. Remaining 10 files (45 total)

---

### Phase 4: Migrate Error Messages

**JavaScript error messages:**
```javascript
// Before
throw new Error('Failed to load flight data');
console.error('Unable to connect to server');

// After
throw new Error(PERTII18n.t('error.loadFailed', { resource: 'flight data' }));
console.error(PERTII18n.t('error.networkError', { message: 'connection refused' }));
```

**PHP API responses:**
```php
// Before
return json_encode(['error' => 'Invalid request']);

// After
return json_encode(['error' => 'INVALID_REQUEST', 'message' => 'Invalid request']);
// Client handles translation based on error code
```

---

### Phase 5: Date/Time Localization (Optional Enhancement)

**Extend `PERTIDateTime` module:**
```javascript
// Add locale-aware formatting option
function formatLocalTime(date, options = {}) {
    const locale = options.locale || PERTII18n.getLocale();
    return date.toLocaleTimeString(locale, {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
        ...options
    });
}
```

Note: Aviation times should remain in UTC/Zulu format. This is only for non-operational displays.

---

## File Structure

```
assets/
├── js/
│   ├── lib/
│   │   ├── i18n.js          # NEW - Core i18n module
│   │   ├── dialog.js        # NEW - Swal wrapper
│   │   ├── datetime.js      # UPDATE - Add locale options
│   │   └── logger.js        # No changes
│   └── config/
│       ├── phase-colors.js  # UPDATE - Use i18n keys
│       └── filter-colors.js # UPDATE - Use i18n keys
├── locales/
│   ├── en-US.json           # NEW - English (default)
│   ├── en-GB.json           # FUTURE - British English
│   └── index.js             # NEW - Locale loader
```

---

## Implementation Order

| Step | Task | Effort | Dependencies |
|------|------|--------|--------------|
| 1 | Create `lib/i18n.js` module | 2 hrs | None |
| 2 | Create `locales/en-US.json` with common strings | 3 hrs | Step 1 |
| 3 | Create `lib/dialog.js` wrapper | 1 hr | Step 1 |
| 4 | Update config files to use i18n keys | 2 hrs | Step 2 |
| 5 | Migrate `tmi-publish.js` dialogs | 4 hrs | Step 3 |
| 6 | Migrate `plan.js` dialogs | 3 hrs | Step 3 |
| 7 | Migrate remaining JS files | 4 hrs | Step 3 |
| 8 | Add PHP error code mapping | 2 hrs | Step 2 |
| 9 | Testing and validation | 3 hrs | All |

**Total estimated effort: 24 hours**

---

## Migration Strategy

### Approach: Incremental with Backward Compatibility

1. **Phase 1-3 can be done file-by-file** without breaking existing code
2. **i18n module falls back to key** if string not found (graceful degradation)
3. **Existing strings work unchanged** until migrated
4. **No big-bang migration** - can be done over multiple sessions

### Testing Strategy

1. After each file migration, visually verify all dialogs render correctly
2. Search for orphaned i18n keys (keys in JSON but not used in code)
3. Search for unmigrated strings (hardcoded strings still in JS)
4. Browser console warnings for missing translations

---

## Out of Scope (Future Work)

- **Right-to-left (RTL) support** - Not needed for current user base
- **Additional languages** - Only English for now, structure supports future
- **Server-side i18n** - PHP templates not prioritized
- **Currency formatting** - Not used in PERTI
- **Timezone display** - Aviation uses UTC exclusively

---

## Success Criteria

1. All Swal.fire dialogs use i18n wrapper
2. All label/status maps use i18n keys
3. Single locale file contains all user-facing strings
4. No hardcoded English strings in main JS files
5. Adding a new language requires only a new JSON file
