# CTP E26 Departure Analytics — 2026-04-25

**Generated**: 2026-04-25 ~21:00Z (updated with non-event comparison, route compliance V2, CCFRP CTOT analysis, slot utilization comparison; audited ~22:00Z — canonical time definitions, data-drift corrections)
**Source**: Live queries against VATSIM_ADL, VATSIM_TMI, perti_site (MySQL), and FlowControl (flowcontrol.vatsim.net) production data
**Event**: CTPE26 Eastbound, Session ID 9
**Analysis window**: 25/0900Z to 25/1800Z (Sections 2, 6, 9, 10 use this window; Sections 1, 3-5, 7-8 use full day; Section 13 uses 10-23Z event window)
**Non-event definition**: Transatlantic flights traversing NAT oceanic FIRs (BGGL, BIRD, CZQX, CZQO, EGGX, KZNY, LPPO, TTZO, GVSC), identified by ICAO prefix matching: ORIG K\*/M\*/P\*/C\*/T\*/S\* → DEST E\*/L\*/G\*/D\*/H\*/F\*/O\*/U\*/B\*/W\* (and vice versa for westbound). FIR-based crossing filtering was attempted but today's active flights have no crossing records yet (daemon lag). Route-based identification used instead.
**Validation**: All data from SQL queries with column names verified via INFORMATION_SCHEMA. No synthetic data.

### Time Definitions (Canonical Sources)

| Abbreviation | Full Name | Source | Definition |
|:------------:|-----------|--------|------------|
| **CTOT** | Calculated Take-Off Time | EUROCONTROL ATFCM | Time at which the aircraft is expected to become airborne, issued by flow control |
| **EDCT** | Expect Departure Clearance Time | FAA ATCSCC | US equivalent of CTOT — assigned departure time under a GDP/AFP |
| **CTD** | Controlled Time of Departure | PERTI TMI | PERTI's slot-assigned departure time (equivalent to EDCT for internal slot management) |
| **ETD** | Estimated Time of Departure | ICAO FPL (Field 13) | Filed/planned departure time from the flight plan |
| **OUT** | Gate Departure (Pushback) | OOOI | Actual time the aircraft leaves the gate (first movement) |
| **OFF** | Wheels Off (Takeoff) | OOOI | Actual time the aircraft becomes airborne |
| **OEP** | Ocean Entry Point | Oceanic ATC | The fix where a flight enters oceanic airspace |
| **TOT** | Take-Off Time | FlowControl | FlowControl's field name for CTOT; functionally equivalent |

---

## 1. Event Overview

| Metric | Value |
|--------|-------|
| Session | CTPE26 (ID 9) |
| Direction | EASTBOUND |
| Status | ACTIVE |
| Event Window | 10:00Z – 23:59Z |
| Slot Interval | 5 min |
| Max Rate/hr | 20 |
| Constrained FIRs | CZQX, EGGX |
| Tracks Defined | 39 |
| Tracks with Assignments | 9 |
| Total Slots Generated | 9,394 |
| Slots Assigned | 14 (0.15%) |

### Nattrak Bookings

| Metric | Value |
|--------|-------|
| Total Bookings | 1,234 |
| Matched to ADL | 1,034 (83.8%) |
| Unmatched (No-Show) | 200 (16.2%) |
| Unique Pilots | 1,201 |
| Departure Airports | 10 |
| Arrival Airports | 11 |

---

## 2. Event vs Non-Event NAT Traffic (09–18Z Window)

Non-event traffic = non-CTP flights with transatlantic routes through NAT oceanic FIRs (BGGL, BIRD, CZQX, CZQO, EGGX, KZNY, LPPO, TTZO, GVSC), identified by ICAO prefix filter (see header).

| Traffic Type | Pushed 09–18Z | % of NAT Traffic |
|-------------|:-------------:|:----------------:|
| **CTP** | **836** | **73.8%** |
| **Non-CTP Transatlantic** | **296** | **26.2%** |
| **Total Transatlantic** | **1,132** | 100% |

CTP dominates transatlantic traffic during the event window, representing **74% of all transatlantic departures** between 09–18Z. The broader non-event ICAO prefix filter (K/M/P/C/T/S origins, E/L/G/D/H/F/O/U/B/W destinations and vice versa) captures both eastbound and westbound transatlantic flights.

### Hourly Pushback Profile (09–18Z)

| Hour | CTP | Non-CTP | Total | CTP Share |
|------|:---:|:-------:|:-----:|:---------:|
| 09Z | 0 | 32 | 32 | 0.0% |
| 10Z | 10 | 29 | 39 | 25.6% |
| 11Z | 67 | 35 | 102 | 65.7% |
| 12Z | 174 | 46 | 220 | **79.1%** |
| **13Z** | **275** | **23** | **298** | **92.3%** |
| 14Z | 203 | 21 | 224 | 90.6% |
| 15Z | 91 | 36 | 127 | 71.7% |
| 16Z | 13 | 32 | 45 | 28.9% |
| 17Z | 3 | 42 | 45 | 6.7% |
| **TOTAL** | **836** | **296** | **1,132** | **73.9%** |

**Peak CTP hour**: 13Z (275 pushbacks, 92.3% of all transatlantic traffic that hour). Non-CTP traffic is relatively steady at 21–46 per hour, with a slight dip during peak CTP hours (13–14Z). CTP traffic dominates between 12–14Z.

### Non-CTP Direction Split

| Direction | Count | % of Non-CTP |
|-----------|:-----:|:------------:|
| Eastbound (NA→EU) | 177 | 59.8% |
| Westbound (EU→NA) | 119 | 40.2% |
| **Total** | **296** | 100% |

