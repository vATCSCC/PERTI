# Two-Pass Route Disambiguation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix wrong-continent waypoint resolution in `expand_route()` by adding a backward-looking correction pass that uses bidirectional anchor context to re-resolve ambiguous fixes.

**Architecture:** The existing forward pass in `expand_route()` is modified to buffer results into PL/pgSQL arrays instead of streaming via `RETURN NEXT`. After the forward pass completes, a correction pass classifies each waypoint as an anchor (high-confidence) or ambiguous (multiple candidates in `nav_fixes`), then re-resolves ambiguous waypoints using the midpoint of the nearest left and right anchors as proximity context. Additionally, `resolve_waypoint()`'s no-context fallback path gets a deterministic `ORDER BY` to eliminate nondeterminism.

**Tech Stack:** PostgreSQL/PostGIS PL/pgSQL, PHP (API layer — no changes needed), manual testing via SWIM resolve endpoint.

**Design doc:** `docs/plans/2026-03-30-two-pass-disambiguation-design.md`

---

### Task 1: Fix `resolve_waypoint()` No-Context Nondeterminism

**Files:**
- Create: `database/migrations/postgis/020_two_pass_disambiguation.sql`

This is a quick standalone fix. The no-context path in `resolve_waypoint()` currently uses `LIMIT 1` without `ORDER BY`, returning whichever row the query planner picks. Add deterministic ordering.

**Step 1: Create migration file with the `resolve_waypoint()` fix**

Create `database/migrations/postgis/020_two_pass_disambiguation.sql` with the following content at the top:

```sql
-- ============================================================================
-- Migration 020: Two-Pass Route Disambiguation
-- ============================================================================
-- Problem: expand_route() resolves waypoints left-to-right in a single forward
-- pass. When the first waypoint is ambiguous and has no prior context,
-- resolve_waypoint() picks an arbitrary row (LIMIT 1 without ORDER BY),
-- sometimes resolving to the wrong continent. The forward pass cannot
-- self-correct because it never looks ahead.
--
-- Fix:
-- 1. resolve_waypoint(): Add ORDER BY to no-context fallback for determinism
-- 2. expand_route(): Buffer forward pass results into arrays, then run an
--    anchor-based correction pass that re-resolves ambiguous waypoints
--    using bidirectional context from high-confidence "anchor" waypoints
--
-- Validated against 327 real routes (CTP, Caribbean, polar, domestic).
-- Found 4 real wrong-continent bugs, 0 false positives.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. resolve_waypoint: deterministic no-context fallback
-- ----------------------------------------------------------------------------
-- The no-context path (p_context_lat IS NULL) previously used LIMIT 1 without
-- ORDER BY, returning whichever row the query planner happened to pick.
-- Now orders by lat, lon for deterministic (though still arbitrary) selection.
-- This is NOT a substitute for the correction pass — it just eliminates
-- nondeterminism in the edge case where no context is available at all.

CREATE OR REPLACE FUNCTION public.resolve_waypoint(
    p_fix_name character varying,
    p_context_lat numeric DEFAULT NULL,
    p_context_lon numeric DEFAULT NULL
)
RETURNS TABLE(fix_id character varying, lat numeric, lon numeric, source character varying)
LANGUAGE plpgsql
STABLE
AS $function$
DECLARE
    v_coord RECORD;
BEGIN
    -- Try nav_fixes first (most common - waypoints, VORs, NDBs)
    IF p_context_lat IS NOT NULL AND p_context_lon IS NOT NULL THEN
        -- Context-aware: pick closest match using equirectangular approximation
        RETURN QUERY
        SELECT nf.fix_name::VARCHAR, nf.lat, nf.lon, 'nav_fix'::VARCHAR
        FROM nav_fixes nf
        WHERE nf.fix_name = p_fix_name
        ORDER BY (nf.lat - p_context_lat)^2 +
                 ((nf.lon - p_context_lon) * cos(radians(p_context_lat)))^2
        LIMIT 1;
    ELSE
        -- No context: deterministic fallback (prefer northern/western hemisphere
        -- since most VATSIM traffic is US/Europe, but this is just for consistency)
        RETURN QUERY
        SELECT nf.fix_name::VARCHAR, nf.lat, nf.lon, 'nav_fix'::VARCHAR
        FROM nav_fixes nf
        WHERE nf.fix_name = p_fix_name
        ORDER BY nf.lat DESC, nf.lon ASC
        LIMIT 1;
    END IF;
    IF FOUND THEN RETURN; END IF;

    -- Try airports by ICAO code (e.g., KJFK, KLAX)
    RETURN QUERY
    SELECT a.icao_id::VARCHAR, a.lat, a.lon, 'airport'::VARCHAR
    FROM airports a
    WHERE a.icao_id = p_fix_name
    LIMIT 1;
    IF FOUND THEN RETURN; END IF;

    -- Try airports by FAA code (e.g., DFW, JFK - 3-letter codes)
    RETURN QUERY
    SELECT a.icao_id::VARCHAR, a.lat, a.lon, 'airport_faa'::VARCHAR
    FROM airports a
    WHERE a.arpt_id = p_fix_name
    LIMIT 1;
    IF FOUND THEN RETURN; END IF;

    -- Try with K prefix for US airports (3-letter to ICAO conversion)
    IF LENGTH(p_fix_name) = 3 AND p_fix_name ~ '^[A-Z]{3}$' THEN
        RETURN QUERY
        SELECT a.icao_id::VARCHAR, a.lat, a.lon, 'airport_k'::VARCHAR
        FROM airports a
        WHERE a.icao_id = 'K' || p_fix_name
        LIMIT 1;
        IF FOUND THEN RETURN; END IF;
    END IF;

    -- Try area_centers (ARTCC/TRACON pseudo-fixes like ZNY, ZBW)
    RETURN QUERY
    SELECT ac.center_code::VARCHAR, ac.lat, ac.lon, 'area_center'::VARCHAR
    FROM area_centers ac
    WHERE ac.center_code = p_fix_name
    LIMIT 1;
    IF FOUND THEN RETURN; END IF;

    -- Fallback: try parsing as a coordinate token
    SELECT ct.lat, ct.lon INTO v_coord
    FROM parse_coordinate_token(p_fix_name) ct
    LIMIT 1;

    IF v_coord.lat IS NOT NULL THEN
        RETURN QUERY SELECT p_fix_name::VARCHAR, v_coord.lat, v_coord.lon, 'coordinate'::VARCHAR;
        RETURN;
    END IF;
END;
$function$;
```

