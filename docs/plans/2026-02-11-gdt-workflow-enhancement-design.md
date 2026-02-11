# GDT Workflow Enhancement Design

**Date**: 2026-02-11
**Status**: Approved
**Scope**: Workflow & program lifecycle overhaul for the Ground Delay Tool

---

## Problem Statement

The GDT currently supports creating and activating Ground Stop and GDP programs, but the workflow has significant gaps:

1. **No active program visibility** — users land on a blank form with no awareness of what's already running
2. **State confusion** — all buttons (Preview, Simulate, Submit, Send Actual) are always visible regardless of program state, leading to out-of-sequence actions
3. **No GS-to-GDP transition** — the most common real-world evolution (Ground Stop lifts, GDP takes over) requires manually cancelling the GS and creating a new GDP from scratch
4. **No extend or revise** — active programs can't be modified mid-stream without cancellation
5. **No multi-program awareness** — no way to see or coordinate across simultaneous programs (e.g., GS at KJFK + GDP at KEWR)

## Design Overview

Six components delivered across six phases:

| Phase | Component | Value |
|-------|-----------|-------|
| 1 | Active Programs Dashboard | Immediate situational awareness on page load |
| 2 | Workflow Stepper & State Management | Eliminates button confusion, guides users step-by-step |
| 3 | Extend & Revise | Modify active programs without cancel/recreate |
| 4 | GS-to-GDP Transition | Smooth handoff matching FAA advisory chain pattern |
| 5 | Re-model (What-If) | Dry-run simulation without touching active program |
| 6 | Multi-Program Timeline | Gantt chart + conflict detection across programs |

---

## 1. Active Programs Dashboard

A collapsible panel at the top of `gdt.php`, below the info bar but above the two-column layout. Auto-loads on page init and refreshes every 60 seconds.

### Layout

Horizontal card strip. Each active program gets a compact card showing:

- **Program type badge** — GS (red) / GDP (amber) / AFP (blue)
- **CTL element and ARTCC** — e.g., "KJFK / ZNY"
- **Time window** — e.g., "1400Z - 1800Z" with a progress bar showing elapsed percentage
- **Key metrics** — controlled flights, exempt, avg delay
- **Status badge** — ACTIVE, MODELING, PROPOSED, TRANSITIONED
- **Quick action buttons** — Extend, Transition (GS only), Cancel, Edit/Re-model

### Behavior

- Clicking a card loads that program into the GS/GDP form below, populating all fields and setting the stepper to the appropriate state
- "Edit/Re-model" puts the form into revision state where changes generate a new advisory number
- When no programs are active, the panel shows a subtle "No active programs" message and stays collapsed by default
- Cards sorted by urgency: GS first, then GDP, then AFP
- Total controlled flights across all programs shown in a summary row

### Data Source

New endpoint: `GET /api/gdt/programs/active.php`

Returns all programs where `is_active = 1` OR `status IN ('PROPOSED', 'MODELING')`, ordered by `start_utc`. Includes summary metrics (flight counts, avg delay) computed server-side.

---

## 2. Workflow Stepper & State Management

### Problem

The current UI has Preview/Simulate/Submit/Send Actual buttons always visible regardless of state. Users press buttons out of sequence or don't know what step comes next.

### Stepper UI

A horizontal step bar (checkout-flow style) with 4 steps above the action buttons:

```
[ 1. Configure ] ──── [ 2. Preview ] ──── [ 3. Model ] ──── [ 4. Active ]
```

Each step lights up as the user progresses. Active step is highlighted with the PERTI accent color. Completed steps show a checkmark.

### State Machine

```
NEW → PROPOSED → MODELING → ACTIVE → [COMPLETED | CANCELLED | TRANSITIONED]
                    │                       ↑
                    └─── ACTIVE (Send Actual)│
```

Central state variable: `GS_WORKFLOW_STATE` in `gdt.js`.

### Button Visibility Rules

| State | Visible Buttons | Disabled Buttons |
|-------|----------------|-----------------|
| Configure | Preview | Simulate, Submit, Send Actual |
| Preview | Simulate, Send Actual, Back to Configure | Submit |
| Modeling | Submit to TMI, Re-model, Back to Preview | — |
| Active | Extend, Transition, Cancel, Re-model | Preview, Simulate |

### Navigation

Users can go back without losing data:
- Modeling → Preview: keeps form populated, clears simulation results
- Preview → Configure: form stays as-is (no-op)
- Active programs loaded from dashboard enter at the Active step

### Implementation

- `GS_WORKFLOW_STATE` variable drives all button visibility via a single `updateWorkflowUI()` function
- Each API response calls `setWorkflowState()` to transition
- Stepper is a lightweight CSS component (colored circles + connecting lines), no new dependencies

