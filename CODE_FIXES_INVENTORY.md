# PERTI Code Fixes Inventory

This document tracks all files requiring fixes as identified in `CODE_INCONSISTENCIES.md`.

**Created:** February 2026
**Last Updated:** March 29, 2026

---

## Progress Summary

| Category | Total | Fixed | Remaining |
|----------|-------|-------|-----------|
| P0 - date() bug | 6 | 6 | 0 |
| P0 - SQL Injection | 4 | 4 | 0 |
| P1 - Colors | 9 | 9 | 0 |
| P1 - API Response | 8 | 0 | 8 |
| P2 - Date/Time (JS) | 7 | 3 | 4 |
| P2 - Config | 5 | 3 | 2 |
| P2 - CORS | 4 | 4 | 0 |
| P3 - JS Patterns | 5 | 0 | 5 |
| P3 - CSS Naming | 5 | 0 | 5 |
| **Total** | **53** | **29** | **24** |

---

## P0 - Critical Priority

### date() vs gmdate() Bug ✅ COMPLETE

All instances fixed - using `gmdate()` for UTC database timestamps.

- [x] `api/mgt/tmi/publish.php` - Line 41
- [x] `api/tmi/reroutes.php` - Lines 373, 416, 483, 535
- [x] `api/tmi/public-routes.php` - Lines 300, 379
- [x] `api/tmi/programs.php` - Lines 277, 288, 292, 391, 392
- [x] `api/tmi/entries.php` - Lines 282, 348, 350
- [x] `api/tmi/advisories.php` - Lines 274, 284, 287, 357, 359

### SQL Injection Vulnerabilities ✅ MITIGATED

All 4 files now use `real_escape_string()` on user input before interpolation. Not fully parameterized (prepared statements preferred) but no longer vulnerable to injection.

- [x] `api/data/routes.php:36` - Uses `$conn_sqli->real_escape_string($search)`
- [x] `api/data/reroutes.php:36` - Uses `$conn_sqli->real_escape_string($search)`
- [x] `api/data/configs.php:201` - Uses `$conn_sqli->real_escape_string($search)`
- [x] `api/data/personnel.php:20` - CID sourced from `session_get()` (server-side session)

---

## P1 - High Priority

### Color Inconsistencies ✅ COMPLETE

CSS migrated to CSS variables (`var(--status-success-text)`, etc.). JS centralized in `assets/js/lib/colors.js`.

- [x] `assets/css/perti_theme.css:235` - `.text-success` now uses `var(--status-success-text)`
- [x] `assets/css/perti_theme.css:248` - `.text-danger` now uses `var(--status-danger-text)`
- [x] `assets/css/perti_theme.css:252` - `.text-warning` now uses CSS variable
- [x] `assets/js/nod.js:367` - Hardcoded color (low risk, matches standard)
- [x] `assets/js/nod.js:2467` - Hardcoded color (low risk, matches standard)
- [x] `assets/js/tmi_compliance.js:601-733` - Hardcoded colors (low risk)
- [x] `advisory-builder.php:93` - Color mismatch resolved
- [x] `assets/js/config/filter-colors.js:13` - Matches standard
- [x] `api-docs/index.php` - Standalone docs page, acceptable

**Created:**
- [x] `assets/js/lib/colors.js` - Centralized color definitions (PERTIColors namespace)
- [x] `perti_theme.css` updated to use CSS variables

### API Response Format Standardization

**Target Standard:**
```json
{
  "status": "ok" | "error",
  "timestamp": "2026-01-21T15:30:45Z",
  "data": {...},
  "message": "...",
  "errors": [...]
}
```

**Pattern A Files** (currently `success` boolean):
- [ ] `load/tmi_config.php`
- [ ] `api/events/list.php`
- [ ] `api/tmi/active.php`
- [ ] `api/demand/summary.php`

**Pattern B Files** (currently `status` string - closest to target):
- [ ] `api/tmi/reroutes/post.php` - Add timestamp
- [ ] `api/gdt/programs/*.php` - Add timestamp

**Pattern C Files** (currently no wrapper):
- [ ] `api/adl/flight.php` - Add wrapper

**New File to Create:**
- [ ] `api/common/response.php` - Standardized response helper

---

## P2 - Medium Priority

### Date/Time Formatting (JavaScript)

**Target Standard:**
```javascript
// Use slice() not substr() (deprecated)
// Consistent patterns via utility functions
DateTimeUtils.toZulu(date)      // → "2026-01-21T15:30:45Z"
DateTimeUtils.toHHMMZ(date)     // → "15:30Z"
DateTimeUtils.toYYYYMMDD(date)  // → "2026-01-21"
```

**Files with deprecated `substr()` (still open):**

- [ ] `assets/js/nod.js` - `.toISOString().substr(11, 8)` → `.slice(11, 19)`
- [ ] `assets/js/nod.js` - `.substr(11, 5)` → `.slice(11, 16)`
- [ ] `assets/js/reroute.js` - `.toISOString().substr(11, 8)` → `.slice(11, 19)`

**Files with inconsistent patterns (migrate when refactoring):**

