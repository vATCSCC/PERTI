# Airport Groupings ASPM82/OPSNET45 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Update FAA airport grouping terminology from ASPM77 to ASPM82 (71→82 airports) and add OPSNET45 (45 airports) across all databases, code, and documentation.

**Architecture:** Four-phase implementation starting with database schema changes, then updating centralized constants in the codebase-globalization worktree, followed by global find/replace across all code files, and finishing with documentation updates. Integration with the existing codebase-globalization effort to maintain single sources of truth.

**Tech Stack:** Azure SQL, PostgreSQL/PostGIS, PHP 8.2, JavaScript ES6, Python 3.9, Git worktrees

**Design Document:** `docs/plans/2026-02-08-airport-groupings-design.md`

**Worktrees:**
- Main: `C:/Temp/perti-worktrees/airport-group-updates` (current)
- Globalization: `C:/Temp/perti-worktrees/codebase-globalization` (update first)

---

## Phase 1: Database Schema Updates

### Task 1: Create Migration File

**Files:**
- Create: `adl/migrations/088_airport_groupings_aspm82_opsnet45.sql`

**Step 1: Create migration file with header**

Create file `adl/migrations/088_airport_groupings_aspm82_opsnet45.sql`:

```sql
-- =====================================================
-- Airport Groupings: ASPM82 + OPSNET45
-- Migration: 088_airport_groupings_aspm82_opsnet45.sql
-- Databases: VATSIM_ADL (Azure SQL), VATSIM_GIS (PostgreSQL)
-- Purpose: Update FAA airport tier groupings to current standards
-- =====================================================
--
-- Changes:
--   1. Rename ASPM77 → ASPM82 in ref_major_airports
--   2. Remove 3 deprecated airports (ALB, CHS, RIC)
--   3. Add 14 new ASPM82 airports
--   4. Add opsnet45 column to apts table
--   5. Set airport tier flags
--
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Starting Airport Groupings migration...';
GO
```

**Step 2: Add ref_major_airports updates**

Append to migration file:

```sql
-- =====================================================
-- 1. Update ref_major_airports (VATSIM_ADL)
-- =====================================================

PRINT '1. Updating ref_major_airports region names...';

-- Rename ASPM77 → ASPM82
UPDATE dbo.ref_major_airports
SET region = 'ASPM82'
WHERE region = 'ASPM77';

PRINT '   - Renamed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows from ASPM77 to ASPM82';

-- Remove deprecated airports
DELETE FROM dbo.ref_major_airports
WHERE airport_icao IN ('KALB', 'KCHS', 'KRIC')
  AND region = 'ASPM82';

PRINT '   - Removed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' deprecated airports';

-- Add 14 new ASPM82 airports
INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
('KAPA', 'ASPM82', 0, 'Denver Centennial'),
('KASE', 'ASPM82', 0, 'Aspen'),
('KBJC', 'ASPM82', 0, 'Denver Rocky Mountain'),
('KBOI', 'ASPM82', 0, 'Boise'),
('KDAY', 'ASPM82', 0, 'Dayton'),
('KGYY', 'ASPM82', 0, 'Gary Chicago'),
('KHPN', 'ASPM82', 0, 'Westchester County'),
('KISP', 'ASPM82', 0, 'Long Island Mac Arthur'),
('KMHT', 'ASPM82', 0, 'Manchester'),
('KOXR', 'ASPM82', 0, 'Oxnard'),
('KPSP', 'ASPM82', 0, 'Palm Springs'),
('KRFD', 'ASPM82', 0, 'Greater Rockford'),
('KSWF', 'ASPM82', 0, 'Stewart'),
('KVNY', 'ASPM82', 0, 'Van Nuys');

PRINT '   - Added ' + CAST(@@ROWCOUNT AS VARCHAR) + ' new ASPM82 airports';

-- Verify final count
DECLARE @aspm82_count INT;
SELECT @aspm82_count = COUNT(*) FROM dbo.ref_major_airports WHERE region = 'ASPM82';
PRINT '   - Final ASPM82 airport count: ' + CAST(@aspm82_count AS VARCHAR) + ' (expected: 82)';

IF @aspm82_count != 82
BEGIN
    RAISERROR('ERROR: ASPM82 count is %d, expected 82', 16, 1, @aspm82_count);
END;

GO
```

