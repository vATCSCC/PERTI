# Analysis

This page indexes operational analyses produced from PERTI flight data. Each analysis is generated from production database queries and documents findings, methodology, and data caveats.

## Published Analyses

| Analysis | Period | Key Finding | Link |
|----------|--------|-------------|------|
| **ETA Calculation Accuracy** | Jan - Mar 2026 | 7.5x MAE improvement (38→5 min) across V3→V35 algorithm transition | [Full Report](../blob/main/docs/ETA_ACCURACY_ANALYSIS_JAN_MAR_2026.md) |

## Planned / Possible Analyses

The sections below outline analyses that are feasible with current data (1.6M flights, Dec 2025 - Mar 2026).

### Demand & Capacity

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **Airport Demand Profiles** | Peak hour demand vs configured AAR/ADR at major airports. Identify airports where demand routinely exceeds capacity. | 1.6M flights, 1,421 airport configs, 4,499 taxi references |
| **ARTCC Sector Workload** | Flights per sector per hour, peak loading, sector split effectiveness. Which sectors are chronically overloaded? | 35M crossing predictions, 3,033 boundary polygons, sector split configs |
| **Day-of-Week / Time-of-Day Patterns** | Network-wide traffic shape — weekend peaks (Sun 289K vs Wed 200K), hourly distribution, regional variation. | 1.6M flights with timestamps |
| **Route Congestion** | Most-used routes and waypoints. Identify bottleneck fixes where demand concentrates. | 9.3M waypoint records, 940K parsed routes |

### Flight Operations

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **Taxi Time Analysis** | Compare observed taxi times against unimpeded reference. Identify airports with systematic ground delays. | 688K OUT times, 161K OFF times, 4,499 airport taxi references |
| **Flight Time by City Pair** | Actual vs expected block times for top city pairs. Seasonal and directional (wind) variation. | 5,854 city-pair stats, 1.6M flight records |
| **Fleet Mix & Performance** | Aircraft type distribution by region/route length. Compare ETA accuracy across aircraft categories. | 926K aircraft records (ICAO type, weight class, engine) |
| **Route Parse Success Rate** | What % of routes parse fully? Which route patterns fail? Impact of parse quality on downstream accuracy. | 940K COMPLETE, 248K PENDING, 7.8K PARTIAL, 1.5K FAILED |

### TMI Effectiveness

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **GDP Program Analysis** | Historical GDP programs — duration, delay generated, airport distribution. SFO (21), DCA (14), BOS (13) lead. | 172 programs (139 GDP, 29 GS), 1,020 advisories |
| **Reroute Compliance** | How many flights follow issued reroutes? Compliance rates by route type and facility. | 268 reroute definitions |
| **Advisory Patterns** | When are advisories issued? Distribution by type, facility, time of day. Correlation with actual demand. | 1,020 advisories with Discord integration |

### System Performance

| Analysis | Description | Data Available |
|----------|-------------|----------------|
| **Boundary Detection Accuracy** | Compare predicted sector entry times against trajectory-observed entries. | 35M crossing predictions, trajectory data |
| **Waypoint ETA Accuracy** | Per-waypoint predicted vs actual passage times (where `ata_utc` populated). | 9.3M waypoint records with eta_utc + ata_utc |
| **Wind Correction Effectiveness** | Deep dive into V35_SEG_WIND vs non-wind methods, stratified by wind magnitude and direction. | Wind component data on all flights since Jan 27 |
| **Processing Latency** | How fresh are ETAs at query time? Daemon cycle times and calculation staleness. | Daemon logs, `eta_last_calc_utc` timestamps |

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
