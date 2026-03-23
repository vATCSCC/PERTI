# CTP Route Planner → Playbook Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build SWIM API endpoints for CTP Route Planner to sync route definitions into PERTI Playbooks, with batch traversal lookup and throughput data support.

**Architecture:** Full-state sync pattern — CTP sends complete route set, we diff against current playbook state. 3-phase algorithm: Diff (MySQL) → Traversal (deduplicated PostGIS) → Write (batched per-play transactions). 4 auto-created playbooks per session (FULL, NA, EMEA, OCEANIC).

**Tech Stack:** PHP 8.2, MySQL 8 (perti_site), PostGIS (VATSIM_GIS), Azure SQL (SWIM_API), sqlsrv/PDO

**Spec:** `docs/superpowers/specs/2026-03-23-ctp-playbook-sync-design.md`

---

### Task 1: MySQL Migration 014

**Files:**
- Create: `database/migrations/playbook/014_ctp_external_fields.sql`

- [ ] **Step 1:** Write migration extending `playbook_plays.source` ENUM with `CTP`, adding `external_revision` column, adding `external_*` columns to `playbook_routes`, relaxing `origin`/`dest` NOT NULL, and creating UNIQUE index on `(play_id, external_source, external_id)`.

- [ ] **Step 2:** Apply migration to `perti_site` MySQL database.

- [ ] **Step 3:** Verify columns exist with `DESCRIBE playbook_routes` and `DESCRIBE playbook_plays`.

---

### Task 2: Main Sync Endpoint

**Files:**
- Create: `api/swim/v1/ingest/ctp-routes.php`

**Bootstrap pattern:** Load `config.php` + `connect.php` (gets MySQL) BEFORE `auth.php` (which sets `PERTI_SWIM_ONLY` — too late to suppress MySQL). Include `playbook_helpers.php` for `computeTraversedFacilities()`.

- [ ] **Step 1:** Write endpoint with SWIM auth, CTP field authority check, payload validation (session_id, revision, group_mapping, routes array, max 1000).

- [ ] **Step 2:** Implement play auto-creation — `findOrCreateCtpPlay()` creates 4 plays (FULL/NA/EMEA/OCEANIC) with `source='CTP'`, `visibility='private_org'`, `org_code='CTP'`, `ctp_session_id`, `ctp_scope`. Logs `play_created` changelog.

- [ ] **Step 3:** Implement Phase 1 Diff — load current routes by `external_id`, classify changes into ADD/ROUTE_CHANGED/METADATA_ONLY/DELETE/SKIP. Route_string or origin/dest changes → ROUTE_CHANGED. External fields or filter changes → METADATA_ONLY.

- [ ] **Step 4:** Implement Phase 2 Traversal — deduplicate by `(route_string, origin, dest)` key, call `computeTraversedFacilities()` once per unique key.

- [ ] **Step 5:** Implement Phase 3 Write — per-play MySQL transactions: INSERT new routes, UPDATE changed routes, DELETE removed routes, batch-insert changelogs, upsert throughput, update play revision.

- [ ] **Step 6:** Return JSON response with per-play counts. Idempotent re-sends return 200 with zero counts.

---

### Task 3: Batch Traversal Lookup Endpoint

**Files:**
- Create: `api/swim/v1/playbook/traversal.php`

- [ ] **Step 1:** Write SWIM-auth endpoint accepting POST with `routes[]` (max 100) and optional `fields[]` filter (`artccs`, `tracons`, `sectors`, `geometry`, `distance`, `waypoints`).

- [ ] **Step 2:** Call `computeTraversedFacilities()` per route, apply field filtering, return results array. ARTCCs are already L1 (PostGIS `artcc_boundaries` stores only top-level ARTCC/FIR boundaries). Failed routes return with `error` field instead of failing batch.

---

### Task 4: Throughput session_id Filter

**Files:**
- Modify: `api/swim/v1/playbook/throughput.php`

- [ ] **Step 1:** Add `session_id` parameter to GET handler. JOIN through `swim_playbook_routes` → find `play_id` values from `swim_playbook_plays` WHERE `ctp_session_id = ?`. Add to WHERE clause.

---

### Task 5: Validate, Commit, Deploy

- [ ] **Step 1:** PHP syntax check all new/modified files.
- [ ] **Step 2:** Commit all changes on feature branch.
- [ ] **Step 3:** Push and create PR.
- [ ] **Step 4:** Merge PR.
- [ ] **Step 5:** Create GitHub issue on `vatsimnetwork/ctp-route-planner` repo with integration guide.
