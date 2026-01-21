# GDT Development Session - January 21, 2026

## Session Summary

Completed Phase 1 (Database Migration) and substantial Phase 2 (API Layer) work.

---

## ✅ Phase 1: Database Migration - COMPLETE

### Migrations Deployed
| File | Objects Created |
|------|-----------------|
| `010_gdt_incremental_schema.sql` | 2 tables, 12+ columns added, 10+ indexes |
| `011_create_gdt_views.sql` | 6 views |
| `012_create_gdt_procedures.sql` | 12 stored procedures |

### New Database Objects

**Tables:**
- `tmi_flight_control` - Per-flight TMI assignments (55+ columns)
- `tmi_popup_queue` - Pop-up detection queue

**Views:**
- `vw_tmi_flight_list` - Flight list with control details
- `vw_tmi_slot_allocation` - Slot allocation summary
- `vw_tmi_demand_by_hour` - Hourly demand metrics
- `vw_tmi_demand_by_quarter` - 15-minute bins
- `vw_tmi_popup_pending` - Pending pop-ups
- `vw_tmi_program_metrics` - Program dashboard

**Stored Procedures:**
- `sp_TMI_CreateProgram`, `sp_TMI_GenerateSlots`, `sp_TMI_ActivateProgram`
- `sp_TMI_PurgeProgram`, `sp_TMI_AssignFlightsRBS`, `sp_TMI_DetectPopups`
- `sp_TMI_AssignPopups`, `sp_TMI_ApplyGroundStop`, `sp_TMI_TransitionGStoGDP`
- `sp_TMI_ExtendProgram`, `sp_TMI_ArchiveData`

**User Type:**
- `FlightListType` - Table-valued parameter

---

## ✅ Phase 2: API Layer - IN PROGRESS

### API Directory Structure Created
```
api/gdt/
├── common.php           ✅ Shared utilities, DB connections
├── index.php            ✅ API endpoint listing
├── programs/
│   ├── create.php       ✅ POST - sp_TMI_CreateProgram
│   ├── list.php         ✅ GET - Query tmi_programs
│   ├── get.php          ✅ GET - Single program + slots + counts
│   ├── simulate.php     ✅ POST - sp_TMI_GenerateSlots + sp_TMI_AssignFlightsRBS
│   ├── activate.php     ✅ POST - sp_TMI_ActivateProgram
│   ├── extend.php       ✅ POST - sp_TMI_ExtendProgram
│   ├── purge.php        ✅ POST - sp_TMI_PurgeProgram
│   └── transition.php   ✅ POST - sp_TMI_TransitionGStoGDP
├── flights/
│   └── list.php         ✅ GET - tmi_flight_control query
├── slots/
│   └── list.php         ✅ GET - tmi_slots query
└── demand/
    └── hourly.php       ✅ GET - Hourly demand data
```

### Database Connections
- **VATSIM_TMI** (`get_conn_tmi()`) - Programs, slots, flight_control
- **VATSIM_ADL** (`get_conn_adl()`) - Live flight data from vw_adl_flights

### Key Implementation: simulate.php
The simulate endpoint bridges both databases:
1. Queries flights from VATSIM_ADL (vw_adl_flights)
2. Applies exemption rules (airborne, departing soon, etc.)
3. Builds FlightListType table-valued parameter
4. Calls stored procedure in VATSIM_TMI
5. Returns flights, slots, and summary

### Documentation Created
- `docs/tmi/GDT_API_Documentation.md` - Complete API reference
- `docs/tmi/GDT_API_Development_Session.md` - Development notes

---

## 📋 Remaining Work

### Phase 2 Remaining (API)
- [ ] `flights/exempt.php` - POST - Exempt individual flight
- [ ] `flights/ecr.php` - POST - EDCT Change Request
- [ ] `flights/substitute.php` - POST - Slot substitution
- [ ] `slots/hold.php` - POST - Hold/release slot
- [ ] `slots/bridge.php` - POST - Create slot bridge
- [ ] `demand/metrics.php` - GET - vw_tmi_program_metrics
- [ ] API Testing with real data

### Phase 3: Daemon Integration
- Add pop-up detection to ADL refresh daemon
- Call `sp_TMI_DetectPopups` after each refresh cycle
- Call `sp_TMI_AssignPopups` for GAAP/UDP programs
- Add `sp_TMI_ArchiveData` to scheduled maintenance

### Phase 4: UI Updates
- Update `gdt.js` to use new `/api/gdt/` endpoints
- Add unified program type selector (GS/GDP-DAS/GDP-GAAP/GDP-UDP)
- Add GS→GDP transition workflow
- Add compression controls
- Add ECR interface

### Phase 5: Advisory Integration
- Auto-generate GS/GDP advisories on program activation
- Link programs to `tmi_advisories` table
- Discord notifications via TMIDiscord.php

---

## Files Created/Modified This Session

### Database Migrations (renamed)
```
database/migrations/tmi/010_gdt_incremental_schema.sql
database/migrations/tmi/011_create_gdt_views.sql
database/migrations/tmi/012_create_gdt_procedures.sql
```

### API Layer (NEW)
```
api/gdt/common.php
api/gdt/index.php
api/gdt/programs/create.php
api/gdt/programs/list.php
api/gdt/programs/get.php
api/gdt/programs/simulate.php
api/gdt/programs/activate.php
api/gdt/programs/extend.php
api/gdt/programs/purge.php
api/gdt/programs/transition.php
api/gdt/flights/list.php
api/gdt/slots/list.php
api/gdt/demand/hourly.php
```

### Documentation (NEW/Updated)
```
docs/tmi/GDT_API_Documentation.md
docs/tmi/GDT_API_Development_Session.md
docs/tmi/GDT_Session_20260121.md (this file)
```

---

## Technical Notes

### SQL Server Filtered Index Syntax
`INCLUDE` clause must come **before** `WHERE`:
```sql
CREATE INDEX name ON table(cols) INCLUDE (cols) WHERE condition;
```

### Table-Valued Parameters in PHP
Since PHP sqlsrv doesn't directly support TVPs, we use temp tables:
```php
// Create temp table
sqlsrv_query($conn, "CREATE TABLE #FlightList (...)");

// Insert flights
foreach ($flights as $f) {
    sqlsrv_query($conn, "INSERT INTO #FlightList ...", [...]);
}

// Declare TVP from temp table and call SP
sqlsrv_query($conn, "
    DECLARE @flights dbo.FlightListType;
    INSERT INTO @flights SELECT * FROM #FlightList;
    EXEC dbo.sp_TMI_AssignFlightsRBS @program_id = ?, @flights = @flights, ...
");
```

---

## Status Summary

| Component | Status |
|-----------|--------|
| Database Schema (010) | ✅ DEPLOYED |
| Views (011) | ✅ DEPLOYED |
| Stored Procedures (012) | ✅ DEPLOYED |
| API: common.php | ✅ CREATED |
| API: programs/* (8 endpoints) | ✅ CREATED |
| API: flights/list | ✅ CREATED |
| API: slots/list | ✅ CREATED |
| API: demand/hourly | ✅ CREATED |
| API Testing | ⏳ PENDING |
| UI Integration | ⏳ PENDING |
| Daemon Integration | ⏳ PENDING |

---

*Session Date: January 21, 2026*
