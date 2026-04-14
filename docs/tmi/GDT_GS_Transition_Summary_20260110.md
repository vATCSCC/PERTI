# GDT Ground Stop Implementation - Transition Summary
## Session: January 9-10, 2026

---

## Overview

This session implemented the database schema, stored procedures, and PHP API endpoints for the unified Ground Delay Tools (GDT) Ground Stop system, integrating with PERTI's normalized ADL 8-table architecture.

---

## Completed Work

### 1. Database Schema (Deployed & Tested ✅)

**Location:** `adl/migrations/tmi/`

| File | Status | Description |
|------|--------|-------------|
| `001_ntml_schema.sql` | ✅ Deployed | Creates `ntml`, `ntml_info`, `ntml_slots` tables; enhances `adl_flight_tmi` |
| `002_gs_procedures.sql` | ✅ Deployed | Ground Stop stored procedures (7 total) |
| `003_gdt_views.sql` | ✅ Deployed | GDT views for API queries (6 total) |
| `test_gs_workflow.sql` | ✅ Tested | Test script - verified full GS workflow |

**Tables Created:**
- `ntml` - National Traffic Management Log (program registry)
- `ntml_info` - Event log / audit trail
- `ntml_slots` - Arrival slot allocation (for GDP, future use)

**Columns Added to `adl_flight_tmi`:**
- `program_id`, `slot_id`, `aslot`, `octd_utc`, `octa_utc`, `ctl_prgm`
- `ctl_exempt`, `ctl_exempt_reason`, `program_delay_min`, `delay_capped`
- `sl_hold`, `subbable`, `gs_held`, `gs_release_utc`
- `is_popup`, `is_recontrol`, `popup_detected_utc`
- `ecr_pending`, `ecr_requested_cta`, `ecr_requested_by`, `ecr_requested_utc`
- `ux_cancelled`, `fx_cancelled`, `rz_removed`, `assigned_utc`

**Stored Procedures:**
- `sp_GS_Create` - Create proposed ground stop
- `sp_GS_Model` - Identify affected flights (accepts `@dep_facilities` for tier expansion)
- `sp_GS_IssueEDCTs` - Activate ground stop
- `sp_GS_Extend` - Extend end time
- `sp_GS_Purge` - Cancel/purge
- `sp_GS_GetFlights` - Get affected flights
- `sp_GS_DetectPopups` - Find new pop-ups during active GS

**Views:**
- `vw_GDT_FlightList` - Complete flight info (JOINs all normalized tables)
- `vw_GDT_DemandByQuarter` - 15-min bin demand
- `vw_GDT_DemandByHour` - Hourly demand
- `vw_GDT_DemandByCenter` - Demand by origin ARTCC
- `vw_NTML_Active` - Active programs
- `vw_NTML_Today` - Today's programs

**Helper Function:**
- `fn_HaversineNM` - Great circle distance in nautical miles

### 2. Database Test Results ✅

Ran full GS workflow test with live ADL data:
- Created GS for KJFK (program_id=1, ADVZY 001)
- Modeled with tier expansion (ZNY ZDC ZBW ZOB)
- Found 1 flight in scope (N964GB KDCA→KJFK)
- Activated successfully
- Purged successfully
- Event log captured all actions with JSON details

### 3. PHP API Endpoints (Deployed, Pending Test)

**Location:** `api/tmi/gs/`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `common.php` | - | Shared utilities (respond_json, fetch_all, etc.) |
| `create.php` | POST | Create new GS in PROPOSED state |
| `model.php` | POST | Model GS (identify affected flights) |
| `activate.php` | POST | Activate GS (issue EDCTs) |
| `extend.php` | POST | Extend GS end time |
| `purge.php` | POST | Cancel/purge GS |
| `flights.php` | GET/POST | Get affected flights |
| `get.php` | GET/POST | Get single program with optional flights/events |
| `list.php` | GET/POST | List GS programs with filters |
| `demand.php` | GET/POST | Get arrival demand (for bar graphs) |

### 4. Design Documentation

**Updated:** `docs/GDT_Unified_Design_Document_v1.md`
- Full schema documentation
- Stored procedure reference
- API endpoint summary
- Implementation roadmap

---

## Naming Conventions (Corrected This Session)

| Old/Wrong | Correct | Notes |
|-----------|---------|-------|
| `tmi_programs` | `ntml` | National Traffic Management Log |
| `tmi_events` | `ntml_info` | Event/audit log |
| `sp_TMI_ActivateProgram` | `sp_GS_IssueEDCTs` | FSM terminology |
| EBSA | RBS | Ration-By-Schedule (FAA term) |
| Slot naming | FSM-style | e.g., `KJFK.091530A` |