**Step 3: Add apts table column updates**

Append to migration file:

```sql
-- =====================================================
-- 2. Update apts table (VATSIM_ADL)
-- =====================================================

PRINT '2. Updating apts table columns...';

-- Check if aspm77 column exists before renaming
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'aspm77')
BEGIN
    EXEC sp_rename 'dbo.apts.aspm77', 'aspm82', 'COLUMN';
    PRINT '   - Renamed apts.aspm77 → apts.aspm82';
END
ELSE
BEGIN
    PRINT '   - Column apts.aspm77 does not exist, skipping rename';
END;

-- Add opsnet45 column if it doesn't exist
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'opsnet45')
BEGIN
    ALTER TABLE dbo.apts ADD opsnet45 BIT NULL;
    PRINT '   - Added apts.opsnet45 column';
END
ELSE
BEGIN
    PRINT '   - Column apts.opsnet45 already exists';
END;

GO
```

**Step 4: Add airport flag updates**

Append to migration file:

```sql
-- =====================================================
-- 3. Set airport tier flags (VATSIM_ADL)
-- =====================================================

PRINT '3. Setting airport tier flags...';

-- Update ASPM82 flags (82 airports)
UPDATE dbo.apts
SET aspm82 = 1
WHERE ICAO_ID IN (
    'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
    'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
    'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
    'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
    'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
    'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
    'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
    'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
    'KTUS', 'KVNY'
);

PRINT '   - Set aspm82 flag for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports (expected: 82)';

-- Update OPSNET45 flags (45 airports)
UPDATE dbo.apts
SET opsnet45 = 1
WHERE ICAO_ID IN (
    'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
    'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJFK', 'KLAS',
    'KLAX', 'KLGA', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK',
    'KORD', 'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
    'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA'
);

PRINT '   - Set opsnet45 flag for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports (expected: 45)';

GO
```

**Step 5: Add PostgreSQL GIS updates**

Append to migration file:

```sql
-- =====================================================
-- 4. Update airports table (VATSIM_GIS - PostgreSQL)
-- =====================================================
-- NOTE: Run this section separately via psql for PostgreSQL database

/*
PRINT '4. Updating VATSIM_GIS airports table...';

-- Rename column
ALTER TABLE airports RENAME COLUMN aspm77 TO aspm82;

-- Add new column
ALTER TABLE airports ADD COLUMN opsnet45 BOOLEAN;

-- Update ASPM82 flags
UPDATE airports
SET aspm82 = true
WHERE icao_id IN (
    'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
    'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
    'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
    'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
    'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
    'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
    'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
    'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
    'KTUS', 'KVNY'
);

-- Update OPSNET45 flags
UPDATE airports
SET opsnet45 = true
WHERE icao_id IN (
    'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
    'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJFK', 'KLAS',
    'KLAX', 'KLGA', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK',
    'KORD', 'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
    'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA'
);
*/

PRINT 'Migration complete.';
PRINT 'NOTE: PostgreSQL GIS updates commented out - run separately via psql';
GO
```

**Step 6: Commit migration file**

