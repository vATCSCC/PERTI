# Unified Ground Delay Tools (GDT) Design Document
## Normalized ADL Architecture Integration

**Version:** 1.1  
**Date:** January 18, 2026  
**Status:** Phase 1 Complete - Schema Implemented

---

## 1. Executive Summary

This document outlines the design for a unified Ground Delay Tools (GDT) system that combines Ground Stop (GS) and Ground Delay Program (GDP) functionality following real-world FAA TFMS/FSM specifications. The system is adapted to work with PERTI's normalized ADL 8-table architecture.

### 1.1 Goals

1. **Unified TMI Interface** - Single tool for GS, GDP, AFP, and GS→GDP transitions
2. **FAA-Compliant Algorithms** - RBS, Compression, GAAP, UDP delay assignment
3. **Full TMI Lifecycle** - Initial, Revision, Extension, Compression, Purge
4. **EDCT Management** - Per-flight control time modifications (ECR)
5. **Normalized ADL Integration** - Leverage 8-table structure for performance

### 1.2 Supported Program Types (per FSM)

| Program Type | Description | Slot Algorithm |
|-------------|-------------|----------------|
| Ground Stop (GS) | Hold all departures to destination | No slots - departure hold |
| GDP - DAS | Delay Assignment System | RBS + Compression |
| GDP - GAAP | General Aviation Airport Program | RBS + Reserve slots for pop-ups |
| GDP - UDP | Unified Delay Program | DAS + GAAP + Revised Pop-up Mgmt |
| AFP | Airspace Flow Program | RBS for FCA entry slots |
| Compression | Optimize existing slot assignments | Slot compression algorithm |
| Blanket | Uniform delay adjustment (+/-) | Apply delta to all CTDs |
| Purge | Cancel TMI program | Clear all control times |

### 1.3 Design Decisions (Confirmed January 18, 2026)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Slot Naming | FSM format (`KJFK.091530A`) | Per FADT spec, industry standard |
| Database | VATSIM_TMI (Azure SQL) | Separate from ADL for cost isolation |
| Pop-up Detection | Auto-detect + auto-assign | Daemon integration with ADL refresh |
| Compression | Manual + Adaptive | Both modes supported |
| Transitions | GS→GDP supported | With flight transfer |
| AFP/FCA | Supported | GDP-equivalent for FCA elements |
| Combined Programs | GS/GDP + AFP | Supported |

---

## 2. Database Schema (VATSIM_TMI)

**Location:** `/PERTI/database/migrations/tmi/`

### 2.1 Core Tables

#### 2.1.1 `tmi_programs` - TMI Program Registry

Tracks all GS/GDP/AFP programs (active and historical).

**Key Fields:**
- `program_id` (PK), `program_guid` (UNIQUEIDENTIFIER)
- `ctl_element` (airport ICAO or FCA name)
- `element_type` ('APT', 'FCA', 'FEA')
- `program_type` ('GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP', 'BLANKET', 'COMPRESSION')
- `status` ('PROPOSED', 'MODELING', 'ACTIVE', 'PAUSED', 'COMPLETED', 'PURGED', 'SUPERSEDED')
- `start_utc`, `end_utc`, `cumulative_start_utc`, `cumulative_end_utc`
- `program_rate`, `reserve_rate`, `delay_limit_min`
- `rates_hourly_json`, `reserve_hourly_json`
- `scope_type`, `scope_distance_nm`, `scope_centers_json`
- `parent_program_id` (for GS→GDP transition)
- `compression_enabled`, `adaptive_compression`

#### 2.1.2 `tmi_slots` - Arrival Slot Allocation

FSM-format slot naming (`KJFK.091530A`).

**Key Fields:**
- `slot_id` (BIGINT PK), `program_id` (FK)
- `slot_name` (unique, FSM format)
- `slot_index`, `slot_time_utc`
- `slot_type` ('REGULAR', 'RESERVED', 'UNASSIGNED')
- `slot_status` ('OPEN', 'ASSIGNED', 'BRIDGED', 'HELD', 'CANCELLED', 'COMPRESSED')
- `bin_date`, `bin_hour`, `bin_quarter` (15-min granularity)
- `assigned_flight_uid`, `assigned_callsign`, `assigned_carrier`
- `slot_delay_min`, `original_eta_utc`
- `sl_hold`, `subbable` (substitution management)
- `bridge_from_slot_id`, `bridge_to_slot_id` (SCS)
- `is_popup_slot`, `popup_lead_time_min`

#### 2.1.3 `tmi_flight_control` - Per-Flight TMI Assignments

Control times and slot assignments for individual flights.

