# ETA Calculation Accuracy Analysis: January - March 2026

**Generated**: 2026-03-12
**Data Source**: VATSIM_ADL production database (`adl_flight_times`, `adl_flight_plan`, `adl_flight_core`)
**Methodology**: Predicted ETA (`eta_utc`) vs Actual Time of Arrival (`ata_utc`), excluding outliers >2 hours

---

## Executive Summary

ETA accuracy improved **dramatically** over the January-March 2026 period, driven by a complete rewrite of the ETA calculation engine (V3 to V35), introduction of route-based distance calculations, and segment-level wind adjustments.

| Metric | January | February | March (1-12) |
|--------|---------|----------|--------------|
| **Flights analyzed** | 250,448 | 275,136 | 110,606 |
| **Mean Absolute Error** | 19.9 min | 5.7 min | 5.1 min |
| **Within 5 min** | 53.2% | 76.7% | 83.1% |
| **Within 10 min** | 77.6% | 93.5% | 94.2% |
| **Within 15 min** | 80.2% | 95.8% | 96.4% |
| **Mean Bias** | -14.6 min (early) | -3.6 min (early) | -3.4 min (early) |
| **ETA Coverage** | 85.6% | 94.6% | 97.0% |
| **High Confidence (>=0.95)** | 67.2% | 98.0% | 97.2% |

**Key achievement**: MAE reduced from **19.9 minutes to 5.1 minutes** (74% improvement). Within-5-minute accuracy rose from 53% to 83%.

---

## 1. Timeline of Backend Changes

The ETA system underwent three major phases of evolution during this period.

### Phase 1: Foundation ETA System (Early January, Weeks 2-3)

**Deployed**: ~Jan 6-15, 2026

| Change | Impact |
|--------|--------|
| ETA & Trajectory calculation system (`sp_CalculateETA`, `sp_ProcessTrajectoryBatch`) | Established baseline V3 method |
| Aircraft performance profiles (`fn_GetAircraftPerformance`) | BADA-derived climb/cruise/descent profiles |
| ETA batch processing (`sp_CalculateETABatch`) | Enabled bulk ETA calculation |
| Route distance tracking (`fn_CalculateRouteDistanceRemaining`) | Route-parsed distances instead of GCD only |
| SimBrief data parsing integration | Pilot-filed performance data (V3_SB, V3_ROUTE_SB methods) |

**Accuracy during Week 2** (first week of data): MAE = **38.4 min**, only 16.5% within 5 minutes.

### Phase 2: V35 Algorithm + Route Integration (Mid-January, Week 3-4)

**Deployed**: ~Jan 16, 2026 (transition visible in data)

The V3 methods were replaced by V35 — a fundamentally improved algorithm with:
- Better ground speed sampling and smoothing
- Improved phase-of-flight detection
- Route-distance-aware calculations (V35_ROUTE)
- Tiered confidence scoring

**Method transition timeline** (from actual data):

| Date | V3 | V3_ROUTE | V35 | V35_ROUTE |
|------|-----|----------|-----|-----------|
| Jan 10 | 6,482 | 0 | 0 | 0 |
| Jan 13 | 282 | 2,703 | 0 | 0 |
| Jan 15 | 1,333 | 7,711 | 0 | 0 |
| **Jan 16** | **90** | **677** | **1,327** | **6,982** |
| Jan 17 | 0 | 0 | 2,562 | 10,693 |
| Jan 18+ | 0 | 0 | 5,860+ | 8,065+ |

**Impact**: Within-5-minute accuracy jumped from 37.7% (Week 3) to **71.0%** (Week 4) — a near-doubling.

### Phase 3: Segment Wind Adjustments (Late January, Week 5+)

**Deployed**: ~Jan 27, 2026

Introduction of `V35_SEG_WIND` method — applies NOAA GFS wind data at route segment level rather than a single flight-level adjustment. Key components:
- Wind grid infrastructure (`wind/001-003` migrations)
- `sp_UpdateFlightWindAdjustments_V2` stored procedure
- Segment-level headwind/tailwind decomposition
- Tiered wind confidence (grid-based, GS-based cruise, GS-based other)

**V35_SEG_WIND adoption** appeared on Jan 27 at ~15% of flights, reached ~26% by Jan 28, and stabilized at 22-27% of flights through February-March.

---

## 2. Weekly Accuracy Trend

