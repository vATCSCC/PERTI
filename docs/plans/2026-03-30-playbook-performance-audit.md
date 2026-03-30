# Playbook.php Performance Audit

**Date**: 2026-03-30
**Status**: Investigation Complete — Findings Documented

## Executive Summary

The playbook page has **3 critical performance problems** causing total page load times exceeding **120 seconds** and making play selection completely non-functional during load:

| Rank | Issue | Impact | Fix Effort |
|------|-------|--------|------------|
| **P0** | DCC Loader fires 95 individual `get.php` calls | Saturates PHP-FPM pool, blocks ALL user interaction for 2+ min | Medium |
| **P1** | 28 MB of uncompressed CSV data files | 28 MB raw transfer on every uncached visit | Easy |
| **P2** | `get.php` missing `PERTI_MYSQL_ONLY` | Each call takes 1.7s instead of ~0.6s (4 wasted Azure SQL connections) | Trivial |

---

## Bottleneck #1 (P0-CRITICAL): DCC Loader N+1 Query Storm

### The Problem

`playbook-dcc-loader.js` (lines 60-79) fetches the play list, then fires **95 individual `get.php` AJAX calls in a tight `forEach` loop** — one per non-FAA play:

```javascript
// playbook-dcc-loader.js:72-78
nonFaa.forEach(function(p) {
    $.getJSON(API_GET + '?id=' + p.play_id, function(d) {
        if (d && d.success && d.routes) {
            injectPlay(d.play, d.routes);
        }
    });
});
```

### Measured Impact

- **95 concurrent HTTP requests** to `get.php`, each taking **~1.7s server-side**
- With 40 PHP-FPM workers, these requests are processed ~40 at a time
- Total drain time: **95 x 1.7s / 40 workers = ~4s per batch x 3 batches = ~12s minimum**
- But the real impact is worse: these requests **saturate the PHP-FPM pool**, blocking:
  - The user's own `get.php` call when clicking a play (stuck in queue)
  - Any other user accessing any page on the entire site
- **Playwright confirmed**: Clicking a play showed loading spinner for **60+ seconds** without ever completing — the user's request was queued behind the DCC loader's 95 calls

### Also Note

The DCC Loader makes a **duplicate** `list.php` call (`per_page=500&hide_legacy=1`) while `loadPlays()` already fetched `list.php?per_page=10000&hide_legacy=1` — redundant.

### Fix Options

1. **Best: Create a bulk endpoint** — `api/data/playbook/bulk_get.php?ids=8692,8710,...` that returns all 95 plays with routes in a single query. The MySQL query is trivial (~20ms for 95 plays). Single request instead of 95.

2. **Good: Use `list.php` data directly** — The DCC Loader only needs `play_name`, `route_string`, and origin/dest fields for PB directive resolution. The `list.php` response already includes `agg_route_strings` for sets ≤200 plays. Add the missing fields to `list.php` aggregation and eliminate `get.php` calls entirely.

3. **Minimum: Throttle to 3-5 concurrent** — Replace `forEach` with a queue that processes 3-5 plays at a time, preventing PHP-FPM saturation. Still slow but won't block other users.

---

## Bottleneck #2 (P1): 28 MB Uncompressed CSV Data

### The Problem

Nginx `gzip_types` does not include `text/csv` or `application/octet-stream`. CSV files are served at their raw size with **zero compression**.

### Measured Sizes (All Uncompressed)

| File | Raw Size | Est. Gzipped | Purpose |
|------|----------|-------------|---------|
| `points.csv` | **12.65 MB** | ~2.5 MB | 340,290 navigation fixes |
| `playbook_routes.csv` | **9.54 MB** | ~1.9 MB | 95,299 playbook routes |
| `cdrs.csv` | **3.22 MB** | ~0.6 MB | 47,141 CDR routes |
| `apts.csv` | **2.53 MB** | ~0.5 MB | 19,755 airports + facility hierarchy |
| `navaid_magvar.csv` | 132 KB | ~25 KB | 4,203 navaid magnetic variations |
| `navaids.csv` | 63 KB | ~12 KB | Navaid reference |
| **Total** | **28.1 MB** | **~5.5 MB** | |

For comparison, the GeoJSON files **are** gzip-compressed (75-84% reduction) because they're served as `application/json`.

### Fix

Add `text/csv` to the nginx `gzip_types` directive in the `default` config file:

```nginx
# default:18-27 — add text/csv
gzip_types
    text/plain
    text/css
    text/csv
    text/javascript
    application/javascript
    application/json
    application/xml
    image/svg+xml
    application/font-woff2
    font/woff2;
```

**Also** ensure nginx recognizes `.csv` as `text/csv` (add MIME type mapping if needed).

**Expected improvement**: ~22.5 MB saved per uncached page load (~80% reduction in CSV transfer).

---

## Bottleneck #3 (P2): `get.php` Missing PERTI_MYSQL_ONLY

### The Problem

`api/data/playbook/get.php` includes `connect.php` without `PERTI_MYSQL_ONLY`, causing **4 eager Azure SQL connections** that are never used:

```php
// get.php:18 — NO PERTI_MYSQL_ONLY defined
include("../../../load/connect.php");
```

Without `PERTI_MYSQL_ONLY`, `connect.php` (line 394-404) eagerly connects to:
- `$conn_adl` (Azure SQL) — **not used by get.php**
- `$conn_swim` (Azure SQL) — **not used by get.php**
- `$conn_tmi` (Azure SQL) — **not used by get.php**
- `$conn_ref` (Azure SQL) — **not used by get.php**

`get.php` only uses `$conn_sqli` (MySQL) and `get_conn_gis()` (lazy PostGIS getter).

