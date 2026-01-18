# VATSIM_TMI Database Setup & GDT Phase 1 Transition

**Date:** January 18, 2026  
**Version:** 1.0.0  
**Session Context:** TMI Discord v3.4.0 completion → GDT Database Setup

---

## 1. Session Summary

### Completed This Session

#### TMI Discord Formatting (v3.4.0)
- ✅ Fixed all delay types: D/D (Departure Delay), E/D (En Route Delay), A/D (Arrival Delay)
- ✅ Corrected NTML field ordering (EXCL before reason codes)
- ✅ Added REASON:DETAIL pattern support
- ✅ Implemented TBM zone handling
- ✅ Test suite v2.0.0 created (39 tests: 25 NTML + 14 Advisory)

#### VATSIM_TMI Database Schema (NEW)
Created complete migration suite for GDT functionality:

| Migration | Purpose |
|-----------|---------|
| `001_create_tmi_programs.sql` | TMI Program Registry |
| `002_create_tmi_slots.sql` | Arrival Slot Allocation (FSM format) |
| `003_create_tmi_flight_control.sql` | Per-Flight TMI Assignments |
| `004_create_tmi_events_and_popup.sql` | Audit Log + Pop-up Queue |
| `005_create_views.sql` | 7 Query Views |
| `006_create_core_procedures.sql` | Create/Activate/Purge Programs |
| `007_create_rbs_and_popup_procedures.sql` | RBS Algorithm + Pop-up Detection |
| `008_create_gs_and_transition_procedures.sql` | Ground Stop + GS→GDP Transition |
| `009_create_compression_and_retention.sql` | Manual/Adaptive Compression + Archival |

---

## 2. Database Setup Instructions

### Step 1: Create Database on Azure

Connect to your Azure SQL Server and run:

```sql
-- Create the VATSIM_TMI database
CREATE DATABASE VATSIM_TMI
COLLATE SQL_Latin1_General_CP1_CI_AS;
GO

-- Optional: Set service tier (Basic = $5/month)
-- ALTER DATABASE VATSIM_TMI MODIFY (EDITION = 'Basic', SERVICE_OBJECTIVE = 'Basic');
```

### Step 2: Run Migrations

Using Azure Data Studio or SSMS, connect to VATSIM_TMI and run each migration in order:

**GDT-Specific Migrations (NEW - January 18):**
```
/PERTI/database/migrations/tmi/
├── 001_create_tmi_programs.sql
├── 002_create_tmi_slots.sql
├── 003_create_tmi_flight_control.sql
├── 004_create_tmi_events_and_popup.sql
├── 005_create_views.sql
├── 006_create_core_procedures.sql
├── 007_create_rbs_and_popup_procedures.sql
├── 008_create_gs_and_transition_procedures.sql
└── 009_create_compression_and_retention.sql
```

**Note:** There is also an older consolidated migration (`001_tmi_core_schema_azure_sql.sql`) from January 17 that includes additional tables for NTML entries, advisories, and reroutes. Review both approaches and decide:
- **Option A**: Run the new modular migrations (001-009) for GDT-only tables
- **Option B**: Run the consolidated migration for full TMI suite (entries, advisories, reroutes + GDT)

If VATSIM_TMI database already has NTML tables, use Option A to add only GDT tables.

### Step 3: Update config.php

Add TMI database constants to your production `config.php`:

```php
// VATSIM_TMI - Traffic Management Initiatives (GDT/GS/GDP)
define("TMI_SQL_HOST", "your-server.database.windows.net");
define("TMI_SQL_DATABASE", "VATSIM_TMI");
define("TMI_SQL_USERNAME", "your-username");
define("TMI_SQL_PASSWORD", "your-password");
```

### Step 4: Verify Installation

Run this query to verify all objects were created:

```sql
USE VATSIM_TMI;

-- Tables (5)
SELECT 'Tables' AS Type, COUNT(*) AS Count 
FROM sys.tables WHERE schema_id = SCHEMA_ID('dbo');

-- Views (7)
SELECT 'Views' AS Type, COUNT(*) AS Count 
FROM sys.views WHERE schema_id = SCHEMA_ID('dbo');

-- Procedures (12)
SELECT 'Procedures' AS Type, COUNT(*) AS Count 
FROM sys.procedures WHERE schema_id = SCHEMA_ID('dbo');

-- List all objects
SELECT type_desc, name, create_date
FROM sys.objects
WHERE schema_id = SCHEMA_ID('dbo')
  AND type IN ('U', 'V', 'P', 'FN')
ORDER BY type_desc, name;
```

Expected counts:
- Tables: 5 (`tmi_programs`, `tmi_slots`, `tmi_flight_control`, `tmi_events`, `tmi_popup_queue`)
- Views: 7
- Procedures: 12
- User Types: 1 (`FlightListType`)

---

## 3. Design Decisions Confirmed

### Slot Naming Format
Using FSM format per FADT spec:
- GDP: `KJFK.091530A` (airport.ddHHmmL)
- AFP: `FCA027.091530A` (FCA prefix)

### Retention Tiers
| Tier | Duration | Data Type |
|------|----------|-----------|
| Hot | 90 days | Flight assignments, Slots |
| Cool | 1 year | Archived flight/slot data |
| Cold | Indefinite | Historical archive |
| Programs | 5 years hot | Purged programs before cold |

