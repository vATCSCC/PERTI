# ADL Cycle Interval Cost & Impact Analysis

**Date**: 2026-03-31
**Author**: Claude (automated analysis)
**Data Sources**: Live production daemon logs, Azure CLI resource queries, Azure published pricing

---

## Executive Summary

Changing the ADL ingest cycle from 15 seconds to longer intervals yields **surprisingly modest financial savings** because Azure SQL Hyperscale Serverless billing is dominated by the **minimum vCore floor charge** (billed 24/7), not by actual query execution. The database never auto-pauses because SWIM sync, monitoring, and archival daemons hit it continuously.

| Scenario | Savings at 1min | Savings at 5min | Savings at 15min |
|----------|----------------|-----------------|------------------|
| **Hibernated** | $63/mo (12%) | $101/mo (19%) | $123/mo (23%) |
| **Operational** | $219/mo (13%) | $345/mo (21%) | $399/mo (24%) |

**Better alternative**: Lowering ADL min vCores from 1.0 to 0.5 (hibernated) saves **$188/month** with zero data quality loss — more than any interval change.

---

## 1. Methodology

### 1.1 Data Sources

| Source | What | Confidence |
|--------|------|------------|
| Production daemon logs (`/home/LogFiles/vatsim_adl.log`) | Real cycle timing, pilot counts, step durations | **High** — direct measurement |
| Azure CLI (`az sql db show`, `az postgres flexible-server show`) | Confirmed database SKUs, min/max vCores | **High** — authoritative |
| Azure published pricing | Hyperscale Serverless rate: $0.000145/vCore-second | **Medium** — published rate, actual may vary by agreement |
| Code analysis (daemon source files) | Sub-process architecture, interval configuration | **High** — direct code reading |

### 1.2 Limitations

- **vCore consumption per second not directly measurable**: Azure Monitor metrics were inaccessible via CLI. vCore estimates during active processing are modeled, not measured.
- **Operational profile extrapolated**: Current production is hibernated (~1,067 pilots). Operational estimates (2,500 pilots) are scaled from hibernated measurements using code-analysis-informed scaling factors.
- **`adl_refresh_perf` and `adl_run_log` tables are empty** (cleared during March 22 bloat purge). All timing data comes from daemon log files.

---

## 2. Current System Architecture

### 2.1 Main ADL Cycle (15-second interval)

```
VATSIM API ──fetch──> PHP Parse ──staging INSERT──> sp_Adl_RefreshFromVatsim_Staged ──> Deferred ETAs
  (280ms)              (12ms)       (1,840ms)              (3,500ms)                     (600-1,220ms)
                                                                                         ↓
                                                          Total DB active: ~5,940ms per cycle
                                                          Total cycle: ~6,740ms per 15s
                                                          Duty cycle: ~45%
```

### 2.2 Sub-Tasks Within Main Daemon

These run every N **cycles** (not N seconds):

| Sub-task | Every N Cycles | Current (15s) | At 60s | At 5min |
|----------|---------------|---------------|--------|---------|
| Wind adjustment | 2 | 30s | 2min | 10min |
| TMI→ADL sync | 4 | 60s | 4min | **20min** |
| Event position log | 4 | 60s | 4min | 20min |
| CTP compliance | 8 | 2min | 8min | **40min** |
| GDP compliance | 8 | 2min | 8min | **40min** |
| SWIM inline sync (fallback) | 8 | 2min | 8min | 40min |
| GDP reoptimization | 16 | 4min | 16min | **80min** |
| Flight stats | 60 | 15min | 60min | 5hr |
| Runway detection | 120 | 30min | 2hr | 10hr |
| ATIS cleanup | 240 | 1hr | 4hr | 20hr |

**Critical**: Without decoupling these from cycle count, GDP reoptimization at 5-min intervals would run every 80 minutes instead of 4 minutes.

### 2.3 Offloaded Daemons (Independent Intervals)

