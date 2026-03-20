# Route Pipeline Alignment — Design Document

## Goal

Eliminate ARTCC normalization inconsistencies, boundary filtering gaps, and code duplication across all route processing pipelines to ensure coherent, accurate facility traversal results regardless of which pipeline processes a route.

## Background

The [route pipeline divergence audit](../superpowers/specs/2026-03-19-route-pipeline-divergence-audit.md) identified 8 divergences (D1-D8) across 7 route resolution pipelines. This design addresses divergences D3, D5, and D8 — the normalization and boundary filtering issues that cause incorrect or inconsistent ARTCC results in production today.

### Divergences Addressed

| ID | Problem | Severity |
|----|---------|----------|
| D3 | ARTCC code normalization inconsistent across pipelines (K-prefix, Canadian 3→4, PAZA→ZAN) | HIGH |
| D5 | No ST_IsValid pre-filter on TRACON/sector boundary queries in 5 high-risk files | HIGH |
| D8 | `computeTraversedFacilities()` normalizes GIS results but not origin/dest ARTCCs | MEDIUM |

### Divergences NOT Addressed (separate workstreams)

| ID | Problem | Notes |
|----|---------|-------|
| D1 | PostGIS airway data incomplete vs awys.csv | Being addressed via AIRAC pipeline fix (separate session) |
| D2 | No SID/STAR expansion server-side | Feature gap, not a bug — future workstream |
| D4 | analysis.php duplicate endpoint injection | Low severity |
| D6 | Different fix databases (points.csv vs nav_fixes) | Validated: only 2 missing fixes, not actionable |
| D7 | Densification difference | Low severity, correct behavior for different use cases |

---

## Architecture

Three layers of fixes, applied bottom-up so each layer benefits the ones above:

```
Layer 3: PHP Centralization
  lib/ArtccNormalizer.php replaces 7 copy-pasted functions
  Fix playbook_helpers.php origin/dest normalization (D8)
  Fix analysis.php output normalization (D3)
  Fix GISService.php partial normalization (D3)
  Fix swim plays.php partial normalization (D3)

Layer 2: PostGIS Normalization Function
  normalize_artcc_code() applied inside expand_route_with_artccs()
  and analyze_route_traversal() so all callers get clean codes

Layer 1: ST_IsValid Boundary Protection
  Add ST_IsValid pre-filter to 5 high-risk files
  Protects against 108 invalid TRACONs + 265 invalid sectors
```

---

## Layer 1: ST_IsValid Boundary Protection

### Problem

108 TRACON boundaries and 265 sector boundaries have invalid geometries (unclosed LinearRings). Any `ST_Intersects()` call against these can crash with "Geometry could not be converted to GEOS". Five files with live/daemon code paths lack protection.

### Fix Strategy

Use the **subquery pre-filter pattern** (proven in `backfill_geometry.php`):

```sql
-- Before (crashes on invalid geometries):
FROM tracon_boundaries tb
WHERE ST_Intersects(route_geom, tb.geom)

-- After (pre-filters invalid geometries):
FROM (SELECT facility_id, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) tb
WHERE ST_Intersects(route_geom, tb.geom)
```

Why subquery over `ST_MakeValid()`: The subquery approach is proven in production (`backfill_geometry.php`), is more explicit about skipping bad data, and avoids the per-row overhead of repairing geometries that might produce unpredictable results. `ST_MakeValid()` is used in `analyze_route_traversal()` and works fine there — we keep that as-is rather than changing a working function.

### Files to Fix

| File | Lines | Boundary Tables | Context |
|------|-------|-----------------|---------|
| `api/mgt/playbook/playbook_helpers.php` | 164, 169 | TRACON, sector | Live playbook save endpoint |
| `scripts/playbook/recompute_traversed.php` | 70, 75 | TRACON, sector | Batch recompute script |
| `scripts/postgis/005_batch_route_functions.sql` | 174, 185, 196, 207 | sector (x3), TRACON | Batch expand function |
| `scripts/postgis/008_trajectory_crossings.sql` | 184, 274 | sector (x2) | Live crossing daemon |
| `scripts/postgis/009_tracon_crossings.sql` | 56, 144, 155 | TRACON, sector, TRACON | Live crossing daemon |

