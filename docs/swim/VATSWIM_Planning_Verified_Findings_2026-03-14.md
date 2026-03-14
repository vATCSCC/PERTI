# VATSWIM Planning Pack (Verified Findings)

Date: 2026-03-14  
Audience: Claude planning/implementation workflow  
Author: Codex verification pass

## Scope

This document re-validates prior VATSWIM sync/scaling findings against repository source code and separates:

1. Verified facts from code.
2. Inferences/recommendations.
3. Implementation backlog suitable for Claude planning.

## Verification Method

- Method: static repo inspection only (no runtime execution against live databases).
- Confidence: high for code-path existence and SQL behavior in checked files.
- Not proven here: production runtime throughput, actual DTU utilization, data distribution, live failure rates.

## Claim Verification Matrix

| ID | Claim | Status | Evidence |
|---|---|---|---|
| C1 | ADL ingest loop is 15-second and calls staged refresh SP. | Verified | `scripts/vatsim_adl_daemon.php:8` |
| C2 | ADL staged refresh supports change-flag delta behavior and heartbeat short-circuiting. | Verified | `adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:13`, `:14`, `:19-23`, `:274`, `:293` |
| C3 | ADL staged refresh supports deferring expensive steps via `@defer_expensive`. | Verified | `adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:44`, `:827`, `:859`, `:887`, `:895` |
| C4 | TVP-based staging ingest objects exist for pilot/prefile bulk paths. | Verified | `adl/sql/adl_staging_tvp_types.sql:30`, `:66`, `:89`, `:124` |
| C5 | SWIM flight sync is delta-based and keyed off last sync watermark (`MAX(last_sync_utc)`). | Verified | `scripts/swim_sync.php:8`, `:135`, `:194`, `:236`, `:238`, `:240` |
| C6 | SWIM flight sync uses `sp_Swim_BulkUpsert` JSON batch path. | Verified | `scripts/swim_sync.php:320`, `database/migrations/swim/004_swim_bulk_upsert_sp.sql:12`, `:107` |
| C7 | `sp_Swim_BulkUpsert` uses `MERGE` with unconditional `WHEN MATCHED THEN UPDATE SET` full-row updates. | Verified | `database/migrations/swim/004_swim_bulk_upsert_sp.sql:110`, `:113` |
| C8 | SWIM daemon is long-running, default 120s cycle, with heartbeat file. | Verified | `scripts/swim_sync_daemon.php:6`, `:15`, `:27`, `:53`, `:106` |
| C9 | ADL daemon treats `swim_sync_daemon.php` as primary path and uses inline fallback when heartbeat is stale/missing (or forced inline). | Verified | `scripts/vatsim_adl_daemon.php:160`, `:162`, `:165`, `:2534`, `:2938`, `:2942` |
| C10 | SWIM reference-data sync currently does full-table delete and reload for CDR/playbook tables. | Verified | `scripts/swim_refdata_sync.php:101`, `:200`, `:206` |
| C11 | WebSocket real-time path exists with internal port 8090 and event-file polling. | Verified | `scripts/swim_ws_server.php:86`, `:157`, `:182`; `docs/swim/SWIM_Phase2_RealTime_Design.md:45`, `:46` |
| C12 | SWIM flight table has multiple active/query path indexes. | Verified | `database/migrations/swim/003_swim_api_database_fixed.sql:123-130` |
| C13 | SWIM realtime design doc states 15-second event detection cadence (tied to ADL refresh). | Verified | `docs/swim/SWIM_Phase2_RealTime_Design.md:47` |

## Additional Re-Check Result

| ID | Finding | Status | Evidence |
|---|---|---|---|
| D1 | WebSocket position detection query uses `pos.position_updated_utc`, aligned with ADL position schema/procedure timestamp contract (`position_updated_utc`). | Verified aligned in code | `scripts/swim_ws_events.php:199`, `adl/migrations/core/001_adl_core_tables.sql:109`, `adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:465` |

Note: Keep this aligned during future refactors and enforce it with regression tests.

## Inference Boundaries (Not Hallucinations, But Not Directly Proven by Static Code)

These were recommendations/inferences, not claims of observed production behavior:

1. A 1-second external pull model against 5-DTU-class DB tiers will likely not scale without push/caching/fan-out controls.
2. Full delete+reload refdata sync is likely a major source of avoidable write amplification.
3. Adding change-feed + consumer watermarks is a robust way to support multi-database fan-out and external near-real-time consumers.

## Project-Specific Application of Findings

This section maps the verified repo state and the web-researched best practices to exact implementation targets in this project.