```bash
git add adl/migrations/088_airport_groupings_aspm82_opsnet45.sql
git commit -m "feat(db): add ASPM82/OPSNET45 airport groupings migration

- Rename ASPM77 → ASPM82 in ref_major_airports
- Remove 3 deprecated airports (ALB, CHS, RIC)
- Add 14 new ASPM82 airports
- Add opsnet45 column to apts table
- Set tier flags for 82 ASPM82 and 45 OPSNET45 airports
- Include PostgreSQL GIS updates (commented, run separately)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 2: Update Existing Migration Comments

**Files:**
- Modify: `adl/migrations/087_atis_tiered_cleanup.sql`
- Modify: `adl/migrations/stats/006_airport_groupings.sql`
- Modify: `adl/migrations/stats/008_apts_military_columns.sql`

**Step 1: Update 087_atis_tiered_cleanup.sql**

Find and replace in `adl/migrations/087_atis_tiered_cleanup.sql`:
- Line 11: `ASPM77 airports` → `ASPM82 airports`
- Line 39: `-- ASPM77, CA_MX_LATAM, GLOBAL` → `-- ASPM82, CA_MX_LATAM, GLOBAL`
- Line 45: `-- Tier 0: ASPM77 Airports` → `-- Tier 0: ASPM82 Airports`
- Lines 47-117: Replace all `'ASPM77'` with `'ASPM82'` in region column

**Step 2: Update 006_airport_groupings.sql**

Find and replace in `adl/migrations/stats/006_airport_groupings.sql`:
- Line 3: `-- Fallback hierarchy: Core30 -> OEP35 -> ASPM77` → `-- Fallback hierarchy: Core30 -> OEP35 -> OPSNET45 -> ASPM82`
- Line 5: `-- 1 = must match Core30/OEP35/ASPM77` → `-- 1 = must match Core30/OEP35/OPSNET45/ASPM82`
- Line 7: `-- 'CORE30', 'OEP35', 'ASPM77', 'COMMERCIAL'` → `-- 'CORE30', 'OEP35', 'OPSNET45', 'ASPM82', 'COMMERCIAL'`
- Lines 10-20: Update comments referencing tier hierarchy

**Step 3: Update 008_apts_military_columns.sql**

Find and replace in `adl/migrations/stats/008_apts_military_columns.sql`:
- Update any references to ASPM77 → ASPM82
- Update hierarchy comments to include OPSNET45

**Step 4: Commit migration comment updates**

```bash
git add adl/migrations/087_atis_tiered_cleanup.sql adl/migrations/stats/006_airport_groupings.sql adl/migrations/stats/008_apts_military_columns.sql
git commit -m "docs(db): update migration comments for ASPM82/OPSNET45

- Update ASPM77 → ASPM82 references
- Add OPSNET45 to hierarchy documentation
- Update tier descriptions in comments

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 2: Centralized Constants (codebase-globalization worktree)

### Task 3: Update facility-hierarchy.js Airport Groups

**Files:**
- Modify: `C:/Temp/perti-worktrees/codebase-globalization/assets/js/facility-hierarchy.js:~370-450`

**Step 1: Switch to codebase-globalization worktree**

```bash
cd C:/Temp/perti-worktrees/codebase-globalization
```

**Step 2: Update AIRPORT_GROUPS constant**

Find the `AIRPORT_GROUPS` object (around line 370-450) and update:

```javascript
// Source: FAA OPSNET/ASPM - rarely changes
// ===========================================

const AIRPORT_GROUPS = {
    'CORE30': {
        name: 'Core 30',
        airports: [
            'KATL', 'KBOS', 'KBWI', 'KCLT', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL',
            'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCO', 'KMDW', 'KMEM', 'KMIA',
            'KMSP', 'KORD', 'KPHL', 'KPHX', 'KSAN', 'KSEA', 'KSFO', 'KSLC', 'KTPA', 'PHNL',
        ],
    },
    'OEP35': {
        name: 'OEP 35',
        airports: [
            'KATL', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN', 'KDFW', 'KDTW',
            'KEWR', 'KFLL', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCO', 'KMDW',
            'KMEM', 'KMIA', 'KMSP', 'KORD', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KSAN', 'KSEA',
            'KSFO', 'KSLC', 'KSTL', 'KTPA', 'PHNL',
        ],
    },
    'OPSNET45': {
        name: 'OPSNET 45',
        airports: [
            'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
            'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJFK', 'KLAS',
            'KLAX', 'KLGA', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK',
            'KORD', 'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
            'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA',
        ],
    },
    'ASPM82': {
        name: 'ASPM 82',
        airports: [
            'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
            'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
            'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
            'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
            'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
            'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
            'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
            'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
            'KTUS', 'KVNY',
        ],
    },
};
```

**Step 3: Commit facility-hierarchy.js changes**

