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

---

## Files Modified

### PHP Files
| File | Changes |
|------|---------|  
| `tmi-publish.php` | User profile modal, clickable username, source filter dropdown, CSS/JS version bumps |
| `api/mgt/tmi/active.php` | Source filter parameter (PRODUCTION/STAGING/ALL), updated query functions |

### JavaScript Files
| File | Changes |
|------|---------|  
| `assets/js/tmi-publish.js` | Hotline form rebuild, facility selector, profile functions, container ID fixes |
| `assets/js/tmi-active-display.js` | Source filter support, simplified buildFilterControls, updated filter state |

### CSS Files
| File | Changes |
|------|---------|
| `assets/css/tmi-publish.css` | Queue button styling, facility selector wrapper |

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.7.0 | Jan 27, 2026 | Category:Cause implementation, Active TMI Display |
| 1.7.1 | Jan 27, 2026 | Container ID fixes, form loading fixes |
| 1.7.2 | Jan 27, 2026 | Queue button text fallbacks |
| 1.8.0 | Jan 27, 2026 | Hotline overhaul, user profile, NTML improvements |

---

## Outstanding Items

The following items from the original request list still need implementation:

### High Priority
1. **Airport CONFIG presets** - Pull from database table used by airport_config.php

### Medium Priority
2. **Airport FAA/ICAO code matching** - Auto-match from apts.csv or dbo.apts table
3. **NTML date/time fields** - Add date pickers for start/end times (currently time-only)

### Low Priority
4. **Qualifier dropdown sizing** - Right-size to prevent truncation

### Resolved
- **Active TMIs toggle** - Implemented: Source filter dropdown (Production/Staging/All)
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
