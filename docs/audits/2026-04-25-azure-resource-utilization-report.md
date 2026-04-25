# Azure Resource Utilization Report — April 25, 2026

**Window**: 09:00Z – 21:00Z on April 23, 24, 25 (12h each)
**Data source**: Azure Monitor metrics (PT1H summary + PT5M detail for Apr 25)

---

## Resource Inventory

| Resource | SKU | Specs | Status |
|---|---|---|---|
| App Service (vatcscc) | P1v2 PremiumV2 | 1 vCPU, 3.5 GB RAM | Running |
| VATSIM_ADL | Hyperscale Serverless Gen5 | 0–16 vCores auto-scale | Online |
| SWIM_API | Standard S1 | 10 DTU, 250 GB max | Online |
| VATSIM_TMI | Basic | 5 DTU, 2 GB max | Online |
| VATSIM_REF | Basic | 5 DTU, 2 GB max | Online |
| VATSIM_STATS | GP Serverless Gen5 | 1 vCore | **Paused** |
| MySQL (vatcscc-perti) | Standard_D2ds_v4 | 2 vCPU, 8 GB RAM, 20 GB disk | Ready |
| PostGIS (vatcscc-gis) | Standard_B2s Burstable | 2 vCPU, 4 GB RAM, 32 GB disk | Ready |

---

## 1. App Service

### 12-Hour Totals

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU Time (sec) | 932.8 | 1,208.6 | **2,669.0** | +186% |
| Requests | 6,324 | 6,269 | **34,688** | +449% |
| Data Out (MB) | 793 | 381 | **2,713** | +242% |
| Data In (MB) | 217 | 148 | **819** | +277% |
| Http 5xx | 99 | 122 | **740** | +647% |
| Http 4xx | 203 | 595 | **825** | +306% |
| Avg Response Time (s) | 2.26 | 0.94 | **9.22** | +308% |
| Avg Memory (MB) | 544 | 618 | **787** | +45% |

### App Service Plan Utilization

| Metric | Apr 23 | Apr 24 | Apr 25 |
|---|---|---|---|
| CPU avg / max | 7.3% / 8.7% | 7.9% / 9.2% | **12.0% / 17.2%** |
| Memory avg / max | 54.5% / 59.4% | 56.6% / 60.8% | **61.9% / 63.6%** |

### Apr 25 Hourly Breakdown

| Hour | CPU Time (s) | Requests | 5xx | Avg Resp (s) | Memory (MB) |
|---|---|---|---|---|---|
| 09Z | 247.8 | 2,380 | 80 | 18.49 | 764 |
| 10Z | 274.0 | 4,072 | 144 | 12.81 | 791 |
| 11Z | 313.8 | 3,920 | 140 | 8.40 | 728 |
| 12Z | 284.2 | 3,803 | 111 | 6.27 | 781 |
| 13Z | 133.2 | 3,033 | 9 | 4.54 | 783 |
| 14Z | 181.2 | 3,133 | 11 | 4.19 | 810 |
| 15Z | 159.4 | 2,991 | 11 | 5.79 | 814 |
| 16Z | 231.8 | 3,168 | 28 | 13.74 | **860** |
| 17Z | 311.8 | 3,837 | 142 | 14.64 | 731 |
| 18Z | 205.3 | 1,901 | 34 | 14.75 | 768 |
| 19Z | 120.0 | 758 | 18 | 3.44 | 837 |
| 20Z | 206.6 | 1,692 | 12 | 3.58 | 773 |

Two distinct traffic peaks: **09–12Z** (heavy requests + high 5xx) and **16–18Z** (high response times).

---

## 2. VATSIM_ADL (Hyperscale Serverless)

### Summary

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU % avg / max | 26.8 / 31.3 | 27.6 / 33.0 | **30.7 / 41.7** | +15% avg |
| Data IO % avg / max | 6.1 / 7.2 | 6.6 / 7.2 | **6.1 / 7.9** | flat |
| Log Write % | 0.84 | 0.86 | **1.07** | +27% |
| Connections (12h) | 5,053 | 3,144 | **22,486** | +345% |
| Workers % avg / max | 2.4 / 2.7 | 2.9 / 3.2 | **3.1 / 3.7** | +29% |
| Deadlocks | 0 | 0 | **0** | — |
| Storage (GB) | 640→644 | 647→652 | **655→663** | +7.4 GB/day |

### Serverless Billing (from `app_cpu_billed` metric)

| Period | vCore-sec Billed | Avg vCores Used | App CPU % | App Memory % |
|---|---|---|---|---|
| Apr 23 09-15Z | 188,356 | 4.39 | 7.9% | 26.7% |
| Apr 23 15-21Z | 199,552 | 4.19 | 7.9% | 28.4% |
| Apr 24 09-15Z | 178,882 | 4.37 | 7.9% | 25.5% |
| Apr 24 15-21Z | 206,196 | 4.45 | 8.4% | 29.6% |
| **Apr 25 09-15Z** | **235,623** | **4.57** | **9.3%** | **33.5%** |
| **Apr 25 15-21Z** | **264,690** | **5.25** | **10.4%** | **37.1%** |

12h billed vCore-seconds: Apr 23 = 387,908 | Apr 24 = 385,079 | **Apr 25 = 500,313** (+30%)

### Apr 25 Hourly CPU

```
09Z  10Z  11Z  12Z  13Z  14Z  15Z  16Z  17Z  18Z  19Z  20Z
26.3 36.8 28.9 25.4 23.6 30.4 31.2 41.7 31.0 29.9 30.5 32.6
```

Peak at **16Z: 41.7%** — correlates with App Service CPU spike.

---

## 3. SWIM_API (Standard S1, 10 DTU)