Non-CTP traffic includes both directions. The 119 westbound flights (EU→NA) are not covered by CTPE26NE playbook routes, which are eastbound-only.

---

## 3. Departure Airport Analysis (CTP Flights)

| Airport | Bookings |
|---------|:--------:|
| KDFW | 196 |
| CYYZ | 156 |
| KMCO | 141 |
| KJFK | 133 |
| KIAD | 121 |
| KATL | 118 |
| KBOS | 114 |
| CYYC | 109 |
| SKBO | 106 |
| TNCM | 40 |

**Note**: Booking counts are from the `ctp_event_bookings` table (Nattrak import). KDFW has the most bookings (196).

### Arrival Airports

| Airport | Total | Departed | Arrived |
|---------|-------|----------|---------|
| EHAM | 145 | 0 | 0 |
| LFPG | 145 | 0 | 0 |
| ENGM | 144 | 0 | 0 |
| LEMD | 125 | 0 | 0 |
| LIMC | 102 | 1 | 0 |
| UUEE | 98 | 0 | 0 |
| EGLL | 97 | 0 | 0 |
| EDDP | 90 | 0 | 0 |
| EBBR | 86 | 0 | 0 |
| GOBD | 85 | 0 | 0 |
| LOWW | 78 | 0 | 0 |

---

## 4. Slot Utilization Analysis

### Slot System Status: PASSIVE/MONITORING MODE

Only **14 of 9,394** slots (0.15%) have been assigned to flights. All CTD values are NULL, meaning the CTOT-to-EDCT cascade has not been triggered. No departure times have been propagated to flights.

### Tracks with Assigned Slots

| Track | Entry Fix | Exit Fix | Max ACPH | Total Slots | Assigned | Fill % |
|-------|-----------|----------|----------|-------------|----------|--------|
| B1 | KETLA | MATIK | 20 | 280 | 2 | 0.7% |
| B2 | NIFTY | ATSIX | 20 | 280 | 1 | 0.4% |
| F | ENNSO | SUNOT | 20 | 280 | 1 | 0.4% |
| L | ALLRY | LIMRI | 20 | 280 | 1 | 0.4% |
| N1 | NICSO | ALUTA | 2 | 28 | 4 | 14.3% |
| O1 | OMSAT | ALUTA | 1 | 14 | 2 | 14.3% |
| R | RELIC | H4508 | 8 | 112 | 1 | 0.9% |
| T1 | JOBOC | MUDOS | 20 | 280 | 1 | 0.4% |
| W2 | SNAGY | LUMPO | 20 | 280 | 1 | 0.4% |

30 tracks have **zero** assignments. Only constrained tracks (N1: 2 acph, O1: 1 acph, R: 8 acph) show meaningful fill rates.

### Assigned Slot Details

| Callsign | Track | OEP Time | Origin | Dest | CTD | Delay |
|----------|-------|----------|--------|------|-----|-------|
| BAW14K | B1 | 12:36Z | KJFK | EGLL | NULL | NULL |
| PIA619 | W2 | 12:39Z | KMCO | GOBD | NULL | NULL |
| AAL781 | B2 | 12:54Z | KDFW | EGLL | NULL | NULL |
| VIR10LL | F | 13:00Z | KATL | EGLL | NULL | NULL |
| THY85 | B1 | 13:15Z | KJFK | LTFM | NULL | NULL |
| UAL487 | N1 | 17:00Z | KEWR | EHAM | NULL | NULL |
| KLM650 | N1 | 17:30Z | KJFK | EHAM | NULL | NULL |
| AAL4587 | R | 17:45Z | KJFK | EGLL | NULL | NULL |
| AFR180 | N1 | 18:00Z | KJFK | LFPG | NULL | NULL |
| DAL596 | L | 18:21Z | KMIA | EGLL | NULL | NULL |
| DLH411B | O1 | 19:00Z | KJFK | EDDM | NULL | NULL |
| KLM265 | N1 | 19:30Z | KJFK | EHAM | NULL | NULL |
| NCR18LB | T1 | 19:57Z | KJFK | LSZH | NULL | NULL |
| DLH18R | O1 | 20:00Z | KJFK | EGLL | NULL | NULL |

---

## 5. Departure Delay Analysis (OUT–ETD Proxy)

Since EDCTs/CTDs are largely unassigned, we use **OUT time – ETD** as a delay proxy. Positive = pushback later than estimated.

### Overall Statistics (846 flights)

| Metric | Value |
|--------|-------|
| Mean | +24.8 min |
| Median | +40.0 min |
| Std Dev | 218.3 min |
| P5 | +6.5 min |
| P10 | +18.2 min |
| P25 | +32.4 min |
| P75 | +50.4 min |
| P90 | +69.9 min |
| P95 | +88.8 min |
| Min | -4,394 min* |
| Max | +264 min |

*Extreme negative outliers are stale flights with OUT times from days ago.

### Delay Distribution

| Category | Count | % |
|----------|-------|---|
| Very early (<-15m) | 15 | 1.8% |
| Early (-15 to -5m) | 10 | 1.2% |
| Slightly early (-5 to 0m) | 3 | 0.4% |
| On time (0 to +5m) | 10 | 1.2% |
| Slightly late (+5 to +15m) | 32 | 3.8% |
| Late (+15 to +30m) | 109 | 12.9% |
| **Very late (>30m)** | **666** | **78.8%** |

