# PERTI Codebase Reorganization Catalog

> **Audit Date:** 2026-03-01
> **Scope:** 1,732 tracked files across the entire repository
> **Supersedes:** January 2026 Reorganization Catalog (8 phases, 528 lines)
> **Status:** Document only — no files have been moved

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Critical Files — DO NOT MOVE](#2-critical-files--do-not-move)
3. [Tier 1: Quick Wins (Zero Dependencies)](#3-tier-1-quick-wins-zero-dependencies)
4. [Tier 2: Root File Organization](#4-tier-2-root-file-organization)
5. [Tier 3: Scripts Directory Reorganization](#5-tier-3-scripts-directory-reorganization)
6. [Tier 4: API Directory Issues](#6-tier-4-api-directory-issues)
7. [Tier 5: load/ Directory Separation](#7-tier-5-load-directory-separation)
8. [Tier 6: JavaScript Organization (Deferred)](#8-tier-6-javascript-organization-deferred)
9. [Tier 7: Architectural Issues (Long-term)](#9-tier-7-architectural-issues-long-term)
10. [Code Quality Issues](#10-code-quality-issues)
11. [Appendix A: Root-Level File Inventory](#appendix-a-root-level-file-inventory)
12. [Appendix B: Critical Daemon References in startup.sh](#appendix-b-critical-daemon-references-in-startupsh)
13. [Appendix C: JS Script Tag Reference Map](#appendix-c-js-script-tag-reference-map)
14. [Verification Checklist](#verification-checklist)

---

## 1. Executive Summary

### Codebase Snapshot (March 2026)

| Metric | Count |
|--------|-------|
| Total tracked files | 1,732 |
| Root-level tracked files | 80 |
| `docs/` files | 121 |
| `database/migrations/` files | 144 |
| `adl/migrations/` files | 164 |
| `adl/procedures/` stored procs | 56 |
| `sql/migrations/` files | 1 |
| `assets/js/` files | 60 |
| `assets/vendor/` vendored libs | 35 |
| `assets/data/` data files | 30+ (incl. 12 backups, 24MB) |
| `assets/geojson/` spatial data | 8 (49MB total) |
| `scripts/` root-level files | 66 |
| `integrations/` files | 66 |
| `sdk/` files | 73 |
| `wiki/` files | 47 |
| `simulator/engine/` files | 18 |
| Test/debug/diagnostic files | 37 |
| Discord-related files | 44 (across 5 directories) |
| Files >1MB tracked | 20+ (~130MB total) |

### Root-Level File Breakdown by Extension

| Extension | Count | Notes |
|-----------|-------|-------|
| `.php` | 31 | Core pages — most belong here |
| `.md` | 22 | Most should be in `docs/` |
| `.py` | 5 | Should be in `scripts/` |
| `.json` | 5 | `package.json`, `package-lock.json`, `composer.json`, `composer.lock`, `.eslintrc.json` |
| `.yml` | 2 | Stale Azure Pipelines configs |
| `.js` | 2 | `advisory-templates.js`, `.eslint-perti-rules.js` |
| Other | 13 | `.sln`, `.bat`, `.sh`, `.toml`, `.ini`, `.conf`, `.deployment`, `.ostype`, `default`, `composer`, `.gitignore`, `.gitattributes`, `LICENSE` |

### Key Findings Not in the January 2026 Catalog

- `.venv/` Python virtualenv exists on disk but is NOT in `.gitignore`
- SQL injection vulnerability in `data.php` (lines 15, 32, 50)
- `artifacts/swimclient/VatSim.Swim.Client.1.0.0.nupkg` — binary tracked in git
- 2 backup files (`_bu.php`) committed to git
- 37 test/debug/diagnostic files scattered across the codebase
- Duplicate Ground Stop implementation (6 legacy + 10 new files)
- 6 class files sitting in `api/` tree instead of `lib/`
- 3 migration locations (`database/migrations/`, `adl/migrations/`, `sql/migrations/`)
- Stale Azure Pipelines configs tracked in git (project uses GitHub Actions)
- **18MB** `api/data/sua_boundaries.json` — generated data file tracked in git (not gitignored)
- **22MB** `assets/geojson/SUA_transformed.geojson` — transformed SUA data tracked in git
- **24MB** `assets/data/backups/` — 12 AIRAC navdata backup CSV files committed to git
- **16MB** `assets/data/T_T100D_SEGMENT_US_CARRIER_ONLY.csv` — FAA T-100 carrier data in git
- **16MB** `adl/reference_data/sql_output/` — generated SQL INSERT scripts tracked in git
- **3.2MB** `composer` binary tracked in git (executable, not `composer.json`)
- 2 deprecated session files (`sessions/cid.php`, `sessions/query.php`) still tracked
- `postman/` and `.postman/` — two separate Postman directories tracked
- `assets/vendor/` — 35 vendored JS library files tracked (not via CDN/npm)
- `assets/img/demo/` — 39 theme demo images tracked (template assets, not project-specific)
- `simulator/engine/.azure/config` — Azure deployment config with resource names committed
- `adl/Phase5E1_Boundary_Import_Transition_Summary.md` duplicated in `adl/archive/`
- `.vscode/settings.json`, `.claude/settings.json` — IDE settings committed
- `cron/` has only 2 files — consider merging into `scripts/`
- `adl/sql/` — 2 staging SQL files separate from `adl/migrations/` (4th migration location)

---

## 2. Critical Files — DO NOT MOVE

These files have hardcoded dependencies in deployment, startup scripts, Azure configuration, or core application logic. Moving them would break the application.

| File | Reason | Referenced By |
|------|--------|---------------|
| `load/config.php` | Core configuration — all PHP files depend on this | 50+ PHP files via `include` |
| `load/connect.php` | Database connections (lazy-loaded getters) | All API endpoints |
| `load/input.php` | Input validation for PHP 8.2+ | `connect.php` line 16 |
| `load/header.php` | HTML head (CSS/JS CDN includes) | All root PHP pages |
| `load/footer.php` | Page footer, global JS includes | All root PHP pages |
| `load/nav.php` | Navigation bar (authenticated) | All authenticated pages |
| `load/nav_public.php` | Navigation bar (public) | Public pages |
| `load/hibernation.php` | Hibernation redirect logic | Multiple pages, SWIM API |
| `sessions/handler.php` | Session management | All pages (line 3 pattern) |
| `scripts/startup.sh` | Azure daemon launcher | GitHub Actions workflow, Azure App Service |
| `scripts/vatsim_adl_daemon.php` | Main ADL ingest daemon | `startup.sh` |
| `scripts/scheduler_daemon.php` | Scheduler daemon | `startup.sh` |
| `scripts/swim_ws_server.php` | WebSocket server | `startup.sh`, `composer.json` |
| `scripts/swim_sync_daemon.php` | SWIM sync daemon | `startup.sh` |
| `scripts/swim_sync.php` | Sync functions | `swim_sync_daemon.php`, `load/connect.php` |
| `scripts/swim_cleanup.php` | SWIM cleanup | `swim_sync_daemon.php` |
| `scripts/swim_ws_events.php` | WS event definitions | `swim_ws_server.php` |
| `scripts/archival_daemon.php` | Archival daemon | `startup.sh` |
| `scripts/monitoring_daemon.php` | Monitoring daemon | `startup.sh` |
| `scripts/atis_parser.php` | ATIS parsing | `vatsim_adl_daemon.php`, `api/adl/atis-debug.php` |
| `scripts/zone_daemon.php` | Zone detection daemon | `startup.sh` |
| `scripts/event_sync_daemon.php` | Event sync daemon | `startup.sh` |
| `scripts/adl_archive_daemon.php` | ADL archive daemon | `startup.sh` |
| `scripts/simtraffic_swim_poll.php` | SimTraffic polling | `startup.sh` |
| `scripts/swim_adl_reverse_sync_daemon.php` | Reverse sync daemon | `startup.sh` |
| `scripts/tmi/process_discord_queue.php` | Discord queue processor | `startup.sh` |
| `adl/php/parse_queue_gis_daemon.php` | GIS parse daemon | `startup.sh` |
| `adl/php/boundary_gis_daemon.php` | Boundary detection daemon | `startup.sh` |
| `adl/php/crossing_gis_daemon.php` | Crossing calculation daemon | `startup.sh` |
| `adl/php/waypoint_eta_daemon.php` | Waypoint ETA daemon | `startup.sh`, GitHub Actions |
| `composer.json` | PSR-4 autoload paths | Azure deployment, vendor loading |
| `nginx-site.conf` | Web server config | Azure deployment, `startup.sh` |
| `php.ini` | PHP configuration | Azure PHP-FPM |
| `api/swim/v1/ws/` (directory) | WebSocket classes | `composer.json` autoload |
| `.github/workflows/azure-webapp-vatcscc.yml` | CI/CD deployment | GitHub Actions |

---

## 3. Tier 1: Quick Wins (Zero Dependencies)

These changes have no code dependencies and can be done immediately without risk.

### 3.1 Add `.venv/` to `.gitignore`

The `.venv/` Python virtualenv directory exists on disk but is NOT in `.gitignore`. While it currently has 0 tracked files, it should be explicitly excluded to prevent accidental commits.

```
Action: EDIT .gitignore
Add: .venv/
```

### 3.2 Delete Stale Root Files

These files are tracked in git but serve no active purpose:

| File | Reason to Delete |
|------|------------------|
| `azure-pipelines.yml` | Project uses GitHub Actions, not Azure Pipelines |
| `azure-pipelines-1.yml` | Duplicate stale pipeline config |
| `global.json` | .NET SDK version pin — no .NET code in active use |
| `PERTI.sln` | Visual Studio solution file — not used (PHP project) |
| `run_atis_daemon.bat` | Windows batch file — app runs on Azure Linux |
| `PLAN.md` | Stale planning doc |
| `.ostype` | Build artifact |
| `.deployment` | Azure Kudu deployment config — superseded by GitHub Actions |
| `oryx-manifest.toml` | Azure Oryx build manifest — auto-generated |

### 3.3 Delete Backup Files

| File | Reason |
|------|--------|
| `api/data/plans/term_inits_bu.php` | Backup copy of `term_inits.php` — use git history instead |
| `api/mgt/schedule/delete_bu.php` | Backup copy of delete endpoint |

### 3.4 Delete Stale Load Test Results

| File | Reason |
|------|--------|
| `scripts/tmi/load_test_results_2026-01-28_063851.json` | Test output — should not be in source control |
| `scripts/tmi/load_test_results_2026-01-28_155924.json` | Test output — should not be in source control |

### 3.5 Delete Debug Data File

| File | Reason |
|------|--------|
| `adl/php/weather_debug.json` | Debug data committed to git |

### 3.6 Consolidate Lone Migration File

`sql/migrations/` contains exactly 1 file: `20260117_add_flow_tables.sql`

```
Action: MOVE sql/migrations/20260117_add_flow_tables.sql → database/migrations/tmi/20260117_add_flow_tables.sql
Then: DELETE empty sql/migrations/ and sql/ directories
```

### 3.7 Gitignore Binary Artifacts

`artifacts/swimclient/VatSim.Swim.Client.1.0.0.nupkg` is a NuGet binary package tracked in git.

```
Action: Add to .gitignore: artifacts/
Action: git rm --cached artifacts/swimclient/VatSim.Swim.Client.1.0.0.nupkg
```

### 3.8 Archive SWIM Session Transition Documents

These are internal session notes from January 2026 development sprints:

| File | Action |
|------|--------|
| `docs/swim/SWIM_Session_Transition_20260115.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_AOCTelemetry.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_Phase1Complete.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_Phase2Complete.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_Phase2Start.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_SDKComplete.md` | Move to `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_v2.md` | Move to `docs/swim/archive/` |

**Dependencies:** None (internal session notes, not referenced by code)

### 3.9 Delete Deprecated Session Files

These files are explicitly marked `@deprecated` in their docblocks:

| File | Reason |
|------|--------|
| `sessions/cid.php` | Deprecated — cURL session validation removed, native PHP sessions used |
| `sessions/query.php` | Deprecated — selfcookie validation removed |

### 3.10 Large Generated/Data Files to Gitignore

These large files inflate the repository and should be generated at deploy time or served from blob storage:

| File | Size | Action |
|------|------|--------|
| `api/data/sua_boundaries.json` | 18 MB | Add to `.gitignore`, `git rm --cached` |
| `assets/geojson/SUA_transformed.geojson` | 22 MB | Add to `.gitignore`, `git rm --cached` |
| `assets/geojson/SUA_transform_report.json` | 23 KB | Add to `.gitignore`, `git rm --cached` (build artifact) |
| `assets/data/T_T100D_SEGMENT_US_CARRIER_ONLY.csv` | 16 MB | Add to `.gitignore`, `git rm --cached` (FAA reference data) |
| `adl/reference_data/sql_output/*.sql` | 43 MB total | Add to `.gitignore`, `git rm --cached` (generated INSERT scripts) |

**Note:** These files exist in git history even after removal. Consider `git filter-repo` or BFG to truly shrink the repo, but that's a separate decision.

### 3.11 AIRAC Navdata Backups

`assets/data/backups/` contains 12 navdata CSV backup files totaling **24 MB**. These are auto-generated by AIRAC update scripts.

```
Action: Add to .gitignore: assets/data/backups/
Action: git rm --cached -r assets/data/backups/
```

### 3.12 Duplicate ADL Transition Document

`adl/Phase5E1_Boundary_Import_Transition_Summary.md` exists in both the `adl/` root AND `adl/archive/`. The files differ slightly — keep the archive version (it's the final), delete the root copy.

```
Action: git rm adl/Phase5E1_Boundary_Import_Transition_Summary.md
```

### 3.13 IDE/Tool Settings to Gitignore

| File | Action |
|------|--------|
| `.vscode/settings.json` | Add `.vscode/` to `.gitignore`, `git rm --cached` |
| `.claude/settings.json` | Add `.claude/` to `.gitignore`, `git rm --cached` |

**Note:** `.claude/` may already be partially gitignored — verify with `git ls-files .claude/`.

---

## 4. Tier 2: Root File Organization

### 4.1 Root Markdown Files → `docs/`

22 markdown files at the root level should be in `docs/` subdirectories. For each move, any cross-references in other docs must be updated.

| Current Location | Proposed Destination | Category |
|-----------------|---------------------|----------|
| `advisory_builder_alignment_v1.md` | `docs/advisory/advisory_builder_alignment_v1.md` | Advisory |
| `advisory_formatting_spec_for_claude_code.md` | `docs/advisory/formatting_spec.md` | Advisory |
| `assistant_codebase_index_v18.md` | `docs/archive/assistant_codebase_index_v18.md` | Archive |
| `CODE_FIXES_INVENTORY.md` | `docs/archive/CODE_FIXES_INVENTORY.md` | Archive |
| `CODE_INCONSISTENCIES.md` | `docs/archive/CODE_INCONSISTENCIES.md` | Archive |
| `CODE_INCONSISTENCIES_EXPLAINED.md` | `docs/archive/CODE_INCONSISTENCIES_EXPLAINED.md` | Archive |
| `CODE_PATTERNS_CATALOG.md` | `docs/CODE_PATTERNS_CATALOG.md` | Reference |
| `CODING_STANDARDS.md` | `docs/CODING_STANDARDS.md` | Reference |
| `DEPENDENCY_MAP.md` | `docs/DEPENDENCY_MAP.md` | Reference |
| `eta_enhancement_transition_summary.md` | `docs/archive/eta_enhancement_transition_summary.md` | Archive |
| `GDT_Incremental_Migration.md` | `docs/gdt/archive/GDT_Incremental_Migration.md` | GDT archive |
| `GDT_Phase1_Transition.md` | `docs/gdt/archive/GDT_Phase1_Transition.md` | GDT archive |
| `GDT_Unified_Design_Document_v1.1.md` | `docs/gdt/GDT_Unified_Design_Document.md` | GDT |
| `NAMING_CONVENTIONS.md` | `docs/NAMING_CONVENTIONS.md` | Reference |
| `ORPHANED_FILES.md` | `docs/ORPHANED_FILES.md` | Reference |
| `PERTI_MIGRATION_TRACKER.md` | `docs/PERTI_MIGRATION_TRACKER.md` | Reference |
| `STYLING_GUIDE.md` | `docs/STYLING_GUIDE.md` | Reference |
| `TMI_Documentation_Index.md` | `docs/tmi/INDEX.md` | TMI |

**Keep at root** (standard convention):
- `README.md` — Project README
- `CLAUDE.md` — AI assistant instructions
- `SECURITY.md` — Security policy
- `LICENSE` — License file

### 4.2 Reference Updates Required for Root Markdown Moves

When moving GDT docs, update these cross-references:

| File to Edit | Line(s) | Old Reference | New Reference |
|--------------|---------|---------------|---------------|
| `docs/tmi/GDT_Session_20260121.md` | 253 | `GDT_Unified_Design_Document_v1.1.md` | `../gdt/GDT_Unified_Design_Document.md` |
| `docs/tmi/GDT_API_Documentation.md` | 328-331 | `GDT_Unified_Design_Document_v1.md`, `GDT_Phase1_Transition.md` | Updated paths |
| `docs/GDT_GS_Transition_Summary_20260110.md` | 87, 163 | `docs/GDT_Unified_Design_Document_v1.md` | `gdt/GDT_Unified_Design_Document.md` |
| `docs/STATUS.md` | 628 | `docs/GDT_Unified_Design_Document_v1.md` | `docs/gdt/GDT_Unified_Design_Document.md` |
| `docs/tmi/ARCHITECTURE.md` | 403 | `GDT_Unified_Design_Document_v1.md` | `../gdt/GDT_Unified_Design_Document.md` |
| `docs/tmi/GS_Eligibility_Fix_Transition.md` | 286 | `/GDT_Unified_Design_Document_v1.md` | `/docs/gdt/GDT_Unified_Design_Document.md` |
| `README.md` | 295 | `assistant_codebase_index_v17.md` | `docs/archive/assistant_codebase_index_v18.md` |

When moving TMI_Documentation_Index.md:

| File to Edit | Line(s) | Old Reference | New Reference |
|--------------|---------|---------------|---------------|
| `docs/tmi/GDT_API_Documentation.md` | 331 | `TMI_Documentation_Index.md` | `INDEX.md` |
| `docs/tmi/NTML_Discord_Parser_Alignment_20260117.md` | 184 | `TMI_Documentation_Index.md` | `INDEX.md` |

### 4.3 Root Python Scripts → `scripts/`

| Current Location | Proposed Destination | Purpose |
|-----------------|---------------------|---------|
| `airac_full_update.py` | `scripts/navdata/airac_full_update.py` | AIRAC cycle data update |
| `nasr_navdata_updater.py` | `scripts/navdata/nasr_navdata_updater.py` | FAA NASR import |
| `escape_desert_analysis.py` | `scripts/analysis/escape_desert_analysis.py` | One-off event analysis |
| `mit_analysis_final.py` | `scripts/analysis/mit_analysis_final.py` | MIT analysis |
| `mit_trajectory_analysis.py` | `scripts/analysis/mit_trajectory_analysis.py` | MIT trajectory analysis |

**Note:** `airac_full_update.py` and `nasr_navdata_updater.py` may be referenced in operational docs or wiki. Grep before moving.

### 4.4 Root JavaScript File

| Current Location | Proposed Destination | Notes |
|-----------------|---------------------|-------|
| `advisory-templates.js` | `assets/js/config/advisory-templates.js` | No `<script>` tag found in PHP pages — verify if loaded dynamically |

### 4.5 Investigate Before Moving

| File | Question |
|------|----------|
| `playbook.php` | Root-level PHP page — is it active or orphaned? Not in `load/nav.php` navigation |
| `startup.sh` (root) | How does this relate to `scripts/startup.sh`? Which is authoritative? |
| `default` | nginx config file at root — is this used by Azure deployment or redundant with `nginx-site.conf`? |

---

## 5. Tier 3: Scripts Directory Reorganization

The `scripts/` directory has **66 files at root level** mixing daemons, utilities, migrations, SQL scripts, test files, and analysis scripts. Well-organized subdirectories already exist for some features.

### 5.1 Existing Subdirectories (Well-Organized)

These are already properly structured:

| Subdirectory | Files | Purpose |
|-------------|-------|---------|
| `scripts/tmi/` | 15+ | TMI processing, parsers, tests |
| `scripts/tmi_compliance/` | Multi | TMI compliance analyzer (Python) |
| `scripts/statsim/` | Multi | Statistical simulation |
| `scripts/playbook/` | Multi | CDR/playbook route parsing |
| `scripts/bada/` | Multi | BADA performance data |
| `scripts/openap/` | Multi | OpenAP performance data |
| `scripts/vatsim_atis/` | Multi | ATIS fetcher |
| `scripts/navdata/` | Multi | Navigation data import |
| `scripts/adl_archive/` | Multi | ADL archival utilities |
| `scripts/postgis/` | Multi | PostGIS boundary scripts |
| `scripts/discord/` | 2 | Discord test scripts |
| `scripts/indexer/` | Multi | Search indexer |

### 5.2 Daemons at Root — DO NOT MOVE

These scripts are referenced by `scripts/startup.sh` with relative paths. See [Appendix B](#appendix-b-critical-daemon-references-in-startupsh) for the complete list.

### 5.3 Proposed Reorganization for Non-Daemon Files

**Create `scripts/maintenance/`** — data migration and export utilities:

| Current Location | Notes |
|-----------------|-------|
| `scripts/export_config_data.php` | Config data export |
| `scripts/export_sql.php` | SQL export |
| `scripts/export_config_to_sql.php` | Config to SQL converter |
| `scripts/export_perti_events.php` | Events export |
| `scripts/migrate_config_data.php` | Config migration |
| `scripts/migrate_division_events.php` | Event migration |
| `scripts/migrate_php82.php` | PHP 8.2 compatibility migration |
| `scripts/import_rw_rates.php` | Rate import |
| `scripts/run_migration.php` | Migration runner |
| `scripts/fix_input_php.php` | Input fix utility |
| `scripts/refresh_vatsim_boundaries.php` | Boundary refresh |
| `scripts/sync_division_events.php` | Division event sync |
| `scripts/sync_perti_events.php` | PERTI event sync |
| `scripts/deploy_sp_optimizations.php` | SP deployment |
| `scripts/convert_config_csv_to_sql.ps1` | CSV converter |

**Path update required:** Change `require_once $baseDir . '/load/config.php'` to `__DIR__ . '/../../load/config.php'` in each moved file.

**Create `scripts/sql/`** — standalone SQL scripts:

| Current Location | Notes |
|-----------------|-------|
| `scripts/analyze_runway_data.sql` | Runway analysis |
| `scripts/backfill_ata_utc.sql` | ATA backfill |
| `scripts/check_batch_version.sql` | Batch version check |
| `scripts/cleanup_non_airports.sql` | Data cleanup |
| `scripts/clear_config_data.sql` | Config clear |
| `scripts/config_data_migration.sql` | Config migration SQL |
| `scripts/diagnose_flight_counts.sql` | Diagnostic query |
| `scripts/eta_accuracy_diagnostic.sql` | ETA diagnostic |
| `scripts/eta_accuracy_report.sql` | ETA report |
| `scripts/eta_diagnostic.sql` | ETA diagnostic |
| `scripts/fix_config_migration.sql` | Config fix |
| `scripts/parse_runway_data.sql` | Runway parsing |
| `scripts/prefile_batch_trace.sql` | Prefile trace |
| `scripts/prefile_eta_debug.sql` | Prefile debug |
| `scripts/timing_accuracy_analysis.sql` | Timing analysis |

**Create `scripts/testing/`** — test and load test scripts:

| Current Location | Notes |
|-----------------|-------|
| `scripts/test_atis_parser.php` | ATIS parser test (refs `../atis_parser.php` — update path) |
| `scripts/test_daemons.ps1` | Daemon test (PowerShell) |
| `scripts/test_daemons.sh` | Daemon test (bash) |
| `scripts/test_multi_discord.php` | Discord test |
| `scripts/test_swim_eta.php` | SWIM ETA test |
| `scripts/test_trajectory_crossings.php` | Trajectory test |
| `scripts/swim_load_test.php` | SWIM load test |
| `scripts/prefile_update_test.sql` | Prefile test SQL |

**Create `scripts/analysis/`** — one-off analysis scripts:

| Current Location | Notes |
|-----------------|-------|
| `scripts/analyze_monitoring.php` | Monitoring analysis |
| `scripts/analyze_mysql_runways.php` | Runway analysis |
| `scripts/brightline_sno_export.py` | Event export |
| `scripts/ese_to_geojson.py` | Sector file converter |
| `scripts/build_sector_boundaries.py` | Boundary builder |
| `scripts/update_playbook_routes.py` | Playbook updater |
| `scripts/statsim_scraper.js` | Stats scraper |
| `scripts/merge_sua_data.js` | SUA merger (JS) |
| `scripts/merge_sua_data.php` | SUA merger (PHP) |
| `scripts/transform_sua_geojson.js` | SUA transformer |

---

## 6. Tier 4: API Directory Issues

### 6.1 Class Files in `api/` Tree

These files define reusable classes but live in the API endpoint tree instead of `lib/`:

| Current Location | Proposed Destination | Referenced By |
|-----------------|---------------------|---------------|
| `api/adl/AdlQueryHelper.php` | `lib/AdlQueryHelper.php` | `api/adl/current.php`, `api/adl/flight.php`, other ADL endpoints |
| `api/stats/StatsHelper.php` | `lib/StatsHelper.php` | `api/stats/*.php` |
| `api/tmi/AdvisoryNumber.php` | `lib/AdvisoryNumber.php` | `api/tmi/advisories.php` |
| `api/tmi/helpers.php` | `lib/TMIHelpers.php` | Multiple TMI endpoints |
| `api/jatoc/auth.php` | `lib/JATOCAuth.php` | `api/jatoc/*.php` |
| `api/jatoc/datetime.php` | `lib/JATOCDateTime.php` | `api/jatoc/*.php` |
| `api/jatoc/validators.php` | `lib/JATOCValidators.php` | `api/jatoc/*.php` |
| `api/swim/v1/auth.php` | `lib/SWIMAuth.php` | `api/swim/v1/*.php` |

**Note:** `api/swim/v1/ws/WebSocketServer.php`, `ClientConnection.php`, and `SubscriptionManager.php` are already in `composer.json` PSR-4 autoload — DO NOT MOVE without updating autoload config.

### 6.2 Duplicate Ground Stop Code

Two parallel implementations exist:

**Legacy (flat files):** `api/tmi/`
| File | Purpose |
|------|---------|
| `gs_apply.php` | Apply ground stop |
| `gs_apply_ctd.php` | Apply CTD |
| `gs_preview.php` | Preview |
| `gs_purge_all.php` | Purge all |
| `gs_purge_local.php` | Purge local |
| `gs_simulate.php` | Simulate |

**New (subdirectory):** `api/tmi/gs/`
| File | Purpose |
|------|---------|
| `create.php` | Create ground stop |
| `activate.php` | Activate |
| `extend.php` | Extend |
| `purge.php` | Purge |
| `get.php` | Get details |
| `list.php` | List active |
| `flights.php` | Flight list |
| `demand.php` | Demand data |
| `model.php` | Data model |
| `common.php` | Shared utilities |

**Recommendation:** Determine which set is active (check `gdt.js` AJAX calls), deprecate the other, then delete after verification.

### 6.3 API Naming Inconsistencies

The API tree uses three different naming conventions:

| Convention | Examples |
|-----------|----------|
| PascalCase | `AdvisoryNumber.php`, `AdlQueryHelper.php`, `StatsHelper.php` |
| kebab-case | `public-routes.php`, `cleanup-queue.php`, `atis-debug.php` |
| snake_case | `gs_apply.php`, `snapshot_history.php`, `rate_history.php` |

**Recommendation:** Standardize on kebab-case for endpoints (REST convention), PascalCase for class files. This is a large change — defer unless doing a major API refactor.

### 6.4 Oddities

| File | Issue |
|------|-------|
| `api/data/plans.l.php` | Unusual `.l.php` extension — the `l` likely means "list" |
| `api/test/swim_reroute_test.php` | Test endpoint checked into production code |
| `api/cron.php` | Cron trigger in API root — consider moving to `cron/` |

---

## 7. Tier 5: load/ Directory Separation

The `load/` directory mixes 5 different concerns in 22 tracked files:

### 7.1 Current Contents by Category

**Configuration (keep in load/):**
| File | Purpose |
|------|---------|
| `config.example.php` | Configuration template |
| `connect.php` | Database connections |
| `input.php` | Input validation |
| `swim_config.php` | SWIM configuration |
| `perti_constants.php` | Application constants |
| `airport_aliases.php` | Airport alias mapping |
| `org_context.php` | Organization context |
| `hibernation.php` | Hibernation mode logic |
| `i18n.php` | PHP i18n helpers |

**Page Templates (keep in load/):**
| File | Purpose |
|------|---------|
| `header.php` | HTML head |
| `footer.php` | Page footer |
| `nav.php` | Auth navigation |
| `nav_public.php` | Public navigation |
| `breadcrumb.php` | Breadcrumb component |
| `gdp_section.php` | GDP section partial |
| `coordination_log.php` | Coordination log partial |

**Discord Integration (already in subdirectory):**
| File | Purpose |
|------|---------|
| `discord/DiscordAPI.php` | Discord API client |
| `discord/MultiDiscordAPI.php` | Multi-org Discord |
| `discord/TMIDiscord.php` | TMI Discord logic |
| `discord/DiscordMessageParser.php` | Message formatting |
| `discord/DiscordWebhookHandler.php` | Webhook processing |

**Services (could move to lib/services/):**
| File | Purpose |
|------|---------|
| `services/GISService.php` | PostGIS spatial queries |

### 7.2 Recommendation

The current structure is functional. The only candidate for relocation is `services/GISService.php` → `lib/services/GISService.php` to consolidate utility classes. **Low priority.**

---

## 8. Tier 6: JavaScript Organization (Deferred)

### 8.1 Current State

60 tracked files in `assets/js/`:
- **48 files** at root level (flat structure)
- **3 subdirectories**: `lib/` (9 files), `config/` (5 files), `plugins/` (2 files)

### 8.2 Naming Inconsistency

| Convention | Count | Examples |
|-----------|-------|----------|
| kebab-case | 28 | `tmi-publish.js`, `adl-service.js`, `route-maplibre.js` |
| snake_case | 8 | `tmi_compliance.js`, `initiative_timeline.js`, `statsim_rates.js` |
| camelCase | 0 | — |

### 8.3 Proposed Feature Subdirectories

If reorganized, the JS files could be grouped:

| Subdirectory | Files |
|-------------|-------|
| `assets/js/tmi/` | `tmi-gdp.js`, `tmi-active-display.js`, `tmi-publish.js`, `tmi_compliance.js`, `advisory-config.js`, `gdp.js`, `gdt.js` |
| `assets/js/weather/` | `weather_radar.js`, `weather_radar_integration.js`, `weather_impact.js`, `weather_hazards.js` |
| `assets/js/map/` | `route-maplibre.js`, `route-symbology.js`, `fir-scope.js`, `fir-integration.js`, `plan-splits-map.js` |
| `assets/js/plan/` | `plan.js`, `plan-tables.js`, `sheet.js`, `review.js`, `schedule.js` |
| `assets/js/data/` | `facility-hierarchy.js`, `awys.js`, `cycle.js`, `procs.js`, `procs_enhanced.js`, `navdata.js` |
| `assets/js/reroute/` | `reroute.js`, `public-routes.js`, `playbook.js`, `playbook-cdr-search.js`, `playbook-dcc-loader.js` |

### 8.4 WARNING: High Risk

**Every JS file move requires updating `<script src>` tags in PHP files.** See [Appendix C](#appendix-c-js-script-tag-reference-map) for the complete reference map. This affects 15+ PHP pages with 40+ script tags.

**Recommendation:** Defer unless implementing a build system (Vite, webpack) that would abstract file paths.

---

## 9. Tier 7: Architectural Issues (Long-term)

### 9.1 Discord Integration Spread

Discord-related files span 5+ locations:

| Location | Files | Purpose |
|----------|-------|---------|
| `load/discord/` | 5 | PHP utility classes |
| `api/discord/` | 5 | REST API endpoints |
| `api/nod/discord.php`, `discord-post.php` | 2 | NOD Discord posting |
| `scripts/discord/` | 2 | Test scripts |
| `scripts/tmi/process_discord_queue.php` | 1 | Queue processor |
| `discord-bot/` | 5 | Node.js Gateway bot |

**Total:** 44 Discord-related tracked files. The current separation (utilities in `load/`, endpoints in `api/`, bot in `discord-bot/`) follows the project's conventions. **No action recommended** — consolidation would require rewriting include paths in 11+ files.

### 9.2 Authentication Pattern Scatter

Three distinct auth patterns coexist:

| Pattern | Used By | Mechanism |
|---------|---------|-----------|
| Session-based | All PHP pages, plan API | `sessions/handler.php` → `$_SESSION['VATSIM_CID']` |
| SWIM API key | `api/swim/v1/` | `X-API-Key` header → `swim_api_keys` table |
| Discord bot key | `api/mgt/tmi/coordinate.php` | `X-API-Key` header (separate key) |

**Recommendation:** Document the patterns but don't unify — each serves a different authentication context.

### 9.3 Migration File Locations

Migration SQL files exist in four locations:

| Location | Files | Database Target |
|----------|-------|-----------------|
| `database/migrations/` | 144 | Mixed (MySQL, Azure SQL, PostGIS) |
| `adl/migrations/` | 164 | Azure SQL (VATSIM_ADL primarily) |
| `adl/sql/` | 2 | ADL staging table definitions |
| `sql/migrations/` | 1 | Stale (consolidate per Tier 1, Section 3.6) |

The split between `database/migrations/` and `adl/migrations/` is intentional — ADL migrations grew as a semi-autonomous subsystem. `adl/sql/` contains staging table definitions that are functionally migrations. **Consider consolidating** by moving `adl/migrations/` and `adl/sql/` contents into `database/migrations/adl/` as a future effort.

### 9.4 Documentation Sprawl

121 docs files, many of which are session transition summaries or superseded design docs:

| Category | Count | Action |
|----------|-------|--------|
| Active reference docs | ~30 | Keep |
| SWIM session transitions | 8 | Archive (see Tier 1, Section 3.8) |
| TMI session/transition docs | ~15 | Consider archiving |
| Plan design docs (`docs/plans/`) | 17 | Keep as design record |
| Discord thread exports (`docs/discord-threads/`) | 13 | Consider moving to wiki or archiving |

### 9.5 Vendored Frontend Libraries

`assets/vendor/` contains **35 tracked files** — full source copies of Bootstrap, jQuery slim, Jarallax, Parallax.js, SimpleBar, Smooth Scroll, and bs-custom-file-input. These are vendored rather than loaded via CDN or npm.

**Recommendation:** Low priority. The vendored approach works for deployment simplicity. If a build system is ever adopted, these should move to `node_modules/` managed by `package.json`.

### 9.6 Theme Demo Assets

`assets/img/demo/presentation/` contains **39 tracked files** (demo screenshots, icons, holiday-themed intro images). These appear to be from the original Bootstrap theme template and are unlikely to be used in production.

**Recommendation:** Verify none are referenced by active pages, then remove.

### 9.7 Large Reference Data in Git

Several large reference data files are tracked that could be served from blob storage or generated at build time:

| File/Dir | Size | Purpose |
|----------|------|---------|
| `assets/data/points.csv` | 7.6 MB | Navigation waypoints |
| `assets/data/playbook_routes.csv` | 5.8 MB | Playbook routes |
| `assets/data/cdrs.csv` | 3.4 MB | Coded departure routes |
| `assets/geojson/artcc.json` | 9.3 MB | ARTCC boundary GeoJSON |
| `assets/geojson/tracon.json` | 7.8 MB | TRACON boundary GeoJSON |
| `assets/geojson/SUA.geojson` | 9.2 MB | SUA boundary GeoJSON |
| `scripts/statsim/074_vatusa_event_import.sql` | 4.6 MB | VATUSA event import SQL |
| `assets/data/logs/*.json` | 3+ MB | Navdata changelog JSON |

These are served to the browser or used for imports. Moving to external storage would reduce clone size but add deployment complexity. **Document only — no action unless repo size becomes a problem.**

### 9.8 ADL as Semi-Autonomous Sub-Project

The `adl/` directory functions as a semi-independent subsystem with its own:
- `adl/migrations/` (164 files — more than `database/migrations/`)
- `adl/procedures/` (56 stored procedure SQL files)
- `adl/reference_data/` (import scripts + generated SQL output)
- `adl/scripts/` (15 utility scripts — overlap with `scripts/`)
- `adl/analysis/` (analysis scripts)
- `adl/php/` (6 GIS daemons)
- `adl/sql/` (2 staging definitions)
- `adl/archive/` (archived docs)
- 10+ markdown docs at `adl/` root

**Recommendation:** The ADL subsystem is well-organized internally. The main concern is `adl/scripts/` overlapping with the top-level `scripts/` directory. No urgent action needed.

### 9.9 Tiny Directories

| Directory | Files | Observation |
|-----------|-------|-------------|
| `cron/` | 2 | Only `run_indexer.php` and `process_tmi_proposals.php` — could merge into `scripts/` |
| `login/` | 3 | `.htaccess`, `callback.php`, `index.php` — small but self-contained |
| `sessions/` | 3 | `handler.php` + 2 deprecated files (see Tier 1, Section 3.9) |
| `api-docs/` | 3 | `README.md`, `index.php`, `openapi.yaml` — small but serves a clear purpose |

### 9.10 Postman Configuration Duplication

Two separate Postman directories exist:

| Directory | Contents |
|-----------|----------|
| `postman/` | `README.md`, environments JSON, globals JSON (3 files) |
| `.postman/` | `config.json` with workspace ID and spec references (1 file) |

**Recommendation:** Consolidate into one location. `.postman/` appears to be a Postman desktop app config; `postman/` contains shared collection data.

---

## 10. Code Quality Issues

These are not file-move items but were discovered during the audit and should be addressed.

### 10.1 SQL Injection in `data.php`

**Severity: HIGH**

```php
// Line 15: Unsanitized user input
$uri = explode('?', $_SERVER['REQUEST_URI']);
$id = $uri[1];

// Line 32: Direct interpolation into SQL
$plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();

// Line 50: Direct echo into JavaScript (XSS)
var plan_id = <?= $id ?>;
```

**Fix:** Use parameterized query and cast to int:
```php
$id = (int)($uri[1] ?? 0);
```

### 10.2 Scattered Test/Debug Endpoints

37 test, debug, and diagnostic files are tracked in git. These should not be deployed to production:

**API endpoints (accessible via HTTP):**
| File | Risk |
|------|------|
| `api/adl/atis-debug.php` | Exposes ATIS parsing internals |
| `api/adl/demand/debug.php` | Exposes demand calculation internals |
| `api/adl/diagnose.php` | Diagnostic data |
| `api/adl/diagnostic.php` | Diagnostic data |
| `api/splits/debug.php` | Splits debug data |
| `api/splits/test.php` | Test endpoint |
| `api/stats/boundary_debug.php` | Boundary debug |
| `api/stats/snapshot_debug.php` | Snapshot debug |
| `api/test/swim_reroute_test.php` | SWIM reroute test |

**Recommendation:** Either:
1. Add `.htaccess` rules to block debug endpoints in production, OR
2. Gate these endpoints behind admin authentication, OR
3. Remove from deployment (add to `.github/workflows/azure-webapp-vatcscc.yml` exclude list)

**Script test files (not HTTP-accessible, lower risk):**
See `scripts/test_*.php`, `scripts/tmi/test_*.php`, `scripts/tmi/load_test_*.php` — 28 files total.

### 10.3 Duplicate Aviation Standards Reference

| File | Action |
|------|--------|
| `docs/swim/Aviation_Standards_Cross_Reference.md` | DELETE (duplicate) |
| `docs/swim/Aviation_Data_Standards_Cross_Reference.md` | KEEP (canonical) |

### 10.4 Azure Resource Names in Committed Config

`simulator/engine/.azure/config` contains Azure resource names and deployment targets:
```ini
group = VATSIM_RG
appserviceplan = ASP-VATSIMRG-9bb6
web = vatcscc-atfm-engine
```

While not secrets, this exposes infrastructure naming. Consider adding to `.gitignore` or moving to environment variables.

### 10.5 Composer Binary Tracked in Git

The `composer` file at root (3.2 MB) is the Composer PHAR binary committed to the repo. This is a common practice for reproducible deployments but inflates the repository. Azure's build system can install Composer automatically.

### 10.6 Pre-commit Hook Setup

`.husky/pre-commit` contains a comprehensive pre-commit hook checking for:
- ESLint errors on JS files
- `.substr()` usage (should use `.slice()`)
- `console.log()` in production JS
- Hardcoded credentials
- PHP syntax errors
- PHP-CS-Fixer style issues
- SQL injection patterns
- `date()` vs `gmdate()` usage
- CORS wildcard warnings

**Status:** The hook exists but requires `npm install husky` to activate. Verify if the team has it enabled.

---

## Appendix A: Root-Level File Inventory

Complete listing of all 80 tracked files at root level with recommended disposition.

### Keep (Core Application)

| File | Purpose |
|------|---------|
| `index.php` | Home page |
| `plan.php` | PERTI plan detail |
| `schedule.php` | Event schedule |
| `demand.php` | ADL demand charts |
| `splits.php` | Sector split tool |
| `route.php` | Route visualization |
| `review.php` | Post-event review |
| `sheet.php` | Planning sheet |
| `gdt.php` | Ground Delay Table |
| `nod.php` | North Atlantic display |
| `status.php` | System status |
| `swim.php` | SWIM API info |
| `swim-doc.php` | SWIM doc viewer |
| `swim-docs.php` | SWIM docs hub |
| `swim-keys.php` | API key management |
| `jatoc.php` | JATOC incidents |
| `tmi-publish.php` | TMI publishing |
| `sua.php` | SUA display |
| `event-aar.php` | Event AAR config |
| `airport_config.php` | Airport config editor |
| `fmds-comparison.php` | FMDS comparison |
| `data.php` | Planning data view |
| `simulator.php` | ATC simulator |
| `navdata.php` | Navigation data |
| `transparency.php` | About page |
| `privacy.php` | Privacy policy |
| `healthcheck.php` | Health check |
| `hibernation.php` | Hibernation info page |
| `logout.php` | Session destroy |

### Keep (Standard Config)

| File | Purpose |
|------|---------|
| `README.md` | Project README |
| `CLAUDE.md` | AI assistant instructions |
| `SECURITY.md` | Security policy |
| `LICENSE` | License |
| `.gitignore` | Git exclusions |
| `.gitattributes` | Git attributes |
| `composer.json` | PHP autoload/dependencies |
| `package.json` | Node.js dependencies (for SUA merge scripts) |
| `package-lock.json` | Node.js lockfile |
| `nginx-site.conf` | Web server config |
| `php.ini` | PHP config |
| `composer` | Composer binary |
| `.eslintrc.json` | ESLint config |
| `.eslint-perti-rules.js` | Custom ESLint rules |
| `.php-cs-fixer.php` | PHP CS Fixer config |

### Move to `docs/`

See [Section 4.1](#41-root-markdown-files--docs) — 22 markdown files.

### Move to `scripts/`

See [Section 4.3](#43-root-python-scripts--scripts) — 5 Python files.

### Move to `assets/js/`

| File | Destination |
|------|-------------|
| `advisory-templates.js` | `assets/js/config/advisory-templates.js` |

### Delete

See [Section 3.2](#32-delete-stale-root-files) — 9 stale files.

### Investigate

| File | Question |
|------|----------|
| `playbook.php` | Active page or orphaned? Not in navigation. |
| `startup.sh` | Root copy vs `scripts/startup.sh` — which is canonical? |
| `default` | Nginx config — redundant with `nginx-site.conf`? |

---

## Appendix B: Critical Daemon References in startup.sh

`scripts/startup.sh` launches these daemons with relative paths. DO NOT MOVE without updating startup.sh.

```
scripts/vatsim_adl_daemon.php
scripts/archival_daemon.php
scripts/monitoring_daemon.php
scripts/swim_ws_server.php
scripts/swim_sync_daemon.php
scripts/simtraffic_swim_poll.php
scripts/swim_adl_reverse_sync_daemon.php
scripts/scheduler_daemon.php
scripts/event_sync_daemon.php
scripts/adl_archive_daemon.php
scripts/zone_daemon.php
scripts/tmi/process_discord_queue.php
adl/php/parse_queue_gis_daemon.php
adl/php/boundary_gis_daemon.php
adl/php/crossing_gis_daemon.php
adl/php/waypoint_eta_daemon.php
```

Supporting files loaded by daemons (also immovable):
```
scripts/atis_parser.php          → loaded by vatsim_adl_daemon.php
scripts/swim_sync.php            → loaded by swim_sync_daemon.php
scripts/swim_cleanup.php         → loaded by swim_sync_daemon.php
scripts/swim_ws_events.php       → loaded by swim_ws_server.php
scripts/swim_adl_reverse_sync.php → loaded by swim_adl_reverse_sync_daemon.php
```

---

## Appendix C: JS Script Tag Reference Map

Every JavaScript file and the PHP page(s) that load it via `<script src>` tags. This map MUST be updated if any JS file is moved.

| JS File | PHP File(s) |
|---------|-------------|
| `assets/js/lib/i18n.js` | `load/footer.php` (global) |
| `assets/js/lib/dialog.js` | `load/footer.php` (global) |
| `assets/js/lib/datetime.js` | `load/footer.php` (global) |
| `assets/js/lib/logger.js` | `load/footer.php` (global) |
| `assets/js/lib/colors.js` | `load/footer.php` (global) |
| `assets/js/lib/deeplink.js` | Multiple pages |
| `assets/js/lib/perti.js` | `load/footer.php` (global) |
| `assets/js/plugins/datetimepicker.js` | `load/footer.php` |
| `assets/js/theme.min.js` | `load/footer.php` |
| `assets/js/config/phase-colors.js` | `demand.php`, `gdt.php`, `nod.php`, `route.php`, `status.php` |
| `assets/js/config/rate-colors.js` | `demand.php`, `gdt.php` |
| `assets/js/config/filter-colors.js` | `demand.php`, `nod.php`, `route.php` |
| `assets/js/config/constants.js` | Multiple pages |
| `assets/js/config/facility-roles.js` | `splits.php` |
| `assets/js/adl-service.js` | `demand.php`, `gdt.php`, `nod.php`, `route.php` |
| `assets/js/adl-refresh-utils.js` | `demand.php`, `gdt.php` |
| `assets/js/advisory-config.js` | `gdt.php`, `plan.php`, `route.php` |
| `assets/js/awys.js` | `route.php` |
| `assets/js/cycle.js` | `navdata.php` |
| `assets/js/demand.js` | `demand.php`, `gdt.php` |
| `assets/js/facility-hierarchy.js` | `splits.php`, `plan.php` |
| `assets/js/fir-scope.js` | `gdt.php` |
| `assets/js/fir-integration.js` | `gdt.php` |
| `assets/js/gdt.js` | `gdt.php` |
| `assets/js/gdp.js` | `gdt.php` |
| `assets/js/initiative_timeline.js` | `plan.php` |
| `assets/js/jatoc.js` | `jatoc.php` |
| `assets/js/jatoc-facility-patch.js` | `jatoc.php` |
| `assets/js/navdata.js` | `navdata.php` |
| `assets/js/nod.js` | `nod.php` |
| `assets/js/nod-demand-layer.js` | `nod.php` |
| `assets/js/plan.js` | `plan.php` |
| `assets/js/plan-tables.js` | `plan.php`, `data.php` |
| `assets/js/plan-splits-map.js` | `plan.php`, `data.php` |
| `assets/js/playbook.js` | `route.php` |
| `assets/js/playbook-cdr-search.js` | `route.php` |
| `assets/js/playbook-dcc-loader.js` | `route.php` |
| `assets/js/procs.js` | `route.php` |
| `assets/js/procs_enhanced.js` | `route.php` |
| `assets/js/public-routes.js` | `route.php` |
| `assets/js/reroute.js` | `route.php` |
| `assets/js/review.js` | `review.php` |
| `assets/js/route-maplibre.js` | `route.php` |
| `assets/js/route-symbology.js` | `route.php` |
| `assets/js/schedule.js` | `schedule.php` |
| `assets/js/sheet.js` | `sheet.php`, `data.php` |
| `assets/js/splits.js` | `splits.php` |
| `assets/js/statsim_rates.js` | `review.php` |
| `assets/js/sua.js` | `sua.php` |
| `assets/js/tmi_compliance.js` | `tmi-publish.php` |
| `assets/js/tmi-active-display.js` | `gdt.php`, `tmi-publish.php` |
| `assets/js/tmi-gdp.js` | `gdt.php` |
| `assets/js/tmi-publish.js` | `tmi-publish.php` |
| `assets/js/tmr_report.js` | `review.php` |
| `assets/js/weather_hazards.js` | `route.php` |
| `assets/js/weather_impact.js` | `route.php`, `demand.php` |
| `assets/js/weather_radar.js` | `route.php`, `nod.php` |
| `assets/js/weather_radar_integration.js` | `route.php` |
| `assets/js/plugins/snow.js` | `load/footer.php` (seasonal) |

---

## Verification Checklist

After implementing changes at any tier, verify:

- [ ] Site loads without errors at `https://perti.vatcscc.org`
- [ ] Navigation works (all menu items resolve)
- [ ] API endpoints respond (spot-check 3-4 endpoints)
- [ ] No 404 errors in browser console
- [ ] `git status` shows only intended changes
- [ ] `grep -r "OLD_FILENAME" --include="*.php" --include="*.js" --include="*.md"` finds no stale references
- [ ] Daemons start correctly (check `/home/LogFiles/` after deployment)
- [ ] `composer dump-autoload` runs without errors (if lib/ files moved)

### Per-Tier Verification

| Tier | Additional Checks |
|------|-------------------|
| Tier 1 | `.gitignore` updated, `git status` clean, no untracked `.venv/` warning |
| Tier 2 | All moved markdown files accessible from new paths, cross-references updated |
| Tier 3 | Scripts that reference `__DIR__` paths still work, daemons unaffected |
| Tier 4 | `composer dump-autoload` if PSR-4 classes moved, API endpoints respond |
| Tier 5 | No changes recommended |
| Tier 6 | Every PHP page loads its JS files (no 404s in Network tab) |
| Tier 7 | Document only — no verification needed |

---

*End of Reorganization Catalog — March 2026 Audit*
