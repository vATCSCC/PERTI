# TMI Historical Import Statistics

> Data imported from 2020 NTML and ADVZY logs via [PR #24](https://github.com/vATCSCC/PERTI/pull/24) and [PR #33](https://github.com/vATCSCC/PERTI/pull/33). Statistics generated 2026-02-11.

## Overview

**8,800 records** across 7 tables, spanning **February 2020 through February 2026** (~6 years of vATCSCC operations).

| Table | Rows | Description |
|-------|------|-------------|
| `tmi_entries` | 5,288 | Core TMI log entries (MIT, STOP, CONFIG, delays, etc.) |
| `tmi_programs` | 145 | GDP and Ground Stop programs |
| `tmi_advisories` | 1,019 | Advisory messages |
| `tmi_reroutes` | 251 | Reroute definitions |
| `tmi_reroute_routes` | 966 | Route strings for reroutes |
| `tmi_delay_entries` | 308 | Departure/enroute/arrival delay reports |
| `tmi_airport_configs` | 823 | Airport configuration snapshots |
| **Total** | **8,800** | |

### Date Ranges

| Source | Earliest | Latest |
|--------|----------|--------|
| `tmi_entries.valid_from` | 2020-04-17 | 2026-02-08 |
| `tmi_programs.start_utc` | 2020-03-29 | 2026-02-11 |
| `tmi_advisories.effective_from` | 2020-02-29 | 2026-02-08 |

---

## TMI Entries (5,288)

### Entry Type Distribution

| Type | Count | % | Description |
|------|-------|---|-------------|
| MIT | 3,629 | 68.6% | Miles-In-Trail restrictions |
| CONFIG | 823 | 15.6% | Airport configuration changes |
| CANCEL | 245 | 4.6% | Cancellation of prior TMIs |
| DD | 197 | 3.7% | Departure Delays |
| CFR | 97 | 1.8% | Call For Release |
| STOP | 89 | 1.7% | Ground Stops (entry-level) |
| AD | 57 | 1.1% | Arrival Delays |
| ED | 54 | 1.0% | Enroute Delays |
| MINIT | 49 | 0.9% | Minutes-In-Trail |
| APREQ | 29 | 0.5% | Approval Requests |
| TBM | 17 | 0.3% | Time-Based Management |
| MISC | 2 | <0.1% | Miscellaneous/Planning |

### Restriction Values by Type

| Type | Non-null | Min | Max | Avg | Unit |
|------|----------|-----|-----|-----|------|
| MIT | 3,613 | 0 | 80 | 23.1 | NM |
| DD | 168 | 10 | 90 | 34.4 | MIN |
| MINIT | 49 | 2 | 60 | 13.8 | MIN |
| ED | 15 | 15 | 45 | 26.3 | MIN |
| AD | 14 | 15 | 45 | 23.6 | MIN |
| TBM | 1 | 3 | 3 | 3.0 | MIN |

### Field Population Rates

| Field | Count | % | Notes |
|-------|-------|---|-------|
| `condition_text` | 5,288 | 100% | 3,780 distinct values |
| `parsed_data` (JSON) | 5,288 | 100% | Structured flow/stream metadata |
| `restriction_value` | 3,860 | 73% | Range 0-90 |
| `flow.fix` (JSON) | 3,731 | 70.6% | Primary measurement point/fix |
| `qualifiers` | 1,198 | 22.6% | Stream modifiers, type filters |
| `flow.modifier` | 848 | 16.0% | AS ONE, PER STREAM, etc. |
| `flow.direction` | 197 | 3.7% | arrivals (139), departures (58) |
| `flow.dd_origin` | 35 | 0.7% | D/D origin airport |

### Impacting Condition (Reason)

| Reason | Count | % |
|--------|-------|---|
| VOLUME | 4,006 | 75.8% |
| VMC | 629 | 11.9% |
| _(null)_ | 423 | 8.0% |
| LVMC | 98 | 1.9% |
| IMC | 77 | 1.5% |
| OTHER | 17 | 0.3% |
| LIMC | 16 | 0.3% |
| WEATHER | 9 | 0.2% |
| NAVAID / RUNWAY | 12 | 0.2% |

---

## MIT Deep Dive (3,629 entries)

### Restriction Value Histogram

```
  0-5  NM: ####                                          62  (1.7%)
 6-10  NM: ################                             458 (12.7%)
11-15  NM: ######################                       655 (18.1%)
16-20  NM: ##############################               943 (26.1%)
21-25  NM: ##############                               394 (10.9%)
26-30  NM: ##################                           516 (14.3%)
31-40  NM: ###############                              412 (11.4%)
41-50  NM: #####                                        144  (4.0%)
51-60  NM: #                                             20  (0.6%)
61-80  NM: #                                              8  (0.2%)
```

Most MITs are in the 16-20 NM range (26.1%). The practical range is 10-40 NM (93.5% of all MITs).

### Top Qualifier Combinations

| Qualifier | Count | Description |
|-----------|-------|-------------|
| AS ONE | 392 | Treat all streams as single flow |
| PER STREAM | 224 | Apply restriction per stream |
| RALT | 91 | Regardless/Requested Altitude (altitude-agnostic restriction) |
| PER ROUTE | 85 | Apply per route |
| NO STACKS | 81 | No stacking allowed |
| TYPE:JET(S) | 114 | Jets only (TYPE:JET and TYPE:JETS combined) |
| PER AIRPORT | 28 | Apply per airport |
| AS ONE + TYPE:ALL | 22 | Combined qualifier |
| EACH | 11 | Apply to each individually |

### Flow Modifiers

| Modifier | Count | % of MIT |
|----------|-------|----------|
| _(none)_ | 2,784 | 76.7% |
| AS ONE | 443 | 12.2% |
| PER STREAM | 259 | 7.1% |
| PER ROUTE | 93 | 2.6% |
| PER AIRPORT | 33 | 0.9% |
| SINGLE STREAM | 17 | 0.5% |

### Top Fix-Airport Pairs

These represent the most frequently used flow control points:

| Fix | Airport | Count | Flow Description |
|-----|---------|-------|-----------------|
| MERIT | BOS | 50 | Boston arrivals via MERIT |
| JFK | BOS | 49 | Boston arrivals via JFK (metro fix) |
| CAMRN | JFK | 40 | JFK arrivals via CAMRN |
| NEWES | BOS | 31 | Boston arrivals via NEWES |
| SLT | EWR | 31 | Newark arrivals via SLT |
| KORRY | LGA | 30 | LaGuardia arrivals via KORRY |
| CCC | JFK | 29 | JFK arrivals via CCC |
| DYLIN | EWR | 26 | Newark arrivals via DYLIN |
| MIP | LGA | 25 | LaGuardia arrivals via MIP |
| BONNT | ORD | 23 | O'Hare arrivals via BONNT |
| FWA | ORD | 23 | O'Hare arrivals via FWA |
| CRANK | EWR | 23 | Newark arrivals via CRANK |
| PONCT | BOS | 22 | Boston arrivals via PONCT |
| ALL | DCA | 22 | DCA all-fix restriction |

### MIT Counts by Airport (Top 25)

| # | Airport | MIT Count | % |
|---|---------|-----------|---|
| 1 | BOS | 304 | 11.3% |
| 2 | JFK | 224 | 8.3% |
| 3 | ORD | 209 | 7.7% |
| 4 | DCA | 198 | 7.3% |
| 5 | DTW | 161 | 6.0% |
| 6 | LGA | 159 | 5.9% |
| 7 | EWR | 155 | 5.7% |
| 8 | SFO | 146 | 5.4% |
| 9 | MIA | 130 | 4.8% |
| 10 | MDW | 98 | 3.6% |
| 11 | PHL | 93 | 3.4% |
| 12 | DEN | 78 | 2.9% |
| 13 | SEA | 75 | 2.8% |
| 14 | IAD | 74 | 2.7% |
| 15 | ATL | 72 | 2.7% |
| 16 | MCO | 69 | 2.6% |
| 17 | MSP | 64 | 2.4% |
| 18 | LAX | 60 | 2.2% |
| 19 | BNA | 59 | 2.2% |
| 20 | PHX | 51 | 1.9% |
| 21 | SJC | 46 | 1.7% |
| 22 | DFW | 45 | 1.7% |
| 23 | BWI | 44 | 1.6% |
| 24 | LAS | 43 | 1.6% |
| 25 | PDX | 41 | 1.5% |

---

## Temporal Analysis

### Entries by Year

| Year | Count | % | Trend |
|------|-------|---|-------|
| 2020 | 409 | 7.7% | Early pandemic era |
| 2021 | 515 | 9.7% | Recovery period |
| 2022 | 467 | 8.8% | Steady state |
| 2023 | 994 | 18.8% | Growth |
| 2024 | 1,169 | 22.1% | Peak activity |
| 2025 | 1,389 | 26.3% | Highest year |
| 2026 | 345 | 6.5% | Partial (through Feb) |

### Year-over-Year by Entry Type

| Type | 2020 | 2021 | 2022 | 2023 | 2024 | 2025 | 2026 |
|------|------|------|------|------|------|------|------|
| MIT | 134 | 275 | 319 | 654 | 864 | 1,104 | 279 |
| CONFIG | 207 | 167 | 107 | 151 | 100 | 78 | 13 |
| CANCEL | 0 | 1 | 4 | 63 | 83 | 76 | 18 |
| DD | 9 | 20 | 18 | 40 | 62 | 41 | 7 |
| CFR | 7 | 6 | 4 | 29 | 6 | 33 | 12 |
| STOP | 19 | 1 | 5 | 16 | 23 | 21 | 4 |
| AD | 11 | 17 | 2 | 8 | 8 | 5 | 6 |
| ED | 13 | 9 | 3 | 9 | 7 | 7 | 6 |

MIT entries have grown ~8x from 2020 to 2025, reflecting increased network activity and TMI sophistication. CONFIG entries peaked in 2020 and declined as live ATIS integration replaced manual config logging.

### Seasonal Distribution (All Years)

| Season | Months | Count | % |
|--------|--------|-------|---|
| Winter | Dec, Jan, Feb | 2,422 | 45.8% |
| Fall | Sep, Oct, Nov | 1,086 | 20.5% |
| Summer | Jun, Jul, Aug | 961 | 18.2% |
| Spring | Mar, Apr, May | 815 | 15.4% |

Winter (DJF) dominates with nearly half of all entries, consistent with the VATSIM winter event season (Cross the Pond, FNO series, holiday events). Fall (SON) is second, driven by the start of the fall/winter event cycle.

### Day of Week

| Day | Count | % |
|-----|-------|---|
| Friday | 2,189 | 41.4% |
| Thursday | 1,924 | 36.4% |
| Saturday | 831 | 15.7% |
| Sunday | 127 | 2.4% |
| Wednesday | 88 | 1.7% |
| Tuesday | 67 | 1.3% |
| Monday | 58 | 1.1% |

93.5% of TMI activity occurs Thursday-Saturday, driven by VATSIM's Friday Night Operations (FNO) and Saturday events (timestamps in UTC shift the perceived US-evening activity into Thu/Fri).

### Hourly Distribution (UTC + US Local)

> Local times shown as **Standard / Daylight**. Most TMI activity falls in fall/winter (66.3%), so standard time applies for the majority of entries.

| UTC | Eastern | Central | Mountain | Pacific | Count | % | Note |
|-----|---------|---------|----------|---------|-------|---|------|
| 23:00Z | 18:00/19:00 | 17:00/18:00 | 16:00/17:00 | 15:00/16:00 | 2,012 | 38.0% | Event start |
| 00:00Z | 19:00/20:00 | 18:00/19:00 | 17:00/18:00 | 16:00/17:00 | 1,111 | 21.0% | Peak event |
| 01:00Z | 20:00/21:00 | 19:00/20:00 | 18:00/19:00 | 17:00/18:00 | 602 | 11.4% | Mid-event |
| 02:00Z | 21:00/22:00 | 20:00/21:00 | 19:00/20:00 | 18:00/19:00 | 352 | 6.7% | Late event |
| 03:00Z | 22:00/23:00 | 21:00/22:00 | 20:00/21:00 | 19:00/20:00 | 185 | 3.5% | Wind-down |
| 22:00Z | 17:00/18:00 | 16:00/17:00 | 15:00/16:00 | 14:00/15:00 | 286 | 5.4% | Pre-event setup |
| 21:00Z | 16:00/17:00 | 15:00/16:00 | 14:00/15:00 | 13:00/14:00 | 146 | 2.8% | Early starts |

Peak TMI activity (23:00-01:00Z) corresponds to **18:00-20:00 US Eastern (standard)** — the prime Friday Night Operations (FNO) window.

### Busiest Days

| Date | Day | Entries |
|------|-----|---------|
| 2025-11-22 | Saturday | 65 |
| 2026-01-31 | Saturday | 65 |
| 2026-01-30 | Friday | 61 |
| 2025-09-26 | Friday | 58 |
| 2025-06-27 | Friday | 58 |
| 2026-02-07 | Saturday | 52 |
| 2025-08-15 | Friday | 51 |
| 2025-08-16 | Saturday | 49 |
| 2021-02-27 | Saturday | 48 |

Average entries per active day: **11.4** (across 465 active days).

### Data Gaps

21 months have zero entries, concentrated in 2020-2021 (pandemic period, early vATCSCC operations) and scattered single-month gaps in later years.

---

## Airport Profiles (Top 10)

### BOS - Boston Logan

| Metric | Value |
|--------|-------|
| Total entries | 399 |
| MIT entries | 304 (76.2%) |
| Programs | 13 |
| Advisories | 53 |
| Reroute destinations | 33 |
| Top fix | MERIT (53 uses) |
| Top qualifier | AS ONE (38x) |
| Avg MIT restriction | 27.6 NM |
| STOP entries | 21 |

### JFK - John F. Kennedy

| Metric | Value |
|--------|-------|
| Total entries | 315 |
| MIT entries | 224 (71.1%) |
| Programs | 11 |
| Advisories | 22 |
| Top fix | CAMRN (40 uses) |
| Top qualifier | AS ONE (30x) |
| Avg MIT restriction | 27.6 NM |

### DCA - Washington National

| Metric | Value |
|--------|-------|
| Total entries | 256 |
| MIT entries | 198 (77.3%) |
| Programs | 14 |
| Advisories | 44 |
| Top fix | ALL (23 uses) |
| Top qualifier | AS ONE (29x) |
| Avg MIT restriction | 28.5 NM |

### ORD - Chicago O'Hare

| Metric | Value |
|--------|-------|
| Total entries | 246 |
| MIT entries | 209 (85.0%) |
| Programs | 1 |
| Advisories | 8 |
| Top fix | BONNT (23 uses) |
| Top qualifier | RALT (39x) |
| Avg MIT restriction | 20.0 NM |

### EWR - Newark Liberty

| Metric | Value |
|--------|-------|
| Total entries | 217 |
| MIT entries | 155 (71.4%) |
| Programs | 5 |
| Top fix | SLT (33 uses) |
| Top qualifier | TYPE:JET (7x) |
| Avg MIT restriction | 24.2 NM |

### Other Top Airports

| Airport | Entries | MIT | Top Fix | Avg MIT NM |
|---------|---------|-----|---------|------------|
| LGA | 209 | 159 | KORRY (30) | 26.4 |
| DTW | 205 | 161 | KOZAR (19) | 17.5 |
| SFO | 197 | 146 | MAKRS/STOKD (12) | 27.8 |
| MIA | 171 | 130 | FROGZ (17) | 23.7 |
| ATL | 156 | 72 | CHPPR/GLAVN (7) | 14.4 |

---

## Programs (145)

### By Type

| Type Code | Meaning | Count | % | Avg Duration | Description |
|-----------|---------|-------|---|-------------|-------------|
| `1` | Ground Stop | 139 | 95.9% | 34 min | Advisory-issued ground stops — created from ADVZY GS advisories. Stops all departures to a specific airport. Duration range: 14-135 min. |
| `2` | GDP | 4 | 2.8% | 342 min (5.7 hrs) | Ground Delay Programs — assigns EDCTs to meter arrivals. Includes program rate (7-42 arrivals/hr). |
| `GS` | Ground Stop (system) | 2 | 1.4% | 306 min | Ground stops created via the live GS system (not historical import). |

**Type 1 (Ground Stops)** are the dominant program type because vATCSCC primarily uses ground stops to manage congestion during events. GDPs (type 2) are rare — only 4 in the dataset — because GDP operations require sustained high demand over several hours, which is uncommon on VATSIM. The 4 GDPs were all early-era programs (2020-2021) at SFO, PHX, DEN, and BOS.

### Top Airports

SFO (21), DCA (14), BOS (13), JFK (11), MSP (10), DEN (7), EWR (5), N90 (4), SEA (4), SJC (4)

### Program Rates

Only 4 programs have a `program_rate` set (range 7-42, avg 28.2). Only 10/145 (6.9%) have enriched `scope_json`. Historical ground stop programs do not carry flight count or delay statistics — these metrics are only populated for programs managed via the live TMI system.

---

## Advisories (1,019)

| Type | Count | % |
|------|-------|---|
| GENERAL | 579 | 56.8% |
| REROUTE | 263 | 25.8% |
| GROUND_STOP | 139 | 13.6% |
| CANCELLATION | 34 | 3.3% |
| GROUND_DELAY | 4 | 0.4% |

- 86% have no linked `program_id` (standalone advisories)
- Top GS advisory airports: SFO (20), DCA (14), BOS (12), JFK (11), MSP (10)

---

## Reroutes (251 reroutes, 966 routes)

### Route Statistics

| Metric | Value |
|--------|-------|
| Routes per reroute (avg) | 4.0 |
| Routes per reroute (median) | 2.0 |
| Routes per reroute (max) | 34 |
| Route string length (avg) | 44 chars |
| Route string length (max) | 443 chars |

### Top Origin-Destination Pairs

| Origin | Destination | Count |
|--------|-------------|-------|
| RDU | BOS | 16 |
| ORF | BOS | 15 |
| ZTL | BOS | 15 |
| ZJX | BOS | 14 |
| ZMA | BOS | 14 |
| FMY/PIE | BOS | 13 |
| JFK | DCA | 11 |
| PVD | BOS/BDL | 21 |

BOS dominates as a reroute destination (192 of 966 routes, 19.9%).

### Largest Reroutes

| Name | Adv# | Routes |
|------|------|--------|
| ZMA/ZJX/ZTL/ZDC departures to BOS/PVD/BDL | ADVZY 001 | 34 |
| North FL FNO Routes | ADVZY 002 | 21 |
| Caribbean FNO Routes | ADVZY 004 | 21 |
| Florida to NE Partial Mod | ADVZY 001 | 17 |
| ZBW to PCT | ADVZY 001 | 16 |

---

## Delay Entries (308)

### By Delay Type

| Type | Count | % | Avg Delay | Median | P75 | Zero Count |
|------|-------|---|-----------|--------|-----|------------|
| D/D (Departure) | 197 | 64.0% | 29.3 min | 30 min | 45 min | 29 (15%) |
| A/D (Arrival) | 57 | 18.5% | 5.8 min | 0 min | 0 min | 43 (75%) |
| E/D (Enroute) | 54 | 17.5% | 7.3 min | 0 min | 15 min | 39 (72%) |

D/D entries have substantial delay values (median 30 min). A/D and E/D are mostly holding reports (zero delay minutes, delay info in holding fields).

### Delay Trends

| Type | Increasing | Decreasing | Unknown |
|------|-----------|------------|---------|
| D/D | 107 (54%) | 61 (31%) | 29 (15%) |
| A/D | 37 (65%) | 16 (28%) | 4 (7%) |
| E/D | 36 (67%) | 13 (24%) | 5 (9%) |

### Holding

Holding entries indicate the **start** or **end** of holding at a specified fix:
- **+Holding** = holding has begun (aircraft are in holding patterns)
- **-Holding** = holding has ended (aircraft released from holds)

Matching +Holding/-Holding pairs represent a complete holding event. In practice, reporting inconsistencies may result in unpaired entries (e.g., a +Holding with no corresponding -Holding), making it difficult to determine exact holding durations from the log data alone.

- 68 entries (22.1% of delay reports) report holding status: **48 +Holding** (start) and **20 -Holding** (end)
- 45 entries have a named holding fix
- Top fixes: SKILS/CL (3), TRISH/SK (3), CRANK (2), DEDWT (2), ENDEW (2)

### Hourly Distribution

Delay reports peak at 01:00-02:00 UTC / 20:00-21:00 Eastern (standard), corresponding to mid-event congestion when arrival demand exceeds capacity.

### Top Airports

JFK (31), ATL (30), BOS (29), DCA (24), SFO (18), MIA (13), LAX (12), DAL (10), CLT (10), EWR (9)

---

## Airport Configs (823)

### Weather Conditions

| Condition | Count | % |
|-----------|-------|---|
| VMC | 730 | 88.7% |
| IMC | 93 | 11.3% |

### Acceptance/Departure Rates

| Metric | AAR | ADR |
|--------|-----|-----|
| Count (non-null) | 741 | 820 |
| Min | 10 | 12 |
| Max | 152 | 132 |
| P25 | 32 | 30 |
| Median (P50) | 45 | 40 |
| P75 | 66 | 60 |
| Avg | 51.2 | 45.8 |

### AAR/ADR by Airport

| Airport | Configs | Min AAR | Max AAR | Avg AAR | Min ADR | Max ADR | Avg ADR |
|---------|---------|---------|---------|---------|---------|---------|---------|
| ATL | 39 | 20 | 132 | 87.6 | 20 | 132 | 77.1 |
| ORD | 20 | 76 | 114 | 92.7 | - | - | - |
| CLT | 28 | 48 | 87 | 68.7 | 36 | 87 | 58.8 |
| MCO | 21 | 22 | 86 | 64.4 | 40 | 86 | 61.5 |
| DTW | 25 | - | - | - | 32 | 72 | 55.9 |
| JFK | 36 | 32 | 60 | 47.0 | 20 | 52 | 33.8 |
| BOS | 31 | 32 | 54 | 39.7 | 30 | 54 | 39.1 |
| EWR | 30 | 28 | 51 | 39.2 | 28 | 44 | 37.0 |
| SFO | 23 | 20 | 54 | 36.1 | 20 | 54 | 37.9 |
| LGA | 29 | 30 | 40 | 34.9 | 28 | 40 | 36.4 |

### Top Runway Configurations

**Arrival:**

| Config | Count | Likely Airport |
|--------|-------|---------------|
| 22L | 26 | JFK |
| 26R/27L/28 | 18 | ATL |
| 28L/28R | 17 | SFO |
| 10L/10R | 16 | MCO/MDW |
| 30L/30R | 15 | ORD |

**Departure:**

| Config | Count | Likely Airport |
|--------|-------|---------------|
| 22R | 30 | JFK |
| 26L/27R | 23 | ATL |
| 10L/10R | 20 | MCO/MDW |
| 01L/01R | 19 | SFO/OAK |
| 27 | 18 | LGA |

### Population Rates

- Arrival runways populated: 791/823 (96.1%)
- Departure runways populated: 790/823 (96.0%)

---

## Ground Stops (89 STOP entries + 139 GS programs)

Ground stops appear in two places in the dataset:
1. **STOP entries** (89 in `tmi_entries`) — NTML log records of ground stop actions with flow-level detail (fix, airports, direction)
2. **GS programs** (139 in `tmi_programs`, type `1`) — advisory-issued ground stop programs created from ADVZY GS advisories, with duration and scope

### GS Program Statistics

| Metric | Value |
|--------|-------|
| Total GS programs | 139 |
| Duration range | 14 - 135 min |
| Average duration | 34 min |
| Top airports | SFO (21), DCA (14), BOS (13), JFK (11), MSP (10) |
| Impacting condition | VOLUME (100%) |

### GS Advisory Scope Examples

GS advisories include departure scope information indicating which facilities/tiers are affected:

| Scope | Meaning | Example Advisory |
|-------|---------|-----------------|
| `(1STTIER)` | First-tier departure airports only | SFO GS, 1st tier scope |
| `(1STTIER+CANADA)` | First tier plus Canadian airports | BOS GS with CYYZ/CYUL included |
| `ZMA,ZJX,ZDC,ZTL,...` | Specific departure center list | Multi-center GS for MIA |
| `ALL US/CNDN FACILITIES` | Network-wide ground stop | Full NAS ground stop |

### Notable Ground Stops

| Date | Airport(s) | Duration | Scope | Description |
|------|-----------|----------|-------|-------------|
| 2020-04-18 | BOS, BDL | ~60 min | 1st Tier | BOS departures STOP, multi-airport |
| 2020-05-29 | N90, JFK, EWR, LGA, PHL | ~45 min | Multi-center | Major NY metro STOP (west & north gates) |
| 2023-06-20 | ATL | ~30 min | 1st Tier | ATL via SOUTH STOP |
| 2024-01-21 | SNA, ONT, LAX | ~40 min | 1st Tier + Canada | SoCal arrivals via ALL STOP |
| 2025-06-16 | MIA, FLL | ~50 min | ZMA, ZJX, ZDC, ZTL | South FL double STOP (EXBOX, DEFUN, ACORI) |
| 2025-08-16 | ZJX, ATL | ~35 min | 1st Tier | ZJX via BANKR + ATL via SITTH |
| 2026-01-30 | BOS, DCA | ~65 min | 1st Tier + Canada | Multi-facility STOP (Q133, HNK, BUCKO) |

> **Note**: Duration estimates are approximate, derived from STOP entry valid_from/valid_until or corresponding GS advisory effective periods. Historical GS programs do not carry per-flight delay statistics; delay impact can be estimated from D/D and E/D entries at the same airport during the same period.

---

## Cancel Analysis (245 entries)

### Cancel Targets

| Target | Count | Description |
|--------|-------|-------------|
| TMI (generic) | 176 | Cancel all TMIs at airport |
| MIT | 22 | Cancel specific MIT restriction |
| RESTR | 13 | Cancel restriction |
| TBFM | 2 | Cancel TBFM measure |
| _(unknown)_ | 32 | Target not parseable |

### Top Airports for Cancellations

DTW (15), JFK (15), ORD (13), DCA (12), LGA (12), EWR (11), MDW (10), MIA (9), BOS (7)

---

## Parsed Data Completeness

Shows which JSON sections are populated per entry type:

| Type | Total | flow | qualifiers | delay | config | tbm | cancel | facilities |
|------|-------|------|------------|-------|--------|-----|--------|------------|
| MIT | 3,629 | 3,628 | 1,195 | - | - | - | - | 3,583 |
| CONFIG | 823 | - | - | - | 823 | - | - | - |
| CANCEL | 245 | 145 | - | - | - | - | 213 | 216 |
| DD | 197 | 35 | - | 168 | - | - | - | - |
| CFR | 97 | 96 | - | - | - | - | - | 94 |
| STOP | 89 | 87 | - | - | - | - | - | 89 |
| AD | 57 | 3 | - | 55 | - | - | - | 54 |
| ED | 54 | - | - | 54 | - | - | - | 47 |
| MINIT | 49 | 49 | 3 | - | - | - | - | 49 |
| APREQ | 29 | 27 | - | - | - | - | - | 29 |
| TBM | 17 | 17 | - | - | - | 14 | - | 17 |

### Condition Text Examples

| Type | Sample |
|------|--------|
| MIT | `BOS via MERIT 15NM` |
| MIT | `DTW via GRAYT, HAYLL, TPGUN, WNGNT, BONZZ, CRAKN 10NM PER STREAM` |
| CONFIG | `ATL VMC ARR:26R/27L/28 DEP:26L/27R AAR:132 ADR:70` |
| DD | `JFK D/D +45min` |
| ED | `ZDC E/D for BOS +30min 13 ACFT` |
| STOP | `BOS,BDL departures STOP` |
| CFR | `MIA,FLL,RSW departures CFR` |
| TBM | `ATL TBM 3_WEST` |
| CANCEL | `MSP CANCEL RESTR via MNOSO` |
| APREQ | `JFK,EWR,LGA APREQ via J220` |

---

## Data Sources & Methodology

- **Source files**: `DCC/NTML_2020.txt` (9,579 lines), `DCC/ADVZY_2020.txt` (21,092 lines)
- **Parsers**: `scripts/tmi/ntml_parser.php` (12 entry types), `scripts/tmi/advzy_parser.php` (5 advisory types)
- **Import orchestrator**: `scripts/tmi/import_historical.php`
- **Python re-import**: `C:/temp/tmi_reimport.py` (handles structured JSON field mapping)
- **NTML success rate**: 5,288/5,288 (100%)
- **ADVZY success rate**: 1,015/1,030 (98.5%, 15 edge cases with NULL required fields)
- **All records**: `source_type = 'IMPORT'`, `created_by_name = 'Historical Import 2020'`

### Known Limitations

1. **21 gap months**: Some months have zero entries due to periods when NTML logging was not active or data was not archived
2. **15 ADVZY failures**: NULL `start_utc` (GS entries without parseable times) and NULL `destination` (reroutes without parseable O/D pairs)
3. **14 DD entries with UNKN airport**: D/D entries where neither the airport nor requesting facility could be extracted from the log text
4. **CONFIG decline**: CONFIG entry counts dropped from 207 (2020) to 13 (2026 partial) as live ATIS integration replaced manual logging
