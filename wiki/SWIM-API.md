# SWIM API

> **System Wide Information Management** -- the public, external-facing API for PERTI flight data.

**Base URL**: `https://perti.vatcscc.org/api/swim/v1`

---

## SWIM APIs vs PERTI APIs

PERTI exposes **two separate API layers** serving different audiences:

| | **SWIM API** | **PERTI API** |
|---|---|---|
| **Purpose** | External data exchange with third-party consumers | Internal web UI and operational tools |
| **Audience** | Virtual airlines, pilot clients, ATC tools, data integrators | PERTI web pages (plan.php, demand.php, etc.) |
| **Base path** | `/api/swim/v1/` | `/api/adl/`, `/api/data/`, `/api/tmi/`, `/api/mgt/`, `/api/stats/` |
| **Auth** | API key (`Authorization: Bearer {key}` or `X-API-Key: {key}`) | Session-based (VATSIM OAuth login) |
| **Rate limits** | Tier-based: 100-30,000 req/min | No formal rate limits |
| **Formats** | JSON, FIXM, XML, GeoJSON, CSV, KML, NDJSON | JSON only |
| **CORS** | Open (`Access-Control-Allow-Origin: *`) | Same-origin only |
| **Field naming** | FIXM-aligned (FAA SWIM compatible) | Internal naming conventions |
| **Caching** | APCu with ETag support | None |
| **Documentation** | This page, [[SWIM Routes API]], [OpenAPI spec](../blob/main/docs/swim/openapi.yaml) | [[API Reference]] |

**Key distinction**: SWIM endpoints are designed for external consumers and follow FAA SWIM/FIXM conventions. PERTI endpoints power the internal web interface and use session-based authentication tied to VATSIM OAuth. They are separate codebases with separate authentication mechanisms.

For internal API documentation, see [[API Reference]].

---

## Authentication

### API Key

All authenticated SWIM endpoints require an API key sent via header:

```
Authorization: Bearer swim_dev_abc123...
```

or:

```
X-API-Key: swim_dev_abc123...
```

### Key Tiers

| Tier | Rate Limit | Write Access | WS Connections | Use Case |
|------|-----------|-------------|----------------|----------|
| **public** | 100/min | No | 5 | Read-only data browsing |
| **developer** | 300/min | No | 50 | Pilot clients, personal projects |
| **partner** | 3,000/min | Yes | 500 | Virtual airlines, ATC tools |
| **system** | 30,000/min | Yes | 10,000 | Core integrations (vNAS, SimTraffic) |

### Getting a Key

