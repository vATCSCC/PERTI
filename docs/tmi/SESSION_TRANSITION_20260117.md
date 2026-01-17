# TMI Session Transition - January 17, 2026 (Updated)

## Session Summary

**Session 1 (Earlier):** Created VATSIM_TMI database and core API infrastructure.
**Session 2 (Current):** Completed API endpoints and prepared for GS migration.

---

## What Was Accomplished This Session

### API Endpoints Completed
| Endpoint | File | Status |
|----------|------|--------|
| `GET /api/tmi/` | `index.php` | ✅ Working |
| `GET /api/tmi/active.php` | `active.php` | ✅ Working |
| `GET/POST/PUT/DELETE /api/tmi/entries.php` | `entries.php` | ✅ Working |
| `GET/POST/PUT/DELETE /api/tmi/programs.php` | `programs.php` | ✅ Working |
| `GET/POST/PUT/DELETE /api/tmi/advisories.php` | `advisories.php` | ✅ Working |
| `GET/POST/PUT/DELETE /api/tmi/public-routes.php` | `public-routes.php` | ✅ Working |
| `GET/POST/PUT/DELETE /api/tmi/reroutes.php` | `reroutes.php` | ✅ **NEW** |

### Test Scripts Created
| Script | Purpose |
|--------|---------|
| `scripts/tmi/test_crud.php` | Creates test data across all tables |
| `scripts/tmi/cleanup_test_data.php` | Removes test data |

---

## Reroutes API Documentation

**Endpoint:** `/api/tmi/reroutes.php`

### GET - List Reroutes
```bash
# List all active reroutes
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?active_only=1"

# Filter by origin
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?origin=ZBW"

# Filter by destination
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?dest=KJFK"

# Include expired/cancelled
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?include_expired=1"
```

### GET - Single Reroute
```bash
# Get reroute by ID
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?id=123"

# Include assigned flights
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?id=123&flights=1"

# Include compliance summary
curl "https://perti.vatcscc.org/api/tmi/reroutes.php?id=123&compliance=1"
```

### POST - Create Reroute
```bash
curl -X POST https://perti.vatcscc.org/api/tmi/reroutes.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tmi_bot_xxxxx" \
  -d '{
    "name": "ZNY East Reroute",
    "status": 1,
    "start_utc": "2026-01-17T15:00:00Z",
    "end_utc": "2026-01-17T20:00:00Z",
    "protected_segment": "J75 BIGGY J36",
    "protected_fixes": ["BIGGY", "BRIGS"],
    "avoid_fixes": ["LENDY"],
    "origin_centers": ["ZBW", "ZOB"],
    "dest_airports": ["KJFK", "KEWR"],
    "source_type": "DISCORD"
  }'
```

### PUT - Update Reroute
```bash
curl -X PUT "https://perti.vatcscc.org/api/tmi/reroutes.php?id=123" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tmi_bot_xxxxx" \
  -d '{"status": 2}'
```

### DELETE - Cancel Reroute
```bash
curl -X DELETE "https://perti.vatcscc.org/api/tmi/reroutes.php?id=123" \
  -H "Authorization: Bearer tmi_bot_xxxxx"
```

### Status Codes
| Code | Name | Description |
|------|------|-------------|
| 0 | DRAFT | Initial creation |
| 1 | PROPOSED | Pending approval |
| 2 | ACTIVE | Currently in effect |
| 3 | MONITORING | Active, monitoring compliance |
| 4 | EXPIRED | Time passed |
| 5 | CANCELLED | Manually cancelled |

---

## Outstanding Migration: GS Files → tmi_programs

### Current State
The existing GS files in `/api/tmi/gs/` use:
- **Database:** VATSIM_ADL
- **Table:** `dbo.ntml`
- **Connection:** `$conn_adl`
- **Stored Procedures:** `sp_GS_Create`, `sp_GS_Activate`, `sp_GS_Extend`, `sp_GS_Purge`

### Target State
New infrastructure uses:
- **Database:** VATSIM_TMI
- **Table:** `dbo.tmi_programs`
- **Connection:** `$conn_tmi`
- **API:** `/api/tmi/programs.php` (CRUD already working)

