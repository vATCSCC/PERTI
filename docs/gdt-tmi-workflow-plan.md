# GDT → TMI Publishing Workflow Separation Plan

## Overview

Separate GDT demand analysis from TMI Publishing for GS/GDP operations, following the existing reroute coordination pattern.

**Key Principle:** GDT handles modeling/analysis; TMI Publishing handles coordination, advisory creation, and publication.

---

## 1. Workflow Summary

### GDP Flow (Default: Coordination Required)
```
GDT: Model GDP → Submit to TMI Publishing
TMI: Configure deadline (default Now+45min) → Submit for Coordination
       → Publishes PROPOSED GDP Advisory with "USER UPDATES MUST BE RECEIVED BY: DD/HHMMZ"
       → Posts Discord coordination message with deadline
Facilities: Approve/deny via emoji reactions
On Approval: Regenerate flight list → Activate → Publish ACTUAL GDP advisory
On Changes: Modify params → Re-model → Regenerate flight list → Update proposal → Publish
On Denial/Expiry: No Actual GDP created
```

### GS Flow (Default: Immediate Activation)
```
GDT: Model GS → Submit to TMI Publishing
TMI: Default "Immediate" mode → Publish Now (skip coordination)
Optional: Select "Standard" coordination → Same as GDP flow
```

### Extension/Modification Flow
```
Load active GS/GDP params → Modify in GDT → Re-model
Submit to TMI → Creates new proposal linked to original
On activation: Original → SUPERSEDED, New → ACTIVE
```

### Cancellation Flow
```
Select active program → Cancel (skips coordination)
Post cancellation advisory immediately with EDCT purge instruction:
  - Default: "DISREGARD EDCTS FOR DEST [airport]"
  - With delay: "DISREGARD EDCTS FOR DEST [airport] AFTER DD/HHMMZ"
  - If AFP active: "FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP"
Purge EDCTs in tmi_flight_list for this program only
```

---

## 2. Database Schema Changes

### Existing: Global DCC Advisory Sequence

> **Already exists:** `tmi_advisory_sequences` table and `sp_GetNextAdvisoryNumber` procedure are in the core schema (001_tmi_core_schema_azure_sql.sql). No separate migration needed.

### Migration 027: Add program coordination columns to tmi_programs

**File:** `database/migrations/tmi/027_add_program_coordination.sql`

```sql
ALTER TABLE dbo.tmi_programs ADD
    proposal_id              INT NULL,
    proposal_status          NVARCHAR(20) NULL,       -- PENDING_COORD, APPROVED, DENIED, ACTIVATED
    coordination_deadline_utc DATETIME2(0) NULL,      -- "USER UPDATES MUST BE RECEIVED BY"
    coordination_facilities_json NVARCHAR(MAX) NULL,  -- Facilities for approval
    flight_list_generated_at DATETIME2(0) NULL,
    proposed_advisory_num    NVARCHAR(16) NULL,       -- ADVZY for PROPOSED advisory
    actual_advisory_num      NVARCHAR(16) NULL,       -- ADVZY for ACTUAL advisory
    cancel_advisory_num      NVARCHAR(16) NULL,       -- ADVZY for cancellation
    cancellation_reason      NVARCHAR(64) NULL,
    cancellation_edct_action NVARCHAR(64) NULL,       -- DISREGARD, DISREGARD_AFTER, AFP_ACTIVE
    cancellation_edct_time   DATETIME2(0) NULL,       -- For DISREGARD_AFTER option
    cancellation_notes       NVARCHAR(MAX) NULL;

CREATE INDEX IX_programs_proposal ON dbo.tmi_programs(proposal_id) WHERE proposal_id IS NOT NULL;
```

### Migration 028: Add program_id to tmi_proposals

**File:** `database/migrations/tmi/028_add_proposal_program_link.sql`

```sql
ALTER TABLE dbo.tmi_proposals ADD
    program_id               INT NULL,
    program_snapshot_json    NVARCHAR(MAX) NULL;

ALTER TABLE dbo.tmi_proposals ADD CONSTRAINT FK_proposals_program
    FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id);

CREATE INDEX IX_proposals_program ON dbo.tmi_proposals(program_id) WHERE program_id IS NOT NULL;
```

### Migration 029: Create flight list table (multi-TMI support)

**File:** `database/migrations/tmi/029_create_tmi_flight_list.sql`

