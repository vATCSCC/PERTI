# VATSWIM Connector Guide

> Unified guide for integrating external data sources with the VATSWIM API.

## What is VATSWIM?

VATSWIM (System Wide Information Management) is PERTI's centralized data exchange hub for real-time flight information sharing across the VATSIM ecosystem. It aggregates data from multiple sources — ATC automation systems, flight simulators, virtual airlines, CDM platforms, and flow management systems — into a unified API.

**Base URL:** `https://perti.vatcscc.org/api/swim/v1`

## Getting an API Key

API keys are available through self-service provisioning:

1. Visit https://perti.vatcscc.org/swim-keys.php
2. Select your tier based on your use case
3. Keys are provisioned instantly for Public and Developer tiers
4. System and Partner tier keys require approval

### API Key Tiers

| Tier | Prefix | Rate Limit | Write Access | Use Case |
|------|--------|------------|--------------|----------|
| **System** | `swim_sys_` | 30,000/min | Yes | Trusted systems (vNAS, SimTraffic, vACDM) |
| **Partner** | `swim_par_` | 3,000/min | Yes | Integration partners (Virtual Airlines) |
| **Developer** | `swim_dev_` | 300/min | No | Development and testing |
| **Public** | `swim_pub_` | 100/min | No | Dashboards and widgets |

## Data Sources

VATSWIM integrates with 7 external data sources:

