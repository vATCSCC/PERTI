# Unified JSON Procedure Format Audit Findings (2026-04-05)

## Scope and Validation Method
- Audited files:
  - `nasr_navdata_updater.py`
  - `adl/scripts/airac_update.py`
  - `scripts/postgis/sync_ref_to_postgis.py`
  - `database/migrations/schema/013_procedures_body_runway_group.sql`
  - `database/migrations/postgis/022_procedures_body_runway_group.sql`
  - `database/migrations/postgis/023_expand_route_runway_aware.sql`
  - `database/migrations/postgis/020_two_pass_disambiguation.sql`
- Used real project data from:
  - `assets/data/dp_full_routes.csv`
  - `assets/data/star_full_routes.csv`
  - `assets/data/apts.csv`
- Validation included direct execution of current code paths (not synthetic fixtures) and key-collision analysis against current CSV contents.

## Findings

### 1) CRITICAL - Non-idempotent NASR merge for procedures
- File: `nasr_navdata_updater.py` lines 1641-1645, 1649-1673, 2964-2970
- Problem:
  - `merge_list_records()` stores `existing_dict = {make_key(r): r for r in existing}`.
  - Duplicate composite keys collapse to one row before comparison.
  - Real data currently contains duplicate merge keys with different `ROUTE_POINTS`:
    - DP key (`DP_COMPUTER_CODE`, `TRANSITION_COMPUTER_CODE`, `ORIG_GROUP`): 8 duplicate groups, all with distinct routes.
    - STAR key (`STAR_COMPUTER_CODE`, `TRANSITION_COMPUTER_CODE`, `DEST_GROUP`): 43 duplicate groups, all with distinct routes.
  - Running the actual method with unchanged data (`existing == new`) produced false modifications:
    - DP output delta: +8 rows
    - STAR output delta: +71 rows
    - Total spurious "modified": 79
- Impact:
  - Repeated runs can mint new `_old_{cycle}_changed` rows even when source data is unchanged.
  - Historical supersession state becomes polluted and row counts grow incorrectly.
- Suggested fix:
  - Do not model `existing` as a single dict value per key.
  - Either:
    - expand key to include true variant discriminator(s) (`BODY_NAME` and route identity), or
    - represent each key as a multiset/list and diff one-to-many.
  - Add idempotence test: unchanged input must produce zero modifications.

### 2) CRITICAL - Supersession reinsert logic in `import_procedures()` creates false "changed" rows
- File: `adl/scripts/airac_update.py` lines 1281-1295, 1313-1337
- Problem:
  - `imported_active` is a dict keyed by `(computer_code, transition_name, runway_group)`.
  - Real data has non-unique keys for that tuple with different `full_route` (variant collisions).
  - Dict overwrite keeps only last route per key.
  - When old rows are iterated, other valid routes under the same key are misclassified as changed.
- Real-data evidence:
  - Simulating old snapshot == newly imported data with current logic still yields:
    - 79 superseded inserts
    - all marked `superseded_reason='changed'`
- Impact:
  - False superseded records are inserted during normal no-change cycles.
- Suggested fix:
  - Track a set of routes per key instead of one route value.
  - Include additional discriminator(s) such as `body_name` or an immutable route variant id in comparison keys.

### 3) HIGH - Changelog key normalization mismatch (`None` vs `'None'`)
- File: `adl/scripts/airac_update.py` lines 245-253, 1164-1166, 1388-1391
- Problem:
  - Snapshot keys normalize `None -> ''` via `_normalize_key_val`.
  - New changelog keys use `str(p['transition_name'])`, so `None -> 'None'`.
  - Keys for null transition names do not match even when data is unchanged.
- Real-data evidence:
  - With old/new logically identical data, current keying would report:
    - `added = 6155`
    - `removed = 6155`
    - `changed = 0`
- Impact:
  - Changelog noise and false delta reporting across cycles.
- Suggested fix:
  - Use the same normalization function for `new_rows` key construction:
    - e.g., `_normalize_key_val(p['transition_name'])`
    - and `_normalize_key_val(p.get('runway_group'))`.

### 4) HIGH - Runway preference in `expand_route()` ignores airport context
- File: `database/migrations/postgis/023_expand_route_runway_aware.sql` lines 67-70, 217-223, 387-389, 414-415, 440-442, 464-466, 489-496
- Problem:
  - Function pre-scans and stores departure/arrival airport/runway context.
  - Airport variables (`v_dep_airport`, `v_arr_airport`) and runway-airport arrays are never used in selection.
  - ORDER BY runway preference checks only substring match on runway text (`LIKE '%31L%'` style), not airport+runway pair.
- Impact:
  - Can prefer wrong procedure variant when multiple airports share runway numbers.
- Suggested fix:
  - Match both airport and runway.
  - Prefer structured predicate over free-text substring:
    - either parse `runway_group` JSON in SQL for `(airport, runway)` match, or
    - store a normalized searchable form explicitly for indexed matching.

### 5) MEDIUM - Azure and PostGIS filtered-index semantics diverge for `is_superseded IS NULL`
- Files:
  - `database/migrations/schema/013_procedures_body_runway_group.sql` line 34
  - `database/migrations/postgis/022_procedures_body_runway_group.sql` line 18
- Problem:
  - Azure filter: `is_superseded = 0` (excludes NULL).
  - PostGIS filter: `(is_superseded IS NULL OR is_superseded = false)` (includes NULL).
- Impact:
  - Cross-DB behavior mismatch.
  - Azure may skip indexing rows where `is_superseded` is NULL.
- Suggested fix:
  - Align predicates across DBs and/or backfill NULLs to explicit false value.

### 6) LOW - Runway parser keeps empty runway tokens on trailing `|`
- Files:
  - `nasr_navdata_updater.py` line 2202
  - `adl/scripts/airac_update.py` line 212
- Problem:
  - `rwys.split('|')` preserves empty tokens (e.g., `31L|` -> `['31L', '']`).
- Real-data note:
  - No trailing-pipe groups found in current `dp_full_routes.csv` / `star_full_routes.csv`.
- Suggested fix:
  - Filter empties (`[r for r in rwys.split('|') if r]`).

## Checks Completed and Confirmed OK
- `build_lid_to_icao_map()` parity:
  - `nasr_navdata_updater.py` and `airac_update.py` produce identical map on current `assets/data/apts.csv` (2683 entries).
- Runway-group conversion parity:
  - `_parse_runway_group()` and `_build_runway_group_json()` are structurally equivalent over 3411 unique real group strings.
- `import_procedures()` INSERT alignment:
  - 16 target columns, 16 values, 13 placeholders (3 constants) are consistent.
- `sync_nav_procedures()` shape:
  - REF SELECT/unpack/POSTGIS INSERT column order is aligned.
  - `body_name`/`runway_group` nullable handling is safe.
- `expand_route()` pass-2 threshold:
  - 0.01 threshold remains in migration 023 (same as migration 020).

