# VATSIM_TMI GDT Incremental Migration - Transition Document

**Date:** January 21, 2026  
**Version:** 1.2.0  
**Status:** ✅ COMPLETED - All GDT migrations deployed

---

## 1. Migration Status

**All GDT database migrations have been successfully deployed to VATSIM_TMI on January 21, 2026.**

| Migration | Status | Date |
|-----------|--------|------|
| `010_gdt_incremental_schema.sql` | ✅ Complete | Jan 21, 2026 |
| `011_create_gdt_views.sql` | ✅ Complete | Jan 21, 2026 |
| `012_create_gdt_procedures.sql` | ✅ Complete | Jan 21, 2026 |

---

## 2. Current Database State

The VATSIM_TMI database now includes:

### Existing Tables (14)
- `tmi_entries` - NTML log entries
- `tmi_advisories` - Formal advisories
- `tmi_advisory_sequences` - Advisory numbering
- `tmi_programs` - GS/GDP programs (needs GDT columns)
- `tmi_slots` - GDP slot allocation (needs GDT columns)
- `tmi_reroutes` - Reroute definitions
- `tmi_reroute_flights` - Flight assignments
- `tmi_reroute_compliance_log` - Compliance history
- `tmi_public_routes` - Public route display
- `tmi_events` - Unified audit log
- `tmi_flow_events` - Flow events
- `tmi_flow_event_participants` - Event participants
- `tmi_flow_measures` - Flow measures
- `tmi_flow_providers` - Flow providers

### Existing Views (8)
- `vw_tmi_active_advisories`
- `vw_tmi_active_entries`
- `vw_tmi_active_flow_events`
- `vw_tmi_active_flow_measures`
- `vw_tmi_active_programs`
- `vw_tmi_active_public_routes`
- `vw_tmi_active_reroutes`
- `vw_tmi_recent_entries`

### Existing Procedures (4)
- `sp_ExpireOldEntries`
- `sp_GetActivePublicRoutes`
- `sp_GetNextAdvisoryNumber`
- `sp_LogTmiEvent`

---

## 3. GDT Objects Deployed

### Migration Files (Deployed)

```
/PERTI/database/migrations/tmi/
├── 010_gdt_incremental_schema.sql    ✅ Deployed
├── 011_create_gdt_views.sql          ✅ Deployed
└── 012_create_gdt_procedures.sql     ✅ Deployed
```

### Objects Deployed

**Part 1: ALTER tmi_programs** (11 new columns)
- `is_archived` - Retention management
- `gs_probability` - GS probability type
- `gs_release_rate` - Release rate when GS lifts
- `fca_name` - AFP/FCA display name
- `fca_entry_time_offset` - Entry time offset
- `transition_type` - GS_TO_GDP, REVISION, EXTENSION
- `superseded_by_id` - FK to newer revision
- `compression_enabled` - Enable compression
- `last_compression_utc` - Last compression time
- `popup_flights` - Pop-up flight count
- `earliest_r_slot_min` - R-slot setting
- `completed_at` - Completion timestamp

**Part 2: ALTER tmi_slots** (10 new columns)
- `bin_date` - Date for demand analysis (populated from slot_time_utc)
- `assigned_dest` - For AFP
- `original_eta_utc` - ETA before slot assignment
- `slot_delay_min` - Delay imposed by slot
- `bridge_reason` - ECR, SCS, COMPRESSION
- `is_popup_slot` - Pop-up flag
- `popup_lead_time_min` - Lead time when detected
- `is_archived` - Retention management
- `archive_tier` - 1=Hot, 2=Cool, 3=Cold
- `archived_at` - Archive timestamp

**Part 3: CREATE tmi_flight_control** (new table)
- Per-flight TMI assignments
- Control times (CTD, CTA, OCTD, OCTA)
- Exemption tracking
- Delay tracking
- Ground Stop tracking
- Pop-up/Re-control tracking
- ECR tracking
- Compliance tracking

**Part 4: CREATE tmi_popup_queue** (new table)
- Pop-up detection queue
- Processing status
- Assignment tracking

**Part 5: CREATE FlightListType** (new type)
- Table-valued parameter for flight input

**Part 6: New Indexes**
- `IX_tmi_slots_retention`
- `IX_tmi_slots_bin`
- `IX_tmi_slots_open`
- `IX_tmi_programs_retention`