**Key Fields:**
- `control_id` (BIGINT PK), `flight_uid` (FK to VATSIM_ADL), `callsign`
- `program_id`, `slot_id`
- `ctd_utc`, `cta_utc`, `octd_utc`, `octa_utc` (control times)
- `aslot` (slot name), `ctl_elem`, `ctl_type`
- `ctl_exempt`, `ctl_exempt_reason`
- `program_delay_min`, `delay_capped`, `z_slot_delay`
- `orig_etd_utc`, `orig_eta_utc`, `orig_ete_min`
- `gs_held`, `gs_release_utc`, `gs_release_sequence`
- `is_popup`, `is_recontrol`, `popup_detected_utc`
- `ecr_pending`, `ecr_requested_cta`
- `compliance_status`, `actual_dep_utc`, `compliance_delta_min`

#### 2.1.4 `tmi_events` - Audit History

Event log for all TMI operations.

**Key Fields:**
- `event_id` (BIGINT PK)
- `event_type`, `event_subtype`
- `program_id`, `slot_id`, `flight_uid`, `control_id`
- `details_json`, `old_value`, `new_value`
- `event_source` ('USER', 'SYSTEM', 'DAEMON', 'API', 'COMPRESSION')
- `event_user`, `event_utc`

#### 2.1.5 `tmi_popup_queue` - Pop-up Detection Queue

Pending pop-up flights awaiting slot assignment.

**Key Fields:**
- `queue_id` (BIGINT PK), `flight_uid`, `callsign`, `program_id`
- `detected_utc`, `flight_eta_utc`, `lead_time_min`
- `dep_airport`, `arr_airport`, `dep_center`, `carrier`, `aircraft_type`
- `queue_status` ('PENDING', 'PROCESSING', 'ASSIGNED', 'EXEMPT', 'FAILED', 'EXPIRED')
- `assigned_slot_id`, `assignment_type`

### 2.2 Views

| View | Purpose |
|------|---------|
| `vw_tmi_active_programs` | Active/proposed programs for monitoring |
| `vw_tmi_flight_list` | Complete flight list with control details |
| `vw_tmi_slot_allocation` | Slot allocation summary |
| `vw_tmi_demand_by_hour` | Hourly demand (bar graph) |
| `vw_tmi_demand_by_quarter` | 15-min bins (detailed charts) |
| `vw_tmi_popup_pending` | Pending pop-ups awaiting assignment |
| `vw_tmi_program_metrics` | Dashboard metrics |

### 2.3 Stored Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_TMI_CreateProgram` | Create new GS/GDP/AFP |
| `sp_TMI_GenerateSlots` | Generate slots using RBS rate |
| `sp_TMI_ActivateProgram` | Activate proposed program |
| `sp_TMI_PurgeProgram` | Cancel/purge program |
| `sp_TMI_AssignFlightsRBS` | Assign flights to slots (RBS algorithm) |
| `sp_TMI_DetectPopups` | Auto-detect pop-up flights |
| `sp_TMI_AssignPopups` | Auto-assign pop-ups (GAAP/UDP) |
| `sp_TMI_ApplyGroundStop` | Apply GS to flights |
| `sp_TMI_ReleaseGroundStop` | Release GS (metered or all) |
| `sp_TMI_TransitionGStoGDP` | GS→GDP transition |
| `sp_TMI_ExtendProgram` | Extend program end time |
| `sp_TMI_RunCompression` | Manual compression |
| `sp_TMI_AdaptiveCompression` | Auto compression (daemon) |
| `sp_TMI_ArchiveData` | Retention management |

### 2.4 User-Defined Types

```sql
CREATE TYPE dbo.FlightListType AS TABLE (
    flight_uid          BIGINT NOT NULL PRIMARY KEY,
    callsign            NVARCHAR(12) NOT NULL,
    eta_utc             DATETIME2(0) NOT NULL,
    etd_utc             DATETIME2(0) NULL,
    dep_airport         NVARCHAR(4) NULL,
    arr_airport         NVARCHAR(4) NULL,
    dep_center          NVARCHAR(4) NULL,
    arr_center          NVARCHAR(4) NULL,
    carrier             NVARCHAR(8) NULL,
    aircraft_type       NVARCHAR(8) NULL,
    flight_status       NVARCHAR(16) NULL,
    is_exempt           BIT DEFAULT 0,
    exempt_reason       NVARCHAR(32) NULL
);
```

---

## 3. Retention Policy

### 3.1 Data Tiers

| Tier | Duration | Storage | Data Types |
|------|----------|---------|------------|
| Hot | 0-90 days | Primary DB | Active programs, recent flights/slots |
| Cool | 90 days - 1 year | Primary DB (archived flag) | Historical flights/slots |
| Cold | 1+ years | Cold storage | Archived data (indefinite) |
| Programs (Purged) | 5 years hot | Primary DB | Purged program records |

### 3.2 Archival Process

```sql
-- Daily archival job (via scheduler daemon)
EXEC sp_TMI_ArchiveData @archive_mode = 'HOT_TO_COOL';

-- Monthly archival job
EXEC sp_TMI_ArchiveData @archive_mode = 'COOL_TO_COLD';
```