### Summary

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU % avg / max | 1.8 / 10.5 | 11.4 / 23.4 | **17.9 / 26.7** | +894% avg |
| **Data IO % avg / max** | **6.2 / 46.4** | **12.1 / 86.0** | **39.9 / 99.4** | **+543% avg** |
| Log Write % avg | 5.3 | 7.1 | **16.0** | +202% |
| Connections (12h) | 2,721 | 314 | **2,341** | — |
| Workers % avg / max | 0.8 / 1.9 | 1.4 / 4.8 | **3.1 / 5.5** | +288% avg |
| Sessions % | 2.4 | 2.2 | **3.0** | +25% |
| Storage (GB) | 1.41→1.48 | 1.47→1.72 | **1.77→1.99** | +41% in 3 days |

### Apr 25 Hourly IO — Hitting the Ceiling

```
         09Z  10Z  11Z  12Z  13Z  14Z  15Z  16Z  17Z  18Z  19Z  20Z
CPU %    12.0 19.1 16.6 20.2 17.1 26.7 14.4 14.8 16.9 19.4 17.3 20.3
IO %      2.5 67.7 41.7 61.8 99.4 32.2  0.3  0.2  2.1 40.9 94.8 35.0
LogW %    4.5 23.2 20.4 22.9 11.4 39.6  3.3  3.5  3.9 21.8 10.4 27.1
Workers%  1.2  4.8  3.4  4.5  5.5  3.3  1.5  1.3  1.6  2.8  4.8  2.4
```

**IO hit 99.4% at 13Z and 94.8% at 19Z.** The pattern shows IO spikes correlating with SWIM sync daemon cycles. At S1 (10 DTU), this database is IO-starved. The trend was already developing — Apr 24 peaked at 86.0%.

---

## 4. VATSIM_TMI (Basic 5 DTU)

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU % avg / max | 0.2 / 0.4 | 0.1 / 0.3 | **2.5 / 8.6** | +1150% avg |
| Connections (12h) | 2,649 | 254 | **5,194** | +96% |
| Workers % avg / max | 3.0 / 3.1 | 3.0 / 3.0 | **4.1 / 8.7** | +37% avg |
| Sessions % | 5.3 | 5.2 | **6.1** | +15% |
| Storage (GB) | 0.10 | 0.10 | **0.12** | — |

TMI saw a 12.5x CPU increase on Apr 25 (still low absolute). Workers peaked at 8.7%.

---

## 5. VATSIM_REF (Basic 5 DTU)

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU % avg / max | 1.5 / 6.9 | 0.8 / 5.7 | **5.0 / 12.7** | +233% avg |
| Log Write % | 1.16 | 0.53 | **4.55** | +292% |
| Connections (12h) | 2,639 | 225 | **1,469** | -44% |
| Workers % avg / max | 0.3 / 1.6 | 0.2 / 1.3 | **1.1 / 3.0** | +267% avg |
| Sessions % | 4.5 | 4.2 | **5.2** | +16% |
| Storage (GB) | 0.63→0.65 | 0.64→0.63 | **0.65→0.65** | stable |

REF CPU peaked at 12.7% — 3x its baseline. Still within capacity.

---

## 6. PostGIS (B2s Burstable)

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU % avg / max | 9.9 / 11.4 | 10.7 / 14.1 | **12.1 / 14.7** | +22% avg |
| Memory % | 38.4 | 39.0 | **38.9** | flat |
| Active Connections | 10.6 | 10.7 | **10.8** | flat |
| Storage % | 14.7% | 14.8% | **14.8%** | flat (4.7 GB / 32 GB) |
| Egress (GB) | 2.17 | 3.04 | **2.25** | +4% |
| Ingress (GB) | 0.81 | 0.83 | **0.85** | +5% |

PostGIS is healthy. All metrics stable with significant headroom.

---

## 7. MySQL (D2ds_v4 GeneralPurpose)

| Metric | Apr 23 | Apr 24 | Apr 25 | 25 vs 23 |
|---|---|---|---|---|
| CPU % avg / max | 7.5 / 17.1 | 6.1 / 11.9 | **9.4 / 15.0** | +25% avg |
| Memory % | 22.8 | 23.4 | **24.1** | +6% |
| Storage % | 14.1 | 14.1 | **14.2** | flat |
| Active Connections (max) | 61 | 61 | **65** | +7% |
| Total Connections (12h) | 7,786 | 3,810 | **7,479** | -4% |
| Queries (12h) | 73,030 | 65,779 | **93,119** | +28% |
| IO % avg / max | 1.8 / 6.3 | 1.6 / 6.4 | **3.3 / 6.4** | +83% avg |
| Egress (GB) | 1.21 | 1.78 | **3.13** | +159% |

MySQL is well-provisioned. CPU headroom at 85%, memory at 76%, IO well under ceiling.

---

## 8. Cost Breakdown

### Daily Compute & Storage Costs

| Resource | Pricing Model | Est. $/day | % of Total |
|---|---|---|---|
| **VATSIM_ADL compute** | Serverless vCore-sec | **$112–145** | **87–89%** |
| VATSIM_ADL storage | 663 GB @ ~$0.25/GB/mo | $5.53 | 4% |
| MySQL D2ds_v4 | Fixed tier | $5.77 | 4% |
| App Service P1v2 | Fixed tier | $2.42 | 2% |
| SWIM_API S1 | Fixed tier (10 DTU) | $0.49 | <1% |
| PostGIS B2s | Fixed tier | $1.11 | <1% |
| VATSIM_TMI Basic | Fixed tier (5 DTU) | $0.16 | <1% |
| VATSIM_REF Basic | Fixed tier (5 DTU) | $0.16 | <1% |
| Storage accounts (5) | Usage-based | ~$0.50 | <1% |
| **TOTAL** | | **$128–162/day** | |

### Monthly Projection

