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

---

## See Also

- [[Contributing]] - Contribution guidelines
- [[Testing]] - Testing practices