```bash
git add assets/js/facility-hierarchy.js
git commit -m "feat(facility): update airport tiers to ASPM82 and add OPSNET45

- Rename ASPM77 → ASPM82 (71→82 airports)
- Add 14 new airports: APA, ASE, BJC, BOI, DAY, GYY, HPN, ISP, MHT, OXR, PSP, RFD, SWF, VNY
- Remove 3 deprecated: ALB, CHS, RIC
- Add OPSNET45 grouping (45 airports)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 4: Update perti.js ATCT List

**Files:**
- Modify: `C:/Temp/perti-worktrees/codebase-globalization/assets/js/lib/perti.js:357-367`

**Step 1: Update FACILITY_LISTS.ATCT array**

Find `FACILITY_LISTS.ATCT` (line 357-367) and replace with:

```javascript
// ASPM 82 airport towers (per FAA ASPM facility list)
ATCT: Object.freeze([
    'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
    'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
    'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
    'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
    'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
    'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
    'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
    'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
    'KTUS', 'KVNY',
]),
```

**Step 2: Update comment references**

Find line ~111 and update:

```javascript
// Extended NTML Qualifiers - matching OPSNET/ASPM terminology
```

**Step 3: Commit perti.js changes**

```bash
git add assets/js/lib/perti.js
git commit -m "feat(facility): update ATCT list to ASPM82 (82 airports)

- Update FACILITY_LISTS.ATCT from ASPM77 to ASPM82
- Add 14 new tower codes
- Remove 3 deprecated tower codes

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 5: Update PERTI Migration Tracker

**Files:**
- Modify: `C:/Temp/perti-worktrees/codebase-globalization/PERTI_MIGRATION_TRACKER.md`

**Step 1: Add migration entry**

Append to the appropriate section in `PERTI_MIGRATION_TRACKER.md`:

```markdown
### Airport Tier Updates (v1.6.0)

| Date | Change | Files Affected |
|------|--------|----------------|
| 2026-02-08 | ASPM77 → ASPM82 (82 airports) | `facility-hierarchy.js`, `lib/perti.js` |
| 2026-02-08 | Add OPSNET45 (45 airports) | `facility-hierarchy.js` |

**Details:**
- Renamed `AIRPORT_GROUPS.ASPM77` → `AIRPORT_GROUPS.ASPM82`
- Added `AIRPORT_GROUPS.OPSNET45` (new FAA grouping)
- Updated `FACILITY_LISTS.ATCT` to match ASPM82 list
- Removed: ALB, CHS, RIC
- Added: APA, ASE, BJC, BOI, DAY, GYY, HPN, ISP, MHT, OXR, PSP, RFD, SWF, VNY

**Hierarchy:** Core30 → OEP35 → OPSNET45 → ASPM82
```

**Step 2: Commit migration tracker update**

```bash
git add PERTI_MIGRATION_TRACKER.md
git commit -m "docs(migration): document ASPM82/OPSNET45 airport tier updates

Track changes to centralized airport groupings in facility-hierarchy.js
and lib/perti.js for PERTI namespace v1.6.0.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 6: Push and Merge codebase-globalization

**Step 1: Push codebase-globalization branch**

```bash
cd C:/Temp/perti-worktrees/codebase-globalization
git push origin feature/codebase-globalization
```

**Step 2: Notify user to merge**

Output message:
```
✅ codebase-globalization updates complete and pushed.

Next steps:
1. Create PR for feature/codebase-globalization → main
2. Get approval and merge
3. Return to airport-group-updates worktree
4. Merge latest main into airport-group-updates
5. Continue with Phase 3
```

**Step 3: Wait for merge confirmation**

PAUSE HERE until user confirms codebase-globalization is merged to main.

---

## Phase 3: Codebase Updates (airport-group-updates worktree)

### Task 7: Merge Latest Main

**Files:**
- Working directory: `C:/Temp/perti-worktrees/airport-group-updates`

**Step 1: Switch to airport-group-updates worktree**

```bash
cd C:/Temp/perti-worktrees/airport-group-updates
```

**Step 2: Fetch and merge main**

```bash
git fetch origin
git merge origin/main -m "Merge main with codebase-globalization updates"
```

Expected: Clean merge with facility-hierarchy.js and lib/perti.js updated.

**Step 3: Verify merge**

```bash
git log --oneline -5
```

Expected: See codebase-globalization commits in history.

---

### Task 8: Global Find/Replace - JavaScript Files

**Files:**
- Modify: All `.js` files in `assets/js/`

**Step 1: Find all ASPM77 references**

```bash
grep -r "ASPM77" assets/js/ --include="*.js" | wc -l
```

Expected: See count of references (likely 20-30).

**Step 2: Replace ASPM77 → ASPM82 (case-sensitive)**

```bash
find assets/js/ -type f -name "*.js" -exec sed -i 's/ASPM77/ASPM82/g' {} +
```

**Step 3: Replace aspm77 → aspm82 (lowercase)**

```bash
find assets/js/ -type f -name "*.js" -exec sed -i 's/aspm77/aspm82/g' {} +
```

**Step 4: Replace 'ASPM 77' → 'ASPM 82' (with space)**

```bash
find assets/js/ -type f -name "*.js" -exec sed -i "s/'ASPM 77'/'ASPM 82'/g" {} +
```

**Step 5: Verify replacements**

```bash
grep -r "ASPM77\|aspm77" assets/js/ --include="*.js" | wc -l
```

Expected: 0 results (all replaced).

```bash
grep -r "ASPM82\|aspm82" assets/js/ --include="*.js" | wc -l
```

Expected: Same count as before (20-30+).

**Step 6: Commit JavaScript replacements**

```bash
git add assets/js/
git commit -m "refactor(js): rename ASPM77 → ASPM82 globally