| Source | Type | Language | Description | Auth Field |
|--------|------|----------|-------------|------------|
| [**vNAS**](#vnas) | Push | C# | ATC automation (ERAM/STARS) surveillance | `track` |
| [**SimTraffic**](#simtraffic) | Bidirectional | Python | TBFM-style metering and timing | `metering` |
| [**vACDM**](#vacdm) | Bidirectional | JavaScript | A-CDM departure milestones | `cdm` |
| [**ECFMP**](#ecfmp) | Poll (read) | Python | European flow management measures | Read-only |
| [**Hoppie ACARS**](#hoppie) | Push | PHP | ACARS/CPDLC OOOI times | `datalink` |
| [**vATIS**](#vatis) | Push | PHP | Runway correlation, weather | `adl` |
| [**Virtual Airlines**](#virtual-airlines) | Push | PHP | Schedule/PIREP/OOOI via VA platforms | `datalink` |

### Integration Types

- **Push**: External system sends data to VATSWIM ingest endpoints
- **Poll**: VATSWIM fetches data from the external system's API
- **Bidirectional**: Both push and poll paths available

## Authentication

All write requests require authentication via API key:

```bash
# Bearer token (recommended)
curl -H "Authorization: Bearer swim_sys_your_key_here" \
     -X POST https://perti.vatcscc.org/api/swim/v1/ingest/...

# Query parameter (for testing)
curl "https://perti.vatcscc.org/api/swim/v1/flights?key=swim_dev_your_key_here"
```

## Common Patterns

### Batch Requests

All ingest endpoints accept batch payloads. Send multiple updates in a single request:

```json
{
  "flights": [
    {"callsign": "UAL123", "altitude_ft": 35000},
    {"callsign": "DAL456", "altitude_ft": 28000}
  ]
}
```

### Error Handling

| HTTP Code | Meaning | Action |
|-----------|---------|--------|
| 200 | Success | Process response |
| 400 | Bad request | Fix payload format |
| 401 | Unauthorized | Check API key |
| 403 | Forbidden | Check tier/authority |
| 429 | Rate limited | Backoff and retry |
| 503 | Service unavailable | Retry with exponential backoff |

### Rate Limit Backoff

When you receive a 429 response, implement exponential backoff:

```python
import time

RETRY_BACKOFF = [1, 3, 10]  # seconds

for attempt in range(3):
    response = send_request(data)
    if response.status != 429:
        break
    time.sleep(RETRY_BACKOFF[attempt])
```

## Data Authority Model

VATSWIM uses a priority-based data authority model. Each field group has an authoritative source, and when multiple sources provide conflicting data, the highest-priority source wins.

| Field Group | Authoritative Source | Priority 1 | Can Override |
|-------------|---------------------|------------|--------------|
| Identity | VATSIM | VATSIM | No |
| Flight Plan | VATSIM | VATSIM | No |
| Track/Position | vNAS | vNAS | Yes |
| Metering | SimTraffic | SimTraffic | Yes |
| Times | SimTraffic | SimTraffic | Yes |
| OOOI/Datalink | ACARS | Hoppie | Yes |
| CDM Milestones | vACDM | vACDM | Yes |
| Runway/Weather | vATIS | vATIS | No |
| Schedule | Virtual Airline | VA platforms | Yes |

## Source-Specific Guides

### vNAS

Push track surveillance from ERAM/STARS systems.

**Endpoints:**
- `POST /ingest/vnas/track.php` — Surveillance data (batch 1000)
- `POST /ingest/vnas/tags.php` — ATC automation tags (batch 500)
- `POST /ingest/vnas/handoff.php` — Sector handoffs (batch 200)

**Client SDK:** [`integrations/connectors/vnas/`](../../integrations/connectors/vnas/) (C#)

**Detailed docs:** [vNAS Integration Guide](vNAS_VATSWIM_Integration.md)

### SimTraffic

Bidirectional metering and timing data exchange.

**Endpoint:** `POST /ingest/simtraffic.php` (batch 500)

**Client SDK:** [`integrations/connectors/simtraffic/`](../../integrations/connectors/simtraffic/) (Python)

**Detailed docs:** [SimTraffic Integration Guide](SimTraffic_VATSWIM_Integration.md)

### vACDM

A-CDM departure milestone data (TOBT/TSAT/TTOT/ASAT/EXOT).

**Endpoint:** `POST /ingest/cdm.php` (batch 500)

**Client SDK:** [`integrations/connectors/vacdm/`](../../integrations/connectors/vacdm/) (JavaScript)

**Detailed docs:** [vACDM Integration Guide](vACDM_VATSWIM_Integration.md)

### ECFMP

European flow management measures (read-only from VATSWIM).

**Endpoints (read):**
- `GET /tmi/flow/events.php` — Active flow events
- `GET /tmi/flow/measures.php` — Active flow measures
- `GET /tmi/flow/providers.php` — Registered providers

**Client SDK:** [`integrations/connectors/ecfmp/`](../../integrations/connectors/ecfmp/) (Python)

**Detailed docs:** [ECFMP Integration Guide](ECFMP_VATSWIM_Integration.md)

### Hoppie

ACARS/CPDLC bridge via the Hoppie network.

**Endpoint:** `POST /ingest/acars.php` (batch 100)

**Full client:** [`integrations/hoppie-cpdlc/`](../../integrations/hoppie-cpdlc/) (PHP)

**Detailed docs:** [ACARS Integration Guide](ACARS_VATSWIM_Integration.md)

### vATIS

Runway correlation and ATIS-derived weather data.

**Endpoint:** `POST /ingest/adl.php` (batch 500)

**Full client:** [`integrations/vatis/`](../../integrations/vatis/) (PHP)

### Virtual Airlines

Schedule, PIREP, and OOOI data from VA management platforms.

**Endpoint:** `POST /ingest/acars.php` (batch 100)

**Full clients:**
- phpVMS 7: [`integrations/virtual-airlines/phpvms7/`](../../integrations/virtual-airlines/phpvms7/)
- smartCARS: [`integrations/virtual-airlines/smartcars/`](../../integrations/virtual-airlines/smartcars/)
- VAM: [`integrations/virtual-airlines/vam/`](../../integrations/virtual-airlines/vam/)

## Quick Start Code

### Python (SimTraffic/ECFMP)

```python
import json
from urllib.request import Request, urlopen

API_KEY = "swim_sys_your_key_here"
BASE_URL = "https://perti.vatcscc.org"

payload = json.dumps({
    "mode": "push",
    "flights": [{
        "callsign": "UAL123",
        "arrival": {"eta": "2026-03-06T17:15:00Z"}
    }]
}).encode()

req = Request(f"{BASE_URL}/api/swim/v1/ingest/simtraffic.php", data=payload, method="POST")
req.add_header("Authorization", f"Bearer {API_KEY}")
req.add_header("Content-Type", "application/json")

with urlopen(req) as resp:
    print(json.loads(resp.read()))
```

### C# (vNAS)

```csharp
var client = new HttpClient();
client.DefaultRequestHeaders.Authorization =
    new AuthenticationHeaderValue("Bearer", "swim_sys_your_key_here");

var payload = JsonSerializer.Serialize(new {
    facility_id = "ZDC",
    system_type = "ERAM",
    tracks = new[] { new { callsign = "UAL123", position = new { latitude = 40.64, longitude = -73.78 } } }
});

var response = await client.PostAsync(
    "https://perti.vatcscc.org/api/swim/v1/ingest/vnas/track.php",
    new StringContent(payload, Encoding.UTF8, "application/json"));
```

### JavaScript (vACDM)

```javascript
const response = await fetch('https://perti.vatcscc.org/api/swim/v1/ingest/cdm.php', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer swim_sys_your_key_here',
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        updates: [{
            callsign: 'BAW123',
            airport: 'EGLL',
            tobt: '2026-03-06T14:30:00Z',
            readiness_state: 'READY'
        }]
    })
});
```

## Connector Health

Monitor the health of all VATSWIM connectors:

```bash
# Lightweight aggregate status (any API key)
curl -H "Authorization: Bearer swim_dev_key" \
     https://perti.vatcscc.org/api/swim/v1/connectors/health.php

# Detailed per-connector status (system/partner key)
curl -H "Authorization: Bearer swim_sys_key" \
     https://perti.vatcscc.org/api/swim/v1/connectors/status.php
```

## Additional Resources

- [Full API Documentation](VATSWIM_API_Documentation.md)
- [FIXM Field Mapping](VATSWIM_FIXM_Field_Mapping.md)
- [SimTraffic Integration](SimTraffic_VATSWIM_Integration.md)
- [vNAS Integration](vNAS_VATSWIM_Integration.md)
- [ACARS Integration](ACARS_VATSWIM_Integration.md)
- [Simulator Integration](Simulator_VATSWIM_Integration.md)
- [Client SDKs](../../sdk/) (Python, C#, Java, JavaScript)
- [OpenAPI Spec](openapi.yaml)
