# TMI Publisher v1.8.0 Transition Summary
**Date:** January 27, 2026  
**Session:** TMI Publisher Bug Fixes & Feature Enhancements  
**Version:** 1.7.1 → 1.7.2 → 1.8.0

---

## Overview

This session addressed multiple bug fixes for v1.7.1 and implemented significant feature enhancements culminating in v1.8.0. The changes focused on fixing form loading issues, improving Hotline advisory functionality, adding user profile management, and enhancing the NTML entry system.

---

## Bug Fixes (v1.7.1 → v1.7.2)

### Container ID Mismatches Fixed
| Location | Issue | Fix |
|----------|-------|-----|
| `tmi-publish.js:383` | `#ntml_form_container` | → `#ntmlFormContainer` |
| `tmi-publish.js:1050` | `#adv_form_container` | → `#advisoryFormContainer` |

### Default Form Loading
- Added `loadNtmlForm('MIT')` on page init
- Added `loadAdvisoryForm('OPS_PLAN')` on page init
- Forms now display immediately instead of "Loading form..."

### Advisory Type Aliases
Added case aliases to handle both HTML attribute values and internal codes:
- `case 'OPS_PLAN':` + `case 'OPSPLAN':`
- `case 'FREE_FORM':` + `case 'FREEFORM':`

### Queue Button Icons
- FontAwesome kit was returning 403 errors
- Added CDN fallback in tmi-publish.php
- Added text labels ("View", "Del") as fallbacks
- Added CSS styling for consistent button appearance

---

## Feature Enhancements (v1.8.0)

### 1. Hotline Advisory Overhaul

**Hotline Names** - Now match PERTI Plan options (index.php lines 230-241):
- NY Metro, DC Metro, Chicago, Atlanta, Florida, Texas
- East Coast, West Coast, Canada East, Canada West, Mexico, Caribbean

**Participation Options** - Expanded to 7 levels:
- MANDATORY, EXPECTED, STRONGLY ENCOURAGED, STRONGLY RECOMMENDED
- ENCOURAGED, RECOMMENDED, OPTIONAL

**Facility Selector** - New dropdown + text input pattern:
- Multi-select dropdown with all US ARTCCs, N90, Canada, Mexico, Caribbean
- Text input for typing comma-separated codes
- Bi-directional sync between select and input

**Hotline Address** - Auto-mapped based on hotline region:
- `ts.vatusa.net` - VATUSA TeamSpeak (US hotlines)
- `ts.vatcan.ca` - VATCAN TeamSpeak (Canada hotlines)
- `vATCSCC Discord, Hotline Backup voice channel` - Discord backup

**Date/Time Fields** - Changed from time-only to datetime-local:
- Start Date/Time (UTC)
- End Date/Time (UTC)

**Removed Fields:**
- Associated Restrictions (per request)
- Probability of Extension (per request)
- Custom hotline name (now uses predefined list)
- Hotline Location (replaced by address dropdown)
- Hotline PIN

### 3. Active TMI Source Filter

**Filter Dropdown** added to Active TMIs panel:
- Production (default) - Shows only published TMIs
- Staging - Shows only staged/pending TMIs  
- All Sources - Shows both production and staging

**API Enhancement** (`api/mgt/tmi/active.php`):
- New `source` parameter: `PRODUCTION`, `STAGING`, or `ALL`
- Updated query functions to support source filtering
- Backward compatible (defaults to PRODUCTION)

**JS Updates** (`tmi-active-display.js`):
- Added `source` to filters state
- `applyFilters()` reads `#filterSource` dropdown
- `resetFilters()` resets source to PRODUCTION
- `loadActiveTmis()` passes source to API

### 4. User Profile System

**Profile Modal** (`userProfileModal`):
- Accessible by clicking username in header
- Fields: Name (readonly), CID (readonly), Operating Initials, Home Facility
- Saved to localStorage
- Auto-populates on first visit if no facility set

**Features:**
- Facility dropdown with all ARTCCs, N90, DCC
- 2-3 character OI validation
- Profile persists across sessions
- Default requesting facility applied to new NTML forms

### 5. NTML Entry Improvements

**Renamed Labels:**
- "Reason category" → "Impacting Condition"
- "Cause" → "Specific Impact"

**New Qualifiers:**
- NON-RVSM added to Equipment qualifiers
- Altitude filter input: AOB120, AOA320, 140B180 format
- Speed filter input: Number in KTS

**Format Changes:**
- TBM Freeze Horizon: Now `TIME+{value}MIN`
- CFR: Kept as "CFR" (no expansion)
- Valid period & req:prov ALWAYS at end of entries

