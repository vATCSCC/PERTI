# Postman Collections & API Documentation

## Canonical Locations

| API | Postman Collection | OpenAPI Spec |
|-----|-------------------|--------------|
| **PERTI API** | `postman/collections/PERTI API.postman_collection.json` | `api-docs/openapi.yaml` |
| **SWIM API** | `docs/swim/VATSIM_SWIM_API.postman_collection.json` | `docs/swim/openapi.yaml` |

## PERTI API

Internal VATSIM PERTI platform API for:
- ADL (Aggregate Demand List) flight data
- TMI (Traffic Management Initiatives) - GDP, Ground Stops
- Demand analysis and forecasting
- Sector configurations
- Route management

**Import into Postman:**
```
postman/collections/PERTI API.postman_collection.json
```

## SWIM API

External System Wide Information Management API for third-party integrations:
- Virtual Airlines (fleet tracking, OOOI, telemetry)
- Facility/vNAS (sector traffic, demand monitoring)
- TMI Coordination (programs, controlled flights)
- Data providers (CRC, EuroScope)

**Import into Postman:**
```
docs/swim/VATSIM_SWIM_API.postman_collection.json
```

**SDK Examples:** `sdk/python/examples/`

## Directory Structure

```
postman/
├── collections/
│   ├── PERTI API.postman_collection.json  # ← PERTI API (active)
│   └── _deprecated/                       # Old duplicates - safe to delete
├── environments/
│   └── SWIM_Development.postman_environment.json
├── globals/
│   └── workspace.postman_globals.json
├── specs/
│   ├── README.md                          # Points to canonical specs
│   └── _deprecated/                       # Postman stub files - safe to delete
└── README.md                              # This file

docs/swim/
├── openapi.yaml                           # ← SWIM API OpenAPI spec (active)
├── VATSIM_SWIM_API.postman_collection.json # ← SWIM API Postman collection (active)
└── ...

api-docs/
├── openapi.yaml                           # ← PERTI API OpenAPI spec (active)
└── index.php                              # Swagger UI viewer
```

## Environments

| Environment | Description |
|-------------|-------------|
| `SWIM_Development` | Development/testing environment variables |

## Deprecated Files

Duplicate/stub files have been moved to `_deprecated/` folders and can be safely deleted:
- `collections/_deprecated/` - Duplicate Postman collections
- `specs/_deprecated/` - Postman-generated stub OpenAPI specs
