# CTP-to-SWIM NAT Track Throughput Pipeline Design

**Date**: 2026-03-21
**Status**: Implementation Ready
**Scope**: NAT track resolution, throughput configuration, planning simulator, SWIM API endpoints, sync daemon extension

## 1. Problem Statement

CTP (Cross the Pond) planners need to manage, visualize, and distribute traffic across NAT (North Atlantic) tracks and city pairs during CTP events. Currently:

- No per-NAT-track demand tracking (only entry-FIR-level binning)
- No throughput configuration for track/origin/destination combinations
- No pre-event planning simulator for modeling traffic distributions
- No SWIM API for external consumers (CANOC, ECFMP, third-party tools) to query NAT track metrics
- No automated sync of CTP-specific data (resolved track, compliance) to SWIM

## 2. Existing Foundation

### 2.1 CTP Oceanic Schema (Migration 045)

Already deployed in `VATSIM_TMI`:

| Table | Purpose |
|-------|---------|
| `ctp_sessions` | Event sessions with perspective org mapping, slot parameters, constrained FIRs |
| `ctp_flight_control` | Per-flight management: oceanic crossing info, 3-segment route decomposition, EDCT, compliance, `swim_push_version` |
| `ctp_audit_log` | Action audit trail with before/after JSON snapshots |
| `ctp_route_templates` | NAT tracks, segment-scoped route templates with origin/dest filters |

### 2.2 CTP API (25 endpoints under `/api/ctp/`)

Fully operational:
- **Sessions**: list, get, create, update, activate, complete
- **Flights**: list, get, detect, validate_route, modify_route, assign_edct, assign_edct_batch, remove_edct, compliance, exclude, routes_geojson
- **Routes**: suggest, templates
- **Reference**: demand, stats, audit_log, changelog, boundaries

All endpoints use `ctp_require_auth()`, `ctp_audit_log()`, `ctp_push_swim_event()` from `api/ctp/common.php`.

### 2.3 NAT Track System (Deployed 2026-03-20)

- `api/data/playbook/nat_tracks.php`: Merges VATSIM natTrak API + CTP route templates with 30-min MySQL cache (`nat_track_cache`)
- `assets/js/natots-search.js`: FAA NATOTs advisory parser UI
- `route-maplibre.js`: NAT token expansion in route plotter (current pattern: `/^(?:NAT|TRACK|TRK)-?[A-Z]$/`)

### 2.4 SWIM Sync Infrastructure

- `swim_sync_daemon.php`: 2-min cycle, delta sync via `sp_Swim_BulkUpsert(@Json)` with OPENJSON + row-hash skip
- `swim_sync_state`: Per-table sync watermarks
- `swim_change_feed`: Append-only event log (monotonic `seq BIGINT IDENTITY`)
- `swim_sync_watermarks`: Consumer watermark tracking
- 12 existing SWIM TMI endpoints (8 root + 4 `/flow/`)

### 2.5 CTP Data Flow (Current)

```
assign_edct.php ──→ ctp_flight_control (CTP tracking)
                └─→ tmi_flight_control (TMI bridge, when program_id set)
                            │
executeDeferredTMISync() ───┘ (60s ADL daemon cycle)
                            │
                     adl_flight_tmi ──→ adl_flight_times
                            │
swim_sync_from_adl() ──────┘ (2-min daemon cycle)
                            │
                     swim_flights.edct_utc
```

CTP compliance check: `executeCtpComplianceCheck()` runs every 120s (8 x 15s cycles), reads `adl_flight_times.off_utc/out_utc`, writes `ctp_flight_control.compliance_status`.

### 2.6 Aircraft Performance

`VATSIM_ADL.dbo.fn_GetAircraftPerformance(@aircraft_icao, @weight_class, @engine_type)` returns `cruise_speed_ktas`, `cruise_mach`, `optimal_fl`, `source` with BADA PTF data and category defaults.

### 2.7 PostGIS Route Expansion

`expand_route(p_route_string TEXT)` returns `(waypoint_seq INT, waypoint_id VARCHAR, lat NUMERIC, lon NUMERIC, waypoint_type VARCHAR)`. Supports NAT coordinate formats (ICAO compact, NAT slash, NAT half-degree, ARINC 5-char).

## 3. Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| NAT resolution strategy | Hybrid: token first, full sequence match fallback | Token is fast; sequence match catches flights that file full waypoints instead of track name |
| Sequence match strictness | Full track match only (not partial overlap) | Partial overlap produces false positives on random routings sharing a few fixes |
| NAT token regex | `(NAT\|TRACK\|TRAK\|TRK)-?[A-Z0-9]{1,5}` | User-specified base; `TRK` added for backward compat with existing routes; optional hyphen for `NAT-A` form |
| Track name storage | `NVARCHAR(8)` | `NAT` prefix (3) + identifier (up to 5) |
| SWIM consumers | Full external: CANOC, ECFMP, third-party | Public SWIM API with keyed auth and rate limiting |
| Sync architecture | Extend `swim_sync_daemon` + event-triggered immediate push | Periodic for metrics aggregation; immediate for individual flight actions |
| Metrics storage granularity | 15-minute bins, configurable query (15/30/60) | Matches ATC flow management conventions; aggregation on query |
| Throughput config model | Generalized: any combo of track(s) + origin(s) + destination(s) with NULL = wildcard | Covers all CTP planning scenarios from individual city pairs to global caps |
| Concurrency control | `updated_at` timestamp check | Matches project patterns; no existing `version` column precedent in TMI |
| Chart library | ECharts via `DemandChartCore` (migrate CTP from Chart.js) | Consistency with main demand system (`demand.php`, `gdt.php`) |
| Data retention | All CTP data indefinite | Historical event analysis, no archival purge |
| Track rotation | Preserve original resolution with timestamp | Flight was planned against the original track; re-resolve only on explicit route modification |