**Key finding**: The vast majority of CTP flights (78.8%) push back **>30 minutes after their ETD**. This is likely because ETD is filed/estimated departure time, and CTP pilots connect early/prefile well ahead but wait for the actual event departure window (10:00Z+). The delays are structural/intentional, not operational issues.

### By Departure Airport

| Airport | N | Mean | Median | P90 | Max | Early(<-5m) | On-time(±5m) | Late(>15m) |
|---------|---|------|--------|-----|-----|-------------|--------------|------------|
| KDFW | 166 | +18.5 | +53.1 | +82.5 | +136.8 | 2 | 1 | 163 |
| KMCO | 108 | +39.4 | +37.3 | +56.8 | +84.1 | 0 | 0 | 107 |
| KJFK | 105 | +20.9 | +39.1 | +56.6 | +263.8 | 2 | 0 | 103 |
| KIAD | 100 | +34.8 | +44.2 | +88.6 | +164.0 | 1 | 0 | 98 |
| KATL | 96 | +38.5 | +40.9 | +64.6 | +240.5 | 1 | 0 | 95 |
| CYYC | 85 | -32.0 | +18.8 | +40.2 | +175.3 | 9 | 8 | 52 |
| SKBO | 71 | +29.0 | +26.9 | +58.5 | +135.0 | 5 | 4 | 52 |
| KBOS | 63 | +43.2 | +41.8 | +62.2 | +126.2 | 3 | 0 | 59 |
| CYYZ | 51 | +43.6 | +35.6 | +75.1 | +253.0 | 2 | 0 | 46 |

CYYC is an outlier with a negative mean — this is driven by stale flights with very early OUT times (Apr 20–24).

### By Arrival Airport

| Airport | N | Mean | Median | P90 |
|---------|---|------|--------|-----|
| ENGM | 119 | -16.3 | +41.2 | +75.2 |
| EHAM | 98 | +34.9 | +39.2 | +83.2 |
| LFPG | 86 | +32.0 | +42.6 | +81.6 |
| LEMD | 83 | +23.2 | +41.5 | +63.1 |
| UUEE | 76 | +8.5 | +34.6 | +60.2 |
| LIMC | 74 | +40.6 | +40.2 | +57.2 |
| EDDP | 71 | +49.1 | +42.2 | +71.7 |
| EGLL | 68 | +51.2 | +44.4 | +78.8 |
| GOBD | 60 | +31.3 | +32.6 | +56.8 |
| LOWW | 58 | +44.7 | +38.5 | +70.4 |
| EBBR | 52 | -4.9 | +42.0 | +72.3 |

### 15 Latest Departures

| Callsign | Route | ETD | OUT | Delta | Type |
|----------|-------|-----|-----|-------|------|
| SAS921 | KJFK->ENGM | 12:49 | 17:12 | +264m | A21N |
| WAT4554 | CYYZ->ENGM | 10:45 | 14:58 | +253m | A333 |
| GAF209 | KATL->EDDP | 11:16 | 15:16 | +241m | A359 |
| QTR24DS | KATL->EGLL | 11:21 | 14:59 | +218m | A346 |
| BOX325 | KATL->EDDP | 11:09 | 14:45 | +216m | B77L |
| KLM86 | CYYZ->EHAM | 12:12 | 15:21 | +190m | MD11 |
| BOX204 | KATL->EBBR | 11:31 | 14:35 | +185m | B77L |
| AFL64X | CYYC->UUEE | 09:31 | 12:26 | +175m | A339 |
| AAL94 | KJFK->LEMD | 13:07 | 15:59 | +172m | B772 |
| VIR26K | KJFK->EGLL | 12:46 | 15:35 | +169m | A343 |

---

## 6. Event vs Non-Event Delay Comparison (09–18Z)

### Overall Statistics

| Metric | CTP (N=834) | Non-CTP (N=296) | Delta |
|--------|:-----------:|:--------------------:|:-----:|
| Mean OUT-ETD | **+44.5 min** | +40.0 min | **+4.5 min** |
| Std Dev | 30.3 min | 61.0 min | — |
| Min | -76 min | -74 min | — |
| Max | +263 min | +287 min | — |

### Percentiles

| Percentile | CTP | Non-CTP | Delta |
|:----------:|:---:|:-------:|:-----:|
| P10 | +19.0m | -6.0m | +25.0m |
| P25 | +32.0m | +5.0m | +27.0m |
| **P50 (Median)** | **+40.0m** | **+20.0m** | **+20.0m** |
| P75 | +50.0m | +48.0m | +2.0m |
| P90 | +70.0m | +131.0m | -61.0m |
| P95 | +89.1m | +169.0m | -79.9m |

### Delay Distribution

| Category | CTP | CTP % | Non-CTP | Non-CTP % |
|----------|:---:|:-----:|:-------:|:---------:|
| Very early (<-15m) | 6 | 0.7% | 15 | 5.2% |
| Early (-15 to -5m) | 10 | 1.2% | 15 | 5.2% |
| On-time (±5m) | 13 | 1.6% | 44 | **15.2%** |
| Slightly late (+5 to +15m) | 33 | 4.0% | 49 | 17.0% |
| Late (+15 to +30m) | 119 | 14.3% | 61 | 21.1% |
| **Very late (+30 to +60m)** | **533** | **64.2%** | 43 | 14.9% |
| Extremely late (>60m) | 116 | 14.0% | 62 | **21.5%** |

### On-Time Performance (±15 min of ETD)

