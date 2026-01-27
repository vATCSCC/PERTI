# VATSWIM (System Wide Information Management)

> Centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem.

[![Status](https://img.shields.io/badge/status-production-brightgreen)]()
[![Phase](https://img.shields.io/badge/phase_3-in_progress-yellow)]()
[![Cost](https://img.shields.io/badge/cost-$5/mo-brightgreen)]()
[![FIXM](https://img.shields.io/badge/FIXM-migration_active-orange)]()

> **FIXM Migration (2026-01-27):** Transitioning to FIXM-aligned column names (`actual_off_block_time`
> instead of `out_utc`, etc.). Both column sets populated during 30-day transition. Use `?format=fixm`
> for FIXM output. See [VATSWIM_FIXM_Field_Mapping.md](VATSWIM_FIXM_Field_Mapping.md).

## ✅ Status: Production Ready

| Phase | Status |
|-------|--------|
| Phase 0: Infrastructure | ✅ Complete |
| Phase 1: REST API | ✅ Complete |
| Phase 2: WebSocket | ✅ Complete |
| Phase 3: SDKs & Integrations | 🔨 Python + AOC Telemetry done |

**Live Features:**
- REST API with FIXM field naming
- **Multiple output formats:** JSON, FIXM, XML, GeoJSON, CSV, KML, NDJSON
- Real-time WebSocket events
- Database-backed authentication
- Tier-based rate limits (100-30,000 req/min)
- Response caching, ETags, gzip compression
- Python SDK
- **NEW:** AOC Telemetry Ingest (vertical rate, OOOI times)

---

## Quick Start

### REST API

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
     "https://perti.vatcscc.org/api/swim/v1/flights?status=active"
```

### WebSocket (Python)

```bash
pip install -e sdk/python
python examples/basic_example.py YOUR_API_KEY
```

```python
from swim_client import SWIMClient

client = SWIMClient('your-api-key')

@client.on('flight.departed')
def on_departure(data, timestamp):
    print(f"{data.callsign} departed {data.dep}")

client.subscribe(['flight.departed', 'flight.arrived'])
client.run()
```

### WebSocket (JavaScript)

```javascript
const swim = new SWIMWebSocket('your-api-key');
await swim.connect();

swim.subscribe(['flight.departed'], { airports: ['KJFK'] });

swim.on('flight.departed', (data) => {
    console.log(`${data.callsign} departed ${data.dep}`);
});
```

---

## 🆕 AOC Telemetry Integration

Virtual airlines can push flight sim telemetry via the ingest API:

### Push Telemetry with Vertical Rate

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/adl" \
  -H "Authorization: Bearer swim_par_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "flights": [{
      "callsign": "DLH401",
      "dept_icao": "KJFK",
      "dest_icao": "EDDF",
      "altitude_ft": 35000,
      "groundspeed_kts": 485,
      "vertical_rate_fpm": -1800
    }]
  }'
```

### Push OOOI Times

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/adl" \
  -H "Authorization: Bearer swim_par_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "flights": [{
      "callsign": "DLH401",
      "dept_icao": "KJFK",
      "dest_icao": "EDDF",
      "out_utc": "2026-01-16T14:30:00Z",
      "off_utc": "2026-01-16T14:45:00Z"
    }]
  }'
```

### Supported Telemetry Fields

| Field | Type | Description |
|-------|------|-------------|
| `vertical_rate_fpm` | INT | Climb/descent rate (+ = climb, - = descend) |
| `out_utc` | DATETIME | OOOI - Gate departure |
| `off_utc` | DATETIME | OOOI - Wheels up |
| `on_utc` | DATETIME | OOOI - Wheels down |
| `in_utc` | DATETIME | OOOI - Gate arrival |
| `eta_utc` | DATETIME | FMC-calculated ETA |
| `etd_utc` | DATETIME | Expected departure |

---

## API Endpoints

**Base URL:** `https://perti.vatcscc.org/api/swim/v1`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/` | GET | API info (no auth) |
| `/flights` | GET | List flights |
| `/flight` | GET | Single flight by GUFI |
| `/positions` | GET | Bulk positions (GeoJSON) |
| `/tmi/programs` | GET | Active TMI programs (GS/GDP) |
| `/tmi/controlled` | GET | TMI-controlled flights |
| `/tmi/reroutes` | GET | TMI reroute definitions |
| `/tmi/gs` | GET/POST | Ground Stop programs |
| `/tmi/gs/{id}` | GET | Ground Stop details |
| `/tmi/gs/{id}/flights` | GET | Flights affected by GS |
| `/tmi/gs/{id}/model` | POST | Model GS impact |
| `/tmi/gs/{id}/activate` | POST | Activate GS program |
| `/tmi/gdp` | GET/POST | Ground Delay Programs |
| `/tmi/gdp/{id}/flights` | GET | Flights in GDP |
| `/tmi/gdp/{id}/slots` | GET | GDP slot allocation |
| `/tmi/mit` | GET | Miles-In-Trail restrictions |
| `/tmi/minit` | GET | Minutes-In-Trail restrictions |
| `/tmi/afp` | GET | Airspace Flow Programs |
| `/metering/{airport}` | GET | TBFM metering data for airport |
| `/metering/{airport}/sequence` | GET | Arrival sequence list |
| `/jatoc/incidents` | GET | JATOC incident records |
| `/splits/presets` | GET | Runway configuration presets |
| `/fea` | GET | Flow Evaluation Areas |
| `/ingest/adl` | POST | Ingest flight data (write access) |
| `/ingest/track` | POST | High-freq position updates (write access) |
| `/ingest/metering` | POST | TBFM metering data (write access) |
| `/ws` | WS | Real-time WebSocket |

---

## WebSocket Events

| Event | Description |
|-------|-------------|
| `flight.created` | New pilot connected |
| `flight.departed` | Wheels up |
| `flight.arrived` | Wheels down |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Position batch |
| `tmi.issued` | GS/GDP issued |
| `tmi.released` | TMI ended |
| `system.heartbeat` | Server keepalive |

### Filters

```json
{
    "airports": ["KJFK", "KLAX"],
    "artccs": ["ZNY"],
    "callsign_prefix": ["AAL", "UAL"]
}
```

---

## API Key Tiers

| Tier | Connections | Rate Limit | Write Access |
|------|-------------|------------|--------------|
| public | 5 | 100/min | No |
| developer | 50 | 300/min | No |
| partner | 500 | 3,000/min | Yes |
| system | 10,000 | 30,000/min | Yes |

**Request a key:** Contact dev@vatcscc.org

---

## SDKs

| Language | Status | Location |
|----------|--------|----------|
| Python | ✅ Complete | `sdk/python/` |
| JavaScript | ✅ Built-in | `api/swim/v1/ws/swim-ws-client.js` |
| C# | ⏳ Planned | — |
| Java | ⏳ Planned | — |

---

## Documentation

| Document | Description |
|----------|-------------|
| [API Documentation](/swim-doc?file=VATSWIM_API_Documentation) | Full API reference |
| [Implementation Tracker](/swim-doc?file=SWIM_TODO) | Current status |
| [AOC Telemetry Transition](/swim-doc?file=SWIM_Session_Transition_20260116_AOCTelemetry) | Latest changes |
| [OpenAPI Spec](/docs/swim/openapi.yaml) | REST API spec (import into Postman) |
| [Swagger UI](/docs/swim/) | Interactive docs |

---

## Cost

| Component | Monthly |
|-----------|---------|
| Azure SQL Basic | $5 |
| WebSocket | $0 |
| **Total** | **$5** |

---

## Contact

- **Email:** dev@vatcscc.org
- **Discord:** vATCSCC Server
