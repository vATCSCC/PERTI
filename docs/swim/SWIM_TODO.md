# VATSWIM Implementation Tracker

**Last Updated:** 2026-03-14
**Status:** Phase 4 COMPLETE ✅ (Data Isolation)
**Repository:** `VATSIM PERTI/PERTI/`

---

## Quick Status

| Phase | Status | Progress |
|-------|--------|----------|
| Phase 0: Infrastructure | ✅ COMPLETE | 100% |
| Phase 1: REST API & Docs | ✅ COMPLETE | 100% |
| Phase 2: Real-Time WebSocket | ✅ COMPLETE | 100% |
| Phase 3: SDKs & Integrations | ✅ COMPLETE | 100% |
| Phase 4: Data Isolation | ✅ COMPLETE | 100% |

---

## 🎉 Latest: Data Isolation Complete (Phase 4)

All SWIM API endpoints now query exclusively from the `SWIM_API` database. Three sync daemons (`swim_sync_daemon`, `swim_tmi_sync_daemon`, `refdata_sync_daemon`) keep 25+ mirror tables fresh within the 5 DTU budget. See Phase 4 section below for full details.

## SDKs

Client SDKs now available for all major platforms:

| SDK | Language | Location | Features |
|-----|----------|----------|----------|
| Python | Python 3.8+ | `sdk/python/` | REST + WebSocket, async support |
| C# | .NET 6/7/8 | `sdk/csharp/` | REST + WebSocket, full async |
| Java | Java 11+ | `sdk/java/` | REST + WebSocket, OkHttp/Jackson |
| JavaScript | TS/JS (Node + Browser) | `sdk/javascript/` | REST + WebSocket, full TypeScript |

---

## ✅ Phase 0: Infrastructure (COMPLETE)

| Task | Status |
|------|--------|
| Azure SQL Basic database `SWIM_API` | ✅ |
| `swim_flights` table (75 columns) | ✅ |
| `sp_Swim_BulkUpsert` stored procedure | ✅ |
| ADL daemon sync integration | ✅ |
| `swim_api_keys` table | ✅ |

---

## ✅ Phase 1: REST API & Documentation (COMPLETE)

| Task | Status |
|------|--------|
| OpenAPI 3.0 specification | ✅ |
| Swagger UI | ✅ |
| Postman collection | ✅ |
| FIXM field naming | ✅ |
| All REST endpoints | ✅ |
| Ingest endpoints (ADL + Track) | ✅ |

---

## ✅ Phase 2: Real-Time WebSocket (COMPLETE)

| Task | Status | Notes |
|------|--------|-------|
| Ratchet WebSocket server | ✅ | Port 8090 |
| Database authentication | ✅ | `swim_api_keys` validation |
| Tier-based connection limits | ✅ | Enforced per tier |
| External WSS access | ✅ | Via Apache proxy |

---

## ✅ Phase 3: SDKs & Integrations (COMPLETE)

### Python SDK v2.0.0

| Feature | Status |
|---------|--------|
| REST Client (sync) | ✅ |
| REST Client (async with aiohttp) | ✅ |
| WebSocket Client | ✅ |
| Typed Models (dataclasses) | ✅ |
| Examples | ✅ |

**Location:** `sdk/python/swim_client/`

**Install:**
```bash
pip install swim-client
# or with async support:
pip install swim-client[async]
```

### C# SDK v1.0.0

| Feature | Status |
|---------|--------|
| SwimRestClient | ✅ |
| SwimWebSocketClient | ✅ |
| Typed Models | ✅ |
| .NET 6/7/8 + Standard 2.0 | ✅ |

**Location:** `sdk/csharp/SwimClient/`

**Install:**
```bash
dotnet add package VatSim.Swim.Client
```

### Java SDK v1.0.0

| Feature | Status |
|---------|--------|
| SwimRestClient | ✅ |
| SwimWebSocketClient | ✅ |
| Typed Models (POJOs) | ✅ |
| Java 11+ | ✅ |

**Location:** `sdk/java/swim-client/`

**Install (Maven):**
```xml
<dependency>
    <groupId>org.vatsim.swim</groupId>
    <artifactId>swim-client</artifactId>
    <version>1.0.0</version>
</dependency>
```

### JavaScript/TypeScript SDK v1.0.0

| Feature | Status |
|---------|--------|
| SwimRestClient | ✅ |
| SwimWebSocketClient | ✅ |
| Full TypeScript types | ✅ |
| Node.js + Browser | ✅ |

**Location:** `sdk/javascript/`

**Install:**
```bash
npm install @vatsim/swim-client
```

---

## ✅ Phase 4: Data Isolation (COMPLETE)

_Added March 14, 2026._