---

## 3. Extend & Revise

### Extend

Push the end time of an active program later. Common for GS extensions ("probability of extension: HIGH").

**UI**: Modal with:
- Current end time (read-only)
- New end time picker
- Updated probability of extension
- Updated comments

**Behavior**:
- Generates a new advisory with the extended period
- For GDP: calls existing `sp_TMI_ExtendProgram` stored procedure to create new slots for the extended window
- For GS: updates `end_utc` only (no slots)

**API**: Existing `POST /api/gdt/programs/extend.php` — already built, needs UI modal wiring.

### Revise

Change program parameters while it stays active. Common for GDP rate adjustments mid-program.

**UI**: Opens the form in edit mode with current values populated. User changes rate, scope, exemptions, delay cap, etc.

**Behavior**:
- Updates program record in place
- Generates a revision advisory: "CDM GROUND DELAY PROGRAM REVISION"
- Re-runs RBS for remaining unassigned slots with new parameters
- Increments `revision_count` on the program record
- Advisory comments auto-include what changed (e.g., "RATE REVISED FROM 30 TO 36")

**API**: Enhanced `POST /api/gdt/programs/simulate.php` with `revise: true` flag, or new `POST /api/gdt/programs/revise.php`.

---

## 4. GS-to-GDP Transition

### Real-World Pattern

Based on FAA advisory analysis (EWR 04/20/2025 ADVZY 019→020→022, DEN 07/22/2025 ADVZY 126→130→133):

1. **Active GS** — already issued (ADVZY N)
2. **Proposed GDP** — issued while GS is still active (ADVZY N+x), with conference time for coordination. Standard "CDM PROPOSED GROUND DELAY PROGRAM" format. Comments reference the GS exit strategy.
3. **GDP Activation** — GS gets status TRANSITIONED, GDP goes ACTIVE (ADVZY N+y). Standard "CDM GROUND DELAY PROGRAM" format with "GROUND STOP CANCELLED." in comments and CUMULATIVE PROGRAM PERIOD spanning from original GS start.

### Database Model

New `tmi_programs` row with `parent_program_id` FK pointing to the original GS. The parent GS gets `status = 'TRANSITIONED'` (distinct from CANCELLED). Both linked via `advisory_chain_id`. GDP record has `cumulative_start_utc` set to the chain's earliest `start_utc`.

### UI Flow

1. User clicks **Transition** on active GS card in dashboard
2. Transition modal opens with GDP form pre-populated from parent GS (ctl_element, scope, exemptions)
3. User fills GDP-specific fields: program rate (stepped, e.g., `57/39/48/64`), delay cap, end time
4. CUMULATIVE PROGRAM PERIOD auto-calculated from GS start through GDP end
5. Optional: schedule coordination conference time
6. Two-phase submit:
   - **Propose** — issues "CDM PROPOSED GROUND DELAY PROGRAM" advisory, GS stays active
   - **Activate** — transitions GS to TRANSITIONED, activates GDP

### Advisory Format

Standard GDP advisory format. GS-specific additions:
- "GROUND STOP CANCELLED." prepended to comments field
- `CUMULATIVE PROGRAM PERIOD: DD/HHMMZ - DD/HHMMZ` line showing full chain span

### API

`POST /api/gdt/programs/transition.php`

Request:
```json
{
  "parent_program_id": 123,
  "program_rate": "57/39/48/64",
  "end_utc": "2026-02-11T20:00Z",
  "delay_cap_min": 300,
  "scope_json": { ... },
  "exemptions_json": { ... },
  "comments": "ARR 4R, DEP 4R. MODIFIED LOW POP UP.",
  "phase": "propose"
}
```

Phase `"propose"` creates the GDP in PROPOSED status. Phase `"activate"` transitions the parent GS and activates the GDP.

---

## 5. Re-model (What-If)

Run simulation again without changing the active program. For "what-if" analysis when considering a revise or rate change.

### Behavior

- Puts the stepper back to MODELING state temporarily
- Uses a shadow copy of program parameters — edits in the form don't touch the active record
- Visual indicator: stepper shows "What-If Mode" badge
- If confirmed: promotes to a Revise (Section 3)
- If discarded: restores original active program state, stepper returns to Active

### Implementation

- Add `dry_run: true` flag to `simulate.php` — runs RBS and returns results but doesn't persist slot assignments or update program record
- Frontend caches the active program state before entering what-if mode
- Discard button restores cached state

---

## 6. Multi-Program Timeline

### Coordination Timeline

Below the dashboard cards, an expandable Gantt-style horizontal bar chart:

