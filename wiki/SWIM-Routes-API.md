# SWIM Routes API

The SWIM Routes API provides public, read-only access to reference route data: **Coded Departure Routes (CDRs)** and **National Playbook plays**. Both endpoints serve the latest FAA/NASR data, reimported daily at 06:00Z.

**Base URL**: `https://perti.vatcscc.org/api/swim/v1`

**Authentication**: None required. Endpoints are public. An optional API key can be provided via `X-API-Key` header for rate-limit tracking.

**CORS**: `Access-Control-Allow-Origin: *` -- callable from any origin.

**Format**: JSON only.

---

## Coded Departure Routes

### GET /api/swim/v1/routes/cdrs

Returns paginated CDRs from the VATSIM_REF reference database. CDRs are pre-coordinated reroutes between airport pairs, used for traffic management rerouting and playbook operations.

**Source**: FAA NASR `cdrs.csv` (~41,000 routes)

**Parameters:**

| Name | Type | Required | Description | Example |
|------|------|----------|-------------|---------|
| `origin` | string | No | Filter by departure airport (ICAO, case-insensitive) | `KJFK` |
| `dest` | string | No | Filter by arrival airport (ICAO, case-insensitive) | `KORD` |
| `code` | string | No | CDR code prefix match | `JFKORD` |
| `search` | string | No | Free-text search across CDR code and route string | `GREKI` |
| `artcc` | string | No | Filter by departure OR arrival ARTCC (aliases: `fir`, `acc`) | `ZNY` |
| `dep_artcc` | string | No | Filter by departure ARTCC only (aliases: `dep_fir`, `dep_acc`) | `ZNY` |
| `arr_artcc` | string | No | Filter by arrival ARTCC only (aliases: `arr_fir`, `arr_acc`) | `ZAU` |
| `page` | int | No | Page number (default 1, min 1, max 5000) | `2` |
| `per_page` | int | No | Results per page (default 50, max 200) | `100` |

All filter parameters can be combined. For example, `?origin=KJFK&artcc=ZAU` returns CDRs departing JFK that involve ZAU airspace.

