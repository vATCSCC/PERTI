# Route Pipeline Alignment Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Eliminate ARTCC normalization inconsistencies (D3/D8) and add ST_IsValid boundary protection (D5) across all route processing pipelines.

**Architecture:** Bottom-up: Layer 1 adds ST_IsValid boundary protection to 5 high-risk files. Layer 2 adds a PostGIS `normalize_artcc_code()` function applied inside `expand_route_with_artccs()` and `analyze_route_traversal()`. Layer 3 creates a shared PHP `ArtccNormalizer` class replacing 7 copy-pasted functions and fixing the origin/dest normalization gap.

**Tech Stack:** PHP 8.2, PostgreSQL/PostGIS (plpgsql), SQL migrations

**Worktree:** `C:/Temp/perti-worktrees/pipeline-alignment` on branch `feature/pipeline-alignment`

**Design doc:** `docs/plans/2026-03-20-pipeline-alignment-design.md`

---

## Task 1: Create PostGIS `normalize_artcc_code()` Function (Layer 2)

**Files:**
- Create: `database/migrations/postgis/017_normalize_artcc_function.sql`

**Step 1: Write the migration file**

```sql
-- Migration: 017_normalize_artcc_function.sql
-- Purpose: Canonical ARTCC code normalization function for PostGIS
-- Handles: K-prefix stripping (KZNY→ZNY), Canadian 3→4 letter (CZE→CZEG), PAZA→ZAN

CREATE OR REPLACE FUNCTION normalize_artcc_code(code TEXT)
RETURNS TEXT LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    v_upper TEXT := upper(trim(code));
    v_map   JSONB := '{
        "CZE":"CZEG", "CZU":"CZUL", "CZV":"CZVR",
        "CZW":"CZWG", "CZY":"CZYZ", "CZM":"CZQM",
        "CZQ":"CZQX", "CZO":"CZQO",
        "PAZA":"ZAN", "KZAK":"ZAK", "KZWY":"ZWY",
        "PGZU":"ZUA", "PAZN":"ZAP", "PHZH":"ZHN"
    }';
BEGIN
    -- Check alias map first
    IF v_map ? v_upper THEN
        RETURN v_map ->> v_upper;
    END IF;
    -- K-prefix stripping (KZ** pattern only)
    IF v_upper ~ '^KZ[A-Z]{2}$' THEN
        RETURN substring(v_upper FROM 2);
    END IF;
    RETURN v_upper;
END;
$$;

COMMENT ON FUNCTION normalize_artcc_code(TEXT) IS
    'Normalizes ARTCC codes: strips K-prefix (KZNY→ZNY), expands Canadian 3-letter (CZE→CZEG), maps PAZA→ZAN and other ICAO→FAA conversions';
```

**Step 2: Commit**

```bash
cd C:/Temp/perti-worktrees/pipeline-alignment
git add database/migrations/postgis/017_normalize_artcc_function.sql
git commit -m "feat: add PostGIS normalize_artcc_code() function

Canonical normalization for ARTCC codes at the SQL layer.
Handles K-prefix stripping, Canadian 3→4 letter expansion,
and ICAO→FAA mappings (PAZA→ZAN, etc.)."
```

---

## Task 2: Apply `normalize_artcc_code()` to `expand_route_with_artccs()` (Layer 2)

**Files:**
- Modify: `scripts/postgis/004_route_expansion_functions.sql:814-824`

**Step 1: Wrap `artcc_code` in the ARTCC traversal query**

At line 815-824, the current code builds the `v_artccs` array from raw `artcc_code`. Wrap it with `normalize_artcc_code()`:

```sql
-- BEFORE (lines 814-824):
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ...
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;

-- AFTER:
        SELECT ARRAY(
            SELECT normalize_artcc_code(artcc_code) FROM (
                SELECT DISTINCT ON (normalize_artcc_code(ab.artcc_code))
                    normalize_artcc_code(ab.artcc_code) AS artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                ORDER BY normalize_artcc_code(ab.artcc_code), traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;
```