**Step 2: Verify the fix locally**

Deploy to PostGIS and run:
```sql
-- Before fix: LIMIT 1 without ORDER BY, nondeterministic
-- After fix: ORDER BY lat DESC, lon ASC — deterministic
SELECT * FROM resolve_waypoint('PIKIL');
-- Should return the northernmost PIKIL (North Atlantic, ~56N)
-- instead of arbitrary (was returning Australia, -32S)

SELECT * FROM resolve_waypoint('JSY');
-- Should return a deterministic result (previously arbitrary)
```

**Step 3: Commit**

```bash
git add database/migrations/postgis/020_two_pass_disambiguation.sql
git commit -m "feat(postgis): add deterministic ORDER BY to resolve_waypoint no-context path"
```

---

### Task 2: Convert `expand_route()` Forward Pass to Buffered Output

**Files:**
- Modify: `database/migrations/postgis/020_two_pass_disambiguation.sql`

The current `expand_route()` uses `RETURN NEXT` to stream each waypoint as it's resolved. To add a correction pass, we need all waypoints buffered first. This task converts from streaming to array-buffered output without changing any logic.

**Step 1: Add the buffered `expand_route()` to the migration file**

Append to `020_two_pass_disambiguation.sql`. The function is identical to migration 019's version except:
- Replace every `RETURN NEXT;` with array append operations
- Add array declarations: `v_r_ids`, `v_r_lats`, `v_r_lons`, `v_r_types`
- Add counter: `v_r_count`
- At the end, loop over arrays and `RETURN NEXT`
- The correction pass will be added in Task 3 (between buffer and return)

The key variable declarations to add (after existing declarations):

```sql
    -- ── Correction-pass buffer arrays ──
    v_r_count INT := 0;
    v_r_ids   VARCHAR[];
    v_r_lats  NUMERIC[];
    v_r_lons  NUMERIC[];
    v_r_types VARCHAR[];
```

