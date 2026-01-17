# TMI Unified System Documentation

**Status:** ✅ **DEPLOYED & LIVE**  
**Date:** January 17, 2026

This directory contains documentation for the unified Traffic Management Initiative (TMI) system, which consolidates NTML entries, Advisories, GDT Programs (GS/GDP), Reroutes, and Public Routes across multiple input sources into a single authoritative database.

---

## Quick Start

### API Base URL
```
https://perti.vatcscc.org/api/tmi/
```

### Test the API
```bash
# API info
curl https://perti.vatcscc.org/api/tmi/

# All active TMI data
curl https://perti.vatcscc.org/api/tmi/active.php
```

### Database Connection
```
Server:   vatsim.database.windows.net
Database: VATSIM_TMI
Username: TMI_admin
Password: (see config.php)
```

---

## API Endpoints (Live ✅)

| Endpoint | Methods | Description | Status |
|----------|---------|-------------|--------|
| `/api/tmi/` | GET | API info and endpoint list | ✅ Live |
| `/api/tmi/active` | GET | All currently active TMI data | ✅ Live |
| `/api/tmi/entries` | GET, POST, PUT, DELETE | NTML log entries | ✅ Live |
| `/api/tmi/programs` | GET, POST, PUT, DELETE | GDT programs (GS/GDP) | ✅ Live |
| `/api/tmi/advisories` | GET, POST, PUT, DELETE | Formal advisories | ✅ Live |
| `/api/tmi/public-routes` | GET, POST, PUT, DELETE | Public route display | ✅ Live |
| `/api/tmi/reroutes` | GET, POST, PUT, DELETE | Reroute definitions | ✅ Live |

### Example API Calls

```bash
# Get all active TMI data
curl https://perti.vatcscc.org/api/tmi/active.php

# Get active entries only
curl "https://perti.vatcscc.org/api/tmi/entries.php?active_only=1"

# Get active programs (GS/GDP)
curl "https://perti.vatcscc.org/api/tmi/programs.php?active_only=1"

# Get public routes as GeoJSON
curl "https://perti.vatcscc.org/api/tmi/public-routes.php?geojson=1"

# Create new entry (requires auth)
curl -X POST https://perti.vatcscc.org/api/tmi/entries.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tmi_bot_xxxxx" \
  -d '{"determinant_code":"05B01","protocol_type":5,"entry_type":"MIT",...}'
```

---

## Documentation Index

| Document | Description |
|----------|-------------|
| [ARCHITECTURE.md](ARCHITECTURE.md) | System architecture and design decisions |
| [DATABASE.md](DATABASE.md) | Complete database schema (10 tables, 269 fields) |
| [STATUS_WORKFLOW.md](STATUS_WORKFLOW.md) | Entry/Advisory/Program lifecycle states |
| [COST_ANALYSIS.md](COST_ANALYSIS.md) | Azure SQL pricing and usage projections |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Deployment guide (✅ completed) |

---

## Database Status ✅

**Deployment Date:** January 17, 2026  
**Verified:** All objects created and tested

| Object Type | Count | Status |
|-------------|-------|--------|
| Tables | 10 | ✅ Verified |
| Views | 6 | ✅ Verified |
| Stored Procedures | 4 | ✅ Verified |
| Indexes | 30+ | ✅ Verified |

### Tables

| Table | Fields | Description |
|-------|--------|-------------|
| `tmi_entries` | 35 | NTML log (MIT, MINIT, DELAY, CONFIG, APREQ, etc.) |
| `tmi_programs` | 47 | GS/GDP/AFP programs with rates, scope, exemptions |
| `tmi_slots` | 22 | GDP slot allocation (RBS algorithm) |
| `tmi_advisories` | 40 | Formal advisories (GS, GDP, AFP, Reroute, etc.) |
| `tmi_reroutes` | 45 | Reroute definitions with filtering |
| `tmi_reroute_flights` | 30 | Flight assignments to reroutes |
| `tmi_reroute_compliance_log` | 9 | Compliance history snapshots |
| `tmi_public_routes` | 21 | Public route display on map |
| `tmi_events` | 18 | Unified audit log |
| `tmi_advisory_sequences` | 2 | Advisory numbering by date |

### Views

| View | Description |
|------|-------------|
| `vw_tmi_active_entries` | Active NTML entries |
| `vw_tmi_active_advisories` | Active advisories |
| `vw_tmi_active_programs` | Active GDT programs |
| `vw_tmi_active_reroutes` | Active reroutes |
| `vw_tmi_active_public_routes` | Active public routes |
| `vw_tmi_recent_entries` | Entries from last 24 hours |