## 4. Schema Changes

### 4.1 New Columns on `ctp_flight_control` (VATSIM_TMI)

```sql
ALTER TABLE dbo.ctp_flight_control ADD
    resolved_nat_track      NVARCHAR(8) NULL,       -- e.g. 'NATA', 'NATB2', NULL if random route
    nat_track_resolved_at   DATETIME2(0) NULL,       -- When resolution happened
    nat_track_source        NVARCHAR(8) NULL;        -- 'TOKEN' or 'SEQUENCE'

CREATE NONCLUSTERED INDEX IX_ctp_fc_nat_track
    ON dbo.ctp_flight_control(session_id, resolved_nat_track)
    WHERE resolved_nat_track IS NOT NULL;
```

### 4.2 New Columns on `swim_flights` (SWIM_API)

```sql
ALTER TABLE dbo.swim_flights ADD
    resolved_nat_track      NVARCHAR(8) NULL,
    nat_track_resolved_at   DATETIME2(0) NULL,
    nat_track_source        NVARCHAR(8) NULL;

CREATE NONCLUSTERED INDEX IX_swim_flights_nat_track
    ON dbo.swim_flights(resolved_nat_track)
    WHERE resolved_nat_track IS NOT NULL AND is_active = 1
    INCLUDE (flight_uid, callsign, dep_airport, arr_airport);
```

Extend `sp_Swim_BulkUpsert` OPENJSON WITH clause to include these 3 columns (124 total). Add `resolved_nat_track` to the row-hash calculation (19 volatile columns becomes 20) so NAT track changes trigger sync.

### 4.3 New Table: `ctp_track_throughput_config` (VATSIM_TMI)

Generalized throughput constraints. NULL fields = wildcard (match any).

```sql
CREATE TABLE dbo.ctp_track_throughput_config (
    config_id               INT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    config_label            NVARCHAR(64) NOT NULL,       -- Display name
    tracks_json             NVARCHAR(MAX) NULL,           -- ["NATA","NATB"] or NULL = all
    origins_json            NVARCHAR(MAX) NULL,           -- ["KJFK"] or NULL = all
    destinations_json       NVARCHAR(MAX) NULL,           -- ["EGLL","LFPG"] or NULL = all
    max_acph                INT NOT NULL,                  -- Max aircraft per hour
    priority                INT NOT NULL DEFAULT 50,       -- Evaluation order (lower first)
    is_active               BIT NOT NULL DEFAULT 1,
    notes                   NVARCHAR(256) NULL,
    created_by              NVARCHAR(16) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT FK_ctp_ttc_session FOREIGN KEY (session_id)
        REFERENCES dbo.ctp_sessions(session_id)
);

CREATE NONCLUSTERED INDEX IX_ctp_ttc_session
    ON dbo.ctp_track_throughput_config(session_id, is_active)
    WHERE is_active = 1;

CREATE NONCLUSTERED INDEX IX_ctp_ttc_session_priority
    ON dbo.ctp_track_throughput_config(session_id, priority)
    WHERE is_active = 1
    INCLUDE (config_label, max_acph, tracks_json, origins_json, destinations_json);
```

**Flight matching logic:**
```
match = (tracks_json IS NULL OR flight.resolved_nat_track IN tracks_json)
    AND (origins_json IS NULL OR flight.dep_airport IN origins_json)
    AND (destinations_json IS NULL OR flight.arr_airport IN destinations_json)
```

A single flight can match multiple configs simultaneously. The most restrictive applicable constraint governs EDCT assignment.

**Overlap detection:** When creating/updating a config, the API checks for overlapping configs (same session, intersecting tracks/origins/destinations scopes). If overlap exists, the response includes a warning with the overlapping config IDs and labels but does **not** block creation — planners intentionally layer constraints (e.g., a global cap + per-track cap). The `priority` column controls evaluation order when multiple configs match; lower priority number = evaluated first.

**Examples:**

| tracks | origins | destinations | max_acph | Meaning |
|--------|---------|-------------|----------|---------|
| `["NATA"]` | `["KJFK"]` | `["EGLL"]` | 15 | NATA, KJFK to EGLL |
| `["NATA"]` | `["KPHL","KDCA","KBWI"]` | `["LFPG"]` | 25 | NATA, Mid-Atlantic to Paris combined |
| `["NATA","NATB"]` | `NULL` | `["EGLL"]` | 30 | NATA+NATB, any origin to EGLL |
| `NULL` | `["KJFK"]` | `["EGLL"]` | 50 | All tracks, KJFK to EGLL |
| `["NATC"]` | `["KBOS"]` | `NULL` | 10 | NATC, KBOS to anywhere |
| `NULL` | `NULL` | `NULL` | 120 | Total oceanic throughput |

### 4.4 New Table: `swim_nat_track_metrics` (SWIM_API)

Pre-computed 15-minute bin metrics per NAT track per session.

