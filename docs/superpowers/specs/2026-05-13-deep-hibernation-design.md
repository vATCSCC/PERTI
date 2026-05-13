# Deep Hibernation Mode — Design Specification

**Date**: 2026-05-13
**Status**: Draft
**Author**: Claude (AI-assisted design)

---

## 1. Overview

Deep hibernation is a cost-reduction mode that goes beyond the existing hibernation (Level 1) by suspending **all** flight data processing — including the ADL ingest pipeline and SWIM API. Instead of processing VATSIM data into SQL tables, the system captures raw JSON files and archives them to Azure Blob Storage for post-processing replay when deep hibernation ends.

### Mode Hierarchy

| Level | Name | ADL Ingest | SWIM API | Flight Processing | Pages |
|-------|------|-----------|----------|-------------------|-------|
| 0 | Operational | Full pipeline | Operational | All daemons | All |
| 1 | Hibernation | Full pipeline (ATIS/TMI/CTP/GDP features disabled) | Operational | GIS daemons stopped | 7 pages redirect |
| 2 | **Deep Hibernation** | **Raw JSON capture only** | **503** | **All stopped** | 11 pages redirect |

### Goals

1. Reduce Azure costs beyond Level 1 by stopping all SQL-heavy daemons
2. Preserve raw VATSIM data for lossless post-processing replay
3. Maintain full functionality for planning, review, and TMI publishing pages
4. Provide a clear replay procedure to restore all data after exiting

---

## 2. Configuration

### New Constant

In `load/config.php`:

```php
define("DEEP_HIBERNATION_MODE", env('DEEP_HIBERNATION_MODE', false));
```

When `DEEP_HIBERNATION_MODE` is true, `HIBERNATION_MODE` must also be true (deep hibernation is a superset of hibernation). This is enforced in `config.php` immediately after defining both constants:

```php
define("DEEP_HIBERNATION_MODE", env('DEEP_HIBERNATION_MODE', false));

// Deep hibernation implies hibernation
if (DEEP_HIBERNATION_MODE && !HIBERNATION_MODE) {
    // This shouldn't happen in practice (both App Settings should be set),
    // but enforce the invariant to prevent inconsistent state.
    // Note: can't redefine a constant, so this triggers an error log.
    error_log("WARNING: DEEP_HIBERNATION_MODE=1 but HIBERNATION_MODE=0. Both must be set.");
}
```

Code that needs to distinguish levels should check deep first:

```php
if (DEEP_HIBERNATION_MODE) {
    // Deep hibernation behavior
} elseif (HIBERNATION_MODE) {
    // Level 1 hibernation behavior
}
```

Code that applies to both levels can just check `HIBERNATION_MODE` (true in both Level 1 and Level 2).

### Azure App Setting

- Setting: `DEEP_HIBERNATION_MODE=1` (use `1`/`0`, not `true`/`false`)
- Same PHP string-truthiness caveat as `HIBERNATION_MODE`

### Files Modified

| File | Change |
|------|--------|
| `load/config.php` | Add `DEEP_HIBERNATION_MODE` constant |
| `load/hibernation.php` | Extend redirect list and add SWIM API 503 for deep hibernation |
| `scripts/startup.sh` | Add deep hibernation conditional — start only capture daemon + monitoring |
| `load/nav.php` | Mark SWIM nav items as hibernated when `DEEP_HIBERNATION_MODE` |
| `load/nav_public.php` | Same as nav.php |
| `docs/operations/HIBERNATION_RUNBOOK.md` | Add Deep Hibernation section |

### New Files

| File | Purpose |
|------|---------|
| `scripts/deep_hibernation_daemon.php` | VATSIM JSON capture + blob archival |
| `scripts/deep_hibernation_replay.php` | Post-hibernation data replay |

---

## 3. Daemons

### Running in Deep Hibernation

| Daemon | Script | Interval | Purpose |
|--------|--------|----------|---------|
| **Deep Hibernation Capture** | `scripts/deep_hibernation_daemon.php` | 15s fetch, 10min upload | Fetch VATSIM JSON, gzip, batch-upload to blob |
| **Monitoring** | `scripts/monitoring_daemon.php` | 60s | System health metrics |

### Stopped in Deep Hibernation

All other daemons are stopped, including those that run in Level 1 hibernation:

