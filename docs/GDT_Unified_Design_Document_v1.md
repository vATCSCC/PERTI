# Unified Ground Delay Tools (GDT) Design Document
## Normalized ADL Architecture Integration

**Version:** 1.0  
**Date:** January 9, 2026  
**Status:** Design Review

---

## 1. Executive Summary

This document outlines the design for a unified Ground Delay Tools (GDT) system that combines Ground Stop (GS) and Ground Delay Program (GDP) functionality following real-world FAA TFMS/FSM specifications. The system will be adapted to work with PERTI's normalized ADL 8-table architecture.

### 1.1 Goals

1. **Unified TMI Interface** - Single tool for GS, GDP, and GS→GDP transitions
2. **FAA-Compliant Algorithms** - RBS (Ration-By-Schedule), Compression, GAAP, UDP delay assignment
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
| Compression | Optimize existing slot assignments | Slot compression algorithm |
| Blanket | Uniform delay adjustment (+/-) | Apply delta to all CTDs |
| Purge | Cancel TMI program | Clear all control times |

---

## 2. Database Schema

### 2.1 Core TMI Tables (Azure SQL)

#### 2.1.1 `ntml` - National Traffic Management Log (Program Registry)

Tracks all GS/GDP programs (active and historical).

```sql
CREATE TABLE dbo.ntml (
    program_id          INT IDENTITY(1,1) PRIMARY KEY,
    program_guid        UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL,
    
    -- Program identification
    ctl_element         NVARCHAR(8) NOT NULL,      -- Airport (KJFK) or FCA (FCA001)
    element_type        NVARCHAR(8) NOT NULL,      -- 'APT' or 'FCA'
    program_type        NVARCHAR(16) NOT NULL,     -- 'GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP'
    program_name        AS (computed),             -- e.g., "KJFK-GS-01091530"
    adv_number          NVARCHAR(16) NULL,         -- Advisory number (e.g., "ADVZY 001")
    
    -- Program times
    start_utc           DATETIME2(0) NOT NULL,
    end_utc             DATETIME2(0) NOT NULL,
    cumulative_start    DATETIME2(0) NULL,         -- For extensions (original start)
    cumulative_end      DATETIME2(0) NULL,         -- For extensions (latest end)
    
    -- Program status
    status              NVARCHAR(16) NOT NULL DEFAULT 'PROPOSED',
                        -- PROPOSED, ACTIVE, COMPLETED, PURGED, SUPERSEDED
    is_proposed         BIT DEFAULT 1,
    is_active           BIT DEFAULT 0,
    
    -- Rates (GDP only)
    program_rate        INT NULL,                   -- Default arrivals/hour
    reserve_rate        INT NULL,                   -- Reserved slots/hour for pop-ups
    delay_limit_min     INT DEFAULT 180,            -- Maximum assignable delay
    
    -- Scope parameters
    scope_type          NVARCHAR(16) NULL,          -- TIER, DISTANCE, MANUAL
    scope_tier          TINYINT NULL,               -- 1, 2, 3
    scope_distance_nm   INT NULL,
    scope_json          NVARCHAR(MAX) NULL,
    exemptions_json     NVARCHAR(MAX) NULL,
    
    -- Metrics (populated after modeling)
    total_flights       INT NULL,
    controlled_flights  INT NULL,
    exempt_flights      INT NULL,
    airborne_flights    INT NULL,
    avg_delay_min       DECIMAL(8,2) NULL,
    max_delay_min       INT NULL,
    
    -- Audit
    created_by          NVARCHAR(64) NULL,
    created_utc         DATETIME2(0) DEFAULT SYSUTCDATETIME(),
    activated_by        NVARCHAR(64) NULL,
    activated_utc       DATETIME2(0) NULL,
    purged_by           NVARCHAR(64) NULL,
    purged_utc          DATETIME2(0) NULL
);
```

#### 2.1.2 `ntml_info` - Program Event Log / Audit Trail

```sql
CREATE TABLE dbo.ntml_info (
    event_id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    program_id          INT NOT NULL,
    flight_uid          BIGINT NULL,
    slot_id             BIGINT NULL,
    
    event_type          NVARCHAR(32) NOT NULL,
    -- PROGRAM_CREATED, PROGRAM_MODELED, PROGRAM_ACTIVATED, PROGRAM_REVISED,
    -- PROGRAM_EXTENDED, PROGRAM_COMPRESSED, PROGRAM_PURGED, GS_TO_GDP,
    -- FLIGHT_CONTROLLED, FLIGHT_EXEMPTED, FLIGHT_POPUP, FLIGHT_RECONTROL,
    -- ECR_REQUESTED, ECR_APPROVED, ECR_DENIED
    
    event_details_json  NVARCHAR(MAX) NULL,
    event_message       NVARCHAR(512) NULL,
    performed_by        NVARCHAR(64) NULL,
    performed_utc       DATETIME2(0) DEFAULT SYSUTCDATETIME()
);
```

#### 2.1.3 `ntml_slots` - Arrival Slot Allocation (GDP only)