```sql
-- Flight list with GUFI tracking - flights can be controlled by multiple TMIs
CREATE TABLE dbo.tmi_flight_list (
    list_id           INT IDENTITY(1,1) PRIMARY KEY,
    program_id        INT NOT NULL,
    flight_gufi       NVARCHAR(64) NOT NULL,        -- Global Unique Flight Identifier
    callsign          NVARCHAR(10) NOT NULL,
    dep_airport       NVARCHAR(4) NOT NULL,
    arr_airport       NVARCHAR(4) NOT NULL,
    original_etd_utc  DATETIME2(0) NULL,            -- Original scheduled departure
    edct_utc          DATETIME2(0) NULL,            -- Assigned EDCT from this TMI
    cta_utc           DATETIME2(0) NULL,            -- Controlled Time of Arrival
    slot_id           INT NULL,                      -- RBS slot assignment
    exemption_code    NVARCHAR(16) NULL,            -- If exempt, why
    compliance_status NVARCHAR(20) DEFAULT 'PENDING', -- PENDING, COMPLIANT, NON_COMPLIANT, EXEMPT
    added_at          DATETIME2(0) DEFAULT SYSUTCDATETIME(),
    updated_at        DATETIME2(0) DEFAULT SYSUTCDATETIME(),
    CONSTRAINT FK_flight_list_program FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id)
);

-- Unique constraint: one entry per flight per program (allows same flight in multiple TMIs)
CREATE UNIQUE INDEX UX_flight_list_program_gufi ON dbo.tmi_flight_list(program_id, flight_gufi);

-- Index for finding all TMIs controlling a specific flight
CREATE INDEX IX_flight_list_gufi ON dbo.tmi_flight_list(flight_gufi);

-- Index for flight list queries by program
CREATE INDEX IX_flight_list_program ON dbo.tmi_flight_list(program_id, compliance_status);
```

### Migration 030: Create program coordination log

**File:** `database/migrations/tmi/030_create_program_coord_log.sql`

```sql
CREATE TABLE dbo.tmi_program_coordination_log (
    log_id            INT IDENTITY(1,1) PRIMARY KEY,
    program_id        INT NOT NULL,
    proposal_id       INT NULL,
    action_type       NVARCHAR(50) NOT NULL,
    action_details    NVARCHAR(MAX) NULL,
    performed_by      NVARCHAR(64) NULL,
    performed_by_cid  NVARCHAR(20) NULL,
    performed_at      DATETIME2(0) DEFAULT SYSUTCDATETIME(),
    CONSTRAINT FK_prog_coord_log FOREIGN KEY (program_id) REFERENCES dbo.tmi_programs(program_id)
);

CREATE INDEX IX_prog_coord_log_program ON dbo.tmi_program_coordination_log(program_id, performed_at DESC);
```

---

## 3. API Endpoints

### New Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/gdt/programs/submit_proposal.php` | POST | Submit GS/GDP for coordination |
| `/api/gdt/programs/publish.php` | POST | Publish approved program |
| `/api/gdt/programs/cancel.php` | POST | Cancel with advisory (skip coordination) |
| `/api/gdt/programs/flight_list.php` | GET | Get dynamic flight list |

### `/api/gdt/programs/submit_proposal.php`

**Request:**
```json
{
  "program_id": 123,
  "coordination_mode": "STANDARD",
  "deadline_minutes": 45,
  "facilities": ["ZDC", "ZNY"],
  "advisory_text": "...",
  "user_cid": "1234567",
  "user_name": "John Doe"
}
```

**Logic:**
1. Get next advisory number via `sp_GetNextAdvisoryNumber`
2. Generate PROPOSED advisory with "USER UPDATES MUST BE RECEIVED BY" line
3. If `coordination_mode` = 'IMMEDIATE': activate directly, skip to ACTUAL
4. Otherwise: create `tmi_proposals` record with `entry_type` = 'GS' or 'GDP'
5. Link proposal to program via `program_id`
6. Post PROPOSED advisory to NTML channel
7. Post Discord coordination message with deadline

### `/api/gdt/programs/publish.php`

**Request:**
```json
{
  "proposal_id": 456,
  "program_id": 123,
  "regenerate_flight_list": true
}
```

**Logic:**
1. Verify proposal status = APPROVED
2. If changes made: verify re-modeling was done
3. Regenerate flight list into `tmi_flight_list`
4. Get next advisory number for ACTUAL advisory
5. Call `sp_TMI_ActivateProgram`
6. Post ACTUAL advisory to NTML channel
7. Create `tmi_advisories` record