| Metric | CTP | Non-CTP |
|--------|:---:|:-------:|
| Within ±15m of ETD | 56 (6.7%) | 123 (42.6%) |
| >15m late | 768 (92.5%) | 166 (57.4%) |
| >60m late | 116 (14.0%) | 62 (21.5%) |

### Interpretation

CTP and non-CTP transatlantic traffic show fundamentally different delay profiles:

1. **CTP is tightly clustered** (std 30.3m) with 64% of flights in the +30–60m band. This is structural — pilots prefile well before the event, then push back during the window. The +40m median is expected, not an operational issue.

2. **Non-CTP is widely dispersed** (std 61.0m) with a more uniform distribution: 15.2% on-time (±5m), but 21.5% extremely late (>60m). The broader spread reflects both eastbound and westbound traffic operating on diverse schedules.

3. **CTP has lower variance and fewer extremes**: Only 14% of CTP flights exceed +60m vs 21.5% for non-CTP. The CTP event structure imposes discipline at the tails.

4. **CTP mean is slightly higher than non-CTP** (+4.5 min) and the median is +20m higher (+40 vs +20), reflecting the structural delay floor where CTP pilots prefile early and wait. Non-CTP traffic has more on-time departures (42.6% vs 6.7% within ±15m) because they operate on demand-based schedules.

---

## 7. Booking Compliance (Booked Time vs Actual OUT)

For 750 flights with both a Nattrak booked takeoff time and an actual OUT time:

| Metric | Value |
|--------|-------|
| Mean delta | +17.9 min |
| Median delta | +12.7 min |
| P10 | -7.3 min |
| P90 | +55.3 min |
| Min | -94.3 min |
| Max | +175.3 min |

### Compliance Distribution

| Category | Count | % |
|----------|-------|---|
| Early (>15m before booked) | 20 | 2.7% |
| **On-time (±15m)** | **403** | **53.7%** |
| Late (>15m after booked) | 327 | 43.6% |
| Very late (>60m after booked) | 42 | 5.6% |

**53.7% of pilots pushed back within ±15 minutes of their booked time.** 43.6% were late by more than 15 minutes, but only 5.6% were very late (>60m).

---

## 8. Track Distribution (Bookings)

| Track | Booked | Matched | Match% | Has OUT |
|-------|--------|---------|--------|---------|
| K | 65 | 48 | 73.8% | 27 |
| A4 | 62 | 52 | 83.9% | 38 |
| B2 | 62 | 51 | 82.3% | 38 |
| A2 | 59 | 48 | 81.4% | 44 |
| J | 55 | 49 | 89.1% | 29 |
| P | 54 | 46 | 85.2% | 35 |
| Z | 54 | 44 | 81.5% | 25 |
| L | 53 | 46 | 86.8% | 37 |
| M | 51 | 46 | 90.2% | 39 |
| Q | 50 | 40 | 80.0% | 32 |

Traffic is well distributed across tracks. Tracks K, A4, B2, A2 are the most popular. Match rates range from 61.5% (Y1) to 100% (N, W1, V2).

---

## 9. Aircraft Type Distribution (CTP vs Non-CTP, 09–18Z)

| Type | CTP | Non-CTP | Total | CTP Share |
|------|:---:|:-------:|:-----:|:---------:|
| B77W | 173 | 65 | 238 | 72.7% |
| A359 | 171 | 43 | 214 | 79.9% |
| B772 | 107 | 17 | 124 | 86.3% |
| B77L | 77 | 18 | 95 | 81.1% |
| A346 | 38 | 21 | 59 | 64.4% |
| A339 | 40 | 12 | 52 | 76.9% |
| A343 | 33 | 13 | 46 | 71.7% |
| A35K | 28 | 14 | 42 | 66.7% |
| A333 | 25 | 14 | 39 | 64.1% |
| B789 | 23 | 15 | 38 | 60.5% |
| MD11 | 32 | 6 | 38 | 84.2% |
| A388 | 13 | 11 | 24 | 54.2% |
| A21N | 14 | 10 | 24 | 58.3% |
| A332 | 7 | 4 | 11 | 63.6% |
| B737 | 8 | 3 | 11 | 72.7% |

Heavy widebodies dominate both CTP and non-CTP traffic. B777 variants (333 total), A350 variants (256), and B772 (124) are the top three families. CTP fleet mix is very similar to non-CTP — no significant type divergence. A388 (54.2% CTP) and A21N (58.3% CTP) have the lowest CTP share, suggesting these types are more common on regular transatlantic routes.

### Departure Airports (CTP vs Non-CTP, 09–18Z)

| Airport | CTP | Non-CTP | Total | Notes |
|---------|:---:|:-------:|:-----:|-------|
| KDFW | 164 | 5 | 169 | CTP gateway |
| KJFK | 107 | 38 | 145 | Year-round hub |
| KIAD | 99 | 10 | 109 | CTP gateway |
| KMCO | 107 | 2 | 109 | CTP gateway |
| KATL | 95 | 7 | 102 | CTP gateway |
| CYYC | 81 | 4 | 85 | CTP gateway |
| KBOS | 62 | 18 | 80 | Mixed |
| SKBO | 70 | 4 | 74 | CTP gateway |
| CYYZ | 51 | 11 | 62 | Mixed |
| **EGLL** | 0 | **27** | 27 | **WB non-CTP only** |
| **EDDF** | 0 | **20** | 20 | **WB non-CTP only** |
| CYUL | 0 | 15 | 15 | EB non-CTP only |
| EDDM | 0 | 9 | 9 | WB non-CTP only |
| KMIA | 0 | 7 | 7 | EB non-CTP only |
| LEMD | 0 | 7 | 7 | WB non-CTP only |
| LFPG | 0 | 7 | 7 | WB non-CTP only |
| CYYT | 0 | 6 | 6 | EB non-CTP only |
| KORD | 0 | 6 | 6 | EB non-CTP only |
| KEWR | 0 | 5 | 5 | EB non-CTP only |
| KSEA | 0 | 5 | 5 | EB non-CTP only |

