# Code Style

Coding standards for PERTI contributions.

---

## PHP

Follow PSR-12 coding standard.

### Naming

- Classes: `PascalCase`
- Methods/functions: `camelCase`
- Variables: `$camelCase`
- Constants: `UPPER_SNAKE_CASE`

### Example

```php
<?php

class FlightProcessor
{
    private const MAX_BATCH_SIZE = 100;

    public function processFlights(array $flights): array
    {
        $results = [];
        foreach ($flights as $flight) {
            $results[] = $this->processSingleFlight($flight);
        }
        return $results;
    }
}
```

### PERTI_MYSQL_ONLY Pattern

Endpoints that only need the `perti_site` MySQL database can skip the five eager Azure SQL connections by defining `PERTI_MYSQL_ONLY` before including `connect.php`. This saves roughly 500-1000ms per request.

```php
// MySQL-only endpoint (skips ~500-1000ms of Azure SQL connections)
include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
```

**Before applying this flag**, always verify the file does not use any Azure SQL connections:

```bash
grep -n '$conn_adl\|$conn_tmi\|$conn_swim\|$conn_ref\|$conn_gis' path/to/file.php
```

If any of those variables appear, the file needs the full connection set and must **not** use this flag.

### PHP Gotchas

- **Never use `?>` inside comments.** The sequence `?>` terminates PHP mode even within a `//` comment. For example, `// some text ?> rest` causes `rest` to be emitted as raw HTML.
- **Start sessions before including `connect.php`.** The connect file may output whitespace that sends headers, preventing a later `session_start()` from working.
- **Use `sqlsrv_*` functions for Azure SQL, not PDO.** Azure SQL connections use the `sqlsrv` extension directly (`sqlsrv_query()`, `sqlsrv_fetch_array()`). Do not mix them with PDO methods.
- **Use lazy-loaded getters for Azure connections.** Call `get_conn_adl()`, `get_conn_tmi()`, `get_conn_swim()`, `get_conn_ref()`, and `get_conn_gis()` instead of referencing global variables directly.

---

## JavaScript

Use ES6+ syntax.

### Naming

- Functions: `camelCase`
- Variables: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Classes: `PascalCase`

### Example

```javascript
const MAX_RETRIES = 3;

function fetchFlightData(callsign) {
    return fetch(`/api/adl/flight.php?callsign=${callsign}`)
        .then(response => response.json());
}
```

### API Parallelization

Use `Promise.all()` for independent concurrent API calls. This is especially important on pages that load multiple data sources at once (plan.js loads 16 calls in parallel, sheet.js loads 5, review.js loads 3).

```javascript
// Good - parallel loading
const [configs, staffing, constraints] = await Promise.all([
    fetch('/api/data/plans/configs.php?p_id=' + planId).then(r => r.json()),
    fetch('/api/data/plans/term_staffing.php?p_id=' + planId).then(r => r.json()),
    fetch('/api/data/plans/term_constraints.php?p_id=' + planId).then(r => r.json()),
]);

// Bad - sequential loading
const configs = await fetch('/api/data/plans/configs.php?p_id=' + planId).then(r => r.json());
const staffing = await fetch('/api/data/plans/term_staffing.php?p_id=' + planId).then(r => r.json());
const constraints = await fetch('/api/data/plans/term_constraints.php?p_id=' + planId).then(r => r.json());
```

---

## Internationalization (i18n)

All new user-facing strings in JavaScript **must** use `PERTII18n.t()`. Never hardcode English strings directly in JS source files.

### Translation Patterns

```javascript
// Good - use i18n keys
PERTIDialog.success('dialog.success.saved');
PERTII18n.t('error.loadFailed', { resource: 'flights' });
PERTII18n.tp('flight', count);  // "1 flight" or "5 flights"

// Bad - hardcoded strings
Swal.fire({ title: 'Success', text: 'Data saved' });
alert('Failed to load flights');
```

### Dialog Wrapper

Use the `PERTIDialog` wrapper instead of calling `Swal.fire()` directly. It automatically resolves i18n keys and provides a consistent look.

```javascript
PERTIDialog.success('dialog.success.saved');
PERTIDialog.error('common.error', 'error.loadFailed', { resource: 'flights' });
PERTIDialog.confirm('dialog.confirmDelete.title', 'dialog.confirmDelete.text');
PERTIDialog.confirmDanger('dialog.confirmDelete.title', 'dialog.confirmDelete.text');
PERTIDialog.loading('common.loading');
PERTIDialog.toast('common.copied', 'success');
```

### Adding New Translation Keys

Add keys to `assets/locales/en-US.json` using a nested structure. Keys auto-flatten to dot notation at runtime.

```json
{
  "myFeature": {
    "title": "Feature Title",
    "error": {
      "loadFailed": "Failed to load {resource}"
    }
  }
}
```

The above produces the keys `myFeature.title` and `myFeature.error.loadFailed`.

---

## SQL

- Keywords: `UPPERCASE`
- Table/column names: `snake_case`
- Use meaningful aliases

### Example

```sql
SELECT
    f.callsign,
    f.departure AS origin,
    f.arrival AS destination
FROM adl_flights f
WHERE f.phase = 'cruise'
ORDER BY f.eta;
```

### Normalized Flight Tables

New code should use the 8-table normalized flight architecture rather than the legacy monolithic `adl_flights` table. All tables are keyed on `flight_uid bigint`:

| Table | Purpose |
|-------|---------|
| `adl_flight_core` | Main flight record (callsign, phase, active flag) |
| `adl_flight_plan` | Filed flight plan (route, airports, aircraft type) |
| `adl_flight_position` | Current position (lat, lon, altitude, speed) |
| `adl_flight_times` | All timing data (ETD, ETA, OOOI, EDCT) |
| `adl_flight_tmi` | TMI control assignments (GDP, GS, reroute) |
| `adl_flight_aircraft` | Aircraft performance data (weight class, engine type) |
| `adl_flight_trajectory` | Position history |
| `adl_flight_waypoints` | Parsed route waypoints |

The legacy `adl_flights` table still exists and is used by some older features, but all new queries should join across the normalized tables instead.

---

## See Also

- [[Contributing]] - Contribution guidelines
- [[Testing]] - Testing practices