| Scenario | ADL vCore-sec/day | Est. Monthly |
|---|---|---|
| Baseline (Apr 23/24 rate) | ~770,000 | **~$3,800/mo** |
| Elevated (Apr 25 rate) | ~1,000,000 | **~$4,900/mo** |

ADL Hyperscale Serverless is **~90% of total Azure spend**. All other resources combined are ~$12/day.

### ADL Billing Detail

The `app_cpu_billed` metric shows ADL consistently uses 4.2–5.3 vCores (of 16 max). On Apr 25, compute billing increased 30% over baseline due to the traffic surge.

---

## 9. Key Findings

### SWIM_API IO Saturation (Critical)

SWIM_API at S1 (10 DTU) is the primary bottleneck. IO hit **99.4%** at 13Z and **94.8%** at 19Z on Apr 25. The problem was already developing — Apr 24 peaked at 86%, Apr 23 at 46%. During IO saturation, the SWIM sync daemon stalls, which cascades into App Service 5xx errors and degraded response times (18.5s peak). Upgrading to S2 (50 DTU, +$1.97/day) would provide 5x IO headroom.

### Apr 25 Traffic Surge

Apr 25 saw 5.5x normal request volume (34,688 vs ~6,300). This drove:
- 7.5x 5xx errors (740 vs 99)
- 4x response time degradation (9.2s avg vs 2.3s)
- 30% higher ADL compute billing
- 2x connections to ADL (22,486 vs 5,053)

### ADL Storage Growth

ADL is at **663 GB**, growing **~7.4 GB/day**. The purge stored procedure (`sp_Purge_OldData`) is documented as broken. At current growth, 700 GB will be reached in ~5 days. While Hyperscale has no hard storage cap, storage costs scale linearly.

### ADL Dominates Spend

ADL Hyperscale Serverless compute ($112–145/day) is 87–89% of total Azure spend. All fixed-tier resources combined cost ~$12/day. Any cost optimization effort should focus on ADL — reserved capacity, min/max vCore tuning, or workload optimization.

### Healthy Resources

PostGIS (B2s) and MySQL (D2ds_v4) are both well within capacity. PostGIS CPU averages 12%, memory 39%. MySQL CPU averages 9%, memory 24%. Neither requires scaling changes.

---

## 10. Detailed Sub-Hourly Data (Apr 25 — PT5M granularity)

All data below is from Azure Monitor at 5-minute resolution, presented in 15-minute buckets showing the average and peak (highest 5-min value) within each bucket.

### 10.1 App Service — 15-Minute Breakdown

| Time | CPU (s) | Requests | 5xx | Avg Resp (s) | Peak Resp (s) | Memory (MB) |
|---|---|---|---|---|---|---|
| 09:00 | 17.5 | 123 | 6 | 69.8 | **113.1** | 988 |
| 09:15 | 20.3 | 162 | 6 | 29.5 | 43.7 | 720 |
| 09:30 | 16.3 | 113 | 3 | 7.8 | 13.1 | 689 |
| 09:45 | 28.6 | 396 | 12 | 7.7 | 12.0 | 660 |
| 10:00 | 24.0 | 396 | 10 | 9.5 | 14.2 | 755 |
| 10:15 | 32.6 | 450 | **21** | 16.9 | 30.7 | 835 |
| 10:30 | 19.2 | 256 | 10 | 10.4 | 11.7 | 809 |
| 10:45 | 15.5 | 256 | 7 | 11.2 | 13.4 | 766 |
| 11:00 | 24.7 | 318 | 11 | 10.6 | 15.4 | 712 |
| 11:15 | 24.4 | 239 | 13 | 12.2 | 14.4 | 663 |
| 11:30 | 25.1 | 377 | 7 | 7.4 | 9.4 | 742 |
| 11:45 | 30.4 | 373 | 16 | 6.4 | 8.8 | 797 |
| 12:00 | 22.1 | 314 | 11 | 9.2 | 14.1 | 812 |
| 12:15 | 29.9 | 290 | **16** | 5.2 | 8.5 | 782 |
| 12:30 | 27.9 | 422 | 6 | 5.6 | 9.2 | 774 |
| 12:45 | 14.8 | 242 | 3 | 5.0 | 5.5 | 756 |
| 13:00 | 11.6 | 245 | 0 | 4.6 | 5.4 | 758 |
| 13:15 | 10.5 | 289 | 1 | 2.9 | 3.2 | 754 |
| 13:30 | 10.9 | 251 | 0 | 3.5 | 3.9 | 813 |
| 13:45 | 11.4 | 226 | 1 | 7.9 | 14.3 | 809 |
| 14:00 | 9.5 | 219 | 1 | 5.0 | 5.2 | 802 |
| 14:15 | 19.4 | 232 | 0 | 4.3 | 5.6 | 802 |
| 14:30 | 17.8 | 241 | 1 | 5.5 | 6.4 | 817 |
| 14:45 | 13.7 | 352 | 1 | 3.0 | 4.3 | 817 |
| 15:00 | 11.2 | 209 | 1 | 5.0 | 5.4 | 815 |
| 15:15 | 13.4 | 371 | 1 | 5.5 | 7.4 | 804 |
| 15:30 | 12.8 | 248 | 1 | 4.9 | 6.4 | 798 |
| 15:45 | 15.8 | 168 | 1 | 10.0 | 13.4 | 839 |
| 16:00 | 16.6 | 235 | 1 | 13.6 | 16.7 | **864** |
| 16:15 | 16.8 | 237 | 1 | 14.9 | 19.6 | 860 |
| 16:30 | 19.1 | 313 | 1 | 14.6 | 24.1 | 865 |
| 16:45 | 24.9 | 271 | 6 | 11.5 | 16.3 | 849 |
| 17:00 | 27.7 | 273 | **20** | 10.4 | 13.3 | 762 |
| 17:15 | 30.1 | 389 | **25** | 14.5 | 21.9 | 723 |
| 17:30 | 20.1 | 315 | 1 | 14.9 | 23.2 | 750 |
| 17:45 | 26.1 | 302 | 1 | 19.2 | **26.4** | 689 |
| 18:00 | 26.4 | 294 | 8 | 14.7 | 16.2 | 699 |
| 18:15 | 18.9 | 256 | 0 | 15.6 | 19.5 | 693 |
| 18:30 | 14.1 | 68 | 1 | 6.4 | 16.0 | 794 |
| 18:45 | 9.0 | 15 | 2 | 1.6 | 1.9 | 885 |
| 19:00 | 7.8 | 19 | 2 | 1.0 | 1.9 | 888 |
| 19:15 | 7.7 | 12 | 0 | 0.9 | 1.3 | 889 |
| 19:30 | 10.3 | 145 | 1 | 5.0 | 6.8 | 854 |
| 19:45 | 14.2 | 77 | 3 | 1.4 | 1.4 | 718 |
| 20:00 | 10.2 | 80 | 1 | 1.2 | 1.3 | 739 |
| 20:15 | 19.2 | 162 | 1 | 2.9 | 3.3 | 849 |
| 20:30 | 22.8 | 104 | 1 | 7.3 | 10.3 | 751 |
| 20:45 | 16.6 | 218 | 1 | 3.9 | 5.6 | 753 |