| Week | Dates | Flights | MAE (min) | Within 5min | Within 10min | Key Event |
|------|-------|---------|-----------|-------------|--------------|-----------|
| 2 | Jan 6-11 | 40,574 | **38.4** | 16.5% | 56.5% | V3 baseline deployed |
| 3 | Jan 12-18 | 68,293 | **14.4** | 37.7% | 81.5% | V3_ROUTE introduced, V35 transition begins |
| 4 | Jan 19-25 | 81,149 | **22.8** | 71.0% | 76.3% | V35+V35_ROUTE stabilizing |
| 5 | Jan 26-Feb 1 | 73,651 | **9.2** | 72.4% | 90.2% | V35_SEG_WIND introduced |
| 6 | Feb 2-8 | 69,208 | **6.3** | 74.8% | 93.0% | Wind adjustments maturing |
| 7 | Feb 9-15 | 69,209 | **5.4** | 78.5% | 93.9% | SP V9.2 deferred ETA processing |
| 8 | Feb 16-22 | 65,687 | **5.7** | 74.4% | 92.7% | SP V9.4 geography pre-computation |
| 9 | Feb 23-Mar 1 | 70,703 | **5.1** | 80.2% | 94.4% | Route distance V2.2 + covering index |
| 10 | Mar 2-8 | 66,717 | **5.1** | 83.7% | 94.3% | Stable period |
| 11 | Mar 9-12 | 31,023 | **5.3** | 81.8% | 93.6% | Post-hibernation (partial week) |

**Notable anomaly**: Week 4 shows higher MAE (22.8 min) than Week 3 despite V35 deployment. This is because V3 flights from Week 3 were disproportionately short-range (route-parsed), while Week 4 included the full mix of V35 flights still calibrating. By Week 5, the system had stabilized.

---

## 3. Accuracy by ETA Method

### January (mixed V3/V35 period)

| Method | Flights | MAE (min) | Within 5min | Within 10min |
|--------|---------|-----------|-------------|--------------|
| BATCH_V1 | 408 | 82.9 | 1.0% | 3.2% |
| V3 | 22,873 | 65.9 | 13.1% | 24.6% |
| V3_ROUTE | 14,915 | 11.7 | 16.1% | 76.2% |
| V3_ROUTE_SB | 35 | 82.6 | 0.0% | 8.6% |
| V3_SB | 6 | 97.9 | 0.0% | 0.0% |
| **V35** | **67,593** | **32.1** | **56.0%** | **64.5%** |
| **V35_ROUTE** | **97,539** | **8.3** | **76.3%** | **91.6%** |
| **V35_SEG_WIND** | **12,021** | **6.3** | **76.3%** | **94.9%** |

### February (V35-only period)

| Method | Flights | MAE (min) | Within 5min | Within 10min |
|--------|---------|-----------|-------------|--------------|
| V35 | 65,178 | 6.2 | 74.5% | 91.9% |
| V35_ROUTE | 140,306 | 5.5 | 76.1% | 93.7% |
| **V35_SEG_WIND** | **69,335** | **5.6** | **79.9%** | **94.8%** |

### March (1-12, mature system)

| Method | Flights | MAE (min) | Within 5min | Within 10min |
|--------|---------|-----------|-------------|--------------|
| V35 | 73,200 | 5.2 | 82.5% | 93.8% |
| V35_ROUTE | 10,186 | 4.8 | 81.2% | 94.8% |
| **V35_SEG_WIND** | **27,223** | **5.1** | **85.4%** | **95.2%** |

**Key finding**: V35_SEG_WIND consistently achieves the best within-5-minute accuracy (85.4% in March), confirming the value of segment-level wind corrections. All V35 variants now cluster within ~0.4 min MAE of each other, suggesting the core algorithm is the primary driver and wind is a refinement.

---

## 4. Accuracy by Distance Source

| Month | Source | Flights | MAE (min) | Within 5min |
|-------|--------|---------|-----------|-------------|
| **Jan** | ROUTE | 120,450 | **8.6** | 68.9% |
| Jan | GCD | 60,612 | 15.4 | 67.1% |
| Jan | UNKNOWN | 56,277 | 29.3 | 16.8% |
| Jan | NONE | 13,109 | 104.4 | 1.2% |
| **Feb** | ROUTE | 192,147 | **5.3** | 77.5% |
| Feb | GCD | 81,949 | 6.0 | 75.3% |
| Feb | NONE | 723 | 59.9 | 9.7% |
| Feb | UNKNOWN | 317 | 16.3 | 66.9% |
| **Mar** | ROUTE | 16,623 | **4.6** | 83.3% |
| Mar | GCD | 93,324 | 4.9 | 83.6% |
| Mar | NONE | 669 | 46.8 | 13.8% |

**Observations**:

1. Route-parsed distance was more accurate than GCD in January (8.6 vs 15.4 min MAE), but the gap has **closed completely** by March — GCD (4.9 min MAE, 83.6% within 5 min) is now essentially equivalent to ROUTE (4.6 min MAE, 83.3% within 5 min). This indicates the V35 algorithm's ground-speed sampling has improved GCD-based estimates to near-parity with route-parsed distances.

