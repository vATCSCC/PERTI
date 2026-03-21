# CTP-to-SWIM NAT Track Throughput Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete CTP-to-SWIM pipeline for NAT track resolution, throughput configuration, planning simulator, SWIM API endpoints, sync daemon extension, and demand visualization migration.

**Architecture:** Extends the existing CTP Oceanic system (migration 045) with: (1) NAT track resolution via hybrid token+sequence matching, (2) generalized throughput constraints with NULL=wildcard, (3) pre-event planning simulator with PostGIS route expansion, (4) SWIM API endpoints for external consumers, (5) daemon-based CTP→SWIM sync with delta-driven metrics recompute, (6) Chart.js→ECharts migration for CTP demand charts.

**Tech Stack:** PHP 8.2 (sqlsrv + PDO pgsql), Azure SQL (VATSIM_TMI + SWIM_API), PostGIS (VATSIM_GIS), ECharts 5.4.3, jQuery 2.2.4, PERTII18n

**Spec:** `docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md`

**No automated test suite** — verification is manual via API calls, database queries, and browser testing.

---

## File Structure

### New Files (30 files)

| # | File | Responsibility |
|---|------|---------------|
| 1 | `database/migrations/tmi/048_ctp_nat_track_throughput.sql` | TMI schema: ALTER ctp_flight_control + CREATE ctp_track_throughput_config + planning tables |
| 2 | `database/migrations/swim/030_swim_nat_track_metrics.sql` | SWIM schema: ALTER swim_flights + CREATE swim_nat_track_metrics + swim_nat_track_throughput + CTP provider |
| 3 | `database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql` | Extend sp_Swim_BulkUpsert for 3 NAT columns (124 total) + row-hash v3 (21 volatile columns) |
| 4 | `load/services/NATTrackResolver.php` | Shared NAT resolution: token regex + full sequence match |
| 5 | `load/services/NATTrackFunctions.php` | Extracted shared functions from nat_tracks.php |
| 6 | `api/ctp/throughput/list.php` | GET: list throughput configs for session |
| 7 | `api/ctp/throughput/create.php` | POST: create throughput config |
| 8 | `api/ctp/throughput/update.php` | POST: update config with optimistic concurrency |
| 9 | `api/ctp/throughput/delete.php` | POST: soft-delete config (is_active=0) |
| 10 | `api/ctp/throughput/preview.php` | GET: preview config impact against current flights |
| 11 | `api/ctp/planning/scenarios.php` | GET/POST: list/create planning scenarios |
| 12 | `api/ctp/planning/scenario_save.php` | POST: update scenario |
| 13 | `api/ctp/planning/scenario_delete.php` | POST: delete scenario |
| 14 | `api/ctp/planning/scenario_clone.php` | POST: clone scenario |
| 15 | `api/ctp/planning/block_save.php` | POST: create/update traffic block |
| 16 | `api/ctp/planning/block_delete.php` | POST: delete traffic block |
| 17 | `api/ctp/planning/assignment_save.php` | POST: create/update track assignment |
| 18 | `api/ctp/planning/assignment_delete.php` | POST: delete track assignment |
| 19 | `api/ctp/planning/compute.php` | POST: run demand profile computation |
| 20 | `api/ctp/planning/apply_to_session.php` | POST: promote scenario to live configs |
| 21 | `api/swim/v1/tmi/nat_tracks/metrics.php` | GET: pre-computed NAT track metrics for SWIM consumers |
| 22 | `api/swim/v1/tmi/nat_tracks/status.php` | GET: live NAT track definitions + occupancy |

### Modified Files (16 files)

| # | File | Changes |
|---|------|---------|
| 23 | `api/data/playbook/nat_tracks.php` | Extract shared functions to NATTrackFunctions.php, update buildNATAliases regex |
| 24 | `api/ctp/demand.php` | Add `group_by=nat_track` option |
| 25 | `api/ctp/flights/detect.php` | Add NAT resolution call after batch insert (after line 301) |
| 26 | `api/ctp/flights/modify_route.php` | Add NAT re-resolution + SWIM push (after line 157, before audit log) |
| 27 | `api/ctp/flights/assign_edct.php` | Add SWIM immediate push for resolved_nat_track (after line 180) |
| 28 | `api/ctp/flights/assign_edct_batch.php` | Add SWIM batch push for resolved_nat_track |
| 29 | `api/ctp/flights/remove_edct.php` | Add SWIM immediate push (after line 99) |
| 30 | `scripts/swim_sync_daemon.php` | Add CTP sync step after ADL sync (after line 158) |
| 31 | `scripts/swim_sync.php` | Add `swim_sync_ctp_to_swim()` function |
| 32 | `assets/js/ctp.js` | DemandChart ECharts migration, throughput UI, planning UI, API endpoint additions |
| 33 | `assets/js/route-maplibre.js` | Update NAT token pattern at line 162 |
| 34 | `assets/locales/en-US.json` | ~85 new i18n keys under ctp.throughput, ctp.planning, ctp.nat |
| 35 | `assets/locales/fr-CA.json` | French translations for all 85 keys |
| 36 | `assets/locales/en-CA.json` | Canadian English regional overrides |
| 37 | `assets/locales/en-EU.json` | European English regional overrides |
| 38 | `ctp.php` | Change `<canvas id="ctp_demand_chart">` to `<div>` for ECharts, add throughput/planning HTML sections |

---

## Task 1: TMI Database Migration (048)

**Files:**
- Create: `database/migrations/tmi/048_ctp_nat_track_throughput.sql`

**Context:** This migration adds columns to `ctp_flight_control` for NAT resolution, creates the `ctp_track_throughput_config` table, and creates the 3 planning simulator tables. All in VATSIM_TMI (Azure SQL). Run as `jpeterson` admin (adl_api_user lacks CREATE TABLE).

- [ ] **Step 1: Write TMI migration SQL**

Create `database/migrations/tmi/048_ctp_nat_track_throughput.sql` with the following exact SQL from spec Section 4:

```sql
-- Migration 048: CTP NAT Track Throughput + Planning Tables
-- Database: VATSIM_TMI
-- Run as: jpeterson (DDL admin)
-- Spec: docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md

-- ============================================================================
-- 4.1: New columns on ctp_flight_control
-- ============================================================================
ALTER TABLE dbo.ctp_flight_control ADD
    resolved_nat_track      NVARCHAR(8) NULL,
    nat_track_resolved_at   DATETIME2(0) NULL,
    nat_track_source        NVARCHAR(8) NULL;
GO

CREATE NONCLUSTERED INDEX IX_ctp_fc_nat_track
    ON dbo.ctp_flight_control(session_id, resolved_nat_track)
    WHERE resolved_nat_track IS NOT NULL;
GO

-- ============================================================================
-- 4.3: ctp_track_throughput_config
-- ============================================================================
CREATE TABLE dbo.ctp_track_throughput_config (
    config_id               INT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    config_label            NVARCHAR(64) NOT NULL,
    tracks_json             NVARCHAR(MAX) NULL,
    origins_json            NVARCHAR(MAX) NULL,
    destinations_json       NVARCHAR(MAX) NULL,
    max_acph                INT NOT NULL,
    priority                INT NOT NULL DEFAULT 50,
    is_active               BIT NOT NULL DEFAULT 1,
    notes                   NVARCHAR(256) NULL,
    created_by              NVARCHAR(16) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT FK_ctp_ttc_session FOREIGN KEY (session_id)
        REFERENCES dbo.ctp_sessions(session_id)
);
GO

CREATE NONCLUSTERED INDEX IX_ctp_ttc_session
    ON dbo.ctp_track_throughput_config(session_id, is_active)
    WHERE is_active = 1;
GO

CREATE NONCLUSTERED INDEX IX_ctp_ttc_session_priority
    ON dbo.ctp_track_throughput_config(session_id, priority)
    WHERE is_active = 1
    INCLUDE (config_label, max_acph, tracks_json, origins_json, destinations_json);
GO

-- ============================================================================
-- 4.6: Planning simulator tables
-- ============================================================================
CREATE TABLE dbo.ctp_planning_scenarios (
    scenario_id             INT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NULL,
    scenario_name           NVARCHAR(64) NOT NULL,
    departure_window_start  DATETIME2(0) NOT NULL,
    departure_window_end    DATETIME2(0) NOT NULL,
    status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    notes                   NVARCHAR(MAX) NULL,
    created_by              NVARCHAR(16) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_ctp_ps_status CHECK (status IN ('DRAFT', 'ACTIVE', 'ARCHIVED')),
    CONSTRAINT FK_ctp_ps_session FOREIGN KEY (session_id)
        REFERENCES dbo.ctp_sessions(session_id)
);
GO

CREATE TABLE dbo.ctp_planning_traffic_blocks (
    block_id                INT IDENTITY(1,1) PRIMARY KEY,
    scenario_id             INT NOT NULL,
    block_label             NVARCHAR(64) NULL,
    origins_json            NVARCHAR(MAX) NOT NULL,
    destinations_json       NVARCHAR(MAX) NOT NULL,
    flight_count            INT NOT NULL,
    dep_distribution        NVARCHAR(16) NOT NULL DEFAULT 'UNIFORM',
    dep_distribution_json   NVARCHAR(MAX) NULL,
    aircraft_mix_json       NVARCHAR(MAX) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_ctp_ptb_dist CHECK (dep_distribution IN ('UNIFORM','FRONT_LOADED','BACK_LOADED','CUSTOM')),
    CONSTRAINT FK_ctp_ptb_scenario FOREIGN KEY (scenario_id)
        REFERENCES dbo.ctp_planning_scenarios(scenario_id) ON DELETE CASCADE
);
GO

CREATE TABLE dbo.ctp_planning_track_assignments (
    assignment_id           INT IDENTITY(1,1) PRIMARY KEY,
    block_id                INT NOT NULL,
    track_name              NVARCHAR(8) NULL,
    route_string            NVARCHAR(MAX) NULL,
    flight_count            INT NOT NULL,
    altitude_range          NVARCHAR(32) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT FK_ctp_pta_block FOREIGN KEY (block_id)
        REFERENCES dbo.ctp_planning_traffic_blocks(block_id) ON DELETE CASCADE
);
GO
```

- [ ] **Step 2: Run the TMI migration**

Connect to VATSIM_TMI as `jpeterson` (DDL admin) and execute:
```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_TMI -U jpeterson -P Jhp21012 -i database/migrations/tmi/048_ctp_nat_track_throughput.sql
```

- [ ] **Step 3: Verify migration**