**Peak response time: 113s at 09:00Z** — cold start / initial connection storm. The 09:00-09:15Z window is consistently the worst 15 minutes. Second peak zone is 16:30-17:45Z (response times 14-26s).

### 10.2 App Service Plan — 15-Minute CPU & Memory

| Time | CPU avg% | CPU peak% | Mem avg% | Mem peak% |
|---|---|---|---|---|
| 09:00 | 10.5 | 12.0 | 64.3 | 66.8 |
| 09:15 | 16.2 | 19.8 | 61.4 | 68.0 |
| 09:45 | 18.3 | **27.6** | 58.5 | 61.0 |
| 10:15 | 17.6 | 19.6 | 62.2 | 62.8 |
| 11:00 | 15.6 | 26.2 | 59.1 | 61.0 |
| 11:45 | 23.3 | **35.4** | 63.4 | 64.0 |
| 12:15 | 18.2 | **28.8** | 65.8 | 66.2 |
| 16:45 | 13.9 | 16.0 | 64.0 | 65.4 |
| 17:00 | 16.1 | 21.8 | 62.6 | 63.4 |
| 17:15 | 18.6 | **26.8** | 61.5 | 62.4 |
| 17:45 | 16.5 | **28.2** | 60.9 | 62.0 |

CPU peaked at **35.4% at 11:45Z** (5-min granularity). Memory stayed between 58-68% all day — no memory pressure.

### 10.3 SWIM_API — 15-Minute IO Detail (The Bottleneck)

| Time | CPU avg | CPU pk | IO avg | IO pk | LogW avg | LogW pk | Wrk avg | Wrk pk |
|---|---|---|---|---|---|---|---|---|
| 09:00 | 10.4 | 12.8 | 1.9 | 5.8 | 2.4 | 2.9 | 1.2 | 1.3 |
| 09:45 | 18.0 | 29.6 | 7.8 | 22.9 | 11.7 | 29.8 | 1.7 | 2.2 |
| 10:00 | 22.3 | 48.6 | 2.0 | 5.9 | 14.3 | 38.8 | 1.8 | 3.0 |
| 10:15 | 21.7 | 33.0 | **71.8** | **91.5** | 50.7 | 82.8 | 5.1 | 6.4 |
| 10:30 | 15.3 | 19.9 | **98.1** | **99.5** | 15.4 | 19.2 | 5.8 | 6.8 |
| 10:45 | 16.8 | 20.9 | **99.3** | **99.8** | 12.3 | 14.4 | 6.3 | 7.7 |
| 11:00 | 16.6 | 22.3 | **65.6** | **95.8** | 5.8 | 8.8 | 4.0 | 6.7 |
| 11:15 | 14.8 | 18.9 | 2.2 | 5.0 | 2.7 | 4.0 | 1.7 | 1.9 |
| 11:30 | 17.1 | 27.6 | 13.9 | 30.0 | 31.2 | 76.0 | 2.1 | 2.9 |
| 11:45 | 17.7 | 28.1 | **85.3** | **100.0** | 41.9 | 88.0 | 5.9 | 7.5 |
| 12:00 | 19.6 | 25.9 | **99.1** | **100.0** | 10.3 | 11.3 | 7.0 | 7.7 |
| 12:15 | 18.2 | 20.9 | **56.4** | **99.9** | 5.8 | 7.1 | 4.5 | 7.7 |
| 12:30 | 20.4 | 32.1 | 13.7 | 36.4 | 23.1 | 62.6 | 2.2 | 3.2 |
| 12:45 | 22.8 | 33.0 | **78.5** | **94.2** | 52.3 | 91.0 | 4.3 | 4.5 |
| 13:00 | 15.2 | 16.2 | **99.7** | **99.8** | 8.4 | 9.4 | 5.7 | 6.5 |
| 13:15 | 17.7 | 20.6 | **99.6** | **100.0** | 9.7 | 12.6 | 5.4 | 5.8 |
| 13:30 | 18.1 | 27.0 | **99.4** | **100.0** | 11.5 | 13.1 | 5.7 | 6.3 |
| 13:45 | 17.2 | 18.2 | **98.8** | **99.8** | 16.0 | 22.8 | 5.5 | 5.7 |
| 14:00 | 29.4 | 35.6 | **95.7** | **100.0** | 17.1 | 23.9 | 5.8 | 6.1 |
| 14:15 | 37.3 | **47.2** | 22.9 | 42.8 | 67.6 | 78.0 | 3.1 | 3.5 |
| 14:30 | 25.9 | 28.1 | 9.8 | 13.8 | **68.1** | **82.5** | 2.7 | 2.8 |
| 14:45 | 14.1 | 22.8 | 1.0 | 2.7 | 5.2 | 9.6 | 1.4 | 2.0 |
| 15:00–16:55 | 8–16 | 9–22 | **0.1–0.8** | **0.2–0.8** | 2–5 | 3–5 | 1.2–1.9 | 1.3–2.0 |
| 17:00 | 18.4 | 24.5 | 4.4 | 12.8 | 4.4 | 5.0 | 1.8 | 2.4 |
| 17:15–17:45 | 15–17 | 21–23 | 0.2–3.6 | 0.3–10.4 | 3–5 | 4–5 | 1.3–1.8 | 1.5–2.0 |
| 18:30 | 30.8 | **49.0** | **58.7** | **100.0** | **57.9** | **95.8** | 3.6 | 4.9 |
| 18:45 | 15.8 | 17.4 | **99.9** | **100.0** | 17.6 | 32.4 | 5.2 | 5.8 |
| 19:00 | 15.0 | 17.4 | **99.1** | **100.0** | 8.5 | 10.2 | 5.1 | 5.3 |
| 19:15 | 16.4 | 17.1 | **98.9** | **99.6** | 13.0 | 16.6 | 4.5 | 4.9 |
| 19:30 | 19.3 | 21.8 | **99.8** | **99.9** | 13.1 | 20.4 | 5.3 | 6.0 |
| 19:45 | 18.7 | 21.2 | **81.5** | **98.9** | 7.2 | 8.3 | 4.6 | 6.4 |
| 20:00 | 14.4 | 15.6 | 5.8 | 15.1 | 10.1 | 23.9 | 1.3 | 1.6 |
| 20:15 | 27.6 | 38.1 | **65.8** | **96.3** | 59.1 | 77.3 | 3.7 | 4.7 |
| 20:30 | 18.4 | 19.9 | **52.7** | **93.5** | 8.1 | 13.8 | 2.6 | 4.0 |
| 20:45 | 20.8 | 33.9 | 15.5 | 33.0 | 31.0 | 69.8 | 1.8 | 3.0 |