**Total: 12 ST_Intersects calls to fix across 5 files.**

Already protected (no changes needed):
- `backfill_geometry.php` — subquery pre-filter
- `route_analysis_function.sql` — ST_MakeValid wrapping
- ARTCC boundary queries — all 1004 boundaries are valid

---

## Layer 2: PostGIS Normalization Function

### Problem

`expand_route_with_artccs()` and `analyze_route_traversal()` return raw `artcc_code` values from the `artcc_boundaries` table (e.g., `KZNY`, `CZE`, `PAZA`). Every PHP caller must remember to normalize, and two callers (`analysis.php`, `GISService.php`) don't.

### Fix

Create a PostGIS function and apply it at the return point of both functions:

```sql
CREATE OR REPLACE FUNCTION normalize_artcc_code(code TEXT)
RETURNS TEXT LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    v_upper TEXT := upper(trim(code));
    v_map   JSONB := '{
        "CZE":"CZEG", "CZU":"CZUL", "CZV":"CZVR",
        "CZW":"CZWG", "CZY":"CZYZ", "CZQ":"CZQX",
        "CZM":"CZQM", "CZX":"CZQX",
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
```

Applied in:
- `expand_route_with_artccs()`: wrap `artcc_code` in the ARTCC traversal query (~line 820)
- `analyze_route_traversal()`: wrap `ab.artcc_code` in the facility_id output (~line 66)

This is **idempotent** — callers that already normalize in PHP will get the same result.

### Migration File

`database/migrations/postgis/017_normalize_artcc_function.sql`

---

## Layer 3: PHP Centralization

### Problem

The `normalizeCanadianArtcc()` function is copy-pasted in 7 PHP files with minor variations. Some files filter `UNKN` only, others also filter `VARIOUS`. `GISService.php` only strips K-prefix (no Canadian/PAZA). `swim/.../plays.php` handles specific codes but not generic K-prefix.

### Fix

Create `lib/ArtccNormalizer.php`:

```php
<?php
namespace PERTI\Lib;

class ArtccNormalizer
{
    private const ALIASES = [
        'CZE'  => 'CZEG', 'CZU'  => 'CZUL', 'CZV'  => 'CZVR',
        'CZW'  => 'CZWG', 'CZY'  => 'CZYZ', 'CZQ'  => 'CZQX',
        'CZM'  => 'CZQM', 'CZX'  => 'CZQX',
        'PAZA' => 'ZAN',  'KZAK' => 'ZAK',  'KZWY' => 'ZWY',
        'PGZU' => 'ZUA',  'PAZN' => 'ZAP',  'PHZH' => 'ZHN',
    ];

    private const PSEUDO_FIXES = ['UNKN', 'VARIOUS'];

    public static function normalize(string $code): string
    {
        $upper = strtoupper(trim($code));
        if (isset(self::ALIASES[$upper])) {
            return self::ALIASES[$upper];
        }
        if (preg_match('/^KZ[A-Z]{2}$/', $upper)) {
            return substr($upper, 1);
        }
        return $upper;
    }

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
}
```

### Files to Update

