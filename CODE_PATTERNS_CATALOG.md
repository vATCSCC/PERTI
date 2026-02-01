# PERTI Code Patterns Catalog

**Generated:** February 2026
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

---

## 1. Color Definitions

### 1.1 JavaScript Config Files

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

### 1.2 CSS Theme Colors

#### `/assets/css/perti_theme.css` (Lines 230-339)

**Semantic Text Colors:**
| Class | Hex | Notes |
|-------|-----|-------|
| `.text-success` | `#43ac6a` | **Inconsistent** with Bootstrap `#28a745` |
| `.text-danger` | `#F04124` | **Inconsistent** with Bootstrap `#dc3545` |
| `.text-warning` | `#E99002` | **Inconsistent** with Bootstrap `#fd7e14` |
| `.text-info` | `#239BCD` | **Inconsistent** with Bootstrap `#17a2b8` |
| `.text-primary` | `#332e7a` | Custom purple |

**Badge Colors:**
| Class | Hex |
|-------|-----|
| `.badge-success` | `#63BD49` |
| `.badge-info` | `#239BCD` |
| `.badge-primary` | `#332e7a` |
| `.badge-dark` | `#1c1946` |

### 1.3 Weather Radar Colors

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

### 1.4 UI Pages Using Colors

| Page | File | Color Usage Location |
|------|------|---------------------|
| Demand Dashboard | `/demand.php` | Info bar cards, phase badges |
| TMI Publisher | `/tmi-publish.php` | Status badges, advisory headers |
| NOD | `/nod.php` | Flight markers, phase indicators |
| GDT | `/gdt.php` | Program status, delay indicators |
| Weather Radar | `/route.php` | Radar legend, hazard indicators |

---

## 2. Date/Time Formats

### 2.1 JavaScript Patterns

#### ISO String Patterns

| Pattern | File | Line | Output Format |
|---------|------|------|---------------|
| `.toISOString()` | multiple | - | `2026-01-21T15:30:45.000Z` |
| `.toISOString().slice(0, 16)` | tmi-publish.js | 1463, 1516 | `2026-01-21T15:30` |
| `.toISOString().slice(11, 16) + 'Z'` | weather_radar.js | 247 | `15:30Z` |
| `.toISOString().substr(11, 8)` | nod.js | 265 | `15:30:45` |
| `.toISOString().substr(11, 5) + 'Z'` | adl-service.js | 265 | `15:30Z` |

**DEPRECATED:** Files using `.substr()` instead of `.slice()`:
- `assets/js/nod.js` - Lines 265, 3612, 5668, 5678
- `assets/js/tmi-publish.js` - Lines 294, 5183
- `assets/js/adl-service.js` - Line 265

#### Custom Format Functions

| Function | File | Output |
|----------|------|--------|
| `formatSignatureTime(date)` | advisory-templates.js:115 | `YY/MM/DD HH:MM` |
| `formatProgramTime(date)` | advisory-templates.js:94 | `DD/HHMMZ` |
| `formatADLTime(date)` | advisory-templates.js:105 | `HHMMZ` |
| `formatTimeDDHHMM(date)` | advisory-templates.js:83 | `DDHHMM` |
| `formatDateMMDDYYYY(date)` | advisory-templates.js:72 | `MM/DD/YYYY` |

#### UI Clock Displays

| Element ID | File | Format | Page |
|------------|------|--------|------|
| `#utc_clock` | tmi-publish.js:295 | `HH:MM:SSZ` | TMI Publisher |
| `#utcTime` | nod.js:266 | `HH:MM:SSZ` | NOD |
| `#jatoc_utc_clock` | jatoc.js:463 | `HH:MM:SSZ` | JATOC |
| `.adl-last-update` | adl-service.js:265 | `HH:MMZ` | Demand, NOD |
| `.tmi-last-refresh` | tmi-active-display.js:2562 | `HH:MM:SS UTC` | TMI Panels |

### 2.2 PHP Patterns

#### `date()` vs `gmdate()` Usage

| Pattern | Files | Purpose |
|---------|-------|---------|
| `gmdate('Y-m-d H:i:s')` | All TMI API files | UTC database timestamps |
| `gmdate('Y-m-d\TH:i:s\Z')` | weather import | ISO 8601 with Z |
| `date('Y-m-d H:i:s')` | cron scripts | Local time logging |

**Files using `gmdate()` correctly:**
- `api/tmi/advisories.php` - Lines 274, 284, 287, 357, 359
- `api/tmi/entries.php` - Lines 282, 348, 350
- `api/tmi/programs.php` - Lines 277, 288, 292, 391, 392
- `api/tmi/reroutes.php` - Lines 373, 416, 483, 535
- `api/tmi/public-routes.php` - Lines 300, 379

