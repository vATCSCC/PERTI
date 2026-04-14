# PERTI Code Inconsistencies - Deep Dive Explanation

This document explains each category of inconsistency, what caused it, and what effects it has on the system.

---

## Table of Contents

1. [Color Definitions](#1-color-definitions)
2. [Date/Time Formatting](#2-datetime-formatting)
3. [API Response Formats](#3-api-response-formats)
4. [Configuration Patterns](#4-configuration-patterns)
5. [CSS Class Naming](#5-css-class-naming)
6. [JavaScript Patterns](#6-javascript-patterns)
7. [Database Schema](#7-database-schema)
8. [PHP Template Structure](#8-php-template-structure)
9. [SQL Injection Vulnerabilities](#9-sql-injection-vulnerabilities)
10. [CORS Inconsistencies](#10-cors-inconsistencies)

---

## 1. Color Definitions

### What Is the Inconsistency?

The same semantic color meaning (e.g., "success", "danger", "warning") uses different hex values across different files.

**Example - "Success Green":**
| Hex Value | Location | Context |
|-----------|----------|---------|
| `#28a745` | `filter-colors.js:13` | Bootstrap standard green |
| `#43ac6a` | `perti_theme.css:236` | `.text-success` class |
| `#63BD49` | `perti_theme.css:257` | `.badge-success` class |

**Example - "Danger Red":**
| Hex Value | Location | Context |
|-----------|----------|---------|
| `#dc3545` | `filter-colors.js`, most JS files | Bootstrap standard red |
| `#F04124` | `perti_theme.css:248` | `.text-danger` class |

### What Caused It?

1. **Multiple developers, no style guide**: Different developers picked colors they thought looked good, without consulting a central reference.

2. **Bootstrap customization vs. hardcoding**: Some files override Bootstrap's default colors (in CSS), while JavaScript files use Bootstrap's standard values directly. The overrides weren't propagated to JS.

3. **Copy-paste from different sources**: Some colors came from Foundation CSS (`#43ac6a`, `#F04124`), while others are Bootstrap 4/5 defaults (`#28a745`, `#dc3545`). PERTI likely started with Foundation and partially migrated to Bootstrap.

4. **Inline colors vs. config**: Many JavaScript files hardcode colors (e.g., `nod.js:367` uses `#28a745`) instead of referencing the centralized `FILTER_CONFIG` in `filter-colors.js`.

### What Effect Does It Have?

1. **Visual inconsistency**: A "success" state in the NTML publisher appears different from a "success" state on the reroute map. Users subconsciously notice this, reducing perceived quality.

2. **Confusion for developers**: When adding a new feature, which green should you use? Developers guess, adding more variants.

3. **Maintenance burden**: If you want to update the brand colors, you must find and change every hardcoded value across 9+ files.

4. **Accessibility issues**: The different greens have different contrast ratios against dark backgrounds. `#43ac6a` passes WCAG AA, but `#63BD49` may not in certain contexts.

**Concrete Example:**
```
Reroute card header in advisory-builder.php → #28a745 (bright green)
Reroute polyline on nod.js map → #fd7e14 (orange)
```
Both represent "reroute" but display completely different colors.

---

## 2. Date/Time Formatting

### What Is the Inconsistency?

The codebase has 20+ different patterns for formatting dates and times, mixing:
- `substr()` vs `slice()` (deprecated vs modern)
- Different slice indices for the same output
- Multiple ways to append the 'Z' suffix
- `date()` vs `gmdate()` in PHP (local time vs UTC)

**JavaScript Examples:**
```javascript
// Pattern 1: nod.js
now.toISOString().substr(11, 8)     // "15:30:45"

// Pattern 2: tmi-publish.js
now.toISOString().slice(11, 16)     // "15:30"

// Pattern 3: demand.js
date.toISOString().replace('.000Z', 'Z')  // "2026-01-21T15:30:45Z"

// Pattern 4: reroute.js
d.toISOString().substr(11, 5) + 'Z'       // "15:30Z"
```

**PHP Examples:**
```php
// Pattern A: Most files (CORRECT)
gmdate('Y-m-d H:i:s')              // "2026-01-21 15:30:45" in UTC

// Pattern B: publish.php:41 (BUG - now fixed)
date('Y-m-d H:i:s')                // Uses SERVER LOCAL TIME, not UTC!

// Pattern C: TMIDiscord.php
$dt->format('d/Hi')                // "21/1530" (FAA format)
```

### What Caused It?

1. **No utility library**: Each developer wrote their own date formatting inline, leading to variations.

2. **Evolution of JavaScript**: `substr()` was common pre-ES6, but is now deprecated. Files written at different times use different methods.

3. **Different display requirements**: The FAA NTML format (`21/1530Z`) differs from ISO 8601 (`2026-01-21T15:30:45Z`). Developers created ad-hoc conversions.

4. **PHP timezone confusion**: PHP's `date()` uses the server's configured timezone, while `gmdate()` always uses UTC. On Azure App Service, the server timezone might not be UTC, causing silent data corruption.

### What Effect Does It Have?

1. **Critical Bug (now fixed)**: `date()` in `publish.php:41` wrote local time to the database while all other code expected UTC. If the server was in EST, a 15:00 entry would be stored as 15:00 but actually meant 20:00 UTC. This caused:
   - NTML entries appearing 5 hours off
   - Expiration logic failing (entries expire early or late)
   - Discord posts showing wrong times

2. **Deprecated `substr()` warnings**: Modern JavaScript linters flag `substr()` as deprecated. This creates noise in development tools and may break in future JS engines.

3. **Inconsistent time display**: Users might see "15:30Z" in one place and "15:30:45" in another for the same timestamp. This causes confusion during coordination.

4. **Maintenance difficulty**: To change the time format (e.g., add seconds everywhere), you'd need to find and update 20+ different patterns.

**Code Smell Example:**
```javascript
// nod.js line 265 - uses deprecated substr
const utc = now.toISOString().substr(11, 8);  // "15:30:45"

// nod.js line 5668 - also uses substr but different indices
return d.toISOString().substr(11, 5) + 'Z';   // "15:30Z"

// Both are in the same file but format times differently!
```

---

## 3. API Response Formats

### What Is the Inconsistency?

API endpoints return data in 4 different wrapper formats:

**Pattern A - `success` boolean:**
```json
{
  "success": true,
  "data": { "entries": [...] },
  "message": "Loaded 15 entries"
}
```
Used by: `tmi_config.php`, `events/list.php`, `active.php`, `demand/summary.php`

**Pattern B - `status` string:**
```json
{
  "status": "ok",
  "message": "Reroute created",
  "data": { "reroute_id": 123 }
}
```
Used by: `reroutes/post.php`, `gdt/programs/*.php`

**Pattern C - Direct data (no wrapper):**
```json
{ "callsign": "UAL123", "origin": "KORD", "destination": "KLAX" }
```
Used by: `adl/flight.php`

**Error field inconsistency:**
```json
// Pattern A error
{ "success": false, "error": "Not found" }

// Pattern B error
{ "status": "error", "message": "Not found" }

// Pattern C error
{ "error": "Flight not found" }
```

### What Caused It?

1. **Different developers, different conventions**: Some developers learned "success: true/false" from tutorials, others used "status: ok/error" from REST conventions.

2. **Incremental development**: Early APIs (like `adl/flight.php`) returned raw data. Later APIs added wrappers for consistency, but older ones weren't updated.

3. **No API guidelines document**: Without a written standard, each endpoint evolved independently.

4. **Copy-paste inheritance**: New endpoints were often copied from existing ones, perpetuating whichever pattern the source used.

### What Effect Does It Have?

1. **Client-side complexity**: Frontend JavaScript must handle multiple response formats:
   ```javascript
   // Every API call needs conditional logic
   fetch('/api/tmi/active.php')
     .then(r => r.json())
     .then(data => {
       if (data.success !== undefined) {
         // Pattern A
         return data.data;
       } else if (data.status !== undefined) {
         // Pattern B
         return data.data;
       } else {
         // Pattern C - raw data
         return data;
       }
     });
   ```

2. **Error handling inconsistency**: Is the error in `data.error`, `data.message`, or the HTTP status code? Different endpoints require different checks.

3. **Integration difficulty**: External systems consuming the API must implement special cases for each endpoint.

4. **Documentation burden**: API docs must explain each endpoint's response format individually.

**JSON Key Naming Sub-Issue:**
```json
// active.php uses camelCase
{ "entityType": "AIRPORT", "validFrom": "2026-01-21T15:00:00Z" }

// demand/summary.php uses snake_case
{ "time_range": "1h", "top_origins": [...] }
```
This forces frontend code to handle both conventions or map between them.

---

## 4. Configuration Patterns

### What Is the Inconsistency?

Configuration is defined in 4 different ways across the codebase:

**Pattern 1 - PHP `define()` constants:**
```php
// config.php
define('SQL_HOST', 'vatcscc-perti.mysql.database.azure.com');
define('SQL_USERNAME', 'perti_admin');
```

**Pattern 2 - PHP arrays:**
```php
// swim_config.php
$SWIM_RATE_LIMITS = [
    'STDDS' => 30000,  // Magic number!
    'TFMS' => 60000
];
```

**Pattern 3 - PHP class constants:**
```php
// jatoc/config.php
const JATOC_TRIGGERS = ['CONVECTIVE', 'VOLCANIC', 'TURBULENCE'];
```

**Pattern 4 - JSON files:**
```json
// azure_perti_config.json
{ "subscription_id": "...", "resource_group": "perti-prod" }
```

**Discord Configuration Duplication:**
```php
// config.php (production values)
'guild_id' => '1039586513689780224'
'channels' => ['ntml' => '1350319537526014062']

// config.example.php (completely different values!)
'guild_id' => '358294607974539265'
'channels' => ['ntml' => '358295136398082048']
```

### What Caused It?

1. **Organic growth**: The project started with `define()` statements. As it grew, developers added arrays for complex structures, classes for OOP code, and JSON for infrastructure.

2. **Example file drift**: `config.example.php` was created as a template, but someone put real (old) Discord IDs in it instead of placeholders. It was never updated when production values changed.

3. **Magic numbers**: Developers hardcoded values like `30000` (rate limit in ms) directly instead of extracting to named constants.

4. **No configuration management strategy**: Without a decision on "how we do config," everyone did what felt natural.

### What Effect Does It Have?

1. **Potential message routing failures**: If someone sets up a new environment using `config.example.php` as a template, Discord messages would go to the wrong server/channel (or fail entirely if those IDs are deleted).

2. **Confusion about source of truth**: Should I look in `config.php`, `azure_perti_config.json`, or environment variables? Different code paths check different places.

3. **Magic number maintenance**: When the SWIM rate limit needs to change from 30000ms to 45000ms, you must search for `30000` across the codebase and hope you find all instances.

4. **Mixed eager/lazy loading in connect.php**:
   ```php
   // Lines 46-69: Eager loading - ALWAYS creates MySQL connection on include
   $conn_pdo = new PDO("mysql:host={$sql_host}...");

   // Lines 109-155: Lazy loading - only connects when get_conn_adl() is called
   function get_conn_adl() { ... }

   // Line 362: TODO comment acknowledging the inconsistency
   // "TODO: Migrate callers to use get_conn_*"
   ```
   This wastes resources on pages that don't need all databases.

---

## 5. CSS Class Naming

### What Is the Inconsistency?

Multiple naming conventions coexist without clear rules:

**Prefix Systems:**
| Prefix | Origin | Examples |
|--------|--------|----------|
| `.perti-` | PERTI custom | `.perti-info-bar`, `.perti-clock-display` |
| `.cs-` | Unknown (legacy?) | `.cs-fancy-tabs`, `.cs-password-toggle` |
| `.advisory-` | Feature-based | `.advisory-type-card`, `.advisory-card` |
| `.tmi-` | Feature-based | `.tmi-section-title`, `.tmi-section-tabs` |
| (none) | Bootstrap | `.card`, `.btn`, `.badge` |

**Bootstrap Override Conflicts:**
```css
/* perti_theme.css */
.text-success { color: #43ac6a !important; }  /* Overrides Bootstrap */
.btn-primary { background-color: #222d5c; }   /* Overrides Bootstrap */

/* But Bootstrap's default is #28a745 and #007bff respectively */
```

**Semantic Collision:**
```css
/* review.php - these are NOT Bootstrap grid columns! */
.col-arr { width: 60px; }  /* Arrival column */
.col-dep { width: 60px; }  /* Departure column */

/* But Bootstrap has .col-* for responsive grid */
```

### What Caused It?

1. **Framework migration**: Project likely started with Foundation (`.cs-` prefix?), then migrated to Bootstrap without renaming existing classes.

2. **No naming convention document**: Without a written standard, developers used whatever prefix felt right.

3. **Bootstrap customization approach**: Instead of using Bootstrap's theming system (Sass variables), developers overrode classes directly with `!important`, which is a code smell.

4. **Collision blindness**: `.col-arr` was named thinking "column for arrivals" without considering Bootstrap's `.col-*` grid system.

### What Effect Does It Have?

1. **Specificity wars**: The `!important` flags indicate specificity battles. To override a `!important`, you need another `!important` with higher specificity. This creates escalating CSS complexity.

2. **Bootstrap upgrade risk**: If you upgrade Bootstrap, the new version's `.text-success` might conflict with your override, causing visual bugs.

3. **Developer confusion**: When someone sees `.col-dep`, do they think "Bootstrap column" or "departure column"? This slows down onboarding.

4. **State class confusion**:
   ```css
   /* tmi-publish.css */
   .active { background: #007bff; }

   /* advisory-builder.php inline styles */
   .selected { background: #007bff; }
   ```
   Both mean "currently chosen" but use different class names. Components can't share styling.

---

## 6. JavaScript Patterns

### What Is the Inconsistency?

8+ different coding patterns are mixed throughout the JavaScript codebase:

**jQuery vs Vanilla JS:**
```javascript
// tmi-publish.js - jQuery
$('#submitBtn').on('click', function() { ... });

// weather_radar_integration.js - Vanilla
document.getElementById('submitBtn').addEventListener('click', () => { ... });
```

**AJAX Patterns:**
```javascript
// Pattern 1: jQuery callbacks (tmi-publish.js, tmi_compliance.js)
$.ajax({
    url: '/api/tmi/entries.php',
    success: function(data) { ... },
    error: function(xhr) { ... }
});

// Pattern 2: fetch + then (demand.js, weather_hazards.js)
fetch('/api/demand/summary.php')
    .then(r => r.json())
    .then(data => { ... });

// Pattern 3: await $.ajax (tmi-publish.js:3416)
const result = await $.ajax({ url: '/api/...' });

// Pattern 4: await fetch (adl-service.js)
const result = await fetch('/api/...').then(r => r.json());
```

**Silent Error Suppression:**
```javascript
// gdt.js:2579, route-maplibre.js:2328, tmi-publish.js:5486
try {
    const data = JSON.parse(localStorage.getItem('key'));
} catch (e) {}  // Error silently swallowed!
```

**Module Patterns:**
```javascript
// IIFE + window (tmi-publish.js)
(function() {
    window.TMIPublisher = { ... };
})();

// Direct window (demand.js)
window.formatConfigName = function(name) { ... };

// IIFE + module.exports (weather_radar.js)
(function() {
    if (typeof module !== 'undefined') {
        module.exports = WeatherRadar;
    }
})();
```

### What Caused It?

1. **Incremental jQuery removal**: Project started with jQuery. Some files were modernized to vanilla JS, others weren't. The transition was never completed.

2. **No code review standards**: Silent `catch(e) {}` blocks were added for "quick fixes" and never flagged in review.

3. **async/await adoption timeline**: Earlier files use callbacks, newer files use async/await. Both patterns coexist.

4. **No module bundler**: Without Webpack/Rollup, there's no standard module system. Each file invents its own export pattern.

### What Effect Does It Have?

1. **Debugging nightmares**: When `catch(e) {}` swallows errors, bugs become invisible:
   ```javascript
   try {
       const profile = JSON.parse(localStorage.getItem('userProfile'));
       // If JSON is corrupted, profile is undefined
       // No error logged, no indication anything went wrong
   } catch (e) {}
   // Code continues with undefined profile, causing mysterious bugs later
   ```

2. **Bundle size bloat**: jQuery is ~87KB minified. If most code is vanilla JS but a few files need jQuery, you're shipping 87KB for a handful of uses.

3. **Maintenance inconsistency**: Fixing a bug might require understanding both jQuery and vanilla patterns in the same file.

4. **Deprecated `substr()` usage**: Found in 36+ locations across the JS codebase:
   ```javascript
   // This will trigger linter warnings and may break in future JS engines
   now.toISOString().substr(11, 8)

   // Should be:
   now.toISOString().slice(11, 19)
   ```

---

## 7. Database Schema

### What Is the Inconsistency?

Tables across different databases/migrations use different conventions for the same concepts:

**Timestamp Column Naming:**
```sql
-- adl/migrations/core/001_adl_core_tables.sql
first_seen_utc      DATETIME2(0),
last_seen_utc       DATETIME2(0)

-- database/migrations/tmi/001_tmi_core_schema_azure_sql.sql
created_at          DATETIME2(0),
updated_at          DATETIME2(0)

-- adl/migrations/tmi/010_reroute_tables.sql
created_utc         DATETIME2(0),
updated_utc         DATETIME2(0)
```

**Status Column Types:**
```sql
-- 010_reroute_tables.sql - TINYINT with magic numbers
status TINYINT NOT NULL DEFAULT 0,  -- 0=draft, 1=proposed, 2=active...

-- 001_tmi_core_schema_azure_sql.sql - NVARCHAR with strings
status NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',  -- 'DRAFT', 'PROPOSED', 'ACTIVE'
```

**Primary Key Naming:**
```sql
-- swim tables
id INT IDENTITY(1,1) PRIMARY KEY

-- tmi tables
entry_id INT IDENTITY(1,1) PRIMARY KEY

-- adl tables
flight_uid BIGINT IDENTITY(1,1) PRIMARY KEY
```

**Character Type Inconsistencies:**
```sql
-- adl/core/001
callsign NVARCHAR(16)
airport CHAR(4)

-- tmi/010_reroute_tables.sql
callsign NVARCHAR(12)   -- Different length!
airport NCHAR(4)        -- Different type!
```

### What Caused It?

1. **Multiple database designers**: ADL was designed by one person/team, TMI by another. Each brought their own naming conventions.

2. **TINYINT vs NVARCHAR for status**: TINYINT saves space (1 byte vs 16+ bytes) but requires a lookup table or code comments to understand. NVARCHAR is self-documenting but larger. Different developers made different tradeoffs.

3. **Evolution over time**: Early tables used `_utc` suffix (explicit), later tables used `_at` suffix (Rails-inspired). Neither was deprecated.

4. **No schema standards document**: Without written rules, each migration followed whatever the author preferred.

### What Effect Does It Have?

1. **Query complexity**: Joining tables requires careful column mapping:
   ```sql
   -- Which timestamp column is which?
   SELECT
       e.created_at,        -- tmi_entries
       r.created_utc,       -- tmi_reroutes
       f.first_seen_utc     -- adl_flight_core
   FROM tmi_entries e
   JOIN tmi_reroutes r ON ...
   JOIN adl_flight_core f ON ...
   ```

2. **Status comparison issues**:
   ```sql
   -- This works for tmi_entries (NVARCHAR)
   WHERE status = 'ACTIVE'

   -- This works for tmi_reroutes (TINYINT)
   WHERE status = 2

   -- You can't write one query that works for both!
   ```

3. **Callsign truncation risk**: If `tmi_reroutes.callsign` is `NVARCHAR(12)` but the source data has 16-character callsigns, data will be silently truncated or cause insert failures.

4. **DATETIME2 precision inconsistency**:
   ```sql
   DATETIME2(0)   -- Seconds precision (tmi tables)
   DATETIME2(3)   -- Milliseconds (some adl tables)
   DATETIME2(7)   -- Full precision, default (swim tables)
   ```
   Joining on timestamps with different precisions can cause subtle matching failures.

---

## 8. PHP Template Structure

### What Is the Inconsistency?

**Include Patterns:**
```php
// index.php, demand.php
include("header.php");

// API files
require_once(__DIR__ . "/../../load/config.php");

// header.php
include_once(__DIR__ . "/nav.php");
```

**Short Tags vs Full Echo:**
```php
// index.php, header.php (short tags)
<h1><?= $pageTitle ?></h1>

// API files (full echo - rarely used)
<?php echo json_encode($data); ?>
```

**Script Loading Attributes:**
```html
<!-- index.php, airport_config.php -->
<script src="app.js" async></script>

<!-- review.php -->
<script src="review.js" defer></script>

<!-- 30+ other files -->
<script src="other.js"></script>  <!-- No async or defer -->
```

### What Caused It?

1. **No PHP style guide**: `include` vs `require` vs `require_once` have different behaviors (fail silently vs throw error vs prevent double-loading), but no document explains when to use which.

2. **Short tag preference**: Short tags (`<?=`) are cleaner but were disabled by default in older PHP versions. Developers who grew up with disabled short tags write `<?php echo`.

3. **Script performance knowledge**: `async` and `defer` improve page load performance but require understanding of execution timing. Most developers just wrote `<script>` without thinking about it.

### What Effect Does It Have?

1. **Silent include failures**: `include("missing.php")` produces a warning but continues execution. The page may render partially broken without obvious errors.

2. **Double-loading bugs**: Without `include_once`, the same file can be included multiple times, causing "function already defined" errors.

3. **Page load performance**: Scripts without `async` or `defer` block HTML parsing:
   ```html
   <!-- User sees blank page until ALL scripts load -->
   <script src="big-library.js"></script>
   <script src="another-big-file.js"></script>
   <div>Content appears after scripts load</div>
   ```

---

## 9. SQL Injection Vulnerabilities

### What Is the Inconsistency?

Some files use prepared statements (secure), while others use direct string interpolation (vulnerable).

**Vulnerable Code (api/data/routes.php:35):**
```php
$search = get_input('search');
$query = mysqli_query($conn_sqli,
    "SELECT * FROM route_cdr WHERE rte_orig LIKE '%$search%'"
);
```

**Secure Code (api/tmi/entries.php):**
```php
$search = tmi_param('search');
$stmt = $conn_tmi->prepare(
    "SELECT * FROM tmi_entries WHERE condition_text LIKE ?"
);
$stmt->execute(['%' . $search . '%']);
```

### What Caused It?

1. **Legacy code**: Older files (like `routes.php`) were written before the team adopted prepared statements as a standard.

2. **get_input() false sense of security**: The `get_input()` function does basic sanitization, but it's NOT sufficient for SQL injection prevention. Developers may have assumed it was safe.

3. **No security audit**: These files were never reviewed with security in mind.

### What Effect Does It Have?

1. **Data breach risk**: An attacker can inject SQL to:
   ```
   /api/data/routes.php?search=' OR '1'='1
   ```
   This would return ALL routes, not just matching ones.

2. **Data destruction**: With sufficient privileges:
   ```
   /api/data/routes.php?search='; DROP TABLE route_cdr; --
   ```

3. **Authentication bypass**: In `personnel.php:21`:
   ```php
   $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
   ```
   An attacker could inject `' OR '1'='1` to bypass CID validation.

---

## 10. CORS Inconsistencies

### What Is the Inconsistency?

**Wildcard CORS (least secure):**
```php
// weather/refresh.php, data/sua.php, data/tfr.php
header('Access-Control-Allow-Origin: *');
```

**Whitelisted CORS (most secure):**
```php
// api/tmi/helpers.php - TmiResponse class
$allowed = ['https://perti.vatcscc.net', 'http://localhost:3000'];
if (in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
```

### What Caused It?

1. **Convenience over security**: `*` is the easiest CORS setting. When debugging, developers set `*` and forgot to restrict it later.

2. **Public vs private endpoints**: Weather data (`sua.php`, `tfr.php`) is genuinely public, so `*` might be intentional. But there's no documentation explaining the decision.

3. **TmiResponse came later**: The secure CORS helper in `helpers.php` was added for new TMI endpoints. Older endpoints weren't migrated.

### What Effect Does It Have?

1. **Security exposure**: With `Access-Control-Allow-Origin: *`, any website can make requests to these endpoints:
   ```javascript
   // Malicious site can fetch PERTI data
   fetch('https://perti.vatcscc.net/api/data/sua.php')
       .then(r => r.json())
       .then(data => sendToAttackerServer(data));
   ```

2. **Inconsistent security posture**: Some endpoints are hardened, others are wide open. This creates confusion about the security model.

3. **Credential issues**: `Access-Control-Allow-Origin: *` cannot be combined with `Access-Control-Allow-Credentials: true`. If an endpoint needs cookies/sessions, the `*` won't work.

---

## Summary: Root Causes

Most inconsistencies stem from:

1. **Organic growth without standards** - The project evolved over time without written conventions
2. **Multiple developers with different backgrounds** - Each brought their own preferences
3. **Incomplete migrations** - Framework/pattern changes started but never finished
4. **No code review checklist** - Inconsistencies weren't caught during review
5. **Copy-paste inheritance** - New code copied patterns from nearby (possibly outdated) code

## Recommended Fix Order

1. **P0 - Security**: SQL injection vulnerabilities (4 files)
2. **P0 - Data integrity**: Already fixed (`date()` → `gmdate()`)
3. **P1 - Developer experience**: API response format standardization
4. **P1 - User experience**: Color standardization
5. **P2 - Maintainability**: Date/time utilities, configuration cleanup
6. **P3 - Technical debt**: JS modernization, CSS cleanup

---

*Generated: February 2026*
