# PERTI API Documentation

OpenAPI specification for the internal PERTI platform API.

## Files

| File | Description |
|------|-------------|
| `openapi.yaml` | OpenAPI 3.0 specification |
| `index.php` | Swagger UI viewer |

## Import into Postman

Import `openapi.yaml` directly into Postman to generate a collection:
1. Open Postman → **Import**
2. Select `api-docs/openapi.yaml`
3. Postman generates the collection automatically

## SWIM API

For the external SWIM API (used by third-party integrations), see:
```
docs/swim/openapi.yaml
```

## API Overview

The PERTI API provides:
- **ADL** - Aggregate Demand List (real-time flight tracking)
- **TMI** - Traffic Management Initiatives (GDP, Ground Stops)
- **Demand** - Traffic demand analysis and forecasting
- **Splits** - Sector configurations
- **Routes** - Route management
- **NOD** - NAS Operations Dashboard
- **Crossings** - Boundary crossing analysis

## Base URL

```
https://perti.vatcscc.org/api
```

## Authentication

Most read-only endpoints are public. Management endpoints require VATSIM session authentication via cookie.