- **Auto-provisioned**: Pilot clients can auto-provision developer keys via VATSIM OAuth (see [Key Provisioning](#key-provisioning) below)
- **Manual**: Contact dev@vatcscc.org for partner or system tier keys

### Public Endpoints (No Auth Required)

A few endpoints are accessible without an API key:

| Endpoint | Description |
|----------|-------------|
| `GET /` | API index and documentation links |
| `GET /routes/cdrs` | Coded Departure Routes |
| `GET /playbook/plays` | Playbook plays |
| `GET /tmi/reroutes` | TMI reroute definitions |
| `GET /health` | Health check (also accepts localhost) |

---

## Output Formats

Most GET endpoints support a `format` query parameter:

| Format | Content-Type | Notes |
|--------|-------------|-------|
| `json` | `application/json` | Default for most endpoints |
| `fixm` | `application/json` | FIXM 4.3.0 field names (camelCase) |
| `xml` | `application/xml` | XML representation |
| `geojson` | `application/geo+json` | GeoJSON FeatureCollection |
| `csv` | `text/csv` | Flat CSV export |
| `kml` | `application/vnd.google-earth.kml+xml` | Google Earth format |
| `ndjson` | `application/x-ndjson` | Newline-delimited JSON (streaming) |

Not all formats are available on every endpoint. When a format is unsupported, the endpoint returns JSON with an error message.

---

## Endpoints

### Flight Data

#### GET /flights

Returns active flights with filtering and pagination.

**Auth**: Required (read-only)
**Formats**: json, fixm, xml, geojson, csv, kml, ndjson

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | `active` (default), `completed`, `all` |
| `dept_icao` | string | Departure airport(s), comma-separated |
| `dest_icao` | string | Destination airport(s), comma-separated |
| `dep_artcc` | string | Departure ARTCC (FAA or ICAO codes) |
| `dest_artcc` | string | Destination ARTCC |
| `current_artcc` | string | Currently in ARTCC |
| `dep_tracon` | string | Departure TRACON |
| `dest_tracon` | string | Destination TRACON |
| `current_tracon` | string | Currently in TRACON |
| `current_sector` | string | Currently in sector |
| `strata` | string | Sector strata: `low`, `high`, `superhigh` |
| `callsign` | string | Callsign filter (use `*` as wildcard) |
| `phase` | string | Flight phase: `PREFILED`, `TAXI`, `DEPARTURE`, `ENROUTE`, `ARRIVAL`, `LANDED` |
| `tmi_controlled` | bool | Only TMI-controlled flights |
| `page` | int | Page number (default 1) |
| `per_page` | int | Results per page (max 500) |
| `format` | string | Output format |

```bash
curl -H "Authorization: Bearer YOUR_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/flights?dest_icao=KJFK&status=active&per_page=10"
```

#### GET /flight

Returns detailed data for a single flight from the normalized ADL tables.

**Auth**: Required (read-only)
**Formats**: json, fixm

| Parameter | Type | Description |
|-----------|------|-------------|
| `gufi` | string | GUFI (e.g., `VAT-20260115-UAL123-KJFK-KLAX`) |
| `flight_uid` | int | Numeric flight UID |
| `flight_key` | string | Flight key string |
| `include_history` | bool | Include historical/inactive flights (default: false) |
| `format` | string | Output format (fixm default) |

Returns full flight detail: identity, position, plan, times, TMI control, aircraft performance, airspace assignment, and progress data.

#### GET /positions

Bulk flight positions optimized for map rendering.

**Auth**: Required (read-only)
**Format**: GeoJSON only (FeatureCollection with Point geometries)

| Parameter | Type | Description |
|-----------|------|-------------|
| `dest_icao` | string | Destination airport filter |
| `dest_artcc` | string | Destination ARTCC filter |
| `current_artcc` | string | Current ARTCC filter |
| `current_tracon` | string | Current TRACON filter |
| `current_sector` | string | Current sector filter |
| `strata` | string | Sector strata filter |
| `bounds` | string | Bounding box: `minLon,minLat,maxLon,maxLat` |
| `tmi_controlled` | bool | TMI control filter |
| `phase` | string | Flight phase filter |
| `include_route` | bool | Include route string (default: false) |

Each feature contains `[lon, lat, altitude]` coordinates with flight metadata properties.

---

### Controllers

#### GET /controllers

ATC controller data with vNAS sector enrichment.

**Auth**: Required (read-only)
**Formats**: json, fixm, xml, geojson, csv, ndjson

| Parameter | Type | Description |
|-----------|------|-------------|
| `summary` | bool | Return facility staffing summary instead of individual controllers |
| `status` | string | `active` (default), `all` |
| `callsign` | string | Callsign wildcard filter |
| `facility_type` | string | `CTR`, `APP`, `TWR`, `FSS`, `DEL`, `GND` |
| `facility_id` | string | Facility identifier |
| `artcc` | string | ARTCC filter |
| `rating` | int | VATSIM rating (1-12) |
| `has_vnas` | bool | Only vNAS-enriched controllers |
| `page` | int | Page number |
| `per_page` | int | Results per page |

Returns controller records with facility, position, vNAS sector assignment, and ERAM/STARS data when available.

---

### Traffic Management (TMI)

#### GET /tmi/programs

Active TMI programs (Ground Stops and GDPs).

**Auth**: Required (read-only)

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `all` (default), `gs`, `gdp` |
| `airport` | string | Filter by control element (airport) |
| `artcc` | string | Filter by ARTCC |
| `include_history` | bool | Include programs from last 2 hours (default: false) |
| `flights` | bool | Include affected flights (default: false) |
| `id` | int | Get single program by ID |

#### GET /tmi/controlled

Flights currently under TMI control.

**Auth**: Required (read-only)

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `all` (default), `gs`, `gdp`, `afp`, `reroute` |
| `airport` | string | Destination airport filter |
| `artcc` | string | Destination ARTCC filter |
| `dept_icao` | string | Departure airport filter |
| `phase` | string | Flight phase filter |
| `include_exempt` | bool | Include exempt flights (default: false) |
| `page` | int | Page number |
| `per_page` | int | Results per page |

#### GET /tmi/advisories

Formal TMI advisories (NTML messages).

**Auth**: Required (read-only)

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | Advisory type: `GS`, `GDP`, `REROUTE` |
| `airport` | string | Control element filter |
| `facility` | string | Issuing facility filter |
| `status` | string | Status filter |
| `active_only` | bool | Only active advisories (default: true) |
| `include_text` | bool | Include full advisory body (default: true) |
| `page` | int | Page number |
| `per_page` | int | Results per page |

#### GET /tmi/reroutes

Reroute definitions and compliance.

**Auth**: Public (no auth required)

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | int | Single reroute by ID |
| `origin` | string | Origin center filter |
| `dest` | string | Destination filter |
| `status` | string | Status filter |
| `active_only` | bool | Only active reroutes (default: true) |
| `flights` | bool | Include flight assignments (default: false) |
| `compliance` | bool | Include compliance data (default: false) |
| `include_advisory` | bool | Include Discord advisory text (default: false) |
| `page` | int | Page number |
| `per_page` | int | Results per page |

#### GET /tmi/entries

TMI log entries (MIT, AFP, restrictions).

**Auth**: Required (read-only)

#### GET /tmi/routes

Published TMI route visualizations for map display.

**Auth**: Required (read-only)

#### GET /tmi/measures

ECFMP flow measures.

**Auth**: Required (read-only)

---

### Metering (TBFM-aligned)

#### GET /metering/{airport}

TBFM-style metering data for airport arrivals.

**Auth**: Required (read-only)
**Formats**: json, fixm, xml, csv, ndjson

| Parameter | Type | Description |
|-----------|------|-------------|
| `{airport}` | path | Airport ICAO code |
| `status` | string | Metering status: `UNMETERED`, `METERED`, `FROZEN`, `SUSPENDED`, `EXEMPT` |
| `runway` | string | Arrival runway filter |
| `stream` | string | Arrival stream (corner post) filter |
| `metered_only` | bool | Only flights with metering data (default: true) |
| `format` | string | Output format (fixm default) |

Returns FIXM-aligned TBFM fields: `sequence_number`, `scheduled_time_of_arrival`, `metering_delay`, `metering_status`.

#### GET /metering/{airport}/sequence

Arrival sequence list sorted by sequence number.

**Auth**: Required (read-only)

Compact format optimized for vNAS datablocks: SEQ, STA, ETA, delay, runway, stream.

---

### Reference Data

#### GET /reference/taxi-times

Airport unimpeded taxi-out reference times using FAA ASPM p5-p15 methodology over a 90-day rolling window. Refreshed daily at 02:00Z.

**Auth**: Required (read-only)
**Formats**: json, fixm, xml, csv, ndjson

| Parameter | Type | Description |
|-----------|------|-------------|
| `confidence` | string | `HIGH`, `MEDIUM`, `LOW`, `DEFAULT` |
| `min_samples` | int | Minimum sample count |
| `format` | string | Output format |

Returns all 3,628 airports with taxi reference data.

#### GET /reference/taxi-times/{airport}

Single airport taxi reference with dimensional breakdown (weight class, carrier, engine configuration, destination region).

**Auth**: Required (read-only)

**Example:**

```bash
curl -H "Authorization: Bearer YOUR_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/reference/taxi-times/KJFK"
```

```json
{
  "success": true,
  "airport": {
    "airport_icao": "KJFK",
    "unimpeded_taxi_out_sec": 720,
    "sample_size": 4521,
    "confidence": "HIGH",
    "p05_taxi_sec": 480,
    "p15_taxi_sec": 600,
    "last_refreshed_utc": "2026-02-10T02:00:00+00:00"
  },
  "details": [
    {"dimension": "WEIGHT_CLASS", "dimension_value": "HEAVY", "unimpeded_taxi_out_sec": 780, "sample_size": 1205},
    {"dimension": "CARRIER", "dimension_value": "DAL", "unimpeded_taxi_out_sec": 700, "sample_size": 890}
  ],
  "detail_count": 12,
  "methodology": {
    "description": "FAA ASPM p5-p15 average, 90-day rolling window",
    "min_samples": 50,
    "default_taxi_sec": 600,
    "refresh_schedule": "Daily at 02:00Z",
    "source": "VATSIM OOOI data (out_utc, off_utc)"
  }
}
```

---

### Routes & Playbook

Reference route data served from the isolated `SWIM_API` database, reimported daily at 06:00Z. CDR and playbook list/detail endpoints are **public**; analysis and throughput require an API key.

For full documentation, parameters, response schemas, geometry support, and use cases, see **[[SWIM Routes API]]**.

#### GET /routes/cdrs

Coded Departure Routes (~41,000 routes). Pre-coordinated reroutes between airport pairs.

**Auth**: Public (no auth required)

| Key Parameters | |
|---|---|
| `origin`, `dest` | Airport ICAO filters |
| `artcc` | ARTCC filter (departure or arrival) |
| `search` | Free-text search across code and route |
| `include=geometry` | Add GeoJSON route geometry via PostGIS |

```bash
curl "https://perti.vatcscc.org/api/swim/v1/routes/cdrs?origin=KJFK&dest=KORD&per_page=2"
```

#### GET /playbook/plays

National Playbook plays (~3,800 plays, ~268,000 routes). Operates in list mode (metadata only) or single-play mode (`?id=X` or `?name=PLAY_NAME` for full routes with scope and traversal).

**Auth**: Public (no auth required)

| Key Parameters | |
|---|---|
| `id` | Single play by ID (enables full route detail) |
| `name` | Single play by name (alternative to `id`) |
| `category` | FAA category filter |
| `artcc` | ARTCC filter |
| `search` | Free-text search |
| `include=geometry` | Add GeoJSON route geometry via PostGIS |

```bash
curl "https://perti.vatcscc.org/api/swim/v1/playbook/plays?search=ORD+EAST&per_page=5"
```

#### GET /playbook/analysis

Route facility traversal, distances, and time segments via PostGIS spatial analysis.

**Auth**: Required (read-only)

| Key Parameters | |
|---|---|
| `route_id` | Playbook route ID |
| `route_string` | Route string (with `origin` + `dest`) |
| `cruise_kts` | Cruise speed in knots TAS (default 460) |
| `facility_types` | Facility types to include (default `ARTCC,FIR`) |

#### GET/POST /playbook/throughput

CTP route throughput data. GET retrieves; POST ingests per-route metrics.

**Auth**: Required (read for GET, write for POST)

---

### Ingest (Write Endpoints)

Write endpoints for external data sources. Require partner or system tier API keys.

#### POST /ingest/adl

Ingest flight data from authoritative sources. Max 500 flights per request.

**Auth**: Required (write access, `adl` field authorization)

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/adl" \
  -H "Authorization: Bearer swim_par_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"flights": [{"callsign": "DLH401", "dept_icao": "KJFK", "dest_icao": "EDDF", "altitude_ft": 35000}]}'
```

#### POST /ingest/track

High-frequency position updates. Max 1,000 tracks per request.

**Auth**: Required (write access, `track` field authorization)

#### POST /ingest/metering

TBFM metering data (SimTraffic integration).

**Auth**: Required (write access, `metering` field authorization)

#### POST /ingest/cdm

CDM milestone data.

**Auth**: Required (write access, `cdm` field authorization)

#### POST /ingest/vnas/track

vNAS track data.

**Auth**: Required (write access)

#### POST /ingest/vnas/tags

vNAS datablock tags.

**Auth**: Required (write access)

#### POST /ingest/vnas/handoff

vNAS handoff events.

**Auth**: Required (write access)

#### POST /ingest/vnas/controllers

vNAS controller position updates.

**Auth**: Required (write access)

#### POST /ingest/acars

ACARS message ingest (OOOI times, datalink).

**Auth**: Required (write access)

---

### Key Provisioning

#### POST /keys/provision

Auto-provision developer-tier API keys for pilot clients after VATSIM OAuth authentication.

**Auth**: None (uses VATSIM OAuth access token in payload)

```json
{
  "access_token": "VATSIM_OAUTH_TOKEN",
  "client_name": "My Pilot Client",
  "client_version": "1.2.0"
}
```

Returns a developer-tier API key tied to the VATSIM CID.

#### POST /keys/revoke

Revoke an existing API key.

**Auth**: Required (system tier only)

---

### WebSocket

Real-time flight and TMI event streaming.

**URL**: `wss://perti.vatcscc.org/api/swim/v1/ws/?key=YOUR_API_KEY`
**Server**: Ratchet PHP WebSocket on port 8090

#### Subscribing

```json
{
  "action": "subscribe",
  "channels": ["flight.departed", "flight.arrived", "tmi.issued"],
  "filters": {
    "airports": ["KJFK", "KLAX"],
    "artccs": ["ZNY"],
    "callsign_prefix": ["AAL", "UAL"]
  }
}
```

#### Events

| Event | Description |
|-------|-------------|
| `flight.created` | New pilot connected |
| `flight.departed` | Wheels up |
| `flight.arrived` | Wheels down |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Position batch update |
| `tmi.issued` | GS/GDP issued |
| `tmi.released` | TMI ended |
| `controller.connected` | ATC controller logged on |
| `controller.disconnected` | ATC controller logged off |
| `controller.positions` | Controller position batch |
| `system.heartbeat` | Server keepalive |

#### SDK Examples

**Python:**

```python
from swim_client import SWIMClient

client = SWIMClient('your-api-key')

@client.on('flight.departed')
def on_departure(data, timestamp):
    print(f"{data.callsign} departed {data.dep}")

client.subscribe(['flight.departed', 'flight.arrived'])
client.run()
```

**JavaScript:**

```javascript
const swim = new SWIMWebSocket('your-api-key');
await swim.connect();

swim.subscribe(['flight.departed'], { airports: ['KJFK'] });
swim.on('flight.departed', (data) => {
    console.log(`${data.callsign} departed ${data.dep}`);
});
```

---

### Health

#### GET /health

System health check.

**Auth**: Optional (any valid key or localhost)

Returns status of SWIM database, API keys, WebSocket server, APCu cache, and rate limiting. Reports `healthy`, `degraded`, or `unhealthy`.

#### GET /

API index page. Lists all available endpoints, authentication info, and contact details.

**Auth**: None

---

## Error Handling

All SWIM endpoints return consistent error responses:

```json
{
  "error": true,
  "message": "Missing Authorization header. Use \"Authorization: Bearer {api_key}\" or \"X-API-Key: {api_key}\"",
  "status": 401,
  "code": "UNAUTHORIZED"
}
```

| Code | Meaning |
|------|---------|
| 400 | Bad request (invalid parameters) |
| 401 | Missing or invalid API key |
| 403 | Insufficient tier permissions |
| 404 | Resource not found |
| 405 | Method not allowed |
| 429 | Rate limit exceeded |
| 500 | Internal server error |
| 503 | Service unavailable (database down or hibernation mode) |

---

## Pagination

Paginated endpoints return:

```json
{
  "pagination": {
    "page": 1,
    "per_page": 50,
    "total": 1247,
    "total_pages": 25,
    "has_more": true
  }
}
```

Increment `page` until `has_more` is `false`. Maximum `per_page` varies by endpoint (typically 200-500).

---

## SDKs

| Language | Location | Notes |
|----------|----------|-------|
| Python | `sdk/python/` | Full REST + WebSocket client |
| JavaScript | `api/swim/v1/ws/swim-ws-client.js` | WebSocket client |
| C# | `sdk/csharp/` | Full REST client |
| Java | `sdk/java/` | Full REST client |

Install the Python SDK:

```bash
pip install -e sdk/python
```

---

## Related Resources

- **[[SWIM Routes API]]** -- detailed CDR and playbook endpoint documentation with real examples
- **[OpenAPI Spec](../blob/main/docs/swim/openapi.yaml)** -- machine-readable API specification (import into Postman)
- **[SWIM Documentation Portal](https://perti.vatcscc.org/swim-docs.php)** -- connector guides, integration docs
- **[Swagger UI](https://perti.vatcscc.org/docs/swim/)** -- interactive API explorer
- **[[API Reference]]** -- internal PERTI API documentation
- **Contact**: dev@vatcscc.org