Run verification queries against VATSIM_TMI:
```sql
-- Verify new columns on ctp_flight_control
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'ctp_flight_control'
AND COLUMN_NAME IN ('resolved_nat_track', 'nat_track_resolved_at', 'nat_track_source');
-- Expected: 3 rows

-- Verify new table exists
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_NAME IN ('ctp_track_throughput_config', 'ctp_planning_scenarios',
    'ctp_planning_traffic_blocks', 'ctp_planning_track_assignments');
-- Expected: 4 rows

-- Verify indexes
SELECT name FROM sys.indexes WHERE object_id = OBJECT_ID('ctp_flight_control')
AND name = 'IX_ctp_fc_nat_track';
-- Expected: 1 row
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tmi/048_ctp_nat_track_throughput.sql
git commit -m "feat(ctp): migration 048 — NAT track columns, throughput config, planning tables"
```

---

## Task 2: SWIM Database Migration (030)

**Files:**
- Create: `database/migrations/swim/030_swim_nat_track_metrics.sql`

**Context:** Creates SWIM-side tables for pre-computed metrics and throughput utilization. Also extends swim_flights with NAT columns. Run as `jpeterson`. No FK to VATSIM_TMI (cross-database not supported on Azure SQL Basic).

- [ ] **Step 1: Write SWIM migration 030 SQL**

Create `database/migrations/swim/030_swim_nat_track_metrics.sql`:

```sql
-- Migration 030: SWIM NAT Track Metrics + Throughput
-- Database: SWIM_API
-- Run as: jpeterson (DDL admin)

-- ============================================================================
-- 4.2: New columns on swim_flights
-- ============================================================================
ALTER TABLE dbo.swim_flights ADD
    resolved_nat_track      NVARCHAR(8) NULL,
    nat_track_resolved_at   DATETIME2(0) NULL,
    nat_track_source        NVARCHAR(8) NULL;
GO

CREATE NONCLUSTERED INDEX IX_swim_flights_nat_track
    ON dbo.swim_flights(resolved_nat_track)
    WHERE resolved_nat_track IS NOT NULL AND is_active = 1
    INCLUDE (flight_uid, callsign, dep_airport, arr_airport);
GO

-- ============================================================================
-- 4.4: swim_nat_track_metrics
-- ============================================================================
CREATE TABLE dbo.swim_nat_track_metrics (
    metric_id               BIGINT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    track_name              NVARCHAR(8) NOT NULL,
    bin_start_utc           DATETIME2(0) NOT NULL,
    bin_end_utc             DATETIME2(0) NOT NULL,
    flight_count            INT NOT NULL DEFAULT 0,
    slotted_count           INT NOT NULL DEFAULT 0,
    compliant_count         INT NOT NULL DEFAULT 0,
    avg_delay_min           FLOAT NULL,
    peak_rate_hr            INT NULL,
    direction               NVARCHAR(8) NULL,
    flight_levels_json      NVARCHAR(256) NULL,
    origins_json            NVARCHAR(MAX) NULL,
    destinations_json       NVARCHAR(MAX) NULL,
    source                  NVARCHAR(16) NOT NULL DEFAULT 'CTP',
    computed_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT UQ_swim_ntm_session_track_bin
        UNIQUE (session_id, track_name, bin_start_utc),
    CONSTRAINT CK_swim_ntm_direction
        CHECK (direction IS NULL OR direction IN ('WESTBOUND', 'EASTBOUND'))
);
GO

CREATE NONCLUSTERED INDEX IX_swim_ntm_session_time
    ON dbo.swim_nat_track_metrics(session_id, bin_start_utc);
GO

-- ============================================================================
-- 4.5: swim_nat_track_throughput
-- ============================================================================
CREATE TABLE dbo.swim_nat_track_throughput (
    throughput_id           BIGINT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    config_id               INT NOT NULL,
    config_label            NVARCHAR(64) NULL,
    tracks_json             NVARCHAR(MAX) NULL,
    origins_json            NVARCHAR(MAX) NULL,
    destinations_json       NVARCHAR(MAX) NULL,
    bin_start_utc           DATETIME2(0) NOT NULL,
    bin_end_utc             DATETIME2(0) NOT NULL,
    max_acph                INT NOT NULL,
    actual_count            INT NOT NULL DEFAULT 0,
    actual_rate_hr          INT NULL,
    utilization_pct         FLOAT NULL,
    computed_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT UQ_swim_ntt_session_config_bin
        UNIQUE (session_id, config_id, bin_start_utc)
);
GO

-- ============================================================================
-- 4.7: CTP flow provider registration
-- ============================================================================
INSERT INTO dbo.swim_tmi_flow_providers (
    provider_id, provider_code, provider_name, api_base_url,
    auth_type, sync_enabled, is_active, priority, created_at, updated_at
)
VALUES (
    (SELECT ISNULL(MAX(provider_id), 0) + 1 FROM dbo.swim_tmi_flow_providers),
    'CTP', 'Cross the Pond', 'https://perti.vatcscc.org/api/ctp',
    'session', 0, 1, 10, SYSUTCDATETIME(), SYSUTCDATETIME()
);
GO

-- ============================================================================
-- Sync state watermarks for CTP tables
-- ============================================================================
INSERT INTO dbo.swim_sync_state (table_name, last_sync_utc, last_row_count, sync_mode)
VALUES ('ctp_nat_track_metrics', '2000-01-01', 0, 'delta');
GO

INSERT INTO dbo.swim_sync_state (table_name, last_sync_utc, last_row_count, sync_mode)
VALUES ('ctp_nat_track_throughput', '2000-01-01', 0, 'delta');
GO
```

- [ ] **Step 2: Run the SWIM migration**

```bash
sqlcmd -S vatsim.database.windows.net -d SWIM_API -U jpeterson -P Jhp21012 -i database/migrations/swim/030_swim_nat_track_metrics.sql
```

- [ ] **Step 3: Verify migration**

```sql
-- Verify new columns
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'swim_flights'
AND COLUMN_NAME IN ('resolved_nat_track', 'nat_track_resolved_at', 'nat_track_source');
-- Expected: 3 rows

-- Verify new tables
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_NAME IN ('swim_nat_track_metrics', 'swim_nat_track_throughput');
-- Expected: 2 rows

-- Verify CTP provider
SELECT provider_code FROM dbo.swim_tmi_flow_providers WHERE provider_code = 'CTP';
-- Expected: 1 row

-- Verify sync state watermarks
SELECT table_name FROM dbo.swim_sync_state
WHERE table_name IN ('ctp_nat_track_metrics', 'ctp_nat_track_throughput');
-- Expected: 2 rows
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/swim/030_swim_nat_track_metrics.sql
git commit -m "feat(swim): migration 030 — NAT track metrics, throughput tables, CTP provider"
```

---

## Task 3: SWIM BulkUpsert SP Extension (031)

**Files:**
- Create: `database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql`
- Reference: `database/migrations/swim/026_swim_data_isolation.sql` (current SP v2.0)

**Context:** The existing `sp_Swim_BulkUpsert` uses OPENJSON WITH 121 columns and a row_hash of 20 volatile columns (the SP comment says "~19" but actual count is 20: lat, lon, altitude_ft, groundspeed_kts, heading_deg, phase, is_active, current_artcc, eta/etd/out/off/on/in/ctd/cta_utc, ctl_type, delay_minutes, gs_held, fp_route). We add 3 NAT columns to OPENJSON (124 total) and add `resolved_nat_track` to the row-hash (21 volatile columns). This must be a CREATE OR ALTER to replace the existing SP.

- [ ] **Step 1: Read current SP to get exact column list**

Read `database/migrations/swim/026_swim_data_isolation.sql` to extract the full OPENJSON WITH clause and row_hash formula. The new SP must include ALL existing 121 columns plus the 3 new ones.

- [ ] **Step 2: Write SWIM migration 031**

Create `database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql`. The SP must:
1. Add to OPENJSON WITH clause:
   - `resolved_nat_track NVARCHAR(8) '$.resolved_nat_track'`
   - `nat_track_resolved_at NVARCHAR(30) '$.nat_track_resolved_at'`
   - `nat_track_source NVARCHAR(8) '$.nat_track_source'`
2. Add `resolved_nat_track` to the row_hash SHA1 calculation (21st volatile column, after the existing 20)
3. Add to MERGE SET clause for WHEN MATCHED
4. Add to INSERT column list for WHEN NOT MATCHED
5. Add `resolved_nat_track` to change_feed `changed_cols` detection

The SP is ~350 lines. Copy the full SP from migration 026, make the additions, and save as v3.0.

- [ ] **Step 3: Run SWIM migration 031**

```bash
sqlcmd -S vatsim.database.windows.net -d SWIM_API -U jpeterson -P Jhp21012 -i database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql
```

- [ ] **Step 4: Verify SP was updated**

```sql
-- Check SP exists and was recently modified
SELECT modify_date FROM sys.procedures WHERE name = 'sp_Swim_BulkUpsert';
-- Expected: today's date

-- Check SP definition includes new columns
SELECT OBJECT_DEFINITION(OBJECT_ID('dbo.sp_Swim_BulkUpsert'));
-- Verify: contains 'resolved_nat_track', 'nat_track_resolved_at', 'nat_track_source'
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql
git commit -m "feat(swim): migration 031 — sp_Swim_BulkUpsert v3 with NAT track columns"
```

---

## Task 4: Extract NAT Track Shared Functions

**Files:**
- Create: `load/services/NATTrackFunctions.php`
- Modify: `api/data/playbook/nat_tracks.php`

**Context:** The resolver (Task 5) and sync daemon both need `fetchNatTrakTracks()`, `fetchCTPTracks()`, `mergeTrackSources()`, and `normalizeNATName()`. Currently these are defined only in `nat_tracks.php`. Extract to a shared file.

**Note:** This file is not in the spec's Section 16 file list (which only lists `NATTrackResolver.php`). This is a plan-level architectural decision to separate function extraction (pure refactoring) from resolver logic (new feature), making each change smaller and independently verifiable.

- [ ] **Step 1: Create NATTrackFunctions.php**

Create `load/services/NATTrackFunctions.php` containing the 5 functions extracted from `api/data/playbook/nat_tracks.php` (lines 71-284):
- `fetchNatTrakTracks()` (uses global `$conn_pdo` for MySQL cache)
- `fetchCTPTracks($session_id)` (uses `get_conn_tmi()` for Azure SQL)
- `mergeTrackSources($nattrak, $ctp)`
- `normalizeNATName($name)`
- `buildNATAliases($name)` — update regex from `NAT[\s-]*([A-Z]+)` to `NAT[\s-]*([A-Z0-9]{1,5})` per spec Section 5.4

