# Azure Resource Utilization Audit — April 25, 2026

**Window analyzed**: 09:00Z – 21:00Z on April 23, 24, 25
**Data source**: Azure Monitor metrics via `az monitor metrics list` (PT1H granularity)
**Audit date**: 2026-04-25
**Raw data**: `C:\Temp\azure_audit_results.json`

---

## 1. Resource Inventory & Tier Verification

Every tier claim has been verified against `az` CLI output.

| Resource | Claimed Tier | Verified Tier | Verdict |
|---|---|---|---|
| App Service (vatcscc) | P1v2 (PremiumV2), 1 vCPU, 3.5 GB RAM | **P1v2 PremiumV2, capacity=1** | CORRECT |
| VATSIM_ADL | Hyperscale Serverless Gen5 (16 max vCores) | **HS_S_Gen5, capacity=16, Hyperscale** | CORRECT |
| SWIM_API | Standard S1 (10 DTU) | **Standard, capacity=10** | CORRECT (10 DTU) |
| VATSIM_TMI | Basic (5 DTU) | **Basic, capacity=5** | CORRECT |
| VATSIM_REF | Basic (5 DTU) | **Basic, capacity=5** | CORRECT |
| VATSIM_STATS | GP Serverless Gen5 (1 vCore), PAUSED | **GP_S_Gen5, capacity=1, status=Paused** | CORRECT |
| MySQL (vatcscc-perti) | Standard_D2ds_v4 (GeneralPurpose), 2 vCPU, 8 GB, 20 GB | **Standard_D2ds_v4, GeneralPurpose, storageSizeGb=20** | CORRECT |
| PostGIS (vatcscc-gis) | Standard_B2s (Burstable), 2 vCPU, 4 GB, 32 GB | **Standard_B2s, Burstable, storageSizeGb=32** | CORRECT |

**All 8 tier claims verified.**

---

## 2. App Service (vatcscc) Claims

### 2a. Summary Metrics (12h totals/averages)

| Metric | Claimed Apr 23 | Verified Apr 23 | Claimed Apr 24 | Verified Apr 24 | Claimed Apr 25 | Verified Apr 25 | Verdict |
|---|---|---|---|---|---|---|---|
| CPU Time (sec) | 932 | **932.8** | 1,209 | **1,208.6** | 2,669 | **2,669.0** | CORRECT (all within rounding) |
| Requests | 6,324 | **6,324** | 6,269 | **6,269** | 34,688 | **34,688** | EXACT MATCH |
| Data Out (MB) | 793 | **793.4** | 381 | **381.4** | 2,712 | **2,712.5** | CORRECT |
| Data In (MB) | 216 | **217.4** | 148 | **148.2** | 819 | **819.3** | CORRECT |
| Http 5xx | 99 | **99** | 122 | **122** | 740 | **740** | EXACT MATCH |
| Http 4xx | 203 | **203** | 595 | **595** | 825 | **825** | EXACT MATCH |
| Avg Response Time (s) | 2.18 | **2.26** | 0.94 | **0.94** | 9.24 | **9.22** | MINOR DISCREPANCY on Apr 23 (2.18 vs 2.26) |
| Avg Memory (MB) | 502→682 | **544** (avg) | 557→737 | **618** (avg) | 729→860 | **787** (avg) | METHODOLOGY ISSUE — see below |

**Memory claim issue**: The original report showed min→max range per day. The audit computes the 12h average. Both are valid representations but the original's "502→682" for Apr 23 implied the memory started at 502 MB and ended at 682 MB. Re-checking the hourly data:
- Apr 23: hourly avg memory ranged from 455 MB (18Z) to 703 MB (19Z). The "502→682" appears to be cherry-picked first/last values, not the full trend. **PARTIALLY MISLEADING** — memory doesn't monotonically rise.
- Apr 25: hourly avg memory ranged from 729 MB (11Z) to 860 MB (16Z). The claim "729→860" is the actual min/max, not a temporal trend. More accurately: avg=787 MB, peak=860 MB.

### 2b. Delta Calculations

