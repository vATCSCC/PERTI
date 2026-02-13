# PERTI Coding Standards

**Version:** 1.0.0
**Effective:** February 2026

This document defines the coding standards for the PERTI codebase. All new code MUST follow these standards. Existing code should be migrated incrementally.

---

## Table of Contents

1. [PHP Standards](#php-standards)
2. [JavaScript Standards](#javascript-standards)
3. [Database Standards](#database-standards)
4. [API Standards](#api-standards)
5. [Security Standards](#security-standards)
6. [Library Usage](#library-usage)

---

## PHP Standards

### P1. Database Queries - REQUIRED

**NEVER use raw SQL interpolation. ALWAYS use the `Database` class.**

```php
// ❌ FORBIDDEN - SQL Injection vulnerable
$query = $conn->query("SELECT * FROM users WHERE id=$id");
$query = $conn->query("SELECT * FROM users WHERE cid='$cid'");
$query = $conn->query("UPDATE table SET col='$val' WHERE id=$id");

// ✅ REQUIRED - Use Database class
use PERTI\Lib\Database;

$user = Database::selectOne($conn, "SELECT * FROM users WHERE id = ?", [$id]);
$users = Database::select($conn, "SELECT * FROM users WHERE cid = ?", [$cid]);
Database::execute($conn, "UPDATE table SET col = ? WHERE id = ?", [$val, $id]);

// ✅ For LIKE queries
$search = Database::escapeLike($userInput);
$results = Database::select($conn, "SELECT * FROM t WHERE col LIKE ?", ["%{$search}%"]);
```

### P2. Date/Time Handling - REQUIRED

**NEVER use `date()` for UTC times. ALWAYS use the `DateTime` class.**

```php
// ❌ FORBIDDEN - Uses server timezone
$now = date('Y-m-d H:i:s');
$date = date('Ymd');

// ✅ REQUIRED - Use DateTime class
use PERTI\Lib\DateTime;

$now = DateTime::nowUtc();           // 2026-02-01 15:30:45
$date = DateTime::gufiDate();        // 20260201
$iso = DateTime::formatIso();        // 2026-02-01T15:30:45Z
$log = DateTime::formatLog();        // [2026-02-01 15:30:45 UTC]
```

### P3. API Responses - REQUIRED

**NEVER use raw `echo json_encode()`. ALWAYS use the `Response` class.**

```php
// ❌ FORBIDDEN - Inconsistent format
echo json_encode(['success' => true, 'data' => $data]);
http_response_code('500');  // String arg
header('Access-Control-Allow-Origin: *');  // Wildcard

// ✅ REQUIRED - Use Response class
use PERTI\Lib\Response;

Response::success($data);
Response::error('Not found', 404, 'NOT_FOUND');
Response::validationError(['field' => 'required']);
Response::handlePreflight();  // Handle OPTIONS
```

### P4. Session Handling - REQUIRED

**NEVER use raw `$_SESSION` keys. ALWAYS use the `Session` class.**

```php
// ❌ FORBIDDEN - Inconsistent keys
$cid = $_SESSION['VATSIM_CID'];
$cid = $_SESSION['cid'];  // Different key!

// ✅ REQUIRED - Use Session class
use PERTI\Lib\Session;

$cid = Session::getCid();
Session::requireAuth();  // Auto 401 if not logged in
Session::requirePermission('admin');
```

### P5. Configuration - REQUIRED

**NEVER hardcode credentials or connection strings.**

```php
// ❌ FORBIDDEN - Hardcoded credentials
$pass = getenv('DB_PASS') ?: '<PASSWORD>';

// ✅ REQUIRED - Require env vars
$pass = getenv('DB_PASS');
if (!$pass) {
    throw new Exception('DB_PASS environment variable not set');
}
```

### P6. Include Guards - RECOMMENDED

```php
// ✅ RECOMMENDED - Prevent double-include
if (defined('MY_FILE_LOADED')) { return; }
define('MY_FILE_LOADED', true);
```

---

## JavaScript Standards

### J1. Variable Declarations - REQUIRED

**NEVER use `var`. ALWAYS use `const` or `let`.**

```javascript
// ❌ FORBIDDEN
var x = 5;
var arr = [];

// ✅ REQUIRED
const x = 5;       // Immutable binding
let arr = [];      // Mutable binding

// ⚠️ EXCEPTION: Legacy patterns that intentionally use hoisting
// (snow.js, cycle.js) - document with comment
```

### J2. String Methods - REQUIRED

**NEVER use `.substr()`. ALWAYS use `.slice()`.**

```javascript
// ❌ DEPRECATED
str.substr(11, 5);   // Start index, length
str.substr(0, 10);

// ✅ REQUIRED
str.slice(11, 16);   // Start index, end index
str.slice(0, 10);
```

### J3. Date/Time Handling - REQUIRED

**NEVER use raw Date methods for formatting. ALWAYS use `PERTIDateTime`.**

```javascript
// ❌ FORBIDDEN
new Date().toISOString().substr(11, 8);
const d = new Date(); d.getUTCHours() + ':' + d.getUTCMinutes();

// ✅ REQUIRED
PERTIDateTime.nowTimeZ();        // "15:30:45Z"
PERTIDateTime.formatTimeZ(d);    // "15:30:45Z"
PERTIDateTime.formatSignature(); // "26/02/01 15:30"
```

### J4. Logging - REQUIRED

**NEVER use bare `console.log()` in production code. ALWAYS use `PERTILogger`.**

```javascript
// ❌ FORBIDDEN in production
console.log('Debug:', data);
console.log('Loading...');

// ✅ REQUIRED - Conditional logging
const log = PERTILogger.create('MyModule');
log.debug('Debug:', data);   // Only outputs if DEBUG enabled
log.error('Failed:', err);   // Always outputs

// ⚠️ EXCEPTION: Server-side scripts (discord-bot, simulator)
// may use console.log for process output
```

### J5. Colors - REQUIRED

**NEVER hardcode hex colors. ALWAYS use `PERTIColors`.**

```javascript
// ❌ FORBIDDEN
const color = '#dc3545';
chart.color = '#28a745';

// ✅ REQUIRED
const color = PERTIColors.semantic.danger;
chart.color = PERTIColors.semantic.success;
const phaseColor = PERTIColors.forPhase('enroute');
const wtcColor = PERTIColors.forWeightClass('H');
```

### J6. Error Handling - REQUIRED

**NEVER use empty catch blocks. ALWAYS log or handle errors.**

```javascript
// ❌ FORBIDDEN - Silent failure
try {
    JSON.parse(data);
} catch (e) {}

// ✅ REQUIRED - Log the error
try {
    JSON.parse(data);
} catch (e) {
    log.warn('Failed to parse JSON:', e.message);
    return null;
}
```

### J7. AJAX Requests - RECOMMENDED

**Prefer `fetch()` with async/await over jQuery AJAX.**

```javascript
// ⚠️ LEGACY - Migrate when touching this code
$.ajax({ url: '/api/data', success: fn });

// ✅ PREFERRED
const response = await fetch('/api/data');
const data = await response.json();
```

---

## Database Standards

### D1. Parameterized Queries - REQUIRED

All database queries MUST use parameterized statements. See [P1](#p1-database-queries---required).

### D2. Timestamp Columns - REQUIRED

**Use `_utc` suffix for all timestamp columns.**

```sql
-- ✅ REQUIRED
created_utc DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
updated_utc DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
expires_utc DATETIME2 NULL

-- ❌ AVOID mixing conventions
created_at, updated_at  -- Inconsistent with _utc pattern
```

### D3. Status Columns - REQUIRED

**Use NVARCHAR with defined values, not magic integers.**

```sql
-- ✅ REQUIRED
status NVARCHAR(16) NOT NULL CHECK (status IN ('DRAFT', 'ACTIVE', 'EXPIRED'))

-- ❌ AVOID
status TINYINT  -- What does 3 mean?
```

### D4. Explicit Column Lists - REQUIRED

**NEVER use `SELECT *` in production code.**

```sql
-- ❌ FORBIDDEN
SELECT * FROM users WHERE id = ?

-- ✅ REQUIRED
SELECT id, cid, name, email, created_utc FROM users WHERE id = ?
```

---

## API Standards

### A1. Response Format - REQUIRED

All API responses MUST follow this structure:

```json
// Success
{
    "success": true,
    "data": { ... },
    "timestamp": "2026-02-01T15:30:45Z",
    "meta": { ... }  // Optional
}

// Error
{
    "success": false,
    "error": true,
    "message": "Human readable message",
    "status": 400,
    "code": "VALIDATION_ERROR",
    "timestamp": "2026-02-01T15:30:45Z"
}
```

### A2. HTTP Status Codes - REQUIRED

| Code | Usage |
|------|-------|
| 200 | Success (GET, PUT) |
| 201 | Created (POST) |
| 204 | No Content (DELETE, OPTIONS) |
| 400 | Validation error |
| 401 | Authentication required |
| 403 | Permission denied |
| 404 | Resource not found |
| 500 | Server error |

### A3. CORS - REQUIRED

**NEVER use wildcard CORS on authenticated endpoints.**

```php
// ❌ FORBIDDEN on write endpoints
header('Access-Control-Allow-Origin: *');

// ✅ REQUIRED - Use Response class
Response::setCors();  // Uses whitelist
```

---

## Security Standards

### S1. Input Validation - REQUIRED

- Validate ALL input types before use
- Cast numeric IDs: `$id = Database::validateId($input)`
- Sanitize display output: `htmlspecialchars()`
- NEVER trust client-side validation alone

### S2. Authentication - REQUIRED

- Use `Session::requireAuth()` on protected endpoints
- Use `Session::requirePermission()` for role-based access
- NEVER bypass auth checks in production code

### S3. Secrets - REQUIRED

- NEVER commit passwords, API keys, or tokens
- Use environment variables for all credentials
- Use Azure App Settings for production config

---

## Library Usage

### Required Imports

```php
// PHP - Add to files needing these utilities
use PERTI\Lib\Database;
use PERTI\Lib\DateTime;
use PERTI\Lib\Response;
use PERTI\Lib\Session;
```

```html
<!-- JavaScript - Include before app code -->
<script src="/assets/js/lib/datetime.js"></script>
<script src="/assets/js/lib/logger.js"></script>
<script src="/assets/js/lib/colors.js"></script>
```

### Autoloading

Add to `composer.json`:
```json
{
    "autoload": {
        "psr-4": {
            "PERTI\\Lib\\": "lib/"
        }
    }
}
```

---

## Migration Checklist

When modifying existing code, apply these standards:

- [ ] Replace raw SQL with `Database::` methods
- [ ] Replace `date()` with `DateTime::` methods
- [ ] Replace `echo json_encode()` with `Response::` methods
- [ ] Replace `$_SESSION` access with `Session::` methods
- [ ] Replace `var` with `const`/`let`
- [ ] Replace `.substr()` with `.slice()`
- [ ] Replace `console.log()` with `PERTILogger`
- [ ] Replace hardcoded colors with `PERTIColors`
- [ ] Add error handling to empty catch blocks

---

*Last Updated: February 1, 2026*
