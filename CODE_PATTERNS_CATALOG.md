# PERTI Code Patterns Catalog

**Generated:** February 2026
**Last Updated:** February 1, 2026
**Purpose:** Comprehensive inventory of all code patterns, configurations, and inconsistencies across the PERTI codebase

---

## Table of Contents

1. [Color Definitions](#1-color-definitions)
2. [Date/Time Formats](#2-datetime-formats)
3. [API Response Formats](#3-api-response-formats)
4. [Configuration Patterns](#4-configuration-patterns)
5. [CSS Classes/Naming/Usage](#5-css-classesnamingusage)
6. [JavaScript Patterns](#6-javascript-patterns)
7. [Database Schemas](#7-database-schemas)
8. [PHP Templates](#8-php-templates)
9. [SQL Vulnerabilities](#9-sql-vulnerabilities)
10. [CORS Inconsistencies](#10-cors-inconsistencies)
11. [Magic Numbers](#11-magic-numbers)
12. [Hardcoded References](#12-hardcoded-references)
13. [Error Handling Issues](#13-error-handling-issues)
14. [Security Issues](#14-security-issues)
15. [Code Quality Issues](#15-code-quality-issues)

---

## 1. Color Definitions

### 1.1 Summary Statistics

| Category | Count | Location |
|----------|-------|----------|
| **Total hardcoded colors** | 500+ | 30 JS files |
| **Centralized config files** | 3 | filter-colors.js, phase-colors.js, rate-colors.js |
| **Bootstrap overrides** | 4 | perti_theme.css |
| **Inline hex in JS** | 400+ | Scattered across assets/js/ |

### 1.2 JavaScript Config Files

#### `/assets/js/config/filter-colors.js` (Lines 7-402)

**Weight Class Colors:**
| Key | Hex | Semantic |
|-----|-----|----------|
| `'J'` (Super) | `#ffc107` | Amber |
| `'H'` (Heavy) | `#dc3545` | Red |
| `'L'` (Large) | `#28a745` | Green |
| `'S'` (Small) | `#17a2b8` | Cyan |
| `'UNKNOWN'` | `#6c757d` | Gray |

**Flight Rule Colors:**
| Key | Hex | Semantic |
|-----|-----|----------|
| `'I'` (IFR) | `#007bff` | Blue |
| `'V'` (VFR) | `#28a745` | Green |

**Carrier Colors (65+ airlines):**
- AAL (American): `#0078d2`
- UAL (United): `#0033a0`
- DAL (Delta): `#e01933`
- SWA (Southwest): `#f9b612`
- FDX (FedEx): `#ff6600`
- UPS: `#351c15`
- *(Full list in filter-colors.js)*

**DCC Region Colors:**
| Region | Hex | ARTCCs |
|--------|-----|--------|
| WEST | `#dc3545` | ZAK, ZAN, ZHN, ZLA, ZLC, ZOA, ZSE |
| SOUTH_CENTRAL | `#fd7e14` | ZAB, ZFW, ZHO, ZHU, ZME |
| MIDWEST | `#28a745` | ZAU, ZDV, ZKC, ZMP |
| SOUTHEAST | `#ffc107` | ZID, ZJX, ZMA, ZMO, ZTL |
| NORTHEAST | `#007bff` | ZBW, ZDC, ZNY, ZOB, ZWY |
| CANADA_EAST | `#9b59b6` | CZYZ, CZUL, etc. |
| CANADA_WEST | `#ff69b4` | CZWG, CZEG, CZVR |

#### `/assets/js/config/phase-colors.js` (Lines 17-48)

**Flight Phase Colors:**
| Phase | Hex | Description |
|-------|-----|-------------|
| `arrived` | `#1a1a1a` | Black - Landed |
| `disconnected` | `#f97316` | Orange - Mid-flight disconnect |
| `descending` | `#991b1b` | Dark Red - On approach |
| `enroute` | `#dc2626` | Red - Cruising |
| `departed` | `#f87171` | Light Red - Just took off |
| `taxiing` | `#22c55e` | Green - Ground movement |
| `prefile` | `#3b82f6` | Blue - Filed, not connected |
| `actual_gs` | `#eab308` | Yellow - GS EDCT issued |
| `simulated_gs` | `#fef08a` | Light Yellow - GS simulated |
| `proposed_gs` | `#ca8a04` | Gold - GS proposed |
| `actual_gdp` | `#92400e` | Brown - GDP EDCT issued |
| `simulated_gdp` | `#d4a574` | Tan - GDP simulated |
| `proposed_gdp` | `#78350f` | Dark Brown - GDP proposed |
| `exempt` | `#6b7280` | Gray - Exempt from TMI |
| `unknown` | `#9333ea` | Purple - Unknown phase |

#### `/assets/js/config/rate-colors.js` (Lines 22-110)

**Rate Display Colors:**
| Type | Hex | Usage |
|------|-----|-------|
| VATSIM Active | `#000000` | Strategic rates (black) |
| Real World Active | `#00FFFF` | RW rates (cyan) |
| VATSIM Suggested | `#6b7280` | Inferred rates (gray) |
| Real World Suggested | `#0d9488` | RW inferred (teal) |

**Weather Category Colors:**
| Category | Hex | Description |
|----------|-----|-------------|
| VMC | `#22c55e` | Green - Visual conditions |
| LVMC | `#eab308` | Yellow - Low VMC |
| IMC | `#f97316` | Orange - Instrument conditions |
| LIMC | `#ef4444` | Red - Low IMC |
| VLIMC | `#dc2626` | Dark Red - Very Low IMC |

### 1.3 Files with Extensive Hardcoded Colors

| File | Approx Count | Key Colors |
|------|--------------|------------|
| `nod.js` | 50+ | `#ffffff`, `#000000`, `#28a745`, `#6f42c1`, `#4a9eff` |
| `demand.js` | 40+ | `#2d2d44`, `#4dabf7`, `#228be6`, `#51cf66` |
| `tmi_compliance.js` | 35+ | `#007bff`, `#dc3545`, `#17a2b8`, `#6c757d` |
| `splits.js` | 30+ | 12-color palette, MapLibre layer colors |
| `gdt.js` | 25+ | Airport palette (10 vibrant colors) |
| `weather_radar.js` | 20+ | dBZ scale (15 colors) |
| `route-maplibre.js` | 20+ | SUA/Airspace type colors |
| `statsim_rates.js` | 20+ | Chart styling colors |
| `tmi-publish.js` | 15+ | SweetAlert2 button colors |
| `tmi-active-display.js` | 15+ | Region and status colors |

### 1.4 CSS Theme Colors

#### `/assets/css/perti_theme.css` (Lines 230-339)

**Semantic Text Colors (Bootstrap Overrides):**
| Class | Custom Hex | Bootstrap Hex | Status |
|-------|------------|---------------|--------|
| `.text-success` | `#43ac6a` | `#28a745` | **INCONSISTENT** |
| `.text-danger` | `#F04124` | `#dc3545` | **INCONSISTENT** |
| `.text-warning` | `#E99002` | `#fd7e14` | **INCONSISTENT** |
| `.text-info` | `#239BCD` | `#17a2b8` | **INCONSISTENT** |
| `.text-primary` | `#332e7a` | `#007bff` | Custom purple |

**Badge Colors:**
| Class | Hex |
|-------|-----|
| `.badge-success` | `#63BD49` |
| `.badge-info` | `#239BCD` |
| `.badge-primary` | `#332e7a` |
| `.badge-dark` | `#1c1946` |

### 1.5 Weather Radar Colors

#### `/assets/js/weather_radar.js` (Lines 128-173)

**Radar Reflectivity (dBZ) Scale:**
| dBZ | Hex | Weather Type |
|-----|-----|--------------|
| 5 | `#04e9e7` | Very Light |
| 20 | `#02fd02` | Light |
| 30 | `#008e00` | Moderate |
| 40 | `#e5bc00` | Heavy |
| 50 | `#fd0000` | Extreme |
| 65 | `#f800fd` | Hail |

---

## 2. Date/Time Formats

### 2.1 Summary Statistics

| Issue | Count | Severity |
|-------|-------|----------|
| **Deprecated `.substr()` usage** | 37 | P2 |
| **PHP `date()` instead of `gmdate()`** | 13 files | P1 |
| **`strtotime()` without UTC context** | 14+ | P2 |
| **Mixed timestamp formats** | Multiple | P3 |

### 2.2 JavaScript Patterns

#### ISO String Patterns

| Pattern | File | Line | Output Format |
|---------|------|------|---------------|
| `.toISOString()` | multiple | - | `2026-01-21T15:30:45.000Z` |
| `.toISOString().slice(0, 16)` | tmi-publish.js | 1463, 1516 | `2026-01-21T15:30` |
| `.toISOString().slice(11, 16) + 'Z'` | weather_radar.js | 247 | `15:30Z` |
| `.toISOString().substr(11, 8)` | nod.js | 265 | `15:30:45` |
| `.toISOString().substr(11, 5) + 'Z'` | adl-service.js | 265 | `15:30Z` |

#### **DEPRECATED:** Files using `.substr()` instead of `.slice()` (37 instances)

| File | Lines |
|------|-------|
| `nod.js` | 265, 3612, 5668, 5678, + more |
| `tmi-publish.js` | 294, 5183, + more |
| `adl-service.js` | 265 |
| `gdt.js` | Multiple instances |
| `demand.js` | Multiple instances |
| `splits.js` | Multiple instances |

#### Custom Format Functions

| Function | File | Output |
|----------|------|--------|
| `formatSignatureTime(date)` | advisory-templates.js:115 | `YY/MM/DD HH:MM` |
| `formatProgramTime(date)` | advisory-templates.js:94 | `DD/HHMMZ` |
| `formatADLTime(date)` | advisory-templates.js:105 | `HHMMZ` |
| `formatTimeDDHHMM(date)` | advisory-templates.js:83 | `DDHHMM` |
| `formatDateMMDDYYYY(date)` | advisory-templates.js:72 | `MM/DD/YYYY` |

### 2.3 PHP Patterns

#### `date()` vs `gmdate()` Issues (13 files)

| File | Lines | Issue |
|------|-------|-------|
| `api/tmi/gdp_apply.php` | 94 | Uses `date()` instead of `gmdate()` |
| `api/tmi/gdp_simulate.php` | 257 | Uses `date()` instead of `gmdate()` |
| `api/tmi/helpers.php` | 432, 464 | Uses `date()` instead of `gmdate()` |
| `api/tmi/gs/activate.php` | 193, 194 | Uses `date()` instead of `gmdate()` |
| `api/tmi/gs/common.php` | 266 | Uses `date()` instead of `gmdate()` |
| `cron/process_tmi_proposals.php` | Multiple | Mixed usage |
| `api/weather/refresh.php` | Multiple | Uses `date()` for logging |
| + 6 more files | - | Various date() calls |

#### `strtotime()` Without UTC Context (14+ locations)

Files using `strtotime()` without explicit timezone:
- `api/tmi/helpers.php`
- `api/tmi/programs.php`
- `api/mgt/tmi/publish.php`
- `api/gdt/programs/publish.php`
- Multiple cron scripts

---

## 3. API Response Formats

### 3.1 Summary Statistics

| Pattern | Files Using | Consistency |
|---------|-------------|-------------|
| TmiResponse class | 8 files | Good |
| SwimResponse class | 3 files | Good |
| GDT respond_json | 3 files | Medium |
| Raw JSON (no wrapper) | 20+ files | **INCONSISTENT** |
| Mixed success/status keys | 15+ files | **INCONSISTENT** |

### 3.2 Pattern A: TmiResponse Class

**Used in:** `/api/tmi/`, `/api/mgt/tmi/`

**Success Response:**
```json
{
  "success": true,
  "data": { ... },
  "timestamp": "2026-02-01T12:34:56+00:00",
  "meta": { ... }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": true,
  "message": "Error description",
  "status": 400,
  "code": "ERROR_CODE"
}
```

### 3.3 Pattern B: SwimResponse Class

**Used in:** `/api/swim/v1/`

Same structure as TmiResponse but with:
- `X-SWIM-Cache: HIT|MISS` header
- `ETag` support
- `Content-Encoding: gzip` support

### 3.4 Pattern C: GDT respond_json

**Used in:** `/api/gdt/`

```json
{
  "status": "ok" | "error",
  "message": "Description",
  "data": { ... }
}
```

### 3.5 Pattern D: Raw JSON (No Wrapper) - **INCONSISTENT**

| File | Structure |
|------|-----------|
| `api/adl/current.php` | `{snapshot_utc, flights}` |
| `api/adl/flight.php` | `{error}` or raw flight object |
| `api/data/sua.php` | GeoJSON FeatureCollection |
| `api/demand/summary.php` | `{success, timestamp, time_range, data}` |
| `api/stats/*.php` | Various structures |

### 3.6 Inconsistent Response Keys

| Key Pattern | Files Using | Notes |
|-------------|-------------|-------|
| `success: true/false` | TmiResponse, SwimResponse | Preferred |
| `status: 'ok'/'error'` | GDT files | Alternative |
| `error: true` + message | Some endpoints | Inconsistent |
| `ok: true/false` | 2 files | Rare |

---

## 4. Configuration Patterns

### 4.1 Summary Statistics

| Issue | Count | Severity |
|-------|-------|----------|
| **Hardcoded passwords in code** | 2 files | **P0 CRITICAL** |
| **Duplicate config definitions** | 5+ files | P2 |
| **Mixed config access patterns** | 3 patterns | P3 |
| **Inconsistent env var names** | 4 schemes | P3 |

### 4.2 PHP Configuration Methods

#### Pattern 1: `define()` with `env()` helper (Preferred)

**File:** `/load/config.php`
```php
define("SQL_HOST", env("SQL_HOST", "default"));
```

#### Pattern 2: Direct `getenv()` calls

**Files:** Various API files
```php
$apiKey = getenv('SIMTRAFFIC_API_KEY');
```

#### Pattern 3: Laravel-style `env()` function

**File:** `/integrations/virtual-airlines/phpvms7/Config/config.php`
```php
'api_key' => env('VATSWIM_API_KEY', ''),
```

### 4.3 Environment Variable Naming Inconsistencies

**Database credentials use 4 different naming schemes:**

| Scheme | Files Using | Variables |
|--------|-------------|-----------|
| Scheme 1 | config.php | `SQL_USERNAME`, `SQL_PASSWORD`, `SQL_HOST`, `SQL_DATABASE` |
| Scheme 2 | adl/php/import_wind_data.php | `DB_SERVER`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| Scheme 3 | adl/php/import_weather_alerts.php | `SQL_USER`, `SQL_PASS` |
| Scheme 4 | api/swim/v1/health.php | `SWIM_DB_SERVER`, `SWIM_DB_NAME`, `SWIM_DB_USER`, `SWIM_DB_PASS` |

### 4.4 Duplicate Configuration Definitions

| Config Value | Files Containing |
|--------------|------------------|
| `CRON_KEY` default | `cron/process_tmi_proposals.php:23`, `api/scheduler.php:277` |
| `VATSWIM_BASE_URL` | 5 integration files |
| Database connection code | 4 files (should use connect.php) |

### 4.5 **CRITICAL:** Hardcoded Passwords in Code

| File | Line | Issue |
|------|------|-------|
| `scripts/migrate_division_events.php` | 26 | `'pass' => getenv('DB_PASS') ?: '<PASSWORD>'` |
| `api/stats/config_stats.php` | 41 | `define('STATS_SQL_PASSWORD', env_stats('STATS_SQL_PASSWORD', '<PASSWORD>'))` |

**Recommendation:** Remove hardcoded passwords immediately, require environment variables.

---

## 5. CSS Classes/Naming/Usage

### 5.1 Summary Statistics

| Category | Count | Notes |
|----------|-------|-------|
| **Custom prefixes** | 6 | .perti-, .dcccp-, .ntml-, .tmi-, .advisory-, .cs- |
| **Inline styles in PHP** | 985 instances | Should use CSS classes |
| **Bootstrap overrides** | 4 classes | Inconsistent with Bootstrap |

### 5.2 Custom Prefix Patterns

#### `.perti-` Prefix (Info Bar)

**File:** `/assets/css/info-bar.css`

| Class | Lines | Usage |
|-------|-------|-------|
| `.perti-info-bar` | 28-43 | Main container |
| `.perti-info-card` | 103-118 | Card wrapper |
| `.perti-card-utc` | 120-136 | UTC clock card |
| `.perti-card-global` | 138-155 | Global/airport card |
| `.perti-stat-value` | 220-235 | Monospace stat display |
| `.perti-clock-display-*` | 55-75 | Clock sizes (lg/md/sm) |

#### `.dcccp-` Prefix (Initiative Timeline)

**File:** `/assets/css/initiative_timeline.css`

| Class | Purpose |
|-------|---------|
| `.dcccp-timeline-wrapper` | Main timeline container |
| `.dcccp-item` | Individual initiative item |
| `.dcccp-item.level-*` | Level variants (CDW, Possible, etc.) |
| `.dcccp-now-line` | Current time indicator |

#### `.ntml-`, `.tmi-`, `.advisory-` Prefixes

**File:** `/assets/css/tmi-publish.css`

**Pages using:** `/tmi-publish.php`

### 5.3 Inline Styles in PHP (985 instances)

Files with most inline styles:
- `demand.php` - Heavy use of inline color/spacing
- `gdt.php` - Inline table styling
- `nod.php` - Marker styling
- `tmi-publish.php` - Modal styling

---

## 6. JavaScript Patterns

### 6.1 Summary Statistics

| Issue | Count | Severity |
|-------|-------|----------|
| **`var` declarations** | 97 | P2 |
| **`document.write()` usage** | 24 | P2 |
| **jQuery instead of fetch** | 12 files | P3 |
| **Silent catch blocks** | 24 | P1 |
| **Console.log in production** | 180+ | P3 |

### 6.2 Module Patterns

| Pattern | Files Using | Percentage |
|---------|-------------|------------|
| IIFE with namespace | 17 files | 39% |
| Global objects | 20 files | 45% |
| ES6 class | 1 file (initiative_timeline.js) | 2% |
| ES6 modules | 1 file (route-maplibre.js) | 2% |

### 6.3 Variable Declaration Issues (97 `var` declarations)

| Should Be | Count | Examples |
|-----------|-------|----------|
| `const` | 82 | Loop invariants, config objects |
| `let` | 15 | Loop counters, reassigned values |

Files with most `var` usage:
- `gdt.js`
- `demand.js`
- `splits.js`
- `nod.js`

### 6.4 `document.write()` Usage (24 instances)

**File:** `gdt.js` (print functionality)

All instances are in print-related functions. Consider replacing with DOM manipulation or print stylesheets.

### 6.5 Console Statements in Production (180+ instances)

| File | Count | Assessment |
|------|-------|------------|
| `demand.js` | 35+ | **Extensive debug logging** |
| `adl-service.js` | 14+ | Debug logging |
| `adl-refresh-utils.js` | 11+ | Debug logging |
| Utility scripts | 50+ | Appropriate for CLI |
| Discord bot | 25+ | Appropriate for server |
| Simulator engine | 20+ | Appropriate for server |

**Recommendation:** Implement conditional logging based on DEBUG environment variable for frontend files.

### 6.6 AJAX Patterns

| Pattern | Files | Recommendation |
|---------|-------|----------------|
| `fetch()` + async/await | 21 files | **Preferred** |
| `$.ajax()` / `$.get()` | 12 files | Migrate to fetch |
| jQuery Deferred (`.done()`) | 9 files | Migrate to async/await |

**Files needing migration:**
- `plan.js` - Heavy jQuery usage
- `demand.js` - Uses `$.getJSON()`
- `review.js`
- `sheet.js`

---

## 7. Database Schemas

### 7.1 Summary Statistics

| Issue | Count | Notes |
|-------|-------|-------|
| **SELECT * usage** | 17+ | Should use explicit columns |
| **Mixed timestamp conventions** | 2 patterns | `_utc` vs `_at` suffix |
| **Inconsistent status types** | 3 types | NVARCHAR, TINYINT, BIT |
| **Mixed JOIN syntax** | Common | `JOIN` vs `INNER JOIN` |
| **Mixed date functions** | 2 | `GETUTCDATE()` vs `SYSUTCDATETIME()` |

### 7.2 Timestamp Naming Conventions

| Convention | Tables Using | Example |
|------------|--------------|---------|
| `*_utc` suffix | ADL core, some TMI | `first_seen_utc`, `last_seen_utc` |
| `*_at` suffix | Most TMI tables | `created_at`, `updated_at` |
| Semantic names | Advisories | `effective_from`, `effective_until` |

**INCONSISTENCY:** Mixed `_utc` and `_at` in same tables (e.g., `tmi_programs`)

### 7.3 Status Column Types

| Type | Tables | Example Values |
|------|--------|----------------|
| `NVARCHAR(16)` | tmi_entries, tmi_advisories | 'DRAFT', 'ACTIVE', 'EXPIRED' |
| `TINYINT` | tmi_reroutes, tmi_public_routes | 0, 1, 2, 3, 4, 5 |
| `BIT` | Multiple | is_active, is_proposed |

**CRITICAL INCONSISTENCY:** Same concept (status) uses different types

### 7.4 SELECT * Usage (17+ instances)

Files using `SELECT *`:
- `api/data/configs.php`
- `api/data/routes.php`
- `api/user/*.php`
- `api/mgt/*.php`

**Recommendation:** Use explicit column lists for security and performance.

---

## 8. PHP Templates

### 8.1 Summary Statistics

| Issue | Count | Notes |
|-------|-------|-------|
| **Mixed include patterns** | 3 types | include, include_once, require_once |
| **Files without guards** | Multiple | Risk of double-include |
| **Duplicate connection code** | 4 files | Should use connect.php |

### 8.2 Include Patterns

| Pattern | Files Using | Recommended? |
|---------|-------------|--------------|
| `include()` | index.php, advisory-builder.php | No - use require_once |
| `include_once()` | header.php, nav.php, connect.php | Yes |
| `require_once()` | splits.php, api files | Yes |

**Guard Pattern (Recommended):**
```php
if (defined('NAV_PHP_LOADED')) { return; }
define('NAV_PHP_LOADED', true);
```

**Files with guards:**
- `load/nav.php` (Lines 3-7)
- `load/connect.php` (Lines 8-11)
- `load/input.php` (Lines 10-13)
- `sessions/handler.php` (Lines 20-23)

### 8.3 Duplicate Database Connection Functions

| File | Function | Issue |
|------|----------|-------|
| `api/adl/demand/summary.php` | `get_adl_conn()` | Duplicates connect.php |
| `api/adl/demand/batch.php` | `get_adl_conn()` | Duplicates connect.php |
| `api/stats/config_stats.php` | Custom connection | Duplicates connect.php |
| `scripts/migrate_division_events.php` | Inline connection | Duplicates connect.php |

---

## 9. SQL Vulnerabilities

### 9.1 Summary Statistics

| Severity | Count | Description |
|----------|-------|-------------|
| **HIGH (SQL Injection)** | 100+ files | Direct variable interpolation |
| **MEDIUM (Session Variable)** | 10+ files | Session values in queries |
| **MEDIUM (LIKE Injection)** | 5+ files | Unsanitized LIKE patterns |

### 9.2 Critical Vulnerabilities (HIGH Severity)

#### Direct String Interpolation in UPDATE

| File | Line | Vulnerable Code |
|------|------|-----------------|
| `api/user/term_staffing/update.php` | 23 | `WHERE id=$id` |
| `api/user/enroute_staffing/update.php` | 23 | `WHERE id=$id` |
| `api/user/dcc/update.php` | 21 | `WHERE id=$id` |
| `api/user/configs/update.php` | 23 | `WHERE id=$id` |
| `api/mgt/terminal_staffing/update.php` | 52 | `WHERE id=$id` |
| `api/mgt/perti/update.php` | 52 | Multiple columns |
| `api/mgt/configs/update.php` | 54 | Multiple columns |
| `api/mgt/dcc/update.php` | 51 | Multiple columns |

#### LIKE Clause Injection

| File | Line | Vulnerable Code |
|------|------|-----------------|
| `api/data/configs.php` | 184 | `LIKE '%$search%'` |
| `api/data/routes.php` | 35 | `LIKE '%$search%'` |
| `api/data/reroutes.php` | 35 | `LIKE '%$search%'` |

### 9.3 Session Variable Injection (MEDIUM Severity)

| File | Line | Vulnerable Code |
|------|------|-----------------|
| `airspace-elements.php` | 22 | `WHERE cid='$cid'` |
| `airport_config.php` | 22 | `WHERE cid='$cid'` |
| `tmi-publish.php` | 50 | `WHERE cid='$userCid'` |
| `swim-keys.php` | 33 | `WHERE cid='$cid'` |

### 9.4 Remediation Pattern

```php
// BEFORE (VULNERABLE):
$query = $conn->query("SELECT * FROM table WHERE id=$id");

// AFTER (SECURE):
$stmt = $conn->prepare("SELECT * FROM table WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
```

---

## 10. CORS Inconsistencies

### 10.1 Summary Statistics

| Category | Count | Risk |
|----------|-------|------|
| **Wildcard CORS (`*`)** | 77 files | Variable |
| **Wildcard on write ops** | 15+ files | **HIGH** |
| **Dynamic origin whitelist** | 2 files | Secure |
| **No CORS headers** | 50+ files | Internal only |

### 10.2 CORS Patterns Found

#### Pattern 1: Simple Wildcard (77 files)

```php
header('Access-Control-Allow-Origin: *');
```

Used by most public-facing API endpoints.

#### Pattern 2: Wildcard with Methods (36 files)

```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
```

#### Pattern 3: Dynamic Origin with Whitelist (2 files)

**File:** `/api/tmi/helpers.php` (Lines 115-134)

```php
$allowed_origins = [
    'https://perti.vatcscc.org',
    'https://vatcscc.azurewebsites.net',
    'http://localhost',
    'http://localhost:8080'
];
```

#### Pattern 4: **CRITICAL** Wildcard Fallback

**File:** `/api/swim/v1/auth.php` (Lines 467, 939)

```php
} else {
    header("Access-Control-Allow-Origin: *");  // INSECURE FALLBACK
}
```

### 10.3 High-Risk Wildcard Endpoints

| File | Methods | Risk |
|------|---------|------|
| `api/adl/demand/monitors.php` | GET, POST, DELETE | **HIGH** |
| `api/mgt/tmi/reroutes/update_geojson.php` | POST | **MEDIUM** |
| `api/gis/boundaries.php` | GET, POST | **MEDIUM** |
| `api/mgt/tmi/cancel.php` | POST | **MEDIUM** |
| `api/mgt/tmi/publish.php` | POST | **MEDIUM** |

---

## 11. Magic Numbers

### 11.1 JavaScript Magic Numbers

#### Timeouts & Intervals

| File | Line | Value | Suggested Constant |
|------|------|-------|-------------------|
| reroute.js | 20 | `15000` | `REROUTE_REFRESH_RATE_MS` |
| reroute.js | 68 | `1000` | `CLOCK_TICK_RATE_MS` |
| schedule.js | - | `3000` | `TOAST_TIMEOUT_MS` |
| weather_radar.js | 48 | `300000` | `RADAR_REFRESH_INTERVAL_MS` |
| initiative_timeline.js | 946 | `60000` | `NOW_LINE_UPDATE_MS` |

#### Map Configuration

| File | Line | Value | Suggested Constant |
|------|------|-------|-------------------|
| reroute.js | 72 | `39.5` | `MAP_DEFAULT_LAT` |
| reroute.js | 72 | `-98.35` | `MAP_DEFAULT_LON` |
| reroute.js | 72 | `4` | `MAP_DEFAULT_ZOOM` |
| reroute.js | 74 | `12` | `MAP_MAX_ZOOM` |

#### Visual Properties

| File | Line | Value | Suggested Constant |
|------|------|-------|-------------------|
| reroute.js | 531 | `8` | `PROTECTED_FIX_RADIUS` |
| reroute.js | 560 | `6` | `FLIGHT_MARKER_RADIUS` |
| weather_radar.js | 43 | `0.7` | `RADAR_DEFAULT_OPACITY` |

### 11.2 PHP Magic Numbers

| File | Line | Value | Suggested Constant |
|------|------|-------|-------------------|
| connect.php | 327 | `'5432'` | `POSTGRES_DEFAULT_PORT` |
| parse_aixm_sua.php | 76 | `6371` | `EARTH_RADIUS_KM` |
| parse_aixm_sua.php | 49 | `64` | `CIRCLE_POLYGON_POINTS` |
| rate_history.php | 23 | `30` | `RATE_HISTORY_DEFAULT_DAYS` |

### 11.3 Cache TTL Inconsistencies

| Location | TTL | Usage |
|----------|-----|-------|
| NOD demand | 2 min | Demand data |
| TMI compliance | 10 min | Compliance checks |
| Weather radar | 5 min | Radar tiles |
| ADL refresh | 60 sec | Flight positions |
| SWIM cache | 300 sec | API responses |
| Rate history | 60 sec | Rate data |

---

## 12. Hardcoded References

### 12.1 Discord IDs

| Type | Value | File | Line |
|------|-------|------|------|
| Guild ID | `358294607974539265` | config.example.php | 120 |
| NTML Channel | `358295136398082048` | config.example.php | 121 |
| Advisories Channel | `358300240236773376` | config.example.php | 122 |
| Role Mention | `<@&1268395359714021396>` | plan.js | 3598 |

### 12.2 API URLs

| URL | File | Purpose |
|-----|------|---------|
| `https://data.vatsim.net/v3/vatsim-data.json` | api/adl/ingest.php | VATSIM data feed |
| `https://aviationweather.gov/api/data/airsigmet` | api/weather/refresh.php | Weather alerts |
| `https://api.simtraffic.net/v1/flight/` | api/tmi/simtraffic_flight.php | SimTraffic API |
| `https://tfr.faa.gov` | api/data/tfr.php | TFR data |
| `https://mesonet.agron.iastate.edu` | api/data/weather.php | Weather tiles |
| `https://perti.vatcscc.org/api` | api/analysis/tmi_compliance.php | Self-reference |

### 12.3 ARTCC/Facility Lists

**File:** `/assets/js/tmi-publish.js` (Lines 109-143)

**US ARTCCs (22):**
```javascript
ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX, ZKC, ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZTL
```

**Cross-Border Facilities:**
```javascript
['ZBW', 'ZMP', 'ZSE', 'ZLC', 'ZOB', 'CZYZ', 'CZWG', 'CZVR', 'CZEG']
```

### 12.4 NTML Qualifiers

**File:** `/assets/js/tmi-publish.js` (Lines 146-196)

```javascript
NTML_QUALIFIERS = {
    spacing: ['AS ONE', 'PER STREAM', 'PER AIRPORT', 'PER FIX', 'EACH'],
    aircraft: ['JET', 'PROP', 'TURBOJET', 'B757'],
    weight: ['HEAVY', 'LARGE', 'SMALL', 'SUPER'],
    equipment: ['RNAV', 'NON-RNAV', 'RNP', 'RVSM', 'NON-RVSM'],
    flow: ['ARR', 'DEP', 'OVFLT'],
    operator: ['AIR CARRIER', 'AIR TAXI', 'GA', 'CARGO', 'MIL'],
    altitude: ['HIGH ALT', 'LOW ALT']
}
```

---

## 13. Error Handling Issues

### 13.1 Silent Catch Blocks (24 instances)

| File | Lines | Context |
|------|-------|---------|
| `gdt.js` | 2579, 2714, 4898, 6748, 6865, 7025, 7038, 7116 | Date parsing, cache updates |
| `route-maplibre.js` | 2328, 7106, 7113, 7218, 7226, 8362 | Turf.js, GeoJSON parsing |
| `sua.js` | 1156, 1203, 1251, 1304 | JSON response parsing |
| `tmi-publish.js` | 5486, 5504, 5570 | localStorage profile parsing |
| `reroute.js` | 94, 109 | Optional data loading |

### 13.2 `http_response_code()` with String Arguments (8 instances)

| File | Line | Code |
|------|------|------|
| `api/user/term_staffing/update.php` | Multiple | `http_response_code('500')` |
| `api/user/enroute_staffing/update.php` | Multiple | `http_response_code('500')` |
| `api/user/dcc/update.php` | Multiple | `http_response_code('500')` |
| `api/user/configs/update.php` | Multiple | `http_response_code('500')` |

**Issue:** Should use integer `500` not string `'500'`.

### 13.3 Missing Error Logging

Many catch blocks lack proper error logging:
- No consistent logging destination
- No error prefixes for grep/search
- Mixed console.error, console.warn, and silent suppression

---

## 14. Security Issues

### 14.1 Priority Summary

| Priority | Issue | Count | Status |
|----------|-------|-------|--------|
| **P0** | SQL Injection | 100+ files | Needs fix |
| **P0** | Hardcoded passwords | 2 files | **CRITICAL** |
| **P0** | CORS wildcard fallback | 2 locations | Needs fix |
| **P1** | DEV mode auth bypass | 1 pattern | Review |
| **P1** | Session key inconsistency | 2 keys | Review |

### 14.2 Authentication Patterns

#### DEV Mode Override

```php
if (!defined('DEV')) {
    // Check session CID against users table
} else {
    $perm = true;  // All auth bypassed in DEV
}
```

#### Inconsistent Session Keys

| Key | Files Using |
|-----|-------------|
| `$_SESSION['VATSIM_CID']` | Most files |
| `$_SESSION['cid']` | Some files |

---

## 15. Code Quality Issues

### 15.1 Function Naming Inconsistencies

| Pattern | Examples | Recommendation |
|---------|----------|----------------|
| `get*` | getFlights, getData | For synchronous returns |
| `fetch*` | fetchFlights, fetchData | For async API calls |
| `load*` | loadConfig, loadData | For file/resource loading |

**Inconsistent usage found in 15+ locations.**

### 15.2 Boolean Naming

| Found | Should Be |
|-------|-----------|
| `active` | `isActive` |
| `proposed` | `isProposed` |
| `has_data` | `hasData` |

### 15.3 Commented-Out Code

| File | Lines | Content |
|------|-------|---------|
| `api/tmi/advisories.php` | Multiple | Discord posting code |
| `api/tmi/gs_apply_ctd.php` | Multiple | Debug logging |

---

## Summary Statistics

| Category | Total Items | Issues Found | Priority |
|----------|-------------|--------------|----------|
| Color Definitions | 500+ | Bootstrap inconsistencies | P2 |
| Date/Time Formats | 25+ patterns | 37 deprecated `.substr()`, 13 `date()` bugs | P1-P2 |
| API Response Formats | 5+ patterns | Inconsistent structures | P3 |
| Configuration Patterns | 8 files | 2 hardcoded passwords | **P0** |
| CSS Classes | 150+ custom | 985 inline styles | P3 |
| JavaScript Patterns | 44 files | 97 `var`, 24 silent catch, 180+ console.log | P1-P3 |
| Database Schemas | 60+ tables | Mixed conventions | P3 |
| PHP Templates | 30+ files | Mixed include patterns | P3 |
| SQL Vulnerabilities | 100+ files | Direct interpolation | **P0** |
| CORS Inconsistencies | 77 wildcard | 2 critical fallbacks | **P0** |
| Magic Numbers | 100+ | Should be constants | P3 |
| Hardcoded References | 50+ | Discord IDs, URLs, lists | P3 |
| Error Handling | 24 silent catch | Missing logging | P1 |
| Security Issues | Multiple | Auth bypass, SQL injection | **P0** |

---

## Priority Actions

### P0 - CRITICAL (Fix Immediately)

1. **Remove hardcoded passwords** from `scripts/migrate_division_events.php` and `api/stats/config_stats.php`
2. **Fix SQL injection vulnerabilities** in 100+ files using prepared statements
3. **Remove CORS wildcard fallback** in `api/swim/v1/auth.php` (lines 467, 939)

### P1 - HIGH (Fix Soon)

4. **Fix PHP `date()` calls** - Change to `gmdate()` in 13 files
5. **Add error logging** to 24 silent catch blocks
6. **Fix `http_response_code()` string args** in 8 files
7. **Standardize session keys** - Use `$_SESSION['VATSIM_CID']` consistently

### P2 - MEDIUM (Plan to Fix)

8. **Replace deprecated `.substr()`** with `.slice()` (37 instances)
9. **Convert `var` to `const`/`let`** (97 instances)
10. **Centralize hardcoded colors** to config files (500+ values)
11. **Replace `SELECT *`** with explicit column lists (17+ files)

### P3 - LOW (Address When Convenient)

12. **Migrate jQuery AJAX** to fetch() (12 files)
13. **Remove debug console.log** from production files (180+ statements)
14. **Standardize API response formats** across all endpoints
15. **Extract magic numbers** to constants files
16. **Consolidate date/time formatting** utilities

---

*Last Updated: February 1, 2026*
*Crawl Rounds Completed: 4*