### Stored Procedures

| Procedure | Description |
|-----------|-------------|
| `sp_GetNextAdvisoryNumber` | Generates "ADVZY 001", "ADVZY 002", etc. |
| `sp_LogTmiEvent` | Logs to tmi_events audit table |
| `sp_ExpireOldEntries` | Auto-expires/activates based on time |
| `sp_GetActivePublicRoutes` | Returns active routes (auto-expires first) |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           INPUT SOURCES                                 │
├─────────────────────────────────────────────────────────────────────────┤
│   Discord Bot          TypeForm/Zapier          PERTI Website          │
│   (Slash Commands)     (Webhooks)               (Web Forms)            │
│        │                    │                        │                 │
│        └────────────────────┼────────────────────────┘                 │
│                             ▼                                          │
│                    ┌─────────────────┐                                 │
│                    │  PERTI PHP API  │  ◄── Single source of truth    │
│                    │  /api/tmi/*     │      ✅ DEPLOYED                │
│                    └────────┬────────┘                                 │
│                             │                                          │
│                             ▼                                          │
│        ┌─────────────────────────────────────────┐                     │
│        │           VATSIM_TMI Database           │  ✅ DEPLOYED        │
│        │        (Azure SQL on vatsim.db)         │                     │
│        ├─────────────────────────────────────────┤                     │
│        │  tmi_entries      tmi_programs          │                     │
│        │  tmi_advisories   tmi_slots             │                     │
│        │  tmi_reroutes     tmi_reroute_flights   │                     │
│        │  tmi_public_routes tmi_events           │                     │
│        └─────────────────────────────────────────┘                     │
│                             │                                          │
│              ┌──────────────┼──────────────┐                           │
│              ▼              ▼              ▼                           │
│         SWIM API       Discord        Map Display                      │
│        (External)      (Posting)      (Public Routes)                  │
└─────────────────────────────────────────────────────────────────────────┘
```

## Database Architecture

```
Azure SQL Server: vatsim.database.windows.net
├── VATSIM_ADL    ($15/mo S0)   - Flight data, adl_flight_tmi
├── SWIM_API     ($5/mo Basic)  - Public API (read-cached)
└── VATSIM_TMI   ($5/mo Basic)  - TMI data ✅ DEPLOYED
                 ─────────────
                 Total: ~$25/mo
```

---

## API File Structure

```
api/tmi/
├── .htaccess           # Apache URL rewriting
├── web.config          # IIS URL rewriting
├── helpers.php         # Common functions, auth, response handling
├── index.php           # API info endpoint
├── active.php          # Get all active TMI data
├── entries.php         # NTML entries CRUD
├── programs.php        # GDT programs CRUD
├── advisories.php      # Advisories CRUD
├── public-routes.php   # Public routes CRUD
└── reroutes.php        # Reroutes CRUD ✅
```

---

## Configuration

Add to `load/config.php`:

```php
// TMI Database (Azure SQL - Traffic Management)
define("TMI_SQL_HOST", "vatsim.database.windows.net");
define("TMI_SQL_DATABASE", "VATSIM_TMI");
define("TMI_SQL_USERNAME", "TMI_admin");
define("TMI_SQL_PASSWORD", "your_password_here");
```

---

## Cost Summary

| Component | Monthly | Annual |
|-----------|---------|--------|
| VATSIM_TMI (Basic tier) | $4.99 | $59.88 |
| Event scale-ups (~6/year) | ~$2 | ~$12 |
| **Total** | **~$7** | **~$72** |

---

## Remaining Work

### High Priority
1. **Update existing GDT files** - Migrate `gs/*.php` and `gdp_*.php` to use new `tmi_programs` table
2. **Test Discord posting** - Verify NTML format output matches NTML_2020.txt

### Medium Priority  
3. **Discord bot integration** - Update bot to call PHP API
4. **GDT stored procedures** - `sp_TMI_GenerateSlots`, `sp_TMI_AssignFlight`, etc.
5. **Data migration** - Move existing public routes from MySQL if any

### Lower Priority
6. **UI updates** - Update `gdt.js` for new API structure

### Completed ✅
- `reroutes.php` endpoint (Jan 17, 2026)
- NTML parser & Discord format alignment (Jan 17, 2026)
- SWIM TMI endpoints (Jan 17, 2026): index, entries, advisories, reroutes, routes

---

## Verification

Test the deployment:
```bash
curl "https://perti.vatcscc.org/scripts/tmi/verify_deployment.php?allow=1"
```

---

*Last Updated: January 17, 2026*