### `/api/gdt/programs/cancel.php`

**Request:**
```json
{
  "program_id": 123,
  "cancel_reason": "WEATHER_IMPROVEMENT",
  "cancel_notes": "Thunderstorms moved east",
  "edct_action": "DISREGARD",
  "edct_action_time": null
}
```

**EDCT Action Options:**
- `DISREGARD` (default) → "DISREGARD EDCTS FOR DEST [airport]"
- `DISREGARD_AFTER` → "DISREGARD EDCTS FOR DEST [airport] AFTER DD/HHMMZ" (requires `edct_action_time`)
- `AFP_ACTIVE` → "FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP"

**Logic:**
1. Get next advisory number via `sp_GetNextAdvisoryNumber`
2. Generate cancellation advisory with appropriate EDCT line
3. Post immediately (no coordination)
4. Update program status to CANCELLED
5. Delete flights from `tmi_flight_list` for this program_id only
6. Other TMIs controlling same flights remain unaffected

### Modify `/api/mgt/tmi/coordinate.php`

Add support for GS/GDP entry types in `handleSubmitForCoordination()`:

```php
// Add to existing entry_type handling
if (in_array($entryType, ['GS', 'GDP'])) {
    $programId = $entry['program_id'] ?? null;
    if ($programId) {
        // Update tmi_programs.proposal_id
        // Store snapshot in program_snapshot_json
    }
}
```

---

## 4. GDT Page Changes

### Remove from gdt.php / gdt.js
- "Send Actual" button
- "Run Proposed" button
- Direct Discord posting functions

### Add to gdt.php / gdt.js
- "Submit to TMI Publishing" button (replaces Send Actual)
- Program status badge showing coordination state
- Link to TMI Publishing Coordination tab

### New JavaScript Function (gdt.js)

```javascript
async function submitToTmiPublishing() {
    if (!GS_SIMULATION_READY) {
        Swal.fire('Error', 'Run Simulate first.', 'error');
        return;
    }

    const programData = {
        program_id: GS_CURRENT_PROGRAM_ID,
        program_type: document.getElementById('gdp_program_type')?.value || 'GS',
        ctl_element: document.getElementById('gs_ctl_element')?.value,
        start_utc: getValue('gs_start'),
        end_utc: getValue('gs_end'),
        program_rate: parseInt(getValue('gdp_program_rate')) || null,
        scope_json: JSON.stringify(collectScopeData()),
        exemptions_json: JSON.stringify(collectExemptionData()),
        model_summary: collectModelSummary(),
        advisory_preview: document.getElementById('gs_advisory_preview')?.textContent
    };

    sessionStorage.setItem('gdt_program_transfer', JSON.stringify(programData));
    window.location.href = 'tmi-publish.php#gdp-tab';
}
```

---

## 5. TMI Publishing Changes

### Add GS/GDP Tab to tmi-publish.php

```html
<li class="nav-item">
    <a class="nav-link" id="gdp-tab" data-toggle="tab" href="#gdpPanel">
        <i class="fas fa-clock mr-1"></i> GS/GDP
    </a>
</li>
```

### GS/GDP Panel Structure

**Left Column: Program Details**
- Imported program summary from GDT
- Model stats (flights, avg delay, max delay)
- Coordination mode toggle: Standard (45min) / Expedited (15min) / Immediate
- Deadline override input
- Facilities checklist (auto-populated from scope ARTCCs)

**Right Column: Advisory & Actions**
- Advisory preview (pre-formatted)
- Flight list preview table (refreshable)
- Action buttons: Submit for Coordination / Publish Now

### New JavaScript Module: assets/js/tmi-gdp.js

```javascript
const GdpPublisher = {
    programData: null,

    init() {
        this.checkForImport();
        this.bindEvents();
    },

    checkForImport() {
        const transfer = sessionStorage.getItem('gdt_program_transfer');
        if (transfer) {
            this.programData = JSON.parse(transfer);
            sessionStorage.removeItem('gdt_program_transfer');
            this.displayProgram();
            this.setDefaultCoordinationMode();
        }
    },

    setDefaultCoordinationMode() {
        // GS defaults to Immediate, GDP defaults to Standard
        const mode = this.programData.program_type === 'GS' ? 'IMMEDIATE' : 'STANDARD';
        this.setCoordinationMode(mode);
    },

    async submitForCoordination() {
        const payload = {
            program_id: this.programData.program_id,
            coordination_mode: this.getCoordinationMode(),
            deadline_minutes: this.getDeadlineMinutes(),
            facilities: this.getSelectedFacilities(),
            advisory_text: document.getElementById('gdp_advisory_preview').textContent,
            user_cid: ProfileManager.getCid(),
            user_name: ProfileManager.getName()
        };

        const r = await fetch('/api/gdt/programs/submit_proposal.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        // Handle response...
    }
};
```

