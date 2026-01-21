# GDT Database Migration Session - January 21, 2026

## Session Summary

Successfully deployed the GDT (Ground Delay Tools) database schema to VATSIM_TMI.

---

## ✅ Completed Tasks

### 1. Database Migrations Deployed
| File | Objects Created |
|------|-----------------|
| `010_gdt_incremental_schema.sql` | 2 tables, 12+ columns added, 10+ indexes |
| `011_create_gdt_views.sql` | 6 views |
| `012_create_gdt_procedures.sql` | 12 stored procedures |

### 2. New Database Objects

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
- `sp_TMI_CreateProgram` - Create GS/GDP/AFP
- `sp_TMI_GenerateSlots` - RBS slot generation
- `sp_TMI_ActivateProgram` - Activate proposed
- `sp_TMI_PurgeProgram` - Cancel/purge
- `sp_TMI_AssignFlightsRBS` - RBS assignment
- `sp_TMI_DetectPopups` - Pop-up detection
- `sp_TMI_AssignPopups` - Auto-assign pop-ups
- `sp_TMI_ApplyGroundStop` - Apply GS
- `sp_TMI_TransitionGStoGDP` - GS→GDP transition
- `sp_TMI_ExtendProgram` - Extend end time
- `sp_TMI_ArchiveData` - Retention management

**User Type:**
- `FlightListType` - Table-valued parameter

### 3. Schema Enhancements

**tmi_programs** (12 new columns):
- `is_archived`, `gs_probability`, `gs_release_rate`
- `fca_name`, `fca_entry_time_offset`, `transition_type`
- `superseded_by_id`, `compression_enabled`, `last_compression_utc`
- `popup_flights`, `earliest_r_slot_min`, `completed_at`

**tmi_slots** (11 new columns):
- `bin_date`, `assigned_dest`, `original_eta_utc`
- `slot_delay_min`, `bridge_reason`, `is_popup_slot`
- `popup_lead_time_min`, `is_archived`, `archive_tier`, `archived_at`

### 4. Documentation Updated
- `GDT_Incremental_Migration.md` - Marked complete
- `GDT_Phase1_Transition.md` - Phase 1 marked complete
- `TMI_Documentation_Index.md` - Updated GDT status

### 5. Files Cleaned Up
- Renamed migration files (removed version suffixes)
- Final files: `010_gdt_incremental_schema.sql`, `011_create_gdt_views.sql`, `012_create_gdt_procedures.sql`

### 6. Technical Note
**SQL Server filtered index syntax:** `INCLUDE` clause must come **before** `WHERE`:
```sql
CREATE INDEX name ON table(cols) INCLUDE (cols) WHERE condition;
```

---

## 📋 Next Steps (Priority Order)

### Phase 2: API Layer (HIGH PRIORITY)
Create `/api/gdt/` endpoint structure to expose the new stored procedures:

```
/api/gdt/
├── programs/
│   ├── create.php       POST - sp_TMI_CreateProgram
│   ├── list.php         GET  - Query tmi_programs
│   ├── get.php          GET  - Single program details
│   ├── simulate.php     POST - Run RBS simulation
│   ├── activate.php     POST - sp_TMI_ActivateProgram
│   ├── revise.php       POST - Create revision
│   ├── extend.php       POST - sp_TMI_ExtendProgram
│   ├── compress.php     POST - Run compression
│   ├── purge.php        POST - sp_TMI_PurgeProgram
│   └── transition.php   POST - sp_TMI_TransitionGStoGDP
├── flights/
│   ├── list.php         GET  - vw_tmi_flight_list
│   ├── exempt.php       POST - Exempt individual flight
│   ├── ecr.php          POST - EDCT change request
│   └── substitute.php   POST - Slot substitution
├── slots/
│   ├── list.php         GET  - vw_tmi_slot_allocation
│   ├── hold.php         POST - Hold/release slot
│   └── bridge.php       POST - Create slot bridge
└── demand/
    ├── hourly.php       GET  - vw_tmi_demand_by_hour
    ├── quarter.php      GET  - vw_tmi_demand_by_quarter
    └── metrics.php      GET  - vw_tmi_program_metrics
```

**Existing file to migrate:** `/api/mgt/tmi/ground_stops/post.php`

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

## Reference Files

| File | Location |
|------|----------|
| GDT Design Document | `PERTI/GDT_Unified_Design_Document_v1.1.md` |
| Migration Guide | `PERTI/GDT_Incremental_Migration.md` |
| Phase 1 Transition | `PERTI/GDT_Phase1_Transition.md` |
| TMI Doc Index | `PERTI/TMI_Documentation_Index.md` |
| Migration SQL | `PERTI/database/migrations/tmi/010-012_*.sql` |

---

*Session Date: January 21, 2026*
