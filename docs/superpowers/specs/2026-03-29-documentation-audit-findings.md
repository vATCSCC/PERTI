# Documentation & Wiki Audit Findings

**Date:** 2026-03-29
**Scope:** Comprehensive audit of all documentation, wiki, API specs, SDK READMEs, and root-level reference files against the current codebase state.

---

## Executive Summary

350+ documentation files audited across 7 categories. The core architecture documentation (CLAUDE.md, wiki) is structurally sound but **6-12 months behind** on newer subsystems (CTP, CDM, VACDM). The most critical gaps are in CLAUDE.md (missing 12 daemons, 23 JS modules, 16 API directories, 27 load/ files) and the SDK documentation (version conflicts, missing error handling docs).

### Severity Distribution

| Severity | Count | Description |
|----------|-------|-------------|
| Critical | 5 | Broken links, wrong system status, version conflicts |
| High | 12 | Missing daemons, subsystems, API directories |
| Medium | 15 | Incomplete coverage, stale fix tracking |
| Low | 8 | Cosmetic, naming, minor gaps |

---

## 1. CLAUDE.md Audit

### 1.1 Top-Level Directories
- **Documented:** 21 | **Actual:** 28
- **Missing:** `App_Data/`, `__pycache__/`, `artifacts/`, `backups/`, `node_modules/`, `postman/`, `vendor/`
- **Impact:** Low (mostly build artifacts, but `postman/` is useful for API testing)

### 1.2 Top-Level PHP Pages
- **Documented:** 28 | **Actual:** 32+
- **Missing pages:** `cdm.php`, `ctp.php`, `historical-routes.php`, `navdata.php`
- **Impact:** High (these are operational pages users navigate to)

### 1.3 Frontend JavaScript Modules
- **Documented:** "65 modules, 45 using i18n"
- **Actual:** 71+ modules (54 root + 17 lib/config)
- **Missing from listing (23 modules):**
  - `adl-refresh-utils.js`, `advisory-config.js`, `cdm.js`, `ctp.js`
  - `jatoc-facility-patch.js`, `natots-search.js`, `navdata.js`, `nod-demand-layer.js`
  - `plan-splits-map.js`, `plan-tables.js`, `playbook-dcc-loader.js`
  - `playbook-filter-parser.js`, `playbook-query-builder.js`, `procs_enhanced.js`
  - `public-routes.js`, `reroute-advisory-search.js`, `route-analysis-panel.js`
  - `routes-map.js`, `routes.js`, `statsim_rates.js`, `tmi-active-display.js`
  - `tmr_report.js`, `weather_radar_integration.js`
- **Missing lib/ utilities (7):** `aircraft.js`, `artcc-hierarchy.js`, `artcc-labels.js`, `deeplink.js`, `norad-codes.js`, `perti.js`, `route-advisory-parser.js`
- **Missing config/ (2):** `facility-roles.js`, `filter-colors.js`

### 1.4 Frontend CSS Files
- **Documented:** 13 | **Actual:** 16
- **Missing:** `ctp.css`, `navdata.css`, `route-analysis.css`, `routes.css`

### 1.5 PHP Utility Classes (lib/)
- **Documented:** 4 | **Actual:** 6
- **Missing:** `ArtccNormalizer.php`, `Changelog.php`

### 1.6 Load Directory (load/)
- **Documented:** 8 root + 4 discord + 1 service | **Actual:** 35 root + 5 discord + 7 services
- **Missing root files (27):** Including `aircraft_families.php`, `airport_aliases.php`, `breadcrumb.php`, `cache.php`, `gdp_section.php`, `hibernation.php`, `i18n.php`, `org_context.php`, `perti_constants.php`, `playbook_visibility.php`, and others
- **Missing discord:** `DiscordWebhookHandler.php`
- **Missing services (6):** `CDMService.php`, `CTPApiClient.php`, `CTPPlaybookSync.php`, `EDCTDelivery.php`, `NATTrackFunctions.php`, `NATTrackResolver.php`
- **Impact:** HIGH - load/ is severely under-documented

### 1.7 API Endpoints
- **Documented:** 12 paths | **Actual:** 28 directories
- **Missing (16):** `admin/`, `analysis/`, `ctp/`, `demand/`, `discord/`, `events/`, `gdt/`, `gis/`, `routes/`, `session/`, `statsim/`, `system/`, `test/`, `tiers/`, `user/`, `util/`
- **SWIM v1/ subdirs:** Documents 5, actual has 10 (missing `cdm/`, `connectors/`, `ctp/`, `playbook/`, `reference/`, `routes/`)