### Modify Coordination Tab

- Add filter for entry_type including 'GS', 'GDP'
- Show program-specific columns (Rate, Delay Stats)
- Add "Modify" action → reopens in GDT for re-modeling
- Add "Publish" action for approved proposals

---

## 6. Dynamic Flight List

### Background Refresh Script

**File:** `scripts/refresh_program_flight_lists.php`

Run via cron every 2-5 minutes:
```php
// For each ACTIVE GS/GDP program:
// 1. Query current ADL flights matching scope
// 2. Compare with tmi_flight_list records
// 3. Add new flights (newly connected pilots)
// 4. Update compliance status
// 5. Log changes
```

### Flight List API

**File:** `/api/gdt/programs/flight_list.php`

```php
// GET ?program_id=123&include_new=true
// Returns:
// - flights: Current assigned flights with EDCT
// - new_flights: Flights added since last refresh
// - compliance: Per-flight compliance status
// - stats: Summary (controlled, exempt, avg_delay)
```

---

## 7. Advisory Formats (Per FAA Advisories and General Messages v1.3)

### PROPOSED GDP Advisory (Published to NTML/Discord)

```
CDM PROPOSED GROUND DELAY PROGRAM ADVZY [global_seq]

CTL ELEMENT.................. KATL/ATL
REASON FOR PROGRAM........... WEATHER/THUNDERSTORMS
ANTICIPATED PROGRAM START.... DD/HHMMZ
ANTICIPATED END TIME......... DD/HHMMZ
AVERAGE DELAY................ [XXX] MINUTES
MAXIMUM DELAY................ [XXX] MINUTES
DELAY ASSIGNMENT MODE........ UDP
PROGRAM RATE................. [XX] PER HOUR
[Scope lines...]

USER UPDATES MUST BE RECEIVED BY: DD/HHMMZ

JO/DCC
```

### ACTUAL GDP Advisory (After Coordination Approval)

```
CDM GROUND DELAY PROGRAM ADVZY [global_seq]

CTL ELEMENT.................. KATL/ATL
REASON FOR PROGRAM........... WEATHER/THUNDERSTORMS
PROGRAM START................ DD/HHMMZ
END TIME..................... DD/HHMMZ
AVERAGE DELAY................ [XXX] MINUTES
MAXIMUM DELAY................ [XXX] MINUTES
DELAY ASSIGNMENT MODE........ UDP
PROGRAM RATE................. [XX] PER HOUR
[Scope lines...]

JO/DCC
```

### PROPOSED GS Advisory

```
CDM PROPOSED GROUND STOP ADVZY [global_seq]

CTL ELEMENT.................. KATL/ATL
REASON FOR GROUND STOP....... WEATHER/THUNDERSTORMS
ANTICIPATED GROUND STOP...... DD/HHMMZ
ANTICIPATED END TIME......... DD/HHMMZ
[Scope lines...]

USER UPDATES MUST BE RECEIVED BY: DD/HHMMZ

JO/DCC
```

### ACTUAL GS Advisory

```
CDM GROUND STOP ADVZY [global_seq]

CTL ELEMENT.................. KATL/ATL
REASON FOR GROUND STOP....... WEATHER/THUNDERSTORMS
GROUND STOP.................. DD/HHMMZ
END TIME..................... DD/HHMMZ
[Scope lines...]

JO/DCC
```

### Cancellation Advisory

```
DCC [GROUND STOP|GROUND DELAY PROGRAM] CANCELLATION ADVZY [global_seq]

CTL ELEMENT.................. KATL/ATL
CANCEL TIME.................. DD/HHMMZ

[EDCT Purge Line - one of:]
DISREGARD EDCTS FOR DEST KATL
-or-
DISREGARD EDCTS FOR DEST KATL AFTER DD/HHMMZ
-or-
FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP

JO/DCC
```

### Discord Coordination Message (Separate from Advisory)

