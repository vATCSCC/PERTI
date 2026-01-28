# TMI Coordination System - Session Transition Document
**Date:** 2026-01-28
**Version:** 2.0.0

## Summary

This session focused on major workflow improvements to the TMI Publisher coordination system:
- **Approved proposals now appear in queue for manual publication** (instead of auto-activating)
- **Editing approved proposals restarts coordination**
- Bug fixes for edit dialog, hotline filtering, and deadline defaults

---

## Completed Changes

### 1. Approved Proposals Queue Workflow (NEW)
**Files Modified:**
- `api/mgt/tmi/coordinate.php` - Added `handlePublishApprovedProposal()` function, modified routing, stopped auto-activation
- `assets/js/tmi-publish.js` - Added Publish button UI, `handlePublishProposal()` function
- `cron/process_tmi_proposals.php` - Stopped auto-activation, added approval notification

**Previous Flow:**
1. User submits TMI for coordination
2. Facilities approve via Discord reactions
3. When fully approved, `activateProposal()` immediately creates TMI entry and posts to Discord

**New Flow:**
1. User submits TMI for coordination
2. Facilities approve via Discord reactions
3. When fully approved, status becomes `APPROVED` (not `ACTIVATED`)
4. Proposal appears in TMI Publisher queue with green row highlighting
5. User can review/edit before final publication
6. User clicks "Publish" to activate and post to Discord

**Key Changes:**
- Removed `activateProposal()` call from approval handlers (lines ~1038-1042)
- Added `POST action=PUBLISH` endpoint for manual activation
- GET `?list=pending` now includes both `PENDING` and `APPROVED` proposals
- APPROVED proposals shown first in list, sorted by status then deadline

**UI Changes:**
- APPROVED proposals show green row highlight (`table-success`)
- Shows "Ready" badge instead of approval progress
- Shows "Publish" button (green) instead of Approve/Deny/Extend buttons
- Edit button still available (restarts coordination)

---

### 2. Edit Approved Proposals (NEW)
**Files Modified:**
- `api/mgt/tmi/coordinate.php` - Modified `handleEditProposal()` to accept APPROVED status

**Feature:**
- Users can edit proposals that are already approved
- Editing resets status to PENDING and clears all facility approvals
- Posts new Discord coordination message
- Same behavior as editing pending proposals

**Implementation:**
```php
// Now accepts both PENDING and APPROVED
if (!in_array($proposal['status'], ['PENDING', 'APPROVED'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Can only edit PENDING or APPROVED proposals']);
    return;
}
```

---

### 3. Edit Proposal - Auto-Update Raw Text
**Files Modified:**
- `assets/js/tmi-publish.js` (lines ~5012-5040)
- `api/mgt/tmi/coordinate.php` (lines ~1252-1280)

**Problem:** When editing a proposal, changing the restriction value (e.g., 4MINIT to 5MINIT) didn't update the displayed raw_text field.

**Solution:**
- **Frontend:** Added `didOpen` callback to edit dialog that listens for changes to restriction_value and restriction_unit inputs, then auto-updates the raw_text textarea using regex replacement
- **Backend:** Added logic to auto-update raw_text when restriction values change, ensuring consistency even for API edits

**Pattern Matched:** `(\d+)(MIT|MINIT)` replaced with new value + unit

---

### 4. Filter Hotline Termination Advisories from Dropdown
**File Modified:** `assets/js/tmi-publish.js` (lines ~2193-2200)