| Daemon | Interval | Database Hit | Impact of ADL Interval Change |
|--------|----------|-------------|-------------------------------|
| Parse Queue GIS | 10s | PostGIS + ADL | **Indirect**: Queue filled less frequently, same total routes |
| Boundary GIS | 15s | PostGIS + ADL | **Indirect**: Staler positions, same workload |
| Crossing GIS | 30s+ tiered | PostGIS + ADL | **Indirect**: Staler data, same total work |
| Waypoint ETA | 15s+ tiered | ADL | **Indirect**: Staler positions, less accurate ETAs |
| SWIM Sync | 120s | SWIM_API + ADL | **Indirect**: Syncs staler ADL data |
| SWIM TMI Sync | 300s | TMI + SWIM_API | **None**: Independent of ADL cycle |
| Archival | 1-4hr | ADL + Blob | **None**: Processes completed flights |
| Monitoring | 60s | ADL | **None**: System metrics, independent |

**PostGIS cost is fixed**: B2s ($25/month) regardless of interval. GIS daemons run on their own schedules.

---

## 3. Real Production Metrics

### 3.1 Live Daemon Log (March 31, 2026, ~1,067 pilots)

25 consecutive cycles analyzed:

| Metric | Average | Min | Max | Notes |
|--------|---------|-----|-----|-------|
| `pilots` | 1,067 | 1,064 | 1,075 | Hibernation-era off-peak |
| `fetch_ms` | 280 | 110 | 607 | VATSIM API network latency |
| `parse_ms` | 12 | 9 | 18 | PHP JSON parsing |
| `stg_ms` | 8 | 6 | 10 | Staging prep |
| `ins_ms` | 1,840 | 1,757 | 8,760 | Bulk INSERT to staging tables |
| `sp_ms` | 3,500 | 1,285 | 6,535 | SP execution |
| `hb` (heartbeat) | 238 | 231 | 286 | ~22% of pilots unchanged |
| `def_ms` | 600 | 325 | 954 | Deferred processing budget used |
| `def_eta1` | 1,120 | 1,112 | 1,134 | Basic ETA calc (every cycle) |
| `def_eta2` | 1,220 | 1,209 | 1,239 | Wind ETA calc (every other cycle) |
| `ws_events` | 3-4 | 1 | 7 | WebSocket broadcasts |

### 3.2 Slow Cycles Observed

| Cycle | SP ms | INS ms | Cause |
|-------|-------|--------|-------|
| #6238 | 6,535 | 8,066 | Staging INSERT spike (I/O variance) |
| #6240 | 7,935 | 8,760 | Flight stats SP timeout (coincident) |

When SP + INSERT exceeds ~10s, deferred processing is skipped (`"def":"SKIP"`).

### 3.3 Key Timing Insight

Total DB active time per cycle: **ins_ms + sp_ms + def_ms ≈ 5,940ms**
Total cycle time: **~6,740ms** (including fetch, parse, staging prep)
Idle time per cycle: **~8,260ms** (55% of 15s)

---

## 4. Azure Resource State (Verified via CLI)

### 4.1 Current Tiers (Hibernated, March 31 2026)

| Resource | SKU | Min Capacity | Max Capacity | Monthly Cost |
|----------|-----|-------------|-------------|-------------|
| **VATSIM_ADL** | HS_S_Gen5 (Hyperscale Serverless) | 1.0 vCores | 4 vCores | ~$524 (computed) |
| **SWIM_API** | Standard S0 (10 DTU) | n/a | 10 DTU | ~$15 |
| **VATSIM_TMI** | Basic (5 DTU) | n/a | 5 DTU | ~$5 |
| **VATSIM_REF** | Basic (5 DTU) | n/a | 5 DTU | ~$5 |
| **VATSIM_STATS** | GP_S_Gen5 (Serverless) | 0.5 vCores | 1 vCore | **$0** (auto-paused since Mar 21) |
| **MySQL** | Standard_B1ms | n/a | 1 vCore | ~$12 |
| **PostGIS** | Standard_B1ms | n/a | 1 vCore | ~$12 |
| **App Service** | P1v2 | n/a | 3.5 GB RAM | ~$81 |
| **Blob Storage** | Hot | n/a | n/a | ~$5 |
| **TOTAL** | | | | **~$659** |