---

## 4. Algorithm Details

### 4.1 Ration By Schedule (RBS)

1. Order flights by ETA (scheduled arrival time)
2. Assign each flight to the next available slot >= their ETA
3. Calculate delay as `(slot_time - original_eta)`
4. Cap delay at `delay_limit_min`
5. For GAAP/UDP: Reserve every Nth slot for pop-ups

### 4.2 Pop-up Detection

Called by ADL refresh daemon after each cycle:

```php
// In vatsim_adl_daemon.php
if ($active_gdp_programs) {
    foreach ($programs as $program) {
        $conn_tmi = get_conn_tmi();
        // Call sp_TMI_DetectPopups with current flights
        // Call sp_TMI_AssignPopups for GAAP/UDP
    }
}
```

### 4.3 Compression

- **Trigger**: Flights depart before CTD or no-show
- **Process**: Move later flights to earlier slots
- **Result**: Reduced total delay while maintaining schedule order

---

## 5. API Structure

**Base Path:** `/api/gdt/`

### 5.1 Programs Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/programs/create.php` | Create new program |
| POST | `/programs/simulate.php` | Run simulation |
| POST | `/programs/activate.php` | Activate proposed |
| POST | `/programs/revise.php` | Create revision |
| POST | `/programs/extend.php` | Extend end time |
| POST | `/programs/compress.php` | Run compression |
| POST | `/programs/purge.php` | Cancel/purge |
| POST | `/programs/transition.php` | GS→GDP |
| GET | `/programs/list.php` | List programs |
| GET | `/programs/get.php` | Get program details |

### 5.2 Flights Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/flights/list.php` | Flight list with TMI status |
| POST | `/flights/exempt.php` | Exempt individual flight |
| POST | `/flights/ecr.php` | EDCT change request |
| POST | `/flights/substitute.php` | Slot substitution |

### 5.3 Slots Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/slots/list.php` | Slot allocation list |
| POST | `/slots/hold.php` | Hold/release slot |
| POST | `/slots/bridge.php` | Create slot bridge |

### 5.4 Demand Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/demand/hourly.php` | Hourly demand |
| GET | `/demand/byCenter.php` | Demand by origin center |
| GET | `/demand/metrics.php` | Delay metrics |

---

## 6. Migration Path

### 6.1 Phase 1: Schema Creation ✅ COMPLETE (January 18, 2026)

- [x] Create `tmi_programs` table
- [x] Create `tmi_slots` table
- [x] Create `tmi_flight_control` table
- [x] Create `tmi_events` table
- [x] Create `tmi_popup_queue` table
- [x] Create views
- [x] Create stored procedures
- [x] Update config.example.php

### 6.2 Phase 2: API Layer (Week 2)

- [ ] Create `/api/gdt/programs/` endpoints
- [ ] Create `/api/gdt/flights/` endpoints
- [ ] Create `/api/gdt/slots/` endpoints
- [ ] Create `/api/gdt/demand/` endpoints
- [ ] Migrate existing gdp_simulate.php
- [ ] Migrate existing gs_apply.php

### 6.3 Phase 3: Daemon Integration (Week 3)

- [ ] Add pop-up detection to ADL daemon
- [ ] Implement auto-assignment for GAAP/UDP
- [ ] Add adaptive compression to scheduler
- [ ] Add archival to maintenance jobs

### 6.4 Phase 4: UI Updates (Week 4)

- [ ] Update `gdt.js` to use new APIs
- [ ] Add unified program type selector
- [ ] Add GS→GDP transition workflow
- [ ] Add compression controls
- [ ] Add ECR interface

### 6.5 Phase 5: Advanced Features (Week 5+)

- [ ] Slot substitution (SCS)
- [ ] Historical analytics dashboard
- [ ] Advisory generation integration
- [ ] SimBrief EDCT compliance detection

---

## 7. Open Questions

1. ~~**Slot naming convention**: Use FSM format or simplified?~~ → **FSM format confirmed**

2. ~~**Historical retention**: How long to keep completed programs?~~ → **5 years hot, then cold**

3. ~~**Real-time updates**: Auto-detect pop-ups?~~ → **Yes, daemon integration**

4. ~~**Compression**: Manual or automatic?~~ → **Both modes supported**

5. ~~**Multi-element programs**: AFP/FCA support?~~ → **Yes, supported**

6. **Future**: Multi-airport CTOP-style programs?

7. **Future**: Integration with virtual airline CDM systems?

---

## 8. References

- FSM User Guide v13.0 (Chapters 8, 19-20: GDP/GS Operations)
- ADL File Format Specification v14.1
- FADT File Format Specification v4.3
- Advisories and General Messages v1.3
- TFMDI Interface Control Document

---

*Document Version: 1.1 | Last Updated: January 18, 2026*