| Claim | Calculation | Verified | Verdict |
|---|---|---|---|
| "5.5x request volume Apr 25 vs baseline" | 34,688 / 6,324 = 5.49x | **CORRECT** (using Apr 23 as baseline) |
| "CPU time nearly tripled" | 2,669 / 932.8 = 2.86x | **CORRECT** (2.86x ≈ "nearly tripled") |
| "5xx errors surged 7.4x" | 740 / 99 = 7.47x | **CORRECT** |
| "Response time degraded — 18.5s avg at 09Z" | Hourly data: 09Z = 18.49s | **CORRECT** |
| "14.6s at 17Z" | 17Z = 14.64s | **CORRECT** |
| "vs ~2s baseline" | Apr 23 avg = 2.26s | **CORRECT** |
| "+449% requests" | (34,688 - 6,324) / 6,324 = 448.6% | **CORRECT** |
| "+242% Data Out" | (2,712 - 793) / 793 = 242.0% | **CORRECT** |
| "+279% Data In" | (819 - 217) / 217 = 277.4% | CLOSE — claimed +279%, actual +277%. **TRIVIAL ROUNDING** |
| "+647% 5xx errors" | (740 - 99) / 99 = 647.5% | **CORRECT** |
| "+324% Avg Response Time" | (9.22 - 2.26) / 2.26 = 308.0% | **WRONG** — claimed +324%, actual +308%. Used 2.18 baseline instead of 2.26. |

### 2c. App Service Plan Metrics

| Claim | Verified | Verdict |
|---|---|---|
| "Plan CPU 7.2-7.4% on Apr 23" | avg=7.3%, max=8.7% | CORRECT (avg matches) |
| "Plan CPU 7.5-8.3% on Apr 24" | avg=7.9%, max=9.2% | CORRECT |
| "Plan CPU 12.9-11.2% on Apr 25" | avg=12.0%, max=17.2% | PARTIALLY WRONG — original showed the two 6h-bucket values (12.87, 11.18), which are correct for that interval. But the max hourly was 17.2%, not captured in original report. |
| "Plan Memory 53-55% on Apr 23" | avg=54.5%, max=59.4% | CORRECT (6h bucket range) |
| "Plan Memory 56-57% on Apr 24" | avg=56.6%, max=60.8% | CORRECT (6h bucket range) |
| "Plan Memory 62-62% on Apr 25" | avg=61.9%, max=63.6% | CORRECT |
| "+60% CPU vs baseline" | 12.0/7.3 = 1.64x = +64% | **CORRECT** (within rounding) |
| "+15% Memory vs baseline" | 61.9/54.5 = 1.14x = +14% | **CORRECT** |
| "Memory at 62% of plan (2.2 GB of 3.5 GB)" | 62% × 3.5 GB = 2.17 GB | **CORRECT** |

### 2d. Hourly Detail Claims (Apr 25)

| Claim | Verified | Verdict |
|---|---|---|
| "5xx heaviest at 10-12Z (144, 140, 111)" | 10Z=144, 11Z=140, 12Z=111 | **EXACT MATCH** |
| "5xx 142 at 17Z" | 17Z=142 | **EXACT MATCH** |
| "Peak CPU Time at 11Z (313s) and 17Z (312s)" | 11Z=313.8s, 17Z=311.8s | **CORRECT** |

---

## 3. VATSIM_ADL Claims

| Metric | Claimed Apr 23 | Verified | Claimed Apr 24 | Verified | Claimed Apr 25 | Verified | Verdict |
|---|---|---|---|---|---|---|---|
| CPU % (avg) | 26.8 | **26.8** | 27.6 | **27.6** | 30.7 | **30.7** | EXACT |
| Data IO % | 6.2 | **6.1** | 6.6 | **6.6** | 6.1 | **6.1** | TRIVIAL (6.2 vs 6.1 for Apr 23) |
| Log Write % | 0.84 | **0.84** | 0.86 | **0.86** | 1.07 | **1.07** | EXACT |
| Connections (12h) | 5,053 | **5,053** | 3,144 | **3,144** | 22,486 | **22,486** | EXACT |
| Workers % | 2.4 | **2.4** | 2.9 | **2.9** | 3.15 | **3.1** | TRIVIAL |
| Storage (GB) | 599→600 | **640→644** | 604→607 | **647→652** | 613→617 | **655→663** | **WRONG** |
| Deadlocks | 0 | **0** | 0 | **0** | 0 | **0** | CORRECT |