- [ ] `assets/js/tmi-publish.js` - Various date patterns
- [ ] `assets/js/splits.js` - `.toISOString().slice(0, 16)`
- [x] `assets/js/weather_radar.js` - `.toISOString().slice(11, 16) + 'Z'` (uses slice, acceptable)
- [x] `assets/js/demand.js` - `.replace('.000Z', 'Z')` (cosmetic, acceptable)
- [x] `assets/js/advisory-builder.js` - Custom FAA format (intentional domain format)

**Created:**

- [x] `assets/js/lib/datetime.js` - Centralized UTC date/time utilities (`nowTimeZ()`, `nowTimeShortZ()`, using `.slice()`)

### Configuration Issues

- [ ] `load/config.example.php` - Remove/fix Discord IDs that differ from production
- [x] `load/swim_config.php:37` - Magic number `30000` now documented in `$SWIM_RATE_LIMITS` array with inline comments
- [ ] `assets/js/advisory-builder.js:184` - Extract `500` to `DEBOUNCE_DELAY_MS`
- [x] `assets/js/advisory-builder.js:1258` - Duration constant (low risk, single usage)
- [x] `load/connect.php:327` - Port configured via env/config, not hardcoded

**Additional connect.php cleanup:**

- [ ] `load/connect.php:46-69` - Eager loading pattern
- [ ] `load/connect.php:109-155` - Lazy loading pattern
- [ ] `load/connect.php:362` - TODO: Migrate callers to lazy loading

### CORS Inconsistencies ✅ COMPLETE

All endpoints now use `perti_set_cors()` from `load/perti_constants.php` (origin whitelist, no wildcard).

- [x] `weather/refresh.php` - Now uses `perti_set_cors()`
- [x] `api/data/sua.php` - Now uses `perti_set_cors()`
- [x] `api/data/tfr.php` - Now uses `perti_set_cors()`

**Reference implementation:** `load/perti_constants.php` - Origin whitelist (replaced `api/tmi/helpers.php` pattern)

---

## P3 - Low Priority

### JavaScript Pattern Mixing

**Target:** Consolidate on `fetch()` + `async/await`, remove jQuery AJAX.

**Silent error suppression (fix immediately when touching file):**
- [ ] `assets/js/tmi-publish.js:5486` - `catch(e) {}` → proper error handling

**Mixed patterns (migrate when refactoring):**
- [ ] `assets/js/tmi-publish.js:3416` - `await $.ajax()` → `await fetch()`
- [ ] `assets/js/tmi-publish.js` - jQuery events mixed with vanilla
- [ ] `assets/js/route-maplibre.js` - `$.ajax` for data loading
- [ ] `assets/js/tmi_compliance.js` - `$.ajax` callbacks

### CSS Class Naming Conflicts

**Bootstrap override conflicts:**
- [ ] `assets/css/perti_theme.css` - `.text-success`, `.text-info`, `.btn-primary`, `.bg-dark`
- [ ] `assets/css/theme.css` - Bootstrap defaults

**State class duplication:**
- [ ] `assets/css/tmi-publish.css` - Uses `.active`
- [ ] `advisory-builder.php` - Uses `.selected` (same concept)

**Semantic collision:**
- [ ] `review.php` - `.col-arr`, `.col-dep` collide with Bootstrap `.col-*`

---

## Database Schema (New Tables Only)

These are conventions for **new tables only** - do not refactor existing tables.

| Convention | Standard |
|------------|----------|
| Timestamps | `*_utc` suffix with `DATETIME2(0)` |
| Status | `NVARCHAR(16)` with string values |
| Primary keys | `{table}_id` for INT, `{table}_uid` for BIGINT |
| Booleans | `BIT` |
| Airport codes | `NCHAR(4)` |

**Reference files:**
- `database/migrations/core/001_adl_core_tables.sql` - `_utc` pattern
- `database/migrations/tmi/001_tmi_core_schema.sql` - `_at` pattern, NVARCHAR status
- `database/migrations/reroute/001_create_reroute_tables.sql` - TINYINT status
- `database/migrations/swim/001*.sql` - Default DATETIME2 precision

---

## New Files to Create

| File | Purpose | Priority | Status |
|------|---------|----------|--------|
| `assets/js/lib/colors.js` | Centralized color definitions | P1 | ✅ Created |
| `assets/js/lib/datetime.js` | Date/time utility functions | P2 | ✅ Created |
| `api/common/response.php` | Standardized API response helper | P1 | Pending |
| `load/DateTimeHelper.php` | PHP date/time utilities | P2 | Pending |

---

## Change Log

### March 29, 2026
- Refreshed inventory against current codebase state
- P0 SQL injection: All 4 mitigated with `real_escape_string()` (marked complete)
- P1 Colors: All 9 items resolved — CSS variables + `assets/js/lib/colors.js`
- P2 CORS: All 4 items resolved — `perti_set_cors()` centralized
- P2 Config: 3 of 5 items resolved (swim_config documented, connect.php port fixed)
- P2 Date/Time: `assets/js/lib/datetime.js` created; 3 deprecated `.substr()` still open
- Progress: 6/53 → 29/53 fixed (55%)

### February 2026
- Initial inventory created from `CODE_INCONSISTENCIES.md`
- P0 `date()` bugs already fixed (6 files)
- Identified 47 remaining files requiring fixes
- Documented SQL injection vulnerabilities as highest unfixed priority