| File | Current Function | Change |
|------|-----------------|--------|
| `api/mgt/playbook/playbook_helpers.php` | `normalizeCanadianArtcc()` (lines 8-30) | Replace with `ArtccNormalizer::normalize()`. Add normalization to origin/dest ARTCCs (lines 225-233). Add `VARIOUS` filter. |
| `scripts/playbook/backfill_geometry.php` | `normalizeCanadianArtcc()` (lines 83-103) | Replace with `ArtccNormalizer` |
| `scripts/playbook/recompute_traversed.php` | `normalizeCanadianArtcc()` (lines 88-108) | Replace with `ArtccNormalizer` |
| `scripts/playbook/import_faa_to_db.php` | `normalizeCanadianArtcc()` | Replace with `ArtccNormalizer` |
| `scripts/playbook/import_historical_to_db.php` | `normalizeCanadianArtcc()` | Replace with `ArtccNormalizer` |
| `scripts/playbook/import_cadena_pasa.php` | `normalizeCanadianArtcc()` | Replace with `ArtccNormalizer` |
| `scripts/refdata_sync_daemon.php` | `normalizeArtcc()` | Replace with `ArtccNormalizer` |
| `api/data/playbook/analysis.php` | None | Add `ArtccNormalizer::normalize()` to facility_id output |
| `load/services/GISService.php` | `cleanArtccCodes()` (K-only) | Replace with `ArtccNormalizer::normalize()` |
| `api/swim/v1/playbook/plays.php` | `normalizeArtccAlias()` (partial) | Replace with `ArtccNormalizer::normalize()` |

**Total: 10 files updated, 7 function copies eliminated.**

---

## Testing Strategy

Since PERTI has no automated test suite, validation is manual:

### Layer 1 (ST_IsValid)
- Deploy to production
- Run `backfill_geometry.php` status check to confirm it still works
- Save a playbook route through the UI to confirm `computeTraversedFacilities()` works
- Monitor crossing daemon logs for errors

### Layer 2 (PostGIS normalization)
- Deploy migration 017 to PostGIS
- Run `SELECT normalize_artcc_code('KZNY'), normalize_artcc_code('CZE'), normalize_artcc_code('PAZA'), normalize_artcc_code('ZBW')` — expect `ZNY, CZEG, ZAN, ZBW`
- Call `expand_route_with_artccs()` with a route crossing Canadian airspace — verify codes are normalized
- Call `analyze_route_traversal()` — verify `facility_id` values are normalized

### Layer 3 (PHP centralization)
- Save a playbook route with Canadian origin/dest ARTCCs — verify no duplicates (`CZE` + `CZEG`)
- Hit `analysis.php` Mode 2 API — verify `facility_id` returns `ZNY` not `KZNY`
- Check SWIM playbook API with `?artcc=KZFW` — verify it matches routes

---

## Risk Assessment

| Change | Risk | Mitigation |
|--------|------|------------|
| ST_IsValid pre-filters | LOW — skips bad boundaries (same as backfill_geometry.php already does) | Only affects TRACON/sector, not ARTCC. Invalid boundaries were already producing errors or being skipped. |
| PostGIS normalize function | LOW — idempotent, PHP callers that already normalize will get same result | Deploy function first, then update callers. Function can exist without breaking anything. |
| PHP centralization | MEDIUM — touching 10 files | Feature branch isolates changes. Each file can be tested independently. Normalization is idempotent so double-normalization is safe. |
| Removing inline functions | LOW — replaced by equivalent shared class | Keep old functions as deprecated aliases for one release cycle if needed (probably not needed since we deploy continuously). |

---

## Scope Exclusions

- **JS normalization**: `PERTI.normalizeArtcc()` in `perti.js` is already the superset implementation and is loaded on all pages. The fallback paths in `route-maplibre.js`/`nod.js`/`tmi_compliance.js` are edge cases (only hit if `perti.js` fails to load). Not worth the risk to change.
- **Boundary data cleanup**: The 108 invalid TRACON + 265 invalid sector boundaries should be fixed at the source (ST_MakeValid UPDATE), but that's a separate data quality task, not a code change.
- **`artcc_boundaries` table normalization**: We could UPDATE the table to strip K-prefixes, but that would break any code expecting K-prefixed codes (e.g., GeoJSON boundary files). The PostGIS function approach is safer.
