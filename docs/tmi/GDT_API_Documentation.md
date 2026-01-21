# GDT API Documentation

## Overview

The Ground Delay Tools (GDT) API provides endpoints for managing Traffic Management Initiatives (TMIs) including Ground Stops (GS), Ground Delay Programs (GDP), and Airspace Flow Programs (AFP).

**Base URL:** `/api/gdt/`

**Database:** `VATSIM_TMI` (Azure SQL Server)

**Version:** 1.0.0  
**Date:** 2026-01-21

---

## Authentication

Currently, the API does not require authentication. Future versions will implement VATSIM OAuth.

---

## Endpoints

### Programs

#### Create Program
`POST /api/gdt/programs/create.php`

Creates a new GS/GDP/AFP program in PROPOSED status.

**Request Body:**
```json
{
  "ctl_element": "KJFK",              // Required: destination airport
  "program_type": "GS",               // Required: GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP
  "start_utc": "2026-01-21T15:00:00", // Required: program start
  "end_utc": "2026-01-21T18:00:00",   // Required: program end
  "element_type": "APT",              // Optional: APT, CTR, FCA (default: APT)
  "program_rate": 30,                 // Optional: arrivals/hour (GDP/AFP)
  "reserve_rate": 5,                  // Optional: reserved slots/hour (GAAP/UDP)
  "delay_limit_min": 180,             // Optional: max delay cap
  "scope_json": {...},                // Optional: scope filters
  "impacting_condition": "WEATHER",   // Optional: WEATHER, VOLUME, RUNWAY
  "cause_text": "Thunderstorms",      // Optional: description
  "created_by": "username"            // Optional: creator
}
```

**Response:**
```json
{
  "status": "ok",
  "message": "Program created",
  "data": {
    "program_id": 1,
    "program_guid": "uuid...",
    "program": { ... }
  }
}
```

---

#### List Programs
`GET /api/gdt/programs/list.php`

Lists programs with optional filtering.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by status (PROPOSED, MODELING, ACTIVE, etc.) |
| `ctl_element` | string | Filter by destination airport |
| `program_type` | string | Filter by type (GS, GDP-DAS, etc.) |
| `active_only` | "1" | Show only active programs |
| `include_completed` | "1" | Include completed programs |
| `limit` | int | Max records (default: 50, max: 200) |
| `offset` | int | Pagination offset |

---

#### Get Program
`GET /api/gdt/programs/get.php?program_id=1`

Returns a single program with optional slot and count data.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | **Required.** Program ID |
| `include_slots` | "1" | Include slot allocation |
| `include_counts` | "0" | Exclude counts (default: included) |

---

#### Simulate Program
`POST /api/gdt/programs/simulate.php`

Generates slots and runs RBS (Ration By Schedule) assignment algorithm.

**Request Body:**
```json
{
  "program_id": 1,
  "scope": {
    "origin_centers": ["ZNY", "ZDC"],
    "origin_airports": ["KJFK"],
    "carriers": ["AAL", "DAL"],
    "aircraft_type": "ALL",
    "distance_nm": 500
  },
  "exemptions": {
    "airborne": true,
    "departing_within_min": 30,
    "origins": ["KLGA"],
    "callsigns": ["AAL100"]
  }
}
```

**Response:**
```json
{
  "status": "ok",
  "message": "Simulation complete",
  "data": {
    "program_id": 1,
    "slot_count": 60,
    "assigned_count": 45,
    "exempt_count": 5,
    "summary": { ... },
    "flights": [ ... ],
    "slots": [ ... ]
  }
}
```

---

#### Activate Program
`POST /api/gdt/programs/activate.php`

Activates a PROPOSED or MODELING program.

**Request Body:**
```json
{
  "program_id": 1,
  "activated_by": "username"
}
```

---

#### Extend Program
`POST /api/gdt/programs/extend.php`

Extends a program's end time and generates additional slots.

**Request Body:**
```json
{
  "program_id": 1,
  "new_end_utc": "2026-01-21T20:00:00",
  "extended_by": "username"
}
```

---

#### Purge Program
`POST /api/gdt/programs/purge.php`

Cancels/purges a program and releases held flights.