```sql
CREATE TABLE dbo.swim_nat_track_metrics (
    metric_id               BIGINT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    track_name              NVARCHAR(8) NOT NULL,           -- e.g. 'NATA'
    bin_start_utc           DATETIME2(0) NOT NULL,
    bin_end_utc             DATETIME2(0) NOT NULL,
    flight_count            INT NOT NULL DEFAULT 0,
    slotted_count           INT NOT NULL DEFAULT 0,
    compliant_count         INT NOT NULL DEFAULT 0,
    avg_delay_min           FLOAT NULL,
    peak_rate_hr            INT NULL,                        -- Extrapolated hourly rate
    direction               NVARCHAR(8) NULL,                -- WESTBOUND/EASTBOUND
    flight_levels_json      NVARCHAR(256) NULL,              -- ["FL350","FL370"]
    origins_json            NVARCHAR(MAX) NULL,               -- Empirical: airports using this track
    destinations_json       NVARCHAR(MAX) NULL,
    source                  NVARCHAR(16) NOT NULL DEFAULT 'CTP',
    computed_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    -- No FK to ctp_sessions: cross-database (SWIM_API → VATSIM_TMI) not supported on Azure SQL Basic.
    -- Integrity maintained by sync daemon which only writes for valid sessions.
    CONSTRAINT UQ_swim_ntm_session_track_bin
        UNIQUE (session_id, track_name, bin_start_utc),
    CONSTRAINT CK_swim_ntm_direction
        CHECK (direction IS NULL OR direction IN ('WESTBOUND', 'EASTBOUND'))
);

-- UQ constraint also serves as covering index for metrics queries (session + track + time range)
CREATE NONCLUSTERED INDEX IX_swim_ntm_session_time
    ON dbo.swim_nat_track_metrics(session_id, bin_start_utc);
```

**Bin aggregation formula:** Flights are binned by `oceanic_entry_utc` floored to 15-minute boundaries:
```sql
bin_start_utc = DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0)
bin_end_utc   = DATEADD(MINUTE, 15, bin_start_utc)
```
A flight with `oceanic_entry_utc = 14:07Z` falls in the `14:00-14:15Z` bin.

### 4.5 New Table: `swim_nat_track_throughput` (SWIM_API)

Per-config utilization metrics in 15-minute bins.

```sql
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

    -- No FK to ctp_sessions or ctp_track_throughput_config: cross-database not supported.
    -- Integrity maintained by sync daemon.
    CONSTRAINT UQ_swim_ntt_session_config_bin
        UNIQUE (session_id, config_id, bin_start_utc)
);
```

**Note:** `config_label`, `tracks_json`, `origins_json`, `destinations_json` are denormalized from `ctp_track_throughput_config` for read performance. SWIM API consumers get self-contained responses without cross-database JOINs. If config label is updated after bins are computed, the daemon refreshes on the next 2-min cycle.

### 4.6 Planning Simulator Tables (VATSIM_TMI)

**`ctp_planning_scenarios`:**

```sql
CREATE TABLE dbo.ctp_planning_scenarios (
    scenario_id             INT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NULL,                        -- FK to ctp_sessions (NULL = standalone)
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
```

**`ctp_planning_traffic_blocks`:**

```sql
CREATE TABLE dbo.ctp_planning_traffic_blocks (
    block_id                INT IDENTITY(1,1) PRIMARY KEY,
    scenario_id             INT NOT NULL,
    block_label             NVARCHAR(64) NULL,               -- e.g. "JFK to Paris"
    origins_json            NVARCHAR(MAX) NOT NULL,           -- ["KJFK"]
    destinations_json       NVARCHAR(MAX) NOT NULL,           -- ["LFPG"]
    flight_count            INT NOT NULL,
    dep_distribution        NVARCHAR(16) NOT NULL DEFAULT 'UNIFORM',
    dep_distribution_json   NVARCHAR(MAX) NULL,               -- Custom bin weights
    aircraft_mix_json       NVARCHAR(MAX) NULL,               -- {"B77W": 40, "A359": 20}
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_ctp_ptb_dist CHECK (dep_distribution IN ('UNIFORM','FRONT_LOADED','BACK_LOADED','CUSTOM')),
    CONSTRAINT FK_ctp_ptb_scenario FOREIGN KEY (scenario_id)
        REFERENCES dbo.ctp_planning_scenarios(scenario_id) ON DELETE CASCADE
);
```

**`ctp_planning_track_assignments`:**

```sql
CREATE TABLE dbo.ctp_planning_track_assignments (
    assignment_id           INT IDENTITY(1,1) PRIMARY KEY,
    block_id                INT NOT NULL,
    track_name              NVARCHAR(8) NULL,                -- NAT track (NULL = random route)
    route_string            NVARCHAR(MAX) NULL,               -- Explicit route if not named track
    flight_count            INT NOT NULL,
    altitude_range          NVARCHAR(32) NULL,                -- e.g. "FL350-FL390"
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT FK_ctp_pta_block FOREIGN KEY (block_id)
        REFERENCES dbo.ctp_planning_traffic_blocks(block_id) ON DELETE CASCADE
);
```

### 4.7 CTP Flow Provider Registration (SWIM_API)

```sql
INSERT INTO dbo.swim_tmi_flow_providers (
    provider_id, provider_code, provider_name, api_base_url,
    auth_type, sync_enabled, is_active, priority, created_at, updated_at
)
VALUES (
    (SELECT ISNULL(MAX(provider_id), 0) + 1 FROM dbo.swim_tmi_flow_providers),
    'CTP', 'Cross the Pond', 'https://perti.vatcscc.org/api/ctp',
    'session', 0, 1, 10, SYSUTCDATETIME(), SYSUTCDATETIME()
);
```

Note: `sync_enabled = 0` because CTP data is pushed by our daemon, not pulled by the SWIM sync framework. The `auth_type = 'session'` reflects that CTP endpoints use session auth, not API key auth.

## 5. NAT Track Resolution

### 5.1 Shared Resolver (`load/services/NATTrackResolver.php`)

```php
function resolveNATTrack(
    string $filed_route,
    string $seg_oceanic_route,
    array $active_tracks
): ?array  // Returns ['track' => 'NATA', 'source' => 'TOKEN'|'SEQUENCE'] or null
```

**Step 1 — Token detection** (fast path):
- Scan `$filed_route` for pattern: `/\b(?:NAT|TRACK|TRAK|TRK)-?([A-Z0-9]{1,5})\b/i`
- Normalize to `NAT{identifier}`, verify it exists in `$active_tracks`
- If valid, return immediately with `source = 'TOKEN'`