Non-CTP traffic includes both directions. European airports (EGLL, EDDF, EDDM, LEMD, LFPG) appear as departure airports for **westbound** non-CTP flights. KJFK has the highest non-CTP count (38), reflecting its role as a year-round transatlantic hub. North American non-CTP gateways (CYUL, KORD, KMIA) serve regular eastbound traffic outside the CTP event.

---

## 10. Route Compliance Analysis (09–18Z)

Route compliance measures how well flights adhered to published CTP event routes and non-event (NE) routes. Routes are normalized (stripping step climbs, DCT, speed/altitude groups, runway specs) and matched against playbook waypoint sequences using subsequence analysis.

**Data sources**:
- **CTP event routes**: 456 routes across 5 playbook plays (CTPE26, CTPE26_EU, CTPE26_FULL, CTPE26_NA, CTPE26_OCA) + 39 session tracks (34 event + 5 NE tracks in DB)
- **Valid CTPE26NE (Cross the Pond Eastbound 2026 Non-Event) playbook routes** (per FlowControl definition): 1,320 routes across 4 plays: CTPE26NE_NA_ALLEX (320), CTPE26NE_NA_BRADD (352), CTPE26NE_NA_KANNI (320), CTPE26NE_NA_TUSKY (328)
- **Non-event tracks** (per FlowControl definition): M1, N1, O1, R, M2, N2, O2, RR1. Of these, M1/N1/O1/R/RR1 are present in `ctp_session_tracks`; M2/N2/O2 are defined only in FlowControl (same routes as M1/N1/O1 respectively, ending at KOGAD)

### CTP Flight Route Compliance (N=836)

| Category | Count | % |
|----------|:-----:|:-:|
| **Event track match** | **777** | **92.9%** |
| NE playbook route | 35 | 4.2% |
| Event playbook route | 20 | 2.4% |
| NE track match | 4 | 0.5% |
| **Total compliant** | **836** | **100.0%** |

100% of CTP flights filed a route matching a published track or playbook route. 4 CTP flights matched NE tracks (M1=2, N1=1, O1=1).

### CTP Track Distribution

| Track | Flights | | Track | Flights | | Track | Flights |
|:-----:|:-------:|-|:-----:|:-------:|-|:-----:|:-------:|
| A2 | 48 | | B2 | 44 | | M | 43 |
| A4 | 41 | | Q | 37 | | A3 | 35 |
| G | 33 | | O | 32 | | A1 | 31 |
| T1 | 31 | | V1 | 31 | | J | 29 |
| Z | 29 | | E | 28 | | K | 28 |
| P | 28 | | B1 | 27 | | S | 24 |
| C | 23 | | N | 22 | | F | 21 |
| L | 21 | | W2 | 18 | | Y2 | 18 |
| T2 | 17 | | W1 | 8 | | X2 | 8 |
| D | 7 | | V2 | 7 | | U | 6 |
| TEST_A | 1 | | X1 | 1 | | | |

### Non-CTP Route Compliance (N=296)

Matching hierarchy: NE track → NE playbook route → event track → event playbook route → partial → none.

#### Eastbound Non-CTP (N=177)

| Category | Count | % |
|----------|:-----:|:-:|
| NE track (M1/N1/R) | 35 | 19.8% |
| NE playbook route | 15 | 8.5% |
| Event track | 22 | 12.4% |
| Event playbook route | 38 | 21.5% |
| **Total matched** | **110** | **62.1%** |
| Partial match (20–50%) | 44 | 24.9% |
| No match (<20%) | 22 | 12.4% |
| No route filed | 1 | 0.6% |

**NE track breakdown (EB):** N1=27, M1=4, R=4

**NE playbook route breakdown (EB):** BRADD=7, KANNI=5, ALLEX=2, TUSKY=1

**Event track breakdown (EB):** TEST_A=5, O=3, A4=2, J=2, L=2, N=2, E=1, K=1, Q=1, S=1, TEST_B=1, Z=1

#### Westbound Non-CTP (N=119)

| Category | Count | % |
|----------|:-----:|:-:|
| Event playbook route | 15 | 12.6% |
| NE track | 1 | 0.8% |
| Event track | 1 | 0.8% |
| **Total matched** | **17** | **14.3%** |
| Partial match | 35 | 29.4% |
| No match | 67 | 56.3% |

Westbound flights have low match rates as expected — NE routes and tracks are eastbound-only.

#### All Non-CTP Combined (N=296)

| Category | Count | % |
|----------|:-----:|:-:|
| NE track | 36 | 12.2% |
| NE playbook route | 15 | 5.1% |
| Event track | 23 | 7.8% |
| Event playbook route | 53 | 17.9% |
| **Total matched** | **127** | **42.9%** |
| Partial match | 79 | 26.7% |
| No match | 89 | 30.1% |
| No route filed | 1 | 0.3% |

### Non-CTP Unmatched EB Flights (Sample)

