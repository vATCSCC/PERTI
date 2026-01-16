# VATSIM SWIM (System Wide Information Management)

> Centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem.

[![Status](https://img.shields.io/badge/status-phase_2_active-yellow)]()
[![Version](https://img.shields.io/badge/api_version-1.0-blue)]()
[![Cost](https://img.shields.io/badge/cost-$5/mo-brightgreen)]()

## ✅ Current Status

**Phase 0: Infrastructure** - COMPLETE  
**Phase 1: API Standards & Documentation** - COMPLETE  
**Phase 2: Real-Time Distribution** - IN PROGRESS (60%)  
**Phase 3: Partner Integrations** - PENDING

The SWIM API is operational with dedicated infrastructure:
- `SWIM_API` database deployed (Azure SQL Basic, $5/month fixed)
- 2-minute sync from ADL daemon (~2,000 flights)
- All REST endpoints functional with authentication
- FIXM/TFMS field naming standards implemented
- `?format=fixm` parameter for FIXM 4.3.0 aligned field names
- Track and metering ingest endpoints ready
- **NEW:** WebSocket server components ready for testing

---

## Quick Links

| Document | Description |
|----------|-------------|
| [OpenAPI Spec](./openapi.yaml) | REST API specification (Swagger) |
| [Swagger UI](./index.html) | Interactive documentation |
| [Design Document](./VATSIM_SWIM_Design_Document_v1.md) | Full architecture |
| [Phase 2 Design](./SWIM_Phase2_RealTime_Design.md) | WebSocket design |
| [Field Migration](./VATSIM_SWIM_API_Field_Migration.md) | FIXM-aligned field names |
| [Implementation Tracker](./SWIM_TODO.md) | Current tasks |
| [Aviation Standards](./Aviation_Data_Standards_Cross_Reference.md) | FIXM/TFMS reference |

---

## Architecture

```
┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
│    VATSIM_ADL       │      │     SWIM_API        │      │    Public API       │
│  (Serverless $$$)   │─────▶│   (Basic $5/mo)     │─────▶│    REST + WebSocket │
│  Internal only      │ 2min │  Dedicated for API  │      │                     │
└─────────────────────┘      └─────────────────────┘      └─────────────────────┘
                                                                    │
                         ┌──────────────────────────────────────────┤
                         │                                          │
                    ┌────▼────┐  ┌────────┐  ┌────────┐  ┌─────────▼┐
                    │   CRC   │  │ vNAS   │  │SimAware│  │  vPilot  │
                    └─────────┘  └────────┘  └────────┘  └──────────┘
```

**Key Principle:** Public API traffic NEVER hits VATSIM_ADL directly. Fixed $5/mo cost regardless of traffic.

---

## REST API Endpoints

### Base URL
```
https://perti.vatcscc.org/api/swim/v1
```

### Authentication
```http
Authorization: Bearer {api_key}
```

### Available Endpoints

| Method | Endpoint | Description | Status |
|--------|----------|-------------|--------|
| GET | `/` | API info (no auth) | ✅ |
| GET | `/flights` | List flights with filters | ✅ |
| GET | `/flight` | Single flight by GUFI/flight_uid | ✅ |
| GET | `/positions` | Bulk positions (GeoJSON) | ✅ |
| GET | `/tmi/programs` | Active TMI programs | ✅ |
| GET | `/tmi/controlled` | TMI-controlled flights | ✅ |
| POST | `/ingest/adl` | Ingest ADL data | ✅ |
| POST | `/ingest/track` | Ingest track data | ✅ |
| POST | `/ingest/metering` | Ingest metering data | ✅ |

### Example Usage

```bash
# Get API info (no auth required)
curl https://perti.vatcscc.org/api/swim/v1/

# List active flights
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flights?status=active&per_page=10"

# Get flights with FIXM field names
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flights?format=fixm"
```

---

## WebSocket API (Phase 2 - Testing)

### Connection URL
```
wss://perti.vatcscc.org/api/swim/v1/ws?api_key={api_key}
```

### Event Types

| Event | Description |
|-------|-------------|
| `flight.position` | Single position update |
| `flight.positions` | Batched position updates |
| `flight.created` | New flight filed |
| `flight.departed` | OFF time detected |
| `flight.arrived` | IN time detected |
| `flight.deleted` | Pilot disconnected |
| `tmi.issued` | New GS/GDP created |
| `tmi.released` | TMI ended |
| `system.heartbeat` | Server heartbeat (30s) |

### JavaScript Client

```javascript
const swim = new SWIMWebSocket('your-api-key');
await swim.connect();

// Subscribe to events for JFK
swim.subscribe(['flight.position', 'flight.departed'], {
    airports: ['KJFK']
});

// Handle events
swim.on('flight.position', (data) => {
    console.log(`${data.callsign} at ${data.altitude_ft}ft`);
});

swim.on('flight.departed', (data) => {
    console.log(`${data.callsign} departed ${data.dep}`);
});
```

### Subscription Filters

```javascript
swim.subscribe(['flight.position'], {
    // Filter by airports (dep OR arr)
    airports: ['KJFK', 'KLAX'],
    
    // Filter by ARTCC
    artccs: ['ZNY', 'ZLA'],
    
    // Filter by callsign prefix
    callsign_prefix: ['AAL', 'UAL'],
    
    // Filter by bounding box
    bbox: {
        north: 42.0,
        south: 40.0,
        east: -72.0,
        west: -75.0
    }
});
```

See [Phase 2 Design Document](./SWIM_Phase2_RealTime_Design.md) for complete WebSocket specification.

---

## Data Standards

SWIM API uses aviation industry standards for field naming:

| Standard | Use | Reference |
|----------|-----|-----------|
| **FIXM 4.3.0** | Primary field names | [fixm.aero](https://fixm.aero) |
| **TFMS** | Abbreviations (EDCT, EOBT, etc.) | FAA TFMDI ICD |
| **vATCSCC** | Extension namespace for VATSIM-specific fields | `vATCSCC:pilotCid` |

See [VATSIM_SWIM_API_Field_Migration.md](./VATSIM_SWIM_API_Field_Migration.md) for complete field mapping.

---

## API Key Tiers

| Tier | REST Rate | WebSocket Conn | Write Access |
|------|-----------|----------------|--------------|
| System | 10,000/min | Unlimited | Yes |
| Partner | 1,000/min | 500 | Limited |
| Developer | 100/min | 50 | No |
| Public | 30/min | 5 | No |

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

## Cost Structure

| Service | Cost | Notes |
|---------|------|-------|
| Azure SQL Basic (SWIM_API) | **$5/mo** | Fixed, unlimited queries |
| WebSocket (Ratchet) | **$0** | Self-hosted on existing App Service |
| Azure Storage (archives) | ~$2/mo | Optional |
| **TOTAL** | **~$5-7/mo** | |

---

## Setup (Developers)

### Install Dependencies

```bash
cd /path/to/PERTI
composer install
```

### Start WebSocket Server (Development)

```bash
php scripts/swim_ws_server.php --debug
```

### Test Connection

```bash
# Using wscat
wscat -c "ws://localhost:8080?api_key=test-key"

# Send subscribe message
{"action":"subscribe","channels":["flight.*"]}
```

---

## Related Documentation

- [Phase 2 Design](./SWIM_Phase2_RealTime_Design.md) - WebSocket architecture
- [Design Document](./VATSIM_SWIM_Design_Document_v1.md) - Complete REST architecture
- [Field Migration](./VATSIM_SWIM_API_Field_Migration.md) - FIXM field alignment
- [Aviation Standards](./Aviation_Data_Standards_Cross_Reference.md) - Industry standards reference
- [Implementation Tracker](./SWIM_TODO.md) - Task status
- [ADL Schema Reference](./ADL_NORMALIZED_SCHEMA_REFERENCE.md) - Source database schema

---

## Contact

- **Email:** dev@vatcscc.org
- **Discord:** vATCSCC Server
- **Repository:** VATSIM PERTI/PERTI
