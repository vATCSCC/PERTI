# Analysis

This page indexes operational analyses produced from PERTI flight data. Each analysis is generated from production database queries and documents findings, methodology, and data caveats.

## Published Analyses

| Analysis | Period | Key Finding | Link |
|----------|--------|-------------|------|
| **ETA Calculation Accuracy** | Jan - Mar 2026 | 7.5x MAE improvement (38→5 min) across V3→V35 algorithm transition | [Full Report](../blob/main/docs/audits/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md) |
| **Operational Analysis** | Jan - Mar 2026 | 8 analyses: ARTCC workload, traffic patterns, taxi times, route parsing, wind corrections, processing latency, data gap identification | [Full Report](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) |

## Planned / Possible Analyses

The sections below outline analyses that are feasible with current data (1.6M flights, Dec 2025 - Mar 2026).

### Demand & Capacity

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **Airport Demand Profiles** | Peak hour demand vs configured AAR/ADR at major airports. Identify airports where demand routinely exceeds capacity. | 1.6M flights, 1,421 airport configs, 4,499 taxi references |
| ~~**ARTCC Sector Workload**~~ | ✅ Published in [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) — KZNY dominates at 208 peak flights/hr | |
| ~~**Day-of-Week / Time-of-Day Patterns**~~ | ✅ Published in [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) — weekends 43% higher, 18Z peak | |
| **Route Congestion** | Most-used routes and waypoints. Identify bottleneck fixes where demand concentrates. | 9.3M waypoint records, 940K parsed routes |

### Flight Operations

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| ~~**Taxi Time Analysis**~~ | ✅ Published in [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) — 90% exceed reference, 39% longer at peak hours | |
| **Flight Time by City Pair** | Actual vs expected block times for top city pairs. Seasonal and directional (wind) variation. | 5,854 city-pair stats, 1.6M flight records |
| **Fleet Mix & Performance** | Aircraft type distribution by region/route length. Compare ETA accuracy across aircraft categories. | 926K aircraft records (ICAO type, weight class, engine) |
| ~~**Route Parse Success Rate**~~ | ✅ Published in [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) — 73% overall, 95%+ US domestic | |

### TMI Effectiveness

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **GDP Program Analysis** | Historical GDP programs — duration, delay generated, airport distribution. SFO (21), DCA (14), BOS (13) lead. | 172 programs (139 GDP, 29 GS), 1,020 advisories |
| **Reroute Compliance** | How many flights follow issued reroutes? Compliance rates by route type and facility. | 268 reroute definitions |
| **Advisory Patterns** | When are advisories issued? Distribution by type, facility, time of day. Correlation with actual demand. | 1,020 advisories with Discord integration |

### System Performance

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| ~~**Boundary Detection Accuracy**~~ | ⚠️ Data gap — no `actual_entry_utc` column exists. See [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) | |
| ~~**Waypoint ETA Accuracy**~~ | ⚠️ Data gap — `ata_utc` never populated (0 of 4.58M records). See [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) | |
| ~~**Wind Correction Effectiveness**~~ | ✅ Published in [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) — V35 outperforms V35_SEG_WIND (needs investigation) | |
| ~~**Processing Latency**~~ | ✅ Published in [Operational Analysis](../blob/main/docs/audits/OPERATIONAL_ANALYSIS_JAN_MAR_2026.md) — <1 min staleness for active flights | |

### Event Correlation

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **Event Traffic Impact** | Traffic volume and pattern changes during VATUSA/VATCAN events vs baseline. | 795 perti_events, 348 division_events, flight data |
| **ATIS Runway Impact** | How runway configuration changes affect arrival rates and taxi times. | ATIS data + airport config + flight times |

## Data Inventory

As of 2026-03-12:

| Category | Key Tables | Records | Coverage |
|----------|-----------|---------|----------|
| Flight core | `adl_flight_core` | 1.6M | Dec 2025 - present |
| Route data | `adl_flight_waypoints` | 9.3M | 940K fully parsed routes |
| Crossings | `adl_flight_planned_crossings` | 35.9M | Boundary entry/exit predictions |
| Times | `adl_flight_times` | 1.2M | OOOI, ETAs, bucket times |
| Aircraft | `adl_flight_aircraft` | 926K | Type, weight, engine, wake |
| TMI | `tmi_programs` / `tmi_advisories` | 172 / 1,020 | Programs since 2020, advisories since 2025 |
| Reference | airports / fixes / boundaries | 27K / 269K / 3K | Global coverage |
| Stats (pre-aggregated) | `flight_stats_*` | ~60K rows | Jan 6 - Feb 7, 2026 |

### Data Limitations

- **OOOI capture**: OUT is 57%, but OFF/ON/IN are only 5-13% (VATSIM pilot client limitation — most gate events are inferred)
- **Trajectory retention**: Only 346K records in live table — older data is tiered to blob archive by archival daemon
- **Stats aggregation**: Pre-aggregated stats cover only ~1 month; raw data covers 3+ months
- **GDP slots**: `tmi_slots` table is empty (slot assignment data not retained after program completion)
- **Reviews**: Only 14 TMR reports — too few for statistical analysis of review quality

## Methodology Notes

- All analyses use UTC timestamps
- ETA accuracy excludes outliers with >2 hour error (likely data quality issues, not algorithm failures)
- "MAE" = Mean Absolute Error (average of |predicted - actual|)
- "Bias" = signed mean error (negative = early arrival relative to prediction)
- Flight counts may vary slightly between queries due to live data ingestion during analysis
