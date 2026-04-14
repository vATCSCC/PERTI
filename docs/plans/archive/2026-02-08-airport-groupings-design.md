# Airport Grouping Updates: ASPM82 + OPSNET45

**Date:** 2026-02-08
**Status:** Approved
**Author:** Claude Sonnet 4.5 (AI-assisted design)
**Branch:** `feature/airport-group-updates`
**Related:** Integrates with `feature/codebase-globalization`

## Executive Summary

Update FAA airport grouping terminology and lists throughout the PERTI codebase and databases to reflect current FAA standards:
- **ASPM77 → ASPM82**: Rename and expand from 71 to 82 airports
- **OPSNET45**: Add new 45-airport operational network grouping
- **OEP35**: Keep unchanged (backward compatibility)
- **Core30**: Keep unchanged

This is part of the broader codebase globalization effort to centralize reference data and eliminate duplication.

---

## Part 1: Scope & Changes

### Current State Analysis

The codebase currently has **71 airports** in the "ASPM77" grouping (historically labeled ASPM77 but count was off by 6). Comparing against the FAA's current ASPM82 list:

**Airports being REMOVED (3):**
- KALB (Albany)
- KCHS (Charleston)
- KRIC (Richmond)

**Airports being ADDED (14):**
- KAPA (Denver Centennial)
- KASE (Aspen)
- KBJC (Denver Rocky Mountain)
- KBOI (Boise)
- KDAY (Dayton)
- KGYY (Gary Chicago)
- KHPN (Westchester County)
- KISP (Long Island Mac Arthur)
- KMHT (Manchester)
- KOXR (Oxnard)
- KPSP (Palm Springs)
- KRFD (Greater Rockford)
- KSWF (Stewart)
- KVNY (Van Nuys)

**Net change:** 71 → 82 airports (+11)

**New grouping being ADDED:**
- **OPSNET45** (45 airports, brand new grouping for operational network metrics)

**Hierarchy Update:**
- **Old:** Core30 → OEP35 → ASPM77
- **New:** Core30 → OEP35 → OPSNET45 → ASPM82

### Complete Airport Lists

#### ASPM82 (82 airports)
```
ABQ, ANC, APA, ASE, ATL, AUS, BDL, BHM, BJC, BNA, BOI, BOS, BUF, BUR, BWI,
CLE, CLT, CMH, CVG, DAL, DAY, DCA, DEN, DFW, DTW, EWR, FLL, GYY, HNL, HOU,
HPN, IAD, IAH, IND, ISP, JAX, JFK, LAS, LAX, LGA, LGB, MCI, MCO, MDW, MEM,
MHT, MIA, MKE, MSP, MSY, OAK, OGG, OMA, ONT, ORD, OXR, PBI, PDX, PHL, PHX,
PIT, PSP, PVD, RDU, RFD, RSW, SAN, SAT, SDF, SEA, SFO, SJC, SJU, SLC, SMF,
SNA, STL, SWF, TEB, TPA, TUS, VNY
```
*Plus non-CONUS: ANC (PANC), HNL (PHNL), OGG (PHOG), SJU (TJSJ)*

#### OPSNET45 (45 airports)
```
ABQ, ATL, BNA, BOS, BWI, CLE, CLT, CVG, DCA, DEN, DFW, DTW, EWR, FLL, HOU,
IAD, IAH, IND, JFK, LAS, LAX, LGA, MCI, MCO, MDW, MEM, MIA, MSP, MSY, OAK,
ORD, PBI, PDX, PHL, PHX, PIT, RDU, SAN, SEA, SFO, SJC, SLC, STL, TEB, TPA
```

---

## Part 2: Database Schema Changes

### 1. VATSIM_ADL (Azure SQL)

**ref_major_airports table:**
```sql
-- Update region name
UPDATE dbo.ref_major_airports
SET region = 'ASPM82'
WHERE region = 'ASPM77';

-- Remove deprecated airports
DELETE FROM dbo.ref_major_airports
WHERE airport_icao IN ('KALB', 'KCHS', 'KRIC') AND region = 'ASPM82';

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
```