### STORAGE CLAIM ERROR (CRITICAL)

The original report claimed:
- Apr 23: "599→600 GB"
- Apr 24: "604→607 GB"
- Apr 25: "613→617 GB"

Verified values:
- Apr 23: **640.2→644.4 GB**
- Apr 24: **647.0→651.6 GB**
- Apr 25: **655.1→662.9 GB**

The original numbers were **~40 GB too low across all three days**. This appears to have been caused by the original 6-hour bucket aggregation losing precision (the Azure API returns `maximum` for storage, and the 6h bucket may have averaged differently). The fresh hourly query using the correct aggregation function shows the true values.

**Corrected ADL storage growth**: 644.4 GB (end of Apr 23) → 662.9 GB (end of Apr 25) = **+18.5 GB over ~60 hours = ~7.4 GB/day** (not "~10 GB/day" as originally claimed). The 12h window on Apr 25 alone: 655.1→662.9 = **+7.8 GB in 12h**, which if extrapolated to 24h would be ~15.6 GB/day — but extrapolation from a 12h peak window overstates the daily average.

| Claim | Verified | Verdict |
|---|---|---|
| "Storage growing ~10 GB/day" | 3-day rate = 7.4 GB/day; 12h peak-window rate = 15.6 GB/day projected | **OVERSTATED** — 7.4 GB/day is the real average |
| "+15% CPU vs baseline" | 30.7/26.8 = 1.15x = +15% | **CORRECT** |
| "+345% connections" | (22,486-5,053)/5,053 = 345% | **CORRECT** |
| "Peak at 16Z: 41.7%" | 16Z hourly = 41.7% | **CORRECT** |
| "hitting 700 GB within 2 weeks" | At 7.4 GB/day: 662.9 + (14 × 7.4) = 766.5 GB in 2 weeks | **DIRECTIONALLY CORRECT** (would exceed 700 GB in ~5 days at this rate, faster than claimed) |

---

## 4. SWIM_API Claims

| Metric | Claimed Apr 23 | Verified | Claimed Apr 24 | Verified | Claimed Apr 25 | Verified | Verdict |
|---|---|---|---|---|---|---|---|
| CPU % | "0.05→3.5" | **avg=1.8%, max=10.5%** | "7.4→15.4" | **avg=11.4%, max=23.4%** | 17.9 | **avg=17.9%** | MIXED — see below |
| Data IO % | "0→12.3" | **avg=6.2%, max=46.4%** | "0→24.1" | **avg=12.1%, max=86.0%** | "39.8 avg" | **avg=39.9%** | MOSTLY CORRECT avg; original 6h buckets hid maximums |
| Log Write % | "0.05→10.6" | **avg=5.3%** | "1.7→12.5" | **avg=7.1%** | "16.0 avg" | **avg=16.0%** | CORRECT |
| Connections | 2,721 | **2,721** | 314 | **314** | 2,341 | **2,341** | EXACT |
| Workers % | "0.6→1.0" | **avg=0.8%, max=1.9%** | "0.8→2.0" | **avg=1.4%, max=4.8%** | "3.1 avg" | **avg=3.1%** | CORRECT |
| Storage (GB) | "1.31→1.37" | **1.41→1.48** | "1.42→1.60" | **1.47→1.72** | "1.80→1.87" | **1.77→1.99** | **WRONG** (same aggregation issue as ADL) |

### SWIM_API CPU/IO Claim Issues

