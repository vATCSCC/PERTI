# VATSIM SWIM (System Wide Information Management)

> Centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem.

[![Status](https://img.shields.io/badge/status-phase_0_infrastructure-orange)]()
[![Version](https://img.shields.io/badge/api_version-1.0-blue)]()
[![Cost](https://img.shields.io/badge/target_cost-$7--24/mo-green)]()

## ⚠️ Current Status

**Phase 0: Infrastructure Migration Required**

The API is functional but currently queries VATSIM_ADL Serverless directly, which would be expensive under load. Before public release, we need to:

1. Create dedicated `SWIM_API` database (Azure SQL Basic, $5/month)
2. Implement sync procedure from VATSIM_ADL
3. Update API endpoints to use the dedicated database

See [SWIM_TODO.md](./SWIM_TODO.md) for detailed migration tasks.

---

## Quick Links

- [Design Document](./VATSIM_SWIM_Design_Document_v1.md) - Full architecture & specifications
- [Implementation Tracker](./SWIM_TODO.md) - Current status & tasks
- [Normalized Schema](./ADL_NORMALIZED_SCHEMA_REFERENCE.md) - Source data schema
- [API Endpoint](https://perti.vatcscc.org/api/swim/v1/) - Live API

---

## Architecture

```
┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
│    VATSIM_ADL       │      │     SWIM_API        │      │    Public API       │
│  (Serverless)       │─────▶│   (Basic $5/mo)     │─────▶│    Endpoints        │
│  Internal only      │ sync │  Dedicated for API  │      │                     │
└─────────────────────┘ 15s  └─────────────────────┘      └─────────────────────┘
```

**Key Principle:** Public API traffic NEVER hits VATSIM_ADL directly. This keeps costs predictable (~$7-24/month vs $500-7,500+/month).

---

## API Endpoints

### Base URL
```
https://perti.vatcscc.org/api/swim/v1
```

### Authentication
```http
Authorization: Bearer {api_key}
```

### Available Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | API info (no auth) |
| GET | `/flights` | List flights with filters |
| GET | `/flight` | Single flight by GUFI/flight_uid |
| GET | `/positions` | Bulk positions (GeoJSON) |
| GET | `/tmi/programs` | Active TMI programs |
| GET | `/tmi/controlled` | TMI-controlled flights |

### Example Usage

```bash
# Get API info (no auth required)
curl https://perti.vatcscc.org/api/swim/v1/

# List active flights
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flights?status=active&per_page=10"

# Get single flight
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flight?flight_uid=12345"

# Get positions as GeoJSON
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/positions?artcc=ZNY"
```

---

## Cost Structure

### Target Infrastructure (~$7-24/month)

| Service | Purpose | Cost |
|---------|---------|------|
| Azure SQL Basic | SWIM_API database | $5/mo |
| Azure Redis (optional) | High-traffic cache | $16/mo |
| Azure Storage | Archives | $2-3/mo |
| **TOTAL** | | **$7-24/mo** |

### Why Not Query VATSIM_ADL Directly?

VATSIM_ADL uses Azure SQL Serverless which charges per vCore-second:

| API Traffic | Direct VATSIM_ADL | Dedicated SWIM_API |
|-------------|-------------------|-------------------|
| 10K req/day | ~$15-45/mo | **$5/mo** |
| 100K req/day | ~$150-450/mo | **$5/mo** |
| 1M req/day | ~$1,500-4,500/mo | **$5/mo** |

---

## API Key Tiers

| Tier | Rate Limit | Write Access | Use Case |
|------|-----------|--------------|----------|
| System | 10,000/min | Yes | Internal services |
| Partner | 1,000/min | Limited | vNAS, CRC, SimTraffic |
| Developer | 100/min | No | Third-party apps |
| Public | 30/min | No | General access |

---

## Data Sources

| Data | Source | Update Frequency |
|------|--------|-----------------|
| Flight positions | VATSIM API | 15 seconds |
| Flight plans | VATSIM API | On change |
| TMI (GS/GDP) | vATCSCC | Real-time |
| OOOI times | vATCSCC | On detection |
| Aircraft data | ACD reference | Static |

---

## Related Documentation

- [VATSIM_SWIM_Design_Document_v1.md](./VATSIM_SWIM_Design_Document_v1.md) - Complete design specification
- [SWIM_TODO.md](./SWIM_TODO.md) - Implementation tracker
- [ADL_NORMALIZED_SCHEMA_REFERENCE.md](./ADL_NORMALIZED_SCHEMA_REFERENCE.md) - Database schema

---

## Contact

- **Email:** dev@vatcscc.org
- **Discord:** vATCSCC Server
- **Repository:** VATSIM PERTI/PERTI