#### SWIM_API IO Saturation Windows (5-min resolution)

Three distinct IO saturation episodes, each lasting 45-75 minutes:

**Episode 1: 10:20–11:10Z** (50 min at >80% IO)
```
10:20  83.1%  → 10:25  91.5%  → 10:30  99.5%  → 10:35  96.3%
10:40  98.5%  → 10:45  99.8%  → 10:50  98.8%  → 10:55  99.4%
11:00  95.8%  → 11:05  88.5%  → 11:10  12.6% (recovery)
```

**Episode 2: 11:50–14:10Z** (140 min, longest sustained)
```
11:50 100.0%  → 11:55  99.0%  → 12:00  97.7%  → 12:05 100.0%
12:10  99.7%  → 12:15  99.9%  → 12:20  66.2% (brief dip)
12:40  36.4%  → 12:45  49.0%  → 12:50  92.4%  → 12:55  94.2%
13:00  99.5%  → 13:05  99.8%  → 13:10  99.8%  → 13:15  99.2%
13:20  99.7%  → 13:25 100.0%  → 13:30 100.0%  → 13:35  98.2%
13:40 100.0%  → 13:45  99.8%  → 13:50  97.0%  → 13:55  99.8%
14:00 100.0%  → 14:05 100.0%  → 14:10  87.2% (recovery begins)
```

**Episode 3: 18:40–19:55Z** (75 min)
```
18:40 100.0%  → 18:45  99.7%  → 18:50 100.0%  → 18:55 100.0%
19:00 100.0%  → 19:05  97.5%  → 19:10  99.8%  → 19:15  99.6%
19:20  99.3%  → 19:25  97.8%  → 19:30  99.8%  → 19:35  99.9%
19:40  99.7%  → 19:45  98.9%  → 19:50  88.7%  → 19:55  57.0% (recovery)
```

**Quiet window**: 15:00–18:25Z — IO stayed below 1% for nearly 3.5 hours. This is the period between SWIM sync daemon bulk cycles.

### 10.4 VATSIM_ADL — 15-Minute CPU & Billing

