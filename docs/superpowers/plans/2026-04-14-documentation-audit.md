# Documentation Audit & Reorganization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Audit, reorganize, and update all ~266 documentation files across the PERTI project for accuracy, navigability, and consolidation.

**Architecture:** Six sequential phases — triage, CLAUDE.md overhaul, file reorganization (74 moves + 8 deletes), spec/plan archival, wiki accuracy audit, and navigation indexing. All file moves use `git mv` to preserve history. Cross-references are updated in the same commit as their corresponding file moves.

**Tech Stack:** Git (file operations), Markdown, Bash

**Spec:** `docs/superpowers/specs/2026-04-14-documentation-audit-design.md`

**User stipulations:**
- Do not prompt the user — work autonomously
- Do not hallucinate — validate everything against the filesystem
- All examples must use real, queried data
- Document findings as you go

---

## Verified Inventory (2026-04-14)

### Root Markdown Files (23)

```
CLAUDE.md                                    → KEEP at root
README.md                                    → KEEP at root
SECURITY.md                                  → KEEP at root
PLAN.md                                      → KEEP at root
CODING_STANDARDS.md                          → docs/standards/
NAMING_CONVENTIONS.md                        → docs/standards/
STYLING_GUIDE.md                             → docs/standards/
CODE_PATTERNS_CATALOG.md                     → docs/standards/
CODE_FIXES_INVENTORY.md                      → docs/standards/
DEPENDENCY_MAP.md                            → docs/standards/
PERTI_MIGRATION_TRACKER.md                   → docs/standards/
advisory_builder_alignment_v1.md             → docs/tmi/
advisory_formatting_spec_for_claude_code.md  → docs/tmi/
GDT_Incremental_Migration.md                → docs/tmi/
GDT_Phase1_Transition.md                    → docs/tmi/
GDT_Unified_Design_Document_v1.1.md         → docs/tmi/
CODE_INCONSISTENCIES.md                      → docs/audits/
CODE_INCONSISTENCIES_EXPLAINED.md            → docs/audits/
ORPHANED_FILES.md                            → docs/audits/
eta_enhancement_transition_summary.md        → docs/plans/archive/
assistant_codebase_index_v18.md              → DELETE
tower_cab_snapshot.md                        → DELETE
TMI_Documentation_Index.md                   → DELETE
```

### docs/ Root Loose Files (51)

All verified via `Glob("docs/*.md")`. See Task 5 for full move table.

### Cross-Reference Map (verified via grep)

Files that link to docs/ root paths that will move:

| Source File | Line | Current Target | New Target |
|-------------|------|----------------|------------|
| `wiki/_Sidebar.md` | 9 | `docs/DEPLOYMENT_GUIDE.md` | `docs/operations/DEPLOYMENT_GUIDE.md` |
| `wiki/_Sidebar.md` | 10 | `docs/COMPUTATIONAL_REFERENCE.md` | `docs/reference/COMPUTATIONAL_REFERENCE.md` |
| `wiki/_Sidebar.md` | 93 | `docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` | `docs/audits/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` |
| `wiki/Home.md` | 15 | `docs/DEPLOYMENT_GUIDE.md` | `docs/operations/DEPLOYMENT_GUIDE.md` |
| `wiki/Home.md` | 16 | `docs/COMPUTATIONAL_REFERENCE.md` | `docs/reference/COMPUTATIONAL_REFERENCE.md` |
| `wiki/Algorithms-Overview.md` | 3 | `docs/COMPUTATIONAL_REFERENCE.md` | `docs/reference/COMPUTATIONAL_REFERENCE.md` |
| `wiki/Analysis.md` | 9 | `docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` | `docs/audits/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` |
| `wiki/Architecture.md` | 571 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `wiki/Troubleshooting.md` | 314 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `wiki/Navigation-Helper.md` | 305 | `docs/QUICK_REFERENCE.md` | `docs/reference/QUICK_REFERENCE.md` |
| `wiki/Getting-Started.md` | 3 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `wiki/FAQ.md` | 243 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `wiki/Deployment.md` | 3 | `docs/DEPLOYMENT_GUIDE.md` | `docs/operations/DEPLOYMENT_GUIDE.md` |
| `wiki/Deployment.md` | 418 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `wiki/Daemons-and-Scripts.md` | 3 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `CLAUDE.md` | 475 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `PLAN.md` | 5 | `docs/I18N_TRACKING.md` | `docs/operations/I18N_TRACKING.md` |
| `README.md` | 359 | `docs/STATUS.md` | `docs/operations/STATUS.md` |
| `README.md` | 360 | `docs/QUICK_REFERENCE.md` | `docs/reference/QUICK_REFERENCE.md` |
| `docs/QUICK_REFERENCE.md` | 5 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `docs/STATUS.md` | 9 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `docs/DEPLOYMENT_GUIDE.md` | 2777 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |
| `docs/refs/codebase-globalization.md` | 346 | `docs/I18N_TRACKING.md` | `docs/operations/I18N_TRACKING.md` |
| `scripts/startup.sh` | 23 | `docs/HIBERNATION_RUNBOOK.md` | `docs/operations/HIBERNATION_RUNBOOK.md` |

### CI/CD Impact

The deployment workflow (`.github/workflows/azure-webapp-vatcscc.yml`) line 44 **excludes ALL** `docs/` then explicitly copies back `docs/swim/*` and `docs/stats/*`. Our reorganization is safe — swim/ files staying in `docs/swim/` (archive subdirectory included in wildcard copy).

---

## Task 1: Delete Stale Files

**Files:**
- Delete: `assistant_codebase_index_v18.md`
- Delete: `tower_cab_snapshot.md`
- Delete: `TMI_Documentation_Index.md`
- Delete: `data/indexes/codebase_index.md`
- Delete: `data/indexes/agent_context.md`
- Delete: `data/indexes/database_schema.md`
- Delete: `data/indexes/database_quick_reference.md`
- Delete: `docs/GDT_Unified_Design_Document_v1.md`

