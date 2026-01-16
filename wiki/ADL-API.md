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

## Airspace Element Demand APIs (v17)

Query traffic demand at navigation fixes, airway segments, and route segments.

### GET /api/adl/demand/fix.php

Returns flights passing through a specific navigation fix.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `fix` | string | Navigation fix identifier (required) |
| `minutes` | int | Time window in minutes (default: 60, max: 720) |
| `dep_tracon` | string | Filter by departure TRACON |
| `arr_tracon` | string | Filter by arrival TRACON |
| `format` | string | 'list' (default) or 'count' |

**Example:**

```
GET /api/adl/demand/fix?fix=MERIT&minutes=45&dep_tracon=N90
```

**Response:**

```json
{
  "fix": "MERIT",
  "time_window_minutes": 45,
  "filters": { "dep_tracon": "N90" },
  "count": 12,
  "generated_utc": "2026-01-15T14:30:00Z",
  "flights": [
    {
      "flight_uid": "abc123",
      "callsign": "DAL456",
      "departure": "KLGA",
      "destination": "KORD",
      "aircraft_type": "B739",
      "eta_at_fix": "2026-01-15T14:45:00Z",
      "minutes_until_fix": 15,
      "phase": "cruise"
    }
  ]
}
```

---

### GET /api/adl/demand/airway.php

Returns flights on an airway segment between two fixes.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `airway` | string | Airway identifier (required, e.g., J48, V1) |
| `from_fix` | string | Segment start fix (required) |
| `to_fix` | string | Segment end fix (required) |
| `minutes` | int | Time window in minutes (default: 60, max: 720) |
| `format` | string | 'list' (default) or 'count' |

**Example:**

```
GET /api/adl/demand/airway?airway=J48&from_fix=LANNA&to_fix=MOL&minutes=180
```

**Response:**

```json
{
  "airway": "J48",
  "segment": { "from": "LANNA", "to": "MOL" },
  "time_window_minutes": 180,
  "count": 8,
  "generated_utc": "2026-01-15T14:30:00Z",
  "flights": [
    {
      "flight_uid": "def456",
      "callsign": "UAL789",
      "departure": "KBOS",
      "destination": "KORD",
      "entry_eta": "2026-01-15T15:00:00Z",
      "exit_eta": "2026-01-15T15:12:00Z",
      "segment_minutes": 12,
      "direction": "forward"
    }
  ]
}
```

---

### GET /api/adl/demand/segment.php

Returns flights passing through two fixes in sequence (airway or direct).

Unlike the airway endpoint, this does not require flights to have filed via an airway. It finds any flights whose parsed route includes both fixes, useful for VATSIM where pilots often file direct (DCT) routes.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `from_fix` | string | First fix (required) |
| `to_fix` | string | Second fix (required) |
| `minutes` | int | Time window in minutes (default: 60, max: 720) |
| `format` | string | 'list' (default) or 'count' |

**Example:**

```
GET /api/adl/demand/segment?from_fix=CAM&to_fix=GONZZ&minutes=180
```

**Response:**

```json
{
  "segment": { "from": "CAM", "to": "GONZZ" },
  "time_window_minutes": 180,
  "count": 5,
  "generated_utc": "2026-01-15T22:00:00Z",
  "flights": [
    {
      "flight_uid": "ghi789",
      "callsign": "AAL123",
      "departure": "KMIA",
      "destination": "KJFK",
      "entry_eta": "2026-01-15T22:15:00Z",
      "exit_eta": "2026-01-15T22:28:00Z",
      "segment_minutes": 13,
      "direction": "forward",
      "on_airway": "J48"
    }
  ]
}
```

---

## See Also

- [[API Reference]] - Complete API overview
- [[TMI API]] - Traffic management APIs
- [[Data Flow]] - How flight data is processed