2. The **UNKNOWN** category — flights where the distance source wasn't recorded — was the second-largest group in January (56,277 flights, 22.5% of total) with poor 29.3 min MAE. By February this dropped to 317 flights, and by March it was eliminated entirely. This reflects the rollout of proper distance source tagging in the V35 methods.

3. The **NONE** category (no distance calculation at all) shrank from 13,109 to 669 flights (95% reduction), though remaining NONE flights still show 46.8 min MAE — these are likely unparseable routes or very short flights.

---

## 5. Confidence Score Correlation

| Month | Band | Flights | MAE (min) | Within 5min |
|-------|------|---------|-----------|-------------|
| **Jan** | 95-100% | 199,453 | 7.0 | 66.1% |
| Jan | 90-95% | 2,162 | 7.6 | 21.0% |
| Jan | 85-90% | 6,107 | 17.0 | 7.5% |
| Jan | 75-85% | 34,525 | 85.2 | 1.6% |
| Jan | <75% | 8,201 | 64.5 | 0.2% |
| **Feb** | 95-100% | 260,965 | 4.7 | 80.2% |
| Feb | 90-95% | 2,956 | 9.1 | 25.3% |
| Feb | 85-90% | 4,482 | 15.3 | 8.3% |
| Feb | 75-85% | 6,705 | 36.6 | 7.4% |
| Feb | <75% | 28 | 58.8 | 3.6% |
| **Mar** | 95-100% | 104,474 | 4.3 | 86.9% |
| Mar | 90-95% | 1,670 | 7.2 | 32.6% |
| Mar | 85-90% | 1,389 | 13.4 | 9.6% |
| Mar | 75-85% | 3,088 | 29.2 | 16.5% |

**Conclusion**: Confidence scores are well-calibrated. High-confidence (>=95%) flights achieve 4.3 min MAE with 86.9% within 5 minutes in March. Lower confidence bands correctly predict worse accuracy. The 75-85% band still shows 29.2 min MAE — these are typically pre-filed or early-phase flights where ETA is inherently uncertain.

The proportion of flights in the high-confidence band grew from 67.2% to 97.2% (measured across all ETA-having arrivals, per executive summary). Within the analyzable set (excluding >2hr outliers), the proportion is 79.6% in January rising to 94.4% in March — the difference reflects that many January outlier flights had low confidence, i.e., the system correctly flagged its worst predictions as unreliable.

---

## 6. Prediction Bias Analysis

| Month | Total | Arrived Late vs ETA | Arrived Early vs ETA | Mean Bias |
|-------|-------|--------------------|--------------------|-----------|
| Jan | 250,448 | 19,233 (7.7%) | 230,940 (92.2%) | **-14.6 min** |
| Feb | 275,136 | 17,438 (6.3%) | 256,982 (93.4%) | **-3.6 min** |
| Mar | 110,628 | 6,400 (5.8%) | 103,998 (94.0%) | **-3.4 min** |

**The system has a consistent early-arrival bias** — flights arrive earlier than predicted (negative bias = ATA before ETA). This means the system overestimates flight time. A -3.4-minute bias in March is operationally conservative and appropriate for flow management: slightly pessimistic ETAs are safer than optimistic ones for demand planning.

The bias improved dramatically from -14.6 min in January to -3.4 min in March — an 11-minute reduction. However, the ratio of early vs late predictions (94%/6%) has remained remarkably stable across all three months, suggesting the directional bias is systematic (likely from conservative descent/approach modeling) while the magnitude has been reduced by better speed estimation.

Note: Within-2-minute accuracy (not shown in other tables) improved from 2.0% in January to 15.5% in February to **23.1%** in March, showing continued tightening even within the "good" prediction band.

---

## 7. Wind Impact on Accuracy

| Month | Category | Flights | MAE (min) | Within 5min |
|-------|----------|---------|-----------|-------------|
| **Jan** | Calm/Light | 209,204 | 22.4 | 60.2% |
| Jan | Headwind | 4,662 | 14.9 | 11.0% |
| Jan | Tailwind | 1,524 | 14.2 | 26.8% |
| **Feb** | Calm/Light | 269,577 | 5.5 | 78.0% |
| Feb | Headwind | 4,414 | 11.9 | 9.4% |
| Feb | Tailwind | 828 | 18.4 | 11.7% |
| **Mar** | Calm/Light | 109,037 | 5.0 | 84.2% |
| Mar | Headwind | 1,447 | 10.9 | 10.4% |
| Mar | Tailwind | 149 | 11.5 | 10.1% |

**Wind remains the largest accuracy challenge.** Even with V35_SEG_WIND, flights with significant headwinds or tailwinds (>5 kts component) show ~11 min MAE vs ~5 min for calm conditions. Only ~10% of wind-affected flights achieve within-5-minute accuracy.