**Step 2 — Full sequence match** (fallback):
- Parse `$seg_oceanic_route` into ordered waypoint array (split on whitespace)
- For each active track, parse its `route_string` the same way
- Match: flight's oceanic waypoint sequence must match the track's full sequence exactly (same fixes, same order, same count)
- If exactly one track matches, return with `source = 'SEQUENCE'`
- If zero or multiple matches, return `null`

### 5.2 When Resolution Runs

| Trigger | Action |
|---------|--------|
| `flights/detect.php` | Resolve after populating `ctp_flight_control` |
| `flights/modify_route.php` | Re-resolve after oceanic segment changes |
| `swim_sync_daemon` (2-min) | Resolve flights where `resolved_nat_track IS NULL` and `seg_oceanic_route IS NOT NULL` |

Track rotation: Once resolved, a flight keeps its original track assignment (historical fact). Re-resolution only runs for unresolved flights or after explicit route modification.

### 5.3 Active Track Source

Call `fetchNatTrakTracks()` + `fetchCTPTracks()` + `mergeTrackSources()` from `nat_tracks.php` (extract to shared utility). Cache active track set for duration of a sync cycle.

### 5.4 JS Pattern Update

Update `route-maplibre.js` NAT token pattern from:
```javascript
const natTokenPattern = /^(?:NAT|TRACK|TRK)-?[A-Z]$/;
```
To:
```javascript
const natTokenPattern = /^(?:NAT|TRACK|TRAK|TRK)-?([A-Z0-9]{1,5})$/;
```

Update alias builder in `nat_tracks.php` `buildNATAliases()` to match.

## 6. CTP-to-SWIM Sync

### 6.1 Extend `swim_sync_daemon.php`

Add a new step after the existing ADL-to-SWIM sync:

```
swim_sync_from_adl()            ← existing (2-min cycle)
swim_sync_ctp_to_swim()         ← NEW (same cycle, skipped if no active CTP session)
```

### 6.2 `swim_sync_ctp_to_swim()` Logic

**Pre-check:** Query `ctp_sessions` for `status IN ('ACTIVE', 'MONITORING')`. If none, skip entirely (zero overhead when no CTP event).

**Per-flight sync (delta-driven):**
1. Query `ctp_flight_control` rows where `updated_at > swim_pushed_at`
2. Batch UPDATE `swim_flights` via JSON + SP pattern for CTP-specific columns:
   - `resolved_nat_track`, `nat_track_resolved_at`, `nat_track_source`
3. EDCT data (`edct_utc`, `compliance_status`, etc.) already flows through the TMI→ADL→SWIM chain when `program_id` is set
4. Update `swim_push_version` and `swim_pushed_at` on synced rows
5. Emit events to `swim_change_feed` with `event_type = 'ctp_flight_update'`

**Metrics recompute (delta-driven):**
1. Read last sync time from `swim_sync_state` where `table_name = 'ctp_nat_track_metrics'` (existing table, existing `last_sync_utc` column)
2. Query changed `ctp_flight_control` rows since that timestamp
3. Identify affected `(resolved_nat_track, bin_start)` pairs using bin formula: `DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0)`
4. For each affected bin, aggregate from `ctp_flight_control` and MERGE into `swim_nat_track_metrics`
5. Safeguard: If affected bins exceed 50% of total session bins (e.g., bulk track rotation), fall back to full session recompute in a single GROUP BY query
6. Update `swim_sync_state` with new `last_sync_utc` and `last_duration_ms`

**Throughput config utilization recompute:**
1. For each active throughput config, run aggregate query per bin using the match logic (Section 4.3)
2. MERGE into `swim_nat_track_throughput`
3. Use `swim_sync_state` table with `table_name = 'ctp_nat_track_throughput'` for watermark (separate from metrics watermark to support independent failure/retry per Section 6.5)

### 6.3 Event-Triggered Immediate Push

In CTP API endpoints that modify flight state, add a lightweight direct push:

| Endpoint | Push Action |
|----------|-------------|
| `assign_edct.php` | Re-resolve NAT track + UPDATE `swim_flights` for resolved_nat_track |
| `assign_edct_batch.php` | Batch push |
| `modify_route.php` | Re-resolve NAT track + UPDATE `swim_flights` |
| `remove_edct.php` | UPDATE `swim_flights` |

The push is a direct `swim_flights` UPDATE for the single flight (sub-second latency). Full metrics recompute waits for the 2-min daemon cycle.

**Timing coordination with daemon:** The immediate push updates both `swim_flights` and `ctp_flight_control.swim_pushed_at`. The daemon's delta query (`WHERE updated_at > swim_pushed_at`) naturally skips rows that were already pushed, avoiding duplicate work.

After each push, call `ctp_push_swim_event()` to notify WebSocket clients. For batch operations (`assign_edct_batch.php`), emit a single batch event rather than per-flight events to avoid event storms:
```json
{"event": "ctp.edct.batch_assigned", "session_id": 1, "count": 45, "updated_by": "1234567"}
```

### 6.4 Robustness

- Connection failure on SWIM side does not block ADL sync — catch, log, retry next cycle
- `swim_push_version` as optimistic concurrency: `WHERE flight_uid = ? AND swim_push_version < ?`
- Active track set cached per sync cycle (not per-flight)
- Stale flights (>2h inactive) handled by existing SWIM cleanup

### 6.5 Partial Failure Handling

The CTP sync step has three independent sub-steps: per-flight sync, metrics recompute, and throughput utilization recompute. Each runs in its own try/catch:

1. **Per-flight sync fails:** Log error, skip metrics step (data would be stale), retry next cycle. `swim_pushed_at` not advanced for failed rows, so they re-enter the delta query.
2. **Metrics recompute fails:** Log error, `swim_sync_state.last_sync_utc` for `ctp_nat_track_metrics` not updated, so affected bins re-enter next cycle. Per-flight sync is unaffected (already committed).
3. **Throughput utilization fails:** Log error, retry next cycle. Metrics and per-flight data are unaffected.

Error counts tracked in `swim_sync_state.error_count` (existing column) per table_name. If `error_count > 5`, emit a monitoring alert via `monitoring_daemon.php` metrics collection.

## 7. SWIM API Endpoints

### 7.1 `GET /api/swim/v1/tmi/nat_tracks/metrics`

Pre-computed NAT track throughput metrics for external consumers.

**Auth:** `swim_init_auth(true)` — requires API key. Uses `PERTI_SWIM_ONLY` pattern (set by `auth.php`) — queries `SWIM_API` database only. No TMI/ADL/GIS connections needed; all data is pre-computed by the sync daemon.

**Parameters:**

| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `session_id` | int | Yes | | CTP session |
| `track` | string | No | all | Comma-separated track names |
| `bin_min` | int | No | 15 | Aggregation: 15, 30, or 60 |
| `from` | ISO 8601 | No | session start | Time range start |
| `to` | ISO 8601 | No | session end | Time range end |
| `direction` | string | No | all | WESTBOUND or EASTBOUND |
| `origin` | string | No | | Filter tracks serving this origin |
| `dest` | string | No | | Filter tracks serving this destination |
| `city_pair` | string | No | | Shorthand `KJFK-EGLL` |

**Response:**
```json
{
  "success": true,
  "data": {
    "session": {
      "session_id": 1,
      "session_name": "CTP2026W",
      "direction": "WESTBOUND"
    },
    "bin_min": 15,
    "tracks": [
      {
        "track_name": "NATA",
        "direction": "WESTBOUND",
        "bins": [
          {
            "bin_start": "2026-10-19T12:00:00Z",
            "bin_end": "2026-10-19T12:15:00Z",
            "flight_count": 8,
            "slotted_count": 7,
            "compliant_count": 6,
            "avg_delay_min": 12.3,
            "peak_rate_hr": 32,
            "flight_levels": ["FL350", "FL370", "FL390"],
            "origins": ["KJFK", "KBOS"],
            "destinations": ["EGLL", "LFPG"]
          }
        ],
        "totals": {
          "total_flights": 142,
          "avg_delay_min": 14.1,
          "compliance_pct": 87.3
        }
      }
    ]
  },
  "timestamp": "2026-10-19T14:32:00Z"
}
```

When `bin_min=30` or `bin_min=60`, aggregate from stored 15-min rows on-the-fly (SUM counts, AVG delays, MAX peak rate). If `bin_min` is not one of `{15, 30, 60}`, return 400 with `INVALID_PARAM` error code and message "bin_min must be 15, 30, or 60".

### 7.2 `GET /api/swim/v1/tmi/nat_tracks/status`

Live snapshot of NAT track definitions + real-time occupancy. Uses `PERTI_SWIM_ONLY` — track definitions fetched via external HTTP call to natTrak API (not a database query), occupancy counts from `SWIM_API` tables. No TMI/ADL/GIS connections needed.

**Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `session_id` | int | No | Include CTP metrics if provided |
| `source` | string | No | `nattrak`, `ctp`, or `all` (default `all`) |

**Response:**
```json
{
  "success": true,
  "data": {
    "tracks": [
      {
        "track_name": "NATA",
        "route_string": "DOTTY 50N050W 51N040W 52N030W 53N020W MALOT",
        "source": "nattrak",
        "direction": "WESTBOUND",
        "flight_levels": "FL350 FL370 FL390",
        "valid_from": "2026-10-19T11:00:00Z",
        "valid_to": "2026-10-19T19:00:00Z",
        "current_flights": 12,
        "current_rate_hr": 28,
        "slotted_pct": 91.7
      }
    ],
    "fetched_at": "2026-10-19T14:32:00Z"
  },
  "timestamp": "2026-10-19T14:32:00Z"
}
```

### 7.3 Auth & Rate Limiting

Both endpoints use existing SWIM API key auth (`swim_api_keys` table, `X-API-Key` or `Authorization: Bearer` header).

| Tier | Rate Limit |
|------|-----------|
| public | 60 req/min |
| partner (CANOC, ECFMP) | 300 req/min |
| internal | unlimited |

Response format follows `SwimResponse::success($data)` pattern: `{success: true, data: {...}, timestamp: "..."}`.

**Error responses** use structured error codes (e.g., `MISSING_PARAM`, `INVALID_PARAM`, `NOT_FOUND`, `SESSION_NOT_FOUND`) so consumers can switch on the code rather than parsing English text. Human-readable `message` field is English-only per existing SWIM convention — API responses are not i18n'd.

## 8. CTP API Extensions

