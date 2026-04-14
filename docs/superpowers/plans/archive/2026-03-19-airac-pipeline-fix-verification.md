# AIRAC Pipeline Fix Plan - Independent Verification (Codex)

- Verified on: 2026-03-19 (America/New_York)
- Source document reviewed: `docs/superpowers/plans/2026-03-19-airac-pipeline-fix.md`
- Verification mode: local-repo evidence only (code + CSV + migration files)
- Constraint: live DB queries to Azure SQL/PostGIS were not runnable from this sandboxed session

## To-Do Stipulations Applied

The following user stipulations were applied as acceptance criteria for this verification pass:

1. Be thorough and comprehensive.
2. Be non-hallucinatory (every claim tied to local evidence or explicitly marked unverifiable).
3. Check and verify work with representative samples.
4. Document all work and findings.

## Method

1. Extracted every concrete claim from the plan (counts, line numbers, paths, algorithms, schema assertions).
2. Cross-checked implementation claims in:
   - `adl/scripts/airac_update.py`
   - `scripts/postgis/sync_ref_to_postgis.py`
   - `nasr_navdata_updater.py`
   - `adl/reference_data/import_airways.php`
   - `adl/reference_data/generate_base_transitions.py`
3. Recomputed CSV-derived metrics from `assets/data/*.csv` using reproducible commands and representative samples.
4. Cross-checked migration/path assertions against `database/migrations/**` and existing numbering.
5. Classified each material claim as: `Verified`, `Partially Verified`, `Contradicted`, or `Unverifiable (local)`.

## Executive Findings

- Overall status: **Partially Verified**.
- Most line-level code references in the plan are accurate.
- Multiple important discrepancies were found (format coverage, pathing, migration numbering, and a larger-than-documented airway `_old_` filtering bug).
- Several DB-count assertions are not verifiable locally without live database access.

## 1) Code-Location Claims

### 1.1 `adl/scripts/airac_update.py` root-cause claims

- **Verified**: `_old_` filters exist at the cited lines.
  - `import_nav_fixes`: lines 118, 142
  - `import_airways`: line 240
  - `import_cdrs`: line 349
  - `import_procedures`: lines 452, 491
- **Verified**: `TRUNCATE TABLE dbo.airway_segments` at line 277.
- **Verified**: Airways import uses one large `executemany()` at line 298.
- **Verified**: No airway-segment regeneration exists in Python pipeline after truncation.
- **Verified**: Airway type classification is limited (`JET`, `VICTOR`, `RNAV`, else `OTHER`) at lines 246-253.
- **Verified**: `sync_ref_to_adl()` table defs at line 708 currently do not include supersession columns.
- **Minor correction**: file length is **992** lines, not 993.

### 1.2 `scripts/postgis/sync_ref_to_postgis.py` sync-scope claims

- **Verified**: script handles `nav_fixes`, `airways` (+ `airway_segments`), `area_centers`.
- **Verified**: missing direct sync handlers for `coded_departure_routes`, `nav_procedures`, `playbook_routes`.
- **Verified**: table choices are `nav_fixes|airways|area_centers|all`, and `all` maps to those 3 logical sync buckets.
- **Minor correction**: file length is **368** lines, not 369.

## 2) CSV/Data Claims (Locally Recomputed)

### 2.1 Raw line counts and `_old_` counts

Computed from local files:

- `points.csv`: 382,232 lines; `_old_` matches 2,771
- `navaids.csv`: 1,874 lines; `_old_` matches 114
- `awys.csv`: 16,988 lines; `_old_` matches 78
- `cdrs.csv`: 47,141 lines; `_old_` matches 6,003
- `dp_full_routes.csv`: 6,507 physical lines (6,506 data rows); `_old_` matches 2,063
- `star_full_routes.csv`: 8,224 physical lines (8,223 data rows); `_old_` matches 2,352
- `playbook_routes.csv`: 55,683 physical lines (55,682 data rows); `_old_` matches 28,441

### 2.2 Import-equivalent counts from current Python logic

Recomputed to mirror current code behavior:

- `nav_fixes`: **381,221** (379,461 waypoints + 1,760 navaids)
- `airways`: **16,956**
- `coded_departure_routes`: **41,138**
- `nav_procedures`: **10,314** (4,443 DP + 5,871 STAR)
- `playbook_routes`: **55,682**

Notes:
- `cdrs`, `procedures`, and `playbook` import-equivalent counts align with plan expectations.
- `airways` count differs from the plan's implied post-filter expectation (~16,910) due a real logic gap described below.

## 3) Critical Discrepancies Found

### 3.1 Airway `_old_` filtering bug is larger than described

- Current code truncates `airway_name` to 8 chars **before** checking `_old_`.
- Filter checks specifically for `_old_` (with trailing underscore).
- This lets many historical names slip through, including:
  - truncated modern names (example: `B932_old_2602_changed` -> `B932_old`)
  - legacy uppercase names (`G8_OLD`, `J101_OLD`, etc.)

Local evidence:
- airway rows with case-insensitive `_old` in name: **140**
- rows filtered by current `_old_` check after truncation: **32**
- historical rows slipping through current logic: **108**

