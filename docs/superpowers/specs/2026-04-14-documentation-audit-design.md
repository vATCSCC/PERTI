# Full Documentation Audit & Reorganization

**Date**: 2026-04-14
**Status**: Approved design
**Scope**: All ~260 documentation files across the PERTI project
**Goals**: Accuracy cleanup, consolidation, onboarding readiness
**Approach**: 6-phase audit (triage, CLAUDE.md overhaul, reorganization, archival, wiki audit, indexing)

---

## File Inventory (Verified 2026-04-14)

| Location | Files | Notes |
|----------|-------|-------|
| Root `*.md` | 23 | 4 keep at root, 19 relocate/archive/delete |
| `docs/*.md` (root-level orphans) | 51 | Biggest organizational problem |
| `docs/plans/` | 34 | Mix of shipped and active |
| `docs/superpowers/specs/` | 26 | Mix of shipped and active |
| `docs/superpowers/plans/` | 20 | Mix of shipped and active |
| `docs/swim/` | 34 | 7 session transitions archivable |
| `docs/tmi/` | 21 | 7 transition/session docs archivable |
| `docs/analysis/` | 13 | Recent (Mar-Apr 2026), keep |
| `docs/audits/` | 1 | i18n localization audit |
| `docs/reference/` | 1 | vNAS ecosystem reference |
| `wiki/` | 57 | Primary user-facing docs |
| `adl/` | 11 | ADL subsystem docs |
| `data/indexes/` | 4 | AI-generated indices, stale |

**Total project docs**: ~266 markdown files (excluding node_modules)

---

## Phase 1: Triage & Categorization

Scan every documentation file and assign to one of five buckets:

| Bucket | Criteria | Action |
|--------|----------|--------|
| **Keep** | Accurate, actively referenced, correct location | No change |
| **Update** | Valuable but contains stale info | Fix in Phase 2/5 |
| **Relocate** | Good content, wrong location | Move in Phase 3 |
| **Archive** | Shipped specs/plans, old transitions | Move to `archive/` in Phase 4 |
| **Delete** | Redundant, superseded, stale-by-design | Remove |

**Output**: Triage report (markdown table) listing every file with bucket assignment and reasoning.

### Delete Candidates (Verified)

| File | Reason |
|------|--------|
| `data/indexes/codebase_index.md` | AI-generated, stale immediately |
| `data/indexes/agent_context.md` | AI-generated, stale immediately |
| `data/indexes/database_schema.md` | AI-generated, stale immediately |
| `data/indexes/database_quick_reference.md` | AI-generated, stale immediately |
| `assistant_codebase_index_v18.md` (root) | AI-generated, stale immediately |
| `docs/GDT_Unified_Design_Document_v1.md` | Superseded by root `GDT_Unified_Design_Document_v1.1.md` |
| `tower_cab_snapshot.md` (root) | Browser accessibility dump, not documentation |
| `TMI_Documentation_Index.md` (root) | Stale index referencing nonexistent files; `docs/tmi/README.md` is the maintained replacement |

---

## Phase 2: CLAUDE.md Overhaul

### Add: vNAS Integration

All files verified to exist on `feature/vnas-reference-sync` branch:

**Glossary additions**: vNAS, CRC, TCP, STARS Area, ULID

**Database tables** (VATSIM_ADL): 9 `vnas_*` tables:
- `vnas_facilities` — ARTCCs, TRACONs, ATCTs with JSON configs
- `vnas_positions` — Controller positions with ULID references
- `vnas_stars_tcps` — Terminal Control Positions
- `vnas_stars_areas` — STARS areas with visibility centers
- `vnas_beacon_banks` — Beacon code allocation ranges
- `vnas_transceivers` — Frequency/location mappings
- `vnas_video_map_index` — Video map metadata
- `vnas_airport_groups` — Named airport groupings
- `vnas_restrictions` — Inter-facility agreements and Auto ATC rules

**API endpoints** (6 files under `/api/swim/v1/ingest/vnas/`):
- `facilities.php` — Import facility hierarchy, positions, TCPs, areas, beacon banks, transceivers, video maps
- `restrictions.php` — Import restrictions and Auto ATC rules
- `controllers.php` — Live controller feed with staffing/consolidation detection
- `handoff.php` — Position handoff events
- `tags.php` — Controller tags/callsigns
- `track.php` — Controller tracking data

