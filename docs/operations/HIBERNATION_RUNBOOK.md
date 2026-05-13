# PERTI Hibernation Mode Runbook

## Overview

Hibernation mode is an open-ended operational pause that reduces PERTI to core data collection plus VATSWIM. Most downstream flight processing and several UI pages are suspended, but **SWIM API, SWIM pages, and SWIM daemons remain fully operational**. Azure resources are downscaled to match the reduced workload.

**Status**: Active (entered 2026-04-26)
**History**: Active March 2026 - March 7, 2026; Re-entered March 9, 2026; Exited March 12, 2026; Re-entered March 13, 2026 with SWIM exemption; Exited March 20, 2026; Re-entered March 22, 2026 with PostGIS at B2s + review/TMR/TMI Compliance exemption; Exited March 29, 2026; Re-entered March 29, 2026; Exited March 30, 2026; Re-entered March 30, 2026; Exited April 19, 2026; Re-entered April 26, 2026

---

## What Runs During Hibernation

### Daemons (Always Running)

| Daemon | Interval | Purpose |
|--------|----------|---------|
| `vatsim_adl_daemon.php` | 15s | VATSIM Data API fetch + ingest via SP (see disabled features below) |
| `archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge |
| `adl_archive_daemon.php` | Daily 10:00Z | Trajectory archival to blob storage (requires `ADL_ARCHIVE_STORAGE_CONN` env var) |
| `monitoring_daemon.php` | 60s | System metrics collection |
| `process_discord_queue.php` | Continuous | Async TMI Discord posting |
| `ecfmp_poll_daemon.php` | 5min | ECFMP flow measure polling |
| `export_playbook.php` | Daily | Playbook data backup |
| `swim_ws_server.php` | Persistent | SWIM WebSocket server (port 8090) |
| `swim_sync_daemon.php` | 2min | ADL-to-SWIM sync + cleanup |
| `swim_tmi_sync_daemon.php` | 5min | TMI/CDM/reference data sync to SWIM mirrors |
| `simtraffic_swim_poll.php` | 10min | SimTraffic time data polling (webhook fallback) |
| `swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic data back to ADL |
| `refdata_sync_daemon.php` | Daily 06:00Z | CDR + playbook reference reimport |
| `viff_cdm_poll_daemon.php` | 30s | EU CDM milestone data (conditional: `VIFF_CDM_ENABLED=1`) |

### Data Still Being Collected

- VATSIM Data API ingested every 15 seconds
- Flight positions, plans, and times updated in `adl_flight_*` tables
- Trajectories captured in `adl_flight_trajectory`
- Deferred ETA processing (time-budgeted within SP)
- Trajectory archival to Azure Blob Storage (daily, if env var set)

---

## What Is Paused

### Daemons (Skipped at Startup)

| Daemon | Purpose |
|--------|---------|
| `parse_queue_gis_daemon.php` | Route parsing via PostGIS |
| `boundary_gis_daemon.php` | ARTCC/TRACON boundary detection |
| `crossing_gis_daemon.php` | Boundary crossing ETA predictions |
| `waypoint_eta_daemon.php` | Waypoint ETA calculations |
| `scheduler_daemon.php` | Splits/routes auto-activation |
| `event_sync_daemon.php` | VATUSA/VATCAN event sync |
| `cdm_daemon.php` | CDM milestone computation |
| `vacdm_poll_daemon.php` | vACDM polling |
| `delay_attribution_daemon.php` | Per-flight delay computation from EDCT/OOOI baselines |
| `facility_stats_daemon.php` | Hourly/daily facility statistics |
| `webhook_delivery_daemon.php` | Outbound event webhook delivery queue |

### ADL Daemon Features Disabled

When `HIBERNATION_MODE=true`, the ADL daemon auto-disables these features:

- **ATIS parsing** (`atis_enabled = false`)
- **TMI sync** (`tmi_sync_enabled = false`)
- **CTP compliance** (`ctp_compliance_enabled = false`)
- **CTP booking sync** (`ctp_booking_sync_enabled = false`)
- **GDP reoptimization** (`gdp_reopt_enabled = false`)
- **GDP compliance** (`gdp_compliance_enabled = false`)

### Web Pages (Redirect to /hibernation)

`demand.php`, `nod.php`, `simulator.php`, `gdt.php`, `cdm.php`, `sua.php`, `event-aar.php`

**SWIM pages are exempt**: `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php` remain fully accessible.

### SWIM API

**Exempt from hibernation** — all `api/swim/v1/` endpoints remain fully operational. SWIM sync daemon keeps `swim_flights` populated from ADL data.

