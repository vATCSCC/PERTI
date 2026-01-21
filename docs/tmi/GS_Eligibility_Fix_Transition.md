# GS Flight Eligibility Fix - Transition Summary
## ADL gs_flag Computation + Client-Side Fallback

**Date:** January 21, 2026  
**Ticket:** GDT-BUGFIX-001  
**Status:** DEPLOYED

---

## Problem Statement

The GDT Preview/Simulate feature was displaying flights that are ineligible for EDCT assignment (already departed, airborne, or arrived flights). This violated FSM business rules where Ground Stops only apply to pre-departure flights.

### Symptoms
- Flights with `phase='enroute'` appeared in GS preview
- Flights with `phase='arrived'` appeared in preview tables  
- "Flights Matching GS Filters" table showed airborne aircraft
- No phase-based filtering was occurring

### Root Cause Analysis

The frontend (`gdt.js` lines 901-1218) expects a `gs_flag` field from the ADL API:

```javascript
// gdt.js - gs_flag enforcement logic
var rawGsFlag = (typeof f.gs_flag !== "undefined" ? f.gs_flag 
                 : (typeof f.GS_FLAG !== "undefined" ? f.GS_FLAG : null));
var gsFlag = 0;
if (rawGsFlag === true || rawGsFlag === 1 || rawGsFlag === "1" || 
    rawGsFlag === "true" || rawGsFlag === "TRUE") {
    gsFlag = 1;
}

// Filtering logic
rows = rows.filter(function(r) {
    return r.gsFlag === 1;
});
```

**Root Causes Identified:**

1. **View Mode Issue:** `AdlQueryHelper.php` view mode query used `SELECT *` which doesn't compute `gs_flag`
2. **No Client-Side Fallback:** If ADL API didn't return `gs_flag`, flights defaulted to `gsFlag=0` and were filtered out OR the filtering wasn't applied at all
3. **VATSIM Data Gap:** `filterFlight()` for VATSIM data didn't set `gsFlag` at all, leaving it undefined

---

## Solution Implementation

### 1. API Fix: `AdlQueryHelper.php` (View Mode)

Added computed `gs_flag` to the view mode query so both view and normalized modes return consistent data:

```php
// buildCurrentFlightsViewQuery() - BEFORE
$sql = "SELECT TOP {$limit} * FROM dbo.vw_adl_flights";

// buildCurrentFlightsViewQuery() - AFTER
$sql = "SELECT TOP {$limit} *,
        CASE WHEN phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 ELSE 0 END AS gs_flag
        FROM dbo.vw_adl_flights";
```

**Location:** `/api/adl/AdlQueryHelper.php` lines 99-103

### 2. Client-Side Fallback: `gdt.js` - filterAdlFlight()

Added phase-based fallback when API doesn't provide `gs_flag`:

```javascript
// GS eligibility flag from ADL (or computed from phase)
// Pre-departure phases eligible for TMI control: prefile, taxiing, scheduled
// Airborne/completed phases NOT eligible: departed, enroute, descending, arrived, disconnected
var rawGsFlag = (typeof f.gs_flag !== "undefined"
                 ? f.gs_flag
                 : (typeof f.GS_FLAG !== "undefined" ? f.GS_FLAG : null));
var gsFlag = 0;
if (rawGsFlag === true || rawGsFlag === 1 || rawGsFlag === "1" ||
    rawGsFlag === "true" || rawGsFlag === "TRUE") {
    gsFlag = 1;
} else if (rawGsFlag === null || typeof rawGsFlag === "undefined") {
    // Fallback: compute GS eligibility from phase if API didn't provide gs_flag
    // Only pre-departure flights can receive EDCTs
    var eligiblePhases = ["PREFILE", "TAXIING", "SCHEDULED", "P", "T", "S"];
    if (eligiblePhases.indexOf(flightStatus) !== -1) {
        gsFlag = 1;
    }
}
```

**Location:** `/assets/js/gdt.js` lines 901-921

### 3. VATSIM Data Fix: `gdt.js` - filterFlight()

Added `gsFlag` to VATSIM flight records with appropriate defaults:

```javascript
// GS eligibility for VATSIM data:
// - PREFILE: Always eligible (not yet connected, pre-departure)
// - PILOT: Unknown without ADL phase data; default to NOT eligible
//   (ADL augmentation will set correct gsFlag based on actual phase)
var vatsimGsFlag = (sourceTag === "PREFILE") ? 1 : 0;

return {
    // ... other fields ...
    gsFlag: vatsimGsFlag
};
```

**Location:** `/assets/js/gdt.js` lines 849-877

### 4. Filtering Logic Update: `gdt.js` - renderFlightsFromAdl()

Updated filtering to always apply gsFlag check (not just when ADL is available):

```javascript
// Augment timing information with ADL when available
var hasAdlData = GS_ADL && Array.isArray(GS_ADL.flights) && GS_ADL.flights.length;
if (hasAdlData) {
    augmentRowsWithAdl(rows);
}

// Filter to keep only GS-eligible flights (gs_flag = 1)
// Eligibility is determined by flight phase:
//   - Eligible: prefile, taxiing, scheduled (pre-departure)
//   - NOT Eligible: departed, enroute, descending, arrived, disconnected
rows = rows.filter(function(r) {
    return r.gsFlag === 1;
});
```