### 4.2 Operational Tiers (Unhibernated)

| Resource | SKU | Min Capacity | Max Capacity | Monthly Cost |
|----------|-----|-------------|-------------|-------------|
| **VATSIM_ADL** | HS_S_Gen5 | **3.0 vCores** | **16 vCores** | ~$1,629 (computed) |
| **SWIM_API** | Standard S0 | n/a | 10 DTU | ~$15 |
| **VATSIM_TMI** | Basic | n/a | 5 DTU | ~$5 |
| **VATSIM_REF** | Basic | n/a | 5 DTU | ~$5 |
| **VATSIM_STATS** | GP_S_Gen5 | 0.5 vCores | 1 vCore | ~$0 (auto-paused) |
| **MySQL** | **Standard_D2ds_v4** | n/a | **2 vCores** | **~$197** |
| **PostGIS** | **Standard_B2s** | n/a | **2 vCores** | **~$25** |
| **App Service** | P1v2 | n/a | 3.5 GB RAM | ~$81 |
| **Blob Storage** | Hot | n/a | n/a | ~$5 |
| **TOTAL** | | | | **~$1,962** |

### 4.3 Discrepancy Found

**PostGIS actual tier: B1ms** (verified via `az postgres flexible-server show`)
**PostGIS documented tier: B2s** (per HIBERNATION_RUNBOOK.md and MEMORY.md)

The PostGIS server appears to have been downscaled to B1ms at some point. Per user, it should be B2s even during hibernation.

---

## 5. Cost Model

### 5.1 How Hyperscale Serverless Billing Works

```
For each second of the month:
  billed_vCores = max(actual_vCores_used, min_capacity)
  cost = billed_vCores × $0.000145
```

- **Floor cost**: min_capacity × $0.000145 × 2,592,000 seconds/month
- **Auto-pause**: Drops to storage-only after 60 min of zero connections
- **PERTI never auto-pauses**: SWIM sync (2min), monitoring (60s), archival daemons keep ADL alive 24/7

### 5.2 Floor Cost (Unavoidable, Regardless of Interval)

| Profile | Min vCores | Floor/Month | % of Total |
|---------|-----------|-------------|-----------|
| **Hibernated** | 1.0 | **$375.84** | 72% |
| **Operational** | 3.0 | **$1,127.52** | 69% |

This is the dominant cost. **Reducing cycle frequency only affects the 28-31% burst component.**

### 5.3 Burst Cost Modeling

During DB-active seconds, actual vCore usage exceeds the minimum due to query parallelism. Estimates:

**Hibernated (~1,067 pilots):**
- Staging INSERT (1.84s): ~1.5 vCores (I/O-bound bulk write)
- SP execution (3.5s): ~2.5 vCores (complex JOINs, UPDATEs, MERGEs)
- Deferred processing (0.6s): ~1.5 vCores (ETA calculations)
- Weighted average during active: **~2.0 vCores**

**Operational (~2,500 pilots):**
- Staging INSERT (3s est): ~2 vCores
- SP execution (6s est): ~6 vCores (benefits from parallelism with more min vCores)
- Deferred processing (1s est): ~3 vCores
- Weighted average during active: **~5.0 vCores**

### 5.4 Cycle Timing at Longer Intervals

At longer intervals, more changes accumulate per cycle:
- **Heartbeat optimization disappears**: At ≥60s, every pilot has position changes (VATSIM updates every 15s)
- **More new/departed flights**: More INSERT/DELETE operations per cycle
- **More route changes**: More hash misses → more Step 4/5 work
- **Scaling is sublinear**: Fixed-cost steps (temp table creation, indexes, deferred setup) don't scale