**Request Body:**
```json
{
  "program_id": 1,
  "purge_reason": "Weather improved",
  "purged_by": "username"
}
```

---

#### Transition GS to GDP
`POST /api/gdt/programs/transition.php`

Transitions a Ground Stop to a GDP program.

**Request Body:**
```json
{
  "gs_program_id": 1,
  "gdp_type": "GDP-DAS",
  "gdp_end_utc": "2026-01-21T20:00:00",
  "program_rate": 30,
  "reserve_rate": 5,
  "delay_limit_min": 180,
  "transitioned_by": "username"
}
```

---

### Flights

#### List Flights
`GET /api/gdt/flights/list.php?program_id=1`

Lists flights assigned to a program.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | **Required.** Program ID |
| `include_exempt` | "0" | Exclude exempt flights (default: included) |
| `status` | string | Filter: CONTROLLED, EXEMPT, GS_HELD |
| `dep_airport` | string | Filter by departure airport |
| `dep_center` | string | Filter by departure ARTCC |
| `carrier` | string | Filter by carrier |
| `limit` | int | Max records (default: 500) |
| `offset` | int | Pagination offset |

---

### Slots

#### List Slots
`GET /api/gdt/slots/list.php?program_id=1`

Lists slots for a program.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | **Required.** Program ID |
| `status` | string | Filter: OPEN, ASSIGNED, BRIDGED |
| `type` | string | Filter: REGULAR, RESERVED |
| `bin_hour` | int | Filter by hour (0-23) |
| `limit` | int | Max records (default: 500) |
| `offset` | int | Pagination offset |

---

### Demand

#### Hourly Demand
`GET /api/gdt/demand/hourly.php?program_id=1`

Returns demand and capacity data by hour.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `program_id` | int | **Required.** Program ID |
| `include_quarter` | "1" | Include 15-minute breakdown |

---

## Program Types

| Type | Name | Has Slots | Has Rates |
|------|------|-----------|-----------|
| `GS` | Ground Stop | No | No |
| `GDP-DAS` | GDP - Delay Assignment System | Yes | Yes |
| `GDP-GAAP` | GDP - General Aviation Airport Program | Yes | Yes (+reserve) |
| `GDP-UDP` | GDP - Unified Delay Program | Yes | Yes (+reserve) |
| `AFP` | Airspace Flow Program | Yes | Yes |

---

## Program Statuses

| Status | Description |
|--------|-------------|
| `PROPOSED` | Created but not activated |
| `MODELING` | Slots generated, being modeled |
| `ACTIVE` | Live program |
| `EXTENDED` | Extended from original end |
| `SUPERSEDED` | Replaced by revision |
| `COMPLETED` | Finished normally |
| `CANCELLED` | Cancelled before completion |
| `PURGED` | Cancelled and controls cleared |

---

## Stored Procedures Used

| Procedure | Called By |
|-----------|-----------|
| `sp_TMI_CreateProgram` | programs/create.php |
| `sp_TMI_GenerateSlots` | programs/simulate.php |
| `sp_TMI_AssignFlightsRBS` | programs/simulate.php |
| `sp_TMI_ApplyGroundStop` | programs/simulate.php (GS) |
| `sp_TMI_ActivateProgram` | programs/activate.php |
| `sp_TMI_ExtendProgram` | programs/extend.php |
| `sp_TMI_PurgeProgram` | programs/purge.php |
| `sp_TMI_TransitionGStoGDP` | programs/transition.php |

---

## Error Responses

All errors return JSON with this structure:

```json
{
  "status": "error",
  "message": "Human-readable error message",
  "errors": [ ... ]  // Optional: SQL Server errors
}
```

**HTTP Status Codes:**
- `200` - Success
- `201` - Created
- `400` - Bad request (validation error)
- `404` - Resource not found
- `405` - Method not allowed
- `500` - Server error

---

## Related Documentation

- [GDT Unified Design Document](GDT_Unified_Design_Document_v1.md)
- [GDT Phase 1 Transition](GDT_Phase1_Transition.md)
- [GDT Incremental Migration](GDT_Incremental_Migration.md)
- [TMI Documentation Index](TMI_Documentation_Index.md)