### 8.1 Throughput Config CRUD

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/ctp/throughput/list.php` | GET | List configs for session |
| `/api/ctp/throughput/create.php` | POST | Create config |
| `/api/ctp/throughput/update.php` | POST | Update config (with `updated_at` concurrency check) |
| `/api/ctp/throughput/delete.php` | POST | Soft-delete: sets `is_active = 0`, `updated_at = SYSUTCDATETIME()`. No physical DELETE. |
| `/api/ctp/throughput/preview.php` | GET | Preview impact of a config against current flight data |

All mutations audit-logged with `action_type = 'THROUGHPUT_CONFIG_CREATE'` / `'UPDATE'` / `'DELETE'`.

**Concurrency:** Update requires `expected_updated_at` parameter. Server checks `WHERE config_id = ? AND updated_at = ?`. If 0 rows affected, returns 409 Conflict with current data. Matches project pattern of using `updated_at` timestamps.

**WebSocket:** `ctp_push_swim_event('ctp.throughput.updated', {...})` notifies connected planners. UI shows toast: "Config 'KJFK to EGLL on NATA' updated by CID 1234567".

### 8.2 Extend CTP Demand API

Add `group_by=nat_track` option to existing `/api/ctp/demand.php`:

- Groups flights by `resolved_nat_track` instead of FIR/status/fix
- Flights with `resolved_nat_track IS NULL` grouped as "Random Route"
- Same bin/label/dataset format as existing groups

### 8.3 Planning Simulator API

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/ctp/planning/scenarios.php` | GET/POST | List/create scenarios |
| `/api/ctp/planning/scenario_save.php` | POST | Update scenario |
| `/api/ctp/planning/scenario_delete.php` | POST | Delete scenario |
| `/api/ctp/planning/block_save.php` | POST | Create/update traffic block |
| `/api/ctp/planning/block_delete.php` | POST | Delete traffic block |
| `/api/ctp/planning/assignment_save.php` | POST | Create/update track assignment |
| `/api/ctp/planning/assignment_delete.php` | POST | Delete track assignment |
| `/api/ctp/planning/compute.php` | POST | Run demand profile computation |
| `/api/ctp/planning/apply_to_session.php` | POST | Promote scenario to live throughput configs |

**Compute endpoint** requires 3 database connections: GIS (`$conn_gis` for `expand_route()`), ADL (`$conn_adl` for `fn_GetAircraftPerformance()`), TMI (`$conn_tmi` for planning tables). Cannot use `PERTI_MYSQL_ONLY`.

**Scenario ownership:** `created_by` CID is the owner. Only owner can edit; others can view and clone via `POST /api/ctp/planning/scenario_clone.php`.

## 9. Planning Simulator Compute Engine

### 9.1 Algorithm

When the planner hits "Compute" on a scenario:

**Step 1 — Spread departures:**
For each traffic block, distribute `flight_count` across the departure window using the chosen distribution:
- `UNIFORM`: Evenly spaced
- `FRONT_LOADED`: Weight toward window start (e.g., 60/30/10 split across thirds)
- `BACK_LOADED`: Weight toward window end
- `CUSTOM`: Use `dep_distribution_json` bin weights

**Step 2 — Route each flight:**
For each track assignment, call PostGIS `expand_route(route_string)` to get ordered waypoints with lat/lon. Cache results per unique route (most assignments share the same track route).

**Step 3 — Compute flight times:**
For each simulated flight:
1. Look up cruise TAS via `fn_GetAircraftPerformance(type, weight_class, engine_type)` from `aircraft_mix_json`. If no mix specified, use category default (460 kts for jet).
2. Calculate route distance from waypoint lat/lon sequence (great circle segments).
3. Compute segment times:
   - Departure to oceanic entry: distance / TAS
   - Oceanic transit: distance / TAS (with optional wind component)
   - Oceanic exit to arrival: distance / TAS
4. Derive: `oceanic_entry_time = dep_time + dep_to_entry_time`, `arrival_time = dep_time + total_time`

**Step 4 — Build profiles:**
- **Arrival demand profile:** For each destination, bin computed arrival times into 15-min intervals. Stacked by origin.
- **Oceanic entry profile:** Bin oceanic entry times by track. Stacked by origin.

**Step 5 — Check constraints:**
If `session_id` is set and throughput configs exist for that session, overlay them on profiles. Flag where planned traffic exceeds configured caps. If `session_id IS NULL` (standalone scenario), skip constraint checks and include a note in the response: `"constraint_checks": [], "constraint_note": "No session linked — constraint checks skipped."`

### 9.2 Compute Response

```json
{
  "status": "ok",
  "data": {
    "scenario_id": 1,
    "arrival_profiles": {
      "LFPG": {
        "labels": ["14:00", "14:15", "14:30", ...],
        "series": [
          { "name": "KJFK", "data": [[ts, 8], [ts, 12], ...] },
          { "name": "KIAD", "data": [[ts, 3], [ts, 5], ...] }
        ],
        "rate_cap": { "aar": 45, "source": "airport_config" }
      }
    },
    "oceanic_entry_profiles": {
      "labels": ["12:00", "12:15", "12:30", ...],
      "series": [
        { "name": "NATA", "data": [[ts, 15], [ts, 18], ...] },
        { "name": "NATB", "data": [[ts, 12], [ts, 10], ...] }
      ]
    },
    "constraint_checks": [
      {
        "config_id": 1,
        "config_label": "KJFK to EGLL on NATA",
        "max_acph": 15,
        "peak_actual": 18,
        "violated": true,
        "violation_bins": ["12:15", "12:30"]
      }
    ],
    "track_summary": [
      { "track": "NATA", "flights": 50, "avg_transit_min": 245, "distance_nm": 2890 },
      { "track": "NATB", "flights": 85, "avg_transit_min": 261, "distance_nm": 3020 }
    ]
  }
}
```

### 9.3 Edge Cases

- **Empty scenario** (no traffic blocks): Return 400 `EMPTY_SCENARIO` — "Scenario has no traffic blocks."
- **Zero flights in a block**: Skip the block silently; warn in response `warnings` array.
- **Track with no route** (track_name set but route_string NULL and no matching active track): Return partial result with warning; track excluded from profiles.
- **Aircraft type not found** in `fn_GetAircraftPerformance()`: Fall back to category defaults (460 kts cruise for jet, 260 kts for turboprop).
- **PostGIS `expand_route()` fails** for a route: Return partial result with warning; that assignment excluded from profiles.
- **Departure window < 15 min**: Compute succeeds with a single bin; warn that results may not be meaningful.
- **Wind component**: Planning simulator uses TAS only (no wind correction). Synthetic flights have no real-time position for wind lookup. Planners can account for wind by adjusting the departure window or flight counts. A future enhancement could add a per-scenario `wind_component_kts` parameter (headwind positive, tailwind negative) applied uniformly to oceanic transit time, but this is out of scope for V1.

