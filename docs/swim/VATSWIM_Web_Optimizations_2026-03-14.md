# VATSWIM Web Research: Verified Safe Optimizations

Date: 2026-03-14  
Audience: Claude implementation planning  
Method: official docs only (Microsoft Learn, PostgreSQL docs, MySQL docs)

## Purpose

This document captures widely used, low-risk optimization patterns relevant to:

1. Multi-database synchronization (ADL, SWIM, MySQL, Postgres, Azure SQL).
2. High update cadence (15s ingest).
3. Future high fan-out external access at up to 1s perceived resolution.

## High-Confidence Findings

## 1) Prefer native change feeds over custom timestamp scans where possible

- SQL Server/Azure SQL supports both Change Tracking (CT) and CDC; CT is synchronous/minimal-overhead and CDC is asynchronous/history-oriented.
- For CT clients, Microsoft explicitly documents the `CHANGETABLE(CHANGES...)` + `CHANGE_TRACKING_CURRENT_VERSION()` + `CHANGE_TRACKING_MIN_VALID_VERSION()` pattern.
- Microsoft also documents using snapshot isolation in sync workflows to keep version validation and change reads consistent.

Why this is safe:
- Vendor-native mechanism, explicit sync semantics, built-in cleanup controls.

Potential fit in your stack:
- Use CT/CDC to back SWIM/internal fan-out deltas instead of broad scans where feasible.

## 2) Keep batching as a first-order optimization for Azure SQL

- Microsoft states batching significantly improves performance/scalability for Azure SQL interactions.
- Microsoft docs also indicate TVPs are often preferred/flexible for mixed insert/update logic; `SqlBulkCopy` can outperform TVPs at larger pure-insert batch sizes.
- Their guidance warns there is often no gain from splitting large batches into many smaller chunks.

Why this is safe:
- Matches your existing TVP and staged bulk patterns; this is vendor-endorsed and already aligned with your code.

## 3) Treat 5 DTU limits as hard architectural constraints

- Azure SQL Basic tier is documented as 5 DTU with strict worker/login/external-connection/session caps.

Why this matters:
- Supports your concern: direct 1-second polling by many clients cannot be the primary serving pattern.

## 4) Use resilient retry semantics for transient Azure SQL faults

- Microsoft guidance: retry transient failures, but on a fresh connection; do not blindly retry a failed `SELECT` on the same broken connection.
- For failed `UPDATE`, use fresh connection and ensure full transaction atomicity in retry logic.

Why this is safe:
- Reduces false failures and partial-write risk during throttling/network blips.

## 5) Connection pooling remains mandatory (and nuanced)

- ADO.NET pooling is client-side and works across Azure SQL/SQL MI/SQL Server.
- Pooling behavior is per exact connection string; fragmentation can occur with too many distinct strings.

Why this is safe:
- Standard practice with explicit platform behavior documented by Microsoft.

## 6) PostgreSQL: logical replication is a direct fit for incremental fan-out

- PostgreSQL logical replication uses publication/subscription and explicitly lists incremental change distribution as a core use case.
- Subscriber applies changes in publisher order for transactional consistency (within a subscription).
- Subscription/slot management guidance warns unmanaged slots can retain WAL and eventually fill disk.
- Publications support row/column filtering at definition time (fine-grained sync scope).

Why this is safe:
- Official engine-native incremental replication with tunable scope.

## 7) PostgreSQL bulk movement: use `COPY` with observability

- `COPY` supports high-throughput ingest/export with progress in `pg_stat_progress_copy`.
- `ON_ERROR` controls behavior for best-effort loads.

Why this is safe:
- Canonical PostgreSQL bulk path.

## 8) PostgreSQL connection pressure: PgBouncer is first-class on Azure Flexible Server

- Microsoft docs state Postgres connections are process-based and expensive at high idle counts.
- Built-in PgBouncer is designed for short-lived/idle connection scaling and documents high client-connection capacity with low overhead.

