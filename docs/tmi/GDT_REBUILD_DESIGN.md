# GDT System Rebuild Design Document

**Version:** 1.0  
**Date:** January 17, 2026  
**Status:** Draft for Review

---

## 1. Overview

This document outlines the design for rebuilding Ground Delay Tool (GDT) functionality using the new VATSIM_TMI database schema. The system supports three program types:

| Type | Description | Slots? | CTD/CTA? |
|------|-------------|--------|----------|
| **GS** | Ground Stop - No departures allowed | No | No |
| **GDP** | Ground Delay Program - Metered arrivals | Yes | Yes |
| **AFP** | Airspace Flow Program - Metered through FCA | Yes | Yes |

---

## 2. Program Lifecycle

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          GDT PROGRAM LIFECYCLE                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌──────────┐      ┌──────────┐      ┌──────────┐      ┌──────────┐      │
│   │  CREATE  │ ───► │ SIMULATE │ ───► │ ACTIVATE │ ───► │  ACTIVE  │      │
│   │ (DRAFT)  │      │(PROPOSED)│      │          │      │          │      │
│   └──────────┘      └──────────┘      └──────────┘      └────┬─────┘      │
│                            │                                  │            │
│                            │                    ┌─────────────┴──────────┐ │
│                            ▼                    ▼            ▼           │ │
│                     ┌──────────┐         ┌──────────┐ ┌──────────┐       │ │
│                     │  CANCEL  │         │  EXTEND  │ │  REVISE  │       │ │
│                     │          │         │          │ │  (rates) │       │ │
│                     └──────────┘         └──────────┘ └──────────┘       │ │
│                                                                           │ │
│                                          ┌──────────┐                     │ │
│                                          │  PURGE   │◄────────────────────┘ │
│                                          │(COMPLETE)│                       │
│                                          └──────────┘                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Status Transitions

| From | To | Trigger | Notes |
|------|----|---------|-------|
| - | PROPOSED | Create | Initial creation with simulation |
| PROPOSED | ACTIVE | Activate | Generates slots (GDP/AFP), marks flights |
| PROPOSED | CANCELLED | Cancel | Before activation |
| ACTIVE | ACTIVE | Extend | Updates end_utc, regenerates slots |
| ACTIVE | ACTIVE | Revise | Updates rates, rebalances slots |
| ACTIVE | COMPLETED | Purge | Normal end, freezes data |
| ACTIVE | CANCELLED | Cancel | Emergency cancellation |

---

## 3. API Endpoint Structure

### 3.1 Primary GDT API

**Base URL:** `/api/tmi/gdt/`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/tmi/gdt/` | GET | API info and status |
| `/api/tmi/gdt/programs.php` | GET/POST | List/create programs |
| `/api/tmi/gdt/programs.php?id=X` | GET/PUT/DELETE | Single program CRUD |
| `/api/tmi/gdt/simulate.php` | POST | Simulate program (preview) |
| `/api/tmi/gdt/activate.php` | POST | Activate proposed program |
| `/api/tmi/gdt/extend.php` | POST | Extend active program |
| `/api/tmi/gdt/revise.php` | POST | Revise rates on active program |
| `/api/tmi/gdt/purge.php` | POST | End/purge active program |
| `/api/tmi/gdt/cancel.php` | POST | Cancel program |

### 3.2 Slot Management API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/tmi/gdt/slots.php` | GET | List slots for a program |
| `/api/tmi/gdt/slots.php?id=X` | GET/PUT | Single slot operations |
| `/api/tmi/gdt/assign.php` | POST | Assign flight to slot |
| `/api/tmi/gdt/swap.php` | POST | Swap two flight slots |
| `/api/tmi/gdt/bridge.php` | POST | Bridge slot (SCS) |
| `/api/tmi/gdt/compress.php` | POST | Compress unused slots |

### 3.3 Flight Query API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/tmi/gdt/flights.php` | GET | Flights affected by program |
| `/api/tmi/gdt/demand.php` | GET | Demand analysis for airport |
| `/api/tmi/gdt/impact.php` | GET | Delay impact analysis |

---

## 4. Core Operations

### 4.1 Ground Stop (GS)

**Create GS:**
```json
POST /api/tmi/gdt/programs.php
{
  "program_type": "GS",
  "ctl_element": "KJFK",
  "start_utc": "2026-01-17T15:00:00Z",
  "end_utc": "2026-01-17T17:00:00Z",
  "scope_json": {
    "type": "TIER",
    "tier": 1,
    "exempt_airborne": true,
    "exempt_within_min": 45
  },
  "impacting_condition": "WEATHER",
  "cause_text": "Low visibility"
}
```