**GDT Views (6 new)**
- `vw_tmi_flight_list`
- `vw_tmi_slot_allocation`
- `vw_tmi_demand_by_hour`
- `vw_tmi_demand_by_quarter`
- `vw_tmi_popup_pending`
- `vw_tmi_program_metrics`

**GDT Procedures (12 new)**
- `sp_TMI_CreateProgram`
- `sp_TMI_GenerateSlots`
- `sp_TMI_ActivateProgram`
- `sp_TMI_PurgeProgram`
- `sp_TMI_AssignFlightsRBS`
- `sp_TMI_DetectPopups`
- `sp_TMI_AssignPopups`
- `sp_TMI_ApplyGroundStop`
- `sp_TMI_TransitionGStoGDP`
- `sp_TMI_ExtendProgram`
- `sp_TMI_ArchiveData`

---

## 4. Technical Notes

### SQL Server Syntax for Filtered Indexes
When creating filtered indexes with INCLUDE clause, the order must be:
```sql
CREATE INDEX name ON table(columns) INCLUDE (columns) WHERE condition;
```
`INCLUDE` must come **before** `WHERE`.

---

## 4. Verify Installation

```sql
USE VATSIM_TMI;

-- Check new columns in tmi_programs
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME IN 
('is_archived','gs_probability','gs_release_rate','fca_name','transition_type','superseded_by_id','compression_enabled','popup_flights');

-- Check new columns in tmi_slots  
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME IN 
('bin_date','assigned_dest','original_eta_utc','slot_delay_min','is_popup_slot','is_archived');

-- Check new tables
SELECT name FROM sys.tables WHERE name IN ('tmi_flight_control', 'tmi_popup_queue');

-- Check new views
SELECT name FROM sys.views WHERE name LIKE 'vw_tmi_%' AND name NOT IN 
('vw_tmi_active_advisories','vw_tmi_active_entries','vw_tmi_active_flow_events',
'vw_tmi_active_flow_measures','vw_tmi_active_programs','vw_tmi_active_public_routes',
'vw_tmi_active_reroutes','vw_tmi_recent_entries');

-- Check new procedures
SELECT name FROM sys.procedures WHERE name LIKE 'sp_TMI_%';

-- Check user type
SELECT name FROM sys.types WHERE name = 'FlightListType';
```

Expected results:
- 8 new columns in tmi_programs
- 6 new columns in tmi_slots
- 2 new tables
- 6 new views
- 12 new procedures
- 1 user type

---

## 5. Post-Migration Updates

### Update config.php

If not already present, add TMI database constants:

```php
// VATSIM_TMI - Traffic Management Initiatives
define("TMI_SQL_HOST", "vatsim.database.windows.net");
define("TMI_SQL_DATABASE", "VATSIM_TMI");
define("TMI_SQL_USERNAME", "your-username");
define("TMI_SQL_PASSWORD", "your-password");
```

### Git Commit

```bash
cd PERTI
git add database/migrations/tmi/010_gdt_incremental_schema.sql
git add database/migrations/tmi/011_create_gdt_views.sql
git add database/migrations/tmi/012_create_gdt_procedures.sql
git add GDT_Unified_Design_Document_v1.1.md
git add GDT_Phase1_Transition.md
git commit -m "Add GDT incremental migration for VATSIM_TMI"
git push
```

---

## 6. Next Steps

### Phase 2: API Layer (Next Session)
- Create `/api/gdt/programs/` endpoints
- Create `/api/gdt/flights/` endpoints
- Create `/api/gdt/slots/` endpoints
- Migrate existing gdp_simulate.php

### Phase 3: Daemon Integration
- Add pop-up detection to ADL daemon
- Implement auto-assignment for GAAP/UDP

### Phase 4: UI Updates
- Update `gdt.js` to use new APIs
- Add GS→GDP transition workflow

---

## 7. Files Created

| File | Purpose |
|------|---------|
| `010_gdt_incremental_schema.sql` | Schema changes (ALTERs + new tables) |
| `011_create_gdt_views.sql` | 6 GDT views |
| `012_create_gdt_procedures.sql` | 12 GDT procedures |
| `GDT_Unified_Design_Document_v1.1.md` | Updated design doc |
| `GDT_Phase1_Transition.md` | This document (superseded) |

---

*Document Version: 1.2.0 | Last Updated: January 21, 2026*