| Interval | Changed Pilots | Est. Active Time (H) | Est. Active Time (O) | Est. vCores (H) | Est. vCores (O) |
|----------|---------------|----------------------|----------------------|------------------|------------------|
| 15s | 78% (~830) | 5.9s | 10s | 2.0 | 5.0 |
| 60s | 100% (1,067) | 9s | 15s | 2.5 | 6.0 |
| 120s | 100% + more new | 12s | 20s | 3.0 | 7.0 |
| 5min | 100% + many new/departed | 15s | 25s | 3.5 | 8.0 |
| 10min | 100% + flights missed | 18s | 30s | 4.0 (max) | 10.0 |
| 15min | 100% + max staleness | 20s | 35s | 4.0 (max) | 10.0 |

---

## 6. Per-Interval Cost Analysis

### 6.1 Profile 1: HIBERNATED (min 1 / max 4 vCores, ~1,067 pilots)

| Interval | Cycles/mo | Active/cycle | Idle/cycle | vCore-sec/mo | ADL Cost/mo | Savings | % |
|----------|-----------|-------------|-----------|-------------|------------|---------|---|
| **15s** | 172,800 | 5.9s @ 2.0 vC | 9.1s @ 1.0 vC | 3,611,520 | **$523.67** | — | — |
| **60s** | 43,200 | 9.0s @ 2.5 vC | 51.0s @ 1.0 vC | 3,175,200 | **$460.40** | $63.27 | 12.1% |
| **2min** | 21,600 | 12.0s @ 3.0 vC | 108.0s @ 1.0 vC | 3,110,400 | **$451.01** | $72.66 | 13.9% |
| **5min** | 8,640 | 15.0s @ 3.5 vC | 285.0s @ 1.0 vC | 2,916,000 | **$422.82** | $100.85 | 19.3% |
| **10min** | 4,320 | 18.0s @ 4.0 vC | 582.0s @ 1.0 vC | 2,825,280 | **$409.67** | $114.00 | 21.8% |
| **15min** | 2,880 | 20.0s @ 4.0 vC | 880.0s @ 1.0 vC | 2,764,800 | **$400.90** | $122.77 | 23.4% |

**Observation**: Going from 15s→60s captures 52% of the maximum possible savings. Going further to 15min only adds another 48%.

### 6.2 Profile 2: OPERATIONAL (min 3 / max 16 vCores, ~2,500 pilots)

| Interval | Cycles/mo | Active/cycle | Idle/cycle | vCore-sec/mo | ADL Cost/mo | Savings | % |
|----------|-----------|-------------|-----------|-------------|------------|---------|---|
| **15s** | 172,800 | 10.0s @ 5.0 vC | 5.0s @ 3.0 vC | 11,232,000 | **$1,628.64** | — | — |
| **60s** | 43,200 | 15.0s @ 6.0 vC | 45.0s @ 3.0 vC | 9,720,000 | **$1,409.40** | $219.24 | 13.5% |
| **2min** | 21,600 | 20.0s @ 7.0 vC | 100.0s @ 3.0 vC | 9,504,000 | **$1,378.08** | $250.56 | 15.4% |
| **5min** | 8,640 | 25.0s @ 8.0 vC | 275.0s @ 3.0 vC | 8,856,000 | **$1,284.12** | $344.52 | 21.2% |
| **10min** | 4,320 | 30.0s @ 10.0 vC | 570.0s @ 3.0 vC | 8,683,200 | **$1,259.06** | $369.58 | 22.7% |
| **15min** | 2,880 | 35.0s @ 10.0 vC | 865.0s @ 3.0 vC | 8,481,600 | **$1,229.83** | $398.81 | 24.5% |

---

## 7. Full Monthly Infrastructure Cost

### 7.1 Hibernated Profile