### Measured Impact

| Endpoint | Has PERTI_MYSQL_ONLY | Avg TTFB |
|----------|---------------------|----------|
| `categories.php` | Yes | **0.60s** |
| `list.php` | Yes | **0.72s** (heavier query) |
| `get.php` | **No** | **1.74s** |

The ~1.0s delta is the cost of 4 wasted Azure SQL connections.

### Fix

Add `PERTI_MYSQL_ONLY` before the `connect.php` include:

```php
include("../../../load/config.php");
include("../../../load/input.php");
define('PERTI_MYSQL_ONLY', true);  // Only needs MySQL + lazy PostGIS
include("../../../load/connect.php");
```

This is proven safe — `analysis.php` in the same directory uses this exact pattern (PERTI_MYSQL_ONLY + get_conn_gis() lazy getter).

**Expected improvement**: get.php TTFB drops from ~1.7s to ~0.6-0.7s per call.

---

## Other Findings (Lower Priority)

### 4. Duplicate list.php Call

On page load, **two** `list.php` requests fire:
- `loadPlays()` → `list.php?per_page=10000&hide_legacy=1` (1.5s, 1.51 MB)
- DCC Loader → `list.php?per_page=500&hide_legacy=1` (1.0s, ~200 KB)

The DCC Loader should reuse the data from `loadPlays()` instead of making its own call.

### 5. list.php Response Size: 1.51 MB (201 KB gzipped)

The main `list.php` call returns 464 plays with route aggregation data. At 201 KB gzipped this is acceptable, but the `agg_route_strings` field adds bulk. The GROUP_CONCAT aggregation takes ~0.85s server-side for 464 plays joining 50K routes — acceptable for now.

### 6. GeoJSON Files: 7.9 MB (1.5 MB gzipped)

Seven GeoJSON boundary files load for the map. Gzip is working. These are needed for map rendering and are cached on repeat visits. Not a bottleneck.

### 7. playbook.php HTML: 0.5-0.6s TTFB

The PHP page itself is well-optimized with `PERTI_MYSQL_ONLY`. This is the minimum baseline and not a concern.

---

## Recommended Fix Order

1. **Immediate (P0)**: Fix DCC Loader — replace 95 individual get.php calls with a single bulk endpoint or eliminate entirely by enriching list.php data
2. **Quick win (P2)**: Add `PERTI_MYSQL_ONLY` to `get.php` — one line, ~1s improvement per get.php call
3. **Quick win (P1)**: Add `text/csv` to nginx `gzip_types` — saves ~22 MB transfer per uncached load
4. **Cleanup**: Eliminate duplicate list.php call from DCC Loader

### Expected Total Improvement

| Metric | Before | After Fixes |
|--------|--------|-------------|
| Page "load" event | **>120s** (Playwright timeout) | ~5-8s |
| Play click response | **>60s** (blocked by DCC loader) | ~0.7s |
| Data transfer (uncached) | ~30 MB CSV + 1.5 MB JSON | ~7 MB total |
| PHP-FPM saturation | Yes (95 concurrent requests) | No (1-2 requests) |

---

## Raw Measurement Data

### curl Timings (Multiple Runs)

| Endpoint | TTFB Run 1 | TTFB Run 2 | TTFB Run 3 | Avg |
|----------|-----------|-----------|-----------|-----|
| `categories.php` | 0.572s | 0.635s | 0.567s | 0.591s |
| `list.php` (10K) | 1.189s | 1.074s | - | 1.131s |
| `get.php` (35769) | 1.883s | 1.657s | 1.683s | 1.741s |
| `get.php` (not found) | 1.700s | 1.840s | 1.720s | 1.753s |
| `list.php` (per_page=1) | 0.850s | 0.657s | 0.660s | 0.722s |
| `playbook.php` HTML | 0.640s | 0.530s | - | 0.585s |

### Response Sizes

| Resource | Raw | Gzipped |
|----------|-----|---------|
| `list.php` (10K, hide_legacy) | 1,588,288 B | 201,509 B |
| `get.php` (play 35769) | 29,832 B | ~5 KB |
| `categories.php` | 719 B | ~400 B |
| `playbook.php` HTML | 60,108 B | ~8 KB |

### Database Stats

| Table | Rows |
|-------|------|
| `playbook_plays` | 4,063 |
| `playbook_routes` | 308,974 |
| Active non-legacy plays | 464 |
| Non-FAA active plays (DCC loader targets) | 95 |

### Network Requests (Playwright Waterfall)

- Total requests on initial load: **~200+**
- `get.php` calls from DCC Loader: **95**
- Static CSV/data files: 6 files, 28.1 MB uncompressed
- GeoJSON boundary files: 7 files, 7.9 MB (1.5 MB gzipped)
- CDN scripts (jQuery, Bootstrap, MapLibre, Turf, etc.): ~20 requests
- Map tiles: ~20 requests

### Key Files

| File | Role |
|------|------|
| `playbook.php` | Page shell (PERTI_MYSQL_ONLY, fast) |
| `assets/js/playbook.js` | Main module (5,977 lines) — `loadPlays()`, `loadCategories()` on init |
| `assets/js/playbook-dcc-loader.js` | **P0 OFFENDER** — fires 95 get.php calls |
| `api/data/playbook/list.php` | Play list API (PERTI_MYSQL_ONLY, GROUP_CONCAT route agg) |
| `api/data/playbook/get.php` | **P2 OFFENDER** — missing PERTI_MYSQL_ONLY |
| `api/data/playbook/categories.php` | Categories API (fast, optimized) |
| `default` (nginx config) | **P1 OFFENDER** — missing `text/csv` in gzip_types |