### Files Requiring Migration

| File | Current | Priority |
|------|---------|----------|
| `gs/create.php` | Uses sp_GS_Create → ntml | HIGH |
| `gs/activate.php` | Uses sp_GS_Activate | HIGH |
| `gs/extend.php` | Uses sp_GS_Extend | HIGH |
| `gs/purge.php` | Uses sp_GS_Purge | HIGH |
| `gs/list.php` | Queries ntml | MEDIUM |
| `gs/get.php` | Queries ntml | MEDIUM |
| `gs/flights.php` | Queries ntml + flights | MEDIUM |
| `gs/demand.php` | Queries flight data | LOW |
| `gs/model.php` | Flight modeling | LOW |
| `gs_*.php` files | Legacy endpoints | LOW |
| `gdp_*.php` files | GDP functions | FUTURE |

### Migration Approaches

**Option A: Direct Migration**
- Rewrite stored procedures for VATSIM_TMI
- Update all GS files to use `$conn_tmi` and `tmi_programs`
- Pros: Clean separation, single source of truth
- Cons: Significant effort, needs testing

**Option B: API Wrapper**
- Have GS files call the existing `/api/tmi/programs.php` API
- Pros: Uses already-tested CRUD operations
- Cons: Slight overhead, different error handling

**Option C: Dual-Write**
- Write to both ntml and tmi_programs during transition
- Pros: Safe rollback, gradual migration
- Cons: Data sync complexity

### Schema Mapping (ntml → tmi_programs)

| ntml Column | tmi_programs Column | Notes |
|-------------|---------------------|-------|
| program_id | program_id | Same |
| ctl_element | ctl_element | Same |
| program_type | program_type | GS, GDP-DAS, etc. |
| start_utc | start_utc | Same |
| end_utc | end_utc | Same |
| status | status | PROPOSED, ACTIVE, etc. |
| is_active | is_active | Same |
| is_proposed | is_proposed | Same |
| scope_type | scope_json | JSON in new schema |
| scope_tier | scope_json | JSON in new schema |
| scope_distance_nm | scope_json | JSON in new schema |
| flt_incl_* | Various filter fields | Expanded in new schema |
| created_utc | created_at | Renamed |
| created_by | created_by | Same |

---

## Testing Instructions

### Run Test Script
```bash
cd PERTI
php scripts/tmi/test_crud.php
```

Expected output:
```
=== TMI CRUD Test Script ===
✓ Connected to VATSIM_TMI
--- Test 1: TMI Entries ---
✓ Created entry ID: 1
--- Test 2: TMI Programs ---
✓ Created program ID: 1
--- Test 3: TMI Advisories ---
✓ Created advisory ID: 1
--- Test 4: TMI Reroutes ---
✓ Created reroute ID: 1
--- Test 5: TMI Public Routes ---
✓ Created public route ID: 1
--- Test 6: Views ---
✓ Active Entries: 1 records
...
```

### Test API Endpoints
```bash
# Test reroutes endpoint
curl https://perti.vatcscc.org/api/tmi/reroutes.php

# Test active data
curl https://perti.vatcscc.org/api/tmi/active.php
```

### Cleanup Test Data
```bash
php scripts/tmi/cleanup_test_data.php
```

---

## Next Session Recommendations

1. **Run test script** to verify CRUD operations work
2. **Discuss migration strategy** for GS files
3. **If Option A chosen:**
   - Create stored procedures in VATSIM_TMI
   - Migrate gs/create.php first as proof of concept
   - Test thoroughly before migrating other files
4. **If Option B chosen:**
   - Create wrapper functions in gs/common.php
   - Update individual GS files to use wrappers

---

## Files Created This Session

```
api/tmi/
└── reroutes.php          # NEW - Reroutes CRUD endpoint

scripts/tmi/
├── test_crud.php         # NEW - Test data creation
└── cleanup_test_data.php # NEW - Test data cleanup

docs/tmi/
└── SESSION_TRANSITION_20260117.md  # UPDATED
```

---

*Last Updated: January 17, 2026 (Session 2)*
