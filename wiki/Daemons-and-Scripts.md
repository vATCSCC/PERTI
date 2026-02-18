# Daemons and Scripts

Background processes that keep PERTI data current. All 14 daemons are started at App Service boot via `scripts/startup.sh` and run continuously.

---

## Daemon Overview

| Daemon | Script | Interval | Purpose |
|--------|--------|----------|---------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s | Flight data ingestion + ATIS processing |
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Route parsing with PostGIS |
| Boundary Detection (GIS) | `adl/php/boundary_gis_daemon.php` | 15s | Spatial boundary detection |
| Crossing Calculation | `adl/php/crossing_gis_daemon.php` | Tiered | Boundary crossing ETA prediction |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered | Waypoint ETA calculation |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent | Real-time events on port 8090 |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min | Sync ADL to SWIM_API database |
| SimTraffic Poll | `scripts/simtraffic_swim_poll.php` | 2min | SimTraffic time data polling |
| Reverse Sync | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic data back to ADL |
| Scheduler | `scripts/scheduler_daemon.php` | 60s | Splits/routes auto-activation |
| Archival | `scripts/archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge |
| Monitoring | `scripts/monitoring_daemon.php` | 60s | System metrics collection |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous | Async TMI Discord posting |
| Event Sync | `scripts/event_sync_daemon.php` | 6h | VATUSA/VATCAN/VATSIM event sync |

---

## Active Daemons (Detail)

### vatsim_adl_daemon.php

Refreshes flight data from VATSIM API and processes ATIS data. Uses `sp_Adl_RefreshFromVatsim_Staged` (V9.3.0) with delta detection and optional deferred processing.

| Setting | Value |
|---------|-------|
| Location | `scripts/vatsim_adl_daemon.php` |
| Interval | ~15 seconds |
| Language | PHP |
| SP Version | V9.3.0 |

**Key config options:**

| Option | Default | Purpose |
|--------|---------|---------|
| `defer_expensive` | `true` | Defer ETA/snapshot steps, always capture trajectory |
| `deferred_eta_interval` | `2` | Run wind-adjusted batch ETA every N cycles when budget allows |
| `zone_daemon_enabled` | `false` | Skip zone detection in SP (use separate zone_daemon.php) |

**Delta detection (V9.3.0):** Each cycle, the daemon compares pilot data against the previous cycle in memory via `computeChangeFlags()`. Unchanged flights get `change_flags=0` (heartbeat), which tells the SP to skip geography, position, plan, and aircraft processing — only timestamps are updated. This reduces SP time by ~30-40%. The `hb=N` value in log output shows how many heartbeat flights were detected.

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

Syncs flight data from VATSIM_ADL to the dedicated SWIM_API database.

| Setting | Value |
|---------|-------|
| Location | `scripts/swim_sync_daemon.php` |
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

## See Also

- [[Deployment]] - Service setup
- [[Data Flow]] - Data pipelines
- [[Troubleshooting]] - Common issues