The file must:
- Start with `<?php` and a docblock
- NOT include config/connect (callers provide connections)
- Use a guard: `if (defined('NAT_TRACK_FUNCTIONS_LOADED')) return; define('NAT_TRACK_FUNCTIONS_LOADED', true);`
- Keep the same function signatures

```php
<?php
/**
 * Shared NAT Track Functions
 *
 * Extracted from api/data/playbook/nat_tracks.php for reuse by
 * NATTrackResolver, swim_sync, and the nat_tracks API endpoint.
 */

if (defined('NAT_TRACK_FUNCTIONS_LOADED')) return;
define('NAT_TRACK_FUNCTIONS_LOADED', true);

function fetchNatTrakTracks() {
    // ... exact copy from nat_tracks.php lines 71-168
}

function fetchCTPTracks($session_id = null) {
    // ... exact copy from nat_tracks.php lines 177-227
}

function mergeTrackSources($nattrak, $ctp) {
    // ... exact copy from nat_tracks.php lines 236-251
}

function normalizeNATName($name) {
    $upper = strtoupper(trim($name));
    // Updated: added TRAK to prefix list for consistency with token regex
    $upper = preg_replace('/^(TRACK|TRAK|TRK|NAT)\s*-?\s*/', 'NAT', $upper);
    return $upper;
}

function buildNATAliases($name) {
    // Updated regex: [A-Z] -> [A-Z0-9]{1,5}
    $aliases = [$name];
    $upper = strtoupper($name);
    if (preg_match('/NAT[\s-]*([A-Z0-9]{1,5})/i', $upper, $m)) {
        $letter = $m[1];
        $aliases[] = 'NAT' . $letter;
        $aliases[] = 'NAT-' . $letter;
        $aliases[] = 'TRACK' . $letter;
        $aliases[] = 'TRAK' . $letter;
        $aliases[] = 'TRK' . $letter;
    }
    return array_values(array_unique($aliases));
}
```

- [ ] **Step 2: Update nat_tracks.php to use shared functions**

Modify `api/data/playbook/nat_tracks.php`:
1. Add `require_once __DIR__ . '/../../../load/services/NATTrackFunctions.php';` after line 18 (after connect.php include)
2. Remove function definitions at lines 71-284 (fetchNatTrakTracks, fetchCTPTracks, mergeTrackSources, normalizeNATName, buildNATAliases)
3. Keep the top-level flow logic (lines 39-62) unchanged — it still calls the same functions

- [ ] **Step 3: Verify nat_tracks.php still works**

```bash
curl -s "https://perti.vatcscc.org/api/data/playbook/nat_tracks.php" | python -m json.tool | head -20
```
Expected: `{"status":"success","count":...,"tracks":[...]}` — same response as before.

- [ ] **Step 4: Commit**

```bash
git add load/services/NATTrackFunctions.php api/data/playbook/nat_tracks.php
git commit -m "refactor: extract NAT track shared functions for reuse by resolver and sync"
```

---

## Task 5: NAT Track Resolver Service

**Files:**
- Create: `load/services/NATTrackResolver.php`

**Context:** Spec Section 5. Hybrid resolution: Step 1 token regex (fast), Step 2 full sequence match (fallback). Used by detect.php, modify_route.php, and swim_sync daemon.

- [ ] **Step 1: Create NATTrackResolver.php**

```php
<?php
/**
 * NAT Track Resolver
 *
 * Resolves which NAT track a CTP flight is using via hybrid approach:
 * 1. Token detection in filed route (fast path)
 * 2. Full sequence match against oceanic waypoints (fallback)
 *
 * @see docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md Section 5
 */

if (defined('NAT_TRACK_RESOLVER_LOADED')) return;
define('NAT_TRACK_RESOLVER_LOADED', true);

require_once __DIR__ . '/NATTrackFunctions.php';

/**
 * Resolve which NAT track a flight is using.
 *
 * @param string $filed_route       Full filed route string (may contain NAT token)
 * @param string $seg_oceanic_route Oceanic segment route (space-separated waypoints)
 * @param array  $active_tracks     Array of track arrays, each with 'name' and 'route_string'
 * @return array|null ['track' => 'NATA', 'source' => 'TOKEN'|'SEQUENCE'] or null
 */
function resolveNATTrack(
    string $filed_route,
    string $seg_oceanic_route,
    array $active_tracks
): ?array {
    // Step 1: Token detection (fast path)
    $result = resolveNATTrackByToken($filed_route, $active_tracks);
    if ($result !== null) {
        return $result;
    }

    // Step 2: Full sequence match (fallback)
    if ($seg_oceanic_route !== '') {
        $result = resolveNATTrackBySequence($seg_oceanic_route, $active_tracks);
        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

/**
 * Step 1: Scan route for NAT token pattern.
 */
function resolveNATTrackByToken(string $route, array $active_tracks): ?array {
    // Pattern: NAT, TRACK, TRAK, TRK with optional hyphen, 1-5 alphanumeric chars
    if (!preg_match('/\b(?:NAT|TRACK|TRAK|TRK)-?([A-Z0-9]{1,5})\b/i', $route, $m)) {
        return null;
    }

    $identifier = strtoupper($m[1]);
    $canonical = 'NAT' . $identifier;

    // Verify track exists in active tracks
    foreach ($active_tracks as $trk) {
        $norm = normalizeNATName($trk['name']);
        if ($norm === $canonical) {
            return ['track' => $canonical, 'source' => 'TOKEN'];
        }
    }

    return null; // Token found but not in active tracks
}

/**
 * Step 2: Match flight's oceanic waypoint sequence against track route strings.
 * Requires exact full match (same fixes, same order, same count).
 */
function resolveNATTrackBySequence(string $seg_oceanic_route, array $active_tracks): ?array {
    $flight_wpts = parseWaypointSequence($seg_oceanic_route);
    if (empty($flight_wpts)) {
        return null;
    }

    $matches = [];
    foreach ($active_tracks as $trk) {
        if (empty($trk['route_string'])) continue;

        $track_wpts = parseWaypointSequence($trk['route_string']);
        if (empty($track_wpts)) continue;

        // Full match: same fixes, same order, same count
        if ($flight_wpts === $track_wpts) {
            $matches[] = normalizeNATName($trk['name']);
        }
    }

    // Exactly one match = resolved; zero or multiple = ambiguous
    if (count($matches) === 1) {
        return ['track' => $matches[0], 'source' => 'SEQUENCE'];
    }

    return null;
}

/**
 * Parse a route string into an ordered array of uppercase waypoint identifiers.
 * Strips DCT, SID/STAR tokens, airway designators — keeps only fix names.
 */
function parseWaypointSequence(string $route): array {
    $tokens = preg_split('/\s+/', strtoupper(trim($route)));
    $wpts = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '' || $tok === 'DCT' || $tok === 'DIRECT') continue;
        // Skip airway designators (letter + digits pattern like J584, UB881)
        if (preg_match('/^[A-Z]{1,2}\d{1,4}$/', $tok)) continue;
        $wpts[] = $tok;
    }
    return $wpts;
}

/**
 * Get active tracks with caching for batch operations.
 * Call once per sync cycle, pass result to resolveNATTrack for each flight.
 *
 * @param int|null $session_id CTP session ID for CTP overrides
 * @return array Active track definitions
 */
function getActiveTracksForResolution(?int $session_id = null): array {
    $nattrak = fetchNatTrakTracks();
    $ctp = fetchCTPTracks($session_id);
    return mergeTrackSources($nattrak, $ctp);
}

/**
 * Resolve and persist NAT track for a single CTP flight.
 *
 * @param resource $conn_tmi  Azure SQL connection to VATSIM_TMI
 * @param int      $ctp_control_id
 * @param string   $filed_route
 * @param string   $seg_oceanic_route
 * @param array    $active_tracks
 * @return array|null Resolution result or null
 */
function resolveAndPersistNATTrack(
    $conn_tmi,
    int $ctp_control_id,
    string $filed_route,
    string $seg_oceanic_route,
    array $active_tracks
): ?array {
    $result = resolveNATTrack($filed_route, $seg_oceanic_route, $active_tracks);

    if ($result !== null) {
        $sql = "UPDATE dbo.ctp_flight_control
                SET resolved_nat_track = ?,
                    nat_track_resolved_at = SYSUTCDATETIME(),
                    nat_track_source = ?,
                    swim_push_version = swim_push_version + 1
                WHERE ctp_control_id = ?";
        sqlsrv_query($conn_tmi, $sql, [$result['track'], $result['source'], $ctp_control_id]);
    }

    return $result;
}
```

- [ ] **Step 2: Verify file loads without errors**

Upload temporarily or test locally:
```php
<?php
require_once 'load/config.php';
require_once 'load/connect.php';
require_once 'load/services/NATTrackResolver.php';
echo "Loaded OK\n";
$tracks = getActiveTracksForResolution();
echo "Active tracks: " . count($tracks) . "\n";
```

- [ ] **Step 3: Commit**

```bash
git add load/services/NATTrackResolver.php
git commit -m "feat(ctp): NAT track resolver — hybrid token + sequence match"
```

---

## Task 6: CTP Flight Endpoint Modifications

**Files:**
- Modify: `api/ctp/flights/detect.php` (line ~301)
- Modify: `api/ctp/flights/modify_route.php` (line ~157)
- Modify: `api/ctp/flights/assign_edct.php` (line ~180)
- Modify: `api/ctp/flights/assign_edct_batch.php`
- Modify: `api/ctp/flights/remove_edct.php` (line ~99)

**Context:** Spec Sections 5.2 and 6.3. Add NAT resolution to detect and modify_route. Add SWIM immediate push to all EDCT endpoints.

- [ ] **Step 1: Add resolver include to all 5 files**

Each file already includes `common.php`. Add after the `require_once(__DIR__ . '/../common.php')` line in each file:

```php
require_once(__DIR__ . '/../../../load/services/NATTrackResolver.php');
```

Files and their include line numbers:
- `detect.php`: after line 23
- `modify_route.php`: after line 23
- `assign_edct.php`: after line 23
- `assign_edct_batch.php`: after line 23
- `remove_edct.php`: after line 23

- [ ] **Step 2: Modify detect.php — add NAT resolution after batch insert**

After the batch insert loop (after line 295 `$detected++`), before the "Update session stats" section (line 297), add NAT resolution for newly detected flights:

```php
// Resolve NAT tracks for newly detected flights
if ($detected > 0) {
    $active_tracks = getActiveTracksForResolution($session_id);
    // Token matching uses filed_route (works immediately); sequence matching needs seg_oceanic_route.
    // Include flights with either field so token resolution works even before oceanic decomposition.
    $resolve_sql = "SELECT ctp_control_id, filed_route, seg_oceanic_route
                    FROM dbo.ctp_flight_control
                    WHERE session_id = ? AND resolved_nat_track IS NULL
                      AND (filed_route IS NOT NULL OR seg_oceanic_route IS NOT NULL)";
    $resolve_result = ctp_fetch_all($conn_tmi, $resolve_sql, [$session_id]);
    if ($resolve_result['success'] && !empty($resolve_result['data'])) {
        $resolved_count = 0;
        foreach ($resolve_result['data'] as $f) {
            $res = resolveAndPersistNATTrack(
                $conn_tmi,
                (int)$f['ctp_control_id'],
                $f['filed_route'] ?? '',          // Full filed route for token matching
                $f['seg_oceanic_route'] ?? '',     // Oceanic segment for sequence matching
                $active_tracks
            );
            if ($res !== null) $resolved_count++;
        }
    }
}
```

Also update the response data to include resolved count:
```php
'nat_resolved' => $resolved_count ?? 0,
```

- [ ] **Step 3: Modify modify_route.php — re-resolve NAT track**

After the successful UPDATE (line 157) and before the audit log (line 159), add:

```php
// Re-resolve NAT track after route modification
if ($segment === 'OCEANIC' || $segment === 'FULL') {
    $active_tracks = getActiveTracksForResolution($session_id);
    $new_oceanic = ($segment === 'OCEANIC') ? $route_string : ($flight['seg_oceanic_route'] ?? '');
    $filed = $flight['filed_route'] ?? '';
    resolveAndPersistNATTrack($conn_tmi, $ctp_control_id, $filed, $new_oceanic, $active_tracks);

    // SWIM immediate push for resolved_nat_track
    $conn_swim = get_conn_swim();
    if ($conn_swim && !empty($flight['flight_uid'])) {
        $nat_data = ctp_fetch_one($conn_tmi,
            "SELECT resolved_nat_track, nat_track_resolved_at, nat_track_source FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
            [$ctp_control_id]);
        if ($nat_data['success'] && $nat_data['data']) {
            $nd = $nat_data['data'];
            sqlsrv_query($conn_swim,
                "UPDATE dbo.swim_flights SET resolved_nat_track = ?, nat_track_resolved_at = ?, nat_track_source = ? WHERE flight_uid = ?",
                [$nd['resolved_nat_track'], $nd['nat_track_resolved_at'], $nd['nat_track_source'], $flight['flight_uid']]);
        }
    }
}
```

- [ ] **Step 4: Modify assign_edct.php — add SWIM immediate push**