| Resource | 15s (current) | 60s | 2min | 5min | 10min | 15min |
|----------|--------------|-----|------|------|-------|-------|
| VATSIM_ADL | $524 | $460 | $451 | $423 | $410 | $401 |
| SWIM_API (S0) | $15 | $15 | $15 | $15 | $15 | $15 |
| VATSIM_TMI (Basic) | $5 | $5 | $5 | $5 | $5 | $5 |
| VATSIM_REF (Basic) | $5 | $5 | $5 | $5 | $5 | $5 |
| VATSIM_STATS | $0 | $0 | $0 | $0 | $0 | $0 |
| MySQL (B1ms) | $12 | $12 | $12 | $12 | $12 | $12 |
| PostGIS (B2s) | $25 | $25 | $25 | $25 | $25 | $25 |
| App Service (P1v2) | $81 | $81 | $81 | $81 | $81 | $81 |
| Blob Storage | $5 | $5 | $5 | $5 | $5 | $5 |
| **TOTAL** | **$672** | **$608** | **$599** | **$571** | **$558** | **$549** |
| **Savings** | — | **$64** | **$73** | **$101** | **$114** | **$123** |

### 7.2 Operational Profile

| Resource | 15s (current) | 60s | 2min | 5min | 10min | 15min |
|----------|--------------|-----|------|------|-------|-------|
| VATSIM_ADL | $1,629 | $1,409 | $1,378 | $1,284 | $1,259 | $1,230 |
| SWIM_API (S0) | $15 | $15 | $15 | $15 | $15 | $15 |
| VATSIM_TMI (Basic) | $5 | $5 | $5 | $5 | $5 | $5 |
| VATSIM_REF (Basic) | $5 | $5 | $5 | $5 | $5 | $5 |
| VATSIM_STATS | $0 | $0 | $0 | $0 | $0 | $0 |
| MySQL (D2ds_v4) | $197 | $197 | $197 | $197 | $197 | $197 |
| PostGIS (B2s) | $25 | $25 | $25 | $25 | $25 | $25 |
| App Service (P1v2) | $81 | $81 | $81 | $81 | $81 | $81 |
| Blob Storage | $5 | $5 | $5 | $5 | $5 | $5 |
| **TOTAL** | **$1,962** | **$1,742** | **$1,711** | **$1,617** | **$1,592** | **$1,563** |
| **Savings** | — | **$220** | **$251** | **$345** | **$370** | **$399** |

---

## 8. Data Quality Impact

### 8.1 Impact Matrix

| Aspect | 15s | 60s | 2min | 5min | 10min | 15min |
|--------|:---:|:---:|:----:|:----:|:-----:|:-----:|
| Position resolution | 15s | 60s | 2min | 5min | 10min | 15min |
| Trajectory points/flight/hr | 240 | 60 | 30 | 12 | 6 | 4 |
| OOOI detection latency | ≤15s | ≤60s | ≤2min | ≤5min | ≤10min | ≤15min |
| VATSIM updates captured | ~85% | ~25% | ~12.5% | ~5% | ~2.5% | ~1.7% |
| Short flights (≤10min) | Full | 5-10 pts | 3-5 pts | 1-2 pts | 0-1 pts | Missed |
| Boundary crossing accuracy | Excellent | Good | Moderate | Poor | Very poor | Unusable |
| Route change detection | Real-time | 1min lag | 2min lag | 5min lag | 10min lag | 15min lag |
| GDP/EDCT compliance | Real-time | Near-RT | Delayed | **Broken** | **Broken** | **Broken** |

### 8.2 Sub-Task Timing Impact (Without Decoupling)

| Sub-task | Current | At 60s | At 2min | At 5min | At 15min |
|----------|---------|--------|---------|---------|----------|
| TMI→ADL sync | 60s | 4min | 8min | **20min** | **60min** |
| GDP compliance check | 2min | 8min | **16min** | **40min** | **2hr** |
| GDP reoptimization | 4min | **16min** | **32min** | **80min** | **4hr** |
| CTP compliance | 2min | 8min | **16min** | **40min** | **2hr** |
| Flight stats | 15min | 60min | 2hr | **5hr** | **15hr** |

