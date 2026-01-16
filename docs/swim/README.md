# VATSIM SWIM (System Wide Information Management)

> Centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem.

[![Status](https://img.shields.io/badge/status-phase_2_live-brightgreen)]()
[![Version](https://img.shields.io/badge/api_version-1.0-blue)]()
[![Cost](https://img.shields.io/badge/cost-$5/mo-brightgreen)]()

## ✅ Current Status

| Phase | Status | Progress |
|-------|--------|----------|
| Phase 0: Infrastructure | ✅ COMPLETE | 100% |
| Phase 1: REST API | ✅ COMPLETE | 100% |
| Phase 2: WebSocket | ✅ LIVE | 95% (rate limits pending) |
| Phase 3: SDKs | 🔨 ACTIVE | Python complete |

**What's Working:**
- REST API: All endpoints operational
- WebSocket: Real-time flight events streaming
- Python SDK: Ready for use
- Database auth: API keys validated against `swim_api_keys`

---

## Quick Links

| Document | Description |
|----------|-------------|
| [OpenAPI Spec](./openapi.yaml) | REST API specification |
| [Swagger UI](./index.html) | Interactive docs |
| [Implementation Tracker](./SWIM_TODO.md) | Current status |
| [Transition Summary](./SWIM_Phase2_Phase3_Transition.md) | Recent changes |
| [Design Document](./VATSIM_SWIM_Design_Document_v1.md) | Full architecture |
| [WebSocket Design](./SWIM_Phase2_RealTime_Design.md) | Real-time design |

---

## Quick Start

### REST API

```bash
# Get API info (no auth)
curl https://perti.vatcscc.org/api/swim/v1/

# List active flights
curl -H "Authorization: Bearer YOUR_API_KEY" \
     "https://perti.vatcscc.org/api/swim/v1/flights?status=active"
```

### WebSocket (Python SDK)

```bash
# Install
cd sdk/python
pip install -e .

# Run example
python examples/basic_example.py YOUR_API_KEY
```

```python
from swim_client import SWIMClient

client = SWIMClient('your-api-key')

@client.on('flight.departed')
def on_departure(data, timestamp):
    print(f"{data.callsign} departed {data.dep}")

@client.on('flight.arrived')
def on_arrival(data, timestamp):
    print(f"{data.callsign} arrived {data.arr}")

client.subscribe(['flight.departed', 'flight.arrived'])
client.run()
```

### WebSocket (JavaScript)

```javascript
const swim = new SWIMWebSocket('your-api-key');
await swim.connect();

swim.subscribe(['flight.departed', 'flight.arrived'], {
    airports: ['KJFK', 'KLAX']
});

swim.on('flight.departed', (data) => {
    console.log(`${data.callsign} departed ${data.dep}`);
});
```

---

## Architecture

```
┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐
│   VATSIM_ADL    │      │    SWIM_API     │      │   Public API    │
│  (Serverless)   │─────▶│  (Basic $5/mo)  │─────▶│  REST + WS      │
│  Internal only  │ 2min │  Fixed cost     │      │                 │
└─────────────────┘      └─────────────────┘      └────────┬────────┘
                                                           │
              ┌────────────────┬───────────────┬───────────┤
              ▼                ▼               ▼           ▼
         ┌────────┐      ┌────────┐      ┌────────┐  ┌────────┐
         │  CRC   │      │  vNAS  │      │SimAware│  │ vPilot │
         └────────┘      └────────┘      └────────┘  └────────┘
```

**Cost principle:** Public API never hits VATSIM_ADL. Fixed $5/mo regardless of traffic.

---

## REST API Endpoints

**Base URL:** `https://perti.vatcscc.org/api/swim/v1`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | API info (no auth) |
| GET | `/flights` | List flights with filters |
| GET | `/flight` | Single flight by GUFI |
| GET | `/positions` | Bulk positions (GeoJSON) |
| GET | `/tmi/programs` | Active TMI programs |
| GET | `/tmi/controlled` | TMI-controlled flights |
| POST | `/ingest/track` | Ingest track data |
| POST | `/ingest/metering` | Ingest metering data |

---

## WebSocket API

**URL:** `wss://perti.vatcscc.org/api/swim/v1/ws?api_key={key}`

### Event Types

| Event | Description |
|-------|-------------|
| `flight.created` | New pilot connected |
| `flight.departed` | Wheels up (OFF time) |
| `flight.arrived` | Wheels down (IN time) |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Batched position updates |
| `tmi.issued` | New GS/GDP issued |
| `tmi.released` | TMI ended |
| `system.heartbeat` | Server keepalive (30s) |

### Subscription Filters

```json
{
    "action": "subscribe",
    "channels": ["flight.departed", "flight.arrived"],
    "filters": {
        "airports": ["KJFK", "KLAX"],
        "artccs": ["ZNY", "ZLA"],
        "callsign_prefix": ["AAL", "UAL"]
    }
}
```

---

## API Keys

Keys stored in `VATSIM_ADL.dbo.swim_api_keys`

| Tier | REST Rate | WS Connections |
|------|-----------|----------------|
| public | 30/min | 5 |
| developer | 100/min | 50 |
| partner | 1000/min | 500 |
| system | 10000/min | Unlimited |

**Request a key:** Contact dev@vatcscc.org

---

## SDKs

### Python (Complete)

Location: `sdk/python/`

```bash
pip install -e sdk/python
```

Features:
- Async WebSocket client
- Auto-reconnect
- Typed event classes
- Subscription filters

Examples:
- `basic_example.py` - Simple events
- `airport_monitor.py` - Track specific airports
- `position_tracker.py` - Flight positions
- `tmi_monitor.py` - Ground Stops, GDPs

### JavaScript (Built-in)

File: `api/swim/v1/ws/swim-ws-client.js`

```html
<script src="https://perti.vatcscc.org/api/swim/v1/ws/swim-ws-client.js"></script>
```

### C# / Java

Coming soon (as needed by consumers)

---

## Cost Summary

| Component | Monthly |
|-----------|---------|
| SWIM_API (Azure SQL Basic) | $5 |
| WebSocket (self-hosted) | $0 |
| **Total** | **$5** |

---

## Files Reference

```
PERTI/
├── api/swim/v1/
│   ├── ws/
│   │   ├── WebSocketServer.php
│   │   ├── ClientConnection.php
│   │   ├── SubscriptionManager.php
│   │   └── swim-ws-client.js
│   ├── flights.php
│   ├── positions.php
│   └── tmi/
├── scripts/
│   ├── swim_ws_server.php
│   ├── swim_ws_events.php
│   └── startup.sh
├── sdk/
│   └── python/
└── docs/swim/
    ├── README.md (this file)
    ├── SWIM_TODO.md
    ├── openapi.yaml
    └── index.html
```

---

## Contact

- **Email:** dev@vatcscc.org
- **Discord:** vATCSCC Server