```sql
CREATE TABLE dbo.ntml_slots (
    slot_id             BIGINT IDENTITY(1,1) PRIMARY KEY,
    program_id          INT NOT NULL,
    
    -- Slot identification (FSM-style: KJFK.091530A)
    slot_name           NVARCHAR(16) NOT NULL,
    slot_index          INT NOT NULL,
    slot_time_utc       DATETIME2(0) NOT NULL,
    
    slot_type           NVARCHAR(16) NOT NULL,      -- REGULAR, RESERVED, UNASSIGNED
    slot_status         NVARCHAR(16) NOT NULL,      -- OPEN, ASSIGNED, BRIDGED, HELD, CANCELLED
    
    -- Assignment
    assigned_flight_uid BIGINT NULL,
    assigned_callsign   NVARCHAR(12) NULL,
    assigned_carrier    NVARCHAR(8) NULL,
    assigned_origin     NVARCHAR(4) NULL,
    
    -- Slot management
    sl_hold             BIT DEFAULT 0,
    subbable            BIT DEFAULT 1
);
```

#### 2.1.4 `adl_flight_tmi` - Per-Flight TMI Assignments (Enhanced)

The existing `adl_flight_tmi` table is enhanced with GS/GDP fields:

**New columns added:**
- `program_id` - FK to ntml
- `slot_id` - FK to ntml_slots
- `aslot` - Slot name (FSM-style)
- `octd_utc`, `octa_utc` - Original control times (frozen)
- `ctl_prgm` - Program name
- `ctl_exempt`, `ctl_exempt_reason` - Exemption tracking
- `program_delay_min`, `delay_capped` - Delay metrics
- `gs_held`, `gs_release_utc` - Ground stop specific
- `is_popup`, `is_recontrol`, `popup_detected_utc` - Pop-up tracking
- `ecr_pending`, `ecr_requested_cta`, `ecr_requested_by` - ECR tracking
- `ux_cancelled`, `fx_cancelled`, `rz_removed` - Cancel flags (per FAA ADL spec)

---

## 3. Stored Procedures

### 3.1 Ground Stop Procedures

| Procedure | Description |
|-----------|-------------|
| `sp_GS_Create` | Create a proposed ground stop |
| `sp_GS_Model` | Model the ground stop (identify affected flights) |
| `sp_GS_IssueEDCTs` | Activate the ground stop |
| `sp_GS_Extend` | Extend GS end time |
| `sp_GS_Purge` | Cancel/purge the ground stop |
| `sp_GS_GetFlights` | Get flights affected by a GS |
| `sp_GS_DetectPopups` | Detect new pop-up flights during active GS |

### 3.2 Key Implementation Notes

**Scope Filtering:**
- The `sp_GS_Model` procedure accepts a `@dep_facilities` parameter containing space-delimited ARTCC codes
- This allows the JS layer to continue using `TierInfo.csv` for tier expansion
- The expanded ARTCC list is passed to the stored procedure, maintaining current behavior

**Pop-up Detection:**
- `sp_GS_DetectPopups` is designed to be called by the VATSIM daemon
- It finds flights that appeared after the GS started and auto-assigns them
- Airborne flights are automatically exempted if `exempt_airborne = 1`

---

## 4. Views

| View | Description |
|------|-------------|
| `vw_GDT_FlightList` | Complete flight info for GDT displays (JOINs all normalized tables) |
| `vw_GDT_DemandByQuarter` | Demand by 15-minute bins for bar graphs |
| `vw_GDT_DemandByHour` | Hourly demand aggregation |
| `vw_GDT_DemandByCenter` | Demand by origin ARTCC for scope analysis |
| `vw_NTML_Active` | Active TMI programs |
| `vw_NTML_Today` | All programs created/active today |

---

## 5. Migration Files

Located in: `adl/migrations/tmi/`

| File | Contents |
|------|----------|
| `001_ntml_schema.sql` | Creates `ntml`, `ntml_info`, `ntml_slots`; enhances `adl_flight_tmi` |
| `002_gs_procedures.sql` | Ground Stop stored procedures |
| `003_gdt_views.sql` | GDT views for API queries |

---

## 6. Next Steps

### Phase 1: Database (Current)
- [x] Create NTML schema migration
- [x] Create GS stored procedures
- [x] Create GDT views
- [ ] Run migrations on VATSIM_ADL database
- [ ] Verify `adl_flight_tmi` enhancements

### Phase 2: API Layer
- [ ] Create `/api/gdt/gs/create.php`
- [ ] Create `/api/gdt/gs/model.php`
- [ ] Create `/api/gdt/gs/activate.php`
- [ ] Create `/api/gdt/gs/extend.php`
- [ ] Create `/api/gdt/gs/purge.php`
- [ ] Create `/api/gdt/gs/flights.php`

### Phase 3: JS Integration
- [ ] Update `gdt.js` to use new API endpoints
- [ ] Wire up GS workflow to stored procedures
- [ ] Test tier-based scope filtering

### Phase 4: GDP (Future)
- [ ] Create GDP stored procedures (`sp_GDP_*`)
- [ ] Implement RBS slot allocation
- [ ] Add GS→GDP transition procedure

---

## 7. References

- FSM User Guide v13.0 (Chapter 19-20: Ground Stop Operations)
- FSM User Guide v13.0 (Chapter 8: Modeling a Ground Delay Program)
- ADL File Format Specification v14.1
- FADT File Format Specification v4.3
- Advisories and General Messages v1.3

---

*Document Version: 1.0 | Last Updated: January 9, 2026*
