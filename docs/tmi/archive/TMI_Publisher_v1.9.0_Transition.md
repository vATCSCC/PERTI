# TMI Publisher v1.9.0 Transition Summary
**Date:** January 28, 2026
**Session:** TMI Publisher UI Refinements & Advisory Enhancements
**Version:** 1.8.3 → 1.9.0

---

## Overview

This session implemented multiple UI refinements and feature enhancements for the TMI Publisher. Changes focused on simplifying the user interface, expanding facility support, adding traffic flow options, improving datetime handling for advisories, and adding filter state persistence.

---

## Feature Enhancements (v1.9.0)

### 1. Simplified Reason Labels

**Before:** "Reason (Category:Cause)" - verbose label
**After:** "Reason" - simplified display

The reason selector label has been simplified across all NTML forms while maintaining the same Category:Cause dropdown functionality.

### 2. Traffic Flow Options

Added traffic flow selector to MIT/MINIT, STOP, APREQ, CFR, and TBM forms:

**Options:**
- `ARR` - Arrivals
- `DEP` - Departures
- `OVF` - Overflights

**Output Format:**
```
MIT 20 KLAX ARR via SADDE
STOP KJFK DEP via MERIT
APREQ KATL OVF via SHINE
```

Forms affected:
- MIT/MINIT: `#ntml_traffic_flow`
- STOP: `#ntml_traffic_flow`
- APREQ: `#ntml_traffic_flow`
- TBM/TBFM: `#ntml_traffic_flow`

### 3. Reason Category Simplification

**Renamed Options:**
- "Volume (General)" → "Volume"
- TBM renamed to "TBM/TBFM (Time-Based Flow Management)"

### 4. Multiple Requesters/Providers Support

**Changes:**
- Increased maxlength from 4 to 30 characters
- Supports comma-delimited facility codes (e.g., "ZNY, ZBW, ZOB")
- Output uses `/` delimiter per FAA format: `ZNY/ZBW/ZOB`

### 5. Expanded Facility Dropdowns

Added comprehensive facility list to requester/provider dropdowns:

**US ARTCCs (22):**
```
ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX, ZKC,
ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZTL
```

**US TRACONs (Major):**
```
A80 (Atlanta), A90 (Boston), C90 (Chicago), D01 (Denver), D10 (Dallas),
I90 (Houston), L30 (Las Vegas), M98 (Minneapolis), N90 (New York),
NCT (Nor Cal), P50 (Phoenix), PCT (Potomac), S46 (Seattle), SCT (So Cal)
```

**Canadian FIRs:**
```
CZEG (Edmonton), CZQM (Moncton), CZQX (Gander), CZUL (Montreal),
CZVR (Vancouver), CZWG (Winnipeg), CZYZ (Toronto)
```

**Caribbean:**
```
TJSJ (San Juan CERAP)
```

**Mexico:**
```
MMEX (Mexico City ACC), MMTY (Monterrey ACC)
```

### 6. Advisory DateTime Fields

Changed from time-only to datetime-local for all advisories:

**SWAP Advisory:**
- `adv_effective_time` → `adv_effective_datetime`
- Updated `buildSwapPreview()` to parse datetime-local format
- Correctly calculates duration across midnight

**Free-form Advisory:**
- Added `adv_valid_from` (datetime-local)
- Added `adv_valid_until` (datetime-local)
- Updated preview to show valid period

**Ops Plan Advisory:**
- Added `adv_valid_from` (datetime-local)
- Added `adv_valid_until` (datetime-local)
- Replaced `adv_date` (readonly text) with proper datetime inputs
- Updated preview to use datetime fields instead of `getValidTimeRange()`

### 7. PERTI Plan Import for Ops Plan

**Import Button:** Added "Import PERTI Plan" button to Ops Plan form header

**Import Flow:**
1. Click "Import PERTI Plan" button
2. Select plan date in modal
3. System fetches plan data from `api/mgt/plan/get.php`
4. Data populates form fields:
   - TMIs and initiatives → Key Initiatives
   - Weather/constraints → Terminal/Enroute Constraints
   - Events → Special Events
   - Valid times → From/Until datetime fields

### 8. Active TMI Filter Persistence

**localStorage Key:** `tmi_active_filters`

**Persisted Filters:**
- Source (PRODUCTION/STAGING/ALL)
- Requesting Facility
- Providing Facility
- Type
- Status

**Behavior:**
- Filters saved on `applyFilters()`
- Filters restored on page load via `loadSavedFilters()`
- Reset clears localStorage and resets to defaults

### 9. Cancel/Edit from Active TMIs Page

**Edit Button:** Pencil icon on each active TMI entry
- Opens modal with editable fields:
  - Valid From (datetime-local, UTC)
  - Valid Until (datetime-local, UTC)
  - Value (for entries with restriction values)
- Calls `api/mgt/tmi/edit.php` on save

**Cancel Button:** X icon on each active TMI entry
- Prompts for cancellation reason
- Calls `api/mgt/tmi/cancel.php` on confirm
- Logs cancel event with actor info

### 10. Cancelled Indicator Visibility

**CSS Improvements (`tmi-publish.css`):**
- Cancelled rows: `background-color: rgba(108, 117, 125, 0.15)`
- Text color: `#495057` (readable dark gray)
- Restriction text: `text-decoration: line-through`
- Advisory cards: Header background `#6c757d` with white text

### 11. UTC Timezone Handling

**Fixed Double-Conversion Issue:**
- `parseValidTime()` in `publish.php` explicitly treats input as UTC
- Appends ' UTC' to strtotime() to prevent server timezone interpretation
- Uses `gmdate()` for output format