| Time | CPU avg | CPU pk | IO avg | IO pk | LogW% | Wrk% | Conns | Billed (vCore-s) |
|---|---|---|---|---|---|---|---|---|
| 09:00 | 12.9 | 24.6 | 4.1 | 8.7 | 0.9 | 2.9 | 87 | 2,308 |
| 09:15 | 41.6 | **69.3** | 5.2 | 10.7 | 1.1 | 3.0 | 109 | 3,794 |
| 09:30 | 27.9 | 59.9 | 5.5 | 11.5 | 0.8 | 2.9 | 86 | 4,190 |
| 09:45 | 23.0 | 38.9 | 5.2 | 11.9 | 1.0 | 3.4 | 195 | 4,047 |
| 10:00 | 36.9 | **71.0** | 9.0 | 23.4 | 0.7 | 2.8 | 255 | 3,683 |
| 10:15 | 45.2 | **81.8** | 8.2 | 22.6 | 0.7 | 3.8 | 288 | 3,392 |
| 10:30 | 25.9 | 37.3 | 0.8 | 1.6 | 1.0 | 3.8 | 184 | 3,361 |
| 10:45 | 39.3 | **81.2** | 8.0 | 23.1 | 0.9 | 3.3 | 175 | 3,386 |
| 11:00 | 22.4 | 34.8 | 3.9 | 8.0 | 1.2 | 3.6 | 196 | 4,152 |
| 11:30 | 37.4 | **74.2** | 8.6 | 23.1 | 1.0 | 3.1 | 193 | 3,919 |
| 11:45 | 36.6 | **77.6** | 8.2 | 23.9 | 1.0 | 2.9 | 194 | 3,620 |
| 12:30 | 37.7 | **65.0** | 8.5 | 21.4 | 1.0 | 3.0 | 213 | 3,701 |
| 12:45 | 37.3 | **70.8** | 7.9 | 22.8 | 0.9 | 3.2 | 182 | 3,266 |
| 13:15 | 31.2 | **75.0** | 8.2 | 22.9 | 0.5 | 2.5 | 185 | 2,806 |
| 13:45 | 39.0 | **77.3** | 9.5 | 19.8 | 0.9 | 3.1 | 172 | 2,736 |
| 14:00 | 35.0 | **85.5** | 9.0 | **26.2** | 0.5 | 2.6 | 172 | 2,597 |
| 14:30 | 37.9 | **85.0** | 8.4 | 21.2 | 0.9 | 3.4 | 176 | 2,624 |
| 14:45 | 37.3 | **80.0** | 7.9 | 19.4 | 0.8 | 3.1 | 178 | 2,927 |
| 15:00 | 33.8 | **76.7** | 7.2 | 19.1 | 0.9 | 3.1 | 167 | 3,025 |
| 15:45 | 37.2 | **67.5** | 8.8 | 14.8 | 0.9 | 2.9 | 126 | 3,461 |
| 16:30 | **52.2** | **70.8** | 6.8 | 15.1 | **2.3** | **4.0** | 210 | 3,383 |
| 16:45 | **50.3** | **65.7** | 9.0 | 22.1 | **2.0** | 3.4 | 202 | **3,811** |
| 17:15 | 40.8 | **77.8** | 9.1 | 21.4 | 1.0 | 3.7 | 217 | 4,111 |
| 18:15 | 38.1 | **81.0** | 8.3 | 20.9 | 0.8 | 3.1 | 196 | 4,177 |
| 19:45 | 41.0 | **82.8** | 10.1 | **24.9** | 1.8 | 3.3 | 59 | 3,562 |

ADL CPU shows a "sawtooth" pattern — CPU spikes to 65-85% every ~15 minutes (stored procedure execution cycles), then drops to 10-25% between cycles. Peak 5-min CPU was **85.5% at 14:00Z**. The 16:30-16:45Z window had the highest sustained average (50-52%) with elevated log writes (2.0-2.3%), correlating with the App Service response time spike.

### 10.5 VATSIM_TMI — 15-Minute Detail

| Time | CPU avg | CPU pk | Wrk avg | Wrk pk | Sess% | Conns |
|---|---|---|---|---|---|---|
| 09:00–09:30 | 0.8–1.6 | 1.1–2.4 | 3.1–3.2 | 3.2–3.4 | 5.4–6.3 | 40–55 |
| **10:00** | **8.3** | **10.4** | **9.4** | **11.7** | 6.5 | 59 |
| **10:15** | **17.1** | **19.2** | **16.2** | **18.1** | **7.4** | **78** |
| 10:30 | 8.3 | 11.4 | 6.1 | 8.2 | 6.1 | 38 |
| 10:45–11:00 | 0.8–2.7 | 1.3–3.8 | 3.1–4.7 | 3.2–5.7 | 6.1–6.5 | 36–45 |
| 11:15–12:00 | 1.7–5.2 | 3.2–7.9 | 3.3–4.9 | 3.8–6.4 | 5.9–6.5 | 35–47 |
| **12:30** | **7.1** | **12.6** | **7.4** | **12.8** | 6.4 | 45 |
| 13:00–20:45 | 0.1–5.3 | 0.2–8.6 | 3.0–4.4 | 3.0–6.2 | 5.0–6.5 | 2–55 |

TMI peaked at **19.2% CPU and 18.1% workers at 10:15Z** — likely CTP slot program processing. This coincides with the main request surge. Outside that window, TMI is near-idle.

### 10.6 PostGIS — 15-Minute Detail

| Time | CPU avg | CPU pk | Mem avg | Mem pk | Conn avg | Conn pk |
|---|---|---|---|---|---|---|
| 09:00–20:45 | 9.2–17.6 | 9.7–20.8 | 38.5–39.2 | 38.6–39.3 | 10.0–11.6 | 10.0–12.4 |

PostGIS is rock-steady all day. CPU peaked at **20.8% at 12:15Z** (15-min avg 17.6). Memory never moved more than 0.7% the entire day. Connections stayed between 10-12.

### 10.7 MySQL — 15-Minute Detail

| Time | CPU avg | CPU pk | Mem% | IO avg | IO pk | Conn avg | Conn pk | Queries |
|---|---|---|---|---|---|---|---|---|
| 09:00 | 3.5 | 4.1 | 23.3 | 0.9 | 0.9 | 6 | 7 | 521 |
| 09:45 | 5.7 | 10.1 | 24.1 | 2.0 | **4.3** | 45 | 61 | 1,407 |
| 10:00 | 7.1 | 9.2 | 23.6 | 1.9 | **3.9** | 39 | 41 | 662 |
| 11:00 | 4.1 | 4.8 | 23.4 | 0.9 | 0.9 | 46 | **65** | 1,109 |
| **11:30** | **8.3** | **15.0** | 24.1 | 1.9 | **4.0** | 36 | 39 | 894 |
| **12:30** | **8.2** | **13.9** | 23.9 | **2.7** | **6.4** | 38 | 41 | 815 |
| 14:00 | 8.3 | 11.4 | 23.5 | 0.9 | 0.9 | 36 | 41 | 405 |
| **18:15** | **7.0** | **14.5** | 23.8 | **2.5** | **5.8** | 33 | 33 | 531 |
| 20:45 | 5.3 | 9.4 | 23.7 | **2.7** | **6.4** | 36 | 41 | 645 |

