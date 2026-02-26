# VATSWIM API - Official Launch Announcement

**System Wide Information Management (SWIM) for VATSIM**

**Release Date:** January 16, 2026
**Version:** 1.0.0
**Status:** Production Ready

---

## What is VATSWIM?

VATSWIM (System Wide Information Management) is a centralized real-time data exchange hub that provides unified access to flight information across the entire VATSIM network. SWIM serves as a single source of truth for flight data, enabling consistent information sharing between all VATSIM systems, tools, and services.

**Base URL:** `https://perti.vatcscc.org/api/swim/v1`

---

## Key Features

- **REST API** - Query flights, positions, and TMI data with advanced filtering
- **WebSocket** - Real-time event streaming with sub-second latency
- **Multi-format Support** - JSON, GeoJSON, and FIXM-compatible field naming
- **Tiered Access** - Rate limits scaled for different use cases
- **Self-Service API Keys** - Create and manage keys at https://perti.vatcscc.org/swim-keys
- **SDKs Available** - Python, JavaScript, C#, and Java client libraries

---

## Getting Started

### Step 1: Create Your API Key

Visit **https://perti.vatcscc.org/swim-keys** and log in with your VATSIM account to create an API key. Self-service tiers available:

| Tier | Rate Limit | WebSocket Connections | Best For |
|------|------------|----------------------|----------|
| **Public** | 100 req/min | 5 | Personal projects, web apps |
| **Developer** | 300 req/min | 50 | Development, testing, prototypes |

*Partner (3,000/min) and System (30,000/min) tiers available upon request for virtual airlines and integration partners.*

### Step 2: Make Your First Request

Include your API key in the `Authorization` header:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  https://perti.vatcscc.org/api/swim/v1/flights
```

### Step 3: Explore the Documentation

Full API documentation: **https://perti.vatcscc.org/docs/swim/**

---

## Usage Guide by User Type

### For Virtual Airlines

SWIM enables real-time fleet tracking, schedule integration, and operational data exchange for virtual airline operations.

**Key Capabilities:**
- Track all your fleet's active flights in real-time
- Push OOOI times (Out/Off/On/In) from your ACARS/AOC systems
- Receive TMI notifications (ground stops, delays) affecting your flights
- Export flight data for on-time performance analysis

**Recommended Endpoints:**
```
GET /flights?callsign=DAL*          # All Delta callsigns
GET /flights?airline_icao=AAL       # All American Airlines flights
GET /tmi/controlled?dest=KJFK       # TMI-controlled flights to JFK
POST /ingest/adl                    # Push OOOI times (Partner tier required)
```

**WebSocket Events:**
Subscribe to departure/arrival events for your fleet:
```json
{
  "action": "subscribe",
  "filters": { "callsign_prefix": "DAL" }
}
```

**Integration Example:**
```python
from swim_client import SwimClient

client = SwimClient(api_key="swim_par_yourkey")

# Get all active flights for your airline
flights = client.get_flights(callsign="UAL*")

# Push actual departure time from ACARS
client.ingest_adl({
    "gufi": "VAT-20260116-UAL123-KJFK-KLAX",
    "off_utc": "2026-01-16T15:30:00Z"
})
```

**Request Partner Tier:** Contact vATCSCC for Partner tier access with write capabilities.

---

### For Developers & Third-Party Tools

Build flight tracking applications, ATC tools, statistics dashboards, or integrate SWIM data into existing software.

**Key Capabilities:**
- Query live flight data with powerful filtering
- Stream real-time position updates via WebSocket
- Access GeoJSON-formatted data for mapping applications
- Build custom dashboards and analytics tools

**Recommended Endpoints:**
```
GET /flights                        # All active flights (paginated)
GET /flights?dest_icao=KJFK         # Arrivals to JFK
GET /flights?artcc=ZNY              # Flights in NY Center airspace
GET /positions                      # GeoJSON positions for mapping
GET /flight/{gufi}                  # Single flight by GUFI
```

**WebSocket Real-Time Streaming:**
```javascript
const ws = new WebSocket('wss://perti.vatcscc.org/api/swim/v1/ws');

ws.onopen = () => {
  ws.send(JSON.stringify({
    action: 'auth',
    api_key: 'swim_dev_yourkey'
  }));

  ws.send(JSON.stringify({
    action: 'subscribe',
    events: ['flight.departed', 'flight.arrived', 'flight.positions'],
    filters: { airport: 'KJFK' }
  }));
};

ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  console.log('Event:', data.event, data.payload);
};
```

**Available SDKs:**
- **Python:** `pip install vatsim-swim-client`
- **JavaScript:** `npm install @vatsim/swim-client`
- **C#:** NuGet package `VATSIM.SWIM.Client`
- **Java:** Maven artifact `org.vatsim:swim-client`

---

### For ATC Facilities & vNAS/CRC Users

SWIM provides authoritative flight data for traffic management, demand analysis, and operational coordination.

**Key Capabilities:**
- Monitor sector traffic loads in real-time
- Track TMI-controlled flights and EDCT compliance
- Access demand forecasts for arrival/departure planning
- Receive ground stop and GDP notifications

**Recommended Endpoints:**
```
GET /flights?artcc=ZDC              # All flights in DC Center
GET /flights?fp_dest_artcc=ZNY      # Flights destined to NY Center
GET /tmi/programs                   # Active ground stops and GDPs
GET /tmi/controlled                 # All TMI-controlled flights
GET /flights?phase=departing        # Departing flights
```

**TMI Data Available:**
- Ground stop status (`gs_held`, `gs_release_utc`)
- EDCT assignments (`edct_utc`)
- Controlled times (`ctd_utc`, `cta_utc`)
- Delay information (`delay_minutes`, `delay_status`)
- Exemption status (`is_exempt`, `exempt_reason`)

**Example: Monitor Arrival Demand**
```python
# Get all inbound flights to Atlanta
atl_arrivals = client.get_flights(dest_icao="KATL", phase="enroute")

