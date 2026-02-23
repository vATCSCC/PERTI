# PERTI Performance Optimization Log

## Overview

Performance audit and optimization work started 2026-02-22/23 to address site-wide slowness. Changes are organized in tiers by impact-to-effort ratio.

Full audit plan: `.claude/plans/unified-puzzling-pie.md`

## Baseline Metrics (Pre-Optimization)

| Metric | Value |
|--------|-------|
| Total JS payload | 4.8MB (unminified, uncompressed) |
| Total CSS payload | 949KB (unminified, uncompressed) |
| Largest JS files | tmi_compliance.js (487KB), tmi-publish.js (472KB), gdt.js (435KB) |
| Largest CSS file | theme.css (397KB) |
| Nginx gzip | NOT enabled |
| PHP OPcache | NOT configured |
| Azure SQL LoginTimeout | NOT set (30s default) |
| Header.php script loading | ALL synchronous (no defer/async) |
| FontAwesome | Loaded TWICE (CSS + Kit JS) |
| Cache-busting | Only 1 file uses filemtime() |
| PHP-FPM workers | 40 (P1v2 3.5GB tier) |
| Background daemons | 15 |
| API files with PERTI_MYSQL_ONLY | 102 of 387 |

## Tier 1: Quick Wins (2026-02-23)

### 1. Nginx gzip compression
- **File**: `default`
- **Change**: Added gzip block with level 6 compression for text/css/js/json/xml/svg/woff2
- **Impact**: ~75% bandwidth reduction on all text-based responses
- **Risk**: None — transparent to clients

### 2. PHP OPcache
- **File**: `scripts/startup.sh`
- **Change**: Added opcache-perti.ini configuration (128MB, revalidate every 60s)
- **Impact**: Eliminates ~10-50ms PHP script parsing per request
- **Risk**: Low — `validate_timestamps=1` means file changes picked up within 60s

### 3. Azure SQL LoginTimeout
- **File**: `load/connect.php`
- **Change**: Added `"LoginTimeout" => 5` to all 4 sqlsrv connection arrays (ADL, SWIM, TMI, REF)
- **Impact**: Failed Azure SQL connections timeout in 5s instead of 30s
- **Risk**: None — 5s is generous for same-region Azure SQL

### 4. Remove duplicate FontAwesome
- **File**: `load/header.php`
- **Change**: Removed Kit JS (line 72), kept CSS-only (line 70)
- **Impact**: One fewer CDN request, no icon flickering
- **Risk**: Low — CSS provides identical icon rendering; Kit JS sometimes returned 403

### 5. Defer non-critical header.php scripts (conservative)
- **File**: `load/header.php`
- **Change**: Added `defer` to `javascript.util.min.js`, `facility-hierarchy.js` (61KB), `deeplink.js`
- **NOT deferred**: jQuery, jQuery UI, Popper, Bootstrap, SweetAlert2, Select2 — these are used by inline scripts on many pages and would break if deferred
- **Impact**: 3 scripts (~95KB) no longer block rendering
- **Risk**: Low — only scripts never called by inline code were deferred
- **Future**: Broader defer requires moving all inline scripts to external deferred files

### 6. Cache-busting with `_v()` helper (header.php)
- **File**: `load/header.php`
- **Change**: Added `_v()` helper function using `filemtime()` to append modification timestamps to all 13 local JS/CSS includes. Replaces manual version strings.
- **Pattern**: `<script src="assets/js/foo.js<?= _v('assets/js/foo.js') ?>">` → `assets/js/foo.js?v=1740268200`
- **Impact**: Users always get fresh assets after every deployment — no more stale browser cache
- **Note**: `_v()` is defined once at top of header.php, uses `dirname(__DIR__)` for path resolution

## Tier 2: Medium Effort (2026-02-23)

### 7. Extend filemtime() cache-busting to all pages
- **Files**: `tmi-publish.php`, `gdt.php`, `route.php`, `demand.php`, `review.php`, `nod.php`, `splits.php`, `plan.php`, `schedule.php`, `sua.php`, `jatoc.php`, `sheet.php`, `data.php`, `status.php`
- **Change**: Applied `_v()` cache-busting pattern to all local JS/CSS includes across 14 pages + `load/footer.php` (5 local plugin scripts)
- **Impact**: Complete cache-busting coverage across the entire site — no more stale browser cache after deploys
- **Risk**: None — `_v()` is already defined in header.php, included by all pages
- **Note**: `footer.php` scripts (datetimepicker, parallax, jarallax, theme.min) were initially missed; added in follow-up fix

### 8. Cache-Control for static reference APIs
- **File**: `api/data/fixes.php`
- **Change**: Added `Cache-Control: public, max-age=3600` (navaid data changes only at AIRAC cycles)
- **Impact**: Browsers cache navaid fix lookups for 1 hour
- **Note**: `api/tiers.php` (5min) and `api/splits/sectors.php` (1h) already had caching
- **NOT applied to**: Any operational, TMI, ADL, demand, weather, or plan data endpoints