| Callsign | Route | Note |
|----------|-------|------|
| DLH4W | KCLT→EDDM | Partial (0.33) — contains BRADD/NICSO but not enough match |
| DLH405 | KJFK→EDDF | Partial (0.29) — filed via RAFIN, a non-NE oceanic entry |
| DLH491 | KSEA→EDDF | No match — polar route via YDC/5830N |
| ITY621 | KLAX→LIRF | No match — southern routing via ISN |
| RRR972 | SPJC→EGVN | No match — South America via 19N053W |
| SAS986 | KLAX→ESSA | No match — polar route via DEBMA/KAVKI |
| BAW4EA | KSEA→EGLL | No match — polar route via YDC/ROMRA |

---

## 11. CCFRP CTOT Analysis (FlowControl)

The VATSIM FlowControl system (flowcontrol.vatsim.net) issued CTOTs (Calculated Take-Off Times) to non-event transatlantic flights via CCFRP (Continental Call-For-Release Program) programs during the CTP event.

**Source**: FlowControl "Completed Requests" page, extracted 2026-04-25 ~20:00Z.

### CCFRP Overview

| Metric | Value |
|--------|-------|
| Total completed CTOT requests | 92 |
| Event day (Apr 25) requests | 87 |
| Pre-event (Apr 23–24) requests | 5 |
| Programs | CCFRP DCC (69), CCFRP CANOC (18) |
| Direction | 84 eastbound, 3 westbound |

### CCFRP CTOT Hourly Distribution

| Hour (Z) | CTOTs Issued |
|:---------:|:------------:|
| 00 | 1 |
| 10 | 1 |
| 11 | 9 |
| 12 | 7 |
| 13 | 8 |
| 14 | 16 |
| 15 | 13 |
| 16 | 22 |
| 17 | 7 |
| 18 | 3 |

Peak CCFRP activity at 16Z (22 CTOTs), with a second peak at 14Z (16).

### CCFRP Departure Airport Distribution

| Airport | CTOTs | | Airport | CTOTs |
|:-------:|:-----:|-|:-------:|:-----:|
| KJFK | 30 | | KEWR | 13 |
| CYUL | 9 | | CYYZ | 8 |
| KATL | 7 | | KDFW | 5 |
| KIAD | 4 | | EDDF | 2 |
| SKBO | 2 | | Other (7) | 7 |

### Cross-Reference with ADL Data

Of the 87 event-day CCFRP CTOT requests:

| Metric | Count |
|--------|:-----:|
| Found in ADL flight data | 63 |
| Not found in ADL | 24 |
| Tagged as CTP event (`flow_event_code = 'CTPE26'`) | 10 |
| Tagged as non-CTP | 53 |
| Has EDCT/CTD populated in ADL | 1 |
| No EDCT/CTD in ADL | 62 |

The 24 unmatched callsigns were likely flights that received CTOTs but did not depart during the analysis window, or had callsign mismatches between FlowControl and VATSIM.

### CCFRP CTOT vs Actual Departure

For the 63 matched flights, comparing FlowControl-assigned CTOT (labeled "TOT" in FlowControl UI) to ADL `out_utc`:

| CTOT Compliance | Count | % |
|----------------|:-----:|:-:|
| Within ±15 min | 37 | 58.7% |
| Early >15 min | 11 | 17.5% |
| Late >15 min | 15 | 23.8% |

Flights departing >60 minutes from assigned CTOT:

| Callsign | Route | CTOT | Actual OUT | Diff |
|----------|-------|:---:|:----------:|:----:|
| DAL36 | KATL→EGLL | 15:30z | 03:19z | -731m |
| ACA838 | CYYZ→EBBR | 16:00z | 06:20z | -580m |
| DLH405 | KEWR→EDDF | 16:00z | 11:58z | -242m |
| ACA890 | CYYZ→LIRF | 13:30z | 16:40z | +190m |
| BOX451 | KJFK→EDDF | 14:30z | 12:42z | -108m |
| VIR235 | KEWR→EGLL | 13:15z | 11:30z | -105m |
| AAL724 | KCLT→EIDW | 15:30z | 13:57z | -93m |
| UAL414 | KJFK→EDDF | 18:30z | 17:00z | -90m |
| UAE242 | CYYZ→OMDB | 15:45z | 17:05z | +80m |
| GEC8161 | KJFK→EDDF | 11:35z | 12:38z | +63m |

Large negative deviations (DAL36, ACA838) are flights with OUT times well before the CTOT was issued — the FlowControl CTOT completion timestamps post-date the actual departures.

### CCFRP Data Gap

FlowControl CTOTs were **not reflected in PERTI's ADL database**. Of 63 matched flights, 62 had NULL values for all EDCT/CTD columns in `adl_flight_times` and `adl_flight_tmi`. The FlowControl CCFRP system operated independently — there is no data feed from FlowControl into PERTI.

---

## 12. Nattrak Bookings vs CTP Tagged vs Pushed

Three distinct counts appear throughout this report. They represent different stages of the CTP pipeline:

| Metric | Count | Definition |
|--------|:-----:|-----------|
| **Nattrak bookings** | 1,234 | Pilots who booked a CTP slot via Nattrak |
| **Bookings matched to ADL** | 1,034 | Nattrak bookings that matched to an active ADL flight record (83.8%) |
| **ADL flights tagged CTPE26** | 1,202 | All ADL flights with `flow_event_code = 'CTPE26'` (includes booking matches + daemon tagging) |
| **CTP pushed 09–18Z** | 836 | Tagged CTP flights that recorded `out_utc` within the 09–18Z analysis window |

### Where the Differences Come From