| Finding | How it applies here | Existing code anchor | Recommended implementation in this repo |
|---|---|---|---|
| Native change-feed is safer than broad polling scans for multi-consumer sync. | SWIM sync currently uses a single watermark (`MAX(last_sync_utc)`) and re-queries ADL deltas. This is fine for one consumer path but does not scale cleanly to many internal/external consumers with independent lag/replay windows. | `scripts/swim_sync.php:135`, `:236-240` | Add `swim_change_feed` + `sync_watermarks` tables in SWIM and emit per-change sequence rows from upsert/refdata paths; expose `since_seq` API for replay. |
| Batch-first ingest/write patterns are correct and should be preserved. | You already do staged ADL ingest and batch SWIM upsert; these are aligned with Azure SQL guidance for low-resource tiers. | `scripts/vatsim_adl_daemon.php:196`, `:948`, `scripts/swim_sync.php:320`, `database/migrations/swim/004_swim_bulk_upsert_sp.sql:12` | Keep staged ingest + JSON/TVP batching as the baseline; optimize by reducing unnecessary updates, not by switching to row-by-row logic. |
| Write amplification is currently the primary avoidable SQL cost. | `sp_Swim_BulkUpsert` updates all columns on every matched row, and refdata sync does full table delete/reload. | `database/migrations/swim/004_swim_bulk_upsert_sp.sql:113`, `scripts/swim_refdata_sync.php:101`, `:200`, `:206` | Add row-version/hash compare to skip no-op updates in `MERGE`; convert refdata sync to incremental upsert + deactivate tombstones. |
| 5-DTU class limits conflict with many 1-second pollers. | ADL already runs every 15s and SWIM sync every 2 minutes; if many external clients poll DB directly each second, reads will dominate constrained DTU/session/worker budgets. | `scripts/vatsim_adl_daemon.php:53`, `:162`, `:175-176`, `:2894` | Make WebSocket/event fan-out primary; keep DB for authoritative state and replay/backfill only; add short-TTL cache for snapshot endpoints. |
| Existing WS path is a strong base for push distribution. | WS event pipeline exists and is integrated with ADL loop, and position-stream timestamp filtering is aligned with schema (`position_updated_utc`). | `scripts/vatsim_adl_daemon.php:2953`, `scripts/swim_ws_events.php:199`, `adl/migrations/core/001_adl_core_tables.sql:109` | Keep `position_updated_utc` as the canonical filter and add a regression test so this contract does not drift. |
| Deferred expensive processing should stay enabled under load. | Your ADL staged SP explicitly supports `@defer_expensive`, and daemon config already enables it to protect ingest cadence. | `adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:44`, `:827`, `:859`, `scripts/vatsim_adl_daemon.php:211`, `:2718`, `:2741` | Keep `defer_expensive=true` for normal operations; move costly non-critical work to budgeted post-refresh jobs. |
| Pooling and transient retry behavior need to be standardized across all DB paths. | Some connection and reconnect logic exists, but retry semantics are not normalized across each integration path (ADL, SWIM, REF, MySQL). | `scripts/vatsim_adl_daemon.php:314-323`, `:3070-3076` | Implement centralized retry policy (fresh connection, bounded retries, jitter, idempotent operations) and add per-connection-string pool cardinality logging. |
| SQL Data Sync should not be introduced for long-term architecture. | You need durable long-lived fan-out and cross-db sync; Data Sync lifecycle/perf caveats make it a poor new dependency. | (Web research finding; no current dependency found in repo) | Keep app-level change-feed/watermark design and datastore-native replication patterns; avoid onboarding Azure SQL Data Sync. |

## What You Need to Provide to Claude

Use this as the minimum input packet so Claude can produce implementation-ready plans and patches without guessing.

1. Environment matrix:
   - Which databases are authoritative for each domain (ADL, SWIM, REF, MySQL, Postgres if used).
   - Which ones must be near-real-time mirrors vs periodic copies.
2. Scale/SLO targets with concrete numbers:
   - Expected peak active flights, update cadence, and external subscriber count.
   - Max acceptable end-to-end staleness for internal sync and external API/WS.
3. Resource and cost constraints:
   - Current DB tier(s) (for example, Azure SQL Basic 5 DTU) and whether upgrade is allowed.
   - Hard limits on monthly cost increases.
4. Consumer contract decisions:
   - Canonical external pattern (`WebSocket + delta replay` vs `polling` fallback rules).
   - Required retention window for replay (`since_seq`) and maximum payload size.
