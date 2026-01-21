# PERTI Codebase Reorganization - Complete Implementation Catalog

> **Generated:** 2026-01-21
> **Purpose:** Catalog of ALL changes required to reorganize the codebase without breaking dependencies

---

## Table of Contents

1. [Critical Files - DO NOT MOVE](#1-critical-files---do-not-move)
2. [Phase 1: Quick Wins (No Dependencies)](#2-phase-1-quick-wins-no-dependencies)
3. [Phase 2: Documentation Cleanup](#3-phase-2-documentation-cleanup)
4. [Phase 3: Root Markdown File Migration](#4-phase-3-root-markdown-file-migration)
5. [Phase 4: Scripts Directory Reorganization](#5-phase-4-scripts-directory-reorganization)
6. [Phase 5: JavaScript Reorganization](#6-phase-5-javascript-reorganization)
7. [Phase 6: Discord Integration Consolidation](#7-phase-6-discord-integration-consolidation)
8. [Phase 7: SWIM File Renaming](#8-phase-7-swim-file-renaming)
9. [Phase 8: GDT Documentation Consolidation](#9-phase-8-gdt-documentation-consolidation)
10. [Reference Update Checklist](#10-reference-update-checklist)

---

## 1. Critical Files - DO NOT MOVE

These files have hardcoded dependencies in deployment, startup scripts, or core application logic. Moving them would require extensive refactoring.

| File | Reason | Referenced By |
|------|--------|---------------|
| `load/config.php` | Core configuration - all PHP files depend on this | 50+ PHP files |
| `load/connect.php` | Database connection - triggers swim_sync | All API endpoints |
| `load/input.php` | Input validation | connect.php line 16 |
| `load/header.php` | Page layout | All root PHP pages |
| `load/footer.php` | Page layout, global JS | All root PHP pages |
| `load/nav.php` | Navigation config | All authenticated pages |
| `load/nav_public.php` | Public navigation | Public pages |
| `sessions/handler.php` | Session management | All pages line 3 |
| `scripts/startup.sh` | Azure daemon launcher | GitHub workflow line 59 |
| `scripts/vatsim_adl_daemon.php` | Main data daemon | startup.sh line 36 |
| `scripts/scheduler_daemon.php` | Scheduler daemon | startup.sh line 74 |
| `scripts/swim_ws_server.php` | WebSocket server | startup.sh line 61, composer.json |
| `scripts/swim_sync_daemon.php` | SWIM sync daemon | startup.sh line 68 |
| `scripts/swim_sync.php` | Sync functions | swim_sync_daemon.php, load/connect.php |
| `scripts/atis_parser.php` | ATIS parsing | vatsim_adl_daemon.php, api/adl/atis-debug.php |
| `adl/php/waypoint_eta_daemon.php` | ETA daemon | startup.sh line 55, GitHub workflow line 61 |
| `composer.json` | PSR-4 autoload paths | Azure pipelines, vendor loading |
| `nginx-site.conf` | Web server config | Azure deployment |
| `api/swim/v1/ws/` | WebSocket classes | composer.json autoload |

---

## 2. Phase 1: Quick Wins (No Dependencies)

These changes have zero dependencies and can be done immediately.

### 2.1 Delete `nul` File
```
Action: DELETE
File: nul
Dependencies: None
```

### 2.2 Update .gitignore
```
Action: EDIT
File: .gitignore
Add lines:
  nul
  .claude/settings.local.json
```

### 2.3 Delete Duplicate Aviation Standards Reference
```
Action: DELETE
File: docs/swim/Aviation_Standards_Cross_Reference.md
Keep: docs/swim/Aviation_Data_Standards_Cross_Reference.md
Dependencies: None (duplicate file)
```

---

## 3. Phase 2: Documentation Cleanup

### 3.1 Archive SWIM Session Transition Documents

**Action:** Move to `docs/swim/archive/`

| File | New Location |
|------|--------------|
| `docs/swim/SWIM_Session_Transition_20260115.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_AOCTelemetry.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_Phase1Complete.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_Phase2Complete.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_Phase2Start.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_SDKComplete.md` | `docs/swim/archive/` |
| `docs/swim/SWIM_Session_Transition_20260116_v2.md` | `docs/swim/archive/` |

**Dependencies:** None (internal session notes)

---

## 4. Phase 3: Root Markdown File Migration

### 4.1 Files to Move

| Current Location | New Location | References to Update |
|-----------------|--------------|---------------------|
| `advisory_builder_alignment_v1.md` | `docs/advisory/advisory_builder_alignment_v1.md` | None found |
| `advisory_formatting_spec_for_claude_code.md` | `docs/advisory/formatting_spec.md` | See 4.2 |
| `assistant_codebase_index_v18.md` | `docs/archive/assistant_codebase_index_v18.md` | See 4.2 |
| `eta_enhancement_transition_summary.md` | `docs/archive/eta_enhancement_transition_summary.md` | None found |
| `GDT_Incremental_Migration.md` | `docs/gdt/archive/GDT_Incremental_Migration.md` | See 4.2 |
| `GDT_Phase1_Transition.md` | `docs/gdt/archive/GDT_Phase1_Transition.md` | See 4.2 |
| `GDT_Unified_Design_Document_v1.1.md` | `docs/gdt/GDT_Unified_Design_Document.md` | See 4.2 |
| `NTML_Advisory_Formatting_Spec.md` | `docs/advisory/NTML_Formatting_Spec.md` | See 4.2 |
| `NTML_Advisory_Formatting_Transition.md` | `docs/archive/NTML_Advisory_Formatting_Transition.md` | See 4.2 |
| `TMI_Documentation_Index.md` | `docs/tmi/INDEX.md` | See 4.2 |

### 4.2 Reference Updates Required

#### File: `advisory_formatting_spec_for_claude_code.md`
After moving to `docs/advisory/formatting_spec.md`, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| (self) | 6 | `NTML_Advisory_Formatting_Spec.md` | `NTML_Formatting_Spec.md` |

#### File: `assistant_codebase_index_v18.md`
After moving to `docs/archive/`, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `README.md` | 295 | `assistant_codebase_index_v17.md` | `docs/archive/assistant_codebase_index_v18.md` |

#### File: `GDT_Unified_Design_Document_v1.1.md`
After consolidation, update these files:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `docs/tmi/GDT_Session_20260121.md` | 253 | `GDT_Unified_Design_Document_v1.1.md` | `../gdt/GDT_Unified_Design_Document.md` |
| `docs/tmi/GDT_API_Documentation.md` | 328 | `GDT_Unified_Design_Document_v1.md` | `../gdt/GDT_Unified_Design_Document.md` |
| `docs/GDT_GS_Transition_Summary_20260110.md` | 87, 163 | `docs/GDT_Unified_Design_Document_v1.md` | `gdt/GDT_Unified_Design_Document.md` |
| `docs/STATUS.md` | 628 | `docs/GDT_Unified_Design_Document_v1.md` | `docs/gdt/GDT_Unified_Design_Document.md` |
| `docs/tmi/ARCHITECTURE.md` | 403 | `GDT_Unified_Design_Document_v1.md` | `../gdt/GDT_Unified_Design_Document.md` |
| `docs/tmi/GS_Eligibility_Fix_Transition.md` | 286 | `/GDT_Unified_Design_Document_v1.md` | `/docs/gdt/GDT_Unified_Design_Document.md` |

#### File: `GDT_Phase1_Transition.md`
After moving, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `docs/tmi/GDT_API_Documentation.md` | 329 | `GDT_Phase1_Transition.md` | `../gdt/archive/GDT_Phase1_Transition.md` |

#### File: `GDT_Incremental_Migration.md`
After moving, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `TMI_Documentation_Index.md` | 134 | `GDT_Incremental_Migration.md` | `docs/gdt/archive/GDT_Incremental_Migration.md` |

#### File: `NTML_Advisory_Formatting_Spec.md`
After moving to `docs/advisory/`, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `advisory_formatting_spec_for_claude_code.md` | 6 | `NTML_Advisory_Formatting_Spec.md` | `docs/advisory/NTML_Formatting_Spec.md` |
| `NTML_Advisory_Formatting_Transition.md` | 24-25 | `NTML_Advisory_Formatting_Spec.md` | `docs/advisory/NTML_Formatting_Spec.md` |
| `docs/tmi/NTML_Discord_Parser_Alignment_20260117.md` | 183 | `NTML_Advisory_Formatting_Spec.md` | `../advisory/NTML_Formatting_Spec.md` |

#### File: `NTML_Advisory_Formatting_Transition.md`
After moving, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `TMI_Documentation_Index.md` | 13, 132 | `NTML_Advisory_Formatting_Transition.md` | `docs/archive/NTML_Advisory_Formatting_Transition.md` |

#### File: `TMI_Documentation_Index.md`
After moving to `docs/tmi/INDEX.md`, update:

| File to Edit | Line | Old Reference | New Reference |
|--------------|------|---------------|---------------|
| `docs/tmi/GDT_API_Documentation.md` | 331 | `TMI_Documentation_Index.md` | `INDEX.md` |
| `docs/tmi/NTML_Discord_Parser_Alignment_20260117.md` | 184 | `TMI_Documentation_Index.md` | `INDEX.md` |

---

## 5. Phase 4: Scripts Directory Reorganization

### 5.1 CRITICAL WARNING

**DO NOT MOVE these scripts** - they are referenced in startup.sh with absolute paths:
- `vatsim_adl_daemon.php`
- `scheduler_daemon.php`
- `swim_ws_server.php`
- `swim_sync_daemon.php`
- `archival_daemon.php`
- `monitoring_daemon.php`
- `atis_parser.php`
- `swim_sync.php`
- `swim_cleanup.php`
- `swim_ws_events.php`
- `startup.sh`
- `zone_daemon.php`

### 5.2 Safe to Reorganize (Utility Scripts)

These scripts are not referenced in startup or deployment and can be moved:

**Create `scripts/migrations/`:**
| Current Location | New Location |
|-----------------|--------------|
| `scripts/export_config_data.php` | `scripts/migrations/export_config_data.php` |
| `scripts/export_sql.php` | `scripts/migrations/export_sql.php` |
| `scripts/export_config_to_sql.php` | `scripts/migrations/export_config_to_sql.php` |
| `scripts/migrate_config_data.php` | `scripts/migrations/migrate_config_data.php` |
| `scripts/migrate_public_routes.php` | `scripts/migrations/migrate_public_routes.php` |
| `scripts/run_migration.php` | `scripts/migrations/run_migration.php` |
| `scripts/import_rw_rates.php` | `scripts/migrations/import_rw_rates.php` |

**Update required in each moved file:**
Change `require_once $baseDir . '/load/config.php'` to use `__DIR__ . '/../../load/config.php'`

**Create `scripts/diagnostics/`:**
| Current Location | New Location |
|-----------------|--------------|
| `scripts/analyze_mysql_runways.php` | `scripts/diagnostics/analyze_mysql_runways.php` |
| `scripts/analyze_monitoring.php` | `scripts/diagnostics/analyze_monitoring.php` |

**Create `scripts/testing/`:**
| Current Location | New Location |
|-----------------|--------------|
| `scripts/test_atis_parser.php` | `scripts/testing/test_atis_parser.php` |
| `scripts/swim_load_test.php` | `scripts/testing/swim_load_test.php` |

**Update for test_atis_parser.php after move:**
```php
// Line 13: Change from
require_once(__DIR__ . '/atis_parser.php');
// To
require_once(__DIR__ . '/../atis_parser.php');
```

---

## 6. Phase 5: JavaScript Reorganization

### 6.1 Files That CANNOT Be Moved

All JavaScript files in `/assets/js/` are loaded via `<script>` tags with hardcoded paths. Moving ANY file requires updating the corresponding PHP page.

### 6.2 If Reorganizing, Update These PHP Files

**For each JS file moved, update the `<script src="">` tag in:**

| JS File | PHP File(s) | Line(s) |
|---------|-------------|---------|
| `advisory-config.js` | `advisory-builder.php` | 828 |
| | `gdt.php` | 1719 |
| | `plan.php` | 2352 |
| | `route.php` | 2447 |
| `advisory-builder.js` | `advisory-builder.php` | 829 |
| `demand.js` | `demand.php` | 808 |
| | `gdt.php` | 1685 |
| `config/phase-colors.js` | `demand.php` | 20 |
| | `gdt.php` | 1681 |
| | `nod.php` | 1817 |
| | `route.php` | 2450 |
| | `status.php` | 2012 |
| `config/rate-colors.js` | `demand.php` | 22 |
| | `gdt.php` | 1683 |
| `config/filter-colors.js` | `demand.php` | 24 |
| | `nod.php` | 1819 |
| | `route.php` | 2452 |
| `fir-scope.js` | `gdt.php` | 1721 |
| `fir-integration.js` | `gdt.php` | 1722 |
| `gdt.js` | `gdt.php` | 1723 |
| `gdp.js` | `gdt.php` | 1724 |
| `jatoc.js` | `jatoc.php` | 1598 |
| `jatoc-facility-patch.js` | `jatoc.php` | 1599 |
| `nod.js` | `nod.php` | 1822 |
| `nod-demand-layer.js` | `nod.php` | 1825 |
| `ntml.js` | `ntml.php` | 486 |
| `plan.js` | `plan.php` | 2355 |
| `initiative_timeline.js` | `plan.php` | 2358 |
| `reroute.js` | `reroutes.php` | 377 |
| `review.js` | `review.php` | 821 |
| `statsim_rates.js` | `review.php` | 362 |
| `awys.js` | `route.php` | 2455 |
| `procs_enhanced.js` | `route.php` | 2456 |
| `route-symbology.js` | `route.php` | 2457 |
| `public-routes.js` | `route.php` | 2461 |
| `playbook-cdr-search.js` | `route.php` | 2462 |
| `route-maplibre.js` | `route.php` | 2466 (dynamic) |
| `route.js` | `route.php` | 2468 (dynamic) |
| `leaflet.textpath.js` | `route.php` | 42 (dynamic) |
| `schedule.js` | `schedule.php` | 222 |
| `sheet.js` | `sheet.php` | 453 |
| `splits.js` | `splits.php` | 3157 |
| `sua.js` | `sua.php` | 782 |
| `plugins/datetimepicker.js` | `load/footer.php` | 6 |
| `theme.min.js` | `load/footer.php` | 10 |

### 6.3 Recommendation

**DO NOT reorganize JavaScript files** unless you're prepared to update 40+ script tag references across 15+ PHP files. The risk of breaking the application outweighs the organizational benefit.

---

## 7. Phase 6: Discord Integration Consolidation

### 7.1 Current Structure
```
/load/discord/
  ├── DiscordAPI.php
  ├── DiscordWebhookHandler.php
  ├── DiscordMessageParser.php
  └── TMIDiscord.php

/api/discord/
  ├── messages.php
  ├── webhook.php
  ├── channels.php
  ├── reactions.php
  └── announcements.php

/api/nod/discord.php
/api/nod/discord-post.php

/scripts/discord/
  ├── test_ntml_live.php
  └── test_tmi_discord.php
```

### 7.2 If Consolidating to `/integrations/discord/`

**Files that require these Discord classes:**

| File | Line | Requires |
|------|------|----------|
| `api/discord/messages.php` | 20 | `/load/discord/DiscordAPI.php` |
| `api/discord/webhook.php` | 24-25 | `/load/discord/DiscordWebhookHandler.php`, `DiscordMessageParser.php` |
| `api/discord/channels.php` | 17 | `/load/discord/DiscordAPI.php` |
| `api/discord/reactions.php` | 18 | `/load/discord/DiscordAPI.php` |
| `api/discord/announcements.php` | 16 | `/load/discord/DiscordAPI.php` |
| `api/nod/discord.php` | 30-32 | All three Discord classes |
| `api/mgt/ntml/post.php` | ~52-54 | `DiscordAPI.php`, `TMIDiscord.php` |
| `api/test/ntml_discord_test.php` | 42-43 | `DiscordAPI.php`, `TMIDiscord.php` |
| `scripts/discord/test_tmi_discord.php` | 15 | `TMIDiscord.php` |
| `scripts/discord/test_ntml_live.php` | 18 | `TMIDiscord.php` |
| `scripts/tmi/test_advisory_format.php` | 13 | `TMIDiscord.php` |

### 7.3 Recommendation

**Keep Discord classes in `/load/discord/`** - this is the PHP convention for shared libraries. Moving them would require updating 11+ files with relative path changes.

If you still want to consolidate test scripts:
```
Move:
  scripts/discord/test_ntml_live.php → scripts/testing/discord/test_ntml_live.php
  scripts/discord/test_tmi_discord.php → scripts/testing/discord/test_tmi_discord.php

Update in each:
  require_once __DIR__ . '/../../load/discord/TMIDiscord.php'
  → require_once __DIR__ . '/../../../load/discord/TMIDiscord.php'
```

---

## 8. Phase 7: SWIM File Renaming

### 8.1 Proposed Renames

| Current | Proposed | Purpose Clarification |
|---------|----------|----------------------|
| `swim.php` | Keep as-is | Main SWIM overview page |
| `swim-doc.php` | `swim-doc-viewer.php` | Single document viewer |
| `swim-docs.php` | `swim-doc-index.php` | Documentation index/hub |
| `swim-keys.php` | `swim-api-keys.php` | API key management |

### 8.2 References to Update for Each Rename

#### If renaming `swim-keys.php` → `swim-api-keys.php`:

| File | Line | Change |
|------|------|--------|
| `load/nav.php` | 95 | `'./swim-keys'` → `'./swim-api-keys'` |
| `load/nav_public.php` | 72 | `'./swim-keys'` → `'./swim-api-keys'` |
| `swim.php` | 352 | `href="swim-keys"` → `href="swim-api-keys"` |
| `swim.php` | 412 | `href="swim-keys"` → `href="swim-api-keys"` |
| `swim.php` | 754 | `href="swim-keys"` → `href="swim-api-keys"` |
| `swim.php` | 804 | `href="swim-keys"` → `href="swim-api-keys"` |
| `swim-docs.php` | 212 | `href="swim-keys"` → `href="swim-api-keys"` |
| `swim-keys.php` (self) | 538, 603, 644 | AJAX `$.post('swim-keys.php'` → `$.post('swim-api-keys.php'` |
| `docs/swim/VATSWIM_Release_Documentation.md` | 159, 1813 | URL references |
| `docs/swim/VATSWIM_Announcement.md` | 25, 34, 300, 301 | URL references |
| `docs/swim/openapi.yaml` | 29 | URL reference |
| `docs/swim/index.html` | 161 | URL reference |
| `integrations/flight-sim/msfs/vatswim_config.ini.example` | 7 | URL reference |
| `integrations/virtual-airlines/phpvms7/README.md` | 112 | URL reference |

#### If renaming `swim-docs.php` → `swim-doc-index.php`:

| File | Line | Change |
|------|------|--------|
| `load/nav.php` | 97 | `'./swim-docs'` → `'./swim-doc-index'` |
| `load/nav_public.php` | 74 | `'./swim-docs'` → `'./swim-doc-index'` |
| `swim-doc.php` | 87 | `header('Location: swim-docs')` → `header('Location: swim-doc-index')` |
| `swim-doc.php` | 435 | `href="swim-docs"` → `href="swim-doc-index"` |
| `swim-doc.php` | 449 | `href="swim-docs"` → `href="swim-doc-index"` |
| `docs/swim/VATSWIM_Announcement.md` | 300 | URL reference |
| Multiple integration READMEs | Various | `swim/docs` references |

#### If renaming `swim-doc.php` → `swim-doc-viewer.php`:

| File | Line | Change |
|------|------|--------|
| `swim-docs.php` | 245, 262, 269, 276, 293, 300, 307, 314, 321, 394, 417, 423, 429 | All `href="swim-doc?file=..."` → `href="swim-doc-viewer?file=..."` |
| `.github/workflows/azure-webapp-vatcscc.yml` | 48 | Comment reference |

### 8.3 Recommendation

**Risk vs. Benefit:** Renaming SWIM files requires updating 30+ references across code and documentation. The current names work. Consider this LOW PRIORITY.

---

## 9. Phase 8: GDT Documentation Consolidation

### 9.1 Consolidation Plan

**Keep as primary:**
- `docs/gdt/GDT_Unified_Design_Document.md` (merged from v1 and v1.1)

**Archive (move to `docs/gdt/archive/`):**
- `GDT_Phase1_Transition.md`
- `GDT_Incremental_Migration.md`
- `docs/GDT_Unified_Design_Document_v1.md` (after merging)
- `docs/GDT_GS_Transition_Summary_20260110.md`

**Keep in place (API documentation):**
- `docs/tmi/GDT_API_Documentation.md`
- `docs/tmi/GDT_API_Development_Session.md`
- `docs/tmi/GDT_Session_20260121.md`
- `docs/tmi/GDT_REBUILD_DESIGN.md`

**Update wiki:**
- `wiki/GDT-Ground-Delay-Tool.md` - update to point to consolidated doc

### 9.2 All References to Update

See [Phase 3 Section 4.2](#42-reference-updates-required) for complete list of 27 references across 12 files.

---

## 10. Reference Update Checklist

### 10.1 Navigation Files (Update for ANY page rename)

- [ ] `load/nav.php` - Lines 49-108
- [ ] `load/nav_public.php` - Lines 35-85

### 10.2 Documentation Index Files (Update for doc moves)

- [ ] `README.md` - Line 295
- [ ] `TMI_Documentation_Index.md` - Lines 10-134
- [ ] `assistant_codebase_index_v18.md` - Lines 1295-1601
- [ ] `docs/STATUS.md` - Lines 628-629
- [ ] `docs/tmi/ARCHITECTURE.md` - Line 403

### 10.3 Cross-Reference Documents

- [ ] `docs/tmi/GDT_API_Documentation.md` - Lines 328-331
- [ ] `docs/tmi/GDT_Session_20260121.md` - Lines 80-81, 144-146, 253
- [ ] `docs/tmi/GDT_API_Development_Session.md` - Lines 69, 145-146
- [ ] `docs/tmi/NTML_Discord_Parser_Alignment_20260117.md` - Lines 183-184
- [ ] `docs/GDT_GS_Transition_Summary_20260110.md` - Lines 87, 163
- [ ] `docs/tmi/GS_Eligibility_Fix_Transition.md` - Line 286

### 10.4 External Documentation (Update URLs carefully)

- [ ] `docs/swim/VATSWIM_Release_Documentation.md` - Lines 159, 1813
- [ ] `docs/swim/VATSWIM_Announcement.md` - Lines 25, 34, 300, 301
- [ ] `docs/swim/openapi.yaml` - Line 29
- [ ] `docs/swim/index.html` - Line 161
- [ ] `sdk/php/README.md` - Line 318
- [ ] `sdk/cpp/README.md` - Line 274
- [ ] `sdk/php/composer.json` - Line 13
- [ ] `integrations/*/README.md` files - Various lines

### 10.5 Deployment Files (CRITICAL - Test thoroughly)

- [ ] `azure-pipelines.yml`
- [ ] `azure-pipelines-1.yml`
- [ ] `.github/workflows/azure-webapp-vatcscc.yml`
- [ ] `composer.json` - Lines 12, 16-17
- [ ] `scripts/startup.sh` - All daemon paths

---

## Implementation Order

1. **Phase 1** - Quick wins (delete nul, update .gitignore) - 5 minutes
2. **Phase 2** - Archive SWIM session docs - 10 minutes
3. **Phase 3** - Move root markdown files + update references - 1 hour
4. **Phase 4** - Reorganize safe scripts + update paths - 30 minutes
5. **Phase 8** - GDT doc consolidation + update references - 1 hour

**SKIP or DEFER:**
- Phase 5 (JavaScript) - Too many hardcoded references
- Phase 6 (Discord) - Current structure is functional
- Phase 7 (SWIM renames) - Low benefit, high risk

---

## Verification Checklist

After each phase, verify:

- [ ] Site loads without errors
- [ ] Navigation works (all menu items)
- [ ] API endpoints respond
- [ ] No 404 errors in browser console
- [ ] Run: `git status` to review all changes
- [ ] Run: `grep -r "OLD_FILENAME"` to find missed references

---

*End of Reorganization Catalog*