- **X axis**: UTC time (scrollable, centered on current time)
- **Y axis**: programs stacked vertically
- **Bars**: program duration, color-coded by type (GS red, GDP amber, AFP blue)
- **Connected bars**: GS→GDP transitions shown as adjacent bars with an arrow connector
- **Current time**: vertical red line at UTC now
- **Hover**: shows program details tooltip

Uses existing Chart.js dependency with horizontal bar chart and custom rendering.

### Conflict Detection

When two programs share overlapping scope facilities (e.g., GDP at KJFK and GDP at KLGA both including ZNY departures), show a "shared scope" badge on both cards linking them. Computed client-side by comparing `scope_json` across active programs.

### Program Switching

- Clicking a card or timeline bar loads that program
- Previously-viewed program's flight table state is cached in memory for instant switch-back
- "New Program" button clears form and resets stepper to Configure

### Implementation

Purely frontend — no new database tables or API endpoints. Consumes existing `programs/list.php` filtered to active + recent programs.

---

## Data Model Changes

Minimal schema additions to existing `tmi_programs` table in VATSIM_TMI:

```sql
ALTER TABLE dbo.tmi_programs ADD
    parent_program_id    INT NULL,                  -- FK to parent GS when transitioning to GDP
    advisory_chain_id    INT NULL,                  -- Groups related programs (GS + GDP chain)
    cumulative_start_utc DATETIME2 NULL,            -- Earliest start across chain
    revision_count       INT NOT NULL DEFAULT 0,    -- Incremented on each revise
    transition_type      VARCHAR(20) NULL;          -- 'GS_TO_GDP', 'GDP_REVISION', NULL
```

### Status Values

Adding one new status to the existing set:

| Status | Meaning |
|--------|---------|
| PROPOSED | Program created, not yet simulated |
| MODELING | Simulation run, awaiting activation |
| ACTIVE | Program is live, controlling flights |
| COMPLETED | Program ended naturally |
| CANCELLED | Program was explicitly cancelled |
| **TRANSITIONED** | **GS that was converted to GDP (fulfilled its purpose)** |

### Advisory Chain Logic

- Standalone program: `advisory_chain_id = program_id` (self-referencing)
- GDP from GS transition: inherits parent's `advisory_chain_id`
- `cumulative_start_utc` = chain's earliest `start_utc`
- Dashboard groups programs by `advisory_chain_id` for visual chain display

### No Changes To

- `tmi_slots` — already supports GDP slot generation
- `tmi_flight_control` — already tracks per-flight assignments
- `tmi_advisories` — already stores advisory text with program_id FK

---

## Implementation Sequence

### Phase 1: Schema + Active Programs Dashboard

**Files**:
- `database/migrations/tmi/xxx_gdt_workflow_columns.sql` — migration
- `api/gdt/programs/active.php` — new endpoint
- `gdt.php` — dashboard panel HTML
- `assets/js/gdt.js` — dashboard load/refresh/click logic
- `assets/css/theme.css` — dashboard card styles

**Deliverable**: On page load, users see all active programs with metrics and can click to load.

### Phase 2: Workflow Stepper & State Management

**Files**:
- `gdt.php` — stepper HTML
- `assets/js/gdt.js` — `GS_WORKFLOW_STATE` state machine, `updateWorkflowUI()`, button rewiring
- `assets/css/theme.css` — stepper styles

**Deliverable**: Clear visual progression, buttons only appear when valid.

### Phase 3: Extend & Revise

**Files**:
- `gdt.php` — extend modal, revise mode UI
- `assets/js/gdt.js` — extend/revise handlers
- `api/gdt/programs/revise.php` — new endpoint (or enhance simulate.php)
- `assets/js/gdt.js` — advisory builder revision variant

**Deliverable**: Active programs can be extended or revised with proper advisory generation.

### Phase 4: GS-to-GDP Transition

**Files**:
- `gdt.php` — transition modal HTML
- `assets/js/gdt.js` — transition workflow, form pre-population, two-phase submit
- `api/gdt/programs/transition.php` — enhanced with chain logic
- `assets/js/gdt.js` — advisory builder cumulative period support

**Deliverable**: Full GS→GDP transition matching FAA advisory chain pattern.

### Phase 5: Re-model (What-If)

**Files**:
- `api/gdt/programs/simulate.php` — add `dry_run` flag
- `assets/js/gdt.js` — shadow state management, what-if mode badge

**Deliverable**: Safe what-if analysis without touching active programs.

### Phase 6: Multi-Program Timeline

**Files**:
- `gdt.php` — timeline container HTML
- `assets/js/gdt.js` — Chart.js Gantt rendering, conflict detection, program switching cache

**Deliverable**: Visual timeline of all programs with chain visualization and conflict awareness.
