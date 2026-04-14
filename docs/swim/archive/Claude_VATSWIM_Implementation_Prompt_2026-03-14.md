# Claude Prompt: VATSWIM Implementation Planning (PERTI)

Copy/paste the prompt below into Claude, then fill the `REPLACE_ME` values before sending.

```text
You are planning implementation work for VATSWIM in the PERTI repository.

Date context:
- Today is 2026-03-14.
- If you refer to time-relative concepts (today/yesterday/tomorrow), also include absolute dates.

Operating mode:
- Produce an implementation plan that can be executed in phased PRs with low operational risk.
- Do not hallucinate repo facts. If a claim is not directly supported by the facts below or provided inputs, label it as an assumption.

Hard constraints:
- Preserve ADL ingest cadence at 15 seconds.
- Support high fan-out external consumers at up to 1-second perceived resolution.
- Assume direct DB polling by all clients is not acceptable on constrained tiers (e.g., Azure SQL Basic 5 DTU).
- Treat SWIM as the distribution hub for internal fan-out and external API serving.
- Avoid introducing new dependency on Azure SQL Data Sync for long-term architecture.

Verified repo facts (authoritative):
- ADL daemon refresh loop runs every 15s and calls staged refresh SP:
  - scripts/vatsim_adl_daemon.php:53
  - scripts/vatsim_adl_daemon.php:2718
- ADL staged refresh supports deferring expensive work via @defer_expensive:
  - adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:44
  - adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:827
  - adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql:859
- SWIM sync is delta-based from ADL, using last_sync watermark:
  - scripts/swim_sync.php:135
  - scripts/swim_sync.php:236
  - scripts/swim_sync.php:238
  - scripts/swim_sync.php:240
- SWIM sync uses JSON bulk upsert SP:
  - scripts/swim_sync.php:320
  - database/migrations/swim/004_swim_bulk_upsert_sp.sql:12
- SWIM bulk upsert currently does unconditional update on matched rows:
  - database/migrations/swim/004_swim_bulk_upsert_sp.sql:113
- SWIM refdata sync currently does full delete+reload:
  - scripts/swim_refdata_sync.php:101
  - scripts/swim_refdata_sync.php:200
  - scripts/swim_refdata_sync.php:206
- ADL daemon uses SWIM daemon as primary and inline SWIM as fallback based on heartbeat:
  - scripts/vatsim_adl_daemon.php:162
  - scripts/vatsim_adl_daemon.php:164
  - scripts/vatsim_adl_daemon.php:165
  - scripts/vatsim_adl_daemon.php:2894
- WebSocket event flow exists and is integrated in ADL daemon:
  - scripts/vatsim_adl_daemon.php:2953
  - scripts/swim_ws_server.php
- Position timestamp query/schema alignment is currently verified:
  - scripts/swim_ws_events.php:199 filters on pos.position_updated_utc
  - adl/migrations/core/001_adl_core_tables.sql:109 defines position_updated_utc

User-provided project inputs (authoritative; fill these now):
- environment_matrix:
  - Near-real-time mirrors required: IDEALLY YES IF NOT TOO COSTLY
- scale_targets:
  - steady_active_flights: 2500
  - peak_active_flights: 5000
  - source_update_cadence_sec: 15
  - expected_external_subscribers: 100
  - allowed_internal_sync_staleness_sec: 15
  - allowed_external_staleness_sec: 15
- resource_constraints:
  - azure_sql_tier: Basic 5 DTU
  - can_upgrade_tier: LIMITED
  - max_monthly_cost_increase_usd: $10
- consumer_contract:
  - canonical_external_transport: TBD
  - polling_fallback_policy: TBD
  - replay_retention_hours: 30 DAYS
  - max_delta_payload_kb: TBD
- schema_constraints:
  - immutable_tables_or_columns: TBD
  - new_tables_allowed: TBD
- rollout_constraints:
  - environments_order: TBD
  - maintenance_windows_utc: TBD
  - rollback_requirements: TBD
  - feature_flag_requirements: TBD
- security_constraints:
  - allowed_managed_services: TBD
  - prohibited_services: TBD
  - external_data_exposure_limits: TBD
- validation_artifacts:
  - sample_payload_paths: TBD
  - top_slow_sql_summary: TBD
  - dtu_cpu_io_snapshots: TBD

Required output structure:
1. Executive summary
   - Max 12 lines.
   - State recommended target architecture and why it is safe under constraints.
2. Decision matrix
   - Compare at least 2 options for each key decision:
     - Change feed strategy (SWIM-local feed vs DB-native CT/CDC/replication per source DB)
     - External 1-second delivery strategy (WebSocket fan-out vs polling-heavy model)
     - Refdata sync strategy (delete+reload vs incremental upsert+tombstone)
   - For each option include benefits, risks, operational complexity, and compatibility with 5 DTU.
3. Phase plan (Phase 0 to Phase 4)
   - For each phase include:
     - SQL migrations
     - PHP code touchpoints (exact files)
     - API/WS contract changes
     - tests (unit/integration/load)
     - observability/alerts
     - rollback path
     - acceptance criteria with measurable thresholds
4. First 3 PR plan
   - PR1/PR2/PR3 with:
     - exact file list
     - migration sequence
     - risk level
     - test plan
     - deployment order
5. Operational SLO and guardrails
   - Propose concrete SLOs and alert thresholds for:
     - ingest lag
     - delta replay lag
     - WS fan-out health
     - DB pressure (CPU/DTU/IO/session)
     - queue depth/backlog
6. Unknowns and assumptions
   - List unknowns that block implementation.
   - For each unknown, provide a default assumption and impact if wrong.
7. Deliverables format
   - Provide:
     - one concise roadmap table,
     - one risk register table,
     - one migration dependency graph (text form),
     - one cutover checklist.

Quality bar:
- Be explicit about tradeoffs.
- Do not propose direct 1-second full-table polling against constrained SQL tiers.
- Prefer idempotent, resumable, watermark-based sync contracts.
- Keep recommendations aligned with repository facts listed above.
```