**Problem:** When selecting TERMINATION action, the dropdown showed all active hotlines including termination notices themselves (which shouldn't be terminatable again).

**Solution:** Added filter to exclude advisories where `subject.toUpperCase().includes('TERMINATION')`

```javascript
const activeHotlines = (response.data?.active || []).filter(item =>
    item.entityType === 'ADVISORY' &&
    item.entryType === 'HOTLINE' &&
    item.status === 'ACTIVE' &&
    !(item.subject && item.subject.toUpperCase().includes('TERMINATION'))
);
```

**Note:** Original advisories that were terminated should also be filtered, but this requires database tracking of termination references (not yet implemented).

---

### 5. Default Approval Deadline to T(start) - 1 Minute
**File Modified:** `assets/js/tmi-publish.js` (lines ~3491-3510)

**Problem:** Approval deadline defaulted to "now + 2 hours" regardless of TMI start time.

**Solution:** Now calculates deadline as:
1. Get `valid_from` from queue entry data
2. Set deadline to `valid_from - 1 minute`
3. Fallback to `now + 2 hours` if no valid start time or if calculated deadline is in the past

```javascript
const validFrom = entryData.valid_from || entryData.validFrom;
if (validFrom) {
    const startTime = new Date(validFrom.includes('Z') ? validFrom : validFrom + 'Z');
    if (!isNaN(startTime.getTime()) && startTime > new Date()) {
        defaultDeadline = new Date(startTime.getTime() - 60 * 1000);
    }
}
```

---

### 6. Coordination Log Enhancements (Previous Session)
**File Modified:** `load/coordination_log.php`

**Features Added:**
- Discord timestamps: `<t:UNIX:f>` (long format) and `<t:UNIX:R>` (relative)
- Action types for all coordination activities (TMI_CREATED, TMI_EDITED, TMI_CANCELLED, ADVISORY_*, REROUTE_*, PROGRAM_*, PUBLICROUTE_*, PROPOSAL_*, FACILITY_APPROVE/DENY, DCC_OVERRIDE)
- Entry IDs, proposal IDs, and tracking aids included in log messages

**Message Format Example:**
```
`[2026-01-28 14:05:32Z]` <t:1706447132:f> (<t:1706447132:R>) 📌 **TMI CREATED** MIT ID #42 | KJFK | Fac: ZNY | by Jeremy Peterson
```

---

### 7. Internal TMI Auto-Approval (Previous Session)
**File Modified:** `api/mgt/tmi/coordinate.php` (lines ~492-545)

**Feature:** TMIs where all facilities share the same responsible ARTCC are auto-approved without Discord coordination.

**Note:** Internal TMIs still auto-activate immediately (no queue step needed since no external coordination required).

---

## Completed Additional Items

### 8. Fix X Button Alignment in Proposals Table
**Files Modified:**
- `tmi-publish.php` - Adjusted column widths (ACTIONS: 120px → 160px)
- `assets/js/tmi-publish.js` - Changed buttons to use Bootstrap `btn-group` for compact layout

**Solution:**
- Increased ACTIONS column width from 120px to 160px with `min-width: 160px`
- Reduced other column widths slightly to compensate
- Converted individual buttons to Bootstrap button group for better spacing
- Added `min-width: 150px` to RESTRICTION column to prevent text truncation

---

### 9. JFK Airport Config Discord Posting (Investigated)
**Status:** Infrastructure/operational issue - not a code bug

**Findings:**
- CONFIG entries use async queue (`tmi_discord_posts` table)
- Background processor `scripts/tmi/process_discord_queue.php` must be running
- Posts go to `ntml` channel for the selected org
- Deduplication logic prevents re-posting unchanged CONFIGs

**Diagnosis Steps:**
1. Verify queue processor daemon is running: `php scripts/tmi/process_discord_queue.php`
2. Check `tmi_discord_posts` table for pending/failed JFK entries
3. Check queue processor logs for errors
4. Verify `ntml` channel ID is configured for the target org

---

## Pending Items

### 1. Terminated Advisory Tracking
**Status:** Not implemented

**Problem:** When a TERMINATION advisory is published, the original advisory should be marked as terminated/cancelled so it doesn't appear in dropdowns.

**Solution Options:**
1. Add `terminated_by_advisory_id` column to track relationship
2. Store reference ID when creating TERMINATION advisory
3. On publish, update original advisory status to CANCELLED

---

## Files Modified This Session

| File | Changes |
|------|---------|
| `api/mgt/tmi/coordinate.php` | Publish endpoint, edit APPROVED, stopped auto-activation |
| `assets/js/tmi-publish.js` | Publish button UI, edit dialog, hotline filter, deadline, button groups |
| `cron/process_tmi_proposals.php` | Stopped auto-activation, approval notification |
| `tmi-publish.php` | Adjusted table column widths for proposals |

## Testing Checklist

- [ ] Submit TMI for coordination, wait for approval
- [ ] Verify approved proposal appears in queue with green highlight and "Publish" button
- [ ] Click "Publish" and verify TMI is activated and posted to Discord
- [ ] Edit an approved proposal, verify it resets to PENDING with new Discord message
- [ ] Edit proposal: change restriction value, verify raw_text updates
- [ ] Create TERMINATION hotline: verify only ACTIVATION hotlines appear in dropdown
- [ ] Submit for coordination: verify deadline defaults to T(start)-1 minute
- [ ] Verify coordination log messages in Discord include timestamps
- [ ] Test internal TMI auto-approval (same ARTCC facilities - should still auto-activate)

---

## Reference: Proposal Status Flow

```
PENDING ──────────────────┬──────────────────────────> DENIED
    │                     │                               │
    │ (All facilities     │ (Any denial + no override)    │
    │  approve)           │                               │
    ▼                     │                               │
APPROVED ─────────────────┤                               │
    │                     │                               │
    │ (User clicks        │ (Past deadline)               │
    │  "Publish")         │                               │
    ▼                     ▼                               │
ACTIVATED ←───────────── EXPIRED ─────────────────────────┘
    │                     │
    │ (If future start)   │ (DCC can override)
    ▼                     │
SCHEDULED                 │
```

---

## Reference: Coordination Log Action Types

| Action | Description | Emoji |
|--------|-------------|-------|
| TMI_CREATED | New TMI entry created | 📌 |
| TMI_EDITED | TMI entry modified | ✏️ |
| TMI_CANCELLED | TMI entry cancelled | 🗑️ |
| ADVISORY_CREATED | New advisory created | 📢 |
| ADVISORY_EDITED | Advisory modified | ✏️ |
| ADVISORY_CANCELLED | Advisory cancelled | 🗑️ |
| REROUTE_CREATED | New reroute created | 🛣️ |
| REROUTE_EDITED | Reroute modified | ✏️ |
| REROUTE_CANCELLED | Reroute cancelled | 🗑️ |
| PROGRAM_CREATED | New program (GS/GDP) created | 🛑 |
| PROGRAM_EDITED | Program modified | ✏️ |
| PROGRAM_CANCELLED | Program cancelled | 🗑️ |
| PROPOSAL_SUBMITTED | New proposal for coordination | 📝 |
| FACILITY_APPROVE | Facility approved proposal | ✅ |
| FACILITY_DENY | Facility denied proposal | ❌ |
| DCC_OVERRIDE | DCC override action | ⚡ |
| PROPOSAL_APPROVED | All facilities approved | 🎉 |
| PROPOSAL_DENIED | Proposal denied | 🚫 |
| PROPOSAL_EXPIRED | Deadline passed | ⏰ |
| PROPOSAL_EDITED | Proposal edited, coordination restarted | ✏️ |
| AUTO_APPROVED | Internal TMI auto-approved | ✅ |
| PUBLISHED | Approved proposal manually published | 📡 |

---

## Reference: ARTCC Emoji Mappings

### US ARTCCs
| Facility | Emoji | Key Letter |
|----------|-------|------------|
| ZAB | 🇦 | A - Albuquerque |
| ZAN | 🇬 | G - anchoraGe |
| ZAU | 🇺 | U - Chicago |
| ZBW | 🇧 | B - Boston |
| ZDC | 🇩 | D - Washington DC |
| ZDV | 🇻 | V - DenVer |
| ZFW | 🇫 | F - Fort Worth |
| ZHN | 🇭 | H - Honolulu |
| ZHU | 🇼 | W - Houston |
| ZID | 🇮 | I - Indianapolis |
| ZJX | 🇯 | J - Jacksonville |
| ZKC | 🇰 | K - Kansas City |
| ZLA | 🇱 | L - Los Angeles |
| ZLC | 🇨 | C - Salt Lake City |
| ZMA | 🇲 | M - Miami |
| ZME | 🇪 | E - mEmphis |
| ZMP | 🇵 | P - minneaPolis |
| ZNY | 🇳 | N - New York |
| ZOA | 🇴 | O - Oakland |
| ZOB | 🇷 | R - cleveland |
| ZSE | 🇸 | S - Seattle |
| ZTL | 🇹 | T - aTlanta |

### Canadian FIRs
| Facility | Emoji |
|----------|-------|
| CZEG | 1️⃣ |
| CZVR | 2️⃣ |
| CZWG | 3️⃣ |
| CZYZ | 4️⃣ |
| CZQM | 5️⃣ |
| CZQX | 6️⃣ |
| CZQO | 7️⃣ |
| CZUL | 8️⃣ |