| Daemon | Level 1 Status | Deep Status | Rationale |
|--------|---------------|-------------|-----------|
| `vatsim_adl_daemon.php` | Running | **Stopped** | No SQL processing — replaced by capture daemon |
| `swim_ws_server.php` | Running | **Stopped** | SWIM suspended |
| `swim_sync_daemon.php` | Running | **Stopped** | SWIM suspended |
| `swim_tmi_sync_daemon.php` | Running | **Stopped** | SWIM suspended |
| `simtraffic_swim_poll.php` | Running | **Stopped** | SWIM suspended |
| `swim_adl_reverse_sync_daemon.php` | Running | **Stopped** | SWIM suspended |
| `archival_daemon.php` | Running | **Stopped** | No new data to archive |
| `adl_archive_daemon.php` | Running | **Stopped** | No new trajectories |
| `process_discord_queue.php` | Running | **Stopped** | TMI Discord posting paused |
| `ecfmp_poll_daemon.php` | Running | **Stopped** | No flow measure processing |
| `viff_cdm_poll_daemon.php` | Running (conditional) | **Stopped** | CDM suspended |
| `export_playbook.php` | Running | **Stopped** | No operational changes to back up |
| `refdata_sync_daemon.php` | Running | **Stopped** | Writes to VATSIM_REF + SWIM_API (pointless with SWIM 503); MySQL playbook data is static |
| All GIS daemons | Stopped | Stopped | Already stopped in Level 1 |
| All other conditional daemons | Stopped | Stopped | Already stopped in Level 1 |

**Net result**: 2 daemons running, down from 14+ in Level 1.

---

## 4. Page Accessibility

### Fully Functional

These pages and all their API endpoints work with only MySQL (`perti_site`) or no database at all.

| Page | DB Dependency | Verified |
|------|--------------|----------|
| `index.php` | MySQL (`PERTI_MYSQL_ONLY`) | `index.php:12` |
| `plan.php` | MySQL (`PERTI_MYSQL_ONLY`) | `plan.php:12` |
| `sheet.php` | MySQL (`PERTI_MYSQL_ONLY`) | `sheet.php:12` |
| `schedule.php` | MySQL (`PERTI_MYSQL_ONLY`) | `schedule.php:12` |
| `review.php` | MySQL (`PERTI_MYSQL_ONLY`) | `review.php:12` |
| `data.php` | MySQL (`PERTI_MYSQL_ONLY`) | `data.php:12` |
| `jatoc.php` | None (static JS config) | No `connect.php` include |
| `navdata.php` | None (static JSON from `assets/data/logs/`) | No `connect.php` include |
| `hibernation.php` | MySQL (hit tracking via standalone PDO) | `hibernation.php` |
| `login/` | MySQL (sessions + users) | OAuth + perti_site |

### Functional with Degraded Features

These pages render and core features work. Azure SQL (VATSIM_ADL at min 1 / max 4 vCores, auto-pause **disabled**, always running) responds immediately. Some JS features that need live flight data or PostGIS return empty/error gracefully.

| Page | What Works | What Degrades |
|------|-----------|---------------|
| `route.php` | Map rendering, static CSV data (points, navaids, CDRs, playbook routes from `assets/data/`), route string parsing, saved route shares | Live traffic overlay (`api/adl/current.php` — no active flights), SUA display (`api/data/sua` — reference data loads but no live SUA), route expansion via PostGIS (`api/gis/boundaries`), TMI reroute GeoJSON |
| `playbook.php` | List/create/edit/delete plays (MySQL), categories, groups, ACL, changelog, NAT tracks | Route analysis (`api/data/playbook/analysis.php` — needs PostGIS), throughput data (`api/swim/v1/playbook/throughput` — SWIM 503), route geometry enrichment in `api/data/playbook/get.php` (PostGIS) |
| `airport_config.php` | PHP page renders (`PERTI_MYSQL_ONLY`) | **All config CRUD** uses `$conn_adl`: listing (`api/data/configs.php`), create/update/delete (`api/mgt/config_data/*`). Works normally since VATSIM_ADL is always running. |
| `tmi-publish.php` | Publishing NTML entries/advisories (own PDO to VATSIM_TMI via `TMI_SQL_HOST`), cancel, coordinate, staged/promote workflow | Active TMI list (`api/mgt/tmi/active.php` — creates own PDO to both VATSIM_TMI and VATSIM_ADL), airport CONFIG presets (`api/mgt/tmi/airport_configs.php` — uses `$conn_adl`) |
| `splits.php` | All CRUD — sector boundary configs, presets, areas, TRACONs (all use `$conn_adl` reference data) | No degradation — VATSIM_ADL always running |

