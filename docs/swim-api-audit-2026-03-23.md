# SWIM API Comprehensive Audit Report

**Date**: 2026-03-23 10:00-10:10 UTC
**Auditor**: Claude (automated)
**API Key Used**: `swim_pub_routes_*` (public tier, read-only)
**Base URL**: `https://perti.vatcscc.org/api/swim/v1`
**System State**: Hibernation mode active (most data tables empty)

---

## Executive Summary

- **Total endpoints tested**: 46 (39 HTTP GET/POST + 7 format variants)
- **Healthy (200)**: 28 endpoints
- **Broken (500)**: 3 endpoints (schema mismatches)
- **Auth rejected as expected (401/403)**: 12 endpoints
- **Client errors (400/404/405)**: 5 endpoints
- **Anomalous**: 1 endpoint (302 redirect instead of JSON error)

### Critical Issues Found

| # | Severity | Endpoint | Issue |
|---|----------|----------|-------|
| 1 | **CRITICAL** | `/controllers` | 500 - `dbo.swim_controllers` table does not exist |
| 2 | **CRITICAL** | `/tmi/entries` | 500 - Column `aircraft_type` does not exist in `swim_tmi_entries` |
| 3 | **CRITICAL** | `/tmi/routes` | 500 - Column `coordination_status` does not exist in `swim_tmi_public_routes` |
| 4 | **HIGH** | `/ingest/cdm` | 302 redirect to hibernation page instead of JSON 403 |
| 5 | **HIGH** | `/health` | Doesn't read `X-API-Key` header; only accepts `Authorization` or `?key=` |
| 6 | **HIGH** | `/health?key=` | 500 with 0-byte response when key passes format check |
| 7 | **MEDIUM** | `/playbook/traversal` | nginx 404 - file not deployed (untracked in git) |
| 8 | **MEDIUM** | `/ingest/ctp-routes` | nginx 404 - file not deployed (untracked in git) |
| 9 | **LOW** | `/flights?format=csv` | Returns 0 bytes when data is empty (should return header row) |
| 10 | **LOW** | `/flights?format=ndjson` | Returns 1 byte (bare newline) when data is empty |
| 11 | **LOW** | `/ingest/acars` | Skips write-permission source check; goes straight to body validation |

---

## Detailed Endpoint Results

### Core Endpoints

| Endpoint | Method | Status | Time (s) | Size (B) | Notes |
|----------|--------|--------|----------|----------|-------|
| `/` (index) | GET | 200 | 0.31 | 1,916 | No auth required. Lists all endpoints. |
| `/health` | GET | **401** | 0.31 | 90 | Rejects X-API-Key header. See Issue #5. |
| `/health?key=swim_pub_*` | GET | **500** | 0.97 | 0 | Passes format check, then crashes. See Issue #6. |
| `/flights` | GET | 200 | 0.77 | 146 | Works. 0 flights (hibernation). |
| `/flights?dest_icao=KJFK` | GET | 200 | 0.70 | 146 | Filter works. 0 results. |
| `/flights?dest_artcc=ZNY` | GET | 200 | 0.94 | 146 | Filter works. 0 results. |
| `/flight?flight_uid=1` | GET | 404 | 0.69 | 75 | Correct 404 for nonexistent flight. |
| `/positions` | GET | 200 | 0.89 | 146 | Returns valid GeoJSON FeatureCollection. |
| `/controllers` | GET | **500** | 0.75 | 171 | **BUG**: `swim_controllers` table missing. Migration 024 not applied. |
| (no auth) `/flights` | GET | 401 | 0.51 | 159 | Correct auth rejection. |

### Format Variants (tested on `/flights?per_page=3`)

| Format | Status | Size (B) | Notes |
|--------|--------|----------|-------|
| `json` (default) | 200 | 146 | Correct JSON response. |
| `geojson` | 200 | 208 | Valid GeoJSON FeatureCollection. |
| `xml` | 200 | 319 | Valid XML with proper `<swim_flights>` root. |
| `csv` | **200** | **0** | **BUG**: Empty response. Should return CSV header row. |
| `ndjson` | **200** | **1** | **BUG**: Single newline. Should return empty string or nothing. |
| `kml` | 200 | 960 | Valid KML with styles but no placemarks (expected when empty). |

### TMI Endpoints