Global find/replace across all JavaScript files:
- ASPM77 → ASPM82
- aspm77 → aspm82
- 'ASPM 77' → 'ASPM 82'

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 9: Global Find/Replace - PHP Files

**Files:**
- Modify: All `.php` files

**Step 1: Replace ASPM77 → ASPM82**

```bash
find . -type f -name "*.php" -exec sed -i 's/ASPM77/ASPM82/g' {} +
```

**Step 2: Replace aspm77 → aspm82**

```bash
find . -type f -name "*.php" -exec sed -i 's/aspm77/aspm82/g' {} +
```

**Step 3: Replace 'ASPM 77' → 'ASPM 82'**

```bash
find . -type f -name "*.php" -exec sed -i "s/'ASPM 77'/'ASPM 82'/g" {} +
```

**Step 4: Verify replacements**

```bash
grep -r "ASPM77\|aspm77" --include="*.php" | wc -l
```

Expected: 0 results.

**Step 5: Commit PHP replacements**

```bash
git add .
git commit -m "refactor(php): rename ASPM77 → ASPM82 globally

Global find/replace across all PHP files:
- ASPM77 → ASPM82
- aspm77 → aspm82

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 10: Global Find/Replace - SQL Files

**Files:**
- Modify: All `.sql` files in `adl/migrations/`

**Step 1: Replace in SQL files**

```bash
find adl/migrations/ -type f -name "*.sql" -exec sed -i 's/ASPM77/ASPM82/g' {} +
find adl/migrations/ -type f -name "*.sql" -exec sed -i 's/aspm77/aspm82/g' {} +
```

**Step 2: Verify replacements**

```bash
grep -r "ASPM77\|aspm77" adl/migrations/ --include="*.sql" | wc -l
```

Expected: 0 results (except possibly in migration file names).

**Step 3: Commit SQL replacements**

```bash
git add adl/migrations/
git commit -m "refactor(sql): rename ASPM77 → ASPM82 in migration files

Update all SQL migration comments and data to use ASPM82.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 11: Update apts.csv Data File

**Files:**
- Modify: `assets/data/apts.csv`

**Step 1: Read current CSV to understand structure**

```bash
head -1 assets/data/apts.csv
```

Expected: See column headers including `aspm77` and `oep35`.

**Step 2: Rename column header aspm77 → aspm82**

Use sed to replace header:

```bash
sed -i '1s/aspm77/aspm82/' assets/data/apts.csv
```

**Step 3: Add opsnet45 column header**

Add `,opsnet45` after aspm82 column in header row.

Manual edit required - open file and add column after aspm82.

**Step 4: Update airport flags in data rows**

This requires manual review or scripting to set correct flags based on airport lists.

For now, add placeholder zeros for opsnet45 column:

```bash
sed -i '2,$s/$/,0/' assets/data/apts.csv
```

Then manually update the 45 OPSNET45 airports to have `1` in that column.

**Step 5: Verify CSV structure**

```bash
head -5 assets/data/apts.csv
```

Expected: Header shows `aspm82,opsnet45`, data rows have values.

**Step 6: Commit apts.csv changes**