- [ ] **Step 1: Verify each file exists and confirm it's truly deletable**

Run from project root:
```bash
# Verify all 8 files exist
ls -la assistant_codebase_index_v18.md tower_cab_snapshot.md TMI_Documentation_Index.md \
  data/indexes/codebase_index.md data/indexes/agent_context.md \
  data/indexes/database_schema.md data/indexes/database_quick_reference.md \
  docs/GDT_Unified_Design_Document_v1.md
```

Expected: All 8 files listed.

- [ ] **Step 2: Check for any remaining cross-references to these files**

```bash
grep -rl "assistant_codebase_index\|tower_cab_snapshot\|TMI_Documentation_Index\|data/indexes/codebase_index\|data/indexes/agent_context\|data/indexes/database_schema\|data/indexes/database_quick_reference\|docs/GDT_Unified_Design_Document_v1\.md" --include="*.md" --include="*.php" --include="*.js" . | grep -v node_modules | grep -v ".git/"
```

For any hits found: update the reference to remove the link or point to the replacement. Known reference: `TMI_Documentation_Index.md` is referenced from `assistant_codebase_index_v18.md` (also being deleted — no action needed).

- [ ] **Step 3: Delete the files**

```bash
git rm assistant_codebase_index_v18.md
git rm tower_cab_snapshot.md
git rm TMI_Documentation_Index.md
git rm data/indexes/codebase_index.md data/indexes/agent_context.md data/indexes/database_schema.md data/indexes/database_quick_reference.md
git rm docs/GDT_Unified_Design_Document_v1.md
```

- [ ] **Step 4: Commit**

```bash
git commit -m "docs: delete 8 stale/superseded documentation files

Remove AI-generated indexes (data/indexes/*, assistant_codebase_index_v18),
browser accessibility dump (tower_cab_snapshot), stale TMI index
(TMI_Documentation_Index - replaced by docs/tmi/README.md), and
superseded GDT design doc v1 (replaced by root v1.1).

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Create New Directory Structure

**Directories to create:**
- `docs/standards/`
- `docs/operations/`
- `docs/simulator/`
- `docs/admin/`
- `docs/infra/`
- `docs/plans/archive/`
- `docs/superpowers/specs/archive/`
- `docs/superpowers/plans/archive/`
- `docs/swim/archive/`
- `docs/tmi/archive/`

- [ ] **Step 1: Verify which directories already exist**

```bash
ls -d docs/standards docs/operations docs/simulator docs/admin docs/infra \
  docs/plans/archive docs/superpowers/specs/archive docs/superpowers/plans/archive \
  docs/swim/archive docs/tmi/archive 2>&1
```

Expected: All should say "No such file or directory" (none exist yet). `docs/reference/` and `docs/audits/` and `docs/analysis/` already exist.

- [ ] **Step 2: Create directories with .gitkeep files**

Git doesn't track empty directories, so add `.gitkeep` placeholder files:

```bash
mkdir -p docs/standards docs/operations docs/simulator docs/admin docs/infra \
  docs/plans/archive docs/superpowers/specs/archive docs/superpowers/plans/archive \
  docs/swim/archive docs/tmi/archive

touch docs/standards/.gitkeep docs/operations/.gitkeep docs/simulator/.gitkeep \
  docs/admin/.gitkeep docs/infra/.gitkeep \
  docs/plans/archive/.gitkeep docs/superpowers/specs/archive/.gitkeep \
  docs/superpowers/plans/archive/.gitkeep docs/swim/archive/.gitkeep \
  docs/tmi/archive/.gitkeep

git add docs/standards/.gitkeep docs/operations/.gitkeep docs/simulator/.gitkeep \
  docs/admin/.gitkeep docs/infra/.gitkeep \
  docs/plans/archive/.gitkeep docs/superpowers/specs/archive/.gitkeep \
  docs/superpowers/plans/archive/.gitkeep docs/swim/archive/.gitkeep \
  docs/tmi/archive/.gitkeep
```

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: create directory structure for documentation reorganization

New directories: standards/, operations/, simulator/, admin/, infra/
Archive directories: plans/archive/, superpowers/specs/archive/,
superpowers/plans/archive/, swim/archive/, tmi/archive/

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Move Root Files (19 moves + cross-ref updates)

**Files:**
- Move: 7 files to `docs/standards/`
- Move: 5 files to `docs/tmi/`
- Move: 3 files to `docs/audits/`
- Move: 1 file to `docs/plans/archive/`
- Modify: `PLAN.md` (line 5 — I18N_TRACKING ref will be fixed in Task 5)

- [ ] **Step 1: Move 7 files to docs/standards/**

```bash
git mv CODING_STANDARDS.md docs/standards/
git mv NAMING_CONVENTIONS.md docs/standards/
git mv STYLING_GUIDE.md docs/standards/
git mv CODE_PATTERNS_CATALOG.md docs/standards/
git mv CODE_FIXES_INVENTORY.md docs/standards/
git mv DEPENDENCY_MAP.md docs/standards/
git mv PERTI_MIGRATION_TRACKER.md docs/standards/
```

- [ ] **Step 2: Move 5 files to docs/tmi/**

```bash
git mv advisory_builder_alignment_v1.md docs/tmi/
git mv advisory_formatting_spec_for_claude_code.md docs/tmi/
git mv GDT_Incremental_Migration.md docs/tmi/
git mv GDT_Phase1_Transition.md docs/tmi/
git mv GDT_Unified_Design_Document_v1.1.md docs/tmi/
```

- [ ] **Step 3: Move 3 files to docs/audits/**

```bash
git mv CODE_INCONSISTENCIES.md docs/audits/
git mv CODE_INCONSISTENCIES_EXPLAINED.md docs/audits/
git mv ORPHANED_FILES.md docs/audits/
```

- [ ] **Step 4: Archive 1 transition file**

```bash
git mv eta_enhancement_transition_summary.md docs/plans/archive/
```

- [ ] **Step 5: Commit root moves**

```bash
git commit -m "docs: move 16 root markdown files to docs/ subdirectories