**NOTE:** OPSNET45 does NOT get entries in `ref_major_airports` - that table is specifically for ATIS retention tiers. OPSNET45 is used in airport classification and statistics grouping only.

**apts table (if columns exist):**
```sql
-- Rename column
EXEC sp_rename 'dbo.apts.aspm77', 'aspm82', 'COLUMN';

-- Add new column
ALTER TABLE dbo.apts ADD opsnet45 BIT NULL;

-- Set flags based on airport lists
UPDATE dbo.apts SET aspm82 = 1 WHERE ICAO_ID IN ('KABQ', 'PANC', ...);
UPDATE dbo.apts SET opsnet45 = 1 WHERE ICAO_ID IN ('KABQ', 'KATL', ...);
```

### 2. VATSIM_GIS (PostgreSQL)

**airports table:**
```sql
-- Rename column
ALTER TABLE airports RENAME COLUMN aspm77 TO aspm82;

-- Add new column
ALTER TABLE airports ADD COLUMN opsnet45 BOOLEAN;

-- Update flags
UPDATE airports SET aspm82 = true WHERE icao_id IN ('KABQ', 'PANC', ...);
UPDATE airports SET opsnet45 = true WHERE icao_id IN ('KABQ', 'KATL', ...);
```

### 3. Migration Files to Update

**New Migration: `088_airport_groupings_aspm82_opsnet45.sql`**
- Complete schema updates for all databases
- Data updates for airport flags
- Comment updates

