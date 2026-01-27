# TMI Publisher v1.7.0 - Active TMI Display Enhancement

## Summary

This version adds an enhanced Active TMI Display tab modeled after the FAA's Restrictions and Advisory Database pages:
- https://www.fly.faa.gov/restrictions/restrictions
- https://www.fly.faa.gov/adv/advADB

## Key Features

### 1. FAA-Style Restrictions Table
- Column layout matches FAA format: **REQUESTING | PROVIDING | RESTRICTION | START TIME | STOP TIME**
- Date/time format: `MM/DD/YYYY HHMM` (FAA standard)
- Restriction text format: `HHMM-HHMM [description] FAC:FAC`
- Clickable rows show full details modal
- Cancel button for active entries

### 2. Status Summary Cards
Four count cards at top of display:
- **Active** (green) - Currently active TMIs
- **Scheduled** (blue) - Future TMIs awaiting start time
- **Cancelled** (gray) - Recently cancelled (last 4 hours)
- **Advisories** (blue) - Active advisory count

### 3. Filter Controls
- **Requesting Facility** dropdown - All CONUS ARTCCs
- **Providing Facility** dropdown - All CONUS ARTCCs  
- **Type** dropdown - MIT, MINIT, STOP, APREQ/CFR, TBM, CONFIG, DELAY, GDP, GS
- **Status** dropdown - Active, Scheduled, Recently Cancelled, All
- Apply/Reset buttons

### 4. Auto-Refresh
- 60-second refresh interval (matches FAA)
- Countdown timer shows seconds until next refresh
- Last updated timestamp in UTC
- Manual "Refresh Now" button

### 5. Advisory Cards
- Expandable/collapsible cards for each advisory
- Color-coded headers by type (HOTLINE=red, SWAP=yellow, OPSPLAN=blue)
- FAA-style format: `ATCSCC ADVZY [NUM] [FAC] [DATE] [SUBJECT]`
- Monospace preformatted body text

### 6. Cancel Action
- Cancel button on each active restriction/advisory
- Confirmation dialog with optional reason
- Calls DELETE endpoint to soft-cancel entries

## Files Modified

### tmi-publish.php
- **Version**: 1.1.0 → 1.7.0
- Replaced simple Active TMIs panel with FAA-style display
- Added status header with refresh timer
- Added summary count cards
- Added filter controls section  
- Added restrictions table (FAA format)
- Added advisories container with expandable cards
- Updated CSS version to 1.2
- Added tmi-active-display.js include

### assets/css/tmi-publish.css
- **Version**: 1.1 → 1.2
- Added Active TMI Display styles:
  - `.tmi-status-header` - Status header gradient
  - `.tmi-status-count`, `.tmi-status-label` - Count card styles
  - `#restrictionsTable` styles - FAA blue header, hover effects
  - `.advisory-card`, `.advisory-text` - Expandable card styles
  - `.refresh-timer` - Auto-refresh countdown display
  - Filter controls styling
  - Empty state styling
  - Status badge styles for scheduled/cancelled rows

### assets/js/tmi-active-display.js (NEW)
- **Version**: 1.0.0
- Self-contained module for Active TMI tab
- Features:
  - `loadActiveTmis()` - Fetches from api/mgt/tmi/active.php
  - `renderRestrictions()` - Builds FAA-style table rows
  - `renderAdvisories()` - Builds expandable advisory cards
  - `applyFilters()`, `resetFilters()` - Filter management
  - `showRestrictionDetails()` - Detail modal with SweetAlert2
  - `cancelTmi()`, `performCancel()` - Cancel action handling
  - Auto-refresh with countdown timer
  - Proper UTC time formatting

## API Dependencies

- **GET** `api/mgt/tmi/active.php` - Fetches active/scheduled/cancelled TMIs
  - Returns: `{ success, timestamp, counts, data: { active, scheduled, cancelled } }`
  
- **DELETE** `api/tmi/entries.php?id={id}` - Cancels NTML entry
- **DELETE** `api/tmi/advisories.php?id={id}` - Cancels advisory

## Database Dependencies

Uses existing tables in VATSIM_TMI database:
- `tmi_entries` - NTML entry storage
- `tmi_advisories` - Advisory storage

## Browser Compatibility

- Requires ES6+ (arrow functions, template literals)
- Uses jQuery for DOM manipulation
- Uses SweetAlert2 for modals/confirmations
- Bootstrap 4 components

## Testing Checklist

- [ ] Active TMIs tab loads without errors
- [ ] Auto-refresh countdown works (60 seconds)
- [ ] Refresh Now button loads fresh data
- [ ] Filter by Requesting Facility
- [ ] Filter by Providing Facility
- [ ] Filter by Type
- [ ] Filter by Status (Active/Scheduled/Cancelled/All)
- [ ] Reset Filters works
- [ ] Click restriction row shows detail modal
- [ ] Cancel button shows confirmation
- [ ] Cancel action calls API and refreshes
- [ ] Advisory cards expand/collapse
- [ ] Advisory cancel button works
- [ ] Empty state displays correctly when no TMIs
- [ ] Status counts update after refresh

## Known Limitations

1. Database must be configured (TMI_SQL_* constants in config.php)
2. Cancel action requires user authentication
3. Advisory cancel endpoint may need implementation (api/tmi/advisories.php)

## Next Steps

Potential future enhancements:
- Add advisory cancel endpoint if not yet implemented
- Add export to CSV/PDF
- Add map visualization of affected facilities
- Add sound/notification on new TMIs
- Add filter persistence via localStorage