Why this is safe:
- Managed service support, documented compatibility/limits.

## 9) MySQL replication: favor GTID for operational safety

- MySQL docs state GTID replication is transactional, simplifies operations (no file/position handling), and guarantees source/replica consistency when transactions are fully applied.

Why this is safe:
- Officially recommended modern replication mode for consistency/failover workflows.

## 10) MySQL replication format tuning can materially affect write/log costs

- MySQL docs: RBR is safest for change correctness but can increase binlog volume substantially for many-row updates/deletes.
- Docs explicitly point to `binlog_row_image=minimal` to reduce that overhead.
- Docs also require coordinated source/replica format changes to avoid replication failure.

Why this is safe:
- Directly from MySQL replication docs; production-oriented caveats included.

## 11) MySQL upsert caveat

- MySQL docs warn to avoid `INSERT ... ON DUPLICATE KEY UPDATE` on tables with multiple unique indexes when possible (ambiguous/limited match behavior).

Why this is safe:
- Helps avoid subtle sync anomalies in multi-unique schemas.

## 12) 1-second external consumption: use push/fan-out, not DB polling

- Microsoft Web PubSub docs explicitly call out polling drawbacks (stale/inconsistent data, wasted resources).
- Azure Web PubSub and SignalR documentation describe high-scale WebSocket fan-out capabilities and scaling controls.
- Azure Architecture Center cache-aside pattern is recommended for unpredictable read demand, with explicit consistency caveats.

Why this is safe:
- Managed real-time distribution + documented caching pattern reduces direct DB fan-out load.

## 13) Important Azure SQL Data Sync caveat

- Microsoft documents SQL Data Sync retirement date (2027-09-30) and describes trigger/side-table performance impact and eventual consistency model.

Planning implication:
- Avoid introducing new hard dependencies on SQL Data Sync for long-term architecture.

## Recommended Additions to Your Existing Plan

1. Add an explicit "native change feed" decision matrix per datastore:
   - Azure SQL: CT vs CDC.
   - PostgreSQL: logical replication publications/subscriptions.
   - MySQL: GTID replication + format policy.
2. Add explicit "fan-out layer" requirement for external 1s consumers:
   - WebSocket push (SignalR/Web PubSub) + cache-backed snapshot fallback.
3. Add replication-slot/WAL and binlog growth monitoring as non-optional SLO guardrails.
4. Add connection-pool fragmentation checks in app diagnostics (exact connection-string cardinality).

## Application to This Repository (PERTI)

The following shows where each web finding maps into current code paths.

1. Change-feed recommendation maps to:
   - Current single-watermark delta pull in `scripts/swim_sync.php:135` and `:236-240`.
   - Proposed repo change: add `swim_change_feed` + per-consumer `sync_watermarks` in SWIM to support internal DB fan-out and external replay without full-table polling.
2. Batch-first guidance maps to:
   - ADL staged/bulk ingest and deferred expensive processing in `scripts/vatsim_adl_daemon.php:196`, `:211`, `:2718`.
   - SWIM JSON bulk upsert entry point in `scripts/swim_sync.php:320` and `database/migrations/swim/004_swim_bulk_upsert_sp.sql:12`.
3. Write-amplification warning maps to:
   - Unconditional matched updates in `database/migrations/swim/004_swim_bulk_upsert_sp.sql:113`.
   - Full delete/reload reference sync in `scripts/swim_refdata_sync.php:101`, `:200`, `:206`.
4. 1-second consumer guidance maps to:
   - Existing WS event path in `scripts/vatsim_adl_daemon.php:2953` and `scripts/swim_ws_server.php` (already better aligned than DB polling).
   - Config signal that high-volume position WS is disabled by default in `scripts/vatsim_adl_daemon.php:176`.