### 9.4 Audit Logging

All scenario mutations (create, update, delete, clone, apply_to_session) are logged to `ctp_audit_log` with action types: `SCENARIO_CREATE`, `SCENARIO_UPDATE`, `SCENARIO_DELETE`, `SCENARIO_CLONE`, `SCENARIO_APPLY`. The `apply_to_session` action includes `action_detail_json` with the full mapping from scenario blocks → throughput configs.

### 9.5 Compute Response Data Format

Uses ECharts-compatible `[timestamp_ms, value]` pairs per series (see Section 10). Rate caps rendered as `markLine` overlays on the first series.

## 10. Demand Visualization

### 10.1 Chart Library Migration

The existing CTP demand chart in `ctp.js` uses Chart.js (`new Chart(ctx, config)` at line 2016). The main demand system (`demand.php`, `gdt.php`) uses ECharts via `DemandChartCore`.

**Migration:** Replace the CTP `DemandChart` submodule to use `DemandChartCore`:
- Replace `new Chart(ctx, config)` with `DemandChartCore.createChart(container, options)`
- Convert API response from Chart.js `{labels, datasets}` to ECharts `[timestamp_ms, value]` pairs
- Rate caps via `DemandChartCore.buildRateMarkLinesForChart()`
- Phase/status colors via shared `PHASE_COLORS` / `config/rate-colors.js`

### 10.2 Shared Chart Patterns

All CTP demand charts (live demand, throughput utilization, planning simulator) use:
- ECharts stacked bar with time axis (`[timestamp_ms, value]` data format)
- `DemandChartCore.createChart()` factory
- Rate caps as `markLine` overlays (horizontal lines on first series)
- Current time marker as vertical amber `markLine`
- Configurable `bin_min` (15/30/60) matching API granularity
- Auto-scaled Y-axis: `Math.ceil(max(demand, rate_cap) * 1.15)`

### 10.3 CTP-Specific Chart Views

**Throughput utilization heatmap:**
- Visual grid: tracks (rows) x time bins (columns)
- Cell color = `utilization_pct` of most restrictive applicable config
- Green (<70%), amber (70-90%), red (>90%), flashing red (>100%)
- Hover tooltip: breakdown of all applicable configs with their utilization

**Planning arrival profile:**
- ECharts stacked bar, stacked by origin airport
- X-axis = arrival time bins (UTC)
- Rate cap line = destination airport AAR from `airport_config`

**Planning oceanic entry profile:**
- ECharts stacked bar, stacked by NAT track
- X-axis = oceanic entry time bins (UTC)
- Rate cap lines from throughput configs (one per applicable config)

### 10.4 What-If Preview

Before confirming route modification or EDCT assignment:
- Show impact on all affected throughput configs
- "This would push NATA/KJFK to EGLL from 14 to 15 acph (100%)"
- Rank available tracks by remaining capacity for the flight's O/D pair
- "NATA: 1 slot left, NATB: 8 slots left -- suggest NATB"

### 10.5 Overflow Alerts

When any config exceeds its `max_acph`:
- Surface alert in CTP dashboard
- Identify specific constraint violated and contributing flights
- Suggest redistribution options

## 11. Internationalization

### 11.1 Current State

CTP i18n is mature: 45+ keys in `ctp.*` namespace, fully translated in `fr-CA.json`. NATOTs has 14 keys. Demand has 220+ keys. All 4 locale files maintained.

### 11.2 New Keys Required (~85 keys)

**`ctp.throughput.*` (~30 keys):**
- config, maxAcph, utilization, utilizationPct, acph
- origins, destinations, tracks, combined, wildcard
- overCapacity, underCapacity, atCapacity
- createConfig, editConfig, deleteConfig, confirmDelete
- individual, group, configLabel, priority, active
- previewImpact, constraintViolation, conflictDetected

**`ctp.planning.*` (~40 keys):**
- scenario, scenarioName, createScenario, cloneScenario
- departureWindow, windowStart, windowEnd
- trafficBlock, addBlock, removeBlock, blockLabel
- flightCount, distribution, uniform, frontLoaded, backLoaded, custom
- trackAssignment, assignToTrack, altitudeRange
- aircraftMix, addAircraftType, removeType
- compute, computing, computeResults, recompute
- arrivalProfile, oceanicEntryProfile, constraintCheck
- planned, actual, variance, violated, withinLimits
- applyToSession, applyConfirm, appliedSuccessfully
- suggestTrack, remainingCapacity, slotsAvailable

**`ctp.nat.*` (~10 keys):**
- resolved, unresolved, resolving, randomRoute
- trackResolved, trackUnresolved
- matchMethod, tokenMatch, sequenceMatch
- reResolve, trackRotated

**`ctp.demand.groupByTrack` (~5 keys):**
- natTrack, allTracks, trackDemand, trackUtilization

**Shared key** `ctp.nat.randomRoute`: Used by both demand grouping and planning UI for flights without a resolved NAT track. Single key avoids duplication.

### 11.3 Locale Updates

| File | Action |
|------|--------|
| `en-US.json` | Add ~85 keys under `ctp.throughput`, `ctp.planning`, `ctp.nat` |
| `fr-CA.json` | Full French translations for all 85 keys |
| `en-CA.json` | Only if Canadian English terms differ |
| `en-EU.json` | Only if EU English terms differ (e.g., "programme") |

### 11.4 Multi-Org Display

