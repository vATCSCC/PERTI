# TMI API

Traffic Management Initiative APIs for Ground Stops, GDPs, and related operations.

---

## Database Architecture

TMI data is stored in two databases:

| Database | Purpose | Status |
|----------|---------|--------|
| **VATSIM_TMI** | Unified GDT operations (new) | Active |
| **VATSIM_ADL** | Legacy NTML tables | Being migrated |

The new `/api/gdt/` endpoints use `VATSIM_TMI.tmi_programs` while legacy `/api/tmi/gs/` endpoints still use `VATSIM_ADL.ntml`. Migration is ongoing.

---

## GDT API (Unified - Recommended)

The new GDT API provides unified access to all TMI program types using the `VATSIM_TMI` database.

**Base Path:** `/api/gdt/`

### Program Operations

#### POST /api/gdt/programs/create.php

Creates a new GS/GDP/AFP program.

**Request:**

```json
{
  "ctl_element": "KJFK",
  "element_type": "APT",
  "program_type": "GS",
  "start_utc": "2026-01-26T14:00:00Z",
  "end_utc": "2026-01-26T18:00:00Z",
  "scope_type": "TIER",
  "scope_tier": 1,
  "impacting_condition": "WEATHER",
  "cause_text": "Thunderstorms"
}
```

**Response:**

```json
{
  "success": true,
  "program_id": 42,
  "program_guid": "a1b2c3d4-...",
  "status": "PROPOSED"
}
```

#### GET /api/gdt/programs/list.php

Lists programs with optional filtering.

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | PROPOSED, ACTIVE, COMPLETED, PURGED, all |
| `ctl_element` | string | Filter by airport/FCA |
| `program_type` | string | GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP |

#### GET /api/gdt/programs/get.php

Gets single program with slots and counts.

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Program ID (required) |

#### POST /api/gdt/programs/simulate.php

Generates slots and runs RBS (Ration by Schedule) assignment.

**Request:**

```json
{
  "program_id": 42
}
```

**Response:**

```json
{
  "success": true,
  "slots_created": 24,
  "flights_modeled": 47,
  "avg_delay_min": 35.5
}
```

#### POST /api/gdt/programs/activate.php

Activates a proposed/modeled program.

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Program ID (required) |

#### POST /api/gdt/programs/extend.php

Extends program end time.

**Request:**

```json
{
  "program_id": 42,
  "new_end_utc": "2026-01-26T20:00:00Z"
}
```

#### POST /api/gdt/programs/purge.php

Cancels/purges a program.

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Program ID (required) |

#### POST /api/gdt/programs/transition.php

Transitions a Ground Stop to GDP.

**Request:**

```json
{
  "program_id": 42,
  "new_type": "GDP-DAS",
  "program_rate": 32
}
```

### Flight Operations

#### GET /api/gdt/flights/list.php

Lists flights assigned to a program.

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Program ID (required) |
| `status` | string | Filter by control status |

**Response:**

```json
{
  "success": true,
  "flights": [
    {
      "flight_uid": 12345,
      "callsign": "DAL123",
      "dep_airport": "KATL",
      "arr_airport": "KJFK",
      "ctd_utc": "2026-01-26T15:30:00Z",
      "cta_utc": "2026-01-26T17:45:00Z",
      "aslot": "KJFK.261745A",
      "program_delay_min": 45,
      "ctl_exempt": false,
      "gs_held": true
    }
  ]
}
```

### Slot Operations

#### GET /api/gdt/slots/list.php

Lists slots for a program.

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Program ID (required) |
| `status` | string | Filter: OPEN, ASSIGNED, BRIDGED, HELD |

**Response:**

```json
{
  "success": true,
  "slots": [
    {
      "slot_id": 1001,
      "slot_name": "KJFK.261530A",
      "slot_time_utc": "2026-01-26T15:30:00Z",
      "slot_type": "REGULAR",
      "slot_status": "ASSIGNED",
      "assigned_callsign": "DAL123"
    }
  ]
}
```

### Demand Operations

#### GET /api/gdt/demand/hourly.php

Gets hourly demand/capacity data.

| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | Program ID |
| `airport` | string | Airport ICAO (alternative) |
| `hours` | int | Hours to forecast (default: 6) |

---

## Legacy Ground Stop APIs

> **Note:** These endpoints use `VATSIM_ADL.ntml`. Consider migrating to `/api/gdt/` endpoints.

### POST /api/tmi/gs/create.php

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