#### DateTime Class Usage

| Pattern | File | Purpose |
|---------|------|---------|
| `new DateTime('now', new DateTimeZone('UTC'))` | cron/process_tmi_proposals.php | UTC timestamp |
| `DateTime::createFromFormat('m/d/Y', $date)` | import_procedures.php | Parse US date |
| `$dt->format('Y-m-d\TH:i:s\Z')` | api/weather/impact.php | ISO 8601 output |

---

## 3. API Response Formats

### 3.1 Pattern A: TmiResponse Class

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

**Files using this pattern:**
- `api/tmi/advisories.php`
- `api/tmi/entries.php`
- `api/tmi/programs.php`
- `api/tmi/reroutes.php`
- `api/tmi/public-routes.php`
- `api/mgt/tmi/publish.php`
- `api/mgt/tmi/cancel.php`
- `api/mgt/tmi/coordinate.php`

### 3.2 Pattern B: SwimResponse Class

**Used in:** `/api/swim/v1/`

Same structure as TmiResponse but with:
- `X-SWIM-Cache: HIT|MISS` header
- `ETag` support
- `Content-Encoding: gzip` support

**Files using this pattern:**
- `api/swim/v1/tmi/routes.php`
- `api/swim/v1/flights.php`
- `api/swim/v1/flight.php`

### 3.3 Pattern C: GDT respond_json

**Used in:** `/api/gdt/`

```json
{
  "status": "ok" | "error",
  "message": "Description",
  "data": { ... }
}
```

**Files using this pattern:**
- `api/gdt/programs/publish.php`
- `api/gdt/programs/cancel.php`
- `api/gdt/programs/submit_proposal.php`

### 3.4 Pattern D: Raw JSON (No Wrapper)

**Inconsistent:** Various structures across files

| File | Structure |
|------|-----------|
| `api/adl/current.php` | `{snapshot_utc, flights}` |
| `api/adl/flight.php` | `{error}` or raw flight object |
| `api/data/sua.php` | GeoJSON FeatureCollection |
| `api/demand/summary.php` | `{success, timestamp, time_range, data}` |

---

## 4. Configuration Patterns

### 4.1 PHP Configuration

#### `/load/config.php` (via config.example.php)

**Pattern:** `define()` constants with `env()` helper fallback

```php
define("SQL_HOST", env("SQL_HOST", "default"));
```

**Categories:**
| Category | Constants | Lines |
|----------|-----------|-------|
| Database | SQL_*, ADL_SQL_*, TMI_SQL_*, etc. | 28-79 |
| OAuth | CONNECT_* | 87-91 |
| Discord | DISCORD_* | 96-139 |
| Features | *_ENABLED flags | 141-147 |

#### `/load/swim_config.php`

**Pattern:** Global arrays (`$SWIM_*`) + helper functions

| Variable | Lines | Purpose |
|----------|-------|---------|
| `$SWIM_RATE_LIMITS` | 37-42 | API rate limits by tier |
| `$SWIM_KEY_PREFIXES` | 47-52 | API key prefix patterns |
| `$SWIM_DATA_SOURCES` | 65-106 | Known data sources |
| `$SWIM_DATA_AUTHORITY` | 118-150 | Field authority rules |
| `$SWIM_SOURCE_PRIORITY` | 164-268 | Source priority rankings |
| `$SWIM_FIELD_MERGE_BEHAVIOR` | 283-351 | Merge conflict resolution |
| `$SWIM_CACHE_TTL` | 446-469 | Cache duration by endpoint |
| `$SWIM_CORS_ORIGINS` | 540-546 | Allowed CORS origins |

### 4.2 JavaScript Configuration

#### `/assets/js/config/` Directory

| File | Pattern | Contents |
|------|---------|----------|
| `phase-colors.js` | Multiple const objects | PHASE_COLORS, PHASE_LABELS, PHASE_STACK_ORDER |
| `filter-colors.js` | Single nested FILTER_CONFIG | Weight, carrier, ARTCC colors |
| `rate-colors.js` | Nested RATE_LINE_CONFIG | Rate display styling |

---

## 5. CSS Classes/Naming/Usage

### 5.1 Custom Prefix Patterns

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

**Pages using:**
- `/demand.php` (Lines 491-650)
- `/route.php`
- `/gdt.php`
- `/nod.php`

#### `.dcccp-` Prefix (Initiative Timeline)

**File:** `/assets/css/initiative_timeline.css`

