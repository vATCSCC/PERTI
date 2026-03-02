# PERTI Hibernation Mode Runbook

## Overview

Hibernation mode is an open-ended operational pause that reduces PERTI to core data collection only. Most downstream flight processing, the SWIM API, and several UI pages are suspended. Azure resources are downscaled to match the reduced workload.

**Status**: Active (since March 2026)
**Timeframe**: Until further notice

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
| `swim_ws_server.php` | SWIM WebSocket server |
| `swim_sync_daemon.php` | ADL-to-SWIM sync |
| `simtraffic_swim_poll.php` | SimTraffic polling |
| `swim_adl_reverse_sync_daemon.php` | SWIM-to-ADL reverse sync |
| `scheduler_daemon.php` | Splits/routes auto-activation |
| `event_sync_daemon.php` | VATUSA/VATCAN event sync |

### ADL Daemon Features Disabled

- ATIS parsing (controlled by `atis_enabled` config, auto-disabled when `HIBERNATION_MODE=true`)

### Web Pages (Redirect to /hibernation)

`demand.php`, `nod.php`, `review.php`, `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`, `simulator.php`, `gdt.php`, `sua.php`, `event-aar.php`

### SWIM API

All `api/swim/v1/` endpoints return HTTP 503 JSON:
```json
{
  "error": "Service Temporarily Unavailable",
  "message": "VATSWIM API is currently in hibernation mode.",
  "status": 503,
  "info": "https://perti.vatcscc.org/hibernation"
}
```

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