All SWIM API endpoints now query exclusively from the `SWIM_API` database. Internal databases (`VATSIM_TMI`, `VATSIM_ADL`, `VATSIM_REF`, `perti_site` MySQL) are never accessed directly by API request handlers.

| Task | Status |
|------|--------|
| Migration 026 — swim_flights expansion (+34 cols, row_hash, 14 index drops) | ✅ |
| Migration 026 — 25 mirror tables (10 TMI + 4 flow + 4 CDM + 4 reference + 3 infrastructure) | ✅ |
| Migration 026 — 14 SWIM views (active filters, CDM views) | ✅ |
| Migration 026 — sp_Swim_BulkUpsert update (row-hash skip + change feed + 60 new columns) | ✅ |
| swim_sync.php update — add ~60 columns to ADL SELECT/JSON mapping | ✅ |
| swim_tmi_sync_daemon.php — new TMI sync daemon (5min operational, daily reference) | ✅ |
| refdata_sync_daemon.php — new reference data sync daemon (daily 06:00Z) | ✅ |
| Endpoint migration — auth.php (remove ADL fallback) | ✅ |
| Endpoint migration — flights.php, positions.php (remove ADL fallback) | ✅ |
| Endpoint migration — flight.php (invert to SWIM-only, single-table queries) | ✅ |
| Endpoint migration — TMI endpoints (10 files, conn_tmi → conn_swim, swim_tmi_* tables) | ✅ |
| Endpoint migration — CDM endpoints (5 files + CDMService v2.0, SWIM reads) | ✅ |
| Endpoint migration — reference endpoints (taxi-times, cdrs, throughput, plays) | ✅ |
| Endpoint migration — keys endpoints (provision.php, revoke.php) | ✅ |
| Endpoint migration — ingest/cdm.php (lazy TMI connection) | ✅ |
| PERTI_SWIM_ONLY optimization — connect.php + auth.php (skip MySQL/ADL/TMI) | ✅ |
| startup.sh — add swim_tmi_sync_daemon + refdata_sync_daemon | ✅ |
| WebSocket bug fix — pos.updated_at → pos.position_updated_utc | ✅ |
| Documentation updates | ✅ |

---

## AOC Telemetry Support

Virtual Airlines can push flight sim telemetry via the ingest API:

| Field | Type | Description |
|-------|------|-------------|
| `vertical_rate_fpm` | INT | Climb/descent rate (+ = climb, - = descent) |
| `out_utc` | DATETIME | OOOI - Gate departure |
| `off_utc` | DATETIME | OOOI - Wheels up |
| `on_utc` | DATETIME | OOOI - Wheels down |
| `in_utc` | DATETIME | OOOI - Gate arrival |
| `eta_utc` | DATETIME | FMC-calculated ETA |
| `etd_utc` | DATETIME | Expected departure |

**Endpoints:**
- `POST /ingest/adl` - Full flight data with telemetry
- `POST /ingest/track` - High-frequency position updates (1000/batch)

---

## 📁 File Structure

```
PERTI/
├── api/swim/v1/
│   ├── ingest/
│   │   ├── adl.php      # v3.2.0 - telemetry support
│   │   ├── track.php    # v1.2.0 - high-freq positions
│   │   └── metering.php
│   ├── ws/
│   │   └── WebSocketServer.php
│   ├── flights.php
│   └── positions.php
├── sdk/
│   ├── python/          # Python SDK v2.0.0
│   │   ├── swim_client/
│   │   │   ├── __init__.py
│   │   │   ├── client.py      # WebSocket client
│   │   │   ├── rest.py        # REST client
│   │   │   ├── models.py      # Data models
│   │   │   └── events.py      # Event types
│   │   ├── examples/
│   │   ├── pyproject.toml
│   │   └── README.md
│   ├── csharp/          # C# SDK v1.0.0
│   │   └── SwimClient/
│   │       ├── SwimRestClient.cs
│   │       ├── SwimWebSocketClient.cs
│   │       ├── Models/
│   │       ├── SwimClient.csproj
│   │       └── README.md
│   ├── java/            # Java SDK v1.0.0
│   │   └── swim-client/
│   │       ├── src/main/java/org/vatsim/swim/
│   │       │   ├── SwimRestClient.java
│   │       │   ├── SwimWebSocketClient.java
│   │       │   ├── SwimApiException.java
│   │       │   └── model/
│   │       ├── pom.xml
│   │       └── README.md
│   └── javascript/      # JavaScript/TypeScript SDK v1.0.0
│       ├── src/
│       │   ├── index.ts
│       │   ├── rest.ts
│       │   ├── websocket.ts
│       │   └── types.ts
│       ├── package.json
│       ├── tsconfig.json
│       └── README.md
└── docs/swim/
    ├── VATSIM_SWIM_API_Documentation.md
    ├── SWIM_TODO.md
    ├── openapi.yaml
    └── VATSIM_SWIM_API.postman_collection.json
```