The key changes:
- `DISTINCT ON` uses normalized code (so `KZNY` and `ZNY` don't appear as separate entries)
- The outer SELECT wraps `artcc_code` in `normalize_artcc_code()`
- `ORDER BY` in the subquery uses the normalized code to match `DISTINCT ON`

**Step 2: Commit**

```bash
git add scripts/postgis/004_route_expansion_functions.sql
git commit -m "feat: apply normalize_artcc_code() in expand_route_with_artccs()

PostGIS now returns normalized ARTCC codes (ZNY not KZNY, CZEG not CZE).
PHP callers that already normalize will get the same result (idempotent)."
```

---

## Task 3: Apply `normalize_artcc_code()` to `analyze_route_traversal()` (Layer 2)

**Files:**
- Modify: `database/migrations/postgis/route_analysis_function.sql:66-70`

**Step 1: Wrap `artcc_code` in the facility_id output**

At line 70, change the raw `ab.artcc_code::text AS fid` to use normalization:

```sql
-- BEFORE (line 70):
            ab.artcc_code::text AS fid,

-- AFTER:
            normalize_artcc_code(ab.artcc_code) AS fid,
```

Also update the ARTCC/FIR classification at lines 66-69 to use normalized code:

```sql
-- BEFORE (lines 66-69):
            CASE
                WHEN ab.artcc_code ~ '^KZ[A-Z]{2}' THEN 'ARTCC'
                ELSE 'FIR'
            END AS ftype,

-- AFTER:
            CASE
                WHEN normalize_artcc_code(ab.artcc_code) ~ '^Z[A-Z]{2}$' THEN 'ARTCC'
                WHEN ab.artcc_code ~ '^CZ' THEN 'FIR'
                ELSE 'FIR'
            END AS ftype,
```

After normalization, US ARTCCs are 3-letter `Z**` codes (not `KZ**`), so the regex changes. Canadian FIRs keep `CZ` prefix (e.g., `CZEG`).

**Step 2: Commit**

```bash
git add database/migrations/postgis/route_analysis_function.sql
git commit -m "feat: apply normalize_artcc_code() in analyze_route_traversal()

Analysis API now returns normalized facility IDs (ZNY not KZNY).
Updated ARTCC/FIR classification regex for normalized codes."
```

---

## Task 4: Add ST_IsValid Pre-Filter to `playbook_helpers.php` (Layer 1)

**Files:**
- Modify: `api/mgt/playbook/playbook_helpers.php:162-170`

**Step 1: Add ST_IsValid subquery filter to TRACON and sector boundary queries**

Replace the direct table references with validity-filtered subqueries:

```php
// BEFORE (lines 162-170):
                        SELECT 'tracon', t.tracon_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                        FROM tracon_boundaries t WHERE ST_Intersects(route.geom, t.geom)
                            AND route.geom IS NOT NULL
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                        FROM sector_boundaries s WHERE ST_Intersects(route.geom, s.geom)
                            AND route.geom IS NOT NULL

// AFTER:
                        SELECT 'tracon', t.tracon_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                        FROM (SELECT tracon_code, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) t
                        WHERE ST_Intersects(route.geom, t.geom)
                            AND route.geom IS NOT NULL
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                        FROM (SELECT sector_code, sector_type, geom FROM sector_boundaries WHERE ST_IsValid(geom)) s
                        WHERE ST_Intersects(route.geom, s.geom)
                            AND route.geom IS NOT NULL
```

**Step 2: Commit**

```bash
git add api/mgt/playbook/playbook_helpers.php
git commit -m "fix: add ST_IsValid pre-filter to playbook TRACON/sector queries

Prevents crashes from 108 invalid TRACON + 265 invalid sector
boundary geometries when saving playbook routes."
```

---

## Task 5: Add ST_IsValid Pre-Filter to `recompute_traversed.php` (Layer 1)

**Files:**
- Modify: `scripts/playbook/recompute_traversed.php:68-76`

**Step 1: Same ST_IsValid subquery pattern as Task 4**

```php
// BEFORE (lines 68-76):
                        SELECT 'tracon', t.tracon_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                        FROM tracon_boundaries t WHERE ST_Intersects(route.geom, t.geom)
                            AND route.geom IS NOT NULL
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                        FROM sector_boundaries s WHERE ST_Intersects(route.geom, s.geom)
                            AND route.geom IS NOT NULL

// AFTER:
                        SELECT 'tracon', t.tracon_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                        FROM (SELECT tracon_code, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) t
                        WHERE ST_Intersects(route.geom, t.geom)
                            AND route.geom IS NOT NULL
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                        FROM (SELECT sector_code, sector_type, geom FROM sector_boundaries WHERE ST_IsValid(geom)) s
                        WHERE ST_Intersects(route.geom, s.geom)
                            AND route.geom IS NOT NULL
```

**Step 2: Commit**

```bash
git add scripts/playbook/recompute_traversed.php
git commit -m "fix: add ST_IsValid pre-filter to recompute_traversed TRACON/sector queries"
```

---

## Task 6: Add ST_IsValid Pre-Filter to PostGIS Batch Functions (Layer 1)

**Files:**
- Modify: `scripts/postgis/005_batch_route_functions.sql:174,185,196,207`

**Step 1: Add ST_IsValid pre-filter to all 4 boundary queries**

For each of the 4 ST_Intersects calls in `expand_routes_full()`, change the direct table reference to a validity-filtered subquery. The pattern for each:

```sql
-- BEFORE (line 174, LOW sectors):
FROM sector_boundaries sb
WHERE ST_Intersects(v_geom, sb.geom) AND sb.sector_type = 'LOW'

-- AFTER:
FROM (SELECT sector_code, geom FROM sector_boundaries WHERE ST_IsValid(geom) AND sector_type = 'LOW') sb
WHERE ST_Intersects(v_geom, sb.geom)
```

Apply the same pattern to lines 185 (HIGH sectors), 196 (SUPERHIGH sectors), and 207 (TRACONs):

```sql
-- Line 207 (TRACONs):
-- BEFORE:
FROM tracon_boundaries tb WHERE ST_Intersects(v_geom, tb.geom)

-- AFTER:
FROM (SELECT tracon_code, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) tb
WHERE ST_Intersects(v_geom, tb.geom)
```

**Step 2: Commit**

```bash
git add scripts/postgis/005_batch_route_functions.sql
git commit -m "fix: add ST_IsValid pre-filter to batch route expansion functions

Protects expand_routes_full() from 108 invalid TRACON + 265 invalid
sector boundary geometries."
```

---

## Task 7: Add ST_IsValid Pre-Filter to Trajectory Crossing Functions (Layer 1)

**Files:**
- Modify: `scripts/postgis/008_trajectory_crossings.sql:184,274`
- Modify: `scripts/postgis/009_tracon_crossings.sql:56,144,155`

**Step 1: Fix 008_trajectory_crossings.sql**

```sql
-- Line 184 (get_trajectory_sector_crossings):
-- BEFORE:
FROM sector_boundaries sb
WHERE ST_Intersects(trajectory, sb.geom)

-- AFTER:
FROM (SELECT sector_code, sector_type, geom FROM sector_boundaries WHERE ST_IsValid(geom)) sb
WHERE ST_Intersects(trajectory, sb.geom)

-- Line 274 (get_trajectory_all_crossings, sector_crossings CTE):
-- BEFORE:
FROM sector_boundaries sb
WHERE ST_Intersects(trajectory, sb.geom)

-- AFTER:
FROM (SELECT sector_code, sector_type, geom FROM sector_boundaries WHERE ST_IsValid(geom)) sb
WHERE ST_Intersects(trajectory, sb.geom)
```

**Step 2: Fix 009_tracon_crossings.sql**

```sql
-- Line 56 (get_trajectory_tracon_crossings):
-- BEFORE:
FROM tracon_boundaries tb WHERE ST_Intersects(trajectory, tb.geom)

-- AFTER:
FROM (SELECT tracon_code, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) tb
WHERE ST_Intersects(trajectory, tb.geom)

-- Line 144 (get_trajectory_all_crossings, sector_crossings CTE):
-- BEFORE:
FROM sector_boundaries sb WHERE ST_Intersects(trajectory, sb.geom)

-- AFTER:
FROM (SELECT sector_code, sector_type, geom FROM sector_boundaries WHERE ST_IsValid(geom)) sb
WHERE ST_Intersects(trajectory, sb.geom)

-- Line 155 (tracon_crossings CTE):
-- BEFORE:
FROM tracon_boundaries tb WHERE ST_Intersects(trajectory, tb.geom)

-- AFTER:
FROM (SELECT tracon_code, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) tb
WHERE ST_Intersects(trajectory, tb.geom)
```

Note: Line 133 (artcc_crossings CTE) is left unchanged — all 1004 ARTCC boundaries are valid.

**Step 3: Commit**

```bash
git add scripts/postgis/008_trajectory_crossings.sql scripts/postgis/009_tracon_crossings.sql
git commit -m "fix: add ST_IsValid pre-filter to trajectory crossing functions

Protects live crossing daemon from invalid TRACON/sector geometries."
```

---

## Task 8: Create Shared PHP `ArtccNormalizer` Class (Layer 3)

**Files:**
- Create: `lib/ArtccNormalizer.php`

**Step 1: Create the class**

```php
<?php
namespace PERTI\Lib;

/**
 * Canonical ARTCC code normalization.
 *
 * Handles three categories:
 * 1. K-prefix stripping: KZNY → ZNY (US ARTCCs in ICAO format)
 * 2. Canadian 3→4 letter: CZE → CZEG (abbreviated FIR codes)
 * 3. ICAO→FAA mappings: PAZA → ZAN, KZAK → ZAK, etc.
 */
class ArtccNormalizer
{
    private const ALIASES = [
        'CZE'  => 'CZEG', 'CZU'  => 'CZUL', 'CZV'  => 'CZVR',
        'CZW'  => 'CZWG', 'CZY'  => 'CZYZ', 'CZM'  => 'CZQM',
        'CZQ'  => 'CZQX', 'CZO'  => 'CZQO', 'CZX'  => 'CZQX',
        'PAZA' => 'ZAN',  'KZAK' => 'ZAK',  'KZWY' => 'ZWY',
        'PGZU' => 'ZUA',  'PAZN' => 'ZAP',  'PHZH' => 'ZHN',
    ];

    private const PSEUDO_FIXES = ['UNKN', 'VARIOUS'];

    /**
     * Normalize a single ARTCC code.
     * Idempotent: normalize(normalize(x)) === normalize(x).
     */
    public static function normalize(string $code): string
    {
        $upper = strtoupper(trim($code));
        if ($upper === '') return $upper;
        if (isset(self::ALIASES[$upper])) {
            return self::ALIASES[$upper];
        }
        if (preg_match('/^KZ[A-Z]{2}$/', $upper)) {
            return substr($upper, 1);
        }
        return $upper;
    }

    /**
     * Normalize a comma-separated list of ARTCC codes.
     * Filters out empty strings and pseudo-fixes (UNKN, VARIOUS).
     * Returns deduplicated, comma-separated string.
     */
    public static function normalizeCsv(string $csv): string
    {
        if (trim($csv) === '') return '';
        $codes = array_map('trim', explode(',', $csv));
        $codes = array_filter($codes, function ($c) {
            $u = strtoupper(trim($c));
            return $u !== '' && !in_array($u, self::PSEUDO_FIXES, true);
        });
        $codes = array_map([self::class, 'normalize'], $codes);
        return implode(',', array_unique($codes));
    }

    /**
     * Normalize an array of ARTCC codes.
     * Filters pseudo-fixes and deduplicates.
     */
    public static function normalizeArray(array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $normalized = self::normalize($code);
            if ($normalized !== '' && !in_array(strtoupper($normalized), self::PSEUDO_FIXES, true)) {
                $result[] = $normalized;
            }
        }
        return array_values(array_unique($result));
    }
}
```

**Step 2: Commit**

```bash
git add lib/ArtccNormalizer.php
git commit -m "feat: create shared ArtccNormalizer class

Canonical ARTCC normalization replacing 7 copy-pasted functions.
Handles K-prefix, Canadian 3→4, PAZA→ZAN, pseudo-fix filtering."
```

---

## Task 9: Replace Inline Functions in `playbook_helpers.php` + Fix D8 (Layer 3)

**Files:**
- Modify: `api/mgt/playbook/playbook_helpers.php:16-31,224-233`

**Step 1: Add require and replace function definitions**

At the top of the file (after the opening `<?php` and any existing requires), add:

```php
require_once __DIR__ . '/../../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;
```

Remove the `normalizeCanadianArtcc()` function definition (lines 16-26) and `normalizeCanadianArtccCsv()` function (lines 28-31). Replace all call sites:

- Every `normalizeCanadianArtcc($code)` → `ArtccNormalizer::normalize($code)`
- Every `normalizeCanadianArtccCsv($csv)` → `ArtccNormalizer::normalizeCsv($csv)`

**Step 2: Fix D8 — normalize origin/dest ARTCCs before merging**

Replace lines 224-233 with:

```php
    // Merge origin ARTCCs BEFORE GIS results, dest ARTCCs AFTER.
    // array_unique() preserves first occurrence, so insertion order matters:
    // origin -> GIS spatial -> destination gives correct traversal ordering.
    $origin_list = [];
    foreach (explode(',', $origin_artccs) as $a) {
        $a = ArtccNormalizer::normalize($a);
        if ($a !== '' && $a !== 'UNKN' && $a !== 'VARIOUS') $origin_list[] = $a;
    }
    $dest_list = [];
    foreach (explode(',', $dest_artccs) as $a) {
        $a = ArtccNormalizer::normalize($a);
        if ($a !== '' && $a !== 'UNKN' && $a !== 'VARIOUS') $dest_list[] = $a;
    }
    $artccs = array_merge($origin_list, $artccs, $dest_list);
```

Key changes:
1. `ArtccNormalizer::normalize($a)` applied to each origin/dest ARTCC (was missing — D8 fix)
2. Added `VARIOUS` filter (was only filtering `UNKN`)

**Step 3: Commit**

```bash
git add api/mgt/playbook/playbook_helpers.php
git commit -m "fix: normalize origin/dest ARTCCs in computeTraversedFacilities (D8)

Origin/dest ARTCCs were not normalized before merging with GIS results,
causing duplicates like CZE + CZEG. Now uses shared ArtccNormalizer.
Also adds VARIOUS pseudo-fix filter for consistency."
```

---

## Task 10: Replace Inline Functions in Remaining PHP Files (Layer 3)

**Files:**
- Modify: `scripts/playbook/backfill_geometry.php:83-115`
- Modify: `scripts/playbook/recompute_traversed.php:95-117`
- Modify: `scripts/playbook/import_faa_to_db.php`
- Modify: `scripts/playbook/import_historical_to_db.php`
- Modify: `scripts/playbook/import_cadena_pasa.php`
- Modify: `scripts/refdata_sync_daemon.php`

**Step 1: For each file, add the require and replace function calls**

Add to each file (adjusting relative path as needed):

```php
require_once __DIR__ . '/../../lib/ArtccNormalizer.php';  // adjust path per file
use PERTI\Lib\ArtccNormalizer;
```

Then:
- Delete the inline `normalizeCanadianArtcc()` / `normalizeArtcc()` function definition
- Delete the inline `normalizeCanadianArtccCsv()` / `normalizeArtccCsv()` function definition if present
- Replace all `normalizeCanadianArtcc($code)` → `ArtccNormalizer::normalize($code)`
- Replace all `normalizeCanadianArtccCsv($csv)` → `ArtccNormalizer::normalizeCsv($csv)`

**Require paths by file:**

| File | Relative path to `lib/` |
|------|------------------------|
| `scripts/playbook/backfill_geometry.php` | `__DIR__ . '/../../lib/ArtccNormalizer.php'` |
| `scripts/playbook/recompute_traversed.php` | `__DIR__ . '/../../lib/ArtccNormalizer.php'` |
| `scripts/playbook/import_faa_to_db.php` | `__DIR__ . '/../../lib/ArtccNormalizer.php'` |
| `scripts/playbook/import_historical_to_db.php` | `__DIR__ . '/../../lib/ArtccNormalizer.php'` |
| `scripts/playbook/import_cadena_pasa.php` | `__DIR__ . '/../../lib/ArtccNormalizer.php'` |
| `scripts/refdata_sync_daemon.php` | `__DIR__ . '/../lib/ArtccNormalizer.php'` |

**Step 2: Commit**

```bash
git add scripts/playbook/backfill_geometry.php scripts/playbook/recompute_traversed.php \
    scripts/playbook/import_faa_to_db.php scripts/playbook/import_historical_to_db.php \
    scripts/playbook/import_cadena_pasa.php scripts/refdata_sync_daemon.php
git commit -m "refactor: replace 6 inline ARTCC normalizers with shared ArtccNormalizer

Eliminates code duplication. All files now use lib/ArtccNormalizer.php."
```

---

## Task 11: Fix `analysis.php` ARTCC Output (Layer 3)

**Files:**
- Modify: `api/data/playbook/analysis.php:387`

**Step 1: Add require and normalize facility_id**

Add at top of file (after includes):

```php
require_once __DIR__ . '/../../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;
```

At line 387, where facility_id is mapped to the response, apply normalization for ARTCC/FIR types:

```php
// BEFORE (line 387):
'id'               => $t['facility_id'],

// AFTER:
'id'               => (in_array($t['facility_type'], ['ARTCC', 'FIR']))
                        ? ArtccNormalizer::normalize($t['facility_id'])
                        : $t['facility_id'],
```

This is a belt-and-suspenders fix — PostGIS now normalizes in `analyze_route_traversal()` (Task 3), but this ensures the PHP layer also normalizes in case the migration hasn't been applied yet.

**Step 2: Commit**

```bash
git add api/data/playbook/analysis.php
git commit -m "fix: normalize ARTCC codes in analysis API output (D3)

Analysis API was returning raw KZNY codes from PostGIS.
Now applies ArtccNormalizer for ARTCC/FIR facility types."
```

---

## Task 12: Fix `GISService.php` Partial Normalization (Layer 3)

**Files:**
- Modify: `load/services/GISService.php:1108-1117`

**Step 1: Replace `cleanArtccCodes()` with ArtccNormalizer**

Add at top of file:

```php
require_once __DIR__ . '/../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;
```

Replace the `cleanArtccCodes()` method (lines 1108-1117):

```php
// BEFORE:
private function cleanArtccCodes(array $artccs): array
{
    return array_map(function($a) {
        // KZFW -> ZFW
        if (strlen($a) === 4 && substr($a, 0, 1) === 'K') {
            return substr($a, 1);
        }
        return $a;
    }, $artccs);
}

// AFTER:
private function cleanArtccCodes(array $artccs): array
{
    return ArtccNormalizer::normalizeArray($artccs);
}
```

This fixes two bugs:
1. Now handles Canadian codes (CZE→CZEG) and PAZA→ZAN (was K-prefix only)
2. No longer incorrectly strips K from non-ARTCC codes like KJFK (old code matched any 4-char K-initial string)

**Step 2: Commit**

```bash
git add load/services/GISService.php
git commit -m "fix: replace partial ARTCC normalization in GISService (D3)

cleanArtccCodes() only stripped K-prefix (and too broadly).
Now uses ArtccNormalizer for full normalization including
Canadian and PAZA codes."
```

---

## Task 13: Fix `swim/.../plays.php` Partial Normalization (Layer 3)

**Files:**
- Modify: `api/swim/v1/playbook/plays.php:684-698`

**Step 1: Replace `normalizeArtccAlias()` with ArtccNormalizer**

Add at top of file:

```php
require_once __DIR__ . '/../../../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;
```

Replace the `normalizeArtccAlias()` function (lines 684-698):

```php
// BEFORE:
function normalizeArtccAlias(string $code): string {
    static $aliases = [
        'CZE' => 'CZEG', 'CZU' => 'CZUL', ...
        // 24 aliases but NO generic K-prefix stripping
    ];
    return $aliases[$code] ?? $code;
}

// AFTER:
function normalizeArtccAlias(string $code): string {
    return ArtccNormalizer::normalize($code);
}
```

Note: The SWIM plays.php had extra aliases (Mexican FIRs: ZMX→MMMX, ZMT→MMTY, etc. and Caribbean: ZSU→TJZS). These are SWIM-specific international mappings. If these are needed, keep them as a local extension:

```php
function normalizeArtccAlias(string $code): string {
    // SWIM-specific international aliases not in the shared normalizer
    static $swim_extras = [
        'ZMX' => 'MMMX', 'ZMT' => 'MMTY', 'ZMZ' => 'MMZT',
        'ZMR' => 'MMMD', 'ZMC' => 'MMUN', 'ZSU' => 'TJZS',
    ];
    $normalized = ArtccNormalizer::normalize($code);
    return $swim_extras[$normalized] ?? $normalized;
}
```

**Step 2: Commit**

```bash
git add api/swim/v1/playbook/plays.php
git commit -m "fix: add generic K-prefix stripping to SWIM playbook API (D3)

normalizeArtccAlias() had 24 specific aliases but no generic KZ**
stripping. Now uses shared ArtccNormalizer + SWIM-specific extras."
```

---

## Task 14: Verify All Changes Compile

**Step 1: PHP syntax check on all modified files**

```bash
cd C:/Temp/perti-worktrees/pipeline-alignment

php -l lib/ArtccNormalizer.php
php -l api/mgt/playbook/playbook_helpers.php
php -l api/data/playbook/analysis.php
php -l load/services/GISService.php
php -l api/swim/v1/playbook/plays.php
php -l scripts/playbook/backfill_geometry.php
php -l scripts/playbook/recompute_traversed.php
php -l scripts/playbook/import_faa_to_db.php
php -l scripts/playbook/import_historical_to_db.php
php -l scripts/playbook/import_cadena_pasa.php
php -l scripts/refdata_sync_daemon.php
```

Expected: `No syntax errors detected` for each file.

**Step 2: Verify SQL migration syntax**

```bash
# Check for basic SQL syntax issues (no runtime validation without DB connection)
cat database/migrations/postgis/017_normalize_artcc_function.sql | head -5
```

**Step 3: Commit any fixes if needed**

---

## Task 15: Write Inline Verification Script

**Files:**
- Create: `scripts/verify_artcc_normalizer.php` (temporary, for manual testing)

**Step 1: Write the script**

```php
<?php
/**
 * Verify ArtccNormalizer works correctly.
 * Run: php scripts/verify_artcc_normalizer.php
 */
require_once __DIR__ . '/../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

$tests = [
    // [input, expected]
    ['KZNY', 'ZNY'],
    ['KZFW', 'ZFW'],
    ['KZLA', 'ZLA'],
    ['ZBW', 'ZBW'],        // Already normalized
    ['ZNY', 'ZNY'],        // Already normalized
    ['CZE', 'CZEG'],       // Canadian 3→4
    ['CZU', 'CZUL'],
    ['CZV', 'CZVR'],
    ['CZW', 'CZWG'],
    ['CZY', 'CZYZ'],
    ['CZEG', 'CZEG'],      // Already expanded
    ['PAZA', 'ZAN'],        // Alaska
    ['KZAK', 'ZAK'],       // Oceanic
    ['PGZU', 'ZUA'],
    ['KJFK', 'KJFK'],      // NOT an ARTCC — should NOT strip K
    ['KORD', 'KORD'],       // NOT an ARTCC — should NOT strip K
    ['', ''],               // Empty
    ['  ZNY  ', 'ZNY'],    // Whitespace trimming
];

$csv_tests = [
    ['ZNY,KZFW,CZE,UNKN,VARIOUS', 'ZNY,ZFW,CZEG'],
    ['', ''],
    ['UNKN', ''],
    ['ZBW,ZNY', 'ZBW,ZNY'],
];

echo "=== Single code tests ===\n";
$pass = 0; $fail = 0;
foreach ($tests as [$input, $expected]) {
    $result = ArtccNormalizer::normalize($input);
    $ok = $result === $expected;
    if ($ok) { $pass++; } else { $fail++; }
    printf("  %s: normalize('%s') = '%s' (expected '%s')\n",
        $ok ? 'PASS' : '** FAIL **', $input, $result, $expected);
}

echo "\n=== CSV tests ===\n";
foreach ($csv_tests as [$input, $expected]) {
    $result = ArtccNormalizer::normalizeCsv($input);
    $ok = $result === $expected;
    if ($ok) { $pass++; } else { $fail++; }
    printf("  %s: normalizeCsv('%s') = '%s' (expected '%s')\n",
        $ok ? 'PASS' : '** FAIL **', $input, $result, $expected);
}

echo "\n=== Idempotency test ===\n";
foreach (['ZNY', 'CZEG', 'ZAN', 'ZBW'] as $code) {
    $r1 = ArtccNormalizer::normalize($code);
    $r2 = ArtccNormalizer::normalize($r1);
    $ok = $r1 === $r2;
    if ($ok) { $pass++; } else { $fail++; }
    printf("  %s: normalize(normalize('%s')) == normalize('%s')\n",
        $ok ? 'PASS' : '** FAIL **', $code, $code);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
```

**Step 2: Run the verification**

```bash
php scripts/verify_artcc_normalizer.php
```

Expected: All tests pass.

**Step 3: Commit**

```bash
git add scripts/verify_artcc_normalizer.php
git commit -m "test: add ArtccNormalizer verification script

Manual test for normalize(), normalizeCsv(), and idempotency."
```

---

## Deployment Order

After all tasks are committed to `feature/pipeline-alignment`:

1. **Deploy PostGIS migration 017** (Task 1) — creates `normalize_artcc_code()` function
2. **Deploy PostGIS function updates** (Tasks 2-3) — applies normalization in `expand_route_with_artccs()` and `analyze_route_traversal()`
3. **Merge feature branch to main** — deploys all PHP changes via GitHub Actions
4. **Verify** — save a playbook route, check analysis API, monitor crossing daemon logs

PostGIS migrations must be applied manually via `psql` or the migration runner. PHP changes deploy automatically on merge.
