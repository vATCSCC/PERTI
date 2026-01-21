# GS Flight Eligibility Fix - Transition Summary
## Migration 009: Add gs_flag to ADL View

**Date:** January 21, 2026  
**Ticket:** GDT-BUGFIX-001  
**Status:** Ready to Deploy

---

## Problem Statement

The GDT Preview/Simulate feature was displaying flights that are ineligible for EDCT assignment (already departed, airborne, or arrived flights). This violated FSM business rules where Ground Stops only apply to pre-departure flights.

### Symptoms
- Flights with `phase='enroute'` appeared in GS preview
- Flights with `phase='arrived'` appeared in preview tables  
- "Flights Matching GS Filters" table showed airborne aircraft
- No phase-based filtering was occurring

### Root Cause

The frontend (`gdt.js` lines 901-1218) expects a `gs_flag` field from the ADL API:

```javascript
// gdt.js line 901-912
var rawGsFlag = (typeof f.gs_flag !== "undefined" ? f.gs_flag 
                 : (typeof f.GS_FLAG !== "undefined" ? f.GS_FLAG : null));
var gsFlag = 0;
if (rawGsFlag === true || rawGsFlag === 1 || rawGsFlag === "1" || 
    rawGsFlag === "true" || rawGsFlag === "TRUE") {
    gsFlag = 1;
}

// gdt.js line 1214-1218
if (enforceGsFlag) {
    rows = rows.filter(function(r) {
        return r.gsFlag === 1;
    });
}
```

**However**, `gs_flag` was never returned by the ADL API because:
1. It wasn't defined in `vw_adl_flights` view
2. It wasn't computed in `AdlQueryHelper.php` normalized queries

---

## Solution

### 1. Database Migration: `009_add_gs_flag_eligibility.sql`

**File:** `/adl/migrations/tmi/009_add_gs_flag_eligibility.sql`

Updates `vw_adl_flights` view to include computed `gs_flag` column:

```sql
CASE 
    WHEN c.phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 
    ELSE 0 
END AS gs_flag
```

### 2. API Update: `AdlQueryHelper.php`

**File:** `/api/adl/AdlQueryHelper.php`

Added `gs_flag` computation to `buildCurrentFlightsNormalizedQuery()`:

```php
// GS Eligibility Flag (computed)
// Pre-departure flights (prefile, taxiing, scheduled) are eligible for TMI control
// Airborne and completed flights are NOT eligible
CASE WHEN c.phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 ELSE 0 END AS gs_flag,
```

---

## GS Eligibility Matrix

| Phase | gs_flag | Description | FSM Status |
|-------|---------|-------------|------------|
| `prefile` | **1** | Flight plan filed, pilot not connected | P - Prefiled |
| `taxiing` | **1** | On ground at departure airport | T - Taxiing |
| `scheduled` | **1** | Scheduled flight, not yet active | S - Scheduled |
| `departed` | 0 | Just took off, climbing | D - Departed |
| `enroute` | 0 | Cruising at altitude | E - Enroute |
| `descending` | 0 | On approach to destination | A - Arriving |
| `arrived` | 0 | Landed at destination | Z - Arrived |
| `disconnected` | 0 | Lost connection mid-flight | X - Disconnected |
| NULL/other | 0 | Unknown phase | - |

---

## Deployment Steps

### Step 1: Deploy Database Migration

Run on **VATSIM_ADL** database (Azure SQL):

```powershell
sqlcmd -S your-server.database.windows.net -d VATSIM_ADL -U admin -P "password" -i adl/migrations/tmi/009_add_gs_flag_eligibility.sql
```

Or via Azure Portal Query Editor:
1. Open VATSIM_ADL database
2. Run `adl/migrations/tmi/009_add_gs_flag_eligibility.sql`
3. Verify view updated: `SELECT TOP 1 gs_flag FROM vw_adl_flights`

### Step 2: Deploy PHP Files

Files to deploy via GitHub Actions or manual push:
- `/api/adl/AdlQueryHelper.php`

### Step 3: Verify Fix

1. **Test ADL API:**
   ```
   GET https://perti.vatcscc.org/api/adl/current.php?arr=KJFK&limit=10
   ```
   - Response should include `gs_flag` field (0 or 1)

2. **Test GDT Preview:**
   - Create GS for busy airport (KJFK, KATL)
   - Click "Preview Impacted Flights"
   - Only pre-departure flights should appear
   - No enroute/descending/arrived flights

3. **Test gs_flag Values:**
   ```sql
   SELECT phase, gs_flag, COUNT(*) 
   FROM vw_adl_flights 
   WHERE is_active = 1 
   GROUP BY phase, gs_flag 
   ORDER BY gs_flag DESC, phase;
   ```

---

## Rollback Procedure

If issues occur, revert the view to previous definition:

```sql
-- Run migration 008 to restore previous view
-- (Copy from 008_view_compute_missing_times.sql)
```

---

## Related Documentation

- **FSM User Guide Chapter 19** - Ground Stop operations
- **FADT Spec v4.3** - Flight phases P/S/T/D/E/A/Z
- **GDT Session Doc** - `/docs/tmi/GDT_Session_20260121.md`
- **GDT Design Doc** - `/GDT_Unified_Design_Document_v1.1.md`

---

## Files Changed

| File | Change Type |
|------|-------------|
| `adl/migrations/tmi/009_add_gs_flag_eligibility.sql` | NEW |
| `api/adl/AdlQueryHelper.php` | MODIFIED |
| `docs/tmi/GDT_Session_20260121.md` | UPDATED |
| `docs/tmi/GS_Eligibility_Fix_Transition.md` | NEW (this file) |

---

*Author: Claude AI Assistant*  
*Reviewed: Pending*  
*Deployed: Pending*
