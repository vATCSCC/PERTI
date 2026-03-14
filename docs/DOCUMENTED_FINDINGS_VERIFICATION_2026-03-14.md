# Documented Findings Verification

Date: 2026-03-14  
Scope: verification of currently documented findings in dependency-map and VATSWIM planning docs

## Verified Documents

1. `docs/CHANGE_IMPACT_DEPENDENCY_MAP_2026-03-14.md`
2. `docs/swim/VATSWIM_Web_Optimizations_2026-03-14.md`
3. `docs/swim/Claude_VATSWIM_Implementation_Prompt_2026-03-14.md`
4. `docs/swim/VATSWIM_Planning_Verified_Findings_2026-03-14.md`

## Method

1. Re-validated all dependency-map metrics/tables against machine artifacts in `artifacts/dependency-map-final/`.
2. Re-validated repo-backed SWIM findings directly against current code and SQL files.
3. Re-validated external web-research findings against official vendor docs (Microsoft Learn, PostgreSQL docs, MySQL docs).
4. Patched stale findings where claims no longer matched current code.

## Result Summary

| Area | Status | Notes |
| --- | --- | --- |
| Change Impact Dependency Map | Verified | Metrics, artifact list, and all published table snapshots match `dependency-map-final` outputs. |
| VATSWIM repo-backed findings | Verified | Core claims validated from source files. |
| VATSWIM external research findings | Verified | All high-confidence findings are supported by official docs listed below. |
| Stale claim cleanup | Completed | Replaced stale `pos.updated_at` mismatch claims with current aligned `position_updated_utc` evidence. |

Machine-readable verification report: `artifacts/verification_report_findings_2026-03-14.json`

## Dependency Map Verification Details

All checks passed for:

1. Coverage metrics (11/11) vs `artifacts/dependency-map-final/meta.json`
2. Artifact presence (16/16) for all authoritative outputs listed in the doc
3. Table snapshots:
   - Shared file dependency hotspots
   - Frontend page change impact matrix
   - API domain footprint
   - DB connection footprint
   - Highest-impact table groups
   - Docs/wiki update hotspots
4. Grouped-table count claim (`361`) vs CSV actual (`361`)

## Repo-Backed SWIM Verification Details

Validated anchors include:

1. ADL 15s ingest loop and staged refresh execution.
2. `@defer_expensive` support in staged SP.
3. SWIM delta watermark/query logic (`MAX(last_sync_utc)`, position/times/tmi updated filters).
4. SWIM JSON bulk upsert path and unconditional `WHEN MATCHED THEN UPDATE SET`.
5. SWIM refdata full delete+reload behavior for CDR/playbook tables.
6. ADL->SWIM daemon/fallback/heartbeat behavior.
7. WS integration and default position-stream setting.
8. Connection pooling and safe reconnect/close logic.
9. WS position query uses `pos.position_updated_utc` and matches schema/procedure timestamp contract.

## Corrections Applied

Updated stale mismatch claims in:

1. `docs/swim/Claude_VATSWIM_Implementation_Prompt_2026-03-14.md`
2. `docs/swim/VATSWIM_Web_Optimizations_2026-03-14.md`
3. `docs/swim/VATSWIM_Planning_Verified_Findings_2026-03-14.md`

Changes:

1. Removed claim that `scripts/swim_ws_events.php:199` references `pos.updated_at`.
2. Replaced with verified current state: query uses `pos.position_updated_utc`, aligned with `adl/migrations/core/001_adl_core_tables.sql`.
3. Updated planning text to treat this as an alignment invariant + regression-test requirement, not an active defect.

## External Findings Verification (Official Sources)

The web findings in `docs/swim/VATSWIM_Web_Optimizations_2026-03-14.md` are supported by these official sources:

1. SQL Server CT/CDC and CT sync pattern (`CHANGETABLE`, current/min valid version, snapshot guidance):
   - https://learn.microsoft.com/en-us/sql/relational-databases/track-changes/track-data-changes-sql-server?view=sql-server-ver17
   - https://learn.microsoft.com/en-us/sql/relational-databases/track-changes/work-with-change-tracking-sql-server?view=sql-server-ver17
2. Azure SQL batching, TVP vs bulk copy, and batch-splitting caution:
   - https://learn.microsoft.com/en-us/azure/azure-sql/performance-improve-use-batching?view=azuresql
3. Azure SQL Basic 5 DTU constraints:
   - https://learn.microsoft.com/en-us/azure/azure-sql/database/resource-limits-dtu-single-databases?view=azuresql
4. Azure SQL transient retry guidance:
   - https://learn.microsoft.com/en-us/azure/azure-sql/database/troubleshoot-common-connectivity-issues?view=azuresql
5. ADO.NET SQL Server pooling semantics and fragmentation behavior:
   - https://learn.microsoft.com/en-us/dotnet/framework/data/adonet/sql-server-connection-pooling
6. PostgreSQL logical replication (incremental distribution, ordering/slot caveats), publication filtering, and COPY progress/error controls:
   - https://www.postgresql.org/docs/current/logical-replication.html
   - https://www.postgresql.org/docs/current/logical-replication-subscription.html
   - https://www.postgresql.org/docs/current/sql-createpublication.html
   - https://www.postgresql.org/docs/current/sql-copy.html
7. Azure PostgreSQL PgBouncer guidance:
   - https://learn.microsoft.com/en-us/azure/postgresql/connectivity/concepts-pgbouncer
8. Web PubSub polling drawbacks, SignalR scale/autoscale, cache-aside consistency caveats:
   - https://learn.microsoft.com/en-us/azure/azure-web-pubsub/overview
   - https://learn.microsoft.com/en-us/azure/azure-signalr/signalr-howto-scale-signalr
   - https://learn.microsoft.com/en-us/azure/azure-signalr/signalr-howto-scale-autoscale
   - https://learn.microsoft.com/en-us/azure/architecture/patterns/cache-aside
9. Azure SQL Data Sync retirement and behavior caveats:
   - https://learn.microsoft.com/en-us/azure/azure-sql/database/sql-data-sync-data-sql-server-sql-database?view=azuresql
10. MySQL GTID replication, replication-format tradeoffs, binlog tuning context, and upsert caveat:
   - https://dev.mysql.com/doc/refman/8.4/en/replication-gtids.html
   - https://dev.mysql.com/doc/refman/8.4/en/replication-sbr-rbr.html
   - https://dev.mysql.com/doc/refman/8.4/en/binary-log-setting.html
   - https://dev.mysql.com/doc/refman/8.4/en/insert-on-duplicate.html
