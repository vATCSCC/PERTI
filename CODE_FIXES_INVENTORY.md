# PERTI Code Fixes Inventory

This document tracks all files requiring fixes as identified in `CODE_INCONSISTENCIES.md`.

**Created:** February 2026
**Last Updated:** February 2026

---

## Progress Summary

| Category | Total | Fixed | Remaining |
|----------|-------|-------|-----------|
| P0 - date() bug | 6 | 6 | 0 |
| P0 - SQL Injection | 4 | 0 | 4 |
| P1 - Colors | 9 | 0 | 9 |
| P1 - API Response | 8 | 0 | 8 |
| P2 - Date/Time (JS) | 7 | 0 | 7 |
| P2 - Config | 5 | 0 | 5 |
| P2 - CORS | 4 | 0 | 4 |
| P3 - JS Patterns | 5 | 0 | 5 |
| P3 - CSS Naming | 5 | 0 | 5 |
| **Total** | **53** | **6** | **47** |

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

### SQL Injection Vulnerabilities ⚠️ HIGH PRIORITY

Legacy files using direct string interpolation instead of prepared statements.

- [ ] `api/data/routes.php:35` - `%$search%` in LIKE clause
- [ ] `api/data/reroutes.php:35` - `%$search%` in LIKE clause
- [ ] `api/data/configs.php:184` - Unescaped search parameter
- [ ] `api/data/personnel.php:21` - Direct CID in query

**Fix Pattern:**
```php
// Before (vulnerable)
$query = "SELECT * FROM table WHERE col LIKE '%$search%'";

// After (secure)
$stmt = $pdo->prepare("SELECT * FROM table WHERE col LIKE :search");
$stmt->execute(['search' => '%' . $search . '%']);
```

---

## P1 - High Priority

### Color Inconsistencies

**Target Standard:**
| Semantic | Hex Value |
|----------|-----------|
| Success | `#28a745` |
| Danger | `#dc3545` |
| Warning | `#fd7e14` |
| Info | `#17a2b8` |

**Files to Fix:**

- [ ] `assets/css/perti_theme.css:235` - `.text-success` uses `#43ac6a` → `#28a745`
- [ ] `assets/css/perti_theme.css:248` - `.text-danger` uses `#F04124` → `#dc3545`
- [ ] `assets/css/perti_theme.css:252` - `.text-warning` uses `#E99002` → `#fd7e14`
- [ ] `assets/js/nod.js:367` - Hardcoded `#28a745` → use config
- [ ] `assets/js/nod.js:2467` - Hardcoded `#fd7e14` → use config
- [ ] `assets/js/tmi_compliance.js:601-733` - Multiple hardcoded colors → use config
- [ ] `advisory-builder.php:93` - Reroute header color mismatch
- [ ] `assets/js/config/filter-colors.js:13` - Ensure matches standard
- [ ] `api-docs/index.php` - Uses `#63BD49` and `#239BCD`

**New File to Create:**
- [ ] `assets/js/config/colors.js` - Centralized color definitions
- [ ] Update `perti_theme.css` to use CSS variables

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

**Files with deprecated `substr()`:**
- [ ] `assets/js/nod.js` - `.toISOString().substr(11, 8)` → `.slice(11, 19)`
- [ ] `assets/js/nod.js` - `.substr(11, 5)` → `.slice(11, 16)`
- [ ] `assets/js/reroute.js` - `.toISOString().substr(11, 8)` → `.slice(11, 19)`

**Files with inconsistent patterns:**
- [ ] `assets/js/tmi-publish.js` - Various date patterns
- [ ] `assets/js/splits.js` - `.toISOString().slice(0, 16)`
- [ ] `assets/js/weather_radar.js` - `.toISOString().slice(11, 16) + 'Z'`
- [ ] `assets/js/demand.js` - `.replace('.000Z', 'Z')`
- [ ] `assets/js/advisory-builder.js` - Custom FAA format

**New File to Create:**
- [ ] `assets/js/utils/datetime.js` - Centralized date/time utilities

### Configuration Issues

- [ ] `load/config.example.php` - Remove/fix Discord IDs that differ from production
- [ ] `load/swim_config.php:37` - Extract magic number `30000` to constant
- [ ] `assets/js/advisory-builder.js:184` - Extract `500` to `DEBOUNCE_DELAY_MS`
- [ ] `assets/js/advisory-builder.js:1258` - Extract `4*60*60*1000` to `DEFAULT_ADVISORY_DURATION_MS`
- [ ] `load/connect.php:327` - Extract `'5432'` to `DEFAULT_GIS_PORT`

**Additional connect.php cleanup:**
- [ ] `load/connect.php:46-69` - Eager loading pattern
- [ ] `load/connect.php:109-155` - Lazy loading pattern
- [ ] `load/connect.php:362` - TODO: Migrate callers to lazy loading

### CORS Inconsistencies

**Target:** Use `TmiResponse` CORS helper for all endpoints.

**Files using wildcard `*`:**
- [ ] `weather/refresh.php` - `Access-Control-Allow-Origin: *`
- [ ] `api/data/sua.php` - `Access-Control-Allow-Origin: *`
- [ ] `api/data/tfr.php` - `Access-Control-Allow-Origin: *`

**Reference implementation:**
- `api/tmi/helpers.php` - Whitelisted origins (correct pattern)

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

| File | Purpose | Priority |
|------|---------|----------|
| `assets/js/config/colors.js` | Centralized color definitions | P1 |
| `assets/js/utils/datetime.js` | Date/time utility functions | P2 |
| `api/common/response.php` | Standardized API response helper | P1 |
| `load/DateTimeHelper.php` | PHP date/time utilities | P2 |

---

## Change Log

### February 2026
- Initial inventory created from `CODE_INCONSISTENCIES.md`
- P0 `date()` bugs already fixed (6 files)
- Identified 47 remaining files requiring fixes
- Documented SQL injection vulnerabilities as highest unfixed priority
