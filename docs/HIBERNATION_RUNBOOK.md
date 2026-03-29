# PERTI Hibernation Mode Runbook

## Overview

Hibernation mode is an open-ended operational pause that reduces PERTI to core data collection plus VATSWIM. Most downstream flight processing and several UI pages are suspended, but **SWIM API, SWIM pages, and SWIM daemons remain fully operational**. Azure resources are downscaled to match the reduced workload.

**Status**: Active (re-entered 2026-03-29)
**History**: Active March 2026 - March 7, 2026; Re-entered March 9, 2026; Exited March 12, 2026; Re-entered March 13, 2026 with SWIM exemption; Exited March 20, 2026; Re-entered March 22, 2026 with PostGIS at B2s + review/TMR/TMI Compliance exemption; Exited March 29, 2026; Re-entered March 29, 2026

---

## What Runs During Hibernation

### Daemons (Always Running)

| Daemon | Interval | Purpose |
|--------|----------|---------|
| `vatsim_adl_daemon.php` | 15s | VATSIM Data API fetch + ingest via SP (ATIS parsing disabled) |
| `archival_daemon.php` | 1-4h | Trajectory tiering, changelog purge |
| `adl_archive_daemon.php` | Daily 10:00Z | Trajectory archival to blob storage |
| `monitoring_daemon.php` | 60s | System metrics collection |
| `process_discord_queue.php` | Continuous | Async TMI Discord posting |
| `ecfmp_poll_daemon.php` | 5min | ECFMP flow measure polling |
| `export_playbook.php` | Daily | Playbook data backup |
| `swim_ws_server.php` | Persistent | SWIM WebSocket server (port 8090) |
| `swim_sync_daemon.php` | 2min | ADL-to-SWIM sync + cleanup |
| `simtraffic_swim_poll.php` | 2min | SimTraffic time data polling |
| `swim_adl_reverse_sync_daemon.php` | 2min | SimTraffic data back to ADL |

### Data Still Being Collected

- VATSIM Data API ingested every 15 seconds
- Flight positions, plans, and times updated in `adl_flight_*` tables
- Trajectories captured in `adl_flight_trajectory`
- Deferred ETA processing (time-budgeted within SP)
- Trajectory archival to Azure Blob Storage (daily)

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

### ADL Daemon Features Disabled

- ATIS parsing (controlled by `atis_enabled` config, auto-disabled when `HIBERNATION_MODE=true`)

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
- SWIM API 503 responses
- Nav item styling (muted/italic with snowflake icon)
- ATIS parsing disable in ADL daemon

### Azure App Setting

**Setting**: `HIBERNATION_MODE=true`

This controls:
- Daemon startup behavior in `scripts/startup.sh`
- PHP `env()` helper reads Azure App Settings

### Files Involved

| File | Role |
|------|------|
| `load/config.php` | Defines `HIBERNATION_MODE` constant |
| `load/hibernation.php` | Centralized page redirect + SWIM API 503 |
| `hibernation.php` | Public info page |
| `load/nav.php` | Nav items marked with `hibernated` flag |
| `load/nav_public.php` | Same as nav.php for public pages |
| `assets/css/perti_theme.css` | `.nav-hibernated` CSS class |
| `scripts/startup.sh` | Conditional daemon startup |
| `scripts/vatsim_adl_daemon.php` | ATIS disabled via `HIBERNATION_MODE` |
| `api/data/hibernation_stats.php` | JSON API for hit statistics |
| `database/migrations/hibernation/001_hibernation_hits.sql` | MySQL table for hit tracking |

### Hit Tracking

Every access attempt to a hibernated page or SWIM API endpoint is recorded in the `hibernation_hits` table (MySQL `perti_site`). This provides demand data for paused features.

- **Tracked**: Page redirects (type=`page`) and SWIM API 503s (type=`api`)
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
| **PostGIS** (VATSIM_GIS) | Standard_B2s (Burstable, 2 vCore) | Standard_B1ms (Burstable, 1 vCore, 2GB) | ~$15 |
| **SWIM_API** (Azure SQL) | Basic 5 DTU | Basic 5 DTU (unchanged — already ~$5/mo) | $0 |
| **VATSIM_TMI/REF** (Azure SQL) | Basic 5 DTU | Basic 5 DTU (unchanged) | $0 |
| **VATSIM_STATS** (Azure SQL) | GP_S_Gen5 1 vCore | GP_S_Gen5 (unchanged) | $0 |
| **Synapse** | Serverless | Serverless (pay-per-query) | $0 |
| **Blob Storage** | Active | Active (minimal cost) | $0 |
| **Total estimated savings** | | | **~$1,000-1,150/mo** |

### CLI Commands for Downscaling (Entering Hibernation)

```bash
# VATSIM_ADL: Reduce Hyperscale Serverless vCore range
az sql db update --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
    --min-capacity 1 --capacity 4 --edition Hyperscale --family Gen5 --compute-model Serverless

# MySQL: GeneralPurpose → Burstable
az mysql flexible-server update --name vatcscc-perti --resource-group VATSIM_RG \
    --sku-name Standard_B1ms --tier Burstable

# PostGIS: B2s → B1ms (requires server restart)
az postgres flexible-server update --name vatcscc-gis --resource-group VATSIM_RG \
    --sku-name Standard_B1ms --tier Burstable --yes
```

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

# PostGIS: B1ms → B2s
az postgres flexible-server update --name vatcscc-gis --resource-group VATSIM_RG \
    --sku-name Standard_B2s --tier Burstable --yes
```

**Note**: App Service stays at P1v2 (has 4 deployment slots; B1 doesn't support slots). SWIM_API stays at Basic 5 DTU (already minimal cost).

### 2. Update PHP Config

In `load/config.php`, change:
```php
define("HIBERNATION_MODE", env('HIBERNATION_MODE', true));
```
to:
```php
define("HIBERNATION_MODE", env('HIBERNATION_MODE', false));
```

### 3. Remove Azure App Setting

In Azure Portal: App Service → Configuration → Application settings
Remove or set `HIBERNATION_MODE` to `false`.

### 4. Restart App Service

```bash
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

This triggers `startup.sh` which will start all daemons since `HIBERNATION_MODE` is now off.

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

1. Check `load/config.php` — `HIBERNATION_MODE` must be `false`
2. Check Azure App Setting — must be removed or `false`
3. OPcache may be stale — wait 60s for `revalidate_freq` or restart PHP-FPM

### Daemons not starting

1. Check `startup.sh` logs: `cat /home/LogFiles/startup.log`
2. Verify `HIBERNATION_MODE` env var: `echo $HIBERNATION_MODE` in Kudu SSH
3. Manual daemon start: `nohup php /home/site/wwwroot/scripts/<daemon>.php >> /home/LogFiles/<daemon>.log 2>&1 &`

### SWIM API still returning 503

1. Check `load/hibernation.php` — the SWIM API 503 is triggered by `HIBERNATION_MODE`
2. Verify SWIM_API database is resumed and accessible
3. Verify `swim_sync_daemon.php` is running

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
| SWIM API sync | **NOT running** | Yes, via full sync |

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