**Location:** `/assets/js/gdt.js` lines 1224-1245

### 5. UI Fix: `gdt.php` - Button Icons

Added visible text labels to Flight List and Model buttons (fallback if FontAwesome icons don't load):

```html
<button class="btn btn-outline-secondary" id="gs_view_flight_list_btn" type="button" title="View GS Flight List">
    <i class="fas fa-list-alt mr-1"></i>List
</button>
<button class="btn btn-outline-primary" id="gs_open_model_btn" type="button" title="Open Model GS Data Graph">
    <i class="fas fa-chart-line mr-1"></i>Model
</button>
```

**Location:** `/gdt.php` lines 674-680

---

## GS Eligibility Matrix

| Phase | gs_flag | Description | FSM Status | Can Receive EDCT? |
|-------|---------|-------------|------------|-------------------|
| `prefile` | **1** | Flight plan filed, pilot not connected | P - Prefiled | ✅ Yes |
| `taxiing` | **1** | On ground at departure airport | T - Taxiing | ✅ Yes |
| `scheduled` | **1** | Scheduled flight, not yet active | S - Scheduled | ✅ Yes |
| `departed` | 0 | Just took off, climbing | D - Departed | ❌ No |
| `enroute` | 0 | Cruising at altitude | E - Enroute | ❌ No |
| `descending` | 0 | On approach to destination | A - Arriving | ❌ No |
| `arrived` | 0 | Landed at destination | Z - Arrived | ❌ No |
| `disconnected` | 0 | Lost connection mid-flight | X - Disconnected | ❌ No |
| NULL/other | 0 | Unknown phase | - | ❌ No |

---

## Data Flow After Fix

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Flight Eligibility Flow                           │
└─────────────────────────────────────────────────────────────────────────────┘

1. VATSIM Data Load (filterFlight):
   ├── PREFILE → gsFlag = 1 (eligible)
   └── PILOT   → gsFlag = 0 (unknown, needs ADL confirmation)

2. ADL API Request (/api/adl/current.php):
   ├── VIEW MODE: SELECT *, CASE WHEN phase IN ('prefile','taxiing','scheduled') THEN 1 ELSE 0 END AS gs_flag
   └── NORMALIZED MODE: Same CASE expression in SELECT

3. ADL Augmentation (augmentRowsWithAdl):
   └── If ADL match found → overlay gsFlag from ADL (based on actual phase)

4. Final Filtering (renderFlightsFromAdl):
   └── rows.filter(r => r.gsFlag === 1)
       ├── Keeps: prefile, taxiing, scheduled
       └── Removes: departed, enroute, descending, arrived, disconnected
```

---

## Testing Verification

### 1. Test ADL API gs_flag Response

```bash
curl "https://perti.vatcscc.org/api/adl/current.php?arr=KJFK&limit=10" | jq '.flights[0] | {callsign, phase, gs_flag}'
```

Expected response includes `gs_flag` field:
```json
{
  "callsign": "AAL123",
  "phase": "enroute",
  "gs_flag": 0
}
```

### 2. Test GDT Preview Filtering

1. Create GS for busy airport (KJFK, KATL, KLAX)
2. Click "Preview Impacted Flights"
3. **Expected:** Only pre-departure flights appear (PREFILE/TAXIING/SCHEDULED status)
4. **Verify:** No ENROUTE, DESCENDING, ARRIVED, or DISCONNECTED flights in table

### 3. SQL Verification Query

```sql
-- Run on VATSIM_ADL database
SELECT 
    phase, 
    CASE WHEN phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 ELSE 0 END AS computed_gs_flag,
    COUNT(*) as flight_count
FROM vw_adl_flights 
WHERE is_active = 1 
GROUP BY phase 
ORDER BY computed_gs_flag DESC, phase;
```

---

## Files Changed

| File | Change Type | Description |
|------|-------------|-------------|
| `api/adl/AdlQueryHelper.php` | MODIFIED | Added gs_flag computation to view mode query |
| `assets/js/gdt.js` | MODIFIED | Added phase-based gsFlag fallback, VATSIM gsFlag defaults, always-on filtering |
| `gdt.php` | MODIFIED | Added visible text labels to Flight List and Model buttons |
| `docs/tmi/GS_Eligibility_Fix_Transition.md` | UPDATED | This transition document |

---

## Rollback Procedure

If issues occur, revert the files via git:

```bash
cd /path/to/PERTI
git checkout HEAD~1 -- api/adl/AdlQueryHelper.php
git checkout HEAD~1 -- assets/js/gdt.js
git checkout HEAD~1 -- gdt.php
```

---

## Related Documentation

- **FSM User Guide Chapter 19** - Ground Stop operations
- **FADT Spec v4.3** - Flight phases P/S/T/D/E/A/Z
- **GDT Design Doc** - `/GDT_Unified_Design_Document_v1.md`
- **ADL Query Helper** - Supports both 'view' and 'normalized' query modes

---

*Author: Claude AI Assistant*  
*Date: January 21, 2026*  
*Status: DEPLOYED*
