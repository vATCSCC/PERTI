# VATSIM SWIM (System Wide Information Management)

> Centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem.

**Status:** Phase 1 - Foundation (In Progress)  
**Version:** 1.1.0  
**Last Updated:** 2026-01-15

---

## Quick Links

- [Design Document](./VATSIM_SWIM_Design_Document_v1.md) - Full architecture and specification
- [Implementation Tracker](./SWIM_TODO.md) - What's done, what's next
- [System Status](../STATUS.md) - PERTI system dashboard

---

## What is SWIM?

SWIM provides a **single source of truth** for VATSIM flight data, enabling:

- ✅ Consistent TMI (Ground Stop/GDP) application across all facilities
- ✅ Real-time position and track data sharing
- ✅ Unified flight record with data from all sources
- ✅ Metering coordination between SimTraffic and ATC clients (planned)
- ✅ Enhanced ATC client displays (TMI status in tags) (planned)

---

## Current Implementation Status

### ✅ Completed (Phase 1)

| Component | Description |
|-----------|-------------|
| **API Endpoints** | 5 endpoints ready for testing |
| **Authentication** | Bearer token with tiered API keys |
| **Database Schema** | 5 tables + 3 stored procedures |
| **Documentation** | Design doc, README, TODO tracker |

### ⏳ Pending (Next Sprint)

| Component | Description |
|-----------|-------------|
| **Deploy Migration** | Run `001_swim_tables.sql` on Azure |
| **Single Flight API** | GET by GUFI |
| **API Reference** | OpenAPI/Swagger documentation |

### 📅 Future (Phase 2+)

| Component | Description |
|-----------|-------------|
| **WebSocket** | Real-time distribution |
| **vNAS Integration** | Track data exchange |
| **CRC Plugin** | Bidirectional sync |

---

## API Overview

### Base URL
```
https://perti.vatcscc.org/api/swim/v1
```

### Authentication
```http
Authorization: Bearer {api_key}
```

### Endpoints

| Method | Endpoint | Status | Description |
|--------|----------|--------|-------------|
| GET | `/` | ✅ | API info (no auth) |
| GET | `/flights` | ✅ | List flights with filters |
| GET | `/flights/{gufi}` | ⏳ | Get single flight |
| GET | `/positions` | ✅ | Bulk positions (GeoJSON) |
| GET | `/tmi/programs` | ✅ | Active GS/GDP programs |
| POST | `/ingest/adl` | ✅ | Ingest ADL data |

### Example Requests

```bash
# API Info (no auth required)
curl https://perti.vatcscc.org/api/swim/v1/

# List active flights (requires auth)
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flights?status=active&per_page=10"

# Flights by destination
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flights?dest_icao=KJFK,KLGA,KEWR"

# GeoJSON positions for an ARTCC
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/positions?artcc=ZNY"

# Active TMI programs
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/programs"
```

---

## File Structure

```
VATSIM PERTI/PERTI/
├── api/swim/v1/
│   ├── auth.php              # Authentication middleware
│   ├── index.php             # API router/info
│   ├── flights.php           # Flight list endpoint
│   ├── positions.php         # GeoJSON positions
│   ├── ingest/
│   │   └── adl.php           # ADL data ingest
│   └── tmi/
│       └── programs.php      # TMI programs (GS/GDP)
│
├── database/migrations/swim/
│   └── 001_swim_tables.sql   # Database schema
│
├── docs/swim/
│   ├── README.md             # This file
│   ├── VATSIM_SWIM_Design_Document_v1.md
│   └── SWIM_TODO.md          # Implementation tracker
│
└── load/
    └── swim_config.php       # Configuration
```

---

## Data Sources

SWIM queries data from two databases:

| Data | Database | Table |
|------|----------|-------|
| Flight Data | Azure SQL (VATSIM_ADL) | `adl_flights` |
| Ground Stops | MySQL (PERTI) | `tmi_ground_stops` |
| GDP Programs | Azure SQL (VATSIM_ADL) | `gdp_log` |
| Airport Info | Azure SQL (VATSIM_ADL) | `apts` |

---

## GUFI Format

Globally Unique Flight Identifier:
```
VAT-YYYYMMDD-CALLSIGN-DEPT-DEST
```

Example: `VAT-20260115-UAL123-KJFK-KLAX`

---

## API Key Tiers

| Tier | Prefix | Rate Limit | Write Access |
|------|--------|-----------|--------------|
| System | `swim_sys_` | 10,000/min | Yes |
| Partner | `swim_par_` | 1,000/min | Limited |
| Developer | `swim_dev_` | 100/min | No |
| Public | `swim_pub_` | 30/min | No |

---

## Next Steps

1. **Deploy Database Migration** - Run `001_swim_tables.sql` on Azure SQL
2. **Test API Endpoints** - Verify functionality with Postman/curl
3. **Create Single Flight Endpoint** - `GET /flights/{gufi}`
4. **API Documentation** - Generate OpenAPI spec
5. **Integrate with ADL Refresh** - Publish updates on flight changes

---

## Contact

- **Project:** vATCSCC PERTI
- **Email:** dev@vatcscc.org
- **Discord:** vATCSCC Server

---

*Part of the vATCSCC/PERTI project*
