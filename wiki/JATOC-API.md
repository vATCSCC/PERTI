# JATOC API

APIs for managing ATC incidents in the Joint Air Traffic Operations Command system.

---

## Endpoints

### GET /api/jatoc/incidents.php

Returns list of incidents.

**Access:** Public

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `status` | string | active, resolved, all |
| `type` | string | atc_zero, atc_alert, atc_limited, non_responsive |
| `facility` | string | Filter by facility code |
| `from` | datetime | Start date (ISO 8601) |
| `to` | datetime | End date (ISO 8601) |
| `limit` | int | Max results |

**Response:**

```json
{
  "success": true,
  "count": 3,
  "incidents": [
    {
      "id": 456,
      "facility": "ZNY",
      "type": "atc_limited",
      "ops_level": 2,
      "description": "Reduced staffing",
      "started_at": "2026-01-10T14:00:00Z",
      "expected_duration": 120,
      "status": "active"
    }
  ]
}
```

---

### GET /api/jatoc/incident.php

Returns single incident with full details.

**Access:** Public

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Incident ID (required) |

**Response includes:**
- Full incident details
- Update timeline
- Related TMIs
- Resolution notes (if resolved)

---

### POST /api/jatoc/incident.php

Creates a new incident.

**Access:** Authenticated (DCC role)

**Request:**

```json
{
  "facility": "ZNY",
  "type": "atc_limited",
  "ops_level": 2,
  "description": "Reduced staffing - expect delays",
  "expected_duration": 120,
  "affected_area": "New York TRACON airspace"
}
```

**Response:**

```json
{
  "success": true,
  "id": 456,
  "message": "Incident created"
}
```

---

### PUT /api/jatoc/incident.php

Updates an existing incident.

**Access:** Authenticated (DCC role)

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Incident ID (required) |

**Request:**

```json
{
  "ops_level": 1,
  "update_note": "Staffing restored to normal",
  "status": "resolved"
}
```

---

### DELETE /api/jatoc/incident.php

Deletes an incident (admin only).

**Access:** Authenticated (Admin role)

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Incident ID (required) |

---

### GET /api/jatoc/ops_level.php

Returns current NAS operations level.

**Access:** Public

**Response:**

```json
{
  "success": true,
  "ops_level": 1,
  "active_incidents": 0,
  "last_updated": "2026-01-10T14:30:00Z"
}
```

---

## Incident Types

| Type | Description |
|------|-------------|
| `atc_zero` | Complete suspension of ATC |
| `atc_alert` | Significant degradation |
| `atc_limited` | Reduced services |
| `non_responsive` | Communication issues |

---

## Operations Levels

| Level | Description |
|-------|-------------|
| 1 | Normal operations |
| 2 | Degraded - expect delays |
| 3 | Severely impacted |

---

## See Also

- [[JATOC]] - JATOC user interface
- [[API Reference]] - Complete API overview
- [[NOD Dashboard]] - Operations dashboard