**API Functions Updated:**
- `api/mgt/tmi/publish.php` - `parseValidTime()`
- `api/mgt/tmi/edit.php` - `parseUtcDateTime()`

---

## API Changes

### New Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/mgt/tmi/edit.php` | POST | Edit active TMI entry or advisory |

### Edit API Request Format
```json
{
    "entityType": "ENTRY|ADVISORY",
    "entityId": 123,
    "updates": {
        "validFrom": "2026-01-28T14:00",
        "validUntil": "2026-01-28T18:00",
        "restrictionValue": 20
    },
    "userCid": "1234567",
    "userName": "John Doe"
}
```

### Cancel API (Existing)
```json
{
    "entityType": "ENTRY|ADVISORY",
    "entityId": 123,
    "reason": "Cancellation reason",
    "userCid": "1234567",
    "userName": "John Doe"
}
```

---

## Files Modified

### PHP Files
| File | Changes |
|------|---------|
| `api/mgt/tmi/publish.php` | UTC timezone fix in `parseValidTime()` |
| `api/mgt/tmi/edit.php` | NEW: Edit API for TMI entries and advisories |
| `api/mgt/tmi/cancel.php` | Unchanged (existing endpoint) |

### JavaScript Files
| File | Version | Changes |
|------|---------|---------|
| `assets/js/tmi-publish.js` | 1.9.0 | Traffic flow, multi-facility, datetime fields, PERTI import |
| `assets/js/tmi-active-display.js` | 1.2.0 | Filter persistence, edit/cancel buttons |

### CSS Files
| File | Version | Changes |
|------|---------|---------|
| `assets/css/tmi-publish.css` | 1.7.0 | Cancelled indicator visibility improvements |

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.9.0 | Jan 28, 2026 | Traffic flow, multi-facility, advisory datetime, PERTI import, filter persistence |
| 1.8.3 | Jan 28, 2026 | NTML datetime fields (date + time pickers) |
| 1.8.2 | Jan 28, 2026 | Airport FAA/ICAO code lookup |
| 1.8.1 | Jan 27, 2026 | Airport CONFIG presets from database |
| 1.8.0 | Jan 27, 2026 | Hotline overhaul, user profile, NTML improvements |

---

## Testing Checklist

- [ ] Hard refresh (Ctrl+Shift+R) to clear cache
- [ ] Verify "Reason" label appears (not "Reason (Category:Cause)")
- [ ] MIT form: Traffic flow dropdown present with ARR/DEP/OVF options
- [ ] STOP form: Traffic flow dropdown present
- [ ] APREQ form: Traffic flow dropdown present
- [ ] TBM form: Traffic flow dropdown present and labeled "TBM/TBFM"
- [ ] Reason dropdown: "Volume" option (not "Volume (General)")
- [ ] Requester field: Can enter "ZNY, ZBW" (multiple facilities)
- [ ] Provider field: Can enter "ZDC, ZOB, ZTL" (up to 30 chars)
- [ ] Profile modal: All ARTCCs, TRACONs, and international facilities in dropdown
- [ ] SWAP advisory: Datetime picker for effective time (not time-only)
- [ ] Free-form advisory: Valid From and Valid Until datetime pickers
- [ ] Ops Plan advisory: Valid From and Valid Until datetime pickers
- [ ] Ops Plan: "Import PERTI Plan" button visible in header
- [ ] Ops Plan: Click import → Date picker modal appears
- [ ] Active TMIs: Edit button (pencil) on each entry
- [ ] Active TMIs: Click edit → Modal with datetime fields
- [ ] Active TMIs: Cancel button (X) on each entry
- [ ] Active TMIs: Click cancel → Reason prompt appears
- [ ] Active TMIs: Apply filter → Refresh page → Filter persists
- [ ] Active TMIs: Reset filters → Filter persistence cleared
- [ ] Cancelled entries: Row has light gray background, readable text
- [ ] Cancelled advisories: Card header has gray background with white text

---

## Code References

### Traffic Flow Selector HTML
```javascript
<div class="col-md-3">
    <label class="form-label small text-muted">Traffic Flow</label>
    <select class="form-control" id="ntml_traffic_flow">
        <option value="">All Traffic</option>
        <option value="ARR">Arrivals</option>
        <option value="DEP">Departures</option>
        <option value="OVF">Overflights</option>
    </select>
</div>
```

### Multi-Facility Output Conversion
```javascript
// Input: "ZNY, ZBW, ZOB" (comma-delimited)
// Output: "ZNY/ZBW/ZOB" (FAA slash format)
const facilities = value.split(/[,\s]+/).filter(f => f.trim()).join('/');
```

### Filter State Persistence
```javascript
const FILTER_STORAGE_KEY = 'tmi_active_filters';

function saveFilters() {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(state.filters));
}

function loadSavedFilters() {
    const saved = localStorage.getItem(FILTER_STORAGE_KEY);
    if (saved) {
        state.filters = JSON.parse(saved);
        // Update UI dropdowns...
    }
}
```

### DateTime Parsing for UTC
```javascript
// Parse datetime-local as UTC (append 'Z' for UTC interpretation)
const startDate = new Date(effDateTime + 'Z');
const startDay = String(startDate.getUTCDate()).padStart(2, '0');
const startHour = startDate.getUTCHours();
```

---

## Related Documents

- `TMI_Publisher_v1.8.0_Transition.md` - Previous version transition
- `NTML_Advisory_Formatting_Spec.md` - NTML format specification
- `docs/tmi/DATABASE.md` - TMI database schema

---

*End of Transition Document*