standards/ (7): CODING_STANDARDS, NAMING_CONVENTIONS, STYLING_GUIDE,
  CODE_PATTERNS_CATALOG, CODE_FIXES_INVENTORY, DEPENDENCY_MAP,
  PERTI_MIGRATION_TRACKER
tmi/ (5): advisory_builder_alignment_v1, advisory_formatting_spec,
  GDT_Incremental_Migration, GDT_Phase1_Transition,
  GDT_Unified_Design_Document_v1.1
audits/ (3): CODE_INCONSISTENCIES, CODE_INCONSISTENCIES_EXPLAINED,
  ORPHANED_FILES
plans/archive/ (1): eta_enhancement_transition_summary

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Move docs/ Root Files — Batch A (simulator + admin + infra = 20 files)

These files have no known cross-references, so they can be moved without link updates.

- [ ] **Step 1: Move 4 simulator files**

```bash
git mv docs/ATFM_Simulator_Design_Document_v1.md docs/simulator/
git mv docs/ATFM_Simulator_Deployment_2026-01-12.md docs/simulator/
git mv docs/ATFM_Simulator_Phase1_GroundStop_2026-01-12.md docs/simulator/
git mv docs/ATFM_Simulator_Phase1_5_TrafficGen_2026-01-12.md docs/simulator/
```

- [ ] **Step 2: Move 12 admin files**

```bash
git mv docs/FUND_AIR_CANADA_FOUNDATION.md docs/admin/
git mv docs/FUND_CISCO_CSR.md docs/admin/
git mv docs/FUND_MSFT_PARTNER.md docs/admin/
git mv docs/FUND_FFWD_APPLICATION.md docs/admin/
git mv docs/FUND_RAY_FOUNDATION.md docs/admin/
git mv docs/FUND_MSFT_TSI.md docs/admin/
git mv docs/FUND_FAA_NOFO20-01_WHITEPAPER.md docs/admin/
git mv docs/FUND_GTIA_GIVES.md docs/admin/
git mv docs/FUNDING_PACKET.md docs/admin/
git mv docs/FUNDING_PACKET_FILLED.md docs/admin/
git mv docs/vATCSCC_Use_Case_Digestible_v4.md docs/admin/
git mv docs/vATCSCC_Use_Case_Digestible_v5.md docs/admin/
```

- [ ] **Step 3: Move 4 infra files**

```bash
git mv docs/AZURE_COST_OPTIMIZATION_ANALYSIS.md docs/infra/
git mv docs/mysql-8-upgrade-analysis.md docs/infra/
git mv docs/POWERBI_DATAFLOW_UPDATE.md docs/infra/
git mv docs/VATSIM_STATS_DATABASE.md docs/infra/
```

- [ ] **Step 4: Commit**