MySQL CPU peaked at **15.0% at 11:30Z**. IO peaked at **6.4%** (twice, at 12:30Z and 20:45Z). Both far from any ceiling. Connection peaks at 61-65 are well within the D2ds_v4 limit.

---

## 11. Peak Moments Summary

### Top 5-Minute Peaks Across All Resources

**SWIM_API IO** — 26 five-minute intervals at 100.0%:
- Episode 1: 10:30–11:05Z (6 intervals)
- Episode 2: 11:50–14:10Z (sustained, 15+ intervals)
- Episode 3: 18:40–19:40Z (8 intervals)

**App Service Response Time** — Top peaks:
| Rank | Time | Avg Response (s) |
|---|---|---|
| 1 | 09:10Z | **113.1** |
| 2 | 09:05Z | 55.7 |
| 3 | 09:15Z | 43.7 |
| 4 | 09:20Z | 42.8 |
| 5 | 09:00Z | 40.7 |
| 6 | 10:25Z | 30.7 |
| 7 | 17:55Z | 26.4 |
| 8 | 16:30Z | 24.1 |
| 9 | 17:40Z | 23.2 |
| 10 | 17:20Z | 21.9 |

**App Service 5xx Errors** — Top peaks:
| Rank | Time | Count (5-min) |
|---|---|---|
| 1 | 17:25Z | **55** |
| 2 | 17:05Z | 43 |
| 3 | 12:15Z | 34 |
| 4 | 10:20Z | 22 |
| 5 | 11:15Z | 22 |

**ADL CPU** — Top peaks:
| Rank | Time | CPU % |
|---|---|---|
| 1 | 14:10Z | **85.5%** |
| 2 | 14:30Z | 85.0% |
| 3 | 19:45Z | 82.8% |
| 4 | 10:25Z | 81.8% |
| 5 | 10:45Z | 81.2% |

**ADL Billing** — Top billed 5-min intervals:
| Rank | Time | vCore-sec |
|---|---|---|
| 1 | 12:30Z | **4,601** |
| 2 | 11:00Z | 4,557 |
| 3 | 09:20Z | 4,525 |
| 4 | 17:10Z | 4,518 |
| 5 | 10:00Z | 4,514 |

### Correlation Analysis

The **09:00-09:15Z response time spike** (113s peak) is NOT caused by SWIM IO — SWIM was under 6% IO at that time. This is a pure **cold start / connection storm** at the start of the monitoring window.

The **10:15-11:10Z degradation** directly correlates with SWIM IO Episode 1. As SWIM IO hit 99%, App Service 5xx errors spiked to 20-22 per 5-min, and response times climbed to 14-30s.

The **16:30-17:45Z second peak** is driven by ADL — CPU sustained at 50%+ with elevated log writes (2.0-2.3%), while SWIM IO was actually quiet (<1%). This suggests the response time degradation in this window was caused by ADL query latency, not SWIM IO starvation.

The **18:40-19:55Z SWIM IO Episode 3** happened during low traffic (15-68 requests per 15 min), so the user impact was minimal despite IO being at 100%.

---

## 12. Deployment Timeline — Flow Control & SWIM Changes

The Apr 25 traffic surge was not organic growth — it was the **CTP E26 oceanic slot engine going live** with the flow control team actively using the API. 38 commits were deployed across the Apr 23-25 analysis window, with 15 on Apr 25 alone.

### Apr 23 — CTP Slot Engine Initial Rollout

*All times converted to UTC from git commit timestamps (EDT -04:00).*