**Note**: VATSIM_ADL stays at min 1 / max 4 vCores with auto-pause **disabled** (always running). No cold start delays. Pages that use Azure SQL reference data work with normal latency.

### Redirected to /hibernation

Pages already redirected in Level 1 (no change):

- `demand.php`, `nod.php`, `simulator.php`, `gdt.php`, `cdm.php`, `sua.php`, `event-aar.php`

Pages **newly** redirected in deep hibernation:

- `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`

### SWIM API (503)

All `api/swim/v1/*` endpoints return HTTP 503 with JSON body:

```json
{
    "error": "Service suspended",
    "mode": "deep_hibernation",
    "message": "SWIM API is suspended during deep hibernation. Data is being archived for post-processing."
}
```

This is enforced in `load/hibernation.php` by checking `DEEP_HIBERNATION_MODE` and matching the request URI against `api/swim/`.

### Non-Existent Page

`routes.php` was listed in the original requirements but **does not exist** as a top-level page. Only `route.php` (singular) exists. API-level route endpoints (`api/data/routes.php`, etc.) continue to work as dependencies of other pages.

---

## 5. Raw JSON Capture Daemon

### Architecture

**File**: `scripts/deep_hibernation_daemon.php`

Standalone PHP daemon (not a mode of the existing ADL daemon). Single responsibility: fetch, compress, buffer, upload.

### Fetch Cycle (every 15 seconds)

1. HTTP GET `https://data.vatsim.net/v3/vatsim-data.json`
2. Validate response (non-null, >1KB, valid JSON structure with `pilots` key)
3. Gzip compress the raw JSON
4. Write to local buffer: `/home/site/data/deep-hibernation-buffer/vatsim-data-YYYYMMDD-HHMMSS.json.gz`

### Upload Cycle (every 10 minutes)

1. List all `.json.gz` files in the local buffer directory
2. Upload each to Azure Blob Storage via REST API (SAS token auth)
3. Delete local file after successful upload
4. Log upload count, total bytes, and any failures

### Crash Recovery

On daemon startup:
1. Check `/home/site/data/deep-hibernation-buffer/` for existing files
2. Upload any found files before entering the main loop
3. `/home/` is an Azure Storage file share — persists across App Service restarts

### Blob Storage Layout

- **Container**: `adl-raw-archive` (existing)
- **Path**: `datafeed/YYYY/MM/DD/HH/vatsim-data-YYYYMMDD-HHMMSS.json.gz`
- **Naming**: UTC timestamps ensure lexicographic order = chronological order

### Lifecycle Rule

Add a new rule to the existing `adl-raw-archive` container lifecycle policy:

```json
{
    "name": "datafeed-tiering",
    "enabled": true,
    "type": "Lifecycle",
    "definition": {
        "filters": {
            "blobTypes": ["blockBlob"],
            "prefixMatch": ["datafeed/"]
        },
        "actions": {
            "baseBlob": {
                "tierToCool": {
                    "daysAfterModificationGreaterThan": 8
                }
            }
        }
    }
}
```

No auto-delete — files persist until explicitly purged after replay.

### Estimated Data Volume

- Raw JSON per fetch: ~1-2 MB uncompressed, ~200-300 KB gzipped
- Daily: 5,760 files, ~1.4 GB gzipped
- Monthly: ~43 GB gzipped

### Estimated Cost