- **1,234 → 1,034**: 200 bookings (16.2%) had no matching ADL flight — pilots who booked but never connected/filed.
- **1,034 → 1,202**: The daemon tags flights via callsign+route matching in addition to booking matches, so 168 additional flights were tagged that didn't have Nattrak bookings.
- **1,202 → 836**: Of 1,202 tagged flights, 354 had no `out_utc` (connected/prefiled but not yet pushed at query time), and 12 pushed outside the 09–18Z window. The remaining 836 pushed within the analysis window.

---

## 13. Non-Event Slot Utilization — Potential vs Actual (10–23Z)

During the event, technology issues with PERTI's slot request system prevented efficient assignment of NE track slots to non-event traffic. This section compares what could have been achieved with functioning technology against what actually occurred.

### NE Track Configuration (in `ctp_session_tracks`)

| Track | Max ACPH | Entry Fix | Exit Fix | Slots Generated | Program ID |
|:-----:|:--------:|:---------:|:--------:|:---------------:|:----------:|
| M1 | 2 | MUSAK | ALUTA | 28 | 1874 |
| N1 | 2 | NICSO | ALUTA | 28 | 1876 |
| O1 | 1 | OMSAT | ALUTA | 14 | 1878 |
| R | 8 | RELIC | H4508 | 112 | 1881 |
| RR1 | 16 | RESNO | LOMSI | 0 (no program) | — |
| **Total** | **29** | | | **182** | |

RR1 had no `program_id` assigned and therefore no slots were generated. Effective NE slot capacity was 13 slots/hr (M1=2, N1=2, O1=1, R=8) across 4 tracks.

### Actual Slot Assignments (NE Tracks Only)

Only **7 of 182 NE slots** (3.8%) were assigned to flights. All assignments occurred in the 17–20Z window:

| Track | Slot Time | Callsign | Route | CTD | Status |
|:-----:|:---------:|----------|-------|:---:|:------:|
| N1 | 17:00Z | UAL487 | KEWR→EHAM | NULL | ASSIGNED |
| N1 | 17:30Z | KLM650 | KJFK→EHAM | NULL | ASSIGNED |
| R | 17:45Z | AAL4587 | KJFK→EGLL | NULL | ASSIGNED |
| N1 | 18:00Z | AFR180 | KJFK→LFPG | NULL | ASSIGNED |
| O1 | 19:00Z | DLH411B | KJFK→EDDM | NULL | ASSIGNED |
| N1 | 19:30Z | KLM265 | KJFK→EHAM | NULL | ASSIGNED |
| O1 | 20:00Z | DLH18R | KJFK→EGLL | NULL | ASSIGNED |

No CTDs were set. M1 had zero assignments. R had 112 slots but only 1 assigned.

### Per-Track Fill Rates

| Track | Slots | Assigned | Fill % |
|:-----:|:-----:|:--------:|:------:|
| M1 | 28 | 0 | 0.0% |
| N1 | 28 | 4 | 14.3% |
| O1 | 14 | 2 | 14.3% |
| R | 112 | 1 | 0.9% |
| RR1 | 0 | 0 | — |

### Potential vs Actual Comparison (Hourly)

"Potential" = min(available NE slots, non-event EB flights) per hour. This is the number of flights that could have been assigned slots if the technology had worked and all non-event EB traffic had been slotted.

| Hour | NE Slots | NE EB Flights | Potential | Actual | Pot % | Act % | Note |
|:----:|:--------:|:-------------:|:---------:|:------:|:-----:|:-----:|------|
| 10Z | 13 | 13 | 13 | 0 | 100% | 0% | Demand = capacity |
| 11Z | 13 | 24 | 13 | 0 | 100% | 0% | Demand exceeds capacity by 11 |
| 12Z | 13 | 22 | 13 | 0 | 100% | 0% | Demand exceeds capacity by 9 |
| 13Z | 13 | 7 | 7 | 0 | 54% | 0% | |
| 14Z | 13 | 10 | 10 | 0 | 77% | 0% | |
| 15Z | 13 | 27 | 13 | 0 | 100% | 0% | Demand exceeds capacity by 14 |
| 16Z | 13 | 27 | 13 | 0 | 100% | 0% | Demand exceeds capacity by 14 |
| 17Z | 13 | 33 | 13 | 3 | 100% | 23% | Demand exceeds capacity by 20 |
| 18Z | 13 | 18 | 13 | 1 | 100% | 8% | Demand exceeds capacity by 5 |
| 19Z | 13 | 14 | 13 | 2 | 100% | 15% | Demand exceeds capacity by 1 |
| 20Z | 13 | 1 | 1 | 1 | 8% | 8% | |
| 21Z | 13 | 0 | 0 | 0 | 0% | 0% | |
| 22Z | 13 | 0 | 0 | 0 | 0% | 0% | |
| 23Z | 13 | 0 | 0 | 0 | 0% | 0% | |
| **TOTAL** | **182** | **196** | **122** | **7** | **67.0%** | **3.8%** | |

### Summary

| Metric | Value |
|--------|:-----:|
| NE track capacity (10–23Z) | 182 slots across 4 active tracks |
| Effective rate | 13 slots/hr (M1=2, N1=2, O1=1, R=8) |
| Non-CTP EB flights (10–23Z) | 196 |
| **Actual utilization** | **7/182 (3.8%)** |
| **Potential utilization** | **122/182 (67.0%)** |
| **Gap (missed assignments)** | **115 flights** |
| **Utilization delta** | **+63.2 percentage points** |
| Hours where demand exceeded capacity | 7 of 10 active hours (74 excess flights) |
| Unused slots even at full potential | 60 (demand was below capacity at 13Z, 14Z, 20-23Z) |