This is materially larger than the plan's framing of 78 `_old_` airway rows simply being discarded.

### 3.2 Additional `_old_`/legacy formats exist beyond plan examples

In live CSVs, these formats exist:

- modern: `name_old_2602_changed|moved|removed`
- legacy pre: `name_old_pre2602`
- legacy uppercase: `name_OLD`
- playbook-specific unlabeled cycle: `name_old_2601` (no reason suffix)

So proposed parser should cover `_OLD` and `_old_<cycle>` (reasonless) explicitly.

### 3.3 Reason vocabulary in current data does not include `superseded`

- In local CSV fields analyzed, modern reason suffixes observed: `moved`, `changed`, `removed`.
- `superseded` is supported in `nasr_navdata_updater.py` but not present in current CSV samples/logs.

### 3.4 Migration file/path assertions need correction

- Proposed file `database/migrations/navdata/001_airac_pipeline_fix.sql` path does not exist yet (no `navdata` folder currently).
- Proposed PostGIS file `database/migrations/postgis/010_supersession_columns.sql` would collide with existing `010_pseudo_fix_skip.sql`.
- Plan should use next available migration numbering strategy instead of reusing `010`.

### 3.5 CIFP/base-transition location/target mismatch

- Plan cites `scripts/postgis/generate_base_transitions.py`.
- Actual script is `adl/reference_data/generate_base_transitions.py`.
- That script writes to SQL Server `dbo.nav_procedures` (ADL-config DB), not directly to PostGIS.

### 3.6 Active fix-name length claim is not true for source CSVs

- Plan says active fix names fit current `nvarchar(16)`.
- Local CSV evidence: 11 active names exceed 16 chars (examples include `ZZ_RJDG_SOUTH_KYUSHU_HIGH_01`).
- Current importer truncates to 16; this creates 3 observed truncation-collision keys.

### 3.7 `1,515 rows survive` airway partial-batch claim cannot be demonstrated from current code path

- Current `airac_update.py` sets `conn.autocommit = False` and rollbacks on `executemany` failure.
- With this code, a duplicate-key error should yield `inserted=0` for that run, not a partially committed batch.
- The plan's observed `~1,515` likely came from external DB state/history, not this exact local code path behavior.

## 4) Claims Verified With Representative Samples

### 4.1 Airways duplicate-name pressure is real

From `awys.csv`:
- total names: 16,988
- unique names: 10,287
- duplicates: 6,701

Top duplicate counts include:
- `W3` (19), `W6` (19), `W19` (19), `W8` (18), `W4` (17), `V3` (17)

Representative sequence diversity verified:
- `W3`: 19 total, 19 unique fix sequences
- `W6`: 19 total, 19 unique fix sequences
- `V3`: 17 total, 17 unique fix sequences

### 4.2 International presence in points/airways is verified

Representative points found in `points.csv`:
- `AADPO,-14.16876111,-170.9675333`
- `ACRON,13.62375277,143.0311417`
- `YAABA`, `YAAKK`, `YBC`, `YVR`, `YYC`

Airway series presence verified in `awys.csv`:
- `A`, `B`, `G`, `L`, `M`, `N`, `R`, `UB`, `UG`, `UL`, `UR`, `W`, etc.

Correction:
- W-series is not limited to `W1-W22`; local file includes many higher IDs (up to `W976`).

## 5) Unverifiable Locally (Need Live DB Evidence)

The following plan assertions could not be independently revalidated in this sandboxed run:

1. Live row counts in `VATSIM_REF`, `VATSIM_ADL`, `VATSIM_GIS`.
2. Existence/details of `IX_airway_name` unique index in live Azure SQL.
3. PostGIS current counts (e.g., `nav_procedures=97,889`, `airway_segments=91,808`).
4. Whether ADL mirrors REF exactly right now in production.

These may still be true; they are simply not locally provable without database connectivity.

## 6) Recommended Plan Corrections Before Implementation

1. Expand `_old_` parser/ingest logic to handle all observed forms:
   - `_old_<cycle>_<reason>`
   - `_old_pre<cycle>`
   - `_old_<cycle>` (no reason)
   - `_OLD` / `_OLD<cycle>`
2. Parse historical suffix on full source field **before** truncation.
3. Adjust expected airway import counts to account for currently slipping historical rows.
4. Correct CIFP base-transition script path and target-database narrative.
5. Avoid migration filename collision (`postgis/010_*` already exists).
6. Reconcile/clarify any claims that depend on live DB snapshots (include timestamped query output in-plan).
7. Add regression tests for:
   - suffix parsing coverage
   - truncation-before-filter regressions
   - airway duplicate insert behavior under unique/non-unique indexes

## 7) Final Classification of Original Plan

- **Strong parts**: core bug identification in `airac_update.py`, missing PostGIS table sync scope, and need for supersession/changelog modeling.
- **Needs correction**: suffix format coverage, migration path/versioning, CIFP/PostGIS narrative, some count assumptions, and one claimed behavior (`~1,515` survivors) that is not explainable by current local code path alone.