```
**GDP PROPOSAL** | KATL | Submitted by John Doe

**Advisory:** ADVZY [global_seq]
**Program:** GDP-UDP KATL
**Period:** 30/1400Z - 30/2200Z
**Rate:** 32/hr
**Scope:** ZTL, ZJX, ZDC, ZNY arrivals

**Model Summary:**
- Controlled Flights: 145
- Avg Delay: 47 min | Max Delay: 123 min

**Coordination Deadline:** 30/1345Z

React to approve: 🇹 ZTL | 🇯 ZJX | 🇩 ZDC | 🇳 ZNY
```

---

## 8. Implementation Sequence

### Phase 1: Database ✅ COMPLETE

> Note: Advisory sequence already exists in core schema (001_tmi_core_schema_azure_sql.sql)

1. ~~Create migration 027 (program coordination columns)~~ ✅
2. ~~Create migration 028 (proposal-program link)~~ ✅
3. ~~Create migration 029 (tmi_flight_list)~~ ✅
4. ~~Create migration 030 (coordination log)~~ ✅
5. Run migrations on VATSIM_TMI

### Phase 2: Core APIs
1. Create `/api/gdt/programs/submit_proposal.php`
2. Create `/api/gdt/programs/publish.php`
3. Create `/api/gdt/programs/cancel.php`
4. Modify `/api/mgt/tmi/coordinate.php` for GS/GDP support

### Phase 3: GDT Changes
1. Remove Send Actual / Run Proposed buttons
2. Add "Submit to TMI Publishing" button
3. Add `submitToTmiPublishing()` function to gdt.js
4. Add session storage transfer mechanism

### Phase 4: TMI Publishing
1. Add GS/GDP tab to tmi-publish.php
2. Create assets/js/tmi-gdp.js
3. Implement program import from GDT
4. Implement coordination submission
5. Implement direct publish (Immediate mode)
6. Update Coordination tab for GS/GDP entries

### Phase 5: Cancellation & Flight List
1. Add cancel modal to TMI Publishing
2. Implement cancellation API
3. Create flight list refresh script
4. Implement `/api/gdt/programs/flight_list.php`

### Phase 6: Testing
1. GDP coordination flow end-to-end
2. GS immediate activation flow
3. Extension/modification flow
4. Cancellation with EDCT options
5. Dynamic flight list updates

---

## 9. Critical Files

| File | Purpose |
|------|---------|
| `database/migrations/tmi/027_add_program_coordination.sql` | Program coordination columns |
| `database/migrations/tmi/028_add_proposal_program_link.sql` | Proposal-program link |
| `database/migrations/tmi/029_create_tmi_flight_list.sql` | Flight list with GUFI/multi-TMI support |
| `database/migrations/tmi/030_create_program_coord_log.sql` | Coordination audit log |
| `api/gdt/programs/submit_proposal.php` | New - coordination submission |
| `api/gdt/programs/publish.php` | New - publish approved program |
| `api/gdt/programs/cancel.php` | New - cancellation flow |
| `api/mgt/tmi/coordinate.php` | Modify - add GS/GDP support |
| `gdt.php` | Modify - add Submit to TMI button |
| `assets/js/gdt.js` | Modify - add transfer function |
| `tmi-publish.php` | Modify - add GS/GDP tab |
| `assets/js/tmi-gdp.js` | New - GS/GDP tab functionality |
| `assets/js/tmi-publish.js` | Modify - integrate GS/GDP |

---

## 10. Verification Steps

1. **GDP Coordination Test:**
   - Model GDP in GDT → Submit to TMI → Verify proposal in DB
   - Check PROPOSED advisory published with "USER UPDATES MUST BE RECEIVED BY"
   - Check Discord coordination message posted
   - Simulate facility approvals → Verify status update
   - Publish → Verify ACTUAL advisory posted, program activated

2. **GS Immediate Test:**
   - Model GS in GDT → Submit to TMI
   - Verify "Immediate" mode default
   - Publish Now → Verify instant activation, ACTUAL advisory posted (no PROPOSED)

3. **Cancellation Test:**
   - Cancel active program → Verify cancellation advisory posted
   - Test DISREGARD (default) EDCT line
   - Test DISREGARD_AFTER with time
   - Test AFP_ACTIVE option
   - Verify flights removed from tmi_flight_list for this program only

4. **Dynamic Flight List Test:**
   - Activate GDP → Note initial flight count in tmi_flight_list
   - Simulate pilot connecting → Verify flight added with GUFI
   - Check compliance tracking updates
   - Verify same flight can appear in multiple TMI programs
