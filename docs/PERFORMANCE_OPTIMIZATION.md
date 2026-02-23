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
- **Change**: Added `"LoginTimeout" => 5` to all 5 sqlsrv connection arrays
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
- **Change**: Added `_v()` helper function using `filemtime()` to append modification timestamps to all 12 local JS/CSS includes. Replaces manual version strings.
- **Pattern**: `<script src="assets/js/foo.js<?= _v('assets/js/foo.js') ?>">` → `assets/js/foo.js?v=1740268200`
- **Impact**: Users always get fresh assets after every deployment — no more stale browser cache
- **Note**: `_v()` is defined once at top of header.php, uses `dirname(__DIR__)` for path resolution

## Tier 2: Medium Effort (Planned)

### 7. Extend filemtime() cache-busting to all pages
- **Files**: `tmi-publish.php`, `gdt.php`, `route.php`, `demand.php`, `review.php`, `nod.php`, `splits.php`, `plan.php`
- **Change**: Apply `_v()` pattern or `filemtime()` to page-specific JS/CSS includes
- **Impact**: Complete cache-busting coverage across the site

### 8. Cache-Control for static reference APIs
- **Files**: ~5 reference API endpoints (fixes, routes, tiers, sectors, airspace elements)
- **Change**: Add `Cache-Control: max-age=300` to `max-age=3600`
- **NOT applied to**: Any operational, TMI, ADL, demand, weather, or plan data endpoints

### 9. JS/CSS minification in CI/CD
- **File**: `.github/workflows/azure-webapp-vatcscc.yml`
- **Change**: Add terser + cssnano build step before deploy
- **Impact**: ~60-70% JS/CSS size reduction before gzip

### 10. PERTI_MYSQL_ONLY expansion
- **Files**: ~30 API files currently making unnecessary Azure SQL connections
- **Change**: Add `define('PERTI_MYSQL_ONLY', true)` where safe
- **Impact**: 100-200ms saved per unnecessary connection set

## Tier 3: Larger Refactors (Planned)

### 10. Batch plan data API
### 11. Lazy-load page-specific JS
### 12. Exclude T_T100D from deploy

## Data Freshness Policy

**CRITICAL**: The following endpoint categories must NEVER have client-side caching:
- All ADL flight data (`api/adl/*`)
- All demand/rate data (`api/demand/*`)
- All TMI data (`api/mgt/tmi/*`, `api/tmi/*`)
- All weather data (`api/weather/*`)
- All PERTI plan data (`api/data/plans/*`, `api/data/sheet/*`, `api/mgt/perti/*`)
- All SUA activations, schedule data

TMU personnel rely on live data for operational decisions. Only truly static reference data (navaid fixes, airway definitions, tier structures) may be cached.