---

## Configuration

### PHP Config Flag

**File**: `load/config.php`

```php
define("HIBERNATION_MODE", env('HIBERNATION_MODE', true));
```

This controls:
- Page redirects (via `load/hibernation.php`)
- Nav item styling (muted/italic with snowflake icon)
- ADL daemon feature flags (ATIS, TMI sync, CTP, GDP)

### Azure App Setting

**Setting**: `HIBERNATION_MODE=1` (use `1` to enable, `0` to disable; do NOT use `true`/`false` strings)

> **Warning**: String `"false"` is truthy in PHP — any non-empty string except `"0"` is truthy. Always use `1` / `0`.

This controls:
- Daemon startup behavior in `scripts/startup.sh`
- PHP `env()` helper reads Azure App Settings

### Files Involved

| File | Role |
|------|------|
| `load/config.php` | Defines `HIBERNATION_MODE` constant |
| `load/hibernation.php` | Centralized page redirect + hit tracking |
| `hibernation.php` | Public info page |
| `load/nav.php` | Nav items marked with `hibernated` flag |
| `load/nav_public.php` | Same as nav.php for public pages |
| `assets/css/perti_theme.css` | `.nav-hibernated` CSS class |
| `scripts/startup.sh` | Conditional daemon startup |
| `scripts/vatsim_adl_daemon.php` | Feature flags disabled via `HIBERNATION_MODE` |
| `api/data/hibernation_stats.php` | JSON API for hit statistics |
| `database/migrations/hibernation/001_hibernation_hits.sql` | MySQL table for hit tracking |

### Hit Tracking

Every access attempt to a hibernated page is recorded in the `hibernation_hits` table (MySQL `perti_site`). This provides demand data for paused features.

- **Tracked**: Page redirects (type=`page`)
- **Privacy**: IPs are SHA-256 hashed with a salt; raw IPs are never stored
- **Stats API**: `GET /api/data/hibernation_stats.php` returns totals, per-page breakdown, and 30-day daily trend
- **Display**: Stats are shown on the `/hibernation` info page via AJAX

---

## Azure Resource Changes

### Current (Hibernation) vs. Operational Tiers