**APREQ Form:**
- Removed departure scope field (not applicable)

### 6. Default Facility Logic

When user profile has a saved facility:
- Requesting Facility auto-populates on new NTML forms
- Applied after `loadNtmlForm()` completes
- Only applies if field is empty

### 7. Airport CONFIG Presets (v1.8.1)

**New API** (`api/mgt/tmi/airport_configs.php`):
- GET endpoint for fetching airport configurations
- Accepts `airport` parameter (FAA or ICAO code)
- Returns config presets with runways and rates
- Queries `dbo.vw_airport_config_summary` and `dbo.vw_airport_config_rates`
- Falls back to MySQL `config_data` table if ADL unavailable

**CONFIG Form Enhancements**:
- Removed "Config Name" text input
- Added "Config Preset" dropdown (populated from database)
- Auto-loads presets when airport code entered
- Selecting preset populates: Arrival Runways, Departure Runways, AAR, ADR
- Weather category change updates rates (VMC vs IMC)

**New Event Handlers**:
- `initConfigFormHandlers()` - Sets up CONFIG form specific handlers
- `loadAirportConfigs(airport)` - Fetches presets from API
- `applyConfigPreset(configId)` - Populates form fields from preset

### 8. Airport FAA/ICAO Code Lookup (v1.8.2)

**Utility Functions** (`tmi-publish.js`):
- `lookupAirportCode(code, callback)` - Async lookup via API
- `initAirportLookupHandler($input, $statusEl)` - Binds blur/input events
- `performAirportLookup(code, $statusEl)` - Executes lookup and displays result
- `icaoLookupCache` - Caches results to reduce API calls

**Form Updates**:
- MIT/MINIT, STOP, TBM, DELAY forms now have `#airport_lookup_status` element
- Status shows: "JFK / KJFK (John F Kennedy Intl)" when found
- Placeholders updated to show FAA codes (e.g., "JFK" not "KJFK")
- Debounced input handler (500ms) for real-time feedback

**Existing API** (`api/util/icao_lookup.php`):
- Already existed - queries `dbo.apts` table
- Returns FAA code, ICAO code, and airport name
- Fallback logic for codes not in database (K-prefix for US)

### 9. NTML DateTime Fields (v1.8.3)

**Changed Input Types**:
- All validity time fields changed from `type="time"` to `type="datetime-local"`
- Applies to: MIT, MINIT, STOP, APREQ, TBM forms
- Users can now select both date and time for TMI validity periods

**Updated Functions**:
- `getSmartDefaultTimes()` - Returns full datetime format (YYYY-MM-DDTHH:MM)
  - Also provides `startTime`/`endTime` for backwards compatibility
  - Handles day rollover correctly when end time crosses midnight
- `formatValidTime(from, until)` - Handles both datetime-local and time-only formats
- `formatValidDateTime(from, until)` - NEW: Returns display format with dates
  - Same day: "1400-1800Z"
  - Different days: "01/28 1400-01/29 0200Z"

**Form Updates**:
- Added "Date and time in Zulu" helper text under datetime fields
- Placeholders removed (datetime-local has native picker)

### 10. Qualifier Button CSS Improvements (v1.8.3)

**CSS Changes** (`assets/css/tmi-publish.css` v1.6.0):
- Added `min-width: fit-content` - prevents text truncation
- Added `text-overflow: unset` and `overflow: visible`
- Added `overflow-x: auto` to `.qualifier-sections` - allows horizontal scroll on small screens
- Improved responsive sizing for mobile (0.7rem font, 0.6rem padding)
- Better gap spacing for mobile qualifier groups

---

## Files Modified

### PHP Files
| File | Changes |
|------|---------|  
| `tmi-publish.php` | User profile modal, clickable username, source filter dropdown, CSS/JS version bumps (v1.8.1) |
| `api/mgt/tmi/active.php` | Source filter parameter (PRODUCTION/STAGING/ALL), updated query functions |
| `api/mgt/tmi/airport_configs.php` | NEW: Airport config presets API for CONFIG form |

### JavaScript Files
| File | Changes |
|------|---------|  
| `assets/js/tmi-publish.js` | v1.8.3: DateTime pickers, CONFIG presets, FAA/ICAO lookup, Hotline form |
| `assets/js/tmi-active-display.js` | v1.1.0: Source filter support, simplified buildFilterControls |

### CSS Files
| File | Changes |
|------|---------|
| `assets/css/tmi-publish.css` | v1.6.0: Qualifier button sizing fixes, queue button styling, facility selector wrapper |