### 1.8 Background Jobs & Daemons
- **Documented:** 15 daemons | **Actual:** 24 daemons + 1 startup job
- **Missing daemons (12):**
  - `scripts/tmi/process_discord_queue.php` (continuous, always runs)
  - `scripts/ecfmp_poll_daemon.php` (5min, always runs)
  - `scripts/viff_cdm_poll_daemon.php` (30s, conditional)
  - `scripts/playbook/export_playbook.php` (daily backup)
  - `scripts/refdata_sync_daemon.php` (daily 06:00Z)
  - `scripts/swim_tmi_sync_daemon.php` (5min, always runs)
  - `scripts/event_sync_daemon.php` (6h, hibernation conditional)
  - `scripts/cdm_daemon.php` (60s, hibernation conditional)
  - `scripts/vacdm_poll_daemon.php` (2min, hibernation conditional)
  - `adl/php/parse_queue_daemon.php` (legacy fallback)
  - `adl/php/boundary_daemon.php` (legacy fallback)
  - `scripts/indexer/run_indexer.php` (startup job)
- **Missing documentation:** GIS/ADL mode switch (`USE_GIS_DAEMONS` flag), hibernation-conditional behavior

---

## 2. Wiki Audit

### 2.1 Sidebar (_Sidebar.md)
- **Broken link:** `[[Security Policy|SECURITY]]` references non-existent wiki page (actual file is root `/SECURITY.md`)
- **Orphaned pages (not in sidebar):**
  - `GDT-Reference.md` (comprehensive TFMS reference, 906 pages of synthesized content)
  - `TMI-Historical-Import-Statistics.md`
  - `TMI_Status_Demand_Chart_Integration_v2.md`
  - `analysis-page-format-consistency.md`

### 2.2 Key Pages
- **Home.md:** Last updated March 11 (18 days stale), references v18 not v19
- **API-Reference.md:** Last updated February 2026, missing v19 SWIM data isolation
- **Daemons-and-Scripts.md:** Claims "17 daemons" but 24 actually start; missing 5+ daemons from table
- **Architecture.md:** Generally accurate for core systems
- **Database-Schema.md:** Updated March 17, comprehensive and accurate
- **Changelog.md:** Current (v19)
- **Releases.md:** Current (v20 in-progress)
- **GDT-Reference.md:** Excellent but orphaned (not linked from sidebar)

---

## 3. Root-Level Documentation Audit