| Resource | Operational Tier | Hibernation Tier | Monthly Savings |
|----------|-----------------|------------------|-----------------|
| **App Service** (ASP-VATSIMRG-9bb6) | P1v2 (3.5GB) | P1v2 (unchanged — has 4 deployment slots, B1 doesn't support slots) | $0 |
| **VATSIM_ADL** (Hyperscale Serverless) | HS_S_Gen5 min 3 / max 16 vCores | HS_S_Gen5 min 1 / max 4 vCores | ~$800-950 |
| **MySQL** (perti_site) | Standard_D2ds_v4 (GP, 2 vCore, 8GB) | Standard_B1ms (Burstable, 1 vCore, 2GB) | ~$185 |
| **PostGIS** (VATSIM_GIS) | Standard_B2s (Burstable, 2 vCore) | Standard_B2s (unchanged — keeps GIS daemons and backfills performant) | $0 |
| **SWIM_API** (Azure SQL) | Basic 5 DTU | Basic 5 DTU (unchanged — already ~$5/mo) | $0 |
| **VATSIM_TMI/REF** (Azure SQL) | Basic 5 DTU | Basic 5 DTU (unchanged) | $0 |
| **VATSIM_STATS** (Azure SQL) | GP_S_Gen5 1 vCore | GP_S_Gen5 (unchanged) | $0 |
| **Synapse** | Serverless | Serverless (pay-per-query) | $0 |
| **Blob Storage** | Active | Active (minimal cost) | $0 |
| **Total estimated savings** | | | **~$985-1,135/mo** |

### CLI Commands for Entering Hibernation

```bash
# 1. Set Azure App Setting
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings HIBERNATION_MODE=1

# 2. VATSIM_ADL: Reduce Hyperscale Serverless vCore range
az sql db update --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
    --min-capacity 1 --capacity 4 --edition Hyperscale --family Gen5 --compute-model Serverless

# 3. MySQL: GeneralPurpose → Burstable
az mysql flexible-server update --name vatcscc-perti --resource-group VATSIM_RG \
    --sku-name Standard_B1ms --tier Burstable

# 4. PostGIS: stays at B2s (no change needed)

# 5. Restart App Service (wait 2-5 min for DB tier changes first)
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

> **Important**: Database tier changes take 2-5 minutes. Restart the App Service only after the DB changes complete, otherwise daemons may crash on startup when the DB is mid-transition.

---

## How to Exit Hibernation

Follow these steps in order:

### 1. Upscale Azure Resources

```bash
# VATSIM_ADL: Restore Hyperscale Serverless vCore range (min 3, max 16)
az sql db update --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
    --min-capacity 3 --capacity 16 --edition Hyperscale --family Gen5 --compute-model Serverless

# MySQL: Burstable → GeneralPurpose
az mysql flexible-server update --name vatcscc-perti --resource-group VATSIM_RG \
    --sku-name Standard_D2ds_v4 --tier GeneralPurpose

# PostGIS: stays at B2s (no change needed)
```

**Note**: App Service stays at P1v2 (has 4 deployment slots; B1 doesn't support slots). SWIM_API stays at Basic 5 DTU (already minimal cost). PostGIS stays at B2s in both modes.

### 2. Update PHP Config

In `load/config.php`, change:
```php
define("HIBERNATION_MODE", env('HIBERNATION_MODE', true));
```
to:
```php
define("HIBERNATION_MODE", env('HIBERNATION_MODE', false));
```

### 3. Update Azure App Setting

Set `HIBERNATION_MODE` to `0` or remove the setting entirely.

> **Warning**: Do NOT set to `false` — the string `"false"` is truthy in PHP (any non-empty string except `"0"` is truthy). Use `0` or remove the setting entirely.

### 4. Restart App Service

```bash
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

This triggers `startup.sh` which will start all daemons since `HIBERNATION_MODE` is now off. Wait 2-5 min for DB tier changes to complete before restarting.

### 5. Verify

- [ ] All daemons running: `ps aux | grep php` on Kudu SSH
- [ ] ADL ingest working: check `/home/LogFiles/vatsim_adl.log`
- [ ] GIS daemons running: check parse/boundary/crossing logs
- [ ] SWIM sync running: check `/home/LogFiles/swim_sync.log`
- [ ] SWIM API responding: `curl https://perti.vatcscc.org/api/swim/v1/health`
- [ ] Hibernated pages accessible: visit `/demand`, `/gdt`, `/nod`
- [ ] Nav items no longer muted
- [ ] ATIS parsing re-enabled in ADL logs

---

## Troubleshooting

### Pages still redirecting after disabling hibernation

1. Check `load/config.php` — `HIBERNATION_MODE` default must be `false`
2. Check Azure App Setting — must be `0` or removed entirely (not `"false"`)
3. OPcache may be stale — wait 60s for `revalidate_freq` or restart PHP-FPM

### Daemons not starting

1. Check `startup.sh` logs: `cat /home/LogFiles/startup.log`
2. Verify `HIBERNATION_MODE` env var: `echo $HIBERNATION_MODE` in Kudu SSH
3. Manual daemon start: `nohup php /home/site/wwwroot/scripts/<daemon>.php >> /home/LogFiles/<daemon>.log 2>&1 &`

### Database tier changes still in progress

If daemons crash immediately after restart, the DB tier change may not have completed yet. Wait 2-5 minutes and restart again:

```bash
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

---

## Data Recovery & Backfill

### What Happens to Data During Hibernation

During hibernation, core ADL ingest continues (positions, plans, trajectories) but GIS
enrichment daemons are paused. This means flights that flew during hibernation have:

| Data | Status | Recoverable? |
|------|--------|-------------|
| Positions (lat/lon/alt) | Captured every 15s | While in core tables |
| Flight plans (route string) | Captured | While in core tables |
| Trajectories (full-res) | Captured, tiering skipped | While in core tables |
| Times (ETD/ETA/OOOI) | Captured | While in core tables |
| Route parsing (waypoints, geometry) | **NOT processed** | Yes, via backfill |
| Boundary detection (ARTCC/TRACON) | **NOT processed** | Yes, via backfill |
| Crossing predictions | **NOT processed** | Yes, via backfill |
| Waypoint ETAs | **NOT processed** | Active flights only |
| ATIS data | **NOT captured** | Unrecoverable |
| TMI sync to ADL | **NOT running** | Resumes automatically on exit |
| CTP compliance | **NOT running** | Resumes automatically on exit |
| GDP compliance/reopt | **NOT running** | Resumes automatically on exit |
| Delay attribution | **NOT running** | Resumes automatically on exit |

### Critical: Archive Deletes Source Data

`sp_Archive_CompletedFlights` runs during hibernation and **CASCADE-deletes all source
data** (position, plan, trajectory, waypoints) from core tables 2 hours after a flight
completes. The archive table only keeps a denormalized summary (~50 columns).

This means: **flights that completed more than 2 hours ago are already gone from core
tables and cannot be backfilled.** Only currently active flights and very recently
completed flights are recoverable.

### Backfill Procedure

Run the backfill script **immediately after un-hibernating** (after Step 4 above):

#### Step 1: Extend Archive Grace Period

Prevent the archive SP from deleting flights before the backfill pipeline can process them:

```bash
php scripts/backfill/hibernation_recovery.php --phase=0 --delay-hours=24
```

This sets `COMPLETED_FLIGHT_DELAY_HOURS` to 24 (from the default 2), giving the pipeline
24 hours to process flights before archival deletes them.

#### Step 2: Run Diagnostic

```bash
php scripts/backfill/hibernation_recovery.php --phase=0
```

Check the output for:
- How many flights are in core tables (vs already archived)
- Route parse status distribution
- Boundary detection coverage gaps
- Missing crossing predictions

#### Step 3: Queue Route Parsing

```bash
php scripts/backfill/hibernation_recovery.php --phase=1 --include-inactive
```

This inserts unparsed flights into `adl_parse_queue`. The `parse_queue_gis_daemon` (now
running after un-hibernation) processes the queue automatically. Wait for the daemon to
drain the queue before proceeding to Phase 3.

Monitor progress: `tail -f /home/LogFiles/parse_queue_gis.log`

#### Step 4: Backfill Boundary Detection

```bash
php scripts/backfill/hibernation_recovery.php --phase=2 --include-inactive --batch=100
```

Runs PostGIS `detect_boundaries_and_sectors_batch()` for all flights with position data
but no ARTCC assignment. Can run in parallel with the parse queue daemon.

#### Step 5: Backfill Crossing Predictions

```bash
php scripts/backfill/hibernation_recovery.php --phase=3 --include-inactive --batch=50
```

Requires parsed routes (Phase 1 queue must be drained first). Runs PostGIS
`calculate_crossing_etas()` for each flight with waypoints but no crossings.

#### Step 6: Waypoint ETA + SWIM Sync

```bash
php scripts/backfill/hibernation_recovery.php --phase=4
php scripts/backfill/hibernation_recovery.php --phase=5
```

Phase 4 uses the existing SP (active flights only). Phase 5 resets the SWIM sync marker
to trigger a full resync on the next daemon cycle.

#### Step 7: Reset Archive Delay

After the backfill pipeline has caught up (check Phase 0 diagnostic again):

```bash
php scripts/backfill/hibernation_recovery.php --delay-hours=2 --phase=0
```

### Dry Run Mode

All phases support `--dry-run` to preview what would be done without making changes:

```bash
php scripts/backfill/hibernation_recovery.php --phase=all --dry-run
```

### Options Reference

| Option | Description |
|--------|-------------|
| `--phase=N\|all` | Phase 0-5 or `all` to run 1-5 sequentially |
| `--dry-run` | Preview only, no writes |
| `--batch=N` | GIS batch size (default: 100) |
| `--delay-hours=N` | Set archive delay in `adl_archive_config` |
| `--include-inactive` | Process inactive flights too (default: active only for phases 2-4) |
| `--verbose` | Extra logging detail |

---

## Deep Hibernation (Level 2)

### Overview

Deep hibernation is a cost-reduction mode that goes beyond Level 1 by suspending **all** flight data processing — including ADL ingest and SWIM API. Raw VATSIM JSON is captured to Azure Blob Storage for post-processing replay.

### Mode Hierarchy

| Level | ADL Ingest | SWIM API | Daemons Running | Pages Redirected |
|-------|-----------|----------|-----------------|------------------|
| 0 (Operational) | Full pipeline | Operational | All | None |
| 1 (Hibernation) | Full pipeline | Operational | Core + SWIM | 7 pages |
| **2 (Deep)** | **Raw capture only** | **503** | **2 daemons** | **11 pages** |

### What Runs

| Daemon | Interval | Purpose |
|--------|----------|---------|
| `deep_hibernation_daemon.php` | 15s fetch, 10min upload | VATSIM JSON capture + blob archival |
| `monitoring_daemon.php` | 60s | System health metrics |

### What Is Stopped

All daemons from Level 1 are stopped, including:
- `vatsim_adl_daemon.php` (replaced by capture daemon)
- All SWIM daemons (ws, sync, SimTraffic, reverse sync, TMI sync)
- `archival_daemon.php`, `adl_archive_daemon.php`
- `process_discord_queue.php`, `ecfmp_poll_daemon.php`
- `refdata_sync_daemon.php`, `export_playbook.php`
- All conditional daemons (viff, playbook export)

### Additional Pages Redirected

SWIM pages are redirected to `/hibernation` in deep mode (exempt in Level 1):
- `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`

### SWIM API

All `api/swim/v1/*` endpoints return **HTTP 503** with:
```json
{"error": "Service suspended", "mode": "deep_hibernation", "message": "..."}
```

### Configuration

| Setting | Value | Notes |
|---------|-------|-------|
| `DEEP_HIBERNATION_MODE` (Azure App Setting) | `1` | Use `1`/`0`, not `true`/`false` |
| `HIBERNATION_MODE` (Azure App Setting) | `1` | Must also be set (deep implies hibernation) |
| `load/config.php` | `DEEP_HIBERNATION_MODE` constant | Default: `false` |

### Data Capture

- Raw JSON from `https://data.vatsim.net/v3/vatsim-data.json` captured every 15 seconds
- Gzip-compressed, buffered locally at `/home/site/data/deep-hibernation-buffer/`
- Batch-uploaded to `adl-raw-archive` container under `datafeed/YYYY/MM/DD/HH/` every 10 minutes
- Estimated: ~1.4 GB/day compressed, ~$0.92/month Azure storage cost

### Entering Deep Hibernation (from Level 1)

```bash
# 1. Set Azure App Settings
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings DEEP_HIBERNATION_MODE=1 HIBERNATION_MODE=1

# 2. Update load/config.php defaults (commit + deploy):
#    DEEP_HIBERNATION_MODE default -> true (optional, for defense-in-depth)

# 3. VATSIM_ADL: no change (stays at min 1 / max 4, auto-pause off)

# 4. (Optional) Pause PostGIS if degraded route analysis is acceptable
az postgres flexible-server stop --name vatcscc-gis --resource-group VATSIM_RG

# 5. Restart App Service (after any DB changes propagate)
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

### Exiting Deep Hibernation

```bash
# 1. (If operational tier needed) Upscale VATSIM_ADL
az sql db update --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
    --min-capacity 3 --capacity 16

# 2. (If paused) Restart PostGIS
az postgres flexible-server start --name vatcscc-gis --resource-group VATSIM_RG

# 3. Update Azure App Settings
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings DEEP_HIBERNATION_MODE=0 HIBERNATION_MODE=0

# 4. Update load/config.php defaults (commit + deploy)

# 5. Restart App Service
az webapp restart --name vatcscc --resource-group VATSIM_RG

# 6. Run replay
php scripts/deep_hibernation_replay.php --verbose

# 7. Run GIS backfill
php scripts/backfill/hibernation_recovery.php --phase=auto --include-inactive

# 8. Reset archive grace period
php scripts/backfill/hibernation_recovery.php --delay-hours=2

# 9. Verify (see checklist)
```

### Exit Verification Checklist

- [ ] All daemons running: `ps aux | grep php` on Kudu SSH
- [ ] ADL ingest working: `tail /home/LogFiles/vatsim_adl.log`
- [ ] GIS daemons running: check parse/boundary/crossing logs
- [ ] SWIM sync running: `tail /home/LogFiles/swim_sync.log`
- [ ] SWIM API responding: `curl https://perti.vatcscc.org/api/swim/v1/health`
- [ ] All pages accessible (demand, gdt, nod, swim)
- [ ] Nav items no longer muted/snowflaked
- [ ] Replay completed: check `/home/LogFiles/deep_hibernation_replay.log`
- [ ] GIS backfill completed: `php scripts/backfill/hibernation_recovery.php --phase=0`
- [ ] Archive grace period reset to 2 hours

### Troubleshooting

#### Capture daemon not archiving

1. Check log: `tail /home/LogFiles/deep_hibernation.log`
2. Verify `ADL_ARCHIVE_STORAGE_CONN` is set: `echo $ADL_ARCHIVE_STORAGE_CONN | head -c 40`
3. Check buffer directory: `ls -la /home/site/data/deep-hibernation-buffer/`
4. Manual test: `curl -s https://data.vatsim.net/v3/vatsim-data.json | head -c 100`

#### Replay fails mid-way

1. The replay script is resumable — re-run with `--start-date` set to where it stopped
2. Check ADL connection: `tail /home/LogFiles/deep_hibernation_replay.log`
3. If SP timeout issues, try `--delay-ms=1000` to slow down

#### SWIM API still returning 503 after exit

1. Check `DEEP_HIBERNATION_MODE` env var: must be `0` or removed
2. Check `load/config.php` default (must be `false`)
3. OPcache stale — wait 60s or restart PHP-FPM
