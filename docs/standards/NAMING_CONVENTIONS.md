# PERTI Naming Conventions

**Version:** 1.0.0
**Effective:** February 2026

This document defines naming conventions for all global variables, functions, classes, and constants throughout the PERTI codebase.

---

## Table of Contents

1. [PHP Naming](#php-naming)
2. [JavaScript Naming](#javascript-naming)
3. [Database Naming](#database-naming)
4. [Environment Variables](#environment-variables)
5. [Global Variable Registry](#global-variable-registry)

---

## PHP Naming

### Classes

**Format:** `PascalCase`
**Namespace:** `PERTI\{Module}`

```php
// ✅ CORRECT
namespace PERTI\Lib;
class Database { }
class TmiProgram { }
class SwimResponse { }

// ❌ WRONG
class database { }
class tmi_program { }
class SWIM_RESPONSE { }
```

### Functions

**Format:** `snake_case` for global, `camelCase` for methods

```php
// ✅ Global functions
function get_adl_connection() { }
function validate_cid($cid) { }
function format_utc_timestamp($ts) { }

// ✅ Class methods
class User {
    public function getCid() { }
    public function hasPermission($perm) { }
    public function validateSession() { }
}

// ❌ WRONG
function GetADLConnection() { }
function ValidateCID() { }
```

### Constants

**Format:** `SCREAMING_SNAKE_CASE`
**Prefix by module**

```php
// ✅ CORRECT - With module prefix
define('SQL_HOST', '...');           // Core database
define('SWIM_API_VERSION', '1.0');   // SWIM module
define('TMI_MAX_DELAY', 180);        // TMI module
define('ADL_REFRESH_INTERVAL', 60);  // ADL module

// ❌ WRONG
define('host', '...');
define('apiVersion', '1.0');
define('MaxDelay', 180);
```

### Variables

**Format:** `$snake_case`

| Type | Prefix | Example |
|------|--------|---------|
| Database connection | `$conn_` | `$conn_sqli`, `$conn_adl`, `$conn_tmi` |
| Query result | `$result_` or `$rows_` | `$result_flights`, `$rows_users` |
| Prepared statement | `$stmt_` | `$stmt_insert` |
| Configuration | `$config_` | `$config_api` |
| User input | `$input_` | `$input_cid`, `$input_search` |
| Temporary | `$tmp_` | `$tmp_data` |
| Boolean | `$is_`, `$has_`, `$can_` | `$is_active`, `$has_permission` |

```php
// ✅ CORRECT
$conn_sqli = get_mysql_connection();
$conn_adl = get_adl_connection();
$rows_flights = Database::select($conn_adl, "SELECT ...");
$is_authenticated = Session::isAuthenticated();
$has_admin_access = Session::hasPermission('admin');

// ❌ WRONG
$c = get_connection();
$data = Database::select(...);
$auth = Session::isAuthenticated();
```

### Global PHP Variables

**MUST use descriptive prefixes**

| Variable | Purpose | Defined In |
|----------|---------|------------|
| `$conn_sqli` | MySQL connection (PERTI site) | `load/connect.php` |
| `$conn_adl` | SQL Server connection (VATSIM_ADL) | `load/connect.php` |
| `$conn_tmi` | SQL Server connection (VATSIM_TMI) | `load/connect.php` |
| `$conn_ref` | SQL Server connection (VATSIM_REF) | `load/connect.php` |
| `$conn_swim` | SQL Server connection (SWIM_API) | `load/connect.php` |
| `$conn_gis` | PostgreSQL connection (VATSIM_GIS) | `load/connect.php` |
| `$SWIM_CORS_ORIGINS` | Allowed CORS origins | `load/swim_config.php` |

---

## JavaScript Naming

### Global Objects/Namespaces

**Format:** `PascalCase` with `PERTI` prefix for libraries

```javascript
// ✅ CORRECT - Library namespaces
const PERTIDateTime = (function() { ... })();
const PERTILogger = (function() { ... })();
const PERTIColors = (function() { ... })();

// ✅ CORRECT - Module namespaces
const GDTModule = (function() { ... })();
const TMIPublish = (function() { ... })();
const NODDisplay = (function() { ... })();

// ❌ WRONG
const datetime = { ... };
const gdt = { ... };
const TMIPUBLISH = { ... };
```

### Global State Objects

**Format:** `camelCase` with descriptive name

```javascript
// ✅ CORRECT
const gdtState = {
    flights: [],
    selectedAirport: null,
    isLoading: false,
    lastRefresh: null,
};

const tmiProgramState = {
    programId: null,
    entries: [],
    isDraft: true,
};

// ❌ WRONG
const state = { ... };  // Too generic
const s = { ... };       // Meaningless
const DATA = { ... };    // Unclear purpose
```

### Constants

**Format:** `SCREAMING_SNAKE_CASE`

```javascript
// ✅ CORRECT
const MAP_DEFAULT_LAT = 39.5;
const MAP_DEFAULT_LON = -98.35;
const REFRESH_INTERVAL_MS = 60000;
const MAX_FLIGHTS_DISPLAY = 1000;
const API_BASE_URL = '/api';

// ❌ WRONG
const defaultLat = 39.5;
const DEFAULTLAT = 39.5;
const DefaultLat = 39.5;
```

### Functions

**Format:** `camelCase`
**Naming patterns:**

| Action | Pattern | Example |
|--------|---------|---------|
| Get data | `get{What}` | `getFlights()`, `getUserInfo()` |
| Fetch from API | `fetch{What}` | `fetchFlightData()`, `fetchWeather()` |
| Load from storage | `load{What}` | `loadConfig()`, `loadFromCache()` |
| Set/update | `set{What}` or `update{What}` | `setActive()`, `updateDisplay()` |
| Boolean check | `is{What}` or `has{What}` | `isValid()`, `hasPermission()` |
| Event handler | `handle{Event}` or `on{Event}` | `handleClick()`, `onLoad()` |
| Render/display | `render{What}` | `renderFlightList()`, `renderMap()` |
| Format | `format{What}` | `formatTime()`, `formatCurrency()` |
| Validate | `validate{What}` | `validateInput()`, `validateCid()` |
| Parse | `parse{What}` | `parseResponse()`, `parseDate()` |
| Build/create | `create{What}` or `build{What}` | `createMarker()`, `buildQuery()` |
| Initialize | `init{What}` or `initialize{What}` | `initMap()`, `initializeApp()` |

```javascript
// ✅ CORRECT
function fetchFlightData(airportCode) { }
function renderFlightList(flights) { }
function formatTimeZ(date) { }
function isValidCid(cid) { }
function handleRefreshClick(event) { }
function initializeMap(containerId) { }

// ❌ WRONG
function data() { }        // Unclear action
function process() { }     // Unclear what
function doStuff() { }     // Meaningless
function xyz() { }         // Cryptic
```

### Variables

**Format:** `camelCase`

| Type | Pattern | Example |
|------|---------|---------|
| Array | Plural noun | `flights`, `airports`, `entries` |
| Object | Singular noun | `flight`, `airport`, `entry` |
| DOM element | `{name}El` or `${name}` | `mapEl`, `$flightList` |
| Boolean | `is{State}`, `has{Thing}`, `can{Action}` | `isLoading`, `hasData`, `canEdit` |
| Count | `{thing}Count` or `num{Things}` | `flightCount`, `numRetries` |
| Index | `{thing}Index` or `{thing}Idx` | `currentIndex`, `rowIdx` |
| Callback | `on{Event}` or `{action}Callback` | `onClick`, `successCallback` |
| Timer | `{name}Timer` or `{name}Interval` | `refreshTimer`, `updateInterval` |

```javascript
// ✅ CORRECT
const flights = [];
const selectedFlight = null;
const isLoading = false;
const hasMoreData = true;
const flightCount = flights.length;
const mapEl = document.getElementById('map');
const refreshTimer = setInterval(refresh, 60000);

// ❌ WRONG
const f = [];           // Unclear
const sel = null;       // Abbreviated
const flag = false;     // Meaningless
const x = 0;            // Cryptic
```

### Module Exports Registry

All JavaScript modules should register their public API:

```javascript
// ✅ CORRECT - Clear public interface
const MyModule = (function() {
    'use strict';

    // Private state
    const state = { ... };

    // Private functions
    function privateHelper() { ... }

    // Public API
    return {
        // State access
        getState: () => ({ ...state }),

        // Actions
        initialize,
        refresh,
        clear,

        // Queries
        getFlightById,
        isLoading: () => state.isLoading,
    };
})();
```

---

## Database Naming

### Tables

**Format:** `snake_case`, singular noun, prefixed by module

| Module | Prefix | Example |
|--------|--------|---------|
| TMI | `tmi_` | `tmi_program`, `tmi_entry`, `tmi_advisory` |
| ADL | `adl_` or none | `flight_current`, `flight_history` |
| Reference | `ref_` | `ref_airport`, `ref_airline` |
| Config | `config_` or `p_` | `config_data`, `p_terminal_staffing` |

### Columns

**Format:** `snake_case`

| Type | Pattern | Example |
|------|---------|---------|
| Primary key | `{table}_id` or `id` | `program_id`, `id` |
| Foreign key | `{referenced_table}_id` | `airport_id`, `user_id` |
| Timestamp | `{action}_utc` | `created_utc`, `updated_utc`, `expires_utc` |
| Boolean | `is_{state}` | `is_active`, `is_proposed` |
| Status | `status` | NVARCHAR with CHECK constraint |
| Count | `{thing}_count` | `flight_count`, `delay_count` |
| Code | `{thing}_code` | `airport_code`, `carrier_code` |

```sql
-- ✅ CORRECT
CREATE TABLE tmi_program (
    program_id INT IDENTITY(1,1) PRIMARY KEY,
    airport_code NVARCHAR(4) NOT NULL,
    program_type NVARCHAR(16) NOT NULL,
    status NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    is_active BIT NOT NULL DEFAULT 0,
    flight_count INT NOT NULL DEFAULT 0,
    created_utc DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    expires_utc DATETIME2 NULL
);

-- ❌ WRONG
CREATE TABLE TMIPrograms (
    ID INT,
    apt VARCHAR(4),
    type VARCHAR(16),
    active INT,
    cnt INT,
    created DATETIME
);
```

---

## Environment Variables

**Format:** `SCREAMING_SNAKE_CASE`
**Prefix by service/module**

| Prefix | Service | Example |
|--------|---------|---------|
| `SQL_` | MySQL (PERTI site) | `SQL_HOST`, `SQL_USERNAME` |
| `ADL_` | ADL SQL Server | `ADL_SQL_HOST`, `ADL_SQL_PASSWORD` |
| `TMI_` | TMI SQL Server | `TMI_SQL_HOST` |
| `SWIM_` | SWIM API | `SWIM_API_KEY`, `SWIM_DB_HOST` |
| `STATS_` | Statistics DB | `STATS_SQL_HOST` |
| `GIS_` | PostGIS | `GIS_HOST`, `GIS_PASSWORD` |
| `DISCORD_` | Discord bot | `DISCORD_TOKEN`, `DISCORD_GUILD_ID` |
| `VATSIM_` | VATSIM OAuth | `VATSIM_CLIENT_ID`, `VATSIM_SECRET` |

```bash
# ✅ CORRECT
SQL_HOST=vatcscc-perti.mysql.database.azure.com
SQL_USERNAME=perti_api_user
SQL_PASSWORD=*****
SQL_DATABASE=perti_site

ADL_SQL_HOST=vatsim.database.windows.net
ADL_SQL_USERNAME=adl_api_user

SWIM_API_VERSION=1.0
SWIM_RATE_LIMIT=100

# ❌ WRONG
host=...              # No prefix
dbpass=...            # Unclear which DB
SQLPASSWORD=...       # Missing underscore
```

---

## Global Variable Registry

### PHP Globals (defined in load/*.php)

| Variable | Type | Defined In | Purpose |
|----------|------|------------|---------|
| `$conn_sqli` | mysqli | connect.php | MySQL connection |
| `$conn_adl` | resource | connect.php | VATSIM_ADL connection |
| `$conn_tmi` | resource | connect.php | VATSIM_TMI connection |
| `$conn_ref` | resource | connect.php | VATSIM_REF connection |
| `$conn_swim` | resource | connect.php | SWIM_API connection |
| `$conn_gis` | resource | connect.php | PostGIS connection |
| `$SWIM_CORS_ORIGINS` | array | swim_config.php | Allowed CORS origins |

### JavaScript Globals (defined in assets/js/lib/*.js)

| Variable | Type | Defined In | Purpose |
|----------|------|------------|---------|
| `PERTIDateTime` | object | lib/datetime.js | Date/time formatting |
| `PERTILogger` | object | lib/logger.js | Conditional logging |
| `PERTIColors` | object | lib/colors.js | Color palette |

### JavaScript Page Modules

| Variable | Pages | Purpose |
|----------|-------|---------|
| `GDTModule` | gdt.php | Ground Delay Table |
| `NODDisplay` | nod.php | Network Ops Display |
| `TMIPublish` | tmi-publish.php | TMI publishing |
| `DemandModule` | demand.php | Demand charts |
| `SplitsModule` | splits.php | Sector splits |
| `RerouteModule` | reroute.php | Reroute management |

---

## Quick Reference

### Naming At-a-Glance

| Element | PHP | JavaScript | SQL |
|---------|-----|------------|-----|
| Class/Module | `PascalCase` | `PascalCase` | N/A |
| Function | `snake_case` | `camelCase` | N/A |
| Variable | `$snake_case` | `camelCase` | `snake_case` |
| Constant | `SCREAMING_SNAKE` | `SCREAMING_SNAKE` | N/A |
| Table | N/A | N/A | `snake_case` |
| Column | N/A | N/A | `snake_case` |
| Env var | `SCREAMING_SNAKE` | N/A | N/A |
| Boolean | `$is_`, `$has_` | `is`, `has`, `can` | `is_` |

### Anti-Patterns to Avoid

```php
// ❌ Single-letter variables (except loop counters)
$c = getConnection();
$d = getData();

// ❌ Ambiguous abbreviations
$cfg = loadConfig();      // Use $config
$mgr = new Manager();     // Use $manager
$usr = getUser();         // Use $user

// ❌ Meaningless names
$data = fetchData();      // What data?
$result = process();      // What result?
$temp = calculate();      // Temporary what?

// ❌ Hungarian notation (outdated)
$strName = 'John';        // Use $name
$arrFlights = [];         // Use $flights
$intCount = 0;            // Use $count
$boolIsActive = true;     // Use $isActive
```

---

*Last Updated: February 1, 2026*