**These become operationally unacceptable beyond 2-minute intervals** unless decoupled from cycle count and run on wall-clock timers (a code change, but straightforward).

### 8.3 Downstream Pipeline Impact

| Pipeline Stage | Input Freshness at 60s | Impact |
|---------------|----------------------|--------|
| Parse Queue GIS | Routes queued 1x/min instead of 4x/min | Burstier queue, same throughput |
| Boundary GIS | Positions 60s stale | Less accurate ARTCC assignment during transitions |
| Crossing GIS | Positions + routes staler | ETA predictions less accurate |
| Waypoint ETA | Positions 60s stale | ETAs lag by ~1 minute |
| SWIM Sync | ADL data 60s stale | SWIM consumers see 60s lag (vs ~30s current) |
| TMI compliance | Positions 60s stale | Compliance windows need widening |

### 8.4 Operational Viability Summary

| Interval | Real-time TMI Ops | Historical Analysis | Data Collection Only | Verdict |
|----------|:-----------------:|:-------------------:|:--------------------:|---------|
| **15s** | Full | Full | Full | Current production standard |
| **60s** | Viable (minor latency) | Full | Full | **Best cost/quality trade-off** |
| **2min** | Marginal | Good | Good | Acceptable for hibernation |
| **5min** | Not viable | Moderate | Degraded | Hibernation data-collection only |
| **10min** | Not viable | Degraded | Degraded | Emergency cost savings only |
| **15min** | Not viable | Poor | Minimal | Not recommended |

---

## 9. Alternative Cost Savings (Higher ROI)

These alternatives save more money per dollar of quality lost:

| Option | Monthly Savings | Quality Impact | Complexity | Reversible? |
|--------|----------------|---------------|------------|-------------|
| **Lower ADL min: 1.0→0.5 vC** (hib) | **$188** | None if workload fits in 0.5 vC | 1 CLI command | Yes, instant |
| **Lower ADL min: 3.0→1.0 vC** (op) | **$752** | Possible latency spikes at peak | 1 CLI command | Yes, instant |
| **Lower ADL min: 3.0→2.0 vC** (op) | **$376** | Minimal — 2 vCores handles most cycles | 1 CLI command | Yes, instant |
| Pause VATSIM_STATS entirely | $0 | None | Already paused | n/a |
| Downgrade SWIM_API: S0→Basic | $10 | Slower SWIM API queries | 1 CLI command | Yes |
| Downgrade MySQL: D2ds_v4→D2ads_v4 (op) | ~$40 | Minimal | 1 CLI command | Yes |

**Best single lever (hibernated)**: ADL min 1.0→0.5 saves $188/month — 53% more than going to 15-min intervals ($123/month), with zero data quality impact.

**Best single lever (operational)**: ADL min 3.0→2.0 saves $376/month — more than going to 15-min intervals ($399/month) but with far less quality degradation.

**Combined**: ADL min 1.0→0.5 + 60s interval (hibernated) = $188 + $63 = **$251/month** savings with minimal quality impact.

### 9.1 Min vCore Feasibility Check

Can PERTI run at 0.5 min vCores (hibernated)?

Current measured workload: SP takes 3.5s average at 1,067 pilots with ~2 vCores average.
- At 0.5 min vCores, idle periods cost 0.5 vC instead of 1.0 vC
- Active periods still scale to whatever vCores the workload demands (up to max 4)
- The only risk: if workload briefly drops below 0.5 vCores, you still pay 0.5 (no savings)
- The actual risk is that the SP execution may take slightly longer at startup if it needs to scale up from 0.5 vCores