### 9. JS/CSS minification in CI/CD (Planned)
- **File**: `.github/workflows/azure-webapp-vatcscc.yml`
- **Change**: Add terser + cssnano build step before deploy
- **Impact**: ~60-70% JS/CSS size reduction before gzip (4.8MB JS → ~2MB minified → ~500KB gzipped)

### 10. PERTI_MYSQL_ONLY expansion
- **Files**: 13 API files that were unnecessarily opening 4 Azure SQL connections
- **Change**: Added `define('PERTI_MYSQL_ONLY', true)` to: `personnel.php`, `schedule.php`, `routes.php`, `reroutes.php`, `plans.l.php`, `tmi/ground_stop.php`, `tmi/ground_stops.php`, `review/tmr_export.php`, `review/tmr_ops_plan.php`, `review/tmr_report.php`, `review/tmr_parse_ntml.php`, `review/tmr_staffing.php`, `review/tmr_weather.php`
- **Impact**: 100-200ms saved per request on these endpoints (skips 4 Azure SQL TCP connections)
- **Total PERTI_MYSQL_ONLY coverage**: 115 of ~387 API files (was 102)

### 10b. Page loading indicator
- **Files**: `load/header.php`, `load/nav.php`, `load/nav_public.php`, `load/footer.php`
- **Change**: Added thin animated progress bar across top of every page with transparent click-blocking overlay. Bar shows while deferred scripts load, dismissed on `window.onload` (or 15s fallback).
- **Impact**: Users see visual feedback during load and can't interact with incomplete UI
- **Risk**: None — bar self-removes on `window.onload`; 15s fallback prevents permanent display

### 11. JATOC API connection optimization (PERTI_ADL_ONLY)
- **Files**: `load/connect.php`, 8 JATOC API files, `api/jatoc/space_ops.php`
- **Change**: Added `PERTI_ADL_ONLY` flag to `connect.php` (opens MySQL + ADL only, skips SWIM/TMI/REF). Applied to 8 JATOC endpoints that only use `$conn_adl`. Applied `PERTI_MYSQL_ONLY` to `space_ops.php` (MySQL only).
- **Files with PERTI_ADL_ONLY**: `incidents.php`, `incident.php`, `updates.php`, `personnel.php`, `oplevel.php`, `daily_ops.php`, `report.php`, `special_emphasis.php`
- **Measured improvement**:
  | Endpoint | Before | After | Improvement |
  |----------|--------|-------|-------------|
  | `incident.php?id=N` | ~2000ms | ~644ms | 68% faster |
  | `incidents.php` (list) | ~1979ms | ~802ms | 59% faster |
  | `space_ops.php` | ~2000ms | ~313ms | 84% faster |
  | `personnel.php` | ~2000ms | ~636ms | 68% faster |
  | `oplevel.php` | ~2600ms | ~653ms | 75% faster |
- **Root cause**: Each JATOC API call was opening 4 Azure SQL connections (ADL+SWIM+TMI+REF) but only using ADL — ~1.3s wasted on 3 unused TCP+TLS+auth handshakes per request
- **Risk**: Low — `PERTI_ADL_ONLY` is a subset of existing `PERTI_MYSQL_ONLY` pattern

### 12. JATOC auto-refresh interval (5s → 15s)
- **File**: `assets/js/jatoc.js`
- **Change**: Increased `state.countdown` from 5 to 15 seconds in `startAutoRefresh()`
- **Impact**: 3x fewer polling requests, dramatically reducing PHP-FPM worker contention
- **Risk**: Low — 15s is still responsive for operational awareness; incidents are not time-critical to the second

## Tier 3: Larger Refactors (Planned)

### 13. Batch plan data API
- Reduce plan.php from 16 parallel API requests to 1 batched request
- Create `api/data/plans/batch.php` endpoint

### 14. Lazy-load page-specific JS
- Move `facility-hierarchy.js` (61KB) from header.php to only pages that use it

### 15. Exclude T_T100D from deploy
- Remove 15MB `T_T100D_SEGMENT_US_CARRIER_ONLY.csv` from deploy package

## Data Freshness Policy

**CRITICAL**: The following endpoint categories must NOT have long-term client-side caching:
- All ADL flight data (`api/adl/*`) — max 60s (`demand/batch.php` uses 60s)
- All TMI data (`api/mgt/tmi/*`, `api/tmi/*`) — no-cache
- All PERTI plan data (`api/data/plans/*`, `api/data/sheet/*`, `api/mgt/perti/*`) — no-cache
- All weather data (`api/weather/*`) — short cache OK (15-60s: `impact.php` 15s, `alerts.php` 60s, `weather.php` 60s)
- All SUA activations (`api/data/sua/activations.php`) — 60s cache
- SUA definitions/geometry (`api/data/sua/sua_list.php`, `sua_geojson.php`) — 300s cache

TMU personnel rely on live data for operational decisions. Short caches (15-60s) are acceptable for frequently-polled endpoints to reduce server load. Only truly static reference data (navaid fixes, airway definitions, tier structures) may use long caches (5min+).
