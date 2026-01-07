# ETA/Trajectory Enhancement - Session Transition Document

**Version:** 2.0  
**Updated:** 2026-01-07  
**Previous Session**: ETA Batch Consolidation + Aircraft Performance Infrastructure  
**Transcript**: `/mnt/transcripts/2026-01-07-04-49-30-eta-batch-fix-deployment.txt`

---

## Overall Progress Summary

| Phase | Item | Status | Notes |
|-------|------|--------|-------|
| **A** | #4 Zone Detection (OOOI V3) | ✅ Complete | 85%+ IN capture, 195+ cycles |
| **A** | #6 ETA Consolidation | ✅ Complete | 312ms for 509 flights |
| **B** | #3 Aircraft Performance | 🔄 In Progress | Infrastructure ready, BADA pending |
| **B** | #7 SimBrief Parsing | ⏳ Pending | |
| **C** | #1 Route Distance | ⏳ Pending | |
| **C** | #5 Sector ETA | ⏳ Pending | |
| **D** | #2 Wind Data | ⏳ Pending | |

---

## Session Accomplishments

### 1. ETA Batch Consolidation - COMPLETED ✅

**Problem**: Two procedures calculated ETA with duplicated/divergent logic.

**Solution**: Created `sp_CalculateETABatch` - single source of truth for all ETA calculations.

**Deployment Issues Fixed**:
1. **Subquery in PRINT** - T-SQL doesn't allow `PRINT 'Rows: ' + (SELECT COUNT(*))`. Fixed by capturing to variable first.
2. **CREATE OR ALTER in IF block** - DDL can't be inside conditionals. Fixed by using CREATE OR ALTER directly.

**Files Created**:
```
adl/migrations/044_eta_batch_consolidation.sql  # Original (has errors)
adl/migrations/044b_eta_batch_fix.sql           # Fix deployment ✅
adl/procedures/sp_CalculateETABatch.sql         # Standalone procedure
adl/eta_consolidation_analysis.md               # Design analysis
```

**Performance Results (509 active flights)**:
| Phase | Time | % |
|-------|------|---|
| Work table build | 124ms | 40% |
| Performance lookup | 46ms | 15% |
| ETA calculation | 93ms | 30% |
| UPDATE flight_times | 15ms | 5% |
| **Total** | **312ms** | ✅ |

**Scaling Projections**:
- 2,500 flights: ~1.5 sec
- 5,000 flights: ~3.1 sec
- 10,000 flights: ~6.1 sec

### 2. Aircraft Performance Infrastructure - CREATED ✅

**Current State**: 
- 70 SEED profiles (manual)
- 43 ESTIMATED profiles (auto-generated from defaults)
- 13 DEFAULT category profiles

**Infrastructure Created for BADA Import**:

**New Tables**:
| Table | Purpose |
|-------|---------|
| `aircraft_performance_ptf` | FL-specific performance (TAS, ROCD, fuel at each altitude) |
| `aircraft_performance_apf` | Speed schedules (CAS/Mach for climb/cruise/descent) |
| `aircraft_performance_opf` | Aircraft limits (VMO, MMO, mass, stall speeds) |
| `bada_import_staging` | Staging for raw BADA file content |
| `bada_import_log` | Import audit trail |

**New Columns Added to `aircraft_performance_profiles`**:
- `climb_crossover_ft`, `descent_crossover_ft`
- `max_altitude_ft`, `vmo_kts`, `mmo`
- `approach_speed_kias`, `bada_revision`

**New Functions/Procedures**:
| Object | Purpose |
|--------|---------|
| `fn_GetAircraftPerformanceAtFL()` | Returns altitude-specific performance |
| `sp_SyncBADA_ToProfiles` | Syncs PTF data to summary profiles |
| `vw_AircraftPerformanceSummary` | Easy performance lookup view |

**Files Created**:
```
adl/migrations/045_aircraft_performance_seed.sql    # 115 aircraft profiles
adl/migrations/046_bada_import_infrastructure.sql   # BADA tables & procs
scripts/bada/bada_ptf_parser.py                     # Parse PTF files → SQL
scripts/bada/bada_apf_parser.py                     # Parse APF files → SQL
scripts/bada/README.md                              # Usage instructions
```

### 3. EUROCONTROL BADA License - APPLIED

Applied for BADA license. Recommended application text:
- **Domain**: ATC/ATM Research & Development
- **Use**: ETA prediction for virtual ATC training platform (VATSIM PERTI)
- **Non-commercial**: Yes

---

## Current Database State

### Performance Profile Sources
```sql
SELECT source, COUNT(*) AS profiles, AVG(cruise_speed_ktas) AS avg_cruise
FROM dbo.aircraft_performance_profiles
GROUP BY source;
```
| source | profiles | avg_cruise |
|--------|----------|------------|
| DEFAULT | 13 | 356 |
| ESTIMATED | 43 | 423 |
| SEED | 70 | 371 |

### ETA Method Distribution
```sql
SELECT eta_method, COUNT(*) AS flights
FROM dbo.adl_flight_times ft
JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
WHERE c.is_active = 1
GROUP BY eta_method;
```
Expected: Mostly `BATCH_V1` after consolidation.

---

## Next Steps for Aircraft Performance