**Existing Migrations (comments only, don't re-run):**
- `adl/migrations/087_atis_tiered_cleanup.sql`: Update "ASPM77" → "ASPM82" in comments and data
- `adl/migrations/stats/006_airport_groupings.sql`: Add OPSNET45 to `matched_by` VARCHAR values, update fallback hierarchy comments
- `adl/migrations/stats/008_apts_military_columns.sql`: Update grouping logic comments
- `adl/migrations/navdata/011_ourairports_global_import.sql`: Update column names
- `adl/migrations/oooi/003_seed_airport_zones.sql`: Update OOOI zone documentation

---

## Part 3: Code File Updates

### Centralized Constants (codebase-globalization worktree)

**1. `assets/js/facility-hierarchy.js`**

Update `AIRPORT_GROUPS` object:
```javascript
const AIRPORT_GROUPS = {
    'CORE30': {
        name: 'Core 30',
        airports: [
            'KATL', 'KBOS', 'KBWI', 'KCLT', 'KDCA', 'KDEN', 'KDFW', 'KDTW',
            'KEWR', 'KFLL', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
            'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KORD', 'KPHL', 'KPHX',
            'KSAN', 'KSEA', 'KSFO', 'KSLC', 'KTPA', 'PHNL',
        ],
    },
    'OEP35': {
        name: 'OEP 35',
        airports: [
            'KATL', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
            'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KIAD', 'KIAH', 'KJFK', 'KLAS',
            'KLAX', 'KLGA', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KORD',
            'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KSAN', 'KSEA', 'KSFO', 'KSLC',
            'KSTL', 'KTPA', 'PHNL',
        ],
    },
    'OPSNET45': {  // NEW
        name: 'OPSNET 45',
        airports: [
            'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG',
            'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD',
            'KIAH', 'KIND', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCI', 'KMCO',
            'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK', 'KORD', 'KPBI',
            'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
            'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA',
        ],
    },
    'ASPM82': {  // RENAMED from ASPM77
        name: 'ASPM 82',
        airports: [
            'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM',
            'KBJC', 'KBNA', 'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE',
            'KCLT', 'KCMH', 'KCVG', 'KDAL', 'KDAY', 'KDCA', 'KDEN', 'KDFW',
            'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU', 'KHPN', 'KIAD',
            'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
            'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE',
            'KMSP', 'KMSY', 'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR',
            'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KPSP', 'KPVD', 'KRDU',
            'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA', 'KSFO', 'KSJC',
            'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
            'KTUS', 'KVNY',
        ],
    },
};
```

**2. `assets/js/lib/perti.js`**

Update `FACILITY_LISTS.ATCT` array (lines 357-367) to match ASPM82 list.

**3. `PERTI_MIGRATION_TRACKER.md`**

Add entry documenting the airport tier updates.

### JavaScript Files (airport-group-updates worktree)

**Global find/replace across all JS files:**
- `ASPM77` → `ASPM82` (case-sensitive)
- `aspm77` → `aspm82` (case-sensitive)
- `'ASPM 77'` → `'ASPM 82'`
- `ASPM_77` → `ASPM_82`

**Files affected (18 files):**
1. `assets/js/splits.js`
2. `assets/js/route-maplibre.js`
3. `assets/js/nod.js`
4. `assets/js/gdt.js`
5. `assets/js/demand.js`
6. `assets/js/plan.js`
7. `assets/js/initiative_timeline.js`
8. `assets/js/tmi-publish.js`
9. `assets/js/tmi_compliance.js`
10. `assets/js/tmi-gdp.js`
11. `assets/js/playbook-cdr-search.js`
12. `assets/js/jatoc.js`
13. `assets/js/sua.js`
14. `assets/js/facility-hierarchy.js` (also update AIRPORT_GROUPS)
15. `assets/js/lib/colors.js`
16. `assets/js/config/constants.js`
17. `assets/js/config/filter-colors.js`
18. `assets/js/config/rate-colors.js`

**Add OPSNET45 support where needed:**
- Update tier filtering dropdowns to include OPSNET45 option
- Add OPSNET45 to hierarchy checks

### PHP Files (10+ files)

**Global find/replace:**
- `aspm77` → `aspm82`
- `ASPM77` → `ASPM82`
- `'ASPM 77'` → `'ASPM 82'`

**Files affected:**
1. `api/adl/AdlQueryHelper.php`
2. `api/adl/stats.php`
3. `api/demand/airports.php`
4. `api/splits/tracons.php`
5. `status.php`
6. `route.php`
7. `gdt.php`
8. `demand.php`
9. `advisory-builder.php`
10. `scripts/vatsim_adl_daemon.php`

### Python Files (1 file)

**`nasr_navdata_updater.py`:**
- Update column name mapping: `aspm77` → `aspm82`
- Add `opsnet45` column to import

### Data Files (1 file)

**`assets/data/apts.csv`:**
- Rename column header: `aspm77` → `aspm82`
- Add column header: `opsnet45`
- Update flag values for all airports based on lists

### Documentation (9+ files)

**Files to update:**
1. `CLAUDE.md`: Update schema documentation (line 18: airports table columns)
2. `wiki/Acronyms.md`: Add OPSNET45, update ASPM77→ASPM82
3. `wiki/Demand-Analysis-Walkthrough.md`
4. `wiki/Algorithm-Zone-Detection.md`
5. `wiki/Algorithm-Route-Parsing.md`
6. `adl/OOOI_Quick_Start.md`
7. `adl/OOOI_Zone_Detection_Transition_Summary.md`
8. `assistant_codebase_index_v18.md`
9. `api-docs/openapi.yaml`: Update schema definitions

---

## Part 4: Implementation Sequence

### Phase 1: Database Schema (Do First)

1. **Create migration `adl/migrations/088_airport_groupings_aspm82_opsnet45.sql`:**
   - Rename columns in GIS `airports` table
   - Update `ref_major_airports` region values from ASPM77 to ASPM82
   - Delete 3 deprecated airports (ALB, CHS, RIC)
   - Insert 14 new ASPM82 airports
   - Add `opsnet45` column to relevant tables
   - Update airport flags based on lists

2. **Update existing migrations (comments only, don't re-run):**
   - `087_atis_tiered_cleanup.sql`: Update comments and INSERT data
   - `006_airport_groupings.sql`: Add OPSNET45 to hierarchy
   - `008_apts_military_columns.sql`: Update comments
   - `011_ourairports_global_import.sql`: Update column names
   - `003_seed_airport_zones.sql`: Update documentation

3. **Run migration on all databases:**
   ```bash
   # VATSIM_ADL
   sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -i 088_airport_groupings_aspm82_opsnet45.sql

   # VATSIM_GIS (PostgreSQL)
   psql -h vatcscc-gis.postgres.database.azure.com -d VATSIM_GIS -f 088_airport_groupings_aspm82_opsnet45.sql
   ```

### Phase 2: Centralized Constants (codebase-globalization worktree)

1. **Switch to codebase-globalization worktree:**
   ```bash
   cd C:/Temp/perti-worktrees/codebase-globalization
   ```

2. **Update `assets/js/facility-hierarchy.js`:**
   - Rename `AIRPORT_GROUPS.ASPM77` → `AIRPORT_GROUPS.ASPM82`
   - Update airport list to 82 airports
   - Add `AIRPORT_GROUPS.OPSNET45` with 45 airports

3. **Update `assets/js/lib/perti.js`:**
   - Update `FACILITY_LISTS.ATCT` array with ASPM82 list

4. **Update `PERTI_MIGRATION_TRACKER.md`:**
   - Document airport tier updates

5. **Commit and push:**
   ```bash
   git add assets/js/facility-hierarchy.js assets/js/lib/perti.js PERTI_MIGRATION_TRACKER.md
   git commit -m "feat(facility): update airport tiers to ASPM82 and add OPSNET45"
   git push origin feature/codebase-globalization
   ```

6. **Merge codebase-globalization to main** (or get it approved and merged)

### Phase 3: Codebase Updates (airport-group-updates worktree)

1. **Switch to airport-group-updates worktree:**
   ```bash
   cd C:/Temp/perti-worktrees/airport-group-updates
   ```

2. **Merge latest main (with codebase-globalization changes):**
   ```bash
   git fetch origin
   git merge origin/main
   ```

3. **Global find/replace (use VS Code or sed):**
   ```bash
   # Case-sensitive replacements
   find . -type f \( -name "*.js" -o -name "*.php" -o -name "*.py" -o -name "*.md" -o -name "*.sql" \) \
     -exec sed -i 's/ASPM77/ASPM82/g' {} +

   find . -type f \( -name "*.js" -o -name "*.php" -o -name "*.py" -o -name "*.md" -o -name "*.sql" \) \
     -exec sed -i 's/aspm77/aspm82/g' {} +

   find . -type f \( -name "*.js" -o -name "*.php" -o -name "*.py" -o -name "*.md" -o -name "*.sql" \) \
     -exec sed -i "s/'ASPM 77'/'ASPM 82'/g" {} +
   ```

4. **Update `assets/data/apts.csv`:**
   - Rename column header
   - Add opsnet45 column
   - Update airport flags

5. **Update documentation files manually:**
   - `CLAUDE.md`
   - `wiki/*.md`
   - `adl/*.md`
   - `api-docs/openapi.yaml`

6. **Add OPSNET45 support where needed:**
   - Dropdowns in demand.js, gdt.js, etc.
   - Hierarchy checks in statistics queries

### Phase 4: Testing

See Part 5 below for complete testing checklist.

---

## Part 5: Testing & Validation Checklist

### Database Verification

- [ ] **ref_major_airports table:**
  - [ ] Query shows 82 rows with region = 'ASPM82'
  - [ ] Zero rows with region = 'ASPM77'
  - [ ] ALB, CHS, RIC not present in ASPM82 list
  - [ ] All 14 new airports present (APA, ASE, BJC, BOI, DAY, GYY, HPN, ISP, MHT, OXR, PSP, RFD, SWF, VNY)

- [ ] **GIS airports table:**
  - [ ] Column `aspm77` does not exist
  - [ ] Column `aspm82` exists with correct type
  - [ ] Column `opsnet45` exists with correct type
  - [ ] Flags set correctly for all airports

- [ ] **ADL apts table:**
  - [ ] Column renamed successfully
  - [ ] No NULL constraint violations
  - [ ] Airport flags match lists

- [ ] **Statistics views:**
  - [ ] `006_airport_groupings.sql` view returns OPSNET45 in matched_by
  - [ ] Hierarchy fallback works: Core30 → OEP35 → OPSNET45 → ASPM82

### Frontend Functionality

- [ ] **Demand charts (`demand.php`):**
  - [ ] Airport tier dropdown shows ASPM82, OPSNET45 options
  - [ ] Filtering by ASPM82 returns correct 82 airports
  - [ ] Filtering by OPSNET45 returns correct 45 airports
  - [ ] Charts render without errors

- [ ] **GDT (`gdt.php`):**
  - [ ] Airport classification displays correct tier
  - [ ] ASPM82 airports show "ASPM 82" badge
  - [ ] OPSNET45 airports show "OPSNET 45" badge
  - [ ] No JavaScript console errors

- [ ] **Route visualization (`route.php`, `route-maplibre.js`):**
  - [ ] Tier-based airport styling works
  - [ ] Airport markers colored correctly by tier
  - [ ] Map loads without errors

- [ ] **Splits config (`splits.php`):**
  - [ ] Airport tier dropdowns populated
  - [ ] Selection persists correctly
  - [ ] No errors on save

- [ ] **NOD (`nod.js`):**
  - [ ] Airport filtering includes OPSNET45 option
  - [ ] Track visualization works
  - [ ] Demand layer renders correctly

- [ ] **Advisory builder (`advisory-builder.php`):**
  - [ ] Tier-based airport scoping works
  - [ ] OPSNET45 scope option available
  - [ ] Advisory preview correct

### API Endpoints

- [ ] **`/api/demand/airports.php`:**
  - [ ] Returns correct tier classifications
  - [ ] OPSNET45 airports tagged correctly
  - [ ] Response time acceptable (<500ms)

- [ ] **`/api/adl/stats.php`:**
  - [ ] Grouping logic includes OPSNET45
  - [ ] Statistics aggregate correctly by tier
  - [ ] No SQL errors in logs

- [ ] **`/api/splits/tracons.php`:**
  - [ ] Tier filtering uses ASPM82
  - [ ] Response includes OPSNET45 flag
  - [ ] No breaking changes

### Data Integrity

- [ ] **`apts.csv`:**
  - [ ] File loads without parse errors
  - [ ] Column count matches expected (verify aspm82, opsnet45 columns)
  - [ ] All 82 ASPM82 airports flagged
  - [ ] All 45 OPSNET45 airports flagged

- [ ] **ATIS daemon (`scripts/vatsim_adl_daemon.php`):**
  - [ ] Tier 0 retention applies to all 82 ASPM82 airports
  - [ ] No retention errors in logs
  - [ ] Daemon restarts successfully

- [ ] **Statistics jobs:**
  - [ ] Airport grouping queries execute without errors
  - [ ] Daily/hourly/monthly stats jobs complete
  - [ ] No deadlocks or timeouts

### Documentation

- [ ] **`CLAUDE.md`:**
  - [ ] Schema documentation reflects aspm82, opsnet45 columns
  - [ ] Table definitions updated
  - [ ] Glossary includes OPSNET45

- [ ] **Wiki pages:**
  - [ ] Acronyms.md has OPSNET45 definition
  - [ ] All references to ASPM77 updated to ASPM82
  - [ ] No broken internal links

- [ ] **Code comments:**
  - [ ] No stale ASPM77 references in comments
  - [ ] Migration files have updated headers
  - [ ] SQL files have correct tier names

### Globalization Integration

- [ ] **`codebase-globalization` branch:**
  - [ ] Merged to main successfully
  - [ ] `facility-hierarchy.js` AIRPORT_GROUPS validated
  - [ ] `perti.js` ATCT list matches ASPM82
  - [ ] No duplicate tier lists in other files (nod.js, route-maplibre.js)

- [ ] **Migration tracker:**
  - [ ] `PERTI_MIGRATION_TRACKER.md` updated
  - [ ] Airport tier changes documented
  - [ ] Version bumped appropriately

### Regression Testing

- [ ] **Existing features:**
  - [ ] Plan creation works
  - [ ] TMI publishing works
  - [ ] GDT simulation works
  - [ ] Statistics dashboards load
  - [ ] No new JavaScript errors in console

- [ ] **Backward compatibility:**
  - [ ] Old PERTI plans load correctly
  - [ ] Historical statistics queries still work
  - [ ] No broken API consumers

---

## Rollback Plan

If critical issues are discovered post-deployment:

1. **Revert database migration:**
   ```sql
   -- Rename back
   EXEC sp_rename 'dbo.apts.aspm82', 'aspm77', 'COLUMN';

   -- Update region
   UPDATE dbo.ref_major_airports SET region = 'ASPM77' WHERE region = 'ASPM82';

   -- Re-add removed airports
   INSERT INTO dbo.ref_major_airports ...

   -- Remove added airports
   DELETE FROM dbo.ref_major_airports WHERE airport_icao IN ('KAPA', 'KASE', ...);
   ```

2. **Revert code changes:**
   ```bash
   git revert <commit-hash>
   git push origin feature/airport-group-updates
   ```

3. **Redeploy previous version:**
   - Trigger Azure App Service deployment of previous commit
   - Restart PHP-FPM and daemons

---

## Success Metrics

| Metric | Target | How to Verify |
|--------|--------|---------------|
| ASPM82 airport count | 82 | `SELECT COUNT(*) FROM ref_major_airports WHERE region = 'ASPM82'` |
| OPSNET45 airport count | 45 | `SELECT COUNT(*) FROM airports WHERE opsnet45 = 1` |
| Code references to ASPM77 | 0 | `git grep -i aspm77` returns no results |
| JavaScript console errors | 0 | Browser dev tools on all major pages |
| Failed API requests | 0 | Check `/home/LogFiles/*.log` |
| Documentation accuracy | 100% | Manual review of wiki and CLAUDE.md |

---

## Next Steps

1. **Get design approval** from project owner
2. **Merge codebase-globalization branch first** (Phase 2)
3. **Implement database migrations** (Phase 1)
4. **Complete codebase updates** (Phase 3)
5. **Execute testing checklist** (Phase 4)
6. **Deploy to production**
7. **Monitor for 48 hours** post-deployment

---

## Appendix: File Change Summary

### Total Files Affected: 68+

**Databases (3):**
- VATSIM_ADL
- VATSIM_GIS
- SWIM_API (mirror)

**Migrations (5):**
- 088_airport_groupings_aspm82_opsnet45.sql (NEW)
- 087_atis_tiered_cleanup.sql
- 006_airport_groupings.sql
- 008_apts_military_columns.sql
- 011_ourairports_global_import.sql

**JavaScript (18):**
- facility-hierarchy.js (AIRPORT_GROUPS)
- lib/perti.js (ATCT list)
- splits.js, route-maplibre.js, nod.js, gdt.js, demand.js, plan.js
- initiative_timeline.js, tmi-publish.js, tmi_compliance.js, tmi-gdp.js
- playbook-cdr-search.js, jatoc.js, sua.js
- lib/colors.js, config/constants.js, config/filter-colors.js, config/rate-colors.js

**PHP (10):**
- api/adl/AdlQueryHelper.php, api/adl/stats.php
- api/demand/airports.php, api/splits/tracons.php
- status.php, route.php, gdt.php, demand.php, advisory-builder.php
- scripts/vatsim_adl_daemon.php

**Python (1):**
- nasr_navdata_updater.py

**Data (1):**
- assets/data/apts.csv

**Documentation (9):**
- CLAUDE.md
- wiki/Acronyms.md, wiki/Demand-Analysis-Walkthrough.md, wiki/Algorithm-Zone-Detection.md
- adl/OOOI_Quick_Start.md, adl/OOOI_Zone_Detection_Transition_Summary.md
- assistant_codebase_index_v18.md
- api-docs/openapi.yaml
- PERTI_MIGRATION_TRACKER.md

**Additional (globalization worktree):**
- PERTI_MIGRATION_TRACKER.md
- docs/plans/2026-02-08-airport-groupings-design.md (this file)

---

**Design Status:** ✅ Ready for Implementation
**Estimated Effort:** 4-6 hours (database + code + testing)
**Risk Level:** Medium (schema changes, widespread code updates)
**Dependencies:** Merge `feature/codebase-globalization` first