**Daemons**: `scripts/vnas_controller_poll.php` — **pending** (commented out in `startup.sh` with TODO: "Uncomment when migration 024 is deployed"). Also add `scripts/facility_stats_daemon.php` (active, most recently added daemon).

**Python scripts**: `scripts/vnas_sync/` — `crc_parser.py`, `vnas_crc_watcher.py`, `__init__.py`

**Migrations**:
- `database/migrations/vnas/001_vnas_reference_schema.sql`
- `database/migrations/vnas/002_vnas_restrictions_schema.sql`
- `database/migrations/vnas/003_vnas_staffing_mapping.sql`
- `database/migrations/swim/021_vnas_integration_schema.sql`
- `database/migrations/schema/009_splits_column_widening.sql`

**Gotchas**:
- vNAS position ULIDs: Live controller feed uses ULIDs that match `vnas_positions.position_ulid` exactly — no transformation needed
- `splits_presets.artcc` was CHAR(3), too narrow for 4-char international FIR codes — widened to NVARCHAR(4)
- Runway-aware procedures: Only set arrival runway context when a distinct airport token is found

### Verify & Fix Existing Content

- Cross-check all file paths in Project Structure against current filesystem
- Verify daemon table against current `scripts/startup.sh` (add `facility_stats_daemon.php`)
- Verify API endpoint tables against current `api/` directory
- Update JS module count (currently says "71+")
- Fix `docs/stats/` reference — directory exists but contains only `index.html`, no documentation
- Note schema migration 009 number collision (`009_scheduler_state.sql` and `009_splits_column_widening.sql`)
- Update any paths that will change in Phase 3 (e.g., `docs/HIBERNATION_RUNBOOK.md` references)

---

## Phase 3: File Reorganization

### New Directory Structure

Create these new `docs/` subdirectories:

| Directory | Purpose |
|-----------|---------|
| `docs/standards/` | Coding standards, naming conventions, patterns |
| `docs/operations/` | Deployment, hibernation, status, incident reports |
| `docs/reference/` | (exists) Technical references, quick lookups |
| `docs/simulator/` | ATFM simulator design and deployment |
| `docs/admin/` | Funding, use cases, non-technical project docs |
| `docs/infra/` | Azure costs, MySQL upgrade, PowerBI, stats DB |

### Phase 3A: Root-Level Files (23 files)

**Keep at root (4)**:
- `CLAUDE.md`
- `README.md`
- `SECURITY.md`
- `PLAN.md`

**Move to `docs/standards/` (7)**:
- `CODING_STANDARDS.md`
- `NAMING_CONVENTIONS.md`
- `STYLING_GUIDE.md`
- `CODE_PATTERNS_CATALOG.md`
- `CODE_FIXES_INVENTORY.md`
- `DEPENDENCY_MAP.md`
- `PERTI_MIGRATION_TRACKER.md` — tracks JS constant migrations to PERTI namespace

**Move to `docs/tmi/` (5)**:
- `advisory_builder_alignment_v1.md`
- `advisory_formatting_spec_for_claude_code.md`
- `GDT_Incremental_Migration.md`
- `GDT_Phase1_Transition.md`
- `GDT_Unified_Design_Document_v1.1.md` (supersedes `docs/GDT_Unified_Design_Document_v1.md`)

**Move to `docs/audits/` (3)**:
- `CODE_INCONSISTENCIES.md`
- `CODE_INCONSISTENCIES_EXPLAINED.md`
- `ORPHANED_FILES.md` — valuable static analysis report (34 orphaned files, ~8,200 lines dead code)

**Archive (1)**:
- `eta_enhancement_transition_summary.md` → `docs/plans/archive/`

**Delete (3)**:
- `assistant_codebase_index_v18.md` — stale AI index
- `tower_cab_snapshot.md` — browser accessibility dump
- `TMI_Documentation_Index.md` — stale; `docs/tmi/README.md` is the maintained replacement

### Phase 3B: docs/ Root Files (51 files)

**Move to `docs/simulator/` (4)**:
- `ATFM_Simulator_Design_Document_v1.md`
- `ATFM_Simulator_Deployment_2026-01-12.md`
- `ATFM_Simulator_Phase1_GroundStop_2026-01-12.md`
- `ATFM_Simulator_Phase1_5_TrafficGen_2026-01-12.md`

