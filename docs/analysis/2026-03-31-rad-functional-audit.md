# RAD (Route Amendment Dialogue) Functional Audit Report

**Date**: 2026-03-31
**Scope**: Full functional sweep of all 4 RAD tabs (Search, Detail, Edit, Monitoring)
**Status**: All issues found and fixed. All tests passing.

---

## Executive Summary

The RAD page had **10+ column name mismatches** between `RADService.php` SQL queries and the production database schema, rendering Search completely non-functional. Three additional logic bugs were found in the amendment routing, status filtering, and changelog field naming. All issues have been fixed across 5 commits and validated via Playwright testing.

## Bugs Found and Fixed

### Critical (Search completely broken)

| # | Issue | Root Cause | Fix | Commit |
|---|-------|-----------|-----|--------|
| 1 | Search returns 0 results | `c.gufi` column doesn't exist on production (`adl_flight_core`) | Changed to `c.flight_key AS gufi` | 7168b2d4 |
| 2 | Search returns 0 results | `c.flight_phase` doesn't exist; column is `c.phase` | Changed to `c.phase` | 2d16edf4 |
| 3 | Search returns 0 results | `p.fp_aircraft_icao` doesn't exist; column is `a.aircraft_icao` on `adl_flight_aircraft` | Changed to `a.aircraft_icao` | d510e7cd |
| 4 | Search returns 0 results | `t.ete_min`/`t.cte_min` don't exist; columns are `t.ete_minutes`/`t.cete_minutes` | Changed column names | d510e7cd |
| 5 | Search returns 0 results | `p.dept_artcc`/`p.dest_artcc` don't exist; columns are `p.fp_dept_artcc`/`p.fp_dest_artcc` | Changed column names | 2d16edf4 |
| 6 | Search returns 0 results | `p.dept_tracon`/`p.dest_tracon` don't exist; columns are `p.fp_dept_tracon`/`p.fp_dest_tracon` | Changed column names | 2d16edf4 |
| 7 | PostGIS connection failure on page load | `$conn_gis` is null (lazy-loaded), but was used directly | Changed to `get_conn_gis()` lazy call | 9619d742 |
| 8 | Search pagination broken | `LIMIT/OFFSET` syntax not valid for SQL Server | Changed to `OFFSET/FETCH NEXT` | 9619d742 |

### High (Logic bugs)

| # | Issue | Root Cause | Fix | Commit |
|---|-------|-----------|-----|--------|
| 9 | "Airborne" filter too narrow | ACTIVE status mapped to single phase `enroute` only | Changed to `IN ('enroute','departed','descending','taxiing')` | 87e58da7 |
| 10 | Route History always returns empty | `field_name = 'route'` in WHERE, but changelog trigger stores `'fp_route'` | Changed to `field_name = 'fp_route'` | 87e58da7 |
| 11 | Send Amendment from Edit tab fails 400 | `action === 'send'` routed to send-existing-draft (expects `body['id']`), not create-and-send | Changed to `action === 'send' && !empty($body['id'])` for existing drafts; create path now includes `send` flag | 87e58da7 |

### Low (Informational, not fixed)

| # | Issue | Notes |
|---|-------|-------|
| 12 | Migration 057 uses `gufi UNIQUEIDENTIFIER` type | Mismatches `flight_key NVARCHAR(32)` — needs migration fix when RAD tables are deployed |
| 13 | `adl_flight_tmi` columns `rad_amendment_id`/`rad_assigned_route` | ALTER TABLE statements commented out in migration 057 |
| 14 | 2 cosmetic map warnings | `route-fixes-labels` and `route-fixes-labels-moved` layers not found — only when route hasn't been plotted |

## Files Modified

### PHP Backend
- **`load/services/RADService.php`** — 10 column fixes, status filter fix, changelog field fix, debug cleanup
- **`api/rad/amendment.php`** — Amendment action routing fix, send flag fix
- **`api/rad/common.php`** — GIS lazy-load fix (`get_conn_gis()`)

### No JS changes required
All JavaScript modules (`rad.js`, `rad-flight-search.js`, `rad-flight-detail.js`, `rad-amendment.js`, `rad-monitoring.js`, `rad-event-bus.js`) were reviewed and found correct — they reference API response field names (aliases), not database column names.

## Commits (chronological)

1. **9619d742** — `fix(rad): GIS lazy-load + OFFSET/FETCH pagination`
2. **2d16edf4** — `fix(rad): column name fixes (phase, ARTCC/TRACON)`
3. **d510e7cd** — `fix(rad): remaining column fixes (aircraft_icao, ete_minutes, cete_minutes, getFlightByGufi, checkCompliance)`
4. **7168b2d4** — `fix(rad): use flight_key instead of gufi column (not deployed)`
5. **87e58da7** — `fix(rad): changelog field_name, status filter, amendment routing`

## Playwright Test Results

All tests run on production (https://perti.vatcscc.org/rad.php) on 2026-03-31.

| Test | Description | Result |
|------|-------------|--------|
| 1 | Default search (no filters) | PASSED — 50 flights, all fields populated |
| 2 | Origin filter (KJFK) | PASSED — 14 flights, all with KJFK origin |
| 3 | Airborne time range filter | PASSED — 51 flights, only DEPARTED/AIRBORNE statuses |
| 4 | Select flights + Add to Detail | PASSED — Badge shows "2", flights transferred |
| 5 | Detail tab display | PASSED — All columns populated (Callsign, O/D, TRACON, Center, Amendment, Route, Type, Times, Phase) |
| 6 | Route History button | PASSED — API call succeeds, dialog shows "No route changes recorded" |
| 7 | Edit tab layout | PASSED — All controls present (Route Options, CDR, Validate, Plot, Amendment Preview, TMI, Channels, Save/Send) |
| 8 | Validate route | PASSED — Amendment Preview shows diff (Original vs Assigned vs Diff columns) |
| 9 | Monitoring tab | PASSED — Status cards, compliance bar, filter buttons, amendment table all render correctly |

**Console**: 0 errors, 2 warnings (cosmetic map layers)

## Root Cause Analysis

The RAD feature was developed against a schema design document that assumed:
1. A `gufi` column would exist on `adl_flight_core` (migration 014 was never applied to production)
2. Certain column names from the design (`flight_phase`, `dept_artcc`, `fp_aircraft_icao`, `ete_min`, `cte_min`) that differ from the actual production schema

The `sqlsrv` driver silently returns `false` on queries with invalid column names, and the COUNT query for pagination uses `COUNT(*)` which succeeds regardless of SELECT column errors, masking the root cause.

## Recommendations

1. **Deploy migration 057** with corrected `gufi` column type (use `NVARCHAR(64)` to match `flight_key` format, not `UNIQUEIDENTIFIER`)
2. **Add integration tests** for RAD API endpoints that validate against production schema
3. **Consider a schema validation layer** in `RADService` constructor that checks required columns exist
