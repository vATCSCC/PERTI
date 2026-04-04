# SWIM Reroute Parity Fixes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix 4 data parity issues between VATSIM_TMI and SWIM_API discovered during route amendment audit.

**Architecture:** SQL migrations + watermark reset + new PHP endpoint. No daemon changes needed — the sync daemon already handles these tables, it just needs the target table created and watermarks reset.

**Tech Stack:** Azure SQL (sqlsrv), PHP 8.2, SWIM API pattern (auth.php + SwimResponse)

---

### Task 1: Fix migration 036 — add synced_utc column

**Files:**
- Modify: `database/migrations/swim/036_swim_rad_mirror.sql`

Migration 036 creates `swim_rad_amendments` but omits the `synced_utc` column that the sync daemon requires (lines 190, 206 of swim_tmi_sync_daemon.php). Add it.

### Task 2: Run migration 036 against SWIM_API

Execute via sqlcmd. Creates `swim_rad_amendments` table + indexes + `allowed_features` column on `swim_api_keys`.

### Task 3: Fix adv_number type mismatch

ALTER COLUMN `swim_tmi_reroutes.adv_number` from INT to NVARCHAR(16) to match TMI source. Write as migration 037.

### Task 4: Reset SWIM sync watermarks

Set `last_sync_utc = NULL` in `swim_sync_state` for:
- `swim_tmi_reroutes` (triggers full sync of 276 reroutes)
- `swim_tmi_reroute_routes` (triggers full sync of 1,763 routes)
- `swim_rad_amendments` (triggers first sync of 3+ amendments)

Daemon will pick up on next 5-min cycle.

### Task 5: Create SWIM API amendments endpoint

**Files:**
- Create: `api/swim/v1/tmi/amendments.php`

Follow same pattern as `reroutes.php`: auth.php, SwimResponse, sqlsrv queries against `swim_rad_amendments`.

### Task 6: Verify parity after sync

Re-query counts in both databases to confirm sync completed.