```bash
git add assets/data/apts.csv
git commit -m "feat(data): update apts.csv for ASPM82/OPSNET45

- Rename column aspm77 → aspm82
- Add opsnet45 column
- Update airport tier flags

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 12: Update Python Import Scripts

**Files:**
- Modify: `nasr_navdata_updater.py`

**Step 1: Replace ASPM77 references in Python**

```bash
find . -type f -name "*.py" -exec sed -i 's/aspm77/aspm82/g' {} +
find . -type f -name "*.py" -exec sed -i 's/ASPM77/ASPM82/g' {} +
```

**Step 2: Verify Python files**

```bash
grep -r "aspm77\|ASPM77" --include="*.py" | wc -l
```

Expected: 0 results.

**Step 3: Commit Python changes**

```bash
git add .
git commit -m "refactor(python): rename ASPM77 → ASPM82 in import scripts

Update nasr_navdata_updater.py and other Python scripts to use
ASPM82 column naming.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 4: Documentation Updates

### Task 13: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

**Step 1: Update table schema documentation**

Find line 18 (or search for `airport_id PK, arpt_id, icao_id...`):

Replace:
```
`airport_id PK, arpt_id, icao_id, arpt_name, lat, lon, elev, resp_artcc_id, computer_id, artcc_name, twr_type_code, dcc_region, aspm77, oep35, core30, tower, approach, departure, approach_id, geom (geometry)`
```

With:
```
`airport_id PK, arpt_id, icao_id, arpt_name, lat, lon, elev, resp_artcc_id, computer_id, artcc_name, twr_type_code, dcc_region, aspm82, opsnet45, oep35, core30, tower, approach, departure, approach_id, geom (geometry)`
```

**Step 2: Update glossary if present**

Add OPSNET45 definition if glossary exists:

```markdown
| **OPSNET45** | Operational Network 45 airports - FAA operational performance metrics |
```

Update ASPM77 → ASPM82:

```markdown
| **ASPM82** | Airport System Performance Metrics - 82 FAA-monitored airports |
```

**Step 3: Commit CLAUDE.md changes**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md for ASPM82/OPSNET45

- Update GIS airports table schema
- Add OPSNET45 to glossary
- Update ASPM77 → ASPM82 references

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 14: Update Wiki Documentation

**Files:**
- Modify: `wiki/Acronyms.md`
- Modify: `wiki/Demand-Analysis-Walkthrough.md`
- Modify: `wiki/Algorithm-Zone-Detection.md`

**Step 1: Update Acronyms.md**

Add OPSNET45 entry:

```markdown
**OPSNET45** - Operational Network 45 airports, FAA's operational performance metric set including major US commercial airports.
```

Update ASPM entry:

```markdown
**ASPM82** - Airport System Performance Metrics, FAA's 82-airport performance monitoring program (formerly ASPM77).
```

**Step 2: Global replace in wiki files**

```bash
find wiki/ -type f -name "*.md" -exec sed -i 's/ASPM77/ASPM82/g' {} +
find wiki/ -type f -name "*.md" -exec sed -i 's/ASPM 77/ASPM 82/g' {} +
```

**Step 3: Verify wiki updates**

```bash
grep -r "ASPM77" wiki/ --include="*.md" | wc -l
```

Expected: 0 results.

**Step 4: Commit wiki changes**

```bash
git add wiki/
git commit -m "docs(wiki): update airport tier terminology to ASPM82/OPSNET45

- Add OPSNET45 to Acronyms.md
- Update ASPM77 → ASPM82 across all wiki pages
- Update tier references in walkthroughs

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 15: Update ADL Documentation

**Files:**
- Modify: `adl/OOOI_Quick_Start.md`
- Modify: `adl/OOOI_Zone_Detection_Transition_Summary.md`

**Step 1: Replace in ADL docs**

```bash
find adl/ -type f -name "*.md" -exec sed -i 's/ASPM77/ASPM82/g' {} +
```

**Step 2: Update OOOI_Zone_Detection_Transition_Summary.md tier table**

Find the tier table and update:

```markdown
| ASPM82 (US) | 82 | KATL, KJFK, KLAX, KORD, KSFO |
```

**Step 3: Commit ADL documentation changes**

```bash
git add adl/
git commit -m "docs(adl): update OOOI docs for ASPM82