---

## Key Design Decisions

1. **Tier expansion stays in JavaScript** - `sp_GS_Model` accepts `@dep_facilities` parameter (space-delimited ARTCC list). JS uses existing `TierInfo.csv` lookup, passes expanded list to SP.

2. **ETA field** - Uses `eta_runway_utc` from `adl_flight_times` (populated by trajectory calculations).

3. **Pop-up detection** - `sp_GS_DetectPopups` designed for VATSIM daemon to call during active programs.

4. **Airports table columns** - Uses `ICAO_ID`, `LAT_DECIMAL`, `LONG_DECIMAL` (not `icao_code`, `latitude`, `longitude`).

---

## Next Steps

### Immediate (Next Session)
1. **Test PHP APIs** - Claude's network now has perti.vatcscc.org whitelisted
   ```bash
   curl -s "https://perti.vatcscc.org/api/tmi/gs/list.php"
   curl -s -X POST https://perti.vatcscc.org/api/tmi/gs/create.php \
     -H "Content-Type: application/json" \
     -d '{"ctl_element":"KLAX","start_utc":"2026-01-10T02:00:00Z","end_utc":"2026-01-10T04:00:00Z","scope_type":"TIER","scope_tier":1,"exempt_airborne":true,"impacting_condition":"WEATHER","cause_text":"API Test","created_by":"test"}'
   ```

2. **Wire up gdt.js** - Connect existing UI to new API endpoints

### Future
3. **GDP Procedures** - `sp_GDP_Create`, `sp_GDP_Model`, RBS slot allocation
4. **GS→GDP Transition** - Convert active GS to GDP

---

## File Locations

```
PERTI/
├── adl/migrations/tmi/
│   ├── 001_ntml_schema.sql        ✅ Deployed
│   ├── 002_gs_procedures.sql      ✅ Deployed  
│   ├── 003_gdt_views.sql          ✅ Deployed
│   └── test_gs_workflow.sql       ✅ Test script
├── api/tmi/gs/
│   ├── common.php                 ✅ Created
│   ├── create.php                 ✅ Created
│   ├── model.php                  ✅ Created
│   ├── activate.php               ✅ Created
│   ├── extend.php                 ✅ Created
│   ├── purge.php                  ✅ Created
│   ├── flights.php                ✅ Created
│   ├── get.php                    ✅ Created
│   ├── list.php                   ✅ Created
│   └── demand.php                 ✅ Created
├── api/tmi/                       (legacy APIs still present)
│   ├── gs_preview.php
│   ├── gs_apply.php
│   └── ...
└── docs/
    └── GDT_Unified_Design_Document_v1.md  ✅ Updated
```

---

## API Test Commands (Ready to Run)

```bash
# 1. List existing programs
curl -s "https://perti.vatcscc.org/api/tmi/gs/list.php"

# 2. Create a GS
curl -s -X POST https://perti.vatcscc.org/api/tmi/gs/create.php \
  -H "Content-Type: application/json" \
  -d '{
    "ctl_element": "KLAX",
    "start_utc": "2026-01-10T03:00:00Z",
    "end_utc": "2026-01-10T05:00:00Z",
    "scope_type": "TIER",
    "scope_tier": 1,
    "exempt_airborne": true,
    "impacting_condition": "WEATHER",
    "cause_text": "API Test",
    "created_by": "test"
  }'

# 3. Model it (use program_id from step 2)
# KLAX 1st tier = ZLA ZOA ZLC ZDV ZAB
curl -s -X POST https://perti.vatcscc.org/api/tmi/gs/model.php \
  -H "Content-Type: application/json" \
  -d '{
    "program_id": 2,
    "dep_facilities": "ZLA ZOA ZLC ZDV ZAB",
    "performed_by": "test"
  }'

# 4. Get flights
curl -s "https://perti.vatcscc.org/api/tmi/gs/flights.php?program_id=2"

# 5. Get demand
curl -s "https://perti.vatcscc.org/api/tmi/gs/demand.php?airport=KLAX&hours_ahead=6"

# 6. Purge (cleanup)
curl -s -X POST https://perti.vatcscc.org/api/tmi/gs/purge.php \
  -H "Content-Type: application/json" \
  -d '{"program_id": 2, "purged_by": "test"}'
```

---

## Previous Transcripts

- `/mnt/transcripts/2026-01-10-01-14-19-gdt-unified-design-normalized-adl.txt` - Initial GDT design discussion
- `/mnt/transcripts/2026-01-10-01-46-10-gdt-ground-stop-ntml-implementation.txt` - Schema creation, compacted session

---

*Transition document created: January 10, 2026 01:58 UTC*