| Class | Purpose |
|-------|---------|
| `.dcccp-timeline-wrapper` | Main timeline container |
| `.dcccp-item` | Individual initiative item |
| `.dcccp-item.level-*` | Level variants (CDW, Possible, etc.) |
| `.dcccp-now-line` | Current time indicator |

**Pages using:**
- `/advisory-builder.php` (Modal)

#### `.ntml-`, `.tmi-`, `.advisory-` Prefixes

**File:** `/assets/css/tmi-publish.css`

**Pages using:**
- `/tmi-publish.php`

### 5.2 Bootstrap Overrides

| Bootstrap Class | Override Location | Custom Value |
|-----------------|-------------------|--------------|
| `.text-success` | perti_theme.css:235 | `#43ac6a` (not `#28a745`) |
| `.text-danger` | perti_theme.css:248 | `#F04124` (not `#dc3545`) |
| `.badge-success` | perti_theme.css:255 | `#63BD49` |
| `.btn-primary` | perti_theme.css:315 | `#222d5c` |

### 5.3 CSS Variables

**File:** `/assets/css/info-bar.css` (Lines 11-26)

```css
--info-utc-color: #3b82f6;
--info-utc-bg: linear-gradient(135deg, #eff6ff, #dbeafe);
--info-airport-color: #0891b2;
--info-config-color: #7c3aed;
--info-atis-color: #059669;
--info-arr-color: #16a34a;
--info-dep-color: #ea580c;
--info-refresh-color: #64748b;
```

---

## 6. JavaScript Patterns

### 6.1 Module Patterns

| Pattern | Files Using | Percentage |
|---------|-------------|------------|
| IIFE with namespace | 17 files | 39% |
| Global objects | 20 files | 45% |
| ES6 class | 1 file (initiative_timeline.js) | 2% |
| ES6 modules | 1 file (route-maplibre.js) | 2% |

**IIFE Example:**
```javascript
(function() {
    'use strict';
    window.FIR_INTEGRATION = { ... };
})();
```

### 6.2 AJAX Patterns

| Pattern | Files | Recommendation |
|---------|-------|----------------|
| `fetch()` + async/await | 21 files | **Preferred** |
| `$.ajax()` / `$.get()` | 12 files | Migrate to fetch |
| jQuery Deferred (`.done()`) | 9 files | Migrate to async/await |

**Files needing migration:**
- `assets/js/plan.js` - Heavy jQuery usage
- `assets/js/demand.js` - Uses `$.getJSON()`
- `assets/js/review.js`
- `assets/js/sheet.js`

### 6.3 Error Handling Issues

**Silent catch blocks found:**
| File | Line | Issue |
|------|------|-------|
| `gdt.js` | 2579, 2714 | `catch(e) {}` - no logging |
| `reroute.js` | 94 | `catch(e) { /* optional */ }` |
| `tmi-publish.js` | 5486 | `catch(e) {}` - silent failure |

### 6.4 Event Handling

| Pattern | Files | Notes |
|---------|-------|-------|
| `addEventListener` | 37 files | Preferred pattern |
| jQuery `.on()` | 24 files | Mixed with vanilla |
| MapLibre `.on()` | 2 files | Library-specific |

---

## 7. Database Schemas

### 7.1 Timestamp Naming Conventions

| Convention | Tables Using | Example |
|------------|--------------|---------|
| `*_utc` suffix | ADL core, some TMI | `first_seen_utc`, `last_seen_utc` |
| `*_at` suffix | Most TMI tables | `created_at`, `updated_at` |
| Semantic names | Advisories | `effective_from`, `effective_until` |

**INCONSISTENCY:** Mixed `_utc` and `_at` in same tables (e.g., `tmi_programs`)

### 7.2 Status Column Types

| Type | Tables | Example Values |
|------|--------|----------------|
| `NVARCHAR(16)` | tmi_entries, tmi_advisories | 'DRAFT', 'ACTIVE', 'EXPIRED' |
| `TINYINT` | tmi_reroutes, tmi_public_routes | 0, 1, 2, 3, 4, 5 |
| `BIT` | Multiple | is_active, is_proposed |

**CRITICAL INCONSISTENCY:** Same concept (status) uses different types

### 7.3 Primary Key Naming

| Pattern | Tables Using | Example |
|---------|--------------|---------|
| `{table}_id` | Most tables | `program_id`, `advisory_id` |
| `{table}_uid` | ADL core entities | `flight_uid` |
| `id` only | Some tables | Generic identity |

### 7.4 Timestamp Precision

| Precision | Tables | Usage |
|-----------|--------|-------|
| `DATETIME2(0)` | Most ADL/TMI | Standard precision |
| `DATETIME2(3)` | Detail/changelog | Sub-second precision |
| `DATETIME2(7)` | SWIM tables | Default precision |

