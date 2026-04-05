# Unified JSON Procedure Format — Full Audit Request

You are auditing a multi-phase code change to a navdata pipeline. Your job is to find bugs, hallucinations, inconsistencies, missed items, and logic errors. Be adversarial — assume the developer made mistakes and find them.

## Project Context

This is a PHP/Python/PostgreSQL air traffic management system. The change adds runway-group awareness to the procedure (DP/STAR) data pipeline:
- **nasr_navdata_updater.py** — Python script that parses FAA NASR subscription data and writes CSV/JSON
- **airac_update.py** — Python script that imports CSV/JSON into Azure SQL (VATSIM_REF)
- **sync_ref_to_postgis.py** — Python script that syncs VATSIM_REF → PostGIS (VATSIM_GIS)
- **Migration 013** — Azure SQL schema (adds columns + index)
- **Migration 022** — PostGIS schema (adds columns + index)
- **Migration 023** — PostGIS function `expand_route()` rewrite with runway awareness

## What Changed (claimed)

1. **FAA LID → ICAO airport code conversion**: Built lookup from apts.csv instead of naive K-prefix (SJU→TJSJ, HNL→PHNL, ANC→PANC)
2. **New DB columns**: `body_name` and `runway_group` on `nav_procedures` in all 3 databases
3. **Merge key fix**: Added ORIG_GROUP/DEST_GROUP to procedure merge keys so runway-group variants aren't collapsed
4. **Supersession key fix**: Per-variant tracking with broad fallback for NULL→populated transition
5. **Runway-aware expand_route()**: Pre-scans route for airport/runway tokens, DP lookups prefer departure runway, STAR lookups prefer arrival runway
6. **Procedure number extraction regex**: Changed from `^([A-Z]+)(\d+)([A-Z]?)$` to `^([A-Z]+)(\d{1,3})([A-Z]{0,2})$`

## Audit Checklist

For each file, check:

### A. nasr_navdata_updater.py
1. Does `build_lid_to_icao_map()` handle: missing apts.csv? Empty ICAO_ID? Duplicate ARPT_ID? Non-US airports in the CSV?
2. Does `_lid_to_icao_convert()` handle: 2-char codes? Numeric codes (0J0, 2A8)? Already-ICAO codes (KJFK)? None input?
3. Does the updated `_parse_runway_group()` correctly parse all ORIG_GROUP formats: `JFK/31L|31R`, `FRG/01|14|19|32 JFK/04R|13L|13R`, empty string, None?
4. Does `_extract_proc_parts()` regex `^([A-Z]+)(\d{1,3})([A-Z]{0,2})$` correctly handle: DEEZZ6, BPK5K, AGT1WD, ADB01D, SEP146, OMNI (no digits), RW31L (starts with alpha but has digits)?
5. Does `write_procedures_json()` correctly pass `lid_to_icao` through all code paths?
6. Is the merge key `['DP_COMPUTER_CODE', 'TRANSITION_COMPUTER_CODE', 'ORIG_GROUP']` safe? What if ORIG_GROUP is empty/None for some records — will that cause false deduplication?
7. Does the existing `remove_duplicates_records()` (full hash) interact badly with the new merge key?

### B. airac_update.py
1. Does `_build_lid_to_icao_map()` produce the same output as the nasr version? Any divergence in edge case handling?
2. Does `_build_runway_group_json()` produce valid JSON? What about: empty orig_group, single airport no runway, pipe-delimited runways with trailing pipe?
3. INSERT SQL: Count the columns in the INSERT statement vs the `?` placeholders vs the values tuple. Do they all match exactly? (This is a common source of bugs)
4. Supersession logic: The new 3-tuple key `(code, trans, rwy_group)` — what happens when old records have `rwy_group=NULL` (empty string after normalization) and new records have `rwy_group='KJFK/31L|31R'`? Does the broad fallback handle this correctly?
5. Supersession re-insert: Does the batch_data tuple for superseded inserts have exactly the right number of elements matching the INSERT statement?
6. Is there a race condition between TRUNCATE TABLE and supersession re-insert?
7. `snapshot_table` with 3-column key: What if `runway_group` is NULL in the DB? Does `_normalize_key_val(None)` → `''` match `p.get('runway_group') or ''` → `''`?

### C. sync_ref_to_postgis.py
1. Column count: SELECT has N columns, INSERT has N-1 columns (no procedure_id), tuple has N-1 values. Verify the exact count.
2. Column ORDER: Do the SELECT columns align positionally with the tuple unpacking? A misalignment would silently put body_name into runway_group's slot or vice versa.
3. Does the sync handle NULL body_name/runway_group correctly (no NOT NULL constraint violations)?