- Update ASPM77 → ASPM82 in Quick Start guide
- Update tier table in Zone Detection summary
- Update airport coverage counts

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 16: Update API Documentation

**Files:**
- Modify: `api-docs/openapi.yaml`

**Step 1: Replace in OpenAPI spec**

```bash
sed -i 's/aspm77/aspm82/g' api-docs/openapi.yaml
sed -i 's/ASPM77/ASPM82/g' api-docs/openapi.yaml
```

**Step 2: Add opsnet45 to schema definitions**

Find airport schema and add opsnet45 property:

```yaml
opsnet45:
  type: boolean
  description: Airport is in OPSNET45 operational network grouping
```

**Step 3: Commit OpenAPI changes**

```bash
git add api-docs/openapi.yaml
git commit -m "docs(api): update OpenAPI spec for ASPM82/OPSNET45

- Rename aspm77 → aspm82 in schemas
- Add opsnet45 boolean property
- Update tier descriptions

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 17: Update assistant_codebase_index

**Files:**
- Modify: `assistant_codebase_index_v18.md`

**Step 1: Global replace in index**

```bash
sed -i 's/ASPM77/ASPM82/g' assistant_codebase_index_v18.md
sed -i 's/aspm77/aspm82/g' assistant_codebase_index_v18.md
```

**Step 2: Commit index changes**

```bash
git add assistant_codebase_index_v18.md
git commit -m "docs(index): update codebase index for ASPM82/OPSNET45

Update assistant codebase index to reflect new airport tier naming.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Final Verification & Testing

### Task 18: Run Database Migration

**Step 1: Connect to VATSIM_ADL via Azure**

Use credentials from `.claude/credentials.md`:

```bash
# Via Azure Cloud Shell or local sqlcmd
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U <username> -P <password> -i adl/migrations/088_airport_groupings_aspm82_opsnet45.sql
```

**Step 2: Verify migration output**

Expected output:
```
Starting Airport Groupings migration...
1. Updating ref_major_airports region names...
   - Renamed 71 rows from ASPM77 to ASPM82
   - Removed 3 deprecated airports
   - Added 14 new ASPM82 airports
   - Final ASPM82 airport count: 82 (expected: 82)
2. Updating apts table columns...
   - Renamed apts.aspm77 → apts.aspm82
   - Added apts.opsnet45 column
3. Setting airport tier flags...
   - Set aspm82 flag for 82 airports (expected: 82)
   - Set opsnet45 flag for 45 airports (expected: 45)
Migration complete.
```

**Step 3: Run PostgreSQL migration for VATSIM_GIS**

```bash
# Extract PostgreSQL section from migration file
# Run via psql
psql -h vatcscc-gis.postgres.database.azure.com -d VATSIM_GIS -U <username> -c "ALTER TABLE airports RENAME COLUMN aspm77 TO aspm82;"
psql -h vatcscc-gis.postgres.database.azure.com -d VATSIM_GIS -U <username> -c "ALTER TABLE airports ADD COLUMN opsnet45 BOOLEAN;"
# ... run update statements
```

**Step 4: Verify database state**

Query to verify:

```sql
-- Check ref_major_airports
SELECT region, COUNT(*) as cnt FROM dbo.ref_major_airports GROUP BY region;
-- Expected: ASPM82 = 82

-- Check apts table structure
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'apts' AND COLUMN_NAME IN ('aspm82', 'opsnet45');
-- Expected: Both columns present
```

---

### Task 19: Frontend Testing

**Step 1: Start local development server**

```bash
# If using PHP built-in server
php -S localhost:8000
```

**Step 2: Test demand charts**

Navigate to: `http://localhost:8000/demand.php`

Verify:
- [ ] Airport tier dropdown shows "ASPM 82" option
- [ ] Airport tier dropdown shows "OPSNET 45" option
- [ ] Filtering by ASPM 82 returns 82 airports
- [ ] Filtering by OPSNET 45 returns 45 airports
- [ ] No JavaScript console errors

**Step 3: Test GDT**

Navigate to: `http://localhost:8000/gdt.php`