Every place in the function body that currently does:
```sql
                v_seq := v_seq + 1;
                waypoint_seq := v_seq;
                waypoint_id := <fix_id>;
                lat := <lat>;
                lon := <lon>;
                waypoint_type := <type>;
                RETURN NEXT;
```

Replace with:
```sql
                v_r_count := v_r_count + 1;
                v_r_ids[v_r_count] := <fix_id>;
                v_r_lats[v_r_count] := <lat>;
                v_r_lons[v_r_count] := <lon>;
                v_r_types[v_r_count] := <type>;
```

And at the very end of the function (before `END;`), add the return loop:

```sql
    -- ── Return buffered results ──
    FOR v_k IN 1..v_r_count LOOP
        waypoint_seq := v_k;
        waypoint_id := v_r_ids[v_k];
        lat := v_r_lats[v_k];
        lon := v_r_lons[v_k];
        waypoint_type := v_r_types[v_k];
        RETURN NEXT;
    END LOOP;
```

**Important**: There are **8 `RETURN NEXT` sites** in the current function (migration 019):
1. Line 371 — procedure expansion (dot-notation)
2. Line 430 — airway intermediate waypoints
3. Line 464 — direct waypoint/fix resolution
4. Line 569 — standalone procedure expansion (DP/STAR fallback)

Plus the `v_seq` counter is no longer needed for output (use `v_r_count` instead), but keep it for any internal logic that references it.

Also add the `v_k` loop variable declaration:
```sql
    v_k INT;
```

**Step 2: Deploy and verify output unchanged**

Deploy to PostGIS and verify identical output for a known route:
```sql
-- Pick a route with mixed types (airport, airway, coordinate, direct fix)
SELECT * FROM expand_route('KJFK MERIT HFD PUT BOS TOPPS N507B ALLRY');
-- Output should be identical to pre-change (same waypoints, same order, same coords)

-- Also verify a coordinate-heavy polar route
SELECT * FROM expand_route('CYYC SAXOL 54N10 PETMA 6094N 6380N AVPUT');
```

**Step 3: Commit**

```bash
git add database/migrations/postgis/020_two_pass_disambiguation.sql
git commit -m "refactor(postgis): buffer expand_route forward pass into arrays for correction pass"
```

---

### Task 3: Add Anchor Classification + Correction Pass

**Files:**
- Modify: `database/migrations/postgis/020_two_pass_disambiguation.sql`

This is the core change. Insert the correction pass between the forward-pass buffer loop and the return loop.

**Step 1: Add the correction pass**

Between the end of the main `WHILE` loop and the return loop, add:

```sql
    -- ══════════════════════════════════════════════════════════════════════
    -- PASS 2: Anchor-based correction
    -- ══════════════════════════════════════════════════════════════════════
    -- Classify each buffered waypoint as "anchor" (high-confidence position)
    -- or "ambiguous" (multiple candidates in nav_fixes). Re-resolve ambiguous
    -- waypoints using the midpoint of nearest left/right anchors as context.
    --
    -- This catches wrong-continent resolution when the first waypoint has
    -- no prior context (e.g., PIKIL → Australia instead of N Atlantic).

    IF v_r_count > 1 THEN
        DECLARE
            v_is_anchor BOOLEAN[];
            v_cand_count INT;
            v_left_lat NUMERIC;
            v_left_lon NUMERIC;
            v_right_lat NUMERIC;
            v_right_lon NUMERIC;
            v_ctx_lat NUMERIC;
            v_ctx_lon NUMERIC;
            v_new_lat NUMERIC;
            v_new_lon NUMERIC;
        BEGIN
            -- Step 1: Classify anchors
            FOR v_k IN 1..v_r_count LOOP
                -- Airports, coordinates, area centers, and airway intermediates
                -- are always anchors (their positions come from authoritative sources)
                IF v_r_types[v_k] IN ('airport', 'airport_faa', 'airport_k',
                                       'coordinate', 'area_center', 'procedure')
                   OR v_r_types[v_k] LIKE 'airway_%' THEN
                    v_is_anchor[v_k] := true;
                ELSE
                    -- Check if this fix_name has multiple entries in nav_fixes
                    SELECT COUNT(*) INTO v_cand_count
                    FROM nav_fixes nf
                    WHERE nf.fix_name = v_r_ids[v_k];

                    v_is_anchor[v_k] := (v_cand_count <= 1);
                END IF;
            END LOOP;

            -- Step 2: Re-resolve ambiguous waypoints with bidirectional context
            FOR v_k IN 1..v_r_count LOOP
                IF v_is_anchor[v_k] THEN
                    CONTINUE;
                END IF;

                -- Find nearest left anchor
                v_left_lat := NULL;
                v_left_lon := NULL;
                FOR v_j IN REVERSE (v_k - 1)..1 LOOP
                    IF v_is_anchor[v_j] THEN
                        v_left_lat := v_r_lats[v_j];
                        v_left_lon := v_r_lons[v_j];
                        EXIT;
                    END IF;
                END LOOP;

                -- Find nearest right anchor
                v_right_lat := NULL;
                v_right_lon := NULL;
                FOR v_j IN (v_k + 1)..v_r_count LOOP
                    IF v_is_anchor[v_j] THEN
                        v_right_lat := v_r_lats[v_j];
                        v_right_lon := v_r_lons[v_j];
                        EXIT;
                    END IF;
                END LOOP;

                -- Compute context point from anchors
                IF v_left_lat IS NOT NULL AND v_right_lat IS NOT NULL THEN
                    -- Bidirectional: use midpoint
                    v_ctx_lat := (v_left_lat + v_right_lat) / 2.0;
                    v_ctx_lon := (v_left_lon + v_right_lon) / 2.0;
                ELSIF v_right_lat IS NOT NULL THEN
                    -- Right-only: forward pass had no left context, so right
                    -- context is new information
                    v_ctx_lat := v_right_lat;
                    v_ctx_lon := v_right_lon;
                ELSE
                    -- Left-only: forward pass already used left context,
                    -- no improvement possible. Skip.
                    CONTINUE;
                END IF;

                -- Re-resolve with anchor context
                SELECT rw.lat, rw.lon INTO v_new_lat, v_new_lon
                FROM resolve_waypoint(v_r_ids[v_k], v_ctx_lat, v_ctx_lon) rw
                LIMIT 1;

                -- Update if resolution changed significantly (>0.01 deg ~ 0.6nm)
                IF v_new_lat IS NOT NULL THEN
                    IF abs(v_r_lats[v_k] - v_new_lat) > 0.01
                       OR abs(v_r_lons[v_k] - v_new_lon) > 0.01 THEN
                        v_r_lats[v_k] := v_new_lat;
                        v_r_lons[v_k] := v_new_lon;
                    END IF;
                END IF;
            END LOOP;
        END;
    END IF;
```

**Important PL/pgSQL note**: The `DECLARE ... BEGIN ... END;` block inside the `IF` creates a nested block scope for the correction-pass variables. This is valid PL/pgSQL and keeps the variable namespace clean. If the PostgreSQL version on production doesn't support nested DECLARE (unlikely — it's been supported since 9.x), flatten the variables into the top-level DECLARE block instead.

**Step 2: Deploy and test with known bad routes**

```sql
-- PIKIL: should resolve to North Atlantic (~56N, -15W), NOT Australia (-32S, 117E)
SELECT * FROM expand_route('PIKIL SOVED MIMKU SURAT PETIL VEDAR VEDEN M869 LEP M864 DIPOP UUEE');
-- Check: first waypoint (PIKIL) lat should be ~56, NOT ~-32

-- SPP: should resolve to Caribbean (~10N, -66W), NOT Spain (~36N, -5W)
SELECT * FROM expand_route('UNKN SPP FALLA SELEK UCL UNKN');
-- Check: SPP lat should be ~10, NOT ~36

-- Normal route should be unchanged:
SELECT * FROM expand_route('KJFK MERIT HFD PUT BOS TOPPS N507B ALLRY');
```

**Step 3: Commit**

```bash
git add database/migrations/postgis/020_two_pass_disambiguation.sql
git commit -m "feat(postgis): add anchor-based correction pass to expand_route for disambiguation"
```

---

### Task 4: Full Validation Test Suite

**Files:**
- No code changes — this is a testing task

Run the complete 327-route test suite through the updated `expand_route()` on PostGIS to verify:

**Step 1: Test domestic US routes (should be unchanged)**