### Program Types Supported
- GS (Ground Stop)
- GDP-DAS (Delay Assignment System)
- GDP-GAAP (General Aviation Airport Program)
- GDP-UDP (Unified Delay Program)
- AFP (Airspace Flow Program)
- BLANKET (Uniform delay adjustment)
- COMPRESSION (Slot optimization)

### Pop-up Handling
- Auto-detect via ADL daemon
- Flag in `tmi_popup_queue`
- Auto-assign to RESERVED slots (GAAP/UDP mode)
- DAS mode: Apply average delay

### Compression
- Manual trigger: `sp_TMI_RunCompression`
- Adaptive auto: `sp_TMI_AdaptiveCompression` (daemon)

---

## 4. Files Created/Modified

### New Files
```
/PERTI/database/migrations/tmi/
├── 000_master_migration.sql      (orchestration script)
├── 001_create_tmi_programs.sql
├── 002_create_tmi_slots.sql
├── 003_create_tmi_flight_control.sql
├── 004_create_tmi_events_and_popup.sql
├── 005_create_views.sql
├── 006_create_core_procedures.sql
├── 007_create_rbs_and_popup_procedures.sql
├── 008_create_gs_and_transition_procedures.sql
└── 009_create_compression_and_retention.sql

/PERTI/load/
└── config.example.php            (updated with TMI constants)
```

### Existing File Updates Pending
- `/PERTI/load/config.php` - Add TMI_SQL_* constants (production)
- `/PERTI/load/connect.php` - Already has `get_conn_tmi()` function

---

## 5. Next Steps (GDT Phase 2)

### Priority 1: Deploy Database
1. Create VATSIM_TMI database on Azure
2. Run all migration scripts
3. Add TMI credentials to production config
4. Verify with test queries

### Priority 2: Deploy Test Suite
1. Git push `ntml_discord_test.php` v2.0.0
2. Run full 39-test validation
3. Verify all Discord formatting

### Priority 3: API Layer
Create `/api/gdt/` endpoint structure:

```
/api/gdt/
├── programs/
│   ├── create.php      POST - Create new program
│   ├── simulate.php    POST - Run simulation (RBS)
│   ├── activate.php    POST - Activate proposed program
│   ├── revise.php      POST - Create revision
│   ├── extend.php      POST - Extend program end time
│   ├── compress.php    POST - Run compression
│   ├── purge.php       POST - Cancel/purge program
│   └── transition.php  POST - GS to GDP transition
├── flights/
│   ├── list.php        GET  - Flight list with TMI status
│   ├── exempt.php      POST - Exempt individual flight
│   └── ecr.php         POST - EDCT change request
├── slots/
│   ├── list.php        GET  - Slot allocation list
│   ├── hold.php        POST - Hold/release slot
│   └── bridge.php      POST - Create slot bridge
└── demand/
    ├── hourly.php      GET  - Hourly demand by airport
    └── metrics.php     GET  - Delay metrics summary
```

### Priority 4: Daemon Integration
1. Add pop-up detection to ADL refresh daemon
2. Call `sp_TMI_DetectPopups` after each refresh
3. Call `sp_TMI_AssignPopups` for GAAP/UDP programs
4. Add compression to scheduler daemon

### Priority 5: UI Updates
1. Update `gdt.js` to use new API endpoints
2. Add unified program type selector
3. Add GS→GDP transition workflow
4. Add compression controls

---

## 6. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        GDT System Architecture                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐       │
│  │   gdt.js    │────▶│  /api/gdt/  │────▶│ VATSIM_TMI  │       │
│  │  (Browser)  │     │   (PHP)     │     │ (Azure SQL) │       │
│  └─────────────┘     └─────────────┘     └─────────────┘       │
│                             │                    │               │
│                             │                    │               │
│                             ▼                    ▼               │
│                      ┌─────────────┐     ┌─────────────┐       │
│                      │TMIDiscord.php│    │ VATSIM_ADL  │       │
│                      │  (Notify)   │     │ (Flights)   │       │
│                      └─────────────┘     └─────────────┘       │
│                             │                    ▲               │
│                             ▼                    │               │
│                      ┌─────────────┐     ┌─────────────┐       │
│                      │   Discord   │     │ ADL Daemon  │       │
│                      │  Channels   │     │ (15s cycle) │       │
│                      └─────────────┘     └─────────────┘       │
│                                                  │               │
│                                                  ▼               │
│                                          ┌─────────────┐       │
│                                          │ Pop-up      │       │
│                                          │ Detection   │       │
│                                          └─────────────┘       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 7. Reference Documents

- FSM User Guide v13.0 (Chapters 8, 19-20)
- ADL File Format Specification v14.1
- FADT File Format Specification v4.3
- Advisories and General Messages v1.3
- GDT_Unified_Design_Document_v1.md

---

## 8. Open Questions (For Future Sessions)

1. **AFP Support Scope**: Full AFP with FCA boundary drawing, or GDP-equivalent for FCA elements only?
2. **Multi-Element Programs**: Single program controlling multiple airports (CTOP-style)?
3. **Historical Analytics**: Dashboard for program effectiveness metrics?
4. **SimBrief Integration**: Auto-detect EDCT compliance from filed route changes?

---

**Session Status:** Ready for database deployment  
**Next Action:** Run migrations on Azure SQL, then start API layer

*Document Version: 1.0.0 | Last Updated: January 18, 2026*