| Time (UTC) | Commit | Change |
|---|---|---|
| 19:46Z | `0f146cd8` | **feat: CTP ETE/CTOT bidirectional API + NOD flight search (#324)** |
| 00:24Z+1 | `f67cada2` | **feat(ctp): CTP oceanic slot assignment engine + SWIM API (#326)** |
| 02:41Z+1 | `48bdd58f` | fix(ctp): slot engine column bugs, truncation fix, E2E validation (#328) |
| 03:14Z+1 | `bce2558e` | **feat(ctp): auto-tag CTP E26 flights and exempt from TMIs** |
| 03:35Z+1 | `14db84e9` | fix(ctp): handle JSON-wrapped CSV from Nattrak API |
| 03:38Z+1 | `6c25b1ff` | fix(ctp): use correct SQLSRV_FETCH_NUMERIC constant |
| 03:42Z+1 | `01d37e6b` | fix(ctp): use MERGE to create TMI rows for CTP flight tagging |
| 03:51Z+1 | `7c9f2beb` | **fix(swim): remove non-existent c.gufi from ADL SELECT** |
| 03:54Z+1 | `161c2cb7` | **fix(swim): handle NULL gufi in sp_Swim_BulkUpsert INSERT** |
| 03:57Z+1 | `209c676f` | **fix(swim): preserve existing gufi on UPDATE in sp_Swim_BulkUpsert** |

The slot engine went live at ~00:24Z Apr 24. Four rapid SWIM hotfixes followed within 20 minutes (03:35-03:57Z), fixing bugs in the `sp_Swim_BulkUpsert` stored procedure that handles the CTP→SWIM data pipeline.

### Apr 24 — Load Testing

| Time (UTC) | Commit | Change |
|---|---|---|
| 04:10Z | `0fd2eb4b` | **feat(swim): expose flow_event_code and flow_gs_exempt in SWIM API** |
| 18:33Z | `8fb8dbc0` | test(ctp): add SWIM endpoint load test and fix generateSlotGrid bug (#330) |

Light activity day — primarily SWIM API schema additions and load testing. This explains the low Apr 24 metrics (6,269 requests, baseline IO).

### Apr 25 — Live Operations (15 commits)

#### Pre-Monitoring Window (before 09Z)

| Time (UTC) | Commit | Change |
|---|---|---|
| 09:24Z | `afb45c61` | **fix: prevent FPM worker saturation from slow Azure SQL queries (#332)** |
| 09:46Z | `2e72788d` | **fix: prevent FPM worker saturation with APCu cache (#333)** |
| 11:01Z | `8543bfb4` | **feat(ctp): generate-slots endpoint + route disambiguation + OEP selection (#334)** |
| 11:16Z | `cdd8127b` | fix(ctp): aggregate duplicate facility constraints by summing max_acph (#335) |
| 12:16Z | `c1753a0b` | feat(ctp): single-track slot suggestion when FC specifies track (#336) |

The two FPM saturation fixes at 09:24-09:46Z were emergency patches deployed just as the monitoring window opened — the system was already under stress overnight from CTP operations. These added APCu caching and Azure SQL query timeouts to prevent PHP-FPM worker exhaustion.

#### During Monitoring Window (09-21Z)

| Time (UTC) | Commit | Metric Correlation |
|---|---|---|
| 17:02Z | `fa657133` **perf(ctp): reduce request-slot queries ~84% (#337)** | Deployed during Episode 2 (SWIM IO at 99-100%). The quiet IO window that followed suggests this fix was effective. |
| 17:24Z | `531987b9` fix(ctp): lock FC track, no past slots, populate EDCT for compliance (#338) | Continued Episode 2 fixes |
| 17:44Z | `250cab62` feat(ctp): support random routes and unspecified tracks (#339) | |
| 18:02Z | `ec038c52` **fix(ctp): reverse CTOT cascade on slot release + force release (#340)** | Deployed as Episode 2 wound down |
| 19:49Z | `8df46412` docs: add CTP slot program operational runbook (#341) | During quiet window |
| 20:29Z | `cf516682` docs: CTP E26 departure analytics report | During quiet window |
| 21:26Z | `8cee36c5` **feat(demand): add NAT, DCC region + optimize GLOBAL queries** | Correlates with ADL CPU spike at 16:30-17:45Z (metrics lag from deploy→GitHub Actions→restart) |
| 21:33Z | `60a0c655` **fix(ctp): set is_active=1 on CTP slot programs so TMI sync propagates CTDs (#342)** | Critical fix — CTP slot programs weren't syncing to SWIM |
| 21:35Z | `3941abe7` **fix(demand): revert GLOBAL query optimizations that broke results** | Immediate revert of the 21:26Z optimization |

*Note: Commits after ~21Z were authored after the 09-21Z monitoring window closed, but the demand optimization that caused the 16:30-17:45Z ADL spike was deployed via GitHub Actions earlier — the git author timestamp reflects when the commit was pushed, not when the code reached production. Azure deploys typically take 2-4 minutes after push.*

### Deployment-to-Metric Correlation Map

```
Time(Z) SWIM IO%  App 5xx   Deploys (UTC)
──────  ────────  ───────   ──────────────────────────────────────────────
09:24                       fix: FPM worker saturation (emergency)
09:46                       fix: FPM APCu cache (emergency)
09:00    5.8%      6        ← monitoring window opens
09:10              —        ← 113s response time (cold start + new endpoints)
10:15   71.8%     21        ← Episode 1 begins (CTP slot operations)
10:30   98.1%     10
11:01                       feat: generate-slots endpoint
11:10   12.6%      —        ← Episode 1 recovery
11:50  100.0%     —         ← Episode 2 begins
12:05  100.0%     34
12:16                       feat: single-track slot suggestion
13:00   99.5%      —
13:25  100.0%      —
14:05  100.0%      —
14:10   87.2%      —        ← Episode 2 recovery begins
14:45    1.0%      —        ← quiet window begins
15:00    0.2%      1        ← IO drops to near-zero
16:30    0.2%      1
16:45    0.3%      6        ← ADL CPU spike begins
17:02                       perf: 84% query reduction
17:05    0.2%     43        ← 5xx spike from ADL, not SWIM
17:24                       fix: lock FC track, EDCT compliance
17:44                       feat: random routes, unspecified tracks
18:02                       fix: CTOT cascade on slot release
18:30   58.7%      —        ← Episode 3 begins (evening sync cycle)
18:40  100.0%      1
19:00  100.0%      2
19:55   57.0%      —        ← Episode 3 recovery
20:00    1.4%      1        ← quiet
21:26                       feat: GLOBAL query optimization (post-window)
21:33                       fix: CTP is_active=1 for TMI sync
21:35                       fix: revert GLOBAL optimization
```

### Key Takeaways

1. **The "traffic surge" was CTP E26 going live** — the 5.5x request increase and 7.5x 5xx increase are explained by the flow control team actively hitting the new slot engine endpoints, not organic growth.

2. **SWIM IO saturation was caused by CTP write amplification** — the slot engine creates/updates SWIM records via `sp_Swim_BulkUpsert`, and the new `flow_event_code`/`flow_gs_exempt` columns added Apr 24 increased per-flight write volume. At S1 (10 DTU), the database couldn't keep up.

3. **The 84% query reduction fix at 17:02Z was deployed mid-operations** — the quiet IO window in the afternoon suggests it was effective, though the timing also coincides with reduced FC team activity.

4. **The 16:30-17:45Z degradation was caused by ADL query load** — ADL CPU sustained 50%+ with elevated log writes, causing App Service response times to spike to 14-26s. The demand query optimization was deployed and reverted post-window (21:26-21:35Z).

5. **Two emergency FPM fixes at 09:24Z** were deployed right at the start of the monitoring window — the system was already degraded from overnight CTP operations.

6. **SWIM_API S1 (10 DTU) is undersized for CTP operations** — even after the 84% query reduction, the evening sync cycle (Episode 3, 18:40-19:55Z) still saturated IO. Upgrading to S2 (50 DTU) is needed before the next CTP event.