The original used 6h bucket averages which showed "0.05→3.5" for Apr 23 CPU. The hourly data reveals:
- Apr 23 CPU ranged from 0.0% to 10.5% — the "0.05" was the first 6h bucket, "3.5" was the second. The avg was 1.8% and the **max was 10.5%**, which the original report didn't capture.
- Apr 24 IO **peaked at 86.0%** (not shown in original's "0→24.1" — the 24.1 was the second 6h bucket average hiding a massive peak).

**The 6h-bucket presentation systematically hid peak values.** The hourly granularity reveals:
- Apr 24 SWIM_API IO peaked at **86.0%** (not visible in original report)
- Apr 23 SWIM_API IO peaked at **46.4%** (not visible in original report)

This means the **IO saturation trend was already developing on Apr 23-24**, not just Apr 25.

### SWIM_API Storage

| Claim | Verified | Verdict |
|---|---|---|
| "1.31 GB (Apr 23) → 1.87 GB (Apr 25) = 43% in 3 days" | 1.41 GB → 1.99 GB = **41% in 3 days** | **CLOSE** but base numbers were wrong |
| "99.4% IO at 13Z" | 13Z = **99.4%** | **EXACT MATCH** |
| "94.8% IO at 19Z" | 19Z = **94.8%** | **EXACT MATCH** |
| "SWIM_API hit 99.4% IO — #1 bottleneck" | Verified — S1 at 10 DTU, IO regularly at 90%+ | **CORRECT assessment** |
| "S1 at 10 DTU is IO-starved" | 99.4% IO is definitively at the ceiling | **CORRECT** |

**Updated finding**: The IO problem is worse than originally reported. Apr 24 already hit 86.0% IO (hidden by 6h bucketing), meaning this has been degrading for at least 2 days.

---

## 5. VATSIM_TMI Claims

| Metric | Claimed Apr 23 | Verified | Claimed Apr 25 | Verified | Verdict |
|---|---|---|---|---|---|
| CPU % | 0.18→0.24 | **avg=0.2%, max=0.4%** | 3.45→1.51 | **avg=2.5%, max=8.6%** | NOTABLE — original missed that TMI spiked to 8.6% on Apr 25 |
| Connections | ~2,649 | **2,649** | ~5,194 | **5,194** | CORRECT |
| Workers % | ~3.0 | **3.0** | 4.75→3.51 | **avg=4.1%, max=8.7%** | NOTABLE — workers peaked at 8.7% on Apr 25 (not in original) |
| Sessions % | ~5.3 | **5.3** | 6.22→5.97 | **avg=6.1%** | CORRECT |

TMI had a more significant jump on Apr 25 than the original report suggested. CPU went from 0.2% avg to 2.5% avg (**12.5x increase**), and workers peaked at 8.7%. While still well within capacity, this correlates with the higher traffic.

---

## 6. VATSIM_REF Claims

| Metric | Claimed Apr 23 | Verified | Claimed Apr 25 | Verified | Verdict |
|---|---|---|---|---|---|
| CPU % | "0.28→2.64" | **avg=1.5%, max=6.9%** | "4.73→5.21" | **avg=5.0%, max=12.7%** | NOTABLE — max hit 12.7% (not in original) |
| Log Write % | "0→2.31" | **avg=1.16%** | "4.42→4.68" | **avg=4.55%** | CORRECT |
| Connections | ~2,639 | **2,639** | ~1,469 | **1,469** | CORRECT |

REF CPU peaked at 12.7% on Apr 25, higher than the original report showed. Still well within 5 DTU capacity but a 3x increase worth noting.

---

## 7. PostGIS Claims

| Metric | Claimed Apr 23 | Verified | Claimed Apr 24 | Verified | Claimed Apr 25 | Verified | Verdict |
|---|---|---|---|---|---|---|---|
| CPU % avg | "9.5→10.4" | **9.9%** | "9.3→12.1" | **10.7%** | "12.1 avg" | **12.1%** | CORRECT |
| CPU % max | Not stated | **11.4%** | Not stated | **14.1%** | "14.7% peak at 12Z" | **14.7%** | CORRECT (Apr 25 peak) |
| Memory % | "38.4" | **38.4%** | "39.0" | **39.0%** | "38.9" | **38.9%** | EXACT |
| Connections | "10.6" | **10.6** | "10.7" | **10.7** | "10.8" | **10.8** | EXACT |
| Storage % | "14.75%" | **14.7%** | "14.75%" | **14.8%** | "14.75%" | **14.8%** | TRIVIAL |
| Data Out (GB) | "2.02" | **2.17** | "2.82" | **3.04** | "2.09" | **2.25** | **WRONG** — all three values understated |

### PostGIS Data Out Error

Original 6h-bucket sums were lower than the hourly sums:
- Apr 23: claimed 2.02 GB, verified **2.17 GB** (+7.4%)
- Apr 24: claimed 2.82 GB, verified **3.04 GB** (+7.8%)
- Apr 25: claimed 2.09 GB, verified **2.25 GB** (+7.7%)

This systematic ~7-8% undercount suggests the 6h bucket aggregation truncated some data points. Not material to conclusions but worth noting.

| Claim | Verdict |
|---|---|
| "PostGIS is healthy and stable" | **CORRECT** — all metrics well within capacity |
| "CPU averaging 12% with peaks at 14.7%" | **CORRECT** |
| "Memory locked at ~39%" | **CORRECT** |
| "No concerns" | **CORRECT** |

---

## 8. MySQL Claims

| Metric | Claimed Apr 23 | Verified | Claimed Apr 24 | Verified | Claimed Apr 25 | Verified | Verdict |
|---|---|---|---|---|---|---|---|
| CPU % | "12.7→17.1" | **avg=7.5%, max=17.1%** | "11.9→10.9" | **avg=6.1%, max=11.9%** | "15.0→14.5" | **avg=9.4%, max=15.0%** | MIXED — original showed 6h-bucket values, not avg/max |
| Memory % | "22.7→23.9" | **avg=22.8%** | "23.3→24.3" | **avg=23.4%** | "24.5→24.5" | **avg=24.1%** | CORRECT (range format) |
| Storage % | "14.09%" | **14.1%** | "14.1→14.2%" | **14.1%** | "14.2→14.6%" | **14.2%** | MINOR — original overstated Apr 25 range |
| Active Conns | "13→61" | **max=61** | "33→61" | **max=61** | "65→61" | **max=65** | CORRECT |
| Total Conns | 7,786 | **7,786** | 3,810 | **3,810** | 7,479 | **7,479** | EXACT |
| Queries | 73,030 | **73,030** | 65,779 | **65,779** | 93,119 | **93,119** | EXACT |
| IO % | "1.5→6.4" | **avg=1.8%, max=6.3%** | "0.9→6.4" | **avg=1.6%, max=6.4%** | "6.4" | **avg=3.3%, max=6.4%** | Apr 25 avg was 3.3%, NOT 6.4 — original used peak |
| Data Out (GB) | "1.13" | **1.21** | "1.65" | **1.78** | "2.91" | **3.13** | **UNDERSTATED** (~7% low, same 6h-bucket issue) |

### MySQL IO Claim Error

The original claimed Apr 25 IO was "6.4" (suggesting constant 6.4%). The verified average is **3.3%** with a max of 6.4%. The original misrepresented the peak as the average.

| Claim | Verified | Verdict |
|---|---|---|
| "MySQL is well-provisioned" | **CORRECT** — CPU max 15%, Memory 24%, IO max 6.4% |
| "CPU peaks at 15% (11Z)" | **CORRECT** |
| "D2ds_v4 has significant headroom" | **CORRECT** |
| "Query count up 27% (93K vs 73K)" | 93,119/73,030 = 1.275x = +27.5% | **CORRECT** |
| "+76% Data Out" | 3.13/1.21 = 2.59x = +159% (not +76%) | **WRONG** — original used wrong baseline |

Data Out comparison: The original claimed "+76%" but:
- If comparing to Apr 24: 3.13/1.78 = +75.8% — **CORRECT** (using Apr 24 as baseline, not Apr 23)
- If comparing to Apr 23: 3.13/1.21 = +158.7% — would be +159%

The +76% was relative to Apr 24. This is valid but inconsistent with using Apr 23 as baseline elsewhere.

---

## 9. Cost Estimate Audit

The Azure Consumption API returned null cost fields for the recent period (billing data lag). Costs are estimated from published Azure pricing. Verification against Azure pricing calculator:

| Resource | Claimed $/day | Azure Pricing Basis | Verified $/day | Verdict |
|---|---|---|---|---|
| App Service P1v2 | $2.40 | P1v2 Linux: ~$73.73/mo = $2.42/day | **$2.42** | CORRECT |
| VATSIM_ADL HS Serverless | $8-25 (usage) | HS_S Gen5: $0.000145/vCore/sec billed. At 30.7% of 16 vCores avg → ~4.9 vCores × 43,200s × $0.000145 = **$30.7** | **$25-35** | **UNDERSTATED** — serverless billing is higher than claimed |
| SWIM_API S1 (10 DTU) | $0.50 | S1: $15.03/mo = $0.49/day | **$0.49** | CORRECT |
| VATSIM_TMI Basic 5 | $0.16 | Basic 5 DTU: $4.99/mo = $0.16/day | **$0.16** | CORRECT |
| VATSIM_REF Basic 5 | $0.16 | Basic 5 DTU: $4.99/mo = $0.16/day | **$0.16** | CORRECT |
| VATSIM_STATS GP Serverless | $0.00 | Paused — no compute charge | **$0.00** | CORRECT |
| MySQL D2ds_v4 | $5.50 | D2ds_v4: ~$175.68/mo = $5.77/day + storage | **~$6.00** | CLOSE (slightly understated) |
| PostGIS B2s | $1.10 | B2s: ~$33.87/mo = $1.11/day + storage | **~$1.30** | CLOSE |
| Storage (5 accounts) | ~$0.50 | Varies by tier and usage | **~$0.50-1.00** | PLAUSIBLE |

### ADL Cost — Now With Actual `app_cpu_billed` Metric

The `app_cpu_billed` metric reports actual vCore-seconds billed by Azure. Queried fresh:

| Period | app_cpu_billed (vCore-sec) | cpu_used (avg vCores) | app_cpu_percent | app_memory_percent |
|---|---|---|---|---|
| Apr 23 09-15Z | 188,356 | 4.39 | 7.9% | 26.7% |
| Apr 23 15-21Z | 199,552 | 4.19 | 7.9% | 28.4% |
| Apr 24 09-15Z | 178,882 | 4.37 | 7.9% | 25.5% |
| Apr 24 15-21Z | 206,196 | 4.45 | 8.4% | 29.6% |
| **Apr 25 09-15Z** | **235,623** | **4.57** | **9.3%** | **33.5%** |
| **Apr 25 15-21Z** | **264,690** | **5.25** | **10.4%** | **37.1%** |

**Actual 12h vCore-seconds billed:**
- Apr 23: 387,908 vCore-sec (12h) → projected 24h: ~775,816
- Apr 24: 385,079 vCore-sec (12h) → projected 24h: ~770,158
- **Apr 25: 500,313 vCore-sec (12h) → projected 24h: ~1,000,626**

**Cost calculation** (Hyperscale Serverless East US: ~$0.000145/vCore/sec):
- Apr 23: 775,816 × $0.000145 = **$112.49/day** (compute only)
- Apr 24: 770,158 × $0.000145 = **$111.67/day**
- **Apr 25: 1,000,626 × $0.000145 = $145.09/day** (compute only)
- Storage: 663 GB × $0.25/30 = **$5.53/day**

**ADL daily total: ~$117-151/day** ($3,500-4,500/month)

NOTE: The $0.000145/vCore/sec is the list price for Hyperscale Serverless Gen5 in East US. Actual billing may differ based on reservation discounts or negotiated rates. The `app_cpu_billed` vCore-seconds are the ground truth for what Azure charges compute on.

| Claim | Verified | Verdict |
|---|---|---|
| "Estimated daily total: ~$18-37/day" | **~$128-162/day** with actual ADL billing | **MASSIVELY UNDERSTATED** (original was 7-8x too low) |
| "Monthly burn: ~$700-870" | **~$3,800-4,900/month** at current utilization | **MASSIVELY UNDERSTATED** |
| "+15% higher compute cost on Apr 25" | Apr 25 billed 30% more vCore-sec than Apr 23/24 | **UNDERSTATED** (+30%, not +15%) |

---

## 10. Recommendation Audit

### "Upgrade SWIM_API to S2 (50 DTU) for $1.21/day"

- S2 pricing: $75.14/mo = **$2.47/day** (not $1.21/day as claimed for the increment)
- S1 pricing: $15.03/mo = $0.49/day
- **Delta: +$1.97/day** (not +$0.71/day as claimed)
- The claim "$1.21/day" was actually the S2 absolute cost misremembered. S2 is $2.47/day.
- **Verdict**: The recommendation to upgrade is **CORRECT** (SWIM_API is genuinely IO-starved). The cost delta was **WRONG** ($1.97/day, not $0.71/day).

### "ADL storage growth — sp_Purge_OldData needs attention"

- Storage grew 640→663 GB over 3 days (7.4 GB/day average)
- sp_Purge_OldData is documented as broken in project memory
- **Verdict**: **CORRECT** — this is a real ongoing issue

### "SWIM_API storage doubling rate"

- 1.41→1.99 GB over 3 days = +41%
- "Doubling rate" was hyperbolic but direction is correct
- **Verdict**: **DIRECTIONALLY CORRECT** but "doubling" overstates it

### "VATSIM_STATS is paused — no analytics pipeline"

- Confirmed status=Paused
- **Verdict**: **CORRECT**

---

## 11. Summary of Errors Found

### Significant Errors

| # | Claim | Error | Impact |
|---|---|---|---|
| 1 | **Monthly cost "~$700-870"** | Actually **~$3,800-4,900/month** — ADL Hyperscale Serverless compute alone is ~$112-145/day | **CRITICAL** — original estimate was 5-6x too low |
| 2 | ADL storage "599→600 GB" through "613→617 GB" | Actually 640→663 GB — **~40 GB undercount** | High — understates storage costs and urgency |
| 3 | SWIM_API storage "1.31→1.87 GB" | Actually 1.41→1.99 GB — **~0.1 GB undercount** | Low — direction correct |
| 4 | SWIM S2 upgrade cost "+$0.71/day" | Actually **+$1.97/day** | Medium — still trivial vs overall spend |
| 5 | ADL storage growth "~10 GB/day" | 3-day average is **7.4 GB/day** | Medium — overstated by 35% |
| 6 | Apr 25 Avg Response Time delta "+324%" | Actually **+308%** (wrong baseline: 2.18 vs 2.26) | Low |

### Presentation Issues

| # | Issue | Impact |
|---|---|---|
| 1 | 6h-bucket aggregation hid peak values (SWIM IO 86% on Apr 24 not shown) | Medium — understated severity of IO problem |
| 2 | MySQL IO "6.4" presented as if constant (was actually the max, avg=3.3%) | Low |
| 3 | Memory shown as "min→max" ranges implying temporal trend when values fluctuated | Low |
| 4 | Data Out totals systematically ~7-8% low due to 6h bucketing | Low |

### Correct Claims (32 of 39 verifiable claims)

All 8 tier claims, request counts, 5xx/4xx counts, connection counts, deadlock counts, query counts, CPU averages, and directional trends were correct. The 7 errors were: storage byte values (wrong aggregation on ADL and SWIM), cost estimates (ADL serverless massively underpriced), SWIM upgrade pricing, storage growth rate, one response time delta, and MySQL IO misrepresentation. The biggest miss was the cost estimate — ADL Hyperscale Serverless is ~90% of total spend and was estimated at $8-25/day when it's actually $112-145/day.

---

## 12. Corrected Key Findings

1. **SWIM_API is IO-saturated** — CONFIRMED AND WORSE THAN REPORTED. IO peaked at 86% on Apr 24 (hidden in original), 99.4% on Apr 25. This has been degrading for 2+ days, not just Apr 25.

2. **App Service had 5.5x traffic surge on Apr 25** — CONFIRMED. 34,688 requests vs ~6,300 baseline. 740 5xx errors (7.5x baseline). Response times up to 18.5s.

3. **ADL storage is 663 GB and growing 7.4 GB/day** — CORRECTED from original 617 GB / 10 GB/day claim. Still urgent — purge SP is broken.

4. **Monthly cost is ~$3,800-4,900** — CORRECTED from original $700-870 using actual `app_cpu_billed` metric. ADL Hyperscale Serverless compute alone is $112-145/day ($3,400-4,350/mo), dwarfing all other resources combined (~$12/day).

5. **PostGIS and MySQL are healthy** — CONFIRMED. Both well within capacity limits.

6. **TMI and REF had notable spikes** — NEW FINDING not adequately captured in original. TMI CPU went from 0.2% to 2.5% avg (8.6% peak), REF CPU went from 1.5% to 5.0% avg (12.7% peak). Both still within capacity but correlate with traffic surge.