| Document | Status | Action Needed |
|----------|--------|---------------|
| `DEPENDENCY_MAP.md` | Missing CTP, CDM, VACDM, navdata subsystems | UPDATE |
| `CODE_PATTERNS_CATALOG.md` | Accurate but documents unfixed issues | KEEP |
| `NAMING_CONVENTIONS.md` | Accurate, no changes needed | KEEP |
| `CODING_STANDARDS.md` | Accurate, no changes needed | KEEP |
| `STYLING_GUIDE.md` | Missing newer subsystem styling | LOW UPDATE |
| `CODE_INCONSISTENCIES.md` | Fix status unclear, SQL injection points need verification | VERIFY |
| `CODE_FIXES_INVENTORY.md` | Stale (only P0 tracked as fixed, 47/53 show 0 fixes) | REFRESH or DEPRECATE |
| `ORPHANED_FILES.md` | JATOC incorrectly listed as orphaned (it's active) | FIX |
| `PERTI_MIGRATION_TRACKER.md` | Excellent, most well-maintained doc | KEEP |
| `TMI_Documentation_Index.md` | Missing CDM/VACDM sections | UPDATE |

---

## 4. API & SDK Documentation Audit

### 4.1 Version Conflicts
- `VATSWIM_API_Documentation.md`: claims v1.2.0
- `docs/swim/openapi.yaml`: claims v1.1.0
- `api-docs/openapi.yaml`: claims v1.1.0
- Python SDK pyproject.toml: v2.0.0 (all other SDKs: v1.0.0)

### 4.2 System Status Contradictions
- `api-docs/openapi.yaml`: "OPERATIONAL"
- `docs/swim/openapi.yaml`: "SWIM ACTIVE"
- `docs/QUICK_REFERENCE.md`: "HIBERNATED (since March 22, 2026)"
- **Reality:** System exited hibernation March 29, 2026

### 4.3 SDK Issues
- Python SDK v2.0.0 undocumented in README (still says v1.0.0 patterns)
- No SDK documents FIXM format parameter
- Error handling inconsistent across SDKs (different exception names/fields)
- Rate limiting only documented in PHP SDK (`SwimRateLimitException`)
- WebSocket event `tmi.modified` only in Python README, not others
- Authentication tier prefix format (`swim_sys_`, `swim_par_`, etc.) not shown in SDK examples

### 4.4 Missing API Documentation
- CDM, Connectors, CTP sessions, ACARS ingest endpoints undocumented in quick reference
- v19 data isolation architecture not documented anywhere in SWIM docs
- 16 API directories missing from all documentation

---

## 5. Fixes Applied — Round 1 (Core Documentation)

| # | File | Change | Severity |
|---|------|--------|----------|
| 1 | `CLAUDE.md` | Added missing PHP pages, JS modules, CSS, lib classes, load/ files, API dirs, daemons | High |
| 2 | `wiki/_Sidebar.md` | Fixed broken SECURITY link, added orphaned pages | Critical |
| 3 | `wiki/Daemons-and-Scripts.md` | Added 12 missing daemons, hibernation/GIS mode docs | High |
| 4 | `docs/QUICK_REFERENCE.md` | Updated system status to OPERATIONAL (hibernation exited) | Critical |
| 5 | `DEPENDENCY_MAP.md` | Added CTP, CDM, VACDM, navdata subsystems | High |
| 6 | `ORPHANED_FILES.md` | Corrected JATOC status (active, not orphaned) | High |
| 7 | `wiki/Home.md` | Updated to v19, system status OPERATIONAL | Critical |
| 8 | `TMI_Documentation_Index.md` | Added CDM, CTP sections; updated GDT to all-complete | High |

---

## 6. Fixes Applied — Round 2 (SDKs, APIs, Inventory)

| # | File | Change | Severity |
|---|------|--------|----------|
| 9 | `sdk/python/README.md` | Added tier prefix, rate limits table, FIXM section | Medium |
| 10 | `sdk/javascript/README.md` | Added tier prefix, tmi.modified event, rate limits table, FIXM section | Medium |
| 11 | `sdk/csharp/SwimClient/README.md` | Added tier prefix, tmi.modified event, rate limits table, FIXM section, rate limit error handling | Medium |
| 12 | `sdk/java/swim-client/README.md` | Added tier prefix, tmi.modified event, rate limits table, FIXM section, rate limit error handling | Medium |
| 13 | `sdk/cpp/README.md` | Added error handling section with SwimStatus codes, rate limits table | Medium |
| 14 | `sdk/php/README.md` | Added rate limits table, FIXM format section | Medium |
| 15 | `docs/swim/openapi.yaml` | Version 1.1.0 → 1.2.0 (aligned with VATSWIM_API_Documentation.md) | High |
| 16 | `api-docs/openapi.yaml` | Version 1.1.0 → 1.2.0 (aligned with VATSWIM_API_Documentation.md) | High |
| 17 | `CODE_FIXES_INVENTORY.md` | Refreshed: 6/53 → 29/53 fixed (55%). SQL injection mitigated, colors migrated to CSS vars, CORS centralized | Medium |
| 18 | `wiki/API-Reference.md` | Version v18 → v19, added CDM/CTP API sections, SWIM v1.2.0 endpoints table, See Also links | Medium |

---

## 7. Remaining Work

### Deferred (Low Risk)

1. **Python SDK v2.0.0** - pyproject.toml says v2.0.0 but README patterns are v1.0.0. Low risk (SDK not yet published).
2. **CODE_FIXES_INVENTORY.md remaining 24 items** - P1 API response standardization (8), P2 deprecated `.substr()` (4), P2 config magic numbers (2), P3 JS jQuery→fetch migration (5), P3 CSS naming conflicts (5). All are code quality, not documentation.
3. **STYLING_GUIDE.md** - Missing newer subsystem styling (CTP, CDM, navdata pages). Low impact.

### Completed (Previously Listed as Remaining)

- ~~SDK version reconciliation~~ → Tier prefixes standardized across all 6 SDKs
- ~~OpenAPI spec version alignment~~ → Both specs aligned to v1.2.0
- ~~SDK error handling standardization~~ → Rate limit handling + error patterns added to all 6 SDKs
- ~~SDK FIXM format documentation~~ → FIXM section added to all 5 applicable SDKs (not C++, ingest-only)
- ~~CODE_FIXES_INVENTORY.md~~ → Refreshed against codebase, 55% now verified fixed
- ~~SQL injection verification~~ → All 4 endpoints use real_escape_string() (mitigated)
- ~~wiki/API-Reference.md~~ → Updated to v19 with CDM, CTP, SWIM v1.2.0
- ~~wiki/Home.md~~ → Updated to v19, OPERATIONAL status