If the slot request technology had been functioning, NE track utilization could have reached **67%** instead of the actual **3.8%** — a **63 percentage point gap** representing **115 additional flights** that could have been assigned to NE track slots.

In 7 of the 10 hours with active NE EB traffic (11-12Z, 15-19Z), demand exceeded the 13 slots/hr NE capacity, meaning even with perfect technology, 74 flights would have been unslotted. This indicates the NE track capacity (13 slots/hr without RR1) was undersized for the actual traffic volume during peak hours.

---

## 14. Key Findings & Observations

### Event Health
1. **Strong participation**: 1,234 bookings with 83.8% match rate and 1,202 ADL-tagged flights
2. **Good booking compliance**: 53.7% of pilots within ±15m of booked time
3. **Low no-show rate**: 16.2% unmatched bookings

### Slot System
4. **Slot system in passive mode**: Only 14 of 9,394 slots assigned (0.15%). CTD/EDCT propagation not active.
5. **Most tracks unused for slot control**: 30 of 39 tracks have zero assignments
6. **Only constrained tracks see slot fill**: N1 (14.3%), O1 (14.3%), R (0.9%)

### Delays (09–18Z, Event vs Non-Event)
7. **CTP OUT-ETD delays are tightly clustered**: 64% of CTP flights push back +30–60min after ETD (median +40min). This is structural — pilots prefile early and wait for the event window.
8. **Non-CTP traffic is widely dispersed** (std 61.0m): 15.2% on-time (±5m), 21.5% extremely late (>60m). Much wider variance than CTP (std 30.3m).
9. **CTP mean is close to non-CTP** (+44.5m vs +40.0m) but has higher median (+40m vs +20m) due to the structural delay floor. Non-CTP has far more on-time departures (42.6% within ±15m vs CTP 6.7%).
10. **No EDCT data in PERTI databases** — all CTD/EDCT values are NULL in PERTI's slot system and flight tables.

### CCFRP Flow Control (FlowControl System)
11. **87 CCFRP CTOTs issued on event day** via FlowControl (69 DCC, 18 CANOC). These are take-off times assigned to non-event transatlantic flights.
12. **CCFRP data not integrated with PERTI**: Of 63 CCFRP flights found in ADL, 62 had no EDCT/CTD values. FlowControl operates independently.
13. **58.7% of CCFRP flights departed within ±15m of assigned CTOT**. 23.8% departed >15m late. Some extreme outliers where CTOT was issued after departure.
14. **10 CCFRP-controlled flights were tagged as CTP event flights** in ADL, suggesting overlap between CCFRP and CTP event traffic.

### Route Compliance (09–18Z, Event vs Non-Event)
15. **CTP route compliance is 100%**: 92.9% match event tracks, 4.2% NE playbook routes, 2.4% event playbook routes. Zero non-compliant CTP flights.
16. **EB non-CTP compliance is 62.1%** (110/177): 19.8% NE tracks (N1=27, M1=4, R=4), 8.5% NE playbook routes, 12.4% event tracks, 21.5% event playbook routes.
17. **WB non-CTP compliance is 14.3%** (17/119): NE routes/tracks are eastbound-only. WB matches are to event playbook routes only.
18. **Overall non-CTP compliance is 42.9%** (127/296).
19. **N1 is the dominant NE track for non-CTP EB flights** (27 of 35 NE track matches). M1 and R each have 4 matches.

### Traffic Patterns (09–18Z, Event vs Non-Event)
20. **CTP dominates transatlantic traffic**: 836 of 1,132 departures (73.9%) during 09–18Z
21. **Peak CTP saturation at 13Z**: 275 CTP pushbacks = 92.3% of all transatlantic traffic that hour. Non-CTP drops to 21–23 during peak CTP hours (13–14Z).
22. **Non-CTP is 60% eastbound, 40% westbound**: 177 EB + 119 WB. Westbound departures from EGLL (27), EDDF (20), EDDM (9) are significant non-CTP traffic.
23. **KDFW is the largest CTP gateway** (164 pushbacks 09–18Z), followed by KJFK (107) and KMCO (107)
24. **KJFK is the top mixed-use airport**: 107 CTP + 38 non-CTP (145 total). Non-CTP gateways: CYUL (15), KORD (6), KMIA (7).

### Non-Event Slot Utilization (10–23Z)
25. **NE slot utilization was 3.8% actual vs 67.0% potential** — 7 of 182 NE slots assigned vs 122 that could have been filled if slot request technology had been functioning.
26. **115 additional flights could have been slotted** — the gap between potential and actual utilization.
27. **NE track capacity was exceeded in 7 of 10 active hours** — 74 flights would have been unslotted even with perfect technology, indicating the 13 slots/hr NE capacity (without RR1) was undersized for actual demand.
28. **RR1 had no program_id and no slots generated** — its 16 acph capacity was effectively zero. If RR1 had been active, total NE capacity would have been 29 slots/hr (vs 13), sufficient for all 196 non-event EB flights.
29. **All 7 actual assignments occurred 17–20Z** — no slots were assigned during the 10–16Z period when 123 non-event EB flights departed.

### Data Quality / System Issues
30. Some stale OUT times from days ago (CYYC has Apr 20 OUT times) — these are test/reconnected flights
31. **M2/N2/O2 NE tracks defined in FlowControl but absent from `ctp_session_tracks`** — FlowControl has 44 oceanic tracks vs 39 in PERTI DB. Additional FlowControl-only tracks include NE3E, NE3W, T270, T610.
