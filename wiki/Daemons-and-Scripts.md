# Daemons and Scripts

> **HIBERNATION MODE ACTIVE** (since March 22, 2026): ADL Ingest and all SWIM daemons run during hibernation (SWIM exempt). All non-SWIM daemons are suspended. See `docs/HIBERNATION_RUNBOOK.md` for re-activation procedures.

Background processes that keep PERTI data current. All 17 daemons are started at App Service boot via `scripts/startup.sh` and run continuously (ADL Archive is conditional on `ADL_ARCHIVE_STORAGE_CONN`).

---

## Daemon Overview

| Daemon | Script | Interval | Purpose | Hibernation |
|--------|--------|----------|---------|-------------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s | Flight data ingestion + ATIS processing | **ACTIVE** |
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Route parsing with PostGIS | Suspended |
| Boundary Detection (GIS) | `adl/php/boundary_gis_daemon.php` | 15s | Spatial boundary detection | Suspended |
| Crossing Calculation | `adl/php/crossing_gis_daemon.php` | Tiered | Boundary crossing ETA prediction | Suspended |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered | Waypoint ETA calculation | Suspended |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent | Real-time events on port 8090 | **Active** (SWIM exempt) |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min | Sync ADL flights to SWIM_API | **Active** (SWIM exempt) |
| SWIM TMI Sync | `scripts/swim_tmi_sync_daemon.php` | 5min | Sync TMI/CDM/flow/ref data to SWIM_API | **Active** (SWIM exempt) |
| SWIM Refdata Sync | `scripts/refdata_sync_daemon.php` | Daily 06:00Z | Sync CDRs, playbook, airports, taxi ref to SWIM_API | **Active** (SWIM exempt) |
| SimTraffic Poll | `scripts/simtraffic_swim_poll.php` | 2min | SimTraffic time data polling | **Active** (SWIM exempt) |
| Reverse Sync | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic data back to ADL | **Active** (SWIM exempt) |
| Scheduler | `scripts/scheduler_daemon.php` | 60s | Splits/routes auto-activation | Suspended |
| Archival | `scripts/archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge | Suspended |
| Monitoring | `scripts/monitoring_daemon.php` | 60s | System metrics collection | Suspended |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous | Async TMI Discord posting | Suspended |
| Event Sync | `scripts/event_sync_daemon.php` | 6h | VATUSA/VATCAN/VATSIM event sync | Suspended |
| ADL Archive | `scripts/adl_archive_daemon.php` | Daily 10:00Z | Trajectory archival to blob storage (conditional) | Suspended |

---

## Active Daemons (Detail)

### vatsim_adl_daemon.php

Refreshes flight data from VATSIM API and processes ATIS data. Uses `sp_Adl_RefreshFromVatsim_Staged` (V9.4.0) with delta detection and optional deferred processing.

| Setting | Value |
|---------|-------|
| Location | `scripts/vatsim_adl_daemon.php` |
| Interval | ~15 seconds |
| Language | PHP |
| SP Version | V9.4.0 |

**Key config options:**

| Option | Default | Purpose |
|--------|---------|---------|
| `defer_expensive` | `true` | Defer ETA/snapshot steps, always capture trajectory |
| `deferred_eta_interval` | `2` | Run wind-adjusted batch ETA every N cycles when budget allows |
| `zone_daemon_enabled` | `false` | Skip zone detection in SP (use separate zone_daemon.php) |

**Delta detection (V9.4.0):** Each cycle, the daemon compares pilot data against the previous cycle in memory via `computeChangeFlags()`. Unchanged flights get `change_flags=0` (heartbeat), which tells the SP to skip geography, position, plan, and aircraft processing — only timestamps are updated. This reduces SP time by ~30-40%. The `hb=N` value in log output shows how many heartbeat flights were detected.

When `defer_expensive` is enabled, the SP captures trajectory points on every cycle but defers ETA calculations. After the SP returns, the daemon checks remaining time budget and runs deferred ETA steps if time permits (with a 2s safety margin). This ensures data ingestion always completes within the 15s VATSIM API window.

**Usage:**
```bash
php scripts/vatsim_adl_daemon.php
```

---

### parse_queue_gis_daemon.php

Processes route parsing queue using PostGIS for spatial geometry.

| Setting | Value |
|---------|-------|
| Location | `adl/php/parse_queue_gis_daemon.php` |
| Interval | 10 seconds (batch) |
| Language | PHP |

**Usage:**
```bash
php adl/php/parse_queue_gis_daemon.php --loop
php adl/php/parse_queue_gis_daemon.php --batch=100
```

---

### boundary_gis_daemon.php

Detects ARTCC/TRACON boundary crossings using PostGIS spatial queries.

| Setting | Value |
|---------|-------|
| Location | `adl/php/boundary_gis_daemon.php` |
| Interval | 15 seconds |
| Language | PHP |

---

### crossing_gis_daemon.php

Calculates boundary crossing ETAs using tiered processing intervals.

| Setting | Value |
|---------|-------|
| Location | `adl/php/crossing_gis_daemon.php` |
| Interval | Tiered (15s-5min) |
| Language | PHP |

---

### waypoint_eta_daemon.php

Calculates ETAs at each waypoint on a flight's route.

| Setting | Value |
|---------|-------|
| Location | `adl/php/waypoint_eta_daemon.php` |
| Interval | Tiered (15s-5min) |
| Language | PHP |

**Usage:**
```bash
php adl/php/waypoint_eta_daemon.php --loop
php adl/php/waypoint_eta_daemon.php --tier=0
php adl/php/waypoint_eta_daemon.php --loop --interval=15 --flights=500
```

---

### swim_ws_server.php

WebSocket server providing real-time flight data events.

| Setting | Value |
|---------|-------|
| Location | `scripts/swim_ws_server.php` |
| Port | 8090 |
| Language | PHP |

---

### swim_sync_daemon.php

Syncs flight data from VATSIM_ADL to the dedicated SWIM_API database. Uses `sp_Swim_BulkUpsert` with row-hash skip to avoid updating unchanged rows, and emits changes to `swim_change_feed` for downstream consumers.

| Setting | Value |
|---------|-------|
| Location | `scripts/swim_sync_daemon.php` |
| Interval | 2 minutes |
| Language | PHP |
| Data source | `scripts/swim_sync.php` (~219 columns from 6 ADL tables) |
| Target | `swim_flights` via `sp_Swim_BulkUpsert` |

---

### swim_tmi_sync_daemon.php

Syncs TMI, CDM, flow, and reference data from VATSIM_TMI and VATSIM_ADL to SWIM_API mirror tables. Two-tier sync: operational data every 5 minutes (offset from flight sync), reference data daily at a random time in the 0601-0801Z window.

| Setting | Value |
|---------|-------|
| Location | `scripts/swim_tmi_sync_daemon.php` |
| Interval | 5 minutes (operational) / daily (reference) |
| Language | PHP |
| Tables synced | 14 mirror tables (10 TMI + 4 flow) |
| Method | Watermark-based delta detection + OPENJSON MERGE |

**Operational tier (every 5 min):** `swim_ntml`, `swim_tmi_programs`, `swim_tmi_entries`, `swim_tmi_advisories`, `swim_tmi_reroutes`, `swim_tmi_reroute_routes`, `swim_tmi_reroute_flights`, `swim_tmi_reroute_compliance_log`, `swim_tmi_public_routes`, `swim_tmi_flight_control`, `swim_tmi_flow_providers`, `swim_tmi_flow_events`, `swim_tmi_flow_event_participants`, `swim_tmi_flow_measures`

**Reference tier (daily 0601-0801Z):** `swim_airports` (from ADL `apts`), `swim_airport_taxi_reference` + `_detail`

---

### refdata_sync_daemon.php

Syncs reference data (CDRs, playbook routes, airports, taxi reference) from VATSIM_REF, VATSIM_ADL, and MySQL to SWIM_API. Runs daily with incremental upsert + tombstone deactivation for large tables.

| Setting | Value |
|---------|-------|
| Location | `scripts/refdata_sync_daemon.php` |
| Schedule | Daily at 06:00Z |
| Language | PHP |
| Tables synced | `swim_coded_departure_routes` (~41K), `swim_playbook_routes` (~55K), `swim_airports`, `swim_airport_taxi_reference` |
| Method | Full MERGE with `is_active` tombstoning for CDRs/playbook |

---

### simtraffic_swim_poll.php

Polls SimTraffic time data and syncs it into the SWIM_API database.

| Setting | Value |
|---------|-------|
| Location | `scripts/simtraffic_swim_poll.php` |
| Interval | 2 minutes |
| Language | PHP |

---

### swim_adl_reverse_sync_daemon.php

Reverse syncs data from SWIM_API back to VATSIM_ADL — primarily SimTraffic metering times and CDM milestone data.

| Setting | Value |
|---------|-------|
| Location | `scripts/swim_adl_reverse_sync_daemon.php` |
| Interval | 2 minutes |
| Language | PHP |

---

### scheduler_daemon.php

Manages automatic activation/deactivation of splits and scheduled routes.

| Setting | Value |
|---------|-------|
| Location | `scripts/scheduler_daemon.php` |
| Interval | 60 seconds |
| Language | PHP |

---

### archival_daemon.php

Manages trajectory data tiering, changelog purging, and data archival.

| Setting | Value |
|---------|-------|
| Location | `scripts/archival_daemon.php` |
| Interval | 1-4 hours |
| Language | PHP |

---

### monitoring_daemon.php

Collects system metrics for health monitoring.

| Setting | Value |
|---------|-------|
| Location | `scripts/monitoring_daemon.php` |
| Interval | 60 seconds |
| Language | PHP |

---

### process_discord_queue.php

Processes the TMI Discord posting queue asynchronously for multi-org support.

| Setting | Value |
|---------|-------|
| Location | `scripts/tmi/process_discord_queue.php` |
| Mode | Continuous |
| Language | PHP |

---

### event_sync_daemon.php

Syncs events from VATUSA, VATCAN, and VATSIM APIs.

| Setting | Value |
|---------|-------|
| Location | `scripts/event_sync_daemon.php` |
| Interval | 6 hours |
| Language | PHP |

---

### adl_archive_daemon.php

Archives trajectory data to Azure Blob Storage on a daily schedule.

| Setting | Value |
|---------|-------|
| Location | `scripts/adl_archive_daemon.php` |
| Schedule | Daily at 10:00Z (configurable via `ADL_ARCHIVE_HOUR_UTC`) |
| Language | PHP |
| Condition | Only starts if `ADL_ARCHIVE_STORAGE_CONN` environment variable is set |

The archive daemon runs at the lowest-traffic time (night in the Americas, morning in Europe). It moves completed flight trajectory data from `adl_flight_trajectory` to Azure Blob Storage for long-term retention.

---

### atis_daemon.py

Imports ATIS data from VATSIM with weather parsing. Note: ATIS processing is now also integrated into the main ADL ingest daemon.

| Setting | Value |
|---------|-------|
| Location | `scripts/vatsim_atis/atis_daemon.py` |
| Interval | 15 seconds |
| Language | Python |

**Usage:**
```bash
python scripts/vatsim_atis/atis_daemon.py
python scripts/vatsim_atis/atis_daemon.py --once
python scripts/vatsim_atis/atis_daemon.py --airports KJFK,KLAX
```

---

## Tiered Processing

Several daemons use tiered intervals based on flight priority:

| Tier | Interval | Criteria |
|------|----------|----------|
| Tier 0 | 15s | Active flights within 60nm of destination |
| Tier 1 | 30s | Active flights en route |
| Tier 2 | 60s | Prefiled flights departing within 2h |
| Tier 3 | 2min | Prefiled flights departing within 6h |
| Tier 4 | 5min | All other flights |

---

## Import Scripts

| Script | Purpose | Schedule |
|--------|---------|----------|
| `import_weather_alerts.php` | SIGMET/AIRMET updates | Every 5 min |
| `nasr_navdata_updater.py` | FAA NASR data | On demand / AIRAC cycle |
| `update_playbook_routes.py` | FAA playbook routes | On demand |
| `build_sector_boundaries.py` | Sector boundary polygons | On demand |
| `airac_full_update.py` | Full AIRAC cycle data update | Every 28 days |

---

## Startup

All daemons are started via `scripts/startup.sh` during Azure App Service boot:

```bash
# startup.sh configures nginx, starts all daemons, then PHP-FPM foreground
scripts/startup.sh
```

Logs are written to `/home/LogFiles/<daemon>.log`.

---

## Backfill System (Hibernation Recovery)

During hibernation recovery, a dedicated backfill pipeline processes accumulated flight data through the offline processing stages.

| Phase | Description | Status |
|-------|-------------|--------|
| Phase 1 | Route parsing queue (185K flights) | Complete |
| Phase 2 | Boundary detection (166K flights) | Complete |
| Phase 3 | Crossing calculations (705K flights) | **In Progress** (~108 flights/min) |
| Phase 4 | Waypoint ETA calculations | Pending |
| Phase 5 | SWIM sync | Pending |
| Phase 6 | Archival | Pending |

**Script:** `_backfill_full.php` (deployed on production)
**Status URL:** `https://perti.vatcscc.org/_backfill_full.php?action=status`
**State storage:** MySQL `backfill_state` / `backfill_log` tables

---

## See Also

- [[Deployment]] - Service setup
- [[Data Flow]] - Data pipelines
- [[Troubleshooting]] - Common issues