---

## 8. PHP Templates

### 8.1 Include Patterns

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

### 8.2 Session Handling

**Standard Pattern:**
```php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
```

**Files following standard:**
- `/index.php` (Lines 5-8)
- `/airport_config.php` (Lines 5-8)
- `/tmi-publish.php` (Lines 28-30)

### 8.3 Authorization Patterns

**Pattern 1: DEV Mode Override**
```php
if (!defined('DEV')) {
    // Check session CID against users table
} else {
    $perm = true;
}
```

**Pattern 2: Page-Level Gating**
```php
<?php if ($perm == true) { ?>
    <button>Admin Action</button>
<?php } ?>
```

---

## 9. SQL Vulnerabilities

### 9.1 Critical Vulnerabilities (HIGH Severity)

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

### 9.2 Session Variable Injection (MEDIUM Severity)

| File | Line | Vulnerable Code |
|------|------|-----------------|
| `airspace-elements.php` | 22 | `WHERE cid='$cid'` |
| `airport_config.php` | 22 | `WHERE cid='$cid'` |
| `tmi-publish.php` | 50 | `WHERE cid='$userCid'` |
| `swim-keys.php` | 33 | `WHERE cid='$cid'` |

### 9.3 Remediation Pattern

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

### 10.1 Secure Implementations

| Component | File | Method |
|-----------|------|--------|
| TmiResponse | api/tmi/helpers.php | Whitelist + localhost pattern |
| SwimAuth | api/swim/v1/auth.php | Whitelist with fallback |
| Config | load/swim_config.php | Static whitelist |

**Whitelist Origins:**
```php
$SWIM_CORS_ORIGINS = [
    'https://perti.vatcscc.org',
    'https://vatcscc.org',
    'https://swim.vatcscc.org',
    'http://localhost:3000',
    'http://localhost:8080'
];
```

### 10.2 Insecure Implementations (Wildcard)

| File | Line | Methods | Risk |
|------|------|---------|------|
| `api/weather/refresh.php` | 13 | GET | LOW |
| `api/weather/impact.php` | 22 | GET | LOW |
| `api/adl/demand/monitors.php` | 46 | GET, POST, DELETE | **HIGH** |
| `api/mgt/tmi/reroutes/update_geojson.php` | 23 | POST | MEDIUM |
| `api/gis/boundaries.php` | 19 | GET, POST | MEDIUM |

### 10.3 Critical Issue: SwimAuth Fallback

**File:** `/api/swim/v1/auth.php` (Line 467)

```php
} else {
    header("Access-Control-Allow-Origin: *");  // INSECURE FALLBACK
}
```

**Recommendation:** Remove wildcard fallback

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

### 12.5 Internal API Keys

| Key | File | Purpose |
|-----|------|---------|
| `swim_sys_vatcscc_internal_001` | api/admin/run_migration_*.php | Internal migration scripts |

**Recommendation:** Move to environment variables

---

## Summary Statistics

| Category | Total Items | Issues Found |
|----------|-------------|--------------|
| Color Definitions | 200+ | 4 inconsistencies with Bootstrap |
| Date/Time Formats | 25+ patterns | 3 deprecated `.substr()` usages |
| API Response Formats | 4 patterns | Pattern D needs standardization |
| Configuration Patterns | 8 files | Good, but needs consolidation |
| CSS Classes | 150+ custom | 3 class conflicts identified |
| JavaScript Patterns | 44 files | 12 files need jQuery migration |
| Database Schemas | 60+ tables | Critical naming inconsistencies |
| PHP Templates | 30+ files | Mixed include patterns |
| SQL Vulnerabilities | 38 found | **25 HIGH severity** |
| CORS Inconsistencies | 15 endpoints | 1 critical wildcard fallback |
| Magic Numbers | 100+ | Should be in constants files |
| Hardcoded References | 50+ | Discord IDs, URLs, lists |

---

## Priority Actions

1. **P0 - CRITICAL:** Fix 25 SQL injection vulnerabilities
2. **P0 - CRITICAL:** Remove SwimAuth wildcard CORS fallback
3. **P1 - HIGH:** Standardize color values (Bootstrap consistency)
4. **P1 - HIGH:** Create centralized constants files
5. **P2 - MEDIUM:** Migrate jQuery AJAX to fetch()
6. **P2 - MEDIUM:** Standardize API response formats
7. **P3 - LOW:** Consolidate date/time formatting utilities
8. **P3 - LOW:** Externalize hardcoded lists to config/database

---

*Last Updated: February 2026*