5. Correctness alignment check before scaling WS maps to:
   - Position timestamp query is currently aligned in `scripts/swim_ws_events.php:199` (`pos.position_updated_utc`) and schema in `adl/migrations/core/001_adl_core_tables.sql:109` (`position_updated_utc`).
6. Pooling/retry guidance maps to:
   - Existing `ConnectionPooling => true` and reconnect logic in `scripts/vatsim_adl_daemon.php:314-323`, `:3070-3076`.
   - Gap: no shared retry policy across all sync scripts yet.

Bottom line for this codebase:
- Keep ADL 15s ingest + SWIM batched upsert as the authoritative write pipeline.
- Add change-feed/watermarks for multi-consumer durability.
- Use WS + cache for external 1s consumption, not direct SQL polling.
- Reduce no-op writes (bulk-upsert no-op skip + incremental refdata sync) before adding higher fan-out load.

## Source Links

- Azure SQL batching:
  - https://learn.microsoft.com/en-us/azure/azure-sql/performance-improve-use-batching?view=azuresql
- Azure SQL DTU limits:
  - https://learn.microsoft.com/en-us/azure/azure-sql/database/resource-limits-dtu-single-databases?view=azuresql
- Azure SQL transient retry guidance:
  - https://learn.microsoft.com/en-us/azure/azure-sql/database/troubleshoot-common-connectivity-issues?view=azuresql
- SQL Server change tracking/CDC:
  - https://learn.microsoft.com/en-us/sql/relational-databases/track-changes/track-data-changes-sql-server?view=sql-server-ver17
  - https://learn.microsoft.com/en-us/sql/relational-databases/track-changes/work-with-change-tracking-sql-server?view=sql-server-ver17
  - https://learn.microsoft.com/en-us/sql/relational-databases/track-changes/enable-and-disable-change-tracking-sql-server?view=sql-server-ver17
  - https://learn.microsoft.com/en-us/sql/relational-databases/system-catalog-views/change-tracking-catalog-views-sys-change-tracking-tables?view=sql-server-ver17
- SQL Server TVPs and pooling:
  - https://learn.microsoft.com/en-us/dotnet/framework/data/adonet/sql/table-valued-parameters
  - https://learn.microsoft.com/en-us/dotnet/framework/data/adonet/sql-server-connection-pooling
- PostgreSQL logical replication / COPY:
  - https://www.postgresql.org/docs/current/logical-replication.html
  - https://www.postgresql.org/docs/current/logical-replication-subscription.html
  - https://www.postgresql.org/docs/current/sql-createpublication.html
  - https://www.postgresql.org/docs/current/sql-createsubscription.html
  - https://www.postgresql.org/docs/current/sql-copy.html
- Azure PostgreSQL PgBouncer:
  - https://learn.microsoft.com/en-us/azure/postgresql/connectivity/concepts-pgbouncer
- MySQL replication and upsert behavior:
  - https://dev.mysql.com/doc/refman/8.4/en/replication.html
  - https://dev.mysql.com/doc/refman/8.4/en/replication-sbr-rbr.html
  - https://dev.mysql.com/doc/refman/8.4/en/binary-log-setting.html
  - https://dev.mysql.com/doc/refman/8.4/en/replication-gtids.html
  - https://dev.mysql.com/doc/refman/8.4/en/insert-on-duplicate.html
- Real-time fan-out and caching patterns:
  - https://learn.microsoft.com/en-us/azure/azure-web-pubsub/overview
  - https://learn.microsoft.com/en-us/azure/azure-signalr/signalr-howto-scale-signalr
  - https://learn.microsoft.com/en-us/azure/azure-signalr/signalr-howto-scale-autoscale
  - https://learn.microsoft.com/en-us/azure/architecture/patterns/cache-aside
- Azure SQL Data Sync lifecycle/perf caveats:
  - https://learn.microsoft.com/en-us/azure/azure-sql/database/sql-data-sync-data-sql-server-sql-database?view=azuresql
