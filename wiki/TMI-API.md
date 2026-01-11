# TMI API

Traffic Management Initiative APIs for Ground Stops, GDPs, and related operations.

---

## Ground Stop APIs

### POST /api/tmi/gs/create.php

Creates a new Ground Stop (proposed status).

**Request:**

```json
{
  "airport": "KJFK",
  "reason": "Weather",
  "scope": "tier1",
  "end_time": "2026-01-10T18:00:00Z",
  "notes": "Thunderstorms in terminal area"
}
```

**Response:**

```json
{
  "success": true,
  "gs_id": 123,
  "status": "proposed"
}
```

---

### POST /api/tmi/gs/model.php

Models affected flights for a proposed Ground Stop.

**Request:**

```json
{
  "gs_id": 123
}
```

**Response:**

```json
{
  "success": true,
  "affected_count": 47,
  "flights": [
    {
      "callsign": "DAL123",
      "origin": "KATL",
      "eta": "2026-01-10T16:30:00Z",
      "distance_nm": 450
    }
  ]
}
```

---

### POST /api/tmi/gs/activate.php

Activates a Ground Stop and issues EDCTs.

**Request:**

```json
{
  "gs_id": 123
}
```

**Response:**

```json
{
  "success": true,
  "status": "active",
  "edcts_issued": 47
}
```

---

### POST /api/tmi/gs/extend.php

Extends an active Ground Stop.

**Request:**

```json
{
  "gs_id": 123,
  "new_end": "2026-01-10T20:00:00Z"
}
```

---

### POST /api/tmi/gs/purge.php

Cancels/purges a Ground Stop.

**Request:**

```json
{
  "gs_id": 123
}
```

---

### GET /api/tmi/gs/list.php

Lists Ground Stop programs.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `status` | string | proposed, active, expired, all |
| `airport` | string | Filter by airport |

---

### GET /api/tmi/gs/get.php

Gets single Ground Stop details.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Ground Stop ID |

---

### GET /api/tmi/gs/flights.php

Gets flights affected by a Ground Stop.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `gs_id` | int | Ground Stop ID |
| `status` | string | Filter by compliance status |

---

### GET /api/tmi/gs/demand.php

Gets demand data for Ground Stop planning.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `airport` | string | Airport ICAO |
| `hours` | int | Hours to forecast |

---

## GDP APIs

### POST /api/tmi/gdp/create.php

Creates a Ground Delay Program.

**Request:**

```json
{
  "airport": "KJFK",
  "rate": 32,
  "reason": "Weather",
  "start_time": "2026-01-10T14:00:00Z",
  "end_time": "2026-01-10T20:00:00Z"
}
```

---

### POST /api/tmi/gdp/modify.php

Modifies GDP parameters.

---

### POST /api/tmi/gdp/cancel.php

Cancels a GDP.

---

## Scope Tiers

| Tier | Description |
|------|-------------|
| `tier1` | Flights within ~2 hours ETA |
| `tier2` | Flights within ~4 hours ETA |
| `tier3` | All flights to destination |
| `custom` | Custom distance/time criteria |

---

## GS Status Values

| Status | Description |
|--------|-------------|
| `proposed` | Created, not yet active |
| `active` | Currently in effect |
| `extended` | Extended past original end |
| `purged` | Canceled before expiration |
| `expired` | Ended naturally |

---

## See Also

- [[API Reference]] - Complete API overview
- [[GDT Ground Delay Tool]] - GDT user interface
- [[ADL API]] - Flight data APIs
