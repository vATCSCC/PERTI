# VATSIM SWIM (System Wide Information Management)

> Centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem.

[![Status](https://img.shields.io/badge/status-production-brightgreen)]()
[![Phase](https://img.shields.io/badge/phase_2-complete-brightgreen)]()
[![Cost](https://img.shields.io/badge/cost-$5/mo-brightgreen)]()

## ✅ Status: Production Ready

| Phase | Status |
|-------|--------|
| Phase 0: Infrastructure | ✅ Complete |
| Phase 1: REST API | ✅ Complete |
| Phase 2: WebSocket | ✅ Complete |
| Phase 3: SDKs | 🔨 Python done |

**Live Features:**
- REST API with FIXM field naming
- Real-time WebSocket events
- Database-backed authentication
- Tier-based rate limits
- Python SDK

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

## API Endpoints

**Base URL:** `https://perti.vatcscc.org/api/swim/v1`

| Endpoint | Description |
|----------|-------------|
| `GET /` | API info (no auth) |
| `GET /flights` | List flights |
| `GET /flight` | Single flight by GUFI |
| `GET /positions` | Bulk positions (GeoJSON) |
| `GET /tmi/programs` | Active TMI programs |
| `WS /ws` | Real-time WebSocket |

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

| Tier | Connections | Rate Limit |
|------|-------------|------------|
| public | 5 | 30/min |
| developer | 50 | 100/min |
| partner | 500 | 1000/min |
| system | 10,000 | 10000/min |

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
| [Implementation Tracker](./SWIM_TODO.md) | Current status |
| [Transition Summary](./SWIM_Phase2_Phase3_Transition.md) | Recent changes |
| [OpenAPI Spec](./openapi.yaml) | REST API spec |
| [Swagger UI](./index.html) | Interactive docs |

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