- CANOC users see `fr-CA` locale
- ECFMP users see `en-EU` locale
- Track names (`NATA`, `NATB`) are universal (no translation)
- Airport codes (ICAO) are universal
- Times always UTC
- Number formatting via `PERTII18n.formatNumber()` (locale-specific decimal separators)

## 12. OpenAPI Specification

Update `api-docs/openapi.yaml` with new endpoint definitions:

**SWIM API (keyed):**
- `GET /api/swim/v1/tmi/nat_tracks/metrics` — parameters, response schema, error codes
- `GET /api/swim/v1/tmi/nat_tracks/status` — parameters, response schema

**CTP API (authenticated):**
- Throughput CRUD: list, create, update, delete, preview
- Planning CRUD: scenarios, blocks, assignments, compute, apply, clone

Each spec includes: parameters, request/response schemas (JSON Schema), error codes, auth requirements, rate limits.

## 13. Data Retention

All CTP data is retained **indefinitely**. No archival purge.

| Data | Retention |
|------|-----------|
| `ctp_sessions` | Indefinite |
| `ctp_flight_control` | Indefinite |
| `ctp_audit_log` | Indefinite |
| `ctp_track_throughput_config` | Indefinite |
| `ctp_planning_scenarios` + blocks + assignments | Indefinite |
| `ctp_route_templates` | Indefinite |
| `swim_nat_track_metrics` | Indefinite |
| `swim_nat_track_throughput` | Indefinite |

## 14. Concurrent Access

### 14.1 Throughput Configs

Optimistic concurrency via `updated_at` timestamp:
- API returns `updated_at` in every response
- Update requires `expected_updated_at` parameter
- Server: `WHERE config_id = ? AND updated_at = ?`
- 0 rows affected = 409 Conflict with current data

### 14.2 Planning Scenarios

Per-planner isolation:
- `created_by` CID is the owner
- Only owner can edit
- Others can view and clone
- No locking needed

### 14.3 Real-Time Notifications

- `ctp_push_swim_event()` on all mutations
- Connected planners receive WebSocket events
- UI shows toast when another planner modifies shared configs

## 15. Track Rotation

NAT tracks rotate ~2x daily. Policy: preserve original resolution.

- `resolved_nat_track`: The track the flight was planned against (historical fact)
- `nat_track_resolved_at`: When resolution happened
- `nat_track_source`: `TOKEN` or `SEQUENCE`

Once resolved, a flight keeps its assignment even if the track rotates out. Re-resolution only occurs:
- For flights with `resolved_nat_track IS NULL` (daemon periodic re-resolve)
- After explicit route modification (`modify_route.php`)

Metrics filter by `oceanic_entry_utc` within the track's `valid_from`/`valid_to`, so rotated-out tracks show no new flights.

## 16. Summary of New Files

### Database Migrations
- `database/migrations/tmi/048_ctp_nat_track_throughput.sql` — Sections 4.1, 4.3, 4.6
- `database/migrations/swim/030_swim_nat_track_metrics.sql` — Sections 4.2, 4.4, 4.5, 4.7
- `database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql` — Extend SP for NAT columns

### PHP (Backend)
- `load/services/NATTrackResolver.php` — Section 5
- `api/ctp/throughput/list.php` — Section 8.1
- `api/ctp/throughput/create.php` — Section 8.1
- `api/ctp/throughput/update.php` — Section 8.1
- `api/ctp/throughput/delete.php` — Section 8.1
- `api/ctp/throughput/preview.php` — Section 8.1
- `api/ctp/planning/scenarios.php` — Section 8.3
- `api/ctp/planning/scenario_save.php` — Section 8.3
- `api/ctp/planning/scenario_delete.php` — Section 8.3
- `api/ctp/planning/scenario_clone.php` — Section 8.3
- `api/ctp/planning/block_save.php` — Section 8.3
- `api/ctp/planning/block_delete.php` — Section 8.3
- `api/ctp/planning/assignment_save.php` — Section 8.3
- `api/ctp/planning/assignment_delete.php` — Section 8.3
- `api/ctp/planning/compute.php` — Section 9
- `api/ctp/planning/apply_to_session.php` — Section 8.3
- `api/swim/v1/tmi/nat_tracks/metrics.php` — Section 7.1
- `api/swim/v1/tmi/nat_tracks/status.php` — Section 7.2

### PHP (Modified)
- `api/ctp/demand.php` — Add `group_by=nat_track` (Section 8.2)
- `api/ctp/flights/detect.php` — Add NAT resolution call (Section 5.2)
- `api/ctp/flights/modify_route.php` — Add NAT re-resolution + SWIM push (Section 5.2)
- `api/ctp/flights/assign_edct.php` — Add SWIM immediate push (Section 6.3)
- `api/ctp/flights/assign_edct_batch.php` — Add SWIM immediate push (Section 6.3)
- `api/ctp/flights/remove_edct.php` — Add SWIM immediate push (Section 6.3)
- `scripts/swim_sync_daemon.php` — Add CTP sync step (Section 6.1)
- `scripts/swim_sync.php` — Add `swim_sync_ctp_to_swim()` function (Section 6.2)
- `api/data/playbook/nat_tracks.php` — Extract shared functions for resolver (Section 5.3)

### JavaScript (Modified)
- `assets/js/ctp.js` — DemandChart submodule: migrate to ECharts, add throughput/planning UI (Sections 10, 8)
- `assets/js/route-maplibre.js` — Update NAT token pattern (Section 5.4)

### Locales
- `assets/locales/en-US.json` — ~85 new keys (Section 11.2)
- `assets/locales/fr-CA.json` — ~85 new translated keys
- `assets/locales/en-CA.json` — Regional overrides if needed
- `assets/locales/en-EU.json` — Regional overrides if needed

### Documentation
- `api-docs/openapi.yaml` — New endpoint specs (Section 12)