```bash
git commit -m "docs: move 20 files to simulator/, admin/, infra/

simulator/ (4): ATFM design, deployment, Phase1 ground stop, Phase1.5 traffic gen
admin/ (12): 8 FUND_* funding packets, 2 FUNDING_PACKET*, 2 vATCSCC use cases
infra/ (4): Azure cost analysis, MySQL 8 upgrade, PowerBI, VATSIM_STATS

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Move docs/ Root Files — Batch B (operations + reference + audits + tmi + adl + analysis = 30 files + cross-ref updates)

This batch includes files with cross-references that must be updated simultaneously.

- [ ] **Step 1: Move 7 files to docs/operations/**

```bash
git mv docs/DEPLOYMENT_GUIDE.md docs/operations/
git mv docs/HIBERNATION_RUNBOOK.md docs/operations/
git mv docs/STATUS.md docs/operations/
git mv docs/PERFORMANCE_OPTIMIZATION.md docs/operations/
git mv docs/ctp-pull-sync-go-live.md docs/operations/
git mv docs/adl-ingest-outage-2026-02-17-claude-brief.md docs/operations/
git mv docs/I18N_TRACKING.md docs/operations/
```

- [ ] **Step 2: Move 2 files to docs/reference/**

```bash
git mv docs/COMPUTATIONAL_REFERENCE.md docs/reference/
git mv docs/QUICK_REFERENCE.md docs/reference/
```

- [ ] **Step 3: Move 9 files to docs/audits/**

```bash
git mv docs/UI_CONSISTENCY_AUDIT_2026-03-06.md docs/audits/
git mv docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md docs/audits/
git mv docs/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md docs/audits/
git mv docs/CHANGE_IMPACT_DEPENDENCY_MAP_2026-03-14.md docs/audits/
git mv docs/DOCUMENTED_FINDINGS_VERIFICATION_2026-03-14.md docs/audits/
git mv docs/swim-api-audit-2026-03-23.md docs/audits/
git mv docs/ui-audit-2026-02-09.md docs/audits/
git mv docs/fr-ca-localization-audit-for-claude.md docs/audits/
git mv docs/CODEBASE_REORGANIZATION_CATALOG.md docs/audits/
```

- [ ] **Step 4: Move 4 files to docs/tmi/**

```bash
git mv docs/GDT_GS_Transition_Summary_20260110.md docs/tmi/
git mv docs/gdt-tmi-workflow-plan.md docs/tmi/
git mv docs/TMIDiscord_Formatting_Transition_v3.2.md docs/tmi/
git mv docs/CANOC_ADVISORY_INTEGRATION.md docs/tmi/
```

- [ ] **Step 5: Move 5 files to adl/ (project root)**

```bash
git mv docs/ADL_REFRESH_MIGRATION_GUIDE.md adl/
git mv docs/planned_crossings_performance.md adl/
git mv docs/route_distance_transition.md adl/
git mv docs/simbrief_parsing_summary.md adl/
git mv docs/simbrief_parsing_transition.md adl/
```

- [ ] **Step 6: Move 3 files to docs/analysis/**

```bash
git mv docs/ctp-sample-scenario-april2026.md docs/analysis/
git mv docs/CTP_EXTERNAL_REPO_AUDIT_AND_INTEGRATION.md docs/analysis/
git mv docs/fmds-comparison.md docs/analysis/
```

- [ ] **Step 7: Update ALL cross-references**

Update every file that references a moved path. Each edit must change the old path to the new path.

**wiki/_Sidebar.md** — 3 link updates:
- Line 9: `docs/DEPLOYMENT_GUIDE.md` → `docs/operations/DEPLOYMENT_GUIDE.md`
- Line 10: `docs/COMPUTATIONAL_REFERENCE.md` → `docs/reference/COMPUTATIONAL_REFERENCE.md`
- Line 93: `docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` → `docs/audits/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md`

**wiki/Home.md** — 2 link updates:
- Line 15: `docs/DEPLOYMENT_GUIDE.md` → `docs/operations/DEPLOYMENT_GUIDE.md`
- Line 16: `docs/COMPUTATIONAL_REFERENCE.md` → `docs/reference/COMPUTATIONAL_REFERENCE.md`

**wiki/Algorithms-Overview.md** — 1 link update:
- Line 3: `docs/COMPUTATIONAL_REFERENCE.md` → `docs/reference/COMPUTATIONAL_REFERENCE.md`

**wiki/Analysis.md** — 1 link update:
- Line 9: `docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md` → `docs/audits/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md`

**wiki/Architecture.md** — 1 ref update:
- Line 571: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**wiki/Troubleshooting.md** — 1 ref update:
- Line 314: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**wiki/Navigation-Helper.md** — 1 ref update:
- Line 305: `docs/QUICK_REFERENCE.md` → `docs/reference/QUICK_REFERENCE.md`

**wiki/Getting-Started.md** — 1 ref update:
- Line 3: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**wiki/FAQ.md** — 1 ref update:
- Line 243: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**wiki/Deployment.md** — 2 ref updates:
- Line 3: `docs/DEPLOYMENT_GUIDE.md` → `docs/operations/DEPLOYMENT_GUIDE.md`
- Line 418: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**wiki/Daemons-and-Scripts.md** — 1 ref update:
- Line 3: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**CLAUDE.md** — 1 ref update:
- Line 475: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**PLAN.md** — 1 ref update:
- Line 5: `docs/I18N_TRACKING.md` → `docs/operations/I18N_TRACKING.md`

**README.md** — 2 ref updates:
- Line 359: `docs/STATUS.md` → `docs/operations/STATUS.md`
- Line 360: `docs/QUICK_REFERENCE.md` → `docs/reference/QUICK_REFERENCE.md`

**docs/operations/QUICK_REFERENCE.md** (already moved) — 1 ref update:
- Line 5: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**docs/operations/STATUS.md** (already moved) — 1 ref update:
- Line 9: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**docs/operations/DEPLOYMENT_GUIDE.md** (already moved) — 1 ref update:
- Line 2777: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

**docs/refs/codebase-globalization.md** — 1 ref update:
- Line 346: `docs/I18N_TRACKING.md` → `docs/operations/I18N_TRACKING.md`

**scripts/startup.sh** — 1 comment update:
- Line 23: `docs/HIBERNATION_RUNBOOK.md` → `docs/operations/HIBERNATION_RUNBOOK.md`

- [ ] **Step 8: Verify no docs/ root orphans remain**

```bash
ls docs/*.md
```

Expected: Zero `.md` files directly in `docs/`. All 51 should be relocated.

- [ ] **Step 9: Commit**

```bash
git commit -m "docs: move 30 docs/ root files to subdirectories, update 24 cross-references

operations/ (7): DEPLOYMENT_GUIDE, HIBERNATION_RUNBOOK, STATUS,
  PERFORMANCE_OPTIMIZATION, ctp-pull-sync-go-live, adl-ingest-outage, I18N_TRACKING
reference/ (2): COMPUTATIONAL_REFERENCE, QUICK_REFERENCE
audits/ (9): UI_CONSISTENCY_AUDIT, ETA_ACCURACY_ANALYSIS, OPERATIONAL_ANALYSIS,
  CHANGE_IMPACT, DOCUMENTED_FINDINGS, swim-api-audit, ui-audit,
  fr-ca-localization-audit, CODEBASE_REORGANIZATION_CATALOG
tmi/ (4): GDT_GS_Transition, gdt-tmi-workflow, TMIDiscord_Formatting, CANOC_ADVISORY
adl/ (5): ADL_REFRESH_MIGRATION, planned_crossings_performance,
  route_distance_transition, simbrief_parsing_summary, simbrief_parsing_transition
analysis/ (3): ctp-sample-scenario, CTP_EXTERNAL_REPO, fmds-comparison

Updated 24 cross-references in wiki/, CLAUDE.md, README.md, PLAN.md,
startup.sh, and 3 relocated docs.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 6: Archive Shipped docs/plans/ (23 files)

- [ ] **Step 1: Move 23 shipped plans to archive**

```bash
git mv docs/plans/2026-02-02-tmi-compliance-ui-redesign.md docs/plans/archive/
git mv docs/plans/2026-02-02-tmi-compliance-implementation.md docs/plans/archive/
git mv docs/plans/2026-02-02-demand-chart-ui-design.md docs/plans/archive/
git mv docs/plans/2026-02-07-gs-reroute-compliance-design.md docs/plans/archive/
git mv docs/plans/2026-02-07-gs-reroute-implementation-plan.md docs/plans/archive/
git mv docs/plans/2026-02-02-codebase-globalization.md docs/plans/archive/
git mv docs/plans/2026-02-02-norad-codes-design.md docs/plans/archive/
git mv docs/plans/2026-02-02-perti-namespace-design.md docs/plans/archive/
git mv docs/plans/2026-02-08-airport-groupings-design.md docs/plans/archive/
git mv docs/plans/2026-02-08-airport-groupings-implementation.md docs/plans/archive/
git mv docs/plans/2026-02-09-nod-tmi-facility-flows-design.md docs/plans/archive/
git mv docs/plans/2026-02-11-gdt-workflow-enhancement-design.md docs/plans/archive/
git mv docs/plans/2026-02-02-tmi-trajectory.md docs/plans/archive/
git mv docs/plans/2026-02-15-vatcan-interop-design.md docs/plans/archive/
git mv docs/plans/2026-02-15-vatcan-interop-implementation.md docs/plans/archive/
git mv docs/plans/2026-02-16-holding-detection-design.md docs/plans/archive/
git mv docs/plans/2026-02-16-i18n-site-wide-design.md docs/plans/archive/
git mv docs/plans/2026-02-16-i18n-site-wide-implementation.md docs/plans/archive/
git mv docs/plans/2026-02-16-holding-detection.md docs/plans/archive/
git mv docs/plans/2026-02-17-perti-plans-homepage-design.md docs/plans/archive/
git mv docs/plans/2026-02-17-perti-plans-homepage-implementation.md docs/plans/archive/
git mv docs/plans/2026-03-20-pipeline-alignment-design.md docs/plans/archive/
git mv docs/plans/2026-03-20-pipeline-alignment-implementation.md docs/plans/archive/
```

- [ ] **Step 2: Verify active plans remain**

```bash
ls docs/plans/*.md
```

Expected 11 active plans remaining:
- `2026-02-02-adl-raw-data-lake-design.md`
- `2026-03-29-variable-rate-gdp-design.md`
- `2026-03-29-variable-rate-gdp-implementation.md`
- `2026-03-30-playbook-performance-audit.md`
- `2026-03-30-two-pass-disambiguation-design.md`
- `2026-03-30-two-pass-disambiguation-implementation.md`
- `2026-03-30-vatswim-client-bridges-design.md`
- `2026-03-30-vatswim-client-bridges-plan.md`
- `2026-04-04-swim-reroute-parity-fixes.md`
- `2026-04-07-vnas-reference-sync-design.md`
- `2026-04-07-vnas-reference-sync-plan.md`

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: archive 23 shipped plans (+ 1 from root = 24 total in archive)

All Feb-Mar 2026 plans whose features are deployed on main:
TMI compliance, demand chart, GS reroute, codebase globalization,
NORAD codes, PERTI namespace, airport groupings, NOD TMI facility flows,
GDT workflow, TMI trajectory, VATCAN interop, holding detection,
i18n site-wide, PERTI plans homepage, pipeline alignment

11 active/planned plans remain in docs/plans/.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 7: Archive Shipped Superpowers Specs & Plans (26 files)

- [ ] **Step 1: Archive 15 shipped specs**

```bash
git mv docs/superpowers/specs/2026-03-12-ctp-e26-integration-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-13-swim-playbook-cdr-routes-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-15-atfcm-subfields-asrt-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-18-fix-label-drag-positioning-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-18-playbook-compound-filter-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-18-route-symbology-filter-icons-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-19-route-pipeline-divergence-audit.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-22-ctp-api-vatswim-integration.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-23-ctp-playbook-sync-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-24-playbook-facility-counts-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-26-ctp-pull-sync-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-29-demand-page-enhancements-design.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-29-documentation-audit-findings.md docs/superpowers/specs/archive/
git mv docs/superpowers/specs/2026-03-30-tmi-operations-analytics-design.md docs/superpowers/specs/archive/
```

- [ ] **Step 2: Archive 11 shipped plans**

```bash
git mv docs/superpowers/plans/2026-03-15-atfcm-subfields-asrt.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-18-playbook-compound-filter.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-18-route-symbology-filter-icons.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-19-airac-pipeline-fix-verification.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-19-airac-pipeline-fix.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-21-ctp-swim-nat-track-throughput.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-23-ctp-playbook-sync.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-24-playbook-facility-counts.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-26-ctp-pull-sync.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-29-demand-page-enhancements.md docs/superpowers/plans/archive/
git mv docs/superpowers/plans/2026-03-30-tmi-operations-analytics.md docs/superpowers/plans/archive/
```

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: archive 26 shipped superpowers specs and plans

specs/archive/ (15): CTP E26, SWIM playbook/CDR, ATFCM subfields,
  fix label drag, playbook compound filter, route symbology,
  route pipeline divergence, CTP SWIM NAT, CTP API integration,
  CTP playbook sync, playbook facility counts, CTP pull sync,
  demand page enhancements, documentation audit findings,
  TMI operations analytics
plans/archive/ (11): matching implementation plans for above specs

12 active specs and 9 active plans remain.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 8: Archive SWIM & TMI Transition Files (18 files)

- [ ] **Step 1: Archive 11 SWIM files**

```bash
git mv docs/swim/SWIM_Session_Transition_20260115.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116_v2.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116_Phase1Complete.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116_Phase2Complete.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116_Phase2Start.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116_SDKComplete.md docs/swim/archive/
git mv docs/swim/SWIM_Session_Transition_20260116_AOCTelemetry.md docs/swim/archive/
git mv docs/swim/SWIM_Phase2_Phase3_Transition.md docs/swim/archive/
git mv docs/swim/SWIM_Phase2_RealTime_Design.md docs/swim/archive/
git mv docs/swim/Claude_VATSWIM_Implementation_Prompt_2026-03-14.md docs/swim/archive/
```

Note: `SWIM_Session_Transition_20260116_Phase2Start.md` was not in the original spec but is a session transition file — archiving it too.

- [ ] **Step 2: Archive 7 TMI files**

```bash
git mv docs/tmi/TMI_Publisher_v1.6_Transition.md docs/tmi/archive/
git mv docs/tmi/TMI_Publisher_v1.7_Transition.md docs/tmi/archive/
git mv docs/tmi/TMI_Publisher_v1.8.0_Transition.md docs/tmi/archive/
git mv docs/tmi/TMI_Publisher_v1.9.0_Transition.md docs/tmi/archive/
git mv docs/tmi/GS_Eligibility_Fix_Transition.md docs/tmi/archive/
git mv docs/tmi/SESSION_TRANSITION_20260117.md docs/tmi/archive/
git mv docs/tmi/GDT_Session_20260121.md docs/tmi/archive/
```

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: archive 18 SWIM and TMI session transition files

swim/archive/ (11): 7 session transitions (Jan 2026), Phase2/3 transition,
  Phase2 real-time design, Claude implementation prompt
  (includes Phase2Start.md not in original spec — also a session transition)
tmi/archive/ (7): TMI Publisher v1.6-v1.9 transitions, GS eligibility fix,
  session transition, GDT session

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 9: CLAUDE.md Overhaul — vNAS Integration & Corrections

**Files:**
- Modify: `CLAUDE.md`

This task updates CLAUDE.md with vNAS integration data and fixes verified inaccuracies.

- [ ] **Step 1: Add vNAS terms to Domain Glossary**

In the `### Domain Glossary` table, add after the `CTP` row:

```markdown
| **vNAS** | Virtual National Airspace System — VATSIM's centralized ATC facility/position configuration |
| **CRC** | Combined Radar Controller — vNAS ATC client application |
| **TCP** | Terminal Control Position — STARS radar position entry in vNAS |
| **ULID** | Universally Unique Lexicographically Sortable Identifier — used for vNAS position IDs |
```

- [ ] **Step 2: Add vNAS tables to Database Schema**

In the `#### VATSIM_ADL` section, after the `Events:` line and before `Discord:`, add:

```markdown
- vNAS: `vnas_facilities`, `vnas_positions`, `vnas_stars_tcps`, `vnas_stars_areas`, `vnas_beacon_banks`, `vnas_transceivers`, `vnas_video_map_index`, `vnas_airport_groups`, `vnas_restrictions`
```

- [ ] **Step 3: Add vNAS API endpoints**

In the API endpoint table, add a row after `/api/gis/`:

```markdown
| `/api/swim/v1/ingest/vnas/` | vNAS integration: `facilities`, `restrictions`, `controllers`, `handoff`, `tags`, `track` |
```

- [ ] **Step 4: Add missing daemons**

In the **Conditional daemons** table, add these 3 rows:

```markdown
| Delay Attribution | `scripts/tmi/delay_attribution_daemon.php` | 60s | Per-flight delay computation from EDCT/OOOI baselines |
| Facility Stats | `scripts/tmi/facility_stats_daemon.php` | 3600s | Hourly/daily facility statistics from flight data |
| Webhook Delivery | `scripts/webhook_delivery_daemon.php` | Continuous | Outbound event webhook delivery queue |
```

Add to the **Conditional daemons** table footnote or after the vACDM row:

```markdown
| vNAS Controller Poll | `scripts/vnas_controller_poll.php` | 60s | **Pending** — commented out in startup.sh (awaits migration 024) |
```

- [ ] **Step 5: Update JS module count**

Change `71+` to `79` in the JavaScript section. The verified count: 62 root + 12 lib/ + 5 config/ = 79.

- [ ] **Step 6: Add vNAS migrations**

In the migrations section, add `vnas/` to the list of migration areas:

```
Migrations under `database/migrations/` by area: `tmi/`, `swim/`, `schema/`, `postgis/`, `vnas/`, `gdp/`, ...
```

- [ ] **Step 7: Add vNAS Python scripts**

In the Python Scripts section, add:

```markdown
- `scripts/vnas_sync/crc_parser.py` - Parse vNAS CRC facility configuration JSON
- `scripts/vnas_sync/vnas_crc_watcher.py` - Watch for vNAS CRC data updates
```

- [ ] **Step 8: Add vNAS gotchas**

In the Gotchas section, add:

```markdown
- **vNAS ULID matching**: Live controller feed uses ULIDs that match `vnas_positions.position_ulid` exactly — no transformation needed.
- **splits_presets.artcc width**: Was `CHAR(3)`, too narrow for 4-char international FIR codes (e.g., `CZYZ`) — widened to `NVARCHAR(4)` in migration `schema/009_splits_column_widening.sql`.
- **Schema migration 009 collision**: Two files share number 009 — `schema/009_splits_column_widening.sql` and `gdp/009_scheduler_state.sql`. Both deployed; no conflict since they target different databases.
```

- [ ] **Step 9: Fix HIBERNATION_RUNBOOK path**

The CLAUDE.md reference on line 475 was already updated in Task 5 step 7. Verify it reads:
```
**Procedures**: See `docs/operations/HIBERNATION_RUNBOOK.md`.
```

- [ ] **Step 10: Commit**

```bash
git commit -m "docs(CLAUDE.md): add vNAS integration, fix daemon list, update JS count

- Add vNAS/CRC/TCP/ULID to domain glossary
- Add 9 vnas_* tables to VATSIM_ADL schema section
- Add 6 vNAS API endpoints
- Add 3 missing daemons (delay_attribution, facility_stats, webhook_delivery)
- Note vnas_controller_poll as pending (commented out, awaits migration 024)
- Update JS module count from 71+ to 79 (verified)
- Add vnas/ to migration areas
- Add vNAS Python scripts
- Add vNAS gotchas (ULID matching, splits CHAR(3), migration 009 collision)

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 10: Wiki Accuracy Audit — High Priority Pages

**Files to audit and update:**
- `wiki/Daemons-and-Scripts.md`
- `wiki/Database-Schema.md`
- `wiki/Architecture.md`
- `wiki/API-Reference.md`
- `wiki/Acronyms.md`

These are the wiki pages most likely to be stale. Audit each against current codebase state.

- [ ] **Step 1: Audit wiki/Daemons-and-Scripts.md**

Read the file and compare its daemon list against `scripts/startup.sh` (already read — 457 lines). Check for:
- Missing daemons: `delay_attribution_daemon.php`, `facility_stats_daemon.php`, `webhook_delivery_daemon.php`
- Pending daemon: `vnas_controller_poll.php` (commented out)
- Interval changes: SimTraffic poll changed from 2min to 10min
- Conditional daemons section
- Update hibernation date if it says March 22 (should be March 29/30)

Apply fixes directly to the file.

- [ ] **Step 2: Audit wiki/Database-Schema.md**

Read the file and check for:
- Normalized 8-table architecture (`adl_flight_core`, etc.)
- vNAS tables (9 `vnas_*` tables)
- Updated row counts where mentioned
- TMI operations analytics tables (migration 055)
- CDM tables
- CTP tables

Apply fixes directly.

- [ ] **Step 3: Audit wiki/Architecture.md**

Read the file and check for:
- Daemon list matches startup.sh
- Data flow diagram accuracy
- vNAS integration mentioned
- Hibernation status/date correct

Apply fixes directly.

- [ ] **Step 4: Audit wiki/API-Reference.md**

Read the file and check for:
- vNAS endpoints (`/api/swim/v1/ingest/vnas/`)
- GDT endpoints (`/api/gdt/`)
- CTP endpoints (`/api/ctp/`)
- Demand endpoints (`/api/demand/`)
- GIS endpoints (`/api/gis/`)
- Routes endpoints (`/api/routes/`)

Apply fixes directly.

- [ ] **Step 5: Audit wiki/Acronyms.md**

Read the file and sync with CLAUDE.md glossary. Add any missing terms:
- vNAS, CRC, TCP, ULID (added to CLAUDE.md in Task 9)
- Any other terms present in CLAUDE.md but missing from wiki

Apply fixes directly.

- [ ] **Step 6: Commit wiki high-priority updates**

```bash
git commit -m "docs(wiki): update high-priority pages for accuracy

Audit Daemons-and-Scripts, Database-Schema, Architecture,
API-Reference, and Acronyms against current codebase state.
Add vNAS integration, missing daemons, fix stale counts/dates.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 11: Wiki Accuracy Audit — Medium Priority Pages

- [ ] **Step 1: Audit wiki/GDT-Ground-Delay-Tool.md and wiki/GDT-Reference.md**

Check for GDP algorithm redesign changes (FPFS+RBD hybrid, compression, reoptimization).

- [ ] **Step 2: Audit wiki/Playbook.md**

Check for spatial validation, compound filters, geometry backfill, international CIFP procedures.

- [ ] **Step 3: Audit wiki/AIRAC-Update.md**

Check for international CIFP integration, supersession tracking, transition_type column.

- [ ] **Step 4: Audit wiki/Splits.md**

Check for column widening (CHAR(3) → NVARCHAR(4)) for international FIR codes.

- [ ] **Step 5: Audit wiki/Changelog.md**

Add entries for recent work: vNAS integration, AIRAC 2603, international CIFP, GDP algorithm redesign, TMI operations analytics, playbook spatial validation, route analysis improvements.

- [ ] **Step 6: Commit medium-priority wiki updates**

```bash
git commit -m "docs(wiki): update medium-priority pages

Audit GDT, Playbook, AIRAC-Update, Splits, Changelog for accuracy.
Add GDP algorithm redesign, CIFP integration, spatial validation,
FIR code widening, and recent changelog entries.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 12: Wiki Navigation & Orphan Fixes

**Files:**
- Modify: `wiki/_Sidebar.md`
- Modify: `wiki/Home.md`

- [ ] **Step 1: Fix orphaned wiki pages**

Add to `wiki/_Sidebar.md`:
- `[[Ground Stop Reference]]` — add to Features section (after GDT Reference)
- `[[TMI Historical Import Statistics]]` — add to Reference section (if still relevant; delete if completely stale)
- `[[TMI Status Demand Chart Integration v2]]` — add to Features section or archive if stale
- `[[analysis-page-format-consistency]]` — add to Analysis section or archive if stale

Read each orphaned page first to determine if it's still relevant.

- [ ] **Step 2: Add new features to sidebar**

Add to Features section:
- `[[CDM]]` (CDM dashboard — `cdm.php`)
- `[[CTP]]` (Collaborative Traffic Planning — `ctp.php`)
- `[[Reroutes]]` (Reroute management)
- `[[Demand Analysis]]` (Demand charts — `demand.php`)
- `[[RAD]]` (Route Amendment Dialogue — if page exists)
- `[[Historical Routes]]` (Route history analysis — `historical-routes.php`)

- [ ] **Step 3: Update wiki/Home.md**

Update the version section to reflect current state:
- Version: v19 → check if version has changed
- Update feature list with recent additions (vNAS, international CIFP, TMI operations analytics)
- Update system scale numbers where possible (query databases for current counts)
- Fix "Last updated" date

- [ ] **Step 4: Commit**

```bash
git commit -m "docs(wiki): fix orphaned pages, add new features to sidebar, update Home

Add Ground Stop Reference, CDM, CTP, Reroutes, Demand Analysis to sidebar.
Link orphaned pages or archive stale ones. Update Home.md version info
and system scale numbers.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 13: Create docs/README.md Master Index

**Files:**
- Create: `docs/README.md`
- Create: `docs/plans/archive/README.md`
- Create: `docs/superpowers/specs/archive/README.md`

- [ ] **Step 1: Create docs/README.md**

```markdown
# PERTI Documentation

## Directory Structure

| Directory | Contents |
|-----------|----------|
| `admin/` | Funding packets, use cases, organizational documents |
| `analysis/` | Strategic analyses, CTP scenarios, FMDS comparison |
| `audits/` | Code quality audits, dependency maps, UI audits, reorganization catalog |
| `infra/` | Azure cost optimization, MySQL 8 upgrade, PowerBI, VATSIM_STATS |
| `operations/` | Deployment guide, hibernation runbook, status, incidents, i18n tracking |
| `plans/` | Feature design and implementation plans (active) |
| `plans/archive/` | Shipped feature plans (preserved for reference) |
| `reference/` | Technical references — computational algorithms, API quick-ref, vNAS ecosystem |
| `simulator/` | ATFM training simulator design and deployment docs |
| `standards/` | Coding standards, naming conventions, patterns, migration tracker |
| `superpowers/` | AI-assisted design specs and implementation plans |
| `swim/` | VATSWIM API documentation, integration guides, data standards |
| `tmi/` | TMI system docs — GDT, Discord, publisher, coordination |

## Key Entry Points

- **New to PERTI?** Start with the [wiki](../wiki/Home.md)
- **Deploying?** See [Deployment Guide](operations/DEPLOYMENT_GUIDE.md)
- **Algorithm details?** See [Computational Reference](reference/COMPUTATIONAL_REFERENCE.md)
- **API consumers?** See [SWIM README](swim/README.md) or [API Quick Reference](reference/QUICK_REFERENCE.md)
- **TMI system?** See [TMI README](tmi/README.md)

## Archive Policy

`archive/` subdirectories contain shipped specs, plans, and session transition files.
These are preserved for historical reference but are no longer actively maintained.
A spec/plan is archived when its feature is deployed to production on `main`.
```

- [ ] **Step 2: Create archive README files**

Create `docs/plans/archive/README.md`:
```markdown
# Archived Plans

Shipped feature design and implementation plans. These features are deployed on `main`.
Preserved for historical reference — not actively maintained.

Archived on: 2026-04-14
```

Create `docs/superpowers/specs/archive/README.md`:
```markdown
# Archived Specs

Shipped AI-assisted design specifications. These features are deployed on `main`.
Preserved for historical reference — not actively maintained.

Archived on: 2026-04-14
```

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: create README.md master index and archive READMEs

docs/README.md — directory structure guide with key entry points
docs/plans/archive/README.md — archive policy note
docs/superpowers/specs/archive/README.md — archive policy note

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 14: Final Cross-Reference Sweep & Validation

- [ ] **Step 1: Search for any remaining broken links**

```bash
# Search for references to docs/ root files that should no longer exist there
grep -rn "docs/DEPLOYMENT_GUIDE\.md\|docs/COMPUTATIONAL_REFERENCE\.md\|docs/HIBERNATION_RUNBOOK\.md\|docs/STATUS\.md\|docs/QUICK_REFERENCE\.md\|docs/ETA_ACCURACY_ANALYSIS\|docs/I18N_TRACKING\.md\|docs/PERFORMANCE_OPTIMIZATION" \
  --include="*.md" --include="*.php" --include="*.js" --include="*.sh" . \
  | grep -v node_modules | grep -v ".git/" | grep -v "docs/operations/" | grep -v "docs/reference/" | grep -v "docs/audits/" | grep -v "archive/"
```

Fix any remaining references found.

- [ ] **Step 2: Verify file counts**

```bash
echo "=== Root .md files ===" && find . -maxdepth 1 -name "*.md" | wc -l
echo "=== docs/ root .md files ===" && find docs -maxdepth 1 -name "*.md" | wc -l
echo "=== docs/standards/ ===" && ls docs/standards/*.md | wc -l
echo "=== docs/operations/ ===" && ls docs/operations/*.md | wc -l
echo "=== docs/audits/ ===" && ls docs/audits/*.md | wc -l
echo "=== docs/plans/ (active) ===" && ls docs/plans/*.md | wc -l
echo "=== docs/plans/archive/ ===" && ls docs/plans/archive/*.md | wc -l
```

Expected:
- Root: 4 (CLAUDE, README, SECURITY, PLAN)
- docs/ root: 1 (README.md only)
- standards: 7
- operations: 7
- audits: 10 (1 existing + 9 moved)
- plans active: 11
- plans archive: 24 (23 + 1 from root)

- [ ] **Step 3: Commit any remaining fixes**

```bash
git commit -m "docs: final cross-reference sweep and validation

Fix any remaining broken links discovered during validation pass.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Summary

| Task | Files Changed | Type |
|------|--------------|------|
| 1 | 8 deleted | Cleanup |
| 2 | 10 .gitkeep created | Structure |
| 3 | 16 moved | Root reorganization |
| 4 | 20 moved | docs/ batch A (no cross-refs) |
| 5 | 30 moved + 24 cross-ref updates | docs/ batch B (with cross-refs) |
| 6 | 23 archived | Plans archival |
| 7 | 26 archived | Specs/plans archival |
| 8 | 18 archived | SWIM/TMI archival |
| 9 | 1 modified (CLAUDE.md) | vNAS + corrections |
| 10 | 5 wiki pages updated | High-priority audit |
| 11 | 5+ wiki pages updated | Medium-priority audit |
| 12 | 2 wiki pages updated | Navigation fixes |
| 13 | 3 new READMEs | Indexing |
| 14 | Validation sweep | Final check |

**Total**: ~141 files moved/archived, 8 deleted, 3 created, 30+ modified, 14 commits