# Group by ETA hour for demand analysis
for flight in atl_arrivals:
    eta = flight.get('eta_runway_utc')
    # Build demand chart...
```

---

### For Pilot Clients & End Users

Access flight tracking data, check TMI status, and build personal flight tools.

**Key Capabilities:**
- Look up any active flight on VATSIM
- Check if your flight is affected by ground stops
- View real-time positions and ETAs
- Build personal flight tracking displays

**Recommended Endpoints:**
```
GET /flight/{gufi}                  # Your specific flight
GET /flights?callsign=N12345        # Search by callsign
GET /tmi/programs                   # Check active ground stops
GET /positions?bbox=...             # Flights in geographic area
```

**Example: Check Your Flight Status**
```bash
# Find your flight
curl -H "Authorization: Bearer swim_pub_yourkey" \
  "https://perti.vatcscc.org/api/swim/v1/flights?callsign=N172SP"

# Check for ground stops at your destination
curl -H "Authorization: Bearer swim_pub_yourkey" \
  "https://perti.vatcscc.org/api/swim/v1/tmi/programs?airport=KJFK"
```

---

### For Internal Systems (vNAS, CRC, SimTraffic)

System-tier access for core VATSIM infrastructure with full read/write capabilities.

**Key Capabilities:**
- High-frequency position updates (up to 1,000/batch)
- TMI data synchronization
- Metering data exchange
- Full write access to authoritative fields

**Ingest Endpoints:**
```
POST /ingest/adl                    # Flight data updates
POST /ingest/track                  # Position updates (batch)
POST /ingest/metering               # Metering/sequencing data
```

**Data Authority:**
- vNAS: Primary source for track positions
- CRC/EuroScope: Secondary track source
- SimTraffic: Primary source for metering data
- vATCSCC: Authoritative for TMI data

**Contact vATCSCC for System tier credentials and integration support.**

---

## API Reference Summary

### REST Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/flights` | List flights with filtering |
| GET | `/flight/{gufi}` | Get single flight by GUFI |
| GET | `/positions` | GeoJSON flight positions |
| GET | `/tmi/programs` | Active TMI programs |
| GET | `/tmi/controlled` | TMI-controlled flights |
| POST | `/ingest/adl` | Ingest flight data (Partner+) |
| POST | `/ingest/track` | Ingest position data (System) |

### Common Query Parameters

| Parameter | Example | Description |
|-----------|---------|-------------|
| `callsign` | `DAL*` | Filter by callsign (wildcards supported) |
| `dest_icao` | `KJFK` | Filter by destination airport |
| `dept_icao` | `KLAX` | Filter by departure airport |
| `artcc` | `ZNY` | Filter by current ARTCC |
| `fp_dest_artcc` | `ZDC` | Filter by destination ARTCC |
| `phase` | `enroute` | Filter by flight phase |
| `page` | `1` | Pagination page number |
| `per_page` | `100` | Results per page (max 1000) |
| `format` | `fixm` | Use FIXM field naming |

### WebSocket Events

| Event | Description |
|-------|-------------|
| `flight.created` | New flight detected |
| `flight.departed` | Flight has taken off |
| `flight.arrived` | Flight has landed |
| `flight.positions` | Position update batch |
| `flight.deleted` | Flight removed from system |
| `tmi.issued` | Ground stop or GDP issued |
| `tmi.released` | Ground stop or GDP released |
| `system.heartbeat` | Server keepalive (30s interval) |

---

## Support & Resources

- **API Documentation:** https://perti.vatcscc.org/docs/swim/
- **Technical Documentation:** https://perti.vatcscc.org/swim-docs
- **API Key Management:** https://perti.vatcscc.org/swim-keys
- **OpenAPI Specification:** https://perti.vatcscc.org/docs/swim/openapi.yaml

**Contact:**
- Technical Support: dev@vatcscc.org
- Partner Tier Requests: dev@vatcscc.org
- Discord: vATCSCC Server (https://vats.im/CommandCenter)

---

## Rate Limits & Best Practices

### Rate Limits by Tier

| Tier | Requests/Minute | WebSocket Connections | Write Access |
|------|-----------------|----------------------|--------------|
| Public | 30 | 5 | No |
| Developer | 100 | 50 | No |
| Partner | 1,000 | 500 | Limited |
| System | 10,000 | 10,000 | Full |

### Best Practices

1. **Cache responses** - Flight data updates every 15 seconds; no need to poll faster
2. **Use WebSocket** - For real-time needs, subscribe to events instead of polling
3. **Batch requests** - Use pagination efficiently; request only needed fields
4. **Handle rate limits** - Implement exponential backoff on 429 responses
5. **Use filters** - Always filter by airport/ARTCC/callsign to reduce data transfer

### Error Responses

| Status | Meaning |
|--------|---------|
| 400 | Bad request (invalid parameters) |
| 401 | Unauthorized (invalid/missing API key) |
| 403 | Forbidden (insufficient permissions) |
| 404 | Not found |
| 429 | Rate limit exceeded |
| 500 | Server error |

---

## Changelog

### v1.0.0 (January 16, 2026)
- Initial public release
- REST API with full flight query capabilities
- WebSocket real-time event streaming
- Self-service API key management portal
- Python, JavaScript, C#, and Java SDKs
- FIXM-compatible field naming support
- TMI data integration (ground stops, GDPs, EDCT)
- GeoJSON position output for mapping

---

*VATSWIM is provided by vATCSCC for the VATSIM community. For flight simulation use only.*
