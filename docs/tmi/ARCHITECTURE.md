# TMI System Architecture

**Version:** 2.0  
**Date:** January 17, 2026  
**Status:** Design Approved

---

## 1. Overview

The TMI (Traffic Management Initiative) system consolidates all traffic management data from multiple input sources into a single authoritative database:

- **NTML Entries** - MIT, MINIT, DELAY, CONFIG, APREQ, CONTINGENCY, MISC, REROUTE
- **Advisories** - Formal notices for GS, GDP, AFP, CTOP, Reroutes, etc.
- **GDT Programs** - Ground Stop and Ground Delay Programs with slot allocation
- **Reroutes** - Route definitions with flight assignments and compliance tracking
- **Public Routes** - Map-displayable route information

### 1.1 Design Goals

1. **Single Source of Truth** - One database for all TMI data (10 tables)
2. **Multi-Source Input** - Accept entries from PERTI, Discord, TypeForm, API
3. **Contingency Support** - Discord direct entry as fallback when systems are down
4. **SWIM Accessible** - Public API for external consumers
5. **FAA-Compliant** - Follow FSM/TFMS specifications for GDT operations
6. **Cost Effective** - Azure SQL Basic tier (~$5/mo)
7. **Highly Available** - Same server as VATSIM_ADL for reliability

---