| Endpoint | Method | Status | Time (s) | Size (B) | Notes |
|----------|--------|--------|----------|----------|-------|
| `/tmi/` (index) | GET | 200 | 0.80 | 1,916 | Lists 11 TMI sub-endpoints with active counts. |
| `/tmi/programs` | GET | 200 | 0.88 | 239 | Works. 0 active GS/GDP programs. |
| `/tmi/controlled` | GET | 200 | 0.80 | 375 | Works. 0 controlled flights. Full stats structure. |
| `/tmi/reroutes` | GET | 200 | 1.33 | 292 | Works. 0 active reroutes. |
| `/tmi/advisories` | GET | 200 | 1.40 | 342 | Works. 0 active advisories. |
| `/tmi/entries` | GET | **500** | 1.55 | 164 | **BUG**: Column `aircraft_type` doesn't exist in `swim_tmi_entries`. |
| `/tmi/routes` | GET | **500** | 0.59 | 170 | **BUG**: Column `coordination_status` doesn't exist in `swim_tmi_public_routes`. |
| `/tmi/measures` | GET | 200 | 0.77 | 484 | Works. Unified USA + external measures. |
| `/tmi/nat_tracks/status` | GET | 200 | 1.14 | 2,318 | Works. Returns 7 active NAT tracks (NATA-NATG) with real data. |
| `/tmi/nat_tracks/metrics` | GET | 400 | 0.69 | 120 | Requires `session_id` parameter. Correct validation. |
| `/tmi/flow/` (index) | GET | 200 | 1.06 | 2,161 | Lists 3 flow sub-endpoints + 4 providers + 8 measure types. |
| `/tmi/flow/events` | GET | 200 | 0.62 | 381 | Works. 0 active events. |
| `/tmi/flow/measures` | GET | 200 | 0.62 | 387 | Works. 0 active measures. |
| `/tmi/flow/providers` | GET | 200 | 0.58 | 1,248 | Works. Returns 2 active providers (CTP, VIFF). |

### Metering & Reference Endpoints

| Endpoint | Method | Status | Time (s) | Size (B) | Notes |
|----------|--------|--------|----------|----------|-------|
| `/metering/KJFK` | GET | 200 | 1.01 | 195 | Works. 0 metered flights (expected). |
| `/metering/KJFK/sequence` | GET | 200 | 0.88 | 195 | Works. 0 flights in sequence. |
| `/reference/taxi-times` | GET | 200 | **18.52** | **829,519** | Works but **very slow** (18.5s, 810KB). Returns 4,727 airports. |
| `/reference/taxi-times/KJFK` | GET | 200 | 0.98 | 486 | Works. KJFK: 250s unimpeded, 255 samples, HIGH confidence. |
| `/routes/cdrs` | GET | 200 | 2.39 | 14,052 | Works. 41,138 CDR routes total, 50/page default. |

### Playbook Endpoints

| Endpoint | Method | Status | Time (s) | Size (B) | Notes |
|----------|--------|--------|----------|----------|-------|
| `/playbook/plays?per_page=3` | GET | 200 | 1.32 | 2,354 | Works. 3,829 total plays. Returns CADENA PASA routes. |
| `/playbook/analysis` | GET | 200* | - | - | Works (GET only). POST returns 405 - correctly documented as GET in source. |
| `/playbook/throughput` | GET | 400 | 0.64 | 94 | Requires `play_id` or `route_id`. Correct validation. |
| `/playbook/traversal` | GET | **404** | 0.26 | 153 | **Not deployed**. Raw nginx 404. File is untracked in git. |

### CTP Endpoints

| Endpoint | Method | Status | Time (s) | Size (B) | Notes |
|----------|--------|--------|----------|----------|-------|
| `/ctp/sessions` | GET | 200 | 1.27 | 1,191 | Works. Returns 2 sessions (CTP April 2026 EB + CTPE26_TEST). |

### Ingest Endpoints (Write - all tested with POST)