5. Schema and compatibility constraints:
   - Columns/tables that cannot change.
   - Whether adding new tables (`swim_change_feed`, `sync_watermarks`) is approved.
6. Rollout and risk constraints:
   - Deployment environment order and maintenance windows.
   - Rollback requirements and feature-flag expectations.
7. Security/compliance boundaries:
   - Allowed external services (SignalR/Web PubSub/Redis/CDN) and prohibited ones.
   - Data fields that must not be exposed outside internal boundaries.
8. Validation artifacts:
   - Sample production-like payloads (anonymized) for 500/1000/3000/5000-flight scenarios.
   - Current top-10 slow SQL statements and any DTU/CPU/IO charts for representative peak periods.

## Implementation Backlog for Claude (Phased)

### Phase 0: Correctness and Safety (Do First)

1. Add a regression test that asserts websocket position detection uses canonical `position_updated_utc`.
2. Add integration tests for:
   - ADL delta selection.
   - SWIM bulk upsert idempotence.
   - WS event emission for position updates.
3. Add feature flags for rollout and rollback on all sync behavior changes.

Acceptance:
- No schema-reference errors in WS event query path.
- Sync + WS tests pass in CI with representative fixtures.

### Phase 1: Make SWIM the Reliable Hub for Internal Fan-Out

1. Add `sync_consumers` and `sync_watermarks` tables in SWIM.
2. Replace file-based reverse-sync state with DB-backed watermarks where possible.
3. Enforce one authoritative writer path into `swim_flights` at a time.

Acceptance:
- Each downstream consumer replays from its own durable watermark.
- No duplicate or missing records across restarts.

### Phase 2: Reduce Write Amplification

1. Update `sp_Swim_BulkUpsert` to skip update when payload hash/version unchanged.
2. Convert `swim_refdata_sync.php` from full delete/reload to incremental upsert + tombstone deactivate.
3. Keep bounded cleanup jobs; avoid broad delete scans in hot windows.

Acceptance:
- Significant reduction in rows updated per cycle when input data is unchanged.
- Refdata sync avoids full-table churn on small source changes.

### Phase 3: Add Change Feed for 1-Second Consumers

1. Add `swim_change_feed` (`seq BIGINT IDENTITY`, `event_type`, `flight_uid`, `changed_cols`, `event_utc`, payload/min-payload).
2. Emit feed rows from bulk-upsert and refdata sync paths.
3. Build REST delta endpoint: `GET /api/swim/v1/changes?since_seq=<n>&limit=<m>`.

Acceptance:
- Clients can stay current by consuming sequence deltas only.
- No need for per-client full table polling.

### Phase 4: External Distribution Strategy

1. Keep WS as primary near-real-time channel.
2. Use short-TTL edge caching for snapshot endpoints.
3. Reserve DB reads for selective queries and backfill/recovery.

Acceptance:
- High client fan-out does not linearly increase DB query load.
- Recovery path exists for clients that disconnect and replay via `since_seq`.

## Ready-to-Paste Claude Planning Prompt

Use this prompt in Claude:

```text
You are planning implementation work for VATSWIM in the PERTI repository.

Hard constraints:
- Treat SWIM as the hub for internal fan-out.
- Preserve existing ADL 15s ingest cadence.
- Support future high fan-out external consumers at up to 1s perceived resolution.
- Avoid designs that require direct DB polling by all clients.

Verified baseline facts (from code):
- ADL ingest calls sp_Adl_RefreshFromVatsim_Staged every 15s.
- ADL staged refresh has change_flags and @defer_expensive controls.
- SWIM flight sync is delta-based and uses sp_Swim_BulkUpsert JSON MERGE.
- swim_sync_daemon is primary; ADL inline SWIM is fallback when heartbeat stale/missing.
- swim_refdata_sync currently does full DELETE + batch INSERT for CDR/playbook.
- WS server exists on internal 8090 and distributes events.
- Position timestamp query uses `pos.position_updated_utc`, aligned with schema `position_updated_utc`.

Plan request:
1) Produce a phased implementation plan (Phase 0-4) for correctness, durability, incremental sync, change-feed, and external distribution.
2) For each phase include:
   - SQL migrations
   - PHP code touchpoints
   - test strategy
   - observability metrics
   - rollback path
3) Prioritize low-risk, high-impact work first.
4) Explicitly define acceptance criteria and deployment order.
```

## Suggested First Implementation Ticket

Ticket: "Fix WS position timestamp column and add regression tests"

Why first:
- Removes a likely correctness defect.
- Low blast radius.
- Establishes confidence before larger sync architecture changes.