### D. Migration 013 (Azure SQL)
1. Is `NVARCHAR(MAX)` appropriate for runway_group? The plan says flat strings like `KJFK/31L|31R` which are short. MAX has performance implications for indexing.
2. The filtered index `WHERE is_active = 1 AND is_superseded = 0` — what about records where `is_superseded IS NULL`? SQL Server treats `NULL = 0` as FALSE, so NULL records would be excluded from the index. Is that intentional?
3. Is `INCLUDE` before `WHERE` correct SQL Server syntax? (Yes, but verify)

### E. Migration 022 (PostGIS)
1. The index is on `(computer_code, runway_group)` — runway_group is TEXT type. Is indexing a TEXT column appropriate in PostgreSQL? Any performance concerns?
2. `WHERE is_active = true AND (is_superseded IS NULL OR is_superseded = false)` — does this match the Azure SQL filter semantics?

### F. Migration 023 (expand_route — the big one)
1. **Pre-scan regex**: `v_part ~ '^\d{2}/\d{2,3}$'` for NAT coordinates — does this correctly exclude `55/40` but include `KJFK/31L`? What about `A1/B2` (not an airport)?
2. **Airport detection**: `v_rwy_apt ~ '^[A-Z]{3,4}$'` — this would match 3-letter FAA LIDs (SJU) but the runway_group in the DB uses ICAO (TJSJ). The LIKE comparison `np.runway_group LIKE '%31L%'` only searches for the runway, not the airport code. Is this robust enough?
3. **Runway regex**: `v_rwy_list[1] ~ '^\d{2}[LRCB]?$'` — what about runway 01, 9L (single digit), 36C? Does the regex handle all valid runway designators?
4. **split_part safety**: `split_part(v_dep_runways, '|', 1)` — what if v_dep_runways is NULL? Does PostgreSQL's split_part handle NULL input?
5. **The 6 ORDER BY additions**: Each adds `CASE WHEN np.runway_group IS NOT NULL AND v_dep_runways IS NOT NULL AND np.runway_group LIKE '%' || split_part(...) || '%' THEN 0 ELSE 1 END`. What if the runway number appears as a substring of something else? E.g., searching for `%08%` would match `KJFK/08` but also `KJFK/08L|08R`. Is this a problem?
6. **Pass 2 threshold**: The 0.01 degree threshold — is this the same as migration 020? Was it accidentally dropped and re-added?
7. **Overall: Is the function a true superset of migration 020?** Diff the two carefully. Any logic from 020 that's missing in 023 (besides the runway additions)?

### G. Cross-cutting concerns
1. **Data type consistency**: runway_group is `NVARCHAR(MAX)` in Azure SQL but `TEXT` in PostGIS. Are these compatible for the sync?
2. **JSON vs flat string**: The plan says JSON array `[{"airport":"TJFK","runways":["08"]}]` in the JSON file but flat string `TJSJ/08|10` in the DB column. Is this dual format handled correctly everywhere?
3. **CIFP source filter**: The expand_route() WHERE clauses include `'CIFP'` in the source list. Was this intentional? The memory notes say CIFP source records were purged.
4. **Index usage**: Will the new runway_group ORDER BY preference actually use the new index? Or will it cause a full table scan?

## Output Format

For each finding, rate severity:
- **CRITICAL**: Will cause data loss, crashes, or wrong results in production
- **HIGH**: Likely bug that will cause incorrect behavior in some cases
- **MEDIUM**: Potential issue depending on data patterns
- **LOW**: Style, performance, or theoretical concern
- **OK**: Checked and found correct

List every finding with: severity, file, line (if known), description, and suggested fix.

---

## Source Code

Paste the full content of each file below. The files needed are:

1. `nasr_navdata_updater.py` — the changed sections (search for `build_lid_to_icao_map`, `_lid_to_icao_convert`, `_parse_runway_group`, `_extract_proc_parts`, `write_procedures_json`, `_csv_row_to_proc_json`, and the `merge_list_records` call sites around line 2964)
2. `adl/scripts/airac_update.py` — the `import_procedures()` function (lines ~1050-1385)
3. `scripts/postgis/sync_ref_to_postgis.py` — the `sync_nav_procedures()` function
4. `database/migrations/schema/013_procedures_body_runway_group.sql` (full file)
5. `database/migrations/postgis/022_procedures_body_runway_group.sql` (full file)
6. `database/migrations/postgis/023_expand_route_runway_aware.sql` (full file)
7. `database/migrations/postgis/020_two_pass_disambiguation.sql` (the original `expand_route` for diff comparison)