After the existing `ctp_push_swim_event` call (line 174-180) and **BEFORE** `respond_json(200, ...)` at line 182 (which calls `exit` — any code after it won't execute), add:

```php
// SWIM immediate push for resolved_nat_track columns
$conn_swim = get_conn_swim();
if ($conn_swim && !empty($flight['flight_uid'])) {
    $nat_data = ctp_fetch_one($conn_tmi,
        "SELECT resolved_nat_track, nat_track_resolved_at, nat_track_source FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
        [$ctp_control_id]);
    if ($nat_data['success'] && $nat_data['data']) {
        $nd = $nat_data['data'];
        sqlsrv_query($conn_swim,
            "UPDATE dbo.swim_flights SET resolved_nat_track = ?, nat_track_resolved_at = ?, nat_track_source = ? WHERE flight_uid = ?",
            [$nd['resolved_nat_track'], $nd['nat_track_resolved_at'], $nd['nat_track_source'], $flight['flight_uid']]);
    }
}
```

- [ ] **Step 5: Modify assign_edct_batch.php — add SWIM batch push**

After the batch processing loop completes (before the final response), add the same SWIM push logic but for all flights in the batch. Collect flight_uids during the loop, then do a single batch push.

- [ ] **Step 6: Modify remove_edct.php — add SWIM immediate push**

After the existing `ctp_push_swim_event` call (line 99), add the same SWIM push pattern as Step 4.

- [ ] **Step 7: Verify all endpoints still work**

Test each endpoint via curl against a test session:
```bash
# detect
curl -s -X POST "https://perti.vatcscc.org/api/ctp/flights/detect.php" \
  -H "Content-Type: application/json" \
  -d '{"session_id": 1}' --cookie "..."

# Each should return {"status":"ok","data":{...}} with no PHP errors
```

- [ ] **Step 8: Commit**

```bash
git add api/ctp/flights/detect.php api/ctp/flights/modify_route.php \
        api/ctp/flights/assign_edct.php api/ctp/flights/assign_edct_batch.php \
        api/ctp/flights/remove_edct.php
git commit -m "feat(ctp): NAT resolution + SWIM immediate push in flight endpoints"
```

---

## Task 7: CTP Demand API Extension

**Files:**
- Modify: `api/ctp/demand.php`

**Context:** Spec Section 8.2. Add `group_by=nat_track` option. Flights with `resolved_nat_track IS NULL` grouped as "Random Route". Same bin/label/dataset format.

- [ ] **Step 1: Add nat_track to allowed group_by values**

Find the `in_array` validation for `group_by` (currently `['fir', 'status', 'fix']`). Add `'nat_track'`:
```php
if (!in_array($group_by, ['fir', 'status', 'fix', 'nat_track'])) {
```

- [ ] **Step 2: Add nat_track to SQL query columns**

Find the SELECT query for demand data. Add `resolved_nat_track` to the column list:
```sql
SELECT oceanic_entry_utc, edct_utc, edct_status, oceanic_entry_fir, oceanic_entry_fix, resolved_nat_track
```

- [ ] **Step 3: Add nat_track to group_col mapping**

Find the `if ($group_by === 'fir')` / `elseif ($group_by === 'fix')` block. Add after the `'fix'` case:
```php
elseif ($group_by === 'nat_track') $group_col = 'resolved_nat_track';
```

- [ ] **Step 4: Add nat_track grouping logic**

Find the flight grouping switch that handles `'status'`, `'fir'`, `'fix'`. Add the `'nat_track'` case:
```php
} elseif ($group_by === 'nat_track') {
    $group_val = $f['resolved_nat_track'] ?? 'Random Route';
}
```

- [ ] **Step 5: Add NAT track colors**

After the `$fir_palette` array definition, add:
```php
// NAT track colors (distinctive per track letter)
$nat_palette = [
    'rgba(229,57,53,0.7)',    // Red
    'rgba(0,150,136,0.7)',    // Teal
    'rgba(156,39,176,0.7)',   // Purple
    'rgba(255,152,0,0.7)',    // Orange
    'rgba(33,150,243,0.7)',   // Blue
    'rgba(76,175,80,0.7)',    // Green
    'rgba(233,30,99,0.7)',    // Pink
    'rgba(121,85,72,0.7)',    // Brown
];
```

Update the color assignment block (where `$group_by === 'status'` selects `$status_colors`) to include nat_track:
```php
} elseif ($group_by === 'nat_track') {
    if ($gk === 'Random Route') {
        $color = 'rgba(173,181,189,0.7)'; // Grey for random route
    } else {
        $color = $nat_palette[$idx % count($nat_palette)];
    }
}
```

- [ ] **Step 6: Verify**

```bash
curl -s "https://perti.vatcscc.org/api/ctp/demand.php?session_id=1&group_by=nat_track" | python -m json.tool
```
Expected: Same format as other group_by values but with NAT track names as dataset labels.

- [ ] **Step 7: Commit**

```bash
git add api/ctp/demand.php
git commit -m "feat(ctp): add group_by=nat_track to demand API"
```

---

## Task 8: Throughput Config CRUD API

**Files:**
- Create: `api/ctp/throughput/list.php`
- Create: `api/ctp/throughput/create.php`
- Create: `api/ctp/throughput/update.php`
- Create: `api/ctp/throughput/delete.php`
- Create: `api/ctp/throughput/preview.php`

**Context:** Spec Section 8.1. All follow CTP API pattern from `common.php`. All mutations audit-logged. Update uses optimistic concurrency via `expected_updated_at`.

- [ ] **Step 1: Create list.php**

Standard CTP endpoint pattern:
```php
<?php
header('Content-Type: application/json; charset=utf-8');
// OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { /* ... */ exit; }
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();
$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) respond_json(400, ['status' => 'error', 'message' => 'session_id required.']);

$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);

$result = ctp_fetch_all($conn_tmi,
    "SELECT config_id, session_id, config_label, tracks_json, origins_json, destinations_json,
            max_acph, priority, is_active, notes, created_by, created_at, updated_at
     FROM dbo.ctp_track_throughput_config
     WHERE session_id = ? AND is_active = 1
     ORDER BY priority ASC, config_label ASC",
    [$session_id]);

if (!$result['success']) respond_json(500, ['status' => 'error', 'message' => 'Query failed.']);

// Parse JSON fields
$configs = array_map(function($row) {
    $row['tracks_json'] = $row['tracks_json'] ? json_decode($row['tracks_json'], true) : null;
    $row['origins_json'] = $row['origins_json'] ? json_decode($row['origins_json'], true) : null;
    $row['destinations_json'] = $row['destinations_json'] ? json_decode($row['destinations_json'], true) : null;
    foreach (['created_at', 'updated_at'] as $col) {
        if ($row[$col] instanceof \DateTimeInterface) {
            $row[$col] = datetime_to_iso($row[$col]);
        }
    }
    return $row;
}, $result['data']);

respond_json(200, ['status' => 'ok', 'data' => $configs]);
```

- [ ] **Step 2: Create create.php**

POST endpoint. Reads JSON body with: session_id, config_label, tracks_json, origins_json, destinations_json, max_acph, priority, notes. Validates session exists. Checks for overlapping configs (warn, don't block). INSERTs into `ctp_track_throughput_config`. Audit logs with `THROUGHPUT_CONFIG_CREATE`. Pushes SWIM event.

Key SQL:
```sql
INSERT INTO dbo.ctp_track_throughput_config
    (session_id, config_label, tracks_json, origins_json, destinations_json,
     max_acph, priority, notes, created_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);
SELECT SCOPE_IDENTITY() AS config_id;
```

- [ ] **Step 3: Create update.php**

POST endpoint. Requires `config_id` + `expected_updated_at` for optimistic concurrency:
```sql
UPDATE dbo.ctp_track_throughput_config
SET config_label = ?, tracks_json = ?, origins_json = ?, destinations_json = ?,
    max_acph = ?, priority = ?, notes = ?, updated_at = SYSUTCDATETIME()
WHERE config_id = ? AND updated_at = ?
```
If 0 rows affected → 409 Conflict with current data. Audit logs with `THROUGHPUT_CONFIG_UPDATE`.

- [ ] **Step 4: Create delete.php**

POST endpoint. Soft-delete: `UPDATE SET is_active = 0, updated_at = SYSUTCDATETIME() WHERE config_id = ?`. Audit logs with `THROUGHPUT_CONFIG_DELETE`.

- [ ] **Step 5: Create preview.php**

GET endpoint. Takes proposed config parameters (or existing config_id) and returns impact analysis:
- Query `ctp_flight_control` for matching flights (using spec Section 4.3 match logic)
- Bin by `oceanic_entry_utc` into 15-min intervals
- Return: bins with counts, bins exceeding max_acph, total matching flights

- [ ] **Step 6: Verify all endpoints**

```bash
# List (should return empty array for new session)
curl -s "https://perti.vatcscc.org/api/ctp/throughput/list.php?session_id=1" --cookie "..."

# Create
curl -s -X POST "https://perti.vatcscc.org/api/ctp/throughput/create.php" \
  -H "Content-Type: application/json" --cookie "..." \
  -d '{"session_id":1,"config_label":"Test Config","max_acph":20,"priority":50}'
```

- [ ] **Step 7: Commit**

```bash
git add api/ctp/throughput/
git commit -m "feat(ctp): throughput config CRUD API — list, create, update, delete, preview"
```

---

## Task 9: Planning Simulator CRUD API

**Files:**
- Create: `api/ctp/planning/scenarios.php`
- Create: `api/ctp/planning/scenario_save.php`
- Create: `api/ctp/planning/scenario_delete.php`
- Create: `api/ctp/planning/scenario_clone.php`
- Create: `api/ctp/planning/block_save.php`
- Create: `api/ctp/planning/block_delete.php`
- Create: `api/ctp/planning/assignment_save.php`
- Create: `api/ctp/planning/assignment_delete.php`

**Context:** Spec Section 8.3. Standard CTP pattern. Scenario ownership by `created_by` CID. CASCADE deletes from scenario→blocks→assignments.

- [ ] **Step 1: Create scenarios.php (GET + POST)**

GET: List scenarios for session or all by user.
POST: Create new scenario.

```php
// GET params: session_id (optional), status (optional)
// POST body: session_id, scenario_name, departure_window_start, departure_window_end, notes
```

Key SQL for GET:
```sql
SELECT scenario_id, session_id, scenario_name, departure_window_start, departure_window_end,
       status, notes, created_by, created_at, updated_at
FROM dbo.ctp_planning_scenarios
WHERE (session_id = ? OR ? IS NULL)
ORDER BY created_at DESC
```

Key SQL for POST:
```sql
INSERT INTO dbo.ctp_planning_scenarios
    (session_id, scenario_name, departure_window_start, departure_window_end, notes, created_by)
VALUES (?, ?, ?, ?, ?, ?);
SELECT SCOPE_IDENTITY() AS scenario_id;
```

- [ ] **Step 2: Create scenario_save.php**

POST: Update scenario. Only owner can edit. Validates ownership via `created_by = $cid`.

- [ ] **Step 3: Create scenario_delete.php**

POST: Delete scenario. Only owner. CASCADE deletes blocks and assignments automatically.

- [ ] **Step 4: Create scenario_clone.php**

POST: Clone scenario. Creates new scenario with same blocks and assignments but new `created_by`. Uses INSERT...SELECT pattern for blocks and assignments.

Audit log: `SCENARIO_CLONE` with original scenario_id in detail.

- [ ] **Step 5: Create block_save.php**

POST: Create or update traffic block. Validates scenario ownership.
```sql
-- Create
INSERT INTO dbo.ctp_planning_traffic_blocks
    (scenario_id, block_label, origins_json, destinations_json, flight_count,
     dep_distribution, dep_distribution_json, aircraft_mix_json)
VALUES (?, ?, ?, ?, ?, ?, ?, ?);

-- Update (when block_id provided)
UPDATE dbo.ctp_planning_traffic_blocks
SET block_label = ?, origins_json = ?, destinations_json = ?, flight_count = ?,
    dep_distribution = ?, dep_distribution_json = ?, aircraft_mix_json = ?,
    updated_at = SYSUTCDATETIME()
WHERE block_id = ? AND scenario_id = ?;
```

- [ ] **Step 6: Create block_delete.php**

POST: Delete traffic block. CASCADE deletes assignments.

- [ ] **Step 7: Create assignment_save.php and assignment_delete.php**

Standard CRUD for track assignments within a block.

- [ ] **Step 8: Verify all planning endpoints**

```bash
# Create scenario
curl -s -X POST "https://perti.vatcscc.org/api/ctp/planning/scenarios.php" \
  -H "Content-Type: application/json" --cookie "..." \
  -d '{"scenario_name":"Test CTP Plan","departure_window_start":"2026-10-19T12:00:00Z","departure_window_end":"2026-10-19T18:00:00Z"}'

# List
curl -s "https://perti.vatcscc.org/api/ctp/planning/scenarios.php" --cookie "..."
```

- [ ] **Step 9: Commit**

```bash
git add api/ctp/planning/
git commit -m "feat(ctp): planning simulator CRUD — scenarios, blocks, track assignments"
```

---

## Task 10: Planning Simulator Compute Engine

**Files:**
- Create: `api/ctp/planning/compute.php`
- Create: `api/ctp/planning/apply_to_session.php`

**Context:** Spec Section 9. Most complex endpoint. Requires 3 DB connections: GIS (expand_route), ADL (fn_GetAircraftPerformance), TMI (planning tables). Cannot use PERTI_MYSQL_ONLY.

- [ ] **Step 1: Create compute.php**

POST endpoint. The 5-step algorithm from spec Section 9.1:

```php
<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { /* preflight */ exit; }
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
// NOTE: No PERTI_MYSQL_ONLY — needs $conn_gis and $conn_adl

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();
$conn_gis = ctp_get_conn_gis();
$conn_adl = ctp_get_conn_adl();

$payload = read_request_payload();
$scenario_id = (int)($payload['scenario_id'] ?? 0);
if ($scenario_id <= 0) respond_json(400, ['status' => 'error', 'message' => 'scenario_id required.']);

// Fetch scenario
$scenario = ctp_fetch_one($conn_tmi,
    "SELECT * FROM dbo.ctp_planning_scenarios WHERE scenario_id = ?",
    [$scenario_id]);
if (!$scenario['success'] || !$scenario['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Scenario not found.']);
}
$sc = $scenario['data'];

// Fetch blocks + assignments
$blocks = ctp_fetch_all($conn_tmi,
    "SELECT b.*, a.assignment_id, a.track_name, a.route_string AS assign_route, a.flight_count AS assign_count, a.altitude_range
     FROM dbo.ctp_planning_traffic_blocks b
     LEFT JOIN dbo.ctp_planning_track_assignments a ON b.block_id = a.block_id
     WHERE b.scenario_id = ?
     ORDER BY b.block_id, a.assignment_id",
    [$scenario_id]);

if (!$blocks['success'] || empty($blocks['data'])) {
    respond_json(400, ['status' => 'error', 'message' => 'Scenario has no traffic blocks.', 'code' => 'EMPTY_SCENARIO']);
}

// Step 1-5 implementation
// ... (full algorithm from spec)
```

The compute engine is complex (~200 lines). Key pieces:

**Step 1 — Spread departures:**
```php
function spreadDepartures($flight_count, $window_start, $window_end, $distribution, $custom_weights = null) {
    $window_sec = $window_end - $window_start;
    $bin_sec = 900; // 15 min
    $num_bins = max(1, (int)ceil($window_sec / $bin_sec));
    $dep_times = [];
    // ... distribution logic per spec
    return $dep_times; // Array of Unix timestamps
}
```

**Step 2 — Route expansion via PostGIS:**
```php
function expandRouteForCompute($conn_gis, $route_string) {
    $stmt = $conn_gis->prepare("SELECT * FROM expand_route(?)");
    $stmt->execute([$route_string]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Step 3 — Aircraft performance lookup:**
```php
function getAircraftPerformance($conn_adl, $aircraft_icao, $weight_class, $engine_type) {
    $sql = "SELECT cruise_speed_ktas, cruise_mach, optimal_fl, source
            FROM dbo.fn_GetAircraftPerformance(?, ?, ?)";
    $stmt = sqlsrv_query($conn_adl, $sql, [$aircraft_icao, $weight_class, $engine_type]);
    // ... fallback to 460 kts if not found
}
```

**Step 5 — Constraint checks (skip if session_id IS NULL):**
```php
if ($sc['session_id'] !== null) {
    // Load throughput configs for session, check each bin against constraints
} else {
    $constraint_checks = [];
    $constraint_note = 'No session linked — constraint checks skipped.';
}
```

Response format matches spec Section 9.2 exactly with ECharts-compatible `[timestamp_ms, value]` pairs.

- [ ] **Step 2: Create apply_to_session.php**

POST endpoint. Promotes scenario to live throughput configs:
1. Validate scenario has session_id set
2. For each traffic block's track assignment, create a `ctp_track_throughput_config` entry
3. Audit log with `SCENARIO_APPLY` and full mapping in `action_detail_json`

- [ ] **Step 3: Verify compute endpoint**

```bash
# Create a scenario with blocks/assignments first, then compute
curl -s -X POST "https://perti.vatcscc.org/api/ctp/planning/compute.php" \
  -H "Content-Type: application/json" --cookie "..." \
  -d '{"scenario_id": 1}'
```

- [ ] **Step 4: Commit**

```bash
git add api/ctp/planning/compute.php api/ctp/planning/apply_to_session.php
git commit -m "feat(ctp): planning simulator compute engine + apply_to_session"
```

---

## Task 11: SWIM Sync Extension

**Files:**
- Modify: `scripts/swim_sync_daemon.php` (after line 158)
- Modify: `scripts/swim_sync.php` (add new function)

**Context:** Spec Section 6. Add `swim_sync_ctp_to_swim()` after existing `swim_sync_from_adl()`. Three independent sub-steps: per-flight sync, metrics recompute, throughput utilization recompute. Each in its own try/catch per spec Section 6.5.

- [ ] **Step 1: Add CTP sync call to daemon**

In `scripts/swim_sync_daemon.php`, insert between the sync catch block (line 158 `}` closing the catch) and the cleanup section (line 160 `// Run Cleanup`). The new code goes at line 159, before `$timeSinceCleanup = time() - $lastCleanupTime;`:

```php
    // ========================================================================
    // Run CTP-to-SWIM Sync (if active CTP session)
    // ========================================================================
    try {
        $ctp_result = swim_sync_ctp_to_swim();
        if ($ctp_result['success']) {
            if ($ctp_result['skipped']) {
                // No active CTP session — zero overhead
            } else {
                swim_log("CTP sync: " . $ctp_result['message']);
            }
        } else {
            swim_log("CTP sync error: " . $ctp_result['message'], 'ERROR');
        }
    } catch (Throwable $e) {
        swim_log("CTP sync exception: " . $e->getMessage(), 'ERROR');
    }
```

- [ ] **Step 2: Create swim_sync_ctp_to_swim() in swim_sync.php**

Add to end of `scripts/swim_sync.php`:

```php
/**
 * Sync CTP-specific data to SWIM.
 * Three independent sub-steps, each in its own try/catch:
 *   1. Per-flight sync (resolved_nat_track → swim_flights)
 *   2. Metrics recompute (ctp_flight_control → swim_nat_track_metrics)
 *   3. Throughput utilization recompute (configs → swim_nat_track_throughput)
 *
 * @return array ['success' => bool, 'message' => string, 'skipped' => bool]
 */
function swim_sync_ctp_to_swim() {
    global $conn_tmi, $conn_swim;

    if (!$conn_tmi) {
        $conn_tmi = get_conn_tmi();
        if (!$conn_tmi) return ['success' => false, 'message' => 'TMI connection unavailable', 'skipped' => false];
    }
    if (!$conn_swim) {
        $conn_swim = get_conn_swim();
        if (!$conn_swim) return ['success' => false, 'message' => 'SWIM connection unavailable', 'skipped' => false];
    }

    // Pre-check: any active CTP sessions?
    $sess_result = sqlsrv_query($conn_tmi,
        "SELECT session_id FROM dbo.ctp_sessions WHERE status IN ('ACTIVE', 'MONITORING')");
    if ($sess_result === false) {
        return ['success' => false, 'message' => 'Failed to check CTP sessions', 'skipped' => false];
    }
    $active_sessions = [];
    while ($row = sqlsrv_fetch_array($sess_result, SQLSRV_FETCH_ASSOC)) {
        $active_sessions[] = (int)$row['session_id'];
    }
    sqlsrv_free_stmt($sess_result);

    if (empty($active_sessions)) {
        return ['success' => true, 'message' => 'No active CTP sessions', 'skipped' => true];
    }

    $stats = ['flights_synced' => 0, 'metrics_bins' => 0, 'throughput_bins' => 0, 'errors' => 0];

    // Sub-step 1: Per-flight sync
    try {
        $stats['flights_synced'] = swim_sync_ctp_flights($conn_tmi, $conn_swim, $active_sessions);
    } catch (Throwable $e) {
        $stats['errors']++;
        swim_sync_increment_error($conn_swim, 'ctp_nat_track_metrics');
    }

    // Sub-step 2: Metrics recompute (only if flight sync succeeded)
    if ($stats['errors'] === 0) {
        try {
            $stats['metrics_bins'] = swim_sync_ctp_metrics($conn_tmi, $conn_swim, $active_sessions);
        } catch (Throwable $e) {
            $stats['errors']++;
            swim_sync_increment_error($conn_swim, 'ctp_nat_track_metrics');
        }
    }

    // Sub-step 3: Throughput utilization recompute
    try {
        $stats['throughput_bins'] = swim_sync_ctp_throughput($conn_tmi, $conn_swim, $active_sessions);
    } catch (Throwable $e) {
        $stats['errors']++;
        swim_sync_increment_error($conn_swim, 'ctp_nat_track_throughput');
    }

    $msg = sprintf("CTP sync: %d flights, %d metric bins, %d throughput bins, %d errors",
        $stats['flights_synced'], $stats['metrics_bins'], $stats['throughput_bins'], $stats['errors']);

    return ['success' => $stats['errors'] === 0, 'message' => $msg, 'skipped' => false];
}
```

Then implement the three sub-functions:

**`swim_sync_ctp_flights()`**: Query `ctp_flight_control WHERE updated_at > swim_pushed_at`, batch UPDATE `swim_flights` for resolved_nat_track columns, update `swim_pushed_at`.

**`swim_sync_ctp_metrics()`**: Delta-driven bin recompute using spec Section 6.2 formula. MERGE into `swim_nat_track_metrics`. Update `swim_sync_state`.

**`swim_sync_ctp_throughput()`**: For each active config, aggregate per bin using match logic. MERGE into `swim_nat_track_throughput`. Update `swim_sync_state` with separate watermark.

**`swim_sync_increment_error()`**: Increment `error_count` in `swim_sync_state` for the given table_name.

- [ ] **Step 3: Verify daemon runs without errors**

Monitor daemon log after restart:
```bash
tail -f /home/LogFiles/swim_sync.log | grep CTP
```
Expected: "No active CTP sessions" when no active sessions, or sync stats when sessions are active.

- [ ] **Step 4: Commit**

```bash
git add scripts/swim_sync_daemon.php scripts/swim_sync.php
git commit -m "feat(swim): CTP-to-SWIM sync — per-flight, metrics recompute, throughput utilization"
```

---

## Task 12: SWIM API Endpoints

**Files:**
- Create: `api/swim/v1/tmi/nat_tracks/metrics.php`
- Create: `api/swim/v1/tmi/nat_tracks/status.php`

**Context:** Spec Sections 7.1 and 7.2. Both use SWIM auth pattern (`swim_init_auth(true)`) and `SwimResponse`. Both use `PERTI_SWIM_ONLY` — all data comes from SWIM_API database (pre-computed by sync daemon).

- [ ] **Step 1: Create directory structure**

```bash
mkdir -p api/swim/v1/tmi/nat_tracks
```

- [ ] **Step 2: Create metrics.php**

```php
<?php
/**
 * VATSWIM API v1 — NAT Track Metrics
 *
 * Pre-computed NAT track throughput metrics for external consumers (CANOC, ECFMP, third-party).
 *
 * GET /api/swim/v1/tmi/nat_tracks/metrics
 *   ?session_id=1 (required)
 *   &track=NATA,NATB (optional, comma-separated)
 *   &bin_min=15|30|60 (optional, default 15)
 *   &from=ISO8601 &to=ISO8601 (optional)
 *   &direction=WESTBOUND|EASTBOUND (optional)
 */

// auth.php defines PERTI_SWIM_ONLY and includes config/connect
require_once __DIR__ . '/../../auth.php';

// Established SWIM TMI pattern: global conn first, then auth
global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, false); // require key, read-only

// Parameters
$session_id = swim_get_int_param('session_id', 0, 0, 999999);
if ($session_id <= 0) SwimResponse::error('session_id is required', 400, 'MISSING_PARAM');

$track_filter = swim_get_param('track', '');
$bin_min = swim_get_int_param('bin_min', 15, 15, 60);
$from = swim_get_param('from', '');
$to = swim_get_param('to', '');
$direction = swim_get_param('direction', '');

// Validate bin_min
if (!in_array($bin_min, [15, 30, 60])) {
    SwimResponse::error('bin_min must be 15, 30, or 60', 400, 'INVALID_PARAM');
}

// Build query
$where = ['m.session_id = ?'];
$params = [$session_id];

if ($track_filter) {
    $tracks = array_map('trim', explode(',', strtoupper($track_filter)));
    $placeholders = implode(',', array_fill(0, count($tracks), '?'));
    $where[] = "m.track_name IN ($placeholders)";
    $params = array_merge($params, $tracks);
}
if ($direction) {
    $where[] = "m.direction = ?";
    $params[] = strtoupper($direction);
}
if ($from) {
    $where[] = "m.bin_start_utc >= ?";
    $params[] = $from;
}
if ($to) {
    $where[] = "m.bin_end_utc <= ?";
    $params[] = $to;
}

$where_sql = implode(' AND ', $where);

// Query raw 15-min bins
$sql = "SELECT track_name, direction, bin_start_utc, bin_end_utc,
               flight_count, slotted_count, compliant_count, avg_delay_min,
               peak_rate_hr, flight_levels_json, origins_json, destinations_json
        FROM dbo.swim_nat_track_metrics m
        WHERE $where_sql
        ORDER BY track_name, bin_start_utc";

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    SwimResponse::error('Database error', 500, 'DB_ERROR');
}

// Fetch and aggregate by bin_min
// ... (aggregate 15-min rows to 30 or 60 min on-the-fly)

SwimResponse::success($response, ['source' => 'swim_api', 'table' => 'swim_nat_track_metrics']);
```

- [ ] **Step 3: Create status.php**

GET endpoint. Track definitions from natTrak HTTP API + occupancy counts from `swim_nat_track_metrics`. No TMI connection needed.

```php
<?php
/**
 * VATSWIM API v1 — NAT Track Status
 *
 * Live snapshot of NAT track definitions + real-time occupancy.
 * Track definitions fetched via HTTP from natTrak API (not database).
 */

require_once __DIR__ . '/../../auth.php';

$auth = swim_init_auth(false); // Optional auth for public access
// ... fetch natTrak + merge CTP + add occupancy counts from swim_nat_track_metrics
```

- [ ] **Step 4: Verify both endpoints**

```bash
# metrics
curl -s -H "X-API-Key: <test-key>" \
  "https://perti.vatcscc.org/api/swim/v1/tmi/nat_tracks/metrics?session_id=1"

# status
curl -s "https://perti.vatcscc.org/api/swim/v1/tmi/nat_tracks/status"
```

- [ ] **Step 5: Commit**

```bash
git add api/swim/v1/tmi/nat_tracks/
git commit -m "feat(swim): NAT track metrics + status SWIM API endpoints"
```

---

## Task 13: JavaScript — NAT Regex Update

**Files:**
- Modify: `assets/js/route-maplibre.js` (line 162)

**Context:** Spec Section 5.4. Update pattern from `/^(?:NAT|TRACK|TRK)-?[A-Z]$/` to include TRAK and multi-char identifiers.

- [ ] **Step 1: Update NAT token pattern**

At `assets/js/route-maplibre.js:162`, change:
```javascript
const natTokenPattern = /^(?:NAT|TRACK|TRK)-?[A-Z]$/;
```
to:
```javascript
const natTokenPattern = /^(?:NAT|TRACK|TRAK|TRK)-?([A-Z0-9]{1,5})$/;
```

- [ ] **Step 2: Verify route plotter still resolves NAT tracks**

Open `https://perti.vatcscc.org/route.php`, enter a route with `NATC` token, verify it expands correctly.

- [ ] **Step 3: Commit**

```bash
git add assets/js/route-maplibre.js
git commit -m "fix(route): update NAT token regex to match TRAK and multi-char identifiers"
```

---

## Task 14: JavaScript — CTP DemandChart ECharts Migration

**Files:**
- Modify: `assets/js/ctp.js` (lines 1927-2019, DemandChart submodule)

**Context:** Spec Section 10. Replace Chart.js rendering with ECharts via `DemandChartCore.createChart()`. DemandChartCore is already loaded globally from `assets/js/demand.js` and available as `window.DemandChart.create` or `DemandChartCore.createChart`.

- [ ] **Step 1: Add new API endpoint references**

At the top of `ctp.js` in the API object (around line 55-58), add:

```javascript
throughput: {
    list:    'api/ctp/throughput/list.php',
    create:  'api/ctp/throughput/create.php',
    update:  'api/ctp/throughput/update.php',
    delete:  'api/ctp/throughput/delete.php',
    preview: 'api/ctp/throughput/preview.php'
},
planning: {
    scenarios:         'api/ctp/planning/scenarios.php',
    scenario_save:     'api/ctp/planning/scenario_save.php',
    scenario_delete:   'api/ctp/planning/scenario_delete.php',
    scenario_clone:    'api/ctp/planning/scenario_clone.php',
    block_save:        'api/ctp/planning/block_save.php',
    block_delete:      'api/ctp/planning/block_delete.php',
    assignment_save:   'api/ctp/planning/assignment_save.php',
    assignment_delete: 'api/ctp/planning/assignment_delete.php',
    compute:           'api/ctp/planning/compute.php',
    apply_to_session:  'api/ctp/planning/apply_to_session.php'
},
```

- [ ] **Step 2: Replace DemandChart.render with ECharts**

Replace the DemandChart submodule (lines 1927-2019) with ECharts-based rendering.

**IMPORTANT:** `DemandChartCore.createChart()` is a self-contained airport demand chart factory that fetches its own data. It is NOT suitable for CTP data. Instead, use direct `echarts.init()` plus `DemandChartCore` utility helpers (`buildRateMarkLinesForChart()`, `getCurrentTimeMarkLineForTimeAxis()`, `formatTimeLabelFromTimestamp()`).

```javascript
var DemandChart = {
    chart: null,
    container: null,

    init: function() {
        this.container = document.getElementById('ctp_demand_chart_container');
        // Container must be a div (not canvas) for ECharts
    },

    refresh: function() {
        if (!this.container || !state.currentSession) return;
        if (typeof echarts === 'undefined') return;
        var self = this;

        $.ajax({
            url: API.demand,
            method: 'GET',
            data: {
                session_id: state.currentSession,
                group_by: state.demandGroupBy || 'status',
                bin_min: state.demandBinMin || 60
            },
            success: function(resp) {
                if (resp.status !== 'ok' || !resp.data) return;
                self.render(resp.data);
            }
        });
    },

    render: function(data) {
        if (!this.container) return;

        // Convert Chart.js labels/datasets to ECharts [timestamp_ms, value] format
        var binMin = data.bin_min || 60;
        var series = [];
        var datasets = data.datasets || [];

        for (var i = 0; i < datasets.length; i++) {
            var ds = datasets[i];
            var seriesData = [];
            for (var j = 0; j < (data.labels || []).length; j++) {
                // Parse label "HH:MM" to timestamp
                var parts = data.labels[j].split(':');
                var ts = Date.UTC(2026, 0, 1, parseInt(parts[0]), parseInt(parts[1]));
                seriesData.push([ts + (binMin * 30000), ds.data[j] || 0]);
            }

            var seriesItem = {
                name: ds.label,
                type: 'bar',
                stack: 'demand',
                data: seriesData,
                itemStyle: { color: ds.backgroundColor },
                barMaxWidth: 30
            };

            // Add rate cap markLine on first series only
            if (i === 0 && data.rate_cap_per_bin) {
                seriesItem.markLine = {
                    silent: true,
                    symbol: 'none',
                    lineStyle: { color: '#ff5252', type: 'dashed', width: 2 },
                    data: [{ yAxis: data.rate_cap_per_bin,
                             label: { formatter: t('ctp.demand.rateCap') + ': ' + data.rate_cap_per_bin } }]
                };
            }

            series.push(seriesItem);
        }

        if (this.chart) { this.chart.dispose(); }
        this.chart = echarts.init(this.container);

        var option = {
            tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
            legend: { top: 0, textStyle: { fontSize: 10 }, itemWidth: 12 },
            grid: { left: 50, right: 20, top: 40, bottom: 30 },
            xAxis: {
                type: 'time',
                name: t('ctp.demand.timeUtc'),
                axisLabel: { fontSize: 10, formatter: function(v) {
                    var d = new Date(v);
                    return ('0'+d.getUTCHours()).slice(-2) + ':' + ('0'+d.getUTCMinutes()).slice(-2);
                }}
            },
            yAxis: {
                type: 'value',
                name: t('ctp.demand.flights'),
                minInterval: 1
            },
            series: series
        };

        this.chart.setOption(option);

        // Handle resize
        var self = this;
        window.addEventListener('resize', function() { if (self.chart) self.chart.resize(); });
    },

    destroy: function() {
        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }
    }
};
```

**Important — `ctp.php` HTML changes required (see modified files table, #38):**

1. Change `<canvas id="ctp_demand_chart" height="140"></canvas>` to `<div id="ctp_demand_chart_container" style="height:300px"></div>`. ECharts requires a `<div>`, not `<canvas>`.
2. Add the ECharts 5.4.3 CDN `<script>` tag in the page header (ctp.php does NOT currently include ECharts). Use the same tag as `demand.php`:
   ```html
   <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
   ```
3. Add `<select id="ctp_demand_group_by">` dropdown near the demand chart.
4. Add placeholder HTML sections for the throughput config table and planning simulator panel.

- [ ] **Step 3: Add group_by=nat_track toggle**

Add a dropdown or button group near the demand chart for switching `group_by`:
```javascript
// Add to DemandChart.init():
var groupBySelect = document.getElementById('ctp_demand_group_by');
if (groupBySelect) {
    groupBySelect.addEventListener('change', function() {
        state.demandGroupBy = this.value;
        DemandChart.refresh();
    });
}
```

- [ ] **Step 4: Verify chart renders correctly**

Open `https://perti.vatcscc.org/ctp.php`, load a session, verify demand chart displays with ECharts (zoom, tooltip, legend interaction should all work).

- [ ] **Step 5: Commit**

```bash
git add assets/js/ctp.js
git commit -m "feat(ctp): migrate DemandChart from Chart.js to ECharts"
```

---

## Task 15: JavaScript — Throughput & Planning UI

**Files:**
- Modify: `assets/js/ctp.js`

**Context:** Spec Sections 8 and 10. Add throughput config management UI and planning simulator UI within the CTP page. These are new submodules within the existing IIFE.

- [ ] **Step 1: Add ThroughputManager submodule**

New submodule in `ctp.js` (after the existing AuditLog submodule, before the IIFE closing):

```javascript
var ThroughputManager = {
    configs: [],

    init: function() { /* ... */ },

    refresh: function() {
        $.ajax({
            url: API.throughput.list,
            data: { session_id: state.currentSession },
            success: function(resp) { /* render config table */ }
        });
    },

    showCreateDialog: function() { /* SweetAlert2 form */ },
    showEditDialog: function(config) { /* with expected_updated_at */ },
    deleteConfig: function(config_id) { /* soft-delete confirmation */ },
    showPreview: function(config_id) { /* impact preview chart */ },

    renderTable: function(configs) {
        // Table with: label, tracks, origins, dests, max_acph, priority, actions
    },

    renderUtilizationHeatmap: function() {
        // ECharts heatmap: tracks × time bins, colored by utilization_pct
    }
};
```

- [ ] **Step 2: Add PlanningSimulator submodule**

```javascript
var PlanningSimulator = {
    scenarios: [],
    currentScenario: null,

    init: function() { /* ... */ },

    loadScenarios: function() { /* GET scenarios.php */ },
    createScenario: function() { /* SweetAlert2 form */ },
    editScenario: function(id) { /* ... */ },
    deleteScenario: function(id) { /* ... */ },
    cloneScenario: function(id) { /* ... */ },

    loadBlocks: function(scenario_id) { /* ... */ },
    saveBlock: function(block) { /* ... */ },
    deleteBlock: function(block_id) { /* ... */ },

    loadAssignments: function(block_id) { /* ... */ },
    saveAssignment: function(assignment) { /* ... */ },
    deleteAssignment: function(assignment_id) { /* ... */ },

    compute: function(scenario_id) {
        $.ajax({
            url: API.planning.compute,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ scenario_id: scenario_id }),
            success: function(resp) {
                PlanningSimulator.renderResults(resp.data);
            }
        });
    },

    renderResults: function(data) {
        // Render arrival profiles (ECharts stacked bar per destination)
        // Render oceanic entry profiles (ECharts stacked bar per track)
        // Render constraint checks (table with violation flags)
        // Render track summary table
    },

    applyToSession: function(scenario_id) { /* confirmation + POST */ }
};
```

- [ ] **Step 3: Initialize new submodules**

In the main init function of the CTP module, add:
```javascript
ThroughputManager.init();
PlanningSimulator.init();
```

- [ ] **Step 4: Verify UI renders**

Open CTP page, verify throughput tab shows empty state, planning tab shows scenario list.

- [ ] **Step 5: Commit**

```bash
git add assets/js/ctp.js
git commit -m "feat(ctp): throughput config manager + planning simulator UI submodules"
```

---

## Task 16: Internationalization

**Files:**
- Modify: `assets/locales/en-US.json`
- Modify: `assets/locales/fr-CA.json`
- Modify: `assets/locales/en-CA.json`
- Modify: `assets/locales/en-EU.json`

**Context:** Spec Section 11. ~85 new keys across 3 namespaces: `ctp.throughput`, `ctp.planning`, `ctp.nat`, `ctp.demand.groupByTrack`.

- [ ] **Step 1: Add keys to en-US.json**

Add under the existing `"ctp"` namespace:

```json
"throughput": {
    "config": "Throughput Config",
    "maxAcph": "Max ACPH",
    "utilization": "Utilization",
    "utilizationPct": "{pct}% utilization",
    "acph": "ACPH",
    "origins": "Origins",
    "destinations": "Destinations",
    "tracks": "Tracks",
    "combined": "Combined",
    "wildcard": "All (wildcard)",
    "overCapacity": "Over Capacity",
    "underCapacity": "Under Capacity",
    "atCapacity": "At Capacity",
    "createConfig": "Create Config",
    "editConfig": "Edit Config",
    "deleteConfig": "Delete Config",
    "confirmDelete": "Are you sure you want to delete this throughput config?",
    "individual": "Individual",
    "group": "Group",
    "configLabel": "Config Label",
    "priority": "Priority",
    "active": "Active",
    "previewImpact": "Preview Impact",
    "constraintViolation": "Constraint Violation",
    "conflictDetected": "Overlap detected with config(s): {configs}",
    "noConfigs": "No throughput configs for this session.",
    "saved": "Throughput config saved.",
    "deleted": "Throughput config deleted.",
    "updated": "Throughput config updated."
},
"planning": {
    "scenario": "Scenario",
    "scenarioName": "Scenario Name",
    "createScenario": "Create Scenario",
    "cloneScenario": "Clone Scenario",
    "departureWindow": "Departure Window",
    "windowStart": "Window Start",
    "windowEnd": "Window End",
    "trafficBlock": "Traffic Block",
    "addBlock": "Add Block",
    "removeBlock": "Remove Block",
    "blockLabel": "Block Label",
    "flightCount": "Flight Count",
    "distribution": "Distribution",
    "uniform": "Uniform",
    "frontLoaded": "Front-Loaded",
    "backLoaded": "Back-Loaded",
    "custom": "Custom",
    "trackAssignment": "Track Assignment",
    "assignToTrack": "Assign to Track",
    "altitudeRange": "Altitude Range",
    "aircraftMix": "Aircraft Mix",
    "addAircraftType": "Add Aircraft Type",
    "removeType": "Remove Type",
    "compute": "Compute",
    "computing": "Computing demand profiles...",
    "computeResults": "Compute Results",
    "recompute": "Recompute",
    "arrivalProfile": "Arrival Profile",
    "oceanicEntryProfile": "Oceanic Entry Profile",
    "constraintCheck": "Constraint Check",
    "planned": "Planned",
    "actual": "Actual",
    "variance": "Variance",
    "violated": "Violated",
    "withinLimits": "Within Limits",
    "applyToSession": "Apply to Session",
    "applyConfirm": "Apply this scenario's constraints to the live session? This will create throughput configs.",
    "appliedSuccessfully": "Scenario applied to session.",
    "suggestTrack": "Suggest Track",
    "remainingCapacity": "Remaining Capacity",
    "slotsAvailable": "{count} slots available",
    "noScenarios": "No planning scenarios yet."
},
"nat": {
    "resolved": "Resolved",
    "unresolved": "Unresolved",
    "resolving": "Resolving...",
    "randomRoute": "Random Route",
    "trackResolved": "Track Resolved",
    "trackUnresolved": "Track Unresolved",
    "matchMethod": "Match Method",
    "tokenMatch": "Token Match",
    "sequenceMatch": "Sequence Match",
    "reResolve": "Re-resolve",
    "trackRotated": "Track Rotated"
},
"demand": {
    ... (existing keys),
    "groupByTrack": "Group by Track",
    "natTrack": "NAT Track",
    "allTracks": "All Tracks",
    "trackDemand": "Track Demand",
    "trackUtilization": "Track Utilization"
}
```

- [ ] **Step 2: Add French translations to fr-CA.json**

Full French translations for all ~85 keys. Examples:
```json
"throughput": {
    "config": "Config. de débit",
    "maxAcph": "ACPH max",
    "utilization": "Utilisation",
    ...
},
"planning": {
    "scenario": "Scénario",
    "scenarioName": "Nom du scénario",
    "compute": "Calculer",
    ...
}
```

- [ ] **Step 3: Add regional overrides to en-CA.json and en-EU.json**

Only add keys where the regional variant differs:
```json
// en-EU.json
"planning": {
    "scenario": "Scenario",
    "applyToSession": "Apply to Programme"
}
```

- [ ] **Step 4: Verify i18n loads correctly**

Open browser console, run:
```javascript
console.log(PERTII18n.t('ctp.throughput.config')); // "Throughput Config"
console.log(PERTII18n.t('ctp.planning.scenario')); // "Scenario"
console.log(PERTII18n.t('ctp.nat.randomRoute')); // "Random Route"
```

- [ ] **Step 5: Commit**

```bash
git add assets/locales/en-US.json assets/locales/fr-CA.json \
        assets/locales/en-CA.json assets/locales/en-EU.json
git commit -m "feat(i18n): add ~85 CTP throughput, planning, NAT track keys across 4 locales"
```

---

## Task 17: OpenAPI Specification

**Files:**
- Modify: `api-docs/openapi.yaml`

**Context:** Spec Section 12. Add endpoint definitions for SWIM metrics, SWIM status, CTP throughput CRUD, and CTP planning CRUD.

- [ ] **Step 1: Read current openapi.yaml structure**

Examine the existing structure to follow the same patterns for paths, parameters, schemas, and security definitions.

- [ ] **Step 2: Add SWIM NAT track endpoints**

Add under paths:
- `GET /api/swim/v1/tmi/nat_tracks/metrics` — parameters, response schema, error codes
- `GET /api/swim/v1/tmi/nat_tracks/status` — parameters, response schema

- [ ] **Step 3: Add CTP throughput endpoints**

Add under paths:
- `GET /api/ctp/throughput/list.php`
- `POST /api/ctp/throughput/create.php`
- `POST /api/ctp/throughput/update.php`
- `POST /api/ctp/throughput/delete.php`
- `GET /api/ctp/throughput/preview.php`

- [ ] **Step 4: Add CTP planning endpoints**

Add under paths:
- All 10 planning endpoints from spec Section 8.3

- [ ] **Step 5: Commit**

```bash
git add api-docs/openapi.yaml
git commit -m "docs: OpenAPI spec for CTP throughput, planning, and SWIM NAT track endpoints"
```

---

## Task 18: Final Integration Verification

**Context:** End-to-end verification across all components. No automated tests — manual verification via API calls, database queries, and browser testing.

- [ ] **Step 1: Verify database schema across all 3 migrations**

Run schema verification queries in VATSIM_TMI and SWIM_API (same queries from Tasks 1-3 Step 3).

- [ ] **Step 2: Verify NAT resolution end-to-end**

1. Create a CTP session (or use existing test session)
2. Detect flights via `detect.php`
3. Verify `resolved_nat_track` is populated in `ctp_flight_control`
4. Verify `swim_flights` has the same `resolved_nat_track` after daemon cycle

```sql
-- Check TMI
SELECT TOP 5 ctp_control_id, callsign, resolved_nat_track, nat_track_source, nat_track_resolved_at
FROM dbo.ctp_flight_control
WHERE session_id = ? AND resolved_nat_track IS NOT NULL;

-- Check SWIM
SELECT TOP 5 flight_uid, callsign, resolved_nat_track, nat_track_source
FROM dbo.swim_flights
WHERE resolved_nat_track IS NOT NULL;
```

- [ ] **Step 3: Verify throughput config CRUD cycle**

1. Create a config via `create.php`
2. List configs via `list.php` — verify it appears
3. Update config via `update.php` — verify concurrency check
4. Delete config via `delete.php` — verify soft-delete
5. Check `ctp_audit_log` for all 3 mutations

- [ ] **Step 4: Verify demand API with nat_track grouping**

```bash
curl -s "https://perti.vatcscc.org/api/ctp/demand.php?session_id=1&group_by=nat_track&bin_min=15" | python -m json.tool
```

- [ ] **Step 5: Verify SWIM sync daemon**

Wait 2 minutes after creating/modifying CTP data, then:
```sql
SELECT * FROM dbo.swim_sync_state
WHERE table_name IN ('ctp_nat_track_metrics', 'ctp_nat_track_throughput');
-- Verify last_sync_utc is recent and error_count = 0
```

- [ ] **Step 6: Verify SWIM API endpoints**

```bash
curl -s -H "X-API-Key: <test-key>" \
  "https://perti.vatcscc.org/api/swim/v1/tmi/nat_tracks/metrics?session_id=1&bin_min=15"
curl -s "https://perti.vatcscc.org/api/swim/v1/tmi/nat_tracks/status"
```

- [ ] **Step 7: Verify CTP page UI**

1. Open `ctp.php` in browser
2. Load a session
3. Verify demand chart renders with ECharts (not Chart.js)
4. Switch group_by to "NAT Track"
5. Open throughput tab — verify empty state
6. Open planning tab — verify empty state

- [ ] **Step 8: Verify i18n in all locales**

Switch browser locale to `fr-CA`, reload CTP page, verify French translations appear for new keys.

- [ ] **Step 9: Final commit with all remaining changes**

```bash
git add -A
git status  # Review all changes
git commit -m "feat(ctp): CTP-to-SWIM NAT track throughput pipeline — complete implementation"
```

---

## Dependency Graph

```
Task 1 (TMI migration 048) ──┐
Task 2 (SWIM migration 030) ──┼── Task 3 (SP v3)
                               │
Task 4 (Extract shared funcs) ─┼── Task 5 (Resolver)
                               │         │
                               ├── Task 6 (Flight endpoint mods)
                               │
                               ├── Task 7 (Demand API)
                               │
                               ├── Task 8 (Throughput CRUD) ──┐
                               │                              ├── Task 10 (Compute engine)
                               ├── Task 9 (Planning CRUD) ────┘
                               │
                               ├── Task 11 (SWIM sync) ── Task 12 (SWIM API)
                               │
                               ├── Task 13 (JS regex)
                               │
                               ├── Task 14 (JS ECharts) ── Task 15 (JS Throughput+Planning UI)
                               │
                               ├── Task 16 (i18n)
                               │
                               ├── Task 17 (OpenAPI)
                               │
                               └── Task 18 (Integration verification)
```

**Parallelizable groups:**
- Tasks 1+2 can run in parallel (different databases)
- Tasks 4+13 can run in parallel (no dependencies)
- Tasks 7+8+9 can run in parallel (independent APIs)
- Tasks 11+14+16+17 can run in parallel (independent concerns)

**Sequential chains:**
- Task 1 → Task 3 (SP needs new columns)
- Task 4 → Task 5 → Task 6 (resolver needs shared functions, endpoints need resolver)
- Task 8+9 → Task 10 (compute needs CRUD infrastructure)
- Task 11 → Task 12 (SWIM API needs sync to populate data)
- Task 14 → Task 15 (UI modules need ECharts base)
- All → Task 18 (integration test)
