# Operational Analysis: January - March 2026

**Generated**: 2026-03-12
**Data Source**: VATSIM_ADL, VATSIM_TMI production databases
**Period**: 2026-01-01 through 2026-03-12 (1.29M flights)

---

## Table of Contents

1. [ARTCC & Sector Workload](#1-artcc--sector-workload)
2. [Day-of-Week & Time-of-Day Patterns](#2-day-of-week--time-of-day-patterns)
3. [Taxi Time Analysis](#3-taxi-time-analysis)
4. [Route Parse Success Rate](#4-route-parse-success-rate)
5. [System Performance](#5-system-performance)
   - [Wind Correction Effectiveness](#51-wind-correction-effectiveness)
   - [Processing Latency](#52-processing-latency)
   - [Waypoint & Boundary Accuracy](#53-waypoint--boundary-accuracy-data-gaps)

---

## 1. ARTCC & Sector Workload

### 1.1 Busiest Boundaries

35.9M boundary crossings were recorded across 654 ARTCC, 1,023 TRACON, and 1,310 sector boundaries.

**Top 15 ARTCCs by total crossings** (Jan-Mar 2026):

| Rank | ARTCC | Crossings | Unique Flights | Peak Hour (Feb) | Avg Hour (Feb) |
|------|-------|-----------|----------------|-----------------|----------------|
| 1 | **KZNY** (New York) | 431,737 | 61,119 | **208** | 96.5 |
| 2 | CZQM (Moncton) | 252,116 | 38,251 | 117 | 55.9 |
| 3 | CZQX (Gander) | 249,062 | 38,303 | 122 | 54.8 |
| 4 | LPPO (Lisbon Oceanic) | 163,867 | 45,989 | **179** | 91.8 |
| 5 | NAT (North Atlantic) | 148,201 | 47,922 | 149 | 76.7 |
| 6 | EGGX (Shanwick) | 137,761 | 45,837 | 153 | 72.1 |
| 7 | EDXX (Germany) | 129,692 | 63,169 | 150 | 47.9 |
| 8 | EGVV (London Military) | 129,063 | 55,112 | 118 | 55.5 |
| 9 | EGTT (London) | 121,520 | 51,859 | 126 | 51.0 |
| 10 | CZQO (Gander Oceanic) | 120,787 | 39,258 | 134 | 64.1 |
| 11 | KZBW (Boston) | 114,435 | 37,881 | 140 | 50.3 |
| 12 | LPPC (Santa Maria) | 113,013 | 31,519 | 135 | 59.0 |
| 13 | LFRR (Brest) | 108,819 | 36,321 | 120 | 53.1 |
| 14 | LECM (Madrid) | 107,068 | 32,987 | 123 | 54.0 |
| 15 | LFFF (Paris) | 105,566 | 36,910 | 105 | 46.1 |

**Key finding**: The North Atlantic corridor dominates — KZNY, CZQM, CZQX, LPPO, NAT, EGGX, CZQO, and LPPC collectively handle the transatlantic flow. KZNY's peak of 208 flights/hour is 30% higher than any other ARTCC.

### 1.2 Boundary Type Distribution

| Type | Crossings | Unique Boundaries | Unique Flights |
|------|-----------|-------------------|----------------|
| ARTCC | 10,974,194 | 654 | 283,006 |
| TRACON | 8,415,620 | 1,023 | 333,215 |
| LOW sectors | 6,443,440 | 532 | 145,468 |
| HIGH sectors | 6,396,982 | 532 | 144,804 |
| SUPERHIGH sectors | 3,735,179 | 246 | 128,010 |

TRACONs handle the most unique flights (333K) despite fewer crossings than ARTCCs, reflecting shorter transit times through terminal areas.

### 1.3 KZNY Hourly Profile (February)

KZNY shows a clear evening peak driven by North Atlantic westbound arrivals:

| Period (UTC) | Avg Flights/Hour | Pattern |
|--------------|-----------------|---------|
| 05:00-12:00Z | 2,211-2,641 | **Trough** — European morning departures haven't arrived yet |
| 13:00-17:00Z | 2,407-2,642 | **Building** — Eastbound departures + early westbound arrivals |
| 18:00-23:00Z | 2,821-3,111 | **Peak** — Westbound NAT arrivals flooding KZNY |
| 00:00-04:00Z | 2,742-3,037 | **Late peak** — Overnight transatlantic departures |

### 1.4 Day-of-Week Variation

Across the top 10 boundaries, **Monday is consistently the busiest day** and **Friday the quietest**:

| Boundary | Mon | Tue | Wed | Thu | Fri | Sat | Sun |
|----------|-----|-----|-----|-----|-----|-----|-----|
| KZNY | **6,855** | 6,415 | 6,000 | 6,074 | 5,612 | 6,306 | 6,045 |
| LPPO | **8,258** | 7,858 | 7,908 | 7,642 | 7,164 | 7,772 | 8,010 |
| NAT | **7,314** | 6,533 | 6,527 | 6,136 | 5,627 | 6,511 | 7,049 |
| EGGX | **6,937** | 6,195 | 6,314 | 5,889 | 5,317 | 6,172 | 6,692 |

Monday-to-Friday drop: ~20% (KZNY: 6,855 → 5,612). This aligns with VATSIM's leisure-pilot demographic — Sunday/Monday peaks from weekend flying, mid-week trough, Friday departure.

---

## 2. Day-of-Week & Time-of-Day Patterns

### 2.1 Weekly Shape

| Day | Flights | Avg Daily | vs Avg |
|-----|---------|-----------|--------|
| **Sunday** | 233,776 | **23,378** | +42% |
| **Saturday** | 230,494 | **23,049** | +40% |
| Friday | 184,779 | 18,478 | +12% |
| Thursday | 173,820 | 17,382 | +6% |
| Monday | 166,458 | 16,646 | +1% |
| Wednesday | 158,646 | 15,865 | -4% |
| Tuesday | 146,842 | 14,684 | **-11%** |

**Weekend vs Weekday**: 23,214 avg/day (weekend) vs 16,283 avg/day (weekday) — **43% more traffic on weekends**.

This pattern is stable across all 3 months, confirming VATSIM's leisure-driven traffic cycle. Tuesday is consistently the quietest day.

### 2.2 Hourly Profile (UTC)

Peak traffic occurs at **17:00-18:00Z** (1,163-1,302 flights/hour avg), with the global minimum at **04:00-05:00Z** (341-351 flights/hour).

| Hour (UTC) | Avg Flights/Day | Intensity | Primary Region |
|------------|----------------|-----------|----------------|
| 04-06Z | 341-394 | Low | Oceania ramping up, all others quiet |
| 07-09Z | 473-655 | Rising | Europe morning push begins |
| 10-13Z | 723-979 | High | Europe peak + US East waking up |
| 14-17Z | 1,052-1,163 | Very High | Europe afternoon + US morning |
| **18Z** | **1,302** | **Peak** | Europe evening rush + US afternoon |
| 19-20Z | 1,014-1,198 | High | US peak + late Europe |
| 21-23Z | 624-815 | Declining | US evening, Europe closing |
| 00-03Z | 413-589 | Low | US late night + South America |

### 2.3 Regional Hourly Profiles (February)

Each region has a distinct daily cycle:

| Region | Peak Hour (UTC) | Peak Flights | Off-Peak Hour | Off-Peak Flights | Ratio |
|--------|----------------|-------------|---------------|-----------------|-------|
| **Europe (E)** | 18Z | 11,915 | 03Z | 435 | **27:1** |
| **US (K)** | 22Z | 5,572 | 08Z | 834 | 6.7:1 |
| **Oceania (Y)** | 07Z | 1,442 | 14Z | 161 | 9.0:1 |
| **Canada (C)** | 23Z | 833 | 08Z | 74 | 11.3:1 |
| **S. America (S)** | 22Z | 1,610 | 05Z | 194 | 8.3:1 |

Europe has the sharpest peak-to-trough ratio (27:1), reflecting concentrated evening event flying. US traffic is more evenly distributed.

### 2.4 Daily Volume Range

- **Busiest day**: Saturday Jan 10 — **24,568 flights**
- **Quietest day**: Tuesday Feb 17 — **9,937 flights** (possibly event-related dip)
- **Typical Saturday**: 22,000-24,700
- **Typical Tuesday**: 14,700-17,400

---

## 3. Taxi Time Analysis

### 3.1 Network Overview

- **147,661 flights** with valid taxi-out data (OUT to OFF, 1-60 min)
- **Average taxi-out**: 8.6 min (stdev 6.9 min)
- Taxi reference coverage: **4,499 airports** (taxi), **6,704 airports** (connect-to-push)

### 3.2 Airports with Highest Excess Taxi Time

Excess taxi = observed taxi - unimpeded reference. Airports where pilots consistently taxi longer than baseline:

| Airport | Flights | Observed (min) | Reference (min) | Excess (min) | Stdev |
|---------|---------|---------------|-----------------|-------------|-------|
| YWLM (Williamtown) | 59 | 15.8 | 2.2 | **+13.6** | 17.9 |
| EGVN (Brize Norton) | 108 | 16.6 | 3.1 | **+13.5** | 15.6 |
| PANC (Anchorage) | 52 | 11.9 | 1.8 | **+10.1** | 12.8 |
| SBGR (Guarulhos) | 104 | 12.1 | 2.6 | **+9.6** | 14.5 |
| KCLT (Charlotte) | 138 | 12.1 | 3.4 | **+8.8** | 14.4 |
| LEMD (Madrid) | 1,107 | 10.1 | 3.4 | **+6.8** | 8.0 |
| KBOS (Boston) | 235 | 11.4 | 4.8 | **+6.6** | 9.7 |
| ENGM (Oslo) | 1,260 | 11.0 | 4.5 | **+6.5** | 7.0 |
| CYYZ (Toronto) | 234 | 11.7 | 5.2 | **+6.5** | 8.2 |
| YSSY (Sydney) | 1,939 | 9.8 | 3.5 | **+6.4** | 5.7 |

High-volume airports (>1,000 flights) with consistent excess: **LEMD** (+6.8 min, 1,107 flights), **ENGM** (+6.5 min, 1,260 flights), **YSSY** (+6.4 min, 1,939 flights).

### 3.3 Total Ground Delay by Airport

Airports generating the most cumulative excess taxi delay:

| Airport | Flights | Avg Taxi | Ref | Total Excess Delay (min) | % Delayed |
|---------|---------|----------|-----|-------------------------|-----------|
| **EDDF** (Frankfurt) | 4,835 | 9.9 | 4.0 | **29,099** | 90.0% |
| **EGLL** (Heathrow) | 4,202 | 10.5 | 4.2 | **27,128** | 90.2% |
| **EHAM** (Amsterdam) | 2,751 | 9.9 | 3.7 | **17,122** | 90.4% |
| **EDDM** (Munich) | 2,387 | 10.8 | 4.8 | **14,626** | 90.3% |
| **YSSY** (Sydney) | 1,939 | 9.8 | 3.5 | **12,608** | 90.4% |
| EGKK (Gatwick) | 2,030 | 9.3 | 3.4 | 12,116 | 90.3% |
| EGCC (Manchester) | 1,823 | 10.2 | 4.3 | 10,973 | 89.8% |
| EKCH (Copenhagen) | 1,885 | 9.5 | 4.1 | 10,336 | 90.6% |
| OMDB (Dubai) | 1,744 | 9.8 | 4.0 | 10,329 | 89.8% |
| EDDH (Hamburg) | 1,718 | 8.4 | 2.8 | 9,684 | 89.9% |

**90% of departures exceed the unimpeded reference** at all major airports — this is expected on VATSIM where pilots often take extra time at the gate or during pushback.

### 3.4 Taxi Time by Hour of Day

Network-wide taxi times show a clear time-of-day pattern:

| Period (UTC) | Avg Taxi (min) | Pattern |
|-------------|---------------|---------|
| 03-07Z | 7.3-7.8 | **Shortest** — off-peak, minimal taxi conflicts |
| 08-13Z | 7.5-8.8 | Rising with traffic |
| 14-18Z | 9.0-10.0 | **Building** — peak period approach |
| **19-20Z** | **10.1-10.2** | **Longest** — coincides with 18Z traffic peak |
| 21-23Z | 8.2-9.1 | Declining |

Taxi time at peak (10.2 min) is **39% longer** than off-peak (7.3 min).

### 3.5 Connect-to-Push Reference

Top airports by connect-to-push reference time (gate time before pushback):

| Airport | Connect Time (min) | Sample Size |
|---------|-------------------|-------------|
| KJFK | 21.9 | 5,459 |
| KDFW | 20.4 | 6,050 |
| KLAX | 19.8 | 7,530 |
| KATL | 18.8 | 6,598 |
| EDDF | 18.5 | 21,050 |
| KMIA | 18.4 | 5,678 |
| EDDM | 18.4 | 11,687 |
| EKCH | 17.6 | 7,564 |
| EGLL | 17.2 | 16,611 |
| EGKK | 17.1 | 10,137 |

US airports have longer connect times (19-22 min) vs European airports (15-18 min), likely reflecting longer pre-departure preparation.

---

## 4. Route Parse Success Rate

### 4.1 Overall Distribution

| Status | Flights | Percentage |
|--------|---------|------------|
| **COMPLETE** | 942,576 | **73.0%** |
| PENDING | 245,551 | 19.0% |
| NULL | 92,971 | 7.2% |
| PARTIAL | 7,831 | 0.6% |
| FAILED | 1,561 | 0.1% |
| NO_ROUTE | 144 | 0.01% |

**73% parse rate** for the full period, but this improved significantly month-over-month:

| Month | COMPLETE | PENDING | PARTIAL | FAILED |
|-------|----------|---------|---------|--------|
| January | 390,546 (67.6%) | 90,696 (15.7%) | 3,378 | 596 |
| February | 420,870 (82.0%) | 87,286 (17.0%) | 3,485 | 782 |
| March (1-12) | 130,589 (65.3%) | 68,050 (34.1%) | 964 | 183 |

February had the best parse rate at 82%. The March dip to 65% reflects the hibernation period where PENDING routes accumulated without the parse daemon processing them.

### 4.2 Failure Patterns

**All 1,561 FAILED routes share common characteristics:**

| Pattern | Description | Examples |
|---------|-------------|---------|
| **VFR flights** | Visual flight rules — no IFR route to parse | `VFR`, `VFR ALPS`, `VFR FLIGHT` |
| **DCT-only** | Direct routing with no waypoints | `DCT` |
| **Same-airport** | Departure = arrival (local flights) | ULLY→ULLY, EPKX→EPKX |
| **ZZZZ airports** | Unknown/custom airports | ZZZZ→ZZZZ, LOJO→ZZZZ |
| **Local patterns** | Pattern work and training | `LOCAL`, `ELUS` |
| **Lat/lon routes** | Coordinate-based routing (no named fixes) | `5515N03825E/BULGAKOVO...` |

These are all correctly identified as unparseable — the parser is not failing on valid IFR routes.

### 4.3 Parse Success by Region

| Region | COMPLETE | PENDING | PARTIAL | FAILED | Parse Rate |
|--------|----------|---------|---------|--------|------------|
| US (K) | 108,200 | 4,838 | 579 | **7** | **95.2%** |
| Canada (C) | 12,227 | 415 | 179 | 43 | 95.0% |
| S. Europe (L) | 102,265 | 7,246 | 465 | 190 | 92.8% |
| Europe (E) | 155,715 | 12,566 | 1,649 | 185 | 91.5% |
| Oceania (Y) | 16,324 | 1,244 | 161 | 38 | 91.9% |
| S. America (S) | 26,534 | 1,489 | 284 | 65 | 93.5% |
| **Other** | **130,252** | **127,488** | 1,132 | 437 | **50.3%** |

**US routes parse at 95.2%** with only 7 failures (the best). The "Other" category (Middle East, Africa, Asia — non-E/K/Y/C/S/L prefixes) has only 50% parse rate, largely because many of these routes are still PENDING rather than failed.

### 4.4 Parse Success by Route Complexity

| Waypoint Count | COMPLETE | Other | Observations |
|----------------|----------|-------|-------------|
| No waypoints | 0 | 338,248 (NULL/PENDING) | Routes never submitted to parser |
| <5 waypoints | 329,944 | 9,551 (PARTIAL/FAILED) | Short routes — most failures here |
| 5-9 waypoints | 263,842 | 119 PENDING | Very high success |
| 10-19 waypoints | 231,908 | 71 PENDING | Very high success |
| 20-49 waypoints | 105,775 | 53 PENDING | Very high success |
| 50+ waypoints | 11,117 | 5 PENDING | Very high success |

Routes with 5+ waypoints parse at effectively **100%**. All failures and partials are in the <5 waypoint band — VFR, DCT, and minimal-route flights.

---

## 5. System Performance

### 5.1 Wind Correction Effectiveness

#### By Wind Strength and Method

| Wind | V35 MAE | V35_ROUTE MAE | V35_SEG_WIND MAE | Best Method |
|------|---------|---------------|------------------|-------------|
| Calm (<5 kts) | 5.6 min | 5.2 min | 5.3 min | V35_ROUTE |
| Light (5-15 kts) | 13.6 min | 18.7 min | 15.6 min | V35 |
| Moderate (15-30 kts) | 12.0 min | 17.7 min | 14.7 min | V35 |
| Strong (30+ kts) | 10.6 min | 13.2 min | 11.3 min | V35 |

**Unexpected finding**: In wind-affected conditions, **V35 (no route, no wind correction) outperforms V35_ROUTE and V35_SEG_WIND**. This suggests that:
- Route-based distance calculations may introduce errors when wind alters the actual flight path from the filed route
- The wind correction algorithm may be over-compensating or under-compensating in a way that adds noise
- V35's simpler ground-speed extrapolation is more robust when winds are significant

However, for calm conditions (98%+ of traffic), V35_SEG_WIND achieves the best **within-5-minute accuracy** (82.8%) vs V35 (79.9%) vs V35_ROUTE (77.8%).

#### By Wind Direction

| Direction | Flights | MAE (min) | Bias (min) | Within 5min |
|-----------|---------|-----------|-----------|-------------|
| Calm (-5 to +5 kts) | 379,029 | 5.4 | -3.5 | 79.8% |
| Moderate Headwind (-5 to -15) | 253 | 15.5 | -3.8 | 5.1% |
| Strong Headwind (<-15) | 5,614 | 11.5 | **-8.4** | 9.9% |
| Moderate Tailwind (+5 to +15) | 198 | 17.7 | -3.2 | 11.6% |
| Strong Tailwind (>+15) | 779 | 17.3 | -1.4 | 11.4% |

**Strong headwinds produce the largest bias (-8.4 min)** — the system underestimates flight time in headwinds, causing flights to arrive even later than already-pessimistic predictions. Strong tailwinds show nearly zero bias (-1.4 min), suggesting tailwind corrections are better calibrated.

#### Wind Confidence Tiers

| Confidence | Flights | MAE (min) | Within 5min |
|------------|---------|-----------|-------------|
| 0.40 (low) | 24,267 | **4.4** | **84.4%** |
| 0.50 | 1,135 | 18.4 | 48.3% |
| 0.60 | 248 | 9.1 | 57.7% |
| 0.95 (high) | 96,758 | 5.5 | 81.4% |

**Counter-intuitive result**: The lowest confidence tier (0.40) has the best MAE (4.4 min) and within-5-minute accuracy (84.4%). This likely means flights assigned low wind confidence have minimal wind impact, so the baseline prediction is accurate regardless. The 0.50 tier (18.4 min MAE) represents flights where wind data is uncertain AND wind is actually significant — the worst combination.

### 5.2 Processing Latency

#### Current State (Live Snapshot)

| Metric | Value |
|--------|-------|
| Active flights with ETA | 2,153 |
| Average staleness | **<1 minute** |
| 100% of flights | <1 minute old |

**All active flight ETAs are refreshed within 1 minute** — the tiered daemon architecture is keeping up with current traffic load.

#### Historical Last-Calc-to-Arrival

| Month | Flights | Avg Last Calc to Arrival |
|-------|---------|------------------------|
| January | 7,996 | 44.5 min |
| February | 1,227 | 46.5 min |
| March | 316 | 0.4 min |

The January/February figures (44-46 min) represent flights where `eta_last_calc_utc` was recorded — this is the time between the final ETA recalculation and actual arrival. Most flights stop being recalculated once they're in final approach (last ~45 min of flight), which explains this value. The March figure (0.4 min) reflects the post-hibernation state where recently-arrived flights had very fresh calculations.

### 5.3 Waypoint & Boundary Accuracy (Data Gaps)

Two planned analyses are currently **not feasible** due to data limitations:

**Waypoint ETA Accuracy**: The `adl_flight_waypoints.ata_utc` column (actual passage time) is **never populated** — 0 of 4.58M recent waypoints have actual times. Waypoint ETAs are calculated but not validated against observed passage. This would require trajectory-to-waypoint matching to populate.

**Boundary Detection Accuracy**: The `adl_flight_planned_crossings` table has no `actual_entry_utc` column — there is no mechanism to compare predicted vs observed sector entry times. This would require matching trajectory points against boundary geometry to derive actual crossing times.

Both analyses would require new data pipeline work to enable.

---

## Summary of Key Findings

### Network Character
- **Weekend-dominant traffic** (43% higher than weekdays), peaking Sunday/Saturday
- **18Z global peak** (1,302 flights/hour avg) driven by European evening rush + US afternoon
- **North Atlantic corridor** is the dominant traffic flow — 8 of top 15 boundaries are NAT-related
- KZNY handles 30% more peak traffic than any other ARTCC

### Operational Insights
- **90% of departures exceed unimpeded taxi time** at major airports — systemic across the network
- Peak-hour taxi times are 39% longer than off-peak (10.2 vs 7.3 min)
- EDDF and EGLL generate the most total ground delay (~29K and 27K excess minutes)
- US airports have longer connect-to-push times (19-22 min) vs European airports (15-18 min)

### System Health
- Route parser achieves **95%+ success** on US/Canadian routes, **73% overall** (VFR/DCT failures are correct behavior)
- ETA processing latency is **<1 minute** for all active flights
- Wind corrections help in calm conditions (+3% within-5-min accuracy) but **V35 base method outperforms in windy conditions** — wind algorithm needs investigation
- Strong headwind bias (-8.4 min) is the largest systematic ETA error source

### Data Gaps to Address
- Waypoint actual passage times (`ata_utc`) are never populated
- Boundary actual crossing times are not tracked
- "Other" region (Middle East, Africa, Asia) route parse backlog is significant (50% PENDING)