```sql
-- ANC_CARGO_ROUTES sample: all US/Canada, forward pass is sufficient
SELECT * FROM expand_route('PANC PRIOR PRIOR1 V438 EHM BGQ T222 FBK FAI');
```

**Step 2: Test CTP oceanic routes (should catch PIKIL/SPP corrections)**

```sql
-- All 3 PIKIL routes:
SELECT waypoint_id, lat, lon FROM expand_route('PIKIL SOVED MIMKU SURAT PETIL VEDAR VEDEN M869 LEP M864 DIPOP UUEE') WHERE waypoint_id = 'PIKIL';
SELECT waypoint_id, lat, lon FROM expand_route('PIKIL SOVED MIMKU SURAT PETIL KOSEB BANUB ENOBI L979 MATUS N869 RATIN L999 AGLAN M864 DIPOP UUEE') WHERE waypoint_id = 'PIKIL';
SELECT waypoint_id, lat, lon FROM expand_route('PIKIL SOVED AMLAD ALOTI RIPAM ENGM') WHERE waypoint_id = 'PIKIL';
-- All should show lat ~56, lon ~-15 (North Atlantic)
```

**Step 3: Test Caribbean routes**

```sql
-- PASA AVOID MKJK: SPP should be Caribbean
SELECT waypoint_id, lat, lon FROM expand_route('UNKN SPP FALLA SELEK UCL UNKN') WHERE waypoint_id = 'SPP';
-- Should show lat ~10, lon ~-66 (Caribbean), NOT lat ~36 (Spain)
```

**Step 4: Test coordinate-heavy polar routes**

```sql
SELECT * FROM expand_route('CYYC SAXOL 54N10 PETMA 6094N 6380N AVPUT');
-- Coordinates are anchors; forward pass handles these correctly
```

**Step 5: Commit the final migration**

```bash
git add database/migrations/postgis/020_two_pass_disambiguation.sql
git commit -m "feat(postgis): migration 020 — two-pass disambiguation for expand_route

Adds anchor-based correction pass that re-resolves ambiguous waypoints
using bidirectional context. Fixes wrong-continent resolution when the
first waypoint has no prior context (PIKIL, SPP, JSY).

Validated against 327 real routes: 4 real corrections, 0 false positives."
```

---

### Task 5: Deploy to Production PostGIS

**Files:**
- Deploy: `database/migrations/postgis/020_two_pass_disambiguation.sql`

**Step 1: Deploy via Kudu temp script**

Since PostGIS is only accessible from the App Service, deploy using the Kudu VFS API + temp PHP script pattern:

1. Create a PHP script that connects to PostGIS and executes the migration SQL
2. Upload via `PUT /api/vfs/site/wwwroot/_migrate_020.php` with `If-Match: *` header
3. Wait 65 seconds for OPcache revalidation
4. Hit `https://perti.vatcscc.org/_migrate_020.php` to execute
5. Verify output shows successful function replacement
6. Delete the temp script via `DELETE /api/vfs/site/wwwroot/_migrate_020.php`

**Step 2: Verify via SWIM resolve endpoint**

```bash
# Test PIKIL route via the public API
curl -s "https://perti.vatcscc.org/api/swim/v1/routes/resolve.php" \
  -d "route=PIKIL SOVED MIMKU SURAT PETIL VEDAR VEDEN M869 LEP M864 DIPOP UUEE" \
  | jq '.waypoints[0]'
# Expected: lat ~56, lon ~-15 (North Atlantic)
```

**Step 3: Verify no regression on normal routes**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/routes/resolve.php" \
  -d "route=KJFK MERIT HFD PUT BOS TOPPS N507B ALLRY" | jq '.waypoints'
```

---

## Summary of Changes

| File | Change | Lines |
|------|--------|-------|
| `database/migrations/postgis/020_two_pass_disambiguation.sql` | New migration | ~700 |

### Functions Modified

1. **`resolve_waypoint()`** — Added `ORDER BY nf.lat DESC, nf.lon ASC` to no-context `LIMIT 1` path
2. **`expand_route()`** — Converted from streaming to buffered output; added anchor classification + correction pass after forward pass

### No Changes To

- PHP API layer (`api/swim/v1/routes/resolve.php`) — transparent, calls same function
- `expand_airway()` — unchanged
- `parse_coordinate_token()` — unchanged
- Any JavaScript/frontend code
- Any other database