## 2. System Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           TMI UNIFIED SYSTEM                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│                    ┌─────────────────────────────┐                          │
│                    │   VATSIM_TMI Database       │                          │
│                    │   (Azure SQL Basic)         │                          │
│                    │                             │                          │
│                    │   • tmi_entries     (NTML)  │                          │
│                    │   • tmi_programs    (GDT)   │                          │
│                    │   • tmi_slots       (GDP)   │                          │
│                    │   • tmi_advisories          │                          │
│                    │   • tmi_reroutes            │                          │
│                    │   • tmi_reroute_flights     │                          │
│                    │   • tmi_public_routes       │                          │
│                    │   • tmi_events     (Audit)  │                          │
│                    └─────────────┬───────────────┘                          │
│                                  │                                          │
│              ┌───────────────────┼───────────────────┐                     │
│              │                   │                   │                     │
│              ▼                   ▼                   ▼                     │
│        ┌──────────┐       ┌──────────┐       ┌──────────┐                  │
│        │ SWIM API │       │ Discord  │       │  PERTI   │                  │
│        │ (Public) │       │ (Output) │       │  Views   │                  │
│        └──────────┘       └──────────┘       └──────────┘                  │
│                                  ▲                                          │
│                                  │                                          │
│         ┌────────────────────────┼────────────────────────┐                │
│         │                 PERTI PHP API                   │                │
│         │              (Business Logic Hub)               │                │
│         │                                                 │                │
│         │    /api/tmi/entries/*     - NTML CRUD          │                │
│         │    /api/tmi/advisories/*  - Advisory CRUD      │                │
│         │    /api/gdt/programs/*    - GDT operations     │                │
│         │    /api/gdt/slots/*       - Slot management    │                │
│         │    /api/reroutes/*        - Reroute management │                │
│         │    /api/public-routes/*   - Route display      │                │
│         │                                                 │                │
│         └────────────────────────┼────────────────────────┘                │
│                                  ▲                                          │
│              ┌───────────────────┼───────────────────┐                     │
│              │                   │                   │                     │
│        ┌─────┴─────┐      ┌──────┴──────┐     ┌─────┴─────┐               │
│        │  PERTI    │      │  Discord    │     │ TypeForm/ │               │
│        │  Website  │      │    Bot      │     │  Zapier   │               │
│        │ (Primary) │      │(Contingency)│     │ (Legacy)  │               │
│        └───────────┘      └─────────────┘     └───────────┘               │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Database Architecture

### 3.1 Multi-Database Layout

```
Azure SQL Server: vatsim.database.windows.net
├── VATSIM_ADL    ($15/mo S0)   - Flight data (high-volume)
│   └── adl_flight_tmi          - Flight-level TMI assignments
│
├── SWIM_API     ($5/mo Basic)  - Public API (read-cached)
│
└── VATSIM_TMI   ($5/mo Basic)  - TMI data (NEW)
    ├── tmi_entries             - NTML log
    ├── tmi_programs            - GS/GDP programs
    ├── tmi_slots               - GDP slot allocation
    ├── tmi_advisories          - Formal advisories
    ├── tmi_reroutes            - Reroute definitions
    ├── tmi_reroute_flights     - Flight assignments
    ├── tmi_reroute_compliance_log
    ├── tmi_public_routes       - Map display
    ├── tmi_events              - Audit log
    └── tmi_advisory_sequences  - Number generation
```

### 3.2 Cross-Database Integration

The `adl_flight_tmi` table stays in VATSIM_ADL (tight coupling with flight data) but references TMI tables:

```sql
-- Example: Get controlled flights with program details
SELECT 
    f.callsign, f.ctd_utc, f.cta_utc, f.program_delay_min,
    p.program_type, p.program_name, p.ctl_element
FROM VATSIM_ADL.dbo.adl_flight_tmi f
JOIN VATSIM_TMI.dbo.tmi_programs p ON f.program_id = p.program_id
WHERE p.is_active = 1;
```

### 3.3 Why This Architecture?

| Requirement | How VATSIM_TMI Satisfies It |
|-------------|----------------------------|
| **High Availability** | Same server as production VATSIM_ADL |
| **Robustness** | Azure SQL with auto-backup, geo-redundancy |
| **Scalability** | Can scale independently or use Elastic Pool |
| **SWIM Compatible** | Same infrastructure, cross-DB queries |
| **Efficient** | Indexed for TMI query patterns |
| **Low Cost** | Basic tier ~$5/mo for TMI volume |
| **FAA Compliant** | Schema follows FSM/TFMS data structures |

---

## 4. Component Details

### 4.1 NTML Entries (`tmi_entries`)

NTML (National Traffic Management Log) entries record all traffic management actions:

| Entry Type | Description | Example |
|------------|-------------|---------|
| MIT | Miles-In-Trail | "20 MIT KJFK via LENDY" |
| MINIT | Minutes-In-Trail | "10 MINIT ZNY/ZBW" |
| DELAY | Delay | "30 MIN DELAY KORD ARR" |
| CONFIG | Configuration | "KJFK 31L/31R/22L" |
| APREQ | Approval Request | "APREQ ZDC → ZNY" |
| CONTINGENCY | Contingency | "Contingency Plan Alpha" |
| MISC | Miscellaneous | General log entry |
| REROUTE | Reroute Notice | "SWAP West reroute active" |

### 4.2 GDT Programs (`tmi_programs`)

Ground Delay Tools following FAA FSM specifications:

| Program Type | Description | Has Slots |
|--------------|-------------|-----------|
| GS | Ground Stop | No |
| GDP-DAS | Delay Assignment System | Yes |
| GDP-GAAP | General Aviation Airport Program | Yes |
| GDP-UDP | Unified Delay Program | Yes |
| AFP-DAS | Airspace Flow Program (DAS) | Yes |
| AFP-GAAP | Airspace Flow Program (GAAP) | Yes |
| AFP-UDP | Airspace Flow Program (UDP) | Yes |

### 4.3 Advisories (`tmi_advisories`)

Formal notifications following FAA advisory formats:

| Advisory Type | Linked Entity |
|---------------|---------------|
| GS, GDP, AFP | tmi_programs |
| REROUTE, CDR, SWAP | tmi_reroutes |
| CTOP, FEA, FCA | (standalone) |
| OPS_PLAN, GENERAL | (standalone) |
| MIT | tmi_entries |

### 4.4 Reroutes (`tmi_reroutes`)

Route management with compliance tracking:

```
tmi_reroutes (definition)
    │
    └── tmi_reroute_flights (assignments)
            │
            └── tmi_reroute_compliance_log (history)
```

### 4.5 Public Routes (`tmi_public_routes`)

Map-displayable routes with GeoJSON geometry for visualization.

---

## 5. Bot Architecture (PHP-Centric)

### 5.1 Design Principle

**The PERTI PHP API owns all business logic. The Discord bot is a thin client.**

```
┌─────────────────────────────────────────────────────────────────┐
│                     DISCORD BOT (Python)                        │
│                     (Thin Client)                               │
│                                                                 │
│   Responsibilities:                                             │
│   • Listen to Discord messages in #ntml, #advisories           │
│   • Provide slash commands (/ntml, /gs, /gdp, /reroute)        │
│   • Forward raw input to PERTI API                             │
│   • Display responses/errors from API                          │
│   • Add reactions for status (✅ ❌ ⚠️)                         │
│                                                                 │
│   Does NOT do:                                                  │
│   • Complex parsing                                             │
│   • Database operations                                         │
│   • Business logic                                              │
│   • State management                                            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              │ HTTP POST
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     PERTI PHP API                               │
│                     (Business Logic Hub)                        │
│                                                                 │
│   NTML Endpoints:                                               │
│   POST /api/tmi/entries/create    - Create from any source     │
│   POST /api/tmi/entries/parse     - Parse only (no save)       │
│   PUT  /api/tmi/entries/{id}      - Update entry               │
│   POST /api/tmi/entries/{id}/cancel - Cancel entry             │
│                                                                 │
│   GDT Endpoints:                                                │
│   POST /api/gdt/programs/create   - Create GS/GDP              │
│   POST /api/gdt/programs/simulate - Run RBS simulation         │
│   POST /api/gdt/programs/activate - Activate program           │
│   POST /api/gdt/programs/revise   - Revise rates/times         │
│   POST /api/gdt/gs/transition     - GS → GDP transition        │
│                                                                 │
│   Reroute Endpoints:                                            │
│   POST /api/reroutes/create       - Create reroute             │
│   POST /api/reroutes/{id}/assign  - Assign flights             │
│   GET  /api/reroutes/{id}/compliance - Check compliance        │
└─────────────────────────────────────────────────────────────────┘
```

### 5.2 Bot Example

```python
@client.slash_command(name="gdp", description="Create or manage GDP")
async def gdp_command(ctx, airport: str, rate: int, duration: int):
    response = await call_perti_api('/api/gdt/programs/create', {
        'ctl_element': airport,
        'program_type': 'GDP-DAS',
        'program_rate': rate,
        'duration_minutes': duration,
        'source_type': 'DISCORD',
        'source_id': str(ctx.interaction.id),
        'created_by': str(ctx.author.id),
        'created_by_name': ctx.author.display_name
    })
    
    if response['success']:
        await ctx.respond(f"✅ GDP created for {airport}: {response['program_name']}")
    else:
        await ctx.respond(f"❌ Error: {response['error']}")
```

---

## 6. API Endpoint Summary

### 6.1 TMI Entries API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/tmi/entries/create` | Create entry |
| GET | `/api/tmi/entries/{id}` | Get entry |
| PUT | `/api/tmi/entries/{id}` | Update entry |
| POST | `/api/tmi/entries/{id}/cancel` | Cancel entry |
| GET | `/api/tmi/entries/active` | List active entries |

### 6.2 GDT API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/gdt/programs/create` | Create program |
| POST | `/api/gdt/programs/{id}/simulate` | Run simulation |
| POST | `/api/gdt/programs/{id}/activate` | Activate program |
| POST | `/api/gdt/programs/{id}/revise` | Revise program |
| POST | `/api/gdt/programs/{id}/purge` | Purge program |
| GET | `/api/gdt/slots/{program_id}` | List slots |
| POST | `/api/gdt/ecr/request` | ECR request |

### 6.3 Reroutes API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/reroutes/create` | Create reroute |
| GET | `/api/reroutes/{id}` | Get reroute |
| POST | `/api/reroutes/{id}/activate` | Activate |
| GET | `/api/reroutes/{id}/flights` | List assigned flights |
| GET | `/api/reroutes/{id}/compliance` | Compliance summary |

### 6.4 SWIM TMI API

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/swim/v1/tmi/entries` | Public NTML feed |
| GET | `/api/swim/v1/tmi/advisories` | Public advisories |
| GET | `/api/swim/v1/tmi/programs` | Active GDT programs |
| GET | `/api/swim/v1/tmi/reroutes` | Active reroutes |

---

## 7. State Management

### 7.1 Entry/Advisory States

```
DRAFT → PROPOSED → APPROVED → SCHEDULED → ACTIVE → EXPIRED
                      ↓           ↓          ↓
                  CANCELLED   CANCELLED   CANCELLED
                                           ↓
                                       SUPERSEDED
```

### 7.2 Program States

```
PROPOSED → ACTIVE → COMPLETED
    ↓         ↓
  PURGED   PURGED/SUPERSEDED
```

### 7.3 Auto-Expiration

The `sp_ExpireOldEntries` stored procedure runs every minute to:
- Activate SCHEDULED entries when `valid_from` is reached
- Expire ACTIVE entries when `valid_until` is passed
- Update program status based on `end_utc`

---

## 8. Cost Analysis Summary

| Component | Configuration | Monthly Cost |
|-----------|---------------|--------------|
| VATSIM_TMI | Azure SQL Basic (5 DTU) | $4.99 |
| Event scale-up | S1 for CTP/FNO (~6/year) | ~$2 |
| **Total** | | **~$7/month** |

**Annual Cost:** ~$72-84

See [COST_ANALYSIS.md](COST_ANALYSIS.md) for detailed breakdown.

---

## 9. Implementation Phases

### Phase 1: Database (Week 1) ✅
- [x] Design complete schema (10 tables)
- [x] Create migration script
- [x] Update config files
- [ ] Deploy VATSIM_TMI to Azure
- [ ] Run migration

### Phase 2: Core Procedures (Week 2)
- [ ] `sp_TMI_CreateProgram`
- [ ] `sp_TMI_GenerateSlots`
- [ ] `sp_TMI_AssignFlightsToSlots`
- [ ] `sp_TMI_SimulateGDP`
- [ ] `sp_TMI_ApplyGroundStop`

### Phase 3: API Layer (Week 3)
- [ ] `/api/tmi/entries/*` endpoints
- [ ] `/api/gdt/*` endpoints
- [ ] `/api/reroutes/*` endpoints
- [ ] SWIM TMI endpoints

### Phase 4: UI & Bot (Week 4)
- [ ] Update `gdt.js` for new APIs
- [ ] Update Discord bot commands
- [ ] Add GS→GDP transition UI

### Phase 5: Advanced Features (Week 5+)
- [ ] Compression algorithm
- [ ] Slot substitution (SCS)
- [ ] Pop-up/re-control handling
- [ ] Advisory auto-generation

---

## 10. Related Documents

- [DATABASE.md](DATABASE.md) - Full schema (269 fields)
- [STATUS_WORKFLOW.md](STATUS_WORKFLOW.md) - State machine
- [COST_ANALYSIS.md](COST_ANALYSIS.md) - Azure pricing
- GDT_Unified_Design_Document_v1.md - GDT specifications

---

*Last Updated: January 17, 2026*