---

## 💰 Cost Summary

| Component | Monthly |
|-----------|---------|
| SWIM_API (Azure SQL Basic) | $5 |
| WebSocket (self-hosted) | $0 |
| **Total** | **$5** |

---

## 🔗 API Endpoints

| Endpoint | Method | Status |
|----------|--------|--------|
| `/api/swim/v1` | GET | ✅ |
| `/api/swim/v1/flights` | GET | ✅ |
| `/api/swim/v1/flight` | GET | ✅ |
| `/api/swim/v1/positions` | GET | ✅ |
| `/api/swim/v1/tmi/programs` | GET | ✅ |
| `/api/swim/v1/tmi/controlled` | GET | ✅ |
| `/api/swim/v1/ingest/adl` | POST | ✅ |
| `/api/swim/v1/ingest/track` | POST | ✅ |
| `/api/swim/v1/ws` | WS | ✅ |

---

## 📝 Change Log

### 2026-03-14 Data Isolation (Phase 4 Complete)
- ✅ Migration 026 — swim_flights expansion (+34 cols, row_hash, 14 index drops)
- ✅ Migration 026 — 25 mirror tables (10 TMI + 4 flow + 4 CDM + 4 ref + 3 infra)
- ✅ Migration 026 — sp_Swim_BulkUpsert update (row-hash skip + change feed + 60 cols)
- ✅ swim_sync.php — add ~60 columns to ADL SELECT/JSON mapping
- ✅ swim_tmi_sync_daemon.php — new TMI sync daemon (5min operational, daily reference)
- ✅ refdata_sync_daemon.php — new reference data sync daemon (daily 06:00Z)
- ✅ All 48 SWIM endpoints migrated to SWIM_API-only queries
- ✅ PERTI_SWIM_ONLY optimization — skip non-SWIM DB connections
- ✅ WebSocket bug fix (pos.updated_at → pos.position_updated_utc)

### 2026-01-16 Session 5 (SDKs Complete)
- ✅ Enhanced Python SDK v2.0.0 - Added REST client, models
- ✅ Created C# SDK v1.0.0 - REST + WebSocket
- ✅ Created Java SDK v1.0.0 - REST + WebSocket
- ✅ Created JavaScript/TypeScript SDK v1.0.0 - REST + WebSocket
- ✅ Phase 3 COMPLETE

### 2026-01-16 Session 4 (AOC Telemetry)
- ✅ Added vertical_rate_fpm support to ingest/adl.php
- ✅ Added OOOI times support (out/off/on/in_utc)
- ✅ Added eta_utc/etd_utc support
- ✅ Fixed ingest/track.php database connection
- ✅ Updated Postman collection with AOC examples

### 2026-01-16 Session 3 (Phase 2 Complete)
- ✅ Database authentication implemented
- ✅ Tier-based connection limits
- ✅ Phase 2 COMPLETE

### 2026-01-16 Sessions 1-2
- ✅ WebSocket server deployed
- ✅ Python SDK (WebSocket only) created

---

## 🚀 Future Enhancements

| Feature | Priority | Notes |
|---------|----------|-------|
| Redis IPC | Low | File-based IPC adequate |
| Message compression | Low | Performance optimization |
| Historical replay | Low | Past event retrieval |
| Metrics dashboard | Low | Usage tracking |
| Additional languages | As needed | Go, Rust, etc. |

---

## 📊 SDK Quick Reference

### Python
```python
from swim_client import SWIMRestClient, SWIMClient

rest = SWIMRestClient('api-key')
flights = rest.get_flights(dest_icao='KJFK')

ws = SWIMClient('api-key')
ws.subscribe(['flight.departed'])
ws.run()
```

### C#
```csharp
using var rest = new SwimRestClient("api-key");
var flights = await rest.GetFlightsAsync(destIcao: "KJFK");

await using var ws = new SwimWebSocketClient("api-key");
ws.OnFlightDeparted += (s, e) => Console.WriteLine(e.Data.Callsign);
await ws.ConnectAsync();
```

### Java
```java
try (SwimRestClient rest = new SwimRestClient("api-key")) {
    List<Flight> flights = rest.getFlights("KJFK", null, "active");
}

SwimWebSocketClient ws = new SwimWebSocketClient("api-key");
ws.on("flight.departed", (data, ts) -> System.out.println(data.get("callsign")));
ws.connect();
```

### JavaScript/TypeScript
```typescript
const rest = new SwimRestClient('api-key');
const flights = await rest.getFlights({ dest_icao: 'KJFK' });

const ws = new SwimWebSocketClient('api-key');
ws.on('flight.departed', (data) => console.log(data.callsign));
await ws.connect();
```

---

**Contact:** dev@vatcscc.org