**Example Request:**

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KORD
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "cdr_id": 22663,
      "cdr_code": "JFKORD1K",
      "full_route": "KJFK GAYEL Q818 WOZEE KENPA OBSTR WYNDE3 KORD",
      "origin_icao": "KJFK",
      "dest_icao": "KORD",
      "dep_artcc": "ZNY",
      "arr_artcc": "ZAU",
      "direction": null,
      "altitude_min_ft": null,
      "altitude_max_ft": null,
      "is_active": true,
      "source": "NASR"
    },
    {
      "cdr_id": 22664,
      "cdr_code": "JFKORD1N",
      "full_route": "KJFK GAYEL Q818 WOZEE NOSIK ZOHAN OBSTR WYNDE3 KORD",
      "origin_icao": "KJFK",
      "dest_icao": "KORD",
      "dep_artcc": "ZNY",
      "arr_artcc": "ZAU",
      "direction": null,
      "altitude_min_ft": null,
      "altitude_max_ft": null,
      "is_active": true,
      "source": "NASR"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 50,
    "total": 23,
    "total_pages": 1,
    "has_more": false
  },
  "metadata": {
    "generated": "2026-03-13T19:40:40+00:00",
    "source": "vatsim_ref.coded_departure_routes"
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `cdr_id` | int | Unique CDR identifier |
| `cdr_code` | string | CDR code (e.g., `JFKORD1K`) |
| `full_route` | string | Full route string including departure, fixes, airways, and arrival |
| `origin_icao` | string | Departure airport ICAO code |
| `dest_icao` | string | Arrival airport ICAO code |
| `dep_artcc` | string | Responsible ARTCC for the departure airport (e.g., `ZNY`) |
| `arr_artcc` | string | Responsible ARTCC for the arrival airport (e.g., `ZAU`) |
| `direction` | string | Route direction indicator (if available) |
| `altitude_min_ft` | int | Minimum altitude in feet (if specified) |
| `altitude_max_ft` | int | Maximum altitude in feet (if specified) |
| `is_active` | bool | Whether the CDR is currently active |
| `source` | string | Data source identifier |

**Error Responses:**

| Code | When |
|------|------|
| 405 | Non-GET method used |
| 503 | VATSIM_REF database unavailable |

---

## Playbook Plays

### GET /api/swim/v1/playbook/plays

Returns paginated National Playbook plays with their associated routes. Each play represents a named traffic management scenario containing one or more reroutes.

**Source**: FAA National Playbook `playbook_routes.csv` (~1,800 plays, ~56,000 routes across 9 categories)

**Parameters:**

| Name | Type | Required | Description | Example |
|------|------|----------|-------------|---------|
| `id` | int | No | Specific play by ID | `4926` |
| `category` | string | No | FAA category filter | `Airports` |
| `source` | string | No | Data source filter | `FAA` |
| `search` | string | No | Free-text search across play name and routes | `ORD EAST` |
| `artcc` | string | No | Filter by ARTCC involved in any route (aliases: `fir`, `acc`) | `ZAU` |
| `status` | string | No | Play status filter | `active` |
| `format` | string | No | Response format: `summary` (no routes) or `full` | `full` |
| `page` | int | No | Page number (default 1) | `2` |
| `per_page` | int | No | Results per page (default 50, max 200) | `100` |

**Example Request:**

```
GET /api/swim/v1/playbook/plays?category=Airports&artcc=ZAU&per_page=2
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "play_id": 4960,
      "play_name": "ORD EAST 1",
      "display_name": null,
      "description": null,
      "category": "Airports",
      "impacted_area": "ZAU/ZBW/ZDC/ZID/ZNY/ZOB",
      "facilities_involved": "ZAU,ZBW,ZDC,ZID,ZNY,ZOB",
      "route_format": "standard",
      "source": "FAA",
      "status": "active",
      "route_count": 15,
      "visibility": "public",
      "routes": [
        {
          "route_id": 48201,
          "route_string": "KJFK GREKI JUDDS CAM NOVON KENPA OBSTR WYNDE3 KORD",
          "origin": "KJFK",
          "dest": "KORD",
          "origin_airports": "KJFK",
          "origin_tracons": "N90",
          "origin_artccs": "ZNY",
          "dest_airports": "KORD",
          "dest_tracons": "C80",
          "dest_artccs": "ZAU"
        }
      ]
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 2,
    "total": 34,
    "total_pages": 17,
    "has_more": true
  },
  "metadata": {
    "generated": "2026-03-13T20:00:00+00:00",
    "source": "perti_site.playbook_plays"
  }
}
```

**FAA Playbook Categories:**

| Category | Description |
|----------|-------------|
| Airports | Airport-specific reroutes (e.g., ORD EAST, ATL NO CHPPR) |
| East to West Transcon | Transcontinental eastbound-to-westbound routes |
| West to East Transcon | Transcontinental westbound-to-eastbound routes |
| Regional Routes | Regional reroute plays (e.g., FLORIDA TO NE, LAKE ERIE) |
| Snowbird | Seasonal north-south migration routes |
| Space Ops | Cape Canaveral launch reroutes |
| Special Ops | Special operations reroutes |
| SUA Activity | Reroutes for Special Use Airspace activity |
| Equipment | Equipment-specific routing plays |

---

## Pagination

Both endpoints return a `pagination` object:

| Field | Type | Description |
|-------|------|-------------|
| `page` | int | Current page number |
| `per_page` | int | Results per page |
| `total` | int | Total matching results |
| `total_pages` | int | Total number of pages |
| `has_more` | bool | Whether more pages exist after this one |

To iterate through all results, increment `page` until `has_more` is `false`.

---

## Data Freshness

Both CDR and playbook data are reimported daily at **06:00Z** by the `refdata_sync_daemon.php` daemon. The authoritative source files update with each AIRAC cycle (every 28 days). Consumers should cache results appropriately -- reference data rarely changes between AIRAC cycles.

The `metadata.generated` field in each response indicates when the response was built (not when the data was last imported).

---

## Common Use Cases

### Find all CDRs between two airports

```
GET /api/swim/v1/routes/cdrs?origin=KJFK&dest=KLAX
```

### Find CDRs involving a specific ARTCC

```
GET /api/swim/v1/routes/cdrs?artcc=ZNY
```

This returns CDRs where ZNY is either the departure or arrival ARTCC. Use `dep_artcc` or `arr_artcc` for one-sided filtering.

### Search CDRs by route fix

```
GET /api/swim/v1/routes/cdrs?search=GREKI
```

Returns all CDRs whose code or route string contains "GREKI" (543 results).

### Find playbook plays for a specific airport scenario

```
GET /api/swim/v1/playbook/plays?search=ORD EAST
```

### Get all plays involving an ARTCC

```
GET /api/swim/v1/playbook/plays?artcc=ZAU&format=full
```

### Download all CDRs (paginated)

```bash
page=1
while true; do
  response=$(curl -s "https://perti.vatcscc.org/api/swim/v1/routes/cdrs?page=$page&per_page=200")
  echo "$response" >> cdrs_all.json
  has_more=$(echo "$response" | jq -r '.pagination.has_more')
  if [ "$has_more" != "true" ]; then break; fi
  page=$((page + 1))
done
```

---

## Related Resources

- **OpenAPI Spec**: [docs/swim/openapi.yaml](../blob/main/docs/swim/openapi.yaml) -- machine-readable API specification
- **SWIM Documentation Portal**: [swim-docs.php](https://perti.vatcscc.org/swim-docs.php)
- **Playbook UI**: [playbook.php](https://perti.vatcscc.org/playbook.php) -- interactive playbook browser
- **Route Plotter**: [route.php](https://perti.vatcscc.org/route.php) -- MapLibre-based route visualization
- [[API Reference]] -- full API index
- [[Playbook]] -- internal playbook feature documentation