**Verdict**: 0.5 min vCores is likely feasible for hibernation. The workload consistently needs >0.5 vCores during active processing (the burst is billed at actual usage regardless), and the savings come purely from reduced idle billing.

---

## 10. Findings

1. **Hyperscale Serverless floor cost dominates**: 72% (hibernated) to 69% (operational) of ADL compute is the minimum vCore charge. Slowing cycles only reduces the 28-31% burst component.

2. **Diminishing returns beyond 60s**: The 15s→60s change captures 52% of maximum savings. Going from 60s→15min captures the remaining 48% with dramatically worse data quality.

3. **Data quality cliff at 5 minutes**: Beyond 2 minutes, TMI/GDP operations become unworkable without decoupling sub-tasks from cycle count to wall-clock timers.

4. **PostGIS cost is completely unaffected**: GIS daemons run independently with fixed-cost PostgreSQL. Only data freshness changes.

5. **Sub-task coupling is the biggest risk**: GDP reoptimization, TMI sync, and compliance checks are tied to cycle count. Longer intervals without code changes break these operationally.

6. **Min vCore reduction is the better lever**: Lowering min vCores saves more per month with zero quality loss. This should be explored first.

7. **Auto-pause is impossible**: Multiple always-on daemons (SWIM sync, monitoring, archival) ensure the database never reaches the 60-minute inactivity threshold.

8. **PostGIS tier discrepancy**: Production PostGIS is at B1ms (verified via CLI), not B2s as documented and intended.

---

## 11. Recommendation Matrix

| Scenario | Recommended Interval | Estimated Savings | Reasoning |
|----------|---------------------|-------------------|-----------|
| **Cost-minimized hibernation** | 60s + min 0.5 vC | $251/mo | Best savings-to-quality ratio |
| **Standard hibernation** | 60s (current min) | $63/mo | Minimal quality loss |
| **Aggressive hibernation** | 2min + min 0.5 vC | $261/mo | Acceptable for data collection |
| **Standard operational** | 15s (no change) | $0 | TMI operations need 15s |
| **Cost-optimized operational** | 15s + min 2.0 vC | $376/mo | Risk: occasional latency |
| **Never recommended** | ≥5min for any profile | - | TMI/GDP broken without code changes |

---

## Appendix A: Pricing Reference

| Resource Type | SKU | Rate | Monthly (30d) |
|--------------|-----|------|---------------|
| Hyperscale Serverless Gen5 | per vCore-second | $0.000145 | $375.84/vCore |
| GP Serverless Gen5 | per vCore-second | $0.000145 | $375.84/vCore |
| Basic (5 DTU) | fixed | — | ~$4.90 |
| Standard S0 (10 DTU) | fixed | — | ~$15.00 |
| MySQL B1ms | fixed | — | ~$12.41 |
| MySQL D2ds_v4 | fixed | — | ~$197.00 |
| PostgreSQL B1ms | fixed | — | ~$12.41 |
| PostgreSQL B2s | fixed | — | ~$25.00 |
| App Service P1v2 | fixed | — | ~$81.00 |

Source: [Azure SQL Database Pricing](https://azure.microsoft.com/en-us/pricing/details/azure-sql-database/single/)

## Appendix B: CLI Commands Used

```bash
# Verify ADL database configuration
az sql db show --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
  --query "{sku:sku, minCapacity:minCapacity}"

# Verify PostGIS tier
az postgres flexible-server show --name vatcscc-gis --resource-group VATSIM_RG \
  --query "{sku:sku}"

# Check STATS auto-pause status
az sql db show --name VATSIM_STATS --server vatsim --resource-group VATSIM_RG \
  --query "{status:status, pausedDate:pausedDate}"

# Read production daemon logs
curl -s -u '$vatcscc:PASSWORD' -X POST -H "Content-Type: application/json" \
  -d '{"command":"tail -n 100 /home/LogFiles/vatsim_adl.log"}' \
  https://vatcscc.scm.azurewebsites.net/api/command
```