**Move to `docs/admin/` (12)**:
- `FUND_AIR_CANADA_FOUNDATION.md`
- `FUND_CISCO_CSR.md`
- `FUND_MSFT_PARTNER.md`
- `FUND_FFWD_APPLICATION.md`
- `FUND_RAY_FOUNDATION.md`
- `FUND_MSFT_TSI.md`
- `FUND_FAA_NOFO20-01_WHITEPAPER.md`
- `FUND_GTIA_GIVES.md`
- `FUNDING_PACKET.md`
- `FUNDING_PACKET_FILLED.md`
- `vATCSCC_Use_Case_Digestible_v4.md`
- `vATCSCC_Use_Case_Digestible_v5.md`

**Move to `docs/operations/` (7)**:
- `DEPLOYMENT_GUIDE.md`
- `HIBERNATION_RUNBOOK.md`
- `STATUS.md`
- `PERFORMANCE_OPTIMIZATION.md`
- `ctp-pull-sync-go-live.md` — go-live checklist (BLOCKED status)
- `adl-ingest-outage-2026-02-17-claude-brief.md` — incident report
- `I18N_TRACKING.md` — i18n migration progress tracker

**Move to `docs/reference/` (2)**:
- `COMPUTATIONAL_REFERENCE.md` — 15-section technical reference covering all computational subsystems
- `QUICK_REFERENCE.md` — API endpoint index and codebase quick lookup

**Move to `docs/audits/` (9)**:
- `UI_CONSISTENCY_AUDIT_2026-03-06.md`
- `ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md`
- `OPERATIONAL_ANALYSIS_JAN_MAR_2026.md`
- `CHANGE_IMPACT_DEPENDENCY_MAP_2026-03-14.md`
- `DOCUMENTED_FINDINGS_VERIFICATION_2026-03-14.md`
- `swim-api-audit-2026-03-23.md`
- `ui-audit-2026-02-09.md`
- `fr-ca-localization-audit-for-claude.md`
- `CODEBASE_REORGANIZATION_CATALOG.md` — existing reorg plan (March 1, covers code + docs; "no files moved")

**Move to `docs/tmi/` (4)**:
- `GDT_GS_Transition_Summary_20260110.md`
- `gdt-tmi-workflow-plan.md`
- `TMIDiscord_Formatting_Transition_v3.2.md`
- `CANOC_ADVISORY_INTEGRATION.md` — Canadian advisory (GDP/GS) integration into TMI Publisher

**Move to `adl/` (existing project root, 5)**:
- `ADL_REFRESH_MIGRATION_GUIDE.md`
- `planned_crossings_performance.md`
- `route_distance_transition.md`
- `simbrief_parsing_summary.md`
- `simbrief_parsing_transition.md`

Note: There is no `docs/adl/` — the ADL docs live in `adl/` at project root (which has 11 existing docs).

**Move to `docs/infra/` (4)**:
- `AZURE_COST_OPTIMIZATION_ANALYSIS.md`
- `mysql-8-upgrade-analysis.md`
- `POWERBI_DATAFLOW_UPDATE.md`
- `VATSIM_STATS_DATABASE.md`

**Move to `docs/analysis/` (3)**:
- `ctp-sample-scenario-april2026.md`
- `CTP_EXTERNAL_REPO_AUDIT_AND_INTEGRATION.md`
- `fmds-comparison.md` — FMDS vs PERTI strategic analysis (NOT SWIM-related)

**Delete (1)**:
- `GDT_Unified_Design_Document_v1.md` — superseded by root `v1.1`

**Totals**: 51 relocated + 1 deleted = 0 loose files in `docs/` root

### Critical: Update Cross-References Simultaneously

These wiki/doc files link to `docs/` root paths that will change:

| Source | Current Link | New Link |
|--------|-------------|----------|
| `wiki/_Sidebar.md` | `docs/DEPLOYMENT_GUIDE.md` | `docs/operations/DEPLOYMENT_GUIDE.md` |
| `wiki/_Sidebar.md` | `docs/COMPUTATIONAL_REFERENCE.md` | `docs/reference/COMPUTATIONAL_REFERENCE.md` |
| `wiki/_Sidebar.md` | `docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` | `docs/audits/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` |
| `wiki/Home.md` | `docs/DEPLOYMENT_GUIDE.md` | `docs/operations/DEPLOYMENT_GUIDE.md` |
| `wiki/Home.md` | `docs/COMPUTATIONAL_REFERENCE.md` | `docs/reference/COMPUTATIONAL_REFERENCE.md` |
| `docs/COMPUTATIONAL_REFERENCE.md` | `HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `docs/QUICK_REFERENCE.md` | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `docs/STATUS.md` | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `CLAUDE.md` | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |

All link updates MUST happen in the same commit as the file moves.

---

## Phase 4: Archive Shipped Work

### Determination Method

A spec/plan is "shipped" if:
1. The feature it describes has committed code on `main` branch, AND
2. The feature is mentioned in MEMORY.md or CLAUDE.md as deployed/complete, OR
3. The feature's implementation plan is fully checked off

### `docs/superpowers/specs/archive/`

Check each of the 26 specs. Known shipped (per MEMORY.md):
- CTP E26 integration, SWIM playbook/CDR routes, ATFCM subfields, playbook compound filter, route symbology filter icons, AIRAC pipeline fix, CTP SWIM NAT track throughput, CTP playbook sync, playbook facility counts, CTP pull sync, demand page enhancements, TMI operations analytics

Likely still active/planned: GUFI UUID migration, VATSWIM client bridges specs, RAD amendment, VATSWIM reference library, antimeridian route analysis, demand-TMI timeline, facility demand parity, route amendment dialogue, splits-to-SWIM bridge, SimTraffic webhook bridge, VATSWIM route query API

### `docs/superpowers/plans/archive/`

Matching plans for shipped specs above.

### `docs/plans/archive/`

Check each of 34 plans. Known shipped:
- TMI compliance UI/implementation (Feb), demand chart (Feb), GS reroute compliance (Feb), i18n site-wide (Feb), holding detection (Feb), pipeline alignment (Mar), airport groupings (Feb), PERTI plans homepage (Feb), VATCAN interop (Feb), NOD TMI facility flows (Feb), GDT workflow enhancement (Feb), codebase globalization (Feb), NORAD codes (Feb), PERTI namespace (Feb), TMI trajectory (Feb)

Likely still active: Variable-rate GDP, two-pass disambiguation, VATSWIM client bridges, vNAS reference sync, SWIM reroute parity, ADL raw data lake, playbook performance audit

### `docs/swim/archive/`

7 session transition files (all from January 2026):
- `SWIM_Session_Transition_20260115.md`
- `SWIM_Session_Transition_20260116.md`
- `SWIM_Session_Transition_20260116_v2.md`
- `SWIM_Session_Transition_20260116_Phase1Complete.md`
- `SWIM_Session_Transition_20260116_Phase2Complete.md`
- `SWIM_Session_Transition_20260116_SDKComplete.md`
- `SWIM_Session_Transition_20260116_AOCTelemetry.md`

Also archive candidates (implementation details for shipped features):
- `SWIM_Phase2_Phase3_Transition.md`
- `SWIM_Phase2_RealTime_Design.md`
- `Claude_VATSWIM_Implementation_Prompt_2026-03-14.md` — AI session context, not reference

### `docs/tmi/archive/`

7 transition/session files:
- `TMI_Publisher_v1.6_Transition.md`
- `TMI_Publisher_v1.7_Transition.md`
- `TMI_Publisher_v1.8.0_Transition.md`
- `TMI_Publisher_v1.9.0_Transition.md`
- `GS_Eligibility_Fix_Transition.md`
- `SESSION_TRANSITION_20260117.md`
- `GDT_Session_20260121.md`

---

## Phase 5: Wiki Accuracy Audit

Wiki remains the primary user-facing documentation (57 pages).

### High Priority (likely stale)

| Page | Issue |
|------|-------|
| `Database-Schema.md` | Needs normalized 8-table architecture, vNAS tables, updated row counts |
| `Architecture.md` | Verify daemon list and data flow descriptions against current state |
| `API-Reference.md` | Add vNAS, GDT, CTP, demand, GIS endpoints |
| `Daemons-and-Scripts.md` | Match against current `startup.sh` (add facility_stats, note pending vnas_controller_poll) |
| `SWIM-API.md` / `SWIM-Routes-API.md` / `SWIM-Playbook-API.md` | Check for new endpoints added since last update |
| `Acronyms.md` | Sync with CLAUDE.md glossary (add vNAS terms) |

### Medium Priority (check for drift)

| Page | Concern |
|------|---------|
| 6 Algorithm pages | Verify formulas/logic against current stored procedures |
| `GDT-Ground-Delay-Tool.md` / `GDT-Reference.md` | GDP algorithm redesign (FPFS+RBD) changes |
| `Ground-Stop-Reference.md` | GS eligibility changes |
| `Playbook.md` | Spatial validation, compound filters, geometry backfill |
| `Splits.md` | Column widening for international FIR codes |
| `NOD-Dashboard.md` | Recent enhancements |
| `AIRAC-Update.md` | International CIFP integration, supersession tracking |
| `Changelog.md` / `Releases.md` | Update with recent work |

### Low Priority (stable content)

- `Home.md`, `_Sidebar.md`, `_Footer.md` — update links after Phase 3
- Walkthroughs, FAQ, Getting-Started, Contributing, Code-Style, Testing
- `FMDS-Comparison.md`, `CDM-Connector-Guide.md`, `Navigation-Helper.md`

### Also Audit: `adl/` Docs (11 files)

- `ARCHITECTURE.md`, `DAEMON_SETUP.md` — verify against current daemon structure
- `NAVDATA_IMPORT.md` — verify against current AIRAC pipeline (international CIFP)
- 5 transition summaries (`ETA_AircraftPerf_Transition_Summary.md`, `OOOI_Zone_Detection_Transition_Summary.md`, `Phase5_Weather_Boundaries_Transition_Summary.md`, `Phase5E1_Boundary_Import_Transition_Summary.md`) — archive candidates if shipped
- 3 OOOI docs (`OOOI_Quick_Start.md`, `OOOI_Full_Roadmap.md`, `oooi_enhanced_design_v2.1.md`) — check accuracy

### Fix: Orphaned Wiki Pages

4 wiki pages exist but aren't linked from `_Sidebar.md`:
- `Ground-Stop-Reference.md` — add to Features section
- `TMI-Historical-Import-Statistics.md` — add to Reference section or delete if stale
- `TMI_Status_Demand_Chart_Integration_v2.md` — add to Features or archive
- `analysis-page-format-consistency.md` — add to Analysis or archive

---

## Phase 6: Navigation & Indexing

### Wiki Updates

- Update `wiki/_Sidebar.md`: fix broken links from Phase 3, add orphaned pages, add new features
- Update `wiki/Home.md`: fix links, update feature list with recent additions (vNAS, CTP, international CIFP)

### docs/ Index Creation

- Create `docs/README.md` — master index listing all subdirectories with descriptions:
  ```
  docs/
    admin/       - Funding packets, use cases, organizational docs
    analysis/    - Strategic analyses, audits, comparisons
    audits/      - Code quality audits, dependency maps, verification reports
    infra/       - Azure costs, database upgrades, PowerBI
    operations/  - Deployment, hibernation, status, incidents
    plans/       - Feature design and implementation plans
    reference/   - Technical references (computational, API quick-ref, vNAS ecosystem)
    simulator/   - ATFM simulator design and deployment
    standards/   - Coding standards, naming conventions, patterns
    superpowers/ - AI-assisted design specs and plans
    swim/        - SWIM API documentation and integrations
    tmi/         - TMI system documentation
  ```

- Create `archive/` README files explaining what archived docs are and why they're preserved

### Cross-Reference Verification

- Grep all markdown files for links to moved files; update any remaining broken references
- Verify `CLAUDE.md` internal path references still resolve after all moves

---

## Existing Work to Reference

The `docs/CODEBASE_REORGANIZATION_CATALOG.md` (March 1, 2026) covers broader code reorganization across 1,732 files including scripts, API, load/, and JS directories. Our doc audit is a subset of that scope. The catalog will be moved to `docs/audits/` in Phase 3 and remains relevant for future code reorganization work beyond this documentation effort.

---

## Risk Considerations

- **Git history**: `git mv` preserves history. Use `git mv` for all file moves, never copy+delete.
- **Deployment pipeline**: `.github/workflows/azure-webapp-vatcscc.yml` references `docs/swim/` and `docs/stats/` explicitly. Verify pipeline doesn't reference any moved paths.
- **Wiki sync**: The `wiki/` directory may sync to GitHub wiki. Verify sidebar/home link format works after path changes.
- **CLAUDE.md size**: Already large (~400 lines). vNAS additions should be concise; avoid bloating sections that are already comprehensive.