**GS Scope Options:**
- `TIER`: Predefined geographic tiers (1, 2, 3)
- `DISTANCE`: Circular radius in NM
- `CENTERS`: Specific ARTCCs
- `MANUAL`: Custom airport list

**GS Logic (No Slots):**
1. Identify flights matching scope + destination
2. Apply exemptions (airborne, within X minutes, carriers)
3. Mark flights as `GS_STOPPED` in VATSIM_ADL
4. Track compliance (did they depart anyway?)

### 4.2 Ground Delay Program (GDP)

**Create GDP:**
```json
POST /api/tmi/gdt/programs.php
{
  "program_type": "GDP-DAS",
  "ctl_element": "KJFK",
  "start_utc": "2026-01-17T15:00:00Z",
  "end_utc": "2026-01-17T20:00:00Z",
  "program_rate": 30,
  "reserve_rate": 2,
  "delay_limit_min": 180,
  "scope_json": {
    "type": "TIER",
    "tier": 2,
    "exempt_airborne": true
  },
  "exemptions_json": {
    "carriers": ["LIFEGUARD", "MEDEVAC"],
    "aircraft_types": [],
    "origins": []
  }
}
```

**GDP Types:**
- `GDP-DAS`: Delay Assignment - first-scheduled, first-served
- `GDP-GAAP`: Compression - gaps filled automatically
- `GDP-UDP`: User-specified delays - airlines pick slots

**GDP Slot Generation:**
1. Calculate total slots = (end - start) hours × rate
2. Generate slot times at even intervals
3. Reserve X slots per hour for pop-ups
4. Query eligible flights from VATSIM_ADL
5. Assign slots by ETA order (DAS) or demand modeling
6. Calculate CTD = slot_time - flight_time

### 4.3 Airspace Flow Program (AFP)

Similar to GDP but:
- `ctl_element` is an FCA (Flow Control Area) instead of airport
- Meters flights through a geographic area
- Slots based on FCA entry time, not arrival time

---

## 5. Scope Definition Schema

The `scope_json` field defines which flights are included:

```json
{
  "type": "TIER|DISTANCE|CENTERS|MANUAL",
  
  // For TIER scope
  "tier": 1,  // 1, 2, or 3
  
  // For DISTANCE scope
  "distance_nm": 400,
  "center_lat": 40.6413,
  "center_lon": -73.7781,
  
  // For CENTERS scope
  "centers": ["ZBW", "ZNY", "ZDC", "ZOB"],
  
  // For MANUAL scope
  "origins": ["KBOS", "KORD", "KDFW"],
  
  // Common exemptions
  "exempt_airborne": true,
  "exempt_within_min": 45,
  "exempt_carriers": ["LIFEGUARD"],
  "exempt_origins": [],
  
  // Filters
  "include_carriers": null,  // null = all
  "include_aircraft_types": "ALL",  // ALL, JET, PROP, TURBOPROP
  "arrival_fix": null  // null = all arrival fixes
}
```

### Tier Definitions (VATSIM-specific)

| Tier | Description | Approx Distance |
|------|-------------|-----------------|
| 1 | Adjacent facilities | ~200nm |
| 2 | Regional (Northeast, etc.) | ~500nm |
| 3 | National (CONUS-wide) | Unlimited |

These should be defined in a lookup table or config.

---

## 6. Stored Procedures

### 6.1 Program Management

| Procedure | Purpose |
|-----------|---------|
| `sp_GDT_CreateProgram` | Create new program (any type) |
| `sp_GDT_SimulateProgram` | Preview without activating |
| `sp_GDT_ActivateProgram` | Activate and generate slots |
| `sp_GDT_ExtendProgram` | Extend end time |
| `sp_GDT_ReviseProgram` | Update rates |
| `sp_GDT_PurgeProgram` | Complete program |
| `sp_GDT_CancelProgram` | Cancel program |

### 6.2 Slot Management

| Procedure | Purpose |
|-----------|---------|
| `sp_GDT_GenerateSlots` | Create slots for GDP/AFP |
| `sp_GDT_AssignFlights` | Initial flight assignment |
| `sp_GDT_AssignSlot` | Assign single flight to slot |
| `sp_GDT_SwapSlots` | Swap two flights |
| `sp_GDT_BridgeSlot` | Move flight to later slot |
| `sp_GDT_CompressSlots` | Fill gaps |
| `sp_GDT_ReleaseSlot` | Unassign flight from slot |

### 6.3 Query Functions

| Function | Purpose |
|----------|---------|
| `fn_GDT_GetEligibleFlights` | Flights matching scope |
| `fn_GDT_CalculateCTD` | CTD from slot time |
| `fn_GDT_GetDemandByHour` | Demand analysis |
| `fn_GDT_GetDelayStats` | Delay metrics |

---

## 7. Data Flow