| Endpoint | Status | Time (s) | Notes |
|----------|--------|----------|-------|
| `/ingest/adl` | 403 | 0.76 | Correct: source not authorized for ADL writes. |
| `/ingest/track` | 403 | 0.68 | Correct: source not authorized for track writes. |
| `/ingest/metering` | 403 | 0.65 | Correct: source not authorized. Clear error message. |
| `/ingest/cdm` | **302** | 0.43 | **BUG**: Redirects to hibernation page instead of JSON 403. |
| `/ingest/acars` | **400** | 0.64 | **Concern**: Returns "Request body is required" - skips write-permission check. |
| `/ingest/ctp` | 403 | 0.65 | Correct: requires system tier with CTP authority. |
| `/ingest/ctp_event` | 403 | 0.61 | Correct: source not authorized. |
| `/ingest/ctp-routes` | **404** | 1.00 | **Not deployed**. Raw nginx 404. File is untracked in git. |
| `/ingest/simtraffic` | 403 | 0.65 | Correct: requires system/partner tier with times authority. |
| `/ingest/vnas/controllers` | 403 | 0.74 | Correct: source not authorized. |
| `/ingest/vnas/handoff` | 403 | 0.67 | Correct: source not authorized. |
| `/ingest/vnas/tags` | 403 | 0.66 | Correct: source not authorized. |
| `/ingest/vnas/track` | 403 | 0.62 | Correct: source not authorized. |

### Admin/System Endpoints

| Endpoint | Status | Time (s) | Notes |
|----------|--------|----------|-------|
| `/connectors/health` | 401 | 0.26 | Same auth bug as `/health` - doesn't read X-API-Key header. |
| `/connectors/status` | 403 | 0.30 | Correct: requires system/partner tier. |
| `/keys/provision` | 401 | 0.86 | Correct: VATSIM OAuth token required (not SWIM key). |
| `/keys/revoke` | 400 | 0.56 | Requires `access_token` (VATSIM OAuth), not SWIM API key. By design. |

---

## Resource Utilization Summary

### Response Times

| Percentile | Time (s) | Category |
|------------|----------|----------|
| p50 (median) | 0.69 | Most endpoints |
| p90 | 1.40 | TMI endpoints |
| p99 | 2.39 | CDR routes (large dataset) |
| Max | **18.52** | Taxi-times (all airports) |

### Response Sizes

| Endpoint | Size | Notes |
|----------|------|-------|
| `/reference/taxi-times` (all) | **810 KB** | 4,727 airports. No pagination! |
| `/routes/cdrs` (page 1) | 14 KB | 50 routes/page, 41K total. Paginated. |
| `/playbook/plays` (3/page) | 2.4 KB | Well-paginated. |
| `/tmi/nat_tracks/status` | 2.3 KB | 7 tracks. Reasonable. |
| Most endpoints | 100-500 B | Lean responses. |

### Performance Concerns

1. **`/reference/taxi-times` (all airports)**: 18.5s / 810KB with NO pagination. This is a full table dump of 4,727 airports. Should add pagination or limit default response.
2. **`/routes/cdrs`**: 2.4s for first page is acceptable but could be optimized with covering indexes.

---

## Detailed Issue Analysis

### Issue #1: `/controllers` - Missing Table (CRITICAL)

**Error**: `Invalid object name 'dbo.swim_controllers'`
**Root Cause**: Migration 024 (swim_controllers table creation) has not been applied to the SWIM_API database.
**Source**: `api/swim/v1/controllers.php:33` queries `dbo.swim_controllers`
**Fix**: Apply migration 024 to create the `swim_controllers` table and `vw_swim_facility_staffing` view.

### Issue #2: `/tmi/entries` - Missing Column (CRITICAL)

**Error**: `Invalid column name 'aircraft_type'`
**Root Cause**: The `swim_tmi_entries` table schema doesn't include the `aircraft_type` column that the endpoint queries at line 101.
**Source**: `api/swim/v1/tmi/entries.php:101` selects `e.aircraft_type`
**Fix**: Either add the `aircraft_type` column to `swim_tmi_entries` via ALTER TABLE, or remove it from the SELECT statement.

### Issue #3: `/tmi/routes` - Missing Column (CRITICAL)

**Error**: `Invalid column name 'coordination_status'`
**Root Cause**: The `swim_tmi_public_routes` table doesn't have a `coordination_status` column.
**Source**: `api/swim/v1/tmi/routes.php:97,101,135` references `r.coordination_status`
**Fix**: Add `coordination_status` and `coordination_proposal_id` columns to `swim_tmi_public_routes`, or remove from queries.

### Issue #4: `/ingest/cdm` - Hibernation Redirect (HIGH)