Verify:
- [ ] Airport classification shows correct tier badges
- [ ] No JavaScript console errors
- [ ] GDP simulation works

**Step 4: Test route visualization**

Navigate to: `http://localhost:8000/route.php`

Verify:
- [ ] Map loads correctly
- [ ] Airport markers render
- [ ] No JavaScript console errors

**Step 5: Check browser console for errors**

Open DevTools (F12) on each page, verify no errors related to:
- `ASPM77 is not defined`
- `aspm77` undefined
- Tier filtering failures

---

### Task 20: API Endpoint Testing

**Step 1: Test /api/demand/airports.php**

```bash
curl http://localhost:8000/api/demand/airports.php?tier=ASPM82
```

Expected: JSON response with 82 airports.

```bash
curl http://localhost:8000/api/demand/airports.php?tier=OPSNET45
```

Expected: JSON response with 45 airports.

**Step 2: Test /api/adl/stats.php**

```bash
curl http://localhost:8000/api/adl/stats.php
```

Expected: No errors, statistics include OPSNET45 grouping.

**Step 3: Verify no 500 errors in logs**

```bash
tail -f /home/LogFiles/php_errors.log
```

Expected: No errors related to aspm77 or tier filtering.

---

### Task 21: Final Commit and Summary

**Step 1: Verify all changes committed**

```bash
git status
```

Expected: "working tree clean" or only untracked files.

**Step 2: Review commit history**

```bash
git log --oneline -20
```

Expected: See all phase commits in order.

**Step 3: Create final summary commit (if needed)**

If any loose changes exist, commit them:

```bash
git add .
git commit -m "chore: final cleanup for ASPM82/OPSNET45 implementation

All database, code, and documentation updates complete.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

**Step 4: Push to remote**

```bash
git push origin feature/airport-group-updates
```

---

## Deployment Checklist

After all tasks complete:

### Pre-Deployment
- [ ] All commits pushed to feature/airport-group-updates
- [ ] Database migrations tested locally
- [ ] Frontend pages tested (demand, GDT, route, splits)
- [ ] API endpoints tested
- [ ] No JavaScript console errors
- [ ] No PHP errors in logs

### Deployment Steps
1. [ ] Create PR: feature/airport-group-updates → main
2. [ ] Get code review approval
3. [ ] Merge to main
4. [ ] Run database migration on production VATSIM_ADL
5. [ ] Run database migration on production VATSIM_GIS
6. [ ] Deploy code to Azure App Service (auto-triggered via GitHub Actions)
7. [ ] Restart PHP-FPM and daemons

### Post-Deployment Verification
- [ ] Check `/api/demand/airports.php?tier=ASPM82` returns 82 airports
- [ ] Check `/api/demand/airports.php?tier=OPSNET45` returns 45 airports
- [ ] Verify demand charts load
- [ ] Verify GDT works
- [ ] Check Azure App Service logs for errors
- [ ] Monitor for 48 hours

### Rollback (if needed)
1. Revert database migrations (see design doc Part 5)
2. Revert code commits
3. Redeploy previous version

---

## Success Metrics

| Metric | Target | Verification |
|--------|--------|--------------|
| ASPM82 airport count | 82 | `SELECT COUNT(*) FROM ref_major_airports WHERE region = 'ASPM82'` |
| OPSNET45 airport count | 45 | `SELECT COUNT(*) FROM airports WHERE opsnet45 = 1` |
| Code references to ASPM77 | 0 | `git grep -i aspm77` returns nothing |
| JavaScript console errors | 0 | Browser DevTools on major pages |
| Failed API requests | 0 | Check `/home/LogFiles/*.log` |
| Documentation accuracy | 100% | Manual review |

---

## Estimated Time

| Phase | Tasks | Time |
|-------|-------|------|
| Phase 1: Database | 1-2 | 30 min |
| Phase 2: Globalization | 3-6 | 45 min |
| Phase 3: Codebase | 7-12 | 2 hours |
| Phase 4: Documentation | 13-17 | 1 hour |
| Testing & Deployment | 18-21 | 1.5 hours |
| **Total** | **21 tasks** | **5-6 hours** |

---

**Plan Status:** ✅ Ready for Execution
**Next Step:** Use @superpowers:executing-plans or @superpowers:subagent-driven-development