### 7.1 Create → Simulate → Activate

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Client    │     │  GDT API    │     │ VATSIM_TMI  │     │ VATSIM_ADL  │
└──────┬──────┘     └──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │                   │                   │                   │
       │  POST /programs   │                   │                   │
       │──────────────────►│                   │                   │
       │                   │  INSERT program   │                   │
       │                   │──────────────────►│                   │
       │                   │                   │                   │
       │  POST /simulate   │                   │                   │
       │──────────────────►│  Query flights    │                   │
       │                   │───────────────────┼──────────────────►│
       │                   │                   │    Flight data    │
       │                   │◄──────────────────┼───────────────────│
       │                   │  Calculate slots  │                   │
       │                   │  (in memory)      │                   │
       │   Preview data    │                   │                   │
       │◄──────────────────│                   │                   │
       │                   │                   │                   │
       │  POST /activate   │                   │                   │
       │──────────────────►│  UPDATE status    │                   │
       │                   │──────────────────►│                   │
       │                   │  INSERT slots     │                   │
       │                   │──────────────────►│                   │
       │                   │  Mark flights     │                   │
       │                   │───────────────────┼──────────────────►│
       │   Active program  │                   │                   │
       │◄──────────────────│                   │                   │
       │                   │                   │                   │
```

### 7.2 Cross-Database Interaction

**VATSIM_TMI stores:**
- Program definitions
- Slot allocations
- Events/audit log

**VATSIM_ADL stores:**
- Flight data (read-only for GDT)
- Flight TMI assignments (`adl_flight_tmi` table)

**Query pattern:**
```sql
-- Get flights for GDP at KJFK with Tier 2 scope
SELECT f.*
FROM VATSIM_ADL.dbo.adl_flight_core f
WHERE f.dest = 'KJFK'
  AND f.dep IN (SELECT airport FROM tier_2_airports)
  AND f.status NOT IN ('ARRIVED', 'CANCELLED')
  AND f.eta_utc BETWEEN @start_utc AND @end_utc
ORDER BY f.eta_utc;
```

---

## 8. Implementation Phases

### Phase 1: Core Infrastructure (This Session)
- [ ] Create `/api/tmi/gdt/` directory structure
- [ ] Create `common.php` with shared utilities
- [ ] Create `programs.php` (basic CRUD via tmi_programs)
- [ ] Create scope validation functions

### Phase 2: Ground Stop
- [ ] `sp_GDT_CreateProgram` (GS variant)
- [ ] `sp_GDT_ActivateProgram` (GS - marks flights)
- [ ] `sp_GDT_PurgeProgram`
- [ ] Flight marking in VATSIM_ADL
- [ ] `/api/tmi/gdt/activate.php`
- [ ] `/api/tmi/gdt/purge.php`

### Phase 3: GDP Simulation
- [ ] `sp_GDT_GenerateSlots`
- [ ] `sp_GDT_SimulateProgram`
- [ ] `/api/tmi/gdt/simulate.php`
- [ ] Demand analysis queries

### Phase 4: GDP Activation
- [ ] `sp_GDT_AssignFlights`
- [ ] CTD/CTA calculation
- [ ] `/api/tmi/gdt/slots.php`
- [ ] Flight assignment to slots

### Phase 5: GDP Operations
- [ ] Slot swap/bridge/compress
- [ ] Extension handling
- [ ] Rate revision
- [ ] Pop-up flight handling

### Phase 6: AFP (Future)
- [ ] FCA definition
- [ ] FCA-based slot generation
- [ ] Metering point calculations

---

## 9. Questions for HP

Before proceeding, please confirm:

1. **Tier Definitions:** Should we create a `gdt_tiers` config table, or hardcode tier→airport mappings?

2. **Flight Marking:** The `adl_flight_tmi` table in VATSIM_ADL - does it exist, or do we need to create it?

3. **Slot Naming:** FSM uses format `KJFK.091530A` (airport.DDHHMM + letter). Should we follow this?

4. **Rate Granularity:** 
   - Simple: One rate for entire program
   - Hourly: Different rate each hour (rates_hourly_json)
   - 15-min: Different rate each quarter (most granular)

5. **GS vs GDP Priority:** Which should we implement first? GS is simpler (no slots), GDP is more complex but more useful.

6. **Discord Integration:** Should activation/purge auto-post to Discord, or is that handled separately?

7. **VATSIM Data Refresh:** How often does VATSIM_ADL update? Do we need to handle flights appearing/disappearing during a program?

---

## 10. Next Steps

Once questions are answered:

1. Create the `/api/tmi/gdt/` directory structure
2. Build `common.php` with scope validation
3. Start with GS (simpler) or GDP (more useful) based on priority
4. Create stored procedures incrementally
5. Test with real VATSIM flight data

---

*Created: January 17, 2026*