### Option A: Wait for BADA (Recommended)
1. BADA license typically takes 1-2 weeks
2. When received, run parsers:
   ```bash
   python scripts/bada/bada_ptf_parser.py -i "C:\BADA\PTF" -o bada_ptf.sql
   python scripts/bada/bada_apf_parser.py -i "C:\BADA\APF" -o bada_apf.sql
   ```
3. Run generated SQL in SSMS
4. Call `EXEC dbo.sp_SyncBADA_ToProfiles;`

### Option B: Use OpenAP (Free Alternative)
1. Install: `pip install openap`
2. Create extraction script to query kinematic data
3. Generate SQL inserts for ~100 aircraft
4. Less accurate than BADA but immediate

### Option C: Expand Manual Seed
1. Research more aircraft from POH/manufacturer specs
2. Add to 045_aircraft_performance_seed.sql
3. Redeploy

---

## Files Reference

### Migrations (Run Order)
```
044_eta_batch_consolidation.sql   # ETA batch (has errors, skip)
044b_eta_batch_fix.sql            # ETA batch fix ✅ DEPLOYED
045_aircraft_performance_seed.sql # 115 aircraft profiles ✅ DEPLOYED
046_bada_import_infrastructure.sql # BADA tables/procs ⏳ READY
```

### Procedures
```
adl/procedures/sp_CalculateETABatch.sql        # Main ETA batch
adl/procedures/fn_GetAircraftPerformance.sql   # Performance lookup
adl/procedures/fn_GetAircraftPerformanceAtFL.sql # FL-specific lookup (in 046)
```

### Scripts
```
scripts/bada/bada_ptf_parser.py   # Parse BADA PTF files
scripts/bada/bada_apf_parser.py   # Parse BADA APF files
scripts/bada/README.md            # Usage guide
```

---

## Verification Queries

### Check ETA Batch Performance
```sql
-- Run ETA batch and check timing
DECLARE @start DATETIME2 = SYSUTCDATETIME();
EXEC dbo.sp_CalculateETABatch;
SELECT DATEDIFF(MILLISECOND, @start, SYSUTCDATETIME()) AS elapsed_ms;
```

### Check Performance Profile Coverage
```sql
-- Which active flights have EXACT vs DEFAULT performance?
SELECT 
    perf.source AS perf_source,
    COUNT(*) AS flights
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
CROSS APPLY dbo.fn_GetAircraftPerformance(ac.aircraft_icao, ac.weight_class, ac.engine_type) perf
WHERE c.is_active = 1
GROUP BY perf.source;
```

### Check BADA Infrastructure Ready
```sql
SELECT 
    'aircraft_performance_ptf' AS tbl, COUNT(*) AS rows FROM dbo.aircraft_performance_ptf
UNION ALL SELECT 'aircraft_performance_apf', COUNT(*) FROM dbo.aircraft_performance_apf
UNION ALL SELECT 'aircraft_performance_opf', COUNT(*) FROM dbo.aircraft_performance_opf;
```

---

## Session Start Prompt

```
Continue working on the ETA/Trajectory Enhancement Project - Aircraft Performance (#3).

Previous session:
- ✅ Completed ETA Batch Consolidation (sp_CalculateETABatch, 312ms for 509 flights)
- ✅ Created BADA import infrastructure (046 migration, Python parsers)
- ✅ Seeded 115 aircraft profiles (045 migration)
- ⏳ Applied for EUROCONTROL BADA license

Current state:
- 70 SEED profiles, 43 ESTIMATED, 13 DEFAULT
- BADA tables exist but are empty (awaiting license)

Options:
1. Deploy 046_bada_import_infrastructure.sql if not yet run
2. Consider OpenAP as free alternative while waiting for BADA
3. Expand manual seed with more aircraft
4. Move on to #7 SimBrief Parsing or other items

What would you like to focus on?
```

---

## Key Technical Notes

### Performance Function Priority Chain
```
fn_GetAircraftPerformance:
1. EXACT match in aircraft_performance_profiles
2. DEFAULT category (_DEF_JL, _DEF_JH, etc.)
3. HARDCODED fallback (280/450/280 kts)

fn_GetAircraftPerformanceAtFL (new):
1. EXACT FL match in aircraft_performance_ptf
2. INTERPOLATED between nearest FLs
3. SUMMARY from aircraft_performance_profiles
```

### BADA File Types
| File | Extension | Contents |
|------|-----------|----------|
| PTF | `.PTF` | Performance tables (TAS, ROCD, fuel by FL) |
| APF | `.APF` | Speed schedules (CAS/Mach procedures) |
| OPF | `.OPF` | Drag polar, thrust, mass limits |

### ETA Batch Method Codes
| Code | Meaning |
|------|---------|
| `BATCH_V1` | Standard batch calculation |
| `BATCH_TAXI` | Taxiing, using taxi time estimate |
| `BATCH_PREFILE` | Prefiled, distance-based estimate |
| `BATCH_ARRIVED` | Already arrived |

---

## Related Documentation

- `ETA_Trajectory_Design_Document_v1_1.md` - Overall design
- `OOOI_Zone_Detection_Transition_Summary.md` - OOOI V3 details
- `assistant_codebase_index_v13.md` - Full codebase reference
- `scripts/bada/README.md` - BADA import instructions