---

## Version History

| Version | Date | Changes |
|---------|------|---------|  
| 1.8.3 | Jan 28, 2026 | NTML datetime fields (date + time pickers) |
| 1.8.2 | Jan 28, 2026 | Airport FAA/ICAO code lookup |
| 1.8.1 | Jan 27, 2026 | Airport CONFIG presets from database |
| 1.8.0 | Jan 27, 2026 | Hotline overhaul, user profile, NTML improvements |
| 1.7.2 | Jan 27, 2026 | Queue button text fallbacks |
| 1.7.1 | Jan 27, 2026 | Container ID fixes, form loading fixes |
| 1.7.0 | Jan 27, 2026 | Category:Cause implementation, Active TMI Display |

---

## Outstanding Items

The following items from the original request list still need implementation:

### Resolved
- **Qualifier button sizing** - Fixed: CSS improvements for fit-content, responsive sizing (v1.8.3)
- **NTML date/time fields** - Implemented: datetime-local pickers (v1.8.3)
- **Airport FAA/ICAO code matching** - Implemented: Auto-lookup with caching (v1.8.2)
- **Airport CONFIG presets** - Implemented: API + auto-load from database (v1.8.1)
- **Active TMIs toggle** - Implemented: Source filter dropdown (v1.8.0)
- **Duplicate buttons** - Investigated: Buttons are in separate tab panels, not duplicated

---

## Testing Checklist

- [ ] Hard refresh (Ctrl+Shift+R) to clear cache
- [ ] Verify NTML form loads on page init (MIT should be selected)
- [ ] Verify Advisory form loads on page init (Ops Plan should be selected)
- [ ] Click username in header → Profile modal opens
- [ ] Save profile with facility → Requesting facility auto-populates
- [ ] Add NTML entry to queue → View/Del buttons display correctly
- [ ] Switch to Hotline advisory → Verify new form layout
- [ ] Select "Canada East" hotline → Address auto-changes to ts.vatcan.ca
- [ ] Test facility multi-select → Values sync to text input
- [ ] Type facilities in text input → Values sync to dropdown
- [ ] Active TMIs: Change source filter to "Staging" → Only staged items shown
- [ ] Active TMIs: Change source filter to "All Sources" → Both production and staging shown
- [ ] Active TMIs: Reset filters → Source returns to "Production"
- [ ] CONFIG form: Enter "JFK" in airport → Presets dropdown populates
- [ ] CONFIG form: Select a preset → Runways and rates auto-fill
- [ ] CONFIG form: Change weather to IMC → Rates update from preset
- [ ] MIT/MINIT form: Enter "JFK" → Shows "JFK / KJFK" with airport name
- [ ] STOP form: Enter "ORD" → Shows "ORD / KORD (Chicago O'Hare Intl)"
- [ ] Verify lookup caching → Second lookup of same code is instant
- [ ] MIT form: Datetime fields show date picker with today's date
- [ ] MIT form: Default times are snapped to quarter-hour
- [ ] MIT form: End time is 4 hours after start
- [ ] STOP form: Can select date spanning midnight (e.g., 2200-0200)
- [ ] APREQ form: Datetime fields work correctly
- [ ] TBM form: Datetime fields work correctly
- [ ] Qualifier buttons: "AIR CARRIER" and "PER AIRPORT" display fully without truncation
- [ ] Qualifier buttons: Groups wrap properly on mobile width screens

---

## Code References

### Container IDs (Must Match)
```javascript
// PHP (tmi-publish.php line 315)
<div id="ntmlFormContainer">

// JS (tmi-publish.js line 412)
$('#ntmlFormContainer').html(formHtml);
```

### Profile Storage
```javascript
// Key: tmi_user_profile
// Value: { oi: "XX", facility: "ZNY" }
localStorage.setItem('tmi_user_profile', JSON.stringify(profile));
```

### Hotline Address Auto-Mapping
```javascript
$('#adv_hotline_name').on('change', function() {
    const name = $(this).val() || '';
    if (name.includes('Canada')) {
        $('#adv_hotline_address').val('ts.vatcan.ca');
    } else {
        $('#adv_hotline_address').val('ts.vatusa.net');
    }
});
```

---

## Related Documents

- `TMI_Documentation_Index.md` - Master TMI documentation index
- `NTML_Advisory_Formatting_Spec.md` - NTML format specification
- `docs/tmi/NTML_Discord_Parser_Alignment_20260117.md` - Parser alignment

---

*End of Transition Document*
