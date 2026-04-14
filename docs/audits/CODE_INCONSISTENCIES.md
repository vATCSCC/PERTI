# PERTI Code & Database Inconsistency Report

This document identifies inconsistencies across the codebase with the goal of standardizing formats, configs, layouts, and behaviors.

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Color Definitions](#color-definitions)
3. [Date/Time Formatting](#datetime-formatting)
4. [API Response Formats](#api-response-formats)
5. [Configuration Patterns](#configuration-patterns)
6. [CSS Class Naming](#css-class-naming)
7. [JavaScript Patterns](#javascript-patterns)
8. [Database Schema](#database-schema)
9. [PHP Template Structure](#php-template-structure)
10. [Recommendations](#recommendations)

---

## Executive Summary

### Critical Issues (Immediate Action Required)

| Category | Issue | Impact |
|----------|-------|--------|
| **Colors** | 3 different greens for "success" (`#28a745`, `#43ac6a`, `#63BD49`) | Visual inconsistency |
| **API** | Mixed response wrappers (`success` vs `status` vs direct) | Client integration issues |
| **Config** | Discord IDs defined in two files with different values | Potential message routing failures |
| **Database** | Status columns use TINYINT, NVARCHAR, and BIT interchangeably | Query complexity |
| **JS** | Mixed jQuery/vanilla JS and fetch/$.ajax patterns | Maintenance burden |

### High Priority Issues

| Category | Count | Primary Files Affected |
|----------|-------|------------------------|
| Color inconsistencies | 15+ colors | `perti_theme.css`, `filter-colors.js`, `nod.js` |
| Date format variations | 20+ patterns | `tmi-publish.js`, `TMIDiscord.php`, API endpoints |
| API response structures | 4 different patterns | All `/api/` endpoints |
| CSS naming conflicts | 50+ classes | `perti_theme.css`, `theme.css` |
| JS pattern mixing | 8+ patterns | All `/assets/js/` files |

---

## Color Definitions

### Problem: Same semantic meaning, different hex values

#### Success/Green Colors
| Hex Value | Used In | Purpose |
|-----------|---------|---------|
| `#28a745` | `filter-colors.js`, `demand.js`, `phase-colors.js` | Bootstrap Green (Standard) |
| `#43ac6a` | `perti_theme.css` (line 235) | `.text-success` color |
| `#63BD49` | `perti_theme.css`, `api-docs/index.php` | Badge/Button background |

**Files:**
- [perti_theme.css:235](assets/css/perti_theme.css#L235)
- [filter-colors.js:13](assets/js/config/filter-colors.js#L13)

#### Danger/Red Colors
| Hex Value | Used In | Purpose |
|-----------|---------|---------|
| `#dc3545` | `perti_theme.css`, `filter-colors.js`, `tmi_compliance.js` | Bootstrap Red |
| `#F04124` | `perti_theme.css` (line 248) | `.text-danger` only |

#### Info/Cyan Colors
| Hex Value | Used In | Purpose |
|-----------|---------|---------|
| `#17a2b8` | `perti_theme.css`, `nod.js`, `filter-colors.js` | Bootstrap Cyan |
| `#239BCD` | `perti_theme.css`, `api-docs/index.php` | Primary brand color |
| `#249bcd` | `perti_theme.css` | Nav/link color (lowercase) |

#### Warning/Orange Colors
| Hex Value | Used In | Purpose |
|-----------|---------|---------|
| `#ffc107` | `filter-colors.js`, `demand.js` | Bootstrap Yellow |
| `#fd7e14` | `perti_theme.css`, `nod.js` | Bootstrap Orange |
| `#E99002` | `perti_theme.css` (line 252) | `.text-warning` only |

### Hardcoded Colors (Should Use Config)

| File | Line | Color | Should Use |
|------|------|-------|------------|
| `nod.js` | 367 | `#28a745` | `FILTER_CONFIG.weight.Large.color` |
| `nod.js` | 2467 | `#fd7e14` | CSS variable or config |
| `tmi_compliance.js` | 601-733 | Multiple | Centralized status colors |
| `advisory-builder.php` | 93 | `#28a745` | Match reroute color in `nod.js` |

### Reroute Color Mismatch
- **advisory-builder.php**: `.adv-header-reroute` = `#28a745` (green)
- **nod.js**: Default reroute color = `#fd7e14` (orange)
- **nod.js**: Fallback = `#17a2b8` (cyan)

---

## Date/Time Formatting

### Problem: 20+ different date formatting patterns

#### JavaScript Patterns

| Pattern | Files | Example Output |
|---------|-------|----------------|
| `.toISOString().slice(0, 16)` | `tmi-publish.js`, `splits.js` | `2026-01-21T15:30` |
| `.toISOString().slice(11, 16) + 'Z'` | `tmi-publish.js`, `weather_radar.js` | `15:30Z` |
| `.toISOString().substr(11, 8)` | `nod.js`, `reroute.js` | `15:30:45` |
| `.toISOString().substr(11, 5) + 'Z'` | `nod.js` | `15:30Z` |
| `.replace('.000Z', 'Z')` | `demand.js` | ISO without milliseconds |
| Custom FAA format | `advisory-builder.js` | `DD/HHMMZ` |

**Issues:**
- Mix of `substr()` (deprecated) and `slice()`
- Inconsistent slice indices
- Multiple ways to append 'Z' suffix

#### PHP Patterns

| Pattern | Files | Example Output |
|---------|-------|----------------|
| `gmdate('Y-m-d H:i:s')` | `publish.php`, `cancel.php` | `2026-01-21 15:30:45` |
| `gmdate('Y-m-d\TH:i:s\Z')` | `active.php` | `2026-01-21T15:30:45Z` |
| `->format('d/Hi')` | `TMIDiscord.php` | `21/1530` |
| `->format('Hi')` | `TMIDiscord.php` | `1530` |
| `date('Y-m-d H:i:s')` | `publish.php` (line 41) | **BUG: Uses local time** |

**Critical Bug:** [publish.php:41](api/mgt/tmi/publish.php#L41) uses `date()` instead of `gmdate()`.

#### TMIDiscord.php Format Functions (Lines 1189-1201)

```php
formatLogTime()              → 'd/Hi'        → 21/1530
formatDateMMDDYYYY()         → 'm/d/Y'       → 01/21/2026
formatTimeHHMM()             → 'Hi'          → 1530
formatTimeDDHHMM()           → 'dHi'         → 211530
formatTimeDDHHMMZ()          → 'd/Hi' + 'Z'  → 21/1530Z
formatSignature()            → 'y/m/d H:i'   → 26/01/21 15:30
```

---

## API Response Formats

### Problem: 4 different response wrapper patterns

#### Pattern A: `success` boolean wrapper
**Files:** `tmi_config.php`, `events/list.php`, `active.php`, `demand/summary.php`

```json
{
  "success": true,
  "data": {...},
  "message": "..."
}
```

#### Pattern B: `status` with "ok"/"error"
**Files:** `reroutes/post.php`, `gdt/programs/*.php`

```json
{
  "status": "ok",
  "message": "...",
  "data": {...}
}
```

#### Pattern C: Direct data (no wrapper)
**Files:** `adl/flight.php`

```json
{ "callsign": "...", "origin": "...", ... }
```

#### Pattern D: Error key inconsistency

| Endpoint | Error Field |
|----------|-------------|
| `adl/flight.php` | `"error"` |
| `events/list.php` | `"error"` with `"success": false` |
| `reroutes/post.php` | `"message"` with `"status": "error"` |

### JSON Key Naming

| Convention | Files | Examples |
|------------|-------|----------|
| camelCase | `active.php` | `entityType`, `validFrom`, `createdAt` |
| snake_case | `demand/summary.php` | `time_range`, `top_origins` |
| Mixed | Multiple | Same response has both conventions |

### Pagination: Inconsistent or Absent

- `events/list.php`: Has `count`, `by_source` summary
- `demand/summary.php`: No pagination metadata
- `adl/flight.php`: Returns single object

### Authentication Patterns

| Pattern | Files | Code |
|---------|-------|------|
| Session + DEV override | `data/personnel.php` | Checks `$_SESSION['VATSIM_CID']` |
| 401 response | `reroutes/post.php` | Returns JSON error |
| No auth | `active.php` | CORS headers only |
| OPTIONS handling | Mixed | Returns 200 or 204 |

---

## Configuration Patterns

### Problem: Duplicate constants with conflicting values

#### Discord Configuration Duplication

**config.php (Production):**
```php
'guild_id' => '1039586513689780224'
'channels' => [
    'ntml' => '1350319537526014062',
    'advisories' => '1447715453425418251'
]
```

**config.example.php (Different values):**
```php
'guild_id' => '358294607974539265'  // DIFFERENT!
'channels' => [
    'ntml' => '358295136398082048',  // DIFFERENT!
    'advisories' => '358300240236773376'  // DIFFERENT!
]
```

#### Config Format Inconsistencies

| Format | Files | Pattern |
|--------|-------|---------|
| PHP `define()` | `config.php` | `define('SQL_HOST', '...')` |
| PHP arrays | `swim_config.php` | `$SWIM_RATE_LIMITS = [...]` |
| PHP class constants | `jatoc/config.php` | `const JATOC_TRIGGERS = [...]` |
| JSON files | `azure_perti_config.json` | Infrastructure metadata |

#### Magic Numbers Without Constants

| Location | Value | Should Be |
|----------|-------|-----------|
| `swim_config.php:37` | `30000` | `SWIM_RATE_LIMIT_SYSTEM` |
| `advisory-builder.js:1258` | `4 * 60 * 60 * 1000` | `DEFAULT_ADVISORY_DURATION_MS` |
| `advisory-builder.js:184` | `500` | `DEBOUNCE_DELAY_MS` |
| `connect.php:327` | `'5432'` | `DEFAULT_GIS_PORT` |

#### Database Connection Patterns

| Pattern | Files | Issue |
|---------|-------|-------|
| Eager loading | `connect.php:46-69` | Always creates connections |
| Lazy loading | `connect.php:109-155` | On-demand via getters |
| TODO comment | `connect.php:362` | "Migrate callers to use get_conn_*" |

---

## CSS Class Naming

### Problem: Mixed naming conventions

#### Multiple Prefix Systems

| Prefix | Origin | Examples |
|--------|--------|----------|
| `.perti-` | PERTI custom | `.perti-info-bar`, `.perti-clock-display` |
| `.cs-` | Unknown | `.cs-fancy-tabs`, `.cs-password-toggle` |
| `.advisory-` | Feature | `.advisory-type-card`, `.advisory-card` |
| `.tmi-` | Feature | `.tmi-section-title`, `.tmi-section-tabs` |
| (none) | Bootstrap | `.card`, `.btn`, `.badge` |

#### Color Class Conflicts

Same class, different values in different files:

| Class | perti_theme.css | theme.css |
|-------|-----------------|-----------|
| `.text-success` | `#43ac6a` | Bootstrap default |
| `.text-info` | `#239BCD` | Bootstrap default |
| `.btn-primary` | `#222d5c` | Bootstrap default |
| `.bg-dark` | `#242444` | Bootstrap default |

Requires `!important` flags to override, which is a code smell.

#### State Class Duplication

| State | Classes Used | Files |
|-------|--------------|-------|
| Active | `.active` | `tmi-publish.css`, `_utilities.scss` |
| Selected | `.selected` | `advisory-builder.php` |

Both represent the same concept.

#### Semantic Collision

- `.col-arr`, `.col-dep` in `review.php` are NOT Bootstrap grid columns
- Creates confusion with Bootstrap's `.col-*` responsive grid system

---

## JavaScript Patterns

### Problem: 8+ different coding patterns

#### jQuery vs Vanilla JS

| Operation | jQuery (Used) | Vanilla (Used) |
|-----------|---------------|----------------|
| Selector | `$('#id')` | `document.getElementById()` |
| Events | `.on('click', ...)` | `.addEventListener()` |
| AJAX | `$.ajax({...})` | `fetch(...).then()` |
| DOM | `.addClass()` | `.classList.add()` |

**Files mixing both:**
- `tmi-publish.js`: jQuery for events, but vanilla in some functions
- `weather_radar_integration.js`: Pure vanilla JS
- `route-maplibre.js`: $.ajax for data loading

#### AJAX Patterns

| Pattern | Example | Files |
|---------|---------|-------|
| `$.ajax()` callbacks | `success: function(){}` | `tmi-publish.js`, `tmi_compliance.js` |
| `fetch().then()` | `.then(r => r.json())` | `demand.js`, `weather_hazards.js` |
| `await $.ajax()` | `const r = await $.ajax()` | `tmi-publish.js:3416` |
| `await fetch()` | `const r = await fetch()` | `adl-service.js` |

#### Module Patterns

| Pattern | Files | Example |
|---------|-------|---------|
| IIFE + window | `tmi-publish.js` | `window.TMIPublisher = {...}` |
| IIFE + module.exports | `weather_radar.js` | Node.js compatible |
| Direct window | `demand.js` | `window.formatConfigName = function` |
| $(document).ready | `tmi-publish.js` | jQuery initialization |

#### Error Handling

| Pattern | Example | Issue |
|---------|---------|-------|
| `.catch(err => console.error())` | `weather_impact.js` | Good |
| `try/catch` | `weather_hazards.js` | Good |
| `error: function(){}` | `tmi-publish.js` | jQuery callback |
| `catch(e) {}` | `tmi-publish.js:5486` | **Silent suppression** |

---

## Database Schema

### Problem: Inconsistent column types and naming

#### Timestamp Column Naming

| Pattern | Files | Example |
|---------|-------|---------|
| `_utc` suffix | `core/001_adl_core_tables.sql` | `first_seen_utc`, `last_seen_utc` |
| `_at` suffix | `tmi/001_tmi_core_schema.sql` | `created_at`, `updated_at` |
| Mixed | `reroute/001_create_reroute_tables.sql` | `created_utc`, `updated_utc` |

#### Status Column Types

| Type | Files | Values |
|------|-------|--------|
| TINYINT | `reroute/001` | 0=draft, 1=proposed, 2=active... |
| NVARCHAR(16) | `tmi/001` | 'DRAFT', 'PROPOSED', 'ACTIVE' |
| BIT | `tmi/001` | 0/1 for boolean flags |

**Issue:** Cannot query status consistently across tables.

#### Primary Key Naming

| Pattern | Files | Example |
|---------|-------|---------|
| `id` | `swim/001` | Generic identifier |
| `{table}_id` | `tmi/001` | `entry_id`, `program_id` |
| `{table}_uid` | `core/001` | `flight_uid` (BIGINT) |

#### Character Type Inconsistencies

| Data | Type Used | Files |
|------|-----------|-------|
| Airport codes | CHAR(4) | `core/001` |
| Airport codes | NCHAR(4) | `reroute/001` |
| Callsigns | NVARCHAR(16) | `core/001` |
| Callsigns | NVARCHAR(12) | `tmi/001` |

#### DATETIME2 Precision

| Precision | Files | Use Case |
|-----------|-------|----------|
| (default=7) | `swim/001` | Full precision |
| (0) | `tmi/001` | Seconds only |
| (3) | `core/002` | Milliseconds |

---

## PHP Template Structure

### Include/Require Patterns

| Pattern | Files | Use Case |
|---------|-------|----------|
| `include` | `index.php`, `demand.php` | Main pages |
| `require_once()` | API files | Dependencies |
| `include_once()` | `header.php` | File-level guards |

**Issue:** No consistent pattern; only some files have include guards.

### Short Tags vs Full Echo

| Pattern | Files | Example |
|---------|-------|---------|
| `<?= ?>` | `index.php`, `header.php` | `<?= date('Y-m-d') ?>` |
| `<?php echo ?>` | API files | Rarely used |

### Script Loading

| Attribute | Files | Count |
|-----------|-------|-------|
| `async` | `index.php`, `airport_config.php` | 2 files |
| `defer` | `review.php` | 1 file |
| Neither | All others | 30+ files |

---

## Recommendations

### 1. Color Standardization

Create `assets/js/config/colors.js`:

```javascript
export const COLORS = {
    success: '#28a745',
    danger: '#dc3545',
    warning: '#fd7e14',
    info: '#17a2b8',
    primary: '#766df4',
    // ... etc
};
```

Update `perti_theme.css` to use CSS variables:

```css
:root {
    --color-success: #28a745;
    --color-danger: #dc3545;
    /* ... */
}

.text-success { color: var(--color-success); }
.btn-success { background-color: var(--color-success); }
```

### 2. Date/Time Utilities

Create `assets/js/utils/datetime.js`:

```javascript
export const DateTimeUtils = {
    toZulu: (date) => date.toISOString().slice(0, 19) + 'Z',
    toHHMMZ: (date) => date.toISOString().slice(11, 16) + 'Z',
    toYYYYMMDD: (date) => date.toISOString().slice(0, 10),
    // ... etc
};
```

Create `load/DateTimeHelper.php`:

```php
class DateTimeHelper {
    public static function toZulu(DateTime $dt): string {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
    // ... etc
}
```

### 3. API Response Standardization

Create `api/common/response.php`:

```php
function respond_json(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $code < 400 ? 'ok' : 'error',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'data' => $payload['data'] ?? null,
        'message' => $payload['message'] ?? null,
        'errors' => $payload['errors'] ?? null,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
```

### 4. Configuration Consolidation

1. Remove duplicate Discord IDs from `config.example.php`
2. Convert `$SWIM_*` arrays to `define()` constants
3. Create `load/FeatureFlags.php` for feature toggles
4. Document all config keys in a single reference file

### 5. CSS Standardization

1. Choose one prefix system (recommend `.perti-` for custom, no prefix for Bootstrap)
2. Remove `!important` flags by fixing specificity order
3. Consolidate card variants into one pattern
4. Document when to use `.active` vs `.selected`

### 6. JavaScript Modernization

Priority order:
1. Replace `substr()` with `slice()` (deprecated)
2. Consolidate on `fetch()` + `async/await` (remove $.ajax)
3. Standardize module pattern (ES6 modules or consistent IIFE)
4. Remove silent error suppression (`catch(e) {}`)

### 7. Database Schema Standards

For new tables:
- Timestamps: Use `*_utc` suffix with `DATETIME2(0)`
- Status: Use `NVARCHAR(16)` with string values
- Primary keys: Use `{table}_id` for INT, `{table}_uid` for BIGINT
- Booleans: Use `BIT` consistently
- Airport codes: Use `NCHAR(4)`

### 8. PHP Template Standards

1. Use `require_once` for all dependencies
2. Add include guards to all shared files
3. Use `<?= ?>` short tags consistently in templates
4. Add `async` or `defer` to all script tags

---

## Priority Matrix

| Priority | Category | Effort | Impact |
|----------|----------|--------|--------|
| P0 | Fix `date()` vs `gmdate()` bug | Low | High |
| P0 | Consolidate Discord config | Low | High |
| P1 | Standardize API responses | Medium | High |
| P1 | Create color config | Medium | Medium |
| P2 | Date/time utilities | Medium | Medium |
| P2 | CSS class cleanup | High | Medium |
| P3 | JS pattern modernization | High | Medium |
| P3 | Database schema alignment | High | Low |

---

## Fixes Applied (February 2026)

### P0 Fixes - Completed

#### 1. `date()` vs `gmdate()` Bug - FIXED

Changed all database timestamp operations from `date()` to `gmdate()` to ensure UTC consistency:

| File | Lines Fixed |
|------|-------------|
| `api/mgt/tmi/publish.php` | Line 41 |
| `api/tmi/reroutes.php` | Lines 373, 416, 483, 535 |
| `api/tmi/public-routes.php` | Lines 300, 379 |
| `api/tmi/programs.php` | Lines 277, 288, 292, 391, 392 |
| `api/tmi/entries.php` | Lines 282, 348, 350 |
| `api/tmi/advisories.php` | Lines 274, 284, 287, 357, 359 |

#### 2. Missing `tmi_reroute_routes` Migration - FIXED

Created `database/migrations/tmi/024_create_reroute_routes.sql`:

- Migration existed in `adl/migrations/tmi/011_reroute_routes_table.sql` but was not in canonical location
- New migration includes both original columns AND filter columns from migration 026
- Ensures table exists before 026 tries to add columns to it

### New Issues Discovered

#### SQL Injection Vulnerabilities - HIGH PRIORITY

Legacy files using direct string interpolation instead of prepared statements:

| File | Line | Issue |
|------|------|-------|
| `api/data/routes.php` | 35 | `%$search%` in LIKE clause |
| `api/data/reroutes.php` | 35 | `%$search%` in LIKE clause |
| `api/data/configs.php` | 184 | Unescaped search parameter |
| `api/data/personnel.php` | 21 | Direct CID in query |

**Recommendation:** Migrate these to prepared statements.

#### CORS Inconsistency - MEDIUM PRIORITY

Mixed CORS strategies across endpoints:

| Pattern | Files |
|---------|-------|
| `Access-Control-Allow-Origin: *` | weather/refresh.php, data/sua.php, data/tfr.php |
| Whitelisted origins | api/tmi/helpers.php |

**Recommendation:** Use TmiResponse CORS helper for all endpoints.

#### Remaining `date()` Usage - LOW PRIORITY

Console/log output scripts still use `date()` for local timestamp display (acceptable for logging):

- `cron/process_tmi_proposals.php` - Console output
- `integrations/*/cron_sync.php` - Cron log output

---

*Generated: February 2026*
*Analysis performed by Claude Code*
*Updated: February 2026 with fixes applied*
