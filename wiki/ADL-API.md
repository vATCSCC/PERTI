# ADL API

The Aggregate Demand List (ADL) API provides access to real-time and historical flight data.

---

## Endpoints

### GET /api/adl/current.php

Returns current flight snapshot.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `dest` | string | Filter by destination (ICAO) |
| `orig` | string | Filter by origin (ICAO) |
| `artcc` | string | Filter by ARTCC |
| `airline` | string | Filter by airline code |
| `phase` | string | Filter by flight phase |
| `limit` | int | Max results (default: 1000) |

**Example:**

```
GET /api/adl/current.php?dest=KJFK&phase=cruise
```

**Response:**

```json
{
  "success": true,
  "timestamp": "2026-01-10T14:30:00Z",
  "count": 47,
  "flights": [
    {
      "id": 12345,
      "callsign": "DAL123",
      "departure": "KATL",
      "arrival": "KJFK",
      "aircraft": "B739",
      "altitude": 35000,
      "groundspeed": 480,
      "latitude": 39.5234,
      "longitude": -74.1234,
      "heading": 045,
      "phase": "cruise",
      "eta": "2026-01-10T15:45:00Z",
      "route": "KAJIN J209 SBJ J60 RBV"
    }
  ]
}
```

---

### GET /api/adl/flight.php

Returns detailed single flight information.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Flight ID |
| `callsign` | string | Flight callsign |

**Response includes:**
- Full flight details
- Parsed route waypoints
- Trajectory history
- EDCT if assigned

---

### GET /api/adl/stats.php

Returns aggregate flight statistics.

**Response:**

```json
{
  "success": true,
  "stats": {
    "total_flights": 1523,
    "by_phase": {
      "preflight": 234,
      "taxi_out": 45,
      "departure": 89,
      "cruise": 892,
      "descent": 156,
      "approach": 67,
      "taxi_in": 40
    },
    "by_artcc": {
      "ZNY": 145,
      "ZDC": 132,
      "ZTL": 198
    }
  }
}
```

---

### GET /api/adl/snapshot_history.php

Returns historical flight snapshots.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `from` | datetime | Start time (ISO 8601) |
| `to` | datetime | End time (ISO 8601) |
| `airport` | string | Filter by airport |
| `interval` | int | Snapshot interval (minutes) |

---

### GET /api/adl/trajectory.php

Returns flight trajectory points.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `flight_id` | int | Flight ID |
| `from` | datetime | Start time |
| `to` | datetime | End time |

---

### GET /api/adl/crossings.php

Returns boundary crossing data.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `boundary` | string | ARTCC or sector ID |
| `from` | datetime | Start time |
| `to` | datetime | End time |
| `direction` | string | entry, exit, or both |

---

## Flight Phases

| Phase | Description |
|-------|-------------|
| `preflight` | Flight plan filed, not moving |
| `taxi_out` | Moving on ground pre-departure |
| `departure` | Airborne, climbing |
| `cruise` | Level flight |
| `descent` | Descending |
| `approach` | Final approach |
| `taxi_in` | On ground post-arrival |
| `arrived` | Parked at gate |

---

## Error Responses

| Code | Description |
|------|-------------|
| 400 | Invalid parameters |
| 401 | Authentication required |
| 404 | Flight not found |
| 500 | Server error |

---

## See Also

- [[API Reference]] - Complete API overview
- [[TMI API]] - Traffic management APIs
- [[Data Flow]] - How flight data is processed