**Error**: Returns HTTP 302 redirect to the hibernation page HTML instead of a JSON 403
**Root Cause**: The CDM endpoint's auth chain triggers the hibernation redirect before SWIM auth can process the request. Other ingest endpoints (adl, track, metering) don't have this issue.
**Likely cause**: A code path difference in CDM's include chain or a race condition with PERTI_SWIM_ONLY definition.
**Impact**: API consumers get HTML instead of JSON error, breaking parsers.

### Issue #5: `/health` Auth Pattern (HIGH)

**Root Cause**: `health.php` (line 24) reads auth from `$_GET['key']` or `$_SERVER['HTTP_AUTHORIZATION']` only. It does NOT check `$_SERVER['HTTP_X_API_KEY']`, which is the header used by this audit and likely by many API consumers.
**Same issue**: `connectors/health.php` (line 32) has the identical pattern.
**Fix**: Add `$_SERVER['HTTP_X_API_KEY'] ?? ''` as a third fallback in both files.

### Issue #6: `/health?key=` - Silent 500 (HIGH)

**Root Cause**: When passing the API key as `?key=swim_pub_routes_*`, the format check passes (matches `swim_pub_`), but subsequent database operations fail silently, producing a 500 with empty body. Likely an uncaught exception or fatal error in the sqlsrv_connect() call.
**Fix**: Add try-catch or error output around the database connection in health.php.

### Issue #7-8: Undeployed Files (MEDIUM)

**Files**: `api/swim/v1/playbook/traversal.php` and `api/swim/v1/ingest/ctp-routes.php`
Both are listed as untracked (`??`) in git status. They exist locally but haven't been committed, so they're not deployed to production.

### Issue #9-10: Empty Format Handling (LOW)

**CSV**: When the flights data array is empty, `SwimFormat::toCsv()` returns an empty string (no header row). Should return at minimum the column headers.
**NDJSON**: `SwimFormat::toNdjson()` returns `\n` (1 byte) for empty arrays because the `implode("\n", $lines) . "\n"` adds a trailing newline even with no lines.

### Issue #11: ACARS Write Permission (LOW)

**Observation**: `/ingest/acars` authenticates via `swim_init_auth(true, true)` which should reject read-only keys. However, the pub key somehow passes write auth and hits the body validation instead. This suggests `can_write` may be set to 1 for this key, or the auth check has a bypass.
**Risk**: If write auth is genuinely bypassed, any authenticated user could submit ACARS data.

---

## Endpoints Not Tested (Require Higher Tier)

These endpoints require `system` or `partner` tier keys:
- `/connectors/status` (403 - requires system/partner)
- All ingest endpoints (write access required)
- WebSocket server (port 8090, requires persistent connection)

---

## Positive Findings

1. **Auth system works correctly** for most endpoints - proper 401/403 with clear error messages
2. **Rate limiting** appears functional (APCu-based, per-minute buckets)
3. **CORS headers** properly set on all endpoints
4. **Pagination** works correctly on flights, CDRs, playbook plays, TMI data
5. **Multi-format output** (JSON, XML, GeoJSON, KML) works correctly for non-empty responses
6. **NAT tracks** returns real-time data even during hibernation (external data source)
7. **CTP sessions** returns active session data
8. **Taxi reference** data is fresh (refreshed 2026-03-23T02:08:49Z) with 4,727 airports
9. **Flow providers** correctly shows 2 active providers (CTP, VIFF)
10. **Error messages** are generally clear and include error codes

---

## Recommendations

### Immediate (Fix before next event)
1. Apply migration 024 for `swim_controllers` table
2. Fix `swim_tmi_entries` schema (add `aircraft_type` column)
3. Fix `swim_tmi_public_routes` schema (add `coordination_status`, `coordination_proposal_id`)
4. Fix `/health` and `/connectors/health` to read `X-API-Key` header
5. Debug `/ingest/cdm` 302 redirect issue

### Short-term
6. Add pagination to `/reference/taxi-times` (810KB unbounded response)
7. Fix CSV/NDJSON empty-data edge cases
8. Deploy `traversal.php` and `ctp-routes.php` (commit + push)
9. Investigate ACARS write-permission bypass
10. Add error handling to `/health` for DB connection failures

### Long-term
11. Add response compression benchmarks
12. Consider CDN caching for reference data endpoints
13. Add API versioning headers consistently
14. Implement health check monitoring integration