However, wind-affected flights are a small fraction of total traffic (1.4% in March), so they have minimal impact on aggregate metrics. The wind adjustment system is correctly **identifying** these flights (via `eta_wind_component_kts`) even if corrections aren't fully compensating.

---

## 8. Performance Optimizations (February)

While not directly changing ETA accuracy, several February changes improved **ETA freshness** (how frequently predictions update):

| Change | Date | Impact |
|--------|------|--------|
| Delta detection bitmask (SP V9.2) | ~Feb 7 | 30-40% fewer redundant recalculations |
| Deferred ETA processing (`@defer_expensive`) | ~Feb 9 | Decoupled ingest from ETA calc, saving ~800ms/cycle |
| Geography pre-computation (SP V9.4) | ~Feb 14 | ~12% faster cycles (eliminated 8,500 Point() CLR calls) |
| Route distance V2.2 set-based rewrite | ~Feb 25 | 25% total SP cycle reduction |
| Covering index on waypoints | ~Feb 28 | Step B: 1,643ms to 381ms |

**Net effect**: ETA recalculation frequency increased because cycles completed faster, meaning ETAs are more current at any given moment. This shows in the steady improvement from Week 7 (5.4 min MAE) to Week 9 (5.1 min MAE) without any algorithm changes.

---

## 9. Hibernation Impact (March 9-12)

The system entered hibernation briefly March 9-12, then was restored. Week 11 (Mar 9-12, partial) shows:
- MAE: 5.3 min (slight degradation from 5.1 min pre-hibernation)
- Within 5 min: 81.8% (vs 83.7% pre-hibernation)

This minor dip is expected: the backfill pipeline was reprocessing historical data, and ETA state for some flights needed to rebuild after the daemon restart. The system recovered to near-peak accuracy within hours of restoration.

---

## 10. Summary of Improvements

### What Worked Best

1. **V35 algorithm rewrite** (Jan 16): Single largest improvement. MAE dropped from 65.9 min (V3) to 8.3 min (V35_ROUTE) — an 87% reduction.

2. **Route-parsed distances** (Jan 13+): Using actual filed route distances instead of great-circle reduced MAE by ~40% for applicable flights.

3. **Segment wind adjustments** (Jan 27): V35_SEG_WIND achieves the highest within-5-minute accuracy (85.4%), a 3-point improvement over non-wind V35.

4. **Processing speed optimizations** (February): Faster cycles = fresher ETAs = better accuracy at query time.

5. **Confidence scoring calibration**: High-confidence predictions (95-100%) are genuinely accurate (4.3 min MAE, 86.9% within 5 min), giving users reliable quality indicators.

### Remaining Challenges

1. **Persistent early bias** (-3.4 min): Flights consistently arrive earlier than predicted. This is operationally conservative but could be reduced with better descent profile modeling.

2. **Wind-affected flights** (~11 min MAE): Significant headwinds/tailwinds still cause 2x the error of calm conditions. Only ~10% of wind-affected flights are within 5 minutes.

3. **Low-confidence flights** (75-85% band): 3,088 flights in March with 29.2 min MAE. These are mostly pre-filed or early-phase flights where ETA is inherently uncertain.

4. **ETA coverage gap**: 3% of arrivals still lack ETAs entirely (down from 14.4% in January). These are typically very short flights or flights with unparseable routes.

### Accuracy Trajectory

```
Jan Week 2:  MAE 38.4 min  |  16.5% within 5 min  |  V3 baseline
Jan Week 3:  MAE 14.4 min  |  37.7% within 5 min  |  V3_ROUTE introduced
Jan Week 4:  MAE 22.8 min  |  71.0% within 5 min  |  V35 transition (mixed methods)
Jan Week 5:  MAE  9.2 min  |  72.4% within 5 min  |  V35_SEG_WIND introduced
Feb Week 6:  MAE  6.3 min  |  74.8% within 5 min  |  Stabilizing
Feb Week 7:  MAE  5.4 min  |  78.5% within 5 min  |  SP optimizations begin
Feb Week 9:  MAE  5.1 min  |  80.2% within 5 min  |  Route distance V2.2
Mar Week 10: MAE  5.1 min  |  83.7% within 5 min  |  Peak accuracy
Mar Week 11: MAE  5.3 min  |  81.8% within 5 min  |  Post-hibernation
```

**Bottom line**: The ETA system went from a 38-minute average error to a 5-minute average error in 10 weeks — a **7.5x improvement** — with 83% of predictions now within 5 minutes of actual arrival. The system is operationally mature and the remaining accuracy gains will come from edge cases (wind, pre-filed flights, short hops).
