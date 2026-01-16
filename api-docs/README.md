# PERTI API Documentation

OpenAPI specification for the internal PERTI platform API.

## Files

| File | Description |
|------|-------------|
| `openapi.yaml` | OpenAPI 3.0 specification |
| `index.php` | Swagger UI viewer |

## Postman Collection

The Postman collection for this API is located at:
```
postman/collections/PERTI API.postman_collection.json
```

## SWIM API

For the external SWIM API (used by third-party integrations), see:
```
docs/swim/
├── openapi.yaml                           # OpenAPI spec
├── VATSIM_SWIM_API.postman_collection.json # Postman collection
└── README.md                              # Documentation
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