Based on [Microsoft's sample LRS pricing](https://learn.microsoft.com/en-us/azure/storage/blobs/blob-storage-estimate-costs) (Block Blob, Hot tier):

| Component | Calculation | Monthly Cost |
|-----------|-------------|-------------|
| Write operations | 4,320/month (144/day batched at 10min) / 10,000 * $0.055 | $0.024 |
| Storage (Hot, first 50TB) | 43 GB * $0.0208/GB | $0.90 |
| **Total** | | **~$0.92/month** |

After 8 days, lifecycle policy moves to Cool tier ($0.0115/GB), reducing ongoing storage cost for older data.

### Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `DEEP_HIB_FETCH_INTERVAL` | `15` | Seconds between VATSIM API fetches |
| `DEEP_HIB_UPLOAD_INTERVAL` | `600` | Seconds between blob upload batches |
| `DEEP_HIB_BUFFER_PATH` | `/home/site/data/deep-hibernation-buffer` | Local buffer directory |
| `ADL_ARCHIVE_STORAGE_CONN` | (existing) | Azure Blob Storage connection string |

---

## 6. Post-Processing Replay

### Architecture

**File**: `scripts/deep_hibernation_replay.php`

CLI script that reads archived JSON files from blob storage in chronological order and feeds each through the normal ADL ingest pipeline.

### Replay Flow

```
1. Pre-flight checks (DB connections, archive inventory)
2. Safety: extend archive grace period to 48 hours
3. Safety: verify archival daemon is stopped
4. Download archived JSON files in chronological order
5. For each file:
   a. Decompress gzip
   b. Feed through ADL ingest pipeline (staging tables -> SP -> normalized tables)
   c. Log progress (file count, elapsed time, flights processed)
6. After replay: run hibernation_recovery.php phases 1-5 (GIS backfill)
7. Reset archive grace period to 2 hours
8. Restart archival daemon
```

### Replay Rate

Configurable via `--delay-ms`:
- `--delay-ms=0` (default): Process as fast as possible. SP execution is the bottleneck (~2-5s per cycle). A full day of data (5,760 files) replays in ~3-8 hours.
- `--delay-ms=15000`: Real-time replay (15s between cycles). Useful for testing.

### Archive Safety (Belt and Suspenders)

The existing `sp_Archive_CompletedFlights` CASCADE-deletes flight data from core tables 2 hours after completion. During replay, flights get ingested and could be deleted before GIS enrichment catches up.

Two protections applied simultaneously:

1. **Extended grace period**: Set `COMPLETED_FLIGHT_DELAY_HOURS` to 48 in `adl_archive_config` before replay starts (same mechanism as `hibernation_recovery.php --delay-hours=48`)
2. **Archival daemon stopped**: Verify `archival_daemon.php` is not running. If it is, refuse to start replay.

After replay + GIS backfill completes:
1. Reset `COMPLETED_FLIGHT_DELAY_HOURS` to 2
2. Restart archival daemon

### CLI Options

```
--start-date=YYYY-MM-DD   Start date for replay (default: earliest archived)
--end-date=YYYY-MM-DD     End date (default: latest archived)
--delay-ms=N              Delay between cycles (default: 0)
--dry-run                 List files and counts without processing
--batch=N                 Process N files then pause for confirmation
--skip-safety             Skip archive grace period extension (not recommended)
--verbose                 Detailed per-cycle logging
```

### Post-Replay GIS Backfill

After replay completes, run the existing hibernation recovery phases:

```bash
php scripts/backfill/hibernation_recovery.php --phase=0              # Diagnostic
php scripts/backfill/hibernation_recovery.php --phase=1 --include-inactive  # Route parsing
# Wait for parse_queue_gis_daemon to drain
php scripts/backfill/hibernation_recovery.php --phase=2 --include-inactive  # Boundary detection
php scripts/backfill/hibernation_recovery.php --phase=3 --include-inactive  # Crossing predictions
php scripts/backfill/hibernation_recovery.php --phase=4              # Waypoint ETAs
php scripts/backfill/hibernation_recovery.php --phase=5              # SWIM resync
```

---

## 7. Azure Resource Changes

### Entering Deep Hibernation (from Level 1)

| Resource | Level 1 Tier | Deep Hibernation Tier | Monthly Savings |
|----------|-------------|----------------------|-----------------|
| **App Service** | P1v2 | P1v2 (unchanged) | $0 |
| **VATSIM_ADL** | HS_S min 1 / max 4 | HS_S min 1 / max 4 (unchanged, auto-pause off) | $0 — same tier as Level 1 |
| **MySQL** | B1ms (Burstable) | B1ms (unchanged) | $0 |
| **PostGIS** | B2s | B2s (unchanged) or pause | ~$30/month if paused |
| **SWIM_API** | Basic 5 DTU | Basic 5 DTU (unchanged) or pause | ~$5/month if paused |
| **VATSIM_TMI** | Basic 5 DTU | Basic 5 DTU (unchanged) | $0 — needed for tmi-publish |
| **VATSIM_REF** | Basic 5 DTU | Basic 5 DTU (unchanged) or pause | ~$5/month if paused |

**Primary savings**: Stopping 12+ daemons reduces App Service CPU/memory pressure. VATSIM_ADL stays at same tier (min 1 / max 4) but with no daemons writing to it, serverless auto-scaling will stay near min vCores. Optional pausing of PostGIS/SWIM_API/REF saves ~$40/month additional.

### CLI Commands: Enter Deep Hibernation

```bash
# 1. Set Azure App Settings
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings DEEP_HIBERNATION_MODE=1 HIBERNATION_MODE=1

# 2. VATSIM_ADL: no change needed (stays at min 1 / max 4, auto-pause off)

# 3. (Optional) Pause PostGIS if degraded playbook/route analysis is acceptable
az postgres flexible-server stop --name vatcscc-gis --resource-group VATSIM_RG

# 4. (Optional) Pause SWIM_API and VATSIM_REF
# Note: Basic DTU databases cannot be paused via CLI. Consider scaling to
# Free tier or leaving at Basic ($5/month each).

# 5. Restart App Service (wait for DB changes to propagate)
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

### CLI Commands: Exit Deep Hibernation

```bash
# 1. Upscale VATSIM_ADL back to operational tier
az sql db update --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
    --min-capacity 3 --capacity 16

# 2. (If paused) Restart PostGIS
az postgres flexible-server start --name vatcscc-gis --resource-group VATSIM_RG

# 3. Update App Settings
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings DEEP_HIBERNATION_MODE=0 HIBERNATION_MODE=0

# 4. Update load/config.php defaults (commit + deploy)
# DEEP_HIBERNATION_MODE default -> false
# HIBERNATION_MODE default -> false

# 5. Restart App Service
az webapp restart --name vatcscc --resource-group VATSIM_RG

# 6. Run replay (see Section 6)
php scripts/deep_hibernation_replay.php --verbose

# 7. Run GIS backfill (see Section 6)
php scripts/backfill/hibernation_recovery.php --phase=all --include-inactive

# 8. Verify (see checklist below)
```

### Exit Verification Checklist

- [ ] All daemons running: `ps aux | grep php` on Kudu SSH
- [ ] ADL ingest working: `tail /home/LogFiles/vatsim_adl.log`
- [ ] GIS daemons running: check parse/boundary/crossing logs
- [ ] SWIM sync running: `tail /home/LogFiles/swim_sync.log`
- [ ] SWIM API responding: `curl https://perti.vatcscc.org/api/swim/v1/health`
- [ ] Hibernated pages accessible: visit `/demand`, `/gdt`, `/nod`, `/swim`
- [ ] Nav items no longer muted/snowflaked
- [ ] Replay completed: check replay log for final count
- [ ] GIS backfill completed: check `hibernation_recovery.php --phase=0` diagnostic
- [ ] Archive grace period reset to 2 hours

---

## 8. Hibernation Page Updates

The `/hibernation` info page should display the current mode level and additional context for deep hibernation:

- **Level 1**: Current behavior (shows redirected page stats, "data collection continues")
- **Level 2**: Show "Deep hibernation active — raw data is being archived. No live flight data available. Planning and TMI tools remain operational."

The `api/data/hibernation_stats.php` endpoint works as-is (MySQL-only). Hit tracking continues for all redirected pages including the newly redirected SWIM pages.

---

## 9. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Blob upload failure (network/auth) | Low | Buffered files accumulate on `/home/` | Local buffer persists across restarts; daemon retries on next upload cycle; `/home/` has ~250GB capacity (~170 days of buffer) |
| VATSIM API format change during deep hibernation | Low | Archived JSON may not replay correctly | Replay script validates JSON structure before processing; can skip malformed files |
| Replay SP timeout with large backlog | Medium | Replay stalls | Configurable `--delay-ms` allows throttling; SP has 120s timeout; daemon logs per-cycle timing |
| Archive SP deletes data during replay | High (if not mitigated) | Flight data lost before GIS enrichment | Belt-and-suspenders: 48h grace period + archival daemon stopped |
| VATSIM_ADL at min 1 vCore too slow for reference queries | Low | Slower query response | Acceptable — only reference data reads, no heavy ingest workload competing for resources |
| PostGIS paused but user tries route analysis | Low | API returns error | Graceful degradation — playbook/route.php catch GIS connection failures |

---

## 10. Out of Scope

- Automatic switching between hibernation levels based on traffic (manual only)
- Streaming replay (replaying data while still in deep hibernation)
- Partial replay (replaying only specific airports or flights)
- Changes to the ADL ingest stored procedure
- Changes to the existing Level 1 hibernation behavior
- `routes.php` — does not exist as a top-level page (only `route.php` exists)
